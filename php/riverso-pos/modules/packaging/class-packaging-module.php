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
require_once RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
require_once RIVERSO_POS_PLUGIN_DIR . 'inventory/movements/class-movement.php';

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

    public static function allowed_tipo_envase() {
        global $wpdb;
        $defaults = ['envase', 'caja', 'balde', 'bolsa_fabrica', 'bolsa_interna', 'otro'];
        $table = $wpdb->prefix . 'riverso_envase_tipos';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return $defaults;
        }
        $slugs = $wpdb->get_col("SELECT slug FROM {$table} WHERE activo = 1") ?: [];
        return array_values(array_unique(array_merge($defaults, $slugs)));
    }

    public function create_envase($producto_base_id, $cantidad_unidades, $sku_envase = '', $variation_id = 0, $extra = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $cantidad_unidades = floatval($cantidad_unidades);
        if (!$producto_base_id || $cantidad_unidades <= 0) {
            return new WP_Error('invalid', 'Producto base y cantidad de unidades requeridos');
        }

        $tipo_envase = sanitize_key($extra['tipo_envase'] ?? 'envase');
        $allowed_types = self::allowed_tipo_envase();
        if (!in_array($tipo_envase, $allowed_types, true)) {
            $tipo_envase = 'otro';
        }

        $wpdb->insert("{$prefix}envases", [
            'producto_base_id' => $producto_base_id,
            'sku_envase' => $sku_envase ? sanitize_text_field($sku_envase) : null,
            'woocommerce_variation_id' => intval($variation_id),
            'cantidad_unidades' => $cantidad_unidades,
            'producto_proveedor_id' => intval($extra['producto_proveedor_id'] ?? 0) ?: null,
            'proveedor_id' => intval($extra['proveedor_id'] ?? 0) ?: null,
            'codigo_proveedor' => !empty($extra['codigo_proveedor']) ? sanitize_text_field($extra['codigo_proveedor']) : null,
            'tipo_envase' => $tipo_envase,
            'es_vendible' => !empty($extra['es_vendible']) ? 1 : 0,
            'lleva_stock_propio' => !empty($extra['lleva_stock_propio']) ? 1 : 0,
            'permite_apertura' => isset($extra['permite_apertura']) ? (!empty($extra['permite_apertura']) ? 1 : 0) : 1,
            'origen_datos' => sanitize_key($extra['origen_datos'] ?? 'manual'),
            'requires_human_review' => !empty($extra['requires_human_review']) ? 1 : 0,
            'review_status' => !empty($extra['requires_human_review']) ? 'pendiente' : 'aprobado',
            'activo' => 1,
        ], ['%d', '%s', '%d', '%f', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%d']);

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
        if (isset($envase['permite_apertura']) && empty($envase['permite_apertura'])) {
            return new WP_Error('not_allowed', 'Esta presentación no permite apertura.');
        }

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            intval($envase['producto_base_id'])
        ), ARRAY_A);
        if (!$pb) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        // Regla BR-005: solo se puede abrir si el stock abierto está habilitado (miembro o unitario).
        $unit_base_id = Riverso_Unit_Product_Service::get_instance()->resolve_unit_for_base(intval($pb['id']));
        $stock_target_id = $unit_base_id ?: intval($pb['id']);
        $stock_target = $unit_base_id
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}producto_base WHERE id = %d", $stock_target_id), ARRAY_A)
            : $pb;

        if (empty($stock_target['stock_abierto_habilitado']) && !$unit_base_id) {
            return new WP_Error('not_allowed', 'El producto no permite stock abierto (BR-005)');
        }
        if ($unit_base_id && empty($stock_target['stock_abierto_habilitado'])) {
            $wpdb->update(
                "{$prefix}producto_base",
                ['stock_abierto_habilitado' => 1],
                ['id' => $stock_target_id],
                ['%d'],
                ['%d']
            );
        }

        $unidades = $cantidad_envases * (float) $envase['cantidad_unidades'];

        // Costo unitario: del lote indicado o c_ref local.
        $costo_unitario = null;
        if ($lote_id) {
            $lote = $wpdb->get_row($wpdb->prepare(
                "SELECT costo_unitario, cantidad_disponible FROM {$prefix}lotes WHERE id = %d",
                intval($lote_id)
            ), ARRAY_A);
            if (!$lote || (float) $lote['cantidad_disponible'] < $cantidad_envases) {
                return new WP_Error('insufficient_closed_stock', 'Stock cerrado insuficiente en el lote seleccionado.');
            }
            $costo_unitario = $lote['costo_unitario'];
        }
        if ($costo_unitario === null && class_exists('Riverso_Pricing_Module')) {
            $costo_unitario = Riverso_Pricing_Module::get_instance()->calculate_c_ref_local($pb['id']);
        }

        $stock_anterior = (float) ($stock_target['stock_abierto'] ?? 0);
        $stock_nuevo = $stock_anterior + $unidades;

        $wc_id = intval($envase['woocommerce_variation_id']) ?: intval($pb['woocommerce_variation_id']) ?: intval($pb['woocommerce_product_id']);
        $wc_product = null;
        if ($wc_id && function_exists('wc_get_product')) {
            $wc_product = wc_get_product($wc_id);
            if ($wc_product && $wc_product->managing_stock()) {
                $closed_stock = $wc_product->get_stock_quantity();
                if ($closed_stock !== null && (float) $closed_stock < $cantidad_envases) {
                    return new WP_Error('insufficient_closed_stock', 'Stock cerrado insuficiente en WooCommerce.');
                }
            }
        }

        $wpdb->query('START TRANSACTION');

        // 1. Aumentar inventario abierto (unitario si existe, si no miembro — dual-write).
        if ($unit_base_id) {
            $updated_open = $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_base
                 SET stock_abierto = stock_abierto + %f, stock_abierto_habilitado = 1
                 WHERE id = %d AND stock_abierto = %f",
                $unidades,
                $unit_base_id,
                $stock_anterior
            ));
            if ($updated_open !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('concurrent_stock_change', 'El stock unitario cambió durante la apertura.');
            }

            $ubicacion_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
                 WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
                $unit_base_id
            ));
            if (!$ubicacion_id) {
                $ubicacion_id = (int) $wpdb->get_var(
                    "SELECT id FROM {$prefix}ubicaciones WHERE activo = 1 ORDER BY id ASC LIMIT 1"
                );
            }
            if ($ubicacion_id && class_exists('Riverso_Movement')) {
                Riverso_Movement::create('entrada', $unit_base_id, $unidades, [
                    'ubicacion_destino' => $ubicacion_id,
                    'referencia_tipo' => 'apertura',
                    'notas' => sprintf('Apertura envase #%d → unitario', $envase_id),
                ]);
            }
            $stock_nuevo = $stock_anterior + $unidades;
        } else {
            $updated_open = $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_base
                 SET stock_abierto = stock_abierto + %f
                 WHERE id = %d AND stock_abierto = %f",
                $unidades,
                $stock_target_id,
                $stock_anterior
            ));
            if ($updated_open !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('concurrent_stock_change', 'El stock cambió durante la apertura. Intenta nuevamente.');
            }
        }

        // Dual-write en miembro para compatibilidad con compute_family_stock legacy.
        if ($unit_base_id && $stock_target_id !== intval($pb['id'])) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_base SET stock_abierto = stock_abierto + %f WHERE id = %d",
                $unidades,
                intval($pb['id'])
            ));
        }

        // 2. Descontar stock cerrado (lote si aplica).
        if ($lote_id) {
            $updated_lot = $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}lotes
                 SET cantidad_disponible = cantidad_disponible - %f
                 WHERE id = %d AND cantidad_disponible >= %f",
                $cantidad_envases,
                intval($lote_id),
                $cantidad_envases
            ));
            if ($updated_lot !== 1) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('concurrent_closed_stock_change', 'El lote cambió durante la apertura.');
            }
        }

        // 3. Descontar stock cerrado en WooCommerce (envase = variación/producto).
        if ($wc_product && $wc_product->managing_stock()) {
            $wc_stock_result = wc_update_product_stock($wc_product, $cantidad_envases, 'decrease');
            if ($wc_stock_result === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wc_stock_update_failed', 'No se pudo descontar el stock cerrado en WooCommerce.');
            }
        }

        // 4. Registrar apertura.
        $opened = $wpdb->insert("{$prefix}aperturas", [
            'envase_id' => $envase_id,
            'producto_base_id' => $stock_target_id,
            'lote_id' => $lote_id ? intval($lote_id) : null,
            'cantidad_envases' => $cantidad_envases,
            'cantidad_abierta' => $unidades,
            'costo_unitario' => $costo_unitario !== null ? (float) $costo_unitario : null,
            'usuario_id' => get_current_user_id(),
        ], ['%d', '%d', '%d', '%f', '%f', '%f', '%d']);
        if (!$opened) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('opening_log_failed', 'No se pudo registrar la apertura.');
        }
        $apertura_id = (int) $wpdb->insert_id;

        // 5. Movimiento de inventario (tipo apertura).
        $movement = $wpdb->insert("{$prefix}movimientos", [
            'product_id' => intval($stock_target['woocommerce_product_id'] ?: $pb['woocommerce_product_id']),
            'variation_id' => intval($envase['woocommerce_variation_id']) ?: null,
            'lote_id' => $lote_id ? intval($lote_id) : null,
            'tipo' => 'apertura',
            'cantidad' => $unidades,
            'stock_anterior' => $stock_anterior,
            'stock_nuevo' => $stock_nuevo,
            'referencia_tipo' => 'apertura',
            'referencia_id' => $apertura_id,
            'notas' => sprintf(
                'Apertura de %s envase(s) = %s unidades abiertas%s',
                $cantidad_envases,
                $unidades,
                $unit_base_id ? ' (unitario)' : ''
            ),
            'usuario_id' => get_current_user_id(),
        ]);
        if (!$movement) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('movement_failed', 'No se pudo registrar el movimiento de apertura.');
        }

        $wpdb->query('COMMIT');

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('envase_abierto', 'producto_base', $stock_target_id, [
                'old_value' => ['stock_abierto' => $stock_anterior],
                'new_value' => ['stock_abierto' => $stock_nuevo, 'unit_base_id' => $unit_base_id],
                'details' => 'Apertura de envase',
            ]);
        }

        return [
            'apertura_id' => $apertura_id,
            'unidades_abiertas' => $unidades,
            'stock_abierto' => $stock_nuevo,
            'unit_producto_base_id' => $unit_base_id ?: intval($pb['id']),
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

        $unit_base_id = Riverso_Unit_Product_Service::get_instance()->resolve_unit_for_base($producto_base_id);
        $stock_target_id = $unit_base_id ?: $producto_base_id;
        $stock_pb = $unit_base_id
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}producto_base WHERE id = %d", $stock_target_id), ARRAY_A)
            : $pb;

        $stock_abierto = (float) ($stock_pb['stock_abierto'] ?? 0);
        if ($cantidad > $stock_abierto) {
            return new WP_Error('insufficient', 'Stock abierto insuficiente (' . $stock_abierto . ')');
        }

        // Costo unitario y total.
        $costo_unitario = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()->calculate_c_ref_local($stock_target_id)
            : null;

        // SKU de la bolsa y EAN13 (formato interno 2SSSSSSQQQQQX) — siempre SKU del unitario.
        $sku_base = $stock_pb['canonical_sku'] ?: ('B' . $stock_target_id);
        $sku_bolsa = $sku_base . '-B' . (int) $cantidad;
        $ean13 = null;
        if (!empty($stock_pb['permite_ean13_personalizado'])) {
            $ean13 = Riverso_EAN13_Generator::build_for_product($stock_target_id, $cantidad);
            if (is_wp_error($ean13)) {
                return $ean13;
            }
        }

        $wpdb->query('START TRANSACTION');
        $stock_updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}producto_base
             SET stock_abierto = stock_abierto - %f
             WHERE id = %d AND stock_abierto >= %f AND stock_abierto = %f",
            $cantidad,
            $stock_target_id,
            $cantidad,
            $stock_abierto
        ));
        if ($stock_updated !== 1) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('concurrent_stock_change', 'El stock abierto cambió. Intenta nuevamente.');
        }

        if ($unit_base_id && $stock_target_id !== $producto_base_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$prefix}producto_base
                 SET stock_abierto = GREATEST(0, stock_abierto - %f)
                 WHERE id = %d",
                $cantidad,
                $producto_base_id
            ));
        }

        $ubicacion_id = 0;
        if ($unit_base_id) {
            $ubicacion_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ubicacion_id FROM {$prefix}producto_ubicacion_preferida
                 WHERE producto_base_id = %d AND es_preferido = 1 LIMIT 1",
                $stock_target_id
            ));
        }

        $bag_inserted = $wpdb->insert("{$prefix}bolsas", [
            'producto_base_id' => $stock_target_id,
            'cantidad' => $cantidad,
            'sku_bolsa' => $sku_bolsa,
            'ean13' => $ean13,
            'costo_unitario' => $costo_unitario !== null ? (float) $costo_unitario : null,
            'estado' => 'generada',
            'usuario_id' => get_current_user_id(),
            'created_by_system' => 0,
            'requires_human_review' => 1,
        ], ['%d', '%f', '%s', '%s', '%f', '%s', '%d', '%d', '%d']);
        if (!$bag_inserted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('bag_insert_failed', 'No se pudo registrar la bolsa.');
        }
        $bolsa_id = (int) $wpdb->insert_id;

        // Movimiento de salida del stock abierto.
        if ($unit_base_id && $ubicacion_id && class_exists('Riverso_Movement')) {
            Riverso_Movement::create('salida', $stock_target_id, $cantidad, [
                'ubicacion_origen' => $ubicacion_id,
                'referencia_tipo' => 'bolsa',
                'referencia_id' => $bolsa_id,
                'notas' => 'Generación de bolsa ' . $sku_bolsa,
            ]);
        }

        $movement = $wpdb->insert("{$prefix}movimientos", [
            'product_id' => intval($stock_pb['woocommerce_product_id'] ?: $pb['woocommerce_product_id']),
            'tipo' => 'embolsado',
            'cantidad' => $cantidad,
            'stock_anterior' => $stock_abierto,
            'stock_nuevo' => $stock_abierto - $cantidad,
            'referencia_tipo' => 'bolsa',
            'referencia_id' => $bolsa_id,
            'notas' => 'Generación de bolsa ' . $sku_bolsa . ($unit_base_id ? ' (unitario)' : ''),
            'usuario_id' => get_current_user_id(),
        ]);
        if (!$movement) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('movement_failed', 'No se pudo registrar el movimiento de embolsado.');
        }

        $wpdb->query('COMMIT');

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

        if ($ean13 && class_exists('Riverso_Barcode_Model')) {
            Riverso_Barcode_Model::create(
                $ean13,
                'internal',
                $producto_base_id,
                $cantidad,
                $pb['unidad_base'] ?: 'unidad',
                null,
                null,
                $cantidad
            );
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
            'unit_producto_base_id' => $stock_target_id,
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
            intval($_POST['woocommerce_variation_id'] ?? 0),
            [
                'producto_proveedor_id' => intval($_POST['producto_proveedor_id'] ?? 0),
                'proveedor_id' => intval($_POST['proveedor_id'] ?? 0),
                'codigo_proveedor' => sanitize_text_field($_POST['codigo_proveedor'] ?? ''),
                'tipo_envase' => sanitize_key($_POST['tipo_envase'] ?? 'envase'),
                'es_vendible' => !empty($_POST['es_vendible']),
                'lleva_stock_propio' => !empty($_POST['lleva_stock_propio']),
                'permite_apertura' => !isset($_POST['permite_apertura']) || !empty($_POST['permite_apertura']),
            ]
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
