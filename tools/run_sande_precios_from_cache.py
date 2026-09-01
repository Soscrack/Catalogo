#!/usr/bin/env python3
"""Upload cache + run CLI --from-json --force-historial on prod; verify schema."""
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
LOCAL_JSON = ROOT / "data" / "competencia" / "sande" / "raw" / "productos_237184.json"
REMOTE_JSON = "/tmp/productos_237184.json"
CLI = (
    "/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/"
    "riverso-pos/cli/sande-precios-refresh.php"
)


def load_env():
    path = ROOT / ".env.deploy"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=900):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    load_env()
    if not LOCAL_JSON.is_file():
        print(f"Falta {LOCAL_JSON}")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ["RIVERSO_DEPLOY_HOST"],
        username=os.environ.get("RIVERSO_DEPLOY_USER", "root"),
        password=os.environ["RIVERSO_DEPLOY_PASSWORD"],
        timeout=30,
    )

    # Sync updated CLI
    local_cli = ROOT / "php" / "riverso-pos" / "cli" / "sande-precios-refresh.php"
    sftp = ssh.open_sftp()
    print("Uploading CLI + JSON…")
    sftp.put(str(local_cli), CLI)
    sftp.put(str(LOCAL_JSON), REMOTE_JSON)
    sftp.close()
    run(ssh, f"chown riverso.cl_1xybiw6rlcq:psacln {CLI}")

    php = 'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)'
    print("=== dry-run from-json ===")
    code, out, err = run(
        ssh,
        f'{php}; "$PHP_BIN" {CLI} --dry-run --from-json={REMOTE_JSON}',
        timeout=120,
    )
    print(out)
    if err:
        print("STDERR:", err[:1000])
    if code != 0:
        ssh.close()
        return code

    print("=== refresh + force-historial ===")
    code, out, err = run(
        ssh,
        f'{php}; "$PHP_BIN" {CLI} --from-json={REMOTE_JSON} --force-historial',
        timeout=900,
    )
    print(out)
    if err:
        print("STDERR:", err[:1000])
    if code != 0:
        ssh.close()
        return code

    verify = r"""<?php
$c=file_get_contents('/var/www/vhosts/riverso.cl/httpdocs/wp-config.php');
function grab($c,$n){
  if(preg_match("/define\s*\(\s*['\"]".$n."['\"]\s*,\s*['\"]([^'\"]*)['\"]/",$c,$m)) return $m[1];
  throw new Exception($n);
}
if(!preg_match("/\$table_prefix\s*=\s*['\"]([^'\"]*)['\"]/",$c,$t)) throw new Exception('prefix');
$host=grab($c,'DB_HOST'); $port=3306;
if(strpos($host,':')!==false){ list($host,$port)=explode(':',$host,2); }
$db=new mysqli($host, grab($c,'DB_USER'), grab($c,'DB_PASSWORD'), grab($c,'DB_NAME'), (int)$port);
$p=$t[1].'riverso_';
$precios=(int)$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios")->fetch_assoc()['c'];
$hist=(int)$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios_historial")->fetch_assoc()['c'];
$ux=$db->query("SHOW INDEX FROM {$p}competencia_precios WHERE Key_name='ux_producto_id'")->num_rows;
$col=$db->query("SHOW COLUMNS FROM {$p}competencia_precios LIKE 'actualizado_at'")->num_rows;
$today=$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios WHERE snapshot_fecha=CURDATE()")->fetch_assoc()['c'];
$htoday=$db->query("SELECT COUNT(*) c FROM {$p}competencia_precios_historial WHERE snapshot_fecha=CURDATE()")->fetch_assoc()['c'];
echo "precios=$precios hist=$hist ux=$ux actualizado_at=$col vigentes_hoy=$today hist_hoy=$htoday\n";
"""
    remote = "/tmp/verify_phase40.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote, "w") as f:
        f.write(verify)
    sftp.close()
    print("=== verify ===")
    code2, out2, err2 = run(ssh, f'{php}; "$PHP_BIN" {remote}; rm -f {remote} {REMOTE_JSON}')
    print(out2)
    if err2:
        print("STDERR:", err2[:1000])
    ssh.close()
    return 0 if code == 0 and code2 == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
