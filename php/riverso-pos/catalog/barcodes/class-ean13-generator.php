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
     * @return string EAN13 de 13 dígitos
     */
    public static function build($sku, $cantidad) {
        $sku_digits = preg_replace('/\D/', '', (string) $sku);
        if ($sku_digits === '') {
            $sku_digits = '0';
        }
        // 6 dígitos (trunca por la izquierda si excede, rellena con ceros).
        $sku_digits = substr($sku_digits, -6);
        $sku_part = str_pad($sku_digits, 6, '0', STR_PAD_LEFT);

        $cantidad = max(0, min(99999, (int) $cantidad));
        $qty_part = str_pad((string) $cantidad, 5, '0', STR_PAD_LEFT);

        $twelve = self::PREFIX . $sku_part . $qty_part; // 1 + 6 + 5 = 12
        $check = self::check_digit($twelve);

        return $twelve . $check;
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
        ];
    }
}
