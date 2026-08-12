<?php
/**
 * Plugin Name: Riverso POS
 * Plugin URI: https://riverso.cl
 * Description: Sistema POS/mini-ERP integrado con WooCommerce para gestión de productos, facturas, inventario y tareas operativas.
 * Version: 1.5.31
 * Author: Riverso
 * Author URI: https://riverso.cl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: riverso-pos
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes del plugin
define('RIVERSO_POS_VERSION', '1.5.31');
define('RIVERSO_POS_PLUGIN_FILE', __FILE__);
define('RIVERSO_POS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RIVERSO_POS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RIVERSO_POS_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
final class Riverso_POS {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Módulos cargados
     */
    private $modules = [];
    
    /**
     * Obtiene la instancia única
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Verifica requisitos mínimos
     * @return bool
     */
    private function check_requirements() {
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo '<strong>Riverso POS</strong> requiere WooCommerce para funcionar.';
                echo '</p></div>';
            });
            return false;
        }
        return true;
    }
    
    /**
     * Incluye archivos necesarios
     */
    private function includes() {
        // Nuevo loader con autoload + aliases (Fase 1)
        require_once RIVERSO_POS_PLUGIN_DIR . 'loader.php';
        
        // Core
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-deactivator.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-admin-menu.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-assets.php';
        
        // Helpers
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
        
        // Aliases de compatibilidad: cargar clases movidas a core/
        // (permiten que código antiguo siga usando el path antiguo)
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/aliases-core.php';
    }
    
    /**
     * Inicializa hooks principales
     */
    private function init_hooks() {
        // Activación/Desactivación
        register_activation_hook(RIVERSO_POS_PLUGIN_FILE, ['Riverso_POS_Activator', 'activate']);
        register_deactivation_hook(RIVERSO_POS_PLUGIN_FILE, ['Riverso_POS_Deactivator', 'deactivate']);
        
        // Inicialización
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
        
        // Redirección de login según rol
        add_filter('login_redirect', [$this, 'login_redirect'], 10, 3);
        add_action('template_redirect', [$this, 'protect_internal_pages']);
        
        // HPOS Compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables', 
                    RIVERSO_POS_PLUGIN_FILE, 
                    true
                );
            }
        });
    }
    
    /**
     * Redirecciona al usuario según su rol después del login
     */
    public function login_redirect($redirect_to, $requested_redirect_to, $user) {
        if (!is_wp_error($user) && $user instanceof WP_User) {
            // Si es empleado interno, redirigir al portal (respetar deep link pedido)
            if (Riverso_POS_Permissions::is_employee($user->ID)) {
                $requested = $requested_redirect_to ?: $redirect_to;
                $path = is_string($requested) ? wp_parse_url($requested, PHP_URL_PATH) : '';
                if (is_string($path) && strpos($path, '/interno') === 0) {
                    return $requested;
                }
                return home_url('/interno/');
            }
        }
        return $redirect_to;
    }
    
    /**
     * Protege las páginas internas - solo empleados pueden acceder
     */
    public function protect_internal_pages() {
        // Verificar si estamos en /interno/
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/interno') !== false) {
            if (!is_user_logged_in()) {
                // Conservar /interno/catalog/ (u otra subruta) tras el login
                $path = wp_parse_url($uri, PHP_URL_PATH);
                if (!is_string($path) || strpos($path, '/interno') !== 0) {
                    $path = '/interno/';
                }
                $query = wp_parse_url($uri, PHP_URL_QUERY);
                $target = home_url($path);
                if (!empty($query)) {
                    $target .= (strpos($target, '?') === false ? '?' : '&') . $query;
                }
                wp_redirect(wp_login_url($target));
                exit;
            }
            if (!Riverso_POS_Permissions::is_employee()) {
                wp_redirect(home_url());
                exit;
            }
        }
    }
    
    /**
     * Ejecuta cuando todos los plugins están cargados
     */
    public function on_plugins_loaded() {
        // Verificar requisitos
        if (!$this->check_requirements()) {
            return;
        }
        
        // Cargar traducciones
        load_plugin_textdomain(
            'riverso-pos',
            false,
            dirname(RIVERSO_POS_PLUGIN_BASENAME) . '/languages'
        );
        
        // Cargar módulos
        $this->load_modules();
    }
    
    /**
     * Inicialización general
     */
    public function init() {
        // Registrar endpoints personalizados si es necesario
        do_action('riverso_pos_init');
        
        // Inicializar AJAX de permisos
        Riverso_POS_Permissions::init_ajax();
    }
    
    /**
     * Inicialización admin
     */
    public function admin_init() {
        // Verificar actualizaciones de base de datos
        $this->check_db_updates();
        
        do_action('riverso_pos_admin_init');
    }
    
    /**
     * Carga los módulos del plugin
     */
    private function load_modules() {
        // Bootstrap ERP: inventory / catalog / sync / OC / picking (sin duplicar modules/)
        $this->boot_erp_domains();

        $modules_dir = RIVERSO_POS_PLUGIN_DIR . 'modules/';
        
        // Lista de módulos a cargar (paths legacy modules/ + core overrides)
        $module_list = [
            'portal'    => ['file' => 'class-portal-module.php', 'class' => 'Riverso_Portal_Module'],
            'invoices'  => ['file' => 'class-invoice-module.php', 'class' => 'Riverso_Invoice_Module'],
            'tasks'     => [
                'file' => 'class-task-module.php',
                'class' => 'Riverso_Task_Module',
                'paths' => [
                    RIVERSO_POS_PLUGIN_DIR . 'core/tasks/class-task-module.php',
                    RIVERSO_POS_PLUGIN_DIR . 'modules/tasks/class-task-module.php',
                ],
            ],
            'warehouse' => ['file' => 'class-warehouse-module.php', 'class' => 'Riverso_Warehouse_Module'],
            'suppliers' => ['file' => 'class-supplier-module.php', 'class' => 'Riverso_POS_Supplier_Module'],
            'employees' => [
                'file' => 'class-employee-module.php',
                'class' => 'Riverso_POS_Employee_Module',
                'paths' => [
                    RIVERSO_POS_PLUGIN_DIR . 'core/employees/class-employee-module.php',
                    RIVERSO_POS_PLUGIN_DIR . 'modules/employees/class-employee-module.php',
                ],
            ],
            'quotes'    => ['file' => 'class-received-quote-module.php', 'class' => 'Riverso_POS_Received_Quote_Module'],
            'costs'     => ['file' => 'class-cost-history-module.php', 'class' => 'Riverso_Cost_History_Module'],
            'codes'     => ['file' => 'class-supplier-links-module.php', 'class' => 'Riverso_Supplier_Links_Module'],
            'barcodes'  => ['file' => 'class-barcode-module.php', 'class' => 'Riverso_Barcode_Module'],
            'catalogs'  => ['file' => 'class-catalog-module.php', 'class' => 'Riverso_Supplier_Catalogs_Module'],
            'products'  => ['file' => 'class-product-module.php', 'class' => 'Riverso_Product_Module'],
            'families'  => ['file' => 'class-family-module.php', 'class' => 'Riverso_Family_Module'],
            'tienda-local' => ['file' => 'class-tienda-local-module.php', 'class' => 'Riverso_Tienda_Local_Module'],
            'labels'    => ['file' => 'class-label-print-module.php', 'class' => 'Riverso_Label_Print_Module'],
            'matching'  => [
                'file' => 'class-matching-module.php',
                'class' => 'Riverso_Matching_Module',
                'paths' => [
                    RIVERSO_POS_PLUGIN_DIR . 'catalog/matching/class-matching-module.php',
                    RIVERSO_POS_PLUGIN_DIR . 'modules/matching/class-matching-module.php',
                ],
            ],
            'pricing'   => [
                'file' => 'class-pricing-module.php',
                'class' => 'Riverso_Pricing_Module',
                'paths' => [
                    RIVERSO_POS_PLUGIN_DIR . 'pricing/price_lists/class-pricing-module.php',
                    RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-pricing-module.php',
                ],
            ],
            'publish'   => ['file' => 'class-woo-publisher-module.php', 'class' => 'Riverso_Woo_Publisher_Module'],
            'packaging' => ['file' => 'class-packaging-module.php', 'class' => 'Riverso_Packaging_Module'],
            'import'    => ['file' => 'class-mamut-import-module.php', 'class' => 'Riverso_Mamut_Import_Module'],
            'customer-quotes' => ['file' => 'class-customer-quote-module.php', 'class' => 'Riverso_Customer_Quote_Module'],
            'pos'             => [
                'file' => 'class-pos-module.php',
                'class' => 'Riverso_POS_Module',
                'paths' => [
                    RIVERSO_POS_PLUGIN_DIR . 'sales/pos/class-pos-module.php',
                    RIVERSO_POS_PLUGIN_DIR . 'modules/pos/class-pos-module.php',
                ],
            ],
            'reports'         => ['file' => 'class-reports-module.php', 'class' => 'Riverso_Reports_Module'],
        ];
        
        foreach ($module_list as $module_name => $config) {
            $module_file = null;
            if (!empty($config['paths'])) {
                foreach ($config['paths'] as $candidate) {
                    if (file_exists($candidate)) {
                        $module_file = $candidate;
                        break;
                    }
                }
            } else {
                $module_file = $modules_dir . $module_name . '/' . $config['file'];
            }
            
            if ($module_file && file_exists($module_file)) {
                require_once $module_file;
                
                $class_name = $config['class'];
                
                if (class_exists($class_name)) {
                    if (method_exists($class_name, 'get_instance')) {
                        $module = $class_name::get_instance();
                    } else {
                        $module = new $class_name();
                    }
                    
                    if (method_exists($module, 'init')) {
                        $module->init();
                    }
                    $this->modules[$module_name] = $module;
                }
            }
        }
        
        do_action('riverso_pos_modules_loaded', $this->modules);
    }

    /**
     * Domains ERP nuevos (Fases 2–6) sin reemplazar modules/ legacy.
     */
    private function boot_erp_domains() {
        $boot = [
            RIVERSO_POS_PLUGIN_DIR . 'catalog/catalog-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'catalog/health/class-catalog-health-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'catalog/import/class-presentation-backfill-service.php',
            RIVERSO_POS_PLUGIN_DIR . 'inventory/inventory-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'inventory/stock/class-stock-service.php',
            RIVERSO_POS_PLUGIN_DIR . 'inventory/reservations/class-reservation-service.php',
            RIVERSO_POS_PLUGIN_DIR . 'inventory/stock_count/class-stock-count-service.php',
            RIVERSO_POS_PLUGIN_DIR . 'woocommerce/woocommerce-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'purchases/purchase_orders/class-purchase-order-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'purchases/reception/class-reception-service.php',
            RIVERSO_POS_PLUGIN_DIR . 'logistics/picking/class-picking-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'settings/class-settings-module.php',
            RIVERSO_POS_PLUGIN_DIR . 'sales/customers/class-customer-view.php',
        ];

        foreach ($boot as $file) {
            if (!file_exists($file)) {
                continue;
            }
            require_once $file;
        }

        // Inits explícitos (evita double-init de publish/import)
        if (class_exists('Riverso_Catalog_Module') && method_exists('Riverso_Catalog_Module', 'init')) {
            Riverso_Catalog_Module::init();
        }
        if (class_exists('Riverso_Catalog_Health_Module')) {
            Riverso_Catalog_Health_Module::get_instance()->init();
        }
        if (class_exists('Riverso_Presentation_Backfill_Service')) {
            Riverso_Presentation_Backfill_Service::register_cli();
        }
        if (class_exists('Riverso_Inventory_Module') && method_exists('Riverso_Inventory_Module', 'init')) {
            Riverso_Inventory_Module::init();
        }
        if (class_exists('Riverso_WooCommerce_Module')) {
            Riverso_WooCommerce_Module::get_instance()->init();
        }
        if (class_exists('Riverso_Purchase_Order_Module')) {
            $po = method_exists('Riverso_Purchase_Order_Module', 'get_instance')
                ? Riverso_Purchase_Order_Module::get_instance()
                : new Riverso_Purchase_Order_Module();
            if (method_exists($po, 'init')) {
                $po->init();
            }
        }
        if (class_exists('Riverso_Picking_Module')) {
            $pk = method_exists('Riverso_Picking_Module', 'get_instance')
                ? Riverso_Picking_Module::get_instance()
                : new Riverso_Picking_Module();
            if (method_exists($pk, 'init')) {
                $pk->init();
            }
        }

        // Auth audit en runtime (además de activator)
        if (class_exists('Riverso_Auth_Service')) {
            Riverso_Auth_Service::get_instance()->init();
        }
    }
    
    /**
     * Verifica si hay actualizaciones de BD pendientes
     */
    private function check_db_updates() {
        $current_version = get_option('riverso_pos_db_version', '0');
        
        if (version_compare($current_version, RIVERSO_POS_VERSION, '<')) {
            Riverso_POS_Activator::update_database();
        }
    }
    
    /**
     * Obtiene un módulo específico
     */
    public function get_module($name) {
        return $this->modules[$name] ?? null;
    }
    
    /**
     * Obtiene todos los módulos
     */
    public function get_modules() {
        return $this->modules;
    }
    
    /**
     * Prevenir clonación
     */
    private function __clone() {}
    
    /**
     * Prevenir deserialización
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Función principal para acceder al plugin
 */
function riverso_pos() {
    return Riverso_POS::instance();
}

// Iniciar el plugin
riverso_pos();
