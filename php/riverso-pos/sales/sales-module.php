<?php
/**
 * Agregador sales — no recarga POS/quotes (viven en modules/).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Sales_Module {

    private static $booted = false;

    public static function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $customers = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-view.php';
        if (file_exists($customers)) {
            require_once $customers;
            if (class_exists('Riverso_Customer_View')) {
                Riverso_Customer_View::get_instance()->init();
            }
        }

        $customer_mod = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-module.php';
        if (file_exists($customer_mod)) {
            require_once $customer_mod;
            if (class_exists('Riverso_Customer_Module')) {
                Riverso_Customer_Module::get_instance()->init();
            }
        }
    }
}
