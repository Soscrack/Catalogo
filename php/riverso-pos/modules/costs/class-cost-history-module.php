<?php
/**
 * Cost History Module - Historial de Costos
 * Registra y analiza el historial de costos por producto y proveedor
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Cost_History_Module {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'riverso_cost_history';
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_riverso_get_cost_history', array($this, 'ajax_get_cost_history'));
        add_action('wp_ajax_riverso_add_cost_entry', array($this, 'ajax_add_cost_entry'));
        add_action('wp_ajax_riverso_get_product_cost_analysis', array($this, 'ajax_get_product_cost_analysis'));
        add_action('wp_ajax_riverso_get_cost_comparison', array($this, 'ajax_get_cost_comparison'));
        add_action('wp_ajax_riverso_get_margin_alerts', array($this, 'ajax_get_margin_alerts'));
        add_action('wp_ajax_riverso_get_cost_chart_data', array($this, 'ajax_get_cost_chart_data'));
        add_action('wp_ajax_riverso_bulk_import_costs', array($this, 'ajax_bulk_import_costs'));
        add_action('wp_ajax_riverso_delete_cost_entry', array($this, 'ajax_delete_cost_entry'));
    }
    
    /**
     * Create tables - called on activation
     */
    public static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'riverso_cost_history';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
            supplier_id BIGINT(20) UNSIGNED DEFAULT NULL,
            source_type VARCHAR(50) NOT NULL DEFAULT 'manual',
            source_document_id BIGINT(20) UNSIGNED DEFAULT NULL,
            source_item_id BIGINT(20) UNSIGNED DEFAULT NULL,
            supplier_code VARCHAR(100) DEFAULT NULL,
            cost DECIMAL(12,2) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'CLP',
            quantity DECIMAL(10,2) DEFAULT 1,
            unit_cost DECIMAL(12,2) GENERATED ALWAYS AS (cost / NULLIF(quantity, 0)) STORED,
            document_date DATE NOT NULL,
            notes TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product (product_id),
            KEY idx_variation (variation_id),
            KEY idx_supplier (supplier_id),
            KEY idx_source (source_type, source_document_id),
            KEY idx_document_date (document_date),
            KEY idx_supplier_code (supplier_code),
            KEY idx_cost_analysis (product_id, supplier_id, document_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
    }
    
    /**
     * Record a cost entry
     */
    public function record_cost($data) {
        global $wpdb;
        
        $defaults = array(
            'product_id' => 0,
            'variation_id' => null,
            'supplier_id' => null,
            'source_type' => 'manual',
            'source_document_id' => null,
            'source_item_id' => null,
            'supplier_code' => null,
            'cost' => 0,
            'currency' => 'CLP',
            'quantity' => 1,
            'document_date' => current_time('Y-m-d'),
            'notes' => '',
            'created_by' => get_current_user_id()
        );
        
        $data = wp_parse_args($data, $defaults);
        
        $has_product = !empty($data['product_id']);
        $has_supplier_ref = !empty($data['supplier_code']) && !empty($data['source_item_id']);

        if ((!$has_product && !$has_supplier_ref) || $data['cost'] <= 0) {
            return new WP_Error('invalid_data', 'Se requiere producto o código proveedor con ítem de factura, y un costo válido');
        }

        // Evitar duplicados por misma línea de factura.
        if (!empty($data['source_type']) && $data['source_type'] === 'invoice' && !empty($data['source_item_id'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name}
                 WHERE source_type = 'invoice' AND source_item_id = %d LIMIT 1",
                absint($data['source_item_id'])
            ));
            if ($existing) {
                $wpdb->update(
                    $this->table_name,
                    array(
                        'product_id' => $has_product ? absint($data['product_id']) : null,
                        'variation_id' => !empty($data['variation_id']) ? absint($data['variation_id']) : null,
                        'supplier_id' => $data['supplier_id'] ? absint($data['supplier_id']) : null,
                        'supplier_code' => $data['supplier_code'] ? sanitize_text_field($data['supplier_code']) : null,
                        'cost' => floatval($data['cost']),
                        'quantity' => floatval($data['quantity']),
                        'document_date' => sanitize_text_field($data['document_date']),
                        'notes' => sanitize_textarea_field($data['notes']),
                        'descripcion_proveedor' => !empty($data['descripcion_proveedor']) ? sanitize_text_field($data['descripcion_proveedor']) : null,
                        'costo_producto_unitario' => isset($data['costo_producto_unitario']) ? floatval($data['costo_producto_unitario']) : null,
                        'costo_envio_prorrateado' => isset($data['costo_envio_prorrateado']) ? floatval($data['costo_envio_prorrateado']) : null,
                        'pendiente_vinculacion' => $has_product ? 0 : 1,
                    ),
                    array('id' => (int) $existing)
                );
                return (int) $existing;
            }
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'product_id' => $has_product ? absint($data['product_id']) : null,
                'variation_id' => $data['variation_id'] ? absint($data['variation_id']) : null,
                'supplier_id' => $data['supplier_id'] ? absint($data['supplier_id']) : null,
                'source_type' => sanitize_text_field($data['source_type']),
                'source_document_id' => $data['source_document_id'] ? absint($data['source_document_id']) : null,
                'source_item_id' => $data['source_item_id'] ? absint($data['source_item_id']) : null,
                'supplier_code' => $data['supplier_code'] ? sanitize_text_field($data['supplier_code']) : null,
                'cost' => floatval($data['cost']),
                'currency' => sanitize_text_field($data['currency']),
                'quantity' => floatval($data['quantity']),
                'document_date' => sanitize_text_field($data['document_date']),
                'notes' => sanitize_textarea_field($data['notes']),
                'created_by' => absint($data['created_by']),
                'descripcion_proveedor' => !empty($data['descripcion_proveedor']) ? sanitize_text_field($data['descripcion_proveedor']) : null,
                'costo_producto_unitario' => isset($data['costo_producto_unitario']) ? floatval($data['costo_producto_unitario']) : null,
                'costo_envio_prorrateado' => isset($data['costo_envio_prorrateado']) ? floatval($data['costo_envio_prorrateado']) : null,
                'pendiente_vinculacion' => $has_product ? 0 : 1,
            ),
            array('%d', '%d', '%d', '%s', '%d', '%d', '%s', '%f', '%s', '%f', '%s', '%s', '%d', '%s', '%f', '%f', '%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Error al guardar registro de costo');
        }
        
        $entry_id = $wpdb->insert_id;
        
        // Log audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'cost_recorded',
                'cost_history',
                $entry_id,
                null,
                $data
            );
        }
        
        return $entry_id;
    }
    
    /**
     * Get cost history with filters
     */
    public function get_history($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'product_id' => null,
            'variation_id' => null,
            'supplier_id' => null,
            'source_type' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => '',
            'orderby' => 'document_date',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['product_id'])) {
            $where[] = 'ch.product_id = %d';
            $values[] = absint($args['product_id']);
        }
        
        if (!empty($args['variation_id'])) {
            $where[] = 'ch.variation_id = %d';
            $values[] = absint($args['variation_id']);
        }
        
        if (!empty($args['supplier_id'])) {
            $where[] = 'ch.supplier_id = %d';
            $values[] = absint($args['supplier_id']);
        }
        
        if (!empty($args['source_type'])) {
            $where[] = 'ch.source_type = %s';
            $values[] = sanitize_text_field($args['source_type']);
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'ch.document_date >= %s';
            $values[] = sanitize_text_field($args['date_from']);
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'ch.document_date <= %s';
            $values[] = sanitize_text_field($args['date_to']);
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(p.post_title LIKE %s OR ch.supplier_code LIKE %s OR s.nombre LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $allowed_orderby = array('document_date', 'cost', 'product_id', 'supplier_id', 'created_at');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'document_date';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
        
        $sql = "SELECT ch.*, 
                       p.post_title as product_name,
                       s.nombre as supplier_name,
                       s.rut as supplier_rut,
                       u.display_name as created_by_name,
                       pm_price.meta_value as current_price,
                       pm_sku.meta_value as product_sku
                FROM {$this->table_name} ch
                LEFT JOIN {$wpdb->posts} p ON ch.product_id = p.ID
                LEFT JOIN {$suppliers_table} s ON ch.supplier_id = s.id
                LEFT JOIN {$wpdb->users} u ON ch.created_by = u.ID
                LEFT JOIN {$wpdb->postmeta} pm_price ON ch.product_id = pm_price.post_id AND pm_price.meta_key = '_price'
                LEFT JOIN {$wpdb->postmeta} pm_sku ON ch.product_id = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                WHERE {$where_clause}
                ORDER BY ch.{$orderby} {$order}
                LIMIT %d OFFSET %d";
        
        $values[] = absint($args['limit']);
        $values[] = absint($args['offset']);
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        // Calculate margin for each entry
        foreach ($results as &$row) {
            $price = floatval($row['current_price']);
            $cost = floatval($row['unit_cost'] ?: $row['cost']);
            $row['margin'] = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : null;
            $row['margin_alert'] = $price > 0 && $price < ($cost * 1.5);
        }
        
        return $results;
    }
    
    /**
     * Get total count for pagination
     */
    public function get_history_count($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'product_id' => null,
            'supplier_id' => null,
            'source_type' => null,
            'date_from' => null,
            'date_to' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }
        
        if (!empty($args['supplier_id'])) {
            $where[] = 'supplier_id = %d';
            $values[] = absint($args['supplier_id']);
        }
        
        if (!empty($args['source_type'])) {
            $where[] = 'source_type = %s';
            $values[] = sanitize_text_field($args['source_type']);
        }
        
        if (!empty($args['date_from'])) {
            $where[] = 'document_date >= %s';
            $values[] = sanitize_text_field($args['date_from']);
        }
        
        if (!empty($args['date_to'])) {
            $where[] = 'document_date <= %s';
            $values[] = sanitize_text_field($args['date_to']);
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        
        return (int) $wpdb->get_var($sql);
    }
    
    /**
     * Get latest cost for a product
     */
    public function get_latest_cost($product_id, $supplier_id = null) {
        global $wpdb;
        
        $where = 'product_id = %d';
        $values = array(absint($product_id));
        
        if ($supplier_id) {
            $where .= ' AND supplier_id = %d';
            $values[] = absint($supplier_id);
        }
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where}
             ORDER BY document_date DESC, created_at DESC
             LIMIT 1",
            $values
        );
        
        return $wpdb->get_row($sql, ARRAY_A);
    }
    
    /**
     * Compare cost with previous entries
     */
    public function compare_cost($product_id, $new_cost, $supplier_id = null) {
        $latest = $this->get_latest_cost($product_id, $supplier_id);
        
        if (!$latest) {
            return array(
                'status' => 'first_entry',
                'previous_cost' => null,
                'difference' => null,
                'percentage' => null
            );
        }
        
        $previous_cost = floatval($latest['unit_cost'] ?: $latest['cost']);
        $difference = $new_cost - $previous_cost;
        $percentage = $previous_cost > 0 ? round(($difference / $previous_cost) * 100, 2) : 0;
        
        $status = 'unchanged';
        if ($percentage > 5) {
            $status = 'increase';
        } elseif ($percentage < -5) {
            $status = 'decrease';
        }
        
        return array(
            'status' => $status,
            'previous_cost' => $previous_cost,
            'previous_date' => $latest['document_date'],
            'difference' => $difference,
            'percentage' => $percentage
        );
    }
    
    /**
     * Get cost analysis for a product
     */
    public function get_product_analysis($product_id) {
        global $wpdb;
        
        $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
        
        // Get cost stats per supplier
        $sql = $wpdb->prepare(
            "SELECT 
                ch.supplier_id,
                s.nombre as supplier_name,
                COUNT(*) as entry_count,
                MIN(ch.cost) as min_cost,
                MAX(ch.cost) as max_cost,
                AVG(ch.cost) as avg_cost,
                MIN(ch.document_date) as first_date,
                MAX(ch.document_date) as last_date,
                (SELECT ch2.cost FROM {$this->table_name} ch2 
                 WHERE ch2.product_id = ch.product_id 
                 AND ch2.supplier_id = ch.supplier_id 
                 ORDER BY ch2.document_date DESC, ch2.created_at DESC LIMIT 1) as latest_cost
             FROM {$this->table_name} ch
             LEFT JOIN {$suppliers_table} s ON ch.supplier_id = s.id
             WHERE ch.product_id = %d
             GROUP BY ch.supplier_id
             ORDER BY latest_cost ASC",
            $product_id
        );
        
        $by_supplier = $wpdb->get_results($sql, ARRAY_A);
        
        // Get overall stats
        $overall = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_entries,
                MIN(cost) as min_cost,
                MAX(cost) as max_cost,
                AVG(cost) as avg_cost,
                MIN(document_date) as first_date,
                MAX(document_date) as last_date
             FROM {$this->table_name}
             WHERE product_id = %d",
            $product_id
        ), ARRAY_A);
        
        // Get product info
        $product = wc_get_product($product_id);
        $product_info = null;
        if ($product) {
            $product_info = array(
                'id' => $product_id,
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price()
            );
            
            // Calculate margin with latest cost
            if (!empty($by_supplier)) {
                $lowest_cost = floatval($by_supplier[0]['latest_cost']);
                $price = floatval($product->get_price());
                $product_info['lowest_current_cost'] = $lowest_cost;
                $product_info['margin'] = $price > 0 ? round((($price - $lowest_cost) / $price) * 100, 2) : null;
                $product_info['margin_alert'] = $price > 0 && $price < ($lowest_cost * 1.5);
            }
        }
        
        return array(
            'product' => $product_info,
            'overall' => $overall,
            'by_supplier' => $by_supplier
        );
    }
    
    /**
     * Get products with margin alerts
     */
    public function get_margin_alerts($threshold = 1.5, $limit = 50) {
        global $wpdb;
        
        $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
        
        // Get products where price < threshold * latest cost
        $sql = $wpdb->prepare(
            "SELECT DISTINCT
                ch.product_id,
                p.post_title as product_name,
                pm_sku.meta_value as sku,
                pm_price.meta_value as current_price,
                ch.cost as latest_cost,
                ch.supplier_id,
                s.nombre as supplier_name,
                ch.document_date as cost_date,
                ROUND(((pm_price.meta_value - ch.cost) / pm_price.meta_value) * 100, 2) as margin_percent
             FROM {$this->table_name} ch
             INNER JOIN (
                 SELECT product_id, MAX(document_date) as max_date
                 FROM {$this->table_name}
                 GROUP BY product_id
             ) latest ON ch.product_id = latest.product_id AND ch.document_date = latest.max_date
             LEFT JOIN {$wpdb->posts} p ON ch.product_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm_price ON ch.product_id = pm_price.post_id AND pm_price.meta_key = '_price'
             LEFT JOIN {$wpdb->postmeta} pm_sku ON ch.product_id = pm_sku.post_id AND pm_sku.meta_key = '_sku'
             LEFT JOIN {$suppliers_table} s ON ch.supplier_id = s.id
             WHERE pm_price.meta_value IS NOT NULL 
               AND pm_price.meta_value > 0
               AND pm_price.meta_value < (ch.cost * %f)
             ORDER BY margin_percent ASC
             LIMIT %d",
            $threshold,
            $limit
        );
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Get chart data for a product
     */
    public function get_chart_data($product_id, $months = 12) {
        global $wpdb;
        
        $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
        $date_from = date('Y-m-d', strtotime("-{$months} months"));
        
        $sql = $wpdb->prepare(
            "SELECT 
                ch.document_date,
                ch.cost,
                ch.supplier_id,
                s.nombre as supplier_name
             FROM {$this->table_name} ch
             LEFT JOIN {$suppliers_table} s ON ch.supplier_id = s.id
             WHERE ch.product_id = %d
               AND ch.document_date >= %s
             ORDER BY ch.document_date ASC",
            $product_id,
            $date_from
        );
        
        $results = $wpdb->get_results($sql, ARRAY_A);
        
        // Group by supplier for multi-line chart
        $by_supplier = array();
        foreach ($results as $row) {
            $supplier_id = $row['supplier_id'] ?: 0;
            $supplier_name = $row['supplier_name'] ?: 'Sin proveedor';
            
            if (!isset($by_supplier[$supplier_id])) {
                $by_supplier[$supplier_id] = array(
                    'supplier_id' => $supplier_id,
                    'supplier_name' => $supplier_name,
                    'data' => array()
                );
            }
            
            $by_supplier[$supplier_id]['data'][] = array(
                'date' => $row['document_date'],
                'cost' => floatval($row['cost'])
            );
        }
        
        return array_values($by_supplier);
    }
    
    // ==================== AJAX HANDLERS ====================
    
    public function ajax_get_cost_history() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $args = array(
            'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : null,
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null,
            'source_type' => isset($_POST['source_type']) ? sanitize_text_field($_POST['source_type']) : null,
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : null,
            'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : null,
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'orderby' => isset($_POST['orderby']) ? sanitize_text_field($_POST['orderby']) : 'document_date',
            'order' => isset($_POST['order']) ? sanitize_text_field($_POST['order']) : 'DESC',
            'limit' => isset($_POST['limit']) ? absint($_POST['limit']) : 50,
            'offset' => isset($_POST['offset']) ? absint($_POST['offset']) : 0
        );
        
        $history = $this->get_history($args);
        $total = $this->get_history_count($args);
        
        wp_send_json_success(array(
            'history' => $history,
            'total' => $total,
            'pages' => ceil($total / $args['limit'])
        ));
    }
    
    public function ajax_add_cost_entry() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_costs')) {
            wp_send_json_error('Sin permisos para agregar costos');
        }
        
        $data = array(
            'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : 0,
            'variation_id' => isset($_POST['variation_id']) ? absint($_POST['variation_id']) : null,
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null,
            'source_type' => isset($_POST['source_type']) ? sanitize_text_field($_POST['source_type']) : 'manual',
            'supplier_code' => isset($_POST['supplier_code']) ? sanitize_text_field($_POST['supplier_code']) : null,
            'cost' => isset($_POST['cost']) ? floatval($_POST['cost']) : 0,
            'quantity' => isset($_POST['quantity']) ? floatval($_POST['quantity']) : 1,
            'currency' => isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'CLP',
            'document_date' => isset($_POST['document_date']) ? sanitize_text_field($_POST['document_date']) : current_time('Y-m-d'),
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : ''
        );
        
        $result = $this->record_cost($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Get comparison with previous cost
        $comparison = $this->compare_cost($data['product_id'], $data['cost'] / $data['quantity'], $data['supplier_id']);
        
        wp_send_json_success(array(
            'id' => $result,
            'comparison' => $comparison,
            'message' => 'Costo registrado correctamente'
        ));
    }
    
    public function ajax_get_product_cost_analysis() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error('Producto no especificado');
        }
        
        $analysis = $this->get_product_analysis($product_id);
        
        wp_send_json_success($analysis);
    }
    
    public function ajax_get_cost_comparison() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $new_cost = isset($_POST['cost']) ? floatval($_POST['cost']) : 0;
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null;
        
        if (!$product_id) {
            wp_send_json_error('Producto no especificado');
        }
        
        $comparison = $this->compare_cost($product_id, $new_cost, $supplier_id);
        
        wp_send_json_success($comparison);
    }
    
    public function ajax_get_margin_alerts() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $threshold = isset($_POST['threshold']) ? floatval($_POST['threshold']) : 1.5;
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
        
        $alerts = $this->get_margin_alerts($threshold, $limit);
        
        wp_send_json_success(array(
            'alerts' => $alerts,
            'count' => count($alerts),
            'threshold' => $threshold
        ));
    }
    
    public function ajax_get_cost_chart_data() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $months = isset($_POST['months']) ? absint($_POST['months']) : 12;
        
        if (!$product_id) {
            wp_send_json_error('Producto no especificado');
        }
        
        $data = $this->get_chart_data($product_id, $months);
        
        wp_send_json_success($data);
    }
    
    public function ajax_bulk_import_costs() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_costs')) {
            wp_send_json_error('Sin permisos');
        }
        
        $entries = isset($_POST['entries']) ? $_POST['entries'] : array();
        
        if (empty($entries) || !is_array($entries)) {
            wp_send_json_error('No hay entradas para importar');
        }
        
        $imported = 0;
        $errors = array();
        
        foreach ($entries as $entry) {
            $result = $this->record_cost($entry);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $imported++;
            }
        }
        
        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'message' => sprintf('Se importaron %d registros de costo', $imported)
        ));
    }
    
    public function ajax_delete_cost_entry() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_costs')) {
            wp_send_json_error('Sin permisos para eliminar');
        }
        
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error('ID no especificado');
        }
        
        global $wpdb;
        
        // Get entry before deleting for audit
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
        
        if (!$entry) {
            wp_send_json_error('Registro no encontrado');
        }
        
        $result = $wpdb->delete($this->table_name, array('id' => $id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error('Error al eliminar');
        }
        
        // Log audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'cost_deleted',
                'cost_history',
                $id,
                $entry,
                null
            );
        }
        
        wp_send_json_success('Registro eliminado');
    }
    
    /**
     * Get statistics for dashboard widget
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Total entries this month
        $stats['entries_this_month'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE MONTH(document_date) = MONTH(CURRENT_DATE()) 
             AND YEAR(document_date) = YEAR(CURRENT_DATE())"
        );
        
        // Products with cost increase > 10%
        $stats['price_increases'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT product_id) FROM (
                SELECT 
                    ch1.product_id,
                    ch1.cost as current_cost,
                    (SELECT ch2.cost FROM {$this->table_name} ch2 
                     WHERE ch2.product_id = ch1.product_id 
                     AND ch2.document_date < ch1.document_date
                     ORDER BY ch2.document_date DESC LIMIT 1) as previous_cost
                FROM {$this->table_name} ch1
                WHERE ch1.document_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
            ) t
            WHERE previous_cost IS NOT NULL 
            AND ((current_cost - previous_cost) / previous_cost) > 0.1"
        );
        
        // Margin alerts count
        $stats['margin_alerts'] = count($this->get_margin_alerts(1.5, 1000));
        
        // Unique products tracked
        $stats['products_tracked'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT product_id) FROM {$this->table_name}"
        );
        
        // Unique suppliers
        $stats['suppliers_tracked'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT supplier_id) FROM {$this->table_name} WHERE supplier_id IS NOT NULL"
        );
        
        return $stats;
    }
}
