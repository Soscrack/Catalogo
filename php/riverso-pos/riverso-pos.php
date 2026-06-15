<?php
/**
 * Plugin Name: Riverso POS
 * Plugin URI: https://riverso.cl
 * Description: Sistema POS/mini-ERP integrado con WooCommerce para gestión de productos, facturas, inventario y tareas operativas.
 * Version: 1.1.1
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
define('RIVERSO_POS_VERSION', '1.1.1');
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
        // Core
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-loader.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-deactivator.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-admin-menu.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-permissions.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit-module.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-assets.php';
        
        // Helpers
        require_once RIVERSO_POS_PLUGIN_DIR . 'includes/helpers.php';
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
            // Si es empleado interno, redirigir al portal
            if (Riverso_POS_Permissions::is_employee($user->ID)) {
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
                wp_redirect(wp_login_url(home_url('/interno/')));
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
        $modules_dir = RIVERSO_POS_PLUGIN_DIR . 'modules/';
        
        // Lista de módulos a cargar
        $module_list = [
            'portal'    => ['file' => 'class-portal-module.php', 'class' => 'Riverso_Portal_Module'],
            'invoices'  => ['file' => 'class-invoice-module.php', 'class' => 'Riverso_Invoice_Module'],
            'tasks'     => ['file' => 'class-task-module.php', 'class' => 'Riverso_Task_Module'],
            'warehouse' => ['file' => 'class-warehouse-module.php', 'class' => 'Riverso_Warehouse_Module'],
            'suppliers' => ['file' => 'class-supplier-module.php', 'class' => 'Riverso_POS_Supplier_Module'],
            'employees' => ['file' => 'class-employee-module.php', 'class' => 'Riverso_POS_Employee_Module'],
            'quotes'    => ['file' => 'class-received-quote-module.php', 'class' => 'Riverso_POS_Received_Quote_Module'],
            'costs'     => ['file' => 'class-cost-history-module.php', 'class' => 'Riverso_Cost_History_Module'],
            'codes'     => ['file' => 'class-supplier-links-module.php', 'class' => 'Riverso_Supplier_Links_Module'],
            'barcodes'  => ['file' => 'class-barcode-module.php', 'class' => 'Riverso_Barcode_Module'],
            'tienda-local' => ['file' => 'class-tienda-local-module.php', 'class' => 'Riverso_Tienda_Local_Module'],
            'matching'  => ['file' => 'class-matching-module.php', 'class' => 'Riverso_Matching_Module'],
            'pricing'   => ['file' => 'class-pricing-module.php', 'class' => 'Riverso_Pricing_Module'],
            'packaging' => ['file' => 'class-packaging-module.php', 'class' => 'Riverso_Packaging_Module'],
            'import'    => ['file' => 'class-mamut-import-module.php', 'class' => 'Riverso_Mamut_Import_Module'],
            'customer-quotes' => ['file' => 'class-customer-quote-module.php', 'class' => 'Riverso_Customer_Quote_Module'],
            'pos'             => ['file' => 'class-pos-module.php', 'class' => 'Riverso_POS_Module'],
            'reports'         => ['file' => 'class-reports-module.php', 'class' => 'Riverso_Reports_Module'],
        ];
        
        foreach ($module_list as $module_name => $config) {
            $module_file = $modules_dir . $module_name . '/' . $config['file'];
            
            if (file_exists($module_file)) {
                require_once $module_file;
                
                $class_name = $config['class'];
                
                if (class_exists($class_name)) {
                    // Usar get_instance() si existe (singleton), sino new
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
