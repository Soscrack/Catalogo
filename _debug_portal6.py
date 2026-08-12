#!/usr/bin/env python
"""Test rewrite with ?v= cache buster and full front controller include."""
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
echo "matched=" . var_export($wp->matched_rule, true) . "\\n";
echo "riverso_portal=" . ($wp->query_vars["riverso_portal"] ?? "(none)") . "\\n";

// Simulate template_include filter chain
$portal = Riverso_Portal_Module::class;
// Find instance via hooks
$tpl = apply_filters("template_include", get_index_template());
echo "template_include=" . $tpl . "\\n";
echo "is_portal_tpl=" . (strpos($tpl, "portal-main.php") !== false ? "yes" : "no") . "\\n";
'

# Also fix: preserve catalog path in login redirect
echo "=== checking protect redirect ==="
grep -n "wp_login_url\\|home_url.*interno" {WP}/wp-content/plugins/riverso-pos/riverso-pos.php {WP}/wp-content/plugins/riverso-pos/portal/class-portal-module.php {WP}/wp-content/plugins/riverso-pos/modules/portal/class-portal-module.php
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=60)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2000])
client.close()
