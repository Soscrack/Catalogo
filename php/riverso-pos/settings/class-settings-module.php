<?php
/**
 * Módulo Settings — wrapper delgado sobre riverso_get_setting / riverso_set_setting
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Settings_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_get_settings', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_save_settings', [$this, 'ajax_save']);
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function get($key, $default = null) {
        if (function_exists('riverso_get_setting')) {
            return riverso_get_setting($key, $default);
        }
        $settings = get_option('riverso_pos_settings', []);
        return $settings[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function set($key, $value) {
        if (function_exists('riverso_set_setting')) {
            riverso_set_setting($key, $value);
            return;
        }
        $settings = get_option('riverso_pos_settings', []);
        $settings[$key] = $value;
        update_option('riverso_pos_settings', $settings);
    }

    /**
     * @return array
     */
    public static function all() {
        return get_option('riverso_pos_settings', []);
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        if ($key) {
            wp_send_json_success(['key' => $key, 'value' => self::get($key)]);
        }
        wp_send_json_success(self::all());
    }

    public function ajax_save() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('manage_options') && !current_user_can('riverso_manage_system')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        if (!$key) {
            wp_send_json_error(['message' => 'key requerido']);
        }
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : null;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            } else {
                $value = sanitize_text_field($value);
            }
        }
        self::set($key, $value);
        wp_send_json_success(['key' => $key, 'value' => self::get($key)]);
    }
}

add_action('riverso_pos_init', function () {
    Riverso_Settings_Module::get_instance()->init();
});
