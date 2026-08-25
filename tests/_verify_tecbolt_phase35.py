#!/usr/bin/env python
"""Verify phase35 Tecbolt unify on prod."""
import json
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env_path = ROOT / '.env.deploy'
if env_path.exists():
    for raw in env_path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

import paramiko

HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ['RIVERSO_DEPLOY_PASSWORD']
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

php = r'''
require "{wp}/wp-load.php";
global $wpdb;
$p = $wpdb->prefix . "riverso_";
$out = [];

$out["version"] = defined("RIVERSO_POS_VERSION") ? RIVERSO_POS_VERSION : null;
$out["phase35"] = get_option("riverso_pos_phase35_tecbolt_unify");
$out["report"] = get_option("riverso_pos_phase35_tecbolt_unify_report");

$out["mamut_supplier"] = $wpdb->get_results(
  "SELECT id, rut, nombre, activo FROM {$p}proveedores WHERE nombre='MAMUT' OR rut='MAMUT'", ARRAY_A);

$out["tecbolt"] = $wpdb->get_results(
  "SELECT id, rut, nombre, activo FROM {$p}proveedores WHERE nombre LIKE '%Tecbolt%'", ARRAY_A);

$tec_id = (int)($out["tecbolt"][0]["id"] ?? 0);
$out["apodos"] = $tec_id ? $wpdb->get_col($wpdb->prepare(
  "SELECT apodo FROM {$p}proveedor_apodos WHERE proveedor_id=%d", $tec_id)) : [];

$out["catalog"] = $wpdb->get_row(
  "SELECT id, proveedor_id, nombre, alias FROM {$p}catalogos WHERE alias='mamut' LIMIT 1", ARRAY_A);

$out["origen_dist"] = $wpdb->get_results(
  "SELECT origen_datos, COUNT(*) c FROM {$p}producto_proveedor GROUP BY origen_datos ORDER BY c DESC", ARRAY_A);

$out["pending_confirm"] = (int)$wpdb->get_var(
  "SELECT COUNT(*) FROM {$p}producto_proveedor
   WHERE activo=1 AND producto_base_id IS NOT NULL
     AND COALESCE(match_estado,'') <> 'VERIFIED'
     AND requires_human_review=1 AND review_status='pendiente'");

$out["tasks_open"] = (int)$wpdb->get_var(
  "SELECT COUNT(*) FROM {$p}tareas
   WHERE tipo='confirmar_codigo_proveedor' AND estado IN ('pendiente','asignado','en_progreso')");

foreach (["50ATPF-G","20CCIR","09RTP"] as $code) {
  $out["samples"][$code] = $wpdb->get_results($wpdb->prepare(
    "SELECT pp.id, pp.proveedor_id, prov.nombre AS proveedor, pp.origen_datos, pp.activo,
            pp.review_status, pp.requires_human_review, pp.match_estado, pb.canonical_sku
     FROM {$p}producto_proveedor pp
     LEFT JOIN {$p}proveedores prov ON prov.id=pp.proveedor_id
     LEFT JOIN {$p}producto_base pb ON pb.id=pp.producto_base_id
     WHERE pp.codigo_proveedor=%s ORDER BY pp.activo DESC, pp.id", $code), ARRAY_A);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
'''.replace('{wp}', WP_PATH)

php_escaped = php.replace("'", "'\\''")
cmd = f"""
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=512M -r '{php_escaped}'
"""
stdin, stdout, stderr = ssh.exec_command(cmd, timeout=120)
out = stdout.read().decode()
err = stderr.read().decode()
print(out)
if err:
    print('STDERR:', err[:2000])
ssh.close()
