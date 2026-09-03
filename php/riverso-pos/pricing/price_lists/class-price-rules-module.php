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
        self::maybe_upgrade_schema();
        self::maybe_backfill_missing_rule_tasks();
        add_action('wp_ajax_riverso_price_rules_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_price_rule_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_price_rule_save', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_price_rule_approve', [$this, 'ajax_approve']);
        add_action('wp_ajax_riverso_price_rule_assign', [$this, 'ajax_assign']);
        add_action('wp_ajax_riverso_price_rule_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_riverso_price_rule_eval_formulas', [$this, 'ajax_eval_formulas']);
        add_action('wp_ajax_riverso_price_rule_search_assignments', [$this, 'ajax_search_assignments']);
        add_action('wp_ajax_riverso_price_rule_search_targets', [$this, 'ajax_search_targets']);
        add_action('wp_ajax_riverso_price_rule_lookup_target', [$this, 'ajax_lookup_target']);
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
            formula VARCHAR(500) DEFAULT NULL,
            formula_total VARCHAR(500) DEFAULT NULL,
            total_minimo DECIMAL(12,2) DEFAULT NULL,
            piso_total DECIMAL(12,2) DEFAULT NULL,
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

        self::maybe_upgrade_schema();
        self::seed_example_rule();
        self::seed_legacy_screw_rule();
    }

    /**
     * Añade columnas nuevas de tramos si la tabla ya existía.
     */
    public static function maybe_upgrade_schema() {
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_price_rule_tiers';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$found) {
            return;
        }
        $table_esc = esc_sql($table);
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_esc}` LIKE 'formula'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table_esc}` ADD formula VARCHAR(500) NULL DEFAULT NULL AFTER redondeo");
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_esc}` LIKE 'formula_total'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table_esc}` ADD formula_total VARCHAR(500) NULL DEFAULT NULL AFTER formula");
        }
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_esc}` LIKE 'piso_total'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table_esc}` ADD piso_total DECIMAL(12,2) NULL DEFAULT NULL AFTER total_minimo");
        }
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
            ['qty_min' => 1,     'qty_max' => 20,    'formula_tipo' => 'formula', 'formula' => 'T10(P*3)', 'multiplicador' => 3,    'addendo' => null, 'redondeo' => 'ninguno', 'total_minimo' => 30,   'orden' => 1],
            ['qty_min' => 21,    'qty_max' => 50,    'formula_tipo' => 'formula', 'formula' => 'T10(P*2)', 'multiplicador' => 2,    'addendo' => null, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 2],
            ['qty_min' => 51,    'qty_max' => 100,   'formula_tipo' => 'formula', 'formula' => 'P+4',      'multiplicador' => null, 'addendo' => 4,    'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 3],
            ['qty_min' => 101,   'qty_max' => 299,   'formula_tipo' => 'formula', 'formula' => 'P+3',      'multiplicador' => null, 'addendo' => 3,    'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 4],
            ['qty_min' => 300,   'qty_max' => 10999, 'formula_tipo' => 'formula', 'formula' => 'P',        'multiplicador' => 1,    'addendo' => null, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 5],
            ['qty_min' => 11000, 'qty_max' => null,  'formula_tipo' => 'formula', 'formula' => 'P*1.7',    'multiplicador' => 1.7,  'addendo' => null, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 6],
        ];
        foreach ($tiers as $t) {
            $t['rule_id'] = $rule_id;
            $wpdb->insert("{$prefix}price_rule_tiers", $t);
        }
    }

    /**
     * Regla propuesta para tornillos legacy. Se crea en borrador y sin
     * asignaciones para que no altere precios hasta revisión humana.
     */
    public static function seed_legacy_screw_rule() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $code = 'TORNILLO-LEGACY';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}price_rules WHERE codigo = %s LIMIT 1",
            $code
        ));
        if ($exists) {
            return;
        }

        $wpdb->insert("{$prefix}price_rules", [
            'codigo' => $code,
            'nombre' => 'Tornillos legacy: +3 y precio referencia',
            'version' => 1,
            'estado' => 'borrador',
            'created_by_system' => 1,
            'requires_human_review' => 1,
        ]);
        $rule_id = intval($wpdb->insert_id);
        if (!$rule_id) {
            return;
        }

        $tiers = [
            ['qty_min' => 1, 'qty_max' => 30, 'formula_tipo' => 'formula', 'formula' => 'T10(P+3)', 'multiplicador' => null, 'addendo' => 3, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 1],
            ['qty_min' => 31, 'qty_max' => 300, 'formula_tipo' => 'formula', 'formula' => 'P+3', 'multiplicador' => null, 'addendo' => 3, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 2],
            ['qty_min' => 301, 'qty_max' => null, 'formula_tipo' => 'formula', 'formula' => 'P', 'multiplicador' => 1, 'addendo' => null, 'redondeo' => 'ninguno', 'total_minimo' => null, 'orden' => 3],
        ];
        foreach ($tiers as $tier) {
            $tier['rule_id'] = $rule_id;
            $wpdb->insert("{$prefix}price_rule_tiers", $tier);
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

        $replaced = $this->replace_tiers($rule_id, $data['tiers'] ?? []);
        if (is_wp_error($replaced)) {
            $wpdb->delete("{$prefix}price_rules", ['id' => $rule_id], ['%d']);
            return $replaced;
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('rule_created', 'price_rule', $rule_id, [
                'new_value' => ['codigo' => $codigo, 'version' => $next_version],
            ]);
        }

        return $rule_id;
    }

    /**
     * Normaliza y valida tramos (incluye fórmulas tipo calculadora).
     *
     * @param array $tiers
     * @return array|WP_Error
     */
    public function normalize_tiers($tiers) {
        $normalized = [];
        $orden = 0;
        foreach ((array) $tiers as $t) {
            $orden++;
            $formula_txt = Riverso_Price_Rule_Engine::sanitize_formula($t['formula'] ?? '');
            if ($formula_txt === '' && !empty($t['formula_tipo']) && $t['formula_tipo'] !== 'formula') {
                $formula_txt = Riverso_Price_Rule_Engine::formula_from_tier($t);
            }
            if ($formula_txt !== '') {
                $check = Riverso_Price_Rule_Engine::validate_formula($formula_txt);
                if (is_wp_error($check)) {
                    return new WP_Error('invalid_formula', 'Tramo ' . $orden . ' (fórmula P): ' . $check->get_error_message());
                }
            }

            $formula_total_txt = Riverso_Price_Rule_Engine::sanitize_formula($t['formula_total'] ?? '');
            if ($formula_total_txt !== '') {
                $check_total = Riverso_Price_Rule_Engine::validate_formula_total($formula_total_txt);
                if (is_wp_error($check_total)) {
                    return new WP_Error('invalid_formula', 'Tramo ' . $orden . ' (fórmula T): ' . $check_total->get_error_message());
                }
            }

            $formula_tipo = 'formula';
            if ($formula_txt === '') {
                $formula_tipo = in_array($t['formula_tipo'] ?? '', Riverso_Price_Rule_Engine::FORMULAS, true)
                    ? $t['formula_tipo'] : 'multiplicador';
            }
            $redondeo = in_array($t['redondeo'] ?? '', Riverso_Price_Rule_Engine::REDONDEOS, true)
                ? $t['redondeo'] : 'ninguno';
            if ($formula_txt !== '') {
                $redondeo = 'ninguno';
            }

            $normalized[] = [
                'qty_min' => intval($t['qty_min'] ?? 1),
                'qty_max' => (isset($t['qty_max']) && $t['qty_max'] !== '' && $t['qty_max'] !== null) ? intval($t['qty_max']) : null,
                'formula_tipo' => $formula_tipo,
                'multiplicador' => (isset($t['multiplicador']) && $t['multiplicador'] !== '' && $t['multiplicador'] !== null) ? floatval($t['multiplicador']) : null,
                'addendo' => (isset($t['addendo']) && $t['addendo'] !== '' && $t['addendo'] !== null) ? floatval($t['addendo']) : null,
                'redondeo' => $redondeo,
                'formula' => $formula_txt !== '' ? $formula_txt : null,
                'formula_total' => $formula_total_txt !== '' ? $formula_total_txt : null,
                'total_minimo' => (isset($t['total_minimo']) && $t['total_minimo'] !== '' && $t['total_minimo'] !== null) ? floatval($t['total_minimo']) : null,
                'piso_total' => (isset($t['piso_total']) && $t['piso_total'] !== '' && $t['piso_total'] !== null) ? floatval($t['piso_total']) : null,
                'orden' => intval($t['orden'] ?? $orden),
            ];
        }
        return $normalized;
    }

    /**
     * Reemplaza los tramos de una regla.
     *
     * @return true|WP_Error
     */
    public function replace_tiers($rule_id, $tiers) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rule_id = intval($rule_id);

        $normalized = $this->normalize_tiers($tiers);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $wpdb->delete("{$prefix}price_rule_tiers", ['rule_id' => $rule_id], ['%d']);

        foreach ($normalized as $row) {
            $row['rule_id'] = $rule_id;
            $wpdb->insert("{$prefix}price_rule_tiers", $row);
        }
        return true;
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

        $this->transfer_assignments_for_codigo($rule['codigo'], $rule_id);

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
        foreach ($rule['tiers'] as &$tier) {
            $tier['formula'] = Riverso_Price_Rule_Engine::formula_from_tier($tier);
        }
        unset($tier);
        $rule['assignments'] = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$prefix}price_rule_assignments a
             INNER JOIN {$prefix}price_rules r ON r.id = a.rule_id
             WHERE r.codigo = %s
             ORDER BY a.target_tipo ASC, a.target_id ASC",
            $rule['codigo']
        ), ARRAY_A);
        $rule['assignments'] = $this->enrich_assignments($rule['assignments'] ?: []);
        return $rule;
    }

    /**
     * URL admin de una familia: pestaña Familias + editor.
     *
     * @param int $grupo_id
     * @return string
     */
    public function family_admin_url($grupo_id) {
        return add_query_arg([
            'page' => 'riverso-pos-categories',
            'tab' => 'families',
            'grupo_id' => intval($grupo_id),
        ], admin_url('admin.php'));
    }

    /**
     * Enriquece asignaciones con nombre/SKU legibles y URLs.
     *
     * @param array $assignments
     * @return array
     */
    public function enrich_assignments(array $assignments) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $out = [];
        foreach ($assignments as $a) {
            $tipo = $a['target_tipo'] ?? '';
            $tid = intval($a['target_id'] ?? 0);
            $label = $tipo . '#' . $tid;
            $sku = '';
            $url = '';
            if ($tipo === 'producto' && $tid) {
                $pb = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                    $tid
                ), ARRAY_A);
                if ($pb) {
                    $sku = (string) ($pb['canonical_sku'] ?? '');
                    $nombre = trim((string) ($pb['nombre_canonico'] ?? ''));
                    $label = '#' . $tid . ' · ' . trim(($sku ? $sku . ' · ' : '') . $nombre);
                    $url = admin_url('admin.php?page=riverso-pos-products&action=detail&id=' . $tid);
                    $a['nombre'] = $nombre;
                }
            } elseif ($tipo === 'familia' && $tid) {
                $fam = $wpdb->get_row($wpdb->prepare(
                    "SELECT g.id, g.nombre, g.codigo_grupo, g.unit_producto_base_id, g.es_producto_unitario,
                            pb.canonical_sku AS unit_sku, pb.nombre_canonico AS unit_nombre
                     FROM {$prefix}equivalence_groups g
                     LEFT JOIN {$prefix}producto_base pb ON pb.id = g.unit_producto_base_id
                     WHERE g.id = %d",
                    $tid
                ), ARRAY_A);
                if ($fam) {
                    $sku = (string) ($fam['unit_sku'] ?? '');
                    $nombre = $fam['nombre'] ?: ('Familia #' . $tid);
                    $label = '#' . $tid . ' · ' . $nombre . ($sku ? ' · unitario ' . $sku : '');
                    $url = $this->family_admin_url($tid);
                    $a['nombre'] = $nombre;
                }
            } elseif ($tipo === 'categoria' && $tid) {
                $term = get_term($tid, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $label = '#' . $tid . ' · ' . $term->name;
                    $a['nombre'] = $term->name;
                }
            }
            $a['label'] = $label !== '' ? $label : ($tipo . '#' . $tid);
            $a['sku'] = $sku;
            $a['url'] = $url;
            if (!isset($a['nombre'])) {
                $a['nombre'] = $label;
            }
            $out[] = $a;
        }
        return $out;
    }

    /**
     * Busca familias unitarias y productos que ya tienen regla asignada.
     *
     * @param array $args search, tipo (familia|producto|all), rule_id, limit
     * @return array
     */
    public function search_assignments(array $args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $search = trim((string) ($args['search'] ?? ''));
        $tipo = sanitize_key($args['tipo'] ?? 'all');
        $rule_id = intval($args['rule_id'] ?? 0);
        $limit = min(100, max(1, intval($args['limit'] ?? 50)));
        $rule_codigo = '';
        if ($rule_id) {
            $rule_codigo = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT codigo FROM {$prefix}price_rules WHERE id = %d",
                $rule_id
            ));
        }

        $rows = [];

        if ($tipo === 'all' || $tipo === 'familia') {
            $sql = "SELECT a.id AS assignment_id, a.target_tipo, a.target_id, a.rule_id,
                           r.codigo AS rule_codigo, r.nombre AS rule_nombre, r.version AS rule_version, r.estado AS rule_estado,
                           g.nombre AS target_nombre, g.codigo_grupo,
                           pb.canonical_sku AS unit_sku, pb.nombre_canonico AS unit_nombre, pb.id AS unit_producto_base_id
                    FROM {$prefix}price_rule_assignments a
                    INNER JOIN {$prefix}price_rules r ON r.id = a.rule_id
                    INNER JOIN {$prefix}equivalence_groups g ON g.id = a.target_id AND g.activo = 1 AND g.es_producto_unitario = 1
                    LEFT JOIN {$prefix}producto_base pb ON pb.id = g.unit_producto_base_id
                    WHERE a.target_tipo = 'familia'";
            $params = [];
            if ($rule_codigo !== '') {
                $sql .= ' AND r.codigo = %s';
                $params[] = $rule_codigo;
            } elseif ($rule_id) {
                $sql .= ' AND a.rule_id = %d';
                $params[] = $rule_id;
            }
            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $member_sql = $this->sql_family_member_match('g', $search);
                $sql .= ' AND (g.nombre LIKE %s OR g.codigo_grupo LIKE %s OR g.id = %d OR pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR r.codigo LIKE %s OR r.nombre LIKE %s OR ' . $member_sql['sql'] . ')';
                array_push($params, $like, $like, intval($search), $like, $like, $like, $like);
                $params = array_merge($params, $member_sql['params']);
            }
            $sql .= ' ORDER BY g.nombre ASC LIMIT %d';
            $params[] = $limit;
            $fam_rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
            foreach ($fam_rows as $row) {
                $rows[] = [
                    'assignment_id' => (int) $row['assignment_id'],
                    'target_tipo' => 'familia',
                    'target_id' => (int) $row['target_id'],
                    'label' => '#' . (int) $row['target_id'] . ' · ' . trim(($row['target_nombre'] ?: ('Familia #' . $row['target_id'])) . (!empty($row['unit_sku']) ? ' · unitario ' . $row['unit_sku'] : '')),
                    'nombre' => $row['target_nombre'] ?: ('Familia #' . $row['target_id']),
                    'sku' => (string) ($row['unit_sku'] ?? ''),
                    'unit_producto_base_id' => !empty($row['unit_producto_base_id']) ? (int) $row['unit_producto_base_id'] : null,
                    'rule_id' => (int) $row['rule_id'],
                    'rule_codigo' => $row['rule_codigo'],
                    'rule_nombre' => $row['rule_nombre'],
                    'rule_version' => (int) $row['rule_version'],
                    'rule_estado' => $row['rule_estado'],
                    'url' => $this->family_admin_url((int) $row['target_id']),
                ];
            }
        }

        if ($tipo === 'all' || $tipo === 'producto') {
            $sql = "SELECT a.id AS assignment_id, a.target_tipo, a.target_id, a.rule_id,
                           r.codigo AS rule_codigo, r.nombre AS rule_nombre, r.version AS rule_version, r.estado AS rule_estado,
                           pb.canonical_sku, pb.nombre_canonico
                    FROM {$prefix}price_rule_assignments a
                    INNER JOIN {$prefix}price_rules r ON r.id = a.rule_id
                    INNER JOIN {$prefix}producto_base pb ON pb.id = a.target_id AND pb.deleted_at IS NULL
                    WHERE a.target_tipo = 'producto'";
            $params = [];
            if ($rule_codigo !== '') {
                $sql .= ' AND r.codigo = %s';
                $params[] = $rule_codigo;
            } elseif ($rule_id) {
                $sql .= ' AND a.rule_id = %d';
                $params[] = $rule_id;
            }
            if ($search !== '') {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $sql .= " AND (pb.id = %d OR pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR r.codigo LIKE %s OR r.nombre LIKE %s
                    OR EXISTS (
                        SELECT 1 FROM {$prefix}producto_proveedor pp_s
                        WHERE pp_s.producto_base_id = pb.id AND pp_s.activo = 1
                          AND (pp_s.codigo_proveedor LIKE %s OR pp_s.codigo_proveedor = %s)
                    ))";
                array_push($params, intval($search), $like, $like, $like, $like, $like, $search);
            }
            $sql .= ' ORDER BY pb.canonical_sku ASC LIMIT %d';
            $params[] = $limit;
            $prod_rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
            foreach ($prod_rows as $row) {
                $sku = (string) ($row['canonical_sku'] ?? '');
                $rows[] = [
                    'assignment_id' => (int) $row['assignment_id'],
                    'target_tipo' => 'producto',
                    'target_id' => (int) $row['target_id'],
                    'label' => '#' . (int) $row['target_id'] . ' · ' . trim(($sku ? $sku . ' · ' : '') . ($row['nombre_canonico'] ?? '')),
                    'nombre' => (string) ($row['nombre_canonico'] ?? ''),
                    'sku' => $sku,
                    'unit_producto_base_id' => null,
                    'rule_id' => (int) $row['rule_id'],
                    'rule_codigo' => $row['rule_codigo'],
                    'rule_nombre' => $row['rule_nombre'],
                    'rule_version' => (int) $row['rule_version'],
                    'rule_estado' => $row['rule_estado'],
                    'url' => admin_url('admin.php?page=riverso-pos-products&action=detail&id=' . (int) $row['target_id']),
                ];
            }
        }

        return $rows;
    }

    /**
     * EXISTS para coincidir integrantes de una familia por nombre, SKU o código proveedor.
     *
     * @param string $g_alias Alias de equivalence_groups
     * @param string $search
     * @return array{sql:string,params:array}
     */
    private function sql_family_member_match($g_alias, $search) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $compact = preg_replace('/[\s\-\/]+/', '', $search);
        $compact_like = '%' . $wpdb->esc_like($compact) . '%';
        $sql = "EXISTS (
            SELECT 1 FROM {$prefix}equivalence_members em_s
            INNER JOIN {$prefix}producto_base pb_s ON pb_s.id = em_s.producto_base_id AND pb_s.deleted_at IS NULL
            LEFT JOIN {$prefix}producto_proveedor pp_s
                ON pp_s.producto_base_id = pb_s.id AND pp_s.activo = 1
            WHERE em_s.grupo_id = {$g_alias}.id AND em_s.activo = 1
              AND (
                  pb_s.nombre_canonico LIKE %s
                  OR pb_s.canonical_sku LIKE %s
                  OR pb_s.canonical_sku = %s
                  OR REPLACE(REPLACE(REPLACE(IFNULL(pb_s.canonical_sku,''), '-', ''), ' ', ''), '/', '') LIKE %s
                  OR pp_s.codigo_proveedor LIKE %s
                  OR pp_s.codigo_proveedor = %s
                  OR REPLACE(REPLACE(REPLACE(IFNULL(pp_s.codigo_proveedor,''), '-', ''), ' ', ''), '/', '') LIKE %s
              )
        )";
        return [
            'sql' => $sql,
            'params' => [$like, $like, $search, $compact_like, $like, $search, $compact_like],
        ];
    }

    /**
     * Busca familias unitarias o productos para asignar (por nombre, SKU o código de integrante).
     *
     * @param array $args search, tipo (familia|producto|all), limit
     * @return array
     */
    public function search_targets(array $args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $search = trim((string) ($args['search'] ?? ''));
        $tipo = sanitize_key($args['tipo'] ?? 'all');
        $limit = min(40, max(1, intval($args['limit'] ?? 20)));
        $rows = [];
        if ($search === '') {
            return $rows;
        }
        $like = '%' . $wpdb->esc_like($search) . '%';

        if ($tipo === 'all' || $tipo === 'familia') {
            $member_sql = $this->sql_family_member_match('g', $search);
            $sql = "SELECT g.id, g.nombre, g.codigo_grupo, pb.canonical_sku AS unit_sku
                    FROM {$prefix}equivalence_groups g
                    LEFT JOIN {$prefix}producto_base pb ON pb.id = g.unit_producto_base_id
                    WHERE g.activo = 1 AND g.es_producto_unitario = 1
                      AND (g.id = %d OR g.nombre LIKE %s OR g.codigo_grupo LIKE %s OR pb.canonical_sku LIKE %s OR " . $member_sql['sql'] . ")
                    ORDER BY g.nombre ASC LIMIT %d";
            $params = array_merge([intval($search), $like, $like, $like], $member_sql['params'], [$limit]);
            $fam_rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
            foreach ($fam_rows as $row) {
                $nombre = $row['nombre'] ?: ('Familia #' . $row['id']);
                $sku = (string) ($row['unit_sku'] ?? '');
                $rows[] = [
                    'target_tipo' => 'familia',
                    'target_id' => (int) $row['id'],
                    'nombre' => $nombre,
                    'sku' => $sku,
                    'label' => '#' . (int) $row['id'] . ' · ' . $nombre . ($sku ? ' · unitario ' . $sku : ''),
                ];
            }
        }

        if ($tipo === 'all' || $tipo === 'producto') {
            $sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico
                    FROM {$prefix}producto_base pb
                    WHERE pb.deleted_at IS NULL
                      AND (pb.id = %d OR pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s
                        OR EXISTS (
                            SELECT 1 FROM {$prefix}producto_proveedor pp
                            WHERE pp.producto_base_id = pb.id AND pp.activo = 1
                              AND (pp.codigo_proveedor LIKE %s OR pp.codigo_proveedor = %s)
                        ))
                    ORDER BY pb.canonical_sku ASC LIMIT %d";
            $prod_rows = $wpdb->get_results($wpdb->prepare($sql, intval($search), $like, $like, $like, $search, $limit), ARRAY_A) ?: [];
            foreach ($prod_rows as $row) {
                $sku = (string) ($row['canonical_sku'] ?? '');
                $nombre = (string) ($row['nombre_canonico'] ?? '');
                $rows[] = [
                    'target_tipo' => 'producto',
                    'target_id' => (int) $row['id'],
                    'nombre' => $nombre,
                    'sku' => $sku,
                    'label' => '#' . (int) $row['id'] . ' · ' . trim(($sku ? $sku . ' · ' : '') . $nombre),
                ];
            }
        }

        return $rows;
    }

    /**
     * Resuelve nombre de un target para mostrar junto al ID.
     *
     * @param string $target_tipo
     * @param int    $target_id
     * @return array|null
     */
    public function lookup_target($target_tipo, $target_id) {
        $enriched = $this->enrich_assignments([[
            'target_tipo' => $target_tipo,
            'target_id' => intval($target_id),
        ]]);
        $row = $enriched[0] ?? null;
        if (!$row) {
            return null;
        }
        return [
            'target_tipo' => $target_tipo,
            'target_id' => intval($target_id),
            'label' => $row['label'] ?? '',
            'nombre' => $row['nombre'] ?? '',
            'sku' => $row['sku'] ?? '',
        ];
    }

    /**
     * Mueve asignaciones de una versión de regla a otra.
     */
    public function transfer_assignments($from_rule_id, $to_rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $from_rule_id = intval($from_rule_id);
        $to_rule_id = intval($to_rule_id);
        if (!$from_rule_id || !$to_rule_id || $from_rule_id === $to_rule_id) {
            return 0;
        }
        return (int) $wpdb->update(
            "{$prefix}price_rule_assignments",
            ['rule_id' => $to_rule_id],
            ['rule_id' => $from_rule_id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Mueve a $to_rule_id todas las asignaciones de otras versiones del mismo código.
     */
    public function transfer_assignments_for_codigo($codigo, $to_rule_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $to_rule_id = intval($to_rule_id);
        $codigo = sanitize_text_field($codigo);
        if (!$to_rule_id || $codigo === '') {
            return 0;
        }
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}price_rule_assignments a
             INNER JOIN {$prefix}price_rules r ON r.id = a.rule_id
             SET a.rule_id = %d
             WHERE r.codigo = %s AND a.rule_id <> %d",
            $to_rule_id,
            $codigo,
            $to_rule_id
        ));
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

        if ($target_tipo === 'familia') {
            $this->complete_missing_rule_tasks_for_familia($target_id);
        }

        return true;
    }

    /**
     * Completa tareas abiertas asignar_regla_precio de una familia.
     */
    public function complete_missing_rule_tasks_for_familia($grupo_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        if (!$grupo_id) {
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}tareas
             WHERE tipo = %s AND referencia_tipo = %s AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            'asignar_regla_precio',
            'familia',
            $grupo_id
        ));
        if (empty($ids) || !class_exists('Riverso_Task_Module')) {
            return;
        }
        $mod = Riverso_Task_Module::get_instance();
        foreach ($ids as $tid) {
            $mod->complete_task(intval($tid), 'Regla de precio asignada a la familia', 'system');
        }
    }

    /**
     * Cancela tareas abiertas asignar_regla_precio de una familia.
     *
     * @param int    $grupo_id
     * @param string $motivo
     */
    public function cancel_missing_rule_tasks_for_familia($grupo_id, $motivo = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $grupo_id = intval($grupo_id);
        if (!$grupo_id) {
            return;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}tareas
             WHERE tipo = %s AND referencia_tipo = %s AND referencia_id = %d
               AND estado NOT IN ('completada', 'cancelada')",
            'asignar_regla_precio',
            'familia',
            $grupo_id
        ));
        if (empty($ids)) {
            return;
        }
        $motivo = $motivo !== '' ? $motivo : 'Tarea cancelada';
        foreach ($ids as $tid) {
            $wpdb->update(
                "{$prefix}tareas",
                [
                    'estado' => 'cancelada',
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => intval($tid)],
                ['%s', '%s'],
                ['%d']
            );
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('task_cancelled', 'tarea', intval($tid), [
                    'motivo' => $motivo,
                    'grupo_id' => $grupo_id,
                ]);
            }
        }
    }

    /**
     * Backfill idempotente: tareas para familias unitarias sin regla.
     */
    public static function maybe_backfill_missing_rule_tasks() {
        $done = get_option('riverso_price_rule_tasks_backfill_v2');
        if ($done === '1') {
            return;
        }
        if (!class_exists('Riverso_Unit_Product_Service')) {
            $file = RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('Riverso_Unit_Product_Service')) {
            return;
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . 'equivalence_groups'));
        if (!$found) {
            return;
        }
        $svc = Riverso_Unit_Product_Service::get_instance();
        $ids = $wpdb->get_col(
            "SELECT g.id FROM {$prefix}equivalence_groups g
             WHERE g.activo = 1 AND g.es_producto_unitario = 1
             LIMIT 500"
        );
        foreach ((array) $ids as $gid) {
            $svc->ensure_missing_rule_task(intval($gid));
        }
        update_option('riverso_price_rule_tasks_backfill_v2', '1', false);
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
        return $this->get_assigned_rule_id($target_tipo, $target_id);
    }

    /**
     * Regla asignada a un target (sin resolver versión aprobada).
     *
     * @param string $target_tipo
     * @param int    $target_id
     * @return int|null
     */
    public function get_assigned_rule_id($target_tipo, $target_id) {
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

        if ($p_asignado === null) {
            $p_asignado = $this->resolve_p_asignado_for_base($producto_base_id);
        }
        if ($p_asignado === null) {
            return null;
        }

        $tiers = $this->get_tiers($rule_id);
        return Riverso_Price_Rule_Engine::evaluate($tiers, $p_asignado, $qty);
    }

    /**
     * P base para reglas: producto unitario de la familia si aplica, si no el propio.
     *
     * @param int $producto_base_id
     * @return float|null
     */
    public function resolve_p_asignado_for_base($producto_base_id) {
        if (!class_exists('Riverso_Pricing_Module')) {
            return null;
        }

        $pricing = Riverso_Pricing_Module::get_instance();
        $producto_base_id = intval($producto_base_id);

        $product_rule = $this->get_assigned_rule_id('producto', $producto_base_id);
        $price_base_id = $producto_base_id;

        if (!$product_rule && class_exists('Riverso_Unit_Product_Service')) {
            $ctx = Riverso_Unit_Product_Service::get_instance()->resolve_family_unit_for_base($producto_base_id);
            if ($ctx && !empty($ctx['es_producto_unitario']) && !empty($ctx['unit_producto_base_id'])) {
                $price_base_id = intval($ctx['unit_producto_base_id']);
            }
        }

        $price_row = $pricing->get_local_price($price_base_id);
        if ($price_row && $price_row['p_asignado'] !== null) {
            return (float) $price_row['p_asignado'];
        }

        return null;
    }

    /* ===================== AJAX ===================== */

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices') && !current_user_can('riverso_manage_products')) {
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
                $this->transfer_assignments_for_codigo($rule['codigo'], $new_id);
                wp_send_json_success(['rule_id' => $new_id, 'message' => 'Nueva versión en borrador creada. Asignaciones actualizadas.']);
            }
            $replaced = $this->replace_tiers($rule_id, $tiers);
            if (is_wp_error($replaced)) {
                wp_send_json_error(['message' => $replaced->get_error_message()]);
            }
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
        $tiers = [];
        if (!empty($_POST['tiers'])) {
            $decoded = json_decode(wp_unslash($_POST['tiers']), true);
            if (is_array($decoded)) {
                $normalized = $this->normalize_tiers($decoded);
                if (is_wp_error($normalized)) {
                    wp_send_json_error(['message' => $normalized->get_error_message()]);
                }
                $tiers = $normalized;
            }
        }
        if (!$tiers && $rule_id) {
            $tiers = $this->get_tiers($rule_id);
        }
        $eval = Riverso_Price_Rule_Engine::evaluate_with_total($tiers, $p_asignado, $qty);
        wp_send_json_success([
            'price' => $eval['price'],
            'qty' => $qty,
            'total' => $eval['total'],
            'breakdown' => $eval['breakdown'],
        ]);
    }

    /**
     * Vista previa por tramo (unitario, total T, recálculo).
     */
    public function ajax_eval_formulas() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);
        $qty = floatval($_POST['qty'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }

        $tiers = isset($_POST['tiers']) ? json_decode(wp_unslash($_POST['tiers']), true) : [];
        if (!is_array($tiers)) {
            $tiers = [];
        }

        $results = [];
        foreach ($tiers as $i => $tier) {
            if (!is_array($tier)) {
                $results[(string) $i] = ['ok' => false, 'hint' => 'Tramo inválido'];
                continue;
            }
            try {
                $formula = Riverso_Price_Rule_Engine::sanitize_formula($tier['formula'] ?? '');
                if ($formula !== '') {
                    $check = Riverso_Price_Rule_Engine::validate_formula($formula);
                    if (is_wp_error($check)) {
                        throw new InvalidArgumentException($check->get_error_message());
                    }
                }
                $formula_total = Riverso_Price_Rule_Engine::sanitize_formula($tier['formula_total'] ?? '');
                if ($formula_total !== '') {
                    $check_t = Riverso_Price_Rule_Engine::validate_formula_total($formula_total);
                    if (is_wp_error($check_t)) {
                        throw new InvalidArgumentException($check_t->get_error_message());
                    }
                }

                $normalized = [
                    'formula' => $formula !== '' ? $formula : 'P',
                    'formula_total' => $formula_total !== '' ? $formula_total : null,
                    'total_minimo' => (isset($tier['total_minimo']) && $tier['total_minimo'] !== '' && $tier['total_minimo'] !== null)
                        ? floatval($tier['total_minimo']) : null,
                    'piso_total' => (isset($tier['piso_total']) && $tier['piso_total'] !== '' && $tier['piso_total'] !== null)
                        ? floatval($tier['piso_total']) : null,
                ];

                $breakdown = Riverso_Price_Rule_Engine::explain_tier($normalized, $p_asignado, $qty);
                $results[(string) $i] = [
                    'ok' => true,
                    'breakdown' => $breakdown,
                    'hint' => '',
                ];
            } catch (Exception $e) {
                $results[(string) $i] = ['ok' => false, 'hint' => $e->getMessage()];
            }
        }
        wp_send_json_success(['results' => $results]);
    }

    public function ajax_search_assignments() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices') && !current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $rows = $this->search_assignments([
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'tipo' => sanitize_key($_POST['tipo'] ?? 'all'),
            'rule_id' => intval($_POST['rule_id'] ?? 0),
            'limit' => intval($_POST['limit'] ?? 50),
        ]);
        wp_send_json_success(['items' => $rows]);
    }

    public function ajax_search_targets() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices') && !current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $rows = $this->search_targets([
            'search' => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'tipo' => sanitize_key($_POST['tipo'] ?? 'all'),
            'limit' => intval($_POST['limit'] ?? 20),
        ]);
        wp_send_json_success(['items' => $rows]);
    }

    public function ajax_lookup_target() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_prices') && !current_user_can('riverso_manage_prices')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        $tipo = sanitize_key($_POST['target_tipo'] ?? '');
        $id = intval($_POST['target_id'] ?? 0);
        if (!$tipo || !$id) {
            wp_send_json_error(['message' => 'Tipo e ID requeridos']);
        }
        $row = $this->lookup_target($tipo, $id);
        if (!$row) {
            wp_send_json_error(['message' => 'No encontrado']);
        }
        wp_send_json_success($row);
    }
}
