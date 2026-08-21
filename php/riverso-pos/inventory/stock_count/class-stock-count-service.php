<?php
/**
 * Servicio de conteo de inventario (Fase 3+)
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Stock_Count_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_count_start', [$this, 'ajax_start']);
        add_action('wp_ajax_riverso_count_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_riverso_count_approve', [$this, 'ajax_approve']);
    }

    /**
     * Crea tablas de sesión e ítems de conteo.
     */
    public static function create_tables() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_session = "CREATE TABLE {$prefix}conteo_sesiones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(150) DEFAULT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'abierta',
            creado_por BIGINT UNSIGNED DEFAULT NULL,
            approved_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY estado (estado)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$prefix}conteo_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sesion_id BIGINT UNSIGNED NOT NULL,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            codigo_escaneado VARCHAR(50) DEFAULT NULL,
            cantidad_sistema DECIMAL(12,3) DEFAULT 0,
            cantidad_contada DECIMAL(12,3) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sesion_id (sesion_id),
            KEY producto_base_id (producto_base_id)
        ) {$charset_collate};";

        dbDelta($sql_session);
        dbDelta($sql_items);
    }

    /**
     * Inicia sesión de conteo.
     *
     * @param array $data
     * @return int|false
     */
    public function start_session($data = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . 'conteo_sesiones'));
        if (!$exists) {
            self::create_tables();
        }

        $inserted = $wpdb->insert(
            "{$prefix}conteo_sesiones",
            [
                'nombre' => isset($data['nombre']) ? sanitize_text_field($data['nombre']) : 'Conteo ' . current_time('Y-m-d H:i'),
                'ubicacion_id' => isset($data['ubicacion_id']) ? intval($data['ubicacion_id']) : null,
                'estado' => 'abierta',
                'creado_por' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Agrega un escaneo a la sesión.
     *
     * @param int    $sesion_id
     * @param string $codigo
     * @param float  $cantidad
     * @return array|false
     */
    public function add_scan($sesion_id, $codigo, $cantidad = 1.0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $sesion_id = intval($sesion_id);
        $codigo = sanitize_text_field($codigo);
        $cantidad = floatval($cantidad);

        $sesion = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$prefix}conteo_sesiones WHERE id = %d AND estado = 'abierta'", $sesion_id),
            ARRAY_A
        );
        if (!$sesion) {
            return false;
        }

        $producto_base_id = 0;
        $factor = 1.0;

        if (class_exists('Riverso_Barcode_Model') && method_exists('Riverso_Barcode_Model', 'resolve_for_operation')) {
            $op = Riverso_Barcode_Model::resolve_for_operation($codigo);
            if (!empty($op['conflicts'])) {
                return false;
            }
            if (!empty($op['resolved'])) {
                $producto_base_id = intval($op['resolved']['producto_base_id'] ?? $op['resolved']['product_id'] ?? 0);
                $factor = floatval($op['resolved']['cantidad'] ?? $op['resolved']['factor_a_unidad_base'] ?? 1);
            }
        } elseif (class_exists('Riverso_Barcode_Model') && method_exists('Riverso_Barcode_Model', 'resolve')) {
            $resolved = Riverso_Barcode_Model::resolve($codigo);
            if ($resolved) {
                $producto_base_id = intval($resolved['producto_base_id'] ?? $resolved['product_id'] ?? 0);
                $factor = floatval($resolved['cantidad'] ?? $resolved['factor_a_unidad_base'] ?? 1);
            }
        }

        if (!$producto_base_id) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT producto_base_id, cantidad FROM {$prefix}codigo_barra
                     WHERE codigo = %s AND activo = 1 AND estado IN ('verificado', 'propuesto')
                     ORDER BY CASE WHEN estado = 'verificado' THEN 0 ELSE 1 END
                     LIMIT 1",
                    $codigo
                ),
                ARRAY_A
            );
            if ($row) {
                $producto_base_id = intval($row['producto_base_id']);
                $factor = floatval($row['cantidad'] ?: 1);
            }
        }

        if (!$producto_base_id) {
            return false;
        }

        $qty_counted = $cantidad * $factor;
        $sistema = 0.0;
        if (class_exists('Riverso_Stock_Service')) {
            $sistema = Riverso_Stock_Service::get_instance()->get_balance(
                $producto_base_id,
                $sesion['ubicacion_id'] ? intval($sesion['ubicacion_id']) : null
            );
        }

        $wpdb->insert(
            "{$prefix}conteo_items",
            [
                'sesion_id' => $sesion_id,
                'producto_base_id' => $producto_base_id,
                'codigo_escaneado' => $codigo,
                'cantidad_sistema' => $sistema,
                'cantidad_contada' => $qty_counted,
                'created_at' => current_time('mysql'),
            ]
        );

        return [
            'item_id' => (int) $wpdb->insert_id,
            'producto_base_id' => $producto_base_id,
            'cantidad_contada' => $qty_counted,
            'cantidad_sistema' => $sistema,
        ];
    }

    /**
     * Aprueba sesión y genera ajustes vía Riverso_Movement.
     *
     * @param int $sesion_id
     * @return array|false
     */
    public function approve($sesion_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $sesion_id = intval($sesion_id);

        $sesion = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$prefix}conteo_sesiones WHERE id = %d AND estado = 'abierta'", $sesion_id),
            ARRAY_A
        );
        if (!$sesion) {
            return false;
        }

        // Agregar por producto
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT producto_base_id,
                        MAX(cantidad_sistema) as cantidad_sistema,
                        SUM(cantidad_contada) as cantidad_contada
                 FROM {$prefix}conteo_items
                 WHERE sesion_id = %d
                 GROUP BY producto_base_id",
                $sesion_id
            ),
            ARRAY_A
        );

        $ajustes = [];
        foreach ((array) $items as $item) {
            $diff = floatval($item['cantidad_contada']) - floatval($item['cantidad_sistema']);
            if (abs($diff) < 0.0001) {
                continue;
            }

            $movement_id = false;
            if (class_exists('Riverso_Movement')) {
                $meta = [
                    'referencia_tipo' => 'conteo',
                    'referencia_id' => $sesion_id,
                    'notas' => 'Ajuste por conteo #' . $sesion_id,
                ];
                if (!empty($sesion['ubicacion_id'])) {
                    $meta['ubicacion_destino'] = intval($sesion['ubicacion_id']);
                }
                $movement_id = Riverso_Movement::create(
                    'ajuste',
                    intval($item['producto_base_id']),
                    abs($diff),
                    $meta
                );
            }

            $ajustes[] = [
                'producto_base_id' => intval($item['producto_base_id']),
                'diff' => $diff,
                'movement_id' => $movement_id,
            ];
        }

        $wpdb->update(
            "{$prefix}conteo_sesiones",
            [
                'estado' => 'aprobada',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql'),
            ],
            ['id' => $sesion_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        if (function_exists('riverso_event_publish')) {
            riverso_event_publish('stock_count.approved', [
                'sesion_id' => $sesion_id,
                'ajustes' => $ajustes,
            ], ['user_id' => get_current_user_id()]);
        }

        return ['sesion_id' => $sesion_id, 'ajustes' => $ajustes];
    }

    public function ajax_start() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $id = $this->start_session([
            'nombre' => isset($_POST['nombre']) ? sanitize_text_field(wp_unslash($_POST['nombre'])) : null,
            'ubicacion_id' => isset($_POST['ubicacion_id']) ? intval($_POST['ubicacion_id']) : null,
        ]);
        if (!$id) {
            wp_send_json_error(['message' => 'No se pudo iniciar conteo']);
        }
        wp_send_json_success(['sesion_id' => $id]);
    }

    public function ajax_scan() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->add_scan(
            isset($_POST['sesion_id']) ? intval($_POST['sesion_id']) : 0,
            isset($_POST['codigo']) ? sanitize_text_field(wp_unslash($_POST['codigo'])) : '',
            isset($_POST['cantidad']) ? floatval($_POST['cantidad']) : 1
        );
        if (!$result) {
            wp_send_json_error(['message' => 'Escaneo inválido']);
        }
        wp_send_json_success($result);
    }

    public function ajax_approve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->approve(isset($_POST['sesion_id']) ? intval($_POST['sesion_id']) : 0);
        if (!$result) {
            wp_send_json_error(['message' => 'No se pudo aprobar']);
        }
        wp_send_json_success($result);
    }
}
