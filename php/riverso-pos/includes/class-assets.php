<?php
/**
 * Gestión de assets (CSS/JS)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Assets {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }
    
    /**
     * Encola assets en el admin
     */
    public function enqueue_admin_assets($hook) {
        // Solo en páginas del plugin
        if (strpos($hook, 'riverso-pos') === false) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'riverso-pos-admin',
            RIVERSO_POS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            RIVERSO_POS_VERSION
        );
        
        $this->enqueue_label_print_client('riverso_label_print_config');
        
        // JS
        wp_enqueue_script(
            'riverso-pos-admin',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util', 'riverso-label-print-client'],
            RIVERSO_POS_VERSION,
            true
        );
        
        wp_localize_script('riverso-pos-admin', 'riverso_pos', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'i18n' => [
                'confirm_delete' => __('¿Estás seguro de eliminar?', 'riverso-pos'),
                'saving' => __('Guardando...', 'riverso-pos'),
                'saved' => __('Guardado', 'riverso-pos'),
                'error' => __('Error', 'riverso-pos'),
                'loading' => __('Cargando...', 'riverso-pos'),
            ]
        ]);

        // Editor de familias (Categorías/Familias y productos; Precios lo encola en enqueue_price_history_assets)
        if (strpos($hook, 'riverso-pos-categories') !== false
            || strpos($hook, 'riverso-pos-products') !== false
        ) {
            $this->enqueue_family_editor_assets();
        }

        // Historial de costos: Chart.js + explorador
        if (strpos($hook, 'riverso-pos-costs') !== false) {
            $this->enqueue_cost_history_assets();
        }

        if (strpos($hook, 'riverso-pos-manual-mapping') !== false) {
            $this->enqueue_manual_mapping_assets();
        }

        if (strpos($hook, 'riverso-pos-pricing') !== false) {
            $this->enqueue_price_history_assets();
        }
    }

    /**
     * Encola Chart.js + cost-history.js (admin y portal)
     */
    private function enqueue_cost_history_assets() {
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            [],
            '3.9.1',
            true
        );

        $js_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/cost-history.js';
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : RIVERSO_POS_VERSION;
        wp_enqueue_script(
            'riverso-cost-history',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/cost-history.js',
            ['jquery', 'chartjs'],
            $js_ver,
            true
        );

        $manual_map_url = current_user_can('riverso_manage_codes')
            ? (get_query_var('riverso_portal')
                ? home_url('/interno/manual-mapping/')
                : admin_url('admin.php?page=riverso-pos-manual-mapping'))
            : '';

        wp_localize_script('riverso-cost-history', 'riversoCostHistory', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'can_manage' => current_user_can('riverso_manage_costs'),
            'can_manage_codes' => current_user_can('riverso_manage_codes'),
            'manual_mapping_url' => $manual_map_url,
        ]);
    }

    /**
     * Encola JS de mapeo manual (admin y portal)
     */
    private function enqueue_manual_mapping_assets() {
        $js_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/manual-mapping.js';
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : RIVERSO_POS_VERSION;
        wp_enqueue_script(
            'riverso-manual-mapping',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/manual-mapping.js',
            ['jquery'],
            $js_ver,
            true
        );

        $is_portal = (bool) get_query_var('riverso_portal');
        wp_localize_script('riverso-manual-mapping', 'riversoManualMapping', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'cost_history_url' => $is_portal
                ? home_url('/interno/cost-history/')
                : admin_url('admin.php?page=riverso-pos-costs'),
            'can_manage' => current_user_can('riverso_manage_codes'),
        ]);
    }

    /**
     * Encola family-editor.js (Categorías, productos, Precios).
     */
    private function enqueue_family_editor_assets() {
        $family_editor_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/family-editor.js';
        $family_editor_ver = file_exists($family_editor_path)
            ? (string) filemtime($family_editor_path)
            : RIVERSO_POS_VERSION;
        wp_enqueue_script(
            'riverso-family-editor',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/family-editor.js',
            ['jquery'],
            $family_editor_ver,
            true
        );
        wp_localize_script('riverso-family-editor', 'riversoFamilyEditor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'canManage' => current_user_can('riverso_manage_families'),
        ]);
    }

    /**
     * Encola Chart.js + price-history.js (Centro de Precios)
     */
    private function enqueue_price_history_assets() {
        $css_path = RIVERSO_POS_PLUGIN_DIR . 'assets/css/price-history.css';
        $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : RIVERSO_POS_VERSION;
        wp_enqueue_style(
            'riverso-price-history',
            RIVERSO_POS_PLUGIN_URL . 'assets/css/price-history.css',
            [],
            $css_ver
        );

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            [],
            '3.9.1',
            true
        );

        $this->enqueue_family_editor_assets();

        $js_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/price-history.js';
        $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : RIVERSO_POS_VERSION;
        wp_enqueue_script(
            'riverso-price-history',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/price-history.js',
            ['jquery', 'chartjs', 'riverso-family-editor'],
            $js_ver,
            true
        );

        wp_localize_script('riverso-price-history', 'riversoPriceHistory', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'can_manage' => current_user_can('riverso_manage_prices'),
            'can_approve' => current_user_can('riverso_approve_prices'),
            'can_create_local' => current_user_can('riverso_manage_products')
                && (current_user_can('riverso_manage_codes') || current_user_can('riverso_process_invoices')),
            'can_link_sku' => current_user_can('riverso_manage_codes')
                || current_user_can('riverso_process_invoices'),
            'can_answer_family' => current_user_can('riverso_manage_products')
                || current_user_can('riverso_manage_families'),
            'can_manage_families' => current_user_can('riverso_manage_families'),
            'can_manage_competencia' => current_user_can('riverso_manage_competencia'),
            'can_view_barcodes' => current_user_can('riverso_view_products'),
            'can_assign_barcodes' => current_user_can('riverso_manage_products'),
            'products_admin_url' => admin_url('admin.php?page=riverso-pos-products'),
            'admin_url' => admin_url(),
        ]);
    }

    /**
     * Encola assets en el frontend (portal /interno)
     */
    public function enqueue_frontend_assets() {
        // Portal usa rewrite /interno (no es is_page())
        $portal_page = get_query_var('riverso_portal');
        if (empty($portal_page) && !is_page() && !is_singular()) {
            return;
        }

        if (!empty($portal_page)) {
            wp_enqueue_script('jquery');
            // El cliente de impresión va en footer; sin esto WordPress mueve jQuery
            // al footer y los scripts inline del portal fallan (pestañas muertas).
            if (function_exists('wp_scripts')) {
                wp_scripts()->add_data('jquery', 'group', 0);
                wp_scripts()->add_data('jquery-core', 'group', 0);
                wp_scripts()->add_data('jquery-migrate', 'group', 0);
            }
            wp_enqueue_style('dashicons');

            if ($portal_page === 'categories') {
                $family_editor_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/family-editor.js';
                $family_editor_ver = file_exists($family_editor_path)
                    ? (string) filemtime($family_editor_path)
                    : RIVERSO_POS_VERSION;
                wp_enqueue_script(
                    'riverso-family-editor',
                    RIVERSO_POS_PLUGIN_URL . 'assets/js/family-editor.js',
                    ['jquery'],
                    $family_editor_ver,
                    true
                );
                wp_localize_script('riverso-family-editor', 'riversoFamilyEditor', [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('riverso_pos_nonce'),
                    'canManage' => current_user_can('riverso_manage_families'),
                ]);
            }

            if ($portal_page === 'cost-history') {
                $this->enqueue_cost_history_assets();
            }

            if ($portal_page === 'manual-mapping') {
                $this->enqueue_manual_mapping_assets();
            }
        }

        $this->enqueue_label_print_client('riverso_label_print_config');
    }

    /**
     * Encola el cliente JS de impresión de etiquetas
     */
    private function enqueue_label_print_client($config_var = 'riverso_label_print_config') {
        wp_enqueue_script(
            'riverso-label-print-client',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/label-print-client.js',
            [],
            RIVERSO_POS_VERSION,
            true
        );

        $agent_url = get_option('riverso_label_print_agent_url', 'http://127.0.0.1:19284');
        $agent_token = get_option('riverso_label_print_auth_token', '');

        wp_localize_script('riverso-label-print-client', $config_var, [
            'agentUrl' => $agent_url,
            'authToken' => $agent_token,
        ]);
    }
}

// Inicializar
new Riverso_POS_Assets();
