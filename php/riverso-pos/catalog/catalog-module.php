<?php
/**
 * Módulo Catálogo (Fase 2)
 * 
 * Agrupa: productos, atributos, categorías, proveedores, códigos, envases, matching, equivalencias.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Catalog_Module {

    /**
     * Inicializa el módulo
     */
    public static function init() {
        // Registrar capabilities específicas del catálogo
        do_action('riverso_register_capabilities', [
            'riverso_view_products',
            'riverso_manage_products',
            'riverso_edit_products',
            'riverso_edit_skus',
            'riverso_manage_codes',
            'riverso_view_suppliers',
            'riverso_edit_suppliers',
            'riverso_manage_packaging',
            'riverso_generate_ean13',
            'riverso_print_labels',
            'riverso_scan_barcodes',
            'riverso_assign_barcodes',
            'riverso_manage_matching',
        ]);

        // Suscribirse a eventos del módulo
        riverso_event_subscribe('barcode.scanned', [__CLASS__, 'on_barcode_scanned'], 10);
        riverso_event_subscribe('product.created', [__CLASS__, 'on_product_created'], 10);
        riverso_event_subscribe('product.updated', [__CLASS__, 'on_product_updated'], 10);
        riverso_event_subscribe('invoice.received', [__CLASS__, 'on_invoice_received'], 10);

        // AJAX endpoints
        add_action('wp_ajax_riverso_scan_barcode', [__CLASS__, 'ajax_scan_barcode']);
        add_action('wp_ajax_riverso_get_product', [__CLASS__, 'ajax_get_product']);
        add_action('wp_ajax_riverso_create_product', [__CLASS__, 'ajax_create_product']);
    }

    /**
     * Manejador: código escaneado
     */
    public static function on_barcode_scanned($payload, $context) {
        // Aquí pueden suscribirse otros módulos (POS, conteo, etc.)
        // Evento ya propagado a través del bus
    }

    /**
     * Manejador: nuevo producto creado
     */
    public static function on_product_created($payload, $context) {
        // Crear precios automáticos, crear códigos EAN13, etc.
    }

    /**
     * Manejador: producto actualizado
     */
    public static function on_product_updated($payload, $context) {
        // Auditar, propagar cambios
    }

    /**
     * Manejador: factura recibida
     * Crear nuevos producto_proveedor de facturas
     */
    public static function on_invoice_received($payload, $context) {
        // Crear matching automático, tareas de revisión
    }

    /**
     * AJAX: escanear código
     */
    public static function ajax_scan_barcode() {
        check_ajax_referer('riverso-nonce', 'nonce');

        if (!current_user_can('riverso_scan_barcodes')) {
            wp_send_json_error('Sin permisos');
        }

        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';

        if (!$code) {
            wp_send_json_error('Código vacío');
        }

        $barcode_data = Riverso_Barcode_Model::resolve($code);

        if (!$barcode_data) {
            wp_send_json_error('Código no encontrado');
        }

        wp_send_json_success($barcode_data);
    }

    /**
     * AJAX: obtener producto
     */
    public static function ajax_get_product() {
        check_ajax_referer('riverso-nonce', 'nonce');

        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error('Sin permisos');
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error('ID inválido');
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $product = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$prefix}producto_base WHERE id = %d",
                $product_id
            ),
            ARRAY_A
        );

        if (!$product) {
            wp_send_json_error('Producto no encontrado');
        }

        wp_send_json_success($product);
    }

    /**
     * AJAX: crear producto
     */
    public static function ajax_create_product() {
        check_ajax_referer('riverso-nonce', 'nonce');

        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error('Sin permisos');
        }

        $data = [
            'nombre_canonico' => isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '',
            'canonical_sku' => isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '',
            'unidad_base' => isset($_POST['unidad']) ? sanitize_text_field($_POST['unidad']) : 'unidad',
        ];

        if (!$data['nombre_canonico'] || !$data['canonical_sku']) {
            wp_send_json_error('Nombre y SKU requeridos');
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $result = $wpdb->insert(
            "{$prefix}producto_base",
            array_merge($data, [
                'estado' => 'activo',
                'created_at' => current_time('mysql'),
            ]),
            ['%s', '%s', '%s', '%s', '%s']
        );

        if (!$result) {
            wp_send_json_error('Error al crear producto');
        }

        $product_id = $wpdb->insert_id;

        riverso_event_publish('product.created', [
            'product_id' => $product_id,
            'nombre' => $data['nombre_canonico'],
            'sku' => $data['canonical_sku'],
        ], [
            'user_id' => get_current_user_id(),
        ]);

        wp_send_json_success(['product_id' => $product_id]);
    }

    /**
     * Crea las tablas del módulo
     */
    public static function create_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $charset_collate = $wpdb->get_charset_collate();

        // Tabla unificada de códigos de barras (Fase 2)
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}codigo_barra (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL UNIQUE,
            tipo ENUM('ean13', 'supplier', 'internal') DEFAULT 'ean13',
            producto_base_id BIGINT UNSIGNED NOT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad DECIMAL(10, 3) NOT NULL,
            unidad_medida VARCHAR(20) NOT NULL,
            envase_id BIGINT UNSIGNED DEFAULT NULL,
            factor_a_unidad_base DECIMAL(10, 3) DEFAULT 1,
            activo TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_codigo (codigo),
            KEY idx_producto (producto_base_id),
            KEY idx_proveedor (proveedor_id),
            KEY idx_tipo (tipo),
            KEY idx_activo (activo)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Inicializar al cargar
add_action('riverso_init', [Riverso_Catalog_Module::class, 'init']);
