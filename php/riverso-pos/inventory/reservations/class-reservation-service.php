<?php
/**
 * Servicio de reservas de stock (Fase 3+)
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Reservation_Service {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_reserve_stock', [$this, 'ajax_reserve']);
        add_action('wp_ajax_riverso_release_stock', [$this, 'ajax_release']);
    }

    /**
     * Crea tabla riverso_reservas.
     */
    public static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_reservas';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Debe coincidir con create_erp_phase_tables() del activator.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
            cantidad DECIMAL(12,4) NOT NULL DEFAULT 0,
            origen VARCHAR(30) NOT NULL DEFAULT 'pos',
            referencia_tipo VARCHAR(50) DEFAULT NULL,
            referencia_id BIGINT UNSIGNED DEFAULT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'activa',
            usuario_id BIGINT UNSIGNED DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            released_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_producto_estado (producto_base_id, estado),
            KEY idx_ubicacion (ubicacion_id),
            KEY idx_referencia (referencia_tipo, referencia_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Completa columnas faltantes en installs con schema antiguo.
     */
    private static function ensure_schema_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_reservas';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns)) {
            return;
        }

        $needed = [
            'ubicacion_id' => 'ubicacion_id BIGINT UNSIGNED DEFAULT NULL',
            'expires_at' => 'expires_at DATETIME DEFAULT NULL',
            'origen' => "origen VARCHAR(30) NOT NULL DEFAULT 'pos'",
        ];

        foreach ($needed as $name => $definition) {
            if (!in_array($name, $columns, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$definition}");
            }
        }
    }

    /**
     * Reserva stock.
     *
     * @param int   $producto_base_id
     * @param float $cantidad
     * @param array $meta
     * @return int|false
     */
    public function reserve($producto_base_id, $cantidad, $meta = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_reservas';
        $producto_base_id = intval($producto_base_id);
        $cantidad = floatval($cantidad);

        if ($producto_base_id <= 0 || $cantidad <= 0) {
            return false;
        }

        // Asegurar tabla
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            self::create_tables();
        }

        $available = class_exists('Riverso_Stock_Service')
            ? Riverso_Stock_Service::get_instance()->get_available($producto_base_id, $meta['ubicacion_id'] ?? null)
            : $cantidad;

        if ($available < $cantidad) {
            return false;
        }

        $row = [
            'producto_base_id' => $producto_base_id,
            'ubicacion_id' => isset($meta['ubicacion_id']) ? intval($meta['ubicacion_id']) : null,
            'cantidad' => $cantidad,
            'origen' => isset($meta['origen']) ? sanitize_text_field($meta['origen']) : 'pos',
            'referencia_tipo' => isset($meta['referencia_tipo']) ? sanitize_text_field($meta['referencia_tipo']) : null,
            'referencia_id' => isset($meta['referencia_id']) ? intval($meta['referencia_id']) : null,
            'estado' => 'activa',
            'usuario_id' => get_current_user_id(),
            'expires_at' => isset($meta['expires_at']) ? sanitize_text_field($meta['expires_at']) : null,
            'created_at' => current_time('mysql'),
        ];

        // Si la tabla aún no tiene columnas nuevas, intentar completar schema.
        self::ensure_schema_columns();

        $inserted = $wpdb->insert($table, $row);

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Libera una reserva.
     *
     * @param int $reservation_id
     * @return bool
     */
    public function release($reservation_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_reservas';
        $reservation_id = intval($reservation_id);

        $updated = $wpdb->update(
            $table,
            [
                'estado' => 'liberada',
                'released_at' => current_time('mysql'),
            ],
            [
                'id' => $reservation_id,
                'estado' => 'activa',
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Cantidad reservada activa.
     *
     * @param int      $producto_base_id
     * @param int|null $ubicacion_id
     * @return float
     */
    public function get_reserved($producto_base_id, $ubicacion_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_reservas';
        $producto_base_id = intval($producto_base_id);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) {
            return 0.0;
        }

        if ($ubicacion_id) {
            $qty = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(cantidad) FROM {$table}
                     WHERE producto_base_id = %d AND estado = 'activa' AND ubicacion_id = %d",
                    $producto_base_id,
                    intval($ubicacion_id)
                )
            );
        } else {
            $qty = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT SUM(cantidad) FROM {$table}
                     WHERE producto_base_id = %d AND estado = 'activa'",
                    $producto_base_id
                )
            );
        }

        return floatval($qty ?? 0);
    }

    public function ajax_reserve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $id = $this->reserve(
            isset($_POST['producto_base_id']) ? intval($_POST['producto_base_id']) : 0,
            isset($_POST['cantidad']) ? floatval($_POST['cantidad']) : 0,
            [
                'ubicacion_id' => isset($_POST['ubicacion_id']) ? intval($_POST['ubicacion_id']) : null,
                'referencia_tipo' => isset($_POST['referencia_tipo']) ? sanitize_text_field(wp_unslash($_POST['referencia_tipo'])) : null,
                'referencia_id' => isset($_POST['referencia_id']) ? intval($_POST['referencia_id']) : null,
            ]
        );

        if (!$id) {
            wp_send_json_error(['message' => 'No se pudo reservar']);
        }
        wp_send_json_success(['reservation_id' => $id]);
    }

    public function ajax_release() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_edit_stock') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $ok = $this->release(isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0);
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo liberar']);
        }
        wp_send_json_success(['released' => true]);
    }
}
