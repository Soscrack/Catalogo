#!/usr/bin/env python
"""Verify login redirect preserves /interno/catalog/ and AJAX still works."""
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
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
echo "=== version ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r 'require "{WP}/wp-load.php"; echo RIVERSO_POS_VERSION;'
echo

echo "=== unauth Location header ==="
curl -sI "https://riverso.cl/interno/catalog/?v=testfix" | grep -iE "HTTP/|location:"

echo "=== catalog_list + get hooks ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" <<'EOF'
<?php
require "{WP}/wp-load.php";
global $wp_filter;
foreach (["wp_ajax_riverso_catalog_list","wp_ajax_riverso_catalog_get","wp_ajax_riverso_catalogs_get"] as $h) {{
  $names=[];
  if (isset($wp_filter[$h])) {{
    foreach ($wp_filter[$h]->callbacks as $cbs) {{
      foreach ($cbs as $cb) {{
        $fn=$cb["function"];
        if (is_array($fn)) {{
          $cls=is_object($fn[0])?get_class($fn[0]):$fn[0];
          $names[]=$cls."::".$fn[1];
        }}
      }}
    }}
  }}
  echo "$h = " . implode("|", $names) . "\\n";
}}
echo "protect_has_catalog_path=" . (strpos(file_get_contents("{WP}/wp-content/plugins/riverso-pos/riverso-pos.php"), "Conservar /interno/catalog") !== false ? "yes" : "no") . "\\n";
EOF
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=60)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:1500])
client.close()
