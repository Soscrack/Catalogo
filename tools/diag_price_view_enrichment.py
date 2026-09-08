#!/usr/bin/env python3
"""Diagnóstico remoto: neto/bruto + orígenes en price lookup."""
import json
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
    php = f'''<?php
require_once '{wp}/wp-load.php';
$path = WP_PLUGIN_DIR . '/riverso-pos/modules/pricing/class-price-lookup-service.php';
$js = WP_PLUGIN_DIR . '/riverso-pos/assets/js/price-history.js';
$tpl = WP_PLUGIN_DIR . '/riverso-pos/templates/partials/price-explorer.php';
echo 'lookup_has_cbruto=' . (strpos(file_get_contents($path), 'c_ref_bruto') !== false ? '1' : '0') . PHP_EOL;
echo 'pricing_has_gross=' . (method_exists('Riverso_Pricing_Module', 'gross_from_net') ? '1' : '0') . PHP_EOL;
echo 'js_has_view=' . (strpos(file_get_contents($js), 'priceViewMode') !== false ? '1' : '0') . PHP_EOL;
echo 'tpl_has_toggle=' . (strpos(file_get_contents($tpl), 'rpe-view-toggle') !== false ? '1' : '0') . PHP_EOL;
if (!class_exists('Riverso_Price_Lookup_Service')) {{
  require_once $path;
}}
$svc = Riverso_Price_Lookup_Service::get_instance();
$hits = $svc->search('29068', 5);
$h = $hits[0] ?? null;
echo json_encode([
  'hits' => count($hits),
  'sku' => $h['canonical_sku'] ?? null,
  'c_ref_bruto' => $h['local']['c_ref_bruto'] ?? null,
  'c_ref_neto' => $h['local']['c_ref_neto'] ?? null,
  'p_ref_bruto' => $h['local']['p_ref_bruto'] ?? null,
  'p_ref_neto' => $h['local']['p_ref_neto'] ?? null,
  'p_asignado' => $h['p_asignado'] ?? null,
  'p_neto' => $h['p_neto'] ?? null,
  'margen_factor' => $h['local']['margen_factor'] ?? $h['margen_factor'] ?? null,
  'margen_unitario' => $h['local']['margen_unitario'] ?? null,
  'origen_precio' => $h['origen_precio'] ?? null,
  'origen_costo' => $h['origen_costo'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
if (!empty($h['producto_base_id'])) {{
  $payload = $svc->build_explorer_payload((int) $h['producto_base_id']);
  if (is_wp_error($payload)) {{
    echo 'explorer_err=' . $payload->get_error_message() . PHP_EOL;
  }} else {{
    $l = $payload['local'];
    echo 'ok c_bruto=' . $l['c_ref_bruto'] . ' c_neto=' . $l['c_ref_neto']
      . ' p_ref_bruto=' . $l['p_ref_bruto'] . ' p_asig=' . $l['p_asignado']
      . ' p_neto=' . $l['p_neto'] . ' margen=' . $l['margen_factor'] . PHP_EOL;
  }}
}}
'''
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag_price_view.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_price_view.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=120)
    print(stdout.read().decode(errors='replace'))
    err = stderr.read().decode(errors='replace')
    if err.strip():
        print('STDERR:', err[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
