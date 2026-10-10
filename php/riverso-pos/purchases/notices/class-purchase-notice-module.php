<?php
/**
 * Módulo Avisos de compra (portal /interno/avisos/).
 *
 * Reemplaza el grupo de WhatsApp de encargos: bodega y mesón avisan qué falta,
 * quien arma el pedido lo ve por proveedor y marca "ingresado".
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Notice_Module {

    const NONCE = 'riverso_avisos';

    private static $instance = null;

    /** @var Riverso_Purchase_Notice_Service */
    private $service;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $file = dirname(__FILE__) . '/class-purchase-notice-service.php';
        if (file_exists($file)) {
            require_once $file;
        }
        $this->service = Riverso_Purchase_Notice_Service::get_instance();
        $this->init_hooks();
    }

    public function init() {
        // Bootstrap del plugin.
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_av_resolve', [$this, 'ajax_resolve']);
        add_action('wp_ajax_riverso_av_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_av_join', [$this, 'ajax_join']);
        add_action('wp_ajax_riverso_av_mine', [$this, 'ajax_mine']);
        add_action('wp_ajax_riverso_av_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_av_update', [$this, 'ajax_update']);
        add_action('wp_ajax_riverso_av_suppliers', [$this, 'ajax_suppliers']);
    }

    /**
     * @return Riverso_Purchase_Notice_Service
     */
    public function service() {
        return $this->service;
    }

    /**
     * Quien ya cuenta inventario o atiende el mesón puede avisar.
     *
     * @return bool
     */
    public function can_report() {
        return current_user_can('riverso_report_shortage')
            || current_user_can('riverso_do_inventory')
            || current_user_can('riverso_edit_stock')
            || $this->can_manage();
    }

    /**
     * Bandeja de quien arma el pedido.
     *
     * @return bool
     */
    public function can_manage() {
        return current_user_can('riverso_manage_purchase_notices')
            || current_user_can('riverso_edit_purchases')
            || current_user_can('manage_options');
    }

    /**
     * @return array<string, mixed>
     */
    public function app_config() {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'baseUrl' => home_url('/interno/avisos/'),
            'userId' => get_current_user_id(),
            'canManage' => $this->can_manage(),
            'units' => Riverso_Purchase_Notice_Service::UNITS,
            'discardReasons' => Riverso_Purchase_Notice_Service::DISCARD_REASONS,
            'counts' => $this->service->counts(),
            'actions' => [
                'resolve' => 'riverso_av_resolve',
                'create' => 'riverso_av_create',
                'join' => 'riverso_av_join',
                'mine' => 'riverso_av_mine',
                'list' => 'riverso_av_list',
                'update' => 'riverso_av_update',
                'suppliers' => 'riverso_av_suppliers',
            ],
        ];
    }

    public function render_app() {
        if (!$this->can_report()) {
            echo '<p>No tienes permiso para avisar faltantes.</p>';
            return;
        }
        if (!$this->service->ready()) {
            echo '<p>Avisos no disponible: falta la migración de base de datos.</p>';
            return;
        }
        $riverso_av = $this->app_config();
        include RIVERSO_POS_PLUGIN_DIR . 'templates/avisos/app.php';
    }

    /* ===================== AJAX ===================== */

    public function ajax_resolve() {
        $this->authorize();
        $this->ok($this->service->resolve(
            $this->post_string('q'),
            $this->post_string('origen') === 'camara' ? 'camara' : 'teclado',
            (int) $this->post_string('proveedor_id')
        ));
    }

    public function ajax_create() {
        $this->authorize();
        $this->run(function () {
            $file = (isset($_FILES['foto']) && is_array($_FILES['foto'])) ? $_FILES['foto'] : null;
            return ['aviso' => $this->service->create($this->notice_input(), $file)];
        });
    }

    public function ajax_join() {
        $this->authorize();
        $this->run(function () {
            return ['aviso' => $this->service->join((int) $this->post_string('id'), $this->notice_input())];
        });
    }

    public function ajax_mine() {
        $this->authorize();
        $this->ok(['avisos' => $this->service->mine(get_current_user_id())]);
    }

    public function ajax_list() {
        $this->authorize(true);
        $filters = [
            'estado' => $this->post_string('estado'),
            'buscar' => $this->post_string('buscar'),
        ];
        if (isset($_POST['proveedor_id']) && $_POST['proveedor_id'] !== '') {
            $filters['proveedor_id'] = (int) $this->post_string('proveedor_id');
        }
        $this->ok($this->service->list_notices($filters));
    }

    public function ajax_update() {
        // Quien avisó puede retirar su propio aviso; el resto lo valida el servicio.
        $this->authorize();
        $this->run(function () {
            return [
                'aviso' => $this->service->update(
                    (int) $this->post_string('id'),
                    sanitize_key($this->post_string('op')),
                    $this->notice_input(),
                    $this->can_manage()
                ),
                'counts' => $this->service->counts(),
            ];
        });
    }

    public function ajax_suppliers() {
        $this->authorize();
        $this->ok(['proveedores' => $this->service->suppliers($this->post_string('q'))]);
    }

    /* ===================== Helpers ===================== */

    /**
     * @return array<string, mixed>
     */
    private function notice_input() {
        $data = [];
        foreach ([
            'producto_base_id', 'producto_proveedor_id', 'proveedor_id', 'codigo_leido', 'origen_codigo',
            'match_fuente', 'texto', 'cantidad', 'unidad', 'factor', 'factor_origen', 'motivo',
        ] as $key) {
            if (isset($_POST[$key])) {
                $data[$key] = $this->post_string($key);
            }
        }
        if (isset($_POST['nota']) && is_string($_POST['nota'])) {
            $data['nota'] = sanitize_textarea_field(wp_unslash($_POST['nota']));
        }
        $data['sin_stock'] = $this->post_string('sin_stock') === '1';
        return $data;
    }

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

    /**
     * @param bool $manage Exige permiso de bandeja.
     */
    private function authorize($manage = false) {
        $allowed = $manage ? $this->can_manage() : $this->can_report();
        if (!$allowed || !wp_verify_nonce($this->post_string('nonce'), self::NONCE)) {
            $this->fail('No tienes permiso para esta acción.', 403);
        }
        if (!$this->service->ready()) {
            $this->fail('Avisos no disponible: falta la migración de base de datos.', 500);
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

    private function ok(array $data) {
        wp_send_json_success($data);
    }

    private function fail($message, $status = 400) {
        wp_send_json_error(['message' => $message], $status);
    }
}
