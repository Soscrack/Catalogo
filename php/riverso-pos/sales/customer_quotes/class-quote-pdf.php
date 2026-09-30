<?php
/**
 * Modelo de documento PDF/HTML de cotización de venta.
 * Precios de la cotización son bruto comercial (IVA incluido);
 * el documento muestra neto e IVA como Facto.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Pdf {
    const TEMPLATE_FAMILY = 'family';
    const TEMPLATE_PRODUCT = 'product';
    const IVA_FACTOR = 1.19;

    /**
     * Datos fijos de la empresa (cabecera / transferencia).
     *
     * @return array<string, string>
     */
    public static function company() {
        return array(
            'razon_social' => 'COMERCIALIZADORA YUBINZA RIVERA RAMIREZ E.I.R.L.',
            'giro' => 'VENTA AL POR MENOR DE ARTICULOS DE FERRETERIA Y MATERIALES DE CONSTRUC',
            'direccion' => 'Casa Matriz: CONDELL 2999, Antofagasta ANTOFAGASTA',
            'fono' => '+56987433687',
            'email' => 'rs.riverso@gmail.com',
            'rut' => '76.443.852-3',
            'banco' => 'Banco BCI',
            'cuenta' => 'Cuenta corriente 63988411',
            'titular' => 'Comercializadora Yubinza Rivera Ramirez E.I.R.L',
            'rut_banco' => '76443852-3',
        );
    }

    /**
     * @param string $template
     * @return string family|product
     */
    public static function normalize_template($template) {
        $template = strtolower(trim((string) $template));
        return $template === self::TEMPLATE_PRODUCT
            ? self::TEMPLATE_PRODUCT
            : self::TEMPLATE_FAMILY;
    }

    /**
     * @param array  $quote Cotización ya hidratada (_family).
     * @param string $template family|product
     * @return array Documento listo para la plantilla.
     */
    public static function build(array $quote, $template = self::TEMPLATE_FAMILY) {
        $template = self::normalize_template($template);
        $lines = isset($quote['lines']) && is_array($quote['lines']) ? $quote['lines'] : array();
        $iva_map = self::load_iva_tipos($lines);

        if ($template === self::TEMPLATE_FAMILY) {
            $rows = self::build_family_rows($lines, $iva_map);
        } else {
            $rows = self::build_product_rows($lines, $iva_map);
        }

        $anchor_bruto = self::resolve_anchor_bruto($quote, $rows);
        $reconciled = self::reconcile_to_anchor($rows, $anchor_bruto);
        $seller = self::resolve_seller($quote);

        return array(
            'company' => self::company(),
            'template' => $template,
            'quote_id' => isset($quote['id']) ? (int) $quote['id'] : 0,
            'quote_number' => (string) (isset($quote['quote_number']) ? $quote['quote_number'] : ''),
            'issue_date' => self::format_date(isset($quote['issue_date']) ? $quote['issue_date'] : (isset($quote['created_at']) ? $quote['created_at'] : '')),
            'customer_name' => (string) (isset($quote['customer_name']) ? $quote['customer_name'] : ''),
            'customer_rut' => '',
            'customer_phone' => '',
            'customer_email' => '',
            'seller_name' => $seller['name'],
            'seller_email' => $seller['email'],
            'currency' => 'PESO CHILENO',
            'rows' => $reconciled['rows'],
            'totals' => $reconciled['totals'],
            'notes' => (string) (isset($quote['notes']) ? $quote['notes'] : ''),
            'validity_days' => isset($quote['validity_days']) ? $quote['validity_days'] : null,
            'validity_terms' => (string) (isset($quote['validity_terms']) ? $quote['validity_terms'] : ''),
        );
    }

    /**
     * @param array $lines
     * @return array<int, string> producto_base_id => afecto|exento
     */
    private static function load_iva_tipos(array $lines) {
        global $wpdb;
        $ids = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            if ($pb > 0) {
                $ids[$pb] = $pb;
            }
        }
        $map = array();
        if (!$ids || !isset($wpdb) || !is_object($wpdb)) {
            return $map;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $id_list = implode(',', array_map('intval', array_values($ids)));
        // Columna puede faltar en schemas antiguos.
        $has_col = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = 'facto_iva_tipo'",
                $prefix . 'producto_base'
            )
        );
        if (!(int) $has_col) {
            return $map;
        }
        $rows = $wpdb->get_results(
            "SELECT id, facto_iva_tipo FROM {$prefix}producto_base WHERE id IN ({$id_list})",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return $map;
        }
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $tipo = isset($row['facto_iva_tipo']) ? (string) $row['facto_iva_tipo'] : 'afecto';
            if (class_exists('Riverso_Pricing_Module') && method_exists('Riverso_Pricing_Module', 'normalize_iva_tipo')) {
                $tipo = Riverso_Pricing_Module::normalize_iva_tipo($tipo);
            } else {
                $tipo = strtolower(trim($tipo)) === 'exento' ? 'exento' : 'afecto';
            }
            $map[$id] = $tipo;
        }
        return $map;
    }

    /**
     * @param array $lines
     * @param array $iva_map
     * @return array<int, array>
     */
    private static function build_product_rows(array $lines, array $iva_map) {
        $rows = array();
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $rows[] = self::row_from_line($line, $iva_map, true);
        }
        return $rows;
    }

    /**
     * Una fila por familia unitaria; pack/kit y sin familia quedan línea a línea.
     *
     * @param array $lines
     * @param array $iva_map
     * @return array<int, array>
     */
    private static function build_family_rows(array $lines, array $iva_map) {
        $rows = array();
        $used = array();
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            if (!empty($used[$i])) {
                continue;
            }
            $line = $lines[$i];
            if (!is_array($line)) {
                $used[$i] = true;
                continue;
            }
            $gid = self::line_grupo_id($line);
            if ($gid <= 0 || !self::is_unitaria_family($line)) {
                $rows[] = self::row_from_line($line, $iva_map, true);
                $used[$i] = true;
                continue;
            }

            $indexes = array();
            for ($j = $i; $j < $n; $j++) {
                if (!empty($used[$j]) || !is_array($lines[$j])) {
                    continue;
                }
                if (self::line_grupo_id($lines[$j]) === $gid && self::is_unitaria_family($lines[$j])) {
                    $indexes[] = $j;
                    $used[$j] = true;
                }
            }

            if (count($indexes) <= 1) {
                $rows[] = self::row_from_line($line, $iva_map, true);
                continue;
            }

            $rows[] = self::collapse_family_group($lines, $indexes, $iva_map);
        }

        return $rows;
    }

    /**
     * @param array $lines
     * @param int[] $indexes
     * @param array $iva_map
     * @return array
     */
    private static function collapse_family_group(array $lines, array $indexes, array $iva_map) {
        $first = $lines[$indexes[0]];
        $fam = isset($first['_family']) && is_array($first['_family']) ? $first['_family'] : array();
        $description = '';
        if (!empty($fam['family_name'])) {
            $description = (string) $fam['family_name'];
        } elseif (!empty($fam['family_code'])) {
            $description = (string) $fam['family_code'];
        } else {
            $description = (string) (isset($first['description']) ? $first['description'] : $first['sku']);
        }

        $qty_units = 0.0;
        $bruto_total = 0.0;
        $discount_total = 0.0;
        $afecto = true;
        foreach ($indexes as $idx) {
            $line = $lines[$idx];
            $qty_units += self::family_units($line);
            $figs = self::line_commercial($line);
            $bruto_total += $figs['bruto'];
            $discount_total += $figs['discount'];
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            $tipo = isset($iva_map[$pb]) ? $iva_map[$pb] : 'afecto';
            if ($tipo === 'exento') {
                $afecto = false;
            }
        }
        $qty_units = round($qty_units, 3);
        $bruto_total = round($bruto_total, 2);
        $discount_total = round($discount_total, 2);

        return self::make_row(
            $description,
            $qty_units,
            $bruto_total,
            $discount_total,
            $afecto
        );
    }

    /**
     * @param array $line
     * @param array $iva_map
     * @param bool  $annotate_pack Si true, añade ×N a la descripción de envases.
     * @return array
     */
    private static function row_from_line(array $line, array $iva_map, $annotate_pack = false) {
        $description = trim((string) (isset($line['description']) ? $line['description'] : ''));
        if ($description === '') {
            $description = (string) (isset($line['sku']) ? $line['sku'] : '');
        }
        if ($annotate_pack) {
            $upp = self::units_per_pack($line);
            if ($upp > 1.0001) {
                $label = self::presentation_label($line, $upp);
                if ($label !== '' && stripos($description, $label) === false) {
                    $description .= ' (' . $label . ')';
                }
            }
        }

        $figs = self::line_commercial($line);
        $qty = self::family_units($line);
        $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
        $tipo = isset($iva_map[$pb]) ? $iva_map[$pb] : 'afecto';
        $afecto = ($tipo !== 'exento');

        return self::make_row($description, $qty, $figs['bruto'], $figs['discount'], $afecto);
    }

    /**
     * @param string $description
     * @param float  $qty
     * @param float  $bruto_total Total comercial bruto de la fila (con IVA si afecto).
     * @param float  $discount_bruto Descuento en bruto.
     * @param bool   $afecto
     * @return array
     */
    private static function make_row($description, $qty, $bruto_total, $discount_bruto, $afecto) {
        $qty = round((float) $qty, 3);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $bruto_total = round((float) $bruto_total, 2);
        $discount_bruto = round((float) $discount_bruto, 2);
        if ($discount_bruto < 0) {
            $discount_bruto = 0.0;
        }

        // bruto_total = comercial DESPUÉS de descuento (peso para el ancla).
        // bruto_before = lista SIN descuento (para Valor unitario).
        $bruto_before = round($bruto_total + $discount_bruto, 2);

        if ($afecto) {
            $neto_raw = (float) self::net_from_gross($bruto_total);
            $discount_neto = round((float) self::net_from_gross($discount_bruto), 2);
            $neto_before_raw = (float) self::net_from_gross($bruto_before);
        } else {
            $neto_raw = $bruto_total;
            $discount_neto = $discount_bruto;
            $neto_before_raw = $bruto_before;
        }

        $discount_pct = 0.0;
        if ($bruto_before > 0 && $discount_bruto > 0) {
            $discount_pct = round(($discount_bruto / $bruto_before) * 100, 2);
        }

        return array(
            'description' => (string) $description,
            'quantity' => $qty,
            'qty_label' => self::format_qty($qty) . ' UN',
            'unit_net' => round($neto_before_raw / $qty, 6),
            'unit_decimals' => 2,
            'line_net' => round($neto_raw, 2),
            'line_bruto' => $bruto_total,
            'line_bruto_before' => $bruto_before,
            'discount_pct' => $discount_pct,
            'discount_neto' => $discount_neto,
            'afecto_iva' => $afecto,
            'afecto_label' => $afecto ? 'SI' : 'NO',
            'imp_esp' => 0.0,
        );
    }

    /**
     * Bruto comercial ancla del documento (pie Total).
     * Preferir net_total de la cotización (p. ej. 16600 en COT-2026-0011).
     *
     * @param array $quote
     * @param array $rows
     * @return int
     */
    private static function resolve_anchor_bruto(array $quote, array $rows) {
        if (isset($quote['net_total']) && $quote['net_total'] !== null && $quote['net_total'] !== '') {
            return (int) round((float) $quote['net_total']);
        }
        if (isset($quote['total']) && $quote['total'] !== null && $quote['total'] !== '') {
            return (int) round((float) $quote['total']);
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum = round($sum + (float) ($row['line_bruto'] ?? 0), 2);
        }
        return (int) round($sum);
    }

    /**
     * Ancla el pie al bruto comercial y reparte neto entero por fila
     * de forma que Cant × Valor = Total de línea.
     *
     * @param array $rows
     * @param int   $anchor_bruto
     * @return array{rows:array,totals:array}
     */
    private static function reconcile_to_anchor(array $rows, $anchor_bruto) {
        $anchor_bruto = (int) round((float) $anchor_bruto);
        if ($anchor_bruto < 0) {
            $anchor_bruto = 0;
        }

        $weight_afecto = 0.0;
        $weight_exento = 0.0;
        foreach ($rows as $row) {
            $w = round((float) ($row['line_bruto'] ?? 0), 2);
            if ($w < 0) {
                $w = 0.0;
            }
            if (!empty($row['afecto_iva'])) {
                $weight_afecto = round($weight_afecto + $w, 2);
            } else {
                $weight_exento = round($weight_exento + $w, 2);
            }
        }
        $weight_total = round($weight_afecto + $weight_exento, 2);

        if ($weight_total > 0) {
            $bruto_exento = (int) round($anchor_bruto * ($weight_exento / $weight_total));
        } else {
            $bruto_exento = 0;
        }
        if ($bruto_exento < 0) {
            $bruto_exento = 0;
        }
        if ($bruto_exento > $anchor_bruto) {
            $bruto_exento = $anchor_bruto;
        }
        $bruto_afecto = $anchor_bruto - $bruto_exento;

        $neto_afecto = (int) round($bruto_afecto / self::IVA_FACTOR);
        if ($neto_afecto < 0) {
            $neto_afecto = 0;
        }
        if ($neto_afecto > $bruto_afecto) {
            $neto_afecto = $bruto_afecto;
        }
        $iva = $bruto_afecto - $neto_afecto;
        $exento = $bruto_exento;

        $rows = self::allocate_integer_by_weight($rows, true, $neto_afecto, $weight_afecto);
        $rows = self::allocate_integer_by_weight($rows, false, $exento, $weight_exento);

        foreach ($rows as &$row) {
            $qty = (float) ($row['quantity'] ?? 0);
            $line_net = round((float) ($row['line_net'] ?? 0), 2);
            $bruto_after = round((float) ($row['line_bruto'] ?? 0), 2);
            $bruto_before = round((float) ($row['line_bruto_before'] ?? $bruto_after), 2);
            $discount_pct = round((float) ($row['discount_pct'] ?? 0), 2);

            // Total de fila = neto CON descuento (ya repartido).
            // Valor unitario = lista SIN descuento.
            $neto_for_unit = $line_net;
            if ($discount_pct > 0 && $bruto_after > 0 && $bruto_before > $bruto_after) {
                $neto_for_unit = round($line_net * ($bruto_before / $bruto_after), 2);
            } elseif ($discount_pct > 0 && $discount_pct < 100) {
                $neto_for_unit = round($line_net / (1 - ($discount_pct / 100)), 2);
            }

            $priced = self::unit_price_for_line($neto_for_unit, $qty);
            $row['unit_net'] = $priced['unit'];
            $row['unit_decimals'] = $priced['decimals'];
            $row['line_net'] = $line_net;
            // Con descuento, Cant×Valor ≠ Total a propósito (Valor es lista).
            $row['unit_is_list_price'] = ($discount_pct > 0);
        }
        unset($row);

        return array(
            'rows' => $rows,
            'totals' => array(
                'monto_neto' => $neto_afecto,
                'monto_exento' => $exento,
                'iva' => $iva,
                'imp_esp' => 0,
                'total' => $anchor_bruto,
            ),
        );
    }

    /**
     * Reparte un monto entero entre filas afectas o exentas según peso bruto.
     * La última fila absorbe el residuo.
     *
     * @param array $rows
     * @param bool  $afecto
     * @param int   $total_int
     * @param float $weight_sum
     * @return array
     */
    private static function allocate_integer_by_weight(array $rows, $afecto, $total_int, $weight_sum) {
        $indexes = array();
        foreach ($rows as $i => $row) {
            $is_afecto = !empty($row['afecto_iva']);
            if ($is_afecto === (bool) $afecto) {
                $indexes[] = $i;
            }
        }
        if (!$indexes) {
            return $rows;
        }

        $total_int = (int) $total_int;
        $weight_sum = (float) $weight_sum;
        $floors = array();
        $fracs = array();
        $sum_floor = 0;
        foreach ($indexes as $i) {
            $w = (float) ($rows[$i]['line_bruto'] ?? 0);
            if ($w < 0) {
                $w = 0.0;
            }
            $exact = ($weight_sum > 0) ? ($total_int * $w / $weight_sum) : 0.0;
            $floor = (int) floor($exact + 1e-9);
            $floors[$i] = $floor;
            $fracs[$i] = $exact - $floor;
            $sum_floor += $floor;
        }
        $remain = $total_int - $sum_floor;
        if ($remain > 0) {
            arsort($fracs, SORT_NUMERIC);
            foreach (array_keys($fracs) as $i) {
                if ($remain <= 0) {
                    break;
                }
                $floors[$i]++;
                $remain--;
            }
        } elseif ($remain < 0) {
            asort($fracs, SORT_NUMERIC);
            foreach (array_keys($fracs) as $i) {
                if ($remain >= 0) {
                    break;
                }
                if ($floors[$i] <= 0) {
                    continue;
                }
                $floors[$i]--;
                $remain++;
            }
        }

        foreach ($floors as $i => $share) {
            if ($share < 0) {
                $share = 0;
            }
            $rows[$i]['line_net'] = (float) $share;
        }
        return $rows;
    }

    /**
     * Valor unitario con 2–6 decimales tal que round(Cant × Valor, 2) = Total.
     *
     * @param float $line_net
     * @param float $qty
     * @return array{unit:float,decimals:int}
     */
    private static function unit_price_for_line($line_net, $qty) {
        $line_net = round((float) $line_net, 2);
        $qty = (float) $qty;
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $target = $line_net;
        for ($decimals = 2; $decimals <= 6; $decimals++) {
            $unit = round($target / $qty, $decimals);
            if (abs(round($unit * $qty, 2) - $target) < 0.005) {
                return array(
                    'unit' => $unit,
                    'decimals' => $decimals,
                );
            }
        }
        // Último recurso: 6 decimales (puede diferir 1 centavo en casos extremos).
        return array(
            'unit' => round($target / $qty, 6),
            'decimals' => 6,
        );
    }

    /**
     * Bruto comercial de línea (después de descuento) y monto de descuento.
     *
     * @param array $line
     * @return array{bruto:float,discount:float}
     */
    private static function line_commercial(array $line) {
        $qty = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
        $price = round((float) (isset($line['unit_price']) ? $line['unit_price'] : 0), 4);
        $billable = self::family_units($line);
        $price_rate = self::rate(isset($line['price_discount']) ? $line['price_discount'] : 0);
        $margin_rate = self::rate(isset($line['margin_discount']) ? $line['margin_discount'] : 0);

        $gross = null;
        $mode = isset($line['price_mode']) ? strtolower(trim((string) $line['price_mode'])) : '';
        if ($mode === 'manual' && isset($line['price_total']) && $line['price_total'] !== null && $line['price_total'] !== '') {
            $gross = round((float) $line['price_total'], 2);
        } elseif (!empty($line['rule_adjusted']) && isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
            // Sin prorrateo de grupo aquí: al colapsar familia se suman line_net guardados.
            $gross = null;
        }
        if ($gross === null) {
            if (isset($line['line_net']) && $line['line_net'] !== null && $line['line_net'] !== '') {
                // line_net almacenado = bruto comercial después de descuento.
                $line_net = round((float) $line['line_net'], 2);
                $discount = round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2);
                if ($price_rate > 0 && $discount <= 0) {
                    // Reconstruir bruto previo si solo hay %.
                    $gross_before = $line_net / (1 - ($price_rate / 100));
                    $discount = round($gross_before - $line_net, 2);
                }
                return array(
                    'bruto' => $line_net,
                    'discount' => max(0.0, $discount),
                );
            }
            $gross = round($billable * round($price, 2), 2);
        }

        $discount = 0.0;
        if ($price_rate > 0) {
            $discount = round($gross * $price_rate / 100, 2);
        } elseif ($margin_rate > 0 && isset($line['unit_cost']) && $line['unit_cost'] !== null && $line['unit_cost'] !== '') {
            $unit_cost = round((float) $line['unit_cost'], 2);
            $margin_base = round($gross - round($billable * $unit_cost, 2), 2);
            if ($margin_base > 0) {
                $discount = round($margin_base * $margin_rate / 100, 2);
            }
        } else {
            $discount = round((float) (isset($line['discount_amount']) ? $line['discount_amount'] : 0), 2);
        }
        if ($discount < 0) {
            $discount = 0.0;
        }
        if ($discount > $gross) {
            $discount = $gross;
        }

        return array(
            'bruto' => round($gross - $discount, 2),
            'discount' => $discount,
        );
    }

    /**
     * @param array $line
     * @return bool
     */
    private static function is_unitaria_family(array $line) {
        $fam = isset($line['_family']) && is_array($line['_family']) ? $line['_family'] : array();
        $tipo = '';
        if (!empty($fam['tipo_comercial'])) {
            $tipo = strtolower(trim((string) $fam['tipo_comercial']));
        } elseif (!empty($fam['commercial']['tipo_comercial'])) {
            $tipo = strtolower(trim((string) $fam['commercial']['tipo_comercial']));
        }
        if ($tipo === 'pack' || $tipo === 'kit') {
            return false;
        }
        // unitario, vacío o solo familia exacta sin tipo comercial → colapsable.
        return self::line_grupo_id($line) > 0;
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
     * Unidades de familia (cantidad × units_per_pack).
     *
     * @param array $line
     * @return float
     */
    private static function family_units(array $line) {
        $qty = round((float) (isset($line['quantity']) ? $line['quantity'] : 0), 3);
        $upp = self::units_per_pack($line);
        return round($qty * $upp, 3);
    }

    /**
     * @param array $line
     * @return float
     */
    private static function units_per_pack(array $line) {
        $upp = isset($line['units_per_pack']) ? (float) $line['units_per_pack'] : 0.0;
        if ($upp <= 0 && isset($line['_family']['units_per_pack'])) {
            $upp = (float) $line['_family']['units_per_pack'];
        }
        if ($upp <= 0) {
            $upp = 1.0;
        }
        return $upp;
    }

    /**
     * @param array $line
     * @param float $upp
     * @return string
     */
    private static function presentation_label(array $line, $upp) {
        if (!empty($line['packaging'])) {
            return (string) $line['packaging'];
        }
        $qty_label = rtrim(rtrim(number_format((float) $upp, 3, '.', ''), '0'), '.');
        return '×' . $qty_label;
    }

    /**
     * @param float $bruto
     * @return float
     */
    private static function net_from_gross($bruto) {
        $bruto = (float) $bruto;
        if (class_exists('Riverso_Pricing_Module') && method_exists('Riverso_Pricing_Module', 'net_from_gross')) {
            $neto = Riverso_Pricing_Module::net_from_gross($bruto, 'afecto');
            return $neto === null ? 0.0 : (float) $neto;
        }
        return round($bruto / self::IVA_FACTOR, 4);
    }

    /**
     * @param mixed $value
     * @return float
     */
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
     * @param float $qty
     * @return string
     */
    private static function format_qty($qty) {
        $qty = (float) $qty;
        if (abs($qty - round($qty)) < 0.0005) {
            return (string) (int) round($qty);
        }
        return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',');
    }

    /**
     * @param string $datetime
     * @return string d-m-Y
     */
    private static function format_date($datetime) {
        $datetime = (string) $datetime;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $datetime, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return $datetime !== '' ? $datetime : date('d-m-Y');
    }

    /**
     * @param array $quote
     * @return array{name:string,email:string}
     */
    private static function resolve_seller(array $quote) {
        $name = (string) (isset($quote['seller_name']) ? $quote['seller_name'] : '');
        $email = (string) (isset($quote['seller_email']) ? $quote['seller_email'] : '');
        $uid = isset($quote['created_by']) ? (int) $quote['created_by'] : 0;
        if ($uid > 0 && function_exists('get_userdata')) {
            $user = get_userdata($uid);
            if ($user) {
                if ($name === '' && !empty($user->display_name)) {
                    $name = (string) $user->display_name;
                }
                if ($email === '' && !empty($user->user_email)) {
                    $email = (string) $user->user_email;
                }
            }
        }
        if ($email === '') {
            $company = self::company();
            $email = $company['email'];
        }
        return array(
            'name' => $name,
            'email' => $email,
        );
    }
}
