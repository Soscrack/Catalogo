<?php
/**
 * Búsqueda de productos para cotizaciones.
 * channel=online: WooCommerce publish|private (SKU|proveedor|barcode + lupa).
 * channel=local: Riverso product quick-view (producto_base) + pricing/familia POS.
 * No crea catálogo paralelo ni productos.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Catalog_Lookup {
    /**
     * @param string $query
     * @param int    $limit
     * @param string $mode    quick|advanced
     * @param string $scope   todo|descripcion|codigos (solo advanced)
     * @param string $channel local|online
     * @return array
     */
    public function search($query, $limit = 20, $mode = 'quick', $scope = 'todo', $channel = 'local') {
        $channel = $this->normalize_channel($channel);
        if ($channel === 'local') {
            return $this->search_local($query, $limit, $mode, $scope);
        }
        return $this->search_online($query, $limit, $mode, $scope);
    }

    /**
     * @param string $channel
     * @return string
     */
    public function normalize_channel($channel) {
        $channel = strtolower(trim((string) $channel));
        return $channel === 'online' ? 'online' : 'local';
    }

    /**
     * Online = lookup Woo actual (publish|private).
     */
    private function search_online($query, $limit = 20, $mode = 'quick', $scope = 'todo') {
        global $wpdb;
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        $mode = $mode === 'advanced' ? 'advanced' : 'quick';
        $scope = $this->normalize_scope($scope);
        if ($query === '' || !isset($wpdb->posts, $wpdb->postmeta)) {
            return array();
        }
        if ($mode === 'advanced' && $scope === 'descripcion' && $this->mb_len($query) < 2) {
            return array();
        }

        $ids = array();
        if ($mode === 'quick' || $scope === 'codigos' || $scope === 'todo') {
            $ids = array_merge($ids, $this->ids_by_codes($query, $limit));
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo') && $this->mb_len($query) >= 2) {
            $ids = array_merge($ids, $this->ids_by_description($query, $limit));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $rows = $this->hydrate($ids, $query, $limit, $mode, $scope);
        foreach ($rows as &$row) {
            $row['channel'] = 'online';
            $row = $this->enrich_online_pricing($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Local = quick-view (producto_base) + get_local_price / family helpers.
     */
    private function search_local($query, $limit = 20, $mode = 'quick', $scope = 'todo') {
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        $mode = $mode === 'advanced' ? 'advanced' : 'quick';
        $scope = $this->normalize_scope($scope);
        if ($query === '') {
            return array();
        }
        if ($mode === 'advanced' && $scope === 'descripcion' && $this->mb_len($query) < 2) {
            return array();
        }

        $this->ensure_quick_view();
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            // Fallback legacy: tienda-local (ayuda), no catálogo paralelo.
            return $this->search_local_via_tienda($query, $limit);
        }

        $qv = Riverso_Product_Quick_View_Service::get_instance();
        $field = 'todos';
        if ($mode === 'quick' || $scope === 'codigos') {
            $field = 'codigos';
        } elseif ($scope === 'descripcion') {
            $field = 'nombre';
        }

        if ($mode === 'quick') {
            $hits = $qv->lookup_for_quotes($query, $limit);
            if (!$hits && $this->mb_len($query) >= 2) {
                $hits = $qv->search_for_quotes($query, 'todos', $limit);
            }
        } else {
            $hits = $qv->search_for_quotes($query, $field, $limit);
        }

        if (!is_array($hits) || !$hits) {
            // Legacy help only if quick-view vacío.
            $legacy = $this->search_local_via_tienda($query, $limit);
            if ($legacy) {
                return $legacy;
            }
            return array();
        }

        $out = array();
        foreach ($hits as $hit) {
            $mapped = $this->map_local_hit($hit);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array $hit hydrate_grid_rows shape
     * @return array|null
     */
    private function map_local_hit(array $hit) {
        $pb_id = isset($hit['id']) ? (int) $hit['id'] : (isset($hit['producto_base_id']) ? (int) $hit['producto_base_id'] : 0);
        if ($pb_id <= 0) {
            return null;
        }
        $sku = isset($hit['canonical_sku']) ? (string) $hit['canonical_sku'] : (isset($hit['sku']) ? (string) $hit['sku'] : '');
        if ($sku === '') {
            $sku = 'PB-' . $pb_id;
        }

        $wc_id = $this->resolve_wc_product_id($pb_id);
        $price_pack = $this->local_price_pack($pb_id, 1.0);
        $family = $this->family_offers_for_base($pb_id);

        $has_local_price = ((float) $price_pack['unit_price']) > 0;
        return array(
            'product_id' => $wc_id > 0 ? $wc_id : null,
            'producto_base_id' => $pb_id,
            'sku' => $sku,
            'barcode' => isset($hit['barcode']) ? (string) $hit['barcode'] : '',
            'supplier_code' => isset($hit['codigo_proveedor']) ? (string) $hit['codigo_proveedor'] : (isset($hit['supplier_code']) ? (string) $hit['supplier_code'] : ''),
            'description' => isset($hit['nombre']) ? (string) $hit['nombre'] : (isset($hit['description']) ? (string) $hit['description'] : $sku),
            'unit_price' => $price_pack['unit_price'],
            'unit_cost' => $price_pack['unit_cost'],
            'has_local_price' => $has_local_price,
            'sin_precio_local' => !$has_local_price,
            'p_asignado' => $price_pack['p_asignado'],
            'local_price' => $price_pack['local_price'],
            'channel' => 'local',
            'local_only' => $wc_id <= 0,
            'family_mode' => isset($family['default_mode']) ? $family['default_mode'] : 'unitaria',
            'units_per_pack' => isset($family['units_per_pack']) ? $family['units_per_pack'] : 1.0,
            'packaging' => isset($family['packaging']) ? $family['packaging'] : '',
            'family' => $family,
            'facturar_ok' => $wc_id > 0,
        );
    }

    /**
     * Legacy help: tienda-local search → misma forma CQ.
     */
    private function search_local_via_tienda($query, $limit) {
        if (!class_exists('Riverso_Tienda_Local_Module')) {
            return array();
        }
        $mod = Riverso_Tienda_Local_Module::get_instance();
        if (!method_exists($mod, 'search')) {
            return array();
        }
        $result = $mod->search($query);
        $items = is_array($result) && isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $out = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pb = isset($item['producto_base_id']) ? (int) $item['producto_base_id'] : 0;
            if ($pb <= 0) {
                continue;
            }
            $mapped = $this->map_local_hit(array(
                'id' => $pb,
                'canonical_sku' => isset($item['sku']) ? $item['sku'] : '',
                'nombre' => isset($item['nombre']) ? $item['nombre'] : (isset($item['name']) ? $item['name'] : ''),
                'barcode' => isset($item['barcode']) ? $item['barcode'] : (isset($item['matched_barcode']) ? $item['matched_barcode'] : ''),
                'codigo_proveedor' => isset($item['codigo_proveedor']) ? $item['codigo_proveedor'] : '',
            ));
            if ($mapped !== null) {
                $out[] = $mapped;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Precio local + regla de familia (qty agregada).
     *
     * @param int   $producto_base_id
     * @param float $family_qty
     * @return array{unit_price:float,unit_cost:?float,local_price:?float,rule_price:?float,p_asignado:?float,c_ref:?float}
     */
    public function local_price_pack($producto_base_id, $family_qty = 1.0) {
        $producto_base_id = (int) $producto_base_id;
        $family_qty = (float) $family_qty;
        if ($family_qty <= 0) {
            $family_qty = 1.0;
        }
        $unit_price = 0.0;
        $unit_cost = null;
        $local_price = null;
        $rule_price = null;
        $p_asignado = null;
        $c_ref = null;

        if ($producto_base_id > 0 && class_exists('Riverso_Pricing_Module')) {
            $pricing = Riverso_Pricing_Module::get_instance();
            $row = $pricing->get_local_price($producto_base_id);
            if (is_array($row)) {
                if (isset($row['p_asignado']) && $row['p_asignado'] !== null && $row['p_asignado'] !== '') {
                    $p_asignado = (float) $row['p_asignado'];
                    if (!isset($row['estado_aprobacion']) || $row['estado_aprobacion'] === 'aprobado' || $row['estado_aprobacion'] === '' || $row['estado_aprobacion'] === null) {
                        $local_price = $p_asignado;
                        $unit_price = $p_asignado;
                    }
                }
                if (isset($row['c_ref']) && $row['c_ref'] !== null && $row['c_ref'] !== '') {
                    $c_ref = (float) $row['c_ref'];
                    $unit_cost = $c_ref;
                }
            }
            if (class_exists('Riverso_Price_Rules_Module')) {
                $rp = Riverso_Price_Rules_Module::get_instance()->apply_for_base($producto_base_id, $family_qty, $local_price);
                if ($rp !== null) {
                    $rule_price = (float) $rp;
                    $unit_price = $rule_price;
                }
            }
        }

        $unit_price_r = round((float) $unit_price, 2);
        return array(
            'unit_price' => $unit_price_r,
            'unit_cost' => $unit_cost === null ? null : round((float) $unit_cost, 2),
            'local_price' => $local_price,
            'rule_price' => $rule_price,
            'p_asignado' => $p_asignado,
            'c_ref' => $c_ref,
            'has_local_price' => $unit_price_r > 0,
            'sin_precio_local' => $unit_price_r <= 0,
            'family_qty' => $family_qty,
            'producto_base_id' => $producto_base_id,
        );
    }

    /**
     * Ofertas Unitaria / Pack / Kit desde family + commercial + unit services.
     *
     * @param int $producto_base_id
     * @return array
     */
    public function family_offers_for_base($producto_base_id) {
        $producto_base_id = (int) $producto_base_id;
        $offers = array(
            'grupo_id' => null,
            'default_mode' => 'unitaria',
            'modes' => array('unitaria'),
            'unitaria' => null,
            'pack' => null,
            'kit' => null,
            'units_per_pack' => 1.0,
            'packaging' => 'unitaria',
            'envases' => array(),
            'pack_tiers' => array(),
            'n_a_pack_kit' => true,
            'n_a_reason' => 'Sin familia comercial Pack/Kit para este producto.',
        );
        if ($producto_base_id <= 0) {
            return $offers;
        }

        $grupo_id = 0;
        if (class_exists('Riverso_Family_Module')) {
            $fam = Riverso_Family_Module::get_instance()->get_exacta_family_of_product($producto_base_id);
            if (is_array($fam) && !empty($fam['grupo_id'])) {
                $grupo_id = (int) $fam['grupo_id'];
                $offers['grupo_id'] = $grupo_id;
                $offers['family_name'] = isset($fam['nombre']) ? (string) $fam['nombre'] : '';
                $offers['family_code'] = isset($fam['codigo_grupo']) ? (string) $fam['codigo_grupo'] : '';
            }
        }

        $envase = null;
        if (class_exists('Riverso_Unit_Product_Service')) {
            $unit_svc = Riverso_Unit_Product_Service::get_instance();
            $envase = $unit_svc->get_canonical_envase($producto_base_id);
            if (is_array($envase)) {
                $qty = isset($envase['cantidad_unidades']) ? (float) $envase['cantidad_unidades'] : 1.0;
                if ($qty <= 0) {
                    $qty = 1.0;
                }
                $offers['units_per_pack'] = $qty;
                $offers['envases'][] = array(
                    'envase_id' => isset($envase['id']) ? (int) $envase['id'] : 0,
                    'tipo_envase' => isset($envase['tipo_envase']) ? (string) $envase['tipo_envase'] : 'envase',
                    'cantidad_unidades' => $qty,
                    'label' => (isset($envase['tipo_envase']) ? (string) $envase['tipo_envase'] : 'envase') . ' × ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                );
            }
            if ($grupo_id > 0) {
                $unit_snap = $unit_svc->get_unit_snapshot($grupo_id);
                if (!is_wp_error($unit_snap) && is_array($unit_snap)) {
                    $offers['unitaria'] = array(
                        'available' => true,
                        'unit_producto_base_id' => isset($unit_snap['unit_producto_base_id']) ? (int) $unit_snap['unit_producto_base_id'] : (isset($unit_snap['unit']['id']) ? (int) $unit_snap['unit']['id'] : null),
                        'snapshot' => $unit_snap,
                    );
                    $packs = $unit_svc->get_pack_members_by_qty($grupo_id);
                    if (is_array($packs) && $packs) {
                        $offers['pack_members'] = $packs;
                    }
                }
            }
        }

        if ($grupo_id > 0 && class_exists('Riverso_Family_Commercial_Service')) {
            $commercial = Riverso_Family_Commercial_Service::get_instance()->get_snapshot($grupo_id);
            if (!is_wp_error($commercial) && is_array($commercial)) {
                $tipo = isset($commercial['tipo_comercial']) ? (string) $commercial['tipo_comercial'] : '';
                $offers['tipo_comercial'] = $tipo;
                $offers['commercial'] = array(
                    'tipo_comercial' => $tipo,
                    'pack_modo' => isset($commercial['pack_modo']) ? $commercial['pack_modo'] : null,
                    'pack_tiers' => isset($commercial['pack_tiers']) ? $commercial['pack_tiers'] : array(),
                    'kit_components' => isset($commercial['kit_components']) ? $commercial['kit_components'] : array(),
                    'kit_pricing' => isset($commercial['kit_pricing']) ? $commercial['kit_pricing'] : null,
                );
                if ($tipo === 'pack' && !empty($commercial['pack_tiers'])) {
                    $offers['modes'][] = 'pack';
                    $offers['pack'] = array(
                        'available' => true,
                        'tiers' => $commercial['pack_tiers'],
                    );
                    $offers['pack_tiers'] = $commercial['pack_tiers'];
                    $offers['n_a_pack_kit'] = false;
                    $offers['n_a_reason'] = '';
                }
                if ($tipo === 'kit' && !empty($commercial['kit_components'])) {
                    $offers['modes'][] = 'kit';
                    $offers['kit'] = array(
                        'available' => true,
                        'components' => $commercial['kit_components'],
                        'pricing' => isset($commercial['kit_pricing']) ? $commercial['kit_pricing'] : null,
                    );
                    $offers['n_a_pack_kit'] = false;
                    $offers['n_a_reason'] = '';
                }
                if ($tipo === 'unitario' || $tipo === '') {
                    $offers['default_mode'] = 'unitaria';
                } elseif ($tipo === 'pack') {
                    $offers['default_mode'] = 'pack';
                } elseif ($tipo === 'kit') {
                    $offers['default_mode'] = 'kit';
                }
            }
        }

        $offers['modes'] = array_values(array_unique($offers['modes']));
        return $offers;
    }

    /**
     * @param int $producto_base_id
     * @return int WC product id or 0
     */
    public function resolve_wc_product_id($producto_base_id) {
        global $wpdb;
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return 0;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base WHERE id = %d LIMIT 1",
            $producto_base_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return 0;
        }
        $var = isset($row['woocommerce_variation_id']) ? (int) $row['woocommerce_variation_id'] : 0;
        $pid = isset($row['woocommerce_product_id']) ? (int) $row['woocommerce_product_id'] : 0;
        if ($var > 0) {
            return $var;
        }
        return $pid > 0 ? $pid : 0;
    }

    private function enrich_online_pricing(array $row) {
        $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
        if ($pid <= 0 || !class_exists('Riverso_Pricing_Module')) {
            return $row;
        }
        $pricing = Riverso_Pricing_Module::get_instance();
        $base_id = 0;
        if (method_exists($pricing, 'get_base_id_by_wc')) {
            $base_id = (int) $pricing->get_base_id_by_wc($pid, 0);
            if ($base_id <= 0 && function_exists('wc_get_product')) {
                $p = wc_get_product($pid);
                if ($p && $p->is_type('variation')) {
                    $base_id = (int) $pricing->get_base_id_by_wc((int) $p->get_parent_id(), $pid);
                }
            }
        }
        if ($base_id > 0) {
            $row['producto_base_id'] = $base_id;
            $online = $pricing->get_online_price($base_id, $pid);
            if (is_array($online) && isset($online['p_asignado']) && $online['p_asignado'] !== null
                && (!isset($online['estado_aprobacion']) || $online['estado_aprobacion'] === 'aprobado')
            ) {
                $row['unit_price'] = round((float) $online['p_asignado'], 2);
                $row['online_price'] = (float) $online['p_asignado'];
            }
        }
        $row['local_only'] = false;
        $row['facturar_ok'] = true;
        return $row;
    }

    private function ensure_quick_view() {
        if (class_exists('Riverso_Product_Quick_View_Service')) {
            return;
        }
        $path = defined('RIVERSO_POS_PLUGIN_DIR')
            ? RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-quick-view-service.php'
            : dirname(__FILE__) . '/../../modules/products/class-product-quick-view-service.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private function normalize_scope($scope) {
        $scope = strtolower(trim((string) $scope));
        if ($scope === 'descripcion' || $scope === 'codigos') {
            return $scope;
        }
        return 'todo';
    }

    private function mb_len($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function ids_by_codes($query, $limit) {
        global $wpdb;
        $meta_keys = array('_sku', '_barcode', '_global_unique_id', '_riverso_supplier_code', '_supplier_sku');
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
              AND m.meta_key IN ($placeholders)
              AND (m.meta_value = %s OR m.meta_value LIKE %s)
            LIMIT %d";
        $params = array_merge($meta_keys, array($query, $like, max($limit * 4, $limit)));
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        return is_array($ids) ? $ids : array();
    }

    private function ids_by_description($query, $limit) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
              AND (
                    p.post_title LIKE %s
                 OR p.post_excerpt LIKE %s
                 OR p.post_content LIKE %s
              )
            LIMIT %d";
        $cap = max($limit * 4, $limit);
        $ids = $wpdb->get_col($wpdb->prepare($sql, $like, $like, $like, $cap));
        if (!is_array($ids)) {
            $ids = array();
        }
        $ids = $this->expand_variable_parents_to_variations($ids, $cap);
        return $ids;
    }

    private function expand_variable_parents_to_variations(array $ids, $cap) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $out = $ids;
        $id_list = implode(',', array_map('intval', $ids));
        $children = $wpdb->get_col(
            "SELECT c.ID
             FROM {$wpdb->posts} c
             INNER JOIN {$wpdb->posts} parent ON parent.ID = c.post_parent
             WHERE c.post_parent IN ($id_list)
               AND c.post_type = 'product_variation'
               AND c.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
               AND parent.post_type = 'product'
             LIMIT " . (int) max($cap * 2, $cap)
        );
        if (is_array($children)) {
            foreach ($children as $cid) {
                $out[] = (int) $cid;
            }
        }
        $out = array_values(array_unique(array_map('intval', $out)));
        if (count($out) > $cap * 2) {
            $out = array_slice($out, 0, $cap * 2);
        }
        return $out;
    }

    private function hydrate(array $ids, $query, $limit, $mode, $scope) {
        global $wpdb;
        $ids = array_values(array_filter($ids, static function ($id) { return (int) $id > 0; }));
        if ($ids === array()) {
            return array();
        }
        $id_list = implode(',', $ids);
        $posts = $wpdb->get_results("SELECT ID, post_title, post_parent, post_type FROM {$wpdb->posts} WHERE ID IN ($id_list)", ARRAY_A);
        $meta_keys = array('_sku','_barcode','_global_unique_id','_riverso_supplier_code','_supplier_sku','_price','_regular_price','_riverso_unit_cost','_wc_cog_cost','_alg_wc_cog_cost');
        $key_list = "'" . implode("','", $meta_keys) . "'";
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key IN ($key_list)",
            ARRAY_A
        );
        $titles = array();
        $parents = array();
        foreach (is_array($posts) ? $posts : array() as $post) {
            $pid = (int) $post['ID'];
            $titles[$pid] = (string) $post['post_title'];
            $parents[$pid] = (int) (isset($post['post_parent']) ? $post['post_parent'] : 0);
        }
        $need_parent = array();
        foreach ($parents as $pid => $parent_id) {
            if ($parent_id > 0 && trim($titles[$pid]) === '') {
                $need_parent[] = $parent_id;
            }
        }
        if ($need_parent) {
            $need_parent = array_values(array_unique($need_parent));
            $plist = implode(',', array_map('intval', $need_parent));
            $prows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($plist)", ARRAY_A);
            $ptitles = array();
            foreach (is_array($prows) ? $prows : array() as $prow) {
                $ptitles[(int) $prow['ID']] = (string) $prow['post_title'];
            }
            foreach ($parents as $pid => $parent_id) {
                if ($parent_id > 0 && trim($titles[$pid]) === '' && isset($ptitles[$parent_id])) {
                    $titles[$pid] = $ptitles[$parent_id];
                }
            }
        }
        $meta = array();
        foreach (is_array($meta_rows) ? $meta_rows : array() as $row) {
            $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }
        $q = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        $ranked = array();
        foreach ($ids as $id) {
            $values = isset($meta[$id]) ? $meta[$id] : array();
            $sku = isset($values['_sku']) ? $values['_sku'] : '';
            if ($sku === '') { continue; }
            $product = array(
                'product_id' => $id,
                'sku' => $sku,
                'barcode' => $this->first($values, array('_barcode', '_global_unique_id')),
                'supplier_code' => $this->first($values, array('_riverso_supplier_code', '_supplier_sku')),
                'description' => isset($titles[$id]) ? $titles[$id] : '',
                'unit_price' => $this->first_number($values, array('_price', '_regular_price')),
                'unit_cost' => $this->first_optional_number($values, array('_riverso_unit_cost', '_wc_cog_cost', '_alg_wc_cog_cost')),
            );
            $score = $this->score($product, $q, $mode, $scope);
            if ($score === null) { continue; }
            $product['_score'] = $score;
            $ranked[] = $product;
        }
        usort($ranked, static function ($a, $b) { return $a['_score'] <=> $b['_score']; });
        $ranked = array_slice($ranked, 0, $limit);
        foreach ($ranked as &$row) { unset($row['_score']); }
        unset($row);
        return $ranked;
    }

    private function score(array $product, $q, $mode, $scope) {
        $best = null;
        $fields = array();
        if ($mode === 'quick' || $scope === 'codigos' || $scope === 'todo') {
            $fields = array_merge($fields, array('sku', 'supplier_code', 'barcode'));
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo')) {
            $fields[] = 'description';
        }
        if ($mode === 'quick' || $scope === 'codigos') {
            $fields = array('sku', 'supplier_code', 'barcode');
        }
        foreach ($fields as $field) {
            $raw = isset($product[$field]) ? $product[$field] : '';
            $value = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $raw), 'UTF-8') : strtolower(trim((string) $raw));
            if ($value === '') { continue; }
            if ($value === $q) { $score = 0; }
            elseif (strpos($value, $q) === 0) { $score = 1; }
            elseif (strpos($value, $q) !== false) { $score = 2; }
            else { continue; }
            if ($field === 'description') { $score += 10; }
            if ($best === null || $score < $best) { $best = $score; }
        }
        return $best;
    }

    private function first(array $values, array $keys) {
        foreach ($keys as $key) {
            if (!empty($values[$key])) { return (string) $values[$key]; }
        }
        return '';
    }

    private function first_number(array $values, array $keys) {
        foreach ($keys as $key) {
            if (isset($values[$key]) && $values[$key] !== '' && is_numeric($values[$key])) {
                return round((float) $values[$key], 2);
            }
        }
        return 0.0;
    }

    private function first_optional_number(array $values, array $keys) {
        foreach ($keys as $key) {
            if (isset($values[$key]) && $values[$key] !== '' && is_numeric($values[$key])) {
                return round((float) $values[$key], 2);
            }
        }
        return null;
    }
}
