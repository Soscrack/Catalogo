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

local_php = ROOT / "tests" / "diag_scan_stream.php"
remote_php = "/tmp/diag_scan_stream.php"

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
sftp.put(str(local_php), remote_php)
sftp.close()
_, out, _ = ssh.exec_command("ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1", timeout=30)
php = out.read().decode().strip()
cmd = f"sudo -u riverso.cl_1xybiw6rlcq {php} -d memory_limit=512M {remote_php}"
_, stdout, stderr = ssh.exec_command(cmd, timeout=180)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print("STDERR:", err)
ssh.exec_command(f"rm -f {remote_php}")
ssh.close()
