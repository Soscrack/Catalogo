#!/usr/bin/env python
"""Verificación remota post-deploy Riverso ERP 1.4.0"""
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
    ('Version header', f'grep "Version:" {PLUGIN}/riverso-pos.php | head -1'),
    ('RIVERSO_POS_VERSION', f'grep RIVERSO_POS_VERSION {PLUGIN}/riverso-pos.php | head -1'),
    ('loader.php', f'test -f {PLUGIN}/loader.php && echo OK || echo MISSING'),
    ('core/events', f'test -f {PLUGIN}/core/events/class-event-bus.php && echo OK || echo MISSING'),
    ('barcode model', f'test -f {PLUGIN}/catalog/barcodes/class-barcode-model.php && echo OK || echo MISSING'),
    ('woo sync', f'test -f {PLUGIN}/woocommerce/sync/class-woo-sync-manager.php && echo OK || echo MISSING'),
    ('purchase orders', f'test -f {PLUGIN}/purchases/purchase_orders/class-purchase-order-module.php && echo OK || echo MISSING'),
    ('boot_erp_domains', f'grep -c boot_erp_domains {PLUGIN}/riverso-pos.php'),
    ('create_erp_phase_tables', f'grep -c create_erp_phase_tables {PLUGIN}/includes/class-activator.php'),
    ('PHP lint activator', f'php -l {PLUGIN}/includes/class-activator.php 2>&1 | tail -1'),
    ('PHP lint main', f'php -l {PLUGIN}/riverso-pos.php 2>&1 | tail -1'),
    ('PHP lint sync', f'php -l {PLUGIN}/woocommerce/sync/class-woo-sync-manager.php 2>&1 | tail -1'),
    ('PHP lint barcode', f'php -l {PLUGIN}/catalog/barcodes/class-barcode-model.php 2>&1 | tail -1'),
    ('PHP lint movement', f'php -l {PLUGIN}/inventory/movements/class-movement.php 2>&1 | tail -1'),
]

print('=== Remote file/syntax checks ===')
for label, cmd in checks:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    print(f'{label}: {out or err}')

# Trigger DB update via WP-CLI if available
print('\n=== Trigger DB migrations ===')
cmds = [
    f'cd {WP} && wp option get riverso_pos_db_version --allow-root 2>/dev/null || echo no-wp-cli',
    f'cd {WP} && wp eval \'if (class_exists("Riverso_POS_Activator")) {{ Riverso_POS_Activator::update_database(); echo "migrated"; }} else {{ echo "no-activator"; }}\' --allow-root 2>&1 | tail -5',
    f'cd {WP} && wp option get riverso_pos_db_version --allow-root 2>/dev/null || true',
]
for cmd in cmds:
    _, stdout, stderr = ssh.exec_command(cmd)
    print(stdout.read().decode().strip() or stderr.read().decode().strip())

# Verify new tables exist
print('\n=== DB tables ===')
mysql = r"""mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -N -e "SHOW TABLES LIKE 'nExLU_riverso_%';" | egrep 'codigo_barra|empleados|ordenes_compra|reservas|conteos|woo_sync_log|tarea_historial|precio_historial' || true"""
_, stdout, stderr = ssh.exec_command(mysql)
print(stdout.read().decode().strip() or '(none matched)')
err = stderr.read().decode().strip()
if err and 'Warning' not in err:
    print('stderr:', err)

ssh.close()
print('\nDone.')
