<?php
/**
 * Barcode Management Module
 * Gestiona códigos de barra, escaneo y vinculación con productos
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-ean13-generator.php';

class Riverso_Barcode_Module {
    
    private static $instance = null;
    private $table_barcodes;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_barcodes = $wpdb->prefix . 'riverso_barcodes';
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // AJAX handlers
        add_action('wp_ajax_riverso_search_barcode', array($this, 'ajax_search_barcode'));
        add_action('wp_ajax_riverso_get_barcodes', array($this, 'ajax_get_barcodes'));
        add_action('wp_ajax_riverso_add_barcode', array($this, 'ajax_add_barcode'));
        add_action('wp_ajax_riverso_delete_barcode', array($this, 'ajax_delete_barcode'));
        add_action('wp_ajax_riverso_bulk_import_barcodes', array($this, 'ajax_bulk_import'));
        add_action('wp_ajax_riverso_get_barcode_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_riverso_get_unassigned_barcodes', array($this, 'ajax_get_unassigned'));
        add_action('wp_ajax_riverso_assign_barcode', array($this, 'ajax_assign_barcode'));
    }
    
    /**
     * Create tables
     */
    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_barcodes';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            barcode VARCHAR(50) NOT NULL,
            product_id BIGINT(20) UNSIGNED DEFAULT NULL,
            variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
            sku VARCHAR(100) DEFAULT NULL,
            barcode_type ENUM('EAN13','EAN8','UPC','CODE128','CODE39','INTERNAL') DEFAULT 'EAN13',
            is_primary TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            notes TEXT,
            source VARCHAR(50) DEFAULT 'manual',
            created_by BIGINT(20) UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_barcode (barcode),
            KEY idx_product (product_id),
            KEY idx_variation (variation_id),
            KEY idx_sku (sku),
            KEY idx_is_active (is_active)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
    }
    
    /**
     * Search by barcode - returns product info
     */
    public function search_by_barcode($barcode) {
        global $wpdb;
        
        $barcode = trim($barcode);
        if (empty($barcode)) {
            return null;
        }
        $normalized = ltrim($barcode, '0');
        if ($normalized === '') {
            $normalized = '0';
        }
        
        // First check our barcodes table
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_barcodes}
             WHERE barcode = %s
                OR TRIM(LEADING '0' FROM barcode) = %s
             LIMIT 1",
            $barcode,
            $normalized
        ), ARRAY_A);
        
        if ($result && $result['product_id']) {
            $product = wc_get_product($result['variation_id'] ?: $result['product_id']);
            if ($product) {
                return array(
                    'source' => 'barcodes_table',
                    'barcode_id' => $result['id'],
                    'product_id' => $result['product_id'],
                    'variation_id' => $result['variation_id'],
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'stock' => $product->get_stock_quantity(),
                    'image' => wp_get_attachment_url($product->get_image_id())
                );
            }
        }
        
        // Check WooCommerce product meta (_barcode)
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
              WHERE meta_key IN ('_barcode', '_ean', '_upc', 'barcode') 
             AND (meta_value = %s OR TRIM(LEADING '0' FROM meta_value) = %s)
             LIMIT 1",
            $barcode,
            $normalized
        ));
        
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                return array(
                    'source' => 'wc_meta',
                    'product_id' => $product->is_type('variation') ? $product->get_parent_id() : $product_id,
                    'variation_id' => $product->is_type('variation') ? $product_id : null,
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'stock' => $product->get_stock_quantity(),
                    'image' => wp_get_attachment_url($product->get_image_id())
                );
            }
        }
        
        // Check if barcode matches a SKU
        $sku_product_id = wc_get_product_id_by_sku($barcode);
        if ($sku_product_id) {
            $product = wc_get_product($sku_product_id);
            if ($product) {
                return array(
                    'source' => 'sku_match',
                    'product_id' => $product->is_type('variation') ? $product->get_parent_id() : $sku_product_id,
                    'variation_id' => $product->is_type('variation') ? $sku_product_id : null,
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'stock' => $product->get_stock_quantity(),
                    'image' => wp_get_attachment_url($product->get_image_id())
                );
            }
        }
        
        // EAN13 interno propio (formato 2SSSSSSQQQQQX): parsing inverso.
        $internal = Riverso_EAN13_Generator::parse($barcode);
        if ($internal !== null) {
            $bag = $this->resolve_internal_barcode($barcode, $internal);
            if ($bag) {
                return $bag;
            }
        }

        // Barcode exists but not linked
        if ($result) {
            return array(
                'source' => 'unlinked',
                'barcode_id' => $result['id'],
                'barcode' => $barcode,
                'sku' => $result['sku'],
                'message' => 'Código de barra encontrado pero sin producto vinculado'
            );
        }
        
        // Not found
        return null;
    }

    /**
     * Resuelve un EAN13 interno (bolsa) a su producto y cantidad embolsada.
     *
     * @param string $barcode
     * @param array  $internal ['sku' => ..., 'cantidad' => ...]
     * @return array|null
     */
    private function resolve_internal_barcode($barcode, $internal) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $bolsa = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, pb.woocommerce_product_id, pb.nombre_canonico, pb.canonical_sku
             FROM {$prefix}bolsas b
             INNER JOIN {$prefix}producto_base pb ON pb.id = b.producto_base_id
             WHERE b.ean13 = %s
             LIMIT 1",
            $barcode
        ), ARRAY_A);

        if ($bolsa) {
            $product = $bolsa['woocommerce_product_id'] ? wc_get_product($bolsa['woocommerce_product_id']) : null;
            return array(
                'source' => 'internal_bag',
                'barcode' => $barcode,
                'bolsa_id' => intval($bolsa['id']),
                'producto_base_id' => intval($bolsa['producto_base_id']),
                'product_id' => $product ? $product->get_id() : null,
                'variation_id' => null,
                'sku' => $bolsa['sku_bolsa'] ?: $bolsa['canonical_sku'],
                'name' => ($bolsa['nombre_canonico'] ?: 'Bolsa') . ' x' . rtrim(rtrim($bolsa['cantidad'], '0'), '.'),
                'cantidad' => (float) $bolsa['cantidad'],
                'price' => $product ? $product->get_price() : null,
                'costo_unitario' => $bolsa['costo_unitario'],
                'message' => 'Bolsa interna detectada (EAN13 propio)'
            );
        }

        // Sin bolsa registrada: devolver datos parseados del código.
        return array(
            'source' => 'internal_parsed',
            'barcode' => $barcode,
            'sku' => $internal['sku'],
            'cantidad' => (float) $internal['cantidad'],
            'message' => 'EAN13 interno válido pero sin bolsa registrada'
        );
    }

    /**
     * Persiste un EAN13 generado por el sistema (source=generated, tipo INTERNAL).
     *
     * @param string $ean13
     * @param int    $product_id
     * @param array  $extra ['sku', 'bolsa_id', 'producto_base_id']
     * @return int|false ID del barcode o false
     */
    public function register_generated_barcode($ean13, $product_id = 0, $extra = array()) {
        global $wpdb;

        $ean13 = trim($ean13);
        if ($ean13 === '') {
            return false;
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_barcodes} WHERE barcode = %s",
            $ean13
        ));
        if ($exists) {
            return intval($exists);
        }

        $notes = '';
        if (!empty($extra['bolsa_id'])) {
            $notes = 'Bolsa #' . intval($extra['bolsa_id']);
        }

        $result = $wpdb->insert(
            $this->table_barcodes,
            array(
                'barcode' => $ean13,
                'product_id' => $product_id ?: null,
                'variation_id' => null,
                'sku' => isset($extra['sku']) ? $extra['sku'] : null,
                'barcode_type' => 'INTERNAL',
                'is_primary' => 0,
                'notes' => $notes,
                'source' => 'generated',
                'created_by' => get_current_user_id(),
            ),
            array('%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d')
        );

        if ($result === false) {
            return false;
        }

        $barcode_id = (int) $wpdb->insert_id;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('barcode_generated', 'barcode', $barcode_id, array(
                'new_value' => array('barcode' => $ean13, 'sku' => $extra['sku'] ?? null),
                'details' => 'EAN13 interno generado',
            ));
        }

        return $barcode_id;
    }
    
    /**
     * AJAX: Search barcode
     */
    public function ajax_search_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        
        if (empty($barcode)) {
            wp_send_json_error(array('message' => 'Código de barra vacío'));
        }
        
        $result = $this->search_by_barcode($barcode);
        
        if ($result) {
            wp_send_json_success(array('product' => $result));
        } else {
            wp_send_json_error(array(
                'message' => 'Código de barra no encontrado',
                'barcode' => $barcode,
                'can_create_task' => true
            ));
        }
    }
    
    /**
     * AJAX: Get all barcodes with pagination and filters
     */
    public function ajax_get_barcodes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        global $wpdb;
        
        $page = absint($_POST['page'] ?? 1);
        $per_page = absint($_POST['per_page'] ?? 50);
        $search = sanitize_text_field($_POST['search'] ?? '');
        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        
        $offset = ($page - 1) * $per_page;
        
        $where = "1=1";
        $params = array();
        
        if (!empty($search)) {
            $where .= " AND (b.barcode LIKE %s OR b.sku LIKE %s)";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        if ($filter === 'linked') {
            $where .= " AND b.product_id IS NOT NULL";
        } elseif ($filter === 'unlinked') {
            $where .= " AND b.product_id IS NULL";
        }
        
        $sql = "SELECT b.*, p.post_title as product_name
                FROM {$this->table_barcodes} b
                LEFT JOIN {$wpdb->posts} p ON b.product_id = p.ID
                WHERE {$where}
                ORDER BY b.created_at DESC
                LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $barcodes = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        
        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$this->table_barcodes} b WHERE {$where}";
        $total = $wpdb->get_var(count($params) > 2 
            ? $wpdb->prepare($count_sql, array_slice($params, 0, -2))
            : $count_sql
        );
        
        wp_send_json_success(array(
            'barcodes' => $barcodes,
            'total' => intval($total),
            'page' => $page,
            'per_page' => $per_page,
            'pages' => ceil($total / $per_page)
        ));
    }
    
    /**
     * AJAX: Add barcode
     */
    public function ajax_add_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        global $wpdb;
        
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        $product_id = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $barcode_type = sanitize_text_field($_POST['barcode_type'] ?? 'EAN13');
        $is_primary = absint($_POST['is_primary'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        if (empty($barcode)) {
            wp_send_json_error(array('message' => 'Código de barra requerido'));
        }
        
        // Check if exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_barcodes} WHERE barcode = %s",
            $barcode
        ));
        
        if ($exists) {
            wp_send_json_error(array('message' => 'Este código de barra ya existe'));
        }
        
        // Get SKU from product if not provided
        if (empty($sku) && ($product_id || $variation_id)) {
            $product = wc_get_product($variation_id ?: $product_id);
            if ($product) {
                $sku = $product->get_sku();
            }
        }
        
        $result = $wpdb->insert(
            $this->table_barcodes,
            array(
                'barcode' => $barcode,
                'product_id' => $product_id ?: null,
                'variation_id' => $variation_id ?: null,
                'sku' => $sku ?: null,
                'barcode_type' => $barcode_type,
                'is_primary' => $is_primary,
                'notes' => $notes,
                'source' => 'manual',
                'created_by' => get_current_user_id()
            ),
            array('%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d')
        );
        
        if ($result) {
            // Audit log
            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'barcode_created',
                    'barcode',
                    $wpdb->insert_id,
                    null,
                    array(
                        'barcode' => $barcode,
                        'product_id' => $product_id,
                        'sku' => $sku
                    )
                );
            }
            
            wp_send_json_success(array(
                'message' => 'Código de barra creado',
                'id' => $wpdb->insert_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Error creando código de barra'));
        }
    }
    
    /**
     * AJAX: Delete barcode
     */
    public function ajax_delete_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        global $wpdb;
        
        $id = absint($_POST['id'] ?? 0);
        
        if (!$id) {
            wp_send_json_error(array('message' => 'ID requerido'));
        }
        
        // Get barcode before delete for audit
        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_barcodes} WHERE id = %d",
            $id
        ), ARRAY_A);
        
        $result = $wpdb->delete(
            $this->table_barcodes,
            array('id' => $id),
            array('%d')
        );
        
        if ($result) {
            // Audit log
            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'barcode_deleted',
                    'barcode',
                    $id,
                    $barcode,
                    null
                );
            }
            
            wp_send_json_success(array('message' => 'Código de barra eliminado'));
        } else {
            wp_send_json_error(array('message' => 'Error eliminando'));
        }
    }
    
    /**
     * AJAX: Bulk import barcodes from CSV
     */
    public function ajax_bulk_import() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        global $wpdb;
        
        $data = $_POST['barcodes'] ?? array();
        
        if (empty($data) || !is_array($data)) {
            wp_send_json_error(array('message' => 'No hay datos para importar'));
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = array();
        
        foreach ($data as $row) {
            $barcode = sanitize_text_field($row['barcode'] ?? '');
            $sku = sanitize_text_field($row['sku'] ?? '');
            
            if (empty($barcode)) {
                $skipped++;
                continue;
            }
            
            // Check if exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_barcodes} WHERE barcode = %s",
                $barcode
            ));
            
            if ($exists) {
                $skipped++;
                continue;
            }
            
            // Try to find product by SKU
            $product_id = null;
            $variation_id = null;
            
            if (!empty($sku)) {
                $found_id = wc_get_product_id_by_sku($sku);
                if ($found_id) {
                    $product = wc_get_product($found_id);
                    if ($product) {
                        if ($product->is_type('variation')) {
                            $variation_id = $found_id;
                            $product_id = $product->get_parent_id();
                        } else {
                            $product_id = $found_id;
                        }
                    }
                }
            }
            
            $result = $wpdb->insert(
                $this->table_barcodes,
                array(
                    'barcode' => $barcode,
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'sku' => $sku ?: null,
                    'barcode_type' => 'EAN13',
                    'is_primary' => 1,
                    'source' => 'import',
                    'created_by' => get_current_user_id()
                ),
                array('%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d')
            );
            
            if ($result) {
                $imported++;
            } else {
                $errors[] = $barcode;
            }
        }
        
        // Audit
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'barcodes_imported',
                'barcode',
                0,
                null,
                array('imported' => $imported, 'skipped' => $skipped)
            );
        }
        
        wp_send_json_success(array(
            'message' => "Importados: {$imported}, Omitidos: {$skipped}",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ));
    }
    
    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        global $wpdb;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_barcodes}");
        $linked = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_barcodes} WHERE product_id IS NOT NULL");
        $unlinked = $total - $linked;
        
        // Group by type
        $by_type = $wpdb->get_results(
            "SELECT barcode_type, COUNT(*) as count FROM {$this->table_barcodes} GROUP BY barcode_type",
            ARRAY_A
        );
        
        // Recent imports
        $recent = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_barcodes} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        wp_send_json_success(array(
            'total' => intval($total),
            'linked' => intval($linked),
            'unlinked' => intval($unlinked),
            'by_type' => $by_type,
            'recent' => intval($recent)
        ));
    }
    
    /**
     * AJAX: Get unassigned barcodes
     */
    public function ajax_get_unassigned() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        global $wpdb;
        
        $page = absint($_POST['page'] ?? 1);
        $per_page = absint($_POST['per_page'] ?? 50);
        $offset = ($page - 1) * $per_page;
        
        $barcodes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_barcodes} 
             WHERE product_id IS NULL 
             ORDER BY created_at DESC 
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);
        
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_barcodes} WHERE product_id IS NULL"
        );
        
        wp_send_json_success(array(
            'barcodes' => $barcodes,
            'total' => intval($total),
            'page' => $page,
            'pages' => ceil($total / $per_page)
        ));
    }
    
    /**
     * AJAX: Assign barcode to product
     */
    public function ajax_assign_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(array('message' => 'Sin permisos'));
        }
        
        global $wpdb;
        
        $barcode_id = absint($_POST['barcode_id'] ?? 0);
        $product_id = absint($_POST['product_id'] ?? 0);
        $variation_id = absint($_POST['variation_id'] ?? 0);
        
        if (!$barcode_id) {
            wp_send_json_error(array('message' => 'ID de código de barra requerido'));
        }
        
        // Get old data for audit
        $old_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_barcodes} WHERE id = %d",
            $barcode_id
        ), ARRAY_A);
        
        // Get SKU from product
        $sku = null;
        if ($product_id || $variation_id) {
            $product = wc_get_product($variation_id ?: $product_id);
            if ($product) {
                $sku = $product->get_sku();
                
                // Handle variation
                if ($product->is_type('variation')) {
                    $variation_id = $product->get_id();
                    $product_id = $product->get_parent_id();
                }
            }
        }
        
        $result = $wpdb->update(
            $this->table_barcodes,
            array(
                'product_id' => $product_id ?: null,
                'variation_id' => $variation_id ?: null,
                'sku' => $sku
            ),
            array('id' => $barcode_id),
            array('%d', '%d', '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            // Audit
            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'barcode_assigned',
                    'barcode',
                    $barcode_id,
                    $old_data,
                    array(
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'sku' => $sku
                    )
                );
            }
            
            wp_send_json_success(array('message' => 'Código de barra asignado'));
        } else {
            wp_send_json_error(array('message' => 'Error asignando'));
        }
    }
    
    /**
     * Check if barcode is valid
     */
    public function validate_barcode($barcode) {
        $barcode = trim($barcode);
        $length = strlen($barcode);
        
        // EAN-13
        if ($length === 13 && ctype_digit($barcode)) {
            return $this->validate_ean13($barcode) ? 'EAN13' : false;
        }
        
        // EAN-8
        if ($length === 8 && ctype_digit($barcode)) {
            return $this->validate_ean8($barcode) ? 'EAN8' : false;
        }
        
        // UPC-A
        if ($length === 12 && ctype_digit($barcode)) {
            return 'UPC';
        }
        
        // Internal code (any alphanumeric)
        if ($length >= 4 && $length <= 20) {
            return 'INTERNAL';
        }
        
        return false;
    }
    
    private function validate_ean13($barcode) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($barcode[$i]) * ($i % 2 === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;
        return intval($barcode[12]) === $check;
    }
    
    private function validate_ean8($barcode) {
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $sum += intval($barcode[$i]) * ($i % 2 === 0 ? 3 : 1);
        }
        $check = (10 - ($sum % 10)) % 10;
        return intval($barcode[7]) === $check;
    }
}
