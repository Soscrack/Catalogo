#!/usr/bin/env python
"""Check employee gate and recent portal AJAX fatals; probe catalog page sections."""
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
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
wp_set_current_user(1);
$uid = get_current_user_id();
echo "uid=$uid login=" . wp_get_current_user()->user_login . "\\n";
echo "is_employee=" . (Riverso_POS_Permissions::is_employee($uid) ? "yes" : "no") . "\\n";
echo "role=" . Riverso_POS_Permissions::get_riverso_role($uid) . "\\n";
$mods = Riverso_POS_Permissions::get_accessible_modules($uid);
echo "accessible="; print_r($mods);

// Check if publisher methods used by portal exist
$pub = class_exists("Riverso_Woo_Publisher_Module") ? Riverso_Woo_Publisher_Module::get_instance() : null;
echo "publisher=" . ($pub ? "yes" : "no") . "\\n";
echo "has_category_tree=" . (method_exists($pub, "category_tree") ? "yes" : "no") . "\\n";
echo "has_ajax_category=" . (has_action("wp_ajax_riverso_category_tree") ? "yes" : "no") . "\\n";

// Check mamut/catalog AJAX hooks common in portal
foreach (["riverso_category_tree","riverso_mamut_list","riverso_portal_get_dashboard","riverso_products_list","riverso_catalogs_list"] as $a) {{
  echo "hook_$a=" . (has_action("wp_ajax_$a") ? "yes" : "no") . "\\n";
}}
'

echo "=== last 2 hours error around interno ==="
awk -v d="$(date -d \"2 hours ago\" \"+%d/%b/%Y:%H\" 2>/dev/null || date -v-2H +%d/%b/%Y:%H 2>/dev/null || echo Aug)" '
  /interno|portal-main|Fatal|Uncaught|Parse error/ {{print}}
' /var/www/vhosts/riverso.cl/logs/error_log | tail -n 30

echo "=== check if request hits rewrite ==="
# Simulate WP parse with REQUEST_URI
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
$_SERVER["REQUEST_URI"]="/interno/catalog/";
$_SERVER["HTTP_HOST"]="riverso.cl";
$_SERVER["SERVER_NAME"]="riverso.cl";
$_SERVER["REQUEST_METHOD"]="GET";
$_SERVER["HTTPS"]="on";
define("WP_USE_THEMES", false);
require "{WP}/wp-blog-header.php";
global $wp, $wp_query;
echo "query_vars="; print_r($wp->query_vars);
echo "riverso_portal=" . get_query_var("riverso_portal") . "\\n";
echo "is_404=" . (is_404()?"yes":"no") . "\\n";
echo "is_home=" . (is_home()?"yes":"no") . "\\n";
'
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=90)
print(stdout.read().decode("utf-8", "replace")[:12000])
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:4000])
client.close()
