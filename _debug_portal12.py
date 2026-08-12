#!/usr/bin/env python
"""Auth cookie properly then fetch portal; check access logs."""
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
$cookie = wp_generate_auth_cookie(1, time() + DAY_IN_SECONDS, "logged_in");
file_put_contents("/tmp/riverso_cookie.txt", LOGGED_IN_COOKIE . "=" . $cookie);
echo LOGGED_IN_COOKIE . "\\n";
echo "ok\\n";
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_mkcookie.php", "w") as f:
    f.write(php)
sftp.close()

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_mkcookie.php
COOKIE=$(cat /tmp/riverso_cookie.txt)
echo "COOKIE_PREFIX=$(echo $COOKIE | cut -c1-40)..."

# Use curl with cookie jar and follow redirects carefully
curl -sS -c /tmp/rj.jar -b "$COOKIE" -D /tmp/cat.hdr -o /tmp/cat.html -w "final_http=%{{http_code}} size=%{{size_download}} url_effective=%{{url_effective}}\\n" \
  "https://riverso.cl/interno/catalog/?v=$(date +%s)"
echo "--- headers ---"
head -25 /tmp/cat.hdr
echo "--- html checks ---"
python3 - <<'PY'
html=open('/tmp/cat.html',encoding='utf-8',errors='replace').read()
print('len', len(html))
low=html.lower()
print('is_login', 'iniciar sesión' in low or 'wp-login' in low)
print('catalog-list', 'catalog-list' in html)
print('portal shell', 'portal-sidebar' in html or 'portal-nav' in html or 'riverso-portal' in html)
# title
import re
m=re.search(r'<title>(.*?)</title>', html, re.I|re.S)
print('title', m.group(1).strip() if m else None)
# any fatal
print('fatal', 'fatal error' in low)
print('snippet', html[html.find('catalog'):html.find('catalog')+80] if 'catalog' in html else 'no catalog word')
PY

echo "=== access log recent interno/catalog ==="
for f in /var/www/vhosts/system/riverso.cl/logs/access_ssl_log /var/www/vhosts/riverso.cl/logs/access_ssl_log /var/www/vhosts/riverso.cl/logs/proxy_access_ssl_log; do
  if [ -f "$f" ]; then
    echo "-- $f --"
    grep "interno/catalog" "$f" | tail -15
  fi
done
ls /var/www/vhosts/system/riverso.cl/logs/ 2>/dev/null | head
ls /var/www/vhosts/riverso.cl/logs/ 2>/dev/null | head
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2000])
client.close()
