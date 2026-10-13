<?php

declare(strict_types=1);

final class Riverso_POS_Supplier_Code_Lookup {
    public function __construct(private Riverso_POS_Catalog_Reader $reader) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array {
        return $this->reader->search_field(['supplier_code'], $query, $limit);
    }
}
