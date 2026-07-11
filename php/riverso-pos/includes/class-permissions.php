<?php
/**
 * Compatibilidad: implementación en core/permissions/.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_POS_Permissions')) {
    require_once dirname(__DIR__) . '/core/permissions/class-permissions.php';
}
