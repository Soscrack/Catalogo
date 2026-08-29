<?php
/**
 * Módulo de Manufactura [WIP] — orquesta procesos (Embolsar).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/packaging/class-packaging-module.php';

class Riverso_Manufacturing_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_manufacturing_families', [$this, 'ajax_list_families']);
        add_action('wp_ajax_riverso_manufacturing_start', [$this, 'ajax_start_order']);
        add_action('wp_ajax_riverso_manufacturing_open', [$this, 'ajax_step_open']);
        add_action('wp_ajax_riverso_manufacturing_bolsa', [$this, 'ajax_step_bolsa']);
        add_action('wp_ajax_riverso_manufacturing_print', [$this, 'ajax_step_print']);
        add_action('wp_ajax_riverso_manufacturing_get', [$this, 'ajax_get_order']);
    }

    public static function create_tables() {
        return true;
    }

    private function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    public function ajax_list_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $rows = $wpdb->get_results(
            "SELECT g.id, g.codigo_grupo, g.nombre, g.unit_producto_base_id, g.es_producto_unitario,
                    pb.canonical_sku, pb.nombre_canonico, pb.stock_abierto
             FROM {$prefix}equivalence_groups g
             LEFT JOIN {$prefix}producto_base pb ON pb.id = g.unit_producto_base_id
             WHERE g.activo = 1 AND g.es_producto_unitario = 1 AND g.unit_producto_base_id IS NOT NULL
             ORDER BY g.nombre ASC
             LIMIT 500",
            ARRAY_A
        );

        foreach ($rows as &$row) {
            $row['envases'] = $wpdb->get_results($wpdb->prepare(
                "SELECT e.id, e.cantidad_unidades, e.tipo_envase, e.sku_envase, e.codigo_proveedor,
                        e.producto_base_id, pb2.canonical_sku AS miembro_sku
                 FROM {$prefix}envases e
                 INNER JOIN {$prefix}equivalence_members em ON em.producto_base_id = e.producto_base_id AND em.grupo_id = %d AND em.activo = 1
                 INNER JOIN {$prefix}producto_base pb2 ON pb2.id = e.producto_base_id
                 WHERE e.activo = 1 AND e.permite_apertura = 1 AND e.cantidad_unidades > 1
                 ORDER BY e.cantidad_unidades DESC",
                intval($row['id'])
            ), ARRAY_A) ?: [];
        }
        unset($row);

        wp_send_json_success(['families' => $rows ?: []]);
    }

    public function ajax_start_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $unit_id = Riverso_Unit_Product_Service::get_instance()->get_unit_base_id($grupo_id);
        if (!$unit_id) {
            wp_send_json_error(['message' => 'La familia no tiene producto unitario configurado']);
        }

        $wpdb->insert("{$prefix}manufactura_ordenes", [
            'tipo_proceso' => sanitize_key($_POST['tipo_proceso'] ?? 'embolsar'),
            'grupo_id' => $grupo_id,
            'unit_producto_base_id' => $unit_id,
            'estado' => 'en_proceso',
            'usuario_id' => get_current_user_id(),
            'notas' => sanitize_textarea_field($_POST['notas'] ?? ''),
        ], ['%s', '%d', '%d', '%s', '%d', '%s']);

        $orden_id = (int) $wpdb->insert_id;
        wp_send_json_success(['orden_id' => $orden_id]);
    }

    public function ajax_step_open() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $orden_id = absint($_POST['orden_id'] ?? 0);
        $envase_id = absint($_POST['envase_id'] ?? 0);
        $cantidad = floatval($_POST['cantidad_envases'] ?? 1);

        $packaging = Riverso_Packaging_Module::get_instance();
        $result = $packaging->open_envase($envase_id, $cantidad, !empty($_POST['lote_id']) ? absint($_POST['lote_id']) : null);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $this->log_step($orden_id, 'abrir', 'apertura', intval($result['apertura_id']), wp_json_encode($result));
        wp_send_json_success($result);
    }

    public function ajax_step_bolsa() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $orden_id = absint($_POST['orden_id'] ?? 0);
        $orden = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}manufactura_ordenes WHERE id = %d",
            $orden_id
        ), ARRAY_A);
        if (!$orden) {
            wp_send_json_error(['message' => 'Orden no encontrada']);
        }

        $unit_id = intval($orden['unit_producto_base_id']);
        $cantidad = floatval($_POST['cantidad'] ?? 0);
        $result = Riverso_Packaging_Module::get_instance()->create_bolsa($unit_id, $cantidad);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $this->log_step($orden_id, 'embolsar', 'bolsa', intval($result['bolsa_id']), wp_json_encode($result));
        wp_send_json_success($result);
    }

    public function ajax_step_print() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $orden_id = absint($_POST['orden_id'] ?? 0);
        $bolsa_id = absint($_POST['bolsa_id'] ?? 0);

        $bolsa = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, pb.nombre_canonico, pb.canonical_sku
             FROM {$prefix}bolsas b
             INNER JOIN {$prefix}producto_base pb ON pb.id = b.producto_base_id
             WHERE b.id = %d",
            $bolsa_id
        ), ARRAY_A);
        if (!$bolsa) {
            wp_send_json_error(['message' => 'Bolsa no encontrada']);
        }

        $precio = null;
        if (class_exists('Riverso_Pricing_Module')) {
            $pr = Riverso_Pricing_Module::get_instance()->get_local_price(intval($bolsa['producto_base_id']));
            if ($pr && $pr['p_asignado'] !== null) {
                $precio = (float) $pr['p_asignado'];
            }
        }

        $user = wp_get_current_user();
        $numero = 'MFG-' . gmdate('Ymd') . '-' . wp_rand(1000, 9999);
        $copias = max(1, absint($_POST['copias'] ?? 1));

        $wpdb->insert("{$prefix}ordenes_impresion", [
            'numero_orden' => $numero,
            'estado' => 'borrador',
            'tipo' => 'bolsa',
            'prioridad' => 0,
            'notas' => 'Generada desde Manufactura orden #' . $orden_id,
            'solicitado_por' => $user->ID,
            'solicitado_por_nombre' => $user->display_name,
            'total_items' => 1,
            'total_copias' => $copias,
        ], ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d']);

        $print_order_id = (int) $wpdb->insert_id;
        if (!$print_order_id) {
            wp_send_json_error(['message' => 'No se pudo crear orden de impresión']);
        }

        $wpdb->insert("{$prefix}orden_impresion_items", [
            'orden_id' => $print_order_id,
            'sku' => substr((string) $bolsa['sku_bolsa'], 0, 50),
            'nombre' => substr($bolsa['nombre_canonico'] ?: ('Bolsa ' . $bolsa['sku_bolsa']), 0, 255),
            'precio' => $precio,
            'cantidad_ean' => max(1, (int) $bolsa['cantidad']),
            'copias' => $copias,
            'modo' => 'BolsaCOD',
            'color' => sanitize_text_field($_POST['color'] ?? 'BN'),
            'ean13' => preg_replace('/\D+/', '', (string) ($bolsa['ean13'] ?? '')),
            'impreso' => 0,
            'orden_posicion' => 0,
        ], ['%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s', '%d', '%d']);

        $this->log_step($orden_id, 'etiquetar', 'orden_impresion', $print_order_id, wp_json_encode([
            'bolsa_id' => $bolsa_id,
            'ean13' => $bolsa['ean13'],
        ]));

        $wpdb->update(
            "{$prefix}manufactura_ordenes",
            ['estado' => 'completada'],
            ['id' => $orden_id],
            ['%s'],
            ['%d']
        );

        wp_send_json_success([
            'print_order_id' => $print_order_id,
            'numero_orden' => $numero,
            'bolsa' => $bolsa,
        ]);
    }

    public function ajax_get_order() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_manufacturing')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $this->prefix();
        $orden_id = absint($_POST['orden_id'] ?? 0);
        $orden = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}manufactura_ordenes WHERE id = %d",
            $orden_id
        ), ARRAY_A);
        if (!$orden) {
            wp_send_json_error(['message' => 'Orden no encontrada']);
        }
        $pasos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}manufactura_pasos WHERE orden_id = %d ORDER BY id ASC",
            $orden_id
        ), ARRAY_A);
        wp_send_json_success(['orden' => $orden, 'pasos' => $pasos ?: []]);
    }

    private function log_step($orden_id, $paso, $ref_tipo, $ref_id, $detalle = '') {
        if (!$orden_id) {
            return;
        }
        global $wpdb;
        $prefix = $this->prefix();
        $wpdb->insert("{$prefix}manufactura_pasos", [
            'orden_id' => intval($orden_id),
            'paso' => sanitize_key($paso),
            'referencia_tipo' => sanitize_key($ref_tipo),
            'referencia_id' => $ref_id ? intval($ref_id) : null,
            'detalle' => $detalle,
        ], ['%d', '%s', '%s', '%d', '%s']);
    }
}
