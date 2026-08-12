#!/usr/bin/env python
"""Upload and run remote PHP probe + auth fetch."""
import os
import io
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
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
PLUGIN = f"{WP}/wp-content/plugins/riverso-pos"

php = f'''<?php
$_SERVER["REQUEST_URI"] = "/interno/catalog/";
$_SERVER["HTTP_HOST"] = "riverso.cl";
$_SERVER["SERVER_NAME"] = "riverso.cl";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["HTTPS"] = "on";
$_SERVER["SERVER_PORT"] = "443";
require "{WP}/wp-load.php";

global $wp_filter, $wpdb;

echo "VERSION=" . RIVERSO_POS_VERSION . "\\n";

$actions = [
  "wp_ajax_riverso_catalog_list",
  "wp_ajax_riverso_catalog_get",
  "wp_ajax_riverso_catalogs_get",
];
foreach ($actions as $h) {{
  echo "$h: ";
  if (!isset($wp_filter[$h])) {{ echo "(none)\\n"; continue; }}
  $parts = [];
  foreach ($wp_filter[$h]->callbacks as $prio => $cbs) {{
    foreach ($cbs as $cb) {{
      $fn = $cb["function"];
      if (is_array($fn)) {{
        $cls = is_object($fn[0]) ? get_class($fn[0]) : $fn[0];
        $parts[] = $cls . "::" . $fn[1] . "@" . $prio;
      }}
    }}
  }}
  echo implode(" | ", $parts) . "\\n";
}}

wp_set_current_user(1);
$pid = (int) $wpdb->get_var("SELECT woocommerce_product_id FROM {{$wpdb->prefix}}riverso_producto_base WHERE woocommerce_product_id IS NOT NULL LIMIT 1");
echo "sample_pid=$pid\\n";

// Direct call catalog list
$_POST["nonce"] = wp_create_nonce("riverso_pos_nonce");
$_REQUEST["nonce"] = $_POST["nonce"];
$_POST["limit"] = 3;
$_POST["offset"] = 0;
$_POST["search"] = "";

ob_start();
try {{
  do_action("wp_ajax_riverso_catalog_list");
}} catch (Throwable $e) {{
  echo "list_exception=" . $e->getMessage() . "\\n";
}}
$out = ob_get_clean();
echo "list_out=" . substr($out, 0, 400) . "\\n";

$_POST["product_id"] = $pid;
ob_start();
try {{
  do_action("wp_ajax_riverso_catalog_get");
}} catch (Throwable $e) {{
  echo "get_exception=" . $e->getMessage() . "\\n";
}}
$out = ob_get_clean();
echo "get_out=" . substr($out, 0, 500) . "\\n";

// Auth cookie for curl
$cookie = wp_generate_auth_cookie(1, time() + DAY_IN_SECONDS, "logged_in");
file_put_contents("/tmp/riverso_cookie.txt", LOGGED_IN_COOKIE . "=" . $cookie);
file_put_contents("/tmp/riverso_nonce.txt", $_POST["nonce"]);
echo "cookie_written\\n";

// File hashes
foreach ([
  "modules/catalogs/class-catalog-module.php",
  "modules/publish/class-woo-publisher-module.php",
  "modules/portal/class-portal-module.php",
  "templates/portal/portal-main.php",
  "riverso-pos.php",
] as $rel) {{
  $p = "{PLUGIN}/" . $rel;
  echo "md5 $rel " . (file_exists($p) ? md5_file($p) : "MISSING") . "\\n";
}}
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_portal_probe.php", "w") as f:
    f.write(php)
sftp.close()

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_portal_probe.php
echo "=== curl auth catalog ==="
COOKIE=$(cat /tmp/riverso_cookie.txt)
curl -sS -D /tmp/cat.hdr -o /tmp/cat.html -w "http=%{{http_code}} size=%{{size_download}}\\n" -H "Cookie: $COOKIE" "https://riverso.cl/interno/catalog/?v=$(date +%s)"
head -20 /tmp/cat.hdr
python3 - <<'PY'
html=open('/tmp/cat.html',encoding='utf-8',errors='replace').read()
print('len', len(html))
print('login_page', 'wp-login.php' in html or 'Iniciar sesión' in html)
print('catalog-list', 'catalog-list' in html)
print('riverso_catalog_list', 'riverso_catalog_list' in html)
print('nonce_present', 'riverso_pos_nonce' in html or 'nonce' in html)
# find JS errors markers
for s in ['Unexpected', 'SyntaxError', 'Fatal error', 'Coming soon', 'tienda en construcción']:
    if s.lower() in html.lower():
        print('found', s)
PY
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
