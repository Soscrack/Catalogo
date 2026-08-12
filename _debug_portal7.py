#!/usr/bin/env python
"""Diagnose why template_include doesn't use portal-main.php"""
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
global $wp, $wp_query, $wp_filter;

$wp->parse_request();
$wp->query_posts();

echo "qv_riverso_portal=" . get_query_var("riverso_portal") . "\\n";
echo "query_vars=" . json_encode($wp->query_vars) . "\\n";

// Check if template_include filter is registered
$has = isset($wp_filter["template_include"]);
echo "has_template_include_filter=" . ($has ? "yes" : "no") . "\\n";
if ($has) {{
    foreach ($wp_filter["template_include"]->callbacks as $prio => $cbs) {{
        foreach ($cbs as $cb) {{
            $fn = $cb["function"];
            if (is_array($fn)) {{
                $cls = is_object($fn[0]) ? get_class($fn[0]) : $fn[0];
                echo "  prio=$prio $cls::" . $fn[1] . "\\n";
            }} else {{
                echo "  prio=$prio " . (is_string($fn) ? $fn : "closure") . "\\n";
            }}
        }}
    }}
}}

// Call portal module filter directly
if (class_exists("Riverso_Portal_Module")) {{
    $m = Riverso_Portal_Module::get_instance();
    $out = $m->template_include("/theme/index.php");
    echo "direct_template_include=$out\\n";
    echo "portal_page_prop=";
    // inspect via reflection
    $ref = new ReflectionClass($m);
    echo "methods=" . implode(",", array_map(function($x){{return $x->getName();}}, $ref->getMethods(ReflectionMethod::IS_PUBLIC))) . "\\n";
}}

$tpl = apply_filters("template_include", get_index_template());
echo "final_tpl=$tpl\\n";

// Check which portal file exists
foreach ([
  "{WP}/wp-content/plugins/riverso-pos/modules/portal/class-portal-module.php",
  "{WP}/wp-content/plugins/riverso-pos/portal/class-portal-module.php",
  "{WP}/wp-content/plugins/riverso-pos/templates/portal/portal-main.php",
] as $p) {{
  echo (file_exists($p) ? "OK " : "MISSING ") . $p . "\\n";
}}

// Which portal was loaded?
$rf = new ReflectionClass("Riverso_Portal_Module");
echo "loaded_from=" . $rf->getFileName() . "\\n";
'
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=90)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
