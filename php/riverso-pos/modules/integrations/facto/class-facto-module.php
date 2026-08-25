<?php
/**
 * Módulo FACTO: settings, cron outbox, backfill y suscripción a eventos de producto.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-facto-client.php';
require_once __DIR__ . '/class-facto-product-sync.php';
require_once __DIR__ . '/class-facto-inbox-import.php';

class Riverso_Facto_Module {

    const CRON_HOOK = 'riverso_facto_process_outbox';

    private static $instance = null;

    /** @var Riverso_Facto_Product_Sync */
    private $sync;

    /** @var Riverso_Facto_Inbox_Import */
    private $inbox_import;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        $this->sync = new Riverso_Facto_Product_Sync();
        $this->inbox_import = new Riverso_Facto_Inbox_Import();

        add_action('wp_ajax_riverso_save_facto_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_riverso_facto_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_riverso_facto_reconcile', [$this, 'ajax_reconcile']);
        add_action('wp_ajax_riverso_facto_process_outbox', [$this, 'ajax_process_outbox']);
        add_action('wp_ajax_riverso_facto_inbox_estimate', [$this, 'ajax_inbox_estimate']);
        add_action('wp_ajax_riverso_facto_inbox_import', [$this, 'ajax_inbox_import']);
        add_action('wp_ajax_riverso_facto_inbox_runs', [$this, 'ajax_inbox_runs']);

        add_action(self::CRON_HOOK, [$this, 'cron_process_outbox']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        $this->schedule_cron();

        if (function_exists('riverso_event_subscribe')) {
            riverso_event_subscribe('product.created', [$this, 'on_product_created']);
            riverso_event_subscribe('product.updated', [$this, 'on_product_updated']);
            riverso_event_subscribe('product.archived', [$this, 'on_product_archived']);
            riverso_event_subscribe('product.deleted', [$this, 'on_product_archived']);
            riverso_event_subscribe('product.restored', [$this, 'on_product_updated']);
        } else {
            add_action('riverso_product_created', [$this, 'on_product_created'], 10, 2);
            add_action('riverso_product_updated', [$this, 'on_product_updated'], 10, 2);
            add_action('riverso_product_archived', [$this, 'on_product_archived'], 10, 2);
            add_action('riverso_product_deleted', [$this, 'on_product_archived'], 10, 2);
            add_action('riverso_product_restored', [$this, 'on_product_updated'], 10, 2);
        }
    }

    public function add_cron_schedules($schedules) {
        if (!isset($schedules['riverso_every_5_minutes'])) {
            $schedules['riverso_every_5_minutes'] = [
                'interval' => 300,
                'display'  => 'Cada 5 minutos (Riverso FACTO)',
            ];
        }
        return $schedules;
    }

    public function schedule_cron() {
        $desired = 'riverso_every_5_minutes';
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next) {
            // Migrar desde horario antiguo (hourly) a cada 5 min.
            $cron = _get_cron_array();
            $event = $cron[$next][self::CRON_HOOK] ?? null;
            $current = null;
            if (is_array($event)) {
                $first = reset($event);
                $current = is_array($first) ? ($first['schedule'] ?? null) : null;
            }
            if ($current && $current !== $desired) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
                $next = false;
            }
        }
        if (!$next) {
            wp_schedule_event(time() + 60, $desired, self::CRON_HOOK);
        }
    }

    public function cron_process_outbox() {
        $this->sync->process_outbox(40);
    }

    /**
     * Empuja el outbox en ~15s (WP-Cron) sin esperar el intervalo de 5 min.
     */
    private function kick_outbox_soon() {
        if (get_transient('riverso_facto_outbox_kick')) {
            return;
        }
        set_transient('riverso_facto_outbox_kick', 1, 30);
        wp_schedule_single_event(time() + 15, self::CRON_HOOK);
    }

    public function on_product_created($payload = [], $context = []) {
        $id = $this->payload_id($payload);
        if ($id) {
            $this->sync->enqueue($id, 'create', is_array($payload) ? $payload : []);
            $this->sync->process_outbox(5);
        }
    }

    public function on_product_updated($payload = [], $context = []) {
        $id = $this->payload_id($payload);
        if ($id) {
            $this->sync->enqueue($id, 'update', is_array($payload) ? $payload : []);
            $this->sync->process_outbox(5);
        }
    }

    public function on_product_archived($payload = [], $context = []) {
        $id = $this->payload_id($payload);
        if ($id) {
            $this->sync->enqueue($id, 'archive', is_array($payload) ? $payload : []);
            $this->sync->process_outbox(5);
        }
    }

    private function payload_id($payload) {
        if (is_array($payload)) {
            return absint($payload['id'] ?? $payload['producto_base_id'] ?? 0);
        }
        return absint($payload);
    }

    private function can_manage() {
        return current_user_can('riverso_manage_settings') || current_user_can('manage_options');
    }

    private function can_import_invoices() {
        return current_user_can('riverso_process_invoices')
            || current_user_can('riverso_manage_settings')
            || current_user_can('manage_options');
    }

    public function ajax_inbox_estimate() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_import_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        if (!riverso_facto_is_configured()) {
            wp_send_json_error(['message' => 'FACTO no configurado']);
        }

        $from = sanitize_text_field(wp_unslash($_POST['fecha_desde'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_POST['fecha_hasta'] ?? ''));
        $result = $this->inbox_import->estimate_range($from, $to);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_inbox_import() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_import_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        if (!riverso_facto_is_configured()) {
            wp_send_json_error(['message' => 'FACTO no configurado']);
        }

        $from = sanitize_text_field(wp_unslash($_POST['fecha_desde'] ?? ''));
        $to = sanitize_text_field(wp_unslash($_POST['fecha_hasta'] ?? ''));
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $batch = isset($_POST['pages']) ? absint($_POST['pages']) : 3;
        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        $force_reprocess = !empty($_POST['force_reprocess']);

        if ($run_id <= 0) {
            $estimate = $this->inbox_import->estimate_range($from, $to);
            if (is_wp_error($estimate)) {
                wp_send_json_error(['message' => $estimate->get_error_message()]);
            }
            $run_id = $this->inbox_import->create_run(
                $estimate['fecha_desde'],
                $estimate['fecha_hasta'],
                $estimate['page_from'],
                $estimate['page_to']
            );
            if ($page < (int) $estimate['page_from']) {
                $page = (int) $estimate['page_from'];
            }
        }

        $result = $this->inbox_import->import_batch($run_id, $from, $to, $page, $batch, $force_reprocess);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'run_id' => $run_id]);
        }
        $result['run_id'] = $run_id;
        wp_send_json_success($result);
    }

    public function ajax_inbox_runs() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_import_invoices()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $runs = $this->inbox_import->list_runs(30);
        wp_send_json_success(['runs' => $runs]);
    }

    public function ajax_save_settings() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $keys = [
            'facto_enabled', 'facto_sync_enabled', 'facto_base_url',
            'facto_client_id', 'facto_client_secret', 'facto_username', 'facto_password',
            'facto_account_id', 'facto_price_list_id', 'facto_location_id',
            'facto_currency_id', 'facto_tax_type_id',
        ];

        foreach ($keys as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $val = sanitize_text_field(wp_unslash($_POST[$key]));
            if (in_array($key, ['facto_client_secret', 'facto_password'], true)) {
                if ($val === '' || preg_match('/^\*+$/', $val) || strpos($val, '****') !== false) {
                    continue;
                }
            }
            if (in_array($key, ['facto_enabled', 'facto_sync_enabled'], true)) {
                $val = $val ? 1 : 0;
            }
            riverso_set_setting($key, $val);
        }

        delete_transient(Riverso_Facto_Client::TOKEN_TRANSIENT);
        wp_send_json_success(['message' => 'Configuración FACTO guardada']);
    }

    public function ajax_test_connection() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $client = new Riverso_Facto_Client();
        $token = $client->get_token(true);
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()]);
        }
        $cats = $client->request('GET', 'product_categories');
        if (is_wp_error($cats)) {
            wp_send_json_error(['message' => 'Auth OK pero GET falló: ' . $cats->get_error_message()]);
        }
        $n = count(Riverso_Facto_Client::embed_collection($cats, 'product_categories'));
        wp_send_json_success([
            'message' => 'Conexión FACTO OK',
            'categories_sample_count' => $n,
        ]);
    }

    public function ajax_reconcile() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        if (!riverso_facto_is_configured()) {
            wp_send_json_error(['message' => 'FACTO no configurado']);
        }
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $batch = isset($_POST['pages']) ? absint($_POST['pages']) : 5;
        if ($page < 1) {
            $page = 1;
        }
        if ($batch < 1) {
            $batch = 5;
        }
        $result = $this->sync->reconcile_from_facto($page, $batch);
        if (!empty($result['errors'])) {
            wp_send_json_error(array_merge($result, [
                'message' => implode('; ', $result['errors']),
            ]));
        }
        wp_send_json_success($result);
    }

    public function ajax_process_outbox() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        wp_send_json_success($this->sync->process_outbox(25));
    }
}
