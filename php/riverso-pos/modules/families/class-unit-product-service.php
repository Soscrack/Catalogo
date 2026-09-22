<?php
/**
 * Servicio de producto unitario por familia.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Unit_Product_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param int $grupo_id
     * @return array|null
     */
    public function get_family_row($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d AND activo = 1",
            intval($grupo_id)
        ), ARRAY_A);
    }

    /**
     * Resuelve el producto unitario de una familia.
     *
     * @param int $grupo_id
     * @return int|null
     */
    public function get_unit_base_id($grupo_id) {
        $family = $this->get_family_row($grupo_id);
        if (!$family || empty($family['es_producto_unitario']) || empty($family['unit_producto_base_id'])) {
            return null;
        }
        return intval($family['unit_producto_base_id']);
    }

    /**
     * Resuelve familia + unitario para un producto_base.
     *
     * @param int $producto_base_id
     * @return array|null {grupo_id, unit_producto_base_id, es_producto_unitario}
     */
    public function resolve_family_unit_for_base($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT g.id AS grupo_id, g.es_producto_unitario, g.unit_producto_base_id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND g.activo = 1
             ORDER BY (g.tipo_sustitucion = 'exacta') DESC, g.id ASC
             LIMIT 1",
            $producto_base_id
        ), ARRAY_A);

        if (!$row || empty($row['es_producto_unitario']) || empty($row['unit_producto_base_id'])) {
            return null;
        }

        return [
            'grupo_id' => intval($row['grupo_id']),
            'unit_producto_base_id' => intval($row['unit_producto_base_id']),
            'es_producto_unitario' => (int) $row['es_producto_unitario'],
        ];
    }

    /**
     * Resuelve el producto unitario para cualquier miembro de la familia.
     *
     * @param int $producto_base_id
     * @return int|null
     */
    public function resolve_unit_for_base($producto_base_id) {
        $ctx = $this->resolve_family_unit_for_base($producto_base_id);
        return $ctx ? intval($ctx['unit_producto_base_id']) : null;
    }

    /**
     * Envase canónico del miembro (mayor cantidad_unidades activa).
     *
     * @param int $producto_base_id
     * @return array|null
     */
    public function get_canonical_envase($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}envases
             WHERE producto_base_id = %d AND activo = 1
             ORDER BY (cantidad_unidades > 1) DESC, cantidad_unidades DESC
             LIMIT 1",
            intval($producto_base_id)
        ), ARRAY_A);
    }

    /**
     * MAX(coste_unitario / cantidad_unidades) sobre miembros activos (excluye unitario).
     *
     * @param int $grupo_id
     * @return array {coste, breakdown, warnings}
     */
    public function calculate_coste_unitario($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $unit_id = $this->get_unit_base_id($grupo_id);

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1
               AND pb.deleted_at IS NULL",
            $grupo_id
        ), ARRAY_A) ?: [];

        $max = null;
        $breakdown = [];
        $warnings = [];

        foreach ($members as $m) {
            $base_id = intval($m['producto_base_id']);
            if ($unit_id && $base_id === $unit_id) {
                continue;
            }
            if (!empty($m['es_unidad_minima'])) {
                continue;
            }

            $envase = $this->get_canonical_envase($base_id);
            $cantidad = $envase ? floatval($envase['cantidad_unidades']) : 0.0;
            if ($cantidad <= 0) {
                $warnings[] = 'SKU ' . ($m['canonical_sku'] ?: $base_id) . ' sin cantidad_unidades; omitido del coste unitario.';
                continue;
            }

            $costo_caja = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(l.costo_unitario)
                 FROM {$prefix}lotes l
                 INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
                 WHERE pp.producto_base_id = %d
                   AND l.costo_unitario IS NOT NULL
                   AND l.estado <> 'bloqueado'",
                $base_id
            ));

            if ($costo_caja === null && class_exists('Riverso_Pricing_Module')) {
                $price = Riverso_Pricing_Module::get_instance()->get_local_price($base_id);
                if ($price && $price['c_ref'] !== null) {
                    $costo_caja = (float) $price['c_ref'];
                }
            }

            if ($costo_caja === null) {
                $warnings[] = 'SKU ' . ($m['canonical_sku'] ?: $base_id) . ' sin coste de lote ni c_ref.';
                continue;
            }

            $coste_u = (float) $costo_caja / $cantidad;
            $breakdown[] = [
                'producto_base_id' => $base_id,
                'canonical_sku' => $m['canonical_sku'],
                'nombre_canonico' => $m['nombre_canonico'],
                'cantidad_unidades' => $cantidad,
                'costo_presentacion' => (float) $costo_caja,
                'coste_unitario' => round($coste_u, 4),
            ];

            if ($max === null || $coste_u > $max) {
                $max = $coste_u;
            }
        }

        return [
            'coste' => $max !== null ? round($max, 4) : null,
            'breakdown' => $breakdown,
            'warnings' => $warnings,
        ];
    }

    /**
     * Referencia legacy por SKU (solo lectura).
     *
     * @param string $sku
     * @return array|null
     */
    public function get_legacy_ref($sku) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}legacy_precio_ref WHERE sku = %s ORDER BY importado_at DESC LIMIT 1",
            $sku
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $costo = $row['costo_neto'] !== null ? (float) $row['costo_neto'] : null;
        return [
            'sku' => $row['sku'],
            'nombre' => $row['nombre'],
            'costo_neto' => $costo,
            'costo_sin_dato' => ($costo === null || $costo <= 0),
            'precio_neto' => $row['precio_neto'] !== null ? (float) $row['precio_neto'] : null,
            'precio_total' => $row['precio_total'] !== null ? (float) $row['precio_total'] : null,
            'codigo_barras' => $row['codigo_barras'],
            'stock_bodega_general' => $row['stock_bodega_general'],
            'fuente' => $row['fuente'],
            'referencia' => true,
        ];
    }

    /**
     * Snapshot completo del producto unitario de una familia.
     *
     * @param int $grupo_id
     * @return array|WP_Error
     */
    public function get_unit_snapshot($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $unit_id = intval($family['unit_producto_base_id'] ?? 0);
        $coste = $this->calculate_coste_unitario($grupo_id);

        $unit = null;
        $precio = null;
        $stock = 0.0;
        $ubicacion = null;

        if ($unit_id) {
            $unit = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}producto_base WHERE id = %d",
                $unit_id
            ), ARRAY_A);

            if (class_exists('Riverso_Pricing_Module')) {
                $precio = Riverso_Pricing_Module::get_instance()->get_local_price($unit_id);
            }

            if (class_exists('Riverso_Stock_Service')) {
                $stock = Riverso_Stock_Service::get_instance()->get_balance($unit_id);
            } else {
                $stock = floatval($unit['stock_abierto'] ?? 0);
            }

            $ubicacion = $wpdb->get_row($wpdb->prepare(
                "SELECT u.id, u.codigo, u.nombre, pu.cantidad, pu.es_principal
                 FROM {$prefix}producto_ubicacion pu
                 INNER JOIN {$prefix}ubicaciones u ON u.id = pu.ubicacion_id
                 WHERE pu.product_id = %d
                 ORDER BY pu.es_principal DESC, pu.cantidad DESC
                 LIMIT 1",
                $unit_id
            ), ARRAY_A);

            if (!$ubicacion) {
                $ubicacion = $wpdb->get_row($wpdb->prepare(
                    "SELECT u.id, u.codigo, u.nombre, 1 AS es_principal
                     FROM {$prefix}producto_ubicacion_preferida pup
                     INNER JOIN {$prefix}ubicaciones u ON u.id = pup.ubicacion_id
                     WHERE pup.producto_base_id = %d AND pup.es_preferido = 1
                     LIMIT 1",
                    $unit_id
                ), ARRAY_A);
            }
        }

        $legacy = $unit && !empty($unit['canonical_sku'])
            ? $this->get_legacy_ref($unit['canonical_sku'])
            : null;

        $rule_assignment = null;
        if (class_exists('Riverso_Price_Rules_Module')) {
            $rules = Riverso_Price_Rules_Module::get_instance();
            $rule_id = $rules->get_assigned_rule_id('familia', intval($grupo_id));
            if ($rule_id) {
                $rule_assignment = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, codigo, nombre, version, estado FROM {$prefix}price_rules WHERE id = %d",
                    intval($rule_id)
                ), ARRAY_A);
            }
        }

        return [
            'grupo_id' => intval($grupo_id),
            'es_producto_unitario' => (int) ($family['es_producto_unitario'] ?? 0),
            'unit_producto_base_id' => $unit_id ?: null,
            'unit' => $unit ? [
                'id' => $unit_id,
                'canonical_sku' => $unit['canonical_sku'],
                'nombre_canonico' => $unit['nombre_canonico'],
                'stock_abierto' => floatval($unit['stock_abierto'] ?? 0),
                'stock_abierto_habilitado' => (int) ($unit['stock_abierto_habilitado'] ?? 0),
            ] : null,
            'coste_calculado' => $coste['coste'],
            'coste_breakdown' => $coste['breakdown'],
            'coste_warnings' => $coste['warnings'],
            'precio' => $precio ? [
                'id' => intval($precio['id']),
                'p_asignado' => $precio['p_asignado'] !== null ? (float) $precio['p_asignado'] : null,
                'c_ref' => $precio['c_ref'] !== null ? (float) $precio['c_ref'] : null,
                'p_ref' => $precio['p_ref'] !== null ? (float) $precio['p_ref'] : null,
            ] : null,
            'stock' => $stock,
            'ubicacion' => $ubicacion,
            'legacy_ref' => $legacy,
            'rule_assignment' => $rule_assignment,
            'needs_r1_confirmation' => empty($rule_assignment),
            'falta_regla_precio' => !empty($family['es_producto_unitario']) && empty($rule_assignment),
        ];
    }

    /**
     * Crea o vincula producto unitario (idempotente).
     *
     * @param int   $grupo_id
     * @param array $opts
     * @return array|WP_Error
     */
    public function ensure_unit_product($grupo_id, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        if (!empty($opts['convert_producto_base_id'])) {
            return $this->convert_member_to_unit($grupo_id, intval($opts['convert_producto_base_id']), $opts);
        }

        if (!empty($family['unit_producto_base_id'])) {
            return $this->get_unit_snapshot($grupo_id);
        }

        $nombre = sanitize_text_field($opts['nombre'] ?? $family['nombre']);
        $sku = trim((string) ($opts['canonical_sku'] ?? ''));
        if ($sku !== '' && !preg_match('/^\d{1,6}$/', $sku)) {
            return new WP_Error('invalid_sku', 'SKU local debe ser numérico de 1 a 6 dígitos');
        }
        if ($sku === '') {
            $sku = $this->generate_next_sku();
            if (is_wp_error($sku)) {
                return $sku;
            }
        }

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
            $sku
        ));
        if ($existing_id) {
            return $this->convert_member_to_unit($grupo_id, $existing_id, $opts);
        }

        $wpdb->query('START TRANSACTION');

        $wpdb->insert("{$prefix}producto_base", [
            'canonical_sku' => $sku,
            'nombre_canonico' => $nombre,
            'unidad_base' => 'unidad',
            'stock_abierto_habilitado' => 1,
            'permite_ean13_personalizado' => 1,
            'es_unidad_minima' => 1,
            'unit_of_grupo_id' => $grupo_id,
            'estado' => 'activo',
            'origen_datos' => 'unit_product',
        ], ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']);

        $unit_id = (int) $wpdb->insert_id;
        if (!$unit_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'No se pudo crear el producto unitario');
        }

        $this->ensure_unit_envase($unit_id);
        $this->link_unit_to_family($grupo_id, $unit_id, $opts);
        $this->sync_unit_coste($unit_id, $grupo_id);

        if (!empty($opts['p_asignado']) && class_exists('Riverso_Pricing_Module')) {
            $pricing = Riverso_Pricing_Module::get_instance();
            $row = $pricing->recalc_price($unit_id, Riverso_Pricing_Module::CANAL_LOCAL);
            if (!is_wp_error($row) && !empty($row['id'])) {
                $pricing->set_assigned_price((int) $row['id'], floatval($opts['p_asignado']));
            }
        }

        $wpdb->query('COMMIT');

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('unit_product_created', 'equivalence_groups', $grupo_id, [
                'new_value' => ['unit_producto_base_id' => $unit_id, 'canonical_sku' => $sku],
            ]);
        }

        return $this->get_unit_snapshot($grupo_id);
    }

    /**
     * Convierte un miembro existente en producto unitario (conserva SKU legacy).
     *
     * @param int   $grupo_id
     * @param int   $producto_base_id
     * @param array $opts
     * @return array|WP_Error
     */
    public function convert_member_to_unit($grupo_id, $producto_base_id, array $opts = []) {
        return $this->convert_local_to_unit($grupo_id, $producto_base_id, $opts);
    }

    /**
     * Convierte un producto local (miembro o no) en producto unitario de la familia.
     *
     * @param int   $grupo_id
     * @param int   $producto_base_id
     * @param array $opts
     * @return array|WP_Error
     */
    public function convert_local_to_unit($grupo_id, $producto_base_id, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $producto_base_id = intval($producto_base_id);

        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $sku = trim((string) ($pb['canonical_sku'] ?? ''));
        if ($sku === '') {
            return new WP_Error('no_sku', 'Se requiere SKU local para convertir en producto unitario');
        }

        $existing_unit_grupo = intval($pb['unit_of_grupo_id'] ?? 0);
        if ($existing_unit_grupo > 0 && $existing_unit_grupo !== $grupo_id) {
            $other = $wpdb->get_var($wpdb->prepare(
                "SELECT nombre FROM {$prefix}equivalence_groups WHERE id = %d",
                $existing_unit_grupo
            ));
            return new WP_Error(
                'unit_other_family',
                'Este SKU ya es unidad mínima de la familia «' . ($other ?: $existing_unit_grupo) . '»'
            );
        }

        if (!empty($family['unit_producto_base_id']) && (int) $family['unit_producto_base_id'] !== $producto_base_id) {
            $this->demote_unit_product((int) $family['unit_producto_base_id']);
        }

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_members
             WHERE grupo_id = %d AND producto_base_id = %d AND activo = 1 LIMIT 1",
            $grupo_id,
            $producto_base_id
        ), ARRAY_A);
        if (!$member) {
            if (class_exists('Riverso_Family_Module')) {
                Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $producto_base_id, 100);
            } else {
                $wpdb->insert("{$prefix}equivalence_members", [
                    'grupo_id' => $grupo_id,
                    'producto_base_id' => $producto_base_id,
                    'prioridad' => 100,
                    'activo' => 1,
                ], ['%d', '%d', '%d', '%d']);
            }
        }

        $envase = $this->get_canonical_envase($producto_base_id);
        $cantidad_caja = $envase ? floatval($envase['cantidad_unidades']) : 1.0;
        $new_box_id = null;
        $pack_map = $this->get_pack_members_by_qty($grupo_id, $producto_base_id);
        $has_pack_members = !empty($pack_map);
        $preview = $this->build_link_preview($grupo_id, $producto_base_id);
        if (is_wp_error($preview)) {
            return $preview;
        }

        $wpdb->query('START TRANSACTION');

        // Si la familia ya tiene cajas (100u, 500u…), heredar códigos/barcodes a ellas.
        // Solo crear miembro-caja nuevo cuando no hay packs en la familia.
        if ($has_pack_members) {
            $inherit = $this->apply_inheritance_plan($grupo_id, $producto_base_id, $preview);
            if (is_wp_error($inherit)) {
                $wpdb->query('ROLLBACK');
                return $inherit;
            }
            if (!empty($wpdb->last_error)) {
                $err = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', 'Error al heredar códigos: ' . $err);
            }
        } elseif ($envase && $cantidad_caja > 1) {
            $new_sku = $this->generate_next_sku();
            if (is_wp_error($new_sku)) {
                $wpdb->query('ROLLBACK');
                return $new_sku;
            }

            $box_nombre = sanitize_text_field($opts['box_nombre'] ?? ($pb['nombre_canonico'] . ' (caja ' . (int) $cantidad_caja . ' u)'));

            $wpdb->insert("{$prefix}producto_base", [
                'canonical_sku' => $new_sku,
                'nombre_canonico' => $box_nombre,
                'unidad_base' => $pb['unidad_base'] ?: 'unidad',
                'woocommerce_product_id' => $pb['woocommerce_product_id'] ?: null,
                'woocommerce_variation_id' => $pb['woocommerce_variation_id'] ?: null,
                'estado' => 'activo',
                'origen_datos' => 'unit_product_split',
                'requires_human_review' => 1,
                'review_status' => 'pendiente',
            ], ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s']);

            $new_box_id = (int) $wpdb->insert_id;
            if (!$new_box_id) {
                $err = $wpdb->last_error ?: 'No se pudo crear el miembro-caja';
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_error', $err);
            }

            $envase_id = intval($envase['id']);
            $wpdb->update(
                "{$prefix}envases",
                ['producto_base_id' => $new_box_id],
                ['id' => $envase_id],
                ['%d'],
                ['%d']
            );

            $moved_pps = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_proveedor WHERE producto_base_id = %d",
                $producto_base_id
            )) ?: [];

            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_proveedor SET producto_base_id = %d WHERE producto_base_id = %d",
                $new_box_id,
                $producto_base_id
            ));
            foreach ($moved_pps as $pp_id) {
                $this->reassign_supplier_code_tasks((int) $pp_id, $new_box_id);
            }

            if (class_exists('Riverso_Family_Module')) {
                Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $new_box_id, 200);
            } else {
                $wpdb->insert("{$prefix}equivalence_members", [
                    'grupo_id' => $grupo_id,
                    'producto_base_id' => $new_box_id,
                    'prioridad' => 200,
                    'activo' => 1,
                ], ['%d', '%d', '%d', '%d']);
            }

            $moved_barcodes = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$prefix}codigo_barra
                 WHERE producto_base_id = %d AND cantidad > 1",
                $producto_base_id
            )) ?: [];

            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}codigo_barra
                 SET producto_base_id = %d, envase_id = %d, cantidad = %f, factor_a_unidad_base = %f
                 WHERE producto_base_id = %d AND cantidad > 1",
                $new_box_id,
                $envase_id,
                $cantidad_caja,
                $cantidad_caja,
                $producto_base_id
            ));
            foreach ($moved_barcodes as $bc_id) {
                $this->reassign_barcode_tasks((int) $bc_id, $new_box_id);
            }

            $wpdb->update(
                "{$prefix}producto_base",
                [
                    'woocommerce_product_id' => null,
                    'woocommerce_variation_id' => null,
                ],
                ['id' => $producto_base_id],
                ['%d', '%d'],
                ['%d']
            );
        }

        $updated_pb = $wpdb->update(
            "{$prefix}producto_base",
            [
                'es_unidad_minima' => 1,
                'unit_of_grupo_id' => $grupo_id,
                'stock_abierto_habilitado' => 1,
                'unidad_base' => 'unidad',
            ],
            ['id' => $producto_base_id],
            ['%d', '%d', '%d', '%s'],
            ['%d']
        );
        if ($updated_pb === false || !empty($wpdb->last_error)) {
            $err = $wpdb->last_error ?: 'No se pudo marcar el producto unitario';
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', $err);
        }

        $this->ensure_unit_envase($producto_base_id);
        if (!empty($wpdb->last_error)) {
            $err = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Error envase unitario: ' . $err);
        }

        $opts['es_producto_unitario'] = isset($opts['es_producto_unitario'])
            ? !empty($opts['es_producto_unitario'])
            : true;
        $this->link_unit_to_family($grupo_id, $producto_base_id, $opts);
        if (!empty($wpdb->last_error)) {
            $err = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Error al vincular familia: ' . $err);
        }

        $linked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT unit_producto_base_id FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ));
        if ($linked !== $producto_base_id) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('link_failed', 'No se persistió unit_producto_base_id en la familia');
        }

        $wpdb->query('COMMIT');
        if (!empty($wpdb->last_error)) {
            return new WP_Error('db_error', 'COMMIT falló: ' . $wpdb->last_error);
        }

        // Fuera de la transacción: pricing puede hacer queries complejas.
        $this->sync_unit_coste($producto_base_id, $grupo_id);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('unit_product_converted', 'equivalence_groups', $grupo_id, [
                'old_value' => ['producto_base_id' => $producto_base_id],
                'new_value' => [
                    'unit_producto_base_id' => $producto_base_id,
                    'box_producto_base_id' => $new_box_id,
                    'inherited' => $has_pack_members,
                ],
            ]);
        }

        $snapshot = $this->get_unit_snapshot($grupo_id);
        if (!is_wp_error($snapshot)) {
            $snapshot['box_producto_base_id'] = $new_box_id;
            $snapshot['link_preview'] = $preview;
        }
        return $snapshot;
    }

    /**
     * Miembros de familia con envase cantidad > 1, indexados por cantidad.
     *
     * @param int $grupo_id
     * @param int $exclude_base_id
     * @return array<string, array{producto_base_id:int,canonical_sku:?string,nombre_canonico:?string,cantidad_unidades:float,envase_id:?int}>
     */
    public function get_pack_members_by_qty($grupo_id, $exclude_base_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $exclude_base_id = intval($exclude_base_id);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id,
                    e.id AS envase_id, e.cantidad_unidades, e.codigo_proveedor, e.origen_datos
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id AND pb.deleted_at IS NULL
             LEFT JOIN {$prefix}envases e ON e.producto_base_id = em.producto_base_id AND e.activo = 1
             WHERE em.grupo_id = %d AND em.activo = 1
               AND em.producto_base_id <> %d
             ORDER BY e.cantidad_unidades DESC",
            $grupo_id,
            $exclude_base_id
        ), ARRAY_A) ?: [];

        $map = [];
        $seen_products = [];
        foreach ($rows as $row) {
            $base_id = intval($row['producto_base_id']);
            if (isset($seen_products[$base_id])) {
                continue;
            }
            $qty = floatval($row['cantidad_unidades'] ?? 0);
            $envase_id = !empty($row['envase_id']) ? intval($row['envase_id']) : null;
            $origen = (string) ($row['origen_datos'] ?? '');

            // Corregir falso positivo woo (#8) antes de usarlo como pack.
            if ($origen === 'woo_attr_envase' && $qty > 0 && $qty <= 16) {
                $woo_qty = $this->resolve_envase_qty_from_woo(
                    intval($row['woocommerce_variation_id'] ?? 0),
                    intval($row['woocommerce_product_id'] ?? 0)
                );
                if ($woo_qty > 1) {
                    $qty = $woo_qty;
                    if ($envase_id) {
                        $wpdb->update(
                            "{$prefix}envases",
                            ['cantidad_unidades' => $woo_qty],
                            ['id' => $envase_id],
                            ['%f'],
                            ['%d']
                        );
                    }
                } else {
                    $qty = 0;
                }
            }

            if ($qty <= 1) {
                $woo_qty = $this->resolve_envase_qty_from_woo(
                    intval($row['woocommerce_variation_id'] ?? 0),
                    intval($row['woocommerce_product_id'] ?? 0)
                );
                if ($woo_qty > 1) {
                    $qty = $woo_qty;
                }
            }
            if ($qty <= 1) {
                continue;
            }
            $seen_products[$base_id] = true;
            $qty_key = $this->qty_key($qty);
            if (isset($map[$qty_key])) {
                // Misma cantidad en dos miembros: no se puede heredar por qty de forma única.
                continue;
            }
            $sku_online = $this->resolve_woo_sku(
                intval($row['woocommerce_variation_id'] ?? 0),
                intval($row['woocommerce_product_id'] ?? 0)
            );
            $aliases = [];
            if (!empty($row['codigo_proveedor'])) {
                $aliases[] = strtoupper(trim((string) $row['codigo_proveedor']));
            }
            if ($sku_online !== '') {
                $aliases[] = strtoupper($sku_online);
            }
            if (!empty($row['canonical_sku'])) {
                $aliases[] = strtoupper(trim((string) $row['canonical_sku']));
            }
            $map[$qty_key] = [
                'producto_base_id' => $base_id,
                'canonical_sku' => $row['canonical_sku'],
                'nombre_canonico' => $row['nombre_canonico'],
                'cantidad_unidades' => $qty,
                'envase_id' => $envase_id,
                'codigo_proveedor' => $row['codigo_proveedor'] ?? null,
                'sku_online' => $sku_online !== '' ? $sku_online : null,
                'aliases' => array_values(array_unique(array_filter($aliases))),
            ];
        }
        return $map;
    }

    private function resolve_envase_qty_from_woo($variation_id, $product_id) {
        $woo_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($woo_id <= 0 || !function_exists('wc_get_product')) {
            return 0.0;
        }
        $product = wc_get_product($woo_id);
        if (!$product) {
            return 0.0;
        }

        $candidates = [];
        foreach (['envase', 'pa_envase', 'packaging', 'pack'] as $slug) {
            $v = $product->get_attribute($slug);
            if ($v) {
                $candidates[$slug] = $v;
            }
        }
        $attrs = $product->get_attributes();
        if (is_array($attrs)) {
            foreach ($attrs as $key => $attr) {
                $name = is_object($attr) && method_exists($attr, 'get_name')
                    ? (string) $attr->get_name()
                    : (string) $key;
                $hay = strtolower($name . ' ' . $key);
                if (strpos($hay, 'envase') === false && strpos($hay, 'pack') === false) {
                    continue;
                }
                $val = '';
                if (is_string($attr)) {
                    $val = $attr;
                } elseif (is_object($attr) && method_exists($attr, 'get_options')) {
                    $opts = $attr->get_options();
                    $val = is_array($opts) ? implode(' ', $opts) : (string) $opts;
                }
                $candidates[$name] = $val;
            }
        }

        foreach ($candidates as $val) {
            $text = trim((string) $val);
            if ($text === '') {
                continue;
            }
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*u\b/i', $text, $m)) {
                $n = floatval(str_replace(',', '.', $m[1]));
                if ($n > 1) {
                    return $n;
                }
            }
            if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $text, $m)) {
                $n = floatval(str_replace(',', '.', $m[1]));
                if ($n > 16) {
                    return $n;
                }
            }
        }
        return 0.0;
    }

    private function resolve_woo_sku($variation_id, $product_id) {
        if ($variation_id > 0 && function_exists('get_post_meta')) {
            $sku = (string) get_post_meta($variation_id, '_sku', true);
            if ($sku !== '') {
                return $sku;
            }
        }
        if ($product_id > 0 && function_exists('get_post_meta')) {
            $sku = (string) get_post_meta($product_id, '_sku', true);
            if ($sku !== '') {
                return $sku;
            }
        }
        return '';
    }

    /**
     * Flags SKU local/online para un miembro (misma lógica que Family Module).
     *
     * @param array $row producto_base o miembro con canonical_sku + woo ids
     * @return array{sku_local:string,sku_online:string,es_local:bool,es_online:bool}
     */
    public function member_sku_flags(array $row) {
        $sku_local = trim((string) ($row['canonical_sku'] ?? ''));
        $sku_online = $this->resolve_woo_sku(
            absint($row['woocommerce_variation_id'] ?? 0),
            absint($row['woocommerce_product_id'] ?? 0)
        );
        $es_online = $sku_online !== ''
            || absint($row['woocommerce_product_id'] ?? 0) > 0
            || absint($row['woocommerce_variation_id'] ?? 0) > 0;
        return [
            'sku_local' => $sku_local,
            'sku_online' => $sku_online,
            'es_local' => $sku_local !== '',
            'es_online' => $es_online,
        ];
    }

    /**
     * Preview de herencia de códigos/barcodes/tareas antes de vincular unitario.
     *
     * @param int $grupo_id
     * @param int $producto_base_id
     * @return array|WP_Error
     */
    public function build_link_preview($grupo_id, $producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $producto_base_id = intval($producto_base_id);

        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $pack_map = $this->get_pack_members_by_qty($grupo_id, $producto_base_id);
        $members_caja = array_values($pack_map);
        $split_would_create_box = empty($pack_map);

        $barcodes = $wpdb->get_results($wpdb->prepare(
            "SELECT id, codigo, cantidad, factor_a_unidad_base, envase_id, tipo, estado, origen_datos
             FROM {$prefix}codigo_barra WHERE producto_base_id = %d
             ORDER BY cantidad DESC, id ASC",
            $producto_base_id
        ), ARRAY_A) ?: [];

        $supplier_codes = $wpdb->get_results($wpdb->prepare(
            "SELECT id, codigo_proveedor, factor_conversion, nombre_proveedor, catalogo_id, activo
             FROM {$prefix}producto_proveedor WHERE producto_base_id = %d AND activo = 1
             ORDER BY id ASC",
            $producto_base_id
        ), ARRAY_A) ?: [];

        $barcode_items = [];
        foreach ($barcodes as $bc) {
            $qty = floatval($bc['cantidad'] ?? 0);
            if ($qty <= 0) {
                $qty = floatval($bc['factor_a_unidad_base'] ?? 0);
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $item = $this->suggest_inherit_action($qty, $pack_map, [
                'id' => intval($bc['id']),
                'tipo' => 'barcode',
                'codigo' => $bc['codigo'],
                'cantidad' => $qty,
            ]);
            $item['task_ids'] = $this->find_open_barcode_task_ids(intval($bc['id']));
            $barcode_items[] = $item;
        }

        $code_items = [];
        foreach ($supplier_codes as $pp) {
            $qty = floatval($pp['factor_conversion'] ?? 0);
            $matched_by_code = null;
            $codigo = (string) ($pp['codigo_proveedor'] ?? '');
            $codigo_norm = strtoupper(trim($codigo));
            if ($codigo_norm !== '') {
                foreach ($pack_map as $pack) {
                    $aliases = $pack['aliases'] ?? [];
                    foreach ($aliases as $alias) {
                        if ($alias === $codigo_norm
                            || str_replace(['-', ' '], '', $alias) === str_replace(['-', ' '], '', $codigo_norm)
                        ) {
                            $matched_by_code = $pack;
                            $qty = floatval($pack['cantidad_unidades']);
                            break 2;
                        }
                    }
                }
            }
            if ($qty <= 0 && $matched_by_code) {
                $qty = floatval($matched_by_code['cantidad_unidades']);
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }

            if ($matched_by_code) {
                $item = [
                    'id' => intval($pp['id']),
                    'tipo' => 'supplier_code',
                    'codigo' => $codigo,
                    'cantidad' => $qty,
                    'action' => 'inherit',
                    'suggested_producto_base_id' => intval($matched_by_code['producto_base_id']),
                    'suggested_label' => $this->member_label($matched_by_code),
                ];
            } else {
                $item = $this->suggest_inherit_action($qty, $pack_map, [
                    'id' => intval($pp['id']),
                    'tipo' => 'supplier_code',
                    'codigo' => $codigo,
                    'cantidad' => $qty,
                ]);
            }
            $item['task_ids'] = $this->find_open_supplier_task_ids(intval($pp['id']));
            $code_items[] = $item;
        }

        $tasks = [];
        foreach (array_merge($barcode_items, $code_items) as $row) {
            foreach ($row['task_ids'] as $tid) {
                $tasks[] = [
                    'id' => $tid,
                    'follows' => $row['tipo'],
                    'ref_id' => $row['id'],
                    'action' => $row['action'],
                    'suggested_producto_base_id' => $row['suggested_producto_base_id'],
                ];
            }
        }

        return [
            'unit' => [
                'id' => intval($pb['id']),
                'canonical_sku' => $pb['canonical_sku'],
                'nombre_canonico' => $pb['nombre_canonico'],
            ],
            'members_caja' => $members_caja,
            'barcodes' => $barcode_items,
            'supplier_codes' => $code_items,
            'tasks' => $tasks,
            'split_would_create_box' => $split_would_create_box,
            'summary' => [
                'inherit' => count(array_filter(array_merge($barcode_items, $code_items), function ($r) {
                    return ($r['action'] ?? '') === 'inherit';
                })),
                'keep_unit' => count(array_filter(array_merge($barcode_items, $code_items), function ($r) {
                    return ($r['action'] ?? '') === 'keep_unit';
                })),
                'unresolved' => count(array_filter(array_merge($barcode_items, $code_items), function ($r) {
                    return ($r['action'] ?? '') === 'unresolved';
                })),
            ],
        ];
    }

    /**
     * Aplica herencia según preview (o recalcula).
     *
     * @param int        $grupo_id
     * @param int        $unit_id
     * @param array|null $preview
     * @return true|WP_Error
     */
    public function apply_inheritance_plan($grupo_id, $unit_id, $preview = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $unit_id = intval($unit_id);
        $grupo_id = intval($grupo_id);

        if ($preview === null) {
            $preview = $this->build_link_preview($grupo_id, $unit_id);
            if (is_wp_error($preview)) {
                return $preview;
            }
        }

        $pack_map = $this->get_pack_members_by_qty($grupo_id, $unit_id);

        foreach ($preview['supplier_codes'] as $row) {
            if (($row['action'] ?? '') !== 'inherit') {
                continue;
            }
            $dest = intval($row['suggested_producto_base_id'] ?? 0);
            $pp_id = intval($row['id'] ?? 0);
            if (!$dest || !$pp_id) {
                continue;
            }
            $wpdb->update(
                "{$prefix}producto_proveedor",
                ['producto_base_id' => $dest],
                ['id' => $pp_id, 'producto_base_id' => $unit_id],
                ['%d'],
                ['%d', '%d']
            );
            $this->reassign_supplier_code_tasks($pp_id, $dest);
        }

        foreach ($preview['barcodes'] as $row) {
            if (($row['action'] ?? '') !== 'inherit') {
                continue;
            }
            $dest = intval($row['suggested_producto_base_id'] ?? 0);
            $bc_id = intval($row['id'] ?? 0);
            if (!$dest || !$bc_id) {
                continue;
            }
            $qty = floatval($row['cantidad'] ?? 0);
            $qty_key = $this->qty_key($qty);
            $envase_id = isset($pack_map[$qty_key]['envase_id'])
                ? intval($pack_map[$qty_key]['envase_id'])
                : null;
            $data = ['producto_base_id' => $dest];
            $formats = ['%d'];
            if ($qty > 1) {
                $data['cantidad'] = $qty;
                $data['factor_a_unidad_base'] = $qty;
                $formats[] = '%f';
                $formats[] = '%f';
            }
            if ($envase_id) {
                $data['envase_id'] = $envase_id;
                $formats[] = '%d';
            }
            $wpdb->update(
                "{$prefix}codigo_barra",
                $data,
                ['id' => $bc_id, 'producto_base_id' => $unit_id],
                $formats,
                ['%d', '%d']
            );
            $this->reassign_barcode_tasks($bc_id, $dest);
        }

        // Anotar destinos sugeridos en tareas aparcadas que ahora coinciden con un pack.
        $this->annotate_parked_barcode_tasks_for_family($grupo_id);

        return true;
    }

    /**
     * Preview de remapeo de un barcode (unitario → hijo/envase).
     *
     * @param int $producto_base_id Producto donde está el barcode hoy
     * @param int $barcode_id
     * @return array|WP_Error
     */
    public function build_barcode_remap_preview($producto_base_id, $barcode_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);
        $barcode_id = intval($barcode_id);

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, es_unidad_minima, unit_of_grupo_id,
                    familia_decision, estado
             FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $barcode_id
        ), ARRAY_A);
        if (!$barcode) {
            return new WP_Error('not_found', 'Código de barra no encontrado');
        }
        if ((int) ($barcode['producto_base_id'] ?? 0) !== $producto_base_id) {
            return new WP_Error('mismatch', 'El código no pertenece a este producto');
        }

        $family_ctx = $this->resolve_family_context_for_remap($producto_base_id);
        $grupo_id = $family_ctx['grupo_id'] ? intval($family_ctx['grupo_id']) : 0;
        $is_unitario = !empty($pb['es_unidad_minima'])
            || (!empty($family_ctx['unit_producto_base_id'])
                && intval($family_ctx['unit_producto_base_id']) === $producto_base_id);
        $can_be_unitario = $is_unitario
            || empty($pb['familia_decision'])
            || ($pb['familia_decision'] === 'requiere')
            || ($grupo_id > 0 && empty($family_ctx['unit_producto_base_id']));

        $pack_map = $grupo_id
            ? $this->get_pack_members_by_qty($grupo_id, $producto_base_id)
            : [];
        $members_caja = array_values($pack_map);

        $qty = floatval($barcode['cantidad'] ?? 0);
        if ($qty <= 0) {
            $qty = floatval($barcode['factor_a_unidad_base'] ?? 0);
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }

        $suggested = null;
        $qty_key = $this->qty_key($qty);
        if ($qty > 1.0001 && isset($pack_map[$qty_key])) {
            $suggested = $pack_map[$qty_key];
        }

        $task_ids = $this->find_open_barcode_task_ids($barcode_id);
        $parked = false;
        foreach ($task_ids as $tid) {
            $extra = $this->get_task_datos_extra($tid);
            if (!empty($extra['parked_until_presentacion'])
                || (($extra['mapeo_accion'] ?? '') === 'park_presentacion')
            ) {
                $parked = true;
                break;
            }
        }

        $can_create_child = false;
        if ($grupo_id > 0) {
            if ($is_unitario) {
                $can_create_child = true;
            } elseif (empty($family_ctx['unit_producto_base_id']) && $can_be_unitario) {
                // Familia sin unitario aún: se puede crear el hijo si este producto será el unitario.
                $can_create_child = true;
            }
        }

        return [
            'product' => [
                'id' => intval($pb['id']),
                'canonical_sku' => $pb['canonical_sku'],
                'nombre_canonico' => $pb['nombre_canonico'],
                'es_unidad_minima' => (int) ($pb['es_unidad_minima'] ?? 0),
                'familia_decision' => $pb['familia_decision'] ?? null,
            ],
            'barcode' => [
                'id' => intval($barcode['id']),
                'codigo' => $barcode['codigo'],
                'cantidad' => $qty,
                'estado' => $barcode['estado'] ?? '',
                'tipo' => $barcode['tipo'] ?? '',
                'origen_datos' => $barcode['origen_datos'] ?? '',
            ],
            'family' => $family_ctx,
            'is_unitario' => (bool) $is_unitario,
            'can_be_unitario' => (bool) $can_be_unitario,
            'show_wizard' => (bool) ($is_unitario || $can_be_unitario),
            'can_create_child' => (bool) $can_create_child,
            'can_assign_child' => (bool) $can_create_child,
            'can_move_child' => !empty($members_caja),
            'can_park' => true,
            'members_caja' => $members_caja,
            'suggested_destino' => $suggested,
            'task_ids' => $task_ids,
            'parked' => $parked,
            'actions' => ['keep_unit', 'move_child', 'assign_child', 'create_child', 'park_presentacion', 'reject'],
        ];
    }

    /**
     * Preview de remapeo código proveedor (unitario → hijo/envase).
     *
     * @param int $producto_base_id
     * @param int $pp_id producto_proveedor.id
     * @return array|WP_Error
     */
    public function build_code_remap_preview($producto_base_id, $pp_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);
        $pp_id = intval($pp_id);

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, es_unidad_minima, unit_of_grupo_id,
                    familia_decision, estado
             FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT pp.*, p.nombre AS proveedor_nombre
             FROM {$prefix}producto_proveedor pp
             LEFT JOIN {$prefix}proveedores p ON p.id = pp.proveedor_id
             WHERE pp.id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Código de proveedor no encontrado');
        }
        if ((int) ($pp['producto_base_id'] ?? 0) !== $producto_base_id) {
            return new WP_Error('mismatch', 'El código no pertenece a este producto');
        }
        if (isset($pp['activo']) && (int) $pp['activo'] !== 1) {
            return new WP_Error('inactive', 'El código no está activo');
        }

        $family_ctx = $this->resolve_family_context_for_remap($producto_base_id);
        $grupo_id = $family_ctx['grupo_id'] ? intval($family_ctx['grupo_id']) : 0;
        $is_unitario = !empty($pb['es_unidad_minima'])
            || (!empty($family_ctx['unit_producto_base_id'])
                && intval($family_ctx['unit_producto_base_id']) === $producto_base_id);
        $can_be_unitario = $is_unitario
            || empty($pb['familia_decision'])
            || ($pb['familia_decision'] === 'requiere')
            || ($grupo_id > 0 && empty($family_ctx['unit_producto_base_id']));

        $pack_map = $grupo_id
            ? $this->get_pack_members_by_qty($grupo_id, $producto_base_id)
            : [];
        $members_caja = array_values($pack_map);

        $can_create_child = false;
        if ($grupo_id > 0) {
            if ($is_unitario) {
                $can_create_child = true;
            } elseif (empty($family_ctx['unit_producto_base_id']) && $can_be_unitario) {
                $can_create_child = true;
            }
        }

        $needs_confirm = function_exists('riverso_pp_needs_human_confirm')
            ? riverso_pp_needs_human_confirm($pp)
            : false;

        return [
            'product' => [
                'id' => intval($pb['id']),
                'canonical_sku' => $pb['canonical_sku'],
                'nombre_canonico' => $pb['nombre_canonico'],
                'es_unidad_minima' => (int) ($pb['es_unidad_minima'] ?? 0),
                'familia_decision' => $pb['familia_decision'] ?? null,
            ],
            'code' => [
                'id' => intval($pp['id']),
                'codigo_proveedor' => $pp['codigo_proveedor'] ?? '',
                'proveedor_id' => (int) ($pp['proveedor_id'] ?? 0),
                'proveedor_nombre' => $pp['proveedor_nombre'] ?? '',
                'nombre_proveedor' => $pp['nombre_proveedor'] ?? '',
                'origen_datos' => $pp['origen_datos'] ?? '',
                'match_estado' => $pp['match_estado'] ?? '',
                'needs_confirm' => (bool) $needs_confirm,
                'catalogo_id' => isset($pp['catalogo_id']) ? (int) $pp['catalogo_id'] : null,
            ],
            'family' => $family_ctx,
            'is_unitario' => (bool) $is_unitario,
            'can_be_unitario' => (bool) $can_be_unitario,
            'show_wizard' => (bool) ($is_unitario || $can_be_unitario),
            'can_create_child' => (bool) $can_create_child,
            'can_assign_child' => (bool) $can_create_child,
            'can_move_child' => !empty($members_caja),
            'members_caja' => $members_caja,
            'suggested_destino' => !empty($members_caja) ? $members_caja[0] : null,
            'actions' => ['keep_unit', 'move_child', 'assign_child', 'create_child'],
        ];
    }

    /**
     * Mueve un vínculo producto_proveedor al producto destino (hijo/envase).
     *
     * @param int   $pp_id
     * @param int   $destino_producto_base_id
     * @param array $opts verify, motivo
     * @return array|WP_Error
     */
    public function move_supplier_code_to_product($pp_id, $destino_producto_base_id, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = intval($pp_id);
        $destino_producto_base_id = intval($destino_producto_base_id);
        if ($pp_id <= 0 || $destino_producto_base_id <= 0) {
            return new WP_Error('invalid', 'Parámetros inválidos');
        }

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Código de proveedor no encontrado');
        }

        $dest = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $destino_producto_base_id
        ), ARRAY_A);
        if (!$dest) {
            return new WP_Error('not_found', 'Producto destino no encontrado');
        }

        $from_id = (int) ($pp['producto_base_id'] ?? 0);
        if ($from_id === $destino_producto_base_id) {
            return [
                'pp_id' => $pp_id,
                'from_producto_base_id' => $from_id,
                'destino_producto_base_id' => $destino_producto_base_id,
                'moved' => false,
            ];
        }

        // Unique (proveedor_id, codigo_proveedor): no puede haber otro activo en destino.
        $clash = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id FROM {$prefix}producto_proveedor
             WHERE proveedor_id = %d AND codigo_proveedor = %s AND id <> %d AND activo = 1
             LIMIT 1",
            (int) $pp['proveedor_id'],
            $pp['codigo_proveedor'],
            $pp_id
        ), ARRAY_A);
        if ($clash && (int) $clash['producto_base_id'] !== $destino_producto_base_id) {
            return new WP_Error(
                'conflict',
                'El código ya está vinculado a otro producto (#' . (int) $clash['producto_base_id'] . ').'
            );
        }

        $verify = !isset($opts['verify']) || !empty($opts['verify']);
        $update = [
            'producto_base_id' => $destino_producto_base_id,
            'updated_at' => current_time('mysql'),
        ];
        if ($verify) {
            $update['match_estado'] = 'VERIFIED';
            $update['matched_at'] = current_time('mysql');
            $update['match_origen'] = 'human';
            $update['requires_human_review'] = 0;
            $update['review_status'] = 'aprobado';
        }

        $ok = $wpdb->update(
            "{$prefix}producto_proveedor",
            $update,
            ['id' => $pp_id]
        );
        if ($ok === false) {
            return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo mover el código');
        }

        return [
            'pp_id' => $pp_id,
            'from_producto_base_id' => $from_id,
            'destino_producto_base_id' => $destino_producto_base_id,
            'codigo_proveedor' => $pp['codigo_proveedor'],
            'proveedor_id' => (int) $pp['proveedor_id'],
            'moved' => true,
            'verified' => $verify,
        ];
    }

    /**
     * Asigna un producto_base ya existente como miembro-caja de la familia
     * (ensure member + envase con cantidad) para luego mapear el barcode.
     *
     * @param int   $grupo_id
     * @param int   $unit_id Producto unitario (origen del barcode)
     * @param int   $child_id Producto hijo ya creado
     * @param float $cantidad
     * @param array $opts tipo_envase
     * @return array|WP_Error
     */
    public function assign_pack_member_for_qty($grupo_id, $unit_id, $child_id, $cantidad, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $unit_id = intval($unit_id);
        $child_id = intval($child_id);
        $cantidad = floatval($cantidad);

        if ($grupo_id <= 0 || $unit_id <= 0 || $child_id <= 0) {
            return new WP_Error('invalid', 'Familia, unitario e hijo son requeridos');
        }
        if ($child_id === $unit_id) {
            return new WP_Error('invalid', 'El hijo no puede ser el mismo producto unitario');
        }
        if ($cantidad <= 1.0001) {
            return new WP_Error('invalid_qty', 'La cantidad del envase debe ser mayor que 1');
        }

        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $child = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, es_unidad_minima, unit_of_grupo_id, deleted_at, estado
             FROM {$prefix}producto_base WHERE id = %d",
            $child_id
        ), ARRAY_A);
        if (!$child || !empty($child['deleted_at'])) {
            return new WP_Error('not_found', 'Producto hijo no encontrado');
        }
        if (!empty($child['es_unidad_minima']) && intval($child['unit_of_grupo_id'] ?? 0) > 0
            && intval($child['unit_of_grupo_id']) !== $grupo_id
        ) {
            return new WP_Error('conflict', 'Ese producto ya es unitario de otra familia');
        }
        if (!empty($child['es_unidad_minima']) && intval($child['unit_of_grupo_id'] ?? 0) === $grupo_id) {
            return new WP_Error('invalid', 'No puedes asignar el unitario de esta familia como hijo-caja');
        }

        $pack_map = $this->get_pack_members_by_qty($grupo_id, $unit_id);
        $qty_key = $this->qty_key($cantidad);
        if (isset($pack_map[$qty_key]) && intval($pack_map[$qty_key]['producto_base_id']) !== $child_id) {
            return new WP_Error(
                'duplicate_pack',
                'Ya existe otro miembro con envase de '
                    . rtrim(rtrim(number_format($cantidad, 4, '.', ''), '0'), '.')
                    . ' u: ' . $this->member_label($pack_map[$qty_key])
            );
        }

        // Si ya pertenece a otra familia exacta, bloquear.
        if (class_exists('Riverso_Family_Module')) {
            $fam_mod = Riverso_Family_Module::get_instance();
            if (method_exists($fam_mod, 'get_exacta_family_of_product')) {
                $other = $fam_mod->get_exacta_family_of_product($child_id);
                if ($other && intval($other['grupo_id']) !== $grupo_id) {
                    return new WP_Error(
                        'other_family',
                        'El producto ya pertenece a la familia exacta "'
                            . ($other['nombre'] ?: ($other['codigo_grupo'] ?? '#' . $other['grupo_id']))
                            . '".'
                    );
                }
            }
        }

        if (class_exists('Riverso_Family_Module')) {
            Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $child_id, 200);
        } else {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}equivalence_members
                 WHERE grupo_id = %d AND producto_base_id = %d LIMIT 1",
                $grupo_id,
                $child_id
            ));
            if ($exists) {
                $wpdb->update(
                    "{$prefix}equivalence_members",
                    ['activo' => 1, 'prioridad' => 200],
                    ['id' => intval($exists)],
                    ['%d', '%d'],
                    ['%d']
                );
            } else {
                $wpdb->insert("{$prefix}equivalence_members", [
                    'grupo_id' => $grupo_id,
                    'producto_base_id' => $child_id,
                    'prioridad' => 200,
                    'activo' => 1,
                ], ['%d', '%d', '%d', '%d']);
            }
        }

        $tipo_envase = sanitize_text_field($opts['tipo_envase'] ?? 'caja');
        if ($tipo_envase === '') {
            $tipo_envase = 'caja';
        }

        $envase_id = 0;
        $existing_envase = $this->get_canonical_envase($child_id);
        if ($existing_envase) {
            $envase_id = intval($existing_envase['id']);
            $wpdb->update(
                "{$prefix}envases",
                [
                    'cantidad_unidades' => $cantidad,
                    'tipo_envase' => $tipo_envase,
                    'activo' => 1,
                    'es_vendible' => 1,
                    'permite_apertura' => 1,
                ],
                ['id' => $envase_id],
                ['%f', '%s', '%d', '%d', '%d'],
                ['%d']
            );
        } elseif (class_exists('Riverso_Packaging_Module')) {
            $created = Riverso_Packaging_Module::get_instance()->create_envase(
                $child_id,
                $cantidad,
                '',
                0,
                [
                    'tipo_envase' => $tipo_envase,
                    'origen_datos' => 'barcode_remap_assign',
                    'es_vendible' => 1,
                    'permite_apertura' => 1,
                ]
            );
            if (!is_wp_error($created)) {
                $envase_id = intval($created);
            }
        }
        if (!$envase_id) {
            $wpdb->insert("{$prefix}envases", [
                'producto_base_id' => $child_id,
                'cantidad_unidades' => $cantidad,
                'tipo_envase' => $tipo_envase,
                'permite_apertura' => 1,
                'es_vendible' => 1,
                'origen_datos' => 'barcode_remap_assign',
                'activo' => 1,
            ], ['%d', '%f', '%s', '%d', '%d', '%s', '%d']);
            $envase_id = (int) $wpdb->insert_id;
        }

        $this->annotate_parked_barcode_tasks_for_family($grupo_id);

        return [
            'producto_base_id' => $child_id,
            'envase_id' => $envase_id,
            'canonical_sku' => $child['canonical_sku'],
            'nombre_canonico' => $child['nombre_canonico'],
            'cantidad_unidades' => $cantidad,
            'assigned' => true,
        ];
    }

    /**
     * Crea un miembro-caja en la familia con envase de la cantidad indicada.
     *
     * @param int   $grupo_id
     * @param int   $unit_id Producto unitario (o candidato)
     * @param float $cantidad
     * @param array $opts box_nombre, tipo_envase
     * @return array|WP_Error {producto_base_id, envase_id, canonical_sku, cantidad_unidades}
     */
    public function create_pack_member_for_qty($grupo_id, $unit_id, $cantidad, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $unit_id = intval($unit_id);
        $cantidad = floatval($cantidad);

        if ($grupo_id <= 0 || $unit_id <= 0) {
            return new WP_Error('invalid', 'Familia y producto unitario son requeridos');
        }
        if ($cantidad <= 1.0001) {
            return new WP_Error('invalid_qty', 'La cantidad del envase debe ser mayor que 1');
        }

        $family = $this->get_family_row($grupo_id);
        if (!$family) {
            return new WP_Error('not_found', 'Familia no encontrada');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, unidad_base
             FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
            $unit_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto unitario no encontrado');
        }

        $pack_map = $this->get_pack_members_by_qty($grupo_id, $unit_id);
        $qty_key = $this->qty_key($cantidad);
        if (isset($pack_map[$qty_key])) {
            return new WP_Error(
                'duplicate_pack',
                'Ya existe un miembro con envase de '
                    . rtrim(rtrim(number_format($cantidad, 4, '.', ''), '0'), '.')
                    . ' u: ' . $this->member_label($pack_map[$qty_key])
            );
        }

        $new_sku = $this->generate_next_sku();
        if (is_wp_error($new_sku)) {
            return $new_sku;
        }

        $box_nombre = sanitize_text_field(
            $opts['box_nombre']
            ?? ($pb['nombre_canonico'] . ' (caja ' . (int) $cantidad . ' u)')
        );
        $tipo_envase = sanitize_text_field($opts['tipo_envase'] ?? 'caja');
        if ($tipo_envase === '') {
            $tipo_envase = 'caja';
        }

        $wpdb->insert("{$prefix}producto_base", [
            'canonical_sku' => $new_sku,
            'nombre_canonico' => $box_nombre,
            'unidad_base' => $pb['unidad_base'] ?: 'unidad',
            'estado' => 'activo',
            'origen_datos' => 'barcode_pack_split',
            'requires_human_review' => 1,
            'review_status' => 'pendiente',
        ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s']);

        $new_box_id = (int) $wpdb->insert_id;
        if (!$new_box_id) {
            return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo crear el miembro-caja');
        }

        if (class_exists('Riverso_Family_Module')) {
            Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $new_box_id, 200);
        } else {
            $wpdb->insert("{$prefix}equivalence_members", [
                'grupo_id' => $grupo_id,
                'producto_base_id' => $new_box_id,
                'prioridad' => 200,
                'activo' => 1,
            ], ['%d', '%d', '%d', '%d']);
        }

        $envase_id = 0;
        if (class_exists('Riverso_Packaging_Module')) {
            $created = Riverso_Packaging_Module::get_instance()->create_envase(
                $new_box_id,
                $cantidad,
                '',
                0,
                [
                    'tipo_envase' => $tipo_envase,
                    'origen_datos' => 'barcode_remap',
                    'es_vendible' => 1,
                    'permite_apertura' => 1,
                ]
            );
            if (!is_wp_error($created)) {
                $envase_id = intval($created);
            }
        }
        if (!$envase_id) {
            $wpdb->insert("{$prefix}envases", [
                'producto_base_id' => $new_box_id,
                'cantidad_unidades' => $cantidad,
                'tipo_envase' => $tipo_envase,
                'permite_apertura' => 1,
                'es_vendible' => 1,
                'origen_datos' => 'barcode_remap',
                'activo' => 1,
            ], ['%d', '%f', '%s', '%d', '%d', '%s', '%d']);
            $envase_id = (int) $wpdb->insert_id;
        }

        $this->annotate_parked_barcode_tasks_for_family($grupo_id);

        return [
            'producto_base_id' => $new_box_id,
            'envase_id' => $envase_id,
            'canonical_sku' => $new_sku,
            'nombre_canonico' => $box_nombre,
            'cantidad_unidades' => $cantidad,
        ];
    }

    /**
     * Mueve un barcode al hijo/envase y reasigna tareas abiertas.
     *
     * @param int   $barcode_id
     * @param int   $dest_producto_base_id
     * @param float $cantidad
     * @param array $opts verify, motivo, envase_id
     * @return true|WP_Error
     */
    public function move_barcode_to_pack($barcode_id, $dest_producto_base_id, $cantidad, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $barcode_id = intval($barcode_id);
        $dest_producto_base_id = intval($dest_producto_base_id);
        $cantidad = floatval($cantidad);

        if ($barcode_id <= 0 || $dest_producto_base_id <= 0) {
            return new WP_Error('invalid', 'barcode y destino son requeridos');
        }
        if ($cantidad <= 0) {
            $cantidad = 1.0;
        }

        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $barcode_id
        ), ARRAY_A);
        if (!$barcode) {
            return new WP_Error('not_found', 'Código de barra no encontrado');
        }

        $dest = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
            $dest_producto_base_id
        ), ARRAY_A);
        if (!$dest) {
            return new WP_Error('not_found', 'Producto destino no encontrado');
        }

        $envase_id = intval($opts['envase_id'] ?? 0);
        if (!$envase_id) {
            $envase = $this->get_canonical_envase($dest_producto_base_id);
            if ($envase) {
                $envase_id = intval($envase['id']);
                if ($cantidad <= 1.0001 && floatval($envase['cantidad_unidades']) > 1) {
                    $cantidad = floatval($envase['cantidad_unidades']);
                }
            }
        }

        $data = [
            'producto_base_id' => $dest_producto_base_id,
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $cantidad,
            'pending_sku' => null,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%d', '%f', '%f', '%s', '%s'];
        if ($envase_id) {
            $data['envase_id'] = $envase_id;
            $formats[] = '%d';
        }

        $updated = $wpdb->update(
            "{$prefix}codigo_barra",
            $data,
            ['id' => $barcode_id],
            $formats,
            ['%d']
        );
        if ($updated === false) {
            return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo mover el código');
        }

        $this->reassign_barcode_tasks($barcode_id, $dest_producto_base_id, [
            'mapeo_accion' => 'move_child',
            'destino_producto_base_id' => $dest_producto_base_id,
            'cantidad_pack' => $cantidad,
            'parked_until_presentacion' => 0,
        ]);

        $verify = !empty($opts['verify']);
        if ($verify && class_exists('Riverso_Barcode_Model')) {
            $motivo = sanitize_text_field($opts['motivo'] ?? 'Mapeado a envase/hijo desde unitario');
            if (Riverso_Barcode_Model::is_legacy_row($barcode)) {
                Riverso_Barcode_Model::accept_legacy_as_supplier($barcode_id, $motivo);
            } else {
                Riverso_Barcode_Model::set_status($barcode_id, 'verificado', $motivo);
            }
        }

        return true;
    }

    /**
     * Aparca la tarea de barcode hasta que exista presentación/hijo.
     * No cierra la tarea.
     *
     * @param int        $barcode_id
     * @param float|null $cantidad_pack
     * @param int        $from_product_id
     * @return true|WP_Error
     */
    public function park_barcode_presentacion($barcode_id, $cantidad_pack = null, $from_product_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $barcode_id = intval($barcode_id);
        $from_product_id = intval($from_product_id);

        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $barcode_id
        ), ARRAY_A);
        if (!$barcode) {
            return new WP_Error('not_found', 'Código de barra no encontrado');
        }

        $product_id = $from_product_id ?: intval($barcode['producto_base_id'] ?? 0);
        $qty = $cantidad_pack !== null ? floatval($cantidad_pack) : null;

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, datos_extra FROM {$prefix}tareas
             WHERE tipo = 'confirmar_barcode_legacy'
               AND referencia_tipo = 'codigo_barra'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            $barcode_id
        ), ARRAY_A) ?: [];

        if (empty($tasks) && class_exists('Riverso_Task_Module')) {
            $codigo = (string) ($barcode['codigo'] ?? '');
            $name = '';
            if ($product_id) {
                $name = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                    $product_id
                ));
            }
            Riverso_Task_Module::get_instance()->create_legacy_barcode_review_task(
                $barcode_id,
                $product_id,
                $codigo,
                $name
            );
            $tasks = $wpdb->get_results($wpdb->prepare(
                "SELECT id, datos_extra FROM {$prefix}tareas
                 WHERE tipo = 'confirmar_barcode_legacy'
                   AND referencia_tipo = 'codigo_barra'
                   AND referencia_id = %d
                   AND estado NOT IN ('completada', 'cancelada')",
                $barcode_id
            ), ARRAY_A) ?: [];
        }

        if (empty($tasks)) {
            return new WP_Error('no_task', 'No hay tarea abierta para aparcar');
        }

        foreach ($tasks as $task) {
            $extra = [];
            if (!empty($task['datos_extra'])) {
                $decoded = is_string($task['datos_extra'])
                    ? json_decode($task['datos_extra'], true)
                    : $task['datos_extra'];
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            $extra['mapeo_accion'] = 'park_presentacion';
            $extra['parked_until_presentacion'] = 1;
            $extra['producto_base_id'] = $product_id;
            $extra['codigo_id'] = $barcode_id;
            if ($qty !== null && $qty > 0) {
                $extra['cantidad_pack'] = $qty;
            }
            unset($extra['destino_producto_base_id']);

            $wpdb->update(
                "{$prefix}tareas",
                [
                    'datos_extra' => wp_json_encode($extra, JSON_UNESCAPED_UNICODE),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $task['id']],
                ['%s', '%s'],
                ['%d']
            );
        }

        return true;
    }

    /**
     * Lista tareas aparcadas de barcodes en la familia que ya tienen destino posible.
     *
     * @param int $grupo_id
     * @return array
     */
    public function list_resumable_parked_barcode_tasks($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        if ($grupo_id <= 0) {
            return [];
        }

        $unit_id = $this->get_unit_base_id($grupo_id);
        $member_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT producto_base_id FROM {$prefix}equivalence_members
             WHERE grupo_id = %d AND activo = 1",
            $grupo_id
        )) ?: [];
        $member_ids = array_map('intval', $member_ids);
        if ($unit_id) {
            $member_ids[] = intval($unit_id);
        }
        $member_ids = array_values(array_unique(array_filter($member_ids)));
        if (empty($member_ids)) {
            return [];
        }

        $pack_map = $this->get_pack_members_by_qty($grupo_id, $unit_id ?: 0);
        $in = implode(',', $member_ids);

        $tasks = $wpdb->get_results(
            "SELECT t.id, t.datos_extra, t.referencia_id, t.titulo, t.estado
             FROM {$prefix}tareas t
             WHERE t.tipo = 'confirmar_barcode_legacy'
               AND t.estado NOT IN ('completada', 'cancelada')
               AND t.datos_extra LIKE '%park_presentacion%'
               AND t.referencia_id IN (
                    SELECT id FROM {$prefix}codigo_barra
                    WHERE producto_base_id IN ({$in})
               )",
            ARRAY_A
        ) ?: [];

        // También tareas aparcadas cuyo datos_extra apunta a un miembro, aunque el barcode se haya movido.
        $extra_tasks = $wpdb->get_results(
            "SELECT t.id, t.datos_extra, t.referencia_id, t.titulo, t.estado
             FROM {$prefix}tareas t
             WHERE t.tipo = 'confirmar_barcode_legacy'
               AND t.estado NOT IN ('completada', 'cancelada')
               AND t.datos_extra LIKE '%park_presentacion%'
               AND t.datos_extra LIKE '%producto_base_id%'",
            ARRAY_A
        ) ?: [];

        $by_id = [];
        foreach (array_merge($tasks, $extra_tasks) as $task) {
            $by_id[intval($task['id'])] = $task;
        }
        $tasks = array_values($by_id);

        $out = [];
        foreach ($tasks as $task) {
            $extra = [];
            if (!empty($task['datos_extra'])) {
                $decoded = is_string($task['datos_extra'])
                    ? json_decode($task['datos_extra'], true)
                    : $task['datos_extra'];
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            if (empty($extra['parked_until_presentacion'])
                && ($extra['mapeo_accion'] ?? '') !== 'park_presentacion'
            ) {
                continue;
            }

            $extra_product = intval($extra['producto_base_id'] ?? 0);
            if ($extra_product && !in_array($extra_product, $member_ids, true)) {
                // Solo si no está referenciada por barcode de la familia.
                $bc_check = intval($extra['codigo_id'] ?? $task['referencia_id'] ?? 0);
                $bc_owner = $bc_check ? intval($wpdb->get_var($wpdb->prepare(
                    "SELECT producto_base_id FROM {$prefix}codigo_barra WHERE id = %d",
                    $bc_check
                ))) : 0;
                if (!$bc_owner || !in_array($bc_owner, $member_ids, true)) {
                    continue;
                }
            }

            $bc_id = intval($extra['codigo_id'] ?? $task['referencia_id'] ?? 0);
            $barcode = $bc_id ? $wpdb->get_row($wpdb->prepare(
                "SELECT id, codigo, cantidad, factor_a_unidad_base, producto_base_id, estado
                 FROM {$prefix}codigo_barra WHERE id = %d",
                $bc_id
            ), ARRAY_A) : null;
            if (!$barcode) {
                continue;
            }

            $qty = floatval($extra['cantidad_pack'] ?? 0);
            if ($qty <= 1.0001) {
                $qty = floatval($barcode['cantidad'] ?? 0);
            }
            if ($qty <= 1.0001) {
                $qty = floatval($barcode['factor_a_unidad_base'] ?? 0);
            }

            $suggested = null;
            if ($qty > 1.0001) {
                $key = $this->qty_key($qty);
                if (isset($pack_map[$key])) {
                    $suggested = $pack_map[$key];
                }
            }

            $out[] = [
                'task_id' => intval($task['id']),
                'barcode_id' => intval($barcode['id']),
                'codigo' => $barcode['codigo'],
                'producto_base_id' => intval($barcode['producto_base_id']),
                'cantidad_pack' => $qty > 0 ? $qty : null,
                'suggested_destino' => $suggested,
                'can_resume' => $suggested !== null,
            ];
        }

        return $out;
    }

    /**
     * Anota destino sugerido en tareas aparcadas cuando aparece un pack coincidente.
     * No mueve ni cierra: el humano confirma.
     *
     * @param int $grupo_id
     * @return int cantidad anotadas
     */
    public function annotate_parked_barcode_tasks_for_family($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $resumable = $this->list_resumable_parked_barcode_tasks($grupo_id);
        $n = 0;
        foreach ($resumable as $row) {
            if (empty($row['can_resume']) || empty($row['suggested_destino'])) {
                continue;
            }
            $task_id = intval($row['task_id']);
            $extra = $this->get_task_datos_extra($task_id);
            $dest_id = intval($row['suggested_destino']['producto_base_id'] ?? 0);
            if (!$task_id || !$dest_id) {
                continue;
            }
            $extra['mapeo_accion'] = 'park_presentacion';
            $extra['parked_until_presentacion'] = 1;
            $extra['destino_producto_base_id'] = $dest_id;
            $extra['cantidad_pack'] = floatval($row['cantidad_pack'] ?? $row['suggested_destino']['cantidad_unidades'] ?? 0);
            $extra['suggested_label'] = $this->member_label($row['suggested_destino']);
            $wpdb->update(
                "{$prefix}tareas",
                [
                    'datos_extra' => wp_json_encode($extra, JSON_UNESCAPED_UNICODE),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $task_id],
                ['%s', '%s'],
                ['%d']
            );
            $n++;
        }
        return $n;
    }

    /**
     * Contexto de familia para remapeo (incluye familias sin unitario aún).
     *
     * @param int $producto_base_id
     * @return array
     */
    public function resolve_family_context_for_remap($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);

        $unit_ctx = $this->resolve_family_unit_for_base($producto_base_id);
        if ($unit_ctx) {
            $unit_pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                intval($unit_ctx['unit_producto_base_id'])
            ), ARRAY_A);
            return [
                'grupo_id' => intval($unit_ctx['grupo_id']),
                'unit_producto_base_id' => intval($unit_ctx['unit_producto_base_id']),
                'es_producto_unitario' => 1,
                'unitario' => $unit_pb ? [
                    'id' => intval($unit_pb['id']),
                    'canonical_sku' => $unit_pb['canonical_sku'],
                    'nombre_canonico' => $unit_pb['nombre_canonico'],
                ] : null,
            ];
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT g.id AS grupo_id, g.es_producto_unitario, g.unit_producto_base_id, g.nombre
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND g.activo = 1
             ORDER BY (g.tipo_sustitucion = 'exacta') DESC, g.id ASC
             LIMIT 1",
            $producto_base_id
        ), ARRAY_A);

        if (!$row) {
            $as_unit = $wpdb->get_row($wpdb->prepare(
                "SELECT id AS grupo_id, es_producto_unitario, unit_producto_base_id, nombre
                 FROM {$prefix}equivalence_groups
                 WHERE unit_producto_base_id = %d AND activo = 1
                 LIMIT 1",
                $producto_base_id
            ), ARRAY_A);
            if ($as_unit) {
                $row = $as_unit;
            }
        }

        if (!$row) {
            return [
                'grupo_id' => null,
                'unit_producto_base_id' => null,
                'es_producto_unitario' => 0,
                'unitario' => null,
            ];
        }

        $unit_id = !empty($row['unit_producto_base_id']) ? intval($row['unit_producto_base_id']) : null;
        $unitario = null;
        if ($unit_id) {
            $unit_pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                $unit_id
            ), ARRAY_A);
            if ($unit_pb) {
                $unitario = [
                    'id' => intval($unit_pb['id']),
                    'canonical_sku' => $unit_pb['canonical_sku'],
                    'nombre_canonico' => $unit_pb['nombre_canonico'],
                ];
            }
        }

        return [
            'grupo_id' => intval($row['grupo_id']),
            'unit_producto_base_id' => $unit_id,
            'es_producto_unitario' => (int) ($row['es_producto_unitario'] ?? 0),
            'nombre' => $row['nombre'] ?? '',
            'unitario' => $unitario,
        ];
    }

    private function get_task_datos_extra($task_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT datos_extra FROM {$prefix}tareas WHERE id = %d",
            intval($task_id)
        ));
        if (!$raw) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    private function suggest_inherit_action($qty, array $pack_map, array $base) {
        $qty = floatval($qty);
        if ($qty <= 1.0001) {
            return array_merge($base, [
                'action' => 'keep_unit',
                'suggested_producto_base_id' => null,
                'suggested_label' => 'Se queda en el producto unitario',
            ]);
        }
        $key = $this->qty_key($qty);
        if (isset($pack_map[$key])) {
            return array_merge($base, [
                'action' => 'inherit',
                'suggested_producto_base_id' => intval($pack_map[$key]['producto_base_id']),
                'suggested_label' => $this->member_label($pack_map[$key]),
            ]);
        }
        return array_merge($base, [
            'action' => 'unresolved',
            'suggested_producto_base_id' => null,
            'suggested_label' => 'Sin miembro con envase de ' . rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') . ' u',
        ]);
    }

    private function qty_key($qty) {
        return number_format(round(floatval($qty), 4), 4, '.', '');
    }

    private function member_label(array $member) {
        $sku = $member['canonical_sku'] ?? '';
        $name = $member['nombre_canonico'] ?? '';
        $qty = isset($member['cantidad_unidades'])
            ? rtrim(rtrim(number_format(floatval($member['cantidad_unidades']), 4, '.', ''), '0'), '.')
            : '';
        $bits = [];
        if ($sku !== '' && $sku !== null) {
            $bits[] = 'SKU ' . $sku;
        } else {
            $bits[] = '#' . intval($member['producto_base_id']);
        }
        if ($qty !== '') {
            $bits[] = $qty . ' u';
        }
        if ($name) {
            $bits[] = $name;
        }
        return implode(' · ', $bits);
    }

    private function find_open_barcode_task_ids($barcode_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $barcode_id = intval($barcode_id);
        if (!$barcode_id) {
            return [];
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}tareas
             WHERE tipo = 'confirmar_barcode_legacy'
               AND referencia_tipo = 'codigo_barra'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            $barcode_id
        )) ?: []);
    }

    private function find_open_supplier_task_ids($pp_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = intval($pp_id);
        if (!$pp_id) {
            return [];
        }
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}tareas
             WHERE tipo = 'confirmar_codigo_proveedor'
               AND referencia_tipo = 'producto_proveedor'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            $pp_id
        )) ?: []);
    }

    private function reassign_supplier_code_tasks($pp_id, $new_product_id) {
        global $wpdb;
        $pp_id = intval($pp_id);
        $new_product_id = intval($new_product_id);
        if (!$pp_id || !$new_product_id) {
            return;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, datos_extra FROM {$prefix}tareas
             WHERE tipo = 'confirmar_codigo_proveedor'
               AND referencia_tipo = 'producto_proveedor'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            $pp_id
        ), ARRAY_A) ?: [];

        foreach ($tasks as $task) {
            $extra = [];
            if (!empty($task['datos_extra'])) {
                $decoded = is_string($task['datos_extra'])
                    ? json_decode($task['datos_extra'], true)
                    : $task['datos_extra'];
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            $extra['producto_base_id'] = $new_product_id;
            $wpdb->update(
                "{$prefix}tareas",
                [
                    'datos_extra' => wp_json_encode($extra, JSON_UNESCAPED_UNICODE),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $task['id']],
                ['%s', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Reasigna tareas confirmar_barcode_legacy al nuevo producto_base.
     *
     * @param int   $barcode_id
     * @param int   $new_product_id
     * @param array $extra_patch Campos a fusionar en datos_extra
     */
    public function reassign_barcode_tasks($barcode_id, $new_product_id, array $extra_patch = []) {
        global $wpdb;
        $barcode_id = intval($barcode_id);
        $new_product_id = intval($new_product_id);
        if (!$barcode_id || !$new_product_id) {
            return;
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, datos_extra FROM {$prefix}tareas
             WHERE tipo = 'confirmar_barcode_legacy'
               AND referencia_tipo = 'codigo_barra'
               AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            $barcode_id
        ), ARRAY_A) ?: [];

        foreach ($tasks as $task) {
            $extra = [];
            if (!empty($task['datos_extra'])) {
                $decoded = is_string($task['datos_extra'])
                    ? json_decode($task['datos_extra'], true)
                    : $task['datos_extra'];
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            $extra['producto_base_id'] = $new_product_id;
            foreach ($extra_patch as $k => $v) {
                $extra[$k] = $v;
            }
            $wpdb->update(
                "{$prefix}tareas",
                [
                    'datos_extra' => wp_json_encode($extra, JSON_UNESCAPED_UNICODE),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $task['id']],
                ['%s', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Activa/desactiva producto unitario en la familia.
     *
     * @param int  $grupo_id
     * @param bool $enabled
     * @return true|WP_Error
     */
    public function toggle_unit_product($grupo_id, $enabled) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $updated = $wpdb->update(
            "{$prefix}equivalence_groups",
            ['es_producto_unitario' => $enabled ? 1 : 0],
            ['id' => intval($grupo_id)],
            ['%d'],
            ['%d']
        );
        if ($updated === false) {
            return new WP_Error('db_error', 'No se pudo actualizar la familia');
        }
        if ($enabled) {
            $this->ensure_missing_rule_task($grupo_id);
        } else {
            $this->cancel_missing_rule_tasks($grupo_id, 'Producto unitario desactivado');
        }
        return true;
    }

    /**
     * Asigna regla R-1 a la familia (requiere confirmación explícita del caller).
     *
     * @param int $grupo_id
     * @param int $rule_id
     * @return true|WP_Error
     */
    public function assign_default_rule($grupo_id, $rule_id = 0) {
        if (!class_exists('Riverso_Price_Rules_Module')) {
            return new WP_Error('no_rules', 'Módulo de reglas no disponible');
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!$rule_id) {
            $rule_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}price_rules WHERE codigo = %s AND estado = 'aprobada' ORDER BY version DESC LIMIT 1",
                'R-1'
            ));
        }
        if (!$rule_id) {
            return new WP_Error('no_r1', 'Regla R-1 no encontrada');
        }

        $result = Riverso_Price_Rules_Module::get_instance()->assign_rule($rule_id, 'familia', intval($grupo_id));
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }

    /**
     * Crea tarea de revisión si la familia unitaria no tiene regla de precio.
     *
     * @param int $grupo_id
     * @return int|null|WP_Error ID de tarea o null si no aplica
     */
    public function ensure_missing_rule_task($grupo_id) {
        $grupo_id = intval($grupo_id);
        if (!$grupo_id) {
            return null;
        }

        $family = $this->get_family_row($grupo_id);
        if (!$family || empty($family['es_producto_unitario'])) {
            $this->cancel_missing_rule_tasks($grupo_id, 'La familia ya no es producto unitario');
            return null;
        }

        if (class_exists('Riverso_Price_Rules_Module')) {
            $assigned = Riverso_Price_Rules_Module::get_instance()->get_assigned_rule_id('familia', $grupo_id);
            if ($assigned) {
                Riverso_Price_Rules_Module::get_instance()->complete_missing_rule_tasks_for_familia($grupo_id);
                return null;
            }
        }

        if (!function_exists('riverso_create_review_task')) {
            return null;
        }

        $unit_id = intval($family['unit_producto_base_id'] ?? 0);
        $sku = '';
        if ($unit_id) {
            global $wpdb;
            $sku = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT canonical_sku FROM {$wpdb->prefix}riverso_producto_base WHERE id = %d",
                $unit_id
            ));
        }

        $nombre = $family['nombre'] ?: ('Familia #' . $grupo_id);
        return riverso_create_review_task(
            'asignar_regla_precio',
            'Asignar regla de precio a familia «' . $nombre . '»',
            'familia',
            $grupo_id,
            [
                'prioridad' => 'normal',
                'descripcion' => 'La familia unitaria quedó sin regla de precio. Asigna una regla en Reglas de Precio.',
                'datos_extra' => [
                    'grupo_id' => $grupo_id,
                    'unit_producto_base_id' => $unit_id ?: null,
                    'canonical_sku' => $sku,
                ],
            ]
        );
    }

    /**
     * Cancela tareas abiertas asignar_regla_precio de una familia.
     *
     * @param int    $grupo_id
     * @param string $motivo
     */
    public function cancel_missing_rule_tasks($grupo_id, $motivo = '') {
        if (class_exists('Riverso_Price_Rules_Module')) {
            Riverso_Price_Rules_Module::get_instance()->cancel_missing_rule_tasks_for_familia(
                intval($grupo_id),
                $motivo !== '' ? $motivo : 'Familia eliminada o ya no es unitaria'
            );
        }
    }

    /**
     * Preview de precios por integrante con regla de familia.
     *
     * @param int        $grupo_id
     * @param float|null $p_asignado_override
     * @return array|WP_Error
     */
    public function preview_member_prices($grupo_id, $p_asignado_override = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $snapshot = $this->get_unit_snapshot($grupo_id);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $p_asignado = $p_asignado_override;
        if ($p_asignado === null && !empty($snapshot['precio']['p_asignado'])) {
            $p_asignado = (float) $snapshot['precio']['p_asignado'];
        }
        if ($p_asignado === null) {
            return new WP_Error('no_price', 'Indica el precio unitario (P) para previsualizar');
        }

        $rules_mod = class_exists('Riverso_Price_Rules_Module')
            ? Riverso_Price_Rules_Module::get_instance()
            : null;

        $family_rule_id = $rules_mod
            ? $rules_mod->get_assigned_rule_id('familia', intval($grupo_id))
            : null;

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1",
            intval($grupo_id)
        ), ARRAY_A) ?: [];

        $rows = [];
        foreach ($members as $m) {
            $base_id = intval($m['producto_base_id']);
            $envase = $this->get_canonical_envase($base_id);
            $qty = $envase ? floatval($envase['cantidad_unidades']) : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }

            $product_rule_id = $rules_mod ? $rules_mod->get_assigned_rule_id('producto', $base_id) : null;
            $shadowed = (bool) $product_rule_id;

            $unit_price = null;
            if ($rules_mod && $family_rule_id && !$shadowed) {
                $unit_price = $rules_mod->apply_for_base($base_id, $qty, $p_asignado);
            } elseif ($rules_mod && $product_rule_id) {
                $unit_price = $rules_mod->apply_for_base($base_id, $qty, null);
            }

            $coste_u = null;
            foreach ($snapshot['coste_breakdown'] as $bd) {
                if ((int) $bd['producto_base_id'] === $base_id) {
                    $coste_u = $bd['coste_unitario'];
                    break;
                }
            }

            $sku_flags = $this->member_sku_flags($m);
            $rows[] = array_merge([
                'producto_base_id' => $base_id,
                'canonical_sku' => $m['canonical_sku'],
                'nombre_canonico' => $m['nombre_canonico'],
                'es_unidad_minima' => (int) ($m['es_unidad_minima'] ?? 0),
                'cantidad_unidades' => $qty,
                'precio_unitario_regla' => $unit_price,
                'precio_total_presentacion' => $unit_price !== null ? round($unit_price * $qty, 2) : null,
                'coste_unitario' => $coste_u,
                'margen' => ($unit_price !== null && $coste_u) ? round($unit_price - $coste_u, 2) : null,
                'regla_sombreada' => $shadowed,
                'regla_producto_id' => $product_rule_id ? intval($product_rule_id) : null,
                'woocommerce_product_id' => absint($m['woocommerce_product_id'] ?? 0),
                'woocommerce_variation_id' => absint($m['woocommerce_variation_id'] ?? 0),
            ], $sku_flags);
        }

        return [
            'p_asignado' => $p_asignado,
            'family_rule_id' => $family_rule_id ? intval($family_rule_id) : null,
            'members' => $rows,
        ];
    }

    /**
     * Escala simple de regla para familia unitaria (pasos por qty real + tramos).
     *
     * @param int        $grupo_id
     * @param float|null $p_asignado
     * @param array|null $preview Resultado de preview_member_prices
     * @param array|null $rule    get_rule_with_tiers
     * @return array|null
     */
    public function build_family_rule_visual($grupo_id, $p_asignado = null, $preview = null, $rule = null) {
        $grupo_id = intval($grupo_id);
        $snapshot = $this->get_unit_snapshot($grupo_id);
        if (is_wp_error($snapshot) || empty($snapshot['es_producto_unitario'])) {
            return null;
        }

        if (!is_array($preview)) {
            $preview = $this->preview_member_prices($grupo_id, $p_asignado);
            if (is_wp_error($preview)) {
                $preview = ['error' => $preview->get_error_message(), 'members' => [], 'p_asignado' => $p_asignado];
            }
        }

        $p = isset($preview['p_asignado']) ? (float) $preview['p_asignado'] : ($p_asignado !== null ? (float) $p_asignado : null);
        $unit = $snapshot['unit'] ?? null;
        $unit_id = (int) ($snapshot['unit_producto_base_id'] ?? 0);

        if ($rule === null && class_exists('Riverso_Price_Rules_Module')) {
            $rules = Riverso_Price_Rules_Module::get_instance();
            $rule_id = $rules->get_assigned_rule_id('familia', $grupo_id);
            $rule = $rule_id ? $rules->get_rule_with_tiers($rule_id) : null;
        }

        $steps = [];
        $members = is_array($preview['members'] ?? null) ? $preview['members'] : [];
        if (!$members) {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima,
                        pb.woocommerce_product_id, pb.woocommerce_variation_id
                 FROM {$prefix}equivalence_members em
                 INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
                 WHERE em.grupo_id = %d AND em.activo = 1",
                $grupo_id
            ), ARRAY_A) ?: [];
            foreach ($members as &$mrow) {
                $env = $this->get_canonical_envase((int) $mrow['producto_base_id']);
                $mrow['cantidad_unidades'] = $env ? floatval($env['cantidad_unidades']) : 1.0;
                $mrow['precio_unitario_regla'] = null;
                $mrow['precio_total_presentacion'] = null;
                $mrow = array_merge($mrow, $this->member_sku_flags($mrow));
            }
            unset($mrow);
        }
        usort($members, static function ($a, $b) {
            $qa = (float) ($a['cantidad_unidades'] ?? 1);
            $qb = (float) ($b['cantidad_unidades'] ?? 1);
            if ($qa === $qb) {
                return strcmp((string) ($a['canonical_sku'] ?? ''), (string) ($b['canonical_sku'] ?? ''));
            }
            return $qa <=> $qb;
        });
        foreach ($members as $m) {
            $qty = (float) ($m['cantidad_unidades'] ?? 1);
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $is_unit = $unit_id > 0
                ? ((int) ($m['producto_base_id'] ?? 0) === $unit_id)
                : ((int) ($m['es_unidad_minima'] ?? 0) === 1 || $qty <= 1.0001);
            $pu = isset($m['precio_unitario_regla']) ? $m['precio_unitario_regla'] : null;
            if ($pu === null && $p !== null && class_exists('Riverso_Price_Rules_Module') && $rule) {
                $pu = Riverso_Price_Rules_Module::get_instance()->apply_for_base(
                    (int) $m['producto_base_id'],
                    $qty,
                    $p
                );
            }
            $steps[] = [
                'producto_base_id' => (int) ($m['producto_base_id'] ?? 0),
                'canonical_sku' => (string) ($m['canonical_sku'] ?? ''),
                'sku_local' => (string) ($m['sku_local'] ?? $m['canonical_sku'] ?? ''),
                'sku_online' => (string) ($m['sku_online'] ?? ''),
                'es_local' => !empty($m['es_local']) || trim((string) ($m['canonical_sku'] ?? '')) !== '',
                'es_online' => !empty($m['es_online']),
                'nombre_canonico' => (string) ($m['nombre_canonico'] ?? ''),
                'cantidad_unidades' => $qty,
                'precio_unitario_regla' => $pu !== null ? (float) $pu : null,
                'precio_total_presentacion' => $pu !== null ? round((float) $pu * $qty, 2) : null,
                'es_unitario' => $is_unit,
            ];
        }

        $tiers_out = [];
        $tiers = is_array($rule['tiers'] ?? null) ? $rule['tiers'] : [];
        $engine_ok = class_exists('Riverso_Price_Rule_Engine');
        foreach ($tiers as $t) {
            $desde = isset($t['cantidad_desde']) ? (float) $t['cantidad_desde'] : (isset($t['desde']) ? (float) $t['desde'] : 1.0);
            if ($desde <= 0) {
                $desde = 1.0;
            }
            $hasta = null;
            if (isset($t['cantidad_hasta']) && $t['cantidad_hasta'] !== '' && $t['cantidad_hasta'] !== null) {
                $hasta = (float) $t['cantidad_hasta'];
            } elseif (isset($t['hasta']) && $t['hasta'] !== '' && $t['hasta'] !== null) {
                $hasta = (float) $t['hasta'];
            }
            $formula = (string) ($t['formula'] ?? '');
            $pu = null;
            if ($p !== null && $engine_ok && $tiers) {
                $pu = Riverso_Price_Rule_Engine::evaluate($tiers, $p, $desde);
            }
            $tiers_out[] = [
                'desde' => $desde,
                'hasta' => $hasta,
                'formula' => $formula,
                'precio_unitario' => $pu !== null ? (float) $pu : null,
            ];
        }

        return [
            'es_producto_unitario' => 1,
            'grupo_id' => $grupo_id,
            'p_asignado' => $p,
            'unit' => $unit ? [
                'producto_base_id' => $unit_id,
                'canonical_sku' => (string) ($unit['canonical_sku'] ?? ''),
                'nombre_canonico' => (string) ($unit['nombre_canonico'] ?? ''),
                'cantidad_unidades' => 1.0,
            ] : null,
            'rule' => $rule ? [
                'id' => (int) ($rule['id'] ?? 0),
                'codigo' => (string) ($rule['codigo'] ?? ''),
                'nombre' => (string) ($rule['nombre'] ?? ''),
            ] : null,
            'steps' => $steps,
            'tiers' => $tiers_out,
            'error' => isset($preview['error']) ? (string) $preview['error'] : null,
        ];
    }

    /**
     * Códigos de barra y proveedor activos por integrante de la familia.
     *
     * @param int $grupo_id
     * @return array{members:array,unit_producto_base_id:?int}
     */
    public function get_family_member_codes($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $unit_id = $this->get_unit_base_id($grupo_id);
        $pack_map = $this->get_pack_members_by_qty($grupo_id, 0);

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id AND pb.deleted_at IS NULL
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY pb.es_unidad_minima DESC, pb.canonical_sku ASC",
            $grupo_id
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($members as $m) {
            $base_id = (int) $m['producto_base_id'];
            $envase = $this->get_canonical_envase($base_id);
            $qty = $envase ? floatval($envase['cantidad_unidades']) : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $is_unit = $unit_id > 0 ? ($base_id === $unit_id) : ((int) ($m['es_unidad_minima'] ?? 0) === 1);
            $sku_flags = $this->member_sku_flags($m);

            $barcodes = $wpdb->get_results($wpdb->prepare(
                "SELECT id, codigo, cantidad, factor_a_unidad_base, estado, tipo, activo
                 FROM {$prefix}codigo_barra
                 WHERE producto_base_id = %d AND activo = 1
                   AND (estado IS NULL OR estado NOT IN ('en_desuso','rechazado'))
                 ORDER BY id ASC",
                $base_id
            ), ARRAY_A) ?: [];

            $codes_bc = [];
            foreach ($barcodes as $bc) {
                $bc_qty = floatval($bc['cantidad'] ?? 0);
                if ($bc_qty <= 0) {
                    $bc_qty = floatval($bc['factor_a_unidad_base'] ?? 0);
                }
                if ($bc_qty <= 0) {
                    $bc_qty = $qty;
                }
                $suggested = null;
                $qty_key = $this->qty_key($bc_qty);
                if ($is_unit && $bc_qty > 1.0001 && isset($pack_map[$qty_key])) {
                    $suggested = $pack_map[$qty_key];
                }
                if ($is_unit && !$suggested) {
                    $elsewhere = $wpdb->get_row($wpdb->prepare(
                        "SELECT pb.id AS producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                                e.cantidad_unidades, e.id AS envase_id
                         FROM {$prefix}codigo_barra cb2
                         INNER JOIN {$prefix}producto_base pb ON pb.id = cb2.producto_base_id AND pb.deleted_at IS NULL
                         INNER JOIN {$prefix}equivalence_members em
                            ON em.producto_base_id = pb.id AND em.grupo_id = %d AND em.activo = 1
                         LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
                         WHERE cb2.codigo = %s AND cb2.activo = 1 AND cb2.producto_base_id <> %d
                         ORDER BY (e.cantidad_unidades > 1) DESC LIMIT 1",
                        $grupo_id,
                        (string) $bc['codigo'],
                        $base_id
                    ), ARRAY_A);
                    if ($elsewhere && floatval($elsewhere['cantidad_unidades'] ?? 0) > 1) {
                        $suggested = [
                            'producto_base_id' => (int) $elsewhere['producto_base_id'],
                            'canonical_sku' => $elsewhere['canonical_sku'],
                            'nombre_canonico' => $elsewhere['nombre_canonico'],
                            'cantidad_unidades' => floatval($elsewhere['cantidad_unidades']),
                            'envase_id' => !empty($elsewhere['envase_id']) ? (int) $elsewhere['envase_id'] : null,
                        ];
                    }
                }
                $codes_bc[] = [
                    'id' => (int) $bc['id'],
                    'tipo' => 'barcode',
                    'codigo' => (string) ($bc['codigo'] ?? ''),
                    'cantidad' => $bc_qty,
                    'estado' => (string) ($bc['estado'] ?? ''),
                    'suggested_destino' => $suggested,
                    'mismatch' => (bool) $suggested,
                ];
            }

            $pps = $wpdb->get_results($wpdb->prepare(
                "SELECT id, codigo_proveedor, factor_conversion, proveedor_id, activo
                 FROM {$prefix}producto_proveedor
                 WHERE producto_base_id = %d AND activo = 1
                 ORDER BY id ASC",
                $base_id
            ), ARRAY_A) ?: [];

            $codes_pp = [];
            foreach ($pps as $pp) {
                $pp_qty = floatval($pp['factor_conversion'] ?? 0);
                if ($pp_qty <= 0) {
                    $pp_qty = $qty;
                }
                // Si el factor es 1 pero el código parece de caja, intentar qty del barcode homónimo.
                if ($is_unit && $pp_qty <= 1.0001) {
                    $homonym = $wpdb->get_row($wpdb->prepare(
                        "SELECT cantidad, factor_a_unidad_base FROM {$prefix}codigo_barra
                         WHERE codigo = %s AND producto_base_id = %d AND activo = 1
                         ORDER BY id ASC LIMIT 1",
                        (string) $pp['codigo_proveedor'],
                        $base_id
                    ), ARRAY_A);
                    if ($homonym) {
                        $hq = floatval($homonym['cantidad'] ?? 0);
                        if ($hq <= 0) {
                            $hq = floatval($homonym['factor_a_unidad_base'] ?? 0);
                        }
                        if ($hq > 1.0001) {
                            $pp_qty = $hq;
                        }
                    }
                }
                $suggested = null;
                $qty_key = $this->qty_key($pp_qty);
                if ($is_unit && $pp_qty > 1.0001 && isset($pack_map[$qty_key])) {
                    $suggested = $pack_map[$qty_key];
                }
                // Mismo código ya en otro integrante → sugerir ese destino.
                if ($is_unit && !$suggested) {
                    $elsewhere = $wpdb->get_row($wpdb->prepare(
                        "SELECT pb.id AS producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                                e.cantidad_unidades, e.id AS envase_id
                         FROM {$prefix}producto_proveedor pp2
                         INNER JOIN {$prefix}producto_base pb ON pb.id = pp2.producto_base_id AND pb.deleted_at IS NULL
                         INNER JOIN {$prefix}equivalence_members em
                            ON em.producto_base_id = pb.id AND em.grupo_id = %d AND em.activo = 1
                         LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
                         WHERE pp2.codigo_proveedor = %s AND pp2.activo = 1 AND pp2.producto_base_id <> %d
                         ORDER BY (e.cantidad_unidades > 1) DESC LIMIT 1",
                        $grupo_id,
                        (string) $pp['codigo_proveedor'],
                        $base_id
                    ), ARRAY_A);
                    if (!$elsewhere) {
                        $elsewhere = $wpdb->get_row($wpdb->prepare(
                            "SELECT pb.id AS producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                                    e.cantidad_unidades, e.id AS envase_id
                             FROM {$prefix}codigo_barra cb
                             INNER JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id AND pb.deleted_at IS NULL
                             INNER JOIN {$prefix}equivalence_members em
                                ON em.producto_base_id = pb.id AND em.grupo_id = %d AND em.activo = 1
                             LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
                             WHERE cb.codigo = %s AND cb.activo = 1 AND cb.producto_base_id <> %d
                             ORDER BY (e.cantidad_unidades > 1) DESC LIMIT 1",
                            $grupo_id,
                            (string) $pp['codigo_proveedor'],
                            $base_id
                        ), ARRAY_A);
                    }
                    if ($elsewhere && floatval($elsewhere['cantidad_unidades'] ?? 0) > 1) {
                        $suggested = [
                            'producto_base_id' => (int) $elsewhere['producto_base_id'],
                            'canonical_sku' => $elsewhere['canonical_sku'],
                            'nombre_canonico' => $elsewhere['nombre_canonico'],
                            'cantidad_unidades' => floatval($elsewhere['cantidad_unidades']),
                            'envase_id' => !empty($elsewhere['envase_id']) ? (int) $elsewhere['envase_id'] : null,
                        ];
                    }
                }
                $codes_pp[] = [
                    'id' => (int) $pp['id'],
                    'tipo' => 'supplier',
                    'codigo' => (string) ($pp['codigo_proveedor'] ?? ''),
                    'cantidad' => $pp_qty,
                    'proveedor_id' => (int) ($pp['proveedor_id'] ?? 0),
                    'suggested_destino' => $suggested,
                    'mismatch' => (bool) $suggested,
                ];
            }

            $out[] = array_merge([
                'producto_base_id' => $base_id,
                'canonical_sku' => (string) ($m['canonical_sku'] ?? ''),
                'nombre_canonico' => (string) ($m['nombre_canonico'] ?? ''),
                'cantidad_unidades' => $qty,
                'es_unitario' => $is_unit,
                'es_unitario_familia' => $is_unit,
                'woocommerce_product_id' => absint($m['woocommerce_product_id'] ?? 0),
                'woocommerce_variation_id' => absint($m['woocommerce_variation_id'] ?? 0),
                'barcodes' => $codes_bc,
                'supplier_codes' => $codes_pp,
            ], $sku_flags);
        }

        $pack_conflicts = [];
        if (class_exists('Riverso_Family_Module')) {
            $fam = Riverso_Family_Module::get_instance();
            if (method_exists($fam, 'detect_pack_qty_conflicts')) {
                $pack_conflicts = $fam->detect_pack_qty_conflicts($grupo_id, $out);
            }
        }

        return [
            'unit_producto_base_id' => $unit_id ?: null,
            'members' => $out,
            'pack_conflicts' => $pack_conflicts,
            'destinos' => array_map(static function ($m) {
                return [
                    'producto_base_id' => (int) $m['producto_base_id'],
                    'canonical_sku' => (string) $m['canonical_sku'],
                    'sku_local' => (string) ($m['sku_local'] ?? ''),
                    'sku_online' => (string) ($m['sku_online'] ?? ''),
                    'es_local' => !empty($m['es_local']),
                    'es_online' => !empty($m['es_online']),
                    'nombre_canonico' => (string) $m['nombre_canonico'],
                    'cantidad_unidades' => (float) $m['cantidad_unidades'],
                    'es_unitario' => !empty($m['es_unitario']),
                ];
            }, $out),
        ];
    }

    /**
     * SKUs a mostrar en columna folio: unitario (qty) · envase (qty).
     *
     * @param int    $producto_base_id Producto resuelto de la línea
     * @param string $codigo_proveedor
     * @param int    $grupo_id
     * @return array
     */
    public function resolve_folio_sku_pair($producto_base_id, $codigo_proveedor = '', $grupo_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);
        $codigo_proveedor = trim((string) $codigo_proveedor);
        $grupo_id = intval($grupo_id);

        $ctx = $grupo_id
            ? null
            : $this->resolve_family_unit_for_base($producto_base_id);
        if ($ctx) {
            $grupo_id = (int) ($ctx['grupo_id'] ?? 0);
        }
        $unit_id = $grupo_id ? $this->get_unit_base_id($grupo_id) : 0;
        if (!$grupo_id || !$unit_id) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT canonical_sku FROM {$prefix}producto_base WHERE id = %d",
                $producto_base_id
            ), ARRAY_A);
            $env = $this->get_canonical_envase($producto_base_id);
            $qty = $env ? floatval($env['cantidad_unidades']) : 1.0;
            return [
                'unit_sku' => (string) ($pb['canonical_sku'] ?? ''),
                'unit_qty' => $qty > 0 ? $qty : 1.0,
                'pack_sku' => null,
                'pack_qty' => null,
                'pack_producto_base_id' => null,
            ];
        }

        $unit_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku FROM {$prefix}producto_base WHERE id = %d",
            $unit_id
        ), ARRAY_A);
        $unit_env = $this->get_canonical_envase($unit_id);
        $unit_qty = $unit_env ? floatval($unit_env['cantidad_unidades']) : 1.0;
        if ($unit_qty <= 0) {
            $unit_qty = 1.0;
        }

        $pack_id = null;
        $pack_sku = null;
        $pack_qty = null;

        if ($producto_base_id !== $unit_id) {
            $pack_id = $producto_base_id;
        } elseif ($codigo_proveedor !== '') {
            $code_qty = $this->resolve_code_qty_hint($codigo_proveedor, $producto_base_id);
            if ($code_qty > 1.0001) {
                $pack_map = $this->get_pack_members_by_qty($grupo_id, $unit_id);
                $key = $this->qty_key($code_qty);
                if (isset($pack_map[$key])) {
                    $pack_id = (int) $pack_map[$key]['producto_base_id'];
                }
            }
            // Mismo código ya vive en otro integrante (p. ej. 02TADB en 148 y también en 247570).
            if (!$pack_id) {
                $other = $wpdb->get_row($wpdb->prepare(
                    "SELECT pb.id, e.cantidad_unidades
                     FROM {$prefix}codigo_barra cb
                     INNER JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id AND pb.deleted_at IS NULL
                     INNER JOIN {$prefix}equivalence_members em
                        ON em.producto_base_id = pb.id AND em.grupo_id = %d AND em.activo = 1
                     LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
                     WHERE cb.codigo = %s AND cb.activo = 1 AND cb.producto_base_id <> %d
                     ORDER BY (e.cantidad_unidades > 1) DESC, e.cantidad_unidades DESC
                     LIMIT 1",
                    $grupo_id,
                    $codigo_proveedor,
                    $unit_id
                ), ARRAY_A);
                if (!$other) {
                    $other = $wpdb->get_row($wpdb->prepare(
                        "SELECT pb.id, e.cantidad_unidades
                         FROM {$prefix}producto_proveedor pp
                         INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                         INNER JOIN {$prefix}equivalence_members em
                            ON em.producto_base_id = pb.id AND em.grupo_id = %d AND em.activo = 1
                         LEFT JOIN {$prefix}envases e ON e.producto_base_id = pb.id AND e.activo = 1
                         WHERE pp.codigo_proveedor = %s AND pp.activo = 1 AND pp.producto_base_id <> %d
                         ORDER BY (e.cantidad_unidades > 1) DESC, e.cantidad_unidades DESC
                         LIMIT 1",
                        $grupo_id,
                        $codigo_proveedor,
                        $unit_id
                    ), ARRAY_A);
                }
                if ($other) {
                    $pack_id = (int) $other['id'];
                }
            }
        }

        if ($pack_id) {
            $pack_row = $wpdb->get_row($wpdb->prepare(
                "SELECT canonical_sku FROM {$prefix}producto_base WHERE id = %d",
                $pack_id
            ), ARRAY_A);
            $pack_env = $this->get_canonical_envase($pack_id);
            $pack_qty = $pack_env ? floatval($pack_env['cantidad_unidades']) : null;
            $pack_sku = (string) ($pack_row['canonical_sku'] ?? '');
        }

        return [
            'unit_sku' => (string) ($unit_row['canonical_sku'] ?? ''),
            'unit_qty' => $unit_qty,
            'unit_producto_base_id' => $unit_id,
            'pack_sku' => $pack_sku ?: null,
            'pack_qty' => $pack_qty,
            'pack_producto_base_id' => $pack_id,
        ];
    }

    /**
     * Cantidad asociada a un código (barcode o factor proveedor).
     *
     * @param string $codigo
     * @param int    $producto_base_id
     * @return float
     */
    public function resolve_code_qty_hint($codigo, $producto_base_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $codigo = trim((string) $codigo);
        $producto_base_id = intval($producto_base_id);
        if ($codigo === '') {
            return 1.0;
        }

        // Preferir cantidad > 1 aunque el código esté mal asignado al unitario.
        $bc = $wpdb->get_row($wpdb->prepare(
            "SELECT cantidad, factor_a_unidad_base FROM {$prefix}codigo_barra
             WHERE codigo = %s AND activo = 1
             ORDER BY (cantidad > 1) DESC, (factor_a_unidad_base > 1) DESC,
                      (producto_base_id = %d) DESC, id ASC
             LIMIT 1",
            $codigo,
            $producto_base_id > 0 ? $producto_base_id : 0
        ), ARRAY_A);
        if ($bc) {
            $q = floatval($bc['cantidad'] ?? 0);
            if ($q <= 0) {
                $q = floatval($bc['factor_a_unidad_base'] ?? 0);
            }
            if ($q > 0) {
                return $q;
            }
        }

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT factor_conversion FROM {$prefix}producto_proveedor
             WHERE codigo_proveedor = %s AND activo = 1
             ORDER BY (factor_conversion > 1) DESC, (producto_base_id = %d) DESC, id ASC
             LIMIT 1",
            $codigo,
            $producto_base_id > 0 ? $producto_base_id : 0
        ), ARRAY_A);
        if ($pp) {
            $q = floatval($pp['factor_conversion'] ?? 0);
            if ($q > 0) {
                return $q;
            }
        }

        $env = $wpdb->get_row($wpdb->prepare(
            "SELECT cantidad_unidades FROM {$prefix}envases
             WHERE codigo_proveedor = %s AND activo = 1
             ORDER BY cantidad_unidades DESC LIMIT 1",
            $codigo
        ), ARRAY_A);
        if ($env) {
            $q = floatval($env['cantidad_unidades'] ?? 0);
            if ($q > 0) {
                return $q;
            }
        }

        return 1.0;
    }

    /**
     * Mueve o desvincula un código entre integrantes de la misma familia.
     *
     * @param int    $grupo_id
     * @param string $code_tipo barcode|supplier
     * @param int    $code_id
     * @param string $accion move|unlink
     * @param int    $destino_producto_base_id
     * @param array  $opts
     * @return array|WP_Error
     */
    public function map_family_member_code($grupo_id, $code_tipo, $code_id, $accion, $destino_producto_base_id = 0, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $code_id = intval($code_id);
        $destino_producto_base_id = intval($destino_producto_base_id);
        $code_tipo = $code_tipo === 'supplier' ? 'supplier' : 'barcode';
        $accion = $accion === 'unlink' ? 'unlink' : 'move';

        if ($grupo_id <= 0 || $code_id <= 0) {
            return new WP_Error('invalid', 'Parámetros inválidos');
        }

        $member_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT producto_base_id FROM {$prefix}equivalence_members
             WHERE grupo_id = %d AND activo = 1",
            $grupo_id
        )) ?: [];
        $member_ids = array_map('intval', $member_ids);
        if (!$member_ids) {
            return new WP_Error('empty', 'La familia no tiene integrantes');
        }

        if ($code_tipo === 'barcode') {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
                $code_id
            ), ARRAY_A);
            if (!$row) {
                return new WP_Error('not_found', 'Código de barra no encontrado');
            }
            $from_id = (int) ($row['producto_base_id'] ?? 0);
            if (!in_array($from_id, $member_ids, true)) {
                return new WP_Error('mismatch', 'El código no pertenece a un integrante de esta familia');
            }

            if ($accion === 'unlink') {
                $ok = $wpdb->update(
                    "{$prefix}codigo_barra",
                    [
                        'estado' => 'en_desuso',
                        'activo' => 0,
                        'motivo_estado' => $opts['motivo'] ?? 'Desvinculado desde familia (precios)',
                        'estado_por' => get_current_user_id() ?: null,
                        'estado_at' => current_time('mysql'),
                    ],
                    ['id' => $code_id],
                    ['%s', '%d', '%s', '%d', '%s'],
                    ['%d']
                );
                if ($ok === false) {
                    return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo desvincular');
                }
                return [
                    'accion' => 'unlink',
                    'tipo' => 'barcode',
                    'code_id' => $code_id,
                    'from_producto_base_id' => $from_id,
                ];
            }

            if ($destino_producto_base_id <= 0 || !in_array($destino_producto_base_id, $member_ids, true)) {
                return new WP_Error('invalid', 'Destino inválido');
            }
            if ($destino_producto_base_id === $from_id) {
                return ['accion' => 'move', 'tipo' => 'barcode', 'moved' => false, 'code_id' => $code_id];
            }

            $dest_env = $this->get_canonical_envase($destino_producto_base_id);
            $cantidad = $dest_env ? floatval($dest_env['cantidad_unidades']) : floatval($row['cantidad'] ?? 1);
            if ($cantidad <= 0) {
                $cantidad = 1.0;
            }

            // Si el destino ya tiene el mismo código activo, conservar destino y desactivar origen.
            $clash = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$prefix}codigo_barra
                 WHERE codigo = %s AND producto_base_id = %d AND id <> %d AND activo = 1
                 LIMIT 1",
                (string) $row['codigo'],
                $destino_producto_base_id,
                $code_id
            ), ARRAY_A);
            if ($clash) {
                $wpdb->update(
                    "{$prefix}codigo_barra",
                    [
                        'estado' => 'en_desuso',
                        'activo' => 0,
                        'motivo_estado' => 'Duplicado: ya existe en destino (mapear familia)',
                        'estado_por' => get_current_user_id() ?: null,
                        'estado_at' => current_time('mysql'),
                    ],
                    ['id' => $code_id],
                    ['%s', '%d', '%s', '%d', '%s'],
                    ['%d']
                );
                return [
                    'accion' => 'move',
                    'tipo' => 'barcode',
                    'moved' => true,
                    'deduped' => true,
                    'code_id' => $code_id,
                    'kept_code_id' => (int) $clash['id'],
                    'from_producto_base_id' => $from_id,
                    'destino_producto_base_id' => $destino_producto_base_id,
                ];
            }

            $moved = $this->move_barcode_to_pack($code_id, $destino_producto_base_id, $cantidad, [
                'verify' => true,
                'motivo' => $opts['motivo'] ?? 'Mapeado entre integrantes de familia',
                'envase_id' => $dest_env ? (int) $dest_env['id'] : 0,
            ]);
            if (is_wp_error($moved)) {
                return $moved;
            }
            return [
                'accion' => 'move',
                'tipo' => 'barcode',
                'moved' => true,
                'code_id' => $code_id,
                'from_producto_base_id' => $from_id,
                'destino_producto_base_id' => $destino_producto_base_id,
                'cantidad' => $cantidad,
            ];
        }

        // supplier
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $code_id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Código de proveedor no encontrado');
        }
        $from_id = (int) ($row['producto_base_id'] ?? 0);
        if (!in_array($from_id, $member_ids, true)) {
            return new WP_Error('mismatch', 'El código no pertenece a un integrante de esta familia');
        }

        if ($accion === 'unlink') {
            $ok = $wpdb->update(
                "{$prefix}producto_proveedor",
                [
                    'activo' => 0,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $code_id],
                ['%d', '%s'],
                ['%d']
            );
            if ($ok === false) {
                return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo desvincular');
            }
            return [
                'accion' => 'unlink',
                'tipo' => 'supplier',
                'code_id' => $code_id,
                'from_producto_base_id' => $from_id,
            ];
        }

        if ($destino_producto_base_id <= 0 || !in_array($destino_producto_base_id, $member_ids, true)) {
            return new WP_Error('invalid', 'Destino inválido');
        }
        if ($destino_producto_base_id === $from_id) {
            return ['accion' => 'move', 'tipo' => 'supplier', 'moved' => false, 'code_id' => $code_id];
        }

        $clash = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id FROM {$prefix}producto_proveedor
             WHERE proveedor_id = %d AND codigo_proveedor = %s AND id <> %d AND activo = 1
             LIMIT 1",
            (int) $row['proveedor_id'],
            $row['codigo_proveedor'],
            $code_id
        ), ARRAY_A);
        if ($clash && (int) $clash['producto_base_id'] === $destino_producto_base_id) {
            $wpdb->update(
                "{$prefix}producto_proveedor",
                ['activo' => 0, 'updated_at' => current_time('mysql')],
                ['id' => $code_id],
                ['%d', '%s'],
                ['%d']
            );
            return [
                'accion' => 'move',
                'tipo' => 'supplier',
                'moved' => true,
                'deduped' => true,
                'code_id' => $code_id,
                'kept_code_id' => (int) $clash['id'],
                'from_producto_base_id' => $from_id,
                'destino_producto_base_id' => $destino_producto_base_id,
            ];
        }

        $moved = $this->move_supplier_code_to_product($code_id, $destino_producto_base_id, [
            'verify' => true,
            'motivo' => $opts['motivo'] ?? 'Mapeado entre integrantes de familia',
        ]);
        if (is_wp_error($moved)) {
            return $moved;
        }
        return array_merge(is_array($moved) ? $moved : [], [
            'accion' => 'move',
            'tipo' => 'supplier',
            'code_id' => $code_id,
        ]);
    }

    /**
     * Incrementa stock suelto del unitario (dual-write ubicación + stock_abierto).
     *
     * @param int   $unit_base_id
     * @param float $cantidad
     * @param array $meta
     * @return array|WP_Error
     */
    public function add_open_stock($unit_base_id, $cantidad, array $meta = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $unit_base_id = intval($unit_base_id);
        $cantidad = floatval($cantidad);
        if ($cantidad <= 0) {
            return new WP_Error('invalid', 'Cantidad inválida');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $unit_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto unitario no encontrado');
        }

        $ubicacion_id = intval($meta['ubicacion_id'] ?? 0);
        if (!$ubicacion_id) {
            $ubicacion_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
                 WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
                $unit_base_id
            ));
        }
        if (!$ubicacion_id) {
            $ubicacion_id = (int) $wpdb->get_var(
                "SELECT id FROM {$prefix}ubicaciones WHERE activo = 1 ORDER BY id ASC LIMIT 1"
            );
        }

        $stock_anterior = floatval($pb['stock_abierto']);
        $stock_nuevo = $stock_anterior + $cantidad;

        $wpdb->query('START TRANSACTION');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_base
             SET stock_abierto = stock_abierto + %f, stock_abierto_habilitado = 1
             WHERE id = %d AND stock_abierto = %f",
            $cantidad,
            $unit_base_id,
            $stock_anterior
        ));
        if ($updated !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('concurrent', 'El stock abierto cambió; reintenta');
        }

        if ($ubicacion_id && class_exists('Riverso_Movement')) {
            Riverso_Movement::create('entrada', $unit_base_id, $cantidad, [
                'ubicacion_destino' => $ubicacion_id,
                'referencia_tipo' => $meta['referencia_tipo'] ?? 'apertura',
                'referencia_id' => intval($meta['referencia_id'] ?? 0) ?: null,
                'notas' => $meta['notas'] ?? 'Stock suelto producto unitario',
            ]);
        }

        $wpdb->query('COMMIT');

        return [
            'unit_producto_base_id' => $unit_base_id,
            'stock_abierto' => $stock_nuevo,
            'ubicacion_id' => $ubicacion_id ?: null,
        ];
    }

    /**
     * Descuenta stock suelto del unitario.
     *
     * @param int   $unit_base_id
     * @param float $cantidad
     * @param array $meta
     * @return array|WP_Error
     */
    public function consume_open_stock($unit_base_id, $cantidad, array $meta = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $unit_base_id = intval($unit_base_id);
        $cantidad = floatval($cantidad);

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT stock_abierto FROM {$prefix}producto_base WHERE id = %d",
            $unit_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto unitario no encontrado');
        }

        $stock_anterior = floatval($pb['stock_abierto']);
        if ($cantidad > $stock_anterior) {
            return new WP_Error('insufficient', 'Stock abierto insuficiente (' . $stock_anterior . ')');
        }

        $ubicacion_id = intval($meta['ubicacion_id'] ?? 0);
        if (!$ubicacion_id) {
            $ubicacion_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
                 WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
                $unit_base_id
            ));
        }

        $wpdb->query('START TRANSACTION');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_base
             SET stock_abierto = stock_abierto - %f
             WHERE id = %d AND stock_abierto >= %f AND stock_abierto = %f",
            $cantidad,
            $unit_base_id,
            $cantidad,
            $stock_anterior
        ));
        if ($updated !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('concurrent', 'El stock abierto cambió; reintenta');
        }

        if ($ubicacion_id && class_exists('Riverso_Movement')) {
            Riverso_Movement::create('salida', $unit_base_id, $cantidad, [
                'ubicacion_origen' => $ubicacion_id,
                'referencia_tipo' => $meta['referencia_tipo'] ?? 'embolsado',
                'referencia_id' => intval($meta['referencia_id'] ?? 0) ?: null,
                'notas' => $meta['notas'] ?? 'Consumo stock suelto unitario',
            ]);
        }

        $wpdb->query('COMMIT');

        return [
            'unit_producto_base_id' => $unit_base_id,
            'stock_abierto' => $stock_anterior - $cantidad,
        ];
    }

    /* ===================== Internos ===================== */

    private function generate_next_sku() {
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

    private function demote_unit_product($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);
        if ($producto_base_id <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_base
             SET es_unidad_minima = 0, unit_of_grupo_id = NULL
             WHERE id = %d",
            $producto_base_id
        ));
    }

    private function ensure_unit_envase($unit_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}envases WHERE producto_base_id = %d AND activo = 1 LIMIT 1",
            intval($unit_base_id)
        ));
        if ($exists) {
            $wpdb->update(
                "{$prefix}envases",
                ['cantidad_unidades' => 1, 'tipo_envase' => 'envase', 'permite_apertura' => 0],
                ['id' => intval($exists)],
                ['%f', '%s', '%d'],
                ['%d']
            );
            return intval($exists);
        }

        $wpdb->insert("{$prefix}envases", [
            'producto_base_id' => intval($unit_base_id),
            'cantidad_unidades' => 1,
            'tipo_envase' => 'envase',
            'permite_apertura' => 0,
            'origen_datos' => 'unit_product',
            'activo' => 1,
        ], ['%d', '%f', '%s', '%d', '%s', '%d']);

        return (int) $wpdb->insert_id;
    }

    private function link_unit_to_family($grupo_id, $unit_id, array $opts = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (class_exists('Riverso_Family_Module')) {
            Riverso_Family_Module::get_instance()->ensure_member($grupo_id, $unit_id, 1);
        }

        $enabled = isset($opts['es_producto_unitario']) ? (int) !empty($opts['es_producto_unitario']) : 1;
        $user_id = get_current_user_id();
        $data = [
            'unit_producto_base_id' => intval($unit_id),
            'es_producto_unitario' => $enabled,
            'unit_config_at' => current_time('mysql'),
        ];
        $formats = ['%d', '%d', '%s'];
        if ($user_id) {
            $data['unit_config_by'] = $user_id;
            $formats[] = '%d';
        }

        $ok = $wpdb->update(
            "{$prefix}equivalence_groups",
            $data,
            ['id' => intval($grupo_id)],
            $formats,
            ['%d']
        );
        if ($ok === false) {
            return;
        }

        if (class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->resolve_family_assigned((int) $unit_id);
        }
    }

    private function sync_unit_coste($unit_base_id, $grupo_id) {
        if (!class_exists('Riverso_Pricing_Module')) {
            return;
        }
        $coste = $this->calculate_coste_unitario($grupo_id);
        if ($coste['coste'] === null) {
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pricing = Riverso_Pricing_Module::get_instance();
        $row = $pricing->recalc_price($unit_base_id, Riverso_Pricing_Module::CANAL_LOCAL);
        if (is_wp_error($row)) {
            return;
        }

        $wpdb->update(
            "{$prefix}precios",
            ['c_ref' => $coste['coste']],
            ['id' => intval($row['id'])],
            ['%f'],
            ['%d']
        );
    }
}
