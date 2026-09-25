<?php
/**
 * Emparejamientos de precio y/o stock (Fase 54).
 *
 * Agrupa productos sin familia o el unitario de una familia.
 * Flags independientes: emparejar_precios / emparejar_stock.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Emparejamiento_Module {

    private static $instance = null;

    /** Evita recursión al propagar precios. */
    private $syncing_prices = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        if (class_exists('Riverso_POS_Activator')) {
            Riverso_POS_Activator::ensure_emparejamientos_schema();
        }

        add_action('wp_ajax_riverso_emparejamientos_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_emparejamientos_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_emparejamientos_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_emparejamientos_update', [$this, 'ajax_update']);
        add_action('wp_ajax_riverso_emparejamientos_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_riverso_emparejamientos_add_member', [$this, 'ajax_add_member']);
        add_action('wp_ajax_riverso_emparejamientos_remove_member', [$this, 'ajax_remove_member']);
        add_action('wp_ajax_riverso_emparejamientos_resolve_conflict', [$this, 'ajax_resolve_conflict']);
        add_action('wp_ajax_riverso_emparejamientos_preview_price', [$this, 'ajax_preview_price']);
        add_action('wp_ajax_riverso_emparejamientos_apply_price', [$this, 'ajax_apply_price']);
        add_action('wp_ajax_riverso_emparejamientos_search_candidates', [$this, 'ajax_search_candidates']);
        add_action('wp_ajax_riverso_emparejamientos_answer_need', [$this, 'ajax_answer_need']);
        add_action('wp_ajax_riverso_emparejamientos_create_and_assign', [$this, 'ajax_create_and_assign']);
        add_action('wp_ajax_riverso_emparejamientos_stock_status', [$this, 'ajax_stock_status']);
        add_action('wp_ajax_riverso_emparejamientos_suggest_names', [$this, 'ajax_suggest_names']);
        add_action('wp_ajax_riverso_emparejamientos_create_member', [$this, 'ajax_create_member']);
        add_action('wp_ajax_riverso_emparejamientos_save', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_emparejamientos_preview_members', [$this, 'ajax_preview_members']);

        add_action('riverso_family_member_changed', [$this, 'on_family_member_changed'], 10, 2);
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    private function check_manage() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products') && !current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    /* ===================== Elegibilidad ===================== */

    /**
     * Elegible: sin familia, o producto unitario de una familia.
     * Packs / miembros no-unitarios: no.
     *
     * @param int $producto_base_id
     * @return array{eligible:bool,reason:string,grupo_id:int,is_unitario:bool}
     */
    public function eligibility($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $producto_base_id = absint($producto_base_id);
        $out = [
            'eligible' => false,
            'reason' => '',
            'grupo_id' => 0,
            'is_unitario' => false,
        ];
        if (!$producto_base_id) {
            $out['reason'] = 'Producto inválido';
            return $out;
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, deleted_at, archived_at, unit_of_grupo_id
             FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb || !empty($pb['deleted_at'])) {
            $out['reason'] = 'Producto no encontrado';
            return $out;
        }

        $unit_grupo = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups
             WHERE unit_producto_base_id = %d AND activo = 1 LIMIT 1",
            $producto_base_id
        ), ARRAY_A);
        if ($unit_grupo) {
            $out['eligible'] = true;
            $out['is_unitario'] = true;
            $out['grupo_id'] = (int) $unit_grupo['id'];
            return $out;
        }

        if (!empty($pb['unit_of_grupo_id'])) {
            $out['eligible'] = true;
            $out['is_unitario'] = true;
            $out['grupo_id'] = (int) $pb['unit_of_grupo_id'];
            return $out;
        }

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT em.grupo_id, eg.es_producto_unitario, eg.unit_producto_base_id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups eg ON eg.id = em.grupo_id AND eg.activo = 1
             WHERE em.producto_base_id = %d AND em.activo = 1
             LIMIT 1",
            $producto_base_id
        ), ARRAY_A);

        if ($member) {
            $unit_id = (int) ($member['unit_producto_base_id'] ?? 0);
            if ($unit_id > 0 && $unit_id === $producto_base_id) {
                $out['eligible'] = true;
                $out['is_unitario'] = true;
                $out['grupo_id'] = (int) $member['grupo_id'];
                return $out;
            }
            $out['reason'] = 'Es miembro de familia pero no el producto unitario; usa el unitario.';
            $out['grupo_id'] = (int) $member['grupo_id'];
            return $out;
        }

        $out['eligible'] = true;
        $out['reason'] = '';
        return $out;
    }

    public function is_eligible($producto_base_id) {
        return !empty($this->eligibility($producto_base_id)['eligible']);
    }

    /**
     * Emparejamiento activo del producto (si existe).
     *
     * @param int $producto_base_id
     * @return array|null
     */
    public function get_of_product($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $producto_base_id = absint($producto_base_id);
        if (!$producto_base_id) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, m.id AS miembro_id, m.prioridad
             FROM {$prefix}emparejamiento_miembros m
             INNER JOIN {$prefix}emparejamientos e ON e.id = m.emparejamiento_id
             WHERE m.producto_base_id = %d AND m.activo = 1 AND e.activo = 1
             LIMIT 1",
            $producto_base_id
        ), ARRAY_A) ?: null;
    }

    public function product_has_emparejamiento($producto_base_id) {
        return (bool) $this->get_of_product($producto_base_id);
    }

    /* ===================== CRUD ===================== */

    public function list_emparejamientos($search = '') {
        global $wpdb;
        $prefix = $this->prefix();
        $search = trim((string) $search);
        $sql = "SELECT e.*,
                    (SELECT COUNT(*) FROM {$prefix}emparejamiento_miembros m
                     WHERE m.emparejamiento_id = e.id AND m.activo = 1) AS miembros_count
                FROM {$prefix}emparejamientos e
                WHERE e.activo = 1";
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $sql .= " AND (
                e.nombre LIKE %s OR e.codigo LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$prefix}emparejamiento_miembros m
                    INNER JOIN {$prefix}producto_base pb ON pb.id = m.producto_base_id
                    WHERE m.emparejamiento_id = e.id AND m.activo = 1
                      AND (pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s)
                )
            )";
            $args = [$like, $like, $like, $like];
        }
        $sql .= ' ORDER BY e.updated_at DESC, e.id DESC LIMIT 200';
        $rows = $args
            ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        return $rows ?: [];
    }

    public function get($id, $with_members = true) {
        global $wpdb;
        $prefix = $this->prefix();
        $id = absint($id);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}emparejamientos WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        if ($with_members) {
            $row['miembros'] = $this->get_members($id);
            $row['stock'] = !empty($row['emparejar_stock']) ? $this->compute_stock($id) : null;
            $row['price_preview'] = !empty($row['emparejar_precios'])
                ? $this->margin_preview_for_group($id, null)
                : null;
        }
        return $row;
    }

    public function get_members($emparejamiento_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $emparejamiento_id = absint($emparejamiento_id);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, pb.canonical_sku, pb.nombre_canonico, pb.emparejamiento_decision,
                    pr.p_asignado, pr.c_ref, pr.alerta_margen
             FROM {$prefix}emparejamiento_miembros m
             INNER JOIN {$prefix}producto_base pb ON pb.id = m.producto_base_id
             LEFT JOIN {$prefix}precios pr ON pr.producto_base_id = m.producto_base_id
                AND pr.canal = 'local' AND pr.woocommerce_variation_id = 0
             WHERE m.emparejamiento_id = %d AND m.activo = 1
             ORDER BY m.prioridad ASC, m.id ASC",
            $emparejamiento_id
        ), ARRAY_A) ?: [];

        foreach ($rows as &$r) {
            $elig = $this->eligibility((int) $r['producto_base_id']);
            $r['eligible'] = !empty($elig['eligible']);
            $r['eligible_reason'] = $elig['reason'];
            $r['is_unitario'] = !empty($elig['is_unitario']);
            $r['grupo_id'] = (int) ($elig['grupo_id'] ?? 0);
            $iva = 'afecto';
            if (class_exists('Riverso_Pricing_Module')) {
                $iva = Riverso_Pricing_Module::get_instance()->get_iva_tipo_for_product((int) $r['producto_base_id']);
            }
            $r['iva_tipo'] = $iva;
            $p = $r['p_asignado'] !== null && $r['p_asignado'] !== '' ? (float) $r['p_asignado'] : null;
            $c = $r['c_ref'] !== null && $r['c_ref'] !== '' ? (float) $r['c_ref'] : null;
            $r['margen_bruto'] = ($p !== null && $c !== null) ? round($p - $c, 3) : null;
            if ($p !== null && $c !== null && class_exists('Riverso_Pricing_Module')) {
                $neto = Riverso_Pricing_Module::net_from_gross($p, $iva);
                $r['margen_neto'] = ($neto !== null) ? round((float) $neto - $c, 3) : null;
            } else {
                $r['margen_neto'] = null;
            }
        }
        unset($r);
        return $rows;
    }

    public function create($data) {
        global $wpdb;
        $prefix = $this->prefix();

        $nombre = sanitize_text_field($data['nombre'] ?? '');
        if ($nombre === '') {
            return new WP_Error('invalid', 'Nombre requerido');
        }
        $emp_precios = !empty($data['emparejar_precios']) ? 1 : 0;
        $emp_stock = !empty($data['emparejar_stock']) ? 1 : 0;
        if (!$emp_precios && !$emp_stock) {
            return new WP_Error('invalid', 'Debes activar Emparejar precios y/o Emparejar stock');
        }

        $codigo = sanitize_text_field($data['codigo'] ?? '');
        if ($codigo === '') {
            $codigo = $this->generate_codigo($nombre);
        }

        $ok = $wpdb->insert("{$prefix}emparejamientos", [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'emparejar_precios' => $emp_precios,
            'emparejar_stock' => $emp_stock,
            'stock_minimo' => isset($data['stock_minimo']) && $data['stock_minimo'] !== ''
                ? (int) $data['stock_minimo'] : null,
            'stock_critico' => isset($data['stock_critico']) && $data['stock_critico'] !== ''
                ? (int) $data['stock_critico'] : null,
            'activo' => 1,
            'notas' => isset($data['notas']) ? sanitize_textarea_field($data['notas']) : null,
            'created_by' => get_current_user_id(),
        ]);
        if (!$ok) {
            return new WP_Error('db', 'No se pudo crear el emparejamiento');
        }
        $id = (int) $wpdb->insert_id;
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('emparejamiento.created', 'emparejamiento', $id, [
                'new_value' => ['nombre' => $nombre, 'codigo' => $codigo],
            ]);
        }
        return $this->get($id);
    }

    private function generate_codigo($nombre) {
        global $wpdb;
        $prefix = $this->prefix();
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', substr((string) $nombre, 0, 12)));
        if ($base === '') {
            $base = 'EMP';
        }
        $base = 'EMP-' . $base;
        $codigo = $base;
        $n = 1;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}emparejamientos WHERE codigo = %s LIMIT 1",
            $codigo
        ))) {
            $n++;
            $codigo = $base . '-' . $n;
        }
        return $codigo;
    }

    public function update($id, $data, $confirm_disable = false) {
        global $wpdb;
        $prefix = $this->prefix();
        $id = absint($id);
        $row = $this->get($id, false);
        if (!$row) {
            return new WP_Error('not_found', 'Emparejamiento no encontrado');
        }

        $update = [];
        $formats = [];
        if (isset($data['nombre'])) {
            $nombre = sanitize_text_field($data['nombre']);
            if ($nombre === '') {
                return new WP_Error('invalid', 'Nombre vacío');
            }
            $update['nombre'] = $nombre;
            $formats[] = '%s';
        }
        if (isset($data['notas'])) {
            $update['notas'] = sanitize_textarea_field($data['notas']);
            $formats[] = '%s';
        }
        if (array_key_exists('stock_minimo', $data)) {
            $update['stock_minimo'] = ($data['stock_minimo'] === '' || $data['stock_minimo'] === null)
                ? null : (int) $data['stock_minimo'];
            $formats[] = '%d';
        }
        if (array_key_exists('stock_critico', $data)) {
            $update['stock_critico'] = ($data['stock_critico'] === '' || $data['stock_critico'] === null)
                ? null : (int) $data['stock_critico'];
            $formats[] = '%d';
        }

        $new_precios = (array_key_exists('emparejar_precios', $data) && $data['emparejar_precios'] !== null)
            ? (!empty($data['emparejar_precios']) ? 1 : 0)
            : (int) $row['emparejar_precios'];
        $new_stock = (array_key_exists('emparejar_stock', $data) && $data['emparejar_stock'] !== null)
            ? (!empty($data['emparejar_stock']) ? 1 : 0)
            : (int) $row['emparejar_stock'];

        if (!$new_precios && !$new_stock) {
            return new WP_Error('invalid', 'Debes dejar al menos Emparejar precios o Emparejar stock');
        }

        $warnings = [];
        if ((int) $row['emparejar_precios'] === 1 && $new_precios === 0 && !empty($row['precios_usados'])) {
            if (!$confirm_disable) {
                return new WP_Error(
                    'confirm_disable_precios',
                    'Emparejar precios ya se usó para cambios. Confirma para desactivarlo (no revierte precios).',
                    ['needs_confirm' => true, 'flag' => 'emparejar_precios']
                );
            }
            $warnings[] = 'Se desactivó Emparejar precios (no se revirtieron precios).';
        }
        if ((int) $row['emparejar_stock'] === 1 && $new_stock === 0 && !empty($row['stock_usados'])) {
            if (!$confirm_disable) {
                return new WP_Error(
                    'confirm_disable_stock',
                    'Emparejar stock ya se usó. Confirma para desactivarlo (no revierte alarmas propias).',
                    ['needs_confirm' => true, 'flag' => 'emparejar_stock']
                );
            }
            $warnings[] = 'Se desactivó Emparejar stock.';
        }

        $update['emparejar_precios'] = $new_precios;
        $formats[] = '%d';
        $update['emparejar_stock'] = $new_stock;
        $formats[] = '%d';

        $min = array_key_exists('stock_minimo', $update) ? $update['stock_minimo'] : $row['stock_minimo'];
        $crit = array_key_exists('stock_critico', $update) ? $update['stock_critico'] : $row['stock_critico'];
        if ($min !== null && $crit !== null && (int) $crit > (int) $min) {
            return new WP_Error('invalid', 'Stock crítico no puede ser mayor que el mínimo');
        }

        $wpdb->update("{$prefix}emparejamientos", $update, ['id' => $id], $formats, ['%d']);
        $result = $this->get($id);
        if ($warnings) {
            $result['warnings'] = $warnings;
        }
        return $result;
    }

    public function soft_delete($id) {
        global $wpdb;
        $prefix = $this->prefix();
        $id = absint($id);
        $wpdb->update("{$prefix}emparejamientos", ['activo' => 0], ['id' => $id], ['%d'], ['%d']);
        $wpdb->update(
            "{$prefix}emparejamiento_miembros",
            ['activo' => 0],
            ['emparejamiento_id' => $id],
            ['%d'],
            ['%d']
        );
        return true;
    }

    /* ===================== Miembros ===================== */

    /**
     * Detecta conflicto de precio al agregar miembro.
     *
     * @return array{has_conflict:bool,options:array,candidate:array,members:array}
     */
    public function detect_price_conflict($emparejamiento_id, $producto_base_id) {
        $group = $this->get($emparejamiento_id, false);
        $members = $this->get_members($emparejamiento_id);
        $cand_price = $this->local_price($producto_base_id);
        $cand_p = $cand_price['p_asignado'] ?? null;
        $cand_p = ($cand_p !== null && $cand_p !== '') ? (float) $cand_p : null;

        $existing = [];
        foreach ($members as $m) {
            $p = $m['p_asignado'];
            if ($p !== null && $p !== '') {
                $existing[] = round((float) $p, 3);
            }
        }
        $existing = array_values(array_unique($existing));

        $has = false;
        if (!empty($group['emparejar_precios'])) {
            if ($cand_p === null && !empty($existing)) {
                $has = true;
            } elseif ($cand_p !== null && !empty($existing)) {
                foreach ($existing as $ep) {
                    if (abs($ep - $cand_p) > 0.001) {
                        $has = true;
                        break;
                    }
                }
            } elseif ($cand_p !== null && empty($existing) && count($members) > 0) {
                // Miembros sin precio + candidato con precio: no conflicto duro, se puede adoptar.
                $has = false;
            } elseif ($cand_p === null && empty($existing) && count($members) > 0) {
                $has = true; // nadie tiene precio
            }
        }

        $options = [];
        if ($cand_p !== null) {
            $options[] = [
                'source' => 'candidate',
                'producto_base_id' => (int) $producto_base_id,
                'p_asignado' => $cand_p,
                'label' => 'Precio del nuevo miembro',
            ];
        }
        foreach ($members as $m) {
            if ($m['p_asignado'] !== null && $m['p_asignado'] !== '') {
                $options[] = [
                    'source' => 'member',
                    'producto_base_id' => (int) $m['producto_base_id'],
                    'p_asignado' => (float) $m['p_asignado'],
                    'label' => ($m['canonical_sku'] ?: ('#' . $m['producto_base_id'])) . ' — ' . ($m['nombre_canonico'] ?? ''),
                ];
            }
        }

        return [
            'has_conflict' => $has && !empty($group['emparejar_precios']),
            'candidate_price' => $cand_p,
            'existing_prices' => $existing,
            'options' => $options,
            'members' => $members,
            'candidate' => [
                'producto_base_id' => (int) $producto_base_id,
                'p_asignado' => $cand_p,
                'c_ref' => $cand_price['c_ref'] ?? null,
            ],
        ];
    }

    public function add_member($emparejamiento_id, $producto_base_id, $opts = []) {
        global $wpdb;
        $prefix = $this->prefix();
        $emparejamiento_id = absint($emparejamiento_id);
        $producto_base_id = absint($producto_base_id);
        $force_price = isset($opts['p_asignado']) ? (float) $opts['p_asignado'] : null;
        $confirm = !empty($opts['confirm']);
        $skip_conflict = !empty($opts['skip_conflict_check']);

        $group = $this->get($emparejamiento_id, false);
        if (!$group || empty($group['activo'])) {
            return new WP_Error('not_found', 'Emparejamiento no encontrado');
        }

        $elig = $this->eligibility($producto_base_id);
        if (empty($elig['eligible'])) {
            return new WP_Error('ineligible', $elig['reason'] ?: 'Producto no elegible');
        }

        $other = $this->get_of_product($producto_base_id);
        if ($other && (int) $other['id'] !== $emparejamiento_id) {
            return new WP_Error(
                'already_paired',
                'El producto ya pertenece al emparejamiento «' . ($other['nombre'] ?? $other['codigo']) . '»'
            );
        }

        if (!$skip_conflict && !empty($group['emparejar_precios']) && !$confirm) {
            $conflict = $this->detect_price_conflict($emparejamiento_id, $producto_base_id);
            if (!empty($conflict['has_conflict'])) {
                return new WP_Error(
                    'price_conflict',
                    'Conflicto de precios: elige el precio compartido y confirma.',
                    $conflict
                );
            }
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, activo FROM {$prefix}emparejamiento_miembros
             WHERE emparejamiento_id = %d AND producto_base_id = %d LIMIT 1",
            $emparejamiento_id,
            $producto_base_id
        ), ARRAY_A);
        if ($existing) {
            if (empty($existing['activo'])) {
                $wpdb->update(
                    "{$prefix}emparejamiento_miembros",
                    ['activo' => 1],
                    ['id' => (int) $existing['id']],
                    ['%d'],
                    ['%d']
                );
            }
        } else {
            $wpdb->insert("{$prefix}emparejamiento_miembros", [
                'emparejamiento_id' => $emparejamiento_id,
                'producto_base_id' => $producto_base_id,
                'prioridad' => 100,
                'activo' => 1,
            ]);
        }

        $wpdb->update(
            "{$prefix}producto_base",
            ['emparejamiento_decision' => 'requiere'],
            ['id' => $producto_base_id],
            ['%s'],
            ['%d']
        );

        if (!empty($group['emparejar_precios'])) {
            $price_to_apply = $force_price;
            if ($price_to_apply === null) {
                $conflict = $this->detect_price_conflict($emparejamiento_id, $producto_base_id);
                if (!empty($conflict['existing_prices'])) {
                    $price_to_apply = (float) $conflict['existing_prices'][0];
                } elseif ($conflict['candidate_price'] !== null) {
                    $price_to_apply = (float) $conflict['candidate_price'];
                }
            }
            if ($price_to_apply !== null && $price_to_apply > 0) {
                $this->apply_shared_price($emparejamiento_id, $price_to_apply, [
                    'source_type' => 'manual',
                    'notas' => 'Alta de miembro en emparejamiento',
                ]);
            }
        }

        return $this->get($emparejamiento_id);
    }

    public function remove_member($emparejamiento_id, $producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $wpdb->update(
            "{$prefix}emparejamiento_miembros",
            ['activo' => 0],
            [
                'emparejamiento_id' => absint($emparejamiento_id),
                'producto_base_id' => absint($producto_base_id),
            ],
            ['%d'],
            ['%d', '%d']
        );
        return $this->get(absint($emparejamiento_id));
    }

    /* ===================== Precios ===================== */

    private function local_price($producto_base_id) {
        if (!class_exists('Riverso_Pricing_Module')) {
            return null;
        }
        return Riverso_Pricing_Module::get_instance()->get_local_price(absint($producto_base_id));
    }

    public function margin_preview_for_group($emparejamiento_id, $p_asignado = null) {
        return $this->margin_preview_for_products(
            array_map(static function ($m) {
                return (int) $m['producto_base_id'];
            }, $this->get_members($emparejamiento_id)),
            $p_asignado
        );
    }

    /**
     * Preview de márgenes para una lista de producto_base_id (draft o persistidos).
     * Incluye bases de costo (neto/bruto), orígenes y factor para la UI.
     *
     * @param int[]      $producto_base_ids
     * @param float|null $p_asignado  Precio compartido propuesto (bruto comercial).
     * @return array
     */
    public function margin_preview_for_products(array $producto_base_ids, $p_asignado = null) {
        global $wpdb;
        $prefix = $this->prefix();
        $rows = [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $producto_base_ids))));
        if (!class_exists('Riverso_Price_Lookup_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        $lookup = class_exists('Riverso_Price_Lookup_Service')
            ? Riverso_Price_Lookup_Service::get_instance()
            : null;

        foreach ($ids as $pid) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                $pid
            ), ARRAY_A);
            if (!$pb) {
                continue;
            }

            $pack = $lookup ? $lookup->get_local_price_pack($pid) : null;
            $price = $this->local_price($pid);

            $iva = 'afecto';
            if ($pack && !empty($pack['iva_tipo'])) {
                $iva = (string) $pack['iva_tipo'];
            } elseif (class_exists('Riverso_Pricing_Module')) {
                $iva = Riverso_Pricing_Module::get_instance()->get_iva_tipo_for_product($pid);
            }

            $actual_bruto = null;
            if ($pack && $pack['p_asignado'] !== null && $pack['p_asignado'] !== '') {
                $actual_bruto = (float) $pack['p_asignado'];
            } elseif ($price && $price['p_asignado'] !== null && $price['p_asignado'] !== '') {
                $actual_bruto = (float) $price['p_asignado'];
            }

            $nuevo_bruto = $p_asignado !== null ? (float) $p_asignado : $actual_bruto;
            $actual_neto = ($pack && $pack['p_neto'] !== null)
                ? (float) $pack['p_neto']
                : (class_exists('Riverso_Pricing_Module')
                    ? Riverso_Pricing_Module::net_from_gross($actual_bruto, $iva)
                    : $actual_bruto);
            $nuevo_neto = class_exists('Riverso_Pricing_Module')
                ? Riverso_Pricing_Module::net_from_gross($nuevo_bruto, $iva)
                : $nuevo_bruto;

            $c_ref_bases = is_array($pack) ? ($pack['c_ref_bases'] ?? null) : null;
            $c_ref_bruto = null;
            $c_ref_neto = null;
            if ($pack) {
                $c_ref_bruto = $pack['c_ref_bruto'] ?? $pack['c_ref'] ?? null;
                $c_ref_neto = $pack['c_ref_neto'] ?? null;
            }
            if (($c_ref_bruto === null || $c_ref_bruto === '') && $price
                && $price['c_ref'] !== null && $price['c_ref'] !== '') {
                $c_ref_bruto = (float) $price['c_ref'];
            }
            if ($c_ref_neto === null && $c_ref_bruto !== null && class_exists('Riverso_Pricing_Module')) {
                $c_ref_neto = Riverso_Pricing_Module::net_from_gross((float) $c_ref_bruto, $iva);
            }
            if (!$c_ref_bases && ($c_ref_neto !== null || $c_ref_bruto !== null)
                && class_exists('Riverso_Cost_Lookup_Service')) {
                $c_ref_bases = Riverso_Cost_Lookup_Service::bases_from_c_ref(
                    $c_ref_neto !== null ? (float) $c_ref_neto : null,
                    $c_ref_bruto !== null ? (float) $c_ref_bruto : null
                );
            }

            // Default de cálculo (tras D/R) para margen/factor servidor.
            $cost_neto = $c_ref_neto !== null ? (float) $c_ref_neto : null;
            $cost_bruto = $c_ref_bruto !== null ? (float) $c_ref_bruto : null;
            if (is_array($c_ref_bases) && !empty($c_ref_bases['tras_dr']) && is_array($c_ref_bases['tras_dr'])) {
                $pair = $c_ref_bases['tras_dr'];
                if (isset($pair['neto']) && $pair['neto'] !== null && $pair['neto'] !== '') {
                    $cost_neto = (float) $pair['neto'];
                }
                if (isset($pair['bruto']) && $pair['bruto'] !== null && $pair['bruto'] !== '') {
                    $cost_bruto = (float) $pair['bruto'];
                }
            }

            $margen_bruto = ($nuevo_bruto !== null && $cost_bruto !== null)
                ? round($nuevo_bruto - $cost_bruto, 3) : null;
            $margen_neto = ($nuevo_neto !== null && $cost_neto !== null)
                ? round((float) $nuevo_neto - $cost_neto, 3) : null;
            $factor = ($nuevo_neto !== null && $cost_neto !== null && $cost_neto > 0)
                ? round((float) $nuevo_neto / $cost_neto, 4) : null;

            $alerta = 0;
            if ($nuevo_bruto !== null && $cost_neto !== null && class_exists('Riverso_Pricing_Module')) {
                $alerta = Riverso_Pricing_Module::is_margin_alert($nuevo_bruto, $cost_neto, 1.0, $iva);
            }

            $empty_origin = [
                'key' => '',
                'label' => '—',
                'fecha' => '',
                'folio' => '',
                'detalle' => '',
                'url' => '',
            ];

            $origen_precio = is_array($pack) ? ($pack['origen_precio'] ?? $empty_origin) : $empty_origin;
            $origen_costo = is_array($pack) ? ($pack['origen_costo'] ?? $empty_origin) : $empty_origin;
            $meta = is_array($pack) ? ($pack['costo_bases_meta'] ?? null) : null;
            if (is_array($meta) && empty($origen_costo['url']) && !empty($meta['factura_id'])) {
                $origen_costo = array_merge(is_array($origen_costo) ? $origen_costo : $empty_origin, [
                    'url' => admin_url(
                        'admin.php?page=riverso-pos-pricing&tab=process&factura_id=' .
                        absint($meta['factura_id'])
                    ),
                    'folio' => $origen_costo['folio'] ?: (string) ($meta['folio'] ?? ''),
                    'fecha' => $origen_costo['fecha'] ?: (string) ($meta['fecha_emision'] ?? ''),
                ]);
            }

            $rows[] = [
                'producto_base_id' => $pid,
                'sku' => $pb['canonical_sku'],
                'nombre' => $pb['nombre_canonico'],
                'iva_tipo' => $iva,
                'c_ref' => $c_ref_bruto !== null ? (float) $c_ref_bruto : null,
                'c_ref_bruto' => $c_ref_bruto !== null ? (float) $c_ref_bruto : null,
                'c_ref_neto' => $cost_neto,
                'c_ref_bases' => $c_ref_bases,
                'costo_bases_meta' => $meta,
                'p_asignado_actual' => $actual_bruto,
                'p_asignado_nuevo' => $nuevo_bruto,
                'p_neto_actual' => $actual_neto !== null ? (float) $actual_neto : null,
                'p_neto_nuevo' => $nuevo_neto !== null ? (float) $nuevo_neto : null,
                'margen_bruto' => $margen_bruto,
                'margen_neto' => $margen_neto,
                'factor' => $factor,
                'alerta_margen' => $alerta,
                'origen_precio' => $origen_precio,
                'origen_costo' => $origen_costo,
            ];
        }
        return $rows;
    }

    /**
     * Detecta conflicto de precios entre una lista de productos (draft).
     *
     * @param int[] $producto_base_ids
     * @return array
     */
    public function detect_price_conflict_among(array $producto_base_ids) {
        $prices = [];
        $options = [];
        $missing = [];
        foreach (array_unique(array_filter(array_map('absint', $producto_base_ids))) as $pid) {
            $row = $this->local_price($pid);
            $p = ($row && $row['p_asignado'] !== null && $row['p_asignado'] !== '')
                ? round((float) $row['p_asignado'], 3) : null;
            if ($p === null) {
                $missing[] = $pid;
                continue;
            }
            $prices[] = $p;
            global $wpdb;
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT canonical_sku, nombre_canonico FROM {$this->prefix()}producto_base WHERE id = %d",
                $pid
            ), ARRAY_A);
            $options[] = [
                'source' => 'member',
                'producto_base_id' => $pid,
                'p_asignado' => $p,
                'label' => (($pb['canonical_sku'] ?? '') ?: ('#' . $pid)) . ' — ' . ($pb['nombre_canonico'] ?? ''),
            ];
        }
        $unique = array_values(array_unique($prices));
        $has = count($unique) > 1 || (count($missing) > 0 && count($unique) > 0)
            || (count($missing) > 0 && count($unique) === 0 && count($producto_base_ids) > 1);
        return [
            'has_conflict' => $has,
            'existing_prices' => $unique,
            'missing_ids' => $missing,
            'options' => $options,
        ];
    }

    /**
     * Guarda create/update + sincroniza miembros y precio compartido opcional.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public function save_full(array $data) {
        $id = absint($data['id'] ?? 0);
        $member_ids = [];
        if (!empty($data['member_ids']) && is_array($data['member_ids'])) {
            $member_ids = array_values(array_unique(array_filter(array_map('absint', $data['member_ids']))));
        }
        $confirm_disable = !empty($data['confirm_disable']);
        $p_asignado = isset($data['p_asignado']) && $data['p_asignado'] !== '' && $data['p_asignado'] !== null
            ? (float) $data['p_asignado'] : null;
        $emp_precios = !empty($data['emparejar_precios']);

        if ($emp_precios && count($member_ids) >= 2 && ($p_asignado === null || $p_asignado <= 0)) {
            $conflict = $this->detect_price_conflict_among($member_ids);
            if (!empty($conflict['has_conflict'])) {
                return new WP_Error(
                    'price_conflict',
                    'Hay conflicto de precios entre miembros. Elegí el precio compartido antes de guardar.',
                    $conflict
                );
            }
            if (!empty($conflict['existing_prices'])) {
                $p_asignado = (float) $conflict['existing_prices'][0];
            }
        }

        if ($id <= 0) {
            $created = $this->create([
                'nombre' => $data['nombre'] ?? '',
                'codigo' => $data['codigo'] ?? '',
                'emparejar_precios' => $emp_precios,
                'emparejar_stock' => !empty($data['emparejar_stock']),
                'stock_minimo' => $data['stock_minimo'] ?? null,
                'stock_critico' => $data['stock_critico'] ?? null,
                'notas' => $data['notas'] ?? null,
            ]);
            if (is_wp_error($created)) {
                return $created;
            }
            $id = (int) $created['id'];
        } else {
            $updated = $this->update($id, [
                'nombre' => $data['nombre'] ?? null,
                'emparejar_precios' => array_key_exists('emparejar_precios', $data)
                    ? ($emp_precios ? 1 : 0) : null,
                'emparejar_stock' => array_key_exists('emparejar_stock', $data)
                    ? (!empty($data['emparejar_stock']) ? 1 : 0) : null,
                'stock_minimo' => array_key_exists('stock_minimo', $data) ? $data['stock_minimo'] : null,
                'stock_critico' => array_key_exists('stock_critico', $data) ? $data['stock_critico'] : null,
                'notas' => array_key_exists('notas', $data) ? $data['notas'] : null,
            ], $confirm_disable);
            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        $current = $this->get_members($id);
        $current_ids = array_map(static function ($m) {
            return (int) $m['producto_base_id'];
        }, $current);

        foreach ($member_ids as $pid) {
            if (in_array($pid, $current_ids, true)) {
                continue;
            }
            $add = $this->add_member($id, $pid, [
                'skip_conflict_check' => true,
                'confirm' => true,
            ]);
            if (is_wp_error($add)) {
                return $add;
            }
        }

        if (array_key_exists('member_ids', $data)) {
            foreach ($current_ids as $pid) {
                if (!in_array($pid, $member_ids, true)) {
                    $this->remove_member($id, $pid);
                }
            }
        }

        $group = $this->get($id, false);
        if (!empty($group['emparejar_precios']) && $p_asignado !== null && $p_asignado > 0) {
            $applied = $this->apply_shared_price($id, $p_asignado, [
                'source_type' => 'manual',
                'notas' => 'Guardado desde interfaz de emparejamientos',
            ]);
            if (is_wp_error($applied)) {
                return $applied;
            }
        }

        return $this->get($id);
    }

    /**
     * Escribe el mismo p_asignado en todos los miembros (sin copiar c_ref).
     *
     * @param int   $emparejamiento_id
     * @param float $p_asignado
     * @param array $meta source_type, source_document_id, notas, skip_product_id
     * @return array|WP_Error
     */
    public function apply_shared_price($emparejamiento_id, $p_asignado, $meta = []) {
        $emparejamiento_id = absint($emparejamiento_id);
        $p_asignado = (float) $p_asignado;
        if ($p_asignado <= 0) {
            return new WP_Error('invalid', 'Precio inválido');
        }
        $group = $this->get($emparejamiento_id, false);
        if (!$group || empty($group['emparejar_precios'])) {
            return new WP_Error('disabled', 'Emparejar precios no está activo');
        }
        if (!class_exists('Riverso_Pricing_Module')) {
            return new WP_Error('no_pricing', 'Módulo de precios no disponible');
        }

        $this->syncing_prices = true;
        $pricing = Riverso_Pricing_Module::get_instance();
        $skip = absint($meta['skip_product_id'] ?? 0);
        $members = $this->get_members($emparejamiento_id);
        $results = [];
        $source = sanitize_key($meta['source_type'] ?? 'manual');
        $base_meta = [
            'source_type' => $source,
            'source_document_id' => $meta['source_document_id'] ?? null,
            'notas' => $meta['notas'] ?? ('Emparejamiento #' . ($group['codigo'] ?? $emparejamiento_id)),
            'emparejamiento_id' => $emparejamiento_id,
        ];

        foreach ($members as $m) {
            $pid = (int) $m['producto_base_id'];
            if ($skip && $pid === $skip) {
                continue;
            }
            $res = $pricing->upsert_assigned_price($pid, 'local', $p_asignado, 0, $base_meta);
            $results[] = [
                'producto_base_id' => $pid,
                'ok' => !is_wp_error($res),
                'error' => is_wp_error($res) ? $res->get_error_message() : null,
            ];
        }
        $this->syncing_prices = false;

        global $wpdb;
        $wpdb->update(
            $this->prefix() . 'emparejamientos',
            ['precios_usados' => 1],
            ['id' => $emparejamiento_id],
            ['%d'],
            ['%d']
        );

        return [
            'emparejamiento_id' => $emparejamiento_id,
            'p_asignado' => $p_asignado,
            'results' => $results,
            'preview' => $this->margin_preview_for_group($emparejamiento_id, $p_asignado),
        ];
    }

    /**
     * Tras guardar precio de un producto, propaga si pertenece a emparejamiento de precios.
     *
     * @param int   $producto_base_id
     * @param float $p_asignado
     * @param array $meta
     * @return array|null|WP_Error
     */
    public function maybe_propagate_price($producto_base_id, $p_asignado, $meta = []) {
        if ($this->syncing_prices) {
            return null;
        }
        $emp = $this->get_of_product($producto_base_id);
        if (!$emp || empty($emp['emparejar_precios'])) {
            return null;
        }
        $meta['skip_product_id'] = absint($producto_base_id);
        if (empty($meta['emparejamiento_id'])) {
            $meta['emparejamiento_id'] = (int) $emp['id'];
        }
        return $this->apply_shared_price((int) $emp['id'], (float) $p_asignado, $meta);
    }

    public function is_syncing_prices() {
        return $this->syncing_prices;
    }

    /* ===================== Stock ===================== */

    /**
     * Stock sumado del emparejamiento (solo lectura).
     * Suelto: ubicaciones + stock_abierto.
     * Unitario de familia: compute_family_stock.
     */
    public function compute_stock($emparejamiento_id) {
        $members = $this->get_members($emparejamiento_id);
        $total = 0.0;
        $detalle = [];
        $warnings = [];

        foreach ($members as $m) {
            $pid = (int) $m['producto_base_id'];
            $elig = $this->eligibility($pid);
            $qty = 0.0;
            $mode = 'propio';

            if (!empty($elig['is_unitario']) && !empty($elig['grupo_id']) && class_exists('Riverso_Family_Module')) {
                $fam = Riverso_Family_Module::get_instance()->compute_family_stock((int) $elig['grupo_id']);
                $qty = (float) ($fam['stock_unidades'] ?? 0);
                $mode = 'familia';
                if (!empty($fam['warnings'])) {
                    $warnings = array_merge($warnings, (array) $fam['warnings']);
                }
            } else {
                $qty = $this->product_own_stock_units($pid);
            }

            $total += $qty;
            $detalle[] = [
                'producto_base_id' => $pid,
                'sku' => $m['canonical_sku'],
                'nombre' => $m['nombre_canonico'],
                'stock_unidades' => $qty,
                'modo' => $mode,
            ];
        }

        $group = $this->get($emparejamiento_id, false);
        $min = isset($group['stock_minimo']) && $group['stock_minimo'] !== null
            ? (int) $group['stock_minimo'] : null;
        $crit = isset($group['stock_critico']) && $group['stock_critico'] !== null
            ? (int) $group['stock_critico'] : null;

        return [
            'stock_unidades' => $total,
            'detalle' => $detalle,
            'warnings' => $warnings,
            'stock_minimo' => $min,
            'stock_critico' => $crit,
            'alerta' => ($min !== null && $total <= $min) ? 1 : 0,
            'critico' => ($crit !== null && $total <= $crit) ? 1 : 0,
        ];
    }

    private function product_own_stock_units($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $producto_base_id = absint($producto_base_id);
        $bal = 0.0;
        if (class_exists('Riverso_Stock_Service')) {
            $bal = (float) Riverso_Stock_Service::get_instance()->get_balance($producto_base_id);
        } else {
            $bal = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(cantidad),0) FROM {$prefix}producto_ubicacion WHERE product_id = %d",
                $producto_base_id
            ));
        }
        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT stock_abierto, stock_abierto_habilitado FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if ($pb && !empty($pb['stock_abierto_habilitado'])) {
            $bal += (float) ($pb['stock_abierto'] ?? 0);
        }
        return $bal;
    }

    public function list_stock_alerts() {
        global $wpdb;
        $prefix = $this->prefix();
        $rows = $wpdb->get_results(
            "SELECT id FROM {$prefix}emparejamientos
             WHERE activo = 1 AND emparejar_stock = 1
             ORDER BY id ASC",
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $g = $this->get((int) $r['id'], false);
            $stock = $this->compute_stock((int) $r['id']);
            if (empty($stock['alerta']) && empty($stock['critico'])) {
                // Incluir siempre para listado completo en Bodega opcionalmente;
                // por defecto devolvemos todos con umbral configurado.
            }
            $out[] = array_merge([
                'id' => (int) $g['id'],
                'codigo' => $g['codigo'],
                'nombre' => $g['nombre'],
            ], $stock);
            // Marcar stock_usados si hay umbrales
            if (($g['stock_minimo'] !== null || $g['stock_critico'] !== null) && empty($g['stock_usados'])) {
                $wpdb->update(
                    "{$prefix}emparejamientos",
                    ['stock_usados' => 1],
                    ['id' => (int) $g['id']],
                    ['%d'],
                    ['%d']
                );
            }
        }
        return $out;
    }

    /* ===================== Decisión folio ===================== */

    public function set_decision($producto_base_id, $decision) {
        global $wpdb;
        $prefix = $this->prefix();
        $producto_base_id = absint($producto_base_id);
        $decision = sanitize_key($decision);
        if (!in_array($decision, ['no_requiere', 'requiere'], true)) {
            return new WP_Error('invalid', 'Decisión inválida');
        }
        $wpdb->update(
            "{$prefix}producto_base",
            ['emparejamiento_decision' => $decision],
            ['id' => $producto_base_id],
            ['%s'],
            ['%d']
        );
        return true;
    }

    public function get_decision($producto_base_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT emparejamiento_decision FROM {$this->prefix()}producto_base WHERE id = %d",
            absint($producto_base_id)
        ));
    }

    /**
     * ¿Debe bloquear el folio por emparejamiento?
     * Se evalúa sobre el target de precio (unitario).
     */
    public function needs_emparejamiento_gate($producto_base_id) {
        $producto_base_id = absint($producto_base_id);
        if (!$producto_base_id || !$this->is_eligible($producto_base_id)) {
            return false;
        }
        if ($this->product_has_emparejamiento($producto_base_id)) {
            return false;
        }
        $decision = (string) $this->get_decision($producto_base_id);
        return $decision !== 'no_requiere';
    }

    /* ===================== Auto-remover no elegibles (T8) ===================== */

    public function purge_ineligible_member($producto_base_id) {
        $producto_base_id = absint($producto_base_id);
        $emp = $this->get_of_product($producto_base_id);
        if (!$emp) {
            return null;
        }
        if ($this->is_eligible($producto_base_id)) {
            return null;
        }
        $this->remove_member((int) $emp['id'], $producto_base_id);
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('emparejamiento.member_purged', 'emparejamiento', (int) $emp['id'], [
                'details' => 'Miembro ya no elegible (pack/familia no unitaria)',
                'producto_base_id' => $producto_base_id,
            ]);
        }
        return [
            'removed' => true,
            'emparejamiento_id' => (int) $emp['id'],
            'nombre' => $emp['nombre'] ?? '',
            'producto_base_id' => $producto_base_id,
        ];
    }

    public function on_family_member_changed($grupo_id, $producto_base_id) {
        $this->purge_ineligible_member(absint($producto_base_id));
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        $this->check_manage();
        $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
        wp_send_json_success(['items' => $this->list_emparejamientos($search)]);
    }

    public function ajax_get() {
        $this->check_manage();
        $id = absint($_POST['id'] ?? 0);
        $row = $this->get($id);
        if (!$row) {
            wp_send_json_error(['message' => 'No encontrado']);
        }
        wp_send_json_success(['emparejamiento' => $row]);
    }

    public function ajax_create() {
        $this->check_manage();
        $result = $this->create([
            'nombre' => wp_unslash($_POST['nombre'] ?? ''),
            'codigo' => wp_unslash($_POST['codigo'] ?? ''),
            'emparejar_precios' => !empty($_POST['emparejar_precios']),
            'emparejar_stock' => !empty($_POST['emparejar_stock']),
            'stock_minimo' => $_POST['stock_minimo'] ?? null,
            'stock_critico' => $_POST['stock_critico'] ?? null,
            'notas' => wp_unslash($_POST['notas'] ?? ''),
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $member_id = absint($_POST['producto_base_id'] ?? 0);
        if ($member_id) {
            $add = $this->add_member((int) $result['id'], $member_id, ['skip_conflict_check' => true]);
            if (!is_wp_error($add)) {
                $result = $add;
            }
        }
        wp_send_json_success(['emparejamiento' => $result]);
    }

    public function ajax_update() {
        $this->check_manage();
        $id = absint($_POST['id'] ?? 0);
        $confirm = !empty($_POST['confirm_disable']);
        $result = $this->update($id, [
            'nombre' => isset($_POST['nombre']) ? wp_unslash($_POST['nombre']) : null,
            'emparejar_precios' => array_key_exists('emparejar_precios', $_POST)
                ? !empty($_POST['emparejar_precios']) : null,
            'emparejar_stock' => array_key_exists('emparejar_stock', $_POST)
                ? !empty($_POST['emparejar_stock']) : null,
            'stock_minimo' => array_key_exists('stock_minimo', $_POST) ? $_POST['stock_minimo'] : null,
            'stock_critico' => array_key_exists('stock_critico', $_POST) ? $_POST['stock_critico'] : null,
            'notas' => isset($_POST['notas']) ? wp_unslash($_POST['notas']) : null,
        ], $confirm);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
                'data' => $result->get_error_data(),
            ]);
        }
        wp_send_json_success(['emparejamiento' => $result]);
    }

    public function ajax_delete() {
        $this->check_manage();
        $id = absint($_POST['id'] ?? 0);
        $this->soft_delete($id);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_add_member() {
        $this->check_manage();
        $emp_id = absint($_POST['emparejamiento_id'] ?? 0);
        $pid = absint($_POST['producto_base_id'] ?? 0);
        $opts = [
            'confirm' => !empty($_POST['confirm']),
            'p_asignado' => isset($_POST['p_asignado']) ? (float) $_POST['p_asignado'] : null,
        ];
        $result = $this->add_member($emp_id, $pid, $opts);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
                'data' => $result->get_error_data(),
            ]);
        }
        wp_send_json_success(['emparejamiento' => $result]);
    }

    public function ajax_remove_member() {
        $this->check_manage();
        $result = $this->remove_member(
            absint($_POST['emparejamiento_id'] ?? 0),
            absint($_POST['producto_base_id'] ?? 0)
        );
        wp_send_json_success(['emparejamiento' => $result]);
    }

    public function ajax_resolve_conflict() {
        $this->check_manage();
        $emp_id = absint($_POST['emparejamiento_id'] ?? 0);
        $pid = absint($_POST['producto_base_id'] ?? 0);
        $p = (float) ($_POST['p_asignado'] ?? 0);
        if ($p <= 0) {
            wp_send_json_error(['message' => 'Precio requerido']);
        }

        $member_ids = array_map(static function ($m) {
            return (int) ($m['producto_base_id'] ?? 0);
        }, $this->get_members($emp_id));
        if ($pid && !in_array($pid, $member_ids, true)) {
            $member_ids[] = $pid;
        }
        $preview = $this->margin_preview_for_products($member_ids, $p);

        if (empty($_POST['confirm'])) {
            $conflict = $this->detect_price_conflict($emp_id, $pid);
            wp_send_json_success([
                'needs_confirm' => true,
                'preview' => $preview,
                'p_asignado' => $p,
                'conflict' => $conflict,
            ]);
        }
        $result = $this->add_member($emp_id, $pid, [
            'confirm' => true,
            'p_asignado' => $p,
            'skip_conflict_check' => true,
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
                'data' => $result->get_error_data(),
            ]);
        }
        wp_send_json_success(['emparejamiento' => $result, 'preview' => $preview]);
    }

    public function ajax_preview_price() {
        $this->check_manage();
        $emp_id = absint($_POST['emparejamiento_id'] ?? 0);
        $p = isset($_POST['p_asignado']) ? (float) $_POST['p_asignado'] : null;
        wp_send_json_success([
            'preview' => $this->margin_preview_for_group($emp_id, $p),
        ]);
    }

    public function ajax_apply_price() {
        $this->check_manage();
        $emp_id = absint($_POST['emparejamiento_id'] ?? 0);
        $p = (float) ($_POST['p_asignado'] ?? 0);
        if (empty($_POST['confirm'])) {
            wp_send_json_success([
                'needs_confirm' => true,
                'preview' => $this->margin_preview_for_group($emp_id, $p),
                'message' => '¿Seguro de aplicar este precio a todos los miembros?',
            ]);
        }
        $result = $this->apply_shared_price($emp_id, $p, [
            'source_type' => 'manual',
            'notas' => 'Aplicado desde interfaz de emparejamientos',
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_search_candidates() {
        $this->check_manage();
        $palabras_raw = $_POST['palabras'] ?? [];
        if (!is_array($palabras_raw)) {
            $palabras_raw = $palabras_raw !== '' && $palabras_raw !== null
                ? [$palabras_raw]
                : [];
        }
        $palabras = [];
        $seen = [];
        foreach ($palabras_raw as $palabra) {
            $w = trim(sanitize_text_field(wp_unslash((string) $palabra)));
            if ($w === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($w, 'UTF-8') : strtolower($w);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $palabras[] = $w;
            if (count($palabras) >= 10) {
                break;
            }
        }
        $filters = [
            'q' => trim(sanitize_text_field(wp_unslash($_POST['q'] ?? ''))),
            'nombre' => trim(sanitize_text_field(wp_unslash($_POST['nombre'] ?? ''))),
            'sku' => trim(sanitize_text_field(wp_unslash($_POST['sku'] ?? ''))),
            'barcode' => trim(sanitize_text_field(wp_unslash($_POST['barcode'] ?? ''))),
            'codigo_proveedor' => trim(sanitize_text_field(wp_unslash($_POST['codigo_proveedor'] ?? ''))),
            'palabras' => $palabras,
            'mode' => sanitize_key($_POST['mode'] ?? 'quick'),
            'limit' => absint($_POST['limit'] ?? 40),
        ];
        wp_send_json_success(['items' => $this->search_candidate_items($filters)]);
    }

    /**
     * Busca candidatos elegibles por SKU, barcode y/o código proveedor (modo quick),
     * o por filtros separados (modo advanced: nombre, sku, barcode, codigo_proveedor, palabras).
     *
     * @param array $filters
     * @return array
     */
    public function search_candidate_items(array $filters) {
        global $wpdb;
        $prefix = $this->prefix();
        $mode = ($filters['mode'] ?? 'quick') === 'advanced' ? 'advanced' : 'quick';
        $limit = min(80, max(10, absint($filters['limit'] ?? 40)));

        $q = trim((string) ($filters['q'] ?? ''));
        $nombre = trim((string) ($filters['nombre'] ?? ''));
        $sku = trim((string) ($filters['sku'] ?? ''));
        $barcode = trim((string) ($filters['barcode'] ?? ''));
        $codigo_proveedor = trim((string) ($filters['codigo_proveedor'] ?? ''));
        $palabras = is_array($filters['palabras'] ?? null) ? $filters['palabras'] : [];

        $where = [
            "(pb.deleted_at IS NULL OR pb.deleted_at = '0000-00-00 00:00:00')",
            '(pb.archived_at IS NULL OR pb.archived_at = \'0000-00-00 00:00:00\')',
        ];
        $params = [];
        $order_extra = '';

        if ($mode === 'advanced') {
            $has = false;
            if ($nombre !== '') {
                if (strlen($nombre) < 2) {
                    return [];
                }
                $where[] = 'pb.nombre_canonico LIKE %s';
                $params[] = '%' . $wpdb->esc_like($nombre) . '%';
                $has = true;
            }
            if ($sku !== '') {
                $where[] = '(pb.canonical_sku = %s OR pb.canonical_sku LIKE %s)';
                $params[] = $sku;
                $params[] = '%' . $wpdb->esc_like($sku) . '%';
                $has = true;
            }
            if ($barcode !== '') {
                $compact = preg_replace('/[\s\-\/]+/', '', $barcode);
                $where[] = '(cb.codigo = %s OR cb.codigo LIKE %s OR REPLACE(REPLACE(REPLACE(IFNULL(cb.codigo,\'\'), \'-\', \'\'), \' \', \'\'), \'/\', \'\') LIKE %s)';
                $params[] = $barcode;
                $params[] = '%' . $wpdb->esc_like($barcode) . '%';
                $params[] = '%' . $wpdb->esc_like($compact) . '%';
                $has = true;
            }
            if ($codigo_proveedor !== '') {
                $compact = preg_replace('/[\s\-\/]+/', '', $codigo_proveedor);
                $where[] = '(pp.codigo_proveedor = %s OR pp.codigo_proveedor LIKE %s OR REPLACE(REPLACE(REPLACE(IFNULL(pp.codigo_proveedor,\'\'), \'-\', \'\'), \' \', \'\'), \'/\', \'\') LIKE %s)';
                $params[] = $codigo_proveedor;
                $params[] = '%' . $wpdb->esc_like($codigo_proveedor) . '%';
                $params[] = '%' . $wpdb->esc_like($compact) . '%';
                $has = true;
            }
            foreach ($palabras as $palabra) {
                $w = trim((string) $palabra);
                if ($w === '' || strlen($w) < 1) {
                    continue;
                }
                $where[] = 'pb.nombre_canonico LIKE %s';
                $params[] = '%' . $wpdb->esc_like($w) . '%';
                $has = true;
            }
            if (!$has) {
                return [];
            }
        } else {
            // Quick: barcode, SKU o código proveedor (no nombre).
            if ($q === '') {
                return [];
            }
            if (strlen($q) < 2 && !ctype_digit($q)) {
                return [];
            }
            $like = '%' . $wpdb->esc_like($q) . '%';
            $compact = preg_replace('/[\s\-\/]+/', '', $q);
            $compact_like = '%' . $wpdb->esc_like($compact) . '%';
            $where[] = '(
                pb.id = %d
                OR pb.canonical_sku = %s
                OR pb.canonical_sku LIKE %s
                OR cb.codigo = %s
                OR cb.codigo LIKE %s
                OR REPLACE(REPLACE(REPLACE(IFNULL(cb.codigo,\'\'), \'-\', \'\'), \' \', \'\'), \'/\', \'\') LIKE %s
                OR pp.codigo_proveedor = %s
                OR pp.codigo_proveedor LIKE %s
                OR REPLACE(REPLACE(REPLACE(IFNULL(pp.codigo_proveedor,\'\'), \'-\', \'\'), \' \', \'\'), \'/\', \'\') LIKE %s
            )';
            $params = array_merge($params, [
                absint($q),
                $q,
                $like,
                $q,
                $like,
                $compact_like,
                $q,
                $like,
                $compact_like,
            ]);
            $order_extra = "CASE
                    WHEN pb.id = %d THEN 0
                    WHEN pb.canonical_sku = %s THEN 1
                    WHEN cb.codigo = %s THEN 2
                    WHEN pp.codigo_proveedor = %s THEN 3
                    WHEN pb.canonical_sku LIKE %s THEN 4
                    ELSE 5
                END ASC,";
            $params[] = absint($q);
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $wpdb->esc_like($q) . '%';
        }

        $sql = "SELECT DISTINCT pb.id, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}codigo_barra cb ON (
                cb.producto_base_id = pb.id
                OR (
                    pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
                    AND cb.producto_base_id IS NULL
                    AND (cb.sku_local = pb.canonical_sku OR cb.pending_sku = pb.canonical_sku)
                )
             )
             LEFT JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.activo = 1
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$order_extra} pb.canonical_sku ASC
             LIMIT %d";
        $params[] = $limit * 3;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

        $items = [];
        $seen = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $elig = $this->eligibility($pid);
            if (empty($elig['eligible'])) {
                continue;
            }
            $paired = $this->get_of_product($pid);
            $price = $this->local_price($pid);
            $codes = $this->candidate_code_hints($pid);
            $items[] = [
                'id' => $pid,
                'sku' => $r['canonical_sku'],
                'nombre' => $r['nombre_canonico'],
                'is_unitario' => !empty($elig['is_unitario']),
                'emparejamiento_id' => $paired ? (int) $paired['id'] : 0,
                'emparejamiento_nombre' => $paired['nombre'] ?? '',
                'p_asignado' => ($price && $price['p_asignado'] !== null && $price['p_asignado'] !== '')
                    ? (float) $price['p_asignado'] : null,
                'c_ref' => ($price && $price['c_ref'] !== null && $price['c_ref'] !== '')
                    ? (float) $price['c_ref'] : null,
                'barcodes' => $codes['barcodes'],
                'codigos_proveedor' => $codes['codigos_proveedor'],
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }

    /**
     * @param int $producto_base_id
     * @return array{barcodes: string[], codigos_proveedor: string[]}
     */
    private function candidate_code_hints($producto_base_id) {
        global $wpdb;
        $prefix = $this->prefix();
        $pid = absint($producto_base_id);
        $barcodes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT codigo FROM {$prefix}codigo_barra
             WHERE producto_base_id = %d AND codigo IS NOT NULL AND codigo <> ''
             ORDER BY codigo ASC LIMIT 5",
            $pid
        )) ?: [];
        $sku = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT canonical_sku FROM {$prefix}producto_base WHERE id = %d",
            $pid
        ));
        if ($sku !== '') {
            $extra = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT codigo FROM {$prefix}codigo_barra
                 WHERE producto_base_id IS NULL
                   AND (sku_local = %s OR pending_sku = %s)
                   AND codigo IS NOT NULL AND codigo <> ''
                 ORDER BY codigo ASC LIMIT 5",
                $sku,
                $sku
            )) ?: [];
            $barcodes = array_values(array_unique(array_merge($barcodes, $extra)));
        }
        $proveedor = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT codigo_proveedor FROM {$prefix}producto_proveedor
             WHERE producto_base_id = %d AND activo = 1
               AND codigo_proveedor IS NOT NULL AND codigo_proveedor <> ''
             ORDER BY codigo_proveedor ASC LIMIT 5",
            $pid
        )) ?: [];
        return [
            'barcodes' => array_slice(array_map('strval', $barcodes), 0, 5),
            'codigos_proveedor' => array_slice(array_map('strval', $proveedor), 0, 5),
        ];
    }

    public function ajax_answer_need() {
        $this->check_manage();
        $pid = absint($_POST['producto_base_id'] ?? 0);
        $decision = sanitize_key($_POST['decision'] ?? '');
        $result = $this->set_decision($pid, $decision);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['ok' => true, 'decision' => $decision]);
    }

    public function ajax_create_and_assign() {
        $this->check_manage();
        $pid = absint($_POST['producto_base_id'] ?? 0);
        $created = $this->create([
            'nombre' => wp_unslash($_POST['nombre'] ?? ''),
            'emparejar_precios' => !empty($_POST['emparejar_precios']),
            'emparejar_stock' => !empty($_POST['emparejar_stock']),
        ]);
        if (is_wp_error($created)) {
            wp_send_json_error(['message' => $created->get_error_message()]);
        }
        if ($pid) {
            $add = $this->add_member((int) $created['id'], $pid, [
                'skip_conflict_check' => true,
            ]);
            if (is_wp_error($add)) {
                wp_send_json_error(['message' => $add->get_error_message()]);
            }
            $created = $add;
        }
        wp_send_json_success(['emparejamiento' => $created]);
    }

    public function ajax_stock_status() {
        $this->check_manage();
        wp_send_json_success(['items' => $this->list_stock_alerts()]);
    }

    public function ajax_suggest_names() {
        $this->check_manage();
        $ids = [];
        if (isset($_POST['producto_base_ids']) && is_array($_POST['producto_base_ids'])) {
            $ids = array_map('absint', $_POST['producto_base_ids']);
        } elseif (!empty($_POST['producto_base_ids'])) {
            $raw = sanitize_text_field((string) $_POST['producto_base_ids']);
            $ids = array_map('absint', preg_split('/[\s,;]+/', $raw) ?: []);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            wp_send_json_success(['suggestions' => []]);
        }
        $suggestions = [];
        if (class_exists('Riverso_Family_Module')) {
            $suggestions = Riverso_Family_Module::get_instance()
                ->suggest_family_names_from_products($ids);
        }
        wp_send_json_success(['suggestions' => $suggestions]);
    }

    public function ajax_create_member() {
        $this->check_manage();
        $nombre = sanitize_text_field(wp_unslash($_POST['nombre'] ?? ''));
        $sku = sanitize_text_field($_POST['canonical_sku'] ?? '');
        if ($nombre === '') {
            wp_send_json_error(['message' => 'Indicá el nombre del producto nuevo']);
        }
        if ($sku !== '' && !preg_match('/^\d{1,6}$/', $sku)) {
            wp_send_json_error(['message' => 'SKU Local debe ser numérico y máximo 6 dígitos']);
        }
        if (!class_exists('Riverso_Family_Commercial_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-family-commercial-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Family_Commercial_Service')) {
            wp_send_json_error(['message' => 'Servicio de productos no disponible']);
        }

        $created = Riverso_Family_Commercial_Service::get_instance()->create_local_product([
            'canonical_sku' => $sku,
            'nombre' => $nombre,
            'origen_datos' => 'emparejamiento_member_create',
        ]);
        if (is_wp_error($created)) {
            wp_send_json_error(['message' => $created->get_error_message()]);
        }

        $producto_base_id = (int) ($created['producto_base_id'] ?? 0);
        if ($producto_base_id) {
            $this->set_decision($producto_base_id, 'requiere');
        }

        $emp_id = absint($_POST['emparejamiento_id'] ?? 0);
        $emparejamiento = null;
        if ($emp_id && $producto_base_id) {
            $add = $this->add_member($emp_id, $producto_base_id, [
                'skip_conflict_check' => true,
            ]);
            if (is_wp_error($add)) {
                wp_send_json_success([
                    'product' => $created,
                    'producto_base_id' => $producto_base_id,
                    'message' => 'Producto creado; no se pudo agregar: ' . $add->get_error_message(),
                    'add_error' => $add->get_error_message(),
                ]);
            }
            $emparejamiento = $add;
        }

        wp_send_json_success([
            'product' => $created,
            'producto_base_id' => $producto_base_id,
            'emparejamiento' => $emparejamiento,
            'message' => $emp_id
                ? 'Miembro creado y agregado'
                : 'Producto creado; se agregará al guardar el emparejamiento',
        ]);
    }

    public function ajax_preview_members() {
        $this->check_manage();
        $ids = [];
        if (isset($_POST['producto_base_ids']) && is_array($_POST['producto_base_ids'])) {
            $ids = array_map('absint', $_POST['producto_base_ids']);
        }
        $p = isset($_POST['p_asignado']) && $_POST['p_asignado'] !== ''
            ? (float) $_POST['p_asignado'] : null;
        $conflict = $this->detect_price_conflict_among($ids);
        wp_send_json_success([
            'preview' => $this->margin_preview_for_products($ids, $p),
            'conflict' => $conflict,
        ]);
    }

    public function ajax_save() {
        $this->check_manage();
        $member_ids = [];
        if (isset($_POST['member_ids']) && is_array($_POST['member_ids'])) {
            $member_ids = array_map('absint', $_POST['member_ids']);
        } elseif (!empty($_POST['member_ids'])) {
            $raw = sanitize_text_field((string) $_POST['member_ids']);
            $member_ids = array_map('absint', preg_split('/[\s,;]+/', $raw) ?: []);
        }

        $result = $this->save_full([
            'id' => absint($_POST['id'] ?? 0),
            'nombre' => wp_unslash($_POST['nombre'] ?? ''),
            'codigo' => wp_unslash($_POST['codigo'] ?? ''),
            'emparejar_precios' => !empty($_POST['emparejar_precios']),
            'emparejar_stock' => !empty($_POST['emparejar_stock']),
            'stock_minimo' => array_key_exists('stock_minimo', $_POST) ? $_POST['stock_minimo'] : null,
            'stock_critico' => array_key_exists('stock_critico', $_POST) ? $_POST['stock_critico'] : null,
            'notas' => isset($_POST['notas']) ? wp_unslash($_POST['notas']) : null,
            'member_ids' => $member_ids,
            'p_asignado' => isset($_POST['p_asignado']) && $_POST['p_asignado'] !== ''
                ? (float) $_POST['p_asignado'] : null,
            'confirm_disable' => !empty($_POST['confirm_disable']),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
                'data' => $result->get_error_data(),
            ]);
        }
        wp_send_json_success(['emparejamiento' => $result]);
    }
}
