<?php
/**
 * Agregador purchases — OC + recepción; invoices/quotes siguen en modules/.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchases_Module {

    private static $booted = false;

    public static function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $po = RIVERSO_POS_PLUGIN_DIR . 'purchases/purchase_orders/class-purchase-order-module.php';
        if (file_exists($po)) {
            require_once $po;
            if (class_exists('Riverso_Purchase_Order_Module')) {
                Riverso_Purchase_Order_Module::get_instance()->init();
            }
        }

        $rx = RIVERSO_POS_PLUGIN_DIR . 'purchases/reception/class-reception-service.php';
        if (file_exists($rx)) {
            require_once $rx;
        }
    }
}
