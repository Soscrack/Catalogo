#!/usr/bin/env python3
import os
from pathlib import Path
import paramiko

ROOT = Path(__file__).resolve().parents[1]
for raw in (ROOT / '.env.deploy').read_text(encoding='utf-8').splitlines():
    line = raw.strip()
    if not line or line.startswith('#') or '=' not in line:
        continue
    k, v = line.split('=', 1)
    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    os.environ['RIVERSO_DEPLOY_HOST'],
    username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
    password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
    timeout=30,
)
cmd = (
    f"grep -n \"RIVERSO_POS_VERSION\\|te-view-changes-btn\\|Columnas que cambian\\|ob_end_clean\" "
    f"{wp}/wp-content/plugins/riverso-pos/riverso-pos.php "
    f"{wp}/wp-content/plugins/riverso-pos/templates/tpv-export.php "
    f"{wp}/wp-content/plugins/riverso-pos/modules/integrations/tpv/class-tpv-module.php "
    f"| head -40"
)
_, stdout, _ = ssh.exec_command(cmd, timeout=30)
print(stdout.read().decode())
ssh.close()
