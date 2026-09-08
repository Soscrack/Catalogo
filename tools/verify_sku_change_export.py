#!/usr/bin/env python3
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
for raw in (ROOT / '.env.deploy').read_text(encoding='utf-8').splitlines():
    line = raw.strip()
    if not line or line.startswith('#') or '=' not in line:
        continue
    k, v = line.split('=', 1)
    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
php = f"""<?php
require_once '{wp}/wp-load.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-export-service.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/tpv/class-tpv-export-service.php';
echo 'version=' . RIVERSO_POS_VERSION . "\\n";
$f = new Riverso_Facto_Export_Service();
$s = $f->get_sku_changes_summary(5);
echo json_encode([
    'sku_changes' => $s,
    'has_generate' => method_exists($f, 'generate_sku_changes_file'),
], JSON_UNESCAPED_UNICODE) . "\\n";
$t = new Riverso_Tpv_Export_Service();
$p = $t->preview(['only_changed' => 1]);
echo json_encode([
    'tpv' => [
        'change_sku_productos' => $p['change_sku_productos'] ?? null,
        'create_productos' => $p['create_productos'] ?? null,
        'update_productos' => $p['update_productos'] ?? null,
        'delete_productos' => $p['delete_productos'] ?? null,
        'total_productos' => $p['total_productos'] ?? null,
        'baseline' => $p['baseline'] ?? null,
    ],
], JSON_UNESCAPED_UNICODE) . "\\n";
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    os.environ['RIVERSO_DEPLOY_HOST'],
    username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
    password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
    timeout=30,
)
sftp = ssh.open_sftp()
with sftp.open('/tmp/v_sku.php', 'w') as handle:
    handle.write(php)
sftp.close()
_, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/v_sku.php',
    timeout=120,
)
print(stdout.read().decode())
err = stderr.read().decode()
clean = '\n'.join(
    ln for ln in err.splitlines()
    if 'bluex' not in ln and 'Failed to open' not in ln and 'Duplicate' not in ln
)
if clean.strip():
    print(clean[:1500])
ssh.close()
