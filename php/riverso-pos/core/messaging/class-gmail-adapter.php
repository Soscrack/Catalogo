<?php
/**
 * Adaptador Gmail API (OAuth2 + history.list).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Gmail_Adapter {

    const TOKEN_OPTION = 'riverso_gmail_oauth';
    const HISTORY_OPTION = 'riverso_gmail_history_id';
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function is_configured() {
        $client = riverso_get_messaging_config('gmail_client_id');
        $secret = riverso_get_messaging_config('gmail_client_secret');
        return $client !== '' && $secret !== '';
    }

    public function is_connected() {
        $tok = $this->get_stored_tokens();
        return !empty($tok['refresh_token']) || riverso_get_messaging_config('gmail_refresh_token') !== '';
    }

    public function redirect_uri() {
        return rest_url('riverso/v1/oauth/gmail');
    }

    public function auth_url($state = '') {
        $params = [
            'client_id' => riverso_get_messaging_config('gmail_client_id'),
            'redirect_uri' => $this->redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state ?: wp_create_nonce('riverso_gmail_oauth'),
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    public function exchange_code($code) {
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 30,
            'body' => [
                'code' => $code,
                'client_id' => riverso_get_messaging_config('gmail_client_id'),
                'client_secret' => riverso_get_messaging_config('gmail_client_secret'),
                'redirect_uri' => $this->redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($json['access_token'])) {
            return new WP_Error('gmail_oauth', $json['error_description'] ?? 'No se obtuvo access_token');
        }
        $stored = $this->get_stored_tokens();
        $stored['access_token'] = $json['access_token'];
        $stored['expires_at'] = time() + (int) ($json['expires_in'] ?? 3500);
        if (!empty($json['refresh_token'])) {
            $stored['refresh_token'] = $json['refresh_token'];
        }
        update_option(self::TOKEN_OPTION, $stored, false);
        return $stored;
    }

    public function get_access_token() {
        $stored = $this->get_stored_tokens();
        if (!empty($stored['access_token']) && !empty($stored['expires_at']) && $stored['expires_at'] > time() + 60) {
            return $stored['access_token'];
        }
        $refresh = $stored['refresh_token'] ?? riverso_get_messaging_config('gmail_refresh_token');
        if (!$refresh) {
            return new WP_Error('gmail_no_refresh', 'Gmail no está conectado. Autoriza OAuth en Inbox.');
        }
        $response = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 30,
            'body' => [
                'client_id' => riverso_get_messaging_config('gmail_client_id'),
                'client_secret' => riverso_get_messaging_config('gmail_client_secret'),
                'refresh_token' => $refresh,
                'grant_type' => 'refresh_token',
            ],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($json['access_token'])) {
            return new WP_Error('gmail_refresh', $json['error_description'] ?? 'No se pudo renovar el token');
        }
        $stored['refresh_token'] = $refresh;
        $stored['access_token'] = $json['access_token'];
        $stored['expires_at'] = time() + (int) ($json['expires_in'] ?? 3500);
        update_option(self::TOKEN_OPTION, $stored, false);
        return $stored['access_token'];
    }

    private function get_stored_tokens() {
        $opt = get_option(self::TOKEN_OPTION, []);
        return is_array($opt) ? $opt : [];
    }

    /**
     * @param array{fecha_desde?:string,fecha_hasta?:string} $args
     * @return array{imported:int,skipped:int,quotes:int}|WP_Error
     */
    public function sync($args = []) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $user = riverso_get_messaging_config('gmail_user', 'rs.riverso@gmail.com');
        $cuenta_id = Riverso_Messaging_Store::ensure_account('email', $user, 'Gmail Compras');

        $fecha_desde = isset($args['fecha_desde']) ? (string) $args['fecha_desde'] : '';
        $fecha_hasta = isset($args['fecha_hasta']) ? (string) $args['fecha_hasta'] : '';
        $has_range = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)
            || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta);

        $history_id = get_option(self::HISTORY_OPTION, '');
        $need_backfill = get_option('riverso_gmail_label_backfill') !== '1';
        $message_ids = [];

        if ($has_range) {
            $q_range = $this->build_gmail_date_query($fecha_desde, $fecha_hasta);
            foreach (['in:inbox', 'is:important', 'in:spam'] as $scope) {
                $list = $this->api_get('/messages', [
                    'maxResults' => 100,
                    'q' => trim($scope . ' ' . $q_range),
                ], $token);
                if (is_wp_error($list)) {
                    return $list;
                }
                foreach ($list['messages'] ?? [] as $m) {
                    if (!empty($m['id'])) {
                        $message_ids[] = $m['id'];
                    }
                }
            }
        } else {
            if ($history_id) {
                $hist = $this->api_get('/history', [
                    'startHistoryId' => $history_id,
                    'historyTypes' => 'messageAdded',
                ], $token);
                if (is_wp_error($hist) && $hist->get_error_code() === 'gmail_404') {
                    $history_id = '';
                } elseif (is_wp_error($hist)) {
                    return $hist;
                } else {
                    foreach ($hist['history'] ?? [] as $h) {
                        foreach ($h['messagesAdded'] ?? [] as $added) {
                            if (!empty($added['message']['id'])) {
                                $message_ids[] = $added['message']['id'];
                            }
                        }
                    }
                    if (!empty($hist['historyId'])) {
                        update_option(self::HISTORY_OPTION, (string) $hist['historyId'], false);
                    }
                }
            }

            if (!$history_id || $need_backfill) {
                foreach (['in:inbox newer_than:14d', 'is:important newer_than:14d', 'in:spam newer_than:14d'] as $q) {
                    $list = $this->api_get('/messages', [
                        'maxResults' => 40,
                        'q' => $q,
                    ], $token);
                    if (is_wp_error($list)) {
                        return $list;
                    }
                    foreach ($list['messages'] ?? [] as $m) {
                        if (!empty($m['id'])) {
                            $message_ids[] = $m['id'];
                        }
                    }
                }
                $profile = $this->api_get('', [], $token);
                if (!is_wp_error($profile) && !empty($profile['historyId'])) {
                    update_option(self::HISTORY_OPTION, (string) $profile['historyId'], false);
                }
            }
        }

        $message_ids = array_unique($message_ids);
        $imported = 0;
        $skipped = 0;
        $quotes = 0;
        $refresh_labels = $need_backfill || $has_range;
        foreach ($message_ids as $mid) {
            $r = $this->ingest_message($mid, $token, $cuenta_id, $refresh_labels);
            if ($r === 'skipped') {
                $skipped++;
            } elseif (is_array($r)) {
                $imported++;
                if (!empty($r['quote'])) {
                    $quotes++;
                }
            }
        }

        if ($need_backfill && !$has_range) {
            update_option('riverso_gmail_label_backfill', '1', false);
        }

        return compact('imported', 'skipped', 'quotes');
    }

    /**
     * Gmail search: after:YYYY/MM/DD before:YYYY/MM/DD (before = día siguiente exclusivo).
     */
    private function build_gmail_date_query($fecha_desde, $fecha_hasta) {
        $parts = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
            $parts[] = 'after:' . str_replace('-', '/', $fecha_desde);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
            $ts = strtotime($fecha_hasta . ' +1 day');
            if ($ts) {
                $parts[] = 'before:' . date('Y/m/d', $ts);
            }
        }
        return implode(' ', $parts);
    }

    private function ingest_message($gmail_id, $token, $cuenta_id, $refresh_labels = false) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . Riverso_Messaging_Store::prefix() . 'messaging_messages WHERE remote_id = %s',
            $gmail_id
        ));
        if ($exists) {
            if ($refresh_labels) {
                $meta = $this->api_get('/messages/' . rawurlencode($gmail_id), [
                    'format' => 'metadata',
                    'metadataHeaders' => 'From',
                ], $token);
                if (!is_wp_error($meta)) {
                    Riverso_Messaging_Store::update_message_gmail_labels(
                        (int) $exists,
                        $meta['labelIds'] ?? []
                    );
                }
            }
            return 'skipped';
        }

        $msg = $this->api_get('/messages/' . rawurlencode($gmail_id), ['format' => 'full'], $token);
        if (is_wp_error($msg)) {
            return $msg;
        }

        $headers = $this->header_map($msg['payload']['headers'] ?? []);
        $from = $headers['From'] ?? '';
        $subject = $headers['Subject'] ?? '';
        $date = !empty($msg['internalDate']) ? gmdate('Y-m-d H:i:s', (int) floor($msg['internalDate'] / 1000)) : current_time('mysql');
        $thread_gmail = $msg['threadId'] ?? $gmail_id;
        $from_email = $this->extract_email($from);
        $body = $this->extract_body($msg['payload'] ?? []);
        $labels = $msg['labelIds'] ?? [];
        $is_spam = riverso_messaging_labels_is_spam($labels);
        $is_important = riverso_messaging_labels_is_important($labels);

        $prov = Riverso_Messaging_Store::match_proveedor_by_email($from_email);
        $tipo = $prov ? 'proveedor' : 'otro';
        $has_purchase = $this->has_purchase_attachment($msg['payload'] ?? []);
        $looks_quote = riverso_messaging_looks_like_quote($subject, $body['text'], $has_purchase, (bool) $prov);
        if ($looks_quote && !$prov) {
            $tipo = 'proveedor';
        }
        if ($is_spam && $tipo === 'otro') {
            $tipo = 'spam';
        }

        $thread_id = Riverso_Messaging_Store::upsert_thread([
            'cuenta_id' => $cuenta_id,
            'canal' => 'email',
            'remote_id' => $thread_gmail,
            'contacto_nombre' => $prov['nombre'] ?? $from,
            'contacto_identificador' => $from_email,
            'tipo_chat' => $tipo,
            'proveedor_id' => $prov['id'] ?? null,
            'last_message_at' => $date,
            'last_preview' => $subject ?: mb_substr($body['text'], 0, 180),
            'increment_unread' => true,
            'is_spam' => $is_spam,
            'is_important' => $is_important,
            'quote_hint' => $looks_quote ? 1 : 0,
        ]);

        $message_id = Riverso_Messaging_Store::insert_message([
            'thread_id' => $thread_id,
            'direction' => 'in',
            'remote_id' => $gmail_id,
            'subject' => $subject,
            'body_text' => $body['text'],
            'body_html' => $body['html'],
            'from_address' => $from,
            'to_address' => $headers['To'] ?? '',
            'sent_at' => $date,
            'gmail_labels' => $labels,
            'payload' => ['snippet' => $msg['snippet'] ?? ''],
        ]);

        $saved_files = $this->save_attachments($msg['payload'] ?? [], $gmail_id, $token, $message_id);
        $quote = null;
        $has_saved_purchase = false;
        foreach ($saved_files as $f) {
            if (riverso_messaging_is_quote_filename($f['filename'] ?? '')) {
                $has_saved_purchase = true;
                break;
            }
        }
        if (riverso_messaging_looks_like_quote($subject, $body['text'], $has_saved_purchase, (bool) $prov)) {
            $quote = $this->maybe_create_quote($thread_id, $message_id, $prov, $saved_files, $subject);
            Riverso_Messaging_Store::upsert_thread([
                'canal' => 'email',
                'remote_id' => $thread_gmail,
                'quote_hint' => 1,
                'cuenta_id' => $cuenta_id,
            ]);
        }

        Riverso_Messaging_Store::notify(
            'Correo nuevo: ' . ($subject ?: $from_email),
            mb_substr($body['text'], 0, 200),
            home_url('/interno/inbox/?thread=' . $thread_id),
            'email'
        );

        if (function_exists('riverso_event')) {
            riverso_event('riverso.message.received', ['thread_id' => $thread_id, 'message_id' => $message_id, 'canal' => 'email']);
        }

        return ['message_id' => $message_id, 'quote' => $quote];
    }

    private function maybe_create_quote($thread_id, $message_id, $prov, $files, $subject) {
        if (!class_exists('Riverso_POS_Received_Quote_Module')) {
            return null;
        }
        $pdf = null;
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'xlsx', 'xls', 'csv', 'txt'], true)) {
                $pdf = $f;
                break;
            }
        }
        if (!$pdf) {
            return null;
        }
        $mod = Riverso_POS_Received_Quote_Module::get_instance();
        if (!method_exists($mod, 'create_from_message')) {
            return null;
        }
        return $mod->create_from_message([
            'mensaje_id' => $message_id,
            'canal' => 'email',
            'proveedor_id' => $prov['id'] ?? null,
            'archivo_path' => $pdf['local_path'],
            'archivo_original' => $pdf['filename'],
            'numero_documento' => $subject,
        ]);
    }

    public function send_reply($thread_id, $to, $subject, $body, $in_reply_to = null) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $from = riverso_get_messaging_config('gmail_user', 'rs.riverso@gmail.com');
        $raw = "From: {$from}\r\nTo: {$to}\r\nSubject: {$subject}\r\n";
        if ($in_reply_to) {
            $raw .= "In-Reply-To: {$in_reply_to}\r\nReferences: {$in_reply_to}\r\n";
        }
        $raw .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $body;
        $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        global $wpdb;
        $thread = $wpdb->get_row($wpdb->prepare(
            'SELECT remote_id FROM ' . Riverso_Messaging_Store::prefix() . 'messaging_threads WHERE id = %d',
            $thread_id
        ), ARRAY_A);

        $payload = ['raw' => $encoded];
        if (!empty($thread['remote_id'])) {
            $payload['threadId'] = $thread['remote_id'];
        }

        $res = $this->api_post('/messages/send', $payload, $token);
        if (is_wp_error($res)) {
            return $res;
        }

        $mid = Riverso_Messaging_Store::insert_message([
            'thread_id' => $thread_id,
            'direction' => 'out',
            'remote_id' => $res['id'] ?? ('out-' . uniqid()),
            'subject' => $subject,
            'body_text' => $body,
            'from_address' => $from,
            'to_address' => $to,
            'sent_at' => current_time('mysql'),
            'status' => 'sent',
        ]);
        Riverso_Messaging_Store::upsert_thread([
            'canal' => 'email',
            'remote_id' => $thread['remote_id'] ?? ('local-' . $thread_id),
            'last_message_at' => current_time('mysql'),
            'last_preview' => mb_substr($body, 0, 180),
            'cuenta_id' => null,
        ]);
        return $mid;
    }

    private function save_attachments($payload, $gmail_id, $token, $message_id) {
        $out = [];
        $parts = $this->flatten_parts($payload);
        riverso_messaging_protect_uploads_dir();
        $base = riverso_messaging_uploads_dir() . '/' . date('Y/m');
        wp_mkdir_p($base);

        foreach ($parts as $part) {
            $filename = $part['filename'] ?? '';
            $att_id = $part['body']['attachmentId'] ?? '';
            if ($filename === '' || $att_id === '') {
                continue;
            }
            $att = $this->api_get('/messages/' . rawurlencode($gmail_id) . '/attachments/' . rawurlencode($att_id), [], $token);
            if (is_wp_error($att) || empty($att['data'])) {
                continue;
            }
            $bin = $this->b64url_decode($att['data']);
            $safe = wp_unique_filename($base, sanitize_file_name($filename));
            $path = $base . '/' . $safe;
            file_put_contents($path, $bin);
            $r2_key = null;
            if (class_exists('Riverso_R2_Client')) {
                $r2 = new Riverso_R2_Client();
                if ($r2->is_configured()) {
                    $key = 'inbox/email/' . date('Y/m') . '/' . $safe;
                    $put = $r2->put_object($key, $bin, $part['mimeType'] ?? 'application/octet-stream');
                    if (!is_wp_error($put)) {
                        $r2_key = $key;
                    }
                }
            }
            Riverso_Messaging_Store::add_attachment(
                $message_id,
                $filename,
                $part['mimeType'] ?? '',
                strlen($bin),
                $path,
                $r2_key
            );
            $out[] = ['filename' => $filename, 'local_path' => $path, 'mime' => $part['mimeType'] ?? ''];
        }
        return $out;
    }

    private function has_purchase_attachment($payload) {
        foreach ($this->flatten_parts($payload) as $part) {
            if (riverso_messaging_is_quote_filename($part['filename'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    private function has_file_attachment($payload) {
        foreach ($this->flatten_parts($payload) as $part) {
            if (!empty($part['filename'])) {
                return true;
            }
        }
        return false;
    }

    private function flatten_parts($payload) {
        $out = [];
        $stack = [$payload];
        while ($stack) {
            $p = array_pop($stack);
            $out[] = $p;
            foreach ($p['parts'] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        return $out;
    }

    private function extract_body($payload) {
        $text = '';
        $html = '';
        foreach ($this->flatten_parts($payload) as $part) {
            $mime = $part['mimeType'] ?? '';
            $data = $part['body']['data'] ?? '';
            if ($data === '') {
                continue;
            }
            $decoded = $this->b64url_decode($data);
            if ($mime === 'text/plain' && $text === '') {
                $text = $decoded;
            } elseif ($mime === 'text/html' && $html === '') {
                $html = $decoded;
            }
        }
        if ($text === '' && $html !== '') {
            $text = wp_strip_all_tags($html);
        }
        return ['text' => $text, 'html' => $html];
    }

    private function header_map($headers) {
        $map = [];
        foreach ($headers as $h) {
            if (!empty($h['name'])) {
                $map[$h['name']] = $h['value'] ?? '';
            }
        }
        return $map;
    }

    private function extract_email($from) {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($from));
    }

    private function b64url_decode($data) {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($data);
    }

    private function api_get($path, $query, $token) {
        $url = self::API . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $res = wp_remote_get($url, [
            'timeout' => 60,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        return $this->parse_api($res);
    }

    private function api_post($path, $body, $token) {
        $res = wp_remote_post(self::API . $path, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);
        return $this->parse_api($res);
    }

    private function parse_api($res) {
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if ($code === 404) {
            return new WP_Error('gmail_404', $json['error']['message'] ?? 'Not found');
        }
        if ($code < 200 || $code >= 300) {
            return new WP_Error('gmail_api', $json['error']['message'] ?? ('HTTP ' . $code));
        }
        return is_array($json) ? $json : [];
    }
}
