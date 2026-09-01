<?php
/**
 * Módulo Competencia — staging Sande + matching supervisado.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/competencia/class-competencia-match-service.php';

class Riverso_Competencia_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_competencia_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_competencia_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_riverso_competencia_confirm_match', [$this, 'ajax_confirm_match']);
        add_action('wp_ajax_riverso_competencia_confirm_preflight', [$this, 'ajax_confirm_preflight']);
        add_action('wp_ajax_riverso_competencia_reject_match', [$this, 'ajax_reject_match']);
        add_action('wp_ajax_riverso_competencia_manual_match', [$this, 'ajax_manual_match']);
        add_action('wp_ajax_riverso_competencia_search_local', [$this, 'ajax_search_local']);
        add_action('wp_ajax_riverso_competencia_stats', [$this, 'ajax_stats']);
        add_action('wp_ajax_riverso_competencia_price_history', [$this, 'ajax_price_history']);
        add_action('wp_ajax_riverso_competencia_price_series', [$this, 'ajax_price_series']);
    }

    private function guard() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_competencia')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    public function ajax_list() {
        $this->guard();
        $result = Riverso_Competencia_Match_Service::list_matches([
            'fuente'   => isset($_POST['fuente']) ? sanitize_key(wp_unslash($_POST['fuente'])) : 'sande',
            'seccion'  => isset($_POST['seccion']) ? sanitize_key(wp_unslash($_POST['seccion'])) : '',
            'estado'   => isset($_POST['estado']) ? sanitize_key(wp_unslash($_POST['estado'])) : '',
            'metodo'   => isset($_POST['metodo']) ? sanitize_key(wp_unslash($_POST['metodo'])) : '',
            'search'   => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'page'     => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'per_page' => isset($_POST['per_page']) ? (int) $_POST['per_page'] : 25,
        ]);
        wp_send_json_success($result);
    }

    public function ajax_stats() {
        $this->guard();
        $fuente = isset($_POST['fuente']) ? sanitize_key(wp_unslash($_POST['fuente'])) : 'sande';
        $result = Riverso_Competencia_Match_Service::list_matches([
            'fuente'   => $fuente,
            'per_page' => 1,
            'page'     => 1,
        ]);
        wp_send_json_success(['stats' => $result['stats'], 'total' => $result['total']]);
    }

    public function ajax_suggest() {
        $this->guard();
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 500;
        $result = Riverso_Competencia_Match_Service::run_suggestions(0, $limit);
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('competencia.suggest', 'competencia', 0, [
                'actor_type' => 'user',
                'details'    => $result,
            ]);
        }
        wp_send_json_success($result);
    }

    public function ajax_confirm_preflight() {
        $this->guard();
        $competencia_id = isset($_POST['producto_competencia_id']) ? (int) $_POST['producto_competencia_id'] : 0;
        $producto_base_id = isset($_POST['producto_base_id']) ? (int) $_POST['producto_base_id'] : 0;
        if ($competencia_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido'], 400);
        }
        $preflight = Riverso_Competencia_Match_Service::confirm_preflight($competencia_id, $producto_base_id);
        if (empty($preflight['ok'])) {
            wp_send_json_error(['message' => $preflight['message'] ?? 'Error'], 404);
        }
        wp_send_json_success($preflight);
    }

    public function ajax_confirm_match() {
        $this->guard();
        $competencia_id = isset($_POST['producto_competencia_id']) ? (int) $_POST['producto_competencia_id'] : 0;
        $producto_base_id = isset($_POST['producto_base_id']) ? (int) $_POST['producto_base_id'] : 0;
        $nota = isset($_POST['nota']) ? sanitize_textarea_field(wp_unslash($_POST['nota'])) : '';

        if ($competencia_id <= 0 || $producto_base_id <= 0) {
            wp_send_json_error(['message' => 'IDs inválidos'], 400);
        }

        $match_id = Riverso_Competencia_Match_Service::confirm_match(
            $competencia_id,
            $producto_base_id,
            get_current_user_id(),
            $nota
        );

        if (is_wp_error($match_id)) {
            wp_send_json_error([
                'message'   => $match_id->get_error_message(),
                'blockers'  => $match_id->get_error_data()['blockers'] ?? [],
                'unit_hint' => $match_id->get_error_data()['unit_hint'] ?? '',
            ], 409);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('competencia.confirm', 'competencia', $competencia_id, [
                'actor_type' => 'user',
                'details'    => [
                    'match_id'         => $match_id,
                    'producto_base_id' => $producto_base_id,
                ],
            ]);
        }

        wp_send_json_success(['match_id' => $match_id]);
    }

    public function ajax_reject_match() {
        $this->guard();
        $competencia_id = isset($_POST['producto_competencia_id']) ? (int) $_POST['producto_competencia_id'] : 0;
        $nota = isset($_POST['nota']) ? sanitize_textarea_field(wp_unslash($_POST['nota'])) : '';

        if ($competencia_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido'], 400);
        }

        $match_id = Riverso_Competencia_Match_Service::reject_match(
            $competencia_id,
            get_current_user_id(),
            $nota
        );

        wp_send_json_success(['match_id' => $match_id]);
    }

    public function ajax_manual_match() {
        $this->guard();
        $competencia_id = isset($_POST['producto_competencia_id']) ? (int) $_POST['producto_competencia_id'] : 0;
        $producto_base_id = isset($_POST['producto_base_id']) ? (int) $_POST['producto_base_id'] : 0;
        $nota = isset($_POST['nota']) ? sanitize_textarea_field(wp_unslash($_POST['nota'])) : '';

        if ($competencia_id <= 0 || $producto_base_id <= 0) {
            wp_send_json_error(['message' => 'IDs inválidos'], 400);
        }

        $match_id = Riverso_Competencia_Match_Service::upsert_match(
            $competencia_id,
            $producto_base_id,
            'manual',
            100,
            'sugerido',
            $nota ?: 'Match manual pendiente de confirmación'
        );

        wp_send_json_success(['match_id' => $match_id]);
    }

    public function ajax_search_local() {
        $this->guard();
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $rows = Riverso_Competencia_Match_Service::search_local_products($search, 25);
        wp_send_json_success(['products' => $rows]);
    }

    public function ajax_price_history() {
        $this->guard();
        $result = Riverso_Competencia_Match_Service::list_price_history([
            'fuente'          => isset($_POST['fuente']) ? sanitize_key(wp_unslash($_POST['fuente'])) : 'sande',
            'search'          => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
            'solo_vinculados' => !empty($_POST['solo_vinculados']),
            'page'            => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'per_page'        => isset($_POST['per_page']) ? (int) $_POST['per_page'] : 25,
        ]);
        wp_send_json_success($result);
    }

    public function ajax_price_series() {
        $this->guard();
        $id = isset($_POST['producto_competencia_id']) ? (int) $_POST['producto_competencia_id'] : 0;
        $result = Riverso_Competencia_Match_Service::get_price_series($id);
        if (!$result) {
            wp_send_json_error(['message' => 'Producto no encontrado'], 404);
        }
        wp_send_json_success($result);
    }
}
