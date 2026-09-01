#!/usr/bin/env python3
"""Crea o repara (idempotente) la tarea Plesk de precios Sande a las 02:00 America/Santiago."""
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
SCRIPT_REL = "httpdocs/wp-content/plugins/riverso-pos/cli/sande-precios-refresh.php"
LOG_DIR = (
    "/var/www/vhosts/riverso.cl/httpdocs/wp-content/uploads/riverso-logs"
)
DESC = "Sande precios 02:00 America/Santiago"
# Plesk subscription cron: usar -type php con versión completa (chroot no tiene /opt/plesk/php)
PHP_VERSION = os.environ.get("RIVERSO_SANDE_PHP_VERSION", "8.4.25")


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
        f"mkdir -p {LOG_DIR} && chown riverso.cl_1xybiw6rlcq:psacln {LOG_DIR} && chmod 775 {LOG_DIR} && echo ok",
    )
    print(out.strip(), err.strip(), "exit", code)

    print("=== list tasks ===")
    code, out, err = run(ssh, "plesk bin scheduler --list -subscription riverso.cl -json")
    rows = json.loads(out) if out.strip() else []
    task_id = find_task(rows)
    print("existing id:", task_id or "(ninguna)")

    script_arg = json.dumps(SCRIPT_REL)
    desc_arg = json.dumps(DESC)

    if task_id:
        print(f"=== update task {task_id} → type php {PHP_VERSION} ===")
        cmd = (
            f"plesk bin scheduler --update {task_id} "
            f"-subscription riverso.cl "
            f"-type php -php {PHP_VERSION} "
            f"-schedule '0 2 * * *' -active true -notify errors "
            f"-description {desc_arg} -command {script_arg}"
        )
    else:
        print("=== create task ===")
        cmd = (
            "plesk bin scheduler --create "
            f"-subscription riverso.cl "
            f"-type php -php {PHP_VERSION} "
            f"-schedule '0 2 * * *' -active true -notify errors "
            f"-description {desc_arg} -command {script_arg}"
        )

    code, out, err = run(ssh, cmd)
    print(out.strip())
    if err.strip():
        print("STDERR:", err.strip()[:2000])
    print("exit", code)

    print("=== list after ===")
    code2, out2, _ = run(ssh, "plesk bin scheduler --list -subscription riverso.cl -json")
    print(out2[:2500] if out2 else "")
    ssh.close()
    return 0 if code == 0 else code


if __name__ == "__main__":
    raise SystemExit(main())
