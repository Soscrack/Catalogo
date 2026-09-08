#!/usr/bin/env python3
import os
from pathlib import Path
import paramiko

ROOT = Path(__file__).resolve().parents[1]

def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        return
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

def main():
    load_env()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = """<?php
require_once '%s/wp-load.php';
if (!class_exists('Riverso_Price_Lookup_Service')) {
  require_once WP_PLUGIN_DIR . '/riverso-pos/modules/pricing/class-price-lookup-service.php';
}
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$row = $wpdb->get_row(\"
  SELECT cm.producto_base_id, pb.canonical_sku
  FROM {$p}competencia_match cm
  INNER JOIN {$p}producto_base pb ON pb.id = cm.producto_base_id AND pb.deleted_at IS NULL
  INNER JOIN {$p}equivalence_members em ON em.producto_base_id = pb.id AND em.activo=1
  WHERE cm.estado='sugerido'
  ORDER BY cm.id DESC LIMIT 1
\", ARRAY_A);
echo 'pick=' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$row) exit;
$svc = Riverso_Price_Lookup_Service::get_instance();
$payload = $svc->build_explorer_payload((int)$row['producto_base_id']);
$c = $payload['competencia'];
echo json_encode([
  'sku' => $payload['product']['canonical_sku'],
  'family' => $payload['family']['nombre'] ?? null,
  'members' => count($payload['family']['members'] ?? []),
  'familia_cmp' => is_array($c['comparacion_familia'] ?? null) ? count($c['comparacion_familia']) : 0,
  'sugeridos' => count($c['sugeridos']),
  'member0' => !empty($c['comparacion_familia'][0]) ? [
    'sku' => $c['comparacion_familia'][0]['canonical_sku'],
    'qty' => $c['comparacion_familia'][0]['cantidad_unidades'],
    'p_u' => $c['comparacion_familia'][0]['precio_unitario'],
    'rivales' => count($c['comparacion_familia'][0]['rivales'] ?? []),
    'delta0' => $c['comparacion_familia'][0]['rivales'][0]['delta'] ?? null,
  ] : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
""" % wp
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'), username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'), password=os.environ['RIVERSO_DEPLOY_PASSWORD'], timeout=30)
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag_comp5.php', 'w') as f:
        f.write(php)
    sftp.close()
    cmd = 'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_comp5.php'
    _, o, e = ssh.exec_command(cmd, timeout=90)
    print(o.read().decode('utf-8', 'replace'))
    ssh.close()

if __name__ == '__main__':
    main()
