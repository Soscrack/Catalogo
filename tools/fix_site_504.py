#!/usr/bin/env python3
"""Restart PHP-FPM and test site response."""
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

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

cmds = [
    "pkill -u riverso.cl_1xybiw6rlcq -f 'php-fpm: pool riverso.cl' 2>/dev/null; sleep 1; echo killed_workers",
    "systemctl reload plesk-php84-fpm 2>/dev/null || service plesk-php84-fpm reload 2>/dev/null || echo reload_skipped",
    "curl -s -o /dev/null -w 'home=%{http_code} t=%{time_total}\\n' --max-time 30 https://riverso.cl/ || echo home_fail",
    "curl -s -o /dev/null -w 'admin=%{http_code} t=%{time_total}\\n' --max-time 30 https://riverso.cl/wp-admin/ || echo admin_fail",
    "curl -s -o /dev/null -w 'interno=%{http_code} t=%{time_total}\\n' --max-time 30 https://riverso.cl/interno/ || echo interno_fail",
]

for c in cmds:
    print('\n>>>', c)
    stdin, stdout, stderr = ssh.exec_command(c, timeout=45)
    print(stdout.read().decode(errors='replace'))
    err = stderr.read().decode(errors='replace')
    if err.strip():
        print('err:', err[:500])

ssh.close()
