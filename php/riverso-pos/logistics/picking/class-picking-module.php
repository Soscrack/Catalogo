<?php
/**
 * Módulo de picking (Fase 6) — stub
 *
 * Genera listas de picking a partir de IDs de pedidos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Picking_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_picking_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_picking_get', [$this, 'ajax_get']);
    }

    /**
     * Crea una lista de picking desde IDs de pedidos WC / internos.
     *
     * @param array $order_ids
     * @return array
     */
    public function create_pick_list($order_ids) {
        $order_ids = array_filter(array_map('intval', (array) $order_ids));
        $lines = [];

        foreach ($order_ids as $order_id) {
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    foreach ($order->get_items() as $item) {
                        $product_id = $item->get_variation_id() ?: $item->get_product_id();
                        $lines[] = [
                            'order_id' => $order_id,
                            'product_id' => $product_id,
                            'name' => $item->get_name(),
                            'qty' => floatval($item->get_quantity()),
                            'sku' => $item->get_product() ? $item->get_product()->get_sku() : '',
                        ];
                    }
                    continue;
                }
            }

            // Stub sin WC: solo referencia al pedido
            $lines[] = [
                'order_id' => $order_id,
                'product_id' => 0,
                'name' => 'Pedido #' . $order_id,
                'qty' => 0,
                'sku' => '',
            ];
        }

        $pick_id = 'PICK-' . gmdate('YmdHis') . '-' . wp_generate_password(3, false, false);

        return [
            'pick_id' => $pick_id,
            'order_ids' => $order_ids,
            'lines' => $lines,
            'created_at' => current_time('mysql'),
            'status' => 'pendiente',
        ];
    }

    public function ajax_create() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_picking') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $ids_raw = isset($_POST['order_ids']) ? wp_unslash($_POST['order_ids']) : [];
        $ids = is_array($ids_raw) ? $ids_raw : json_decode($ids_raw, true);
        if (!is_array($ids) || empty($ids)) {
            wp_send_json_error(['message' => 'order_ids requerido']);
        }

        wp_send_json_success($this->create_pick_list($ids));
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_picking') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        // Stub: sin persistencia aún
        wp_send_json_error(['message' => 'Persistencia de picking pendiente']);
    }
}
