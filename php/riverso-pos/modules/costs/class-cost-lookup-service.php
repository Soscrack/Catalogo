<?php
/**
 * Cost Lookup Service
 * Busca productos y arma historial de costos por par (proveedor, código proveedor)
 * leyendo en vivo desde facturas / factura_items.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Cost_Lookup_Service {

    private static $instance = null;

    /** @var string */
    private $prefix;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'riverso_';
    }

    /**
     * Costo unitario desde una fila de factura_items.
     *
     * @param object|array $row
     * @return float|null
     */
    public static function unit_cost_from_item($row) {
        $row = (array) $row;
        $landed = isset($row['costo_landed_unitario']) ? floatval($row['costo_landed_unitario']) : 0;
        if ($landed > 0) {
            return $landed;
        }
        $qty = isset($row['cantidad']) ? floatval($row['cantidad']) : 0;
        $neto = isset($row['costo_neto_final']) ? floatval($row['costo_neto_final']) : 0;
        if ($qty > 0 && $neto > 0) {
            return round($neto / $qty, 4);
        }
        $precio = isset($row['precio_unitario']) ? floatval($row['precio_unitario']) : 0;
        return $precio > 0 ? $precio : null;
    }

    /**
     * Búsqueda unificada: SKU local, barcode, código proveedor, nombre.
     *
     * @param string $term
     * @param int    $limit
     * @return array
     */
    public function search($term, $limit = 15) {
        global $wpdb;

        $term = trim((string) $term);
        $limit = max(1, min(30, (int) $limit));
        if ($term === '' || strlen($term) < 1) {
            return [];
        }

        $results = [];
        $seen_pb = [];
        $seen_loose = [];

        $add = function ($row) use (&$results, &$seen_pb, &$seen_loose, $limit) {
            if (count($results) >= $limit) {
                return;
            }
            $pb_id = !empty($row['producto_base_id']) ? (int) $row['producto_base_id'] : null;
            if ($pb_id) {
                if (!empty($seen_pb[$pb_id])) {
                    return;
                }
                $seen_pb[$pb_id] = true;
            } else {
                $key = (int) ($row['proveedor_id'] ?? 0) . '|' . strtolower((string) ($row['codigo_proveedor'] ?? ''));
                if ($key === '0|' || !empty($seen_loose[$key])) {
                    return;
                }
                $seen_loose[$key] = true;
            }
            $results[] = $row;
        };

        // 1) SKU canónico exacto
        $exact = $wpdb->get_results($wpdb->prepare(
            "SELECT id AS producto_base_id, canonical_sku, nombre_canonico AS nombre
             FROM {$this->prefix}producto_base
             WHERE canonical_sku = %s
             LIMIT %d",
            $term,
            $limit
        ), ARRAY_A);
        foreach ($exact as $row) {
            $add([
                'producto_base_id' => (int) $row['producto_base_id'],
                'canonical_sku' => $row['canonical_sku'],
                'nombre' => $row['nombre'],
                'match_source' => 'sku_exact',
                'proveedor_id' => null,
                'codigo_proveedor' => null,
                'pares_count' => 0,
            ]);
        }

        // 2) Código de barras
        if (count($results) < $limit && class_exists('Riverso_Barcode_Model')) {
            $bundle = Riverso_Barcode_Model::lookup_for_search($term, ['limit' => $limit]);
            foreach ($bundle['hits'] as $hit) {
                $pb_id = (int) ($hit['producto_base_id'] ?? 0);
                if (!$pb_id) {
                    continue;
                }
                $pb = $this->get_producto_base($pb_id);
                if (!$pb) {
                    continue;
                }
                $add([
                    'producto_base_id' => $pb_id,
                    'canonical_sku' => $pb['canonical_sku'],
                    'nombre' => $pb['nombre_canonico'],
                    'match_source' => $hit['match_source'] ?? 'barcode',
                    'proveedor_id' => null,
                    'codigo_proveedor' => null,
                    'pares_count' => 0,
                ]);
            }
        }

        // 3) producto_proveedor por código / barcode proveedor
        if (count($results) < $limit) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pp.producto_base_id, pb.canonical_sku, pb.nombre_canonico AS nombre,
                        pp.proveedor_id, pp.codigo_proveedor
                 FROM {$this->prefix}producto_proveedor pp
                 INNER JOIN {$this->prefix}producto_base pb ON pb.id = pp.producto_base_id
                 WHERE pp.activo = 1
                   AND (pp.codigo_proveedor = %s
                        OR pp.codigo_barras_proveedor = %s
                        OR pp.codigo_proveedor LIKE %s
                        OR pp.codigo_barras_proveedor LIKE %s)
                 LIMIT %d",
                $term,
                $term,
                $like,
                $like,
                $limit
            ), ARRAY_A);
            foreach ($rows as $row) {
                $exact_code = strcasecmp((string) $row['codigo_proveedor'], $term) === 0;
                $add([
                    'producto_base_id' => (int) $row['producto_base_id'],
                    'canonical_sku' => $row['canonical_sku'],
                    'nombre' => $row['nombre'],
                    'match_source' => $exact_code ? 'supplier_code' : 'supplier_code_partial',
                    'proveedor_id' => (int) $row['proveedor_id'],
                    'codigo_proveedor' => $row['codigo_proveedor'],
                    'pares_count' => 0,
                ]);
            }
        }

        // 4) supplier_product_links
        if (count($results) < $limit) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $spl = $this->prefix . 'supplier_product_links';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $spl));
            if ($table_exists) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT COALESCE(spl.product_base_id, 0) AS producto_base_id,
                            spl.internal_sku AS canonical_sku,
                            spl.supplier_description AS nombre,
                            spl.supplier_id AS proveedor_id,
                            spl.supplier_code AS codigo_proveedor
                     FROM {$spl} spl
                     WHERE spl.is_active = 1
                       AND (spl.supplier_code = %s
                            OR spl.supplier_barcode = %s
                            OR spl.internal_sku = %s
                            OR spl.supplier_code LIKE %s
                            OR spl.internal_sku LIKE %s)
                     LIMIT %d",
                    $term,
                    $term,
                    $term,
                    $like,
                    $like,
                    $limit
                ), ARRAY_A);
                foreach ($rows as $row) {
                    $pb_id = (int) $row['producto_base_id'];
                    $sku = $row['canonical_sku'];
                    $nombre = $row['nombre'];
                    if ($pb_id) {
                        $pb = $this->get_producto_base($pb_id);
                        if ($pb) {
                            $sku = $pb['canonical_sku'];
                            $nombre = $pb['nombre_canonico'];
                        }
                    } elseif (!empty($row['canonical_sku'])) {
                        $pb = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, canonical_sku, nombre_canonico
                             FROM {$this->prefix}producto_base
                             WHERE canonical_sku = %s LIMIT 1",
                            $row['canonical_sku']
                        ), ARRAY_A);
                        if ($pb) {
                            $pb_id = (int) $pb['id'];
                            $sku = $pb['canonical_sku'];
                            $nombre = $pb['nombre_canonico'];
                        }
                    }
                    $add([
                        'producto_base_id' => $pb_id ?: null,
                        'canonical_sku' => $sku,
                        'nombre' => $nombre ?: $sku,
                        'match_source' => 'supplier_link',
                        'proveedor_id' => (int) $row['proveedor_id'],
                        'codigo_proveedor' => $row['codigo_proveedor'],
                        'pares_count' => 0,
                    ]);
                }
            }
        }

        // 5) codigos legacy
        if (count($results) < $limit) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT COALESCE(c.product_base_id, 0) AS producto_base_id,
                        c.sku_local AS canonical_sku,
                        c.nombre_proveedor AS nombre,
                        c.proveedor_id,
                        c.codigo_proveedor
                 FROM {$this->prefix}codigos c
                 WHERE c.activo = 1
                   AND (c.codigo_proveedor = %s
                        OR c.sku_local = %s
                        OR c.codigo_barras = %s
                        OR c.codigo_proveedor LIKE %s
                        OR c.sku_local LIKE %s)
                 LIMIT %d",
                $term,
                $term,
                $term,
                $like,
                $like,
                $limit
            ), ARRAY_A);
            foreach ($rows as $row) {
                $pb_id = (int) $row['producto_base_id'];
                $sku = $row['canonical_sku'];
                $nombre = $row['nombre'];
                if ($pb_id) {
                    $pb = $this->get_producto_base($pb_id);
                    if ($pb) {
                        $sku = $pb['canonical_sku'];
                        $nombre = $pb['nombre_canonico'];
                    }
                } elseif (!empty($sku)) {
                    $pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico
                         FROM {$this->prefix}producto_base
                         WHERE canonical_sku = %s LIMIT 1",
                        $sku
                    ), ARRAY_A);
                    if ($pb) {
                        $pb_id = (int) $pb['id'];
                        $sku = $pb['canonical_sku'];
                        $nombre = $pb['nombre_canonico'];
                    }
                }
                $add([
                    'producto_base_id' => $pb_id ?: null,
                    'canonical_sku' => $sku,
                    'nombre' => $nombre ?: $sku,
                    'match_source' => 'codigo_legacy',
                    'proveedor_id' => (int) $row['proveedor_id'],
                    'codigo_proveedor' => $row['codigo_proveedor'],
                    'pares_count' => 0,
                ]);
            }
        }

        // 6) SKU / nombre parcial en producto_base
        if (count($results) < $limit) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id AS producto_base_id, canonical_sku, nombre_canonico AS nombre
                 FROM {$this->prefix}producto_base
                 WHERE canonical_sku LIKE %s OR nombre_canonico LIKE %s
                 ORDER BY
                    CASE WHEN canonical_sku = %s THEN 0
                         WHEN canonical_sku LIKE %s THEN 1
                         ELSE 2 END,
                    nombre_canonico ASC
                 LIMIT %d",
                $like,
                $like,
                $term,
                $wpdb->esc_like($term) . '%',
                $limit
            ), ARRAY_A);
            foreach ($rows as $row) {
                $add([
                    'producto_base_id' => (int) $row['producto_base_id'],
                    'canonical_sku' => $row['canonical_sku'],
                    'nombre' => $row['nombre'],
                    'match_source' => 'sku_name',
                    'proveedor_id' => null,
                    'codigo_proveedor' => null,
                    'pares_count' => 0,
                ]);
            }
        }

        // 7) Fallback: código visto en facturas sin catálogo
        if (count($results) < $limit) {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT f.proveedor_id, fi.codigo_proveedor,
                        MAX(fi.nombre) AS nombre,
                        p.nombre AS proveedor_nombre
                 FROM {$this->prefix}factura_items fi
                 INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                 LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                 WHERE fi.item_tipo = 'producto'
                   AND fi.codigo_proveedor IS NOT NULL
                   AND fi.codigo_proveedor != ''
                   AND (fi.codigo_proveedor = %s OR fi.codigo_proveedor LIKE %s OR fi.nombre LIKE %s)
                 GROUP BY f.proveedor_id, fi.codigo_proveedor
                 ORDER BY MAX(f.fecha_emision) DESC
                 LIMIT %d",
                $term,
                $like,
                $like,
                $limit
            ), ARRAY_A);
            foreach ($rows as $row) {
                $add([
                    'producto_base_id' => null,
                    'canonical_sku' => null,
                    'nombre' => $row['nombre'],
                    'match_source' => 'invoice_loose',
                    'proveedor_id' => (int) $row['proveedor_id'],
                    'codigo_proveedor' => $row['codigo_proveedor'],
                    'proveedor_nombre' => $row['proveedor_nombre'],
                    'pares_count' => 1,
                ]);
            }
        }

        // Enriquecer pares_count
        foreach ($results as &$r) {
            if (!empty($r['producto_base_id'])) {
                $r['pares_count'] = count($this->get_supplier_pairs((int) $r['producto_base_id']));
            } elseif (empty($r['pares_count'])) {
                $r['pares_count'] = (!empty($r['proveedor_id']) && !empty($r['codigo_proveedor'])) ? 1 : 0;
            }
        }
        unset($r);

        return array_values($results);
    }

    /**
     * Pares (proveedor_id, codigo_proveedor) vinculados a un producto_base.
     *
     * @param int $producto_base_id
     * @return array
     */
    public function get_supplier_pairs($producto_base_id) {
        global $wpdb;

        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return [];
        }

        $pairs = [];
        $key_of = static function ($prov_id, $code) {
            return (int) $prov_id . '|' . strtolower(trim((string) $code));
        };

        $add_pair = function ($prov_id, $code, $nombre = null, $source = '') use (&$pairs, $key_of, $wpdb) {
            $prov_id = (int) $prov_id;
            $code = trim((string) $code);
            if ($prov_id <= 0 || $code === '') {
                return;
            }
            $key = $key_of($prov_id, $code);
            if (isset($pairs[$key])) {
                return;
            }
            if ($nombre === null) {
                $nombre = $wpdb->get_var($wpdb->prepare(
                    "SELECT nombre FROM {$this->prefix}proveedores WHERE id = %d",
                    $prov_id
                ));
            }
            $pairs[$key] = [
                'proveedor_id' => $prov_id,
                'codigo_proveedor' => $code,
                'proveedor_nombre' => $nombre ?: ('Proveedor #' . $prov_id),
                'source' => $source,
            ];
        };

        // producto_proveedor
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.proveedor_id, pp.codigo_proveedor, p.nombre AS proveedor_nombre
             FROM {$this->prefix}producto_proveedor pp
             LEFT JOIN {$this->prefix}proveedores p ON p.id = pp.proveedor_id
             WHERE pp.producto_base_id = %d AND pp.activo = 1
               AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != ''",
            $producto_base_id
        ), ARRAY_A);
        foreach ($rows as $row) {
            $add_pair($row['proveedor_id'], $row['codigo_proveedor'], $row['proveedor_nombre'], 'producto_proveedor');
        }

        // supplier_product_links
        $spl = $this->prefix . 'supplier_product_links';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $spl))) {
            $pb = $this->get_producto_base($producto_base_id);
            $sku = $pb ? $pb['canonical_sku'] : '';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT spl.supplier_id AS proveedor_id, spl.supplier_code AS codigo_proveedor,
                        p.nombre AS proveedor_nombre
                 FROM {$spl} spl
                 LEFT JOIN {$this->prefix}proveedores p ON p.id = spl.supplier_id
                 WHERE spl.is_active = 1
                   AND (spl.product_base_id = %d OR (%s != '' AND spl.internal_sku = %s))
                   AND spl.supplier_code IS NOT NULL AND spl.supplier_code != ''",
                $producto_base_id,
                $sku,
                $sku
            ), ARRAY_A);
            foreach ($rows as $row) {
                $add_pair($row['proveedor_id'], $row['codigo_proveedor'], $row['proveedor_nombre'], 'supplier_link');
            }
        }

        // codigos legacy
        $pb = $this->get_producto_base($producto_base_id);
        $sku = $pb ? $pb['canonical_sku'] : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.proveedor_id, c.codigo_proveedor, p.nombre AS proveedor_nombre
             FROM {$this->prefix}codigos c
             LEFT JOIN {$this->prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.activo = 1
               AND (c.product_base_id = %d OR (%s != '' AND c.sku_local = %s))
               AND c.codigo_proveedor IS NOT NULL AND c.codigo_proveedor != ''",
            $producto_base_id,
            $sku,
            $sku
        ), ARRAY_A);
        foreach ($rows as $row) {
            $add_pair($row['proveedor_id'], $row['codigo_proveedor'], $row['proveedor_nombre'], 'codigos');
        }

        // También pares vistos en facturas vía sku_local del item
        if ($sku) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT f.proveedor_id, fi.codigo_proveedor, p.nombre AS proveedor_nombre
                 FROM {$this->prefix}factura_items fi
                 INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                 LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                 WHERE fi.item_tipo = 'producto'
                   AND fi.sku_local = %s
                   AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''
                   AND f.proveedor_id IS NOT NULL",
                $sku
            ), ARRAY_A);
            foreach ($rows as $row) {
                $add_pair($row['proveedor_id'], $row['codigo_proveedor'], $row['proveedor_nombre'], 'factura_sku');
            }
        }

        return array_values($pairs);
    }

    /**
     * Timeline: últimas N facturas por cada par.
     *
     * @param array $pares  [{proveedor_id, codigo_proveedor}, ...]
     * @param array $args
     * @return array
     */
    public function get_timeline($pares, $args = []) {
        // Default 3; permitir expandir hasta 500 (todas las prácticas)
        $limit = isset($args['limit_per_pair']) ? max(1, min(500, (int) $args['limit_per_pair'])) : 3;
        $doc_type = $this->normalize_doc_type($args['doc_type'] ?? 'factura');
        $out = [];

        foreach ($pares as $pair) {
            $prov_id = (int) ($pair['proveedor_id'] ?? 0);
            $code = trim((string) ($pair['codigo_proveedor'] ?? ''));
            if ($prov_id <= 0 || $code === '') {
                continue;
            }
            $total = $this->count_pair_documents($prov_id, $code, $doc_type);
            $docs = $this->query_pair_documents($prov_id, $code, $limit, null, $doc_type);
            $out[] = [
                'proveedor_id' => $prov_id,
                'codigo_proveedor' => $code,
                'proveedor_nombre' => $pair['proveedor_nombre'] ?? $this->get_proveedor_nombre($prov_id),
                'documents' => $docs,
                'shown' => count($docs),
                'total_documentos' => $total,
                'has_more' => $total > count($docs),
                'limit' => $limit,
                'doc_type' => $doc_type,
            ];
        }

        return $out;
    }

    /**
     * Resumen por par: último costo, previo, variación, min/max/avg.
     *
     * @param array $pares
     * @return array
     */
    public function get_pair_summary($pares, $doc_type = 'factura') {
        $doc_type = $this->normalize_doc_type($doc_type);
        $summaries = [];
        $global_latest = null;

        foreach ($pares as $pair) {
            $prov_id = (int) ($pair['proveedor_id'] ?? 0);
            $code = trim((string) ($pair['codigo_proveedor'] ?? ''));
            if ($prov_id <= 0 || $code === '') {
                continue;
            }

            $docs = $this->query_pair_documents($prov_id, $code, 500, null, $doc_type);
            $costs = [];
            foreach ($docs as $d) {
                if ($d['costo_unitario'] !== null) {
                    $costs[] = (float) $d['costo_unitario'];
                }
            }

            $latest = $docs[0] ?? null;
            $previous = $docs[1] ?? null;
            $variation = null;
            if ($latest && $previous && $previous['costo_unitario'] > 0 && $latest['costo_unitario'] !== null) {
                $variation = round(
                    (($latest['costo_unitario'] - $previous['costo_unitario']) / $previous['costo_unitario']) * 100,
                    2
                );
            }

            $summary = [
                'proveedor_id' => $prov_id,
                'codigo_proveedor' => $code,
                'proveedor_nombre' => $pair['proveedor_nombre'] ?? $this->get_proveedor_nombre($prov_id),
                'ultimo_costo' => $latest ? $latest['costo_unitario'] : null,
                'ultimo_fecha' => $latest ? $latest['fecha_emision'] : null,
                'ultimo_folio' => $latest ? $latest['folio'] : null,
                'ultimo_factura_id' => $latest ? ($latest['factura_id'] ?? null) : null,
                'ultimo_item_id' => $latest ? $latest['item_id'] : null,
                'ultimo_source_kind' => $latest ? ($latest['source_kind'] ?? 'invoice') : null,
                'costo_previo' => $previous ? $previous['costo_unitario'] : null,
                'variacion_pct' => $variation,
                'min_costo' => $costs ? min($costs) : null,
                'max_costo' => $costs ? max($costs) : null,
                'avg_costo' => $costs ? round(array_sum($costs) / count($costs), 2) : null,
                'total_documentos' => count($docs),
                'doc_type' => $doc_type,
            ];
            $summaries[] = $summary;

            if ($latest && $latest['costo_unitario'] !== null) {
                if (
                    $global_latest === null
                    || strcmp((string) $latest['fecha_emision'], (string) $global_latest['ultimo_fecha']) > 0
                    || (
                        $latest['fecha_emision'] === $global_latest['ultimo_fecha']
                        && (int) ($latest['factura_id'] ?? $latest['cotizacion_id'] ?? 0)
                            > (int) ($global_latest['ultimo_factura_id'] ?? 0)
                    )
                ) {
                    $global_latest = $summary;
                }
            }
        }

        return [
            'by_pair' => $summaries,
            'highlight' => $global_latest,
        ];
    }

    /**
     * Series para gráfico Chart.js (eje category).
     *
     * @param array $pares
     * @param int   $months
     * @return array
     */
    public function get_chart_series($pares, $months = 24, $doc_type = 'factura') {
        $months = max(1, min(60, (int) $months));
        $doc_type = $this->normalize_doc_type($doc_type);
        $date_from = date('Y-m-d', strtotime("-{$months} months"));
        $series = [];
        $all_dates = [];

        foreach ($pares as $pair) {
            $prov_id = (int) ($pair['proveedor_id'] ?? 0);
            $code = trim((string) ($pair['codigo_proveedor'] ?? ''));
            if ($prov_id <= 0 || $code === '') {
                continue;
            }

            $docs = $this->query_pair_documents($prov_id, $code, 500, $date_from, $doc_type);
            // Orden cronológico ascendente para el gráfico
            $docs = array_reverse($docs);
            $points = [];
            foreach ($docs as $d) {
                if ($d['costo_unitario'] === null) {
                    continue;
                }
                $points[] = [
                    'fecha' => $d['fecha_emision'],
                    'costo_unitario' => $d['costo_unitario'],
                    'folio' => $d['folio'],
                    'factura_id' => $d['factura_id'] ?? null,
                    'source_kind' => $d['source_kind'] ?? 'invoice',
                    'doc_label' => $d['doc_label'] ?? '',
                ];
                $all_dates[$d['fecha_emision']] = true;
            }

            $label = ($pair['proveedor_nombre'] ?? $this->get_proveedor_nombre($prov_id)) . ' / ' . $code;
            $series[] = [
                'proveedor_id' => $prov_id,
                'codigo_proveedor' => $code,
                'label' => $label,
                'points' => $points,
            ];
        }

        $labels = array_keys($all_dates);
        sort($labels);

        // Mapear cada serie a valores alineados con labels (null si no hay punto ese día)
        $datasets = [];
        foreach ($series as $s) {
            $by_date = [];
            foreach ($s['points'] as $p) {
                // Si hay varios el mismo día, quedarse con el último (más reciente en lista asc)
                $by_date[$p['fecha']] = $p;
            }
            $data = [];
            foreach ($labels as $lab) {
                $data[] = isset($by_date[$lab]) ? $by_date[$lab]['costo_unitario'] : null;
            }
            $datasets[] = [
                'proveedor_id' => $s['proveedor_id'],
                'codigo_proveedor' => $s['codigo_proveedor'],
                'label' => $s['label'],
                'data' => $data,
                'points' => $s['points'],
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Documento completo para el modal.
     *
     * @param int $factura_id
     * @return array|null
     */
    public function get_document($factura_id) {
        global $wpdb;

        $factura_id = (int) $factura_id;
        if ($factura_id <= 0) {
            return null;
        }

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.nombre AS proveedor_nombre, p.rut AS proveedor_rut
             FROM {$this->prefix}facturas f
             LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            $factura_id
        ), ARRAY_A);

        if (!$factura) {
            return null;
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}factura_items
             WHERE factura_id = %d
             ORDER BY numero_linea ASC",
            $factura_id
        ), ARRAY_A);

        foreach ($items as &$item) {
            $item['costo_unitario'] = self::unit_cost_from_item($item);
        }
        unset($item);

        return [
            'id' => (int) $factura['id'],
            'tipo_dte' => (int) $factura['tipo_dte'],
            'folio' => $factura['folio'],
            'proveedor_id' => (int) $factura['proveedor_id'],
            'proveedor_nombre' => $factura['proveedor_nombre'] ?: $factura['razon_social_emisor'],
            'rut_emisor' => $factura['rut_emisor'],
            'fecha_emision' => $factura['fecha_emision'],
            'monto_neto' => floatval($factura['monto_neto']),
            'monto_iva' => floatval($factura['monto_iva']),
            'monto_total' => floatval($factura['monto_total']),
            'estado' => $factura['estado'],
            'items' => $items,
        ];
    }

    /**
     * Payload completo al seleccionar un producto (o par suelto).
     *
     * @param array $args
     * @return array|WP_Error
     */
    public function build_explorer_payload($args) {
        $producto_base_id = !empty($args['producto_base_id']) ? (int) $args['producto_base_id'] : null;
        $proveedor_id = !empty($args['proveedor_id']) ? (int) $args['proveedor_id'] : null;
        $codigo_proveedor = !empty($args['codigo_proveedor']) ? sanitize_text_field($args['codigo_proveedor']) : null;
        $limit_per_pair = isset($args['limit_per_pair']) ? (int) $args['limit_per_pair'] : 3;
        $months = isset($args['months']) ? (int) $args['months'] : 24;
        $doc_type = $this->normalize_doc_type($args['doc_type'] ?? 'factura');

        $product = null;
        $pares = [];

        if ($producto_base_id) {
            $pb = $this->get_producto_base($producto_base_id);
            if (!$pb) {
                return new WP_Error('not_found', 'Producto no encontrado');
            }
            $product = [
                'producto_base_id' => (int) $pb['id'],
                'canonical_sku' => $pb['canonical_sku'],
                'nombre' => $pb['nombre_canonico'],
            ];
            $pares = $this->get_supplier_pairs($producto_base_id);
        } elseif ($proveedor_id && $codigo_proveedor) {
            $pares = [[
                'proveedor_id' => $proveedor_id,
                'codigo_proveedor' => $codigo_proveedor,
                'proveedor_nombre' => $this->get_proveedor_nombre($proveedor_id),
                'source' => 'loose',
            ]];
            $product = [
                'producto_base_id' => null,
                'canonical_sku' => null,
                'nombre' => $codigo_proveedor,
            ];
        } else {
            return new WP_Error('invalid', 'Se requiere producto_base_id o par proveedor/código');
        }

        if (empty($pares)) {
            return [
                'product' => $product,
                'pares' => [],
                'timeline' => [],
                'summary' => ['by_pair' => [], 'highlight' => null],
                'chart' => ['labels' => [], 'datasets' => []],
                'doc_type' => $doc_type,
            ];
        }

        return [
            'product' => $product,
            'pares' => $pares,
            'timeline' => $this->get_timeline($pares, [
                'limit_per_pair' => $limit_per_pair,
                'doc_type' => $doc_type,
            ]),
            'summary' => $this->get_pair_summary($pares, $doc_type),
            'chart' => $this->get_chart_series($pares, $months, $doc_type),
            'doc_type' => $doc_type,
        ];
    }

    /**
     * Busca facturas de productos por folio / proveedor / RUT.
     *
     * @param string $term
     * @param int    $limit
     * @return array
     */
    public function search_product_invoices($term, $limit = 15) {
        global $wpdb;

        $term = trim((string) $term);
        $limit = max(1, min(30, (int) $limit));
        if ($term === '') {
            return [];
        }

        $like = '%' . $wpdb->esc_like($term) . '%';
        $sql = "SELECT f.id, f.folio, f.tipo_dte, f.fecha_emision, f.created_at,
                       f.monto_total, f.estado, f.rut_emisor, f.proveedor_id,
                       COALESCE(f.documento_subtipo, 'productos') AS documento_subtipo,
                       COALESCE(p.nombre, f.razon_social_emisor) AS proveedor_nombre
                FROM {$this->prefix}facturas f
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE f.tipo_dte IN (33, 34)
                  AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
                  AND f.estado NOT IN ('rejected', 'archived')
                  AND (
                        CAST(f.folio AS CHAR) = %s
                     OR CAST(f.folio AS CHAR) LIKE %s
                     OR p.nombre LIKE %s
                     OR p.rut LIKE %s
                     OR f.rut_emisor LIKE %s
                     OR f.razon_social_emisor LIKE %s
                  )
                ORDER BY
                    CASE WHEN CAST(f.folio AS CHAR) = %s THEN 0
                         WHEN CAST(f.folio AS CHAR) LIKE %s THEN 1
                         ELSE 2 END,
                    f.fecha_emision DESC, f.id DESC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare(
            $sql,
            $term,
            $like,
            $like,
            $like,
            $like,
            $like,
            $term,
            $wpdb->esc_like($term) . '%',
            $limit
        ), ARRAY_A);

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'folio' => $row['folio'],
                'tipo_dte' => (int) $row['tipo_dte'],
                'fecha_emision' => $row['fecha_emision'],
                'created_at' => $row['created_at'],
                'monto_total' => floatval($row['monto_total']),
                'estado' => $row['estado'],
                'rut_emisor' => $row['rut_emisor'],
                'proveedor_id' => (int) $row['proveedor_id'],
                'proveedor_nombre' => $row['proveedor_nombre'],
                'documento_subtipo' => $row['documento_subtipo'],
            ];
        }
        return $out;
    }

    /**
     * Listado de facturas de productos recientes (grid inferior del análisis).
     *
     * @param array $args date_field (fecha_emision|created_at), date_from, date_to,
     *                    proveedor_id, search, orderby, order, limit, offset
     * @return array{items:array,total:int,pages:int}
     */
    public function list_recent_product_invoices($args = []) {
        global $wpdb;

        $date_field = ($args['date_field'] ?? 'fecha_emision') === 'created_at' ? 'created_at' : 'fecha_emision';
        $orderby = ($args['orderby'] ?? $date_field) === 'created_at' ? 'created_at' : 'fecha_emision';
        if (!in_array($orderby, ['fecha_emision', 'created_at', 'folio', 'monto_total'], true)) {
            $orderby = 'fecha_emision';
        }
        $order = strtoupper($args['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min(100, (int) ($args['limit'] ?? 25)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $proveedor_id = !empty($args['proveedor_id']) ? (int) $args['proveedor_id'] : 0;
        $search = trim((string) ($args['search'] ?? ''));
        $date_from = trim((string) ($args['date_from'] ?? ''));
        $date_to = trim((string) ($args['date_to'] ?? ''));

        $where = [
            'f.tipo_dte IN (33, 34)',
            "(f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')",
            "f.estado NOT IN ('rejected', 'archived')",
        ];
        $params = [];

        if ($proveedor_id > 0) {
            $where[] = 'f.proveedor_id = %d';
            $params[] = $proveedor_id;
        }

        if ($date_from !== '') {
            if ($date_field === 'created_at') {
                $where[] = 'DATE(f.created_at) >= %s';
            } else {
                $where[] = 'f.fecha_emision >= %s';
            }
            $params[] = $date_from;
        }

        if ($date_to !== '') {
            if ($date_field === 'created_at') {
                $where[] = 'DATE(f.created_at) <= %s';
            } else {
                $where[] = 'f.fecha_emision <= %s';
            }
            $params[] = $date_to;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(CAST(f.folio AS CHAR) LIKE %s OR p.nombre LIKE %s OR p.rut LIKE %s OR f.rut_emisor LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*)
                      FROM {$this->prefix}facturas f
                      LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                      WHERE {$where_sql}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $sql = "SELECT f.id, f.folio, f.tipo_dte, f.fecha_emision, f.created_at,
                       f.monto_neto, f.monto_total, f.estado, f.rut_emisor, f.proveedor_id,
                       COALESCE(f.documento_subtipo, 'productos') AS documento_subtipo,
                       COALESCE(p.nombre, f.razon_social_emisor) AS proveedor_nombre,
                       (SELECT COUNT(*) FROM {$this->prefix}factura_items fi
                        WHERE fi.factura_id = f.id
                          AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL OR fi.item_tipo = '')
                       ) AS items_count
                FROM {$this->prefix}facturas f
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE {$where_sql}
                ORDER BY f.{$orderby} {$order}, f.id DESC
                LIMIT %d OFFSET %d";

        $query_params = array_merge($params, [$limit, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        $items = [];
        foreach ($rows ?: [] as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'folio' => $row['folio'],
                'tipo_dte' => (int) $row['tipo_dte'],
                'fecha_emision' => $row['fecha_emision'],
                'created_at' => $row['created_at'],
                'monto_neto' => floatval($row['monto_neto']),
                'monto_total' => floatval($row['monto_total']),
                'estado' => $row['estado'],
                'rut_emisor' => $row['rut_emisor'],
                'proveedor_id' => (int) $row['proveedor_id'],
                'proveedor_nombre' => $row['proveedor_nombre'],
                'documento_subtipo' => $row['documento_subtipo'],
                'items_count' => (int) $row['items_count'],
                'origen' => $row['proveedor_nombre'] ?: ($row['rut_emisor'] ?: 'Sin proveedor'),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Listado de alzas de costo: líneas de factura de productos cuyo costo
     * subió respecto a la última factura anterior del mismo par.
     *
     * @param array $args date_from, date_to, min_pct, margin_threshold, limit
     * @return array{items:array,total:int,min_pct:float}
     */
    public function list_cost_increases($args = []) {
        global $wpdb;

        $date_to = trim((string) ($args['date_to'] ?? ''));
        $date_from = trim((string) ($args['date_from'] ?? ''));
        if ($date_to === '') {
            $date_to = current_time('Y-m-d');
        }
        if ($date_from === '') {
            $date_from = date('Y-m-d', strtotime($date_to . ' -30 days'));
        }
        $min_pct = max(0.0, floatval($args['min_pct'] ?? 0));
        $margin_threshold = floatval($args['margin_threshold'] ?? 1.5);
        if ($margin_threshold <= 0) {
            $margin_threshold = 1.5;
        }
        $limit = max(1, min(200, (int) ($args['limit'] ?? 100)));
        // Cargar más candidatos para filtrar en PHP tras comparar con factura anterior
        $fetch = min(500, max($limit * 5, 150));

        $sql = "SELECT fi.id AS item_id, fi.numero_linea, fi.nombre, fi.cantidad, fi.unidad,
                       fi.codigo_proveedor, fi.sku_local, fi.precio_unitario,
                       fi.costo_neto_final, fi.costo_landed_unitario,
                       f.id AS factura_id, f.tipo_dte, f.folio, f.fecha_emision, f.proveedor_id,
                       COALESCE(p.nombre, f.razon_social_emisor) AS proveedor_nombre
                FROM {$this->prefix}factura_items fi
                INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE fi.item_tipo = 'producto'
                  AND f.tipo_dte IN (33, 34)
                  AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
                  AND f.estado NOT IN ('rejected', 'archived')
                  AND f.fecha_emision >= %s
                  AND f.fecha_emision <= %s
                  AND fi.codigo_proveedor IS NOT NULL
                  AND fi.codigo_proveedor <> ''
                  AND f.proveedor_id IS NOT NULL
                  AND f.proveedor_id > 0
                ORDER BY f.fecha_emision DESC, f.id DESC, fi.numero_linea ASC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $date_from, $date_to, $fetch), ARRAY_A);

        $prev_cache = [];
        $price_cache = [];
        $items = [];

        foreach ($rows ?: [] as $row) {
            $current = self::unit_cost_from_item($row);
            if ($current === null) {
                continue;
            }

            $prov_id = (int) $row['proveedor_id'];
            $code = trim((string) $row['codigo_proveedor']);
            $fecha = $row['fecha_emision'];
            $factura_id = (int) $row['factura_id'];
            $cache_key = $prov_id . '|' . $code . '|' . $fecha . '|' . $factura_id;

            if (!array_key_exists($cache_key, $prev_cache)) {
                $prev_cache[$cache_key] = $this->get_last_invoice_before($prov_id, $code, $fecha, $factura_id);
            }
            $prev = $prev_cache[$cache_key];
            if (!$prev || empty($prev['costo_unitario']) || (float) $prev['costo_unitario'] <= 0) {
                continue;
            }

            $prev_cost = (float) $prev['costo_unitario'];
            $delta = $current - $prev_cost;
            if ($delta <= 0) {
                continue;
            }
            $delta_pct = round(($delta / $prev_cost) * 100, 2);
            if ($delta_pct <= $min_pct) {
                continue;
            }

            $sku = trim((string) ($row['sku_local'] ?? ''));
            $sale_price = null;
            $product_id = null;
            $price_key = $sku !== '' ? 'sku:' . $sku : 'code:' . $code;
            if (!array_key_exists($price_key, $price_cache)) {
                $price_cache[$price_key] = $this->lookup_woo_price_for_code($sku, $code);
            }
            $woo = $price_cache[$price_key];
            if ($woo) {
                $sale_price = $woo['price'];
                $product_id = $woo['product_id'];
            }

            $margin_pct = null;
            $margin_alert = false;
            if ($sale_price !== null && $sale_price > 0) {
                $margin_pct = round((($sale_price - $current) / $sale_price) * 100, 2);
                $margin_alert = $sale_price < ($current * $margin_threshold);
            }

            $items[] = [
                'item_id' => (int) $row['item_id'],
                'factura_id' => $factura_id,
                'tipo_dte' => (int) $row['tipo_dte'],
                'folio' => $row['folio'],
                'fecha_emision' => $fecha,
                'proveedor_id' => $prov_id,
                'proveedor_nombre' => $row['proveedor_nombre'],
                'codigo_proveedor' => $code,
                'nombre' => $row['nombre'],
                'sku_local' => $sku !== '' ? $sku : null,
                'cantidad' => floatval($row['cantidad']),
                'costo_anterior' => $prev_cost,
                'costo_actual' => $current,
                'delta' => round($delta, 4),
                'delta_pct' => $delta_pct,
                'prev_folio' => $prev['folio'],
                'prev_fecha' => $prev['fecha_emision'],
                'prev_factura_id' => (int) $prev['factura_id'],
                'sale_price' => $sale_price,
                'product_id' => $product_id,
                'margin_pct' => $margin_pct,
                'margin_alert' => $margin_alert,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        // Ordenar por mayor alza %
        usort($items, function ($a, $b) {
            return $b['delta_pct'] <=> $a['delta_pct'];
        });

        return [
            'items' => $items,
            'total' => count($items),
            'min_pct' => $min_pct,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'margin_threshold' => $margin_threshold,
        ];
    }

    /**
     * Precio Woo por SKU local o código proveedor (postmeta _sku).
     *
     * @param string $sku_local
     * @param string $codigo_proveedor
     * @return array{product_id:int,price:float}|null
     */
    private function lookup_woo_price_for_code($sku_local, $codigo_proveedor) {
        global $wpdb;

        $sku = trim((string) $sku_local);
        if ($sku === '') {
            $sku = trim((string) $codigo_proveedor);
        }
        if ($sku === '') {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.ID AS product_id, pm_price.meta_value AS price
             FROM {$wpdb->postmeta} pm_sku
             INNER JOIN {$wpdb->posts} p ON p.ID = pm_sku.post_id
             LEFT JOIN {$wpdb->postmeta} pm_price ON pm_price.post_id = p.ID AND pm_price.meta_key = '_price'
             WHERE pm_sku.meta_key = '_sku'
               AND pm_sku.meta_value = %s
               AND p.post_status IN ('publish', 'private', 'draft')
               AND p.post_type IN ('product', 'product_variation')
             ORDER BY FIELD(p.post_type, 'product_variation', 'product')
             LIMIT 1",
            $sku
        ), ARRAY_A);

        if (!$row || $row['price'] === null || $row['price'] === '') {
            return null;
        }

        return [
            'product_id' => (int) $row['product_id'],
            'price' => floatval($row['price']),
        ];
    }

    /**
     * Analiza una factura de productos: costo actual vs última factura anterior
     * y cotización aprobada (WIP).
     *
     * @param int $factura_id
     * @return array|WP_Error
     */
    public function analyze_invoice($factura_id) {
        global $wpdb;

        $factura_id = (int) $factura_id;
        if ($factura_id <= 0) {
            return new WP_Error('invalid', 'Factura no especificada');
        }

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.nombre AS proveedor_nombre, p.rut AS proveedor_rut
             FROM {$this->prefix}facturas f
             LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            $factura_id
        ), ARRAY_A);

        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $subtipo = $factura['documento_subtipo'] ?? 'productos';
        if ($subtipo !== '' && $subtipo !== null && $subtipo !== 'productos') {
            return new WP_Error('invalid_type', 'Solo se pueden analizar facturas de productos');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}factura_items
             WHERE factura_id = %d
               AND (item_tipo = 'producto' OR item_tipo IS NULL OR item_tipo = '')
             ORDER BY numero_linea ASC",
            $factura_id
        ), ARRAY_A);

        $proveedor_id = (int) ($factura['proveedor_id'] ?? 0);
        $fecha = $factura['fecha_emision'];
        $rows = [];

        foreach ($items ?: [] as $item) {
            $current = self::unit_cost_from_item($item);
            $code = trim((string) ($item['codigo_proveedor'] ?? ''));

            $prev_invoice = null;
            if ($proveedor_id > 0 && $code !== '' && $fecha) {
                $prev_invoice = $this->get_last_invoice_before($proveedor_id, $code, $fecha, $factura_id);
            }

            // WIP: cotizaciones aprobadas — stub hasta implementar la sección.
            $prev_quote = $this->get_last_approved_quote_before($proveedor_id, $code, $fecha);

            $picked = $this->pick_reference($current, $prev_invoice, $prev_quote);

            $rows[] = [
                'item_id' => (int) $item['id'],
                'numero_linea' => (int) $item['numero_linea'],
                'codigo_proveedor' => $code !== '' ? $code : null,
                'nombre' => $item['nombre'],
                'cantidad' => floatval($item['cantidad']),
                'unidad' => $item['unidad'],
                'costo_actual' => $current,
                'prev_invoice' => $prev_invoice,
                'prev_quote' => $prev_quote,
                'reference_source' => $picked['source'],
                'reference_cost' => $picked['previous'],
                'delta' => $picked['delta'],
                'delta_pct' => $picked['pct'],
                'trend' => $picked['trend'],
            ];
        }

        return [
            'invoice' => [
                'id' => (int) $factura['id'],
                'tipo_dte' => (int) $factura['tipo_dte'],
                'folio' => $factura['folio'],
                'proveedor_id' => $proveedor_id,
                'proveedor_nombre' => $factura['proveedor_nombre'] ?: $factura['razon_social_emisor'],
                'rut_emisor' => $factura['rut_emisor'],
                'fecha_emision' => $factura['fecha_emision'],
                'created_at' => $factura['created_at'],
                'monto_neto' => floatval($factura['monto_neto']),
                'monto_iva' => floatval($factura['monto_iva']),
                'monto_total' => floatval($factura['monto_total']),
                'estado' => $factura['estado'],
                'documento_subtipo' => $subtipo ?: 'productos',
            ],
            'rows' => $rows,
            'quote_wip' => true,
        ];
    }

    /**
     * Stub WIP: última cotización aprobada antes de $date para el par.
     * Cuando se implemente: cotizaciones_recibidas.estado='approved'
     * + cotizacion_items.codigo_proveedor / costo_neto, fecha_documento < $date.
     *
     * @param int    $proveedor_id
     * @param string $codigo_proveedor
     * @param string $date
     * @return array|null
     */
    public function get_last_approved_quote_before($proveedor_id, $codigo_proveedor, $date) {
        unset($proveedor_id, $codigo_proveedor, $date);
        return null;
    }

    /**
     * Última factura de productos anterior al documento, mismo par.
     *
     * @param int    $proveedor_id
     * @param string $codigo_proveedor
     * @param string $fecha_emision
     * @param int    $exclude_factura_id
     * @return array|null {costo_unitario, folio, fecha_emision, factura_id, tipo_dte}
     */
    private function get_last_invoice_before($proveedor_id, $codigo_proveedor, $fecha_emision, $exclude_factura_id) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT f.id AS factura_id, f.tipo_dte, f.folio, f.fecha_emision,
                    fi.cantidad, fi.costo_neto_final, fi.costo_landed_unitario, fi.precio_unitario
             FROM {$this->prefix}factura_items fi
             INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
             WHERE fi.item_tipo = 'producto'
               AND f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               AND f.tipo_dte IN (33, 34)
               AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
               AND f.id <> %d
               AND (
                    f.fecha_emision < %s
                    OR (f.fecha_emision = %s AND f.id < %d)
               )
             ORDER BY f.fecha_emision DESC, f.id DESC
             LIMIT 1",
            (int) $proveedor_id,
            $codigo_proveedor,
            (int) $exclude_factura_id,
            $fecha_emision,
            $fecha_emision,
            (int) $exclude_factura_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $cost = self::unit_cost_from_item($row);
        if ($cost === null) {
            return null;
        }

        return [
            'factura_id' => (int) $row['factura_id'],
            'tipo_dte' => (int) $row['tipo_dte'],
            'folio' => $row['folio'],
            'fecha_emision' => $row['fecha_emision'],
            'costo_unitario' => $cost,
        ];
    }

    /**
     * Elige la referencia con mayor delta (la que más subió).
     *
     * @param float|null $current
     * @param array|null $prev_invoice
     * @param array|null $prev_quote  {costo_unitario, ...} o null
     * @return array{source:?string,previous:?float,delta:?float,pct:?float,trend:?string}
     */
    private function pick_reference($current, $prev_invoice, $prev_quote) {
        $empty = [
            'source' => null,
            'previous' => null,
            'delta' => null,
            'pct' => null,
            'trend' => null,
        ];

        if ($current === null || !is_numeric($current)) {
            return $empty;
        }

        $candidates = [];
        if ($prev_invoice && isset($prev_invoice['costo_unitario']) && $prev_invoice['costo_unitario'] !== null) {
            $candidates[] = [
                'source' => 'invoice',
                'previous' => (float) $prev_invoice['costo_unitario'],
            ];
        }
        if ($prev_quote && isset($prev_quote['costo_unitario']) && $prev_quote['costo_unitario'] !== null) {
            $candidates[] = [
                'source' => 'quote',
                'previous' => (float) $prev_quote['costo_unitario'],
            ];
        }

        if (!$candidates) {
            return $empty;
        }

        $best = null;
        foreach ($candidates as $c) {
            $delta = (float) $current - $c['previous'];
            $c['delta'] = $delta;
            if ($best === null || $delta > $best['delta']) {
                $best = $c;
            }
        }

        $delta = $best['delta'];
        $prev = $best['previous'];
        $pct = ($prev != 0.0) ? round(($delta / $prev) * 100, 2) : null;

        $trend = 'se_mantuvo';
        if (abs($delta) >= 0.001) {
            $trend = $delta > 0 ? 'subio' : 'bajo';
        }

        return [
            'source' => $best['source'],
            'previous' => $prev,
            'delta' => round($delta, 4),
            'pct' => $pct,
            'trend' => $trend,
        ];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function get_producto_base($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$this->prefix}producto_base WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    private function get_proveedor_nombre($id) {
        global $wpdb;
        $nombre = $wpdb->get_var($wpdb->prepare(
            "SELECT nombre FROM {$this->prefix}proveedores WHERE id = %d",
            (int) $id
        ));
        return $nombre ?: ('Proveedor #' . (int) $id);
    }

    /**
     * Cuenta documentos de un par según tipo.
     *
     * @param int    $proveedor_id
     * @param string $codigo_proveedor
     * @param string $doc_type
     * @return int
     */
    private function count_pair_documents($proveedor_id, $codigo_proveedor, $doc_type = 'factura') {
        $doc_type = $this->normalize_doc_type($doc_type);
        $count = 0;
        if ($doc_type !== 'cotizaciones') {
            $count += $this->count_pair_invoices($proveedor_id, $codigo_proveedor, $doc_type === 'todos' ? 'todos_xml' : $doc_type);
        }
        if ($doc_type === 'cotizaciones' || $doc_type === 'todos') {
            $count += $this->count_pair_quotes($proveedor_id, $codigo_proveedor);
        }
        return $count;
    }

    /**
     * Consulta documentos de un par (facturas/guías/cotizaciones), más recientes primero.
     *
     * @param int         $proveedor_id
     * @param string      $codigo_proveedor
     * @param int         $limit
     * @param string|null $date_from
     * @param string      $doc_type
     * @return array
     */
    private function query_pair_documents($proveedor_id, $codigo_proveedor, $limit = 3, $date_from = null, $doc_type = 'factura') {
        $doc_type = $this->normalize_doc_type($doc_type);
        $limit = max(1, min(500, (int) $limit));
        $docs = [];

        if ($doc_type !== 'cotizaciones') {
            $invoice_filter = $doc_type === 'todos' ? 'todos_xml' : $doc_type;
            $docs = array_merge($docs, $this->query_pair_invoices($proveedor_id, $codigo_proveedor, 500, $date_from, $invoice_filter));
        }
        if ($doc_type === 'cotizaciones' || $doc_type === 'todos') {
            $docs = array_merge($docs, $this->query_pair_quotes($proveedor_id, $codigo_proveedor, 500, $date_from));
        }

        usort($docs, function ($a, $b) {
            $fa = (string) ($a['fecha_emision'] ?? '');
            $fb = (string) ($b['fecha_emision'] ?? '');
            if ($fa === $fb) {
                $ida = (int) ($a['factura_id'] ?? $a['cotizacion_id'] ?? 0);
                $idb = (int) ($b['factura_id'] ?? $b['cotizacion_id'] ?? 0);
                return $idb <=> $ida;
            }
            return strcmp($fb, $fa);
        });

        return array_slice($docs, 0, $limit);
    }

    private function normalize_doc_type($doc_type) {
        $doc_type = strtolower(trim((string) $doc_type));
        $allowed = ['factura', 'guias', 'cotizaciones', 'todos'];
        return in_array($doc_type, $allowed, true) ? $doc_type : 'factura';
    }

    private function invoice_doc_type_sql($doc_type) {
        switch ($doc_type) {
            case 'factura':
                return " AND f.tipo_dte IN (33, 34)
                         AND (f.documento_subtipo IS NULL OR f.documento_subtipo IN ('', 'productos')) ";
            case 'guias':
                return " AND (f.tipo_dte = 52 OR f.documento_subtipo = 'guia_despacho') ";
            case 'todos_xml':
                // Facturas + guías y otros XML de productos (excluye envíos/gastos puros si están tipados)
                return " AND (f.documento_subtipo IS NULL OR f.documento_subtipo IN ('', 'productos', 'guia_despacho'))
                         AND (f.tipo_dte IN (33, 34, 52) OR f.documento_subtipo = 'guia_despacho') ";
            default:
                return " AND f.tipo_dte IN (33, 34)
                         AND (f.documento_subtipo IS NULL OR f.documento_subtipo IN ('', 'productos')) ";
        }
    }

    /**
     * Cuenta facturas/líneas de un par.
     *
     * @param int    $proveedor_id
     * @param string $codigo_proveedor
     * @param string $doc_type factura|guias|todos_xml
     * @return int
     */
    private function count_pair_invoices($proveedor_id, $codigo_proveedor, $doc_type = 'factura') {
        global $wpdb;
        $type_sql = $this->invoice_doc_type_sql($doc_type);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->prefix}factura_items fi
             INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
             WHERE fi.item_tipo = 'producto'
               AND f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               {$type_sql}",
            (int) $proveedor_id,
            $codigo_proveedor
        ));
    }

    private function count_pair_quotes($proveedor_id, $codigo_proveedor) {
        global $wpdb;
        $table = $this->prefix . 'cotizaciones_recibidas';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->prefix}cotizacion_items ci
             INNER JOIN {$this->prefix}cotizaciones_recibidas c ON c.id = ci.cotizacion_id
             WHERE c.proveedor_id = %d
               AND ci.codigo_proveedor = %s
               AND c.estado NOT IN ('rejected', 'archived')",
            (int) $proveedor_id,
            $codigo_proveedor
        ));
    }

    /**
     * Consulta facturas de un par, más recientes primero.
     *
     * @param int         $proveedor_id
     * @param string      $codigo_proveedor
     * @param int         $limit
     * @param string|null $date_from
     * @param string      $doc_type
     * @return array
     */
    private function query_pair_invoices($proveedor_id, $codigo_proveedor, $limit = 3, $date_from = null, $doc_type = 'factura') {
        global $wpdb;

        $type_sql = $this->invoice_doc_type_sql($doc_type);
        $sql = "SELECT f.id AS factura_id, f.tipo_dte, f.folio, f.fecha_emision, f.estado,
                       f.documento_subtipo, f.proveedor_id, p.nombre AS proveedor_nombre,
                       fi.id AS item_id, fi.numero_linea, fi.nombre, fi.cantidad, fi.unidad,
                       fi.precio_unitario, fi.costo_neto_final, fi.costo_landed_unitario,
                       fi.codigo_proveedor, fi.sku_local, fi.monto_total
                FROM {$this->prefix}factura_items fi
                INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE fi.item_tipo = 'producto'
                  AND f.proveedor_id = %d
                  AND fi.codigo_proveedor = %s
                  {$type_sql}";
        $params = [(int) $proveedor_id, $codigo_proveedor];

        if ($date_from) {
            $sql .= ' AND f.fecha_emision >= %s';
            $params[] = $date_from;
        }

        $sql .= ' ORDER BY f.fecha_emision DESC, f.id DESC, fi.numero_linea ASC LIMIT %d';
        $params[] = (int) $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $tipo = (int) $row['tipo_dte'];
            $doc_label = $tipo === 52 || ($row['documento_subtipo'] ?? '') === 'guia_despacho'
                ? 'Guía'
                : ($tipo === 34 ? 'Exenta' : ($tipo === 33 ? 'Factura' : ('DTE ' . $tipo)));
            $out[] = [
                'source_kind' => 'invoice',
                'factura_id' => (int) $row['factura_id'],
                'cotizacion_id' => null,
                'tipo_dte' => $tipo,
                'folio' => $row['folio'],
                'fecha_emision' => $row['fecha_emision'],
                'estado' => $row['estado'],
                'documento_subtipo' => $row['documento_subtipo'],
                'doc_label' => $doc_label,
                'proveedor_id' => (int) $row['proveedor_id'],
                'proveedor_nombre' => $row['proveedor_nombre'],
                'item_id' => (int) $row['item_id'],
                'numero_linea' => (int) $row['numero_linea'],
                'nombre' => $row['nombre'],
                'cantidad' => floatval($row['cantidad']),
                'unidad' => $row['unidad'],
                'codigo_proveedor' => $row['codigo_proveedor'],
                'sku_local' => $row['sku_local'],
                'costo_unitario' => self::unit_cost_from_item($row),
                'monto_total' => floatval($row['monto_total']),
            ];
        }
        return $out;
    }

    private function query_pair_quotes($proveedor_id, $codigo_proveedor, $limit = 3, $date_from = null) {
        global $wpdb;
        $table = $this->prefix . 'cotizaciones_recibidas';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $sql = "SELECT c.id AS cotizacion_id, c.numero_documento AS folio, c.fecha_documento AS fecha_emision,
                       c.estado, c.proveedor_id, p.nombre AS proveedor_nombre,
                       ci.id AS item_id, ci.linea AS numero_linea, ci.descripcion AS nombre,
                       ci.cantidad, ci.unidad, ci.costo_neto, ci.codigo_proveedor, ci.costo_total
                FROM {$this->prefix}cotizacion_items ci
                INNER JOIN {$this->prefix}cotizaciones_recibidas c ON c.id = ci.cotizacion_id
                LEFT JOIN {$this->prefix}proveedores p ON p.id = c.proveedor_id
                WHERE c.proveedor_id = %d
                  AND ci.codigo_proveedor = %s
                  AND c.estado NOT IN ('rejected', 'archived')";
        $params = [(int) $proveedor_id, $codigo_proveedor];

        if ($date_from) {
            $sql .= ' AND c.fecha_documento >= %s';
            $params[] = $date_from;
        }

        $sql .= ' ORDER BY c.fecha_documento DESC, c.id DESC, ci.linea ASC LIMIT %d';
        $params[] = (int) $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = [
                'source_kind' => 'quote',
                'factura_id' => null,
                'cotizacion_id' => (int) $row['cotizacion_id'],
                'tipo_dte' => null,
                'folio' => $row['folio'] ?: ('COT-' . $row['cotizacion_id']),
                'fecha_emision' => $row['fecha_emision'],
                'estado' => $row['estado'],
                'documento_subtipo' => 'cotizacion',
                'doc_label' => 'Cotización',
                'proveedor_id' => (int) $row['proveedor_id'],
                'proveedor_nombre' => $row['proveedor_nombre'],
                'item_id' => (int) $row['item_id'],
                'numero_linea' => (int) $row['numero_linea'],
                'nombre' => $row['nombre'],
                'cantidad' => floatval($row['cantidad']),
                'unidad' => $row['unidad'],
                'codigo_proveedor' => $row['codigo_proveedor'],
                'sku_local' => null,
                'costo_unitario' => $row['costo_neto'] !== null ? floatval($row['costo_neto']) : null,
                'monto_total' => floatval($row['costo_total']),
            ];
        }
        return $out;
    }
}
