<?php
/**
 * Modelo de movimientos (Kardex unificado) - Fase 3
 * 
 * Registra todos los cambios de stock:
 * - ENTRADA: recepción de factura
 * - SALIDA: venta POS
 * - AJUSTE: corrección manual
 * - RECEPCIÓN: recepción parcial
 * - DEVOLUCIÓN: devolución de cliente
 * - APERTURA: apertura de envase
 * - BOLSA: generación de bolsa
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Movement {

    const TYPES = [
        'entrada' => 'Entrada de mercadería',
        'salida' => 'Salida (venta)',
        'ajuste' => 'Ajuste de stock',
        'recepcion' => 'Recepción parcial',
        'venta' => 'Venta finalizada',
        'devolcion' => 'Devolución cliente',
        'apertura' => 'Apertura de envase',
        'bolsa' => 'Generación de bolsa',
        'traslado' => 'Traslado entre ubicaciones',
    ];

    /**
     * Crea un movimiento de stock
     * 
     * @param string $tipo            Tipo de movimiento
     * @param int    $producto_base_id
     * @param float  $cantidad        Cantidad del movimiento
     * @param array  $metadata        Metadatos adicionales
     * @return int|false ID del movimiento o false
     */
    public static function create($tipo, $producto_base_id, $cantidad, $metadata = []) {
        if (!isset(self::TYPES[$tipo])) {
            return false;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Obtener saldo anterior
        $stock_anterior = self::get_current_balance($producto_base_id, $metadata['ubicacion_destino'] ?? null);

        // Calcular saldo nuevo
        $cantidad_neta = ($tipo === 'salida' || $tipo === 'venta') ? -$cantidad : $cantidad;
        $stock_nuevo = $stock_anterior + $cantidad_neta;

        $data = [
            'producto_base_id' => $producto_base_id,
            'tipo' => $tipo,
            'cantidad' => $cantidad,
            'cantidad_neta' => $cantidad_neta,
            'stock_anterior' => $stock_anterior,
            'stock_nuevo' => $stock_nuevo,
            'usuario_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ];

        // Agregar metadatos opcionales
        if (isset($metadata['ubicacion_origen'])) {
            $data['ubicacion_origen_id'] = intval($metadata['ubicacion_origen']);
        }
        if (isset($metadata['ubicacion_destino'])) {
            $data['ubicacion_destino_id'] = intval($metadata['ubicacion_destino']);
        }
        if (isset($metadata['referencia_tipo'])) {
            $data['referencia_tipo'] = sanitize_text_field($metadata['referencia_tipo']);
        }
        if (isset($metadata['referencia_id'])) {
            $data['referencia_id'] = intval($metadata['referencia_id']);
        }
        if (isset($metadata['notas'])) {
            $data['notas'] = sanitize_text_field($metadata['notas']);
        }
        if (isset($metadata['lote_id'])) {
            $data['lote_id'] = intval($metadata['lote_id']);
        }

        $result = $wpdb->insert(
            "{$prefix}movimientos",
            $data,
            array_fill(0, count($data), isset($data['stock_nuevo']) ? '%f' : '%s')
        );

        if (!$result) {
            return false;
        }

        $movement_id = $wpdb->insert_id;

        // Actualizar saldo en producto_ubicacion
        if (isset($metadata['ubicacion_destino'])) {
            self::_update_location_balance($producto_base_id, $metadata['ubicacion_destino'], $stock_nuevo);
        } else {
            self::_update_total_balance($producto_base_id, $stock_nuevo);
        }

        // Emitir evento
        riverso_event_publish('inventory.movement.created', [
            'movement_id' => $movement_id,
            'tipo' => $tipo,
            'producto_base_id' => $producto_base_id,
            'cantidad' => $cantidad,
            'stock_nuevo' => $stock_nuevo,
        ], [
            'user_id' => get_current_user_id(),
        ]);

        return $movement_id;
    }

    /**
     * Obtiene el saldo actual de un producto
     */
    private static function get_current_balance($producto_base_id, $ubicacion_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        if ($ubicacion_id) {
            $balance = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT cantidad FROM {$prefix}riverso_producto_ubicacion 
                     WHERE product_id = %d AND ubicacion_id = %d",
                    $producto_base_id,
                    $ubicacion_id
                )
            );
        } else {
            // Suma total de todas las ubicaciones
            $balance = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(cantidad) FROM {$prefix}riverso_producto_ubicacion 
                     WHERE product_id = %d",
                    $producto_base_id
                )
            );
        }

        return floatval($balance ?? 0);
    }

    /**
     * Actualiza saldo en ubicación específica
     */
    private static function _update_location_balance($producto_base_id, $ubicacion_id, $new_balance) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$prefix}riverso_producto_ubicacion 
                 WHERE product_id = %d AND ubicacion_id = %d",
                $producto_base_id,
                $ubicacion_id
            )
        );

        if ($exists) {
            $wpdb->update(
                "{$prefix}riverso_producto_ubicacion",
                ['cantidad' => $new_balance],
                ['product_id' => $producto_base_id, 'ubicacion_id' => $ubicacion_id],
                ['%f'],
                ['%d', '%d']
            );
        } else {
            $wpdb->insert(
                "{$prefix}riverso_producto_ubicacion",
                [
                    'product_id' => $producto_base_id,
                    'ubicacion_id' => $ubicacion_id,
                    'cantidad' => $new_balance,
                ],
                ['%d', '%d', '%f']
            );
        }
    }

    /**
     * Actualiza saldo total (suma todas ubicaciones)
     */
    private static function _update_total_balance($producto_base_id, $new_balance) {
        // Este método actualizaría una tabla de saldos totales si la hubiera
        // Por ahora es informativo
    }

    /**
     * Obtiene historial de movimientos de un producto
     */
    public static function get_history($producto_base_id, $limit = 50) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$prefix}movimientos 
                 WHERE producto_base_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $producto_base_id,
                $limit
            ),
            ARRAY_A
        );
    }
}
