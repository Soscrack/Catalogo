<?php
/**
 * Compat shim: implementación canónica en pricing/price_lists.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/pricing/price_lists/class-price-rules-module.php';
