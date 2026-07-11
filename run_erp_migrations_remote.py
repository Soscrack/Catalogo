#!/usr/bin/env python
"""Ejecuta migraciones y smoke checks en producción vía WP-CLI + MySQL."""
import paramiko

HOST = "72.61.37.37"
USER = "root"
PASSWORD = "9.#R/S12yE(4LRSTOMaB"
PHP = "/opt/plesk/php/8.3/bin/php"
WP = "/var/www/vhosts/riverso.cl/httpdocs"
WPCLI = f"{PHP} /usr/local/bin/wp --path={WP} --allow-root"


def run(ssh, cmd, timeout=180):
    print(">>", cmd[:140])
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace").strip()
    err = stderr.read().decode(errors="replace").strip()
    if out:
        print(out)
    if err:
        # filtrar warnings mysql password
        lines = [l for l in err.splitlines() if "Warning" not in l and l.strip()]
        if lines:
            print("ERR:", "\n".join(lines)[:800])
    print()
    return out


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    run(ssh, f"{WPCLI} option get riverso_pos_db_version")

    eval_php = (
        "if (class_exists('Riverso_POS_Activator')) { "
        "Riverso_POS_Activator::update_database(); "
        "echo 'OK migrated to ' . RIVERSO_POS_VERSION; "
        "} else { echo 'NO Activator'; }"
    )
    run(ssh, f"{WPCLI} eval \"{eval_php}\"")

    run(ssh, f"{WPCLI} option get riverso_pos_db_version")
    run(ssh, f"{WPCLI} plugin status riverso-pos")

    classes = (
        "echo (class_exists('Riverso_Event_Bus')?'EventBus OK':'EventBus FAIL');"
        "echo '|';"
        "echo (class_exists('Riverso_Barcode_Model')?'Barcode OK':'Barcode FAIL');"
        "echo '|';"
        "echo (class_exists('Riverso_Woo_Sync_Manager')?'Sync OK':'Sync FAIL');"
        "echo '|';"
        "echo (class_exists('Riverso_Purchase_Order_Module')?'PO OK':'PO FAIL');"
        "echo '|';"
        "echo (class_exists('Riverso_Movement')?'Movement OK':'Movement FAIL');"
        "echo '|';"
        "echo (class_exists('Riverso_Task_Service')?'TaskService OK':'TaskService FAIL');"
    )
    run(ssh, f"{WPCLI} eval \"{classes}\"")

    mysql = (
        "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -N -e "
        "\"SHOW TABLES LIKE 'nExLU_riverso_%';\" "
        "| egrep 'codigo_barra|empleados|ordenes_compra|reservas|conteos|woo_sync|tarea_historial|precio_historial' "
        "|| true"
    )
    run(ssh, mysql)

    # cron sync scheduled?
    cron = (
        "echo (wp_next_scheduled('riverso_pos_sync_stock') "
        "? ('CRON OK '.wp_next_scheduled('riverso_pos_sync_stock')) "
        ": 'CRON NOT SCHEDULED');"
    )
    run(ssh, f"{WPCLI} eval \"{cron}\"")

    # force schedule if missing
    schedule = (
        "if (!wp_next_scheduled('riverso_pos_sync_stock')) {"
        "wp_schedule_event(time()+3600,'hourly','riverso_pos_sync_stock');"
        "echo 'CRON SCHEDULED';"
        "} else { echo 'CRON ALREADY'; }"
    )
    run(ssh, f"{WPCLI} eval \"{schedule}\"")

    ssh.close()
    print("Done.")


if __name__ == "__main__":
    main()
