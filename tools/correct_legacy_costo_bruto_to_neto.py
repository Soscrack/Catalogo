#!/usr/bin/env python3
"""Corrige legacy_precio_ref.costo_neto: valores FACTO guardados como neto eran brutos.

Solo toca fuentes productos_xlsx_* (Excel FACTO). No toca productos_tpv ni precios.c_ref.

Dry-run por defecto. Con --apply escribe en producción vía SSH + wp-load.

Idempotencia: option riverso_legacy_costo_bruto_fixed; si ya está, 0 filas.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
OPTION_KEY = 'riverso_legacy_costo_bruto_fixed'
IVA_FACTOR = 1.19
SAMPLE_SIZE = 15


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


def build_php(wp_path: str, apply: bool) -> str:
    apply_flag = 'true' if apply else 'false'
    return f'''<?php
require_once '{wp_path}/wp-load.php';
global $wpdb;

$apply = {apply_flag};
$option_key = '{OPTION_KEY}';
$iva_factor = {IVA_FACTOR};
$sample_size = {SAMPLE_SIZE};
$p = $wpdb->prefix . 'riverso_';
$legacy = $p . 'legacy_precio_ref';
$pb = $p . 'producto_base';

$already = get_option($option_key);
if ($already) {{
    echo json_encode([
        'dry_run' => !$apply,
        'skipped' => true,
        'reason' => 'already_fixed',
        'option' => $already,
        'updated' => 0,
        'would_update' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}}

$rows = $wpdb->get_results(
    "SELECT l.id, l.sku, l.nombre, l.costo_neto, l.precio_neto, l.precio_total,
            l.fuente, l.importado_at, pb.facto_iva_tipo
     FROM `{{$legacy}}` l
     LEFT JOIN `{{$pb}}` pb ON pb.canonical_sku = l.sku
     WHERE l.fuente LIKE 'productos_xlsx_%'
       AND l.costo_neto IS NOT NULL
       AND l.costo_neto > 0",
    ARRAY_A
);

$by_fuente = [];
$by_iva = ['afecto' => 0, 'exento' => 0];
$by_criterio = ['ratio' => 0, 'producto_base' => 0, 'default_afecto' => 0];
$would = [];
$audit = [];

foreach ($rows as $row) {{
    $fuente = (string) $row['fuente'];
    if (!isset($by_fuente[$fuente])) {{
        $by_fuente[$fuente] = ['total' => 0, 'afecto' => 0, 'exento' => 0];
    }}
    $by_fuente[$fuente]['total']++;

    $antes = (float) $row['costo_neto'];
    $iva = null;
    $criterio = null;

    $pn = $row['precio_neto'] !== null && $row['precio_neto'] !== '' ? (float) $row['precio_neto'] : null;
    $pt = $row['precio_total'] !== null && $row['precio_total'] !== '' ? (float) $row['precio_total'] : null;
    if ($pn !== null && $pt !== null && $pn > 0.0001) {{
        $ratio = $pt / $pn;
        if (abs($ratio - $iva_factor) < 0.03) {{
            $iva = 'afecto';
            $criterio = 'ratio';
        }} elseif (abs($ratio - 1.0) < 0.03) {{
            $iva = 'exento';
            $criterio = 'ratio';
        }}
    }}

    if ($iva === null) {{
        $tipo = strtolower(trim((string) ($row['facto_iva_tipo'] ?? '')));
        if ($tipo === 'exento' || $tipo === 'afecto') {{
            $iva = $tipo;
            $criterio = 'producto_base';
        }}
    }}

    if ($iva === null) {{
        $iva = 'afecto';
        $criterio = 'default_afecto';
    }}

    $by_iva[$iva]++;
    $by_criterio[$criterio]++;
    $by_fuente[$fuente][$iva]++;

    if ($iva === 'exento') {{
        continue;
    }}

    $despues = round($antes / $iva_factor, 4);
    if (abs($despues - $antes) < 0.00005) {{
        continue;
    }}

    $entry = [
        'id' => (int) $row['id'],
        'sku' => $row['sku'],
        'nombre' => $row['nombre'],
        'fuente' => $fuente,
        'valor_antes' => $antes,
        'valor_despues' => $despues,
        'iva' => $iva,
        'criterio_iva' => $criterio,
        'facto_iva_tipo' => $row['facto_iva_tipo'],
        'precio_neto' => $pn,
        'precio_total' => $pt,
    ];
    $would[] = $entry;
    $audit[] = $entry;
}}

$updated = 0;
$error = '';

if ($apply && count($would) > 0) {{
    $wpdb->query('START TRANSACTION');
    foreach ($would as $entry) {{
        $ok = $wpdb->update(
            $legacy,
            ['costo_neto' => $entry['valor_despues']],
            ['id' => $entry['id']],
            ['%f'],
            ['%d']
        );
        if ($ok === false) {{
            $error = $wpdb->last_error ?: 'update failed';
            $wpdb->query('ROLLBACK');
            echo json_encode([
                'dry_run' => false,
                'error' => $error,
                'updated' => 0,
                'failed_sku' => $entry['sku'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit(1);
        }}
        $updated++;
    }}
    update_option($option_key, [
        'fixed_at' => current_time('mysql'),
        'updated' => $updated,
        'iva_factor' => $iva_factor,
        'fuentes' => array_keys($by_fuente),
    ], false);
    $wpdb->query('COMMIT');
}} elseif ($apply && count($would) === 0) {{
    update_option($option_key, [
        'fixed_at' => current_time('mysql'),
        'updated' => 0,
        'note' => 'nothing_to_fix',
    ], false);
}}

$sample = array_slice($would, 0, $sample_size);

echo json_encode([
    'dry_run' => !$apply,
    'skipped' => false,
    'candidates_total' => count($rows),
    'would_update' => count($would),
    'updated' => $updated,
    'skipped_exento_or_unchanged' => count($rows) - count($would),
    'by_fuente' => $by_fuente,
    'by_iva' => $by_iva,
    'by_criterio' => $by_criterio,
    'sample' => $sample,
    'audit_count' => count($audit),
    'audit' => $apply ? $audit : [],
    'error' => $error,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
'''


def run(apply: bool) -> dict:
    load_env()
    password = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
    if not password:
        raise SystemExit('Falta RIVERSO_DEPLOY_PASSWORD en .env.deploy')

    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = build_php(wp.replace("'", "\\'"), apply)

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    host = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
    user = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
    print(f'Conectando a {host} apply={apply}...')
    ssh.connect(host, username=user, password=password, timeout=30)

    remote_php = '/tmp/correct_legacy_costo_bruto_to_neto.php'
    sftp = ssh.open_sftp()
    with sftp.open(remote_php, 'w') as handle:
        handle.write(php)
    sftp.close()

    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        f'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" {remote_php}; '
        f'rm -f {remote_php}'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=600)
    out = stdout.read().decode(errors='replace')
    err = stderr.read().decode(errors='replace')
    code = stdout.channel.recv_exit_status()
    ssh.close()

    if err.strip():
        print('stderr:', err[:2000])
    if code != 0:
        print(out[:4000])
        raise SystemExit(f'PHP falló (exit {code})')

    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        print(out[:4000])
        raise SystemExit('Respuesta no es JSON válido')

    return data


def print_report(data: dict) -> None:
    if data.get('skipped'):
        print(f"SKIP: ya corregido ({data.get('reason')})")
        print(json.dumps(data.get('option'), ensure_ascii=False, indent=2))
        return

    mode = 'DRY-RUN' if data.get('dry_run') else 'APPLY'
    print(f"=== {mode} corrección legacy costo bruto→neto ===")
    print(f"Candidatos FACTO (costo>0): {data.get('candidates_total')}")
    print(f"A actualizar (afecto):      {data.get('would_update')}")
    print(f"Actualizados:               {data.get('updated')}")
    print(f"Omitidos (exento/igual):    {data.get('skipped_exento_or_unchanged')}")
    print('Por fuente:', json.dumps(data.get('by_fuente'), ensure_ascii=False, indent=2))
    print('Por IVA:', json.dumps(data.get('by_iva'), ensure_ascii=False))
    print('Por criterio IVA:', json.dumps(data.get('by_criterio'), ensure_ascii=False))
    print('Muestra (antes → después):')
    for row in data.get('sample') or []:
        print(
            f"  {row['sku']}: {row['valor_antes']} → {row['valor_despues']} "
            f"({row['criterio_iva']}/{row['iva']})"
        )


def main():
    parser = argparse.ArgumentParser(
        description='Corrige legacy_precio_ref.costo_neto FACTO (bruto→neto)'
    )
    parser.add_argument(
        '--apply',
        action='store_true',
        help='Escribe en BD (sin flag = dry-run)',
    )
    args = parser.parse_args()

    data = run(apply=args.apply)
    print_report(data)

    out_path = ROOT / 'tools' / '_correct_legacy_costo_last.json'
    out_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'Reporte JSON: {out_path}')


if __name__ == '__main__':
    main()
