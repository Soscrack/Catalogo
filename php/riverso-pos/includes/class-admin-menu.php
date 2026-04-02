<?php
/**
 * Menú de administración del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_POS_Admin_Menu {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menus']);
    }
    
    /**
     * Registra los menús del plugin
     */
    public function register_menus() {
        // Menú principal
        add_menu_page(
            __('Riverso POS', 'riverso-pos'),
            __('Riverso POS', 'riverso-pos'),
            'riverso_view_products',
            'riverso-pos',
            [$this, 'render_dashboard'],
            'dashicons-store',
            30
        );
        
        // Dashboard (mismo que principal)
        add_submenu_page(
            'riverso-pos',
            __('Dashboard', 'riverso-pos'),
            __('Dashboard', 'riverso-pos'),
            'riverso_view_products',
            'riverso-pos',
            [$this, 'render_dashboard']
        );
        
        // Facturas
        add_submenu_page(
            'riverso-pos',
            __('Facturas', 'riverso-pos'),
            __('Facturas', 'riverso-pos'),
            'riverso_view_invoices',
            'riverso-pos-invoices',
            [$this, 'render_invoices']
        );
        
        // Códigos
        add_submenu_page(
            'riverso-pos',
            __('Códigos', 'riverso-pos'),
            __('Códigos', 'riverso-pos'),
            'riverso_manage_codes',
            'riverso-pos-codes',
            [$this, 'render_codes']
        );
        
        // Tareas
        add_submenu_page(
            'riverso-pos',
            __('Tareas', 'riverso-pos'),
            __('Tareas', 'riverso-pos'),
            'riverso_view_tasks',
            'riverso-pos-tasks',
            [$this, 'render_tasks']
        );
        
        // Ubicaciones
        add_submenu_page(
            'riverso-pos',
            __('Bodega', 'riverso-pos'),
            __('Bodega', 'riverso-pos'),
            'riverso_view_stock',
            'riverso-pos-warehouse',
            [$this, 'render_warehouse']
        );
        
        // Configuración
        add_submenu_page(
            'riverso-pos',
            __('Configuración', 'riverso-pos'),
            __('Configuración', 'riverso-pos'),
            'riverso_manage_settings',
            'riverso-pos-settings',
            [$this, 'render_settings']
        );
    }
    
    /**
     * Renderiza el dashboard
     */
    public function render_dashboard() {
        $this->render_page('dashboard');
    }
    
    /**
     * Renderiza la página de facturas
     */
    public function render_invoices() {
        $this->render_page('invoices');
    }
    
    /**
     * Renderiza la página de códigos
     */
    public function render_codes() {
        $this->render_page('codes');
    }
    
    /**
     * Renderiza la página de tareas
     */
    public function render_tasks() {
        // Cargar módulo de tareas para constantes
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/tasks/class-task-module.php';
        $this->render_page('tasks');
    }
    
    /**
     * Renderiza la página de bodega
     */
    public function render_warehouse() {
        $this->render_page('warehouse');
    }
    
    /**
     * Renderiza la página de configuración
     */
    public function render_settings() {
        $this->render_page('settings');
    }
    
    /**
     * Renderiza una página del plugin
     */
    private function render_page($page) {
        $template = RIVERSO_POS_PLUGIN_DIR . "templates/{$page}.php";
        
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html(ucfirst($page)) . '</h1>';
            echo '<p>' . __('Página en desarrollo...', 'riverso-pos') . '</p>';
            echo '</div>';
        }
    }
}

// Inicializar
new Riverso_POS_Admin_Menu();
