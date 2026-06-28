<?php
/**
 * Módulo de impresión de etiquetas - Riverso POS
 * 
 * Proporciona endpoints AJAX para preparar y enviar trabajos de impresión
 * al agente local PrintAgentHost (http://127.0.0.1:19284/)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Label_Print_Module {
    private static $instance;
    
    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp_ajax_riverso_prepare_print_job', [$this, 'ajax_prepare_print_job']);
        add_action('wp_ajax_riverso_check_print_agent', [$this, 'ajax_check_agent']);
    }

    /**
     * AJAX: Prepara un trabajo de impresión
     * 
     * @param string $sku SKU del producto
     * @param int $cantidad Cantidad para el EAN13
     * @param string $context Contexto: tienda_local|woo|packaging|task
     * @param int $producto_id ID de producto WooCommerce (opcional)
     * @param string $ean13 EAN13 pregenerado (opcional)
     */
    public function ajax_prepare_print_job() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $cantidad = intval($_POST['cantidad'] ?? 100);
        $context = sanitize_text_field($_POST['context'] ?? 'tienda_local');
        $producto_id = intval($_POST['producto_id'] ?? 0);
        $ean13 = sanitize_text_field($_POST['ean13'] ?? '');

        if (empty($sku)) {
            wp_send_json_error(['message' => 'SKU requerido']);
            return;
        }

        $job = [
            'sku' => $sku,
            'cantidad' => $cantidad,
            'copias' => intval($_POST['copias'] ?? 1),
            'modo' => sanitize_text_field($_POST['modo'] ?? 'BolsaCOD'),
            'color' => sanitize_text_field($_POST['color'] ?? 'BN'),
            'ean13' => $ean13
        ];

        // Resolver nombre y precio según contexto
        switch ($context) {
            case 'woo':
                $this->enrich_from_woocommerce($job, $producto_id, $sku);
                break;
            case 'tienda_local':
                $this->enrich_from_tienda_local($job, $sku);
                break;
            case 'packaging':
                $job['nombre'] = sanitize_text_field($_POST['nombre'] ?? $sku);
                $job['precio'] = intval($_POST['precio'] ?? 0) ?: null;
                break;
            case 'task':
            default:
                $job['nombre'] = sanitize_text_field($_POST['nombre'] ?? $sku);
                $job['precio'] = intval($_POST['precio'] ?? 0) ?: null;
                break;
        }

        // Generar EAN13 si no existe
        if (empty($job['ean13'])) {
            $job['ean13'] = $this->generate_ean13($sku, $cantidad);
        }

        wp_send_json_success($job);
    }

    /**
     * Enriquece datos del producto desde WooCommerce
     */
    private function enrich_from_woocommerce(&$job, $producto_id, $sku) {
        if ($producto_id > 0) {
            $product = wc_get_product($producto_id);
            if ($product) {
                $job['nombre'] = $product->get_name();
                $job['precio'] = intval($product->get_price());
                return;
            }
        }

        // Buscar por SKU
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            $product = wc_get_product($product_id);
            $job['nombre'] = $product->get_name();
            $job['precio'] = intval($product->get_price());
        } else {
            $job['nombre'] = $sku;
        }
    }

    /**
     * Enriquece datos del producto desde Tienda Local
     */
    private function enrich_from_tienda_local(&$job, $sku) {
        global $wpdb;

        if (!class_exists('Riverso_Tienda_Local_Module')) {
            $job['nombre'] = $sku;
            return;
        }

        $module = Riverso_Tienda_Local_Module::get_instance();
        $result = $module->search($sku);

        if (!empty($result['items']) && is_array($result['items'])) {
            $product = reset($result['items']);
            $job['nombre'] = $product['nombre'] ?? $sku;
            $job['precio'] = intval($product['precio'] ?? 0) ?: null;
        } else {
            $job['nombre'] = $sku;
        }
    }

    /**
     * Genera EAN13 usando la lógica compartida
     */
    private function generate_ean13($sku, $cantidad) {
        if (class_exists('Riverso_EAN13_Generator')) {
            return Riverso_EAN13_Generator::build($sku, $cantidad);
        }

        // Fallback: lógica simple si no está disponible
        $sku_digits = preg_replace('/\D/', '', (string) $sku);
        if ($sku_digits === '') {
            $sku_digits = '0';
        }
        $sku_part = str_pad(substr($sku_digits, -6), 6, '0', STR_PAD_LEFT);
        $qty_part = str_pad((string) min(99999, max(0, $cantidad)), 5, '0', STR_PAD_LEFT);

        $twelve = '2' . $sku_part . $qty_part;
        $check = $this->calculate_check_digit($twelve);

        return $twelve . $check;
    }

    /**
     * Calcula dígito verificador EAN13
     */
    private function calculate_check_digit($twelve) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelve[$i];
            $sum += $digit * (($i % 2 === 0) ? 1 : 3);
        }
        return (10 - ($sum % 10)) % 10;
    }

    /**
     * AJAX: Verifica estado del agente
     */
    public function ajax_check_agent() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $agent_url = get_option('riverso_label_print_agent_url', 'http://127.0.0.1:19284');

        $response = wp_remote_get(
            $agent_url . '/health',
            [
                'timeout' => 2,
                'blocking' => true,
                'sslverify' => false
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json(['ok' => false, 'error' => 'Agente no disponible']);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        wp_send_json($data ?: ['ok' => false]);
    }

    /**
     * Obtiene URL y token del agente desde opciones
     */
    public static function get_agent_config() {
        return [
            'agentUrl' => get_option('riverso_label_print_agent_url', 'http://127.0.0.1:19284'),
            'authToken' => get_option('riverso_label_print_auth_token', '')
        ];
    }
}

// Instanciar módulo si no existe
if (!isset($GLOBALS['riverso_label_print_module'])) {
    $GLOBALS['riverso_label_print_module'] = Riverso_Label_Print_Module::get_instance();
}
