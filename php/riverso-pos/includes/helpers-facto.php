<?php
/**
 * Helpers de configuración FACTO.
 *
 * Prioridad: constantes wp-config.php > option riverso_pos_settings > default.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $key enabled|base_url|client_id|client_secret|username|password|account_id|price_list_id|location_id|currency_id|tax_type_id|sync_enabled
 * @param mixed  $default
 * @return mixed
 */
function riverso_get_facto_config($key, $default = '') {
    $const_map = [
        'base_url'      => 'RIVERSO_FACTO_BASE_URL',
        'client_id'     => 'RIVERSO_FACTO_CLIENT_ID',
        'client_secret' => 'RIVERSO_FACTO_CLIENT_SECRET',
        'username'      => 'RIVERSO_FACTO_USERNAME',
        'password'      => 'RIVERSO_FACTO_PASSWORD',
        'account_id'    => 'RIVERSO_FACTO_ACCOUNT_ID',
    ];

    $defaults = [
        'enabled'       => 0,
        'sync_enabled'  => 0,
        'base_url'      => 'https://apifacto.com/v1',
        'currency_id'   => 39,
        'tax_type_id'   => 387,
        'price_list_id' => 1,
        'location_id'   => 1,
        'account_id'    => '',
    ];

    $value = array_key_exists($key, $defaults) ? $defaults[$key] : $default;

    $from_settings = riverso_get_setting('facto_' . $key, null);
    if ($from_settings !== null && $from_settings !== '') {
        $value = $from_settings;
    }

    if (isset($const_map[$key]) && defined($const_map[$key])) {
        $const_val = constant($const_map[$key]);
        if ($const_val !== '' && $const_val !== null) {
            $value = $const_val;
        }
    }

    return $value;
}

/**
 * ¿Credenciales mínimas presentes?
 */
function riverso_facto_is_configured() {
    return riverso_get_facto_config('client_id', '') !== ''
        && riverso_get_facto_config('client_secret', '') !== ''
        && riverso_get_facto_config('username', '') !== ''
        && riverso_get_facto_config('password', '') !== '';
}

/**
 * Sync activo (flag + credenciales).
 */
function riverso_facto_sync_enabled() {
    return !empty(riverso_get_facto_config('enabled', 0))
        && !empty(riverso_get_facto_config('sync_enabled', 0))
        && riverso_facto_is_configured();
}
