<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
$keys = [
    'scan_r2_access_key_id',
    'scan_r2_secret_access_key',
    'scan_r2_endpoint',
    'scan_r2_bucket',
];
foreach ($keys as $k) {
    $v = riverso_get_setting($k, '(unset)');
    echo "$k=" . $v . "\n";
}
echo "via config access=" . riverso_get_scan_config('r2_access_key_id') . "\n";
echo "via config secret len=" . strlen(riverso_get_scan_config('r2_secret_access_key')) . "\n";
echo "masked=" . (preg_match('/\*{4,}/', riverso_get_scan_config('r2_access_key_id')) ? 'yes' : 'no') . "\n";
