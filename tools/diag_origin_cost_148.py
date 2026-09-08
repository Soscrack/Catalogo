#!/usr/bin/env python3
"""Diagnóstico remoto: origen costo estable (SKU 148) + modal guardar."""
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
$lookup = WP_PLUGIN_DIR . '/riverso-pos/modules/pricing/class-price-lookup-service.php';
$js = WP_PLUGIN_DIR . '/riverso-pos/assets/js/price-history.js';
$tpl = WP_PLUGIN_DIR . '/riverso-pos/templates/partials/price-explorer.php';
echo 'ver=' . (defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '?') . PHP_EOL;
echo 'has_cost_change_logic=' . (strpos(file_get_contents($lookup), 'force_cost_src') !== false ? '1' : '0') . PHP_EOL;
echo 'has_save_modal_tpl=' . (strpos(file_get_contents($tpl), 'rpe-save-modal') !== false ? '1' : '0') . PHP_EOL;
echo 'has_save_modal_js=' . (strpos(file_get_contents($js), 'openSaveConfirmModal') !== false ? '1' : '0') . PHP_EOL;
if (!class_exists('Riverso_Price_Lookup_Service')) {{
  require_once $lookup;
}}
$svc = Riverso_Price_Lookup_Service::get_instance();
$hits = $svc->search('148', 5);
$h = null;
foreach ($hits as $hit) {{
  if ((string)($hit['canonical_sku'] ?? '') === '148') {{ $h = $hit; break; }}
}}
if (!$h) {{ $h = $hits[0] ?? null; }}
echo json_encode([
  'sku' => $h['canonical_sku'] ?? null,
  'pb' => $h['producto_base_id'] ?? null,
  'origen_precio' => $h['origen_precio'] ?? null,
  'origen_costo' => $h['origen_costo'] ?? null,
  'c_ref' => $h['local']['c_ref'] ?? null,
  'p_asignado' => $h['p_asignado'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
if (!empty($h['producto_base_id'])) {{
  $payload = $svc->build_explorer_payload((int)$h['producto_base_id']);
  if (!is_wp_error($payload)) {{
    echo 'explorer_cost=' . json_encode($payload['local']['origen_costo'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo 'explorer_precio=' . json_encode($payload['local']['origen_precio'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
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
    with sftp.open('/tmp/diag_origin_cost_148.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/diag_origin_cost_148.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=60)
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        print('STDERR:', err)
    ssh.close()


if __name__ == '__main__':
    main()
