<?php
/**
 * Módulo de Embolsado / Producto abierto - Riverso POS.
 *
 * Gestiona:
 *   - Envases cerrados (riverso_envases): definición de la unidad de compra/venta
 *     cerrada por producto_base.
 *   - Apertura de envase (riverso_aperturas): convierte stock cerrado en stock
 *     abierto (suelto), respetando stock_abierto_habilitado / codigo_abierto
 *     (regla BR-005).
 *   - Bolsas (riverso_bolsas): empaques personalizados generados desde el stock
 *     abierto, con su EAN13 propio (formato interno 2SSSSSSQQQQQX).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/barcodes/class-ean13-generator.php';

class Riverso_Packaging_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_packaging_envases', [$this, 'ajax_list_envases']);
        add_action('wp_ajax_riverso_packaging_create_envase', [$this, 'ajax_create_envase']);
        add_action('wp_ajax_riverso_packaging_open_envase', [$this, 'ajax_open_envase']);
        add_action('wp_ajax_riverso_packaging_create_bolsa', [$this, 'ajax_create_bolsa']);
        add_action('wp_ajax_riverso_packaging_bolsas', [$this, 'ajax_list_bolsas']);
        add_action('wp_ajax_riverso_packaging_stock', [$this, 'ajax_open_stock']);
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$prefix}envases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            sku_envase VARCHAR(100) DEFAULT NULL,
            woocommerce_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cantidad_unidades DECIMAL(12,4) NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_base (producto_base_id),
            UNIQUE KEY ux_sku_envase (sku_envase)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}aperturas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            envase_id BIGINT UNSIGNED DEFAULT NULL,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            lote_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad_envases DECIMAL(12,4) NOT NULL DEFAULT 1,
            cantidad_abierta DECIMAL(12,4) NOT NULL,
            costo_unitario DECIMAL(12,4) DEFAULT NULL,
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            notas TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_base (producto_base_id),
            KEY idx_envase (envase_id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}bolsas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            cantidad DECIMAL(12,4) NOT NULL,
            sku_bolsa VARCHAR(100) DEFAULT NULL,
            ean13 VARCHAR(20) DEFAULT NULL,
            costo_unitario DECIMAL(12,4) DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'generada',
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_system TINYINT(1) NOT NULL DEFAULT 0,
            requires_human_review TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_base (producto_base_id),
            KEY idx_ean (ean13)
        ) $charset_collate;";
        dbDelta($sql);
    }

    /* ===================== Envases ===================== */

    public function create_envase($producto_base_id, $cantidad_unidades, $sku_envase = '', $variation_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $cantidad_unidades = floatval($cantidad_unidades);
        if (!$producto_base_id || $cantidad_unidades <= 0) {
            return new WP_Error('invalid', 'Producto base y cantidad de unidades requeridos');
        }

        $wpdb->insert("{$prefix}envases", [
            'producto_base_id' => $producto_base_id,
            'sku_envase' => $sku_envase ? sanitize_text_field($sku_envase) : null,
            'woocommerce_variation_id' => intval($variation_id),
            'cantidad_unidades' => $cantidad_unidades,
            'activo' => 1,
        ], ['%d', '%s', '%d', '%f', '%d']);

        return (int) $wpdb->insert_id;
    }

    /* ===================== Apertura de envase ===================== */

    /**
     * Abre uno o más envases cerrados: descuenta stock cerrado y aumenta el
     * inventario abierto del producto_base. Registra apertura + movimiento.
     *
     * @param int      $envase_id
     * @param float    $cantidad_envases
     * @param int|null $lote_id
     * @return array|WP_Error
     */
    public function open_envase($envase_id, $cantidad_envases = 1, $lote_id = null) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $envase_id = intval($envase_id);
        $cantidad_envases = floatval($cantidad_envases);
        if ($cantidad_envases <= 0) {
            return new WP_Error('invalid', 'Cantidad de envases inválida');
        }

        $envase = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}envases WHERE id = %d", $envase_id), ARRAY_A);
        if (!$envase) {
            return new WP_Error('not_found', 'Envase no encontrado');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            intval($envase['producto_base_id'])
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        // Regla BR-005: solo se puede abrir si el stock abierto está habilitado.
        if (empty($pb['stock_abierto_habilitado'])) {
            return new WP_Error('not_allowed', 'El producto no permite stock abierto (BR-005)');
        }

        $unidades = $cantidad_envases * (float) $envase['cantidad_unidades'];

        // Costo unitario: del lote indicado o c_ref local.
        $costo_unitario = null;
        if ($lote_id) {
            $costo_unitario = $wpdb->get_var($wpdb->prepare(
                "SELECT costo_unitario FROM {$prefix}lotes WHERE id = %d",
                intval($lote_id)
            ));
        }
        if ($costo_unitario === null && class_exists('Riverso_Pricing_Module')) {
            $costo_unitario = Riverso_Pricing_Module::get_instance()->calculate_c_ref_local($pb['id']);
        }

        $stock_anterior = (float) $pb['stock_abierto'];
        $stock_nuevo = $stock_anterior + $unidades;

        // 1. Aumentar inventario abierto.
        $wpdb->update(
            "{$prefix}producto_base",
            ['stock_abierto' => $stock_nuevo],
            ['id' => $pb['id']],
            ['%f'],
            ['%d']
        );

        // 2. Descontar stock cerrado (lote si aplica).
        if ($lote_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}lotes
                 SET cantidad_disponible = GREATEST(0, cantidad_disponible - %f)
                 WHERE id = %d",
                $cantidad_envases,
                intval($lote_id)
            ));
        }

        // 3. Descontar stock cerrado en WooCommerce (envase = variación/producto).
        $wc_id = intval($envase['woocommerce_variation_id']) ?: intval($pb['woocommerce_variation_id']) ?: intval($pb['woocommerce_product_id']);
        if ($wc_id && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($wc_id);
            if ($wc_product && $wc_product->managing_stock()) {
                wc_update_product_stock($wc_product, $cantidad_envases, 'decrease');
            }
        }

        // 4. Registrar apertura.
        $wpdb->insert("{$prefix}aperturas", [
            'envase_id' => $envase_id,
            'producto_base_id' => $pb['id'],
            'lote_id' => $lote_id ? intval($lote_id) : null,
            'cantidad_envases' => $cantidad_envases,
            'cantidad_abierta' => $unidades,
            'costo_unitario' => $costo_unitario !== null ? (float) $costo_unitario : null,
            'usuario_id' => get_current_user_id(),
        ], ['%d', '%d', '%d', '%f', '%f', '%f', '%d']);
        $apertura_id = (int) $wpdb->insert_id;

        // 5. Movimiento de inventario (tipo apertura).
        $wpdb->insert("{$prefix}movimientos", [
            'product_id' => intval($pb['woocommerce_product_id']),
            'variation_id' => intval($envase['woocommerce_variation_id']) ?: null,
            'lote_id' => $lote_id ? intval($lote_id) : null,
            'tipo' => 'apertura',
            'cantidad' => $unidades,
            'stock_anterior' => $stock_anterior,
            'stock_nuevo' => $stock_nuevo,
            'referencia_tipo' => 'apertura',
            'referencia_id' => $apertura_id,
            'notas' => sprintf('Apertura de %s envase(s) = %s unidades abiertas', $cantidad_envases, $unidades),
            'usuario_id' => get_current_user_id(),
        ]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('envase_abierto', 'producto_base', $pb['id'], [
                'old_value' => ['stock_abierto' => $stock_anterior],
                'new_value' => ['stock_abierto' => $stock_nuevo],
                'details' => 'Apertura de envase',
            ]);
        }

        return [
            'apertura_id' => $apertura_id,
            'unidades_abiertas' => $unidades,
            'stock_abierto' => $stock_nuevo,
            'costo_unitario' => $costo_unitario,
        ];
    }

    /* ===================== Bolsas ===================== */

    /**
     * Genera una bolsa personalizada desde el stock abierto, con EAN13 propio.
     *
     * @param int   $producto_base_id
     * @param float $cantidad
     * @return array|WP_Error
     */
    public function create_bolsa($producto_base_id, $cantidad) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $cantidad = floatval($cantidad);
        if (!$producto_base_id || $cantidad <= 0) {
            return new WP_Error('invalid', 'Producto base y cantidad requeridos');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $stock_abierto = (float) $pb['stock_abierto'];
        if ($cantidad > $stock_abierto) {
            return new WP_Error('insufficient', 'Stock abierto insuficiente (' . $stock_abierto . ')');
        }

        // Costo unitario y total.
        $costo_unitario = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()->calculate_c_ref_local($producto_base_id)
            : null;

        // SKU de la bolsa y EAN13 (formato interno 2SSSSSSQQQQQX).
        $sku_base = $pb['canonical_sku'] ?: ('B' . $producto_base_id);
        $sku_bolsa = $sku_base . '-B' . (int) $cantidad;
        $ean13 = null;
        if (!empty($pb['permite_ean13_personalizado'])) {
            $ean13 = Riverso_EAN13_Generator::build($sku_base, (int) $cantidad);
        }

        // Descontar stock abierto.
        $wpdb->update(
            "{$prefix}producto_base",
            ['stock_abierto' => $stock_abierto - $cantidad],
            ['id' => $producto_base_id],
            ['%f'],
            ['%d']
        );

        $wpdb->insert("{$prefix}bolsas", [
            'producto_base_id' => $producto_base_id,
            'cantidad' => $cantidad,
            'sku_bolsa' => $sku_bolsa,
            'ean13' => $ean13,
            'costo_unitario' => $costo_unitario !== null ? (float) $costo_unitario : null,
            'estado' => 'generada',
            'usuario_id' => get_current_user_id(),
            'created_by_system' => 0,
            'requires_human_review' => 1,
        ], ['%d', '%f', '%s', '%s', '%f', '%s', '%d', '%d', '%d']);
        $bolsa_id = (int) $wpdb->insert_id;

        // Persistir el EAN13 en barcodes (source=generated) y tarea de verificación.
        if ($ean13 && class_exists('Riverso_Barcode_Module')) {
            $barcode_mod = Riverso_Barcode_Module::get_instance();
            if (method_exists($barcode_mod, 'register_generated_barcode')) {
                $barcode_mod->register_generated_barcode($ean13, intval($pb['woocommerce_product_id']), [
                    'sku' => $sku_bolsa,
                    'bolsa_id' => $bolsa_id,
                    'producto_base_id' => $producto_base_id,
                ]);
            }
        }

        if ($ean13 && function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'verificar_etiquetado',
                'Verificar etiquetado de bolsa ' . $sku_bolsa . ' (' . $ean13 . ')',
                'bolsa',
                $bolsa_id,
                ['prioridad' => 'normal']
            );
        }

        // Movimiento de salida del stock abierto.
        $wpdb->insert("{$prefix}movimientos", [
            'product_id' => intval($pb['woocommerce_product_id']),
            'tipo' => 'embolsado',
            'cantidad' => $cantidad,
            'stock_anterior' => $stock_abierto,
            'stock_nuevo' => $stock_abierto - $cantidad,
            'referencia_tipo' => 'bolsa',
            'referencia_id' => $bolsa_id,
            'notas' => 'Generación de bolsa ' . $sku_bolsa,
            'usuario_id' => get_current_user_id(),
        ]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('bolsa_generada', 'bolsa', $bolsa_id, [
                'new_value' => ['cantidad' => $cantidad, 'ean13' => $ean13, 'sku' => $sku_bolsa],
            ]);
        }

        return [
            'bolsa_id' => $bolsa_id,
            'sku_bolsa' => $sku_bolsa,
            'ean13' => $ean13,
            'cantidad' => $cantidad,
            'stock_abierto' => $stock_abierto - $cantidad,
        ];
    }

    /* ===================== AJAX ===================== */

    public function ajax_list_envases() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = intval($_POST['producto_base_id'] ?? 0);
        $where = $base_id ? $wpdb->prepare('WHERE e.producto_base_id = %d', $base_id) : '';
        $rows = $wpdb->get_results(
            "SELECT e.*, pb.canonical_sku, pb.nombre_canonico, pb.stock_abierto, pb.stock_abierto_habilitado
             FROM {$prefix}envases e
             INNER JOIN {$prefix}producto_base pb ON pb.id = e.producto_base_id
             {$where}
             ORDER BY e.id DESC LIMIT 200",
            ARRAY_A
        );
        wp_send_json_success(['envases' => $rows]);
    }

    public function ajax_create_envase() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->create_envase(
            intval($_POST['producto_base_id'] ?? 0),
            floatval($_POST['cantidad_unidades'] ?? 0),
            sanitize_text_field($_POST['sku_envase'] ?? ''),
            intval($_POST['woocommerce_variation_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['envase_id' => $result]);
    }

    public function ajax_open_envase() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->open_envase(
            intval($_POST['envase_id'] ?? 0),
            floatval($_POST['cantidad_envases'] ?? 1),
            !empty($_POST['lote_id']) ? intval($_POST['lote_id']) : null
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_create_bolsa() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->create_bolsa(
            intval($_POST['producto_base_id'] ?? 0),
            floatval($_POST['cantidad'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_list_bolsas() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = intval($_POST['producto_base_id'] ?? 0);
        $where = $base_id ? $wpdb->prepare('WHERE b.producto_base_id = %d', $base_id) : '';
        $rows = $wpdb->get_results(
            "SELECT b.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}bolsas b
             INNER JOIN {$prefix}producto_base pb ON pb.id = b.producto_base_id
             {$where}
             ORDER BY b.id DESC LIMIT 200",
            ARRAY_A
        );
        wp_send_json_success(['bolsas' => $rows]);
    }

    public function ajax_open_stock() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_packaging')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = intval($_POST['producto_base_id'] ?? 0);
        $stock = $wpdb->get_var($wpdb->prepare(
            "SELECT stock_abierto FROM {$prefix}producto_base WHERE id = %d",
            $base_id
        ));
        wp_send_json_success(['stock_abierto' => $stock !== null ? (float) $stock : 0]);
    }
}
