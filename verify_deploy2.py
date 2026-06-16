#!/usr/bin/env python
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
PLUGIN = '/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/riverso-pos'

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

cmds = [
    f'ls -la {PLUGIN}/ | head -5',
    f'ls -la {PLUGIN}/riverso-pos/ 2>/dev/null | head -5 || echo NO_NESTED',
    f'head -8 {PLUGIN}/riverso-pos.php',
]
for cmd in cmds:
    _, stdout, _ = ssh.exec_command(cmd)
    print('---', cmd)
    print(stdout.read().decode())

ssh.close()
