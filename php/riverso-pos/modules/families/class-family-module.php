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
        add_action('wp_ajax_riverso_families_add_member', [$this, 'ajax_add_member']);
        add_action('wp_ajax_riverso_families_remove_member', [$this, 'ajax_remove_member']);
        add_action('wp_ajax_riverso_families_search_candidates', [$this, 'ajax_search_candidates']);
        add_action('wp_ajax_riverso_families_tree', [$this, 'ajax_family_tree']);
        add_action('wp_ajax_riverso_families_stock', [$this, 'ajax_family_stock']);
        add_action('wp_ajax_riverso_families_suggest', [$this, 'ajax_suggest_families']);
        add_action('wp_ajax_riverso_families_accept_suggestion', [$this, 'ajax_accept_suggestion']);
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
     * AJAX: Listar todas las familias activas con conteo de miembros y stock.
     */
    public function ajax_list_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_families')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $families = $wpdb->get_results(
            "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion, g.activo,
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
            $stock = $this->compute_family_stock(intval($family['id']));
            $family['stock_unidades'] = $stock['stock_unidades'];
            $family['stock_warnings'] = $stock['warnings'];
            $family['stock_completo'] = empty($stock['warnings']);
        }
        unset($family);

        wp_send_json_success(['families' => $families ?: []]);
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
                    pb.woocommerce_product_id, pb.woocommerce_variation_id
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY em.prioridad DESC, pb.nombre_canonico ASC",
            $grupo_id
        ), ARRAY_A);

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
        }
        unset($m);

        $family['members'] = $members;
        $family['pending'] = $this->get_pending_suppliers($grupo_id);
        $family['stock'] = $stock;
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

        if (!$codigo_grupo || !$nombre) {
            wp_send_json_error(['message' => 'Código y nombre son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups WHERE codigo_grupo = %s",
            $codigo_grupo
        ))) {
            wp_send_json_error(['message' => 'El código de grupo ya existe']);
        }

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

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT em.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.id = %d",
            $member_id
        ), ARRAY_A);

        wp_send_json_success(['member' => $member]);
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
                    pb.woocommerce_product_id, pb.woocommerce_variation_id
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

        $items = [];
        foreach ($rows as $row) {
            $pid = absint($row['id']);
            $this->enrich_product_sku_flags($row);
            $sku = (string) ($row['sku_local'] ?? '');
            $es_local = !empty($row['es_local']);
            $es_online = !empty($row['es_online']);

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
            'origen_datos' => 'familia_mamut_suggestion',
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
                "SELECT id, cantidad_unidades, tipo_envase, codigo_proveedor
                 FROM {$prefix}envases
                 WHERE producto_base_id = %d AND activo = 1
                 ORDER BY (cantidad_unidades > 1) DESC, cantidad_unidades DESC
                 LIMIT 1",
                $base_id
            ), ARRAY_A);

            $cantidad = $envase ? floatval($envase['cantidad_unidades']) : 0.0;
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
                $warnings[] = 'SKU ' . ($m['canonical_sku'] ?: $base_id) . ' sin cantidad_unidades de envase; no se suma al stock de familia.';
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
}
