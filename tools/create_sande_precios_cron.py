#!/usr/bin/env python3
"""Instala el cron de precios Sande a las 02:00 America/Santiago.

Plesk programa en UTC del servidor, así que `0 2 * * *` en la tarea 164
corría a las 22:00 de Chile. La fuente de verdad es /etc/cron.d con CRON_TZ.
La tarea Plesk se deja inactiva para poder usar «Ejecutar ahora» sin duplicar.
"""
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
PHP_BIN = "/opt/plesk/php/8.4/bin/php"
SCRIPT = (
    "/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/"
    "riverso-pos/cli/sande-precios-refresh.php"
)
LOG_DIR = "/var/www/vhosts/riverso.cl/httpdocs/wp-content/uploads/riverso-logs"
CRON_FILE = "/etc/cron.d/riverso-sande-precios"
USER = "riverso.cl_1xybiw6rlcq"
DESC = "Sande precios 02:00 America/Santiago"
CRON_BODY = f"""# Precios Sande: 02:00 America/Santiago (respeta horario de verano)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/bin
CRON_TZ=America/Santiago
0 2 * * * {USER} {PHP_BIN} {SCRIPT}
"""


def load_env():
    path = ROOT / ".env.deploy"
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=60):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def find_task(rows):
    for row in rows:
        blob = json.dumps(row)
        if "sande-precios" in blob or DESC in blob or "sande-precios-refresh" in blob:
            return str(row.get("id") or "")
    return None


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

    print("=== prepare log dir ===")
    code, out, err = run(
        ssh,
        f"mkdir -p {LOG_DIR} && chown {USER}:psacln {LOG_DIR} && chmod 775 {LOG_DIR} && echo ok",
    )
    print(out.strip(), err.strip(), "exit", code)

    print("=== write cron.d ===")
    sftp = ssh.open_sftp()
    with sftp.file(CRON_FILE, "w") as handle:
        handle.write(CRON_BODY)
    sftp.close()
    run(ssh, f"chmod 644 {CRON_FILE} && echo installed {CRON_FILE}")
    _, out, _ = run(ssh, f"cat {CRON_FILE}")
    print(out.strip())

    print("=== deactivate Plesk duplicate (keep for Run now) ===")
    code, out, err = run(ssh, "plesk bin scheduler --list -subscription riverso.cl -json")
    rows = json.loads(out) if out.strip() else []
    task_id = find_task(rows)
    if task_id:
        code, out, err = run(
            ssh,
            f"plesk bin scheduler --update {task_id} -active false 2>&1",
        )
        print((out or err).strip()[:800], "exit", code)
    else:
        print("no Plesk task found")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
