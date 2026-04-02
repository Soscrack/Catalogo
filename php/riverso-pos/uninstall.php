<?php
/**
 * Uninstall script - Se ejecuta al eliminar el plugin
 */

// Si no se llamó desde WordPress, salir
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verificar permisos
if (!current_user_can('delete_plugins')) {
    exit;
}

global $wpdb;

$prefix = $wpdb->prefix . 'riverso_';

// Eliminar tablas (en orden inverso por foreign keys)
$tables = [
    'movimientos',
    'producto_ubicacion',
    'ubicaciones',
    'tareas',
    'factura_items',
    'facturas',
    'codigos_historial',
    'codigos',
    'proveedores'
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
}

// Eliminar roles
remove_role('riverso_cotizador');
remove_role('riverso_vendedor');
remove_role('riverso_editor');

// Eliminar capacidades del admin
$admin = get_role('administrator');
if ($admin) {
    $caps = [
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
        'riverso_manage_settings',
        'riverso_manage_users',
    ];
    
    foreach ($caps as $cap) {
        $admin->remove_cap($cap);
    }
}

// Eliminar opciones
delete_option('riverso_pos_db_version');
delete_option('riverso_pos_settings');
delete_option('riverso_pos_activated');
delete_option('riverso_pos_deactivated');

// Limpiar transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_riverso_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_riverso_%'");
