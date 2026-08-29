#!/usr/bin/env python3
"""Restaura la API key Gemini en producción y despliega el fix del plugin."""
from __future__ import annotations

import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    if not path.exists():
        return env
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        env.setdefault(key.strip(), value.strip().strip('"').strip("'"))
    return env


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 60) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


RESTORE_PHP = r"""<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
$in = json_decode(stream_get_contents(STDIN), true);
$key = trim((string) ($in['gemini_api_key'] ?? ''));
$model = trim((string) ($in['gemini_model'] ?? 'gemini-3.6-flash'));
if ($key === '' || preg_match('/\*{4,}/', $key)) {
    fwrite(STDERR, "invalid key\n");
    exit(1);
}
$settings = get_option('riverso_pos_settings', []);
$settings['scan_gemini_api_key'] = $key;
$settings['scan_gemini_model'] = $model;
update_option('riverso_pos_settings', $settings);
$stored = (string) (get_option('riverso_pos_settings', [])['scan_gemini_api_key'] ?? '');
echo 'stored_len=' . strlen($stored) . PHP_EOL;
echo 'stored_masked=' . (preg_match('/\*{4,}/', $stored) ? 'yes' : 'no') . PHP_EOL;
echo 'config_len=' . strlen((string) riverso_get_scan_config('gemini_api_key', '')) . PHP_EOL;
echo 'configured=' . ((string) riverso_get_scan_config('gemini_api_key', '') !== '' ? 'yes' : 'no') . PHP_EOL;
"""

WPCONFIG_PHP = r"""<?php
$wp = '/var/www/vhosts/riverso.cl/httpdocs/wp-config.php';
$in = json_decode(file_get_contents('/tmp/restore_gemini.json'), true);
$key = trim((string) ($in['gemini_api_key'] ?? ''));
if ($key === '') {
    fwrite(STDERR, "no key\n");
    exit(1);
}
$src = file_get_contents($wp);
if (strpos($src, 'RIVERSO_GEMINI_API_KEY') !== false) {
    echo "wp-config already has constant\n";
    exit(0);
}
$define = "\n/* Riverso POS — Gemini */\ndefine('RIVERSO_GEMINI_API_KEY', " . var_export($key, true) . ");\ndefine('RIVERSO_GEMINI_MODEL', 'gemini-3.6-flash');\n";
$needles = ["/* That's all, stop editing!", "That's all, stop editing", "Eso es todo", "deja de editar"];
$inserted = false;
foreach ($needles as $n) {
    $pos = strpos($src, $n);
    if ($pos !== false) {
        $src = substr($src, 0, $pos) . $define . substr($src, $pos);
        $inserted = true;
        break;
    }
}
if (!$inserted) {
    $src .= $define;
}
$bak = $wp . '.bak-gemini-' . date('YmdHis');
copy($wp, $bak);
file_put_contents($wp, $src);
echo 'wp-config inserted bak=' . basename($bak) . PHP_EOL;
"""

DIAG_PHP = r"""<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
echo 'helpers_fn=' . (function_exists('riverso_scan_gemini_configured') ? 'yes' : 'no') . PHP_EOL;
echo 'defined_const=' . (defined('RIVERSO_GEMINI_API_KEY') ? 'yes' : 'no') . PHP_EOL;
$s = get_option('riverso_pos_settings', []);
$stored = (string) ($s['scan_gemini_api_key'] ?? '');
echo 'stored_len=' . strlen($stored) . ' stored_masked=' . (preg_match('/\*{4,}/', $stored) ? 'yes' : 'no') . PHP_EOL;
$cfg = (string) riverso_get_scan_config('gemini_api_key', '');
echo 'config_len=' . strlen($cfg) . ' config_masked=' . (preg_match('/\*{4,}/', $cfg) ? 'yes' : 'no') . PHP_EOL;
$g = new Riverso_Gemini_Client();
echo 'is_configured=' . ($g->is_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'model=' . $g->get_model() . PHP_EOL;
"""


def main() -> int:
    deploy = load_env(ROOT / ".env.deploy")
    local = load_env(ROOT / ".env")
    for key, value in deploy.items():
        os.environ.setdefault(key, value)

    api_key = local.get("GEMINI_API_KEY", "").strip()
    model = local.get("GEMINI_MODEL", "gemini-3.6-flash").strip() or "gemini-3.6-flash"
    if not api_key or "****" in api_key:
        raise SystemExit("GEMINI_API_KEY local inválida")
    print(f"local_key_len={len(api_key)} prefix={api_key[:3]}")

    host = os.environ["RIVERSO_DEPLOY_HOST"]
    user = os.environ["RIVERSO_DEPLOY_USER"]
    password = os.environ["RIVERSO_DEPLOY_PASSWORD"]
    wp_path = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
    plugin = f"{wp_path}/wp-content/plugins/riverso-pos"

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=user, password=password, timeout=30)

    code, out, err = run(ssh, f"grep -n \"stop editing\\|That's all\\|deja de editar\" {wp_path}/wp-config.php | head")
    print("WPCONFIG_MARKERS:", out.strip() or err.strip())

    code, out, _ = run(ssh, "ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1")
    php = out.strip()
    print("PHP", php)

    sftp = ssh.open_sftp()
    with sftp.file("/tmp/restore_gemini.php", "w") as handle:
        handle.write(RESTORE_PHP)
    with sftp.file("/tmp/restore_gemini.json", "w") as handle:
        handle.write(json.dumps({"gemini_api_key": api_key, "gemini_model": model}))
    with sftp.file("/tmp/insert_gemini_wpconfig.php", "w") as handle:
        handle.write(WPCONFIG_PHP)
    run(ssh, "chmod 644 /tmp/restore_gemini.php /tmp/insert_gemini_wpconfig.php")
    run(ssh, "chmod 600 /tmp/restore_gemini.json")

    code, out, err = run(
        ssh,
        f"sudo -u riverso.cl_1xybiw6rlcq {php} /tmp/restore_gemini.php < /tmp/restore_gemini.json",
    )
    print("RESTORE", out.strip())
    if err.strip():
        print("RESTORE_ERR", err.strip())
    if code != 0:
        raise SystemExit(f"restore failed: {code}")

    code, out, err = run(ssh, f"{php} /tmp/insert_gemini_wpconfig.php")
    print("WPCONFIG", out.strip())
    if err.strip():
        print("WPCONFIG_ERR", err.strip())

    code, out, _ = run(ssh, f"stat -c %U:%G {plugin}/includes/helpers-scan.php")
    owner = out.strip() or "riverso.cl_1xybiw6rlcq:psacln"
    print("OWNER", owner)

    files = [
        ("php/riverso-pos/includes/helpers-scan.php", f"{plugin}/includes/helpers-scan.php"),
        ("php/riverso-pos/modules/scans/class-gemini-client.php", f"{plugin}/modules/scans/class-gemini-client.php"),
        ("php/riverso-pos/modules/scans/class-scan-module.php", f"{plugin}/modules/scans/class-scan-module.php"),
        ("php/riverso-pos/templates/settings.php", f"{plugin}/templates/settings.php"),
        ("php/riverso-pos/templates/invoices-scans.php", f"{plugin}/templates/invoices-scans.php"),
    ]
    for local_rel, remote in files:
        local_path = ROOT / local_rel
        print("PUT", local_rel)
        sftp.put(str(local_path), remote)
        run(ssh, f"chown {owner} {remote}")

    with sftp.file("/tmp/diag_gemini_after.php", "w") as handle:
        handle.write(DIAG_PHP)
    sftp.close()

    run(ssh, "rm -f /tmp/restore_gemini.php /tmp/restore_gemini.json /tmp/insert_gemini_wpconfig.php")
    run(ssh, "systemctl reload plesk-php84-fpm 2>/dev/null || systemctl reload php8.4-fpm 2>/dev/null || true")

    code, out, err = run(
        ssh,
        f"sudo -u riverso.cl_1xybiw6rlcq {php} /tmp/diag_gemini_after.php",
    )
    print("DIAG", out.strip())
    if err.strip():
        print("DIAG_ERR", err.strip())
    run(ssh, "rm -f /tmp/diag_gemini_after.php")
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
