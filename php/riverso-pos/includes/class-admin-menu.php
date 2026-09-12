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
        add_action('admin_footer', [$this, 'nav_label_script']);
    }
    
    /**
     * Registra los menús del plugin desde el registry unificado.
     */
    public function register_menus() {
        if (!class_exists('Riverso_POS_Nav_Registry')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/permissions/class-nav-registry.php';
        }

        $top_cap = Riverso_POS_Nav_Registry::get_admin_top_capability();

        add_menu_page(
            __('Riverso POS', 'riverso-pos'),
            __('Riverso POS', 'riverso-pos'),
            $top_cap,
            'riverso-pos',
            [$this, 'render_dashboard'],
            'dashicons-store',
            30
        );

        // Quitar el duplicado automático para conservar el orden de categorías.
        remove_submenu_page('riverso-pos', 'riverso-pos');

        $grouped = Riverso_POS_Nav_Registry::get_grouped('admin');

        foreach ($grouped as $group_id => $group) {
            $first = $group['items'][0] ?? null;
            $header_cap = $top_cap;
            if ($first) {
                $header_cap = $first['admin_capability']
                    ?? $first['capability']
                    ?? $top_cap;
            }

            // Cabecera de sección (no navegable)
            add_submenu_page(
                'riverso-pos',
                '',
                '<span class="riverso-nav-label">' . esc_html($group['label']) . '</span>',
                $header_cap,
                'riverso-pos-nav-' . $group_id,
                '__return_null'
            );

            foreach ($group['items'] as $item) {
                $page = $item['admin_page'] ?? null;
                $callback_name = $item['admin_callback'] ?? null;
                if (!$page || !$callback_name || !method_exists($this, $callback_name)) {
                    continue;
                }

                $cap = $item['admin_capability']
                    ?? $item['capability']
                    ?? $top_cap;

                $menu_label = $item['menu_label'] ?? $item['label'];

                add_submenu_page(
                    'riverso-pos',
                    $item['label'],
                    $menu_label,
                    $cap,
                    $page,
                    [$this, $callback_name]
                );
            }
        }
    }

    /**
     * Anula clicks en cabeceras de categoría del menú admin.
     */
    public function nav_label_script() {
        ?>
        <script>
        (function () {
            document.querySelectorAll('#adminmenu .riverso-nav-label').forEach(function (label) {
                var link = label.closest('a');
                if (!link) return;
                link.setAttribute('href', '#');
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                });
                var li = link.closest('li');
                if (li) {
                    li.classList.add('riverso-nav-label-item');
                }
            });
        })();
        </script>
        <?php
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
     * Renderiza la página de mapeo manual (Proveedor + Código → SKU).
     */
    public function render_manual_mapping() {
        if (!class_exists('Riverso_Manual_Mapping_Module')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'modules/codes/class-manual-mapping-module.php';
        }
        Riverso_Manual_Mapping_Module::get_instance();
        $this->render_page('manual-mapping');
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

    public function render_inbox() {
        if (!class_exists('Riverso_Messaging_Module')) {
            require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-messaging-module.php';
        }
        Riverso_Messaging_Module::get_instance();
        $this->render_page('inbox');
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
        $lookup = RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-lookup-service.php';
        if (file_exists($lookup)) {
            require_once $lookup;
        }
        $hist = RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-history-module.php';
        if (file_exists($hist)) {
            require_once $hist;
            Riverso_Price_History_Module::get_instance();
        }
        $this->render_page('price-history');
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

    public function render_tpv_export() {
        $this->render_page('tpv-export');
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
