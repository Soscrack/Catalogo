<?php
/**
 * Búsqueda rápida para cotizaciones: SKU | código proveedor | código de barras.
 * Usa WooCommerce postmeta existentes (no crea productos ni copia catálogo).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Catalog_Lookup {
    public function search($query, $limit = 20) {
        global $wpdb;
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        if ($query === '' || !isset($wpdb->posts, $wpdb->postmeta)) {
            return array();
        }

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
        if (!is_array($ids) || $ids === array()) {
            return array();
        }
        return $this->hydrate(array_map('intval', $ids), $query, $limit);
    }

    private function hydrate(array $ids, $query, $limit) {
        global $wpdb;
        $ids = array_values(array_filter($ids, static function ($id) { return (int) $id > 0; }));
        if ($ids === array()) {
            return array();
        }
        $id_list = implode(',', $ids);
        $posts = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($id_list)", ARRAY_A);
        $meta_keys = array('_sku','_barcode','_global_unique_id','_riverso_supplier_code','_supplier_sku','_price','_regular_price','_riverso_unit_cost','_wc_cog_cost','_alg_wc_cog_cost');
        $key_list = "'" . implode("','", $meta_keys) . "'";
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key IN ($key_list)",
            ARRAY_A
        );
        $titles = array();
        foreach (is_array($posts) ? $posts : array() as $post) {
            $titles[(int) $post['ID']] = (string) $post['post_title'];
        }
        $meta = array();
        foreach (is_array($meta_rows) ? $meta_rows : array() as $row) {
            $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }
        $q = strtolower($query);
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
            $score = $this->score($product, $q);
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

    private function score(array $product, $q) {
        $best = null;
        foreach (array('sku', 'supplier_code', 'barcode') as $field) {
            $value = strtolower(trim((string) (isset($product[$field]) ? $product[$field] : '')));
            if ($value === '') { continue; }
            if ($value === $q) { $score = 0; }
            elseif (strpos($value, $q) === 0) { $score = 1; }
            elseif (strpos($value, $q) !== false) { $score = 2; }
            else { continue; }
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