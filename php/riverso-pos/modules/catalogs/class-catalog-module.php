<?php
/**
 * Módulo de Catálogos de Proveedores (entidad catalogos)
 *
 * Distinto del dominio Riverso_Catalog_Module (catalog/catalog-module.php).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Supplier_Catalogs_Module {
    
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
        add_action('wp_ajax_riverso_catalogs_list', [$this, 'ajax_list_catalogs']);
        add_action('wp_ajax_riverso_catalogs_get', [$this, 'ajax_get_catalog']);
        add_action('wp_ajax_riverso_catalogs_search_products', [$this, 'ajax_search_catalog_products']);
    }
    
    /**
     * AJAX: Listar catálogos activos (para dropdown del Hub)
     */
    public function ajax_list_catalogs() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $catalogs = $wpdb->get_results(
            "SELECT c.id, c.nombre, c.alias, c.proveedor_id, p.nombre as proveedor_nombre
             FROM {$prefix}catalogos c
             LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
             WHERE c.activo = 1
             ORDER BY c.created_at DESC"
        );
        
        wp_send_json_success(['catalogs' => $catalogs]);
    }
    
    /**
     * AJAX: Obtener detalles de un catálogo con stats
     */
    public function ajax_get_catalog() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        $catalog_id = absint($_POST['catalog_id'] ?? 0);
        if (!$catalog_id) {
            wp_send_json_error(['message' => 'Catálogo no especificado']);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $catalog = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, p.nombre as proveedor_nombre FROM {$prefix}catalogos c
                 LEFT JOIN {$prefix}proveedores p ON p.id = c.proveedor_id
                 WHERE c.id = %d",
                $catalog_id
            )
        );
        
        if (!$catalog) {
            wp_send_json_error(['message' => 'Catálogo no encontrado']);
        }
        
        // Stats
        $total_pp = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor WHERE catalogo_id = %d",
                $catalog_id
            )
        );
        
        $linked_pp = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prefix}producto_proveedor WHERE catalogo_id = %d AND producto_base_id IS NOT NULL",
                $catalog_id
            )
        );
        
        $catalog->total_productos = (int) $total_pp;
        $catalog->productos_vinculados = (int) $linked_pp;
        $catalog->productos_sin_vincular = (int) ($total_pp - $linked_pp);
        
        wp_send_json_success(['catalog' => $catalog]);
    }
    
    /**
     * AJAX: Buscar productos dentro de un catálogo
     */
    public function ajax_search_catalog_products() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }
        
        $catalog_id = absint($_POST['catalog_id'] ?? 0);
        $search = sanitize_text_field($_POST['search'] ?? '');
        $limit = min(20, max(1, absint($_POST['limit'] ?? 10)));
        
        if (!$catalog_id) {
            wp_send_json_error(['message' => 'Catálogo no especificado']);
        }
        
        if (strlen($search) < 1) {
            wp_send_json_success(['products' => []]);
        }
        
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        $like = '%' . $wpdb->esc_like($search) . '%';
        
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor, 
                        pb.id as producto_base_id, pb.canonical_sku, pb.nombre_canonico
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 WHERE pp.catalogo_id = %d
                   AND (pp.codigo_proveedor LIKE %s OR pp.nombre_proveedor LIKE %s)
                 ORDER BY pp.codigo_proveedor ASC
                 LIMIT %d",
                $catalog_id,
                $like,
                $like,
                $limit
            )
        );
        
        wp_send_json_success(['products' => $products]);
    }
    
    /**
     * Buscar productos por catálogo con sugerencias fuzzy
     * (usado por UI para autocomplete)
     */
    public function search_with_fuzzy($catalog_id, $search, $limit = 10) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        $catalog_id = absint($catalog_id);
        $search = sanitize_text_field($search);
        $limit = min($limit, 20);
        
        if (!$catalog_id || strlen($search) < 1) {
            return [];
        }
        
        $like = '%' . $wpdb->esc_like($search) . '%';
        
        // Búsqueda exacta parcial (incluye activo=0: Mamut histórico)
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pp.id, pp.codigo_proveedor, pp.nombre_proveedor,
                        pb.id as producto_base_id, pb.canonical_sku
                 FROM {$prefix}producto_proveedor pp
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 WHERE pp.catalogo_id = %d
                   AND (pp.codigo_proveedor LIKE %s OR pp.nombre_proveedor LIKE %s)
                 LIMIT %d",
                $catalog_id,
                $like,
                $like,
                $limit
            )
        );
        
        // Ranking fuzzy por similar_text si es necesario (para futuros refinamientos)
        usort($results, function($a, $b) use ($search) {
            similar_text($search, $a->codigo_proveedor, $pct_a);
            similar_text($search, $b->codigo_proveedor, $pct_b);
            return $pct_b - $pct_a; // Orden descendente
        });
        
        return array_slice($results, 0, $limit);
    }
}
