<?php
/**
 * Módulo Cotizaciones de venta (P0–P5a).
 * Estados: borrador ↔ lista; facturada vía riverso_cq_invoice.
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
        'listed' => 'Aprobada',
        'invoiced' => 'Facturada',
        'rejected' => 'Rechazada',
        'cancelled' => 'Anulada',
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
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_channel')) {
            Riverso_POS_Activator::ensure_customer_quotes_channel();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_price_mode')) {
            Riverso_POS_Activator::ensure_customer_quotes_price_mode();
        }
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_customer_quotes_issue_date')) {
            Riverso_POS_Activator::ensure_customer_quotes_issue_date();
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
            'class-quote-pdf.php',
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
        add_action('wp_ajax_riverso_cq_delete', array($this, 'ajax_delete'));
        add_action('wp_ajax_riverso_cq_discard_empty', array($this, 'ajax_discard_empty'));
        add_action('wp_ajax_riverso_cq_search', array($this, 'ajax_search'));
        add_action('wp_ajax_riverso_cq_line_stock', array($this, 'ajax_line_stock'));
        add_action('wp_ajax_riverso_cq_bag_sizes', array($this, 'ajax_bag_sizes'));
        add_action('wp_ajax_riverso_cq_invoice', array($this, 'ajax_invoice'));
        add_action('wp_ajax_riverso_cq_received_list', array($this, 'ajax_received_list'));
        add_action('wp_ajax_riverso_cq_received_preview', array($this, 'ajax_received_preview'));
        add_action('wp_ajax_riverso_cq_received_import', array($this, 'ajax_received_import'));
        add_action('wp_ajax_riverso_cq_pdf', array($this, 'ajax_pdf'));
        // P1.5 proxies (nonce cotizaciones) → quick-view / pricing / families / unit / tienda-local
        add_action('wp_ajax_riverso_cq_products_quick_lookup', array($this, 'ajax_products_quick_lookup'));
        add_action('wp_ajax_riverso_cq_products_quick_search', array($this, 'ajax_products_quick_search'));
        add_action('wp_ajax_riverso_cq_products_quick_summary', array($this, 'ajax_products_quick_summary'));
        add_action('wp_ajax_riverso_cq_pricing', array($this, 'ajax_pricing'));
        add_action('wp_ajax_riverso_cq_families', array($this, 'ajax_families'));
        add_action('wp_ajax_riverso_cq_unit_product', array($this, 'ajax_unit_product'));
        add_action('wp_ajax_riverso_cq_tienda_local', array($this, 'ajax_tienda_local'));
        add_action('wp_ajax_riverso_cq_family_price', array($this, 'ajax_family_price'));
        add_action('wp_ajax_riverso_cq_customers_search', array($this, 'ajax_customers_search'));
        add_action('wp_ajax_riverso_cq_customer_save', array($this, 'ajax_customer_save'));
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
            'adminUrl' => admin_url('admin.php'),
            'productsUrl' => admin_url('admin.php?page=riverso-pos-products'),
            'nonce' => wp_create_nonce('riverso_customer_quotes'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'standalone' => false,
            'surface' => 'admin',
            'portalUrl' => home_url('/interno/customer-quotes/'),
            'billingEmitUrl' => home_url('/interno/facturacion/'),
            'canEmitDte' => current_user_can('riverso_emit_dte')
                || current_user_can('manage_options')
                || current_user_can('manage_woocommerce'),
            'canDelete' => current_user_can('riverso_edit_quotes')
                || current_user_can('riverso_create_quotes')
                || current_user_can('manage_woocommerce')
                || current_user_can('manage_options'),
            'currentUserName' => $user_name,
            'caps' => array(
                'viewStock' => (bool) $can_view_stock,
                'doInventory' => (bool) $can_inventory,
                'editCustomers' => current_user_can('riverso_edit_customers')
                    || current_user_can('riverso_create_quotes')
                    || current_user_can('riverso_edit_quotes')
                    || current_user_can('manage_options'),
            ),
            'warehouseUrl' => $warehouse_url,
            'comunas' => (function () {
                if (!class_exists('Riverso_Customer_Module')) {
                    $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-module.php';
                    if (file_exists($path)) {
                        require_once $path;
                    }
                }
                return class_exists('Riverso_Customer_Module')
                    ? Riverso_Customer_Module::chile_comunas()
                    : array();
            })(),
            'actions' => array(
                'list' => 'riverso_cq_list',
                'get' => 'riverso_cq_get',
                'save' => 'riverso_cq_save',
                'transition' => 'riverso_cq_transition',
                'delete' => 'riverso_cq_delete',
                'discardEmpty' => 'riverso_cq_discard_empty',
                'search' => 'riverso_cq_search',
                'lineStock' => 'riverso_cq_line_stock',
                'bagSizes' => 'riverso_cq_bag_sizes',
                'receivedList' => 'riverso_cq_received_list',
                'receivedPreview' => 'riverso_cq_received_preview',
                'receivedImport' => 'riverso_cq_received_import',
                'productsQuickLookup' => 'riverso_cq_products_quick_lookup',
                'productsQuickSearch' => 'riverso_cq_products_quick_search',
                'productsQuickSummary' => 'riverso_cq_products_quick_summary',
                'pricing' => 'riverso_cq_pricing',
                'families' => 'riverso_cq_families',
                'unitProduct' => 'riverso_cq_unit_product',
                'tiendaLocal' => 'riverso_cq_tienda_local',
                'familyPrice' => 'riverso_cq_family_price',
                'pdf' => 'riverso_cq_pdf',
                'customersSearch' => 'riverso_cq_customers_search',
                'customerSave' => 'riverso_cq_customer_save',
            ),
            'defaultChannel' => 'local',
            'todayDate' => current_time('Y-m-d'),
        );
    }

    /**
     * HTML imprimible de cotización (Abrir → Guardar como PDF).
     * Acepta GET (pestaña nueva) o POST.
     */
    public function ajax_pdf() {
        $this->authorize_request();
        $id = (int) $this->request_string('id');
        if ($id <= 0) {
            $this->fail_html('Cotización no encontrada.', 404);
        }
        $template = $this->request_string('template');
        $quote = $this->quotes->find($id);
        if ($quote === null) {
            $this->fail_html('Cotización no encontrada.', 404);
        }
        $quote = $this->catalog->hydrate_quote_families($quote);
        $quote = $this->enrich_quote_seller($quote, $id);
        if (!class_exists('Riverso_Quote_Pdf')) {
            $this->fail_html('Generador PDF no disponible.', 500);
        }
        $doc = Riverso_Quote_Pdf::build($quote, $template);
        $css_path = RIVERSO_POS_PLUGIN_DIR . 'assets/css/customer-quote-pdf.css';
        $css_url = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets/css/customer-quote-pdf.css';
        if (is_file($css_path)) {
            $css_url .= '?ver=' . rawurlencode((string) filemtime($css_path));
        }
        $logo_path = RIVERSO_POS_PLUGIN_DIR . 'assets/img/logo-rs.png';
        $logo_url = '';
        if (is_file($logo_path)) {
            $logo_url = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets/img/logo-rs.png?ver='
                . rawurlencode((string) filemtime($logo_path));
        }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');
        include RIVERSO_POS_PLUGIN_DIR . 'templates/customer-quotes/pdf.php';
        exit;
    }

    /**
     * Completa email del vendedor desde created_by (no viaja en present()).
     *
     * @param array $quote
     * @param int   $id
     * @return array
     */
    private function enrich_quote_seller(array $quote, $id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0 || !isset($wpdb)) {
            return $quote;
        }
        $table = $wpdb->prefix . 'riverso_customer_quotes';
        $created_by = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT created_by FROM {$table} WHERE id = %d",
            $id
        ));
        if ($created_by <= 0) {
            return $quote;
        }
        $quote['created_by'] = $created_by;
        if (function_exists('get_userdata')) {
            $user = get_userdata($created_by);
            if ($user) {
                if (empty($quote['seller_name']) && !empty($user->display_name)) {
                    $quote['seller_name'] = (string) $user->display_name;
                }
                if (!empty($user->user_email)) {
                    $quote['seller_email'] = (string) $user->user_email;
                }
            }
        }
        return $quote;
    }

    public function render_app($surface = null) {
        if ($surface !== 'portal' && $surface !== 'admin') {
            $surface = (function_exists('is_admin') && is_admin()) ? 'admin' : 'portal';
        }
        $riverso_cq = $this->app_config();
        $riverso_cq['surface'] = $surface;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/customer-quotes/app.php';
    }

    public function ajax_list() {
        $this->authorize();
        $status = $this->post_string('status');
        $quote_type = $this->post_string('quote_type');
        $date_from = $this->post_string('date_from');
        $date_to = $this->post_string('date_to');
        $quote_number = $this->post_string('quote_number');
        $customer_name = $this->post_string('customer_name');
        $created_by = $this->post_string('created_by');
        $order_by = strtolower($this->post_string('order_by'));
        $order_dir = strtoupper($this->post_string('order_dir'));
        $filters = array();
        if ($status !== '' && $status !== 'all') {
            $filters['status'] = $status;
        }
        if ($quote_type !== '' && $quote_type !== 'all') {
            $filters['quote_type'] = $quote_type;
        }
        if ($quote_number !== '') {
            $filters['quote_number'] = $quote_number;
        }
        if ($customer_name !== '') {
            $filters['customer_name'] = $customer_name;
        }
        if ($created_by !== '' && $created_by !== '0' && ctype_digit($created_by)) {
            $filters['created_by'] = (int) $created_by;
        }
        if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $filters['date_from'] = $date_from;
        }
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $filters['date_to'] = $date_to;
        }
        $allowed_order = array('date', 'number', 'customer', 'amount', 'status');
        if (!in_array($order_by, $allowed_order, true)) {
            $order_by = 'date';
        }
        if ($order_dir !== 'ASC' && $order_dir !== 'DESC') {
            $order_dir = 'DESC';
        }
        $filters['order_by'] = $order_by;
        $filters['order_dir'] = $order_dir;
        $this->quotes->sweep_empty_drafts(12);
        try {
            $this->ok(array(
                'quotes' => $this->quotes->list_quotes($filters),
                'responsables' => $this->quotes->list_responsables(),
                'order_by' => $order_by,
                'order_dir' => $order_dir,
            ));
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
        $this->ok(array('quote' => $this->present_quote_with_docs($quote)));
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
        $quote = $this->present_quote_with_docs($quote);
        $this->ok(array(
            'quote' => $quote,
            'message' => 'Cotización ' . $quote['quote_number'] . ' guardada.',
        ));
    }

    public function ajax_transition() {
        $this->authorize();
        $id = (int) $this->post_string('id');
        $to = $this->post_string('status');
        try {
            $quote = $this->quotes->transition($id, $to);
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        }
        $this->ok(array(
            'quote' => $this->present_quote_with_docs($quote),
            'message' => 'Estado actualizado a ' . $quote['status_label'] . '.',
        ));
    }

    public function ajax_delete() {
        $this->authorize();
        $can_delete = current_user_can('riverso_edit_quotes')
            || current_user_can('riverso_create_quotes')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
        if (!$can_delete) {
            $this->fail('No tienes permiso para borrar cotizaciones.', 403);
        }
        $id = (int) $this->post_string('id');
        try {
            $this->quotes->delete_quote($id);
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        }
        $this->ok(array(
            'deleted_id' => $id,
            'message' => 'Cotización borrada.',
        ));
    }

    /**
     * Descarta un borrador vacío al salir del editor (botón volver, cierre de pestaña vía beacon).
     * No exige permiso de borrado: el repositorio solo borra borradores sin líneas ni documentos.
     */
    public function ajax_discard_empty() {
        $this->authorize();
        $id = (int) $this->post_string('id');
        $deleted = $this->quotes->discard_if_empty($id);
        $this->ok(array(
            'deleted' => $deleted,
            'deleted_id' => $id,
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
        $channel = $this->catalog->normalize_channel($this->post_string('channel'));
        $contains = $this->post_contains_words();
        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }
        if ($query === '' && $contains) {
            $query = $contains[0];
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo')) {
            $len = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
            if ($scope === 'descripcion' && $len < 2 && !$contains) {
                $this->ok(array(
                    'products' => array(),
                    'hint' => 'Escribe al menos 2 caracteres para buscar por descripción.',
                    'channel' => $channel,
                    'total' => 0,
                    'page' => 1,
                    'per_page' => 30,
                    'pages' => 1,
                ));
                return;
            }
        }
        $limit = $mode === 'advanced' ? 30 : 20;
        $products = $this->catalog->search($query, $limit, $mode, $scope, $channel, $page, $mode === 'advanced' ? $contains : array());
        if ($mode !== 'advanced' && $contains) {
            $products = $this->catalog->filter_contains_words($products, $contains, $scope);
            $products = array_slice(array_values($products), 0, 20);
        }
        $meta = is_array($this->catalog->search_meta) ? $this->catalog->search_meta : array();
        $this->ok(array(
            'products' => $products,
            'mode' => $mode,
            'scope' => $scope,
            'channel' => $channel,
            'contains' => $contains,
            'total' => isset($meta['total']) ? (int) $meta['total'] : count($products),
            'page' => isset($meta['page']) ? (int) $meta['page'] : 1,
            'per_page' => isset($meta['per_page']) ? (int) $meta['per_page'] : $limit,
            'pages' => isset($meta['pages']) ? (int) $meta['pages'] : 1,
            'capped' => !empty($meta['capped']),
        ));
    }

    /**
     * Palabras del filtro "Contiene palabra" (JSON array o CSV).
     *
     * @return string[]
     */
    private function post_contains_words() {
        $raw = isset($_POST['contains']) ? wp_unslash($_POST['contains']) : '';
        $words = array();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $words = $decoded;
            } else {
                $words = preg_split('/\s*,\s*/', $raw) ?: array();
            }
        }
        $out = array();
        $seen = array();
        foreach ($words as $word) {
            if (!is_string($word) && !is_numeric($word)) {
                continue;
            }
            $word = trim(preg_replace('/\s+/u', ' ', (string) $word));
            if ($word === '') {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
            if ($len < 2) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($word, 'UTF-8') : strtolower($word);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $word;
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
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
     * Tamaños de bolsa registrados y disponibles de un producto (detalle de entrega).
     */
    public function ajax_bag_sizes() {
        $this->authorize();
        $pb = (int) $this->post_string('producto_base_id');
        $sizes = ($pb > 0 && class_exists('Riverso_Sale_Stock_Service'))
            ? Riverso_Sale_Stock_Service::get_instance()->bag_sizes($pb)
            : array();
        $this->ok(array('sizes' => $sizes));
    }

    /**
     * Mapa de stock por product_id WC (uso interno y módulos hermanos).
     *
     * @param int[] $wc_product_ids
     * @return array<string, array>
     */
    public function stock_map_for_wc_products(array $wc_product_ids) {
        return $this->resolve_stock_for_wc_products($wc_product_ids);
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
        $reservations = class_exists('Riverso_Reservation_Service') ? Riverso_Reservation_Service::get_instance() : null;
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
            $reserved = $reservations ? (float) $reservations->get_reserved($base_id) : 0.0;
            // Orden en que saldría al vender (Modo avanzado muestra la vista previa).
            $sale_stock = class_exists('Riverso_Sale_Stock_Service') ? Riverso_Sale_Stock_Service::get_instance() : null;
            $cascade = $sale_stock ? $sale_stock->cascade_candidates($base_id) : array();
            $unlocated = $sale_stock ? $sale_stock->unlocated_balance($base_id) : 0.0;
            $stock_total = $stock !== null ? (float) $stock['stock_total'] : null;
            $out[(string) $wc_id] = array(
                'product_id' => (int) $wc_id,
                'producto_base_id' => $base_id,
                'stock_total' => $stock_total,
                // Reservado por cotizaciones aprobadas; disponible puede ser negativo.
                'reservado' => round($reserved, 4),
                'cascada' => $cascade,
                'sin_ubicar' => round($unlocated, 4),
                'disponible' => $stock_total !== null ? round($stock_total - $reserved, 4) : null,
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
     * P5a: Facturar cotización listed+venta → pedido WC pending (idempotente).
     * Antes de crear: reutiliza pedido WC con meta _riverso_customer_quote_id.
     * Si create OK y mark falla: elimina el pedido huérfano y reporta error claro.
     */
    public function ajax_invoice() {
        $this->authorize_invoice();
        $id = (int) $this->post_string('id');
        if ($id <= 0) {
            $this->fail('Cotización no encontrada.', 404);
        }

        $lock_key = 'riverso_cq_invoice_lock_' . $id;
        if (get_transient($lock_key)) {
            $this->fail('Facturación en curso. Espere un momento e intente de nuevo.');
        }
        set_transient($lock_key, 1, 30);

        try {
            $quote = $this->quotes->find($id);
            if ($quote === null) {
                $this->fail('Cotización no encontrada.', 404);
            }

            // Idempotente: ya facturada con pedido.
            if ($quote['status'] === Riverso_Quote_Status::INVOICED && !empty($quote['order_id'])) {
                $this->ok(array(
                    'quote' => $quote,
                    'order_id' => (int) $quote['order_id'],
                    'order_url' => isset($quote['order_url']) ? $quote['order_url'] : '',
                    'message' => 'Cotización ya facturada. Pedido #' . (int) $quote['order_id'] . '.',
                    'idempotent' => true,
                ));
            }

            if ($quote['status'] !== Riverso_Quote_Status::LISTED) {
                $this->fail('Solo se puede facturar una cotización Aprobada.');
            }
            if (($quote['quote_type'] ?? '') !== Riverso_Quote_Type::VENTA) {
                $this->fail('Solo las cotizaciones de tipo Venta se pueden facturar.');
            }
            if (!empty($quote['order_id'])) {
                // Ya tiene pedido pero status raro: re-presentar.
                $fixed = $this->quotes->mark_invoiced($id, (int) $quote['order_id']);
                $this->ok(array(
                    'quote' => $fixed,
                    'order_id' => (int) $fixed['order_id'],
                    'order_url' => isset($fixed['order_url']) ? $fixed['order_url'] : '',
                    'message' => 'Cotización ya facturada. Pedido #' . (int) $fixed['order_id'] . '.',
                    'idempotent' => true,
                ));
            }

            // Recuperación / anti-duplicado: pedido WC ya creado (p.ej. mark falló tras create).
            $existing_order_id = $this->find_wc_order_id_for_quote($id);
            if ($existing_order_id > 0) {
                $fixed = $this->quotes->mark_invoiced($id, $existing_order_id);
                $this->ok(array(
                    'quote' => $fixed,
                    'order_id' => (int) $fixed['order_id'],
                    'order_url' => isset($fixed['order_url']) ? $fixed['order_url'] : '',
                    'message' => 'Cotización ya facturada. Pedido #' . (int) $fixed['order_id'] . '.',
                    'idempotent' => true,
                ));
            }

            $lines = isset($quote['lines']) && is_array($quote['lines']) ? $quote['lines'] : array();
            if (!$lines) {
                $this->fail('La cotización no tiene líneas para facturar.');
            }

            $order_items = array();
            foreach ($lines as $idx => $line) {
                $pid = isset($line['product_id']) ? (int) $line['product_id'] : 0;
                $qty = isset($line['quantity']) ? (float) $line['quantity'] : 0.0;
                if ($pid <= 0) {
                    $this->fail('Hay líneas locales sin producto WooCommerce (product_id). No se puede facturar (P5a-6).');
                }
                if ($qty <= 0) {
                    $this->fail('Hay líneas con cantidad inválida. No se puede facturar.');
                }
                $line_net = isset($line['line_net']) ? (float) $line['line_net'] : 0.0;
                $unit = $qty > 0 ? round($line_net / $qty, 6) : 0.0;
                // Preferir precio unitario cotizado neto de descuentos.
                $order_items[] = array(
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'price' => $unit,
                    'name' => isset($line['description']) ? (string) $line['description'] : '',
                );
            }

            if (!class_exists('Riverso_POS_Module') || !method_exists('Riverso_POS_Module', 'create_pending_wc_order')) {
                $this->fail('No está disponible el helper de pedidos pendientes.');
            }

            $note = 'Facturado desde cotización ' . (isset($quote['quote_number']) ? $quote['quote_number'] : ('#' . $id));
            // customer_id de la cotización es id de riverso_clientes, no de usuario WP.
            $billing_name = isset($quote['customer_name']) ? (string) $quote['customer_name'] : '';
            $billing_email = '';
            $billing_phone = '';
            $cliente_id = isset($quote['customer_id']) ? (int) $quote['customer_id'] : 0;
            if ($cliente_id > 0) {
                $crepo = $this->customer_repo();
                if ($crepo) {
                    $cust = $crepo->get($cliente_id);
                    if ($cust) {
                        if ($billing_name === '' && !empty($cust['nombre_fantasia'])) {
                            $billing_name = (string) $cust['nombre_fantasia'];
                        }
                        if (!empty($cust['contacto_email'])) {
                            $billing_email = (string) $cust['contacto_email'];
                        }
                        if (!empty($cust['contacto_telefono'])) {
                            $billing_phone = (string) $cust['contacto_telefono'];
                        } elseif (!empty($cust['facturacion_telefono'])) {
                            $billing_phone = (string) $cust['facturacion_telefono'];
                        }
                    }
                }
            }
            $order = Riverso_POS_Module::create_pending_wc_order(array(
                'items' => $order_items,
                'customer_id' => 0,
                'customer_name' => $billing_name,
                'customer_email' => $billing_email,
                'customer_phone' => $billing_phone,
                'notes' => $note,
                'created_via' => 'riverso_customer_quote',
                'meta' => array(
                    '_riverso_customer_quote_id' => $id,
                    '_riverso_customer_quote_number' => isset($quote['quote_number']) ? (string) $quote['quote_number'] : '',
                    '_riverso_cliente_id' => $cliente_id > 0 ? $cliente_id : '',
                ),
            ));

            if (is_wp_error($order)) {
                $this->fail($order->get_error_message());
            }

            $order_id = (int) $order->get_id();

            // Persist order_id ASAP; si mark falla no dejar listed + huérfano silencioso.
            try {
                $marked = $this->quotes->mark_invoiced($id, $order_id);
            } catch (Riverso_Quote_Exception $mark_error) {
                $this->discard_orphan_wc_order($order);
                $this->fail(
                    'Pedido creado pero no se pudo vincular a la cotización; el pedido huérfano fue eliminado. '
                    . $mark_error->getMessage()
                );
            }

            // Race: otro request pudo facturar primero.
            if ((int) $marked['order_id'] !== $order_id) {
                $this->discard_orphan_wc_order($order);
                $this->ok(array(
                    'quote' => $marked,
                    'order_id' => (int) $marked['order_id'],
                    'order_url' => isset($marked['order_url']) ? $marked['order_url'] : '',
                    'message' => 'Cotización ya facturada. Pedido #' . (int) $marked['order_id'] . '.',
                    'idempotent' => true,
                ));
            }

            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'customer_quote.invoiced',
                    'customer_quote',
                    $id,
                    array('status' => Riverso_Quote_Status::LISTED),
                    array(
                        'status' => Riverso_Quote_Status::INVOICED,
                        'order_id' => $order_id,
                    ),
                    'Facturar → pedido WC pending #' . $order_id
                );
            }

            $this->ok(array(
                'quote' => $marked,
                'order_id' => $order_id,
                'order_url' => isset($marked['order_url']) ? $marked['order_url'] : '',
                'message' => 'Cotización facturada. Pedido #' . $order_id . ' (pendiente).',
                'idempotent' => false,
            ));
        } catch (Riverso_Quote_Exception $error) {
            $this->fail($error->getMessage());
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Busca pedido WC existente ligado a la cotización (meta _riverso_customer_quote_id).
     * Preferir el más antiguo para recuperar huérfanos de un Facturar previo fallido.
     *
     * @param int $quote_id
     * @return int order id o 0
     */
    private function find_wc_order_id_for_quote($quote_id) {
        $quote_id = (int) $quote_id;
        if ($quote_id <= 0) {
            return 0;
        }

        if (function_exists('wc_get_orders')) {
            $query = array(
                'limit' => 1,
                'return' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'status' => 'any',
                'meta_key' => '_riverso_customer_quote_id',
                'meta_value' => (string) $quote_id,
                'meta_compare' => '=',
            );
            $ids = wc_get_orders($query);
            if (is_array($ids) && !empty($ids)) {
                return (int) $ids[0];
            }
        }

        // Fallback CPT postmeta (tiendas sin HPOS / wc_get_orders incompleto).
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s AND meta_value = %s
             ORDER BY post_id ASC LIMIT 1",
            '_riverso_customer_quote_id',
            (string) $quote_id
        ));
        return $found ? (int) $found : 0;
    }

    /**
     * Elimina un pedido WC huérfano tras fallo de mark_invoiced / carrera.
     *
     * @param mixed $order WC_Order|null
     */
    private function discard_orphan_wc_order($order) {
        if (!$order || !is_object($order)) {
            return;
        }
        if (method_exists($order, 'delete')) {
            $order->delete(true);
            return;
        }
        if (method_exists($order, 'get_id') && function_exists('wp_delete_post')) {
            wp_delete_post((int) $order->get_id(), true);
        }
    }

    /**
     * P5b: listado de cotizaciones recibidas confirmadas (preferir versión final del grupo).
     */
    public function ajax_received_list() {
        $this->authorize();
        if (!class_exists('Riverso_POS_Received_Quote_Module')) {
            $this->fail('Módulo de cotizaciones recibidas no disponible.');
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $mod = Riverso_POS_Received_Quote_Module::get_instance();
        if (method_exists($mod, 'ensure_version_columns')) {
            // ensure_version_columns is private; decorate via list query columns if present.
        }

        $table = $prefix . 'cotizaciones_recibidas';
        $has_version = false;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        if (is_array($cols) && in_array('version_group_id', $cols, true)) {
            $has_version = true;
        }
        $version_select = $has_version
            ? 'c.version_group_id, c.version_n,'
            : 'NULL AS version_group_id, NULL AS version_n,';
        $sql = "SELECT c.id, c.numero_documento, c.fecha_documento, c.estado, c.proveedor_id,
                       c.tipo_doc, c.tipo_confirmado, c.total, {$version_select}
                       c.created_at, p.nombre AS proveedor_nombre
                FROM {$table} c
                LEFT JOIN {$prefix}proveedores p ON c.proveedor_id = p.id
                WHERE c.estado = 'approved'
                  AND (c.tipo_doc = 'cotizacion' OR c.tipo_doc IS NULL OR c.tipo_doc = '')
                  AND IFNULL(c.tipo_confirmado, 1) = 1
                ORDER BY c.fecha_documento DESC, c.id DESC
                LIMIT 80";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            $rows = array();
        }

        // Prefer final version of each group (highest version_n).
        $by_group = array();
        $ungrouped = array();
        foreach ($rows as $row) {
            $gid = isset($row['version_group_id']) ? (int) $row['version_group_id'] : 0;
            if ($gid > 0) {
                $vn = isset($row['version_n']) ? (int) $row['version_n'] : 0;
                if (!isset($by_group[$gid]) || $vn >= (int) ($by_group[$gid]['version_n'] ?? 0)) {
                    $by_group[$gid] = $row;
                }
            } else {
                $ungrouped[] = $row;
            }
        }
        $out = array_values($by_group);
        foreach ($ungrouped as $row) {
            $out[] = $row;
        }
        usort($out, static function ($a, $b) {
            $fa = (string) ($a['fecha_documento'] ?? '');
            $fb = (string) ($b['fecha_documento'] ?? '');
            if ($fa === $fb) {
                return ((int) $b['id']) - ((int) $a['id']);
            }
            return strcmp($fb, $fa);
        });

        $quotes = array();
        foreach ($out as $row) {
            $quotes[] = array(
                'id' => (int) $row['id'],
                'numero_documento' => (string) ($row['numero_documento'] ?? ''),
                'fecha_documento' => (string) ($row['fecha_documento'] ?? ''),
                'proveedor_nombre' => (string) ($row['proveedor_nombre'] ?? ''),
                'total' => isset($row['total']) ? (float) $row['total'] : 0,
                'version_n' => isset($row['version_n']) ? (int) $row['version_n'] : null,
            );
        }
        $this->ok(array('quotes' => $quotes));
    }

    /**
     * P5b: preview de ítems importables (solo matched/manual con WC product).
     */
    public function ajax_received_preview() {
        $this->authorize();
        $id = (int) $this->post_string('id');
        if ($id <= 0) {
            $this->fail('Cotización recibida no encontrada.', 404);
        }
        if (!class_exists('Riverso_POS_Received_Quote_Module')) {
            $this->fail('Módulo de cotizaciones recibidas no disponible.');
        }
        $mod = Riverso_POS_Received_Quote_Module::get_instance();
        $quote = $mod->get_quote($id);
        if (!$quote) {
            $this->fail('Cotización recibida no encontrada.', 404);
        }
        $estado = is_object($quote) ? (string) ($quote->estado ?? '') : (string) ($quote['estado'] ?? '');
        $tipo_doc = is_object($quote) ? (string) ($quote->tipo_doc ?? '') : (string) ($quote['tipo_doc'] ?? '');
        $tipo_conf = is_object($quote) ? (int) ($quote->tipo_confirmado ?? 1) : (int) ($quote['tipo_confirmado'] ?? 1);
        if ($estado !== 'approved' || $tipo_doc === 'posible_cotizacion' || $tipo_conf === 0) {
            $this->fail('Solo se pueden importar cotizaciones recibidas confirmadas/aprobadas.');
        }

        $items = $mod->get_quote_items($id);
        if (!is_array($items)) {
            $items = array();
        }

        $lines = array();
        $skipped = array('ambiguous' => 0, 'not_found' => 0, 'no_wc' => 0, 'other' => 0);
        foreach ($items as $item) {
            $row = is_object($item) ? (array) $item : $item;
            $match = strtolower((string) ($row['match_status'] ?? 'pending'));
            $vid = isset($row['variacion_id']) ? (int) $row['variacion_id'] : 0;
            $pid = isset($row['producto_id']) ? (int) $row['producto_id'] : 0;
            $wc_id = $vid > 0 ? $vid : $pid;
            if ($match === 'ambiguous') {
                $skipped['ambiguous']++;
                continue;
            }
            if ($match === 'not_found' || $match === 'pending') {
                $skipped['not_found']++;
                continue;
            }
            if ($wc_id <= 0) {
                $skipped['no_wc']++;
                continue;
            }
            if (!in_array($match, array('matched', 'manual'), true)) {
                $skipped['other']++;
                continue;
            }

            $catalog_price = 0.0;
            $sku = (string) ($row['sku_match'] ?? '');
            $name = (string) ($row['descripcion'] ?? '');
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($wc_id);
                if ($product) {
                    $catalog_price = (float) $product->get_price();
                    if ($catalog_price <= 0) {
                        $catalog_price = (float) $product->get_regular_price();
                    }
                    if ($sku === '') {
                        $sku = (string) $product->get_sku();
                    }
                    if ($name === '') {
                        $name = (string) $product->get_name();
                    }
                } else {
                    $skipped['no_wc']++;
                    continue;
                }
            }

            $cost = isset($row['costo_neto']) ? (float) $row['costo_neto'] : 0.0;
            $qty = isset($row['cantidad']) ? (float) $row['cantidad'] : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $lines[] = array(
                'item_id' => (int) ($row['id'] ?? 0),
                'product_id' => $wc_id,
                'sku' => $sku,
                'supplier_code' => (string) ($row['codigo_proveedor'] ?? ''),
                'barcode' => (string) ($row['codigo_barras'] ?? ''),
                'description' => $name,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'unit_price' => $catalog_price,
                'match_status' => $match,
                'selectable' => true,
            );
        }

        $this->ok(array(
            'quote' => array(
                'id' => $id,
                'numero_documento' => is_object($quote) ? (string) ($quote->numero_documento ?? '') : (string) ($quote['numero_documento'] ?? ''),
                'proveedor_nombre' => '',
            ),
            'lines' => $lines,
            'skipped' => $skipped,
        ));
    }

    /**
     * P5b: registra auditoría de importación; el cliente reutiliza addProduct con las líneas.
     */
    public function ajax_received_import() {
        $this->authorize();
        $received_id = (int) $this->post_string('received_quote_id');
        $raw = isset($_POST['item_ids']) ? wp_unslash($_POST['item_ids']) : '';
        $item_ids = array();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $item_ids = $decoded;
            }
        }
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids))));
        if ($received_id <= 0) {
            $this->fail('Cotización recibida inválida.');
        }
        if (!$item_ids) {
            $this->fail('Selecciona al menos una línea para importar.');
        }

        // Reusar preview para normalizar precios (cost→unit_cost; unit_price=catálogo WC).
        $_POST['id'] = (string) $received_id;
        if (!class_exists('Riverso_POS_Received_Quote_Module')) {
            $this->fail('Módulo de cotizaciones recibidas no disponible.');
        }
        $mod = Riverso_POS_Received_Quote_Module::get_instance();
        $quote = $mod->get_quote($received_id);
        if (!$quote) {
            $this->fail('Cotización recibida no encontrada.', 404);
        }
        $all_items = $mod->get_quote_items($received_id);
        $by_id = array();
        foreach ($all_items as $item) {
            $row = is_object($item) ? (array) $item : $item;
            $by_id[(int) ($row['id'] ?? 0)] = $row;
        }

        $products = array();
        foreach ($item_ids as $iid) {
            if (!isset($by_id[$iid])) {
                continue;
            }
            $row = $by_id[$iid];
            $match = strtolower((string) ($row['match_status'] ?? ''));
            if (!in_array($match, array('matched', 'manual'), true)) {
                continue;
            }
            $vid = isset($row['variacion_id']) ? (int) $row['variacion_id'] : 0;
            $pid = isset($row['producto_id']) ? (int) $row['producto_id'] : 0;
            $wc_id = $vid > 0 ? $vid : $pid;
            if ($wc_id <= 0 || !function_exists('wc_get_product')) {
                continue;
            }
            $product = wc_get_product($wc_id);
            if (!$product) {
                continue;
            }
            $catalog_price = (float) $product->get_price();
            if ($catalog_price <= 0) {
                $catalog_price = (float) $product->get_regular_price();
            }
            $qty = isset($row['cantidad']) ? (float) $row['cantidad'] : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $products[] = array(
                'product_id' => $wc_id,
                'sku' => (string) ($product->get_sku() ?: ($row['sku_match'] ?? '')),
                'supplier_code' => (string) ($row['codigo_proveedor'] ?? ''),
                'barcode' => (string) ($row['codigo_barras'] ?? ''),
                'description' => (string) ($row['descripcion'] ?: $product->get_name()),
                'quantity' => $qty,
                'unit_price' => $catalog_price,
                'unit_cost' => isset($row['costo_neto']) ? (float) $row['costo_neto'] : null,
            );
        }
        if (!$products) {
            $this->fail('Ninguna línea seleccionada es importable (requiere match WC).');
        }

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'customer_quote.import_received',
                'customer_quote',
                0,
                null,
                array(
                    'received_quote_id' => $received_id,
                    'item_ids' => $item_ids,
                    'imported_count' => count($products),
                ),
                'Importar líneas desde cotización recibida #' . $received_id
            );
        }

        $this->ok(array(
            'products' => $products,
            'received_quote_id' => $received_id,
            'message' => count($products) . ' línea(s) listas para agregar.',
        ));
    }


    /**
     * Proxy: quick-view lookup (Local canónico).
     */
    public function ajax_products_quick_lookup() {
        $this->authorize();
        $this->ensure_quick_view_loaded();
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            $this->fail('Servicio quick-view no disponible.');
        }
        $code = $this->post_string('code');
        if ($code === '') {
            $code = $this->post_string('q');
        }
        $items = Riverso_Product_Quick_View_Service::get_instance()->lookup_for_quotes($code, 20);
        $products = array();
        foreach (is_array($items) ? $items : array() as $hit) {
            $mapped = $this->catalog_map_local_hit($hit);
            if ($mapped !== null) {
                $products[] = $mapped;
            }
        }
        $this->ok(array('products' => $products, 'items' => $items, 'channel' => 'local'));
    }

    public function ajax_products_quick_search() {
        $this->authorize();
        $this->ensure_quick_view_loaded();
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            $this->fail('Servicio quick-view no disponible.');
        }
        $term = $this->post_string('term');
        if ($term === '') {
            $term = $this->post_string('q');
        }
        $field = $this->post_string('field');
        if ($field === '') {
            $field = 'todos';
        }
        $items = Riverso_Product_Quick_View_Service::get_instance()->search_for_quotes($term, $field, 20);
        $products = array();
        foreach (is_array($items) ? $items : array() as $hit) {
            $mapped = $this->catalog_map_local_hit($hit);
            if ($mapped !== null) {
                $products[] = $mapped;
            }
        }
        $this->ok(array('products' => $products, 'items' => $items, 'channel' => 'local', 'field' => $field));
    }

    public function ajax_products_quick_summary() {
        $this->authorize();
        $this->ensure_quick_view_loaded();
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            $this->fail('Servicio quick-view no disponible.');
        }
        $id = (int) $this->post_string('producto_base_id');
        if ($id <= 0) {
            $id = (int) $this->post_string('id');
        }
        $summary = Riverso_Product_Quick_View_Service::get_instance()->build_summary($id);
        if (is_wp_error($summary)) {
            $this->fail($summary->get_error_message());
        }
        $this->ok(array('summary' => $summary));
    }

    /**
     * Proxy pricing: op=local_price|online_price|family_offers
     */
    public function ajax_pricing() {
        $this->authorize();
        $op = strtolower($this->post_string('op'));
        $pb = (int) $this->post_string('producto_base_id');
        $qty = (float) $this->post_string('family_qty');
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if ($op === '' || $op === 'local_price') {
            if ($pb <= 0) {
                $this->fail('producto_base_id requerido');
            }
            $this->ok(array('pricing' => $this->catalog->local_price_pack($pb, $qty)));
        }
        if ($op === 'online_price') {
            if ($pb <= 0 || !class_exists('Riverso_Pricing_Module')) {
                $this->fail('producto_base_id / pricing no disponible');
            }
            $var = (int) $this->post_string('woocommerce_variation_id');
            $row = Riverso_Pricing_Module::get_instance()->get_online_price($pb, $var);
            $this->ok(array('pricing' => $row));
        }
        if ($op === 'family_offers') {
            if ($pb <= 0) {
                $this->fail('producto_base_id requerido');
            }
            $this->ok(array('family' => $this->catalog->family_offers_for_base($pb)));
        }
        $this->fail('op inválido (local_price|online_price|family_offers)');
    }

    /**
     * Proxy families (solo lectura): op=exacta|commercial
     * No create_product comercial desde cotización.
     */
    public function ajax_families() {
        $this->authorize();
        $op = strtolower($this->post_string('op'));
        if ($op === '' || $op === 'exacta') {
            $pb = (int) $this->post_string('producto_base_id');
            if ($pb <= 0 || !class_exists('Riverso_Family_Module')) {
                $this->fail('producto_base_id / families no disponible');
            }
            $fam = Riverso_Family_Module::get_instance()->get_exacta_family_of_product($pb);
            $this->ok(array('family' => $fam, 'offers' => $this->catalog->family_offers_for_base($pb)));
        }
        if ($op === 'commercial') {
            $grupo = (int) $this->post_string('grupo_id');
            if ($grupo <= 0 || !class_exists('Riverso_Family_Commercial_Service')) {
                $this->fail('grupo_id / commercial no disponible');
            }
            $snap = Riverso_Family_Commercial_Service::get_instance()->get_snapshot($grupo);
            if (is_wp_error($snap)) {
                $this->fail($snap->get_error_message());
            }
            $this->ok(array('commercial' => $snap));
        }
        $this->fail('op inválido (exacta|commercial). create_product fuera de alcance.');
    }

    /**
     * Proxy unit-product (lectura): op=snapshot|resolve|pack_members|envase
     */
    public function ajax_unit_product() {
        $this->authorize();
        if (!class_exists('Riverso_Unit_Product_Service')) {
            $this->fail('Unit product service no disponible');
        }
        $svc = Riverso_Unit_Product_Service::get_instance();
        $op = strtolower($this->post_string('op'));
        if ($op === '' || $op === 'resolve') {
            $pb = (int) $this->post_string('producto_base_id');
            $ctx = $svc->resolve_family_unit_for_base($pb);
            $this->ok(array('unit' => $ctx, 'offers' => $this->catalog->family_offers_for_base($pb)));
        }
        if ($op === 'snapshot') {
            $grupo = (int) $this->post_string('grupo_id');
            $snap = $svc->get_unit_snapshot($grupo);
            if (is_wp_error($snap)) {
                $this->fail($snap->get_error_message());
            }
            $this->ok(array('snapshot' => $snap));
        }
        if ($op === 'pack_members') {
            $grupo = (int) $this->post_string('grupo_id');
            $this->ok(array('members' => $svc->get_pack_members_by_qty($grupo)));
        }
        if ($op === 'envase') {
            $pb = (int) $this->post_string('producto_base_id');
            $this->ok(array('envase' => $svc->get_canonical_envase($pb)));
        }
        $this->fail('op inválido (resolve|snapshot|pack_members|envase)');
    }

    /**
     * Proxy tienda-local search (legacy help).
     */
    public function ajax_tienda_local() {
        $this->authorize();
        if (!class_exists('Riverso_Tienda_Local_Module')) {
            $this->fail('Tienda local no disponible');
        }
        $q = $this->post_string('q');
        if ($q === '') {
            $q = $this->post_string('query');
        }
        $result = Riverso_Tienda_Local_Module::get_instance()->search($q);
        $this->ok(array('result' => $result, 'channel' => 'local'));
    }

    /**
     * Recalc precio familia (Local) como POS rule_price / recalc_family_price.
     */
    public function ajax_family_price() {
        $this->authorize();
        $pb = (int) $this->post_string('producto_base_id');
        $qty = (float) $this->post_string('family_qty');
        if ($qty <= 0) {
            $qty = (float) $this->post_string('qty');
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if ($pb <= 0) {
            $pid = (int) $this->post_string('product_id');
            if ($pid > 0 && class_exists('Riverso_Pricing_Module')) {
                $pb = (int) Riverso_Pricing_Module::get_instance()->get_base_id_by_wc($pid, 0);
            }
        }
        if ($pb <= 0) {
            $this->fail('producto_base_id requerido');
        }
        $p_ref_raw = $this->post_string('p_ref');
        $p_override = null;
        if ($p_ref_raw !== '' && is_numeric($p_ref_raw)) {
            $p_override = (float) $p_ref_raw;
            if ($p_override <= 0) {
                $p_override = null;
            }
        }
        $rule_mode = strtolower(trim($this->post_string('rule_mode')));
        if (!in_array($rule_mode, array('auto', 'std', 'manual', 'ref'), true)) {
            $rule_mode = 'auto';
        }
        $pack = $this->catalog->local_price_pack($pb, $qty, $p_override, $rule_mode);
        $offers = $this->catalog->family_offers_for_base($pb);

        $by_total = null;
        $target_total = (float) $this->post_string('target_total');
        if ($target_total > 0) {
            $upp = (float) $this->post_string('units_per_pack');
            if ($upp <= 0) {
                $upp = 1.0;
            }
            $others = (float) $this->post_string('others_units');
            if ($others < 0) {
                $others = 0.0;
            }
            $by_total = $this->catalog->qty_for_amount($pb, $target_total, array(
                'rule_mode' => $rule_mode,
                'p_ref' => $p_override,
                'units_per_pack' => $upp,
                'others_units' => $others,
            ));
            $by_total['target_total'] = $target_total;
        }

        $this->ok(array(
            'pricing' => $pack,
            'family' => $offers,
            'unit_price' => $pack['unit_price'],
            'rule_total' => isset($pack['rule_total']) ? $pack['rule_total'] : null,
            'rule_adjusted' => !empty($pack['rule_adjusted']),
            'unitario0' => isset($pack['unitario0']) ? $pack['unitario0'] : null,
            'has_rule' => !empty($pack['has_rule']),
            'rule_codigo' => isset($pack['rule_codigo']) ? $pack['rule_codigo'] : null,
            'rule_nombre' => isset($pack['rule_nombre']) ? $pack['rule_nombre'] : null,
            'assigned_rule_codigo' => isset($pack['assigned_rule_codigo']) ? $pack['assigned_rule_codigo'] : null,
            'std_rule' => isset($pack['std_rule']) ? $pack['std_rule'] : null,
            'p_asignado' => isset($pack['p_asignado']) ? $pack['p_asignado'] : null,
            'producto_base_id' => $pb,
            'family_qty' => $qty,
            'p_ref' => $p_override,
            'rule_mode' => $rule_mode,
            'by_total' => $by_total,
        ));
    }

    /**
     * Proxy: buscar clientes comerciales (nonce cotizaciones).
     */
    public function ajax_customers_search() {
        $this->authorize();
        $repo = $this->customer_repo();
        if (!$repo) {
            $this->fail('Módulo de clientes no disponible.');
        }

        $nombre = $this->post_string('nombre');
        $rut = $this->post_string('rut');
        $razon_social = $this->post_string('razon_social');
        if ($nombre === '' && $rut === '' && $razon_social === '') {
            $this->fail('Por favor seleccione parámetros para realizar la búsqueda');
        }

        $result = $repo->list_customers(
            array(
                'status' => 'active',
                'nombre' => $nombre,
                'rut' => $rut,
                'razon_social' => $razon_social,
            ),
            1,
            20
        );

        $customers = array();
        foreach ($result['items'] as $row) {
            $customers[] = array(
                'id' => (int) ($row['id'] ?? 0),
                'nombre_fantasia' => (string) ($row['nombre_fantasia'] ?? ''),
                'rut' => (string) ($row['rut'] ?? ''),
                'razon_social' => (string) ($row['razon_social'] ?? ''),
                'es_cliente' => true,
                'contacto_email' => (string) ($row['contacto_email'] ?? ''),
                'contacto_telefono' => (string) ($row['contacto_telefono'] ?? ''),
                'facturacion_telefono' => (string) ($row['facturacion_telefono'] ?? ''),
            );
        }

        $this->ok(array(
            'customers' => $customers,
            'total' => (int) ($result['total'] ?? 0),
        ));
    }

    /**
     * Proxy: crear cliente comercial desde cotización (nonce cotizaciones).
     */
    public function ajax_customer_save() {
        $this->authorize();
        $can_create = current_user_can('riverso_edit_customers')
            || current_user_can('riverso_create_quotes')
            || current_user_can('riverso_edit_quotes')
            || current_user_can('manage_options');
        if (!$can_create) {
            $this->fail('Sin permisos para crear clientes', 403);
        }

        $this->ensure_customer_module_loaded();
        if (!class_exists('Riverso_Customer_Module')) {
            $this->fail('Módulo de clientes no disponible.');
        }

        $module = Riverso_Customer_Module::get_instance();
        $input = $module->input_from_request();
        // Desde cotización siempre se crea (no editar).
        $input['id'] = 0;

        $repo = $this->customer_repo();
        if (!$repo) {
            $this->fail('Módulo de clientes no disponible.');
        }

        $result = $repo->save($input);
        if (empty($result['ok'])) {
            $this->fail(isset($result['message']) ? (string) $result['message'] : 'No se pudo guardar');
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('customer_created', 'customer', (int) $result['id'], array(
                'entity_name' => $input['nombre_fantasia'],
                'source' => 'customer_quote',
            ));
        }

        $this->ok(array(
            'message' => $result['message'] ?? 'Cliente creado',
            'id' => (int) $result['id'],
            'customer' => $result['customer'] ?? null,
        ));
    }

    /**
     * @return Riverso_Customer_Repository|null
     */
    private function customer_repo() {
        $this->ensure_customer_module_loaded();
        if (!class_exists('Riverso_Customer_Repository')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-repository.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Customer_Repository')) {
            return null;
        }
        return new Riverso_Customer_Repository();
    }

    private function ensure_customer_module_loaded() {
        if (class_exists('Riverso_Customer_Module')) {
            return;
        }
        $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-module.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private function ensure_quick_view_loaded() {
        if (class_exists('Riverso_Product_Quick_View_Service')) {
            return;
        }
        $path = RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-quick-view-service.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    /**
     * @param array $hit
     * @return array|null
     */
    private function catalog_map_local_hit(array $hit) {
        // Reusa map vía search_local interno: construir producto mínimo.
        $pb = isset($hit['id']) ? (int) $hit['id'] : 0;
        if ($pb <= 0) {
            return null;
        }
        $sku = trim((string) (isset($hit['canonical_sku']) ? $hit['canonical_sku'] : ''));
        if ($sku === '') {
            return null;
        }
        $rows = $this->catalog->search(
            isset($hit['canonical_sku']) ? (string) $hit['canonical_sku'] : (string) $pb,
            1,
            'quick',
            'codigos',
            'local'
        );
        if ($rows) {
            return $rows[0];
        }
        $price = $this->catalog->local_price_pack($pb, 1.0);
        $wc = $this->catalog->resolve_wc_product_id($pb);
        return array(
            'product_id' => $wc > 0 ? $wc : null,
            'producto_base_id' => $pb,
            'sku' => isset($hit['canonical_sku']) ? (string) $hit['canonical_sku'] : ('PB-' . $pb),
            'barcode' => isset($hit['barcode']) ? (string) $hit['barcode'] : '',
            'supplier_code' => isset($hit['codigo_proveedor']) ? (string) $hit['codigo_proveedor'] : '',
            'description' => isset($hit['nombre']) ? (string) $hit['nombre'] : '',
            'unit_price' => $price['unit_price'],
            'unit_cost' => $price['unit_cost'],
            'channel' => 'local',
            'local_only' => $wc <= 0,
            'family' => $this->catalog->family_offers_for_base($pb),
            'facturar_ok' => $wc > 0,
        );
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
            channel VARCHAR(16) NOT NULL DEFAULT 'local',
            issue_date DATE DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_quote_number (quote_number),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_quote_type (quote_type),
            KEY idx_cq_order_id (order_id),
            KEY idx_cq_channel (channel),
            KEY idx_cq_issue_date (issue_date),
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
            producto_base_id BIGINT(20) UNSIGNED DEFAULT NULL,
            family_mode VARCHAR(32) DEFAULT NULL,
            packaging VARCHAR(64) DEFAULT NULL,
            units_per_pack DECIMAL(14,4) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_quote (quote_id),
            KEY idx_product (product_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
        return true;
    }


    private function authorize_invoice() {
        $nonce = $this->post_string('nonce');
        if (!wp_verify_nonce($nonce, 'riverso_customer_quotes')) {
            $this->fail('No tienes permiso para facturar.', 403);
        }
        $quotes_ok = current_user_can('riverso_create_quotes')
            || current_user_can('riverso_edit_quotes')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
        $orders_ok = current_user_can('riverso_create_orders')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
        if (!$quotes_ok || !$orders_ok) {
            $this->fail('Se requieren permisos de cotizaciones y de crear pedidos.', 403);
        }
    }

    private function authorize() {
        $nonce = $this->post_string('nonce');
        if (!$this->user_can() || !wp_verify_nonce($nonce, 'riverso_customer_quotes')) {
            $this->fail('No tienes permiso para cotizar.', 403);
        }
    }

    /**
     * Autorización GET/POST para el documento PDF.
     */
    private function authorize_request() {
        $nonce = $this->request_string('nonce');
        if (!$this->user_can() || !wp_verify_nonce($nonce, 'riverso_customer_quotes')) {
            $this->fail_html('No tienes permiso para cotizar.', 403);
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

    private function request_string($key) {
        if (isset($_POST[$key])) {
            return $this->post_string($key);
        }
        if (!isset($_GET[$key])) {
            return '';
        }
        $value = wp_unslash($_GET[$key]);
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field($value);
    }

    private function ok(array $data) {
        wp_send_json_success($data);
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function present_quote_with_docs(array $quote) {
        $id = (int) ($quote['id'] ?? 0);
        $docs = $this->list_associated_documents($id);
        if ($docs && $this->quotes->sync_document_status($id)) {
            $fresh = $this->quotes->find($id);
            if (is_array($fresh)) {
                $quote = $fresh;
            }
        }
        $sales = $id > 0 ? $this->quotes->attach_comparisons($this->quotes->sale_summaries(array($id))) : array();
        $quote['sale'] = isset($sales[$id]) ? $sales[$id] : null;
        if ($quote['sale'] !== null) {
            unset($quote['sale']['document_draft_ids']);
            $quote['status_label'] = Riverso_Customer_Quote_Repository::status_label_with_changes(
                (string) ($quote['status'] ?? ''),
                (string) ($quote['status_label'] ?? ''),
                $quote['sale']
            );
        }
        if ($docs) {
            // Con documento de venta el estado lo maneja la facturación.
            $quote['allowed_transitions'] = array();
            $quote['is_expired'] = false;
        }
        $quote = $this->catalog->hydrate_quote_families($quote);
        $quote['associated_documents'] = $docs;
        return $quote;
    }

    /**
     * @param int $quote_id
     * @return array<int, array<string, mixed>>
     */
    private function list_associated_documents($quote_id) {
        $quote_id = absint($quote_id);
        if ($quote_id <= 0) {
            return [];
        }

        $draft_path = RIVERSO_POS_PLUGIN_DIR . 'sales/billing/class-billing-draft-repository.php';
        $dte_path = RIVERSO_POS_PLUGIN_DIR . 'sales/billing/class-dte-issued-repository.php';
        if (!class_exists('Riverso_Billing_Draft_Repository') && file_exists($draft_path)) {
            require_once $draft_path;
        }
        if (!class_exists('Riverso_Dte_Issued_Repository') && file_exists($dte_path)) {
            require_once $dte_path;
        }

        $drafts = [];
        $issued = [];
        if (class_exists('Riverso_Billing_Draft_Repository')) {
            $drafts = (new Riverso_Billing_Draft_Repository())->list_by_quote($quote_id);
        }
        if (class_exists('Riverso_Dte_Issued_Repository')) {
            $issued = (new Riverso_Dte_Issued_Repository())->list_by_quote($quote_id);
        }

        $covered = [];
        $out = [];
        foreach ($drafts as $d) {
            if (!is_array($d)) {
                continue;
            }
            $dte_id = !empty($d['dte_id']) ? (int) $d['dte_id'] : 0;
            if ($dte_id > 0) {
                $covered[$dte_id] = true;
            }
            $out[] = $this->present_associated_document($d);
        }
        foreach ($issued as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($covered[$id])) {
                continue;
            }
            $out[] = $this->present_associated_document([
                'draft_id' => null,
                'dte_id' => $id,
                'document_type_id' => (int) ($row['document_type_id'] ?? 37),
                'status' => !empty($row['folio']) ? 'emitted' : 'draft',
                'folio' => (string) ($row['folio'] ?? ''),
            ]);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function present_associated_document(array $doc) {
        $type_id = (int) ($doc['document_type_id'] ?? 37);
        $type = $this->associated_document_type_label($type_id);
        $status = (string) ($doc['status'] ?? 'draft');
        $folio = trim((string) ($doc['folio'] ?? ''));
        $emitted = $status === 'emitted' || ($folio !== '' && !empty($doc['dte_id']));
        if ($emitted) {
            $status_word = 'emitida';
        } elseif ($status === 'closed_local') {
            $status_word = 'cerrada';
        } else {
            $status_word = 'borrador';
        }
        $label = $type . ' ' . $status_word;
        if ($folio !== '') {
            $label .= ' (N°' . $folio . ')';
        }
        return [
            'draft_id' => !empty($doc['draft_id']) ? (int) $doc['draft_id'] : null,
            'dte_id' => !empty($doc['dte_id']) ? (int) $doc['dte_id'] : null,
            'document_type_id' => $type_id,
            'status' => $emitted ? 'emitted' : $status,
            'folio' => $folio,
            'label' => $label,
        ];
    }

    /**
     * @param int $type_id
     * @return string
     */
    private function associated_document_type_label($type_id) {
        $type_id = (int) $type_id;
        if ($type_id === 2) {
            return 'Factura electrónica';
        }
        if ($type_id === 37) {
            return 'Boleta electrónica';
        }
        if (class_exists('Riverso_Billing_Totals') && method_exists('Riverso_Billing_Totals', 'type_label')) {
            return Riverso_Billing_Totals::type_label($type_id);
        }
        return 'Documento';
    }

    private function fail($message, $status = 400) {
        status_header($status);
        wp_send_json_error(array('message' => $message), $status);
    }

    private function fail_html($message, $status = 400) {
        status_header($status);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        $safe = htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="es-CL"><head><meta charset="utf-8"><title>Error</title></head>';
        echo '<body><p>' . $safe . '</p></body></html>';
        exit;
    }
}