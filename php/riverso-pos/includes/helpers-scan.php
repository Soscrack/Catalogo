<?php
/**
 * Helpers para documentos escaneados (PDF/imagen) e integración IA/R2.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

function riverso_scan_uploads_dir() {
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        return '';
    }
    return trailingslashit($upload['basedir']) . 'riverso-scans';
}

/**
 * Ruta local permanente de un archivo escaneado (por hash SHA-256).
 */
function riverso_scan_local_archive_path($hash, $ext) {
    $dir = riverso_scan_uploads_dir();
    if ($dir === '') {
        return '';
    }
    $hash = preg_replace('/[^a-f0-9]/', '', strtolower((string) $hash));
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string) $ext));
    if ($hash === '' || $ext === '') {
        return '';
    }
    return $dir . '/' . $hash . '.' . $ext;
}

/**
 * Extensión a partir del MIME del escaneo.
 */
function riverso_scan_ext_from_mime($mime) {
    $map = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
    ];
    return $map[(string) $mime] ?? 'pdf';
}

/**
 * Bloquea acceso HTTP directo a wp-content/uploads/riverso-scans/.
 */
function riverso_scan_protect_uploads_dir() {
    $dir = riverso_scan_uploads_dir();
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

/**
 * Configuración de escaneos: constantes wp-config.php tienen prioridad sobre riverso_pos_settings.
 *
 * @param string $key gemini_api_key|gemini_model|r2_access_key_id|r2_secret_access_key|r2_endpoint|r2_bucket|r2_prefix|expected_receptor_rut
 * @param mixed  $default
 * @return mixed
 */
function riverso_get_scan_config($key, $default = '') {
    $const_map = [
        'gemini_api_key'        => 'RIVERSO_GEMINI_API_KEY',
        'gemini_model'          => 'RIVERSO_GEMINI_MODEL',
        'r2_access_key_id'      => 'RIVERSO_R2_ACCESS_KEY_ID',
        'r2_secret_access_key'  => 'RIVERSO_R2_SECRET_ACCESS_KEY',
        'r2_endpoint'           => 'RIVERSO_R2_ENDPOINT',
        'r2_bucket'             => 'RIVERSO_R2_BUCKET',
    ];

    $defaults = [
        'gemini_model'          => 'gemini-3.6-flash',
        'r2_bucket'             => 'riverso-documentos',
        'r2_prefix'             => '',
        'expected_receptor_rut' => '76.443.852-3',
    ];

    $value = $defaults[$key] ?? $default;

    $settings_key = 'scan_' . $key;
    $from_settings = riverso_get_setting($settings_key, null);
    if ($from_settings !== null && $from_settings !== '' && !riverso_is_masked_secret($from_settings)) {
        $value = $from_settings;
    }

    if (isset($const_map[$key]) && defined($const_map[$key])) {
        $val = constant($const_map[$key]);
        if ($val !== '') {
            $value = $val;
        }
    }

    if ($key === 'gemini_model') {
        return riverso_normalize_gemini_model($value);
    }

    return $value;
}

/**
 * Normaliza nombre de modelo Gemini (quita prefijo models/ y migra deprecados).
 */
function riverso_normalize_gemini_model($model) {
    $model = trim((string) $model);
    $model = preg_replace('#^models/#', '', $model);
    if ($model === '') {
        return 'gemini-3.6-flash';
    }

    $deprecated = [
        'gemini-2.5-flash'      => 'gemini-3.6-flash',
        'gemini-2.5-flash-lite' => 'gemini-3.6-flash',
        'gemini-2.0-flash'      => 'gemini-3.6-flash',
    ];

    return $deprecated[$model] ?? $model;
}

/**
 * Detecta valores enmascarados de UI (ej. 17b5**************541a) que no deben persistirse.
 */
function riverso_is_masked_secret($value) {
    $value = (string) $value;
    return $value !== '' && preg_match('/\*{4,}/', $value);
}

/**
 * Enmascara un secreto para mostrar en UI.
 */
function riverso_mask_secret($value) {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}

/**
 * Normaliza RUT chileno a formato 12.345.678-9 (alias de riverso_format_rut).
 */
function riverso_normalize_rut($rut) {
    return riverso_format_rut($rut);
}

/**
 * Normaliza folio: quita puntos y espacios.
 */
function riverso_normalize_folio($folio) {
    return preg_replace('/[^0-9A-Za-z]/', '', (string) $folio);
}

/**
 * Cuenta páginas de un PDF sin dependencias externas.
 */
function riverso_pdf_page_count($path) {
    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        return 1;
    }
    if (preg_match_all('/\/Type\s*\/Page\b/', $content, $matches)) {
        return max(1, count($matches[0]));
    }
    return 1;
}

/**
 * MIME permitidos para escaneos.
 *
 * @return string[]
 */
function riverso_scan_allowed_mimes() {
    return [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
    ];
}

/**
 * Detecta MIME y extensión de un archivo subido.
 *
 * @return array{ext:string,mime:string}|WP_Error
 */
function riverso_scan_detect_file_type($path, $original_name = '') {
    $allowed = riverso_scan_allowed_mimes();
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext && isset($allowed[$ext])) {
        return ['ext' => $ext === 'jpeg' ? 'jpg' : ($ext === 'tiff' ? 'tif' : $ext), 'mime' => $allowed[$ext]];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        foreach ($allowed as $e => $m) {
            if ($m === $mime) {
                return ['ext' => $e === 'jpeg' ? 'jpg' : ($e === 'tiff' ? 'tif' : $e), 'mime' => $m];
            }
        }
    }
    return new WP_Error('invalid_mime', 'Tipo de archivo no permitido. Use PDF, JPG, PNG o WEBP.');
}

/**
 * Prefijo R2 con barra final opcional.
 */
function riverso_scan_r2_prefix() {
    $prefix = trim((string) riverso_get_scan_config('r2_prefix', ''));
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    return $prefix;
}

/**
 * Hash de documento para deduplicación.
 */
function riverso_scan_doc_hash($tipo_dte, $folio, $rut_emisor) {
    $payload = implode('|', [
        (int) $tipo_dte,
        riverso_normalize_folio($folio),
        strtoupper(preg_replace('/[^0-9K]/', '', (string) $rut_emisor)),
    ]);
    return hash('sha256', $payload);
}

/**
 * Mapeo tipo referencia escaneo → código SII / interno.
 */
function riverso_scan_ref_tipo_doc($tipo_ref) {
    $map = [
        'factura_electronica'         => 33,
        'factura_exenta'              => 34,
        'boleta'                      => 39,
        'boleta_electronica'          => 39,
        'guia_despacho'               => 52,
        'guia_despacho_electronica'   => 52,
        'nota_credito'                => 61,
        'nota_debito'                 => 56,
        'orden_compra'                => 801,
        'nro_pedido'                  => 802,
        'nota_venta'                  => 803,
        'guia'                        => 52,
        'oc'                          => 801,
    ];
    $key = strtolower(preg_replace('/[^a-z0-9_]/', '_', (string) $tipo_ref));
    return $map[$key] ?? 0;
}

/**
 * Mapeo tipo documento escaneo → TipoDTE.
 */
function riverso_scan_tipo_dte($tipo_documento, $fallback = 33) {
    $map = [
        'factura_electronica'       => 33,
        'factura'                   => 33,
        'factura_exenta'            => 34,
        'boleta_electronica'        => 39,
        'boleta'                    => 39,
        'guia_despacho'             => 52,
        'guia_despacho_electronica' => 52,
        'guia'                      => 52,
        'nota_credito'              => 61,
        'nota_debito'               => 56,
        'nota_de_credito'           => 61,
    ];
    $key = strtolower(preg_replace('/[^a-z0-9_]/', '_', (string) $tipo_documento));
    return $map[$key] ?? (int) $fallback;
}

/**
 * Etiqueta legible de tipo DTE.
 */
function riverso_scan_tipo_label($tipo_dte, $tipo_documento = '') {
    $labels = [
        33 => 'Factura',
        34 => 'Fact. Exenta',
        39 => 'Boleta',
        52 => 'Guía',
        56 => 'N. Débito',
        61 => 'N. Crédito',
    ];
    if (isset($labels[(int) $tipo_dte])) {
        return $labels[(int) $tipo_dte];
    }
    return $tipo_documento ? ucfirst(str_replace('_', ' ', $tipo_documento)) : 'Documento';
}

/**
 * Semáforo de confianza.
 *
 * @return string green|amber|red
 */
function riverso_scan_confidence_level($confianza, $validacion = []) {
    $confianza = (float) $confianza;
    $errors = (int) ($validacion['error_count'] ?? 0);
    if ($errors > 0 || $confianza < 0.65) {
        return 'red';
    }
    if ($confianza >= 0.85) {
        return 'green';
    }
    return 'amber';
}
