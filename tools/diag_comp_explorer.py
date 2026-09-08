#!/usr/bin/env python3
"""Diagnóstico remoto: bloque competencia en price explorer."""
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
$lookup = WP_PLUGIN_DIR . '/riverso-pos/modules/pricing/class-price-lookup-service.php';
$js = WP_PLUGIN_DIR . '/riverso-pos/assets/js/price-history.js';
$tpl = WP_PLUGIN_DIR . '/riverso-pos/templates/partials/price-explorer.php';
echo 'ver=' . (defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '?') . PHP_EOL;
echo 'has_mapeados=' . (strpos(file_get_contents($lookup), 'comparacion_familia') !== false ? '1' : '0') . PHP_EOL;
echo 'has_tpl=' . (strpos(file_get_contents($tpl), 'rpe-comp-sugeridos-body') !== false ? '1' : '0') . PHP_EOL;
echo 'has_js=' . (strpos(file_get_contents($js), 'normalizeCompetencia') !== false ? '1' : '0') . PHP_EOL;
if (!class_exists('Riverso_Price_Lookup_Service')) {
  require_once $lookup;
}
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$pb = (int) $wpdb->get_var(
  \"SELECT cm.producto_base_id FROM {$p}competencia_match cm
   WHERE cm.estado='confirmado' AND cm.producto_base_id IS NOT NULL
   ORDER BY cm.id DESC LIMIT 1\"
);
if ($pb <= 0) {
  $pb = (int) $wpdb->get_var(
    \"SELECT cm.producto_base_id FROM {$p}competencia_match cm
     WHERE cm.estado='sugerido' AND cm.producto_base_id IS NOT NULL
     ORDER BY cm.id DESC LIMIT 1\"
  );
}
echo \"pb=$pb\\n\";
if ($pb > 0) {
  $svc = Riverso_Price_Lookup_Service::get_instance();
  $payload = $svc->build_explorer_payload($pb);
  if (is_wp_error($payload)) {
    echo 'err=' . $payload->get_error_message() . PHP_EOL;
  } else {
    $c = $payload['competencia'];
    echo json_encode([
      'sku' => $payload['product']['canonical_sku'] ?? null,
      'is_object' => is_array($c) && isset($c['mapeados']),
      'mapeados' => is_array($c) ? count($c['mapeados'] ?? []) : 0,
      'sugeridos' => is_array($c) ? count($c['sugeridos'] ?? []) : 0,
      'familia_rows' => is_array($c) && !empty($c['comparacion_familia']) ? count($c['comparacion_familia']) : 0,
      'sample_mapeado' => is_array($c) && !empty($c['mapeados'][0]) ? [
        'nombre' => $c['mapeados'][0]['nombre'] ?? null,
        'nuestro' => $c['mapeados'][0]['nuestro_precio'] ?? null,
        'rival' => $c['mapeados'][0]['rival_unitario'] ?? null,
        'delta' => $c['mapeados'][0]['delta'] ?? null,
        'delta_pct' => $c['mapeados'][0]['delta_pct'] ?? null,
      ] : null,
      'admin_url' => is_array($c) ? ($c['admin_url'] ?? null) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
  }
}
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
    with sftp.open('/tmp/diag_comp_explorer.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_comp_explorer.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=90)
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        print('STDERR:', err[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
