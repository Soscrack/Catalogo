<?php
/**
 * Cliente Google Gemini para extracción de documentos escaneados.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Gemini_Client {

    private $api_key;
    private $model;
    private $last_tokens_in = 0;
    private $last_tokens_out = 0;

    public function __construct() {
        $this->api_key = (string) riverso_get_scan_config('gemini_api_key', '');
        $this->model   = (string) riverso_get_scan_config('gemini_model', 'gemini-3.6-flash');
    }

    public function is_configured() {
        return $this->api_key !== '';
    }

    public function get_model() {
        return $this->model;
    }

    public function get_last_usage() {
        return [
            'tokens_in'  => $this->last_tokens_in,
            'tokens_out' => $this->last_tokens_out,
        ];
    }

    /**
     * Extrae documentos de un archivo (PDF o imagen).
     *
     * @param string $file_path
     * @param string $mime
     * @param string $prompt
     * @param array  $response_schema
     * @return array|WP_Error
     */
    public function extract_document($file_path, $mime, $prompt, $response_schema) {
        if (!$this->is_configured()) {
            return new WP_Error('gemini_not_configured', 'Gemini API no está configurada.');
        }
        if (!is_readable($file_path)) {
            return new WP_Error('file_unreadable', 'No se puede leer el archivo.');
        }

        $bytes = filesize($file_path);
        $max_inline = 15 * 1024 * 1024;
        if ($bytes > $max_inline) {
            return new WP_Error('file_too_large', 'Archivo demasiado grande para procesamiento inline (máx 15 MB).');
        }

        $data = base64_encode(file_get_contents($file_path));
        return $this->generate_with_inline($mime, $data, $prompt, $response_schema);
    }

    /**
     * Segunda pasada acotada a un rango de páginas.
     */
    public function extract_page_range($file_path, $mime, $prompt, $response_schema) {
        return $this->extract_document($file_path, $mime, $prompt, $response_schema);
    }

    private function generate_with_inline($mime, $base64_data, $prompt, $response_schema) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent';

        $body = [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => $base64_data]],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $response_schema,
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 180,
        ]);

        return $this->parse_response($response);
    }

    /**
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    private function parse_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $code);
            return new WP_Error('gemini_api_error', 'Error Gemini: ' . $msg, ['raw' => $raw]);
        }

        $this->last_tokens_in = (int) ($json['usageMetadata']['promptTokenCount'] ?? 0);
        $this->last_tokens_out = (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0);

        $text = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (!empty($part['text'])) {
                $text .= $part['text'];
            }
        }
        if ($text === '') {
            return new WP_Error('gemini_empty', 'Gemini no devolvió contenido.');
        }

        $parsed = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('gemini_json', 'Respuesta JSON inválida de Gemini.');
        }
        return $parsed;
    }

    /**
     * Esquema JSON para responseSchema de Gemini.
     */
    public static function extraction_schema() {
        $item_schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'numero'          => ['type' => 'INTEGER'],
                'codigo'          => ['type' => 'STRING', 'nullable' => true],
                'descripcion'     => ['type' => 'STRING'],
                'cantidad'        => ['type' => 'NUMBER'],
                'unidad'          => ['type' => 'STRING', 'nullable' => true],
                'precio_unitario' => ['type' => 'NUMBER'],
                'monto_total'     => ['type' => 'NUMBER'],
                'descuento_pct'   => ['type' => 'NUMBER', 'nullable' => true],
                'confianza'       => ['type' => 'NUMBER'],
            ],
            'required' => ['numero', 'descripcion', 'cantidad', 'precio_unitario', 'monto_total'],
        ];

        $ref_schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'tipo'  => ['type' => 'STRING'],
                'folio' => ['type' => 'STRING'],
                'fecha' => ['type' => 'STRING', 'nullable' => true],
                'razon' => ['type' => 'STRING', 'nullable' => true],
            ],
            'required' => ['tipo', 'folio'],
        ];

        $doc_schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'pagina_inicio'           => ['type' => 'INTEGER'],
                'pagina_fin'              => ['type' => 'INTEGER'],
                'tipo_documento'          => ['type' => 'STRING'],
                'tipo_dte'                => ['type' => 'INTEGER', 'nullable' => true],
                'folio'                   => ['type' => 'STRING', 'nullable' => true],
                'emisor'                  => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'rut'          => ['type' => 'STRING', 'nullable' => true],
                        'razon_social' => ['type' => 'STRING', 'nullable' => true],
                        'giro'         => ['type' => 'STRING', 'nullable' => true],
                        'direccion'    => ['type' => 'STRING', 'nullable' => true],
                        'comuna'       => ['type' => 'STRING', 'nullable' => true],
                    ],
                ],
                'receptor'                => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'rut'          => ['type' => 'STRING', 'nullable' => true],
                        'razon_social' => ['type' => 'STRING', 'nullable' => true],
                        'giro'         => ['type' => 'STRING', 'nullable' => true],
                        'direccion'    => ['type' => 'STRING', 'nullable' => true],
                        'comuna'       => ['type' => 'STRING', 'nullable' => true],
                    ],
                ],
                'fecha_emision'           => ['type' => 'STRING', 'nullable' => true],
                'fecha_vencimiento'       => ['type' => 'STRING', 'nullable' => true],
                'forma_pago'              => ['type' => 'STRING', 'nullable' => true],
                'items'                   => ['type' => 'ARRAY', 'items' => $item_schema],
                'totales'                 => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'neto'     => ['type' => 'NUMBER'],
                        'exento'   => ['type' => 'NUMBER'],
                        'iva'      => ['type' => 'NUMBER'],
                        'flete'    => ['type' => 'NUMBER'],
                        'total'    => ['type' => 'NUMBER'],
                        'tasa_iva' => ['type' => 'NUMBER'],
                    ],
                ],
                'referencias'             => ['type' => 'ARRAY', 'items' => $ref_schema],
                'anotaciones_manuscritas' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'sellos_recepcion'        => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'confianza_global'        => ['type' => 'NUMBER'],
                'campos_dudosos'          => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['pagina_inicio', 'pagina_fin', 'tipo_documento', 'items', 'totales', 'confianza_global'],
        ];

        return [
            'type'       => 'OBJECT',
            'properties' => [
                'documentos' => [
                    'type'  => 'ARRAY',
                    'items' => $doc_schema,
                ],
            ],
            'required' => ['documentos'],
        ];
    }
}
