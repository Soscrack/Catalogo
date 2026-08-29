#!/usr/bin/env python
import json
import os
import paramiko

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
env = {}
with open(os.path.join(ROOT, '.env.deploy'), encoding='utf-8') as f:
    for raw in f:
        line = raw.strip()
        if line and not line.startswith('#') and '=' in line:
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip().strip('"').strip("'")

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(env.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'), username=env.get('RIVERSO_DEPLOY_USER', 'root'), password=env['RIVERSO_DEPLOY_PASSWORD'], timeout=30)

cmd = r'''PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
require "/var/www/vhosts/riverso.cl/httpdocs/wp-load.php";
$mod = Riverso_Family_Module::get_instance();
$_POST = ["search" => ""];
ob_start();
try {
  $mod->ajax_list_families();
} catch (Throwable $e) {
  echo "ERR ".$e->getMessage();
}
' 2>/dev/null | tail -1
'''

stdin, stdout, stderr = ssh.exec_command(cmd, timeout=120)
line = stdout.read().decode().strip()
print(line[:500])
if line.startswith('{'):
    data = json.loads(line)
    fam = (data.get('data') or {}).get('families') or []
    if fam:
        f0 = fam[0]
        print('first family keys:', sorted(f0.keys()))
        print('members count:', len(f0.get('members') or []))
        if f0.get('members'):
            print('sample member:', f0['members'][0])
ssh.close()
