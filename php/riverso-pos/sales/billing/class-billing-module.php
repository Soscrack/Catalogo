<?php
/**
 * Facturación · Emitir DTE (factura/boleta vía FACTO).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Billing_Module {

    private static $instance = null;

    /** @var Riverso_Dte_Issued_Repository */
    private $issued;

    /** @var Riverso_Receiver_Design_Repository */
    private $designs;

    /** @var Riverso_Billing_Draft_Repository */
    private $drafts;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->ensure_schema();
        $this->ensure_capability();
        $dir = dirname(__FILE__) . '/';
        foreach ([
            'class-billing-totals.php',
            'class-dte-issued-repository.php',
            'class-receiver-design-repository.php',
            'class-sii-stc-client.php',
            'class-billing-draft-repository.php',
        ] as $file) {
            $path = $dir . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        $this->issued = new Riverso_Dte_Issued_Repository();
        $this->designs = new Riverso_Receiver_Design_Repository();
        $this->drafts = new Riverso_Billing_Draft_Repository();
        $this->init_hooks();
    }

    public function init() {
        // Bootstrap del plugin.
    }

    public static function create_tables() {
        if (class_exists('Riverso_POS_Activator')) {
            if (method_exists('Riverso_POS_Activator', 'ensure_dte_issued_schema')) {
                Riverso_POS_Activator::ensure_dte_issued_schema();
            }
            if (method_exists('Riverso_POS_Activator', 'ensure_receiver_designs_schema')) {
                Riverso_POS_Activator::ensure_receiver_designs_schema();
            }
            if (method_exists('Riverso_POS_Activator', 'ensure_billing_drafts_schema')) {
                Riverso_POS_Activator::ensure_billing_drafts_schema();
            }
        }
    }

    private function ensure_schema() {
        self::create_tables();
    }

    /**
     * Otorga riverso_emit_dte a admin y roles con create_quotes (instalaciones ya existentes).
     */
    private function ensure_capability() {
        $cap = 'riverso_emit_dte';
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap($cap)) {
            $admin->add_cap($cap);
        }
        foreach (['riverso_admin', 'riverso_ventas', 'riverso_cotizador'] as $role_key) {
            $role = get_role($role_key);
            if ($role && !$role->has_cap($cap) && ($role->has_cap('riverso_create_quotes') || $role_key === 'riverso_admin')) {
                $role->add_cap($cap);
            }
            if ($role && $role_key === 'riverso_admin' && !$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_billing_bootstrap', [$this, 'ajax_bootstrap']);
        add_action('wp_ajax_riverso_billing_estimate_folio', [$this, 'ajax_estimate_folio']);
        add_action('wp_ajax_riverso_billing_search_customers', [$this, 'ajax_search_customers']);
        add_action('wp_ajax_riverso_billing_lookup_rut', [$this, 'ajax_lookup_rut']);
        add_action('wp_ajax_riverso_billing_lookup_sii', [$this, 'ajax_lookup_sii']);
        add_action('wp_ajax_riverso_billing_ensure_customer', [$this, 'ajax_ensure_customer']);
        add_action('wp_ajax_riverso_billing_load_quote', [$this, 'ajax_load_quote']);
        add_action('wp_ajax_riverso_billing_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_riverso_billing_emit', [$this, 'ajax_emit']);
        add_action('wp_ajax_riverso_billing_draft_save', [$this, 'ajax_draft_save']);
        add_action('wp_ajax_riverso_billing_draft_get', [$this, 'ajax_draft_get']);
        add_action('wp_ajax_riverso_billing_draft_payment', [$this, 'ajax_draft_payment']);
        add_action('wp_ajax_riverso_billing_product_lookup', [$this, 'ajax_product_lookup']);
        add_action('wp_ajax_riverso_billing_family_price', [$this, 'ajax_family_price']);
        add_action('wp_ajax_riverso_billing_line_stock', [$this, 'ajax_line_stock']);
        add_action('wp_ajax_riverso_billing_search_quotes', [$this, 'ajax_search_quotes']);
    }

    /**
     * @return array<string, mixed>
     */
    public function app_config() {
        $issuer = function_exists('riverso_facto_issuer_profile')
            ? riverso_facto_issuer_profile()
            : ['ok' => false, 'missing' => ['Config FACTO'], 'issuer' => []];
        $quote_id = isset($_GET['quote_id']) ? absint($_GET['quote_id']) : 0;
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $user_name = '';
        if ($user && !empty($user->ID)) {
            $user_name = trim((string) ($user->display_name ?: $user->user_login));
        }
        $issuer_data = isset($issuer['issuer']) && is_array($issuer['issuer']) ? $issuer['issuer'] : [];
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_billing'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'surface' => 'portal',
            'portalUrl' => home_url('/interno/facturacion/'),
            'quotesUrl' => home_url('/interno/customer-quotes/'),
            'todayDate' => current_time('Y-m-d'),
            'quoteId' => $quote_id,
            'currentUserName' => $user_name,
            'caps' => [
                'emit' => $this->can_emit(),
                'viewStock' => current_user_can('riverso_view_stock')
                    || current_user_can('riverso_view_warehouse')
                    || current_user_can('manage_options'),
            ],
            'factoConfigured' => function_exists('riverso_facto_is_configured') && riverso_facto_is_configured(),
            'issuer' => $issuer,
            'issuerProfile' => [
                'legal_name' => (string) ($issuer_data['legal_name'] ?? ''),
                'address' => (string) ($issuer_data['address'] ?? ''),
                'district' => (string) ($issuer_data['district'] ?? ''),
                'city' => (string) ($issuer_data['city'] ?? ''),
                'phone' => (string) ($issuer_data['phone'] ?? ''),
                'activity' => (string) ($issuer_data['activity'] ?? ''),
                'tax_id_code' => (string) ($issuer_data['tax_id_code'] ?? ''),
            ],
            'accountId' => (string) riverso_get_facto_config('account_id', ''),
            'taxTypeId' => (string) riverso_get_facto_config('tax_type_id', 387),
            'currencyId' => (int) riverso_get_facto_config('currency_id', 39),
            'documentTypes' => [
                ['id' => 2, 'label' => 'Factura electrónica', 'enabled' => true],
                ['id' => 37, 'label' => 'Boleta electrónica', 'enabled' => true],
                ['id' => 54, 'label' => 'Guía de despacho electrónica', 'enabled' => false, 'wip' => true],
                ['id' => 0, 'label' => 'Boleta electrónica de honorario a terceros', 'enabled' => false, 'wip' => true],
                ['id' => 41, 'label' => 'Boleta no afecta o exenta electrónica', 'enabled' => false, 'wip' => true],
                ['id' => 0, 'label' => 'Comprobante pago electrónico emitido', 'enabled' => false, 'wip' => true],
            ],
            'actions' => [
                'bootstrap' => 'riverso_billing_bootstrap',
                'estimateFolio' => 'riverso_billing_estimate_folio',
                'searchCustomers' => 'riverso_billing_search_customers',
                'lookupRut' => 'riverso_billing_lookup_rut',
                'lookupSii' => 'riverso_billing_lookup_sii',
                'ensureCustomer' => 'riverso_billing_ensure_customer',
                'loadQuote' => 'riverso_billing_load_quote',
                'preview' => 'riverso_billing_preview',
                'emit' => 'riverso_billing_emit',
                'draftSave' => 'riverso_billing_draft_save',
                'draftGet' => 'riverso_billing_draft_get',
                'draftPayment' => 'riverso_billing_draft_payment',
                'productLookup' => 'riverso_billing_product_lookup',
                'familyPrice' => 'riverso_billing_family_price',
                'lineStock' => 'riverso_billing_line_stock',
                'searchQuotes' => 'riverso_billing_search_quotes',
            ],
        ];
    }

    public function render_app($surface = null) {
        if ($surface !== 'portal' && $surface !== 'admin') {
            $surface = 'portal';
        }
        if (!$this->can_emit()) {
            echo '<p>No tienes permiso para emitir documentos tributarios.</p>';
            return;
        }
        $riverso_billing = $this->app_config();
        $riverso_billing['surface'] = $surface;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/billing/app.php';
    }

    public function ajax_bootstrap() {
        $this->authorize();
        $cfg = $this->app_config();
        unset($cfg['nonce']);
        wp_send_json_success($cfg);
    }

    public function ajax_estimate_folio() {
        $this->authorize();
        $type = isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 0;
        if (!in_array($type, [2, 32, 37, 41], true)) {
            wp_send_json_error(['message' => 'Tipo de documento no soportado.']);
        }
        if (!riverso_facto_is_configured()) {
            wp_send_json_error(['message' => 'FACTO no está configurado.']);
        }
        $client = $this->facto_client();
        $est = $client->estimate_next_folio($type);
        if (is_wp_error($est)) {
            wp_send_json_error(['message' => $est->get_error_message()]);
        }
        wp_send_json_success($est);
    }

    public function ajax_search_customers() {
        $this->authorize();
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'name';
        $search = trim($search);
        if ($search === '') {
            wp_send_json_success(['customers' => []]);
        }
        $repo = $this->customer_repo();
        if (!$repo) {
            wp_send_json_error(['message' => 'Módulo de clientes no disponible.']);
        }
        if ($mode === 'rut') {
            $found = $repo->find_by_rut($search);
            $out = $found ? [$this->present_customer($found)] : [];
            wp_send_json_success(['customers' => $out, 'mode' => 'rut']);
        }
        $result = $repo->list_customers(['search' => $search, 'status' => 'active'], 1, 20);
        $out = [];
        foreach ($result['items'] as $c) {
            $out[] = $this->present_customer($c);
        }
        wp_send_json_success(['customers' => $out, 'mode' => 'name']);
    }

    public function ajax_lookup_rut() {
        $this->authorize();
        $rut = isset($_POST['rut']) ? sanitize_text_field(wp_unslash($_POST['rut'])) : '';
        if (trim($rut) === '') {
            wp_send_json_error(['message' => 'Ingresa un RUT para buscar.']);
        }
        if (function_exists('riverso_validate_rut') && !riverso_validate_rut($rut)) {
            wp_send_json_error(['message' => 'RUT no válido.']);
        }
        $repo = $this->customer_repo();
        if (!$repo) {
            wp_send_json_error(['message' => 'Módulo de clientes no disponible.']);
        }
        $found = $repo->find_by_rut($rut);
        $display_rut = $found
            ? (string) ($found['rut'] ?? '')
            : $this->normalize_rut_display($rut);
        $designs = [];
        foreach ($this->designs->list_by_rut($display_rut !== '' ? $display_rut : $rut) as $d) {
            $designs[] = $this->designs->present($d);
        }
        if (!$found) {
            wp_send_json_success([
                'found' => false,
                'rut' => $display_rut,
                'customer' => null,
                'designs' => $designs,
            ]);
        }
        wp_send_json_success([
            'found' => true,
            'rut' => $display_rut,
            'customer' => $this->present_customer($found),
            'designs' => $designs,
        ]);
    }

    public function ajax_lookup_sii() {
        $this->authorize();
        $rut = isset($_POST['rut']) ? sanitize_text_field(wp_unslash($_POST['rut'])) : '';
        if (trim($rut) === '') {
            wp_send_json_error(['message' => 'Ingresa un RUT para buscar.']);
        }
        if (function_exists('riverso_validate_rut') && !riverso_validate_rut($rut)) {
            wp_send_json_error(['message' => 'RUT no válido.']);
        }
        $force = !empty($_POST['force']);
        $client = new Riverso_Sii_Stc_Client($this->designs);
        $result = $client->lookup($rut, $force);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $designs = [];
        foreach ($this->designs->list_by_rut($result['rut'] ?? $rut) as $d) {
            $designs[] = $this->designs->present($d);
        }
        // Marcar cuáles actividades ya tienen diseño.
        $by_code = [];
        foreach ($designs as $d) {
            $by_code[$d['activity_code']] = $d;
        }
        $acts = [];
        foreach ($result['actividades'] ?? [] as $a) {
            $code = (string) ($a['codigo'] ?? '');
            $a['has_design'] = isset($by_code[$code]);
            $a['design'] = $by_code[$code] ?? null;
            $acts[] = $a;
        }
        $result['actividades'] = $acts;
        $result['designs'] = $designs;
        wp_send_json_success($result);
    }

    public function ajax_ensure_customer() {
        $this->authorize();
        $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
        $doc_type = isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 0;
        $rut = isset($_POST['receiver_rut']) ? sanitize_text_field(wp_unslash($_POST['receiver_rut'])) : '';
        $razon = isset($_POST['receiver_legal_name']) ? sanitize_text_field(wp_unslash($_POST['receiver_legal_name'])) : '';
        $giro = isset($_POST['receiver_activity']) ? sanitize_text_field(wp_unslash($_POST['receiver_activity'])) : '';
        $activity_code = isset($_POST['receiver_activity_code']) ? sanitize_text_field(wp_unslash($_POST['receiver_activity_code'])) : '';
        $direccion = isset($_POST['receiver_address']) ? sanitize_text_field(wp_unslash($_POST['receiver_address'])) : '';
        $comuna = isset($_POST['receiver_district']) ? sanitize_text_field(wp_unslash($_POST['receiver_district'])) : '';
        $ciudad = isset($_POST['receiver_city']) ? sanitize_text_field(wp_unslash($_POST['receiver_city'])) : '';
        $telefono = isset($_POST['receiver_phone']) ? sanitize_text_field(wp_unslash($_POST['receiver_phone'])) : '';
        $postal = isset($_POST['receiver_postal']) ? sanitize_text_field(wp_unslash($_POST['receiver_postal'])) : '0';

        // Factura electrónica: todos los datos de facturación del receptor son obligatorios.
        if ($doc_type === 2) {
            $missing = [];
            if (trim($rut) === '') {
                $missing[] = 'RUT';
            }
            if (trim($razon) === '') {
                $missing[] = 'razón social';
            }
            if (trim($giro) === '') {
                $missing[] = 'giro / actividad';
            }
            if (trim($direccion) === '') {
                $missing[] = 'dirección';
            }
            if (trim($comuna) === '') {
                $missing[] = 'comuna';
            }
            if (trim($ciudad) === '') {
                $missing[] = 'ciudad';
            }
            if (trim($telefono) === '') {
                $missing[] = 'teléfono';
            }
            if ($missing) {
                wp_send_json_error([
                    'message' => 'Factura electrónica: faltan datos obligatorios del receptor (' . implode(', ', $missing) . ').',
                ]);
            }
            if (function_exists('riverso_validate_rut') && !riverso_validate_rut($rut)) {
                wp_send_json_error(['message' => 'RUT no válido.']);
            }
        }

        $repo = $this->customer_repo();
        if (!$repo) {
            wp_send_json_error(['message' => 'Módulo de clientes no disponible.']);
        }

        $this->maybe_save_receiver_design([
            'rut' => $rut,
            'activity_code' => $activity_code,
            'activity_glosa' => $giro,
            'razon_social' => $razon,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'telefono' => $telefono,
            'codigo_postal' => $postal,
        ]);

        if ($customer_id > 0) {
            $existing = $repo->get($customer_id);
            if ($existing) {
                wp_send_json_success([
                    'created' => false,
                    'customer' => $this->present_customer($existing),
                ]);
            }
        }

        $by_rut = $repo->find_by_rut($rut);
        if ($by_rut) {
            wp_send_json_success([
                'created' => false,
                'customer' => $this->present_customer($by_rut),
            ]);
        }

        if ($razon === '') {
            wp_send_json_error(['message' => 'Razón social obligatoria para crear el cliente.']);
        }
        if ($rut === '') {
            wp_send_json_error(['message' => 'RUT obligatorio para crear el cliente.']);
        }

        $result = $repo->save([
            'id' => 0,
            'nombre_fantasia' => $razon,
            'has_contacto' => 0,
            'has_facturacion' => 1,
            'has_datos_extra' => 0,
            'pais' => 'CHILE',
            'tipo_identificacion' => 'RUT_CLIENTE',
            'rut' => $rut,
            'razon_social' => $razon,
            'giro' => $giro,
            'direccion' => $direccion,
            'comuna' => $comuna,
            'ciudad' => $ciudad,
            'facturacion_telefono' => $telefono,
            'codigo_postal' => ($postal !== '' ? $postal : '0'),
            'activo' => 1,
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo crear el cliente.']);
        }

        $created = $repo->get((int) $result['id']);
        if (class_exists('Riverso_POS_Audit') && $created) {
            Riverso_POS_Audit::log('customer_created', 'customer', (int) $result['id'], [
                'entity_name' => $razon,
                'source' => 'billing_emit',
            ]);
        }

        wp_send_json_success([
            'created' => true,
            'customer' => $this->present_customer($created ?: ['id' => (int) $result['id'], 'rut' => $rut, 'razon_social' => $razon]),
        ]);
    }

    public function ajax_load_quote() {
        $this->authorize();
        $id = isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0;
        $payload = $this->quote_to_billing_payload($id);
        if (is_wp_error($payload)) {
            wp_send_json_error(['message' => $payload->get_error_message()]);
        }
        wp_send_json_success($payload);
    }

    public function ajax_preview() {
        $this->authorize();
        $built = $this->build_facto_payload_from_request(true);
        if (is_wp_error($built)) {
            wp_send_json_error(['message' => $built->get_error_message()]);
        }
        $client = $this->facto_client();
        $resp = $client->create_document($built['payload']);
        if (is_wp_error($resp)) {
            wp_send_json_error([
                'message' => $resp->get_error_message(),
                'detail' => $resp->get_error_data(),
            ]);
        }
        $preview = '';
        if (!empty($resp['electronic_document']['document_pdf'])) {
            $preview = (string) $resp['electronic_document']['document_pdf'];
        } elseif (!empty($resp['result']['document_pdf'])) {
            $preview = (string) $resp['result']['document_pdf'];
        }
        wp_send_json_success([
            'totals' => $built['totals'],
            'document_type_id' => $built['document_type_id'],
            'preview_pdf_base64' => $preview,
            'raw_status' => isset($resp['result']['status']) ? $resp['result']['status'] : null,
            'error_message' => isset($resp['result']['error_message']) ? $resp['result']['error_message'] : '',
        ]);
    }

    public function ajax_emit() {
        $this->authorize();
        $quote_id = isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0;

        if ($quote_id > 0) {
            $existing = $this->issued->find_success_by_quote($quote_id);
            if ($existing) {
                wp_send_json_success([
                    'idempotent' => true,
                    'message' => 'Ya existe un DTE para esta cotización (folio ' . $existing['folio'] . ').',
                    'dte' => $existing,
                ]);
            }
            $lock_key = 'riverso_billing_emit_lock_' . $quote_id;
            if (get_transient($lock_key)) {
                wp_send_json_error(['message' => 'Emisión en curso. Espere un momento.']);
            }
            set_transient($lock_key, 1, 60);
        } else {
            $lock_key = '';
        }

        try {
            $built = $this->build_facto_payload_from_request(false);
            if (is_wp_error($built)) {
                wp_send_json_error(['message' => $built->get_error_message()]);
            }

            $client = $this->facto_client();
            $resp = $client->create_document($built['payload']);
            if (is_wp_error($resp)) {
                wp_send_json_error([
                    'message' => $resp->get_error_message(),
                    'detail' => $resp->get_error_data(),
                ]);
            }

            $status = isset($resp['result']['status']) ? (int) $resp['result']['status'] : null;
            $error_msg = isset($resp['result']['error_message']) ? (string) $resp['result']['error_message'] : '';
            $doc_id = 0;
            if (!empty($resp['document_id'])) {
                $doc_id = (int) $resp['document_id'];
            } elseif (!empty($resp['header']['document_id'])) {
                $doc_id = (int) $resp['header']['document_id'];
            }
            $folio = '';
            if (isset($resp['header']['document_number'])) {
                $folio = (string) $resp['header']['document_number'];
            } elseif (isset($resp['document_number'])) {
                $folio = (string) $resp['document_number'];
            }

            if ($status === 1 || ($doc_id <= 0 && $status !== 0 && $status !== 2)) {
                wp_send_json_error([
                    'message' => $error_msg !== '' ? $error_msg : 'FACTO rechazó el documento.',
                    'facto_status' => $status,
                    'response' => $resp,
                ]);
            }

            $receiver = $built['receiver'];
            $insert_id = $this->issued->insert([
                'quote_id' => $quote_id > 0 ? $quote_id : null,
                'customer_id' => $built['customer_id'],
                'document_type_id' => $built['document_type_id'],
                'document_type_label' => Riverso_Billing_Totals::type_label($built['document_type_id']),
                'facto_document_id' => $doc_id > 0 ? $doc_id : null,
                'folio' => $folio !== '' ? $folio : null,
                'issue_date' => $built['issue_date'],
                'payment_conditions' => $built['payment_conditions'],
                'receiver_rut' => $receiver['tax_id_code'] ?? null,
                'receiver_legal_name' => $receiver['legal_name'] ?? null,
                'net_amount' => $built['totals']['net_amount'],
                'taxes_amount' => $built['totals']['taxes_amount'],
                'total_amount' => $built['totals']['total_amount'],
                'facto_status' => $status,
                'facto_error' => $error_msg !== '' ? $error_msg : null,
                'response_json' => wp_json_encode($resp),
            ]);

            if (is_wp_error($insert_id)) {
                wp_send_json_error(['message' => $insert_id->get_error_message()]);
            }

            if (class_exists('Riverso_Audit_Module')) {
                Riverso_Audit_Module::get_instance()->log(
                    'billing.dte_emitted',
                    'dte_issued',
                    (int) $insert_id,
                    [],
                    [
                        'document_type_id' => $built['document_type_id'],
                        'folio' => $folio,
                        'facto_document_id' => $doc_id,
                        'quote_id' => $quote_id,
                    ],
                    'Emitir DTE folio ' . $folio
                );
            }

            $this->maybe_save_receiver_design([
                'rut' => isset($_POST['receiver_rut']) ? sanitize_text_field(wp_unslash($_POST['receiver_rut'])) : ($receiver['tax_id_code'] ?? ''),
                'activity_code' => isset($_POST['receiver_activity_code'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_activity_code']))
                    : '',
                'activity_glosa' => isset($_POST['receiver_activity'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_activity']))
                    : '',
                'razon_social' => isset($_POST['receiver_legal_name'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_legal_name']))
                    : ($receiver['legal_name'] ?? ''),
                'direccion' => isset($_POST['receiver_address'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_address']))
                    : '',
                'comuna' => isset($_POST['receiver_district'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_district']))
                    : '',
                'ciudad' => isset($_POST['receiver_city'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_city']))
                    : '',
                'telefono' => isset($_POST['receiver_phone'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_phone']))
                    : '',
                'codigo_postal' => isset($_POST['receiver_postal'])
                    ? sanitize_text_field(wp_unslash($_POST['receiver_postal']))
                    : '0',
            ]);

            $msg = 'Documento emitido.';
            if ($status === 2) {
                $msg = 'Documento creado pero no enviado al SII' . ($error_msg !== '' ? ': ' . $error_msg : '.');
            } elseif ($status === 0) {
                $msg = 'Documento emitido y enviado al SII.';
            }

            wp_send_json_success([
                'idempotent' => false,
                'message' => $msg,
                'dte' => [
                    'id' => (int) $insert_id,
                    'facto_document_id' => $doc_id,
                    'folio' => $folio,
                    'document_type_id' => $built['document_type_id'],
                    'document_type_label' => Riverso_Billing_Totals::type_label($built['document_type_id']),
                    'facto_status' => $status,
                    'totals' => $built['totals'],
                ],
            ]);
        } finally {
            if ($lock_key !== '') {
                delete_transient($lock_key);
            }
        }
    }

    /**
     * @param int $quote_id
     * @return array|WP_Error
     */
    /**
     * @return Riverso_Quote_Catalog_Lookup|null
     */
    private function quote_catalog() {
        $lookup = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-catalog-lookup.php';
        $totals = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-totals.php';
        if (!class_exists('Riverso_Quote_Catalog_Lookup') && file_exists($lookup)) {
            require_once $lookup;
        }
        if (!class_exists('Riverso_Quote_Totals') && file_exists($totals)) {
            require_once $totals;
        }
        if (!class_exists('Riverso_Quote_Catalog_Lookup')) {
            return null;
        }
        return new Riverso_Quote_Catalog_Lookup();
    }

    /**
     * Hidrata _family y flags de regla en líneas de borrador.
     *
     * @param array<string,mixed>|null $draft
     * @return array<string,mixed>|null
     */
    private function present_draft($draft) {
        if (!$draft || !is_array($draft)) {
            return null;
        }
        $catalog = $this->quote_catalog();
        if (!$catalog || empty($draft['lines']) || !is_array($draft['lines'])) {
            return $draft;
        }
        $hydrated = $catalog->hydrate_quote_families(['lines' => $draft['lines']]);
        $draft['lines'] = [];
        foreach (($hydrated['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!empty($line['rule_adjusted']) && isset($line['rule_total'])) {
                $line['_rule_adjusted'] = true;
                $line['_rule_total'] = (float) $line['rule_total'];
            }
            if (!isset($line['unit_price']) && isset($line['unit_price_bruto'])) {
                $line['unit_price'] = (float) $line['unit_price_bruto'];
            }
            $draft['lines'][] = $line;
        }
        return $draft;
    }

    /**
     * Normaliza líneas con Riverso_Quote_Totals y aplana a unit_price_bruto / line_total_bruto.
     * Totales DTE con Riverso_Billing_Totals.
     *
     * @param array $raw_lines
     * @return array{lines:array,net_amount:float,exempt_amount:float,tax_amount:float,total_amount:float}
     */
    private function prepare_draft_lines_for_save(array $raw_lines) {
        $this->quote_catalog(); // asegura class-quote-totals
        $normalized_input = [];
        foreach ($raw_lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0 && trim((string) ($line['description'] ?? '')) === '' && trim((string) ($line['sku'] ?? '')) === '') {
                continue;
            }
            $unit = (float) ($line['unit_price'] ?? $line['unit_price_bruto'] ?? 0);
            $gid = 0;
            if (!empty($line['grupo_id'])) {
                $gid = (int) $line['grupo_id'];
            } elseif (!empty($line['_family']['grupo_id'])) {
                $gid = (int) $line['_family']['grupo_id'];
            }
            $rule_adjusted = !empty($line['rule_adjusted']) || !empty($line['_rule_adjusted']);
            $rule_total = null;
            if (isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
                $rule_total = (float) $line['rule_total'];
            } elseif (isset($line['_rule_total']) && $line['_rule_total'] !== null && $line['_rule_total'] !== '') {
                $rule_total = (float) $line['_rule_total'];
            }
            $row = [
                'sku' => (string) ($line['sku'] ?? ''),
                'description' => (string) ($line['description'] ?? ''),
                'quantity' => $qty > 0 ? $qty : 1,
                'unit_price' => $unit,
                'unit_price_bruto' => $unit,
                'unit_cost' => array_key_exists('unit_cost', $line) ? $line['unit_cost'] : null,
                'price_discount' => (float) ($line['price_discount'] ?? 0),
                'margin_discount' => (float) ($line['margin_discount'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'product_id' => !empty($line['product_id']) ? (int) $line['product_id'] : null,
                'producto_base_id' => !empty($line['producto_base_id']) ? (int) $line['producto_base_id'] : null,
                'units_per_pack' => (float) ($line['units_per_pack'] ?? 1),
                'family_mode' => (string) ($line['family_mode'] ?? ''),
                'packaging' => (string) ($line['packaging'] ?? ''),
                'grupo_id' => $gid > 0 ? $gid : null,
                'price_mode' => (string) ($line['price_mode'] ?? 'auto'),
                'price_ref' => isset($line['price_ref']) ? $line['price_ref'] : null,
                'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
                'rule_total' => $rule_total,
                'rule_adjusted' => $rule_adjusted,
                'afecto' => !array_key_exists('afecto', $line) || !empty($line['afecto']),
                '_family' => isset($line['_family']) && is_array($line['_family']) ? $line['_family'] : null,
            ];
            $normalized_input[] = $row;
        }

        $calc_lines = $normalized_input;
        if (class_exists('Riverso_Quote_Totals')) {
            $calc = Riverso_Quote_Totals::calculate($normalized_input);
            $calc_lines = $calc['lines'] ?? $normalized_input;
        }

        $emit_lines = [];
        $persist = [];
        foreach ($calc_lines as $idx => $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $line_net = isset($line['line_net'])
                ? (float) $line['line_net']
                : round($qty * (float) ($line['unit_price'] ?? 0), 2);
            $unit_bruto = round($line_net / $qty, 6);
            $src = $normalized_input[$idx] ?? $line;
            $persist_line = [
                'sku' => (string) ($src['sku'] ?? ''),
                'description' => (string) ($src['description'] ?? ''),
                'quantity' => $qty,
                'unit_price' => (float) ($line['unit_price'] ?? $unit_bruto),
                'unit_price_bruto' => $unit_bruto,
                'line_total_bruto' => round($line_net, 2),
                'afecto' => !empty($src['afecto']),
                'product_id' => $src['product_id'] ?? null,
                'producto_base_id' => $src['producto_base_id'] ?? null,
                'units_per_pack' => (float) ($src['units_per_pack'] ?? 1),
                'family_mode' => (string) ($src['family_mode'] ?? ''),
                'packaging' => (string) ($src['packaging'] ?? ''),
                'grupo_id' => $src['grupo_id'] ?? null,
                'unit_cost' => array_key_exists('unit_cost', $line) ? $line['unit_cost'] : ($src['unit_cost'] ?? null),
                'price_mode' => (string) ($src['price_mode'] ?? 'auto'),
                'price_ref' => $src['price_ref'] ?? null,
                'price_total' => $src['price_total'] ?? null,
                'price_discount' => (float) ($line['price_discount'] ?? 0),
                'margin_discount' => (float) ($line['margin_discount'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'rule_total' => isset($line['rule_total']) ? $line['rule_total'] : ($src['rule_total'] ?? null),
                'rule_adjusted' => !empty($line['rule_adjusted']) || !empty($src['rule_adjusted']),
            ];
            $persist[] = $persist_line;
            $emit_lines[] = [
                'sku' => $persist_line['sku'],
                'description' => $persist_line['description'],
                'quantity' => $qty,
                'unit_price_bruto' => $unit_bruto,
                'afecto' => $persist_line['afecto'],
            ];
        }

        $net_amount = 0.0;
        $exempt_amount = 0.0;
        $tax_amount = 0.0;
        $total_amount = 0.0;
        if (class_exists('Riverso_Billing_Totals')) {
            $built = Riverso_Billing_Totals::build($emit_lines, Riverso_Billing_Totals::TYPE_BOLETA);
            $totals = $built['totals'] ?? [];
            $total_amount = round((float) ($totals['total_amount'] ?? 0), 2);
            $tax_amount = round((float) ($totals['taxes_amount'] ?? 0), 2);
            $net_amount = round((float) ($totals['net_amount'] ?? 0), 2);
            foreach ($built['lines_ui'] ?? [] as $lu) {
                if (empty($lu['afecto'])) {
                    $exempt_amount += (float) ($lu['line_bruto'] ?? 0);
                }
            }
            $exempt_amount = round($exempt_amount, 2);
            // Neto afecto = neto total − exento.
            $net_amount = round($net_amount - $exempt_amount, 2);
            if ($net_amount < 0) {
                $net_amount = 0.0;
            }
        }

        return [
            'lines' => $persist,
            'net_amount' => $net_amount,
            'exempt_amount' => $exempt_amount,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount,
        ];
    }

    private function quote_to_billing_payload($quote_id) {
        $quote_id = absint($quote_id);
        if ($quote_id <= 0) {
            return new WP_Error('bad_quote', 'Cotización no indicada.');
        }
        if (!class_exists('Riverso_Customer_Quote_Module')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-module.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Customer_Quote_Repository')) {
            return new WP_Error('no_quotes', 'Módulo de cotizaciones no disponible.');
        }
        $repo = new Riverso_Customer_Quote_Repository();
        $quote = $repo->find($quote_id);
        if ($quote === null) {
            return new WP_Error('not_found', 'Cotización no encontrada.');
        }
        $status = isset($quote['status']) ? Riverso_Quote_Status::normalize_legacy($quote['status']) : 'draft';
        $type = isset($quote['quote_type']) ? (string) $quote['quote_type'] : 'venta';
        if ($status !== Riverso_Quote_Status::LISTED && $status !== Riverso_Quote_Status::INVOICED) {
            return new WP_Error('bad_status', 'Solo se puede emitir desde una cotización en estado Lista.');
        }
        if ($type !== Riverso_Quote_Type::VENTA) {
            return new WP_Error('bad_type', 'Solo cotizaciones de tipo Venta.');
        }

        $existing = $this->issued->find_success_by_quote($quote_id);

        $iva_map = $this->load_iva_map($quote['lines'] ?? []);
        $lines = [];
        foreach (($quote['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $billable = $qty;
            if (class_exists('Riverso_Quote_Totals') && method_exists('Riverso_Quote_Totals', 'billable_units')) {
                // Preferir unidades facturables si existe helper público — fallback qty.
            }
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            $afecto = true;
            if ($pb > 0 && isset($iva_map[$pb]) && $iva_map[$pb] === 'exento') {
                $afecto = false;
            }
            // Precio bruto comercial: price_total / qty, o unit_price si ya es bruto de cotización.
            $bruto_unit = 0.0;
            if (!empty($line['price_total']) && $qty > 0) {
                $bruto_unit = round((float) $line['price_total'] / $qty, 2);
            } elseif (isset($line['line_net']) && $qty > 0) {
                // En cotizaciones net_total/line_net son brutos comerciales (ancla del PDF).
                $bruto_unit = round((float) $line['line_net'] / $qty, 2);
            } else {
                $bruto_unit = round((float) ($line['unit_price'] ?? 0), 2);
            }
            $sku = (string) ($line['sku'] ?? $line['supplier_code'] ?? '');
            $desc = (string) ($line['description'] ?? $line['name'] ?? '');
            $lines[] = [
                'sku' => $sku,
                'description' => $desc,
                'quantity' => $qty,
                'unit_price_bruto' => $bruto_unit,
                'unit_price' => $bruto_unit,
                'afecto' => $afecto,
                'product_id' => !empty($line['product_id']) ? (int) $line['product_id'] : null,
                'producto_base_id' => $pb > 0 ? $pb : null,
                'units_per_pack' => (float) ($line['units_per_pack'] ?? 1),
                'family_mode' => (string) ($line['family_mode'] ?? ''),
                'packaging' => (string) ($line['packaging'] ?? ''),
                'unit_cost' => array_key_exists('unit_cost', $line) ? $line['unit_cost'] : null,
                'price_mode' => (string) ($line['price_mode'] ?? 'auto'),
                'price_ref' => isset($line['price_ref']) ? $line['price_ref'] : null,
                'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
                'price_discount' => (float) ($line['price_discount'] ?? 0),
                'margin_discount' => (float) ($line['margin_discount'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
            ];
        }

        $receiver = null;
        $customer_id = isset($quote['customer_id']) ? (int) $quote['customer_id'] : 0;
        if ($customer_id > 0) {
            if (!class_exists('Riverso_Customer_Repository')) {
                $cpath = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-repository.php';
                if (file_exists($cpath)) {
                    require_once $cpath;
                }
            }
            if (class_exists('Riverso_Customer_Repository')) {
                $crepo = new Riverso_Customer_Repository();
                $cust = $crepo->get($customer_id);
                if ($cust && !empty($cust['has_facturacion'])) {
                    $receiver = [
                        'id' => $customer_id,
                        'rut' => (string) ($cust['rut'] ?? ''),
                        'razon_social' => (string) ($cust['razon_social'] ?? ''),
                        'giro' => (string) ($cust['giro'] ?? ''),
                        'direccion' => (string) ($cust['direccion'] ?? ''),
                        'comuna' => (string) ($cust['comuna'] ?? ''),
                        'ciudad' => (string) ($cust['ciudad'] ?? ''),
                        'telefono' => (string) ($cust['facturacion_telefono'] ?? ''),
                        'codigo_postal' => (string) ($cust['codigo_postal'] ?? '0'),
                    ];
                }
            }
        }

        return [
            'quote' => [
                'id' => (int) $quote['id'],
                'quote_number' => (string) ($quote['quote_number'] ?? ''),
                'customer_name' => (string) ($quote['customer_name'] ?? ''),
                'issue_date' => (string) ($quote['issue_date'] ?? current_time('Y-m-d')),
                'status' => $status,
                'net_total' => (float) ($quote['net_total'] ?? 0),
            ],
            'receiver' => $receiver,
            'lines' => $lines,
            'already_emitted' => $existing,
        ];
    }

    /**
     * @param array $lines
     * @return array<int,string>
     */
    private function load_iva_map(array $lines) {
        global $wpdb;
        $ids = [];
        foreach ($lines as $line) {
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            if ($pb > 0) {
                $ids[$pb] = $pb;
            }
        }
        if (!$ids) {
            return [];
        }
        $prefix = $wpdb->prefix . 'riverso_';
        $col = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'facto_iva_tipo'",
                $prefix . 'producto_base'
            )
        );
        if (!$col) {
            return [];
        }
        $id_list = implode(',', array_map('absint', $ids));
        $rows = $wpdb->get_results(
            "SELECT id, facto_iva_tipo FROM {$prefix}producto_base WHERE id IN ({$id_list})",
            ARRAY_A
        );
        $map = [];
        foreach ((array) $rows as $row) {
            $tipo = isset($row['facto_iva_tipo']) ? (string) $row['facto_iva_tipo'] : 'afecto';
            if (class_exists('Riverso_Pricing_Module') && method_exists('Riverso_Pricing_Module', 'normalize_iva_tipo')) {
                $tipo = Riverso_Pricing_Module::normalize_iva_tipo($tipo);
            }
            $map[(int) $row['id']] = $tipo === 'exento' ? 'exento' : 'afecto';
        }
        return $map;
    }

    /**
     * @param bool $draft_preview
     * @return array|WP_Error
     */
    private function build_facto_payload_from_request($draft_preview) {
        if (!riverso_facto_is_configured()) {
            return new WP_Error('facto_cfg', 'FACTO no está configurado.');
        }
        $profile = riverso_facto_issuer_profile();
        if (empty($profile['ok'])) {
            return new WP_Error(
                'issuer_missing',
                'Faltan datos del emisor: ' . implode(', ', $profile['missing'])
            );
        }
        $account_id = absint(riverso_get_facto_config('account_id', 0));
        if ($account_id <= 0) {
            return new WP_Error('account_missing', 'Configura el Account ID de FACTO en Ajustes.');
        }

        $preferred = isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 2;
        if (!in_array($preferred, [2, 37], true)) {
            return new WP_Error('bad_type', 'Solo se puede emitir Factura (2) o Boleta (37) en este corte.');
        }

        $issue_date = isset($_POST['issue_date']) ? sanitize_text_field(wp_unslash($_POST['issue_date'])) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
            $issue_date = current_time('Y-m-d');
        }
        $payment = isset($_POST['payment_conditions']) ? sanitize_text_field(wp_unslash($_POST['payment_conditions'])) : '0';
        if ($payment === '') {
            $payment = '0';
        }

        $lines_raw = isset($_POST['lines']) ? wp_unslash($_POST['lines']) : '[]';
        if (is_string($lines_raw)) {
            $lines = json_decode($lines_raw, true);
        } else {
            $lines = $lines_raw;
        }
        if (!is_array($lines) || !$lines) {
            return new WP_Error('no_lines', 'Agrega al menos una línea.');
        }

        $built = Riverso_Billing_Totals::build($lines, $preferred);
        if (empty($built['details'])) {
            return new WP_Error('no_lines', 'No hay líneas válidas.');
        }

        $issuer = $profile['issuer'];
        $header = [
            'account_id' => $account_id,
            'document_type_id' => (string) $built['document_type_id'],
            'received_issued_flag' => 1,
            'issue_date' => $issue_date,
            'issuer_tax_id_code' => $issuer['tax_id_code'],
            'issuer_tax_id_type' => $issuer['tax_id_type'],
            'issuer_legal_name' => $issuer['legal_name'],
            'issuer_address' => $issuer['address'],
            'issuer_district' => $issuer['district'],
            'issuer_city' => $issuer['city'],
            'issuer_country_id' => $issuer['country_id'],
            'issuer_phone' => $issuer['phone'],
            'issuer_activity' => $issuer['activity'],
            'payment_conditions' => $payment,
            'currency_id' => (int) riverso_get_facto_config('currency_id', 39),
        ];

        $receiver = [
            'tax_id_code' => '',
            'legal_name' => '',
        ];
        $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
        $needs_receiver = Riverso_Billing_Totals::requires_receiver($built['document_type_id']);

        $recv_rut = isset($_POST['receiver_rut']) ? sanitize_text_field(wp_unslash($_POST['receiver_rut'])) : '';
        $recv_rut = preg_replace('/[^0-9kK\-]/', '', $recv_rut);
        // Normalizar a NNNNNNNN-X
        if ($recv_rut !== '' && strpos($recv_rut, '-') === false && strlen($recv_rut) >= 2) {
            $recv_rut = substr($recv_rut, 0, -1) . '-' . strtoupper(substr($recv_rut, -1));
        }
        $recv_name = isset($_POST['receiver_legal_name']) ? sanitize_text_field(wp_unslash($_POST['receiver_legal_name'])) : '';
        $recv_addr = isset($_POST['receiver_address']) ? sanitize_text_field(wp_unslash($_POST['receiver_address'])) : '';
        $recv_dist = isset($_POST['receiver_district']) ? sanitize_text_field(wp_unslash($_POST['receiver_district'])) : '';
        $recv_city = isset($_POST['receiver_city']) ? sanitize_text_field(wp_unslash($_POST['receiver_city'])) : '';
        $recv_phone = isset($_POST['receiver_phone']) ? sanitize_text_field(wp_unslash($_POST['receiver_phone'])) : '';
        $recv_act = isset($_POST['receiver_activity']) ? sanitize_text_field(wp_unslash($_POST['receiver_activity'])) : '';

        if ($needs_receiver) {
            if ($recv_rut === '' || $recv_name === '' || $recv_addr === '' || $recv_dist === '' || $recv_city === '' || $recv_phone === '' || $recv_act === '') {
                return new WP_Error('receiver_required', 'Completa todos los datos del receptor (factura).');
            }
            if (function_exists('riverso_validate_rut') && !riverso_validate_rut($recv_rut)) {
                return new WP_Error('bad_rut', 'RUT no válido.');
            }
            $header['receiver_tax_id_code'] = $recv_rut;
            $header['receiver_tax_id_type'] = 'CL-RUT';
            $header['receiver_legal_name'] = $recv_name;
            $header['receiver_address'] = $recv_addr;
            $header['receiver_district'] = $recv_dist;
            $header['receiver_city'] = $recv_city;
            $header['receiver_country_id'] = '253';
            $header['receiver_phone'] = $recv_phone;
            $header['receiver_activity'] = $recv_act;
            $receiver = [
                'tax_id_code' => $recv_rut,
                'legal_name' => $recv_name,
            ];
        }
        // Boleta electrónica: no usar datos de receptor (anónima).

        $options = [];
        if ($draft_preview) {
            $options['draft_preview'] = 1;
        }
        // Boleta: valores brutos alineados con cotización.
        if (in_array((int) $built['document_type_id'], [37, 41], true)) {
            $options['gross_values'] = 1;
            $options['rounding_type'] = 'gross';
        } else {
            $options['rounding_type'] = 'net';
        }

        $payload = [
            'options' => $options,
            'header' => $header,
            'details' => $built['details'],
            'totals' => $built['totals'],
        ];

        $obs = isset($_POST['observations']) ? sanitize_textarea_field(wp_unslash($_POST['observations'])) : '';
        if ($obs !== '') {
            $payload['header']['observations'] = $obs;
        }

        return [
            'payload' => $payload,
            'document_type_id' => $built['document_type_id'],
            'totals' => $built['totals'],
            'lines_ui' => $built['lines_ui'],
            'issue_date' => $issue_date,
            'payment_conditions' => $payment,
            'customer_id' => $customer_id > 0 ? $customer_id : null,
            'receiver' => $receiver,
        ];
    }

    /**
     * Guarda diseño de autollenado si hay giro + dirección.
     *
     * @param array<string, mixed> $data
     */
    private function maybe_save_receiver_design(array $data) {
        $giro = trim((string) ($data['activity_glosa'] ?? ''));
        $dir = trim((string) ($data['direccion'] ?? ''));
        $rut = trim((string) ($data['rut'] ?? ''));
        if ($rut === '' || $giro === '' || $dir === '') {
            return;
        }
        $this->designs->upsert($data);
    }

    /**
     * @return Riverso_Facto_Client
     */
    private function facto_client() {
        if (!class_exists('Riverso_Facto_Client')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/integrations/facto/class-facto-client.php';
        }
        return new Riverso_Facto_Client();
    }

    /**
     * @return Riverso_Customer_Repository|null
     */
    private function customer_repo() {
        if (!class_exists('Riverso_Customer_Repository')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-repository.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        return class_exists('Riverso_Customer_Repository') ? new Riverso_Customer_Repository() : null;
    }

    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function present_customer(array $c) {
        return [
            'id' => (int) ($c['id'] ?? 0),
            'nombre_fantasia' => (string) ($c['nombre_fantasia'] ?? ''),
            'rut' => (string) ($c['rut'] ?? ''),
            'razon_social' => (string) ($c['razon_social'] ?? ''),
            'giro' => (string) ($c['giro'] ?? ''),
            'direccion' => (string) ($c['direccion'] ?? ''),
            'comuna' => (string) ($c['comuna'] ?? ''),
            'ciudad' => (string) ($c['ciudad'] ?? ''),
            'facturacion_telefono' => (string) ($c['facturacion_telefono'] ?? ''),
            'codigo_postal' => (string) ($c['codigo_postal'] ?? '0'),
            'has_facturacion' => !empty($c['has_facturacion']),
        ];
    }

    /**
     * @param string $rut
     * @return string
     */
    private function normalize_rut_display($rut) {
        $rut = preg_replace('/[^0-9kK]/', '', (string) $rut);
        if (!is_string($rut) || strlen($rut) < 2) {
            return '';
        }
        return substr($rut, 0, -1) . '-' . strtoupper(substr($rut, -1));
    }

    public function ajax_draft_save() {
        $this->authorize();
        $payload = [
            'id' => isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0,
            'status' => isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'draft',
            'issue_date' => isset($_POST['issue_date']) ? sanitize_text_field(wp_unslash($_POST['issue_date'])) : '',
            'due_date' => isset($_POST['due_date']) ? sanitize_text_field(wp_unslash($_POST['due_date'])) : '',
            'payment_conditions' => isset($_POST['payment_conditions']) ? sanitize_text_field(wp_unslash($_POST['payment_conditions'])) : '0',
            'sale_state' => isset($_POST['sale_state']) ? sanitize_text_field(wp_unslash($_POST['sale_state'])) : 'VENTA: Concretada',
            'quote_id' => isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0,
            'net_amount' => 0,
            'exempt_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
            'refs' => [],
        ];
        $lines_raw = isset($_POST['lines']) ? wp_unslash($_POST['lines']) : '[]';
        $refs_raw = isset($_POST['refs']) ? wp_unslash($_POST['refs']) : '[]';
        $raw_lines = is_string($lines_raw) ? (json_decode($lines_raw, true) ?: []) : (is_array($lines_raw) ? $lines_raw : []);
        $payload['refs'] = is_string($refs_raw) ? (json_decode($refs_raw, true) ?: []) : (is_array($refs_raw) ? $refs_raw : []);

        $prepared = $this->prepare_draft_lines_for_save($raw_lines);
        $payload['lines'] = $prepared['lines'];
        $payload['net_amount'] = $prepared['net_amount'];
        $payload['exempt_amount'] = $prepared['exempt_amount'];
        $payload['tax_amount'] = $prepared['tax_amount'];
        $payload['total_amount'] = $prepared['total_amount'];

        $result = $this->drafts->save($payload);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo guardar el borrador.']);
        }
        $draft = $this->present_draft($this->drafts->get((int) $result['id']));
        wp_send_json_success(['draft' => $draft]);
    }

    public function ajax_draft_get() {
        $this->authorize();
        $id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $draft = $this->present_draft($this->drafts->get($id));
        if (!$draft) {
            wp_send_json_error(['message' => 'Borrador no encontrado.']);
        }
        wp_send_json_success(['draft' => $draft]);
    }

    public function ajax_draft_payment() {
        $this->authorize();
        $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $result = $this->drafts->add_payment($draft_id, [
            'pay_date' => isset($_POST['pay_date']) ? sanitize_text_field(wp_unslash($_POST['pay_date'])) : '',
            'caja' => isset($_POST['caja']) ? sanitize_text_field(wp_unslash($_POST['caja'])) : 'Efectivo',
            'method' => isset($_POST['method']) ? sanitize_text_field(wp_unslash($_POST['method'])) : 'Efectivo',
            'amount_due' => isset($_POST['amount_due']) ? (float) $_POST['amount_due'] : 0,
            'amount_paid' => isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : 0,
            'change_amount' => isset($_POST['change_amount']) ? (float) $_POST['change_amount'] : 0,
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
            'charge_code' => isset($_POST['charge_code']) ? sanitize_text_field(wp_unslash($_POST['charge_code'])) : '',
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo registrar el pago.']);
        }
        $draft = $this->present_draft($this->drafts->get($draft_id));
        wp_send_json_success(['draft' => $draft, 'payment_id' => (int) $result['id']]);
    }

    public function ajax_product_lookup() {
        $this->authorize();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $q = trim($q);
        $mode = isset($_POST['mode']) ? strtolower(sanitize_text_field(wp_unslash($_POST['mode']))) : 'quick';
        if ($mode !== 'advanced') {
            $mode = 'quick';
        }
        $scope = isset($_POST['scope']) ? strtolower(sanitize_text_field(wp_unslash($_POST['scope']))) : 'todo';
        if (!in_array($scope, ['todo', 'descripcion', 'codigos'], true)) {
            $scope = 'todo';
        }
        $contains = $this->parse_contains_words(
            isset($_POST['contains']) ? wp_unslash($_POST['contains']) : ''
        );
        if ($q === '' && $contains) {
            $q = $contains[0];
        }
        if ($q === '' && !$contains) {
            wp_send_json_success(['products' => [], 'hint' => '']);
        }
        if ($mode === 'advanced' && $scope === 'descripcion') {
            $len = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
            if ($len < 2 && !$contains) {
                wp_send_json_success([
                    'products' => [],
                    'hint' => 'Escribe al menos 2 caracteres para buscar por descripción.',
                ]);
            }
        }
        $catalog = $this->quote_catalog();
        if (!$catalog) {
            wp_send_json_error(['message' => 'Búsqueda de productos no disponible.']);
        }
        $limit = $contains ? 60 : 20;
        $products = $catalog->search($q, $limit, $mode, $scope, 'local');
        if ($contains && method_exists($catalog, 'filter_contains_words')) {
            $products = $catalog->filter_contains_words($products, $contains, $scope);
            $products = array_slice(array_values($products), 0, 20);
        }
        $iva_map = $this->load_iva_map(is_array($products) ? $products : []);
        $out = [];
        foreach (is_array($products) ? $products : [] as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $pb = (int) ($hit['producto_base_id'] ?? 0);
            $afecto = true;
            if ($pb > 0 && isset($iva_map[$pb]) && $iva_map[$pb] === 'exento') {
                $afecto = false;
            }
            $unit_price = (float) ($hit['unit_price'] ?? 0);
            $out[] = [
                'product_id' => isset($hit['product_id']) ? $hit['product_id'] : null,
                'producto_base_id' => $pb > 0 ? $pb : null,
                'sku' => (string) ($hit['sku'] ?? ''),
                'supplier_code' => (string) ($hit['supplier_code'] ?? ''),
                'barcode' => (string) ($hit['barcode'] ?? ''),
                'description' => (string) ($hit['description'] ?? $hit['sku'] ?? ''),
                'unit_price' => $unit_price,
                'unit_price_bruto' => $unit_price,
                'unit_cost' => isset($hit['unit_cost']) ? $hit['unit_cost'] : null,
                'units_per_pack' => (float) ($hit['units_per_pack'] ?? 1),
                'family_mode' => (string) ($hit['family_mode'] ?? ''),
                'packaging' => (string) ($hit['packaging'] ?? ''),
                'family' => isset($hit['family']) && is_array($hit['family']) ? $hit['family'] : [],
                'local_only' => !empty($hit['local_only']),
                'afecto' => $afecto,
                'has_local_price' => !empty($hit['has_local_price']),
            ];
        }
        wp_send_json_success([
            'products' => $out,
            'mode' => $mode,
            'scope' => $scope,
            'contains' => $contains,
        ]);
    }

    public function ajax_line_stock() {
        $this->authorize();
        $can_view = current_user_can('riverso_view_stock')
            || current_user_can('riverso_view_warehouse')
            || current_user_can('manage_options');
        if (!$can_view) {
            wp_send_json_error(['message' => 'Sin permiso para ver stock.'], 403);
        }
        $raw = isset($_POST['product_ids']) ? wp_unslash($_POST['product_ids']) : '';
        $ids = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = $decoded;
            } else {
                $ids = preg_split('/\s*,\s*/', $raw);
            }
        } elseif (isset($_POST['product_ids']) && is_array($_POST['product_ids'])) {
            $ids = wp_unslash($_POST['product_ids']);
        }
        $wc_ids = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $wc_ids[$n] = true;
            }
        }
        $wc_ids = array_keys($wc_ids);
        if (count($wc_ids) > 200) {
            $wc_ids = array_slice($wc_ids, 0, 200);
        }
        $map = [];
        $cq_path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-module.php';
        if (!class_exists('Riverso_Customer_Quote_Module') && file_exists($cq_path)) {
            require_once $cq_path;
        }
        if (class_exists('Riverso_Customer_Quote_Module')) {
            $cq = Riverso_Customer_Quote_Module::get_instance();
            if (method_exists($cq, 'stock_map_for_wc_products')) {
                $map = $cq->stock_map_for_wc_products($wc_ids);
            }
        }
        wp_send_json_success(['stock' => $map]);
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function parse_contains_words($raw) {
        $words = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $words = $decoded;
            } else {
                $words = preg_split('/\s*,\s*/', $raw);
            }
        } elseif (is_array($raw)) {
            $words = $raw;
        }
        $out = [];
        foreach ($words as $w) {
            $w = trim((string) $w);
            if ($w === '') {
                continue;
            }
            $out[] = $w;
        }
        return $out;
    }

    public function ajax_family_price() {
        $this->authorize();
        $catalog = $this->quote_catalog();
        if (!$catalog) {
            wp_send_json_error(['message' => 'Motor de precios no disponible.']);
        }
        $pb = isset($_POST['producto_base_id']) ? absint($_POST['producto_base_id']) : 0;
        $qty = isset($_POST['family_qty']) ? (float) $_POST['family_qty'] : 0;
        if ($qty <= 0 && isset($_POST['qty'])) {
            $qty = (float) $_POST['qty'];
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if ($pb <= 0) {
            wp_send_json_error(['message' => 'producto_base_id requerido']);
        }
        $p_ref_raw = isset($_POST['p_ref']) ? wp_unslash($_POST['p_ref']) : '';
        $p_override = null;
        if ($p_ref_raw !== '' && is_numeric($p_ref_raw)) {
            $p_override = (float) $p_ref_raw;
            if ($p_override <= 0) {
                $p_override = null;
            }
        }
        $pack = $catalog->local_price_pack($pb, $qty, $p_override);
        $offers = $catalog->family_offers_for_base($pb);
        wp_send_json_success([
            'pricing' => $pack,
            'family' => $offers,
            'unit_price' => $pack['unit_price'],
            'rule_total' => isset($pack['rule_total']) ? $pack['rule_total'] : null,
            'rule_adjusted' => !empty($pack['rule_adjusted']),
            'unitario0' => isset($pack['unitario0']) ? $pack['unitario0'] : null,
            'has_rule' => !empty($pack['has_rule']),
            'p_asignado' => isset($pack['p_asignado']) ? $pack['p_asignado'] : null,
            'producto_base_id' => $pb,
            'family_qty' => $qty,
            'p_ref' => $p_override,
        ]);
    }

    public function ajax_search_quotes() {
        $this->authorize();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $q = trim($q);
        if ($q === '') {
            wp_send_json_success(['quotes' => []]);
        }
        $repo_path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-repository.php';
        if (!class_exists('Riverso_Customer_Quote_Repository') && file_exists($repo_path)) {
            require_once $repo_path;
        }
        if (!class_exists('Riverso_Customer_Quote_Repository')) {
            wp_send_json_error(['message' => 'Módulo de cotizaciones no disponible.']);
        }
        $repo = new Riverso_Customer_Quote_Repository();
        $items = $repo->list_quotes([
            'quote_number' => $q,
        ]);
        if (!is_array($items)) {
            $items = [];
        }
        $items = array_slice($items, 0, 20);
        $out = [];
        foreach ($items as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'quote_number' => (string) ($row['quote_number'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'total' => isset($row['total']) ? (float) $row['total'] : (isset($row['net_total']) ? (float) $row['net_total'] : null),
            ];
        }
        wp_send_json_success(['quotes' => $out]);
    }

    private function can_emit() {
        return current_user_can('riverso_emit_dte')
            || current_user_can('manage_options')
            || current_user_can('manage_woocommerce');
    }

    private function authorize() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'riverso_billing')) {
            wp_send_json_error(['message' => 'Sesión inválida.'], 403);
        }
        if (!$this->can_emit()) {
            wp_send_json_error(['message' => 'Sin permiso para emitir DTE.'], 403);
        }
    }
}
