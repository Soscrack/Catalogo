<?php
/**
 * Módulo Inbox unificado (correo + WhatsApp).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-messaging-store.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-gmail-adapter.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-whatsapp-adapter.php';

class Riverso_Messaging_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_inbox_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_inbox_thread', [$this, 'ajax_thread']);
        add_action('wp_ajax_riverso_inbox_set_tipo', [$this, 'ajax_set_tipo']);
        add_action('wp_ajax_riverso_inbox_mark_reviewed', [$this, 'ajax_mark_reviewed']);
        add_action('wp_ajax_riverso_inbox_reply', [$this, 'ajax_reply']);
        add_action('wp_ajax_riverso_inbox_sync', [$this, 'ajax_sync']);
        add_action('wp_ajax_riverso_inbox_gmail_auth', [$this, 'ajax_gmail_auth']);
        add_action('wp_ajax_riverso_inbox_status', [$this, 'ajax_status']);
        add_action('wp_ajax_riverso_inbox_create_quote', [$this, 'ajax_create_quote']);
        add_action('wp_ajax_riverso_inbox_send_claim', [$this, 'ajax_send_claim']);
        add_action('wp_ajax_riverso_inbox_mark_notification', [$this, 'ajax_mark_notification']);
        add_action('wp_ajax_riverso_inbox_download_attachment', [$this, 'ajax_download_attachment']);

        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('riverso_messaging_poll', [$this, 'cron_poll']);
        if (!wp_next_scheduled('riverso_messaging_poll')) {
            wp_schedule_event(time() + 120, 'riverso_five_minutes', 'riverso_messaging_poll');
        }
    }

    public function cron_schedules($schedules) {
        $schedules['riverso_five_minutes'] = [
            'interval' => 300,
            'display' => 'Riverso cada 5 minutos',
        ];
        return $schedules;
    }

    public function cron_poll() {
        $gmail = new Riverso_Gmail_Adapter();
        if ($gmail->is_connected()) {
            $gmail->sync();
        }
        $wa = new Riverso_Whatsapp_Adapter();
        $wa->process_queue();
    }

    private function can_view() {
        return current_user_can('riverso_view_inbox')
            || current_user_can('riverso_view_received_quotes')
            || current_user_can('manage_options');
    }

    private function can_manage() {
        return current_user_can('riverso_manage_inbox')
            || current_user_can('riverso_edit_received_quotes')
            || current_user_can('manage_options');
    }

    public function ajax_status() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $gmail = new Riverso_Gmail_Adapter();
        $wa = new Riverso_Whatsapp_Adapter();
        wp_send_json_success([
            'gmail' => [
                'configured' => $gmail->is_configured(),
                'connected' => $gmail->is_connected(),
                'user' => riverso_get_messaging_config('gmail_user'),
                'auth_url' => $gmail->is_configured() ? $gmail->auth_url() : '',
            ],
            'whatsapp' => $wa->setup_status(),
        ]);
    }

    public function ajax_gmail_auth() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $gmail = new Riverso_Gmail_Adapter();
        if (!$gmail->is_configured()) {
            wp_send_json_error(['message' => 'Faltan RIVERSO_GMAIL_CLIENT_ID / SECRET']);
        }
        wp_send_json_success(['url' => $gmail->auth_url()]);
    }

    public function ajax_sync() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $fecha_desde = isset($_POST['fecha_desde']) ? sanitize_text_field(wp_unslash($_POST['fecha_desde'])) : '';
        $fecha_hasta = isset($_POST['fecha_hasta']) ? sanitize_text_field(wp_unslash($_POST['fecha_hasta'])) : '';
        $gmail = new Riverso_Gmail_Adapter();
        $g = $gmail->is_connected()
            ? $gmail->sync([
                'fecha_desde' => $fecha_desde,
                'fecha_hasta' => $fecha_hasta,
            ])
            : ['imported' => 0, 'skipped' => 0, 'quotes' => 0, 'note' => 'gmail_offline'];
        if (is_wp_error($g)) {
            wp_send_json_error(['message' => $g->get_error_message()]);
        }
        $wa = (new Riverso_Whatsapp_Adapter())->process_queue();
        wp_send_json_success(['gmail' => $g, 'whatsapp' => $wa]);
    }

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $p = Riverso_Messaging_Store::prefix();
        $tipo = isset($_POST['tipo_chat']) ? sanitize_text_field(wp_unslash($_POST['tipo_chat'])) : '';
        $canal = isset($_POST['canal']) ? sanitize_text_field(wp_unslash($_POST['canal'])) : '';
        $buscar = isset($_POST['buscar']) ? sanitize_text_field(wp_unslash($_POST['buscar'])) : '';
        $solo_no_leidos = !empty($_POST['unread']);
        $folder = isset($_POST['folder']) ? sanitize_key(wp_unslash($_POST['folder'])) : 'inbox';
        if (!in_array($folder, ['inbox', 'important', 'spam', 'quotes', 'all'], true)) {
            $folder = 'inbox';
        }

        $where = ['1=1'];
        $params = [];
        if ($folder === 'inbox') {
            $where[] = '(t.is_spam = 0 AND t.tipo_chat <> %s)';
            $params[] = 'spam';
        } elseif ($folder === 'important') {
            $where[] = 't.is_important = 1';
        } elseif ($folder === 'spam') {
            $where[] = '(t.is_spam = 1 OR t.tipo_chat = %s)';
            $params[] = 'spam';
        } elseif ($folder === 'quotes') {
            $where[] = "(t.quote_hint = 1 OR EXISTS (
                SELECT 1 FROM {$p}cotizaciones_recibidas c
                INNER JOIN {$p}messaging_messages mq ON mq.id = c.origen_mensaje_id
                WHERE mq.thread_id = t.id
            ))";
        }
        if ($tipo !== '') {
            $where[] = 't.tipo_chat = %s';
            $params[] = $tipo;
        }
        if ($canal !== '') {
            $where[] = 't.canal = %s';
            $params[] = $canal;
        }
        if ($solo_no_leidos) {
            $where[] = 't.unread_count > 0';
        }
        if ($buscar !== '') {
            $like = '%' . $wpdb->esc_like($buscar) . '%';
            $where[] = '(t.contacto_nombre LIKE %s OR t.contacto_identificador LIKE %s OR t.last_preview LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $fecha_desde = isset($_POST['fecha_desde']) ? sanitize_text_field(wp_unslash($_POST['fecha_desde'])) : '';
        $fecha_hasta = isset($_POST['fecha_hasta']) ? sanitize_text_field(wp_unslash($_POST['fecha_hasta'])) : '';
        if ($fecha_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) {
            $where[] = 't.last_message_at >= %s';
            $params[] = $fecha_desde . ' 00:00:00';
        }
        if ($fecha_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) {
            $where[] = 't.last_message_at <= %s';
            $params[] = $fecha_hasta . ' 23:59:59';
        }
        $sql = "SELECT t.*, p.nombre AS proveedor_nombre,
                    (SELECT COUNT(*) FROM {$p}cotizaciones_recibidas c
                     INNER JOIN {$p}messaging_messages mq ON mq.id = c.origen_mensaje_id
                     WHERE mq.thread_id = t.id) AS quote_count
                FROM {$p}messaging_threads t
                LEFT JOIN {$p}proveedores p ON p.id = t.proveedor_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY t.last_message_at DESC LIMIT 100';
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows ?: [] as &$row) {
            $row['has_quote'] = ((int) ($row['quote_count'] ?? 0) > 0) || !empty($row['quote_hint']);
            $row['looks_like_quote'] = !empty($row['quote_hint']);
        }
        unset($row);

        wp_send_json_success(['threads' => $rows ?: []]);
    }

    public function ajax_thread() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        global $wpdb;
        $p = Riverso_Messaging_Store::prefix();
        $thread = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, p.nombre AS proveedor_nombre FROM {$p}messaging_threads t
             LEFT JOIN {$p}proveedores p ON p.id = t.proveedor_id WHERE t.id = %d",
            $id
        ), ARRAY_A);
        if (!$thread) {
            wp_send_json_error(['message' => 'Hilo no encontrado']);
        }
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}messaging_messages WHERE thread_id = %d ORDER BY sent_at ASC, id ASC",
            $id
        ), ARRAY_A) ?: [];
        foreach ($messages as &$m) {
            $m['attachments'] = $wpdb->get_results($wpdb->prepare(
                "SELECT id, filename, mime, size_bytes FROM {$p}messaging_attachments WHERE message_id = %d",
                (int) $m['id']
            ), ARRAY_A) ?: [];
        }
        unset($m);

        $quotes = $wpdb->get_results($wpdb->prepare(
            "SELECT id, numero_documento, estado, tipo_fuente, fecha_documento, total, origen_mensaje_id
             FROM {$p}cotizaciones_recibidas WHERE origen_mensaje_id IN (
                SELECT id FROM {$p}messaging_messages WHERE thread_id = %d
             ) ORDER BY id DESC",
            $id
        ), ARRAY_A) ?: [];
        $quote_by_msg = [];
        foreach ($quotes as $q) {
            $oid = (int) ($q['origen_mensaje_id'] ?? 0);
            if ($oid) {
                $quote_by_msg[$oid] = true;
            }
        }
        foreach ($messages as &$m) {
            $has_purchase = false;
            foreach ($m['attachments'] as $att) {
                if (riverso_messaging_is_quote_filename($att['filename'] ?? '')) {
                    $has_purchase = true;
                    break;
                }
            }
            $m['has_quote'] = !empty($quote_by_msg[(int) $m['id']]);
            $m['looks_like_quote'] = $m['has_quote'] || riverso_messaging_looks_like_quote(
                $m['subject'] ?? '',
                $m['body_text'] ?? '',
                $has_purchase,
                !empty($thread['proveedor_id'])
            );
            $m['gmail_labels'] = riverso_messaging_parse_gmail_labels($m['gmail_labels'] ?? '');
        }
        unset($m);

        $thread['has_quote'] = !empty($quotes) || !empty($thread['quote_hint']);
        $thread['looks_like_quote'] = !empty($thread['quote_hint']) || !empty($quotes);

        $wpdb->update($p . 'messaging_threads', [
            'unread_count' => 0,
            'leido_at' => current_time('mysql'),
        ], ['id' => $id]);

        wp_send_json_success([
            'thread' => $thread,
            'messages' => $messages,
            'quotes' => $quotes,
        ]);
    }

    public function ajax_set_tipo() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $tipo = isset($_POST['tipo_chat']) ? sanitize_text_field(wp_unslash($_POST['tipo_chat'])) : '';
        $allowed = ['proveedor', 'cliente', 'trabajo', 'spam', 'otro'];
        if (!$id || !in_array($tipo, $allowed, true)) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }
        global $wpdb;
        $data = ['tipo_chat' => $tipo];
        if ($tipo === 'spam') {
            $data['is_spam'] = 1;
        }
        $wpdb->update(Riverso_Messaging_Store::prefix() . 'messaging_threads', $data, ['id' => $id]);
        wp_send_json_success(['tipo_chat' => $tipo]);
    }

    public function ajax_mark_reviewed() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        global $wpdb;
        $wpdb->update(Riverso_Messaging_Store::prefix() . 'messaging_threads', [
            'revisado_by' => get_current_user_id(),
            'revisado_at' => current_time('mysql'),
            'unread_count' => 0,
            'leido_at' => current_time('mysql'),
        ], ['id' => $id]);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_reply() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $body = isset($_POST['body']) ? sanitize_textarea_field(wp_unslash($_POST['body'])) : '';
        if (!$id || $body === '') {
            wp_send_json_error(['message' => 'Mensaje vacío']);
        }
        global $wpdb;
        $thread = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Riverso_Messaging_Store::prefix() . 'messaging_threads WHERE id = %d',
            $id
        ), ARRAY_A);
        if (!$thread) {
            wp_send_json_error(['message' => 'Hilo no encontrado']);
        }

        if ($thread['canal'] === 'email') {
            $gmail = new Riverso_Gmail_Adapter();
            $last = $wpdb->get_row($wpdb->prepare(
                'SELECT subject, from_address FROM ' . Riverso_Messaging_Store::prefix() . 'messaging_messages
                 WHERE thread_id = %d AND direction = %s ORDER BY id DESC LIMIT 1',
                $id,
                'in'
            ), ARRAY_A);
            $to = $thread['contacto_identificador'];
            $subject = 'Re: ' . ltrim((string) ($last['subject'] ?? ''), 'Re: ');
            $r = $gmail->send_reply($id, $to, $subject, $body);
            if (is_wp_error($r)) {
                wp_send_json_error(['message' => $r->get_error_message()]);
            }
        } else {
            $wa = new Riverso_Whatsapp_Adapter();
            $r = $wa->send_text($thread['contacto_identificador'], $body);
            if (is_wp_error($r)) {
                wp_send_json_error(['message' => $r->get_error_message()]);
            }
            Riverso_Messaging_Store::insert_message([
                'thread_id' => $id,
                'direction' => 'out',
                'remote_id' => 'wa-out-' . uniqid(),
                'body_text' => $body,
                'to_address' => $thread['contacto_identificador'],
                'sent_at' => current_time('mysql'),
                'status' => 'sent',
            ]);
            Riverso_Messaging_Store::upsert_thread([
                'canal' => 'whatsapp',
                'remote_id' => $thread['remote_id'],
                'last_message_at' => current_time('mysql'),
                'last_preview' => mb_substr($body, 0, 180),
            ]);
        }
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_create_quote() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $message_id = isset($_POST['message_id']) ? intval($_POST['message_id']) : 0;
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        global $wpdb;
        $p = Riverso_Messaging_Store::prefix();
        $msg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}messaging_messages WHERE id = %d", $message_id), ARRAY_A);
        if (!$msg) {
            wp_send_json_error(['message' => 'Mensaje no encontrado']);
        }
        $att = $attachment_id
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}messaging_attachments WHERE id = %d", $attachment_id), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}messaging_attachments WHERE message_id = %d ORDER BY id DESC LIMIT 1", $message_id), ARRAY_A);
        if (!$att) {
            wp_send_json_error(['message' => 'Sin adjunto']);
        }
        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}messaging_threads WHERE id = %d", $msg['thread_id']), ARRAY_A);
        $mod = Riverso_POS_Received_Quote_Module::get_instance();
        $id = $mod->create_from_message([
            'mensaje_id' => $message_id,
            'canal' => $thread['canal'] ?? 'email',
            'proveedor_id' => $thread['proveedor_id'] ?? null,
            'archivo_path' => $att['local_path'],
            'archivo_original' => $att['filename'],
            'numero_documento' => $msg['subject'] ?: $att['filename'],
        ]);
        if (is_wp_error($id)) {
            wp_send_json_error(['message' => $id->get_error_message()]);
        }
        wp_send_json_success(['id' => $id]);
    }

    public function ajax_send_claim() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $thread_id = isset($_POST['thread_id']) ? intval($_POST['thread_id']) : 0;
        $body = isset($_POST['body']) ? sanitize_textarea_field(wp_unslash($_POST['body'])) : '';
        $_POST['id'] = $thread_id;
        $_POST['body'] = $body;
        $this->ajax_reply();
    }

    public function ajax_mark_notification() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        global $wpdb;
        $wpdb->update(Riverso_Messaging_Store::prefix() . 'messaging_notifications', [
            'leido_at' => current_time('mysql'),
        ], ['id' => $id]);
        wp_send_json_success(['ok' => true]);
    }

    public function ajax_download_attachment() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_die('Sin permisos', 403);
        }
        $id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
        if (!$id) {
            wp_die('Adjunto no encontrado', 404);
        }
        global $wpdb;
        $att = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Riverso_Messaging_Store::prefix() . 'messaging_attachments WHERE id = %d',
            $id
        ), ARRAY_A);
        if (!$att || empty($att['local_path']) || !is_file($att['local_path'])) {
            wp_die('Archivo no disponible', 404);
        }
        $filename = $att['filename'] ?: basename($att['local_path']);
        $mime = $att['mime'] ?: 'application/octet-stream';
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . (string) filesize($att['local_path']));
        readfile($att['local_path']);
        exit;
    }
}
