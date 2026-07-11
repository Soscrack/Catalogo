<?php
/**
 * Agregador pricing domain — no recarga pricing/costs (viven en modules/).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Pricing_Domain_Module {

    private static $booted = false;

    public static function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        // Las clases Riverso_Pricing_Module / Cost_History se cargan desde modules/.
    }
}
