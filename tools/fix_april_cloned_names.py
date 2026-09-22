#!/usr/bin/env python3
"""Corrige nombres canónicos clonados en el dump TPV de abril 2026.

Las víctimas son SKU que en CodigosBarra/productos_2026-04-01.csv copiaron
nombre+precio+stock de otro SKU, y cuyo TPV de septiembre ya tiene otro nombre.
Solo se actualiza producto_base (y tienda_local) si el nombre actual sigue
siendo el de abril. No pisa ediciones humanas posteriores.

Uso:
  python tools/fix_april_cloned_names.py
  python tools/fix_april_cloned_names.py --apply
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import re
import sys
from collections import defaultdict
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
APRIL = ROOT / 'CodigosBarra' / 'productos_2026-04-01.csv'
TPV = ROOT / 'TPV' / 'productos_legacy.csv'
SPACE = re.compile(r'\s+', re.UNICODE)


def load_env() -> None:
    path = ROOT / '.env.deploy'
    if not path.is_file():
        raise SystemExit('Falta .env.deploy')
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def collapse(name: str) -> str:
    return SPACE.sub(' ', (name or '').strip())


def load_april() -> dict[str, dict]:
    rows: dict[str, dict] = {}
    with APRIL.open(encoding='utf-8-sig', newline='') as handle:
        for row in csv.DictReader(handle, delimiter=';'):
            sku = (row.get('codigo') or '').strip()
            if not sku:
                continue
            rows[sku] = {
                'nombre': collapse((row.get('nombre') or '').strip().strip('"')),
                'precio': (row.get('precio') or '').strip(),
                'stock': (row.get('stock') or '').strip(),
            }
    return rows


def load_tpv() -> dict[str, str]:
    rows: dict[str, str] = {}
    with TPV.open(encoding='utf-8', newline='') as handle:
        for row in csv.DictReader(handle):
            sku = (row.get('sku') or '').strip()
            nombre = collapse(row.get('nombre') or '')
            if sku and nombre:
                rows[sku] = nombre
    return rows


def build_victims() -> list[dict]:
    april = load_april()
    tpv = load_tpv()
    groups: dict[tuple, list[str]] = defaultdict(list)
    for sku, data in april.items():
        groups[(data['nombre'], data['precio'], data['stock'])].append(sku)

    victims = []
    for (_nombre, _precio, _stock), skus in groups.items():
        if len(skus) < 2:
            continue
        for sku in skus:
            april_name = april[sku]['nombre']
            tpv_name = tpv.get(sku, '')
            if not tpv_name or tpv_name == april_name:
                continue
            keepers = [s for s in skus if s != sku]
            victims.append({
                'sku': sku,
                'abril': april_name,
                'tpv': tpv_name,
                'clon_de': keepers,
            })
    victims.sort(key=lambda item: item['sku'])
    return victims


def build_php(apply: bool) -> str:
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    apply_flag = 'true' if apply else 'false'
    return f'''<?php
require_once '{wp}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$apply = {apply_flag};
$items = json_decode(file_get_contents('/tmp/april_cloned_names.json'), true);
if (!is_array($items)) {{
    fwrite(STDERR, "JSON inválido\\n");
    exit(1);
}}

$out = [
    'apply' => $apply,
    'input' => count($items),
    'updated' => 0,
    'tienda_local_updated' => 0,
    'skipped_already_ok' => 0,
    'skipped_conflict' => 0,
    'missing' => 0,
    'facto_pending' => 0,
    'tasks_refreshed' => 0,
    'rows' => [],
    'conflicts' => [],
    'missing_skus' => [],
];

$now = current_time('mysql');
$map_table = $p . 'facto_producto_map';
$has_map = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map_table)) === $map_table);
$tl_table = $p . 'tienda_local_productos';
$has_tl = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tl_table)) === $tl_table);

foreach ($items as $item) {{
    $sku = trim((string) ($item['sku'] ?? ''));
    $abril = trim((string) ($item['abril'] ?? ''));
    $tpv = trim((string) ($item['tpv'] ?? ''));
    if ($sku === '' || $tpv === '') {{
        continue;
    }}
    $pb = $wpdb->get_row($wpdb->prepare(
        "SELECT id, canonical_sku, nombre_canonico, origen_datos, woocommerce_product_id
         FROM {{$p}}producto_base
         WHERE canonical_sku = %s AND deleted_at IS NULL
         LIMIT 1",
        $sku
    ), ARRAY_A);
    if (!$pb) {{
        $out['missing']++;
        if (count($out['missing_skus']) < 30) {{
            $out['missing_skus'][] = $sku;
        }}
        continue;
    }}
    $actual = trim(preg_replace('/\\s+/u', ' ', (string) ($pb['nombre_canonico'] ?? '')));
    $row = [
        'id' => (int) $pb['id'],
        'sku' => $sku,
        'actual' => $actual,
        'abril' => $abril,
        'tpv' => $tpv,
        'origen' => $pb['origen_datos'],
        'woo_id' => (int) ($pb['woocommerce_product_id'] ?? 0),
        'action' => '',
    ];
    if ($actual === $tpv) {{
        $row['action'] = 'already_ok';
        $out['skipped_already_ok']++;
        $out['rows'][] = $row;
        continue;
    }}
    if ($abril !== '' && $actual !== $abril) {{
        $row['action'] = 'conflict';
        $out['skipped_conflict']++;
        $out['conflicts'][] = $row;
        $out['rows'][] = $row;
        continue;
    }}
    $row['action'] = $apply ? 'updated' : 'would_update';
    if (!$apply) {{
        $out['updated']++;
    }}
    if ($apply) {{
        $ok = $wpdb->update(
            "{{$p}}producto_base",
            [
                'nombre_canonico' => $tpv,
                'updated_at' => $now,
            ],
            ['id' => (int) $pb['id']],
            ['%s', '%s'],
            ['%d']
        );
        if ($ok === false) {{
            $row['action'] = 'error';
            $row['error'] = $wpdb->last_error;
            $out['rows'][] = $row;
            continue;
        }}
        $out['updated']++;
        if ($has_tl) {{
            $tl_ok = $wpdb->update(
                $tl_table,
                ['nombre' => $tpv],
                ['sku' => $sku],
                ['%s'],
                ['%s']
            );
            if ($tl_ok) {{
                $out['tienda_local_updated']++;
            }}
        }}
        if ($has_map) {{
            $map_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {{$map_table}} WHERE producto_base_id = %d",
                (int) $pb['id']
            ));
            if ($map_id) {{
                $wpdb->update(
                    $map_table,
                    ['sync_state' => 'pendiente_excel', 'updated_at' => $now],
                    ['id' => (int) $map_id]
                );
                $out['facto_pending']++;
            }}
        }}
        if (class_exists('Riverso_POS_Audit')) {{
            Riverso_POS_Audit::log_system('product_updated', 'producto_base', (int) $pb['id'], [
                'entity_name' => $sku,
                'old_value' => ['nombre_canonico' => $actual],
                'new_value' => ['nombre_canonico' => $tpv, 'fuente' => 'tpv_septiembre_vs_abril_clon'],
                'details' => sprintf('Nombre víctima dump abril: «%s» → «%s»', $actual, $tpv),
            ]);
        }}
        if (class_exists('Riverso_Task_Module') && method_exists('Riverso_Task_Module', 'refresh_open_product_task_labels')) {{
            $n = Riverso_Task_Module::get_instance()->refresh_open_product_task_labels((int) $pb['id']);
            $out['tasks_refreshed'] += (int) $n;
        }}
    }}
    $out['rows'][] = $row;
}}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
'''


def run_remote(php: str, payload: list[dict], remote_name: str) -> dict:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/april_cloned_names.json', 'w') as handle:
        handle.write(json.dumps(payload, ensure_ascii=False))
    with sftp.open(f'/tmp/{remote_name}', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        f'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/{remote_name}'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=300)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    ssh.close()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex' not in ln and 'Failed to open stream' not in ln and 'Warning:' not in ln
        )
        if clean.strip():
            print(clean[:3000], file=sys.stderr)
    try:
        return json.loads(out)
    except json.JSONDecodeError:
        print(out[:5000], file=sys.stderr)
        raise SystemExit('Respuesta PHP no es JSON válido')


def print_summary(data: dict) -> None:
    print('=== fix_april_cloned_names ===')
    print(
        f"apply={data.get('apply')} input={data.get('input')} "
        f"would/updated={data.get('updated')} already_ok={data.get('skipped_already_ok')} "
        f"conflicts={data.get('skipped_conflict')} missing={data.get('missing')} "
        f"tienda_local={data.get('tienda_local_updated')} facto_pending={data.get('facto_pending')}"
    )
    pending = [r for r in (data.get('rows') or []) if r.get('action') in ('would_update', 'updated')]
    print(f'\nA corregir ({len(pending)}):')
    for row in pending:
        print(f"  {row.get('sku')}: {row.get('actual')!r} → {row.get('tpv')!r}")
    conflicts = data.get('conflicts') or []
    if conflicts:
        print(f'\nConflictos (nombre ya no es el de abril, no se toca) ({len(conflicts)}):')
        for row in conflicts[:25]:
            print(
                f"  {row.get('sku')}: actual={row.get('actual')!r} "
                f"abril={row.get('abril')!r} tpv={row.get('tpv')!r}"
            )


def main() -> None:
    parser = argparse.ArgumentParser(description='Corrige nombres clonados del dump TPV abril 2026')
    parser.add_argument('--apply', action='store_true', help='Escribe en producción')
    args = parser.parse_args()

    if not APRIL.is_file() or not TPV.is_file():
        raise SystemExit('Faltan CSV de abril o TPV')

    load_env()
    victims = build_victims()
    print(f'Víctimas locales (abril clon ≠ TPV sep): {len(victims)}')
    php = build_php(apply=args.apply)
    remote = 'fix_april_names_apply.php' if args.apply else 'fix_april_names_dry.php'
    data = run_remote(php, victims, remote)
    print_summary(data)


if __name__ == '__main__':
    main()
