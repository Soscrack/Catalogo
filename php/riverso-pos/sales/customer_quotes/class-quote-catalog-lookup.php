<?php
/**
 * Búsqueda de productos para cotizaciones.
 * Rápida: SKU | código proveedor | código de barras.
 * Avanzada (mode=advanced): scopes todo | descripcion | codigos.
 * Usa WooCommerce postmeta existentes (no crea productos ni copia catálogo).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Catalog_Lookup {
    /**
     * @param string $query
     * @param int    $limit
     * @param string $mode  quick|advanced
     * @param string $scope todo|descripcion|codigos (solo advanced)
     * @return array
     */
    public function search($query, $limit = 20, $mode = 'quick', $scope = 'todo') {
        global $wpdb;
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        $mode = $mode === 'advanced' ? 'advanced' : 'quick';
        $scope = $this->normalize_scope($scope);
        if ($query === '' || !isset($wpdb->posts, $wpdb->postmeta)) {
            return array();
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo') && $this->mb_len($query) < 2 && $scope === 'descripcion') {
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
        return $this->hydrate($ids, $query, $limit, $mode, $scope);
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
              AND p.post_status IN ('publish', 'private')
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
        // Nombre (post_title) + extracto + contenido. Descripción/Todo deben
        // encontrar «Tornillo» aunque viva en el título del producto.
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private')
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
        // Padres variables: el título está en el padre y el SKU en la variación.
        // Expandir a variaciones publicadas para que hydrate (exige _sku) no las descarte.
        $ids = $this->expand_variable_parents_to_variations($ids, $cap);
        return $ids;
    }

    /**
     * Si un ID es producto variable (o padre sin _sku), incluye sus variaciones.
     * @param array $ids
     * @param int   $cap
     * @return array
     */
    private function expand_variable_parents_to_variations(array $ids, $cap) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $out = $ids;
        $id_list = implode(',', array_map('intval', $ids));
        // Padres cuyo post_type es product y tienen hijos variation.
        $children = $wpdb->get_col(
            "SELECT c.ID
             FROM {$wpdb->posts} c
             INNER JOIN {$wpdb->posts} parent ON parent.ID = c.post_parent
             WHERE c.post_parent IN ($id_list)
               AND c.post_type = 'product_variation'
               AND c.post_status IN ('publish', 'private')
               AND parent.post_type = 'product'
             LIMIT " . (int) max($cap * 2, $cap)
        );
        if (is_array($children)) {
            foreach ($children as $cid) {
                $out[] = (int) $cid;
            }
        }
        // También: si matcheó una variation, incluirla (ya está); si el padre
        // matcheó por título pero la variation no está en $ids, ya la agregamos.
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
        // Variaciones suelen tener título vacío: heredar nombre del padre.
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
        // Códigos (y rápida) nunca puntúan por descripción.
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
            // Prefer codes over description when tying.
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
