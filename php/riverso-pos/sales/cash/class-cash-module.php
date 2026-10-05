<?php
/**
 * Módulo Cobranza · Manejo de Caja + Cuentas bancarias y efectivo.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Cash_Module {

    private static $instance = null;

    /** @var Riverso_Cash_Repository */
    private $repo;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->ensure_schema();
        $this->ensure_capability();
        $repo_file = dirname(__FILE__) . '/class-cash-repository.php';
        if (file_exists($repo_file)) {
            require_once $repo_file;
        }
        $this->repo = new Riverso_Cash_Repository();
        $this->init_hooks();
    }

    public function init() {
        // Bootstrap del plugin.
    }

    public static function create_tables() {
        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_cajas_schema')) {
            Riverso_POS_Activator::ensure_cajas_schema();
        }
    }

    private function ensure_schema() {
        self::create_tables();
    }

    private function ensure_capability() {
        $caps = ['riverso_view_cash', 'riverso_manage_cash_accounts'];
        $admin = get_role('administrator');
        if ($admin) {
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }
        $radmin = get_role('riverso_admin');
        if ($radmin) {
            foreach ($caps as $cap) {
                if (!$radmin->has_cap($cap)) {
                    $radmin->add_cap($cap);
                }
            }
        }
        $ventas = get_role('riverso_ventas');
        if ($ventas && !$ventas->has_cap('riverso_view_cash')) {
            $ventas->add_cap('riverso_view_cash');
        }
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_cash_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_cash_accounts_list', [$this, 'ajax_accounts_list']);
        add_action('wp_ajax_riverso_cash_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_cash_update', [$this, 'ajax_update']);
        add_action('wp_ajax_riverso_cash_delete', [$this, 'ajax_delete']);
        add_action('wp_ajax_riverso_cash_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_cash_get_permisos', [$this, 'ajax_get_permisos']);
        add_action('wp_ajax_riverso_cash_set_permiso', [$this, 'ajax_set_permiso']);
        add_action('wp_ajax_riverso_cash_arqueo', [$this, 'ajax_arqueo']);
        add_action('wp_ajax_riverso_cash_approve_arqueo', [$this, 'ajax_approve_arqueo']);
        add_action('wp_ajax_riverso_cash_reject_arqueo', [$this, 'ajax_reject_arqueo']);
        add_action('wp_ajax_riverso_cash_movimientos', [$this, 'ajax_movimientos']);
        add_action('wp_ajax_riverso_cash_certificadores', [$this, 'ajax_certificadores']);
        add_action('wp_ajax_riverso_cash_pending', [$this, 'ajax_pending']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'riverso-pos-cash') === false) {
            return;
        }
        $this->enqueue_front_assets();
    }

    public function enqueue_front_assets() {
        $css = RIVERSO_POS_PLUGIN_DIR . 'assets/css/cash.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'riverso-cash',
                RIVERSO_POS_PLUGIN_URL . 'assets/css/cash.css',
                [],
                (string) filemtime($css)
            );
        }
    }

    /**
     * @return Riverso_Cash_Repository
     */
    public function repo() {
        return $this->repo;
    }

    /**
     * @param string $view manejo|accounts
     * @return array<string, mixed>
     */
    public function app_config($view = 'manejo') {
        $user_id = get_current_user_id();
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_cash'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'surface' => 'portal',
            'view' => $view,
            'portalUrl' => home_url('/interno/manejo-caja/'),
            'accountsUrl' => home_url('/interno/cuentas-caja/'),
            'editId' => isset($_GET['editar']) ? absint($_GET['editar']) : 0,
            'arqueoId' => isset($_GET['arqueo']) ? absint($_GET['arqueo']) : 0,
            'caps' => [
                'view' => $this->can_view(),
                'manage' => $this->can_manage(),
            ],
            'tipos' => Riverso_Cash_Repository::TIPOS,
            'currentUserId' => $user_id,
            'actions' => [
                'list' => 'riverso_cash_list',
                'accountsList' => 'riverso_cash_accounts_list',
                'create' => 'riverso_cash_create',
                'update' => 'riverso_cash_update',
                'delete' => 'riverso_cash_delete',
                'get' => 'riverso_cash_get',
                'getPermisos' => 'riverso_cash_get_permisos',
                'setPermiso' => 'riverso_cash_set_permiso',
                'arqueo' => 'riverso_cash_arqueo',
                'approveArqueo' => 'riverso_cash_approve_arqueo',
                'rejectArqueo' => 'riverso_cash_reject_arqueo',
                'movimientos' => 'riverso_cash_movimientos',
                'certificadores' => 'riverso_cash_certificadores',
                'pending' => 'riverso_cash_pending',
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
        if (!$this->can_view()) {
            echo '<p>No tienes permiso para ver Manejo de Caja.</p>';
            return;
        }
        $riverso_cash = $this->app_config('manejo');
        $riverso_cash['surface'] = $surface;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/cash/app.php';
    }

    /**
     * @param string|null $surface
     */
    public function render_accounts($surface = null) {
        if ($surface !== 'portal' && $surface !== 'admin') {
            $surface = (function_exists('is_admin') && is_admin()) ? 'admin' : 'portal';
        }
        if (!$this->can_manage()) {
            echo '<p>No tienes permiso para administrar cuentas bancarias y efectivo.</p>';
            return;
        }
        $riverso_cash = $this->app_config('accounts');
        $riverso_cash['surface'] = $surface;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/cash/accounts.php';
    }

    public function ajax_list() {
        $this->authorize_view();
        $user_id = get_current_user_id();
        $items = $this->repo->list_for_manejo($user_id);
        $pending = $this->repo->list_pending_for_certifier($user_id);
        wp_send_json_success(['items' => $items, 'pending' => $pending]);
    }

    public function ajax_accounts_list() {
        $this->authorize_manage();
        $items = $this->repo->list_all(true);
        wp_send_json_success(['items' => $items]);
    }

    public function ajax_create() {
        $this->authorize_manage();
        $result = $this->repo->create([
            'nombre' => isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '',
            'tipo' => isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : 'fisica',
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al crear.']);
        }
        $this->audit('caja.created', (int) $result['id'], 'Caja creada');
        $caja = $this->repo->get((int) $result['id']);
        wp_send_json_success(['caja' => $caja]);
    }

    public function ajax_update() {
        $this->authorize_manage();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $result = $this->repo->update($id, [
            'nombre' => isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : '',
            'tipo' => isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : 'fisica',
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al actualizar.']);
        }
        $this->audit('caja.updated', $id, 'Caja actualizada');
        wp_send_json_success(['caja' => $this->repo->get($id)]);
    }

    public function ajax_delete() {
        $this->authorize_manage();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $result = $this->repo->soft_delete($id);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al eliminar.']);
        }
        $this->audit('caja.deleted', $id, 'Caja eliminada (borrado lógico)');
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_get() {
        $this->authorize_manage_or_view();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $caja = $this->repo->get($id);
        if (!$caja) {
            wp_send_json_error(['message' => 'Caja no encontrada.']);
        }
        wp_send_json_success(['caja' => $caja]);
    }

    public function ajax_get_permisos() {
        $this->authorize_manage();
        $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        if (!$this->repo->get($caja_id)) {
            wp_send_json_error(['message' => 'Caja no encontrada.']);
        }
        $by_user = $this->repo->get_permisos($caja_id);
        $employees = [];
        if (class_exists('Riverso_POS_Permissions')) {
            $users = Riverso_POS_Permissions::get_all_employees();
            foreach ($users as $u) {
                $uid = (int) $u->ID;
                $perm = $by_user[$uid] ?? [
                    'user_id' => $uid,
                    'ver_saldo' => false,
                    'pagar' => false,
                    'borrar_pago' => false,
                    'transferir' => false,
                    'abrir_cerrar' => false,
                ];
                $employees[] = [
                    'user_id' => $uid,
                    'name' => $u->display_name,
                    'ver_saldo' => !empty($perm['ver_saldo']),
                    'pagar' => !empty($perm['pagar']),
                    'borrar_pago' => !empty($perm['borrar_pago']),
                    'transferir' => !empty($perm['transferir']),
                    'abrir_cerrar' => !empty($perm['abrir_cerrar']),
                ];
            }
        }
        usort($employees, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        wp_send_json_success(['permisos' => $employees]);
    }

    public function ajax_set_permiso() {
        $this->authorize_manage();
        $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $flags = [
            'ver_saldo' => !empty($_POST['ver_saldo']),
            'pagar' => !empty($_POST['pagar']),
            'borrar_pago' => !empty($_POST['borrar_pago']),
            'transferir' => !empty($_POST['transferir']),
            'abrir_cerrar' => !empty($_POST['abrir_cerrar']),
        ];
        $result = $this->repo->set_permiso($caja_id, $user_id, $flags);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al guardar permiso.']);
        }
        $this->audit('caja.permiso', $caja_id, 'Permiso actualizado user=' . $user_id);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_arqueo() {
        $this->authorize_view();
        $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        $user_id = get_current_user_id();
        if (!$this->repo->user_has_perm($caja_id, $user_id, 'abrir_cerrar')) {
            wp_send_json_error(['message' => 'No tienes permiso para abrir/cerrar esta caja.']);
        }
        $result = $this->repo->create_arqueo([
            'caja_id' => $caja_id,
            'tipo' => isset($_POST['tipo']) ? sanitize_text_field(wp_unslash($_POST['tipo'])) : '',
            'monto_efectivo' => isset($_POST['monto_efectivo']) ? (float) $_POST['monto_efectivo'] : 0,
            'docs_recibidos' => isset($_POST['docs_recibidos']) ? (float) $_POST['docs_recibidos'] : 0,
            'docs_emitidos' => isset($_POST['docs_emitidos']) ? (float) $_POST['docs_emitidos'] : 0,
            'certificador_id' => isset($_POST['certificador_id']) ? absint($_POST['certificador_id']) : $user_id,
            'fecha' => isset($_POST['fecha']) ? sanitize_text_field(wp_unslash($_POST['fecha'])) : '',
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al registrar arqueo.']);
        }
        $estado = $result['estado'] ?? 'aprobado';
        $this->audit(
            $estado === 'pendiente' ? 'caja.arqueo_pendiente' : 'caja.arqueo',
            $caja_id,
            'Arqueo #' . $result['id'] . ' (' . $estado . ')'
        );

        if ($estado === 'pendiente') {
            $this->create_certify_task((int) $result['id'], $caja_id);
        }

        wp_send_json_success([
            'id' => (int) $result['id'],
            'estado' => $estado,
            'caja' => $this->repo->get($caja_id),
        ]);
    }

    public function ajax_approve_arqueo() {
        $this->authorize_view();
        $arqueo_id = isset($_POST['arqueo_id']) ? absint($_POST['arqueo_id']) : 0;
        $result = $this->repo->approve_arqueo($arqueo_id, get_current_user_id());
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al aprobar.']);
        }
        $arqueo = $this->repo->get_arqueo($arqueo_id);
        $this->audit('caja.arqueo_aprobado', (int) ($arqueo['caja_id'] ?? 0), 'Arqueo #' . $arqueo_id);
        wp_send_json_success(['ok' => true, 'arqueo' => $arqueo]);
    }

    public function ajax_reject_arqueo() {
        $this->authorize_view();
        $arqueo_id = isset($_POST['arqueo_id']) ? absint($_POST['arqueo_id']) : 0;
        $result = $this->repo->reject_arqueo($arqueo_id, get_current_user_id());
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'Error al rechazar.']);
        }
        $arqueo = $this->repo->get_arqueo($arqueo_id);
        $this->audit('caja.arqueo_rechazado', (int) ($arqueo['caja_id'] ?? 0), 'Arqueo #' . $arqueo_id);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_movimientos() {
        $this->authorize_view();
        $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        $user_id = get_current_user_id();
        if (!$this->repo->user_has_any_perm($caja_id, $user_id)
            && !user_can($user_id, 'riverso_manage_cash_accounts')) {
            wp_send_json_error(['message' => 'Sin permiso para ver esta caja.']);
        }
        $can_ver = $this->repo->user_has_perm($caja_id, $user_id, 'ver_saldo');
        $movs = $can_ver ? $this->repo->list_movimientos($caja_id) : [];
        $caja = $this->repo->get($caja_id);
        wp_send_json_success([
            'movimientos' => $movs,
            'caja' => $caja,
            'can_ver_saldo' => $can_ver,
        ]);
    }

    public function ajax_certificadores() {
        $this->authorize_view();
        $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        wp_send_json_success(['certificadores' => $this->repo->list_certificadores($caja_id)]);
    }

    public function ajax_pending() {
        $this->authorize_view();
        wp_send_json_success([
            'pending' => $this->repo->list_pending_for_certifier(get_current_user_id()),
        ]);
    }

    /**
     * @param int $arqueo_id
     * @param int $caja_id
     */
    private function create_certify_task($arqueo_id, $caja_id) {
        $arqueo = $this->repo->get_arqueo($arqueo_id);
        $caja = $this->repo->get($caja_id);
        if (!$arqueo || !$caja) {
            return;
        }
        $cert_id = (int) ($arqueo['certificador_id'] ?? 0);
        if ($cert_id <= 0) {
            return;
        }
        $tipo_label = ($arqueo['tipo'] ?? '') === 'apertura' ? 'apertura' : 'cierre';
        $titulo = 'Certificar ' . $tipo_label . ' de caja: ' . $caja['nombre'];
        $link = home_url('/interno/manejo-caja/?arqueo=' . $arqueo_id);
        if (function_exists('riverso_create_task')) {
            riverso_create_task('certificar_arqueo', $titulo, [
                'asignado_a' => $cert_id,
                'prioridad' => 'alta',
                'descripcion' => 'Hay un arqueo pendiente de certificación. Monto declarado: $'
                    . number_format((float) $arqueo['monto_efectivo'], 0, ',', '.')
                    . '. Enlace: ' . $link,
                'datos_extra' => [
                    'arqueo_id' => $arqueo_id,
                    'caja_id' => $caja_id,
                    'link' => $link,
                ],
            ]);
        }
    }

    /**
     * @param string $action
     * @param int    $object_id
     * @param string $details
     */
    private function audit($action, $object_id, $details) {
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($action, 'caja', $object_id, [
                'details' => $details,
            ]);
        }
    }

    private function can_view() {
        return current_user_can('riverso_view_cash')
            || current_user_can('riverso_manage_cash_accounts')
            || current_user_can('manage_options');
    }

    private function can_manage() {
        return current_user_can('riverso_manage_cash_accounts')
            || current_user_can('manage_options');
    }

    private function authorize_view() {
        check_ajax_referer('riverso_cash', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function authorize_manage() {
        check_ajax_referer('riverso_cash', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function authorize_manage_or_view() {
        check_ajax_referer('riverso_cash', 'nonce');
        if (!$this->can_view() && !$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }
}
