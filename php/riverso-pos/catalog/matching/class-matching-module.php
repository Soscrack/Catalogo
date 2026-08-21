<?php
/**
 * Módulo de Matching progresivo - Riverso POS.
 *
 * Evalúa la confianza de la relación producto_proveedor -> producto_base y
 * gestiona los estados del flujo:
 *
 *   UNMATCHED -> AUTO_MATCH -> HUMAN_REVIEW -> VERIFIED / REJECTED
 *
 * El scoring combina SKU/canonical_sku, código de barra, nombre y medidas.
 * Todo match automático queda con match_origen='computer', requires_human_review=1
 * y genera una tarea de revisión.
 *
 * Las columnas (match_estado, match_score, match_origen, matched_at) se crean en
 * class-activator.php (create_phase2_matching).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Matching_Module {

    private static $instance = null;

    const ESTADOS = ['UNMATCHED', 'AUTO_MATCH', 'HUMAN_REVIEW', 'VERIFIED', 'REJECTED'];
    const ONLINE_ESTADOS = ['UNMATCHED', 'AUTO_MATCH', 'PENDING_REVIEW', 'CONFIRMED', 'REJECTED'];

    // Umbrales de scoring (0-100).
    const THRESHOLD_AUTO = 85;
    const THRESHOLD_REVIEW = 60;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_matching_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_matching_run', [$this, 'ajax_run']);
        add_action('wp_ajax_riverso_matching_run_all', [$this, 'ajax_run_all']);
        add_action('wp_ajax_riverso_matching_set_state', [$this, 'ajax_set_state']);
        add_action('wp_ajax_riverso_online_matching_list', [$this, 'ajax_online_list']);
        add_action('wp_ajax_riverso_online_matching_run', [$this, 'ajax_online_run']);
        add_action('wp_ajax_riverso_online_matching_run_all', [$this, 'ajax_online_run_all']);
        add_action('wp_ajax_riverso_online_matching_set_state', [$this, 'ajax_online_set_state']);
        add_action('wp_ajax_riverso_matching_assign_family', [$this, 'ajax_assign_to_family']);
        add_action('wp_ajax_riverso_matching_assign_product', [$this, 'ajax_assign_to_product']);
        add_action('wp_ajax_riverso_matching_get_assignment', [$this, 'ajax_get_assignment']);
        add_action('wp_ajax_riverso_matching_list_families', [$this, 'ajax_list_families']);
    }

    /* ===================== Scoring ===================== */

    private function normalize($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value);
        return $value;
    }

    /**
     * Calcula el score de confianza (0-100) de la relación de un producto_proveedor
     * con su producto_base actual.
     *
     * @param array $pp Fila de producto_proveedor (con producto_base_id)
     * @return int
     */
    public function compute_relation_score(array $pp) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            intval($pp['producto_base_id'])
        ), ARRAY_A);

        if (!$pb) {
            return 0;
        }

        $score = 0;

        // 1. SKU / código proveedor vs canonical_sku (señal fuerte).
        if (!empty($pp['codigo_proveedor']) && !empty($pb['canonical_sku'])) {
            if ($this->normalize($pp['codigo_proveedor']) === $this->normalize($pb['canonical_sku'])) {
                $score += 50;
            }
        }

        // 2. Código de barra del proveedor vs barcodes del producto Woo.
        if (!empty($pp['codigo_barras_proveedor']) && !empty($pb['woocommerce_product_id'])) {
            $barcode_match = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}barcodes
                 WHERE barcode = %s AND product_id = %d",
                $pp['codigo_barras_proveedor'],
                intval($pb['woocommerce_product_id'])
            ));
            if ($barcode_match) {
                $score += 30;
            }
        }

        // 3. Similitud de nombre.
        if (!empty($pp['nombre_proveedor']) && !empty($pb['nombre_canonico'])) {
            $percent = 0.0;
            similar_text(
                strtolower($pp['nombre_proveedor']),
                strtolower($pb['nombre_canonico']),
                $percent
            );
            $score += (int) round(($percent / 100) * 40);
        }

        return min(100, $score);
    }

    /**
     * Traduce un score a estado de matching.
     */
    public function score_to_state($score) {
        if ($score >= self::THRESHOLD_AUTO) {
            return 'AUTO_MATCH';
        }
        if ($score >= self::THRESHOLD_REVIEW) {
            return 'HUMAN_REVIEW';
        }
        return 'UNMATCHED';
    }

    public function score_to_online_state($score) {
        if ($score >= self::THRESHOLD_AUTO) {
            return 'AUTO_MATCH';
        }
        if ($score >= self::THRESHOLD_REVIEW) {
            return 'PENDING_REVIEW';
        }
        return 'UNMATCHED';
    }

    public function compute_online_candidates(array $pb, $limit = 8) {
        global $wpdb;
        $limit = max(1, min(20, intval($limit)));
        $sku = trim((string) ($pb['canonical_sku'] ?? ''));
        $name = trim((string) ($pb['nombre_canonico'] ?? ''));
        $ids = [];

        if ($sku !== '') {
            $by_sku = wc_get_product_id_by_sku($sku);
            if ($by_sku) {
                $ids[] = intval($by_sku);
            }
        }

        if ($name !== '') {
            $like = '%' . $wpdb->esc_like($name) . '%';
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type IN ('product','product_variation')
                   AND post_status IN ('publish','draft','private')
                   AND post_title LIKE %s
                 ORDER BY post_type = 'product' DESC, ID DESC
                 LIMIT %d",
                $like,
                $limit * 2
            ));
            foreach ($found as $id) {
                $ids[] = intval($id);
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $candidates = [];

        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $score = 0;
            $wc_sku = (string) $product->get_sku();
            if ($sku !== '' && $wc_sku !== '' && $this->normalize($sku) === $this->normalize($wc_sku)) {
                $score += 55;
            }
            if ($name !== '') {
                $percent = 0.0;
                similar_text(strtolower($name), strtolower($product->get_name()), $percent);
                $score += (int) round(($percent / 100) * 35);
            }

            $candidates[] = [
                'id' => $id,
                'product_id' => $product->is_type('variation') ? $product->get_parent_id() : $id,
                'variation_id' => $product->is_type('variation') ? $id : 0,
                'sku' => $wc_sku,
                'name' => $product->get_name(),
                'status' => $product->get_status(),
                'score' => min(100, $score),
            ];
        }

        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($candidates, 0, $limit);
    }

    /* ===================== Workflow ===================== */

    /**
     * Evalúa y actualiza el estado de matching de un producto_proveedor.
     * No sobreescribe decisiones humanas (VERIFIED / REJECTED).
     *
     * @param int $pp_id
     * @return array|WP_Error
     */
    public function run_match($pp_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = intval($pp_id);

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Producto proveedor no encontrado');
        }

        // Ya asignado a familia: no recalcular score producto↔base
        if (!empty($pp['grupo_id']) && empty($pp['producto_base_id'])) {
            return $pp;
        }

        // Sin destino producto: no hay relación que evaluar
        if (empty($pp['producto_base_id'])) {
            return new WP_Error('unassigned', 'Producto proveedor sin producto_base ni familia');
        }

        // Respetar decisiones humanas.
        if (in_array($pp['match_estado'] ?? '', ['VERIFIED', 'REJECTED'], true)) {
            return $pp;
        }

        $score = $this->compute_relation_score($pp);
        $estado = $this->score_to_state($score);

        $wpdb->update(
            "{$prefix}producto_proveedor",
            [
                'match_estado' => $estado,
                'match_score' => $score,
                'match_confidence' => $score,
                'match_origen' => 'computer',
                'matched_at' => current_time('mysql'),
                'requires_human_review' => ($estado === 'VERIFIED') ? 0 : 1,
            ],
            ['id' => $pp_id],
            ['%s', '%d', '%d', '%s', '%s', '%d'],
            ['%d']
        );

        // Encolar tarea de revisión según estado.
        if (function_exists('riverso_create_review_task')) {
            if ($estado === 'UNMATCHED') {
                riverso_create_review_task(
                    'relacionar_producto_proveedor',
                    'Relacionar producto proveedor #' . $pp_id . ' (' . ($pp['codigo_proveedor'] ?? '') . ')',
                    'producto_proveedor',
                    $pp_id,
                    ['prioridad' => 'alta']
                );
            } else {
                riverso_create_review_task(
                    'revisar_relacion',
                    'Revisar relación producto proveedor #' . $pp_id . ' (score ' . $score . ')',
                    'producto_proveedor',
                    $pp_id,
                    ['prioridad' => $estado === 'HUMAN_REVIEW' ? 'alta' : 'normal']
                );
            }
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('match_evaluated', 'producto_proveedor', $pp_id, [
                'new_value' => ['estado' => $estado, 'score' => $score],
                'details' => 'Evaluación automática de matching',
            ]);
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
    }

    /**
     * Ejecuta matching sobre un lote de producto_proveedor sin estado definido.
     *
     * @param int $limit
     * @return int Número procesados
     */
    public function run_batch($limit = 200) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $limit = max(1, intval($limit));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_proveedor
             WHERE match_estado IS NULL OR match_estado = '' OR match_estado = 'UNMATCHED'
             LIMIT %d",
            $limit
        ));

        $count = 0;
        foreach ($ids as $id) {
            $this->run_match(intval($id));
            $count++;
        }
        return $count;
    }

    public function run_online_match($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = absint($producto_base_id);

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        if (in_array($pb['match_estado_online'] ?? '', ['CONFIRMED', 'REJECTED'], true)) {
            return $pb;
        }

        $candidates = $this->compute_online_candidates($pb);
        $best = $candidates[0] ?? null;
        $score = $best ? intval($best['score']) : 0;
        $estado = $this->score_to_online_state($score);
        $candidate_id = $best ? intval($best['id']) : 0;

        $wpdb->update(
            "{$prefix}producto_base",
            [
                'match_estado_online' => $estado,
                'match_score_online' => $score,
                'match_origen_online' => 'computer',
                'matched_online_at' => current_time('mysql'),
                'woocommerce_candidate_id' => $candidate_id,
                'requires_human_review' => 1,
                'publication_stage' => $estado === 'UNMATCHED' ? 'computer_created' : 'pending_review',
            ],
            ['id' => $producto_base_id],
            ['%s', '%d', '%s', '%s', '%d', '%d', '%s'],
            ['%d']
        );

        if (function_exists('riverso_create_review_task') && $estado !== 'UNMATCHED') {
            riverso_create_review_task(
                'confirmar_relacion_online',
                'Confirmar relación producto local ↔ online para ' . ($pb['canonical_sku'] ?: '#' . $producto_base_id),
                'producto_base',
                $producto_base_id,
                ['prioridad' => $estado === 'AUTO_MATCH' ? 'normal' : 'alta']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('online_match_evaluated', 'producto_base', $producto_base_id, [
                'new_value' => [
                    'estado' => $estado,
                    'score' => $score,
                    'candidate_id' => $candidate_id,
                ],
                'details' => 'Evaluación automática de match local ↔ online',
            ]);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        $row['online_candidates'] = $candidates;
        return $row;
    }

    public function run_online_batch($limit = 100) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $limit = max(1, intval($limit));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE (match_estado_online IS NULL OR match_estado_online = '' OR match_estado_online = 'UNMATCHED')
               AND deleted_at IS NULL
             LIMIT %d",
            $limit
        ));

        $count = 0;
        foreach ($ids as $id) {
            $this->run_online_match(intval($id));
            $count++;
        }
        return $count;
    }

    /**
     * Fija un estado humano (VERIFIED / REJECTED) y completa la revisión.
     */
    public function set_state($pp_id, $estado) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = intval($pp_id);

        if (!in_array($estado, ['VERIFIED', 'REJECTED', 'HUMAN_REVIEW'], true)) {
            return new WP_Error('invalid', 'Estado no permitido');
        }

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Producto proveedor no encontrado');
        }

        $wpdb->update(
            "{$prefix}producto_proveedor",
            [
                'match_estado' => $estado,
                'match_origen' => 'human',
                'matched_at' => current_time('mysql'),
                'requires_human_review' => ($estado === 'VERIFIED') ? 0 : 1,
            ],
            ['id' => $pp_id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('match_reviewed', 'producto_proveedor', $pp_id, [
                'old_value' => ['estado' => $pp['match_estado']],
                'new_value' => ['estado' => $estado],
            ]);
        }

        return true;
    }

    public function set_online_state($producto_base_id, $estado, $candidate_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = absint($producto_base_id);

        if (!in_array($estado, ['CONFIRMED', 'REJECTED', 'PENDING_REVIEW'], true)) {
            return new WP_Error('invalid', 'Estado online no permitido');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $candidate_id = absint($candidate_id ?: ($pb['woocommerce_candidate_id'] ?? 0));
        $payload = [
            'match_estado_online' => $estado,
            'match_origen_online' => 'human',
            'matched_online_at' => current_time('mysql'),
            'requires_human_review' => $estado === 'CONFIRMED' ? 0 : 1,
        ];
        $formats = ['%s', '%s', '%s', '%d'];

        if ($estado === 'CONFIRMED') {
            if (!$candidate_id) {
                return new WP_Error('missing_candidate', 'Candidato WooCommerce requerido');
            }
            $product = wc_get_product($candidate_id);
            if (!$product) {
                return new WP_Error('missing_wc', 'Producto WooCommerce no encontrado');
            }
            $payload['woocommerce_product_id'] = $product->is_type('variation') ? $product->get_parent_id() : $candidate_id;
            $payload['woocommerce_variation_id'] = $product->is_type('variation') ? $candidate_id : 0;
            $payload['woocommerce_candidate_id'] = $candidate_id;
            $payload['human_product_review'] = 'approved';
            $payload['publication_stage'] = 'human_verified';
            $formats = array_merge($formats, ['%d', '%d', '%d', '%s', '%s']);
        }

        $wpdb->update("{$prefix}producto_base", $payload, ['id' => $producto_base_id], $formats, ['%d']);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('online_match_reviewed', 'producto_base', $producto_base_id, [
                'actor_type' => 'human',
                'old_value' => [
                    'estado' => $pb['match_estado_online'] ?? null,
                    'woocommerce_product_id' => $pb['woocommerce_product_id'] ?? null,
                ],
                'new_value' => $payload,
            ]);
        }

        return true;
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $estado = sanitize_text_field($_POST['estado'] ?? '');
        $where = '1=1';
        $params = [];
        if ($estado && in_array($estado, self::ESTADOS, true)) {
            $where = 'pp.match_estado = %s';
            $params[] = $estado;
        }
        $limit = min(200, max(1, intval($_POST['limit'] ?? 100)));

        $sql = "SELECT pp.id, pp.codigo_proveedor, pp.codigo_barras_proveedor, pp.nombre_proveedor,
                       pp.match_estado, pp.match_score, pp.match_origen,
                       pp.producto_base_id, pp.grupo_id,
                       pb.canonical_sku, pb.nombre_canonico,
                       eg.nombre AS familia_nombre, eg.codigo_grupo AS familia_codigo
                FROM {$prefix}producto_proveedor pp
                LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                LEFT JOIN {$prefix}equivalence_groups eg ON eg.id = pp.grupo_id
                WHERE {$where}
                ORDER BY pp.match_score ASC, pp.id DESC
                LIMIT {$limit}";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        wp_send_json_success(['items' => $rows, 'estados' => self::ESTADOS]);
    }

    public function ajax_run() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->run_match(intval($_POST['pp_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['item' => $result]);
    }

    public function ajax_run_all() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $count = $this->run_batch(intval($_POST['limit'] ?? 200));
        wp_send_json_success(['processed' => $count]);
    }

    public function ajax_set_state() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->set_state(
            intval($_POST['pp_id'] ?? 0),
            sanitize_text_field($_POST['estado'] ?? '')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Estado actualizado']);
    }

    public function ajax_online_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $estado = sanitize_text_field($_POST['estado'] ?? '');
        $where = 'pb.deleted_at IS NULL';
        $params = [];

        if ($estado && in_array($estado, self::ONLINE_ESTADOS, true)) {
            $where .= ' AND pb.match_estado_online = %s';
            $params[] = $estado;
        }

        $limit = min(200, max(1, intval($_POST['limit'] ?? 100)));
        $sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, pb.woocommerce_product_id,
                       pb.woocommerce_variation_id, pb.woocommerce_candidate_id,
                       pb.match_estado_online, pb.match_score_online, pb.match_origen_online,
                       pb.publication_stage
                FROM {$prefix}producto_base pb
                WHERE {$where}
                ORDER BY pb.match_score_online ASC, pb.id DESC
                LIMIT {$limit}";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as &$row) {
            $candidate = !empty($row['woocommerce_candidate_id']) ? wc_get_product((int) $row['woocommerce_candidate_id']) : null;
            $row['candidate_name'] = $candidate ? $candidate->get_name() : '';
            $row['candidate_sku'] = $candidate ? $candidate->get_sku() : '';
        }

        wp_send_json_success(['items' => $rows, 'estados' => self::ONLINE_ESTADOS]);
    }

    public function ajax_online_run() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->run_online_match(intval($_POST['producto_base_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['item' => $result]);
    }

    public function ajax_online_run_all() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $count = $this->run_online_batch(intval($_POST['limit'] ?? 100));
        wp_send_json_success(['processed' => $count]);
    }

    public function ajax_online_set_state() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->set_online_state(
            intval($_POST['producto_base_id'] ?? 0),
            sanitize_text_field($_POST['estado'] ?? ''),
            intval($_POST['candidate_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Estado online actualizado']);
    }

    /**
     * Asigna un producto_proveedor a una familia (grupo_id) en lugar de producto_base.
     * Mantiene la regla: exactamente uno de (producto_base_id, grupo_id) es NOT NULL.
     *
     * @param int $pp_id ID del producto_proveedor
     * @param int $grupo_id ID del grupo de equivalencia
     * @return bool|WP_Error
     */
    public function assign_to_family($pp_id, $grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = absint($pp_id);
        $grupo_id = absint($grupo_id);
        $user_id = get_current_user_id();

        // Validar producto_proveedor existe
        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id, grupo_id FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Producto proveedor no encontrado');
        }

        // Validar grupo de equivalencia existe
        $grupo = $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre FROM {$prefix}equivalence_groups WHERE id = %d AND activo = 1",
            $grupo_id
        ), ARRAY_A);
        if (!$grupo) {
            return new WP_Error('invalid_grupo', 'Grupo de equivalencia no encontrado o inactivo');
        }

        // Actualizar con SQL explícito: wpdb->update convierte NULL a '' con %s
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_proveedor SET
                producto_base_id = NULL,
                grupo_id = %d,
                assigned_to_family_at = %s,
                assigned_to_family_by = %d,
                match_estado = 'VERIFIED',
                match_origen = 'family_assignment',
                matched_at = %s,
                requires_human_review = 0
             WHERE id = %d",
            $grupo_id,
            current_time('mysql'),
            $user_id,
            current_time('mysql'),
            $pp_id
        ));

        if ($updated === false) {
            return new WP_Error('db_error', 'No se pudo asignar a familia: ' . $wpdb->last_error);
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('assign_to_family', 'producto_proveedor', $pp_id, [
                'old_value' => [
                    'producto_base_id' => $pp['producto_base_id'],
                    'grupo_id' => $pp['grupo_id'],
                ],
                'new_value' => [
                    'producto_base_id' => null,
                    'grupo_id' => $grupo_id,
                    'familia_nombre' => $grupo['nombre'],
                ],
            ]);
        }

        return true;
    }

    /**
     * Asigna un producto_proveedor a un producto_base en lugar de familia.
     * Mantiene la regla: exactamente uno de (producto_base_id, grupo_id) es NOT NULL.
     *
     * @param int $pp_id ID del producto_proveedor
     * @param int $producto_base_id ID del producto base
     * @return bool|WP_Error
     */
    public function assign_to_product($pp_id, $producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = absint($pp_id);
        $producto_base_id = absint($producto_base_id);
        $user_id = get_current_user_id();

        // Validar producto_proveedor existe
        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id, grupo_id, codigo_proveedor, factor_conversion, notas
             FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Producto proveedor no encontrado');
        }

        // Validar producto_base existe
        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('invalid_producto', 'Producto base no encontrado');
        }

        $pending_grupo_id = !empty($pp['grupo_id']) ? intval($pp['grupo_id']) : 0;

        // Si venía pendiente en una familia, promover a miembro ANTES de limpiar grupo_id.
        $promote_result = null;
        if ($pending_grupo_id && class_exists('Riverso_Family_Module')) {
            $promote_result = Riverso_Family_Module::get_instance()->promote_pending_supplier_to_member(
                $producto_base_id,
                $pending_grupo_id,
                $pp
            );
            if (is_array($promote_result) && empty($promote_result['promoted'])
                && ($promote_result['reason'] ?? '') === 'exacta_conflict'
            ) {
                $other = $promote_result['other_family'] ?? [];
                return new WP_Error(
                    'family_exacta_conflict',
                    'El producto ya pertenece a otra familia exacta ("'
                    . ($other['nombre'] ?? $other['codigo_grupo'] ?? '')
                    . '"). No se puede promover el pendiente de esta familia.'
                );
            }
        }

        // Actualizar con SQL explícito para NULL en grupo_id
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_proveedor SET
                producto_base_id = %d,
                grupo_id = NULL,
                assigned_to_family_at = NULL,
                assigned_to_family_by = NULL,
                match_estado = 'VERIFIED',
                match_origen = 'product_assignment',
                matched_at = %s,
                requires_human_review = 0
             WHERE id = %d",
            $producto_base_id,
            current_time('mysql'),
            $pp_id
        ));

        if ($updated === false) {
            return new WP_Error('db_error', 'No se pudo asignar a producto: ' . $wpdb->last_error);
        }

        // Auditoría
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('assign_to_product', 'producto_proveedor', $pp_id, [
                'old_value' => [
                    'producto_base_id' => $pp['producto_base_id'],
                    'grupo_id' => $pp['grupo_id'],
                ],
                'new_value' => [
                    'producto_base_id' => $producto_base_id,
                    'producto_sku' => $pb['canonical_sku'],
                    'producto_nombre' => $pb['nombre_canonico'],
                    'grupo_id' => null,
                ],
                'family_promote' => $promote_result,
            ]);
        }

        return true;
    }

    /**
     * Valida que un producto_proveedor tenga exactamente uno de (producto_base_id, grupo_id).
     * Retorna info sobre el destino (producto o familia).
     *
     * @param int $pp_id ID del producto_proveedor
     * @return array|WP_Error { 'tipo' => 'producto|familia', 'id' => ..., 'nombre' => ... }
     */
    public function get_assignment($pp_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = absint($pp_id);

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT id, producto_base_id, grupo_id FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Producto proveedor no encontrado');
        }

        // Validar regla: uno y solo uno
        $has_product = !empty($pp['producto_base_id']);
        $has_family = !empty($pp['grupo_id']);

        if (!$has_product && !$has_family) {
            return new WP_Error('unassigned', 'Producto proveedor sin asignación a producto ni familia');
        }
        if ($has_product && $has_family) {
            return new WP_Error('ambiguous', 'Producto proveedor tiene ambos destinos (bug)');
        }

        if ($has_product) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                $pp['producto_base_id']
            ), ARRAY_A);
            return [
                'tipo' => 'producto',
                'id' => intval($pp['producto_base_id']),
                'nombre' => $pb['nombre_canonico'] ?? $pb['canonical_sku'],
                'sku' => $pb['canonical_sku'],
            ];
        } else {
            $grupo = $wpdb->get_row($wpdb->prepare(
                "SELECT id, nombre, codigo_grupo FROM {$prefix}equivalence_groups WHERE id = %d",
                $pp['grupo_id']
            ), ARRAY_A);
            return [
                'tipo' => 'familia',
                'id' => intval($pp['grupo_id']),
                'nombre' => $grupo['nombre'],
                'codigo' => $grupo['codigo_grupo'],
            ];
        }
    }

    /**
     * AJAX: assign_to_family
     * Asigna un producto_proveedor a una familia
     */
    public function ajax_assign_to_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->assign_to_family(
            intval($_POST['pp_id'] ?? 0),
            intval($_POST['grupo_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Asignado a familia']);
    }

    /**
     * AJAX: assign_to_product
     * Asigna un producto_proveedor a un producto base
     */
    public function ajax_assign_to_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->assign_to_product(
            intval($_POST['pp_id'] ?? 0),
            intval($_POST['producto_base_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Asignado a producto']);
    }

    /**
     * AJAX: get_assignment
     * Obtiene la asignación actual de un producto_proveedor
     */
    public function ajax_get_assignment() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->get_assignment(intval($_POST['pp_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['assignment' => $result]);
    }

    /**
     * AJAX: lista familias (equivalence_groups) activas para el selector de matching
     */
    public function ajax_list_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_matching')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT id, codigo_grupo, nombre
             FROM {$prefix}equivalence_groups
             WHERE activo = 1
             ORDER BY nombre ASC
             LIMIT 500",
            ARRAY_A
        );

        wp_send_json_success(['families' => $rows ?: []]);
    }
}
