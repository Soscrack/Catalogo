<?php
/**
 * Compatibilidad: warehouse vive en modules/warehouse/.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Warehouse_Module')) {
    $legacy = dirname(__DIR__, 2) . '/modules/warehouse/class-warehouse-module.php';
    if (file_exists($legacy)) {
        require_once $legacy;
    }
}
