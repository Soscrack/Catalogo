#!/usr/bin/env python3
"""Sube lock/dedupe a producción, valida sintaxis y limpia clones."""
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    path = ROOT / '.env.deploy'
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=180):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode('utf-8', 'replace')
    err = stderr.read().decode('utf-8', 'replace')
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    load_env()
    apply = '--apply' in sys.argv
    host = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
    user = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
    password = os.environ['RIVERSO_DEPLOY_PASSWORD']
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    plugin = f'{wp}/wp-content/plugins/riverso-pos'

    files = [
        (
            ROOT / 'php/riverso-pos/modules/invoices/class-invoice-module.php',
            f'{plugin}/modules/invoices/class-invoice-module.php',
        ),
        (
            ROOT / 'php/riverso-pos/modules/integrations/facto/class-facto-inbox-import.php',
            f'{plugin}/modules/integrations/facto/class-facto-inbox-import.php',
        ),
        (
            ROOT / 'tests/repair_cloned_invoice_items.php',
            '/tmp/repair_cloned_invoice_items.php',
        ),
    ]

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=user, password=password, timeout=30)
    sftp = ssh.open_sftp()

    php_bin_cmd = 'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); echo $PHP_BIN'
    code, php_bin, _ = run(ssh, php_bin_cmd)
    php_bin = php_bin.strip()
    if not php_bin:
        raise SystemExit('No PHP bin')
    print('PHP', php_bin)

    for local, remote in files:
        tmp = '/tmp/' + Path(remote).name
        if remote.startswith('/tmp/'):
            tmp = remote
        print('upload', local.name, '->', tmp)
        sftp.put(str(local), tmp)
        lint = f'{php_bin} -l {tmp}'
        code, out, err = run(ssh, lint)
        print(out.strip() or err.strip())
        if code != 0:
            raise SystemExit(f'Syntax error in {local.name}')
        if remote != tmp:
            print('install', remote)
            run(ssh, f'cp {tmp} {remote} && chown riverso.cl_1xybiw6rlcq:psacln {remote}')

    sftp.close()

    flag = ' --apply' if apply else ''
    cmd = f'sudo -u riverso.cl_1xybiw6rlcq "{php_bin}" /tmp/repair_cloned_invoice_items.php{flag}'
    print('run', cmd)
    code, out, err = run(ssh, cmd, timeout=180)
    print(out)
    if err.strip():
        interesting = [l for l in err.splitlines() if 'bluex' not in l.lower() and l.strip()]
        if interesting:
            print('STDERR:', '\n'.join(interesting[-20:]))
    if code != 0:
        raise SystemExit(code)

    verify = r"""<?php
require_once '%s/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$n = (int) $wpdb->get_var("SELECT COUNT(*) FROM (
  SELECT factura_id FROM {$p}factura_items
  GROUP BY factura_id, numero_linea, codigo_proveedor, nombre, cantidad, precio_unitario
  HAVING COUNT(*) > 1
) t");
$f43 = $wpdb->get_row("SELECT COUNT(*) items, COUNT(DISTINCT numero_linea) lineas
  FROM {$p}factura_items WHERE factura_id=43", ARRAY_A);
echo "clones_restantes=$n folio49136_items={$f43['items']} lineas={$f43['lineas']}\n";
""" % wp
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/verify_clones.php', 'w') as handle:
        handle.write(verify)
    sftp.close()
    code, out, err = run(ssh, f'sudo -u riverso.cl_1xybiw6rlcq "{php_bin}" /tmp/verify_clones.php')
    print(out)
    ssh.close()
    raise SystemExit(code)


if __name__ == '__main__':
    main()
