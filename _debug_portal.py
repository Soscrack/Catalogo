#!/usr/bin/env python
"""Diagnose portal / catalog breakage on remote server."""
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
if [ -z "$PHP_BIN" ]; then PHP_BIN=$(command -v php); fi
echo "PHP=$PHP_BIN"
echo "=== VERSION / CLASSES ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
echo "VERSION=" . (defined("RIVERSO_POS_VERSION") ? RIVERSO_POS_VERSION : "missing") . "\\n";
echo "Family=" . (class_exists("Riverso_Family_Module") ? "yes" : "no") . "\\n";
echo "Product=" . (class_exists("Riverso_Product_Module") ? "yes" : "no") . "\\n";
echo "Publisher=" . (class_exists("Riverso_Woo_Publisher_Module") ? "yes" : "no") . "\\n";
'
echo "=== SYNTAX ==="
for f in \
  "{PLUGIN}/riverso-pos.php" \
  "{PLUGIN}/modules/products/class-product-module.php" \
  "{PLUGIN}/modules/families/class-family-module.php" \
  "{PLUGIN}/modules/import/class-mamut-import-module.php" \
  "{PLUGIN}/woocommerce/import/class-mamut-import-module.php" \
  "{PLUGIN}/woocommerce/publish/class-woo-publisher-module.php" \
  "{PLUGIN}/modules/publish/class-woo-publisher-module.php" \
  "{PLUGIN}/templates/portal/portal-main.php" \
  "{PLUGIN}/includes/class-activator.php"
do
  echo "-- $f"
  sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -l "$f" 2>&1 || true
done
echo "=== FATAL / ERROR LOGS ==="
for log in \
  /var/www/vhosts/riverso.cl/logs/error_log \
  /var/www/vhosts/riverso.cl/logs/proxy_error_log \
  {WP}/wp-content/debug.log
do
  if [ -f "$log" ]; then
    echo "---- $log ----"
    grep -E "Fatal|Parse error|riverso|portal|catalog" "$log" | tail -n 40 || tail -n 30 "$log"
  fi
done
echo "=== CURL PORTAL ==="
curl -sI "https://riverso.cl/interno/catalog/" | head -n 20
curl -sL "https://riverso.cl/interno/catalog/" | head -c 1500
echo
"""

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASSWORD, timeout=30)
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
out = stdout.read().decode("utf-8", "replace")
err = stderr.read().decode("utf-8", "replace")
print(out)
if err.strip():
    print("STDERR:", err)
client.close()
