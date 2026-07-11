<?php
/**
 * Gestor de sincronización Riverso ↔ WooCommerce
 *
 * Stock y precios online hacia WooCommerce.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Woo_Sync_Manager {

    private static $instance = null;

    const CRON_HOOK = 'riverso_pos_sync_stock';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registra hooks de cron y acciones.
     */
    public function init() {
        add_action(self::CRON_HOOK, [$this, 'reconcile_stock']);
        $this->schedule_cron();
    }

    /**
     * Programa cron horario si no existe.
     */
    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Sincroniza stock de un producto_base hacia WooCommerce.
     *
     * @param int $producto_base_id
     * @return bool|WP_Error
     */
    public function sync_stock_to_woocommerce($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);

        if (!function_exists('wc_get_product')) {
            return new WP_Error('no_wc', 'WooCommerce no disponible');
        }

        $base = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, woocommerce_product_id, woocommerce_variation_id, stock
                 FROM {$prefix}producto_base WHERE id = %d",
                $producto_base_id
            ),
            ARRAY_A
        );

        if (!$base) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $wc_id = intval($base['woocommerce_variation_id']) ?: intval($base['woocommerce_product_id']);
        if (!$wc_id) {
            return new WP_Error('no_wc_product', 'Sin referencia WooCommerce');
        }

        // Preferir suma de ubicaciones por WC product_id; fallback a lotes disponibles.
        $wc_product_id = intval($base['woocommerce_product_id']);
        $qty = null;

        if ($wc_product_id) {
            $qty = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(cantidad) FROM {$wpdb->prefix}riverso_producto_ubicacion WHERE product_id = %d",
                    $wc_product_id
                )
            );
        }

        if ($qty === null) {
            $qty = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(l.cantidad_disponible)
                     FROM {$prefix}lotes l
                     INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
                     WHERE pp.producto_base_id = %d AND l.estado = 'disponible'",
                    $producto_base_id
                )
            );
        }

        if ($qty === null && isset($base['stock'])) {
            $qty = floatval($base['stock']);
        } else {
            $qty = floatval($qty ?? 0);
        }

        $product = wc_get_product($wc_id);
        if (!$product) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no encontrado');
        }

        $old_qty = $product->get_stock_quantity();
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->save();

        $this->log_sync('stock', $producto_base_id, $wc_id, [
            'old_qty' => $old_qty,
            'new_qty' => $qty,
        ]);

        return true;
    }

    /**
     * Sincroniza precio online vía Riverso_Pricing_Module si existe.
     *
     * @param int $producto_base_id
     * @return mixed
     */
    public function sync_price_online($producto_base_id) {
        if (class_exists('Riverso_Pricing_Module')) {
            return Riverso_Pricing_Module::get_instance()->sync_online_to_woocommerce(
                intval($producto_base_id)
            );
        }
        return new WP_Error('no_pricing', 'Riverso_Pricing_Module no disponible');
    }

    /**
     * Reconcilia stock de productos base recientes con ID WooCommerce.
     */
    public function reconcile_stock() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $rows = $wpdb->get_results(
            "SELECT id FROM {$prefix}producto_base
             WHERE woocommerce_product_id IS NOT NULL
               AND woocommerce_product_id > 0
             ORDER BY updated_at DESC
             LIMIT 200",
            ARRAY_A
        );

        if (!$rows) {
            // Fallback sin updated_at
            $rows = $wpdb->get_results(
                "SELECT id FROM {$prefix}producto_base
                 WHERE woocommerce_product_id IS NOT NULL
                   AND woocommerce_product_id > 0
                 ORDER BY id DESC
                 LIMIT 200",
                ARRAY_A
            );
        }

        $ok = 0;
        $fail = 0;
        foreach ((array) $rows as $row) {
            $result = $this->sync_stock_to_woocommerce(intval($row['id']));
            if (is_wp_error($result) || !$result) {
                $fail++;
            } else {
                $ok++;
            }
        }

        $this->log_sync('reconcile', 0, 0, [
            'ok' => $ok,
            'fail' => $fail,
            'total' => count((array) $rows),
        ]);

        return ['ok' => $ok, 'fail' => $fail];
    }

    /**
     * Escribe en riverso_woo_sync_log si la tabla existe.
     *
     * @param string $tipo
     * @param int    $producto_base_id
     * @param int    $wc_id
     * @param array  $payload
     */
    private function log_sync($tipo, $producto_base_id, $wc_id, $payload = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_woo_sync_log';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'tipo' => sanitize_text_field($tipo),
                'producto_base_id' => intval($producto_base_id),
                'woocommerce_id' => intval($wc_id),
                'payload' => wp_json_encode($payload),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );
    }
}
