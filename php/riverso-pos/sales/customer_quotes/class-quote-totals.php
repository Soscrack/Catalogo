<?php
/**
 * Totales de cabecera a partir de las líneas.
 * El descuento, el margen y la utilidad se calculan aunque el modo avanzado
 * esté apagado y la vista base no muestre esas columnas.
 *
 * Descuento canónico: discount_amount en pesos. Los % de precio y margen
 * son vistas equivalentes. Si hay discount_amount > 0, se resta ese monto.
 * Sin monto, se deriva desde price_discount (o margen legado).
 *
 * Si una familia tiene rule_total ajustado (T_final del motor de reglas),
 * el bruto de cada línea se prorratea para que la suma coincida con ese total.
 *
 * Valor fijado (discount_anchor + discount_anchor_value): el campo que escribió
 * el vendedor y su valor exacto. Si está, el monto se deriva de él con el bruto
 * actual (p. ej. al cambiar la cantidad se mantiene el % o el unitario final).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Totals {
    const LOW_MARGIN_PERCENT = 5.0;

    /** Campos que el vendedor puede fijar en el módulo Descuentos. */
    const ANCHORS = array('price_pct', 'margin_pct', 'amount', 'final_unit', 'final_total', 'factor');

    /**
     * @param mixed $value
     * @return string|null
     */
    public static function normalize_anchor($value) {
        $anchor = strtolower(trim((string) $value));
        return in_array($anchor, self::ANCHORS, true) ? $anchor : null;
    }

    /**
     * @param array $lines
     * @return array
     */
    public static function calculate(array $lines) {
        $lines = array_values($lines);
        $gross_by_index = self::allocate_grosses($lines);

        $net = 0.0;
        $discounts = 0.0;
        $profit = 0.0;
        $profit_known = $lines !== array();
        $normalized = array();

        foreach ($lines as $index => $line) {
            $qty = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
            $rule_adjusted = !empty($line['rule_adjusted']);
            $price_raw = (float) (isset($line['unit_price']) ? $line['unit_price'] : 0);
            $price = $rule_adjusted ? round($price_raw, 4) : round($price_raw, 2);
            $price_rate = self::rate(isset($line['price_discount']) ? $line['price_discount'] : 0);
            $margin_rate = self::rate(isset($line['margin_discount']) ? $line['margin_discount'] : 0);
            $billable = self::billable_units($line, $qty);
            $gross = isset($gross_by_index[$index])
                ? (float) $gross_by_index[$index]
                : round($billable * round($price, 2), 2);
            $has_cost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== '';
            $unit_cost = $has_cost ? round((float) $line['unit_cost'], 2) : null;

            $anchor = self::normalize_anchor(isset($line['discount_anchor']) ? $line['discount_anchor'] : null);
            $anchor_value = ($anchor !== null && isset($line['discount_anchor_value'])
                && $line['discount_anchor_value'] !== null && $line['discount_anchor_value'] !== '')
                ? round((float) $line['discount_anchor_value'], 6)
                : null;
            $anchored = $anchor !== null && $anchor_value !== null
                ? self::discount_from_anchor($anchor, $anchor_value, $gross, $billable, $unit_cost)
                : null;
            if ($anchor_value === null) {
                $anchor = null;
            }
            // Sin costo, margen/factor no se pueden derivar: se usa el monto guardado
            // y el valor fijado se conserva para cuando vuelva el costo.

            $amount_money = round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2);
            if ($anchored !== null) {
                $discount = $anchored;
                // Los % son vistas del monto derivado.
                $price_rate = $gross > 0 ? self::rate($discount / $gross * 100) : 0.0;
                $margin_money = $unit_cost === null ? null : round($gross - round($billable * $unit_cost, 2), 2);
                $margin_rate = ($margin_money !== null && $margin_money > 0)
                    ? self::rate($discount / $margin_money * 100)
                    : 0.0;
            } elseif ($amount_money > 0) {
                // Canónico: monto en pesos; % son vistas.
                $discount = $amount_money;
                if ($discount > $gross) {
                    $discount = $gross;
                }
            } elseif ($price_rate > 0 || $margin_rate > 0) {
                $discount = self::discount_from_rates($gross, $billable, $unit_cost, $price_rate, $margin_rate);
            } else {
                $discount = 0.0;
            }

            $line_net = round($gross - $discount, 2);
            $line_profit = null;
            $line_margin = null;
            if ($unit_cost === null) {
                $profit_known = false;
            } else {
                $line_profit = round($line_net - round($billable * $unit_cost, 2), 2);
                $line_margin = $line_net > 0 ? round(($line_profit / $line_net) * 100, 2) : 0.0;
                $profit += $line_profit;
            }
            $net += $line_net;
            $discounts += $discount;
            $out = array_merge($line, array(
                'quantity' => $qty,
                'unit_price' => $price,
                'price_discount' => $price_rate,
                'margin_discount' => $margin_rate,
                'discount_amount' => $discount,
                'discount_anchor' => $anchor,
                'discount_anchor_value' => $anchor_value,
                'unit_cost' => $unit_cost,
                'line_net' => $line_net,
                'line_profit' => $line_profit,
                'line_margin_percent' => $line_margin,
                'sort_order' => $index,
            ));
            if (!$rule_adjusted) {
                unset($out['rule_total'], $out['rule_adjusted']);
            }
            $normalized[] = $out;
        }

        $net = round($net, 2);
        $discounts = round($discounts, 2);
        $profit_total = $profit_known ? round($profit, 2) : null;
        $margin = null;
        if ($profit_total !== null) {
            $margin = $net > 0 ? round(($profit_total / $net) * 100, 2) : 0.0;
        }

        return array(
            'net_total' => $net,
            'discount_total' => $discounts,
            'profit_total' => $profit_total,
            'margin_percent' => $margin,
            'lines' => $normalized,
        );
    }

    /**
     * Alarma visual. No bloquea pasar a lista.
     * - neg: utilidad negativa
     * - low: utilidad no negativa y margen menor a 5 %
     */
    public static function alarm($profit, $margin_percent) {
        if ($profit === null) {
            return '';
        }
        if ((float) $profit < 0) {
            return 'neg';
        }
        if ($margin_percent !== null && (float) $margin_percent < self::LOW_MARGIN_PERCENT) {
            return 'low';
        }
        return '';
    }

    /**
     * Brutos por índice: prorratea T_final cuando la regla ajustó el total.
     *
     * @param array $lines
     * @return array<int,float>
     */
    private static function allocate_grosses(array $lines) {
        $grosses = array();
        $used = array();
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            if (!empty($used[$i])) {
                continue;
            }
            $line = $lines[$i];
            $gid = self::line_grupo_id($line);
            $rule_adjusted = !empty($line['rule_adjusted']);
            $rule_total = isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== ''
                ? round((float) $line['rule_total'], 2)
                : null;
            $manual_total = self::manual_price_total($line);

            if ($gid > 0) {
                $indexes = array();
                for ($j = $i; $j < $n; $j++) {
                    if (!empty($used[$j])) {
                        continue;
                    }
                    if (self::line_grupo_id($lines[$j]) === $gid) {
                        $indexes[] = $j;
                        $used[$j] = true;
                        if (!empty($lines[$j]['rule_adjusted'])
                            && isset($lines[$j]['rule_total'])
                            && $lines[$j]['rule_total'] !== null
                            && $lines[$j]['rule_total'] !== ''
                        ) {
                            $rule_adjusted = true;
                            $rule_total = round((float) $lines[$j]['rule_total'], 2);
                        }
                        $mt = self::manual_price_total($lines[$j]);
                        if ($mt !== null) {
                            $manual_total = $mt;
                        }
                    }
                }
                if ($manual_total !== null) {
                    self::prorate_into($grosses, $lines, $indexes, $manual_total);
                } elseif ($rule_adjusted && $rule_total !== null) {
                    self::prorate_into($grosses, $lines, $indexes, $rule_total);
                } else {
                    foreach ($indexes as $idx) {
                        $qty = round((float) (isset($lines[$idx]['quantity']) ? $lines[$idx]['quantity'] : 0), 3);
                        $price = round((float) (isset($lines[$idx]['unit_price']) ? $lines[$idx]['unit_price'] : 0), 2);
                        $grosses[$idx] = round(self::billable_units($lines[$idx], $qty) * $price, 2);
                    }
                }
                continue;
            }

            $used[$i] = true;
            $qty = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
            $billable = self::billable_units($line, $qty);
            if ($manual_total !== null) {
                $grosses[$i] = $manual_total;
            } elseif ($rule_adjusted && $rule_total !== null) {
                $grosses[$i] = $rule_total;
            } else {
                $price = round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 2);
                $grosses[$i] = round($billable * $price, 2);
            }
        }

        return $grosses;
    }

    /**
     * Total fijo cuando price_mode=manual y hay price_total.
     *
     * @param array $line
     * @return float|null
     */
    private static function manual_price_total(array $line) {
        $mode = isset($line['price_mode']) ? strtolower(trim((string) $line['price_mode'])) : '';
        if ($mode !== 'manual') {
            return null;
        }
        if (!isset($line['price_total']) || $line['price_total'] === null || $line['price_total'] === '') {
            return null;
        }
        return round((float) $line['price_total'], 2);
    }

    /**
     * @param array<int,float> $grosses
     * @param array            $lines
     * @param int[]            $indexes
     * @param float            $rule_total
     */
    private static function prorate_into(array &$grosses, array $lines, array $indexes, $rule_total) {
        $rule_total = round((float) $rule_total, 2);
        $family_units = 0.0;
        foreach ($indexes as $idx) {
            $qty = round((float) (isset($lines[$idx]['quantity']) ? $lines[$idx]['quantity'] : 0), 3);
            $family_units += self::billable_units($lines[$idx], $qty);
        }
        $family_units = round($family_units, 3);
        if ($family_units <= 0) {
            $last = $indexes[count($indexes) - 1];
            foreach ($indexes as $idx) {
                $grosses[$idx] = ($idx === $last) ? $rule_total : 0.0;
            }
            return;
        }

        $allocated = 0.0;
        $count = count($indexes);
        foreach ($indexes as $i => $idx) {
            $qty = round((float) (isset($lines[$idx]['quantity']) ? $lines[$idx]['quantity'] : 0), 3);
            $units = self::billable_units($lines[$idx], $qty);
            if ($i === $count - 1) {
                $grosses[$idx] = round($rule_total - $allocated, 2);
            } else {
                $share = round($rule_total * $units / $family_units, 2);
                $grosses[$idx] = $share;
                $allocated = round($allocated + $share, 2);
            }
        }
    }

    /**
     * @param array $line
     * @return int
     */
    private static function line_grupo_id(array $line) {
        if (isset($line['grupo_id']) && $line['grupo_id'] !== null && $line['grupo_id'] !== '') {
            return (int) $line['grupo_id'];
        }
        if (isset($line['_family']) && is_array($line['_family']) && !empty($line['_family']['grupo_id'])) {
            return (int) $line['_family']['grupo_id'];
        }
        return 0;
    }

    /**
     * Descuento en pesos desde el valor fijado. null si falta el costo que necesita.
     *
     * @param string     $anchor
     * @param float      $value
     * @param float      $gross     Bruto de la línea antes del descuento
     * @param float      $billable  Unidades facturables
     * @param float|null $unit_cost Costo unitario (2 decimales)
     * @return float|null
     */
    public static function discount_from_anchor($anchor, $value, $gross, $billable, $unit_cost) {
        $gross = round((float) $gross, 2);
        $value = (float) $value;
        $billable = (float) $billable;
        switch ($anchor) {
            case 'price_pct':
                $discount = round($gross * self::rate($value) / 100, 2);
                break;
            case 'margin_pct':
                if ($unit_cost === null) {
                    return null;
                }
                $margin = round($gross - round($billable * $unit_cost, 2), 2);
                $discount = $margin > 0 ? round($margin * self::rate($value) / 100, 2) : 0.0;
                break;
            case 'amount':
                $discount = round($value, 2);
                break;
            case 'final_unit':
                $discount = round($gross - round($value * $billable, 2), 2);
                break;
            case 'final_total':
                $discount = round($gross - round($value, 2), 2);
                break;
            case 'factor':
                if ($unit_cost === null || $unit_cost <= 0) {
                    return null;
                }
                $discount = round($gross - round($value * $unit_cost * $billable, 2), 2);
                break;
            default:
                return null;
        }
        if ($discount < 0) {
            return 0.0;
        }
        if ($discount > $gross) {
            return $gross;
        }
        return $discount;
    }

    private static function rate($value) {
        $rate = round((float) $value, 4);
        if ($rate < 0) {
            return 0.0;
        }
        if ($rate > 100) {
            return 100.0;
        }
        return $rate;
    }

    /**
     * Unidades facturables: envase multiplica cantidad × units_per_pack;
     * embolsado / sin envase usa la cantidad de la línea.
     *
     * @param array $line
     * @param float $qty
     * @return float
     */
    private static function billable_units(array $line, $qty) {
        $qty = (float) $qty;
        $upp = isset($line['units_per_pack']) ? (float) $line['units_per_pack'] : 1.0;
        if ($upp <= 0) {
            $upp = 1.0;
        }
        if ($upp > 1.0001) {
            return round($qty * $upp, 3);
        }
        return round($qty, 3);
    }

    private static function discount_from_rates($gross, $qty, $unit_cost, $price_rate, $margin_rate) {
        $gross = round((float) $gross, 2);
        $price_rate = self::rate($price_rate);
        $margin_rate = self::rate($margin_rate);
        $discount = 0.0;
        if ($price_rate > 0) {
            // Sin monto en pesos: derivar desde % sobre el bruto.
            $discount = round($gross * $price_rate / 100, 2);
        } elseif ($margin_rate > 0 && $unit_cost !== null) {
            // Legado: solo dscto margen (sin precio).
            $margin_base = round($gross - round($qty * $unit_cost, 2), 2);
            if ($margin_base > 0) {
                $discount = round($margin_base * $margin_rate / 100, 2);
            }
        }
        if ($discount < 0) {
            return 0.0;
        }
        if ($discount > $gross) {
            return $gross;
        }
        return $discount;
    }
}
