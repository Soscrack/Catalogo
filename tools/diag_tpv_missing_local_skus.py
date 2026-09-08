#!/usr/bin/env python3
"""Compara SKUs TPV/Excel contra producto_base en producción."""
import csv
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


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


def load_skus():
    tpv = ROOT / 'TPV' / 'productos_legacy.csv'
    skus = set()
    with tpv.open(encoding='utf-8') as handle:
        for row in csv.DictReader(handle):
            sku = (row.get('sku') or '').strip()
            if sku:
                skus.add(sku)
    return sorted(skus)


def main():
    load_env()
    skus = load_skus()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = f"""<?php
require_once '{wp}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$lines = file('/tmp/tpv_skus.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$lines = array_map('trim', $lines);
$found = $wpdb->get_col("SELECT canonical_sku FROM {{$p}}producto_base WHERE canonical_sku IS NOT NULL AND canonical_sku <> '' AND deleted_at IS NULL");
$found_map = array_fill_keys($found, true);
$missing = [];
foreach ($lines as $sku) {{
    if (!isset($found_map[$sku])) {{
        $missing[] = $sku;
    }}
}}
$legacy_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {{$p}}legacy_precio_ref");
$legacy_missing = 0;
foreach ($missing as $sku) {{
    $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {{$p}}legacy_precio_ref WHERE sku=%s", $sku));
    if ($n === 0) {{
        $legacy_missing++;
    }}
}}
echo json_encode([
    'tpv' => count($lines),
    'producto_base' => count($found),
    'missing_local' => count($missing),
    'legacy_precio_ref' => $legacy_count,
    'missing_also_not_in_legacy_ref' => $legacy_missing,
    'sample' => array_slice($missing, 0, 40),
], JSON_UNESCAPED_UNICODE);
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
    with sftp.open('/tmp/tpv_skus.txt', 'w') as handle:
        handle.write('\n'.join(skus) + '\n')
    with sftp.open('/tmp/diag_tpv_missing.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_tpv_missing.php',
        timeout=120,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        print(err[:1500])
    ssh.close()


if __name__ == '__main__':
    main()
