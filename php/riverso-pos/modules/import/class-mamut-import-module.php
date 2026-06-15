<?php
/**
 * Módulo de Importación MAMUT - Riverso POS.
 *
 * Lee el catálogo MAMUT (data/catalogo_mamut_2025_spatial.json), que organiza
 * los SKU por categoría / subcategoría / producto, y:
 *   - crea/asegura un producto_proveedor para el proveedor MAMUT (codigo=SKU),
 *   - crea producto_base mínimo cuando el SKU no existe (gobernanza computer),
 *   - dispara el matching progresivo (Fase 3),
 *   - encola tareas validar_categoria para productos sin relación WooCommerce.
 *
 * Procesa por lotes (offset/limit) para soportar catálogos grandes (~5k SKU).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Mamut_Import_Module {

    private static $instance = null;

    const SUPPLIER_NAME = 'MAMUT';
    const SUPPLIER_RUT  = 'MAMUT';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_mamut_import', [$this, 'ajax_import']);
        add_action('wp_ajax_riverso_mamut_count', [$this, 'ajax_count']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('riverso-mamut import', [$this, 'cli_import']);
        }
    }

    /**
     * Ruta por defecto del catálogo MAMUT (carpeta data del repositorio).
     */
    public function default_path() {
        $candidate = realpath(RIVERSO_POS_PLUGIN_DIR . '../../data/catalogo_mamut_2025_spatial.json');
        return $candidate ?: (RIVERSO_POS_PLUGIN_DIR . '../../data/catalogo_mamut_2025_spatial.json');
    }

    /**
     * Aplana la estructura jerárquica del catálogo en una lista de entradas:
     * [sku, categoria, subcategoria, producto].
     *
     * @param array $node
     * @param array $path
     * @param array $out
     */
    private function flatten($node, $path, &$out) {
        if (!is_array($node)) {
            return;
        }
        // Nodo hoja con lista de SKUs.
        if (isset($node['skus']) && is_array($node['skus'])) {
            foreach ($node['skus'] as $sku) {
                $out[] = [
                    'sku' => (string) $sku,
                    'categoria' => $path[0] ?? '',
                    'subcategoria' => $path[1] ?? '',
                    'producto' => $path[2] ?? ($path[1] ?? ''),
                ];
            }
        }
        foreach ($node as $key => $child) {
            if ($key !== 'skus' && is_array($child)) {
                $next = $path;
                $next[] = $key;
                $this->flatten($child, $next, $out);
            }
        }
    }

    /**
     * Devuelve la lista aplanada de SKUs del catálogo.
     *
     * @param string $path
     * @return array|WP_Error
     */
    public function load_entries($path = '') {
        $path = $path ?: $this->default_path();
        if (!file_exists($path)) {
            return new WP_Error('not_found', 'Archivo MAMUT no encontrado: ' . $path);
        }
        $json = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'JSON inválido: ' . json_last_error_msg());
        }
        $root = isset($json['structure']) ? $json['structure'] : $json;
        $out = [];
        $this->flatten($root, [], $out);
        $products = isset($json['products']) && is_array($json['products']) ? $json['products'] : [];
        foreach ($out as &$entry) {
            $detail = $products[$entry['sku']] ?? [];
            if (!empty($detail['nombre_producto'])) {
                $entry['nombre_producto'] = $detail['nombre_producto'];
            }
            if (!empty($detail['category_path']) && is_array($detail['category_path'])) {
                $entry['category_path'] = $detail['category_path'];
                $entry['categoria'] = $detail['category_path'][0] ?? $entry['categoria'];
                $entry['subcategoria'] = $detail['category_path'][1] ?? $entry['subcategoria'];
                $entry['producto'] = $detail['category_path'][2] ?? $entry['producto'];
            }
            $entry['attributes'] = !empty($detail['attributes']) && is_array($detail['attributes'])
                ? $detail['attributes']
                : [];
        }
        return $out;
    }

    /**
     * Obtiene (o crea) el proveedor MAMUT.
     */
    public function get_or_create_supplier() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_proveedores';

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE rut = %s OR nombre = %s LIMIT 1",
            self::SUPPLIER_RUT,
            self::SUPPLIER_NAME
        ));
        if ($id) {
            return intval($id);
        }

        $wpdb->insert($table, [
            'rut' => self::SUPPLIER_RUT,
            'nombre' => self::SUPPLIER_NAME,
            'activo' => 1,
        ], ['%s', '%s', '%d']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Asegura un producto_base para un SKU MAMUT.
     *
     * @param string $sku
     * @param string $nombre
     * @return array [id, created]
     */
    private function ensure_base($sku, $nombre) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
            $sku
        ));
        if ($id) {
            return ['id' => intval($id), 'created' => false];
        }

        $wpdb->insert("{$prefix}producto_base", [
            'woocommerce_product_id' => 0,
            'woocommerce_variation_id' => 0,
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre ?: $sku,
            'unidad_base' => 'unidad',
            'permite_ean13_personalizado' => 1,
            'estado' => 'activo',
            'created_by_system' => 1,
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
        ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s']);

        return ['id' => (int) $wpdb->insert_id, 'created' => true];
    }

    /**
     * Asegura un producto_proveedor MAMUT para un producto_base.
     *
     * @return int pp_id
     */
    private function ensure_pp($base_id, $supplier_id, $sku, $nombre) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pp_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_proveedor WHERE proveedor_id = %d AND codigo_proveedor = %s",
            $supplier_id,
            $sku
        ));
        if ($pp_id) {
            return intval($pp_id);
        }

        $wpdb->insert("{$prefix}producto_proveedor", [
            'producto_base_id' => $base_id,
            'proveedor_id' => $supplier_id,
            'codigo_proveedor' => $sku,
            'nombre_proveedor' => $nombre,
            'origen_datos' => 'computer',
            'activo' => 1,
            'created_by_system' => 1,
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
            'match_estado' => 'UNMATCHED',
        ], ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);

        return (int) $wpdb->insert_id;
    }

    /**
     * Importa un lote de entradas MAMUT.
     *
     * @param int    $offset
     * @param int    $limit
     * @param string $path
     * @return array|WP_Error
     */
    public function import_batch($offset = 0, $limit = 200, $path = '') {
        $entries = $this->load_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $total = count($entries);
        $offset = max(0, intval($offset));
        $limit = max(1, intval($limit));
        $slice = array_slice($entries, $offset, $limit);

        $supplier_id = $this->get_or_create_supplier();
        $created_bases = 0;
        $created_pps = 0;
        $processed = 0;

        foreach ($slice as $entry) {
            $sku = trim($entry['sku']);
            if ($sku === '') {
                continue;
            }
            $nombre = trim($entry['nombre_producto'] ?? '');
            if ($nombre === '') {
                $nombre = trim($entry['producto'] . ' ' . $entry['subcategoria']);
            }
            $nombre = $nombre ?: $sku;

            $base = $this->ensure_base($sku, $nombre);
            if ($base['created']) {
                $created_bases++;
            }

            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $pp_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor WHERE proveedor_id = %d AND codigo_proveedor = %s",
                $supplier_id,
                $sku
            ));

            $pp_id = $this->ensure_pp($base['id'], $supplier_id, $sku, $nombre);
            if (!$pp_exists) {
                $created_pps++;
            }

            // Disparar matching (Fase 3).
            if (class_exists('Riverso_Matching_Module')) {
                Riverso_Matching_Module::get_instance()->run_match($pp_id);
            }

            // Producto sin relación WooCommerce => tarea validar_categoria.
            $wc_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT woocommerce_product_id FROM {$prefix}producto_base WHERE id = %d",
                $base['id']
            ));
            if (!$wc_id && function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'validar_categoria',
                    'Validar categoría/relación de SKU MAMUT ' . $sku,
                    'producto_base',
                    $base['id'],
                    [
                        'prioridad' => 'normal',
                        'datos_extra' => [
                            'sku' => $sku,
                            'categoria' => $entry['categoria'],
                            'subcategoria' => $entry['subcategoria'],
                        ],
                    ]
                );
            }

            $processed++;
        }

        $next_offset = $offset + $limit;
        $done = $next_offset >= $total;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('mamut_import_batch', 'import', 0, [
                'new_value' => [
                    'offset' => $offset,
                    'processed' => $processed,
                    'created_bases' => $created_bases,
                    'created_pps' => $created_pps,
                ],
            ]);
        }

        return [
            'total' => $total,
            'offset' => $offset,
            'processed' => $processed,
            'created_bases' => $created_bases,
            'created_pps' => $created_pps,
            'next_offset' => $done ? null : $next_offset,
            'done' => $done,
        ];
    }

    /* ===================== AJAX ===================== */

    public function ajax_count() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $entries = $this->load_entries(sanitize_text_field($_POST['path'] ?? ''));
        if (is_wp_error($entries)) {
            wp_send_json_error(['message' => $entries->get_error_message()]);
        }
        wp_send_json_success(['total' => count($entries)]);
    }

    public function ajax_import() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->import_batch(
            intval($_POST['offset'] ?? 0),
            intval($_POST['limit'] ?? 200),
            sanitize_text_field($_POST['path'] ?? '')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /* ===================== WP-CLI ===================== */

    /**
     * wp riverso-mamut import [--limit=<n>]
     */
    public function cli_import($args, $assoc) {
        $limit = isset($assoc['limit']) ? intval($assoc['limit']) : 500;
        $offset = 0;
        do {
            $res = $this->import_batch($offset, $limit);
            if (is_wp_error($res)) {
                WP_CLI::error($res->get_error_message());
                return;
            }
            WP_CLI::log(sprintf(
                'Offset %d: procesados %d (bases nuevas %d, pp nuevos %d)',
                $res['offset'], $res['processed'], $res['created_bases'], $res['created_pps']
            ));
            $offset = $res['next_offset'];
        } while ($offset !== null);

        WP_CLI::success('Importación MAMUT completada (' . $res['total'] . ' SKUs).');
    }
}
