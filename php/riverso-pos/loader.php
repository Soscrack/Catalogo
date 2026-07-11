<?php
/**
 * Cargador de clases y alias de compatibilidad para Riverso POS
 * 
 * Proporciona autoloading moderno y mantiene aliases para clases movidas
 * sin romper código existente.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Class_Loader {

    /**
     * Registra el autoloader
     */
    public static function register() {
        spl_autoload_register([__CLASS__, 'autoload']);
        self::register_class_aliases();
    }

    /**
     * Función autoload
     * 
     * Convierte nombre de clase a ruta de archivo
     * Ejemplo: Riverso_Audit_Service → core/audit/class-audit-service.php
     */
    public static function autoload($class_name) {
        // Solo nuestras clases
        if (strpos($class_name, 'Riverso_') !== 0) {
            return;
        }

        // Mapeo de clases a rutas
        $class_map = [
            // Core - Audit
            'Riverso_POS_Audit' => 'core/audit/class-audit.php',
            'Riverso_Audit_Module' => 'core/audit/class-audit-module.php',

            // Core - Permissions
            'Riverso_POS_Permissions' => 'core/permissions/class-permissions.php',

            // Core - Tasks
            'Riverso_Task_Module' => 'core/tasks/class-task-module.php',

            // Core - Employees
            'Riverso_POS_Employee_Module' => 'core/employees/class-employee-module.php',

            // Core - Auth
            'Riverso_Auth_Service' => 'core/auth/class-auth-service.php',

            // Core - Events
            'Riverso_Event_Bus' => 'core/events/class-event-bus.php',
        ];

        if (isset($class_map[$class_name])) {
            $file = RIVERSO_POS_PLUGIN_DIR . $class_map[$class_name];
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Registra alias de compatibilidad
     * 
     * Permite que código antiguo siga funcionando si la clase se mudó/renombró
     */
    public static function register_class_aliases() {
        // Las clases en core/ están disponibles con sus nombres originales
        // gracias al autoload; esto es para casos especiales donde se renombre

        // Ejemplo futuro:
        // class_alias('Riverso_Audit_Service', 'Riverso_POS_Audit_Legacy');
    }

    /**
     * Require manual de un archivo
     * 
     * @param string $file Ruta relativa desde RIVERSO_POS_PLUGIN_DIR
     */
    public static function require_file($file) {
        $full_path = RIVERSO_POS_PLUGIN_DIR . $file;
        if (file_exists($full_path)) {
            require_once $full_path;
        }
    }
}

// Registrar autoloader al cargar este archivo
Riverso_Class_Loader::register();
