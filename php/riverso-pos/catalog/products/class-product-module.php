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
    }

    public function list_products($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $status = sanitize_text_field($args['status'] ?? 'active');
        $search = sanitize_text_field($args['search'] ?? '');
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
            $where[] = '(pb.canonical_sku LIKE %s OR pb.nombre_canonico LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT pb.*,
                       COUNT(DISTINCT pp.id) AS proveedores_count,
                       COUNT(DISTINCT em.id) AS equivalencias_count
                FROM {$prefix}producto_base pb
                LEFT JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.activo = 1
                LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                WHERE {$where_sql}
                GROUP BY pb.id
                ORDER BY pb.updated_at DESC, pb.id DESC
                LIMIT %d";
        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
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

        return $product;
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

        return $this->get_product($id);
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
        wp_send_json_success([
            'items' => $this->list_products([
                'status' => sanitize_text_field($_POST['status'] ?? 'active'),
                'search' => sanitize_text_field($_POST['search'] ?? ''),
                'limit' => intval($_POST['limit'] ?? 50),
            ]),
        ]);
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
}
