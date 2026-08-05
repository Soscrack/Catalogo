<?php
/**
 * Compat shim: la implementación canónica vive en core/tasks.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/core/tasks/class-task-module.php';
