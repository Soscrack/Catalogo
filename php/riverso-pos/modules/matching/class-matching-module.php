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
                       pb.id AS producto_base_id, pb.canonical_sku, pb.nombre_canonico
                FROM {$prefix}producto_proveedor pp
                INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
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
}
