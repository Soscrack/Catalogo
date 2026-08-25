<?php
/**
 * Cliente HTTP FACTO (API v1 / apifacto.com).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Facto_Client {

    const TOKEN_TRANSIENT = 'riverso_facto_access_token';
    const TOKEN_TTL       = 3300; // 55 min (token FACTO ~60 min)

    private $base_url;
    private $client_id;
    private $client_secret;
    private $username;
    private $password;

    public function __construct() {
        $this->base_url      = rtrim((string) riverso_get_facto_config('base_url', 'https://apifacto.com/v1'), '/');
        $this->client_id     = (string) riverso_get_facto_config('client_id', '');
        $this->client_secret = (string) riverso_get_facto_config('client_secret', '');
        $this->username      = (string) riverso_get_facto_config('username', '');
        $this->password      = (string) riverso_get_facto_config('password', '');
    }

    public function is_configured() {
        return $this->client_id !== '' && $this->client_secret !== ''
            && $this->username !== '' && $this->password !== '';
    }

    /**
     * @return string|WP_Error
     */
    public function get_token($force = false) {
        if (!$this->is_configured()) {
            return new WP_Error('facto_not_configured', 'Credenciales FACTO incompletas.');
        }
        if (!$force) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = wp_remote_post($this->base_url . '/auth', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'RiversoPOS/1.0 (+https://riverso.cl)',
            ],
            'body' => wp_json_encode([
                'grant_type'    => 'password',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'username'      => $this->username,
                'password'      => $this->password,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || empty($body['access_token'])) {
            return new WP_Error(
                'facto_auth_failed',
                'Auth FACTO falló HTTP ' . $code,
                ['body' => $body]
            );
        }

        $ttl = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 300) : self::TOKEN_TTL;
        set_transient(self::TOKEN_TRANSIENT, $body['access_token'], $ttl);
        return $body['access_token'];
    }

    /**
     * @return array|WP_Error Decoded JSON
     */
    public function request($method, $path, $payload = null, $query = []) {
        $token = $this->get_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $url = $this->base_url . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'RiversoPOS/1.0 (+https://riverso.cl)',
            ],
        ];
        if ($payload !== null) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true);

        if ($code === 401) {
            delete_transient(self::TOKEN_TRANSIENT);
            $token = $this->get_token(true);
            if (is_wp_error($token)) {
                return $token;
            }
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);
            $body = json_decode($raw, true);
        }

        if ($code === 429) {
            return new WP_Error('facto_rate_limited', 'FACTO rate limit (429). Reintentar en 30s.', [
                'retry_after' => 30,
                'body'        => $body,
            ]);
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('facto_http_error', 'FACTO HTTP ' . $code, [
                'status' => $code,
                'body'   => $body !== null ? $body : $raw,
            ]);
        }

        return is_array($body) ? $body : [];
    }

    public function list_products($query = []) {
        return $this->request('GET', 'products', null, $query);
    }

    public function list_inbox_documents($query = []) {
        return $this->request('GET', 'inbox_documents', null, $query);
    }

    public function get_inbox_document($inbox_document_id) {
        return $this->request('GET', 'inbox_documents/' . absint($inbox_document_id));
    }

    public function get_product($product_id) {
        return $this->request('GET', 'products/' . absint($product_id));
    }

    public function create_product(array $payload) {
        return $this->request('POST', 'products', $payload);
    }

    public function update_product($product_id, array $payload) {
        return $this->request('PUT', 'products/' . absint($product_id), $payload);
    }

    /**
     * Extrae colección HAL `_embedded.$key` o array plano.
     */
    public static function embed_collection($response, $key) {
        if (!is_array($response)) {
            return [];
        }
        if (isset($response['_embedded'][$key]) && is_array($response['_embedded'][$key])) {
            return $response['_embedded'][$key];
        }
        if (isset($response[$key]) && is_array($response[$key])) {
            return $response[$key];
        }
        return [];
    }

    /** Alias por compatibilidad interna. */
    public static function embed_collection_hal($response, $key) {
        return self::embed_collection($response, $key);
    }
}
