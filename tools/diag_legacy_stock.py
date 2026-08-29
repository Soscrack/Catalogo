#!/usr/bin/env python3
import os, paramiko
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
for line in (ROOT/'.env.deploy').read_text(encoding='utf-8').splitlines():
    if '=' in line and not line.strip().startswith('#'):
        k,v=line.split('=',1); os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
WP=os.environ.get('RIVERSO_WP_PATH','/var/www/vhosts/riverso.cl/httpdocs')
PHP=f"""<?php
require_once '{WP}/wp-load.php';
global $wpdb; $p=$wpdb->prefix;
foreach (['22220','222433'] as $sku) {{
  $legacy=$wpdb->get_row($wpdb->prepare("SELECT * FROM {{$p}}riverso_legacy_precio_ref WHERE sku=%s", $sku), ARRAY_A);
  echo $sku.' legacy=' . json_encode($legacy) . "\\n";
}}
"""
ssh=paramiko.SSHClient(); ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(os.environ['RIVERSO_DEPLOY_HOST'], username='root', password=os.environ['RIVERSO_DEPLOY_PASSWORD'])
sftp=ssh.open_sftp()
with sftp.open('/tmp/lg.php','w') as f: f.write(PHP)
sftp.close()
_,stdout,_=ssh.exec_command('PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/lg.php')
print(stdout.read().decode())
ssh.close()
