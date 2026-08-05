#!/usr/bin/env python
"""Smoke test remoto de Riverso POS catálogo 1.5.x."""

import paramiko

from deploy_plugin import HOST, PASSWORD, PLUGIN_PATH, USER, WP_PATH


def run(ssh, command, timeout=300):
    _, stdout, stderr = ssh.exec_command(command, timeout=timeout)
    output = stdout.read().decode().strip()
    error = stderr.read().decode().strip()
    status = stdout.channel.recv_exit_status()
    if status:
        raise RuntimeError(error or output or f"Remote command failed: {status}")
    return output, error


def main():
    if not PASSWORD:
        raise RuntimeError("Define RIVERSO_DEPLOY_PASSWORD antes de verificar.")

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    php, _ = run(ssh, "ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1")
    php_code = r'''
require "wp-load.php";
delete_transient(Riverso_Catalog_Health_Module::LOCK_KEY);
$result = Riverso_Catalog_Health_Module::get_instance()->scan();
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message());
    exit(1);
}
echo "scan=" . wp_json_encode($result) . PHP_EOL;
$barcode = Riverso_Barcode_Model::resolve("2000148003008");
echo "barcode=" . ($barcode ? wp_json_encode($barcode) : "not-found") . PHP_EOL;
echo "summary=" . wp_json_encode(Riverso_Catalog_Health_Module::get_summary()) . PHP_EOL;
$invalid = Riverso_EAN13_Generator::build("K02TABB", 100);
echo "strict_ean=" . (is_wp_error($invalid) ? $invalid->get_error_code() : $invalid) . PHP_EOL;
global $wpdb;
$tasks = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}riverso_tareas WHERE referencia_tipo = 'data_gap_rule' AND estado IN ('pendiente','en_progreso')");
$draft = $wpdb->get_var("SELECT estado FROM {$wpdb->prefix}riverso_price_rules WHERE codigo = 'TORNILLO-LEGACY' LIMIT 1");
echo "grouped_tasks=" . intval($tasks) . PHP_EOL;
echo "price_rule=" . ($draft ?: "missing") . PHP_EOL;
echo "cron=" . (wp_next_scheduled(Riverso_Catalog_Health_Module::CRON_HOOK) ? "scheduled" : "missing") . PHP_EOL;
'''
    command = (
        f"cd {WP_PATH} && "
        f"sudo -u riverso.cl_1xybiw6rlcq {php} -r "
        + "'" + php_code.replace("'", "'\"'\"'") + "'"
    )
    output, warnings = run(ssh, command)

    version, _ = run(
        ssh,
        f"grep -E 'Version:|RIVERSO_POS_VERSION' {PLUGIN_PATH}/riverso-pos.php | head -2",
    )
    print(version)
    print(output)
    if warnings:
        print(warnings)

    ssh.close()


if __name__ == "__main__":
    main()
