<?php
/**
 * Backfill de presentaciones desde catálogo Mamut + mapeo SKU local.
 *
 * Uso:
 * wp riverso catalog backfill --catalog=/ruta/catalogo.json --mapping=/ruta/sku_mapping.json
 * wp riverso catalog backfill --catalog=/ruta/catalogo.json --mapping=/ruta/sku_mapping.json --apply
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Presentation_Backfill_Service {

    public static function register_cli() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command('riverso catalog backfill', [__CLASS__, 'cli']);
        }
    }

    public static function cli($args, $assoc_args) {
        $catalog = $assoc_args['catalog'] ?? '';
        $mapping = $assoc_args['mapping'] ?? '';
        $apply = isset($assoc_args['apply']);

        $result = self::run($catalog, $mapping, $apply);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (!$apply) {
            WP_CLI::warning('Dry-run: agrega --apply para guardar propuestas.');
        }
        WP_CLI::success('Backfill de presentaciones completado.');
    }

    /**
     * @return array|WP_Error
     */
    public static function run($catalog_path, $mapping_path, $apply = false) {
        if (!is_readable($catalog_path) || !is_readable($mapping_path)) {
            return new WP_Error('source_not_readable', 'No se pueden leer el catálogo o el mapeo indicado.');
        }

        $catalog = json_decode(file_get_contents($catalog_path), true);
        $mapping = json_decode(file_get_contents($mapping_path), true);
        if (!is_array($catalog) || !is_array($mapping)) {
            return new WP_Error('invalid_json', 'El catálogo o el mapeo no contienen JSON válido.');
        }

        $products = $catalog['products'] ?? [];
        if (!is_array($products)) {
            return new WP_Error('invalid_catalog', 'El catálogo no contiene la clave products.');
        }

        $stats = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'catalog_products' => count($products),
            'mapped' => 0,
            'proposed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'missing_mapping' => 0,
            'missing_product' => 0,
            'missing_quantity' => 0,
            'ambiguous_product' => 0,
            'errors' => [],
        ];

        foreach ($products as $supplier_code => $row) {
            $supplier_code = trim((string) $supplier_code);
            if ($supplier_code === '' || empty($mapping[$supplier_code])) {
                $stats['missing_mapping']++;
                continue;
            }

            $stats['mapped']++;
            $sku = trim((string) $mapping[$supplier_code]);
            $product = self::find_unique_product($sku);
            if (is_wp_error($product)) {
                $key = $product->get_error_code() === 'ambiguous_product'
                    ? 'ambiguous_product'
                    : 'missing_product';
                $stats[$key]++;
                $stats['errors'][] = [
                    'codigo' => $supplier_code,
                    'sku' => $sku,
                    'error' => $product->get_error_message(),
                ];
                continue;
            }

            $package = self::extract_package($row);
            if (!$package) {
                $stats['missing_quantity']++;
                self::record_missing_quantity_gap($product['id'], $supplier_code, $row, $apply);
                continue;
            }

            $stats['proposed']++;
            if (!$apply) {
                continue;
            }

            $saved = self::save_proposal($product, $supplier_code, $package);
            if (is_wp_error($saved)) {
                $stats['errors'][] = [
                    'codigo' => $supplier_code,
                    'sku' => $sku,
                    'error' => $saved->get_error_message(),
                ];
            } else {
                $stats[$saved]++;
            }
        }

        return $stats;
    }

    private static function find_unique_product($sku) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_producto_base';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, unidad_base
             FROM {$table}
             WHERE canonical_sku = %s AND estado = 'activo'
             LIMIT 2",
            $sku
        ), ARRAY_A);

        if (!$rows) {
            return new WP_Error('missing_product', 'No existe producto base para el SKU.');
        }
        if (count($rows) > 1) {
            return new WP_Error('ambiguous_product', 'El SKU coincide con más de un producto base.');
        }
        return $rows[0];
    }

    private static function extract_package($row) {
        $attributes = $row['attributes'] ?? [];
        foreach ((array) $attributes as $attribute) {
            if (strtoupper(trim((string) ($attribute['name'] ?? ''))) !== 'ENVASE') {
                continue;
            }
            $raw = trim((string) ($attribute['value'] ?? ''));
            if (!preg_match('/([\d.,]+)\s*([[:alpha:]]+)?/u', $raw, $matches)) {
                return null;
            }

            $number = $matches[1];
            if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $number)) {
                $number = str_replace([',', '.'], '', $number);
            } else {
                $number = str_replace(',', '.', $number);
            }
            $quantity = floatval($number);
            if ($quantity <= 0) {
                return null;
            }

            return [
                'cantidad' => $quantity,
                'unidad' => strtoupper($matches[2] ?? 'U'),
                'texto_original' => $raw,
            ];
        }
        return null;
    }

    private static function save_proposal($product, $supplier_code, $package) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $supplier_product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, proveedor_id
             FROM {$prefix}producto_proveedor
             WHERE codigo_proveedor = %s AND activo = 1
             ORDER BY (producto_base_id = %d) DESC
             LIMIT 1",
            $supplier_code,
            $product['id']
        ), ARRAY_A);

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}envases
             WHERE producto_base_id = %d AND codigo_proveedor = %s
             LIMIT 1",
            $product['id'],
            $supplier_code
        ));

        $data = [
            'producto_base_id' => intval($product['id']),
            'producto_proveedor_id' => $supplier_product ? intval($supplier_product['id']) : null,
            'proveedor_id' => $supplier_product && $supplier_product['proveedor_id']
                ? intval($supplier_product['proveedor_id'])
                : null,
            'codigo_proveedor' => $supplier_code,
            'tipo_envase' => 'envase',
            'cantidad_unidades' => floatval($package['cantidad']),
            'es_vendible' => 0,
            'lleva_stock_propio' => 0,
            'permite_apertura' => 1,
            'origen_datos' => 'catalogo_mamut',
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
            'activo' => 1,
        ];

        if ($existing_id) {
            $ok = $wpdb->update(
                "{$prefix}envases",
                $data,
                ['id' => intval($existing_id)]
            );
            return $ok === false ? new WP_Error('update_failed', $wpdb->last_error) : 'updated';
        }

        $ok = $wpdb->insert("{$prefix}envases", $data);
        return $ok === false ? new WP_Error('insert_failed', $wpdb->last_error) : 'inserted';
    }

    private static function record_missing_quantity_gap($product_id, $supplier_code, $row, $apply) {
        if (!$apply) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_data_gaps';
        $detail = [
            'codigo_proveedor' => $supplier_code,
            'nombre' => $row['nombre_producto'] ?? '',
            'motivo' => 'El catálogo no contiene un atributo ENVASE parseable.',
        ];
        $fingerprint = hash('sha256', wp_json_encode($detail));
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (regla, entidad_tipo, entidad_id, fingerprint, severidad, estado,
                 detalle_json, origen, detectado_at, visto_ultima_vez_at)
             VALUES ('presentacion_sin_cantidad', 'producto_base', %d, %s, 'alta',
                     'abierto', %s, 'catalogo_mamut', %s, %s)
             ON DUPLICATE KEY UPDATE
                estado = 'abierto', detalle_json = VALUES(detalle_json),
                visto_ultima_vez_at = VALUES(visto_ultima_vez_at), resuelto_at = NULL",
            $product_id,
            $fingerprint,
            wp_json_encode($detail),
            $now,
            $now
        ));
    }
}
