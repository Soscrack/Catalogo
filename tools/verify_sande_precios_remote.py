#!/usr/bin/env python3
"""Dry-run sande precios CLI on prod + verify schema counts."""
import os
import re
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    path = ROOT / ".env.deploy"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=700):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    load_env()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ["RIVERSO_DEPLOY_HOST"],
        username=os.environ.get("RIVERSO_DEPLOY_USER", "root"),
        password=os.environ["RIVERSO_DEPLOY_PASSWORD"],
        timeout=30,
    )
    cli = (
        "/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/"
        "riverso-pos/cli/sande-precios-refresh.php"
    )
    print("=== dry-run ===")
    code, out, err = run(
        ssh,
        "PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); "
        f'test -n "$PHP_BIN" && "$PHP_BIN" {cli} --dry-run',
        timeout=700,
    )
    print(out)
    if err:
        print("STDERR:", err[:2000])
    print("exit", code)

    # verify via remote mysql using wp-config parsed on server by the CLI's sibling approach
    verify_php = r"""
<?php
$c = file_get_contents('/var/www/vhosts/riverso.cl/httpdocs/wp-config.php');
function grab($c,$n){ if(!preg_match("/define\s*\(\s*'".$n."'\s*,\s*'([^']*)'/",$c,$m)) throw new Exception($n); return $m[1]; }
preg_match("/\$table_prefix\s*=\s*'([^']*)'/",$c,$t);
$host=grab($c,'DB_HOST'); $port=3306;
if(strpos($host,':')!==false){ list($host,$port)=explode(':',$host,2); }
$db=new mysqli($host, grab($c,'DB_USER'), grab($c,'DB_PASSWORD'), grab($c,'DB_NAME'), (int)$port);
$p=$t[1].'riverso_';
$precios=$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios")->fetch_assoc()['c'];
$hist=$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios_historial")->fetch_assoc()['c'];
$ux=$db->query("SHOW INDEX FROM {$p}competencia_precios WHERE Key_name='ux_producto_id'")->num_rows;
$col=$db->query("SHOW COLUMNS FROM {$p}competencia_precios LIKE 'actualizado_at'")->num_rows;
echo "precios=$precios hist=$hist ux_producto_id=$ux actualizado_at=$col\n";
"""
    remote = "/tmp/verify_phase40.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote, "w") as f:
        f.write(verify_php)
    sftp.close()
    print("=== verify ===")
    code2, out2, err2 = run(
        ssh,
        "PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); "
        f'test -n "$PHP_BIN" && "$PHP_BIN" {remote} && rm -f {remote}',
    )
    print(out2)
    if err2:
        print("STDERR:", err2[:1000])
    ssh.close()
    return 0 if code == 0 and code2 == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
