<?php
/**
 * Modelo unificado de códigos de barras (Fase 2)
 *
 * Un código de barras representa la relación:
 * Producto base → Proveedor (opcional) → Cantidad → Unidad → Envase
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Barcode_Model {

    const TYPES = [
        'ean13'    => 'Código EAN13 estándar',
        'supplier' => 'Código de proveedor',
        'internal' => 'Código interno Riverso',
    ];

    public static function looks_like_barcode($code) {
        $code = trim((string) $code);
        if ($code === '') {
            return false;
        }
        $len = strlen($code);
        return $len >= 8 && $len <= 18 && ctype_digit($code);
    }

    public static function resolve($code, $supplier_id = null) {
        $bundle = self::resolve_with_suggestions($code, $supplier_id);
        return $bundle['match'] ? $bundle['match'] : false;
    }

    /**
     * @return array{match: array|null, suggestions: array, conflicts: bool, rejected: array, query: string}
     */
    public static function resolve_with_suggestions($code, $supplier_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $code = trim((string) $code);
        $empty = [
            'match' => null,
            'suggestions' => [],
            'conflicts' => false,
            'rejected' => [],
            'query' => $code,
        ];
        if ($code === '') {
            return $empty;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cb.*, pb.canonical_sku, pb.nombre_canonico
                FROM {$prefix}codigo_barra cb
                LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                WHERE cb.codigo = %s OR TRIM(LEADING '0' FROM cb.codigo) = %s
                ORDER BY
                    CASE cb.estado
                        WHEN 'verificado' THEN 0
                        WHEN 'propuesto' THEN 1
                        WHEN 'en_desuso' THEN 2
                        ELSE 3
                    END,
                    cb.id ASC",
                $code,
                ltrim($code, '0') === '' ? '0' : ltrim($code, '0')
            ),
            ARRAY_A
        ) ?: [];

        $match = null;
        $suggestions = [];
        $rejected = [];
        $product_ids = [];

        foreach ($rows as $result) {
            $formatted = self::row_to_result($result);
            $estado = $result['estado'] ?: '';
            if ($estado === 'verificado' && intval($result['activo']) === 1 && !empty($result['producto_base_id'])) {
                if (!$match) {
                    $match = $formatted;
                }
                $product_ids[] = intval($result['producto_base_id']);
            } elseif (in_array($estado, ['propuesto', 'en_desuso'], true) && intval($result['activo']) === 1) {
                $suggestions[] = $formatted;
                if (!empty($result['producto_base_id'])) {
                    $product_ids[] = intval($result['producto_base_id']);
                }
                if (!empty($result['pending_sku'])) {
                    $product_ids[] = 'pending:' . $result['pending_sku'];
                }
            } elseif ($estado === 'rechazado') {
                $rejected[] = $formatted;
            }
        }

        if (!$match) {
            $internal = self::resolve_internal_ean($code);
            if ($internal) {
                $match = $internal;
            }
        }

        foreach (self::legacy_suggestions($code, $supplier_id) as $legacy) {
            $key = ($legacy['origen'] ?? '') . ':' . ($legacy['producto_base_id'] ?? 0) . ':' . ($legacy['sku_local'] ?? '');
            $dup = false;
            foreach ($suggestions as $existing) {
                $ekey = ($existing['origen'] ?? '') . ':' . ($existing['producto_base_id'] ?? 0) . ':' . ($existing['sku_local'] ?? '');
                if ($ekey === $key) {
                    $dup = true;
                    break;
                }
            }
            if ($match && intval($legacy['producto_base_id'] ?? 0) === intval($match['producto_base_id'] ?? 0) && !empty($legacy['producto_base_id'])) {
                continue;
            }
            if (!$dup) {
                $suggestions[] = $legacy;
                if (!empty($legacy['producto_base_id'])) {
                    $product_ids[] = intval($legacy['producto_base_id']);
                }
            }
        }

        $unique_products = array_unique($product_ids);
        $conflicts = count($unique_products) > 1;
        foreach ($rows as $r) {
            if (intval($r['conflicto'] ?? 0) === 1) {
                $conflicts = true;
                break;
            }
        }

        return [
            'match' => $match,
            'suggestions' => $suggestions,
            'conflicts' => $conflicts,
            'rejected' => $rejected,
            'query' => $code,
        ];
    }

    private static function row_to_result($result) {
        $legacy_ref = [];
        if (!empty($result['legacy_ref'])) {
            $decoded = json_decode($result['legacy_ref'], true);
            if (is_array($decoded)) {
                $legacy_ref = $decoded;
            }
        }
        return self::format_result([
            'id' => intval($result['id'] ?? 0),
            'producto_base_id' => !empty($result['producto_base_id']) ? intval($result['producto_base_id']) : null,
            'proveedor_id' => !empty($result['proveedor_id']) ? intval($result['proveedor_id']) : null,
            'cantidad_unidades' => floatval($result['cantidad'] ?? 1),
            'unidad_medida' => $result['unidad_medida'] ?? 'unidad',
            'presentacion_id' => !empty($result['envase_id']) ? intval($result['envase_id']) : null,
            'factor_a_unidad_base' => floatval($result['factor_a_unidad_base'] ?? 1),
            'tipo' => $result['tipo'] ?? 'ean13',
            'origen' => $result['origen_datos'] ?: 'codigo_barra',
            'codigo_id' => intval($result['id'] ?? 0),
            'estado' => $result['estado'] ?? null,
            'advertencia' => $result['motivo_estado'] ?? null,
            'requires_review' => in_array($result['estado'] ?? '', ['propuesto', 'en_desuso'], true),
            'sku_local' => $result['sku_local'] ?? null,
            'pending_sku' => $result['pending_sku'] ?? null,
            'canonical_sku' => $result['canonical_sku'] ?? null,
            'nombre_canonico' => $result['nombre_canonico'] ?? null,
            'conflicto' => intval($result['conflicto'] ?? 0) === 1,
            'legacy_ref' => $legacy_ref,
            'legacy' => strpos((string) ($result['origen_datos'] ?? ''), 'legacy') !== false,
        ]);
    }

    private static function legacy_suggestions($code, $supplier_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $suggestions = [];

        $barcodes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.product_id, b.variation_id, b.sku,
                        pb.id AS producto_base_id, pb.unidad_base, pb.canonical_sku, pb.nombre_canonico
                 FROM {$prefix}riverso_barcodes b
                 LEFT JOIN {$prefix}riverso_producto_base pb
                   ON pb.woocommerce_product_id = b.product_id
                  AND (pb.woocommerce_variation_id = COALESCE(b.variation_id, 0)
                       OR COALESCE(b.variation_id, 0) = 0)
                 WHERE b.barcode = %s AND COALESCE(b.is_active, 1) = 1
                 LIMIT 5",
                $code
            ),
            ARRAY_A
        ) ?: [];

        foreach ($barcodes as $barcode) {
            $suggestions[] = self::format_result([
                'producto_base_id' => $barcode['producto_base_id'] ? intval($barcode['producto_base_id']) : null,
                'proveedor_id' => null,
                'cantidad_unidades' => 1,
                'unidad_medida' => $barcode['unidad_base'] ?: 'unidad',
                'presentacion_id' => null,
                'factor_a_unidad_base' => 1,
                'tipo' => 'ean13',
                'origen' => 'legacy_wp_riverso_barcodes',
                'legacy' => true,
                'codigo_id' => intval($barcode['id']),
                'sku_local' => $barcode['sku'] ?: null,
                'canonical_sku' => $barcode['canonical_sku'] ?? null,
                'nombre_canonico' => $barcode['nombre_canonico'] ?? null,
                'estado' => 'propuesto',
                'requires_review' => true,
            ]);
        }

        $local_table = $prefix . 'riverso_tienda_local_barcodes';
        $locals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.id, b.sku, b.barcode, p.nombre
                 FROM {$prefix}riverso_tienda_local_barcodes b
                 LEFT JOIN {$prefix}riverso_tienda_local_productos p ON p.sku = b.sku
                 WHERE b.barcode = %s OR b.barcode_norm = %s
                 LIMIT 5",
                $code,
                ltrim($code, '0') === '' ? '0' : ltrim($code, '0')
            ),
            ARRAY_A
        ) ?: [];
        foreach ($locals as $local) {
                $pb = null;
                if (!empty($local['sku'])) {
                    $pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico, unidad_base
                         FROM {$prefix}riverso_producto_base
                         WHERE canonical_sku = %s
                         LIMIT 1",
                        $local['sku']
                    ), ARRAY_A);
                }
                $suggestions[] = self::format_result([
                    'producto_base_id' => $pb ? intval($pb['id']) : null,
                    'proveedor_id' => null,
                    'cantidad_unidades' => 1,
                    'unidad_medida' => $pb['unidad_base'] ?? 'unidad',
                    'presentacion_id' => null,
                    'factor_a_unidad_base' => 1,
                    'tipo' => 'ean13',
                    'origen' => 'legacy_tienda_local',
                    'legacy' => true,
                    'codigo_id' => intval($local['id']),
                    'sku_local' => $local['sku'] ?: null,
                    'pending_sku' => $pb ? null : ($local['sku'] ?: null),
                    'canonical_sku' => $pb['canonical_sku'] ?? null,
                    'nombre_canonico' => $pb['nombre_canonico'] ?? ($local['nombre'] ?? null),
                    'estado' => 'propuesto',
                    'requires_review' => true,
                ]);
        }

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
                 LIMIT 5",
                ...$params
            ),
            ARRAY_A
        ) ?: [];

        foreach ($supplier_codes as $supplier_code) {
            if (empty($supplier_code['product_base_id'])) {
                continue;
            }
            $factor = max(1, floatval($supplier_code['factor_conversion']));
            $suggestions[] = self::format_result([
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
                'estado' => 'propuesto',
                'requires_review' => true,
            ]);
        }

        return $suggestions;
    }

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
            'canonical_sku' => $product['canonical_sku'] ?? null,
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

        $result['product_base_id'] = $result['producto_base_id'];
        $result['supplier_id'] = $result['proveedor_id'];
        $result['cantidad'] = $result['cantidad_unidades'];
        $result['envase_id'] = $result['presentacion_id'];
        $result['type'] = $result['tipo'];
        return $result;
    }

    public static function create($codigo, $tipo, $producto_base_id, $cantidad, $unidad_medida, $proveedor_id = null, $envase_id = null, $factor_a_unidad_base = 1) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}codigo_barra
             WHERE codigo = %s AND estado = 'verificado' AND activo = 1
             LIMIT 1",
            $codigo
        ));
        if ($existing) {
            return false;
        }

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
                'requires_human_review' => 0,
                'conflicto' => 0,
                'created_at' => current_time('mysql'),
            ]
        );

        if ($result) {
            if (function_exists('riverso_event_publish')) {
                riverso_event_publish('barcode.created', [
                    'codigo_id' => $wpdb->insert_id,
                    'codigo' => $codigo,
                    'tipo' => $tipo,
                    'producto_base_id' => $producto_base_id,
                ], [
                    'user_id' => get_current_user_id(),
                ]);
            }
            return $wpdb->insert_id;
        }

        return false;
    }

    public static function update($id, $fields) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = absint($id);
        if ($id <= 0) {
            return false;
        }

        $allowed = [
            'codigo', 'tipo', 'producto_base_id', 'proveedor_id', 'cantidad',
            'unidad_medida', 'envase_id', 'factor_a_unidad_base', 'activo',
            'estado', 'motivo_estado', 'sku_local', 'pending_sku', 'origen_datos',
            'conflicto', 'requires_human_review', 'estado_por', 'estado_at',
        ];
        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }
        if (empty($data)) {
            return false;
        }
        $data['updated_at'] = current_time('mysql');

        return $wpdb->update("{$prefix}codigo_barra", $data, ['id' => $id]) !== false;
    }

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

    public static function deactivate($codigo_id) {
        return self::set_status($codigo_id, 'en_desuso', 'Desactivado desde administración.');
    }

    public static function set_status($codigo_id, $estado, $motivo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $allowed = ['propuesto', 'verificado', 'en_desuso', 'rechazado'];
        if (!in_array($estado, $allowed, true)) {
            return false;
        }

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT id, codigo, estado, motivo_estado FROM {$prefix}codigo_barra WHERE id = %d",
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
                'requires_human_review' => $estado === 'propuesto' ? 1 : 0,
                'conflicto' => 0,
            ],
            ['id' => $codigo_id],
            ['%s', '%d', '%s', '%d', '%s', '%d', '%d'],
            ['%d']
        );

        if ($updated !== false && $estado === 'verificado' && !empty($previous['codigo'])) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}codigo_barra
                 SET estado = 'rechazado', activo = 0, conflicto = 0,
                     motivo_estado = %s, estado_por = %d, estado_at = %s
                 WHERE codigo = %s AND id <> %d AND estado IN ('propuesto', 'verificado')",
                'Reemplazado al verificar otro mapeo del mismo código.',
                get_current_user_id() ?: 0,
                current_time('mysql'),
                $previous['codigo'],
                $codigo_id
            ));
        }

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

    public static function exists($codigo) {
        return self::resolve($codigo) !== false;
    }
}
