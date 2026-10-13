<?php
/**
 * Template: Clientes (admin wrapper).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Customer_Module')) {
    $mod = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-module.php';
    if (file_exists($mod)) {
        require_once $mod;
    }
}

$module = class_exists('Riverso_Customer_Module')
    ? Riverso_Customer_Module::get_instance()
    : null;

if ($module && method_exists($module, 'render_app')) {
    $module->render_app('admin');
    return;
}

echo '<div class="wrap"><p>No se pudo cargar el módulo de clientes.</p></div>';
