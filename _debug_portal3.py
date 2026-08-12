#!/usr/bin/env python
"""Simulate authenticated portal render and catch fatals."""
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
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d display_errors=1 -d error_reporting=E_ALL -r '
$_SERVER["REQUEST_URI"] = "/interno/catalog/";
$_SERVER["HTTP_HOST"] = "riverso.cl";
$_SERVER["HTTPS"] = "on";
$_GET["riverso_portal"] = "catalog";
require "{WP}/wp-load.php";
wp_set_current_user(1);

// Ensure query var
set_query_var("riverso_portal", "catalog");
$qv = get_query_var("riverso_portal");
echo "qv=$qv\\n";
echo "flushed_opt=" . get_option("riverso_portal_rules_flushed") . "\\n";
echo "version=" . RIVERSO_POS_VERSION . "\\n";
echo "portal_module=" . (class_exists("Riverso_Portal_Module") ? "yes" : "no") . "\\n";

// Check if portal module was loaded in Riverso_POS
$pos = Riverso_POS::instance();
$ref = new ReflectionClass($pos);
$prop = $ref->getProperty("modules");
$prop->setAccessible(true);
$mods = array_keys($prop->getValue($pos));
echo "modules=" . implode(",", $mods) . "\\n";
echo "has_portal_in_modules=" . (in_array("portal", $mods, true) ? "yes" : "no") . "\\n";

// Try template include path
$tpl = RIVERSO_POS_PLUGIN_DIR . "templates/portal/portal-main.php";
echo "tpl_exists=" . (file_exists($tpl) ? "yes" : "no") . " size=" . (file_exists($tpl)?filesize($tpl):0) . "\\n";

// Capture output of portal template
try {{
  ob_start();
  include $tpl;
  $html = ob_get_clean();
  echo "render_ok len=" . strlen($html) . "\\n";
  echo "snip=" . substr(preg_replace("/\\s+/", " ", strip_tags($html)), 0, 400) . "\\n";
}} catch (Throwable $e) {{
  echo "FATAL=" . $e->getMessage() . "\\nFILE=" . $e->getFile() . ":" . $e->getLine() . "\\n";
  echo $e->getTraceAsString() . "\\n";
}}
'

echo "=== curl with cookie jar as admin is hard; check rewrite flush option and .htaccess ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
echo "riverso_portal_rules_flushed=" . var_export(get_option("riverso_portal_rules_flushed"), true) . "\\n";
flush_rewrite_rules(false);
update_option("riverso_portal_rules_flushed", RIVERSO_POS_VERSION);
echo "flushed_now\\n";
$rules = get_option("rewrite_rules");
foreach ($rules as $k=>$v) {{
  if (strpos($k, "interno") !== false) echo "$k => $v\\n";
}}
'
# Check auth protection
grep -n "protect_internal\\|is_user_logged_in\\|wp_redirect" "{PLUGIN}/riverso-pos.php" | head -40
"""

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASSWORD, timeout=30)
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace")[:15000])
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:5000])
client.close()
