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
        
        // Recepción de Mercadería
        add_submenu_page(
            'riverso-pos',
            __('Recepción', 'riverso-pos'),
            __('Recepción', 'riverso-pos'),
            'riverso_receive_items',
            'riverso-pos-reception',
            [$this, 'render_reception']
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

        // Catálogo Canónico
        add_submenu_page(
            'riverso-pos',
            __('Catálogo Canónico', 'riverso-pos'),
            __('Catálogo Canónico', 'riverso-pos'),
            'riverso_manage_codes',
            'riverso-pos-domain',
            [$this, 'render_domain']
        );

        add_submenu_page(
            'riverso-pos',
            __('Salud del catálogo', 'riverso-pos'),
            __('Salud del catálogo', 'riverso-pos'),
            'riverso_manage_codes',
            'riverso-pos-catalog-health',
            [$this, 'render_catalog_health']
        );

        // Productos
        add_submenu_page(
            'riverso-pos',
            __('Productos', 'riverso-pos'),
            __('Productos', 'riverso-pos'),
            'riverso_view_products',
            'riverso-pos-products',
            [$this, 'render_products']
        );
        
        // Categorías y Familias
        add_submenu_page(
            'riverso-pos',
            __('Categorías y Familias', 'riverso-pos'),
            __('Categorías y Familias', 'riverso-pos'),
            'riverso_manage_products',
            'riverso-pos-categories',
            [$this, 'render_categories']
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
        
        // Proveedores
        add_submenu_page(
            'riverso-pos',
            __('Proveedores', 'riverso-pos'),
            __('Proveedores', 'riverso-pos'),
            'riverso_view_suppliers',
            'riverso-pos-suppliers',
            [$this, 'render_suppliers']
        );
        
        // Cotizaciones Recibidas
        add_submenu_page(
            'riverso-pos',
            __('Cotizaciones Recibidas', 'riverso-pos'),
            __('Cotizaciones Recibidas', 'riverso-pos'),
            'riverso_view_invoices',
            'riverso-pos-received-quotes',
            [$this, 'render_received_quotes']
        );
        
        // Auditoría
        add_submenu_page(
            'riverso-pos',
            __('Auditoría', 'riverso-pos'),
            __('Auditoría', 'riverso-pos'),
            'riverso_view_audit',
            'riverso-pos-audit',
            [$this, 'render_audit']
        );
        
        // Empleados
        add_submenu_page(
            'riverso-pos',
            __('Empleados', 'riverso-pos'),
            __('Empleados', 'riverso-pos'),
            'riverso_manage_users',
            'riverso-pos-employees',
            [$this, 'render_employees']
        );
        
        // Historial de Costos
        add_submenu_page(
            'riverso-pos',
            __('Historial de Costos', 'riverso-pos'),
            __('Historial de Costos', 'riverso-pos'),
            'riverso_view_costs',
            'riverso-pos-costs',
            [$this, 'render_costs']
        );

        // Precios
        add_submenu_page(
            'riverso-pos',
            __('Precios', 'riverso-pos'),
            __('💲 Precios', 'riverso-pos'),
            'riverso_view_prices',
            'riverso-pos-pricing',
            [$this, 'render_pricing']
        );

        // Reglas de Precio
        add_submenu_page(
            'riverso-pos',
            __('Reglas de Precio', 'riverso-pos'),
            __('Reglas de Precio', 'riverso-pos'),
            'riverso_manage_prices',
            'riverso-pos-price-rules',
            [$this, 'render_price_rules']
        );

        // Publicación WooCommerce
        add_submenu_page(
            'riverso-pos',
            __('Publicación', 'riverso-pos'),
            __('Publicación', 'riverso-pos'),
            'riverso_review_products',
            'riverso-pos-publish',
            [$this, 'render_publish']
        );

        // Manufactura [WIP]
        add_submenu_page(
            'riverso-pos',
            __('Manufactura', 'riverso-pos'),
            __('Manufactura [WIP]', 'riverso-pos'),
            'riverso_manage_manufacturing',
            'riverso-pos-manufacturing',
            [$this, 'render_manufacturing']
        );

        // Embolsado (redirección a Manufactura)
        add_submenu_page(
            'riverso-pos',
            __('Embolsado', 'riverso-pos'),
            __('Embolsado', 'riverso-pos'),
            'riverso_manage_packaging',
            'riverso-pos-packaging',
            [$this, 'render_packaging']
        );
        
        // Códigos de Barra
        add_submenu_page(
            'riverso-pos',
            __('Códigos de Barra', 'riverso-pos'),
            __('Códigos de Barra', 'riverso-pos'),
            'riverso_manage_products',
            'riverso-pos-barcodes',
            [$this, 'render_barcodes']
        );

        // Impresiones
        add_submenu_page(
            'riverso-pos',
            __('Impresiones', 'riverso-pos'),
            __('Impresiones', 'riverso-pos'),
            'riverso_view_print_orders',
            'riverso-pos-print-orders',
            [$this, 'render_print_orders']
        );

        // Tienda Local
        add_submenu_page(
            'riverso-pos',
            __('Tienda Local', 'riverso-pos'),
            __('Tienda Local', 'riverso-pos'),
            'riverso_view_products',
            'riverso-pos-tienda-local',
            [$this, 'render_tienda_local']
        );
        
        // Cotizaciones a Clientes
        add_submenu_page(
            'riverso-pos',
            __('Cotizaciones a Clientes', 'riverso-pos'),
            __('Cotizaciones a Clientes', 'riverso-pos'),
            'riverso_view_quotes',
            'riverso-pos-customer-quotes',
            [$this, 'render_customer_quotes']
        );
        
        // POS (Punto de Venta)
        add_submenu_page(
            'riverso-pos',
            __('Punto de Venta', 'riverso-pos'),
            __('🛒 Punto de Venta', 'riverso-pos'),
            'riverso_use_pos',
            'riverso-pos-pos',
            [$this, 'render_pos']
        );
        
        // Reportes
        add_submenu_page(
            'riverso-pos',
            __('Reportes', 'riverso-pos'),
            __('📊 Reportes', 'riverso-pos'),
            'riverso_view_reports',
            'riverso-pos-reports',
            [$this, 'render_reports']
        );
        
        // Export FACTO
        add_submenu_page(
            'riverso-pos',
            __('Competencia', 'riverso-pos'),
            __('Competencia', 'riverso-pos'),
            'riverso_manage_competencia',
            'riverso-pos-competencia',
            [$this, 'render_competencia']
        );

        // Configuración
        add_submenu_page(
            'riverso-pos',
            __('Export FACTO', 'riverso-pos'),
            __('Export FACTO', 'riverso-pos'),
            'riverso_export_facto',
            'riverso-pos-facto-export',
            [$this, 'render_facto_export']
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
        
        // Permisos - visible para admin WP o empleados con riverso_manage_permissions
        add_submenu_page(
            'riverso-pos',
            __('Permisos', 'riverso-pos'),
            __('🔐 Permisos', 'riverso-pos'),
            'riverso_manage_permissions',
            'riverso-pos-permissions',
            [$this, 'render_permissions']
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
     * Renderiza la página de recepción
     */
    public function render_reception() {
        $this->render_page('reception');
    }
    
    /**
     * Renderiza la página de códigos
     */
    public function render_codes() {
        $this->render_page('codes');
    }

    /**
     * Renderiza la página de catálogo canónico.
     */
    public function render_domain() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/codes/class-supplier-links-module.php';
        $this->render_page('catalog-domain');
    }

    /**
     * Renderiza la bandeja de calidad del catálogo.
     */
    public function render_catalog_health() {
        $this->render_page('catalog-health');
    }

    /**
     * Renderiza la página de gestión de productos.
     */
    public function render_products() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';
        $this->render_page('products');
    }
    
    /**
     * Renderiza la página de categorías y familias.
     */
    public function render_categories() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-family-module.php';
        $this->render_page('categories-families');
    }
    
    /**
     * Renderiza la página de tareas
     */
    public function render_tasks() {
        // Preferir core/tasks (canónico). Evitar redeclarar la clase si ya cargó.
        if (!class_exists('Riverso_Task_Module')) {
            $core = RIVERSO_POS_PLUGIN_DIR . 'core/tasks/class-task-module.php';
            $legacy = RIVERSO_POS_PLUGIN_DIR . 'modules/tasks/class-task-module.php';
            if (file_exists($core)) {
                require_once $core;
            } elseif (file_exists($legacy)) {
                require_once $legacy;
            }
        }
        $this->render_page('tasks');
    }
    
    /**
     * Renderiza la página de bodega
     */
    public function render_warehouse() {
        $this->render_page('warehouse');
    }
    
    /**
     * Renderiza la página de proveedores
     */
    public function render_suppliers() {
        $this->render_page('suppliers');
    }
    
    /**
     * Renderiza la página de cotizaciones recibidas
     */
    public function render_received_quotes() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/quotes/class-received-quote-module.php';
        $this->render_page('received-quotes');
    }
    
    /**
     * Renderiza la página de auditoría
     */
    public function render_audit() {
        $this->render_page('audit');
    }
    
    /**
     * Renderiza la página de empleados
     */
    public function render_employees() {
        $this->render_page('employees');
    }
    
    /**
     * Renderiza la página de costos
     */
    public function render_costs() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-history-module.php';
        $lookup = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
        if (file_exists($lookup)) {
            require_once $lookup;
        }
        Riverso_Cost_History_Module::get_instance();
        $this->render_page('cost-history');
    }
    
    /**
     * Renderiza la página de códigos de barra
     */
    public function render_barcodes() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-barcode-module.php';
        $this->render_page('barcodes');
    }

    /**
     * Renderiza la página de órdenes de impresión
     */
    public function render_print_orders() {
        if (!class_exists('Riverso_Print_Order_Module')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/print-orders/class-print-order-module.php';
        }
        $this->render_page('print-orders');
    }

    /**
     * Renderiza la página de búsqueda de tienda local.
     */
    public function render_tienda_local() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/tienda-local/class-tienda-local-module.php';
        $this->render_page('tienda-local');
    }

    /**
     * Renderiza la página de precios
     */
    public function render_pricing() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-pricing-module.php';
        $this->render_page('pricing');
    }

    /**
     * Renderiza la página de reglas de precio
     */
    public function render_price_rules() {
        $engine = RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-rule-engine.php';
        if (file_exists($engine)) {
            require_once $engine;
        }
        $this->render_page('price-rules');
    }

    /**
     * Renderiza la página de publicación controlada.
     */
    public function render_publish() {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/publish/class-woo-publisher-module.php';
        $this->render_page('publish');
    }

    /**
     * Renderiza la página de Manufactura [WIP]
     */
    public function render_manufacturing() {
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'riverso-pos'));
        }
        $this->render_page('manufacturing');
    }

    /**
     * Redirección legacy Embolsado → Manufactura
     */
    public function render_packaging() {
        if (!current_user_can('riverso_manage_packaging') && !current_user_can('riverso_manage_manufacturing')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'riverso-pos'));
        }
        wp_safe_redirect(admin_url('admin.php?page=riverso-pos-manufacturing'));
        exit;
    }
    
    /**
     * Renderiza la página de configuración
     */
    public function render_settings() {
        $this->render_page('settings');
    }

    public function render_facto_export() {
        $this->render_page('facto-export');
    }

    public function render_competencia() {
        if (!current_user_can('riverso_manage_competencia')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'riverso-pos'));
        }
        $this->render_page('competencia-matching');
    }
    
    /**
     * Renderiza la página de cotizaciones a clientes
     */
    public function render_customer_quotes() {
        $this->render_page('customer-quotes');
    }
    
    /**
     * Renderiza la página de POS
     */
    public function render_pos() {
        $this->render_page('pos');
    }
    
    /**
     * Renderiza la página de reportes
     */
    public function render_reports() {
        $this->render_page('reports');
    }
    
    /**
     * Renderiza la página de permisos
     */
    public function render_permissions() {
        $this->render_page('permissions');
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
