#!/usr/bin/env python
"""Diagnose how many Mamut catalog codes have local SKU."""
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
echo "catalog_id=$cat\n";

$q1 = $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
    AND pb.deleted_at IS NULL AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   WHERE pp.activo = 1 AND (pp.catalogo_id = %d OR pp.origen_datos IN ('catalogo','computer'))",
  $cat
));
echo "catalog_pp_with_local_sku=$q1\n";

$q2 = $wpdb->get_var(
  "SELECT COUNT(*) FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
    AND pb.deleted_at IS NULL AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   WHERE pp.activo = 1 AND pp.origen_datos = 'legacy'"
);
echo "legacy_pp_with_local_sku=$q2\n";

$q3 = $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
    AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   WHERE pp.activo = 1
     AND pp.requires_human_review = 1 AND pp.review_status = 'pendiente'
     AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
     AND (pp.catalogo_id = %d OR pp.origen_datos IN ('catalogo','legacy'))",
  $cat
));
echo "already_pending_ui=$q3\n";

$q4 = $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id AND pb.canonical_sku <> ''
   WHERE pp.activo = 1 AND pp.match_estado = 'VERIFIED'
     AND (pp.catalogo_id = %d OR pp.origen_datos = 'catalogo')",
  $cat
));
echo "verified_catalog_with_sku=$q4\n";

echo "tasks_open=" . (int) $wpdb->get_var(
  "SELECT COUNT(*) FROM {$p}tareas WHERE tipo='confirmar_codigo_proveedor'
   AND estado IN ('pendiente','asignado','en_progreso')"
) . "\n";

// How many catalog+sku are NOT pending?
$miss = $wpdb->get_results($wpdb->prepare(
  "SELECT pp.id, pp.codigo_proveedor, pb.canonical_sku, pp.match_estado, pp.review_status,
          pp.requires_human_review, pp.origen_datos, pp.match_origen
   FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
    AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   WHERE pp.activo = 1
     AND (pp.catalogo_id = %d OR pp.origen_datos IN ('catalogo','legacy'))
     AND NOT (
       pp.requires_human_review = 1 AND pp.review_status = 'pendiente'
       AND COALESCE(pp.match_estado, '') <> 'VERIFIED'
     )
   ORDER BY pp.id DESC LIMIT 20",
  $cat
), ARRAY_A);
echo "not_pending_sample_count_query_total_hint:\n";
print_r($miss);

$total_should = (int) $wpdb->get_var($wpdb->prepare(
  "SELECT COUNT(*) FROM {$p}producto_proveedor pp
   INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
    AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   WHERE pp.activo = 1
     AND (pp.catalogo_id = %d OR pp.origen_datos IN ('catalogo','legacy'))
     AND COALESCE(pp.match_estado, '') <> 'VERIFIED'",
  $cat
));
echo "should_be_por_confirmar=$total_should\n";
'''.replace('{wp}', WP)

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
import base64
b64 = base64.b64encode(('<?php\n' + php).encode()).decode()
cmd = f"""
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
echo {b64} | base64 -d > /tmp/_diag_pending.php
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=512M /tmp/_diag_pending.php
rm -f /tmp/_diag_pending.php
"""
stdin, stdout, stderr = ssh.exec_command(cmd, timeout=90)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('STDERR:', err[:2000])
ssh.close()
