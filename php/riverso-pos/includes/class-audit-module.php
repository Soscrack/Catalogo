<?php
/**
 * Compatibilidad: implementación en core/audit/.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Audit_Module')) {
    require_once dirname(__DIR__) . '/core/audit/class-audit-module.php';
}
