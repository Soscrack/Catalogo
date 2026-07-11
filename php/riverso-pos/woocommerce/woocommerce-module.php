<?php
/**
 * Módulo WooCommerce Sync (sin re-inicializar publish/import ya cargados desde modules/).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_WooCommerce_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        $sync = RIVERSO_POS_PLUGIN_DIR . 'woocommerce/sync/class-woo-sync-manager.php';
        if (file_exists($sync)) {
            require_once $sync;
            Riverso_Woo_Sync_Manager::get_instance()->init();
        }

        // Tras movimiento de inventario, intentar sync de stock.
        add_action('riverso_inventory_movement_created', [$this, 'on_movement'], 10, 1);
        add_action('riverso_pos_invoice_approved', [$this, 'on_invoice_approved'], 20, 1);
    }

    public function on_movement($payload) {
        if (!is_array($payload) || empty($payload['producto_base_id'])) {
            return;
        }
        if (class_exists('Riverso_Woo_Sync_Manager')) {
            Riverso_Woo_Sync_Manager::get_instance()->sync_stock_to_woocommerce(
                intval($payload['producto_base_id'])
            );
        }
    }

    public function on_invoice_approved($factura_id) {
        // Reconciliación ligera tras recepción.
        if (class_exists('Riverso_Woo_Sync_Manager')) {
            Riverso_Woo_Sync_Manager::get_instance()->reconcile_stock();
        }
    }
}
