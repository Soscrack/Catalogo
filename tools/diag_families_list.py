#!/usr/bin/env python
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
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r 'require "/var/www/vhosts/riverso.cl/httpdocs/wp-load.php"; global $wpdb; $p=$wpdb->prefix."riverso_"; $rows=$wpdb->get_results("SELECT g.id,g.nombre,COUNT(em.id) c FROM {$p}equivalence_groups g LEFT JOIN {$p}equivalence_members em ON em.grupo_id=g.id AND em.activo=1 WHERE g.activo=1 GROUP BY g.id HAVING c>0 ORDER BY c DESC LIMIT 2", ARRAY_A); foreach($rows as $g){ echo "FAM ".$g["id"]." ".$g["nombre"]." c=".$g["c"]."\n"; $ms=$wpdb->get_results($wpdb->prepare("SELECT pb.canonical_sku,pb.nombre_canonico FROM {$p}equivalence_members em INNER JOIN {$p}producto_base pb ON pb.id=em.producto_base_id WHERE em.grupo_id=%d AND em.activo=1 LIMIT 3", $g["id"]), ARRAY_A); foreach($ms as $m){ echo "  sku=".($m["canonical_sku"]?:"-")." n=".substr($m["nombre_canonico"]?:"-",0,30)."\n"; } }' '''

stdin, stdout, stderr = ssh.exec_command(cmd, timeout=120)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('ERR:', err)
ssh.close()
