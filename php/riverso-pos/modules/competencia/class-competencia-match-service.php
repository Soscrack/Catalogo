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

    /** @var array<string,int>|null */
    private static $proveedor_codes = null;

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
        $code = self::normalize_code($competencia_producto['codigo_normalizado'] ?? $competencia_producto['codigo_externo'] ?? '');
        if ($code === '') {
            return null;
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

    public static function upsert_match($producto_competencia_id, $producto_base_id, $metodo, $score, $estado, $nota = '') {
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
     * @return array{blockers:array,can_confirm:bool,message:string,unit_hint:string}
     */
    public static function family_confirm_blockers($producto_base_id, $cantidad_min = 0) {
        global $wpdb;
        $prefix = self::prefix();
        $producto_base_id = (int) $producto_base_id;
        $cantidad_min = (int) $cantidad_min;
        $out = [
            'blockers'    => [],
            'can_confirm' => true,
            'message'     => '',
            'unit_hint'   => '',
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
        foreach ($tasks as $task) {
            $tipo = (string) ($task['tipo'] ?? '');
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
                . 'El código Mamut suele estar en el producto unitario; el ítem de Sande a veces corresponde a un '
                . 'hijo de familia que aún no existe. Resuelve la tarea y vuelve a confirmar.';
        }

        $is_unit = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups
             WHERE unit_producto_base_id = %d AND activo = 1
             LIMIT 1",
            $producto_base_id
        ));
        if ($is_unit > 0 && $cantidad_min > 1) {
            $out['unit_hint'] = sprintf(
                'El producto local es unitario de una familia y Sande vende en envase de %d u. '
                . 'Puede faltar el hijo de familia para ese envase.',
                $cantidad_min
            );
        }

        return $out;
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

        $family = self::family_confirm_blockers($pb_id, (int) ($row['cantidad_min'] ?? 0));

        return [
            'ok'                       => true,
            'can_confirm'              => !empty($family['can_confirm']) && $pb_id > 0 && !empty($local),
            'message'                  => $family['message'],
            'unit_hint'                => $family['unit_hint'],
            'blockers'                 => $family['blockers'],
            'producto_competencia_id'  => $producto_competencia_id,
            'producto_base_id'         => $pb_id,
            'sande'                    => [
                'codigo' => trim((string) ($row['codigo_externo'] ?? '')),
                'nombre' => (string) ($row['nombre'] ?? ''),
                'url'    => self::product_page_url($row),
            ],
            'local'                    => $local ? [
                'id'               => (int) $local['id'],
                'canonical_sku'    => (string) ($local['canonical_sku'] ?? ''),
                'nombre_canonico'  => (string) ($local['nombre_canonico'] ?? ''),
            ] : null,
            'metodo'                   => (string) ($row['metodo'] ?? ''),
            'score'                    => $row['score'] ?? null,
        ];
    }

    public static function confirm_match($producto_competencia_id, $producto_base_id, $user_id = 0, $nota = '') {
        $producto_base_id = (int) $producto_base_id;
        $family = self::family_confirm_blockers($producto_base_id);
        if (empty($family['can_confirm'])) {
            return new WP_Error(
                'familia_pendiente',
                $family['message'] ?: 'Hay tareas de familia pendientes.',
                ['blockers' => $family['blockers'], 'unit_hint' => $family['unit_hint']]
            );
        }
        return self::set_match_state($producto_competencia_id, $producto_base_id, 'confirmado', null, $user_id, $nota);
    }

    public static function reject_match($producto_competencia_id, $user_id = 0, $nota = '') {
        return self::set_match_state($producto_competencia_id, null, 'rechazado', 'manual', $user_id, $nota);
    }

    private static function set_match_state($producto_competencia_id, $producto_base_id, $estado, $metodo, $user_id, $nota) {
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

        $list_sql = "SELECT cp.*, cm.id AS match_id, cm.producto_base_id, cm.metodo, cm.score, cm.estado AS match_estado,
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
}
