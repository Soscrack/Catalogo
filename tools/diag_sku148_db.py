#!/usr/bin/env python3
"""Inspección DB SKU 148: precios, legacy, historial."""
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


def main():
    load_env()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = f'''<?php
require_once '{wp}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$pb = $wpdb->get_row("SELECT id, canonical_sku, nombre_canonico, facto_iva_tipo FROM {{$p}}producto_base WHERE canonical_sku='148' LIMIT 1", ARRAY_A);
echo 'pb=' . json_encode($pb, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$pb) {{ exit; }}
$id = (int) $pb['id'];
$pr = $wpdb->get_row($wpdb->prepare(
  "SELECT id,canal,c_ref,p_ref,p_asignado,updated_at FROM {{$p}}precios WHERE producto_base_id=%d AND canal='local' AND woocommerce_variation_id=0",
  $id
), ARRAY_A);
echo 'precio=' . json_encode($pr, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$leg = $wpdb->get_results($wpdb->prepare(
  "SELECT sku,costo_neto,precio_neto,precio_total,fuente,importado_at FROM {{$p}}legacy_precio_ref WHERE sku=%s ORDER BY importado_at DESC LIMIT 3",
  $pb['canonical_sku']
), ARRAY_A);
echo 'legacy=' . json_encode($leg, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$hist = $wpdb->get_results($wpdb->prepare(
  "SELECT id,c_ref,p_asignado_nuevo,source_type,source_document_id,created_at FROM {{$p}}precio_historial WHERE producto_base_id=%d AND canal='local' ORDER BY id DESC LIMIT 20",
  $id
), ARRAY_A);
echo 'hist=' . json_encode($hist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
'''
    # Fix double braces for f-string - I used {{$p}} wrongly. Rewrite cleanly.
    php = """<?php
require_once '%s/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$pb = $wpdb->get_row(\"SELECT id, canonical_sku, nombre_canonico, facto_iva_tipo FROM {$p}producto_base WHERE canonical_sku='148' LIMIT 1\", ARRAY_A);
echo 'pb=' . json_encode($pb, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$pb) { exit; }
$id = (int) $pb['id'];
$pr = $wpdb->get_row($wpdb->prepare(
  \"SELECT id,canal,c_ref,p_ref,p_asignado,updated_at FROM {$p}precios WHERE producto_base_id=%%d AND canal='local' AND woocommerce_variation_id=0\",
  $id
), ARRAY_A);
echo 'precio=' . json_encode($pr, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$leg = $wpdb->get_results($wpdb->prepare(
  \"SELECT sku,costo_neto,precio_neto,precio_total,fuente,importado_at FROM {$p}legacy_precio_ref WHERE sku=%%s ORDER BY importado_at DESC LIMIT 3\",
  $pb['canonical_sku']
), ARRAY_A);
echo 'legacy=' . json_encode($leg, JSON_UNESCAPED_UNICODE) . PHP_EOL;
$hist = $wpdb->get_results($wpdb->prepare(
  \"SELECT id,c_ref,p_asignado_nuevo,source_type,source_document_id,created_at FROM {$p}precio_historial WHERE producto_base_id=%%d AND canal='local' ORDER BY id DESC LIMIT 20\",
  $id
), ARRAY_A);
echo 'hist=' . json_encode($hist, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
""" % wp

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag148b.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag148b.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=60)
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        print('STDERR:', err[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
