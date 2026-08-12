#!/usr/bin/env python
"""Test portal template resolution + coming soon + HTTP status."""
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
cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)

# 1) HTTP headers unauthenticated
echo "=== HTTP unauth ==="
curl -sI "https://riverso.cl/interno/catalog/?v=161747ec4dc9" | head -20

echo "=== HTTP follow redirects unauth ==="
curl -sI -L --max-redirs 5 "https://riverso.cl/interno/catalog/?v=161747ec4dc9" | grep -iE "HTTP/|location:|content-type"

# 2) PHP: template after query_posts as admin
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
$_SERVER["REQUEST_URI"]="/interno/catalog/?v=161747ec4dc9";
$_SERVER["HTTP_HOST"]="riverso.cl";
$_SERVER["SERVER_NAME"]="riverso.cl";
$_SERVER["REQUEST_METHOD"]="GET";
$_SERVER["HTTPS"]="on";
$_SERVER["SERVER_PORT"]="443";
$_SERVER["QUERY_STRING"]="v=161747ec4dc9";
$_GET["v"]="161747ec4dc9";
require "{WP}/wp-load.php";
wp_set_current_user(1);
global $wp;
$wp->parse_request();
$wp->query_posts();
echo "logged_in=" . (is_user_logged_in()?"yes":"no") . "\\n";
echo "is_employee=" . (Riverso_POS_Permissions::is_employee()?"yes":"no") . "\\n";
echo "qv=" . get_query_var("riverso_portal") . "\\n";
echo "coming_soon=" . (class_exists("\\\\Automattic\\\\WooCommerce\\\\Admin\\\\Features\\\\Features") ? "check" : "n/a") . "\\n";
if (function_exists("wc_get_container")) {{
  try {{
    $opt = get_option("woocommerce_coming_soon");
    echo "wc_coming_soon_opt=" . var_export($opt, true) . "\\n";
    $opt2 = get_option("woocommerce_store_pages_only");
    echo "wc_store_pages_only=" . var_export($opt2, true) . "\\n";
  }} catch (Throwable $e) {{ echo "wc_err=".$e->getMessage()."\\n"; }}
}}
$tpl = apply_filters("template_include", get_index_template());
echo "final_tpl=$tpl\\n";
echo "is_portal=" . (strpos($tpl, "portal-main.php") !== false ? "yes" : "no") . "\\n";

// Render a snippet of portal template without exit
ob_start();
try {{
  include RIVERSO_POS_PLUGIN_DIR . "templates/portal/portal-main.php";
}} catch (Throwable $e) {{
  echo "render_err=" . $e->getMessage() . "\\n";
}}
$html = ob_get_clean();
echo "portal_html_len=" . strlen($html) . "\\n";
echo "has_catalog_tab=" . (strpos($html, "catalog") !== false ? "yes" : "no") . "\\n";
echo "has_error_text=" . (preg_match("/Fatal|Warning|Error|Exception/i", substr($html,0,5000)) ? "yes" : "no") . "\\n";
'

# 3) Recent PHP/WP errors mentioning portal/catalog/interno
echo "=== recent logs ==="
for f in /var/www/vhosts/riverso.cl/logs/error_log /var/www/vhosts/riverso.cl/logs/proxy_error_log {WP}/wp-content/debug.log; do
  if [ -f "$f" ]; then
    echo "-- $f --"
    grep -iE "portal|catalog|interno|riverso|Fatal|Uncaught" "$f" 2>/dev/null | tail -30
  fi
done
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:4000])
client.close()
