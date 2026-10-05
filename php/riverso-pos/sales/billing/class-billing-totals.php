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
            if ($afecto) {
                $has_afecto = true;
                // FACTO CLP: neto e IVA por redondeo de montos netos (pesos enteros).
                $line_net = (float) self::net_pesos_from_gross($line_bruto);
                $unit_net = self::unit_price_for_integer_net($qty, $line_net);
                $tax = (float) (int) round($line_net * 0.19);
                $line_total_facto = $line_net + $tax;
                $net_afecto += $line_net;
                $gross_afecto += $line_bruto;
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
        if ($has_afecto) {
            $net_afecto = (float) self::close_net_to_gross($details, $lines_ui, (int) $net_afecto, (int) round($gross_afecto));
        }
        $net_exento = (float) (int) round($net_exento);
        $net_total = (float) (int) round($net_afecto + $net_exento);
        // FACTO: IVA = 19% del neto afecto redondeado (no bruto − neto comercial).
        $taxes = (float) (int) round($net_afecto * 0.19);
        // Ajustar residual de IVA en la última línea afecto para que la suma de detalles cierre.
        $tax_sum = 0.0;
        $last_afecto_idx = null;
        foreach ($details as $i => $d) {
            if (!empty($d['taxes'])) {
                $tax_sum += (float) ($d['total_taxes'] ?? 0);
                $last_afecto_idx = $i;
            }
        }
        $tax_delta = (int) round($taxes - $tax_sum);
        if ($tax_delta !== 0 && $last_afecto_idx !== null) {
            $new_tax = (int) $details[$last_afecto_idx]['total_taxes'] + $tax_delta;
            if ($new_tax < 0) {
                $new_tax = 0;
            }
            $details[$last_afecto_idx]['total_taxes'] = (float) $new_tax;
            $details[$last_afecto_idx]['taxes'][0]['tax_amount'] = (float) $new_tax;
            $line_net_adj = (int) round(
                (float) $details[$last_afecto_idx]['quantity'] * (float) $details[$last_afecto_idx]['unit_price']
            );
            $details[$last_afecto_idx]['total_amount_line'] = (float) ($line_net_adj + $new_tax);
        }
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
     * Neto afecto N tal que N + round(N × 19 %) = bruto comercial; si no existe, el más cercano.
     *
     * @param int $gross
     * @return int
     */
    public static function net_closing_gross($gross) {
        $gross = (int) $gross;
        $base = (int) round($gross / self::IVA_FACTOR);
        $best = $base;
        $best_diff = null;
        foreach ([0, -1, 1, -2, 2, -3, 3] as $step) {
            $net = $base + $step;
            if ($net < 0) {
                continue;
            }
            $diff = abs($net + (int) round($net * 0.19) - $gross);
            if ($best_diff === null || $diff < $best_diff) {
                $best = $net;
                $best_diff = $diff;
            }
            if ($diff === 0) {
                break;
            }
        }
        return $best;
    }

    /**
     * Reparte en la línea afecta de mayor neto la diferencia para que neto + IVA cierre al bruto.
     *
     * @param array $details
     * @param array $lines_ui
     * @param int   $net_afecto
     * @param int   $gross_afecto
     * @return int neto afecto final
     */
    private static function close_net_to_gross(array &$details, array &$lines_ui, $net_afecto, $gross_afecto) {
        $target = self::net_closing_gross($gross_afecto);
        $delta = $target - (int) $net_afecto;
        if ($delta === 0) {
            return (int) $net_afecto;
        }
        $idx = null;
        $max_net = 0;
        foreach ($details as $i => $d) {
            if (empty($d['taxes'])) {
                continue;
            }
            $line_net = (int) round((float) $d['quantity'] * (float) $d['unit_price']);
            if ($idx === null || $line_net > $max_net) {
                $idx = $i;
                $max_net = $line_net;
            }
        }
        if ($idx === null || $max_net + $delta <= 0) {
            return (int) $net_afecto;
        }
        $new_net = $max_net + $delta;
        $qty = (float) $details[$idx]['quantity'];
        $details[$idx]['unit_price'] = self::unit_price_for_integer_net($qty, $new_net);
        $tax = (float) (int) round($new_net * 0.19);
        $details[$idx]['total_taxes'] = $tax;
        $details[$idx]['taxes'][0]['tax_amount'] = $tax;
        $details[$idx]['total_amount_line'] = (float) ($new_net + $tax);
        if (isset($lines_ui[$idx])) {
            $lines_ui[$idx]['line_net'] = (float) $new_net;
        }
        return $target;
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
