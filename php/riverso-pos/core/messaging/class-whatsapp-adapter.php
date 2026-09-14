<?php
/**
 * WhatsApp Cloud API: webhook + envío + descarga de media.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Whatsapp_Adapter {

    const GRAPH = 'https://graph.facebook.com/v21.0';
    const QUEUE_OPTION = 'riverso_wa_webhook_queue';

    public function is_configured() {
        return riverso_get_messaging_config('wa_access_token') !== ''
            && riverso_get_messaging_config('wa_phone_number_id') !== '';
    }

    public function verify_token_ok($token) {
        $expected = (string) riverso_get_messaging_config('wa_verify_token', 'riverso-wa-verify');
        return hash_equals($expected, (string) $token);
    }

    public function valid_signature($raw_body, $header) {
        $secret = riverso_get_messaging_config('wa_app_secret');
        if ($secret === '') {
            return true;
        }
        if (!$header || strpos($header, 'sha256=') !== 0) {
            return false;
        }
        $given = substr($header, 7);
        $calc = hash_hmac('sha256', $raw_body, $secret);
        return hash_equals($calc, $given);
    }

    public function enqueue_payload($payload) {
        $q = get_option(self::QUEUE_OPTION, []);
        if (!is_array($q)) {
            $q = [];
        }
        $q[] = [
            'at' => time(),
            'payload' => $payload,
        ];
        $q = array_slice($q, -80);
        update_option(self::QUEUE_OPTION, $q, false);
    }

    /**
     * Procesa cola de webhooks (cron).
     */
    public function process_queue() {
        $q = get_option(self::QUEUE_OPTION, []);
        if (!is_array($q) || !$q) {
            return ['processed' => 0];
        }
        update_option(self::QUEUE_OPTION, [], false);
        $n = 0;
        foreach ($q as $item) {
            $this->ingest_payload($item['payload'] ?? []);
            $n++;
        }
        return ['processed' => $n];
    }

    public function ingest_payload($payload) {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return;
        }
        $phone_id = riverso_get_messaging_config('wa_phone_number_id');
        $cuenta_id = Riverso_Messaging_Store::ensure_account('whatsapp', $phone_id ?: 'wa', 'WhatsApp Riverso');

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                foreach ($value['messages'] ?? [] as $msg) {
                    $this->ingest_message($msg, $value['contacts'][0] ?? [], $cuenta_id);
                }
            }
        }
    }

    private function ingest_message($msg, $contact, $cuenta_id) {
        $wa_id = $msg['id'] ?? '';
        $from = $msg['from'] ?? '';
        if ($wa_id === '' || $from === '') {
            return;
        }
        $name = $contact['profile']['name'] ?? $from;
        $type = $msg['type'] ?? 'text';
        $text = '';
        if ($type === 'text') {
            $text = $msg['text']['body'] ?? '';
        } elseif ($type === 'image' && !empty($msg['image']['caption'])) {
            $text = $msg['image']['caption'];
        } elseif ($type === 'document' && !empty($msg['document']['caption'])) {
            $text = $msg['document']['caption'];
        }

        $prov = Riverso_Messaging_Store::match_proveedor_by_phone($from);
        $tipo = $prov ? 'proveedor' : 'otro';
        $ts = !empty($msg['timestamp']) ? gmdate('Y-m-d H:i:s', (int) $msg['timestamp']) : current_time('mysql');

        $thread_id = Riverso_Messaging_Store::upsert_thread([
            'cuenta_id' => $cuenta_id,
            'canal' => 'whatsapp',
            'remote_id' => $from,
            'contacto_nombre' => $prov['nombre'] ?? $name,
            'contacto_identificador' => $from,
            'tipo_chat' => $tipo,
            'proveedor_id' => $prov['id'] ?? null,
            'last_message_at' => $ts,
            'last_preview' => $text ?: ('[' . $type . ']'),
            'increment_unread' => true,
        ]);

        $message_id = Riverso_Messaging_Store::insert_message([
            'thread_id' => $thread_id,
            'direction' => 'in',
            'remote_id' => $wa_id,
            'body_text' => $text,
            'from_address' => $from,
            'sent_at' => $ts,
            'payload' => $msg,
        ]);

        $files = [];
        if (in_array($type, ['document', 'image'], true)) {
            $media = $msg[$type] ?? [];
            $saved = $this->download_media($media['id'] ?? '', $media['filename'] ?? ($type . '.bin'), $media['mime_type'] ?? '', $message_id);
            if ($saved) {
                $files[] = $saved;
            }
        }

        $has_file = false;
        foreach ($files as $f) {
            if (riverso_messaging_is_quote_filename($f['filename'] ?? '')) {
                $has_file = true;
                break;
            }
        }
        $suggested = riverso_messaging_suggest_quote_type($text, $text, $has_file, (bool) $prov);
        if ($suggested) {
            Riverso_Messaging_Store::upsert_thread([
                'canal' => 'whatsapp',
                'remote_id' => $from,
                'quote_hint' => 1,
                'cuenta_id' => $cuenta_id,
            ]);
        }
        if ($suggested && class_exists('Riverso_POS_Received_Quote_Module')) {
            $mod = Riverso_POS_Received_Quote_Module::get_instance();
            if (method_exists($mod, 'create_from_message')) {
                $file = $files[0] ?? null;
                $mod->create_from_message([
                    'mensaje_id' => $message_id,
                    'canal' => 'whatsapp',
                    'proveedor_id' => $prov['id'] ?? null,
                    'archivo_path' => $file['local_path'] ?? '',
                    'archivo_original' => $file['filename'] ?? '',
                    'numero_documento' => $text,
                    'tipo_doc' => $suggested,
                    'tipo_confirmado' => 0,
                ]);
            }
        }

        Riverso_Messaging_Store::notify(
            'WhatsApp: ' . ($name ?: $from),
            mb_substr($text ?: '[' . $type . ']', 0, 200),
            home_url('/interno/inbox/?thread=' . $thread_id),
            'whatsapp'
        );
    }

    private function download_media($media_id, $filename, $mime, $message_id) {
        if ($media_id === '' || !$this->is_configured()) {
            return null;
        }
        $token = riverso_get_messaging_config('wa_access_token');
        $meta = wp_remote_get(self::GRAPH . '/' . rawurlencode($media_id), [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($meta)) {
            return null;
        }
        $json = json_decode(wp_remote_retrieve_body($meta), true);
        $url = $json['url'] ?? '';
        if ($url === '') {
            return null;
        }
        $bin_res = wp_remote_get($url, [
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($bin_res)) {
            return null;
        }
        $bin = wp_remote_retrieve_body($bin_res);
        if ($bin === '') {
            return null;
        }
        riverso_messaging_protect_uploads_dir();
        $base = riverso_messaging_uploads_dir() . '/' . date('Y/m');
        wp_mkdir_p($base);
        $safe = wp_unique_filename($base, sanitize_file_name($filename ?: ($media_id . '.bin')));
        $path = $base . '/' . $safe;
        file_put_contents($path, $bin);
        $r2_key = null;
        if (class_exists('Riverso_R2_Client')) {
            $r2 = new Riverso_R2_Client();
            if ($r2->is_configured()) {
                $key = 'inbox/whatsapp/' . date('Y/m') . '/' . $safe;
                $put = $r2->put_object($key, $bin, $mime ?: 'application/octet-stream');
                if (!is_wp_error($put)) {
                    $r2_key = $key;
                }
            }
        }
        Riverso_Messaging_Store::add_attachment($message_id, $filename, $mime, strlen($bin), $path, $r2_key);
        return ['filename' => $filename, 'local_path' => $path, 'mime' => $mime];
    }

    /**
     * @return array|WP_Error
     */
    public function send_text($to_phone, $text) {
        if (!$this->is_configured()) {
            return new WP_Error('wa_not_configured', 'WhatsApp Cloud API no está configurada.');
        }
        $to = preg_replace('/\D+/', '', $to_phone);
        $phone_id = riverso_get_messaging_config('wa_phone_number_id');
        $token = riverso_get_messaging_config('wa_access_token');
        $res = wp_remote_post(self::GRAPH . '/' . rawurlencode($phone_id) . '/messages', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $text],
            ]),
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('wa_send', $json['error']['message'] ?? ('HTTP ' . $code));
        }
        return $json;
    }

    public function setup_status() {
        return [
            'configured' => $this->is_configured(),
            'phone_number_id' => riverso_get_messaging_config('wa_phone_number_id'),
            'waba_id' => riverso_get_messaging_config('wa_waba_id'),
            'app_id' => riverso_get_messaging_config('wa_app_id'),
            'has_token' => riverso_get_messaging_config('wa_access_token') !== '',
            'has_app_secret' => riverso_get_messaging_config('wa_app_secret') !== '',
            'verify_token' => riverso_get_messaging_config('wa_verify_token', 'riverso-wa-verify'),
            'webhook_url' => rest_url('riverso/v1/webhooks/whatsapp'),
            'steps' => [
                'Vincular WABA y número en Meta Developers.',
                'Crear System User admin y token permanente (whatsapp_business_messaging, whatsapp_business_management).',
                'Definir RIVERSO_WA_ACCESS_TOKEN, RIVERSO_WA_PHONE_NUMBER_ID, RIVERSO_WA_APP_SECRET en wp-config.php.',
                'POST /{PHONE_NUMBER_ID}/register con PIN de 6 dígitos.',
                'POST /{WABA_ID}/subscribed_apps.',
                'Webhook GET/POST en ' . rest_url('riverso/v1/webhooks/whatsapp') . ' (verify token: ' . riverso_get_messaging_config('wa_verify_token', 'riverso-wa-verify') . ').',
                'Campos: messages, message_status.',
                'Modo test: agregar números testers. Producción: App Review.',
                'Respuestas libres solo 24 h; después plantilla aprobada.',
            ],
        ];
    }
}
