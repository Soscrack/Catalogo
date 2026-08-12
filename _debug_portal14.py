#!/usr/bin/env python
"""Find 307 redirect source; decode AJAX bodies from catalog."""
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
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

php = f'''<?php
// Instrument template_redirect to see who redirects
require "{WP}/wp-load.php";

add_action("template_redirect", function() {{
  // dump backtrace if redirect happens - hook early
}}, 0);

// Monkeypatch wp_redirect
if (!function_exists("wp_redirect_intercept_boot")) {{
  // can't redefine - use filter
}}
add_filter("wp_redirect", function($location, $status) {{
  $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
  $frames = [];
  foreach ($bt as $f) {{
    $frames[] = ($f["class"] ?? "") . ($f["type"] ?? "") . ($f["function"] ?? "") . "@" . ($f["file"] ?? "") . ":" . ($f["line"] ?? "");
  }}
  error_log("RIVERSO_REDIRECT status=$status loc=$location frames=" . implode(" || ", $frames));
  return $location;
}}, 1, 2);

$_SERVER["REQUEST_URI"] = "/interno/catalog/?v=161747ec4dc9";
$_SERVER["HTTP_HOST"] = "riverso.cl";
$_SERVER["HTTPS"] = "on";
$_GET["v"] = "161747ec4dc9";
wp_set_current_user(1);

// Simulate main WP request partially
global $wp;
$wp->main(["riverso_portal" => "catalog"]);
echo "after_main done (if you see this, no exit redirect)\\n";
echo "qv=" . get_query_var("riverso_portal") . "\\n";
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(
    os.environ["RIVERSO_DEPLOY_HOST"],
    username=os.environ["RIVERSO_DEPLOY_USER"],
    password=os.environ["RIVERSO_DEPLOY_PASSWORD"],
    timeout=30,
)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_redir_trace.php", "w") as f:
    f.write(php)
sftp.close()

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)

# Search for ?v= hex redirect outside riverso data files
echo "=== search v= redirect pattern ==="
grep -RIn --include='*.php' "add_query_arg" {WP}/wp-content/mu-plugins {WP}/wp-content/plugins --exclude-dir=riverso-pos 2>/dev/null | grep -E "'v'|\\\"v\\\"" | head -40

echo "=== mu-plugins list ==="
ls -la {WP}/wp-content/mu-plugins 2>/dev/null

echo "=== who sets ?v= on interno ==="
grep -RIn --include='*.php' -e "interno" -e "\\$_GET\\['v'\\]" {WP}/wp-content/mu-plugins 2>/dev/null | head -40

# Check if LiteSpeed / cache plugin
ls {WP}/wp-content/plugins | head -80

echo "=== follow 307 with cookie via localhost bypass CF ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_mkcookie.php
COOKIE=$(cat /tmp/riverso_cookie.txt)
# Hit apache directly
curl -sS -D - -o /tmp/local.html -H "Cookie: $COOKIE" -H "Host: riverso.cl" \
  "https://127.0.0.1/interno/catalog/?v=161747ec4dc9" -k --max-redirs 0 | head -30
echo "body_len=$(wc -c </tmp/local.html)"

echo "=== check error_log for RIVERSO_REDIRECT after running tracer ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_redir_trace.php 2>/tmp/trace.err
echo "stdout:"; cat /tmp/trace.err 2>/dev/null | tail -20
grep RIVERSO_REDIRECT /var/www/vhosts/riverso.cl/logs/error_log 2>/dev/null | tail -5
grep RIVERSO_REDIRECT {WP}/wp-content/debug.log 2>/dev/null | tail -5

# Decode what AJAX returned ~5845 bytes - run catalog_get for first list item
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" <<'PHP'
<?php
require "{WP}/wp-load.php";
wp_set_current_user(1);
$_REQUEST["nonce"] = $_POST["nonce"] = wp_create_nonce("riverso_pos_nonce");
$_POST["product_id"] = 1497;
ob_start();
do_action("wp_ajax_riverso_catalog_get");
$out = ob_get_clean();
echo "get_len=" . strlen($out) . "\\n";
echo "get_start=" . substr($out, 0, 300) . "\\n";
$j = json_decode($out, true);
echo "success=" . var_export($j["success"] ?? null, true) . "\\n";
if (!($j["success"] ?? false)) echo "err=" . json_encode($j) . "\\n";
PHP
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
out = stdout.read().decode("utf-8", "replace")
print(out[:12000])
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2500])
client.close()
