#!/usr/bin/env python3
"""Lista encabezados export FACTO (orden columnas bodega)."""
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
$schema = $svc->get_export_schema(true);
$headers = $schema['headers'];
foreach ($headers as $i => $h) {{
    $col = $i + 1;
    $letter = '';
    $n = $col;
    while ($n > 0) {{
        $n--;
        $letter = chr(65 + ($n % 26)) . $letter;
        $n = intdiv($n, 26);
    }}
    echo $letter . "\\t" . $h . "\\n";
}}
echo "---locations---\\n";
foreach ($schema['locations'] as $loc) {{
    echo ($loc['name'] ?? '') . "|" . ($loc['id'] ?? '') . "\\n";
}}
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
with sftp.open('/tmp/fe_headers.php', 'w') as handle:
    handle.write(PHP)
sftp.close()
stdin, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/fe_headers.php; rm -f /tmp/fe_headers.php'
)
print(stdout.read().decode())
err = stderr.read().decode()
if err.strip():
    print('stderr:', err)
ssh.close()
