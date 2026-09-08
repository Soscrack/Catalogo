<?php
/**
 * Price Lookup Service — explorador de precios y análisis de facturas de compra.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Price_Lookup_Service {

    private static $instance = null;

    /** @var string */
    private $prefix;

    /** Tolerancia al comparar valor actual vs legacy / historial. */
    const ORIGIN_EPS = 0.02;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'riverso_';
        $this->ensure_cost_lookup();
    }

    private function ensure_cost_lookup() {
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function cost_lookup() {
        $this->ensure_cost_lookup();
        return class_exists('Riverso_Cost_Lookup_Service')
            ? Riverso_Cost_Lookup_Service::get_instance()
            : null;
    }

    private function pricing() {
        return class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()
            : null;
    }

    private function origin_pack($key, $extra = []) {
        $key = sanitize_key((string) $key);
        $fecha = isset($extra['fecha']) ? (string) $extra['fecha'] : '';
        $folio = isset($extra['folio']) ? (string) $extra['folio'] : '';
        $detalle = isset($extra['detalle']) ? (string) $extra['detalle'] : '';

        if ($key === '') {
            return [
                'key' => '',
                'label' => '—',
                'fecha' => '',
                'folio' => '',
                'detalle' => '',
            ];
        }

        $base = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::source_type_label($key)
            : $key;

        if (($key === 'folio' || $key === 'costo') && $folio !== '') {
            $label = 'Revisión de folio #' . $folio;
        } elseif ($fecha !== '') {
            $label = $base . ' · ' . $this->format_origin_date($fecha);
        } else {
            $label = $base;
        }

        if ($detalle !== '') {
            $label = $detalle;
        }

        return [
            'key' => $key,
            'label' => $label,
            'fecha' => $fecha,
            'folio' => $folio,
            'detalle' => $detalle,
        ];
    }

    private function format_origin_date($mysql_datetime) {
        $mysql_datetime = trim((string) $mysql_datetime);
        if ($mysql_datetime === '') {
            return '';
        }
        $ts = strtotime($mysql_datetime);
        if (!$ts) {
            return $mysql_datetime;
        }
        return date('d/m/Y', $ts);
    }

    private function values_close($a, $b) {
        if ($a === null || $b === null) {
            return false;
        }
        return abs((float) $a - (float) $b) <= self::ORIGIN_EPS;
    }

    /**
     * ¿c_ref (bruto explorador) coincide con legacy costo (neto o bruto histórico)?
     */
    private function matches_legacy_cost($c_ref, $legacy_cost, $iva_tipo = 'afecto') {
        if ($c_ref === null || $legacy_cost === null) {
            return false;
        }
        if ($this->values_close($c_ref, $legacy_cost)) {
            return true;
        }
        if (!class_exists('Riverso_Pricing_Module')) {
            return false;
        }
        $c_neto = Riverso_Pricing_Module::net_from_gross($c_ref, $iva_tipo);
        if ($this->values_close($c_neto, $legacy_cost)) {
            return true;
        }
        $leg_bruto = Riverso_Pricing_Module::gross_from_net($legacy_cost, $iva_tipo);
        return $this->values_close($c_ref, $leg_bruto);
    }

    private function matches_legacy_price($p_asignado, $legacy_price) {
        if ($p_asignado === null || $legacy_price === null) {
            return false;
        }
        return $this->values_close($p_asignado, $legacy_price);
    }

    /**
     * Búsqueda enriquecida: hits de costos + precio local, bruto/neto y orígenes.
     */
    public function search($term, $limit = 15) {
        $svc = $this->cost_lookup();
        $hits = $svc ? $svc->search($term, $limit) : [];
        if (!$hits) {
            return [];
        }
        return $this->enrich_search_hits($hits);
    }

    /**
     * @param array $hits
     * @return array
     */
    private function enrich_search_hits(array $hits) {
        global $wpdb;

        $ids = [];
        foreach ($hits as $hit) {
            $id = !empty($hit['producto_base_id']) ? (int) $hit['producto_base_id'] : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return $hits;
        }

        $id_list = implode(',', array_map('intval', $ids));

        $products = $wpdb->get_results(
            "SELECT id, canonical_sku, facto_iva_tipo
             FROM {$this->prefix}producto_base
             WHERE id IN ({$id_list})",
            ARRAY_A
        ) ?: [];
        $by_product = [];
        foreach ($products as $p) {
            $by_product[(int) $p['id']] = $p;
        }

        $prices = $wpdb->get_results(
            "SELECT *
             FROM {$this->prefix}precios
             WHERE producto_base_id IN ({$id_list})
               AND canal = 'local'
               AND woocommerce_variation_id = 0",
            ARRAY_A
        ) ?: [];
        $by_price = [];
        foreach ($prices as $pr) {
            $by_price[(int) $pr['producto_base_id']] = $pr;
        }

        $skus = [];
        foreach ($by_product as $p) {
            $sku = trim((string) ($p['canonical_sku'] ?? ''));
            if ($sku !== '') {
                $skus[$sku] = $sku;
            }
        }
        $legacy_by_sku = $this->load_legacy_by_skus(array_values($skus));
        $hist_by_pb = $this->load_latest_history_by_products(array_values($ids), 'local');

        foreach ($hits as &$hit) {
            $pb_id = !empty($hit['producto_base_id']) ? (int) $hit['producto_base_id'] : 0;
            if ($pb_id <= 0) {
                $hit['local'] = null;
                continue;
            }
            $product = $by_product[$pb_id] ?? null;
            $sku = $product ? (string) $product['canonical_sku'] : (string) ($hit['canonical_sku'] ?? '');
            $iva = $product['facto_iva_tipo'] ?? 'afecto';
            $price_row = $by_price[$pb_id] ?? null;
            $legacy = $legacy_by_sku[$sku] ?? null;
            $hist = $hist_by_pb[$pb_id] ?? ['price' => null, 'cost' => null];

            $pack = $this->decorate_price_row(
                $price_row,
                'local',
                $iva,
                $sku,
                $legacy,
                $hist
            );
            $hit['facto_iva_tipo'] = class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::normalize_iva_tipo($iva)
                : 'afecto';
            $hit['local'] = $pack;
            $hit['p_asignado'] = $pack['p_asignado'];
            $hit['p_neto'] = $pack['p_neto'];
            $hit['c_ref'] = $pack['c_ref'];
            $hit['c_ref_bruto'] = $pack['c_ref_bruto'];
            $hit['c_ref_neto'] = $pack['c_ref_neto'];
            $hit['p_ref'] = $pack['p_ref'];
            $hit['p_ref_bruto'] = $pack['p_ref_bruto'];
            $hit['p_ref_neto'] = $pack['p_ref_neto'];
            $hit['margen_factor'] = $pack['margen_factor'];
            $hit['origen_precio'] = $pack['origen_precio'];
            $hit['origen_costo'] = $pack['origen_costo'];
        }
        unset($hit);

        return $hits;
    }

    /**
     * @param string[] $skus
     * @return array<string,array>
     */
    private function load_legacy_by_skus(array $skus) {
        global $wpdb;
        if (!$skus) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.sku, l.costo_neto, l.precio_neto, l.precio_total, l.fuente, l.importado_at
             FROM {$this->prefix}legacy_precio_ref l
             INNER JOIN (
                SELECT sku, MAX(importado_at) AS max_at
                FROM {$this->prefix}legacy_precio_ref
                WHERE sku IN ({$placeholders})
                GROUP BY sku
             ) t ON t.sku = l.sku AND t.max_at = l.importado_at",
            ...$skus
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['sku']] = $row;
        }
        return $out;
    }

    /**
     * Último evento de precio y de costo real por producto (un canal).
     *
     * Precio = último historial con p_asignado_nuevo.
     * Costo = último historial donde c_ref cambió respecto al anterior
     * (ignora saves de solo precio que copian c_ref).
     *
     * @param int[]  $ids
     * @param string $canal
     * @return array<int,array{price:?array,cost:?array}>
     */
    private function load_latest_history_by_products(array $ids, $canal = 'local') {
        global $wpdb;
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = ['price' => null, 'cost' => null];
        }
        if (!$ids) {
            return $out;
        }

        $id_list = implode(',', array_map('intval', $ids));
        $canal = $canal === 'online' ? 'online' : 'local';

        // Último cambio de p_asignado (precio).
        $price_rows = $wpdb->get_results(
            "SELECT h.*
             FROM {$this->prefix}precio_historial h
             INNER JOIN (
                SELECT producto_base_id, MAX(id) AS max_id
                FROM {$this->prefix}precio_historial
                WHERE producto_base_id IN ({$id_list})
                  AND canal = '{$canal}'
                  AND p_asignado_nuevo IS NOT NULL
                GROUP BY producto_base_id
             ) t ON t.max_id = h.id",
            ARRAY_A
        ) ?: [];
        foreach ($price_rows as $row) {
            $out[(int) $row['producto_base_id']]['price'] = $row;
        }

        // Historial con c_ref (ASC) para detectar el último cambio real de costo.
        $cost_hist = $wpdb->get_results(
            "SELECT id, producto_base_id, canal, c_ref, p_asignado_nuevo, source_type,
                    source_document_id, created_at, notas
             FROM {$this->prefix}precio_historial
             WHERE producto_base_id IN ({$id_list})
               AND canal = '{$canal}'
               AND c_ref IS NOT NULL
             ORDER BY producto_base_id ASC, id ASC",
            ARRAY_A
        ) ?: [];

        $prev_c_by_pb = [];
        $cost_change_by_pb = [];
        foreach ($cost_hist as $row) {
            $pb = (int) $row['producto_base_id'];
            $c = (float) $row['c_ref'];
            $st = sanitize_key((string) ($row['source_type'] ?? ''));
            $has_prev = array_key_exists($pb, $prev_c_by_pb);
            $prev = $has_prev ? $prev_c_by_pb[$pb] : null;

            // Cambio real de valor (no basta la primera fila: puede ser save de solo precio).
            $changed = $has_prev && !$this->values_close($c, $prev);
            // Fuentes que actualizan costo aunque repitan el valor.
            $force_cost_src = in_array($st, ['folio', 'recalc', 'import'], true);

            if ($changed || $force_cost_src) {
                $cost_change_by_pb[$pb] = $row;
            }
            // manual/copy_local/system con mismo c_ref: no pisa origen de costo.

            if (!$has_prev || $changed) {
                $prev_c_by_pb[$pb] = $c;
            }
        }
        foreach ($cost_change_by_pb as $pb => $row) {
            $out[$pb]['cost'] = $row;
        }

        // Folios de facturas referenciadas.
        $doc_ids = [];
        foreach ($out as $pack) {
            foreach (['price', 'cost'] as $kind) {
                $evt = $pack[$kind] ?? null;
                if ($evt && !empty($evt['source_document_id'])) {
                    $doc_ids[(int) $evt['source_document_id']] = (int) $evt['source_document_id'];
                }
            }
        }
        $folios = $this->load_factura_folios(array_values($doc_ids));
        foreach ($out as $pb => &$pack) {
            foreach (['price', 'cost'] as $kind) {
                if (empty($pack[$kind])) {
                    continue;
                }
                $did = !empty($pack[$kind]['source_document_id'])
                    ? (int) $pack[$kind]['source_document_id']
                    : 0;
                $pack[$kind]['folio'] = ($did && isset($folios[$did])) ? $folios[$did] : '';
            }
        }
        unset($pack);

        return $out;
    }

    /**
     * @param int[] $factura_ids
     * @return array<int,string>
     */
    private function load_factura_folios(array $factura_ids) {
        global $wpdb;
        if (!$factura_ids) {
            return [];
        }
        $id_list = implode(',', array_map('intval', $factura_ids));
        $rows = $wpdb->get_results(
            "SELECT id, folio FROM {$this->prefix}facturas WHERE id IN ({$id_list})",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) ($row['folio'] ?? '');
        }
        return $out;
    }

    /**
     * Payload del explorador de precios.
     *
     * @param int $producto_base_id
     * @return array|WP_Error
     */
    public function build_explorer_payload($producto_base_id) {
        global $wpdb;
        $producto_base_id = intval($producto_base_id);
        if ($producto_base_id <= 0) {
            return new WP_Error('invalid', 'producto_base_id requerido');
        }

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, marca, facto_iva_tipo,
                    woocommerce_product_id, woocommerce_variation_id
             FROM {$this->prefix}producto_base
             WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$product) {
            return new WP_Error('not_found', 'Producto no encontrado');
        }

        $pricing = $this->pricing();
        $local = $pricing ? $pricing->get_local_price($producto_base_id) : null;
        $online = $pricing ? $pricing->get_online_price_row($producto_base_id, 0) : null;

        $sku = (string) ($product['canonical_sku'] ?? '');
        $legacy_map = $this->load_legacy_by_skus($sku !== '' ? [$sku] : []);
        $legacy = $legacy_map[$sku] ?? null;
        $hist_local = $this->load_latest_history_by_products([$producto_base_id], 'local');
        $hist_online = $this->load_latest_history_by_products([$producto_base_id], 'online');

        $iva = $product['facto_iva_tipo'] ?? 'afecto';
        $local_pack = $this->decorate_price_row(
            $local,
            'local',
            $iva,
            $sku,
            $legacy,
            $hist_local[$producto_base_id] ?? null
        );
        $online_pack = $this->decorate_price_row(
            $online,
            'online',
            $iva,
            $sku,
            $legacy,
            $hist_online[$producto_base_id] ?? null
        );

        $family = $this->get_family_block($producto_base_id, $local_pack['p_asignado'] ?? null, $iva);
        $competencia = $this->get_competencia_block(
            $producto_base_id,
            $local_pack['p_asignado'] ?? null,
            $family,
            $iva
        );
        $history = $this->get_product_history($producto_base_id, 50);
        $chart = $this->get_chart_series($producto_base_id);

        return [
            'product' => [
                'producto_base_id' => (int) $product['id'],
                'canonical_sku' => $product['canonical_sku'],
                'nombre' => $product['nombre_canonico'],
                'marca' => $product['marca'] ?? '',
                'facto_iva_tipo' => class_exists('Riverso_Pricing_Module')
                    ? Riverso_Pricing_Module::normalize_iva_tipo($product['facto_iva_tipo'] ?? 'afecto')
                    : 'afecto',
                'woocommerce_variation_id' => (int) ($product['woocommerce_variation_id'] ?? 0),
            ],
            'local' => $local_pack,
            'online' => $online_pack,
            'family' => $family,
            'competencia' => $competencia,
            'history' => $history,
            'chart' => $chart,
        ];
    }

    /**
     * @param array|null $row
     * @param string     $canal
     * @param string     $iva_tipo
     * @param string     $sku
     * @param array|null $legacy
     * @param array|null $hist  {price, cost}
     * @return array
     */
    private function decorate_price_row($row, $canal, $iva_tipo = 'afecto', $sku = '', $legacy = null, $hist = null) {
        $iva_tipo = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::normalize_iva_tipo($iva_tipo)
            : (($iva_tipo === 'exento') ? 'exento' : 'afecto');

        $empty_origin = $this->origin_pack('');

        if (!$row) {
            return [
                'id' => null,
                'canal' => $canal,
                'c_ref' => null,
                'c_ref_bruto' => null,
                'c_ref_neto' => null,
                'p_ref' => null,
                'p_ref_bruto' => null,
                'p_ref_neto' => null,
                'p_asignado' => null,
                'p_neto' => null,
                'iva_tipo' => $iva_tipo,
                'factor_minimo' => Riverso_Pricing_Module::FACTOR_MINIMO_DEFAULT,
                'factor_objetivo' => Riverso_Pricing_Module::FACTOR_OBJETIVO_DEFAULT,
                'estado_aprobacion' => null,
                'alerta_margen' => 0,
                'en_uso' => $canal === 'local' ? 1 : 0,
                'margen_factor' => null,
                'margen_unitario' => null,
                'margen_pct' => null,
                'origen_precio' => $empty_origin,
                'origen_costo' => $empty_origin,
            ];
        }

        // Convención explorador: c_ref, p_ref y p_asignado son BRUTO comercial.
        // Neto = bruto / 1.19 (4 decimales). Margen = P bruto / C bruto.
        $c_ref_bruto = $row['c_ref'] !== null ? (float) $row['c_ref'] : null;
        $p_ref_bruto = $row['p_ref'] !== null ? (float) $row['p_ref'] : null;
        $p_bruto = $row['p_asignado'] !== null ? (float) $row['p_asignado'] : null;

        // Si no hay c_ref en precios, mostrar costo legacy sin persistir.
        // Excel FACTO: columna "Costo neto" suele ser bruto pese al nombre.
        if ($c_ref_bruto === null && is_array($legacy)
            && !empty($legacy['costo_neto']) && (float) $legacy['costo_neto'] > 0
        ) {
            $c_ref_bruto = (float) $legacy['costo_neto'];
        }

        $c_ref_neto = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::net_from_gross($c_ref_bruto, $iva_tipo)
            : $c_ref_bruto;
        $p_ref_neto = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::net_from_gross($p_ref_bruto, $iva_tipo)
            : $p_ref_bruto;
        $p_neto = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::net_from_gross($p_bruto, $iva_tipo)
            : $p_bruto;

        $margen_factor = ($p_bruto !== null && $c_ref_bruto && $c_ref_bruto > 0)
            ? round($p_bruto / $c_ref_bruto, 4)
            : null;
        $margen = ($p_bruto !== null && $c_ref_bruto !== null)
            ? round($p_bruto - $c_ref_bruto, 4)
            : null;
        $pct = ($margen_factor !== null)
            ? round(($margen_factor - 1) * 100, 1)
            : null;

        $origins = $this->resolve_origins($c_ref_bruto, $p_bruto, $legacy, $hist, $iva_tipo);

        $factor_minimo = (float) $row['factor_minimo'];
        $alerta = ($margen_factor !== null && $margen_factor < $factor_minimo) ? 1 : (int) $row['alerta_margen'];

        return [
            'id' => (int) $row['id'],
            'canal' => $row['canal'],
            'c_ref' => $c_ref_bruto,
            'c_ref_bruto' => $c_ref_bruto,
            'c_ref_neto' => $c_ref_neto,
            'p_ref' => $p_ref_bruto,
            'p_ref_bruto' => $p_ref_bruto,
            'p_ref_neto' => $p_ref_neto,
            'p_asignado' => $p_bruto,
            'p_neto' => $p_neto,
            'iva_tipo' => $iva_tipo,
            'factor_minimo' => $factor_minimo,
            'factor_objetivo' => (float) $row['factor_objetivo'],
            'estado_aprobacion' => $row['estado_aprobacion'],
            'alerta_margen' => $alerta,
            'en_uso' => isset($row['en_uso']) ? (int) $row['en_uso'] : 1,
            'margen_factor' => $margen_factor,
            'margen_unitario' => $margen,
            'margen_pct' => $pct,
            'woocommerce_variation_id' => (int) ($row['woocommerce_variation_id'] ?? 0),
            'origen_precio' => $origins['origen_precio'],
            'origen_costo' => $origins['origen_costo'],
        ];
    }

    /**
     * Extrae fecha/folio de un evento de historial para origin_pack.
     *
     * @param array|null $evt
     * @return array{fecha:string,folio:string}
     */
    private function origin_meta_from_event($evt) {
        if (!is_array($evt)) {
            return ['fecha' => '', 'folio' => ''];
        }
        return [
            'fecha' => (string) ($evt['created_at'] ?? ''),
            'folio' => (string) ($evt['folio'] ?? ''),
        ];
    }

    /**
     * Infiere origen de precio y costo sin columnas nuevas.
     *
     * Precio = último historial con p_asignado_nuevo.
     * Costo = último historial con cambio real de c_ref (o folio/recalc/import).
     * Si c_ref vigente sigue igual a legacy y no hubo cambio real de costo → Legacy.
     *
     * @param float|null $c_ref
     * @param float|null $p_asignado
     * @param array|null $legacy
     * @param array|null $hist
     * @param string     $iva_tipo
     * @return array{origen_precio:array,origen_costo:array}
     */
    private function resolve_origins($c_ref, $p_asignado, $legacy, $hist, $iva_tipo = 'afecto') {
        $hist = is_array($hist) ? $hist : ['price' => null, 'cost' => null];
        $price_evt = $hist['price'] ?? null;
        $cost_evt = $hist['cost'] ?? null;

        $legacy_cost = null;
        $legacy_price = null;
        if (is_array($legacy)) {
            if (!empty($legacy['costo_neto']) && (float) $legacy['costo_neto'] > 0) {
                $legacy_cost = (float) $legacy['costo_neto'];
            }
            if (!empty($legacy['precio_total'])) {
                $legacy_price = (float) $legacy['precio_total'];
            } elseif (!empty($legacy['precio_neto'])) {
                $legacy_price = (float) $legacy['precio_neto'];
            }
        }

        // --- Precio ---
        $origen_precio = $this->origin_pack('');
        if ($p_asignado !== null) {
            if ($price_evt) {
                $st = sanitize_key((string) ($price_evt['source_type'] ?? ''));
                $meta = $this->origin_meta_from_event($price_evt);
                if ($st === 'folio') {
                    $origen_precio = $this->origin_pack('folio', $meta);
                } elseif ($st === 'recalc') {
                    $origen_precio = $this->origin_pack('system', $meta);
                } elseif ($st !== '') {
                    $origen_precio = $this->origin_pack($st, $meta);
                }
            }
            if ($origen_precio['key'] === '' && $this->matches_legacy_price($p_asignado, $legacy_price)) {
                $origen_precio = $this->origin_pack('legacy');
            }
        }

        // --- Costo ---
        $origen_costo = $this->origin_pack('');
        if ($c_ref !== null) {
            $matches_legacy = $this->matches_legacy_cost($c_ref, $legacy_cost, $iva_tipo);
            $had_real_cost_change = is_array($cost_evt);

            // Sin cambio real de costo y valor = legacy → Legacy (no pisa un save de solo precio).
            if ($matches_legacy && !$had_real_cost_change) {
                $origen_costo = $this->origin_pack('legacy');
            } elseif ($cost_evt) {
                $st = sanitize_key((string) ($cost_evt['source_type'] ?? ''));
                $meta = $this->origin_meta_from_event($cost_evt);
                if (in_array($st, ['folio', 'recalc'], true)) {
                    $origen_costo = $this->origin_pack('costo', $meta);
                } elseif ($st === 'import') {
                    $origen_costo = $this->origin_pack('import', $meta);
                } elseif ($st === 'manual') {
                    // Cambio real de c_ref vía save manual (no solo precio).
                    $origen_costo = $this->origin_pack('manual', $meta);
                } elseif ($st !== '') {
                    $origen_costo = $this->origin_pack($st, $meta);
                }
            }

            if ($origen_costo['key'] === '' && $matches_legacy) {
                $origen_costo = $this->origin_pack('legacy');
            }
        }

        return [
            'origen_precio' => $origen_precio,
            'origen_costo' => $origen_costo,
        ];
    }

    /**
     * Familia + preview de miembros (precios brutos; costos con neto/bruto).
     */
    private function get_family_block($producto_base_id, $p_asignado, $iva_tipo = 'afecto') {
        global $wpdb;

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT g.id, g.nombre
             FROM {$this->prefix}equivalence_members em
             INNER JOIN {$this->prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.producto_base_id = %d
               AND em.activo = 1
               AND g.activo = 1
             ORDER BY g.id ASC
             LIMIT 1",
            $producto_base_id
        ), ARRAY_A);

        if (!$group) {
            return null;
        }

        $grupo_id = (int) $group['id'];
        $members = [];
        $preview = null;
        $iva_tipo = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::normalize_iva_tipo($iva_tipo)
            : 'afecto';

        if (!class_exists('Riverso_Unit_Product_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (class_exists('Riverso_Unit_Product_Service')) {
            $preview = Riverso_Unit_Product_Service::get_instance()->preview_member_prices($grupo_id, $p_asignado);
            if (is_wp_error($preview)) {
                $preview = ['error' => $preview->get_error_message()];
            } else {
                $members = $preview['members'] ?? [];
            }
        }

        if (!$members) {
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima
                 FROM {$this->prefix}equivalence_members em
                 INNER JOIN {$this->prefix}producto_base pb ON pb.id = em.producto_base_id
                 WHERE em.grupo_id = %d AND em.activo = 1
                 ORDER BY pb.nombre_canonico ASC",
                $grupo_id
            ), ARRAY_A) ?: [];
        }

        foreach ($members as &$m) {
            // Coste de lote/factura es neto contable; bruto = neto × 1.19.
            // Precios de regla son brutos (P TPV).
            $coste_neto = isset($m['coste_unitario']) && $m['coste_unitario'] !== null
                ? (float) $m['coste_unitario']
                : null;
            $m['coste_unitario_neto'] = $coste_neto;
            $m['coste_unitario_bruto'] = class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::gross_from_net($coste_neto, $iva_tipo)
                : $coste_neto;
            $precio_bruto = isset($m['precio_unitario_regla']) && $m['precio_unitario_regla'] !== null
                ? (float) $m['precio_unitario_regla']
                : null;
            $m['margen_factor'] = ($precio_bruto !== null && $m['coste_unitario_bruto'] && $m['coste_unitario_bruto'] > 0)
                ? round($precio_bruto / (float) $m['coste_unitario_bruto'], 4)
                : null;
            $m['margen'] = ($precio_bruto !== null && $m['coste_unitario_bruto'] !== null)
                ? round($precio_bruto - (float) $m['coste_unitario_bruto'], 4)
                : null;
            $m['precio_es_bruto'] = true;
        }
        unset($m);

        $rule_id = null;
        if (class_exists('Riverso_Price_Rules_Module')) {
            $rule_id = Riverso_Price_Rules_Module::get_instance()->get_assigned_rule_id('familia', $grupo_id);
        }

        return [
            'grupo_id' => $grupo_id,
            'nombre' => $group['nombre'],
            'regla_id' => $rule_id,
            'p_asignado_familia' => $p_asignado,
            'precios_son_brutos' => true,
            'iva_tipo' => $iva_tipo,
            'members' => is_array($members) ? $members : [],
            'preview' => is_array($preview) ? $preview : null,
        ];
    }

    /**
     * Bloque competencia del explorador: mapeados, sugeridos y comparación vs familia.
     *
     * @param int        $producto_base_id
     * @param float|null $nuestro_precio  p_asignado bruto del SKU abierto
     * @param array|null $family
     * @param string     $iva_tipo
     * @return array{mapeados:array,sugeridos:array,comparacion_familia:?array,admin_url:string}
     */
    private function get_competencia_block($producto_base_id, $nuestro_precio = null, $family = null, $iva_tipo = 'afecto') {
        global $wpdb;

        if (!class_exists('Riverso_Competencia_Match_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/competencia/class-competencia-match-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $producto_base_id = (int) $producto_base_id;
        $nuestro_precio = ($nuestro_precio !== null && $nuestro_precio !== '')
            ? (float) $nuestro_precio
            : null;
        $iva_tipo = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::normalize_iva_tipo($iva_tipo)
            : 'afecto';

        $mapeados = $wpdb->get_results($wpdb->prepare(
            "SELECT cm.id AS match_id, cm.tipo_match, cm.metodo, cm.score, cm.estado AS match_estado,
                    cp.id AS producto_competencia_id, cp.nombre, cp.codigo_externo, cp.url_producto, cp.slug,
                    f.slug AS fuente_slug, f.nombre AS fuente_nombre,
                    pr.precio, pr.precio_lista, pr.precio_bruto_unitario, pr.precio_bruto_total,
                    pr.cantidad_min, pr.snapshot_fecha, pr.actualizado_at
             FROM {$this->prefix}competencia_match cm
             INNER JOIN {$this->prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
             LEFT JOIN {$this->prefix}competencia_fuentes f ON f.id = cp.fuente_id
             LEFT JOIN {$this->prefix}competencia_precios pr ON pr.producto_id = cp.id
             WHERE cm.producto_base_id = %d
               AND cm.estado = 'confirmado'
             ORDER BY f.nombre ASC, cp.nombre ASC",
            $producto_base_id
        ), ARRAY_A) ?: [];

        foreach ($mapeados as &$row) {
            $row = $this->enrich_competencia_row($row, $nuestro_precio, $iva_tipo, false);
        }
        unset($row);

        $sugeridos = [];
        if (class_exists('Riverso_Competencia_Match_Service')) {
            $sug = Riverso_Competencia_Match_Service::list_sugerencias_for_sku($producto_base_id);
            $sugeridos = is_array($sug['rows'] ?? null) ? $sug['rows'] : [];
        }
        foreach ($sugeridos as &$row) {
            $row = $this->enrich_competencia_row($row, $nuestro_precio, $iva_tipo, true);
        }
        unset($row);

        $comparacion_familia = $this->build_competencia_familia_matrix(
            $family,
            array_merge($mapeados, $sugeridos),
            $iva_tipo
        );

        return [
            'mapeados' => $mapeados,
            'sugeridos' => $sugeridos,
            'comparacion_familia' => $comparacion_familia,
            'admin_url' => admin_url('admin.php?page=riverso-pos-competencia'),
        ];
    }

    /**
     * Normaliza una fila rival con deltas vs nuestro precio.
     *
     * @param array      $row
     * @param float|null $nuestro_precio bruto
     * @param string     $iva_tipo
     * @param bool       $es_sugerido
     * @return array
     */
    private function enrich_competencia_row(array $row, $nuestro_precio, $iva_tipo, $es_sugerido) {
        if (class_exists('Riverso_Competencia_Match_Service')) {
            $row['url_producto'] = Riverso_Competencia_Match_Service::product_page_url($row);
            $row['tipo_match_label'] = Riverso_Competencia_Match_Service::tipo_match_label(
                (string) ($row['tipo_match'] ?? '')
            );
        } else {
            $row['tipo_match_label'] = (string) ($row['tipo_match'] ?? '');
        }

        $rival = null;
        if (isset($row['precio_bruto_unitario']) && $row['precio_bruto_unitario'] !== null && $row['precio_bruto_unitario'] !== '') {
            $rival = (float) $row['precio_bruto_unitario'];
        } elseif (isset($row['precio']) && $row['precio'] !== null && $row['precio'] !== '') {
            $rival = (float) $row['precio'];
        }

        $row['rival_unitario'] = $rival;
        $row['rival_unitario_neto'] = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::net_from_gross($rival, $iva_tipo)
            : $rival;
        $row['nuestro_precio'] = $nuestro_precio;
        $row['nuestro_precio_neto'] = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::net_from_gross($nuestro_precio, $iva_tipo)
            : $nuestro_precio;

        $delta = null;
        $delta_pct = null;
        if ($nuestro_precio !== null && $rival !== null) {
            $delta = round($nuestro_precio - $rival, 4);
            if ($rival > 0) {
                $delta_pct = round(($nuestro_precio / $rival) - 1, 4);
            }
        }
        $row['delta'] = $delta;
        $row['delta_pct'] = $delta_pct;
        $row['es_sugerido'] = $es_sugerido ? 1 : 0;
        $row['score'] = isset($row['score']) && $row['score'] !== null && $row['score'] !== ''
            ? (float) $row['score']
            : null;
        $row['metodo'] = (string) ($row['metodo'] ?? '');
        $row['cantidad_min'] = isset($row['cantidad_min']) && $row['cantidad_min'] !== null && $row['cantidad_min'] !== ''
            ? (float) $row['cantidad_min']
            : null;

        if (!$es_sugerido && class_exists('Riverso_Competencia_Match_Service')) {
            $series = Riverso_Competencia_Match_Service::get_price_series((int) ($row['producto_competencia_id'] ?? 0));
            $row['series'] = $series ? ($series['series'] ?? []) : [];
        } else {
            $row['series'] = $row['series'] ?? [];
        }

        return $row;
    }

    /**
     * Matriz miembro de familia × rival (P unitario bruto vs rival unitario).
     *
     * @param array|null $family
     * @param array      $rivales
     * @param string     $iva_tipo
     * @return array|null
     */
    private function build_competencia_familia_matrix($family, array $rivales, $iva_tipo = 'afecto') {
        if (!is_array($family) || empty($family['members']) || !$rivales) {
            return null;
        }

        $rivales_slim = [];
        foreach ($rivales as $r) {
            $rival_u = isset($r['rival_unitario']) ? $r['rival_unitario'] : null;
            if ($rival_u === null && isset($r['precio_bruto_unitario']) && $r['precio_bruto_unitario'] !== null && $r['precio_bruto_unitario'] !== '') {
                $rival_u = (float) $r['precio_bruto_unitario'];
            } elseif ($rival_u === null && isset($r['precio']) && $r['precio'] !== null && $r['precio'] !== '') {
                $rival_u = (float) $r['precio'];
            }
            $rivales_slim[] = [
                'producto_competencia_id' => (int) ($r['producto_competencia_id'] ?? $r['id'] ?? 0),
                'nombre' => (string) ($r['nombre'] ?? ''),
                'fuente_nombre' => (string) ($r['fuente_nombre'] ?? $r['fuente_slug'] ?? ''),
                'fuente_slug' => (string) ($r['fuente_slug'] ?? ''),
                'codigo_externo' => (string) ($r['codigo_externo'] ?? ''),
                'es_sugerido' => !empty($r['es_sugerido']) ? 1 : 0,
                'rival_unitario' => $rival_u !== null ? (float) $rival_u : null,
                'cantidad_min' => isset($r['cantidad_min']) && $r['cantidad_min'] !== null && $r['cantidad_min'] !== ''
                    ? (float) $r['cantidad_min']
                    : null,
            ];
        }

        $rows = [];
        foreach ($family['members'] as $m) {
            $nuestro_u = isset($m['precio_unitario_regla']) && $m['precio_unitario_regla'] !== null
                ? (float) $m['precio_unitario_regla']
                : null;
            $comparaciones = [];
            foreach ($rivales_slim as $riv) {
                $rival_u = $riv['rival_unitario'];
                $delta = null;
                $delta_pct = null;
                if ($nuestro_u !== null && $rival_u !== null) {
                    $delta = round($nuestro_u - $rival_u, 4);
                    if ($rival_u > 0) {
                        $delta_pct = round(($nuestro_u / $rival_u) - 1, 4);
                    }
                }
                $comparaciones[] = array_merge($riv, [
                    'delta' => $delta,
                    'delta_pct' => $delta_pct,
                ]);
            }
            $rows[] = [
                'producto_base_id' => (int) ($m['producto_base_id'] ?? 0),
                'canonical_sku' => (string) ($m['canonical_sku'] ?? ''),
                'nombre_canonico' => (string) ($m['nombre_canonico'] ?? ''),
                'es_unidad_minima' => (int) ($m['es_unidad_minima'] ?? 0),
                'cantidad_unidades' => isset($m['cantidad_unidades']) ? (float) $m['cantidad_unidades'] : null,
                'precio_unitario' => $nuestro_u,
                'precio_unitario_neto' => class_exists('Riverso_Pricing_Module')
                    ? Riverso_Pricing_Module::net_from_gross($nuestro_u, $iva_tipo)
                    : $nuestro_u,
                'rivales' => $comparaciones,
            ];
        }

        return $rows;
    }

    public function get_product_history($producto_base_id, $limit = 50) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.*, u.display_name AS usuario_nombre
             FROM {$this->prefix}precio_historial h
             LEFT JOIN {$wpdb->users} u ON u.ID = h.usuario_id
             WHERE h.producto_base_id = %d
             ORDER BY h.created_at DESC
             LIMIT %d",
            intval($producto_base_id),
            max(1, min(200, (int) $limit))
        ), ARRAY_A) ?: [];

        foreach ($rows as &$h) {
            $key = sanitize_key((string) ($h['source_type'] ?? ''));
            $h['source_type_key'] = $key;
            $h['source_type_label'] = class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::source_type_label($key === 'folio' ? 'folio' : $key)
                : $key;
            if ($key === 'folio') {
                $h['source_type_label'] = 'Revisión de folio';
            }
        }
        unset($h);

        return $rows;
    }

    public function get_chart_series($producto_base_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT created_at, canal,
                    COALESCE(p_asignado_nuevo, precio_local, precio_online) AS precio,
                    c_ref
             FROM {$this->prefix}precio_historial
             WHERE producto_base_id = %d
             ORDER BY created_at ASC
             LIMIT 500",
            intval($producto_base_id)
        ), ARRAY_A) ?: [];

        $local = ['labels' => [], 'precios' => [], 'costos' => []];
        $online = ['labels' => [], 'precios' => [], 'costos' => []];
        foreach ($rows as $row) {
            $bucket = ($row['canal'] ?? 'local') === 'online' ? 'online' : 'local';
            ${$bucket}['labels'][] = $row['created_at'];
            ${$bucket}['precios'][] = $row['precio'] !== null ? (float) $row['precio'] : null;
            ${$bucket}['costos'][] = $row['c_ref'] !== null ? (float) $row['c_ref'] : null;
        }

        return ['local' => $local, 'online' => $online];
    }

    /**
     * Análisis de factura de compra vs precio de venta / margen.
     *
     * @param int $factura_id
     * @return array|WP_Error
     */
    public function analyze_invoice_for_pricing($factura_id) {
        $svc = $this->cost_lookup();
        if (!$svc) {
            return new WP_Error('no_cost', 'Servicio de costos no disponible');
        }

        $base = $svc->analyze_invoice($factura_id);
        if (is_wp_error($base)) {
            return $base;
        }

        $pricing = $this->pricing();
        $proveedor_id = (int) ($base['invoice']['proveedor_id'] ?? 0);
        $alerts = 0;
        $margen_sum = 0;
        $margen_n = 0;

        foreach ($base['rows'] as &$row) {
            $pb_id = $this->resolve_base_id($proveedor_id, $row['codigo_proveedor'] ?? '');
            $local = ($pb_id && $pricing) ? $pricing->get_local_price($pb_id) : null;
            $online = ($pb_id && $pricing) ? $pricing->get_online_price_row($pb_id, 0) : null;
            $costo = $row['costo_actual'] !== null ? (float) $row['costo_actual'] : null;
            $p_local = ($local && $local['p_asignado'] !== null) ? (float) $local['p_asignado'] : null;
            $p_online = ($online && $online['p_asignado'] !== null) ? (float) $online['p_asignado'] : null;
            $c_ref = ($local && $local['c_ref'] !== null) ? (float) $local['c_ref'] : $costo;
            $factor = $local ? (float) $local['factor_minimo'] : Riverso_Pricing_Module::FACTOR_MINIMO_DEFAULT;
            $qty = (float) ($row['cantidad'] ?? 0);
            $iva_tipo = ($pb_id && $pricing) ? $pricing->get_iva_tipo_for_product($pb_id) : 'afecto';
            $p_neto = ($p_local !== null) ? Riverso_Pricing_Module::net_from_gross($p_local, $iva_tipo) : null;
            $margen_u = ($p_neto !== null && $costo !== null) ? round($p_neto - $costo, 3) : null;
            $margen_t = ($margen_u !== null) ? round($margen_u * $qty, 3) : null;
            $alerta = Riverso_Pricing_Module::is_margin_alert($p_local, $c_ref, $factor, $iva_tipo);
            if ($alerta) {
                $alerts++;
            }
            if ($margen_u !== null) {
                $margen_sum += $margen_u;
                $margen_n++;
            }

            $row['producto_base_id'] = $pb_id;
            $row['precio_local'] = $p_local;
            $row['precio_local_neto'] = $p_neto;
            $row['precio_online'] = $p_online;
            $row['online_en_uso'] = $online ? (int) ($online['en_uso'] ?? 1) : 0;
            $row['c_ref'] = $c_ref;
            $row['margen_unitario'] = $margen_u;
            $row['margen_total'] = $margen_t;
            $row['alerta_margen'] = $alerta;
        }
        unset($row);

        $base['summary'] = [
            'items' => count($base['rows']),
            'alerts' => $alerts,
            'margen_promedio' => $margen_n ? round($margen_sum / $margen_n, 3) : null,
        ];

        return $base;
    }

    private function resolve_base_id($proveedor_id, $codigo_proveedor) {
        global $wpdb;
        $codigo_proveedor = trim((string) $codigo_proveedor);
        if ($proveedor_id <= 0 || $codigo_proveedor === '') {
            return null;
        }
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT producto_base_id
             FROM {$this->prefix}producto_proveedor
             WHERE proveedor_id = %d AND codigo_proveedor = %s AND activo = 1
             LIMIT 1",
            $proveedor_id,
            $codigo_proveedor
        ));
        return $id ? (int) $id : null;
    }
}
