<?php
/**
 * Generador de EAN13 personalizado - Riverso POS.
 *
 * Formato de negocio (prefijo interno 2):
 *
 *   2 SSSSSS QQQQQ X
 *   |  |      |    |
 *   |  |      |    +-- dígito verificador (estándar GS1)
 *   |  |      +------- cantidad (5 dígitos)
 *   |  +-------------- SKU (6 dígitos)
 *   +----------------- prefijo fijo "2" (uso interno)
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_EAN13_Generator {

    const PREFIX = '2';

    /**
     * Calcula el dígito verificador EAN13 sobre los primeros 12 dígitos.
     *
     * @param string $twelve 12 dígitos
     * @return int
     */
    public static function check_digit($twelve) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelve[$i];
            $sum += $digit * (($i % 2 === 0) ? 1 : 3);
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Construye un EAN13 a partir de un SKU y una cantidad.
     *
     * @param string|int $sku
     * @param int        $cantidad
     * @return string|WP_Error EAN13 de 13 dígitos o error si no es representable
     */
    public static function build($sku, $cantidad) {
        $sku = trim((string) $sku);
        if (!preg_match('/^\d{1,6}$/', $sku)) {
            return new WP_Error(
                'ean_sku_not_representable',
                'El SKU debe ser numérico y tener como máximo 6 dígitos. No se truncó el valor.'
            );
        }

        $quantity = self::normalize_quantity($cantidad);
        if (is_wp_error($quantity)) {
            return $quantity;
        }

        return self::build_from_payload(
            str_pad($sku, 6, '0', STR_PAD_LEFT),
            $quantity
        );
    }

    /**
     * Genera un EAN para un producto base. Para SKU no representables asigna
     * un alias estable con payload 1IIIII.
     *
     * @param int $producto_base_id
     * @param int $cantidad
     * @return string|WP_Error
     */
    public static function build_for_product($producto_base_id, $cantidad) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$product) {
            return new WP_Error('ean_product_not_found', 'Producto base no encontrado.');
        }

        if (preg_match('/^\d{1,6}$/', (string) $product['canonical_sku'])) {
            return self::build($product['canonical_sku'], $cantidad);
        }

        $quantity = self::normalize_quantity($cantidad);
        if (is_wp_error($quantity)) {
            return $quantity;
        }

        $payload = self::get_or_create_alias_payload($producto_base_id);
        if (is_wp_error($payload)) {
            return $payload;
        }

        return self::build_from_payload($payload, $quantity);
    }

    private static function build_from_payload($payload, $cantidad) {
        $qty_part = str_pad((string) $cantidad, 5, '0', STR_PAD_LEFT);
        $twelve = self::PREFIX . $payload . $qty_part;
        $check = self::check_digit($twelve);
        return $twelve . $check;
    }

    private static function normalize_quantity($cantidad) {
        if (!is_numeric($cantidad) || (float) $cantidad <= 0 || (float) $cantidad > 99999) {
            return new WP_Error('ean_quantity_out_of_range', 'La cantidad debe estar entre 1 y 99.999.');
        }
        if (floor((float) $cantidad) !== (float) $cantidad) {
            return new WP_Error('ean_quantity_not_integer', 'El EAN interno solo admite cantidades enteras.');
        }
        return intval($cantidad);
    }

    private static function get_or_create_alias_payload($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $table = "{$prefix}ean_aliases";

        $payload = $wpdb->get_var($wpdb->prepare(
            "SELECT payload FROM {$table} WHERE producto_base_id = %d AND activo = 1",
            $producto_base_id
        ));
        if ($payload) {
            return $payload;
        }

        $wpdb->insert(
            $table,
            [
                'producto_base_id' => $producto_base_id,
                'alias_tipo' => '1',
                'activo' => 1,
            ],
            ['%d', '%s', '%d']
        );

        $alias_id = intval($wpdb->insert_id);
        if (!$alias_id) {
            $payload = $wpdb->get_var($wpdb->prepare(
                "SELECT payload FROM {$table} WHERE producto_base_id = %d AND activo = 1",
                $producto_base_id
            ));
            return $payload ?: new WP_Error('ean_alias_create_failed', 'No se pudo reservar un alias EAN.');
        }

        $candidate = max(1, $alias_id);
        while ($candidate <= 99999) {
            $alias_code = str_pad((string) $candidate, 5, '0', STR_PAD_LEFT);
            $payload = '1' . $alias_code;
            $canonical_collision = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base
                 WHERE canonical_sku = %s AND id <> %d LIMIT 1",
                $payload,
                $producto_base_id
            ));
            $alias_collision = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE payload = %s AND id <> %d LIMIT 1",
                $payload,
                $alias_id
            ));

            if (!$canonical_collision && !$alias_collision) {
                $updated = $wpdb->update(
                    $table,
                    ['alias_codigo' => $alias_code, 'payload' => $payload],
                    ['id' => $alias_id],
                    ['%s', '%s'],
                    ['%d']
                );
                if ($updated !== false) {
                    return $payload;
                }
            }
            $candidate++;
        }

        return new WP_Error('ean_alias_exhausted', 'No quedan alias internos disponibles.');
    }

    /**
     * Indica si un código corresponde al formato interno 2SSSSSSQQQQQX válido.
     *
     * @param string $ean13
     * @return bool
     */
    public static function is_internal($ean13) {
        $ean13 = trim((string) $ean13);
        if (!preg_match('/^\d{13}$/', $ean13)) {
            return false;
        }
        if ($ean13[0] !== self::PREFIX) {
            return false;
        }
        return self::check_digit(substr($ean13, 0, 12)) === (int) $ean13[12];
    }

    /**
     * Extrae SKU y cantidad de un EAN13 interno.
     *
     * @param string $ean13
     * @return array|null ['sku' => string, 'cantidad' => int] o null si no es interno válido
     */
    public static function parse($ean13) {
        if (!self::is_internal($ean13)) {
            return null;
        }
        return [
            'sku' => substr($ean13, 1, 6),
            'cantidad' => (int) substr($ean13, 7, 5),
            'payload' => substr($ean13, 1, 6),
            'alias_tipo' => substr($ean13, 1, 1),
        ];
    }
}
