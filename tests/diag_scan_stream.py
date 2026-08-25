#!/usr/bin/env python
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
for line in (ROOT / ".env.deploy").read_text(encoding="utf-8").splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip())

HOST = os.environ["RIVERSO_DEPLOY_HOST"]
USER = os.environ["RIVERSO_DEPLOY_USER"]
PASSWORD = os.environ["RIVERSO_DEPLOY_PASSWORD"]
WP = os.environ["RIVERSO_WP_PATH"]

PHP_SCRIPT = r"""
require WP_LOAD;
global $wpdb;
$t = $wpdb->prefix . 'riverso_documentos_archivos';
$rows = $wpdb->get_results("SELECT id, archivo_hash, nombre_original, mime, bytes, r2_key_original, estado FROM {$t} ORDER BY id DESC LIMIT 3", ARRAY_A);
echo "Recent archivos:\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    $ext = 'pdf';
    if (preg_match('/\.([a-z0-9]+)$/i', $r['r2_key_original'] ?? '', $m)) {
        $ext = strtolower($m[1]);
    }
    $upload = wp_upload_dir();
    $local = trailingslashit($upload['basedir']) . 'riverso-scans/' . preg_replace('/[^a-f0-9]/', '', $r['archivo_hash']) . '.' . $ext;
    echo "  local=$local exists=" . (is_readable($local) ? 'yes' : 'no') . "\n";
}
$dir = wp_upload_dir()['basedir'] . '/riverso-scans';
echo "Dir $dir exists=" . (is_dir($dir) ? 'yes' : 'no') . "\n";
if (is_dir($dir)) {
    $files = glob($dir . '/*') ?: [];
    echo "Files count: " . count($files) . "\n";
    foreach (array_slice($files, 0, 8) as $f) {
        if (is_file($f)) {
            echo "  " . basename($f) . " " . filesize($f) . "\n";
        }
    }
}
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-r2-client.php';
$r2 = new Riverso_R2_Client();
echo "R2 configured=" . ($r2->is_configured() ? 'yes' : 'no') . "\n";
if (!empty($rows[0]['r2_key_original'])) {
    $key = $rows[0]['r2_key_original'];
    $head = $r2->head_object($key);
    echo "R2 head: " . (is_wp_error($head) ? $head->get_error_message() : json_encode($head)) . "\n";
    $presigned = $r2->presigned_url($key, 300);
    echo "Presigned len=" . strlen($presigned) . "\n";
    echo "Presigned=" . $presigned . "\n";
}
""".replace("WP_LOAD", repr(WP + "/wp-load.php"))

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
_, out, _ = ssh.exec_command("ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1", timeout=30)
php = out.read().decode().strip()
cmd = f"sudo -u riverso.cl_1xybiw6rlcq {php} -d memory_limit=512M -r {repr(PHP_SCRIPT)}"
_, stdout, stderr = ssh.exec_command(cmd, timeout=120)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print("STDERR:", err)

# curl presigned from server if we got one
ssh.close()
