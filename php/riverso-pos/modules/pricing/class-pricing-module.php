<?php
/**
 * Módulo de Precios - Riverso POS
 *
 * Motor de precios LOCAL y ONLINE sobre el dominio canónico (producto_base /
 * producto_proveedor / lotes / equivalencias).
 *
 * PRECIO LOCAL:
 *   c_ref = MAX(costo_unitario) entre los lotes de todos los producto_proveedor
 *           del producto_base (agrupando equivalentes del mismo grupo activo).
 *   p_ref = factor_objetivo * c_ref      (1.8 por defecto)
 *   alarma si p_asignado < factor_minimo * c_ref   (1.3 por defecto)
 *
 * PRECIO ONLINE (WooCommerce):
 *   c_ref = costo_unitario del envase/lote específico (sin agrupación).
 *   p_ref = factor_objetivo * c_ref
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Pricing_Module {

    private static $instance = null;

    const CANAL_LOCAL  = 'local';
    const CANAL_ONLINE = 'online';

    const FACTOR_MINIMO_DEFAULT  = 1.30;
    const FACTOR_OBJETIVO_DEFAULT = 1.80;
    const FACTOR_MAXIMO_DEFAULT  = 3.00;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa hooks AJAX y de recálculo automático.
     */
    public function init() {
        add_action('wp_ajax_riverso_pricing_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_pricing_recalc', [$this, 'ajax_recalc']);
        add_action('wp_ajax_riverso_pricing_set_assigned', [$this, 'ajax_set_assigned']);
        add_action('wp_ajax_riverso_pricing_approve', [$this, 'ajax_approve']);
        add_action('wp_ajax_riverso_pricing_alerts', [$this, 'ajax_alerts']);
        add_action('wp_ajax_riverso_pricing_sync_online', [$this, 'ajax_sync_online']);

        // Recálculo automático al aprobar factura (nuevos lotes/costos).
        add_action('riverso_pos_invoice_approved', [$this, 'on_invoice_approved'], 10, 1);
        // Recálculo cuando un proceso registra un lote.
        add_action('riverso_pos_lote_registrado', [$this, 'on_lote_registrado'], 10, 1);

        // Cargar e inicializar el submódulo de reglas de precio (misma carpeta).
        require_once __DIR__ . '/class-price-rules-module.php';
        Riverso_Price_Rules_Module::get_instance()->init();
    }

    /**
     * Crea las tablas de precios (incluye las de reglas de precio).
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$prefix}precios (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            producto_base_id BIGINT UNSIGNED NOT NULL,
            canal VARCHAR(10) NOT NULL DEFAULT 'local',
            woocommerce_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            c_ref DECIMAL(12,4) DEFAULT NULL,
            p_ref DECIMAL(12,2) DEFAULT NULL,
            p_asignado DECIMAL(12,2) DEFAULT NULL,
            factor_minimo DECIMAL(5,2) NOT NULL DEFAULT 1.30,
            factor_objetivo DECIMAL(5,2) NOT NULL DEFAULT 1.80,
            factor_maximo_referencia DECIMAL(5,2) NOT NULL DEFAULT 3.00,
            estado_aprobacion VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            alerta_margen TINYINT(1) NOT NULL DEFAULT 0,
            regla_id BIGINT UNSIGNED DEFAULT NULL,
            aprobado_por BIGINT UNSIGNED DEFAULT NULL,
            aprobado_at DATETIME DEFAULT NULL,
            created_by_system TINYINT(1) NOT NULL DEFAULT 0,
            requires_human_review TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_precio_canal (producto_base_id, canal, woocommerce_variation_id),
            KEY idx_canal (canal),
            KEY idx_alerta (alerta_margen),
            KEY idx_estado (estado_aprobacion)
        ) $charset_collate;";
        dbDelta($sql);

        // Tablas del submódulo de reglas de precio.
        require_once __DIR__ . '/class-price-rules-module.php';
        Riverso_Price_Rules_Module::create_tables();
    }

    /* ===================== Cálculo de costo de referencia ===================== */

    /**
     * Devuelve los IDs de producto_base equivalentes (incluyéndose a sí mismo)
     * que comparten algún grupo de equivalencia activo.
     *
     * @param int $producto_base_id
     * @return int[]
     */
    public function get_equivalent_base_ids($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $ids = [$producto_base_id];

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT em2.producto_base_id
             FROM {$prefix}equivalence_members em1
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em1.grupo_id
             INNER JOIN {$prefix}equivalence_members em2 ON em2.grupo_id = em1.grupo_id
             WHERE em1.producto_base_id = %d
               AND em1.activo = 1
               AND em2.activo = 1
               AND g.activo = 1",
            $producto_base_id
        ));

        if ($rows) {
            foreach ($rows as $id) {
                $ids[] = intval($id);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * c_ref LOCAL: MAX(costo_unitario) entre lotes de los producto_proveedor del
     * producto_base y sus equivalentes activos.
     *
     * @param int $producto_base_id
     * @return float|null
     */
    public function calculate_c_ref_local($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $base_ids = $this->get_equivalent_base_ids($producto_base_id);
        if (empty($base_ids)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($base_ids), '%d'));

        $max = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(l.costo_unitario)
             FROM {$prefix}lotes l
             INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
             WHERE pp.producto_base_id IN ($placeholders)
               AND l.costo_unitario IS NOT NULL
               AND l.estado <> 'bloqueado'",
            ...$base_ids
        ));

        return $max !== null ? (float) $max : null;
    }

    /**
     * c_ref ONLINE: costo_unitario del envase/lote específico (por variación
     * WooCommerce). Toma el lote más reciente con costo para esa variación.
     *
     * @param int $producto_base_id
     * @param int $woocommerce_variation_id
     * @return float|null
     */
    public function calculate_c_ref_online($producto_base_id, $woocommerce_variation_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $woocommerce_variation_id = intval($woocommerce_variation_id);

        if ($woocommerce_variation_id > 0) {
            $cost = $wpdb->get_var($wpdb->prepare(
                "SELECT l.costo_unitario
                 FROM {$prefix}lotes l
                 WHERE l.variation_id = %d
                   AND l.costo_unitario IS NOT NULL
                   AND l.estado <> 'bloqueado'
                 ORDER BY l.fecha_recepcion DESC
                 LIMIT 1",
                $woocommerce_variation_id
            ));
            if ($cost !== null) {
                return (float) $cost;
            }
        }

        // Sin variación: último costo del producto_base por su producto_proveedor.
        $cost = $wpdb->get_var($wpdb->prepare(
            "SELECT l.costo_unitario
             FROM {$prefix}lotes l
             INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
             WHERE pp.producto_base_id = %d
               AND l.costo_unitario IS NOT NULL
               AND l.estado <> 'bloqueado'
             ORDER BY l.fecha_recepcion DESC
             LIMIT 1",
            intval($producto_base_id)
        ));

        return $cost !== null ? (float) $cost : null;
    }

    /* ===================== Recálculo y persistencia ===================== */

    /**
     * Recalcula c_ref y p_ref para un producto_base/canal y hace upsert.
     * Mantiene p_asignado si ya existe y recalcula alerta de margen.
     *
     * @param int    $producto_base_id
     * @param string $canal
     * @param int    $woocommerce_variation_id
     * @return array|WP_Error  Fila resultante
     */
    public function recalc_price($producto_base_id, $canal = self::CANAL_LOCAL, $woocommerce_variation_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $woocommerce_variation_id = intval($woocommerce_variation_id);
        $canal = $canal === self::CANAL_ONLINE ? self::CANAL_ONLINE : self::CANAL_LOCAL;

        if (!$producto_base_id) {
            return new WP_Error('invalid', 'producto_base_id requerido');
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}precios
             WHERE producto_base_id = %d AND canal = %s AND woocommerce_variation_id = %d",
            $producto_base_id,
            $canal,
            $woocommerce_variation_id
        ), ARRAY_A);

        $factor_objetivo = $existing ? (float) $existing['factor_objetivo'] : self::FACTOR_OBJETIVO_DEFAULT;
        $factor_minimo   = $existing ? (float) $existing['factor_minimo'] : self::FACTOR_MINIMO_DEFAULT;

        $c_ref = $canal === self::CANAL_ONLINE
            ? $this->calculate_c_ref_online($producto_base_id, $woocommerce_variation_id)
            : $this->calculate_c_ref_local($producto_base_id);

        $p_ref = ($c_ref !== null) ? round($c_ref * $factor_objetivo, 2) : null;

        $p_asignado = $existing ? ($existing['p_asignado'] !== null ? (float) $existing['p_asignado'] : null) : null;
        $alerta = 0;
        if ($p_asignado !== null && $c_ref !== null && $p_asignado < ($factor_minimo * $c_ref)) {
            $alerta = 1;
        }

        if ($existing) {
            $wpdb->update(
                "{$prefix}precios",
                [
                    'c_ref' => $c_ref,
                    'p_ref' => $p_ref,
                    'alerta_margen' => $alerta,
                ],
                ['id' => $existing['id']],
                ['%f', '%f', '%d'],
                ['%d']
            );
            $id = (int) $existing['id'];
        } else {
            $wpdb->insert(
                "{$prefix}precios",
                [
                    'producto_base_id' => $producto_base_id,
                    'canal' => $canal,
                    'woocommerce_variation_id' => $woocommerce_variation_id,
                    'c_ref' => $c_ref,
                    'p_ref' => $p_ref,
                    'p_asignado' => null,
                    'factor_minimo' => self::FACTOR_MINIMO_DEFAULT,
                    'factor_objetivo' => self::FACTOR_OBJETIVO_DEFAULT,
                    'factor_maximo_referencia' => self::FACTOR_MAXIMO_DEFAULT,
                    'estado_aprobacion' => 'pendiente',
                    'alerta_margen' => $alerta,
                    'created_by_system' => 1,
                    'requires_human_review' => 1,
                ],
                ['%d', '%s', '%d', '%f', '%f', '%s', '%f', '%f', '%f', '%s', '%d', '%d', '%d']
            );
            $id = (int) $wpdb->insert_id;

            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log_system('price_created', 'precio', $id, [
                    'new_value' => ['canal' => $canal, 'c_ref' => $c_ref, 'p_ref' => $p_ref],
                    'details' => 'Precio de referencia generado automáticamente',
                ]);
            }
        }

        // Si se levanta alerta de margen, encolar tarea de revisión.
        if ($alerta && function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'aprobar_lista_precios',
                'Revisar margen bajo en precio del producto base #' . $producto_base_id,
                'precio',
                $id,
                [
                    'prioridad' => 'alta',
                    'descripcion' => 'El precio asignado quedó por debajo del margen mínimo permitido.',
                ]
            );
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}precios WHERE id = %d", $id), ARRAY_A);
    }

    /**
     * Asigna p_asignado (precio comercial) y recalcula alerta de margen.
     *
     * @param int   $precio_id
     * @param float $p_asignado
     * @return array|WP_Error
     */
    public function set_assigned_price($precio_id, $p_asignado) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $precio_id = intval($precio_id);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}precios WHERE id = %d", $precio_id), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Precio no encontrado');
        }

        $p_asignado = (float) $p_asignado;
        $c_ref = $row['c_ref'] !== null ? (float) $row['c_ref'] : null;
        $factor_minimo = (float) $row['factor_minimo'];
        $alerta = ($c_ref !== null && $p_asignado < ($factor_minimo * $c_ref)) ? 1 : 0;

        $wpdb->update(
            "{$prefix}precios",
            [
                'p_asignado' => $p_asignado,
                'alerta_margen' => $alerta,
                'estado_aprobacion' => 'pendiente',
            ],
            ['id' => $precio_id],
            ['%f', '%d', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('price_changed', 'precio', $precio_id, [
                'old_value' => ['p_asignado' => $row['p_asignado']],
                'new_value' => ['p_asignado' => $p_asignado],
            ]);
        }

        if ($alerta && function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'aprobar_lista_precios',
                'Margen bajo: precio asignado bajo el mínimo (precio #' . $precio_id . ')',
                'precio',
                $precio_id,
                ['prioridad' => 'alta']
            );
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}precios WHERE id = %d", $precio_id), ARRAY_A);
    }

    /**
     * Aprueba el precio asignado manualmente.
     */
    public function approve_price($precio_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $precio_id = intval($precio_id);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}precios WHERE id = %d", $precio_id), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Precio no encontrado');
        }
        if ($row['p_asignado'] === null) {
            return new WP_Error('no_assigned', 'Debe asignar un precio antes de aprobar');
        }

        $wpdb->update(
            "{$prefix}precios",
            [
                'estado_aprobacion' => 'aprobado',
                'requires_human_review' => 0,
                'aprobado_por' => get_current_user_id(),
                'aprobado_at' => current_time('mysql'),
            ],
            ['id' => $precio_id],
            ['%s', '%d', '%d', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('price_approved', 'precio', $precio_id, [
                'new_value' => ['p_asignado' => $row['p_asignado']],
            ]);
        }

        return true;
    }

    /**
     * Resuelve el producto_base_id a partir de un producto/variación WooCommerce.
     *
     * @param int $product_id
     * @param int $variation_id
     * @return int 0 si no existe
     */
    public function get_base_id_by_wc($product_id, $variation_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $product_id = intval($product_id);
        $variation_id = intval($variation_id);

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND woocommerce_variation_id = %d
             LIMIT 1",
            $product_id,
            $variation_id
        ));

        if (!$id && $variation_id) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}producto_base
                 WHERE woocommerce_product_id = %d AND woocommerce_variation_id = 0
                 LIMIT 1",
                $product_id
            ));
        }

        return $id ? intval($id) : 0;
    }

    /**
     * Devuelve el precio LOCAL aprobado de un producto_base (o null).
     *
     * @param int $producto_base_id
     * @return array|null
     */
    public function get_local_price($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}precios
             WHERE producto_base_id = %d AND canal = %s AND woocommerce_variation_id = 0",
            intval($producto_base_id),
            self::CANAL_LOCAL
        ), ARRAY_A);
    }

    /**
     * Sincroniza el precio ONLINE de un producto_base a WooCommerce vía API
     * (WC_Product::set_regular_price), nunca por SQL directo.
     *
     * Usa p_asignado online aprobado si existe; en su defecto p_ref online.
     *
     * @param int $producto_base_id
     * @param int $woocommerce_variation_id
     * @return array|WP_Error
     */
    public function sync_online_to_woocommerce($producto_base_id, $woocommerce_variation_id = 0) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $producto_base_id = intval($producto_base_id);
        $woocommerce_variation_id = intval($woocommerce_variation_id);

        if (!function_exists('wc_get_product')) {
            return new WP_Error('no_wc', 'WooCommerce no disponible');
        }

        // Asegurar precio online calculado.
        $row = $this->recalc_price($producto_base_id, self::CANAL_ONLINE, $woocommerce_variation_id);
        if (is_wp_error($row)) {
            return $row;
        }

        $precio = null;
        if (!empty($row['p_asignado']) && $row['estado_aprobacion'] === 'aprobado') {
            $precio = (float) $row['p_asignado'];
        } elseif (!empty($row['p_ref'])) {
            $precio = (float) $row['p_ref'];
        }
        if ($precio === null) {
            return new WP_Error('no_price', 'Sin precio online calculable');
        }

        $base = $wpdb->get_row($wpdb->prepare(
            "SELECT woocommerce_product_id, woocommerce_variation_id FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A);
        if (!$base) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        $wc_id = $woocommerce_variation_id
            ?: intval($base['woocommerce_variation_id'])
            ?: intval($base['woocommerce_product_id']);
        if (!$wc_id) {
            return new WP_Error('no_wc_product', 'Producto sin referencia WooCommerce');
        }

        $product = wc_get_product($wc_id);
        if (!$product) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no encontrado');
        }

        $old_price = $product->get_regular_price();
        $product->set_regular_price((string) $precio);
        if (!$product->get_sale_price()) {
            $product->set_price((string) $precio);
        }
        $product->save();

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('online_price_synced', 'product', $wc_id, [
                'old_value' => ['regular_price' => $old_price],
                'new_value' => ['regular_price' => $precio],
                'details' => 'Sincronización de precio online a WooCommerce',
            ]);
        }

        return ['product_id' => $wc_id, 'price' => $precio];
    }

    /* ===================== Hooks automáticos ===================== */

    /**
     * Al aprobar una factura, recalcula precios de los producto_base afectados.
     */
    public function on_invoice_approved($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $base_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pp.producto_base_id
             FROM {$prefix}lotes l
             INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
             WHERE l.documento_tipo = 'factura' AND l.documento_id = %d",
            intval($factura_id)
        ));

        foreach ($base_ids as $base_id) {
            $this->recalc_price($base_id, self::CANAL_LOCAL, 0);
        }
    }

    /**
     * Al registrar un lote, recalcula el precio local de su producto_base.
     */
    public function on_lote_registrado($producto_base_id) {
        if ($producto_base_id) {
            $this->recalc_price($producto_base_id, self::CANAL_LOCAL, 0);
        }
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $canal = sanitize_text_field($_POST['canal'] ?? self::CANAL_LOCAL);
        $canal = $canal === self::CANAL_ONLINE ? self::CANAL_ONLINE : self::CANAL_LOCAL;
        $limit = min(200, max(1, intval($_POST['limit'] ?? 100)));
        $offset = max(0, intval($_POST['offset'] ?? 0));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pr.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}precios pr
             INNER JOIN {$prefix}producto_base pb ON pb.id = pr.producto_base_id
             WHERE pr.canal = %s
             ORDER BY pr.alerta_margen DESC, pr.updated_at DESC
             LIMIT %d OFFSET %d",
            $canal,
            $limit,
            $offset
        ), ARRAY_A);

        wp_send_json_success(['items' => $rows]);
    }

    public function ajax_recalc() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $producto_base_id = intval($_POST['producto_base_id'] ?? 0);
        $canal = sanitize_text_field($_POST['canal'] ?? self::CANAL_LOCAL);
        $variation_id = intval($_POST['woocommerce_variation_id'] ?? 0);

        if (!$producto_base_id) {
            wp_send_json_error(['message' => 'producto_base_id requerido']);
        }

        $result = $this->recalc_price($producto_base_id, $canal, $variation_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['price' => $result]);
    }

    public function ajax_set_assigned() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $precio_id = intval($_POST['precio_id'] ?? 0);
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);

        if (!$precio_id || $p_asignado <= 0) {
            wp_send_json_error(['message' => 'Datos inválidos']);
        }

        $result = $this->set_assigned_price($precio_id, $p_asignado);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['price' => $result]);
    }

    public function ajax_approve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_approve_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $precio_id = intval($_POST['precio_id'] ?? 0);
        if (!$precio_id) {
            wp_send_json_error(['message' => 'precio_id requerido']);
        }

        $result = $this->approve_price($precio_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Precio aprobado']);
    }

    public function ajax_sync_online() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->sync_online_to_woocommerce(
            intval($_POST['producto_base_id'] ?? 0),
            intval($_POST['woocommerce_variation_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success($result);
    }

    public function ajax_alerts() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $rows = $wpdb->get_results(
            "SELECT pr.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}precios pr
             INNER JOIN {$prefix}producto_base pb ON pb.id = pr.producto_base_id
             WHERE pr.alerta_margen = 1
             ORDER BY pr.updated_at DESC
             LIMIT 200",
            ARRAY_A
        );

        wp_send_json_success(['alerts' => $rows]);
    }
}
