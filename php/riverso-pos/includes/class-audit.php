<?php
/**
 * Compatibilidad: implementación en core/audit/.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_POS_Audit')) {
    require_once dirname(__DIR__) . '/core/audit/class-audit.php';
}
