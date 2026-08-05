#!/usr/bin/env python
"""Verificación post-deploy arquitectura productos 1.4.2"""
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
PLUGIN = '/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/riverso-pos'
WP = '/var/www/vhosts/riverso.cl/httpdocs'

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

checks = [
    ('Version', f'grep -E "Version:|RIVERSO_POS_VERSION" {PLUGIN}/riverso-pos.php | head -3'),
    ('set_channel', f'grep -c riverso_pos_set_channel {PLUGIN}/sales/pos/class-pos-module.php'),
    ('family_qty_cart', f'grep -c calculate_family_qty_from_cart {PLUGIN}/sales/pos/class-pos-module.php'),
    ('assign_to_family', f'grep -c assign_to_family {PLUGIN}/catalog/matching/class-matching-module.php'),
    ('phase12', f'test -f {PLUGIN}/migrations/phase12_family_consolidation_v1.sql && echo OK'),
    ('phase13', f'test -f {PLUGIN}/migrations/phase13_supplier_to_family_v1.sql && echo OK'),
    ('pos_toggle', f'grep -c pos-channel-select {PLUGIN}/templates/pos.php'),
    ('loader_sales_pos', f'grep -c "sales/pos/class-pos-module" {PLUGIN}/riverso-pos.php'),
]

print('=== Product architecture checks ===')
for label, cmd in checks:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = (stdout.read() or stderr.read()).decode().strip()
    print(f'{label}: {out}')

print('\n=== Find PHP / trigger migrations ===')
_, stdout, _ = ssh.exec_command('ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1')
php = stdout.read().decode().strip()
print('php_bin:', php or '(not found)')

if php:
    migrate = (
        f'cd {WP} && {php} -r '
        "'"
        'require "wp-load.php"; '
        'if (class_exists("Riverso_POS_Activator")) { '
        'Riverso_POS_Activator::update_database(); '
        'echo "migrated\\n"; '
        'echo get_option("riverso_pos_db_version"); '
        '} else { echo "no-activator"; }'
        "'"
    )
    _, stdout, stderr = ssh.exec_command(migrate, timeout=180)
    print('migrate:', stdout.read().decode().strip())
    err = stderr.read().decode().strip()
    if err:
        print('migrate_err:', err[:800])

print('\n=== Schema producto_proveedor ===')
mysql = (
    "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -e "
    "\"SHOW COLUMNS FROM nExLU_riverso_producto_proveedor "
    "WHERE Field IN ('producto_base_id','grupo_id','assigned_to_family_at');\""
)
_, stdout, stderr = ssh.exec_command(mysql)
print(stdout.read().decode().strip() or '(empty)')
err = stderr.read().decode().strip()
if err and 'Warning' not in err:
    print('stderr:', err[:400])

ssh.close()
print('\nDone.')
