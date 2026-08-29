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

        return true;
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

    private function reassign_barcode_tasks($barcode_id, $new_product_id) {
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
            "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.es_unidad_minima
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

            $rows[] = [
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
            ];
        }

        return [
            'p_asignado' => $p_asignado,
            'family_rule_id' => $family_rule_id ? intval($family_rule_id) : null,
            'members' => $rows,
        ];
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
