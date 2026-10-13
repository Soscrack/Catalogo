<?php
/**
 * Lee el catálogo ya publicado en WooCommerce. No crea productos.
 *
 * SKU: _sku
 * Código de barras: _barcode, _global_unique_id
 * Código proveedor: _riverso_supplier_code, _supplier_sku
 * Precio: _price, _regular_price
 * Costo (solo para margen/utilidad): _riverso_unit_cost, _wc_cog_cost, _alg_wc_cog_cost
 */

declare(strict_types=1);

final class Riverso_POS_Woo_Catalog_Reader implements Riverso_POS_Catalog_Reader {
    public function search_field(array $fields, string $query, int $limit): array {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->posts, $wpdb->postmeta) || !method_exists($wpdb, 'prepare')) {
            return [];
        }
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }
        $meta_keys = [];
        foreach ($fields as $field) {
            foreach (self::meta_keys_for($field) as $key) {
                $meta_keys[] = $key;
            }
        }
        if ($meta_keys === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status = 'publish'
              AND m.meta_key IN ($placeholders)
              AND (m.meta_value = %s OR m.meta_value LIKE %s)
            LIMIT %d";
        $params = array_merge($meta_keys, [$query, $like, max($limit * 4, $limit)]);
        $prepared = $wpdb->prepare($sql, $params);
        $ids = $wpdb->get_col($prepared);
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        return $this->hydrate($wpdb, array_map('intval', $ids), $fields, $query, $limit);
    }

    /**
     * @return list<string>
     */
    public static function meta_keys_for(string $field): array {
        return match ($field) {
            'sku' => ['_sku'],
            'barcode' => ['_barcode', '_global_unique_id'],
            'supplier_code' => ['_riverso_supplier_code', '_supplier_sku'],
            default => [],
        };
    }

    /**
     * @param list<int> $ids
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function hydrate(object $wpdb, array $ids, array $fields, string $query, int $limit): array {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $id_list = implode(',', $ids);
        $posts = $wpdb->get_results(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($id_list)",
            ARRAY_A
        );
        $meta_keys = [
            '_sku',
            '_barcode',
            '_global_unique_id',
            '_riverso_supplier_code',
            '_supplier_sku',
            '_price',
            '_regular_price',
            '_riverso_unit_cost',
            '_wc_cog_cost',
            '_alg_wc_cog_cost',
        ];
        $key_list = "'" . implode("','", $meta_keys) . "'";
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key IN ($key_list)",
            ARRAY_A
        );
        $titles = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            $titles[(int) $post['ID']] = (string) $post['post_title'];
        }
        $meta = [];
        foreach (is_array($meta_rows) ? $meta_rows : [] as $row) {
            $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }

        $ranked = [];
        foreach ($ids as $id) {
            $values = $meta[$id] ?? [];
            $product = [
                'product_id' => $id,
                'sku' => $values['_sku'] ?? '',
                'barcode' => $this->first($values, ['_barcode', '_global_unique_id']),
                'supplier_code' => $this->first($values, ['_riverso_supplier_code', '_supplier_sku']),
                'description' => $titles[$id] ?? '',
                'unit_price' => $this->first_number($values, ['_price', '_regular_price']),
                'unit_cost' => $this->first_optional_number($values, ['_riverso_unit_cost', '_wc_cog_cost', '_alg_wc_cog_cost']),
            ];
            $score = Riverso_POS_Catalog_Match::score($product, $fields, $query);
            if ($score === null) {
                continue;
            }
            $ranked[] = [
                'score' => $score,
                'product' => Riverso_POS_Catalog_Match::normalize($product),
            ];
        }
        usort($ranked, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        $rows = array_map(static fn (array $row): array => $row['product'], $ranked);
        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $keys
     */
    private function first(array $values, array $keys): string {
        foreach ($keys as $key) {
            if (isset($values[$key]) && trim($values[$key]) !== '') {
                return trim($values[$key]);
            }
        }
        return '';
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $keys
     */
    private function first_number(array $values, array $keys): float {
        $raw = $this->first($values, $keys);
        return $raw === '' ? 0.0 : (float) $raw;
    }

    /**
     * @param array<string, string> $values
     * @param list<string> $keys
     */
    private function first_optional_number(array $values, array $keys): ?float {
        $raw = $this->first($values, $keys);
        return $raw === '' ? null : (float) $raw;
    }
}
