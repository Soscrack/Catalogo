<?php
/**
 * Compat shim: la implementación canónica vive en modules/barcodes.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/modules/barcodes/class-ean13-generator.php';
