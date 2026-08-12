#!/usr/bin/env python
"""Focused: AJAX payload sizes, super-cache, collisions, auth via localhost."""
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
PLUGIN = f"{WP}/wp-content/plugins/riverso-pos"

php = f'''<?php
require "{WP}/wp-load.php";
wp_set_current_user(1);
$nonce = wp_create_nonce("riverso_pos_nonce");

function run_ajax($action, $extra = []) {{
  foreach ($_POST as $k => $_) unset($_POST[$k]);
  foreach ($_REQUEST as $k => $_) unset($_REQUEST[$k]);
  $_POST = array_merge(["action" => $action, "nonce" => $GLOBALS["nonce"]], $extra);
  $_REQUEST = $_POST;
  ob_start();
  try {{ do_action("wp_ajax_" . $action); }} catch (Throwable $e) {{ echo "EX:" . $e->getMessage(); }}
  $out = ob_get_clean();
  $j = json_decode($out, true);
  echo $action . " len=" . strlen($out) . " success=" . var_export($j["success"] ?? null, true);
  if (isset($j["data"]["message"])) echo " msg=" . $j["data"]["message"];
  if (isset($j["data"]["items"])) echo " items=" . count($j["data"]["items"]);
  echo "\\n";
  if (!($j["success"] ?? false)) echo "  body=" . substr($out, 0, 300) . "\\n";
  return $out;
}}
$GLOBALS["nonce"] = $nonce;

run_ajax("riverso_catalog_list", ["search"=>"", "limit"=>100, "offset"=>0]);
run_ajax("riverso_catalog_get", ["product_id"=>1497]);
run_ajax("riverso_catalogs_get", ["catalog_id"=>1]);

// collision scan: all wp_ajax_riverso_* with multiple callbacks
global $wp_filter;
echo "=== multi-callback riverso ajax ===\\n";
foreach ($wp_filter as $hook => $obj) {{
  if (strpos($hook, "wp_ajax_riverso_") !== 0) continue;
  $n = 0; $names=[];
  foreach ($obj->callbacks as $prio=>$cbs) {{
    foreach ($cbs as $cb) {{
      $n++;
      $fn=$cb["function"];
      if (is_array($fn)) {{
        $cls=is_object($fn[0])?get_class($fn[0]):$fn[0];
        $names[]=$cls."::".$fn[1];
      }}
    }}
  }}
  if ($n > 1) echo "$hook => " . implode(" | ", $names) . "\\n";
}}

// cookie for curl
$c = wp_generate_auth_cookie(1, time()+DAY_IN_SECONDS, "logged_in");
file_put_contents("/tmp/riverso_cookie.txt", LOGGED_IN_COOKIE . "=" . $c . "; path=/; domain=riverso.cl");
echo "cookie_ok\\n";
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_focus.php", "w") as f:
    f.write(php)
sftp.close()

cmd = f"""
PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_focus.php

echo "=== super cache config ==="
ls {WP}/wp-content/plugins | grep -i cache
grep -n "interno\\|rejected\\|cache_rejected\\|DONOTCACHE" {WP}/wp-content/wp-cache-config.php 2>/dev/null | head -40
grep -n "interno\\|rejected" {WP}/wp-cache-config.php 2>/dev/null | head -20

echo "=== localhost authenticated fetch following redirects ==="
COOKIE=$(cat /tmp/riverso_cookie.txt)
curl -sk -L --max-redirs 3 -c /tmp/cj -b "$COOKIE" -D /tmp/h.txt -o /tmp/p.html -w "code=%{{http_code}} size=%{{size_download}} url=%{{url_effective}}\\n" \
  -H "Host: riverso.cl" "https://127.0.0.1/interno/catalog/"
python3 - <<'PY'
html=open('/tmp/p.html',encoding='utf-8',errors='replace').read()
print('len',len(html))
print('login','Iniciar sesión' in html or 'wp-login' in html.lower())
print('portal','portal-sidebar' in html)
print('catalog-list','catalog-list' in html)
print('title_snip', html[html.find('<title>'):html.find('</title>')+8] if '<title>' in html else None)
PY
echo "--- headers ---"
grep -iE "HTTP/|location:|set-cookie:|x-redirect" /tmp/h.txt | head -40

echo "=== grep portal post() nonce in portal-main ==="
grep -n "function post\\|nonce\\|riverso_pos_nonce\\|admin-ajax" {PLUGIN}/templates/portal/portal-main.php | head -40
"""
stdin, stdout, stderr = client.exec_command(cmd, timeout=120)
print(stdout.read().decode("utf-8", "replace")[:15000])
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2000])
client.close()
