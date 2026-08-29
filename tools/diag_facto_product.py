#!/usr/bin/env python3
"""Muestra GET /products/{id} de FACTO para SKUs de prueba."""
import json
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env_path = ROOT / '.env.deploy'
if env_path.is_file():
    for line in env_path.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ['RIVERSO_DEPLOY_PASSWORD']
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')

PHP = f"""<?php
require_once '{WP_PATH}/wp-load.php';
require_once '{WP_PATH}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';
global $wpdb;
$client = new Riverso_Facto_Client();
$skus = ['22220', '222433'];
foreach ($skus as $sku) {{
    $map = $wpdb->get_row($wpdb->prepare(
        "SELECT fm.facto_product_id, pb.marca, pb.facto_iva_tipo
         FROM {{$wpdb->prefix}}riverso_facto_producto_map fm
         INNER JOIN {{$wpdb->prefix}}riverso_producto_base pb ON pb.id = fm.producto_base_id
         WHERE pb.canonical_sku = %s",
        $sku
    ), ARRAY_A);
    echo "=== SKU $sku ===\\n";
    if (!$map) {{ echo "no map\\n"; continue; }}
    echo 'local_marca=' . ($map['marca'] ?? '') . ' iva=' . ($map['facto_iva_tipo'] ?? '') . "\\n";
    $remote = $client->get_product((int) $map['facto_product_id']);
    if (is_wp_error($remote)) {{
        echo 'error: ' . $remote->get_error_message() . "\\n";
        continue;
    }}
    echo json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\\n";
    echo 'keys=' . implode(',', array_keys($remote)) . "\\n";
}}
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
with sftp.open('/tmp/fe_prod.php', 'w') as handle:
    handle.write(PHP)
sftp.close()
stdin, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/fe_prod.php; rm -f /tmp/fe_prod.php'
)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('STDERR:', err)
ssh.close()
