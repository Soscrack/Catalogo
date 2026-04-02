<?php
/**
 * Gestión de permisos y roles
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Permissions {
    
    /**
     * Todas las capacidades del plugin
     */
    const CAPABILITIES = [
        // Productos
        'riverso_view_products'    => 'Ver productos',
        'riverso_edit_products'    => 'Editar productos',
        
        // Stock
        'riverso_view_stock'       => 'Ver stock',
        'riverso_edit_stock'       => 'Editar stock',
        
        // Cotizaciones
        'riverso_create_quotes'    => 'Crear cotizaciones',
        'riverso_view_quotes'      => 'Ver cotizaciones',
        
        // Ventas
        'riverso_create_sales'     => 'Crear ventas',
        'riverso_view_sales'       => 'Ver ventas',
        
        // Facturas
        'riverso_view_invoices'    => 'Ver facturas',
        'riverso_process_invoices' => 'Procesar facturas',
        
        // Tareas
        'riverso_view_tasks'       => 'Ver tareas',
        'riverso_create_tasks'     => 'Crear tareas',
        'riverso_complete_tasks'   => 'Completar tareas',
        'riverso_assign_tasks'     => 'Asignar tareas',
        
        // Códigos
        'riverso_manage_codes'     => 'Gestionar códigos',
        
        // Reportes
        'riverso_view_reports'     => 'Ver reportes',
        
        // Administración
        'riverso_manage_settings'  => 'Gestionar configuración',
        'riverso_manage_users'     => 'Gestionar usuarios POS',
    ];
    
    /**
     * Roles del plugin
     */
    const ROLES = [
        'riverso_cotizador' => [
            'name' => 'Cotizador',
            'capabilities' => [
                'riverso_view_products',
                'riverso_create_quotes',
                'riverso_view_quotes',
            ]
        ],
        'riverso_vendedor' => [
            'name' => 'Vendedor',
            'capabilities' => [
                'riverso_view_products',
                'riverso_view_stock',
                'riverso_create_quotes',
                'riverso_view_quotes',
                'riverso_create_sales',
                'riverso_view_sales',
                'riverso_view_tasks',
                'riverso_complete_tasks',
            ]
        ],
        'riverso_editor' => [
            'name' => 'Editor POS',
            'capabilities' => [
                'riverso_view_products',
                'riverso_edit_products',
                'riverso_view_stock',
                'riverso_edit_stock',
                'riverso_create_quotes',
                'riverso_view_quotes',
                'riverso_create_sales',
                'riverso_view_sales',
                'riverso_view_invoices',
                'riverso_process_invoices',
                'riverso_view_tasks',
                'riverso_create_tasks',
                'riverso_complete_tasks',
                'riverso_assign_tasks',
                'riverso_manage_codes',
                'riverso_view_reports',
            ]
        ],
    ];
    
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
     * Obtiene usuarios que pueden completar tareas
     */
    public static function get_task_workers() {
        return get_users([
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => $GLOBALS['wpdb']->prefix . 'capabilities',
                    'value' => 'riverso_complete_tasks',
                    'compare' => 'LIKE'
                ]
            ]
        ]);
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
}
