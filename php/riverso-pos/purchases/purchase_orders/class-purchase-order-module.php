<?php
/**
 * Módulo Pedidos de compra (portal /interno/pedidos-compra/).
 *
 * Armar el pedido a un proveedor desde avisos y alertas, conciliarlo contra su cotización,
 * mandar la revisión de precios si corresponde y seguirlo hasta que Recepción lo recibe.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Order_Module {

    const NONCE = 'riverso_pedidos_compra';

    private static $instance = null;

    /** @var Riverso_Purchase_Order_Service */
    private $service;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $dir = dirname(__FILE__);
        foreach ([dirname($dir) . '/notices/class-purchase-notice-service.php', $dir . '/class-purchase-order-service.php'] as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }
        $this->service = Riverso_Purchase_Order_Service::get_instance();
        $this->init_hooks();
    }

    public function init() {
        // Bootstrap del plugin.
    }

    private function init_hooks() {
        foreach ([
            'list', 'get', 'tray', 'confirm', 'create', 'add_line', 'update_line', 'remove_line', 'move_line',
            'drop_line', 'send', 'text', 'cancel', 'resolve', 'suppliers', 'link_quote', 'unlink_quote', 'match',
            'decide', 'revision_sent', 'accept', 'link_doc', 'unlink_doc', 'rest',
        ] as $action) {
            add_action('wp_ajax_riverso_pc_' . $action, [$this, 'ajax_' . $action]);
        }
        // Recepción recibió, anuló o reclamó en un documento: lo recibido del pedido se recalcula.
        add_action('riverso_purchase_reception_changed', [$this, 'on_reception_changed']);
    }

    /**
     * @return Riverso_Purchase_Order_Service
     */
    public function service() {
        return $this->service;
    }

    /* ===================== Permisos ===================== */

    public function can_view() {
        return current_user_can('riverso_view_purchases') || $this->can_manage();
    }

    /**
     * Armar, editar líneas, enviar y cancelar. No usa riverso_edit_purchases: ese permiso
     * también abre la entrada directa de stock.
     */
    public function can_manage() {
        return current_user_can('riverso_manage_purchase_notices') || current_user_can('manage_options');
    }

    /** Decidir líneas de la cotización y marcar la revisión enviada. */
    public function can_decide() {
        return current_user_can('riverso_edit_received_quotes') || current_user_can('manage_options');
    }

    /** Aceptar la cotización: lo aceptado queda como llegada esperada. */
    public function can_approve() {
        return current_user_can('riverso_approve_received_quotes') || current_user_can('manage_options');
    }

    /* ===================== Página ===================== */

    /**
     * @return array<string, mixed>
     */
    public function app_config() {
        $actions = [];
        foreach ([
            'list', 'get', 'tray', 'confirm', 'create', 'addLine' => 'add_line', 'updateLine' => 'update_line',
            'removeLine' => 'remove_line', 'moveLine' => 'move_line', 'dropLine' => 'drop_line', 'send', 'text', 'cancel',
            'resolve', 'suppliers', 'linkQuote' => 'link_quote', 'unlinkQuote' => 'unlink_quote', 'match', 'decide',
            'revisionSent' => 'revision_sent', 'accept', 'linkDoc' => 'link_doc', 'unlinkDoc' => 'unlink_doc', 'rest',
        ] as $key => $action) {
            $actions[is_int($key) ? $action : $key] = 'riverso_pc_' . $action;
        }
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'baseUrl' => home_url('/interno/pedidos-compra/'),
            'canManage' => $this->can_manage(),
            'canDecide' => $this->can_decide(),
            'canApprove' => $this->can_approve(),
            'canReceive' => current_user_can('riverso_receive_items') || current_user_can('riverso_approve_reception') || current_user_can('manage_options'),
            'units' => Riverso_Purchase_Notice_Service::UNITS,
            'states' => Riverso_Purchase_Order_Service::STATES,
            'avisosUrl' => home_url('/interno/avisos/?vista=bandeja'),
            'quotesUrl' => home_url('/interno/received-quotes/'),
            'receptionUrl' => home_url('/interno/recepcion/'),
            // El borrador del correo de revisión es el de Cotizaciones Proveedores (nonce general del plugin).
            'claimDraftAction' => 'riverso_quote_claim_draft',
            'posNonce' => wp_create_nonce('riverso_pos_nonce'),
            'actions' => $actions,
        ];
    }

    public function render_app() {
        if (!$this->can_view()) {
            echo '<p>No tienes permiso para ver los pedidos de compra.</p>';
            return;
        }
        if (!$this->service->ready()) {
            echo '<p>Pedidos de compra no disponible: falta la migración de base de datos.</p>';
            return;
        }
        $riverso_pc = $this->app_config();
        include RIVERSO_POS_PLUGIN_DIR . 'templates/pedidos-compra/app.php';
    }

    /**
     * @param int $factura_id
     */
    public function on_reception_changed($factura_id) {
        try {
            $this->service->recalc_for_document((int) $factura_id);
        } catch (Exception $e) {
            // Recepción no falla porque un pedido no se pudo recalcular: se recalcula al abrir la ficha.
        }
    }

    /* ===================== AJAX: lista y ficha ===================== */

    public function ajax_list() {
        $this->authorize('view');
        $this->ok($this->service->list_orders([
            'estado' => $this->post_string('estado'),
            'buscar' => $this->post_string('buscar'),
        ]));
    }

    public function ajax_get() {
        $this->authorize('view');
        $order = $this->service->get_order((int) $this->post_string('id'));
        if (!$order) {
            $this->fail('Pedido no encontrado.', 404);
        }
        $this->ok(['pedido' => $order]);
    }

    public function ajax_text() {
        $this->authorize('view');
        $this->run(function () {
            return ['texto' => $this->service->order_text((int) $this->post_string('id'))];
        });
    }

    /* ===================== AJAX: armar ===================== */

    public function ajax_tray() {
        $this->authorize('manage');
        $this->ok($this->service->tray());
    }

    public function ajax_confirm() {
        $this->authorize('manage');
        $this->run(function () {
            return $this->service->confirm((int) $this->post_string('proveedor_id'), $this->post_json('lineas'));
        });
    }

    public function ajax_create() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->create_draft((int) $this->post_string('proveedor_id'))];
        });
    }

    public function ajax_add_line() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->add_line((int) $this->post_string('id'), $this->line_input())];
        });
    }

    public function ajax_update_line() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->update_line((int) $this->post_string('linea_id'), $this->line_input())];
        });
    }

    public function ajax_remove_line() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->remove_line((int) $this->post_string('linea_id'))];
        });
    }

    public function ajax_move_line() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->move_line(
                (int) $this->post_string('linea_id'),
                (int) $this->post_string('proveedor_id'),
                (int) $this->post_string('pp_id')
            )];
        });
    }

    public function ajax_drop_line() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->drop_line((int) $this->post_string('linea_id'))];
        });
    }

    public function ajax_send() {
        $this->authorize('manage');
        $this->run(function () {
            $order = $this->service->send((int) $this->post_string('id'));
            $text = $order['texto'];
            unset($order['texto']);
            return ['pedido' => $order, 'texto' => $text];
        });
    }

    public function ajax_cancel() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->cancel((int) $this->post_string('id'))];
        });
    }

    /** Buscar un producto para agregarlo a mano: el mismo buscador de Avisos. */
    public function ajax_resolve() {
        $this->authorize('manage');
        $this->ok(Riverso_Purchase_Notice_Service::get_instance()->resolve(
            $this->post_string('q'),
            'teclado',
            (int) $this->post_string('proveedor_id')
        ));
    }

    public function ajax_suppliers() {
        $this->authorize('manage');
        $this->ok(['proveedores' => Riverso_Purchase_Notice_Service::get_instance()->suppliers($this->post_string('q'))]);
    }

    /* ===================== AJAX: cotización ===================== */

    public function ajax_link_quote() {
        $this->authorize('decide');
        $this->run(function () {
            return ['pedido' => $this->service->link_quote((int) $this->post_string('id'), (int) $this->post_string('cotizacion_id'))];
        });
    }

    public function ajax_unlink_quote() {
        $this->authorize('decide');
        $this->run(function () {
            return ['pedido' => $this->service->unlink_quote((int) $this->post_string('id'))];
        });
    }

    public function ajax_match() {
        $this->authorize('decide');
        $this->run(function () {
            return ['pedido' => $this->service->set_match((int) $this->post_string('linea_id'), (int) $this->post_string('cot_item_id'))];
        });
    }

    public function ajax_decide() {
        $this->authorize('decide');
        $this->run(function () {
            return ['pedido' => $this->service->decide((int) $this->post_string('id'), [
                'linea_id' => (int) $this->post_string('linea_id'),
                'cot_item_id' => (int) $this->post_string('cot_item_id'),
                'decision' => $this->post_string('decision'),
            ])];
        });
    }

    public function ajax_revision_sent() {
        $this->authorize('decide');
        $this->run(function () {
            return ['pedido' => $this->service->mark_revision_sent((int) $this->post_string('id'))];
        });
    }

    public function ajax_accept() {
        $this->authorize('approve');
        $this->run(function () {
            return ['pedido' => $this->service->accept((int) $this->post_string('id'))];
        });
    }

    /* ===================== AJAX: recepción ===================== */

    public function ajax_link_doc() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->link_document(
                (int) $this->post_string('id'),
                (int) $this->post_string('factura_id'),
                $this->post_string('origen') === 'propuesto' ? 'propuesto' : 'manual'
            )];
        });
    }

    public function ajax_unlink_doc() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->unlink_document((int) $this->post_string('id'), (int) $this->post_string('factura_id'))];
        });
    }

    public function ajax_rest() {
        $this->authorize('manage');
        $this->run(function () {
            return ['pedido' => $this->service->request_rest(
                (int) $this->post_string('linea_id'),
                (int) $this->post_string('proveedor_id'),
                (int) $this->post_string('pp_id')
            )];
        });
    }

    /* ===================== Helpers ===================== */

    /**
     * @return array<string, mixed>
     */
    private function line_input() {
        $data = [];
        foreach (['producto_base_id', 'pp_id', 'cantidad', 'unidad', 'precio', 'descripcion'] as $key) {
            if (isset($_POST[$key])) {
                $data[$key] = $this->post_string($key);
            }
        }
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
     * @param string $level view|manage|decide|approve
     */
    private function authorize($level) {
        $allowed = [
            'view' => $this->can_view(),
            'manage' => $this->can_manage(),
            'decide' => $this->can_decide(),
            'approve' => $this->can_approve(),
        ][$level] ?? false;
        if (!$allowed || !wp_verify_nonce($this->post_string('nonce'), self::NONCE)) {
            $this->fail('No tienes permiso para esta acción.', 403);
        }
        if (!$this->service->ready()) {
            $this->fail('Pedidos de compra no disponible: falta la migración de base de datos.', 500);
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
