#!/usr/bin/env python3
"""Diagnóstico (solo lectura) folio 2440358: facturas, proceso, historial de precios y auditoría."""
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
FOLIO = sys.argv[1] if len(sys.argv) > 1 else '2440358'


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


PHP = r"""<?php
require_once '__WP__/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$folio = '__FOLIO__';
function out($k, $v) { echo "=== $k ===\n" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"; }

$facts = $wpdb->get_results($wpdb->prepare(
  "SELECT id, tipo_dte, folio, rut_emisor, razon_social_emisor, proveedor_id, origen_ingreso, estado,
          documento_subtipo, modo_ingreso, fecha_emision, monto_total, items_total, created_at, updated_at
   FROM {$p}facturas WHERE TRIM(LEADING '0' FROM folio) = %s ORDER BY id", $folio), ARRAY_A);
out('facturas', $facts);
$ids = array_map(fn($r) => (int) $r['id'], $facts);

foreach ($ids as $fid) {
  out("proceso #$fid", $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}precio_folio_proceso WHERE factura_id=%d", $fid), ARRAY_A));
  out("items #$fid", $wpdb->get_results($wpdb->prepare(
    "SELECT id, numero_linea, codigo_proveedor, nombre, sku_local, product_id, estado, item_tipo, cantidad
     FROM {$p}factura_items WHERE factura_id=%d ORDER BY numero_linea", $fid), ARRAY_A));
  out("hist por source_document_id #$fid", $wpdb->get_results($wpdb->prepare(
    "SELECT id, producto_base_id, canal, p_asignado_anterior, p_asignado_nuevo, source_type, notas, created_at
     FROM {$p}precio_historial WHERE source_document_id=%d ORDER BY id", $fid), ARRAY_A));
}

// Historial por texto de notas (sobrevive aunque cambie source_document_id)
$hist_notas = $wpdb->get_results($wpdb->prepare(
  "SELECT h.id, h.producto_base_id, pb.canonical_sku, h.canal, h.p_asignado_nuevo, h.source_type,
          h.source_document_id, h.notas, h.created_at,
          (SELECT COUNT(*) FROM {$p}facturas f WHERE f.id = h.source_document_id) AS factura_existe
   FROM {$p}precio_historial h
   LEFT JOIN {$p}producto_base pb ON pb.id = h.producto_base_id
   WHERE h.notas LIKE %s ORDER BY h.id", '%' . $wpdb->esc_like($folio) . '%'), ARRAY_A);
out('hist por notas', $hist_notas);

// Estado que la app calcula hoy para cada factura
if (class_exists('Riverso_Folio_Price_Process_Service')) {
  $svc = Riverso_Folio_Price_Process_Service::get_instance();
  $rp = new ReflectionMethod($svc, 'resolve_item_product'); $rp->setAccessible(true);
  $rt = new ReflectionMethod($svc, 'resolve_price_target'); $rt->setAccessible(true);
  foreach ($facts as $f) {
    $fid = (int) $f['id'];
    out("progress #$fid", $svc->count_folio_price_progress($fid));
    $items = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$p}factura_items WHERE factura_id=%d AND (item_tipo='producto' OR item_tipo IS NULL OR item_tipo='') ORDER BY numero_linea", $fid), ARRAY_A);
    $res = [];
    foreach ($items as $it) {
      $prod = $rp->invoke($svc, $it, (int) $f['proveedor_id']);
      $tgt = $prod ? $rt->invoke($svc, (int) $prod['producto_base_id']) : null;
      $res[] = [
        'item_id' => (int) $it['id'], 'linea' => $it['numero_linea'], 'codigo' => $it['codigo_proveedor'],
        'sku_local' => $it['sku_local'], 'pb' => $prod['producto_base_id'] ?? null, 'sku' => $prod['sku'] ?? null,
        'target' => $tgt['target_id'] ?? null, 'grupo' => $tgt['grupo_id'] ?? null,
      ];
    }
    out("resolucion actual #$fid", $res);
  }
}

if (getenv('DIAG_CODES') === '2') {
  $targets = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT producto_base_id FROM {$p}precio_historial WHERE source_type='folio' AND source_document_id IN (" . implode(',', $ids ?: [0]) . ")"));
  $rows = [];
  foreach ($targets as $t) {
    $rows[] = [
      'pb' => (int) $t,
      'sku' => $wpdb->get_var($wpdb->prepare("SELECT canonical_sku FROM {$p}producto_base WHERE id=%d", $t)),
      'precios' => $wpdb->get_results($wpdb->prepare(
        "SELECT canal, p_asignado, en_uso, updated_at FROM {$p}precios WHERE producto_base_id=%d", $t), ARRAY_A),
      'hist_ultimos' => $wpdb->get_results($wpdb->prepare(
        "SELECT id, canal, p_asignado_anterior, p_asignado_nuevo, source_type, source_document_id, notas, created_at
         FROM {$p}precio_historial WHERE producto_base_id=%d ORDER BY id DESC LIMIT 4", $t), ARRAY_A),
    ];
  }
  out('precios vigentes vs historial', $rows);
  exit;
}

if (getenv('DIAG_CODES') === '1') {
  foreach ($ids as $fid) {
    $prov = (int) $wpdb->get_var($wpdb->prepare("SELECT proveedor_id FROM {$p}facturas WHERE id=%d", $fid));
    $codes = $wpdb->get_col($wpdb->prepare(
      "SELECT codigo_proveedor FROM {$p}factura_items WHERE factura_id=%d AND (sku_local IS NULL OR sku_local='')", $fid));
    $rows = [];
    foreach ($codes as $c) {
      $core = ltrim(preg_replace('/\s+/', '', $c), '0');
      $pp = $wpdb->get_results($wpdb->prepare(
        "SELECT pp.id, pp.proveedor_id, pp.codigo_proveedor, pp.activo, pp.producto_base_id, pb.canonical_sku,
                pb.deleted_at, pp.updated_at, pp.vinculo_origen, pp.vinculo_factura_id
         FROM {$p}producto_proveedor pp LEFT JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
         WHERE REPLACE(pp.codigo_proveedor,' ','') LIKE %s", '%' . $wpdb->esc_like($core)), ARRAY_A);
      $tasks = $wpdb->get_results($wpdb->prepare(
        "SELECT id, tipo, estado, created_at, updated_at FROM {$p}tareas
         WHERE referencia_tipo='factura_item' AND referencia_id IN (SELECT id FROM {$p}factura_items WHERE factura_id=%d AND codigo_proveedor=%s)",
        $fid, $c), ARRAY_A);
      $rows[] = ['codigo' => $c, 'pp' => $pp, 'tareas' => $tasks];
    }
    out("codigos sin sku #$fid (proveedor $prov)", $rows);
  }
  out('audit sku_mapping_cleared/desvinculos recientes', $wpdb->get_results(
    "SELECT id, action, entity_type, entity_id, entity_name, user_name, details, created_at FROM {$p}audit_log
     WHERE (action LIKE '%clear%' OR action LIKE '%unlink%' OR action LIKE '%desvinc%' OR details LIKE '%→ —%')
       AND created_at >= '2026-09-01' ORDER BY id DESC LIMIT 60", ARRAY_A));
  exit;
}

// Auditoría: cualquier columna que mencione el folio o los ids
$cols = $wpdb->get_col("SHOW COLUMNS FROM {$p}audit_log");
out('audit_log cols', $cols);
$concat = 'CONCAT_WS(\'|\',' . implode(',', array_map(fn($c) => "`$c`", $cols)) . ')';
$conds = [$wpdb->prepare("$concat LIKE %s", '%' . $folio . '%')];
foreach ($ids as $fid) {
  if (in_array('entity_id', $cols, true)) $conds[] = $wpdb->prepare('(entity_id = %d)', $fid);
}
out('audit', $wpdb->get_results(
  "SELECT * FROM {$p}audit_log WHERE " . implode(' OR ', $conds) . " ORDER BY id DESC LIMIT 80", ARRAY_A));
out('merges recientes', $wpdb->get_results(
  "SELECT * FROM {$p}audit_log WHERE action LIKE '%duplicate%' ORDER BY id DESC LIMIT 30", ARRAY_A));
"""


def main():
    load_env()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = PHP.replace('__WP__', wp).replace('__FOLIO__', FOLIO)

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/diag_folio.php', 'w') as handle:
        handle.write(php)
    sftp.close()
    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        f'sudo -u riverso.cl_1xybiw6rlcq env DIAG_CODES={os.environ.get("DIAG_CODES", "0")} "$PHP_BIN" /tmp/diag_folio.php'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=120)
    sys.stdout.reconfigure(encoding='utf-8')
    print(stdout.read().decode('utf-8', errors='replace'))
    err = stderr.read().decode('utf-8', errors='replace')
    if err.strip():
        print('STDERR:', err[:3000])
    ssh.close()


if __name__ == '__main__':
    main()
