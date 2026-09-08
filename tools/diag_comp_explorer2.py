#!/usr/bin/env python3
"""Diag competencia: un SKU con mapeados confirmados."""
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
$row = $wpdb->get_row(
  \"SELECT cm.producto_base_id, pb.canonical_sku,
          (SELECT COUNT(*) FROM {$p}competencia_match cm2 WHERE cm2.producto_base_id=cm.producto_base_id AND cm2.estado='confirmado') AS n_map,
          (SELECT COUNT(*) FROM {$p}competencia_match cm3 WHERE cm3.producto_base_id=cm.producto_base_id AND cm3.estado='sugerido') AS n_sug
   FROM {$p}competencia_match cm
   INNER JOIN {$p}producto_base pb ON pb.id = cm.producto_base_id AND pb.deleted_at IS NULL
   WHERE cm.estado='confirmado' AND cm.producto_base_id IS NOT NULL
   ORDER BY n_map DESC, cm.id DESC
   LIMIT 1\",
  ARRAY_A
);
echo 'pick=' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
if (!$row) { exit; }
$svc = Riverso_Price_Lookup_Service::get_instance();
$payload = $svc->build_explorer_payload((int)$row['producto_base_id']);
if (is_wp_error($payload)) {
  echo 'err=' . $payload->get_error_message() . PHP_EOL;
  exit;
}
$c = $payload['competencia'];
$m0 = $c['mapeados'][0] ?? null;
$f0 = !empty($c['comparacion_familia'][0]) ? $c['comparacion_familia'][0] : null;
echo json_encode([
  'sku' => $payload['product']['canonical_sku'] ?? null,
  'p_asignado' => $payload['local']['p_asignado'] ?? null,
  'mapeados' => count($c['mapeados'] ?? []),
  'sugeridos' => count($c['sugeridos'] ?? []),
  'familia' => $payload['family'] ? ($payload['family']['nombre'] ?? true) : null,
  'familia_cmp' => is_array($c['comparacion_familia'] ?? null) ? count($c['comparacion_familia']) : 0,
  'sample' => $m0 ? [
    'rival' => $m0['nombre'] ?? null,
    'nuestro' => $m0['nuestro_precio'] ?? null,
    'rival_u' => $m0['rival_unitario'] ?? null,
    'delta' => $m0['delta'] ?? null,
    'delta_pct' => $m0['delta_pct'] ?? null,
    'tipo' => $m0['tipo_match_label'] ?? null,
  ] : null,
  'family_sample' => $f0 ? [
    'sku' => $f0['canonical_sku'] ?? null,
    'p_u' => $f0['precio_unitario'] ?? null,
    'rivales' => count($f0['rivales'] ?? []),
    'delta0' => isset($f0['rivales'][0]['delta']) ? $f0['rivales'][0]['delta'] : null,
  ] : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
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
    with sftp.open('/tmp/diag_comp_explorer2.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_comp_explorer2.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=120)
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        # only print non-noise
        lines = [ln for ln in err.splitlines() if 'bluex' not in ln and 'Duplicate' not in ln]
        if lines:
            print('STDERR:', '\n'.join(lines)[:1500])
    ssh.close()


if __name__ == '__main__':
    main()
