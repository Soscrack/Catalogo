<?php
/**
 * REST Riverso v1: webhooks WhatsApp y OAuth Gmail.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Messaging_Store')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-messaging-store.php';
}
if (!class_exists('Riverso_Whatsapp_Adapter')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-whatsapp-adapter.php';
}
if (!class_exists('Riverso_Gmail_Adapter')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'core/messaging/class-gmail-adapter.php';
}

class Riverso_REST_Module {

    public function init() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('riverso/v1', '/webhooks/whatsapp', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'whatsapp_verify'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'whatsapp_receive'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route('riverso/v1', '/oauth/gmail', [
            'methods' => 'GET',
            'callback' => [$this, 'gmail_oauth'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function whatsapp_verify(WP_REST_Request $request) {
        $mode = $request->get_param('hub_mode')
            ?: $request->get_param('hub.mode')
            ?: (isset($_GET['hub_mode']) ? $_GET['hub_mode'] : (isset($_GET['hub.mode']) ? $_GET['hub.mode'] : ''));
        $token = $request->get_param('hub_verify_token')
            ?: $request->get_param('hub.verify_token')
            ?: (isset($_GET['hub_verify_token']) ? $_GET['hub_verify_token'] : (isset($_GET['hub.verify_token']) ? $_GET['hub.verify_token'] : ''));
        $challenge = $request->get_param('hub_challenge')
            ?: $request->get_param('hub.challenge')
            ?: (isset($_GET['hub_challenge']) ? $_GET['hub_challenge'] : (isset($_GET['hub.challenge']) ? $_GET['hub.challenge'] : ''));

        $adapter = new Riverso_Whatsapp_Adapter();
        if ($mode === 'subscribe' && $adapter->verify_token_ok($token)) {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo (string) $challenge;
            exit;
        }
        return new WP_REST_Response('Forbidden', 403);
    }

    public function whatsapp_receive(WP_REST_Request $request) {
        $raw = $request->get_body();
        $adapter = new Riverso_Whatsapp_Adapter();
        $sig = $request->get_header('x-hub-signature-256');
        if (!$adapter->valid_signature($raw, $sig)) {
            return new WP_REST_Response(['error' => 'invalid signature'], 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new WP_REST_Response(['ok' => true], 200);
        }
        $adapter->enqueue_payload($payload);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public function gmail_oauth(WP_REST_Request $request) {
        $code = $request->get_param('code');
        $error = $request->get_param('error');
        $admin = admin_url('admin.php?page=riverso-pos-inbox');
        $portal = home_url('/interno/inbox/');
        if ($error) {
            wp_safe_redirect(add_query_arg('gmail_error', rawurlencode($error), $portal));
            exit;
        }
        if (!$code) {
            wp_safe_redirect(add_query_arg('gmail_error', 'missing_code', $portal));
            exit;
        }
        $adapter = new Riverso_Gmail_Adapter();
        $result = $adapter->exchange_code($code);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg('gmail_error', rawurlencode($result->get_error_message()), $portal));
            exit;
        }
        wp_safe_redirect(add_query_arg('gmail', 'ok', $portal));
        exit;
    }
}
