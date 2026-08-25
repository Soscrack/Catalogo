<?php
/**
 * Cliente Cloudflare R2 (S3-compatible) con firma AWS SigV4 en PHP puro.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_R2_Client {

    private $access_key;
    private $secret_key;
    private $endpoint;
    private $bucket;
    private $region = 'auto';

    public function __construct() {
        $this->access_key = (string) riverso_get_scan_config('r2_access_key_id', '');
        $this->secret_key = (string) riverso_get_scan_config('r2_secret_access_key', '');
        $this->endpoint   = rtrim((string) riverso_get_scan_config('r2_endpoint', ''), '/');
        $this->bucket     = (string) riverso_get_scan_config('r2_bucket', 'riverso-documentos');
    }

    public function is_configured() {
        return $this->access_key !== '' && $this->secret_key !== '' && $this->endpoint !== '' && $this->bucket !== '';
    }

    /**
     * @return true|WP_Error
     */
    public function put_object($key, $body, $content_type = 'application/octet-stream') {
        if (!$this->is_configured()) {
            return new WP_Error('r2_not_configured', 'Cloudflare R2 no está configurado.');
        }
        $key = ltrim($key, '/');
        $url = $this->object_url($key);
        $headers = $this->sign_request('PUT', $url, $body, [
            'content-type'   => $content_type,
            'content-length' => (string) strlen($body),
            'host'           => wp_parse_url($this->endpoint, PHP_URL_HOST),
        ]);
        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 120,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return true;
        }
        return new WP_Error('r2_put_failed', 'Error subiendo a R2: HTTP ' . $code, [
            'body' => wp_remote_retrieve_body($response),
        ]);
    }

    /**
     * @return string|WP_Error
     */
    public function get_object($key) {
        if (!$this->is_configured()) {
            return new WP_Error('r2_not_configured', 'Cloudflare R2 no está configurado.');
        }
        $url = $this->object_url($key);
        $headers = $this->sign_request('GET', $url, '', [
            'host' => wp_parse_url($this->endpoint, PHP_URL_HOST),
        ]);
        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 120,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return wp_remote_retrieve_body($response);
        }
        if ($code === 404) {
            return new WP_Error('r2_not_found', 'Objeto no encontrado en R2.');
        }
        return new WP_Error('r2_get_failed', 'Error leyendo de R2: HTTP ' . $code);
    }

    /**
     * Metadatos HEAD del objeto (content-type, content-length).
     *
     * @return array|WP_Error
     */
    public function head_object($key) {
        if (!$this->is_configured()) {
            return new WP_Error('r2_not_configured', 'Cloudflare R2 no está configurado.');
        }
        $url = $this->object_url($key);
        $headers = $this->sign_request('HEAD', $url, '', [
            'host' => wp_parse_url($this->endpoint, PHP_URL_HOST),
        ]);
        $response = wp_remote_request($url, [
            'method'  => 'HEAD',
            'headers' => $headers,
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return new WP_Error('r2_not_found', 'Objeto no encontrado en R2.');
        }
        if ($code !== 200) {
            return new WP_Error('r2_head_failed', 'Error consultando R2: HTTP ' . $code);
        }
        return [
            'content_type'   => wp_remote_retrieve_header($response, 'content-type') ?: 'application/octet-stream',
            'content_length' => (int) wp_remote_retrieve_header($response, 'content-length'),
        ];
    }

    /**
     * Transmite el objeto al cliente sin cargarlo entero en memoria.
     *
     * @return true|WP_Error
     */
    public function stream_object($key) {
        if (!$this->is_configured()) {
            return new WP_Error('r2_not_configured', 'Cloudflare R2 no está configurado.');
        }
        if (!function_exists('curl_init')) {
            $body = $this->get_object($key);
            if (is_wp_error($body)) {
                return $body;
            }
            echo $body;
            return true;
        }

        $url = $this->object_url($key);
        $signed = $this->sign_request('GET', $url, '', [
            'host' => wp_parse_url($this->endpoint, PHP_URL_HOST),
        ]);
        $curl_headers = [];
        foreach ($signed as $name => $value) {
            $curl_headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_WRITEFUNCTION  => static function ($handle, $chunk) {
                echo $chunk;
                if (function_exists('flush')) {
                    flush();
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false) {
            return new WP_Error('r2_stream_failed', 'Error transmitiendo desde R2: ' . curl_strerror($errno));
        }
        if ($http_code === 404) {
            return new WP_Error('r2_not_found', 'Objeto no encontrado en R2.');
        }
        if ($http_code !== 200) {
            return new WP_Error('r2_stream_failed', 'Error transmitiendo desde R2: HTTP ' . $http_code);
        }
        return true;
    }

    /**
     * Descarga un objeto R2 a disco local.
     *
     * @return true|WP_Error
     */
    public function download_object($key, $dest_path) {
        if (!$this->is_configured()) {
            return new WP_Error('r2_not_configured', 'Cloudflare R2 no está configurado.');
        }

        $dir = dirname($dest_path);
        if (!wp_mkdir_p($dir)) {
            return new WP_Error('local_mkdir', 'No se pudo crear carpeta destino');
        }

        if (function_exists('curl_init')) {
            $url = $this->object_url($key);
            $signed = $this->sign_request('GET', $url, '', [
                'host' => wp_parse_url($this->endpoint, PHP_URL_HOST),
            ]);
            $curl_headers = [];
            foreach ($signed as $name => $value) {
                $curl_headers[] = $name . ': ' . $value;
            }

            $fp = fopen($dest_path, 'wb');
            if (!$fp) {
                return new WP_Error('local_open', 'No se pudo crear archivo local');
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => $curl_headers,
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => 300,
            ]);
            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($ok === false) {
                @unlink($dest_path);
                return new WP_Error('r2_download_failed', 'Error descargando de R2: ' . curl_strerror($errno));
            }
            if ($http_code === 404) {
                @unlink($dest_path);
                return new WP_Error('r2_not_found', 'Objeto no encontrado en R2.');
            }
            if ($http_code !== 200) {
                @unlink($dest_path);
                return new WP_Error('r2_download_failed', 'Error descargando de R2: HTTP ' . $http_code);
            }
            return true;
        }

        $body = $this->get_object($key);
        if (is_wp_error($body)) {
            return $body;
        }
        if (file_put_contents($dest_path, $body) === false) {
            return new WP_Error('local_write', 'No se pudo escribir archivo local');
        }
        return true;
    }

    public function exists($key) {
        if (!$this->is_configured()) {
            return false;
        }
        $meta = $this->head_object($key);
        return !is_wp_error($meta);
    }

    /**
     * URL prefirmada GET (válida $expires segundos).
     */
    public function presigned_url($key, $expires = 3600) {
        if (!$this->is_configured()) {
            return '';
        }
        $host = wp_parse_url($this->endpoint, PHP_URL_HOST);
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        $credential_scope = $date_stamp . '/' . $this->region . '/s3/aws4_request';
        $canonical_uri = $this->object_canonical_path($key);

        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->access_key . '/' . $credential_scope,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => (string) max(1, (int) $expires),
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonical_query = $this->build_canonical_query($query);

        $canonical_headers = 'host:' . $host . "\n";
        $signed_headers = 'host';
        $payload_hash = 'UNSIGNED-PAYLOAD';

        $canonical_request = implode("\n", [
            'GET',
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signature = $this->signature($date_stamp, $string_to_sign);
        return $this->object_url($key) . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
    }

    private function sign_request($method, $url, $body, $extra_headers = []) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = wp_parse_url($url, PHP_URL_PATH) ?: '/';
        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');
        $payload_hash = hash('sha256', $body);

        $headers = array_merge([
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amz_date,
        ], $extra_headers);

        ksort($headers);
        $canonical_headers = '';
        $signed_header_names = [];
        foreach ($headers as $name => $value) {
            $lname = strtolower($name);
            $canonical_headers .= $lname . ':' . trim($value) . "\n";
            $signed_header_names[] = $lname;
        }
        sort($signed_header_names);
        $signed_headers = implode(';', $signed_header_names);

        $credential_scope = $date_stamp . '/' . $this->region . '/s3/aws4_request';
        $canonical_request = implode("\n", [
            strtoupper($method),
            $path,
            '',
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $string_to_sign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signature = $this->signature($date_stamp, $string_to_sign);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;

        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = $value;
        }
        $out['Authorization'] = $authorization;
        return $out;
    }

    private function signature($date_stamp, $string_to_sign) {
        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $this->secret_key, true);
        $k_region = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        return hash_hmac('sha256', $string_to_sign, $k_signing);
    }

    private function encode_key($key) {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function object_url($key) {
        $key = ltrim($key, '/');
        return $this->endpoint . '/' . rawurlencode($this->bucket) . '/' . $this->encode_key($key);
    }

    private function object_canonical_path($key) {
        return wp_parse_url($this->object_url($key), PHP_URL_PATH) ?: '/';
    }

    /**
     * Query string canónico SigV4 (una sola pasada de URI-encoding por clave/valor).
     */
    private function build_canonical_query(array $params) {
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $parts);
    }
}
