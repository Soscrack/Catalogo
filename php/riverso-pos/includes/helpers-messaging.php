<?php
/**
 * Configuración de mensajería (Gmail / WhatsApp Cloud API).
 *
 * Constantes wp-config.php tienen prioridad sobre riverso_pos_settings.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function riverso_get_messaging_config($key, $default = '') {
    $const_map = [
        'gmail_client_id'       => 'RIVERSO_GMAIL_CLIENT_ID',
        'gmail_client_secret'   => 'RIVERSO_GMAIL_CLIENT_SECRET',
        'gmail_refresh_token'   => 'RIVERSO_GMAIL_REFRESH_TOKEN',
        'gmail_user'            => 'RIVERSO_GMAIL_USER',
        'wa_access_token'       => 'RIVERSO_WA_ACCESS_TOKEN',
        'wa_phone_number_id'    => 'RIVERSO_WA_PHONE_NUMBER_ID',
        'wa_waba_id'            => 'RIVERSO_WA_WABA_ID',
        'wa_app_secret'         => 'RIVERSO_WA_APP_SECRET',
        'wa_verify_token'       => 'RIVERSO_WA_VERIFY_TOKEN',
        'wa_app_id'             => 'RIVERSO_WA_APP_ID',
    ];

    if (isset($const_map[$key]) && defined($const_map[$key])) {
        $val = constant($const_map[$key]);
        if ($val !== '' && $val !== null) {
            return $val;
        }
    }

    $settings = get_option('riverso_pos_settings', []);
    $msg = is_array($settings) && isset($settings['messaging']) && is_array($settings['messaging'])
        ? $settings['messaging']
        : [];

    if (array_key_exists($key, $msg) && $msg[$key] !== '' && $msg[$key] !== null) {
        return $msg[$key];
    }

    $defaults = [
        'gmail_user' => 'rs.riverso@gmail.com',
        'wa_app_id'  => '1595458301992424',
        'wa_waba_id' => '315241043039660',
        'wa_verify_token' => 'riverso-wa-verify',
    ];

    return $defaults[$key] ?? $default;
}

function riverso_messaging_uploads_dir() {
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return '';
    }
    return trailingslashit($upload['basedir']) . 'riverso-inbox';
}

function riverso_messaging_protect_uploads_dir() {
    $dir = riverso_messaging_uploads_dir();
    if ($dir === '') {
        return;
    }
    wp_mkdir_p($dir);
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    $index = $dir . '/index.php';
    if (!is_file($index)) {
        file_put_contents($index, "<?php\n// Silence is golden.\n");
    }
}

function riverso_messaging_is_quote_filename($filename) {
    $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'xlsx', 'xls', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function riverso_messaging_normalize_quote_haystack($subject, $body) {
    $hay = strtolower(remove_accents((string) $subject . ' ' . (string) $body));
    return str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $hay);
}

function riverso_messaging_quote_text_is_strong($subject, $body) {
    $hay = riverso_messaging_normalize_quote_haystack($subject, $body);
    return strpos($hay, 'cotizaci') !== false || strpos($hay, 'proforma') !== false;
}

/**
 * Heurística de cotización de compra. "oferta" sola (newsletters) no cuenta.
 *
 * @param bool $has_attachment Adjunto de compra (pdf/xlsx/xls/csv/txt), no cualquier archivo.
 * @param bool $known_proveedor El remitente ya está en proveedores.
 */
function riverso_messaging_looks_like_quote($subject, $body, $has_attachment = false, $known_proveedor = false) {
    $hay = riverso_messaging_normalize_quote_haystack($subject, $body);
    if (strpos($hay, 'cotizaci') !== false || strpos($hay, 'proforma') !== false) {
        return true;
    }
    if (strpos($hay, 'oferta') !== false && ($has_attachment || $known_proveedor)) {
        return true;
    }
    return $has_attachment && (strpos($hay, 'precio') !== false || strpos($hay, 'lista') !== false);
}

/**
 * Sugiere tipo_doc: cotizacion | posible_cotizacion | null.
 *
 * @return string|null
 */
function riverso_messaging_suggest_quote_type($subject, $body, $has_attachment = false, $known_proveedor = false) {
    if (!riverso_messaging_looks_like_quote($subject, $body, $has_attachment, $known_proveedor)) {
        return null;
    }
    if (riverso_messaging_quote_text_is_strong($subject, $body) && $has_attachment) {
        return 'cotizacion';
    }
    return 'posible_cotizacion';
}

function riverso_messaging_parse_gmail_labels($raw) {
    if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw)));
    }
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
}

function riverso_messaging_labels_is_spam(array $labels) {
    return in_array('SPAM', $labels, true);
}

function riverso_messaging_labels_is_important(array $labels) {
    return in_array('IMPORTANT', $labels, true);
}
