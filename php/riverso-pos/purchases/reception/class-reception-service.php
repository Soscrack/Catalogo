<?php
/**
 * Servicio de recepción de mercadería (Fase 5)
 *
 * Crea movimientos de entrada vía Riverso_Movement o riverso_log_movement.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Reception_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_reception_entrada', [$this, 'ajax_entrada']);
    }

    /**
     * Registra una entrada de stock.
     *
     * @param int   $producto_base_id
     * @param float $cantidad
     * @param array $metadata referencia_tipo, referencia_id, ubicacion_destino, notas
     * @return int|false|mixed
     */
    public function receive($producto_base_id, $cantidad, $metadata = []) {
        $producto_base_id = intval($producto_base_id);
        $cantidad = floatval($cantidad);

        if ($producto_base_id <= 0 || $cantidad <= 0) {
            return false;
        }

        if (class_exists('Riverso_Movement')) {
            return Riverso_Movement::create(
                'entrada',
                $producto_base_id,
                $cantidad,
                array_merge([
                    'referencia_tipo' => $metadata['referencia_tipo'] ?? 'recepcion',
                    'notas' => $metadata['notas'] ?? 'Recepción de mercadería',
                ], $metadata)
            );
        }

        if (function_exists('riverso_log_movement')) {
            riverso_log_movement($producto_base_id, 'entrada', $cantidad, $metadata);
            return true;
        }

        return false;
    }

    public function ajax_entrada() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_purchases') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $producto_base_id = isset($_POST['producto_base_id']) ? intval($_POST['producto_base_id']) : 0;
        $cantidad = isset($_POST['cantidad']) ? floatval($_POST['cantidad']) : 0;
        $ubicacion = isset($_POST['ubicacion_id']) ? intval($_POST['ubicacion_id']) : 0;
        $notas = isset($_POST['notas']) ? sanitize_text_field(wp_unslash($_POST['notas'])) : '';
        $ref_tipo = isset($_POST['referencia_tipo']) ? sanitize_text_field(wp_unslash($_POST['referencia_tipo'])) : 'recepcion';
        $ref_id = isset($_POST['referencia_id']) ? intval($_POST['referencia_id']) : 0;

        $meta = [
            'notas' => $notas,
            'referencia_tipo' => $ref_tipo,
        ];
        if ($ubicacion) {
            $meta['ubicacion_destino'] = $ubicacion;
        }
        if ($ref_id) {
            $meta['referencia_id'] = $ref_id;
        }

        $result = $this->receive($producto_base_id, $cantidad, $meta);
        if (!$result) {
            wp_send_json_error(['message' => 'No se pudo registrar la entrada']);
        }

        wp_send_json_success(['movement_id' => $result]);
    }
}
