#!/usr/bin/env python
import os, paramiko
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
for line in (ROOT / ".env.deploy").read_text(encoding="utf-8").splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1); os.environ.setdefault(k.strip(), v.strip())
ssh = paramiko.SSHClient(); ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = ssh.open_sftp(); sftp.put(str(ROOT / "tests/diag_r2_settings.php"), "/tmp/diag_r2_settings.php"); sftp.close()
_, out, _ = ssh.exec_command("ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1", timeout=30)
php = out.read().decode().strip()
_, stdout, stderr = ssh.exec_command(f"sudo -u riverso.cl_1xybiw6rlcq {php} /tmp/diag_r2_settings.php", timeout=60)
print(stdout.read().decode()); print(stderr.read().decode())
ssh.close()
