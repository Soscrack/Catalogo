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
        
        // JS
        wp_enqueue_script(
            'riverso-pos-admin',
            RIVERSO_POS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            RIVERSO_POS_VERSION,
            true
        );
        
        // Localización
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
}

// Inicializar
new Riverso_POS_Assets();
