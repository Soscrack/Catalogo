#!/usr/bin/env python3
"""Restaura c_ref y p_asignado locales desde el catálogo TPV (3 decimales).

TPV `precio` es BRUTO (con IVA si afecto). TPV `coste` es NETO.
p_asignado se guarda igual que TPV (bruto). La alerta de margen usa
neto = bruto / 1.19 (exento: neto = bruto).

Usa wp-load en producción (credenciales de .env.deploy).
"""
import argparse
import json
import os
from pathlib import Path

import pandas as pd
import paramiko

ROOT = Path(__file__).resolve().parents[1]
XLSX = ROOT / 'TPV' / 'productos_tpv.xlsx'
FUENTE = 'productos_tpv'
NOTAS_HIST = 'TPV productos_tpv 2026-09-03 (precio bruto, coste neto)'
FACTOR_MINIMO = 1.30
IVA_FACTOR = 1.19


def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        return
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def parse_clp(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return round(float(value), 3)
    s = str(value).strip().replace('\xa0', '').replace(' ', '')
    if not s or s.lower() == 'nan':
        return None
    if ',' in s and '.' in s:
        s = s.replace('.', '').replace(',', '.')
    elif ',' in s:
        s = s.replace(',', '.')
    try:
        return round(float(s), 3)
    except ValueError:
        return None


def load_items():
    df = pd.read_excel(XLSX, sheet_name='productos')
    items = []
    for _, raw in df.iterrows():
        sku = str(raw.get('sku', '')).strip()
        if not sku or sku.lower() == 'nan':
            continue
        items.append({
            'sku': sku,
            'nombre': str(raw.get('nombre', '') or '').strip(),
            'precio': parse_clp(raw.get('precio')),
            'coste': parse_clp(raw.get('coste')),
        })
    return items


def build_php(wp: str, dry_run: bool) -> str:
    dry_flag = 'true' if dry_run else 'false'
    return f'''<?php
require_once '{wp}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$now = current_time('mysql');
$dry_run = {dry_flag};
$factor_minimo = {FACTOR_MINIMO};
$iva_factor = {IVA_FACTOR};
$notas_hist = {json.dumps(NOTAS_HIST)};
$fuente = {json.dumps(FUENTE)};

$raw = file_get_contents('/tmp/tpv_price_restore.json');
$items = json_decode($raw, true);
if (!is_array($items)) {{
    fwrite(STDERR, "JSON inválido\\n");
    exit(1);
}}

$precios = $p . 'precios';
$hist = $p . 'precio_historial';
$legacy = $p . 'legacy_precio_ref';
$pb_table = $p . 'producto_base';

if (!$dry_run) {{
    $wpdb->query("ALTER TABLE {{$precios}} MODIFY p_ref DECIMAL(12,3) DEFAULT NULL");
    $wpdb->query("ALTER TABLE {{$precios}} MODIFY p_asignado DECIMAL(12,3) DEFAULT NULL");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy) {{
        $wpdb->query("ALTER TABLE {{$legacy}} MODIFY precio_neto DECIMAL(12,3) DEFAULT NULL");
        $wpdb->query("ALTER TABLE {{$legacy}} MODIFY precio_total DECIMAL(12,3) DEFAULT NULL");
    }}
}}

$has_hist = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hist)) === $hist);
$has_legacy = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy);
$has_en_uso = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'en_uso'",
    DB_NAME,
    $precios
)) > 0;

$matched = 0;
$missing = 0;
$updated = 0;
$inserted_price = 0;
$unchanged = 0;
$overwrite = 0;
$coste_zero = 0;
$margen_bajo = 0;
$legacy_upsert = 0;
$hist_inserted = 0;
$missing_sample = [];
$sample_changed = [];
$would_create_tasks = 0; // siempre 0: no creamos tareas

foreach ($items as $item) {{
    $sku = trim((string) ($item['sku'] ?? ''));
    if ($sku === '') {{
        continue;
    }}
    $precio = $item['precio'] === null ? null : round((float) $item['precio'], 3);
    $coste_raw = $item['coste'] === null ? null : round((float) $item['coste'], 3);
    // coste TPV 0 → c_ref NULL (sin costo real)
    $coste = null;
    if ($coste_raw !== null && abs($coste_raw) >= 0.0005) {{
        $coste = $coste_raw;
    }} else if ($coste_raw !== null) {{
        $coste_zero++;
    }}

    $pb = $wpdb->get_row($wpdb->prepare(
        "SELECT id, facto_iva_tipo FROM {{$pb_table}} WHERE canonical_sku = %s AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1",
        $sku
    ), ARRAY_A);
    if (!$pb) {{
        // fallback sin filtro deleted_at por si la columna no aplica
        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, facto_iva_tipo FROM {{$pb_table}} WHERE canonical_sku = %s LIMIT 1",
            $sku
        ), ARRAY_A);
    }}
    if (!$pb) {{
        $missing++;
        if (count($missing_sample) < 40) {{
            $missing_sample[] = $sku;
        }}
        continue;
    }}
    $matched++;
    $pid = (int) $pb['id'];
    $iva_tipo = strtolower(trim((string) ($pb['facto_iva_tipo'] ?? 'afecto')));
    if ($iva_tipo !== 'exento') {{
        $iva_tipo = 'afecto';
    }}

    // p_asignado = TPV precio BRUTO. Alerta compara neto vs c_ref neto.
    $precio_neto = $precio;
    if ($precio !== null && $iva_tipo === 'afecto') {{
        $precio_neto = round($precio / $iva_factor, 3);
    }}

    $alerta = 0;
    if ($precio_neto !== null && $coste !== null && $precio_neto < ($factor_minimo * $coste)) {{
        $alerta = 1;
        $margen_bajo++;
    }}

    $old = $wpdb->get_row($wpdb->prepare(
        "SELECT id, c_ref, p_asignado, p_ref, alerta_margen, estado_aprobacion
         FROM {{$precios}}
         WHERE producto_base_id = %d AND canal = 'local' AND woocommerce_variation_id = 0
         LIMIT 1",
        $pid
    ), ARRAY_A);

    $old_p = ($old && $old['p_asignado'] !== null && $old['p_asignado'] !== '') ? (float) $old['p_asignado'] : null;
    $old_c = ($old && $old['c_ref'] !== null && $old['c_ref'] !== '') ? (float) $old['c_ref'] : null;
    $old_alerta = $old ? (int) ($old['alerta_margen'] ?? 0) : 0;
    $new_p = $precio;
    $new_c = $coste;
    $price_changed = ($old_p !== $new_p) || ($old_c !== $new_c);
    $alerta_changed = ($old_alerta !== $alerta);
    $changed = $price_changed || $alerta_changed || !$old;
    $had_price = ($old_p !== null);

    if ($old) {{
        if ($changed) {{
            if ($had_price && $price_changed) {{
                $overwrite++;
            }}
            $updated++;
            if (count($sample_changed) < 10) {{
                $sample_changed[] = [
                    'sku' => $sku,
                    'old_precio' => $old_p,
                    'new_precio_bruto' => $new_p,
                    'new_precio_neto' => $precio_neto,
                    'old_coste' => $old_c,
                    'new_coste' => $new_c,
                    'iva_tipo' => $iva_tipo,
                    'alerta_margen' => $alerta,
                    'alerta_changed' => $alerta_changed,
                ];
            }}
        }} else {{
            $unchanged++;
        }}
    }} else {{
        $inserted_price++;
        $updated++;
        if (count($sample_changed) < 10) {{
                $sample_changed[] = [
                    'sku' => $sku,
                    'old_precio' => null,
                    'new_precio_bruto' => $new_p,
                    'new_precio_neto' => $precio_neto,
                    'old_coste' => null,
                    'new_coste' => $new_c,
                    'iva_tipo' => $iva_tipo,
                    'alerta_margen' => $alerta,
                    'insert' => true,
                ];
        }}
    }}

    if ($dry_run) {{
        continue;
    }}

    // Siempre sincroniza estado aprobado + alerta (incluso si precio ya coincidía).
    $price_data = [
        'c_ref' => $coste,
        'p_asignado' => $precio,
        'estado_aprobacion' => 'aprobado',
        'alerta_margen' => $alerta,
        'updated_at' => $now,
    ];
    if ($precio !== null && $coste !== null) {{
        $price_data['p_ref'] = round($coste * 1.80, 3);
    }}

    if ($old) {{
        $wpdb->update($precios, $price_data, ['id' => (int) $old['id']]);
        $precio_id = (int) $old['id'];
    }} else {{
        $price_data['producto_base_id'] = $pid;
        $price_data['canal'] = 'local';
        $price_data['woocommerce_variation_id'] = 0;
        $price_data['factor_minimo'] = $factor_minimo;
        $price_data['factor_objetivo'] = 1.80;
        $price_data['factor_maximo_referencia'] = 3.00;
        $price_data['created_by_system'] = 1;
        $price_data['created_at'] = $now;
        if ($has_en_uso) {{
            $price_data['en_uso'] = 1;
        }}
        $wpdb->insert($precios, $price_data);
        $precio_id = (int) $wpdb->insert_id;
    }}

    // Historial solo si cambió precio/costo (no por solo flip de alerta).
    if ($has_hist && $price_changed) {{
        $margen_u = ($precio_neto !== null && $coste !== null) ? round($precio_neto - $coste, 3) : null;
        $ok = $wpdb->insert($hist, [
            'producto_base_id' => $pid,
            'canal' => 'local',
            'woocommerce_variation_id' => 0,
            'precio_sugerido' => isset($price_data['p_ref']) ? $price_data['p_ref'] : null,
            'precio_aprobado' => $precio,
            'precio_local' => $precio,
            'c_ref' => $coste,
            'p_asignado_anterior' => $old_p,
            'p_asignado_nuevo' => $precio,
            'margen_unitario' => $margen_u,
            'source_type' => 'import',
            'notas' => $notas_hist,
            'usuario_id' => 0,
            'created_at' => $now,
        ]);
        if ($ok) {{
            $hist_inserted++;
        }}
    }}

    if ($has_legacy) {{
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO {{$legacy}} (sku, nombre, costo_neto, precio_total, fuente, importado_at)
             VALUES (%s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre),
                costo_neto = VALUES(costo_neto),
                precio_total = VALUES(precio_total),
                fuente = VALUES(fuente),
                importado_at = VALUES(importado_at)",
            $sku,
            $item['nombre'] !== '' ? $item['nombre'] : null,
            $coste,
            $precio,
            $fuente,
            $now
        ));
        if ($ok !== false) {{
            $legacy_upsert++;
        }}
    }}
}}

$checks = [];
foreach (['21924', '29068', '21648', '1014'] as $sku) {{
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT pb.canonical_sku AS sku, pb.facto_iva_tipo, pr.c_ref, pr.p_asignado, pr.alerta_margen, pr.estado_aprobacion
         FROM {{$pb_table}} pb
         LEFT JOIN {{$precios}} pr
           ON pr.producto_base_id = pb.id AND pr.canal = 'local' AND pr.woocommerce_variation_id = 0
         WHERE pb.canonical_sku = %s
         LIMIT 1",
        $sku
    ), ARRAY_A);
    if ($row) {{
        $iva = strtolower(trim((string) ($row['facto_iva_tipo'] ?? 'afecto')));
        $bruto = ($row['p_asignado'] !== null && $row['p_asignado'] !== '') ? (float) $row['p_asignado'] : null;
        $row['p_neto'] = ($bruto === null) ? null : (($iva === 'exento') ? round($bruto, 3) : round($bruto / $iva_factor, 3));
    }}
    $checks[] = $row;
}}

$alertas_total = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {{$precios}} WHERE alerta_margen = 1 AND canal = 'local'"
);
$local_with_price = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {{$precios}} WHERE canal = 'local' AND p_asignado IS NOT NULL AND p_asignado > 0"
);
$tasks_contraparte = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {{$p}}tareas WHERE tipo = %s AND estado IN (%s, %s)",
    'crear_contraparte_local',
    'pendiente',
    'en_progreso'
));
$tasks_aprobar = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {{$p}}tareas WHERE tipo = %s AND estado IN (%s, %s)",
    'aprobar_lista_precios',
    'pendiente',
    'en_progreso'
));

echo json_encode([
    'dry_run' => $dry_run,
    'input' => count($items),
    'matched' => $matched,
    'missing_local' => $missing,
    'missing_sample' => $missing_sample,
    'updated' => $updated,
    'inserted_price' => $inserted_price,
    'overwrite' => $overwrite,
    'unchanged' => $unchanged,
    'coste_zero' => $coste_zero,
    'margen_bajo' => $margen_bajo,
    'legacy_upsert' => $legacy_upsert,
    'hist_inserted' => $hist_inserted,
    'would_create_tasks' => $would_create_tasks,
    'sample_changed' => $sample_changed,
    'checks' => $checks,
    'post' => [
        'local_with_price' => $local_with_price,
        'alertas_margen_local' => $alertas_total,
        'tasks_crear_contraparte_local' => $tasks_contraparte,
        'tasks_aprobar_lista_precios' => $tasks_aprobar,
    ],
    'error' => $wpdb->last_error,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
'''


def run(dry_run: bool):
    load_env()
    if not XLSX.is_file():
        raise SystemExit(f'No existe {XLSX}')

    items = load_items()
    print(f'TPV items: {len(items)} dry_run={dry_run}')

    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = build_php(wp, dry_run)

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/tpv_price_restore.json', 'w') as handle:
        handle.write(json.dumps(items, ensure_ascii=False))
    with sftp.open('/tmp/restore_tpv_prices.php', 'w') as handle:
        handle.write(php)
    sftp.close()

    mode = 'DRY-RUN' if dry_run else 'RESTORE'
    print(f'{mode}: ejecutando en producción...')
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=1024M /tmp/restore_tpv_prices.php',
        timeout=600,
    )
    out = stdout.read().decode()
    print(out)
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex-for-woocommerce' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print(clean)
    status = stdout.channel.recv_exit_status()
    ssh.close()
    if status != 0:
        raise SystemExit(f'Falló con status {status}')
    return out


def main():
    parser = argparse.ArgumentParser(description='Restaura precios/costos TPV a riverso_precios local')
    parser.add_argument('--dry-run', action='store_true', help='Solo conteos, no escribe')
    parser.add_argument('--apply', action='store_true', help='Escribe en producción')
    args = parser.parse_args()
    if not args.dry_run and not args.apply:
        parser.error('Indica --dry-run o --apply')
    if args.dry_run and args.apply:
        parser.error('Usa solo uno: --dry-run o --apply')
    run(dry_run=args.dry_run)


if __name__ == '__main__':
    main()
