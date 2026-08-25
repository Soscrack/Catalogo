<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-scan-module.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-r2-client.php';
global $wpdb;
$t = $wpdb->prefix . 'riverso_documentos_archivos';
$rows = $wpdb->get_results("SELECT * FROM {$t} WHERE r2_key_original IS NOT NULL AND r2_key_original != '' ORDER BY id ASC", ARRAY_A);
$mod = Riverso_Scan_Module::get_instance();
$ref = new ReflectionClass($mod);
$m = $ref->getMethod('ensure_local_archive');
$m->setAccessible(true);
$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $local = $m->invoke($mod, $row);
    if (is_wp_error($local)) {
        echo "FAIL id={$row['id']}: " . $local->get_error_message() . "\n";
        $fail++;
    } else {
        echo "OK id={$row['id']} " . basename($local) . " " . filesize($local) . "\n";
        $ok++;
    }
}
echo "Done ok=$ok fail=$fail\n";
