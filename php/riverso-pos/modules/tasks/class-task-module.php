<?php
/**
 * Módulo de Tareas - Gestión de tareas de bodega y cotización
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Task_Module {

    /**
     * Tipos de tareas disponibles
     */
    const TASK_TYPES = [
        'cotizacion' => 'Cotización pendiente',
        'picking' => 'Picking para venta',
        'reposicion' => 'Reposición de stock',
        'recepcion' => 'Recepción de mercadería',
        'inventario' => 'Conteo de inventario',
        'ubicacion' => 'Cambio de ubicación',
        'etiquetado' => 'Etiquetado de productos',
        'devolucion' => 'Procesamiento de devolución',
        'codigo_faltante' => 'Vincular código proveedor',
    ];

    /**
     * Prioridades
     */
    const PRIORITIES = [
        'baja' => ['label' => 'Baja', 'color' => '#666'],
        'normal' => ['label' => 'Normal', 'color' => '#2196f3'],
        'alta' => ['label' => 'Alta', 'color' => '#ff9800'],
        'urgente' => ['label' => 'Urgente', 'color' => '#f44336'],
    ];

    /**
     * Inicializar módulo
     */
    public function init() {
        add_action('wp_ajax_riverso_create_task', [$this, 'ajax_create_task']);
        add_action('wp_ajax_riverso_get_tasks', [$this, 'ajax_get_tasks']);
        add_action('wp_ajax_riverso_update_task', [$this, 'ajax_update_task']);
        add_action('wp_ajax_riverso_complete_task', [$this, 'ajax_complete_task']);
        add_action('wp_ajax_riverso_assign_task', [$this, 'ajax_assign_task']);
        add_action('wp_ajax_riverso_get_my_tasks', [$this, 'ajax_get_my_tasks']);
    }

    /**
     * Crear tarea
     */
    public function create_task($data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $result = $wpdb->insert(
            "{$prefix}tareas",
            [
                'tipo' => sanitize_text_field($data['tipo']),
                'titulo' => sanitize_text_field($data['titulo']),
                'descripcion' => sanitize_textarea_field($data['descripcion'] ?? ''),
                'prioridad' => sanitize_text_field($data['prioridad'] ?? 'normal'),
                'estado' => 'pendiente',
                'asignado_a' => intval($data['asignado_a'] ?? 0) ?: null,
                'creado_por' => get_current_user_id(),
                'referencia_tipo' => sanitize_text_field($data['referencia_tipo'] ?? ''),
                'referencia_id' => intval($data['referencia_id'] ?? 0) ?: null,
                'datos_extra' => isset($data['datos_extra']) ? wp_json_encode($data['datos_extra']) : null,
                'fecha_limite' => !empty($data['fecha_limite']) ? sanitize_text_field($data['fecha_limite']) : null,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']
        );

        if (!$result) {
            return new WP_Error('db_error', 'Error creando tarea');
        }

        return $wpdb->insert_id;
    }

    /**
     * Obtener tareas con filtros
     */
    public function get_tasks($filters = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['tipo'])) {
            $where[] = 't.tipo = %s';
            $params[] = $filters['tipo'];
        }

        if (!empty($filters['estado'])) {
            $where[] = 't.estado = %s';
            $params[] = $filters['estado'];
        }

        if (!empty($filters['prioridad'])) {
            $where[] = 't.prioridad = %s';
            $params[] = $filters['prioridad'];
        }

        if (!empty($filters['asignado_a'])) {
            $where[] = 't.asignado_a = %d';
            $params[] = $filters['asignado_a'];
        }

        if (!empty($filters['creado_por'])) {
            $where[] = 't.creado_por = %d';
            $params[] = $filters['creado_por'];
        }

        if (isset($filters['sin_asignar']) && $filters['sin_asignar']) {
            $where[] = 't.asignado_a IS NULL';
        }

        $where_sql = implode(' AND ', $where);
        
        $order = 'FIELD(t.prioridad, "urgente", "alta", "normal", "baja"), t.created_at DESC';
        
        if (!empty($filters['order_by'])) {
            $order = sanitize_sql_orderby($filters['order_by']) ?: $order;
        }

        $limit = '';
        if (!empty($filters['limit'])) {
            $limit = sprintf('LIMIT %d', intval($filters['limit']));
            if (!empty($filters['offset'])) {
                $limit .= sprintf(' OFFSET %d', intval($filters['offset']));
            }
        }

        $sql = "SELECT t.*, 
                u_asignado.display_name as asignado_nombre,
                u_creador.display_name as creador_nombre
                FROM {$prefix}tareas t
                LEFT JOIN {$wpdb->users} u_asignado ON t.asignado_a = u_asignado.ID
                LEFT JOIN {$wpdb->users} u_creador ON t.creado_por = u_creador.ID
                WHERE {$where_sql}
                ORDER BY {$order}
                {$limit}";

        if ($params) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Actualizar tarea
     */
    public function update_task($task_id, $data) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $allowed = ['titulo', 'descripcion', 'prioridad', 'estado', 'asignado_a', 'fecha_limite'];
        $update = [];
        $formats = [];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update[$field] = $data[$field];
                $formats[] = in_array($field, ['asignado_a']) ? '%d' : '%s';
            }
        }

        if (empty($update)) {
            return new WP_Error('no_data', 'No hay datos para actualizar');
        }

        $update['updated_at'] = current_time('mysql');
        $formats[] = '%s';

        $result = $wpdb->update(
            "{$prefix}tareas",
            $update,
            ['id' => $task_id],
            $formats,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Completar tarea
     */
    public function complete_task($task_id, $notas = '') {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}tareas WHERE id = %d",
            $task_id
        ), ARRAY_A);

        if (!$task) {
            return new WP_Error('not_found', 'Tarea no encontrada');
        }

        // Verificar permisos
        $user_id = get_current_user_id();
        if ($task['asignado_a'] && $task['asignado_a'] != $user_id && !current_user_can('riverso_assign_tasks')) {
            return new WP_Error('permission', 'No tienes permiso para completar esta tarea');
        }

        $datos_extra = json_decode($task['datos_extra'] ?? '{}', true) ?: [];
        $datos_extra['completado_por'] = $user_id;
        $datos_extra['notas_completado'] = $notas;

        $result = $wpdb->update(
            "{$prefix}tareas",
            [
                'estado' => 'completada',
                'completado_en' => current_time('mysql'),
                'datos_extra' => wp_json_encode($datos_extra),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $task_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Crear tarea de código faltante
     */
    public function create_missing_code_task($factura_item_id, $codigo_proveedor, $descripcion, $proveedor_nombre) {
        return $this->create_task([
            'tipo' => 'codigo_faltante',
            'titulo' => "Vincular código: {$codigo_proveedor}",
            'descripcion' => "Código de {$proveedor_nombre}: {$codigo_proveedor}\nDescripción: {$descripcion}\n\nBuscar producto correspondiente y vincular.",
            'prioridad' => 'alta',
            'referencia_tipo' => 'factura_item',
            'referencia_id' => $factura_item_id,
            'datos_extra' => [
                'codigo_proveedor' => $codigo_proveedor,
                'descripcion_original' => $descripcion,
            ],
        ]);
    }

    /**
     * AJAX: Crear tarea
     */
    public function ajax_create_task() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_create_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $data = [
            'tipo' => $_POST['tipo'] ?? '',
            'titulo' => $_POST['titulo'] ?? '',
            'descripcion' => $_POST['descripcion'] ?? '',
            'prioridad' => $_POST['prioridad'] ?? 'normal',
            'asignado_a' => $_POST['asignado_a'] ?? 0,
            'fecha_limite' => $_POST['fecha_limite'] ?? '',
        ];

        if (empty($data['tipo']) || empty($data['titulo'])) {
            wp_send_json_error(['message' => 'Tipo y título son requeridos']);
        }

        $task_id = $this->create_task($data);

        if (is_wp_error($task_id)) {
            wp_send_json_error(['message' => $task_id->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Tarea creada',
            'task_id' => $task_id,
        ]);
    }

    /**
     * AJAX: Obtener tareas
     */
    public function ajax_get_tasks() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $filters = [
            'tipo' => sanitize_text_field($_POST['tipo'] ?? ''),
            'estado' => sanitize_text_field($_POST['estado'] ?? ''),
            'prioridad' => sanitize_text_field($_POST['prioridad'] ?? ''),
            'asignado_a' => intval($_POST['asignado_a'] ?? 0),
            'limit' => min(100, intval($_POST['limit'] ?? 50)),
            'offset' => intval($_POST['offset'] ?? 0),
        ];

        $tasks = $this->get_tasks($filters);

        wp_send_json_success([
            'tasks' => $tasks,
            'types' => self::TASK_TYPES,
            'priorities' => self::PRIORITIES,
        ]);
    }

    /**
     * AJAX: Actualizar tarea
     */
    public function ajax_update_task() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $task_id = intval($_POST['task_id'] ?? 0);
        
        if (!$task_id) {
            wp_send_json_error(['message' => 'ID de tarea requerido']);
        }

        // Verificar permisos según el campo a actualizar
        if (isset($_POST['asignado_a']) && !current_user_can('riverso_assign_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos para asignar']);
        }

        $data = [];
        $allowed_fields = ['titulo', 'descripcion', 'prioridad', 'estado', 'asignado_a', 'fecha_limite'];
        
        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = $_POST[$field];
            }
        }

        $result = $this->update_task($task_id, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Tarea actualizada']);
    }

    /**
     * AJAX: Completar tarea
     */
    public function ajax_complete_task() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_complete_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $task_id = intval($_POST['task_id'] ?? 0);
        $notas = sanitize_textarea_field($_POST['notas'] ?? '');

        if (!$task_id) {
            wp_send_json_error(['message' => 'ID de tarea requerido']);
        }

        $result = $this->complete_task($task_id, $notas);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'Tarea completada']);
    }

    /**
     * AJAX: Asignar tarea
     */
    public function ajax_assign_task() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_assign_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos para asignar tareas']);
        }

        $task_id = intval($_POST['task_id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);

        if (!$task_id) {
            wp_send_json_error(['message' => 'ID de tarea requerido']);
        }

        $result = $this->update_task($task_id, [
            'asignado_a' => $user_id ?: null,
            'estado' => $user_id ? 'asignada' : 'pendiente',
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => $user_id ? 'Tarea asignada' : 'Asignación removida']);
    }

    /**
     * AJAX: Obtener mis tareas
     */
    public function ajax_get_my_tasks() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        $user_id = get_current_user_id();

        $tasks = $this->get_tasks([
            'asignado_a' => $user_id,
            'estado' => 'asignada',
        ]);

        $pending = $this->get_tasks([
            'sin_asignar' => true,
            'estado' => 'pendiente',
            'limit' => 10,
        ]);

        wp_send_json_success([
            'mis_tareas' => $tasks,
            'sin_asignar' => $pending,
            'types' => self::TASK_TYPES,
        ]);
    }
}
