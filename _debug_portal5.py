#!/usr/bin/env python
"""Verify catalog AJAX works and WP rewrite resolves /interno/catalog/."""
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

echo "hook_catalog_list=" . (has_action("wp_ajax_riverso_catalog_list") ? "yes" : "no") . "\\n";
echo "hook_catalog_get=" . (has_action("wp_ajax_riverso_catalog_get") ? "yes" : "no") . "\\n";
echo "hook_catalog_code_link=" . (has_action("wp_ajax_riverso_catalog_code_link") ? "yes" : "no") . "\\n";

// Which publisher file is loaded?
$ref = new ReflectionClass("Riverso_Woo_Publisher_Module");
echo "publisher_file=" . $ref->getFileName() . "\\n";

// Call catalog_list directly
$mod = Riverso_Woo_Publisher_Module::get_instance();
$_POST["nonce"] = wp_create_nonce("riverso_pos_nonce");
$_REQUEST["nonce"] = $_POST["nonce"];
$_POST["search"] = "";
$_POST["limit"] = 5;
$_POST["offset"] = 0;
try {{
  ob_start();
  $mod->ajax_catalog_list();
  $out = ob_get_clean();
  echo "ajax_out=" . substr($out, 0, 500) . "\\n";
}} catch (Throwable $e) {{
  echo "AJAX_FATAL=" . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . "\\n";
}}
'

echo "=== rewrite parse ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
$_SERVER["REQUEST_URI"]="/interno/catalog/";
$_SERVER["HTTP_HOST"]="riverso.cl";
$_SERVER["SERVER_NAME"]="riverso.cl";
$_SERVER["REQUEST_METHOD"]="GET";
$_SERVER["HTTPS"]="on";
$_SERVER["SERVER_PORT"]="443";
require "{WP}/wp-load.php";
global $wp;
$wp->parse_request();
echo "matched=" . var_export($wp->matched_rule, true) . "\\n";
echo "query_vars="; print_r($wp->query_vars);
echo "riverso_portal=" . ($wp->query_vars["riverso_portal"] ?? "(none)") . "\\n";
'
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=90)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
