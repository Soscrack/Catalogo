<?php
/**
 * Familias comerciales pack / kit (solo catálogo).
 *
 * Pack: tramos por cantidad con descuento al precio o al margen y T50 al total.
 * Kit: conjunto de productos distintos con un descuento y T50 al total del set.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Price_Rule_Engine')) {
    $engine = RIVERSO_POS_PLUGIN_DIR . 'pricing/price_lists/class-price-rule-engine.php';
    if (file_exists($engine)) {
        require_once $engine;
    }
}

class Riverso_Family_Commercial_Service {

    private static $instance = null;

    const TIPOS = ['', 'unitario', 'pack', 'kit'];
    const MODOS_STOCK = ['ilimitado', 'limitado'];
    const DESCUENTO_MODOS = ['precio', 'margen'];
    const FACTOR_WARN = 1.1;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function ensure_schema() {
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_family_commercial_schema')) {
            Riverso_POS_Activator::ensure_family_commercial_schema();
        }
    }

    public static function normalize_tipo_comercial($tipo, $default = '') {
        $tipo = strtolower(trim((string) $tipo));
        return in_array($tipo, self::TIPOS, true) ? $tipo : $default;
    }

    public static function normalize_pack_modo($modo) {
        $modo = strtolower(trim((string) $modo));
        return in_array($modo, self::MODOS_STOCK, true) ? $modo : 'ilimitado';
    }

    public static function normalize_descuento_modo($modo) {
        $modo = strtolower(trim((string) $modo));
        return in_array($modo, self::DESCUENTO_MODOS, true) ? $modo : 'precio';
    }

    /**
     * Snapshot comercial para el editor.
     *
     * @param int $grupo_id
     * @return array|WP_Error
     */
    public function get_snapshot($grupo_id) {
        $this->ensure_schema();
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        if (!$grupo_id) {
            return new WP_Error('invalid', 'grupo_id requerido');
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $tipo = self::normalize_tipo_comercial($family['tipo_comercial'] ?? '');
        if ($tipo === '' && !empty($family['es_producto_unitario'])) {
            $tipo = 'unitario';
        }

        $pack_modo = self::normalize_pack_modo($family['pack_modo'] ?? 'ilimitado');
        $base_id = intval($family['pack_base_producto_id'] ?? 0);
        if (!$base_id && $tipo === 'pack') {
            $base_id = intval($family['unit_producto_base_id'] ?? 0);
        }

        $out = [
            'grupo_id' => $grupo_id,
            'tipo_comercial' => $tipo,
            'pack_modo' => $pack_modo,
            'pack_base_producto_id' => $base_id ?: null,
            'kit_sku_producto_id' => intval($family['kit_sku_producto_id'] ?? 0) ?: null,
            'kit_descuento_modo' => self::normalize_descuento_modo($family['kit_descuento_modo'] ?? 'precio'),
            'kit_descuento_pct' => (float) ($family['kit_descuento_pct'] ?? 0),
            'pack_tiers' => [],
            'kit_components' => [],
            'base' => null,
            'kit_pricing' => null,
            'manufacturing_url' => admin_url('admin.php?page=riverso-pos-manufacturing'),
            'packaging_url' => admin_url('admin.php?page=riverso-pos-packaging'),
        ];

        if ($tipo === 'pack') {
            $out['pack_tiers'] = $this->list_pack_tiers($grupo_id);
            if ($base_id) {
                $out['base'] = $this->product_price_context($base_id);
                $out['pack_tiers'] = array_map(function ($tier) use ($out) {
                    $explained = $this->explain_pack_tier($tier, $out['base']);
                    return array_merge($tier, ['preview' => $explained]);
                }, $out['pack_tiers']);
            }
        }

        if ($tipo === 'kit') {
            $out['kit_components'] = $this->list_kit_components($grupo_id);
            $out['kit_pricing'] = $this->explain_kit(
                $out['kit_components'],
                $out['kit_descuento_modo'],
                $out['kit_descuento_pct']
            );
        }

        return $out;
    }

    /**
     * Cambia tipo comercial con limpieza de datos del tipo anterior.
     *
     * @param int    $grupo_id
     * @param string $nuevo_tipo
     * @param bool   $confirm
     * @return array|WP_Error
     */
    public function set_tipo_comercial($grupo_id, $nuevo_tipo, $confirm = false) {
        $this->ensure_schema();
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $nuevo_tipo = self::normalize_tipo_comercial($nuevo_tipo);

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $actual = self::normalize_tipo_comercial($family['tipo_comercial'] ?? '');
        if ($actual === '' && !empty($family['es_producto_unitario'])) {
            $actual = 'unitario';
        }

        if ($actual === $nuevo_tipo) {
            return $this->get_snapshot($grupo_id);
        }

        $impact = $this->describe_tipo_change_impact($grupo_id, $actual, $family);
        if ($actual !== '' && !$confirm) {
            return new WP_Error('needs_confirm', 'Confirma el cambio de tipo comercial', [
                'from' => $actual,
                'to' => $nuevo_tipo,
                'impact' => $impact,
            ]);
        }

        if ($actual === 'unitario' || (!empty($family['es_producto_unitario']) && $nuevo_tipo !== 'unitario')) {
            $wpdb->update(
                "{$prefix}equivalence_groups",
                [
                    'es_producto_unitario' => 0,
                    'unit_producto_base_id' => null,
                ],
                ['id' => $grupo_id],
                ['%d', '%s'],
                ['%d']
            );
            if (class_exists('Riverso_Unit_Product_Service')) {
                Riverso_Unit_Product_Service::get_instance()->cancel_missing_rule_tasks(
                    $grupo_id,
                    'Tipo comercial cambiado a ' . ($nuevo_tipo ?: 'ninguno')
                );
            }
        }

        if ($actual === 'pack' && $nuevo_tipo !== 'pack') {
            $wpdb->update(
                "{$prefix}family_pack_tiers",
                ['activo' => 0],
                ['grupo_id' => $grupo_id],
                ['%d'],
                ['%d']
            );
        }

        if ($actual === 'kit' && $nuevo_tipo !== 'kit') {
            $wpdb->update(
                "{$prefix}family_kit_components",
                ['activo' => 0],
                ['grupo_id' => $grupo_id],
                ['%d'],
                ['%d']
            );
        }

        $update = [
            'tipo_comercial' => $nuevo_tipo,
        ];
        $formats = ['%s'];
        if ($nuevo_tipo === 'pack') {
            $update['pack_modo'] = self::normalize_pack_modo($family['pack_modo'] ?? 'ilimitado') ?: 'ilimitado';
            $formats[] = '%s';
            if (empty($family['pack_base_producto_id']) && !empty($family['unit_producto_base_id'])) {
                $update['pack_base_producto_id'] = (int) $family['unit_producto_base_id'];
                $formats[] = '%d';
            }
        }
        if ($nuevo_tipo === 'unitario') {
            $update['es_producto_unitario'] = 1;
            $formats[] = '%d';
        }

        $wpdb->update(
            "{$prefix}equivalence_groups",
            $update,
            ['id' => $grupo_id],
            $formats,
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_tipo_comercial_changed', 'equivalence_groups', $grupo_id, [
                'from' => $actual,
                'to' => $nuevo_tipo,
                'impact' => $impact,
            ]);
        }

        return $this->get_snapshot($grupo_id);
    }

    private function describe_tipo_change_impact($grupo_id, $actual, array $family) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $lines = [];
        if ($actual === 'unitario' || !empty($family['es_producto_unitario'])) {
            $lines[] = 'Se desactivará el producto unitario y su vínculo de SKU U.';
        }
        if ($actual === 'pack') {
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}family_pack_tiers WHERE grupo_id = %d AND activo = 1",
                $grupo_id
            ));
            $lines[] = $n
                ? ('Se desactivarán ' . $n . ' tramo(s) de pack.')
                : 'Se dejarán de usar los tramos de pack.';
        }
        if ($actual === 'kit') {
            $n = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}family_kit_components WHERE grupo_id = %d AND activo = 1",
                $grupo_id
            ));
            $lines[] = $n
                ? ('Se desactivarán ' . $n . ' componente(s) del kit.')
                : 'Se dejará de usar la configuración del kit.';
        }
        if (!$lines) {
            $lines[] = 'No hay configuración comercial previa que limpiar.';
        }
        return $lines;
    }

    /**
     * Crea un producto_base local (SKU + nombre) opcionalmente con precio.
     *
     * @param array $opts canonical_sku, nombre, p_asignado, origen_datos
     * @return array|WP_Error {producto_base_id, canonical_sku, nombre_canonico}
     */
    public function create_local_product(array $opts) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $sku = sanitize_text_field($opts['canonical_sku'] ?? '');
        $nombre = sanitize_text_field($opts['nombre'] ?? '');
        if ($nombre === '') {
            return new WP_Error('no_nombre', 'Indicá el nombre del producto nuevo');
        }
        if ($sku === '') {
            $sku = $this->generate_next_sku();
            if (is_wp_error($sku)) {
                return $sku;
            }
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
        if ($exists) {
            return new WP_Error('sku_taken', 'El SKU ' . $sku . ' ya existe');
        }

        $origen = sanitize_text_field($opts['origen_datos'] ?? 'family_commercial_create') ?: 'family_commercial_create';
        $ok = $wpdb->insert("{$prefix}producto_base", [
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre,
            'unidad_base' => 'unidad',
            'estado' => 'activo',
            'origen_datos' => $origen,
            'requires_human_review' => 0,
            'review_status' => 'aprobado',
        ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s']);
        if (!$ok) {
            return new WP_Error('db_error', 'No se pudo crear el producto');
        }
        $id = (int) $wpdb->insert_id;

        $p = isset($opts['p_asignado']) && $opts['p_asignado'] !== '' && $opts['p_asignado'] !== null
            ? (float) $opts['p_asignado']
            : null;
        if ($p !== null && $p > 0 && class_exists('Riverso_Pricing_Module')) {
            $pricing = Riverso_Pricing_Module::get_instance();
            $pricing->recalc_price($id, Riverso_Pricing_Module::CANAL_LOCAL, 0);
            $row = $pricing->get_local_price($id);
            if ($row && !empty($row['id'])) {
                $pricing->set_assigned_price((int) $row['id'], $p, [
                    'source_type' => 'family_commercial',
                    'notas' => 'Precio al crear producto desde familia pack/kit',
                ]);
            }
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_commercial_product_created', 'producto_base', $id, [
                'canonical_sku' => $sku,
                'nombre' => $nombre,
            ]);
        }

        return [
            'producto_base_id' => $id,
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre,
            'p_asignado' => $p,
        ];
    }

    /**
     * Guarda configuración pack (modo, base, tramos).
     *
     * @param int   $grupo_id
     * @param array $data
     * @return array|WP_Error
     */
    public function save_pack_config($grupo_id, array $data) {
        $this->ensure_schema();
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $tipo = self::normalize_tipo_comercial($family['tipo_comercial'] ?? '');
        if ($tipo !== 'pack') {
            return new WP_Error('wrong_tipo', 'La familia no es tipo pack');
        }

        $pack_modo = self::normalize_pack_modo($data['pack_modo'] ?? 'ilimitado');
        $base_id = absint($data['pack_base_producto_id'] ?? 0);
        if (!$base_id) {
            $create_sku = trim((string) ($data['create_base_sku'] ?? ''));
            $create_nombre = trim((string) ($data['create_base_nombre'] ?? ''));
            if ($create_sku === '' && $create_nombre === '') {
                return new WP_Error('no_base', 'Indicá el SKU base del pack o creá uno nuevo');
            }
            $created = $this->create_local_product([
                'canonical_sku' => $create_sku,
                'nombre' => $create_nombre,
                'p_asignado' => $data['create_base_precio'] ?? null,
                'origen_datos' => 'family_pack_base',
            ]);
            if (is_wp_error($created)) {
                return $created;
            }
            $base_id = (int) $created['producto_base_id'];
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, deleted_at FROM {$prefix}producto_base WHERE id = %d",
            $base_id
        ), ARRAY_A);
        if (!$pb || !empty($pb['deleted_at'])) {
            return new WP_Error('base_missing', 'Producto base no encontrado');
        }

        $tiers_in = isset($data['tiers']) && is_array($data['tiers']) ? $data['tiers'] : [];
        $normalized = [];
        $seen_qty = [];
        foreach ($tiers_in as $t) {
            $qty = (int) ($t['cantidad'] ?? 0);
            if ($qty < 2) {
                return new WP_Error('bad_qty', 'Cada pack debe tener cantidad ≥ 2');
            }
            if (isset($seen_qty[$qty])) {
                return new WP_Error('dup_qty', 'Cantidad de pack duplicada: ' . $qty);
            }
            $seen_qty[$qty] = true;
            $modo = self::normalize_descuento_modo($t['descuento_modo'] ?? 'precio');
            $pct = (float) ($t['descuento_pct'] ?? 0);
            if ($pct < 0 || $pct >= 1) {
                // Accept 0–100 as percent or 0–1 as fraction.
                if ($pct > 1 && $pct <= 100) {
                    $pct = $pct / 100.0;
                } else {
                    return new WP_Error('bad_discount', 'Descuento inválido (usar 0–100 %)');
                }
            }
            $normalized[] = [
                'cantidad' => $qty,
                'descuento_modo' => $modo,
                'descuento_pct' => $pct,
                'producto_pack_id' => absint($t['producto_pack_id'] ?? 0) ?: null,
                'orden' => (int) ($t['orden'] ?? $qty),
            ];
        }

        usort($normalized, static function ($a, $b) {
            return $a['cantidad'] <=> $b['cantidad'];
        });

        $base_ctx = $this->product_price_context($base_id);
        foreach ($normalized as $t) {
            if ($t['descuento_modo'] === 'margen' && ($base_ctx['c_ref'] === null || $base_ctx['c_ref'] <= 0)) {
                return new WP_Error(
                    'no_cost',
                    'Modo descuento al margen requiere costo (c_ref) del SKU base. Pack ×' . $t['cantidad']
                );
            }
        }

        $wpdb->query('START TRANSACTION');

        $wpdb->update(
            "{$prefix}equivalence_groups",
            [
                'pack_modo' => $pack_modo,
                'pack_base_producto_id' => $base_id,
                'tipo_comercial' => 'pack',
            ],
            ['id' => $grupo_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_Family_Module')) {
            Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $base_id, 50);
        }

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}family_pack_tiers WHERE grupo_id = %d",
            $grupo_id
        ), ARRAY_A) ?: [];
        $by_qty = [];
        foreach ($existing as $row) {
            $by_qty[(int) $row['cantidad']] = $row;
        }

        $keep_ids = [];
        foreach ($normalized as $t) {
            $pack_product_id = $t['producto_pack_id'];
            if ($pack_modo === 'limitado') {
                $ensured = $this->ensure_limited_pack_sku($grupo_id, $base_id, $t['cantidad'], $pack_product_id, $pb);
                if (is_wp_error($ensured)) {
                    $wpdb->query('ROLLBACK');
                    return $ensured;
                }
                $pack_product_id = (int) $ensured['producto_pack_id'];
                $price_set = $this->sync_limited_pack_price($base_ctx, $t, $pack_product_id);
                if (is_wp_error($price_set)) {
                    $wpdb->query('ROLLBACK');
                    return $price_set;
                }
            } else {
                $pack_product_id = null;
            }

            if (isset($by_qty[$t['cantidad']])) {
                $tid = (int) $by_qty[$t['cantidad']]['id'];
                $wpdb->update(
                    "{$prefix}family_pack_tiers",
                    [
                        'descuento_modo' => $t['descuento_modo'],
                        'descuento_pct' => $t['descuento_pct'],
                        'producto_pack_id' => $pack_product_id,
                        'orden' => $t['orden'],
                        'activo' => 1,
                    ],
                    ['id' => $tid],
                    ['%s', '%f', '%d', '%d', '%d'],
                    ['%d']
                );
                $keep_ids[] = $tid;
            } else {
                $wpdb->insert(
                    "{$prefix}family_pack_tiers",
                    [
                        'grupo_id' => $grupo_id,
                        'cantidad' => $t['cantidad'],
                        'descuento_modo' => $t['descuento_modo'],
                        'descuento_pct' => $t['descuento_pct'],
                        'producto_pack_id' => $pack_product_id,
                        'orden' => $t['orden'],
                        'activo' => 1,
                    ],
                    ['%d', '%d', '%s', '%f', '%d', '%d', '%d']
                );
                $keep_ids[] = (int) $wpdb->insert_id;
            }
        }

        if (!empty($existing)) {
            foreach ($existing as $row) {
                if (!in_array((int) $row['id'], $keep_ids, true)) {
                    $wpdb->update(
                        "{$prefix}family_pack_tiers",
                        ['activo' => 0],
                        ['id' => (int) $row['id']],
                        ['%d'],
                        ['%d']
                    );
                }
            }
        }

        $wpdb->query('COMMIT');

        return $this->get_snapshot($grupo_id);
    }

    /**
     * @param int        $grupo_id
     * @param int        $base_id
     * @param int        $qty
     * @param int|null   $existing_pack_id
     * @param array      $base_pb
     * @return array|WP_Error {producto_pack_id}
     */
    private function ensure_limited_pack_sku($grupo_id, $base_id, $qty, $existing_pack_id, array $base_pb) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $qty = (int) $qty;
        $existing_pack_id = intval($existing_pack_id);

        if ($existing_pack_id && $existing_pack_id !== $base_id) {
            $ok = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
                $existing_pack_id
            ));
            if ($ok) {
                if (class_exists('Riverso_Family_Module')) {
                    Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $existing_pack_id, 100 + $qty);
                    Riverso_Family_Module::get_instance()->upsert_envase_for_member([
                        'producto_base_id' => $existing_pack_id,
                        'cantidad_unidades' => $qty,
                        'origen_datos' => 'family_pack_limited',
                    ]);
                }
                return ['producto_pack_id' => $existing_pack_id];
            }
        }

        // Reusar miembro con mismo envase qty si existe.
        if (class_exists('Riverso_Unit_Product_Service')) {
            $packs = Riverso_Unit_Product_Service::get_instance()->get_pack_members_by_qty($grupo_id, $base_id);
            $qty_key = number_format(round((float) $qty, 4), 4, '.', '');
            if (isset($packs[$qty_key]) && !empty($packs[$qty_key]['producto_base_id'])) {
                $pid = (int) $packs[$qty_key]['producto_base_id'];
                return ['producto_pack_id' => $pid];
            }
            // También probar clave entera por si el mapa se indexó distinto.
            if (isset($packs[$qty]) && !empty($packs[$qty]['producto_base_id'])) {
                return ['producto_pack_id' => (int) $packs[$qty]['producto_base_id']];
            }
        }

        $new_sku = $this->generate_next_sku();
        if (is_wp_error($new_sku)) {
            return $new_sku;
        }

        $nombre = sanitize_text_field(
            ($base_pb['nombre_canonico'] ?? 'Producto') . ' (pack ' . $qty . ' u)'
        );
        $wpdb->insert("{$prefix}producto_base", [
            'canonical_sku' => $new_sku,
            'nombre_canonico' => $nombre,
            'unidad_base' => 'unidad',
            'estado' => 'activo',
            'origen_datos' => 'family_pack_limited',
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
        ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s']);

        $pack_id = (int) $wpdb->insert_id;
        if (!$pack_id) {
            return new WP_Error('db_error', 'No se pudo crear el SKU del pack ×' . $qty);
        }

        if (class_exists('Riverso_Family_Module')) {
            $fam = Riverso_Family_Module::get_instance();
            $fam->ensure_member($grupo_id, $pack_id, 100 + $qty);
            $fam->upsert_envase_for_member([
                'producto_base_id' => $pack_id,
                'cantidad_unidades' => $qty,
                'origen_datos' => 'family_pack_limited',
            ]);
        }

        return ['producto_pack_id' => $pack_id];
    }

    /**
     * @param array $base_ctx
     * @param array $tier
     * @param int   $pack_product_id
     * @return true|WP_Error
     */
    private function sync_limited_pack_price(array $base_ctx, array $tier, $pack_product_id) {
        if (!class_exists('Riverso_Pricing_Module')) {
            return new WP_Error('no_pricing', 'Módulo de precios no disponible');
        }
        $explained = $this->explain_pack_tier($tier, $base_ctx);
        if (!empty($explained['error'])) {
            return new WP_Error('pack_price', $explained['error']);
        }
        $total = (float) $explained['total'];
        $pricing = Riverso_Pricing_Module::get_instance();
        $row = $pricing->get_local_price($pack_product_id);
        if (!$row) {
            $re = $pricing->recalc_price($pack_product_id, Riverso_Pricing_Module::CANAL_LOCAL, 0);
            if (is_wp_error($re)) {
                return $re;
            }
            $row = $pricing->get_local_price($pack_product_id);
        }
        if (!$row || empty($row['id'])) {
            return new WP_Error('no_price_row', 'No hay fila de precio LOCAL para el pack');
        }
        $set = $pricing->set_assigned_price((int) $row['id'], $total, [
            'source_type' => 'family_pack',
            'notas' => 'Precio pack ×' . (int) $tier['cantidad'] . ' (T50 al total)',
        ]);
        return is_wp_error($set) ? $set : true;
    }

    private function generate_next_sku() {
        if (class_exists('Riverso_Unit_Product_Service')) {
            $svc = Riverso_Unit_Product_Service::get_instance();
            $ref = new ReflectionClass($svc);
            if ($ref->hasMethod('generate_next_sku')) {
                $m = $ref->getMethod('generate_next_sku');
                $m->setAccessible(true);
                return $m->invoke($svc);
            }
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $max_sku = $wpdb->get_var(
            "SELECT MAX(CAST(canonical_sku AS UNSIGNED))
             FROM {$prefix}producto_base
             WHERE canonical_sku REGEXP '^[0-9]+$'
               AND CHAR_LENGTH(canonical_sku) <= 6"
        );
        $next = $max_sku ? ((int) $max_sku + 1) : 1;
        if ($next > 999999) {
            return new WP_Error('sku_exhausted', 'No hay SKUs locales disponibles');
        }
        return (string) $next;
    }

    public function list_pack_tiers($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, pb.canonical_sku AS pack_sku, pb.nombre_canonico AS pack_nombre
             FROM {$prefix}family_pack_tiers t
             LEFT JOIN {$prefix}producto_base pb ON pb.id = t.producto_pack_id
             WHERE t.grupo_id = %d AND t.activo = 1
             ORDER BY t.cantidad ASC",
            intval($grupo_id)
        ), ARRAY_A) ?: [];
        return array_map(static function ($r) {
            return [
                'id' => (int) $r['id'],
                'cantidad' => (int) $r['cantidad'],
                'descuento_modo' => self::normalize_descuento_modo($r['descuento_modo'] ?? 'precio'),
                'descuento_pct' => (float) $r['descuento_pct'],
                'producto_pack_id' => intval($r['producto_pack_id'] ?? 0) ?: null,
                'pack_sku' => $r['pack_sku'] ?? null,
                'pack_nombre' => $r['pack_nombre'] ?? null,
                'orden' => (int) ($r['orden'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Contexto de precio/costo de un producto.
     *
     * @param int $producto_base_id
     * @return array
     */
    public function product_price_context($producto_base_id) {
        $producto_base_id = intval($producto_base_id);
        $ctx = [
            'producto_base_id' => $producto_base_id,
            'canonical_sku' => null,
            'nombre_canonico' => null,
            'p_asignado' => null,
            'c_ref' => null,
            'iva_tipo' => 'afecto',
            'factor' => null,
            'precio_id' => null,
        ];
        if (!$producto_base_id) {
            return $ctx;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, facto_iva_tipo FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if ($pb) {
            $ctx['canonical_sku'] = $pb['canonical_sku'];
            $ctx['nombre_canonico'] = $pb['nombre_canonico'];
            $ctx['iva_tipo'] = class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::normalize_iva_tipo($pb['facto_iva_tipo'] ?? 'afecto')
                : 'afecto';
        }
        if (!class_exists('Riverso_Pricing_Module')) {
            return $ctx;
        }
        $row = Riverso_Pricing_Module::get_instance()->get_local_price($producto_base_id);
        if ($row) {
            $ctx['precio_id'] = (int) $row['id'];
            $ctx['p_asignado'] = $row['p_asignado'] !== null ? (float) $row['p_asignado'] : null;
            $ctx['c_ref'] = $row['c_ref'] !== null ? (float) $row['c_ref'] : null;
            if ($ctx['p_asignado'] !== null && $ctx['c_ref'] !== null && $ctx['c_ref'] > 0) {
                $neto = Riverso_Pricing_Module::net_from_gross($ctx['p_asignado'], $ctx['iva_tipo']);
                $ctx['factor'] = $neto !== null ? round($neto / $ctx['c_ref'], 4) : null;
            }
        }
        return $ctx;
    }

    /**
     * Explica un tramo de pack.
     *
     * @param array      $tier
     * @param array|null $base_ctx
     * @return array
     */
    public function explain_pack_tier(array $tier, $base_ctx = null) {
        $qty = (int) ($tier['cantidad'] ?? 0);
        $modo = self::normalize_descuento_modo($tier['descuento_modo'] ?? 'precio');
        $d = (float) ($tier['descuento_pct'] ?? 0);
        if ($d > 1 && $d <= 100) {
            $d = $d / 100.0;
        }

        $out = [
            'cantidad' => $qty,
            'descuento_modo' => $modo,
            'descuento_pct' => $d,
            'descuento_pct_ui' => round($d * 100, 2),
            'total' => null,
            'unitario_efectivo' => null,
            'descuento_al_precio' => null,
            'descuento_al_margen' => null,
            'factor' => null,
            'factor_warn' => false,
            'error' => null,
        ];

        if ($qty < 1) {
            $out['error'] = 'Cantidad inválida';
            return $out;
        }
        if (!$base_ctx || $base_ctx['p_asignado'] === null) {
            $out['error'] = 'Falta precio unitario (P) del SKU base';
            return $out;
        }

        $P = (float) $base_ctx['p_asignado'];
        $c_ref = $base_ctx['c_ref'];
        $iva = $base_ctx['iva_tipo'] ?? 'afecto';
        $factor0 = $base_ctx['factor'];

        $line_before_t50 = null;
        if ($modo === 'precio') {
            $line_before_t50 = ($P * (1.0 - $d)) * $qty;
        } else {
            if ($c_ref === null || $c_ref <= 0 || $factor0 === null) {
                $out['error'] = 'Sin costo: no se puede aplicar descuento al margen';
                return $out;
            }
            $margen0 = $factor0 - 1.0;
            $margen1 = $margen0 * (1.0 - $d);
            $factor1 = 1.0 + $margen1;
            $neto1 = $c_ref * $factor1;
            $bruto1 = Riverso_Pricing_Module::gross_from_net($neto1, $iva);
            $line_before_t50 = ((float) $bruto1) * $qty;
        }

        $total = Riverso_Price_Rule_Engine::techo_cincuentena($line_before_t50);
        $unit = $qty > 0 ? ($total / $qty) : null;
        $out['total'] = round($total, 2);
        $out['unitario_efectivo'] = $unit !== null ? round($unit, 4) : null;

        if ($P > 0 && $unit !== null) {
            $out['descuento_al_precio'] = round(1.0 - ($unit / $P), 4);
        }

        if ($c_ref !== null && $c_ref > 0 && $unit !== null) {
            $neto_u = Riverso_Pricing_Module::net_from_gross($unit, $iva);
            $factor1 = ($neto_u !== null) ? ($neto_u / $c_ref) : null;
            $out['factor'] = $factor1 !== null ? round($factor1, 4) : null;
            $out['factor_warn'] = ($factor1 !== null && $factor1 < self::FACTOR_WARN);
            if ($factor0 !== null && $factor0 > 1.0 && $factor1 !== null) {
                $margen0 = $factor0 - 1.0;
                $margen1 = $factor1 - 1.0;
                if ($margen0 > 0) {
                    $out['descuento_al_margen'] = round(1.0 - ($margen1 / $margen0), 4);
                }
            }
        }

        return $out;
    }

    /**
     * Combinación óptima de packs + sueltos para una cantidad.
     *
     * @param int   $grupo_id
     * @param int   $qty
     * @return array|WP_Error
     */
    public function optimize_pack_combo($grupo_id, $qty) {
        $snap = $this->get_snapshot($grupo_id);
        if (is_wp_error($snap)) {
            return $snap;
        }
        if (($snap['tipo_comercial'] ?? '') !== 'pack') {
            return new WP_Error('wrong_tipo', 'La familia no es pack');
        }
        $qty = (int) $qty;
        if ($qty < 1) {
            return new WP_Error('bad_qty', 'Cantidad debe ser ≥ 1');
        }
        $base = $snap['base'];
        if (!$base || $base['p_asignado'] === null) {
            return new WP_Error('no_price', 'Falta precio unitario del SKU base');
        }
        $P = (float) $base['p_asignado'];
        $tiers = $snap['pack_tiers'] ?: [];
        $pack_opts = [];
        foreach ($tiers as $t) {
            $prev = $t['preview'] ?? $this->explain_pack_tier($t, $base);
            if (!empty($prev['error']) || $prev['total'] === null) {
                continue;
            }
            $pack_opts[] = [
                'cantidad' => (int) $t['cantidad'],
                'total' => (float) $prev['total'],
                'descuento_modo' => $t['descuento_modo'],
                'descuento_pct' => (float) $t['descuento_pct'],
            ];
        }

        // DP: min cost for exactly n units.
        $INF = 1e18;
        $dp = array_fill(0, $qty + 1, $INF);
        $choice = array_fill(0, $qty + 1, null);
        $dp[0] = 0.0;
        for ($n = 1; $n <= $qty; $n++) {
            // suelto
            if ($dp[$n - 1] + $P < $dp[$n]) {
                $dp[$n] = $dp[$n - 1] + $P;
                $choice[$n] = ['type' => 'suelto', 'qty' => 1, 'cost' => $P];
            }
            foreach ($pack_opts as $opt) {
                $q = $opt['cantidad'];
                if ($q > $n) {
                    continue;
                }
                $cand = $dp[$n - $q] + $opt['total'];
                if ($cand < $dp[$n] - 1e-9) {
                    $dp[$n] = $cand;
                    $choice[$n] = [
                        'type' => 'pack',
                        'qty' => $q,
                        'cost' => $opt['total'],
                    ];
                }
            }
        }

        $breakdown = [];
        $left = $qty;
        while ($left > 0 && $choice[$left]) {
            $c = $choice[$left];
            $key = $c['type'] . ':' . $c['qty'];
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'type' => $c['type'],
                    'pack_qty' => (int) $c['qty'],
                    'count' => 0,
                    'unit_cost' => (float) $c['cost'],
                    'subtotal' => 0.0,
                ];
            }
            $breakdown[$key]['count']++;
            $breakdown[$key]['subtotal'] += (float) $c['cost'];
            $left -= (int) $c['qty'];
        }

        $items = array_values($breakdown);
        usort($items, static function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'pack' ? -1 : 1;
            }
            return $b['pack_qty'] <=> $a['pack_qty'];
        });

        // Alternativas simples: solo sueltos, y greedy de pack más grande.
        $alternatives = [
            [
                'label' => 'Solo sueltos',
                'total' => round($P * $qty, 2),
            ],
        ];
        if ($pack_opts) {
            $greedy_total = 0.0;
            $rem = $qty;
            usort($pack_opts, static function ($a, $b) {
                return $b['cantidad'] <=> $a['cantidad'];
            });
            $g_parts = [];
            foreach ($pack_opts as $opt) {
                $n = intdiv($rem, $opt['cantidad']);
                if ($n > 0) {
                    $g_parts[] = $n . '× pack ' . $opt['cantidad'];
                    $greedy_total += $n * $opt['total'];
                    $rem -= $n * $opt['cantidad'];
                }
            }
            if ($rem > 0) {
                $g_parts[] = $rem . ' suelto(s)';
                $greedy_total += $rem * $P;
            }
            $alternatives[] = [
                'label' => 'Greedy: ' . implode(' + ', $g_parts),
                'total' => round($greedy_total, 2),
            ];
        }

        return [
            'qty' => $qty,
            'total' => round($dp[$qty], 2),
            'items' => $items,
            'alternatives' => $alternatives,
            'p_unitario' => $P,
        ];
    }

    public function list_kit_components($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}family_kit_components c
             INNER JOIN {$prefix}producto_base pb ON pb.id = c.producto_base_id
             WHERE c.grupo_id = %d AND c.activo = 1
             ORDER BY c.orden ASC, c.id ASC",
            intval($grupo_id)
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $ctx = $this->product_price_context((int) $r['producto_base_id']);
            $qty = (float) $r['cantidad'];
            $out[] = [
                'id' => (int) $r['id'],
                'producto_base_id' => (int) $r['producto_base_id'],
                'cantidad' => $qty,
                'orden' => (int) $r['orden'],
                'canonical_sku' => $r['canonical_sku'],
                'nombre_canonico' => $r['nombre_canonico'],
                'p_asignado' => $ctx['p_asignado'],
                'c_ref' => $ctx['c_ref'],
                'line_bruto' => ($ctx['p_asignado'] !== null) ? round($ctx['p_asignado'] * $qty, 4) : null,
                'line_c_ref' => ($ctx['c_ref'] !== null) ? round($ctx['c_ref'] * $qty, 4) : null,
            ];
        }
        return $out;
    }

    /**
     * Guarda kit: modo stock (pack_modo reutilizado), descuento, componentes.
     *
     * @param int   $grupo_id
     * @param array $data
     * @return array|WP_Error
     */
    public function save_kit_config($grupo_id, array $data) {
        $this->ensure_schema();
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }
        if (self::normalize_tipo_comercial($family['tipo_comercial'] ?? '') !== 'kit') {
            return new WP_Error('wrong_tipo', 'La familia no es tipo kit');
        }

        $pack_modo = self::normalize_pack_modo($data['pack_modo'] ?? ($family['pack_modo'] ?? 'ilimitado'));
        $modo = self::normalize_descuento_modo($data['descuento_modo'] ?? 'precio');
        $pct = (float) ($data['descuento_pct'] ?? 0);
        if ($pct > 1 && $pct <= 100) {
            $pct = $pct / 100.0;
        }
        if ($pct < 0 || $pct >= 1) {
            return new WP_Error('bad_discount', 'Descuento inválido (usar 0–100 %)');
        }

        $components_in = isset($data['components']) && is_array($data['components']) ? $data['components'] : [];
        if (count($components_in) < 1) {
            return new WP_Error('no_components', 'Agregá al menos un componente al kit');
        }

        $normalized = [];
        $seen = [];
        foreach ($components_in as $i => $c) {
            $pid = absint($c['producto_base_id'] ?? 0);
            $qty = (float) ($c['cantidad'] ?? 0);
            if (!$pid || $qty <= 0) {
                return new WP_Error('bad_component', 'Componente inválido (SKU y cantidad > 0)');
            }
            if (isset($seen[$pid])) {
                return new WP_Error('dup_component', 'Componente duplicado');
            }
            $seen[$pid] = true;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
                $pid
            ));
            if (!$exists) {
                return new WP_Error('missing_product', 'Producto #' . $pid . ' no encontrado');
            }
            $normalized[] = [
                'producto_base_id' => $pid,
                'cantidad' => $qty,
                'orden' => (int) ($c['orden'] ?? ($i + 1)),
            ];
        }

        $explained = $this->explain_kit($normalized, $modo, $pct);
        if (!empty($explained['error'])) {
            return new WP_Error('kit_price', $explained['error']);
        }

        $wpdb->query('START TRANSACTION');

        $kit_sku_id = intval($family['kit_sku_producto_id'] ?? 0) ?: null;
        if ($pack_modo === 'limitado') {
            $ensured = $this->ensure_limited_kit_sku($grupo_id, $family, $normalized, $kit_sku_id);
            if (is_wp_error($ensured)) {
                $wpdb->query('ROLLBACK');
                return $ensured;
            }
            $kit_sku_id = (int) $ensured['kit_sku_producto_id'];
            if (class_exists('Riverso_Pricing_Module') && $explained['total'] !== null) {
                $pricing = Riverso_Pricing_Module::get_instance();
                $row = $pricing->get_local_price($kit_sku_id);
                if (!$row) {
                    $pricing->recalc_price($kit_sku_id, Riverso_Pricing_Module::CANAL_LOCAL, 0);
                    $row = $pricing->get_local_price($kit_sku_id);
                }
                if ($row && !empty($row['id'])) {
                    $pricing->set_assigned_price((int) $row['id'], (float) $explained['total'], [
                        'source_type' => 'family_kit',
                        'notas' => 'Precio kit (T50 al total del conjunto)',
                    ]);
                }
            }
        }

        $wpdb->update(
            "{$prefix}equivalence_groups",
            [
                'pack_modo' => $pack_modo,
                'kit_descuento_modo' => $modo,
                'kit_descuento_pct' => $pct,
                'kit_sku_producto_id' => $kit_sku_id,
                'tipo_comercial' => 'kit',
            ],
            ['id' => $grupo_id],
            ['%s', '%s', '%f', '%d', '%s'],
            ['%d']
        );

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}family_kit_components WHERE grupo_id = %d",
            $grupo_id
        ), ARRAY_A) ?: [];
        $by_pid = [];
        foreach ($existing as $row) {
            $by_pid[(int) $row['producto_base_id']] = $row;
        }

        $keep = [];
        foreach ($normalized as $c) {
            if (class_exists('Riverso_Family_Module')) {
                Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $c['producto_base_id'], 100);
            }
            if (isset($by_pid[$c['producto_base_id']])) {
                $cid = (int) $by_pid[$c['producto_base_id']]['id'];
                $wpdb->update(
                    "{$prefix}family_kit_components",
                    [
                        'cantidad' => $c['cantidad'],
                        'orden' => $c['orden'],
                        'activo' => 1,
                    ],
                    ['id' => $cid],
                    ['%f', '%d', '%d'],
                    ['%d']
                );
                $keep[] = $cid;
            } else {
                $wpdb->insert(
                    "{$prefix}family_kit_components",
                    [
                        'grupo_id' => $grupo_id,
                        'producto_base_id' => $c['producto_base_id'],
                        'cantidad' => $c['cantidad'],
                        'orden' => $c['orden'],
                        'activo' => 1,
                    ],
                    ['%d', '%d', '%f', '%d', '%d']
                );
                $keep[] = (int) $wpdb->insert_id;
            }
        }
        foreach ($existing as $row) {
            if (!in_array((int) $row['id'], $keep, true)) {
                $wpdb->update(
                    "{$prefix}family_kit_components",
                    ['activo' => 0],
                    ['id' => (int) $row['id']],
                    ['%d'],
                    ['%d']
                );
            }
        }

        $wpdb->query('COMMIT');
        return $this->get_snapshot($grupo_id);
    }

    private function ensure_limited_kit_sku($grupo_id, array $family, array $components, $existing_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $existing_id = intval($existing_id);
        if ($existing_id) {
            $ok = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
                $existing_id
            ));
            if ($ok) {
                return ['kit_sku_producto_id' => $existing_id];
            }
        }

        $new_sku = $this->generate_next_sku();
        if (is_wp_error($new_sku)) {
            return $new_sku;
        }
        $nombre = sanitize_text_field(($family['nombre'] ?? 'Kit') . ' (kit)');
        $wpdb->insert("{$prefix}producto_base", [
            'canonical_sku' => $new_sku,
            'nombre_canonico' => $nombre,
            'unidad_base' => 'unidad',
            'estado' => 'activo',
            'origen_datos' => 'family_kit_limited',
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
        ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s']);
        $kit_id = (int) $wpdb->insert_id;
        if (!$kit_id) {
            return new WP_Error('db_error', 'No se pudo crear el SKU del kit');
        }
        if (class_exists('Riverso_Family_Module')) {
            Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $kit_id, 10);
        }
        return ['kit_sku_producto_id' => $kit_id];
    }

    /**
     * @param array  $components lista con producto_base_id, cantidad (y opcional p/c)
     * @param string $modo
     * @param float  $d
     * @return array
     */
    public function explain_kit(array $components, $modo, $d) {
        $modo = self::normalize_descuento_modo($modo);
        $d = (float) $d;
        if ($d > 1 && $d <= 100) {
            $d = $d / 100.0;
        }

        $out = [
            'descuento_modo' => $modo,
            'descuento_pct' => $d,
            'descuento_pct_ui' => round($d * 100, 2),
            'suma_precios' => null,
            'suma_costos' => null,
            'total' => null,
            'descuento_al_precio' => null,
            'descuento_al_margen' => null,
            'factor' => null,
            'factor_warn' => false,
            'error' => null,
            'components' => [],
        ];

        $suma_p = 0.0;
        $suma_c = 0.0;
        $has_all_p = true;
        $has_all_c = true;
        $iva = 'afecto';

        foreach ($components as $c) {
            $pid = absint($c['producto_base_id'] ?? 0);
            $qty = (float) ($c['cantidad'] ?? 1);
            $ctx = isset($c['p_asignado']) || isset($c['c_ref'])
                ? $c
                : $this->product_price_context($pid);
            $p = isset($ctx['p_asignado']) ? $ctx['p_asignado'] : null;
            $cref = isset($ctx['c_ref']) ? $ctx['c_ref'] : null;
            if ($p === null) {
                $has_all_p = false;
            } else {
                $suma_p += ((float) $p) * $qty;
            }
            if ($cref === null) {
                $has_all_c = false;
            } else {
                $suma_c += ((float) $cref) * $qty;
            }
            if (!empty($ctx['iva_tipo'])) {
                $iva = $ctx['iva_tipo'];
            }
            $out['components'][] = [
                'producto_base_id' => $pid,
                'cantidad' => $qty,
                'p_asignado' => $p,
                'c_ref' => $cref,
                'canonical_sku' => $ctx['canonical_sku'] ?? ($c['canonical_sku'] ?? null),
                'nombre_canonico' => $ctx['nombre_canonico'] ?? ($c['nombre_canonico'] ?? null),
            ];
        }

        if (!$has_all_p) {
            $out['error'] = 'Falta precio asignado en uno o más componentes';
            return $out;
        }

        $out['suma_precios'] = round($suma_p, 4);
        $out['suma_costos'] = $has_all_c ? round($suma_c, 4) : null;

        $line_before = null;
        $factor0 = null;
        if ($modo === 'precio') {
            $line_before = $suma_p * (1.0 - $d);
        } else {
            if (!$has_all_c || $suma_c <= 0) {
                $out['error'] = 'Sin costo completo: no se puede aplicar descuento al margen';
                return $out;
            }
            // factor0 sobre el conjunto: neto(suma_p) / suma_c
            $neto0 = Riverso_Pricing_Module::net_from_gross($suma_p, $iva);
            $factor0 = ($neto0 !== null && $suma_c > 0) ? ($neto0 / $suma_c) : null;
            if ($factor0 === null) {
                $out['error'] = 'No se pudo calcular el factor del kit';
                return $out;
            }
            $margen0 = $factor0 - 1.0;
            $margen1 = $margen0 * (1.0 - $d);
            $factor1 = 1.0 + $margen1;
            $neto1 = $suma_c * $factor1;
            $line_before = (float) Riverso_Pricing_Module::gross_from_net($neto1, $iva);
        }

        $total = Riverso_Price_Rule_Engine::techo_cincuentena($line_before);
        $out['total'] = round($total, 2);

        if ($suma_p > 0) {
            $out['descuento_al_precio'] = round(1.0 - ($total / $suma_p), 4);
        }

        if ($has_all_c && $suma_c > 0) {
            $neto_t = Riverso_Pricing_Module::net_from_gross($total, $iva);
            $factor1 = ($neto_t !== null) ? ($neto_t / $suma_c) : null;
            $out['factor'] = $factor1 !== null ? round($factor1, 4) : null;
            $out['factor_warn'] = ($factor1 !== null && $factor1 < self::FACTOR_WARN);
            if ($factor0 === null) {
                $neto0 = Riverso_Pricing_Module::net_from_gross($suma_p, $iva);
                $factor0 = ($neto0 !== null) ? ($neto0 / $suma_c) : null;
            }
            if ($factor0 !== null && $factor0 > 1.0 && $factor1 !== null) {
                $margen0 = $factor0 - 1.0;
                $margen1 = $factor1 - 1.0;
                if ($margen0 > 0) {
                    $out['descuento_al_margen'] = round(1.0 - ($margen1 / $margen0), 4);
                }
            }
        }

        return $out;
    }
}
