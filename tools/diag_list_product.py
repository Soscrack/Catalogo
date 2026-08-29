#!/usr/bin/env python3
import os, paramiko, json
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
for line in (ROOT/'.env.deploy').read_text(encoding='utf-8').splitlines():
    if '=' in line and not line.strip().startswith('#'):
        k,v=line.split('=',1); os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
WP=os.environ.get('RIVERSO_WP_PATH','/var/www/vhosts/riverso.cl/httpdocs')
PHP=f"""<?php
require_once '{WP}/wp-load.php';
require_once '{WP}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';
$c=new Riverso_Facto_Client();
$r=$c->list_products(['sku'=>'22220','page'=>1]);
$items=Riverso_Facto_Client::embed_collection($r,'products');
echo json_encode($items[0] ?? $r, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
"""
ssh=paramiko.SSHClient(); ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(os.environ['RIVERSO_DEPLOY_HOST'], username='root', password=os.environ['RIVERSO_DEPLOY_PASSWORD'])
sftp=ssh.open_sftp()
with sftp.open('/tmp/l.php','w') as f: f.write(PHP)
sftp.close()
_,stdout,_=ssh.exec_command('PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/l.php')
print(stdout.read().decode())
ssh.close()
