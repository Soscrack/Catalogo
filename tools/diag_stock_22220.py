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
require_once '{WP}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';
global $wpdb;
$p=$wpdb->prefix;
$sku='22220';
$pb=$wpdb->get_row($wpdb->prepare("SELECT id, stock_minimo FROM {{$p}}riverso_producto_base WHERE canonical_sku=%s", $sku), ARRAY_A);
echo 'producto_base=' . json_encode($pb) . "\\n";
if ($pb) {{
  $cfg=$wpdb->get_row($wpdb->prepare("SELECT * FROM {{$p}}riverso_producto_stock_config WHERE producto_base_id=%d", $pb['id']), ARRAY_A);
  echo 'stock_config=' . json_encode($cfg) . "\\n";
}}
$client=new Riverso_Facto_Client();
$locs=Riverso_Facto_Client::embed_collection($client->list_product_locations(), 'product_locations');
echo 'locations=' . json_encode($locs, JSON_UNESCAPED_UNICODE) . "\\n";
"""
ssh=paramiko.SSHClient(); ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(os.environ['RIVERSO_DEPLOY_HOST'], username=os.environ.get('RIVERSO_DEPLOY_USER','root'), password=os.environ['RIVERSO_DEPLOY_PASSWORD'])
sftp=ssh.open_sftp()
with sftp.open('/tmp/st.php','w') as f: f.write(PHP)
sftp.close()
_,stdout,_=ssh.exec_command('PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/st.php')
print(stdout.read().decode())
ssh.close()
