<?php
/**
 * Template: Cotizaciones de venta (P0+P1)
 * Lista + editor base; estados Borrador / Lista.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Customer_Quote_Module')) {
    $sales = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-module.php';
    if (file_exists($sales)) {
        require_once $sales;
    }
}

$module = class_exists('Riverso_Customer_Quote_Module')
    ? Riverso_Customer_Quote_Module::get_instance()
    : null;

if ($module && method_exists($module, 'render_app')) {
    $module->render_app();
    return;
}

echo '<div class="wrap"><p>No se pudo cargar el módulo de cotizaciones de venta.</p></div>';