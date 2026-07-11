<?php
/**
 * Script de migración: Conectar empleados legacy con usuarios WP
 * 
 * Este script vincula usuarios WP con roles riverso_* a la tabla riverso_empleados
 * si aún no tienen un registro. Se ejecuta opcionalmente post-upgrade.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

function riverso_migrate_employees_legacy() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    
    // Obtener todos los usuarios con roles riverso_*
    $riverso_roles = [
        'riverso_admin',
        'riverso_ventas',
        'riverso_bodega',
        'riverso_compras',
        'riverso_recepciones',
        'riverso_editor',
        'riverso_cotizador',
    ];
    
    foreach ($riverso_roles as $role) {
        $users = get_users(['role' => $role]);
        
        foreach ($users as $user) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$prefix}riverso_empleados WHERE user_id = %d",
                    $user->ID
                )
            );
            
            if ($existing) {
                continue;
            }
            
            // Crear perfil de empleado vinculado a usuario
            $wpdb->insert(
                "{$prefix}riverso_empleados",
                [
                    'user_id' => $user->ID,
                    'nombre' => $user->display_name,
                    'estado' => 'activo',
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s']
            );
        }
    }
    
    // Marcar que la migración se completó
    update_option('riverso_pos_employees_migration_v1_completed', 1);
}

/**
 * Migración de auditoría: poblar employee_id desde user_id
 */
function riverso_migrate_audit_employees() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    
    // Actualizar registros de auditoría: obtener employee_id del user_id
    $wpdb->query("
        UPDATE {$prefix}riverso_audit_log al
        INNER JOIN {$prefix}riverso_empleados e ON e.user_id = al.user_id
        SET al.employee_id = e.id
        WHERE al.employee_id IS NULL
          AND al.user_id IS NOT NULL
    ");
    
    update_option('riverso_pos_audit_employees_migration_v1_completed', 1);
}

/**
 * Migración de tareas: poblar employee_id desde asignado_a (user_id)
 */
function riverso_migrate_tasks_employees() {
    global $wpdb;
    $prefix = $wpdb->prefix;
    
    $wpdb->query("
        UPDATE {$prefix}riverso_tareas t
        INNER JOIN {$prefix}riverso_empleados e ON e.user_id = t.asignado_a
        SET t.employee_id = e.id
        WHERE t.employee_id IS NULL
          AND t.asignado_a IS NOT NULL
    ");
    
    update_option('riverso_pos_tasks_employees_migration_v1_completed', 1);
}

// Ejecutar migraciones si no se han completado
if (!get_option('riverso_pos_employees_migration_v1_completed')) {
    riverso_migrate_employees_legacy();
}

if (!get_option('riverso_pos_audit_employees_migration_v1_completed')) {
    riverso_migrate_audit_employees();
}

if (!get_option('riverso_pos_tasks_employees_migration_v1_completed')) {
    riverso_migrate_tasks_employees();
}

echo "Migraciones de empleados completadas.\n";
