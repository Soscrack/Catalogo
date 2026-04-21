<?php
/**
 * Módulo de Reportes
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Reports_Module {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_riverso_get_report', [$this, 'ajax_get_report']);
        add_action('wp_ajax_riverso_export_report', [$this, 'ajax_export_report']);
    }
    
    /**
     * Obtener reporte
     */
    public function ajax_get_report() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_reports')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $report_type = isset($_POST['report_type']) ? sanitize_text_field($_POST['report_type']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : date('Y-m-01');
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : date('Y-m-d');
        
        $data = [];
        
        switch ($report_type) {
            case 'sales_summary':
                $data = $this->get_sales_summary($date_from, $date_to);
                break;
            case 'sales_by_day':
                $data = $this->get_sales_by_day($date_from, $date_to);
                break;
            case 'sales_by_product':
                $data = $this->get_sales_by_product($date_from, $date_to);
                break;
            case 'sales_by_cashier':
                $data = $this->get_sales_by_cashier($date_from, $date_to);
                break;
            case 'pos_sessions':
                $data = $this->get_pos_sessions($date_from, $date_to);
                break;
            case 'invoices':
                $data = $this->get_invoices_report($date_from, $date_to);
                break;
            case 'costs':
                $data = $this->get_costs_report($date_from, $date_to);
                break;
            case 'tasks':
                $data = $this->get_tasks_report($date_from, $date_to);
                break;
            case 'stock':
                $data = $this->get_stock_report();
                break;
            case 'low_stock':
                $data = $this->get_low_stock_report();
                break;
            default:
                wp_send_json_error(['message' => 'Tipo de reporte no válido']);
        }
        
        wp_send_json_success(['data' => $data, 'report_type' => $report_type]);
    }
    
    /**
     * Resumen de ventas
     */
    private function get_sales_summary($date_from, $date_to) {
        global $wpdb;
        
        // Total ventas WooCommerce
        $total_sales = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, COALESCE(SUM(pm.meta_value), 0) as total
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(p.post_date) BETWEEN %s AND %s",
            $date_from, $date_to
        ));
        
        // Ventas POS
        $pos_sales = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, COALESCE(SUM(pm2.meta_value), 0) as total
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_riverso_pos_sale'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' 
            AND pm.meta_value = 'yes'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(p.post_date) BETWEEN %s AND %s",
            $date_from, $date_to
        ));
        
        // Ventas online
        $online_sales = [
            'count' => $total_sales->count - $pos_sales->count,
            'total' => $total_sales->total - $pos_sales->total,
        ];
        
        // Por método de pago
        $by_payment = $wpdb->get_results($wpdb->prepare(
            "SELECT pm2.meta_value as method, COUNT(*) as count, 
                    COALESCE(SUM(pm3.meta_value), 0) as total
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_payment_method'
            INNER JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(p.post_date) BETWEEN %s AND %s
            GROUP BY pm2.meta_value
            ORDER BY total DESC",
            $date_from, $date_to
        ));
        
        return [
            'total' => [
                'count' => $total_sales->count,
                'amount' => floatval($total_sales->total),
            ],
            'pos' => [
                'count' => $pos_sales->count,
                'amount' => floatval($pos_sales->total),
            ],
            'online' => [
                'count' => $online_sales['count'],
                'amount' => floatval($online_sales['total']),
            ],
            'by_payment' => $by_payment,
        ];
    }
    
    /**
     * Ventas por día
     */
    private function get_sales_by_day($date_from, $date_to) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(p.post_date) as date, 
                    COUNT(*) as count, 
                    COALESCE(SUM(pm.meta_value), 0) as total
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(p.post_date) BETWEEN %s AND %s
            GROUP BY DATE(p.post_date)
            ORDER BY date ASC",
            $date_from, $date_to
        ));
    }
    
    /**
     * Ventas por producto
     */
    private function get_sales_by_product($date_from, $date_to) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT oi.order_item_name as product_name,
                    SUM(oim_qty.meta_value) as quantity,
                    SUM(oim_total.meta_value) as total,
                    p.ID as product_id
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
            LEFT JOIN {$wpdb->posts} p ON oim_pid.meta_value = p.ID
            WHERE oi.order_item_type = 'line_item'
            AND o.post_type = 'shop_order'
            AND o.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(o.post_date) BETWEEN %s AND %s
            GROUP BY oi.order_item_name, p.ID
            ORDER BY total DESC
            LIMIT 50",
            $date_from, $date_to
        ));
    }
    
    /**
     * Ventas por cajero
     */
    private function get_sales_by_cashier($date_from, $date_to) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.display_name as cashier_name, 
                    pm_cashier.meta_value as cashier_id,
                    COUNT(*) as count, 
                    COALESCE(SUM(pm_total.meta_value), 0) as total
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm_pos ON p.ID = pm_pos.post_id AND pm_pos.meta_key = '_riverso_pos_sale'
            INNER JOIN {$wpdb->postmeta} pm_cashier ON p.ID = pm_cashier.post_id AND pm_cashier.meta_key = '_riverso_pos_cashier'
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
            LEFT JOIN {$wpdb->users} u ON pm_cashier.meta_value = u.ID
            WHERE p.post_type = 'shop_order' 
            AND pm_pos.meta_value = 'yes'
            AND p.post_status IN ('wc-completed', 'wc-processing')
            AND DATE(p.post_date) BETWEEN %s AND %s
            GROUP BY pm_cashier.meta_value, u.display_name
            ORDER BY total DESC",
            $date_from, $date_to
        ));
    }
    
    /**
     * Sesiones POS
     */
    private function get_pos_sessions($date_from, $date_to) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.display_name as user_name,
                (SELECT COUNT(*) FROM {$prefix}pos_payments p WHERE p.session_id = s.id) as orders_count,
                (SELECT COALESCE(SUM(amount), 0) FROM {$prefix}pos_payments p WHERE p.session_id = s.id) as total_sales
            FROM {$prefix}pos_sessions s
            LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
            WHERE DATE(s.opened_at) BETWEEN %s AND %s
            ORDER BY s.opened_at DESC",
            $date_from, $date_to
        ));
    }
    
    /**
     * Reporte de facturas
     */
    private function get_invoices_report($date_from, $date_to) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $facturas = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, p.razon_social as proveedor_nombre
            FROM {$prefix}facturas f
            LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
            WHERE DATE(f.fecha_emision) BETWEEN %s AND %s
            ORDER BY f.fecha_emision DESC",
            $date_from, $date_to
        ));
        
        $totales = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count, 
                    COALESCE(SUM(monto_neto), 0) as total_neto,
                    COALESCE(SUM(monto_iva), 0) as total_iva,
                    COALESCE(SUM(monto_total), 0) as total
            FROM {$prefix}facturas
            WHERE DATE(fecha_emision) BETWEEN %s AND %s",
            $date_from, $date_to
        ));
        
        return [
            'facturas' => $facturas,
            'totales' => $totales,
        ];
    }
    
    /**
     * Historial de costos
     */
    private function get_costs_report($date_from, $date_to) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.post_title as product_name, s.razon_social as supplier_name
            FROM {$prefix}cost_history c
            LEFT JOIN {$wpdb->posts} p ON c.product_id = p.ID
            LEFT JOIN {$prefix}proveedores s ON c.supplier_id = s.id
            WHERE DATE(c.recorded_at) BETWEEN %s AND %s
            ORDER BY c.recorded_at DESC
            LIMIT 500",
            $date_from, $date_to
        ));
    }
    
    /**
     * Reporte de tareas
     */
    private function get_tasks_report($date_from, $date_to) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $tareas = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, 
                    u1.display_name as creado_por_nombre,
                    u2.display_name as asignado_nombre
            FROM {$prefix}tareas t
            LEFT JOIN {$wpdb->users} u1 ON t.creado_por = u1.ID
            LEFT JOIN {$wpdb->users} u2 ON t.asignado_a = u2.ID
            WHERE DATE(t.created_at) BETWEEN %s AND %s
            ORDER BY t.created_at DESC",
            $date_from, $date_to
        ));
        
        $por_estado = $wpdb->get_results($wpdb->prepare(
            "SELECT estado, COUNT(*) as count
            FROM {$prefix}tareas
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY estado",
            $date_from, $date_to
        ));
        
        $por_tipo = $wpdb->get_results($wpdb->prepare(
            "SELECT tipo, COUNT(*) as count
            FROM {$prefix}tareas
            WHERE DATE(created_at) BETWEEN %s AND %s
            GROUP BY tipo
            ORDER BY count DESC",
            $date_from, $date_to
        ));
        
        return [
            'tareas' => $tareas,
            'por_estado' => $por_estado,
            'por_tipo' => $por_tipo,
        ];
    }
    
    /**
     * Reporte de stock
     */
    private function get_stock_report() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT p.ID, p.post_title as name,
                    pm_sku.meta_value as sku,
                    pm_stock.meta_value as stock,
                    pm_price.meta_value as price,
                    pm_status.meta_value as stock_status
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_stock_status'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            ORDER BY CAST(pm_stock.meta_value AS SIGNED) ASC
            LIMIT 500"
        );
    }
    
    /**
     * Productos con stock bajo
     */
    private function get_low_stock_report() {
        global $wpdb;
        
        $low_stock_threshold = get_option('woocommerce_notify_low_stock_amount', 2);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title as name,
                    pm_sku.meta_value as sku,
                    pm_stock.meta_value as stock,
                    pm_price.meta_value as price
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            LEFT JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id AND pm_manage.meta_key = '_manage_stock'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm_manage.meta_value = 'yes'
            AND CAST(pm_stock.meta_value AS SIGNED) <= %d
            AND CAST(pm_stock.meta_value AS SIGNED) >= 0
            ORDER BY CAST(pm_stock.meta_value AS SIGNED) ASC",
            $low_stock_threshold
        ));
    }
    
    /**
     * Exportar reporte
     */
    public function ajax_export_report() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_export_reports')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $report_type = isset($_POST['report_type']) ? sanitize_text_field($_POST['report_type']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        
        // Generar URL temporal para descarga
        $export_token = wp_generate_password(32, false);
        set_transient('riverso_export_' . $export_token, [
            'type' => $report_type,
            'from' => $date_from,
            'to' => $date_to,
            'format' => $format,
            'user' => get_current_user_id(),
        ], 300);
        
        wp_send_json_success([
            'download_url' => admin_url('admin-ajax.php?action=riverso_download_report&token=' . $export_token),
        ]);
    }
}
