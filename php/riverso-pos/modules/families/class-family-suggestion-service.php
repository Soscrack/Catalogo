<?php
/**
 * Sugerencias de familias exactas (mismo ítem, distinto envase) desde catálogo Mamut.
 *
 * Agrupa por (nombre_producto, acabado, NOMINAL, LARGO); solo cambia ENVASE.
 * No auto-crea: el humano acepta vía Riverso_Family_Module.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Family_Suggestion_Service {

    /**
     * Rutas candidatas del catálogo Mamut (producción: dentro del plugin).
     *
     * @return string[]
     */
    public static function catalog_candidate_paths() {
        $paths = [];
        if (defined('RIVERSO_POS_PLUGIN_DIR')) {
            $paths[] = RIVERSO_POS_PLUGIN_DIR . 'data/catalogo_mamut_2025_spatial.json';
            // Monorepo local: php/riverso-pos → ../../data
            $paths[] = RIVERSO_POS_PLUGIN_DIR . '../../data/catalogo_mamut_2025_spatial.json';
        }
        // Uploads WP (opcional override manual)
        if (function_exists('wp_upload_dir')) {
            $uploads = wp_upload_dir();
            if (!empty($uploads['basedir'])) {
                $paths[] = trailingslashit($uploads['basedir']) . 'riverso/catalogo_mamut_2025_spatial.json';
            }
        }
        return $paths;
    }

    /**
     * @return string
     */
    public static function default_catalog_path() {
        foreach (self::catalog_candidate_paths() as $path) {
            $real = realpath($path);
            if ($real && is_readable($real)) {
                return $real;
            }
        }
        $fallback = self::catalog_candidate_paths();
        return $fallback[0] ?? 'catalogo_mamut_2025_spatial.json';
    }

    /**
     * Genera sugerencias de familias exactas (dry-run; no escribe).
     *
     * @param string $catalog_path
     * @param int    $limit
     * @param string $search Filtra por código proveedor, nombre, nominal o largo.
     * @return array|WP_Error
     */
    public static function suggest($catalog_path = '', $limit = 200, $search = '') {
        $path = $catalog_path !== '' ? $catalog_path : self::default_catalog_path();
        if (!is_readable($path)) {
            return new WP_Error('catalog_missing', 'No se puede leer el catálogo Mamut: ' . $path);
        }

        $catalog = json_decode(file_get_contents($path), true);
        if (!is_array($catalog) || empty($catalog['products']) || !is_array($catalog['products'])) {
            return new WP_Error('invalid_catalog', 'El catálogo no contiene products válidos.');
        }

        $groups = self::group_by_measure($catalog['products']);
        $suggestions = [];
        $stats = [
            'catalog_products' => count($catalog['products']),
            'candidate_groups' => 0,
            'suggested' => 0,
            'total_available' => 0,
            'skipped_single' => 0,
            'skipped_unresolved' => 0,
            'skipped_already_in_family' => 0,
            'skipped_kit' => 0,
            'search' => sanitize_text_field($search),
        ];

        foreach ($groups as $group_key => $group) {
            if (count($group['members']) < 2) {
                $stats['skipped_single']++;
                continue;
            }
            if (self::looks_like_kit($group)) {
                $stats['skipped_kit']++;
                continue;
            }

            $stats['candidate_groups']++;
            $resolved = self::resolve_members($group['members']);
            $resolved_ok = array_filter($resolved, function ($m) {
                return !empty($m['producto_base_id']);
            });

            if (count($resolved_ok) < 2) {
                $stats['skipped_unresolved']++;
                if (count($group['members']) < 2) {
                    continue;
                }
            }

            $conflict = self::find_exacta_conflict(array_column($resolved_ok, 'producto_base_id'));
            if ($conflict) {
                $stats['skipped_already_in_family']++;
                continue;
            }

            $confidence = self::score_confidence($group, $resolved);
            $suggestions[] = [
                'suggestion_key' => $group_key,
                'nombre' => $group['nombre'],
                'codigo_grupo_sugerido' => self::suggest_codigo($group),
                'tipo_sustitucion' => 'exacta',
                'nominal' => $group['nominal'],
                'largo' => $group['largo'],
                'acabado' => $group['acabado'],
                'nombre_producto' => $group['nombre_producto'],
                'confidence' => $confidence,
                'members' => $resolved,
                'resolved_count' => count($resolved_ok),
                'member_count' => count($resolved),
                'woo_confirmed' => self::woo_confirms_same_measure($resolved_ok),
            ];
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = function_exists('mb_strtolower')
                ? mb_strtolower($search)
                : strtolower($search);
            $suggestions = array_values(array_filter($suggestions, function ($s) use ($needle) {
                $hay = ($s['nombre'] ?? '') . ' ' . ($s['nombre_producto'] ?? '')
                    . ' ' . ($s['nominal'] ?? '') . ' ' . ($s['largo'] ?? '')
                    . ' ' . ($s['acabado'] ?? '') . ' ' . ($s['codigo_grupo_sugerido'] ?? '');
                foreach ($s['members'] as $m) {
                    $hay .= ' ' . ($m['codigo_proveedor'] ?? '') . ' ' . ($m['canonical_sku'] ?? '');
                }
                $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
                return strpos($hay, $needle) !== false;
            }));
        }

        usort($suggestions, function ($a, $b) {
            $order = ['alta' => 0, 'media' => 1, 'baja' => 2];
            $ca = $order[$a['confidence']] ?? 9;
            $cb = $order[$b['confidence']] ?? 9;
            if ($ca !== $cb) {
                return $ca - $cb;
            }
            // Más SKU locales resueltos primero (útil para revisar).
            $ra = intval($a['resolved_count'] ?? 0);
            $rb = intval($b['resolved_count'] ?? 0);
            if ($ra !== $rb) {
                return $rb - $ra;
            }
            return strcmp($a['nombre'], $b['nombre']);
        });

        $stats['total_available'] = count($suggestions);
        $limit = max(1, min(500, intval($limit)));
        $stats['suggested'] = min(count($suggestions), $limit);

        return [
            'stats' => $stats,
            'suggestions' => array_slice($suggestions, 0, $limit),
            'catalog_path' => $path,
        ];
    }

    /**
     * Agrupa productos Mamut por medida (no por padre Woo).
     *
     * @param array $products
     * @return array
     */
    public static function group_by_measure($products) {
        $groups = [];
        foreach ($products as $codigo => $row) {
            if (!is_array($row)) {
                continue;
            }
            $attrs = self::attributes_map($row['attributes'] ?? []);
            $nominal = trim((string) ($attrs['NOMINAL'] ?? $attrs['nominal'] ?? ''));
            $largo = trim((string) ($attrs['LARGO'] ?? $attrs['largo'] ?? ''));
            $acabado = trim((string) ($attrs['ACABADO'] ?? $attrs['Acabado'] ?? $attrs['acabado'] ?? 'Sin acabado'));
            $nombre_producto = trim((string) ($row['nombre_producto'] ?? $row['producto'] ?? ''));
            $envase_raw = trim((string) ($attrs['ENVASE'] ?? $attrs['Envase'] ?? $attrs['envase'] ?? ''));

            if ($nombre_producto === '' || $nominal === '' || $largo === '' || $envase_raw === '') {
                continue;
            }

            $package = self::parse_envase($envase_raw);
            if (!$package) {
                continue;
            }

            $key = strtolower(sanitize_title($nombre_producto . '|' . $acabado . '|' . $nominal . '|' . $largo));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'nombre_producto' => $nombre_producto,
                    'acabado' => $acabado,
                    'nominal' => $nominal,
                    'largo' => $largo,
                    'nombre' => self::build_family_name($nombre_producto, $nominal, $largo, $acabado),
                    'members' => [],
                ];
            }

            // Evitar duplicar el mismo código proveedor en el grupo.
            foreach ($groups[$key]['members'] as $existing) {
                if ($existing['codigo_proveedor'] === (string) $codigo) {
                    continue 2;
                }
            }

            $groups[$key]['members'][] = [
                'codigo_proveedor' => (string) $codigo,
                'envase_texto' => $envase_raw,
                'cantidad_unidades' => floatval($package['cantidad']),
                'unidad' => $package['unidad'],
            ];
        }

        // Solo grupos con ≥2 envases distintos.
        foreach ($groups as $key => $group) {
            $qtys = array_unique(array_map(function ($m) {
                return (string) $m['cantidad_unidades'];
            }, $group['members']));
            if (count($qtys) < 2) {
                unset($groups[$key]);
            }
        }

        return $groups;
    }

    /**
     * @param array $attrs_list
     * @return array
     */
    public static function attributes_map($attrs_list) {
        $map = [];
        foreach ((array) $attrs_list as $attr) {
            if (!is_array($attr)) {
                continue;
            }
            $name = trim((string) ($attr['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[$name] = trim((string) ($attr['value'] ?? ''));
            $map[strtoupper($name)] = $map[$name];
        }
        return $map;
    }

    /**
     * Parsea texto ENVASE → cantidad + unidad. Reutiliza lógica del backfill.
     *
     * @param string $raw
     * @return array|null
     */
    public static function parse_envase($raw) {
        $raw = trim((string) $raw);
        if ($raw === '' || !preg_match('/([\d.,]+)\s*([[:alpha:]]+)?/u', $raw, $matches)) {
            return null;
        }

        $number = $matches[1];
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $number)) {
            $number = str_replace([',', '.'], '', $number);
        } else {
            $number = str_replace(',', '.', $number);
        }
        $quantity = floatval($number);
        if ($quantity <= 0) {
            return null;
        }

        return [
            'cantidad' => $quantity,
            'unidad' => strtoupper($matches[2] ?? 'U'),
            'texto_original' => $raw,
        ];
    }

    private static function build_family_name($nombre, $nominal, $largo, $acabado) {
        $parts = [trim($nombre), trim($nominal) . ' x ' . trim($largo)];
        if ($acabado && strcasecmp($acabado, 'Sin acabado') !== 0) {
            $parts[] = $acabado;
        }
        return implode(' · ', $parts);
    }

    private static function suggest_codigo($group) {
        $base = sanitize_title(
            'EXA-' . ($group['nombre_producto'] ?? '') . '-' . ($group['nominal'] ?? '') . '-' . ($group['largo'] ?? '')
        );
        $base = strtoupper(substr(preg_replace('/[^A-Z0-9\-]/i', '', $base), 0, 80));
        return $base !== '' ? $base : ('EXA-' . substr(md5(wp_json_encode($group)), 0, 10));
    }

    private static function looks_like_kit($group) {
        $hay = strtolower(($group['nombre_producto'] ?? '') . ' ' . ($group['nombre'] ?? ''));
        foreach (['kit', 'surtido', 'assortment', 'pack mixt'] as $needle) {
            if (strpos($hay, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $members
     * @return array
     */
    private static function resolve_members($members) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $out = [];

        foreach ($members as $m) {
            $codigo = $m['codigo_proveedor'];
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT pp.id AS producto_proveedor_id, pp.producto_base_id, pp.grupo_id,
                        pb.canonical_sku, pb.nombre_canonico,
                        pb.woocommerce_product_id, pb.woocommerce_variation_id
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id AND pb.estado = 'activo'
                 WHERE pp.codigo_proveedor = %s AND pp.activo = 1
                 ORDER BY (pp.producto_base_id IS NOT NULL) DESC, pp.id DESC
                 LIMIT 1",
                $codigo
            ), ARRAY_A);

            $sku_local = null;
            $base_id = null;
            if ($row && !empty($row['producto_base_id'])) {
                $base_id = intval($row['producto_base_id']);
                $sku_local = $row['canonical_sku'];
            } elseif (function_exists('riverso_mamut_online_to_local_sku')) {
                $mapped = riverso_mamut_online_to_local_sku($codigo);
                if ($mapped) {
                    $pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                         FROM {$prefix}producto_base
                         WHERE canonical_sku = %s AND estado = 'activo' LIMIT 1",
                        $mapped
                    ), ARRAY_A);
                    if ($pb) {
                        $base_id = intval($pb['id']);
                        $sku_local = $pb['canonical_sku'];
                        $row = array_merge($row ?: [], $pb);
                    }
                }
            }

            $out[] = array_merge($m, [
                'producto_base_id' => $base_id,
                'canonical_sku' => $sku_local,
                'nombre_canonico' => $row['nombre_canonico'] ?? null,
                'producto_proveedor_id' => $row['producto_proveedor_id'] ?? null,
                'woocommerce_product_id' => isset($row['woocommerce_product_id']) ? intval($row['woocommerce_product_id']) : 0,
                'woocommerce_variation_id' => isset($row['woocommerce_variation_id']) ? intval($row['woocommerce_variation_id']) : 0,
                'resolved' => (bool) $base_id,
            ]);
        }

        usort($out, function ($a, $b) {
            return floatval($a['cantidad_unidades']) <=> floatval($b['cantidad_unidades']);
        });

        return $out;
    }

    /**
     * @param int[] $base_ids
     * @return array|null
     */
    private static function find_exacta_conflict($base_ids) {
        $base_ids = array_values(array_filter(array_map('intval', (array) $base_ids)));
        if (empty($base_ids)) {
            return null;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $in = implode(',', $base_ids);
        return $wpdb->get_row(
            "SELECT em.producto_base_id, em.grupo_id, g.codigo_grupo, g.nombre
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.activo = 1 AND g.activo = 1
               AND g.tipo_sustitucion = 'exacta'
               AND em.producto_base_id IN ({$in})
             LIMIT 1",
            ARRAY_A
        );
    }

    private static function score_confidence($group, $resolved) {
        $total = count($resolved);
        $ok = count(array_filter($resolved, function ($m) {
            return !empty($m['producto_base_id']);
        }));
        if ($total < 2) {
            return 'baja';
        }
        $woo = self::woo_confirms_same_measure(array_filter($resolved, function ($m) {
            return !empty($m['producto_base_id']);
        }));
        if ($ok === $total && $woo) {
            return 'alta';
        }
        if ($ok >= 2) {
            return 'media';
        }
        return 'baja';
    }

    /**
     * Confirma que las variaciones Woo resueltas comparten el mismo nominal-x-largo.
     *
     * @param array $resolved_ok
     * @return bool
     */
    private static function woo_confirms_same_measure($resolved_ok) {
        if (!function_exists('wc_get_product') || count($resolved_ok) < 2) {
            return false;
        }
        $combos = [];
        $parents = [];
        foreach ($resolved_ok as $m) {
            $vid = intval($m['woocommerce_variation_id'] ?? 0);
            if (!$vid) {
                return false;
            }
            $product = wc_get_product($vid);
            if (!$product || !$product->is_type('variation')) {
                return false;
            }
            $parents[] = $product->get_parent_id();
            $attrs = $product->get_attributes();
            $combo = '';
            foreach ($attrs as $key => $val) {
                $slug = strtolower((string) $key);
                if (strpos($slug, 'nominal-x-largo') !== false || strpos($slug, 'nominal_x_largo') !== false) {
                    $combo = (string) $val;
                    break;
                }
            }
            if ($combo === '') {
                return false;
            }
            $combos[] = strtolower(trim($combo));
        }
        $combos = array_unique($combos);
        $parents = array_unique($parents);
        return count($combos) === 1 && count($parents) === 1;
    }
}
