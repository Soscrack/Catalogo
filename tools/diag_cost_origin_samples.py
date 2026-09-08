#!/usr/bin/env python3
"""Busca un SKU con c_ref + legacy para validar origen de costo."""
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
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$rows = $wpdb->get_results(\"
  SELECT pb.canonical_sku, pb.id AS pb_id, pr.c_ref, pr.p_asignado, l.costo_neto, l.precio_total
  FROM {$p}precios pr
  INNER JOIN {$p}producto_base pb ON pb.id = pr.producto_base_id
  INNER JOIN {$p}legacy_precio_ref l ON l.sku = pb.canonical_sku
  WHERE pr.canal='local' AND pr.woocommerce_variation_id=0
    AND pr.c_ref IS NOT NULL AND l.costo_neto IS NOT NULL AND l.costo_neto > 0
  ORDER BY l.importado_at DESC
  LIMIT 30
\", ARRAY_A);
if (!class_exists('Riverso_Price_Lookup_Service')) {
  require_once WP_PLUGIN_DIR . '/riverso-pos/modules/pricing/class-price-lookup-service.php';
}
$svc = Riverso_Price_Lookup_Service::get_instance();
$shown = 0;
foreach ($rows as $r) {
  $payload = $svc->build_explorer_payload((int)$r['pb_id']);
  if (is_wp_error($payload)) continue;
  $oc = $payload['local']['origen_costo'];
  $op = $payload['local']['origen_precio'];
  if (($oc['key'] ?? '') === '' && ($op['key'] ?? '') === '') continue;
  echo json_encode([
    'sku' => $r['canonical_sku'],
    'c_ref' => $r['c_ref'],
    'legacy_costo' => $r['costo_neto'],
    'origen_costo' => $oc,
    'origen_precio' => $op,
  ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
  $shown++;
  if ($shown >= 8) break;
}
echo \"shown=$shown\\n\";
""" % wp

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag_cost_origin_samples.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_cost_origin_samples.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=120)
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        print('STDERR:', err[:1500])
    ssh.close()


if __name__ == '__main__':
    main()
