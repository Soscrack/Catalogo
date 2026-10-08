<?php
/**
 * Totales DTE desde líneas en bruto comercial (misma lógica que PDF de cotización).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Billing_Totals {

    const IVA_FACTOR = 1.19;
    const TAX_TYPE_ID = '387';

    /**
     * Tipos FACTO Chile usados en Emitir.
     */
    const TYPE_FACTURA = 2;
    const TYPE_FACTURA_EXENTA = 32;
    const TYPE_BOLETA = 37;
    const TYPE_BOLETA_EXENTA = 41;

    /**
     * @param array $lines [{sku,description,quantity,unit_price_bruto,afecto}]
     * @param int   $preferred_type 2|37 (afecta se resuelve a exenta si todas las líneas son exentas)
     * @return array{document_type_id:int,details:array,totals:array,lines_ui:array}
     */
    public static function build(array $lines, $preferred_type = self::TYPE_FACTURA) {
        $preferred_type = (int) $preferred_type;
        if (!in_array($preferred_type, [self::TYPE_FACTURA, self::TYPE_BOLETA], true)) {
            $preferred_type = self::TYPE_FACTURA;
        }

        $details = [];
        $lines_ui = [];
        $net_afecto = 0.0;
        $net_exento = 0.0;
        $gross_afecto = 0.0;
        $has_afecto = false;
        $has_exento = false;

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }
            $bruto = round((float) ($line['unit_price_bruto'] ?? $line['unit_price'] ?? 0), 2);
            $afecto = !empty($line['afecto']) || (isset($line['afecto_iva']) && $line['afecto_iva']);
            if (isset($line['afecto']) && ($line['afecto'] === false || $line['afecto'] === 0 || $line['afecto'] === '0')) {
                $afecto = false;
            }
            $sku = substr(trim((string) ($line['sku'] ?? '')), 0, 64);
            $desc = trim((string) ($line['description'] ?? $line['line_description'] ?? 'Ítem'));
            if ($desc === '') {
                $desc = 'Ítem';
            }

            $line_bruto = round($bruto * $qty, 2);
            // Total exacto de la línea (regla / precio fijo): el unitario a 2 decimales × cantidad
            // se corre ($500 en 6 u. → 83,33 × 6 = $499,98). Solo se acepta si la diferencia es
            // la del redondeo del unitario, no un total desfasado.
            if (isset($line['line_total_bruto']) && $line['line_total_bruto'] !== null && $line['line_total_bruto'] !== '') {
                $exact = round((float) $line['line_total_bruto'], 2);
                if (abs($exact - $line_bruto) <= 0.005 * $qty + 0.01) {
                    $line_bruto = $exact;
                }
            }
            if ($afecto) {
                $has_afecto = true;
                // FACTO rounding_type=gross: neto = round(qty × unit neto), IVA = bruto entero − neto.
                $unit_net = round($line_bruto / self::IVA_FACTOR / $qty, 6);
                $line_net = (float) (int) round($qty * $unit_net);
                $line_gross = (float) (int) round($line_bruto);
                $tax = max(0.0, $line_gross - $line_net);
                $line_total_facto = $line_net + $tax;
                $net_afecto += $line_net;
                $gross_afecto += $line_total_facto;
                $details[] = [
                    'quantity' => $qty,
                    'sku' => $sku !== '' ? $sku : 'ITEM',
                    'line_description' => $desc,
                    'unit_measure' => 'UN',
                    'unit_price' => $unit_net,
                    'long_description' => $desc,
                    'modifier_amount' => 0,
                    'modifier_percentage' => 0,
                    'total_taxes' => $tax,
                    'total_amount_line' => $line_total_facto,
                    'taxes' => [
                        [
                            'tax_type_id' => (string) self::TAX_TYPE_ID,
                            'tax_percentage' => 19,
                            'tax_amount' => $tax,
                        ],
                    ],
                ];
            } else {
                $has_exento = true;
                $line_net = (float) (int) round($line_bruto);
                $unit_net = self::unit_price_for_integer_net($qty, $line_net);
                $net_exento += $line_net;
                $details[] = [
                    'quantity' => $qty,
                    'sku' => $sku !== '' ? $sku : 'ITEM',
                    'line_description' => $desc,
                    'unit_measure' => 'UN',
                    'unit_price' => $unit_net,
                    'long_description' => $desc,
                    'modifier_amount' => 0,
                    'modifier_percentage' => 0,
                    'total_taxes' => 0,
                    'total_amount_line' => $line_net,
                ];
            }

            $lines_ui[] = [
                'sku' => $sku,
                'description' => $desc,
                'quantity' => $qty,
                'unit_price_bruto' => $bruto,
                'line_bruto' => $line_bruto,
                'line_net' => $line_net,
                'afecto' => $afecto,
            ];
        }

        $net_afecto = (float) (int) round($net_afecto);
        $gross_afecto = (float) (int) round($gross_afecto);
        $net_exento = (float) (int) round($net_exento);
        $net_total = (float) (int) round($net_afecto + $net_exento);
        // IVA = bruto afecto − neto afecto; puede diferir en ±1 de round(neto × 19 %) (reparo SII, no rechazo).
        $taxes = $has_afecto ? max(0.0, $gross_afecto - $net_afecto) : 0.0;
        $total = (float) (int) round($net_total + $taxes);

        $type = $preferred_type;
        if ($has_afecto && !$has_exento) {
            $type = $preferred_type; // 2 o 37
        } elseif (!$has_afecto && $has_exento) {
            $type = $preferred_type === self::TYPE_BOLETA
                ? self::TYPE_BOLETA_EXENTA
                : self::TYPE_FACTURA_EXENTA;
        } else {
            // Mixto: FACTO espera factura/boleta afecta con líneas exentas sin tax.
            $type = $preferred_type;
        }

        return [
            'document_type_id' => $type,
            'details' => $details,
            'totals' => [
                'net_amount' => $net_total,
                'taxes_amount' => $taxes,
                'total_amount' => $total,
            ],
            'lines_ui' => $lines_ui,
        ];
    }

    /**
     * @param float $bruto
     * @return float
     */
    public static function net_from_gross($bruto) {
        $bruto = (float) $bruto;
        if (class_exists('Riverso_Pricing_Module') && method_exists('Riverso_Pricing_Module', 'net_from_gross')) {
            $neto = Riverso_Pricing_Module::net_from_gross($bruto, 'afecto');
            if ($neto !== null && $neto !== '') {
                return round((float) $neto, 4);
            }
        }
        return round($bruto / self::IVA_FACTOR, 4);
    }

    /**
     * Neto de línea en pesos enteros (CLP / FACTO).
     *
     * @param float $bruto
     * @return int
     */
    public static function net_pesos_from_gross($bruto) {
        return (int) round((float) $bruto / self::IVA_FACTOR);
    }

    /**
     * Precio unitario neto (≤6 decimales) tal que round(qty * unit) = neto entero.
     *
     * @param float $qty
     * @param float $line_net
     * @return float
     */
    public static function unit_price_for_integer_net($qty, $line_net) {
        $qty = (float) $qty;
        $target = (int) round((float) $line_net);
        if ($qty <= 0) {
            return 0.0;
        }
        $unit = round($target / $qty, 6);
        if ((int) round($qty * $unit) === $target) {
            return $unit;
        }
        $delta = 0.000001;
        for ($i = 1; $i <= 50; $i++) {
            $up = round($unit + $delta * $i, 6);
            if ((int) round($qty * $up) === $target) {
                return $up;
            }
            $down = round($unit - $delta * $i, 6);
            if ($down >= 0 && (int) round($qty * $down) === $target) {
                return $down;
            }
        }
        return $unit;
    }

    /**
     * @param int $type_id
     * @return string
     */
    public static function type_label($type_id) {
        switch ((int) $type_id) {
            case self::TYPE_FACTURA:
                return 'Factura electrónica';
            case self::TYPE_FACTURA_EXENTA:
                return 'Factura exenta electrónica';
            case self::TYPE_BOLETA:
                return 'Boleta electrónica';
            case self::TYPE_BOLETA_EXENTA:
                return 'Boleta exenta electrónica';
            default:
                return 'Documento ' . (int) $type_id;
        }
    }

    /**
     * @param int $type_id
     * @return bool
     */
    public static function requires_receiver($type_id) {
        $type_id = (int) $type_id;
        return in_array($type_id, [
            self::TYPE_FACTURA,
            self::TYPE_FACTURA_EXENTA,
        ], true);
    }
}
