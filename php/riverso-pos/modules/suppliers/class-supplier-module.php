<?php
/**
 * Módulo de Proveedores para Riverso POS/ERP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Supplier_Module {
    
    private $table_name;
    private $table_apodos;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'riverso_proveedores';
        $this->table_apodos = $wpdb->prefix . 'riverso_proveedor_apodos';
    }
    
    public function init() {
        if (class_exists('Riverso_POS_Activator')) {
            Riverso_POS_Activator::ensure_known_supplier_schema();
        }
        // AJAX handlers
        add_action('wp_ajax_riverso_get_suppliers', [$this, 'ajax_get_suppliers']);
        add_action('wp_ajax_riverso_get_supplier', [$this, 'ajax_get_supplier']);
        add_action('wp_ajax_riverso_save_supplier', [$this, 'ajax_save_supplier']);
        add_action('wp_ajax_riverso_delete_supplier', [$this, 'ajax_delete_supplier']);
        add_action('wp_ajax_riverso_toggle_supplier', [$this, 'ajax_toggle_supplier']);
        add_action('wp_ajax_riverso_search_suppliers', [$this, 'ajax_search_suppliers']);
        add_action('wp_ajax_riverso_get_supplier_stats', [$this, 'ajax_get_supplier_stats']);
        add_action('wp_ajax_riverso_add_provider_alias', [$this, 'ajax_add_provider_alias']);
        add_action('wp_ajax_riverso_remove_provider_alias', [$this, 'ajax_remove_provider_alias']);
        add_action('wp_ajax_riverso_create_provisional_supplier', [$this, 'ajax_create_provisional']);
        add_action('wp_ajax_riverso_assign_known_supplier', [$this, 'ajax_assign_known']);
        add_action('wp_ajax_riverso_remove_known_supplier', [$this, 'ajax_remove_known']);
    }
    
    /**
     * Obtiene lista de proveedores con filtros
     */
    public function ajax_get_suppliers() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? min(100, max(10, intval($_POST['per_page']))) : 25;
        
        $where = ['1=1'];
        $params = [];
        
        if ($status === 'active') {
            $where[] = 'activo = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'activo = 0';
        }
        
        if ($search) {
            $where[] = "(p.nombre LIKE %s OR p.rut LIKE %s OR p.email LIKE %s OR p.contacto LIKE %s
                         OR EXISTS (
                             SELECT 1 FROM {$this->table_apodos} a
                             WHERE a.proveedor_id = p.id AND a.apodo LIKE %s
                         ))";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Total
        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} p WHERE $where_sql";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);
        
        // Registros
        $offset = ($page - 1) * $per_page;
        $select_sql = "SELECT p.*,
                              (SELECT GROUP_CONCAT(a.apodo ORDER BY a.apodo SEPARATOR '||')
                               FROM {$this->table_apodos} a WHERE a.proveedor_id = p.id) AS apodos_raw
                       FROM {$this->table_name} p
                       WHERE $where_sql
                       ORDER BY p.nombre ASC
                       LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $suppliers = $wpdb->get_results($wpdb->prepare($select_sql, $params));
        
        // Agregar conteo de códigos y apodos por proveedor
        foreach ($suppliers as &$supplier) {
            $supplier->codigos_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_producto_proveedor WHERE proveedor_id = %d AND activo = 1",
                $supplier->id
            ));
            $raw = $supplier->apodos_raw ?? '';
            $supplier->apodos = $raw !== '' && $raw !== null
                ? array_values(array_filter(explode('||', $raw)))
                : [];
            unset($supplier->apodos_raw);
        }
        unset($supplier);
        
        wp_send_json_success([
            'suppliers' => $suppliers,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'page' => $page,
        ]);
    }
    
    /**
     * Obtiene un proveedor específico
     */
    public function ajax_get_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        if (!$supplier) {
            wp_send_json_error(['message' => 'Proveedor no encontrado']);
        }
        
        // Códigos asociados
        $supplier->codigos = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.post_title as product_name 
             FROM {$wpdb->prefix}riverso_codigos c
             LEFT JOIN {$wpdb->posts} p ON c.product_id = p.ID
             WHERE c.proveedor_id = %d
             ORDER BY c.codigo_proveedor ASC",
            $id
        ));
        
        // Historial de facturas
        $supplier->facturas = $wpdb->get_results($wpdb->prepare(
            "SELECT id, numero_factura, fecha_emision, total, estado
             FROM {$wpdb->prefix}riverso_facturas_recibidas
             WHERE proveedor_id = %d
             ORDER BY fecha_emision DESC
             LIMIT 10",
            $id
        ));
        
        wp_send_json_success(['supplier' => $supplier]);
    }
    
    /**
     * Guarda un proveedor (crear o actualizar)
     */
    public function ajax_save_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos para editar proveedores']);
        }
        
        global $wpdb;
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        // Validar RUT
        $rut = isset($_POST['rut']) ? $this->clean_rut($_POST['rut']) : '';
        if (empty($rut)) {
            wp_send_json_error(['message' => 'RUT es requerido']);
        }
        
        // Verificar duplicado
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE rut = %s AND id != %d",
            $rut,
            $id
        ));
        
        if ($existing) {
            wp_send_json_error(['message' => 'Ya existe un proveedor con este RUT']);
        }
        
        $data = [
            'rut'       => $rut,
            'nombre'    => sanitize_text_field($_POST['nombre'] ?? ''),
            'giro'      => sanitize_text_field($_POST['giro'] ?? ''),
            'direccion' => sanitize_text_field($_POST['direccion'] ?? ''),
            'comuna'    => sanitize_text_field($_POST['comuna'] ?? ''),
            'ciudad'    => sanitize_text_field($_POST['ciudad'] ?? ''),
            'telefono'  => sanitize_text_field($_POST['telefono'] ?? ''),
            'email'     => sanitize_email($_POST['email'] ?? ''),
            'contacto'  => sanitize_text_field($_POST['contacto'] ?? ''),
            'notas'     => sanitize_textarea_field($_POST['notas'] ?? ''),
            'activo'    => isset($_POST['activo']) ? intval($_POST['activo']) : 1,
        ];
        
        if (empty($data['nombre'])) {
            wp_send_json_error(['message' => 'Nombre es requerido']);
        }
        
        if ($id > 0) {
            // Obtener datos anteriores para auditoría
            $old_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $id
            ), ARRAY_A);
            
            $result = $wpdb->update($this->table_name, $data, ['id' => $id]);
            
            if ($result !== false) {
                // Auditoría
                if (class_exists('Riverso_POS_Audit')) {
                    Riverso_POS_Audit::log('supplier_updated', 'supplier', $id, [
                        'entity_name' => $data['nombre'],
                        'old_value' => $old_data,
                        'new_value' => $data,
                    ]);
                }
                wp_send_json_success(['message' => 'Proveedor actualizado', 'id' => $id]);
            }
        } else {
            $result = $wpdb->insert($this->table_name, $data);
            
            if ($result) {
                $new_id = $wpdb->insert_id;
                
                // Auditoría
                if (class_exists('Riverso_POS_Audit')) {
                    Riverso_POS_Audit::log('supplier_created', 'supplier', $new_id, [
                        'entity_name' => $data['nombre'],
                        'new_value' => $data,
                    ]);
                }
                wp_send_json_success(['message' => 'Proveedor creado', 'id' => $new_id]);
            }
        }
        
        wp_send_json_error(['message' => 'Error al guardar: ' . $wpdb->last_error]);
    }
    
    /**
     * Elimina un proveedor (solo si no tiene dependencias)
     */
    public function ajax_delete_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        // Verificar dependencias
        $codigos = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_codigos WHERE proveedor_id = %d",
            $id
        ));
        
        $facturas = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_facturas_recibidas WHERE proveedor_id = %d",
            $id
        ));
        
        if ($codigos > 0 || $facturas > 0) {
            wp_send_json_error([
                'message' => "No se puede eliminar. Tiene {$codigos} códigos y {$facturas} facturas asociadas. Desactívelo en su lugar."
            ]);
        }
        
        // Obtener datos para auditoría
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
        
        $result = $wpdb->delete($this->table_name, ['id' => $id]);
        
        if ($result) {
            // Auditoría
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('supplier_deleted', 'supplier', $id, [
                    'entity_name' => $supplier['nombre'],
                    'old_value' => $supplier,
                ]);
            }
            wp_send_json_success(['message' => 'Proveedor eliminado']);
        }
        
        wp_send_json_error(['message' => 'Error al eliminar']);
    }
    
    /**
     * Activa/desactiva un proveedor
     */
    public function ajax_toggle_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_edit_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;
        
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $result = $wpdb->update(
            $this->table_name,
            ['activo' => $active],
            ['id' => $id]
        );
        
        if ($result !== false) {
            $status = $active ? 'activado' : 'desactivado';
            wp_send_json_success(['message' => "Proveedor {$status}"]);
        }
        
        wp_send_json_error(['message' => 'Error al actualizar']);
    }
    
    /**
     * Búsqueda rápida de proveedores (para selects)
     */
    public function ajax_search_suppliers() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!$this->can_pick_supplier()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        global $wpdb;
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = isset($_POST['limit']) ? min(50, max(5, intval($_POST['limit']))) : 10;
        
        $where = ['p.activo = 1'];
        $params = [];
        
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(p.nombre LIKE %s OR p.rut LIKE %s OR EXISTS (
                SELECT 1 FROM {$this->table_apodos} a
                WHERE a.proveedor_id = p.id AND a.apodo LIKE %s
            ))";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        $like = $search !== '' ? ('%' . $wpdb->esc_like($search) . '%') : '';
        
        // El LIKE del subquery de matched_apodo va primero en los placeholders.
        $select_params = $like !== '' ? array_merge([$like], $params, [$limit]) : array_merge($params, [$limit]);
        
        $matched_select = $like !== ''
            ? "(SELECT a.apodo FROM {$this->table_apodos} a
                WHERE a.proveedor_id = p.id AND a.apodo LIKE %s
                ORDER BY a.apodo ASC LIMIT 1) AS matched_apodo"
            : "NULL AS matched_apodo";
        
        $suppliers = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.rut, p.nombre, p.provisional, {$matched_select}
             FROM {$this->table_name} p
             WHERE $where_sql
             ORDER BY p.nombre ASC
             LIMIT %d",
            $select_params
        ));
        
        wp_send_json_success(['suppliers' => $suppliers]);
    }
    
    /**
     * Normaliza un apodo: trim y colapsa espacios internos.
     */
    private function normalize_apodo($apodo) {
        $apodo = sanitize_text_field($apodo);
        $apodo = preg_replace('/\s+/u', ' ', trim($apodo));
        return $apodo;
    }
    
    private function can_manage_aliases() {
        return current_user_can('riverso_manage_codes')
            || current_user_can('riverso_edit_suppliers');
    }
    
    /**
     * Lista apodos de un proveedor.
     */
    public function get_apodos($proveedor_id) {
        global $wpdb;
        $proveedor_id = absint($proveedor_id);
        if (!$proveedor_id) {
            return [];
        }
        return $wpdb->get_col($wpdb->prepare(
            "SELECT apodo FROM {$this->table_apodos}
             WHERE proveedor_id = %d
             ORDER BY apodo ASC",
            $proveedor_id
        )) ?: [];
    }
    
    public function ajax_add_provider_alias() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!$this->can_manage_aliases()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        global $wpdb;
        
        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $apodo = $this->normalize_apodo($_POST['apodo'] ?? '');
        
        if (!$proveedor_id || $apodo === '') {
            wp_send_json_error(['message' => 'Proveedor y apodo requeridos']);
        }
        
        if (mb_strlen($apodo) > 100) {
            wp_send_json_error(['message' => 'El apodo no puede superar 100 caracteres']);
        }
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE id = %d",
            $proveedor_id
        ));
        if (!$exists) {
            wp_send_json_error(['message' => 'Proveedor no encontrado']);
        }
        
        // Unicidad case-insensitive dentro del mismo proveedor.
        $clash = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_apodos}
             WHERE proveedor_id = %d AND LOWER(apodo) = LOWER(%s)
             LIMIT 1",
            $proveedor_id,
            $apodo
        ));
        if ($clash) {
            wp_send_json_error(['message' => 'Ese apodo ya existe para este proveedor']);
        }
        
        $result = $wpdb->insert(
            $this->table_apodos,
            [
                'proveedor_id' => $proveedor_id,
                'apodo' => $apodo,
            ],
            ['%d', '%s']
        );
        
        if ($result === false) {
            wp_send_json_error(['message' => 'No se pudo guardar el apodo']);
        }
        
        wp_send_json_success([
            'message' => 'Apodo agregado',
            'apodo' => $apodo,
            'apodos' => $this->get_apodos($proveedor_id),
        ]);
    }
    
    public function ajax_remove_provider_alias() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!$this->can_manage_aliases()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        global $wpdb;
        
        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $apodo = $this->normalize_apodo($_POST['apodo'] ?? '');
        
        if (!$proveedor_id || $apodo === '') {
            wp_send_json_error(['message' => 'Proveedor y apodo requeridos']);
        }
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_apodos}
             WHERE proveedor_id = %d AND LOWER(apodo) = LOWER(%s)",
            $proveedor_id,
            $apodo
        ));
        
        if ($deleted === false) {
            wp_send_json_error(['message' => 'No se pudo eliminar el apodo']);
        }
        
        wp_send_json_success([
            'message' => 'Apodo eliminado',
            'apodos' => $this->get_apodos($proveedor_id),
        ]);
    }
    
    /**
     * Estadísticas de un proveedor
     */
    public function ajax_get_supplier_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_suppliers')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        // Total facturas
        $total_facturas = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_facturas_recibidas WHERE proveedor_id = %d",
            $id
        ));
        
        // Monto total comprado
        $monto_total = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total) FROM {$wpdb->prefix}riverso_facturas_recibidas WHERE proveedor_id = %d AND estado != 'rechazada'",
            $id
        ));
        
        // Productos asociados
        $productos = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}riverso_codigos WHERE proveedor_id = %d AND product_id IS NOT NULL",
            $id
        ));
        
        // Última compra
        $ultima_compra = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(fecha_emision) FROM {$wpdb->prefix}riverso_facturas_recibidas WHERE proveedor_id = %d",
            $id
        ));
        
        wp_send_json_success([
            'stats' => [
                'total_facturas' => (int) $total_facturas,
                'monto_total' => (float) ($monto_total ?: 0),
                'productos_asociados' => (int) $productos,
                'ultima_compra' => $ultima_compra,
            ]
        ]);
    }
    
    /**
     * Quién puede abrir la lupa de proveedores.
     */
    private function can_pick_supplier() {
        return current_user_can('riverso_view_suppliers')
            || current_user_can('riverso_manage_codes')
            || current_user_can('riverso_view_products')
            || current_user_can('riverso_manage_products')
            || current_user_can('riverso_edit_suppliers')
            || current_user_can('riverso_manage_purchase_notices')
            || current_user_can('riverso_report_shortage')
            || current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
    }

    /**
     * Quién puede anotar un proveedor sin código o crear un apodo provisional.
     */
    private function can_edit_known_supplier() {
        return current_user_can('riverso_manage_products')
            || current_user_can('riverso_edit_suppliers')
            || current_user_can('riverso_manage_purchase_notices')
            || current_user_can('riverso_report_shortage')
            || current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || current_user_can('manage_options');
    }

    public function ajax_create_provisional() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_known_supplier()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = self::create_provisional(wp_unslash($_POST['apodo'] ?? ''));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['supplier' => $result]);
    }

    public function ajax_assign_known() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_known_supplier()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = self::assign_known(
            absint($_POST['producto_base_id'] ?? 0),
            absint($_POST['proveedor_id'] ?? 0),
            !empty($_POST['es_preferido'])
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['id' => (int) $result]);
    }

    public function ajax_remove_known() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_known_supplier()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $ok = self::remove_known(absint($_POST['id'] ?? 0));
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo quitar el proveedor']);
        }
        wp_send_json_success(['message' => 'Proveedor quitado']);
    }

    /**
     * Proveedores anotados por apodo, todavía sin factura.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_provisionals() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_proveedores';
        $rows = $wpdb->get_results(
            "SELECT id, nombre, rut FROM {$table}
             WHERE activo = 1 AND provisional = 1
             ORDER BY nombre ASC
             LIMIT 40",
            ARRAY_A
        );
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
                'rut' => (string) ($row['rut'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Crea un proveedor solo con el apodo. Si ese nombre ya existe, devuelve el existente.
     *
     * @param string $apodo
     * @return array|WP_Error
     */
    public static function create_provisional($apodo) {
        global $wpdb;
        if (class_exists('Riverso_POS_Activator')) {
            Riverso_POS_Activator::ensure_known_supplier_schema();
        }
        $apodo = self::normalize_apodo_static($apodo);
        if ($apodo === '') {
            return new WP_Error('invalid_apodo', 'Escribe el apodo del proveedor');
        }
        if (mb_strlen($apodo) > 100) {
            return new WP_Error('invalid_apodo', 'El apodo no puede superar 100 caracteres');
        }

        $table = $wpdb->prefix . 'riverso_proveedores';
        $apodos = $wpdb->prefix . 'riverso_proveedor_apodos';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT p.id, p.nombre, p.rut, p.provisional
             FROM {$table} p
             LEFT JOIN {$apodos} a ON a.proveedor_id = p.id
             WHERE p.activo = 1 AND (LOWER(p.nombre) = LOWER(%s) OR LOWER(a.apodo) = LOWER(%s))
             LIMIT 1",
            $apodo,
            $apodo
        ), ARRAY_A);
        if ($existing) {
            return [
                'id' => (int) $existing['id'],
                'nombre' => (string) $existing['nombre'],
                'rut' => (string) ($existing['rut'] ?? ''),
                'provisional' => (int) $existing['provisional'],
                'existing' => true,
            ];
        }

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (rut, nombre, provisional, activo) VALUES (NULL, %s, 1, 1)",
            $apodo
        ));
        if (!$inserted) {
            return new WP_Error('db_error', 'No se pudo crear el proveedor');
        }
        $id = (int) $wpdb->insert_id;
        self::store_apodo($id, $apodo);
        return [
            'id' => $id,
            'nombre' => $apodo,
            'rut' => '',
            'provisional' => 1,
            'existing' => false,
        ];
    }

    /**
     * El folio confirma el proveedor provisional: mismo id, nombre oficial, apodo conservado.
     *
     * @param int   $id
     * @param array $data
     * @return int|WP_Error
     */
    public static function claim_provisional($id, array $data) {
        global $wpdb;
        $id = absint($id);
        $table = $wpdb->prefix . 'riverso_proveedores';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$row) {
            return new WP_Error('not_found', 'Ese proveedor provisional ya no existe');
        }

        $rut = preg_replace('/[^0-9kK]/', '', strtoupper((string) ($data['rut'] ?? '')));
        if ($rut !== '') {
            $other = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE rut = %s AND id != %d",
                $rut,
                $id
            ));
            if ($other) {
                return new WP_Error('rut_taken', 'Ese RUT ya pertenece a otro proveedor');
            }
        }

        $old_name = (string) $row->nombre;
        $new_name = sanitize_text_field($data['nombre'] ?? '');
        if ($new_name === '') {
            $new_name = $old_name;
        }
        $update = [
            'nombre' => $new_name,
            'provisional' => 0,
            'activo' => 1,
        ];
        $format = ['%s', '%d', '%d'];
        if ($rut !== '') {
            $update['rut'] = $rut;
            $format[] = '%s';
        }
        foreach (['giro', 'direccion', 'comuna', 'ciudad', 'telefono', 'email', 'contacto'] as $field) {
            $value = sanitize_text_field($data[$field] ?? '');
            if ($value !== '') {
                $update[$field] = $value;
                $format[] = '%s';
            }
        }
        $wpdb->update($table, $update, ['id' => $id], $format, ['%d']);
        if (mb_strtolower($old_name) !== mb_strtolower($new_name)) {
            self::store_apodo($id, $old_name);
        }
        self::store_apodo($id, $old_name);
        return $id;
    }

    /**
     * El RUT del folio ya existía: el apodo pasa al proveedor oficial y los vínculos lo siguen.
     *
     * @param int $provisional_id
     * @param int $official_id
     * @return int|WP_Error
     */
    public static function merge_provisional_into($provisional_id, $official_id) {
        global $wpdb;
        $provisional_id = absint($provisional_id);
        $official_id = absint($official_id);
        if ($provisional_id === $official_id) {
            return $official_id;
        }
        $table = $wpdb->prefix . 'riverso_proveedores';
        $prefix = $wpdb->prefix . 'riverso_';
        $prov = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $provisional_id));
        $official = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $official_id));
        if (!$prov || !$official) {
            return new WP_Error('not_found', 'No se pudo unir el apodo con el proveedor del folio');
        }

        self::store_apodo($official_id, (string) $prov->nombre);
        $apodos = $wpdb->get_col($wpdb->prepare(
            "SELECT apodo FROM {$prefix}proveedor_apodos WHERE proveedor_id = %d",
            $provisional_id
        ));
        foreach ($apodos ?: [] as $apodo) {
            self::store_apodo($official_id, (string) $apodo);
        }

        $known = "{$prefix}producto_proveedor_conocido";
        if (self::table_ready($known)) {
            $wpdb->query($wpdb->prepare(
                "DELETE k FROM {$known} k
                 INNER JOIN {$known} o
                    ON o.producto_base_id = k.producto_base_id AND o.proveedor_id = %d
                 WHERE k.proveedor_id = %d",
                $official_id,
                $provisional_id
            ));
            $wpdb->update($known, ['proveedor_id' => $official_id], ['proveedor_id' => $provisional_id], ['%d'], ['%d']);
        }

        foreach (['avisos_compra', 'ordenes_compra'] as $name) {
            $rel = $prefix . $name;
            if (self::table_ready($rel)) {
                $wpdb->update($rel, ['proveedor_id' => $official_id], ['proveedor_id' => $provisional_id], ['%d'], ['%d']);
            }
        }

        $wpdb->update(
            $table,
            ['activo' => 0, 'provisional' => 0],
            ['id' => $provisional_id],
            ['%d', '%d'],
            ['%d']
        );
        return $official_id;
    }

    /**
     * Marca el proveedor en el producto sin inventar un código.
     *
     * @param int  $producto_base_id
     * @param int  $proveedor_id
     * @param bool $preferido
     * @return int|WP_Error
     */
    public static function assign_known($producto_base_id, $proveedor_id, $preferido = false) {
        global $wpdb;
        if (class_exists('Riverso_POS_Activator')) {
            Riverso_POS_Activator::ensure_known_supplier_schema();
        }
        $producto_base_id = absint($producto_base_id);
        $proveedor_id = absint($proveedor_id);
        if (!$producto_base_id || !$proveedor_id) {
            return new WP_Error('invalid', 'Producto y proveedor son obligatorios');
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $product = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ));
        $supplier = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}proveedores WHERE id = %d AND activo = 1",
            $proveedor_id
        ));
        if (!$product || !$supplier) {
            return new WP_Error('not_found', 'Producto o proveedor no encontrado');
        }

        $table = "{$prefix}producto_proveedor_conocido";
        if ($preferido) {
            $wpdb->update($table, ['es_preferido' => 0], ['producto_base_id' => $producto_base_id], ['%d'], ['%d']);
        }
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE producto_base_id = %d AND proveedor_id = %d",
            $producto_base_id,
            $proveedor_id
        ));
        if ($existing) {
            if ($preferido) {
                $wpdb->update($table, ['es_preferido' => 1], ['id' => $existing], ['%d'], ['%d']);
            }
            return $existing;
        }
        $wpdb->insert($table, [
            'producto_base_id' => $producto_base_id,
            'proveedor_id' => $proveedor_id,
            'es_preferido' => $preferido ? 1 : 0,
            'origen' => 'manual',
            'created_by' => get_current_user_id(),
        ], ['%d', '%d', '%d', '%s', '%d']);
        return (int) $wpdb->insert_id;
    }

    /**
     * Si el producto ya tiene código de ese proveedor, no hace falta la marca.
     *
     * @param int $producto_base_id
     * @param int $proveedor_id
     */
    public static function remember_if_uncoded($producto_base_id, $proveedor_id) {
        global $wpdb;
        $producto_base_id = absint($producto_base_id);
        $proveedor_id = absint($proveedor_id);
        if (!$producto_base_id || !$proveedor_id) {
            return;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $has_code = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_proveedor
             WHERE producto_base_id = %d AND proveedor_id = %d AND activo = 1
             LIMIT 1",
            $producto_base_id,
            $proveedor_id
        ));
        if ($has_code) {
            return;
        }
        self::assign_known($producto_base_id, $proveedor_id, false);
    }

    /**
     * Proveedores conocidos que todavía no tienen código en el producto.
     *
     * @param int        $producto_base_id
     * @param int[]|null $skip_supplier_ids Proveedores que ya tienen código.
     * @return array<int,array<string,mixed>>
     */
    public static function list_known($producto_base_id, $skip_supplier_ids = null) {
        global $wpdb;
        $producto_base_id = absint($producto_base_id);
        if (!$producto_base_id) {
            return [];
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $table = "{$prefix}producto_proveedor_conocido";
        if (!self::table_ready($table)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT k.id, k.proveedor_id, k.es_preferido, p.nombre, p.rut, p.provisional
             FROM {$table} k
             INNER JOIN {$prefix}proveedores p ON p.id = k.proveedor_id AND p.activo = 1
             WHERE k.producto_base_id = %d
             ORDER BY k.es_preferido DESC, p.nombre ASC",
            $producto_base_id
        ), ARRAY_A);
        $skip = array_map('intval', $skip_supplier_ids ?: []);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $sid = (int) $row['proveedor_id'];
            if (in_array($sid, $skip, true)) {
                continue;
            }
            $apodos = $wpdb->get_col($wpdb->prepare(
                "SELECT apodo FROM {$prefix}proveedor_apodos WHERE proveedor_id = %d ORDER BY apodo ASC",
                $sid
            )) ?: [];
            $out[] = [
                'id' => (int) $row['id'],
                'proveedor_id' => $sid,
                'nombre' => (string) $row['nombre'],
                'rut' => (string) ($row['rut'] ?? ''),
                'provisional' => (int) $row['provisional'],
                'es_preferido' => (int) $row['es_preferido'],
                'apodos' => $apodos,
                'sin_codigo' => true,
            ];
        }
        return $out;
    }

    /**
     * @param int $id
     * @return bool
     */
    public static function remove_known($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return false;
        }
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'riverso_producto_proveedor_conocido',
            ['id' => $id],
            ['%d']
        );
        return $deleted !== false && $deleted > 0;
    }

    /**
     * @param string $apodo
     * @return string
     */
    private static function normalize_apodo_static($apodo) {
        $apodo = sanitize_text_field((string) $apodo);
        $apodo = preg_replace('/\s+/u', ' ', trim($apodo));
        return (string) $apodo;
    }

    /**
     * @param int    $proveedor_id
     * @param string $apodo
     */
    private static function store_apodo($proveedor_id, $apodo) {
        global $wpdb;
        $proveedor_id = absint($proveedor_id);
        $apodo = self::normalize_apodo_static($apodo);
        if (!$proveedor_id || $apodo === '' || mb_strlen($apodo) > 100) {
            return;
        }
        $table = $wpdb->prefix . 'riverso_proveedor_apodos';
        $clash = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE proveedor_id = %d AND LOWER(apodo) = LOWER(%s) LIMIT 1",
            $proveedor_id,
            $apodo
        ));
        if ($clash) {
            return;
        }
        $wpdb->insert($table, [
            'proveedor_id' => $proveedor_id,
            'apodo' => $apodo,
        ], ['%d', '%s']);
    }

    /**
     * @param string $table
     * @return bool
     */
    private static function table_ready($table) {
        global $wpdb;
        $like = $wpdb->esc_like($table);
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like)) === $table;
    }

    /**
     * Limpia un RUT (solo números y K)
     */
    private function clean_rut($rut) {
        return preg_replace('/[^0-9kK]/', '', strtoupper($rut));
    }
    
    /**
     * API pública: obtener proveedor por RUT
     */
    public function get_by_rut($rut) {
        global $wpdb;
        
        $clean_rut = $this->clean_rut($rut);
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE rut = %s",
            $clean_rut
        ));
    }
    
    /**
     * API pública: crear proveedor desde factura
     */
    public function create_from_invoice($data) {
        global $wpdb;
        
        $rut = $this->clean_rut($data['rut'] ?? '');
        if (empty($rut)) {
            return false;
        }
        
        // Verificar si ya existe
        $existing = $this->get_by_rut($rut);
        if ($existing) {
            return $existing->id;
        }
        
        $insert_data = [
            'rut'       => $rut,
            'nombre'    => sanitize_text_field($data['nombre'] ?? 'Proveedor ' . $rut),
            'giro'      => sanitize_text_field($data['giro'] ?? ''),
            'direccion' => sanitize_text_field($data['direccion'] ?? ''),
            'activo'    => 1,
        ];
        
        $result = $wpdb->insert($this->table_name, $insert_data);
        
        if ($result) {
            $new_id = $wpdb->insert_id;
            
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('supplier_created', 'supplier', $new_id, [
                    'entity_name' => $insert_data['nombre'],
                    'details' => 'Creado automáticamente desde factura',
                ]);
            }
            
            return $new_id;
        }
        
        return false;
    }
}
