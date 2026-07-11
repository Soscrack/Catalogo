<?php
/**
 * Customer Quotes Module
 * Cotizaciones emitidas a clientes
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Quote_Module {
    
    private static $instance = null;
    private $table_quotes;
    private $table_items;
    
    // Estados de cotización
    const QUOTE_STATES = [
        'draft' => 'Borrador',
        'sent' => 'Enviada',
        'viewed' => 'Vista',
        'accepted' => 'Aceptada',
        'rejected' => 'Rechazada',
        'expired' => 'Expirada',
        'converted' => 'Convertida a Pedido'
    ];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $this->table_items = $wpdb->prefix . 'riverso_customer_quote_items';
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_riverso_get_customer_quotes', [$this, 'ajax_get_quotes']);
        add_action('wp_ajax_riverso_get_customer_quote', [$this, 'ajax_get_quote']);
        add_action('wp_ajax_riverso_create_customer_quote', [$this, 'ajax_create_quote']);
        add_action('wp_ajax_riverso_update_customer_quote', [$this, 'ajax_update_quote']);
        add_action('wp_ajax_riverso_delete_customer_quote', [$this, 'ajax_delete_quote']);
        add_action('wp_ajax_riverso_add_quote_item', [$this, 'ajax_add_item']);
        add_action('wp_ajax_riverso_update_quote_item', [$this, 'ajax_update_item']);
        add_action('wp_ajax_riverso_remove_quote_item', [$this, 'ajax_remove_item']);
        add_action('wp_ajax_riverso_send_customer_quote', [$this, 'ajax_send_quote']);
        add_action('wp_ajax_riverso_convert_quote_to_order', [$this, 'ajax_convert_to_order']);
        add_action('wp_ajax_riverso_duplicate_quote', [$this, 'ajax_duplicate_quote']);
        add_action('wp_ajax_riverso_get_quote_stats', [$this, 'ajax_get_stats']);
    }
    
    /**
     * Create tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $sql1 = "CREATE TABLE IF NOT EXISTS {$table_quotes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quote_number VARCHAR(50) NOT NULL,
            customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_email VARCHAR(255) DEFAULT NULL,
            customer_phone VARCHAR(50) DEFAULT NULL,
            customer_rut VARCHAR(20) DEFAULT NULL,
            customer_address TEXT,
            status VARCHAR(20) DEFAULT 'draft',
            subtotal DECIMAL(12,2) DEFAULT 0,
            discount_type ENUM('percent','fixed') DEFAULT 'percent',
            discount_value DECIMAL(12,2) DEFAULT 0,
            discount_total DECIMAL(12,2) DEFAULT 0,
            tax_total DECIMAL(12,2) DEFAULT 0,
            total DECIMAL(12,2) DEFAULT 0,
            currency VARCHAR(3) DEFAULT 'CLP',
            valid_days INT DEFAULT 3,
            valid_until DATE DEFAULT NULL,
            notes TEXT,
            internal_notes TEXT,
            sent_at DATETIME DEFAULT NULL,
            sent_by BIGINT(20) UNSIGNED DEFAULT NULL,
            viewed_at DATETIME DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            rejection_reason TEXT,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_quote_number (quote_number),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_created_by (created_by),
            KEY idx_valid_until (valid_until)
        ) $charset_collate;";
        
        $table_items = $wpdb->prefix . 'riverso_customer_quote_items';
        $sql2 = "CREATE TABLE IF NOT EXISTS {$table_items} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quote_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED DEFAULT NULL,
            variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
            sku VARCHAR(100) DEFAULT NULL,
            name VARCHAR(500) NOT NULL,
            description TEXT,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL,
            discount_percent DECIMAL(5,2) DEFAULT 0,
            discount_amount DECIMAL(12,2) DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL,
            tax_percent DECIMAL(5,2) DEFAULT 19,
            tax_amount DECIMAL(12,2) DEFAULT 0,
            total DECIMAL(12,2) NOT NULL,
            sort_order INT DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_quote (quote_id),
            KEY idx_product (product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        
        return true;
    }
    
    /**
     * Generate unique quote number
     */
    private function generate_quote_number() {
        global $wpdb;
        
        $prefix = 'COT-' . date('Ym') . '-';
        
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT quote_number FROM {$this->table_quotes} 
             WHERE quote_number LIKE %s 
             ORDER BY id DESC LIMIT 1",
            $prefix . '%'
        ));
        
        if ($last) {
            $num = intval(substr($last, strlen($prefix))) + 1;
        } else {
            $num = 1;
        }
        
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create quote
     */
    public function create_quote($data) {
        global $wpdb;
        
        $quote_number = $this->generate_quote_number();
        $valid_days = intval($data['valid_days'] ?? 3);
        $valid_until = date('Y-m-d', strtotime("+{$valid_days} days"));
        
        $result = $wpdb->insert(
            $this->table_quotes,
            [
                'quote_number' => $quote_number,
                'customer_id' => absint($data['customer_id'] ?? 0) ?: null,
                'customer_name' => sanitize_text_field($data['customer_name'] ?? ''),
                'customer_email' => sanitize_email($data['customer_email'] ?? ''),
                'customer_phone' => sanitize_text_field($data['customer_phone'] ?? ''),
                'customer_rut' => sanitize_text_field($data['customer_rut'] ?? ''),
                'customer_address' => sanitize_textarea_field($data['customer_address'] ?? ''),
                'status' => 'draft',
                'valid_days' => $valid_days,
                'valid_until' => $valid_until,
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'internal_notes' => sanitize_textarea_field($data['internal_notes'] ?? ''),
                'created_by' => get_current_user_id()
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );
        
        if (!$result) {
            return new WP_Error('db_error', 'Error creando cotización');
        }
        
        $quote_id = $wpdb->insert_id;
        
        // Audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'customer_quote_created',
                'customer_quote',
                $quote_id,
                null,
                ['quote_number' => $quote_number, 'customer' => $data['customer_name'] ?? '']
            );
        }
        
        return $quote_id;
    }
    
    /**
     * Add item to quote
     */
    public function add_item($quote_id, $data) {
        global $wpdb;
        
        $unit_price = floatval($data['unit_price'] ?? 0);
        $quantity = intval($data['quantity'] ?? 1);
        $discount_percent = floatval($data['discount_percent'] ?? 0);
        $tax_percent = floatval($data['tax_percent'] ?? 19);
        
        // Calculate totals
        $subtotal = $unit_price * $quantity;
        $discount_amount = $subtotal * ($discount_percent / 100);
        $after_discount = $subtotal - $discount_amount;
        $tax_amount = $after_discount * ($tax_percent / 100);
        $total = $after_discount + $tax_amount;
        
        // Get max sort order
        $max_order = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$this->table_items} WHERE quote_id = %d",
            $quote_id
        )) ?: 0;
        
        $result = $wpdb->insert(
            $this->table_items,
            [
                'quote_id' => $quote_id,
                'product_id' => absint($data['product_id'] ?? 0) ?: null,
                'variation_id' => absint($data['variation_id'] ?? 0) ?: null,
                'sku' => sanitize_text_field($data['sku'] ?? ''),
                'name' => sanitize_text_field($data['name'] ?? ''),
                'description' => sanitize_textarea_field($data['description'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'discount_percent' => $discount_percent,
                'discount_amount' => $discount_amount,
                'subtotal' => $subtotal,
                'tax_percent' => $tax_percent,
                'tax_amount' => $tax_amount,
                'total' => $total,
                'sort_order' => $max_order + 1
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%d']
        );
        
        if ($result) {
            $this->recalculate_totals($quote_id);
            return $wpdb->insert_id;
        }
        
        return new WP_Error('db_error', 'Error agregando producto');
    }
    
    /**
     * Recalculate quote totals
     */
    public function recalculate_totals($quote_id) {
        global $wpdb;
        
        // Get current quote for discount settings
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_quotes} WHERE id = %d",
            $quote_id
        ));
        
        if (!$quote) return false;
        
        // Sum items
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(subtotal) as subtotal,
                SUM(discount_amount) as item_discounts,
                SUM(tax_amount) as tax_total,
                SUM(total) as items_total
             FROM {$this->table_items} 
             WHERE quote_id = %d",
            $quote_id
        ));
        
        $subtotal = floatval($totals->subtotal ?? 0);
        
        // Apply quote-level discount
        $discount_total = 0;
        if ($quote->discount_type === 'percent') {
            $discount_total = $subtotal * (floatval($quote->discount_value) / 100);
        } else {
            $discount_total = floatval($quote->discount_value);
        }
        
        $total = floatval($totals->items_total ?? 0) - $discount_total;
        
        $wpdb->update(
            $this->table_quotes,
            [
                'subtotal' => $subtotal,
                'discount_total' => $discount_total,
                'tax_total' => floatval($totals->tax_total ?? 0),
                'total' => max(0, $total)
            ],
            ['id' => $quote_id],
            ['%f', '%f', '%f', '%f'],
            ['%d']
        );
        
        return true;
    }
    
    /**
     * Convert quote to WooCommerce order
     */
    public function convert_to_order($quote_id) {
        global $wpdb;
        
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_quotes} WHERE id = %d",
            $quote_id
        ), ARRAY_A);
        
        if (!$quote) {
            return new WP_Error('not_found', 'Cotización no encontrada');
        }
        
        if ($quote['status'] === 'converted') {
            return new WP_Error('already_converted', 'Esta cotización ya fue convertida');
        }
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE quote_id = %d ORDER BY sort_order",
            $quote_id
        ), ARRAY_A);
        
        if (empty($items)) {
            return new WP_Error('no_items', 'La cotización no tiene productos');
        }
        
        // Create WooCommerce order
        $order = wc_create_order([
            'customer_id' => $quote['customer_id'] ?: 0,
            'status' => 'pending'
        ]);
        
        if (is_wp_error($order)) {
            return $order;
        }
        
        // Add items
        foreach ($items as $item) {
            if ($item['product_id']) {
                $product = wc_get_product($item['variation_id'] ?: $item['product_id']);
                if ($product) {
                    $order->add_product(
                        $product,
                        $item['quantity'],
                        [
                            'subtotal' => $item['subtotal'],
                            'total' => $item['total'] - $item['tax_amount']
                        ]
                    );
                } else {
                    // Product no longer exists, add as fee
                    $order->add_fee($item['name'], $item['total'] - $item['tax_amount']);
                }
            } else {
                // Custom item
                $order->add_fee($item['name'], $item['total'] - $item['tax_amount']);
            }
        }
        
        // Set customer info
        $order->set_billing_first_name($quote['customer_name']);
        $order->set_billing_email($quote['customer_email']);
        $order->set_billing_phone($quote['customer_phone']);
        $order->set_billing_address_1($quote['customer_address']);
        
        // Add quote reference
        $order->add_order_note(sprintf(
            'Pedido creado desde cotización %s',
            $quote['quote_number']
        ));
        
        $order->calculate_totals();
        $order->save();
        
        $order_id = $order->get_id();
        
        // Update quote status
        $wpdb->update(
            $this->table_quotes,
            [
                'status' => 'converted',
                'order_id' => $order_id
            ],
            ['id' => $quote_id],
            ['%s', '%d'],
            ['%d']
        );
        
        // Audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'quote_converted_to_order',
                'customer_quote',
                $quote_id,
                ['status' => $quote['status']],
                ['status' => 'converted', 'order_id' => $order_id]
            );
        }
        
        return $order_id;
    }
    
    /**
     * AJAX: Get quotes
     */
    public function ajax_get_quotes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $status = sanitize_text_field($_POST['status'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $page = absint($_POST['page'] ?? 1);
        $per_page = absint($_POST['per_page'] ?? 20);
        $offset = ($page - 1) * $per_page;
        
        $where = '1=1';
        $params = [];
        
        if ($status) {
            $where .= ' AND q.status = %s';
            $params[] = $status;
        }
        
        if ($search) {
            $where .= ' AND (q.quote_number LIKE %s OR q.customer_name LIKE %s OR q.customer_email LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $sql = "SELECT q.*, u.display_name as created_by_name
                FROM {$this->table_quotes} q
                LEFT JOIN {$wpdb->users} u ON q.created_by = u.ID
                WHERE {$where}
                ORDER BY q.created_at DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $quotes = $wpdb->get_results(
            $wpdb->prepare($sql, $params),
            ARRAY_A
        );
        
        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$this->table_quotes} q WHERE {$where}";
        $total = $wpdb->get_var(
            count($params) > 2 ? $wpdb->prepare($count_sql, array_slice($params, 0, -2)) : $count_sql
        );
        
        wp_send_json_success([
            'quotes' => $quotes,
            'total' => intval($total),
            'page' => $page,
            'pages' => ceil($total / $per_page)
        ]);
    }
    
    /**
     * AJAX: Get single quote with items
     */
    public function ajax_get_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        if (!$quote_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, u.display_name as created_by_name
             FROM {$this->table_quotes} q
             LEFT JOIN {$wpdb->users} u ON q.created_by = u.ID
             WHERE q.id = %d",
            $quote_id
        ), ARRAY_A);
        
        if (!$quote) {
            wp_send_json_error(['message' => 'Cotización no encontrada']);
        }
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE quote_id = %d ORDER BY sort_order",
            $quote_id
        ), ARRAY_A);
        
        $quote['items'] = $items;
        
        wp_send_json_success($quote);
    }
    
    /**
     * AJAX: Create quote
     */
    public function ajax_create_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $data = [
            'customer_id' => $_POST['customer_id'] ?? 0,
            'customer_name' => $_POST['customer_name'] ?? '',
            'customer_email' => $_POST['customer_email'] ?? '',
            'customer_phone' => $_POST['customer_phone'] ?? '',
            'customer_rut' => $_POST['customer_rut'] ?? '',
            'customer_address' => $_POST['customer_address'] ?? '',
            'valid_days' => $_POST['valid_days'] ?? 3,
            'notes' => $_POST['notes'] ?? '',
            'internal_notes' => $_POST['internal_notes'] ?? ''
        ];
        
        if (empty($data['customer_name'])) {
            wp_send_json_error(['message' => 'Nombre de cliente requerido']);
        }
        
        $quote_id = $this->create_quote($data);
        
        if (is_wp_error($quote_id)) {
            wp_send_json_error(['message' => $quote_id->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Cotización creada',
            'quote_id' => $quote_id
        ]);
    }
    
    /**
     * AJAX: Update quote
     */
    public function ajax_update_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        if (!$quote_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $allowed = [
            'customer_name', 'customer_email', 'customer_phone', 
            'customer_rut', 'customer_address', 'valid_days',
            'notes', 'internal_notes', 'discount_type', 'discount_value'
        ];
        
        $update = [];
        $formats = [];
        
        foreach ($allowed as $field) {
            if (isset($_POST[$field])) {
                $update[$field] = sanitize_text_field($_POST[$field]);
                $formats[] = '%s';
            }
        }
        
        if (isset($_POST['valid_days'])) {
            $valid_days = intval($_POST['valid_days']);
            $update['valid_days'] = $valid_days;
            $update['valid_until'] = date('Y-m-d', strtotime("+{$valid_days} days"));
        }
        
        if (empty($update)) {
            wp_send_json_error(['message' => 'Sin datos para actualizar']);
        }
        
        $wpdb->update(
            $this->table_quotes,
            $update,
            ['id' => $quote_id],
            $formats,
            ['%d']
        );
        
        $this->recalculate_totals($quote_id);
        
        wp_send_json_success(['message' => 'Cotización actualizada']);
    }
    
    /**
     * AJAX: Add item to quote
     */
    public function ajax_add_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        if (!$quote_id) {
            wp_send_json_error(['message' => 'ID de cotización requerido']);
        }
        
        $data = [
            'product_id' => $_POST['product_id'] ?? 0,
            'variation_id' => $_POST['variation_id'] ?? 0,
            'sku' => $_POST['sku'] ?? '',
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'quantity' => $_POST['quantity'] ?? 1,
            'unit_price' => $_POST['unit_price'] ?? 0,
            'discount_percent' => $_POST['discount_percent'] ?? 0,
            'tax_percent' => $_POST['tax_percent'] ?? 19
        ];
        
        if (empty($data['name'])) {
            wp_send_json_error(['message' => 'Nombre de producto requerido']);
        }
        
        $item_id = $this->add_item($quote_id, $data);
        
        if (is_wp_error($item_id)) {
            wp_send_json_error(['message' => $item_id->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Producto agregado',
            'item_id' => $item_id
        ]);
    }
    
    /**
     * AJAX: Update item
     */
    public function ajax_update_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $item_id = absint($_POST['item_id'] ?? 0);
        
        if (!$item_id) {
            wp_send_json_error(['message' => 'ID de item requerido']);
        }
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE id = %d",
            $item_id
        ));
        
        if (!$item) {
            wp_send_json_error(['message' => 'Item no encontrado']);
        }
        
        $quantity = intval($_POST['quantity'] ?? $item->quantity);
        $unit_price = floatval($_POST['unit_price'] ?? $item->unit_price);
        $discount_percent = floatval($_POST['discount_percent'] ?? $item->discount_percent);
        $tax_percent = floatval($_POST['tax_percent'] ?? $item->tax_percent);
        
        // Recalculate
        $subtotal = $unit_price * $quantity;
        $discount_amount = $subtotal * ($discount_percent / 100);
        $after_discount = $subtotal - $discount_amount;
        $tax_amount = $after_discount * ($tax_percent / 100);
        $total = $after_discount + $tax_amount;
        
        $wpdb->update(
            $this->table_items,
            [
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'discount_percent' => $discount_percent,
                'discount_amount' => $discount_amount,
                'subtotal' => $subtotal,
                'tax_percent' => $tax_percent,
                'tax_amount' => $tax_amount,
                'total' => $total
            ],
            ['id' => $item_id],
            ['%d', '%f', '%f', '%f', '%f', '%f', '%f', '%f'],
            ['%d']
        );
        
        $this->recalculate_totals($item->quote_id);
        
        wp_send_json_success(['message' => 'Item actualizado']);
    }
    
    /**
     * AJAX: Remove item
     */
    public function ajax_remove_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $item_id = absint($_POST['item_id'] ?? 0);
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT quote_id FROM {$this->table_items} WHERE id = %d",
            $item_id
        ));
        
        if (!$item) {
            wp_send_json_error(['message' => 'Item no encontrado']);
        }
        
        $wpdb->delete($this->table_items, ['id' => $item_id], ['%d']);
        
        $this->recalculate_totals($item->quote_id);
        
        wp_send_json_success(['message' => 'Item eliminado']);
    }
    
    /**
     * AJAX: Send quote
     */
    public function ajax_send_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_send_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        $method = sanitize_text_field($_POST['method'] ?? 'email');
        
        if (!$quote_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_quotes} WHERE id = %d",
            $quote_id
        ), ARRAY_A);
        
        if (!$quote) {
            wp_send_json_error(['message' => 'Cotización no encontrada']);
        }
        
        if ($method === 'email' && !empty($quote['customer_email'])) {
            // Send email
            $subject = sprintf('Cotización %s - Riverso', $quote['quote_number']);
            $message = $this->generate_quote_email($quote);
            
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            
            $sent = wp_mail($quote['customer_email'], $subject, $message, $headers);
            
            if (!$sent) {
                wp_send_json_error(['message' => 'Error enviando email']);
            }
        }
        
        // Update status
        $wpdb->update(
            $this->table_quotes,
            [
                'status' => 'sent',
                'sent_at' => current_time('mysql'),
                'sent_by' => get_current_user_id()
            ],
            ['id' => $quote_id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        
        // Audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'quote_sent',
                'customer_quote',
                $quote_id,
                ['status' => $quote['status']],
                ['status' => 'sent', 'method' => $method]
            );
        }
        
        wp_send_json_success(['message' => 'Cotización enviada']);
    }
    
    /**
     * Generate email HTML
     */
    private function generate_quote_email($quote) {
        global $wpdb;
        
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE quote_id = %d ORDER BY sort_order",
            $quote['id']
        ), ARRAY_A);
        
        $items_html = '';
        foreach ($items as $item) {
            $items_html .= sprintf(
                '<tr><td>%s</td><td style="text-align:center">%d</td><td style="text-align:right">$%s</td><td style="text-align:right">$%s</td></tr>',
                esc_html($item['name']),
                $item['quantity'],
                number_format($item['unit_price'], 0, ',', '.'),
                number_format($item['total'], 0, ',', '.')
            );
        }
        
        return sprintf('
            <html>
            <body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                <h2>Cotización %s</h2>
                <p>Estimado/a %s,</p>
                <p>Adjunto encontrará nuestra cotización solicitada.</p>
                
                <table style="width: 100%%; border-collapse: collapse; margin: 20px 0;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Producto</th>
                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #ddd;">Cant.</th>
                            <th style="padding: 10px; text-align: right; border-bottom: 2px solid #ddd;">Precio</th>
                            <th style="padding: 10px; text-align: right; border-bottom: 2px solid #ddd;">Total</th>
                        </tr>
                    </thead>
                    <tbody>%s</tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="text-align: right; padding: 10px; font-weight: bold;">Total:</td>
                            <td style="text-align: right; padding: 10px; font-weight: bold; font-size: 18px;">$%s</td>
                        </tr>
                    </tfoot>
                </table>
                
                <p><strong>Vigencia:</strong> %d días (hasta %s)</p>
                %s
                
                <p style="margin-top: 30px;">Saludos cordiales,<br>Equipo Riverso</p>
            </body>
            </html>',
            esc_html($quote['quote_number']),
            esc_html($quote['customer_name']),
            $items_html,
            number_format($quote['total'], 0, ',', '.'),
            $quote['valid_days'],
            date('d/m/Y', strtotime($quote['valid_until'])),
            $quote['notes'] ? '<p><strong>Notas:</strong> ' . esc_html($quote['notes']) . '</p>' : ''
        );
    }
    
    /**
     * AJAX: Convert to order
     */
    public function ajax_convert_to_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_convert_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos para convertir']);
        }
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        if (!$quote_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $order_id = $this->convert_to_order($quote_id);
        
        if (is_wp_error($order_id)) {
            wp_send_json_error(['message' => $order_id->get_error_message()]);
        }
        
        wp_send_json_success([
            'message' => 'Cotización convertida a pedido',
            'order_id' => $order_id,
            'order_url' => admin_url('post.php?post=' . $order_id . '&action=edit')
        ]);
    }
    
    /**
     * AJAX: Duplicate quote
     */
    public function ajax_duplicate_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_create_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_quotes} WHERE id = %d",
            $quote_id
        ), ARRAY_A);
        
        if (!$quote) {
            wp_send_json_error(['message' => 'Cotización no encontrada']);
        }
        
        // Create new quote
        $new_id = $this->create_quote([
            'customer_id' => $quote['customer_id'],
            'customer_name' => $quote['customer_name'],
            'customer_email' => $quote['customer_email'],
            'customer_phone' => $quote['customer_phone'],
            'customer_rut' => $quote['customer_rut'],
            'customer_address' => $quote['customer_address'],
            'valid_days' => $quote['valid_days'],
            'notes' => $quote['notes'],
            'internal_notes' => $quote['internal_notes']
        ]);
        
        if (is_wp_error($new_id)) {
            wp_send_json_error(['message' => $new_id->get_error_message()]);
        }
        
        // Copy items
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE quote_id = %d",
            $quote_id
        ), ARRAY_A);
        
        foreach ($items as $item) {
            $this->add_item($new_id, $item);
        }
        
        wp_send_json_success([
            'message' => 'Cotización duplicada',
            'quote_id' => $new_id
        ]);
    }
    
    /**
     * AJAX: Delete quote
     */
    public function ajax_delete_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_delete_quotes')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $quote_id = absint($_POST['quote_id'] ?? 0);
        
        // Delete items first
        $wpdb->delete($this->table_items, ['quote_id' => $quote_id], ['%d']);
        
        // Delete quote
        $wpdb->delete($this->table_quotes, ['id' => $quote_id], ['%d']);
        
        wp_send_json_success(['message' => 'Cotización eliminada']);
    }
    
    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        global $wpdb;
        
        $stats = [];
        
        $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quotes}");
        $stats['draft'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quotes} WHERE status = 'draft'");
        $stats['sent'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quotes} WHERE status = 'sent'");
        $stats['accepted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quotes} WHERE status = 'accepted'");
        $stats['converted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quotes} WHERE status = 'converted'");
        
        $stats['total_value'] = (float) $wpdb->get_var("SELECT SUM(total) FROM {$this->table_quotes}") ?: 0;
        $stats['this_month'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_quotes} 
             WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) 
             AND YEAR(created_at) = YEAR(CURRENT_DATE())"
        );
        
        wp_send_json_success($stats);
    }
}
