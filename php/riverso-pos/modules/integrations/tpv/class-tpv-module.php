<?php
/**
 * Módulo export TPV: catálogo XLSX para programa local offline.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tpv-export-service.php';

class Riverso_Tpv_Module {

    private static $instance = null;

    /** @var Riverso_Tpv_Export_Service */
    private $export_service;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        $this->export_service = new Riverso_Tpv_Export_Service();

        add_action('wp_ajax_riverso_tpv_export_preview', [$this, 'ajax_export_preview']);
        add_action('wp_ajax_riverso_tpv_export_download', [$this, 'ajax_export_download']);
        add_action('wp_ajax_riverso_tpv_export_mark_applied', [$this, 'ajax_export_mark_applied']);
        add_action('wp_ajax_riverso_tpv_export_batches', [$this, 'ajax_export_batches']);
    }

    private function can_export_tpv() {
        return current_user_can('riverso_export_facto')
            || current_user_can('riverso_manage_settings')
            || current_user_can('manage_options');
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_export_filters() {
        return [
            'sku'          => sanitize_text_field(wp_unslash($_POST['sku'] ?? $_GET['sku'] ?? '')),
            'only_changed' => !empty($_POST['only_changed'] ?? $_GET['only_changed'] ?? false),
        ];
    }

    public function ajax_export_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_export_tpv()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        wp_send_json_success($this->export_service->preview($this->parse_export_filters()));
    }

    public function ajax_export_download() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_export_tpv()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $result = $this->export_service->generate_batch($this->parse_export_filters());
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'data' => $result->get_error_data()]);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Content-Length: ' . strlen($result['binary']));
        header('X-Riverso-Batch-Id: ' . (int) $result['batch_id']);
        echo $result['binary'];
        exit;
    }

    public function ajax_export_mark_applied() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_export_tpv()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $batch_id = absint($_POST['batch_id'] ?? 0);
        $notas = sanitize_textarea_field(wp_unslash($_POST['notas'] ?? ''));
        if (!$batch_id) {
            wp_send_json_error(['message' => 'batch_id requerido']);
        }

        $result = $this->export_service->mark_batch_applied($batch_id, $notas);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Lote TPV marcado como aplicado']);
    }

    public function ajax_export_batches() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_export_tpv()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        wp_send_json_success(['batches' => $this->export_service->list_recent_batches(30)]);
    }
}
