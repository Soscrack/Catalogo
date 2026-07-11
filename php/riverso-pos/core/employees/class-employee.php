<?php
/**
 * Modelo delgado de empleado Riverso
 *
 * Lookup / creación de fila en riverso_empleados.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Employee {

    /**
     * Obtiene o crea el registro de empleado para un user_id WP.
     *
     * @param int $user_id
     * @return array|null
     */
    public static function from_user($user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return null;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }

        $table = $wpdb->prefix . 'riverso_empleados';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists) {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $user_id),
                ARRAY_A
            );

            if ($row) {
                $row['display_name'] = $user->display_name;
                $row['email'] = $user->user_email;
                return $row;
            }

            $wpdb->insert(
                $table,
                [
                    'user_id' => $user_id,
                    'estado' => 'activo',
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s']
            );

            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d", $user_id),
                ARRAY_A
            );
            if ($row) {
                $row['display_name'] = $user->display_name;
                $row['email'] = $user->user_email;
                return $row;
            }
        }

        // Fallback sin tabla
        return [
            'id' => null,
            'user_id' => $user_id,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'estado' => 'activo',
        ];
    }

    /**
     * ID de empleado del usuario actual (fila riverso_empleados.id o user_id).
     *
     * @return int|null
     */
    public static function current_id() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return null;
        }

        $emp = self::from_user($user_id);
        if (!$emp) {
            return null;
        }

        return isset($emp['id']) && $emp['id'] ? intval($emp['id']) : intval($emp['user_id']);
    }

    /**
     * user_id WP del usuario actual.
     *
     * @return int
     */
    public static function current_user_id() {
        return get_current_user_id();
    }
}
