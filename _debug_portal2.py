#!/usr/bin/env python
"""Deeper portal diagnosis: rewrite rules, page content, fatal on load."""
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
HOST = os.environ["RIVERSO_DEPLOY_HOST"]
USER = os.environ["RIVERSO_DEPLOY_USER"]
PASSWORD = os.environ["RIVERSO_DEPLOY_PASSWORD"]
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
PLUGIN = f"{WP}/wp-content/plugins/riverso-pos"

cmd = f"""
set -e
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
echo "PHP=$PHP_BIN"
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
global $wpdb;

// Find portal page
$page = get_page_by_path("interno/catalog");
if (!$page) {{
  $page = get_page_by_path("interno");
}}
echo "page_path_interno/catalog=";
var_export($page ? [$page->ID, $page->post_name, $page->post_status, substr($page->post_content,0,300)] : null);
echo "\\n";

$pages = $wpdb->get_results("SELECT ID, post_name, post_status, post_parent, LEFT(post_content,200) c FROM {{$wpdb->posts}} WHERE post_type=\\"page\\" AND (post_name LIKE \\"%catalog%\\" OR post_name=\\"interno\\" OR post_content LIKE \\"%portal%\\" OR post_content LIKE \\"%riverso%catalog%\\") ORDER BY ID DESC LIMIT 20", ARRAY_A);
echo "pages=";
print_r($pages);

// Rewrite rules containing interno
$rules = get_option("rewrite_rules");
$hits = [];
if (is_array($rules)) {{
  foreach ($rules as $k=>$v) {{
    if (stripos($k, "interno") !== false || stripos($v, "interno") !== false) {{
      $hits[$k] = $v;
    }}
  }}
}}
echo "rewrite_hits=";
print_r($hits);

// Try including portal template for fatal
echo "try_portal_include\\n";
try {{
  ob_start();
  $file = "{PLUGIN}/templates/portal/portal-main.php";
  if (!file_exists($file)) echo "MISSING portal-main\\n";
  else {{
    // just tokenize/include check via php -l already done; check shortcodes
    echo "portal_exists=1 size=".filesize($file)."\\n";
  }}
  ob_end_clean();
}} catch (Throwable $e) {{
  echo "EX=".$e->getMessage()."\\n";
}}

// Check last 30 minutes php errors more carefully
'
echo "=== recent php messages ==="
grep -E "interno|portal|Fatal|Parse error|Uncaught" /var/www/vhosts/riverso.cl/logs/error_log | tail -n 50 || true
echo "=== php-fpm / domain logs ==="
ls -lt /var/www/vhosts/system/riverso.cl/logs/ 2>/dev/null | head -10 || true
for log in /var/www/vhosts/system/riverso.cl/logs/error_log /var/www/vhosts/system/riverso.cl/logs/proxy_error_log; do
  if [ -f "$log" ]; then echo "---- $log ----"; grep -E "Fatal|Parse|Uncaught|portal|interno|riverso-pos" "$log" | tail -n 40; fi
done
echo "=== compare local dirty files timestamps on server ==="
ls -la "{PLUGIN}/woocommerce/publish/class-woo-publisher-module.php" "{PLUGIN}/modules/publish/class-woo-publisher-module.php" "{PLUGIN}/woocommerce/import/class-mamut-import-module.php" "{PLUGIN}/modules/import/class-mamut-import-module.php" "{PLUGIN}/includes/class-activator.php" 2>&1
echo "=== head publisher for recent edits ==="
grep -n "function category_tree\\|Fatal\\|TODO\\|FIXME\\|syntax" "{PLUGIN}/woocommerce/publish/class-woo-publisher-module.php" | head -20
# simulate authenticated request by loading template via WP CLI-ish
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
// Bootstrap as admin user 1
wp_set_current_user(1);
$user = wp_get_current_user();
echo "user=".$user->user_login." caps_manage=". (current_user_can("manage_options")?"1":"0")."\\n";
// Resolve URL
$url = home_url("/interno/catalog/");
echo "url=$url\\n";
$p = url_to_postid($url);
echo "url_to_postid=$p\\n";
$req = new WP_Query(["pagename"=>"interno/catalog"]);
echo "found=".( $req->have_posts() ? "yes ID=".$req->posts[0]->ID : "no")."\\n";
if ($req->have_posts()) {{
  $content = $req->posts[0]->post_content;
  echo "content_len=".strlen($content)."\\n";
  echo "content_snip=".substr($content,0,400)."\\n";
  // Do shortcodes carefully
  try {{
    $out = do_shortcode($content);
    echo "shortcode_out_len=".strlen($out)."\\n";
    echo "shortcode_snip=".substr(strip_tags($out),0,400)."\\n";
  }} catch (Throwable $e) {{
    echo "SHORTCODE_FATAL=".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\\n";
  }}
}}
'
"""

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASSWORD, timeout=30)
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
