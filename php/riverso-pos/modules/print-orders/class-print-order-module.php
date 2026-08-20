<?php
/**
 * Módulo de Órdenes de Impresión
 *
 * Gestiona el ciclo de vida de órdenes de etiquetas: crear, enviar,
 * aprobar, modificar, imprimir y cancelar. La impresión física se
 * ejecuta en el cliente vía RiversoLabelPrint / EtiquetadorRS.exe.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Print_Order_Module {

    private static $instance = null;

    private $table_orders;
    private $table_items;

    const ESTADOS = [
        'borrador'  => 'Borrador',
        'pendiente' => 'Pendiente',
        'aprobada'  => 'Aprobada',
        'impresa'   => 'Impresa',
        'cancelada' => 'Cancelada',
    ];

    const TIPOS = [
        'etiqueta_producto' => 'Etiqueta producto',
        'bolsa'             => 'Bolsa',
        'etiqueta_simple'   => 'Etiqueta simple',
        'etiqueta_logo'     => 'Etiqueta con logo',
    ];

    const MODOS = ['Bolsa', 'BolsaCOD', 'EtiquetaSimple', 'EtiquetaLogo', 'EtiquetaLogoPrecio'];
    const COLORES = ['BN', 'RN'];

    const EDITABLE_STATES = ['borrador', 'pendiente', 'aprobada'];

    const TIPO_MODO_DEFAULT = [
        'etiqueta_producto' => 'BolsaCOD',
        'bolsa'             => 'Bolsa',
        'etiqueta_simple'   => 'EtiquetaSimple',
        'etiqueta_logo'     => 'EtiquetaLogo',
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_orders = $wpdb->prefix . 'riverso_ordenes_impresion';
        $this->table_items  = $wpdb->prefix . 'riverso_orden_impresion_items';
    }

    public function init() {
        add_action('wp_ajax_riverso_print_orders_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_print_orders_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_print_orders_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_print_orders_update', [$this, 'ajax_update']);
        add_action('wp_ajax_riverso_print_orders_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_riverso_print_orders_add_item', [$this, 'ajax_add_item']);
        add_action('wp_ajax_riverso_print_orders_update_item', [$this, 'ajax_update_item']);
        add_action('wp_ajax_riverso_print_orders_remove_item', [$this, 'ajax_remove_item']);
        add_action('wp_ajax_riverso_print_orders_reorder_items', [$this, 'ajax_reorder_items']);
        add_action('wp_ajax_riverso_print_orders_submit', [$this, 'ajax_submit']);
        add_action('wp_ajax_riverso_print_orders_approve', [$this, 'ajax_approve']);
        add_action('wp_ajax_riverso_print_orders_return_draft', [$this, 'ajax_return_draft']);
        add_action('wp_ajax_riverso_print_orders_mark_printed', [$this, 'ajax_mark_printed']);
        add_action('wp_ajax_riverso_print_orders_cancel', [$this, 'ajax_cancel']);
        add_action('wp_ajax_riverso_print_orders_search', [$this, 'ajax_search']);
        add_action('wp_ajax_riverso_print_orders_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_riverso_print_orders_duplicate', [$this, 'ajax_duplicate']);
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $orders = $wpdb->prefix . 'riverso_ordenes_impresion';
        $items  = $wpdb->prefix . 'riverso_orden_impresion_items';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero_orden VARCHAR(20) NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
            tipo VARCHAR(30) NOT NULL DEFAULT 'etiqueta_producto',
            prioridad TINYINT(1) NOT NULL DEFAULT 0,
            notas TEXT DEFAULT NULL,
            solicitado_por BIGINT UNSIGNED DEFAULT NULL,
            solicitado_por_nombre VARCHAR(100) DEFAULT NULL,
            aprobado_por BIGINT UNSIGNED DEFAULT NULL,
            impreso_por BIGINT UNSIGNED DEFAULT NULL,
            impresora_nombre VARCHAR(100) DEFAULT NULL,
            impreso_en DATETIME DEFAULT NULL,
            cancelado_por BIGINT UNSIGNED DEFAULT NULL,
            cancelado_en DATETIME DEFAULT NULL,
            motivo_cancelacion TEXT DEFAULT NULL,
            total_items INT NOT NULL DEFAULT 0,
            total_copias INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY numero_orden (numero_orden),
            KEY estado (estado),
            KEY solicitado_por (solicitado_por),
            KEY created_at (created_at),
            KEY tipo (tipo)
        ) $charset_collate;");

        dbDelta("CREATE TABLE {$items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(50) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            precio DECIMAL(12,2) DEFAULT NULL,
            precio_original DECIMAL(12,2) DEFAULT NULL,
            cantidad_ean INT NOT NULL DEFAULT 100,
            copias INT NOT NULL DEFAULT 1,
            modo VARCHAR(30) NOT NULL DEFAULT 'BolsaCOD',
            color VARCHAR(10) NOT NULL DEFAULT 'BN',
            ean13 VARCHAR(13) DEFAULT NULL,
            impreso TINYINT(1) NOT NULL DEFAULT 0,
            orden_posicion INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY orden_id (orden_id),
            KEY sku (sku)
        ) $charset_collate;");

        $col = $wpdb->get_results("SHOW COLUMNS FROM {$items} LIKE 'precio_original'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE {$items} ADD precio_original DECIMAL(12,2) DEFAULT NULL AFTER precio");
        }

        return true;
    }

    private function require_cap($cap) {
        if (current_user_can($cap) || current_user_can('manage_options')) {
            return;
        }
        wp_send_json_error(['message' => 'Sin permisos'], 403);
    }

    private function require_view() {
        if (
            !current_user_can('riverso_view_print_orders')
            && !current_user_can('riverso_print_labels')
            && !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Sin permisos para ver órdenes de impresión'], 403);
        }
    }

    private function parse_items_from_request() {
        $items = $_POST['items'] ?? [];
        if (is_string($items)) {
            $decoded = json_decode(wp_unslash($items), true);
            $items = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($items)) {
            return [];
        }
        return array_values($items);
    }

    private function sanitize_item($data, $default_modo = 'BolsaCOD') {
        $sku = sanitize_text_field($data['sku'] ?? '');
        $nombre = sanitize_text_field($data['nombre'] ?? '');
        if ($sku === '' || $nombre === '') {
            return new WP_Error('invalid_item', 'Cada ítem requiere SKU y nombre');
        }

        $modo = sanitize_text_field($data['modo'] ?? $default_modo);
        if (!in_array($modo, self::MODOS, true)) {
            $modo = $default_modo;
        }

        $color = strtoupper(sanitize_text_field($data['color'] ?? 'BN'));
        if (!in_array($color, self::COLORES, true)) {
            $color = 'BN';
        }

        $ean13 = preg_replace('/\D+/', '', (string) ($data['ean13'] ?? ''));
        if ($ean13 !== '' && strlen($ean13) > 13) {
            $ean13 = substr($ean13, 0, 13);
        }

        $precio = $this->sanitize_precio($data['precio'] ?? null);
        $precio_original = $this->sanitize_precio($data['precio_original'] ?? null);

        return [
            'sku'             => substr($sku, 0, 50),
            'nombre'          => substr($nombre, 0, 255),
            'precio'          => $precio,
            'precio_original' => $precio_original,
            'cantidad_ean'    => max(1, min(99999, intval($data['cantidad_ean'] ?? 100))),
            'copias'        => max(1, min(9999, intval($data['copias'] ?? 1))),
            'modo'          => $modo,
            'color'         => $color,
            'ean13'         => $ean13 !== '' ? $ean13 : null,
            'orden_posicion'=> max(0, intval($data['orden_posicion'] ?? 0)),
        ];
    }

    private function can_edit_print_price() {
        return current_user_can('riverso_edit_print_order_price') || current_user_can('manage_options');
    }

    private function sanitize_precio($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $precio = round((float) $value, 2);
        if (!is_finite($precio) || $precio < 0) {
            return 0.0;
        }
        return $precio;
    }

    private function prices_equal($a, $b) {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return abs((float) $a - (float) $b) < 0.001;
    }

    private function is_rounding_of($incoming, $locked) {
        if ($incoming === null || $locked === null) {
            return false;
        }
        return abs((float) $incoming - (float) round((float) $locked)) < 0.001;
    }

    /**
     * SKU no se cambia. Precio solo con permiso especial, salvo redondear o desredondear.
     * Los demás campos (nombre, copias, modo, color, ean, cantidad) son de la impresión.
     */
    private function apply_item_identity_locks(array $item, $existing = null) {
        if (!is_array($existing)) {
            return $item;
        }
        $item['sku'] = $existing['sku'];
        if ($this->can_edit_print_price()) {
            return $item;
        }

        $locked = $this->sanitize_precio($existing['precio'] ?? null);
        $orig = $this->sanitize_precio($existing['precio_original'] ?? null);
        $incoming = $this->sanitize_precio($item['precio'] ?? null);

        $allowed = $this->prices_equal($incoming, $locked)
            || $this->is_rounding_of($incoming, $locked)
            || ($orig !== null && (
                $this->prices_equal($incoming, $orig)
                || $this->is_rounding_of($incoming, $orig)
            ));

        if (!$allowed) {
            $item['precio'] = $locked;
            $item['precio_original'] = $orig;
            return $item;
        }

        if ($orig !== null) {
            $item['precio_original'] = $orig;
        } elseif ($this->is_rounding_of($incoming, $locked)) {
            $item['precio_original'] = $locked;
        }

        return $item;
    }

    private function find_existing_item($orden_id, $raw, $items = null) {
        if ($items === null) {
            $items = $this->get_items($orden_id);
        }
        $raw_id = intval($raw['id'] ?? $raw['item_id'] ?? 0);
        if ($raw_id) {
            foreach ($items as $row) {
                if ((int) $row['id'] === $raw_id) {
                    return $row;
                }
            }
        }
        $sku = sanitize_text_field($raw['sku'] ?? '');
        if ($sku === '') {
            return null;
        }
        foreach ($items as $row) {
            if ($row['sku'] === $sku) {
                return $row;
            }
        }
        return null;
    }

    private function generate_order_number() {
        global $wpdb;
        $date = current_time('Ymd');
        $prefix_num = 'IMP-' . $date . '-';

        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT numero_orden FROM {$this->table_orders}
             WHERE numero_orden LIKE %s
             ORDER BY numero_orden DESC LIMIT 1",
            $prefix_num . '%'
        ));

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = intval($m[1]) + 1;
        }

        return $prefix_num . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function refresh_totals($orden_id) {
        global $wpdb;
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS items, COALESCE(SUM(copias), 0) AS copias
             FROM {$this->table_items} WHERE orden_id = %d",
            $orden_id
        ));

        $wpdb->update(
            $this->table_orders,
            [
                'total_items'  => (int) ($counts->items ?? 0),
                'total_copias' => (int) ($counts->copias ?? 0),
            ],
            ['id' => $orden_id],
            ['%d', '%d'],
            ['%d']
        );
    }

    private function insert_item($orden_id, array $item) {
        global $wpdb;
        $ok = $wpdb->insert(
            $this->table_items,
            [
                'orden_id'        => $orden_id,
                'sku'             => $item['sku'],
                'nombre'          => $item['nombre'],
                'precio'          => $item['precio'],
                'precio_original' => $item['precio_original'] ?? null,
                'cantidad_ean'    => $item['cantidad_ean'],
                'copias'          => $item['copias'],
                'modo'            => $item['modo'],
                'color'           => $item['color'],
                'ean13'           => $item['ean13'],
                'impreso'         => 0,
                'orden_posicion'  => $item['orden_posicion'],
            ],
            ['%d', '%s', '%s', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%d', '%d']
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function get_order($orden_id, $with_items = true) {
        global $wpdb;
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_orders} WHERE id = %d",
            $orden_id
        ), ARRAY_A);

        if (!$order) {
            return null;
        }

        $order['prioridad'] = (int) $order['prioridad'];
        $order['total_items'] = (int) $order['total_items'];
        $order['total_copias'] = (int) $order['total_copias'];
        $order['estado_label'] = self::ESTADOS[$order['estado']] ?? $order['estado'];
        $order['tipo_label'] = self::TIPOS[$order['tipo']] ?? $order['tipo'];

        if ($with_items) {
            $order['items'] = $this->get_items($orden_id);
        }

        return $order;
    }

    private function get_items($orden_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_items}
             WHERE orden_id = %d
             ORDER BY orden_posicion ASC, id ASC",
            $orden_id
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['orden_id'] = (int) $row['orden_id'];
            $row['precio'] = $row['precio'] !== null ? round((float) $row['precio'], 2) : null;
            $row['precio_original'] = isset($row['precio_original']) && $row['precio_original'] !== null && $row['precio_original'] !== ''
                ? round((float) $row['precio_original'], 2)
                : null;
            $row['cantidad_ean'] = (int) $row['cantidad_ean'];
            $row['copias'] = (int) $row['copias'];
            $row['impreso'] = (int) $row['impreso'];
            $row['orden_posicion'] = (int) $row['orden_posicion'];
        }

        return $rows;
    }

    private function can_edit_state($estado) {
        return in_array($estado, self::EDITABLE_STATES, true);
    }

    private function audit($action, $orden_id, array $data = []) {
        if (!class_exists('Riverso_POS_Audit')) {
            return;
        }
        $order = $this->get_order($orden_id, false);
        Riverso_POS_Audit::log($action, 'print_order', $orden_id, array_merge([
            'entity_name' => $order['numero_orden'] ?? ('#' . $orden_id),
        ], $data));
    }

    private function current_user_name() {
        $user = wp_get_current_user();
        return $user && $user->ID ? $user->display_name : '';
    }

    public function list_orders($filters = []) {
        global $wpdb;

        $page = max(1, intval($filters['page'] ?? 1));
        $per_page = max(1, min(100, intval($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['estado']) && isset(self::ESTADOS[$filters['estado']])) {
            $where[] = 'o.estado = %s';
            $params[] = $filters['estado'];
        }

        if (!empty($filters['tipo']) && isset(self::TIPOS[$filters['tipo']])) {
            $where[] = 'o.tipo = %s';
            $params[] = $filters['tipo'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'o.created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'o.created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }

        if (!empty($filters['mine'])) {
            $where[] = 'o.solicitado_por = %d';
            $params[] = get_current_user_id();
        } elseif (!empty($filters['solicitado_por'])) {
            $where[] = 'o.solicitado_por = %d';
            $params[] = intval($filters['solicitado_por']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        $join = '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $join = "LEFT JOIN {$this->table_items} i ON i.orden_id = o.id";
            $where[] = '(o.numero_orden LIKE %s OR o.solicitado_por_nombre LIKE %s OR o.notas LIKE %s OR i.sku LIKE %s OR i.nombre LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(DISTINCT o.id) FROM {$this->table_orders} o {$join} WHERE {$where_sql}";
        $list_sql  = "SELECT DISTINCT o.* FROM {$this->table_orders} o {$join} WHERE {$where_sql}
                      ORDER BY o.prioridad DESC, o.created_at DESC
                      LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
            $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, [$per_page, $offset])), ARRAY_A) ?: [];
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows = $wpdb->get_results($wpdb->prepare($list_sql, $per_page, $offset), ARRAY_A) ?: [];
        }

        foreach ($rows as &$row) {
            $row['prioridad'] = (int) $row['prioridad'];
            $row['total_items'] = (int) $row['total_items'];
            $row['total_copias'] = (int) $row['total_copias'];
            $row['estado_label'] = self::ESTADOS[$row['estado']] ?? $row['estado'];
            $row['tipo_label'] = self::TIPOS[$row['tipo']] ?? $row['tipo'];
        }

        return [
            'items'    => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $per_page ? (int) ceil($total / $per_page) : 1,
        ];
    }

    public function get_stats() {
        global $wpdb;

        $by_estado = [];
        foreach (array_keys(self::ESTADOS) as $estado) {
            $by_estado[$estado] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_orders} WHERE estado = %s",
                $estado
            ));
        }

        $impresa_hoy = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_orders}
             WHERE estado = 'impresa' AND impreso_en >= CURDATE()"
        );
        $creadas_hoy = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_orders} WHERE created_at >= CURDATE()"
        );

        $recientes = $wpdb->get_results(
            "SELECT id, numero_orden, estado, tipo, total_items, total_copias,
                    solicitado_por_nombre, impresora_nombre, impreso_en, created_at
             FROM {$this->table_orders}
             WHERE estado = 'impresa'
             ORDER BY impreso_en DESC
             LIMIT 10",
            ARRAY_A
        ) ?: [];

        return [
            'by_estado'    => $by_estado,
            'pendientes'   => $by_estado['pendiente'],
            'aprobadas'    => $by_estado['aprobada'],
            'impresa_hoy'  => $impresa_hoy,
            'creadas_hoy'  => $creadas_hoy,
            'canceladas'   => $by_estado['cancelada'],
            'recientes'    => $recientes,
        ];
    }

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_view();

        $filters = [
            'page'           => $_POST['page'] ?? 1,
            'per_page'       => $_POST['per_page'] ?? 20,
            'estado'         => sanitize_text_field($_POST['estado'] ?? ''),
            'tipo'           => sanitize_text_field($_POST['tipo'] ?? ''),
            'search'         => sanitize_text_field($_POST['search'] ?? ''),
            'date_from'      => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to'        => sanitize_text_field($_POST['date_to'] ?? ''),
            'mine'           => !empty($_POST['mine']),
            'solicitado_por' => intval($_POST['solicitado_por'] ?? 0),
        ];

        wp_send_json_success($this->list_orders($filters));
    }

    public function ajax_search() {
        $this->ajax_list();
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_view();

        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }

        wp_send_json_success(['order' => $order]);
    }

    public function ajax_create() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;

        $tipo = sanitize_text_field($_POST['tipo'] ?? 'etiqueta_producto');
        if (!isset(self::TIPOS[$tipo])) {
            $tipo = 'etiqueta_producto';
        }

        $default_modo = self::TIPO_MODO_DEFAULT[$tipo] ?? 'BolsaCOD';
        $raw_items = $this->parse_items_from_request();
        $items = [];
        foreach ($raw_items as $idx => $raw) {
            $item = $this->sanitize_item($raw, $default_modo);
            if (is_wp_error($item)) {
                wp_send_json_error(['message' => $item->get_error_message()]);
            }
            if (empty($item['orden_posicion'])) {
                $item['orden_posicion'] = $idx;
            }
            $items[] = $item;
        }

        $user = wp_get_current_user();
        $numero = $this->generate_order_number();

        $inserted = $wpdb->insert(
            $this->table_orders,
            [
                'numero_orden'          => $numero,
                'estado'                => 'borrador',
                'tipo'                  => $tipo,
                'prioridad'             => !empty($_POST['prioridad']) ? 1 : 0,
                'notas'                 => sanitize_textarea_field($_POST['notas'] ?? ''),
                'solicitado_por'        => $user->ID,
                'solicitado_por_nombre' => $user->display_name,
                'total_items'           => 0,
                'total_copias'          => 0,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d']
        );

        if (!$inserted) {
            if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate') !== false) {
                $numero = $this->generate_order_number();
                $inserted = $wpdb->insert(
                    $this->table_orders,
                    [
                        'numero_orden'          => $numero,
                        'estado'                => 'borrador',
                        'tipo'                  => $tipo,
                        'prioridad'             => !empty($_POST['prioridad']) ? 1 : 0,
                        'notas'                 => sanitize_textarea_field($_POST['notas'] ?? ''),
                        'solicitado_por'        => $user->ID,
                        'solicitado_por_nombre' => $user->display_name,
                        'total_items'           => 0,
                        'total_copias'          => 0,
                    ],
                    ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d']
                );
            }
        }

        if (!$inserted) {
            wp_send_json_error(['message' => 'No se pudo crear la orden']);
        }

        $orden_id = (int) $wpdb->insert_id;
        foreach ($items as $item) {
            $this->insert_item($orden_id, $item);
        }
        $this->refresh_totals($orden_id);

        $this->audit('print_order.created', $orden_id, [
            'new_value' => [
                'numero_orden' => $numero,
                'tipo'         => $tipo,
                'items'        => count($items),
            ],
            'details' => 'Orden de impresión creada',
        ]);

        wp_send_json_success(['order' => $this->get_order($orden_id)]);
    }

    public function ajax_update() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id, false);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if (!$this->can_edit_state($order['estado'])) {
            wp_send_json_error(['message' => 'No se puede modificar una orden ' . ($order['estado_label'] ?? $order['estado'])]);
        }

        $tipo = isset($_POST['tipo']) ? sanitize_text_field($_POST['tipo']) : $order['tipo'];
        if (!isset(self::TIPOS[$tipo])) {
            $tipo = $order['tipo'];
        }

        $update = [
            'tipo'      => $tipo,
            'prioridad' => isset($_POST['prioridad']) ? (!empty($_POST['prioridad']) ? 1 : 0) : (int) $order['prioridad'],
            'notas'     => array_key_exists('notas', $_POST)
                ? sanitize_textarea_field($_POST['notas'])
                : $order['notas'],
        ];

        $wpdb->update($this->table_orders, $update, ['id' => $id], ['%s', '%d', '%s'], ['%d']);

        if (isset($_POST['items'])) {
            $default_modo = self::TIPO_MODO_DEFAULT[$tipo] ?? 'BolsaCOD';
            $raw_items = $this->parse_items_from_request();
            $existing_items = $this->get_items($id);
            $wpdb->delete($this->table_items, ['orden_id' => $id], ['%d']);
            foreach ($raw_items as $idx => $raw) {
                $item = $this->sanitize_item($raw, $default_modo);
                if (is_wp_error($item)) {
                    wp_send_json_error(['message' => $item->get_error_message()]);
                }
                $existing = $this->find_existing_item($id, $raw, $existing_items);
                $item = $this->apply_item_identity_locks($item, $existing);
                if (empty($item['orden_posicion'])) {
                    $item['orden_posicion'] = $idx;
                }
                $this->insert_item($id, $item);
            }
            $this->refresh_totals($id);
        }

        $this->audit('print_order.modified', $id, [
            'old_value' => ['tipo' => $order['tipo'], 'prioridad' => $order['prioridad'], 'notas' => $order['notas']],
            'new_value' => $update,
            'details'   => 'Orden de impresión modificada',
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_delete() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id, false);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if ($order['estado'] !== 'borrador') {
            wp_send_json_error(['message' => 'Solo se pueden eliminar órdenes en borrador']);
        }

        $wpdb->delete($this->table_items, ['orden_id' => $id], ['%d']);
        $wpdb->delete($this->table_orders, ['id' => $id], ['%d']);

        $this->audit('print_order.cancelled', $id, [
            'old_value' => ['estado' => 'borrador', 'numero_orden' => $order['numero_orden']],
            'details'   => 'Orden de impresión eliminada (borrador)',
        ]);

        wp_send_json_success(['deleted' => true, 'id' => $id]);
    }

    public function ajax_add_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $orden_id = intval($_POST['orden_id'] ?? 0);
        $order = $this->get_order($orden_id, false);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if (!$this->can_edit_state($order['estado'])) {
            wp_send_json_error(['message' => 'No se pueden agregar ítems a esta orden']);
        }

        $default_modo = self::TIPO_MODO_DEFAULT[$order['tipo']] ?? 'BolsaCOD';
        $item = $this->sanitize_item($_POST, $default_modo);
        if (is_wp_error($item)) {
            wp_send_json_error(['message' => $item->get_error_message()]);
        }

        $max_pos = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(orden_posicion), -1) FROM {$this->table_items} WHERE orden_id = %d",
            $orden_id
        ));
        $item['orden_posicion'] = $max_pos + 1;

        $item_id = $this->insert_item($orden_id, $item);
        if (!$item_id) {
            wp_send_json_error(['message' => 'No se pudo agregar el ítem']);
        }

        $this->refresh_totals($orden_id);
        $this->audit('print_order.item_added', $orden_id, [
            'new_value' => $item,
            'details'   => 'Ítem agregado: ' . $item['sku'],
        ]);

        wp_send_json_success(['order' => $this->get_order($orden_id), 'item_id' => $item_id]);
    }

    public function ajax_update_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $item_id = intval($_POST['item_id'] ?? $_POST['id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE id = %d",
            $item_id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Ítem no encontrado'], 404);
        }

        $order = $this->get_order((int) $row['orden_id'], false);
        if (!$order || !$this->can_edit_state($order['estado'])) {
            wp_send_json_error(['message' => 'No se puede modificar este ítem']);
        }

        $merged = array_merge($row, $_POST);
        $item = $this->sanitize_item($merged, $row['modo']);
        if (is_wp_error($item)) {
            wp_send_json_error(['message' => $item->get_error_message()]);
        }
        $item = $this->apply_item_identity_locks($item, $row);

        $wpdb->update(
            $this->table_items,
            $item,
            ['id' => $item_id],
            ['%s', '%s', '%f', '%f', '%d', '%d', '%s', '%s', '%s', '%d'],
            ['%d']
        );

        $this->refresh_totals((int) $row['orden_id']);
        $this->audit('print_order.modified', (int) $row['orden_id'], [
            'old_value' => $row,
            'new_value' => $item,
            'details'   => 'Ítem modificado: ' . $item['sku'],
        ]);

        wp_send_json_success(['order' => $this->get_order((int) $row['orden_id'])]);
    }

    public function ajax_remove_item() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $item_id = intval($_POST['item_id'] ?? $_POST['id'] ?? 0);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_items} WHERE id = %d",
            $item_id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Ítem no encontrado'], 404);
        }

        $order = $this->get_order((int) $row['orden_id'], false);
        if (!$order || !$this->can_edit_state($order['estado'])) {
            wp_send_json_error(['message' => 'No se puede eliminar este ítem']);
        }

        $wpdb->delete($this->table_items, ['id' => $item_id], ['%d']);
        $this->refresh_totals((int) $row['orden_id']);
        $this->audit('print_order.item_removed', (int) $row['orden_id'], [
            'old_value' => ['sku' => $row['sku'], 'nombre' => $row['nombre'], 'copias' => $row['copias']],
            'details'   => 'Ítem eliminado: ' . $row['sku'],
        ]);

        wp_send_json_success(['order' => $this->get_order((int) $row['orden_id'])]);
    }

    public function ajax_reorder_items() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $orden_id = intval($_POST['orden_id'] ?? 0);
        $order = $this->get_order($orden_id, false);
        if (!$order || !$this->can_edit_state($order['estado'])) {
            wp_send_json_error(['message' => 'No se puede reordenar esta orden']);
        }

        $ids = $_POST['item_ids'] ?? [];
        if (is_string($ids)) {
            $decoded = json_decode(wp_unslash($ids), true);
            $ids = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($ids) || empty($ids)) {
            wp_send_json_error(['message' => 'Lista de ítems inválida']);
        }

        foreach (array_values($ids) as $pos => $item_id) {
            $wpdb->update(
                $this->table_items,
                ['orden_posicion' => $pos],
                ['id' => intval($item_id), 'orden_id' => $orden_id],
                ['%d'],
                ['%d', '%d']
            );
        }

        wp_send_json_success(['order' => $this->get_order($orden_id)]);
    }

    public function ajax_submit() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if ($order['estado'] !== 'borrador') {
            wp_send_json_error(['message' => 'Solo se pueden enviar órdenes en borrador']);
        }
        if (empty($order['items'])) {
            wp_send_json_error(['message' => 'Agrega al menos un producto antes de enviar']);
        }

        $wpdb->update($this->table_orders, ['estado' => 'pendiente'], ['id' => $id], ['%s'], ['%d']);
        $this->audit('print_order.submitted', $id, [
            'old_value' => ['estado' => 'borrador'],
            'new_value' => ['estado' => 'pendiente'],
            'details'   => 'Orden enviada a pendiente',
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_approve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_approve_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if ($order['estado'] !== 'pendiente') {
            wp_send_json_error(['message' => 'Solo se pueden aprobar órdenes pendientes']);
        }
        if (empty($order['items'])) {
            wp_send_json_error(['message' => 'La orden no tiene ítems']);
        }

        $wpdb->update(
            $this->table_orders,
            [
                'estado'      => 'aprobada',
                'aprobado_por'=> get_current_user_id(),
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );

        $this->audit('print_order.approved', $id, [
            'old_value' => ['estado' => 'pendiente'],
            'new_value' => ['estado' => 'aprobada'],
            'details'   => 'Orden aprobada para impresión',
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_return_draft() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_approve_print_orders') && !current_user_can('riverso_create_print_orders')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id, false);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if (!in_array($order['estado'], ['pendiente', 'aprobada'], true)) {
            wp_send_json_error(['message' => 'Solo se pueden devolver órdenes pendientes o aprobadas']);
        }

        $old = $order['estado'];
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_orders} SET estado = %s, aprobado_por = NULL WHERE id = %d",
            'borrador',
            $id
        ));

        $this->audit('print_order.returned', $id, [
            'old_value' => ['estado' => $old],
            'new_value' => ['estado' => 'borrador'],
            'details'   => 'Orden devuelta a borrador',
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_mark_printed() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (
            !current_user_can('riverso_print_orders')
            && !current_user_can('riverso_print_labels')
            && !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Sin permisos para imprimir'], 403);
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if (in_array($order['estado'], ['impresa', 'cancelada'], true)) {
            wp_send_json_error(['message' => 'Esta orden ya está ' . $order['estado']]);
        }

        if (!in_array($order['estado'], ['borrador', 'pendiente', 'aprobada'], true)) {
            wp_send_json_error(['message' => 'Esta orden no se puede marcar como impresa']);
        }
        if (empty($order['items'])) {
            wp_send_json_error(['message' => 'La orden no tiene ítems']);
        }

        $printer = sanitize_text_field($_POST['impresora_nombre'] ?? $_POST['printer_name'] ?? '');
        $now = current_time('mysql');

        $wpdb->update(
            $this->table_orders,
            [
                'estado'           => 'impresa',
                'impreso_por'      => get_current_user_id(),
                'impresora_nombre' => $printer !== '' ? $printer : null,
                'impreso_en'       => $now,
                'aprobado_por'     => $order['aprobado_por'] ?: get_current_user_id(),
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s', '%d'],
            ['%d']
        );

        $wpdb->update(
            $this->table_items,
            ['impreso' => 1],
            ['orden_id' => $id],
            ['%d'],
            ['%d']
        );

        $this->audit('print_order.printed', $id, [
            'old_value' => ['estado' => $order['estado']],
            'new_value' => [
                'estado'           => 'impresa',
                'impresora_nombre' => $printer,
                'total_copias'     => $order['total_copias'],
            ],
            'details' => 'Orden impresa' . ($printer ? ' en ' . $printer : ''),
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_cancel() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_cancel_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $order = $this->get_order($id, false);
        if (!$order) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }
        if (in_array($order['estado'], ['impresa', 'cancelada'], true)) {
            wp_send_json_error(['message' => 'No se puede cancelar una orden ' . $order['estado']]);
        }

        $motivo = sanitize_textarea_field($_POST['motivo'] ?? $_POST['motivo_cancelacion'] ?? '');

        $wpdb->update(
            $this->table_orders,
            [
                'estado'             => 'cancelada',
                'cancelado_por'      => get_current_user_id(),
                'cancelado_en'       => current_time('mysql'),
                'motivo_cancelacion' => $motivo !== '' ? $motivo : null,
            ],
            ['id' => $id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        $this->audit('print_order.cancelled', $id, [
            'old_value' => ['estado' => $order['estado']],
            'new_value' => ['estado' => 'cancelada', 'motivo' => $motivo],
            'details'   => $motivo !== '' ? $motivo : 'Orden cancelada',
        ]);

        wp_send_json_success(['order' => $this->get_order($id)]);
    }

    public function ajax_get_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_view();
        wp_send_json_success($this->get_stats());
    }

    public function ajax_duplicate() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_cap('riverso_create_print_orders');

        global $wpdb;
        $id = intval($_POST['id'] ?? $_POST['orden_id'] ?? 0);
        $source = $this->get_order($id);
        if (!$source) {
            wp_send_json_error(['message' => 'Orden no encontrada'], 404);
        }

        $user = wp_get_current_user();
        $numero = $this->generate_order_number();

        $inserted = $wpdb->insert(
            $this->table_orders,
            [
                'numero_orden'          => $numero,
                'estado'                => 'borrador',
                'tipo'                  => $source['tipo'],
                'prioridad'             => (int) $source['prioridad'],
                'notas'                 => $source['notas'],
                'solicitado_por'        => $user->ID,
                'solicitado_por_nombre' => $user->display_name,
                'total_items'           => 0,
                'total_copias'          => 0,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d']
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'No se pudo duplicar la orden']);
        }

        $new_id = (int) $wpdb->insert_id;
        foreach ($source['items'] as $idx => $item) {
            $this->insert_item($new_id, [
                'sku'             => $item['sku'],
                'nombre'          => $item['nombre'],
                'precio'          => $item['precio'],
                'precio_original' => $item['precio_original'] ?? null,
                'cantidad_ean'    => $item['cantidad_ean'],
                'copias'          => $item['copias'],
                'modo'            => $item['modo'],
                'color'           => $item['color'],
                'ean13'           => $item['ean13'],
                'orden_posicion'  => $idx,
            ]);
        }
        $this->refresh_totals($new_id);

        $this->audit('print_order.duplicated', $new_id, [
            'old_value' => ['source_id' => $id, 'source' => $source['numero_orden']],
            'new_value' => ['numero_orden' => $numero],
            'details'   => 'Duplicada desde ' . $source['numero_orden'],
        ]);

        wp_send_json_success(['order' => $this->get_order($new_id)]);
    }
}
