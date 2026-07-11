<?php
/**
 * Modelo unificado de códigos de barras (Fase 2)
 * 
 * Un código de barras representa la relación:
 * Producto base → Proveedor (opcional) → Cantidad → Unidad → Envase
 * 
 * Permite escanear un código y resolver inmediatamente:
 * - Producto (produto_base_id)
 * - Proveedor (proveedor_id, nullable para internos)
 * - Cantidad (ej: 100, 500, 1000)
 * - Unidad (unidad, kg, litro, etc.)
 * - Envase (caja, bolsa, etc.)
 * - Factor de conversión a unidad base
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Barcode_Model {

    /**
     * Tipos de código
     */
    const TYPES = [
        'ean13'    => 'Código EAN13 estándar',
        'supplier' => 'Código de proveedor',
        'internal' => 'Código interno Riverso',
    ];

    /**
     * Resuelve un código a su información completa
     * 
     * @param string $code Código de barras
     * @return array|false {
     *     product_base_id: int,
     *     supplier_id: int|null,
     *     cantidad: decimal,
     *     unidad_medida: string,
     *     envase_id: int|null,
     *     factor_a_unidad_base: decimal,
     *     type: string (ean13|supplier|internal)
     * } o false si no existe
     */
    public static function resolve($code) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Intentar en nueva tabla unificada
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    id,
                    codigo,
                    tipo,
                    producto_base_id,
                    proveedor_id,
                    cantidad,
                    unidad_medida,
                    envase_id,
                    factor_a_unidad_base,
                    activo
                FROM {$prefix}codigo_barra
                WHERE codigo = %s
                  AND activo = 1
                LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($result) {
            return [
                'id' => intval($result['id']),
                'product_base_id' => intval($result['producto_base_id']),
                'supplier_id' => $result['proveedor_id'] ? intval($result['proveedor_id']) : null,
                'cantidad' => floatval($result['cantidad']),
                'unidad_medida' => $result['unidad_medida'],
                'envase_id' => $result['envase_id'] ? intval($result['envase_id']) : null,
                'factor_a_unidad_base' => floatval($result['factor_a_unidad_base']),
                'type' => $result['tipo'],
                'codigo_id' => intval($result['id']),
            ];
        }

        // Fallback a legacy (dual-read para compatibilidad)
        return self::_resolve_legacy($code);
    }

    /**
     * Fallback: Resolver desde tablas legacy para compatibilidad backward
     * 
     * @private
     */
    private static function _resolve_legacy($code) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Intentar EAN13 legacy
        $barcode = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT product_id, product_base_id FROM {$prefix}riverso_barcodes WHERE ean13 = %s LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($barcode && $barcode['product_base_id']) {
            return [
                'product_base_id' => intval($barcode['product_base_id']),
                'supplier_id' => null,
                'cantidad' => 1,
                'unidad_medida' => 'unidad',
                'envase_id' => null,
                'factor_a_unidad_base' => 1,
                'type' => 'ean13',
                'legacy' => true,
            ];
        }

        // Intentar código proveedor legacy
        $supplier_code = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT supplier_product_id, product_base_id, proveedor_id 
                 FROM {$prefix}riverso_codigos 
                 WHERE codigo_proveedor = %s AND activo = 1 
                 LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($supplier_code && $supplier_code['product_base_id']) {
            return [
                'product_base_id' => intval($supplier_code['product_base_id']),
                'supplier_id' => $supplier_code['proveedor_id'] ? intval($supplier_code['proveedor_id']) : null,
                'cantidad' => 1,
                'unidad_medida' => 'unidad',
                'envase_id' => null,
                'factor_a_unidad_base' => 1,
                'type' => 'supplier',
                'legacy' => true,
            ];
        }

        return false;
    }

    /**
     * Crea un nuevo código de barras
     * 
     * @param string $codigo              Código de barras
     * @param string $tipo                ean13|supplier|internal
     * @param int    $producto_base_id
     * @param int    $proveedor_id        (nullable)
     * @param float  $cantidad
     * @param string $unidad_medida
     * @param int    $envase_id           (nullable)
     * @param float  $factor_a_unidad_base
     * @return int|false ID del código o false
     */
    public static function create($codigo, $tipo, $producto_base_id, $cantidad, $unidad_medida, $proveedor_id = null, $envase_id = null, $factor_a_unidad_base = 1) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $result = $wpdb->insert(
            "{$prefix}codigo_barra",
            [
                'codigo' => $codigo,
                'tipo' => $tipo,
                'producto_base_id' => $producto_base_id,
                'proveedor_id' => $proveedor_id,
                'cantidad' => $cantidad,
                'unidad_medida' => $unidad_medida,
                'envase_id' => $envase_id,
                'factor_a_unidad_base' => $factor_a_unidad_base,
                'activo' => 1,
                'created_at' => current_time('mysql'),
            ],
            [
                '%s', '%s', '%d', '%d', '%f', '%s', '%d', '%f', '%d', '%s'
            ]
        );

        if ($result) {
            riverso_event_publish('barcode.created', [
                'codigo_id' => $wpdb->insert_id,
                'codigo' => $codigo,
                'tipo' => $tipo,
                'producto_base_id' => $producto_base_id,
            ], [
                'user_id' => get_current_user_id(),
            ]);

            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Obtiene todos los códigos de un producto
     * 
     * @param int $producto_base_id
     * @param int $proveedor_id (opcional, filtrar por proveedor)
     * @return array
     */
    public static function get_by_product($producto_base_id, $proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $query = $wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra 
             WHERE producto_base_id = %d AND activo = 1",
            $producto_base_id
        );

        if ($proveedor_id) {
            $query .= $wpdb->prepare(" AND proveedor_id = %d", $proveedor_id);
        }

        return $wpdb->get_results($query, ARRAY_A);
    }

    /**
     * Desactiva un código
     * 
     * @param int $codigo_id
     * @return bool
     */
    public static function deactivate($codigo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        return (bool) $wpdb->update(
            "{$prefix}codigo_barra",
            ['activo' => 0],
            ['id' => $codigo_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Verifica si un código existe
     * 
     * @param string $codigo
     * @return bool
     */
    public static function exists($codigo) {
        return self::resolve($codigo) !== false;
    }
}
