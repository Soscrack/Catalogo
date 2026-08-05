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
    public static function resolve($code, $supplier_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }

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
                    activo,
                    estado,
                    motivo_estado
                FROM {$prefix}codigo_barra
                WHERE codigo = %s
                  AND activo = 1
                  AND estado IN ('verificado', 'en_desuso')
                LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($result) {
            return self::format_result([
                'id' => intval($result['id']),
                'producto_base_id' => intval($result['producto_base_id']),
                'proveedor_id' => $result['proveedor_id'] ? intval($result['proveedor_id']) : null,
                'cantidad_unidades' => floatval($result['cantidad']),
                'unidad_medida' => $result['unidad_medida'],
                'presentacion_id' => $result['envase_id'] ? intval($result['envase_id']) : null,
                'factor_a_unidad_base' => floatval($result['factor_a_unidad_base']),
                'tipo' => $result['tipo'],
                'origen' => 'codigo_barra',
                'codigo_id' => intval($result['id']),
                'estado' => $result['estado'],
                'advertencia' => $result['estado'] === 'en_desuso' ? $result['motivo_estado'] : null,
                'requires_review' => $result['estado'] === 'en_desuso',
            ]);
        }

        $internal = self::resolve_internal_ean($code);
        if ($internal) {
            return $internal;
        }

        // Fallback a legacy (dual-read para compatibilidad)
        return self::_resolve_legacy($code, $supplier_id);
    }

    /**
     * Fallback: Resolver desde tablas legacy para compatibilidad backward
     * 
     * @private
     */
    private static function _resolve_legacy($code, $supplier_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix;

        // Intentar barcode legacy y traducir la referencia Woo al producto base.
        $barcode = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.id, b.product_id, b.variation_id, b.sku,
                        pb.id AS producto_base_id, pb.unidad_base
                 FROM {$prefix}riverso_barcodes b
                 LEFT JOIN {$prefix}riverso_producto_base pb
                   ON pb.woocommerce_product_id = b.product_id
                  AND (pb.woocommerce_variation_id = COALESCE(b.variation_id, 0)
                       OR COALESCE(b.variation_id, 0) = 0)
                 WHERE b.barcode = %s AND b.is_active = 1
                 LIMIT 1",
                $code
            ),
            ARRAY_A
        );

        if ($barcode && $barcode['product_base_id']) {
            return self::format_result([
                'producto_base_id' => intval($barcode['producto_base_id']),
                'proveedor_id' => null,
                'cantidad_unidades' => 1,
                'unidad_medida' => $barcode['unidad_base'] ?: 'unidad',
                'presentacion_id' => null,
                'factor_a_unidad_base' => 1,
                'tipo' => 'ean13',
                'origen' => 'barcodes_legacy',
                'legacy' => true,
                'codigo_id' => intval($barcode['id']),
            ]);
        }

        // Intentar código proveedor legacy
        $supplier_where = 'codigo_proveedor = %s AND activo = 1';
        $params = [$code];
        if ($supplier_id) {
            $supplier_where .= ' AND proveedor_id = %d';
            $params[] = intval($supplier_id);
        }
        $supplier_codes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, supplier_product_id, product_base_id, proveedor_id,
                        factor_conversion, unidad_medida
                 FROM {$prefix}riverso_codigos
                 WHERE {$supplier_where}
                 LIMIT 2",
                ...$params
            ),
            ARRAY_A
        );
        $supplier_code = count($supplier_codes) === 1 ? $supplier_codes[0] : null;

        if ($supplier_code && $supplier_code['product_base_id']) {
            $factor = max(1, floatval($supplier_code['factor_conversion']));
            return self::format_result([
                'producto_base_id' => intval($supplier_code['product_base_id']),
                'proveedor_id' => $supplier_code['proveedor_id'] ? intval($supplier_code['proveedor_id']) : null,
                'cantidad_unidades' => $factor,
                'unidad_medida' => 'unidad',
                'presentacion_id' => null,
                'factor_a_unidad_base' => $factor,
                'tipo' => 'supplier',
                'origen' => 'codigos_legacy',
                'legacy' => true,
                'codigo_id' => intval($supplier_code['id']),
                'requires_review' => $factor === 1.0,
            ]);
        }

        return false;
    }

    /**
     * Resuelve bolsas históricas aunque no exista una fila persistida.
     */
    private static function resolve_internal_ean($code) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!class_exists('Riverso_EAN13_Generator')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-ean13-generator.php';
        }
        $parsed = Riverso_EAN13_Generator::parse($code);
        if (!$parsed) {
            return false;
        }

        $payload = $parsed['payload'];
        $normalized = ltrim($payload, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, unidad_base
             FROM {$prefix}producto_base
             WHERE estado = 'activo' AND (canonical_sku = %s OR canonical_sku = %s)
             LIMIT 2",
            $payload,
            $normalized
        ), ARRAY_A);

        $product = count($products) === 1 ? $products[0] : null;
        $origin = 'ean13_legacy_algorithmic';

        if (!$product) {
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT pb.id, pb.canonical_sku, pb.unidad_base
                 FROM {$prefix}ean_aliases ea
                 INNER JOIN {$prefix}producto_base pb ON pb.id = ea.producto_base_id
                 WHERE ea.payload = %s AND ea.activo = 1 AND pb.estado = 'activo'
                 LIMIT 1",
                $payload
            ), ARRAY_A);
            $origin = 'ean13_alias';
        }

        if (!$product) {
            return false;
        }

        return self::format_result([
            'producto_base_id' => intval($product['id']),
            'proveedor_id' => null,
            'cantidad_unidades' => floatval($parsed['cantidad']),
            'unidad_medida' => $product['unidad_base'] ?: 'unidad',
            'presentacion_id' => null,
            'factor_a_unidad_base' => floatval($parsed['cantidad']),
            'tipo' => 'internal',
            'origen' => $origin,
            'legacy' => $origin === 'ean13_legacy_algorithmic',
            'requires_review' => false,
        ]);
    }

    private static function format_result($data) {
        $result = array_merge([
            'producto_base_id' => 0,
            'proveedor_id' => null,
            'cantidad_unidades' => 1.0,
            'unidad_medida' => 'unidad',
            'presentacion_id' => null,
            'factor_a_unidad_base' => 1.0,
            'tipo' => 'internal',
            'origen' => 'unknown',
            'requires_review' => false,
        ], $data);

        // Alias temporales para consumidores existentes.
        $result['product_base_id'] = $result['producto_base_id'];
        $result['supplier_id'] = $result['proveedor_id'];
        $result['cantidad'] = $result['cantidad_unidades'];
        $result['envase_id'] = $result['presentacion_id'];
        $result['type'] = $result['tipo'];
        return $result;
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
                'estado' => 'verificado',
                'estado_por' => get_current_user_id() ?: null,
                'estado_at' => current_time('mysql'),
                'origen_datos' => 'manual',
                'created_at' => current_time('mysql'),
            ],
            [
                '%s', '%s', '%d', '%d', '%f', '%s', '%d', '%f', '%d',
                '%s', '%d', '%s', '%s', '%s'
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
        return self::set_status($codigo_id, 'en_desuso', 'Desactivado desde administración.');
    }

    /**
     * Cambia el estado conservando la relación para trazabilidad.
     */
    public static function set_status($codigo_id, $estado, $motivo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $allowed = ['propuesto', 'verificado', 'en_desuso', 'rechazado'];
        if (!in_array($estado, $allowed, true)) {
            return false;
        }

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT estado, motivo_estado FROM {$prefix}codigo_barra WHERE id = %d",
            $codigo_id
        ), ARRAY_A);
        if (!$previous) {
            return false;
        }

        $updated = $wpdb->update(
            "{$prefix}codigo_barra",
            [
                'estado' => $estado,
                'activo' => in_array($estado, ['propuesto', 'verificado', 'en_desuso'], true) ? 1 : 0,
                'motivo_estado' => sanitize_text_field($motivo),
                'estado_por' => get_current_user_id() ?: null,
                'estado_at' => current_time('mysql'),
            ],
            ['id' => $codigo_id],
            ['%s', '%d', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated !== false && $previous['estado'] !== $estado) {
            $wpdb->insert(
                "{$prefix}codigos_historial",
                [
                    'codigo_id' => $codigo_id,
                    'accion' => 'estado_codigo_barra',
                    'campo_modificado' => 'estado',
                    'valor_anterior' => wp_json_encode($previous),
                    'valor_nuevo' => wp_json_encode(['estado' => $estado, 'motivo' => $motivo]),
                    'usuario_id' => get_current_user_id() ?: null,
                    'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
            );
        }

        return $updated !== false;
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
