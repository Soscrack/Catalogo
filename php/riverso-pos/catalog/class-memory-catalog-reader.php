<?php

declare(strict_types=1);

final class Riverso_POS_Memory_Catalog_Reader implements Riverso_POS_Catalog_Reader {
    /**
     * @param list<array<string, mixed>> $products
     */
    public function __construct(private array $products) {
    }

    public function search_field(array $fields, string $query, int $limit): array {
        $ranked = [];
        foreach ($this->products as $product) {
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
        return array_slice($rows, 0, max(0, $limit));
    }
}
