<?php
/**
 * Módulo de Proveedores para Riverso POS/ERP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Supplier_Module {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'riverso_proveedores';
    }
    
    public function init() {
        // AJAX handlers
        add_action('wp_ajax_riverso_get_suppliers', [$this, 'ajax_get_suppliers']);
        add_action('wp_ajax_riverso_get_supplier', [$this, 'ajax_get_supplier']);
        add_action('wp_ajax_riverso_save_supplier', [$this, 'ajax_save_supplier']);
        add_action('wp_ajax_riverso_delete_supplier', [$this, 'ajax_delete_supplier']);
        add_action('wp_ajax_riverso_toggle_supplier', [$this, 'ajax_toggle_supplier']);
        add_action('wp_ajax_riverso_search_suppliers', [$this, 'ajax_search_suppliers']);
        add_action('wp_ajax_riverso_get_supplier_stats', [$this, 'ajax_get_supplier_stats']);
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
            $where[] = '(nombre LIKE %s OR rut LIKE %s OR email LIKE %s OR contacto LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Total
        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE $where_sql";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);
        
        // Registros
        $offset = ($page - 1) * $per_page;
        $select_sql = "SELECT * FROM {$this->table_name} WHERE $where_sql ORDER BY nombre ASC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $suppliers = $wpdb->get_results($wpdb->prepare($select_sql, $params));
        
        // Agregar conteo de códigos por proveedor
        foreach ($suppliers as &$supplier) {
            $supplier->codigos_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_codigos WHERE proveedor_id = %d",
                $supplier->id
            ));
        }
        
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
        
        global $wpdb;
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = isset($_POST['limit']) ? min(50, max(5, intval($_POST['limit']))) : 10;
        
        $where = ['activo = 1'];
        $params = [];
        
        if ($search) {
            $where[] = '(nombre LIKE %s OR rut LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        
        $where_sql = implode(' AND ', $where);
        $params[] = $limit;
        
        $suppliers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, rut, nombre FROM {$this->table_name} WHERE $where_sql ORDER BY nombre ASC LIMIT %d",
            $params
        ));
        
        wp_send_json_success(['suppliers' => $suppliers]);
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
