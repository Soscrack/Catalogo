<?php
/**
 * Servicio de autenticación con auditoría
 * 
 * Audita eventos de login/logout y mantiene sesión de usuario.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Auth_Service {

    /**
     * Instancia singleton
     */
    private static $instance = null;

    /**
     * Obtiene la instancia singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa los hooks de autenticación
     */
    public function init() {
        static $booted = false;
        if ($booted) {
            return;
        }
        $booted = true;

        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_logout'], 10, 0);
        add_action('set_user_role', [$this, 'on_user_role_changed'], 10, 3);
    }

    /**
     * Manejador de login
     * 
     * @param string   $user_login Nombre de usuario
     * @param WP_User  $user       Objeto de usuario
     */
    public function on_login($user_login, $user) {
        if (!class_exists('Riverso_POS_Audit')) {
            return;
        }

        Riverso_POS_Audit::log('user_login', 'user', $user->ID, [
            'details' => $user_login,
            'new_value' => [
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'ip_address' => $this->get_client_ip(),
            ],
        ]);

        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('auth.user.login', [
                'user_id' => $user->ID,
                'user_login' => $user_login,
            ], [
                'user_id' => $user->ID,
            ]);
        }
    }

    /**
     * Manejador de logout
     */
    public function on_logout() {
        $user = wp_get_current_user();
        
        if (!$user || !$user->ID) {
            return;
        }

        if (!class_exists('Riverso_POS_Audit')) {
            return;
        }

        Riverso_POS_Audit::log('user_logout', 'user', $user->ID, [
            'details' => 'logout',
            'new_value' => ['ip_address' => $this->get_client_ip()],
        ]);

        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('auth.user.logout', [
                'user_id' => $user->ID,
            ], [
                'user_id' => $user->ID,
            ]);
        }
    }

    /**
     * Manejador de cambio de rol
     */
    public function on_user_role_changed($user_id, $role, $old_roles) {
        if (!class_exists('Riverso_POS_Audit')) {
            return;
        }

        Riverso_POS_Audit::log('role_assigned', 'user', $user_id, [
            'old_value' => implode(', ', (array) $old_roles),
            'new_value' => $role,
        ]);

        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('auth.role.changed', [
                'user_id' => $user_id,
                'new_role' => $role,
                'old_roles' => $old_roles,
            ], [
                'user_id' => $user_id,
            ]);
        }
    }

    /**
     * Obtiene la IP del cliente
     * 
     * @return string
     */
    private function get_client_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return sanitize_text_field($ip);
    }

    /**
     * Verifica si el usuario actual es un empleado Riverso
     * 
     * @return bool
     */
    public static function is_riverso_employee() {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return false;
        }

        return Riverso_POS_Permissions::is_employee($user->ID);
    }

    /**
     * Obtiene el usuario actual como empleado
     * 
     * @return int|false User ID si es empleado, false otherwise
     */
    public static function get_current_employee_id() {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) {
            return false;
        }

        if (self::is_riverso_employee()) {
            return $user->ID;
        }

        return false;
    }
}
