#!/usr/bin/env python
"""
Repair bad riverso_codigos pile onto local SKUs (12900, 827, 815, 853, ...).

1) Unlink inactive producto_proveedor rows still pointing at a base (origen=riverso_codigos).
2) Detach supplier barcodes migrated from riverso_codigos whose legacy sku_local
   does not match the base canonical_sku.
3) Restore known-correct local links from riverso_codigos (50ATPF-G→12900, 09RTP→827, 10RTP→815).
"""
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

APPLY = os.environ.get('APPLY_REPAIR', '0') == '1'

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

php = r'''
require "{wp}/wp-load.php";
global $wpdb;
$p = $wpdb->prefix . "riverso_";
$apply = {apply};
$now = current_time("mysql");
$report = ["apply" => (bool)$apply];

// --- 1) Unlink inactive riverso_codigos pp still pointing at bases ---
$sql_pp = "SELECT id, codigo_proveedor, proveedor_id, producto_base_id
           FROM {$p}producto_proveedor
           WHERE origen_datos='riverso_codigos' AND activo=0 AND producto_base_id IS NOT NULL";
$pp_rows = $wpdb->get_results($sql_pp, ARRAY_A);
$report["pp_unlink_candidates"] = count($pp_rows);
if ($apply && $pp_rows) {
  $n = $wpdb->query(
    "UPDATE {$p}producto_proveedor
     SET producto_base_id = NULL, match_estado = 'UNMATCHED', updated_at = '{$now}'
     WHERE origen_datos='riverso_codigos' AND activo=0 AND producto_base_id IS NOT NULL"
  );
  $report["pp_unlinked"] = (int)$n;
}

// --- 2) Detach bad supplier barcodes on local-SKU bases ---
$sql_cb = "SELECT cb.id, cb.codigo, cb.proveedor_id, cb.producto_base_id, pb.canonical_sku
           FROM {$p}codigo_barra cb
           INNER JOIN {$p}producto_base pb ON pb.id = cb.producto_base_id
           LEFT JOIN {$p}codigos c
             ON c.codigo_proveedor = cb.codigo
            AND c.proveedor_id = cb.proveedor_id
            AND c.sku_local = pb.canonical_sku
            AND COALESCE(c.activo,1) = 1
           WHERE cb.tipo = 'supplier'
             AND cb.migrado_de_tabla = 'riverso_codigos'
             AND cb.activo = 1
             AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
             AND c.id IS NULL";
$cb_rows = $wpdb->get_results($sql_cb, ARRAY_A);
$report["barcode_detach_candidates"] = count($cb_rows);
$by_sku = [];
foreach ($cb_rows as $r) {
  $sku = $r["canonical_sku"];
  if (!isset($by_sku[$sku])) $by_sku[$sku] = 0;
  $by_sku[$sku]++;
}
$report["barcode_detach_by_sku"] = $by_sku;
if ($apply && $cb_rows) {
  $ids = array_map("intval", array_column($cb_rows, "id"));
  $id_list = implode(",", $ids);
  $n = $wpdb->query(
    "UPDATE {$p}codigo_barra
     SET activo = 0,
         producto_base_id = NULL,
         estado = 'rechazado',
         motivo_estado = 'phase35: supplier barcode pegado a SKU local incorrecto',
         estado_at = '{$now}'
     WHERE id IN ($id_list)"
  );
  $report["barcodes_detached"] = (int)$n;
}

// --- 3) Restore correct local links from legacy codigos for known SKUs ---
$restores = [
  ["codigo" => "50ATPF-G", "proveedor_id" => 2, "sku" => "12900"],
  ["codigo" => "09RTP", "proveedor_id" => 2, "sku" => "827"],
  ["codigo" => "10RTP", "proveedor_id" => 2, "sku" => "815"],
];
$report["restores"] = [];
foreach ($restores as $r) {
  $base = $wpdb->get_row($wpdb->prepare(
    "SELECT id, canonical_sku, nombre_canonico FROM {$p}producto_base
     WHERE canonical_sku = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
    $r["sku"]
  ), ARRAY_A);
  $leg = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$p}codigos WHERE proveedor_id=%d AND codigo_proveedor=%s LIMIT 1",
    $r["proveedor_id"], $r["codigo"]
  ), ARRAY_A);
  $pp = $wpdb->get_row($wpdb->prepare(
    "SELECT id, producto_base_id, activo, origen_datos FROM {$p}producto_proveedor
     WHERE proveedor_id=%d AND codigo_proveedor=%s LIMIT 1",
    $r["proveedor_id"], $r["codigo"]
  ), ARRAY_A);
  $entry = [
    "codigo" => $r["codigo"],
    "sku" => $r["sku"],
    "base_id" => $base["id"] ?? null,
    "legacy_sku" => $leg["sku_local"] ?? null,
    "pp_before" => $pp,
  ];
  if (!$base) {
    $entry["status"] = "missing_base";
    $report["restores"][] = $entry;
    continue;
  }
  if (($leg["sku_local"] ?? "") !== $r["sku"]) {
    $entry["status"] = "legacy_mismatch_skip";
    $report["restores"][] = $entry;
    continue;
  }
  if ($apply) {
    if ($pp) {
      $wpdb->update(
        "{$p}producto_proveedor",
        [
          "producto_base_id" => (int)$base["id"],
          "activo" => 1,
          "match_estado" => "CONFIRMED",
          "updated_at" => $now,
        ],
        ["id" => (int)$pp["id"]]
      );
      $entry["pp_id"] = (int)$pp["id"];
      $entry["status"] = "pp_updated";
    } else {
      $wpdb->insert("{$p}producto_proveedor", [
        "producto_base_id" => (int)$base["id"],
        "proveedor_id" => (int)$r["proveedor_id"],
        "codigo_proveedor" => $r["codigo"],
        "nombre_proveedor" => $leg["nombre_proveedor"] ?? $base["nombre_canonico"],
        "activo" => 1,
        "origen_datos" => "riverso_codigos",
        "match_estado" => "CONFIRMED",
        "created_at" => $now,
        "updated_at" => $now,
      ]);
      $entry["pp_id"] = (int)$wpdb->insert_id;
      $entry["status"] = "pp_inserted";
    }
    // Ensure supplier barcode kept/reattached
    $cb = $wpdb->get_row($wpdb->prepare(
      "SELECT id FROM {$p}codigo_barra
       WHERE codigo=%s AND COALESCE(proveedor_id,0)=%d AND tipo='supplier'
       ORDER BY (producto_base_id=%d) DESC, id ASC LIMIT 1",
      $r["codigo"], $r["proveedor_id"], $base["id"]
    ), ARRAY_A);
    if ($cb) {
      $wpdb->update("{$p}codigo_barra", [
        "producto_base_id" => (int)$base["id"],
        "activo" => 1,
        "estado" => "verificado",
        "motivo_estado" => "phase35: restored correct local SKU link",
        "estado_at" => $now,
      ], ["id" => (int)$cb["id"]]);
      $entry["barcode_id"] = (int)$cb["id"];
    } else {
      $wpdb->insert("{$p}codigo_barra", [
        "codigo" => $r["codigo"],
        "tipo" => "supplier",
        "producto_base_id" => (int)$base["id"],
        "proveedor_id" => (int)$r["proveedor_id"],
        "cantidad" => 1,
        "unidad_medida" => "unidad",
        "factor_a_unidad_base" => 1,
        "activo" => 1,
        "estado" => "verificado",
        "origen_datos" => "manual",
        "migrado_de_tabla" => "phase35_restore",
        "created_at" => $now,
        "updated_at" => $now,
      ]);
      $entry["barcode_id"] = (int)$wpdb->insert_id;
    }
  } else {
    $entry["status"] = "dry_run";
  }
  $report["restores"][] = $entry;
}

// --- verify ---
foreach (["12900","827","815","853"] as $sku) {
  $base = $wpdb->get_row($wpdb->prepare(
    "SELECT id FROM {$p}producto_base WHERE canonical_sku=%s AND deleted_at IS NULL LIMIT 1", $sku), ARRAY_A);
  if (!$base) { $report["verify"][$sku] = "missing"; continue; }
  $bid = (int)$base["id"];
  $report["verify"][$sku] = [
    "base_id" => $bid,
    "pp_activo" => (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$p}producto_proveedor WHERE producto_base_id=%d AND activo=1", $bid)),
    "pp_inactivo_linked" => (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$p}producto_proveedor WHERE producto_base_id=%d AND activo=0", $bid)),
    "supplier_barcodes_activo" => (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$p}codigo_barra WHERE producto_base_id=%d AND tipo='supplier' AND activo=1", $bid)),
    "codes" => $wpdb->get_col($wpdb->prepare(
      "SELECT codigo FROM {$p}codigo_barra WHERE producto_base_id=%d AND tipo='supplier' AND activo=1", $bid)),
    "pp_codes" => $wpdb->get_col($wpdb->prepare(
      "SELECT codigo_proveedor FROM {$p}producto_proveedor WHERE producto_base_id=%d AND activo=1", $bid)),
  ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
'''.replace('{wp}', WP_PATH).replace('{apply}', 'true' if APPLY else 'false')

php_escaped = php.replace("'", "'\\''")
cmd = f"""
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=512M -r '{php_escaped}'
"""
stdin, stdout, stderr = ssh.exec_command(cmd, timeout=180)
out = stdout.read().decode()
err = stderr.read().decode()
print(out)
if err:
    print('STDERR:', err[:2000])
ssh.close()

out_path = ROOT / 'tests' / '_repair_bad_sku_links_result.json'
try:
    data = json.loads(out)
    out_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')
    print('Wrote', out_path)
except Exception as e:
    print('JSON parse failed:', e)
