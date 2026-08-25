#!/usr/bin/env python
"""Count catalog codes that have a usable local SKU via mapping or base."""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
for raw in (ROOT / '.env.deploy').read_text(encoding='utf-8').splitlines():
    line = raw.strip()
    if not line or line.startswith('#') or '=' not in line:
        continue
    k, v = line.split('=', 1)
    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

import paramiko

HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ['RIVERSO_DEPLOY_PASSWORD']
WP = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')

php = r'''
require "{wp}/wp-load.php";
global $wpdb;
$p = $wpdb->prefix . "riverso_";
$cat = (int) $wpdb->get_var("SELECT id FROM {$p}catalogos WHERE alias='mamut' LIMIT 1");

$map = function_exists('riverso_load_mamut_sku_mapping') ? riverso_load_mamut_sku_mapping() : [];
$usable = 0;
foreach ($map as $online => $local) {
  if (function_exists('riverso_usable_local_sku') && riverso_usable_local_sku($local, $online)) {
    $usable++;
  }
}
echo "map_entries=" . count($map) . " usable_pairs≈$usable (approx double keys)\n";

$rows = $wpdb->get_results($wpdb->prepare(
  "SELECT pp.id, pp.codigo_proveedor, pp.producto_base_id, pb.canonical_sku
   FROM {$p}producto_proveedor pp
   LEFT JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
   WHERE pp.activo = 1 AND (pp.catalogo_id = %d OR pp.origen_datos = 'catalogo')",
  $cat
), ARRAY_A);

$with_base_sku = 0;
$with_map_sku = 0;
$with_map_and_base_exists = 0;
$with_either = 0;
$samples = [];
foreach ($rows as $r) {
  $base_sku = trim((string) ($r['canonical_sku'] ?? ''));
  $mapped = function_exists('riverso_mamut_online_to_local_sku')
    ? riverso_mamut_online_to_local_sku($r['codigo_proveedor'])
    : null;
  $has_base = $base_sku !== '';
  $has_map = $mapped !== null && $mapped !== '';
  if ($has_base) $with_base_sku++;
  if ($has_map) {
    $with_map_sku++;
    $exists = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$p}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
      $mapped
    ));
    if ($exists) $with_map_and_base_exists++;
  }
  if ($has_base || $has_map) {
    $with_either++;
    if (count($samples) < 12) {
      $samples[] = [
        'code' => $r['codigo_proveedor'],
        'base_sku' => $base_sku,
        'mapped' => $mapped,
        'pp_base' => $r['producto_base_id'],
      ];
    }
  }
}
echo "catalog_activo=" . count($rows) . "\n";
echo "with_base_canonical_sku=$with_base_sku\n";
echo "with_mapping_local_sku=$with_map_sku\n";
echo "mapping_local_sku_base_exists=$with_map_and_base_exists\n";
echo "either_base_or_map=$with_either\n";
print_r($samples);
'''.replace('{wp}', WP)

import base64
import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
b64 = base64.b64encode(('<?php\n' + php).encode()).decode()
cmd = f"""
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
echo {b64} | base64 -d > /tmp/_diag_map.php
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=512M /tmp/_diag_map.php
rm -f /tmp/_diag_map.php
"""
stdin, stdout, stderr = ssh.exec_command(cmd, timeout=180)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('STDERR:', err[:2000])
ssh.close()
