#!/usr/bin/env python3
"""Diagnose 504 on riverso.cl after deploy."""
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env = ROOT / '.env.deploy'
if env.is_file():
    for raw in env.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        k, v = line.split('=', 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ['RIVERSO_DEPLOY_PASSWORD']
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

cmds = [
    "ps aux | grep php | grep -v grep | head -25",
    f"sudo -u riverso.cl_1xybiw6rlcq $(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1) -r "
    f"\"require '{WP_PATH}/wp-load.php'; "
    f"echo 'db_version=' . get_option('riverso_pos_db_version') . PHP_EOL; "
    f"echo 'phase38=' . get_option('riverso_pos_phase38_family_decision') . PHP_EOL; "
    f"global \\$wpdb; "
    f"echo 'preguntar_tasks=' . (int)\\$wpdb->get_var(\\\"SELECT COUNT(*) FROM {{\\$wpdb->prefix}}riverso_tareas WHERE tipo='preguntar_familia' AND estado NOT IN ('completada','cancelada')\\\") . PHP_EOL; "
    f"echo 'active_php_ok';\" 2>&1 | tail -20",
]

for c in cmds:
    print('\n=== CMD ===')
    print(c[:200])
    stdin, stdout, stderr = ssh.exec_command(c, timeout=120)
    out = stdout.read().decode(errors='replace')
    err = stderr.read().decode(errors='replace')
    if out:
        print(out[:8000])
    if err:
        print('STDERR:', err[:2000])

ssh.close()
