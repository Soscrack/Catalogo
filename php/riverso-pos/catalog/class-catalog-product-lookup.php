<?php
/**
 * Búsqueda rápida de la cotización.
 * Delega en los módulos de catálogo (SKU, código proveedor, código de barras)
 * y no guarda una copia del catálogo.
 */

declare(strict_types=1);

final class Riverso_POS_Catalog_Product_Lookup {
    public function __construct(
        private Riverso_POS_Product_Lookup $products,
        private Riverso_POS_Barcode_Lookup $barcodes,
        private Riverso_POS_Supplier_Code_Lookup $suppliers
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array {
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }
        $merged = [];
        $buckets = [
            $this->products->search($query, $limit),
            $this->suppliers->search($query, $limit),
            $this->barcodes->search($query, $limit),
        ];
        foreach ($buckets as $rows) {
            foreach ($rows as $row) {
                $key = (string) ($row['product_id'] ?? '') . '|' . strtolower((string) ($row['sku'] ?? ''));
                $score = Riverso_POS_Catalog_Match::score(
                    $row,
                    ['sku', 'supplier_code', 'barcode'],
                    $query
                );
                if ($score === null) {
                    continue;
                }
                if (!isset($merged[$key]) || $score < $merged[$key]['_score']) {
                    $row['_score'] = $score;
                    $merged[$key] = $row;
                }
            }
        }
        $list = array_values($merged);
        usort($list, static fn (array $a, array $b): int => $a['_score'] <=> $b['_score']);
        foreach ($list as &$row) {
            unset($row['_score']);
        }
        unset($row);
        $list = array_slice($list, 0, $limit);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('riverso_catalog_search_products', $list, $query);
            if (is_array($filtered)) {
                $list = array_values($filtered);
            }
        }
        return $list;
    }
}
