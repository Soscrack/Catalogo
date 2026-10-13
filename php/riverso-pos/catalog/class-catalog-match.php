<?php
/**
 * Coincidencia de búsqueda rápida.
 * Exacta, luego prefijo (desde 2 caracteres) y contiene (desde 4).
 */

declare(strict_types=1);

final class Riverso_POS_Catalog_Match {
    /**
     * @param array<string, mixed> $product
     * @param list<string> $fields
     */
    public static function score(array $product, array $fields, string $query): ?int {
        $needle = self::lower(trim($query));
        if ($needle === '') {
            return null;
        }
        $best = null;
        $length = function_exists('mb_strlen') ? mb_strlen($needle, 'UTF-8') : strlen($needle);
        foreach ($fields as $field) {
            $value = self::lower(trim((string) ($product[$field] ?? '')));
            if ($value === '') {
                continue;
            }
            $score = null;
            if ($value === $needle) {
                $score = 0;
            } elseif ($length >= 2 && str_starts_with($value, $needle)) {
                $score = 1;
            } elseif ($length >= 4 && str_contains($value, $needle)) {
                $score = 2;
            }
            if ($score !== null) {
                $best = $best === null ? $score : min($best, $score);
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function normalize(array $product): array {
        $cost = $product['unit_cost'] ?? null;
        if ($cost === '') {
            $cost = null;
        }
        $product_id = $product['product_id'] ?? null;
        return [
            'product_id' => $product_id === null || $product_id === '' ? null : (int) $product_id,
            'sku' => trim((string) ($product['sku'] ?? '')),
            'supplier_code' => trim((string) ($product['supplier_code'] ?? '')),
            'barcode' => trim((string) ($product['barcode'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'unit_price' => round((float) ($product['unit_price'] ?? 0), 2),
            'unit_cost' => $cost === null ? null : round((float) $cost, 2),
        ];
    }

    private static function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
