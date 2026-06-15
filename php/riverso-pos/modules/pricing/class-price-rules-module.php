<?php
/**
 * Módulo de Reglas de Precio por tramos (versionadas) - Riverso POS.
 *
 * Gestiona reglas asignables a producto / familia (grupo de equivalencia) /
 * categoría, con tramos por cantidad editables, aprobables y versionables.
 * La evaluación numérica vive en Riverso_Price_Rule_Engine.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-price-rule-engine.php';

class Riverso_Price_Rules_Module {

    private static $instance = null;

    const ESTADOS = ['borrador', 'aprobada', 'archivada'];
    const TARGETS = ['producto', 'familia', 'categoria'];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_price_rules_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_price_rule_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_price_rule_save', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_price_rule_approve', [$this, 'ajax_approve']);
        add_action('wp_ajax_riverso_price_rule_assign', [$this, 'ajax_assign']);
        add_action('wp_ajax_riverso_price_rule_preview', [$this, 'ajax_preview']);
    }

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'riverso_';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE {$prefix}price_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            nombre VARCHAR(255) NOT NULL,
            version INT NOT NULL DEFAULT 1,
            estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
            aprobado_por BIGINT UNSIGNED DEFAULT NULL,
            aprobado_at DATETIME DEFAULT NULL,
            created_by_system TINYINT(1) NOT NULL DEFAULT 0,
            requires_human_review TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_codigo_version (codigo, version),
            KEY idx_estado (estado)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}price_rule_tiers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            qty_min INT NOT NULL DEFAULT 1,
            qty_max INT DEFAULT NULL,
            formula_tipo VARCHAR(20) NOT NULL DEFAULT 'multiplicador',
            multiplicador DECIMAL(8,4) DEFAULT NULL,
            addendo DECIMAL(12,2) DEFAULT NULL,
            redondeo VARCHAR(20) NOT NULL DEFAULT 'ninguno',
            total_minimo DECIMAL(12,2) DEFAULT NULL,
            orden INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_rule (rule_id),
            KEY idx_orden (rule_id, orden)
        ) $charset_collate;";
        dbDelta($sql);

        $sql = "CREATE TABLE {$prefix}price_rule_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            target_tipo VARCHAR(20) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY ux_target (target_tipo, target_id),
            KEY idx_rule (rule_id)
        ) $charset_collate;";
        dbDelta($sql);

        self::seed_example_rule();
    }

    /**
     * Crea la regla de ejemplo R-1 del plan (idempotente por código).
     */
    public static function seed_example_rule() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}price_rules WHERE codigo = %s LIMIT 1",
            'R-1'
        ));
        if ($exists) {
            return;
        }

        $wpdb->insert("{$prefix}price_rules", [
            'codigo' => 'R-1',
            'nombre' => 'Regla estándar ferretería',
            'version' => 1,
            'estado' => 'aprobada',
            'aprobado_at' => current_time('mysql'),
        ]);
        $rule_id = (int) $wpdb->insert_id;
        if (!$rule_id) {
            return;
        }

        // Tramos del ejemplo R-1.
        $tiers = [
            ['qty_min' => 1,     'qty_max' => 20,    'formula_tipo' => 'multiplicador', 'multiplicador' => 3,    'addendo' => null, 'redondeo' => 'techo_decena', 'total_minimo' => 30,   'orden' => 1],
            ['qty_min' => 21,    'qty_max' => 50,    'formula_tipo' => 'multiplicador', 'multiplicador' => 2,    'addendo' => null, 'redondeo' => 'techo_decena', 'total_minimo' => null, 'orden' => 2],
            ['qty_min' => 51,    'qty_max' => 100,   'formula_tipo' => 'suma',          'multiplicador' => null, 'addendo' => 4,    'redondeo' => 'ninguno',      'total_minimo' => null, 'orden' => 3],
            ['qty_min' => 101,   'qty_max' => 299,   'formula_tipo' => 'suma',          'multiplicador' => null, 'addendo' => 3,    'redondeo' => 'ninguno',      'total_minimo' => null, 'orden' => 4],
            ['qty_min' => 300,   'qty_max' => 10999, 'formula_tipo' => 'multiplicador', 'multiplicador' => 1,    'addendo' => null, 'redondeo' => 'ninguno',      'total_minimo' => null, 'orden' => 5],
            ['qty_min' => 11000, 'qty_max' => null,  'formula_tipo' => 'rango',         'multiplicador' => 1.7,  'addendo' => null, 'redondeo' => 'ninguno',      'total_minimo' => null, 'orden' => 6],
        ];
        foreach ($tiers as $t) {
            $t['rule_id'] = $rule_id;
            $wpdb->insert("{$prefix}price_rule_tiers", $t);
        }
    }

    /* ===================== CRUD / versionado ===================== */

    /**
     * Crea una nueva regla (versión 1 en borrador) con sus tramos.
     */
    public function create_rule($data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $codigo = sanitize_text_field($data['codigo'] ?? '');
        $nombre = sanitize_text_field($data['nombre'] ?? '');
        if (!$codigo || !$nombre) {
            return new WP_Error('invalid', 'Código y nombre requeridos');
        }

        // Calcular siguiente versión para el código.
        $next_version = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(version), 0) + 1 FROM {$prefix}price_rules WHERE codigo = %s",
            $codigo
        ));

        $wpdb->insert("{$prefix}price_rules", [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'version' => $next_version,
            'estado' => 'borrador',
        ]);
        $rule_id = (int) $wpdb->insert_id;
        if (!$rule_id) {
            return new WP_Error('db_error', 'No se pudo crear la regla');
        }

        $this->replace_tiers($rule_id, $data['tiers'] ?? []);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('rule_created', 'price_rule', $rule_id, [
                'new_value' => ['codigo' => $codigo, 'version' => $next_version],
            ]);
        }

        return $rule_id;
    }

    /**
     * Reemplaza los tramos de una regla.
     */
    public function replace_tiers($rule_id, $tiers) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rule_id = intval($rule_id);

        $wpdb->delete("{$prefix}price_rule_tiers", ['rule_id' => $rule_id], ['%d']);

        $orden = 0;
        foreach ((array) $tiers as $t) {
            $orden++;
            $formula = in_array($t['formula_tipo'] ?? '', Riverso_Price_Rule_Engine::FORMULAS, true)
                ? $t['formula_tipo'] : 'multiplicador';
            $redondeo = in_array($t['redondeo'] ?? '', Riverso_Price_Rule_Engine::REDONDEOS, true)
                ? $t['redondeo'] : 'ninguno';

            $wpdb->insert("{$prefix}price_rule_tiers", [
                'rule_id' => $rule_id,
                'qty_min' => intval($t['qty_min'] ?? 1),
                'qty_max' => (isset($t['qty_max']) && $t['qty_max'] !== '' && $t['qty_max'] !== null) ? intval($t['qty_max']) : null,
                'formula_tipo' => $formula,
                'multiplicador' => (isset($t['multiplicador']) && $t['multiplicador'] !== '' && $t['multiplicador'] !== null) ? floatval($t['multiplicador']) : null,
                'addendo' => (isset($t['addendo']) && $t['addendo'] !== '' && $t['addendo'] !== null) ? floatval($t['addendo']) : null,
                'redondeo' => $redondeo,
                'total_minimo' => (isset($t['total_minimo']) && $t['total_minimo'] !== '' && $t['total_minimo'] !== null) ? floatval($t['total_minimo']) : null,
                'orden' => intval($t['orden'] ?? $orden),
            ]);
        }
    }

    /**
     * Aprueba una regla y archiva versiones aprobadas anteriores del mismo código.
     */
    public function approve_rule($rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rule_id = intval($rule_id);

        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}price_rules WHERE id = %d", $rule_id), ARRAY_A);
        if (!$rule) {
            return new WP_Error('not_found', 'Regla no encontrada');
        }

        // Archivar otras versiones aprobadas del mismo código.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}price_rules SET estado = 'archivada'
             WHERE codigo = %s AND id <> %d AND estado = 'aprobada'",
            $rule['codigo'],
            $rule_id
        ));

        $wpdb->update("{$prefix}price_rules", [
            'estado' => 'aprobada',
            'aprobado_por' => get_current_user_id(),
            'aprobado_at' => current_time('mysql'),
            'requires_human_review' => 0,
        ], ['id' => $rule_id], ['%s', '%d', '%s', '%d'], ['%d']);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('rule_approved', 'price_rule', $rule_id, [
                'new_value' => ['codigo' => $rule['codigo'], 'version' => $rule['version']],
            ]);
        }

        return true;
    }

    public function get_rule_with_tiers($rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rule_id = intval($rule_id);

        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}price_rules WHERE id = %d", $rule_id), ARRAY_A);
        if (!$rule) {
            return null;
        }
        $rule['tiers'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}price_rule_tiers WHERE rule_id = %d ORDER BY orden ASC",
            $rule_id
        ), ARRAY_A);
        $rule['assignments'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}price_rule_assignments WHERE rule_id = %d",
            $rule_id
        ), ARRAY_A);
        return $rule;
    }

    public function assign_rule($rule_id, $target_tipo, $target_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        if (!in_array($target_tipo, self::TARGETS, true)) {
            return new WP_Error('invalid', 'Tipo de asignación inválido');
        }
        $rule_id = intval($rule_id);
        $target_id = intval($target_id);

        // Upsert por target (un target solo puede tener una regla).
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$prefix}price_rule_assignments (rule_id, target_tipo, target_id)
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE rule_id = VALUES(rule_id)",
            $rule_id,
            $target_tipo,
            $target_id
        ));

        return true;
    }

    /* ===================== Resolución y aplicación ===================== */

    /**
     * Resuelve la regla aplicable a un producto_base: producto > familia > categoría.
     *
     * @param int $producto_base_id
     * @return int|null rule_id (versión aprobada) o null
     */
    public function resolve_rule_for_base($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $producto_base_id = intval($producto_base_id);

        // 1. Producto
        $rule_id = $this->get_assignment_rule('producto', $producto_base_id);
        if ($rule_id) {
            return $this->resolve_approved_version($rule_id);
        }

        // 2. Familia (grupos de equivalencia activos)
        $grupo_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT em.grupo_id FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups g ON g.id = em.grupo_id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND g.activo = 1",
            $producto_base_id
        ));
        foreach ($grupo_ids as $gid) {
            $rule_id = $this->get_assignment_rule('familia', intval($gid));
            if ($rule_id) {
                return $this->resolve_approved_version($rule_id);
            }
        }

        // 3. Categoría (WooCommerce)
        $wc_product_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT woocommerce_product_id FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ));
        if ($wc_product_id && function_exists('wc_get_product_term_ids')) {
            $cat_ids = wc_get_product_term_ids($wc_product_id, 'product_cat');
            foreach ((array) $cat_ids as $cid) {
                $rule_id = $this->get_assignment_rule('categoria', intval($cid));
                if ($rule_id) {
                    return $this->resolve_approved_version($rule_id);
                }
            }
        }

        return null;
    }

    private function get_assignment_rule($target_tipo, $target_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rule_id = $wpdb->get_var($wpdb->prepare(
            "SELECT rule_id FROM {$prefix}price_rule_assignments WHERE target_tipo = %s AND target_id = %d LIMIT 1",
            $target_tipo,
            $target_id
        ));
        return $rule_id ? intval($rule_id) : null;
    }

    /**
     * Dado un rule_id (cualquier versión), devuelve la versión aprobada vigente
     * del mismo código (o el propio si está aprobado).
     */
    private function resolve_approved_version($rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $codigo = $wpdb->get_var($wpdb->prepare("SELECT codigo FROM {$prefix}price_rules WHERE id = %d", intval($rule_id)));
        if (!$codigo) {
            return null;
        }
        $approved = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}price_rules WHERE codigo = %s AND estado = 'aprobada' ORDER BY version DESC LIMIT 1",
            $codigo
        ));
        return $approved ? intval($approved) : null;
    }

    public function get_tiers($rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}price_rule_tiers WHERE rule_id = %d ORDER BY orden ASC",
            intval($rule_id)
        ), ARRAY_A);
    }

    /**
     * Cantidad agregada de los lotes equivalentes de un producto_base.
     */
    public function get_aggregated_quantity($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $base_ids = class_exists('Riverso_Pricing_Module')
            ? Riverso_Pricing_Module::get_instance()->get_equivalent_base_ids($producto_base_id)
            : [intval($producto_base_id)];

        if (empty($base_ids)) {
            return 0.0;
        }
        $placeholders = implode(',', array_fill(0, count($base_ids), '%d'));
        $sum = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(l.cantidad_disponible), 0)
             FROM {$prefix}lotes l
             INNER JOIN {$prefix}producto_proveedor pp ON pp.id = l.producto_proveedor_id
             WHERE pp.producto_base_id IN ($placeholders)",
            ...$base_ids
        ));
        return (float) $sum;
    }

    /**
     * Aplica la regla resuelta para un producto_base sobre una cantidad.
     *
     * @param int        $producto_base_id
     * @param float      $qty         Cantidad para seleccionar el tramo
     * @param float|null $p_asignado  Precio asignado; si null se toma del precio LOCAL
     * @return float|null             Precio unitario, o null si no hay regla/precio
     */
    public function apply_for_base($producto_base_id, $qty, $p_asignado = null) {
        $rule_id = $this->resolve_rule_for_base($producto_base_id);
        if (!$rule_id) {
            return null;
        }

        if ($p_asignado === null && class_exists('Riverso_Pricing_Module')) {
            $price_row = Riverso_Pricing_Module::get_instance()->get_local_price($producto_base_id);
            if ($price_row && $price_row['p_asignado'] !== null) {
                $p_asignado = (float) $price_row['p_asignado'];
            }
        }
        if ($p_asignado === null) {
            return null;
        }

        $tiers = $this->get_tiers($rule_id);
        return Riverso_Price_Rule_Engine::evaluate($tiers, $p_asignado, $qty);
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT * FROM {$prefix}price_rules ORDER BY codigo ASC, version DESC",
            ARRAY_A
        );
        wp_send_json_success(['rules' => $rows]);
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $rule = $this->get_rule_with_tiers(intval($_POST['rule_id'] ?? 0));
        if (!$rule) {
            wp_send_json_error(['message' => 'Regla no encontrada']);
        }
        wp_send_json_success(['rule' => $rule]);
    }

    public function ajax_save() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $tiers = isset($_POST['tiers']) ? json_decode(stripslashes($_POST['tiers']), true) : [];
        $rule_id = intval($_POST['rule_id'] ?? 0);

        if ($rule_id) {
            // Editar tramos de una regla en borrador (nueva versión si está aprobada).
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}price_rules WHERE id = %d", $rule_id), ARRAY_A);
            if (!$rule) {
                wp_send_json_error(['message' => 'Regla no encontrada']);
            }
            if ($rule['estado'] === 'aprobada') {
                // Editar una regla aprobada => nueva versión en borrador.
                $new_id = $this->create_rule([
                    'codigo' => $rule['codigo'],
                    'nombre' => sanitize_text_field($_POST['nombre'] ?? $rule['nombre']),
                    'tiers' => $tiers,
                ]);
                if (is_wp_error($new_id)) {
                    wp_send_json_error(['message' => $new_id->get_error_message()]);
                }
                wp_send_json_success(['rule_id' => $new_id, 'message' => 'Nueva versión en borrador creada']);
            }
            $this->replace_tiers($rule_id, $tiers);
            if (!empty($_POST['nombre'])) {
                $wpdb->update("{$prefix}price_rules", ['nombre' => sanitize_text_field($_POST['nombre'])], ['id' => $rule_id]);
            }
            wp_send_json_success(['rule_id' => $rule_id, 'message' => 'Regla actualizada']);
        }

        $new_id = $this->create_rule([
            'codigo' => $_POST['codigo'] ?? '',
            'nombre' => $_POST['nombre'] ?? '',
            'tiers' => $tiers,
        ]);
        if (is_wp_error($new_id)) {
            wp_send_json_error(['message' => $new_id->get_error_message()]);
        }
        wp_send_json_success(['rule_id' => $new_id, 'message' => 'Regla creada']);
    }

    public function ajax_approve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_approve_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->approve_rule(intval($_POST['rule_id'] ?? 0));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Regla aprobada']);
    }

    public function ajax_assign() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $result = $this->assign_rule(
            intval($_POST['rule_id'] ?? 0),
            sanitize_text_field($_POST['target_tipo'] ?? ''),
            intval($_POST['target_id'] ?? 0)
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => 'Regla asignada']);
    }

    public function ajax_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $rule_id = intval($_POST['rule_id'] ?? 0);
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);
        $qty = floatval($_POST['qty'] ?? 1);
        $tiers = $this->get_tiers($rule_id);
        $price = Riverso_Price_Rule_Engine::evaluate($tiers, $p_asignado, $qty);
        wp_send_json_success(['price' => $price]);
    }
}
