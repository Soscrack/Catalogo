<?php

declare(strict_types=1);

interface Riverso_POS_Catalog_Reader {
    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function search_field(array $fields, string $query, int $limit): array;
}
