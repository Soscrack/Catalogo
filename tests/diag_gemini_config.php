<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';

function riverso_diag_secret($label, $val) {
    $val = (string) $val;
    $len = strlen($val);
    $masked = $len && function_exists('riverso_is_masked_secret') && riverso_is_masked_secret($val);
    $prefix = $len >= 3 ? substr($val, 0, 3) : $val;
    echo "$label: empty=" . ($val === '' ? 'yes' : 'no') . " len=$len prefix=$prefix masked=" . ($masked ? 'yes' : 'no') . "\n";
}

echo "helpers-scan=" . (function_exists('riverso_get_scan_config') ? 'yes' : 'no') . "\n";
echo "defined RIVERSO_GEMINI_API_KEY=" . (defined('RIVERSO_GEMINI_API_KEY') ? 'yes' : 'no') . "\n";
if (defined('RIVERSO_GEMINI_API_KEY')) {
    riverso_diag_secret('const RIVERSO_GEMINI_API_KEY', RIVERSO_GEMINI_API_KEY);
}
echo "defined RIVERSO_GEMINI_MODEL=" . (defined('RIVERSO_GEMINI_MODEL') ? ('yes=' . RIVERSO_GEMINI_MODEL) : 'no') . "\n";
echo "getenv RIVERSO_GEMINI_API_KEY len=" . strlen((string) getenv('RIVERSO_GEMINI_API_KEY')) . "\n";
echo "getenv GEMINI_API_KEY len=" . strlen((string) getenv('GEMINI_API_KEY')) . "\n";

$s = get_option('riverso_pos_settings', []);
riverso_diag_secret('setting scan_gemini_api_key', $s['scan_gemini_api_key'] ?? '');
echo "setting scan_gemini_model=" . ($s['scan_gemini_model'] ?? '(unset)') . "\n";

if (function_exists('riverso_get_scan_config')) {
    riverso_diag_secret('config gemini_api_key', riverso_get_scan_config('gemini_api_key', ''));
    echo "config gemini_model=" . riverso_get_scan_config('gemini_model', '') . "\n";
    riverso_diag_secret('config r2_access', riverso_get_scan_config('r2_access_key_id', ''));
    riverso_diag_secret('config r2_secret', riverso_get_scan_config('r2_secret_access_key', ''));
    echo "config r2_endpoint=" . riverso_get_scan_config('r2_endpoint', '') . "\n";
    echo "config r2_bucket=" . riverso_get_scan_config('r2_bucket', '') . "\n";
}

if (class_exists('Riverso_Gemini_Client')) {
    $g = new Riverso_Gemini_Client();
    echo "gemini is_configured=" . ($g->is_configured() ? 'yes' : 'no') . "\n";
    echo "gemini model=" . $g->get_model() . "\n";
} else {
    echo "class Riverso_Gemini_Client missing\n";
}

global $wpdb;
$table = $wpdb->prefix . 'riverso_documentos_archivos';
$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
echo "table exists=" . ($exists ? 'yes' : 'no') . "\n";
if ($exists) {
    $rows = $wpdb->get_results(
        "SELECT id, estado, error_mensaje, gemini_llamadas, created_at FROM {$table} ORDER BY id DESC LIMIT 8",
        ARRAY_A
    );
    echo "recent archivos:\n";
    foreach ($rows ?: [] as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$wpconfig = ABSPATH . 'wp-config.php';
echo "wp-config exists=" . (is_file($wpconfig) ? 'yes' : 'no') . "\n";
if (is_file($wpconfig)) {
    $txt = file_get_contents($wpconfig);
    echo "wp-config has RIVERSO_GEMINI_API_KEY=" . (strpos($txt, 'RIVERSO_GEMINI_API_KEY') !== false ? 'yes' : 'no') . "\n";
    echo "wp-config has RIVERSO_R2_ACCESS_KEY_ID=" . (strpos($txt, 'RIVERSO_R2_ACCESS_KEY_ID') !== false ? 'yes' : 'no') . "\n";
}
