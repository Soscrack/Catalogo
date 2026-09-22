<?php
/**
 * Módulo Centro de Precios — historial, explorador, análisis por folio.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Price_History_Module {

    private static $instance = null;

    /** @var string */
    private $table_history;

    /** @var string */
    private $table_folios;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_history = $wpdb->prefix . 'riverso_precio_historial';
        $this->table_folios = $wpdb->prefix . 'riverso_precio_folio_analisis';
        $this->ensure_lookup();
        $this->init_hooks();
    }

    private function ensure_lookup() {
        if (!class_exists('Riverso_Price_Lookup_Service')) {
            $path = dirname(__FILE__) . '/class-price-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Folio_Price_Process_Service')) {
            $path = dirname(__FILE__) . '/class-folio-price-process-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function lookup() {
        $this->ensure_lookup();
        return class_exists('Riverso_Price_Lookup_Service')
            ? Riverso_Price_Lookup_Service::get_instance()
            : null;
    }

    private function folio_process() {
        $this->ensure_lookup();
        return class_exists('Riverso_Folio_Price_Process_Service')
            ? Riverso_Folio_Price_Process_Service::get_instance()
            : null;
    }

    private function pricing() {
        return class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()
            : null;
    }

    private function cost_lookup() {
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Riverso_Cost_Lookup_Service')
            ? Riverso_Cost_Lookup_Service::get_instance()
            : null;
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_price_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_riverso_price_get_explorer', [$this, 'ajax_get_explorer']);
        add_action('wp_ajax_riverso_price_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_riverso_price_add_entry', [$this, 'ajax_add_entry']);
        add_action('wp_ajax_riverso_price_copy_local_to_online', [$this, 'ajax_copy_local_to_online']);
        add_action('wp_ajax_riverso_price_set_online_active', [$this, 'ajax_set_online_active']);
        add_action('wp_ajax_riverso_price_save_assigned', [$this, 'ajax_save_assigned']);
        add_action('wp_ajax_riverso_price_search_invoices', [$this, 'ajax_search_invoices']);
        add_action('wp_ajax_riverso_price_analyze_invoice', [$this, 'ajax_analyze_invoice']);
        add_action('wp_ajax_riverso_price_list_recent_invoices', [$this, 'ajax_list_recent_invoices']);
        add_action('wp_ajax_riverso_price_list_analyzed_folios', [$this, 'ajax_list_analyzed_folios']);
        add_action('wp_ajax_riverso_price_alerts', [$this, 'ajax_alerts']);
        add_action('wp_ajax_riverso_price_search_products_manual', [$this, 'ajax_search_products']);
        add_action('wp_ajax_riverso_price_folio_process_list', [$this, 'ajax_folio_process_list']);
        add_action('wp_ajax_riverso_price_folio_process_start', [$this, 'ajax_folio_process_start']);
        add_action('wp_ajax_riverso_price_folio_process_get', [$this, 'ajax_folio_process_get']);
        add_action('wp_ajax_riverso_price_folio_process_save_line', [$this, 'ajax_folio_process_save_line']);
        add_action('wp_ajax_riverso_price_folio_process_complete', [$this, 'ajax_folio_process_complete']);
        add_action('wp_ajax_riverso_price_folio_process_set_estado', [$this, 'ajax_folio_process_set_estado']);
        add_action('wp_ajax_riverso_price_folio_process_archive', [$this, 'ajax_folio_process_archive']);
        add_action('wp_ajax_riverso_price_folio_process_family', [$this, 'ajax_folio_process_family']);
        add_action('wp_ajax_riverso_price_folio_family_map_code', [$this, 'ajax_folio_family_map_code']);
        add_action('wp_ajax_riverso_price_folio_process_hybrid_items', [$this, 'ajax_folio_process_hybrid_items']);
        add_action('wp_ajax_riverso_price_folio_process_start_hybrid', [$this, 'ajax_folio_process_start_hybrid']);
        add_action('wp_ajax_riverso_price_folio_process_update_omitidos', [$this, 'ajax_folio_process_update_omitidos']);
        add_action('wp_ajax_riverso_price_folio_create_local_and_link', [$this, 'ajax_folio_create_local_and_link']);
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$prefix}precio_folio_analisis (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            factura_id BIGINT UNSIGNED NOT NULL,
            folio VARCHAR(50) DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            fecha_emision DATE DEFAULT NULL,
            resumen_json LONGTEXT DEFAULT NULL,
            analyzed_by BIGINT UNSIGNED DEFAULT NULL,
            analyzed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_factura (factura_id),
            KEY idx_folio (folio),
            KEY idx_analyzed_at (analyzed_at)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}precio_folio_proceso (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            factura_id BIGINT UNSIGNED NOT NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'pendiente',
            estado_manual VARCHAR(40) NULL DEFAULT NULL,
            blockers_json LONGTEXT DEFAULT NULL,
            items_omitidos_json LONGTEXT DEFAULT NULL,
            started_by BIGINT UNSIGNED DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_factura (factura_id),
            KEY idx_estado (estado),
            KEY idx_estado_manual (estado_manual)
        ) $charset_collate;";
        dbDelta($sql);

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$prefix}precio_folio_proceso LIKE 'items_omitidos_json'");
        if (empty($col)) {
            $wpdb->query(
                "ALTER TABLE {$prefix}precio_folio_proceso
                 ADD COLUMN items_omitidos_json LONGTEXT DEFAULT NULL
                 COMMENT 'IDs factura_items ya ingresados (híbrido)' AFTER blockers_json"
            );
        }

        if (class_exists('Riverso_Pricing_Module') && method_exists('Riverso_Pricing_Module', 'ensure_en_uso_column')) {
            Riverso_Pricing_Module::ensure_en_uso_column();
        }

        return true;
    }

    public function get_stats() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $entries_this_month = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_history}
             WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
        $products_tracked = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT producto_base_id) FROM {$this->table_history}"
        );
        $margin_alerts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$prefix}precios WHERE alerta_margen = 1"
        );
        $folios_analyzed = 0;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_folios)) === $this->table_folios) {
            $folios_analyzed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_folios}");
        }

        return [
            'entries_this_month' => $entries_this_month,
            'products_tracked' => $products_tracked,
            'margin_alerts' => $margin_alerts,
            'folios_analyzed' => $folios_analyzed,
        ];
    }

    public function get_history($args = []) {
        global $wpdb;

        $defaults = [
            'producto_base_id' => null,
            'canal' => '',
            'source_type' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $allowed_order = ['created_at', 'p_asignado_nuevo', 'canal', 'source_type'];
        $orderby = in_array($args['orderby'], $allowed_order, true) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        [$where_sql, $values] = $this->history_where($args);
        $limit = max(1, min(100, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);

        $sql = "SELECT h.*, pb.canonical_sku, pb.nombre_canonico, u.display_name AS usuario_nombre
             FROM {$this->table_history} h
             LEFT JOIN {$wpdb->prefix}riverso_producto_base pb ON pb.id = h.producto_base_id
             LEFT JOIN {$wpdb->users} u ON u.ID = h.usuario_id
             WHERE {$where_sql}
             ORDER BY h.{$orderby} {$order}
             LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) ?: [];
    }

    public function get_history_count($args = []) {
        global $wpdb;
        [$where_sql, $values] = $this->history_where($args);
        $sql = "SELECT COUNT(*)
             FROM {$this->table_history} h
             LEFT JOIN {$wpdb->prefix}riverso_producto_base pb ON pb.id = h.producto_base_id
             WHERE {$where_sql}";
        if ($values) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
        }
        return (int) $wpdb->get_var($sql);
    }

    private function history_where($args) {
        $where = ['1=1'];
        $values = [];

        if (!empty($args['producto_base_id'])) {
            $where[] = 'h.producto_base_id = %d';
            $values[] = absint($args['producto_base_id']);
        }
        if (!empty($args['canal'])) {
            $where[] = 'h.canal = %s';
            $values[] = sanitize_text_field($args['canal']);
        }
        if (!empty($args['source_type'])) {
            $where[] = 'h.source_type = %s';
            $values[] = sanitize_text_field($args['source_type']);
        }
        if (!empty($args['date_from'])) {
            $where[] = 'h.created_at >= %s';
            $values[] = sanitize_text_field($args['date_from']) . ' 00:00:00';
        }
        if (!empty($args['date_to'])) {
            $where[] = 'h.created_at <= %s';
            $values[] = sanitize_text_field($args['date_to']) . ' 23:59:59';
        }
        if (!empty($args['search'])) {
            $like = '%' . $GLOBALS['wpdb']->esc_like($args['search']) . '%';
            $where[] = '(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR h.notas LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        return [implode(' AND ', $where), $values];
    }

    public function save_folio_analysis($factura_id, $payload) {
        global $wpdb;
        $factura_id = intval($factura_id);
        if ($factura_id <= 0) {
            return new WP_Error('invalid', 'factura_id requerido');
        }

        $invoice = $payload['invoice'] ?? [];
        $row = [
            'factura_id' => $factura_id,
            'folio' => $invoice['folio'] ?? '',
            'proveedor_id' => intval($invoice['proveedor_id'] ?? 0) ?: null,
            'fecha_emision' => $invoice['fecha_emision'] ?? null,
            'resumen_json' => wp_json_encode($payload),
            'analyzed_by' => get_current_user_id(),
            'analyzed_at' => current_time('mysql'),
        ];

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_folios} WHERE factura_id = %d",
            $factura_id
        ));

        if ($existing) {
            $wpdb->update($this->table_folios, $row, ['id' => (int) $existing]);
            return (int) $existing;
        }

        $wpdb->insert($this->table_folios, $row);
        return (int) $wpdb->insert_id;
    }

    public function list_analyzed_folios($args = []) {
        global $wpdb;
        $page = max(1, intval($args['page'] ?? 1));
        $per_page = max(10, min(100, intval($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        $search = trim((string) ($args['search'] ?? ''));

        $where = ['1=1'];
        $values = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(a.folio LIKE %s OR p.nombre LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*)
             FROM {$this->table_folios} a
             LEFT JOIN {$wpdb->prefix}riverso_proveedores p ON p.id = a.proveedor_id
             WHERE {$where_sql}";
        $total = $values
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $values))
            : (int) $wpdb->get_var($count_sql);

        $sql = "SELECT a.*, p.nombre AS proveedor_nombre, u.display_name AS analyzed_by_name
             FROM {$this->table_folios} a
             LEFT JOIN {$wpdb->prefix}riverso_proveedores p ON p.id = a.proveedor_id
             LEFT JOIN {$wpdb->users} u ON u.ID = a.analyzed_by
             WHERE {$where_sql}
             ORDER BY a.analyzed_at DESC
             LIMIT %d OFFSET %d";
        $list_values = array_merge($values, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $list_values), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $json = json_decode($row['resumen_json'] ?? '', true);
            $row['summary'] = is_array($json) ? ($json['summary'] ?? []) : [];
            unset($row['resumen_json']);
            $row['payload'] = $json;
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ];
    }

    public function get_analyzed_folio($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_folios} WHERE id = %d",
            intval($id)
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $row['payload'] = json_decode($row['resumen_json'] ?? '', true);
        return $row;
    }

    /* ===================== AJAX ===================== */

    private function check_view() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function check_manage() {
        $this->check_view();
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    public function ajax_search_products() {
        $this->check_view();
        $svc = $this->lookup();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? $_POST['q'] ?? ''));
        wp_send_json_success(['results' => $svc->search($term)]);
    }

    public function ajax_get_explorer() {
        $this->check_view();
        $svc = $this->lookup();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $id = absint($_POST['producto_base_id'] ?? 0);
        $result = $svc->build_explorer_payload($id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_get_history() {
        $this->check_view();
        $page = max(1, intval($_POST['page'] ?? 1));
        $limit = 50;
        $args = [
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'canal' => sanitize_text_field($_POST['canal'] ?? ''),
            'source_type' => sanitize_text_field($_POST['source_type'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'orderby' => sanitize_text_field($_POST['orderby'] ?? 'created_at'),
            'order' => sanitize_text_field($_POST['order'] ?? 'DESC'),
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ];
        $rows = $this->get_history($args);
        $total = $this->get_history_count($args);
        wp_send_json_success([
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $limit)),
        ]);
    }

    public function ajax_add_entry() {
        $this->check_manage();
        $pricing = $this->pricing();
        if (!$pricing) {
            wp_send_json_error(['message' => 'Módulo de precios no disponible']);
        }
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $canal = sanitize_text_field($_POST['canal'] ?? 'local');
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);
        $notas = sanitize_textarea_field(wp_unslash($_POST['notas'] ?? ''));
        if (!$producto_base_id || $p_asignado <= 0) {
            wp_send_json_error(['message' => 'Producto y precio requeridos']);
        }
        $meta = [
            'source_type' => 'manual',
            'notas' => $notas,
        ];
        if ($canal === 'online') {
            $meta['en_uso'] = 0;
        }
        $result = $pricing->upsert_assigned_price($producto_base_id, $canal, $p_asignado, 0, $meta);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['price' => $result]);
    }

    public function ajax_save_assigned() {
        $this->check_manage();
        $pricing = $this->pricing();
        if (!$pricing) {
            wp_send_json_error(['message' => 'Módulo de precios no disponible']);
        }
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $canal = sanitize_text_field($_POST['canal'] ?? 'local');
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);
        if (!$producto_base_id || $p_asignado <= 0) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }
        $meta = ['source_type' => 'manual'];
        if ($canal === 'online') {
            $meta['en_uso'] = 0;
        }
        $result = $pricing->upsert_assigned_price($producto_base_id, $canal, $p_asignado, 0, $meta);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $payload = $this->lookup()->build_explorer_payload($producto_base_id);
        wp_send_json_success(['price' => $result, 'explorer' => $payload]);
    }

    public function ajax_copy_local_to_online() {
        $this->check_manage();
        $pricing = $this->pricing();
        $id = absint($_POST['producto_base_id'] ?? 0);
        $result = $pricing->copy_local_to_online($id, absint($_POST['woocommerce_variation_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $payload = $this->lookup()->build_explorer_payload($id);
        wp_send_json_success(['price' => $result, 'explorer' => $payload]);
    }

    public function ajax_set_online_active() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_approve_prices') && !current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $pricing = $this->pricing();
        $id = absint($_POST['producto_base_id'] ?? 0);
        $sync = !empty($_POST['sync_woo']);
        $result = $pricing->set_online_active($id, absint($_POST['woocommerce_variation_id'] ?? 0), $sync);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $payload = $this->lookup()->build_explorer_payload($id);
        wp_send_json_success(['price' => $result, 'explorer' => $payload]);
    }

    public function ajax_search_invoices() {
        $this->check_view();
        $svc = $this->cost_lookup();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        wp_send_json_success(['results' => $svc->search_product_invoices($term)]);
    }

    public function ajax_analyze_invoice() {
        $this->check_view();
        $svc = $this->lookup();
        $factura_id = absint($_POST['factura_id'] ?? 0);
        $result = $svc->analyze_invoice_for_pricing($factura_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $this->save_folio_analysis($factura_id, $result);
        wp_send_json_success($result);
    }

    public function ajax_list_recent_invoices() {
        $this->check_view();
        $svc = $this->cost_lookup();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $page = max(1, absint($_POST['page'] ?? 1));
        $limit = 25;
        $result = $svc->list_recent_product_invoices([
            'date_field' => sanitize_text_field($_POST['date_field'] ?? 'fecha_emision'),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? ''),
            'proveedor_id' => absint($_POST['proveedor_id'] ?? 0),
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'order' => sanitize_text_field($_POST['order'] ?? 'DESC'),
            'orderby' => sanitize_text_field($_POST['orderby'] ?? 'fecha_emision'),
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ]);
        $result['page'] = $page;
        wp_send_json_success($result);
    }

    public function ajax_list_analyzed_folios() {
        $this->check_view();
        $id = absint($_POST['id'] ?? 0);
        if ($id) {
            $row = $this->get_analyzed_folio($id);
            if (!$row) {
                wp_send_json_error(['message' => 'Folio no encontrado']);
            }
            wp_send_json_success($row);
        }
        wp_send_json_success($this->list_analyzed_folios([
            'page' => absint($_POST['page'] ?? 1),
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
        ]));
    }

    public function ajax_alerts() {
        $this->check_view();
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT pr.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}precios pr
             INNER JOIN {$prefix}producto_base pb ON pb.id = pr.producto_base_id
             WHERE pr.alerta_margen = 1
             ORDER BY pr.updated_at DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];
        wp_send_json_success(['alerts' => $rows]);
    }

    public function ajax_folio_process_list() {
        $this->check_view();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        wp_send_json_success($svc->list_folios([
            'page' => absint($_POST['page'] ?? 1),
            'per_page' => absint($_POST['per_page'] ?? 25),
            'estado' => sanitize_key($_POST['estado'] ?? ''),
            'completitud' => sanitize_key($_POST['completitud'] ?? ''),
            'vista' => sanitize_key($_POST['vista'] ?? 'activos'),
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'producto' => sanitize_text_field(wp_unslash($_POST['producto'] ?? '')),
            'proveedor_id' => absint($_POST['proveedor_id'] ?? 0),
            'fecha_folio_desde' => sanitize_text_field(wp_unslash($_POST['fecha_folio_desde'] ?? '')),
            'fecha_folio_hasta' => sanitize_text_field(wp_unslash($_POST['fecha_folio_hasta'] ?? '')),
            'fecha_ingreso_desde' => sanitize_text_field(wp_unslash($_POST['fecha_ingreso_desde'] ?? '')),
            'fecha_ingreso_hasta' => sanitize_text_field(wp_unslash($_POST['fecha_ingreso_hasta'] ?? '')),
            'orderby' => sanitize_key($_POST['orderby'] ?? 'fecha_folio'),
            'order' => sanitize_key($_POST['order'] ?? 'DESC'),
        ]));
    }

    public function ajax_folio_process_start() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $factura_id = absint($_POST['factura_id'] ?? 0);
        $result = $svc->start_process($factura_id);
        if (is_wp_error($result)) {
            $session = $svc->get_session($factura_id);
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'blockers' => $result->get_error_data()['blockers'] ?? [],
                'session' => is_wp_error($session) ? null : $session,
                'code' => $result->get_error_code(),
            ]);
        }
        $session = $svc->get_session($factura_id);
        if (is_wp_error($session)) {
            wp_send_json_error(['message' => $session->get_error_message()]);
        }
        wp_send_json_success($session);
    }

    public function ajax_folio_process_get() {
        $this->check_view();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $session = $svc->get_session(absint($_POST['factura_id'] ?? 0));
        if (is_wp_error($session)) {
            wp_send_json_error(['message' => $session->get_error_message()]);
        }
        wp_send_json_success($session);
    }

    public function ajax_folio_process_save_line() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $p_online = isset($_POST['p_online']) && $_POST['p_online'] !== ''
            ? floatval($_POST['p_online'])
            : null;
        $amount_mode = sanitize_key($_POST['amount_mode'] ?? 'bruto');
        $result = $svc->save_line(
            absint($_POST['factura_id'] ?? 0),
            absint($_POST['item_id'] ?? 0),
            floatval($_POST['p_asignado'] ?? 0),
            $p_online,
            $amount_mode
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_folio_process_complete() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $factura_id = absint($_POST['factura_id'] ?? 0);
        $result = $svc->complete($factura_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['session' => $svc->get_session($factura_id)]);
    }

    public function ajax_folio_process_set_estado() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $result = $svc->set_estado_manual(
            absint($_POST['factura_id'] ?? 0),
            sanitize_key($_POST['estado'] ?? ''),
            sanitize_textarea_field(wp_unslash($_POST['notas'] ?? ''))
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['ok' => true, 'estado' => $svc->resolve_estado(absint($_POST['factura_id'] ?? 0))]);
    }

    public function ajax_folio_process_archive() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $factura_id = absint($_POST['factura_id'] ?? 0);
        $unarchive = !empty($_POST['unarchive']);
        $result = $unarchive
            ? $svc->unarchive_folio($factura_id)
            : $svc->archive_folio($factura_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success([
            'ok' => true,
            'archived' => !$unarchive,
            'estado' => $svc->resolve_estado($factura_id),
        ]);
    }

    public function ajax_folio_process_family() {
        $this->check_view();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $p = isset($_POST['p_asignado']) && $_POST['p_asignado'] !== ''
            ? floatval($_POST['p_asignado'])
            : null;
        $result = $svc->preview_family(absint($_POST['grupo_id'] ?? 0), $p);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_folio_family_map_code() {
        $this->check_view();
        if (!current_user_can('riverso_manage_products') && !current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Sin permisos para mapear códigos'], 403);
        }
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $result = $svc->map_family_code(
            absint($_POST['grupo_id'] ?? 0),
            sanitize_key($_POST['code_tipo'] ?? 'barcode'),
            absint($_POST['code_id'] ?? 0),
            sanitize_key($_POST['accion'] ?? 'move'),
            absint($_POST['destino_producto_base_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_folio_process_hybrid_items() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $result = $svc->get_hybrid_items(absint($_POST['factura_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_folio_process_start_hybrid() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $raw = $_POST['item_ids'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $item_ids = array_map('absint', $raw);
        $result = $svc->start_hybrid(absint($_POST['factura_id'] ?? 0), $item_ids);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_folio_process_update_omitidos() {
        $this->check_manage();
        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }
        $raw = $_POST['item_ids'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $item_ids = array_map('absint', $raw);
        $result = $svc->update_hybrid_omitidos(absint($_POST['factura_id'] ?? 0), $item_ids);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * AJAX 3a: crear producto local y vincular al ítem del folio.
     */
    public function ajax_folio_create_local_and_link() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos para crear productos'], 403);
        }
        if (!current_user_can('riverso_manage_codes') && !current_user_can('riverso_process_invoices')) {
            wp_send_json_error(['message' => 'Sin permisos para vincular códigos'], 403);
        }

        $svc = $this->folio_process();
        if (!$svc) {
            wp_send_json_error(['message' => 'Servicio no disponible']);
        }

        $factura_id = absint($_POST['factura_id'] ?? 0);
        $item_id = absint($_POST['item_id'] ?? 0);
        $nombre = sanitize_text_field(wp_unslash($_POST['nombre_canonico'] ?? ''));

        $result = $svc->create_local_and_link($factura_id, $item_id, $nombre);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error(array_merge([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ], is_array($data) ? $data : []));
        }

        $session = $svc->get_session($factura_id);
        wp_send_json_success([
            'result' => $result,
            'session' => is_wp_error($session) ? null : $session,
            'message' => $result['message'] ?? 'Producto creado y vinculado',
        ]);
    }
}
