<?php
/**
 * Compat shim: implementación canónica en sales/pos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/sales/pos/class-pos-module.php';
