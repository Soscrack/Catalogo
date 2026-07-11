<?php
/**
 * Alias de compatibilidad para clases movidas a core/
 * 
 * Permite que código antiguo siga usando los imports originales
 * sin necesidad de cambiar todos los require_once del proyecto.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

// Las clases están en core/ pero se cargan automáticamente vía Riverso_Class_Loader
// Este archivo simplemente asegura que estén disponibles como si fueran locales

// Nota: El autoloader ya se encarga, pero dejamos esto documentado
// para casos donde se necesite forzar una carga explícita.

// Ejemplos (no necesarios con autoload, pero posibles):
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/audit/class-audit.php';
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/permissions/class-permissions.php';
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/tasks/class-task-module.php';
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/employees/class-employee-module.php';
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/auth/class-auth-service.php';
// require_once RIVERSO_POS_PLUGIN_DIR . 'core/events/class-event-bus.php';
