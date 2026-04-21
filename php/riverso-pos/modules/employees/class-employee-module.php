<?php
/**
 * Módulo de Empleados para Riverso POS/ERP
 * 
 * Gestiona la relación entre usuarios WordPress y el sistema ERP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Employee_Module {
    
    private $table_name;
    
    /**
     * Estados de empleado
     */
    const ESTADOS = [
        'activo'    => 'Activo',
        'vacaciones'=> 'Vacaciones',
        'licencia'  => 'Licencia',
        'inactivo'  => 'Inactivo',
    ];
    
    /**
     * Tipos de contrato
     */
    const CONTRATOS = [
        'indefinido' => 'Indefinido',
        'plazo_fijo' => 'Plazo Fijo',
        'honorarios' => 'Honorarios',
        'practica'   => 'Práctica',
    ];
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'riverso_empleados';
    }
    
    public function init() {
        // AJAX handlers
        add_action('wp_ajax_riverso_get_employees', [$this, 'ajax_get_employees']);
        add_action('wp_ajax_riverso_get_employee', [$this, 'ajax_get_employee']);
        add_action('wp_ajax_riverso_save_employee', [$this, 'ajax_save_employee']);
        add_action('wp_ajax_riverso_delete_employee', [$this, 'ajax_delete_employee']);
        add_action('wp_ajax_riverso_get_employee_stats', [$this, 'ajax_get_employee_stats']);
        add_action('wp_ajax_riverso_search_wp_users', [$this, 'ajax_search_wp_users']);
        add_action('wp_ajax_riverso_assign_role', [$this, 'ajax_assign_role']);
        add_action('wp_ajax_riverso_create_employee', [$this, 'ajax_create_employee']);
        
        // Hooks de usuario
        add_action('delete_user', [$this, 'on_user_deleted']);
    }
    
    /**
     * Crea la tabla de empleados
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'riverso_empleados';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            rut VARCHAR(20) DEFAULT NULL,
            cargo VARCHAR(100) DEFAULT NULL,
            departamento VARCHAR(100) DEFAULT NULL,
            fecha_ingreso DATE DEFAULT NULL,
            tipo_contrato VARCHAR(30) DEFAULT 'indefinido',
            jornada VARCHAR(20) DEFAULT 'completa',
            supervisor_id BIGINT UNSIGNED DEFAULT NULL,
            telefono_personal VARCHAR(50) DEFAULT NULL,
            contacto_emergencia VARCHAR(255) DEFAULT NULL,
            estado VARCHAR(30) DEFAULT 'activo',
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY estado (estado),
            KEY supervisor_id (supervisor_id),
            KEY departamento (departamento)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Lista empleados
     */
    public function ajax_get_employees() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        // Permitir admin WP o roles Riverso con permiso
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'all';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $departamento = isset($_POST['departamento']) ? sanitize_text_field($_POST['departamento']) : '';
        
        // Query base: usuarios con roles Riverso
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor', 'riverso_cotizador'];
        
        $sql = "SELECT 
                    u.ID as user_id,
                    u.user_login,
                    u.user_email,
                    u.display_name,
                    e.id as employee_id,
                    e.rut,
                    e.cargo,
                    e.departamento,
                    e.fecha_ingreso,
                    e.tipo_contrato,
                    e.estado,
                    e.supervisor_id,
                    um.meta_value as wp_capabilities
                FROM {$wpdb->users} u
                LEFT JOIN {$this->table_name} e ON u.ID = e.user_id
                LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'
                WHERE 1=1";
        
        $params = [];
        
        // Filtrar por roles Riverso
        $role_conditions = [];
        foreach ($riverso_roles as $role) {
            $role_conditions[] = "um.meta_value LIKE %s";
            $params[] = '%' . $wpdb->esc_like($role) . '%';
        }
        // También incluir administradores
        $role_conditions[] = "um.meta_value LIKE %s";
        $params[] = '%administrator%';
        
        $sql .= " AND (" . implode(' OR ', $role_conditions) . ")";
        
        // Filtro por estado
        if ($status !== 'all') {
            if ($status === 'sin_perfil') {
                $sql .= " AND e.id IS NULL";
            } else {
                $sql .= " AND e.estado = %s";
                $params[] = $status;
            }
        }
        
        // Filtro por departamento
        if ($departamento) {
            $sql .= " AND e.departamento = %s";
            $params[] = $departamento;
        }
        
        // Búsqueda
        if ($search) {
            $sql .= " AND (u.display_name LIKE %s OR u.user_email LIKE %s OR e.rut LIKE %s OR e.cargo LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        
        $sql .= " ORDER BY u.display_name ASC";
        
        $employees = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Procesar resultados
        foreach ($employees as &$emp) {
            // Extraer rol
            $caps = maybe_unserialize($emp->wp_capabilities);
            $emp->role = 'none';
            $emp->role_name = 'Sin rol';
            if (is_array($caps)) {
                foreach ($riverso_roles as $role) {
                    if (!empty($caps[$role])) {
                        $emp->role = $role;
                        $emp->role_name = $this->get_role_display_name($role);
                        break;
                    }
                }
                if ($emp->role === 'none' && !empty($caps['administrator'])) {
                    $emp->role = 'administrator';
                    $emp->role_name = 'Administrador WP';
                }
            }
            unset($emp->wp_capabilities);
            
            // Contar tareas pendientes
            $emp->tareas_pendientes = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}riverso_tareas WHERE asignado_a = %d AND estado IN ('pendiente', 'en_progreso')",
                $emp->user_id
            ));
            
            // Nombre del supervisor
            if ($emp->supervisor_id) {
                $supervisor = get_userdata($emp->supervisor_id);
                $emp->supervisor_nombre = $supervisor ? $supervisor->display_name : 'N/A';
            } else {
                $emp->supervisor_nombre = null;
            }
        }
        
        // Obtener departamentos únicos para filtro
        $departamentos = $wpdb->get_col("SELECT DISTINCT departamento FROM {$this->table_name} WHERE departamento IS NOT NULL AND departamento != '' ORDER BY departamento");
        
        wp_send_json_success([
            'employees' => $employees,
            'departamentos' => $departamentos,
            'estados' => self::ESTADOS,
            'contratos' => self::CONTRATOS,
        ]);
    }
    
    /**
     * Obtiene un empleado específico
     */
    public function ajax_get_employee() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => 'Usuario no encontrado']);
        }
        
        // Datos del empleado
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));
        
        // Combinar datos
        $data = [
            'user_id' => $user_id,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'display_name' => $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'role' => $this->get_user_riverso_role($user_id),
        ];
        
        if ($employee) {
            $data = array_merge($data, (array) $employee);
        } else {
            // Valores por defecto
            $data['rut'] = '';
            $data['cargo'] = '';
            $data['departamento'] = '';
            $data['fecha_ingreso'] = date('Y-m-d');
            $data['tipo_contrato'] = 'indefinido';
            $data['jornada'] = 'completa';
            $data['supervisor_id'] = null;
            $data['telefono_personal'] = '';
            $data['contacto_emergencia'] = '';
            $data['estado'] = 'activo';
            $data['notas'] = '';
        }
        
        // Tareas recientes
        $data['tareas_recientes'] = $wpdb->get_results($wpdb->prepare(
            "SELECT id, titulo, tipo, estado, prioridad, created_at 
             FROM {$wpdb->prefix}riverso_tareas 
             WHERE asignado_a = %d 
             ORDER BY created_at DESC LIMIT 10",
            $user_id
        ));
        
        // Historial de auditoría
        $data['audit_history'] = [];
        if (class_exists('Riverso_POS_Audit')) {
            $data['audit_history'] = $wpdb->get_results($wpdb->prepare(
                "SELECT action, entity_type, entity_name, details, created_at 
                 FROM {$wpdb->prefix}riverso_audit_log 
                 WHERE user_id = %d 
                 ORDER BY created_at DESC LIMIT 20",
                $user_id
            ));
        }
        
        wp_send_json_success([
            'employee' => $data,
            'estados' => self::ESTADOS,
            'contratos' => self::CONTRATOS,
            'roles' => $this->get_riverso_roles(),
        ]);
    }
    
    /**
     * Guarda un empleado
     */
    public function ajax_save_employee() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos para gestionar empleados']);
        }
        
        global $wpdb;
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'ID de usuario requerido']);
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => 'Usuario no encontrado']);
        }
        
        // Actualizar datos de usuario WP
        $user_data = [
            'ID' => $user_id,
            'display_name' => sanitize_text_field($_POST['display_name'] ?? $user->display_name),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
        ];
        
        wp_update_user($user_data);
        
        // Asignar rol si se especifica
        if (!empty($_POST['role'])) {
            $new_role = sanitize_text_field($_POST['role']);
            $this->assign_riverso_role($user_id, $new_role);
        }
        
        // Datos del empleado
        $employee_data = [
            'user_id' => $user_id,
            'rut' => $this->clean_rut($_POST['rut'] ?? ''),
            'cargo' => sanitize_text_field($_POST['cargo'] ?? ''),
            'departamento' => sanitize_text_field($_POST['departamento'] ?? ''),
            'fecha_ingreso' => !empty($_POST['fecha_ingreso']) ? sanitize_text_field($_POST['fecha_ingreso']) : null,
            'tipo_contrato' => sanitize_text_field($_POST['tipo_contrato'] ?? 'indefinido'),
            'jornada' => sanitize_text_field($_POST['jornada'] ?? 'completa'),
            'supervisor_id' => !empty($_POST['supervisor_id']) ? intval($_POST['supervisor_id']) : null,
            'telefono_personal' => sanitize_text_field($_POST['telefono_personal'] ?? ''),
            'contacto_emergencia' => sanitize_text_field($_POST['contacto_emergencia'] ?? ''),
            'estado' => sanitize_text_field($_POST['estado'] ?? 'activo'),
            'notas' => sanitize_textarea_field($_POST['notas'] ?? ''),
        ];
        
        // Verificar si ya existe
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));
        
        if ($existing) {
            $result = $wpdb->update($this->table_name, $employee_data, ['user_id' => $user_id]);
            $action = 'actualizado';
        } else {
            $result = $wpdb->insert($this->table_name, $employee_data);
            $action = 'creado';
        }
        
        if ($result !== false) {
            // Auditoría
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('role_assigned', 'user', $user_id, [
                    'entity_name' => $user->display_name,
                    'details' => "Perfil de empleado {$action}",
                ]);
            }
            
            wp_send_json_success(['message' => "Empleado {$action} correctamente"]);
        }
        
        wp_send_json_error(['message' => 'Error al guardar: ' . $wpdb->last_error]);
    }
    
    /**
     * Elimina perfil de empleado (no el usuario WP)
     */
    public function ajax_delete_employee() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        global $wpdb;
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        
        $result = $wpdb->delete($this->table_name, ['user_id' => $user_id]);
        
        if ($result) {
            wp_send_json_success(['message' => 'Perfil de empleado eliminado']);
        }
        
        wp_send_json_error(['message' => 'Error al eliminar']);
    }
    
    /**
     * Estadísticas de empleados
     */
    public function ajax_get_employee_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        global $wpdb;
        
        // Total empleados activos
        $total_activos = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE estado = 'activo'"
        );
        
        // Por estado
        $por_estado = $wpdb->get_results(
            "SELECT estado, COUNT(*) as count FROM {$this->table_name} GROUP BY estado"
        );
        
        // Por departamento
        $por_departamento = $wpdb->get_results(
            "SELECT departamento, COUNT(*) as count FROM {$this->table_name} WHERE departamento IS NOT NULL GROUP BY departamento ORDER BY count DESC"
        );
        
        // Por rol
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor'];
        $por_rol = [];
        foreach ($riverso_roles as $role) {
            $count = count(get_users(['role' => $role]));
            if ($count > 0) {
                $por_rol[] = [
                    'role' => $role,
                    'name' => $this->get_role_display_name($role),
                    'count' => $count,
                ];
            }
        }
        
        // Tareas pendientes por empleado (top 5)
        $tareas_pendientes = $wpdb->get_results(
            "SELECT u.display_name, COUNT(t.id) as count 
             FROM {$wpdb->prefix}riverso_tareas t
             JOIN {$wpdb->users} u ON t.asignado_a = u.ID
             WHERE t.estado IN ('pendiente', 'en_progreso')
             GROUP BY t.asignado_a
             ORDER BY count DESC
             LIMIT 5"
        );
        
        wp_send_json_success([
            'total_activos' => (int) $total_activos,
            'por_estado' => $por_estado,
            'por_departamento' => $por_departamento,
            'por_rol' => $por_rol,
            'tareas_pendientes' => $tareas_pendientes,
        ]);
    }
    
    /**
     * Busca usuarios WP para agregar como empleados
     */
    public function ajax_search_wp_users() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system') && !current_user_can('riverso_manage_permissions')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        $args = [
            'number' => 50,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];
        
        // Si hay búsqueda, filtrar
        if (!empty($search)) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }
        
        $users = get_users($args);
        $results = [];
        
        // Mapeo de roles a labels
        $role_labels = [
            'administrator' => 'Administrador',
            'riverso_admin' => 'Admin Riverso',
            'riverso_ventas' => 'Vendedor',
            'riverso_bodega' => 'Bodega',
            'riverso_compras' => 'Compras',
            'riverso_recepciones' => 'Recepciones',
            'riverso_editor' => 'Editor',
            'riverso_cotizador' => 'Cotizador',
            'customer' => 'Cliente',
            'subscriber' => 'Suscriptor',
        ];
        
        foreach ($users as $user) {
            $user_role = $this->get_user_riverso_role($user->ID);
            $role_label = isset($role_labels[$user_role]) ? $role_labels[$user_role] : ucfirst(str_replace('_', ' ', $user_role));
            
            $results[] = [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'role' => $user_role,
                'role_label' => $role_label,
                'avatar' => get_avatar_url($user->ID, ['size' => 40]),
            ];
        }
        
        wp_send_json_success(['users' => $results]);
    }
    
    /**
     * Asigna rol a usuario
     */
    public function ajax_assign_role() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        
        if (!$user_id || !$role) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }
        
        $result = $this->assign_riverso_role($user_id, $role);
        
        if ($result) {
            wp_send_json_success(['message' => 'Rol asignado correctamente']);
        }
        
        wp_send_json_error(['message' => 'Error al asignar rol']);
    }
    
    /**
     * Crea un nuevo empleado (usuario WordPress + rol Riverso)
     */
    public function ajax_create_employee() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos para crear empleados']);
        }
        
        $user_login = isset($_POST['user_login']) ? sanitize_user($_POST['user_login']) : '';
        $user_email = isset($_POST['user_email']) ? sanitize_email($_POST['user_email']) : '';
        $user_pass = isset($_POST['user_pass']) ? $_POST['user_pass'] : '';
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
        $riverso_role = isset($_POST['riverso_role']) ? sanitize_text_field($_POST['riverso_role']) : '';
        
        // Validaciones
        if (empty($user_login) || empty($user_email) || empty($user_pass)) {
            wp_send_json_error(['message' => 'Usuario, email y contraseña son obligatorios']);
        }
        
        if (username_exists($user_login)) {
            wp_send_json_error(['message' => 'El nombre de usuario ya existe']);
        }
        
        if (email_exists($user_email)) {
            wp_send_json_error(['message' => 'El email ya está registrado']);
        }
        
        $valid_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor'];
        if (!in_array($riverso_role, $valid_roles)) {
            wp_send_json_error(['message' => 'Rol inválido']);
        }
        
        // Crear usuario WordPress
        $user_id = wp_create_user($user_login, $user_pass, $user_email);
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => $user_id->get_error_message()]);
        }
        
        // Actualizar nombre
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name) ?: $user_login
        ]);
        
        // Asignar rol Riverso
        $this->assign_riverso_role($user_id, $riverso_role);
        
        // Crear perfil de empleado
        global $wpdb;
        $wpdb->insert($this->table_name, [
            'user_id' => $user_id,
            'estado' => 'activo',
            'fecha_ingreso' => current_time('Y-m-d'),
        ]);
        
        wp_send_json_success([
            'message' => 'Empleado creado correctamente',
            'user_id' => $user_id
        ]);
    }
    
    /**
     * Cuando se elimina un usuario WP
     */
    public function on_user_deleted($user_id) {
        global $wpdb;
        $wpdb->delete($this->table_name, ['user_id' => $user_id]);
    }
    
    /**
     * Asigna un rol Riverso a un usuario
     */
    private function assign_riverso_role($user_id, $role) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }
        
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor', 'riverso_cotizador'];
        
        // Remover roles Riverso existentes
        foreach ($riverso_roles as $r) {
            $user->remove_role($r);
        }
        
        // Agregar nuevo rol si es válido
        if (in_array($role, $riverso_roles)) {
            $user->add_role($role);
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtiene el rol Riverso de un usuario
     */
    private function get_user_riverso_role($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return 'none';
        }
        
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor', 'riverso_cotizador'];
        
        // Primero buscar en los roles de WordPress
        foreach ($user->roles as $role) {
            if (in_array($role, $riverso_roles)) {
                return $role;
            }
        }
        
        if (in_array('administrator', $user->roles)) {
            return 'administrator';
        }
        
        // Si no tiene rol asignado, verificar por capabilities
        // Esto cubre casos donde se asignaron permisos pero no el rol
        foreach ($riverso_roles as $role) {
            if ($user->has_cap($role)) {
                return $role;
            }
        }
        
        // También verificar riverso_vendedor como alias de riverso_ventas
        if ($user->has_cap('riverso_vendedor')) {
            return 'riverso_ventas';
        }
        
        return 'none';
    }
    
    /**
     * Obtiene nombre legible del rol
     */
    private function get_role_display_name($role) {
        $names = [
            'riverso_admin' => 'Administrador Riverso',
            'riverso_ventas' => 'Vendedor',
            'riverso_bodega' => 'Operador Bodega',
            'riverso_compras' => 'Operador Compras',
            'riverso_recepciones' => 'Recepcionista',
            'riverso_editor' => 'Editor Catálogo',
            'riverso_cotizador' => 'Cotizador',
            'administrator' => 'Administrador WP',
        ];
        
        return $names[$role] ?? $role;
    }
    
    /**
     * Lista de roles Riverso disponibles
     */
    private function get_riverso_roles() {
        return [
            'riverso_admin' => 'Administrador Riverso',
            'riverso_ventas' => 'Vendedor',
            'riverso_bodega' => 'Operador Bodega',
            'riverso_compras' => 'Operador Compras',
            'riverso_recepciones' => 'Recepcionista',
            'riverso_editor' => 'Editor Catálogo',
            'riverso_cotizador' => 'Cotizador',
        ];
    }
    
    /**
     * Limpia RUT
     */
    private function clean_rut($rut) {
        return preg_replace('/[^0-9kK]/', '', strtoupper($rut));
    }
    
    /**
     * API: Obtener empleado por user_id
     */
    public function get_by_user_id($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));
    }
    
    /**
     * API: Listar empleados activos (para selects)
     */
    public function get_active_employees() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT e.*, u.display_name, u.user_email 
             FROM {$this->table_name} e
             JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE e.estado = 'activo'
             ORDER BY u.display_name"
        );
    }
}
