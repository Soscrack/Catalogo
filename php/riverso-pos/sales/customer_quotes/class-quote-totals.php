<?php
/**
 * Totales de cabecera a partir de las líneas.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Totals {
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
            $discount = round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2);
            if ($discount < 0) {
                $discount = 0.0;
            }
            $gross = round($qty * $price, 2);
            if ($discount > $gross) {
                $discount = $gross;
            }
            $line_net = round($gross - $discount, 2);
            $has_cost = array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== '';
            $unit_cost = $has_cost ? round((float) $line['unit_cost'], 2) : null;
            $line_profit = null;
            if ($unit_cost === null) {
                $profit_known = false;
            } else {
                $line_profit = round($line_net - round($qty * $unit_cost, 2), 2);
                $profit += $line_profit;
            }
            $net += $line_net;
            $discounts += $discount;
            $normalized[] = array_merge($line, array(
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_amount' => $discount,
                'unit_cost' => $unit_cost,
                'line_net' => $line_net,
                'line_profit' => $line_profit,
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
}
