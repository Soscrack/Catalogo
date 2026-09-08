#!/usr/bin/env python3
"""Crea SKU locales faltantes desde el Excel FACTO/legacy y el CSV TPV.

Protocolo:
1) Lee productos (6).xlsx (catálogo FACTO) + TPV/productos_legacy.csv
2) En producción, inserta producto_base + precio local + barcode si falta
   el canonical_sku. No dispara sync API FACTO (solo marca pendiente_excel).
"""
import csv
import json
import os
from pathlib import Path

import pandas as pd
import paramiko

ROOT = Path(__file__).resolve().parents[1]
XLSX = Path(os.path.expanduser(r'~\Downloads\productos (6).xlsx'))
TPV_CSV = ROOT / 'TPV' / 'productos_legacy.csv'


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


def fstr(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return ''
    s = str(value).strip()
    if s.lower() == 'nan':
        return ''
    return s


def fnum(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    try:
        return round(float(value), 4)
    except (TypeError, ValueError):
        return None


def map_unidad(unit):
    unit = (unit or '').strip().upper()
    mapping = {
        'UN': 'unidad',
        'UNIDAD': 'unidad',
        'CAJA': 'caja',
        'KG': 'kg',
        'LT': 'litro',
        'LTS': 'litro',
        'MT': 'metro',
        'MTS': 'metro',
        'PAR': 'par',
        'PACK': 'pack',
        'SET': 'set',
    }
    return mapping.get(unit, 'unidad')


def build_catalog():
    catalog = {}

    if TPV_CSV.is_file():
        with TPV_CSV.open(encoding='utf-8') as handle:
            for row in csv.DictReader(handle):
                sku = (row.get('sku') or '').strip()
                if not sku:
                    continue
                precio = fnum(row.get('precio'))
                coste = fnum(row.get('coste'))
                catalog[sku] = {
                    'sku': sku,
                    'nombre': (row.get('nombre') or '').strip(),
                    'marca': '',
                    'modelo': '',
                    'categoria': '',
                    'unidad': 'unidad',
                    'barcode': '',
                    'iva': 'afecto',
                    'costo': coste,
                    'precio': precio,
                    'stock_minimo': None,
                    'descripcion': '',
                    'fuente': 'tpv_legacy',
                }

    df = pd.read_excel(XLSX, sheet_name='Datos de producto')
    for _, row in df.iterrows():
        sku = fstr(row.get('SKU'))
        if not sku:
            continue
        iva = fstr(row.get('Venta: afecto/exento de IVA')).lower() or 'afecto'
        if iva not in ('afecto', 'exento'):
            iva = 'afecto'
        prev = catalog.get(sku, {})
        catalog[sku] = {
            'sku': sku,
            'nombre': fstr(row.get('Nombre')) or prev.get('nombre') or sku,
            'marca': fstr(row.get('Marca')),
            'modelo': fstr(row.get('Modelo')),
            'categoria': fstr(row.get('Categoria')),
            'unidad': map_unidad(fstr(row.get('Unidad'))),
            'barcode': fstr(row.get('Código de barras')),
            'iva': iva,
            'costo': prev.get('costo') if prev.get('costo') is not None else fnum(row.get('Costo neto')),
            'precio': prev.get('precio') if prev.get('precio') is not None else fnum(row.get('Venta: Precio total')),
            'stock_minimo': fnum(row.get('Stock mínimo')),
            'descripcion': fstr(row.get('Descripción')),
            'fuente': 'productos_xlsx_2026-09-6',
        }

    return list(catalog.values())


def main():
    load_env()
    if not XLSX.is_file():
        raise SystemExit(f'No existe Excel: {XLSX}')

    items = build_catalog()
    print(f'Catálogo combinado: {len(items)} SKUs')

    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = r'''<?php
require_once '{WP}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$now = current_time('mysql');
$raw = file_get_contents('/tmp/legacy_local_skus.json');
$items = json_decode($raw, true);
if (!is_array($items)) {
    fwrite(STDERR, "JSON inválido\n");
    exit(1);
}

$created = 0;
$restored = 0;
$priced = 0;
$barcodes = 0;
$skipped = 0;
$errors = [];

foreach ($items as $item) {
    $sku = trim((string) ($item['sku'] ?? ''));
    $nombre = trim((string) ($item['nombre'] ?? ''));
    if ($sku === '' || $nombre === '') {
        $skipped++;
        continue;
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, deleted_at, archived_at, estado FROM {$p}producto_base WHERE canonical_sku = %s LIMIT 1",
        $sku
    ), ARRAY_A);

    $pid = 0;
    if ($existing) {
        $pid = (int) $existing['id'];
        if (!empty($existing['deleted_at']) || !empty($existing['archived_at']) || ($existing['estado'] ?? '') !== 'activo') {
            $wpdb->update(
                "{$p}producto_base",
                [
                    'deleted_at' => null,
                    'archived_at' => null,
                    'estado' => 'activo',
                    'updated_at' => $now,
                ],
                ['id' => $pid]
            );
            $restored++;
        } else {
            $skipped++;
            continue;
        }
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
            'origen_datos' => $item['fuente'] ?: 'legacy_xlsx',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) {
            $errors[] = $sku . ': ' . $wpdb->last_error;
            continue;
        }
        $pid = (int) $wpdb->insert_id;
        $created++;

        $map_table = $p . 'facto_producto_map';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map_table)) === $map_table) {
            $map_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$map_table} WHERE producto_base_id = %d",
                $pid
            ));
            if (!$map_id) {
                $wpdb->insert($map_table, [
                    'producto_base_id' => $pid,
                    'facto_product_id' => null,
                    'facto_sku' => $sku,
                    'sync_state' => 'pendiente_excel',
                    'last_error' => 'Alta pendiente de export Excel',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    if ($pid <= 0) {
        continue;
    }

    $precio = $item['precio'];
    $costo = $item['costo'];
    if ($precio !== null || $costo !== null) {
        $precio_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$p}precios WHERE producto_base_id = %d AND canal = 'local' AND woocommerce_variation_id = 0",
            $pid
        ));
        $price_data = [
            'c_ref' => $costo,
            'p_asignado' => $precio,
            'estado_aprobacion' => 'aprobado',
            'updated_at' => $now,
        ];
        if ($precio_id) {
            $wpdb->update("{$p}precios", $price_data, ['id' => (int) $precio_id]);
        } else {
            $price_data['producto_base_id'] = $pid;
            $price_data['canal'] = 'local';
            $price_data['woocommerce_variation_id'] = 0;
            $price_data['created_by_system'] = 1;
            $price_data['created_at'] = $now;
            $wpdb->insert("{$p}precios", $price_data);
        }
        $priced++;
    }

    $barcode = trim((string) ($item['barcode'] ?? ''));
    if ($barcode !== '') {
        $exists_bc = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}codigo_barra WHERE codigo = %s AND producto_base_id = %d",
            $barcode,
            $pid
        ));
        if ($exists_bc === 0) {
            $wpdb->insert("{$p}codigo_barra", [
                'codigo' => $barcode,
                'tipo' => (strlen($barcode) === 13 && ctype_digit($barcode)) ? 'ean13' : 'internal',
                'producto_base_id' => $pid,
                'cantidad' => 1,
                'unidad_medida' => 'unidad',
                'factor_a_unidad_base' => 1,
                'activo' => 1,
                'estado' => 'verificado',
                'origen_datos' => 'legacy_xlsx',
                'sku_local' => $sku,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $barcodes++;
        }
    }
}

echo json_encode([
    'input' => count($items),
    'created' => $created,
    'restored' => $restored,
    'priced' => $priced,
    'barcodes' => $barcodes,
    'skipped_existing' => $skipped,
    'errors' => array_slice($errors, 0, 20),
], JSON_UNESCAPED_UNICODE);
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
    with sftp.open('/tmp/legacy_local_skus.json', 'w') as handle:
        handle.write(json.dumps(items, ensure_ascii=False))
    with sftp.open('/tmp/create_legacy_local_skus.php', 'w') as handle:
        handle.write(php)
    sftp.close()

    print('Ejecutando creación de SKU locales...')
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/create_legacy_local_skus.php',
        timeout=300,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex-for-woocommerce' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print(clean[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
