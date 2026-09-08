#!/usr/bin/env python3
"""Ingiere barcodes TPV + asegura SKU 29731 + siembra baseline TPV.

Protocolo (producción vía SSH):
1) Crea/actualiza SKU 29731 (precio 4500, costo 2023, AQAC-001 legacy).
2) Importa códigos faltantes desde Downloads/codigos_barra.csv como propuesto
   + tarea confirmar_barcode_legacy (remap 129→12900).
3) Archiva el SKU erróneo 129 (duplicado de 12900).
4) Siembra un lote TPV aplicado sintético: productos actuales + barcodes del CSV,
   para que el export solo CREAR códigos nuevos en Riverso.
"""
from __future__ import annotations

import csv
import json
import os
from pathlib import Path

import pandas as pd
import paramiko

ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = Path(os.path.expanduser(r'~\Downloads\codigos_barra.csv'))
FACTO_XLSX = Path(os.path.expanduser(r'~\Downloads\productos (6).xlsx'))
SKU_REMAP = {'129': '12900'}

SKU_29731 = {
    'sku': '29731',
    'precio': 4500.0,
    'costo': 2023.0,
    'barcode': 'AQAC-001',
}


def load_env():
    path = ROOT / '.env.deploy'
    if not path.is_file():
        raise SystemExit(f'Falta {path}')
    for raw in path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def fstr(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return ''
    s = str(value).strip()
    return '' if s.lower() == 'nan' else s


def fnum(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    try:
        return round(float(value), 4)
    except (TypeError, ValueError):
        return None


def classify_barcode(code: str) -> str:
    code = code.strip()
    if 8 <= len(code) <= 18 and code.isdigit():
        return 'ean13'
    return 'supplier'


def load_facto_row(sku: str) -> dict:
    if not FACTO_XLSX.is_file():
        return {}
    df = pd.read_excel(FACTO_XLSX, sheet_name='Datos de producto', dtype=str)
    for _, row in df.iterrows():
        if fstr(row.get('SKU')) != sku:
            continue
        iva = fstr(row.get('Venta: afecto/exento de IVA')).lower() or 'afecto'
        if iva not in ('afecto', 'exento'):
            iva = 'afecto'
        return {
            'nombre': fstr(row.get('Nombre')) or f'Producto {sku}',
            'marca': fstr(row.get('Marca')),
            'modelo': fstr(row.get('Modelo')),
            'categoria': fstr(row.get('Categoria')),
            'unidad': fstr(row.get('Unidad')) or 'UN',
            'iva': iva,
            'barcode': fstr(row.get('Código de barras')),
            'costo': fnum(row.get('Costo neto')),
            'precio': fnum(row.get('Venta: Precio total')),
            'descripcion': fstr(row.get('Descripción')),
            'stock_minimo': fnum(row.get('Stock mínimo')),
        }
    return {}


def map_unidad(unit: str) -> str:
    unit = (unit or '').strip().upper()
    mapping = {
        'UN': 'unidad', 'UNIDAD': 'unidad', 'CAJA': 'caja', 'KG': 'kg',
        'LT': 'litro', 'LTS': 'litro', 'MT': 'metro', 'MTS': 'metro',
        'PAR': 'par', 'PACK': 'pack', 'SET': 'set',
    }
    return mapping.get(unit, 'unidad')


def parse_tpv_barcodes(path: Path):
    pairs = []
    empty_skus = []
    with path.open(encoding='utf-8-sig', newline='') as handle:
        for raw in csv.DictReader(handle):
            sku = (raw.get('sku') or '').strip()
            if not sku:
                continue
            sku = SKU_REMAP.get(sku, sku)
            field = (raw.get('codigo_barras') or '').strip()
            if not field:
                empty_skus.append(sku)
                continue
            for part in field.split('|'):
                code = part.strip()
                if code:
                    pairs.append({
                        'sku': sku,
                        'codigo': code,
                        'tipo': classify_barcode(code),
                    })
    # dedupe
    seen = set()
    unique = []
    for item in pairs:
        key = (item['sku'], item['codigo'])
        if key in seen:
            continue
        seen.add(key)
        unique.append(item)
    return unique, empty_skus


def main():
    load_env()
    if not CSV_PATH.is_file():
        raise SystemExit(f'No existe {CSV_PATH}')

    facto_meta = load_facto_row('29731')
    sku29731 = {
        **SKU_29731,
        'nombre': facto_meta.get('nombre') or 'Producto 29731',
        'marca': facto_meta.get('marca') or '',
        'modelo': facto_meta.get('modelo') or '',
        'categoria': facto_meta.get('categoria') or '',
        'unidad': map_unidad(facto_meta.get('unidad') or 'UN'),
        'iva': facto_meta.get('iva') or 'afecto',
        'descripcion': facto_meta.get('descripcion') or '',
        'stock_minimo': facto_meta.get('stock_minimo'),
        # Preferencia operativa del usuario (precio/costo TPV)
        'precio': SKU_29731['precio'],
        'costo': SKU_29731['costo'],
        'barcode': SKU_29731['barcode'] or facto_meta.get('barcode') or '',
    }

    pairs, empty_skus = parse_tpv_barcodes(CSV_PATH)
    print(f'CSV barcodes únicos: {len(pairs)}; SKUs vacíos: {len(empty_skus)}')
    print(f'29731 nombre FACTO: {sku29731["nombre"]!r}')

    payload = {
        'sku_29731': sku29731,
        'barcodes': pairs,
        'archive_sku': '129',
        'seed_baseline': True,
        'baseline_notas': 'Baseline sintético TPV 2026-09-05: productos actuales + barcodes del dump TPV (codigos_barra.csv, remap 129→12900)',
    }

    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = r'''<?php
require_once '{WP}/wp-load.php';
$facto_client = '{WP}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';
if (is_readable($facto_client)) {
    require_once $facto_client;
}
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$now = current_time('mysql');
$raw = file_get_contents('/tmp/tpv_finish_payload.json');
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "JSON inválido\n");
    exit(1);
}

$stats = [
    'sku_29731' => null,
    'barcodes_inserted' => 0,
    'barcodes_skipped_existing' => 0,
    'barcodes_skipped_no_sku' => 0,
    'tasks_created' => 0,
    'archived_129' => false,
    'baseline_batch_id' => 0,
    'baseline_products' => 0,
    'baseline_barcodes' => 0,
    'errors' => [],
];

function riverso_tpv_classify_tipo($codigo) {
    $codigo = trim((string) $codigo);
    $len = strlen($codigo);
    if ($len >= 8 && $len <= 18 && ctype_digit($codigo)) {
        return 'ean13';
    }
    return 'supplier';
}

function riverso_tpv_ensure_legacy_task($barcode_id, $product_id, $codigo, $product_name) {
    if (!class_exists('Riverso_Task_Module')) {
        return 0;
    }
    $id = Riverso_Task_Module::get_instance()->create_legacy_barcode_review_task(
        $barcode_id,
        $product_id,
        $codigo,
        $product_name
    );
    if (is_wp_error($id)) {
        return 0;
    }
    return (int) $id;
}

// --- 1) SKU 29731 ---
$item = $data['sku_29731'];
$sku = trim((string) ($item['sku'] ?? ''));
$nombre = trim((string) ($item['nombre'] ?? ''));
$pid_29731 = 0;
if ($sku !== '' && $nombre !== '') {
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, deleted_at, archived_at, estado FROM {$p}producto_base WHERE canonical_sku = %s LIMIT 1",
        $sku
    ), ARRAY_A);
    if ($existing) {
        $pid_29731 = (int) $existing['id'];
        $wpdb->update("{$p}producto_base", [
            'nombre_canonico' => $nombre,
            'unidad_base' => $item['unidad'] ?: 'unidad',
            'estado' => 'activo',
            'deleted_at' => null,
            'archived_at' => null,
            'marca' => $item['marca'] !== '' ? $item['marca'] : null,
            'modelo' => $item['modelo'] !== '' ? $item['modelo'] : null,
            'facto_categoria' => $item['categoria'] !== '' ? $item['categoria'] : null,
            'facto_iva_tipo' => in_array($item['iva'], ['afecto', 'exento'], true) ? $item['iva'] : 'afecto',
            'descripcion_facto' => $item['descripcion'] !== '' ? $item['descripcion'] : null,
            'stock_minimo' => $item['stock_minimo'],
            'updated_at' => $now,
        ], ['id' => $pid_29731]);
        $stats['sku_29731'] = ['action' => 'updated', 'id' => $pid_29731];
    } else {
        $ok = $wpdb->insert("{$p}producto_base", [
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre,
            'unidad_base' => $item['unidad'] ?: 'unidad',
            'estado' => 'activo',
            'marca' => $item['marca'] !== '' ? $item['marca'] : null,
            'modelo' => $item['modelo'] !== '' ? $item['modelo'] : null,
            'facto_categoria' => $item['categoria'] !== '' ? $item['categoria'] : null,
            'facto_iva_tipo' => in_array($item['iva'], ['afecto', 'exento'], true) ? $item['iva'] : 'afecto',
            'descripcion_facto' => $item['descripcion'] !== '' ? $item['descripcion'] : null,
            'stock_minimo' => $item['stock_minimo'],
            'created_by_system' => 1,
            'requires_human_review' => 0,
            'origen_datos' => 'tpv_facto_2026-09',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) {
            $stats['errors'][] = '29731 insert: ' . $wpdb->last_error;
        } else {
            $pid_29731 = (int) $wpdb->insert_id;
            $stats['sku_29731'] = ['action' => 'created', 'id' => $pid_29731];
        }
    }

    if ($pid_29731 > 0) {
        $precio_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}precios WHERE producto_base_id = %d AND canal = 'local' AND woocommerce_variation_id = 0",
            $pid_29731
        ));
        $price_data = [
            'c_ref' => $item['costo'],
            'p_asignado' => $item['precio'],
            'estado_aprobacion' => 'aprobado',
            'updated_at' => $now,
        ];
        if ($precio_id) {
            $wpdb->update("{$p}precios", $price_data, ['id' => (int) $precio_id]);
        } else {
            $price_data['producto_base_id'] = $pid_29731;
            $price_data['canal'] = 'local';
            $price_data['woocommerce_variation_id'] = 0;
            $price_data['created_by_system'] = 1;
            $price_data['created_at'] = $now;
            $wpdb->insert("{$p}precios", $price_data);
        }

        // FACTO map: producto ya existe en FACTO → buscar por SKU si hay client, si no pendiente_excel
        $map = $wpdb->get_row($wpdb->prepare(
            "SELECT id, facto_product_id FROM {$p}facto_producto_map WHERE producto_base_id = %d",
            $pid_29731
        ), ARRAY_A);
        $facto_id = null;
        if (class_exists('Riverso_Facto_Client')) {
            try {
                $client = new Riverso_Facto_Client();
                $resp = $client->list_products(['sku' => $sku, 'page' => 1]);
                $items = Riverso_Facto_Client::embed_collection($resp, 'products');
                if (!empty($items[0]['id'])) {
                    $facto_id = (int) $items[0]['id'];
                }
                if (!$facto_id && !empty($items[0]['product_id'])) {
                    $facto_id = (int) $items[0]['product_id'];
                }
                foreach ($items as $it) {
                    if (trim((string) ($it['sku'] ?? '')) === $sku) {
                        $facto_id = (int) ($it['product_id'] ?? $it['id'] ?? 0) ?: null;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $stats['errors'][] = 'facto lookup: ' . $e->getMessage();
            }
        }
        $map_data = [
            'facto_sku' => $sku,
            'sync_state' => $facto_id ? 'linked' : 'pendiente_excel',
            'last_error' => $facto_id ? null : 'Alta local; ya existe en FACTO — verificar vínculo',
            'updated_at' => $now,
        ];
        if ($facto_id) {
            $map_data['facto_product_id'] = $facto_id;
            $map_data['last_error'] = null;
            $map_data['sync_state'] = 'pendiente_excel'; // precio/costo locales pueden diferir → export EDITAR
            $map_data['last_error'] = 'Creado en Riverso; pendiente export Excel para alinear precio/costo';
        }
        if ($map) {
            $wpdb->update("{$p}facto_producto_map", $map_data, ['id' => (int) $map['id']]);
        } else {
            $map_data['producto_base_id'] = $pid_29731;
            $map_data['created_at'] = $now;
            if (!$facto_id) {
                $map_data['facto_product_id'] = null;
            }
            $wpdb->insert("{$p}facto_producto_map", $map_data);
        }
        $stats['sku_29731']['facto_product_id'] = $facto_id;
        $stats['sku_29731']['precio'] = $item['precio'];
        $stats['sku_29731']['costo'] = $item['costo'];
    }
}

// --- 2) Import barcodes ---
$sku_cache = [];
$get_pid = function ($sku) use (&$sku_cache, $wpdb, $p) {
    $sku = trim((string) $sku);
    if ($sku === '') {
        return 0;
    }
    if (!isset($sku_cache[$sku])) {
        $sku_cache[$sku] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
    }
    return $sku_cache[$sku];
};

$name_cache = [];
$get_name = function ($pid) use (&$name_cache, $wpdb, $p) {
    $pid = (int) $pid;
    if ($pid <= 0) {
        return '';
    }
    if (!isset($name_cache[$pid])) {
        $name_cache[$pid] = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT nombre_canonico FROM {$p}producto_base WHERE id = %d",
            $pid
        ));
    }
    return $name_cache[$pid];
};

foreach ((array) ($data['barcodes'] ?? []) as $row) {
    $sku = trim((string) ($row['sku'] ?? ''));
    $codigo = trim((string) ($row['codigo'] ?? ''));
    if ($sku === '' || $codigo === '') {
        continue;
    }
    $pid = $get_pid($sku);
    if ($pid <= 0) {
        $stats['barcodes_skipped_no_sku']++;
        continue;
    }
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$p}codigo_barra WHERE producto_base_id = %d AND codigo = %s LIMIT 1",
        $pid,
        $codigo
    ));
    if ($exists > 0) {
        $stats['barcodes_skipped_existing']++;
        // Asegurar tarea si es legacy propuesto
        $bc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, estado, origen_datos FROM {$p}codigo_barra WHERE id = %d",
            $exists
        ), ARRAY_A);
        if ($bc && ($bc['estado'] ?? '') === 'propuesto' && strpos((string) ($bc['origen_datos'] ?? ''), 'legacy') !== false) {
            $tid = riverso_tpv_ensure_legacy_task((int) $bc['id'], $pid, $codigo, $get_name($pid));
            if ($tid > 0) {
                $stats['tasks_created']++;
            }
        }
        continue;
    }

    $tipo = riverso_tpv_classify_tipo($codigo);
    $ok = $wpdb->insert("{$p}codigo_barra", [
        'codigo' => $codigo,
        'tipo' => $tipo,
        'producto_base_id' => $pid,
        'cantidad' => 1,
        'unidad_medida' => 'unidad',
        'factor_a_unidad_base' => 1,
        'activo' => 1,
        'estado' => 'propuesto',
        'motivo_estado' => 'Importado desde TPV codigos_barra.csv; requiere confirmación legacy',
        'estado_at' => $now,
        'origen_datos' => 'legacy_tpv',
        'requires_human_review' => 1,
        'sku_local' => $sku,
        'legacy_ref' => wp_json_encode([
            'fuente' => 'codigos_barra.csv',
            'sku_tpv' => $sku,
            'extraido' => '2026-09',
        ]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($ok === false) {
        $stats['errors'][] = "$sku|$codigo: " . $wpdb->last_error;
        continue;
    }
    $bid = (int) $wpdb->insert_id;
    $stats['barcodes_inserted']++;
    $tid = riverso_tpv_ensure_legacy_task($bid, $pid, $codigo, $get_name($pid));
    if ($tid > 0) {
        $stats['tasks_created']++;
    }
}

// --- 3) Archivar SKU 129 duplicado ---
$archive_sku = trim((string) ($data['archive_sku'] ?? ''));
if ($archive_sku !== '') {
    $dup = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$p}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
        $archive_sku
    ), ARRAY_A);
    if ($dup) {
        $wpdb->update("{$p}producto_base", [
            'archived_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int) $dup['id']]);
        $map_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}facto_producto_map WHERE producto_base_id = %d",
            (int) $dup['id']
        ));
        if ($map_id) {
            $wpdb->update("{$p}facto_producto_map", [
                'sync_state' => 'ignored',
                'last_error' => 'SKU 129 archivado; canónico es 12900',
                'updated_at' => $now,
            ], ['id' => (int) $map_id]);
        }
        $stats['archived_129'] = true;
        $stats['archived_129_id'] = (int) $dup['id'];
    }
}

// --- 4) Baseline TPV sintético ---
if (!empty($data['seed_baseline'])) {
    // Productos exportables actuales (misma idea que load_tpv_catalog, simplificado)
    $products = $wpdb->get_results(
        "SELECT pb.id, pb.canonical_sku AS sku, pb.nombre_canonico AS nombre, pl.p_asignado AS precio
         FROM {$p}producto_base pb
         INNER JOIN {$p}precios pl
           ON pl.producto_base_id = pb.id AND pl.canal = 'local' AND pl.woocommerce_variation_id = 0
         WHERE pb.deleted_at IS NULL AND pb.archived_at IS NULL AND pb.estado = 'activo'
           AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
           AND pl.p_asignado IS NOT NULL",
        ARRAY_A
    ) ?: [];

    $wpdb->insert("{$p}tpv_export_batches", [
        'alcance' => wp_json_encode([
            'synthetic' => true,
            'source' => 'codigos_barra.csv',
            'only_changed' => false,
        ]),
        'total_productos' => count($products),
        'total_barcodes' => 0, // se actualiza abajo
        'file_hash' => hash('sha256', 'synthetic-tpv-baseline-' . $now),
        'estado' => 'aplicado',
        'created_by' => null,
        'created_at' => $now,
        'applied_at' => $now,
        'notas' => (string) ($data['baseline_notas'] ?? 'Baseline sintético TPV'),
    ]);
    $batch_id = (int) $wpdb->insert_id;
    $stats['baseline_batch_id'] = $batch_id;

    $bc_count = 0;
    foreach ($products as $prod) {
        $sku = (string) $prod['sku'];
        $precio = number_format((float) $prod['precio'], 3, '.', '');
        $payload = [
            'SKU' => $sku,
            'Nombre' => (string) $prod['nombre'],
            'Precio' => $precio,
        ];
        $hash = hash('sha256', wp_json_encode($payload));
        $wpdb->insert("{$p}tpv_export_items", [
            'batch_id' => $batch_id,
            'entity_type' => 'producto',
            'entity_key' => $sku,
            'producto_base_id' => (int) $prod['id'],
            'sku' => $sku,
            'accion' => 'EDITAR',
            'row_hash' => $hash,
            'payload_json' => wp_json_encode($payload),
        ]);
    }
    $stats['baseline_products'] = count($products);

    // Barcodes del CSV (ya remapeados en el payload)
    $seen_bc = [];
    foreach ((array) ($data['barcodes'] ?? []) as $row) {
        $sku = trim((string) ($row['sku'] ?? ''));
        $codigo = trim((string) ($row['codigo'] ?? ''));
        if ($sku === '' || $codigo === '') {
            continue;
        }
        $key = $sku . '|' . $codigo;
        if (isset($seen_bc[$key])) {
            continue;
        }
        $seen_bc[$key] = true;
        $pid = $get_pid($sku);
        $payload = ['SKU' => $sku, 'CodigoBarras' => $codigo];
        $hash = hash('sha256', wp_json_encode($payload));
        $wpdb->insert("{$p}tpv_export_items", [
            'batch_id' => $batch_id,
            'entity_type' => 'barcode',
            'entity_key' => $key,
            'producto_base_id' => $pid ?: null,
            'sku' => $sku,
            'accion' => 'CREAR',
            'row_hash' => $hash,
            'payload_json' => wp_json_encode($payload),
        ]);
        $bc_count++;
    }
    $wpdb->update("{$p}tpv_export_batches", [
        'total_barcodes' => $bc_count,
    ], ['id' => $batch_id]);
    $stats['baseline_barcodes'] = $bc_count;
}

// Verificación puntual
$stats['verify'] = [
    '29731' => $wpdb->get_row($wpdb->prepare(
        "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, pl.p_asignado, pl.c_ref, fm.facto_product_id, fm.sync_state
         FROM {$p}producto_base pb
         LEFT JOIN {$p}precios pl ON pl.producto_base_id = pb.id AND pl.canal='local' AND pl.woocommerce_variation_id=0
         LEFT JOIN {$p}facto_producto_map fm ON fm.producto_base_id = pb.id
         WHERE pb.canonical_sku = %s",
        '29731'
    ), ARRAY_A),
    '129_estado' => $wpdb->get_row($wpdb->prepare(
        "SELECT id, estado, archived_at FROM {$p}producto_base WHERE canonical_sku = %s",
        '129'
    ), ARRAY_A),
    '12900_barcode_count' => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}codigo_barra cb
         INNER JOIN {$p}producto_base pb ON pb.id = cb.producto_base_id
         WHERE pb.canonical_sku = %s AND cb.activo = 1",
        '12900'
    )),
    'legacy_tasks_open' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$p}tareas
         WHERE tipo = 'confirmar_barcode_legacy' AND estado NOT IN ('completada','cancelada')"
    ),
    'tpv_applied_batch' => $wpdb->get_row(
        "SELECT id, total_productos, total_barcodes, estado, applied_at, notas
         FROM {$p}tpv_export_batches WHERE estado = 'aplicado' ORDER BY applied_at DESC, id DESC LIMIT 1",
        ARRAY_A
    ),
];

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
'''
    php = php.replace('{WP}', wp)

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/tpv_finish_payload.json', 'w') as handle:
        handle.write(json.dumps(payload, ensure_ascii=False))
    with sftp.open('/tmp/tpv_finish_exports.php', 'w') as handle:
        handle.write(php)
    sftp.close()

    print('Ejecutando en producción...')
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/tpv_finish_exports.php',
        timeout=600,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex-for-woocommerce' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print('STDERR:', clean[:3000])
    ssh.close()


if __name__ == '__main__':
    main()
