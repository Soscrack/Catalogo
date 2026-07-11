<?php
/**
 * Publicador controlado Riverso POS -> WooCommerce.
 *
 * Crea productos variables desde MAMUT en estado privado/borrador y solo publica
 * cuando las aprobaciones humanas requeridas están completas.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Woo_Publisher_Module {

    private static $instance = null;
    private static $category_path_cache = [];

    const STAGES = [
        'computer_created',
        'pending_review',
        'human_verified',
        'price_verified',
        'approved_for_publication',
        'published',
    ];

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
        add_action('wp_ajax_riverso_publish_mamut_group', [$this, 'ajax_publish_mamut_group']);
        add_action('wp_ajax_riverso_publish_authorize', [$this, 'ajax_authorize']);
        add_action('wp_ajax_riverso_publish_product', [$this, 'ajax_publish_product']);
        add_action('wp_ajax_riverso_catalog_list', [$this, 'ajax_catalog_list']);
        add_action('wp_ajax_riverso_catalog_get', [$this, 'ajax_catalog_get']);
        add_action('wp_ajax_riverso_catalog_save', [$this, 'ajax_catalog_save']);
        add_action('wp_ajax_riverso_catalog_save_attributes', [$this, 'ajax_catalog_save_attributes']);
        add_action('wp_ajax_riverso_catalog_approve_gate', [$this, 'ajax_catalog_approve_gate']);
        add_action('wp_ajax_riverso_catalog_authorize', [$this, 'ajax_catalog_authorize']);
        add_action('wp_ajax_riverso_catalog_publish', [$this, 'ajax_catalog_publish']);
        add_action('wp_ajax_riverso_catalog_search_local_sku', [$this, 'ajax_catalog_search_local_sku']);
        add_action('wp_ajax_riverso_catalog_assign_local_sku', [$this, 'ajax_catalog_assign_local_sku']);
        add_action('wp_ajax_riverso_category_tree', [$this, 'ajax_category_tree']);
        add_action('wp_ajax_riverso_category_create', [$this, 'ajax_category_create']);
        add_action('wp_ajax_riverso_category_rename', [$this, 'ajax_category_rename']);
        add_action('wp_ajax_riverso_category_delete', [$this, 'ajax_category_delete']);
        add_action('wp_ajax_riverso_catalog_codes_list', [$this, 'ajax_catalog_codes_list']);
        add_action('wp_ajax_riverso_catalog_code_unlink', [$this, 'ajax_catalog_code_unlink']);
        add_action('wp_ajax_riverso_catalog_code_link', [$this, 'ajax_catalog_code_link']);
        add_action('wp_ajax_riverso_gate_context', [$this, 'ajax_gate_context']);
        add_action('wp_ajax_riverso_catalog_search_suppliers', [$this, 'ajax_catalog_search_suppliers']);
        add_action('wp_ajax_riverso_catalog_create_supplier', [$this, 'ajax_catalog_create_supplier']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('riverso-publish mamut-group', [$this, 'cli_publish_mamut_group']);
            WP_CLI::add_command('riverso-publish mamut-all', [$this, 'cli_publish_mamut_all']);
            WP_CLI::add_command('riverso-publish backfill-categories', [$this, 'cli_backfill_categories']);
            WP_CLI::add_command('riverso-publish enqueue-local-sku-tasks', [$this, 'cli_enqueue_local_sku_tasks']);
        }
    }

    public function load_mamut_entries($path = '') {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/import/class-mamut-import-module.php';
        return Riverso_Mamut_Import_Module::get_instance()->load_entries($path);
    }

    public function group_entries($entries) {
        $groups = [];
        foreach ($entries as $entry) {
            $attrs = $this->attributes_to_map($entry['attributes'] ?? []);
            $acabado = $attrs['acabado'] ?? 'Sin acabado';
            $product_name = $entry['producto'] ?? ($entry['nombre_producto'] ?? 'Producto');
            $key = sanitize_title($product_name . '-' . $acabado);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'name' => trim($product_name . ($acabado !== 'Sin acabado' ? ' - ' . $acabado : '')),
                    'category_path' => $entry['category_path'] ?? [],
                    'acabado' => $acabado,
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = $entry;
        }
        return $groups;
    }

    public function create_mamut_group($group_key, $path = '', $status = 'private') {
        $entries = $this->load_mamut_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }
        $groups = $this->group_entries($entries);
        if (empty($groups[$group_key])) {
            return new WP_Error('group_not_found', 'Grupo MAMUT no encontrado');
        }
        return $this->create_variable_product($groups[$group_key], $status);
    }

    public function bulk_create_drafts($offset_groups = 0, $limit_groups = 20, $path = '', $status = 'private') {
        $entries = $this->load_mamut_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $groups = $this->group_entries($entries);
        ksort($groups);

        $total = count($groups);
        $offset_groups = max(0, intval($offset_groups));
        $limit_groups = max(1, intval($limit_groups));
        $slice = array_slice($groups, $offset_groups, $limit_groups, true);

        $processed = 0;
        $created_products = 0;
        $created_variations = 0;
        $skipped = 0;
        $errors = [];

        foreach ($slice as $group_key => $group) {
            $result = $this->create_variable_product($group, $status);
            if (is_wp_error($result)) {
                $errors[$group_key] = $result->get_error_message();
                $processed++;
                continue;
            }

            if (!empty($result['skipped'])) {
                $skipped++;
            } else {
                $created_products++;
            }
            $created_variations += intval($result['created_variations'] ?? 0);
            $processed++;
        }

        $next_offset = $offset_groups + $limit_groups;
        $done = $next_offset >= $total;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_import('mamut_publish_batch', 'import', 0, [
                'new_value' => [
                    'offset_groups' => $offset_groups,
                    'processed' => $processed,
                    'created_products' => $created_products,
                    'created_variations' => $created_variations,
                    'skipped' => $skipped,
                    'errors' => count($errors),
                ],
            ]);
        }

        return [
            'total_groups' => $total,
            'offset_groups' => $offset_groups,
            'processed' => $processed,
            'created_products' => $created_products,
            'created_variations' => $created_variations,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_offset' => $done ? null : $next_offset,
            'done' => $done,
        ];
    }

    public function create_variable_product($group, $status = 'private') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $status = in_array($status, ['draft', 'private'], true) ? $status : 'private';
        $existing_product_id = $this->find_existing_group_product_id($group);
        if ($existing_product_id) {
            $linked_variations = $this->sync_existing_group_links($existing_product_id, $group);
            return [
                'product_id' => $existing_product_id,
                'created_variations' => 0,
                'linked_variations' => $linked_variations,
                'group' => $group['key'],
                'skipped' => true,
            ];
        }

        $attributes = $this->build_attributes($group['items']);

        $product = new WC_Product_Variable();
        $product->set_name($group['name']);
        $product->set_status($status);
        $product->set_description('Producto creado por Riverso POS desde catálogo MAMUT. Requiere revisión humana antes de publicación.');
        $product->set_attributes($attributes['wc_attributes']);
        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('save_failed', 'No se pudo crear producto variable WooCommerce');
        }

        $created_variations = 0;
        foreach ($group['items'] as $entry) {
            $sku = trim((string) ($entry['sku'] ?? ''));
            if ($sku === '' || wc_get_product_id_by_sku($sku)) {
                continue;
            }

            $attr_map = $this->attributes_to_map($entry['attributes'] ?? []);
            $variation_attrs = $this->variation_attributes($attr_map);
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_status('publish');
            $variation->set_sku($sku);
            $variation->set_attributes($variation_attrs);
            $variation_id = $variation->save();
            if ($variation_id) {
                $created_variations++;
                $wpdb->update(
                    "{$prefix}producto_base",
                    [
                        'woocommerce_product_id' => $product_id,
                        'woocommerce_variation_id' => $variation_id,
                        'publication_stage' => 'pending_review',
                        'requires_human_review' => 1,
                        'human_attribute_review' => 'pending',
                    ],
                    ['canonical_sku' => $sku],
                    ['%d', '%d', '%s', '%d', '%s'],
                    ['%s']
                );
            }
        }

        if (!empty($attributes['default_attributes'])) {
            update_post_meta($product_id, '_default_attributes', $attributes['default_attributes']);
        }
        if (!empty($group['category_path'])) {
            $cat_result = $this->assign_product_category($product_id, $group['category_path']);
            if (is_wp_error($cat_result) && class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log_import('category_assign_failed', 'product', $product_id, [
                    'new_value' => ['error' => $cat_result->get_error_message(), 'path' => $group['category_path']],
                ]);
            }
        }
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);

        if (function_exists('riverso_create_review_task')) {
            riverso_create_review_task(
                'confirmar_estructura_atributos',
                'Confirmar estructura de atributos para ' . $group['name'],
                'product',
                $product_id,
                ['prioridad' => 'alta']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_import('product_created', 'product', $product_id, [
                'new_value' => [
                    'group_key' => $group['key'],
                    'created_variations' => $created_variations,
                    'status' => $status,
                ],
                'details' => 'Producto variable creado desde MAMUT en estado no publicado',
            ]);
        }

        return [
            'product_id' => $product_id,
            'created_variations' => $created_variations,
            'group' => $group['key'],
            'skipped' => false,
        ];
    }

    private function find_existing_group_product_id($group) {
        foreach ($group['items'] as $entry) {
            $sku = trim((string) ($entry['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    return (int) $parent_id;
                }
            }

            return (int) $product_id;
        }

        return 0;
    }

    private function sync_existing_group_links($product_id, $group) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $linked = 0;

        foreach ($group['items'] as $entry) {
            $sku = trim((string) ($entry['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $variation_id = wc_get_product_id_by_sku($sku);
            if (!$variation_id) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            if ($variation && $variation->is_type('variation') && (int) $variation->get_parent_id() === (int) $product_id) {
                $updated = $wpdb->update(
                    "{$prefix}producto_base",
                    [
                        'woocommerce_product_id' => (int) $product_id,
                        'woocommerce_variation_id' => (int) $variation_id,
                        'publication_stage' => 'pending_review',
                        'requires_human_review' => 1,
                        'human_attribute_review' => 'pending',
                    ],
                    ['canonical_sku' => $sku],
                    ['%d', '%d', '%s', '%d', '%s'],
                    ['%s']
                );
                if ($updated !== false) {
                    $linked++;
                }
            }
        }

        return $linked;
    }

    public function can_publish_base($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            absint($producto_base_id)
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }
        foreach (['human_product_review', 'human_price_review', 'human_category_review', 'human_attribute_review'] as $gate) {
            if (($row[$gate] ?? 'pending') !== 'approved') {
                return new WP_Error('gate_pending', 'Falta aprobación: ' . $gate);
            }
        }
        if (empty($row['woocommerce_product_id'])) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no vinculado');
        }
        $group_check = $this->can_publish_group((int) $row['woocommerce_product_id']);
        if (is_wp_error($group_check)) {
            return $group_check;
        }
        return $row;
    }

    public function can_publish_group($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        if (!$product_id) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no vinculado');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, human_product_review, human_price_review, human_category_review, human_attribute_review
             FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d
               AND deleted_at IS NULL
               AND archived_at IS NULL",
            $product_id
        ), ARRAY_A);

        if (empty($rows)) {
            return new WP_Error('no_group_rows', 'No hay productos base vinculados al producto WooCommerce');
        }

        $gates = ['human_product_review', 'human_price_review', 'human_category_review', 'human_attribute_review'];
        foreach ($rows as $row) {
            foreach ($gates as $gate) {
                if (($row[$gate] ?? 'pending') !== 'approved') {
                    return new WP_Error(
                        'group_gate_pending',
                        sprintf('Falta aprobación %s en producto base #%d', $gate, intval($row['id']))
                    );
                }
            }
        }

        return $rows;
    }

    public function authorize_publication($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $check = $this->can_publish_base($producto_base_id);
        if (is_wp_error($check)) {
            if (function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'autorizar_publicacion',
                    'Autorizar publicación pendiente para producto base #' . absint($producto_base_id),
                    'producto_base',
                    absint($producto_base_id),
                    ['prioridad' => 'alta']
                );
            }
            return $check;
        }
        $wpdb->update(
            "{$prefix}producto_base",
            ['publication_stage' => 'approved_for_publication', 'updated_at' => current_time('mysql')],
            ['woocommerce_product_id' => (int) $check['woocommerce_product_id']],
            ['%s', '%s'],
            ['%d']
        );
        return true;
    }

    public function publish_product($producto_base_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $row = $this->can_publish_base($producto_base_id);
        if (is_wp_error($row)) {
            return $row;
        }
        $product = wc_get_product((int) $row['woocommerce_product_id']);
        if (!$product) {
            return new WP_Error('no_wc_product', 'Producto WooCommerce no encontrado');
        }
        $old_status = $product->get_status();
        $product->set_status('publish');
        $product->save();

        $wpdb->update(
            "{$prefix}producto_base",
            ['publication_stage' => 'published', 'updated_at' => current_time('mysql')],
            ['woocommerce_product_id' => (int) $row['woocommerce_product_id']],
            ['%s', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_published', 'producto_base', absint($producto_base_id), [
                'actor_type' => 'human',
                'old_value' => ['status' => $old_status],
                'new_value' => ['status' => 'publish', 'woocommerce_product_id' => $product->get_id()],
            ]);
        }

        return true;
    }

    /**
     * Crea o resuelve la jerarquía product_cat desde category_path.
     *
     * @param array $path
     * @return int|WP_Error term_id hoja
     */
    public function ensure_category_path($path) {
        if (empty($path) || !is_array($path)) {
            return 0;
        }

        $segments = [];
        foreach ($path as $segment) {
            $segment = trim((string) $segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        if (empty($segments)) {
            return 0;
        }

        $cache_key = implode('>', $segments);
        if (isset(self::$category_path_cache[$cache_key])) {
            return self::$category_path_cache[$cache_key];
        }

        $parent = 0;
        $term_id = 0;
        foreach ($segments as $name) {
            $existing = term_exists($name, 'product_cat', $parent);
            if ($existing) {
                $term_id = is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
            } else {
                $inserted = wp_insert_term($name, 'product_cat', ['parent' => $parent]);
                if (is_wp_error($inserted)) {
                    return $inserted;
                }
                $term_id = (int) $inserted['term_id'];
            }
            $parent = $term_id;
        }

        self::$category_path_cache[$cache_key] = $term_id;
        return $term_id;
    }

    /**
     * Asigna category_path al producto WooCommerce padre.
     */
    public function assign_product_category($product_id, $path) {
        $product_id = absint($product_id);
        if (!$product_id) {
            return new WP_Error('no_product', 'Producto no válido');
        }

        $term_id = $this->ensure_category_path($path);
        if (is_wp_error($term_id)) {
            return $term_id;
        }
        if (!$term_id) {
            return new WP_Error('no_category', 'Ruta de categoría vacía');
        }

        $result = wp_set_object_terms($product_id, [(int) $term_id], 'product_cat');
        if (is_wp_error($result)) {
            return $result;
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_import('category_assigned', 'product', $product_id, [
                'new_value' => ['term_id' => $term_id, 'path' => $path],
            ]);
        }

        return (int) $term_id;
    }

    /**
     * Lee la ruta jerárquica product_cat de un producto (desde el término más profundo).
     */
    public function get_product_category_path($product_id) {
        $terms = wp_get_post_terms(absint($product_id), 'product_cat', ['orderby' => 'parent']);
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $deepest = null;
        $max_depth = -1;
        foreach ($terms as $term) {
            $ancestors = get_ancestors($term->term_id, 'product_cat');
            $depth = count($ancestors);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest = $term;
            }
        }
        if (!$deepest) {
            return [];
        }

        $path = [];
        $ancestors = array_reverse(get_ancestors($deepest->term_id, 'product_cat'));
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_term($ancestor_id, 'product_cat');
            if ($ancestor && !is_wp_error($ancestor)) {
                $path[] = $ancestor->name;
            }
        }
        $path[] = $deepest->name;
        return $path;
    }

    public function bulk_backfill_categories($offset_groups = 0, $limit_groups = 20, $path = '') {
        $entries = $this->load_mamut_entries($path);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $groups = $this->group_entries($entries);
        ksort($groups);

        $total = count($groups);
        $offset_groups = max(0, intval($offset_groups));
        $limit_groups = max(1, intval($limit_groups));
        $slice = array_slice($groups, $offset_groups, $limit_groups, true);

        $processed = 0;
        $assigned = 0;
        $skipped = 0;
        $errors = [];

        foreach ($slice as $group_key => $group) {
            $product_id = $this->find_existing_group_product_id($group);
            if (!$product_id) {
                $errors[$group_key] = 'Producto WooCommerce no encontrado';
                $processed++;
                continue;
            }
            if (empty($group['category_path'])) {
                $skipped++;
                $processed++;
                continue;
            }

            $result = $this->assign_product_category($product_id, $group['category_path']);
            if (is_wp_error($result)) {
                $errors[$group_key] = $result->get_error_message();
            } else {
                $assigned++;
            }
            $processed++;
        }

        $next_offset = $offset_groups + $limit_groups;
        $done = $next_offset >= $total;

        return [
            'total_groups' => $total,
            'offset_groups' => $offset_groups,
            'processed' => $processed,
            'assigned' => $assigned,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_offset' => $done ? null : $next_offset,
            'done' => $done,
        ];
    }

    public function list_catalog_products($args = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $search = sanitize_text_field($args['search'] ?? '');
        $limit = min(100, max(1, intval($args['limit'] ?? 50)));
        $offset = max(0, intval($args['offset'] ?? 0));

        $where = 'pb.woocommerce_product_id IS NOT NULL AND pb.deleted_at IS NULL AND pb.archived_at IS NULL';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (p.post_title LIKE %s OR pb.canonical_sku LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT pb.woocommerce_product_id AS product_id,
                       MAX(p.post_title) AS name,
                       MAX(p.post_status) AS status,
                       COUNT(DISTINCT pb.id) AS variations_count,
                       MIN(pb.publication_stage) AS publication_stage
                FROM {$prefix}producto_base pb
                INNER JOIN {$wpdb->posts} p ON p.ID = pb.woocommerce_product_id
                WHERE {$where}
                GROUP BY pb.woocommerce_product_id
                ORDER BY MAX(p.post_title) ASC
                LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ($rows as &$row) {
            $row['category_path'] = $this->get_product_category_path((int) $row['product_id']);
            $gate_check = $this->can_publish_group((int) $row['product_id']);
            $row['gates_ready'] = !is_wp_error($gate_check);
            $row['gate_error'] = is_wp_error($gate_check) ? $gate_check->get_error_message() : '';
        }
        unset($row);

        return $rows;
    }

    public function get_catalog_product($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return null;
        }

        $bases = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico, publication_stage,
                    woocommerce_variation_id,
                    human_product_review, human_price_review, human_category_review, human_attribute_review
             FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND deleted_at IS NULL AND archived_at IS NULL
             ORDER BY canonical_sku ASC",
            $product_id
        ), ARRAY_A);

        $attributes = [];
        foreach ($product->get_attributes() as $attr) {
            $name = $attr->get_name();
            if (strpos($name, 'pa_') === 0) {
                continue;
            }
            $attributes[] = [
                'name' => $name,
                'visible' => $attr->get_visible(),
                'variation' => $attr->get_variation(),
                'options' => $attr->get_options(),
            ];
        }

        foreach ($bases as &$base) {
            $needs_local = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$prefix}producto_proveedor pp
                 WHERE pp.producto_base_id = %d AND pp.activo = 1
                   AND pp.codigo_proveedor = %s LIMIT 1",
                (int) $base['id'],
                $base['canonical_sku']
            ));
            $base['needs_local_sku'] = $needs_local;
            $base['variation_label'] = $this->get_variation_label((int) ($base['woocommerce_variation_id'] ?? 0));
            $base['provider_codes'] = $wpdb->get_results($wpdb->prepare(
                "SELECT pp.id, pp.proveedor_id, pp.codigo_proveedor, prov.nombre AS proveedor_nombre
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}proveedores prov ON prov.id = pp.proveedor_id
                 WHERE pp.producto_base_id = %d AND pp.activo = 1
                 ORDER BY prov.nombre, pp.codigo_proveedor",
                (int) $base['id']
            ), ARRAY_A);
        }
        unset($base);

        $codes = $this->catalog_codes_list($product_id);

        return [
            'product_id' => $product_id,
            'name' => $product->get_name(),
            'status' => $product->get_status(),
            'category_path' => $this->get_product_category_path($product_id),
            'attributes' => $attributes,
            'variations_count' => count($bases),
            'bases' => $bases,
            'codes' => $codes,
            'publication_stage' => $bases[0]['publication_stage'] ?? 'pending_review',
            'gates_ready' => !is_wp_error($this->can_publish_group($product_id)),
        ];
    }

    public function save_catalog_product($product_id, $data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('not_found', 'Producto no encontrado');
        }

        $old_name = $product->get_name();
        if (!empty($data['name'])) {
            $product->set_name(sanitize_text_field($data['name']));
            $product->save();
        }

        if (isset($data['category_path']) && is_array($data['category_path'])) {
            $path = array_map('sanitize_text_field', $data['category_path']);
            $cat_result = $this->assign_product_category($product_id, $path);
            if (is_wp_error($cat_result)) {
                return $cat_result;
            }
        }

        if (!empty($data['name'])) {
            $wpdb->update(
                "{$prefix}producto_base",
                ['nombre_canonico' => sanitize_text_field($data['name']), 'updated_at' => current_time('mysql')],
                ['woocommerce_product_id' => $product_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_updated', 'product', $product_id, [
                'actor_type' => 'human',
                'old_value' => ['name' => $old_name],
                'new_value' => ['name' => $product->get_name(), 'category_path' => $data['category_path'] ?? null],
            ]);
        }

        return $this->get_catalog_product($product_id);
    }

    public function save_catalog_attributes($product_id, $attributes_data) {
        $product_id = absint($product_id);
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return new WP_Error('not_found', 'Producto variable no encontrado');
        }

        if (!is_array($attributes_data)) {
            return new WP_Error('invalid_data', 'Atributos inválidos');
        }

        $current = $product->get_attributes();
        $updated = [];
        foreach ($current as $key => $attr) {
            $name = $attr->get_name();
            if (strpos($name, 'pa_') === 0) {
                $updated[] = $attr;
                continue;
            }
            $plain = $name;
            if (isset($attributes_data[$plain]) && is_array($attributes_data[$plain])) {
                $options = array_values(array_filter(array_unique(array_map('trim', $attributes_data[$plain]))));
                $new_attr = new WC_Product_Attribute();
                $new_attr->set_id($attr->get_id());
                $new_attr->set_name($name);
                $new_attr->set_options($options);
                $new_attr->set_visible($attr->get_visible());
                $new_attr->set_variation($attr->get_variation());
                $updated[] = $new_attr;
            } else {
                $updated[] = $attr;
            }
        }

        $product->set_attributes($updated);
        $product->save();
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_updated', 'product', $product_id, [
                'actor_type' => 'human',
                'details' => 'Atributos de producto actualizados desde portal interno',
            ]);
        }

        return $this->get_catalog_product($product_id);
    }

    public function approve_group_gate($product_id, $gate, $status = 'approved') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $allowed = ['human_product_review', 'human_price_review', 'human_category_review', 'human_attribute_review'];
        $status = in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'approved';
        if (!in_array($gate, $allowed, true)) {
            return new WP_Error('invalid_gate', 'Gate inválido');
        }

        $product_id = absint($product_id);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND deleted_at IS NULL AND archived_at IS NULL",
            $product_id
        ));
        if (empty($rows)) {
            return new WP_Error('not_found', 'Sin productos base vinculados');
        }

        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';
        $product_module = Riverso_Product_Module::get_instance();
        foreach ($rows as $base_id) {
            $product_module->approve_gate((int) $base_id, $gate, $status);
        }

        if ($status === 'rejected' && function_exists('riverso_create_review_task')) {
            $gate_tasks = [
                'human_product_review' => 'relacionar_producto_proveedor',
                'human_price_review' => 'aprobar_lista_precios',
                'human_category_review' => 'validar_categoria',
                'human_attribute_review' => 'confirmar_estructura_atributos',
            ];
            $gate_labels = [
                'human_product_review' => 'Producto',
                'human_price_review' => 'Precio',
                'human_category_review' => 'Categoría',
                'human_attribute_review' => 'Atributos',
            ];
            if (isset($gate_tasks[$gate])) {
                riverso_create_review_task(
                    $gate_tasks[$gate],
                    sprintf('Revisar gate %s rechazado — producto WC #%d', $gate_labels[$gate] ?? $gate, $product_id),
                    'producto_base',
                    (int) $rows[0],
                    ['prioridad' => 'alta', 'datos_extra' => ['product_id' => $product_id, 'gate' => $gate]]
                );
            }
        }

        return $this->get_catalog_product($product_id);
    }

    public function authorize_group_publication($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        $base_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND deleted_at IS NULL LIMIT 1",
            $product_id
        ));
        if (!$base_id) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }
        return $this->authorize_publication((int) $base_id);
    }

    public function publish_group_product($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        $base_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND deleted_at IS NULL LIMIT 1",
            $product_id
        ));
        if (!$base_id) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }
        return $this->publish_product((int) $base_id);
    }

    private function build_attributes($items) {
        $nominal = [];
        $largo = [];
        $combined = [];
        $envase = [];
        $acabado = [];
        $first_variation = [];

        foreach ($items as $entry) {
            $map = $this->attributes_to_map($entry['attributes'] ?? []);
            if (!empty($map['nominal'])) {
                $nominal[] = $map['nominal'];
            }
            if (!empty($map['largo'])) {
                $largo[] = $map['largo'];
            }
            $combo = $this->combined_nominal_largo($map);
            if ($combo !== '') {
                $combined[] = $combo;
                if (!$first_variation) {
                    $first_variation['nominal-x-largo'] = $combo;
                }
            }
            if (!empty($map['envase'])) {
                $envase[] = $map['envase'];
                $first_variation['envase'] = $first_variation['envase'] ?? $map['envase'];
            }
            if (!empty($map['acabado'])) {
                $acabado[] = $map['acabado'];
                $first_variation['acabado'] = $first_variation['acabado'] ?? $map['acabado'];
            }
        }

        $attributes = [];
        $attributes[] = $this->wc_attribute('Nominal', array_unique($nominal), true, false);
        $attributes[] = $this->wc_attribute('Largo', array_unique($largo), true, false);
        $attributes[] = $this->wc_attribute('Nominal X Largo', array_unique($combined), false, true);
        if ($envase) {
            $attributes[] = $this->wc_attribute('Envase', array_unique($envase), true, true);
        }
        if ($acabado) {
            $attributes[] = $this->wc_attribute('Acabado', array_unique($acabado), true, true);
        }

        return [
            'wc_attributes' => array_filter($attributes),
            'default_attributes' => $first_variation,
        ];
    }

    private function wc_attribute($name, $options, $visible, $variation) {
        $options = array_values(array_filter(array_unique($options)));
        if (!$options) {
            return null;
        }
        $attr = new WC_Product_Attribute();
        $attr->set_id(0);
        $attr->set_name($name);
        $attr->set_options($options);
        $attr->set_visible($visible);
        $attr->set_variation($variation);
        return $attr;
    }

    private function variation_attributes($map) {
        $attrs = [];
        $combo = $this->combined_nominal_largo($map);
        if ($combo !== '') {
            $attrs['nominal-x-largo'] = $combo;
        }
        if (!empty($map['envase'])) {
            $attrs['envase'] = $map['envase'];
        }
        if (!empty($map['acabado'])) {
            $attrs['acabado'] = $map['acabado'];
        }
        return $attrs;
    }

    private function combined_nominal_largo($map) {
        if (empty($map['nominal']) && empty($map['largo'])) {
            return '';
        }
        return trim(($map['nominal'] ?? '') . ' x ' . ($map['largo'] ?? ''));
    }

    private function attributes_to_map($attributes) {
        $out = [];
        foreach ($attributes as $attr) {
            $key = strtolower(trim($attr['name'] ?? ''));
            $key = $key === 'acabado' ? 'acabado' : $key;
            if ($key !== '') {
                $out[$key] = trim((string) ($attr['value'] ?? ''));
            }
        }
        return $out;
    }

    public function ajax_publish_mamut_group() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->create_mamut_group(
            sanitize_text_field($_POST['group_key'] ?? ''),
            sanitize_text_field($_POST['path'] ?? ''),
            sanitize_text_field($_POST['status'] ?? 'private')
        );
        $this->send_result($result, 'Producto WooCommerce creado');
    }

    public function ajax_authorize() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->authorize_publication(absint($_POST['producto_base_id'] ?? 0));
        $this->send_result($result, 'Producto autorizado para publicación');
    }

    public function ajax_publish_product() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->publish_product(absint($_POST['producto_base_id'] ?? 0));
        $this->send_result($result, 'Producto publicado');
    }

    public function ajax_catalog_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        wp_send_json_success([
            'items' => $this->list_catalog_products([
                'search' => sanitize_text_field($_POST['search'] ?? ''),
                'limit' => intval($_POST['limit'] ?? 50),
                'offset' => intval($_POST['offset'] ?? 0),
            ]),
        ]);
    }

    public function ajax_catalog_get() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $item = $this->get_catalog_product(absint($_POST['product_id'] ?? 0));
        if (!$item) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }
        wp_send_json_success(['item' => $item]);
    }

    public function ajax_catalog_save() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $category_path = [];
        if (!empty($_POST['category_path'])) {
            $raw = json_decode(stripslashes((string) $_POST['category_path']), true);
            if (is_array($raw)) {
                $category_path = $raw;
            }
        }
        $result = $this->save_catalog_product(absint($_POST['product_id'] ?? 0), [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'category_path' => $category_path,
        ]);
        $this->send_result($result, 'Producto guardado');
    }

    public function ajax_catalog_save_attributes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $attrs = json_decode(stripslashes((string) ($_POST['attributes'] ?? '{}')), true);
        $result = $this->save_catalog_attributes(absint($_POST['product_id'] ?? 0), is_array($attrs) ? $attrs : []);
        $this->send_result($result, 'Atributos guardados');
    }

    public function ajax_catalog_approve_gate() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->approve_group_gate(
            absint($_POST['product_id'] ?? 0),
            sanitize_text_field($_POST['gate'] ?? ''),
            sanitize_text_field($_POST['status'] ?? 'approved')
        );
        $this->send_result($result, 'Gate actualizado para el grupo');
    }

    public function ajax_catalog_authorize() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->authorize_group_publication(absint($_POST['product_id'] ?? 0));
        $this->send_result($result, 'Grupo autorizado para publicación');
    }

    public function ajax_catalog_publish() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_publish_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        $result = $this->publish_group_product(absint($_POST['product_id'] ?? 0));
        $this->send_result($result, 'Producto publicado');
    }

    private function can_access_catalog() {
        return current_user_can('riverso_review_products')
            || current_user_can('riverso_manage_products')
            || current_user_can('riverso_publish_products');
    }

    private function can_edit_catalog() {
        return current_user_can('riverso_manage_products')
            || current_user_can('riverso_review_products')
            || current_user_can('riverso_publish_products');
    }

    public function cli_publish_mamut_group($args, $assoc) {
        $group = $args[0] ?? ($assoc['group'] ?? '');
        $path = $assoc['json-path'] ?? ($assoc['catalog-path'] ?? '');
        $status = $assoc['status'] ?? 'private';
        $result = $this->create_mamut_group($group, $path, $status);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Producto creado: #' . $result['product_id'] . ' variaciones=' . $result['created_variations']);
    }

    public function cli_publish_mamut_all($args, $assoc) {
        $limit = isset($assoc['per-batch']) ? intval($assoc['per-batch']) : 20;
        $path = $assoc['json-path'] ?? ($assoc['catalog-path'] ?? '');
        $status = $assoc['status'] ?? 'private';
        $offset = isset($assoc['offset']) ? intval($assoc['offset']) : 0;
        $once = isset($assoc['once']);
        $total_created = 0;
        $total_variations = 0;
        $total_skipped = 0;
        $total_errors = 0;

        do {
            $res = $this->bulk_create_drafts($offset, $limit, $path, $status);
            if (is_wp_error($res)) {
                WP_CLI::error($res->get_error_message());
                return;
            }

            $total_created += intval($res['created_products']);
            $total_variations += intval($res['created_variations']);
            $total_skipped += intval($res['skipped']);
            $total_errors += count($res['errors']);

            WP_CLI::log(sprintf(
                'Offset grupos %d: procesados %d/%d (productos nuevos %d, variaciones nuevas %d, omitidos %d, errores %d)',
                $res['offset_groups'],
                $res['processed'],
                $res['total_groups'],
                $res['created_products'],
                $res['created_variations'],
                $res['skipped'],
                count($res['errors'])
            ));

            foreach ($res['errors'] as $group_key => $message) {
                WP_CLI::warning($group_key . ': ' . $message);
            }

            $offset = $res['next_offset'];
        } while (!$once && $offset !== null);

        if ($once && $offset !== null) {
            WP_CLI::success(sprintf(
                'Lote de creación MAMUT completado. Siguiente offset: %d. Productos nuevos %d, variaciones nuevas %d, omitidos %d, errores %d.',
                $offset,
                $total_created,
                $total_variations,
                $total_skipped,
                $total_errors
            ));
            return;
        }

        WP_CLI::success(sprintf(
            'Creación masiva MAMUT completada: productos nuevos %d, variaciones nuevas %d, omitidos %d, errores %d.',
            $total_created,
            $total_variations,
            $total_skipped,
            $total_errors
        ));
    }

    public function cli_backfill_categories($args, $assoc) {
        $limit = isset($assoc['per-batch']) ? intval($assoc['per-batch']) : 20;
        $path = $assoc['json-path'] ?? ($assoc['catalog-path'] ?? '');
        $offset = isset($assoc['offset']) ? intval($assoc['offset']) : 0;
        $once = isset($assoc['once']);
        $total_assigned = 0;
        $total_skipped = 0;
        $total_errors = 0;

        do {
            $res = $this->bulk_backfill_categories($offset, $limit, $path);
            if (is_wp_error($res)) {
                WP_CLI::error($res->get_error_message());
                return;
            }

            $total_assigned += intval($res['assigned']);
            $total_skipped += intval($res['skipped']);
            $total_errors += count($res['errors']);

            WP_CLI::log(sprintf(
                'Offset grupos %d: procesados %d/%d (asignados %d, omitidos %d, errores %d)',
                $res['offset_groups'],
                $res['processed'],
                $res['total_groups'],
                $res['assigned'],
                $res['skipped'],
                count($res['errors'])
            ));

            foreach ($res['errors'] as $group_key => $message) {
                WP_CLI::warning($group_key . ': ' . $message);
            }

            $offset = $res['next_offset'];
        } while (!$once && $offset !== null);

        if ($once && $offset !== null) {
            WP_CLI::success(sprintf(
                'Lote backfill categorías completado. Siguiente offset: %d. Asignados %d, omitidos %d, errores %d.',
                $offset,
                $total_assigned,
                $total_skipped,
                $total_errors
            ));
            return;
        }

        WP_CLI::success(sprintf(
            'Backfill categorías completado: asignados %d, omitidos %d, errores %d.',
            $total_assigned,
            $total_skipped,
            $total_errors
        ));
    }

    public function cli_enqueue_local_sku_tasks($args, $assoc_args) {
        $result = $this->enqueue_local_sku_tasks();
        WP_CLI::success(sprintf(
            'Tareas de SKU local generadas: %d de %d bases MAMUT sin SKU local.',
            $result['created'],
            $result['bases_checked']
        ));
    }

    // ========================================
    // MÉTODOS DE CÓDIGOS DE PROVEEDOR
    // ========================================

    /**
     * Lista los códigos de proveedor asociados a un producto WooCommerce.
     *
     * @param int $product_id ID del producto WC variable.
     * @return array Filas de producto_proveedor activos del grupo.
     */
    public function catalog_codes_list($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);

        $codes = $wpdb->get_results($wpdb->prepare(
            "SELECT pp.id, pp.producto_base_id, pp.proveedor_id, pp.codigo_proveedor, pp.activo,
                    pb.canonical_sku, pb.woocommerce_variation_id,
                    prov.nombre AS proveedor_nombre
             FROM {$prefix}producto_proveedor pp
             INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
             LEFT JOIN {$prefix}proveedores prov ON prov.id = pp.proveedor_id
             WHERE pb.woocommerce_product_id = %d AND pb.deleted_at IS NULL AND pp.activo = 1
             ORDER BY pb.canonical_sku, prov.nombre, pp.codigo_proveedor",
            $product_id
        ), ARRAY_A);

        foreach ($codes as &$code) {
            $code['variation_label'] = $this->get_variation_label((int) ($code['woocommerce_variation_id'] ?? 0));
        }
        unset($code);

        return $codes;
    }

    /**
     * Etiqueta legible de una variación WooCommerce.
     */
    private function get_variation_label($variation_id) {
        $variation_id = absint($variation_id);
        if (!$variation_id) {
            return '';
        }
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return 'Variación #' . $variation_id;
        }
        $summary = $variation->get_attribute_summary();
        if ($summary !== '') {
            return $summary;
        }
        $sku = $variation->get_sku();
        return $sku !== '' ? $sku : ('Variación #' . $variation_id);
    }

    /**
     * Crea un proveedor desde el catálogo (nombre + RUT mínimos).
     */
    public function catalog_create_supplier($nombre, $rut) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $nombre = sanitize_text_field($nombre);
        $rut = preg_replace('/[^0-9kK]/', '', strtoupper(sanitize_text_field($rut)));

        if ($nombre === '' || $rut === '') {
            return new WP_Error('invalid_params', 'Nombre y RUT son requeridos');
        }

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}proveedores WHERE rut = %s",
            $rut
        ));
        if ($existing_id) {
            return [
                'id' => (int) $existing_id,
                'nombre' => $nombre,
                'rut' => $rut,
                'existing' => true,
            ];
        }

        $result = $wpdb->insert(
            "{$prefix}proveedores",
            [
                'rut' => $rut,
                'nombre' => $nombre,
                'activo' => 1,
            ],
            ['%s', '%s', '%d']
        );
        if (!$result) {
            return new WP_Error('insert_failed', 'No se pudo crear el proveedor');
        }

        $id = (int) $wpdb->insert_id;
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('supplier_created', 'supplier', $id, [
                'actor_type' => 'human',
                'new_value' => ['nombre' => $nombre, 'rut' => $rut],
                'details' => 'Proveedor creado desde catálogo interno',
            ]);
        }

        return ['id' => $id, 'nombre' => $nombre, 'rut' => $rut, 'existing' => false];
    }

    public function ajax_catalog_search_suppliers() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $search = sanitize_text_field($_POST['search'] ?? '');
        $limit = min(20, max(5, absint($_POST['limit'] ?? 10)));

        if ($search === '') {
            wp_send_json_success(['suppliers' => []]);
        }

        $like = '%' . $wpdb->esc_like($search) . '%';
        $suppliers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, rut, nombre FROM {$prefix}proveedores
             WHERE activo = 1 AND (nombre LIKE %s OR rut LIKE %s)
             ORDER BY nombre ASC LIMIT %d",
            $like,
            $like,
            $limit
        ), ARRAY_A);

        wp_send_json_success(['suppliers' => $suppliers]);
    }

    public function ajax_catalog_create_supplier() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $result = $this->catalog_create_supplier(
            sanitize_text_field($_POST['nombre'] ?? ''),
            sanitize_text_field($_POST['rut'] ?? '')
        );
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['supplier' => $result]);
    }

    /**
     * Desvincula (soft delete) un código de proveedor.
     *
     * @param int $pp_id ID de producto_proveedor.
     * @return bool|WP_Error true o error.
     */
    public function catalog_code_unlink($pp_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $pp_id = absint($pp_id);

        $pp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_proveedor WHERE id = %d",
            $pp_id
        ), ARRAY_A);
        if (!$pp) {
            return new WP_Error('not_found', 'Código de proveedor no encontrado');
        }

        $wpdb->update(
            "{$prefix}producto_proveedor",
            ['activo' => 0, 'updated_at' => current_time('mysql')],
            ['id' => $pp_id],
            ['%d', '%s'],
            ['%d']
        );

        // Soft delete en supplier_product_links también si existe.
        if (!empty($pp['supplier_link_id'])) {
            $wpdb->update(
                "{$prefix}supplier_product_links",
                ['is_active' => 0, 'updated_at' => current_time('mysql')],
                ['id' => $pp['supplier_link_id']],
                ['%d', '%s'],
                ['%d']
            );
        }

        // Auditar.
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('supplier_code_unlinked', 'producto_proveedor', $pp_id, [
                'actor_type' => 'human',
                'old_value' => ['activo' => 1],
                'new_value' => ['activo' => 0],
            ]);
        }

        return true;
    }

    /**
     * Vincula un código de proveedor a un producto_base.
     * Crea o reactiva la entrada y sincroniza con supplier_product_links si aplica.
     *
     * @param int $base_id ID del producto_base.
     * @param int $proveedor_id ID del proveedor.
     * @param string $codigo Código del proveedor.
     * @return array|WP_Error Fila actualizada o error.
     */
    public function catalog_code_link($base_id, $proveedor_id, $codigo) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = absint($base_id);
        $proveedor_id = absint($proveedor_id);
        $codigo = sanitize_text_field($codigo);

        if (!$base_id || !$proveedor_id || !$codigo) {
            return new WP_Error('invalid_params', 'Parámetros incompletos');
        }

        $base = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $base_id
        ), ARRAY_A);
        if (!$base) {
            return new WP_Error('base_not_found', 'Producto base no encontrado');
        }

        // Buscar o crear entrada producto_proveedor.
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, activo FROM {$prefix}producto_proveedor WHERE proveedor_id = %d AND codigo_proveedor = %s AND producto_base_id = %d",
            $proveedor_id,
            $codigo,
            $base_id
        ), ARRAY_A);

        if ($existing) {
            // Reactivar si estaba inactiva.
            if (!$existing['activo']) {
                $wpdb->update(
                    "{$prefix}producto_proveedor",
                    ['activo' => 1, 'updated_at' => current_time('mysql')],
                    ['id' => $existing['id']],
                    ['%d', '%s'],
                    ['%d']
                );
            }
            $pp_id = $existing['id'];
        } else {
            // Crear nueva entrada.
            $wpdb->insert(
                "{$prefix}producto_proveedor",
                [
                    'producto_base_id' => $base_id,
                    'proveedor_id' => $proveedor_id,
                    'codigo_proveedor' => $codigo,
                    'activo' => 1,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%d', '%s']
            );
            $pp_id = $wpdb->insert_id;

            if (class_exists('Riverso_Supplier_Links_Module')) {
                $links_module = Riverso_Supplier_Links_Module::get_instance();
                $existing_link = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$prefix}supplier_product_links
                     WHERE supplier_id = %d AND supplier_code = %s",
                    $proveedor_id,
                    $codigo
                ));
                if (!$existing_link && method_exists($links_module, 'create_link')) {
                    $links_module->create_link([
                        'supplier_id' => $proveedor_id,
                        'supplier_code' => $codigo,
                        'product_id' => $base['woocommerce_product_id'],
                        'internal_sku' => $base['canonical_sku'] ?? '',
                    ]);
                }
            }
        }

        // Auditar.
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('supplier_code_linked', 'producto_proveedor', $pp_id, [
                'actor_type' => 'human',
                'old_value' => ['activo' => $existing ? 0 : null],
                'new_value' => ['proveedor_id' => $proveedor_id, 'codigo' => $codigo, 'activo' => 1],
            ]);
        }

        return [
            'id' => $pp_id,
            'proveedor_id' => $proveedor_id,
            'codigo_proveedor' => $codigo,
            'activo' => 1,
        ];
    }

    // AJAX endpoints para códigos de proveedor.

    /**
     * Obtiene el contexto (detalles) de lo que se va a aprobar en cada gate.
     *
     * @param int $product_id ID del producto WC variable.
     * @param string $gate El gate: human_product_review, human_price_review, human_category_review, human_attribute_review.
     * @return array Datos contextuales del gate.
     */
    public function gate_context($product_id, $gate) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $product_id = absint($product_id);
        $gate = sanitize_text_field($gate);

        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        $bases = $wpdb->get_results($wpdb->prepare(
            "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base
             WHERE woocommerce_product_id = %d AND deleted_at IS NULL LIMIT 10",
            $product_id
        ), ARRAY_A);

        switch ($gate) {
            case 'human_product_review':
                // Mostrar nombre, estado SKU local, nro de códigos.
                $codes_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$prefix}producto_proveedor pp
                     INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                     WHERE pb.woocommerce_product_id = %d AND pp.activo = 1",
                    $product_id
                ));

                return [
                    'gate' => $gate,
                    'label' => 'Revisar Producto',
                    'product_name' => $product->get_name(),
                    'sku_status' => 'Los SKUs locales deben estar asignados',
                    'codes_count' => (int) $codes_count,
                    'bases_count' => count($bases),
                ];

            case 'human_category_review':
                // Mostrar category_path actual.
                $category_path = $this->get_product_category_path($product_id);
                return [
                    'gate' => $gate,
                    'label' => 'Revisar Categoría',
                    'current_path' => $category_path,
                    'category_count' => count($category_path),
                ];

            case 'human_attribute_review':
                // Mostrar atributos y opciones.
                $attributes = [];
                foreach ($product->get_attributes() as $attr) {
                    $name = $attr->get_name();
                    if (strpos($name, 'pa_') === 0) {
                        continue;
                    }
                    $attributes[] = [
                        'name' => $name,
                        'options' => $attr->get_options(),
                        'count' => count($attr->get_options()),
                    ];
                }
                return [
                    'gate' => $gate,
                    'label' => 'Revisar Atributos',
                    'attributes' => $attributes,
                    'attribute_count' => count($attributes),
                ];

            case 'human_price_review':
                // Mostrar precios, costos, márgenes.
                require_once RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-pricing-module.php';
                $pricing_module = Riverso_Pricing_Module::get_instance();

                $prices = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.* FROM {$prefix}precios p
                     INNER JOIN {$prefix}producto_base pb ON pb.id = p.producto_base_id
                     WHERE pb.woocommerce_product_id = %d AND p.canal = 'online'
                     LIMIT 20",
                    $product_id
                ), ARRAY_A);

                // Obtener costos históricos.
                $costs = $wpdb->get_results($wpdb->prepare(
                    "SELECT ch.* FROM {$prefix}cost_history ch
                     WHERE ch.product_id = %d
                     ORDER BY ch.document_date DESC
                     LIMIT 5",
                    $product_id
                ), ARRAY_A);

                $prices_data = [];
                foreach ($prices as $p) {
                    $margin = 0;
                    if ($p['c_ref'] && $p['p_asignado']) {
                        $margin = (($p['p_asignado'] - $p['c_ref']) / $p['p_asignado']) * 100;
                    }
                    $prices_data[] = [
                        'id' => $p['id'],
                        'c_ref' => $p['c_ref'],
                        'p_ref' => $p['p_ref'],
                        'p_asignado' => $p['p_asignado'],
                        'factor_minimo' => $p['factor_minimo'],
                        'alerta' => $p['alerta_margen'],
                        'margin_pct' => round($margin, 2),
                    ];
                }

                return [
                    'gate' => $gate,
                    'label' => 'Revisar Precio',
                    'prices' => $prices_data,
                    'recent_costs' => $costs,
                    'price_count' => count($prices_data),
                ];

            default:
                return null;
        }
    }

    public function ajax_gate_context() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $gate = sanitize_text_field($_POST['gate'] ?? '');

        if (!$product_id || !$gate) {
            wp_send_json_error(['message' => 'Parámetros incompletos']);
        }

        $context = $this->gate_context($product_id, $gate);
        if (!$context) {
            wp_send_json_error(['message' => 'Contexto no disponible']);
        }

        wp_send_json_success(['context' => $context]);
    }

    // AJAX endpoints para códigos de proveedor.

    public function ajax_catalog_codes_list() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error(['message' => 'product_id requerido']);
        }

        $codes = $this->catalog_codes_list($product_id);
        wp_send_json_success(['codes' => $codes]);
    }

    public function ajax_catalog_code_unlink() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $pp_id = absint($_POST['pp_id'] ?? 0);
        if (!$pp_id) {
            wp_send_json_error(['message' => 'pp_id requerido']);
        }

        $result = $this->catalog_code_unlink($pp_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Código desvinculado']);
    }

    public function ajax_catalog_code_link() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $base_id = absint($_POST['base_id'] ?? 0);
        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $codigo = sanitize_text_field($_POST['codigo'] ?? '');

        if (!$base_id || !$proveedor_id || !$codigo) {
            wp_send_json_error(['message' => 'Parámetros incompletos']);
        }

        $result = $this->catalog_code_link($base_id, $proveedor_id, $codigo);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['code' => $result]);
    }

    // ========================================
    // MÉTODOS DE ÁRBOL DE CATEGORÍAS
    // ========================================

    /**
     * Obtiene el árbol jerárquico de categorías product_cat con conteos.
     *
     * @param int $parent_id ID del padre (0 para raiz).
     * @return array Árbol de terminos con hijos y conteos.
     */
    public function category_tree($parent_id = 0) {
        $parent_id = absint($parent_id);
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => $parent_id,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $tree = [];
        foreach ($terms as $term) {
            $count = $term->count;
            $children = $this->category_tree($term->term_id);
            $tree[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => $count,
                'children' => $children,
                'has_children' => !empty($children),
            ];
        }

        return $tree;
    }

    /**
     * Crea una nueva categoría bajo un padre.
     *
     * @param int $parent_id ID padre (0 para raiz).
     * @param string $name Nombre de la categoria.
     * @return array|WP_Error Nueva categoria o error.
     */
    public function category_create($parent_id, $name) {
        $parent_id = absint($parent_id);
        $name = sanitize_text_field($name);

        if (empty($name)) {
            return new WP_Error('empty_name', 'Nombre de categoría vacío');
        }

        // Validar que no exista duplicado bajo el mismo padre.
        $exists = term_exists($name, 'product_cat', $parent_id);
        if ($exists && !is_wp_error($exists)) {
            return new WP_Error('duplicate', sprintf(
                'Ya existe una categoría "%s" bajo el padre %d',
                $name,
                $parent_id
            ));
        }

        $result = wp_insert_term($name, 'product_cat', ['parent' => $parent_id]);
        if (is_wp_error($result)) {
            return $result;
        }

        $term = get_term($result['term_id'], 'product_cat');
        return [
            'id' => $term->term_id,
            'name' => $term->name,
            'parent' => $term->parent,
        ];
    }

    /**
     * Renombra una categoría.
     *
     * @param int $term_id ID del termino.
     * @param string $new_name Nuevo nombre.
     * @param string $scope 'local' o 'global'. Local crea termino nuevo para solo este producto.
     * @param int $product_id Opcional, si scope=local.
     * @return array|WP_Error Categoria actualizada o error.
     */
    public function category_rename($term_id, $new_name, $scope = 'global', $product_id = 0) {
        $term_id = absint($term_id);
        $new_name = sanitize_text_field($new_name);
        $product_id = absint($product_id);

        if (empty($new_name)) {
            return new WP_Error('empty_name', 'Nombre vacío');
        }

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('not_found', 'Categoría no encontrada');
        }

        if ($scope === 'global') {
            // Actualizar la categoría existente (afecta a todos los productos).
            $result = wp_update_term($term_id, 'product_cat', ['name' => $new_name]);
            if (is_wp_error($result)) {
                return $result;
            }

            // Auditar.
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('category_updated', 'product_cat', $term_id, [
                    'actor_type' => 'human',
                    'old_value' => ['name' => $term->name],
                    'new_value' => ['name' => $new_name],
                    'details' => 'Renombramiento global de categoría',
                ]);
            }

            return [
                'id' => $term_id,
                'name' => $new_name,
                'scope' => 'global',
            ];
        } else {
            // Crear nueva categoría con mismo padre y reasignar solo este producto.
            if (!$product_id) {
                return new WP_Error('missing_product_id', 'product_id requerido para scope=local');
            }

            $parent = $term->parent;
            $new_term_result = wp_insert_term($new_name, 'product_cat', ['parent' => $parent]);
            if (is_wp_error($new_term_result)) {
                return $new_term_result;
            }

            $new_term_id = $new_term_result['term_id'];

            // Reasignar categoría al producto.
            wp_set_object_terms($product_id, $new_term_id, 'product_cat', false);

            // Auditar.
            if (class_exists('Riverso_POS_Audit')) {
                Riverso_POS_Audit::log('category_updated', 'product', $product_id, [
                    'actor_type' => 'human',
                    'old_value' => ['term_id' => $term_id, 'name' => $term->name],
                    'new_value' => ['term_id' => $new_term_id, 'name' => $new_name],
                    'details' => 'Renombramiento local de categoría (nuevo término)',
                ]);
            }

            return [
                'id' => $new_term_id,
                'name' => $new_name,
                'scope' => 'local',
                'old_term_id' => $term_id,
            ];
        }
    }

    /**
     * Elimina una categoría.
     *
     * @param int $term_id ID del termino.
     * @param bool $force Si true, fuerza eliminacion even si tiene hijos.
     * @return bool|WP_Error true si se eliminó, error si no (ej: tiene hijos o productos).
     */
    public function category_delete($term_id, $force = false) {
        $term_id = absint($term_id);
        $term = get_term($term_id, 'product_cat');

        if (!$term || is_wp_error($term)) {
            return new WP_Error('not_found', 'Categoría no encontrada');
        }

        // Validar que no tenga hijos, a menos que force=true.
        $children = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => $term_id,
            'hide_empty' => false,
        ]);

        if (!is_wp_error($children) && !empty($children) && !$force) {
            return new WP_Error('has_children', sprintf(
                'Categoría "%s" tiene %d subcategorías. Usa force=true para eliminar.',
                $term->name,
                count($children)
            ));
        }

        // Validar que no tenga productos asignados, a menos que force=true.
        $product_count = $term->count;
        if ($product_count > 0 && !$force) {
            return new WP_Error('has_products', sprintf(
                'Categoría "%s" tiene %d productos asignados. Usa force=true para eliminar.',
                $term->name,
                $product_count
            ));
        }

        // Eliminar.
        $result = wp_delete_term($term_id, 'product_cat');
        if (is_wp_error($result)) {
            return $result;
        }

        // Auditar.
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log_system('category_deleted', 'product_cat', $term_id, [
                'old_value' => ['name' => $term->name, 'product_count' => $product_count],
                'details' => 'Categoría eliminada (por usuario)',
            ]);
        }

        return true;
    }

    // AJAX endpoints para categorías.

    public function ajax_category_tree() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $parent_id = absint($_POST['parent_id'] ?? 0);
        $tree = $this->category_tree($parent_id);
        wp_send_json_success(['tree' => $tree]);
    }

    public function ajax_category_create() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $parent_id = absint($_POST['parent_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');

        $result = $this->category_create($parent_id, $name);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['category' => $result]);
    }

    public function ajax_category_rename() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $term_id = absint($_POST['term_id'] ?? 0);
        $new_name = sanitize_text_field($_POST['new_name'] ?? '');
        $scope = sanitize_text_field($_POST['scope'] ?? 'global');
        $product_id = absint($_POST['product_id'] ?? 0);

        if (!$term_id || !$new_name) {
            wp_send_json_error(['message' => 'Parámetros incompletos']);
        }

        $result = $this->category_rename($term_id, $new_name, $scope, $product_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['category' => $result]);
    }

    public function ajax_category_delete() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $term_id = absint($_POST['term_id'] ?? 0);
        $force = !empty($_POST['force']);

        if (!$term_id) {
            wp_send_json_error(['message' => 'term_id requerido']);
        }

        $result = $this->category_delete($term_id, $force);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Categoría eliminada']);
    }

    /**
     * Busca SKUs locales en tienda_local_productos por nombre o codigo de barra.
     *
     * @param string $q Termino de busqueda.
     * @return array Filas con sku, nombre, barcode (si coincide).
     */
    public function search_local_sku($q) {
        if (empty($q) || strlen($q) < 2) {
            return [];
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($q) . '%';

        $sql = $wpdb->prepare(
            "SELECT DISTINCT tlp.sku, tlp.nombre
             FROM {$prefix}tienda_local_productos tlp
             WHERE tlp.nombre LIKE %s OR tlp.sku LIKE %s
             LIMIT 20",
            $like,
            $like
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as &$row) {
            $barcode = $wpdb->get_var($wpdb->prepare(
                "SELECT barcode FROM {$prefix}tienda_local_barcodes WHERE sku = %s LIMIT 1",
                $row['sku']
            ));
            $row['barcode'] = $barcode ?? '';
        }
        unset($row);

        return $rows;
    }

    /**
     * Asigna un SKU local a un producto_base (reemplazando el codigo MAMUT por el SKU real).
     * Si crear_nuevo=true, crea una nueva entrada en tienda_local_productos.
     *
     * @param int $base_id ID de producto_base.
     * @param string $sku_local SKU local a asignar.
     * @param bool $crear_nuevo Si true, crea entrada en tienda_local_productos si no existe.
     * @return array|WP_Error Datos actualizados o error.
     */
    public function assign_local_sku($base_id, $sku_local, $crear_nuevo = false) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $base_id = absint($base_id);
        $sku_local = sanitize_text_field($sku_local);

        if (empty($sku_local)) {
            return new WP_Error('invalid_sku', 'SKU local vacío');
        }

        $base = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}producto_base WHERE id = %d",
            $base_id
        ), ARRAY_A);
        if (!$base) {
            return new WP_Error('not_found', 'Producto base no encontrado');
        }

        // Validar que existe el SKU en tienda local o crear si `crear_nuevo`.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$prefix}tienda_local_productos WHERE sku = %s",
            $sku_local
        ));
        if (!$exists && !$crear_nuevo) {
            return new WP_Error('sku_not_found', 'SKU local no existe en tienda local');
        }

        if (!$exists && $crear_nuevo) {
            $result = $wpdb->insert("{$prefix}tienda_local_productos", [
                'sku' => $sku_local,
                'nombre' => "SKU local: {$sku_local}",
                'precio' => 0.00,
                'stock' => 0,
            ], ['%s', '%s', '%f', '%d']);
            if (!$result) {
                return new WP_Error('insert_failed', 'No se pudo crear SKU local: ' . $wpdb->last_error);
            }
        }

        // Actualizar producto_base.canonical_sku y marcar gate listo.
        $old_sku = $base['canonical_sku'];
        $wpdb->update(
            "{$prefix}producto_base",
            [
                'canonical_sku' => $sku_local,
                'human_product_review' => 'approved',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $base_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Auditar cambio.
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_updated', 'producto_base', $base_id, [
                'actor_type' => 'human',
                'old_value' => ['canonical_sku' => $old_sku],
                'new_value' => ['canonical_sku' => $sku_local, 'human_product_review' => 'approved'],
                'details' => "SKU local asignado desde portal",
            ]);
        }

        // Cerrar tarea asociada si existe.
        $pp_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_proveedor
             WHERE producto_base_id = %d AND activo = 1 ORDER BY id ASC LIMIT 1",
            $base_id
        ));
        if ($pp_id) {
            $this->close_related_task('relacionar_producto_proveedor', 'producto_proveedor', (int) $pp_id);
        }

        return [
            'id' => $base_id,
            'canonical_sku' => $sku_local,
            'human_product_review' => 'approved',
        ];
    }

    /**
     * Cierra una tarea de revisión relacionada si existe.
     */
    private function close_related_task($tipo, $referencia_tipo, $referencia_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->update(
            "{$prefix}tareas",
            ['estado' => 'completada', 'completado_en' => current_time('mysql')],
            [
                'tipo' => $tipo,
                'referencia_tipo' => $referencia_tipo,
                'referencia_id' => absint($referencia_id),
                'estado' => 'pendiente',
            ],
            ['%s', '%s'],
            ['%s', '%s', '%d', '%s']
        );
    }

    /**
     * Genera tareas de relacion SKU local para productos_base MAMUT sin SKU local.
     * Se ejecuta como CLI o como helper.
     */
    public function enqueue_local_sku_tasks() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $bases = $wpdb->get_results(
            "SELECT DISTINCT pb.id, pb.canonical_sku, pb.woocommerce_product_id,
                    pp.id AS pp_id, pp.codigo_proveedor
             FROM {$prefix}producto_base pb
             INNER JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id
             WHERE pb.canonical_sku = pp.codigo_proveedor
               AND pb.deleted_at IS NULL
               AND pp.activo = 1
             GROUP BY pb.id, pp.id
             ORDER BY pb.id ASC",
            ARRAY_A
        );

        $created = 0;
        foreach ($bases as $row) {
            if (function_exists('riverso_create_review_task')) {
                riverso_create_review_task(
                    'relacionar_producto_proveedor',
                    sprintf(
                        'Asignar SKU local para código %s (producto %d)',
                        esc_html($row['codigo_proveedor']),
                        $row['woocommerce_product_id']
                    ),
                    'producto_proveedor',
                    (int) $row['pp_id'],
                    [
                        'product_id' => (int) $row['woocommerce_product_id'],
                        'base_id' => (int) $row['id'],
                        'codigo_proveedor' => $row['codigo_proveedor'],
                    ]
                );
                $created++;
            }
        }

        return ['created' => $created, 'bases_checked' => count($bases)];
    }

    // AJAX endpoints para SKU local.

    public function ajax_catalog_search_local_sku() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_access_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $q = sanitize_text_field($_POST['q'] ?? '');
        $results = $this->search_local_sku($q);
        wp_send_json_success(['items' => $results]);
    }

    public function ajax_catalog_assign_local_sku() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!$this->can_edit_catalog()) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $base_id = absint($_POST['base_id'] ?? 0);
        $sku_local = sanitize_text_field($_POST['sku_local'] ?? '');
        $crear_nuevo = !empty($_POST['crear_nuevo']);

        if (!$base_id || !$sku_local) {
            wp_send_json_error(['message' => 'Parámetros incompletos']);
        }

        $result = $this->assign_local_sku($base_id, $sku_local, $crear_nuevo);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'SKU local asignado', 'result' => $result]);
    }

    private function send_result($result, $message) {
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => $message, 'result' => $result]);
    }
}
