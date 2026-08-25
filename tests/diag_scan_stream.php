<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
global $wpdb;
$t = $wpdb->prefix . 'riverso_documentos_archivos';
$rows = $wpdb->get_results("SELECT id, archivo_hash, nombre_original, mime, bytes, r2_key_original, estado FROM {$t} ORDER BY id DESC LIMIT 3", ARRAY_A);
echo "Recent archivos:\n";
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    $ext = 'pdf';
    if (preg_match('/\.([a-z0-9]+)$/i', $r['r2_key_original'] ?? '', $m)) {
        $ext = strtolower($m[1]);
    }
    $upload = wp_upload_dir();
    $local = trailingslashit($upload['basedir']) . 'riverso-scans/' . preg_replace('/[^a-f0-9]/', '', $r['archivo_hash']) . '.' . $ext;
    echo "  local=$local exists=" . (is_readable($local) ? 'yes' : 'no') . " size=" . (is_readable($local) ? filesize($local) : 0) . "\n";
}
$dir = wp_upload_dir()['basedir'] . '/riverso-scans';
echo "Dir $dir exists=" . (is_dir($dir) ? 'yes' : 'no') . "\n";
if (is_dir($dir)) {
    $files = glob($dir . '/*') ?: [];
    echo "Files count: " . count($files) . "\n";
    foreach (array_slice($files, 0, 8) as $f) {
        if (is_file($f)) {
            echo "  " . basename($f) . " " . filesize($f) . "\n";
        }
    }
}
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-r2-client.php';
$r2 = new Riverso_R2_Client();
echo "R2 configured=" . ($r2->is_configured() ? 'yes' : 'no') . "\n";
if (!empty($rows[0]['r2_key_original'])) {
    $key = $rows[0]['r2_key_original'];
    $head = $r2->head_object($key);
    echo "R2 head: " . (is_wp_error($head) ? $head->get_error_message() : json_encode($head)) . "\n";
    $presigned = $r2->presigned_url($key, 300);
    echo "Presigned=$presigned\n";
    if ($presigned) {
        $ch = curl_init($presigned);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_exec($ch);
        echo "Presigned HEAD HTTP=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
        curl_close($ch);
    }
    $dest = $dir . '/diag-test.part';
    $dl = $r2->download_object($key, $dest);
    echo "Download: " . (is_wp_error($dl) ? $dl->get_error_message() : 'ok size=' . filesize($dest)) . "\n";
    @unlink($dest);
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/scans/class-scan-module.php';
$mod = Riverso_Scan_Module::get_instance();
$ref = new ReflectionClass($mod);
$m = $ref->getMethod('ensure_local_archive');
$m->setAccessible(true);
if (!empty($rows[0])) {
    $local = $m->invoke($mod, $rows[0]);
    echo "ensure_local_archive: " . (is_wp_error($local) ? $local->get_error_message() : $local) . "\n";
}
