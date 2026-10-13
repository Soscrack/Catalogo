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

    /** Diferencia máxima (CLP) entre total en pantalla y total FACTO que se absorbe en el último cobro. */
    const EMIT_ROUNDING_TOLERANCE = 3;

    private static $instance = null;

    /** @var Riverso_Dte_Issued_Repository */
    private $issued;

    /** @var Riverso_Receiver_Design_Repository */
    private $designs;

    /** @var Riverso_Receiver_Email_Repository */
    private $emails;

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
            'class-receiver-email-repository.php',
            'class-sii-stc-client.php',
            'class-billing-draft-repository.php',
            'class-payment-method-repository.php',
            'class-billing-payment-sync.php',
            'class-billing-document-search.php',
        ] as $file) {
            $path = $dir . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
        $this->issued = new Riverso_Dte_Issued_Repository();
        $this->designs = new Riverso_Receiver_Design_Repository();
        $this->emails = new Riverso_Receiver_Email_Repository();
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
            if (method_exists('Riverso_POS_Activator', 'ensure_receiver_emails_schema')) {
                Riverso_POS_Activator::ensure_receiver_emails_schema();
            }
            if (method_exists('Riverso_POS_Activator', 'ensure_billing_drafts_schema')) {
                Riverso_POS_Activator::ensure_billing_drafts_schema();
            }
            if (method_exists('Riverso_POS_Activator', 'ensure_billing_payments_schema')) {
                Riverso_POS_Activator::ensure_billing_payments_schema();
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
        add_action('wp_ajax_riverso_billing_preview_html', [$this, 'ajax_preview_html']);
        add_action('wp_ajax_riverso_billing_emit', [$this, 'ajax_emit']);
        add_action('wp_ajax_riverso_billing_draft_save', [$this, 'ajax_draft_save']);
        add_action('wp_ajax_riverso_billing_draft_get', [$this, 'ajax_draft_get']);
        add_action('wp_ajax_riverso_billing_draft_delete', [$this, 'ajax_draft_delete']);
        add_action('wp_ajax_riverso_billing_draft_payment', [$this, 'ajax_draft_payment']);
        add_action('wp_ajax_riverso_billing_product_lookup', [$this, 'ajax_product_lookup']);
        add_action('wp_ajax_riverso_billing_family_price', [$this, 'ajax_family_price']);
        add_action('wp_ajax_riverso_billing_line_stock', [$this, 'ajax_line_stock']);
        add_action('wp_ajax_riverso_billing_search_quotes', [$this, 'ajax_search_quotes']);
        add_action('wp_ajax_riverso_billing_cash_boxes', [$this, 'ajax_cash_boxes']);
        add_action('wp_ajax_riverso_billing_close_local', [$this, 'ajax_close_local']);
        add_action('wp_ajax_riverso_billing_payment_retry', [$this, 'ajax_payment_retry']);
        add_action('wp_ajax_riverso_billing_payment_delete', [$this, 'ajax_payment_delete']);
        add_action('wp_ajax_riverso_billing_payments_list', [$this, 'ajax_payments_list']);
        add_action('wp_ajax_riverso_billing_payment_methods', [$this, 'ajax_payment_methods']);
        add_action('wp_ajax_riverso_billing_search_documents', [$this, 'ajax_search_documents']);
        add_action('wp_ajax_riverso_billing_document_get', [$this, 'ajax_document_get']);
        add_action('wp_ajax_riverso_billing_document_pdf', [$this, 'ajax_document_pdf']);
        add_action('wp_ajax_riverso_billing_document_xml', [$this, 'ajax_document_xml']);
        add_action('wp_ajax_riverso_billing_document_email', [$this, 'ajax_document_email']);
        add_action('wp_ajax_riverso_billing_emails_list', [$this, 'ajax_emails_list']);
        add_action('wp_ajax_riverso_billing_email_add', [$this, 'ajax_email_add']);
        add_action('wp_ajax_riverso_billing_email_toggle', [$this, 'ajax_email_toggle']);
        add_action('wp_ajax_riverso_billing_email_delete', [$this, 'ajax_email_delete']);
    }

    /**
     * @return array<string, mixed>
     */
    public function app_config() {
        $issuer = function_exists('riverso_facto_issuer_profile')
            ? riverso_facto_issuer_profile()
            : ['ok' => false, 'missing' => ['Config FACTO'], 'issuer' => []];
        $quote_id = isset($_GET['quote_id']) ? absint($_GET['quote_id']) : 0;
        $draft_id = isset($_GET['draft_id']) ? absint($_GET['draft_id']) : 0;
        $dte_id = isset($_GET['dte_id']) ? absint($_GET['dte_id']) : 0;
        // Accesos rápidos del inicio: ?tipo=factura | ?tipo=boleta preselecciona el documento.
        $tipo = isset($_GET['tipo']) ? sanitize_key((string) $_GET['tipo']) : '';
        $initial_doc_type = $tipo === 'factura' ? 2 : 37;
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $user_name = '';
        if ($user && !empty($user->ID)) {
            $user_name = trim((string) ($user->display_name ?: $user->user_login));
        }
        $issuer_data = isset($issuer['issuer']) && is_array($issuer['issuer']) ? $issuer['issuer'] : [];
        $cash_boxes = [];
        if (!class_exists('Riverso_Cash_Module')) {
            $cash_file = RIVERSO_POS_PLUGIN_DIR . 'sales/cash/class-cash-module.php';
            if (file_exists($cash_file)) {
                require_once $cash_file;
            }
        }
        if (class_exists('Riverso_Cash_Module')) {
            $cash_boxes = Riverso_Cash_Module::get_instance()->repo()->list_payable_open(get_current_user_id());
        }
        $payment_methods = [];
        if (class_exists('Riverso_Payment_Method_Repository')) {
            $payment_methods = (new Riverso_Payment_Method_Repository())->list_all(true, true);
        }
        $search_users = $this->list_search_users();
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_billing'),
            'assetBase' => rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets',
            'surface' => 'portal',
            'portalUrl' => home_url('/interno/facturacion/'),
            'searchUrl' => home_url('/interno/facturacion/?vista=buscar'),
            'documentUrl' => home_url('/interno/facturacion/?vista=documento'),
            'quotesUrl' => home_url('/interno/customer-quotes/'),
            'todayDate' => current_time('Y-m-d'),
            'quoteId' => $quote_id,
            'draftId' => $draft_id,
            'dteId' => $dte_id,
            'initialDocType' => $initial_doc_type,
            'currentUserName' => $user_name,
            'searchUsers' => $search_users,
            'cashBoxes' => $cash_boxes,
            'paymentMethods' => $payment_methods,
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
            'previewTemplates' => [
                'thermal50mm' => (int) riverso_get_facto_config('template_thermal_50mm', 1),
            ],
            'documentTypes' => $quote_id > 0
                ? [
                    ['id' => 2, 'label' => 'Factura electrónica', 'enabled' => true],
                    ['id' => 37, 'label' => 'Boleta electrónica', 'enabled' => true],
                ]
                : [
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
                'previewHtml' => 'riverso_billing_preview_html',
                'emit' => 'riverso_billing_emit',
                'draftSave' => 'riverso_billing_draft_save',
                'draftGet' => 'riverso_billing_draft_get',
                'draftDelete' => 'riverso_billing_draft_delete',
                'draftPayment' => 'riverso_billing_draft_payment',
                'productLookup' => 'riverso_billing_product_lookup',
                'familyPrice' => 'riverso_billing_family_price',
                'lineStock' => 'riverso_billing_line_stock',
                'searchQuotes' => 'riverso_billing_search_quotes',
                'cashBoxes' => 'riverso_billing_cash_boxes',
                'closeLocal' => 'riverso_billing_close_local',
                'paymentRetry' => 'riverso_billing_payment_retry',
                'paymentDelete' => 'riverso_billing_payment_delete',
                'paymentsList' => 'riverso_billing_payments_list',
                'paymentMethods' => 'riverso_billing_payment_methods',
                'searchDocuments' => 'riverso_billing_search_documents',
                'documentGet' => 'riverso_billing_document_get',
                'documentPdf' => 'riverso_billing_document_pdf',
                'documentXml' => 'riverso_billing_document_xml',
                'documentEmail' => 'riverso_billing_document_email',
                'emailsList' => 'riverso_billing_emails_list',
                'emailAdd' => 'riverso_billing_email_add',
                'emailToggle' => 'riverso_billing_email_toggle',
                'emailDelete' => 'riverso_billing_email_delete',
            ],
        ];
    }

    public function ajax_cash_boxes() {
        $this->authorize();
        $boxes = [];
        if (!class_exists('Riverso_Cash_Module')) {
            $cash_file = RIVERSO_POS_PLUGIN_DIR . 'sales/cash/class-cash-module.php';
            if (file_exists($cash_file)) {
                require_once $cash_file;
            }
        }
        if (class_exists('Riverso_Cash_Module')) {
            $boxes = Riverso_Cash_Module::get_instance()->repo()->list_payable_open(get_current_user_id());
        }
        wp_send_json_success(['cashBoxes' => $boxes]);
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
        $vista = isset($_GET['vista']) ? sanitize_key((string) wp_unslash($_GET['vista'])) : '';
        if ($vista === 'buscar') {
            include RIVERSO_POS_PLUGIN_DIR . 'templates/billing/search.php';
            return;
        }
        if ($vista === 'documento') {
            include RIVERSO_POS_PLUGIN_DIR . 'templates/billing/document.php';
            return;
        }
        if ($vista === 'impresion') {
            include RIVERSO_POS_PLUGIN_DIR . 'templates/billing/printing.php';
            return;
        }
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
        // Llevar la cotización a Facturación la aprueba (Borrador → Aprobada, reserva stock).
        if (method_exists('Riverso_Customer_Quote_Repository', 'approve_for_billing')) {
            try {
                (new Riverso_Customer_Quote_Repository())->approve_for_billing($id);
            } catch (Exception $error) {
                // No bloquear la facturación por el estado de la cotización.
            }
        }
        wp_send_json_success($payload);
    }

    public function ajax_preview() {
        $this->authorize();
        // No llamar a FACTO: draft_preview asigna folio y puede enviar al SII.
        wp_send_json_error([
            'message' => 'Vista previa PDF oficial / térmica detenida: FACTO asigna folio aunque se pida borrador. Usa Carta por familia o Carta por producto. La emisión solo con el botón Emitir.',
            'code' => 'preview_facto_disabled',
        ]);
    }

    /**
     * Vista previa HTML imprimible (carta por familia / por producto).
     * No crea documento en FACTO ni envía al SII.
     */
    public function ajax_preview_html() {
        $this->authorize();

        $template = isset($_POST['template']) ? sanitize_text_field(wp_unslash($_POST['template'])) : 'family';
        $preferred = isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 37;
        if (!in_array($preferred, [2, 37], true)) {
            $preferred = 37;
        }

        $lines_raw = isset($_POST['lines']) ? wp_unslash($_POST['lines']) : '[]';
        if (is_string($lines_raw)) {
            $lines = json_decode($lines_raw, true);
        } else {
            $lines = $lines_raw;
        }
        if (!is_array($lines) || !$lines) {
            wp_send_json_error(['message' => 'Agrega al menos una línea.']);
        }

        $quote_pdf = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-pdf.php';
        if (!class_exists('Riverso_Quote_Pdf') && file_exists($quote_pdf)) {
            require_once $quote_pdf;
        }
        if (!class_exists('Riverso_Quote_Pdf')) {
            wp_send_json_error(['message' => 'Generador de carta no disponible.']);
        }

        $issue_date = isset($_POST['issue_date']) ? sanitize_text_field(wp_unslash($_POST['issue_date'])) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
            $issue_date = current_time('Y-m-d');
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }
            $unit = round((float) ($line['unit_price_bruto'] ?? $line['unit_price'] ?? 0), 4);
            $afecto = true;
            if (isset($line['afecto']) && ($line['afecto'] === false || $line['afecto'] === 0 || $line['afecto'] === '0')) {
                $afecto = false;
            }
            $normalized[] = [
                'sku' => substr(trim((string) ($line['sku'] ?? '')), 0, 64),
                'description' => trim((string) ($line['description'] ?? $line['line_description'] ?? 'Ítem')),
                'quantity' => $qty,
                'unit_price' => $unit,
                'unit_price_bruto' => $unit,
                'line_total_bruto' => isset($line['line_total_bruto']) ? (float) $line['line_total_bruto'] : null,
                'line_net' => isset($line['line_total_bruto']) ? (float) $line['line_total_bruto'] : null,
                'afecto' => $afecto,
                'producto_base_id' => isset($line['producto_base_id']) ? absint($line['producto_base_id']) : 0,
                'grupo_id' => isset($line['grupo_id']) ? absint($line['grupo_id']) : 0,
                'units_per_pack' => isset($line['units_per_pack']) ? (float) $line['units_per_pack'] : 1,
                'price_mode' => isset($line['price_mode']) ? (string) $line['price_mode'] : 'auto',
                'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
                'price_discount' => isset($line['price_discount']) ? (float) $line['price_discount'] : 0,
                'margin_discount' => isset($line['margin_discount']) ? (float) $line['margin_discount'] : 0,
                'discount_amount' => isset($line['discount_amount']) ? (float) $line['discount_amount'] : 0,
                'unit_cost' => isset($line['unit_cost']) ? $line['unit_cost'] : null,
                'rule_total' => isset($line['rule_total']) ? $line['rule_total'] : (isset($line['_rule_total']) ? $line['_rule_total'] : null),
                'rule_adjusted' => !empty($line['rule_adjusted']) || !empty($line['_rule_adjusted']),
                '_family' => isset($line['_family']) && is_array($line['_family']) ? $line['_family'] : [],
            ];
        }
        if (!$normalized) {
            wp_send_json_error(['message' => 'No hay líneas válidas.']);
        }

        $heading = $preferred === 2 ? 'FACTURA ELECTRÓNICA' : 'BOLETA ELECTRÓNICA';
        $folio = isset($_POST['estimated_folio']) ? sanitize_text_field(wp_unslash($_POST['estimated_folio'])) : '';
        $doc_number = $folio !== '' ? $folio : 'BORRADOR';

        $customer_name = isset($_POST['receiver_legal_name'])
            ? sanitize_text_field(wp_unslash($_POST['receiver_legal_name']))
            : '';
        $customer_rut = isset($_POST['receiver_rut'])
            ? sanitize_text_field(wp_unslash($_POST['receiver_rut']))
            : '';
        $customer_phone = isset($_POST['receiver_phone'])
            ? sanitize_text_field(wp_unslash($_POST['receiver_phone']))
            : '';

        $quote_like = [
            'id' => 0,
            'quote_number' => $doc_number,
            'issue_date' => $issue_date,
            'customer_name' => $customer_name,
            'notes' => '',
            'validity_days' => null,
            'validity_terms' => '',
            'lines' => $normalized,
        ];

        $doc = Riverso_Quote_Pdf::build($quote_like, $template);
        $doc['document_heading'] = $heading;
        $doc['document_number'] = $doc_number;
        $doc['quote_number'] = $doc_number;
        $doc['customer_rut'] = $customer_rut;
        $doc['customer_phone'] = $customer_phone;

        $css_path = RIVERSO_POS_PLUGIN_DIR . 'assets/css/customer-quote-pdf.css';
        $css_url = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets/css/customer-quote-pdf.css';
        if (is_file($css_path)) {
            $css_url .= '?ver=' . rawurlencode((string) filemtime($css_path));
        }
        $logo_path = RIVERSO_POS_PLUGIN_DIR . 'assets/img/logo-rs.png';
        $logo_url = '';
        if (is_file($logo_path)) {
            $logo_url = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets/img/logo-rs.png?ver='
                . rawurlencode((string) filemtime($logo_path));
        }

        ob_start();
        include RIVERSO_POS_PLUGIN_DIR . 'templates/customer-quotes/pdf.php';
        $html = ob_get_clean();
        if (!is_string($html) || $html === '') {
            wp_send_json_error(['message' => 'No se pudo generar la carta.']);
        }

        wp_send_json_success([
            'html' => $html,
            'template' => Riverso_Quote_Pdf::normalize_template($template),
            'document_type_id' => $preferred,
        ]);
    }

    public function ajax_emit() {
        $this->authorize();
        $quote_id = isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0;
        $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $mark_paid = !empty($_POST['mark_paid']);
        $send_email = !empty($_POST['send_email']);
        $pay_ctx = null;
        $pay_list = null;
        $has_payments_payload = isset($_POST['payments']) && (string) wp_unslash($_POST['payments']) !== ''
            && (string) wp_unslash($_POST['payments']) !== '[]';
        if ($mark_paid && !$has_payments_payload) {
            $pay_ctx = $this->validate_payment_context_from_request();
            if (is_wp_error($pay_ctx)) {
                wp_send_json_error(['message' => $pay_ctx->get_error_message()]);
            }
        }

        if ($quote_id > 0) {
            $existing = $this->issued->find_success_by_quote($quote_id);
            if ($existing) {
                $this->sync_linked_quote_status($quote_id);
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

            if ($mark_paid && $has_payments_payload) {
                $pay_list = $this->validate_emit_payments_list((float) $built['totals']['total_amount']);
                if (is_wp_error($pay_list)) {
                    wp_send_json_error(['message' => $pay_list->get_error_message()]);
                }
            }

            $client = $this->facto_client();
            $resp = $client->create_document($built['payload'], true);
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

            if ($draft_id > 0) {
                $this->drafts->mark_emitted($draft_id, (int) $insert_id, (float) $built['totals']['total_amount']);
            }

            $warnings = [];
            $stock_warning = $this->apply_sale_stock($draft_id);
            if ($stock_warning !== '') {
                $warnings[] = $stock_warning;
            }
            $immediate_payment = null;
            if ($mark_paid && is_array($pay_list) && $pay_list) {
                $registered = [];
                foreach ($pay_list as $pay_item) {
                    $ctx = $pay_item['ctx'];
                    $result = $this->register_and_sync_payment([
                        'draft_id' => $draft_id,
                        'dte_id' => (int) $insert_id,
                        'pay_date' => current_time('Y-m-d'),
                        'caja_id' => $ctx['caja']['id'],
                        'caja' => $ctx['caja']['nombre'],
                        'method_id' => $ctx['method']['id'],
                        'method' => $ctx['method']['nombre'],
                        'amount_due' => (float) $pay_item['amount_due'],
                        'amount_paid' => (float) $pay_item['amount_paid'],
                        'change_amount' => (float) $pay_item['change_amount'],
                        'amount_applied' => (float) $pay_item['amount_applied'],
                        'notes' => isset($_POST['pay_notes']) ? sanitize_textarea_field(wp_unslash($_POST['pay_notes'])) : '',
                        'cheque_numero' => $ctx['cheque_numero'],
                        'cheque_titular' => $ctx['cheque_titular'],
                        'cheque_banco' => $ctx['cheque_banco'],
                        'facto_sync_status' => 'pending',
                    ]);
                    if (empty($result['ok'])) {
                        $warnings[] = $result['message'] ?? 'No se pudo registrar un cobro.';
                    } elseif (!empty($result['sync']) && empty($result['sync']['ok'])) {
                        $warnings[] = 'Pago registrado, sync FACTO: ' . ($result['sync']['message'] ?? 'error');
                    } else {
                        $registered[] = $result;
                    }
                }
                $immediate_payment = $registered ? $registered[count($registered) - 1] : null;
            } elseif ($mark_paid && $pay_ctx) {
                $immediate_payment = $this->register_and_sync_payment([
                    'draft_id' => $draft_id,
                    'dte_id' => (int) $insert_id,
                    'pay_date' => current_time('Y-m-d'),
                    'caja_id' => $pay_ctx['caja']['id'],
                    'caja' => $pay_ctx['caja']['nombre'],
                    'method_id' => $pay_ctx['method']['id'],
                    'method' => $pay_ctx['method']['nombre'],
                    'amount_due' => (float) $built['totals']['total_amount'],
                    'amount_paid' => (float) $built['totals']['total_amount'],
                    'change_amount' => 0,
                    'amount_applied' => (float) $built['totals']['total_amount'],
                    'notes' => isset($_POST['pay_notes']) ? sanitize_textarea_field(wp_unslash($_POST['pay_notes'])) : '',
                    'cheque_numero' => $pay_ctx['cheque_numero'],
                    'cheque_titular' => $pay_ctx['cheque_titular'],
                    'cheque_banco' => $pay_ctx['cheque_banco'],
                    'facto_sync_status' => 'pending',
                ]);
                if (empty($immediate_payment['ok'])) {
                    $warnings[] = $immediate_payment['message'] ?? 'No se pudo registrar el pago inmediato.';
                } elseif (!empty($immediate_payment['sync']) && empty($immediate_payment['sync']['ok'])) {
                    $warnings[] = 'Pago registrado, sync FACTO: ' . ($immediate_payment['sync']['message'] ?? 'error');
                }
            }

            if ($draft_id > 0) {
                foreach ($this->drafts->list_pending_sync($draft_id) as $pending) {
                    $sync = Riverso_Billing_Payment_Sync::sync((int) $pending['id']);
                    if (empty($sync['ok'])) {
                        $warnings[] = 'Pago #' . $pending['id'] . ': ' . ($sync['message'] ?? 'error de sync');
                    }
                }
            }

            $email_result = null;
            if ($send_email) {
                $email_to = isset($_POST['email_to']) ? sanitize_text_field(wp_unslash($_POST['email_to'])) : '';
                $email_extra = isset($_POST['email_extra']) ? sanitize_text_field(wp_unslash($_POST['email_extra'])) : '';
                $email_result = $this->send_dte_email(
                    $resp,
                    $doc_id,
                    $folio,
                    Riverso_Billing_Totals::type_label($built['document_type_id']),
                    $email_to,
                    $email_extra
                );
                if (empty($email_result['ok'])) {
                    $warnings[] = $email_result['message'] ?? 'No se pudo enviar el correo.';
                } elseif ((int) $built['document_type_id'] === 2) {
                    $recv_rut = isset($_POST['receiver_rut'])
                        ? sanitize_text_field(wp_unslash($_POST['receiver_rut']))
                        : (string) ($receiver['tax_id_code'] ?? '');
                    $used = [];
                    foreach (array_merge(
                        preg_split('/[,\s;]+/', (string) $email_to) ?: [],
                        preg_split('/[,\s;]+/', (string) $email_extra) ?: []
                    ) as $addr) {
                        $addr = sanitize_email(trim((string) $addr));
                        if ($addr !== '' && is_email($addr)) {
                            $used[] = $addr;
                        }
                    }
                    if ($recv_rut !== '' && $used) {
                        $this->emails->mark_used($recv_rut, $used);
                    }
                }
            }

            $msg = 'Documento emitido.';
            if ($status === 2) {
                $msg = 'Documento creado pero no enviado al SII' . ($error_msg !== '' ? ': ' . $error_msg : '.');
            } elseif ($status === 0) {
                $msg = 'Documento emitido y enviado al SII.';
            }
            if ($warnings) {
                $msg .= ' ' . implode(' ', $warnings);
            }

            $payments = $this->drafts->list_document_payments($draft_id, (int) $insert_id);
            $draft = $draft_id > 0 ? $this->present_draft($this->drafts->get($draft_id)) : null;
            $this->sync_linked_quote_status($quote_id > 0 ? $quote_id : (int) ($draft['quote_id'] ?? 0));

            // Impresión rápida: se encola aquí (no en el navegador) para que no se pierda con la
            // redirección. Si la impresora o el hub fallan, la emisión igual queda hecha.
            $print_job = null;
            if (!empty($_POST['quick_print']) && (int) $insert_id > 0 && class_exists('Riverso_Print_Module')) {
                try {
                    $print_job = Riverso_Print_Module::get_instance()->enqueue_dte((int) $insert_id, [
                        'origen' => 'emision',
                        'station_id' => isset($_POST['print_station_id']) ? absint($_POST['print_station_id']) : 0,
                    ]);
                } catch (\Throwable $e) {
                    $print_job = null;
                }
            }

            wp_send_json_success([
                'idempotent' => false,
                'message' => $msg,
                'warnings' => $warnings,
                'print_job' => $print_job,
                'email' => $email_result,
                'immediate_payment' => $immediate_payment,
                'draft' => $draft,
                'payments' => $payments,
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
            // Preferir _rule_total (resultado fresco del cliente) sobre rule_total persistido.
            $rule_total = null;
            if (isset($line['_rule_total']) && $line['_rule_total'] !== null && $line['_rule_total'] !== '') {
                $rule_total = (float) $line['_rule_total'];
            } elseif (isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
                $rule_total = (float) $line['rule_total'];
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
            // Unitario por envase solo para totales DTE de cabecera; no pisa el unitario del cliente.
            $pack_unit_bruto = round($line_net / $qty, 6);
            $src = $normalized_input[$idx] ?? $line;
            $client_unit = (float) ($src['unit_price'] ?? $line['unit_price'] ?? $pack_unit_bruto);
            $rule_total_persist = null;
            if (isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '') {
                $rule_total_persist = $line['rule_total'];
            } elseif (isset($src['rule_total']) && $src['rule_total'] !== null && $src['rule_total'] !== '') {
                $rule_total_persist = $src['rule_total'];
            }
            $rule_adjusted_persist = !empty($line['rule_adjusted']) || !empty($src['rule_adjusted']);
            $persist_line = [
                'sku' => (string) ($src['sku'] ?? ''),
                'description' => (string) ($src['description'] ?? ''),
                'quantity' => $qty,
                'unit_price' => $client_unit,
                // Persistir precio por unidad del cliente (no bruto/envases).
                'unit_price_bruto' => $client_unit,
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
                'rule_total' => $rule_total_persist,
                'rule_adjusted' => $rule_adjusted_persist,
            ];
            $persist[] = $persist_line;
            $emit_lines[] = [
                'sku' => $persist_line['sku'],
                'description' => $persist_line['description'],
                'quantity' => $qty,
                'unit_price_bruto' => $pack_unit_bruto,
                'line_total_bruto' => $persist_line['line_total_bruto'],
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
        if ($type !== Riverso_Quote_Type::VENTA) {
            return new WP_Error('bad_type', 'Solo cotizaciones de tipo Venta.');
        }
        if ($status === Riverso_Quote_Status::REJECTED || $status === Riverso_Quote_Status::CANCELLED) {
            return new WP_Error('closed_quote', 'La cotización está ' . Riverso_Quote_Status::label($status) . '. Reábrala para facturar.');
        }

        $existing = $this->issued->find_success_by_quote($quote_id);

        $quote_lines = $quote['lines'] ?? [];
        $catalog = $this->quote_catalog();
        if ($catalog && $quote_lines) {
            // _family da el grupo: sin él, el total de regla de una familia se contaría por línea.
            $hydrated = $catalog->hydrate_quote_families(['lines' => $quote_lines]);
            $quote_lines = $hydrated['lines'] ?? $quote_lines;
        }
        $iva_map = $this->load_iva_map($quote_lines);
        $lines = [];
        $stored_net = [];
        foreach ($quote_lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $pb = isset($line['producto_base_id']) ? (int) $line['producto_base_id'] : 0;
            $afecto = true;
            if ($pb > 0 && isset($iva_map[$pb]) && $iva_map[$pb] === 'exento') {
                $afecto = false;
            }
            // La misma línea comercial de la cotización: unitario por unidad, modo, regla y
            // descuentos. Facturación recalcula el total igual que la cotización.
            $unit = (float) ($line['unit_price'] ?? 0);
            $rule_adjusted = !empty($line['rule_adjusted'])
                && isset($line['rule_total']) && $line['rule_total'] !== null && $line['rule_total'] !== '';
            $family = isset($line['_family']) && is_array($line['_family']) ? $line['_family'] : null;
            $gid = $family && !empty($family['grupo_id']) ? (int) $family['grupo_id'] : 0;
            $sku = (string) ($line['sku'] ?? $line['supplier_code'] ?? '');
            $desc = (string) ($line['description'] ?? $line['name'] ?? '');
            $lines[] = [
                'sku' => $sku,
                'description' => $desc,
                'quantity' => $qty,
                'unit_price_bruto' => $unit,
                'unit_price' => $unit,
                'afecto' => $afecto,
                'product_id' => !empty($line['product_id']) ? (int) $line['product_id'] : null,
                'producto_base_id' => $pb > 0 ? $pb : null,
                'units_per_pack' => (float) ($line['units_per_pack'] ?? 1),
                'family_mode' => (string) ($line['family_mode'] ?? ''),
                'packaging' => (string) ($line['packaging'] ?? ''),
                'grupo_id' => $gid > 0 ? $gid : null,
                '_family' => $family,
                'unit_cost' => array_key_exists('unit_cost', $line) ? $line['unit_cost'] : null,
                'price_mode' => (string) ($line['price_mode'] ?? 'auto'),
                'price_ref' => isset($line['price_ref']) ? $line['price_ref'] : null,
                'price_total' => isset($line['price_total']) ? $line['price_total'] : null,
                'price_discount' => (float) ($line['price_discount'] ?? 0),
                'margin_discount' => (float) ($line['margin_discount'] ?? 0),
                'discount_amount' => (float) ($line['discount_amount'] ?? 0),
                'rule_total' => $rule_adjusted ? round((float) $line['rule_total'], 2) : null,
                'rule_adjusted' => $rule_adjusted,
                'stock_breakdown' => $line['stock_breakdown'] ?? null,
            ];
            $stored_net[] = round((float) ($line['line_net'] ?? 0), 2);
        }
        $lines = $this->pin_quote_line_totals($lines, $stored_net);

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
     * Cotizaciones guardadas antes de la fase 74 no traen rule_total y su unitario quedó
     * en 2 decimales: unitario × cantidad ya no da el total guardado (R-1 $500 → $499,98).
     * Si una línea, o su familia, no cuadra con lo guardado, ese total queda como total fijo.
     *
     * @param array<int,array> $lines  Líneas para facturación (con grupo_id y modo de precio).
     * @param array<int,float> $stored line_net guardado por índice (bruto después de descuento).
     * @return array<int,array>
     */
    private function pin_quote_line_totals(array $lines, array $stored) {
        if (!class_exists('Riverso_Quote_Totals')) {
            return $lines;
        }
        $groups = [];
        foreach ($lines as $i => $line) {
            $key = !empty($line['grupo_id']) ? 'g' . (int) $line['grupo_id'] : 'l' . $i;
            $groups[$key][] = $i;
        }
        foreach ($groups as $indexes) {
            $group = [];
            foreach ($indexes as $i) {
                $line = $lines[$i];
                $manual_total = ($line['price_mode'] ?? '') === 'manual'
                    && isset($line['price_total']) && $line['price_total'] !== null && $line['price_total'] !== '';
                if (!empty($line['rule_adjusted']) || $manual_total) {
                    continue 2;
                }
                $group[] = $line;
            }
            $calc = Riverso_Quote_Totals::calculate($group);
            $matches = true;
            $gross = 0.0;
            foreach ($indexes as $pos => $i) {
                $calc_line = $calc['lines'][$pos] ?? [];
                if (abs((float) ($calc_line['line_net'] ?? 0) - (float) ($stored[$i] ?? 0)) > 0.005) {
                    $matches = false;
                }
                $gross += (float) ($stored[$i] ?? 0) + (float) ($calc_line['discount_amount'] ?? 0);
            }
            if ($matches) {
                continue;
            }
            foreach ($indexes as $i) {
                $lines[$i]['rule_total'] = round($gross, 2);
                $lines[$i]['rule_adjusted'] = true;
            }
        }
        return $lines;
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

        $options = [
            // Unit price neto (6 dec.) + total bruto exacto. No usar gross_values: FACTO lo rechaza en boleta 37.
            'rounding_type' => 'gross',
        ];
        if ($draft_preview) {
            $options['draft_preview'] = 1;
        }
        $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
        if ($template_id > 0) {
            $options['template_id'] = $template_id;
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
            'email' => (string) ($c['contacto_email'] ?? $c['email'] ?? ''),
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
        $result = $this->save_draft_from_request();
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo guardar el borrador.']);
        }
        $draft = $this->present_draft($this->drafts->get((int) $result['id']));
        if (!empty($draft['quote_id'])) {
            $this->sync_linked_quote_status((int) $draft['quote_id']);
        }
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

    public function ajax_draft_delete() {
        $this->authorize();
        $id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $result = $this->drafts->delete_draft($id);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo borrar el borrador.']);
        }
        $cash = $this->cash_repo();
        $payments = isset($result['payments']) && is_array($result['payments']) ? $result['payments'] : [];
        foreach ($payments as $pay) {
            if (!is_array($pay)) {
                continue;
            }
            $caja_id = (int) ($pay['caja_id'] ?? 0);
            $applied = (float) ($pay['amount_applied'] ?? 0);
            $pay_id = (int) ($pay['id'] ?? 0);
            if ($caja_id > 0 && $applied > 0 && $cash) {
                $cash->register_payment_reverso($caja_id, $applied, $pay_id, 'Reverso pago #' . $pay_id . ' (borrador eliminado)');
            }
            if (!empty($pay['facto_payment_id']) && function_exists('riverso_create_task')) {
                riverso_create_task('eliminar_pago_facto', 'Eliminar pago P' . $pay['facto_payment_id'] . ' en FACTO', [
                    'prioridad' => 'alta',
                    'descripcion' => 'Borrar en FACTO el pago ' . $pay['facto_payment_id']
                        . ' (borrador #' . $id . ' eliminado).',
                    'datos_extra' => [
                        'facto_payment_id' => $pay['facto_payment_id'],
                        'payment_id' => $pay_id,
                        'draft_id' => $id,
                        'amount' => $pay['amount_applied'] ?? 0,
                    ],
                ]);
            }
        }
        // Devolver el stock que descontó (boleta cerrada) y re-sincronizar la cotización.
        if (class_exists('Riverso_Sale_Stock_Service')) {
            Riverso_Sale_Stock_Service::get_instance()->revert_document($id);
        }
        $this->sync_linked_quote_status((int) ($result['draft']['quote_id'] ?? 0));
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'billing.draft_deleted',
                'billing_draft',
                $id,
                $result['draft'] ?? [],
                [],
                'Borrador eliminado'
            );
        }
        wp_send_json_success([
            'deleted_id' => $id,
            'redirect' => home_url('/interno/facturacion/?vista=buscar'),
        ]);
    }

    public function ajax_draft_payment() {
        $this->authorize();
        $ctx = $this->validate_payment_context_from_request();
        if (is_wp_error($ctx)) {
            wp_send_json_error(['message' => $ctx->get_error_message()]);
        }
        $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $dte_id = isset($_POST['dte_id']) ? absint($_POST['dte_id']) : 0;
        $amount_paid = isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : 0;
        $change_amount = isset($_POST['change_amount']) ? (float) $_POST['change_amount'] : 0;
        if (empty($ctx['method']['permite_vuelto'])) {
            $change_amount = 0;
        }
        $applied = round(max(0, $amount_paid - $change_amount), 2);
        $result = $this->register_and_sync_payment([
            'draft_id' => $draft_id,
            'dte_id' => $dte_id,
            'pay_date' => isset($_POST['pay_date']) ? sanitize_text_field(wp_unslash($_POST['pay_date'])) : '',
            'caja_id' => $ctx['caja']['id'],
            'caja' => $ctx['caja']['nombre'],
            'method_id' => $ctx['method']['id'],
            'method' => $ctx['method']['nombre'],
            'amount_due' => isset($_POST['amount_due']) ? (float) $_POST['amount_due'] : 0,
            'amount_paid' => $amount_paid,
            'change_amount' => $change_amount,
            'amount_applied' => $applied,
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '',
            'charge_code' => isset($_POST['charge_code']) ? sanitize_text_field(wp_unslash($_POST['charge_code'])) : '',
            'cheque_numero' => $ctx['cheque_numero'],
            'cheque_titular' => $ctx['cheque_titular'],
            'cheque_banco' => $ctx['cheque_banco'],
            'facto_sync_status' => 'pending',
        ]);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo registrar el pago.']);
        }
        $draft = $draft_id > 0 ? $this->present_draft($this->drafts->get($draft_id)) : null;
        $payments = $this->drafts->list_document_payments($draft_id, $dte_id);
        wp_send_json_success([
            'draft' => $draft,
            'payments' => $payments,
            'payment_id' => (int) ($result['id'] ?? 0),
            'sync' => $result['sync'] ?? null,
        ]);
    }

    public function ajax_close_local() {
        $this->authorize();
        $type = isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 37;
        if ($type !== 37) {
            wp_send_json_error(['message' => 'Cerrar sin enviar al SII solo está disponible en boleta.']);
        }
        $saved = $this->save_draft_from_request('closed_local');
        if (empty($saved['ok'])) {
            wp_send_json_error(['message' => $saved['message'] ?? 'No se pudo cerrar el documento.']);
        }
        $this->drafts->mark_closed_local((int) $saved['id']);
        $this->apply_sale_stock((int) $saved['id']);
        $draft = $this->present_draft($this->drafts->get((int) $saved['id']));
        if (!empty($draft['quote_id'])) {
            $this->sync_linked_quote_status((int) $draft['quote_id']);
        }
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'billing.draft_closed_local',
                'billing_draft',
                (int) $saved['id'],
                [],
                [],
                'Cierre local sin envío al SII'
            );
        }
        wp_send_json_success([
            'message' => 'Documento cerrado sin enviar al SII. Ya puedes registrar pagos.',
            'draft' => $draft,
        ]);
    }

    public function ajax_payment_retry() {
        $this->authorize();
        $id = isset($_POST['payment_id']) ? absint($_POST['payment_id']) : 0;
        $pay = $this->drafts->get_payment($id);
        if (!$pay) {
            wp_send_json_error(['message' => 'Pago no encontrado.']);
        }
        $status = (string) ($pay['facto_sync_status'] ?? '');
        $force_unknown = !empty($_POST['confirm_unknown']);
        if ($status === 'unknown' && !$force_unknown) {
            wp_send_json_error([
                'message' => 'Este pago quedó en estado desconocido. Reintentar podría duplicarlo en FACTO.',
                'needs_confirm' => true,
            ]);
        }
        if ($status !== 'error' && !($status === 'unknown' && $force_unknown) && $status !== 'pending') {
            wp_send_json_error(['message' => 'Este pago no se puede reintentar.']);
        }
        $sync = Riverso_Billing_Payment_Sync::sync($id);
        $draft_id = (int) ($pay['draft_id'] ?? 0);
        $dte_id = (int) ($pay['dte_id'] ?? 0);
        wp_send_json_success([
            'sync' => $sync,
            'payments' => $this->drafts->list_document_payments($draft_id, $dte_id),
            'draft' => $draft_id > 0 ? $this->present_draft($this->drafts->get($draft_id)) : null,
        ]);
    }

    public function ajax_payment_delete() {
        $this->authorize();
        $id = isset($_POST['payment_id']) ? absint($_POST['payment_id']) : 0;
        $pay = $this->drafts->get_payment($id);
        if (!$pay) {
            wp_send_json_error(['message' => 'Pago no encontrado.']);
        }
        $caja_id = (int) ($pay['caja_id'] ?? 0);
        $cash = $this->cash_repo();
        if ($caja_id > 0 && $cash && !$cash->user_has_perm($caja_id, get_current_user_id(), 'borrar_pago')) {
            wp_send_json_error(['message' => 'No tienes permiso para borrar pagos en esta caja.']);
        }
        $deleted = $this->drafts->delete_payment($id);
        if (empty($deleted['ok'])) {
            wp_send_json_error(['message' => $deleted['message'] ?? 'No se pudo borrar.']);
        }
        if ($caja_id > 0 && $cash) {
            $applied = (float) ($pay['amount_applied'] ?? 0);
            if ($applied > 0) {
                $cash->register_payment_reverso($caja_id, $applied, $id, 'Reverso pago #' . $id);
            }
        }
        if (!empty($pay['facto_payment_id']) && function_exists('riverso_create_task')) {
            $dte = !empty($pay['dte_id']) ? $this->issued->get((int) $pay['dte_id']) : null;
            riverso_create_task('eliminar_pago_facto', 'Eliminar pago P' . $pay['facto_payment_id'] . ' en FACTO', [
                'prioridad' => 'alta',
                'descripcion' => 'Borrar en FACTO el pago ' . $pay['facto_payment_id']
                    . ' (folio ' . ($dte['folio'] ?? '—') . ', $' . number_format((float) $pay['amount_applied'], 0, ',', '.') . ').',
                'datos_extra' => [
                    'facto_payment_id' => $pay['facto_payment_id'],
                    'payment_id' => $id,
                    'folio' => $dte['folio'] ?? '',
                    'amount' => $pay['amount_applied'],
                ],
            ]);
        }
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'billing.payment_deleted',
                'billing_payment',
                $id,
                $pay,
                [],
                'Pago borrado'
            );
        }
        $draft_id = (int) ($pay['draft_id'] ?? 0);
        $dte_id = (int) ($pay['dte_id'] ?? 0);
        wp_send_json_success([
            'payments' => $this->drafts->list_document_payments($draft_id, $dte_id),
            'draft' => $draft_id > 0 ? $this->present_draft($this->drafts->get($draft_id)) : null,
        ]);
    }

    public function ajax_payments_list() {
        $this->authorize();
        $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
        $dte_id = isset($_POST['dte_id']) ? absint($_POST['dte_id']) : 0;
        wp_send_json_success([
            'payments' => $this->drafts->list_document_payments($draft_id, $dte_id),
            'draft' => $draft_id > 0 ? $this->present_draft($this->drafts->get($draft_id)) : null,
        ]);
    }

    public function ajax_search_documents() {
        $this->authorize();
        if (!class_exists('Riverso_Billing_Document_Search')) {
            wp_send_json_error(['message' => 'Búsqueda no disponible.']);
        }
        $filters = [
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '',
            'date_to' => isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '',
            'receiver_rut' => isset($_POST['receiver_rut']) ? sanitize_text_field(wp_unslash($_POST['receiver_rut'])) : '',
            'receiver_name' => isset($_POST['receiver_name']) ? sanitize_text_field(wp_unslash($_POST['receiver_name'])) : '',
            'document_number' => isset($_POST['document_number']) ? sanitize_text_field(wp_unslash($_POST['document_number'])) : '',
            'payment_status' => isset($_POST['payment_status']) ? sanitize_key(wp_unslash($_POST['payment_status'])) : 'all',
            'document_status' => isset($_POST['document_status']) ? sanitize_key(wp_unslash($_POST['document_status'])) : '',
            'total_min' => isset($_POST['total_min']) ? sanitize_text_field(wp_unslash($_POST['total_min'])) : '',
            'total_max' => isset($_POST['total_max']) ? sanitize_text_field(wp_unslash($_POST['total_max'])) : '',
            'created_by' => isset($_POST['created_by']) ? absint($_POST['created_by']) : 0,
            'folio_from' => isset($_POST['folio_from']) ? sanitize_text_field(wp_unslash($_POST['folio_from'])) : '',
            'folio_to' => isset($_POST['folio_to']) ? sanitize_text_field(wp_unslash($_POST['folio_to'])) : '',
            'sii_status' => isset($_POST['sii_status']) ? sanitize_key(wp_unslash($_POST['sii_status'])) : '',
            'include_issued' => !isset($_POST['include_issued']) || !empty($_POST['include_issued']),
            'include_drafts' => !isset($_POST['include_drafts']) || !empty($_POST['include_drafts']),
        ];
        $result = (new Riverso_Billing_Document_Search())->search($filters);
        wp_send_json_success($result);
    }

    public function ajax_emails_list() {
        $this->authorize();
        $rut = isset($_POST['rut']) ? sanitize_text_field(wp_unslash($_POST['rut'])) : '';
        $list = $this->emails->list_by_rut($rut);
        $suggestion = null;
        if (!$list) {
            $repo = $this->customer_repo();
            $customer = $repo ? $repo->find_by_rut($rut) : null;
            if ($customer) {
                $email = sanitize_email((string) ($customer['contacto_email'] ?? ''));
                if ($email !== '' && is_email($email)) {
                    $suggestion = [
                        'id' => 0,
                        'rut' => $this->emails->normalize_rut($rut),
                        'email' => $email,
                        'is_selected' => true,
                        'suggested' => true,
                    ];
                }
            }
        }
        wp_send_json_success([
            'emails' => $list,
            'suggestion' => $suggestion,
        ]);
    }

    public function ajax_email_add() {
        $this->authorize();
        $rut = isset($_POST['rut']) ? sanitize_text_field(wp_unslash($_POST['rut'])) : '';
        $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
        $result = $this->emails->add($rut, $email, true);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo agregar el correo.']);
        }
        wp_send_json_success([
            'email' => $result['email'],
            'emails' => $this->emails->list_by_rut($rut),
        ]);
    }

    public function ajax_email_toggle() {
        $this->authorize();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $selected = !empty($_POST['selected']);
        $result = $this->emails->set_selected($id, $selected);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo actualizar.']);
        }
        $rut = (string) ($result['email']['rut'] ?? '');
        wp_send_json_success([
            'email' => $result['email'],
            'emails' => $rut !== '' ? $this->emails->list_by_rut($rut) : [],
        ]);
    }

    public function ajax_email_delete() {
        $this->authorize();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $existing = $this->emails->get($id);
        $result = $this->emails->delete($id);
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo eliminar.']);
        }
        $rut = (string) ($existing['rut'] ?? '');
        wp_send_json_success([
            'deleted_id' => $id,
            'emails' => $rut !== '' ? $this->emails->list_by_rut($rut) : [],
        ]);
    }

    public function ajax_document_email() {
        $this->authorize();
        $dte_id = isset($_POST['dte_id']) ? absint($_POST['dte_id']) : 0;
        if ($dte_id <= 0) {
            wp_send_json_error(['message' => 'Documento inválido.']);
        }
        $dte = $this->issued->get($dte_id);
        if (!$dte || empty($dte['facto_document_id'])) {
            wp_send_json_error(['message' => 'Documento emitido no encontrado.']);
        }
        $emails_raw = isset($_POST['emails']) ? wp_unslash($_POST['emails']) : '';
        if (is_string($emails_raw)) {
            $decoded = json_decode($emails_raw, true);
            $emails_list = is_array($decoded) ? $decoded : preg_split('/[,\s;]+/', $emails_raw);
        } else {
            $emails_list = is_array($emails_raw) ? $emails_raw : [];
        }
        $emails_csv = implode(',', array_map('strval', $emails_list ?: []));
        $result = $this->send_dte_email(
            [],
            (int) $dte['facto_document_id'],
            (string) ($dte['folio'] ?? ''),
            (string) ($dte['document_type_label'] ?? Riverso_Billing_Totals::type_label((int) ($dte['document_type_id'] ?? 0))),
            $emails_csv,
            ''
        );
        if (empty($result['ok'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'No se pudo enviar el correo.']);
        }
        $recv_rut = (string) ($dte['receiver_rut'] ?? '');
        if ($recv_rut !== '') {
            $used = [];
            foreach ($emails_list ?: [] as $addr) {
                $addr = sanitize_email(trim((string) $addr));
                if ($addr !== '' && is_email($addr)) {
                    $used[] = $addr;
                }
            }
            if ($used) {
                $this->emails->mark_used($recv_rut, $used);
            }
        }
        wp_send_json_success([
            'message' => $result['message'] ?? 'Correo enviado.',
            'folio' => (string) ($dte['folio'] ?? ''),
        ]);
    }

    public function ajax_document_pdf() {
        $this->authorize();
        $this->send_electronic_file('document_pdf', 'pdf_base64', 'FACTO no devolvió el PDF del documento.');
    }

    public function ajax_document_xml() {
        $this->authorize();
        $this->send_electronic_file('document_xml', 'xml_base64', 'FACTO no devolvió el XML del documento.');
    }

    /**
     * @param string $source_key Clave en electronic_document de FACTO.
     * @param string $out_key
     * @param string $missing_msg
     */
    private function send_electronic_file($source_key, $out_key, $missing_msg) {
        $dte_id = isset($_POST['dte_id']) ? absint($_POST['dte_id']) : 0;
        if ($dte_id <= 0) {
            wp_send_json_error(['message' => 'Documento inválido.']);
        }
        $dte = $this->issued->get($dte_id);
        if (!$dte || empty($dte['facto_document_id'])) {
            wp_send_json_error(['message' => 'Documento emitido no encontrado.']);
        }
        $full = $this->facto_client()->get_document((int) $dte['facto_document_id']);
        if (is_wp_error($full)) {
            wp_send_json_error(['message' => $full->get_error_message()]);
        }
        $electronic = isset($full['electronic_document']) && is_array($full['electronic_document'])
            ? $full['electronic_document']
            : [];
        $b64 = (string) ($electronic[$source_key] ?? '');
        if ($b64 === '') {
            wp_send_json_error(['message' => $missing_msg]);
        }
        wp_send_json_success([
            $out_key => $b64,
            'folio' => (string) ($dte['folio'] ?? ''),
            'document_type_id' => (int) ($dte['document_type_id'] ?? 0),
            'document_type_label' => (string) ($dte['document_type_label'] ?? ''),
        ]);
    }

    /**
     * Detalle de un DTE emitido: datos locales + borrador vinculado + encabezado/detalle FACTO.
     */
    public function ajax_document_get() {
        $this->authorize();
        $dte_id = isset($_POST['dte_id']) ? absint($_POST['dte_id']) : 0;
        $dte = $dte_id > 0 ? $this->issued->get($dte_id) : null;
        if (!$dte) {
            wp_send_json_error(['message' => 'Documento emitido no encontrado.']);
        }

        $draft = $this->drafts->find_by_dte($dte_id);
        $warnings = [];
        $facto_header = [];
        $facto_details = [];
        if (!empty($dte['facto_document_id'])) {
            $full = $this->facto_client()->get_document((int) $dte['facto_document_id']);
            if (is_wp_error($full)) {
                $warnings[] = 'No se pudo consultar FACTO: ' . $full->get_error_message();
            } else {
                $facto_header = isset($full['header']) && is_array($full['header']) ? $full['header'] : [];
                $facto_details = isset($full['details']) && is_array($full['details']) ? $full['details'] : [];
            }
        }

        $lines = [];
        $lines_source = 'none';
        if ($draft && !empty($draft['lines'])) {
            $lines_source = 'draft';
            foreach ($draft['lines'] as $l) {
                $qty = (float) ($l['quantity'] ?? 0);
                $unit = (float) ($l['unit_price_bruto'] ?? $l['unit_price'] ?? 0);
                $total = (float) ($l['line_total_bruto'] ?? 0);
                if ($total <= 0) {
                    $total = round($qty * $unit, 2);
                }
                $lines[] = [
                    'sku' => (string) ($l['sku'] ?? ''),
                    'description' => (string) ($l['description'] ?? ''),
                    'quantity' => $qty,
                    'unit_price_bruto' => $unit,
                    'line_total_bruto' => $total,
                    'discount_amount' => (float) ($l['discount_amount'] ?? 0),
                    'afecto' => !empty($l['afecto']),
                    'producto_base_id' => $l['producto_base_id'] ?? null,
                    'grupo_id' => $l['grupo_id'] ?? null,
                    'units_per_pack' => $l['units_per_pack'] ?? 1,
                ];
            }
        } elseif ($facto_details) {
            $lines_source = 'facto';
            foreach ($facto_details as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $qty = (float) ($d['quantity'] ?? 0);
                $unit_net = (float) ($d['unit_price'] ?? 0);
                $pct = 0.0;
                if (!empty($d['taxes']) && is_array($d['taxes'])) {
                    foreach ($d['taxes'] as $tax) {
                        $pct += (float) ($tax['tax_percentage'] ?? 0);
                    }
                }
                $afecto = $pct > 0 || (string) ($d['vat_status'] ?? '') === '1';
                $factor = 1 + ($pct / 100);
                $lines[] = [
                    'sku' => (string) ($d['sku'] ?? ''),
                    'description' => (string) ($d['line_description'] ?? $d['long_description'] ?? 'Ítem'),
                    'quantity' => $qty,
                    'unit_price_bruto' => round($unit_net * $factor, 2),
                    'line_total_bruto' => round($qty * $unit_net * $factor),
                    'discount_amount' => (float) ($d['modifier_amount'] ?? 0),
                    'afecto' => $afecto,
                    'producto_base_id' => null,
                    'grupo_id' => null,
                    'units_per_pack' => 1,
                ];
            }
        }

        $pick = static function ($draft_val, $facto_val) {
            $draft_val = trim((string) $draft_val);
            return $draft_val !== '' ? $draft_val : trim((string) $facto_val);
        };
        $receiver = [
            'rut' => $pick($draft['receiver_rut'] ?? '', $facto_header['receiver_tax_id_code'] ?? ($dte['receiver_rut'] ?? '')),
            'legal_name' => $pick($draft['receiver_legal_name'] ?? '', $facto_header['receiver_legal_name'] ?? ($dte['receiver_legal_name'] ?? '')),
            'activity' => $pick($draft['receiver_activity'] ?? '', $facto_header['receiver_activity'] ?? ''),
            'address' => $pick($draft['receiver_address'] ?? '', $facto_header['receiver_address'] ?? ''),
            'district' => $pick($draft['receiver_district'] ?? '', $facto_header['receiver_district'] ?? ''),
            'city' => $pick($draft['receiver_city'] ?? '', $facto_header['receiver_city'] ?? ''),
            'phone' => $pick($draft['receiver_phone'] ?? '', $facto_header['receiver_phone'] ?? ''),
        ];

        $payment_conditions = (string) ($draft['payment_conditions'] ?? ($dte['payment_conditions'] ?? '0'));
        $issue_date = (string) ($dte['issue_date'] ?? '');
        $due_date = (string) ($draft['due_date'] ?? '');
        if ($due_date === '' && $issue_date !== '') {
            $parts = array_map('intval', explode(',', $payment_conditions));
            $days = $parts ? max($parts) : 0;
            $due_date = $days > 0
                ? gmdate('Y-m-d', strtotime($issue_date . ' +' . $days . ' days'))
                : $issue_date;
        }

        $created_by_name = '';
        if (!empty($dte['created_by'])) {
            $u = get_userdata((int) $dte['created_by']);
            if ($u) {
                $created_by_name = trim((string) ($u->display_name ?: $u->user_login));
            }
        }

        $draft_id = $draft ? (int) $draft['id'] : 0;
        $payments = $this->drafts->list_document_payments($draft_id, $dte_id);
        $paid = 0.0;
        foreach ($payments as $p) {
            $paid += (float) ($p['amount_applied'] ?? 0);
        }
        $total = (float) ($dte['total_amount'] ?? 0);

        wp_send_json_success([
            'document' => [
                'dte_id' => $dte_id,
                'draft_id' => $draft_id ?: null,
                'facto_document_id' => $dte['facto_document_id'],
                'document_type_id' => (int) $dte['document_type_id'],
                'document_type_label' => (string) ($dte['document_type_label'] ?: Riverso_Billing_Totals::type_label((int) $dte['document_type_id'])),
                'folio' => (string) ($dte['folio'] ?? ''),
                'issue_date' => $issue_date,
                'due_date' => $due_date,
                'payment_conditions' => $payment_conditions,
                'closed_at' => (string) ($dte['created_at'] ?? ''),
                'created_by_name' => $created_by_name,
                'sale_state' => (string) ($draft['sale_state'] ?? 'VENTA: Concretada'),
                'facto_status' => $dte['facto_status'],
                'facto_error' => (string) ($dte['facto_error'] ?? ''),
                'taxbureau_validation_status' => $facto_header['taxbureau_validation_status'] ?? null,
                'payment_url' => (string) ($dte['payment_url'] ?? ''),
                'receiver' => $receiver,
                'lines' => $lines,
                'lines_source' => $lines_source,
                'refs' => $draft['refs'] ?? [],
                'totals' => [
                    'net_amount' => (float) ($dte['net_amount'] ?? 0),
                    'exempt_amount' => (float) ($draft['exempt_amount'] ?? 0),
                    'taxes_amount' => (float) ($dte['taxes_amount'] ?? 0),
                    'total_amount' => $total,
                ],
                'payments' => $payments,
                'paid_amount' => round($paid, 2),
                'unpaid_amount' => round(max(0, $total - $paid), 2),
            ],
            'warnings' => $warnings,
        ]);
    }

    /**
     * Usuarios para filtro "Ingresado por".
     *
     * @return array<int, array{id:int,name:string}>
     */
    private function list_search_users() {
        $users = get_users([
            'role__in' => ['administrator', 'riverso_admin', 'riverso_ventas', 'riverso_cotizador'],
            'fields' => ['ID', 'display_name', 'user_login'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 200,
        ]);
        $out = [];
        $seen = [];
        foreach ($users as $u) {
            $id = (int) $u->ID;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $name = trim((string) ($u->display_name ?: $u->user_login));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $name,
            ];
        }
        return $out;
    }

    public function ajax_payment_methods() {
        $this->authorize();
        $repo = class_exists('Riverso_Payment_Method_Repository')
            ? new Riverso_Payment_Method_Repository()
            : null;
        wp_send_json_success(['methods' => $repo ? $repo->list_all(true, true) : []]);
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
        $rule_mode = isset($_POST['rule_mode']) ? strtolower(trim((string) wp_unslash($_POST['rule_mode']))) : 'auto';
        if (!in_array($rule_mode, ['auto', 'std', 'manual', 'ref'], true)) {
            $rule_mode = 'auto';
        }
        $pack = $catalog->local_price_pack($pb, $qty, $p_override, $rule_mode);
        $offers = $catalog->family_offers_for_base($pb);

        $by_total = null;
        $target_total = isset($_POST['target_total']) ? (float) $_POST['target_total'] : 0.0;
        if ($target_total > 0) {
            $upp = isset($_POST['units_per_pack']) ? (float) $_POST['units_per_pack'] : 1.0;
            if ($upp <= 0) {
                $upp = 1.0;
            }
            $others = isset($_POST['others_units']) ? (float) $_POST['others_units'] : 0.0;
            if ($others < 0) {
                $others = 0.0;
            }
            $by_total = $catalog->qty_for_amount($pb, $target_total, [
                'rule_mode' => $rule_mode,
                'p_ref' => $p_override,
                'units_per_pack' => $upp,
                'others_units' => $others,
            ]);
            $by_total['target_total'] = $target_total;
        }

        wp_send_json_success([
            'pricing' => $pack,
            'family' => $offers,
            'unit_price' => $pack['unit_price'],
            'rule_total' => isset($pack['rule_total']) ? $pack['rule_total'] : null,
            'rule_adjusted' => !empty($pack['rule_adjusted']),
            'unitario0' => isset($pack['unitario0']) ? $pack['unitario0'] : null,
            'has_rule' => !empty($pack['has_rule']),
            'rule_codigo' => isset($pack['rule_codigo']) ? $pack['rule_codigo'] : null,
            'rule_nombre' => isset($pack['rule_nombre']) ? $pack['rule_nombre'] : null,
            'assigned_rule_codigo' => isset($pack['assigned_rule_codigo']) ? $pack['assigned_rule_codigo'] : null,
            'std_rule' => isset($pack['std_rule']) ? $pack['std_rule'] : null,
            'p_asignado' => isset($pack['p_asignado']) ? $pack['p_asignado'] : null,
            'producto_base_id' => $pb,
            'family_qty' => $qty,
            'p_ref' => $p_override,
            'rule_mode' => $rule_mode,
            'by_total' => $by_total,
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

    /**
     * Descuenta el stock del documento (cascada de ubicaciones). Nunca bloquea la emisión.
     *
     * @param int $draft_id
     * @return string Advertencia para el usuario, o vacío.
     */
    private function apply_sale_stock($draft_id) {
        $draft_id = absint($draft_id);
        if ($draft_id <= 0 || !class_exists('Riverso_Sale_Stock_Service')) {
            return '';
        }
        try {
            $result = Riverso_Sale_Stock_Service::get_instance()->apply_document($draft_id);
        } catch (Exception $error) {
            return 'No se pudo descontar el stock: ' . $error->getMessage();
        }
        $message = (string) ($result['message'] ?? '');
        return strpos($message, 'No se pudo') === 0 ? $message : '';
    }

    /**
     * Ajusta el estado de la cotización según sus documentos de venta:
     * borrador de documento → Aprobada; documento emitido o boleta cerrada → Facturada.
     *
     * @param int $quote_id
     */
    private function sync_linked_quote_status($quote_id) {
        $quote_id = absint($quote_id);
        if ($quote_id <= 0) {
            return;
        }
        $status_path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-status.php';
        $repo_path = RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-repository.php';
        if (!class_exists('Riverso_Quote_Status') && file_exists($status_path)) {
            require_once $status_path;
        }
        if (!class_exists('Riverso_Customer_Quote_Repository') && file_exists($repo_path)) {
            require_once $repo_path;
        }
        if (!class_exists('Riverso_Customer_Quote_Repository') || !method_exists('Riverso_Customer_Quote_Repository', 'sync_document_status')) {
            return;
        }
        try {
            (new Riverso_Customer_Quote_Repository())->sync_document_status($quote_id);
        } catch (Exception $error) {
            // La emisión ya quedó guardada; el listado de cotizaciones vuelve a sincronizar.
        }
    }

    /**
     * @param string $force_status
     * @return array{ok:bool,id?:int,message?:string}
     */
    private function save_draft_from_request($force_status = '') {
        $payload = [
            'id' => isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0,
            'status' => $force_status !== ''
                ? $force_status
                : (isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'draft'),
            'issue_date' => isset($_POST['issue_date']) ? sanitize_text_field(wp_unslash($_POST['issue_date'])) : '',
            'due_date' => isset($_POST['due_date']) ? sanitize_text_field(wp_unslash($_POST['due_date'])) : '',
            'payment_conditions' => isset($_POST['payment_conditions']) ? sanitize_text_field(wp_unslash($_POST['payment_conditions'])) : '0',
            'sale_state' => isset($_POST['sale_state']) ? sanitize_text_field(wp_unslash($_POST['sale_state'])) : 'VENTA: Concretada',
            'quote_id' => isset($_POST['quote_id']) ? absint($_POST['quote_id']) : 0,
            'document_type_id' => isset($_POST['document_type_id']) ? absint($_POST['document_type_id']) : 37,
            'customer_id' => isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0,
            'net_amount' => 0,
            'exempt_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'lines' => [],
            'refs' => [],
        ];
        foreach ([
            'receiver_rut',
            'receiver_legal_name',
            'receiver_activity',
            'receiver_activity_code',
            'receiver_address',
            'receiver_district',
            'receiver_city',
            'receiver_phone',
            'receiver_postal',
        ] as $key) {
            $payload[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        }
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
        return $this->drafts->save($payload);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{caja:array,method:array,cheque_numero:string,cheque_titular:string,cheque_banco:string}|WP_Error
     */
    private function validate_payment_context(array $input) {
        $caja_id = isset($input['caja_id']) ? absint($input['caja_id']) : 0;
        if ($caja_id <= 0 && isset($input['pay_caja_id'])) {
            $caja_id = absint($input['pay_caja_id']);
        }
        $method_id = isset($input['method_id']) ? absint($input['method_id']) : 0;
        if ($method_id <= 0 && isset($input['pay_method_id'])) {
            $method_id = absint($input['pay_method_id']);
        }
        $cash = $this->cash_repo();
        if (!$cash) {
            return new WP_Error('no_cash', 'Módulo de caja no disponible.');
        }
        if ($caja_id <= 0) {
            return new WP_Error('no_caja', 'Selecciona una caja abierta.');
        }
        $caja = $cash->get($caja_id);
        if (!$caja) {
            return new WP_Error('no_caja', 'Caja no encontrada.');
        }
        if (($caja['estado'] ?? '') !== 'abierta') {
            return new WP_Error('caja_cerrada', 'La caja no está abierta.');
        }
        if (!$cash->user_has_perm($caja_id, get_current_user_id(), 'pagar')) {
            return new WP_Error('no_perm', 'No tienes permiso para pagar en esta caja.');
        }
        if (!class_exists('Riverso_Payment_Method_Repository')) {
            return new WP_Error('no_method', 'Catálogo de métodos no disponible.');
        }
        $method = (new Riverso_Payment_Method_Repository())->get($method_id);
        if (!$method || empty($method['activo']) || empty($method['visible'])) {
            return new WP_Error('no_method', 'Selecciona un método de pago.');
        }
        $cheque_numero = isset($input['cheque_numero']) ? sanitize_text_field((string) $input['cheque_numero']) : '';
        $cheque_titular = isset($input['cheque_titular']) ? sanitize_text_field((string) $input['cheque_titular']) : '';
        $cheque_banco = isset($input['cheque_banco']) ? sanitize_text_field((string) $input['cheque_banco']) : '';
        if (!empty($method['requiere_cheque']) && ($cheque_numero === '' || $cheque_titular === '' || $cheque_banco === '')) {
            return new WP_Error('cheque', 'Completa número, titular y banco del cheque.');
        }
        return [
            'caja' => $caja,
            'method' => $method,
            'cheque_numero' => $cheque_numero,
            'cheque_titular' => $cheque_titular,
            'cheque_banco' => $cheque_banco,
        ];
    }

    /**
     * @return array{caja:array,method:array,cheque_numero:string,cheque_titular:string,cheque_banco:string}|WP_Error
     */
    private function validate_payment_context_from_request() {
        $caja_id = isset($_POST['pay_caja_id']) ? absint($_POST['pay_caja_id']) : 0;
        if ($caja_id <= 0) {
            $caja_id = isset($_POST['caja_id']) ? absint($_POST['caja_id']) : 0;
        }
        $method_id = isset($_POST['pay_method_id']) ? absint($_POST['pay_method_id']) : 0;
        if ($method_id <= 0) {
            $method_id = isset($_POST['method_id']) ? absint($_POST['method_id']) : 0;
        }
        return $this->validate_payment_context([
            'caja_id' => $caja_id,
            'method_id' => $method_id,
            'cheque_numero' => isset($_POST['cheque_numero']) ? wp_unslash($_POST['cheque_numero']) : '',
            'cheque_titular' => isset($_POST['cheque_titular']) ? wp_unslash($_POST['cheque_titular']) : '',
            'cheque_banco' => isset($_POST['cheque_banco']) ? wp_unslash($_POST['cheque_banco']) : '',
        ]);
    }

    /**
     * Valida y normaliza la lista de cobros del modal Atajo (antes de emitir).
     *
     * @param float $doc_total
     * @return array<int, array{ctx:array,amount_paid:float,change_amount:float,amount_applied:float,amount_due:float}>|WP_Error
     */
    private function validate_emit_payments_list($doc_total) {
        $raw = isset($_POST['payments']) ? wp_unslash($_POST['payments']) : '';
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $items = is_array($decoded) ? $decoded : [];
        } else {
            $items = is_array($raw) ? $raw : [];
        }
        if (!$items) {
            return new WP_Error('no_payments', 'Agrega al menos un cobro para marcar como pagado.');
        }
        $doc_total = round((float) $doc_total, 2);
        $remaining = $doc_total;
        $out = [];
        $count = count($items);
        $pos = 0;
        foreach ($items as $idx => $item) {
            $pos++;
            if (!is_array($item)) {
                return new WP_Error('bad_payment', 'Cobro #' . ((int) $idx + 1) . ' inválido.');
            }
            $ctx = $this->validate_payment_context([
                'caja_id' => $item['caja_id'] ?? 0,
                'method_id' => $item['method_id'] ?? 0,
                'cheque_numero' => $item['cheque_numero'] ?? '',
                'cheque_titular' => $item['cheque_titular'] ?? '',
                'cheque_banco' => $item['cheque_banco'] ?? '',
            ]);
            if (is_wp_error($ctx)) {
                return new WP_Error(
                    $ctx->get_error_code(),
                    'Cobro #' . ((int) $idx + 1) . ': ' . $ctx->get_error_message()
                );
            }
            $amount_paid = round((float) ($item['amount_paid'] ?? 0), 2);
            $change_amount = round((float) ($item['change_amount'] ?? 0), 2);
            if (empty($ctx['method']['permite_vuelto'])) {
                $change_amount = 0;
            }
            $applied = isset($item['amount_applied'])
                ? round((float) $item['amount_applied'], 2)
                : round(max(0, $amount_paid - $change_amount), 2);
            // El total en pantalla (bruto comercial) puede diferir en pesos del total FACTO por redondeo de neto/IVA.
            if ($pos === $count && $remaining > 0 && abs($applied - $remaining) > 0.009
                && abs($applied - $remaining) <= self::EMIT_ROUNDING_TOLERANCE) {
                if ($change_amount > 0) {
                    $change_amount = round(max(0, $amount_paid - $remaining), 2);
                } else {
                    $amount_paid = $remaining;
                }
                $applied = $remaining;
            }
            if ($applied <= 0) {
                return new WP_Error('bad_amount', 'Cobro #' . ((int) $idx + 1) . ': el monto aplicado debe ser mayor a 0.');
            }
            if ($applied - $remaining > 0.009) {
                return new WP_Error('overpay', 'Cobro #' . ((int) $idx + 1) . ': supera el saldo pendiente.');
            }
            if ($change_amount > 0 && empty($ctx['method']['permite_vuelto'])) {
                return new WP_Error('no_vuelto', 'Cobro #' . ((int) $idx + 1) . ': este método no admite vuelto.');
            }
            if ($change_amount > 0 && abs(($amount_paid - $change_amount) - $applied) > 0.009) {
                return new WP_Error('bad_vuelto', 'Cobro #' . ((int) $idx + 1) . ': vuelto inconsistente.');
            }
            $out[] = [
                'ctx' => $ctx,
                'amount_paid' => $amount_paid,
                'change_amount' => $change_amount,
                'amount_applied' => $applied,
                'amount_due' => $remaining,
            ];
            $remaining = round($remaining - $applied, 2);
        }
        if ($remaining > 0.009) {
            return new WP_Error(
                'underpay',
                'La suma de cobros no cubre el total. Saldo: $' . number_format($remaining, 0, ',', '.')
            );
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $payment
     * @return array{ok:bool,id?:int,message?:string,sync?:array}
     */
    private function register_and_sync_payment(array $payment) {
        $result = $this->drafts->add_document_payment($payment);
        if (empty($result['ok'])) {
            return $result;
        }
        $pay_id = (int) $result['id'];
        $applied = (float) ($payment['amount_applied'] ?? 0);
        $cash = $this->cash_repo();
        if ($applied > 0 && $cash && !empty($payment['caja_id'])) {
            $cash->register_payment_ingreso(
                (int) $payment['caja_id'],
                $applied,
                $pay_id,
                'Pago documento #' . ($payment['draft_id'] ?: $payment['dte_id'] ?: $pay_id)
            );
        }
        $sync = ['ok' => true, 'status' => 'pending', 'message' => 'Pendiente de sync.'];
        $dte_id = (int) ($payment['dte_id'] ?? 0);
        $dte = $dte_id > 0 ? $this->issued->get($dte_id) : null;
        if ($dte && !empty($dte['facto_document_id'])) {
            $sync = Riverso_Billing_Payment_Sync::sync($pay_id);
        }
        return [
            'ok' => true,
            'id' => $pay_id,
            'sync' => $sync,
        ];
    }

    /**
     * @return Riverso_Cash_Repository|null
     */
    private function cash_repo() {
        if (!class_exists('Riverso_Cash_Module')) {
            $cash_file = RIVERSO_POS_PLUGIN_DIR . 'sales/cash/class-cash-module.php';
            if (file_exists($cash_file)) {
                require_once $cash_file;
            }
        }
        return class_exists('Riverso_Cash_Module')
            ? Riverso_Cash_Module::get_instance()->repo()
            : null;
    }

    /**
     * @param array       $resp
     * @param int         $doc_id
     * @param string      $folio
     * @param string      $type_label
     * @param string      $to
     * @param string      $extra
     * @return array{ok:bool,message:string}
     */
    private function send_dte_email(array $resp, $doc_id, $folio, $type_label, $to, $extra) {
        $emails = [];
        foreach (array_merge(preg_split('/[,\s;]+/', (string) $to) ?: [], preg_split('/[,\s;]+/', (string) $extra) ?: []) as $addr) {
            $addr = sanitize_email(trim((string) $addr));
            if ($addr !== '' && is_email($addr)) {
                $emails[$addr] = $addr;
            }
        }
        if (!$emails) {
            return ['ok' => false, 'message' => 'Indica al menos un correo válido.'];
        }
        $electronic = isset($resp['electronic_document']) && is_array($resp['electronic_document'])
            ? $resp['electronic_document']
            : [];
        if ((empty($electronic['document_pdf']) || empty($electronic['document_xml'])) && $doc_id > 0) {
            $full = $this->facto_client()->get_document($doc_id);
            if (!is_wp_error($full) && !empty($full['electronic_document']) && is_array($full['electronic_document'])) {
                $electronic = $full['electronic_document'];
            }
        }
        $attachments = [];
        $tmp_dir = trailingslashit(wp_upload_dir()['basedir']) . 'riverso-tmp';
        wp_mkdir_p($tmp_dir);
        $safe_folio = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $folio) ?: 'dte';
        if (!empty($electronic['document_pdf'])) {
            $pdf = $tmp_dir . '/' . $safe_folio . '.pdf';
            file_put_contents($pdf, base64_decode((string) $electronic['document_pdf']));
            $attachments[] = $pdf;
        }
        if (!empty($electronic['document_xml'])) {
            $xml = $tmp_dir . '/' . $safe_folio . '.xml';
            file_put_contents($xml, base64_decode((string) $electronic['document_xml']));
            $attachments[] = $xml;
        }
        if (!$attachments) {
            return ['ok' => false, 'message' => 'FACTO no devolvió PDF/XML para adjuntar al correo.'];
        }
        $subject = $type_label . ' N° ' . $folio . ' – Riverso';
        $body = 'Adjunto el documento tributario ' . $type_label . ' N° ' . $folio . '.';
        $mail_error = '';
        $on_fail = static function ($error) use (&$mail_error) {
            if (is_wp_error($error)) {
                $mail_error = $error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $on_fail);
        $sent = wp_mail(array_values($emails), $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], $attachments);
        remove_action('wp_mail_failed', $on_fail);
        foreach ($attachments as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (class_exists('Riverso_Audit_Module')) {
            Riverso_Audit_Module::get_instance()->log(
                'billing.dte_email',
                'dte_issued',
                (int) $doc_id,
                [],
                ['to' => array_values($emails), 'folio' => $folio, 'ok' => $sent, 'error' => $mail_error],
                'Correo DTE folio ' . $folio
            );
        }
        if ($sent) {
            return ['ok' => true, 'message' => 'Correo enviado a ' . implode(', ', array_values($emails)) . '.'];
        }
        return [
            'ok' => false,
            'message' => $mail_error !== ''
                ? ('No se pudo enviar el correo: ' . $mail_error)
                : 'wp_mail no pudo enviar el correo.',
        ];
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
