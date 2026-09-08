<?php
/**
 * Mapeo manual: (Proveedor, Código-Proveedor) → SKU local
 * Para vincular códigos de folios antiguos y ver historial de costos del par.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Manual_Mapping_Module {

    private static $instance = null;

    /** @var string */
    private $prefix;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->prefix = $wpdb->prefix . 'riverso_';
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('wp_ajax_riverso_manual_map_search_codes', [$this, 'ajax_search_codes']);
        add_action('wp_ajax_riverso_manual_map_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_riverso_manual_map_assign', [$this, 'ajax_assign']);
        add_action('wp_ajax_riverso_manual_map_unmapped', [$this, 'ajax_unmapped_pairs']);
    }

    public function init() {
        // Hooks registrados en constructor (get_instance).
    }

    private function require_capability() {
        if (!current_user_can('riverso_manage_codes')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
    }

    private function intake() {
        if (!class_exists('Riverso_Invoice_Intake_Service')) {
            $file = RIVERSO_POS_PLUGIN_DIR . 'modules/invoices/class-invoice-intake-service.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('Riverso_Invoice_Intake_Service')) {
            return null;
        }
        return Riverso_Invoice_Intake_Service::get_instance();
    }

    private function cost_lookup() {
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            $file = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('Riverso_Cost_Lookup_Service')) {
            return null;
        }
        return Riverso_Cost_Lookup_Service::get_instance();
    }

    /**
     * AJAX: Autocomplete de códigos proveedor filtrados por proveedor.
     */
    public function ajax_search_codes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_capability();

        global $wpdb;

        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? $_POST['q'] ?? ''));
        $limit = max(1, min(40, absint($_POST['limit'] ?? 20)));

        if (!$proveedor_id) {
            wp_send_json_error(['message' => 'Proveedor requerido'], 400);
        }

        $like = $term !== '' ? '%' . $wpdb->esc_like($term) . '%' : null;
        $results = [];
        $seen = [];

        $add = function ($row) use (&$results, &$seen, $limit) {
            if (count($results) >= $limit) {
                return;
            }
            $code = trim((string) ($row['codigo_proveedor'] ?? ''));
            if ($code === '') {
                return;
            }
            $key = strtolower($code);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $results[] = [
                'codigo_proveedor' => $code,
                'descripcion' => $row['descripcion'] ?? '',
                'sku_local' => $row['sku_local'] ?? null,
                'source' => $row['source'] ?? '',
                'last_seen' => $row['last_seen'] ?? null,
                'docs_count' => isset($row['docs_count']) ? (int) $row['docs_count'] : null,
            ];
        };

        // 1) producto_proveedor
        $sql = "SELECT pp.codigo_proveedor, pp.nombre_proveedor AS descripcion,
                       pb.canonical_sku AS sku_local, 'producto_proveedor' AS source,
                       NULL AS last_seen, NULL AS docs_count
                FROM {$this->prefix}producto_proveedor pp
                LEFT JOIN {$this->prefix}producto_base pb ON pb.id = pp.producto_base_id AND pb.deleted_at IS NULL
                WHERE pp.proveedor_id = %d AND pp.activo = 1
                  AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor != ''";
        $params = [$proveedor_id];
        if ($like) {
            $sql .= " AND (pp.codigo_proveedor LIKE %s OR pp.nombre_proveedor LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= " ORDER BY pp.codigo_proveedor ASC LIMIT %d";
        $params[] = $limit;
        foreach ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [] as $row) {
            $add($row);
        }

        // 2) codigos legacy
        if (count($results) < $limit) {
            $sql = "SELECT c.codigo_proveedor, c.descripcion, c.sku_local, 'codigos' AS source,
                           c.last_seen_document_date AS last_seen, NULL AS docs_count
                    FROM {$this->prefix}codigos c
                    WHERE c.proveedor_id = %d AND c.activo = 1
                      AND c.codigo_proveedor IS NOT NULL AND c.codigo_proveedor != ''";
            $params = [$proveedor_id];
            if ($like) {
                $sql .= " AND (c.codigo_proveedor LIKE %s OR c.descripcion LIKE %s OR c.sku_local LIKE %s)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $sql .= " ORDER BY c.codigo_proveedor ASC LIMIT %d";
            $params[] = $limit;
            foreach ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [] as $row) {
                $add($row);
            }
        }

        // 3) Facturas (códigos vistos en folios)
        if (count($results) < $limit) {
            $sql = "SELECT fi.codigo_proveedor,
                           MAX(fi.nombre) AS descripcion,
                           MAX(NULLIF(fi.sku_local, '')) AS sku_local,
                           'factura' AS source,
                           MAX(f.fecha_emision) AS last_seen,
                           COUNT(DISTINCT f.id) AS docs_count
                    FROM {$this->prefix}factura_items fi
                    INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                    WHERE f.proveedor_id = %d
                      AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)
                      AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''";
            $params = [$proveedor_id];
            if ($like) {
                $sql .= " AND (fi.codigo_proveedor LIKE %s OR fi.nombre LIKE %s OR fi.sku_local LIKE %s)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $sql .= " GROUP BY fi.codigo_proveedor
                      ORDER BY MAX(f.fecha_emision) DESC
                      LIMIT %d";
            $params[] = $limit;
            foreach ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [] as $row) {
                $add($row);
            }
        }

        wp_send_json_success(['codes' => $results]);
    }

    /**
     * AJAX: Preview del par — mapeo actual + timeline de costos.
     */
    public function ajax_preview() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_capability();

        global $wpdb;

        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $codigo = sanitize_text_field(wp_unslash($_POST['codigo_proveedor'] ?? ''));
        $limit = max(1, min(50, absint($_POST['limit_per_pair'] ?? 10)));
        $doc_type = sanitize_key($_POST['doc_type'] ?? 'factura');

        if (!$proveedor_id || $codigo === '') {
            wp_send_json_error(['message' => 'Proveedor y código son obligatorios'], 400);
        }

        $proveedor = $wpdb->get_row($wpdb->prepare(
            "SELECT id, nombre, rut FROM {$this->prefix}proveedores WHERE id = %d",
            $proveedor_id
        ), ARRAY_A);

        $intake = $this->intake();
        $current_sku = $intake ? $intake->get_code_current_sku($proveedor_id, $codigo) : null;
        $mapping = $intake ? $intake->lookup_product_mapping($proveedor_id, $codigo) : null;

        $descripcion = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(fi.nombre)
             FROM {$this->prefix}factura_items fi
             INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
             WHERE f.proveedor_id = %d AND fi.codigo_proveedor = %s",
            $proveedor_id,
            $codigo
        ));
        if (!$descripcion) {
            $descripcion = $wpdb->get_var($wpdb->prepare(
                "SELECT nombre_proveedor FROM {$this->prefix}producto_proveedor
                 WHERE proveedor_id = %d AND codigo_proveedor = %s LIMIT 1",
                $proveedor_id,
                $codigo
            ));
        }

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT f.id) AS facturas,
                    MIN(f.fecha_emision) AS primera_fecha,
                    MAX(f.fecha_emision) AS ultima_fecha,
                    SUM(CASE WHEN fi.sku_local IS NULL OR fi.sku_local = '' THEN 1 ELSE 0 END) AS items_sin_sku,
                    COUNT(fi.id) AS items_total
             FROM {$this->prefix}factura_items fi
             INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
             WHERE f.proveedor_id = %d
               AND fi.codigo_proveedor = %s
               AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)",
            $proveedor_id,
            $codigo
        ), ARRAY_A);

        $pair = [[
            'proveedor_id' => $proveedor_id,
            'codigo_proveedor' => $codigo,
            'proveedor_nombre' => $proveedor['nombre'] ?? ('Proveedor #' . $proveedor_id),
            'source' => 'manual_map',
        ]];

        $timeline = [];
        $summary = [];
        $lookup = $this->cost_lookup();
        if ($lookup) {
            $timeline = $lookup->get_timeline($pair, [
                'limit_per_pair' => $limit,
                'doc_type' => $doc_type,
            ]);
            $summary = $lookup->get_pair_summary($pair, $doc_type);
        }

        $product = null;
        if ($current_sku) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, nombre_canonico
                 FROM {$this->prefix}producto_base
                 WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
                $current_sku
            ), ARRAY_A);
            if ($pb) {
                $product = [
                    'producto_base_id' => (int) $pb['id'],
                    'canonical_sku' => $pb['canonical_sku'],
                    'nombre' => $pb['nombre_canonico'],
                ];
            }
        }

        wp_send_json_success([
            'proveedor' => $proveedor,
            'codigo_proveedor' => $codigo,
            'descripcion' => $descripcion,
            'current_sku' => $current_sku,
            'mapping' => $mapping,
            'product' => $product,
            'stats' => [
                'facturas' => (int) ($stats['facturas'] ?? 0),
                'primera_fecha' => $stats['primera_fecha'] ?? null,
                'ultima_fecha' => $stats['ultima_fecha'] ?? null,
                'items_sin_sku' => (int) ($stats['items_sin_sku'] ?? 0),
                'items_total' => (int) ($stats['items_total'] ?? 0),
            ],
            'timeline' => $timeline,
            'summary' => $summary,
        ]);
    }

    /**
     * AJAX: Asignar SKU al par (aplicación retroactiva a todos los folios).
     */
    public function ajax_assign() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_capability();

        global $wpdb;

        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $codigo = sanitize_text_field(wp_unslash($_POST['codigo_proveedor'] ?? ''));
        $sku_local = sanitize_text_field(wp_unslash($_POST['sku_local'] ?? ''));
        $force = !empty($_POST['force']);
        $clear = !empty($_POST['clear']);
        $descripcion = sanitize_text_field(wp_unslash($_POST['descripcion'] ?? ''));
        $audit_reason = sanitize_textarea_field(wp_unslash($_POST['audit_reason'] ?? ''));

        if (!$proveedor_id || $codigo === '') {
            wp_send_json_error(['message' => 'Proveedor y código son obligatorios'], 400);
        }

        if (!$clear && $sku_local === '') {
            wp_send_json_error(['message' => 'SKU local requerido'], 400);
        }

        if (!$clear && function_exists('riverso_sku_equals_supplier_code')
            && riverso_sku_equals_supplier_code($sku_local, $codigo)) {
            wp_send_json_error([
                'message' => 'El SKU local no puede ser el mismo código de proveedor.',
            ]);
        }

        if (!$clear) {
            $base_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->prefix}producto_base
                 WHERE canonical_sku = %s AND deleted_at IS NULL LIMIT 1",
                $sku_local
            ));
            if (!$base_id) {
                wp_send_json_error(['message' => 'SKU local no encontrado en catálogo: ' . $sku_local]);
            }
        }

        $intake = $this->intake();
        if (!$intake) {
            wp_send_json_error(['message' => 'Servicio de mapeo no disponible'], 500);
        }

        $result = $intake->assign_local_sku_mapping(
            $proveedor_id,
            $codigo,
            $clear ? '' : $sku_local,
            [
                'force' => $force,
                'clear' => $clear,
                'apply_all' => true,
                'descripcion' => $descripcion,
                'actor_type' => 'human',
                'origen_datos' => 'manual',
            ]
        );

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            wp_send_json_error(array_merge([
                'message' => $result->get_error_message(),
                'conflict' => $result->get_error_code() === 'sku_conflict',
            ], is_array($data) ? $data : []));
        }

        if ($audit_reason !== '' && class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('manual_mapping_note', 'sku_mapping', 0, [
                'actor_type' => 'human',
                'entity_name' => $clear ? $codigo : $sku_local,
                'details' => $audit_reason,
                'new_value' => [
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo,
                    'sku_local' => $clear ? null : $sku_local,
                    'cleared' => $clear,
                ],
            ]);
        }

        wp_send_json_success([
            'result' => $result,
            'message' => $clear
                ? 'Mapeo desvinculado'
                : sprintf(
                    'SKU %s vinculado. Actualizados %d ítems en %d facturas.',
                    $sku_local,
                    (int) ($result['applied']['items'] ?? 0),
                    (int) ($result['applied']['invoices'] ?? 0)
                ),
        ]);
    }

    /**
     * AJAX: Pares recientes sin SKU (folios antiguos) para precargar el formulario.
     */
    public function ajax_unmapped_pairs() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $this->require_capability();

        global $wpdb;

        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $limit = max(1, min(50, absint($_POST['limit'] ?? 25)));

        $where = "f.proveedor_id IS NOT NULL
                  AND fi.codigo_proveedor IS NOT NULL AND fi.codigo_proveedor != ''
                  AND (fi.sku_local IS NULL OR fi.sku_local = '')
                  AND (fi.item_tipo = 'producto' OR fi.item_tipo IS NULL)";
        $params = [];
        if ($proveedor_id) {
            $where .= ' AND f.proveedor_id = %d';
            $params[] = $proveedor_id;
        }

        $sql = "SELECT f.proveedor_id, fi.codigo_proveedor,
                       p.nombre AS proveedor_nombre,
                       MAX(fi.nombre) AS descripcion,
                       MAX(f.fecha_emision) AS ultima_fecha,
                       COUNT(DISTINCT f.id) AS facturas,
                       COUNT(fi.id) AS items
                FROM {$this->prefix}factura_items fi
                INNER JOIN {$this->prefix}facturas f ON f.id = fi.factura_id
                LEFT JOIN {$this->prefix}proveedores p ON p.id = f.proveedor_id
                WHERE {$where}
                GROUP BY f.proveedor_id, fi.codigo_proveedor
                ORDER BY MAX(f.fecha_emision) DESC
                LIMIT %d";
        $params[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];

        wp_send_json_success([
            'pairs' => array_map(static function ($r) {
                return [
                    'proveedor_id' => (int) $r['proveedor_id'],
                    'codigo_proveedor' => $r['codigo_proveedor'],
                    'proveedor_nombre' => $r['proveedor_nombre'],
                    'descripcion' => $r['descripcion'],
                    'ultima_fecha' => $r['ultima_fecha'],
                    'facturas' => (int) $r['facturas'],
                    'items' => (int) $r['items'],
                ];
            }, $rows),
        ]);
    }
}
