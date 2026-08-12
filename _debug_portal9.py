#!/usr/bin/env python
"""Auth cookie fetch of /interno/catalog/ + catalog AJAX smoke test."""
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
TMP=/tmp/riverso_portal_test_$$
mkdir -p "$TMP"

# Generate auth cookies for user 1 via WP
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
$user_id = 1;
wp_set_current_user($user_id);
$expiration = time() + DAY_IN_SECONDS;
$cookie = wp_generate_auth_cookie($user_id, $expiration, "logged_in");
$logged_in_cookie = LOGGED_IN_COOKIE;
$secure = is_ssl();
file_put_contents("/tmp/riverso_cookie.txt", $logged_in_cookie . "=" . $cookie . "\\n");
echo "cookie_name=$logged_in_cookie\\n";
echo "cookie_len=" . strlen($cookie) . "\\n";
'

COOKIE=$(cat /tmp/riverso_cookie.txt | tr -d "\\n")
echo "=== authenticated GET /interno/catalog/ ==="
curl -sS -o "$TMP/catalog.html" -w "http=%{{http_code}} size=%{{size_download}} redirect=%{{redirect_url}}\\n" \
  -H "Cookie: $COOKIE" \
  "https://riverso.cl/interno/catalog/?v=fix-$(date +%s)"

# Inspect HTML
python3 - <<PY
from pathlib import Path
html = Path("$TMP/catalog.html").read_text(encoding="utf-8", errors="replace")
print("len", len(html))
print("title", html[html.find("<title>"):html.find("</title>")+8] if "<title>" in html else "no-title")
for needle in ["Catálogo", "catalog-list", "riverso_catalog_list", "wp-login", "Coming soon", "Tienda en construcción", "Fatal", "portal-nav", "nonce"]:
    print(f"has_{needle.replace(' ','_')}=" , needle in html or needle.lower() in html.lower())
# extract script errors near catalog
idx = html.find("catalog-list")
print("snippet_around_catalog_list=", repr(html[max(0,idx-80):idx+120]) if idx>=0 else "N/A")
PY

echo "=== AJAX riverso_catalog_list ==="
NONCE=$(sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
wp_set_current_user(1);
echo wp_create_nonce("riverso_pos_nonce");
')
curl -sS -H "Cookie: $COOKIE" -d "action=riverso_catalog_list&nonce=$NONCE&search=&limit=5&offset=0" \
  "https://riverso.cl/wp-admin/admin-ajax.php" | head -c 800
echo

echo "=== errors with interno/catalog referer today ==="
grep -i "interno/catalog" /var/www/vhosts/riverso.cl/logs/error_log 2>/dev/null | tail -20

echo "=== publisher hooks registered? ==="
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
global $wp_filter;
foreach (["wp_ajax_riverso_catalog_list","wp_ajax_riverso_catalog_get","wp_ajax_riverso_catalog_save"] as $h) {{
  echo $h . "=" . (isset($wp_filter[$h])?"yes":"NO") . "\\n";
}}
echo "version=" . RIVERSO_POS_VERSION . "\\n";
echo "publisher_file=";
if (class_exists("Riverso_Woo_Publisher_Module")) {{
  $r=new ReflectionClass("Riverso_Woo_Publisher_Module");
  echo $r->getFileName() . "\\n";
}}
'
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:3000])
client.close()
