<?php
/**
 * Módulo Cotizaciones de venta (P0+P1+P1b).
 * Estados: borrador ↔ lista; facturada reservada.
 * Bootstrap compatible: get_instance() + init() + ABSPATH.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_Quote_Module {
    private static $instance = null;
    /** @var Riverso_Customer_Quote_Repository */
    private $quotes;
    /** @var Riverso_Quote_Catalog_Lookup */
    private $catalog;

    const QUOTE_STATES = array(
        'draft' => 'Borrador',
        'listed' => 'Lista',
        'invoiced' => 'Facturada',
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_helpers();
        $this->ensure_sale_schema();
        $this->quotes = new Riverso_Customer_Quote_Repository();
        $this->catalog = new Riverso_Quote_Catalog_Lookup();
        $this->init_hooks();
    }

    /**
     * Asegura columnas P0+P1 (phase57) aunque el deploy no haya bump-eado db_version.
     */
    private function ensure_sale_schema() {
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_sale_fields')) {
            Riverso_POS_Activator::ensure_customer_quotes_sale_fields();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_advanced_discounts')) {
            Riverso_POS_Activator::ensure_customer_quotes_advanced_discounts();
        }
    }

    public function init() {
        // Compatibilidad con el bootstrap del plugin (module_list).
    }

    private function load_helpers() {
        $dir = dirname(__FILE__) . '/';
        $files = array(
            'class-quote-exception.php',
            'class-quote-status.php',
            'class-quote-totals.php',
            'class-customer-quote-repository.php',
            'class-quote-catalog-lookup.php',
        );
        foreach ($files as $file) {
            $path = $dir . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_cq_list', array($this, 'ajax_list'));
        add_action('wp_ajax_riverso_cq_get', array($this, 'ajax_get'));
        add_action('wp_ajax_riverso_cq_save', array($this, 'ajax_save'));
        add_action('wp_ajax_riverso_cq_transition', array($this, 'ajax_transition'));
        add_action('wp_ajax_riverso_cq_search', array($this, 'ajax_search'));
        add_action('wp_ajax_riverso_cq_line_stock', array($this, 'ajax_line_stock'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'riverso-pos-customer-quotes') === false
            && strpos((string) $hook, 'riverso-customer-quotes') === false
        ) {
            return;
        }
        $this->enqueue_front_assets();
    }

    public function enqueue_front_assets() {
        // CSS de respaldo en admin; el JS lo carga templates/customer-quotes/app.php
        // (así el portal interno también lo recibe sin admin_enqueue_scripts).
        $css = RIVERSO_POS_PLUGIN_DIR . 'assets/css/customer-quotes.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'riverso-customer-quotes',
                RIVERSO_POS_PLUGIN_URL . 'assets/css/customer-quotes.css',
                array(),
                (string) filemtime($css)
            );
        }
    }

    /**
     * Config para la plantilla app.php
     * @return array
     */
    public function app_config() {
        $user_name = '';
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if ($user && !empty($user->display_name)) {
                $user_name = (string) $user->display_name;
            } elseif ($user && !empty($user->user_login)) {
                $user_name = (string) $user->user_login;
            }
        }
        $can_view_stock = current_user_can('riverso_view_stock')
            || current_user_can('riverso_view_warehouse')
            || current_user_can('manage_options');
        $can_inventory = current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
        $warehouse_url = home_url('/interno/warehouse/');
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_customer_quotes'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'standalone' => false,
            'currentUserName' => $user_name,
            'caps' => array(
                'viewStock' => (bool) $can_view_stock,
                'doInventory' => (bool) $can_inventory,
            ),
            'warehouseUrl' => $warehouse_url,
            'actions' => array(
                'list' => 'riverso_cq_list',
                'get' => 'riverso_cq_get',
                'save' => 'riverso_cq_save',
                'transition' => 'riverso_cq_transition',
                'search' => 'riverso_cq_search',
                'lineStock' => 'riverso_cq_line_stock',
            ),
        );
    }

    public function render_app() {
        $riverso_cq = $this->app_config();
        include RIVERSO_POS_PLUGIN_DIR . 'templates/customer-quotes/app.php';
    }

    public function ajax_list() {
        $this->authorize();
        $status = $this->post_string('status');
        $quote_type = $this->post_string('quote_type');
        $date_from = $this->post_string('date_from');
        $date_to = $this->post_string('date_to');
        $filters = array();
        if ($status !== '' && $status !== 'all') {
            $filters['status'] = $status;
        }
        if ($quote_type !== '' && $quote_type !== 'all') {
            $filters['quote_type'] = $quote_type;
        }
        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $filters['date_from'] = $date_from;
        }
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $filters['date_to'] = $date_to;
        }
        try {
            $this->ok(array('quotes' => $this->quotes->list_quotes($filters)));
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        }
    }

    public function ajax_get() {
        $this->authorize();
        $id = (int) $this->post_string('id');
        $quote = $this->quotes->find($id);
        if ($quote === null) {
            $this->fail('Cotización no encontrada.', 404);
        }
        $this->ok(array('quote' => $quote));
    }

    public function ajax_save() {
        $this->authorize();
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        if (!is_string($raw)) {
            $this->fail('No se pudo leer la cotización.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->fail('No se pudo leer la cotización.');
        }
        try {
            $quote = $this->quotes->save($data);
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        }
        $this->ok(array(
            'quote' => $quote,
            'message' => 'Cotización ' . $quote['quote_number'] . ' guardada.',
        ));
    }

    public function ajax_transition() {
        $this->authorize();
        try {
            $quote = $this->quotes->transition((int) $this->post_string('id'), $this->post_string('status'));
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        }
        $this->ok(array(
            'quote' => $quote,
            'message' => 'Estado actualizado a ' . $quote['status_label'] . '.',
        ));
    }

    public function ajax_search() {
        $this->authorize();
        $query = $this->post_string('q');
        $mode = strtolower($this->post_string('mode'));
        if ($mode !== 'advanced') {
            $mode = 'quick';
        }
        $scope = strtolower($this->post_string('scope'));
        if (!in_array($scope, array('todo', 'descripcion', 'codigos'), true)) {
            $scope = 'todo';
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo')) {
            $len = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
            if ($scope === 'descripcion' && $len < 2) {
                $this->ok(array('products' => array(), 'hint' => 'Escribe al menos 2 caracteres para buscar por descripción.'));
                return;
            }
        }
        $this->ok(array(
            'products' => $this->catalog->search($query, 20, $mode, $scope),
            'mode' => $mode,
            'scope' => $scope,
        ));
    }


    /**
     * P4: stock live por product_id WC (no se persiste en líneas).
     * Proxy bajo nonce de cotizaciones → Riverso_Inventory_Count_Module::get_stock_status_for_product.
     */
    public function ajax_line_stock() {
        $this->authorize();
        $can_view = current_user_can('riverso_view_stock')
            || current_user_can('riverso_view_warehouse')
            || current_user_can('manage_options');
        if (!$can_view) {
            $this->fail('Sin permiso para ver stock.', 403);
        }
        $raw = isset($_POST['product_ids']) ? wp_unslash($_POST['product_ids']) : '';
        $ids = array();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = preg_split('/\s*,\s*/', $raw);
            }
        } elseif (isset($_POST['product_ids']) && is_array($_POST['product_ids'])) {
            $ids = wp_unslash($_POST['product_ids']);
        }
        $wc_ids = array();
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $wc_ids[$n] = true;
            }
        }
        $wc_ids = array_keys($wc_ids);
        if (count($wc_ids) > 200) {
            $wc_ids = array_slice($wc_ids, 0, 200);
        }
        $map = $this->resolve_stock_for_wc_products($wc_ids);
        $this->ok(array('stock' => $map));
    }

    /**
     * @param int[] $wc_product_ids
     * @return array<string, array>
     */
    private function resolve_stock_for_wc_products(array $wc_product_ids) {
        $out = array();
        foreach ($wc_product_ids as $wc_id) {
            $out[(string) $wc_id] = array(
                'product_id' => (int) $wc_id,
                'producto_base_id' => null,
                'stock_total' => null,
                'estado_confianza' => null,
                'estado_inventariado' => null,
                'alerta' => 0,
                'critico' => 0,
                'has_local_sku' => false,
                'canonical_sku' => '',
                'nombre' => '',
                'can_inventory' => false,
            );
        }
        if (!$wc_product_ids) {
            return $out;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $table = $prefix . 'producto_base';
        $placeholders = implode(',', array_fill(0, count($wc_product_ids), '%d'));
        $params = array_merge($wc_product_ids, $wc_product_ids);
        $sql = "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                FROM `{$table}`
                WHERE deleted_at IS NULL
                  AND (woocommerce_product_id IN ($placeholders) OR woocommerce_variation_id IN ($placeholders))";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }
        $by_wc = array();
        foreach ($rows as $row) {
            $pid = (int) (isset($row['woocommerce_product_id']) ? $row['woocommerce_product_id'] : 0);
            $vid = (int) (isset($row['woocommerce_variation_id']) ? $row['woocommerce_variation_id'] : 0);
            foreach (array($vid, $pid) as $key) {
                if ($key > 0 && !isset($by_wc[$key])) {
                    $by_wc[$key] = $row;
                }
            }
        }
        $inv = null;
        if (class_exists('Riverso_Inventory_Count_Module')) {
            $inv = Riverso_Inventory_Count_Module::get_instance();
        }
        $can_inventory = current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
        foreach ($wc_product_ids as $wc_id) {
            if (!isset($by_wc[$wc_id])) {
                continue;
            }
            $row = $by_wc[$wc_id];
            $base_id = (int) $row['id'];
            $sku = trim((string) (isset($row['canonical_sku']) ? $row['canonical_sku'] : ''));
            $has_local = $sku !== '';
            $stock = null;
            if ($inv && method_exists($inv, 'get_stock_status_for_product')) {
                $stock = $inv->get_stock_status_for_product($base_id);
            }
            $out[(string) $wc_id] = array(
                'product_id' => (int) $wc_id,
                'producto_base_id' => $base_id,
                'stock_total' => $stock !== null ? (float) $stock['stock_total'] : null,
                'estado_confianza' => $stock !== null ? (string) $stock['estado_confianza'] : null,
                'estado_inventariado' => $stock !== null ? (string) $stock['estado_inventariado'] : null,
                'alerta' => $stock !== null ? (int) $stock['alerta'] : 0,
                'critico' => $stock !== null ? (int) $stock['critico'] : 0,
                'has_local_sku' => $has_local,
                'canonical_sku' => $sku,
                'nombre' => (string) (isset($row['nombre_canonico']) ? $row['nombre_canonico'] : ''),
                'can_inventory' => (bool) ($can_inventory && $has_local && $base_id > 0),
            );
        }
        return $out;
    }

    /**
     * Crea tablas base (idempotente). Campos P0+P1 los agrega phase57.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_quotes = $wpdb->prefix . 'riverso_customer_quotes';
        $sql1 = "CREATE TABLE IF NOT EXISTS {$table_quotes} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            quote_number VARCHAR(50) NOT NULL,
            customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            customer_email VARCHAR(255) DEFAULT NULL,
            customer_phone VARCHAR(50) DEFAULT NULL,
            customer_rut VARCHAR(20) DEFAULT NULL,
            customer_address TEXT,
            status VARCHAR(20) DEFAULT 'draft',
            quote_type VARCHAR(20) NOT NULL DEFAULT 'venta',
            validity_days INT UNSIGNED DEFAULT NULL,
            validity_terms TEXT NULL,
            subtotal DECIMAL(12,2) DEFAULT 0,
            discount_type ENUM('percent','fixed') DEFAULT 'percent',
            discount_value DECIMAL(12,2) DEFAULT 0,
            discount_total DECIMAL(12,2) DEFAULT 0,
            tax_total DECIMAL(12,2) DEFAULT 0,
            total DECIMAL(12,2) DEFAULT 0,
            net_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            margin_percent DECIMAL(8,2) NULL,
            profit_total DECIMAL(14,2) NULL,
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
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_quote_number (quote_number),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_quote_type (quote_type),
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
            supplier_code VARCHAR(64) DEFAULT NULL,
            barcode VARCHAR(64) DEFAULT NULL,
            name VARCHAR(500) NOT NULL DEFAULT '',
            description TEXT,
            quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) DEFAULT NULL,
            discount_percent DECIMAL(5,2) DEFAULT 0,
            discount_amount DECIMAL(12,2) DEFAULT 0,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_percent DECIMAL(5,2) DEFAULT 19,
            tax_amount DECIMAL(12,2) DEFAULT 0,
            total DECIMAL(12,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            sort_order INT DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_quote (quote_id),
            KEY idx_product (product_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
        return true;
    }

    private function authorize() {
        $nonce = $this->post_string('nonce');
        if (!$this->user_can() || !wp_verify_nonce($nonce, 'riverso_customer_quotes')) {
            $this->fail('No tienes permiso para cotizar.', 403);
        }
    }

    private function user_can() {
        return current_user_can('riverso_view_quotes')
            || current_user_can('riverso_create_quotes')
            || current_user_can('riverso_edit_quotes')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
    }

    private function post_string($key) {
        if (!isset($_POST[$key])) {
            return '';
        }
        $value = wp_unslash($_POST[$key]);
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field($value);
    }

    private function ok(array $data) {
        wp_send_json_success($data);
    }

    private function fail($message, $status = 400) {
        status_header($status);
        wp_send_json_error(array('message' => $message), $status);
    }
}