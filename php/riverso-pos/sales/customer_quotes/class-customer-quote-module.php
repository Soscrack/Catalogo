<?php
/**
 * Módulo Cotizaciones de venta (P0+P1).
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
        $this->quotes = new Riverso_Customer_Quote_Repository();
        $this->catalog = new Riverso_Quote_Catalog_Lookup();
        $this->init_hooks();
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
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_customer_quotes'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'standalone' => false,
            'actions' => array(
                'list' => 'riverso_cq_list',
                'get' => 'riverso_cq_get',
                'save' => 'riverso_cq_save',
                'transition' => 'riverso_cq_transition',
                'search' => 'riverso_cq_search',
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
        $filters = array();
        if ($status !== '' && $status !== 'all') {
            $filters['status'] = $status;
        }
        $this->ok(array('quotes' => $this->quotes->list_quotes($filters)));
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
        $this->ok(array('products' => $this->catalog->search($query, 20)));
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