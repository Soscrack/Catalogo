<?php
/**
 * Persistencia de cuentas, hilos, mensajes y adjuntos del inbox.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Messaging_Store {

    public static function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    public static function create_tables() {
        global $wpdb;
        $prefix = self::prefix();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$prefix}messaging_cuentas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canal VARCHAR(20) NOT NULL,
            identificador VARCHAR(190) NOT NULL,
            display_name VARCHAR(190) DEFAULT NULL,
            config_json LONGTEXT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_canal_ident (canal, identificador)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}messaging_threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            cuenta_id BIGINT UNSIGNED DEFAULT NULL,
            canal VARCHAR(20) NOT NULL,
            remote_id VARCHAR(190) NOT NULL,
            contacto_nombre VARCHAR(190) DEFAULT NULL,
            contacto_identificador VARCHAR(190) DEFAULT NULL,
            tipo_chat VARCHAR(20) NOT NULL DEFAULT 'otro',
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            leido_at DATETIME DEFAULT NULL,
            revisado_by BIGINT UNSIGNED DEFAULT NULL,
            revisado_at DATETIME DEFAULT NULL,
            last_message_at DATETIME DEFAULT NULL,
            last_preview TEXT DEFAULT NULL,
            unread_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_spam TINYINT(1) NOT NULL DEFAULT 0,
            is_important TINYINT(1) NOT NULL DEFAULT 0,
            quote_hint TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_canal_remote (canal, remote_id),
            KEY idx_tipo (tipo_chat),
            KEY idx_proveedor (proveedor_id),
            KEY idx_last (last_message_at),
            KEY idx_spam (is_spam),
            KEY idx_important (is_important),
            KEY idx_quote_hint (quote_hint)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}messaging_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'in',
            remote_id VARCHAR(190) DEFAULT NULL,
            in_reply_to VARCHAR(190) DEFAULT NULL,
            subject VARCHAR(500) DEFAULT NULL,
            body_text LONGTEXT NULL,
            body_html LONGTEXT NULL,
            gmail_labels TEXT NULL,
            from_address VARCHAR(255) DEFAULT NULL,
            to_address VARCHAR(255) DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'received',
            payload_json LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_remote (remote_id),
            KEY idx_thread (thread_id),
            KEY idx_sent (sent_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}messaging_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            filename VARCHAR(255) DEFAULT NULL,
            mime VARCHAR(120) DEFAULT NULL,
            size_bytes BIGINT UNSIGNED DEFAULT 0,
            local_path VARCHAR(500) DEFAULT NULL,
            r2_key VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_message (message_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$prefix}messaging_notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            tipo VARCHAR(50) NOT NULL DEFAULT 'info',
            titulo VARCHAR(255) NOT NULL,
            cuerpo TEXT DEFAULT NULL,
            link VARCHAR(500) DEFAULT NULL,
            leido_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_leido (user_id, leido_at)
        ) {$charset};");

        self::fix_message_unique_index();
    }

    private static function fix_message_unique_index() {
        global $wpdb;
        $table = self::prefix() . 'messaging_messages';
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table}` LIKE 'remote_id'");
        if (empty($col)) {
            return;
        }
        $idx = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'ux_remote'");
        if (empty($idx)) {
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY ux_remote (remote_id)");
        }
        $bad = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'canal_remote_id'");
        if (!empty($bad)) {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX canal_remote_id");
        }
    }

    public static function ensure_account($canal, $identificador, $display_name = '') {
        global $wpdb;
        $table = self::prefix() . 'messaging_cuentas';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE canal = %s AND identificador = %s",
            $canal,
            $identificador
        ), ARRAY_A);
        if ($row) {
            return (int) $row['id'];
        }
        $wpdb->insert($table, [
            'canal' => $canal,
            'identificador' => $identificador,
            'display_name' => $display_name ?: $identificador,
            'activo' => 1,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function upsert_thread($args) {
        global $wpdb;
        $table = self::prefix() . 'messaging_threads';
        $canal = $args['canal'];
        $remote_id = $args['remote_id'];
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE canal = %s AND remote_id = %s",
            $canal,
            $remote_id
        ), ARRAY_A);

        $data = [
            'canal' => $canal,
            'remote_id' => $remote_id,
        ];
        if (array_key_exists('cuenta_id', $args)) {
            $data['cuenta_id'] = $args['cuenta_id'];
        }
        if (array_key_exists('contacto_nombre', $args)) {
            $data['contacto_nombre'] = $args['contacto_nombre'];
        }
        if (array_key_exists('contacto_identificador', $args)) {
            $data['contacto_identificador'] = $args['contacto_identificador'];
        }
        if (!empty($args['last_message_at'])) {
            $data['last_message_at'] = $args['last_message_at'];
        }
        if (isset($args['last_preview'])) {
            $data['last_preview'] = mb_substr((string) $args['last_preview'], 0, 400);
        }
        if (!empty($args['tipo_chat'])) {
            $data['tipo_chat'] = $args['tipo_chat'];
        }
        if (isset($args['proveedor_id'])) {
            $data['proveedor_id'] = $args['proveedor_id'] ?: null;
        }
        if (isset($args['is_spam'])) {
            $data['is_spam'] = !empty($args['is_spam']) ? 1 : 0;
        }
        if (isset($args['is_important'])) {
            $data['is_important'] = !empty($args['is_important']) ? 1 : 0;
        }

        if ($existing) {
            unset($data['canal'], $data['remote_id']);
            if (empty($args['force_tipo'])) {
                unset($data['tipo_chat']);
            }
            $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
            if (!empty($args['increment_unread'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET unread_count = unread_count + 1 WHERE id = %d",
                    $id
                ));
            }
            if (!empty($args['quote_hint'])) {
                $wpdb->update($table, ['quote_hint' => 1], ['id' => $id]);
            }
            return $id;
        }

        $data['tipo_chat'] = $args['tipo_chat'] ?? 'otro';
        $data['unread_count'] = !empty($args['increment_unread']) ? 1 : 0;
        $data['quote_hint'] = !empty($args['quote_hint']) ? 1 : 0;
        if (empty($data['last_message_at'])) {
            $data['last_message_at'] = current_time('mysql');
        }
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    public static function insert_message($args) {
        global $wpdb;
        $table = self::prefix() . 'messaging_messages';
        $remote = $args['remote_id'] ?? null;
        if ($remote) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE remote_id = %s",
                $remote
            ));
            if ($exists) {
                return (int) $exists;
            }
        }
        $row = [
            'thread_id' => (int) $args['thread_id'],
            'direction' => $args['direction'] ?? 'in',
            'remote_id' => $remote,
            'in_reply_to' => $args['in_reply_to'] ?? null,
            'subject' => $args['subject'] ?? null,
            'body_text' => $args['body_text'] ?? null,
            'body_html' => $args['body_html'] ?? null,
            'from_address' => $args['from_address'] ?? null,
            'to_address' => $args['to_address'] ?? null,
            'sent_at' => $args['sent_at'] ?? current_time('mysql'),
            'status' => $args['status'] ?? 'received',
            'payload_json' => isset($args['payload']) ? wp_json_encode($args['payload']) : null,
        ];
        if (isset($args['gmail_labels'])) {
            $labels = riverso_messaging_parse_gmail_labels($args['gmail_labels']);
            $row['gmail_labels'] = wp_json_encode($labels);
        }
        $wpdb->insert($table, $row);
        return (int) $wpdb->insert_id;
    }

    public static function update_message_gmail_labels($message_id, array $labels) {
        global $wpdb;
        $table = self::prefix() . 'messaging_messages';
        $wpdb->update($table, [
            'gmail_labels' => wp_json_encode(array_values($labels)),
        ], ['id' => (int) $message_id]);
        $thread_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT thread_id FROM {$table} WHERE id = %d",
            (int) $message_id
        ));
        if ($thread_id) {
            self::refresh_thread_gmail_flags($thread_id);
        }
    }

    public static function refresh_thread_gmail_flags($thread_id) {
        global $wpdb;
        $thread_id = (int) $thread_id;
        $p = self::prefix();
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT gmail_labels FROM {$p}messaging_messages
             WHERE thread_id = %d ORDER BY sent_at DESC, id DESC LIMIT 1",
            $thread_id
        ));
        $labels = riverso_messaging_parse_gmail_labels($raw);
        $wpdb->update($p . 'messaging_threads', [
            'is_spam' => riverso_messaging_labels_is_spam($labels) ? 1 : 0,
            'is_important' => riverso_messaging_labels_is_important($labels) ? 1 : 0,
        ], ['id' => $thread_id]);
    }

    public static function add_attachment($message_id, $filename, $mime, $size, $local_path, $r2_key = null) {
        global $wpdb;
        $wpdb->insert(self::prefix() . 'messaging_attachments', [
            'message_id' => (int) $message_id,
            'filename' => $filename,
            'mime' => $mime,
            'size_bytes' => (int) $size,
            'local_path' => $local_path,
            'r2_key' => $r2_key,
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function notify($titulo, $cuerpo, $link = '', $tipo = 'inbox', $user_id = null) {
        global $wpdb;
        $wpdb->insert(self::prefix() . 'messaging_notifications', [
            'user_id' => $user_id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'cuerpo' => $cuerpo,
            'link' => $link,
        ]);
    }

    public static function list_notifications($user_id, $limit = 20) {
        global $wpdb;
        $table = self::prefix() . 'messaging_notifications';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE (user_id IS NULL OR user_id = %d)
             ORDER BY id DESC LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A) ?: [];
    }

    public static function match_proveedor_by_email($email) {
        global $wpdb;
        $email = strtolower(trim((string) $email));
        if ($email === '' || !is_email($email)) {
            return null;
        }
        $table = self::prefix() . 'proveedores';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre, email, telefono FROM {$table}
             WHERE email IS NOT NULL AND LOWER(email) = %s LIMIT 1",
            $email
        ), ARRAY_A);
    }

    public static function match_proveedor_by_phone($phone) {
        global $wpdb;
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 8) {
            return null;
        }
        $suffix = substr($digits, -8);
        $table = self::prefix() . 'proveedores';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre, email, telefono FROM {$table}
             WHERE telefono IS NOT NULL AND REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '+', ''), '-', '') LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like($suffix)
        ), ARRAY_A);
    }
}
