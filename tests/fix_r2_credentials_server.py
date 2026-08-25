#!/usr/bin/env python
"""Restaura credenciales R2 en el servidor (pisan valores enmascarados corruptos)."""
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def load_env(path):
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k, v = k.strip(), v.strip().strip('"').strip("'")
        if k and k not in os.environ:
            os.environ[k] = v

load_env(ROOT / ".env.deploy")
load_env(ROOT / ".env")

HOST = os.environ.get("RIVERSO_DEPLOY_HOST", "72.61.37.37")
USER = os.environ.get("RIVERSO_DEPLOY_USER", "root")
PASSWORD = os.environ.get("RIVERSO_DEPLOY_PASSWORD")
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

ACCESS = os.environ.get("R2_ACCESS_KEY_ID") or os.environ.get("RIVERSO_R2_ACCESS_KEY_ID", "")
SECRET = os.environ.get("R2_SECRET_ACCESS_KEY") or os.environ.get("RIVERSO_R2_SECRET_ACCESS_KEY", "")
ENDPOINT = os.environ.get("R2_ENDPOINT") or os.environ.get("RIVERSO_R2_ENDPOINT", "")
BUCKET = os.environ.get("R2_BUCKET") or os.environ.get("RIVERSO_R2_BUCKET", "riverso-documentos")

if not PASSWORD or not ACCESS or not SECRET:
    raise SystemExit("Faltan RIVERSO_DEPLOY_PASSWORD o credenciales R2 en .env")

php_body = f"""<?php
require {repr(WP + '/wp-load.php')};
riverso_set_setting('scan_r2_access_key_id', {repr(ACCESS)});
riverso_set_setting('scan_r2_secret_access_key', {repr(SECRET)});
riverso_set_setting('scan_r2_endpoint', {repr(ENDPOINT)});
riverso_set_setting('scan_r2_bucket', {repr(BUCKET)});

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-r2-client.php';
$r2 = new Riverso_R2_Client();
echo 'configured=' . ($r2->is_configured() ? 'yes' : 'no') . "\\n";
global $wpdb;
$t = $wpdb->prefix . 'riverso_documentos_archivos';
$row = $wpdb->get_row("SELECT * FROM {{$t}} ORDER BY id DESC LIMIT 1", ARRAY_A);
if ($row && !empty($row['r2_key_original'])) {{
    $head = $r2->head_object($row['r2_key_original']);
    echo 'head=' . (is_wp_error($head) ? $head->get_error_message() : json_encode($head)) . "\\n";
    require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-scan-module.php';
    $mod = Riverso_Scan_Module::get_instance();
    $ref = new ReflectionClass($mod);
    $m = $ref->getMethod('ensure_local_archive');
    $m->setAccessible(true);
    $local = $m->invoke($mod, $row);
    echo 'local=' . (is_wp_error($local) ? $local->get_error_message() : $local) . "\\n";
}}
echo 'access_key=' . substr(riverso_get_scan_config('r2_access_key_id'), 0, 4) . '...' . "\\n";
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
remote = "/tmp/fix_r2_credentials.php"
with sftp.file(remote, "w") as f:
    f.write(php_body)
sftp.close()
_, out, _ = ssh.exec_command("ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1", timeout=30)
php = out.read().decode().strip()
_, stdout, stderr = ssh.exec_command(
    f"sudo -u riverso.cl_1xybiw6rlcq {php} -d memory_limit=512M {remote}",
    timeout=180,
)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print("STDERR:", err)
ssh.exec_command(f"rm -f {remote}")
ssh.close()
