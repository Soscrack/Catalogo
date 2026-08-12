#!/usr/bin/env python
"""Find what issues the 307 ?v= redirect on /interno/catalog/."""
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
require "{WP}/wp-load.php";

// Find filters that might redirect with ?v=
global $wp_filter;
foreach (["template_redirect", "init", "wp", "parse_request"] as $hook) {{
  if (!isset($wp_filter[$hook])) continue;
  echo "=== $hook ===\\n";
  foreach ($wp_filter[$hook]->callbacks as $prio => $cbs) {{
    foreach ($cbs as $id => $cb) {{
      $fn = $cb["function"];
      if (is_array($fn)) {{
        $cls = is_object($fn[0]) ? get_class($fn[0]) : (string)$fn[0];
        $name = $cls . "::" . $fn[1];
      }} elseif ($fn instanceof Closure) {{
        $rf = new ReflectionFunction($fn);
        $name = "Closure@" . $rf->getFileName() . ":" . $rf->getStartLine();
      }} else {{
        $name = is_string($fn) ? $fn : gettype($fn);
      }}
      // only print interesting
      if (stripos($name, "riverso") !== false || stripos($name, "cache") !== false || stripos($name, "redirect") !== false || stripos($name, "coming") !== false || stripos($name, "portal") !== false || stripos($name, "version") !== false) {{
        echo "  [$prio] $name\\n";
      }}
    }}
  }}
}}

// Search plugin/theme files for ?v= redirect pattern
echo "=== grep plugins for v= redirect ===\\n";
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_find_redirect.php", "w") as f:
    f.write(php)
sftp.close()

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_find_redirect.php

echo "=== ripgrep v= hash redirect ==="
grep -RIn --include='*.php' -E "\\?v=|add_query_arg\\(\\s*'v'|query_arg\\(.*v|wp_safe_redirect|status_header\\(307|307" \
  {WP}/wp-content/plugins/riverso-pos \
  {WP}/wp-content/mu-plugins \
  {WP}/wp-content/themes/astra \
  2>/dev/null | grep -iE "v=|307|cache|version|hash|interno" | head -80

echo "=== specifically search for md5/uniqid version query ==="
grep -RIn --include='*.php' -E "\\$_GET\\['v'\\]|REQUEST\\['v'\\]|query_var.*\\bv\\b|bin2hex|md5\\(.*time|wp_redirect.*\\?v" \
  {WP}/wp-content/plugins/riverso-pos {WP}/wp-content/mu-plugins 2>/dev/null | head -50

echo "=== AJAX response body from user session window - simulate failed get ==="
# Check ajax responses sizes - decode last error if any for catalog_get colliding
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r 'require "{WP}/wp-load.php"; echo "ok";'

# Inspect portal-main for ?v= generation
grep -n "\\?v=\\|v=" {WP}/wp-content/plugins/riverso-pos/templates/portal/portal-main.php | head -30
grep -n "\\?v=\\|v=" {WP}/wp-content/plugins/riverso-pos/includes/class-assets.php | head -30
grep -rn "\\?v=" {WP}/wp-content/plugins/riverso-pos --include='*.php' | head -40
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2500])
client.close()
