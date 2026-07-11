<?php
/**
 * Módulo de Punto de Venta (POS)
 * 
 * Sistema de ventas rápidas integrado con WooCommerce
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Module {
    
    private static $instance = null;
    
    /**
     * Obtener instancia singleton
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_riverso_pos_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_riverso_pos_search_customers', [$this, 'ajax_search_customers']);
        add_action('wp_ajax_riverso_pos_create_customer', [$this, 'ajax_create_customer']);
        add_action('wp_ajax_riverso_pos_get_product', [$this, 'ajax_get_product']);
        add_action('wp_ajax_riverso_pos_create_order', [$this, 'ajax_create_order']);
        add_action('wp_ajax_riverso_pos_get_cart_totals', [$this, 'ajax_get_cart_totals']);
        add_action('wp_ajax_riverso_pos_get_sessions', [$this, 'ajax_get_sessions']);
        add_action('wp_ajax_riverso_pos_open_session', [$this, 'ajax_open_session']);
        add_action('wp_ajax_riverso_pos_close_session', [$this, 'ajax_close_session']);
        add_action('wp_ajax_riverso_pos_get_session_orders', [$this, 'ajax_get_session_orders']);
        add_action('wp_ajax_riverso_pos_apply_discount', [$this, 'ajax_apply_discount']);
        add_action('wp_ajax_riverso_pos_void_order', [$this, 'ajax_void_order']);
        add_action('wp_ajax_riverso_pos_get_pending_orders', [$this, 'ajax_get_pending_orders']);
        add_action('wp_ajax_riverso_pos_hold_order', [$this, 'ajax_hold_order']);
        add_action('wp_ajax_riverso_pos_resume_order', [$this, 'ajax_resume_order']);
        add_action('wp_ajax_riverso_pos_rule_price', [$this, 'ajax_rule_price']);
    }

    /**
     * AJAX: precio unitario aplicando regla por tramos según la cantidad.
     *
     * Permite a la caja recalcular el precio cuando cambia la cantidad,
     * usando el precio asignado del dominio y la cantidad agregada de lotes
     * equivalentes cuando corresponde.
     */
    public function ajax_rule_price() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $variation_id = intval($_POST['variation_id'] ?? 0);
        $qty = floatval($_POST['qty'] ?? 1);

        if (!$product_id || $qty <= 0) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }

        if (!class_exists('Riverso_Pricing_Module')) {
            wp_send_json_error(['message' => 'Módulo de precios no disponible']);
        }

        $pricing = Riverso_Pricing_Module::get_instance();
        $base_id = $pricing->get_base_id_by_wc($product_id, $variation_id);

        $unit_price = null;
        $local_price = null;
        if ($base_id) {
            $local_row = $pricing->get_local_price($base_id);
            if ($local_row && $local_row['p_asignado'] !== null && $local_row['estado_aprobacion'] === 'aprobado') {
                $local_price = (float) $local_row['p_asignado'];
                $unit_price = $local_price;
            }
            if (class_exists('Riverso_Price_Rules_Module')) {
                $rp = Riverso_Price_Rules_Module::get_instance()->apply_for_base($base_id, $qty, $local_price);
                if ($rp !== null) {
                    $unit_price = (float) $rp;
                }
            }
        }

        wp_send_json_success([
            'producto_base_id' => $base_id,
            'unit_price' => $unit_price,
            'local_price' => $local_price,
            'qty' => $qty,
        ]);
    }
    
    /**
     * Crear tablas del módulo
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Tabla de sesiones POS
        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$prefix}pos_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            register_name VARCHAR(100) DEFAULT 'Caja 1',
            opening_amount DECIMAL(12,2) DEFAULT 0,
            closing_amount DECIMAL(12,2) DEFAULT NULL,
            expected_amount DECIMAL(12,2) DEFAULT NULL,
            difference DECIMAL(12,2) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            notes TEXT,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_status (status),
            KEY idx_opened_at (opened_at)
        ) $charset_collate;";
        
        // Tabla de órdenes en espera
        $sql_held = "CREATE TABLE IF NOT EXISTS {$prefix}pos_held_orders (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            cart_data LONGTEXT NOT NULL,
            total DECIMAL(12,2) DEFAULT 0,
            notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id)
        ) $charset_collate;";
        
        // Tabla de pagos POS
        $sql_payments = "CREATE TABLE IF NOT EXISTS {$prefix}pos_payments (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            reference VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_order (order_id),
            KEY idx_method (payment_method)
        ) $charset_collate;";
        
        dbDelta($sql_sessions);
        dbDelta($sql_held);
        dbDelta($sql_payments);
    }
    
    /**
     * Buscar productos - Búsqueda mejorada
     * Busca por: SKU, código de proveedor, código de barra, nombre
     * Incluye productos sin stock (bodega)
     */
    public function ajax_search_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 30;
        $include_out_of_stock = isset($_POST['include_out_of_stock']) ? $_POST['include_out_of_stock'] === 'true' : true;
        
        if (strlen($search) < 1) {
            wp_send_json_success(['products' => []]);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $found_ids = [];
        $results = [];
        $search_lower = strtolower($search);
        $search_normalized = ltrim($search, '0');
        if ($search_normalized === '') {
            $search_normalized = '0';
        }
        
        // 1. Buscar por SKU exacto (prioridad máxima)
        $exact_sku = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' AND meta_value = %s 
            LIMIT 1",
            $search
        ));
        
        if ($exact_sku) {
            $product = wc_get_product($exact_sku);
            if ($product) {
                $formatted = $this->format_product_extended($product, null, 'SKU exacto');
                if ($formatted) {
                    $results[] = $formatted;
                    $found_ids[] = $exact_sku;
                }
            }
        }
        
        // 2. Buscar por código de barra exacto (tabla propia)
        $barcode_product = $wpdb->get_row($wpdb->prepare(
            "SELECT product_id, variation_id FROM {$prefix}barcodes 
            WHERE (barcode = %s OR TRIM(LEADING '0' FROM barcode) = %s) AND is_active = 1 
            LIMIT 1",
            $search,
            $search_normalized
        ));
        
        if ($barcode_product) {
            $pid = $barcode_product->variation_id ?: $barcode_product->product_id;
            if ($pid && !in_array($pid, $found_ids)) {
                $product = wc_get_product($pid);
                if ($product) {
                    $formatted = $this->format_product_extended($product, null, 'Código de barra');
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $pid;
                    }
                }
            }
        }
        
        // 3. Buscar por código de barra de proveedor (supplier_barcode)
        $supplier_barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT product_id, variation_id, supplier_code, supplier_description 
            FROM {$prefix}supplier_product_links 
            WHERE (supplier_barcode = %s OR TRIM(LEADING '0' FROM supplier_barcode) = %s) AND is_active = 1 
            LIMIT 1",
            $search,
            $search_normalized
        ));
        
        if ($supplier_barcode) {
            $pid = $supplier_barcode->variation_id ?: $supplier_barcode->product_id;
            if ($pid && !in_array($pid, $found_ids)) {
                $product = wc_get_product($pid);
                if ($product) {
                    $match_info = 'Cod. Barra Proveedor: ' . $search;
                    $formatted = $this->format_product_extended($product, null, $match_info);
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $pid;
                    }
                }
            }
        }
        
        // 4. Buscar por código interno de proveedor (supplier_code)
        $supplier_codes = $wpdb->get_results($wpdb->prepare(
            "SELECT spl.product_id, spl.variation_id, spl.supplier_code, spl.supplier_description, p.razon_social as proveedor
            FROM {$prefix}supplier_product_links spl
            LEFT JOIN {$prefix}proveedores p ON spl.supplier_id = p.id
            WHERE (spl.supplier_code = %s OR spl.supplier_code LIKE %s OR TRIM(LEADING '0' FROM spl.supplier_code) = %s) 
            AND spl.is_active = 1 
            LIMIT 10",
            $search,
            '%' . $wpdb->esc_like($search) . '%',
            $search_normalized
        ));
        
        foreach ($supplier_codes as $row) {
            $pid = $row->variation_id ?: $row->product_id;
            if ($pid && !in_array($pid, $found_ids)) {
                $product = wc_get_product($pid);
                if ($product) {
                    $match_info = 'Cod. Proveedor: ' . $row->supplier_code;
                    if ($row->proveedor) {
                        $match_info .= ' (' . $row->proveedor . ')';
                    }
                    $formatted = $this->format_product_extended($product, null, $match_info);
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $pid;
                    }
                }
            }
        }
        
        // 5. Buscar por SKU parcial
        $partial_sku = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' AND meta_value LIKE %s 
            LIMIT 20",
            '%' . $wpdb->esc_like($search) . '%'
        ));
        
        foreach ($partial_sku as $row) {
            if (!in_array($row->post_id, $found_ids)) {
                $product = wc_get_product($row->post_id);
                if ($product) {
                    $formatted = $this->format_product_extended($product, null, 'SKU: ' . $product->get_sku());
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $row->post_id;
                    }
                }
            }
            if (count($results) >= $limit) break;
        }
        
        // 6. Buscar por código de barra parcial
        $partial_barcode = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, variation_id, barcode FROM {$prefix}barcodes 
            WHERE barcode LIKE %s AND is_active = 1 
            LIMIT 10",
            '%' . $wpdb->esc_like($search) . '%'
        ));
        
        foreach ($partial_barcode as $row) {
            $pid = $row->variation_id ?: $row->product_id;
            if ($pid && !in_array($pid, $found_ids)) {
                $product = wc_get_product($pid);
                if ($product) {
                    $formatted = $this->format_product_extended($product, null, 'Código: ' . $row->barcode);
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $pid;
                    }
                }
            }
            if (count($results) >= $limit) break;
        }
        
        // 7. Búsqueda por nombre de producto (WooCommerce)
        if (count($results) < $limit && strlen($search) >= 2) {
            $args = [
                'status' => 'publish',
                'limit' => $limit - count($results),
                's' => $search,
            ];
            
            $products = wc_get_products($args);
            
            foreach ($products as $product) {
                if (in_array($product->get_id(), $found_ids)) continue;
                
                if ($product->is_type('variable')) {
                    $variations = $product->get_available_variations();
                    foreach ($variations as $variation_data) {
                        if (in_array($variation_data['variation_id'], $found_ids)) continue;
                        $variation = wc_get_product($variation_data['variation_id']);
                        if ($variation) {
                            $formatted = $this->format_product_extended($variation, $product, 'Por nombre');
                            if ($formatted) {
                                $results[] = $formatted;
                                $found_ids[] = $variation_data['variation_id'];
                            }
                        }
                        if (count($results) >= $limit) break;
                    }
                } else {
                    $formatted = $this->format_product_extended($product, null, 'Por nombre');
                    if ($formatted) {
                        $results[] = $formatted;
                        $found_ids[] = $product->get_id();
                    }
                }
                if (count($results) >= $limit) break;
            }
        }
        
        // 8. Búsqueda en descripción de proveedor
        if (count($results) < $limit && strlen($search) >= 3) {
            $desc_search = $wpdb->get_results($wpdb->prepare(
                "SELECT spl.product_id, spl.variation_id, spl.supplier_code, spl.supplier_description
                FROM {$prefix}supplier_product_links spl
                WHERE spl.supplier_description LIKE %s AND spl.is_active = 1 
                LIMIT 10",
                '%' . $wpdb->esc_like($search) . '%'
            ));
            
            foreach ($desc_search as $row) {
                $pid = $row->variation_id ?: $row->product_id;
                if ($pid && !in_array($pid, $found_ids)) {
                    $product = wc_get_product($pid);
                    if ($product) {
                        $formatted = $this->format_product_extended($product, null, 'Desc. Proveedor');
                        if ($formatted) {
                            $results[] = $formatted;
                            $found_ids[] = $pid;
                        }
                    }
                }
                if (count($results) >= $limit) break;
            }
        }
        
        // Filtrar productos sin stock si se solicita
        if (!$include_out_of_stock) {
            $results = array_filter($results, function($p) {
                return $p['stock_status'] === 'instock' && ($p['stock_quantity'] === null || $p['stock_quantity'] > 0);
            });
            $results = array_values($results);
        }
        
        wp_send_json_success([
            'products' => $results,
            'count' => count($results),
            'search' => $search
        ]);
    }
    
    /**
     * Formatear producto con información extendida
     */
    private function format_product_extended($product, $parent = null, $match_source = '') {
        if (!$product) return null;
        
        $name = $product->get_name();
        if ($parent && $product->is_type('variation')) {
            $attributes = $product->get_variation_attributes();
            $attr_values = array_values($attributes);
            $name = $parent->get_name() . ' - ' . implode(' / ', $attr_values);
        }
        
        // Obtener ubicación de bodega si existe
        global $wpdb;
        $location = $wpdb->get_var($wpdb->prepare(
            "SELECT ubicacion FROM {$wpdb->prefix}riverso_warehouse_locations 
            WHERE producto_id = %d AND estado = 'activo' 
            ORDER BY cantidad DESC LIMIT 1",
            $product->get_id()
        ));
        
        // Obtener códigos de proveedor vinculados
        $supplier_codes = $wpdb->get_results($wpdb->prepare(
            "SELECT spl.supplier_code, p.razon_social 
            FROM {$wpdb->prefix}riverso_supplier_product_links spl
            LEFT JOIN {$wpdb->prefix}riverso_proveedores p ON spl.supplier_id = p.id
            WHERE (spl.product_id = %d OR spl.variation_id = %d) AND spl.is_active = 1
            LIMIT 3",
            $product->get_id(), $product->get_id()
        ));
        
        $supplier_info = [];
        foreach ($supplier_codes as $sc) {
            $supplier_info[] = $sc->supplier_code . ($sc->razon_social ? ' (' . $sc->razon_social . ')' : '');
        }
        
        $stock_qty = $product->get_stock_quantity();
        $stock_status = $product->get_stock_status();

        // Precio base WooCommerce.
        $wc_price = floatval($product->get_price());
        $effective_price = $wc_price;
        $local_price = null;
        $rule_price = null;
        $producto_base_id = 0;

        // Precio LOCAL del dominio (p_asignado aprobado) y regla por tramos.
        if (class_exists('Riverso_Pricing_Module')) {
            $pricing = Riverso_Pricing_Module::get_instance();
            if ($product->is_type('variation')) {
                $producto_base_id = $pricing->get_base_id_by_wc($product->get_parent_id(), $product->get_id());
            } else {
                $producto_base_id = $pricing->get_base_id_by_wc($product->get_id(), 0);
            }

            if ($producto_base_id) {
                $local_row = $pricing->get_local_price($producto_base_id);
                if ($local_row && $local_row['p_asignado'] !== null && $local_row['estado_aprobacion'] === 'aprobado') {
                    $local_price = (float) $local_row['p_asignado'];
                    $effective_price = $local_price;
                }

                // Precio por regla de tramos (cantidad 1 por defecto).
                if (class_exists('Riverso_Price_Rules_Module')) {
                    $rp = Riverso_Price_Rules_Module::get_instance()->apply_for_base($producto_base_id, 1, $local_price);
                    if ($rp !== null) {
                        $rule_price = (float) $rp;
                        $effective_price = $rule_price;
                    }
                }
            }
        }

        return [
            'id' => $product->get_id(),
            'name' => $name,
            'sku' => $product->get_sku() ?: '',
            'price' => $effective_price,
            'wc_price' => $wc_price,
            'local_price' => $local_price,
            'rule_price' => $rule_price,
            'producto_base_id' => $producto_base_id,
            'regular_price' => floatval($product->get_regular_price()),
            'sale_price' => $product->get_sale_price() ? floatval($product->get_sale_price()) : null,
            'stock_quantity' => $stock_qty,
            'stock_status' => $stock_status,
            'stock_display' => $stock_qty !== null ? $stock_qty : ($stock_status === 'instock' ? '∞' : '0'),
            'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '',
            'type' => $product->get_type(),
            'parent_id' => $parent ? $parent->get_id() : 0,
            'tax_class' => $product->get_tax_class(),
            'location' => $location ?: '',
            'supplier_codes' => $supplier_info,
            'match_source' => $match_source,
            'can_sell' => $stock_status === 'instock' || $stock_status === 'onbackorder',
        ];
    }
    
    /**
     * Formatear producto para respuesta (compatibilidad)
     */
    private function format_product($product, $parent = null) {
        return $this->format_product_extended($product, $parent, '');
    }
    
    /**
     * Obtener producto por ID
     */
    public function ajax_get_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(['message' => 'ID de producto inválido']);
        }
        
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        
        wp_send_json_success(['product' => $this->format_product($product)]);
    }
    
    /**
     * Buscar clientes
     */
    public function ajax_search_customers() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['customers' => []]);
        }
        
        $args = [
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'role__in' => ['customer', 'subscriber'],
        ];
        
        $users = get_users($args);
        $customers = [];
        
        foreach ($users as $user) {
            $customers[] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'phone' => get_user_meta($user->ID, 'billing_phone', true),
                'rut' => get_user_meta($user->ID, 'billing_rut', true),
                'company' => get_user_meta($user->ID, 'billing_company', true),
            ];
        }
        
        // También buscar por meta (teléfono, RUT)
        global $wpdb;
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} 
            WHERE meta_key IN ('billing_phone', 'billing_rut', 'billing_company') 
            AND meta_value LIKE %s 
            LIMIT 10",
            '%' . $wpdb->esc_like($search) . '%'
        ));
        
        foreach ($meta_results as $row) {
            $user = get_user_by('id', $row->user_id);
            if ($user && !in_array($row->user_id, array_column($customers, 'id'))) {
                $customers[] = [
                    'id' => $user->ID,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                    'phone' => get_user_meta($user->ID, 'billing_phone', true),
                    'rut' => get_user_meta($user->ID, 'billing_rut', true),
                    'company' => get_user_meta($user->ID, 'billing_company', true),
                ];
            }
        }
        
        wp_send_json_success(['customers' => $customers]);
    }
    
    /**
     * Crear cliente rápido
     */
    public function ajax_create_customer() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $rut = isset($_POST['rut']) ? sanitize_text_field($_POST['rut']) : '';
        
        if (empty($name)) {
            wp_send_json_error(['message' => 'Nombre es requerido']);
        }
        
        // Crear usuario de WordPress
        $username = sanitize_user(strtolower(str_replace(' ', '', $name))) . '_' . time();
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email ?: $username . '@cliente.local');
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => 'Error al crear cliente: ' . $user_id->get_error_message()]);
        }
        
        // Asignar rol de cliente
        $user = new WP_User($user_id);
        $user->set_role('customer');
        
        // Guardar metadata
        wp_update_user(['ID' => $user_id, 'display_name' => $name]);
        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'billing_rut', $rut);
        update_user_meta($user_id, 'first_name', explode(' ', $name)[0]);
        update_user_meta($user_id, 'billing_first_name', explode(' ', $name)[0]);
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'customer_created',
                'pos',
                $user_id,
                null,
                ['name' => $name, 'phone' => $phone, 'rut' => $rut]
            );
        }
        
        wp_send_json_success([
            'customer' => [
                'id' => $user_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'rut' => $rut,
            ],
            'message' => 'Cliente creado exitosamente'
        ]);
    }
    
    /**
     * Calcular totales del carrito
     */
    public function ajax_get_cart_totals() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
        $discount_type = isset($_POST['discount_type']) ? sanitize_text_field($_POST['discount_type']) : '';
        $discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : 0;
        
        $subtotal = 0;
        $tax_total = 0;
        $tax_rate = 0.19; // 19% IVA Chile
        
        foreach ($items as $item) {
            $line_total = floatval($item['price']) * floatval($item['quantity']);
            $subtotal += $line_total;
        }
        
        // Aplicar descuento
        $discount_amount = 0;
        if ($discount_type === 'percentage' && $discount_value > 0) {
            $discount_amount = $subtotal * ($discount_value / 100);
        } elseif ($discount_type === 'fixed' && $discount_value > 0) {
            $discount_amount = min($discount_value, $subtotal);
        }
        
        $subtotal_after_discount = $subtotal - $discount_amount;
        
        // Calcular IVA (precio incluye IVA en Chile)
        $net = $subtotal_after_discount / (1 + $tax_rate);
        $tax_total = $subtotal_after_discount - $net;
        
        $total = $subtotal_after_discount;
        
        wp_send_json_success([
            'subtotal' => round($subtotal, 0),
            'discount_amount' => round($discount_amount, 0),
            'net' => round($net, 0),
            'tax_total' => round($tax_total, 0),
            'total' => round($total, 0),
        ]);
    }
    
    /**
     * Crear orden WooCommerce
     */
    public function ajax_create_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos para crear órdenes']);
        }
        
        $items = isset($_POST['items']) ? json_decode(stripslashes($_POST['items']), true) : [];
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : 'Cliente Anónimo';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash';
        $payment_reference = isset($_POST['payment_reference']) ? sanitize_text_field($_POST['payment_reference']) : '';
        $discount_type = isset($_POST['discount_type']) ? sanitize_text_field($_POST['discount_type']) : '';
        $discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        
        if (empty($items)) {
            wp_send_json_error(['message' => 'El carrito está vacío']);
        }
        
        // Verificar sesión de caja
        if ($session_id) {
            global $wpdb;
            $session = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}riverso_pos_sessions WHERE id = %d AND status = 'open'",
                $session_id
            ));
            
            if (!$session) {
                wp_send_json_error(['message' => 'Sesión de caja no válida o cerrada']);
            }
        }
        
        try {
            // Crear orden WooCommerce
            $order = wc_create_order([
                'customer_id' => $customer_id,
                'created_via' => 'riverso_pos',
            ]);
            
            if (is_wp_error($order)) {
                wp_send_json_error(['message' => 'Error al crear orden: ' . $order->get_error_message()]);
            }
            
            $subtotal = 0;
            
            // Agregar productos
            foreach ($items as $item) {
                $product_id = intval($item['product_id']);
                $quantity = floatval($item['quantity']);
                $price = floatval($item['price']);
                
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    continue;
                }
                
                $item_id = $order->add_product($product, $quantity, [
                    'subtotal' => $price * $quantity,
                    'total' => $price * $quantity,
                ]);
                
                $subtotal += $price * $quantity;
            }
            
            // Aplicar descuento
            if ($discount_type && $discount_value > 0) {
                $discount_amount = 0;
                if ($discount_type === 'percentage') {
                    $discount_amount = $subtotal * ($discount_value / 100);
                } else {
                    $discount_amount = min($discount_value, $subtotal);
                }
                
                if ($discount_amount > 0) {
                    $coupon = new WC_Order_Item_Coupon();
                    $coupon->set_discount($discount_amount);
                    $coupon->set_code('POS-' . get_current_user_id() . '-' . time());
                    $order->add_item($coupon);
                }
            }
            
            // Configurar datos de facturación
            if ($customer_id) {
                $customer = get_user_by('id', $customer_id);
                if ($customer) {
                    $order->set_billing_first_name(get_user_meta($customer_id, 'billing_first_name', true) ?: $customer->display_name);
                    $order->set_billing_last_name(get_user_meta($customer_id, 'billing_last_name', true));
                    $order->set_billing_email($customer->user_email);
                    $order->set_billing_phone(get_user_meta($customer_id, 'billing_phone', true));
                    $order->set_billing_company(get_user_meta($customer_id, 'billing_company', true));
                }
            } else {
                $order->set_billing_first_name($customer_name);
            }
            
            // Configurar método de pago
            $payment_methods = [
                'cash' => 'Efectivo',
                'card' => 'Tarjeta',
                'transfer' => 'Transferencia',
                'mixed' => 'Pago Mixto',
            ];
            
            $order->set_payment_method($payment_method);
            $order->set_payment_method_title($payment_methods[$payment_method] ?? 'POS');
            
            // Agregar notas
            if ($notes) {
                $order->add_order_note($notes, false);
            }
            
            $order->add_order_note('Venta realizada desde POS por ' . wp_get_current_user()->display_name, false);
            
            // Guardar metadata POS
            $order->update_meta_data('_riverso_pos_sale', 'yes');
            $order->update_meta_data('_riverso_pos_cashier', get_current_user_id());
            $order->update_meta_data('_riverso_pos_session', $session_id);
            $order->update_meta_data('_riverso_pos_payment_method', $payment_method);
            $order->update_meta_data('_riverso_pos_payment_reference', $payment_reference);
            
            // Calcular totales y completar
            $order->calculate_totals();
            $order->set_status('completed', 'Venta POS completada');
            $order->save();
            
            // Registrar pago en tabla POS
            if ($session_id) {
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'riverso_pos_payments',
                    [
                        'session_id' => $session_id,
                        'order_id' => $order->get_id(),
                        'payment_method' => $payment_method,
                        'amount' => $order->get_total(),
                        'reference' => $payment_reference,
                    ],
                    ['%d', '%d', '%s', '%f', '%s']
                );
            }
            
            // Log de auditoría
            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'order_created',
                    'pos',
                    $order->get_id(),
                    null,
                    [
                        'total' => $order->get_total(),
                        'items_count' => count($items),
                        'payment_method' => $payment_method,
                        'session_id' => $session_id,
                    ]
                );
            }
            
            wp_send_json_success([
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'total' => $order->get_total(),
                'status' => $order->get_status(),
                'message' => 'Orden #' . $order->get_order_number() . ' creada exitosamente'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Obtener sesiones de caja
     */
    public function ajax_get_sessions() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $user_id = get_current_user_id();
        $show_all = current_user_can('riverso_manage_settings');
        
        $where = $show_all ? "1=1" : "user_id = $user_id";
        
        $sessions = $wpdb->get_results(
            "SELECT s.*, u.display_name as user_name,
                (SELECT COUNT(*) FROM {$prefix}pos_payments p WHERE p.session_id = s.id) as orders_count,
                (SELECT COALESCE(SUM(amount), 0) FROM {$prefix}pos_payments p WHERE p.session_id = s.id) as total_sales
            FROM {$prefix}pos_sessions s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            WHERE $where
            ORDER BY s.opened_at DESC
            LIMIT 50"
        );
        
        wp_send_json_success(['sessions' => $sessions]);
    }
    
    /**
     * Abrir sesión de caja
     */
    public function ajax_open_session() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $user_id = get_current_user_id();
        $register_name = isset($_POST['register_name']) ? sanitize_text_field($_POST['register_name']) : 'Caja 1';
        $opening_amount = isset($_POST['opening_amount']) ? floatval($_POST['opening_amount']) : 0;
        
        // Verificar si ya hay sesión abierta
        $open_session = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}pos_sessions WHERE user_id = %d AND status = 'open'",
            $user_id
        ));
        
        if ($open_session) {
            wp_send_json_error(['message' => 'Ya tienes una sesión de caja abierta']);
        }
        
        $wpdb->insert(
            $prefix . 'pos_sessions',
            [
                'user_id' => $user_id,
                'register_name' => $register_name,
                'opening_amount' => $opening_amount,
                'status' => 'open',
            ],
            ['%d', '%s', '%f', '%s']
        );
        
        $session_id = $wpdb->insert_id;
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'session_opened',
                'pos',
                $session_id,
                null,
                ['register_name' => $register_name, 'opening_amount' => $opening_amount]
            );
        }
        
        wp_send_json_success([
            'session_id' => $session_id,
            'message' => 'Sesión de caja abierta'
        ]);
    }
    
    /**
     * Cerrar sesión de caja
     */
    public function ajax_close_session() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $closing_amount = isset($_POST['closing_amount']) ? floatval($_POST['closing_amount']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        
        if (!$session_id) {
            wp_send_json_error(['message' => 'ID de sesión inválido']);
        }
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}pos_sessions WHERE id = %d",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error(['message' => 'Sesión no encontrada']);
        }
        
        if ($session->status !== 'open') {
            wp_send_json_error(['message' => 'La sesión ya está cerrada']);
        }
        
        // Calcular total esperado
        $sales_total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$prefix}pos_payments WHERE session_id = %d",
            $session_id
        ));
        
        $expected_amount = floatval($session->opening_amount) + floatval($sales_total);
        $difference = $closing_amount - $expected_amount;
        
        $wpdb->update(
            $prefix . 'pos_sessions',
            [
                'status' => 'closed',
                'closing_amount' => $closing_amount,
                'expected_amount' => $expected_amount,
                'difference' => $difference,
                'closed_at' => current_time('mysql'),
                'notes' => $notes,
            ],
            ['id' => $session_id],
            ['%s', '%f', '%f', '%f', '%s', '%s'],
            ['%d']
        );
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'session_closed',
                'pos',
                $session_id,
                null,
                [
                    'closing_amount' => $closing_amount,
                    'expected_amount' => $expected_amount,
                    'difference' => $difference,
                ]
            );
        }
        
        wp_send_json_success([
            'session_id' => $session_id,
            'sales_total' => $sales_total,
            'expected_amount' => $expected_amount,
            'closing_amount' => $closing_amount,
            'difference' => $difference,
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }
    
    /**
     * Obtener órdenes de una sesión
     */
    public function ajax_get_session_orders() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, o.post_status as order_status
            FROM {$prefix}pos_payments p
            LEFT JOIN {$wpdb->posts} o ON p.order_id = o.ID
            WHERE p.session_id = %d
            ORDER BY p.created_at DESC",
            $session_id
        ));
        
        $orders = [];
        foreach ($payments as $payment) {
            $order = wc_get_order($payment->order_id);
            if ($order) {
                $orders[] = [
                    'order_id' => $payment->order_id,
                    'order_number' => $order->get_order_number(),
                    'total' => $order->get_total(),
                    'status' => $order->get_status(),
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                ];
            }
        }
        
        wp_send_json_success(['orders' => $orders]);
    }
    
    /**
     * Anular orden
     */
    public function ajax_void_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_void_orders')) {
            wp_send_json_error(['message' => 'Sin permisos para anular órdenes']);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'ID de orden inválido']);
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada']);
        }
        
        if ($order->get_meta('_riverso_pos_sale') !== 'yes') {
            wp_send_json_error(['message' => 'Esta orden no es una venta POS']);
        }
        
        $old_status = $order->get_status();
        $order->set_status('cancelled', 'Anulada desde POS: ' . $reason);
        $order->save();
        
        // Log de auditoría
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'order_voided',
                'pos',
                $order_id,
                ['status' => $old_status],
                ['status' => 'cancelled', 'reason' => $reason]
            );
        }
        
        wp_send_json_success([
            'order_id' => $order_id,
            'message' => 'Orden anulada exitosamente'
        ]);
    }
    
    /**
     * Poner orden en espera
     */
    public function ajax_hold_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
        $cart_data = isset($_POST['cart_data']) ? stripslashes($_POST['cart_data']) : '[]';
        $total = isset($_POST['total']) ? floatval($_POST['total']) : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        
        $wpdb->insert(
            $prefix . 'pos_held_orders',
            [
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'customer_id' => $customer_id ?: null,
                'customer_name' => $customer_name,
                'cart_data' => $cart_data,
                'total' => $total,
                'notes' => $notes,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%f', '%s']
        );
        
        wp_send_json_success([
            'held_id' => $wpdb->insert_id,
            'message' => 'Orden guardada en espera'
        ]);
    }
    
    /**
     * Recuperar orden en espera
     */
    public function ajax_resume_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $held_id = isset($_POST['held_id']) ? intval($_POST['held_id']) : 0;
        
        $held_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}pos_held_orders WHERE id = %d",
            $held_id
        ));
        
        if (!$held_order) {
            wp_send_json_error(['message' => 'Orden en espera no encontrada']);
        }
        
        // Eliminar de espera
        $wpdb->delete($prefix . 'pos_held_orders', ['id' => $held_id], ['%d']);
        
        wp_send_json_success([
            'cart_data' => json_decode($held_order->cart_data, true),
            'customer_id' => $held_order->customer_id,
            'customer_name' => $held_order->customer_name,
            'notes' => $held_order->notes,
            'message' => 'Orden recuperada'
        ]);
    }
    
    /**
     * Obtener órdenes pendientes/en espera
     */
    public function ajax_get_pending_orders() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_orders')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $user_id = get_current_user_id();
        
        $held_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
            FROM {$prefix}pos_held_orders h
            LEFT JOIN {$wpdb->users} u ON h.user_id = u.ID
            WHERE h.user_id = %d OR %d = 1
            ORDER BY h.created_at DESC
            LIMIT 20",
            $user_id,
            current_user_can('riverso_manage_settings') ? 1 : 0
        ));
        
        wp_send_json_success(['held_orders' => $held_orders]);
    }
    
    /**
     * Verificar permiso de descuento
     */
    public function ajax_apply_discount() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_apply_discounts')) {
            wp_send_json_error(['message' => 'No tienes permiso para aplicar descuentos']);
        }
        
        $discount_type = isset($_POST['discount_type']) ? sanitize_text_field($_POST['discount_type']) : 'fixed';
        $discount_value = isset($_POST['discount_value']) ? floatval($_POST['discount_value']) : 0;
        $subtotal = isset($_POST['subtotal']) ? floatval($_POST['subtotal']) : 0;
        
        // Verificar límites de descuento
        $max_discount_percent = 20; // Máximo 20% por defecto
        if (current_user_can('riverso_unlimited_discounts')) {
            $max_discount_percent = 100;
        }
        
        if ($discount_type === 'percentage' && $discount_value > $max_discount_percent) {
            wp_send_json_error([
                'message' => "El descuento máximo permitido es {$max_discount_percent}%"
            ]);
        }
        
        $discount_amount = 0;
        if ($discount_type === 'percentage') {
            $discount_amount = $subtotal * ($discount_value / 100);
        } else {
            $discount_amount = min($discount_value, $subtotal);
        }
        
        wp_send_json_success([
            'discount_amount' => round($discount_amount, 0),
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
            'message' => 'Descuento aplicado'
        ]);
    }
}
