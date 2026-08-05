<?php
/**
 * Compat shim: la implementación canónica vive en modules/codes.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/modules/codes/class-supplier-links-module.php';
