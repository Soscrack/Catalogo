<?php
/**
 * Módulo de gestión del dominio canónico de productos.
 *
 * Este módulo administra `producto_base` como fuente interna de verdad. No borra
 * físicamente datos: usa `deleted_at` y `archived_at`.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Product_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function create_tables() {
        return true;
    }

    public function init() {
        add_action('wp_ajax_riverso_products_list', [$this, 'ajax_list']);
        add_action('wp_ajax_riverso_products_get', [$this, 'ajax_get']);
        add_action('wp_ajax_riverso_products_save', [$this, 'ajax_save']);
        add_action('wp_ajax_riverso_products_archive', [$this, 'ajax_archive']);
        add_action('wp_ajax_riverso_products_restore', [$this, 'ajax_restore']);
        add_action('wp_ajax_riverso_products_soft_delete', [$this, 'ajax_soft_delete']);
        add_action('wp_ajax_riverso_products_approve_gate', [$this, 'ajax_approve_gate']);
        add_action('wp_ajax_riverso_products_search_code', [$this, 'ajax_search_code']);
        add_action('wp_ajax_riverso_products_link_supplier', [$this, 'ajax_link_supplier']);
        add_action('wp_ajax_riverso_products_search_woo', [$this, 'ajax_search_woo']);
        add_action('wp_ajax_riverso_products_set_online', [$this, 'ajax_set_online']);
        add_action('wp_ajax_riverso_products_get_barcodes', [$this, 'ajax_get_barcodes']);
        add_action('wp_ajax_riverso_products_add_barcode', [$this, 'ajax_add_barcode']);
        add_action('wp_ajax_riverso_products_remove_barcode', [$this, 'ajax_remove_barcode']);
        add_action('wp_ajax_riverso_products_get_tasks', [$this, 'ajax_get_tasks']);
        add_action('wp_ajax_riverso_products_create_online', [$this, 'ajax_create_online']);
    }

    private function get_completeness_category($product) {
        $has_local = !empty($product['canonical_sku']) && !empty($product['nombre_canonico']);
        $has_online = !empty($product['woocommerce_product_id']) || 
                     (!empty($product['match_estado_online']) && $product['match_estado_online'] === 'CONFIRMED');
        $has_codigo = (int) ($product['proveedores_count'] ?? 0) > 0;
        $is_published = !empty($product['publication_stage']) && 
                       in_array($product['publication_stage'], ['approved_for_publication', 'published'], true);

        if (!$has_local && $has_online) {
            return $is_published ? 'solo_online_publicado' : 'solo_online';
        }
        if ($has_local && !$has_online) {
            return 'falta_online';
        }
        if ($has_online && !$has_codigo) {
            return 'falta_codigo';
        }
        if ($has_local && $has_online && $has_codigo) {
            return $is_published ? 'publicado' : 'completo';
        }
        return 'incompleto';
    }

    public function list_products($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $status = sanitize_text_field($args['status'] ?? 'active');
        $search = sanitize_text_field($args['search'] ?? '');
        $completeness = sanitize_text_field($args['completeness'] ?? 'todos');
        $offset = intval($args['offset'] ?? 0);
        $limit = min(200, max(1, intval($args['limit'] ?? 50)));

        $where = [];
        $params = [];

        if ($status === 'archived') {
            $where[] = 'pb.archived_at IS NOT NULL AND pb.deleted_at IS NULL';
        } elseif ($status === 'deleted') {
            $where[] = 'pb.deleted_at IS NOT NULL';
        } else {
            $where[] = 'pb.archived_at IS NULL AND pb.deleted_at IS NULL';
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s OR pp.codigo_proveedor LIKE %s OR cb.codigo LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        
        // Agregar filtro de completeness si corresponde
        $having = '';
        if ($completeness !== '' && $completeness !== 'todos') {
            // Usar CASE WHEN para determinar la categoría y filtrar en HAVING
            $having = $this->get_completeness_having_clause($completeness);
        }

        $sql = "SELECT pb.*,
                       COUNT(DISTINCT pp.id) AS proveedores_count,
                       COUNT(DISTINCT em.id) AS equivalencias_count,
                       GROUP_CONCAT(DISTINCT pp.codigo_proveedor SEPARATOR ', ') AS codigos_proveedor
                FROM {$prefix}producto_base pb
                LEFT JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.activo = 1
                LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                LEFT JOIN {$prefix}codigo_barra cb ON cb.producto_base_id = pb.id
                WHERE {$where_sql}
                GROUP BY pb.id
                {$having}
                ORDER BY pb.updated_at DESC, pb.id DESC
                LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        
        // Calcular completeness_category y total
        foreach ($results as &$item) {
            $item['completeness_category'] = $this->get_completeness_category($item);
        }

        // Obtener total sin LIMIT/OFFSET para paginación
        $count_sql = "SELECT COUNT(DISTINCT pb.id) as total
                      FROM {$prefix}producto_base pb
                      LEFT JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.activo = 1
                      LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                      LEFT JOIN {$prefix}codigo_barra cb ON cb.producto_base_id = pb.id
                      WHERE {$where_sql}
                      {$having}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        return [
            'items' => array_values($results),
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
        ];
    }

    /**
     * Genera cláusula HAVING para filtro de completeness
     */
    private function get_completeness_having_clause($completeness) {
        switch ($completeness) {
            case 'completo':
                return "HAVING MAX(CASE WHEN pb.canonical_sku != '' AND pb.nombre_canonico != '' AND pb.woocommerce_product_id IS NOT NULL AND COUNT(DISTINCT pp.id) > 0 THEN 1 ELSE 0 END) = 1";
            case 'publicado':
                return "HAVING MAX(CASE WHEN pb.canonical_sku != '' AND pb.nombre_canonico != '' AND pb.woocommerce_product_id IS NOT NULL AND COUNT(DISTINCT pp.id) > 0 AND pb.publication_stage IN ('approved_for_publication', 'published') THEN 1 ELSE 0 END) = 1";
            case 'falta_online':
                return "HAVING MAX(CASE WHEN pb.canonical_sku != '' AND pb.nombre_canonico != '' AND pb.woocommerce_product_id IS NULL THEN 1 ELSE 0 END) = 1";
            case 'falta_codigo':
                return "HAVING MAX(CASE WHEN pb.canonical_sku != '' AND pb.nombre_canonico != '' AND pb.woocommerce_product_id IS NOT NULL AND COUNT(DISTINCT pp.id) = 0 THEN 1 ELSE 0 END) = 1";
            case 'solo_online':
                return "HAVING MAX(CASE WHEN (pb.canonical_sku = '' OR pb.nombre_canonico = '') AND pb.woocommerce_product_id IS NOT NULL THEN 1 ELSE 0 END) = 1";
            case 'solo_online_publicado':
                return "HAVING MAX(CASE WHEN (pb.canonical_sku = '' OR pb.nombre_canonico = '') AND pb.woocommerce_product_id IS NOT NULL AND pb.publication_stage IN ('approved_for_publication', 'published') THEN 1 ELSE 0 END) = 1";
            case 'incompleto':
                return "HAVING MAX(CASE WHEN (pb.canonical_sku = '' OR pb.nombre_canonico = '') THEN 1 ELSE 0 END) = 1";
            default:
                return '';
        }
    }

    public function get_product($id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = absint($id);

        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!$product) {
            return null;
        }

        $product['proveedores'] = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.*, p.nombre AS proveedor_nombre
             FROM {$prefix}producto_proveedor pp
             LEFT JOIN {$prefix}proveedores p ON p.id = pp.proveedor_id
             WHERE pp.producto_base_id = %d
             ORDER BY pp.es_preferido DESC, pp.id DESC",
            $id
        ), ARRAY_A);

        $product['barcodes'] = $this->get_product_barcodes($id);
        $product['tasks'] = $this->get_product_tasks($id);
        $product['completeness_category'] = $this->get_completeness_category($product);
        $product['proveedores_count'] = count($product['proveedores']);

        return $product;
    }

    private function get_product_barcodes($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $barcodes = [];
        if (class_exists('Riverso_Barcode_Model')) {
            $barcodes = Riverso_Barcode_Model::get_by_product($product_id);
        }
        return is_array($barcodes) ? $barcodes : [];
    }

    private function get_product_tasks($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, tipo, titulo, estado, prioridad, fecha_limite
             FROM {$prefix}tareas
             WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
             AND estado IN ('pendiente', 'asignado')
             ORDER BY prioridad DESC, id DESC
             LIMIT 10",
            $product_id
        ), ARRAY_A) ?: [];
    }

    public function save_product($data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $table = "{$prefix}producto_base";

        $id = absint($data['id'] ?? 0);
        $old = $id ? $this->get_product($id) : null;
        $now = current_time('mysql');

        $payload = [
            'canonical_sku' => sanitize_text_field($data['canonical_sku'] ?? ''),
            'nombre_canonico' => sanitize_text_field($data['nombre_canonico'] ?? ''),
            'unidad_base' => sanitize_text_field($data['unidad_base'] ?? 'unidad'),
            'permite_decimal' => !empty($data['permite_decimal']) ? 1 : 0,
            'permite_ean13_personalizado' => !empty($data['permite_ean13_personalizado']) ? 1 : 0,
            'stock_abierto_habilitado' => !empty($data['stock_abierto_habilitado']) ? 1 : 0,
            'codigo_abierto' => sanitize_text_field($data['codigo_abierto'] ?? ''),
            'estado' => sanitize_text_field($data['estado'] ?? 'activo'),
            'requires_human_review' => !empty($data['requires_human_review']) ? 1 : 0,
        ];

        if ($payload['canonical_sku'] === '') {
            return new WP_Error('missing_sku', 'SKU canónico requerido');
        }
        if ($payload['nombre_canonico'] === '') {
            return new WP_Error('missing_name', 'Nombre canónico requerido');
        }
        if ($payload['codigo_abierto'] === '') {
            $payload['codigo_abierto'] = null;
        }

        if ($id) {
            $payload['updated_at'] = $now;
            $result = $wpdb->update($table, $payload, ['id' => $id]);
            $action = 'product_updated';
        } else {
            $payload['created_by_system'] = 0;
            $payload['review_status'] = 'pendiente';
            $payload['publication_stage'] = 'human_verified';
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            $result = $wpdb->insert($table, $payload);
            $id = (int) $wpdb->insert_id;
            $action = 'product_created';
        }

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error ?: 'Error guardando producto');
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($action, 'producto_base', $id, [
                'actor_type' => 'human',
                'old_value' => $old,
                'new_value' => $this->get_product($id),
            ]);
        }

        $product = $this->get_product($id);
        $this->trigger_counterpart_tasks($id);

        return $product;
    }

    public function set_lifecycle($id, $action) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $id = absint($id);
        $old = $this->get_product($id);
        if (!$old) {
            return new WP_Error('not_found', 'Producto no encontrado');
        }

        $now = current_time('mysql');
        $payload = ['updated_at' => $now];
        $audit_action = 'product_updated';

        if ($action === 'archive') {
            $payload['archived_at'] = $now;
            $payload['estado'] = 'archivado';
            $audit_action = 'product_archived';
        } elseif ($action === 'delete') {
            $payload['deleted_at'] = $now;
            $payload['estado'] = 'eliminado';
            $audit_action = 'product_deleted';
        } elseif ($action === 'restore') {
            $payload['archived_at'] = null;
            $payload['deleted_at'] = null;
            $payload['estado'] = 'activo';
            $audit_action = 'product_restored';
        } else {
            return new WP_Error('invalid_action', 'Acción de ciclo de vida inválida');
        }

        $wpdb->update("{$prefix}producto_base", $payload, ['id' => $id]);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log($audit_action, 'producto_base', $id, [
                'actor_type' => 'human',
                'old_value' => $old,
                'new_value' => $this->get_product($id),
            ]);
        }

        return $this->get_product($id);
    }

    public function approve_gate($id, $gate, $status = 'approved') {
        global $wpdb;
        $allowed = ['human_product_review', 'human_price_review', 'human_category_review', 'human_attribute_review'];
        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'approved';
        if (!in_array($gate, $allowed, true)) {
            return new WP_Error('invalid_gate', 'Gate inválido');
        }

        $prefix = $wpdb->prefix . 'riverso_';
        $id = absint($id);
        $old = $this->get_product($id);
        if (!$old) {
            return new WP_Error('not_found', 'Producto no encontrado');
        }

        $wpdb->update(
            "{$prefix}producto_base",
            [$gate => $status, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_updated', 'producto_base', $id, [
                'actor_type' => 'human',
                'old_value' => [$gate => $old[$gate] ?? null],
                'new_value' => [$gate => $status],
                'details' => 'Aprobación humana de gate de publicación',
            ]);
        }

        return $this->get_product($id);
    }

    public function ajax_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->list_products([
            'status' => sanitize_text_field($_POST['status'] ?? 'active'),
            'search' => sanitize_text_field($_POST['search'] ?? ''),
            'completeness' => sanitize_text_field($_POST['completeness'] ?? 'todos'),
            'offset' => intval($_POST['offset'] ?? 0),
            'limit' => intval($_POST['limit'] ?? 50),
        ]);
        wp_send_json_success($result);
    }

    public function ajax_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $item = $this->get_product(absint($_POST['id'] ?? 0));
        if (!$item) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        wp_send_json_success(['item' => $item]);
    }

    public function ajax_save() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->save_product($_POST);
        $this->send_result($result, 'Producto guardado');
    }

    public function ajax_archive() {
        $this->ajax_lifecycle('archive');
    }

    public function ajax_restore() {
        $this->ajax_lifecycle('restore');
    }

    public function ajax_soft_delete() {
        $this->ajax_lifecycle('delete');
    }

    private function ajax_lifecycle($action) {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->set_lifecycle(absint($_POST['id'] ?? 0), $action);
        $this->send_result($result, 'Estado actualizado');
    }

    public function ajax_approve_gate() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_review_products') && !current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->approve_gate(
            absint($_POST['id'] ?? 0),
            sanitize_text_field($_POST['gate'] ?? ''),
            sanitize_text_field($_POST['status'] ?? 'approved')
        );
        $this->send_result($result, 'Gate actualizado');
    }

    private function send_result($result, $message) {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => $message, 'item' => $result]);
    }

    public function ajax_search_code() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $code = sanitize_text_field($_POST['code'] ?? '');
        if (empty($code)) {
            wp_send_json_error(['message' => 'Código requerido']);
        }

        if (!class_exists('Riverso_Supplier_Links_Module')) {
            wp_send_json_error(['message' => 'Módulo de proveedores no disponible']);
        }

        $links = Riverso_Supplier_Links_Module::get_instance()->lookup_by_code($code);
        wp_send_json_success([
            'results' => is_array($links) ? $links : (is_wp_error($links) ? [] : [$links]),
        ]);
    }

    public function ajax_link_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $supplier_code = sanitize_text_field($_POST['supplier_code'] ?? '');
        $supplier_id = absint($_POST['supplier_id'] ?? 0);
        $audit_reason = sanitize_textarea_field($_POST['audit_reason'] ?? '');

        if (!$product_id || !$supplier_code || !$supplier_id) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        if (!class_exists('Riverso_Supplier_Links_Module')) {
            wp_send_json_error(['message' => 'Módulo de proveedores no disponible']);
        }

        $result = Riverso_Supplier_Links_Module::get_instance()->create_link([
            'proveedor_id' => $supplier_id,
            'codigo_proveedor' => $supplier_code,
            'producto_base_id' => $product_id,
            'audit_reason' => $audit_reason,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $this->close_counterpart_task($product_id, 'relacionar_producto_proveedor');
        $product = $this->get_product($product_id);
        $this->trigger_counterpart_tasks($product_id);

        wp_send_json_success(['message' => 'Código proveedor vinculado', 'item' => $product]);
    }

    public function ajax_search_woo() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        $search = sanitize_text_field($_POST['s'] ?? '');
        if (strlen($search) < 2) {
            wp_send_json_success(['results' => []]);
        }

        if (!function_exists('wc_get_products')) {
            wp_send_json_success(['results' => []]);
        }

        $products = wc_get_products([
            's' => $search,
            'limit' => 20,
            'return' => 'objects',
        ]);

        $results = [];
        foreach ($products as $wc_product) {
            $results[] = [
                'id' => $wc_product->get_id(),
                'name' => $wc_product->get_name(),
                'sku' => $wc_product->get_sku(),
                'type' => $wc_product->get_type(),
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_set_online() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $woo_id = absint($_POST['woo_id'] ?? 0);

        if (!$product_id || !$woo_id) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $result = $wpdb->update(
            "{$prefix}producto_base",
            [
                'woocommerce_product_id' => $woo_id,
                'match_estado_online' => 'CONFIRMED',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $product_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => $wpdb->last_error ?: 'Error guardando vínculo Woo']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_online_linked', 'producto_base', $product_id, [
                'actor_type' => 'human',
                'woocommerce_product_id' => $woo_id,
            ]);
        }

        $this->close_counterpart_task($product_id, 'crear_contraparte_online');
        $product = $this->get_product($product_id);
        $this->trigger_counterpart_tasks($product_id);

        wp_send_json_success(['message' => 'Producto online vinculado', 'item' => $product]);
    }

    public function ajax_get_barcodes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error(['message' => 'ID de producto requerido']);
        }

        $barcodes = $this->get_product_barcodes($product_id);
        wp_send_json_success(['barcodes' => $barcodes]);
    }

    public function ajax_add_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $barcode = sanitize_text_field($_POST['barcode'] ?? '');
        $audit_reason = sanitize_textarea_field($_POST['audit_reason'] ?? '');

        if (!$product_id || !$barcode) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        if (!class_exists('Riverso_Barcode_Model')) {
            wp_send_json_error(['message' => 'Módulo de barcodes no disponible']);
        }

        $result = Riverso_Barcode_Model::create($barcode, 'ean13', $product_id, 1, 'unidad');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('barcode_assigned', 'codigo_barra', (int) ($result['id'] ?? 0), [
                'actor_type' => 'human',
                'producto_base_id' => $product_id,
                'codigo' => $barcode,
                'razon' => $audit_reason,
            ]);
        }

        wp_send_json_success(['message' => 'Código de barra agregado', 'barcode' => $result]);
    }

    public function ajax_remove_barcode() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $barcode_code = sanitize_text_field($_POST['barcode'] ?? '');
        $audit_reason = sanitize_textarea_field($_POST['audit_reason'] ?? '');

        if (!$product_id || !$barcode_code) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $barcode = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$prefix}codigo_barra WHERE codigo = %s AND producto_base_id = %d",
            $barcode_code,
            $product_id
        ));

        if (!$barcode) {
            wp_send_json_error(['message' => 'Código de barra no encontrado']);
        }

        $result = $wpdb->update(
            "{$prefix}codigo_barra",
            [
                'estado' => 'en_desuso',
                'motivo_estado' => $audit_reason ?: 'Removido por usuario',
            ],
            ['id' => (int) $barcode->id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Error removiendo código de barra']);
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('barcode_removed', 'codigo_barra', (int) $barcode->id, [
                'actor_type' => 'human',
                'producto_base_id' => $product_id,
                'codigo' => $barcode_code,
                'razon' => $audit_reason,
            ]);
        }

        wp_send_json_success(['message' => 'Código de barra marcado como en desuso']);
    }

    public function ajax_get_tasks() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error(['message' => 'ID de producto requerido']);
        }

        $tasks = $this->get_product_tasks($product_id);
        wp_send_json_success(['tasks' => $tasks]);
    }

    /**
     * Disparar tareas de contraparte cuando se crea/actualiza un producto
     */
    public function trigger_counterpart_tasks($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $product = $this->get_product($product_id);
        if (!$product) {
            return;
        }

        $has_local = !empty($product['canonical_sku']) && !empty($product['nombre_canonico']);
        $has_online = !empty($product['woocommerce_product_id']) || 
                     (!empty($product['match_estado_online']) && $product['match_estado_online'] === 'CONFIRMED');
        $has_codigo = (int) ($product['proveedores_count'] ?? 0) > 0;
        $match_online_unmatched = !empty($product['match_estado_online']) && $product['match_estado_online'] === 'UNMATCHED';

        if (class_exists('Riverso_Task_Module')) {
            $task_module = Riverso_Task_Module::get_instance();

            // Si es local sin online confirmada, crear tarea
            if ($has_local && !$has_online && $match_online_unmatched) {
                $task_module->create_review_task(
                    'crear_contraparte_online',
                    sprintf('Crear o asignar contraparte online para "%s"', $product['nombre_canonico']),
                    'producto_base',
                    $product_id,
                    [
                        'descripcion' => sprintf(
                            'Producto local "%s" (SKU %s) sin vínculo WooCommerce. Crear nuevo producto online o asignar uno existente.',
                            $product['nombre_canonico'],
                            $product['canonical_sku']
                        ),
                        'prioridad' => 'normal',
                    ]
                );
            }

            // Si es local+online sin código, crear tarea
            if ($has_local && $has_online && !$has_codigo) {
                $task_module->create_review_task(
                    'relacionar_producto_proveedor',
                    sprintf('Asignar código proveedor a "%s"', $product['nombre_canonico']),
                    'producto_base',
                    $product_id,
                    [
                        'descripcion' => sprintf(
                            'Producto "%s" (SKU %s) ya tiene contraparte online, pero falta código proveedor.',
                            $product['nombre_canonico'],
                            $product['canonical_sku']
                        ),
                        'prioridad' => 'normal',
                    ]
                );
            }
        }
    }

    /**
     * Cerrar/marcar tareas completadas al vincular
     */
    public function close_counterpart_task($product_id, $task_tipo) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->update(
            "{$prefix}tareas",
            ['estado' => 'completada'],
            [
                'referencia_tipo' => 'producto_base',
                'referencia_id' => (int) $product_id,
                'tipo' => $task_tipo,
            ],
            ['%s'],
            ['%s', '%d', '%s']
        );
    }

    public function ajax_create_online() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $product_type = sanitize_text_field($_POST['product_type'] ?? 'simple');
        $woo_name = sanitize_text_field($_POST['woo_name'] ?? '');
        $woo_sku = sanitize_text_field($_POST['woo_sku'] ?? '');

        if (!$product_id || !$woo_name || !$woo_sku) {
            wp_send_json_error(['message' => 'Datos incompletos']);
        }

        if (!class_exists('Riverso_Woo_Publisher_Module')) {
            wp_send_json_error(['message' => 'Módulo publisher no disponible']);
        }

        $publisher = Riverso_Woo_Publisher_Module::get_instance();
        
        if ($product_type === 'variable') {
            $nominal = sanitize_text_field($_POST['nominal'] ?? '');
            $largo = sanitize_text_field($_POST['largo'] ?? '');
            $result = $publisher->create_woo_variable_from_base($product_id, $woo_name, $woo_sku, $nominal, $largo);
        } else {
            $result = $publisher->create_woo_simple_from_base($product_id, $woo_name, $woo_sku);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Cerrar tarea de crear contraparte
        $this->close_counterpart_task($product_id, 'crear_contraparte_online');

        // Disparar tareas de relacionar proveedor si corresponde
        $this->trigger_counterpart_tasks($product_id);

        // Obtener producto actualizado
        $updated_product = $this->get_product($product_id);

        wp_send_json_success([
            'message' => 'Producto WooCommerce creado',
            'item' => $updated_product,
            'result' => $result,
        ]);
    }
}
