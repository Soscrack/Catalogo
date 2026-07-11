<?php
/**
 * Carga forzada de clases core (fuente de verdad) + wrappers legacy.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$riverso_core_requires = [
    'core/events/class-event-bus.php',
    'core/audit/class-audit.php',
    'core/audit/class-audit-module.php',
    'core/permissions/class-permissions.php',
    'core/auth/class-auth-service.php',
    'core/tasks/class-task-service.php',
    'core/employees/class-employee.php',
    'catalog/barcodes/class-barcode-model.php',
    'inventory/movements/class-movement.php',
];

foreach ($riverso_core_requires as $rel) {
    $path = RIVERSO_POS_PLUGIN_DIR . $rel;
    if (file_exists($path)) {
        require_once $path;
    }
}

// Fallback: si core no tiene audit/permissions, usar includes legacy.
if (!class_exists('Riverso_POS_Audit') && file_exists(RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit.php')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit.php';
}
if (!class_exists('Riverso_Audit_Module') && file_exists(RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit-module.php')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit-module.php';
}
if (!class_exists('Riverso_POS_Permissions') && file_exists(RIVERSO_POS_PLUGIN_DIR . 'includes/class-permissions.php')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-permissions.php';
}
