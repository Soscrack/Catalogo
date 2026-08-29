#!/usr/bin/env python3
"""Verifica hidratación export SKU 22220."""
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
require_once '{WP_PATH}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-export-service.php';
$svc = new Riverso_Facto_Export_Service();
$filters = [
    'modo' => 'update_only',
    'pending_only' => true,
    'hydrate_from_facto' => true,
    'only_changed' => false,
    'include_archived' => false,
    'include_stock' => false,
    'sku' => '222433',
];
$rows = $svc->build_rows($filters);
foreach ($rows as $row) {{
    echo json_encode([
        'SKU' => $row['SKU'] ?? '',
        'Marca' => $row['Marca'] ?? '',
        'Stock minimo' => $row['Stock mínimo'] ?? '',
        'Precio neto' => $row['Venta: Precio neto'] ?? '',
        'Monto IVA' => $row['Venta: Monto IVA'] ?? '',
        'Precio total' => $row['Venta: Precio total'] ?? '',
        'Stock local total' => $row['_stock_local_total'] ?? '',
        'stock_map' => $row['_stock_by_location'] ?? [],
        'local_bodega' => !empty($row['_local_stock_bodega']),
        'hydrated' => !empty($row['_hydrated_from_facto']),
    ], JSON_UNESCAPED_UNICODE) . "\\n";
}}
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
with sftp.open('/tmp/fe_hydrate.php', 'w') as handle:
    handle.write(PHP)
sftp.close()
stdin, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/fe_hydrate.php; rm -f /tmp/fe_hydrate.php'
)
print(stdout.read().decode())
print(stderr.read().decode())
ssh.close()
