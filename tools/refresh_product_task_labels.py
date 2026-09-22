#!/usr/bin/env python3
"""Despliega refresh de títulos de tareas y alinea las abiertas al nombre canónico actual."""
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        raise SystemExit('Falta .env.deploy')
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=180):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    load_env()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    plugin = f'{wp}/wp-content/plugins/riverso-pos'
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    files = [
        (
            ROOT / 'php/riverso-pos/core/tasks/class-task-module.php',
            f'{plugin}/core/tasks/class-task-module.php',
        ),
        (
            ROOT / 'php/riverso-pos/modules/products/class-product-module.php',
            f'{plugin}/modules/products/class-product-module.php',
        ),
    ]
    sftp = ssh.open_sftp()
    code, php_bin, _ = run(ssh, 'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); echo $PHP_BIN')
    php_bin = php_bin.strip()
    if not php_bin:
        raise SystemExit('No PHP')
    for local, remote in files:
        tmp = '/tmp/' + Path(remote).name
        print('upload', local.name, flush=True)
        sftp.put(str(local), tmp)
        lint_code, lint_out, lint_err = run(ssh, f'{php_bin} -l {tmp}')
        print((lint_out or lint_err).strip(), flush=True)
        if lint_code != 0:
            raise SystemExit(f'Syntax error {local.name}')
        run(ssh, f'cp {tmp} {remote} && chown riverso.cl_1xybiw6rlcq:psacln {remote}')
        print('installed', remote, flush=True)

    local_php = ROOT / 'tools/_refresh_task_labels.php'
    print('upload backfill', flush=True)
    sftp.put(str(local_php), '/tmp/refresh_task_labels.php')
    sftp.close()
    lint_code, lint_out, lint_err = run(ssh, f'{php_bin} -l /tmp/refresh_task_labels.php')
    print((lint_out or lint_err).strip(), flush=True)
    if lint_code != 0:
        raise SystemExit('Syntax error refresh_task_labels.php')
    print('running bulk refresh', flush=True)
    code, out, err = run(
        ssh,
        'sudo -u riverso.cl_1xybiw6rlcq "' + php_bin + '" /tmp/refresh_task_labels.php',
        timeout=180,
    )
    print(out, flush=True)
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex' not in ln and 'Failed to open stream' not in ln and 'Warning:' not in ln
        )
        if clean.strip():
            print('STDERR:', clean[:3000], flush=True)
    if code != 0:
        raise SystemExit(f'backfill exit {code}')
    ssh.close()


if __name__ == '__main__':
    main()
