<?php
/**
 * Mapeo autoritativo de códigos de barra (aprobar / rechazar / conflictos).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Barcode_Mapping_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_riverso_barcode_approve', [$this, 'ajax_approve']);
        add_action('wp_ajax_riverso_barcode_reject', [$this, 'ajax_reject']);
        add_action('wp_ajax_riverso_barcode_assign', [$this, 'ajax_assign']);
        add_action('wp_ajax_riverso_barcode_list_conflicts', [$this, 'ajax_list_conflicts']);
        add_action('wp_ajax_riverso_barcode_list_pending', [$this, 'ajax_list_pending']);
        add_action('wp_ajax_riverso_barcode_mapping_stats', [$this, 'ajax_stats']);
        add_action('wp_ajax_riverso_barcode_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_riverso_barcode_list_products', [$this, 'ajax_list_products']);
        add_action('wp_ajax_riverso_barcode_approve_unambiguous', [$this, 'ajax_approve_unambiguous']);
        add_action('wp_ajax_riverso_barcode_list_by_sku', [$this, 'ajax_list_by_sku']);
        add_action('wp_ajax_riverso_barcode_get_sku_detail', [$this, 'ajax_get_sku_detail']);
        add_action('wp_ajax_riverso_barcode_upsert', [$this, 'ajax_upsert']);
        add_action('wp_ajax_riverso_barcode_create_envase', [$this, 'ajax_create_envase']);
        add_action('wp_ajax_riverso_envase_tipos_list', [$this, 'ajax_envase_tipos_list']);
        add_action('wp_ajax_riverso_envase_tipo_create', [$this, 'ajax_envase_tipo_create']);
        add_action('wp_ajax_riverso_envase_tipo_toggle', [$this, 'ajax_envase_tipo_toggle']);

        if (function_exists('riverso_event_subscribe')) {
            riverso_event_subscribe('product.created', [$this, 'on_product_created'], 20);
        }
    }

    private function can_manage() {
        return current_user_can('riverso_manage_products') || current_user_can('riverso_assign_barcodes');
    }

    private function can_view() {
        return $this->can_manage() || current_user_can('riverso_scan_barcodes') || current_user_can('riverso_view_products');
    }

    public function ajax_approve() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $id = absint($_POST['codigo_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Código no encontrado']);
        }
        if (empty($row['producto_base_id'])) {
            wp_send_json_error(['message' => 'No se puede verificar sin producto_base. Reasigna o espera el alta del SKU.']);
        }

        $motivo = sanitize_text_field($_POST['motivo'] ?? 'Aprobado desde portal.');
        $is_legacy = class_exists('Riverso_Barcode_Model') && Riverso_Barcode_Model::is_legacy_row($row);

        if ($is_legacy) {
            $ok = Riverso_Barcode_Model::accept_legacy_as_supplier($id, $motivo);
        } else {
            $ok = Riverso_Barcode_Model::set_status($id, 'verificado', $motivo);
        }
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo aprobar']);
        }

        if (class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->close_legacy_barcode_tasks(
                $id,
                (string) ($row['codigo'] ?? ''),
                (int) ($row['producto_base_id'] ?? 0)
            );
        }

        $this->audit('barcode_approved', $id, $row);
        wp_send_json_success([
            'message' => $is_legacy
                ? 'Código verificado como Código de Proveedor'
                : 'Código verificado',
            'id' => $id,
        ]);
    }

    public function ajax_reject() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $id = absint($_POST['codigo_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'ID requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}codigo_barra WHERE id = %d",
            $id
        ), ARRAY_A);

        $motivo = sanitize_text_field($_POST['motivo'] ?? 'Rechazado desde portal.');
        $is_legacy = $row && class_exists('Riverso_Barcode_Model') && Riverso_Barcode_Model::is_legacy_row($row);

        if ($is_legacy) {
            $ok = Riverso_Barcode_Model::reject_legacy($id, $motivo);
        } else {
            $ok = Riverso_Barcode_Model::set_status($id, 'rechazado', $motivo);
        }
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo rechazar']);
        }

        if ($row && class_exists('Riverso_Product_Module')) {
            Riverso_Product_Module::get_instance()->close_legacy_barcode_tasks(
                $id,
                (string) ($row['codigo'] ?? ''),
                (int) ($row['producto_base_id'] ?? 0)
            );
        }

        $this->audit('barcode_rejected', $id, ['motivo' => $motivo]);
        wp_send_json_success(['message' => 'Código rechazado', 'id' => $id]);
    }

    public function ajax_assign() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $codigo = sanitize_text_field($_POST['codigo'] ?? '');
        $codigo_id = absint($_POST['codigo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $pending_sku = sanitize_text_field($_POST['pending_sku'] ?? '');
        $verify = !empty($_POST['verify']);

        if ($codigo_id && $codigo === '') {
            $codigo = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT codigo FROM {$prefix}codigo_barra WHERE id = %d",
                $codigo_id
            ));
        }
        if ($codigo === '') {
            wp_send_json_error(['message' => 'Código requerido']);
        }

        if ($producto_base_id <= 0 && $pending_sku === '') {
            wp_send_json_error(['message' => 'Indica un producto o un SKU pendiente']);
        }

        $pb = null;
        if ($producto_base_id > 0) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, unidad_base FROM {$prefix}producto_base WHERE id = %d",
                $producto_base_id
            ), ARRAY_A);
            if (!$pb) {
                wp_send_json_error(['message' => 'Producto no encontrado']);
            }
        }

        $estado = ($verify && $pb) ? 'verificado' : 'propuesto';
        $motivo = $pb
            ? ($verify ? 'Asignado y verificado.' : 'Reasignado; pendiente de verificación.')
            : ('esperando alta de producto ' . $pending_sku);

        $payload = [
            'producto_base_id' => $pb ? intval($pb['id']) : null,
            'pending_sku' => $pb ? null : $pending_sku,
            'unidad_medida' => $pb['unidad_base'] ?? 'unidad',
            'estado' => $estado,
            'activo' => 1,
            'motivo_estado' => $motivo,
            'estado_por' => get_current_user_id() ?: null,
            'estado_at' => current_time('mysql'),
            'requires_human_review' => $estado === 'propuesto' ? 1 : 0,
            'conflicto' => 0,
            'origen_datos' => 'manual',
        ];

        if ($codigo_id) {
            $wpdb->update("{$prefix}codigo_barra", $payload, ['id' => $codigo_id]);
            $id = $codigo_id;
        } else {
            $payload['codigo'] = $codigo;
            $payload['tipo'] = 'ean13';
            $payload['cantidad'] = 1;
            $payload['factor_a_unidad_base'] = 1;
            $payload['created_at'] = current_time('mysql');
            $wpdb->insert("{$prefix}codigo_barra", $payload);
            $id = (int) $wpdb->insert_id;
        }

        if ($estado === 'verificado') {
            Riverso_Barcode_Model::set_status($id, 'verificado', $motivo);
        }

        $import = RIVERSO_POS_PLUGIN_DIR . 'migrations/phase29_barcodes_import_legacy.php';
        if (file_exists($import)) {
            require_once $import;
            if (class_exists('Riverso_Barcode_Legacy_Importer')) {
                Riverso_Barcode_Legacy_Importer::mark_conflicts($prefix);
            }
        }

        $this->audit('barcode_assigned', $id, $payload);
        wp_send_json_success(['message' => 'Mapeo actualizado', 'id' => $id, 'estado' => $estado]);
    }

    public function ajax_list_conflicts() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $items = $this->list_groups('conflict');
        $shared = $this->list_shared_sku();
        if (empty($shared)) {
            $shared = $this->list_legacy_shared_sku();
        }
        wp_send_json_success([
            'items' => $items,
            'shared_sku' => $shared,
        ]);
    }

    public function ajax_list_pending() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $items = $this->list_groups('pending');
        if (empty($items)) {
            $items = $this->list_legacy_pending();
        }
        wp_send_json_success(['items' => $items]);
    }

    public function ajax_stats() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $stats = [
            'verificados' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigo_barra WHERE estado = 'verificado' AND activo = 1"),
            'propuestos' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigo_barra WHERE estado = 'propuesto' AND activo = 1"),
            'conflictos' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT codigo) FROM {$prefix}codigo_barra WHERE conflicto = 1 AND estado = 'propuesto'"),
            'pendiente_sku' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigo_barra WHERE estado = 'propuesto' AND producto_base_id IS NULL"),
            'rechazados' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigo_barra WHERE estado = 'rechazado'"),
        ];
        wp_send_json_success($stats);
    }

    public function ajax_search_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $q = sanitize_text_field($_POST['query'] ?? '');
        if (strlen($q) < 1) {
            wp_send_json_success(['items' => []]);
        }
        $like = '%' . $wpdb->esc_like($q) . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico
             FROM {$prefix}producto_base
             WHERE estado = 'activo'
               AND (canonical_sku LIKE %s OR nombre_canonico LIKE %s)
             ORDER BY
                CASE WHEN canonical_sku = %s THEN 0 WHEN canonical_sku LIKE %s THEN 1 ELSE 2 END,
                canonical_sku ASC
             LIMIT 20",
            $like,
            $like,
            $q,
            $wpdb->esc_like($q) . '%'
        ), ARRAY_A) ?: [];
        wp_send_json_success(['items' => $rows]);
    }

    public function ajax_list_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(100, max(10, absint($_POST['per_page'] ?? 40)));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $filter = sanitize_key($_POST['filter'] ?? 'all');
        $offset = ($page - 1) * $per_page;

        $where = ['pb.deleted_at IS NULL', 'pb.archived_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR EXISTS (
                SELECT 1 FROM {$prefix}codigo_barra cbx
                WHERE cbx.producto_base_id = pb.id AND cbx.codigo LIKE %s
            ))";
            array_push($params, $like, $like, $like);
        }

        $having = '';
        if ($filter === 'sin_codigo') {
            $having = 'HAVING barcodes = 0';
        } elseif ($filter === 'con_codigo') {
            $having = 'HAVING barcodes > 0';
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT pb.id, pb.canonical_sku, pb.nombre_canonico,
                       (
                           SELECT COUNT(DISTINCT cbx.codigo)
                           FROM {$prefix}codigo_barra cbx
                           WHERE cbx.activo = 1
                             AND cbx.estado IN ('verificado','propuesto')
                             AND (
                                  cbx.producto_base_id = pb.id
                               OR (
                                    pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
                                    AND cbx.producto_base_id IS NULL
                                    AND (cbx.sku_local = pb.canonical_sku OR cbx.pending_sku = pb.canonical_sku)
                                  )
                             )
                       ) AS barcodes,
                       (
                           SELECT SUM(cbx.estado = 'verificado' AND cbx.activo = 1)
                           FROM {$prefix}codigo_barra cbx
                           WHERE cbx.activo = 1
                             AND cbx.estado IN ('verificado','propuesto')
                             AND (
                                  cbx.producto_base_id = pb.id
                               OR (
                                    pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
                                    AND cbx.producto_base_id IS NULL
                                    AND (cbx.sku_local = pb.canonical_sku OR cbx.pending_sku = pb.canonical_sku)
                                  )
                             )
                       ) AS verificados,
                       (
                           SELECT GROUP_CONCAT(DISTINCT cbx.codigo ORDER BY cbx.codigo SEPARATOR ', ')
                           FROM {$prefix}codigo_barra cbx
                           WHERE cbx.activo = 1
                             AND cbx.estado IN ('verificado','propuesto')
                             AND (
                                  cbx.producto_base_id = pb.id
                               OR (
                                    pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
                                    AND cbx.producto_base_id IS NULL
                                    AND (cbx.sku_local = pb.canonical_sku OR cbx.pending_sku = pb.canonical_sku)
                                  )
                             )
                       ) AS barcode_sample
                FROM {$prefix}producto_base pb
                WHERE {$where_sql}
                {$having}
                ORDER BY (pb.canonical_sku IS NULL OR pb.canonical_sku = '') ASC, pb.canonical_sku ASC, pb.id ASC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        $items = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

        $count_sql = "SELECT COUNT(*) FROM (
                        SELECT pb.id,
                               (
                                   SELECT COUNT(DISTINCT cbx.codigo)
                                   FROM {$prefix}codigo_barra cbx
                                   WHERE cbx.activo = 1
                                     AND cbx.estado IN ('verificado','propuesto')
                                     AND (
                                          cbx.producto_base_id = pb.id
                                       OR (
                                            pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
                                            AND cbx.producto_base_id IS NULL
                                            AND (cbx.sku_local = pb.canonical_sku OR cbx.pending_sku = pb.canonical_sku)
                                          )
                                     )
                               ) AS barcodes
                        FROM {$prefix}producto_base pb
                        WHERE {$where_sql}
                        {$having}
                      ) t";
        $count_params = array_slice($params, 0, -2);
        $total = (int) ($count_params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $count_params))
            : $wpdb->get_var($count_sql));

        wp_send_json_success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }

    public function ajax_approve_unambiguous() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $import = RIVERSO_POS_PLUGIN_DIR . 'migrations/phase29_barcodes_import_legacy.php';
        require_once $import;
        $count = Riverso_Barcode_Legacy_Importer::auto_verify_unambiguous($GLOBALS['wpdb']->prefix . 'riverso_');
        wp_send_json_success(['message' => "Auto-verificados: {$count}", 'count' => $count]);
    }

    public function ajax_list_by_sku() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = min(100, max(10, absint($_POST['per_page'] ?? 40)));
        $search = sanitize_text_field($_POST['search'] ?? '');
        $estado = sanitize_text_field($_POST['estado'] ?? '');
        $offset = ($page - 1) * $per_page;

        $where = ['cb.activo = 1'];
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(cb.codigo LIKE %s OR cb.sku_local LIKE %s OR cb.pending_sku LIKE %s OR pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        if (in_array($estado, ['verificado', 'propuesto', 'rechazado', 'en_desuso'], true)) {
            $where[] = 'cb.estado = %s';
            $params[] = $estado;
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT
                    COALESCE(NULLIF(cb.sku_local, ''), NULLIF(cb.pending_sku, ''), NULLIF(pb.canonical_sku, ''), '(sin sku)') AS sku_key,
                    MAX(pb.nombre_canonico) AS nombre,
                    MAX(cb.producto_base_id) AS producto_base_id,
                    COUNT(*) AS barcodes,
                    SUM(cb.estado = 'verificado') AS verificados,
                    SUM(cb.estado = 'propuesto') AS propuestos,
                    SUM(cb.conflicto = 1) AS conflictos,
                    MAX(cb.updated_at) AS last_mod
                FROM {$prefix}codigo_barra cb
                LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                WHERE {$where_sql}
                GROUP BY sku_key
                ORDER BY last_mod DESC, sku_key ASC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        $items = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

        $count_sql = "SELECT COUNT(*) FROM (
                        SELECT 1
                        FROM {$prefix}codigo_barra cb
                        LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                        WHERE {$where_sql}
                        GROUP BY COALESCE(NULLIF(cb.sku_local, ''), NULLIF(cb.pending_sku, ''), NULLIF(pb.canonical_sku, ''), '(sin sku)')
                      ) t";
        $count_params = array_slice($params, 0, -2);
        $total = (int) ($count_params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $count_params))
            : $wpdb->get_var($count_sql));

        wp_send_json_success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }

    public function ajax_get_sku_detail() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        if ($sku === '' && $producto_base_id <= 0) {
            wp_send_json_error(['message' => 'SKU o producto requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        if ($producto_base_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT cb.*, pb.canonical_sku, pb.nombre_canonico,
                        e.tipo_envase, e.cantidad_unidades AS envase_cantidad, e.sku_envase
                 FROM {$prefix}codigo_barra cb
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                 LEFT JOIN {$prefix}envases e ON e.id = cb.envase_id
                 WHERE cb.producto_base_id = %d
                 ORDER BY
                    CASE cb.estado WHEN 'verificado' THEN 0 WHEN 'propuesto' THEN 1 ELSE 2 END,
                    cb.updated_at DESC, cb.id DESC",
                $producto_base_id
            ), ARRAY_A) ?: [];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT cb.*, pb.canonical_sku, pb.nombre_canonico,
                        e.tipo_envase, e.cantidad_unidades AS envase_cantidad, e.sku_envase
                 FROM {$prefix}codigo_barra cb
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
                 LEFT JOIN {$prefix}envases e ON e.id = cb.envase_id
                 WHERE cb.sku_local = %s OR cb.pending_sku = %s OR pb.canonical_sku = %s
                 ORDER BY
                    CASE cb.estado WHEN 'verificado' THEN 0 WHEN 'propuesto' THEN 1 ELSE 2 END,
                    cb.updated_at DESC, cb.id DESC",
                $sku,
                $sku,
                $sku
            ), ARRAY_A) ?: [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format_row($row, true);
        }

        wp_send_json_success(['sku' => $sku, 'items' => $items]);
    }

    public function ajax_upsert() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = absint($_POST['codigo_id'] ?? 0);
        $codigo = sanitize_text_field($_POST['codigo'] ?? '');
        $sku_local = sanitize_text_field($_POST['sku_local'] ?? '');
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $envase_id = absint($_POST['envase_id'] ?? 0);
        $tipo_envase = sanitize_key($_POST['tipo_envase'] ?? 'envase');
        $cantidad = floatval($_POST['cantidad'] ?? 1);
        if ($cantidad <= 0) {
            $cantidad = 1;
        }
        $estado = sanitize_key($_POST['estado'] ?? 'propuesto');
        if (!in_array($estado, ['propuesto', 'verificado', 'en_desuso', 'rechazado'], true)) {
            $estado = 'propuesto';
        }
        $create_envase = !empty($_POST['create_envase']);

        if ($codigo === '' && !$id) {
            wp_send_json_error(['message' => 'Código requerido']);
        }

        $pb = null;
        if ($producto_base_id > 0) {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, unidad_base FROM {$prefix}producto_base WHERE id = %d",
                $producto_base_id
            ), ARRAY_A);
        } elseif ($sku_local !== '') {
            $pb = $wpdb->get_row($wpdb->prepare(
                "SELECT id, canonical_sku, unidad_base FROM {$prefix}producto_base
                 WHERE canonical_sku = %s LIMIT 1",
                $sku_local
            ), ARRAY_A);
        }
        if ($pb) {
            $producto_base_id = intval($pb['id']);
            if ($sku_local === '') {
                $sku_local = $pb['canonical_sku'];
            }
        }

        if ($estado === 'verificado' && !$pb) {
            wp_send_json_error(['message' => 'No se puede verificar sin producto_base.']);
        }

        if ($create_envase && $pb) {
            $envase_id = $this->ensure_envase($producto_base_id, $tipo_envase, $cantidad);
        } elseif (!$envase_id && $pb) {
            $envase_id = $this->find_envase($producto_base_id, $tipo_envase, $cantidad);
        }

        $payload = [
            'sku_local' => $sku_local !== '' ? $sku_local : null,
            'pending_sku' => $pb ? null : ($sku_local !== '' ? $sku_local : null),
            'producto_base_id' => $pb ? $producto_base_id : null,
            'cantidad' => $cantidad,
            'factor_a_unidad_base' => $cantidad,
            'unidad_medida' => $pb['unidad_base'] ?? 'unidad',
            'envase_id' => $envase_id ?: null,
            'origen_datos' => 'manual',
            'conflicto' => 0,
            'requires_human_review' => $estado === 'propuesto' ? 1 : 0,
            'activo' => in_array($estado, ['propuesto', 'verificado', 'en_desuso'], true) ? 1 : 0,
        ];

        if ($id) {
            if ($codigo !== '') {
                $payload['codigo'] = $codigo;
            }
            $ok = Riverso_Barcode_Model::update($id, $payload);
            if (!$ok) {
                wp_send_json_error(['message' => 'No se pudo actualizar']);
            }
            Riverso_Barcode_Model::set_status($id, $estado, sanitize_text_field($_POST['motivo'] ?? 'Actualizado desde wp-admin.'));
        } else {
            $existing = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}codigo_barra WHERE codigo = %s ORDER BY id DESC LIMIT 1",
                $codigo
            ));
            if ($existing) {
                $payload['codigo'] = $codigo;
                Riverso_Barcode_Model::update($existing, $payload);
                Riverso_Barcode_Model::set_status($existing, $estado, sanitize_text_field($_POST['motivo'] ?? 'Actualizado desde wp-admin.'));
                $id = $existing;
            } else {
                $new_id = Riverso_Barcode_Model::create(
                    $codigo,
                    'ean13',
                    $pb ? $producto_base_id : null,
                    $cantidad,
                    $pb['unidad_base'] ?? 'unidad',
                    null,
                    $envase_id ?: null,
                    $cantidad
                );
                if (!$new_id) {
                    wp_send_json_error(['message' => 'No se pudo crear el código']);
                }
                Riverso_Barcode_Model::update($new_id, [
                    'sku_local' => $payload['sku_local'],
                    'pending_sku' => $payload['pending_sku'],
                    'origen_datos' => 'manual',
                ]);
                if ($estado !== 'verificado') {
                    Riverso_Barcode_Model::set_status($new_id, $estado, sanitize_text_field($_POST['motivo'] ?? 'Creado desde wp-admin.'));
                }
                $id = $new_id;
            }
        }

        $this->audit('barcode_upsert', $id, $payload);
        wp_send_json_success(['message' => 'Código guardado', 'id' => $id, 'envase_id' => $envase_id]);
    }

    public function ajax_create_envase() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $codigo_id = absint($_POST['codigo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $tipo_envase = sanitize_key($_POST['tipo_envase'] ?? 'envase');
        $cantidad = floatval($_POST['cantidad'] ?? 1);
        if ($cantidad <= 0) {
            $cantidad = 1;
        }
        if ($producto_base_id <= 0) {
            wp_send_json_error(['message' => 'Producto requerido']);
        }
        $envase_id = $this->ensure_envase($producto_base_id, $tipo_envase, $cantidad);
        if (!$envase_id) {
            wp_send_json_error(['message' => 'No se pudo crear el envase']);
        }
        if ($codigo_id) {
            Riverso_Barcode_Model::update($codigo_id, [
                'envase_id' => $envase_id,
                'cantidad' => $cantidad,
                'factor_a_unidad_base' => $cantidad,
            ]);
        }
        $this->audit('barcode_envase_linked', $codigo_id, [
            'envase_id' => $envase_id,
            'tipo_envase' => $tipo_envase,
            'cantidad' => $cantidad,
        ]);
        wp_send_json_success(['envase_id' => $envase_id, 'message' => 'Envase vinculado']);
    }

    public function ajax_envase_tipos_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_view()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_envase_tipos';
        $items = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY orden ASC, nombre ASC",
            ARRAY_A
        ) ?: [];
        wp_send_json_success(['items' => $items]);
    }

    public function ajax_envase_tipo_create() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'riverso_envase_tipos';
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $slug = sanitize_key($_POST['slug'] ?? '');
        if ($nombre === '') {
            wp_send_json_error(['message' => 'Nombre requerido']);
        }
        if ($slug === '') {
            $slug = sanitize_key($nombre);
        }
        if ($slug === '') {
            wp_send_json_error(['message' => 'Slug inválido']);
        }
        $ok = $wpdb->insert($table, [
            'slug' => $slug,
            'nombre' => $nombre,
            'descripcion' => sanitize_text_field($_POST['descripcion'] ?? ''),
            'activo' => 1,
            'orden' => absint($_POST['orden'] ?? 40),
        ]);
        if (!$ok) {
            wp_send_json_error(['message' => 'No se pudo crear (¿slug duplicado?)']);
        }
        wp_send_json_success(['id' => (int) $wpdb->insert_id, 'slug' => $slug]);
    }

    public function ajax_envase_tipo_toggle() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_manage()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $activo = !empty($_POST['activo']) ? 1 : 0;
        $table = $wpdb->prefix . 'riverso_envase_tipos';
        $wpdb->update($table, ['activo' => $activo], ['id' => $id]);
        wp_send_json_success(['id' => $id, 'activo' => $activo]);
    }

    public function on_product_created($payload, $context = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = intval($payload['producto_base_id'] ?? $payload['id'] ?? 0);
        $sku = sanitize_text_field($payload['canonical_sku'] ?? '');
        if ($id <= 0 || $sku === '') {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$prefix}codigo_barra
             SET producto_base_id = %d, pending_sku = NULL,
                 motivo_estado = CONCAT(COALESCE(motivo_estado, ''), ' | producto creado')
             WHERE estado = 'propuesto' AND producto_base_id IS NULL
               AND (pending_sku = %s OR sku_local = %s)",
            $id,
            $sku,
            $sku
        ));
    }

    private function list_groups($mode) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $where = $mode === 'conflict'
            ? "cb.conflicto = 1 AND cb.estado = 'propuesto'"
            : "cb.estado = 'propuesto' AND cb.activo = 1";

        $rows = $wpdb->get_results(
            "SELECT cb.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}codigo_barra cb
             LEFT JOIN {$prefix}producto_base pb ON pb.id = cb.producto_base_id
             WHERE {$where}
             ORDER BY cb.codigo ASC, cb.id ASC
             LIMIT 400",
            ARRAY_A
        ) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $code = $row['codigo'];
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'codigo' => $code,
                    'conflicto' => false,
                    'items' => [],
                ];
            }
            $groups[$code]['conflicto'] = $groups[$code]['conflicto'] || intval($row['conflicto']) === 1;
            $groups[$code]['items'][] = $this->format_row($row);
        }
        return array_values($groups);
    }

    private function list_shared_sku() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT sku_local, COUNT(DISTINCT codigo) AS barcodes, GROUP_CONCAT(DISTINCT codigo ORDER BY codigo SEPARATOR ',') AS codigos
             FROM {$prefix}codigo_barra
             WHERE estado IN ('propuesto','verificado') AND activo = 1
               AND sku_local IS NOT NULL AND sku_local <> ''
             GROUP BY sku_local
             HAVING COUNT(DISTINCT codigo) > 1
             ORDER BY barcodes DESC
             LIMIT 80",
            ARRAY_A
        ) ?: [];
        return $rows;
    }

    private function list_legacy_shared_sku() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        return $wpdb->get_results(
            "SELECT sku AS sku_local, COUNT(DISTINCT barcode) AS barcodes,
                    GROUP_CONCAT(DISTINCT barcode ORDER BY barcode SEPARATOR ',') AS codigos
             FROM {$prefix}tienda_local_barcodes
             WHERE sku IS NOT NULL AND sku <> ''
             GROUP BY sku
             HAVING COUNT(DISTINCT barcode) > 1
             ORDER BY barcodes DESC
             LIMIT 80",
            ARRAY_A
        ) ?: [];
    }

    private function list_legacy_pending() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $rows = $wpdb->get_results(
            "SELECT b.barcode AS codigo, b.sku AS sku_local, p.nombre
             FROM {$prefix}tienda_local_barcodes b
             LEFT JOIN {$prefix}tienda_local_productos p ON p.sku = b.sku
             LEFT JOIN {$prefix}codigo_barra cb
               ON cb.codigo = b.barcode AND cb.estado = 'verificado'
             WHERE cb.id IS NULL
             ORDER BY b.sku ASC, b.barcode ASC
             LIMIT 200",
            ARRAY_A
        ) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $code = $row['codigo'];
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'codigo' => $code,
                    'conflicto' => false,
                    'items' => [],
                ];
            }
            $groups[$code]['items'][] = [
                'id' => 0,
                'codigo' => $code,
                'estado' => 'propuesto',
                'origen' => 'legacy_tienda_local',
                'producto_base_id' => null,
                'canonical_sku' => null,
                'nombre_canonico' => $row['nombre'] ?? null,
                'sku_local' => $row['sku_local'] ?? null,
                'pending_sku' => $row['sku_local'] ?? null,
                'motivo' => 'Aún no importado al mapeo propio.',
                'conflicto' => false,
                'legacy_ref' => [],
            ];
        }
        return array_values($groups);
    }

    private function format_row($row, $with_envase = false) {
        $legacy_ref = [];
        if (!empty($row['legacy_ref'])) {
            $decoded = json_decode($row['legacy_ref'], true);
            if (is_array($decoded)) {
                $legacy_ref = $decoded;
            }
        }
        $item = [
            'id' => intval($row['id'] ?? 0),
            'codigo' => $row['codigo'] ?? '',
            'estado' => $row['estado'] ?? '',
            'origen' => $row['origen_datos'] ?? '',
            'producto_base_id' => !empty($row['producto_base_id']) ? intval($row['producto_base_id']) : null,
            'canonical_sku' => $row['canonical_sku'] ?? null,
            'nombre_canonico' => $row['nombre_canonico'] ?? null,
            'sku_local' => $row['sku_local'] ?? null,
            'pending_sku' => $row['pending_sku'] ?? null,
            'motivo' => $row['motivo_estado'] ?? '',
            'conflicto' => intval($row['conflicto'] ?? 0) === 1,
            'legacy_ref' => $legacy_ref,
            'cantidad' => floatval($row['cantidad'] ?? 1),
            'envase_id' => !empty($row['envase_id']) ? intval($row['envase_id']) : null,
            'updated_at' => $row['updated_at'] ?? $row['estado_at'] ?? $row['created_at'] ?? null,
        ];
        if ($with_envase) {
            $item['tipo_envase'] = $row['tipo_envase'] ?? null;
            $item['envase_cantidad'] = isset($row['envase_cantidad']) ? floatval($row['envase_cantidad']) : null;
            $item['sku_envase'] = $row['sku_envase'] ?? null;
        }
        return $item;
    }

    private function find_envase($producto_base_id, $tipo_envase, $cantidad) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}envases
             WHERE producto_base_id = %d AND activo = 1
               AND tipo_envase = %s AND cantidad_unidades = %f
             ORDER BY id ASC LIMIT 1",
            $producto_base_id,
            $tipo_envase,
            $cantidad
        ));
        return $id ? intval($id) : 0;
    }

    private function ensure_envase($producto_base_id, $tipo_envase, $cantidad) {
        $existing = $this->find_envase($producto_base_id, $tipo_envase, $cantidad);
        if ($existing) {
            return $existing;
        }
        $file = RIVERSO_POS_PLUGIN_DIR . 'modules/packaging/class-packaging-module.php';
        if (file_exists($file)) {
            require_once $file;
        }
        if (!class_exists('Riverso_Packaging_Module')) {
            return 0;
        }
        $created = Riverso_Packaging_Module::get_instance()->create_envase(
            $producto_base_id,
            $cantidad,
            '',
            0,
            [
                'tipo_envase' => $tipo_envase,
                'origen_datos' => 'barcode_mapping',
            ]
        );
        if (is_wp_error($created)) {
            return 0;
        }
        return intval($created);
    }

    private function audit($action, $id, $data) {
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($action, 'codigo_barra', intval($id), [
                'new_value' => $data,
                'actor_type' => 'human',
            ]);
        }
    }
}
