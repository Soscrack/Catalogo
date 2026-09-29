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
        $net = 0.0;
        $discounts = 0.0;
        $profit = 0.0;
        $profit_known = $lines !== array();
        $normalized = array();

        foreach (array_values($lines) as $index => $line) {
            $qty = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
            $price = round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 2);
            $price_rate = self::rate(isset($line['price_discount']) ? $line['price_discount'] : 0);
            $margin_rate = self::rate(isset($line['margin_discount']) ? $line['margin_discount'] : 0);
            $gross = round($qty * $price, 2);
            $has_cost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== '';
            $unit_cost = $has_cost ? round((float) $line['unit_cost'], 2) : null;

            if ($price_rate > 0 || $margin_rate > 0) {
                $discount = self::discount_from_rates($gross, $qty, $unit_cost, $price_rate, $margin_rate);
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
                $line_profit = round($line_net - round($qty * $unit_cost, 2), 2);
                $line_margin = $line_net > 0 ? round(($line_profit / $line_net) * 100, 2) : 0.0;
                $profit += $line_profit;
            }
            $net += $line_net;
            $discounts += $discount;
            $normalized[] = array_merge($line, array(
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
