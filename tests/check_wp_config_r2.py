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

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
cmds = [
    f"grep -n 'RIVERSO_R2\\|R2_' {WP}/wp-config.php || true",
    f"grep -n 'scan_r2' {WP}/wp-content/plugins/riverso-pos/../.. 2>/dev/null | head -5 || true",
]
for c in cmds:
    _, stdout, stderr = ssh.exec_command(c, timeout=30)
    print('===', c)
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err:
        print(err)
ssh.close()
