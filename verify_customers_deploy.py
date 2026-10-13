#!/usr/bin/env python
"""Verifica deploy del módulo Clientes en producción."""
import os
import paramiko

ROOT = os.path.dirname(os.path.abspath(__file__))


def load_env():
    path = os.path.join(ROOT, '.env.deploy')
    if not os.path.isfile(path):
        return
    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


load_env()
HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
WP = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
PLUGIN = f'{WP}/wp-content/plugins/riverso-pos'

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

cmd = f'''
set -e
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
test -f {PLUGIN}/sales/customers/class-customer-module.php && echo FILE_MODULE=ok || echo FILE_MODULE=missing
test -f {PLUGIN}/templates/customers/app.php && echo FILE_APP=ok || echo FILE_APP=missing
test -f {PLUGIN}/assets/js/customers.js && echo FILE_JS=ok || echo FILE_JS=missing
test -f {PLUGIN}/assets/css/customers.css && echo FILE_CSS=ok || echo FILE_CSS=missing
grep -E "RIVERSO_POS_VERSION" {PLUGIN}/riverso-pos.php | head -1
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "{WP}/wp-load.php";
global $wpdb;
$t = $wpdb->prefix . "riverso_clientes";
$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t ? "yes" : "no";
echo "TABLE_CLIENTES=$exists\n";
echo "DB_VERSION=" . get_option("riverso_pos_db_version") . "\n";
echo "PLUGIN_VERSION=" . (defined("RIVERSO_POS_VERSION") ? RIVERSO_POS_VERSION : "missing") . "\n";
$role = get_role("riverso_ventas");
echo "CAP_VIEW_VENTAS=" . (!empty($role->capabilities["riverso_view_customers"]) ? "yes" : "no") . "\n";
echo "CAP_EDIT_VENTAS=" . (!empty($role->capabilities["riverso_edit_customers"]) ? "yes" : "no") . "\n";
$role2 = get_role("riverso_cotizador");
echo "CAP_VIEW_COT=" . (!empty($role2->capabilities["riverso_view_customers"]) ? "yes" : "no") . "\n";
'
'''

stdin, stdout, stderr = ssh.exec_command(cmd, timeout=120)
print(stdout.read().decode())
err = stderr.read().decode()
for line in err.splitlines():
    if 'bluex' in line or 'Duplicate' in line:
        continue
    if line.strip():
        print('ERR:', line)
ssh.close()
