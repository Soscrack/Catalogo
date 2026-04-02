<?php
/**
 * Módulo de Bodega - Gestión de ubicaciones y movimientos de stock
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Warehouse_Module {

    /**
     * Tipos de ubicación
     */
    const LOCATION_TYPES = [
        'pasillo' => 'Pasillo',
        'estante' => 'Estante',
        'rack' => 'Rack',
        'piso' => 'Piso',
        'meson' => 'Mesón',
        'vitrina' => 'Vitrina',
        'bodega_ext' => 'Bodega Externa',
    ];

    /**
     * Tipos de movimiento
     */
    const MOVEMENT_TYPES = [
        'entrada' => ['label' => 'Entrada', 'icon' => 'plus', 'color' => '#4caf50'],
        'salida' => ['label' => 'Salida', 'icon' => 'minus', 'color' => '#f44336'],
        'traslado' => ['label' => 'Traslado', 'icon' => 'randomize', 'color' => '#2196f3'],
        'ajuste' => ['label' => 'Ajuste', 'icon' => 'edit', 'color' => '#ff9800'],
        'inventario' => ['label' => 'Inventario', 'icon' => 'clipboard', 'color' => '#9c27b0'],
    ];

    /**
     * Inicializar módulo
     */
    public function init() {
        add_action('wp_ajax_riverso_get_locations', [$this, 'ajax_get_locations']);
        add_action('wp_ajax_riverso_create_location', [$this, 'ajax_create_location']);
        add_action('wp_ajax_riverso_update_location', [$this, 'ajax_update_location']);
        add_action('wp_ajax_riverso_delete_location', [$this, 'ajax_delete_location']);
        add_action('wp_ajax_riverso_assign_product_location', [$this, 'ajax_assign_product']);
        add_action('wp_ajax_riverso_get_product_locations', [$this, 'ajax_get_product_locations']);
        add_action('wp_ajax_riverso_record_movement', [$this, 'ajax_record_movement']);
        add_action('wp_ajax_riverso_get_movements', [$this, 'ajax_get_movements']);
        add_action('wp_ajax_riverso_search_products_warehouse', [$this, 'ajax_search_products']);
    }

    /**
     * Crear ubicación
     */
    public function create_location($data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $codigo = !empty($data['codigo']) 
            ? sanitize_text_field($data['codigo'])
            : $this->generate_location_code($data['tipo'], $data['nombre']);

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}ubicaciones WHERE codigo = %s",
            $codigo
        ));

        if ($exists) {
            return new WP_Error('duplicate', 'Ya existe una ubicación con este código');
        }

        $result = $wpdb->insert(
            "{$prefix}ubicaciones",
            [
                'codigo' => $codigo,
                'nombre' => sanitize_text_field($data['nombre']),
                'tipo' => sanitize_text_field($data['tipo']),
                'descripcion' => sanitize_textarea_field($data['descripcion'] ?? ''),
                'capacidad' => intval($data['capacidad'] ?? 0),
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d']
        );

        return $result ? $wpdb->insert_id : new WP_Error('db_error', 'Error creando ubicación');
    }

    private function generate_location_code($tipo, $nombre) {
        $prefix_map = [
            'pasillo' => 'P', 'estante' => 'E', 'rack' => 'R', 'piso' => 'F',
            'meson' => 'M', 'vitrina' => 'V', 'bodega_ext' => 'B',
        ];
        $prefix = $prefix_map[$tipo] ?? 'X';
        $slug = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $nombre), 0, 3));
        return "{$prefix}-{$slug}" . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    }

    public function get_locations($filters = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = ['1=1'];
        $params = [];

        if (isset($filters['activo'])) {
            $where[] = 'u.activo = %d';
            $params[] = $filters['activo'];
        }
        if (!empty($filters['tipo'])) {
            $where[] = 'u.tipo = %s';
            $params[] = $filters['tipo'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(u.codigo LIKE %s OR u.nombre LIKE %s)';
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT u.*, 
                (SELECT COUNT(*) FROM {$prefix}producto_ubicacion pu WHERE pu.ubicacion_id = u.id) as productos_count
                FROM {$prefix}ubicaciones u
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.tipo, u.codigo";

        return $wpdb->get_results($params ? $wpdb->prepare($sql, ...$params) : $sql, ARRAY_A);
    }

    public function assign_product_location($product_id, $location_id, $cantidad = 0, $posicion = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!wc_get_product($product_id)) {
            return new WP_Error('invalid_product', 'Producto no encontrado');
        }

        $location = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}ubicaciones WHERE id = %d AND activo = 1", $location_id
        ));
        if (!$location) {
            return new WP_Error('invalid_location', 'Ubicación no encontrada');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_ubicacion WHERE product_id = %d AND ubicacion_id = %d",
            $product_id, $location_id
        ));

        if ($existing) {
            $wpdb->update("{$prefix}producto_ubicacion",
                ['cantidad' => $cantidad, 'posicion' => $posicion, 'updated_at' => current_time('mysql')],
                ['id' => $existing->id], ['%d', '%s', '%s'], ['%d']
            );
            return $existing->id;
        }

        $result = $wpdb->insert("{$prefix}producto_ubicacion",
            ['product_id' => $product_id, 'ubicacion_id' => $location_id, 'cantidad' => $cantidad, 'posicion' => $posicion],
            ['%d', '%d', '%d', '%s']
        );
        return $result ? $wpdb->insert_id : new WP_Error('db_error', 'Error asignando ubicación');
    }

    public function record_movement($data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $tipo = sanitize_text_field($data['tipo']);
        $product_id = intval($data['product_id']);
        $cantidad = floatval($data['cantidad']);

        if (!isset(self::MOVEMENT_TYPES[$tipo])) {
            return new WP_Error('invalid_type', 'Tipo de movimiento inválido');
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', 'Producto no encontrado');
        }

        $stock_anterior = $product->get_stock_quantity() ?? 0;
        $stock_nuevo = $stock_anterior;

        switch ($tipo) {
            case 'entrada': $stock_nuevo = $stock_anterior + $cantidad; break;
            case 'salida': $stock_nuevo = $stock_anterior - $cantidad; break;
            case 'ajuste':
            case 'inventario':
                $stock_nuevo = $cantidad;
                $cantidad = $stock_nuevo - $stock_anterior;
                break;
        }

        $result = $wpdb->insert("{$prefix}movimientos", [
            'product_id' => $product_id,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'stock_anterior' => $stock_anterior,
            'stock_nuevo' => $stock_nuevo,
            'ubicacion_origen' => intval($data['ubicacion_origen'] ?? 0) ?: null,
            'ubicacion_destino' => intval($data['ubicacion_destino'] ?? 0) ?: null,
            'referencia_tipo' => sanitize_text_field($data['referencia_tipo'] ?? ''),
            'referencia_id' => intval($data['referencia_id'] ?? 0) ?: null,
            'notas' => sanitize_textarea_field($data['notas'] ?? ''),
            'usuario_id' => get_current_user_id(),
        ], ['%d', '%s', '%f', '%f', '%f', '%d', '%d', '%s', '%d', '%s', '%d']);

        if (!$result) return new WP_Error('db_error', 'Error registrando movimiento');

        if ($tipo !== 'traslado') {
            wc_update_product_stock($product, $stock_nuevo);
        }

        return $wpdb->insert_id;
    }

    public function get_movements($filters = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['product_id'])) {
            $where[] = 'm.product_id = %d';
            $params[] = $filters['product_id'];
        }
        if (!empty($filters['tipo'])) {
            $where[] = 'm.tipo = %s';
            $params[] = $filters['tipo'];
        }
        if (!empty($filters['fecha_desde'])) {
            $where[] = 'm.created_at >= %s';
            $params[] = $filters['fecha_desde'] . ' 00:00:00';
        }
        if (!empty($filters['fecha_hasta'])) {
            $where[] = 'm.created_at <= %s';
            $params[] = $filters['fecha_hasta'] . ' 23:59:59';
        }

        $limit = intval($filters['limit'] ?? 50);
        $offset = intval($filters['offset'] ?? 0);
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT m.*, u.display_name as usuario_nombre,
                uo.codigo as ubicacion_origen_codigo, ud.codigo as ubicacion_destino_codigo
                FROM {$prefix}movimientos m
                LEFT JOIN {$wpdb->users} u ON m.usuario_id = u.ID
                LEFT JOIN {$prefix}ubicaciones uo ON m.ubicacion_origen = uo.id
                LEFT JOIN {$prefix}ubicaciones ud ON m.ubicacion_destino = ud.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY m.created_at DESC LIMIT %d OFFSET %d";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
    }

    // ============ AJAX HANDLERS ============

    public function ajax_get_locations() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $locations = $this->get_locations([
            'activo' => isset($_POST['activo']) ? intval($_POST['activo']) : null,
            'tipo' => sanitize_text_field($_POST['tipo'] ?? ''),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
        ]);
        wp_send_json_success(['locations' => $locations, 'types' => self::LOCATION_TYPES]);
    }

    public function ajax_create_location() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $data = [
            'codigo' => $_POST['codigo'] ?? '',
            'nombre' => $_POST['nombre'] ?? '',
            'tipo' => $_POST['tipo'] ?? 'estante',
            'descripcion' => $_POST['descripcion'] ?? '',
            'capacidad' => $_POST['capacidad'] ?? 0,
        ];

        if (empty($data['nombre'])) wp_send_json_error(['message' => 'Nombre requerido']);

        $result = $this->create_location($data);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success(['message' => 'Ubicación creada', 'location_id' => $result]);
    }

    public function ajax_update_location() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        global $wpdb;
        $id = intval($_POST['location_id'] ?? 0);
        if (!$id) wp_send_json_error(['message' => 'ID requerido']);

        $update = [];
        foreach (['nombre', 'tipo', 'descripcion', 'capacidad', 'activo'] as $field) {
            if (isset($_POST[$field])) {
                $update[$field] = in_array($field, ['capacidad', 'activo']) ? intval($_POST[$field]) : sanitize_text_field($_POST[$field]);
            }
        }
        if (empty($update)) wp_send_json_error(['message' => 'Sin cambios']);

        $wpdb->update($wpdb->prefix . 'riverso_ubicaciones', $update, ['id' => $id]);
        wp_send_json_success(['message' => 'Ubicación actualizada']);
    }

    public function ajax_delete_location() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'riverso_ubicaciones', ['activo' => 0], ['id' => intval($_POST['location_id'] ?? 0)]);
        wp_send_json_success(['message' => 'Ubicación desactivada']);
    }

    public function ajax_assign_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $result = $this->assign_product_location(
            intval($_POST['product_id'] ?? 0),
            intval($_POST['location_id'] ?? 0),
            intval($_POST['cantidad'] ?? 0),
            sanitize_text_field($_POST['posicion'] ?? '')
        );
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success(['message' => 'Producto asignado']);
    }

    public function ajax_get_product_locations() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $locations = $wpdb->get_results($wpdb->prepare(
            "SELECT pu.*, u.codigo, u.nombre, u.tipo FROM {$prefix}producto_ubicacion pu
             JOIN {$prefix}ubicaciones u ON pu.ubicacion_id = u.id
             WHERE pu.product_id = %d AND u.activo = 1 ORDER BY u.codigo",
            intval($_POST['product_id'] ?? 0)
        ), ARRAY_A);
        wp_send_json_success(['locations' => $locations]);
    }

    public function ajax_record_movement() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'product_id' => $_POST['product_id'] ?? 0,
            'cantidad' => $_POST['cantidad'] ?? 0,
            'ubicacion_origen' => $_POST['ubicacion_origen'] ?? 0,
            'ubicacion_destino' => $_POST['ubicacion_destino'] ?? 0,
            'notas' => $_POST['notas'] ?? '',
        ];

        if (empty($data['tipo']) || empty($data['product_id']) || empty($data['cantidad'])) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }

        $result = $this->record_movement($data);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()]);
        wp_send_json_success(['message' => 'Movimiento registrado', 'movement_id' => $result]);
    }

    public function ajax_get_movements() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $movements = $this->get_movements([
            'product_id' => intval($_POST['product_id'] ?? 0),
            'tipo' => sanitize_text_field($_POST['tipo'] ?? ''),
            'fecha_desde' => sanitize_text_field($_POST['fecha_desde'] ?? ''),
            'fecha_hasta' => sanitize_text_field($_POST['fecha_hasta'] ?? ''),
            'limit' => min(100, intval($_POST['limit'] ?? 50)),
            'offset' => intval($_POST['offset'] ?? 0),
        ]);
        wp_send_json_success(['movements' => $movements, 'types' => self::MOVEMENT_TYPES]);
    }

    public function ajax_search_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_stock')) wp_send_json_error(['message' => 'Sin permisos']);

        $search = sanitize_text_field($_POST['search'] ?? '');
        if (strlen($search) < 2) wp_send_json_success(['products' => []]);

        $products = wc_get_products(['limit' => 20, 'status' => 'publish', 's' => $search, 'return' => 'ids']);
        $results = [];
        foreach ($products as $pid) {
            $p = wc_get_product($pid);
            if ($p) $results[] = ['id' => $pid, 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'stock' => $p->get_stock_quantity()];
        }
        wp_send_json_success(['products' => $results]);
    }
}
