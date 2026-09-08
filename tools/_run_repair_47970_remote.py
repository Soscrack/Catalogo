#!/usr/bin/env python
"""Upload and run tests/repair_folio_47970.php on the production server."""
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        raise SystemExit('Missing .env.deploy')
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


def main():
    load_env()
    apply = '--apply' in sys.argv
    host = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
    user = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
    password = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
    if not password:
        raise SystemExit('RIVERSO_DEPLOY_PASSWORD missing')

    local = ROOT / 'tests' / 'repair_folio_47970.php'
    remote = '/tmp/repair_folio_47970.php'
    if not local.is_file():
        raise SystemExit(f'Missing {local}')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f'Connecting to {host}...')
    ssh.connect(host, username=user, password=password, timeout=30)

    sftp = ssh.open_sftp()
    sftp.put(str(local), remote)
    sftp.close()
    print(f'Uploaded {remote}')

    flag = ' --apply' if apply else ''
    mode = 'APPLY' if apply else 'DRY-RUN'
    cmd = f'''
set -e
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
test -n "$PHP_BIN"
echo "=== {mode} ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" {remote}{flag}
'''
    stdin, stdout, stderr = ssh.exec_command(cmd, timeout=180)
    out = stdout.read().decode('utf-8', 'replace')
    err = stderr.read().decode('utf-8', 'replace')
    code = stdout.channel.recv_exit_status()
    print(out)
    if err.strip():
        interesting = [
            line for line in err.splitlines()
            if 'bluex' not in line.lower() and line.strip()
        ]
        if interesting or code != 0:
            print('STDERR:', '\n'.join(interesting[-40:] if interesting else err.splitlines()[-20:]))
    print('exit', code)
    ssh.close()
    raise SystemExit(code)


if __name__ == '__main__':
    main()
