<?php
/**
 * Plugin Name: Riverso POS
 * Plugin URI: https://riverso.cl
 * Description: Sistema POS/mini-ERP integrado con WooCommerce para gestión de productos, facturas, inventario y tareas operativas.
 * Version: 1.0.0
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
define('RIVERSO_POS_VERSION', '1.0.0');
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
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }
    
    /**
     * Verifica requisitos mínimos
     */
    private function check_requirements() {
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo '<strong>Riverso POS</strong> requiere WooCommerce para funcionar.';
                echo '</p></div>';
            });
            return;
        }
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
     * Ejecuta cuando todos los plugins están cargados
     */
    public function on_plugins_loaded() {
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
            'invoices'  => ['file' => 'class-invoice-module.php', 'class' => 'Riverso_Invoice_Module'],
            'tasks'     => ['file' => 'class-task-module.php', 'class' => 'Riverso_Task_Module'],
            'warehouse' => ['file' => 'class-warehouse-module.php', 'class' => 'Riverso_Warehouse_Module'],
        ];
        
        foreach ($module_list as $module_name => $config) {
            $module_file = $modules_dir . $module_name . '/' . $config['file'];
            
            if (file_exists($module_file)) {
                require_once $module_file;
                
                $class_name = $config['class'];
                
                if (class_exists($class_name)) {
                    $module = new $class_name();
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
