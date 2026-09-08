<?php
/**
 * Motor de sugerencias de match competencia -> producto_base.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Competencia_Match_Service {

    const ESTADOS = ['sugerido', 'confirmado', 'rechazado', 'sin_match'];
    const METODOS = ['codigo_exacto', 'codigo_prefijo', 'sku_mapping', 'similitud', 'manual'];
    const TIPOS_MATCH = ['exacto', 'exacto_envase', 'similar', 'otro'];

    public static function is_allowed_tipo_match($tipo_match) {
        return in_array(sanitize_key((string) $tipo_match), self::TIPOS_MATCH, true);
    }

    public static function tipo_match_label($tipo_match) {
        $map = [
            'exacto'         => 'Exacto',
            'exacto_envase'  => 'Exacto diferente U de envase',
            'similar'        => 'Similar',
            'otro'           => 'Otro',
        ];
        $key = sanitize_key((string) $tipo_match);
        return $map[$key] ?? $tipo_match;
    }

    /** @var array<string,int>|null */
    private static $proveedor_codes = null;

    /** @var array<int,string>|null */
    private static $fuentes_by_id = null;

    /** @var array<int,array>|null */
    private static $productos_base = null;

    private static function prefix() {
        global $wpdb;
        return $wpdb->prefix . 'riverso_';
    }

    public static function normalize_code($value) {
        $value = strtoupper(trim((string) $value));
        return preg_replace('/[^A-Z0-9]/', '', $value);
    }

    public static function normalize_name($value) {
        $value = strtolower(trim((string) $value));
        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    public static function product_page_url(array $row) {
        $stored = trim((string) ($row['url_producto'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        $slug = trim((string) ($row['slug'] ?? ''), " \t\n\r\0\x0B/");
        if ($slug === '') {
            return '';
        }
        $fuente_id = (int) ($row['fuente_id'] ?? 0);
        $fuente_slug = self::fuente_slug_by_id($fuente_id);
        if ($fuente_slug === 'dimafi') {
            return 'https://www.dimafi.cl/products/' . rawurlencode($slug);
        }
        // Legacy por defecto: Sande.
        return 'https://www.sande.cl/producto/' . rawurlencode($slug);
    }

    public static function local_product_url(array $row) {
        $variation_id = (int) ($row['woocommerce_variation_id'] ?? 0);
        $product_id = (int) ($row['woocommerce_product_id'] ?? 0);
        $wc_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($wc_id <= 0 || !function_exists('get_permalink')) {
            return '';
        }
        $url = get_permalink($wc_id);
        return is_string($url) ? $url : '';
    }

    private static function fuente_slug_by_id(int $fuente_id): string {
        if ($fuente_id <= 0) {
            return 'sande';
        }
        if (self::$fuentes_by_id === null) {
            global $wpdb;
            $prefix = self::prefix();
            $rows = $wpdb->get_results(
                "SELECT id, slug FROM {$prefix}competencia_fuentes WHERE activo = 1",
                ARRAY_A
            ) ?: [];

            $map = [];
            foreach ($rows as $r) {
                $id = (int) ($r['id'] ?? 0);
                $slug = (string) ($r['slug'] ?? '');
                if ($id > 0 && $slug !== '') {
                    $map[$id] = $slug;
                }
            }
            self::$fuentes_by_id = $map;
        }

        return self::$fuentes_by_id[$fuente_id] ?? 'sande';
    }

    /**
     * Quita prefijos de envase/kit usados en códigos Mamut (K, KB, KF, I, B, R, F).
     */
    public static function strip_package_prefix($code) {
        $code = self::normalize_code($code);
        if ($code === '') {
            return '';
        }
        if (preg_match('/^(K|KB|KF|I|B|R|F)(.+)$/', $code, $m)) {
            return $m[2];
        }
        return $code;
    }

    /**
     * Extrae tokens de "medidas" para mejorar el match de DIMAFI.
     * Ejemplos: 10x75, 585ML, 500UDS, SDSPLUS, M10, etc.
     */
    private static function extract_measure_tokens(string $text): array {
        $text = strtoupper($text);
        $tokens = [];

        $add = function (string $t) use (&$tokens) {
            $t = trim($t);
            if ($t === '') {
                return;
            }
            $tokens[$t] = true;
        };

        // 10x75 / 8X3 / 5x80 (incluye ×)
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*[X×]\s*(\d+(?:[.,]\d+)?)/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $a = str_replace(',', '.', (string) ($mm[1] ?? ''));
                $b = str_replace(',', '.', (string) ($mm[2] ?? ''));
                $a = rtrim(rtrim($a, '0'), '.');
                $b = rtrim(rtrim($b, '0'), '.');
                $add($a . 'x' . $b);
            }
        }

        // 585 ML / 400 ML / 900 MM / 15 CM ...
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(ML|L|MM|CM)\b/', $text, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $mm) {
                $n = str_replace(',', '.', (string) ($mm[1] ?? ''));
                $n = rtrim(rtrim($n, '0'), '.');
                $unit = (string) ($mm[2] ?? '');
                $add($n . $unit);
            }
        }

        // 500 UDS
        if (preg_match_all('/(\d+)\s*UDS?\.?\b/', $text, $m3, PREG_SET_ORDER)) {
            foreach ($m3 as $mm) {
                $add((string) $mm[1] . 'UDS');
            }
        }

        // SDS PLUS / SDS MAX
        if (preg_match_all('/SDS\s*(PLUS|MAX)/', $text, $m4, PREG_SET_ORDER)) {
            foreach ($m4 as $mm) {
                $add('SDS' . (string) ($mm[1] ?? ''));
            }
        }

        // M8 / M10 / M12 ...
        if (preg_match_all('/\bM(\d{1,3})\b/', $text, $m5, PREG_SET_ORDER)) {
            foreach ($m5 as $mm) {
                $add('M' . (string) $mm[1]);
            }
        }

        return array_keys($tokens);
    }

    private static function load_mamut_catalog_id() {
        global $wpdb;
        $prefix = self::prefix();
        return (int) $wpdb->get_var(
            "SELECT id FROM {$prefix}catalogos WHERE alias = 'mamut' AND activo = 1 ORDER BY id DESC LIMIT 1"
        );
    }

    private static function load_proveedor_codes() {
        if (self::$proveedor_codes !== null) {
            return self::$proveedor_codes;
        }
        global $wpdb;
        $prefix = self::prefix();
        $catalog_id = self::load_mamut_catalog_id();
        $where = $catalog_id > 0
            ? $wpdb->prepare(' AND pp.catalogo_id = %d', $catalog_id)
            : '';

        $rows = $wpdb->get_results(
            "SELECT pp.codigo_proveedor, pp.producto_base_id
             FROM {$prefix}producto_proveedor pp
             WHERE pp.producto_base_id IS NOT NULL
               AND pp.codigo_proveedor IS NOT NULL
               AND pp.codigo_proveedor != ''
               {$where}",
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $norm = self::normalize_code($row['codigo_proveedor']);
            if ($norm === '') {
                continue;
            }
            $map[$norm] = (int) $row['producto_base_id'];
        }
        self::$proveedor_codes = $map;
        return $map;
    }

    private static function load_productos_base() {
        if (self::$productos_base !== null) {
            return self::$productos_base;
        }
        global $wpdb;
        $prefix = self::prefix();
        $rows = $wpdb->get_results(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$prefix}producto_base
             WHERE deleted_at IS NULL AND estado = 'activo'",
            ARRAY_A
        ) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }
        self::$productos_base = $map;
        return $map;
    }

    /**
     * @param array $competencia_producto Fila competencia_productos
     * @return array|null ['producto_base_id'=>int,'metodo'=>string,'score'=>float,'nota'=>string]
     */
    public static function suggest_for_product(array $competencia_producto) {
        $fuente_id = (int) ($competencia_producto['fuente_id'] ?? 0);
        $fuente_slug = self::fuente_slug_by_id($fuente_id);

        $code = self::normalize_code($competencia_producto['codigo_normalizado'] ?? $competencia_producto['codigo_externo'] ?? '');
        if ($code === '' && $fuente_slug !== 'dimafi') {
            return null;
        }

        // DIMAFI: priorizar similitud + tokens de medidas (para tornillos/anclajes con formatos).
        if ($fuente_slug === 'dimafi') {
            $nombre_raw = (string) ($competencia_producto['nombre'] ?? '');
            $nombre = self::normalize_name($nombre_raw);
            if ($nombre !== '') {
                $tokens_comp = self::extract_measure_tokens($nombre_raw);
                $tokens_comp_set = [];
                foreach ($tokens_comp as $t) {
                    $tokens_comp_set[$t] = true;
                }

                $best_id = 0;
                $best_score = 0.0;
                foreach (self::load_productos_base() as $pb_id => $pb) {
                    $target_raw = (string) ($pb['nombre_canonico'] ?? '');
                    $target = self::normalize_name($target_raw);
                    if ($target === '') {
                        continue;
                    }
                    similar_text($nombre, $target, $pct);

                    $tokens_pb = self::extract_measure_tokens($target_raw);
                    $overlap = 0;
                    foreach ($tokens_pb as $t) {
                        if (isset($tokens_comp_set[$t])) {
                            $overlap++;
                        }
                    }

                    $bonus = min(20.0, $overlap * 6.0);
                    $score = (float) $pct + $bonus;
                    if ($score > $best_score) {
                        $best_score = $score;
                        $best_id = (int) $pb_id;
                    }
                }

                if ($best_id > 0 && $best_score >= 60) {
                    return [
                        'producto_base_id' => $best_id,
                        'metodo'           => 'similitud_medidas',
                        'score'            => round($best_score, 2),
                        'nota'             => 'similar_text + tokens de medidas',
                    ];
                }
            }
        }

        $codes = self::load_proveedor_codes();

        if (isset($codes[$code])) {
            return [
                'producto_base_id' => $codes[$code],
                'metodo'           => 'codigo_exacto',
                'score'            => 100.0,
                'nota'             => 'Match por codigo_proveedor exacto',
            ];
        }

        $stripped = self::strip_package_prefix($code);
        if ($stripped !== '' && $stripped !== $code && isset($codes[$stripped])) {
            return [
                'producto_base_id' => $codes[$stripped],
                'metodo'           => 'codigo_prefijo',
                'score'            => 85.0,
                'nota'             => 'Match tras quitar prefijo de envase',
            ];
        }

        if (function_exists('riverso_mamut_online_to_local_sku')) {
            $local_sku = riverso_mamut_online_to_local_sku($competencia_producto['codigo_externo'] ?? $code);
            if ($local_sku) {
                global $wpdb;
                $prefix = self::prefix();
                $pb_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}producto_base WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
                    $local_sku
                ));
                if ($pb_id > 0) {
                    return [
                        'producto_base_id' => $pb_id,
                        'metodo'           => 'sku_mapping',
                        'score'            => 80.0,
                        'nota'             => 'sku_mapping.json -> SKU local ' . $local_sku,
                    ];
                }
            }
        }

        $nombre = self::normalize_name($competencia_producto['nombre'] ?? '');
        if ($nombre === '') {
            return null;
        }

        $best_id = 0;
        $best_score = 0.0;
        foreach (self::load_productos_base() as $pb_id => $pb) {
            $target = self::normalize_name($pb['nombre_canonico'] ?? '');
            if ($target === '') {
                continue;
            }
            similar_text($nombre, $target, $pct);
            if ($pct > $best_score) {
                $best_score = $pct;
                $best_id = (int) $pb_id;
            }
        }

        if ($best_id > 0 && $best_score >= 60) {
            return [
                'producto_base_id' => $best_id,
                'metodo'           => 'similitud',
                'score'            => round($best_score, 2),
                'nota'             => 'similar_text sobre nombre',
            ];
        }

        return null;
    }

    /**
     * Genera sugerencias para productos sin match confirmado.
     *
     * @param int $fuente_id
     * @param int $limit
     * @return array{processed:int,suggested:int,skipped:int}
     */
    public static function run_suggestions($fuente_id = 0, $limit = 500) {
        global $wpdb;
        $prefix = self::prefix();
        $limit = max(1, min(5000, (int) $limit));

        if ($fuente_id <= 0) {
            $fuente_id = (int) $wpdb->get_var(
                "SELECT id FROM {$prefix}competencia_fuentes WHERE slug = 'sande' LIMIT 1"
            );
        }
        if ($fuente_id <= 0) {
            return ['processed' => 0, 'suggested' => 0, 'skipped' => 0];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cp.*
             FROM {$prefix}competencia_productos cp
             LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
             WHERE cp.fuente_id = %d
               AND (cm.id IS NULL OR cm.estado IN ('sugerido', 'sin_match'))
             ORDER BY cp.id ASC
             LIMIT %d",
            $fuente_id,
            $limit
        ), ARRAY_A) ?: [];

        $processed = 0;
        $suggested = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $processed++;
            $suggestion = self::suggest_for_product($row);
            if (!$suggestion) {
                self::upsert_match((int) $row['id'], null, 'sin_match', 0, 'sin_match', 'Sin candidato automático');
                $skipped++;
                continue;
            }
            self::upsert_match(
                (int) $row['id'],
                (int) $suggestion['producto_base_id'],
                $suggestion['metodo'],
                (float) $suggestion['score'],
                'sugerido',
                $suggestion['nota'] ?? ''
            );
            $suggested++;
        }

        return compact('processed', 'suggested', 'skipped');
    }

    public static function upsert_match($producto_competencia_id, $producto_base_id, $metodo, $score, $estado, $nota = '', $tipo_match = '') {
        global $wpdb;
        $prefix = self::prefix();
        $table = "{$prefix}competencia_match";

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE producto_competencia_id = %d",
            $producto_competencia_id
        ), ARRAY_A);

        if ($existing && $existing['estado'] === 'confirmado') {
            return (int) $existing['id'];
        }

        $data = [
            'producto_competencia_id' => (int) $producto_competencia_id,
            'producto_base_id'      => $producto_base_id ? (int) $producto_base_id : null,
            'metodo'                => sanitize_key($metodo),
            'score'                 => $score,
            'estado'                => sanitize_key($estado),
            'nota'                  => $nota,
            'updated_at'            => current_time('mysql'),
        ];
        if ($tipo_match !== '') {
            $data['tipo_match'] = self::is_allowed_tipo_match($tipo_match) ? sanitize_key($tipo_match) : null;
        }

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    /**
     * Tareas de familia abiertas que impiden confirmar un match.
     *
     * @return array{blockers:array,can_confirm:bool,message:string,unit_hint:string,unit_context:array}
     */
    public static function family_confirm_blockers($producto_base_id, $cantidad_min = 0) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_base_id = (int) $producto_base_id;
        $cantidad_min = max(0, (int) $cantidad_min);
        $out = [
            'blockers'     => [],
            'can_confirm'  => true,
            'message'      => '',
            'unit_hint'    => '',
            'unit_context' => self::empty_unit_context(),
        ];
        if ($producto_base_id <= 0) {
            $out['can_confirm'] = false;
            $out['message'] = 'Producto local inválido.';
            return $out;
        }

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, tipo, titulo, estado
             FROM {$prefix}tareas
             WHERE referencia_tipo = 'producto_base'
               AND referencia_id = %d
               AND tipo IN ('preguntar_familia', 'asignar_familia')
               AND estado NOT IN ('completada', 'cancelada')
             ORDER BY FIELD(tipo, 'preguntar_familia', 'asignar_familia'), id ASC",
            $producto_base_id
        ), ARRAY_A) ?: [];

        $labels = function_exists('riverso_get_task_types') ? riverso_get_task_types() : [];
        $has_preguntar = false;
        $has_asignar = false;
        foreach ($tasks as $task) {
            $tipo = (string) ($task['tipo'] ?? '');
            if ($tipo === 'preguntar_familia') {
                $has_preguntar = true;
            }
            if ($tipo === 'asignar_familia') {
                $has_asignar = true;
            }
            $label = $labels[$tipo]['label'] ?? ($task['titulo'] ?: $tipo);
            $url = '';
            if (function_exists('riverso_build_task_product_hub_url')) {
                $url = (string) riverso_build_task_product_hub_url($producto_base_id, $tipo, 'admin');
            }
            if ($url === '') {
                $url = admin_url('admin.php?page=riverso-pos-products&action=detail&id=' . $producto_base_id . '&tab=local');
            }
            $out['blockers'][] = [
                'task_id' => (int) $task['id'],
                'tipo'    => $tipo,
                'label'   => $label,
                'estado'  => (string) ($task['estado'] ?? ''),
                'titulo'  => (string) ($task['titulo'] ?? ''),
                'url'     => $url,
            ];
        }

        if (!empty($out['blockers'])) {
            $out['can_confirm'] = false;
            $out['message'] = 'No se puede confirmar todavía: este producto local tiene tareas de familia pendientes. '
                . 'El código Mamut suele estar en el producto unitario; el ítem de la fuente de competencia a veces corresponde a un '
                . 'hijo de familia que aún no existe. Resuelve la tarea y vuelve a confirmar.';
        }

        $out['unit_context'] = self::build_unit_context($producto_base_id, $cantidad_min, $has_preguntar, $has_asignar);

        if (!empty($out['unit_context']['is_unitario']) && $cantidad_min > 1) {
            $out['unit_hint'] = sprintf(
                'El producto local es unitario (U) y la fuente de competencia vende en envase de %d u. '
                . 'Usa el tipo "Exacto diferente U de envase" para relacionarlos, o confirma otro tipo solo si estás seguro.',
                $cantidad_min
            );
        } elseif (!empty($out['unit_context']['units_differ'])) {
            $out['unit_hint'] = sprintf(
                'Unidades distintas: local %s vs competencia %d u. '
                . 'Si es el mismo producto con otro envase, usa "Exacto diferente U de envase".',
                $out['unit_context']['local_unit_label'],
                $cantidad_min > 0 ? $cantidad_min : 1
            );
        }

        return $out;
    }

    private static function empty_unit_context() {
        return [
            'is_unitario'       => false,
            'has_family'        => false,
            'family_status'     => 'missing',
            'family_warning'    => '',
            'cantidad_min'      => 0,
            'local_cantidad'    => 1,
            'local_unit_label'  => '1',
            'units_differ'      => false,
            'badge_u'           => false,
        ];
    }

    /**
     * Contexto de familia / unidades para warnings de confirmación.
     */
    public static function build_unit_context($producto_base_id, $cantidad_min = 0, $has_preguntar = null, $has_asignar = null) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_base_id = (int) $producto_base_id;
        $cantidad_min = max(0, (int) $cantidad_min);
        $ctx = self::empty_unit_context();
        $ctx['cantidad_min'] = $cantidad_min > 0 ? $cantidad_min : 1;

        if ($producto_base_id <= 0) {
            return $ctx;
        }

        if ($has_preguntar === null || $has_asignar === null) {
            $tipos = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT tipo FROM {$prefix}tareas
                 WHERE referencia_tipo = 'producto_base'
                   AND referencia_id = %d
                   AND tipo IN ('preguntar_familia', 'asignar_familia')
                   AND estado NOT IN ('completada', 'cancelada')",
                $producto_base_id
            )) ?: [];
            $has_preguntar = in_array('preguntar_familia', $tipos, true);
            $has_asignar = in_array('asignar_familia', $tipos, true);
        }

        $is_unit_of_group = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups
             WHERE unit_producto_base_id = %d AND activo = 1
             LIMIT 1",
            $producto_base_id
        )) > 0;

        $pb = $wpdb->get_row($wpdb->prepare(
            "SELECT es_unidad_minima, unit_of_grupo_id FROM {$prefix}producto_base WHERE id = %d",
            $producto_base_id
        ), ARRAY_A) ?: [];

        $is_member = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT em.id
             FROM {$prefix}equivalence_members em
             INNER JOIN {$prefix}equivalence_groups eg ON eg.id = em.grupo_id AND eg.activo = 1
             WHERE em.producto_base_id = %d AND em.activo = 1
             LIMIT 1",
            $producto_base_id
        )) > 0;

        $has_family = $is_unit_of_group || $is_member || !empty($pb['unit_of_grupo_id']);
        $is_unitario = $is_unit_of_group || !empty($pb['es_unidad_minima']);

        $local_cantidad = 1;
        if (!$is_unitario) {
            $envase_qty = $wpdb->get_var($wpdb->prepare(
                "SELECT cantidad_unidades FROM {$prefix}envases
                 WHERE producto_base_id = %d AND activo = 1
                 ORDER BY (cantidad_unidades > 1) DESC, cantidad_unidades DESC
                 LIMIT 1",
                $producto_base_id
            ));
            if ($envase_qty !== null && (int) $envase_qty > 0) {
                $local_cantidad = (int) $envase_qty;
            }
        }

        if ($has_preguntar) {
            $family_status = 'unknown';
            $family_warning = 'No se sabe si este producto tiene familia (tarea "preguntar familia" pendiente).';
        } elseif ($has_asignar || !$has_family) {
            $family_status = 'missing';
            $family_warning = $has_asignar
                ? 'Le falta familia (tarea "asignar familia" pendiente).'
                : 'Este producto local no tiene familia asignada.';
        } else {
            $family_status = 'ok';
            $family_warning = '';
        }

        $comp_qty = $ctx['cantidad_min'];
        $units_differ = ($local_cantidad !== $comp_qty);

        $ctx['is_unitario'] = $is_unitario;
        $ctx['has_family'] = $has_family;
        $ctx['family_status'] = $family_status;
        $ctx['family_warning'] = $family_warning;
        $ctx['local_cantidad'] = $local_cantidad;
        $ctx['local_unit_label'] = $is_unitario ? 'U' : (string) $local_cantidad;
        $ctx['units_differ'] = $units_differ;
        $ctx['badge_u'] = $is_unitario;

        return $ctx;
    }

    /**
     * Datos para el modal de confirmación (resumen + blockers).
     */
    public static function confirm_preflight($producto_competencia_id, $producto_base_id = 0) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_competencia_id = (int) $producto_competencia_id;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cp.*, cm.producto_base_id AS match_pb, cm.metodo, cm.score, cm.estado AS match_estado,
                    pr.cantidad_min, pr.precio_bruto_unitario, pr.precio_bruto_total
             FROM {$prefix}competencia_productos cp
             LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
             LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
             WHERE cp.id = %d",
            $producto_competencia_id
        ), ARRAY_A);

        if (!$row) {
            return [
                'ok'          => false,
                'can_confirm' => false,
                'message'     => 'Producto de competencia no encontrado.',
            ];
        }

        $pb_id = (int) $producto_base_id;
        if ($pb_id <= 0) {
            $pb_id = (int) ($row['match_pb'] ?? 0);
        }

        $local = null;
        if ($pb_id > 0) {
            $local = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                $pb_id
            ), ARRAY_A);
        }

        $cantidad_min = (int) ($row['cantidad_min'] ?? 0);
        $family = self::family_confirm_blockers($pb_id, $cantidad_min);
        $unit_context = $family['unit_context'] ?? self::empty_unit_context();

        return [
            'ok'                       => true,
            'can_confirm'              => !empty($family['can_confirm']) && $pb_id > 0 && !empty($local),
            'message'                  => $family['message'],
            'unit_hint'                => $family['unit_hint'],
            'unit_context'             => $unit_context,
            'blockers'                 => $family['blockers'],
            'producto_competencia_id'  => $producto_competencia_id,
            'producto_base_id'         => $pb_id,
            'sande'                    => [
                'codigo'       => trim((string) ($row['codigo_externo'] ?? '')),
                'nombre'       => (string) ($row['nombre'] ?? ''),
                'url'          => self::product_page_url($row),
                'cantidad_min' => $cantidad_min > 0 ? $cantidad_min : 1,
            ],
            'local'                    => $local ? [
                'id'               => (int) $local['id'],
                'canonical_sku'    => (string) ($local['canonical_sku'] ?? ''),
                'nombre_canonico'  => (string) ($local['nombre_canonico'] ?? ''),
                'is_unitario'      => !empty($unit_context['is_unitario']),
                'badge_u'          => !empty($unit_context['badge_u']),
                'unit_label'       => (string) ($unit_context['local_unit_label'] ?? '1'),
            ] : null,
            'metodo'                   => (string) ($row['metodo'] ?? ''),
            'score'                    => $row['score'] ?? null,
            'tipos_match'              => self::TIPOS_MATCH,
        ];
    }

    public static function confirm_match($producto_competencia_id, $producto_base_id, $user_id = 0, $nota = '', $tipo_match = '') {
        $producto_base_id = (int) $producto_base_id;
        $family = self::family_confirm_blockers($producto_base_id);
        if (empty($family['can_confirm'])) {
            return new WP_Error(
                'familia_pendiente',
                $family['message'] ?: 'Hay tareas de familia pendientes.',
                [
                    'blockers'     => $family['blockers'],
                    'unit_hint'    => $family['unit_hint'],
                    'unit_context' => $family['unit_context'] ?? self::empty_unit_context(),
                ]
            );
        }
        return self::set_match_state($producto_competencia_id, $producto_base_id, 'confirmado', null, $user_id, $nota, $tipo_match);
    }

    public static function reject_match($producto_competencia_id, $user_id = 0, $nota = '') {
        return self::set_match_state($producto_competencia_id, null, 'rechazado', 'manual', $user_id, $nota);
    }

    private static function set_match_state($producto_competencia_id, $producto_base_id, $estado, $metodo, $user_id, $nota, $tipo_match = '') {
        global $wpdb;
        $prefix = self::prefix();
        $table = "{$prefix}competencia_match";

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE producto_competencia_id = %d",
            $producto_competencia_id
        ), ARRAY_A);

        $data = [
            'producto_base_id' => $producto_base_id,
            'estado'           => sanitize_key($estado),
            'revisado_por'     => $user_id > 0 ? $user_id : get_current_user_id(),
            'revisado_at'      => current_time('mysql'),
            'nota'             => $nota,
            'updated_at'       => current_time('mysql'),
        ];

        if ($tipo_match !== '') {
            $data['tipo_match'] = self::is_allowed_tipo_match($tipo_match) ? sanitize_key($tipo_match) : null;
        }

        // Conservar método de sugerencia al confirmar; solo forzar si se pasa uno nuevo.
        if ($metodo !== null && $metodo !== '') {
            $data['metodo'] = sanitize_key($metodo);
        } elseif (!$existing) {
            $data['metodo'] = 'manual';
        }

        if ($existing) {
            $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $data['producto_competencia_id'] = (int) $producto_competencia_id;
        $data['score'] = $producto_base_id ? 100 : 0;
        if (!isset($data['metodo'])) {
            $data['metodo'] = 'manual';
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    /**
     * Lista filas para la UI de revisión.
     */
    public static function list_matches($args = []) {
        global $wpdb;
        $prefix = self::prefix();

        $estado = isset($args['estado']) ? sanitize_key($args['estado']) : '';
        $seccion = isset($args['seccion']) ? sanitize_key($args['seccion']) : '';
        $metodo = isset($args['metodo']) ? sanitize_key($args['metodo']) : '';
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $fuente_slug = isset($args['fuente']) ? sanitize_key($args['fuente']) : 'sande';
        if ($fuente_slug === '') {
            $fuente_slug = 'sande';
        }
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(10, min(100, (int) ($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;

        $fuente_id = (int) ($args['fuente_id'] ?? 0);
        if ($fuente_id <= 0) {
            $fuente_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}competencia_fuentes WHERE slug = %s LIMIT 1",
                $fuente_slug
            ));
        }

        $where = ['cp.fuente_id = %d'];
        $params = [$fuente_id];

        if ($seccion === 'revisar') {
            $where[] = "(cm.estado IN ('sugerido', 'sin_match') OR cm.id IS NULL)";
        } elseif ($seccion === 'vinculados') {
            $where[] = "cm.estado = 'confirmado'";
        } elseif ($seccion === 'rechazados') {
            $where[] = "cm.estado = 'rechazado'";
        } elseif ($estado !== '') {
            $where[] = 'cm.estado = %s';
            $params[] = $estado;
        }
        if ($metodo !== '') {
            $where[] = 'cm.metodo = %s';
            $params[] = $metodo;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(cp.nombre LIKE %s OR cp.codigo_externo LIKE %s OR cp.codigo_normalizado LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*)
            FROM {$prefix}competencia_productos cp
            LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
            WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        $list_sql = "SELECT cp.*, cm.id AS match_id, cm.producto_base_id, cm.metodo, cm.tipo_match, cm.score, cm.estado AS match_estado,
                cm.nota AS match_nota, cm.revisado_at,
                pb.canonical_sku, pb.nombre_canonico, pb.woocommerce_product_id, pb.woocommerce_variation_id,
                pr.precio, pr.precio_lista, pr.precio_bruto_unitario, pr.precio_bruto_total,
                pr.cantidad_min, pr.iva, pr.oculto AS precio_oculto, pr.snapshot_fecha, pr.actualizado_at
            FROM {$prefix}competencia_productos cp
            LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
            LEFT JOIN {$prefix}producto_base pb ON pb.id = cm.producto_base_id
            LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
            WHERE {$where_sql}
            ORDER BY cm.estado ASC, cm.score DESC, cp.nombre ASC
            LIMIT %d OFFSET %d";

        $params_list = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $params_list), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['url_producto'] = self::product_page_url($row);
            $row['url_local'] = self::local_product_url($row);
        }
        unset($row);

        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(cm.estado, 'pendiente') AS estado, COUNT(*) AS total
             FROM {$prefix}competencia_productos cp
             LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
             WHERE cp.fuente_id = %d
             GROUP BY COALESCE(cm.estado, 'pendiente')",
            $fuente_id
        ), ARRAY_A) ?: [];

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'stats'    => $stats,
        ];
    }

    /**
     * Listado para subpestaña Historial: vigente + último snapshot + anterior conservado (01/16).
     */
    public static function list_price_history($args = []) {
        global $wpdb;
        $prefix = self::prefix();

        $fuente_slug = isset($args['fuente']) ? sanitize_key($args['fuente']) : 'sande';
        if ($fuente_slug === '') {
            $fuente_slug = 'sande';
        }
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $solo_vinculados = !empty($args['solo_vinculados']);
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(10, min(100, (int) ($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;

        $fuente_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_fuentes WHERE slug = %s LIMIT 1",
            $fuente_slug
        ));

        $where = ['cp.fuente_id = %d'];
        $params = [$fuente_id];
        if ($solo_vinculados) {
            $where[] = "cm.estado = 'confirmado'";
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(cp.nombre LIKE %s OR cp.codigo_externo LIKE %s OR cp.codigo_normalizado LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*)
            FROM {$prefix}competencia_productos cp
            LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
            WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        $list_sql = "SELECT cp.id, cp.codigo_externo, cp.nombre, cp.slug, cp.url_producto,
                cm.estado AS match_estado, cm.producto_base_id,
                pb.canonical_sku, pb.nombre_canonico,
                pr.precio, pr.precio_lista, pr.precio_bruto_unitario, pr.precio_bruto_total,
                pr.cantidad_min, pr.snapshot_fecha, pr.actualizado_at,
                h_last.snapshot_fecha AS hist_fecha,
                h_last.precio_bruto_unitario AS hist_precio_bruto_unitario,
                h_last.precio_bruto_total AS hist_precio_bruto_total,
                h_prev.snapshot_fecha AS hist_prev_fecha,
                h_prev.precio_bruto_unitario AS hist_prev_precio_bruto_unitario
            FROM {$prefix}competencia_productos cp
            LEFT JOIN {$prefix}competencia_match cm ON cm.producto_competencia_id = cp.id
            LEFT JOIN {$prefix}producto_base pb ON pb.id = cm.producto_base_id
            LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
            LEFT JOIN {$prefix}competencia_precios_historial h_last
              ON h_last.producto_id = cp.id
             AND h_last.snapshot_fecha = (
                    SELECT MAX(h2.snapshot_fecha)
                    FROM {$prefix}competencia_precios_historial h2
                    WHERE h2.producto_id = cp.id
             )
            LEFT JOIN {$prefix}competencia_precios_historial h_prev
              ON h_prev.producto_id = cp.id
             AND h_prev.snapshot_fecha = (
                    SELECT MAX(h3.snapshot_fecha)
                    FROM {$prefix}competencia_precios_historial h3
                    WHERE h3.producto_id = cp.id
                      AND h3.snapshot_fecha < (
                          SELECT MAX(h4.snapshot_fecha)
                          FROM {$prefix}competencia_precios_historial h4
                          WHERE h4.producto_id = cp.id
                      )
             )
            WHERE {$where_sql}
            ORDER BY cp.nombre ASC
            LIMIT %d OFFSET %d";

        $params_list = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $params_list), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['url_producto'] = self::product_page_url($row);
            $row['url_local'] = self::local_product_url($row);
            $last = isset($row['hist_precio_bruto_unitario']) ? (float) $row['hist_precio_bruto_unitario'] : null;
            $prev = isset($row['hist_prev_precio_bruto_unitario']) ? (float) $row['hist_prev_precio_bruto_unitario'] : null;
            $row['variacion_pct'] = null;
            if ($last !== null && $prev !== null && $prev != 0.0) {
                $row['variacion_pct'] = round((($last - $prev) / $prev) * 100, 2);
            }
        }
        unset($row);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Serie de historial: precio de hoy + 01/16 de meses anteriores.
     */
    public static function get_price_series($producto_competencia_id) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_competencia_id = (int) $producto_competencia_id;
        if ($producto_competencia_id <= 0) {
            return null;
        }

        $producto = $wpdb->get_row($wpdb->prepare(
            "SELECT cp.id, cp.codigo_externo, cp.nombre, cp.slug, cp.url_producto,
                    pr.precio_bruto_unitario, pr.precio_bruto_total, pr.cantidad_min,
                    pr.snapshot_fecha, pr.actualizado_at
             FROM {$prefix}competencia_productos cp
             LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
             WHERE cp.id = %d",
            $producto_competencia_id
        ), ARRAY_A);
        if (!$producto) {
            return null;
        }
        $producto['url_producto'] = self::product_page_url($producto);

        $series = $wpdb->get_results($wpdb->prepare(
            "SELECT snapshot_fecha, precio, precio_lista, precio_bruto_unitario, precio_bruto_total,
                    cantidad_min, iva, moneda
             FROM {$prefix}competencia_precios_historial
             WHERE producto_id = %d
             ORDER BY snapshot_fecha ASC",
            $producto_competencia_id
        ), ARRAY_A) ?: [];

        $prev = null;
        foreach ($series as &$point) {
            $cur = isset($point['precio_bruto_unitario']) ? (float) $point['precio_bruto_unitario'] : null;
            $point['delta_pct'] = null;
            if ($cur !== null && $prev !== null && $prev != 0.0) {
                $point['delta_pct'] = round((($cur - $prev) / $prev) * 100, 2);
            }
            $prev = $cur;
        }
        unset($point);

        return [
            'producto' => $producto,
            'series'   => $series,
        ];
    }

    public static function search_local_products($search, $limit = 20) {
        global $wpdb;
        $prefix = self::prefix();
        $search = trim((string) $search);
        if ($search === '') {
            return [];
        }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, marca
             FROM {$prefix}producto_base
             WHERE deleted_at IS NULL
               AND (canonical_sku LIKE %s OR nombre_canonico LIKE %s OR codigo_abierto LIKE %s)
             ORDER BY nombre_canonico ASC
             LIMIT %d",
            $like,
            $like,
            $like,
            max(1, min(50, (int) $limit))
        ), ARRAY_A) ?: [];
    }

    public static function reset_fuentes_cache() {
        self::$fuentes_by_id = null;
    }

    const IVA_FACTOR = 1.19;

    /**
     * Detecta slug de fuente a partir de la URL del competidor.
     *
     * @return string sande|dimafi|manual
     */
    public static function detect_fuente_slug_from_url($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === 'sande.cl' || substr($host, -strlen('.sande.cl')) === '.sande.cl') {
            return 'sande';
        }
        if ($host === 'dimafi.cl' || substr($host, -strlen('.dimafi.cl')) === '.dimafi.cl') {
            return 'dimafi';
        }
        return 'manual';
    }

    /**
     * @return int fuente_id
     */
    public static function ensure_fuente_id($slug) {
        global $wpdb;
        $prefix = self::prefix();
        $slug = sanitize_key($slug);
        if ($slug === '') {
            $slug = 'manual';
        }

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_fuentes WHERE slug = %s LIMIT 1",
            $slug
        ));
        if ($id > 0) {
            return $id;
        }

        $nombres = [
            'sande'  => 'Sande Distribución Industrial',
            'dimafi' => 'DIMAFI',
            'manual' => 'Ingreso manual',
        ];
        $wpdb->insert("{$prefix}competencia_fuentes", [
            'slug'     => $slug,
            'nombre'   => $nombres[$slug] ?? ucfirst($slug),
            'base_url' => null,
            'activo'   => 1,
        ]);
        self::reset_fuentes_cache();
        return (int) $wpdb->insert_id;
    }

    /**
     * Normaliza URL para comparación (sin fragmento, trailing slash).
     */
    public static function normalize_product_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            return $url;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Busca producto de competencia por URL (exacta o normalizada) o por slug de path.
     *
     * @return array|null
     */
    public static function find_producto_by_url($url, $fuente_id = 0) {
        global $wpdb;
        $prefix = self::prefix();
        $url = self::normalize_product_url($url);
        if ($url === '') {
            return null;
        }

        $candidates = [$url];
        $no_www = preg_replace('#://www\.#i', '://', $url);
        if ($no_www !== $url) {
            $candidates[] = $no_www;
        } else {
            $with_www = preg_replace('#://#', '://www.', $url, 1);
            if ($with_www !== $url) {
                $candidates[] = $with_www;
            }
        }

        foreach ($candidates as $candidate) {
            if ($fuente_id > 0) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$prefix}competencia_productos
                     WHERE url_producto = %s AND fuente_id = %d
                     LIMIT 1",
                    $candidate,
                    (int) $fuente_id
                ), ARRAY_A);
            } else {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$prefix}competencia_productos
                     WHERE url_producto = %s
                     LIMIT 1",
                    $candidate
                ), ARRAY_A);
            }
            if ($row) {
                return $row;
            }
        }

        // Fallback: slug del path (Sande/DIMAFI scrapeados sin url_producto).
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $slug = trim(basename(rtrim($path, '/')), " \t\n\r\0\x0B/");
        if ($slug === '' || $slug === '/' || strlen($slug) < 2) {
            return null;
        }
        $slug = rawurldecode($slug);
        if ($fuente_id > 0) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}competencia_productos
                 WHERE slug = %s AND fuente_id = %d
                 LIMIT 1",
                $slug,
                (int) $fuente_id
            ), ARRAY_A) ?: null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}competencia_productos
             WHERE slug = %s
             LIMIT 1",
            $slug
        ), ARRAY_A) ?: null;
    }

    /**
     * Upsert precio vigente + snapshot de historial del día.
     *
     * @param float $precio_bruto_total
     * @param int   $cantidad_min
     * @return bool
     */
    public static function upsert_precio($producto_id, $precio_bruto_total, $cantidad_min = 1) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_id = (int) $producto_id;
        if ($producto_id <= 0) {
            return false;
        }

        $cantidad_min = max(1, (int) $cantidad_min);
        $bruto_t = round((float) $precio_bruto_total, 6);
        $bruto_u = round($bruto_t / $cantidad_min, 6);
        $neto = round($bruto_u / self::IVA_FACTOR, 6);
        $today = current_time('Y-m-d');
        $now = current_time('mysql');

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_precios WHERE producto_id = %d LIMIT 1",
            $producto_id
        ));

        $data = [
            'producto_id'            => $producto_id,
            'snapshot_fecha'         => $today,
            'precio'                 => $neto,
            'precio_lista'           => $neto,
            'precio_bruto_unitario'  => $bruto_u,
            'precio_bruto_total'     => $bruto_t,
            'cantidad_min'           => $cantidad_min,
            'iva'                    => self::IVA_FACTOR,
            'moneda'                 => 'CLP',
            'oculto'                 => 0,
            'actualizado_at'         => $now,
        ];

        if ($existing > 0) {
            unset($data['producto_id']);
            $wpdb->update("{$prefix}competencia_precios", $data, ['id' => $existing]);
        } else {
            $data['created_at'] = $now;
            $wpdb->insert("{$prefix}competencia_precios", $data);
        }

        $hist = [
            'producto_id'           => $producto_id,
            'snapshot_fecha'        => $today,
            'precio'                => $neto,
            'precio_lista'          => $neto,
            'precio_bruto_unitario' => $bruto_u,
            'precio_bruto_total'    => $bruto_t,
            'cantidad_min'          => $cantidad_min,
            'iva'                   => self::IVA_FACTOR,
            'moneda'                => 'CLP',
        ];
        $hist_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}competencia_precios_historial
             WHERE producto_id = %d AND snapshot_fecha = %s LIMIT 1",
            $producto_id,
            $today
        ));
        if ($hist_id > 0) {
            unset($hist['producto_id'], $hist['snapshot_fecha']);
            $wpdb->update("{$prefix}competencia_precios_historial", $hist, ['id' => $hist_id]);
        } else {
            $wpdb->insert("{$prefix}competencia_precios_historial", $hist);
        }

        return true;
    }

    /**
     * Ingreso manual: SKU local + URL + precio → producto competencia + match confirmado.
     *
     * @return array|WP_Error
     */
    public static function manual_ingreso($args) {
        global $wpdb;
        $prefix = self::prefix();

        $producto_base_id = (int) ($args['producto_base_id'] ?? 0);
        $url = self::normalize_product_url($args['url'] ?? '');
        $precio_total = isset($args['precio_total']) ? (float) $args['precio_total'] : 0;
        $unidad = max(1, (int) ($args['unidad'] ?? 1));
        $tipo_match = sanitize_key($args['tipo_match'] ?? '');
        $nota = isset($args['nota']) ? (string) $args['nota'] : '';
        $user_id = (int) ($args['user_id'] ?? get_current_user_id());

        if ($producto_base_id <= 0) {
            return new WP_Error('invalid_sku', 'Debes seleccionar un SKU local.');
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('invalid_url', 'La URL del competidor no es válida.');
        }
        if ($precio_total <= 0) {
            return new WP_Error('invalid_precio', 'El precio total debe ser mayor a 0.');
        }
        if (!self::is_allowed_tipo_match($tipo_match)) {
            return new WP_Error('invalid_tipo', 'Debes seleccionar el tipo de match (Exacto, Exacto diferente U de envase, Similar u Otro).');
        }

        $local = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, marca
             FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);
        if (!$local) {
            return new WP_Error('sku_not_found', 'SKU local no encontrado.');
        }

        $family = self::family_confirm_blockers($producto_base_id, $unidad);
        if (empty($family['can_confirm'])) {
            return new WP_Error(
                'familia_pendiente',
                $family['message'] ?: 'Hay tareas de familia pendientes.',
                ['blockers' => $family['blockers'], 'unit_hint' => $family['unit_hint']]
            );
        }

        $fuente_slug = self::detect_fuente_slug_from_url($url);
        $fuente_id = self::ensure_fuente_id($fuente_slug);

        $existing = self::find_producto_by_url($url, $fuente_id);
        if (!$existing && in_array($fuente_slug, ['sande', 'dimafi'], true)) {
            // También buscar sin filtrar fuente por si la URL ya existe en otra fila.
            $existing = self::find_producto_by_url($url, 0);
        }

        if ($existing) {
            $comp_id = (int) $existing['id'];
            $match = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}competencia_match WHERE producto_competencia_id = %d",
                $comp_id
            ), ARRAY_A);
            if ($match && $match['estado'] === 'confirmado') {
                $other_pb = (int) ($match['producto_base_id'] ?? 0);
                if ($other_pb > 0 && $other_pb !== $producto_base_id) {
                    $other = $wpdb->get_row($wpdb->prepare(
                        "SELECT canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE id = %d",
                        $other_pb
                    ), ARRAY_A);
                    $sku_label = $other
                        ? trim(($other['canonical_sku'] ?? '') . ' — ' . ($other['nombre_canonico'] ?? ''))
                        : ('#' . $other_pb);
                    return new WP_Error(
                        'already_linked',
                        'Esta URL ya está vinculada al SKU ' . $sku_label . '.',
                        ['producto_base_id' => $other_pb, 'canonical_sku' => $other['canonical_sku'] ?? '']
                    );
                }
            }
            // Actualizar URL si faltaba.
            if (empty($existing['url_producto'])) {
                $wpdb->update(
                    "{$prefix}competencia_productos",
                    ['url_producto' => $url, 'updated_at' => current_time('mysql')],
                    ['id' => $comp_id]
                );
            }
        } else {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $slug = trim(basename(rtrim($path, '/')), " \t\n\r\0\x0B/");
            $slug = $slug !== '' ? rawurldecode($slug) : ('manual-' . substr(md5($url), 0, 10));
            $id_externo = 'm-' . substr(md5($url), 0, 16);

            $wpdb->insert("{$prefix}competencia_productos", [
                'fuente_id'       => $fuente_id,
                'id_externo'      => $id_externo,
                'codigo_externo'  => null,
                'codigo_normalizado' => null,
                'nombre'          => (string) ($local['nombre_canonico'] ?? ''),
                'slug'            => $slug,
                'url_producto'    => $url,
                'marca'           => null,
                'capturado_at'    => current_time('Y-m-d'),
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ]);
            $comp_id = (int) $wpdb->insert_id;
            if ($comp_id <= 0) {
                return new WP_Error('insert_failed', 'No se pudo crear el producto de competencia.');
            }
        }

        self::upsert_precio($comp_id, $precio_total, $unidad);

        $match_id = self::set_match_state(
            $comp_id,
            $producto_base_id,
            'confirmado',
            'manual',
            $user_id,
            $nota !== '' ? $nota : 'Ingreso manual',
            $tipo_match
        );

        return [
            'producto_competencia_id' => $comp_id,
            'match_id'                => $match_id,
            'fuente_slug'             => $fuente_slug,
            'revisado_at'             => current_time('mysql'),
        ];
    }

    /**
     * Lista SKUs locales con filtros de mapeo competencia (para Ingreso Manual).
     */
    public static function list_local_skus($args = []) {
        global $wpdb;
        $prefix = self::prefix();

        $filtro = isset($args['filtro']) ? sanitize_key($args['filtro']) : 'todos';
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;

        $where = ['pb.deleted_at IS NULL'];
        $params = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR pb.codigo_abierto LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filtro === 'sugerencias') {
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $prefix . 'competencia_match cm
                WHERE cm.producto_base_id = pb.id AND cm.estado = \'sugerido\'
            )';
        } elseif ($filtro === 'sin_mapeo') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM ' . $prefix . 'competencia_match cm
                WHERE cm.producto_base_id = pb.id
            )';
        } elseif ($filtro === 'con_vinculo') {
            $where[] = 'EXISTS (
                SELECT 1 FROM ' . $prefix . 'competencia_match cm
                WHERE cm.producto_base_id = pb.id AND cm.estado = \'confirmado\'
            )';
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$prefix}producto_base pb WHERE {$where_sql}";
        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        $list_sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico, pb.marca,
            pb.woocommerce_product_id, pb.woocommerce_variation_id,
            (SELECT COUNT(*) FROM {$prefix}competencia_match cm2
             WHERE cm2.producto_base_id = pb.id AND cm2.estado = 'sugerido') AS sugerencias,
            (SELECT COUNT(*) FROM {$prefix}competencia_match cm3
             WHERE cm3.producto_base_id = pb.id AND cm3.estado = 'confirmado') AS vinculados
            FROM {$prefix}producto_base pb
            WHERE {$where_sql}
            ORDER BY pb.nombre_canonico ASC
            LIMIT %d OFFSET %d";

        $list_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A) ?: [];

        $ids = array_map(static function ($r) {
            return (int) $r['id'];
        }, $rows);

        $online_map = self::batch_online_skus_for_rows($rows);

        $fuentes_map = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $links = $wpdb->get_results(
                "SELECT cm.producto_base_id, f.slug, f.nombre
                 FROM {$prefix}competencia_match cm
                 INNER JOIN {$prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
                 LEFT JOIN {$prefix}competencia_fuentes f ON f.id = cp.fuente_id
                 WHERE cm.producto_base_id IN ({$in})
                   AND cm.estado = 'confirmado'",
                ARRAY_A
            ) ?: [];
            foreach ($links as $link) {
                $pb = (int) $link['producto_base_id'];
                if (!isset($fuentes_map[$pb])) {
                    $fuentes_map[$pb] = [];
                }
                $slug = (string) ($link['slug'] ?? 'manual');
                $fuentes_map[$pb][$slug] = (string) ($link['nombre'] ?? $slug);
            }
        }

        foreach ($rows as &$row) {
            $pb = (int) $row['id'];
            $row['sugerencias'] = (int) ($row['sugerencias'] ?? 0);
            $row['vinculados'] = (int) ($row['vinculados'] ?? 0);
            $row['sku_local'] = (string) ($row['canonical_sku'] ?? '');
            $row['sku_online'] = (string) ($online_map[$pb] ?? '');
            $row['fuentes'] = [];
            if (!empty($fuentes_map[$pb])) {
                foreach ($fuentes_map[$pb] as $slug => $nombre) {
                    $row['fuentes'][] = ['slug' => $slug, 'nombre' => $nombre];
                }
            }
            unset($row['woocommerce_product_id'], $row['woocommerce_variation_id']);
        }
        unset($row);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Resuelve SKU WooCommerce (_sku) en batch para filas de producto_base.
     *
     * @param array<int,array> $rows
     * @return array<int,string> producto_base_id => sku_online
     */
    private static function batch_online_skus_for_rows(array $rows) {
        global $wpdb;
        $map = [];
        $woo_to_pb = [];
        foreach ($rows as $row) {
            $pb_id = (int) ($row['id'] ?? 0);
            if ($pb_id <= 0) {
                continue;
            }
            $map[$pb_id] = '';
            $variation_id = (int) ($row['woocommerce_variation_id'] ?? 0);
            $product_id = (int) ($row['woocommerce_product_id'] ?? 0);
            $woo_id = $variation_id > 0 ? $variation_id : $product_id;
            if ($woo_id > 0) {
                $woo_to_pb[$woo_id] = $pb_id;
            }
        }
        if (!$woo_to_pb) {
            return $map;
        }

        $woo_ids = array_keys($woo_to_pb);
        $placeholders = implode(',', array_fill(0, count($woo_ids), '%d'));
        $query_params = array_merge(['_sku'], $woo_ids);
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
               AND post_id IN ({$placeholders})",
            $query_params
        ), ARRAY_A) ?: [];

        foreach ($meta_rows as $meta) {
            $woo_id = (int) ($meta['post_id'] ?? 0);
            $pb_id = $woo_to_pb[$woo_id] ?? 0;
            if ($pb_id > 0) {
                $map[$pb_id] = (string) ($meta['meta_value'] ?? '');
            }
        }

        return $map;
    }

    /**
     * Sugerencias pendientes (estado=sugerido) para un SKU local.
     *
     * @return array{producto:array|null,rows:array}
     */
    public static function list_sugerencias_for_sku($producto_base_id) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_base_id = (int) $producto_base_id;
        if ($producto_base_id <= 0) {
            return ['producto' => null, 'rows' => []];
        }

        $producto = $wpdb->get_row($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, marca
             FROM {$prefix}producto_base
             WHERE id = %d AND deleted_at IS NULL",
            $producto_base_id
        ), ARRAY_A);

        if (!$producto) {
            return ['producto' => null, 'rows' => []];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cm.id AS match_id, cm.metodo, cm.score, cm.estado AS match_estado, cm.nota AS match_nota,
                    cm.producto_base_id, cm.tipo_match,
                    cp.id AS producto_competencia_id, cp.nombre, cp.codigo_externo, cp.url_producto, cp.slug,
                    cp.fuente_id, cp.nombre_categoria,
                    f.slug AS fuente_slug, f.nombre AS fuente_nombre,
                    pr.precio, pr.precio_bruto_unitario, pr.precio_bruto_total, pr.cantidad_min, pr.oculto AS precio_oculto
             FROM {$prefix}competencia_match cm
             INNER JOIN {$prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
             LEFT JOIN {$prefix}competencia_fuentes f ON f.id = cp.fuente_id
             LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id
             WHERE cm.producto_base_id = %d
               AND cm.estado = 'sugerido'
             ORDER BY cm.score DESC, cp.nombre ASC
             LIMIT 100",
            $producto_base_id
        ), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['url_producto'] = self::product_page_url($row);
            $row['fuente_slug'] = (string) ($row['fuente_slug'] ?? '');
            $fuente_nombre = (string) ($row['fuente_nombre'] ?? '');
            if ($fuente_nombre === '') {
                $fuente_nombre = $row['fuente_slug'] !== '' ? $row['fuente_slug'] : '—';
            }
            $row['fuente_nombre'] = $fuente_nombre;
            $row['id'] = (int) ($row['producto_competencia_id'] ?? 0);
        }
        unset($row);

        return [
            'producto' => $producto,
            'rows'     => $rows,
        ];
    }

    /**
     * Lista matches confirmados de todas las fuentes (vista Fuentes).
     */
    public static function list_vinculados_todas_fuentes($args = []) {
        global $wpdb;
        $prefix = self::prefix();

        $fuente_slug = isset($args['fuente']) ? sanitize_key($args['fuente']) : '';
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;

        $where = ["cm.estado = 'confirmado'"];
        $params = [];

        if ($fuente_slug !== '' && $fuente_slug !== 'todas') {
            $where[] = 'f.slug = %s';
            $params[] = $fuente_slug;
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR cp.url_producto LIKE %s OR cp.nombre LIKE %s OR cp.codigo_externo LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $from = "FROM {$prefix}competencia_match cm
            INNER JOIN {$prefix}competencia_productos cp ON cp.id = cm.producto_competencia_id
            LEFT JOIN {$prefix}competencia_fuentes f ON f.id = cp.fuente_id
            LEFT JOIN {$prefix}producto_base pb ON pb.id = cm.producto_base_id
            LEFT JOIN {$prefix}competencia_precios pr ON pr.producto_id = cp.id";

        $count_sql = "SELECT COUNT(*) {$from} WHERE {$where_sql}";
        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total = (int) $wpdb->get_var($count_sql);
        }

        $list_sql = "SELECT cm.id AS match_id, cm.tipo_match, cm.metodo, cm.revisado_at, cm.producto_base_id,
                cp.id AS producto_competencia_id, cp.nombre AS nombre_competencia, cp.codigo_externo,
                cp.url_producto, cp.slug, cp.fuente_id,
                f.slug AS fuente_slug, f.nombre AS fuente_nombre,
                pb.canonical_sku, pb.nombre_canonico,
                pb.woocommerce_product_id, pb.woocommerce_variation_id,
                pr.precio_bruto_total, pr.precio_bruto_unitario, pr.cantidad_min
            {$from}
            WHERE {$where_sql}
            ORDER BY cm.revisado_at DESC, cm.id DESC
            LIMIT %d OFFSET %d";

        $list_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['url_producto'] = self::product_page_url($row);
            $row['url_local'] = self::local_product_url($row);
            $row['fuente_slug'] = (string) ($row['fuente_slug'] ?? '');
            $row['fuente_nombre'] = (string) ($row['fuente_nombre'] ?? $row['fuente_slug']);
        }
        unset($row);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }
}
