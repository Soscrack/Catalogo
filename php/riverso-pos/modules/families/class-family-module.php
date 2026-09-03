<?php
/**
 * Módulo de gestión de grupos de equivalencia (familias de productos).
 *
 * Gestiona el CRUD de familias y sus miembros a través de AJAX.
 * Soporta familias exactas (mismo ítem, distinto envase), stock agregado
 * y sugerencias Mamut con revisión humana.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-family-suggestion-service.php';
require_once __DIR__ . '/class-unit-product-service.php';

class Riverso_Family_Module {

    private static $instance = null;

    /** Tipos canónicos de sustitución (UI + backend). */
    const TIPOS_CANONICOS = ['exacta', 'preferida', 'complementaria'];

    /** Mapa de valores legacy → canónicos. */
    const TIPOS_LEGACY_MAP = [
        'compatible' => 'complementaria',
        'sustituto' => 'preferida',
        'preferido' => 'preferida',
        'exacta' => 'exacta',
        'preferida' => 'preferida',
        'complementaria' => 'complementaria',
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_families_list', [$this, 'ajax_list_families']);
        add_action('wp_ajax_riverso_families_get', [$this, 'ajax_get_family']);
        add_action('wp_ajax_riverso_families_create', [$this, 'ajax_create_family']);
        add_action('wp_ajax_riverso_families_update', [$this, 'ajax_update_family']);
        add_action('wp_ajax_riverso_families_delete', [$this, 'ajax_delete_family']);
        add_action('wp_ajax_riverso_families_add_member', [$this, 'ajax_add_member']);
        add_action('wp_ajax_riverso_families_remove_member', [$this, 'ajax_remove_member']);
        add_action('wp_ajax_riverso_families_set_member_envase', [$this, 'ajax_set_member_envase']);
        add_action('wp_ajax_riverso_families_search_candidates', [$this, 'ajax_search_candidates']);
        add_action('wp_ajax_riverso_families_create_local_from_member', [$this, 'ajax_create_local_from_member']);
        add_action('wp_ajax_riverso_families_tree', [$this, 'ajax_family_tree']);
        add_action('wp_ajax_riverso_families_stock', [$this, 'ajax_family_stock']);
        add_action('wp_ajax_riverso_families_suggest', [$this, 'ajax_suggest_families']);
        add_action('wp_ajax_riverso_families_accept_suggestion', [$this, 'ajax_accept_suggestion']);
        add_action('wp_ajax_riverso_families_unit_get', [$this, 'ajax_unit_get']);
        add_action('wp_ajax_riverso_families_unit_configure', [$this, 'ajax_unit_configure']);
        add_action('wp_ajax_riverso_families_unit_toggle', [$this, 'ajax_unit_toggle']);
        add_action('wp_ajax_riverso_families_unit_convert', [$this, 'ajax_unit_convert']);
        add_action('wp_ajax_riverso_families_unit_price_preview', [$this, 'ajax_unit_price_preview']);
        add_action('wp_ajax_riverso_families_unit_link_preview', [$this, 'ajax_unit_link_preview']);
        add_action('wp_ajax_riverso_families_suggest_names', [$this, 'ajax_suggest_names']);
        add_action('wp_ajax_riverso_families_pack_merge_preview', [$this, 'ajax_pack_merge_preview']);
        add_action('wp_ajax_riverso_families_pack_merge_confirm', [$this, 'ajax_pack_merge_confirm']);
    }

    /**
     * Normaliza tipo_sustitucion a whitelist canónica.
     *
     * @param string $tipo
     * @param string $default
     * @return string
     */
    public static function normalize_tipo($tipo, $default = 'exacta') {
        $tipo = strtolower(trim((string) $tipo));
        if (isset(self::TIPOS_LEGACY_MAP[$tipo])) {
            return self::TIPOS_LEGACY_MAP[$tipo];
        }
        return in_array($default, self::TIPOS_CANONICOS, true) ? $default : 'exacta';
    }

    /**
     * AJAX: Listar todas las familias activas con conteo de miembros, stock y preview compacto.
     */
    public function ajax_list_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $search = trim(sanitize_text_field($_POST['search'] ?? ''));
        $has_search = ($search !== '' && (strlen($search) >= 2 || ctype_digit($search)));

        $where_search = '';
        $search_params = [];
        if ($has_search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $compact = preg_replace('/[\s\-\/]+/', '', $search);
            $compact_like = '%' . $wpdb->esc_like($compact) . '%';

            $where_search = " AND (
                g.nombre LIKE %s
                OR g.codigo_grupo LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$prefix}equivalence_members em_s
                    INNER JOIN {$prefix}producto_base pb_s ON pb_s.id = em_s.producto_base_id
                    WHERE em_s.grupo_id = g.id AND em_s.activo = 1
                      AND pb_s.deleted_at IS NULL
                      AND (
                          pb_s.nombre_canonico LIKE %s
                          OR pb_s.canonical_sku LIKE %s
                          OR pb_s.canonical_sku = %s
                          OR REPLACE(REPLACE(REPLACE(IFNULL(pb_s.canonical_sku, ''), '-', ''), ' ', ''), '/', '') LIKE %s
                      )
                )
                OR EXISTS (
                    SELECT 1 FROM {$prefix}producto_proveedor pp_s
                    LEFT JOIN {$prefix}equivalence_members em_pp
                        ON em_pp.producto_base_id = pp_s.producto_base_id AND em_pp.activo = 1
                    WHERE pp_s.activo = 1
                      AND (em_pp.grupo_id = g.id OR pp_s.grupo_id = g.id)
                      AND (
                          pp_s.codigo_proveedor LIKE %s
                          OR pp_s.codigo_proveedor = %s
                          OR REPLACE(REPLACE(REPLACE(IFNULL(pp_s.codigo_proveedor, ''), '-', ''), ' ', ''), '/', '') LIKE %s
                      )
                )
                OR EXISTS (
                    SELECT 1 FROM {$prefix}equivalence_members em_cb
                    INNER JOIN {$prefix}producto_base pb_cb ON pb_cb.id = em_cb.producto_base_id
                    INNER JOIN {$prefix}codigo_barra cb_s ON (
                        cb_s.producto_base_id = pb_cb.id
                        OR (
                            cb_s.producto_base_id IS NULL
                            AND pb_cb.canonical_sku IS NOT NULL AND pb_cb.canonical_sku <> ''
                            AND (cb_s.sku_local = pb_cb.canonical_sku OR cb_s.pending_sku = pb_cb.canonical_sku)
                        )
                    )
                    WHERE em_cb.grupo_id = g.id AND em_cb.activo = 1
                      AND cb_s.activo = 1
                      AND (
                          cb_s.codigo = %s
                          OR cb_s.codigo LIKE %s
                          OR REPLACE(REPLACE(REPLACE(IFNULL(cb_s.codigo, ''), '-', ''), ' ', ''), '/', '') LIKE %s
                      )
                )
                OR EXISTS (
                    SELECT 1 FROM {$prefix}equivalence_members em_woo
                    INNER JOIN {$prefix}producto_base pb_woo ON pb_woo.id = em_woo.producto_base_id
                    INNER JOIN {$wpdb->postmeta} pm_woo ON pm_woo.meta_key = '_sku'
                      AND pm_woo.post_id = COALESCE(NULLIF(pb_woo.woocommerce_variation_id, 0), pb_woo.woocommerce_product_id)
                    WHERE em_woo.grupo_id = g.id AND em_woo.activo = 1
                      AND (pm_woo.meta_value = %s OR pm_woo.meta_value LIKE %s)
                )
            )";
            $search_params = [
                $like,
                $like,
                $like,
                $like,
                $search,
                $compact_like,
                $like,
                $search,
                $compact_like,
                $search,
                $like,
                $compact_like,
                $search,
                $like,
            ];
        }

        $sql = "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion, g.activo,
                    g.unit_producto_base_id, g.es_producto_unitario,
                    ub.canonical_sku AS unit_sku,
                    COUNT(em.id) as miembros_count
             FROM {$prefix}equivalence_groups g
             LEFT JOIN {$prefix}equivalence_members em ON em.grupo_id = g.id AND em.activo = 1
             LEFT JOIN {$prefix}producto_base ub ON ub.id = g.unit_producto_base_id AND ub.deleted_at IS NULL
             WHERE g.activo = 1{$where_search}
             GROUP BY g.id
             ORDER BY g.nombre ASC";

        if ($has_search) {
            $families = $wpdb->get_results($wpdb->prepare($sql, $search_params), ARRAY_A);
        } else {
            $families = $wpdb->get_results($sql, ARRAY_A);
        }

        $family_ids = array_map('intval', array_column($families ?: [], 'id'));
        $members_by_group = $this->batch_list_family_members($family_ids);
        $families_with_rule = [];
        if ($family_ids && class_exists('Riverso_Price_Rules_Module')) {
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $placeholders = implode(',', array_fill(0, count($family_ids), '%d'));
            $assigned = $wpdb->get_col($wpdb->prepare(
                "SELECT target_id FROM {$prefix}price_rule_assignments
                 WHERE target_tipo = 'familia' AND target_id IN ({$placeholders})",
                ...$family_ids
            ));
            $families_with_rule = array_fill_keys(array_map('intval', $assigned ?: []), true);
        }

        foreach ($families as &$family) {
            $family['tipo_sustitucion'] = self::normalize_tipo($family['tipo_sustitucion'] ?? 'exacta');
            $stock = $this->compute_family_stock(intval($family['id']));
            $family['stock_unidades'] = $stock['stock_unidades'];
            $family['stock_warnings'] = $stock['warnings'];
            $family['stock_completo'] = empty($stock['warnings']);
            $family['unit_sku'] = $family['unit_sku'] ?: null;
            $unit_id = !empty($family['unit_producto_base_id'])
                ? intval($family['unit_producto_base_id']) : 0;
            $family['unit_producto_base_id'] = $unit_id ?: null;
            $family['falta_regla_precio'] = !empty($family['es_producto_unitario'])
                && empty($families_with_rule[intval($family['id'])]);

            $members = $members_by_group[intval($family['id'])] ?? [];
            foreach ($members as &$member) {
                $member['es_unitario_familia'] = $unit_id > 0
                    && intval($member['producto_base_id']) === $unit_id;
            }
            unset($member);
            $family['members'] = $members;
        }
        unset($family);

        wp_send_json_success([
            'families' => $families ?: [],
            'search' => $has_search ? $search : '',
            'total' => count($families ?: []),
        ]);
    }

    /**
     * Miembros compactos por familia (batch, sin N+1).
     *
     * @param int[] $grupo_ids
     * @return array<int, array<int, array>>
     */
    private function batch_list_family_members(array $grupo_ids) {
        $grupo_ids = array_values(array_filter(array_map('intval', $grupo_ids)));
        if (!$grupo_ids) {
            return [];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $placeholders = implode(',', array_fill(0, count($grupo_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT em.grupo_id, em.producto_base_id, pb.canonical_sku, pb.nombre_canonico,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id IN ({$placeholders})
               AND em.activo = 1
               AND pb.deleted_at IS NULL
             ORDER BY em.grupo_id ASC, em.prioridad DESC, pb.nombre_canonico ASC",
            $grupo_ids
        ), ARRAY_A) ?: [];

        $sku_online_map = $this->batch_list_online_skus($rows);
        $by_group = [];

        foreach ($rows as $row) {
            $gid = intval($row['grupo_id']);
            $pid = intval($row['producto_base_id']);
            $sku_local = trim((string) ($row['canonical_sku'] ?? ''));
            $sku_online = (string) ($sku_online_map[$pid] ?? '');

            if (!isset($by_group[$gid])) {
                $by_group[$gid] = [];
            }

            $by_group[$gid][] = [
                'producto_base_id' => $pid,
                'nombre_canonico' => (string) ($row['nombre_canonico'] ?? ''),
                'sku_local' => $sku_local,
                'sku_online' => $sku_online,
                'es_local' => $sku_local !== '',
                'es_online' => $sku_online !== ''
                    || absint($row['woocommerce_product_id'] ?? 0) > 0
                    || absint($row['woocommerce_variation_id'] ?? 0) > 0,
            ];
        }

        return $by_group;
    }

    /**
     * SKUs Woo batch para filas de producto_base (clave: producto_base_id).
     *
     * @param array $items
     * @return array<int, string>
     */
    private function batch_list_online_skus(array $items) {
        global $wpdb;
        $map = [];
        $woo_to_pb = [];

        foreach ($items as $item) {
            $pb_id = (int) ($item['producto_base_id'] ?? $item['id'] ?? 0);
            if ($pb_id <= 0) {
                continue;
            }
            $map[$pb_id] = '';
            $variation_id = (int) ($item['woocommerce_variation_id'] ?? 0);
            $product_id = (int) ($item['woocommerce_product_id'] ?? 0);
            $woo_id = $variation_id > 0 ? $variation_id : $product_id;
            if ($woo_id > 0) {
                $woo_to_pb[$woo_id] = $pb_id;
            }
        }

        if (!$woo_to_pb) {
            return $map;
        }

        $woo_ids = array_keys($woo_to_pb);
        $placeholders = implode(',', array_fill(0, count($woo_ids), '%d'));
        $query_params = array_merge(['_sku'], $woo_ids);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
               AND post_id IN ({$placeholders})",
            $query_params
        ), ARRAY_A) ?: [];

        foreach ($rows as $row) {
            $woo_id = (int) ($row['post_id'] ?? 0);
            $pb_id = $woo_to_pb[$woo_id] ?? 0;
            if ($pb_id > 0) {
                $map[$pb_id] = (string) ($row['meta_value'] ?? '');
            }
        }

        return $map;
    }

    /**
     * AJAX: Obtener detalle de una familia con sus miembros y stock.
     */
    public function ajax_get_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        if (!$family) {
            wp_send_json_error(['message' => 'Familia no encontrada']);
        }

        $family['tipo_sustitucion'] = self::normalize_tipo($family['tipo_sustitucion'] ?? 'exacta');

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.id, em.producto_base_id, em.prioridad, em.es_reemplazo_preferido,
                    pb.canonical_sku, pb.nombre_canonico, pb.stock_abierto,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id,
                    pb.es_unidad_minima, pb.unit_of_grupo_id
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY em.prioridad DESC, pb.nombre_canonico ASC",
            $grupo_id
        ), ARRAY_A);

        $unit_id = intval($family['unit_producto_base_id'] ?? 0);
        $stock = $this->compute_family_stock($grupo_id, $members);
        $by_id = [];
        foreach ($stock['members'] as $sm) {
            $by_id[intval($sm['producto_base_id'])] = $sm;
        }
        foreach ($members as &$m) {
            $sid = intval($m['producto_base_id']);
            if (isset($by_id[$sid])) {
                $m = array_merge($m, $by_id[$sid]);
            }
            $this->enrich_product_sku_flags($m);
            $m['es_unitario_familia'] = $unit_id > 0 && $sid === $unit_id;
        }
        unset($m);

        $family['members'] = $members;
        $family['unit_producto_base_id'] = $unit_id ?: null;
        $family['pending'] = $this->get_pending_suppliers($grupo_id);
        $family['stock'] = $stock;
        $family['pack_conflicts'] = $this->detect_pack_qty_conflicts($grupo_id, $members);
        wp_send_json_success(['family' => $family]);
    }

    /**
     * AJAX: Crear nueva familia.
     */
    public function ajax_create_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $codigo_grupo = sanitize_text_field($_POST['codigo_grupo'] ?? '');
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $tipo_sustitucion = self::normalize_tipo($_POST['tipo_sustitucion'] ?? 'exacta');
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        if (!$nombre) {
            wp_send_json_error(['message' => 'El nombre es requerido']);
        }

        $codigo_grupo = $this->make_unique_codigo_grupo($nombre, $codigo_grupo);
        if (is_wp_error($codigo_grupo)) {
            wp_send_json_error(['message' => $codigo_grupo->get_error_message()]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->insert(
            "{$prefix}equivalence_groups",
            [
                'codigo_grupo' => $codigo_grupo,
                'nombre' => $nombre,
                'tipo_sustitucion' => $tipo_sustitucion,
                'notas' => $notas,
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );

        $grupo_id = $wpdb->insert_id;
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'No se pudo crear la familia']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_created', 'equivalence_groups', $grupo_id, [
                'codigo_grupo' => $codigo_grupo,
                'nombre' => $nombre,
                'tipo_sustitucion' => $tipo_sustitucion,
            ]);
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        wp_send_json_success(['family' => $family]);
    }

    /**
     * Genera codigo_grupo único. Si $codigo está vacío, usa slug del nombre.
     *
     * @param string $nombre
     * @param string $codigo
     * @return string|WP_Error
     */
    private function make_unique_codigo_grupo($nombre, $codigo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $codigo = trim((string) $codigo);
        if ($codigo !== '') {
            $base = strtoupper(preg_replace('/[^A-Z0-9_\-]/i', '', $codigo));
            $base = substr($base, 0, 80);
            if ($base === '') {
                return new WP_Error('invalid_codigo', 'El código no es válido (usa letras, números, _ o -)');
            }
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}equivalence_groups WHERE codigo_grupo = %s LIMIT 1",
                $base
            ));
            if ($exists) {
                return new WP_Error('codigo_exists', 'El código de grupo ya existe');
            }
            return $base;
        }

        $slug = sanitize_title((string) $nombre);
        $slug = strtoupper(preg_replace('/[^A-Z0-9_\-]/i', '', str_replace('-', '_', $slug)));
        $slug = substr($slug, 0, 80);
        if ($slug === '') {
            return new WP_Error('invalid_slug', 'No se pudo generar un código desde el nombre. Escribe un código manualmente.');
        }

        $candidate = $slug;
        $n = 2;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups WHERE codigo_grupo = %s LIMIT 1",
            $candidate
        ))) {
            $suffix = '-' . $n;
            $candidate = substr($slug, 0, max(1, 80 - strlen($suffix))) . $suffix;
            $n++;
            if ($n > 9999) {
                return new WP_Error('slug_exhausted', 'No hay códigos disponibles para ese nombre');
            }
        }
        return $candidate;
    }

    /**
     * AJAX: Actualizar familia existente.
     */
    public function ajax_update_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $tipo_sustitucion = self::normalize_tipo($_POST['tipo_sustitucion'] ?? 'exacta');
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        if (!$grupo_id || !$nombre) {
            wp_send_json_error(['message' => 'grupo_id y nombre son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Si pasa a exacta, validar unicidad de miembros.
        if ($tipo_sustitucion === 'exacta') {
            $conflict = $this->find_exacta_member_conflict($grupo_id);
            if ($conflict) {
                wp_send_json_error([
                    'message' => 'No se puede marcar como exacta: el SKU '
                        . ($conflict['canonical_sku'] ?: $conflict['producto_base_id'])
                        . ' ya pertenece a la familia exacta '
                        . ($conflict['otra_familia'] ?: '#' . $conflict['otro_grupo_id']),
                ]);
            }
        }

        $wpdb->update(
            "{$prefix}equivalence_groups",
            [
                'nombre' => $nombre,
                'tipo_sustitucion' => $tipo_sustitucion,
                'notas' => $notas,
            ],
            ['id' => $grupo_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_updated', 'equivalence_groups', $grupo_id, [
                'nombre' => $nombre,
                'tipo_sustitucion' => $tipo_sustitucion,
            ]);
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        wp_send_json_success(['family' => $family]);
    }

    /**
     * AJAX: Eliminar (desactivar) familia con confirmación previa en UI.
     */
    public function ajax_delete_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family) {
            wp_send_json_error(['message' => 'Familia no encontrada']);
        }
        if (empty($family['activo'])) {
            wp_send_json_success(['message' => 'La familia ya estaba eliminada', 'grupo_id' => $grupo_id]);
        }

        $members_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}equivalence_members WHERE grupo_id = %d AND activo = 1",
            $grupo_id
        ));

        $unit_id = intval($family['unit_producto_base_id'] ?? 0);
        if ($unit_id > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_base
                 SET es_unidad_minima = 0, unit_of_grupo_id = NULL
                 WHERE id = %d",
                $unit_id
            ));
        }

        $wpdb->update(
            "{$prefix}equivalence_members",
            ['activo' => 0],
            ['grupo_id' => $grupo_id, 'activo' => 1],
            ['%d'],
            ['%d', '%d']
        );

        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}equivalence_groups
             SET activo = 0, es_producto_unitario = 0, unit_producto_base_id = NULL
             WHERE id = %d",
            $grupo_id
        ));

        if (class_exists('Riverso_Unit_Product_Service')) {
            Riverso_Unit_Product_Service::get_instance()->cancel_missing_rule_tasks(
                $grupo_id,
                'Familia eliminada'
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_deleted', 'equivalence_groups', $grupo_id, [
                'nombre' => $family['nombre'] ?? '',
                'codigo_grupo' => $family['codigo_grupo'] ?? '',
                'members_count' => $members_count,
                'unit_producto_base_id' => $unit_id ?: null,
            ]);
        }

        wp_send_json_success([
            'message' => 'Familia eliminada',
            'grupo_id' => $grupo_id,
            'members_deactivated' => $members_count,
        ]);
    }

    /**
     * AJAX: Definir/actualizar cantidad_unidades de envase de un miembro.
     */
    public function ajax_set_member_envase() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $cantidad = floatval($_POST['cantidad_unidades'] ?? 0);

        if (!$grupo_id || !$producto_base_id) {
            wp_send_json_error(['message' => 'grupo_id y producto_base_id requeridos']);
        }
        if ($cantidad <= 0) {
            wp_send_json_error(['message' => 'cantidad_unidades debe ser mayor a 0']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $is_member = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_members
             WHERE grupo_id = %d AND producto_base_id = %d AND activo = 1 LIMIT 1",
            $grupo_id,
            $producto_base_id
        ));
        if (!$is_member) {
            wp_send_json_error(['message' => 'El producto no es miembro activo de esta familia']);
        }

        $envase_id = $this->upsert_envase_for_member([
            'producto_base_id' => $producto_base_id,
            'cantidad_unidades' => $cantidad,
            'origen_datos' => 'family_editor',
        ]);
        if (!$envase_id) {
            wp_send_json_error(['message' => 'No se pudo guardar el envase']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_envase_set', 'equivalence_groups', $grupo_id, [
                'producto_base_id' => $producto_base_id,
                'cantidad_unidades' => $cantidad,
                'envase_id' => $envase_id,
            ]);
        }

        $stock = $this->compute_family_stock($grupo_id);
        $members = $this->load_family_members_enriched($grupo_id);
        wp_send_json_success([
            'envase_id' => $envase_id,
            'cantidad_unidades' => $cantidad,
            'stock' => $stock,
            'pack_conflicts' => $this->detect_pack_qty_conflicts($grupo_id, $members),
            'message' => 'Cantidad de envase guardada',
        ]);
    }

    /**
     * AJAX: Agregar miembro a familia.
     */
    public function ajax_add_member() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $prioridad = absint($_POST['prioridad'] ?? 100);
        $es_preferido = !empty($_POST['es_preferido']) ? 1 : 0;

        if (!$grupo_id || !$producto_base_id) {
            wp_send_json_error(['message' => 'grupo_id y producto_base_id son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_members WHERE grupo_id = %d AND producto_base_id = %d AND activo = 1",
            $grupo_id,
            $producto_base_id
        ));

        if ($exists) {
            wp_send_json_error(['message' => 'El producto ya es miembro de esta familia']);
        }

        $tipo = $wpdb->get_var($wpdb->prepare(
            "SELECT tipo_sustitucion FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ));
        $tipo = self::normalize_tipo($tipo ?: 'exacta');

        if ($tipo === 'exacta') {
            $other = $this->get_exacta_family_of_product($producto_base_id);
            if ($other && intval($other['grupo_id']) !== $grupo_id) {
                wp_send_json_error([
                    'message' => 'El producto ya pertenece a la familia exacta "'
                        . ($other['nombre'] ?: $other['codigo_grupo'])
                        . '". Un SKU solo puede estar en una familia exacta.',
                ]);
            }
        }

        // Reactivar soft-delete si existía.
        $inactive = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_members WHERE grupo_id = %d AND producto_base_id = %d AND activo = 0",
            $grupo_id,
            $producto_base_id
        ));
        if ($inactive) {
            $wpdb->update(
                "{$prefix}equivalence_members",
                [
                    'activo' => 1,
                    'prioridad' => $prioridad,
                    'es_reemplazo_preferido' => $es_preferido,
                ],
                ['id' => intval($inactive)],
                ['%d', '%d', '%d'],
                ['%d']
            );
            $member_id = intval($inactive);
        } else {
            $wpdb->insert(
                "{$prefix}equivalence_members",
                [
                    'grupo_id' => $grupo_id,
                    'producto_base_id' => $producto_base_id,
                    'prioridad' => $prioridad,
                    'es_reemplazo_preferido' => $es_preferido,
                    'activo' => 1,
                ],
                ['%d', '%d', '%d', '%d', '%d']
            );
            $member_id = $wpdb->insert_id;
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_added', 'equivalence_members', $member_id, [
                'grupo_id' => $grupo_id,
                'producto_base_id' => $producto_base_id,
            ]);
        }

        if (class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->resolve_family_assigned($producto_base_id);
        }

        $parked_suggestions = 0;
        if (class_exists('Riverso_Unit_Product_Service')) {
            $parked_suggestions = Riverso_Unit_Product_Service::get_instance()
                ->annotate_parked_barcode_tasks_for_family($grupo_id);
        }

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT em.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.id = %d",
            $member_id
        ), ARRAY_A);

        $members = $this->load_family_members_enriched($grupo_id);
        wp_send_json_success([
            'member' => $member,
            'parked_barcode_suggestions' => $parked_suggestions,
            'pack_conflicts' => $this->detect_pack_qty_conflicts($grupo_id, $members),
        ]);
    }

    /**
     * AJAX: Quitar miembro de familia.
     */
    public function ajax_remove_member() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $member_id = absint($_POST['member_id'] ?? 0);
        if (!$member_id) {
            wp_send_json_error(['message' => 'member_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->update(
            "{$prefix}equivalence_members",
            ['activo' => 0],
            ['id' => $member_id],
            ['%d'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_removed', 'equivalence_members', $member_id);
        }

        wp_send_json_success(['message' => 'Miembro removido']);
    }

    /**
     * AJAX: Buscar productos candidatos para agregar a una familia.
     * Oculta los que ya están en familia salvo coincidencia exacta (SKU/barcode/código/ID).
     */
    public function ajax_search_candidates() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $q = trim(sanitize_text_field($_POST['q'] ?? $_POST['search'] ?? ''));
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $for_unit = !empty($_POST['for_unit']);
        $limit = min(30, max(5, absint($_POST['limit'] ?? 15)));

        if ($q === '') {
            wp_send_json_success(['items' => []]);
        }
        if (strlen($q) < 2 && !ctype_digit($q)) {
            wp_send_json_success(['items' => [], 'message' => 'Escribe al menos 2 caracteres']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($q) . '%';
        $compact = preg_replace('/[\s\-\/]+/', '', $q);
        $compact_like = '%' . $wpdb->esc_like($compact) . '%';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT pb.id, pb.canonical_sku, pb.nombre_canonico,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id,
                    pb.es_unidad_minima, pb.unit_of_grupo_id
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
             WHERE pb.deleted_at IS NULL
               AND pb.archived_at IS NULL
               " . ($for_unit ? "AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''" : '') . "
               AND (
                    pb.id = %d
                    OR pb.canonical_sku = %s
                    OR pb.canonical_sku LIKE %s
                    OR pb.nombre_canonico LIKE %s
                    OR cb.codigo = %s
                    OR cb.codigo LIKE %s
                    OR REPLACE(REPLACE(REPLACE(IFNULL(cb.codigo,''), '-', ''), ' ', ''), '/', '') LIKE %s
                    OR pp.codigo_proveedor = %s
                    OR pp.codigo_proveedor LIKE %s
                    OR REPLACE(REPLACE(REPLACE(IFNULL(pp.codigo_proveedor,''), '-', ''), ' ', ''), '/', '') LIKE %s
               )
             ORDER BY
                CASE
                    WHEN pb.id = %d THEN 0
                    WHEN pb.canonical_sku = %s THEN 1
                    WHEN cb.codigo = %s THEN 2
                    WHEN pp.codigo_proveedor = %s THEN 3
                    WHEN pb.canonical_sku LIKE %s THEN 4
                    ELSE 5
                END ASC,
                pb.nombre_canonico ASC
             LIMIT %d",
            absint($q),
            $q,
            $like,
            $like,
            $q,
            $like,
            $compact_like,
            $q,
            $like,
            $compact_like,
            absint($q),
            $q,
            $q,
            $q,
            $wpdb->esc_like($q) . '%',
            $limit * 3
        ), ARRAY_A) ?: [];

        // Complementar con productos solo-online hallados por SKU Woo / IDs Woo.
        if (!$for_unit) {
            $rows = $this->merge_woo_sku_candidate_rows($rows, $q, $limit * 3);
        }

        $items = [];
        foreach ($rows as $row) {
            $pid = absint($row['id']);
            $this->enrich_product_sku_flags($row);
            $sku = (string) ($row['sku_local'] ?? '');
            $es_local = !empty($row['es_local']);
            $es_online = !empty($row['es_online']);

            if ($for_unit) {
                if (!$es_local) {
                    continue;
                }

                $unit_of_grupo = absint($row['unit_of_grupo_id'] ?? 0);
                $unit_familia_nombre = null;
                if ($unit_of_grupo > 0) {
                    $unit_familia_nombre = (string) $wpdb->get_var($wpdb->prepare(
                        "SELECT nombre FROM {$prefix}equivalence_groups WHERE id = %d",
                        $unit_of_grupo
                    ));
                }

                $can_select = true;
                $aviso = null;
                if ($unit_of_grupo > 0 && $grupo_id && $unit_of_grupo === $grupo_id) {
                    $aviso = 'Ya es el producto unitario de esta familia';
                } elseif ($unit_of_grupo > 0 && $grupo_id && $unit_of_grupo !== $grupo_id) {
                    $can_select = false;
                    $aviso = 'Unidad mínima de la familia «' . ($unit_familia_nombre ?: $unit_of_grupo) . '»';
                } elseif (!empty($row['es_unidad_minima'])) {
                    $aviso = 'Ya es unidad mínima (sin familia unitaria asignada)';
                }

                $items[] = [
                    'id' => $pid,
                    'canonical_sku' => $sku,
                    'sku_local' => $sku,
                    'sku_online' => (string) ($row['sku_online'] ?? ''),
                    'nombre_canonico' => (string) ($row['nombre_canonico'] ?? ''),
                    'es_local' => $es_local,
                    'es_online' => $es_online,
                    'es_unidad_minima' => (int) ($row['es_unidad_minima'] ?? 0),
                    'unit_of_grupo_id' => $unit_of_grupo ?: null,
                    'unit_familia_nombre' => $unit_familia_nombre,
                    'can_select' => $can_select,
                    'aviso' => $aviso,
                ];

                if (count($items) >= $limit) {
                    break;
                }
                continue;
            }

            $exact = $this->is_exact_candidate_match($pid, $sku, $q, (string) ($row['sku_online'] ?? ''));

            $familia = $wpdb->get_row($wpdb->prepare(
                "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion
                 FROM {$prefix}equivalence_members em
                 INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
                 WHERE em.producto_base_id = %d AND em.activo = 1 AND g.activo = 1
                 ORDER BY (g.tipo_sustitucion = 'exacta') DESC, em.id ASC
                 LIMIT 1",
                $pid
            ), ARRAY_A);

            $in_any_family = !empty($familia);
            $in_this_family = $in_any_family && $grupo_id && absint($familia['id']) === $grupo_id;

            // No exacto + ya en familia → ocultar
            if (!$exact && $in_any_family) {
                continue;
            }

            $can_add = !$in_any_family;
            $aviso = null;
            if ($in_this_family) {
                $aviso = 'Ya es miembro de esta familia';
                $can_add = false;
            } elseif ($in_any_family) {
                $aviso = 'Ya está en la familia «' . ($familia['nombre'] ?: $familia['codigo_grupo']) . '»';
                $can_add = false;
            }

            $items[] = [
                'id' => $pid,
                'canonical_sku' => $sku,
                'sku_local' => $sku,
                'sku_online' => (string) ($row['sku_online'] ?? ''),
                'nombre_canonico' => (string) ($row['nombre_canonico'] ?? ''),
                'es_local' => $es_local,
                'es_online' => $es_online,
                'exacto' => $exact,
                'can_add' => $can_add,
                'aviso' => $aviso,
                'familia' => $familia ? [
                    'id' => absint($familia['id']),
                    'nombre' => (string) ($familia['nombre'] ?? ''),
                    'codigo_grupo' => (string) ($familia['codigo_grupo'] ?? ''),
                    'tipo_sustitucion' => self::normalize_tipo($familia['tipo_sustitucion'] ?? 'exacta'),
                ] : null,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        wp_send_json_success(['items' => $items, 'q' => $q]);
    }

    /**
     * Añade sku_local, sku_online, es_local, es_online a un producto_base row.
     *
     * @param array $row
     */
    private function enrich_product_sku_flags(array &$row) {
        $sku_local = trim((string) ($row['canonical_sku'] ?? ''));
        $sku_online = $this->resolve_online_sku($row);
        $row['sku_local'] = $sku_local;
        $row['sku_online'] = $sku_online;
        $row['es_local'] = $sku_local !== '';
        $row['es_online'] = $sku_online !== ''
            || absint($row['woocommerce_product_id'] ?? 0) > 0
            || absint($row['woocommerce_variation_id'] ?? 0) > 0;
    }

    /**
     * Complementa candidatos locales con producto_base vinculados a SKU/ID Woo.
     *
     * @param array  $rows
     * @param string $q
     * @param int    $limit
     * @return array
     */
    private function merge_woo_sku_candidate_rows(array $rows, $q, $limit = 45) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $q = trim((string) $q);
        if ($q === '') {
            return $rows;
        }

        $seen = [];
        foreach ($rows as $r) {
            $seen[absint($r['id'] ?? 0)] = true;
        }

        $woo_ids = [];
        if (function_exists('wc_get_product_id_by_sku')) {
            $exact_woo = absint(wc_get_product_id_by_sku($q));
            if ($exact_woo > 0) {
                $woo_ids[] = $exact_woo;
            }
        }

        // ID Woo numérico directo.
        if (ctype_digit($q)) {
            $woo_ids[] = absint($q);
        }

        // LIKE en postmeta _sku (parcial), acotado.
        if (function_exists('wc_get_product') && strlen($q) >= 2) {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $meta_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_sku'
                   AND pm.meta_value LIKE %s
                   AND p.post_type IN ('product','product_variation')
                   AND p.post_status NOT IN ('trash','auto-draft')
                 ORDER BY (pm.meta_value = %s) DESC, pm.meta_value ASC
                 LIMIT 20",
                $like,
                $q
            )) ?: [];
            foreach ($meta_ids as $mid) {
                $woo_ids[] = absint($mid);
            }
        }

        $woo_ids = array_values(array_unique(array_filter(array_map('absint', $woo_ids))));
        if (!$woo_ids) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($woo_ids), '%d'));
        $sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico,
                       pb.woocommerce_product_id, pb.woocommerce_variation_id,
                       pb.es_unidad_minima, pb.unit_of_grupo_id
                FROM {$prefix}producto_base pb
                WHERE pb.deleted_at IS NULL
                  AND pb.archived_at IS NULL
                  AND (
                    pb.woocommerce_product_id IN ($placeholders)
                    OR pb.woocommerce_variation_id IN ($placeholders)
                  )
                LIMIT %d";
        $params = array_merge($woo_ids, $woo_ids, [max(5, min(45, (int) $limit))]);
        $extra = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        foreach ($extra as $row) {
            $id = absint($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            // Preferir coincidencias Woo al inicio.
            array_unshift($rows, $row);
        }

        return $rows;
    }

    /**
     * AJAX: Asigna SKU local numérico a un producto solo-online (misma fila producto_base).
     */
    public function ajax_create_local_from_member() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $sku = sanitize_text_field($_POST['canonical_sku'] ?? '');

        if ($producto_base_id <= 0) {
            wp_send_json_error(['message' => 'producto_base_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico,
                    woocommerce_product_id, woocommerce_variation_id,
                    deleted_at, archived_at
             FROM {$prefix}producto_base
             WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);

        if (!$pb || !empty($pb['deleted_at'])) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        if (!empty($pb['archived_at'])) {
            wp_send_json_error(['message' => 'El producto está archivado']);
        }

        $this->enrich_product_sku_flags($pb);
        if (empty($pb['es_online'])) {
            wp_send_json_error(['message' => 'El producto no está vinculado a WooCommerce (online)']);
        }
        if (!empty($pb['es_local'])) {
            wp_send_json_error(['message' => 'El producto ya tiene SKU local: ' . $pb['sku_local']]);
        }

        if ($sku === '') {
            $sku = $this->allocate_next_local_sku();
            if (is_wp_error($sku)) {
                wp_send_json_error(['message' => $sku->get_error_message()]);
            }
        }

        if (!preg_match('/^\d{1,6}$/', $sku)) {
            wp_send_json_error(['message' => 'SKU Local debe ser numérico y máximo 6 dígitos']);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s AND id <> %d LIMIT 1",
            $sku,
            $producto_base_id
        ));
        if ($existing) {
            wp_send_json_error(['message' => 'El SKU Local ' . $sku . ' ya existe']);
        }

        $ok = $wpdb->update(
            "{$prefix}producto_base",
            [
                'canonical_sku' => $sku,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $producto_base_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($ok === false) {
            wp_send_json_error(['message' => 'No se pudo asignar el SKU local']);
        }

        if (class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->close_counterpart_task(
                $producto_base_id,
                'crear_contraparte_local'
            );
        } else {
            $wpdb->update(
                "{$prefix}tareas",
                ['estado' => 'completada'],
                [
                    'referencia_tipo' => 'producto_base',
                    'referencia_id' => $producto_base_id,
                    'tipo' => 'crear_contraparte_local',
                ],
                ['%s'],
                ['%s', '%d', '%s']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_local_created', 'producto_base', $producto_base_id, [
                'grupo_id' => $grupo_id ?: null,
                'canonical_sku' => $sku,
                'nombre_canonico' => $pb['nombre_canonico'] ?? '',
            ]);
        }

        // Dispara sync Facto (y otros listeners): sin esto el SKU local no entra al outbox.
        $event_payload = [
            'id' => $producto_base_id,
            'canonical_sku' => $sku,
            'nombre_canonico' => (string) ($pb['nombre_canonico'] ?? ''),
        ];
        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('product.updated', $event_payload, [
                'source' => 'families_create_local_from_member',
            ]);
        } else {
            do_action('riverso_product_updated', $event_payload, [
                'source' => 'families_create_local_from_member',
            ]);
        }

        $fresh = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico,
                    woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        $this->enrich_product_sku_flags($fresh);

        wp_send_json_success([
            'message' => 'SKU local asignado: ' . $sku,
            'product' => [
                'id' => absint($fresh['id']),
                'producto_base_id' => absint($fresh['id']),
                'canonical_sku' => (string) ($fresh['sku_local'] ?? ''),
                'sku_local' => (string) ($fresh['sku_local'] ?? ''),
                'sku_online' => (string) ($fresh['sku_online'] ?? ''),
                'nombre_canonico' => (string) ($fresh['nombre_canonico'] ?? ''),
                'es_local' => !empty($fresh['es_local']),
                'es_online' => !empty($fresh['es_online']),
            ],
        ]);
    }

    /**
     * Siguiente SKU local numérico disponible (1–6 dígitos).
     *
     * @return string|WP_Error
     */
    private function allocate_next_local_sku() {
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

        for ($i = $next; $i <= 999999; $i++) {
            $taken = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s LIMIT 1",
                (string) $i
            ));
            if (!$taken) {
                return (string) $i;
            }
        }

        return new WP_Error('sku_exhausted', 'No hay SKUs locales disponibles');
    }

    /**
     * SKU WooCommerce del producto vinculado (variación o padre).
     *
     * @param array $item
     * @return string
     */
    private function resolve_online_sku(array $item) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        $variation_id = (int) ($item['woocommerce_variation_id'] ?? 0);
        $product_id = (int) ($item['woocommerce_product_id'] ?? 0);
        $woo_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($woo_id <= 0) {
            return '';
        }
        $product = wc_get_product($woo_id);
        if (!$product) {
            return '';
        }
        return (string) ($product->get_sku() ?: '');
    }

    /**
     * ¿La query coincide exactamente con ID, SKU, barcode o código proveedor?
     */
    private function is_exact_candidate_match($producto_base_id, $sku, $q, $sku_online = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $q = trim((string) $q);
        if ($q === '') {
            return false;
        }
        if (ctype_digit($q) && absint($q) === absint($producto_base_id)) {
            return true;
        }
        if ($sku !== '' && strcasecmp($sku, $q) === 0) {
            return true;
        }
        if ($sku_online !== '' && strcasecmp($sku_online, $q) === 0) {
            return true;
        }
        $hit = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$prefix}codigo_barra
             WHERE activo = 1 AND codigo = %s
               AND (
                    producto_base_id = %d
                    OR (
                        producto_base_id IS NULL AND %s <> ''
                        AND (sku_local = %s OR pending_sku = %s)
                    )
               )
             LIMIT 1",
            $q,
            absint($producto_base_id),
            $sku,
            $sku,
            $sku
        ));
        if ($hit) {
            return true;
        }
        $hit = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$prefix}producto_proveedor
             WHERE activo = 1 AND producto_base_id = %d AND codigo_proveedor = %s
             LIMIT 1",
            absint($producto_base_id),
            $q
        ));
        return (bool) $hit;
    }

    /**
     * AJAX: Obtener árbol de familias con sus miembros y stock.
     */
    public function ajax_family_tree() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $families = $wpdb->get_results(
            "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion,
                    COUNT(em.id) as miembros_count
             FROM {$prefix}equivalence_groups g
             LEFT JOIN {$prefix}equivalence_members em ON em.grupo_id = g.id AND em.activo = 1
             WHERE g.activo = 1
             GROUP BY g.id
             ORDER BY g.nombre ASC",
            ARRAY_A
        );

        foreach ($families as &$family) {
            $family['tipo_sustitucion'] = self::normalize_tipo($family['tipo_sustitucion'] ?? 'exacta');
            $family['members'] = $wpdb->get_results($wpdb->prepare(
                "SELECT em.id, em.producto_base_id, em.prioridad, em.es_reemplazo_preferido,
                        pb.canonical_sku, pb.nombre_canonico
                 FROM {$prefix}equivalence_members em
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
                 WHERE em.grupo_id = %d AND em.activo = 1
                 ORDER BY em.es_reemplazo_preferido DESC, em.prioridad DESC",
                $family['id']
            ), ARRAY_A);

            $stock = $this->compute_family_stock(intval($family['id']), $family['members']);
            $family['stock_unidades'] = $stock['stock_unidades'];
            $family['stock_warnings'] = $stock['warnings'];
            $family['children'] = $family['members'];
        }
        unset($family);

        wp_send_json_success(['tree' => $families]);
    }

    /**
     * AJAX: Stock agregado de una familia.
     */
    public function ajax_family_stock() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }

        wp_send_json_success(['stock' => $this->compute_family_stock($grupo_id)]);
    }

    /**
     * AJAX: Sugerir familias Mamut (dry-run).
     */
    public function ajax_suggest_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $limit = absint($_POST['limit'] ?? 200);
        $path = sanitize_text_field($_POST['catalog_path'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $result = Riverso_Family_Suggestion_Service::suggest($path, $limit, $search);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /**
     * AJAX: Aceptar sugerencia → crea familia exacta + miembros locales + pendientes proveedor.
     *
     * Miembros con SKU local → equivalence_members (+ envase).
     * Códigos Mamut sin SKU local → producto_proveedor.grupo_id (pendiente).
     * Al vincular después el código a un producto local, se promueve a miembro.
     */
    public function ajax_accept_suggestion() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $raw = isset($_POST['suggestion']) ? wp_unslash($_POST['suggestion']) : '';
        if (is_string($raw)) {
            $suggestion = json_decode($raw, true);
        } else {
            $suggestion = is_array($raw) ? $raw : null;
        }

        if (!is_array($suggestion) || empty($suggestion['members']) || !is_array($suggestion['members'])) {
            wp_send_json_error(['message' => 'Sugerencia inválida']);
        }

        $nombre = sanitize_text_field($suggestion['nombre'] ?? '');
        $codigo = sanitize_text_field($suggestion['codigo_grupo_sugerido'] ?? '');
        if ($nombre === '' || $codigo === '') {
            wp_send_json_error(['message' => 'Nombre y código de familia son requeridos']);
        }

        $members = $suggestion['members'];
        $resolved = [];
        $pending = [];
        foreach ($members as $m) {
            if (!is_array($m) || floatval($m['cantidad_unidades'] ?? 0) <= 0) {
                continue;
            }
            if (!empty($m['producto_base_id'])) {
                $resolved[] = $m;
            } elseif (!empty($m['codigo_proveedor'])) {
                $pending[] = $m;
            }
        }

        if (count($resolved) + count($pending) < 2) {
            wp_send_json_error([
                'message' => 'Se requieren al menos 2 presentaciones (SKU local y/o códigos proveedor pendientes)',
            ]);
        }
        if (count($resolved) < 1 && count($pending) < 2) {
            wp_send_json_error([
                'message' => 'Hace falta al menos 1 SKU local, o 2+ códigos proveedor para dejar pendientes en la familia',
            ]);
        }

        foreach ($resolved as $m) {
            $other = $this->get_exacta_family_of_product(intval($m['producto_base_id']));
            if ($other) {
                wp_send_json_error([
                    'message' => 'El SKU ' . ($m['canonical_sku'] ?: $m['producto_base_id'])
                        . ' ya está en la familia exacta "' . ($other['nombre'] ?: $other['codigo_grupo']) . '"',
                ]);
            }
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $base_codigo = $codigo;
        $n = 1;
        while ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups WHERE codigo_grupo = %s",
            $codigo
        ))) {
            $codigo = $base_codigo . '-' . $n;
            $n++;
            if ($n > 50) {
                wp_send_json_error(['message' => 'No se pudo generar un código de grupo único']);
            }
        }

        $wpdb->insert(
            "{$prefix}equivalence_groups",
            [
                'codigo_grupo' => $codigo,
                'nombre' => $nombre,
                'tipo_sustitucion' => 'exacta',
                'notas' => sanitize_textarea_field(
                    'Creada desde sugerencia Mamut. key=' . ($suggestion['suggestion_key'] ?? '')
                    . '; pendientes=' . count($pending)
                ),
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );
        $grupo_id = intval($wpdb->insert_id);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'No se pudo crear la familia']);
        }

        $created_members = [];
        $envases = [];
        foreach ($resolved as $m) {
            $base_id = intval($m['producto_base_id']);
            $cantidad = floatval($m['cantidad_unidades']);
            $this->ensure_member($grupo_id, $base_id);
            $created_members[] = [
                'producto_base_id' => $base_id,
                'canonical_sku' => $m['canonical_sku'] ?? null,
                'cantidad_unidades' => $cantidad,
            ];
            $envase_id = $this->upsert_envase_for_member($m);
            if ($envase_id) {
                $envases[] = ['envase_id' => $envase_id, 'producto_base_id' => $base_id, 'cantidad_unidades' => $cantidad];
            }
        }

        $parked = [];
        $park_errors = [];
        foreach ($pending as $m) {
            $result = $this->park_pending_supplier($grupo_id, $m);
            if (is_wp_error($result)) {
                $park_errors[] = ($m['codigo_proveedor'] ?? '') . ': ' . $result->get_error_message();
            } else {
                $parked[] = $result;
            }
        }

        if (empty($created_members) && empty($parked)) {
            $wpdb->update("{$prefix}equivalence_groups", ['activo' => 0], ['id' => $grupo_id], ['%d'], ['%d']);
            wp_send_json_error([
                'message' => 'No se pudo guardar ningún miembro ni pendiente. '
                    . implode(' | ', $park_errors),
            ]);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_suggestion_accepted', 'equivalence_groups', $grupo_id, [
                'codigo_grupo' => $codigo,
                'members' => count($created_members),
                'pending' => count($parked),
                'envases' => count($envases),
                'park_errors' => $park_errors,
            ]);
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        $family['pending'] = $this->get_pending_suppliers($grupo_id);

        wp_send_json_success([
            'family' => $family,
            'members' => $created_members,
            'pending' => $parked,
            'envases' => $envases,
            'park_errors' => $park_errors,
            'stock' => $this->compute_family_stock($grupo_id),
            'message' => sprintf(
                'Familia creada con %d SKU local(es) y %d código(s) pendiente(s). Al vincular un pendiente a un producto local, entra solo a esta familia.',
                count($created_members),
                count($parked)
            ),
        ]);
    }

    /**
     * Asegura membresía activa en equivalence_members.
     */
    public function ensure_member($grupo_id, $producto_base_id, $prioridad = 100) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $producto_base_id = intval($producto_base_id);
        if (!$grupo_id || !$producto_base_id) {
            return false;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, activo FROM {$prefix}equivalence_members
             WHERE grupo_id = %d AND producto_base_id = %d LIMIT 1",
            $grupo_id,
            $producto_base_id
        ), ARRAY_A);

        if ($existing) {
            if (intval($existing['activo']) !== 1) {
                $wpdb->update(
                    "{$prefix}equivalence_members",
                    ['activo' => 1, 'prioridad' => $prioridad],
                    ['id' => intval($existing['id'])],
                    ['%d', '%d'],
                    ['%d']
                );
            }
            if (class_exists('Riverso_Product_Module')) {
                Riverso_Product_Module::get_instance()->resolve_family_assigned($producto_base_id);
            }
            return intval($existing['id']);
        }

        $wpdb->insert(
            "{$prefix}equivalence_members",
            [
                'grupo_id' => $grupo_id,
                'producto_base_id' => $producto_base_id,
                'prioridad' => $prioridad,
                'es_reemplazo_preferido' => 0,
                'activo' => 1,
            ],
            ['%d', '%d', '%d', '%d', '%d']
        );
        if (class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->resolve_family_assigned($producto_base_id);
        }
        if (class_exists('Riverso_Unit_Product_Service')) {
            Riverso_Unit_Product_Service::get_instance()
                ->annotate_parked_barcode_tasks_for_family($grupo_id);
        }
        return intval($wpdb->insert_id);
    }

    /**
     * Deja un código proveedor pendiente en la familia (sin SKU local aún).
     *
     * @param int   $grupo_id
     * @param array $member
     * @return array|WP_Error
     */
    public function park_pending_supplier($grupo_id, array $member) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        $codigo = sanitize_text_field($member['codigo_proveedor'] ?? '');
        $cantidad = floatval($member['cantidad_unidades'] ?? 0);

        if (!$grupo_id || $codigo === '' || $cantidad <= 0) {
            return new WP_Error('invalid_pending', 'Código o cantidad de envase inválidos');
        }

        $pp = null;
        if (!empty($member['producto_proveedor_id'])) {
            $pp = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
                intval($member['producto_proveedor_id'])
            ), ARRAY_A);
        }
        if (!$pp) {
            $pp = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}producto_proveedor
                 WHERE codigo_proveedor = %s AND activo = 1
                 ORDER BY (producto_base_id IS NOT NULL) ASC, id DESC
                 LIMIT 1",
                $codigo
            ), ARRAY_A);
        }

        if (!$pp) {
            return new WP_Error(
                'missing_pp',
                'No existe producto_proveedor para ' . $codigo . '. Impórtalo o créalo antes de aparcar.'
            );
        }

        // Ya tiene producto local: convertir en miembro en vez de pendiente.
        if (!empty($pp['producto_base_id'])) {
            $other = $this->get_exacta_family_of_product(intval($pp['producto_base_id']));
            if ($other && intval($other['grupo_id']) !== $grupo_id) {
                return new WP_Error(
                    'exacta_conflict',
                    $codigo . ' ya está ligado al SKU de otra familia exacta'
                );
            }
            $this->ensure_member($grupo_id, intval($pp['producto_base_id']));
            $this->upsert_envase_for_member(array_merge($member, [
                'producto_base_id' => intval($pp['producto_base_id']),
                'producto_proveedor_id' => intval($pp['id']),
            ]));
            return [
                'status' => 'promoted_existing',
                'producto_proveedor_id' => intval($pp['id']),
                'producto_base_id' => intval($pp['producto_base_id']),
                'codigo_proveedor' => $codigo,
                'cantidad_unidades' => $cantidad,
            ];
        }

        $note_tag = 'familia_pending_qty=' . $cantidad;
        $notas = trim((string) ($pp['notas'] ?? ''));
        if (strpos($notas, 'familia_pending_qty=') === false) {
            $notas = trim($notas . "\n" . $note_tag);
        } else {
            $notas = preg_replace('/familia_pending_qty=[\d.]+/', $note_tag, $notas);
        }

        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_proveedor SET
                producto_base_id = NULL,
                grupo_id = %d,
                factor_conversion = %f,
                assigned_to_family_at = %s,
                assigned_to_family_by = %d,
                notas = %s,
                match_origen = 'family_suggestion_pending',
                requires_human_review = 1
             WHERE id = %d",
            $grupo_id,
            $cantidad,
            current_time('mysql'),
            get_current_user_id(),
            $notas,
            intval($pp['id'])
        ));

        if ($ok === false) {
            return new WP_Error('db_error', $wpdb->last_error ?: 'No se pudo aparcar pendiente');
        }

        return [
            'status' => 'pending',
            'producto_proveedor_id' => intval($pp['id']),
            'codigo_proveedor' => $codigo,
            'cantidad_unidades' => $cantidad,
            'grupo_id' => $grupo_id,
        ];
    }

    /**
     * Al vincular un producto_proveedor (que estaba en familia) a un producto_base,
     * lo agrega como miembro de equivalence_members y crea el envase.
     *
     * @param int      $producto_base_id
     * @param int|null $grupo_id Grupo previo del PP (si ya se limpió, pasar explícito)
     * @param array    $pp_row   Fila producto_proveedor (opcional, para qty/código)
     * @return array|false
     */
    public function promote_pending_supplier_to_member($producto_base_id, $grupo_id = null, $pp_row = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);
        $grupo_id = $grupo_id !== null ? intval($grupo_id) : 0;

        if (!$producto_base_id) {
            return false;
        }

        if (!$grupo_id && is_array($pp_row) && !empty($pp_row['grupo_id'])) {
            $grupo_id = intval($pp_row['grupo_id']);
        }

        if (!$grupo_id) {
            return false;
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT id, tipo_sustitucion, activo FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        if (!$family || !intval($family['activo'])) {
            return false;
        }

        // Unicidad exacta
        if (self::normalize_tipo($family['tipo_sustitucion'] ?? '') === 'exacta') {
            $other = $this->get_exacta_family_of_product($producto_base_id);
            if ($other && intval($other['grupo_id']) !== $grupo_id) {
                return [
                    'promoted' => false,
                    'reason' => 'exacta_conflict',
                    'other_family' => $other,
                ];
            }
        }

        $member_id = $this->ensure_member($grupo_id, $producto_base_id);

        $cantidad = 0.0;
        $codigo = '';
        $pp_id = null;
        if (is_array($pp_row)) {
            $pp_id = !empty($pp_row['id']) ? intval($pp_row['id']) : null;
            $codigo = sanitize_text_field($pp_row['codigo_proveedor'] ?? '');
            $cantidad = floatval($pp_row['factor_conversion'] ?? 0);
            if ($cantidad <= 0 && !empty($pp_row['notas'])
                && preg_match('/familia_pending_qty=([\d.]+)/', $pp_row['notas'], $mm)
            ) {
                $cantidad = floatval($mm[1]);
            }
        }

        $envase_id = null;
        if ($cantidad > 0) {
            $envase_id = $this->upsert_envase_for_member([
                'producto_base_id' => $producto_base_id,
                'cantidad_unidades' => $cantidad,
                'codigo_proveedor' => $codigo,
                'producto_proveedor_id' => $pp_id,
            ]);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_pending_promoted', 'equivalence_groups', $grupo_id, [
                'producto_base_id' => $producto_base_id,
                'producto_proveedor_id' => $pp_id,
                'codigo_proveedor' => $codigo,
                'cantidad_unidades' => $cantidad,
                'member_id' => $member_id,
                'envase_id' => $envase_id,
            ]);
        }

        return [
            'promoted' => true,
            'grupo_id' => $grupo_id,
            'producto_base_id' => $producto_base_id,
            'member_id' => $member_id,
            'envase_id' => $envase_id,
            'cantidad_unidades' => $cantidad,
        ];
    }

    /**
     * Pendientes: códigos proveedor asignados a la familia sin producto_base.
     *
     * @param int $grupo_id
     * @return array
     */
    public function get_pending_suppliers($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor, pp.factor_conversion,
                    pp.notas, pp.assigned_to_family_at, pp.proveedor_id
             FROM {$prefix}producto_proveedor pp
             WHERE pp.grupo_id = %d AND pp.activo = 1
               AND (pp.producto_base_id IS NULL OR pp.producto_base_id = 0)
             ORDER BY pp.codigo_proveedor ASC",
            intval($grupo_id)
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $qty = floatval($row['factor_conversion'] ?? 0);
            if ($qty <= 0 && !empty($row['notas'])
                && preg_match('/familia_pending_qty=([\d.]+)/', $row['notas'], $mm)
            ) {
                $qty = floatval($mm[1]);
            }
            $row['cantidad_unidades'] = $qty > 0 ? $qty : null;
            $row['status'] = 'pending';
        }
        unset($row);
        return $rows;
    }

    /**
     * Persiste/actualiza envase con cantidad_unidades para un miembro de sugerencia.
     *
     * @param array $member
     * @return int|null envase_id
     */
    private function upsert_envase_for_member(array $member) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $base_id = intval($member['producto_base_id'] ?? 0);
        $cantidad = floatval($member['cantidad_unidades'] ?? 0);
        $codigo = sanitize_text_field($member['codigo_proveedor'] ?? '');
        if (!$base_id || $cantidad <= 0) {
            return null;
        }

        $existing_id = null;
        if ($codigo !== '') {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}envases
                 WHERE producto_base_id = %d AND codigo_proveedor = %s
                 LIMIT 1",
                $base_id,
                $codigo
            ));
        }
        if (!$existing_id) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}envases
                 WHERE producto_base_id = %d AND ABS(cantidad_unidades - %f) < 0.0001 AND activo = 1
                 LIMIT 1",
                $base_id,
                $cantidad
            ));
        }

        $proveedor_id = null;
        $producto_proveedor_id = !empty($member['producto_proveedor_id'])
            ? intval($member['producto_proveedor_id'])
            : null;
        if ($producto_proveedor_id) {
            $proveedor_id = $wpdb->get_var($wpdb->prepare(
                "SELECT proveedor_id FROM {$prefix}producto_proveedor WHERE id = %d",
                $producto_proveedor_id
            ));
        }

        $data = [
            'producto_base_id' => $base_id,
            'cantidad_unidades' => $cantidad,
            'tipo_envase' => 'envase',
            'es_vendible' => 1,
            'lleva_stock_propio' => 1,
            'permite_apertura' => 1,
            'origen_datos' => sanitize_text_field($member['origen_datos'] ?? 'familia_mamut_suggestion') ?: 'familia_mamut_suggestion',
            'requires_human_review' => 0,
            'review_status' => 'aprobado',
            'activo' => 1,
        ];
        if ($codigo !== '') {
            $data['codigo_proveedor'] = $codigo;
        }
        if ($producto_proveedor_id) {
            $data['producto_proveedor_id'] = $producto_proveedor_id;
        }
        if ($proveedor_id) {
            $data['proveedor_id'] = intval($proveedor_id);
        }
        if (!empty($member['woocommerce_variation_id'])) {
            $data['woocommerce_variation_id'] = intval($member['woocommerce_variation_id']);
        }

        // Columnas opcionales pueden no existir en installs antiguos: filtrar.
        $data = $this->filter_envase_columns($data);

        if ($existing_id) {
            $wpdb->update("{$prefix}envases", $data, ['id' => intval($existing_id)]);
            return intval($existing_id);
        }

        $ok = $wpdb->insert("{$prefix}envases", $data);
        return $ok ? intval($wpdb->insert_id) : null;
    }

    /**
     * @param array $data
     * @return array
     */
    private function filter_envase_columns(array $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_envases';
        $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
        if (!$cols) {
            return $data;
        }
        $allowed = array_flip($cols);
        return array_intersect_key($data, $allowed);
    }

    /**
     * Stock de familia en unidades base.
     * stock_unidades = Σ (packs × cantidad_unidades) + stock_abierto.
     * Sin cantidad_unidades confiable no se suma a ciegas (warning).
     *
     * @param int        $grupo_id
     * @param array|null $members_preload
     * @return array
     */
    public function compute_family_stock($grupo_id, $members_preload = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);

        if ($members_preload === null) {
            $members_preload = $wpdb->get_results($wpdb->prepare(
                "SELECT em.producto_base_id, pb.canonical_sku, pb.nombre_canonico, pb.stock_abierto
                 FROM {$prefix}equivalence_members em
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
                 WHERE em.grupo_id = %d AND em.activo = 1",
                $grupo_id
            ), ARRAY_A) ?: [];
        }

        $stock_service = null;
        if (!class_exists('Riverso_Stock_Service') && defined('RIVERSO_POS_PLUGIN_DIR')) {
            $stock_file = RIVERSO_POS_PLUGIN_DIR . 'inventory/stock/class-stock-service.php';
            if (file_exists($stock_file)) {
                require_once $stock_file;
            }
        }
        if (class_exists('Riverso_Stock_Service')) {
            $stock_service = Riverso_Stock_Service::get_instance();
        }

        $total = 0.0;
        $warnings = [];
        $detail = [];

        foreach ($members_preload as $m) {
            $base_id = intval($m['producto_base_id'] ?? 0);
            if (!$base_id) {
                continue;
            }

            $packs = $stock_service
                ? floatval($stock_service->get_balance($base_id))
                : 0.0;

            $envase = $wpdb->get_row($wpdb->prepare(
                "SELECT id, cantidad_unidades, tipo_envase, codigo_proveedor, origen_datos
                 FROM {$prefix}envases
                 WHERE producto_base_id = %d AND activo = 1
                 ORDER BY (cantidad_unidades > 1) DESC, cantidad_unidades DESC
                 LIMIT 1",
                $base_id
            ), ARRAY_A);

            $cantidad = $envase ? floatval($envase['cantidad_unidades']) : 0.0;

            // Revalidar auto-imports Woo erróneos (ej. calibre "#8" guardado como envase 8).
            $origen = (string) ($envase['origen_datos'] ?? '');
            if ($envase && $origen === 'woo_attr_envase') {
                $woo_qty = $this->resolve_envase_qty_from_woocommerce($base_id);
                if ($woo_qty > 1 && abs($woo_qty - $cantidad) > 0.0001) {
                    $wpdb->update(
                        "{$prefix}envases",
                        ['cantidad_unidades' => $woo_qty],
                        ['id' => intval($envase['id'])],
                        ['%f'],
                        ['%d']
                    );
                    $cantidad = $woo_qty;
                    $envase['cantidad_unidades'] = $woo_qty;
                } elseif ($woo_qty <= 0 && $cantidad > 0 && $cantidad <= 16) {
                    // Quedó un falso positivo (#8): invalidar para no heredar mal.
                    $wpdb->update(
                        "{$prefix}envases",
                        ['activo' => 0],
                        ['id' => intval($envase['id'])],
                        ['%d'],
                        ['%d']
                    );
                    $cantidad = 0.0;
                    $envase = null;
                }
            }

            // Fallback: atributo WooCommerce "envase" (ej. "100 U") → persistir en riverso_envases.
            if ($cantidad <= 0) {
                $woo_qty = $this->resolve_envase_qty_from_woocommerce($base_id);
                if ($woo_qty > 1) {
                    $envase_id = $this->upsert_envase_for_member([
                        'producto_base_id' => $base_id,
                        'cantidad_unidades' => $woo_qty,
                        'origen_datos' => 'woo_attr_envase',
                    ]);
                    if ($envase_id) {
                        $cantidad = $woo_qty;
                        $envase = [
                            'id' => $envase_id,
                            'cantidad_unidades' => $woo_qty,
                        ];
                    }
                }
            }

            $abierto = floatval($m['stock_abierto'] ?? $wpdb->get_var($wpdb->prepare(
                "SELECT stock_abierto FROM {$prefix}producto_base WHERE id = %d",
                $base_id
            )) ?: 0);

            $row = [
                'producto_base_id' => $base_id,
                'canonical_sku' => $m['canonical_sku'] ?? null,
                'nombre_canonico' => $m['nombre_canonico'] ?? null,
                'stock_packs' => $packs,
                'cantidad_unidades' => $cantidad > 0 ? $cantidad : null,
                'envase_id' => $envase ? intval($envase['id']) : null,
                'stock_abierto' => $abierto,
                'stock_unidades' => null,
                'incluido' => false,
            ];

            if ($cantidad <= 0) {
                $label = !empty($m['canonical_sku'])
                    ? ('SKU ' . $m['canonical_sku'])
                    : ('producto #' . $base_id);
                $warnings[] = $label . ' sin cantidad de envase en Riverso (no en WooCommerce); no se suma al stock de familia. Define la cantidad en Editar familia.';
                $detail[] = $row;
                continue;
            }

            $units = ($packs * $cantidad) + $abierto;
            $row['stock_unidades'] = $units;
            $row['incluido'] = true;
            $total += $units;
            $detail[] = $row;
        }

        return [
            'grupo_id' => $grupo_id,
            'stock_unidades' => $total,
            'warnings' => $warnings,
            'members' => $detail,
        ];
    }

    /**
     * Lee cantidad de envase desde atributo WooCommerce (envase / packaging / unidades).
     * Ej.: "100 U", "500u", "caja 250".
     *
     * @param int $producto_base_id
     * @return float
     */
    private function resolve_envase_qty_from_woocommerce($producto_base_id) {
        if (!function_exists('wc_get_product')) {
            return 0.0;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id
             FROM {$prefix}producto_base WHERE id = %d",
            intval($producto_base_id)
        ), ARRAY_A);
        if (!$pb) {
            return 0.0;
        }

        $woo_id = intval($pb['woocommerce_variation_id'] ?: $pb['woocommerce_product_id']);
        if ($woo_id <= 0) {
            return 0.0;
        }

        $product = wc_get_product($woo_id);
        if (!$product) {
            return 0.0;
        }

        $candidates = [];
        $attrs = $product->get_attributes();
        if (is_array($attrs)) {
            foreach ($attrs as $key => $attr) {
                $name = is_object($attr) && method_exists($attr, 'get_name')
                    ? (string) $attr->get_name()
                    : (string) $key;
                $val = '';
                if (is_object($attr) && method_exists($attr, 'get_options')) {
                    $opts = $attr->get_options();
                    $val = is_array($opts) ? implode(' ', $opts) : (string) $opts;
                } elseif (is_string($attr)) {
                    $val = $attr;
                } elseif (is_array($attr)) {
                    $val = isset($attr['options']) ? implode(' ', (array) $attr['options']) : '';
                }
                // Variaciones: get_attribute('pa_envase') / 'envase'
                $candidates[$name] = $val;
            }
        }

        foreach (['envase', 'pa_envase', 'packaging', 'unidades', 'cantidad', 'pack'] as $slug) {
            $v = $product->get_attribute($slug);
            if ($v) {
                $candidates[$slug] = $v;
            }
        }

        foreach ($candidates as $name => $val) {
            $hay = strtolower($name . ' ' . $val);
            $looks_envase_attr = strpos($hay, 'envase') !== false
                || strpos($hay, 'pack') !== false
                || strpos($hay, 'packaging') !== false;
            // Solo atributos de envase/pack. Nunca parsear calibre "#8" u otros.
            if (!$looks_envase_attr) {
                continue;
            }
            $qty = $this->parse_envase_qty_text((string) $val);
            if ($qty > 1) {
                return $qty;
            }
        }

        // Segunda pasada: valor explícito tipo "100 U" / "500u" en cualquier attr de envase ya filtrado arriba.
        return 0.0;
    }

    /**
     * @param string $text
     * @return float
     */
    private function parse_envase_qty_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return 0.0;
        }
        // Preferir "100 U", "500u", "caja 250"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*u\b/i', $text, $m)) {
            return floatval(str_replace(',', '.', $m[1]));
        }
        if (preg_match('/\b(?:caja|pack|envase)\s*[:=]?\s*(\d+(?:[.,]\d+)?)/i', $text, $m)) {
            return floatval(str_replace(',', '.', $m[1]));
        }
        // Número solo si el texto es casi solo eso (ej. "100"), no "#8 x 1/2"
        if (preg_match('/^#?\s*(\d+(?:[.,]\d+)?)\s*$/', $text, $m)) {
            $n = floatval(str_replace(',', '.', $m[1]));
            // Calibres de tornillo (#6,#8,#10,#12) no son envase
            if ($n <= 16 && strpos($text, '#') !== false) {
                return 0.0;
            }
            return $n > 1 ? $n : 0.0;
        }
        return 0.0;
    }

    /**
     * @param int $producto_base_id
     * @return array|null
     */
    public function get_exacta_family_of_product($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT em.grupo_id, g.codigo_grupo, g.nombre, g.tipo_sustitucion
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND g.activo = 1
               AND g.tipo_sustitucion = 'exacta'
             LIMIT 1",
            intval($producto_base_id)
        ), ARRAY_A);
    }

    /**
     * Al cambiar una familia a exacta, detecta miembros que ya están en otra exacta.
     *
     * @param int $grupo_id
     * @return array|null
     */
    private function find_exacta_member_conflict($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT em.producto_base_id, pb.canonical_sku,
                    em2.grupo_id AS otro_grupo_id, g2.nombre AS otra_familia, g2.codigo_grupo
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             INNER JOIN {$prefix}equivalence_members em2
                ON em2.producto_base_id = em.producto_base_id
               AND em2.activo = 1 AND em2.grupo_id <> em.grupo_id
             INNER JOIN {$prefix}equivalence_groups g2
                ON g2.id = em2.grupo_id AND g2.activo = 1 AND g2.tipo_sustitucion = 'exacta'
             WHERE em.grupo_id = %d AND em.activo = 1
             LIMIT 1",
            intval($grupo_id)
        ), ARRAY_A);
    }

    /* ===================== Producto unitario ===================== */

    private function unit_service() {
        return Riverso_Unit_Product_Service::get_instance();
    }

    public function ajax_unit_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }
        $snapshot = $this->unit_service()->get_unit_snapshot($grupo_id);
        if (is_wp_error($snapshot)) {
            wp_send_json_error(['message' => $snapshot->get_error_message()]);
        }
        wp_send_json_success(['unit' => $snapshot]);
    }

    public function ajax_unit_configure() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }

        $opts = [
            'nombre' => sanitize_text_field($_POST['nombre'] ?? ''),
            'canonical_sku' => sanitize_text_field($_POST['canonical_sku'] ?? ''),
            'p_asignado' => isset($_POST['p_asignado']) && $_POST['p_asignado'] !== ''
                ? floatval($_POST['p_asignado']) : null,
            'es_producto_unitario' => !isset($_POST['es_producto_unitario']) || !empty($_POST['es_producto_unitario']),
        ];

        $convert_id = absint($_POST['convert_producto_base_id'] ?? 0);
        if ($convert_id) {
            $result = $this->unit_service()->convert_member_to_unit($grupo_id, $convert_id, $opts);
        } else {
            $result = $this->unit_service()->ensure_unit_product($grupo_id, $opts);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (!empty($_POST['confirm_r1']) && class_exists('Riverso_Price_Rules_Module')) {
            $assign = $this->unit_service()->assign_default_rule($grupo_id, absint($_POST['rule_id'] ?? 0));
            if (is_wp_error($assign)) {
                $this->unit_service()->ensure_missing_rule_task($grupo_id);
                wp_send_json_error(['message' => $assign->get_error_message(), 'unit' => $result]);
            }
        } else {
            $this->unit_service()->ensure_missing_rule_task($grupo_id);
        }

        if ($opts['p_asignado'] !== null) {
            $unit_id = intval($result['unit_producto_base_id'] ?? ($result['unit']['id'] ?? 0));
            if ($unit_id && class_exists('Riverso_Pricing_Module')) {
                $pricing = Riverso_Pricing_Module::get_instance();
                $row = $pricing->get_local_price($unit_id);
                if ($row && !empty($row['id'])) {
                    $pricing->set_assigned_price((int) $row['id'], $opts['p_asignado']);
                }
            }
        }

        $snapshot = $this->unit_service()->get_unit_snapshot($grupo_id);
        if (is_wp_error($snapshot)) {
            wp_send_json_error(['message' => $snapshot->get_error_message()]);
        }
        $linked_id = intval($snapshot['unit_producto_base_id'] ?? 0);
        $expected = $convert_id ?: intval($result['unit_producto_base_id'] ?? ($result['unit']['id'] ?? 0));
        if ($expected && $linked_id !== $expected) {
            wp_send_json_error([
                'message' => 'El guardado no persistió el producto unitario (unit_producto_base_id). Reintenta o revisa el log.',
                'unit' => $snapshot,
            ]);
        }
        wp_send_json_success(['unit' => $snapshot, 'message' => 'Producto unitario configurado']);
    }

    public function ajax_unit_toggle() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $enabled = !empty($_POST['enabled']);
        $result = $this->unit_service()->toggle_unit_product($grupo_id, $enabled);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $snapshot = $this->unit_service()->get_unit_snapshot($grupo_id);
        wp_send_json_success([
            'enabled' => $enabled,
            'unit' => is_wp_error($snapshot) ? null : $snapshot,
        ]);
    }

    public function ajax_unit_convert() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        if (!$grupo_id || !$producto_base_id) {
            wp_send_json_error(['message' => 'grupo_id y producto_base_id requeridos']);
        }
        $result = $this->unit_service()->convert_member_to_unit($grupo_id, $producto_base_id, [
            'nombre' => sanitize_text_field($_POST['nombre'] ?? ''),
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['unit' => $result]);
    }

    public function ajax_unit_price_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $p = isset($_POST['p_asignado']) && $_POST['p_asignado'] !== ''
            ? floatval($_POST['p_asignado']) : null;
        $preview = $this->unit_service()->preview_member_prices($grupo_id, $p);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        wp_send_json_success(['preview' => $preview]);
    }

    public function ajax_unit_link_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        if (!$grupo_id || !$producto_base_id) {
            wp_send_json_error(['message' => 'grupo_id y producto_base_id requeridos']);
        }
        $preview = $this->unit_service()->build_link_preview($grupo_id, $producto_base_id);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        wp_send_json_success(['preview' => $preview]);
    }

    /**
     * AJAX: Sugerencias de nombre de familia a partir de productos miembros / unitario.
     */
    public function ajax_suggest_names() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_families') && !current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

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

        $suggestions = $this->suggest_family_names_from_products($ids);
        wp_send_json_success(['suggestions' => $suggestions]);
    }

    /**
     * Construye sugerencias de nombre: producto + Nominal x Largo / otros atributos (sin envase).
     *
     * @param int[] $producto_base_ids
     * @return array<int, array{label:string,source:string,producto_base_id:int}>
     */
    public function suggest_family_names_from_products(array $producto_base_ids) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $out = [];
        $seen = [];

        foreach ($producto_base_ids as $pid) {
            $pid = absint($pid);
            if (!$pid) {
                continue;
            }
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                 FROM {$prefix}producto_base WHERE id = %d AND deleted_at IS NULL",
                $pid
            ), ARRAY_A);
            if (!$pb) {
                continue;
            }

            foreach ($this->build_name_suggestions_for_product($pb) as $sug) {
                $label = trim((string) ($sug['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $key = mb_strtolower($label);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'label' => $label,
                    'source' => (string) ($sug['source'] ?? 'producto'),
                    'producto_base_id' => $pid,
                ];
            }
        }

        return array_slice($out, 0, 12);
    }

    /**
     * @param array $pb producto_base row
     * @return array<int, array{label:string,source:string}>
     */
    private function build_name_suggestions_for_product(array $pb) {
        $suggestions = [];
        $nombre = trim((string) ($pb['nombre_canonico'] ?? ''));
        $attrs = $this->collect_product_display_attributes($pb);
        $base = $this->infer_base_product_name($pb, $attrs, $nombre);

        $nominal = $this->attr_value($attrs, ['nominal', 'calibre', 'diámetro', 'diametro', 'medida']);
        $largo = $this->attr_value($attrs, ['largo', 'longitud', 'length']);
        $acabado = $this->attr_value($attrs, ['acabado', 'finish', 'recubrimiento', 'material']);

        $dim = '';
        if ($nominal !== '' && $largo !== '') {
            $dim = $nominal . ' x ' . $largo;
        } elseif ($nominal !== '') {
            $dim = $nominal;
        } elseif ($largo !== '') {
            $dim = $largo;
        }

        $other_bits = [];
        foreach ($attrs as $a) {
            $slug = strtolower((string) ($a['slug'] ?? ''));
            $name = strtolower((string) ($a['name'] ?? ''));
            $val = trim((string) ($a['value'] ?? ''));
            if ($val === '' || $this->is_envase_attr($slug, $name)) {
                continue;
            }
            if ($this->attr_matches($slug, $name, ['nominal', 'calibre', 'diámetro', 'diametro', 'medida', 'largo', 'longitud', 'length', 'acabado', 'finish', 'recubrimiento', 'material', 'envase', 'pack'])) {
                continue;
            }
            // Evitar atributos muy ruidosos
            if (strlen($val) > 40) {
                continue;
            }
            $other_bits[] = $val;
        }
        $other_bits = array_slice(array_unique($other_bits), 0, 3);

        if ($base !== '') {
            $parts = [$base];
            if ($dim !== '') {
                $parts[] = $dim;
            }
            if ($acabado !== '' && strcasecmp($acabado, 'Sin acabado') !== 0) {
                $parts[] = $acabado;
            }
            $suggestions[] = [
                'label' => implode(' · ', $parts),
                'source' => 'base+attrs',
            ];
            if ($dim !== '' && $other_bits) {
                $suggestions[] = [
                    'label' => implode(' · ', array_merge([$base, $dim], $other_bits)),
                    'source' => 'base+attrs+extra',
                ];
            }
        }

        if ($nombre !== '' && (!$base || strcasecmp($nombre, $base) !== 0)) {
            $suggestions[] = [
                'label' => $this->strip_envase_from_name($nombre),
                'source' => 'nombre_canonico',
            ];
        }

        if ($base !== '' && $dim === '' && $other_bits) {
            $suggestions[] = [
                'label' => $base . ' · ' . implode(' · ', $other_bits),
                'source' => 'base+extra',
            ];
        }

        return $suggestions;
    }

    /**
     * @param array $pb
     * @return array<int, array{name:string,slug:string,value:string}>
     */
    private function collect_product_display_attributes(array $pb) {
        if (!function_exists('wc_get_product')) {
            return [];
        }
        $var_id = absint($pb['woocommerce_variation_id'] ?? 0);
        $prod_id = absint($pb['woocommerce_product_id'] ?? 0);
        $woo_id = $var_id ?: $prod_id;
        if (!$woo_id) {
            return [];
        }
        $product = wc_get_product($woo_id);
        if (!$product) {
            return [];
        }

        $out = [];
        if ($product->is_type('variation')) {
            foreach ($product->get_attributes() as $slug => $value) {
                $label = function_exists('wc_attribute_label') ? wc_attribute_label($slug) : $slug;
                $val = is_string($value) ? $value : (string) $value;
                if ($val === '') {
                    continue;
                }
                // Términos taxonomy a veces vienen como slug
                if (taxonomy_exists($slug)) {
                    $term = get_term_by('slug', $val, $slug);
                    if ($term && !is_wp_error($term)) {
                        $val = $term->name;
                    }
                }
                $out[] = [
                    'name' => (string) $label,
                    'slug' => (string) $slug,
                    'value' => trim((string) $val),
                ];
            }
        } else {
            foreach ($product->get_attributes() as $slug => $attr) {
                $name = is_object($attr) && method_exists($attr, 'get_name')
                    ? (string) $attr->get_name()
                    : (string) $slug;
                $val = '';
                if (is_object($attr) && method_exists($attr, 'get_options')) {
                    $opts = $attr->get_options();
                    if (is_array($opts) && $opts) {
                        if (is_object($attr) && method_exists($attr, 'is_taxonomy') && $attr->is_taxonomy()) {
                            $names = [];
                            foreach ($opts as $term_id) {
                                $term = get_term((int) $term_id);
                                if ($term && !is_wp_error($term)) {
                                    $names[] = $term->name;
                                }
                            }
                            $val = implode(', ', $names);
                        } else {
                            $val = implode(', ', array_map('strval', $opts));
                        }
                    }
                } elseif (is_string($attr)) {
                    $val = $attr;
                }
                if (trim($val) === '') {
                    continue;
                }
                $out[] = [
                    'name' => $name,
                    'slug' => (string) $slug,
                    'value' => trim($val),
                ];
            }
        }
        return $out;
    }

    private function infer_base_product_name(array $pb, array $attrs, $nombre) {
        if (!function_exists('wc_get_product')) {
            return $this->strip_envase_from_name($nombre);
        }
        $var_id = absint($pb['woocommerce_variation_id'] ?? 0);
        $prod_id = absint($pb['woocommerce_product_id'] ?? 0);
        if ($var_id) {
            $variation = wc_get_product($var_id);
            if ($variation && $variation->is_type('variation')) {
                $parent = wc_get_product((int) $variation->get_parent_id());
                if ($parent) {
                    return trim((string) $parent->get_name());
                }
            }
        }
        if ($prod_id) {
            $product = wc_get_product($prod_id);
            if ($product && !$product->is_type('variation')) {
                return trim((string) $product->get_name());
            }
        }
        // Quitar valores de atributos del nombre canónico
        $clean = $this->strip_envase_from_name($nombre);
        foreach ($attrs as $a) {
            $val = trim((string) ($a['value'] ?? ''));
            if ($val === '' || strlen($val) < 2) {
                continue;
            }
            $clean = preg_replace('/\s*[-–·,]\s*' . preg_quote($val, '/') . '\s*$/iu', '', $clean);
            $clean = preg_replace('/\b' . preg_quote($val, '/') . '\b/iu', '', $clean);
        }
        $clean = trim(preg_replace('/\s{2,}/', ' ', (string) $clean));
        return $clean !== '' ? $clean : $this->strip_envase_from_name($nombre);
    }

    private function strip_envase_from_name($nombre) {
        $nombre = trim((string) $nombre);
        // "… (caja 100 u)", "… 100 U", "… - 500u"
        $nombre = preg_replace('/\s*[\(\[]?\s*(caja|pack|envase)?\s*\d+(?:[.,]\d+)?\s*u\.?\s*[\)\]]?\s*$/iu', '', $nombre);
        return trim((string) $nombre);
    }

    private function attr_value(array $attrs, array $keys) {
        foreach ($attrs as $a) {
            if ($this->attr_matches((string) ($a['slug'] ?? ''), (string) ($a['name'] ?? ''), $keys)) {
                return trim((string) ($a['value'] ?? ''));
            }
        }
        return '';
    }

    private function attr_matches($slug, $name, array $keys) {
        $hay = strtolower($slug . ' ' . $name);
        $hay = str_replace(['pa_', '-', '_'], ' ', $hay);
        foreach ($keys as $k) {
            $k = strtolower((string) $k);
            if ($k !== '' && strpos($hay, $k) !== false) {
                return true;
            }
        }
        return false;
    }

    private function is_envase_attr($slug, $name) {
        return $this->attr_matches($slug, $name, ['envase', 'pack', 'packaging', 'unidades', 'cantidad']);
    }

    /**
     * Miembros activos enriquecidos (flags SKU, envase, unitario).
     *
     * @param int $grupo_id
     * @return array
     */
    private function load_family_members_enriched($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = absint($grupo_id);
        if (!$grupo_id) {
            return [];
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT unit_producto_base_id FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);
        $unit_id = intval($family['unit_producto_base_id'] ?? 0);

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.id, em.producto_base_id, em.prioridad, em.es_reemplazo_preferido,
                    pb.canonical_sku, pb.nombre_canonico, pb.stock_abierto,
                    pb.woocommerce_product_id, pb.woocommerce_variation_id,
                    pb.es_unidad_minima, pb.unit_of_grupo_id
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY em.prioridad DESC, pb.nombre_canonico ASC",
            $grupo_id
        ), ARRAY_A) ?: [];

        $stock = $this->compute_family_stock($grupo_id, $members);
        $by_id = [];
        foreach ($stock['members'] as $sm) {
            $by_id[intval($sm['producto_base_id'])] = $sm;
        }
        foreach ($members as &$m) {
            $sid = intval($m['producto_base_id']);
            if (isset($by_id[$sid])) {
                $m = array_merge($m, $by_id[$sid]);
            }
            $this->enrich_product_sku_flags($m);
            $m['es_unitario_familia'] = $unit_id > 0 && $sid === $unit_id;
        }
        unset($m);

        return $members;
    }

    /**
     * Detecta hijos con la misma cantidad de envase en una familia.
     *
     * @param int   $grupo_id
     * @param array $members
     * @return array
     */
    public function detect_pack_qty_conflicts($grupo_id, array $members) {
        unset($grupo_id);
        $by_qty = [];
        foreach ($members as $m) {
            if (!empty($m['es_unitario_familia'])) {
                continue;
            }
            $qty = floatval($m['cantidad_unidades'] ?? 0);
            if ($qty <= 1.0001) {
                continue;
            }
            $key = $this->format_pack_qty_key($qty);
            if (!isset($by_qty[$key])) {
                $by_qty[$key] = [];
            }
            $by_qty[$key][] = $this->format_pack_conflict_member($m);
        }

        $conflicts = [];
        foreach ($by_qty as $group) {
            if (count($group) < 2) {
                continue;
            }
            $qty = floatval($group[0]['cantidad_unidades'] ?? 0);
            $conflict = $this->evaluate_pack_qty_conflict_group($group, $qty);
            if ($conflict) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    /**
     * @param float $qty
     * @return string
     */
    private function format_pack_qty_key($qty) {
        return number_format(round(floatval($qty), 4), 4, '.', '');
    }

    /**
     * @param float $qty
     * @return string
     */
    private function format_pack_qty_label($qty) {
        return rtrim(rtrim(number_format(floatval($qty), 4, '.', ''), '0'), '.');
    }

    /**
     * @param array $m
     * @return array
     */
    private function format_pack_conflict_member(array $m) {
        return [
            'producto_base_id' => intval($m['producto_base_id'] ?? 0),
            'nombre_canonico' => (string) ($m['nombre_canonico'] ?? ''),
            'sku_local' => (string) ($m['sku_local'] ?? $m['canonical_sku'] ?? ''),
            'sku_online' => (string) ($m['sku_online'] ?? ''),
            'es_local' => !empty($m['es_local']),
            'es_online' => !empty($m['es_online']),
            'cantidad_unidades' => floatval($m['cantidad_unidades'] ?? 0),
            'woocommerce_product_id' => absint($m['woocommerce_product_id'] ?? 0),
            'woocommerce_variation_id' => absint($m['woocommerce_variation_id'] ?? 0),
        ];
    }

    /**
     * @param array $members
     * @param float $qty
     * @return array|null
     */
    private function evaluate_pack_qty_conflict_group(array $members, $qty) {
        $online_stubs = [];
        $local_only = [];
        $complete = [];

        foreach ($members as $m) {
            $is_local = !empty($m['es_local']);
            $is_online = !empty($m['es_online']);
            if ($is_online && !$is_local) {
                $online_stubs[] = $m;
            } elseif ($is_local && !$is_online) {
                $local_only[] = $m;
            } elseif ($is_local && $is_online) {
                $complete[] = $m;
            }
        }

        if (!empty($complete) && empty($online_stubs) && empty($local_only)) {
            return null;
        }

        $qty_label = $this->format_pack_qty_label($qty);
        $conflict = [
            'qty' => $qty,
            'qty_label' => $qty_label,
            'members' => $members,
            'mergeable' => false,
            'source_id' => null,
            'target_id' => null,
            'message' => '',
        ];

        if (count($online_stubs) === 1 && count($local_only) === 1 && count($members) === 2) {
            $conflict['mergeable'] = true;
            $conflict['source_id'] = intval($online_stubs[0]['producto_base_id']);
            $conflict['target_id'] = intval($local_only[0]['producto_base_id']);
            $conflict['message'] = sprintf(
                'Mismo envase %s u: el producto online puede fusionarse en el local.',
                $qty_label
            );
            return $conflict;
        }

        $conflict['message'] = sprintf(
            'Varios miembros con envase de %s u (%d productos). Revisa duplicados.',
            $qty_label,
            count($members)
        );
        return $conflict;
    }

    /**
     * Valida par online (origen) + local (destino) para merge en familia.
     *
     * @param int $grupo_id
     * @param int $source_id
     * @param int $target_id
     * @return array|WP_Error
     */
    private function validate_family_pack_merge_pair($grupo_id, $source_id, $target_id) {
        $grupo_id = absint($grupo_id);
        $source_id = absint($source_id);
        $target_id = absint($target_id);

        if (!$grupo_id || !$source_id || !$target_id || $source_id === $target_id) {
            return new WP_Error('invalid_ids', 'IDs de familia o productos inválidos');
        }

        $members = $this->load_family_members_enriched($grupo_id);
        $member_ids = array_map(function ($m) {
            return intval($m['producto_base_id']);
        }, $members);

        if (!in_array($source_id, $member_ids, true) || !in_array($target_id, $member_ids, true)) {
            return new WP_Error('not_member', 'Ambos productos deben ser miembros activos de esta familia');
        }

        $conflicts = $this->detect_pack_qty_conflicts($grupo_id, $members);
        foreach ($conflicts as $conflict) {
            if (empty($conflict['mergeable'])) {
                continue;
            }
            if (intval($conflict['source_id']) === $source_id && intval($conflict['target_id']) === $target_id) {
                $source = null;
                $target = null;
                foreach ($members as $m) {
                    if (intval($m['producto_base_id']) === $source_id) {
                        $source = $m;
                    }
                    if (intval($m['producto_base_id']) === $target_id) {
                        $target = $m;
                    }
                }
                if (!$source || !$target) {
                    return new WP_Error('not_found', 'Productos no encontrados en la familia');
                }
                if (empty($source['es_online']) || !empty($source['es_local'])) {
                    return new WP_Error('invalid_source', 'El origen debe ser un producto solo online');
                }
                if (empty($target['es_local']) || !empty($target['es_online'])) {
                    return new WP_Error('invalid_target', 'El destino debe ser un producto solo local');
                }
                $woo_id = absint($source['woocommerce_variation_id'] ?? 0)
                    ?: absint($source['woocommerce_product_id'] ?? 0);
                if (!$woo_id) {
                    return new WP_Error('no_woo', 'El producto online no tiene WooCommerce vinculado');
                }
                return array_merge($conflict, [
                    'source' => $source,
                    'target' => $target,
                    'woo_id' => $woo_id,
                ]);
            }
        }

        return new WP_Error('invalid_pair', 'No hay un conflicto fusionable para este par en la familia');
    }

    /**
     * AJAX: Preview de merge online→local por mismo envase en familia.
     */
    public function ajax_pack_merge_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $source_id = absint($_POST['source_id'] ?? 0);
        $target_id = absint($_POST['target_id'] ?? 0);

        $validation = $this->validate_family_pack_merge_pair($grupo_id, $source_id, $target_id);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        if (!class_exists('Riverso_Product_Module')) {
            wp_send_json_error(['message' => 'Módulo de productos no disponible']);
        }

        $prod_mod = Riverso_Product_Module::get_instance();
        $woo_id = absint($validation['woo_id']);
        $preview = $prod_mod->build_online_merge_preview($woo_id, $target_id);
        $preview['source_id'] = $source_id;
        $preview['target_id'] = $target_id;
        $preview['needs_merge'] = true;
        $preview['pack_qty'] = floatval($validation['qty']);
        $preview['pack_qty_label'] = (string) $validation['qty_label'];
        $preview['warnings'][] = [
            'type' => 'same_pack_qty',
            'message' => sprintf(
                'Ambos miembros tienen envase de %s u en esta familia.',
                $validation['qty_label']
            ),
            'severity' => 'info',
        ];

        if (!empty($preview['block_merge'])) {
            wp_send_json_error([
                'message' => $preview['family']['message'] ?? 'Merge bloqueado',
                'block_merge' => true,
                'merge' => $preview,
            ]);
        }

        wp_send_json_success(['merge' => $preview]);
    }

    /**
     * AJAX: Confirmar merge online→local por mismo envase en familia.
     */
    public function ajax_pack_merge_confirm() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_manage_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $source_id = absint($_POST['source_id'] ?? 0);
        $target_id = absint($_POST['target_id'] ?? 0);

        $validation = $this->validate_family_pack_merge_pair($grupo_id, $source_id, $target_id);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        if (!class_exists('Riverso_Product_Module')) {
            wp_send_json_error(['message' => 'Módulo de productos no disponible']);
        }

        $prod_mod = Riverso_Product_Module::get_instance();
        $result = $prod_mod->merge_stub_into_local($source_id, $target_id, [
            'force_replace_target_woo' => true,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_pack_merge', 'equivalence_groups', $grupo_id, [
                'source_id' => $source_id,
                'target_id' => $target_id,
                'qty' => floatval($validation['qty']),
                'transferred' => $result['transferred'] ?? [],
            ]);
        }

        $members = $this->load_family_members_enriched($grupo_id);
        wp_send_json_success([
            'message' => sprintf(
                'Merge completado: Woo + %d código(s) y %d barcode(s) heredados; origen #%d eliminado.',
                intval($result['transferred']['codes'] ?? 0),
                intval($result['transferred']['barcodes'] ?? 0),
                $source_id
            ),
            'transferred' => $result['transferred'] ?? [],
            'target_id' => $target_id,
            'pack_conflicts' => $this->detect_pack_qty_conflicts($grupo_id, $members),
        ]);
    }
}

