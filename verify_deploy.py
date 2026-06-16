#!/usr/bin/env python
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
PLUGIN = '/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/riverso-pos'

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

checks = [
    ('Version header', f'grep "Version:" {PLUGIN}/riverso-pos.php | head -1'),
    ('RIVERSO_POS_VERSION', f'grep RIVERSO_POS_VERSION {PLUGIN}/riverso-pos.php'),
    ('search_local_sku', f'grep -c search_local_sku {PLUGIN}/modules/publish/class-woo-publisher-module.php'),
    ('local-sku-section UI', f'grep -c local-sku-section {PLUGIN}/templates/portal/portal-main.php'),
    ('gate_context', f'grep -c gate_context {PLUGIN}/modules/publish/class-woo-publisher-module.php'),
    ('interno/catalog URL', f'grep -c "/interno/catalog/" {PLUGIN}/includes/helpers.php'),
]

for label, cmd in checks:
    _, stdout, _ = ssh.exec_command(cmd)
    print(f'{label}: {stdout.read().decode().strip()}')

ssh.close()
