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
     * Instancia singleton
     */
    private static $instance = null;
    
    /**
     * Obtener instancia singleton
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

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
        'bodegaje' => 'Ubicar en bodega',
        'devolucion' => 'Procesamiento de devolución',
        'codigo_faltante' => 'Vincular código proveedor',
        'barcode_faltante' => 'Asignar código de barra',
        // Tareas de revisión humana (procesos automáticos)
        'revisar_relacion' => 'Revisar relación de producto',
        'validar_categoria' => 'Validar categoría',
        'verificar_etiquetado' => 'Verificar etiquetado',
        'aprobar_lista_precios' => 'Aprobar lista de precios',
        'relacionar_producto_proveedor' => 'Relacionar producto proveedor',
        'confirmar_relacion_online' => 'Confirmar relación producto local ↔ online',
        'confirmar_estructura_atributos' => 'Confirmar estructura de atributos',
        'autorizar_publicacion' => 'Autorizar publicación',
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
        add_action('wp_ajax_riverso_get_task', [$this, 'ajax_get_task']);
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

        $created_by_system = !empty($data['created_by_system']) ? 1 : 0;
        // Proceso automático => creado_por NULL (computer); usuario => id actual.
        $creado_por = $created_by_system ? null : get_current_user_id();
        if (isset($data['creado_por'])) {
            $creado_por = intval($data['creado_por']) ?: null;
        }
        // Las tareas de revisión generadas por el sistema requieren revisión humana.
        $requires_human_review = isset($data['requires_human_review'])
            ? (!empty($data['requires_human_review']) ? 1 : 0)
            : $created_by_system;

        $result = $wpdb->insert(
            "{$prefix}tareas",
            [
                'tipo' => sanitize_text_field($data['tipo']),
                'titulo' => sanitize_text_field($data['titulo']),
                'descripcion' => sanitize_textarea_field($data['descripcion'] ?? ''),
                'prioridad' => sanitize_text_field($data['prioridad'] ?? 'normal'),
                'estado' => 'pendiente',
                'asignado_a' => intval($data['asignado_a'] ?? 0) ?: null,
                'creado_por' => $creado_por,
                'created_by_system' => $created_by_system,
                'requires_human_review' => $requires_human_review,
                'referencia_tipo' => sanitize_text_field($data['referencia_tipo'] ?? ''),
                'referencia_id' => intval($data['referencia_id'] ?? 0) ?: null,
                'datos_extra' => isset($data['datos_extra']) ? wp_json_encode($data['datos_extra']) : null,
                'fecha_limite' => !empty($data['fecha_limite']) ? sanitize_text_field($data['fecha_limite']) : null,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s']
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
        } elseif (empty($filters['include_completed'])) {
            // Por defecto excluir completadas a menos que se pida explícitamente
            $where[] = "t.estado NOT IN ('completada', 'cancelada')";
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
     * Crear tarea de etiquetado de productos
     */
    public function create_labeling_task($factura_id, $items, $proveedor_nombre) {
        $items_list = array();
        foreach ($items as $item) {
            $items_list[] = sprintf(
                "- %s x %d (SKU: %s)",
                $item['descripcion'] ?? 'Sin descripción',
                $item['cantidad'] ?? 1,
                $item['sku_local'] ?? 'N/A'
            );
        }
        
        return $this->create_task([
            'tipo' => 'etiquetado',
            'titulo' => "Etiquetar productos - Factura #{$factura_id}",
            'descripcion' => "Etiquetar productos recibidos de {$proveedor_nombre}:\n\n" . implode("\n", $items_list),
            'prioridad' => 'normal',
            'referencia_tipo' => 'factura',
            'referencia_id' => $factura_id,
            'datos_extra' => [
                'proveedor' => $proveedor_nombre,
                'total_items' => count($items),
                'items' => $items,
            ],
        ]);
    }
    
    /**
     * Crear tarea de bodegaje (ubicar productos en bodega)
     */
    public function create_storage_task($factura_id, $items, $proveedor_nombre) {
        $items_list = array();
        foreach ($items as $item) {
            $ubicacion = $item['ubicacion_sugerida'] ?? 'Sin ubicación asignada';
            $items_list[] = sprintf(
                "- %s x %d → %s",
                $item['descripcion'] ?? 'Sin descripción',
                $item['cantidad'] ?? 1,
                $ubicacion
            );
        }
        
        return $this->create_task([
            'tipo' => 'bodegaje',
            'titulo' => "Ubicar en bodega - Factura #{$factura_id}",
            'descripcion' => "Ubicar productos de {$proveedor_nombre} en bodega:\n\n" . implode("\n", $items_list),
            'prioridad' => 'normal',
            'referencia_tipo' => 'factura',
            'referencia_id' => $factura_id,
            'datos_extra' => [
                'proveedor' => $proveedor_nombre,
                'total_items' => count($items),
                'items' => $items,
            ],
        ]);
    }
    
    /**
     * Crear tarea de asignar código de barra
     */
    public function create_barcode_task($product_id, $product_name, $sku) {
        return $this->create_task([
            'tipo' => 'barcode_faltante',
            'titulo' => "Asignar código de barra: {$sku}",
            'descripcion' => "El producto '{$product_name}' (SKU: {$sku}) no tiene código de barra asignado.\n\nEscanear código de barra del producto y vincularlo.",
            'prioridad' => 'baja',
            'referencia_tipo' => 'producto',
            'referencia_id' => $product_id,
            'datos_extra' => [
                'product_id' => $product_id,
                'product_name' => $product_name,
                'sku' => $sku,
            ],
        ]);
    }
    
    /**
     * Crea (o reutiliza) una tarea de revisión humana generada por un proceso
     * automático (matching, importación, generación de precios/EAN13, etc.).
     *
     * Evita duplicados: si ya existe una tarea pendiente del mismo tipo y
     * referencia, devuelve su ID en lugar de crear otra.
     *
     * @param string $tipo            Tipo de tarea (ver TASK_TYPES)
     * @param string $titulo          Título legible
     * @param string $referencia_tipo Tipo de entidad referenciada
     * @param int    $referencia_id   ID de la entidad referenciada
     * @param array  $extra           Datos adicionales: descripcion, prioridad, datos_extra
     * @return int|WP_Error           ID de la tarea
     */
    public function create_review_task($tipo, $titulo, $referencia_tipo = '', $referencia_id = 0, $extra = []) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        // Deduplicar tareas de revisión abiertas por tipo + referencia.
        if ($referencia_tipo && $referencia_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$prefix}tareas
                 WHERE tipo = %s AND referencia_tipo = %s AND referencia_id = %d
                   AND estado NOT IN ('completada', 'cancelada')
                 LIMIT 1",
                $tipo,
                $referencia_tipo,
                $referencia_id
            ));
            if ($existing) {
                return intval($existing);
            }
        }

        return $this->create_task([
            'tipo' => $tipo,
            'titulo' => $titulo,
            'descripcion' => $extra['descripcion'] ?? '',
            'prioridad' => $extra['prioridad'] ?? 'normal',
            'referencia_tipo' => $referencia_tipo,
            'referencia_id' => $referencia_id,
            'datos_extra' => $extra['datos_extra'] ?? null,
            'created_by_system' => true,
            'requires_human_review' => true,
        ]);
    }

    /**
     * Crear tareas de bodegaje desde factura aprobada
     */
    public function create_tasks_from_approved_invoice($factura_id) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Obtener factura
        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$prefix}facturas WHERE id = %d",
            $factura_id
        ), ARRAY_A);
        
        if (!$factura) {
            return new WP_Error('not_found', 'Factura no encontrada');
        }
        
        // Obtener items aprobados
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$prefix}factura_items 
             WHERE factura_id = %d 
             AND item_status IN ('received_ok', 'modified', 'approved')",
            $factura_id
        ), ARRAY_A);
        
        if (empty($items)) {
            return new WP_Error('no_items', 'No hay items para procesar');
        }
        
        $tasks_created = array();
        $items_for_labeling = array();
        $items_for_storage = array();
        $items_missing_code = array();
        
        // Clasificar items
        foreach ($items as $item) {
            $item_data = array(
                'id' => $item['id'],
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['qty_received'] ?: $item['cantidad'],
                'sku_local' => $item['sku_local'],
                'codigo_proveedor' => $item['codigo_proveedor'],
            );
            
            // Todos los items necesitan etiquetado
            $items_for_labeling[] = $item_data;
            
            // Buscar ubicación sugerida si tiene SKU
            if (!empty($item['sku_local'])) {
                $ubicacion = $this->get_suggested_location($item['sku_local']);
                $item_data['ubicacion_sugerida'] = $ubicacion;
                $items_for_storage[] = $item_data;
            }
            
            // Si no tiene SKU local, crear tarea de código faltante
            if (empty($item['sku_local'])) {
                $items_missing_code[] = $item;
            }
        }
        
        // Crear tarea de etiquetado (una por factura)
        if (!empty($items_for_labeling)) {
            $task_id = $this->create_labeling_task(
                $factura_id,
                $items_for_labeling,
                $factura['razon_social_emisor']
            );
            if (!is_wp_error($task_id)) {
                $tasks_created['labeling'] = $task_id;
            }
        }
        
        // Crear tarea de bodegaje (una por factura)
        if (!empty($items_for_storage)) {
            $task_id = $this->create_storage_task(
                $factura_id,
                $items_for_storage,
                $factura['razon_social_emisor']
            );
            if (!is_wp_error($task_id)) {
                $tasks_created['storage'] = $task_id;
            }
        }
        
        // Crear tareas de códigos faltantes (una por item)
        foreach ($items_missing_code as $item) {
            $task_id = $this->create_missing_code_task(
                $item['id'],
                $item['codigo_proveedor'],
                $item['descripcion'],
                $factura['razon_social_emisor']
            );
            if (!is_wp_error($task_id)) {
                $tasks_created['missing_code_' . $item['id']] = $task_id;
            }
        }
        
        return $tasks_created;
    }
    
    /**
     * Obtener ubicación sugerida para un SKU
     */
    private function get_suggested_location($sku) {
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        
        // Buscar producto por SKU
        $product_id = wc_get_product_id_by_sku($sku);
        if (!$product_id) {
            return 'Sin ubicación asignada';
        }
        
        // Buscar ubicación existente del producto
        $ubicacion = $wpdb->get_row($wpdb->prepare(
            "SELECT pu.*, u.nombre as ubicacion_nombre, u.codigo as ubicacion_codigo
             FROM {$prefix}producto_ubicacion pu
             JOIN {$prefix}ubicaciones u ON pu.ubicacion_id = u.id
             WHERE pu.producto_id = %d
             ORDER BY pu.cantidad DESC
             LIMIT 1",
            $product_id
        ), ARRAY_A);
        
        if ($ubicacion) {
            return $ubicacion['ubicacion_codigo'] . ' - ' . $ubicacion['ubicacion_nombre'];
        }
        
        return 'Sin ubicación asignada';
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
            'sin_asignar' => !empty($_POST['sin_asignar']),
            'limit' => min(100, intval($_POST['limit'] ?? 50)),
            'offset' => intval($_POST['offset'] ?? 0),
        ];

        $tasks = $this->get_tasks($filters);

        // Agregar target_url a cada tarea.
        foreach ($tasks as &$task) {
            $task['target_url'] = riverso_resolve_task_target($task);
        }
        unset($task);

        wp_send_json_success([
            'tasks' => $tasks,
            'types' => self::TASK_TYPES,
            'priorities' => self::PRIORITIES,
        ]);
    }

    /**
     * AJAX: Obtener una tarea específica
     */
    public function ajax_get_task() {
        check_ajax_referer('riverso_pos_nonce', 'nonce');

        if (!current_user_can('riverso_view_tasks')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }

        $task_id = intval($_POST['task_id'] ?? 0);
        if (!$task_id) {
            wp_send_json_error(['message' => 'ID de tarea requerido']);
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';

        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, 
                u_asignado.display_name as asignado_nombre,
                u_creador.display_name as creador_nombre
                FROM {$prefix}tareas t
                LEFT JOIN {$wpdb->users} u_asignado ON t.asignado_a = u_asignado.ID
                LEFT JOIN {$wpdb->users} u_creador ON t.creado_por = u_creador.ID
                WHERE t.id = %d",
            $task_id
        ), ARRAY_A);

        if (!$task) {
            wp_send_json_error(['message' => 'Tarea no encontrada']);
        }

        wp_send_json_success(['task' => $task]);
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

        // Agregar target_url a cada tarea.
        foreach ($tasks as &$task) {
            $task['target_url'] = riverso_resolve_task_target($task);
        }
        foreach ($pending as &$task) {
            $task['target_url'] = riverso_resolve_task_target($task);
        }
        unset($task);

        wp_send_json_success([
            'mis_tareas' => $tasks,
            'sin_asignar' => $pending,
            'types' => self::TASK_TYPES,
        ]);
    }
}
