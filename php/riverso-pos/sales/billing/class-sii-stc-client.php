<?php
/**
 * Cliente público SII · Situación Tributaria de Terceros (stc/noauthz).
 *
 * Endpoint JSON interno de la SPA:
 * POST https://www2.sii.cl/app/stc/recurso/v1/consulta/getConsultaData/
 *
 * Entrega razón social y actividades económicas. No entrega dirección.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Sii_Stc_Client {

    const ENDPOINT = 'https://www2.sii.cl/app/stc/recurso/v1/consulta/getConsultaData/';
    const WARM_URL = 'https://www2.sii.cl/stc/noauthz';
    const TTL_SECONDS = 604800; // 7 días
    const TIMEOUT = 12;
    const COOLDOWN_SECONDS = 2;

    /** @var Riverso_Receiver_Design_Repository|null */
    private $store;

    public function __construct($store = null) {
        $this->store = $store;
    }

    /**
     * @param string $rut
     * @param bool   $force_refresh
     * @return array|WP_Error Normalized payload or error.
     */
    public function lookup($rut, $force_refresh = false) {
        $parts = $this->split_rut($rut);
        if (is_wp_error($parts)) {
            return $parts;
        }

        $display = $parts['rut'] . '-' . $parts['dv'];
        if (!$force_refresh && $this->store) {
            $cached = $this->store->get_sii_cache($display);
            if ($cached) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $user_id = get_current_user_id();
        $lock_key = 'riverso_sii_stc_cd_' . $user_id;
        if ($user_id > 0 && get_transient($lock_key)) {
            $stale = $this->store ? $this->store->get_sii_cache($display, true) : null;
            if ($stale) {
                $stale['from_cache'] = true;
                return $stale;
            }
            return new WP_Error('sii_cooldown', 'Espera un momento antes de consultar el SII otra vez.');
        }

        $raw = $this->fetch_raw($parts['rut'], $parts['dv']);
        if (is_wp_error($raw)) {
            return $raw;
        }

        if (!empty($raw['captchaInvalido'])) {
            return new WP_Error('sii_captcha', 'El SII pidió captcha. Intenta más tarde.');
        }
        if (empty($raw['registrado'])) {
            return new WP_Error('sii_not_found', 'RUT no registrado en el SII.');
        }

        $normalized = $this->normalize($raw, $display);
        if ($this->store) {
            $this->store->set_sii_cache($display, $normalized);
        }
        if ($user_id > 0) {
            set_transient($lock_key, 1, self::COOLDOWN_SECONDS);
        }
        $normalized['from_cache'] = false;
        return $normalized;
    }

    /**
     * @param string $rut_num
     * @param string $dv
     * @return array|WP_Error
     */
    private function fetch_raw($rut_num, $dv) {
        $cookies = $this->warm_session();

        $args = [
            'timeout' => self::TIMEOUT,
            'redirection' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
                'Origin' => 'https://www2.sii.cl',
                'Referer' => 'https://www2.sii.cl/stc/noauthz/consulta',
            ],
            'body' => wp_json_encode([
                'rut' => $rut_num,
                'dv' => $dv,
                'reAction' => 'consultaSTC',
                'reToken' => ' ',
            ]),
        ];
        if ($cookies !== '') {
            $args['headers']['Cookie'] = $cookies;
        }

        $resp = wp_remote_post(self::ENDPOINT, $args);
        if (is_wp_error($resp)) {
            return new WP_Error('sii_http', 'No se pudo contactar al SII: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code === 403 || $code === 429) {
            return new WP_Error('sii_blocked', 'El SII rechazó la consulta (HTTP ' . $code . '). Intenta más tarde.');
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('sii_http', 'Respuesta SII inesperada (HTTP ' . $code . ').');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('sii_parse', 'El SII no devolvió JSON válido.');
        }
        return $data;
    }

    /**
     * @return string Cookie header value
     */
    private function warm_session() {
        $resp = wp_remote_get(self::WARM_URL, [
            'timeout' => 8,
            'redirection' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
        ]);
        if (is_wp_error($resp)) {
            return '';
        }
        $set = wp_remote_retrieve_header($resp, 'set-cookie');
        if (!$set) {
            return '';
        }
        $parts = [];
        $list = is_array($set) ? $set : [$set];
        foreach ($list as $line) {
            $chunk = explode(';', (string) $line, 2);
            if (!empty($chunk[0])) {
                $parts[] = trim($chunk[0]);
            }
        }
        return implode('; ', $parts);
    }

    /**
     * @param array  $raw
     * @param string $display_rut
     * @return array<string, mixed>
     */
    private function normalize(array $raw, $display_rut) {
        $actividades = [];
        $giros = isset($raw['girosNegocio']) && is_array($raw['girosNegocio']) ? $raw['girosNegocio'] : [];
        foreach ($giros as $g) {
            if (!is_array($g)) {
                continue;
            }
            $codigo = trim((string) ($g['codigo'] ?? ''));
            $glosa = trim((string) ($g['descripcion'] ?? ''));
            if ($codigo === '' && $glosa === '') {
                continue;
            }
            $cat = (string) ($g['categoriaTributaria'] ?? '');
            $cat_label = $cat === '1' ? 'Primera' : ($cat === '2' ? 'Segunda' : $cat);
            $afecta = strtoupper((string) ($g['indicadorAfectoIva'] ?? '')) === 'S';
            $actividades[] = [
                'codigo' => $codigo,
                'glosa' => $glosa,
                'categoria' => $cat_label,
                'afecta_iva' => $afecta,
                'fecha_inicio' => (string) ($g['fechaInicio'] ?? ''),
            ];
        }

        return [
            'rut' => $display_rut,
            'razon_social' => trim((string) ($raw['nombre'] ?? '')),
            'inicio_actividades' => !empty($raw['inicioActividades']),
            'fecha_inicio_actividades' => (string) ($raw['fechaInicioActividades'] ?? ''),
            'actividades' => $actividades,
            'fetched_at' => current_time('mysql'),
        ];
    }

    /**
     * @param string $rut
     * @return array{rut:string,dv:string}|WP_Error
     */
    private function split_rut($rut) {
        $clean = preg_replace('/[^0-9kK]/', '', (string) $rut);
        if (!is_string($clean) || strlen($clean) < 2) {
            return new WP_Error('sii_rut', 'RUT inválido.');
        }
        $dv = strtoupper(substr($clean, -1));
        $num = substr($clean, 0, -1);
        $num = ltrim($num, '0');
        if ($num === '' || !preg_match('/^[0-9]+$/', $num) || !preg_match('/^[0-9K]$/', $dv)) {
            return new WP_Error('sii_rut', 'RUT inválido.');
        }
        return ['rut' => $num, 'dv' => $dv];
    }
}
