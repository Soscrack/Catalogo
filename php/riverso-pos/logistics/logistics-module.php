<?php
/**
 * Agregador logística — no recarga labels (vive en modules/labels/).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Logistics_Module {

    private static $booted = false;

    public static function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $picking = RIVERSO_POS_PLUGIN_DIR . 'logistics/picking/class-picking-module.php';
        if (file_exists($picking)) {
            require_once $picking;
            if (class_exists('Riverso_Picking_Module')) {
                Riverso_Picking_Module::get_instance()->init();
            }
        }
    }
}
