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
            if ($afecto) {
                $has_afecto = true;
                $unit_net = self::net_from_gross($bruto);
                $line_net = round($unit_net * $qty, 2);
                // Ajuste residual para que qty * unit_net ≈ neto de bruto total.
                $line_net_alt = self::net_from_gross($line_bruto);
                $line_net = round($line_net_alt, 2);
                $unit_net = $qty > 0 ? round($line_net / $qty, 6) : 0.0;
                $tax = round($line_bruto - $line_net, 2);
                $net_afecto += $line_net;
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
                    'total_amount_line' => $line_bruto,
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
                $line_net = $line_bruto;
                $unit_net = $bruto;
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
                    'total_amount_line' => $line_bruto,
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

        $net_afecto = round($net_afecto, 2);
        $net_exento = round($net_exento, 2);
        $net_total = round($net_afecto + $net_exento, 2);
        $taxes = 0.0;
        $total = $net_total;
        foreach ($details as $d) {
            $taxes += (float) ($d['total_taxes'] ?? 0);
            $total = max($total, 0);
        }
        $taxes = round($taxes, 2);
        // Total = suma de totales de línea (brutos).
        $total = 0.0;
        foreach ($details as $d) {
            $total += (float) ($d['total_amount_line'] ?? 0);
        }
        $total = round($total, 2);
        // Recalcular IVA como total afecto bruto − neto afecto.
        $bruto_afecto = 0.0;
        foreach ($lines_ui as $lu) {
            if (!empty($lu['afecto'])) {
                $bruto_afecto += (float) $lu['line_bruto'];
            }
        }
        $bruto_afecto = round($bruto_afecto, 2);
        $taxes = round($bruto_afecto - $net_afecto, 2);
        if ($taxes < 0) {
            $taxes = 0.0;
        }

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
