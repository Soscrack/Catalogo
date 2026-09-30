<?php
/**
 * Totales de cabecera a partir de las líneas.
 * El descuento, el margen y la utilidad se calculan aunque el modo avanzado
 * esté apagado y la vista base no muestre esas columnas.
 *
 * Dscto precio: porcentaje sobre el bruto (cantidad × precio).
 * Dscto margen: porcentaje del margen que queda después de ese descuento
 * (precio ya descontado menos costo). Sin costo, el dscto margen se guarda
 * pero no descuenta dinero.
 * Si ambos porcentajes quedan en 0, se respeta discount_amount (líneas anteriores).
 *
 * Si una familia tiene rule_total ajustado (T_final del motor de reglas),
 * el bruto de cada línea se prorratea para que la suma coincida con ese total.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Totals {
    const LOW_MARGIN_PERCENT = 5.0;

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

            if ($price_rate > 0 || $margin_rate > 0) {
                $discount = self::discount_from_rates($gross, $billable, $unit_cost, $price_rate, $margin_rate);
            } else {
                $discount = round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2);
                if ($discount < 0) {
                    $discount = 0.0;
                }
                if ($discount > $gross) {
                    $discount = $gross;
                }
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
                    }
                }
                if ($rule_adjusted && $rule_total !== null) {
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
            if ($rule_adjusted && $rule_total !== null) {
                $grosses[$i] = $rule_total;
            } else {
                $price = round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 2);
                $grosses[$i] = round($billable * $price, 2);
            }
        }

        return $grosses;
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

    private static function rate($value) {
        $rate = round((float) $value, 2);
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
        $price_off = round($gross * $price_rate / 100, 2);
        $after = round($gross - $price_off, 2);
        $margin_off = 0.0;
        if ($unit_cost !== null && $margin_rate > 0) {
            $margin_base = round($after - round($qty * $unit_cost, 2), 2);
            if ($margin_base > 0) {
                $margin_off = round($margin_base * $margin_rate / 100, 2);
            }
        }
        $discount = round($price_off + $margin_off, 2);
        if ($discount < 0) {
            return 0.0;
        }
        if ($discount > $gross) {
            return $gross;
        }
        return $discount;
    }
}
