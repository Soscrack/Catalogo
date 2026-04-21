<?php
/**
 * Supplier Product Links Module
 * Gestiona la relación entre códigos de proveedor y productos internos
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Supplier_Links_Module {
    
    private static $instance = null;
    private $table_links;
    private $table_codes;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_links = $wpdb->prefix . 'riverso_supplier_product_links';
        $this->table_codes = $wpdb->prefix . 'riverso_codigos';
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_riverso_get_supplier_links', array($this, 'ajax_get_links'));
        add_action('wp_ajax_riverso_create_supplier_link', array($this, 'ajax_create_link'));
        add_action('wp_ajax_riverso_update_supplier_link', array($this, 'ajax_update_link'));
        add_action('wp_ajax_riverso_delete_supplier_link', array($this, 'ajax_delete_link'));
        add_action('wp_ajax_riverso_search_product_for_link', array($this, 'ajax_search_product'));
        add_action('wp_ajax_riverso_lookup_by_code', array($this, 'ajax_lookup_by_code'));
        add_action('wp_ajax_riverso_bulk_import_links', array($this, 'ajax_bulk_import'));
        add_action('wp_ajax_riverso_get_unlinked_codes', array($this, 'ajax_get_unlinked'));
    }
    
    /**
     * Create tables on activation
     */
    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_supplier_product_links';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_id BIGINT(20) UNSIGNED NOT NULL,
            supplier_code VARCHAR(100) DEFAULT NULL,
            supplier_barcode VARCHAR(50) DEFAULT NULL,
            supplier_description TEXT,
            product_id BIGINT(20) UNSIGNED DEFAULT NULL,
            variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
            internal_sku VARCHAR(100) DEFAULT NULL,
            is_primary TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            match_confidence INT DEFAULT NULL,
            notes TEXT,
            created_by BIGINT(20) UNSIGNED NOT NULL,
            updated_by BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_supplier_code (supplier_id, supplier_code),
            KEY idx_supplier (supplier_id),
            KEY idx_product (product_id),
            KEY idx_variation (variation_id),
            KEY idx_barcode (supplier_barcode),
            KEY idx_sku (internal_sku)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
    }
    
    /**
     * Find product by any code type
     */
    public function lookup_by_code($code, $supplier_id = null) {
        global $wpdb;
        
        $code = trim($code);
        if (empty($code)) {
            return null;
        }
        
        // First, check in our links table
        $where = "(supplier_code = %s OR supplier_barcode = %s OR internal_sku = %s)";
        $params = array($code, $code, $code);
        
        if ($supplier_id) {
            $where .= " AND supplier_id = %d";
            $params[] = absint($supplier_id);
        }
        
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT l.*, s.nombre as supplier_name 
             FROM {$this->table_links} l
             LEFT JOIN {$wpdb->prefix}riverso_proveedores s ON l.supplier_id = s.id
             WHERE {$where} AND l.is_active = 1
             ORDER BY l.is_primary DESC
             LIMIT 1",
            $params
        ), ARRAY_A);
        
        if ($link && $link['product_id']) {
            $product = wc_get_product($link['product_id']);
            if ($product) {
                return array(
                    'found' => true,
                    'source' => 'supplier_link',
                    'link' => $link,
                    'product' => array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'stock' => $product->get_stock_quantity(),
                        'type' => $product->get_type()
                    )
                );
            }
        }
        
        // Check in legacy codigos table
        $legacy = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_codes} 
             WHERE (codigo_proveedor = %s OR sku_local = %s) AND activo = 1
             LIMIT 1",
            $code, $code
        ), ARRAY_A);
        
        if ($legacy && $legacy['product_id']) {
            $product = wc_get_product($legacy['product_id']);
            if ($product) {
                return array(
                    'found' => true,
                    'source' => 'legacy_code',
                    'legacy' => $legacy,
                    'product' => array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'stock' => $product->get_stock_quantity(),
                        'type' => $product->get_type()
                    )
                );
            }
        }
        
        // Check directly in WooCommerce by SKU
        $product_id = wc_get_product_id_by_sku($code);
        if ($product_id) {
            $product = wc_get_product($product_id);
            return array(
                'found' => true,
                'source' => 'woocommerce_sku',
                'product' => array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'price' => $product->get_price(),
                    'stock' => $product->get_stock_quantity(),
                    'type' => $product->get_type()
                )
            );
        }
        
        // Check by barcode in product meta
        $by_barcode = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key IN ('_barcode', '_ean', '_upc', 'barcode') AND meta_value = %s
             LIMIT 1",
            $code
        ));
        
        if ($by_barcode) {
            $product = wc_get_product($by_barcode);
            if ($product) {
                return array(
                    'found' => true,
                    'source' => 'product_barcode',
                    'product' => array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'price' => $product->get_price(),
                        'stock' => $product->get_stock_quantity(),
                        'type' => $product->get_type()
                    )
                );
            }
        }
        
        return array('found' => false, 'code' => $code);
    }
    
    /**
     * Create a new supplier-product link
     */
    public function create_link($data) {
        global $wpdb;
        
        $required = array('supplier_id');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Campo requerido: {$field}");
            }
        }
        
        // Check for duplicate
        if (!empty($data['supplier_code'])) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_links} 
                 WHERE supplier_id = %d AND supplier_code = %s",
                $data['supplier_id'], $data['supplier_code']
            ));
            
            if ($exists) {
                return new WP_Error('duplicate', 'Este código de proveedor ya existe para este proveedor');
            }
        }
        
        // Get internal SKU if product provided
        $internal_sku = null;
        if (!empty($data['product_id'])) {
            $product = wc_get_product($data['product_id']);
            if ($product) {
                $internal_sku = $product->get_sku();
            }
        }
        
        $result = $wpdb->insert(
            $this->table_links,
            array(
                'supplier_id' => absint($data['supplier_id']),
                'supplier_code' => sanitize_text_field($data['supplier_code'] ?? ''),
                'supplier_barcode' => sanitize_text_field($data['supplier_barcode'] ?? ''),
                'supplier_description' => sanitize_textarea_field($data['supplier_description'] ?? ''),
                'product_id' => !empty($data['product_id']) ? absint($data['product_id']) : null,
                'variation_id' => !empty($data['variation_id']) ? absint($data['variation_id']) : null,
                'internal_sku' => $internal_sku ?: ($data['internal_sku'] ?? null),
                'is_primary' => !empty($data['is_primary']) ? 1 : 0,
                'is_active' => 1,
                'match_confidence' => isset($data['match_confidence']) ? intval($data['match_confidence']) : null,
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'created_by' => get_current_user_id()
            ),
            array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Error al crear el link');
        }
        
        $link_id = $wpdb->insert_id;
        
        // Log audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'link_created',
                'supplier_link',
                $link_id,
                null,
                $data
            );
        }
        
        return $link_id;
    }
    
    /**
     * Update an existing link
     */
    public function update_link($id, $data) {
        global $wpdb;
        
        $id = absint($id);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_links} WHERE id = %d", $id
        ), ARRAY_A);
        
        if (!$existing) {
            return new WP_Error('not_found', 'Link no encontrado');
        }
        
        $update_data = array();
        $update_format = array();
        
        $allowed_fields = array(
            'supplier_code' => '%s',
            'supplier_barcode' => '%s',
            'supplier_description' => '%s',
            'product_id' => '%d',
            'variation_id' => '%d',
            'internal_sku' => '%s',
            'is_primary' => '%d',
            'is_active' => '%d',
            'match_confidence' => '%d',
            'notes' => '%s'
        );
        
        foreach ($allowed_fields as $field => $format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $update_format[] = $format;
            }
        }
        
        if (empty($update_data)) {
            return new WP_Error('no_data', 'No hay datos para actualizar');
        }
        
        $update_data['updated_by'] = get_current_user_id();
        $update_format[] = '%d';
        
        $result = $wpdb->update(
            $this->table_links,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Error al actualizar');
        }
        
        // Log audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'link_updated',
                'supplier_link',
                $id,
                $existing,
                $data
            );
        }
        
        return true;
    }
    
    /**
     * Get links with filters
     */
    public function get_links($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'supplier_id' => null,
            'product_id' => null,
            'is_active' => null,
            'unlinked_only' => false,
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['supplier_id'])) {
            $where[] = 'l.supplier_id = %d';
            $values[] = absint($args['supplier_id']);
        }
        
        if (!empty($args['product_id'])) {
            $where[] = 'l.product_id = %d';
            $values[] = absint($args['product_id']);
        }
        
        if ($args['is_active'] !== null) {
            $where[] = 'l.is_active = %d';
            $values[] = $args['is_active'] ? 1 : 0;
        }
        
        if ($args['unlinked_only']) {
            $where[] = 'l.product_id IS NULL';
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(l.supplier_code LIKE %s OR l.supplier_barcode LIKE %s OR l.supplier_description LIKE %s OR l.internal_sku LIKE %s OR p.post_title LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
        
        $sql = "SELECT l.*, 
                       s.nombre as supplier_name,
                       p.post_title as product_name,
                       pm_sku.meta_value as product_sku
                FROM {$this->table_links} l
                LEFT JOIN {$suppliers_table} s ON l.supplier_id = s.id
                LEFT JOIN {$wpdb->posts} p ON l.product_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_sku ON l.product_id = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                WHERE {$where_clause}
                ORDER BY l.{$args['orderby']} {$args['order']}
                LIMIT %d OFFSET %d";
        
        $values[] = absint($args['limit']);
        $values[] = absint($args['offset']);
        
        return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
    }
    
    /**
     * Count links with filters
     */
    public function count_links($args = array()) {
        global $wpdb;
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['supplier_id'])) {
            $where[] = 'supplier_id = %d';
            $values[] = absint($args['supplier_id']);
        }
        
        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = absint($args['product_id']);
        }
        
        if (isset($args['is_active'])) {
            $where[] = 'is_active = %d';
            $values[] = $args['is_active'] ? 1 : 0;
        }
        
        if (!empty($args['unlinked_only'])) {
            $where[] = 'product_id IS NULL';
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT COUNT(*) FROM {$this->table_links} WHERE {$where_clause}";
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        
        return (int) $wpdb->get_var($sql);
    }
    
    // ==================== AJAX HANDLERS ====================
    
    public function ajax_get_links() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $args = array(
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null,
            'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : null,
            'is_active' => isset($_POST['is_active']) ? (bool) $_POST['is_active'] : null,
            'unlinked_only' => !empty($_POST['unlinked_only']),
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'limit' => isset($_POST['limit']) ? absint($_POST['limit']) : 50,
            'offset' => isset($_POST['offset']) ? absint($_POST['offset']) : 0
        );
        
        $links = $this->get_links($args);
        $total = $this->count_links($args);
        
        wp_send_json_success(array(
            'links' => $links,
            'total' => $total,
            'pages' => ceil($total / $args['limit'])
        ));
    }
    
    public function ajax_create_link() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $data = array(
            'supplier_id' => isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : 0,
            'supplier_code' => isset($_POST['supplier_code']) ? sanitize_text_field($_POST['supplier_code']) : '',
            'supplier_barcode' => isset($_POST['supplier_barcode']) ? sanitize_text_field($_POST['supplier_barcode']) : '',
            'supplier_description' => isset($_POST['supplier_description']) ? sanitize_textarea_field($_POST['supplier_description']) : '',
            'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : null,
            'variation_id' => isset($_POST['variation_id']) ? absint($_POST['variation_id']) : null,
            'is_primary' => !empty($_POST['is_primary']),
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : ''
        );
        
        $result = $this->create_link($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'id' => $result,
            'message' => 'Link creado correctamente'
        ));
    }
    
    public function ajax_update_link() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error('ID requerido');
        }
        
        $data = array();
        $fields = array('supplier_code', 'supplier_barcode', 'supplier_description', 
                       'product_id', 'variation_id', 'internal_sku', 'is_primary', 
                       'is_active', 'notes');
        
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = $_POST[$field];
            }
        }
        
        $result = $this->update_link($id, $data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Link actualizado');
    }
    
    public function ajax_delete_link() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error('ID requerido');
        }
        
        global $wpdb;
        
        // Soft delete
        $result = $wpdb->update(
            $this->table_links,
            array('is_active' => 0, 'updated_by' => get_current_user_id()),
            array('id' => $id),
            array('%d', '%d'),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error('Error al eliminar');
        }
        
        wp_send_json_success('Link desactivado');
    }
    
    public function ajax_search_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        
        if (strlen($term) < 2) {
            wp_send_json_success(array());
        }
        
        global $wpdb;
        
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title as name, pm.meta_value as sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'publish'
               AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)
             LIMIT 20",
            '%' . $wpdb->esc_like($term) . '%',
            '%' . $wpdb->esc_like($term) . '%'
        ), ARRAY_A);
        
        wp_send_json_success($products);
    }
    
    public function ajax_lookup_by_code() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error('Sin permisos');
        }
        
        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null;
        
        if (empty($code)) {
            wp_send_json_error('Código requerido');
        }
        
        $result = $this->lookup_by_code($code, $supplier_id);
        
        wp_send_json_success($result);
    }
    
    public function ajax_bulk_import() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        $links = isset($_POST['links']) ? $_POST['links'] : array();
        
        if (empty($links) || !is_array($links)) {
            wp_send_json_error('No hay links para importar');
        }
        
        $imported = 0;
        $errors = array();
        
        foreach ($links as $link_data) {
            $result = $this->create_link($link_data);
            if (is_wp_error($result)) {
                $errors[] = $link_data['supplier_code'] . ': ' . $result->get_error_message();
            } else {
                $imported++;
            }
        }
        
        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors,
            'message' => "Se importaron {$imported} links"
        ));
    }
    
    public function ajax_get_unlinked() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error('Sin permisos');
        }
        
        global $wpdb;
        
        $supplier_id = isset($_POST['supplier_id']) ? absint($_POST['supplier_id']) : null;
        
        // Get codes from invoice items that don't have links
        $where = "fi.sku_local IS NULL AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''";
        $values = array();
        
        if ($supplier_id) {
            $where .= " AND f.proveedor_id = %d";
            $values[] = $supplier_id;
        }
        
        $prefix = $wpdb->prefix . 'riverso_';
        
        $sql = "SELECT DISTINCT 
                    fi.codigo_proveedor,
                    fi.descripcion,
                    f.proveedor_id as supplier_id,
                    p.nombre as supplier_name,
                    COUNT(*) as occurrence_count
                FROM {$prefix}factura_items fi
                JOIN {$prefix}facturas f ON fi.factura_id = f.id
                JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                LEFT JOIN {$this->table_links} l ON fi.codigo_proveedor = l.supplier_code AND f.proveedor_id = l.supplier_id
                WHERE {$where} AND l.id IS NULL
                GROUP BY fi.codigo_proveedor, f.proveedor_id
                ORDER BY occurrence_count DESC
                LIMIT 100";
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        
        $unlinked = $wpdb->get_results($sql, ARRAY_A);
        
        wp_send_json_success(array(
            'unlinked' => $unlinked,
            'count' => count($unlinked)
        ));
    }
    
    /**
     * Get statistics
     */
    public function get_stats() {
        global $wpdb;
        
        return array(
            'total_links' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_links} WHERE is_active = 1"),
            'linked_products' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$this->table_links} WHERE product_id IS NOT NULL AND is_active = 1"),
            'unlinked_codes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_links} WHERE product_id IS NULL AND is_active = 1"),
            'suppliers_with_links' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT supplier_id) FROM {$this->table_links} WHERE is_active = 1")
        );
    }
}
