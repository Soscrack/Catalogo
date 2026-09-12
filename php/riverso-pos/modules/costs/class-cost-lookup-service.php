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
     * Costo unitario bruto desde una fila de factura_items.
     * Escala el landed neto con el factor bruto_final/neto_final (IVA + esp.),
     * o ×1,19 si no hay breakdown. DTE 34 (exenta) → bruto = neto.
     *
     * @param object|array $row
     * @return float|null
     */
    public static function unit_cost_bruto_from_item($row) {
        $row = (array) $row;
        $neto_unit = self::unit_cost_from_item($row);
        if ($neto_unit === null) {
            return null;
        }
        return self::neto_unit_to_bruto(
            $neto_unit,
            isset($row['tipo_dte']) ? (int) $row['tipo_dte'] : null,
            isset($row['costo_neto_final']) ? floatval($row['costo_neto_final']) : 0,
            isset($row['costo_bruto_final']) ? floatval($row['costo_bruto_final']) : 0
        );
    }

    /**
     * Convierte un costo unitario neto a bruto.
     *
     * @param float      $neto_unit
     * @param int|null   $tipo_dte
     * @param float      $neto_final  Total línea neto (opcional, para factor real)
     * @param float      $bruto_final Total línea bruto (opcional)
     * @return float
     */
    public static function neto_unit_to_bruto($neto_unit, $tipo_dte = null, $neto_final = 0, $bruto_final = 0) {
        $neto_unit = (float) $neto_unit;
        if ((int) $tipo_dte === 34) {
            return round($neto_unit, 4);
        }
        $neto_final = (float) $neto_final;
        $bruto_final = (float) $bruto_final;
        if ($neto_final > 0 && $bruto_final > 0) {
            return round($neto_unit * ($bruto_final / $neto_final), 4);
        }
        return round($neto_unit * 1.19, 4);
    }

    /**
     * Empaqueta neto + bruto unitarios para un documento/ítem.
     *
     * @param float|null $neto
     * @param float|null $bruto
     * @return array{costo_unitario:?float,costo_unitario_neto:?float,costo_unitario_bruto:?float}
     */
    public static function pack_unit_costs($neto, $bruto = null) {
        $neto = $neto !== null ? (float) $neto : null;
        if ($bruto === null && $neto !== null) {
            $bruto = self::neto_unit_to_bruto($neto);
        } elseif ($bruto !== null) {
            $bruto = (float) $bruto;
        }
        return [
            'costo_unitario' => $neto,
            'costo_unitario_neto' => $neto,
            'costo_unitario_bruto' => $bruto,
        ];
    }

    /**
     * Dos bases de costo unitario (sin landed): referencia (antes D/R) y tras D/R.
     * Cada base incluye neto + bruto.
     *
     * @param object|array $row
     * @return array{
     *   referencia:?array{neto:?float,bruto:?float},
     *   tras_dr:?array{neto:?float,bruto:?float}
     * }
     */
    public static function unit_cost_bases_packed($row) {
        $row = (array) $row;
        $qty = isset($row['cantidad']) ? floatval($row['cantidad']) : 0;
        if ($qty <= 0) {
            $qty = 1;
        }
        $tipo_dte = isset($row['tipo_dte']) ? (int) $row['tipo_dte'] : null;
        $precio = isset($row['precio_unitario']) && $row['precio_unitario'] !== null && $row['precio_unitario'] !== ''
            ? floatval($row['precio_unitario'])
            : null;

        $ref_neto = null;
        if (isset($row['costo_neto_base']) && $row['costo_neto_base'] !== null && $row['costo_neto_base'] !== ''
            && floatval($row['costo_neto_base']) > 0) {
            $ref_neto = round(floatval($row['costo_neto_base']) / $qty, 4);
        } elseif ($precio !== null && $precio > 0) {
            $ref_neto = round($precio, 4);
        }

        $ref_bruto = null;
        if ($ref_neto !== null) {
            if (isset($row['costo_bruto_base']) && $row['costo_bruto_base'] !== null && $row['costo_bruto_base'] !== ''
                && floatval($row['costo_bruto_base']) > 0) {
                $ref_bruto = round(floatval($row['costo_bruto_base']) / $qty, 4);
            } else {
                $ref_bruto = self::neto_unit_to_bruto(
                    $ref_neto,
                    $tipo_dte,
                    isset($row['costo_neto_base']) ? floatval($row['costo_neto_base']) : 0,
                    isset($row['costo_bruto_base']) ? floatval($row['costo_bruto_base']) : 0
                );
            }
        }

        $dr_neto = null;
        if (isset($row['costo_neto_final']) && $row['costo_neto_final'] !== null && $row['costo_neto_final'] !== ''
            && floatval($row['costo_neto_final']) > 0) {
            $dr_neto = round(floatval($row['costo_neto_final']) / $qty, 4);
        } elseif ($precio !== null && $precio > 0) {
            $dr_neto = round($precio, 4);
        } elseif ($ref_neto !== null) {
            $dr_neto = $ref_neto;
        }

        $dr_bruto = null;
        if ($dr_neto !== null) {
            if (isset($row['costo_bruto_final']) && $row['costo_bruto_final'] !== null && $row['costo_bruto_final'] !== ''
                && floatval($row['costo_bruto_final']) > 0) {
                $dr_bruto = round(floatval($row['costo_bruto_final']) / $qty, 4);
            } else {
                $dr_bruto = self::neto_unit_to_bruto(
                    $dr_neto,
                    $tipo_dte,
                    isset($row['costo_neto_final']) ? floatval($row['costo_neto_final']) : 0,
                    isset($row['costo_bruto_final']) ? floatval($row['costo_bruto_final']) : 0
                );
            }
        }

        return [
            'referencia' => $ref_neto !== null
                ? ['neto' => $ref_neto, 'bruto' => $ref_bruto]
                : null,
            'tras_dr' => $dr_neto !== null
                ? ['neto' => $dr_neto, 'bruto' => $dr_bruto]
                : null,
        ];
    }

    /**
     * Empaqueta bases idénticas (p.ej. cotizaciones sin desglose D/R).
     *
     * @param float|null $neto
     * @param float|null $bruto
     * @return array
     */
    public static function pack_same_bases($neto, $bruto = null) {
        $packed = self::pack_unit_costs($neto, $bruto);
        if ($packed['costo_unitario_neto'] === null) {
            return ['referencia' => null, 'tras_dr' => null];
        }
        $pair = [
            'neto' => $packed['costo_unitario_neto'],
            'bruto' => $packed['costo_unitario_bruto'],
        ];
        return [
            'referencia' => $pair,
            'tras_dr' => $pair,
        ];
    }

    /**
     * Bases de costo de un ítem de cotización recibida.
     * Referencia = precio lista; tras D/R = costo_neto.
     *
     * @param object|array $item
     * @return array{referencia:?array{neto:?float,bruto:?float},tras_dr:?array{neto:?float,bruto:?float}}
     */
    public static function quote_item_cost_bases($item) {
        $item = (array) $item;
        $neto = isset($item['costo_neto']) && $item['costo_neto'] !== null && $item['costo_neto'] !== ''
            ? (float) $item['costo_neto']
            : null;
        $lista = isset($item['precio_lista']) && $item['precio_lista'] !== null && $item['precio_lista'] !== ''
            && (float) $item['precio_lista'] > 0
            ? (float) $item['precio_lista']
            : null;
        $impuesto = isset($item['costo_impuesto']) ? (float) $item['costo_impuesto'] : 0.0;
        $total = isset($item['costo_total']) && $item['costo_total'] !== null && $item['costo_total'] !== ''
            && (float) $item['costo_total'] > 0
            ? (float) $item['costo_total']
            : null;

        $ref_neto = $lista !== null ? $lista : $neto;
        $dr_neto = $neto;
        $ref_bruto = $ref_neto !== null ? self::neto_unit_to_bruto($ref_neto) : null;
        $dr_bruto = null;
        if ($dr_neto !== null) {
            if ($total !== null) {
                $dr_bruto = $total;
            } elseif ($impuesto > 0) {
                $dr_bruto = round($dr_neto + $impuesto, 4);
            } else {
                $dr_bruto = self::neto_unit_to_bruto($dr_neto);
            }
        }

        return [
            'referencia' => $ref_neto !== null
                ? ['neto' => $ref_neto, 'bruto' => $ref_bruto]
                : null,
            'tras_dr' => $dr_neto !== null
                ? ['neto' => $dr_neto, 'bruto' => $dr_bruto]
                : null,
        ];
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
     * Último costo de factura (referencia / tras D/R) para un producto_base.
     * Elige el documento más reciente entre todos los pares proveedor+código.
     *
     * @param int $producto_base_id
     * @return array{
     *   costo_bases:?array,
     *   folio:?string,
     *   fecha_emision:?string,
     *   factura_id:?int,
     *   proveedor_id:?int,
     *   codigo_proveedor:?string,
     *   proveedor_nombre:?string
     * }
     */
    public function latest_cost_bases_for_product($producto_base_id) {
        $empty = [
            'costo_bases' => null,
            'folio' => null,
            'fecha_emision' => null,
            'factura_id' => null,
            'proveedor_id' => null,
            'codigo_proveedor' => null,
            'proveedor_nombre' => null,
        ];
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return $empty;
        }

        $pares = $this->get_supplier_pairs($producto_base_id);
        if (!$pares) {
            return $empty;
        }

        $best = null;
        foreach ($pares as $pair) {
            $prov_id = (int) ($pair['proveedor_id'] ?? 0);
            $code = trim((string) ($pair['codigo_proveedor'] ?? ''));
            if ($prov_id <= 0 || $code === '') {
                continue;
            }
            $docs = $this->query_pair_documents($prov_id, $code, 1, null, 'factura');
            $doc = $docs[0] ?? null;
            if (!$doc || empty($doc['costo_bases'])) {
                continue;
            }
            if ($best === null) {
                $best = $doc;
                $best['_pair'] = $pair;
                continue;
            }
            $fa = (string) ($doc['fecha_emision'] ?? '');
            $fb = (string) ($best['fecha_emision'] ?? '');
            if ($fa > $fb
                || ($fa === $fb
                    && (int) ($doc['factura_id'] ?? 0) > (int) ($best['factura_id'] ?? 0))
            ) {
                $best = $doc;
                $best['_pair'] = $pair;
            }
        }

        if (!$best) {
            return $empty;
        }

        $pair = $best['_pair'] ?? [];
        return [
            'costo_bases' => $best['costo_bases'],
            'folio' => isset($best['folio']) ? (string) $best['folio'] : null,
            'fecha_emision' => isset($best['fecha_emision']) ? (string) $best['fecha_emision'] : null,
            'factura_id' => !empty($best['factura_id']) ? (int) $best['factura_id'] : null,
            'proveedor_id' => !empty($pair['proveedor_id']) ? (int) $pair['proveedor_id'] : null,
            'codigo_proveedor' => isset($pair['codigo_proveedor']) ? (string) $pair['codigo_proveedor'] : null,
            'proveedor_nombre' => isset($pair['proveedor_nombre']) ? (string) $pair['proveedor_nombre'] : null,
        ];
    }

    /**
     * Fallback: empaqueta c_ref persistido como ambas bases (neto+bruto).
     * Útil cuando no hay factura con desglose D/R.
     *
     * @param float|null $neto
     * @param float|null $bruto
     * @return array|null
     */
    public static function bases_from_c_ref($neto, $bruto = null) {
        if ($neto === null && $bruto === null) {
            return null;
        }
        if ($neto === null && $bruto !== null) {
            $neto = round((float) $bruto / 1.19, 4);
        }
        if ($bruto === null && $neto !== null) {
            $bruto = self::neto_unit_to_bruto((float) $neto);
        }
        return self::pack_same_bases($neto, $bruto);
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
            $costs_neto = [];
            $costs_bruto = [];
            $costs_ref_neto = [];
            $costs_ref_bruto = [];
            $costs_dr_neto = [];
            $costs_dr_bruto = [];
            foreach ($docs as $d) {
                if ($d['costo_unitario_neto'] !== null) {
                    $costs_neto[] = (float) $d['costo_unitario_neto'];
                }
                if ($d['costo_unitario_bruto'] !== null) {
                    $costs_bruto[] = (float) $d['costo_unitario_bruto'];
                }
                $bases = $d['costo_bases'] ?? null;
                if (!empty($bases['referencia']['neto'])) {
                    $costs_ref_neto[] = (float) $bases['referencia']['neto'];
                }
                if (!empty($bases['referencia']['bruto'])) {
                    $costs_ref_bruto[] = (float) $bases['referencia']['bruto'];
                }
                if (!empty($bases['tras_dr']['neto'])) {
                    $costs_dr_neto[] = (float) $bases['tras_dr']['neto'];
                }
                if (!empty($bases['tras_dr']['bruto'])) {
                    $costs_dr_bruto[] = (float) $bases['tras_dr']['bruto'];
                }
            }

            $latest = $docs[0] ?? null;
            $previous = $docs[1] ?? null;
            $variation = null;
            if ($latest && $previous && $previous['costo_unitario_neto'] > 0 && $latest['costo_unitario_neto'] !== null) {
                $variation = round(
                    (($latest['costo_unitario_neto'] - $previous['costo_unitario_neto']) / $previous['costo_unitario_neto']) * 100,
                    2
                );
            }

            $ultimo_neto = $latest['costo_unitario_neto'] ?? null;
            $ultimo_bruto = $latest['costo_unitario_bruto'] ?? null;
            $prev_neto = $previous['costo_unitario_neto'] ?? null;
            $prev_bruto = $previous['costo_unitario_bruto'] ?? null;
            $ultimo_bases = $latest['costo_bases'] ?? null;
            $prev_bases = $previous['costo_bases'] ?? null;

            $summary = [
                'proveedor_id' => $prov_id,
                'codigo_proveedor' => $code,
                'proveedor_nombre' => $pair['proveedor_nombre'] ?? $this->get_proveedor_nombre($prov_id),
                'ultimo_costo' => $ultimo_neto,
                'ultimo_costo_neto' => $ultimo_neto,
                'ultimo_costo_bruto' => $ultimo_bruto,
                'ultimo_costo_bases' => $ultimo_bases,
                'ultimo_fecha' => $latest ? $latest['fecha_emision'] : null,
                'ultimo_folio' => $latest ? $latest['folio'] : null,
                'ultimo_factura_id' => $latest ? ($latest['factura_id'] ?? null) : null,
                'ultimo_item_id' => $latest ? $latest['item_id'] : null,
                'ultimo_source_kind' => $latest ? ($latest['source_kind'] ?? 'invoice') : null,
                'costo_previo' => $prev_neto,
                'costo_previo_neto' => $prev_neto,
                'costo_previo_bruto' => $prev_bruto,
                'costo_previo_bases' => $prev_bases,
                'variacion_pct' => $variation,
                'min_costo' => $costs_neto ? min($costs_neto) : null,
                'max_costo' => $costs_neto ? max($costs_neto) : null,
                'avg_costo' => $costs_neto ? round(array_sum($costs_neto) / count($costs_neto), 2) : null,
                'min_costo_neto' => $costs_neto ? min($costs_neto) : null,
                'max_costo_neto' => $costs_neto ? max($costs_neto) : null,
                'min_costo_bruto' => $costs_bruto ? min($costs_bruto) : null,
                'max_costo_bruto' => $costs_bruto ? max($costs_bruto) : null,
                'min_costo_bases' => [
                    'referencia' => [
                        'neto' => $costs_ref_neto ? min($costs_ref_neto) : null,
                        'bruto' => $costs_ref_bruto ? min($costs_ref_bruto) : null,
                    ],
                    'tras_dr' => [
                        'neto' => $costs_dr_neto ? min($costs_dr_neto) : null,
                        'bruto' => $costs_dr_bruto ? min($costs_dr_bruto) : null,
                    ],
                ],
                'max_costo_bases' => [
                    'referencia' => [
                        'neto' => $costs_ref_neto ? max($costs_ref_neto) : null,
                        'bruto' => $costs_ref_bruto ? max($costs_ref_bruto) : null,
                    ],
                    'tras_dr' => [
                        'neto' => $costs_dr_neto ? max($costs_dr_neto) : null,
                        'bruto' => $costs_dr_bruto ? max($costs_dr_bruto) : null,
                    ],
                ],
                'total_documentos' => count($docs),
                'doc_type' => $doc_type,
            ];
            $summaries[] = $summary;

            if ($latest && $ultimo_neto !== null) {
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
                if ($d['costo_unitario_neto'] === null) {
                    continue;
                }
                $bases = $d['costo_bases'] ?? null;
                $points[] = [
                    'fecha' => $d['fecha_emision'],
                    'costo_unitario' => $d['costo_unitario_neto'],
                    'costo_unitario_neto' => $d['costo_unitario_neto'],
                    'costo_unitario_bruto' => $d['costo_unitario_bruto'],
                    'costo_bases' => $bases,
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
            $data_neto = [];
            $data_bruto = [];
            $data_ref_neto = [];
            $data_ref_bruto = [];
            $data_dr_neto = [];
            $data_dr_bruto = [];
            foreach ($labels as $lab) {
                $p = $by_date[$lab] ?? null;
                $data_neto[] = $p ? $p['costo_unitario_neto'] : null;
                $data_bruto[] = $p ? $p['costo_unitario_bruto'] : null;
                $bases = $p['costo_bases'] ?? null;
                $data_ref_neto[] = $bases['referencia']['neto'] ?? null;
                $data_ref_bruto[] = $bases['referencia']['bruto'] ?? null;
                $data_dr_neto[] = $bases['tras_dr']['neto'] ?? ($p ? $p['costo_unitario_neto'] : null);
                $data_dr_bruto[] = $bases['tras_dr']['bruto'] ?? ($p ? $p['costo_unitario_bruto'] : null);
            }
            $datasets[] = [
                'proveedor_id' => $s['proveedor_id'],
                'codigo_proveedor' => $s['codigo_proveedor'],
                'label' => $s['label'],
                'data' => $data_neto,
                'data_neto' => $data_neto,
                'data_bruto' => $data_bruto,
                'data_referencia_neto' => $data_ref_neto,
                'data_referencia_bruto' => $data_ref_bruto,
                'data_tras_dr_neto' => $data_dr_neto,
                'data_tras_dr_bruto' => $data_dr_bruto,
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
            $item['tipo_dte'] = (int) $factura['tipo_dte'];
            $bases = self::unit_cost_bases_packed($item);
            $dr = $bases['tras_dr'];
            $packed = self::pack_unit_costs(
                $dr['neto'] ?? self::unit_cost_from_item($item),
                $dr['bruto'] ?? self::unit_cost_bruto_from_item($item)
            );
            $item['costo_unitario'] = $packed['costo_unitario'];
            $item['costo_unitario_neto'] = $packed['costo_unitario_neto'];
            $item['costo_unitario_bruto'] = $packed['costo_unitario_bruto'];
            $item['costo_bases'] = $bases;
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
     * Referencia de costo del catálogo TPV legacy (legacy_precio_ref).
     *
     * @param string $sku
     * @return array|null
     */
    private function get_legacy_cost_for_sku($sku) {
        global $wpdb;

        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT sku, nombre, costo_neto, precio_neto, precio_total, fuente, importado_at
             FROM {$this->prefix}legacy_precio_ref
             WHERE sku = %s
             ORDER BY importado_at DESC
             LIMIT 1",
            $sku
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $costo = $row['costo_neto'] !== null ? (float) $row['costo_neto'] : null;
        if ($costo === null || $costo <= 0) {
            return null;
        }

        return [
            'sku' => $row['sku'],
            'nombre' => $row['nombre'],
            'costo_neto' => $costo,
            'costo_sin_dato' => false,
            'precio_neto' => $row['precio_neto'] !== null ? (float) $row['precio_neto'] : null,
            'precio_total' => $row['precio_total'] !== null ? (float) $row['precio_total'] : null,
            'fuente' => $row['fuente'],
            'importado_at' => $row['importado_at'] ?: null,
            'referencia' => true,
        ];
    }

    /**
     * Highlight de fallback desde legacy cuando no hay costo de factura/cotización.
     *
     * @param array|null $product
     * @return array{highlight:array|null,legacy_ref:array|null}
     */
    private function legacy_highlight_fallback($product) {
        $sku = !empty($product['canonical_sku']) ? (string) $product['canonical_sku'] : '';
        $legacy = $this->get_legacy_cost_for_sku($sku);
        if (!$legacy) {
            return ['highlight' => null, 'legacy_ref' => null];
        }

        $neto = (float) $legacy['costo_neto'];
        $bruto = self::neto_unit_to_bruto($neto);
        return [
            'legacy_ref' => $legacy,
            'highlight' => [
                'ultimo_costo' => $neto,
                'ultimo_costo_neto' => $neto,
                'ultimo_costo_bruto' => $bruto,
                'ultimo_fecha' => $legacy['importado_at'] ?? null,
                'ultimo_folio' => null,
                'proveedor_nombre' => 'Catálogo TPV legacy',
                'codigo_proveedor' => $sku,
                'ultimo_source_kind' => 'legacy',
                'source' => 'legacy',
                'variacion_pct' => null,
            ],
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
            $legacy_fb = $this->legacy_highlight_fallback($product);
            return [
                'product' => $product,
                'pares' => [],
                'timeline' => [],
                'summary' => [
                    'by_pair' => [],
                    'highlight' => $legacy_fb['highlight'],
                ],
                'chart' => ['labels' => [], 'datasets' => []],
                'doc_type' => $doc_type,
                'legacy_ref' => $legacy_fb['legacy_ref'],
            ];
        }

        $summary = $this->get_pair_summary($pares, $doc_type);
        $legacy_ref = null;
        $highlight = $summary['highlight'] ?? null;
        if (!$highlight || $highlight['ultimo_costo'] === null) {
            $legacy_fb = $this->legacy_highlight_fallback($product);
            $legacy_ref = $legacy_fb['legacy_ref'];
            if ($legacy_fb['highlight']) {
                $summary['highlight'] = $legacy_fb['highlight'];
            }
        }

        return [
            'product' => $product,
            'pares' => $pares,
            'timeline' => $this->get_timeline($pares, [
                'limit_per_pair' => $limit_per_pair,
                'doc_type' => $doc_type,
            ]),
            'summary' => $summary,
            'chart' => $this->get_chart_series($pares, $months, $doc_type),
            'doc_type' => $doc_type,
            'legacy_ref' => $legacy_ref,
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
     * @param int    $factura_id
     * @param string $compare_base auto|invoice|quote
     * @return array|WP_Error
     */
    public function analyze_invoice($factura_id, $compare_base = 'auto') {
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
            $item['tipo_dte'] = (int) ($factura['tipo_dte'] ?? 0);
            $bases = self::unit_cost_bases_packed($item);
            $dr = $bases['tras_dr'];
            $current = $dr['neto'] ?? self::unit_cost_from_item($item);
            $code = trim((string) ($item['codigo_proveedor'] ?? ''));

            $prev_invoice = null;
            if ($proveedor_id > 0 && $code !== '' && $fecha) {
                $prev_invoice = $this->get_last_invoice_before($proveedor_id, $code, $fecha, $factura_id);
            }

            // Cotizaciones aprobadas + fallback legacy por línea.
            $prev_quote = $this->get_last_approved_quote_before($proveedor_id, $code, $fecha);
            $legacy = $this->get_legacy_for_code($proveedor_id, $code);

            $picked = $this->pick_reference($current, $prev_invoice, $prev_quote, $legacy, $compare_base);

            $rows[] = [
                'item_id' => (int) $item['id'],
                'numero_linea' => (int) $item['numero_linea'],
                'codigo_proveedor' => $code !== '' ? $code : null,
                'nombre' => $item['nombre'],
                'cantidad' => floatval($item['cantidad']),
                'unidad' => $item['unidad'],
                'costo_actual' => $current,
                'costo_actual_bases' => $bases,
                'prev_invoice' => $prev_invoice,
                'prev_quote' => $prev_quote,
                'legacy' => $legacy,
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
            'quote_wip' => false,
            'compare_base' => $compare_base,
        ];
    }

    /**
     * Análisis de una cotización recibida vs facturas / cotizaciones aprobadas / legacy.
     *
     * @param int    $cotizacion_id
     * @param string $compare_base auto|invoice|quote
     * @return array|WP_Error
     */
    public function analyze_quote($cotizacion_id, $compare_base = 'auto') {
        global $wpdb;
        $cotizacion_id = (int) $cotizacion_id;
        if ($cotizacion_id <= 0) {
            return new WP_Error('invalid', 'Cotización no especificada');
        }

        $quote = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, p.nombre AS proveedor_nombre, p.rut AS proveedor_rut
             FROM {$this->prefix}cotizaciones_recibidas c
             LEFT JOIN {$this->prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.id = %d",
            $cotizacion_id
        ), ARRAY_A);
        if (!$quote) {
            return new WP_Error('not_found', 'Cotización no encontrada');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->prefix}cotizacion_items WHERE cotizacion_id = %d ORDER BY linea ASC",
            $cotizacion_id
        ), ARRAY_A);

        $proveedor_id = (int) ($quote['proveedor_id'] ?? 0);
        $fecha = $quote['fecha_documento'] ?: current_time('Y-m-d');
        $rows = [];

        foreach ($items ?: [] as $item) {
            $code = trim((string) ($item['codigo_proveedor'] ?? ''));
            $bases = self::quote_item_cost_bases($item);
            $current = $bases['tras_dr']['neto'] ?? ($item['costo_neto'] !== null ? (float) $item['costo_neto'] : null);

            $resolved = $this->resolve_quote_item_reference($item, $proveedor_id, $fecha, $cotizacion_id);
            $prev_invoice = $resolved['prev_invoice'];
            $prev_quote = $resolved['prev_quote'];
            $legacy = $resolved['legacy'];
            $picked = $this->pick_reference($current, $prev_invoice, $prev_quote, $legacy, $compare_base);

            $match_path = null;
            $match_label = null;
            if ($picked['source'] === 'invoice' && is_array($prev_invoice)) {
                $match_path = $prev_invoice['match_path'] ?? 'invoice';
                $match_label = $prev_invoice['match_label'] ?? null;
            } elseif ($picked['source'] === 'quote' && is_array($prev_quote)) {
                $match_path = $prev_quote['match_path'] ?? 'quote';
                $match_label = $prev_quote['match_label'] ?? null;
            } elseif ($picked['source'] === 'legacy' && is_array($legacy)) {
                $match_path = $legacy['match_path'] ?? 'legacy';
                $match_label = $legacy['match_label'] ?? null;
            }

            $rows[] = [
                'item_id' => (int) $item['id'],
                'numero_linea' => (int) $item['linea'],
                'codigo_proveedor' => $code !== '' ? $code : null,
                'codigo_barras' => trim((string) ($item['codigo_barras'] ?? '')) ?: null,
                'sku_match' => trim((string) ($item['sku_match'] ?? '')) ?: null,
                'nombre' => $item['descripcion'],
                'cantidad' => floatval($item['cantidad']),
                'unidad' => $item['unidad'],
                'costo_actual' => $current,
                'costo_actual_bases' => $bases,
                'prev_invoice' => $prev_invoice,
                'prev_quote' => $prev_quote,
                'legacy' => $legacy,
                'resolved_product' => $resolved['product'],
                'reference_source' => $picked['source'],
                'reference_cost' => $picked['previous'],
                'match_path' => $match_path,
                'match_label' => $match_label,
                'delta' => $picked['delta'],
                'delta_pct' => $picked['pct'],
                'trend' => $picked['trend'],
            ];
        }

        return [
            'quote' => [
                'id' => (int) $quote['id'],
                'folio' => $quote['numero_documento'],
                'numero_documento' => $quote['numero_documento'],
                'proveedor_id' => $proveedor_id,
                'proveedor_nombre' => $quote['proveedor_nombre'],
                'fecha_emision' => $quote['fecha_documento'],
                'estado' => $quote['estado'],
                'tipo_fuente' => $quote['tipo_fuente'],
                'total' => floatval($quote['total']),
            ],
            'rows' => $rows,
            'compare_base' => $compare_base,
            'quote_wip' => false,
        ];
    }

    public function list_approved_quotes($args = []) {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(100, (int) $args['limit'])) : 40;
        $proveedor_id = isset($args['proveedor_id']) ? (int) $args['proveedor_id'] : 0;
        $buscar = isset($args['buscar']) ? trim((string) $args['buscar']) : '';

        $sql = "SELECT c.id, c.numero_documento, c.fecha_documento, c.estado, c.tipo_fuente,
                       c.total, c.proveedor_id, c.approved_at, p.nombre AS proveedor_nombre,
                       (SELECT COUNT(*) FROM {$this->prefix}cotizacion_items ci WHERE ci.cotizacion_id = c.id) AS items
                FROM {$this->prefix}cotizaciones_recibidas c
                LEFT JOIN {$this->prefix}proveedores p ON p.id = c.proveedor_id
                WHERE c.estado = 'approved'";
        $params = [];
        if ($proveedor_id > 0) {
            $sql .= ' AND c.proveedor_id = %d';
            $params[] = $proveedor_id;
        }
        if ($buscar !== '') {
            $sql .= ' AND (c.numero_documento LIKE %s OR p.nombre LIKE %s)';
            $like = '%' . $wpdb->esc_like($buscar) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY c.fecha_documento DESC, c.id DESC LIMIT %d';
        $params[] = $limit;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    /**
     * Última cotización aprobada antes de $date para el par proveedor+código.
     *
     * @param int         $proveedor_id
     * @param string      $codigo_proveedor
     * @param string      $date
     * @param int         $exclude_quote_id
     * @return array|null
     */
    public function get_last_approved_quote_before($proveedor_id, $codigo_proveedor, $date, $exclude_quote_id = 0) {
        global $wpdb;
        $proveedor_id = (int) $proveedor_id;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if ($proveedor_id <= 0 || $codigo_proveedor === '' || !$date) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.id AS cotizacion_id, c.numero_documento AS folio, c.fecha_documento AS fecha_emision,
                    ci.cantidad, ci.costo_neto, ci.costo_total
             FROM {$this->prefix}cotizacion_items ci
             INNER JOIN {$this->prefix}cotizaciones_recibidas c ON c.id = ci.cotizacion_id
             WHERE c.proveedor_id = %d
               AND ci.codigo_proveedor = %s
               AND c.estado = 'approved'
               AND c.id <> %d
               AND c.fecha_documento IS NOT NULL
               AND c.fecha_documento < %s
             ORDER BY c.fecha_documento DESC, c.id DESC
             LIMIT 1",
            $proveedor_id,
            $codigo_proveedor,
            (int) $exclude_quote_id,
            $date
        ), ARRAY_A);

        if (!$row) {
            return null;
        }
        $neto = $row['costo_neto'] !== null ? (float) $row['costo_neto'] : null;
        if ($neto === null) {
            return null;
        }
        $packed = self::pack_unit_costs($neto);
        return [
            'cotizacion_id' => (int) $row['cotizacion_id'],
            'folio' => $row['folio'] ?: ('COT-' . $row['cotizacion_id']),
            'fecha_emision' => $row['fecha_emision'],
            'tipo_dte' => null,
            'costo_unitario' => $packed['costo_unitario'],
            'costo_unitario_neto' => $packed['costo_unitario_neto'],
            'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
            'costo_bases' => self::pack_same_bases($neto, $packed['costo_unitario_bruto']),
        ];
    }

    /**
     * Cascada de referencia para un ítem de cotización: factura exacta,
     * código normalizado, producto relacionado (barcode/SKU) y legacy.
     *
     * @param array  $item
     * @param int    $proveedor_id
     * @param string $fecha
     * @param int    $exclude_quote_id
     * @return array{prev_invoice:?array,prev_quote:?array,legacy:?array,product:?array}
     */
    private function resolve_quote_item_reference(array $item, $proveedor_id, $fecha, $exclude_quote_id = 0) {
        $code = trim((string) ($item['codigo_proveedor'] ?? ''));
        $barcode = trim((string) ($item['codigo_barras'] ?? ''));
        $product = $this->resolve_producto_base_for_quote_item($item, $proveedor_id);

        $prev_invoice = null;
        if ($proveedor_id > 0 && $code !== '' && $fecha) {
            $prev_invoice = $this->get_last_invoice_before($proveedor_id, $code, $fecha, 0);
            if ($prev_invoice) {
                $prev_invoice = $this->annotate_ref(
                    $prev_invoice,
                    'invoice_exact',
                    sprintf('Factura folio %s · mismo código', $prev_invoice['folio'] ?? '')
                );
            }
        }

        if (!$prev_invoice && $code !== '') {
            $hit = $this->find_invoice_for_code($proveedor_id, $code, $fecha, true);
            if ($hit) {
                $same = strcasecmp(trim((string) ($hit['codigo_proveedor'] ?? '')), $code) === 0;
                $prev_invoice = $this->annotate_ref(
                    $hit,
                    $same ? 'invoice_exact' : 'invoice_normalized',
                    sprintf(
                        'Factura folio %s · %s',
                        $hit['folio'] ?? '',
                        $same ? 'mismo código' : ('código normalizado ' . ($hit['codigo_proveedor'] ?? $code))
                    )
                );
            }
        }

        if (!$prev_invoice && $code !== '') {
            $hit = $this->find_invoice_for_code($proveedor_id, $code, $fecha, false);
            if ($hit) {
                $prev_invoice = $this->annotate_ref(
                    $hit,
                    'invoice_latest',
                    sprintf('Factura folio %s · última (sin recorte de fecha)', $hit['folio'] ?? '')
                );
            }
        }

        if (!$prev_invoice && $code !== '') {
            $hit = $this->find_invoice_for_code(0, $code, $fecha, false);
            if ($hit) {
                $prov_name = $hit['proveedor_nombre'] ?? ('Proveedor #' . (int) ($hit['proveedor_id'] ?? 0));
                $prev_invoice = $this->annotate_ref(
                    $hit,
                    'invoice_any_supplier',
                    sprintf('Factura folio %s · %s · mismo código', $hit['folio'] ?? '', $prov_name)
                );
            }
        }

        if (!$prev_invoice && $barcode !== '' && $barcode !== $code) {
            $hit = $this->find_invoice_for_code($proveedor_id, $barcode, $fecha, false)
                ?: $this->find_invoice_for_code(0, $barcode, $fecha, false);
            if ($hit) {
                $prev_invoice = $this->annotate_ref(
                    $hit,
                    'invoice_barcode',
                    sprintf('Factura folio %s · vía barcode %s', $hit['folio'] ?? '', $barcode)
                );
            }
        }

        if (!$prev_invoice && !empty($product['producto_base_id'])) {
            $related = $this->invoice_for_related_product((int) $product['producto_base_id'], $fecha);
            if ($related) {
                $sku = $product['canonical_sku'] ?? '';
                $prov_name = $related['proveedor_nombre'] ?? '';
                $path = !empty($related['_latest']) ? 'invoice_related_latest' : 'invoice_related';
                $label = sprintf(
                    'Factura folio %s · vía producto %s%s',
                    $related['folio'] ?? '',
                    $sku !== '' ? $sku : ('#' . $product['producto_base_id']),
                    $prov_name !== '' ? (' · ' . $prov_name) : ''
                );
                unset($related['_latest']);
                $prev_invoice = $this->annotate_ref($related, $path, $label);
            }
        }

        $prev_quote = null;
        if ($proveedor_id > 0 && $code !== '' && $fecha) {
            $prev_quote = $this->get_last_approved_quote_before($proveedor_id, $code, $fecha, $exclude_quote_id);
            if ($prev_quote) {
                $prev_quote = $this->annotate_ref(
                    $prev_quote,
                    'quote_exact',
                    sprintf('Cotización %s · mismo código', $prev_quote['folio'] ?? '')
                );
            }
        }

        $legacy = null;
        $sku = !empty($product['canonical_sku']) ? (string) $product['canonical_sku'] : '';
        if ($sku === '' && $code !== '') {
            $legacy_direct = $this->get_legacy_for_code($proveedor_id, $code);
            if ($legacy_direct) {
                $legacy = $legacy_direct;
            }
        }
        if (!$legacy && $sku !== '') {
            $legacy = $this->legacy_ref_from_sku($sku, $product['match_source'] ?? 'sku');
        }
        if (!$legacy && $code !== '') {
            $legacy = $this->get_legacy_for_code($proveedor_id, $code);
        }

        return [
            'prev_invoice' => $prev_invoice,
            'prev_quote' => $prev_quote,
            'legacy' => $legacy,
            'product' => $product,
        ];
    }

    /**
     * @param array  $item
     * @param int    $proveedor_id
     * @return array|null {producto_base_id,canonical_sku,nombre,match_source}
     */
    private function resolve_producto_base_for_quote_item(array $item, $proveedor_id) {
        global $wpdb;

        $woo_id = (int) ($item['producto_id'] ?? 0);
        if ($woo_id > 0) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico
                 FROM {$this->prefix}producto_base
                 WHERE woocommerce_product_id = %d OR woocommerce_variation_id = %d
                 LIMIT 1",
                $woo_id,
                $woo_id
            ), ARRAY_A);
            if ($pb) {
                return [
                    'producto_base_id' => (int) $pb['id'],
                    'canonical_sku' => $pb['canonical_sku'],
                    'nombre' => $pb['nombre_canonico'],
                    'match_source' => 'woo_product',
                ];
            }
        }

        $terms = [];
        foreach (['codigo_barras', 'codigo_proveedor', 'sku_match'] as $key) {
            $val = trim((string) ($item[$key] ?? ''));
            if ($val !== '' && !in_array($val, $terms, true)) {
                $terms[] = $val;
            }
        }

        return $this->resolve_producto_base_for_terms($terms, $proveedor_id);
    }

    /**
     * @param string[] $terms
     * @param int      $proveedor_id
     * @return array|null
     */
    private function resolve_producto_base_for_terms(array $terms, $proveedor_id = 0) {
        global $wpdb;

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            if (class_exists('Riverso_Barcode_Model')) {
                $bundle = Riverso_Barcode_Model::lookup_for_search($term, ['limit' => 3]);
                foreach ($bundle['hits'] as $hit) {
                    $pb_id = (int) ($hit['producto_base_id'] ?? 0);
                    if ($pb_id <= 0) {
                        continue;
                    }
                    $pb = $this->get_producto_base($pb_id);
                    if ($pb) {
                        return [
                            'producto_base_id' => $pb_id,
                            'canonical_sku' => $pb['canonical_sku'],
                            'nombre' => $pb['nombre_canonico'],
                            'match_source' => $hit['match_source'] ?? 'barcode',
                        ];
                    }
                }
            }

            $sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, pp.proveedor_id
                    FROM {$this->prefix}producto_proveedor pp
                    INNER JOIN {$this->prefix}producto_base pb ON pb.id = pp.producto_base_id
                    WHERE pp.activo = 1
                      AND (pp.codigo_proveedor = %s OR pp.codigo_barras_proveedor = %s
                           OR LOWER(TRIM(pp.codigo_proveedor)) = %s)
                    ORDER BY CASE WHEN pp.proveedor_id = %d THEN 0 ELSE 1 END, pp.id DESC
                    LIMIT 1";
            $row = $wpdb->get_row($wpdb->prepare(
                $sql,
                $term,
                $term,
                strtolower($term),
                (int) $proveedor_id
            ), ARRAY_A);
            if ($row) {
                return [
                    'producto_base_id' => (int) $row['id'],
                    'canonical_sku' => $row['canonical_sku'],
                    'nombre' => $row['nombre_canonico'],
                    'match_source' => 'producto_proveedor',
                ];
            }

            $spl = $this->prefix . 'supplier_product_links';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $spl)) === $spl) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(spl.product_base_id, 0) AS producto_base_id, spl.internal_sku
                     FROM {$spl} spl
                     WHERE spl.is_active = 1
                       AND (spl.supplier_code = %s OR spl.internal_sku = %s)
                     ORDER BY CASE WHEN spl.supplier_id = %d THEN 0 ELSE 1 END
                     LIMIT 1",
                    $term,
                    $term,
                    (int) $proveedor_id
                ), ARRAY_A);
                if ($row) {
                    $pb_id = (int) $row['producto_base_id'];
                    $sku = trim((string) ($row['internal_sku'] ?? ''));
                    $pb = $pb_id ? $this->get_producto_base($pb_id) : null;
                    if (!$pb && $sku !== '') {
                        $pb = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, canonical_sku, nombre_canonico
                             FROM {$this->prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
                            $sku
                        ), ARRAY_A);
                    }
                    if ($pb) {
                        return [
                            'producto_base_id' => (int) $pb['id'],
                            'canonical_sku' => $pb['canonical_sku'],
                            'nombre' => $pb['nombre_canonico'],
                            'match_source' => 'supplier_link',
                        ];
                    }
                }
            }

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COALESCE(c.product_base_id, 0) AS producto_base_id, c.sku_local
                 FROM {$this->prefix}codigos c
                 WHERE c.activo = 1
                   AND (c.codigo_proveedor = %s OR c.sku_local = %s OR c.codigo_barras = %s)
                 LIMIT 1",
                $term,
                $term,
                $term
            ), ARRAY_A);
            if ($row) {
                $pb_id = (int) $row['producto_base_id'];
                $sku = trim((string) ($row['sku_local'] ?? ''));
                $pb = $pb_id ? $this->get_producto_base($pb_id) : null;
                if (!$pb && $sku !== '') {
                    $pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico
                         FROM {$this->prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
                        $sku
                    ), ARRAY_A);
                }
                if ($pb) {
                    return [
                        'producto_base_id' => (int) $pb['id'],
                        'canonical_sku' => $pb['canonical_sku'],
                        'nombre' => $pb['nombre_canonico'],
                        'match_source' => 'codigos',
                    ];
                }
                if ($sku !== '') {
                    return [
                        'producto_base_id' => null,
                        'canonical_sku' => $sku,
                        'nombre' => $sku,
                        'match_source' => 'codigos_sku',
                    ];
                }
            }

            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico
                 FROM {$this->prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
                $term
            ), ARRAY_A);
            if ($pb) {
                return [
                    'producto_base_id' => (int) $pb['id'],
                    'canonical_sku' => $pb['canonical_sku'],
                    'nombre' => $pb['nombre_canonico'],
                    'match_source' => 'sku_exact',
                ];
            }
        }

        foreach ($terms as $term) {
            $hits = $this->search($term, 5);
            foreach ($hits as $hit) {
                if (!empty($hit['producto_base_id'])) {
                    return [
                        'producto_base_id' => (int) $hit['producto_base_id'],
                        'canonical_sku' => $hit['canonical_sku'] ?? null,
                        'nombre' => $hit['nombre'] ?? null,
                        'match_source' => $hit['match_source'] ?? 'search',
                    ];
                }
                if (!empty($hit['canonical_sku'])) {
                    return [
                        'producto_base_id' => null,
                        'canonical_sku' => $hit['canonical_sku'],
                        'nombre' => $hit['nombre'] ?? $hit['canonical_sku'],
                        'match_source' => $hit['match_source'] ?? 'search_sku',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Factura más reciente del código (exacto o normalizado).
     *
     * @param int         $proveedor_id  0 = cualquier proveedor
     * @param string      $codigo_proveedor
     * @param string|null $fecha_emision
     * @param bool        $before_only
     * @return array|null
     */
    private function find_invoice_for_code($proveedor_id, $codigo_proveedor, $fecha_emision = null, $before_only = true) {
        global $wpdb;

        $code = trim((string) $codigo_proveedor);
        if ($code === '') {
            return null;
        }
        $norm = $this->normalize_supplier_code($code);
        $code_lower = strtolower($code);

        $sql = "SELECT f.id AS factura_id, f.tipo_dte, f.folio, f.fecha_emision,
                       f.proveedor_id, p.nombre AS proveedor_nombre,
                       fi.cantidad, fi.precio_unitario, fi.costo_neto_base, fi.costo_bruto_base,
                       fi.costo_neto_final, fi.costo_bruto_final, fi.costo_landed_unitario,
                       fi.codigo_proveedor
                FROM {$this->prefix}factura_items fi
                INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE fi.item_tipo = 'producto'
                  AND f.tipo_dte IN (33, 34)
                  AND (f.documento_subtipo = 'productos' OR f.documento_subtipo IS NULL OR f.documento_subtipo = '')
                  AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''
                  AND (
                        fi.codigo_proveedor = %s
                        OR LOWER(TRIM(fi.codigo_proveedor)) = %s
                        OR REPLACE(REPLACE(REPLACE(LOWER(TRIM(fi.codigo_proveedor)), '-', ''), '_', ''), ' ', '') = %s
                  )";
        $params = [$code, $code_lower, $norm];

        if ((int) $proveedor_id > 0) {
            $sql .= ' AND f.proveedor_id = %d';
            $params[] = (int) $proveedor_id;
        }
        if ($before_only && $fecha_emision) {
            $sql .= ' AND f.fecha_emision <= %s';
            $params[] = $fecha_emision;
        }

        $sql .= ' ORDER BY f.fecha_emision DESC, f.id DESC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        return $this->invoice_row_to_ref($row);
    }

    /**
     * @param int         $producto_base_id
     * @param string|null $fecha
     * @return array|null
     */
    private function invoice_for_related_product($producto_base_id, $fecha = null) {
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return null;
        }

        $pares = $this->get_supplier_pairs($producto_base_id);
        $best = null;
        if ($fecha) {
            foreach ($pares as $pair) {
                $prov_id = (int) ($pair['proveedor_id'] ?? 0);
                $pair_code = trim((string) ($pair['codigo_proveedor'] ?? ''));
                if ($prov_id <= 0 || $pair_code === '') {
                    continue;
                }
                $hit = $this->get_last_invoice_before($prov_id, $pair_code, $fecha, 0);
                if (!$hit) {
                    continue;
                }
                $hit['proveedor_id'] = $prov_id;
                $hit['proveedor_nombre'] = $pair['proveedor_nombre'] ?? $this->get_proveedor_nombre($prov_id);
                $hit['codigo_proveedor'] = $pair_code;
                if ($best === null || (string) $hit['fecha_emision'] > (string) $best['fecha_emision']) {
                    $best = $hit;
                }
            }
            if ($best) {
                $best['_latest'] = false;
                return $best;
            }
        }

        $latest = $this->latest_cost_bases_for_product($producto_base_id);
        $ref = $this->ref_from_latest_product_cost($latest);
        if ($ref) {
            $ref['_latest'] = true;
        }
        return $ref;
    }

    /**
     * @param array $latest
     * @return array|null
     */
    private function ref_from_latest_product_cost(array $latest) {
        if (empty($latest['factura_id']) || empty($latest['costo_bases'])) {
            return null;
        }
        $bases = $latest['costo_bases'];
        $neto = $bases['tras_dr']['neto'] ?? ($bases['referencia']['neto'] ?? null);
        $bruto = $bases['tras_dr']['bruto'] ?? ($bases['referencia']['bruto'] ?? null);
        if ($neto === null) {
            return null;
        }
        $packed = self::pack_unit_costs((float) $neto, $bruto !== null ? (float) $bruto : null);
        return [
            'factura_id' => (int) $latest['factura_id'],
            'tipo_dte' => null,
            'folio' => $latest['folio'],
            'fecha_emision' => $latest['fecha_emision'],
            'proveedor_id' => $latest['proveedor_id'],
            'proveedor_nombre' => $latest['proveedor_nombre'],
            'codigo_proveedor' => $latest['codigo_proveedor'],
            'costo_unitario' => $packed['costo_unitario'],
            'costo_unitario_neto' => $packed['costo_unitario_neto'],
            'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
            'costo_bases' => $bases,
        ];
    }

    /**
     * @param array|null $row
     * @return array|null
     */
    private function invoice_row_to_ref($row) {
        if (!$row) {
            return null;
        }
        $bases = self::unit_cost_bases_packed($row);
        $cost = $bases['tras_dr']['neto'] ?? self::unit_cost_from_item($row);
        if ($cost === null) {
            return null;
        }
        $bruto = $bases['tras_dr']['bruto'] ?? null;
        $packed = self::pack_unit_costs((float) $cost, $bruto !== null ? (float) $bruto : null);
        return [
            'factura_id' => (int) $row['factura_id'],
            'tipo_dte' => isset($row['tipo_dte']) ? (int) $row['tipo_dte'] : null,
            'folio' => $row['folio'],
            'fecha_emision' => $row['fecha_emision'],
            'proveedor_id' => isset($row['proveedor_id']) ? (int) $row['proveedor_id'] : null,
            'proveedor_nombre' => $row['proveedor_nombre'] ?? null,
            'codigo_proveedor' => $row['codigo_proveedor'] ?? null,
            'costo_unitario' => $packed['costo_unitario'],
            'costo_unitario_neto' => $packed['costo_unitario_neto'],
            'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
            'costo_bases' => $bases,
        ];
    }

    /**
     * @param array|null $ref
     * @param string     $match_path
     * @param string     $match_label
     * @return array|null
     */
    private function annotate_ref($ref, $match_path, $match_label) {
        if (!$ref) {
            return null;
        }
        $ref['match_path'] = $match_path;
        $ref['match_label'] = $match_label;
        return $ref;
    }

    private function normalize_supplier_code($code) {
        $code = strtolower(trim((string) $code));
        return str_replace(['-', '_', ' '], '', $code);
    }

    private function get_legacy_for_code($proveedor_id, $codigo_proveedor) {
        $code = trim((string) $codigo_proveedor);
        if ($code === '') {
            return null;
        }
        $resolved = $this->resolve_producto_base_for_terms([$code], (int) $proveedor_id);
        $sku = $resolved && !empty($resolved['canonical_sku']) ? (string) $resolved['canonical_sku'] : '';
        if ($sku === '') {
            return null;
        }
        return $this->legacy_ref_from_sku($sku, $resolved['match_source'] ?? 'sku');
    }

    /**
     * @param string $sku
     * @param string $via
     * @return array|null
     */
    private function legacy_ref_from_sku($sku, $via = 'sku') {
        $legacy = $this->get_legacy_cost_for_sku($sku);
        if (!$legacy) {
            return null;
        }
        $neto = (float) $legacy['costo_neto'];
        $packed = self::pack_unit_costs($neto);
        return [
            'folio' => 'LEGACY',
            'fecha_emision' => $legacy['importado_at'],
            'importado_at' => $legacy['importado_at'],
            'tipo_dte' => null,
            'source_kind' => 'legacy',
            'match_path' => 'legacy',
            'match_label' => sprintf('Legacy TPV · SKU %s', $sku),
            'costo_unitario' => $packed['costo_unitario'],
            'costo_unitario_neto' => $packed['costo_unitario_neto'],
            'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
            'costo_bases' => self::pack_same_bases($neto, $packed['costo_unitario_bruto']),
            'sku' => $sku,
            'via' => $via,
        ];
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
    public function get_last_invoice_before_public($proveedor_id, $codigo_proveedor, $fecha_emision, $exclude_factura_id = 0) {
        return $this->get_last_invoice_before($proveedor_id, $codigo_proveedor, $fecha_emision, $exclude_factura_id);
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
                    fi.cantidad, fi.precio_unitario, fi.costo_neto_base, fi.costo_bruto_base,
                    fi.costo_neto_final, fi.costo_bruto_final, fi.costo_landed_unitario
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

        $bases = self::unit_cost_bases_packed($row);
        $cost = $bases['tras_dr']['neto'] ?? self::unit_cost_from_item($row);
        if ($cost === null) {
            return null;
        }

        return [
            'factura_id' => (int) $row['factura_id'],
            'tipo_dte' => (int) $row['tipo_dte'],
            'folio' => $row['folio'],
            'fecha_emision' => $row['fecha_emision'],
            'costo_unitario' => $cost,
            'costo_bases' => $bases,
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
    private function pick_reference($current, $prev_invoice, $prev_quote, $legacy = null, $compare_base = 'auto') {
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

        $invoice_c = ($prev_invoice && isset($prev_invoice['costo_unitario'])) ? (float) $prev_invoice['costo_unitario'] : null;
        $quote_c = ($prev_quote && isset($prev_quote['costo_unitario'])) ? (float) $prev_quote['costo_unitario'] : null;
        $legacy_c = ($legacy && isset($legacy['costo_unitario'])) ? (float) $legacy['costo_unitario'] : null;

        $chosen = null;
        $source = null;
        if ($compare_base === 'invoice') {
            $chosen = $invoice_c !== null ? $invoice_c : $legacy_c;
            $source = $invoice_c !== null ? 'invoice' : ($legacy_c !== null ? 'legacy' : null);
        } elseif ($compare_base === 'quote') {
            $chosen = $quote_c !== null ? $quote_c : $legacy_c;
            $source = $quote_c !== null ? 'quote' : ($legacy_c !== null ? 'legacy' : null);
        } else {
            $candidates = [];
            if ($invoice_c !== null) {
                $candidates[] = ['source' => 'invoice', 'previous' => $invoice_c];
            }
            if ($quote_c !== null) {
                $candidates[] = ['source' => 'quote', 'previous' => $quote_c];
            }
            if (!$candidates && $legacy_c !== null) {
                $candidates[] = ['source' => 'legacy', 'previous' => $legacy_c];
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
            $chosen = $best['previous'];
            $source = $best['source'];
        }

        if ($chosen === null) {
            return $empty;
        }

        $delta = (float) $current - $chosen;
        $pct = ($chosen != 0.0) ? round(($delta / $chosen) * 100, 2) : null;
        $trend = 'se_mantuvo';
        if (abs($delta) >= 0.001) {
            $trend = $delta > 0 ? 'subio' : 'bajo';
        }

        return [
            'source' => $source,
            'previous' => $chosen,
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
                       fi.precio_unitario, fi.costo_neto_base, fi.costo_bruto_base,
                       fi.costo_neto_final, fi.costo_bruto_final, fi.costo_landed_unitario,
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
            $bases = self::unit_cost_bases_packed($row);
            $dr = $bases['tras_dr'];
            $packed = self::pack_unit_costs(
                $dr['neto'] ?? null,
                $dr['bruto'] ?? null
            );
            // Compat: costo_unitario_* = tras D/R (sin landed en este payload).
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
                'costo_unitario' => $packed['costo_unitario'],
                'costo_unitario_neto' => $packed['costo_unitario_neto'],
                'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
                'costo_bases' => $bases,
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
            $neto = $row['costo_neto'] !== null ? floatval($row['costo_neto']) : null;
            $packed = self::pack_unit_costs($neto);
            $bases = self::pack_same_bases($neto, $packed['costo_unitario_bruto']);
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
                'costo_unitario' => $packed['costo_unitario'],
                'costo_unitario_neto' => $packed['costo_unitario_neto'],
                'costo_unitario_bruto' => $packed['costo_unitario_bruto'],
                'costo_bases' => $bases,
                'monto_total' => floatval($row['costo_total']),
            ];
        }
        return $out;
    }
}
