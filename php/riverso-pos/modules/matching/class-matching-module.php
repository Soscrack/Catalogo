<?php
/**
 * Compat shim: implementación canónica en catalog/matching.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/catalog/matching/class-matching-module.php';
