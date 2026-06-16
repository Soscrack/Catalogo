<?php
/**
 * Funciones helper del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formatea RUT chileno
 */
function riverso_format_rut($rut) {
    $rut = preg_replace('/[^0-9kK]/', '', $rut);
    if (strlen($rut) < 2) return $rut;
    
    $dv = strtoupper(substr($rut, -1));
    $numero = substr($rut, 0, -1);
    $numero = number_format($numero, 0, '', '.');
    
    return $numero . '-' . $dv;
}

/**
 * Valida RUT chileno
 */
function riverso_validate_rut($rut) {
    $rut = preg_replace('/[^0-9kK]/', '', $rut);
    if (strlen($rut) < 2) return false;
    
    $dv = strtoupper(substr($rut, -1));
    $numero = substr($rut, 0, -1);
    
    $suma = 0;
    $multiplo = 2;
    
    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $suma += $numero[$i] * $multiplo;
        $multiplo = $multiplo < 7 ? $multiplo + 1 : 2;
    }
    
    $resto = $suma % 11;
    $dv_calculado = 11 - $resto;
    
    if ($dv_calculado == 11) $dv_calculado = '0';
    elseif ($dv_calculado == 10) $dv_calculado = 'K';
    else $dv_calculado = (string) $dv_calculado;
    
    return $dv === $dv_calculado;
}

/**
 * Formatea moneda chilena
 */
function riverso_format_clp($amount) {
    return '$' . number_format($amount, 0, ',', '.');
}

/**
 * Obtiene tipos de DTE
 */
function riverso_get_dte_types() {
    return [
        33 => 'Factura Electrónica',
        34 => 'Factura No Afecta o Exenta',
        52 => 'Guía de Despacho',
        61 => 'Nota de Crédito',
        56 => 'Nota de Débito',
    ];
}

/**
 * Obtiene nombre de tipo DTE
 */
function riverso_get_dte_name($tipo) {
    $tipos = riverso_get_dte_types();
    return $tipos[$tipo] ?? "Tipo $tipo";
}

/**
 * Obtiene estados de factura
 */
function riverso_get_invoice_statuses() {
    return [
        'pendiente' => ['label' => 'Pendiente', 'color' => '#f0ad4e'],
        'procesando' => ['label' => 'Procesando', 'color' => '#5bc0de'],
        'recibido' => ['label' => 'Recibido', 'color' => '#5cb85c'],
        'parcial' => ['label' => 'Parcial', 'color' => '#f0ad4e'],
        'rechazado' => ['label' => 'Rechazado', 'color' => '#d9534f'],
        'anulado' => ['label' => 'Anulado', 'color' => '#777'],
    ];
}

/**
 * Obtiene estados de tarea
 */
function riverso_get_task_statuses() {
    return [
        'pendiente' => ['label' => 'Pendiente', 'color' => '#f0ad4e'],
        'en_progreso' => ['label' => 'En Progreso', 'color' => '#5bc0de'],
        'completada' => ['label' => 'Completada', 'color' => '#5cb85c'],
        'bloqueada' => ['label' => 'Bloqueada', 'color' => '#d9534f'],
        'cancelada' => ['label' => 'Cancelada', 'color' => '#777'],
    ];
}

/**
 * Obtiene tipos de tarea
 */
function riverso_get_task_types() {
    return [
        'cotizacion' => ['label' => 'Cotización pendiente', 'icon' => 'cart'],
        'picking' => ['label' => 'Picking para venta', 'icon' => 'clipboard'],
        'reposicion' => ['label' => 'Reposición de stock', 'icon' => 'update'],
        'recepcion' => ['label' => 'Recepción de mercadería', 'icon' => 'download'],
        'inventario' => ['label' => 'Conteo de inventario', 'icon' => 'list-view'],
        'ubicacion' => ['label' => 'Cambio de ubicación', 'icon' => 'move'],
        'etiquetado' => ['label' => 'Etiquetado de productos', 'icon' => 'tag'],
        'devolucion' => ['label' => 'Procesamiento de devolución', 'icon' => 'undo'],
        'codigo_faltante' => ['label' => 'Vincular código proveedor', 'icon' => 'warning'],
    ];
}

/**
 * Obtiene prioridades de tarea
 */
function riverso_get_task_priorities() {
    return [
        'baja' => ['label' => 'Baja', 'color' => '#777'],
        'normal' => ['label' => 'Normal', 'color' => '#5bc0de'],
        'alta' => ['label' => 'Alta', 'color' => '#f0ad4e'],
        'urgente' => ['label' => 'Urgente', 'color' => '#d9534f'],
    ];
}

/**
 * Crea una tarea automáticamente
 */
function riverso_create_task($tipo, $titulo, $data = []) {
    global $wpdb;
    
    $insert = array_merge([
        'tipo' => $tipo,
        'titulo' => $titulo,
        'estado' => 'pendiente',
        'prioridad' => 'normal',
        'creado_por' => get_current_user_id(),
    ], $data);
    
    $wpdb->insert($wpdb->prefix . 'riverso_tareas', $insert);
    
    return $wpdb->insert_id;
}

/**
 * Crea una tarea de revisión humana generada por un proceso automático.
 *
 * Wrapper global del helper del módulo de tareas. Marca created_by=computer y
 * requires_human_review=1, y deduplica por tipo + referencia.
 *
 * @param string $tipo            Tipo de tarea (ver Riverso_Task_Module::TASK_TYPES)
 * @param string $titulo          Título legible
 * @param string $referencia_tipo Tipo de entidad referenciada
 * @param int    $referencia_id   ID de la entidad referenciada
 * @param array  $extra           Datos adicionales: descripcion, prioridad, datos_extra
 * @return int|WP_Error|null      ID de la tarea o null si el módulo no está disponible
 */
function riverso_create_review_task($tipo, $titulo, $referencia_tipo = '', $referencia_id = 0, $extra = []) {
    if (!class_exists('Riverso_Task_Module')) {
        return null;
    }
    return Riverso_Task_Module::get_instance()->create_review_task(
        $tipo,
        $titulo,
        $referencia_tipo,
        $referencia_id,
        $extra
    );
}

/**
 * Registra un movimiento de inventario
 */
function riverso_log_movement($product_id, $tipo, $cantidad, $data = []) {
    global $wpdb;
    
    $product = wc_get_product($product_id);
    $stock_actual = $product ? $product->get_stock_quantity() : 0;
    
    $insert = array_merge([
        'product_id' => $product_id,
        'tipo' => $tipo,
        'cantidad' => $cantidad,
        'cantidad_anterior' => $stock_actual,
        'cantidad_posterior' => $stock_actual + $cantidad,
        'usuario_id' => get_current_user_id(),
    ], $data);
    
    $wpdb->insert($wpdb->prefix . 'riverso_movimientos', $insert);
    
    return $wpdb->insert_id;
}

/**
 * Obtiene configuración del plugin
 */
function riverso_get_setting($key, $default = null) {
    $settings = get_option('riverso_pos_settings', []);
    return $settings[$key] ?? $default;
}

/**
 * Guarda configuración del plugin
 */
function riverso_set_setting($key, $value) {
    $settings = get_option('riverso_pos_settings', []);
    $settings[$key] = $value;
    update_option('riverso_pos_settings', $settings);
}

/**
 * Resuelve la URL de destino de una tarea basada en su tipo de referencia.
 *
 * Mapea referencia_tipo -> URL para shortcuts "Ir a la tarea".
 *
 * @param array $task Fila de tarea con campos: id, tipo, referencia_tipo, referencia_id, datos_extra
 * @return string|null URL de destino o null si no resolvible
 */
function riverso_resolve_task_target($task) {
    $tipo = $task['referencia_tipo'] ?? null;
    $id = $task['referencia_id'] ?? null;

    if (!$tipo || !$id) {
        return null;
    }

    switch ($tipo) {
        case 'producto_proveedor':
            return add_query_arg('pp', (int) $id, home_url('/interno/catalog/')) . '#codigos';

        case 'producto_base':
            return add_query_arg('base', (int) $id, home_url('/interno/catalog/'));

        case 'factura_item':
            return add_query_arg('factura_item', (int) $id, admin_url('admin.php?page=riverso-pos-codes'));

        case 'factura':
            return add_query_arg('factura', (int) $id, admin_url('admin.php?page=riverso-pos-invoices'));

        default:
            return null;
    }
}

