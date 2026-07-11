<?php
/**
 * Módulo Inventario (Fase 3+)
 * 
 * Agrupa: stock, warehouse, movements, stock_count, reservations.
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Inventory_Module {

    /**
     * Inicializa el módulo
     */
    public static function init() {
        // Registrar capabilities
        do_action('riverso_register_capabilities', [
            'riverso_view_stock',
            'riverso_edit_stock',
            'riverso_view_warehouse',
            'riverso_edit_warehouse',
        ]);

        // Suscribirse a eventos
        riverso_event_subscribe('invoice.approved', [__CLASS__, 'on_invoice_approved'], 10);
        riverso_event_subscribe('pos.sale.completed', [__CLASS__, 'on_sale_completed'], 10);
        riverso_event_subscribe('stock_count.approved', [__CLASS__, 'on_count_approved'], 10);

        // AJAX
        add_action('wp_ajax_riverso_get_stock', [__CLASS__, 'ajax_get_stock']);
        add_action('wp_ajax_riverso_adjust_stock', [__CLASS__, 'ajax_adjust_stock']);
    }

    /**
     * Manejador: factura aprobada → movimiento ENTRADA
     */
    public static function on_invoice_approved($payload, $context) {
        // Crear movimiento ENTRADA para cada línea
    }

    /**
     * Manejador: venta POS completa → movimiento SALIDA
     */
    public static function on_sale_completed($payload, $context) {
        // Crear movimiento SALIDA para cada producto vendido
    }

    /**
     * Manejador: conteo aprobado → movimiento AJUSTE si hay diferencia
     */
    public static function on_count_approved($payload, $context) {
        // Crear movimiento AJUSTE por diferencias
    }

    /**
     * AJAX: obtener stock
     */
    public static function ajax_get_stock() {
        check_ajax_referer('riverso-nonce', 'nonce');

        if (!current_user_can('riverso_view_stock')) {
            wp_send_json_error('Sin permisos');
        }

        $producto_id = isset($_POST['producto_id']) ? intval($_POST['producto_id']) : 0;

        if (!$producto_id) {
            wp_send_json_error('ID inválido');
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        $stock = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pu.*, u.nombre as ubicacion_nombre 
                 FROM {$prefix}riverso_producto_ubicacion pu
                 LEFT JOIN {$prefix}riverso_ubicaciones u ON pu.ubicacion_id = u.id
                 WHERE pu.product_id = %d",
                $producto_id
            ),
            ARRAY_A
        );

        wp_send_json_success($stock);
    }

    /**
     * AJAX: ajustar stock
     */
    public static function ajax_adjust_stock() {
        check_ajax_referer('riverso-nonce', 'nonce');

        if (!current_user_can('riverso_edit_stock')) {
            wp_send_json_error('Sin permisos');
        }

        $producto_id = isset($_POST['producto_id']) ? intval($_POST['producto_id']) : 0;
        $cantidad = isset($_POST['cantidad']) ? floatval($_POST['cantidad']) : 0;
        $razon = isset($_POST['razon']) ? sanitize_text_field($_POST['razon']) : '';

        if (!$producto_id || $cantidad === 0) {
            wp_send_json_error('Datos inválidos');
        }

        $movement_id = Riverso_Movement::create(
            'ajuste',
            $producto_id,
            abs($cantidad),
            ['notas' => $razon]
        );

        if ($movement_id) {
            wp_send_json_success(['movement_id' => $movement_id]);
        } else {
            wp_send_json_error('Error al crear movimiento');
        }
    }

    /**
     * Crea las tablas del módulo
     */
    public static function create_tables() {
        // Las tablas ya existen en las migraciones
    }
}

add_action('riverso_init', [__CLASS__, 'init']);
