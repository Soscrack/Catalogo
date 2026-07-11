<?php
/**
 * Servicio de saldos de stock (Fase 3+)
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Stock_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Stock físico (suma ubicaciones o fallback producto_base.stock).
     *
     * @param int      $producto_base_id
     * @param int|null $ubicacion_id
     * @return float
     */
    public function get_balance($producto_base_id, $ubicacion_id = null) {
        global $wpdb;
        $producto_base_id = intval($producto_base_id);
        $table = $wpdb->prefix . 'riverso_producto_ubicacion';

        if ($ubicacion_id) {
            $balance = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT cantidad FROM {$table} WHERE product_id = %d AND ubicacion_id = %d",
                    $producto_base_id,
                    intval($ubicacion_id)
                )
            );
            return floatval($balance ?? 0);
        }

        $balance = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(cantidad) FROM {$table} WHERE product_id = %d",
                $producto_base_id
            )
        );

        if ($balance !== null) {
            return floatval($balance);
        }

        $prefix = $wpdb->prefix . 'riverso_';
        $stock = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT stock FROM {$prefix}producto_base WHERE id = %d",
                $producto_base_id
            )
        );

        return floatval($stock ?? 0);
    }

    /**
     * Disponible = físico − reservado.
     *
     * @param int      $producto_base_id
     * @param int|null $ubicacion_id
     * @return float
     */
    public function get_available($producto_base_id, $ubicacion_id = null) {
        $fisico = $this->get_balance($producto_base_id, $ubicacion_id);
        $reservado = 0.0;

        if (class_exists('Riverso_Reservation_Service')) {
            $reservado = Riverso_Reservation_Service::get_instance()->get_reserved(
                intval($producto_base_id),
                $ubicacion_id ? intval($ubicacion_id) : null
            );
        }

        return max(0, $fisico - $reservado);
    }
}
