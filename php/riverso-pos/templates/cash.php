<?php
/**
 * Template: Manejo de Caja (admin wrapper).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Cash_Module')) {
    $mod = RIVERSO_POS_PLUGIN_DIR . 'sales/cash/class-cash-module.php';
    if (file_exists($mod)) {
        require_once $mod;
    }
}

$module = class_exists('Riverso_Cash_Module')
    ? Riverso_Cash_Module::get_instance()
    : null;

if ($module && method_exists($module, 'render_app')) {
    $module->render_app('admin');
    return;
}

echo '<div class="wrap"><p>No se pudo cargar el módulo de caja.</p></div>';
