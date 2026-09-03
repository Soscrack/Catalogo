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
            if ($match && intval($legacy['producto_base_id'] ?? 0) === intval($match['producto_base_id'] ?? 0) && !empty($legacy['producto_base_id'])) {
                continue;
            }
            $merged_into = false;
            foreach ($suggestions as $idx => $existing) {
                if (!self::suggestions_are_identical($existing, $legacy)) {
                    continue;
                }
                $suggestions[$idx] = self::merge_identical_suggestions($existing, $legacy);
                $merged_into = true;
                break;
            }
            if (!$merged_into) {
                $suggestions[] = $legacy;
                if (!empty($legacy['producto_base_id'])) {
                    $product_ids[] = intval($legacy['producto_base_id']);
                }
            }
        }

        $suggestions = self::dedupe_identical_suggestions($suggestions);

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

    /**
     * Misma identidad de producto (SKU / producto_base), sin importar origen legacy.
     */
    private static function suggestion_identity_key($item) {
        $pb = intval($item['producto_base_id'] ?? 0);
        if ($pb > 0) {
            return 'pb:' . $pb;
        }
        $sku = strtolower(trim((string) (
            $item['canonical_sku']
            ?? $item['sku_local']
            ?? $item['pending_sku']
            ?? $item['sku']
            ?? ''
        )));
        if ($sku !== '') {
            return 'sku:' . $sku;
        }
        $origen = (string) ($item['origen'] ?? '');
        $cid = intval($item['codigo_id'] ?? $item['id'] ?? 0);
        return 'raw:' . $origen . ':' . $cid;
    }

    private static function suggestions_are_identical($a, $b) {
        return self::suggestion_identity_key($a) === self::suggestion_identity_key($b);
    }

    /**
     * Conserva el mejor id/nombre y une orígenes (legacy + tienda_local → una tarjeta).
     */
    private static function merge_identical_suggestions($base, $extra) {
        $merged = $base;
        foreach (['producto_base_id', 'canonical_sku', 'sku_local', 'pending_sku', 'nombre_canonico', 'unidad_medida'] as $field) {
            if (empty($merged[$field]) && !empty($extra[$field])) {
                $merged[$field] = $extra[$field];
            }
        }
        // Preferir fila ya en mapeo interno (codigo_barra) sobre solo-legacy.
        $base_id = intval($base['codigo_id'] ?? $base['id'] ?? 0);
        $extra_id = intval($extra['codigo_id'] ?? $extra['id'] ?? 0);
        $base_in_map = $base_id > 0 && strpos((string) ($base['origen'] ?? ''), 'legacy') === false;
        $extra_in_map = $extra_id > 0 && strpos((string) ($extra['origen'] ?? ''), 'legacy') === false;
        if ($extra_in_map && !$base_in_map) {
            $merged['codigo_id'] = $extra_id;
            $merged['id'] = $extra_id;
            if (!empty($extra['estado'])) {
                $merged['estado'] = $extra['estado'];
            }
        } elseif ($base_id <= 0 && $extra_id > 0) {
            $merged['codigo_id'] = $extra_id;
            $merged['id'] = $extra_id;
        }

        $origins = [];
        foreach ([$base['origen'] ?? '', $extra['origen'] ?? ''] as $origen) {
            $origen = trim((string) $origen);
            if ($origen === '' || in_array($origen, $origins, true)) {
                continue;
            }
            $origins[] = $origen;
        }
        if ($origins) {
            $merged['origen'] = implode(' + ', $origins);
            $merged['legacy'] = true;
        }
        return $merged;
    }

    private static function dedupe_identical_suggestions(array $suggestions) {
        $out = [];
        foreach ($suggestions as $item) {
            $merged = false;
            foreach ($out as $idx => $existing) {
                if (!self::suggestions_are_identical($existing, $item)) {
                    continue;
                }
                $out[$idx] = self::merge_identical_suggestions($existing, $item);
                $merged = true;
                break;
            }
            if (!$merged) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Búsqueda unificada para buscadores: mapeo verificado → propuesto unívoco → SKU/nombre.
     * No corta el flujo cuando el código aún no está verificado.
     *
     * @param array{include_unverified?:bool,limit?:int} $opts
     * @return array{query:string,trusted:bool,conflicts:bool,barcode_exact:bool,hits:array,suggestions:array,rejected:array,message:?string}
     */
    public static function lookup_for_search($query, $opts = []) {
        $query = trim((string) $query);
        $limit = max(1, min(50, intval($opts['limit'] ?? 20)));
        $include_unverified = !array_key_exists('include_unverified', $opts) || $opts['include_unverified'];
        $out = [
            'query' => $query,
            'trusted' => false,
            'conflicts' => false,
            'barcode_exact' => false,
            'hits' => [],
            'suggestions' => [],
            'rejected' => [],
            'message' => null,
        ];
        if ($query === '') {
            return $out;
        }

        $seen = [];
        $add_hit = static function ($hit) use (&$out, &$seen, $limit) {
            if (!$hit) {
                return;
            }
            $id = intval($hit['producto_base_id'] ?? 0);
            if ($id > 0 && !empty($seen[$id])) {
                return;
            }
            if ($id > 0) {
                $seen[$id] = true;
            }
            $out['hits'][] = $hit;
        };

        if (self::looks_like_barcode($query)) {
            $bundle = self::resolve_with_suggestions($query);
            $out['suggestions'] = $bundle['suggestions'];
            $out['rejected'] = $bundle['rejected'];
            $out['conflicts'] = !empty($bundle['conflicts']);

            if (!empty($bundle['match']) && !empty($bundle['match']['producto_base_id'])) {
                $add_hit(self::hit_from_mapping($bundle['match'], true, 'barcode_verified'));
                $out['trusted'] = true;
                $out['barcode_exact'] = true;
                return $out;
            }

            if ($include_unverified) {
                $candidates = self::unique_mapping_candidates($bundle);
                if ($out['conflicts'] || count($candidates) > 1) {
                    $out['conflicts'] = true;
                    $out['message'] = 'Este código tiene más de un SKU posible. Resuelve el conflicto en /interno/barcodes.';
                    foreach ($candidates as $cand) {
                        $add_hit(self::hit_from_mapping($cand, false, 'barcode_conflict'));
                    }
                } elseif (count($candidates) === 1) {
                    $add_hit(self::hit_from_mapping($candidates[0], false, 'barcode_proposed'));
                    $out['message'] = 'Código no confirmado. Aparece como sugerencia del mapeo interno.';
                }
            }
        }

        foreach (self::search_producto_base_text($query, $limit) as $row) {
            if (count($out['hits']) >= $limit) {
                break;
            }
            $source = strcasecmp((string) ($row['canonical_sku'] ?? ''), $query) === 0 ? 'sku' : 'name';
            $add_hit(self::hit_from_producto_base($row, true, $source));
        }

        return $out;
    }

    /**
     * Resolución operativa (escaneo/conteo): verificado, o propuesto unívoco.
     *
     * @return array{resolved:?array,trusted:bool,conflicts:bool,suggestions:array}
     */
    public static function resolve_for_operation($code) {
        $bundle = self::resolve_with_suggestions($code);
        if (!empty($bundle['match'])) {
            return [
                'resolved' => $bundle['match'],
                'trusted' => true,
                'conflicts' => false,
                'suggestions' => $bundle['suggestions'],
            ];
        }
        $candidates = self::unique_mapping_candidates($bundle);
        if (!empty($bundle['conflicts']) || count($candidates) > 1) {
            return [
                'resolved' => null,
                'trusted' => false,
                'conflicts' => true,
                'suggestions' => $bundle['suggestions'],
            ];
        }
        if (count($candidates) === 1) {
            return [
                'resolved' => $candidates[0],
                'trusted' => false,
                'conflicts' => false,
                'suggestions' => $bundle['suggestions'],
            ];
        }
        return [
            'resolved' => null,
            'trusted' => false,
            'conflicts' => false,
            'suggestions' => $bundle['suggestions'],
        ];
    }

    private static function unique_mapping_candidates($bundle) {
        $by_id = [];
        foreach ($bundle['suggestions'] ?? [] as $suggestion) {
            $id = intval($suggestion['producto_base_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!isset($by_id[$id])) {
                $by_id[$id] = $suggestion;
            }
        }
        return array_values($by_id);
    }

    private static function hit_from_mapping($mapping, $trusted, $source) {
        $pb_id = intval($mapping['producto_base_id'] ?? 0);
        if ($pb_id <= 0) {
            return null;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pb = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id, estado
                 FROM {$prefix}producto_base
                 WHERE id = %d",
                $pb_id
            ),
            ARRAY_A
        );
        if (!$pb) {
            return null;
        }
        if (empty($pb['canonical_sku']) && !empty($mapping['canonical_sku'])) {
            $pb['canonical_sku'] = $mapping['canonical_sku'];
        }
        if (empty($pb['nombre_canonico']) && !empty($mapping['nombre_canonico'])) {
            $pb['nombre_canonico'] = $mapping['nombre_canonico'];
        }
        return self::hit_from_producto_base($pb, $trusted, $source, $mapping);
    }

    private static function hit_from_producto_base($pb, $trusted, $source, $mapping = null) {
        $woo_product = !empty($pb['woocommerce_product_id']) ? intval($pb['woocommerce_product_id']) : null;
        $woo_var = !empty($pb['woocommerce_variation_id']) ? intval($pb['woocommerce_variation_id']) : null;
        $labels = [
            'barcode_verified' => 'Código de barra (mapeo verificado)',
            'barcode_proposed' => 'Código de barra (no confirmado)',
            'barcode_conflict' => 'Código de barra (conflicto)',
            'sku' => 'SKU',
            'name' => 'Nombre',
        ];

        $units_per_pack = 1.0;
        if (is_array($mapping)) {
            $qty = floatval($mapping['cantidad'] ?? $mapping['cantidad_unidades'] ?? 0);
            $factor = floatval($mapping['factor_a_unidad_base'] ?? 0);
            if ($qty > 0) {
                $units_per_pack = $qty;
            } elseif ($factor > 0) {
                $units_per_pack = $factor;
            }
        }

        $unitario = null;
        $grupo_id = null;
        $base_id = intval($pb['id']);
        if ($base_id > 0 && class_exists('Riverso_Unit_Product_Service')) {
            $svc = Riverso_Unit_Product_Service::get_instance();
            $ctx = $svc->resolve_family_unit_for_base($base_id);
            if ($ctx && !empty($ctx['unit_producto_base_id'])) {
                $grupo_id = intval($ctx['grupo_id']);
                $unit_id = intval($ctx['unit_producto_base_id']);
                if ($unit_id !== $base_id) {
                    global $wpdb;
                    $prefix = $wpdb->prefix . 'riverso_';
                    $unit_pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico
                         FROM {$prefix}producto_base WHERE id = %d",
                        $unit_id
                    ), ARRAY_A);
                    if ($unit_pb) {
                        $unitario = [
                            'id' => intval($unit_pb['id']),
                            'producto_base_id' => intval($unit_pb['id']),
                            'canonical_sku' => $unit_pb['canonical_sku'] ?? '',
                            'nombre_canonico' => $unit_pb['nombre_canonico'] ?? '',
                        ];
                    }
                } else {
                    $unitario = [
                        'id' => $base_id,
                        'producto_base_id' => $base_id,
                        'canonical_sku' => $pb['canonical_sku'] ?? '',
                        'nombre_canonico' => $pb['nombre_canonico'] ?? ($pb['canonical_sku'] ?? ''),
                    ];
                }
            }
        }

        return [
            'producto_base_id' => $base_id,
            'canonical_sku' => $pb['canonical_sku'] ?? '',
            'nombre_canonico' => $pb['nombre_canonico'] ?? ($pb['canonical_sku'] ?? ''),
            'woocommerce_product_id' => $woo_product,
            'woocommerce_variation_id' => $woo_var,
            'wc_id' => $woo_var ?: $woo_product,
            'trusted' => (bool) $trusted,
            'source' => $source,
            'barcode' => $mapping,
            'match_source' => $labels[$source] ?? $source,
            'units_per_pack' => $units_per_pack,
            'unitario' => $unitario,
            'grupo_id' => $grupo_id,
        ];
    }

    private static function search_producto_base_text($query, $limit) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($query) . '%';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico,
                        pb.woocommerce_product_id, pb.woocommerce_variation_id, pb.estado
                 FROM {$prefix}producto_base pb
                 WHERE pb.estado = 'activo'
                   AND pb.deleted_at IS NULL
                   AND (
                        pb.canonical_sku = %s
                     OR pb.canonical_sku LIKE %s
                     OR pb.nombre_canonico LIKE %s
                     OR EXISTS (
                        SELECT 1 FROM {$prefix}codigo_barra cb
                        WHERE cb.producto_base_id = pb.id
                          AND cb.activo = 1
                          AND cb.estado IN ('verificado', 'propuesto')
                          AND (cb.codigo = %s OR cb.codigo LIKE %s)
                     )
                   )
                 ORDER BY
                    CASE
                        WHEN pb.canonical_sku = %s THEN 0
                        WHEN EXISTS (
                            SELECT 1 FROM {$prefix}codigo_barra cb2
                            WHERE cb2.producto_base_id = pb.id
                              AND cb2.activo = 1
                              AND cb2.codigo = %s
                        ) THEN 1
                        WHEN pb.canonical_sku LIKE %s THEN 2
                        ELSE 3
                    END,
                    pb.canonical_sku ASC
                 LIMIT %d",
                $query,
                $like,
                $like,
                $query,
                $like,
                $query,
                $query,
                $wpdb->esc_like($query) . '%',
                $limit
            ),
            ARRAY_A
        ) ?: [];
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
        $producto_base_id = absint($producto_base_id);
        if (!$producto_base_id) {
            return [];
        }

        $sku = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT canonical_sku FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ));

        $where_extra = '';
        $query_args = [$producto_base_id, $sku, $sku, $sku];
        if ($proveedor_id) {
            $where_extra = ' AND proveedor_id = %d';
            $query_args[] = absint($proveedor_id);
        }

        $query = $wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra
             WHERE activo = 1
               AND estado IN ('verificado', 'propuesto')
               AND (
                    producto_base_id = %d
                 OR (
                    producto_base_id IS NULL
                    AND %s <> ''
                    AND (sku_local = %s OR pending_sku = %s)
                 )
               )
               {$where_extra}
             ORDER BY id ASC",
            $query_args
        );

        $rows = $wpdb->get_results($query, ARRAY_A) ?: [];
        return self::dedupe_product_barcodes($rows);
    }

    /**
     * Una fila por código: prioriza verificado y el vínculo a producto_base.
     */
    private static function dedupe_product_barcodes(array $rows) {
        $best = [];
        foreach ($rows as $row) {
            $codigo = trim((string) ($row['codigo'] ?? ''));
            if ($codigo === '') {
                continue;
            }
            $score = 0;
            if (($row['estado'] ?? '') === 'verificado') {
                $score += 8;
            }
            if (!empty($row['producto_base_id'])) {
                $score += 4;
            }
            if (!self::is_legacy_row($row)) {
                $score += 2;
            }
            $score += min(1, absint($row['id'] ?? 0) > 0 ? 1 : 0);
            if (!isset($best[$codigo]) || $score > $best[$codigo]['score']) {
                $best[$codigo] = ['score' => $score, 'row' => $row];
            }
        }
        return array_values(array_map(static function ($item) {
            return $item['row'];
        }, $best));
    }

    public static function deactivate($codigo_id) {
        return self::set_status($codigo_id, 'en_desuso', 'Desactivado desde administración.');
    }

    /**
     * Detecta si una fila de codigo_barra proviene de legacy.
     */
    public static function is_legacy_row($row) {
        if (!is_array($row)) {
            return false;
        }
        $origen = strtolower((string) ($row['origen_datos'] ?? ''));
        $migrado = trim((string) ($row['migrado_de_tabla'] ?? ''));
        $legacy_ref = trim((string) ($row['legacy_ref'] ?? ''));
        return strpos($origen, 'legacy') !== false || $migrado !== '' || $legacy_ref !== '';
    }

    /**
     * Acepta un barcode legacy como Código de Proveedor en mapeo interno.
     * Limpia flags legacy, marca verificado y rechaza duplicados del mismo código.
     */
    public static function accept_legacy_as_supplier($codigo_id, $motivo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $codigo_id = absint($codigo_id);
        if (!$codigo_id) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $codigo_id
        ), ARRAY_A);
        if (!$row) {
            return false;
        }

        $wpdb->update(
            "{$prefix}codigo_barra",
            [
                'tipo' => 'supplier',
                'origen_datos' => 'manual',
                'legacy_ref' => '',
                'migrado_de_tabla' => '',
                'requires_human_review' => 0,
                'conflicto' => 0,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $codigo_id],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%s'],
            ['%d']
        );

        $ok = self::set_status(
            $codigo_id,
            'verificado',
            $motivo !== '' ? $motivo : 'Aceptado como Código de Proveedor desde revisión legacy.'
        );

        // Rechazar otras filas del mismo código en el mismo producto (duplicados legacy).
        if ($ok && !empty($row['codigo']) && !empty($row['producto_base_id'])) {
            $siblings = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$prefix}codigo_barra
                 WHERE producto_base_id = %d AND codigo = %s AND id <> %d
                   AND activo = 1 AND estado IN ('propuesto', 'verificado', 'en_desuso')",
                (int) $row['producto_base_id'],
                $row['codigo'],
                $codigo_id
            )) ?: [];
            foreach ($siblings as $sib_id) {
                self::set_status((int) $sib_id, 'rechazado', 'Duplicado legacy reemplazado al aceptar mapeo.');
            }
        }

        return $ok;
    }

    /**
     * Rechaza (elimina lógicamente) un barcode legacy y sus duplicados en el producto.
     */
    public static function reject_legacy($codigo_id, $motivo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $codigo_id = absint($codigo_id);
        if (!$codigo_id) {
            return false;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $codigo_id
        ), ARRAY_A);
        if (!$row) {
            return false;
        }

        $motivo = $motivo !== '' ? $motivo : 'Rechazado desde revisión legacy.';
        $ids = [$codigo_id];
        if (!empty($row['codigo']) && !empty($row['producto_base_id'])) {
            $siblings = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$prefix}codigo_barra
                 WHERE producto_base_id = %d AND codigo = %s AND id <> %d
                   AND activo = 1 AND estado IN ('propuesto', 'verificado', 'en_desuso')",
                (int) $row['producto_base_id'],
                $row['codigo'],
                $codigo_id
            )) ?: [];
            foreach ($siblings as $sib_id) {
                $ids[] = (int) $sib_id;
            }
        }

        $ok = true;
        foreach (array_unique($ids) as $id) {
            if (!self::set_status((int) $id, 'rechazado', $motivo)) {
                $ok = false;
            }
        }
        return $ok;
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
