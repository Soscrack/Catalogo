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
$stats = $wpdb->get_row(\"SELECT
  SUM(estado='confirmado') AS conf,
  SUM(estado='sugerido') AS sug
  FROM {$p}competencia_match\", ARRAY_A);
echo 'stats=' . json_encode($stats) . PHP_EOL;
$pb = (int) $wpdb->get_var(\"SELECT producto_base_id FROM {$p}competencia_match WHERE estado='sugerido' AND producto_base_id>0 ORDER BY id DESC LIMIT 1\");
$svc = Riverso_Price_Lookup_Service::get_instance();
$payload = $svc->build_explorer_payload($pb);
$c = is_wp_error($payload) ? null : $payload['competencia'];
$s0 = $c['sugeridos'][0] ?? null;
echo json_encode([
  'pb' => $pb,
  'sku' => is_wp_error($payload) ? null : ($payload['product']['canonical_sku'] ?? null),
  'p_asignado' => is_wp_error($payload) ? null : ($payload['local']['p_asignado'] ?? null),
  'mapeados' => $c ? count($c['mapeados']) : 0,
  'sugeridos' => $c ? count($c['sugeridos']) : 0,
  'sample_sug' => $s0 ? [
    'nombre' => $s0['nombre'] ?? null,
    'nuestro' => $s0['nuestro_precio'] ?? null,
    'rival' => $s0['rival_unitario'] ?? null,
    'delta' => $s0['delta'] ?? null,
    'delta_pct' => $s0['delta_pct'] ?? null,
    'score' => $s0['score'] ?? null,
    'metodo' => $s0['metodo'] ?? null,
  ] : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
""" % wp
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'), username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'), password=os.environ['RIVERSO_DEPLOY_PASSWORD'], timeout=30)
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag_comp3.php', 'w') as f:
        f.write(php)
    sftp.close()
    cmd = 'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_comp3.php'
    _, o, e = ssh.exec_command(cmd, timeout=90)
    print(o.read().decode('utf-8', 'replace'))
    ssh.close()

if __name__ == '__main__':
    main()
