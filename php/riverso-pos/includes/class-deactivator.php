<?php
/**
 * Desactivador del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Deactivator {
    
    /**
     * Ejecuta al desactivar el plugin
     */
    public static function deactivate() {
        // Limpiar tareas programadas
        wp_clear_scheduled_hook('riverso_pos_daily_cleanup');
        wp_clear_scheduled_hook('riverso_pos_sync_stock');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Marcar como desactivado
        update_option('riverso_pos_deactivated', time());
        
        // NO eliminamos roles ni tablas en desactivación
        // Eso solo se hace en uninstall.php
    }
}
