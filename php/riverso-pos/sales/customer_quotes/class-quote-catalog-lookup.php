<?php
/**
 * Búsqueda de productos para cotizaciones.
 * channel=online: WooCommerce publish|private (SKU|proveedor|barcode + lupa).
 * channel=local: Riverso product quick-view (producto_base) + pricing/familia POS.
 * No crea catálogo paralelo ni productos.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Catalog_Lookup {
    /**
     * @param string $query
     * @param int    $limit
     * @param string $mode    quick|advanced
     * @param string $scope   todo|descripcion|codigos (solo advanced)
     * @param string $channel local|online
     * @return array
     */
    public function search($query, $limit = 20, $mode = 'quick', $scope = 'todo', $channel = 'local') {
        $channel = $this->normalize_channel($channel);
        if ($channel === 'local') {
            return $this->search_local($query, $limit, $mode, $scope);
        }
        return $this->search_online($query, $limit, $mode, $scope);
    }

    /**
     * @param string $channel
     * @return string
     */
    public function normalize_channel($channel) {
        $channel = strtolower(trim((string) $channel));
        return $channel === 'online' ? 'online' : 'local';
    }

    /**
     * Online = lookup Woo actual (publish|private).
     */
    private function search_online($query, $limit = 20, $mode = 'quick', $scope = 'todo') {
        global $wpdb;
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        $mode = $mode === 'advanced' ? 'advanced' : 'quick';
        $scope = $this->normalize_scope($scope);
        if ($query === '' || !isset($wpdb->posts, $wpdb->postmeta)) {
            return array();
        }
        if ($mode === 'advanced' && $scope === 'descripcion' && $this->mb_len($query) < 2) {
            return array();
        }

        $ids = array();
        if ($mode === 'quick' || $scope === 'codigos' || $scope === 'todo') {
            $ids = array_merge($ids, $this->ids_by_codes($query, $limit));
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo') && $this->mb_len($query) >= 2) {
            $ids = array_merge($ids, $this->ids_by_description($query, $limit));
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $rows = $this->hydrate($ids, $query, $limit, $mode, $scope);
        foreach ($rows as &$row) {
            $row['channel'] = 'online';
            $row = $this->enrich_online_pricing($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * Local = quick-view (producto_base) + get_local_price / family helpers.
     */
    private function search_local($query, $limit = 20, $mode = 'quick', $scope = 'todo') {
        $query = trim((string) $query);
        $limit = max(1, (int) $limit);
        $mode = $mode === 'advanced' ? 'advanced' : 'quick';
        $scope = $this->normalize_scope($scope);
        if ($query === '') {
            return array();
        }
        if ($mode === 'advanced' && $scope === 'descripcion' && $this->mb_len($query) < 2) {
            return array();
        }

        $this->ensure_quick_view();
        if (!class_exists('Riverso_Product_Quick_View_Service')) {
            // Fallback legacy: tienda-local (ayuda), no catálogo paralelo.
            return $this->search_local_via_tienda($query, $limit);
        }

        $qv = Riverso_Product_Quick_View_Service::get_instance();
        $field = 'todos';
        if ($mode === 'quick' || $scope === 'codigos') {
            $field = 'codigos';
        } elseif ($scope === 'descripcion') {
            $field = 'nombre';
        }

        if ($mode === 'quick') {
            $hits = $qv->lookup_for_quotes($query, $limit);
            if (!$hits && $this->mb_len($query) >= 2) {
                $hits = $qv->search_for_quotes($query, 'todos', $limit);
            }
        } else {
            $hits = $qv->search_for_quotes($query, $field, $limit);
        }

        if (!is_array($hits) || !$hits) {
            // Legacy help only if quick-view vacío.
            $legacy = $this->search_local_via_tienda($query, $limit);
            if ($legacy) {
                return $legacy;
            }
            return array();
        }

        $out = array();
        foreach ($hits as $hit) {
            $mapped = $this->map_local_hit($hit);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $this->apply_internal_ean_quantity($out, $query);
    }

    /**
     * Si la consulta es un EAN13 interno 2SSSSSSQQQQQX, adjunta la cantidad
     * embebida (p. ej. 2000148002001 → SKU 148 × 200 uds).
     *
     * @param array  $rows
     * @param string $query
     * @return array
     */
    private function apply_internal_ean_quantity(array $rows, $query) {
        $query = preg_replace('/\D+/', '', trim((string) $query));
        if ($query === '' || !class_exists('Riverso_EAN13_Generator')) {
            $gen = RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-ean13-generator.php';
            if (!class_exists('Riverso_EAN13_Generator') && file_exists($gen)) {
                require_once $gen;
            }
        }
        if (!class_exists('Riverso_EAN13_Generator')) {
            return $rows;
        }
        $parsed = Riverso_EAN13_Generator::parse($query);
        if (!is_array($parsed) || empty($parsed['cantidad'])) {
            return $rows;
        }
        $qty = max(1, (int) $parsed['cantidad']);
        $payload = isset($parsed['sku']) ? (string) $parsed['sku'] : '';
        $payload_norm = ltrim($payload, '0');
        if ($payload_norm === '') {
            $payload_norm = '0';
        }

        $single = count($rows) === 1;
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = isset($row['sku']) ? (string) $row['sku'] : '';
            $sku_norm = ltrim($sku, '0');
            if ($sku_norm === '') {
                $sku_norm = '0';
            }
            $matches_sku = ($sku === $payload || $sku_norm === $payload_norm);
            if (!$single && !$matches_sku) {
                continue;
            }
            $row['quantity'] = $qty;
            $row['scan_quantity'] = $qty;
            $row['barcode'] = $query;
            $row['matched_barcode'] = $query;
            // Precio de regla con la qty del escaneo (luego el recalc de familia lo afina).
            $pb = isset($row['producto_base_id']) ? (int) $row['producto_base_id'] : 0;
            if ($pb > 0) {
                $price_pack = $this->local_price_pack($pb, (float) $qty);
                $row['unit_price'] = $price_pack['unit_price'];
                $row['unit_cost'] = $price_pack['unit_cost'];
                $row['has_local_price'] = !empty($price_pack['has_local_price']);
                $row['sin_precio_local'] = !empty($price_pack['sin_precio_local']);
                $row['p_asignado'] = $price_pack['p_asignado'];
                $row['local_price'] = $price_pack['local_price'];
                if (isset($price_pack['rule_total'])) {
                    $row['rule_total'] = $price_pack['rule_total'];
                    $row['rule_adjusted'] = !empty($price_pack['rule_adjusted']);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array $hit hydrate_grid_rows shape
     * @return array|null
     */
    private function map_local_hit(array $hit) {
        $pb_id = isset($hit['id']) ? (int) $hit['id'] : (isset($hit['producto_base_id']) ? (int) $hit['producto_base_id'] : 0);
        if ($pb_id <= 0) {
            return null;
        }
        $sku = isset($hit['canonical_sku']) ? (string) $hit['canonical_sku'] : (isset($hit['sku']) ? (string) $hit['sku'] : '');
        if ($sku === '') {
            $sku = 'PB-' . $pb_id;
        }

        $wc_id = $this->resolve_wc_product_id($pb_id);
        $price_pack = $this->local_price_pack($pb_id, 1.0);
        $family = $this->family_offers_for_base($pb_id);

        $has_local_price = ((float) $price_pack['unit_price']) > 0;
        return array(
            'product_id' => $wc_id > 0 ? $wc_id : null,
            'producto_base_id' => $pb_id,
            'sku' => $sku,
            'barcode' => isset($hit['barcode']) ? (string) $hit['barcode'] : '',
            'supplier_code' => isset($hit['codigo_proveedor']) ? (string) $hit['codigo_proveedor'] : (isset($hit['supplier_code']) ? (string) $hit['supplier_code'] : ''),
            'description' => isset($hit['nombre']) ? (string) $hit['nombre'] : (isset($hit['description']) ? (string) $hit['description'] : $sku),
            'unit_price' => $price_pack['unit_price'],
            'unit_cost' => $price_pack['unit_cost'],
            'has_local_price' => $has_local_price,
            'sin_precio_local' => !$has_local_price,
            'p_asignado' => $price_pack['p_asignado'],
            'local_price' => $price_pack['local_price'],
            'channel' => 'local',
            'local_only' => $wc_id <= 0,
            'family_mode' => isset($family['default_mode']) ? $family['default_mode'] : 'unitaria',
            'units_per_pack' => isset($family['units_per_pack']) ? $family['units_per_pack'] : 1.0,
            'packaging' => isset($family['packaging']) ? $family['packaging'] : '',
            'family' => $family,
            'facturar_ok' => $wc_id > 0,
        );
    }

    /**
     * Legacy help: tienda-local search → misma forma CQ.
     */
    private function search_local_via_tienda($query, $limit) {
        if (!class_exists('Riverso_Tienda_Local_Module')) {
            return array();
        }
        $mod = Riverso_Tienda_Local_Module::get_instance();
        if (!method_exists($mod, 'search')) {
            return array();
        }
        $result = $mod->search($query);
        $items = is_array($result) && isset($result['items']) && is_array($result['items']) ? $result['items'] : array();
        $out = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pb = isset($item['producto_base_id']) ? (int) $item['producto_base_id'] : 0;
            if ($pb <= 0) {
                continue;
            }
            $mapped = $this->map_local_hit(array(
                'id' => $pb,
                'canonical_sku' => isset($item['sku']) ? $item['sku'] : '',
                'nombre' => isset($item['nombre']) ? $item['nombre'] : (isset($item['name']) ? $item['name'] : ''),
                'barcode' => isset($item['barcode']) ? $item['barcode'] : (isset($item['matched_barcode']) ? $item['matched_barcode'] : ''),
                'codigo_proveedor' => isset($item['codigo_proveedor']) ? $item['codigo_proveedor'] : '',
            ));
            if ($mapped !== null) {
                $out[] = $mapped;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $this->apply_internal_ean_quantity($out, $query);
    }

    /**
     * Precio local + regla de familia (qty agregada).
     *
     * @param int         $producto_base_id
     * @param float       $family_qty
     * @param float|null  $p_override
     * @param string|null $rule_mode  auto|std|manual (manual = sin aplicar regla)
     * @return array
     */
    public function local_price_pack($producto_base_id, $family_qty = 1.0, $p_override = null, $rule_mode = null) {
        $producto_base_id = (int) $producto_base_id;
        $family_qty = (float) $family_qty;
        if ($family_qty <= 0) {
            $family_qty = 1.0;
        }
        $rule_mode = strtolower(trim((string) $rule_mode));
        if (!in_array($rule_mode, array('auto', 'std', 'manual', 'ref'), true)) {
            $rule_mode = 'auto';
        }
        // ref usa la misma regla asignada que auto, con P override.
        if ($rule_mode === 'ref') {
            $rule_mode = 'auto';
        }

        $unit_price = 0.0;
        $unit_cost = null;
        $local_price = null;
        $rule_price = null;
        $rule_total = null;
        $rule_adjusted = false;
        $unitario0 = null;
        $p_asignado = null;
        $c_ref = null;
        $has_rule = false;
        $rule_codigo = null;
        $rule_nombre = null;
        $assigned_rule_codigo = null;
        $assigned_rule_nombre = null;
        $std_rule = null;
        $p_override = ($p_override === null || $p_override === '') ? null : (float) $p_override;
        if ($p_override !== null && $p_override <= 0) {
            $p_override = null;
        }

        $rules_mod = class_exists('Riverso_Price_Rules_Module')
            ? Riverso_Price_Rules_Module::get_instance()
            : null;
        if ($rules_mod) {
            $std = $rules_mod->get_standard_rule();
            if ($std) {
                $std_rule = array(
                    'codigo' => $std['codigo'],
                    'nombre' => $std['nombre'],
                );
            }
        }

        if ($producto_base_id > 0 && class_exists('Riverso_Pricing_Module')) {
            $pricing = Riverso_Pricing_Module::get_instance();
            // P de catálogo: preferir unitario de familia vía resolve_p_asignado.
            if ($rules_mod) {
                $p_asignado = $rules_mod->resolve_p_asignado_for_base($producto_base_id);
            }
            $row = $pricing->get_local_price($producto_base_id);
            if (is_array($row)) {
                if ($p_asignado === null && isset($row['p_asignado']) && $row['p_asignado'] !== null && $row['p_asignado'] !== '') {
                    $p_asignado = (float) $row['p_asignado'];
                }
                if ($p_asignado !== null) {
                    if (!isset($row['estado_aprobacion']) || $row['estado_aprobacion'] === 'aprobado' || $row['estado_aprobacion'] === '' || $row['estado_aprobacion'] === null) {
                        $local_price = $p_asignado;
                        $unit_price = $p_asignado;
                    } elseif ($local_price === null) {
                        $local_price = $p_asignado;
                        $unit_price = $p_asignado;
                    }
                }
                if (isset($row['c_ref']) && $row['c_ref'] !== null && $row['c_ref'] !== '') {
                    $c_ref = (float) $row['c_ref'];
                    $unit_cost = $c_ref;
                }
            }
            if ($p_override !== null) {
                $local_price = $p_override;
                $unit_price = $p_override;
            }

            if ($rules_mod && $rule_mode !== 'manual') {
                $detail = null;
                if ($rule_mode === 'std') {
                    $std = $rules_mod->get_standard_rule();
                    if ($std && !empty($std['tiers'])) {
                        $detail = $rules_mod->apply_rule_tiers_detail(
                            $std['tiers'],
                            $producto_base_id,
                            $family_qty,
                            $local_price
                        );
                        if ($detail) {
                            $rule_codigo = $std['codigo'];
                            $rule_nombre = $std['nombre'];
                        }
                    }
                } else {
                    $detail = $rules_mod->apply_for_base_detail(
                        $producto_base_id,
                        $family_qty,
                        $local_price
                    );
                    if ($detail) {
                        $rule_codigo = isset($detail['rule_codigo']) ? $detail['rule_codigo'] : null;
                        $rule_nombre = isset($detail['rule_nombre']) ? $detail['rule_nombre'] : null;
                    }
                }
                if (is_array($detail) && isset($detail['price'])) {
                    $rule_price = (float) $detail['price'];
                    $unit_price = $rule_price;
                    $rule_total = isset($detail['total']) ? (float) $detail['total'] : null;
                    $rule_adjusted = !empty($detail['adjusted']);
                    $has_rule = true;
                    if (isset($detail['breakdown']['unitario0'])) {
                        $unitario0 = (float) $detail['breakdown']['unitario0'];
                    }
                }
            }

            if ($rules_mod) {
                $assigned_meta = $rules_mod->get_resolved_rule_meta($producto_base_id);
                if ($assigned_meta) {
                    $assigned_rule_codigo = $assigned_meta['codigo'];
                    $assigned_rule_nombre = $assigned_meta['nombre'];
                    if ($rule_codigo === null && $rule_mode !== 'std') {
                        $rule_codigo = $assigned_meta['codigo'];
                        $rule_nombre = $assigned_meta['nombre'];
                        $has_rule = true;
                    }
                }
            }
        }

        // Misma resolución de coste que Productos (Price_Lookup + familia/lotes).
        // Cotizaciones usan coste unitario bruto × billable (qty × units_per_pack).
        if ($unit_cost === null || $unit_cost <= 0) {
            $resolved = $this->resolve_unit_cost_bruto($producto_base_id);
            if ($resolved !== null && $resolved > 0) {
                $unit_cost = $resolved;
                if ($c_ref === null) {
                    $c_ref = $resolved;
                }
            }
        }

        // Si la regla ajustó el total (formula_total / piso), conservar hasta 4 decimales
        // del motor; si no, redondear a 2 como precio de lista habitual.
        $unit_price_r = $rule_adjusted
            ? round((float) $unit_price, 4)
            : round((float) $unit_price, 2);
        return array(
            'unit_price' => $unit_price_r,
            'unit_cost' => $unit_cost === null ? null : round((float) $unit_cost, 2),
            'local_price' => $local_price,
            'rule_price' => $rule_price,
            'rule_total' => $rule_total,
            'rule_adjusted' => $rule_adjusted,
            'unitario0' => $unitario0,
            'p_asignado' => $p_asignado,
            'c_ref' => $c_ref,
            'has_rule' => $has_rule,
            'rule_codigo' => $rule_codigo,
            'rule_nombre' => $rule_nombre,
            'assigned_rule_codigo' => $assigned_rule_codigo,
            'assigned_rule_nombre' => $assigned_rule_nombre,
            'std_rule' => $std_rule,
            'rule_mode' => $rule_mode,
            'has_local_price' => $unit_price_r > 0,
            'sin_precio_local' => $unit_price_r <= 0,
            'family_qty' => $family_qty,
            'producto_base_id' => $producto_base_id,
            'p_override' => $p_override,
        );
    }

    /**
     * Cantidad a entregar para un monto total deseado (antes de descuentos).
     *
     * @param int   $producto_base_id
     * @param float $monto
     * @param array $opts rule_mode, p_ref, units_per_pack, others_units
     * @return array
     */
    public function qty_for_amount($producto_base_id, $monto, array $opts = array()) {
        $producto_base_id = (int) $producto_base_id;
        $monto = (float) $monto;
        $empty = array(
            'debajo' => null,
            'arriba' => null,
            'exacto' => false,
            'minimo' => null,
        );
        if ($producto_base_id <= 0 || $monto <= 0) {
            return $empty;
        }

        $rule_mode = isset($opts['rule_mode']) ? strtolower(trim((string) $opts['rule_mode'])) : 'auto';
        if (!in_array($rule_mode, array('auto', 'std', 'manual', 'ref'), true)) {
            $rule_mode = 'auto';
        }
        $p_ref = isset($opts['p_ref']) && $opts['p_ref'] !== null && $opts['p_ref'] !== ''
            ? (float) $opts['p_ref'] : null;
        if ($p_ref !== null && $p_ref <= 0) {
            $p_ref = null;
        }
        $upp = isset($opts['units_per_pack']) ? (float) $opts['units_per_pack'] : 1.0;
        if ($upp <= 0) {
            $upp = 1.0;
        }
        $step = max(1, (int) round($upp));
        // Si upp no es entero, buscar en unidades y reportar qty en packs fraccionables ≈ units/upp.
        $step_units = (abs($upp - $step) < 0.0001) ? $step : 1;
        $others = isset($opts['others_units']) ? max(0.0, (float) $opts['others_units']) : 0.0;

        $pack = $this->local_price_pack($producto_base_id, 1.0, $p_ref, $rule_mode === 'ref' ? 'auto' : $rule_mode);
        $p = $p_ref !== null ? $p_ref : (isset($pack['p_asignado']) ? $pack['p_asignado'] : null);
        if ($p === null || $p <= 0) {
            $p = isset($pack['unit_price']) ? (float) $pack['unit_price'] : 0.0;
        }

        $tiers = array();
        $rules_mod = class_exists('Riverso_Price_Rules_Module')
            ? Riverso_Price_Rules_Module::get_instance()
            : null;

        if ($rule_mode === 'manual' || !$rules_mod) {
            return $this->qty_for_amount_flat($p > 0 ? $p : (float) $pack['unit_price'], $monto, $upp);
        }

        if ($rule_mode === 'std') {
            $std = $rules_mod->get_standard_rule();
            $tiers = $std && !empty($std['tiers']) ? $std['tiers'] : array();
        } else {
            $meta = $rules_mod->get_resolved_rule_meta($producto_base_id);
            if ($meta) {
                $tiers = $rules_mod->get_tiers($meta['id']) ?: array();
            }
        }

        if (empty($tiers)) {
            $unit = (float) $pack['unit_price'];
            if ($unit <= 0 && $p > 0) {
                $unit = $p;
            }
            return $this->qty_for_amount_flat($unit, $monto, $upp);
        }

        if ($others > 0.0001) {
            $result = Riverso_Price_Rule_Engine::qty_for_line_share($tiers, $p, $monto, $step_units, $others);
        } else {
            $result = Riverso_Price_Rule_Engine::qty_for_total($tiers, $p, $monto, $step_units);
        }

        return $this->normalize_qty_amount_result($result, $upp, $step_units);
    }

    /**
     * Sin regla: k = floor(monto / (unit × upp)).
     */
    private function qty_for_amount_flat($unit_price, $monto, $upp) {
        $unit = max(0.01, (float) $unit_price);
        $upp = max(0.0001, (float) $upp);
        $pack_price = $unit * $upp;
        $k = (int) floor($monto / $pack_price);
        $empty = array(
            'debajo' => null,
            'arriba' => null,
            'exacto' => false,
            'minimo' => null,
        );
        $min_total = round($pack_price, 2);
        $minimo = array(
            'qty' => 1,
            'packs' => 1,
            'units' => $upp,
            'total' => $min_total,
            'unitario' => round($unit, 4),
        );
        if ($k < 1) {
            return array(
                'debajo' => null,
                'arriba' => $minimo,
                'exacto' => false,
                'minimo' => $minimo,
            );
        }
        $debajo_total = round($pack_price * $k, 2);
        $debajo = array(
            'qty' => $k,
            'packs' => $k,
            'units' => $k * $upp,
            'total' => $debajo_total,
            'unitario' => round($unit, 4),
        );
        $exacto = abs($debajo_total - $monto) < 0.01;
        $arriba = null;
        if (!$exacto) {
            $arriba = array(
                'qty' => $k + 1,
                'packs' => $k + 1,
                'units' => ($k + 1) * $upp,
                'total' => round($pack_price * ($k + 1), 2),
                'unitario' => round($unit, 4),
            );
        }
        return array(
            'debajo' => $debajo,
            'arriba' => $arriba,
            'exacto' => $exacto,
            'minimo' => $minimo,
        );
    }

    /**
     * Convierte qty en unidades del motor a cantidad de línea (paquetes).
     */
    private function normalize_qty_amount_result(array $result, $upp, $step_units) {
        $upp = max(0.0001, (float) $upp);
        $map = function ($row) use ($upp, $step_units) {
            if (!$row) {
                return null;
            }
            $units = (int) $row['qty'];
            $packs = ($step_units > 1)
                ? (int) floor($units / $step_units)
                : (int) round($units / $upp);
            if ($packs < 1 && $units > 0) {
                $packs = max(1, (int) ceil($units / $upp));
            }
            return array(
                'qty' => $packs,
                'packs' => $packs,
                'units' => $units,
                'total' => (float) $row['total'],
                'unitario' => (float) $row['unitario'],
            );
        };
        return array(
            'debajo' => $map(isset($result['debajo']) ? $result['debajo'] : null),
            'arriba' => $map(isset($result['arriba']) ? $result['arriba'] : null),
            'exacto' => !empty($result['exacto']),
            'minimo' => $map(isset($result['minimo']) ? $result['minimo'] : null),
        );
    }

    /**
     * Coste unitario bruto para margen de cotización.
     * Alinea con Productos: Price_Lookup (legacy) y desglose familia (lote/qty).
     *
     * @param int $producto_base_id
     * @return float|null
     */
    private function resolve_unit_cost_bruto($producto_base_id) {
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return null;
        }

        if (!class_exists('Riverso_Price_Lookup_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists('Riverso_Price_Lookup_Service')) {
            $pack = Riverso_Price_Lookup_Service::get_instance()->get_local_price_pack($producto_base_id);
            if (is_array($pack)) {
                $bruto = null;
                if (isset($pack['c_ref_bruto']) && $pack['c_ref_bruto'] !== null && $pack['c_ref_bruto'] !== '') {
                    $bruto = (float) $pack['c_ref_bruto'];
                } elseif (isset($pack['c_ref']) && $pack['c_ref'] !== null && $pack['c_ref'] !== '') {
                    $bruto = (float) $pack['c_ref'];
                }
                if ($bruto !== null && $bruto > 0) {
                    return $bruto;
                }
            }
        }

        if (!class_exists('Riverso_Unit_Product_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Unit_Product_Service')) {
            return null;
        }

        $unit_svc = Riverso_Unit_Product_Service::get_instance();
        $ctx = $unit_svc->resolve_family_unit_for_base($producto_base_id);
        if (!$ctx || empty($ctx['grupo_id'])) {
            return null;
        }

        $iva_tipo = 'afecto';
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $iva_row = $wpdb->get_var($wpdb->prepare(
            "SELECT facto_iva_tipo FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ));
        if (class_exists('Riverso_Pricing_Module')) {
            $iva_tipo = Riverso_Pricing_Module::normalize_iva_tipo($iva_row ?: 'afecto');
        }

        $coste = $unit_svc->calculate_coste_unitario((int) $ctx['grupo_id']);
        $coste_u_neto = null;
        foreach ((array) ($coste['breakdown'] ?? array()) as $bd) {
            if ((int) ($bd['producto_base_id'] ?? 0) !== $producto_base_id) {
                continue;
            }
            if (isset($bd['coste_unitario']) && $bd['coste_unitario'] !== null && $bd['coste_unitario'] !== '') {
                $coste_u_neto = (float) $bd['coste_unitario'];
            } elseif (isset($bd['costo_presentacion']) && $bd['costo_presentacion'] !== null) {
                $qty = isset($bd['cantidad_unidades']) ? (float) $bd['cantidad_unidades'] : 0.0;
                if ($qty > 0) {
                    $coste_u_neto = (float) $bd['costo_presentacion'] / $qty;
                }
            }
            break;
        }

        // Producto unitario de la familia: usar el MAX coste_unitario del grupo.
        if ($coste_u_neto === null
            && (int) ($ctx['unit_producto_base_id'] ?? 0) === $producto_base_id
            && isset($coste['coste']) && $coste['coste'] !== null
        ) {
            $coste_u_neto = (float) $coste['coste'];
        }

        if ($coste_u_neto === null || $coste_u_neto <= 0) {
            return null;
        }

        if (class_exists('Riverso_Pricing_Module')) {
            return (float) Riverso_Pricing_Module::gross_from_net($coste_u_neto, $iva_tipo);
        }
        return $coste_u_neto;
    }

    /**
     * Ofertas Unitaria / Pack / Kit desde family + commercial + unit services.
     *
     * @param int $producto_base_id
     * @return array
     */
    public function family_offers_for_base($producto_base_id) {
        $producto_base_id = (int) $producto_base_id;
        $offers = array(
            'grupo_id' => null,
            'default_mode' => 'unitaria',
            'modes' => array('unitaria'),
            'unitaria' => null,
            'pack' => null,
            'kit' => null,
            'units_per_pack' => 1.0,
            'packaging' => 'unitaria',
            'envases' => array(),
            'pack_tiers' => array(),
            'members' => array(),
            'n_a_pack_kit' => true,
            'n_a_reason' => 'Sin familia comercial Pack/Kit para este producto.',
        );
        if ($producto_base_id <= 0) {
            return $offers;
        }

        $grupo_id = 0;
        if (class_exists('Riverso_Family_Module')) {
            $fam = Riverso_Family_Module::get_instance()->get_exacta_family_of_product($producto_base_id);
            if (is_array($fam) && !empty($fam['grupo_id'])) {
                $grupo_id = (int) $fam['grupo_id'];
                $offers['grupo_id'] = $grupo_id;
                $offers['family_name'] = isset($fam['nombre']) ? (string) $fam['nombre'] : '';
                $offers['family_code'] = isset($fam['codigo_grupo']) ? (string) $fam['codigo_grupo'] : '';
            }
        }

        $envase = null;
        if (class_exists('Riverso_Unit_Product_Service')) {
            $unit_svc = Riverso_Unit_Product_Service::get_instance();
            $envase = $unit_svc->get_canonical_envase($producto_base_id);
            if (is_array($envase)) {
                $qty = isset($envase['cantidad_unidades']) ? (float) $envase['cantidad_unidades'] : 1.0;
                if ($qty <= 0) {
                    $qty = 1.0;
                }
                $offers['units_per_pack'] = $qty;
                $offers['envases'][] = array(
                    'envase_id' => isset($envase['id']) ? (int) $envase['id'] : 0,
                    'tipo_envase' => isset($envase['tipo_envase']) ? (string) $envase['tipo_envase'] : 'envase',
                    'cantidad_unidades' => $qty,
                    'label' => (isset($envase['tipo_envase']) ? (string) $envase['tipo_envase'] : 'envase') . ' × ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                );
            }
            if ($grupo_id > 0) {
                $unit_snap = $unit_svc->get_unit_snapshot($grupo_id);
                if (!is_wp_error($unit_snap) && is_array($unit_snap)) {
                    $offers['unitaria'] = array(
                        'available' => true,
                        'unit_producto_base_id' => isset($unit_snap['unit_producto_base_id']) ? (int) $unit_snap['unit_producto_base_id'] : (isset($unit_snap['unit']['id']) ? (int) $unit_snap['unit']['id'] : null),
                        'snapshot' => $unit_snap,
                    );
                    $packs = $unit_svc->get_pack_members_by_qty($grupo_id);
                    if (is_array($packs) && $packs) {
                        $offers['pack_members'] = $packs;
                    }
                }
                $offers['members'] = $this->family_members_for_group(
                    $grupo_id,
                    isset($offers['unitaria']['unit_producto_base_id']) ? (int) $offers['unitaria']['unit_producto_base_id'] : 0,
                    $unit_svc
                );
            }
        }

        if ($grupo_id > 0 && class_exists('Riverso_Family_Commercial_Service')) {
            $commercial = Riverso_Family_Commercial_Service::get_instance()->get_snapshot($grupo_id);
            if (!is_wp_error($commercial) && is_array($commercial)) {
                $tipo = isset($commercial['tipo_comercial']) ? (string) $commercial['tipo_comercial'] : '';
                $offers['tipo_comercial'] = $tipo;
                $offers['commercial'] = array(
                    'tipo_comercial' => $tipo,
                    'pack_modo' => isset($commercial['pack_modo']) ? $commercial['pack_modo'] : null,
                    'pack_tiers' => isset($commercial['pack_tiers']) ? $commercial['pack_tiers'] : array(),
                    'kit_components' => isset($commercial['kit_components']) ? $commercial['kit_components'] : array(),
                    'kit_pricing' => isset($commercial['kit_pricing']) ? $commercial['kit_pricing'] : null,
                );
                if ($tipo === 'pack' && !empty($commercial['pack_tiers'])) {
                    $offers['modes'][] = 'pack';
                    $offers['pack'] = array(
                        'available' => true,
                        'tiers' => $commercial['pack_tiers'],
                    );
                    $offers['pack_tiers'] = $commercial['pack_tiers'];
                    $offers['n_a_pack_kit'] = false;
                    $offers['n_a_reason'] = '';
                }
                if ($tipo === 'kit' && !empty($commercial['kit_components'])) {
                    $offers['modes'][] = 'kit';
                    $offers['kit'] = array(
                        'available' => true,
                        'components' => $commercial['kit_components'],
                        'pricing' => isset($commercial['kit_pricing']) ? $commercial['kit_pricing'] : null,
                    );
                    $offers['n_a_pack_kit'] = false;
                    $offers['n_a_reason'] = '';
                }
                if ($tipo === 'unitario' || $tipo === '') {
                    $offers['default_mode'] = 'unitaria';
                } elseif ($tipo === 'pack') {
                    $offers['default_mode'] = 'pack';
                } elseif ($tipo === 'kit') {
                    $offers['default_mode'] = 'kit';
                }
            }
        }

        if ($grupo_id > 0 && empty($offers['members'])) {
            $unit_id = isset($offers['unitaria']['unit_producto_base_id'])
                ? (int) $offers['unitaria']['unit_producto_base_id']
                : 0;
            $offers['members'] = $this->family_members_for_group($grupo_id, $unit_id, null);
        }

        $offers['modes'] = array_values(array_unique($offers['modes']));
        return $offers;
    }

    /**
     * Miembros activos de una familia exacta para el selector de cotización.
     *
     * @param int                              $grupo_id
     * @param int                              $unit_producto_base_id
     * @param Riverso_Unit_Product_Service|null $unit_svc
     * @return array<int, array{producto_base_id:int,product_id:?int,sku:string,description:string,cantidad_unidades:float,es_unitario:bool,label:string}>
     */
    public function family_members_for_group($grupo_id, $unit_producto_base_id = 0, $unit_svc = null) {
        global $wpdb;
        $grupo_id = (int) $grupo_id;
        $unit_producto_base_id = (int) $unit_producto_base_id;
        if ($grupo_id <= 0) {
            return array();
        }
        if ($unit_svc === null && class_exists('Riverso_Unit_Product_Service')) {
            $unit_svc = Riverso_Unit_Product_Service::get_instance();
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id, pb.es_unidad_minima
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id AND pb.deleted_at IS NULL
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY pb.nombre_canonico ASC",
            $grupo_id
        ), ARRAY_A);
        if (!is_array($rows) || !$rows) {
            return array();
        }

        if ($unit_producto_base_id <= 0) {
            $unit_producto_base_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT unit_producto_base_id FROM {$prefix}equivalence_groups WHERE id = %d",
                $grupo_id
            ));
        }

        $members = array();
        foreach ($rows as $row) {
            $base_id = (int) $row['producto_base_id'];
            $qty = 1.0;
            if ($unit_svc) {
                $envase = $unit_svc->get_canonical_envase($base_id);
                if (is_array($envase) && isset($envase['cantidad_unidades'])) {
                    $qty = (float) $envase['cantidad_unidades'];
                }
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $is_unit = $unit_producto_base_id > 0
                ? ($base_id === $unit_producto_base_id)
                : ((int) ($row['es_unidad_minima'] ?? 0) === 1 || $qty <= 1.0001);
            if ($is_unit) {
                $qty = 1.0;
            }
            $wc = $this->resolve_wc_product_id($base_id);
            if ($wc <= 0) {
                $var = (int) ($row['woocommerce_variation_id'] ?? 0);
                $prod = (int) ($row['woocommerce_product_id'] ?? 0);
                $wc = $var > 0 ? $var : $prod;
            }
            $qty_label = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
            $sku_local = trim((string) ($row['canonical_sku'] ?? ''));
            $members[] = array(
                'producto_base_id' => $base_id,
                'product_id' => $wc > 0 ? $wc : null,
                'sku' => $sku_local,
                'sku_local' => $sku_local,
                'has_local_sku' => $sku_local !== '',
                'es_local' => $sku_local !== '',
                'description' => (string) ($row['nombre_canonico'] ?? ''),
                'cantidad_unidades' => $qty,
                'es_unitario' => (bool) $is_unit,
                'label' => $is_unit ? 'embolsado' : ('×' . $qty_label),
            );
        }

        usort($members, static function ($a, $b) {
            if (!empty($a['es_unitario']) && empty($b['es_unitario'])) {
                return -1;
            }
            if (empty($a['es_unitario']) && !empty($b['es_unitario'])) {
                return 1;
            }
            $qa = (float) ($a['cantidad_unidades'] ?? 1);
            $qb = (float) ($b['cantidad_unidades'] ?? 1);
            if ($qa === $qb) {
                return strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
            }
            return $qb <=> $qa;
        });

        return $members;
    }

    /**
     * Adjunta ofertas de familia (_family) a cada línea con producto_base_id.
     *
     * @param array $quote
     * @return array
     */
    public function hydrate_quote_families(array $quote) {
        if (empty($quote['lines']) || !is_array($quote['lines'])) {
            return $quote;
        }
        $cache = array();
        foreach ($quote['lines'] as &$line) {
            if (!is_array($line)) {
                continue;
            }
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            if ($pb <= 0) {
                continue;
            }
            if (!isset($cache[$pb])) {
                $cache[$pb] = $this->family_offers_for_base($pb);
            }
            $fam = $cache[$pb];
            $line['_family'] = $fam;
            if ((!isset($line['units_per_pack']) || $line['units_per_pack'] === null || (float) $line['units_per_pack'] <= 0)
                && isset($fam['units_per_pack'])
            ) {
                $line['units_per_pack'] = (float) $fam['units_per_pack'];
            }
        }
        unset($line);
        return $quote;
    }

    /**
     * @param int $producto_base_id
     * @return int WC product id or 0
     */
    public function resolve_wc_product_id($producto_base_id) {
        global $wpdb;
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return 0;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base WHERE id = %d LIMIT 1",
            $producto_base_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return 0;
        }
        $var = isset($row['woocommerce_variation_id']) ? (int) $row['woocommerce_variation_id'] : 0;
        $pid = isset($row['woocommerce_product_id']) ? (int) $row['woocommerce_product_id'] : 0;
        if ($var > 0) {
            return $var;
        }
        return $pid > 0 ? $pid : 0;
    }

    private function enrich_online_pricing(array $row) {
        $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
        if ($pid <= 0 || !class_exists('Riverso_Pricing_Module')) {
            return $row;
        }
        $pricing = Riverso_Pricing_Module::get_instance();
        $base_id = 0;
        if (method_exists($pricing, 'get_base_id_by_wc')) {
            $base_id = (int) $pricing->get_base_id_by_wc($pid, 0);
            if ($base_id <= 0 && function_exists('wc_get_product')) {
                $p = wc_get_product($pid);
                if ($p && $p->is_type('variation')) {
                    $base_id = (int) $pricing->get_base_id_by_wc((int) $p->get_parent_id(), $pid);
                }
            }
        }
        if ($base_id > 0) {
            $row['producto_base_id'] = $base_id;
            $online = $pricing->get_online_price($base_id, $pid);
            if (is_array($online) && isset($online['p_asignado']) && $online['p_asignado'] !== null
                && (!isset($online['estado_aprobacion']) || $online['estado_aprobacion'] === 'aprobado')
            ) {
                $row['unit_price'] = round((float) $online['p_asignado'], 2);
                $row['online_price'] = (float) $online['p_asignado'];
            }
        }
        $row['local_only'] = false;
        $row['facturar_ok'] = true;
        return $row;
    }

    private function ensure_quick_view() {
        if (class_exists('Riverso_Product_Quick_View_Service')) {
            return;
        }
        $path = defined('RIVERSO_POS_PLUGIN_DIR')
            ? RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-quick-view-service.php'
            : dirname(__FILE__) . '/../../modules/products/class-product-quick-view-service.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    private function normalize_scope($scope) {
        $scope = strtolower(trim((string) $scope));
        if ($scope === 'descripcion' || $scope === 'codigos') {
            return $scope;
        }
        return 'todo';
    }

    /**
     * Filtra productos: cada palabra debe aparecer (AND) en el texto según scope.
     *
     * @param array    $products
     * @param string[] $words
     * @param string   $scope
     * @return array
     */
    public function filter_contains_words(array $products, array $words, $scope = 'todo') {
        $words = array_values(array_filter(array_map(function ($w) {
            $w = trim(preg_replace('/\s+/u', ' ', (string) $w));
            return $w;
        }, $words)));
        if (!$words) {
            return $products;
        }
        $scope = $this->normalize_scope($scope);
        $out = array();
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $hay = $this->contains_haystack($product, $scope);
            $ok = true;
            foreach ($words as $word) {
                if (!$this->mb_contains($hay, $word)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $product;
            }
        }
        return $out;
    }

    /**
     * @param array  $product
     * @param string $scope
     * @return string
     */
    private function contains_haystack(array $product, $scope) {
        $parts = array();
        if ($scope === 'codigos') {
            $parts[] = isset($product['sku']) ? (string) $product['sku'] : '';
            $parts[] = isset($product['barcode']) ? (string) $product['barcode'] : '';
            $parts[] = isset($product['supplier_code']) ? (string) $product['supplier_code'] : '';
        } elseif ($scope === 'descripcion') {
            $parts[] = isset($product['description']) ? (string) $product['description'] : '';
        } else {
            $parts[] = isset($product['description']) ? (string) $product['description'] : '';
            $parts[] = isset($product['sku']) ? (string) $product['sku'] : '';
            $parts[] = isset($product['barcode']) ? (string) $product['barcode'] : '';
            $parts[] = isset($product['supplier_code']) ? (string) $product['supplier_code'] : '';
        }
        return implode(' ', $parts);
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function mb_contains($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle = (string) $needle;
        if ($needle === '') {
            return true;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    private function mb_len($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function ids_by_codes($query, $limit) {
        global $wpdb;
        $meta_keys = array('_sku', '_barcode', '_global_unique_id', '_riverso_supplier_code', '_supplier_sku');
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
              AND m.meta_key IN ($placeholders)
              AND (m.meta_value = %s OR m.meta_value LIKE %s)
            LIMIT %d";
        $params = array_merge($meta_keys, array($query, $like, max($limit * 4, $limit)));
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));
        return is_array($ids) ? $ids : array();
    }

    private function ids_by_description($query, $limit) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($query) . '%';
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
              AND (
                    p.post_title LIKE %s
                 OR p.post_excerpt LIKE %s
                 OR p.post_content LIKE %s
              )
            LIMIT %d";
        $cap = max($limit * 4, $limit);
        $ids = $wpdb->get_col($wpdb->prepare($sql, $like, $like, $like, $cap));
        if (!is_array($ids)) {
            $ids = array();
        }
        $ids = $this->expand_variable_parents_to_variations($ids, $cap);
        return $ids;
    }

    private function expand_variable_parents_to_variations(array $ids, $cap) {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === array()) {
            return array();
        }
        $out = $ids;
        $id_list = implode(',', array_map('intval', $ids));
        $children = $wpdb->get_col(
            "SELECT c.ID
             FROM {$wpdb->posts} c
             INNER JOIN {$wpdb->posts} parent ON parent.ID = c.post_parent
             WHERE c.post_parent IN ($id_list)
               AND c.post_type = 'product_variation'
               AND c.post_status IN ('publish', 'private') /* Lupa cotizaciones: solo publish|private; draft excluido por diseño (producto en elaboración no se cotiza). */
               AND parent.post_type = 'product'
             LIMIT " . (int) max($cap * 2, $cap)
        );
        if (is_array($children)) {
            foreach ($children as $cid) {
                $out[] = (int) $cid;
            }
        }
        $out = array_values(array_unique(array_map('intval', $out)));
        if (count($out) > $cap * 2) {
            $out = array_slice($out, 0, $cap * 2);
        }
        return $out;
    }

    private function hydrate(array $ids, $query, $limit, $mode, $scope) {
        global $wpdb;
        $ids = array_values(array_filter($ids, static function ($id) { return (int) $id > 0; }));
        if ($ids === array()) {
            return array();
        }
        $id_list = implode(',', $ids);
        $posts = $wpdb->get_results("SELECT ID, post_title, post_parent, post_type FROM {$wpdb->posts} WHERE ID IN ($id_list)", ARRAY_A);
        $meta_keys = array('_sku','_barcode','_global_unique_id','_riverso_supplier_code','_supplier_sku','_price','_regular_price','_riverso_unit_cost','_wc_cog_cost','_alg_wc_cog_cost');
        $key_list = "'" . implode("','", $meta_keys) . "'";
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_list) AND meta_key IN ($key_list)",
            ARRAY_A
        );
        $titles = array();
        $parents = array();
        foreach (is_array($posts) ? $posts : array() as $post) {
            $pid = (int) $post['ID'];
            $titles[$pid] = (string) $post['post_title'];
            $parents[$pid] = (int) (isset($post['post_parent']) ? $post['post_parent'] : 0);
        }
        $need_parent = array();
        foreach ($parents as $pid => $parent_id) {
            if ($parent_id > 0 && trim($titles[$pid]) === '') {
                $need_parent[] = $parent_id;
            }
        }
        if ($need_parent) {
            $need_parent = array_values(array_unique($need_parent));
            $plist = implode(',', array_map('intval', $need_parent));
            $prows = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($plist)", ARRAY_A);
            $ptitles = array();
            foreach (is_array($prows) ? $prows : array() as $prow) {
                $ptitles[(int) $prow['ID']] = (string) $prow['post_title'];
            }
            foreach ($parents as $pid => $parent_id) {
                if ($parent_id > 0 && trim($titles[$pid]) === '' && isset($ptitles[$parent_id])) {
                    $titles[$pid] = $ptitles[$parent_id];
                }
            }
        }
        $meta = array();
        foreach (is_array($meta_rows) ? $meta_rows : array() as $row) {
            $meta[(int) $row['post_id']][(string) $row['meta_key']] = (string) $row['meta_value'];
        }
        $q = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
        $ranked = array();
        foreach ($ids as $id) {
            $values = isset($meta[$id]) ? $meta[$id] : array();
            $sku = isset($values['_sku']) ? $values['_sku'] : '';
            if ($sku === '') { continue; }
            $product = array(
                'product_id' => $id,
                'sku' => $sku,
                'barcode' => $this->first($values, array('_barcode', '_global_unique_id')),
                'supplier_code' => $this->first($values, array('_riverso_supplier_code', '_supplier_sku')),
                'description' => isset($titles[$id]) ? $titles[$id] : '',
                'unit_price' => $this->first_number($values, array('_price', '_regular_price')),
                'unit_cost' => $this->first_optional_number($values, array('_riverso_unit_cost', '_wc_cog_cost', '_alg_wc_cog_cost')),
            );
            $score = $this->score($product, $q, $mode, $scope);
            if ($score === null) { continue; }
            $product['_score'] = $score;
            $ranked[] = $product;
        }
        usort($ranked, static function ($a, $b) { return $a['_score'] <=> $b['_score']; });
        $ranked = array_slice($ranked, 0, $limit);
        foreach ($ranked as &$row) { unset($row['_score']); }
        unset($row);
        return $ranked;
    }

    private function score(array $product, $q, $mode, $scope) {
        $best = null;
        $fields = array();
        if ($mode === 'quick' || $scope === 'codigos' || $scope === 'todo') {
            $fields = array_merge($fields, array('sku', 'supplier_code', 'barcode'));
        }
        if ($mode === 'advanced' && ($scope === 'descripcion' || $scope === 'todo')) {
            $fields[] = 'description';
        }
        if ($mode === 'quick' || $scope === 'codigos') {
            $fields = array('sku', 'supplier_code', 'barcode');
        }
        foreach ($fields as $field) {
            $raw = isset($product[$field]) ? $product[$field] : '';
            $value = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $raw), 'UTF-8') : strtolower(trim((string) $raw));
            if ($value === '') { continue; }
            if ($value === $q) { $score = 0; }
            elseif (strpos($value, $q) === 0) { $score = 1; }
            elseif (strpos($value, $q) !== false) { $score = 2; }
            else { continue; }
            if ($field === 'description') { $score += 10; }
            if ($best === null || $score < $best) { $best = $score; }
        }
        return $best;
    }

    private function first(array $values, array $keys) {
        foreach ($keys as $key) {
            if (!empty($values[$key])) { return (string) $values[$key]; }
        }
        return '';
    }

    private function first_number(array $values, array $keys) {
        foreach ($keys as $key) {
            if (isset($values[$key]) && $values[$key] !== '' && is_numeric($values[$key])) {
                return round((float) $values[$key], 2);
            }
        }
        return 0.0;
    }

    private function first_optional_number(array $values, array $keys) {
        foreach ($keys as $key) {
            if (isset($values[$key]) && $values[$key] !== '' && is_numeric($values[$key])) {
                return round((float) $values[$key], 2);
            }
        }
        return null;
    }
}
