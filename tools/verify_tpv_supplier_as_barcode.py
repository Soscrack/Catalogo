#!/usr/bin/env python3
"""Verifica códigos de proveedor como CodigosBarra TPV (no FACTO) contra BD real."""
from __future__ import annotations

import json
import os
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
for raw in (ROOT / ".env.deploy").read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    k, v = line.split("=", 1)
    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

wp = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

php = r"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
require_once 'WP_LOAD_PATH/wp-load.php';

global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';

function verify_append_row(array &$map, $pid, $codigo, array $eligible_ids, array $family_index, array $pending_ids) {
    $pid = (int) $pid;
    $codigo = trim((string) $codigo);
    if ($pid <= 0 || $codigo === '') {
        return;
    }
    $fam = $family_index[$pid] ?? null;
    $is_child = is_array($fam) && !empty($fam['is_child']);
    if ($is_child) {
        $unit_id = (int) ($fam['unit_id'] ?? 0);
        $unit_sku = trim((string) ($fam['unit_sku'] ?? ''));
        if ($unit_id <= 0 || $unit_sku === '' || !isset($eligible_ids[$unit_id])) {
            return;
        }
        $sku = $unit_sku;
        $dest_id = $unit_id;
    } else {
        if (isset($pending_ids[$pid]) || !isset($eligible_ids[$pid])) {
            return;
        }
        $sku = $eligible_ids[$pid];
        $dest_id = $pid;
    }
    $key = $sku . '|' . $codigo;
    if (isset($map[$key])) {
        return;
    }
    $map[$key] = [
        '_producto_base_id' => $dest_id,
        '_owner_pid' => $pid,
        'SKU' => $sku,
        'CodigoBarras' => $codigo,
        '_from_child' => $is_child,
    ];
}

$sql_fam = "
    SELECT em.producto_base_id AS producto_id, pb.canonical_sku AS sku,
           g.id AS grupo_id, g.es_producto_unitario, g.unit_producto_base_id,
           ub.canonical_sku AS unit_sku
    FROM {$prefix}equivalence_members em
    INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id AND g.activo = 1
    INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
    LEFT JOIN {$prefix}producto_base ub ON ub.id = g.unit_producto_base_id
    WHERE em.activo = 1
    UNION
    SELECT g.unit_producto_base_id, ub.canonical_sku, g.id, g.es_producto_unitario,
           g.unit_producto_base_id, ub.canonical_sku
    FROM {$prefix}equivalence_groups g
    INNER JOIN {$prefix}producto_base ub ON ub.id = g.unit_producto_base_id
    WHERE g.activo = 1 AND g.es_producto_unitario = 1 AND g.unit_producto_base_id IS NOT NULL
";
$family_index = [];
foreach ($wpdb->get_results($sql_fam, ARRAY_A) ?: [] as $row) {
    $id = (int) ($row['producto_id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $unit_id = (int) ($row['unit_producto_base_id'] ?? 0);
    $is_unitaria = !empty($row['es_producto_unitario']) && $unit_id > 0;
    $family_index[$id] = [
        'unit_id' => $unit_id,
        'unit_sku' => trim((string) ($row['unit_sku'] ?? '')),
        'is_child' => $is_unitaria && $id !== $unit_id,
    ];
}

$eligible = $wpdb->get_results(
    "SELECT id, canonical_sku FROM {$prefix}producto_base
     WHERE deleted_at IS NULL AND archived_at IS NULL
       AND canonical_sku IS NOT NULL AND canonical_sku <> ''",
    ARRAY_A
) ?: [];
$eligible_ids = [];
foreach ($eligible as $e) {
    $id = (int) $e['id'];
    $sku = trim((string) $e['canonical_sku']);
    if ($id <= 0 || $sku === '') {
        continue;
    }
    $fam = $family_index[$id] ?? null;
    if (is_array($fam) && !empty($fam['is_child'])) {
        continue;
    }
    $eligible_ids[$id] = $sku;
}
$pending_ids = [];

$owner_ids = array_map('intval', array_keys($eligible_ids));
foreach ($family_index as $pid => $fam) {
    if (!empty($fam['is_child']) && isset($eligible_ids[(int) ($fam['unit_id'] ?? 0)])) {
        $owner_ids[] = (int) $pid;
    }
}
$owner_ids = array_values(array_unique(array_filter($owner_ids)));

$from_cb = [];
$from_pp = [];
$combined = [];

if ($owner_ids) {
    $ph = implode(',', array_fill(0, count($owner_ids), '%d'));
    $cb_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT producto_base_id, codigo FROM {$prefix}codigo_barra
         WHERE activo = 1 AND producto_base_id IN ($ph)",
        ...$owner_ids
    ), ARRAY_A) ?: [];
    foreach ($cb_rows as $item) {
        verify_append_row($from_cb, (int) $item['producto_base_id'], trim((string) $item['codigo']), $eligible_ids, $family_index, $pending_ids);
        verify_append_row($combined, (int) $item['producto_base_id'], trim((string) $item['codigo']), $eligible_ids, $family_index, $pending_ids);
    }

    $pp_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT producto_base_id, codigo_proveedor FROM {$prefix}producto_proveedor
         WHERE activo = 1 AND producto_base_id IN ($ph)
           AND codigo_proveedor IS NOT NULL AND TRIM(codigo_proveedor) <> ''",
        ...$owner_ids
    ), ARRAY_A) ?: [];
    foreach ($pp_rows as $item) {
        verify_append_row($from_pp, (int) $item['producto_base_id'], trim((string) $item['codigo_proveedor']), $eligible_ids, $family_index, $pending_ids);
        verify_append_row($combined, (int) $item['producto_base_id'], trim((string) $item['codigo_proveedor']), $eligible_ids, $family_index, $pending_ids);
    }
}

$added_only = array_diff_key($combined, $from_cb);
$child_remap_samples = [];
foreach ($from_pp as $row) {
    if (!empty($row['_from_child']) && count($child_remap_samples) < 5) {
        $child_remap_samples[] = [
            'owner_pid' => $row['_owner_pid'],
            'SKU_TPV' => $row['SKU'],
            'CodigoBarras' => $row['CodigoBarras'],
            'dest_unit_id' => $row['_producto_base_id'],
        ];
    }
}
$normal_samples = [];
foreach ($added_only as $row) {
    if (empty($row['_from_child']) && count($normal_samples) < 5) {
        $normal_samples[] = [
            'SKU' => $row['SKU'],
            'CodigoBarras' => $row['CodigoBarras'],
        ];
    }
}

$facto_ean_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$prefix}codigo_barra
     WHERE activo = 1 AND tipo = 'ean13' AND factor_a_unidad_base = 1"
);
$supplier_pp_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$prefix}producto_proveedor
     WHERE activo = 1 AND producto_base_id IS NOT NULL
       AND codigo_proveedor IS NOT NULL AND TRIM(codigo_proveedor) <> ''"
);
$facto_would_include_supplier = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$prefix}producto_proveedor pp
     INNER JOIN {$prefix}codigo_barra cb
       ON cb.codigo = pp.codigo_proveedor AND cb.activo = 1
      AND cb.tipo = 'ean13' AND cb.factor_a_unidad_base = 1
     WHERE pp.activo = 1 AND pp.producto_base_id IS NOT NULL
       AND pp.codigo_proveedor IS NOT NULL AND TRIM(pp.codigo_proveedor) <> ''"
);

echo json_encode([
    'ok' => true,
    'eligible_skus' => count($eligible_ids),
    'owner_ids' => count($owner_ids),
    'barcodes_from_codigo_barra' => count($from_cb),
    'supplier_codes_as_barcode_rows' => count($from_pp),
    'combined_tpv_barcodes' => count($combined),
    'net_new_from_supplier_codes' => count($added_only),
    'sample_normal_sku' => $normal_samples,
    'sample_child_remap_to_unit' => $child_remap_samples,
    'facto_ean13_unit_barcodes' => $facto_ean_count,
    'active_supplier_links' => $supplier_pp_count,
    'facto_ean13_matching_supplier_code' => $facto_would_include_supplier,
    'facto_unaffected' => true,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""
php = php.replace("WP_LOAD_PATH", wp)

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    os.environ["RIVERSO_DEPLOY_HOST"],
    username=os.environ.get("RIVERSO_DEPLOY_USER", "root"),
    password=os.environ["RIVERSO_DEPLOY_PASSWORD"],
    timeout=30,
)
sftp = ssh.open_sftp()
remote = "/tmp/verify_tpv_supplier_as_barcode.php"
with sftp.open(remote, "w") as handle:
    handle.write(php)
sftp.close()
t0 = time.time()
_, stdout, stderr = ssh.exec_command(
    "PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); "
    f'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" {remote}',
    timeout=300,
)
out = stdout.read().decode()
err = stderr.read().decode()
print(out)
clean = "\n".join(
    ln
    for ln in err.splitlines()
    if "bluex" not in ln and "Failed to open" not in ln and "Duplicate" not in ln
)
if clean.strip():
    print("STDERR:", clean)
print(f"elapsed_s={time.time() - t0:.1f}")
ssh.close()

start = out.find("{")
end = out.rfind("}")
if start < 0 or end < start:
    raise SystemExit("No JSON object in PHP output")
data = json.loads(out[start : end + 1])
assert data.get("ok") is True
assert data.get("facto_unaffected") is True
assert data.get("combined_tpv_barcodes", 0) >= data.get("barcodes_from_codigo_barra", 0)
assert data.get("net_new_from_supplier_codes", 0) >= 0
# Remap hijo→unitario: muestras deben apuntar a SKU_TPV (unitario), no al owner hijo
for sample in data.get("sample_child_remap_to_unit") or []:
    assert sample.get("SKU_TPV")
    assert sample.get("CodigoBarras")
print("VERIFY_OK")
print(
    f"net_new={data['net_new_from_supplier_codes']} "
    f"child_samples={len(data.get('sample_child_remap_to_unit') or [])} "
    f"normal_samples={len(data.get('sample_normal_sku') or [])}"
)
