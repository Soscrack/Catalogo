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
        add_action('wp_ajax_riverso_products_suggest_variable_parents', [$this, 'ajax_suggest_variable_parents']);
        add_action('wp_ajax_riverso_products_get_variable_attributes', [$this, 'ajax_get_variable_attributes']);
        add_action('wp_ajax_riverso_products_get_variable_parent_details', [$this, 'ajax_get_variable_parent_details']);
        add_action('wp_ajax_riverso_products_search_catalog', [$this, 'ajax_search_catalog_products']);
        add_action('wp_ajax_riverso_products_set_local_price', [$this, 'ajax_set_local_price']);
        add_action('wp_ajax_riverso_products_set_online_price', [$this, 'ajax_set_online_price']);
        add_action('wp_ajax_riverso_products_get_product_categories', [$this, 'ajax_get_product_categories']);
        add_action('wp_ajax_riverso_products_set_product_categories', [$this, 'ajax_set_product_categories']);
		add_action('wp_ajax_riverso_products_get_category_tree', [$this, 'ajax_get_category_tree']);
		add_action('wp_ajax_riverso_products_set_image', [$this, 'ajax_set_image']);
		add_action('wp_ajax_riverso_products_create_category', [$this, 'ajax_create_category']);
		add_action('wp_ajax_riverso_products_rename_category', [$this, 'ajax_rename_category']);
		add_action('wp_ajax_riverso_products_category_impact', [$this, 'ajax_category_impact']);
		add_action('wp_ajax_riverso_products_move_category', [$this, 'ajax_move_category']);
		add_action('wp_ajax_riverso_products_delete_category', [$this, 'ajax_delete_category']);
		add_action('wp_ajax_riverso_products_update', [$this, 'ajax_update']);
		add_action('wp_ajax_riverso_products_get_catalogs', [$this, 'ajax_get_catalogs']);
		add_action('wp_ajax_riverso_products_upload_image', [$this, 'ajax_upload_image']);
		add_action('wp_ajax_riverso_products_complete_task', [$this, 'ajax_complete_task']);
		add_action('wp_ajax_riverso_products_set_family', [$this, 'ajax_set_family']);
		add_action('wp_ajax_riverso_products_search_supplier_codes', [$this, 'ajax_search_supplier_codes']);
		add_action('wp_ajax_riverso_products_assign_supplier_code', [$this, 'ajax_assign_supplier_code']);
		add_action('wp_ajax_riverso_products_remove_supplier_code', [$this, 'ajax_remove_supplier_code']);
		add_action('wp_ajax_riverso_products_link_woo', [$this, 'ajax_link_woo']);
		add_action('wp_ajax_riverso_products_create_woo', [$this, 'ajax_create_woo']);
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
        $catalog_id = absint($args['catalog_id'] ?? 0);
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

        // JOIN proveedor: con catálogo forzar INNER (sin exigir activo=1:
        // en Mamut histórico muchos PP vienen con activo=0 y el filtro quedaba vacío).
        if ($catalog_id > 0) {
            $pp_join = $wpdb->prepare(
                "INNER JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.catalogo_id = %d",
                $catalog_id
            );
        } else {
            $pp_join = "LEFT JOIN {$prefix}producto_proveedor pp ON pp.producto_base_id = pb.id AND pp.activo = 1";
        }

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $prefix_like = $wpdb->esc_like($search) . '%';
            $sku_compact = preg_replace('/[^A-Za-z0-9]/', '', $search);
            $sku_compact_like = $sku_compact !== ''
                ? '%' . $wpdb->esc_like($sku_compact) . '%'
                : $like;

            // Prioriza SKU de catálogo (codigo_proveedor) + nombre catálogo + SKU local
            $where[] = '(
                pp.codigo_proveedor LIKE %s
                OR pp.codigo_proveedor LIKE %s
                OR REPLACE(REPLACE(REPLACE(pp.codigo_proveedor, "-", ""), " ", ""), "/", "") LIKE %s
                OR pp.nombre_proveedor LIKE %s
                OR pb.canonical_sku LIKE %s
                OR pb.nombre_canonico LIKE %s
                OR cb.codigo LIKE %s
            )';
            $params[] = $like;
            $params[] = $prefix_like;
            $params[] = $sku_compact_like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $having = '';
        if ($completeness !== '' && $completeness !== 'todos') {
            $having = $this->get_completeness_having_clause($completeness);
        }

        $order_sql = 'ORDER BY pb.updated_at DESC, pb.id DESC';
        if ($search !== '') {
            $order_sql = $wpdb->prepare(
                "ORDER BY
                    CASE
                        WHEN pp.codigo_proveedor = %s THEN 0
                        WHEN pp.codigo_proveedor LIKE %s THEN 1
                        WHEN pp.codigo_proveedor LIKE %s THEN 2
                        WHEN pp.nombre_proveedor LIKE %s THEN 3
                        ELSE 4
                    END ASC,
                    pb.updated_at DESC, pb.id DESC",
                $search,
                $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $sql = "SELECT pb.*,
                       COUNT(DISTINCT pp.id) AS proveedores_count,
                       COUNT(DISTINCT em.id) AS equivalencias_count,
                       GROUP_CONCAT(DISTINCT pp.codigo_proveedor SEPARATOR ', ') AS codigos_proveedor,
                       GROUP_CONCAT(DISTINCT CASE
                           WHEN pp.catalogo_id IS NOT NULL AND pp.catalogo_id > 0
                           THEN pp.codigo_proveedor
                           ELSE NULL
                       END SEPARATOR ', ') AS codigos_catalogo
                FROM {$prefix}producto_base pb
                {$pp_join}
                LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                LEFT JOIN {$prefix}codigo_barra cb ON cb.producto_base_id = pb.id
                WHERE {$where_sql}
                GROUP BY pb.id
                {$having}
                {$order_sql}
                LIMIT %d OFFSET %d";

        $query_params = $params;
        $query_params[] = $limit;
        $query_params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);
        if (!is_array($results)) {
            $results = [];
        }

        foreach ($results as &$item) {
            $item['sku_local'] = (string) ($item['canonical_sku'] ?? '');
            $item['sku_online'] = $this->resolve_online_sku($item);
            $item['codigos_proveedor'] = (string) ($item['codigos_proveedor'] ?? '');
            $item['codigos_catalogo'] = (string) ($item['codigos_catalogo'] ?? '');
            $item['completeness_category'] = $this->get_completeness_category($item);
        }
        unset($item);

        if ($having !== '') {
            $count_sql = "SELECT COUNT(*) FROM (
                SELECT pb.id
                FROM {$prefix}producto_base pb
                {$pp_join}
                LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                LEFT JOIN {$prefix}codigo_barra cb ON cb.producto_base_id = pb.id
                WHERE {$where_sql}
                GROUP BY pb.id
                {$having}
            ) AS filtered";
        } else {
            $count_sql = "SELECT COUNT(DISTINCT pb.id) as total
                          FROM {$prefix}producto_base pb
                          {$pp_join}
                          LEFT JOIN {$prefix}equivalence_members em ON em.producto_base_id = pb.id AND em.activo = 1
                          LEFT JOIN {$prefix}codigo_barra cb ON cb.producto_base_id = pb.id
                          WHERE {$where_sql}";
        }

        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        return [
            'items' => array_values($results),
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'pages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        ];
    }

    /**
     * SKU Online (WooCommerce) a partir de product_id / variation_id locales.
     */
    private function resolve_online_sku(array $item) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        $variation_id = (int) ($item['woocommerce_variation_id'] ?? 0);
        $product_id = (int) ($item['woocommerce_product_id'] ?? 0);
        $woo_id = $variation_id > 0 ? $variation_id : $product_id;
        if ($woo_id <= 0) {
            return '';
        }
        $product = wc_get_product($woo_id);
        if (!$product) {
            return '';
        }
        return (string) ($product->get_sku() ?: '');
    }

    /**
     * Genera cláusula HAVING para filtro de completeness
     */
    private function get_completeness_having_clause($completeness) {
        switch ($completeness) {
            case 'completo':
                return "HAVING MAX(pb.canonical_sku) != '' AND MAX(pb.nombre_canonico) != '' AND MAX(pb.woocommerce_product_id) IS NOT NULL AND COUNT(DISTINCT pp.id) > 0";
            case 'publicado':
                return "HAVING MAX(pb.canonical_sku) != '' AND MAX(pb.nombre_canonico) != '' AND MAX(pb.woocommerce_product_id) IS NOT NULL AND COUNT(DISTINCT pp.id) > 0 AND MAX(pb.publication_stage) IN ('approved_for_publication', 'published')";
            case 'falta_online':
                return "HAVING MAX(pb.canonical_sku) != '' AND MAX(pb.nombre_canonico) != '' AND MAX(pb.woocommerce_product_id) IS NULL";
            case 'falta_codigo':
                return "HAVING MAX(pb.canonical_sku) != '' AND MAX(pb.nombre_canonico) != '' AND MAX(pb.woocommerce_product_id) IS NOT NULL AND COUNT(DISTINCT pp.id) = 0";
            case 'solo_online':
                return "HAVING (MAX(pb.canonical_sku) = '' OR MAX(pb.nombre_canonico) = '') AND MAX(pb.woocommerce_product_id) IS NOT NULL";
            case 'solo_online_publicado':
                return "HAVING (MAX(pb.canonical_sku) = '' OR MAX(pb.nombre_canonico) = '') AND MAX(pb.woocommerce_product_id) IS NOT NULL AND MAX(pb.publication_stage) IN ('approved_for_publication', 'published')";
            case 'incompleto':
                return "HAVING (MAX(pb.canonical_sku) = '' OR MAX(pb.nombre_canonico) = '')";
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
            "SELECT pp.*, p.nombre AS proveedor_nombre, c.nombre AS catalogo_nombre
             FROM {$prefix}producto_proveedor pp
             LEFT JOIN {$prefix}proveedores p ON p.id = pp.proveedor_id
             LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
             WHERE pp.producto_base_id = %d
             ORDER BY pp.es_preferido DESC, pp.id DESC",
            $id
        ), ARRAY_A);

        // Enriquecer cada proveedor con fuente_display
        foreach ($product['proveedores'] as &$prov) {
            $prov['fuente_display'] = $this->get_supplier_source_label($prov);
        }

        $product['barcodes'] = $this->get_product_barcodes($id);
        $product['tasks'] = $this->get_product_tasks($id);
        $product['completeness_category'] = $this->get_completeness_category($product);
        $product['proveedores_count'] = count($product['proveedores']);

        // Enriquecer con detalles de producto online (si tiene WooCommerce ID)
        $product['online_details'] = $this->get_online_details($product);
        
        // Enriquecer con precio local
        if (class_exists('Riverso_Pricing_Module')) {
            $product['precio_local'] = Riverso_Pricing_Module::get_instance()->get_local_price($id);
            
            // Enriquecer con precio online si tiene variación o producto Woo
            $var_id = (int) ($product['woocommerce_variation_id'] ?? 0);
            if ($product['woocommerce_product_id'] || $var_id) {
                $product['precio_online'] = Riverso_Pricing_Module::get_instance()->get_online_price($id, $var_id);
            } else {
                $product['precio_online'] = null;
            }
        } else {
            $product['precio_local'] = null;
            $product['precio_online'] = null;
        }
        
        // Enriquecer con familia (equivalence group)
        $product['familia'] = $wpdb->get_row($wpdb->prepare(
            "SELECT eg.id, eg.codigo_grupo, eg.nombre, eg.tipo_sustitucion
             FROM {$prefix}equivalence_groups eg
             INNER JOIN {$prefix}equivalence_members em ON em.grupo_id = eg.id
             WHERE em.producto_base_id = %d AND em.activo = 1 AND eg.activo = 1
             LIMIT 1",
            $id
        ), ARRAY_A) ?: null;

        // Enriquecer con imagen (Fase 7)
        $product['imagen_id'] = (int) ($product['imagen_id'] ?? 0);
        $product['imagen_url'] = '';
        $product['imagen_full'] = '';
        if ($product['imagen_id'] > 0) {
            $product['imagen_url'] = wp_get_attachment_image_url($product['imagen_id'], 'thumbnail');
            $product['imagen_full'] = wp_get_attachment_image_url($product['imagen_id'], 'full');
        }

        // Enriquecer con regla de precio (Fase 9)
        $product['regla_precio'] = null;
        if (class_exists('Riverso_Price_Rules_Module')) {
            $regla = Riverso_Price_Rules_Module::get_instance()->resolve_rule_for_base($id);
            if ($regla) {
                $product['regla_precio'] = [
                    'id' => $regla['id'] ?? null,
                    'nombre' => $regla['nombre'] ?? 'Sin nombre',
                    'origen' => $regla['origen'] ?? 'producto'
                ];
            }
        }

        return $product;
    }

    /**
     * Calcula la etiqueta de fuente para un código proveedor
     */
    private function get_supplier_source_label($proveedor) {
        if (!empty($proveedor['catalogo_id']) && !empty($proveedor['catalogo_nombre'])) {
            return 'Catálogo: ' . $proveedor['catalogo_nombre'];
        }
        
        $origen = $proveedor['origen_datos'] ?? 'manual';
        switch ($origen) {
            case 'factura_intake':
                return 'Facturación';
            case 'mamut_import':
                return 'Catálogo Mamut';
            case 'manual':
                return 'Manual';
            default:
                return ucfirst(str_replace('_', ' ', $origen));
        }
    }

    /**
     * Obtiene detalles del producto online (WooCommerce)
     */
    private function get_online_details($product) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $woo_id = (int) ($product['woocommerce_product_id'] ?? 0);
        $var_id = (int) ($product['woocommerce_variation_id'] ?? 0);
        
        if (!$woo_id) {
            return null;
        }
        
        $details = [
            'type' => null,
            'name' => null,
            'sku' => null,
            'status' => null,
            'price' => null,
            'attributes' => [],
            'parent' => null,
            'siblings' => [],
        ];
        
        // Buscar el producto Woo (puede ser padre o variacion)
        if ($var_id > 0) {
            $woo_product = wc_get_product($var_id);
        } else {
            $woo_product = wc_get_product($woo_id);
        }
        
        if (!$woo_product) {
            return $details;
        }
        
        $details['type'] = $woo_product->get_type();
        $details['name'] = $woo_product->get_name();
        $details['sku'] = $woo_product->get_sku();
        $details['status'] = $woo_product->get_status();
        $details['price'] = (float) $woo_product->get_price();
        
        // Si es variacion, obtener atributos y padre
        if ($woo_product->is_type('variation')) {
            $details['type'] = 'variation';
            $parent_id = (int) $woo_product->get_parent_id();
            $parent = wc_get_product($parent_id);
            
            if ($parent) {
                // Atributos de la variacion
                foreach ($woo_product->get_attributes() as $slug => $value) {
                    $label = wc_attribute_label($slug);
                    $details['attributes'][] = [
                        'name' => $label,
                        'slug' => $slug,
                        'value' => $value,
                    ];
                }
                
                // Datos del padre
                $details['parent'] = [
                    'id' => $parent_id,
                    'name' => $parent->get_name(),
                    'sku' => $parent->get_sku(),
                ];
                
                // Hermanos (otras variaciones del padre)
                $details['siblings'] = [];
                foreach ($parent->get_children() as $sibling_id) {
                    if ($sibling_id === $var_id) {
                        continue; // Skip self
                    }
                    
                    $sibling = wc_get_product($sibling_id);
                    if (!$sibling) {
                        continue;
                    }
                    
                    // Buscar producto_base del hermano
                    $sibling_pb = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, canonical_sku, nombre_canonico FROM {$prefix}producto_base WHERE woocommerce_variation_id = %d LIMIT 1",
                        $sibling_id
                    ), ARRAY_A);
                    
                    $sibling_attrs = [];
                    foreach ($sibling->get_attributes() as $slug => $value) {
                        $sibling_attrs[] = wc_attribute_label($slug) . ': ' . $value;
                    }
                    
                    $details['siblings'][] = [
                        'variation_id' => $sibling_id,
                        'name' => $sibling->get_name(),
                        'sku_online' => $sibling->get_sku(),
                        'attributes_text' => implode(', ', $sibling_attrs),
                        'sku_local' => $sibling_pb['canonical_sku'] ?? '',
                        'has_local_sku' => !empty($sibling_pb['canonical_sku']),
                        'producto_base_id' => (int) ($sibling_pb['id'] ?? 0),
                    ];
                }
            }
        } elseif ($woo_product->is_type('variable')) {
            // Es un producto padre variable
            $details['type'] = 'variable';
            
            // Atributos de variacion
            foreach ($woo_product->get_attributes() as $attr) {
                if ($attr->get_variation()) {
                    $label = $attr->get_name();
                    $options = $attr->get_options();
                    if ($attr->is_taxonomy()) {
                        $options = array_map(function ($term_id) {
                            $term = get_term($term_id);
                            return ($term && !is_wp_error($term)) ? $term->name : (string) $term_id;
                        }, $options);
                    }
                    
                    $details['attributes'][] = [
                        'name' => $label,
                        'options' => array_values($options),
                    ];
                }
            }
        }
        
        return $details;
    }

    private function get_product_barcodes($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $barcodes = [];
        if (class_exists('Riverso_Barcode_Model')) {
            $barcodes = Riverso_Barcode_Model::get_by_product($product_id);
            
            // Enriquecer con nombre de proveedor si aplica
            foreach ($barcodes as &$barcode) {
                if (!empty($barcode['proveedor_id'])) {
                    $proveedor = $wpdb->get_row($wpdb->prepare(
                        "SELECT nombre FROM {$prefix}proveedores WHERE id = %d",
                        (int) $barcode['proveedor_id']
                    ), ARRAY_A);
                    $barcode['proveedor_nombre'] = $proveedor['nombre'] ?? '';
                }
            }
        }
        return is_array($barcodes) ? $barcodes : [];
    }

    private function get_product_tasks($product_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
		$tasks = $wpdb->get_results($wpdb->prepare(
			"SELECT id, tipo, titulo, estado, prioridad, fecha_limite, referencia_tipo, referencia_id, datos_extra
			 FROM {$prefix}tareas
			 WHERE referencia_tipo = 'producto_base' AND referencia_id = %d
			 AND estado IN ('pendiente', 'asignado')
			 ORDER BY prioridad DESC, id DESC
			 LIMIT 10",
			$product_id
		), ARRAY_A) ?: [];

		// Agregar target_url y decodificar datos_extra para cada tarea
		foreach ($tasks as &$task) {
			$task['datos_extra'] = json_decode( $task['datos_extra'] ?? '{}', true );
			$task['target_url'] = riverso_resolve_task_target($task);
		}
        
        return $tasks;
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
            // Permitir vacío: productos de catálogo pendientes de SKU Local
            $payload['canonical_sku'] = null;
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
            'catalog_id' => absint($_POST['catalog_id'] ?? 0),
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
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
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
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
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
        $tipo = sanitize_text_field($_POST['tipo'] ?? 'ean13');
        $proveedor_id = absint($_POST['proveedor_id'] ?? 0);
        $cantidad = floatval($_POST['cantidad'] ?? 1);
        $unidad_medida = sanitize_text_field($_POST['unidad_medida'] ?? 'unidad');
        $envase_id = absint($_POST['envase_id'] ?? 0);
        $origen_datos = sanitize_text_field($_POST['origen_datos'] ?? 'manual');

        if (!$product_id || !$barcode) {
            wp_send_json_error(['message' => 'Parámetros inválidos']);
        }

        if (!class_exists('Riverso_Barcode_Model')) {
            wp_send_json_error(['message' => 'Módulo de barcodes no disponible']);
        }

        $allowed_tipos = ['ean13', 'supplier', 'internal'];
        if (!in_array($tipo, $allowed_tipos, true)) {
            $tipo = 'ean13';
        }

        $barcode_id = Riverso_Barcode_Model::create(
            $barcode,
            $tipo,
            $product_id,
            $cantidad > 0 ? $cantidad : 1,
            $unidad_medida ?: 'unidad',
            $proveedor_id > 0 ? $proveedor_id : null,
            $envase_id > 0 ? $envase_id : null
        );

        if (!$barcode_id) {
            wp_send_json_error(['message' => 'No se pudo crear el código de barra']);
        }

        // Persistir origen_datos si difiere del default del modelo
        if ($origen_datos && $origen_datos !== 'manual') {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'riverso_codigo_barra',
                ['origen_datos' => $origen_datos],
                ['id' => (int) $barcode_id],
                ['%s'],
                ['%d']
            );
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('barcode_assigned', 'codigo_barra', (int) $barcode_id, [
                'actor_type' => 'human',
                'producto_base_id' => $product_id,
                'codigo' => $barcode,
                'tipo' => $tipo,
                'razon' => $audit_reason,
            ]);
        }

        $item = $this->get_product($product_id);
        wp_send_json_success([
            'message' => 'Código de barra agregado',
            'barcode_id' => (int) $barcode_id,
            'item' => $item,
        ]);
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
            // Atributos dinámicos desde el array JSON
            $attributes = isset($_POST['attributes']) ? json_decode(stripslashes($_POST['attributes']), true) : [];
            if (!is_array($attributes)) {
                $attributes = [];
            }
            $result = $publisher->create_woo_variable_from_base($product_id, $woo_name, $woo_sku, $attributes);
        } elseif ($product_type === 'child') {
            // Asignar como hijo a un padre existente
            $parent_id = absint($_POST['parent_id'] ?? 0);
            $attach_mode = sanitize_text_field($_POST['attach_mode'] ?? 'create');
            
            if (!$parent_id) {
                wp_send_json_error(['message' => 'Padre variable no especificado']);
            }
            
            $result = $publisher->attach_base_to_variable_parent($product_id, $parent_id, $woo_sku, $attach_mode);
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

    /**
     * Resuelve el ID WooCommerce del padre variable a partir de un producto_base
     * (puede ser el padre mismo o un hijo/variación).
     *
     * @param object $pb Fila producto_base (woocommerce_product_id, woocommerce_variation_id)
     * @return int
     */
    private function resolve_variable_parent_id($pb) {
        if (!$pb) {
            return 0;
        }

        $variation_id = (int) ($pb->woocommerce_variation_id ?? 0);
        if ($variation_id > 0) {
            $variation = wc_get_product($variation_id);
            if ($variation && $variation->is_type('variation')) {
                return (int) $variation->get_parent_id();
            }
        }

        $product_id = (int) ($pb->woocommerce_product_id ?? 0);
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if (!$product) {
                return 0;
            }
            if ($product->is_type('variable')) {
                return $product_id;
            }
            if ($product->is_type('variation')) {
                return (int) $product->get_parent_id();
            }
        }

        return 0;
    }

    /**
     * Enriquece una sugerencia de padre variable con metadatos locales.
     */
    private function build_parent_suggestion($parent_id, $match_hint = '', $source = 'local') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $product = wc_get_product($parent_id);
        if (!$product || !$product->is_type('variable')) {
            return null;
        }

        $child_ids = array_map('intval', $product->get_children() ?: []);
        $lookup_ids = array_values(array_unique(array_filter(array_merge([$parent_id], $child_ids))));
        $in = implode(',', $lookup_ids);

        $meta = $wpdb->get_row(
            "SELECT
                MAX(CASE WHEN pb.woocommerce_product_id = {$parent_id}
                    AND (pb.woocommerce_variation_id IS NULL OR pb.woocommerce_variation_id = 0)
                    THEN pb.canonical_sku END) AS canonical_sku,
                GROUP_CONCAT(DISTINCT pp.codigo_proveedor SEPARATOR ', ') AS codigos,
                MAX(c.nombre) AS catalogo_nombre
             FROM {$prefix}producto_base pb
             LEFT JOIN {$prefix}producto_proveedor pp
                ON pp.producto_base_id = pb.id
             LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
             WHERE pb.deleted_at IS NULL
               AND (
                    pb.woocommerce_product_id = {$parent_id}
                    OR pb.woocommerce_variation_id IN ({$in})
               )"
        );

        return [
            'id' => (int) $parent_id,
            'name' => $product->get_name(),
            'sku' => $product->get_sku() ?: ($meta->canonical_sku ?? ''),
            'sku_online' => $product->get_sku() ?: '',
            'sku_local' => $meta->canonical_sku ?? '',
            'child_count' => count($child_ids),
            'catalogo' => $meta->catalogo_nombre ?? 'Sin catálogo',
            'codigo_catalogo' => $meta->codigos ?? '',
            'match_hint' => $match_hint,
            'source' => $source,
        ];
    }

    /**
     * AJAX: Sugerir padres variables (por nombre y por SKU de hijos / catálogo)
     */
    public function ajax_suggest_variable_parents() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $search = sanitize_text_field($_POST['search'] ?? '');
        $catalog_id = absint($_POST['catalog_id'] ?? 0);

        if (strlen($search) < 1) {
            wp_send_json_success(['suggestions' => []]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $sku_compact = preg_replace('/[^A-Za-z0-9]/', '', $search);
        $sku_compact_like = $sku_compact !== ''
            ? '%' . $wpdb->esc_like($sku_compact) . '%'
            : $like;

        $suggestions_by_id = [];

        // 1) Buscar por hijos / catálogo: codigo_proveedor, nombre proveedor, SKU local, nombre
        $where = [
            'pb.deleted_at IS NULL',
            '(
                pp.codigo_proveedor LIKE %s
                OR REPLACE(REPLACE(REPLACE(pp.codigo_proveedor, "-", ""), " ", ""), "/", "") LIKE %s
                OR pp.nombre_proveedor LIKE %s
                OR pb.canonical_sku LIKE %s
                OR pb.nombre_canonico LIKE %s
            )',
        ];
        $params = [$like, $sku_compact_like, $like, $like, $like];

        if ($catalog_id > 0) {
            $where[] = 'pp.catalogo_id = %d';
            $params[] = $catalog_id;
        }

        $pp_activo_sql = ($catalog_id > 0) ? '1=1' : 'pp.activo = 1';

        $where_sql = implode(' AND ', $where);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pb.id, pb.nombre_canonico, pb.canonical_sku,
                        pb.woocommerce_product_id, pb.woocommerce_variation_id,
                        pp.codigo_proveedor, pp.nombre_proveedor,
                        c.nombre AS catalogo_nombre
                 FROM {$prefix}producto_base pb
                 INNER JOIN {$prefix}producto_proveedor pp
                    ON pp.producto_base_id = pb.id AND {$pp_activo_sql}
                 LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
                 WHERE {$where_sql}
                 ORDER BY
                    CASE
                        WHEN pp.codigo_proveedor = %s THEN 0
                        WHEN pp.codigo_proveedor LIKE %s THEN 1
                        ELSE 2
                    END ASC
                 LIMIT 80",
                array_merge($params, [$search, $wpdb->esc_like($search) . '%'])
            )
        );

        foreach ($rows ?: [] as $row) {
            $parent_id = $this->resolve_variable_parent_id($row);
            if ($parent_id <= 0 || isset($suggestions_by_id[$parent_id])) {
                continue;
            }

            $hint_parts = [];
            if (!empty($row->codigo_proveedor) && stripos((string) $row->codigo_proveedor, $search) !== false) {
                $hint_parts[] = 'SKU Catálogo hijo: ' . $row->codigo_proveedor;
            } elseif (!empty($row->canonical_sku) && stripos((string) $row->canonical_sku, $search) !== false) {
                $hint_parts[] = 'SKU Local hijo: ' . $row->canonical_sku;
            } elseif (!empty($row->nombre_proveedor) && stripos((string) $row->nombre_proveedor, $search) !== false) {
                $hint_parts[] = 'Nombre catálogo: ' . $row->nombre_proveedor;
            } else {
                $hint_parts[] = 'Coincide en familia';
            }

            $suggestion = $this->build_parent_suggestion($parent_id, implode(' | ', $hint_parts), 'local_child');
            if ($suggestion) {
                if ($catalog_id > 0 && empty($suggestion['codigo_catalogo']) && !empty($row->codigo_proveedor)) {
                    $suggestion['codigo_catalogo'] = $row->codigo_proveedor;
                    $suggestion['catalogo'] = $row->catalogo_nombre ?: $suggestion['catalogo'];
                }
                $suggestions_by_id[$parent_id] = $suggestion;
            }

            if (count($suggestions_by_id) >= 10) {
                break;
            }
        }

        // 2) Ampliar: padres Woo cuyo nombre/SKU coincide, con hijos en el catálogo filtrado
        if (count($suggestions_by_id) < 10) {
            $woo_product_ids = wc_get_products([
                'type' => 'variable',
                'status' => ['publish', 'draft', 'private'],
                's' => $search,
                'limit' => 20,
                'return' => 'ids',
            ]);

            foreach ($woo_product_ids as $pid) {
                if (isset($suggestions_by_id[$pid])) {
                    continue;
                }

                if ($catalog_id > 0) {
                    $product = wc_get_product($pid);
                    if (!$product) {
                        continue;
                    }
                    $ids = array_map('intval', array_merge([$pid], $product->get_children() ?: []));
                    $ids = array_filter($ids);
                    if (!$ids) {
                        continue;
                    }
                    $in = implode(',', $ids);
                    $in_catalog = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*)
                             FROM {$prefix}producto_base pb
                             INNER JOIN {$prefix}producto_proveedor pp
                                ON pp.producto_base_id = pb.id AND pp.catalogo_id = %d
                             WHERE pb.deleted_at IS NULL
                               AND (
                                    pb.woocommerce_product_id IN ({$in})
                                    OR pb.woocommerce_variation_id IN ({$in})
                               )",
                            $catalog_id
                        )
                    );
                    if ($in_catalog < 1) {
                        continue;
                    }
                }

                $suggestion = $this->build_parent_suggestion(
                    (int) $pid,
                    $catalog_id > 0 ? 'Nombre padre + familia en catálogo' : 'Búsqueda WooCommerce',
                    'woocommerce'
                );
                if ($suggestion) {
                    $suggestions_by_id[$pid] = $suggestion;
                }
                if (count($suggestions_by_id) >= 10) {
                    break;
                }
            }
        }

        wp_send_json_success(['suggestions' => array_values($suggestions_by_id)]);
    }

    /**
     * AJAX: Obtener atributos de variación de un padre
     */
    public function ajax_get_variable_attributes() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $parent_id = absint($_POST['parent_id'] ?? 0);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'ID de padre no válido']);
        }

        $product = wc_get_product($parent_id);
        if (!$product || $product->get_type() !== 'variable') {
            wp_send_json_error(['message' => 'Producto no es variable']);
        }

        $attributes = [];
        foreach ($product->get_attributes() as $attr) {
            if ($attr->get_variation()) {
                $attributes[] = [
                    'name' => $attr->get_name(),
                    'options' => $attr->get_options(),
                ];
            }
        }

        wp_send_json_success(['attributes' => $attributes]);
    }

    /**
     * AJAX: Detalle de padre variable: atributos + hijos con SKU Local/Online/Catálogo
     */
    public function ajax_get_variable_parent_details() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $parent_id = absint($_POST['parent_id'] ?? 0);
        if (!$parent_id) {
            wp_send_json_error(['message' => 'ID de padre no válido']);
        }

        $product = wc_get_product($parent_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error(['message' => 'Producto no es variable']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $attributes = [];
        foreach ($product->get_attributes() as $attr) {
            if ($attr->get_variation()) {
                $label = $attr->get_name();
                if (strpos($label, 'pa_') === 0) {
                    $tax = $attr->get_taxonomy_object();
                    $label = $tax && !empty($tax->attribute_label) ? $tax->attribute_label : $label;
                }
                $options = $attr->get_options();
                if ($attr->is_taxonomy()) {
                    $options = array_map(function ($term_id) {
                        $term = get_term($term_id);
                        return ($term && !is_wp_error($term)) ? $term->name : (string) $term_id;
                    }, $options);
                }
                $attributes[] = [
                    'name' => $label,
                    'slug' => $attr->get_name(),
                    'options' => array_values($options),
                ];
            }
        }

        $child_ids = $product->get_children();
        $children = [];

        foreach ($child_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $var_attrs = [];
            foreach ($variation->get_attributes() as $slug => $value) {
                $label = $slug;
                if (strpos($slug, 'pa_') === 0) {
                    $tax = get_taxonomy($slug);
                    $label = ($tax && !empty($tax->labels->singular_name))
                        ? $tax->labels->singular_name
                        : wc_attribute_label($slug);
                    if ($value) {
                        $term = get_term_by('slug', $value, $slug);
                        $value = ($term && !is_wp_error($term)) ? $term->name : $value;
                    }
                } else {
                    $label = wc_attribute_label($slug, $product);
                }
                $var_attrs[] = [
                    'name' => $label,
                    'slug' => $slug,
                    'value' => $value,
                ];
            }

            $pb = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                     FROM {$prefix}producto_base
                     WHERE deleted_at IS NULL
                       AND (
                            woocommerce_variation_id = %d
                            OR (woocommerce_product_id = %d AND (woocommerce_variation_id IS NULL OR woocommerce_variation_id = 0))
                       )
                     ORDER BY CASE WHEN woocommerce_variation_id = %d THEN 0 ELSE 1 END
                     LIMIT 1",
                    $variation_id,
                    $variation_id,
                    $variation_id
                )
            );

            // Fallback: match por SKU online = canonical_sku
            if (!$pb) {
                $woo_sku = $variation->get_sku();
                if ($woo_sku !== '') {
                    $pb = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id, canonical_sku, nombre_canonico, woocommerce_product_id, woocommerce_variation_id
                             FROM {$prefix}producto_base
                             WHERE deleted_at IS NULL AND canonical_sku = %s
                             LIMIT 1",
                            $woo_sku
                        )
                    );
                }
            }

            $catalog_codes = [];
            $sku_local = '';
            $producto_base_id = null;
            if ($pb) {
                $producto_base_id = (int) $pb->id;
                $sku_local = (string) ($pb->canonical_sku ?? '');
                $pp_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT pp.codigo_proveedor, pp.nombre_proveedor, c.nombre AS catalogo_nombre
                         FROM {$prefix}producto_proveedor pp
                         LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
                         WHERE pp.producto_base_id = %d
                         ORDER BY pp.id ASC",
                        $producto_base_id
                    )
                );
                foreach ($pp_rows ?: [] as $pp) {
                    if ($pp->codigo_proveedor === null || $pp->codigo_proveedor === '') {
                        continue;
                    }
                    $catalog_codes[] = [
                        'codigo' => $pp->codigo_proveedor,
                        'nombre' => $pp->nombre_proveedor ?: '',
                        'catalogo' => $pp->catalogo_nombre ?: '',
                    ];
                }
            }

            $sku_online = (string) ($variation->get_sku() ?: '');
            $sku_labels = [];
            if ($sku_local !== '') {
                $sku_labels[] = [
                    'type' => 'local',
                    'label' => 'SKU Local',
                    'value' => $sku_local,
                ];
            }
            if ($sku_online !== '') {
                $sku_labels[] = [
                    'type' => 'online',
                    'label' => 'SKU Online',
                    'value' => $sku_online,
                ];
            }
            foreach ($catalog_codes as $cc) {
                $sku_labels[] = [
                    'type' => 'catalogo',
                    'label' => 'SKU Catálogo',
                    'value' => $cc['codigo'],
                    'catalogo' => $cc['catalogo'],
                ];
            }
            if (empty($sku_labels)) {
                $sku_labels[] = [
                    'type' => 'otro',
                    'label' => 'Sin SKU',
                    'value' => '',
                ];
            }

            $children[] = [
                'variation_id' => (int) $variation_id,
                'name' => $variation->get_name(),
                'attributes' => $var_attrs,
                'attributes_text' => implode(' · ', array_map(function ($a) {
                    return $a['name'] . ': ' . $a['value'];
                }, $var_attrs)),
                'sku_local' => $sku_local,
                'sku_online' => $sku_online,
                'sku_catalogo' => array_map(function ($c) {
                    return $c['codigo'];
                }, $catalog_codes),
                'sku_labels' => $sku_labels,
                'has_local_sku' => $sku_local !== '',
                'producto_base_id' => $producto_base_id,
                'status' => $variation->get_status(),
            ];
        }

        // Orden: primero sin SKU local, luego con SKU local
        usort($children, function ($a, $b) {
            if ($a['has_local_sku'] === $b['has_local_sku']) {
                return strcmp($a['attributes_text'], $b['attributes_text']);
            }
            return $a['has_local_sku'] ? 1 : -1;
        });

        $with_local = count(array_filter($children, function ($c) {
            return $c['has_local_sku'];
        }));

        wp_send_json_success([
            'parent' => [
                'id' => (int) $parent_id,
                'name' => $product->get_name(),
                'sku_online' => $product->get_sku() ?: '',
                'child_count' => count($children),
                'with_local_sku' => $with_local,
                'without_local_sku' => count($children) - $with_local,
            ],
            'attributes' => $attributes,
            'children' => $children,
        ]);
    }

    /**
     * AJAX: Buscar productos dentro de un catálogo con sugerencias fuzzy.
     * Prioriza SKU de catálogo (codigo_proveedor) y nombres parecidos.
     */
    public function ajax_search_catalog_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        $catalog_id = absint($_POST['catalog_id'] ?? 0);
        $search = sanitize_text_field($_POST['search'] ?? '');
        $limit = min(20, max(1, absint($_POST['limit'] ?? 10)));

        if (strlen($search) < 1) {
            wp_send_json_success(['products' => []]);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $prefix_like = $wpdb->esc_like($search) . '%';
        $sku_compact = preg_replace('/[^A-Za-z0-9]/', '', $search);
        $sku_compact_like = $sku_compact !== ''
            ? '%' . $wpdb->esc_like($sku_compact) . '%'
            : $like;

        $where = [
            '(
                pp.codigo_proveedor LIKE %s
                OR pp.codigo_proveedor LIKE %s
                OR REPLACE(REPLACE(REPLACE(pp.codigo_proveedor, "-", ""), " ", ""), "/", "") LIKE %s
                OR pp.nombre_proveedor LIKE %s
                OR pb.canonical_sku LIKE %s
            )',
        ];
        $params = [$like, $prefix_like, $sku_compact_like, $like, $like];

        if ($catalog_id > 0) {
            $where[] = 'pp.catalogo_id = %d';
            $params[] = $catalog_id;
        } else {
            $where[] = 'pp.activo = 1';
        }

        $where_sql = implode(' AND ', $where);
        $fetch_limit = max($limit * 5, 40);
        $query_params = array_merge($params, [$search, $prefix_like, $sku_compact_like, $fetch_limit]);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor,
                        pb.id as producto_base_id, pb.canonical_sku,
                        c.nombre as catalogo_nombre
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
                 WHERE {$where_sql}
                 ORDER BY
                    CASE
                        WHEN pp.codigo_proveedor = %s THEN 0
                        WHEN pp.codigo_proveedor LIKE %s THEN 1
                        WHEN REPLACE(REPLACE(REPLACE(pp.codigo_proveedor, '-', ''), ' ', ''), '/', '') LIKE %s THEN 2
                        ELSE 3
                    END ASC,
                    pp.codigo_proveedor ASC
                 LIMIT %d",
                $query_params
            )
        );

        if (!is_array($results)) {
            $results = [];
        }

        if (count($results) < $limit) {
            $extra_clauses = [];
            $extra_params = [];

            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            if (count($tokens) > 1) {
                foreach ($tokens as $token) {
                    if (strlen($token) < 2) {
                        continue;
                    }
                    $tlike = '%' . $wpdb->esc_like($token) . '%';
                    $extra_clauses[] = '(pp.codigo_proveedor LIKE %s OR pp.nombre_proveedor LIKE %s)';
                    $extra_params[] = $tlike;
                    $extra_params[] = $tlike;
                }
            }

            if (strlen($sku_compact) >= 2) {
                $short = substr($sku_compact, 0, min(4, strlen($sku_compact)));
                $extra_clauses[] = 'REPLACE(REPLACE(REPLACE(pp.codigo_proveedor, "-", ""), " ", ""), "/", "") LIKE %s';
                $extra_params[] = $wpdb->esc_like($short) . '%';
            }

            if ($extra_clauses) {
                $extra_where = ['(' . implode(' OR ', $extra_clauses) . ')'];
                if ($catalog_id > 0) {
                    $extra_where[] = 'pp.catalogo_id = %d';
                    $extra_params[] = $catalog_id;
                } else {
                    $extra_where[] = 'pp.activo = 1';
                }
                $extra_params[] = $fetch_limit;
                $extra = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor,
                                pb.id as producto_base_id, pb.canonical_sku,
                                c.nombre as catalogo_nombre
                         FROM {$prefix}producto_proveedor pp
                         LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                         LEFT JOIN {$prefix}catalogos c ON c.id = pp.catalogo_id
                         WHERE " . implode(' AND ', $extra_where) . "
                         LIMIT %d",
                        $extra_params
                    )
                );
                $seen = [];
                foreach ($results as $row) {
                    $seen[(int) $row->id] = true;
                }
                foreach ((array) $extra as $row) {
                    if (empty($seen[(int) $row->id])) {
                        $results[] = $row;
                    }
                }
            }
        }

        if ($results) {
            $search_lower = strtolower($search);
            $compact_lower = strtolower($sku_compact);
            usort($results, function ($a, $b) use ($search_lower, $compact_lower) {
                $score = function ($row) use ($search_lower, $compact_lower) {
                    $code = strtolower((string) ($row->codigo_proveedor ?? ''));
                    $name = strtolower((string) ($row->nombre_proveedor ?? ''));
                    $code_compact = preg_replace('/[^a-z0-9]/', '', $code);
                    $s = 0;
                    if ($code === $search_lower) {
                        $s += 10000;
                    }
                    if ($compact_lower !== '' && $code_compact === $compact_lower) {
                        $s += 8000;
                    }
                    if (strpos($code, $search_lower) === 0) {
                        $s += 5000;
                    }
                    if ($compact_lower !== '' && strpos($code_compact, $compact_lower) === 0) {
                        $s += 4000;
                    }
                    if (strpos($code, $search_lower) !== false) {
                        $s += 2000;
                    }
                    similar_text($search_lower, $code, $pct_code);
                    similar_text($search_lower, $name, $pct_name);
                    $s += (int) ($pct_code * 100);
                    $s += (int) ($pct_name * 40);
                    if ($compact_lower !== '') {
                        similar_text($compact_lower, $code_compact, $pct_compact);
                        $s += (int) ($pct_compact * 80);
                    }
                    return $s;
                };
                return $score($b) <=> $score($a);
            });
        }

        wp_send_json_success(['products' => array_slice($results, 0, $limit)]);
    }

    /**
     * AJAX: Guardar precio local (p_asignado) de un producto.
     */
    public function ajax_set_local_price() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_prices') && !current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $precio_id = absint($_POST['precio_id'] ?? 0);
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);

        if (!$precio_id) {
            wp_send_json_error(['message' => 'precio_id requerido']);
        }

        if (!class_exists('Riverso_Pricing_Module')) {
            wp_send_json_error(['message' => 'Módulo de precios no disponible']);
        }

        // Obtener precio anterior para auditoría
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $precio_anterior = $wpdb->get_var($wpdb->prepare(
            "SELECT p_asignado FROM {$prefix}precios WHERE id = %d",
            $precio_id
        ));

        $result = Riverso_Pricing_Module::get_instance()->set_assigned_price($precio_id, $p_asignado);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Auditar cambio de precio local
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('price_changed', 'precio', $precio_id, [
                'old_value' => $precio_anterior,
                'new_value' => $p_asignado,
                'details' => 'Precio Local actualizado',
            ]);
        }

        // Retornar el precio actualizado
        $precio_actualizado = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}precios WHERE id = %d",
            $precio_id
        ), ARRAY_A);
        wp_send_json_success(['item' => $precio_actualizado]);
    }

    /**
     * AJAX: Guardar precio online (p_asignado) de un producto/variación WooCommerce.
     */
    public function ajax_set_online_price() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_prices') && !current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $woocommerce_variation_id = absint($_POST['woocommerce_variation_id'] ?? 0);
        $p_asignado = floatval($_POST['p_asignado'] ?? 0);
        $sync_to_woo = !empty($_POST['sync_to_woo']);

        if (!$producto_base_id) {
            wp_send_json_error(['message' => 'producto_base_id requerido']);
        }

        if (!class_exists('Riverso_Pricing_Module')) {
            wp_send_json_error(['message' => 'Módulo de precios no disponible']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Obtener o crear el registro de precio online
        $precio = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}precios WHERE producto_base_id = %d AND canal = %s AND woocommerce_variation_id = %d",
            $producto_base_id,
            'online',
            $woocommerce_variation_id
        ), ARRAY_A);

        if (!$precio) {
            // Crear nuevo registro
            $wpdb->insert(
                "{$prefix}precios",
                [
                    'producto_base_id' => $producto_base_id,
                    'canal' => 'online',
                    'woocommerce_variation_id' => $woocommerce_variation_id,
                    'p_asignado' => $p_asignado,
                    'created_by_system' => 0,
                ],
                ['%d', '%s', '%d', '%f', '%d']
            );
            $precio_id = $wpdb->insert_id;
        } else {
            $precio_id = $precio['id'];
            // Actualizar precio existente
            $result = Riverso_Pricing_Module::get_instance()->set_assigned_price($precio_id, $p_asignado);
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }
        }

        // Sincronizar a WooCommerce si se solicita
        if ($sync_to_woo) {
            if ($woocommerce_variation_id > 0) {
                $product = wc_get_product($woocommerce_variation_id);
            } else {
                $product = wc_get_product(intval($wpdb->get_var($wpdb->prepare(
                    "SELECT woocommerce_product_id FROM {$prefix}producto_base WHERE id = %d",
                    $producto_base_id
                ))));
            }

            if ($product && method_exists($product, 'set_regular_price')) {
                $product->set_regular_price((string) $p_asignado);
                if (!$product->get_sale_price()) {
                    $product->set_price((string) $p_asignado);
                }
                $product->save();
            }
        }

        // Auditar cambio de precio online
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('price_changed', 'precio', $precio_id, [
                'old_value' => $precio ? $precio['p_asignado'] : null,
                'new_value' => $p_asignado,
                'details' => 'Precio Online actualizado' . ($sync_to_woo ? ' + sincronizado a WooCommerce' : ''),
            ]);
        }

        // Retornar el precio actualizado
        $precio_actualizado = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}precios WHERE id = %d",
            $precio_id
        ), ARRAY_A);
        wp_send_json_success(['item' => $precio_actualizado]);
    }

    // ============= FASE 6: CATEGORÍAS ONLINE =============
    
    /**
     * AJAX: Obtener categorías actuales de un producto WooCommerce
     */
    public function ajax_get_product_categories() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_categories')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $woo_id = absint($_POST['woocommerce_product_id'] ?? 0);
        if (!$woo_id) {
            wp_send_json_error(['message' => 'woocommerce_product_id requerido']);
        }

        $product = wc_get_product($woo_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Producto WooCommerce no encontrado']);
        }

        $current_cats = wp_get_post_terms($woo_id, 'product_cat', ['fields' => 'ids']);
        wp_send_json_success(['current_categories' => $current_cats ?: []]);
    }

    /**
     * AJAX: Asignar categorías a un producto WooCommerce
     */
    public function ajax_set_product_categories() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_categories')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $woo_id = absint($_POST['woocommerce_product_id'] ?? 0);
        $cat_ids = array_map('absint', (array) ($_POST['category_ids'] ?? []));

        if (!$woo_id) {
            wp_send_json_error(['message' => 'woocommerce_product_id requerido']);
        }

        // Obtener categorías anteriores para auditoría
        $old_cats = wp_get_post_terms($woo_id, 'product_cat', ['fields' => 'ids']);

        wp_set_object_terms($woo_id, $cat_ids, 'product_cat');

        // Auditar cambio de categorías
        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_categories_updated', 'product', $woo_id, [
                'old_value' => $old_cats,
                'new_value' => $cat_ids,
            ]);
        }

        wp_send_json_success(['message' => 'Categorías asignadas exitosamente']);
    }

    /**
     * AJAX: Obtener árbol jerárquico de categorías WooCommerce (recursivo completo)
     */
    public function ajax_get_category_tree() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_categories')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $parent_id = absint($_POST['parent_id'] ?? 0);

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => $parent_id,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($categories)) {
            wp_send_json_error(['message' => $categories->get_error_message()]);
        }

        // Construir árbol recursivo
        $tree = array_map(function($cat) {
            return $this->build_category_tree_recursive($cat);
        }, $categories);

        wp_send_json_success(['tree' => $tree]);
    }

    /**
     * Construye el árbol de categorías de forma recursiva
     */
    private function build_category_tree_recursive($cat) {
        $children_terms = get_terms([
            'taxonomy' => 'product_cat',
            'parent' => $cat->term_id,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        $children = [];
        if (!is_wp_error($children_terms) && !empty($children_terms)) {
            $children = array_map(function($child) {
                return $this->build_category_tree_recursive($child);
            }, $children_terms);
        }

        return [
            'id' => $cat->term_id,
            'name' => $cat->name,
            'parent' => $cat->parent,
            'count' => $cat->count,
            'children' => $children
        ];
    }

    // ============= FASE 7: IMAGEN LOCAL (MEDIA PICKER) =============

    /**
     * AJAX: Asignar imagen a un producto local
     */
    public function ajax_set_image() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $producto_id = absint($_POST['producto_id'] ?? 0);
        $imagen_id = absint($_POST['imagen_id'] ?? 0);

        if (!$producto_id) {
            wp_send_json_error(['message' => 'producto_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Verificar que el producto existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}producto_base WHERE id = %d",
            $producto_id
        ));

        if (!$exists) {
            wp_send_json_error(['message' => 'Producto no encontrado']);
        }

        // Actualizar imagen_id
        $wpdb->update(
            "{$prefix}producto_base",
            ['imagen_id' => $imagen_id ?: null],
            ['id' => $producto_id],
            ['%d'],
            ['%d']
        );

        // Obtener la URL de la imagen si existe
        $imagen_url = '';
        $imagen_full = '';
        if ($imagen_id > 0) {
            $imagen_url = wp_get_attachment_image_url($imagen_id, 'thumbnail');
            $imagen_full = wp_get_attachment_image_url($imagen_id, 'full');
        }

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('product_image_set', 'producto_base', $producto_id, [
                'imagen_id' => $imagen_id,
            ]);
        }

		wp_send_json_success([
			'message' => 'Imagen actualizada exitosamente',
			'imagen_id' => $imagen_id,
			'imagen_url' => $imagen_url,
			'imagen_full' => $imagen_full,
		]);
	}

	public function ajax_create_category() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_categories')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$name = sanitize_text_field($_POST['name'] ?? '');
		$parent_id = absint($_POST['parent_id'] ?? 0);

		if (empty($name)) {
			wp_send_json_error(['message' => 'Nombre de categoría requerido'], 400);
		}

		$term = wp_insert_term($name, 'product_cat', ['parent' => $parent_id]);

		if (is_wp_error($term)) {
			wp_send_json_error(['message' => $term->get_error_message()], 400);
		}

		// Auditar creación de categoría
		if (class_exists('Riverso_POS_Audit')) {
			Riverso_POS_Audit::log('category_created', 'product_cat', $term['term_id'], [
				'entity_name' => $name,
				'parent_id' => $parent_id,
			]);
		}

		wp_send_json_success(['term_id' => $term['term_id'], 'name' => $name, 'parent' => $parent_id]);
	}

	public function ajax_rename_category() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_categories')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$term_id = absint($_POST['term_id'] ?? 0);
		$name = sanitize_text_field($_POST['name'] ?? '');

		if ($term_id <= 0 || empty($name)) {
			wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
		}

		// Obtener nombre anterior para auditoría
		$term = get_term($term_id, 'product_cat');
		$old_name = $term ? $term->name : '';

		$result = wp_update_term($term_id, 'product_cat', ['name' => $name]);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		// Auditar cambio de nombre de categoría
		if (class_exists('Riverso_POS_Audit')) {
			Riverso_POS_Audit::log('category_renamed', 'product_cat', $term_id, [
				'old_value' => $old_name,
				'new_value' => $name,
				'entity_name' => $name,
			]);
		}

		wp_send_json_success(['term_id' => $term_id, 'name' => $name]);
	}

	/**
	 * AJAX: Obtener impacto de cambios en una categoría (productos afectados)
	 */
	public function ajax_category_impact() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_categories')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$term_id = absint($_POST['term_id'] ?? 0);
		if ($term_id <= 0) {
			wp_send_json_error(['message' => 'ID de categoría requerido'], 400);
		}

		// Contar productos directos en esta categoría
		$direct_products = intval(get_term($term_id, 'product_cat')->count ?? 0);

		// Obtener hijos
		$children = get_terms([
			'taxonomy' => 'product_cat',
			'parent' => $term_id,
			'hide_empty' => false,
			'fields' => 'ids',
		]);
		$children_count = !is_wp_error($children) ? count($children) : 0;

		// Contar productos en toda la rama (recursivo)
		$all_affected = $this->count_products_in_category_tree($term_id);

		wp_send_json_success([
			'direct_products' => $direct_products,
			'children_count' => $children_count,
			'total_products' => $all_affected
		]);
	}

	/**
	 * Cuenta recursivamente productos en una categoría y sus subcategorías
	 */
	private function count_products_in_category_tree($term_id) {
		$count = get_term($term_id, 'product_cat')->count ?? 0;

		$children = get_terms([
			'taxonomy' => 'product_cat',
			'parent' => $term_id,
			'hide_empty' => false,
			'fields' => 'ids',
		]);

		if (!is_wp_error($children) && !empty($children)) {
			foreach ($children as $child_id) {
				$count += $this->count_products_in_category_tree($child_id);
			}
		}

		return intval($count);
	}

	/**
	 * AJAX: Mover una categoría a un nuevo padre (cambiar nivel)
	 */
	public function ajax_move_category() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_categories')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$term_id = absint($_POST['term_id'] ?? 0);
		$new_parent_id = absint($_POST['new_parent_id'] ?? 0);

		if ($term_id <= 0) {
			wp_send_json_error(['message' => 'ID de categoría requerido'], 400);
		}

		// Evitar crear ciclos: no permitir que sea padre de sí mismo
		if ($term_id === $new_parent_id) {
			wp_send_json_error(['message' => 'No se puede mover una categoría a sí misma'], 400);
		}

		// Obtener padre anterior para auditoría
		$term = get_term($term_id, 'product_cat');
		$old_parent_id = $term ? $term->parent : 0;

		$result = wp_update_term($term_id, 'product_cat', ['parent' => $new_parent_id]);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		// Auditar movimiento de categoría
		if (class_exists('Riverso_POS_Audit')) {
			Riverso_POS_Audit::log('category_moved', 'product_cat', $term_id, [
				'old_value' => $old_parent_id,
				'new_value' => $new_parent_id,
				'entity_name' => $term ? $term->name : '',
			]);
		}

		wp_send_json_success(['term_id' => $term_id, 'new_parent_id' => $new_parent_id]);
	}

	/**
	 * AJAX: Eliminar una categoría
	 */
	public function ajax_delete_category() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_categories')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$term_id = absint($_POST['term_id'] ?? 0);
		if ($term_id <= 0) {
			wp_send_json_error(['message' => 'ID de categoría requerido'], 400);
		}

		// Obtener nombre de la categoría antes de eliminarla
		$term = get_term($term_id, 'product_cat');
		$term_name = $term ? $term->name : '';

		// Opción: reasignar productos a una categoría padre, o simplemente eliminar la relación
		$reassign_to = absint($_POST['reassign_to'] ?? 0);

		$result = wp_delete_term($term_id, 'product_cat', [
			'reassign' => $reassign_to > 0 ? $reassign_to : 0
		]);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		if ($result === false) {
			wp_send_json_error(['message' => 'No se pudo eliminar la categoría'], 400);
		}

		// Auditar eliminación de categoría
		if (class_exists('Riverso_POS_Audit')) {
			Riverso_POS_Audit::log('category_deleted', 'product_cat', $term_id, [
				'entity_name' => $term_name,
				'reassign_to' => $reassign_to,
			]);
		}

		wp_send_json_success(['term_id' => $term_id, 'message' => 'Categoría eliminada correctamente']);
	}

	/**
	 * AJAX: Actualizar producto (editar SKU, nombre, unidad, flags)
	 */
	public function ajax_update() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		global $wpdb;
		$product_id = absint($_POST['producto_id'] ?? 0);
		if ($product_id <= 0) {
			wp_send_json_error(['message' => 'ID de producto requerido'], 400);
		}

		$sku = sanitize_text_field($_POST['canonical_sku'] ?? '');
		$nombre = sanitize_text_field($_POST['nombre_canonico'] ?? '');
		$unidad = sanitize_text_field($_POST['unidad_base'] ?? 'unidad');
		$decimal = isset($_POST['permite_decimal']) ? (int)$_POST['permite_decimal'] : 0;
		$ean = isset($_POST['permite_ean13']) ? (int)$_POST['permite_ean13'] : 1;
		$stock = isset($_POST['stock_abierto']) ? (int)$_POST['stock_abierto'] : 0;

		$result = $wpdb->update(
			$wpdb->prefix . 'riverso_producto_base',
			[
				'canonical_sku' => $sku,
				'nombre_canonico' => $nombre,
				'unidad_base' => $unidad,
				'permite_decimal' => $decimal,
				'permite_ean13' => $ean,
				'stock_abierto' => $stock,
			],
			['id' => $product_id],
			['%s', '%s', '%s', '%d', '%d', '%d'],
			['%d']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al actualizar'], 500);
		}

		wp_send_json_success(['id' => $product_id]);
	}

	/**
	 * AJAX: Obtener lista de catálogos
	 */
	public function ajax_get_catalogs() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		
		global $wpdb;
		$catalogs = $wpdb->get_results(
			"SELECT DISTINCT catalog_name as name FROM {$wpdb->prefix}riverso_producto_proveedor WHERE catalog_name IS NOT NULL ORDER BY catalog_name",
			ARRAY_A
		);

		wp_send_json_success(['catalogs' => $catalogs ?: []]);
	}

	/**
	 * AJAX: Subir imagen de producto
	 */
	public function ajax_upload_image() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		if (empty($_FILES['file'])) {
			wp_send_json_error(['message' => 'No se cargó archivo'], 400);
		}

		$product_id = absint($_POST['producto_id'] ?? 0);
		if ($product_id <= 0) {
			wp_send_json_error(['message' => 'ID de producto requerido'], 400);
		}

		$file = $_FILES['file'];
		$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
		
		if (!in_array($file['type'], $allowed)) {
			wp_send_json_error(['message' => 'Tipo de archivo no permitido'], 400);
		}

		$upload = wp_handle_upload($file, ['test_form' => false]);
		if (isset($upload['error'])) {
			wp_send_json_error(['message' => $upload['error']], 400);
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'riverso_producto_base',
			['imagen_local_url' => $upload['url']],
			['id' => $product_id],
			['%s'],
			['%d']
		);

		wp_send_json_success(['url' => $upload['url']]);
	}

	/**
	 * AJAX: Completar tarea
	 */
	public function ajax_complete_task() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$task_id = absint($_POST['tarea_id'] ?? 0);
		if ($task_id <= 0) {
			wp_send_json_error(['message' => 'ID de tarea requerido'], 400);
		}

		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'riverso_tareas',
			['estado' => 'completada', 'completada_en' => current_time('mysql')],
			['id' => $task_id],
			['%s', '%s'],
			['%d']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al completar tarea'], 500);
		}

		wp_send_json_success(['tarea_id' => $task_id]);
	}

	/**
	 * AJAX: Asignar familia a producto
	 */
	public function ajax_set_family() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$product_id = absint($_POST['producto_id'] ?? 0);
		$familia_id = isset($_POST['familia_id']) ? absint($_POST['familia_id']) : 0;

		if ($product_id <= 0) {
			wp_send_json_error(['message' => 'ID de producto requerido'], 400);
		}

		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'riverso_producto_base',
			['equivalence_group_id' => $familia_id ?: null],
			['id' => $product_id],
			['%d'],
			['%d']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al asignar familia'], 500);
		}

		wp_send_json_success(['product_id' => $product_id, 'familia_id' => $familia_id]);
	}

	/**
	 * AJAX: Buscar códigos proveedor
	 */
	public function ajax_search_supplier_codes() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');

		$search = sanitize_text_field($_POST['search'] ?? '');
		$limit = min(20, max(1, absint($_POST['limit'] ?? 10)));

		if (strlen($search) < 2) {
			wp_send_json_success(['codes' => []]);
		}

		global $wpdb;
		$codes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT id, proveedor, codigo FROM {$wpdb->prefix}riverso_equivalence_members 
				WHERE codigo LIKE %s LIMIT %d",
				'%' . $wpdb->esc_like($search) . '%',
				$limit
			),
			ARRAY_A
		);

		wp_send_json_success(['codes' => $codes ?: []]);
	}

	/**
	 * AJAX: Asignar código proveedor a producto
	 */
	public function ajax_assign_supplier_code() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$product_id = absint($_POST['producto_id'] ?? 0);
		$supplier_id = absint($_POST['supplier_id'] ?? 0);
		$supplier_code = sanitize_text_field($_POST['supplier_code'] ?? '');
		$reason = sanitize_textarea_field($_POST['audit_reason'] ?? '');

		if ($product_id <= 0 || $supplier_id <= 0 || empty($supplier_code)) {
			wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
		}

		global $wpdb;
		
		// Insert into equivalence relationship
		$result = $wpdb->insert(
			$wpdb->prefix . 'riverso_product_suppliers',
			[
				'producto_id' => $product_id,
				'supplier_id' => $supplier_id,
				'supplier_code' => $supplier_code,
				'created_at' => current_time('mysql'),
			],
			['%d', '%d', '%s', '%s']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al asignar código'], 500);
		}

		// Audit log
		if (class_exists('Riverso_POS_Audit')) {
			Riverso_POS_Audit::log('supplier_code_assigned', 'producto_base', $product_id, [
				'supplier_id' => $supplier_id,
				'supplier_code' => $supplier_code,
				'reason' => $reason,
			]);
		}

		wp_send_json_success(['product_id' => $product_id]);
	}

	/**
	 * AJAX: Remover código proveedor
	 */
	public function ajax_remove_supplier_code() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$code_id = absint($_POST['codigo_id'] ?? 0);
		if ($code_id <= 0) {
			wp_send_json_error(['message' => 'ID de código requerido'], 400);
		}

		global $wpdb;
		$result = $wpdb->delete(
			$wpdb->prefix . 'riverso_product_suppliers',
			['id' => $code_id],
			['%d']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al eliminar'], 500);
		}

		wp_send_json_success(['codigo_id' => $code_id]);
	}

	/**
	 * AJAX: Vincular producto WooCommerce existente
	 */
	public function ajax_link_woo() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$product_id = absint($_POST['producto_id'] ?? 0);
		$woo_id = absint($_POST['woo_id'] ?? 0);

		if ($product_id <= 0 || $woo_id <= 0) {
			wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
		}

		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'riverso_producto_base',
			['woocommerce_product_id' => $woo_id],
			['id' => $product_id],
			['%d'],
			['%d']
		);

		if ($result === false) {
			wp_send_json_error(['message' => 'Error al vincular'], 500);
		}

		wp_send_json_success(['product_id' => $product_id, 'woo_id' => $woo_id]);
	}

	/**
	 * AJAX: Crear nuevo producto WooCommerce
	 */
	public function ajax_create_woo() {
		check_ajax_referer('riverso_pos_nonce', 'nonce');
		if (!current_user_can('riverso_manage_products')) {
			wp_send_json_error(['message' => 'Sin permisos'], 403);
		}

		$product_id = absint($_POST['producto_id'] ?? 0);
		$nombre = sanitize_text_field($_POST['nombre'] ?? '');

		if ($product_id <= 0 || empty($nombre)) {
			wp_send_json_error(['message' => 'Parámetros inválidos'], 400);
		}

		if (!class_exists('WooCommerce')) {
			wp_send_json_error(['message' => 'WooCommerce no está instalado'], 500);
		}

		$woo_product = new WC_Product_Simple();
		$woo_product->set_name($nombre);
		$woo_product->set_status('draft');
		$woo_id = $woo_product->save();

		if (!$woo_id) {
			wp_send_json_error(['message' => 'Error al crear producto en WooCommerce'], 500);
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'riverso_producto_base',
			['woocommerce_product_id' => $woo_id],
			['id' => $product_id],
			['%d'],
			['%d']
		);

		wp_send_json_success(['product_id' => $product_id, 'woo_id' => $woo_id]);
	}
}

