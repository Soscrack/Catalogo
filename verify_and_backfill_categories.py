#!/usr/bin/env python
import paramiko

HOST = "72.61.37.37"
USER = "root"
PASSWORD = "9.#R/S12yE(4LRSTOMaB"
WP_PATH = "/var/www/vhosts/riverso.cl/httpdocs"
PLUGIN_PATH = f"{WP_PATH}/wp-content/plugins/riverso-pos"
LOCAL_JSON = "data/catalogo_mamut_2025_spatial.json"
REMOTE_JSON = "/tmp/riverso_catalogo_mamut_2025_spatial.json"


def run(ssh, command, timeout=60):
    stdin, stdout, stderr = ssh.exec_command(command, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    return out, err


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    checks = [
        ("version", f"grep 'Version:' '{PLUGIN_PATH}/riverso-pos.php' | head -1"),
        ("const", f"grep 'RIVERSO_POS_VERSION' '{PLUGIN_PATH}/riverso-pos.php' | head -1"),
        ("dynamic_levels", f"grep -c 'catalog-cat-level-input' '{PLUGIN_PATH}/templates/portal/portal-main.php'"),
        ("path_fix", f"grep -c 'path\\[\\] = \\$ancestor->name' '{PLUGIN_PATH}/modules/publish/class-woo-publisher-module.php'"),
    ]
    for label, command in checks:
        out, err = run(ssh, command)
        print(f"{label}: {out.strip()}")
        if err.strip():
            print(f"{label} err: {err.strip()}")

    print(f"Uploading {LOCAL_JSON} -> {REMOTE_JSON} ...")
    sftp = ssh.open_sftp()
    sftp.put(LOCAL_JSON, REMOTE_JSON)
    sftp.close()
    print("Upload complete")

    command = (
        f"cd '{WP_PATH}' && "
        f"PATH='/opt/plesk/php/8.3/bin:/usr/local/bin:/usr/bin:/bin' "
        f"wp riverso-publish backfill-categories "
        f"--json-path='{REMOTE_JSON}' --per-batch=100 --allow-root"
    )
    print(f"Running: {command}")
    out, err = run(ssh, command, timeout=240)
    print(out)
    if err.strip():
        print("ERR:", err)

    ssh.close()


if __name__ == "__main__":
    main()
