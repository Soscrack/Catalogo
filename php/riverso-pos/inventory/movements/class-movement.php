<?php
/**
 * Modelo de movimientos (Kardex unificado) - Fase 3
 * 
 * Registra todos los cambios de stock:
 * - ENTRADA: recepción de factura
 * - SALIDA: venta POS
 * - AJUSTE: corrección manual
 * - CORRECCION: cierre de inventario de lugar/producto
 * - VENTA: salida por documento de venta (ver Riverso_Sale_Stock_Service)
 * - REVERSA_VENTA: devuelve lo descontado por un documento eliminado
 * - RECEPCIÓN: entrada por recepción de compra (zona Recepción)
 * - REVERSA_RECEPCION: anula una recepción no ordenada
 * - RECLAMO: faltante o mal estado detectado al ordenar (espera nota de crédito)
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
        'correccion' => 'Corrección por inventario',
        'recepcion' => 'Recepción de compra',
        'venta' => 'Venta finalizada',
        'devolcion' => 'Devolución cliente',
        'apertura' => 'Apertura de envase',
        'bolsa' => 'Generación de bolsa',
        'traslado' => 'Traslado entre ubicaciones',
        'reversa_venta' => 'Reversa de venta',
        'reversa_recepcion' => 'Reversa de recepción',
        'reclamo' => 'Reclamo a proveedor',
    ];

    /** @var bool|null Columnas origen_cantidad/contado_en presentes (fase 71). */
    private static $has_origin_columns = null;

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

        $origen_id = !empty($metadata['ubicacion_origen']) ? intval($metadata['ubicacion_origen']) : 0;
        $destino_id = !empty($metadata['ubicacion_destino']) ? intval($metadata['ubicacion_destino']) : 0;

        // Traslado: restar del origen (si existe) y sumar al destino
        if ($tipo === 'traslado' && $origen_id > 0) {
            $stock_origen_anterior = self::get_current_balance($producto_base_id, $origen_id);
            $stock_origen_nuevo = $stock_origen_anterior - $cantidad;
            self::_update_location_balance($producto_base_id, $origen_id, $stock_origen_nuevo);
        }

        // Ubicación cuyo saldo cambia: destino; en salida/venta sin destino, el origen
        // (antes la salida con solo origen no tocaba ningún saldo).
        $es_salida = in_array($tipo, ['salida', 'venta', 'reclamo', 'reversa_recepcion'], true);
        $saldo_ubicacion_id = $destino_id;
        if ($es_salida && $destino_id <= 0 && $origen_id > 0) {
            $saldo_ubicacion_id = $origen_id;
        }

        // Obtener saldo anterior (ubicación si hay, sino total)
        $balance_ubicacion = $saldo_ubicacion_id > 0 ? $saldo_ubicacion_id : null;
        $stock_anterior = self::get_current_balance($producto_base_id, $balance_ubicacion);

        // Calcular saldo nuevo
        $cantidad_neta = $es_salida ? -abs($cantidad) : $cantidad;
        if ($tipo === 'traslado' && $origen_id > 0 && $destino_id > 0) {
            $cantidad_neta = $cantidad;
        }
        $stock_nuevo = $stock_anterior + $cantidad_neta;

        // IMPORTANTE:
        // La tabla real es riverso_movimientos con columnas:
        // product_id, tipo, cantidad, stock_anterior, stock_nuevo,
        // ubicacion_origen, ubicacion_destino, referencia_tipo, referencia_id, notas, usuario_id, created_at, lote_id
        // (no existe producto_base_id ni ubicacion_origen_id / ubicacion_destino_id).
        $data = [
            'product_id'        => intval($producto_base_id),
            'tipo'              => sanitize_text_field($tipo),
            'cantidad'         => floatval($cantidad),
            'stock_anterior'   => floatval($stock_anterior),
            'stock_nuevo'      => floatval($stock_nuevo),
            'ubicacion_origen' => $origen_id > 0 ? intval($origen_id) : null,
            'ubicacion_destino'=> $destino_id > 0 ? intval($destino_id) : null,
            'referencia_tipo'  => isset($metadata['referencia_tipo']) ? sanitize_text_field($metadata['referencia_tipo']) : null,
            'referencia_id'    => isset($metadata['referencia_id']) ? intval($metadata['referencia_id']) : null,
            'notas'             => isset($metadata['notas']) ? sanitize_text_field($metadata['notas']) : null,
            'usuario_id'       => get_current_user_id(),
            'created_at'       => current_time('mysql'),
        ];

        if (isset($metadata['lote_id'])) {
            $data['lote_id'] = intval($metadata['lote_id']);
        }

        $formats = [
            '%d', // product_id
            '%s', // tipo
            '%f', // cantidad
            '%f', // stock_anterior
            '%f', // stock_nuevo
            '%d', // ubicacion_origen
            '%d', // ubicacion_destino
            '%s', // referencia_tipo
            '%d', // referencia_id
            '%s', // notas
            '%d', // usuario_id
            '%s', // created_at
        ];
        if (array_key_exists('lote_id', $data)) {
            $formats[] = '%d';
        }

        $result = $wpdb->insert("{$prefix}movimientos", $data, $formats);

        if (!$result) {
            return false;
        }

        $movement_id = $wpdb->insert_id;

        // Actualizar saldo en producto_ubicacion
        if ($saldo_ubicacion_id > 0) {
            self::_update_location_balance($producto_base_id, $saldo_ubicacion_id, $stock_nuevo, $tipo);
        } elseif (!isset($metadata['ubicacion_destino'])) {
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
     * Actualiza saldo en ubicación específica.
     * Corrección (conteo) deja el saldo "contado"; venta y su reversa lo dejan "estimado".
     */
    private static function _update_location_balance($producto_base_id, $ubicacion_id, $new_balance, $tipo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $extra = self::origin_fields($tipo);

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
                array_merge(['cantidad' => $new_balance], $extra),
                ['product_id' => $producto_base_id, 'ubicacion_id' => $ubicacion_id]
            );
        } else {
            $wpdb->insert(
                "{$prefix}riverso_producto_ubicacion",
                array_merge([
                    'product_id' => $producto_base_id,
                    'ubicacion_id' => $ubicacion_id,
                    'cantidad' => $new_balance,
                ], $extra)
            );
        }
    }

    /**
     * @param string $tipo
     * @return array<string, mixed>
     */
    private static function origin_fields($tipo) {
        if (self::$has_origin_columns === null) {
            global $wpdb;
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}riverso_producto_ubicacion", 0);
            self::$has_origin_columns = is_array($cols) && in_array('origen_cantidad', $cols, true);
        }
        if (!self::$has_origin_columns) {
            return [];
        }
        if ($tipo === 'correccion') {
            return ['origen_cantidad' => 'contado', 'contado_en' => current_time('mysql')];
        }
        if (in_array($tipo, ['venta', 'reversa_venta', 'recepcion'], true)) {
            return ['origen_cantidad' => 'estimado'];
        }
        return [];
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
                 WHERE product_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d",
                $producto_base_id,
                $limit
            ),
            ARRAY_A
        );
    }
}
