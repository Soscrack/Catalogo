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
        
        // JS - Client de impresión
        wp_enqueue_script(
            'riverso-label-print-client',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/label-print-client.js',
            [],
            RIVERSO_POS_VERSION,
            true
        );

        // JS - Admin principal
        wp_enqueue_script(
            'riverso-pos-admin',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util', 'riverso-label-print-client'],
            RIVERSO_POS_VERSION,
            true
        );
        
        // Localización - incluyendo config de impresión
        $label_print_config = class_exists('Riverso_Label_Print_Module')
            ? Riverso_Label_Print_Module::get_agent_config()
            : [];

        wp_localize_script('riverso-pos-admin', 'riverso_pos', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('riverso_pos_nonce'),
            'label_print' => $label_print_config,
            'i18n' => [
                'confirm_delete' => __('¿Estás seguro de eliminar?', 'riverso-pos'),
                'saving' => __('Guardando...', 'riverso-pos'),
                'saved' => __('Guardado', 'riverso-pos'),
                'error' => __('Error', 'riverso-pos'),
                'loading' => __('Cargando...', 'riverso-pos'),
            ]
        ]);

        // Inicializar cliente de impresión con config
        wp_add_inline_script(
            'riverso-label-print-client',
            sprintf(
                'if (typeof RiversoLabelPrint !== "undefined") { RiversoLabelPrint.init(%s, %s); }',
                wp_json_encode($label_print_config['agentUrl'] ?? 'http://127.0.0.1:19284'),
                wp_json_encode($label_print_config['authToken'] ?? '')
            )
        );
    }
}

// Inicializar
new Riverso_POS_Assets();
