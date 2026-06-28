<?php
/**
 * Servicio de ingreso de facturas XML: clasificación envío/producto,
 * persistencia de códigos proveedor, prorrateo y lotes para precios baseline.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Invoice_Intake_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Palabras clave para detectar líneas de flete/envío en el XML.
     */
    public function get_shipping_keywords() {
        $custom = riverso_get_setting('shipping_keywords', []);
        $defaults = [
            'flete', 'envio', 'envío', 'transporte', 'shipping', 'freight',
            'despacho', 'courier', 'logistica', 'logística', 'transportista',
            'carga', 'tarifa despacho', 'costo despacho', 'servicio de envio',
            'servicio de envío', 'flete terrestre', 'flete aereo', 'flete aéreo',
        ];
        return array_unique(array_merge($defaults, array_filter((array) $custom)));
    }

    /**
     * Determina si una línea del DTE corresponde a costo de envío/flete.
     */
    public function is_shipping_line($nombre, $descripcion = '') {
        $text = mb_strtolower(trim($nombre . ' ' . $descripcion));
        foreach ($this->get_shipping_keywords() as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword !== '' && strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clasifica ítems del XML parseado como producto o envío.
     */
    public function classify_factura_items(array &$factura_data) {
        $shipping_total = 0.0;
        $product_count = 0;

        foreach ($factura_data['items'] as &$item) {
            $item['item_tipo'] = $this->is_shipping_line(
                $item['nombre'] ?? '',
                $item['descripcion'] ?? ''
            ) ? 'envio' : 'producto';

            if ($item['item_tipo'] === 'envio') {
                $shipping_total += (float) ($item['monto'] ?? 0);
            } else {
                $product_count++;
            }
        }
        unset($item);

        $factura_data['costo_envio_inline'] = $shipping_total;
        $factura_data['items_producto'] = $product_count;

        return $factura_data;
    }

    /**
     * Palabras clave en emisor/giro que sugieren factura de transportista.
     */
    public function get_carrier_emisor_keywords() {
        return [
            'transporte', 'transportes', 'logistica', 'logística', 'courier',
            'flete', 'fletes', 'envio', 'envío', 'cargo', 'express', 'delivery',
            'chilexpress', 'starken', 'bluex', 'correos', 'transitaria',
        ];
    }

    /**
     * Detecta si el XML completo es de productos, transportista (envío) o mixto.
     */
    public function detect_document_type(array $factura_data) {
        $items = $factura_data['items'] ?? [];
        $product_count = 0;
        $shipping_count = 0;
        $items_preview = [];

        foreach ($items as $item) {
            $tipo = $item['item_tipo'] ?? ($this->is_shipping_line(
                $item['nombre'] ?? '',
                $item['descripcion'] ?? ''
            ) ? 'envio' : 'producto');

            if ($tipo === 'envio') {
                $shipping_count++;
            } else {
                $product_count++;
            }

            $items_preview[] = [
                'linea' => $item['numero'] ?? 0,
                'nombre' => $item['nombre'] ?? '',
                'tipo' => $tipo,
                'cantidad' => $item['cantidad'] ?? 0,
                'monto' => $item['monto'] ?? 0,
            ];
        }

        $emisor = $factura_data['emisor'] ?? [];
        $emisor_text = mb_strtolower(trim(
            ($emisor['razon_social'] ?? '') . ' ' . ($emisor['giro'] ?? '')
        ));
        $emisor_is_carrier = false;
        foreach ($this->get_carrier_emisor_keywords() as $keyword) {
            if (strpos($emisor_text, $keyword) !== false) {
                $emisor_is_carrier = true;
                break;
            }
        }

        if ($product_count === 0 && $shipping_count > 0) {
            return [
                'tipo' => 'envio',
                'label' => 'Transportista / flete',
                'confianza' => 'alta',
                'motivo' => 'Todas las líneas del XML corresponden a flete o envío.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'emisor_es_transportista' => $emisor_is_carrier,
                'items_preview' => $items_preview,
            ];
        }

        if ($shipping_count > 0 && $product_count > 0) {
            return [
                'tipo' => 'mixto',
                'label' => 'Productos con flete incluido',
                'confianza' => 'alta',
                'motivo' => "El XML incluye {$product_count} línea(s) de producto y {$shipping_count} de envío/flete.",
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'emisor_es_transportista' => $emisor_is_carrier,
                'items_preview' => $items_preview,
            ];
        }

        if ($emisor_is_carrier && $product_count === 0) {
            return [
                'tipo' => 'envio',
                'label' => 'Transportista / flete',
                'confianza' => 'media',
                'motivo' => 'El emisor del DTE parece ser una empresa de transporte o logística.',
                'items_producto' => $product_count,
                'items_envio' => $shipping_count,
                'emisor_es_transportista' => true,
                'items_preview' => $items_preview,
            ];
        }

        return [
            'tipo' => 'productos',
            'label' => 'Factura de productos',
            'confianza' => 'alta',
            'motivo' => $shipping_count > 0
                ? 'Factura de compra de productos.'
                : "Se detectaron {$product_count} línea(s) de producto.",
            'items_producto' => $product_count,
            'items_envio' => $shipping_count,
            'emisor_es_transportista' => $emisor_is_carrier,
            'items_preview' => $items_preview,
        ];
    }

    /**
     * Resuelve SKU local desde lookup; nunca devuelve el código proveedor/online como SKU local.
     */
    public function resolve_local_sku($codigo_proveedor, $lookup = null, $proveedor_id = null) {
        $code = trim((string) $codigo_proveedor);
        if ($code === '') {
            return null;
        }

        // Mapeo Mamut (online → local) es autoritativo cuando existe.
        $mamut_local = riverso_mamut_online_to_local_sku($code);
        if ($mamut_local) {
            return $mamut_local;
        }

        if ($lookup === null && class_exists('Riverso_Supplier_Links_Module')) {
            $links = Riverso_Supplier_Links_Module::get_instance();
            $lookup = $links->lookup_by_code($code, $proveedor_id ? (int) $proveedor_id : null);
        }

        if (is_array($lookup) && !empty($lookup['found'])) {
            $candidates = [];
            if (!empty($lookup['domain']['canonical_sku'])) {
                $candidates[] = trim((string) $lookup['domain']['canonical_sku']);
            }
            if (!empty($lookup['legacy']['sku_local'])) {
                $candidates[] = trim((string) $lookup['legacy']['sku_local']);
            }
            if (!empty($lookup['link']['internal_sku'])) {
                $candidates[] = trim((string) $lookup['link']['internal_sku']);
            }

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && riverso_is_trusted_supplier_local_sku($code, $candidate, $lookup)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Obtiene product_id WooCommerce para un SKU local (producto_base o WC).
     */
    public function resolve_product_id_for_local_sku($local_sku, $supplier_code = '') {
        $local_sku = trim((string) $local_sku);
        if ($local_sku === '') {
            return null;
        }
        if ($supplier_code && riverso_sku_equals_supplier_code($local_sku, $supplier_code)) {
            return null;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base
             WHERE canonical_sku = %s
             LIMIT 1",
            $local_sku
        ));

        if ($base) {
            $ref = (int) ($base->woocommerce_variation_id ?: $base->woocommerce_product_id);
            if ($ref > 0) {
                return $ref;
            }
        }

        if (function_exists('wc_get_product_id_by_sku')) {
            $wc_id = wc_get_product_id_by_sku($local_sku);
            if ($wc_id) {
                return (int) $wc_id;
            }
        }

        return null;
    }

    /**
     * SKU online = SKU del producto en WooCommerce (no confundir con SKU local ni código proveedor).
     */
    public function resolve_sku_online($codigo_proveedor, $lookup = null, $product_id = null, $local_sku = null) {
        $supplier_code = trim((string) $codigo_proveedor);
        $local_sku = trim((string) ($local_sku ?? ''));

        $try_product_sku = function ($pid) use ($local_sku) {
            if (!$pid || !function_exists('wc_get_product')) {
                return null;
            }
            $product = wc_get_product((int) $pid);
            if (!$product) {
                return null;
            }
            $woo_sku = trim((string) $product->get_sku());
            if ($woo_sku === '') {
                return null;
            }
            // El SKU online no debe ser el SKU local del catálogo.
            if ($local_sku !== '' && strcasecmp($woo_sku, $local_sku) === 0) {
                return null;
            }
            return $woo_sku;
        };

        if ($sku = $try_product_sku($product_id)) {
            return $sku;
        }

        if (is_array($lookup) && !empty($lookup['product']['id'])) {
            if ($sku = $try_product_sku((int) $lookup['product']['id'])) {
                return $sku;
            }
        }

        if ($local_sku !== '') {
            $pid = $this->resolve_product_id_for_local_sku($local_sku, $supplier_code);
            if ($sku = $try_product_sku($pid)) {
                return $sku;
            }
        }

        if ($supplier_code !== '' && function_exists('wc_get_product_id_by_sku')) {
            $pid = wc_get_product_id_by_sku($supplier_code);
            if ($sku = $try_product_sku($pid)) {
                return $sku;
            }
        }

        // Mamut: a menudo coincide con código proveedor; otros proveedores pueden diferir.
        return $supplier_code !== '' ? $supplier_code : null;
    }

    /**
     * Normaliza fila de ítem de factura (alias columnas + SKUs local/online).
     */
    public function enrich_factura_item_row($item) {
        if (!$item) {
            return $item;
        }
        if (is_array($item)) {
            $item = (object) $item;
        }
        if (!isset($item->linea)) {
            $item->linea = $item->numero_linea ?? 0;
        }
        if (empty($item->descripcion)) {
            $item->descripcion = $item->nombre ?? '';
        }
        if (empty($item->nombre)) {
            $item->nombre = $item->descripcion ?? '';
        }

        $supplier_code = trim((string) ($item->codigo_proveedor ?? ''));

        if (empty($item->sku_local) && !empty($item->product_id) && function_exists('wc_get_product')) {
            $product = wc_get_product((int) $item->product_id);
            if ($product) {
                $woo_sku = trim((string) $product->get_sku());
                if ($supplier_code !== '' && riverso_sku_equals_supplier_code($woo_sku, $supplier_code)) {
                    $mamut = riverso_mamut_online_to_local_sku($supplier_code);
                    if ($mamut) {
                        $item->sku_local = $mamut;
                    }
                } elseif ($woo_sku !== '' && !riverso_sku_equals_supplier_code($woo_sku, $supplier_code)) {
                    // SKU WC distinto al proveedor: podría ser local si no hay mapeo Mamut.
                    $mamut = $supplier_code ? riverso_mamut_online_to_local_sku($supplier_code) : null;
                    $item->sku_local = $mamut ?: $woo_sku;
                }
            }
        }

        if ($supplier_code !== '' && riverso_sku_equals_supplier_code($item->sku_local ?? '', $supplier_code)) {
            $mamut = riverso_mamut_online_to_local_sku($supplier_code);
            if ($mamut) {
                $item->sku_local = $mamut;
            }
        }

        $lookup = null;
        if ($supplier_code !== '' && class_exists('Riverso_Supplier_Links_Module')) {
            $proveedor_id = isset($item->proveedor_id) ? (int) $item->proveedor_id : 0;
            $lookup = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code($supplier_code, $proveedor_id ?: null);
        }

        $item->sku_online = $this->resolve_sku_online(
            $supplier_code,
            $lookup,
            $item->product_id ?? null,
            $item->sku_local ?? null
        );

        return $item;
    }

    /**
     * Actualiza estado agregado de factura según ítems vinculados.
     */
    public function sync_factura_item_status($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'vinculado' THEN 1 ELSE 0 END) AS vinculados,
                    SUM(CASE WHEN estado = 'rechazado' THEN 1 ELSE 0 END) AS rechazados
             FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $estado = 'recibido';
        if ($stats && (int) $stats->total > 0) {
            if ((int) $stats->vinculados === (int) $stats->total) {
                $estado = 'procesado';
            } elseif ((int) $stats->vinculados > 0 || (int) $stats->rechazados > 0) {
                $estado = 'parcial';
            }
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => $estado],
            ['id' => (int) $factura_id],
            ['%s'],
            ['%d']
        );

        return $estado;
    }

    public function lookup_product_mapping($proveedor_id, $codigo_proveedor, $codigos = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $result = [
            'sku_local' => null,
            'sku_online' => null,
            'product_id' => null,
            'producto_base_id' => null,
            'producto_proveedor_id' => null,
            'source' => null,
        ];

        if (empty($codigo_proveedor)) {
            foreach ($codigos as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    break;
                }
            }
        }

        $codigo_proveedor = trim((string) $codigo_proveedor);
        if ($codigo_proveedor === '') {
            return $result;
        }

        $lookup = null;
        if (class_exists('Riverso_Supplier_Links_Module')) {
            $links = Riverso_Supplier_Links_Module::get_instance();
            $lookup = $links->lookup_by_code($codigo_proveedor, (int) $proveedor_id);
        }

        $local_sku = $this->resolve_local_sku($codigo_proveedor, $lookup, (int) $proveedor_id);

        if (!$local_sku) {
            $mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT sku_local, product_id, product_base_id, supplier_product_id
                 FROM {$prefix}codigos
                 WHERE proveedor_id = %d AND codigo_proveedor = %s AND activo = 1
                 LIMIT 1",
                (int) $proveedor_id,
                $codigo_proveedor
            ), ARRAY_A);

            if ($mapping && !empty($mapping['sku_local'])) {
                $candidate = trim((string) $mapping['sku_local']);
            } else {
                $candidate = '';
            }

            if ($candidate !== ''
                && !riverso_sku_equals_supplier_code($candidate, $codigo_proveedor)
                && riverso_is_trusted_supplier_local_sku($codigo_proveedor, $candidate, $lookup)) {
                $local_sku = $candidate;
                $result['source'] = 'legacy_codigos';
                $result['product_id'] = (int) ($mapping['product_id'] ?: 0) ?: null;
                $result['producto_base_id'] = (int) ($mapping['product_base_id'] ?: 0) ?: null;
                $result['producto_proveedor_id'] = (int) ($mapping['supplier_product_id'] ?: 0) ?: null;
            }
        }

        if ($local_sku) {
            $result['sku_local'] = $local_sku;
            if (!$result['source']) {
                $result['source'] = is_array($lookup) && !empty($lookup['source'])
                    ? $lookup['source']
                    : (riverso_mamut_online_to_local_sku($codigo_proveedor) ? 'mamut_mapping' : null);
            }
            if (!$result['product_id']) {
                $result['product_id'] = $this->resolve_product_id_for_local_sku($local_sku, $codigo_proveedor);
            }
            if (is_array($lookup) && !empty($lookup['domain'])) {
                $result['producto_base_id'] = $result['producto_base_id']
                    ?: ((int) ($lookup['domain']['producto_base_id'] ?? 0) ?: null);
                $result['producto_proveedor_id'] = $result['producto_proveedor_id']
                    ?: ((int) ($lookup['domain']['id'] ?? 0) ?: null);
            }
        }

        $result['sku_online'] = $this->resolve_sku_online(
            $codigo_proveedor,
            $lookup,
            $result['product_id'],
            $result['sku_local']
        );

        return $result;
    }

    /**
     * Corrige ítems de factura vinculados con SKU online en lugar de SKU local.
     */
    public function repair_mislinked_invoice_items($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $scoped = !empty($args['factura_id']) || !empty($args['folio']);
        $where = [
            "(fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)",
            "fi.codigo_proveedor IS NOT NULL",
            "fi.codigo_proveedor != ''",
        ];
        if (!$scoped) {
            $where[] = "(fi.sku_local IS NULL OR fi.sku_local = '' OR fi.sku_local = fi.codigo_proveedor)";
        }
        $params = [];

        if (!empty($args['factura_id'])) {
            $where[] = 'f.id = %d';
            $params[] = (int) $args['factura_id'];
        }
        if (!empty($args['folio'])) {
            $where[] = 'f.folio = %s';
            $params[] = (string) $args['folio'];
        }

        $sql = "SELECT fi.*, f.proveedor_id, f.folio, f.id AS factura_id
                FROM {$prefix}factura_items fi
                INNER JOIN {$prefix}facturas f ON f.id = fi.factura_id
                WHERE " . implode(' AND ', $where);

        $items = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $fixed = 0;
        $cleared = 0;
        $factura_ids = [];

        foreach ($items as $item) {
            $mapping = $this->lookup_product_mapping(
                (int) $item->proveedor_id,
                $item->codigo_proveedor
            );
            $new_sku = $mapping['sku_local'] ?? null;
            $current = trim((string) ($item->sku_local ?? ''));

            if ($new_sku && !riverso_sku_equals_supplier_code($new_sku, $item->codigo_proveedor)) {
                if (strcasecmp($new_sku, $current) === 0) {
                    continue;
                }

                $product_id = $mapping['product_id']
                    ?? $this->resolve_product_id_for_local_sku($new_sku, $item->codigo_proveedor);

                $wpdb->update(
                    "{$prefix}factura_items",
                    [
                        'sku_local' => $new_sku,
                        'product_id' => $product_id,
                        'estado' => 'vinculado',
                    ],
                    ['id' => (int) $item->id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );

                $this->persist_supplier_code(
                    (int) $item->proveedor_id,
                    $item->codigo_proveedor,
                    $item->descripcion ?: $item->nombre,
                    [],
                    $new_sku
                );

                $factura_ids[(int) $item->factura_id] = true;
                $fixed++;
                continue;
            }

            if ($current !== '' && !riverso_is_trusted_supplier_local_sku($item->codigo_proveedor, $current)) {
                $wpdb->update(
                    "{$prefix}factura_items",
                    [
                        'sku_local' => null,
                        'product_id' => null,
                        'estado' => 'pendiente',
                    ],
                    ['id' => (int) $item->id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
                $factura_ids[(int) $item->factura_id] = true;
                $cleared++;
            }
        }

        $proveedor_id = !empty($args['proveedor_id']) ? (int) $args['proveedor_id'] : null;
        if (!$proveedor_id && $scoped && !empty($items[0]->proveedor_id)) {
            $proveedor_id = (int) $items[0]->proveedor_id;
        }

        $codigos_fixed = $this->repair_mislinked_codigos_table($proveedor_id);
        $domain_deactivated = $this->repair_corrupted_domain_mappings($proveedor_id);

        if (!empty($factura_ids)) {
            foreach (array_keys($factura_ids) as $fid) {
                $this->sync_factura_item_status($fid);
            }
        }

        return [
            'items_fixed' => $fixed,
            'items_cleared' => $cleared,
            'items_checked' => count($items),
            'codigos_fixed' => $codigos_fixed,
            'domain_deactivated' => $domain_deactivated,
        ];
    }

    /**
     * Desactiva vínculos de dominio que apuntan a un SKU local no confiable.
     */
    public function repair_corrupted_domain_mappings($proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = 'pp.activo = 1 AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != \'\'';
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND pp.proveedor_id = %d';
            $params[] = (int) $proveedor_id;
        }

        $sql = "SELECT pp.id, pp.codigo_proveedor, pp.proveedor_id, pp.human_product_review,
                       pb.canonical_sku
                FROM {$prefix}producto_proveedor pp
                INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                WHERE {$where}";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        $deactivated = 0;

        foreach ($rows as $row) {
            $canonical = trim((string) ($row->canonical_sku ?? ''));
            if ($canonical === '') {
                continue;
            }
            $lookup = [
                'domain' => [
                    'human_product_review' => $row->human_product_review ?? 'pending',
                ],
            ];
            if (riverso_is_trusted_supplier_local_sku($row->codigo_proveedor, $canonical, $lookup)) {
                continue;
            }
            $wpdb->update(
                "{$prefix}producto_proveedor",
                ['activo' => 0],
                ['id' => (int) $row->id],
                ['%d'],
                ['%d']
            );
            $deactivated++;
        }

        return $deactivated;
    }

    /**
     * Corrige tabla codigos donde sku_local = codigo_proveedor pero existe mapeo Mamut.
     */
    public function repair_mislinked_codigos_table($proveedor_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = "activo = 1 AND codigo_proveedor IS NOT NULL AND codigo_proveedor != ''";
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND proveedor_id = %d';
            $params[] = $proveedor_id;
        }

        $sql = "SELECT id, proveedor_id, codigo_proveedor, nombre_proveedor, sku_local
                FROM {$prefix}codigos WHERE {$where}";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);

        $fixed = 0;
        foreach ($rows as $row) {
            $current = trim((string) ($row->sku_local ?? ''));
            $local = $this->resolve_local_sku($row->codigo_proveedor, null, (int) $row->proveedor_id);

            if ($local && !riverso_sku_equals_supplier_code($local, $row->codigo_proveedor)) {
                if (strcasecmp($local, $current) === 0) {
                    continue;
                }
                $product_id = $this->resolve_product_id_for_local_sku($local, $row->codigo_proveedor);
                $wpdb->update(
                    "{$prefix}codigos",
                    [
                        'sku_local' => $local,
                        'product_id' => $product_id,
                    ],
                    ['id' => (int) $row->id],
                    ['%s', '%d'],
                    ['%d']
                );
                $fixed++;
                continue;
            }

            if ($current !== '' && !riverso_is_trusted_supplier_local_sku($row->codigo_proveedor, $current)) {
                $wpdb->update(
                    "{$prefix}codigos",
                    [
                        'sku_local' => null,
                        'product_id' => null,
                    ],
                    ['id' => (int) $row->id],
                    ['%s', '%d'],
                    ['%d']
                );
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Persiste código interno del proveedor y sincroniza dominio canónico si hay SKU.
     */
    public function persist_supplier_code($proveedor_id, $codigo_proveedor, $descripcion, $codigos = [], $sku_local = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (empty($codigo_proveedor)) {
            return null;
        }

        if ($sku_local && (
            riverso_sku_equals_supplier_code($sku_local, $codigo_proveedor)
            || !riverso_is_trusted_supplier_local_sku($codigo_proveedor, $sku_local)
        )) {
            $sku_local = $this->resolve_local_sku($codigo_proveedor, null, (int) $proveedor_id);
        }

        $codigo_tipo = 'INT1';
        $codigo_barras = null;
        foreach ($codigos as $codigo) {
            if (!empty($codigo['tipo'])) {
                $codigo_tipo = sanitize_text_field($codigo['tipo']);
            }
            $tipo_upper = strtoupper($codigo['tipo'] ?? '');
            if (in_array($tipo_upper, ['EAN13', 'EAN', 'GTIN', 'BARCODE'], true) && !empty($codigo['valor'])) {
                $codigo_barras = sanitize_text_field($codigo['valor']);
            }
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}codigos WHERE proveedor_id = %d AND codigo_proveedor = %s",
            (int) $proveedor_id,
            $codigo_proveedor
        ));

        $product_id = $sku_local ? $this->resolve_product_id_for_local_sku($sku_local, $codigo_proveedor) : null;
        $product_base_id = null;
        $supplier_product_id = null;

        if ($sku_local && $product_id && class_exists('Riverso_Supplier_Links_Module')) {
            $link_data = [
                'supplier_id' => (int) $proveedor_id,
                'supplier_code' => $codigo_proveedor,
                'supplier_description' => $descripcion,
                'supplier_barcode' => $codigo_barras,
                'product_id' => (int) $product_id,
                'is_active' => 1,
                'match_confidence' => 100,
                'notes' => 'Auto-registrado desde factura XML',
            ];

            $links_module = Riverso_Supplier_Links_Module::get_instance();
            $existing_link = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}supplier_product_links
                 WHERE supplier_id = %d AND supplier_code = %s LIMIT 1",
                (int) $proveedor_id,
                $codigo_proveedor
            ));

            if ($existing_link) {
                $links_module->update_link((int) $existing_link, $link_data, 'Actualizado desde factura XML');
            } else {
                $created = $links_module->create_link($link_data);
                if (is_wp_error($created) && $created->get_error_code() === 'duplicate') {
                    $existing_link = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$prefix}supplier_product_links
                         WHERE supplier_id = %d AND supplier_code = %s LIMIT 1",
                        (int) $proveedor_id,
                        $codigo_proveedor
                    ));
                    if ($existing_link) {
                        $links_module->update_link((int) $existing_link, $link_data, 'Actualizado desde factura XML');
                    }
                }
            }

            $mapping = $this->lookup_product_mapping($proveedor_id, $codigo_proveedor);
            $product_base_id = $mapping['producto_base_id'];
            $supplier_product_id = $mapping['producto_proveedor_id'];
        }

        $codigo_payload = [
            'proveedor_id' => (int) $proveedor_id,
            'codigo_proveedor' => $codigo_proveedor,
            'codigo_tipo' => $codigo_tipo,
            'codigo_barras' => $codigo_barras,
            'nombre_proveedor' => $descripcion,
            'sku_local' => $sku_local,
            'product_id' => $product_id,
            'product_base_id' => $product_base_id,
            'supplier_product_id' => $supplier_product_id,
            'activo' => 1,
        ];

        if ($existing) {
            $wpdb->update(
                "{$prefix}codigos",
                array_merge($codigo_payload, ['updated_at' => current_time('mysql')]),
                ['id' => (int) $existing]
            );
        } else {
            $wpdb->insert("{$prefix}codigos", $codigo_payload);
        }

        if (!$supplier_product_id && $sku_local && $product_base_id) {
            $supplier_product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                (int) $proveedor_id,
                $codigo_proveedor
            ));
        }

        return [
            'codigo_id' => $existing ? (int) $existing : (int) $wpdb->insert_id,
            'producto_proveedor_id' => $supplier_product_id ? (int) $supplier_product_id : null,
            'producto_base_id' => $product_base_id ? (int) $product_base_id : null,
        ];
    }

    /**
     * Tabla de vínculos flete ↔ facturas de productos.
     */
    private function flete_vinculos_table() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_factura_flete_vinculos';
    }

    /**
     * Monto total de una factura de flete/transportista.
     */
    public function get_shipping_invoice_amount($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $from_items = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
             WHERE factura_id = %d AND item_tipo = 'envio'",
            (int) $factura_envio_id
        ));
        if ($from_items > 0) {
            return $from_items;
        }

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(monto_total, 0) FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
    }

    /**
     * Recalcula reparto del flete entre todas las facturas de productos vinculadas.
     */
    public function recalculate_flete_allocations($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.factura_productos_id, fp.monto_total
             FROM {$vinculos_table} v
             INNER JOIN {$prefix}facturas fp ON fp.id = v.factura_productos_id
             WHERE v.factura_envio_id = %d",
            (int) $factura_envio_id
        ));

        $shipping_total = $this->get_shipping_invoice_amount((int) $factura_envio_id);
        $base_total = 0.0;
        foreach ($links as $link) {
            $base_total += (float) ($link->monto_total ?? 0);
        }

        $affected_product_ids = [];
        foreach ($links as $link) {
            $share = 0.0;
            if ($shipping_total > 0) {
                if ($base_total > 0) {
                    $share = $shipping_total * ((float) $link->monto_total / $base_total);
                } else {
                    $share = $shipping_total / max(1, count($links));
                }
            }
            $wpdb->update(
                $vinculos_table,
                ['monto_asignado' => round($share, 2)],
                ['id' => (int) $link->id],
                ['%f'],
                ['%d']
            );
            $affected_product_ids[(int) $link->factura_productos_id] = true;
        }

        foreach (array_keys($affected_product_ids) as $producto_id) {
            $assigned = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(monto_asignado), 0) FROM {$vinculos_table} WHERE factura_productos_id = %d",
                (int) $producto_id
            ));
            $inline = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
                 WHERE factura_id = %d AND item_tipo = 'envio'",
                (int) $producto_id
            ));
            $wpdb->update(
                "{$prefix}facturas",
                ['costo_envio_total' => round($assigned + $inline, 2)],
                ['id' => (int) $producto_id],
                ['%f'],
                ['%d']
            );
            $this->prorate_shipping_costs((int) $producto_id);
        }

        return [
            'envio_id' => (int) $factura_envio_id,
            'shipping_total' => $shipping_total,
            'linked_invoices' => count($links),
            'affected_products' => array_keys($affected_product_ids),
        ];
    }

    /**
     * Sincroniza estado legacy de factura de flete según vínculos N:M.
     */
    public function sync_envio_link_state($factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $first_product = $wpdb->get_var($wpdb->prepare(
            "SELECT factura_productos_id FROM {$vinculos_table}
             WHERE factura_envio_id = %d ORDER BY id ASC LIMIT 1",
            (int) $factura_envio_id
        ));
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$vinculos_table} WHERE factura_envio_id = %d",
            (int) $factura_envio_id
        ));

        $wpdb->update(
            "{$prefix}facturas",
            [
                'factura_productos_id' => $first_product ? (int) $first_product : null,
                'estado' => $count > 0 ? 'vinculado' : 'sin_vincular',
            ],
            ['id' => (int) $factura_envio_id],
            ['%d', '%s'],
            ['%d']
        );

        return $count;
    }

    /**
     * Vincula factura de envío (transportista) a factura de productos.
     */
    public function link_shipping_invoice($factura_productos_id, $factura_envio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $producto = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_productos_id
        ));
        if (!$producto) {
            return new WP_Error('not_found', 'Factura de productos no encontrada');
        }
        if (($producto->documento_subtipo ?? 'productos') === 'envio') {
            return new WP_Error('invalid_target', 'La factura destino debe ser de productos, no de flete');
        }

        $envio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
        if (!$envio) {
            return new WP_Error('not_found', 'Factura de envío no encontrada');
        }
        if (($envio->documento_subtipo ?? '') !== 'envio') {
            return new WP_Error('invalid_source', 'La factura origen debe ser de transportista / flete');
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$vinculos_table}
             WHERE factura_envio_id = %d AND factura_productos_id = %d",
            (int) $factura_envio_id,
            (int) $factura_productos_id
        ));
        if ($existing) {
            return new WP_Error('already_linked', 'Este flete ya está vinculado a esa factura de productos');
        }

        $wpdb->insert(
            $vinculos_table,
            [
                'factura_envio_id' => (int) $factura_envio_id,
                'factura_productos_id' => (int) $factura_productos_id,
                'monto_asignado' => 0,
                'created_by' => get_current_user_id() ?: null,
            ],
            ['%d', '%d', '%f', '%d']
        );

        $wpdb->update(
            "{$prefix}facturas",
            ['documento_subtipo' => 'envio'],
            ['id' => (int) $factura_envio_id],
            ['%s'],
            ['%d']
        );

        $result = $this->recalculate_flete_allocations((int) $factura_envio_id);
        $this->sync_envio_link_state((int) $factura_envio_id);

        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'invoice_processed',
                'invoice',
                (int) $factura_envio_id,
                null,
                [
                    'action' => 'flete_vinculado',
                    'factura_productos_id' => (int) $factura_productos_id,
                    'linked_invoices' => $result['linked_invoices'] ?? 1,
                ],
                sprintf(
                    'Flete folio %s vinculado a factura productos folio %s',
                    $envio->folio ?? $factura_envio_id,
                    $producto->folio ?? $factura_productos_id
                )
            );
        }

        return $result;
    }

    /**
     * Desvincula factura de flete de una o todas las facturas de productos.
     */
    public function unlink_shipping_invoice($factura_envio_id, $factura_productos_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $vinculos_table = $this->flete_vinculos_table();

        $envio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_envio_id
        ));
        if (!$envio) {
            return new WP_Error('not_found', 'Factura de flete no encontrada');
        }

        $where = ['factura_envio_id = %d'];
        $params = [(int) $factura_envio_id];
        if ($factura_productos_id) {
            $where[] = 'factura_productos_id = %d';
            $params[] = (int) $factura_productos_id;
        }

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT factura_productos_id FROM {$vinculos_table} WHERE " . implode(' AND ', $where),
            ...$params
        ));

        if (!$links) {
            // Fallback legacy: un solo vínculo en factura_productos_id.
            if (!empty($envio->factura_productos_id)) {
                if ($factura_productos_id && (int) $envio->factura_productos_id !== (int) $factura_productos_id) {
                    return new WP_Error('not_linked', 'Este flete no está vinculado a esa factura');
                }
                $links = [(object) ['factura_productos_id' => (int) $envio->factura_productos_id]];
            } else {
                return new WP_Error('not_linked', 'Este flete no está vinculado a ninguna factura');
            }
        }

        $affected_products = [];
        foreach ($links as $link) {
            $affected_products[(int) $link->factura_productos_id] = true;
        }

        if ($factura_productos_id) {
            $wpdb->delete(
                $vinculos_table,
                [
                    'factura_envio_id' => (int) $factura_envio_id,
                    'factura_productos_id' => (int) $factura_productos_id,
                ],
                ['%d', '%d']
            );
        } else {
            $wpdb->delete($vinculos_table, ['factura_envio_id' => (int) $factura_envio_id], ['%d']);
        }

        $this->recalculate_flete_allocations((int) $factura_envio_id);
        foreach (array_keys($affected_products) as $producto_id) {
            if ((int) $producto_id !== (int) $factura_envio_id) {
                $assigned = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(monto_asignado), 0) FROM {$vinculos_table} WHERE factura_productos_id = %d",
                    (int) $producto_id
                ));
                $inline = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
                     WHERE factura_id = %d AND item_tipo = 'envio'",
                    (int) $producto_id
                ));
                $wpdb->update(
                    "{$prefix}facturas",
                    ['costo_envio_total' => round($assigned + $inline, 2)],
                    ['id' => (int) $producto_id],
                    ['%f'],
                    ['%d']
                );
                $this->prorate_shipping_costs((int) $producto_id);
            }
        }

        $this->sync_envio_link_state((int) $factura_envio_id);

        return true;
    }

    /**
     * Prorratea costo de envío entre ítems de producto (por valor neto de línea).
     */
    public function prorate_shipping_costs($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $inline_shipping = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_total), 0) FROM {$prefix}factura_items
             WHERE factura_id = %d AND item_tipo = 'envio'",
            (int) $factura_id
        ));

        $linked_shipping = (float) ($factura->costo_envio_total ?? 0);
        $total_shipping = $inline_shipping + $linked_shipping;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $product_items = array_filter($items, function ($item) {
            return ($item->item_tipo ?? 'producto') !== 'envio';
        });

        $base_total = 0.0;
        foreach ($product_items as $item) {
            $base_total += (float) $item->monto_total;
        }

        foreach ($product_items as $item) {
            $qty = (float) ($item->qty_received ?: $item->cantidad ?: 1);
            if ($qty <= 0) {
                $qty = 1;
            }

            $product_cost_unit = (float) $item->precio_unitario;
            $shipping_share = 0.0;

            if ($total_shipping > 0 && $base_total > 0) {
                $shipping_share = $total_shipping * ((float) $item->monto_total / $base_total);
            }

            $shipping_per_unit = $shipping_share / $qty;
            $landed_unit = $product_cost_unit + $shipping_per_unit;

            $wpdb->update(
                "{$prefix}factura_items",
                [
                    'costo_envio_prorrateado' => round($shipping_share, 4),
                    'costo_landed_unitario' => round($landed_unit, 4),
                ],
                ['id' => (int) $item->id]
            );
        }

        $wpdb->update(
            "{$prefix}facturas",
            ['envio_prorrateado' => $total_shipping > 0 ? 1 : 0],
            ['id' => (int) $factura_id]
        );

        return [
            'total_shipping' => $total_shipping,
            'items_updated' => count($product_items),
        ];
    }

    /**
     * Crea lote de inventario desde ítem de factura aprobado.
     */
    public function create_lote_from_approved_item($factura, $item) {
        if (!$this->should_update_warehouse($factura)) {
            return null;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (empty($item->sku_local) || ($item->item_tipo ?? 'producto') === 'envio') {
            return null;
        }

        $qty = (float) ($item->qty_received ?: $item->cantidad);
        if ($qty <= 0) {
            return null;
        }

        $landed_unit = (float) ($item->costo_landed_unitario ?: $item->precio_unitario);
        $shipping_unit = 0.0;
        if ($qty > 0 && !empty($item->costo_envio_prorrateado)) {
            $shipping_unit = (float) $item->costo_envio_prorrateado / $qty;
        }

        $persisted = $this->persist_supplier_code(
            (int) $factura->proveedor_id,
            $item->codigo_proveedor,
            $item->descripcion,
            [],
            $item->sku_local
        );

        $producto_proveedor_id = $persisted['producto_proveedor_id'] ?? null;
        if (!$producto_proveedor_id) {
            $producto_proveedor_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                (int) $factura->proveedor_id,
                $item->codigo_proveedor
            ));
        }

        if (!$producto_proveedor_id) {
            return new WP_Error('no_supplier_product', 'No se pudo resolver producto_proveedor para el lote');
        }

        $product_id = wc_get_product_id_by_sku($item->sku_local);
        $wc_product = $product_id ? wc_get_product($product_id) : null;
        $variation_id = 0;
        $parent_id = $product_id;
        if ($wc_product && $wc_product->is_type('variation')) {
            $variation_id = $product_id;
            $parent_id = $wc_product->get_parent_id();
        }

        $lote_codigo = sprintf('FAC-%d-%d', (int) $factura->id, (int) $item->id);

        $existing_lote = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}lotes WHERE lote_codigo = %s LIMIT 1",
            $lote_codigo
        ));
        if ($existing_lote) {
            return (int) $existing_lote;
        }

        $wpdb->insert(
            "{$prefix}lotes",
            [
                'producto_proveedor_id' => (int) $producto_proveedor_id,
                'product_id' => $parent_id,
                'variation_id' => $variation_id ?: null,
                'lote_codigo' => $lote_codigo,
                'fecha_recepcion' => current_time('mysql'),
                'cantidad_inicial' => $qty,
                'cantidad_disponible' => $qty,
                'costo_total' => round($landed_unit * $qty, 2),
                'costo_unitario' => round($landed_unit, 4),
                'costo_envio_unitario' => round($shipping_unit, 4),
                'moneda' => 'CLP',
                'estado' => 'abierto',
                'documento_tipo' => 'factura',
                'documento_id' => (int) $factura->id,
                'documento_item_id' => (int) $item->id,
                'origen_datos' => 'invoice_approval',
                'notas' => sprintf(
                    'Lote desde factura folio %s. Costo producto: %s, envío/u: %s',
                    $factura->folio ?? $factura->id,
                    number_format((float) $item->precio_unitario, 2, ',', '.'),
                    number_format($shipping_unit, 2, ',', '.')
                ),
            ]
        );

        $lote_id = (int) $wpdb->insert_id;
        $producto_base_id = $persisted['producto_base_id'] ?? $wpdb->get_var($wpdb->prepare(
            "SELECT producto_base_id FROM {$prefix}producto_proveedor WHERE id = %d",
            (int) $producto_proveedor_id
        ));

        if ($producto_base_id) {
            do_action('riverso_pos_lote_registrado', (int) $producto_base_id);
        }

        return $lote_id;
    }

    /**
     * Registra entrada de inventario en WooCommerce si está habilitado.
     */
    public function auto_inventory_entry($factura, $item, $lote_id = null) {
        if (!$this->should_update_warehouse($factura)) {
            return null;
        }
        if (!riverso_get_setting('auto_inventory_on_approve', true)) {
            return null;
        }

        if (empty($item->sku_local) || ($item->item_tipo ?? 'producto') === 'envio') {
            return null;
        }

        $qty = (float) ($item->qty_received ?: $item->cantidad);
        if ($qty <= 0) {
            return null;
        }

        $product_id = wc_get_product_id_by_sku($item->sku_local);
        if (!$product_id || !class_exists('Riverso_Warehouse_Module')) {
            return null;
        }

        $warehouse = Riverso_Warehouse_Module::get_instance();
        return $warehouse->record_movement([
            'tipo' => 'entrada',
            'product_id' => $product_id,
            'cantidad' => $qty,
            'referencia_tipo' => 'factura',
            'referencia_id' => (int) $factura->id,
            'notas' => sprintf(
                'Entrada automática factura #%s, ítem %d%s',
                $factura->folio ?? $factura->id,
                (int) $item->id,
                $lote_id ? ", lote {$lote_id}" : ''
            ),
        ]);
    }

    /**
     * Registra historial de costos de todos los ítems de una factura.
     */
    public function record_factura_cost_history($factura_id) {
        if (!class_exists('Riverso_Cost_History_Module')) {
            return ['recorded' => 0, 'pending' => 0];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.*, p.id AS supplier_id FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        if (riverso_get_setting('prorate_shipping_to_products', true)) {
            $this->prorate_shipping_costs((int) $factura_id);
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items
             WHERE factura_id = %d AND (item_tipo = 'producto' OR item_tipo IS NULL)",
            (int) $factura_id
        ));

        $cost_module = Riverso_Cost_History_Module::get_instance();
        $recorded = 0;
        $pending = 0;

        foreach ($items as $item) {
            $qty = (float) ($item->qty_received ?: $item->cantidad ?: 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $landed_unit = (float) ($item->costo_landed_unitario ?: $item->precio_unitario);
            $landed_total = $landed_unit * $qty;
            if ($landed_total <= 0) {
                continue;
            }

            $product_id = null;
            $variation_id = null;
            if (!empty($item->sku_local)) {
                $product_id = wc_get_product_id_by_sku($item->sku_local);
                if ($product_id) {
                    $wc = wc_get_product($product_id);
                    if ($wc && $wc->is_type('variation')) {
                        $variation_id = $product_id;
                    }
                }
            }

            if (!$product_id && empty($item->codigo_proveedor)) {
                continue;
            }

            $result = $cost_module->record_cost([
                'product_id' => $product_id ?: 0,
                'variation_id' => $variation_id,
                'supplier_id' => (int) $factura->supplier_id,
                'source_type' => 'invoice',
                'source_document_id' => (int) $factura_id,
                'source_item_id' => (int) $item->id,
                'supplier_code' => $item->codigo_proveedor,
                'descripcion_proveedor' => $item->descripcion,
                'cost' => $landed_total,
                'quantity' => $qty,
                'costo_producto_unitario' => (float) $item->precio_unitario,
                'costo_envio_prorrateado' => (float) ($item->costo_envio_prorrateado ?? 0),
                'document_date' => $factura->fecha_emision,
                'notes' => $product_id
                    ? sprintf('Costo landed desde factura folio %s', $factura->folio ?? $factura_id)
                    : sprintf('Costo pendiente de vincular — factura folio %s', $factura->folio ?? $factura_id),
            ]);

            if (!is_wp_error($result)) {
                if ($product_id) {
                    $recorded++;
                } else {
                    $pending++;
                }
            }
        }

        return ['recorded' => $recorded, 'pending' => $pending];
    }

    /**
     * Crea tareas para vincular códigos proveedor sin SKU local.
     */
    public function create_supplier_link_tasks($factura_id) {
        if (!class_exists('Riverso_Task_Module')) {
            return [];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT f.id, f.folio, p.nombre AS proveedor_nombre
             FROM {$prefix}facturas f
             LEFT JOIN {$prefix}proveedores p ON p.id = f.proveedor_id
             WHERE f.id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return [];
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, codigo_proveedor, descripcion, sku_local
             FROM {$prefix}factura_items
             WHERE factura_id = %d
               AND (item_tipo = 'producto' OR item_tipo IS NULL)
               AND (sku_local IS NULL OR sku_local = '')
               AND codigo_proveedor IS NOT NULL AND codigo_proveedor != ''",
            (int) $factura_id
        ));

        $task_module = Riverso_Task_Module::get_instance();
        $created = [];
        foreach ($items as $item) {
            $task_id = $task_module->create_missing_code_task(
                (int) $item->id,
                $item->codigo_proveedor,
                $item->descripcion,
                $factura->proveedor_nombre ?? 'Proveedor'
            );
            if ($task_id && !is_wp_error($task_id)) {
                $created[] = (int) $task_id;
            }
        }
        return $created;
    }

    /**
     * Procesa factura en modo solo costos: historial + códigos, sin bodega.
     */
    public function process_cost_only_invoice($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            (int) $factura_id
        ));
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items WHERE factura_id = %d",
            (int) $factura_id
        ), ARRAY_A);

        foreach ($items as $item) {
            if (($item['item_tipo'] ?? 'producto') === 'envio') {
                continue;
            }
            $codigos = [];
            if (!empty($item['codigo_proveedor'])) {
                $codigos[] = ['tipo' => $item['codigo_tipo'] ?? 'INT1', 'valor' => $item['codigo_proveedor']];
            }
            $mapping = $this->lookup_product_mapping(
                (int) $factura->proveedor_id,
                $item['codigo_proveedor'] ?? '',
                $codigos
            );
            $this->persist_supplier_code(
                (int) $factura->proveedor_id,
                $item['codigo_proveedor'] ?? '',
                $item['descripcion'] ?? '',
                $codigos,
                $mapping['sku_local']
            );
        }

        $costs = $this->record_factura_cost_history((int) $factura_id);
        $tasks = $this->create_supplier_link_tasks((int) $factura_id);

        $wpdb->update(
            "{$prefix}facturas",
            ['estado' => 'costos_registrados'],
            ['id' => (int) $factura_id]
        );

        return [
            'costs' => $costs,
            'link_tasks' => count($tasks),
        ];
    }

    /**
     * ¿Debe actualizarse bodega/inventario para esta factura?
     */
    public function should_update_warehouse($factura) {
        $modo = is_object($factura) ? ($factura->modo_ingreso ?? 'recepcion') : ($factura['modo_ingreso'] ?? 'recepcion');
        if ($modo === 'solo_costos') {
            return false;
        }
        return (bool) riverso_get_setting('auto_inventory_on_approve', true);
    }

    /**
     * Procesa ítems al guardar factura: códigos y clasificación.
     */
    public function after_invoice_saved($factura_id, $proveedor_id, array $items, $modo_ingreso = 'recepcion') {
        foreach ($items as $item) {
            if (($item['item_tipo'] ?? 'producto') === 'envio') {
                continue;
            }

            $codigo_proveedor = '';
            foreach ($item['codigos'] ?? [] as $codigo) {
                if (!empty($codigo['valor'])) {
                    $codigo_proveedor = $codigo['valor'];
                    break;
                }
            }

            if (!$codigo_proveedor) {
                continue;
            }

            $mapping = $this->lookup_product_mapping($proveedor_id, $codigo_proveedor, $item['codigos'] ?? []);
            $this->persist_supplier_code(
                $proveedor_id,
                $codigo_proveedor,
                $item['nombre'] ?? '',
                $item['codigos'] ?? [],
                $mapping['sku_local']
            );
        }

        $this->prorate_shipping_costs((int) $factura_id);

        if ($modo_ingreso === 'solo_costos') {
            return $this->process_cost_only_invoice((int) $factura_id);
        }

        if (riverso_get_setting('create_link_task_on_upload', true)) {
            $this->create_supplier_link_tasks((int) $factura_id);
        }

        if (riverso_get_setting('create_reception_task_on_upload', true)
            && class_exists('Riverso_Task_Module')) {
            $task_module = Riverso_Task_Module::get_instance();
            $task_module->create_reception_task((int) $factura_id);
        }

        return null;
    }
}
