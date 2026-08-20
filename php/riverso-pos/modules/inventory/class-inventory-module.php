<?php
/**
 * Inventario de bodega (portal interno)
 *
 * Conteos por lugar/producto, ubicaciones preferidas e historial.
 * Convive con Riverso_Inventory_Module (dominio ERP de stock).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Inventory_Count_Module {

    private static $instance = null;

    const COUNT_TYPES = [
        'general'  => 'Inventario general',
        'lugar'    => 'Inventario de lugar',
        'producto' => 'Inventario de producto',
    ];

    const ORDER_STATES = [
        'pendiente'   => 'Pendiente',
        'en_progreso' => 'En progreso',
        'completada'  => 'Completada',
        'cancelada'   => 'Cancelada',
    ];

    const SORT_ORDER_STATES = [
        'pendiente'   => 'Pendiente',
        'en_progreso' => 'En progreso',
        'completada'  => 'Completada',
        'cancelada'   => 'Cancelada',
    ];

    const SORT_ITEM_STATES = [
        'pendiente'  => 'Pendiente',
        'completado' => 'Completado',
        'omitido'    => 'Omitido',
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_inventory_get_locations', [$this, 'ajax_get_locations']);
        add_action('wp_ajax_riverso_inventory_save_location', [$this, 'ajax_save_location']);
        add_action('wp_ajax_riverso_inventory_set_location_status', [$this, 'ajax_set_location_status']);
        add_action('wp_ajax_riverso_inventory_delete_location', [$this, 'ajax_delete_location']);
        add_action('wp_ajax_riverso_inventory_get_location_overview', [$this, 'ajax_get_location_overview']);
        add_action('wp_ajax_riverso_inventory_find_location_by_barcode', [$this, 'ajax_find_location_by_barcode']);

        add_action('wp_ajax_riverso_inventory_get_product_locations', [$this, 'ajax_get_product_locations']);
        add_action('wp_ajax_riverso_inventory_save_preferred_location', [$this, 'ajax_save_preferred_location']);
        add_action('wp_ajax_riverso_inventory_remove_preferred_location', [$this, 'ajax_remove_preferred_location']);
        add_action('wp_ajax_riverso_inventory_set_primary_location', [$this, 'ajax_set_primary_location']);

        add_action('wp_ajax_riverso_inventory_start_count', [$this, 'ajax_start_count']);
        add_action('wp_ajax_riverso_inventory_list_counts', [$this, 'ajax_list_counts']);
        add_action('wp_ajax_riverso_inventory_get_count', [$this, 'ajax_get_count']);
        add_action('wp_ajax_riverso_inventory_get_count_summary', [$this, 'ajax_get_count_summary']);
        add_action('wp_ajax_riverso_inventory_add_count_item', [$this, 'ajax_add_count_item']);
        add_action('wp_ajax_riverso_inventory_update_count_item', [$this, 'ajax_update_count_item']);
        add_action('wp_ajax_riverso_inventory_delete_count_item', [$this, 'ajax_delete_count_item']);
        add_action('wp_ajax_riverso_inventory_close_count', [$this, 'ajax_close_count']);
        add_action('wp_ajax_riverso_inventory_preview_close', [$this, 'ajax_preview_close']);
        add_action('wp_ajax_riverso_inventory_abort_count', [$this, 'ajax_abort_count']);
        add_action('wp_ajax_riverso_inventory_change_count_location', [$this, 'ajax_change_count_location']);

        add_action('wp_ajax_riverso_inventory_decode_barcode', [$this, 'ajax_decode_barcode']);
        add_action('wp_ajax_riverso_inventory_search_products', [$this, 'ajax_search_products']);

        add_action('wp_ajax_riverso_inventory_list_orders', [$this, 'ajax_list_orders']);
        add_action('wp_ajax_riverso_inventory_save_order', [$this, 'ajax_save_order']);
        add_action('wp_ajax_riverso_inventory_update_order_status', [$this, 'ajax_update_order_status']);

        add_action('wp_ajax_riverso_inventory_move_product', [$this, 'ajax_move_product']);

        add_action('wp_ajax_riverso_sort_orders_list', [$this, 'ajax_sort_orders_list']);
        add_action('wp_ajax_riverso_sort_orders_save', [$this, 'ajax_sort_orders_save']);
        add_action('wp_ajax_riverso_sort_orders_add_item', [$this, 'ajax_sort_orders_add_item']);
        add_action('wp_ajax_riverso_sort_orders_remove_item', [$this, 'ajax_sort_orders_remove_item']);
        add_action('wp_ajax_riverso_sort_orders_update_item', [$this, 'ajax_sort_orders_update_item']);
        add_action('wp_ajax_riverso_sort_orders_complete', [$this, 'ajax_sort_orders_complete']);
        add_action('wp_ajax_riverso_sort_orders_cancel', [$this, 'ajax_sort_orders_cancel']);
        add_action('wp_ajax_riverso_sort_orders_print', [$this, 'ajax_sort_orders_print']);
        add_action('wp_ajax_riverso_sort_orders_fill_destinations', [$this, 'ajax_sort_orders_fill_destinations']);

        // ========== AJAX: estado de stock ==========
        add_action('wp_ajax_riverso_stock_status_list', [$this, 'ajax_stock_status_list']);
        add_action('wp_ajax_riverso_stock_status_save_config', [$this, 'ajax_stock_status_save_config']);
    }

    public static function create_tables() {
        if (class_exists('Riverso_POS_Activator')) {
            return true;
        }
        return true;
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    private function require_nonce() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
    }

    private function require_cap($cap) {
        if (current_user_can($cap) || current_user_can('manage_options')) {
            return;
        }
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    private function can_do_inventory() {
        return current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
    }

    private function can_manage_sort_orders() {
        return current_user_can('riverso_manage_sort_orders')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
    }

    private function can_edit_locations() {
        return current_user_can('riverso_edit_warehouse')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
    }

    private function is_unknown_location($id) {
        $id = intval($id);
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        $prefix = $this->prefix();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$prefix}ubicaciones WHERE id = %d AND codigo = %s",
            $id,
            '?'
        ));
    }

    private function post_text($key, $default = '') {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    private function post_int($key, $default = 0) {
        return isset($_POST[$key]) ? intval($_POST[$key]) : $default;
    }

    private function post_float($key, $default = 0) {
        return isset($_POST[$key]) ? floatval($_POST[$key]) : $default;
    }

    private function location_types() {
        if (class_exists('Riverso_Warehouse_Module')) {
            return Riverso_Warehouse_Module::LOCATION_TYPES;
        }
        return [
            'pasillo' => 'Pasillo',
            'estante' => 'Estante',
            'rack' => 'Rack',
            'piso' => 'Piso',
            'meson' => 'Mesón',
            'vitrina' => 'Vitrina',
            'bodega_ext' => 'Bodega Externa',
        ];
    }

    private function get_location($id) {
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}ubicaciones WHERE id = %d",
            intval($id)
        ), ARRAY_A);
    }

    private function find_location_by_code($code) {
        global $wpdb;
        $prefix = $this->prefix();
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}ubicaciones
             WHERE activo = 1 AND (barcode = %s OR codigo = %s)
             LIMIT 1",
            $code,
            $code
        ), ARRAY_A);
    }

    private function get_open_count($id) {
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}conteos WHERE id = %d AND estado = 'abierto'",
            intval($id)
        ), ARRAY_A);
    }

    private function get_product($id) {
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, unidad_base,
                    woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base WHERE id = %d",
            intval($id)
        ), ARRAY_A);
    }

    private function envase_label($envase_id) {
        if (!$envase_id) {
            return 'unidad';
        }
        global $wpdb;
        $prefix = $this->prefix();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}envases WHERE id = %d",
            intval($envase_id)
        ), ARRAY_A);
        if (!$row) {
            return 'envase #' . intval($envase_id);
        }
        $parts = array_filter([
            $row['tipo_envase'] ?? '',
            $row['sku_envase'] ?? '',
            $row['cantidad_unidades'] ? ('x' . $row['cantidad_unidades']) : '',
        ]);
        return implode(' ', $parts) ?: 'envase';
    }

    private function format_count_item($row) {
        $product = $this->get_product($row['producto_base_id'] ?? 0);
        $location = !empty($row['ubicacion_id']) ? $this->get_location($row['ubicacion_id']) : null;
        return [
            'id' => intval($row['id']),
            'conteo_id' => intval($row['conteo_id']),
            'producto_base_id' => intval($row['producto_base_id']),
            'sku' => $product['canonical_sku'] ?? '',
            'nombre' => $product['nombre_canonico'] ?? '',
            'codigo' => $row['codigo'] ?? '',
            'envase_id' => $row['envase_id'] ? intval($row['envase_id']) : null,
            'envase_label' => $this->envase_label($row['envase_id'] ?? 0),
            'ubicacion_id' => $row['ubicacion_id'] ? intval($row['ubicacion_id']) : null,
            'ubicacion_codigo' => $location['codigo'] ?? 'Desconocido',
            'ubicacion_nombre' => $location['nombre'] ?? 'Lugar desconocido',
            'cantidad_teorica' => floatval($row['cantidad_teorica'] ?? 0),
            'cantidad_contada' => floatval($row['cantidad_contada'] ?? 0),
            'cantidad_manual' => isset($row['cantidad_manual']) ? floatval($row['cantidad_manual']) : null,
            'es_abierto' => intval($row['es_abierto'] ?? 0),
            'created_at' => $row['created_at'] ?? '',
        ];
    }

    private function log_scan($data) {
        global $wpdb;
        $prefix = $this->prefix();
        $wpdb->insert("{$prefix}conteo_scan_log", [
            'conteo_id' => intval($data['conteo_id'] ?? 0),
            'conteo_item_id' => !empty($data['conteo_item_id']) ? intval($data['conteo_item_id']) : null,
            'ubicacion_id' => !empty($data['ubicacion_id']) ? intval($data['ubicacion_id']) : null,
            'barcode_raw' => sanitize_text_field($data['barcode_raw'] ?? ''),
            'tipo_barcode' => sanitize_text_field($data['tipo_barcode'] ?? ''),
            'producto_base_id' => !empty($data['producto_base_id']) ? intval($data['producto_base_id']) : null,
            'envase_id' => !empty($data['envase_id']) ? intval($data['envase_id']) : null,
            'cantidad_decodificada' => isset($data['cantidad_decodificada']) ? floatval($data['cantidad_decodificada']) : null,
            'es_abierto' => !empty($data['es_abierto']) ? 1 : 0,
            'accion' => sanitize_text_field($data['accion'] ?? 'scan'),
            'usuario_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);
    }

    private function find_product_by_local_sku($sku) {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, unidad_base,
                    woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base
             WHERE estado = 'activo' AND canonical_sku = %s
             LIMIT 1",
            $sku
        ), ARRAY_A);
    }

    private function woo_sku_for_product($product) {
        if (!is_array($product) || !function_exists('wc_get_product')) {
            return '';
        }
        $variation_id = intval($product['woocommerce_variation_id'] ?? 0);
        $product_id = intval($product['woocommerce_product_id'] ?? 0);
        $woo_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($woo_id <= 0) {
            return '';
        }
        $woo = wc_get_product($woo_id);
        return $woo ? trim((string) $woo->get_sku()) : '';
    }

    /**
     * Inventario de bodega cuenta SKU local. Si el barcode resolvió el
     * producto online (SKU Woo / Mamut), reubica al producto_base con SKU local.
     */
    private function prefer_local_catalog_product($product) {
        if (!$product) {
            return null;
        }
        $canonical = trim((string) ($product['canonical_sku'] ?? ''));
        $map = function_exists('riverso_mamut_online_to_local_sku')
            ? 'riverso_mamut_online_to_local_sku'
            : null;

        if ($map && $canonical !== '') {
            $mapped = $map($canonical);
            if ($mapped && strcasecmp($mapped, $canonical) !== 0) {
                $local = $this->find_product_by_local_sku($mapped);
                if ($local) {
                    return $local;
                }
            }
        }

        if ($this->product_has_local_sku($product)) {
            return $product;
        }

        $woo_sku = $this->woo_sku_for_product($product);
        if ($woo_sku === '' || !$map) {
            return $product;
        }
        $mapped_woo = $map($woo_sku);
        if ($mapped_woo) {
            $local = $this->find_product_by_local_sku($mapped_woo);
            if ($local) {
                return $local;
            }
        }
        return $product;
    }

    private function decode_barcode($code) {
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }

        $resolved = false;
        if (class_exists('Riverso_Barcode_Model') && method_exists('Riverso_Barcode_Model', 'resolve')) {
            $resolved = Riverso_Barcode_Model::resolve($code);
        }

        if (!$resolved) {
            global $wpdb;
            $prefix = $this->prefix();
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT cb.producto_base_id, cb.cantidad, cb.envase_id, cb.tipo, cb.unidad_medida, cb.factor_a_unidad_base
                 FROM {$prefix}codigo_barra cb
                 INNER JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                 WHERE cb.codigo = %s AND cb.activo = 1
                   AND cb.estado = 'verificado'
                 ORDER BY CASE WHEN pb.canonical_sku IS NOT NULL AND TRIM(pb.canonical_sku) <> '' THEN 0 ELSE 1 END
                 LIMIT 1",
                $code
            ), ARRAY_A);
            if ($row) {
                $resolved = [
                    'producto_base_id' => intval($row['producto_base_id']),
                    'cantidad' => floatval($row['cantidad'] ?: 1),
                    'envase_id' => $row['envase_id'] ? intval($row['envase_id']) : null,
                    'tipo' => $row['tipo'] ?: 'ean13',
                    'unidad_medida' => $row['unidad_medida'] ?: 'unidad',
                    'factor_a_unidad_base' => floatval($row['factor_a_unidad_base'] ?: 1),
                    'origen' => 'codigo_barra',
                ];
            }
        }

        if (!$resolved || empty($resolved['producto_base_id'])) {
            return false;
        }

        $product = $this->get_product($resolved['producto_base_id']);
        $product = $this->prefer_local_catalog_product($product);
        if (!$product) {
            return false;
        }

        $qty = floatval($resolved['cantidad'] ?? $resolved['cantidad_unidades'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }

        return [
            'producto_base_id' => intval($product['id']),
            'sku' => $product['canonical_sku'] ?? '',
            'nombre' => $product['nombre_canonico'] ?? '',
            'cantidad' => $qty,
            'envase_id' => !empty($resolved['envase_id']) ? intval($resolved['envase_id']) : (!empty($resolved['presentacion_id']) ? intval($resolved['presentacion_id']) : null),
            'envase_label' => $this->envase_label($resolved['envase_id'] ?? $resolved['presentacion_id'] ?? 0),
            'unidad_medida' => $resolved['unidad_medida'] ?? ($product['unidad_base'] ?? 'unidad'),
            'tipo' => $resolved['tipo'] ?? $resolved['type'] ?? 'ean13',
            'origen' => $resolved['origen'] ?? 'codigo_barra',
            'barcode' => $code,
        ];
    }

    private function product_has_local_sku($product) {
        return is_array($product) && trim((string) ($product['canonical_sku'] ?? '')) !== '';
    }

    private function suggested_pack_qty($code) {
        if (!class_exists('Riverso_EAN13_Generator')) {
            $gen = RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-ean13-generator.php';
            if (file_exists($gen)) {
                require_once $gen;
            }
        }
        if (class_exists('Riverso_EAN13_Generator')) {
            $parsed = Riverso_EAN13_Generator::parse($code);
            if ($parsed && !empty($parsed['cantidad'])) {
                return max(1, intval($parsed['cantidad']));
            }
        }
        return 1;
    }

    private function classify_scan_for_product($code, $producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $code = trim((string) $code);
        $producto_base_id = intval($producto_base_id);
        $product = $this->get_product($producto_base_id);
        if (!$product || !$this->product_has_local_sku($product)) {
            return ['status' => 'invalid_product'];
        }

        $sku = trim((string) $product['canonical_sku']);
        if (strcasecmp($code, $sku) === 0) {
            return [
                'status' => 'ok',
                'decoded' => [
                    'producto_base_id' => $producto_base_id,
                    'sku' => $sku,
                    'nombre' => $product['nombre_canonico'] ?? '',
                    'cantidad' => 1,
                    'envase_id' => null,
                    'envase_label' => 'unidad',
                    'tipo' => 'sku_local',
                    'barcode' => $code,
                ],
            ];
        }

        $decoded = $this->decode_barcode($code);
        if ($decoded) {
            if (intval($decoded['producto_base_id']) === $producto_base_id) {
                return ['status' => 'ok', 'decoded' => $decoded];
            }
            return [
                'status' => 'other_product',
                'decoded' => $decoded,
                'other' => [
                    'id' => intval($decoded['producto_base_id']),
                    'sku' => $decoded['sku'] ?? '',
                    'nombre' => $decoded['nombre'] ?? '',
                ],
            ];
        }

        $other = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$prefix}producto_base
             WHERE estado = 'activo' AND canonical_sku = %s AND id <> %d
             LIMIT 1",
            $code,
            $producto_base_id
        ), ARRAY_A);
        if ($other) {
            return [
                'status' => 'other_product',
                'other' => [
                    'id' => intval($other['id']),
                    'sku' => $other['canonical_sku'] ?? '',
                    'nombre' => $other['nombre_canonico'] ?? '',
                ],
            ];
        }

        return [
            'status' => 'unknown',
            'suggested_pack_qty' => $this->suggested_pack_qty($code),
        ];
    }

    private function link_barcode_to_product($code, $producto_base_id, $pack_qty) {
        $product = $this->get_product($producto_base_id);
        if (!$product) {
            return false;
        }
        $code = trim((string) $code);
        $pack_qty = max(1, floatval($pack_qty));
        $tipo = 'ean13';
        if (!class_exists('Riverso_EAN13_Generator')) {
            $gen = RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-ean13-generator.php';
            if (file_exists($gen)) {
                require_once $gen;
            }
        }
        if (class_exists('Riverso_EAN13_Generator') && Riverso_EAN13_Generator::is_internal($code)) {
            $tipo = 'internal';
            $parsed = Riverso_EAN13_Generator::parse($code);
            if ($parsed && !empty($parsed['cantidad'])) {
                $pack_qty = max(1, floatval($parsed['cantidad']));
            }
        }

        $envase_id = null;
        if (class_exists('Riverso_Packaging_Module')) {
            $pack = Riverso_Packaging_Module::get_instance();
            $created = $pack->create_envase($producto_base_id, $pack_qty, $code, 0, [
                'tipo_envase' => 'envase',
                'origen_datos' => 'manual',
            ]);
            if (!is_wp_error($created) && intval($created) > 0) {
                $envase_id = intval($created);
            }
        }

        if (!class_exists('Riverso_Barcode_Model')) {
            $model = RIVERSO_POS_PLUGIN_DIR . 'catalog/barcodes/class-barcode-model.php';
            if (file_exists($model)) {
                require_once $model;
            }
        }
        if (!class_exists('Riverso_Barcode_Model')) {
            return false;
        }

        $barcode_id = Riverso_Barcode_Model::create(
            $code,
            $tipo,
            $producto_base_id,
            $pack_qty,
            $product['unidad_base'] ?: 'unidad',
            null,
            $envase_id,
            $pack_qty
        );
        if (!$barcode_id) {
            return false;
        }

        $decoded = $this->decode_barcode($code);
        if (!$decoded) {
            $decoded = [
                'producto_base_id' => $producto_base_id,
                'sku' => $product['canonical_sku'],
                'nombre' => $product['nombre_canonico'],
                'cantidad' => $pack_qty,
                'envase_id' => $envase_id,
                'envase_label' => $this->envase_label($envase_id),
                'tipo' => $tipo,
                'barcode' => $code,
            ];
        }
        return $decoded;
    }

    private function insert_or_update_count_item($count, $decoded, $barcode, $ubicacion_id, $units, $qty_input, $es_abierto, $accion) {
        global $wpdb;
        $prefix = $this->prefix();
        $conteo_id = intval($count['id']);
        $ubicacion_id = $ubicacion_id ? intval($ubicacion_id) : 0;
        $envase_id = !empty($decoded['envase_id']) ? intval($decoded['envase_id']) : 0;
        $producto_id = intval($decoded['producto_base_id']);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}conteo_items
             WHERE conteo_id = %d AND producto_base_id = %d
               AND IFNULL(ubicacion_id, 0) = %d
               AND es_abierto = %d AND IFNULL(envase_id, 0) = %d
             ORDER BY id DESC LIMIT 1",
            $conteo_id,
            $producto_id,
            $ubicacion_id,
            $es_abierto,
            $envase_id
        ), ARRAY_A);

        if ($existing) {
            $new_qty = floatval($existing['cantidad_contada']) + $units;
            $wpdb->update("{$prefix}conteo_items", [
                'cantidad_contada' => $new_qty,
                'cantidad_manual' => $qty_input,
                'codigo' => $barcode ?: ($existing['codigo'] ?? ''),
            ], ['id' => intval($existing['id'])]);
            $item_id = intval($existing['id']);
        } else {
            $wpdb->insert("{$prefix}conteo_items", [
                'conteo_id' => $conteo_id,
                'producto_base_id' => $producto_id,
                'codigo' => $barcode,
                'envase_id' => $envase_id ?: null,
                'ubicacion_id' => $ubicacion_id ?: null,
                'cantidad_teorica' => $this->theoretical_qty($producto_id, $ubicacion_id ?: null),
                'cantidad_contada' => $units,
                'diferencia' => 0,
                'es_abierto' => $es_abierto,
                'cantidad_manual' => $qty_input,
                'created_at' => current_time('mysql'),
            ]);
            $item_id = (int) $wpdb->insert_id;
        }

        $this->log_scan([
            'conteo_id' => $conteo_id,
            'conteo_item_id' => $item_id,
            'ubicacion_id' => $ubicacion_id ?: null,
            'barcode_raw' => $barcode,
            'tipo_barcode' => $decoded['tipo'] ?? '',
            'producto_base_id' => $producto_id,
            'envase_id' => $envase_id ?: null,
            'cantidad_decodificada' => $units,
            'es_abierto' => $es_abierto,
            'accion' => $accion,
        ]);

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteo_items WHERE id = %d", $item_id), ARRAY_A);
        return [
            'item' => $this->format_count_item($item),
            'decoded' => $decoded,
            'summary' => $this->build_summary($conteo_id),
        ];
    }

    private function theoretical_qty($producto_base_id, $ubicacion_id) {
        if (class_exists('Riverso_Stock_Service')) {
            return floatval(Riverso_Stock_Service::get_instance()->get_balance(
                intval($producto_base_id),
                $ubicacion_id ? intval($ubicacion_id) : null
            ));
        }
        return 0;
    }

    private function format_count($count, $include_items = false) {
        global $wpdb;
        $prefix = $this->prefix();
        $location = !empty($count['ubicacion_id']) ? $this->get_location($count['ubicacion_id']) : null;
        $product = !empty($count['producto_base_id']) ? $this->get_product($count['producto_base_id']) : null;
        $out = [
            'id' => intval($count['id']),
            'nombre' => $count['nombre'] ?? '',
            'tipo' => $count['tipo'] ?? 'parcial',
            'tipo_conteo' => $count['tipo_conteo'] ?? 'general',
            'estado' => $count['estado'] ?? 'abierto',
            'ubicacion_id' => $count['ubicacion_id'] ? intval($count['ubicacion_id']) : null,
            'ubicacion_codigo' => $location['codigo'] ?? '',
            'ubicacion_nombre' => $location['nombre'] ?? '',
            'producto_base_id' => $count['producto_base_id'] ? intval($count['producto_base_id']) : null,
            'producto_sku' => $product['canonical_sku'] ?? '',
            'producto_nombre' => $product['nombre_canonico'] ?? '',
            'orden_id' => $count['orden_id'] ? intval($count['orden_id']) : null,
            'iniciado_por' => intval($count['iniciado_por'] ?? 0),
            'iniciado_en' => $count['iniciado_en'] ?? '',
            'cerrado_en' => $count['cerrado_en'] ?? '',
            'notas' => $count['notas'] ?? '',
        ];
        if ($include_items) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$prefix}conteo_items WHERE conteo_id = %d ORDER BY id DESC",
                intval($count['id'])
            ), ARRAY_A);
            $out['items'] = array_map([$this, 'format_count_item'], $rows ?: []);
        }
        return $out;
    }

    private function build_summary($conteo_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}conteo_items WHERE conteo_id = %d ORDER BY id ASC",
            intval($conteo_id)
        ), ARRAY_A) ?: [];

        $closed = [];
        $open = [];
        $by_location = [];
        $total_units = 0;
        $total_items = 0;

        foreach ($items as $row) {
            $formatted = $this->format_count_item($row);
            $loc_key = $formatted['ubicacion_id'] ?: 0;
            if (empty($by_location[$loc_key])) {
                $by_location[$loc_key] = [
                    'ubicacion_id' => $formatted['ubicacion_id'],
                    'ubicacion_codigo' => $formatted['ubicacion_codigo'],
                    'ubicacion_nombre' => $formatted['ubicacion_nombre'],
                    'unidades' => 0,
                    'items' => 0,
                    'abiertos' => 0,
                ];
            }
            if (intval($row['es_abierto'])) {
                $open[] = $formatted;
                $by_location[$loc_key]['abiertos']++;
            } else {
                $closed[] = $formatted;
                $qty = floatval($row['cantidad_contada']);
                $total_units += $qty;
                $total_items++;
                $by_location[$loc_key]['unidades'] += $qty;
                $by_location[$loc_key]['items']++;
            }
        }

        return [
            'total_items' => $total_items,
            'total_unidades' => $total_units,
            'total_abiertos' => count($open),
            'por_lugar' => array_values($by_location),
            'items' => $closed,
            'items_abiertos' => $open,
        ];
    }

    // ========== AJAX: ubicaciones ==========

    public function ajax_get_locations() {
        $this->require_nonce();
        $this->require_cap('riverso_view_warehouse');
        global $wpdb;
        $prefix = $this->prefix();

        $where = ['1=1'];
        $params = [];
        if (isset($_POST['activo']) && $_POST['activo'] !== '') {
            $where[] = 'u.activo = %d';
            $params[] = intval($_POST['activo']);
        }
        $tipo = $this->post_text('tipo');
        if ($tipo !== '') {
            $where[] = 'u.tipo = %s';
            $params[] = $tipo;
        }
        $search = $this->post_text('search');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(u.codigo LIKE %s OR u.nombre LIKE %s OR u.barcode LIKE %s OR u.zona LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = "SELECT u.*,
                    (SELECT COUNT(*) FROM {$prefix}producto_ubicacion_preferida p WHERE p.ubicacion_id = u.id) AS preferidos_count
                FROM {$prefix}ubicaciones u
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.activo DESC, u.zona, u.codigo";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        wp_send_json_success([
            'locations' => $rows ?: [],
            'types' => $this->location_types(),
        ]);
    }

    public function ajax_save_location() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();

        $id = $this->post_int('id');
        $codigo = $this->post_text('codigo');
        $nombre = $this->post_text('nombre');
        $tipo = $this->post_text('tipo', 'estante');
        $barcode = $this->post_text('barcode');
        $zona = $this->post_text('zona');
        $descripcion = isset($_POST['descripcion']) ? sanitize_textarea_field(wp_unslash($_POST['descripcion'])) : '';
        $capacidad = $this->post_int('capacidad');
        $activo = isset($_POST['activo']) ? intval($_POST['activo']) : 1;

        if ($codigo === '') {
            wp_send_json_error(['message' => 'Código requerido']);
        }

        // La ubicacion '?' se maneja en backend (no se edita desde UI)
        if ($codigo === '?' || $this->is_unknown_location($id)) {
            wp_send_json_error(['message' => 'No se puede crear/editar la ubicación especial ?'], 400);
        }

        if ($barcode === '') {
            wp_send_json_error(['message' => 'Código de barras del lugar requerido']);
        }

        $barcode_val = $barcode;

        if ($barcode_val) {
            $dup_bc = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}ubicaciones WHERE barcode = %s AND id <> %d",
                $barcode_val,
                $id
            ));
            if ($dup_bc) {
                wp_send_json_error(['message' => 'Ya existe una ubicación con ese código de barras']);
            }
        }

        $dup_code = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}ubicaciones WHERE codigo = %s AND id <> %d",
            $codigo,
            $id
        ));
        if ($dup_code) {
            wp_send_json_error(['message' => 'Ya existe una ubicación con ese código']);
        }

        $data = [
            'codigo' => $codigo,
            'nombre' => $nombre !== '' ? $nombre : $codigo,
            'tipo' => $tipo !== '' ? $tipo : 'estante',
            'descripcion' => $descripcion,
            'capacidad' => $capacidad,
            'barcode' => $barcode_val,
            'zona' => $zona !== '' ? $zona : null,
        ];
        if (isset($_POST['activo'])) {
            $data['activo'] = $activo ? 1 : 0;
        } elseif (!$id) {
            $data['activo'] = 1;
        }

        if ($id) {
            $wpdb->update("{$prefix}ubicaciones", $data, ['id' => $id]);
            wp_send_json_success(['message' => 'Ubicación actualizada', 'id' => $id, 'location' => $this->get_location($id)]);
        }

        $ok = $wpdb->insert("{$prefix}ubicaciones", $data);
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo crear la ubicación']);
        }
        $new_id = (int) $wpdb->insert_id;
        wp_send_json_success(['message' => 'Ubicación creada', 'id' => $new_id, 'location' => $this->get_location($new_id)]);
    }

    public function ajax_set_location_status() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $activo = $this->post_int('activo') ? 1 : 0;
        if (!$id || !$this->get_location($id)) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }

        if ($this->is_unknown_location($id)) {
            wp_send_json_error(['message' => 'La ubicacion especial ? no se puede desactivar/reactivar'], 400);
        }

        $wpdb->update("{$prefix}ubicaciones", ['activo' => $activo], ['id' => $id], ['%d'], ['%d']);
        wp_send_json_success([
            'message' => $activo ? 'Ubicación reactivada' : 'Ubicación desactivada',
            'location' => $this->get_location($id),
        ]);
    }

    public function ajax_delete_location() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $loc = $this->get_location($id);
        if (!$loc) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }

        if ($this->is_unknown_location($id)) {
            wp_send_json_error(['message' => 'No se puede eliminar la ubicacion especial ?'], 400);
        }

        $open_counts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}conteos WHERE ubicacion_id = %d AND estado = 'abierto'",
            $id
        ));
        if ($open_counts > 0) {
            wp_send_json_error(['message' => 'No se puede eliminar: hay un conteo abierto en este lugar. Ciérralo o abortalo primero.']);
        }

        $wpdb->delete("{$prefix}producto_ubicacion_preferida", ['ubicacion_id' => $id], ['%d']);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}conteo_items SET ubicacion_id = NULL WHERE ubicacion_id = %d",
            $id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}conteos SET ubicacion_id = NULL WHERE ubicacion_id = %d",
            $id
        ));
        $wpdb->delete("{$prefix}producto_ubicacion_historial", ['ubicacion_id' => $id], ['%d']);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}producto_ubicacion WHERE ubicacion_id = %d",
            $id
        ));
        $wpdb->delete("{$prefix}ubicaciones", ['id' => $id], ['%d']);
        wp_send_json_success(['message' => 'Ubicación eliminada']);
    }

    public function ajax_get_location_overview() {
        $this->require_nonce();
        $this->require_cap('riverso_view_warehouse');
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $loc = $this->get_location($id);
        if (!$loc) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }

        $preferidos = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_ubicacion_preferida p
             INNER JOIN {$prefix}producto_base pb ON pb.id = p.producto_base_id
             WHERE p.ubicacion_id = %d
             ORDER BY p.es_preferido DESC, pb.canonical_sku ASC",
            $id
        ), ARRAY_A) ?: [];

        // "Inventario actual" debe reflejar el saldo vivo (riverso_producto_ubicacion),
        // no el último conteo cerrado de producto_ubicacion_historial.
        $inventario = $wpdb->get_results($wpdb->prepare(
            "SELECT pu.product_id AS producto_base_id,
                    pb.canonical_sku,
                    pb.nombre_canonico,
                    pu.cantidad AS cantidad_contada,
                    NULL AS fecha_conteo,
                    NULL AS conteo_id,
                    NULL AS conteo_nombre
             FROM {$prefix}producto_ubicacion pu
             INNER JOIN {$prefix}producto_base pb ON pb.id = pu.product_id
             WHERE pu.ubicacion_id = %d
               AND pu.cantidad <> 0
             ORDER BY pb.canonical_sku ASC",
            $id
        ), ARRAY_A) ?: [];

        wp_send_json_success([
            'location' => $loc,
            'preferidos' => $preferidos,
            'inventario' => $inventario,
        ]);
    }

    public function ajax_find_location_by_barcode() {
        $this->require_nonce();
        $this->require_cap('riverso_view_warehouse');
        $code = $this->post_text('barcode');
        if ($code === '') {
            $code = $this->post_text('codigo');
        }
        $loc = $this->find_location_by_code($code);
        if (!$loc) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }
        wp_send_json_success(['location' => $loc]);
    }

    // ========== AJAX: ubicaciones de producto ==========

    public function ajax_get_product_locations() {
        $this->require_nonce();
        if (!current_user_can('riverso_view_warehouse') && !current_user_can('riverso_view_products') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $producto_id = $this->post_int('producto_base_id');
        if (!$producto_id) {
            wp_send_json_error(['message' => 'Producto requerido']);
        }

        $preferidas = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.codigo, u.nombre, u.zona, u.tipo
             FROM {$prefix}producto_ubicacion_preferida p
             INNER JOIN {$prefix}ubicaciones u ON u.id = p.ubicacion_id
             WHERE p.producto_base_id = %d
             ORDER BY p.es_preferido DESC, p.prioridad ASC, u.codigo ASC",
            $producto_id
        ), ARRAY_A) ?: [];

        $latest_conteo = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(conteo_id) FROM {$prefix}producto_ubicacion_historial WHERE producto_base_id = %d",
            $producto_id
        ));

        $actuales = [];
        if ($latest_conteo) {
            $actuales = $wpdb->get_results($wpdb->prepare(
                "SELECT h.*, u.codigo, u.nombre, u.zona, u.tipo
                 FROM {$prefix}producto_ubicacion_historial h
                 INNER JOIN {$prefix}ubicaciones u ON u.id = h.ubicacion_id
                 WHERE h.producto_base_id = %d AND h.conteo_id = %d
                 ORDER BY u.codigo",
                $producto_id,
                intval($latest_conteo)
            ), ARRAY_A) ?: [];
        }

        $historial = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, u.codigo, u.nombre, u.zona, c.nombre AS conteo_nombre, c.tipo_conteo
             FROM {$prefix}producto_ubicacion_historial h
             INNER JOIN {$prefix}ubicaciones u ON u.id = h.ubicacion_id
             LEFT JOIN {$prefix}conteos c ON c.id = h.conteo_id
             WHERE h.producto_base_id = %d
             ORDER BY h.fecha_conteo DESC, h.id DESC
             LIMIT 100",
            $producto_id
        ), ARRAY_A) ?: [];

        wp_send_json_success([
            'preferidas' => $preferidas,
            'actuales' => $actuales,
            'historial' => $historial,
        ]);
    }

    public function ajax_save_preferred_location() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();

        $producto_id = $this->post_int('producto_base_id');
        $ubicacion_id = $this->post_int('ubicacion_id');
        $es_preferido = $this->post_int('es_preferido');
        $prioridad = $this->post_int('prioridad', 100);
        $notas = isset($_POST['notas']) ? sanitize_textarea_field(wp_unslash($_POST['notas'])) : '';

        if (!$producto_id || !$ubicacion_id) {
            wp_send_json_error(['message' => 'Producto y ubicación requeridos']);
        }
        if (!$this->get_location($ubicacion_id)) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }

        if ($this->is_unknown_location($ubicacion_id)) {
            wp_send_json_error(['message' => 'La ubicacion especial ? no puede ser preferida'], 400);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_ubicacion_preferida
             WHERE producto_base_id = %d AND ubicacion_id = %d",
            $producto_id,
            $ubicacion_id
        ));

        if ($es_preferido) {
            $wpdb->update(
                "{$prefix}producto_ubicacion_preferida",
                ['es_preferido' => 0],
                ['producto_base_id' => $producto_id],
                ['%d'],
                ['%d']
            );
        }

        $payload = [
            'producto_base_id' => $producto_id,
            'ubicacion_id' => $ubicacion_id,
            'es_preferido' => $es_preferido ? 1 : 0,
            'prioridad' => $prioridad,
            'notas' => $notas,
        ];

        if ($existing) {
            $wpdb->update("{$prefix}producto_ubicacion_preferida", $payload, ['id' => intval($existing)]);
            $id = intval($existing);
        } else {
            $wpdb->insert("{$prefix}producto_ubicacion_preferida", $payload);
            $id = (int) $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id, 'message' => 'Ubicación preferida guardada']);
    }

    public function ajax_remove_preferred_location() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $producto_id = $this->post_int('producto_base_id');
        $ubicacion_id = $this->post_int('ubicacion_id');
        if (!$producto_id || !$ubicacion_id) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }
        $wpdb->delete("{$prefix}producto_ubicacion_preferida", [
            'producto_base_id' => $producto_id,
            'ubicacion_id' => $ubicacion_id,
        ], ['%d', '%d']);
        wp_send_json_success(['message' => 'Ubicación preferida eliminada']);
    }

    public function ajax_set_primary_location() {
        $this->require_nonce();
        if (!$this->can_edit_locations()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $producto_id = $this->post_int('producto_base_id');
        $ubicacion_id = $this->post_int('ubicacion_id');
        if (!$producto_id || !$ubicacion_id) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }

        if ($this->is_unknown_location($ubicacion_id)) {
            wp_send_json_error(['message' => 'La ubicacion especial ? no puede ser preferida'], 400);
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_ubicacion_preferida
             WHERE producto_base_id = %d AND ubicacion_id = %d",
            $producto_id,
            $ubicacion_id
        ));
        if (!$exists) {
            $wpdb->insert("{$prefix}producto_ubicacion_preferida", [
                'producto_base_id' => $producto_id,
                'ubicacion_id' => $ubicacion_id,
                'es_preferido' => 1,
                'prioridad' => 1,
            ]);
        }

        $wpdb->update(
            "{$prefix}producto_ubicacion_preferida",
            ['es_preferido' => 0],
            ['producto_base_id' => $producto_id],
            ['%d'],
            ['%d']
        );
        $wpdb->update(
            "{$prefix}producto_ubicacion_preferida",
            ['es_preferido' => 1, 'prioridad' => 1],
            ['producto_base_id' => $producto_id, 'ubicacion_id' => $ubicacion_id],
            ['%d', '%d'],
            ['%d', '%d']
        );
        wp_send_json_success(['message' => 'Ubicación marcada como preferida']);
    }

    // ========== AJAX: conteos ==========

    public function ajax_start_count() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();

        $tipo = $this->post_text('tipo_conteo', 'general');
        if (!isset(self::COUNT_TYPES[$tipo])) {
            $tipo = 'general';
        }
        $ubicacion_id = $this->post_int('ubicacion_id') ?: null;
        $producto_id = $this->post_int('producto_base_id') ?: null;
        $orden_id = $this->post_int('orden_id') ?: null;
        $nombre = $this->post_text('nombre');

        if ($tipo === 'lugar' && !$ubicacion_id) {
            wp_send_json_error(['message' => 'Selecciona un lugar para inventariar']);
        }
        if ($tipo === 'producto') {
            if (!$producto_id) {
                wp_send_json_error(['message' => 'Selecciona un producto para inventariar']);
            }
            $product = $this->get_product($producto_id);
            if (!$product || !$this->product_has_local_sku($product)) {
                wp_send_json_error(['message' => 'Solo se pueden inventariar productos locales con SKU local']);
            }
        } elseif ($producto_id && !$this->get_product($producto_id)) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        if ($ubicacion_id && !$this->get_location($ubicacion_id)) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }

        if ($nombre === '') {
            $nombre = self::COUNT_TYPES[$tipo] . ' ' . current_time('Y-m-d H:i');
        }

        $ok = $wpdb->insert("{$prefix}conteos", [
            'tipo' => $tipo === 'general' ? 'completo' : 'parcial',
            'tipo_conteo' => $tipo,
            'nombre' => $nombre,
            'ubicacion_id' => $ubicacion_id,
            'producto_base_id' => $producto_id,
            'orden_id' => $orden_id,
            'estado' => 'abierto',
            'iniciado_por' => get_current_user_id(),
            'iniciado_en' => current_time('mysql'),
        ]);
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo iniciar el conteo']);
        }

        $id = (int) $wpdb->insert_id;

        if ($orden_id) {
            $wpdb->update(
                "{$prefix}ordenes_inventario",
                ['estado' => 'en_progreso'],
                ['id' => $orden_id, 'estado' => 'pendiente']
            );
        }

        $count = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteos WHERE id = %d", $id), ARRAY_A);
        wp_send_json_success(['count' => $this->format_count($count, true)]);
    }

    public function ajax_list_counts() {
        $this->require_nonce();
        if (!current_user_can('riverso_view_inventory_history') && !$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $estado = $this->post_text('estado');
        $page = max(1, $this->post_int('page', 1));
        $per_page = min(100, max(10, $this->post_int('per_page', 20)));
        $offset = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];
        if ($estado !== '') {
            $where[] = 'c.estado = %s';
            $params[] = $estado;
        }

        $from = "FROM {$prefix}conteos c WHERE " . implode(' AND ', $where);
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", ...$params)) : $wpdb->get_var("SELECT COUNT(*) {$from}"));

        $sql = "SELECT c.* {$from} ORDER BY c.id DESC LIMIT %d OFFSET %d";
        $qparams = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$qparams), ARRAY_A) ?: [];

        wp_send_json_success([
            'counts' => array_map(function ($row) {
                return $this->format_count($row, false);
            }, $rows),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }

    public function ajax_get_count() {
        $this->require_nonce();
        if (!$this->can_do_inventory() && !current_user_can('riverso_view_inventory_history')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $count = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteos WHERE id = %d", $id), ARRAY_A);
        if (!$count) {
            wp_send_json_error(['message' => 'Conteo no encontrado']);
        }
        wp_send_json_success([
            'count' => $this->format_count($count, true),
            'summary' => $this->build_summary($id),
        ]);
    }

    public function ajax_get_count_summary() {
        $this->require_nonce();
        if (!$this->can_do_inventory() && !current_user_can('riverso_view_inventory_history')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $id = $this->post_int('id');
        if (!$id) {
            wp_send_json_error(['message' => 'Conteo requerido']);
        }
        wp_send_json_success($this->build_summary($id));
    }

    public function ajax_add_count_item() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $conteo_id = $this->post_int('conteo_id');
        $count = $this->get_open_count($conteo_id);
        if (!$count) {
            wp_send_json_error(['message' => 'No hay un conteo abierto']);
        }

        $barcode = $this->post_text('barcode');
        $ubicacion_id = $this->post_int('ubicacion_id') ?: intval($count['ubicacion_id']);
        $es_abierto = $this->post_int('es_abierto') ? 1 : 0;
        $qty_input = isset($_POST['cantidad']) && $_POST['cantidad'] !== '' ? floatval($_POST['cantidad']) : null;
        $tipo = $count['tipo_conteo'] ?? '';
        $decoded = null;
        $accion = $qty_input !== null ? 'manual' : 'scan';

        if ($tipo === 'producto') {
            $target_id = intval($count['producto_base_id']);
            $product = $this->get_product($target_id);
            if (!$product || !$this->product_has_local_sku($product)) {
                wp_send_json_error(['message' => 'Este conteo requiere un producto local con SKU local']);
            }
            if ($ubicacion_id && !$this->get_location($ubicacion_id)) {
                wp_send_json_error(['message' => 'Ubicación no encontrada']);
            }
            if (!$ubicacion_id) {
                $ubicacion_id = null;
            }
            if ($barcode === '') {
                $barcode = trim((string) $product['canonical_sku']);
            }

            $classified = $this->classify_scan_for_product($barcode, $target_id);
            $status = $classified['status'] ?? '';

            if ($status === 'other_product') {
                $other = $classified['other'] ?? [];
                $this->log_scan([
                    'conteo_id' => $conteo_id,
                    'ubicacion_id' => $ubicacion_id,
                    'barcode_raw' => $barcode,
                    'tipo_barcode' => 'other_product',
                    'producto_base_id' => intval($other['id'] ?? 0) ?: null,
                    'accion' => 'rechazar',
                ]);
                $other_sku = trim((string) ($other['sku'] ?? '')) ?: 'sin SKU';
                $other_nombre = trim((string) ($other['nombre'] ?? '')) ?: 'producto desconocido';
                wp_send_json_error([
                    'message' => sprintf(
                        'Warning: el código %s pertenece a otro producto (%s · %s). No se sumó.',
                        $barcode,
                        $other_sku,
                        $other_nombre
                    ),
                    'code' => 'other_product',
                    'barcode' => $barcode,
                    'other' => $other,
                ]);
            }

            if ($status === 'unknown') {
                if ($this->post_int('ignorar')) {
                    $this->log_scan([
                        'conteo_id' => $conteo_id,
                        'ubicacion_id' => $ubicacion_id,
                        'barcode_raw' => $barcode,
                        'tipo_barcode' => 'unknown',
                        'producto_base_id' => $target_id,
                        'accion' => 'ignorar',
                    ]);
                    wp_send_json_error([
                        'message' => 'Código ignorado. No se sumó.',
                        'code' => 'ignored',
                        'barcode' => $barcode,
                    ]);
                }
                if (!$this->post_int('vincular')) {
                    wp_send_json_error([
                        'message' => 'El código no está registrado para este producto.',
                        'code' => 'unknown_sku',
                        'barcode' => $barcode,
                        'sku_local' => $product['canonical_sku'],
                        'producto_nombre' => $product['nombre_canonico'],
                        'suggested_pack_qty' => intval($classified['suggested_pack_qty'] ?? 1),
                    ]);
                }
                $pack_qty = $this->post_float('cantidad_envase');
                if ($pack_qty <= 0) {
                    $pack_qty = floatval($classified['suggested_pack_qty'] ?? 1);
                }
                $decoded = $this->link_barcode_to_product($barcode, $target_id, $pack_qty);
                if (!$decoded) {
                    wp_send_json_error(['message' => 'No se pudo vincular el código de barras']);
                }
                $accion = 'vincular';
            } elseif ($status === 'ok') {
                $decoded = $classified['decoded'] ?? null;
            } else {
                wp_send_json_error(['message' => 'Código no válido para este producto']);
            }
        } else {
            if (!$ubicacion_id) {
                wp_send_json_error(['message' => 'Selecciona o escanea un lugar primero']);
            }
            if (!$this->get_location($ubicacion_id)) {
                wp_send_json_error(['message' => 'Ubicación no encontrada']);
            }
            if ($tipo === 'lugar' && intval($count['ubicacion_id']) && intval($count['ubicacion_id']) !== intval($ubicacion_id)) {
                wp_send_json_error(['message' => 'Este conteo está limitado a un solo lugar']);
            }

            $decoded = $this->decode_barcode($barcode);
            if (!$decoded) {
                $producto_id = $this->post_int('producto_base_id');
                if ($producto_id) {
                    $product = $this->get_product($producto_id);
                    if (!$product) {
                        wp_send_json_error(['message' => 'Producto no encontrado']);
                    }
                    $decoded = [
                        'producto_base_id' => $producto_id,
                        'sku' => $product['canonical_sku'],
                        'nombre' => $product['nombre_canonico'],
                        'cantidad' => 1,
                        'envase_id' => $this->post_int('envase_id') ?: null,
                        'tipo' => 'manual',
                        'barcode' => $barcode,
                    ];
                }
            }
            if (!$decoded) {
                wp_send_json_error(['message' => 'Código no reconocido']);
            }
            if (!$this->product_has_local_sku([
                'canonical_sku' => $decoded['sku'] ?? '',
            ])) {
                wp_send_json_error([
                    'message' => 'Ese código apunta a un producto online sin SKU local. No se sumó. Asigna el barcode al SKU local.',
                    'code' => 'online_without_local_sku',
                    'barcode' => $barcode,
                    'producto' => $decoded['nombre'] ?? '',
                    'sku' => $decoded['sku'] ?? '',
                ]);
            }
        }

        if (!$decoded) {
            wp_send_json_error(['message' => 'Código no reconocido']);
        }

        $pack_qty = floatval($decoded['cantidad'] ?? 1);
        if ($pack_qty <= 0) {
            $pack_qty = 1;
        }
        if ($tipo === 'producto') {
            $units = $qty_input !== null ? $qty_input : $pack_qty;
        } else {
            $units = $qty_input !== null ? ($qty_input * $pack_qty) : $pack_qty;
        }
        if ($units <= 0) {
            wp_send_json_error(['message' => 'La cantidad debe ser mayor a 0']);
        }

        wp_send_json_success($this->insert_or_update_count_item(
            $count,
            $decoded,
            $barcode,
            $ubicacion_id,
            $units,
            $qty_input,
            $es_abierto,
            $accion
        ));
    }

    public function ajax_update_count_item() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $item_id = $this->post_int('item_id');
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteo_items WHERE id = %d", $item_id), ARRAY_A);
        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }
        $count = $this->get_open_count($item['conteo_id']);
        if (!$count) {
            wp_send_json_error(['message' => 'El conteo ya está cerrado']);
        }

        $update = [];
        if (isset($_POST['cantidad_contada'])) {
            $update['cantidad_contada'] = max(0, floatval($_POST['cantidad_contada']));
        }
        if (isset($_POST['es_abierto'])) {
            $update['es_abierto'] = intval($_POST['es_abierto']) ? 1 : 0;
        }
        if (empty($update)) {
            wp_send_json_error(['message' => 'Sin cambios']);
        }

        $wpdb->update("{$prefix}conteo_items", $update, ['id' => $item_id]);
        $this->log_scan([
            'conteo_id' => $item['conteo_id'],
            'conteo_item_id' => $item_id,
            'ubicacion_id' => $item['ubicacion_id'],
            'barcode_raw' => $item['codigo'],
            'producto_base_id' => $item['producto_base_id'],
            'envase_id' => $item['envase_id'],
            'cantidad_decodificada' => $update['cantidad_contada'] ?? $item['cantidad_contada'],
            'es_abierto' => $update['es_abierto'] ?? $item['es_abierto'],
            'accion' => 'modificar',
        ]);

        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteo_items WHERE id = %d", $item_id), ARRAY_A);
        wp_send_json_success([
            'item' => $this->format_count_item($fresh),
            'summary' => $this->build_summary($item['conteo_id']),
        ]);
    }

    public function ajax_delete_count_item() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $item_id = $this->post_int('item_id');
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteo_items WHERE id = %d", $item_id), ARRAY_A);
        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }
        $count = $this->get_open_count($item['conteo_id']);
        if (!$count) {
            wp_send_json_error(['message' => 'El conteo ya está cerrado']);
        }

        $this->log_scan([
            'conteo_id' => $item['conteo_id'],
            'conteo_item_id' => $item_id,
            'ubicacion_id' => $item['ubicacion_id'],
            'barcode_raw' => $item['codigo'],
            'producto_base_id' => $item['producto_base_id'],
            'envase_id' => $item['envase_id'],
            'cantidad_decodificada' => $item['cantidad_contada'],
            'es_abierto' => $item['es_abierto'],
            'accion' => 'eliminar',
        ]);
        $wpdb->delete("{$prefix}conteo_items", ['id' => $item_id], ['%d']);
        wp_send_json_success([
            'message' => 'Ítem eliminado',
            'summary' => $this->build_summary($item['conteo_id']),
        ]);
    }

    public function ajax_change_count_location() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $conteo_id = $this->post_int('conteo_id');
        $count = $this->get_open_count($conteo_id);
        if (!$count) {
            wp_send_json_error(['message' => 'No hay un conteo abierto']);
        }

        $code = $this->post_text('barcode');
        $ubicacion_id = $this->post_int('ubicacion_id');

        if ($this->post_int('clear')) {
            if (($count['tipo_conteo'] ?? '') === 'lugar') {
                wp_send_json_error(['message' => 'Este conteo está limitado a un solo lugar']);
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}conteos SET ubicacion_id = NULL WHERE id = %d",
                $conteo_id
            ));
            wp_send_json_success(['location' => null]);
        }

        $loc = $ubicacion_id ? $this->get_location($ubicacion_id) : $this->find_location_by_code($code);
        if (!$loc) {
            wp_send_json_error(['message' => 'Ubicación no encontrada']);
        }
        if (($count['tipo_conteo'] ?? '') === 'lugar' && intval($count['ubicacion_id']) && intval($count['ubicacion_id']) !== intval($loc['id'])) {
            wp_send_json_error(['message' => 'Este conteo está limitado a un solo lugar']);
        }

        $wpdb->update("{$prefix}conteos", ['ubicacion_id' => intval($loc['id'])], ['id' => $conteo_id]);
        wp_send_json_success(['location' => $loc]);
    }

    public function ajax_preview_close() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $conteo_id = $this->post_int('id');
        $count = $this->get_open_count($conteo_id);
        if (!$count) {
            wp_send_json_error(['message' => 'No hay un conteo abierto']);
        }
        wp_send_json_success($this->build_close_preview($count));
    }

    public function ajax_close_count() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $conteo_id = $this->post_int('id');
        $count = $this->get_open_count($conteo_id);
        if (!$count) {
            wp_send_json_error(['message' => 'No hay un conteo abierto']);
        }

        $now = current_time('mysql');
        $this->apply_count_stock_corrections($count, $now);

        $wpdb->update("{$prefix}conteos", [
            'estado' => 'cerrado',
            'cerrado_en' => $now,
        ], ['id' => $conteo_id]);

        if (!empty($count['orden_id'])) {
            $this->sync_order_after_count(intval($count['orden_id']), $conteo_id, $count);
        }

        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}conteos WHERE id = %d", $conteo_id), ARRAY_A);
        wp_send_json_success([
            'count' => $this->format_count($fresh, true),
            'summary' => $this->build_summary($conteo_id),
        ]);
    }

    private function counted_qty_map($conteo_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT producto_base_id, ubicacion_id, SUM(cantidad_contada) AS cantidad
             FROM {$prefix}conteo_items
             WHERE conteo_id = %d AND es_abierto = 0
               AND producto_base_id IS NOT NULL AND producto_base_id > 0
               AND ubicacion_id IS NOT NULL AND ubicacion_id > 0
             GROUP BY producto_base_id, ubicacion_id",
            intval($conteo_id)
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $pid = intval($row['producto_base_id']);
            $uid = intval($row['ubicacion_id']);
            $map[$pid][$uid] = floatval($row['cantidad']);
        }
        return $map;
    }

    private function location_stock_rows($ubicacion_id) {
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT pu.product_id AS producto_base_id,
                    pb.canonical_sku,
                    pb.nombre_canonico,
                    pu.cantidad
             FROM {$prefix}producto_ubicacion pu
             INNER JOIN {$prefix}producto_base pb ON pb.id = pu.product_id
             WHERE pu.ubicacion_id = %d
               AND pu.cantidad <> 0",
            intval($ubicacion_id)
        ), ARRAY_A) ?: [];
    }

    private function location_qty($producto_base_id, $ubicacion_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $qty = $wpdb->get_var($wpdb->prepare(
            "SELECT cantidad FROM {$prefix}producto_ubicacion
             WHERE product_id = %d AND ubicacion_id = %d",
            intval($producto_base_id),
            intval($ubicacion_id)
        ));
        return floatval($qty ?? 0);
    }

    private function product_label($producto_base_id) {
        $p = $this->get_product($producto_base_id);
        return [
            'canonical_sku' => $p['canonical_sku'] ?? '',
            'nombre_canonico' => $p['nombre_canonico'] ?? '',
        ];
    }

    private function location_expected_rows($ubicacion_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $ubicacion_id = intval($ubicacion_id);
        $by_id = [];

        foreach ($this->location_stock_rows($ubicacion_id) as $row) {
            $pid = intval($row['producto_base_id']);
            $by_id[$pid] = $row;
        }

        $hist = $wpdb->get_results($wpdb->prepare(
            "SELECT h.producto_base_id,
                    pb.canonical_sku,
                    pb.nombre_canonico,
                    h.cantidad_contada AS cantidad
             FROM {$prefix}producto_ubicacion_historial h
             INNER JOIN (
                SELECT producto_base_id, MAX(id) AS max_id
                FROM {$prefix}producto_ubicacion_historial
                WHERE ubicacion_id = %d
                GROUP BY producto_base_id
             ) last ON last.max_id = h.id
             INNER JOIN {$prefix}producto_base pb ON pb.id = h.producto_base_id
             WHERE h.cantidad_contada <> 0",
            $ubicacion_id
        ), ARRAY_A) ?: [];

        foreach ($hist as $row) {
            $pid = intval($row['producto_base_id']);
            if (!isset($by_id[$pid])) {
                $by_id[$pid] = $row;
            }
        }

        return array_values($by_id);
    }

    private function count_ubicacion_id($count) {
        if (!empty($count['ubicacion_id'])) {
            return intval($count['ubicacion_id']);
        }
        if (($count['tipo_conteo'] ?? '') !== 'lugar') {
            return 0;
        }
        global $wpdb;
        $prefix = $this->prefix();
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT ubicacion_id FROM {$prefix}conteo_items
             WHERE conteo_id = %d AND ubicacion_id IS NOT NULL AND ubicacion_id > 0
             LIMIT 1",
            intval($count['id'])
        )));
    }

    private function build_close_preview($count) {
        $conteo_id = intval($count['id']);
        $tipo = $count['tipo_conteo'] ?? '';
        $map = $this->counted_qty_map($conteo_id);
        $missing = [];
        $diffs = [];

        foreach ($map as $pid => $locs) {
            foreach ($locs as $uid => $counted) {
                $current = $this->location_qty($pid, $uid);
                if (abs($counted - $current) < 0.0001) {
                    continue;
                }
                $loc = $this->get_location($uid);
                $label = $this->product_label($pid);
                $diffs[] = [
                    'producto_base_id' => $pid,
                    'ubicacion_id' => $uid,
                    'canonical_sku' => $label['canonical_sku'],
                    'nombre_canonico' => $label['nombre_canonico'],
                    'ubicacion_codigo' => $loc['codigo'] ?? '',
                    'cantidad_sistema' => $current,
                    'cantidad_contada' => $counted,
                ];
            }
        }

        $ubicacion_id = $this->count_ubicacion_id($count);
        if ($tipo === 'lugar' && $ubicacion_id) {
            $loc = $this->get_location($ubicacion_id);
            foreach ($this->location_expected_rows($ubicacion_id) as $row) {
                $pid = intval($row['producto_base_id']);
                if (isset($map[$pid][$ubicacion_id])) {
                    continue;
                }
                $live = $this->location_qty($pid, $ubicacion_id);
                $shown = $live != 0.0 ? $live : floatval($row['cantidad']);
                $missing[] = [
                    'producto_base_id' => $pid,
                    'ubicacion_id' => $ubicacion_id,
                    'canonical_sku' => $row['canonical_sku'],
                    'nombre_canonico' => $row['nombre_canonico'],
                    'ubicacion_codigo' => $loc['codigo'] ?? '',
                    'cantidad_sistema' => $shown,
                    'cantidad_contada' => 0,
                ];
            }
        }

        return [
            'tipo_conteo' => $tipo,
            'ubicacion_id' => $ubicacion_id ?: null,
            'missing' => array_values($missing),
            'diffs' => array_values($diffs),
        ];
    }

    private function insert_count_historial($producto_base_id, $ubicacion_id, $conteo_id, $cantidad, $now) {
        global $wpdb;
        $prefix = $this->prefix();
        $wpdb->insert("{$prefix}producto_ubicacion_historial", [
            'producto_base_id' => intval($producto_base_id),
            'ubicacion_id' => intval($ubicacion_id),
            'conteo_id' => intval($conteo_id),
            'cantidad_contada' => floatval($cantidad),
            'fecha_conteo' => $now,
            'created_at' => $now,
        ]);
    }

    private function create_count_correction($producto_base_id, $ubicacion_id, $counted, $current, $conteo_id, $tipo_conteo) {
        $diff = floatval($counted) - floatval($current);
        if (abs($diff) < 0.0001) {
            return;
        }
        if (!class_exists('Riverso_Movement')) {
            return;
        }
        $label = ($tipo_conteo === 'producto') ? 'producto' : (($tipo_conteo === 'lugar') ? 'lugar' : 'inventario');
        Riverso_Movement::create('correccion', intval($producto_base_id), $diff, [
            'ubicacion_destino' => intval($ubicacion_id),
            'referencia_tipo' => 'conteo',
            'referencia_id' => intval($conteo_id),
            'notas' => 'Corrección por inventario de ' . $label . ' #' . intval($conteo_id),
        ]);
    }

    private function apply_count_stock_corrections($count, $now) {
        $conteo_id = intval($count['id']);
        $tipo = $count['tipo_conteo'] ?? '';
        $map = $this->counted_qty_map($conteo_id);

        foreach ($map as $pid => $locs) {
            foreach ($locs as $uid => $counted) {
                $this->insert_count_historial($pid, $uid, $conteo_id, $counted, $now);
                $current = $this->location_qty($pid, $uid);
                $this->create_count_correction($pid, $uid, $counted, $current, $conteo_id, $tipo);
            }
        }

        if ($tipo === 'lugar') {
            $ubicacion_id = $this->count_ubicacion_id($count);
            if ($ubicacion_id) {
                foreach ($this->location_expected_rows($ubicacion_id) as $row) {
                    $pid = intval($row['producto_base_id']);
                    if (isset($map[$pid][$ubicacion_id])) {
                        continue;
                    }
                    $current = $this->location_qty($pid, $ubicacion_id);
                    $this->insert_count_historial($pid, $ubicacion_id, $conteo_id, 0, $now);
                    $this->create_count_correction($pid, $ubicacion_id, 0, $current, $conteo_id, $tipo);
                }
            }
        }
    }

    public function ajax_abort_count() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $conteo_id = $this->post_int('id');
        $count = $this->get_open_count($conteo_id);
        if (!$count) {
            wp_send_json_error(['message' => 'No hay un conteo abierto para abortar']);
        }

        $wpdb->delete("{$prefix}conteo_scan_log", ['conteo_id' => $conteo_id], ['%d']);
        $wpdb->delete("{$prefix}conteo_items", ['conteo_id' => $conteo_id], ['%d']);

        if (!empty($count['orden_id'])) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}orden_inventario_items
                 SET conteo_id = NULL, estado = 'pendiente'
                 WHERE orden_id = %d AND conteo_id = %d",
                intval($count['orden_id']),
                $conteo_id
            ));
        }

        $deleted = $wpdb->delete("{$prefix}conteos", ['id' => $conteo_id, 'estado' => 'abierto'], ['%d', '%s']);
        if (!$deleted) {
            wp_send_json_error(['message' => 'No se pudo eliminar el conteo']);
        }

        wp_send_json_success(['message' => 'Conteo abortado y eliminado']);
    }

    private function sync_order_after_count($orden_id, $conteo_id, $count) {
        global $wpdb;
        $prefix = $this->prefix();

        if (!empty($count['ubicacion_id']) && ($count['tipo_conteo'] ?? '') === 'lugar') {
            $wpdb->update("{$prefix}orden_inventario_items", [
                'estado' => 'completado',
                'conteo_id' => $conteo_id,
            ], [
                'orden_id' => $orden_id,
                'ubicacion_id' => intval($count['ubicacion_id']),
            ]);
        } elseif (!empty($count['producto_base_id']) && ($count['tipo_conteo'] ?? '') === 'producto') {
            $wpdb->update("{$prefix}orden_inventario_items", [
                'estado' => 'completado',
                'conteo_id' => $conteo_id,
            ], [
                'orden_id' => $orden_id,
                'producto_base_id' => intval($count['producto_base_id']),
            ]);
        } else {
            $wpdb->update("{$prefix}orden_inventario_items", [
                'estado' => 'completado',
                'conteo_id' => $conteo_id,
            ], ['orden_id' => $orden_id, 'estado' => 'pendiente']);
        }

        $pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}orden_inventario_items WHERE orden_id = %d AND estado = 'pendiente'",
            $orden_id
        ));
        if ($pending === 0) {
            $wpdb->update("{$prefix}ordenes_inventario", [
                'estado' => 'completada',
                'completado_en' => current_time('mysql'),
            ], ['id' => $orden_id]);
        }
    }

    public function ajax_decode_barcode() {
        $this->require_nonce();
        if (!$this->can_do_inventory() && !current_user_can('riverso_scan_barcodes')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $code = $this->post_text('barcode');
        $loc = $this->find_location_by_code($code);
        if ($loc) {
            wp_send_json_success(['kind' => 'location', 'location' => $loc]);
        }
        $decoded = $this->decode_barcode($code);
        if (!$decoded) {
            wp_send_json_error(['message' => 'Código no reconocido']);
        }
        wp_send_json_success(['kind' => 'product', 'decoded' => $decoded]);
    }

    public function ajax_search_products() {
        $this->require_nonce();
        if (!current_user_can('riverso_view_products') && !$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $q = $this->post_text('q');
        if (strlen($q) < 1) {
            wp_send_json_success(['products' => []]);
        }
        $like = '%' . $wpdb->esc_like($q) . '%';
        $solo_sku = $this->post_int('solo_sku_local');
        $sku_filter = $solo_sku
            ? " AND canonical_sku IS NOT NULL AND TRIM(canonical_sku) <> ''"
            : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$prefix}producto_base
             WHERE estado = 'activo'{$sku_filter}
               AND (canonical_sku LIKE %s OR nombre_canonico LIKE %s)
             ORDER BY canonical_sku ASC
             LIMIT 20",
            $like,
            $like
        ), ARRAY_A);
        wp_send_json_success(['products' => $rows ?: []]);
    }

    // ========== AJAX: órdenes ==========

    public function ajax_list_orders() {
        $this->require_nonce();
        if (!current_user_can('riverso_manage_inventory_orders') && !$this->can_do_inventory() && !current_user_can('riverso_view_inventory_history')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $estado = $this->post_text('estado');
        $where = ['1=1'];
        $params = [];
        if ($estado !== '') {
            $where[] = 'o.estado = %s';
            $params[] = $estado;
        }
        $sql = "SELECT o.*, u.display_name AS creador_nombre
                FROM {$prefix}ordenes_inventario o
                LEFT JOIN {$wpdb->users} u ON u.ID = o.creado_por
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.id DESC LIMIT 100";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $orders = [];
        foreach ($rows ?: [] as $row) {
            $row['items'] = $wpdb->get_results($wpdb->prepare(
                "SELECT i.*, u.codigo AS ubicacion_codigo, u.nombre AS ubicacion_nombre,
                        pb.canonical_sku, pb.nombre_canonico
                 FROM {$prefix}orden_inventario_items i
                 LEFT JOIN {$prefix}ubicaciones u ON u.id = i.ubicacion_id
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = i.producto_base_id
                 WHERE i.orden_id = %d",
                intval($row['id'])
            ), ARRAY_A) ?: [];
            $orders[] = $row;
        }
        wp_send_json_success(['orders' => $orders, 'states' => self::ORDER_STATES]);
    }

    public function ajax_save_order() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_inventory_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $id = $this->post_int('id');
        $titulo = $this->post_text('titulo');
        $tipo = $this->post_text('tipo', 'general');
        $descripcion = isset($_POST['descripcion']) ? sanitize_textarea_field(wp_unslash($_POST['descripcion'])) : '';
        $prioridad = $this->post_int('prioridad');
        $asignado_a = $this->post_int('asignado_a') ?: null;
        $fecha = $this->post_text('fecha_programada');
        $ubicacion_id = $this->post_int('ubicacion_id') ?: null;
        $producto_id = $this->post_int('producto_base_id') ?: null;
        $items = [];
        if (!empty($_POST['items'])) {
            $raw = wp_unslash($_POST['items']);
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
        if ($ubicacion_id) {
            $items = [['ubicacion_id' => $ubicacion_id, 'producto_base_id' => null]];
        }
        if ($producto_id) {
            $items = [['ubicacion_id' => null, 'producto_base_id' => $producto_id]];
        }

        if (!isset(self::COUNT_TYPES[$tipo])) {
            $tipo = 'general';
        }
        if ($tipo === 'lugar') {
            $loc_id = intval($items[0]['ubicacion_id'] ?? 0);
            if (!$loc_id || !$this->get_location($loc_id)) {
                wp_send_json_error(['message' => 'Selecciona un lugar para la orden']);
            }
        }
        if ($tipo === 'producto') {
            $prod_id = intval($items[0]['producto_base_id'] ?? 0);
            $product = $prod_id ? $this->get_product($prod_id) : null;
            if (!$product || !$this->product_has_local_sku($product)) {
                wp_send_json_error(['message' => 'Selecciona un producto local con SKU local']);
            }
        }

        $data = [
            'titulo' => $titulo !== '' ? $titulo : null,
            'descripcion' => $descripcion,
            'tipo' => $tipo,
            'prioridad' => $prioridad ? 1 : 0,
            'asignado_a' => $asignado_a,
            'fecha_programada' => $fecha !== '' ? $fecha : null,
        ];

        if ($id) {
            $wpdb->update("{$prefix}ordenes_inventario", $data, ['id' => $id]);
        } else {
            $data['estado'] = 'pendiente';
            $data['creado_por'] = get_current_user_id();
            $wpdb->insert("{$prefix}ordenes_inventario", $data);
            $id = (int) $wpdb->insert_id;
        }

        if (!$id) {
            wp_send_json_error(['message' => 'No se pudo guardar la orden']);
        }

        if ($titulo === '') {
            $wpdb->update(
                "{$prefix}ordenes_inventario",
                ['titulo' => 'Orden #' . $id],
                ['id' => $id]
            );
        }

        $wpdb->delete("{$prefix}orden_inventario_items", ['orden_id' => $id], ['%d']);
        if ($tipo !== 'general') {
            foreach ($items as $it) {
                $item_loc = !empty($it['ubicacion_id']) ? intval($it['ubicacion_id']) : null;
                $item_prod = !empty($it['producto_base_id']) ? intval($it['producto_base_id']) : null;
                if (!$item_loc && !$item_prod) {
                    continue;
                }
                $wpdb->insert("{$prefix}orden_inventario_items", [
                    'orden_id' => $id,
                    'ubicacion_id' => $item_loc,
                    'producto_base_id' => $item_prod,
                    'estado' => 'pendiente',
                ]);
            }
        }

        wp_send_json_success(['id' => $id, 'message' => 'Orden #' . $id . ' guardada']);
    }

    public function ajax_update_order_status() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_inventory_orders');
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $estado = $this->post_text('estado');
        if (!$id || !isset(self::ORDER_STATES[$estado])) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }
        $update = ['estado' => $estado];
        if ($estado === 'completada') {
            $update['completado_en'] = current_time('mysql');
        }
        $wpdb->update("{$prefix}ordenes_inventario", $update, ['id' => $id]);
        wp_send_json_success(['message' => 'Estado actualizado']);
    }

    public function ajax_move_product() {
        $this->require_nonce();
        if (!$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $producto_id = $this->post_int('producto_base_id');
        $from_id = $this->post_int('ubicacion_origen_id') ?: null;
        $to_id = $this->post_int('ubicacion_destino_id');
        $cantidad = $this->post_float('cantidad', 0);
        $notas = isset($_POST['notas']) ? sanitize_textarea_field(wp_unslash($_POST['notas'])) : 'Ordenar producto';

        if (!$producto_id) {
            wp_send_json_error(['message' => 'Producto requerido']);
        }
        if (!$to_id) {
            $pref = $wpdb->get_var($wpdb->prepare(
                "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
                 WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
                $producto_id
            ));
            $to_id = intval($pref);
        }
        if (!$to_id) {
            wp_send_json_error(['message' => 'El producto no tiene un lugar preferido']);
        }
        if (!$this->get_product($producto_id) || !$this->get_location($to_id)) {
            wp_send_json_error(['message' => 'Producto o ubicación no encontrados']);
        }

        $movement_id = false;
        if (class_exists('Riverso_Movement')) {
            $movement_id = Riverso_Movement::create('traslado', $producto_id, $cantidad > 0 ? $cantidad : 1, [
                'ubicacion_origen' => $from_id,
                'ubicacion_destino' => $to_id,
                'referencia_tipo' => 'ordenar_producto',
                'notas' => $notas,
            ]);
        }

        wp_send_json_success([
            'message' => 'Movimiento registrado hacia el lugar preferido',
            'ubicacion_destino_id' => $to_id,
            'location' => $this->get_location($to_id),
            'movement_id' => $movement_id,
        ]);
    }

    // ========== AJAX: órdenes de ordenar ==========

    private function get_sort_order($id, $with_items = true) {
        global $wpdb;
        $prefix = $this->prefix();
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, u.display_name AS creador_nombre,
                    lo.codigo AS origen_codigo, lo.nombre AS origen_nombre
             FROM {$prefix}ordenes_ordenar o
             LEFT JOIN {$wpdb->users} u ON u.ID = o.creado_por
             LEFT JOIN {$prefix}ubicaciones lo ON lo.id = o.ubicacion_origen_id
             WHERE o.id = %d",
            intval($id)
        ), ARRAY_A);
        if (!$order) {
            return null;
        }
        if ($with_items) {
            $order['items'] = $this->get_sort_order_items(intval($id));
        }
        return $order;
    }

    private function get_sort_order_items($orden_id) {
        global $wpdb;
        $prefix = $this->prefix();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, pb.canonical_sku, pb.nombre_canonico,
                    ud.codigo AS destino_codigo, ud.nombre AS destino_nombre
             FROM {$prefix}orden_ordenar_items i
             INNER JOIN {$prefix}producto_base pb ON pb.id = i.producto_base_id
             LEFT JOIN {$prefix}ubicaciones ud ON ud.id = i.ubicacion_destino_id
             WHERE i.orden_id = %d
             ORDER BY i.id ASC",
            intval($orden_id)
        ), ARRAY_A) ?: [];
    }

    private function get_preferred_location_id($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
             WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
            intval($producto_base_id)
        )));
    }

    private function assert_sort_order_editable($order) {
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada']);
        }
        if (in_array($order['estado'], ['completada', 'cancelada'], true)) {
            wp_send_json_error(['message' => 'La orden ya está ' . $order['estado']]);
        }
    }

    public function ajax_sort_orders_list() {
        $this->require_nonce();
        if (!$this->can_manage_sort_orders() && !$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $this->prefix();
        $id = $this->post_int('id');
        $estado = $this->post_text('estado');
        $where = ['1=1'];
        $params = [];
        if ($id) {
            $where[] = 'o.id = %d';
            $params[] = $id;
        }
        if ($estado !== '') {
            $where[] = 'o.estado = %s';
            $params[] = $estado;
        }
        $sql = "SELECT o.*, u.display_name AS creador_nombre,
                       lo.codigo AS origen_codigo, lo.nombre AS origen_nombre,
                       (SELECT COUNT(*) FROM {$prefix}orden_ordenar_items oi WHERE oi.orden_id = o.id) AS items_count
                FROM {$prefix}ordenes_ordenar o
                LEFT JOIN {$wpdb->users} u ON u.ID = o.creado_por
                LEFT JOIN {$prefix}ubicaciones lo ON lo.id = o.ubicacion_origen_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.id DESC LIMIT 100";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $orders = [];
        foreach ($rows ?: [] as $row) {
            $row['items'] = $this->get_sort_order_items(intval($row['id']));
            $orders[] = $row;
        }
        wp_send_json_success([
            'orders' => $orders,
            'states' => self::SORT_ORDER_STATES,
            'item_states' => self::SORT_ITEM_STATES,
        ]);
    }

    public function ajax_sort_orders_save() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $id = $this->post_int('id');
        $titulo = $this->post_text('titulo');
        $notas = isset($_POST['notas']) ? sanitize_textarea_field(wp_unslash($_POST['notas'])) : '';
        $origen_raw = isset($_POST['ubicacion_origen_id']) ? wp_unslash($_POST['ubicacion_origen_id']) : '';
        $ubicacion_origen_id = ($origen_raw === '' || $origen_raw === '?' || $origen_raw === '0') ? null : intval($origen_raw);
        $asignado_a = $this->post_int('asignado_a') ?: null;

        if ($ubicacion_origen_id && !$this->get_location($ubicacion_origen_id)) {
            wp_send_json_error(['message' => 'Lugar de origen no encontrado']);
        }

        $data = [
            'titulo' => $titulo !== '' ? $titulo : null,
            'notas' => $notas !== '' ? $notas : null,
            'ubicacion_origen_id' => $ubicacion_origen_id,
            'asignado_a' => $asignado_a,
        ];

        if ($id) {
            $existing = $this->get_sort_order($id, false);
            $this->assert_sort_order_editable($existing);
            $wpdb->update("{$prefix}ordenes_ordenar", $data, ['id' => $id]);
            if ($existing['estado'] === 'pendiente') {
                $wpdb->update("{$prefix}ordenes_ordenar", ['estado' => 'en_progreso'], ['id' => $id]);
            }
        } else {
            $data['estado'] = 'pendiente';
            $data['creado_por'] = get_current_user_id();
            $wpdb->insert("{$prefix}ordenes_ordenar", $data);
            $id = (int) $wpdb->insert_id;
        }

        if (!$id) {
            wp_send_json_error(['message' => 'No se pudo guardar la orden']);
        }

        if ($titulo === '') {
            $wpdb->update(
                "{$prefix}ordenes_ordenar",
                ['titulo' => 'Orden #' . $id],
                ['id' => $id]
            );
        }

        wp_send_json_success([
            'id' => $id,
            'order' => $this->get_sort_order($id),
            'message' => 'Orden #' . $id . ' guardada',
        ]);
    }

    public function ajax_sort_orders_add_item() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $orden_id = $this->post_int('orden_id');
        $producto_id = $this->post_int('producto_base_id');
        $cantidad = max(1, $this->post_int('cantidad', 1));
        $destino_id = $this->post_int('ubicacion_destino_id') ?: null;

        $order = $this->get_sort_order($orden_id, false);
        $this->assert_sort_order_editable($order);
        if (!$producto_id || !$this->get_product($producto_id)) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        if ($destino_id && !$this->get_location($destino_id)) {
            wp_send_json_error(['message' => 'Lugar destino no encontrado']);
        }
        if (!$destino_id) {
            $destino_id = $this->get_preferred_location_id($producto_id) ?: null;
        }

        $wpdb->insert("{$prefix}orden_ordenar_items", [
            'orden_id' => $orden_id,
            'producto_base_id' => $producto_id,
            'cantidad' => $cantidad,
            'ubicacion_destino_id' => $destino_id,
            'estado' => 'pendiente',
        ]);
        $item_id = (int) $wpdb->insert_id;
        if (!$item_id) {
            wp_send_json_error(['message' => 'No se pudo agregar el producto']);
        }

        if ($order['estado'] === 'pendiente') {
            $wpdb->update("{$prefix}ordenes_ordenar", ['estado' => 'en_progreso'], ['id' => $orden_id]);
        }

        wp_send_json_success([
            'id' => $item_id,
            'order' => $this->get_sort_order($orden_id),
            'message' => 'Producto agregado',
        ]);
    }

    public function ajax_sort_orders_remove_item() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $item_id = $this->post_int('id');
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}orden_ordenar_items WHERE id = %d",
            $item_id
        ), ARRAY_A);
        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }
        $order = $this->get_sort_order(intval($item['orden_id']), false);
        $this->assert_sort_order_editable($order);
        if ($item['estado'] === 'completado') {
            wp_send_json_error(['message' => 'No se puede quitar un ítem ya completado']);
        }
        $wpdb->delete("{$prefix}orden_ordenar_items", ['id' => $item_id], ['%d']);
        wp_send_json_success([
            'order' => $this->get_sort_order(intval($item['orden_id'])),
            'message' => 'Producto eliminado',
        ]);
    }

    public function ajax_sort_orders_update_item() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $item_id = $this->post_int('id');
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}orden_ordenar_items WHERE id = %d",
            $item_id
        ), ARRAY_A);
        if (!$item) {
            wp_send_json_error(['message' => 'Ítem no encontrado']);
        }
        $order = $this->get_sort_order(intval($item['orden_id']), false);
        $this->assert_sort_order_editable($order);
        if ($item['estado'] === 'completado') {
            wp_send_json_error(['message' => 'No se puede editar un ítem ya completado']);
        }

        $update = [];
        if (isset($_POST['cantidad'])) {
            $update['cantidad'] = max(1, $this->post_int('cantidad', 1));
        }
        if (isset($_POST['ubicacion_destino_id'])) {
            $dest_raw = wp_unslash($_POST['ubicacion_destino_id']);
            if ($dest_raw === '' || $dest_raw === '?' || $dest_raw === '0') {
                $update['ubicacion_destino_id'] = null;
            } else {
                $dest_id = intval($dest_raw);
                if (!$this->get_location($dest_id)) {
                    wp_send_json_error(['message' => 'Lugar destino no encontrado']);
                }
                $update['ubicacion_destino_id'] = $dest_id;
            }
        }
        if (isset($_POST['estado'])) {
            $estado = $this->post_text('estado');
            if (!isset(self::SORT_ITEM_STATES[$estado])) {
                wp_send_json_error(['message' => 'Estado de ítem inválido']);
            }
            $update['estado'] = $estado;
        }
        if (!$update) {
            wp_send_json_error(['message' => 'Nada que actualizar']);
        }
        $wpdb->update("{$prefix}orden_ordenar_items", $update, ['id' => $item_id]);
        wp_send_json_success([
            'order' => $this->get_sort_order(intval($item['orden_id'])),
            'message' => 'Ítem actualizado',
        ]);
    }

    public function ajax_sort_orders_fill_destinations() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $orden_id = $this->post_int('orden_id');
        $order = $this->get_sort_order($orden_id, false);
        $this->assert_sort_order_editable($order);

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}orden_ordenar_items
             WHERE orden_id = %d AND ubicacion_destino_id IS NULL AND estado = 'pendiente'",
            $orden_id
        ), ARRAY_A) ?: [];

        $filled = 0;
        $pending = 0;
        foreach ($items as $item) {
            $pref = $this->get_preferred_location_id(intval($item['producto_base_id']));
            if ($pref) {
                $wpdb->update(
                    "{$prefix}orden_ordenar_items",
                    ['ubicacion_destino_id' => $pref],
                    ['id' => intval($item['id'])]
                );
                $filled++;
            } else {
                $pending++;
            }
        }

        wp_send_json_success([
            'order' => $this->get_sort_order($orden_id),
            'filled' => $filled,
            'pending' => $pending,
            'message' => $filled
                ? "Se rellenaron {$filled} destino(s) con el lugar preferido"
                : 'No había destinos por rellenar automáticamente',
        ]);
    }

    public function ajax_sort_orders_complete() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $orden_id = $this->post_int('orden_id');
        $order = $this->get_sort_order($orden_id);
        $this->assert_sort_order_editable($order);

        $items = $order['items'] ?? [];
        if (!$items) {
            wp_send_json_error(['message' => 'La orden no tiene productos']);
        }

        $origen_id = !empty($order['ubicacion_origen_id']) ? intval($order['ubicacion_origen_id']) : null;
        $to_process = array_filter($items, function ($it) {
            return ($it['estado'] ?? '') === 'pendiente';
        });

        foreach ($to_process as $item) {
            if (empty($item['ubicacion_destino_id'])) {
                wp_send_json_error([
                    'message' => 'Todos los ítems pendientes deben tener un lugar destino conocido (no "?")',
                    'sku' => $item['canonical_sku'] ?? '',
                ]);
            }
        }

        if (!class_exists('Riverso_Movement')) {
            wp_send_json_error(['message' => 'Módulo de movimientos no disponible']);
        }

        $moved = 0;
        $errors = [];
        foreach ($to_process as $item) {
            $destino_id = intval($item['ubicacion_destino_id']);
            $producto_id = intval($item['producto_base_id']);
            $cantidad = max(1, intval($item['cantidad']));

            if ($origen_id && $origen_id === $destino_id) {
                $errors[] = ($item['canonical_sku'] ?? 'Producto') . ': origen y destino son iguales';
                continue;
            }

            $movement_id = Riverso_Movement::create('traslado', $producto_id, $cantidad, [
                'ubicacion_origen' => $origen_id,
                'ubicacion_destino' => $destino_id,
                'referencia_tipo' => 'orden_ordenar',
                'referencia_id' => $orden_id,
                'notas' => 'Orden de ordenar #' . $orden_id,
            ]);
            if (!$movement_id) {
                $errors[] = ($item['canonical_sku'] ?? 'Producto') . ': no se pudo registrar movimiento';
                continue;
            }
            $wpdb->update(
                "{$prefix}orden_ordenar_items",
                ['estado' => 'completado', 'movement_id' => intval($movement_id)],
                ['id' => intval($item['id'])]
            );
            $moved++;
        }

        if ($moved === 0 && $errors) {
            wp_send_json_error(['message' => implode('; ', $errors)]);
        }

        $wpdb->update("{$prefix}ordenes_ordenar", [
            'estado' => 'completada',
            'completado_en' => current_time('mysql'),
        ], ['id' => $orden_id]);

        wp_send_json_success([
            'order' => $this->get_sort_order($orden_id),
            'moved' => $moved,
            'errors' => $errors,
            'message' => $moved
                ? "Orden completada: {$moved} producto(s) movidos"
                : 'Orden completada (sin ítems pendientes)',
        ]);
    }

    public function ajax_sort_orders_cancel() {
        $this->require_nonce();
        $this->require_cap('riverso_manage_sort_orders');
        global $wpdb;
        $prefix = $this->prefix();

        $orden_id = $this->post_int('orden_id');
        $order = $this->get_sort_order($orden_id, false);
        $this->assert_sort_order_editable($order);

        $wpdb->update("{$prefix}ordenes_ordenar", ['estado' => 'cancelada'], ['id' => $orden_id]);
        wp_send_json_success([
            'order' => $this->get_sort_order($orden_id),
            'message' => 'Orden cancelada',
        ]);
    }

    public function ajax_sort_orders_print() {
        $this->require_nonce();
        if (!$this->can_manage_sort_orders() && !$this->can_do_inventory()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $orden_id = $this->post_int('orden_id');
        $order = $this->get_sort_order($orden_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada']);
        }

        $titulo = !empty($order['titulo']) ? $order['titulo'] : ('Orden #' . $order['id']);
        $origen = !empty($order['origen_codigo'])
            ? esc_html($order['origen_codigo'] . ($order['origen_nombre'] ? ' · ' . $order['origen_nombre'] : ''))
            : '? (desconocido)';
        $creador = esc_html($order['creador_nombre'] ?? '—');
        $fecha = esc_html(current_time('d/m/Y H:i'));
        $estado = esc_html(self::SORT_ORDER_STATES[$order['estado']] ?? $order['estado']);

        $rows_html = '';
        $n = 0;
        foreach ($order['items'] ?? [] as $item) {
            if (($item['estado'] ?? '') === 'omitido') {
                continue;
            }
            $n++;
            $dest = !empty($item['destino_codigo'])
                ? esc_html($item['destino_codigo'] . ($item['destino_nombre'] ? ' · ' . $item['destino_nombre'] : ''))
                : '<strong>?</strong>';
            $rows_html .= '<tr>'
                . '<td>' . $n . '</td>'
                . '<td><strong>' . esc_html($item['canonical_sku']) . '</strong><br>'
                . esc_html($item['nombre_canonico']) . '</td>'
                . '<td style="text-align:center;">' . intval($item['cantidad']) . '</td>'
                . '<td>' . $dest . '</td>'
                . '<td style="text-align:center;width:40px;"><span style="display:inline-block;width:18px;height:18px;border:2px solid #333;"></span></td>'
                . '</tr>';
        }
        if ($rows_html === '') {
            $rows_html = '<tr><td colspan="5">Sin productos en la orden</td></tr>';
        }

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<title>' . esc_html($titulo) . '</title>'
            . '<style>'
            . 'body{font-family:Arial,sans-serif;margin:24px;color:#111;}'
            . 'h1{margin:0 0 8px;font-size:22px;}'
            . '.meta{margin-bottom:16px;font-size:13px;color:#444;}'
            . 'table{width:100%;border-collapse:collapse;font-size:13px;}'
            . 'th,td{border:1px solid #ccc;padding:8px;text-align:left;vertical-align:top;}'
            . 'th{background:#f5f5f5;}'
            . '@media print{body{margin:12mm;} .no-print{display:none;}}'
            . '</style></head><body>'
            . '<h1>Orden de ordenar #' . intval($order['id']) . '</h1>'
            . '<div class="meta">'
            . '<div><strong>Título:</strong> ' . esc_html($titulo) . '</div>'
            . '<div><strong>Origen:</strong> ' . $origen . '</div>'
            . '<div><strong>Estado:</strong> ' . $estado . '</div>'
            . '<div><strong>Creado por:</strong> ' . $creador . ' · <strong>Fecha:</strong> ' . $fecha . '</div>'
            . (!empty($order['notas']) ? '<div><strong>Notas:</strong> ' . esc_html($order['notas']) . '</div>' : '')
            . '</div>'
            . '<table><thead><tr>'
            . '<th>#</th><th>Producto</th><th>Cant.</th><th>Destino</th><th>✓</th>'
            . '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
            . '<p class="no-print" style="margin-top:20px;">'
            . '<button onclick="window.print()">Imprimir</button></p>'
            . '</body></html>';

        wp_send_json_success(['html' => $html, 'order' => $order]);
    }

    // ========== AJAX: Estado de stock (límites + confianza) ==========

    public function ajax_stock_status_list() {
        $this->require_nonce();
        if (!current_user_can('riverso_view_warehouse')
            && !current_user_can('riverso_view_stock')
            && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();

        $search = $this->post_text('search');
        $estado_inventariado = $this->post_text('estado_inventariado');
        $estado_confianza = $this->post_text('estado_confianza');
        $alerta = $this->post_text('alerta'); // '', '1', '0'

        $page = max(1, $this->post_int('page', 1));
        $per_page = min(50, max(1, $this->post_int('per_page', 20)));
        $offset = ($page - 1) * $per_page;

        $params = [];
        $likeSql = '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $likeSql = " AND (pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s) ";
            $params[] = $like;
            $params[] = $like;
        }

        // Subquery reusada
        $stock_total_sql = "(SELECT COALESCE(SUM(pu.cantidad),0) FROM {$prefix}producto_ubicacion pu WHERE pu.product_id = pb.id)";

        // Flags
        $exacto_sql = "EXISTS (
            SELECT 1 FROM {$prefix}conteos c
            WHERE c.estado = 'cerrado'
              AND c.tipo_conteo = 'producto'
              AND c.producto_base_id = pb.id
        )";

        $al_menos_sql = "EXISTS (
            SELECT 1
            FROM {$prefix}producto_ubicacion_historial h
            INNER JOIN {$prefix}conteos c ON c.id = h.conteo_id
            WHERE c.estado = 'cerrado'
              AND c.tipo_conteo = 'lugar'
              AND h.producto_base_id = pb.id
        )";

        $prod_recent_sql = "EXISTS (
            SELECT 1 FROM {$prefix}conteos c
            WHERE c.estado = 'cerrado'
              AND c.tipo_conteo = 'producto'
              AND c.producto_base_id = pb.id
              AND c.cerrado_en >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        )";

        // Todas las ubicaciones preferidas contadas en últimos 3 meses (aprox: que el producto aparezca en historial de esas ubicaciones)
        $total_pref_sql = "(SELECT COUNT(*) FROM {$prefix}producto_ubicacion_preferida p WHERE p.producto_base_id = pb.id)";
        $pref_counted_sql = "(SELECT COUNT(DISTINCT h.ubicacion_id)
            FROM {$prefix}producto_ubicacion_historial h
            INNER JOIN {$prefix}conteos c ON c.id = h.conteo_id
            INNER JOIN {$prefix}producto_ubicacion_preferida p2
                ON p2.producto_base_id = pb.id AND p2.ubicacion_id = h.ubicacion_id
            WHERE c.estado = 'cerrado'
              AND c.tipo_conteo = 'lugar'
              AND h.producto_base_id = pb.id
              AND c.cerrado_en >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        )";

        $inner = "
            SELECT
                pb.id,
                pb.canonical_sku,
                pb.nombre_canonico,
                {$stock_total_sql} AS stock_total,
                cfg.stock_minimo,
                cfg.stock_critico,
                CASE
                    WHEN {$exacto_sql} THEN 'exacto'
                    WHEN {$al_menos_sql} THEN 'al_menos'
                    ELSE 'desconocido'
                END AS estado_inventariado,
                CASE
                    WHEN {$stock_total_sql} < 0 THEN 'dudoso'
                    WHEN {$prod_recent_sql} THEN 'confiable'
                    WHEN ({$total_pref_sql} > 0 AND {$pref_counted_sql} = {$total_pref_sql}) THEN 'confiable'
                    ELSE 'poco_confiable'
                END AS estado_confianza,
                COALESCE(
                    (SELECT MAX(c.cerrado_en)
                     FROM {$prefix}conteos c
                     WHERE c.estado='cerrado' AND c.tipo_conteo='producto' AND c.producto_base_id=pb.id),
                    (SELECT MAX(h.fecha_conteo)
                     FROM {$prefix}producto_ubicacion_historial h
                     INNER JOIN {$prefix}conteos c ON c.id = h.conteo_id
                     WHERE c.estado='cerrado' AND c.tipo_conteo='lugar' AND h.producto_base_id=pb.id)
                ) AS ultimo_conteo_fecha,
                CASE
                    WHEN cfg.stock_minimo IS NOT NULL AND {$stock_total_sql} <= cfg.stock_minimo THEN 1
                    ELSE 0
                END AS alerta,
                CASE
                    WHEN cfg.stock_critico IS NOT NULL AND {$stock_total_sql} <= cfg.stock_critico THEN 1
                    ELSE 0
                END AS critico
            FROM {$prefix}producto_base pb
            LEFT JOIN {$prefix}producto_stock_config cfg
                ON cfg.producto_base_id = pb.id
            WHERE pb.estado = 'activo'
              AND pb.canonical_sku IS NOT NULL
              AND TRIM(pb.canonical_sku) <> ''
              {$likeSql}
        ";

        $outerWhere = ['1=1'];
        if ($estado_inventariado !== '') {
            $outerWhere[] = 'estado_inventariado = %s';
            $params[] = $estado_inventariado;
        }
        if ($estado_confianza !== '') {
            $outerWhere[] = 'estado_confianza = %s';
            $params[] = $estado_confianza;
        }
        if ($alerta !== '') {
            // '1' => solo alerta, '0' => solo sin alerta
            $alerta_val = intval($alerta);
            $outerWhere[] = 'alerta = %d';
            $params[] = $alerta_val;
        }

        $sql = "SELECT * FROM ({$inner}) t WHERE " . implode(' AND ', $outerWhere) . "
                ORDER BY t.canonical_sku ASC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        // Normalizar fechas para frontend
        foreach ($rows as &$row) {
            if (!empty($row['ultimo_conteo_fecha'])) {
                $row['ultimo_conteo_fecha'] = $row['ultimo_conteo_fecha'];
            } else {
                $row['ultimo_conteo_fecha'] = null;
            }
            $row['alerta'] = intval($row['alerta']);
            $row['critico'] = intval($row['critico']);
        }

        wp_send_json_success([
            'items' => $rows,
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }

    public function ajax_stock_status_save_config() {
        $this->require_nonce();
        $this->require_cap('riverso_edit_stock');

        global $wpdb;
        $prefix = $this->prefix();

        $producto_id = $this->post_int('producto_base_id');
        $stock_minimo = isset($_POST['stock_minimo']) && $_POST['stock_minimo'] !== '' ? intval($_POST['stock_minimo']) : null;
        $stock_critico = isset($_POST['stock_critico']) && $_POST['stock_critico'] !== '' ? intval($_POST['stock_critico']) : null;
        $last_changed = $this->post_text('last_changed');

        if ($stock_minimo !== null && $stock_minimo < 0) {
            $stock_minimo = 0;
        }
        if ($stock_critico !== null && $stock_critico < 0) {
            $stock_critico = 0;
        }
        if ($stock_minimo !== null && $stock_critico !== null && $stock_critico > $stock_minimo) {
            if ($last_changed === 'critico') {
                $stock_minimo = $stock_critico;
            } else {
                $stock_critico = $stock_minimo;
            }
        }

        if (!$producto_id) {
            wp_send_json_error(['message' => 'Producto requerido']);
        }

        $product = $this->get_product($producto_id);
        if (!$product || !$this->product_has_local_sku($product)) {
            wp_send_json_error(['message' => 'Producto local con SKU requerido']);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_stock_config WHERE producto_base_id = %d LIMIT 1",
            $producto_id
        ));

        $min_sql = $stock_minimo === null ? 'NULL' : (string) intval($stock_minimo);
        $crit_sql = $stock_critico === null ? 'NULL' : (string) intval($stock_critico);

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_stock_config
                 SET stock_minimo = {$min_sql}, stock_critico = {$crit_sql}
                 WHERE id = %d",
                intval($existing)
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$prefix}producto_stock_config (producto_base_id, stock_minimo, stock_critico)
                 VALUES (%d, {$min_sql}, {$crit_sql})",
                $producto_id
            ));
        }

        wp_send_json_success([
            'message' => 'Límites guardados',
            'stock_minimo' => $stock_minimo,
            'stock_critico' => $stock_critico,
        ]);
    }
}
