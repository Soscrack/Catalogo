<?php
/**
 * Módulo de gestión de grupos de equivalencia (familias de productos).
 *
 * Gestiona el CRUD de familias y sus miembros a través de AJAX.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Family_Module {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action('wp_ajax_riverso_families_list', [$this, 'ajax_list_families']);
        add_action('wp_ajax_riverso_families_get', [$this, 'ajax_get_family']);
        add_action('wp_ajax_riverso_families_create', [$this, 'ajax_create_family']);
        add_action('wp_ajax_riverso_families_update', [$this, 'ajax_update_family']);
        add_action('wp_ajax_riverso_families_add_member', [$this, 'ajax_add_member']);
        add_action('wp_ajax_riverso_families_remove_member', [$this, 'ajax_remove_member']);
        add_action('wp_ajax_riverso_families_tree', [$this, 'ajax_family_tree']);
    }

    /**
     * AJAX: Listar todas las familias activas con conteo de miembros.
     */
    public function ajax_list_families() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $families = $wpdb->get_results(
            "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion, g.activo,
                    COUNT(em.id) as miembros_count
             FROM {$prefix}equivalence_groups g
             LEFT JOIN {$prefix}equivalence_members em ON em.grupo_id = g.id AND em.activo = 1
             WHERE g.activo = 1
             GROUP BY g.id
             ORDER BY g.nombre ASC",
            ARRAY_A
        );

        wp_send_json_success(['families' => $families ?: []]);
    }

    /**
     * AJAX: Obtener detalle de una familia con sus miembros.
     */
    public function ajax_get_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        if (!$grupo_id) {
            wp_send_json_error(['message' => 'grupo_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        if (!$family) {
            wp_send_json_error(['message' => 'Familia no encontrada']);
        }

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT em.id, em.producto_base_id, em.prioridad, em.es_reemplazo_preferido,
                    pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.grupo_id = %d AND em.activo = 1
             ORDER BY em.prioridad DESC, pb.nombre_canonico ASC",
            $grupo_id
        ), ARRAY_A);

        $family['members'] = $members;
        wp_send_json_success(['family' => $family]);
    }

    /**
     * AJAX: Crear nueva familia.
     */
    public function ajax_create_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $codigo_grupo = sanitize_text_field($_POST['codigo_grupo'] ?? '');
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $tipo_sustitucion = sanitize_text_field($_POST['tipo_sustitucion'] ?? 'compatible');
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        if (!$codigo_grupo || !$nombre) {
            wp_send_json_error(['message' => 'Código y nombre son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Verificar que no existe
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_groups WHERE codigo_grupo = %s",
            $codigo_grupo
        ))) {
            wp_send_json_error(['message' => 'El código de grupo ya existe']);
        }

        $wpdb->insert(
            "{$prefix}equivalence_groups",
            [
                'codigo_grupo' => $codigo_grupo,
                'nombre' => $nombre,
                'tipo_sustitucion' => in_array($tipo_sustitucion, ['compatible', 'sustituto', 'preferido'], true) ? $tipo_sustitucion : 'compatible',
                'notas' => $notas,
                'activo' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );

        $grupo_id = $wpdb->insert_id;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_created', 'equivalence_groups', $grupo_id, [
                'codigo_grupo' => $codigo_grupo,
                'nombre' => $nombre,
            ]);
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        wp_send_json_success(['family' => $family]);
    }

    /**
     * AJAX: Actualizar familia existente.
     */
    public function ajax_update_family() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        $tipo_sustitucion = sanitize_text_field($_POST['tipo_sustitucion'] ?? 'compatible');
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        if (!$grupo_id || !$nombre) {
            wp_send_json_error(['message' => 'grupo_id y nombre son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $wpdb->update(
            "{$prefix}equivalence_groups",
            [
                'nombre' => $nombre,
                'tipo_sustitucion' => in_array($tipo_sustitucion, ['compatible', 'sustituto', 'preferido'], true) ? $tipo_sustitucion : 'compatible',
                'notas' => $notas,
            ],
            ['id' => $grupo_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_updated', 'equivalence_groups', $grupo_id, ['nombre' => $nombre]);
        }

        $family = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}equivalence_groups WHERE id = %d",
            $grupo_id
        ), ARRAY_A);

        wp_send_json_success(['family' => $family]);
    }

    /**
     * AJAX: Agregar miembro a familia.
     */
    public function ajax_add_member() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $grupo_id = absint($_POST['grupo_id'] ?? 0);
        $producto_base_id = absint($_POST['producto_base_id'] ?? 0);
        $prioridad = absint($_POST['prioridad'] ?? 100);
        $es_preferido = !empty($_POST['es_preferido']) ? 1 : 0;

        if (!$grupo_id || !$producto_base_id) {
            wp_send_json_error(['message' => 'grupo_id y producto_base_id son requeridos']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Verificar que no existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}equivalence_members WHERE grupo_id = %d AND producto_base_id = %d",
            $grupo_id,
            $producto_base_id
        ));

        if ($exists) {
            wp_send_json_error(['message' => 'El producto ya es miembro de esta familia']);
        }

        $wpdb->insert(
            "{$prefix}equivalence_members",
            [
                'grupo_id' => $grupo_id,
                'producto_base_id' => $producto_base_id,
                'prioridad' => $prioridad,
                'es_reemplazo_preferido' => $es_preferido,
                'activo' => 1,
            ],
            ['%d', '%d', '%d', '%d', '%d']
        );

        $member_id = $wpdb->insert_id;

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_added', 'equivalence_members', $member_id, [
                'grupo_id' => $grupo_id,
                'producto_base_id' => $producto_base_id,
            ]);
        }

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT em.*, pb.canonical_sku, pb.nombre_canonico
             FROM {$prefix}equivalence_members em
             LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
             WHERE em.id = %d",
            $member_id
        ), ARRAY_A);

        wp_send_json_success(['member' => $member]);
    }

    /**
     * AJAX: Quitar miembro de familia.
     */
    public function ajax_remove_member() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_manage_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        $member_id = absint($_POST['member_id'] ?? 0);
        if (!$member_id) {
            wp_send_json_error(['message' => 'member_id requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Soft delete (activo = 0)
        $wpdb->update(
            "{$prefix}equivalence_members",
            ['activo' => 0],
            ['id' => $member_id],
            ['%d'],
            ['%d']
        );

        if (class_exists('Riverso_POS_Audit')) {
            Riverso_POS_Audit::log('family_member_removed', 'equivalence_members', $member_id);
        }

        wp_send_json_success(['message' => 'Miembro removido']);
    }

    /**
     * AJAX: Obtener árbol de familias con sus miembros.
     */
    public function ajax_family_tree() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');
        
        if (!current_user_can('riverso_view_products')) {
            wp_send_json_error(['message' => 'Permiso denegado'], 403);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $families = $wpdb->get_results(
            "SELECT g.id, g.codigo_grupo, g.nombre, g.tipo_sustitucion,
                    COUNT(em.id) as miembros_count
             FROM {$prefix}equivalence_groups g
             LEFT JOIN {$prefix}equivalence_members em ON em.grupo_id = g.id AND em.activo = 1
             WHERE g.activo = 1
             GROUP BY g.id
             ORDER BY g.nombre ASC",
            ARRAY_A
        );

        // Enriquecer cada familia con sus miembros
        foreach ($families as &$family) {
            $family['members'] = $wpdb->get_results($wpdb->prepare(
                "SELECT em.id, em.producto_base_id, em.prioridad, em.es_reemplazo_preferido,
                        pb.canonical_sku, pb.nombre_canonico
                 FROM {$prefix}equivalence_members em
                 LEFT JOIN {$prefix}producto_base pb ON pb.id = em.producto_base_id
                 WHERE em.grupo_id = %d AND em.activo = 1
                 ORDER BY em.es_reemplazo_preferido DESC, em.prioridad DESC",
                $family['id']
            ), ARRAY_A);
            
            // Convertir miembros a estructura de árbol (aunque sea plano)
            $family['children'] = $family['members'];
        }

        wp_send_json_success(['tree' => $families]);
    }
}
