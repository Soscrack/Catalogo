<?php
/**
 * Avisos de compra: lo que bodega y mesón avisan que falta, para quien arma el pedido.
 *
 * Un aviso no es un conteo ni una línea de orden de compra: no toca stock y su
 * cantidad es una propuesta. "Sin identificar" no es un estado: es un aviso abierto
 * sin producto, que igual se puede ingresar al pedido mirando la foto.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Notice_Service {

    const STATES = [
        'abierto' => 'Abierto',
        'ingresado' => 'Ingresado',
        'descartado' => 'Descartado',
    ];

    const DISCARD_REASONS = [
        'duplicado' => 'Ya estaba avisado',
        'hay_stock' => 'Hay stock en otro lugar',
        'no_se_pide' => 'No se va a pedir',
        'descontinuado' => 'Producto descontinuado',
        'error' => 'Aviso por error',
        'otro' => 'Otro motivo',
    ];

    const UNITS = [
        'caja' => 'cajas',
        'unidad' => 'unidades',
        'bolsa' => 'bolsas',
        'paquete' => 'paquetes',
        'blister' => 'blísters',
        'bulto' => 'bultos',
        'rollo' => 'rollos',
        'kg' => 'kg',
        'metro' => 'metros',
        'litro' => 'litros',
    ];

    const SOURCES = [
        'barra' => 'Código de barra',
        'barra_sin_confirmar' => 'Código de barra (sin confirmar)',
        'barra_proveedor' => 'Código de barra del proveedor',
        'codigo_proveedor' => 'Código de proveedor',
        'sku' => 'SKU',
        'nombre' => 'Nombre',
    ];

    /** Un "ingresado" de estos días se muestra como "ya pedido" al volver a avisar. */
    const RECENT_DAYS = 30;
    const UPLOAD_DIR = 'riverso-avisos';
    const MAX_PHOTO_BYTES = 8388608;
    const MAX_CANDIDATES = 8;

    private static $instance = null;

    /** @var bool|null */
    private $ready = null;

    /** @var bool|null */
    private $has_families = null;

    /** @var array<int, int> */
    private $local_ids = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param string $name
     * @return string
     */
    private function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'riverso_' . $name;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function table_exists($name) {
        global $wpdb;
        $table = $this->table($name);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /**
     * @return bool
     */
    public function ready() {
        if ($this->ready !== null) {
            return $this->ready;
        }
        $ok = $this->table_exists('avisos_compra') && $this->table_exists('aviso_compra_eventos');
        if (!$ok && class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_purchase_notices')) {
            Riverso_POS_Activator::ensure_purchase_notices();
            $ok = $this->table_exists('avisos_compra') && $this->table_exists('aviso_compra_eventos');
        }
        $this->ready = $ok;
        return $this->ready;
    }

    /* ===================== Identificar ===================== */

    /**
     * Quita espacios, guiones y puntos: "0617 401 100" y "0617401100" son el mismo código.
     * No quita ceros a la izquierda: "000-139" no es el SKU 139.
     *
     * @param string $code
     * @return string
     */
    public static function normalize_code($code) {
        $code = strtoupper(trim((string) $code));
        return (string) preg_replace('/[\s\-\.]+/u', '', $code);
    }

    /**
     * Busca un texto leído o escrito en todas las fuentes y devuelve todos los productos
     * posibles. No elige por la persona cuando hay más de uno.
     *
     * @param string $query
     * @param string $origen       camara|teclado
     * @param int    $proveedor_id Proveedor elegido por la persona (ordena, no filtra).
     * @return array{consulta: string, exactos: int, candidatos: array}
     */
    public function resolve($query, $origen = 'teclado', $proveedor_id = 0) {
        $query = trim((string) preg_replace('/\s+/u', ' ', (string) $query));
        $out = ['consulta' => $query, 'exactos' => 0, 'candidatos' => []];
        if ($query === '') {
            return $out;
        }

        $found = [];
        $norm = self::normalize_code($query);
        $len = strlen($norm);
        if ($len >= 2 && $len <= 40) {
            $order = $origen === 'camara' ? ['barcode', 'supplier', 'sku'] : ['sku', 'supplier', 'barcode'];
            foreach ($order as $source) {
                if ($source === 'barcode') {
                    $hits = $this->find_by_barcode($query);
                } elseif ($source === 'supplier') {
                    $hits = $this->find_by_supplier_code($query, $norm, (int) $proveedor_id);
                } else {
                    $hits = $this->find_by_sku($query);
                }
                foreach ($hits as $hit) {
                    $this->push_candidate($found, $hit);
                }
            }
        }
        $out['exactos'] = count($found);

        $query_length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
        if (!$found && $query_length >= 3) {
            foreach ($this->search_by_name($query, self::MAX_CANDIDATES) as $pb_id) {
                $this->push_candidate($found, [
                    'producto_base_id' => $pb_id,
                    'fuente' => 'nombre',
                    'confiable' => false,
                ]);
            }
        }

        $out['candidatos'] = $this->hydrate_candidates(array_slice(array_values($found), 0, self::MAX_CANDIDATES));
        return $out;
    }

    /**
     * @param array $found
     * @param array $hit
     */
    private function push_candidate(array &$found, array $hit) {
        $pb_id = (int) ($hit['producto_base_id'] ?? 0);
        if ($pb_id > 0) {
            $pb_id = $this->local_product_id($pb_id);
            $hit['producto_base_id'] = $pb_id;
        }
        $pp_id = (int) ($hit['pp_id'] ?? 0);
        if ($pb_id <= 0 && $pp_id <= 0) {
            return;
        }
        $key = $pb_id > 0 ? 'pb:' . $pb_id : 'pp:' . $pp_id;
        if (!isset($found[$key])) {
            $found[$key] = $hit + ['pp_id' => 0, 'pack_qty' => null, 'confiable' => true];
            return;
        }
        // La primera fuente manda; las siguientes solo completan lo que falte.
        if (empty($found[$key]['pp_id']) && $pp_id > 0) {
            $found[$key]['pp_id'] = $pp_id;
        }
        if (empty($found[$key]['pack_qty']) && !empty($hit['pack_qty'])) {
            $found[$key]['pack_qty'] = $hit['pack_qty'];
        }
    }

    /**
     * @param string $query
     * @return array
     */
    private function find_by_barcode($query) {
        global $wpdb;
        $hits = [];

        if (!class_exists('Riverso_Barcode_Model')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'catalog/barcodes/class-barcode-model.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('Riverso_Barcode_Model')) {
            foreach ($this->code_variants($query) as $variant) {
                $bundle = Riverso_Barcode_Model::resolve_with_suggestions($variant);
                if (!empty($bundle['match']['producto_base_id'])) {
                    $hits[] = [
                        'producto_base_id' => (int) $bundle['match']['producto_base_id'],
                        'fuente' => 'barra',
                        'confiable' => true,
                        'pack_qty' => $this->pack_qty($bundle['match']),
                    ];
                }
                foreach ((array) ($bundle['suggestions'] ?? []) as $suggestion) {
                    if (empty($suggestion['producto_base_id'])) {
                        continue;
                    }
                    $hits[] = [
                        'producto_base_id' => (int) $suggestion['producto_base_id'],
                        'fuente' => 'barra_sin_confirmar',
                        'confiable' => false,
                        'pack_qty' => $this->pack_qty($suggestion),
                    ];
                }
            }
        }

        // Códigos de proveedor guardados como barra con separadores ("50ATPF-G", "0617 401 100").
        $norm = self::normalize_code($query);
        if (strlen($norm) >= 4) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT producto_base_id, estado, cantidad FROM {$this->table('codigo_barra')}
                 WHERE activo = 1 AND estado IN ('verificado', 'propuesto') AND producto_base_id IS NOT NULL
                   AND codigo REGEXP '[ .-]'
                   AND UPPER(REPLACE(REPLACE(REPLACE(codigo, ' ', ''), '-', ''), '.', '')) = %s
                 ORDER BY (estado = 'verificado') DESC, id ASC
                 LIMIT 6",
                $norm
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $hits[] = [
                    'producto_base_id' => (int) $row['producto_base_id'],
                    'fuente' => $row['estado'] === 'verificado' ? 'barra' : 'barra_sin_confirmar',
                    'confiable' => $row['estado'] === 'verificado',
                    'pack_qty' => $this->pack_qty($row),
                ];
            }
        }

        if (ctype_digit($query) && strlen($query) >= 6) {
            $stripped = ltrim($query, '0');
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, producto_base_id FROM {$this->table('producto_proveedor')}
                 WHERE activo = 1
                   AND codigo_barras_proveedor IS NOT NULL AND codigo_barras_proveedor <> ''
                   AND (codigo_barras_proveedor = %s OR TRIM(LEADING '0' FROM codigo_barras_proveedor) = %s)
                 ORDER BY es_preferido DESC, id ASC
                 LIMIT 10",
                $query,
                $stripped === '' ? '0' : $stripped
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $hits[] = [
                    'producto_base_id' => (int) $row['producto_base_id'],
                    'pp_id' => (int) $row['id'],
                    'fuente' => 'barra_proveedor',
                    'confiable' => true,
                ];
            }
        }

        return $hits;
    }

    /**
     * Formas en que el mismo código pudo quedar guardado como barra: tal cual, sin
     * separadores, y con el guion de Steelfix (se digita 000139, la caja dice 000-139).
     *
     * @param string $query
     * @return string[]
     */
    private function code_variants($query) {
        $variants = [$query];
        $norm = self::normalize_code($query);
        if ($norm !== '' && strcasecmp($norm, $query) !== 0) {
            $variants[] = $norm;
        }
        if (preg_match('/^\d{6}$/', $norm)) {
            $variants[] = substr($norm, 0, 3) . '-' . substr($norm, 3);
        }
        return array_values(array_unique($variants));
    }

    /**
     * @param array $mapping
     * @return float|null
     */
    private function pack_qty($mapping) {
        $qty = (float) ($mapping['cantidad_unidades'] ?? $mapping['cantidad'] ?? 0);
        return $qty > 1.0001 ? $qty : null;
    }

    /**
     * @param string $query
     * @param string $norm
     * @param int    $proveedor_id
     * @return array
     */
    private function find_by_supplier_code($query, $norm, $proveedor_id) {
        global $wpdb;
        $hits = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, producto_base_id FROM {$this->table('producto_proveedor')}
             WHERE activo = 1
               AND UPPER(REPLACE(REPLACE(REPLACE(codigo_proveedor, ' ', ''), '-', ''), '.', '')) = %s
             ORDER BY (proveedor_id = %d) DESC, es_preferido DESC, id ASC
             LIMIT 12",
            $norm,
            $proveedor_id
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $hits[] = [
                'producto_base_id' => (int) $row['producto_base_id'],
                'pp_id' => (int) $row['id'],
                'fuente' => 'codigo_proveedor',
                'confiable' => true,
            ];
        }

        // Código corto Mamut impreso en la caja (01TRN, 50ATPF-G) → SKU local.
        if (function_exists('riverso_mamut_online_to_local_sku')) {
            $local_sku = riverso_mamut_online_to_local_sku(strtoupper($query));
            if ($local_sku) {
                foreach ($this->find_by_sku($local_sku) as $hit) {
                    $hit['fuente'] = 'codigo_proveedor';
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }

    /**
     * @param string $sku
     * @return array
     */
    private function find_by_sku($sku) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table('producto_base')}
             WHERE estado = 'activo' AND deleted_at IS NULL AND canonical_sku = %s
             LIMIT 3",
            $sku
        )) ?: [];
        $hits = [];
        foreach ($ids as $id) {
            $hits[] = ['producto_base_id' => (int) $id, 'fuente' => 'sku', 'confiable' => true];
        }
        return $hits;
    }

    /**
     * Todas las palabras deben aparecer en el nombre, el SKU o un código del proveedor.
     *
     * @param string $query
     * @param int    $limit
     * @return int[]
     */
    private function search_by_name($query, $limit) {
        global $wpdb;
        $tokens = array_slice(array_values(array_filter(preg_split('/\s+/u', $query), 'strlen')), 0, 6);
        if (!$tokens) {
            return [];
        }
        $pp = $this->table('producto_proveedor');
        $where = [];
        $params = [];
        foreach ($tokens as $token) {
            $like = '%' . $wpdb->esc_like($token) . '%';
            $where[] = "(pb.nombre_canonico LIKE %s OR pb.canonical_sku LIKE %s OR EXISTS (
                SELECT 1 FROM {$pp} pp
                WHERE pp.producto_base_id = pb.id AND pp.activo = 1
                  AND (pp.codigo_proveedor LIKE %s OR pp.nombre_proveedor LIKE %s)))";
            array_push($params, $like, $like, $like, $like);
        }
        $params[] = (int) $limit;
        // Primero el catálogo local (SKU numérico) y los nombres más cortos.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pb.id FROM {$this->table('producto_base')} pb
             WHERE pb.estado = 'activo' AND pb.deleted_at IS NULL AND " . implode(' AND ', $where) . "
             ORDER BY (pb.canonical_sku REGEXP '^[0-9]+$') DESC, CHAR_LENGTH(pb.nombre_canonico) ASC
             LIMIT %d",
            $params
        )) ?: [];
        return array_map('intval', $ids);
    }

    /**
     * Si el producto es la contraparte online o de catálogo (su SKU es el código Mamut, o no
     * tiene SKU y su código de proveedor es Mamut), devuelve el producto del catálogo local,
     * que es el que se cuenta y se pide.
     *
     * @param int $producto_base_id
     * @return int
     */
    private function local_product_id($producto_base_id) {
        global $wpdb;
        $producto_base_id = (int) $producto_base_id;
        if (!function_exists('riverso_mamut_online_to_local_sku')) {
            return $producto_base_id;
        }
        if (isset($this->local_ids[$producto_base_id])) {
            return $this->local_ids[$producto_base_id];
        }
        $sku = trim((string) $wpdb->get_var($wpdb->prepare(
            "SELECT canonical_sku FROM {$this->table('producto_base')} WHERE id = %d",
            $producto_base_id
        )));
        $codes = [$sku];
        if ($sku === '') {
            $codes = $wpdb->get_col($wpdb->prepare(
                "SELECT codigo_proveedor FROM {$this->table('producto_proveedor')}
                 WHERE producto_base_id = %d AND activo = 1 ORDER BY es_preferido DESC, id ASC LIMIT 5",
                $producto_base_id
            )) ?: [];
        }
        $result = $producto_base_id;
        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $mapped = riverso_mamut_online_to_local_sku($code);
            if (!$mapped || strcasecmp((string) $mapped, $sku) === 0) {
                continue;
            }
            $local = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table('producto_base')}
                 WHERE estado = 'activo' AND deleted_at IS NULL AND canonical_sku = %s LIMIT 1",
                $mapped
            ));
            if ($local > 0) {
                $result = $local;
                break;
            }
        }
        $this->local_ids[$producto_base_id] = $result;
        return $result;
    }

    /**
     * @param array $found
     * @return array
     */
    private function hydrate_candidates(array $found) {
        global $wpdb;
        $out = [];
        foreach ($found as $hit) {
            $pb_id = (int) ($hit['producto_base_id'] ?? 0);
            $pp_id = (int) ($hit['pp_id'] ?? 0);
            $product = null;
            if ($pb_id > 0) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                     FROM {$this->table('producto_base')} WHERE id = %d AND deleted_at IS NULL",
                    $pb_id
                ), ARRAY_A);
                if (!$product) {
                    continue;
                }
            }
            $suppliers = $this->suppliers_for($pb_id, $pp_id);
            if (!$product && !$suppliers) {
                continue;
            }
            $fuente = (string) ($hit['fuente'] ?? 'nombre');
            $out[] = [
                'producto_base_id' => $pb_id,
                'sku' => $product ? (string) $product['canonical_sku'] : '',
                'nombre' => $product
                    ? (string) $product['nombre_canonico']
                    : (string) ($suppliers[0]['nombre_en_proveedor'] ?: ('Código ' . $suppliers[0]['codigo'])),
                'imagen' => $product ? $this->product_image($product) : '',
                'fuente' => $fuente,
                'fuente_label' => self::SOURCES[$fuente] ?? $fuente,
                'confiable' => !empty($hit['confiable']),
                'pack_qty' => !empty($hit['pack_qty']) ? (float) $hit['pack_qty'] : null,
                'pp_id' => $pp_id,
                'proveedores' => $suppliers,
                'proveedor_sugerido' => (!$suppliers && $pb_id > 0) ? $this->last_supplier_for($pb_id) : null,
                'abiertos' => $this->related_notices($pb_id, $pp_id, 'abierto'),
                'recientes' => $this->related_notices($pb_id, $pp_id, 'ingresado'),
            ];
        }
        return $out;
    }

    /**
     * @param array $product
     * @return string
     */
    private function product_image($product) {
        if (!function_exists('get_post_thumbnail_id')) {
            return '';
        }
        foreach (['woocommerce_variation_id', 'woocommerce_product_id'] as $field) {
            $post_id = (int) ($product[$field] ?? 0);
            if ($post_id <= 0) {
                continue;
            }
            $thumb = (int) get_post_thumbnail_id($post_id);
            if ($thumb > 0) {
                $url = wp_get_attachment_image_url($thumb, 'thumbnail');
                if ($url) {
                    return (string) $url;
                }
            }
        }
        return '';
    }

    /**
     * Proveedores a los que se le compra el producto. El que calzó por código va primero.
     *
     * @param int $producto_base_id
     * @param int $matched_pp_id
     * @return array
     */
    public function suppliers_for($producto_base_id, $matched_pp_id = 0) {
        global $wpdb;
        $producto_base_id = (int) $producto_base_id;
        $matched_pp_id = (int) $matched_pp_id;
        if ($producto_base_id <= 0 && $matched_pp_id <= 0) {
            return [];
        }

        $conditions = [];
        $params = [];
        if ($matched_pp_id > 0) {
            $conditions[] = 'pp.id = %d';
            $params[] = $matched_pp_id;
        }
        if ($producto_base_id > 0) {
            $conditions[] = 'pp.producto_base_id = %d';
            $params[] = $producto_base_id;
            if ($this->has_families()) {
                $conditions[] = "(pp.grupo_id IS NOT NULL AND pp.grupo_id IN (
                    SELECT em.grupo_id FROM {$this->table('equivalence_members')} em
                    WHERE em.producto_base_id = %d AND em.activo = 1))";
                $params[] = $producto_base_id;
            }
        }
        $params[] = $matched_pp_id;
        $params[] = $producto_base_id;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.id, pp.proveedor_id, pp.producto_base_id, pp.codigo_proveedor, pp.nombre_proveedor,
                    pp.unidad_compra, pp.factor_conversion, pp.es_preferido, pr.nombre AS proveedor_nombre
             FROM {$this->table('producto_proveedor')} pp
             INNER JOIN {$this->table('proveedores')} pr ON pr.id = pp.proveedor_id
             WHERE pp.activo = 1 AND (" . implode(' OR ', $conditions) . ")
             ORDER BY (pp.id = %d) DESC, (pp.producto_base_id = %d) DESC, pp.es_preferido DESC, pp.id ASC
             LIMIT 12",
            $params
        ), ARRAY_A) ?: [];

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            // Un proveedor aparece una vez: la primera fila es la que mejor calza.
            $supplier_id = (int) $row['proveedor_id'];
            if (isset($seen[$supplier_id])) {
                continue;
            }
            $seen[$supplier_id] = true;
            $factor = (float) $row['factor_conversion'];
            $out[] = [
                'pp_id' => (int) $row['id'],
                'proveedor_id' => $supplier_id,
                'nombre' => (string) $row['proveedor_nombre'],
                'codigo' => (string) $row['codigo_proveedor'],
                'nombre_en_proveedor' => (string) $row['nombre_proveedor'],
                'unidad' => (string) $row['unidad_compra'],
                // factor_conversion nace en 1: solo un valor mayor dice algo.
                'factor' => $factor > 1.0001 ? $factor : null,
                'preferido' => (int) $row['es_preferido'] === 1,
                'calzo' => (int) $row['id'] === $matched_pp_id,
            ];
        }
        return $out;
    }

    /**
     * Producto sin vínculo con proveedor: a quién se le pidió la última vez que alguien lo avisó.
     *
     * @param int $producto_base_id
     * @return array{id: int, nombre: string}|null
     */
    private function last_supplier_for($producto_base_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.proveedor_id, pr.nombre
             FROM {$this->table('avisos_compra')} a
             INNER JOIN {$this->table('proveedores')} pr ON pr.id = a.proveedor_id
             WHERE a.producto_base_id = %d AND a.estado <> 'descartado'
             ORDER BY a.id DESC LIMIT 1",
            (int) $producto_base_id
        ), ARRAY_A);
        return $row ? ['id' => (int) $row['proveedor_id'], 'nombre' => (string) $row['nombre']] : null;
    }

    /**
     * @return bool
     */
    private function has_families() {
        if ($this->has_families === null) {
            $this->has_families = $this->table_exists('equivalence_members');
        }
        return $this->has_families;
    }

    /**
     * Avisos del mismo producto: los abiertos ("ya avisado") o los ingresados hace poco ("ya pedido").
     *
     * @param int    $producto_base_id
     * @param int    $pp_id
     * @param string $estado
     * @return array
     */
    private function related_notices($producto_base_id, $pp_id, $estado) {
        global $wpdb;
        $match = [];
        $params = [$estado];
        if ($producto_base_id > 0) {
            $match[] = 'a.producto_base_id = %d';
            $params[] = (int) $producto_base_id;
        }
        if ($pp_id > 0) {
            $match[] = 'a.producto_proveedor_id = %d';
            $params[] = (int) $pp_id;
        }
        if (!$match) {
            return [];
        }
        $recent = '';
        if ($estado === 'ingresado') {
            $recent = ' AND a.ingresado_en >= %s';
            $params[] = gmdate('Y-m-d H:i:s', current_time('timestamp') - self::RECENT_DAYS * DAY_IN_SECONDS);
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT a.id FROM {$this->table('avisos_compra')} a
             WHERE a.estado = %s AND (" . implode(' OR ', $match) . "){$recent}
             ORDER BY a.id DESC LIMIT 3",
            $params
        )) ?: [];
        return $this->get_many(array_map('intval', $ids));
    }

    /* ===================== Crear y sumar ===================== */

    /**
     * @param array      $data
     * @param array|null $file Entrada de $_FILES con la foto.
     * @return array Aviso creado.
     * @throws Exception
     */
    public function create(array $data, $file = null) {
        global $wpdb;
        $pb_id = max(0, (int) ($data['producto_base_id'] ?? 0));
        $pp_id = max(0, (int) ($data['producto_proveedor_id'] ?? 0));
        $supplier_id = max(0, (int) ($data['proveedor_id'] ?? 0));
        $texto = $this->clip($data['texto'] ?? '', 255);
        $codigo = $this->clip($data['codigo_leido'] ?? '', 120);
        $has_file = is_array($file) && !empty($file['tmp_name']);

        if ($pb_id > 0 && !$this->product_exists($pb_id)) {
            throw new Exception('El producto ya no existe.');
        }
        if ($pb_id <= 0 && $pp_id <= 0 && $texto === '' && $codigo === '' && !$has_file) {
            throw new Exception('Escribe qué falta o toma una foto.');
        }

        $link = $this->resolve_supplier_link($pb_id, $pp_id, $supplier_id);
        $quantity = $this->parse_quantity($data);

        $factor = null;
        $factor_origen = null;
        $typed_factor = (float) str_replace(',', '.', (string) ($data['factor'] ?? ''));
        if ($typed_factor > 1.0001) {
            $factor = $typed_factor;
            $factor_origen = in_array($data['factor_origen'] ?? '', ['barra', 'persona'], true) ? $data['factor_origen'] : 'persona';
        } elseif ($link['factor']) {
            $factor = $link['factor'];
            $factor_origen = 'proveedor';
        }

        $photo = $has_file ? $this->store_photo($file) : null;
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $inserted = $wpdb->insert($this->table('avisos_compra'), [
            'estado' => 'abierto',
            'producto_base_id' => $pb_id ?: null,
            'producto_proveedor_id' => $link['pp_id'] ?: null,
            'proveedor_id' => $link['proveedor_id'] ?: null,
            'codigo_leido' => $codigo !== '' ? $codigo : null,
            'origen_codigo' => in_array($data['origen_codigo'] ?? '', ['camara', 'teclado', 'busqueda'], true) ? $data['origen_codigo'] : null,
            'match_fuente' => isset(self::SOURCES[$data['match_fuente'] ?? '']) ? $data['match_fuente'] : null,
            'texto' => $texto !== '' ? $texto : null,
            'nota' => $this->clip($data['nota'] ?? '', 1000) ?: null,
            'sin_stock' => !empty($data['sin_stock']) ? 1 : 0,
            'cantidad' => $quantity['cantidad'],
            'unidad' => $quantity['unidad'],
            'factor' => $factor,
            'factor_origen' => $factor_origen,
            'foto_ruta' => $photo,
            'creado_por' => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$inserted) {
            if ($photo) {
                $this->delete_photo($photo);
            }
            throw new Exception('No se pudo guardar el aviso.');
        }
        $id = (int) $wpdb->insert_id;
        $this->log_event($id, 'creado', [
            'cantidad' => $quantity['cantidad'],
            'unidad' => $quantity['unidad'],
            'sin_stock' => !empty($data['sin_stock']),
        ]);
        return $this->get($id);
    }

    /**
     * Otra persona avisa lo mismo: se suma al aviso abierto en vez de duplicarlo.
     *
     * @param int   $id
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function join($id, array $data) {
        global $wpdb;
        $row = $this->row($id);
        if (!$row || $row['estado'] !== 'abierto') {
            throw new Exception('Ese aviso ya no está abierto.');
        }
        $quantity = $this->parse_quantity($data);
        $this->log_event($id, 'apoyo', [
            'cantidad' => $quantity['cantidad'],
            'unidad' => $quantity['unidad'],
            'sin_stock' => !empty($data['sin_stock']),
            'nota' => $this->clip($data['nota'] ?? '', 500),
        ]);

        $apoyos = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT usuario_id) FROM {$this->table('aviso_compra_eventos')}
             WHERE aviso_id = %d AND tipo = 'apoyo' AND usuario_id <> %d",
            $id,
            (int) $row['creado_por']
        ));
        $update = ['apoyos' => $apoyos, 'updated_at' => current_time('mysql')];
        if (!empty($data['sin_stock'])) {
            $update['sin_stock'] = 1;
        }
        // El aviso no traía cantidad: la primera propuesta que llega la completa.
        if ($row['cantidad'] === null && $quantity['cantidad'] !== null) {
            $update['cantidad'] = $quantity['cantidad'];
            $update['unidad'] = $quantity['unidad'];
        }
        $wpdb->update($this->table('avisos_compra'), $update, ['id' => (int) $id]);
        return $this->get($id);
    }

    /**
     * @param array $data
     * @return array{cantidad: float|null, unidad: string|null}
     * @throws Exception
     */
    private function parse_quantity(array $data) {
        $raw = trim((string) ($data['cantidad'] ?? ''));
        if ($raw === '') {
            return ['cantidad' => null, 'unidad' => null];
        }
        $cantidad = (float) str_replace(',', '.', $raw);
        if ($cantidad <= 0) {
            throw new Exception('La cantidad debe ser mayor que cero.');
        }
        $unidad = sanitize_key((string) ($data['unidad'] ?? ''));
        if (!isset(self::UNITS[$unidad])) {
            // Sin unidad, "300" puede ser 300 tornillos o 300 cajas.
            throw new Exception('Indica en qué unidad va la cantidad (cajas, unidades…).');
        }
        return ['cantidad' => round($cantidad, 3), 'unidad' => $unidad];
    }

    /**
     * Proveedor del aviso: el vínculo que calzó, el que eligió la persona, o el único posible.
     *
     * @param int $pb_id
     * @param int $pp_id
     * @param int $supplier_id
     * @return array{pp_id: int, proveedor_id: int, factor: float|null}
     */
    private function resolve_supplier_link($pb_id, $pp_id, $supplier_id) {
        $suppliers = $this->suppliers_for($pb_id, $pp_id);
        $pick = null;
        foreach ($suppliers as $supplier) {
            if ($supplier_id > 0 ? $supplier['proveedor_id'] === $supplier_id : $supplier['calzo']) {
                $pick = $supplier;
                break;
            }
        }
        if (!$pick && $supplier_id <= 0) {
            if (count($suppliers) === 1) {
                $pick = $suppliers[0];
            } else {
                foreach ($suppliers as $supplier) {
                    if ($supplier['preferido']) {
                        $pick = $supplier;
                        break;
                    }
                }
            }
        }
        if ($pick) {
            return ['pp_id' => $pick['pp_id'], 'proveedor_id' => $pick['proveedor_id'], 'factor' => $pick['factor']];
        }
        if ($supplier_id > 0 && !$this->supplier_exists($supplier_id)) {
            $supplier_id = 0;
        }
        return ['pp_id' => 0, 'proveedor_id' => $supplier_id, 'factor' => null];
    }

    /* ===================== Bandeja ===================== */

    /**
     * @param int   $id
     * @param string $op
     * @param array $data
     * @param bool  $can_manage
     * @return array
     * @throws Exception
     */
    public function update($id, $op, array $data, $can_manage) {
        global $wpdb;
        $id = (int) $id;
        $row = $this->row($id);
        if (!$row) {
            throw new Exception('Aviso no encontrado.');
        }
        $user_id = get_current_user_id();
        $now = current_time('mysql');
        $own_open = (int) $row['creado_por'] === $user_id && $row['estado'] === 'abierto';
        if (!$can_manage && !($op === 'descartar' && $own_open)) {
            throw new Exception('No tienes permiso para cambiar este aviso.');
        }

        $update = ['updated_at' => $now];
        $event = [];

        switch ($op) {
            case 'ingresar':
                if ($row['estado'] !== 'abierto') {
                    throw new Exception('Solo se ingresan avisos abiertos.');
                }
                if (trim((string) ($data['cantidad'] ?? '')) !== '') {
                    $quantity = $this->parse_quantity($data);
                    $update += $this->confirmed_quantity($quantity, $user_id, $now);
                    $event = $quantity;
                }
                $update += ['estado' => 'ingresado', 'ingresado_por' => $user_id, 'ingresado_en' => $now];
                break;

            case 'descartar':
                if ($row['estado'] !== 'abierto') {
                    throw new Exception('Solo se descartan avisos abiertos.');
                }
                $motivo = sanitize_key((string) ($data['motivo'] ?? ''));
                if (!isset(self::DISCARD_REASONS[$motivo])) {
                    $motivo = $can_manage ? 'otro' : 'error';
                }
                $update += [
                    'estado' => 'descartado',
                    'descartado_por' => $user_id,
                    'descartado_en' => $now,
                    'motivo_descarte' => $motivo,
                ];
                $event = ['motivo' => $motivo];
                break;

            case 'reabrir':
                if ($row['estado'] === 'abierto') {
                    throw new Exception('El aviso ya está abierto.');
                }
                $update += [
                    'estado' => 'abierto',
                    'ingresado_por' => null,
                    'ingresado_en' => null,
                    'descartado_por' => null,
                    'descartado_en' => null,
                    'motivo_descarte' => null,
                ];
                $event = ['desde' => $row['estado']];
                break;

            case 'cantidad':
                $quantity = $this->parse_quantity($data);
                if ($quantity['cantidad'] === null) {
                    throw new Exception('Escribe la cantidad.');
                }
                $update += $this->confirmed_quantity($quantity, $user_id, $now);
                $event = $quantity;
                break;

            case 'proveedor':
                $supplier_id = max(0, (int) ($data['proveedor_id'] ?? 0));
                if ($supplier_id > 0 && !$this->supplier_exists($supplier_id)) {
                    throw new Exception('Proveedor no encontrado.');
                }
                $link = $this->resolve_supplier_link((int) $row['producto_base_id'], 0, $supplier_id);
                $update += [
                    'proveedor_id' => $supplier_id ?: null,
                    'producto_proveedor_id' => ($supplier_id > 0 && $link['proveedor_id'] === $supplier_id && $link['pp_id']) ? $link['pp_id'] : null,
                ];
                $event = ['proveedor_id' => $supplier_id];
                break;

            case 'identificar':
                $pb_id = max(0, (int) ($data['producto_base_id'] ?? 0));
                if ($pb_id <= 0 || !$this->product_exists($pb_id)) {
                    throw new Exception('Producto no encontrado.');
                }
                $update += ['producto_base_id' => $pb_id, 'resuelto_por' => $user_id, 'resuelto_en' => $now];
                // Si el aviso no tenía proveedor, toma el del producto cuando hay uno solo o uno preferido.
                if (empty($row['proveedor_id'])) {
                    $link = $this->resolve_supplier_link($pb_id, 0, 0);
                    if ($link['proveedor_id']) {
                        $update['proveedor_id'] = $link['proveedor_id'];
                        $update['producto_proveedor_id'] = $link['pp_id'] ?: null;
                    }
                }
                $event = ['producto_base_id' => $pb_id];
                break;

            default:
                throw new Exception('Acción no reconocida.');
        }

        if ($wpdb->update($this->table('avisos_compra'), $update, ['id' => $id]) === false) {
            throw new Exception('No se pudo actualizar el aviso.');
        }
        $this->log_event($id, $op, $event);
        return $this->get($id);
    }

    /**
     * @param array  $quantity
     * @param int    $user_id
     * @param string $now
     * @return array
     */
    private function confirmed_quantity(array $quantity, $user_id, $now) {
        return [
            'cantidad' => $quantity['cantidad'],
            'unidad' => $quantity['unidad'],
            'cantidad_confirmada_por' => $user_id,
            'cantidad_confirmada_en' => $now,
        ];
    }

    /**
     * Bandeja de quien arma el pedido, agrupada por proveedor.
     *
     * @param array $filters estado (abiertos|sin_identificar|ingresados|descartados), proveedor_id, buscar
     * @return array{grupos: array, counts: array}
     */
    public function list_notices(array $filters = []) {
        global $wpdb;
        $estado = (string) ($filters['estado'] ?? 'abiertos');
        $where = [];
        $params = [];
        switch ($estado) {
            case 'sin_identificar':
                $where[] = "a.estado = 'abierto' AND a.producto_base_id IS NULL";
                break;
            case 'ingresados':
                $where[] = "a.estado = 'ingresado'";
                break;
            case 'descartados':
                $where[] = "a.estado = 'descartado'";
                break;
            default:
                $estado = 'abiertos';
                $where[] = "a.estado = 'abierto'";
        }
        if (array_key_exists('proveedor_id', $filters) && $filters['proveedor_id'] !== '' && $filters['proveedor_id'] !== null) {
            $supplier_id = (int) $filters['proveedor_id'];
            if ($supplier_id > 0) {
                $where[] = 'a.proveedor_id = %d';
                $params[] = $supplier_id;
            } else {
                $where[] = 'a.proveedor_id IS NULL';
            }
        }
        $search = trim((string) ($filters['buscar'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(pb.nombre_canonico LIKE %s OR pb.canonical_sku LIKE %s OR a.texto LIKE %s OR a.codigo_leido LIKE %s OR pp.codigo_proveedor LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql = $this->select_sql() . ' WHERE ' . implode(' AND ', $where) . '
            ORDER BY (pr.nombre IS NULL) ASC, pr.nombre ASC, a.id DESC
            LIMIT 300';
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $groups = [];
        foreach ($rows ?: [] as $row) {
            $notice = $this->format($row);
            $key = $notice['proveedor_id'] ?: 0;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'proveedor_id' => $key,
                    'nombre' => $key ? $notice['proveedor_nombre'] : 'Sin proveedor',
                    'avisos' => [],
                ];
            }
            $groups[$key]['avisos'][] = $notice;
        }

        return ['estado' => $estado, 'grupos' => array_values($groups), 'counts' => $this->counts()];
    }

    /**
     * @return array{abiertos: int, sin_identificar: int}
     */
    public function counts() {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS abiertos, COALESCE(SUM(producto_base_id IS NULL), 0) AS sin_identificar
             FROM {$this->table('avisos_compra')} WHERE estado = 'abierto'",
            ARRAY_A
        );
        return [
            'abiertos' => (int) ($row['abiertos'] ?? 0),
            'sin_identificar' => (int) ($row['sin_identificar'] ?? 0),
        ];
    }

    /**
     * Avisos que la persona creó o apoyó: así ve si ya se pidió lo que avisó.
     *
     * @param int $user_id
     * @return array
     */
    public function mine($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT a.id FROM {$this->table('avisos_compra')} a
             WHERE a.creado_por = %d
                OR a.id IN (SELECT e.aviso_id FROM {$this->table('aviso_compra_eventos')} e
                            WHERE e.usuario_id = %d AND e.tipo = 'apoyo')
             ORDER BY a.id DESC LIMIT 40",
            $user_id,
            $user_id
        )) ?: [];
        return $this->get_many(array_map('intval', $ids));
    }

    /**
     * @param string $term
     * @return array
     */
    public function suppliers($term = '') {
        global $wpdb;
        $term = trim((string) $term);
        $where = 'p.activo = 1';
        $params = [];
        if ($term !== '') {
            $where .= ' AND p.nombre LIKE %s';
            $params[] = '%' . $wpdb->esc_like($term) . '%';
        }
        // Primero los proveedores con más productos vinculados: son los de los pedidos de siempre.
        $sql = "SELECT p.id, p.nombre, COUNT(pp.id) AS productos
                FROM {$this->table('proveedores')} p
                LEFT JOIN {$this->table('producto_proveedor')} pp ON pp.proveedor_id = p.id AND pp.activo = 1
                WHERE {$where}
                GROUP BY p.id, p.nombre
                ORDER BY productos DESC, p.nombre ASC
                LIMIT 200";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']];
        }
        return $out;
    }

    /* ===================== Lectura ===================== */

    /**
     * @param int $id
     * @return array|null
     */
    public function get($id) {
        $rows = $this->get_many([(int) $id]);
        return $rows ? $rows[0] : null;
    }

    /**
     * @param int[] $ids
     * @return array En el orden pedido.
     */
    private function get_many(array $ids) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = $wpdb->get_results(
            $this->select_sql() . ' WHERE a.id IN (' . implode(',', $ids) . ')',
            ARRAY_A
        ) ?: [];
        $by_id = [];
        foreach ($rows as $row) {
            $by_id[(int) $row['id']] = $this->format($row);
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($by_id[$id])) {
                $out[] = $by_id[$id];
            }
        }
        return $out;
    }

    /**
     * @param int $id
     * @return array|null
     */
    private function row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table('avisos_compra')} WHERE id = %d",
            (int) $id
        ), ARRAY_A);
    }

    /**
     * @return string
     */
    private function select_sql() {
        global $wpdb;
        return "SELECT a.*, pb.canonical_sku, pb.nombre_canonico, pr.nombre AS proveedor_nombre,
                       pp.codigo_proveedor, pp.nombre_proveedor AS nombre_en_proveedor,
                       uc.display_name AS creado_nombre, ui.display_name AS ingresado_nombre,
                       ud.display_name AS descartado_nombre, uq.display_name AS confirmo_nombre
                FROM {$this->table('avisos_compra')} a
                LEFT JOIN {$this->table('producto_base')} pb ON pb.id = a.producto_base_id
                LEFT JOIN {$this->table('proveedores')} pr ON pr.id = a.proveedor_id
                LEFT JOIN {$this->table('producto_proveedor')} pp ON pp.id = a.producto_proveedor_id
                LEFT JOIN {$wpdb->users} uc ON uc.ID = a.creado_por
                LEFT JOIN {$wpdb->users} ui ON ui.ID = a.ingresado_por
                LEFT JOIN {$wpdb->users} ud ON ud.ID = a.descartado_por
                LEFT JOIN {$wpdb->users} uq ON uq.ID = a.cantidad_confirmada_por";
    }

    /**
     * @param array $row
     * @return array
     */
    private function format(array $row) {
        $cantidad = $row['cantidad'] !== null ? (float) $row['cantidad'] : null;
        $unidad = (string) ($row['unidad'] ?? '');
        $factor = $row['factor'] !== null ? (float) $row['factor'] : null;
        $equivalencia = null;
        if ($cantidad !== null && $factor !== null && $factor > 1.0001 && $unidad !== 'unidad') {
            $equivalencia = round($cantidad * $factor, 3);
        }
        $identificado = !empty($row['producto_base_id']);
        $titulo = $identificado
            ? (string) $row['nombre_canonico']
            : (string) ($row['texto'] ?: ($row['nombre_en_proveedor'] ?: ($row['codigo_leido'] ? 'Código ' . $row['codigo_leido'] : 'Sin identificar')));
        $now = current_time('timestamp');

        return [
            'id' => (int) $row['id'],
            'estado' => (string) $row['estado'],
            'estado_label' => self::STATES[$row['estado']] ?? (string) $row['estado'],
            'identificado' => $identificado,
            'titulo' => $titulo,
            'producto_base_id' => $identificado ? (int) $row['producto_base_id'] : 0,
            'sku' => (string) ($row['canonical_sku'] ?? ''),
            'proveedor_id' => !empty($row['proveedor_id']) ? (int) $row['proveedor_id'] : 0,
            'proveedor_nombre' => (string) ($row['proveedor_nombre'] ?? ''),
            'codigo_proveedor' => (string) ($row['codigo_proveedor'] ?? ''),
            'codigo_leido' => (string) ($row['codigo_leido'] ?? ''),
            'texto' => (string) ($row['texto'] ?? ''),
            'nota' => (string) ($row['nota'] ?? ''),
            'sin_stock' => (int) $row['sin_stock'] === 1,
            'cantidad' => $cantidad,
            'unidad' => $unidad,
            'unidad_label' => self::UNITS[$unidad] ?? $unidad,
            'factor' => $factor,
            'equivalencia' => $equivalencia,
            'cantidad_confirmada' => !empty($row['cantidad_confirmada_por']),
            'confirmo_nombre' => (string) ($row['confirmo_nombre'] ?? ''),
            'apoyos' => (int) $row['apoyos'],
            'foto' => $this->photo_url($row['foto_ruta'] ?? ''),
            'creado_por' => (int) $row['creado_por'],
            'creado_nombre' => (string) ($row['creado_nombre'] ?? ''),
            'creado_en' => (string) $row['created_at'],
            'hace' => $this->ago($row['created_at'], $now),
            'ingresado_nombre' => (string) ($row['ingresado_nombre'] ?? ''),
            'ingresado_hace' => !empty($row['ingresado_en']) ? $this->ago($row['ingresado_en'], $now) : '',
            'descartado_nombre' => (string) ($row['descartado_nombre'] ?? ''),
            'motivo_descarte' => (string) ($row['motivo_descarte'] ?? ''),
            'motivo_label' => self::DISCARD_REASONS[$row['motivo_descarte'] ?? ''] ?? '',
        ];
    }

    /**
     * @param string $mysql_date
     * @param int    $now
     * @return string
     */
    private function ago($mysql_date, $now) {
        $then = strtotime((string) $mysql_date);
        if (!$then) {
            return '';
        }
        return 'hace ' . human_time_diff($then, max($then, $now));
    }

    /* ===================== Foto ===================== */

    /**
     * La foto es evidencia para quien arma el pedido; no se vuelve a leer como dato.
     *
     * @param array $file
     * @return string Ruta relativa a uploads.
     * @throws Exception
     */
    private function store_photo(array $file) {
        if (!empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('No se pudo recibir la foto.');
        }
        if (filesize($file['tmp_name']) > self::MAX_PHOTO_BYTES) {
            throw new Exception('La foto pesa demasiado.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            throw new Exception('La foto debe ser JPG, PNG o WebP.');
        }

        $upload = wp_upload_dir();
        $subdir = self::UPLOAD_DIR . '/' . gmdate('Y/m', current_time('timestamp'));
        $dir = trailingslashit($upload['basedir']) . $subdir;
        if (!wp_mkdir_p($dir)) {
            throw new Exception('No se pudo guardar la foto.');
        }
        $index = trailingslashit($upload['basedir']) . self::UPLOAD_DIR . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new Exception('No se pudo guardar la foto.');
        }
        return $subdir . '/' . $name;
    }

    /**
     * @param string $path
     */
    private function delete_photo($path) {
        $upload = wp_upload_dir();
        $full = trailingslashit($upload['basedir']) . ltrim((string) $path, '/');
        if (strpos($path, self::UPLOAD_DIR . '/') === 0 && is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function photo_url($path) {
        $path = ltrim((string) $path, '/');
        if ($path === '') {
            return '';
        }
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . $path;
    }

    /* ===================== Helpers ===================== */

    /**
     * @param int    $aviso_id
     * @param string $tipo
     * @param array  $detalle
     */
    private function log_event($aviso_id, $tipo, array $detalle = []) {
        global $wpdb;
        $wpdb->insert($this->table('aviso_compra_eventos'), [
            'aviso_id' => (int) $aviso_id,
            'tipo' => sanitize_key($tipo),
            'usuario_id' => get_current_user_id(),
            'detalle' => $detalle ? wp_json_encode($detalle) : null,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * @param int $id
     * @return bool
     */
    private function product_exists($id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table('producto_base')} WHERE id = %d AND deleted_at IS NULL",
            (int) $id
        ));
    }

    /**
     * @param int $id
     * @return bool
     */
    private function supplier_exists($id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table('proveedores')} WHERE id = %d",
            (int) $id
        ));
    }

    /**
     * @param mixed $value
     * @param int   $max
     * @return string
     */
    private function clip($value, $max) {
        $value = trim((string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        return substr($value, 0, $max);
    }
}
