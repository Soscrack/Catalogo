<?php
/**
 * Módulo Portal Interno - Experiencia de empleados separada de wp-admin
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Portal_Module {

    /**
     * Slug de la página del portal
     */
    const PORTAL_SLUG = 'interno';

    /**
     * Inicializar módulo
     */
    public function init() {
        add_action('init', [$this, 'register_portal_page']);
        add_filter('template_include', [$this, 'load_portal_template']);
        add_action('wp_ajax_riverso_portal_get_dashboard', [$this, 'ajax_get_dashboard']);
    }

    /**
     * Registra el rewrite rule para /interno
     */
    public function register_portal_page() {
        add_rewrite_rule(
            '^' . self::PORTAL_SLUG . '/?$',
            'index.php?riverso_portal=dashboard',
            'top'
        );
        add_rewrite_rule(
            '^' . self::PORTAL_SLUG . '/([^/]+)/?$',
            'index.php?riverso_portal=$matches[1]',
            'top'
        );
        add_rewrite_tag('%riverso_portal%', '([^&]+)');
        
        // Flush rules si es necesario (solo una vez)
        if (get_option('riverso_portal_rules_flushed') !== RIVERSO_POS_VERSION) {
            flush_rewrite_rules();
            update_option('riverso_portal_rules_flushed', RIVERSO_POS_VERSION);
        }
    }

    /**
     * Carga el template del portal si estamos en /interno
     */
    public function load_portal_template($template) {
        $portal_page = get_query_var('riverso_portal');
        
        if ($portal_page) {
            // Verificar que el usuario esté logueado y sea empleado
            if (!is_user_logged_in()) {
                wp_redirect(wp_login_url(home_url('/' . self::PORTAL_SLUG . '/')));
                exit;
            }
            
            if (!Riverso_POS_Permissions::is_employee()) {
                wp_redirect(home_url());
                exit;
            }
            
            // Cargar el template del portal
            $portal_template = RIVERSO_POS_PLUGIN_DIR . 'templates/portal/portal-main.php';
            if (file_exists($portal_template)) {
                return $portal_template;
            }
        }
        
        return $template;
    }

    /**
     * Obtiene datos del dashboard para el usuario actual
     */
    public function get_dashboard_data() {
        $user_id = get_current_user_id();
        $role = Riverso_POS_Permissions::get_riverso_role($user_id);
        
        $data = [
            'user' => [
                'id' => $user_id,
                'name' => wp_get_current_user()->display_name,
                'role' => $role,
                'role_name' => $this->get_role_display_name($role),
            ],
            'modules' => Riverso_POS_Permissions::get_accessible_modules($user_id),
            'stats' => $this->get_user_stats($user_id),
            'tasks' => $this->get_pending_tasks($user_id),
            'notifications' => $this->get_notifications($user_id),
        ];
        
        return $data;
    }

    /**
     * Obtiene el nombre legible del rol
     */
    private function get_role_display_name($role) {
        $roles = Riverso_POS_Permissions::ROLES;
        if (isset($roles[$role])) {
            return $roles[$role]['name'];
        }
        if ($role === 'administrator') {
            return 'Administrador';
        }
        return 'Usuario';
    }

    /**
     * Obtiene estadísticas según el rol del usuario
     */
    private function get_user_stats($user_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $stats = [];
        
        // Tareas pendientes del usuario
        $stats['tareas_pendientes'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}tareas 
             WHERE (asignado_a = %d OR asignado_a IS NULL) 
             AND estado NOT IN ('completada', 'cancelada')",
            $user_id
        ));
        
        // Tareas completadas hoy
        $stats['tareas_hoy'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}tareas 
             WHERE completado_en >= %s AND estado = 'completada'",
            date('Y-m-d 00:00:00')
        ));
        
        // Facturas pendientes (si tiene permiso)
        if (current_user_can('riverso_view_invoices')) {
            $stats['facturas_pendientes'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pendiente'"
            );
        }
        
        // Productos en bodega (si tiene permiso)
        if (current_user_can('riverso_view_warehouse')) {
            $stats['ubicaciones_activas'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$prefix}ubicaciones WHERE activo = 1"
            );
        }
        
        return $stats;
    }

    /**
     * Obtiene tareas pendientes del usuario
     */
    private function get_pending_tasks($user_id, $limit = 5) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, u.display_name as creador_nombre 
             FROM {$prefix}tareas t
             LEFT JOIN {$wpdb->users} u ON t.creado_por = u.ID
             WHERE (t.asignado_a = %d OR t.asignado_a IS NULL)
             AND t.estado NOT IN ('completada', 'cancelada')
             ORDER BY FIELD(t.prioridad, 'urgente', 'alta', 'normal', 'baja'), t.created_at DESC
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Obtiene notificaciones del usuario (placeholder)
     */
    private function get_notifications($user_id) {
        // TODO: Implementar sistema de notificaciones
        return [];
    }

    /**
     * AJAX: Obtener datos del dashboard
     */
    public function ajax_get_dashboard() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!Riverso_POS_Permissions::is_employee()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        wp_send_json_success($this->get_dashboard_data());
    }
}
