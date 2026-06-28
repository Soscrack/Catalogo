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
