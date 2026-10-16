<?php
/**
 * Asistente de Descuento: endpoints del editor de líneas y del Centro de Precios,
 * e historial al aceptar cotizaciones o emitir documentos.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-discount-assistant-service.php';

class Riverso_Discount_Assistant_Module {

    const NONCE = 'riverso_discount_assistant';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_da_context', [$this, 'ajax_context']);
        add_action('wp_ajax_riverso_da_set_link_attitude', [$this, 'ajax_set_link_attitude']);
        add_action('wp_ajax_riverso_da_set_domain_attitude', [$this, 'ajax_set_domain_attitude']);
        add_action('wp_ajax_riverso_da_add_competitor', [$this, 'ajax_add_competitor']);
        add_action('wp_ajax_riverso_da_domains', [$this, 'ajax_domains']);
        add_action('wp_ajax_riverso_da_settings_save', [$this, 'ajax_settings_save']);
        add_action('wp_ajax_riverso_da_history', [$this, 'ajax_history']);
        add_action('wp_ajax_riverso_da_internal_search', [$this, 'ajax_internal_search']);
        add_action('wp_ajax_riverso_da_confirm_preflight', [$this, 'ajax_confirm_preflight']);
        add_action('wp_ajax_riverso_da_confirm_match', [$this, 'ajax_confirm_match']);
        add_action('wp_ajax_riverso_da_set_url_enabled', [$this, 'ajax_set_url_enabled']);
        add_action('wp_ajax_riverso_da_set_hidden_use', [$this, 'ajax_set_hidden_use']);
        add_action('wp_ajax_riverso_da_context_batch', [$this, 'ajax_context_batch']);

        add_action('riverso_customer_quote_status_changed', [$this, 'on_quote_status_changed'], 10, 3);
        add_action('riverso_billing_draft_finalized', [$this, 'on_draft_finalized'], 10, 1);

        if (class_exists('Riverso_POS_Activator') && method_exists('Riverso_POS_Activator', 'ensure_discount_assistant_schema')) {
            Riverso_POS_Activator::ensure_discount_assistant_schema();
        }
    }

    /* ===== Permisos ===== */

    public static function can_view() {
        foreach (['riverso_view_quotes', 'riverso_create_quotes', 'riverso_edit_quotes', 'riverso_emit_dte',
            'riverso_view_prices', 'manage_woocommerce', 'manage_options'] as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    public static function can_manage_competencia() {
        return current_user_can('riverso_manage_competencia') || current_user_can('manage_options');
    }

    public static function can_manage_settings() {
        return current_user_can('riverso_manage_prices') || current_user_can('manage_options');
    }

    /** Configuración para el editor de líneas (cotizaciones y facturación). */
    public static function client_config() {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'settings' => Riverso_Discount_Assistant_Service::get_settings(),
            'canManageCompetencia' => self::can_manage_competencia(),
            'actions' => [
                'context' => 'riverso_da_context',
                'setLinkAttitude' => 'riverso_da_set_link_attitude',
                'setDomainAttitude' => 'riverso_da_set_domain_attitude',
                'addCompetitor' => 'riverso_da_add_competitor',
                'domains' => 'riverso_da_domains',
                'settingsSave' => 'riverso_da_settings_save',
                'history' => 'riverso_da_history',
                'internalSearch' => 'riverso_da_internal_search',
                'confirmPreflight' => 'riverso_da_confirm_preflight',
                'confirmMatch' => 'riverso_da_confirm_match',
                'setUrlEnabled' => 'riverso_da_set_url_enabled',
                'setHiddenUse' => 'riverso_da_set_hidden_use',
                'contextBatch' => 'riverso_da_context_batch',
            ],
            // «Abrir Competencia» y «Abrir en Buscador» (wp-admin), como en Procesar folios.
            'competenciaUrl' => admin_url('admin.php?page=riverso-pos-competencia'),
            'buscadorUrl' => admin_url('admin.php?page=riverso-pos-competencia&vista=buscador'),
        ];
    }

    private function guard($allowed) {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'Sesión expirada. Recarga la página.'], 403);
        }
        if (!$allowed) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function post($key) {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    /* ===== AJAX ===== */

    public function ajax_context() {
        $this->guard(self::can_view());
        $pb = absint($_POST['producto_base_id'] ?? 0);
        $settings = Riverso_Discount_Assistant_Service::get_settings();
        wp_send_json_success([
            'settings' => $settings,
            'producto' => Riverso_Discount_Assistant_Service::product_label($pb),
            'competidores' => Riverso_Discount_Assistant_Service::competitors_for_product($pb, $settings),
            'historial' => Riverso_Discount_Assistant_Service::history_for_product($pb),
            'canManageCompetencia' => self::can_manage_competencia(),
        ]);
    }

    /** «Aplicar descuentos sugeridos»: competencia de todos los productos de la tabla. */
    public function ajax_context_batch() {
        $this->guard(self::can_view());
        $raw = isset($_POST['producto_base_ids']) ? (string) wp_unslash($_POST['producto_base_ids']) : '';
        $ids = array_filter(array_map('absint', explode(',', $raw)));
        $settings = Riverso_Discount_Assistant_Service::get_settings();
        wp_send_json_success([
            'settings' => $settings,
            'competidores' => (object) Riverso_Discount_Assistant_Service::competitors_for_products($ids, $settings),
        ]);
    }

    /** Deshabilita (no cuenta para descuentos) o habilita una URL de competencia. */
    public function ajax_set_url_enabled() {
        $this->guard(self::can_manage_competencia());
        $result = Riverso_Discount_Assistant_Service::set_url_enabled(
            absint($_POST['producto_competencia_id'] ?? 0),
            !empty($_POST['habilitado']) && $_POST['habilitado'] !== '0',
            $this->post('motivo'),
            $this->post('hasta')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    /** Precio oculto por el competidor: «Usar siempre» o «Dejar de usar para siempre». */
    public function ajax_set_hidden_use() {
        $this->guard(self::can_manage_competencia());
        $comp_id = absint($_POST['producto_competencia_id'] ?? 0);
        $use = !empty($_POST['usar']) && $_POST['usar'] !== '0';
        $result = Riverso_Discount_Assistant_Service::set_hidden_price_use($comp_id, $use);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($use ? 'competencia.usar_oculto' : 'competencia.dejar_usar_oculto', 'competencia', $comp_id, [
                'actor_type' => 'user',
                'details' => ['origen' => 'asistente_descuento'],
            ]);
        }
        wp_send_json_success($result);
    }

    public function ajax_set_link_attitude() {
        $this->guard(self::can_manage_competencia());
        $result = Riverso_Discount_Assistant_Service::set_link_attitude(
            absint($_POST['producto_competencia_id'] ?? 0),
            $this->post('actitud')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_set_domain_attitude() {
        $this->guard(self::can_manage_competencia());
        $nombre = isset($_POST['nombre']) ? $this->post('nombre') : null;
        $result = Riverso_Discount_Assistant_Service::set_domain_attitude(
            $this->post('dominio'),
            $this->post('actitud'),
            $nombre
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_add_competitor() {
        $this->guard(self::can_manage_competencia());
        $result = Riverso_Discount_Assistant_Service::add_competitor([
            'producto_base_id' => absint($_POST['producto_base_id'] ?? 0),
            'url' => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
            'precio_total' => (float) str_replace(',', '.', $this->post('precio_total')),
            'unidad' => absint($_POST['unidad'] ?? 1),
            'tipo_match' => $this->post('tipo_match'),
            'actitud' => $this->post('actitud'),
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_domains() {
        $this->guard(self::can_view());
        wp_send_json_success([
            'dominios' => Riverso_Discount_Assistant_Service::list_domains(),
            'canManageCompetencia' => self::can_manage_competencia(),
        ]);
    }

    public function ajax_settings_save() {
        $this->guard(self::can_manage_settings());
        $input = [];
        foreach (array_keys(Riverso_Discount_Assistant_Service::default_settings()) as $key) {
            if (isset($_POST[$key])) {
                $input[$key] = $this->post($key);
            }
        }
        $result = Riverso_Discount_Assistant_Service::save_settings($input);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['settings' => $result, 'message' => 'Ajustes guardados.']);
    }

    public function ajax_history() {
        $this->guard(current_user_can('riverso_view_prices') || current_user_can('manage_options'));
        wp_send_json_success(Riverso_Discount_Assistant_Service::list_history([
            'page' => absint($_POST['page'] ?? 1),
            'search' => $this->post('search'),
            'origen' => $this->post('origen'),
            'evento' => $this->post('evento'),
        ]));
    }

    /* ===== Fuentes internas (mismo flujo que Procesar folios) ===== */

    private function match_service() {
        if (!class_exists('Riverso_Competencia_Match_Service')) {
            $path = RIVERSO_POS_PLUGIN_DIR . 'modules/competencia/class-competencia-match-service.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('Riverso_Competencia_Match_Service')) {
            wp_send_json_error(['message' => 'Módulo de competencia no disponible.']);
        }
    }

    /** Catálogo scrapeado (Sande, DIMAFI) e ingresos manuales. */
    public function ajax_internal_search() {
        $this->guard(self::can_manage_competencia());
        $this->match_service();
        $fuente = sanitize_key($this->post('fuente'));
        $modo = sanitize_key($this->post('coincidencia'));
        $result = Riverso_Competencia_Match_Service::search_competencia_productos([
            'search' => $this->post('search'),
            'campo' => 'todo',
            'coincidencia' => $modo === 'todas' ? 'todas' : 'alguna',
            'fuentes' => $fuente !== '' ? [$fuente] : [],
            'orden' => 'relevancia',
            'page' => max(1, absint($_POST['page'] ?? 1)),
            'per_page' => 10,
        ]);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_confirm_preflight() {
        $this->guard(self::can_manage_competencia());
        $this->match_service();
        $comp_id = absint($_POST['producto_competencia_id'] ?? 0);
        if ($comp_id <= 0) {
            wp_send_json_error(['message' => 'ID inválido.']);
        }
        $preflight = Riverso_Competencia_Match_Service::confirm_preflight($comp_id, absint($_POST['producto_base_id'] ?? 0));
        if (empty($preflight['ok'])) {
            wp_send_json_error(['message' => $preflight['message'] ?? 'No se pudo validar el vínculo.']);
        }
        wp_send_json_success($preflight);
    }

    /** Confirma el vínculo al SKU de la línea y deja la actitud elegida. */
    public function ajax_confirm_match() {
        $this->guard(self::can_manage_competencia());
        $this->match_service();
        $comp_id = absint($_POST['producto_competencia_id'] ?? 0);
        $pb = absint($_POST['producto_base_id'] ?? 0);
        $tipo = sanitize_key($this->post('tipo_match'));
        if ($comp_id <= 0 || $pb <= 0) {
            wp_send_json_error(['message' => 'IDs inválidos.']);
        }
        if (!Riverso_Competencia_Match_Service::is_allowed_tipo_match($tipo)) {
            wp_send_json_error(['message' => 'Debes seleccionar el tipo de match (Exacto, Exacto diferente U de envase, Similar u Otro).']);
        }
        $nota = isset($_POST['nota']) ? sanitize_textarea_field(wp_unslash($_POST['nota'])) : '';
        $match_id = Riverso_Competencia_Match_Service::confirm_match(
            $comp_id,
            $pb,
            get_current_user_id(),
            $nota !== '' ? $nota : 'Vinculado desde Asistente de Descuento',
            $tipo
        );
        if (is_wp_error($match_id)) {
            $data = $match_id->get_error_data();
            wp_send_json_error([
                'message' => $match_id->get_error_message(),
                'blockers' => is_array($data) ? ($data['blockers'] ?? []) : [],
            ]);
        }
        Riverso_Discount_Assistant_Service::set_link_attitude($comp_id, $this->post('actitud'));
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('competencia.buscador_link', 'competencia', $comp_id, [
                'actor_type' => 'user',
                'details' => [
                    'match_id' => $match_id,
                    'producto_base_id' => $pb,
                    'tipo_match' => $tipo,
                    'origen' => 'asistente_descuento',
                ],
            ]);
        }
        wp_send_json_success(['match_id' => $match_id]);
    }

    /* ===== Historial ===== */

    /**
     * @param array  $quote Cotización presentada después del cambio.
     * @param string $from  Estado anterior.
     * @param string $to    Estado nuevo.
     */
    public function on_quote_status_changed($quote, $from, $to) {
        if (!is_array($quote) || !class_exists('Riverso_Quote_Status') || $to !== Riverso_Quote_Status::LISTED) {
            return;
        }
        try {
            Riverso_Discount_Assistant_Service::record_quote_accepted($quote);
        } catch (\Throwable $error) {
            // El historial nunca debe impedir aprobar la cotización.
            error_log('[riverso-pos] historial de descuento (cotización): ' . $error->getMessage());
        }
    }

    /** @param array $draft Borrador presentado (emitido o cerrado sin SII). */
    public function on_draft_finalized($draft) {
        if (!is_array($draft)) {
            return;
        }
        try {
            Riverso_Discount_Assistant_Service::record_draft_finalized($draft);
        } catch (\Throwable $error) {
            error_log('[riverso-pos] historial de descuento (documento): ' . $error->getMessage());
        }
    }
}
