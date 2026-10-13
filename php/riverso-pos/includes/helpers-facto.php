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
 * @param string $key enabled|base_url|client_id|client_secret|username|password|account_id|price_list_id|location_id|currency_id|tax_type_id|template_thermal_50mm|sync_enabled|issuer_*
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
        'issuer_rut'    => 'RIVERSO_FACTO_ISSUER_RUT',
        'issuer_legal_name' => 'RIVERSO_FACTO_ISSUER_LEGAL_NAME',
        'issuer_address' => 'RIVERSO_FACTO_ISSUER_ADDRESS',
        'issuer_district' => 'RIVERSO_FACTO_ISSUER_DISTRICT',
        'issuer_city'   => 'RIVERSO_FACTO_ISSUER_CITY',
        'issuer_phone'  => 'RIVERSO_FACTO_ISSUER_PHONE',
        'issuer_activity' => 'RIVERSO_FACTO_ISSUER_ACTIVITY',
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
        // Plantilla FACTO «Boleta formato térmico 50mm» (MediaBox ~50mm).
        'template_thermal_50mm' => 1,
        'issuer_rut'    => '',
        'issuer_legal_name' => '',
        'issuer_address' => '',
        'issuer_district' => '',
        'issuer_city'   => '',
        'issuer_phone'  => '',
        'issuer_activity' => '',
        'issuer_country_id' => '253',
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

/**
 * Datos del emisor para POST /documents (Chile).
 * Completa campos vacíos de Ajustes con el encabezado del último DTE emitido en FACTO.
 *
 * @return array{ok:bool,missing:string[],issuer:array<string,string>}
 */
function riverso_facto_issuer_profile() {
    $keys = [
        'issuer_rut' => 'RUT emisor',
        'issuer_legal_name' => 'Razón social emisor',
        'issuer_address' => 'Dirección emisor',
        'issuer_district' => 'Comuna emisor',
        'issuer_city' => 'Ciudad emisor',
        'issuer_phone' => 'Teléfono emisor',
        'issuer_activity' => 'Giro emisor',
    ];
    $issuer = [
        'tax_id_code' => trim((string) riverso_get_facto_config('issuer_rut', '')),
        'tax_id_type' => 'CL-RUT',
        'legal_name' => trim((string) riverso_get_facto_config('issuer_legal_name', '')),
        'address' => trim((string) riverso_get_facto_config('issuer_address', '')),
        'district' => trim((string) riverso_get_facto_config('issuer_district', '')),
        'city' => trim((string) riverso_get_facto_config('issuer_city', '')),
        'phone' => trim((string) riverso_get_facto_config('issuer_phone', '')),
        'activity' => trim((string) riverso_get_facto_config('issuer_activity', '')),
        'country_id' => (string) riverso_get_facto_config('issuer_country_id', '253'),
    ];

    $fill_keys = ['tax_id_code', 'legal_name', 'address', 'district', 'city', 'phone', 'activity'];
    $needs_fill = false;
    foreach ($fill_keys as $fk) {
        if ($issuer[$fk] === '') {
            $needs_fill = true;
            break;
        }
    }
    if ($needs_fill) {
        $from_doc = riverso_facto_issuer_from_issued_document();
        if (is_array($from_doc)) {
            foreach ($fill_keys as $fk) {
                if ($issuer[$fk] === '' && !empty($from_doc[$fk])) {
                    $issuer[$fk] = trim((string) $from_doc[$fk]);
                }
            }
            if ($issuer['country_id'] === '' && !empty($from_doc['country_id'])) {
                $issuer['country_id'] = (string) $from_doc['country_id'];
            }
        }
    }

    $missing = [];
    $map = [
        'issuer_rut' => $issuer['tax_id_code'],
        'issuer_legal_name' => $issuer['legal_name'],
        'issuer_address' => $issuer['address'],
        'issuer_district' => $issuer['district'],
        'issuer_city' => $issuer['city'],
        'issuer_phone' => $issuer['phone'],
        'issuer_activity' => $issuer['activity'],
    ];
    foreach ($map as $cfg_key => $val) {
        if ($val === '') {
            $missing[] = $keys[$cfg_key];
        }
    }
    if ($issuer['tax_id_code'] !== '' && function_exists('riverso_validate_rut') && !riverso_validate_rut($issuer['tax_id_code'])) {
        $missing[] = 'RUT emisor (formato inválido)';
    }
    return [
        'ok' => $missing === [],
        'missing' => $missing,
        'issuer' => $issuer,
    ];
}

/**
 * Emisor desde el último documento emitido (boleta 37 o factura 2).
 * Resultado cacheado 1 hora. No escribe opciones de Ajustes.
 *
 * @return array<string,string>|null
 */
function riverso_facto_issuer_from_issued_document() {
    $cache_key = 'riverso_facto_issuer_from_docs';
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['tax_id_code'])) {
        return $cached;
    }
    // Cache negativo breve: evita martillar FACTO si falla.
    if ($cached === 'none') {
        return null;
    }
    if (!riverso_facto_is_configured()) {
        return null;
    }

    $client_path = RIVERSO_POS_PLUGIN_DIR . 'modules/integrations/facto/class-facto-client.php';
    if (!class_exists('Riverso_Facto_Client') && file_exists($client_path)) {
        require_once $client_path;
    }
    if (!class_exists('Riverso_Facto_Client')) {
        return null;
    }

    $client = new Riverso_Facto_Client();
    $header = null;
    foreach ([37, 2] as $type_id) {
        $resp = $client->list_documents([
            'document_type_id' => $type_id,
            'order_by' => 'desc',
            'page' => 1,
            'per_page' => 5,
        ]);
        if (is_wp_error($resp)) {
            continue;
        }
        $docs = Riverso_Facto_Client::embed_collection($resp, 'documents');
        foreach ($docs as $doc) {
            if (!is_array($doc) || empty($doc['document_id'])) {
                continue;
            }
            $full = $client->get_document($doc['document_id']);
            if (is_wp_error($full) || empty($full['header']) || !is_array($full['header'])) {
                continue;
            }
            $h = $full['header'];
            if (trim((string) ($h['issuer_tax_id_code'] ?? '')) === ''
                || trim((string) ($h['issuer_legal_name'] ?? '')) === ''
            ) {
                continue;
            }
            $header = $h;
            break 2;
        }
    }

    if ($header === null) {
        set_transient($cache_key, 'none', 5 * MINUTE_IN_SECONDS);
        return null;
    }

    $profile = [
        'tax_id_code' => trim((string) ($header['issuer_tax_id_code'] ?? '')),
        'legal_name' => trim((string) ($header['issuer_legal_name'] ?? '')),
        'address' => trim((string) ($header['issuer_address'] ?? '')),
        'district' => trim((string) ($header['issuer_district'] ?? '')),
        'city' => trim((string) ($header['issuer_city'] ?? '')),
        'phone' => trim((string) ($header['issuer_phone'] ?? '')),
        'activity' => trim((string) ($header['issuer_activity'] ?? '')),
        'country_id' => trim((string) ($header['issuer_country_id'] ?? '253')),
    ];
    if ($profile['tax_id_code'] === '' || $profile['legal_name'] === '') {
        set_transient($cache_key, 'none', 5 * MINUTE_IN_SECONDS);
        return null;
    }

    set_transient($cache_key, $profile, HOUR_IN_SECONDS);
    return $profile;
}
