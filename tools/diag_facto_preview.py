#!/usr/bin/env python
"""Ejecuta preview FACTO export en servidor."""
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

$cases = [
    'pending_update' => [
        'modo' => 'update_only',
        'pending_only' => true,
        'only_changed' => false,
        'include_archived' => false,
        'include_stock' => false,
        'sku' => '',
        'tanda' => 1,
    ],
    'pending_upsert' => [
        'modo' => 'upsert',
        'pending_only' => true,
        'only_changed' => false,
        'include_archived' => false,
        'include_stock' => false,
        'sku' => '',
        'tanda' => 1,
    ],
    'pending_only_str' => [
        'modo' => 'update_only',
        'pending_only' => '1',
        'only_changed' => '0',
        'include_archived' => '0',
        'include_stock' => '0',
        'sku' => '',
        'tanda' => '1',
    ],
];

foreach ($cases as $label => $filters) {{
    $rows = $svc->build_rows($filters);
    $preview = $svc->preview($filters);
    echo $label . ': rows=' . count($rows) . ' preview_total=' . ($preview['total'] ?? '?') . "\\n";
}}

echo 'pending_summary=' . json_encode($svc->get_pending_crud_summary(5), JSON_UNESCAPED_UNICODE) . "\\n";
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
with sftp.open('/tmp/fe_diag2.php', 'w') as handle:
    handle.write(PHP)
sftp.close()
stdin, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/fe_diag2.php; rm -f /tmp/fe_diag2.php'
)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('STDERR:', err)
ssh.close()
