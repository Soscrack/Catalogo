#!/usr/bin/env python
"""Check AJAX hook collisions for catalog_* actions on remote."""
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parent

def load_env(path):
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

load_env(ROOT / ".env.deploy")
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

php_diag = r'''
require "WP_PATH/wp-load.php";
global $wp_filter;
$actions = [
  "wp_ajax_riverso_catalog_list",
  "wp_ajax_riverso_catalog_get",
  "wp_ajax_riverso_catalogs_list",
  "wp_ajax_riverso_catalogs_get",
  "wp_ajax_riverso_catalog_save",
  "wp_ajax_riverso_catalog_search_products",
  "wp_ajax_riverso_catalogs_search_products",
];
foreach ($actions as $h) {
  echo "$h: ";
  if (!isset($wp_filter[$h])) { echo "(none)\n"; continue; }
  $parts=[];
  foreach ($wp_filter[$h]->callbacks as $prio=>$cbs) {
    foreach ($cbs as $cb) {
      $fn=$cb["function"];
      if (is_array($fn)) {
        $cls=is_object($fn[0])?get_class($fn[0]):$fn[0];
        $parts[] = $cls . "::" . $fn[1] . "@" . $prio;
      } else {
        $parts[] = (is_string($fn)?$fn:"callable") . "@" . $prio;
      }
    }
  }
  echo implode(" | ", $parts) . "\n";
}
'''
php_diag = php_diag.replace("WP_PATH", WP)

php_cookie = r'''
require "WP_PATH/wp-load.php";
$user_id=1;
$expiration=time()+DAY_IN_SECONDS;
$cookie=wp_generate_auth_cookie($user_id,$expiration,"logged_in");
file_put_contents("/tmp/riverso_cookie.txt", LOGGED_IN_COOKIE."=".$cookie);
$nonce=wp_create_nonce("riverso_pos_nonce");
file_put_contents("/tmp/riverso_nonce.txt", $nonce);
global $wpdb;
$pid=(int)$wpdb->get_var("SELECT woocommerce_product_id FROM {$wpdb->prefix}riverso_producto_base WHERE woocommerce_product_id IS NOT NULL LIMIT 1");
file_put_contents("/tmp/riverso_pid.txt", (string)$pid);
echo "pid=$pid\n";
'''
php_cookie = php_cookie.replace("WP_PATH", WP)

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)

echo "=== remote catalogs module hooks ==="
grep -n "wp_ajax_riverso_catalog" {WP}/wp-content/plugins/riverso-pos/modules/catalogs/class-catalog-module.php || echo MISSING_FILE

echo "=== duplicate action names catalogs vs publisher ==="
comm -12 \
  <(grep -oE "wp_ajax_riverso_catalog[^']+" {WP}/wp-content/plugins/riverso-pos/modules/catalogs/class-catalog-module.php 2>/dev/null | sort -u) \
  <(grep -oE "wp_ajax_riverso_catalog[^']+" {WP}/wp-content/plugins/riverso-pos/modules/publish/class-woo-publisher-module.php 2>/dev/null | sort -u) || true

echo "=== live hook callbacks ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r {repr(php_diag)}

echo "=== smoke ajax ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r {repr(php_cookie)}
COOKIE=$(cat /tmp/riverso_cookie.txt)
NONCE=$(cat /tmp/riverso_nonce.txt)
PID=$(cat /tmp/riverso_pid.txt)

echo "-- list --"
curl -sS -H "Cookie: $COOKIE" -d "action=riverso_catalog_list&nonce=$NONCE&limit=3&offset=0" https://riverso.cl/wp-admin/admin-ajax.php | head -c 500
echo
echo "-- get product_id=$PID --"
curl -sS -H "Cookie: $COOKIE" -d "action=riverso_catalog_get&nonce=$NONCE&product_id=$PID" https://riverso.cl/wp-admin/admin-ajax.php | head -c 800
echo

echo "=== authenticated HTML portal ==="
curl -sS -o /tmp/cat.html -w "http=%{{http_code}} size=%{{size_download}}\\n" -H "Cookie: $COOKIE" "https://riverso.cl/interno/catalog/?v=$(date +%s)"
python3 -c "html=open('/tmp/cat.html',encoding='utf-8',errors='replace').read(); print('len',len(html)); print('login', 'wp-login' in html.lower()); print('catalog-list', 'id=\\"catalog-list\\"' in html or 'catalog-list' in html); print('has_portal', 'portal' in html.lower())"
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
