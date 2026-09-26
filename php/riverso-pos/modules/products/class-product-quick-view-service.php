<?php
/**
 * Vista rápida del Hub de Productos: lookup exacto, búsqueda parcial y resumen.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Product_Quick_View_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register() {
        add_action('wp_ajax_riverso_products_quick_lookup', [$this, 'ajax_quick_lookup']);
        add_action('wp_ajax_riverso_products_quick_search', [$this, 'ajax_quick_search']);
        add_action('wp_ajax_riverso_products_quick_summary', [$this, 'ajax_quick_summary']);
        add_action('wp_ajax_riverso_products_quick_update_name', [$this, 'ajax_quick_update_name']);
    }

    private function require_view() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    /**
     * Búsqueda exacta: SKU local, barcode o código proveedor → productos locales activos.
     */
    public function ajax_quick_lookup() {
        $this->require_view();
        $code = isset($_POST['code']) ? trim(sanitize_text_field(wp_unslash($_POST['code']))) : '';
        if ($code === '') {
            wp_send_json_error(['message' => 'Código requerido']);
        }

        $ids = $this->resolve_exact_product_ids($code);
        $items = $this->hydrate_grid_rows($ids, $code);

        $related_ids = [];
        if (strlen($code) >= 2) {
            $related_ids = $this->search_product_ids($code, 'codigos', 30);
            $exact_set = [];
            foreach ($ids as $eid) {
                $exact_set[(int) $eid] = true;
            }
            $related_ids = array_values(array_filter($related_ids, function ($rid) use ($exact_set) {
                return empty($exact_set[(int) $rid]);
            }));
        }
        $related = $this->hydrate_grid_rows($related_ids, $code);

        wp_send_json_success([
            'query' => $code,
            'count' => count($items),
            'items' => $items,
            'related' => $related,
            'related_count' => count($related),
        ]);
    }

    /**
     * Búsqueda parcial (lupa) por campo.
     */
    public function ajax_quick_search() {
        $this->require_view();
        $term = isset($_POST['term']) ? trim(sanitize_text_field(wp_unslash($_POST['term']))) : '';
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : 'todos';
        $limit = min(40, max(5, absint($_POST['limit'] ?? 25)));

        $palabras_raw = $_POST['palabras'] ?? [];
        if (!is_array($palabras_raw)) {
            $palabras_raw = $palabras_raw !== '' && $palabras_raw !== null
                ? [$palabras_raw]
                : [];
        }
        $palabras = [];
        $seen = [];
        foreach ($palabras_raw as $palabra) {
            $w = trim(sanitize_text_field(wp_unslash((string) $palabra)));
            if ($w === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($w, 'UTF-8') : strtolower($w);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $palabras[] = $w;
            if (count($palabras) >= 10) {
                break;
            }
        }

        if (strlen($term) < 2 && empty($palabras)) {
            wp_send_json_error(['message' => 'Escribí al menos 2 caracteres o agregá una palabra']);
        }

        $allowed = ['todos', 'nombre', 'proveedor', 'codigo_proveedor', 'sku', 'barcode', 'codigos'];
        if (!in_array($field, $allowed, true)) {
            $field = 'todos';
        }

        $ids = $this->search_product_ids($term, $field, $limit, $palabras);
        $items = $this->hydrate_grid_rows($ids, $term !== '' ? $term : null);

        wp_send_json_success([
            'term' => $term,
            'field' => $field,
            'palabras' => $palabras,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * Payload completo para el visualizador resumido.
     */
    public function ajax_quick_summary() {
        $this->require_view();
        $id = absint($_POST['producto_base_id'] ?? $_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'producto_base_id requerido']);
        }

        $summary = $this->build_summary($id);
        if (is_wp_error($summary)) {
            wp_send_json_error(['message' => $summary->get_error_message()]);
        }

        wp_send_json_success($summary);
    }

    /**
     * Actualiza solo el nombre canónico (edición rápida).
     */
    public function ajax_quick_update_name() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $product_id = absint($_POST['producto_id'] ?? $_POST['producto_base_id'] ?? 0);
        $nombre = isset($_POST['nombre'])
            ? sanitize_text_field(wp_unslash($_POST['nombre']))
            : sanitize_text_field(wp_unslash($_POST['nombre_canonico'] ?? ''));
        $nombre = trim($nombre);

        if (!$product_id) {
            wp_send_json_error(['message' => 'producto_id requerido']);
        }
        if ($nombre === '') {
            wp_send_json_error(['message' => 'Nombre requerido']);
        }

        $old = $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre_canonico, canonical_sku FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $product_id
        ), ARRAY_A);
        if (!$old) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }

        $updated = $wpdb->update(
            "{$prefix}producto_base",
            [
                'nombre_canonico' => $nombre,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $product_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($updated === false) {
            wp_send_json_error(['message' => 'No se pudo guardar el nombre']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_name_updated', 'producto_base', $product_id, [
                'actor_type' => 'human',
                'old_value' => ['nombre_canonico' => $old['nombre_canonico'] ?? ''],
                'new_value' => ['nombre_canonico' => $nombre],
                'details' => 'Nombre actualizado desde Búsqueda rápida',
            ]);
        }

        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('product.updated', [
                'id' => $product_id,
                'canonical_sku' => $old['canonical_sku'] ?? '',
                'nombre_canonico' => $nombre,
            ], ['source' => 'product_quick_update_name']);
        }

        $summary = $this->build_summary($product_id);
        if (is_wp_error($summary)) {
            wp_send_json_success([
                'message' => 'Nombre actualizado',
                'nombre' => $nombre,
            ]);
        }

        wp_send_json_success([
            'message' => 'Nombre actualizado',
            'nombre' => $nombre,
            'summary' => $summary,
        ]);
    }

    /**
     * @param string $code
     * @return int[]
     */
    private function resolve_exact_product_ids($code) {
        global $wpdb;
        $prefix = $this->prefix();
        $found = [];

        // 1) SKU local exacto
        $sku_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE estado = 'activo'
               AND deleted_at IS NULL
               AND (
                    canonical_sku = %s
                 OR TRIM(LEADING '0' FROM canonical_sku) = TRIM(LEADING '0' FROM %s)
               )
             LIMIT 10",
            $code,
            $code
        )) ?: [];
        foreach ($sku_ids as $pid) {
            $found[(int) $pid] = true;
        }

        // 2) Barcode
        if (class_exists('Riverso_Barcode_Model')) {
            $bundle = Riverso_Barcode_Model::resolve_with_suggestions($code);
            if (!empty($bundle['match']['producto_base_id'])) {
                $found[(int) $bundle['match']['producto_base_id']] = true;
            }
            foreach ((array) ($bundle['suggestions'] ?? []) as $sug) {
                if (!empty($sug['producto_base_id'])) {
                    $found[(int) $sug['producto_base_id']] = true;
                }
            }
        }

        // 3) Código proveedor (lookup + posibles múltiples filas)
        $normalized = ltrim($code, '0');
        if ($normalized === '') {
            $normalized = '0';
        }
        $pp_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pp.producto_base_id
             FROM {$prefix}producto_proveedor pp
             INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
             WHERE pp.activo = 1
               AND pb.estado = 'activo'
               AND pb.deleted_at IS NULL
               AND (
                    pp.codigo_proveedor = %s
                 OR pp.codigo_barras_proveedor = %s
                 OR TRIM(LEADING '0' FROM pp.codigo_proveedor) = %s
                 OR TRIM(LEADING '0' FROM pp.codigo_barras_proveedor) = %s
               )
             LIMIT 20",
            $code,
            $code,
            $normalized,
            $normalized
        )) ?: [];
        foreach ($pp_ids as $pid) {
            $found[(int) $pid] = true;
        }

        if (class_exists('Riverso_Supplier_Links_Module')) {
            $lookup = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code($code);
            if (is_array($lookup) && !empty($lookup['found'])) {
                $domain = $lookup['domain'] ?? null;
                if (is_array($domain) && !empty($domain['producto_base_id'])) {
                    $found[(int) $domain['producto_base_id']] = true;
                }
                // Legacy link may only have Woo product_id — try map via producto_base
                if (!empty($lookup['link']['internal_sku'])) {
                    $sku = (string) $lookup['link']['internal_sku'];
                    $pid = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$prefix}producto_base
                         WHERE canonical_sku = %s AND estado = 'activo' AND deleted_at IS NULL
                         LIMIT 1",
                        $sku
                    ));
                    if ($pid) {
                        $found[$pid] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param string $term
     * @param string $field
     * @param int    $limit
     * @param array  $palabras Palabras que deben aparecer todas en nombre_canonico (AND).
     * @return int[]
     */
    private function search_product_ids($term, $field, $limit, array $palabras = []) {
        global $wpdb;
        $prefix = $this->prefix();
        $term = trim((string) $term);
        $like = $term !== '' ? '%' . $wpdb->esc_like($term) . '%' : '';
        $ids = [];

        $base_where = "pb.estado = 'activo' AND pb.deleted_at IS NULL";
        foreach ($palabras as $palabra) {
            $w = trim((string) $palabra);
            if ($w === '') {
                continue;
            }
            $base_where .= $wpdb->prepare(
                ' AND pb.nombre_canonico LIKE %s',
                '%' . $wpdb->esc_like($w) . '%'
            );
        }

        // Solo palabras: una consulta sobre producto_base.
        if ($term === '' && !empty($palabras)) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT pb.id FROM {$prefix}producto_base pb
                 WHERE {$base_where}
                 ORDER BY pb.nombre_canonico ASC
                 LIMIT %d",
                $limit
            )) ?: [];
            $unique = [];
            foreach ($rows as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $unique[$id] = true;
                }
                if (count($unique) >= $limit) {
                    break;
                }
            }
            return array_keys($unique);
        }

        if ($field === 'sku' || $field === 'todos' || $field === 'codigos') {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT pb.id FROM {$prefix}producto_base pb
                 WHERE {$base_where} AND pb.canonical_sku LIKE %s
                 ORDER BY pb.canonical_sku ASC
                 LIMIT %d",
                $like,
                $limit
            )) ?: [];
            $ids = array_merge($ids, $rows);
        }

        if ($field === 'nombre' || $field === 'todos' || $field === 'codigos') {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT pb.id FROM {$prefix}producto_base pb
                 WHERE {$base_where} AND pb.nombre_canonico LIKE %s
                 ORDER BY pb.nombre_canonico ASC
                 LIMIT %d",
                $like,
                $limit
            )) ?: [];
            $ids = array_merge($ids, $rows);
        }

        if ($field === 'barcode' || $field === 'todos' || $field === 'codigos') {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT cb.producto_base_id
                 FROM {$prefix}codigo_barra cb
                 INNER JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                 WHERE {$base_where}
                   AND cb.activo = 1
                   AND cb.codigo LIKE %s
                 LIMIT %d",
                $like,
                $limit
            )) ?: [];
            $ids = array_merge($ids, $rows);
        }

        if ($field === 'codigo_proveedor' || $field === 'todos' || $field === 'codigos') {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pp.producto_base_id
                 FROM {$prefix}producto_proveedor pp
                 INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 WHERE {$base_where}
                   AND pp.activo = 1
                   AND (pp.codigo_proveedor LIKE %s OR pp.codigo_barras_proveedor LIKE %s)
                 LIMIT %d",
                $like,
                $like,
                $limit
            )) ?: [];
            $ids = array_merge($ids, $rows);
        }

        if ($field === 'proveedor' || $field === 'todos') {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pp.producto_base_id
                 FROM {$prefix}producto_proveedor pp
                 INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 INNER JOIN {$prefix}proveedores pr ON pr.id = pp.proveedor_id
                 LEFT JOIN {$prefix}proveedor_apodos pa ON pa.proveedor_id = pr.id
                 WHERE {$base_where}
                   AND pp.activo = 1
                   AND (pr.nombre LIKE %s OR pa.apodo LIKE %s OR pp.nombre_proveedor LIKE %s)
                 LIMIT %d",
                $like,
                $like,
                $like,
                $limit
            )) ?: [];
            $ids = array_merge($ids, $rows);
        }

        $unique = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $unique[$id] = true;
            }
            if (count($unique) >= $limit) {
                break;
            }
        }
        return array_keys($unique);
    }

    /**
     * @param int[]       $ids
     * @param string|null $term Si hay término, prioriza barcode/código proveedor que lo contienen.
     * @return array
     */
    private function hydrate_grid_rows(array $ids, $term = null) {
        global $wpdb;
        $prefix = $this->prefix();
        if (!$ids) {
            return [];
        }

        $ids = array_map('absint', $ids);
        $ids = array_values(array_filter($ids));
        if (!$ids) {
            return [];
        }

        $term = $term !== null ? trim((string) $term) : '';
        $like = $term !== '' ? '%' . $wpdb->esc_like($term) . '%' : '';

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             WHERE pb.id IN ({$placeholders})
               AND pb.estado = 'activo'
               AND pb.deleted_at IS NULL",
            $ids
        ), ARRAY_A) ?: [];

        $by_id = [];
        foreach ($products as $p) {
            $by_id[(int) $p['id']] = $p;
        }

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($by_id[$id])) {
                continue;
            }
            $p = $by_id[$id];

            if ($like !== '') {
                $supplier = $wpdb->get_row($wpdb->prepare(
                    "SELECT pr.nombre AS proveedor_nombre, pp.codigo_proveedor
                     FROM {$prefix}producto_proveedor pp
                     INNER JOIN {$prefix}proveedores pr ON pr.id = pp.proveedor_id
                     WHERE pp.producto_base_id = %d AND pp.activo = 1
                     ORDER BY
                        CASE WHEN pp.codigo_proveedor LIKE %s OR pp.codigo_barras_proveedor LIKE %s THEN 0 ELSE 1 END,
                        pp.es_preferido DESC, pp.id ASC
                     LIMIT 1",
                    $id,
                    $like,
                    $like
                ), ARRAY_A);

                $barcode = $wpdb->get_var($wpdb->prepare(
                    "SELECT codigo FROM {$prefix}codigo_barra
                     WHERE producto_base_id = %d AND activo = 1
                       AND estado IN ('verificado', 'propuesto')
                     ORDER BY CASE WHEN codigo LIKE %s THEN 0 ELSE 1 END, id ASC
                     LIMIT 1",
                    $id,
                    $like
                ));
            } else {
                $supplier = $wpdb->get_row($wpdb->prepare(
                    "SELECT pr.nombre AS proveedor_nombre, pp.codigo_proveedor
                     FROM {$prefix}producto_proveedor pp
                     INNER JOIN {$prefix}proveedores pr ON pr.id = pp.proveedor_id
                     WHERE pp.producto_base_id = %d AND pp.activo = 1
                     ORDER BY pp.es_preferido DESC, pp.id ASC
                     LIMIT 1",
                    $id
                ), ARRAY_A);

                $barcode = $wpdb->get_var($wpdb->prepare(
                    "SELECT codigo FROM {$prefix}codigo_barra
                     WHERE producto_base_id = %d AND activo = 1
                       AND estado IN ('verificado', 'propuesto')
                     ORDER BY id ASC
                     LIMIT 1",
                    $id
                ));
            }

            $price = $wpdb->get_var($wpdb->prepare(
                "SELECT p_asignado FROM {$prefix}precios
                 WHERE producto_base_id = %d AND canal = 'local' AND woocommerce_variation_id = 0
                 LIMIT 1",
                $id
            ));

            $stock = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(cantidad),0) FROM {$prefix}producto_ubicacion WHERE product_id = %d",
                $id
            ));

            $rows[] = [
                'id' => $id,
                'canonical_sku' => (string) ($p['canonical_sku'] ?? ''),
                'nombre' => (string) ($p['nombre_canonico'] ?? ''),
                'proveedor' => (string) ($supplier['proveedor_nombre'] ?? ''),
                'codigo_proveedor' => (string) ($supplier['codigo_proveedor'] ?? ''),
                'barcode' => (string) ($barcode ?: ''),
                'precio' => $price !== null ? (float) $price : null,
                'stock' => $stock,
            ];
        }

        return $rows;
    }

    /**
     * @param int $producto_base_id
     * @return array|WP_Error
     */
    public function build_summary($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $id = absint($producto_base_id);

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, marca, facto_iva_tipo,
                    familia_decision, emparejamiento_decision, estado,
                    es_unidad_minima
             FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $id
        ), ARRAY_A);

        if (!$product) {
            return new WP_Error('not_found', 'Producto no encontrado');
        }

        $prod_mod = class_exists('Riverso_Product_Module')
            ? Riverso_Product_Module::get_instance()
            : null;

        $barcodes_raw = $prod_mod ? $prod_mod->get_product_barcodes($id) : [];
        $barcode_pack = $this->normalize_barcodes_for_summary($barcodes_raw);
        $barcodes = $barcode_pack['barcodes'];
        $tasks = $prod_mod ? $prod_mod->get_product_tasks($id) : [];
        $tasks = $this->enrich_legacy_barcode_tasks($tasks, $barcodes_raw, $id);

        $suppliers = $this->load_suppliers($id);

        $remap_ctx = null;
        if ($prod_mod) {
            $ctx_pack = $prod_mod->build_barcode_remap_context($id, $product);
            $remap_ctx = $ctx_pack['barcode_remap_context'];
        }

        $pricing = null;
        if (class_exists('Riverso_Price_Lookup_Service')) {
            $pricing = Riverso_Price_Lookup_Service::get_instance()->get_local_price_pack($id);
        }

        $stock = null;
        $locations = ['preferidas' => [], 'actuales' => [], 'historial' => []];
        if (class_exists('Riverso_Inventory_Count_Module')) {
            $inv = Riverso_Inventory_Count_Module::get_instance();
            $stock = $inv->get_stock_status_for_product($id);
            $locations = $inv->get_product_locations_data($id);
        }

        $family = null;
        if (class_exists('Riverso_Family_Module')) {
            $fam = Riverso_Family_Module::get_instance()->get_exacta_family_of_product($id);
            if ($fam) {
                $member_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT em.id FROM {$prefix}equivalence_members em
                     WHERE em.grupo_id = %d AND em.producto_base_id = %d AND em.activo = 1
                     LIMIT 1",
                    (int) $fam['grupo_id'],
                    $id
                ));
                $miembros = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}equivalence_members
                     WHERE grupo_id = %d AND activo = 1",
                    (int) $fam['grupo_id']
                ));
                $family = [
                    'grupo_id' => (int) $fam['grupo_id'],
                    'member_id' => $member_id,
                    'codigo' => (string) ($fam['codigo_grupo'] ?? ''),
                    'nombre' => (string) ($fam['nombre'] ?? ''),
                    'miembros_count' => $miembros,
                ];
            }
        }

        $emparejamiento = null;
        if (class_exists('Riverso_Emparejamiento_Module')) {
            $emp = Riverso_Emparejamiento_Module::get_instance()->get_of_product($id);
            if ($emp) {
                $miembros = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}emparejamiento_miembros
                     WHERE emparejamiento_id = %d AND activo = 1",
                    (int) $emp['id']
                ));
                $emparejamiento = [
                    'id' => (int) $emp['id'],
                    'codigo' => (string) ($emp['codigo'] ?? ''),
                    'nombre' => (string) ($emp['nombre'] ?? ''),
                    'miembros_count' => $miembros,
                    'emparejar_precios' => !empty($emp['emparejar_precios']),
                    'emparejar_stock' => !empty($emp['emparejar_stock']),
                ];
            }
        }

        $alerts = $this->build_alerts($product, $barcodes, $suppliers, $pricing, $stock, $family, $emparejamiento, $tasks);

        return [
            'product' => [
                'id' => (int) $product['id'],
                'canonical_sku' => (string) ($product['canonical_sku'] ?? ''),
                'nombre' => (string) ($product['nombre_canonico'] ?? ''),
                'marca' => (string) ($product['marca'] ?? ''),
                'facto_iva_tipo' => (string) ($product['facto_iva_tipo'] ?? 'afecto'),
                'familia_decision' => $product['familia_decision'] ?? null,
                'emparejamiento_decision' => $product['emparejamiento_decision'] ?? null,
            ],
            'barcodes' => $barcodes,
            'supplier_codes_as_barcode' => $barcode_pack['supplier_codes'],
            'barcode_warnings' => $barcode_pack['warnings'],
            'suppliers' => $suppliers,
            'pricing' => $pricing,
            'stock' => $stock,
            'locations' => [
                'preferidas' => $locations['preferidas'] ?? [],
                'actuales' => $locations['actuales'] ?? [],
            ],
            'family' => $family,
            'emparejamiento' => $emparejamiento,
            'tasks' => $tasks,
            'alerts' => $alerts,
            'barcode_remap_context' => $remap_ctx,
            'can_manage' => current_user_can('riverso_manage_products') || current_user_can('manage_options'),
            'can_manage_prices' => current_user_can('riverso_manage_prices') || current_user_can('manage_options'),
            'can_manage_families' => current_user_can('riverso_manage_families') || current_user_can('manage_options'),
            'can_edit_stock' => current_user_can('riverso_edit_stock') || current_user_can('manage_options'),
            'can_edit_locations' => current_user_can('riverso_edit_warehouse')
                || current_user_can('riverso_edit_stock')
                || current_user_can('manage_options'),
            'urls' => [
                'categories_families' => admin_url('admin.php?page=riverso-pos-categories'),
                'edit_hub' => admin_url('admin.php?page=riverso-pos-products&tab=busqueda&action=detail&id=' . $id),
            ],
        ];
    }

    /**
     * Adjunta legacy_barcode a tareas confirmar_barcode_legacy.
     *
     * @param array $tasks
     * @param array $barcodes_raw
     * @param int   $product_id
     * @return array
     */
    private function enrich_legacy_barcode_tasks(array $tasks, array $barcodes_raw, $product_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $by_id = [];
        $by_code = [];
        foreach ($barcodes_raw as $b) {
            $bid = absint($b['id'] ?? 0);
            if ($bid) {
                $by_id[$bid] = $b;
            }
            $code = strtoupper(trim((string) ($b['codigo'] ?? '')));
            if ($code !== '' && !isset($by_code[$code])) {
                $by_code[$code] = $b;
            }
        }

        foreach ($tasks as &$task) {
            if (($task['tipo'] ?? '') !== 'confirmar_barcode_legacy') {
                continue;
            }
            $extra = is_array($task['datos_extra'] ?? null) ? $task['datos_extra'] : [];
            $barcode = null;
            $ref_tipo = (string) ($task['referencia_tipo'] ?? '');
            $ref_id = absint($task['referencia_id'] ?? 0);
            $candidate_id = 0;
            if ($ref_tipo === 'codigo_barra' && $ref_id) {
                $candidate_id = $ref_id;
            } elseif (!empty($extra['barcode_id'])) {
                $candidate_id = absint($extra['barcode_id']);
            } elseif (!empty($extra['codigo_id'])) {
                $candidate_id = absint($extra['codigo_id']);
            }

            if ($candidate_id && isset($by_id[$candidate_id])) {
                $barcode = $by_id[$candidate_id];
            } elseif ($candidate_id) {
                $barcode = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, codigo, tipo, estado, origen_datos, cantidad, activo,
                            migrado_de_tabla, legacy_ref, producto_base_id
                     FROM {$prefix}codigo_barra WHERE id = %d",
                    $candidate_id
                ), ARRAY_A) ?: null;
            }

            if (!$barcode) {
                $code_hint = trim((string) ($extra['codigo'] ?? $extra['barcode'] ?? ''));
                if ($code_hint === '' && !empty($task['titulo'])) {
                    // Título típico: "Confirmar código legacy KPX1"
                    if (preg_match('/legacy\s+(.+)$/iu', (string) $task['titulo'], $m)) {
                        $code_hint = trim($m[1]);
                    }
                }
                $key = strtoupper($code_hint);
                if ($key !== '' && isset($by_code[$key])) {
                    $barcode = $by_code[$key];
                }
            }

            if ($barcode) {
                $task['legacy_barcode'] = [
                    'id' => (int) ($barcode['id'] ?? 0),
                    'codigo' => (string) ($barcode['codigo'] ?? ''),
                    'tipo' => (string) ($barcode['tipo'] ?? ''),
                    'estado' => (string) ($barcode['estado'] ?? ''),
                    'origen_datos' => (string) ($barcode['origen_datos'] ?? ''),
                    'cantidad' => isset($barcode['cantidad']) ? (float) $barcode['cantidad'] : 1,
                    'is_legacy' => class_exists('Riverso_Barcode_Model')
                        ? Riverso_Barcode_Model::is_legacy_row($barcode)
                        : true,
                ];
            } else {
                $task['legacy_barcode'] = null;
            }
        }
        unset($task);

        return $tasks;
    }

    /**
     * @param int $producto_base_id
     * @return array
     */
    private function load_suppliers($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.*, pr.nombre AS proveedor_nombre, c.nombre AS catalogo_nombre
             FROM {$prefix}producto_proveedor pp
             LEFT JOIN {$prefix}proveedores pr ON pr.id = pp.proveedor_id
             LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
             WHERE pp.producto_base_id = %d AND pp.activo = 1
             ORDER BY pp.es_preferido DESC, pp.id ASC",
            absint($producto_base_id)
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $apodos = [];
            $proveedor_id = absint($row['proveedor_id'] ?? 0);
            if ($proveedor_id) {
                $apodos = $wpdb->get_col($wpdb->prepare(
                    "SELECT apodo FROM {$prefix}proveedor_apodos
                     WHERE proveedor_id = %d
                     ORDER BY apodo ASC",
                    $proveedor_id
                )) ?: [];
            }
            $row['apodos'] = $apodos;
            $nombre = (string) ($row['proveedor_nombre'] ?: $row['nombre_proveedor'] ?: '');
            $apodo_str = $apodos ? ' (' . implode(', ', $apodos) . ')' : '';
            $row['display_name'] = $nombre . $apodo_str;
            $row['fuente_display'] = function_exists('riverso_pp_origen_label')
                ? riverso_pp_origen_label($row)
                : (string) ($row['origen_datos'] ?? 'manual');
            $row['vinculo'] = function_exists('riverso_pp_vinculo_info')
                ? riverso_pp_vinculo_info($row)
                : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Deduplica barcodes, separa tipo supplier y avisa ean13 no numéricos.
     *
     * @param array $barcodes
     * @return array{barcodes:array,supplier_codes:array,warnings:array}
     */
    private function normalize_barcodes_for_summary(array $barcodes) {
        $seen = [];
        $bar = [];
        $supplier = [];
        $warnings = [];

        foreach ($barcodes as $b) {
            if (!empty($b['inactivo'])) {
                continue;
            }
            $code = trim((string) ($b['codigo'] ?? ''));
            if ($code === '') {
                continue;
            }
            $key = strtoupper($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $tipo = strtolower((string) ($b['tipo'] ?? ''));
            $b['is_legacy'] = class_exists('Riverso_Barcode_Model')
                ? Riverso_Barcode_Model::is_legacy_row($b)
                : false;
            if ($tipo === 'supplier') {
                $supplier[] = $b;
                continue;
            }

            if (in_array($tipo, ['ean13', 'ean', 'gtin'], true) && !preg_match('/^\d+$/', $code)) {
                $warnings[] = [
                    'code' => 'ean13_no_numerico',
                    'message' => "«{$code}» está marcado como EAN13 pero no es numérico (¿código proveedor?).",
                    'codigo' => $code,
                ];
            }
            $bar[] = $b;
        }

        return [
            'barcodes' => $bar,
            'supplier_codes' => $supplier,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<int,array{level:string,code:string,message:string}>
     */
    private function build_alerts($product, $barcodes, $suppliers, $pricing, $stock, $family, $emparejamiento, $tasks) {
        $alerts = [];

        $active_barcodes = array_filter((array) $barcodes, function ($b) {
            return empty($b['inactivo']);
        });
        if (!$active_barcodes) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'sin_barcode',
                'message' => 'Sin código de barras activo',
            ];
        }

        if (!$suppliers) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'sin_codigo_proveedor',
                'message' => 'Sin código de proveedor',
            ];
        }

        $precio = is_array($pricing) ? ($pricing['p_asignado'] ?? null) : null;
        $costo = is_array($pricing) ? ($pricing['c_ref_bruto'] ?? $pricing['c_ref'] ?? null) : null;
        if ($precio === null || (float) $precio <= 0) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'sin_precio',
                'message' => 'Sin precio local asignado',
            ];
        }
        if ($costo === null || (float) $costo <= 0) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'sin_costo',
                'message' => 'Sin costo de referencia',
            ];
        }
        if (is_array($pricing) && !empty($pricing['alerta_margen'])) {
            $alerts[] = [
                'level' => 'critical',
                'code' => 'margen_bajo',
                'message' => 'Margen bajo el mínimo comercial',
            ];
        }

        if (is_array($stock)) {
            $total = (float) ($stock['stock_total'] ?? 0);
            if ($total < 0) {
                $alerts[] = [
                    'level' => 'critical',
                    'code' => 'stock_negativo',
                    'message' => 'Stock negativo (' . $total . ')',
                ];
            }
            if (!empty($stock['critico'])) {
                $alerts[] = [
                    'level' => 'critical',
                    'code' => 'stock_critico',
                    'message' => 'Stock bajo el nivel crítico',
                ];
            } elseif (!empty($stock['alerta'])) {
                $alerts[] = [
                    'level' => 'warning',
                    'code' => 'stock_minimo',
                    'message' => 'Stock bajo el mínimo',
                ];
            }
            $estado = (string) ($stock['estado_inventariado'] ?? '');
            if ($estado === 'desconocido') {
                $alerts[] = [
                    'level' => 'warning',
                    'code' => 'stock_desconocido',
                    'message' => 'Stock sin conteo registrado',
                ];
            }
            $conf = (string) ($stock['estado_confianza'] ?? '');
            if ($conf === 'dudoso') {
                $alerts[] = [
                    'level' => 'critical',
                    'code' => 'confianza_dudosa',
                    'message' => 'Confianza de stock dudosa',
                ];
            } elseif ($conf === 'poco_confiable') {
                $alerts[] = [
                    'level' => 'warning',
                    'code' => 'confianza_baja',
                    'message' => 'Confianza de stock poco confiable',
                ];
            }
        }

        $fam_dec = $product['familia_decision'] ?? null;
        if ($fam_dec === 'requiere' && !$family) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'familia_pendiente',
                'message' => 'Familia pendiente de asignar',
            ];
        } elseif ($fam_dec === null || $fam_dec === '' || $fam_dec === 'pendiente') {
            // Solo aviso suave si hay tarea preguntar_familia
            foreach ((array) $tasks as $t) {
                if (($t['tipo'] ?? '') === 'preguntar_familia') {
                    $alerts[] = [
                        'level' => 'warning',
                        'code' => 'familia_preguntar',
                        'message' => 'Falta responder si necesita familia',
                    ];
                    break;
                }
            }
        }

        $emp_dec = $product['emparejamiento_decision'] ?? null;
        if ($emp_dec === 'conflicto' || $emp_dec === 'pendiente') {
            $alerts[] = [
                'level' => $emp_dec === 'conflicto' ? 'critical' : 'warning',
                'code' => 'emparejamiento_' . $emp_dec,
                'message' => $emp_dec === 'conflicto'
                    ? 'Conflicto de emparejamiento'
                    : 'Emparejamiento pendiente',
            ];
        }

        return $alerts;
    }
}
