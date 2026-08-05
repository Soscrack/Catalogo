<?php
/**
 * Compat shim: la implementación canónica vive en modules/packaging.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/modules/packaging/class-packaging-module.php';
