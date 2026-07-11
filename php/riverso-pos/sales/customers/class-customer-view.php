<?php
/**
 * Vista delgada de clientes WooCommerce
 *
 * Busca usuarios WP con rol customer.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Customer_View {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_search_customers', [$this, 'ajax_search']);
        add_action('wp_ajax_riverso_get_customer', [$this, 'ajax_get']);
    }

    /**
     * Busca clientes por nombre/email/login.
     *
     * @param string $term
     * @param int    $limit
     * @return array
     */
    public function search($term = '', $limit = 20) {
        $args = [
            'role'   => 'customer',
            'number' => intval($limit),
            'orderby'=> 'display_name',
            'order'  => 'ASC',
        ];

        $term = trim((string) $term);
        if ($term !== '') {
            $args['search'] = '*' . esc_attr($term) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $users = get_users($args);
        $out = [];
        foreach ($users as $user) {
            $out[] = [
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'login' => $user->user_login,
            ];
        }
        return $out;
    }

    /**
     * Obtiene un cliente por user_id.
     *
     * @param int $user_id
     * @return array|null
     */
    public function get($user_id) {
        $user = get_userdata(intval($user_id));
        if (!$user || !in_array('customer', (array) $user->roles, true)) {
            return null;
        }
        return [
            'id' => $user->ID,
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'login' => $user->user_login,
            'billing_phone' => get_user_meta($user->ID, 'billing_phone', true),
            'billing_company' => get_user_meta($user->ID, 'billing_company', true),
        ];
    }

    public function ajax_search() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_customers') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        wp_send_json_success($this->search($term));
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_customers') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $customer = $this->get($id);
        if (!$customer) {
            wp_send_json_error(['message' => 'Cliente no encontrado']);
        }
        wp_send_json_success($customer);
    }
}
