<?php
/**
 * Módulo de Órdenes de Compra (Fase 5)
 *
 * CRUD mínimo con estados: borrador / enviada / recibida_parcial / recibida / cancelada.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Purchase_Order_Module {

    private static $instance = null;

    const STATUSES = [
        'borrador' => 'Borrador',
        'enviada' => 'Enviada',
        'recibida_parcial' => 'Recibida parcial',
        'recibida' => 'Recibida',
        'cancelada' => 'Cancelada',
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_po_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_po_create', [$this, 'ajax_create']);
        add_action('wp_ajax_riverso_po_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_po_update_status', [$this, 'ajax_update_status']);
    }

    /**
     * Crea tablas de OC e ítems.
     */
    public static function create_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_oc = "CREATE TABLE {$prefix}ordenes_compra (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            numero VARCHAR(50) DEFAULT NULL,
            proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'borrador',
            fecha_emision DATE DEFAULT NULL,
            fecha_esperada DATE DEFAULT NULL,
            notas TEXT DEFAULT NULL,
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY proveedor_id (proveedor_id),
            KEY estado (estado),
            KEY numero (numero)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$prefix}ordenes_compra_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            orden_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED DEFAULT NULL,
            producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL,
            descripcion VARCHAR(255) DEFAULT NULL,
            cantidad DECIMAL(12,3) NOT NULL DEFAULT 0,
            cantidad_recibida DECIMAL(12,3) NOT NULL DEFAULT 0,
            unidad VARCHAR(30) DEFAULT 'unidad',
            precio_unitario DECIMAL(12,4) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY orden_id (orden_id),
            KEY producto_base_id (producto_base_id)
        ) {$charset_collate};";

        dbDelta($sql_oc);
        dbDelta($sql_items);
    }

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_purchases') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $estado = isset($_POST['estado']) ? sanitize_text_field(wp_unslash($_POST['estado'])) : '';
        $limit = isset($_POST['limit']) ? max(1, min(200, intval($_POST['limit']))) : 50;

        if ($estado && isset(self::STATUSES[$estado])) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$prefix}ordenes_compra WHERE estado = %s ORDER BY id DESC LIMIT %d",
                    $estado,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$prefix}ordenes_compra ORDER BY id DESC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        }

        wp_send_json_success($rows ?: []);
    }

    public function ajax_create() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_purchases') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $proveedor_id = isset($_POST['proveedor_id']) ? intval($_POST['proveedor_id']) : 0;
        $notas = isset($_POST['notas']) ? sanitize_textarea_field(wp_unslash($_POST['notas'])) : '';
        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '[]';
        $items = is_array($items_raw) ? $items_raw : json_decode($items_raw, true);
        if (!is_array($items)) {
            $items = [];
        }

        $numero = 'OC-' . gmdate('Ymd') . '-' . wp_generate_password(4, false, false);

        $inserted = $wpdb->insert(
            "{$prefix}ordenes_compra",
            [
                'numero' => $numero,
                'proveedor_id' => $proveedor_id ?: null,
                'estado' => 'borrador',
                'fecha_emision' => current_time('Y-m-d'),
                'notas' => $notas,
                'creado_por' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ]
        );

        if (!$inserted) {
            wp_send_json_error(['message' => 'No se pudo crear la OC']);
        }

        $orden_id = (int) $wpdb->insert_id;

        foreach ($items as $item) {
            $wpdb->insert(
                "{$prefix}ordenes_compra_items",
                [
                    'orden_id' => $orden_id,
                    'producto_base_id' => isset($item['producto_base_id']) ? intval($item['producto_base_id']) : null,
                    'producto_proveedor_id' => isset($item['producto_proveedor_id']) ? intval($item['producto_proveedor_id']) : null,
                    'descripcion' => isset($item['descripcion']) ? sanitize_text_field($item['descripcion']) : '',
                    'cantidad' => isset($item['cantidad']) ? floatval($item['cantidad']) : 0,
                    'unidad' => isset($item['unidad']) ? sanitize_text_field($item['unidad']) : 'unidad',
                    'precio_unitario' => isset($item['precio_unitario']) ? floatval($item['precio_unitario']) : null,
                ]
            );
        }

        wp_send_json_success(['id' => $orden_id, 'numero' => $numero]);
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_purchases') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        $orden = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$prefix}ordenes_compra WHERE id = %d", $id),
            ARRAY_A
        );
        if (!$orden) {
            wp_send_json_error(['message' => 'OC no encontrada']);
        }

        $items = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$prefix}ordenes_compra_items WHERE orden_id = %d", $id),
            ARRAY_A
        );

        $orden['items'] = $items ?: [];
        wp_send_json_success($orden);
    }

    public function ajax_update_status() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_purchases') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $estado = isset($_POST['estado']) ? sanitize_text_field(wp_unslash($_POST['estado'])) : '';

        if (!$id || !isset(self::STATUSES[$estado])) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }

        $updated = $wpdb->update(
            "{$prefix}ordenes_compra",
            [
                'estado' => $estado,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'No se pudo actualizar']);
        }

        wp_send_json_success(['id' => $id, 'estado' => $estado]);
    }
}
