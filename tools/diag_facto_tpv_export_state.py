#!/usr/bin/env python3
"""Diagnóstico: estado Riverso vs CSV TPV + FACTO (SKU 29731, 129/12900, barcodes, lotes)."""
import csv
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(os.path.expanduser(r'~\Downloads\codigos_barra.csv'))
SKU_REMAP = {'129': '12900'}


def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        raise SystemExit(f'Falta {path}')
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def parse_tpv_csv(path: Path):
    rows = []
    empty = 0
    with_codes = 0
    multi = 0
    codes = []  # (sku_canon, codigo)
    if not path.is_file():
        raise SystemExit(f'No existe CSV: {path}')
    with path.open(encoding='utf-8-sig', newline='') as handle:
        reader = csv.DictReader(handle)
        for raw in reader:
            sku = (raw.get('sku') or '').strip()
            if not sku:
                continue
            sku = SKU_REMAP.get(sku, sku)
            field = (raw.get('codigo_barras') or '').strip()
            if not field:
                empty += 1
                rows.append({'sku': sku, 'codes': []})
                continue
            parts = [p.strip() for p in field.split('|') if p.strip()]
            with_codes += 1
            if len(parts) > 1:
                multi += 1
            rows.append({'sku': sku, 'codes': parts})
            for c in parts:
                codes.append((sku, c))
    return {
        'sku_rows': len(rows),
        'empty': empty,
        'with_codes': with_codes,
        'multi': multi,
        'code_pairs': codes,
        'unique_skus': sorted({r['sku'] for r in rows}),
    }


def main():
    load_env()
    tpv = parse_tpv_csv(CSV_PATH)
    # Subir pares sku|codigo para cobertura remota
    pairs_txt = '\n'.join(f'{s}\t{c}' for s, c in tpv['code_pairs'])

    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = f"""<?php
require_once '{wp}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$out = [];

foreach (['29731', '129', '12900'] as $sku) {{
    $pb = $wpdb->get_row($wpdb->prepare(
        "SELECT id, canonical_sku, nombre_canonico, estado, deleted_at, archived_at, origen_datos
         FROM {{$p}}producto_base WHERE canonical_sku = %s LIMIT 1",
        $sku
    ), ARRAY_A);
    $entry = ['sku' => $sku, 'producto_base' => $pb];
    if ($pb) {{
        $pid = (int) $pb['id'];
        $entry['precio'] = $wpdb->get_row($wpdb->prepare(
            "SELECT p_asignado, c_ref, estado_aprobacion FROM {{$p}}precios
             WHERE producto_base_id = %d AND canal = 'local' AND woocommerce_variation_id = 0",
            $pid
        ), ARRAY_A);
        $entry['facto_map'] = $wpdb->get_row($wpdb->prepare(
            "SELECT id, facto_product_id, facto_sku, sync_state, last_error FROM {{$p}}facto_producto_map
             WHERE producto_base_id = %d",
            $pid
        ), ARRAY_A);
        $entry['barcodes'] = $wpdb->get_results($wpdb->prepare(
            "SELECT id, codigo, tipo, estado, activo, origen_datos FROM {{$p}}codigo_barra
             WHERE producto_base_id = %d ORDER BY id",
            $pid
        ), ARRAY_A);
        $entry['legacy_tasks'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {{$p}}tareas
             WHERE tipo = 'confirmar_barcode_legacy'
               AND estado NOT IN ('completada','cancelada')
               AND referencia_id IN (SELECT id FROM {{$p}}codigo_barra WHERE producto_base_id = %d)",
            $pid
        ));
    }}
    $out['skus'][$sku] = $entry;
}}

$out['tpv_batches'] = $wpdb->get_results(
    "SELECT id, total_productos, total_barcodes, estado, created_at, applied_at
     FROM {{$p}}tpv_export_batches ORDER BY id DESC LIMIT 5",
    ARRAY_A
);
$out['facto_batches'] = $wpdb->get_results(
    "SELECT id, estado, created_at, applied_at FROM {{$p}}facto_export_batches ORDER BY id DESC LIMIT 5",
    ARRAY_A
);
$out['pending_excel'] = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {{$p}}facto_producto_map WHERE sync_state = 'pendiente_excel'"
);
$out['codigo_barra_total'] = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {{$p}}codigo_barra WHERE activo = 1"
);
$out['legacy_barcode_tasks_open'] = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {{$p}}tareas
     WHERE tipo = 'confirmar_barcode_legacy' AND estado NOT IN ('completada','cancelada')"
);

// Cobertura CSV vs codigo_barra
$lines = file('/tmp/tpv_barcode_pairs.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$found = 0;
$missing = 0;
$missing_sku = 0;
$sample_missing = [];
$sku_cache = [];
foreach ($lines as $line) {{
    $parts = explode("\\t", $line, 2);
    if (count($parts) < 2) continue;
    $sku = trim($parts[0]);
    $code = trim($parts[1]);
    if ($sku === '' || $code === '') continue;
    if (!isset($sku_cache[$sku])) {{
        $sku_cache[$sku] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {{$p}}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
    }}
    $pid = $sku_cache[$sku];
    if ($pid <= 0) {{
        $missing_sku++;
        if (count($sample_missing) < 30) {{
            $sample_missing[] = ['sku' => $sku, 'codigo' => $code, 'reason' => 'sku_missing'];
        }}
        continue;
    }}
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {{$p}}codigo_barra WHERE producto_base_id = %d AND codigo = %s",
        $pid, $code
    ));
    if ($exists > 0) {{
        $found++;
    }} else {{
        $missing++;
        if (count($sample_missing) < 30) {{
            $sample_missing[] = ['sku' => $sku, 'codigo' => $code, 'reason' => 'barcode_missing'];
        }}
    }}
}}
$out['csv_coverage'] = [
    'pairs' => count($lines),
    'found_in_codigo_barra' => $found,
    'missing_barcode' => $missing,
    'missing_sku' => $missing_sku,
    'sample_missing' => $sample_missing,
];
$out['legacy_csv_readable'] = is_readable('{wp}/wp-content/plugins/riverso-pos/data/tpv/productos_legacy.csv');

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/tpv_barcode_pairs.txt', 'w') as handle:
        handle.write(pairs_txt + '\n')
    with sftp.open('/tmp/diag_facto_tpv_state.php', 'w') as handle:
        handle.write(php)
    sftp.close()

    print('=== CSV local ===')
    print(json.dumps({
        'sku_rows': tpv['sku_rows'],
        'empty': tpv['empty'],
        'with_codes': tpv['with_codes'],
        'multi': tpv['multi'],
        'code_pairs': len(tpv['code_pairs']),
        'has_129': '129' in tpv['unique_skus'],
        'has_12900': '12900' in tpv['unique_skus'],
        'has_29731': '29731' in tpv['unique_skus'],
    }, indent=2))

    print('=== Producción ===')
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_facto_tpv_state.php',
        timeout=300,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex-for-woocommerce' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print(clean[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
