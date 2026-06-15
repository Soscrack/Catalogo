<?php
/**
 * Gestión de permisos y roles para Riverso POS/ERP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Permissions {
    
    /**
     * Capacidad protegida que no se puede quitar (solo admin)
     */
    const PROTECTED_CAPABILITY = 'riverso_manage_permissions';
    
    /**
     * Todas las capacidades del plugin organizadas por módulo
     */
    const CAPABILITIES = [
        // === SISTEMA ===
        'riverso_manage_permissions' => '🔒 Gestionar permisos (protegido)',
        'riverso_access_portal'      => 'Acceder al portal interno',
        'riverso_manage_system'      => 'Administrar sistema completo',
        'riverso_manage_settings'    => 'Gestionar configuración',
        'riverso_manage_users'       => 'Gestionar usuarios empleados',
        'riverso_view_audit'         => 'Ver auditoría',
        
        // === PRODUCTOS / CATÁLOGO ===
        'riverso_view_products'      => 'Ver productos',
        'riverso_edit_products'      => 'Editar productos',
        'riverso_edit_skus'          => 'Editar SKUs internos',
        
        // === STOCK / BODEGA ===
        'riverso_view_stock'         => 'Ver stock',
        'riverso_edit_stock'         => 'Ajustar stock',
        'riverso_view_warehouse'     => 'Ver ubicaciones bodega',
        'riverso_edit_warehouse'     => 'Editar ubicaciones bodega',
        
        // === COTIZACIONES EMITIDAS (a clientes) ===
        'riverso_view_quotes'        => 'Ver cotizaciones clientes',
        'riverso_create_quotes'      => 'Crear cotizaciones clientes',
        'riverso_approve_quotes'     => 'Aprobar cotizaciones clientes',
        
        // === VENTAS / POS ===
        'riverso_use_pos'            => 'Usar POS interno',
        'riverso_create_sales'       => 'Crear ventas',
        'riverso_view_sales'         => 'Ver ventas',
        'riverso_apply_discounts'    => 'Aplicar descuentos',
        'riverso_create_orders'      => 'Crear órdenes/ventas en POS',
        'riverso_view_orders'        => 'Ver órdenes POS',
        'riverso_void_orders'        => 'Anular órdenes POS',
        'riverso_unlimited_discounts' => 'Aplicar descuentos sin límite',
        
        // === COMPRAS / ABASTECIMIENTO ===
        'riverso_view_received_quotes'    => 'Ver cotizaciones recibidas',
        'riverso_create_received_quotes'  => 'Ingresar cotizaciones recibidas',
        'riverso_edit_received_quotes'    => 'Editar cotizaciones recibidas',
        'riverso_approve_received_quotes' => 'Aprobar cotizaciones recibidas',
        
        // === FACTURAS RECIBIDAS ===
        'riverso_view_invoices'      => 'Ver facturas recibidas',
        'riverso_create_invoices'    => 'Ingresar facturas',
        'riverso_process_invoices'   => 'Procesar facturas',
        'riverso_approve_invoices'   => 'Aprobar facturas',
        
        // === RECEPCIÓN FÍSICA ===
        'riverso_receive_items'      => 'Registrar recepción física',
        'riverso_approve_reception'  => 'Aprobar recepción',
        
        // === TAREAS ===
        'riverso_view_tasks'         => 'Ver tareas',
        'riverso_create_tasks'       => 'Crear tareas',
        'riverso_complete_tasks'     => 'Completar tareas propias',
        'riverso_assign_tasks'       => 'Asignar tareas a otros',
        'riverso_approve_tasks'      => 'Aprobar tareas',
        
        // === CÓDIGOS / PROVEEDORES ===
        'riverso_manage_codes'       => 'Gestionar códigos proveedor',
        'riverso_view_suppliers'     => 'Ver proveedores',
        'riverso_edit_suppliers'     => 'Editar proveedores',
        
        // === COSTOS ===
        'riverso_view_costs'         => 'Ver historial de costos',
        'riverso_manage_costs'       => 'Registrar y editar costos',
        'riverso_approve_price_alerts' => 'Aprobar alertas de precio',

        // === PRECIOS ===
        'riverso_view_prices'        => 'Ver precios y listas',
        'riverso_manage_prices'      => 'Gestionar precios y reglas',
        'riverso_approve_prices'     => 'Aprobar precios y listas',

        // === MATCHING / EMPAREJAMIENTO ===
        'riverso_manage_matching'    => 'Gestionar emparejamiento de productos',

        // === EMBOLSADO / EAN13 ===
        'riverso_manage_packaging'   => 'Gestionar embolsado y bolsas',
        'riverso_generate_ean13'     => 'Generar códigos EAN13',
        
        // === CÓDIGOS DE BARRA ===
        'riverso_scan_barcodes'      => 'Escanear códigos de barra',
        'riverso_assign_barcodes'    => 'Asignar códigos de barra',
        
        // === REPORTES ===
        'riverso_view_reports'       => 'Ver reportes',
        'riverso_export_reports'     => 'Exportar reportes',
    ];
    
    /**
     * Categorías de capacidades para UI
     */
    const CAPABILITY_GROUPS = [
        'Sistema' => [
            'riverso_manage_permissions',
            'riverso_access_portal',
            'riverso_manage_system',
            'riverso_manage_settings',
            'riverso_manage_users',
            'riverso_view_audit',
        ],
        'Productos' => [
            'riverso_view_products',
            'riverso_edit_products',
            'riverso_edit_skus',
        ],
        'Stock / Bodega' => [
            'riverso_view_stock',
            'riverso_edit_stock',
            'riverso_view_warehouse',
            'riverso_edit_warehouse',
        ],
        'Cotizaciones a Clientes' => [
            'riverso_view_quotes',
            'riverso_create_quotes',
            'riverso_approve_quotes',
        ],
        'Ventas / POS' => [
            'riverso_use_pos',
            'riverso_create_sales',
            'riverso_view_sales',
            'riverso_apply_discounts',
            'riverso_create_orders',
            'riverso_view_orders',
            'riverso_void_orders',
            'riverso_unlimited_discounts',
        ],
        'Cotizaciones Recibidas' => [
            'riverso_view_received_quotes',
            'riverso_create_received_quotes',
            'riverso_edit_received_quotes',
            'riverso_approve_received_quotes',
        ],
        'Facturas Recibidas' => [
            'riverso_view_invoices',
            'riverso_create_invoices',
            'riverso_process_invoices',
            'riverso_approve_invoices',
        ],
        'Recepción' => [
            'riverso_receive_items',
            'riverso_approve_reception',
        ],
        'Tareas' => [
            'riverso_view_tasks',
            'riverso_create_tasks',
            'riverso_complete_tasks',
            'riverso_assign_tasks',
            'riverso_approve_tasks',
        ],
        'Proveedores / Códigos' => [
            'riverso_manage_codes',
            'riverso_view_suppliers',
            'riverso_edit_suppliers',
        ],
        'Costos' => [
            'riverso_view_costs',
            'riverso_manage_costs',
            'riverso_approve_price_alerts',
        ],
        'Precios' => [
            'riverso_view_prices',
            'riverso_manage_prices',
            'riverso_approve_prices',
        ],
        'Matching' => [
            'riverso_manage_matching',
        ],
        'Embolsado / EAN13' => [
            'riverso_manage_packaging',
            'riverso_generate_ean13',
        ],
        'Códigos de Barra' => [
            'riverso_scan_barcodes',
            'riverso_assign_barcodes',
        ],
        'Reportes' => [
            'riverso_view_reports',
            'riverso_export_reports',
        ],
    ];
    
    /**
     * Roles del plugin con sus capacidades
     */
    const ROLES = [
        // Administrador Riverso - acceso total
        'riverso_admin' => [
            'name' => 'Administrador Riverso',
            'description' => 'Acceso total al sistema ERP',
            'capabilities' => 'ALL', // Todas las capacidades
        ],
        
        // Ventas - POS, cotizaciones, clientes
        'riverso_ventas' => [
            'name' => 'Vendedor',
            'description' => 'Ventas POS y cotizaciones a clientes',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_view_warehouse',
                'riverso_use_pos',
                'riverso_create_sales',
                'riverso_view_sales',
                'riverso_apply_discounts',
                'riverso_create_orders',
                'riverso_view_orders',
                'riverso_view_prices',
                'riverso_view_quotes',
                'riverso_create_quotes',
                'riverso_view_tasks',
                'riverso_complete_tasks',
                'riverso_scan_barcodes',
            ]
        ],
        
        // Bodega - operaciones físicas
        'riverso_bodega' => [
            'name' => 'Operador Bodega',
            'description' => 'Etiquetado, bodegaje, recepción física',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_view_warehouse',
                'riverso_edit_warehouse',
                'riverso_receive_items',
                'riverso_view_tasks',
                'riverso_complete_tasks',
                'riverso_scan_barcodes',
                'riverso_assign_barcodes',
            ]
        ],
        
        // Compras - documentos de entrada
        'riverso_compras' => [
            'name' => 'Operador Compras',
            'description' => 'Cotizaciones y facturas de proveedores',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_view_received_quotes',
                'riverso_create_received_quotes',
                'riverso_edit_received_quotes',
                'riverso_approve_received_quotes',
                'riverso_view_invoices',
                'riverso_create_invoices',
                'riverso_process_invoices',
                'riverso_view_suppliers',
                'riverso_edit_suppliers',
                'riverso_manage_codes',
                'riverso_view_costs',
                'riverso_view_prices',
                'riverso_manage_matching',
                'riverso_view_tasks',
                'riverso_create_tasks',
                'riverso_complete_tasks',
            ]
        ],
        
        // Recepciones - validación física
        'riverso_recepciones' => [
            'name' => 'Recepcionista',
            'description' => 'Recepción y validación de mercadería',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_view_invoices',
                'riverso_receive_items',
                'riverso_approve_reception',
                'riverso_view_warehouse',
                'riverso_view_tasks',
                'riverso_complete_tasks',
                'riverso_scan_barcodes',
            ]
        ],
        
        // Editor - catálogo y datos maestros
        'riverso_editor' => [
            'name' => 'Editor Catálogo',
            'description' => 'Edición de productos, SKUs, códigos',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_edit_products',
                'riverso_edit_skus',
                'riverso_view_stock',
                'riverso_edit_stock',
                'riverso_view_warehouse',
                'riverso_edit_warehouse',
                'riverso_manage_codes',
                'riverso_view_suppliers',
                'riverso_assign_barcodes',
                'riverso_view_prices',
                'riverso_manage_prices',
                'riverso_manage_matching',
                'riverso_manage_packaging',
                'riverso_generate_ean13',
                'riverso_view_tasks',
                'riverso_create_tasks',
                'riverso_complete_tasks',
                'riverso_view_reports',
            ]
        ],
        
        // Cotizador - solo cotizaciones (rol legacy)
        'riverso_cotizador' => [
            'name' => 'Cotizador',
            'description' => 'Solo crear cotizaciones a clientes',
            'capabilities' => [
                'riverso_access_portal',
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_view_quotes',
                'riverso_create_quotes',
            ]
        ],
    ];
    
    /**
     * Verifica si un usuario es empleado interno (tiene rol riverso_*)
     */
    public static function is_employee($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        $user = get_userdata($user_id);
        if (!$user) return false;
        
        // Verificar roles formales
        foreach ($user->roles as $role) {
            if (strpos($role, 'riverso_') === 0 || $role === 'administrator') {
                return true;
            }
        }
        
        // Verificar capabilities de Riverso (para usuarios con permisos sin rol formal)
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor', 'riverso_cotizador', 'riverso_vendedor'];
        foreach ($riverso_roles as $role_cap) {
            if ($user->has_cap($role_cap)) {
                return true;
            }
        }
        
        // También verificar si tiene acceso al portal
        if ($user->has_cap('riverso_access_portal')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Obtiene el rol Riverso principal del usuario
     */
    public static function get_riverso_role($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        $user = get_userdata($user_id);
        if (!$user) return null;
        
        // Primero verificar roles formales
        foreach ($user->roles as $role) {
            if (strpos($role, 'riverso_') === 0) {
                return $role;
            }
        }
        
        // Verificar por capabilities
        $riverso_roles = ['riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor', 'riverso_cotizador'];
        foreach ($riverso_roles as $role_cap) {
            if ($user->has_cap($role_cap)) {
                return $role_cap;
            }
        }
        
        // Alias de vendedor
        if ($user->has_cap('riverso_vendedor')) {
            return 'riverso_ventas';
        }
        
        return $user->has_cap('administrator') ? 'administrator' : null;
    }
    
    /**
     * Verifica si el usuario actual tiene una capacidad
     */
    public static function current_user_can($capability) {
        return current_user_can($capability);
    }
    
    /**
     * Verifica múltiples capacidades (AND)
     */
    public static function current_user_can_all(array $capabilities) {
        foreach ($capabilities as $cap) {
            if (!current_user_can($cap)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Verifica múltiples capacidades (OR)
     */
    public static function current_user_can_any(array $capabilities) {
        foreach ($capabilities as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Obtiene usuarios con un rol específico
     */
    public static function get_users_with_role($role) {
        return get_users(['role' => $role]);
    }
    
    /**
     * Obtiene todos los empleados (usuarios con roles riverso_*)
     */
    public static function get_all_employees() {
        $employees = [];
        foreach (array_keys(self::ROLES) as $role) {
            $users = get_users(['role' => $role]);
            $employees = array_merge($employees, $users);
        }
        // También incluir administradores
        $admins = get_users(['role' => 'administrator']);
        $employees = array_merge($employees, $admins);
        
        // Eliminar duplicados por ID
        $unique = [];
        foreach ($employees as $user) {
            $unique[$user->ID] = $user;
        }
        return array_values($unique);
    }
    
    /**
     * Obtiene usuarios que pueden completar tareas
     */
    public static function get_task_workers() {
        return self::get_all_employees();
    }
    
    /**
     * Crea o actualiza todos los roles del plugin
     */
    public static function setup_roles() {
        $base_caps = ['read' => true];
        $all_caps = array_keys(self::CAPABILITIES);
        
        foreach (self::ROLES as $role_key => $role_config) {
            // Remover rol si existe para recrearlo
            remove_role($role_key);
            
            // Determinar capacidades
            $caps = $base_caps;
            if ($role_config['capabilities'] === 'ALL') {
                foreach ($all_caps as $cap) {
                    $caps[$cap] = true;
                }
            } else {
                foreach ($role_config['capabilities'] as $cap) {
                    $caps[$cap] = true;
                }
            }
            
            // Crear rol
            add_role($role_key, $role_config['name'], $caps);
        }
        
        // Agregar todas las capacidades al administrador
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($all_caps as $cap) {
                $admin->add_cap($cap);
            }
        }
    }
    
    /**
     * Elimina todos los roles del plugin
     */
    public static function remove_roles() {
        foreach (array_keys(self::ROLES) as $role) {
            remove_role($role);
        }
        
        // Remover capacidades del admin
        $admin = get_role('administrator');
        if ($admin) {
            foreach (array_keys(self::CAPABILITIES) as $cap) {
                $admin->remove_cap($cap);
            }
        }
    }
    
    /**
     * Obtiene la URL de redirección según el rol del usuario
     */
    public static function get_redirect_url($user_id = null) {
        if (self::is_employee($user_id)) {
            return home_url('/interno/');
        }
        return wc_get_page_permalink('myaccount');
    }
    
    /**
     * Obtiene los módulos accesibles según los permisos del usuario
     */
    public static function get_accessible_modules($user_id = null) {
        $modules = [];
        
        // Dashboard siempre visible para empleados
        if (current_user_can('riverso_access_portal')) {
            $modules['dashboard'] = ['icon' => 'dashboard', 'label' => 'Dashboard'];
        }
        
        // POS
        if (current_user_can('riverso_use_pos')) {
            $modules['pos'] = ['icon' => 'cart', 'label' => 'Punto de Venta'];
        }
        
        // Cotizaciones a clientes
        if (current_user_can('riverso_view_quotes')) {
            $modules['customer-quotes'] = ['icon' => 'media-document', 'label' => 'Cotizaciones Clientes'];
        }
        
        // Cotizaciones recibidas (proveedores)
        if (current_user_can('riverso_view_received_quotes')) {
            $modules['received-quotes'] = ['icon' => 'download', 'label' => 'Cotizaciones Proveedores'];
        }
        
        // Tareas
        if (current_user_can('riverso_view_tasks')) {
            $modules['tasks'] = ['icon' => 'clipboard', 'label' => 'Tareas'];
        }
        
        // Bodega
        if (current_user_can('riverso_view_warehouse')) {
            $modules['warehouse'] = ['icon' => 'store', 'label' => 'Bodega'];
        }
        
        // Facturas recibidas
        if (current_user_can('riverso_view_invoices')) {
            $modules['invoices'] = ['icon' => 'media-spreadsheet', 'label' => 'Facturas'];
        }
        
        // Códigos de Barra
        if (current_user_can('riverso_scan_barcodes') || current_user_can('riverso_assign_barcodes')) {
            $modules['barcodes'] = ['icon' => 'barcode', 'label' => 'Códigos de Barra'];
        }
        
        // Códigos Proveedor / SKU Links
        if (current_user_can('riverso_manage_codes')) {
            $modules['codes'] = ['icon' => 'admin-links', 'label' => 'Códigos Proveedor'];
        }
        
        // Proveedores
        if (current_user_can('riverso_view_suppliers')) {
            $modules['suppliers'] = ['icon' => 'groups', 'label' => 'Proveedores'];
        }
        
        // Historial de Costos
        if (current_user_can('riverso_view_costs')) {
            $modules['cost-history'] = ['icon' => 'chart-line', 'label' => 'Historial Costos'];
        }
        
        // Empleados
        if (current_user_can('riverso_manage_users')) {
            $modules['employees'] = ['icon' => 'admin-users', 'label' => 'Empleados'];
        }
        
        // Reportes
        if (current_user_can('riverso_view_reports')) {
            $modules['reports'] = ['icon' => 'chart-bar', 'label' => 'Reportes'];
        }
        
        // Configuración
        if (current_user_can('riverso_manage_settings')) {
            $modules['settings'] = ['icon' => 'admin-generic', 'label' => 'Configuración'];
        }
        
        return $modules;
    }
    
    /**
     * Inicializa hooks AJAX para gestión de permisos
     */
    public static function init_ajax() {
        add_action('wp_ajax_riverso_get_all_permissions', [__CLASS__, 'ajax_get_all_permissions']);
        add_action('wp_ajax_riverso_update_role_capability', [__CLASS__, 'ajax_update_role_capability']);
        add_action('wp_ajax_riverso_update_user_capability', [__CLASS__, 'ajax_update_user_capability']);
        add_action('wp_ajax_riverso_get_user_permissions', [__CLASS__, 'ajax_get_user_permissions']);
    }
    
    /**
     * Obtiene todos los permisos de todos los roles
     */
    public static function ajax_get_all_permissions() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!self::can_manage_permissions()) {
            wp_send_json_error(['message' => 'Sin permisos para gestionar permisos']);
        }
        
        $roles_data = [];
        $all_roles = self::get_all_manageable_roles();
        
        foreach ($all_roles as $role_key => $role_name) {
            $role = get_role($role_key);
            if (!$role) continue;
            
            $caps = [];
            foreach (array_keys(self::CAPABILITIES) as $cap) {
                $caps[$cap] = !empty($role->capabilities[$cap]);
            }
            
            $roles_data[$role_key] = [
                'name' => $role_name,
                'capabilities' => $caps,
                'is_admin' => ($role_key === 'administrator'),
            ];
        }
        
        wp_send_json_success([
            'roles' => $roles_data,
            'capabilities' => self::CAPABILITIES,
            'groups' => self::CAPABILITY_GROUPS,
            'protected' => self::PROTECTED_CAPABILITY,
        ]);
    }
    
    /**
     * Actualiza una capacidad de un rol
     */
    public static function ajax_update_role_capability() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!self::can_manage_permissions()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $role_key = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
        $capability = isset($_POST['capability']) ? sanitize_text_field($_POST['capability']) : '';
        $granted = isset($_POST['granted']) ? ($_POST['granted'] === 'true' || $_POST['granted'] === '1') : false;
        
        // Validar rol
        $role = get_role($role_key);
        if (!$role) {
            wp_send_json_error(['message' => 'Rol no encontrado']);
        }
        
        // Validar capacidad
        if (!array_key_exists($capability, self::CAPABILITIES)) {
            wp_send_json_error(['message' => 'Capacidad no válida']);
        }
        
        // Proteger riverso_manage_permissions para administrator
        if ($capability === self::PROTECTED_CAPABILITY && $role_key === 'administrator' && !$granted) {
            wp_send_json_error(['message' => 'No se puede quitar este permiso al administrador']);
        }
        
        // Actualizar
        if ($granted) {
            $role->add_cap($capability);
        } else {
            $role->remove_cap($capability);
        }
        
        wp_send_json_success(['message' => 'Permiso actualizado']);
    }
    
    /**
     * Obtiene permisos de un usuario específico
     */
    public static function ajax_get_user_permissions() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!self::can_manage_permissions()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $user = get_userdata($user_id);
        
        if (!$user) {
            wp_send_json_error(['message' => 'Usuario no encontrado']);
        }
        
        $caps = [];
        foreach (array_keys(self::CAPABILITIES) as $cap) {
            $caps[$cap] = $user->has_cap($cap);
        }
        
        wp_send_json_success([
            'user_id' => $user_id,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'roles' => array_values($user->roles),
            'capabilities' => $caps,
        ]);
    }
    
    /**
     * Actualiza una capacidad de un usuario específico
     */
    public static function ajax_update_user_capability() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!self::can_manage_permissions()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $capability = isset($_POST['capability']) ? sanitize_text_field($_POST['capability']) : '';
        $granted = isset($_POST['granted']) ? ($_POST['granted'] === 'true' || $_POST['granted'] === '1') : false;
        
        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => 'Usuario no encontrado']);
        }
        
        // Validar capacidad
        if (!array_key_exists($capability, self::CAPABILITIES)) {
            wp_send_json_error(['message' => 'Capacidad no válida']);
        }
        
        // Proteger riverso_manage_permissions para admins
        if ($capability === self::PROTECTED_CAPABILITY && in_array('administrator', $user->roles) && !$granted) {
            wp_send_json_error(['message' => 'No se puede quitar este permiso a un administrador']);
        }
        
        // Actualizar
        if ($granted) {
            $user->add_cap($capability);
        } else {
            $user->remove_cap($capability);
        }
        
        wp_send_json_success(['message' => 'Permiso de usuario actualizado']);
    }
    
    /**
     * Verifica si el usuario actual puede gestionar permisos
     */
    public static function can_manage_permissions() {
        // Admin de WP siempre puede
        if (current_user_can('manage_options')) {
            return true;
        }
        // O si tiene el permiso específico
        return current_user_can(self::PROTECTED_CAPABILITY);
    }
    
    /**
     * Obtiene todos los roles gestionables
     */
    public static function get_all_manageable_roles() {
        $roles = [
            'administrator' => 'Administrador',
        ];
        
        foreach (self::ROLES as $role_key => $config) {
            $roles[$role_key] = $config['name'];
        }
        
        return $roles;
    }
}
