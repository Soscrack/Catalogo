<?php
/**
 * Módulo Recepción de compras (portal /interno/recepcion/ y wp-admin).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Reception_Module {

    const NONCE = 'riverso_reception';

    private static $instance = null;

    /** @var Riverso_Purchase_Reception_Service */
    private $service;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $file = dirname(__FILE__) . '/class-purchase-reception-service.php';
        if (file_exists($file)) {
            require_once $file;
        }
        $this->service = Riverso_Purchase_Reception_Service::get_instance();
        $this->init_hooks();
    }

    public function init() {
        // Bootstrap del plugin.
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_rx_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_rx_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_rx_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_riverso_rx_search', [$this, 'ajax_search']);
        add_action('wp_ajax_riverso_rx_receive', [$this, 'ajax_receive']);
        add_action('wp_ajax_riverso_rx_cancel', [$this, 'ajax_cancel']);
        add_action('wp_ajax_riverso_rx_ignore', [$this, 'ajax_ignore']);
        add_action('wp_ajax_riverso_rx_restore', [$this, 'ajax_restore']);
        add_action('wp_ajax_riverso_rx_order', [$this, 'ajax_order']);
        add_action('wp_ajax_riverso_rx_claim', [$this, 'ajax_claim']);
        add_action('wp_ajax_riverso_rx_claims', [$this, 'ajax_claims']);
        add_action('wp_ajax_riverso_rx_claim_update', [$this, 'ajax_claim_update']);
        add_action('wp_ajax_riverso_rx_credit_notes', [$this, 'ajax_credit_notes']);
        add_action('wp_ajax_riverso_rx_destinations', [$this, 'ajax_destinations']);
        add_action('wp_ajax_riverso_rx_scan_inbox', [$this, 'ajax_scan_inbox']);
    }

    /**
     * @return Riverso_Purchase_Reception_Service
     */
    public function service() {
        return $this->service;
    }

    /**
     * @param string|null $surface
     * @return array<string, mixed>
     */
    public function app_config($surface = 'portal') {
        $base = $surface === 'admin'
            ? admin_url('admin.php?page=riverso-pos-reception')
            : home_url('/interno/recepcion/');
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'surface' => $surface,
            'baseUrl' => $base,
            'invoicesUrl' => $surface === 'admin'
                ? admin_url('admin.php?page=riverso-pos-invoices')
                : home_url('/interno/invoices/'),
            // Ingreso del documento que llega con el pedido (escaneos / XML; nonce de Facturas).
            'scanNonce' => wp_create_nonce('riverso_pos_nonce'),
            'canProcessFolio' => current_user_can('riverso_view_prices') || current_user_can('manage_options'),
            'procesarFolioUrl' => admin_url('admin.php?page=riverso-pos-pricing&tab=process&factura_id='),
            'scansUrl' => admin_url('admin.php?page=riverso-pos-invoices'),
            'motivos' => Riverso_Purchase_Reception_Service::MOTIVOS,
            'claimStates' => Riverso_Purchase_Reception_Service::CLAIM_STATES,
            'actions' => [
                'list' => 'riverso_rx_list',
                'get' => 'riverso_rx_get',
                'scan' => 'riverso_rx_scan',
                'search' => 'riverso_rx_search',
                'receive' => 'riverso_rx_receive',
                'cancel' => 'riverso_rx_cancel',
                'ignore' => 'riverso_rx_ignore',
                'restore' => 'riverso_rx_restore',
                'order' => 'riverso_rx_order',
                'claim' => 'riverso_rx_claim',
                'claims' => 'riverso_rx_claims',
                'claimUpdate' => 'riverso_rx_claim_update',
                'creditNotes' => 'riverso_rx_credit_notes',
                'destinations' => 'riverso_rx_destinations',
                'scanInbox' => 'riverso_rx_scan_inbox',
                'scanUpload' => 'riverso_scan_upload',
                'scanStatus' => 'riverso_scan_archivo_status',
                'scanConfirm' => 'riverso_scan_confirm',
                'xmlUpload' => 'riverso_upload_invoice',
            ],
        ];
    }

    /**
     * @param string|null $surface
     */
    public function render_app($surface = null) {
        if ($surface !== 'portal' && $surface !== 'admin') {
            $surface = (function_exists('is_admin') && is_admin()) ? 'admin' : 'portal';
        }
        if (!$this->can_receive()) {
            echo '<p>No tienes permiso para registrar recepciones.</p>';
            return;
        }
        if (!$this->service->ready()) {
            echo '<p>Recepción no disponible: falta la migración de base de datos.</p>';
            return;
        }
        $riverso_rx = $this->app_config($surface);
        include RIVERSO_POS_PLUGIN_DIR . 'templates/reception/app.php';
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        $this->authorize();
        $this->ok($this->service->list_documents([
            'filtro' => $this->post_string('filtro'),
            'buscar' => $this->post_string('buscar'),
            'pagina' => (int) $this->post_string('pagina'),
            'por_pagina' => (int) $this->post_string('por_pagina'),
        ]));
    }

    public function ajax_get() {
        $this->authorize();
        $doc = $this->service->get_document((int) $this->post_string('id'));
        if (!$doc) {
            $this->fail('Documento no encontrado.', 404);
        }
        $this->ok(['doc' => $doc]);
    }

    public function ajax_scan() {
        $this->authorize();
        $this->ok($this->service->resolve_scan((int) $this->post_string('id'), $this->post_string('code')));
    }

    public function ajax_search() {
        $this->authorize();
        $this->ok(['productos' => $this->service->search_products($this->post_string('q'))]);
    }

    public function ajax_receive() {
        $this->authorize();
        $this->run(function () {
            return ['doc' => $this->service->receive(
                (int) $this->post_string('id'),
                $this->post_json('lines'),
                $this->post_string('cerrar') === '1'
            )];
        });
    }

    public function ajax_cancel() {
        $this->authorize();
        $this->run(function () {
            $id = (int) $this->post_string('id');
            $this->service->cancel($id);
            return ['doc' => $this->service->get_document($id)];
        });
    }

    public function ajax_ignore() {
        $this->authorize();
        $ids = array_map('intval', $this->post_json('ids'));
        $single = (int) $this->post_string('id');
        if ($single > 0) {
            $ids[] = $single;
        }
        $count = $this->service->ignore($ids, $this->post_string('motivo'));
        $this->ok(['ignored' => $count]);
    }

    public function ajax_restore() {
        $this->authorize();
        if (!$this->service->restore((int) $this->post_string('id'))) {
            $this->fail('El documento no está ignorado.');
        }
        $this->ok(['restored' => true]);
    }

    public function ajax_order() {
        $this->authorize();
        $this->run(function () {
            return ['doc' => $this->service->order((int) $this->post_string('id'), $this->post_json('moves'))];
        });
    }

    public function ajax_claim() {
        $this->authorize();
        $this->run(function () {
            return ['doc' => $this->service->claim((int) $this->post_string('id'), $this->post_json('claims'))];
        });
    }

    public function ajax_claims() {
        $this->authorize();
        $estado = $this->post_string('estado') ?: 'abiertos';
        $this->ok([
            'claims' => $this->service->list_claims(['estado' => $estado]),
            'open' => $this->service->count_open_claims(),
        ]);
    }

    public function ajax_claim_update() {
        $this->authorize();
        $this->run(function () {
            $data = ['nota_credito_id' => (int) $this->post_string('nota_credito_id')];
            if (isset($_POST['notas'])) {
                $data['notas'] = sanitize_textarea_field(wp_unslash((string) $_POST['notas']));
            }
            return ['claim' => $this->service->update_claim(
                (int) $this->post_string('claim_id'),
                $this->post_string('op'),
                $data
            )];
        });
    }

    public function ajax_credit_notes() {
        $this->authorize();
        $this->ok(['notes' => $this->service->credit_note_candidates((int) $this->post_string('claim_id'))]);
    }

    public function ajax_scan_inbox() {
        $this->authorize();
        $this->ok($this->service->scan_inbox($this->post_string('buscar')));
    }

    public function ajax_destinations() {
        $this->authorize();
        $this->ok(['locations' => $this->service->destinations()]);
    }

    /* ===================== Helpers ===================== */

    /**
     * @param callable $fn
     */
    private function run($fn) {
        try {
            $this->ok($fn());
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    private function can_receive() {
        return current_user_can('riverso_receive_items')
            || current_user_can('riverso_approve_reception')
            || current_user_can('manage_options');
    }

    private function authorize() {
        if (!$this->can_receive() || !wp_verify_nonce($this->post_string('nonce'), self::NONCE)) {
            $this->fail('No tienes permiso para registrar recepciones.', 403);
        }
        if (!$this->service->ready()) {
            $this->fail('Recepción no disponible: falta la migración de base de datos.', 500);
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private function post_string($key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    /**
     * @param string $key
     * @return array
     */
    private function post_json($key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return [];
        }
        $data = json_decode(wp_unslash($_POST[$key]), true);
        return is_array($data) ? $data : [];
    }

    private function ok(array $data) {
        wp_send_json_success($data);
    }

    private function fail($message, $status = 400) {
        wp_send_json_error(['message' => $message], $status);
    }
}
