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
 * Obtiene tipos de tarea (etiquetas e iconos para UI).
 */
function riverso_get_task_types() {
    return [
        'cotizacion' => ['label' => 'Cotización pendiente', 'icon' => 'cart', 'categoria' => 'precios'],
        'picking' => ['label' => 'Picking para venta', 'icon' => 'clipboard', 'categoria' => 'inventario'],
        'reposicion' => ['label' => 'Reposición de stock', 'icon' => 'update', 'categoria' => 'inventario'],
        'recepcion' => ['label' => 'Recepción de mercadería', 'icon' => 'download', 'categoria' => 'inventario'],
        'inventario' => ['label' => 'Conteo de inventario', 'icon' => 'list-view', 'categoria' => 'inventario'],
        'ubicacion' => ['label' => 'Cambio de ubicación', 'icon' => 'move', 'categoria' => 'inventario'],
        'etiquetado' => ['label' => 'Etiquetado de productos', 'icon' => 'tag', 'categoria' => 'inventario'],
        'bodegaje' => ['label' => 'Ubicar en bodega', 'icon' => 'archive', 'categoria' => 'inventario'],
        'devolucion' => ['label' => 'Procesamiento de devolución', 'icon' => 'undo', 'categoria' => 'inventario'],
        'codigo_faltante' => ['label' => 'Vincular código proveedor', 'icon' => 'warning', 'categoria' => 'productos'],
        'barcode_faltante' => ['label' => 'Asignar código de barra', 'icon' => 'tag', 'categoria' => 'productos'],
        'confirmar_barcode_legacy' => ['label' => 'Confirmar código legacy', 'icon' => 'warning', 'categoria' => 'productos'],
        'confirmar_codigo_proveedor' => ['label' => 'Confirmar código proveedor (legacy)', 'icon' => 'warning', 'categoria' => 'productos'],
        'confirmar_tipo_documento' => ['label' => 'Confirmar tipo de documento', 'icon' => 'media-text', 'categoria' => 'administracion'],
        'revisar_relacion' => ['label' => 'Revisar relación de producto', 'icon' => 'randomize', 'categoria' => 'productos'],
        'validar_categoria' => ['label' => 'Validar categoría', 'icon' => 'category', 'categoria' => 'productos'],
        'verificar_etiquetado' => ['label' => 'Verificar etiquetado', 'icon' => 'tag', 'categoria' => 'productos'],
        'aprobar_lista_precios' => ['label' => 'Aprobar lista de precios', 'icon' => 'money-alt', 'categoria' => 'precios'],
        'asignar_regla_precio' => ['label' => 'Asignar regla de precio', 'icon' => 'chart-area', 'categoria' => 'precios'],
        'relacionar_producto_proveedor' => ['label' => 'Relacionar producto proveedor', 'icon' => 'admin-links', 'categoria' => 'productos'],
        'confirmar_relacion_online' => ['label' => 'Confirmar relación online', 'icon' => 'cloud', 'categoria' => 'productos'],
        'crear_contraparte_online' => ['label' => 'Crear contraparte online', 'icon' => 'cloud-upload', 'categoria' => 'productos'],
        'crear_contraparte_local' => ['label' => 'Crear contraparte local', 'icon' => 'admin-home', 'categoria' => 'productos'],
        'preguntar_familia' => ['label' => '¿Necesita familia?', 'icon' => 'groups', 'categoria' => 'productos'],
        'asignar_familia' => ['label' => 'Asignar familia', 'icon' => 'groups', 'categoria' => 'productos'],
        'confirmar_estructura_atributos' => ['label' => 'Confirmar estructura de atributos', 'icon' => 'editor-ul', 'categoria' => 'administracion'],
        'autorizar_publicacion' => ['label' => 'Autorizar publicación', 'icon' => 'visibility', 'categoria' => 'administracion'],
        'revisar_calidad_catalogo' => ['label' => 'Revisar salud del catálogo', 'icon' => 'heart', 'categoria' => 'administracion'],
    ];
}

/**
 * Categorías de tareas para filtros y agrupación.
 *
 * @return array<string,string>
 */
function riverso_get_task_categories() {
    if (class_exists('Riverso_Task_Module')) {
        return Riverso_Task_Module::TASK_CATEGORIES;
    }
    return [
        'administracion' => 'Administración',
        'productos' => 'Productos',
        'precios' => 'Precios',
        'inventario' => 'Inventario',
        'otros' => 'Otros',
    ];
}

/**
 * Modo de cierre de una tarea: guided | manual.
 */
function riverso_task_completion_mode($tipo) {
    if (class_exists('Riverso_Task_Module')) {
        return Riverso_Task_Module::get_completion_mode($tipo);
    }
    return 'guided';
}

/**
 * Orígenes canónicos de producto_proveedor.origen_datos.
 *
 * @return array<string,string>
 */
function riverso_pp_origen_labels() {
    return [
        'catalogo' => 'Catálogo',
        'legacy' => 'Legacy',
        'manual' => 'Manual',
        'factura' => 'Facturación',
        // aliases históricos
        'computer' => 'Catálogo',
        'riverso_codigos' => 'Legacy',
        'mamut_import' => 'Catálogo',
        'factura_intake' => 'Facturación',
    ];
}

/**
 * Etiqueta de origen/fuente para un código proveedor.
 *
 * @param array $row Fila con origen_datos y opcionalmente catalogo_id / catalogo_nombre.
 * @return string
 */
function riverso_pp_origen_label($row) {
    $row = is_array($row) ? $row : [];
    if (!empty($row['catalogo_id']) && !empty($row['catalogo_nombre'])) {
        return 'Catálogo: ' . $row['catalogo_nombre'];
    }
    if (!empty($row['catalogo_id']) && empty($row['catalogo_nombre'])) {
        return 'Catálogo';
    }
    $origen = (string) ($row['origen_datos'] ?? 'manual');
    $labels = riverso_pp_origen_labels();
    if (isset($labels[$origen])) {
        return $labels[$origen];
    }
    return ucfirst(str_replace('_', ' ', $origen));
}

/**
 * ¿El código proveedor requiere confirmación humana del vínculo a SKU local?
 *
 * @param array $row
 * @return bool
 */
function riverso_pp_needs_human_confirm($row) {
    $row = is_array($row) ? $row : [];
    if (empty($row['producto_base_id'])) {
        return false;
    }
    // Solo si hay SKU local real: el catálogo Mamut crea miles de bases sin canonical_sku.
    // En listados AJAX el campo suele venir como sku_local (alias de canonical_sku).
    $sku = trim((string) ($row['canonical_sku'] ?? $row['sku_local'] ?? ''));
    if ($sku === '') {
        return false;
    }
    $match = strtoupper((string) ($row['match_estado'] ?? ''));
    if ($match === 'VERIFIED') {
        return false;
    }
    if (!empty($row['requires_human_review']) && (string) ($row['review_status'] ?? '') === 'pendiente') {
        return true;
    }
    return false;
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
 * Crea una tarea automáticamente (fachada unificada).
 */
function riverso_create_task($tipo, $titulo, $data = []) {
    if (class_exists('Riverso_Task_Service')) {
        $result = Riverso_Task_Service::request($tipo, $titulo, $data);
        // Solo cortar el flujo si la fachada creó (o falló explícitamente) la tarea.
        // null = módulo no disponible → seguir con fallbacks.
        if ($result !== null) {
            return $result;
        }
    }

    if (class_exists('Riverso_Task_Module')) {
        return Riverso_Task_Module::get_instance()->create_task(array_merge([
            'tipo' => $tipo,
            'titulo' => $titulo,
        ], $data));
    }

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
 * @param array|object $task    Fila de tarea.
 * @param string       $context 'admin' (wp-admin) o 'portal' (/interno/...).
 * @return string|null URL de destino o null si no resolvible
 */
function riverso_resolve_task_target($task, $context = 'admin') {
    $task = is_object($task) ? (array) $task : $task;
    $referencia_tipo = $task['referencia_tipo'] ?? null;
    $referencia_id = isset($task['referencia_id']) ? (int) $task['referencia_id'] : 0;
    $task_tipo = $task['tipo'] ?? '';

    $extra = $task['datos_extra'] ?? [];
    if (is_string($extra)) {
        $extra = json_decode($extra, true) ?: [];
    }

    if ($referencia_tipo && $referencia_id) {
        $url = riverso_resolve_task_target_by_reference($task_tipo, $referencia_tipo, $referencia_id, $extra, $context);
        if ($url) {
            return $url;
        }
    }

    return riverso_resolve_task_target_by_type($task_tipo, $extra, $referencia_id, $context);
}

/**
 * Query args de tab/edición para el Hub de productos según tipo de tarea.
 *
 * @return array<string,string>
 */
function riverso_task_product_hub_tab_args($task_tipo) {
    $args = [];
    if ($task_tipo === 'crear_contraparte_local') {
        $args['tab'] = 'local';
        $args['edit'] = '1';
    } elseif (in_array($task_tipo, ['validar_categoria', 'crear_contraparte_online', 'confirmar_relacion_online', 'autorizar_publicacion', 'confirmar_estructura_atributos'], true)) {
        $args['tab'] = 'online';
        if ($task_tipo === 'confirmar_estructura_atributos') {
            $args['scroll'] = 'attributes';
        }
    } elseif (in_array($task_tipo, ['relacionar_producto_proveedor', 'codigo_faltante', 'confirmar_codigo_proveedor', 'revisar_relacion'], true)) {
        $args['tab'] = 'suppliers';
    } elseif (in_array($task_tipo, ['preguntar_familia', 'asignar_familia'], true)) {
        $args['tab'] = 'local';
    } elseif (in_array($task_tipo, ['barcode_faltante', 'confirmar_barcode_legacy'], true)) {
        $args['tab'] = 'barcodes';
    } elseif ($task_tipo === 'aprobar_lista_precios') {
        $args['tab'] = 'pricing';
    }
    return $args;
}

/**
 * URL al Hub de productos (admin o portal).
 */
function riverso_build_task_product_hub_url($producto_base_id, $task_tipo, $context = 'admin') {
    $producto_base_id = (int) $producto_base_id;
    if (!$producto_base_id) {
        return null;
    }

    $args = array_merge(['id' => $producto_base_id], riverso_task_product_hub_tab_args($task_tipo));
    if ($context === 'portal') {
        return add_query_arg($args, home_url('/interno/products/'));
    }

    $args['action'] = 'detail';
    return add_query_arg($args, admin_url('admin.php?page=riverso-pos-products'));
}

/**
 * URL de módulo portal interno.
 */
function riverso_task_portal_module_url($module, array $args = []) {
    $base = home_url('/interno/' . trim($module, '/') . '/');
    return empty($args) ? $base : add_query_arg($args, $base);
}

/**
 * Resuelve destino por entidad referenciada.
 */
function riverso_resolve_task_target_by_reference($task_tipo, $referencia_tipo, $referencia_id, array $extra = [], $context = 'admin') {
    global $wpdb;

    switch ($referencia_tipo) {
        case 'producto_base':
            return riverso_build_task_product_hub_url((int) $referencia_id, $task_tipo, $context);

        case 'codigo_barra':
            $pb_id = $wpdb->get_var($wpdb->prepare(
                "SELECT producto_base_id FROM {$wpdb->prefix}riverso_codigo_barra WHERE id = %d",
                (int) $referencia_id
            ));
            if (!$pb_id) {
                $pb_id = absint($extra['producto_base_id'] ?? 0);
            }
            if ($pb_id) {
                return riverso_build_task_product_hub_url((int) $pb_id, 'barcode_faltante', $context);
            }
            return $context === 'portal'
                ? riverso_task_portal_module_url('barcodes')
                : home_url('/interno/barcodes/');

        case 'producto_proveedor':
            $pb_id = $wpdb->get_var($wpdb->prepare(
                "SELECT producto_base_id FROM {$wpdb->prefix}riverso_producto_proveedor WHERE id = %d",
                (int) $referencia_id
            ));
            if (!$pb_id) {
                $pb_id = absint($extra['producto_base_id'] ?? 0);
            }
            if ($pb_id) {
                return riverso_build_task_product_hub_url((int) $pb_id, 'relacionar_producto_proveedor', $context);
            }
            if ($context === 'portal') {
                return riverso_task_portal_module_url('codes', ['pp' => (int) $referencia_id]);
            }
            return add_query_arg('pp', (int) $referencia_id, admin_url('admin.php?page=riverso-pos-codes'));

        case 'producto':
        case 'product':
            $pb_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}riverso_producto_base WHERE woocommerce_product_id = %d OR woocommerce_variation_id = %d LIMIT 1",
                (int) $referencia_id,
                (int) $referencia_id
            ));
            if (!$pb_id) {
                $pb_id = absint($extra['producto_base_id'] ?? 0);
            }
            if ($pb_id) {
                return riverso_build_task_product_hub_url((int) $pb_id, $task_tipo, $context);
            }
            return null;

        case 'factura_item':
            if ($context === 'portal') {
                return riverso_task_portal_module_url('codes', ['factura_item' => (int) $referencia_id]);
            }
            return add_query_arg('factura_item', (int) $referencia_id, admin_url('admin.php?page=riverso-pos-codes'));

        case 'factura':
            if ($context === 'portal') {
                return riverso_task_portal_module_url('invoices', ['factura' => (int) $referencia_id]);
            }
            return add_query_arg('factura', (int) $referencia_id, admin_url('admin.php?page=riverso-pos-invoices'));

        case 'precio':
            $pb_id = $wpdb->get_var($wpdb->prepare(
                "SELECT producto_base_id FROM {$wpdb->prefix}riverso_precios WHERE id = %d",
                (int) $referencia_id
            ));
            if ($pb_id) {
                return riverso_build_task_product_hub_url((int) $pb_id, 'aprobar_lista_precios', $context);
            }
            if ($context === 'portal') {
                return admin_url('admin.php?page=riverso-pos-pricing');
            }
            return admin_url('admin.php?page=riverso-pos-pricing');

        case 'familia':
        case 'equivalence_group':
            return add_query_arg([
                'page' => 'riverso-pos-price-rules',
                'assign' => 'familia',
                'target_id' => (int) $referencia_id,
                'grupo_id' => (int) $referencia_id,
            ], admin_url('admin.php'));

        case 'data_gap_rule':
            $args = ['page' => 'riverso-pos-catalog-health'];
            if (!empty($extra['regla'])) {
                $args['regla'] = sanitize_key($extra['regla']);
            }
            return add_query_arg($args, admin_url('admin.php'));

        case 'bolsa':
            $args = ['page' => 'riverso-pos-packaging'];
            if ($referencia_id) {
                $args['bolsa'] = (int) $referencia_id;
            }
            return add_query_arg($args, admin_url('admin.php'));

        default:
            return null;
    }
}

/**
 * Fallback: página del módulo según tipo de tarea.
 */
function riverso_resolve_task_target_by_type($task_tipo, array $extra = [], $referencia_id = 0, $context = 'admin') {
    $admin_pages = [
        'recepcion' => 'riverso-pos-reception',
        'inventario' => 'riverso-pos-warehouse',
        'ubicacion' => 'riverso-pos-warehouse',
        'bodegaje' => 'riverso-pos-warehouse',
        'picking' => 'riverso-pos-warehouse',
        'reposicion' => 'riverso-pos-warehouse',
        'etiquetado' => 'riverso-pos-packaging',
        'verificar_etiquetado' => 'riverso-pos-packaging',
        'cotizacion' => 'riverso-pos-received-quotes',
        'devolucion' => 'riverso-pos-invoices',
        'aprobar_lista_precios' => 'riverso-pos-pricing',
        'asignar_regla_precio' => 'riverso-pos-price-rules',
        'revisar_calidad_catalogo' => 'riverso-pos-catalog-health',
        'confirmar_tipo_documento' => 'riverso-pos-invoices',
        'autorizar_publicacion' => 'riverso-pos-publish',
        'confirmar_estructura_atributos' => 'riverso-pos-publish',
        'codigo_faltante' => 'riverso-pos-codes',
        'barcode_faltante' => 'riverso-pos-barcodes',
        'confirmar_barcode_legacy' => 'riverso-pos-barcodes',
        'confirmar_codigo_proveedor' => 'riverso-pos-codes',
        'preguntar_familia' => 'riverso-pos-categories',
        'asignar_familia' => 'riverso-pos-categories',
    ];

    $portal_modules = [
        'inventario' => 'warehouse',
        'ubicacion' => 'warehouse',
        'bodegaje' => 'warehouse',
        'picking' => 'warehouse',
        'reposicion' => 'warehouse',
        'cotizacion' => 'received-quotes',
        'devolucion' => 'invoices',
        'confirmar_tipo_documento' => 'invoices',
        'codigo_faltante' => 'codes',
        'barcode_faltante' => 'barcodes',
        'confirmar_barcode_legacy' => 'barcodes',
        'confirmar_codigo_proveedor' => 'codes',
        'preguntar_familia' => 'categories',
        'asignar_familia' => 'categories',
    ];

    $pb_id = absint($extra['producto_base_id'] ?? $extra['product_id'] ?? 0);
    if ($pb_id && in_array($task_tipo, [
        'validar_categoria', 'relacionar_producto_proveedor', 'crear_contraparte_local',
        'crear_contraparte_online', 'barcode_faltante', 'confirmar_barcode_legacy',
    ], true)) {
        return riverso_build_task_product_hub_url($pb_id, $task_tipo, $context);
    }

    if ($context === 'portal' && isset($portal_modules[$task_tipo])) {
        $args = [];
        if (in_array($task_tipo, ['devolucion', 'confirmar_tipo_documento'], true) && $referencia_id) {
            $args['factura'] = (int) $referencia_id;
        }
        return riverso_task_portal_module_url($portal_modules[$task_tipo], $args);
    }

    if (!isset($admin_pages[$task_tipo])) {
        return null;
    }

    $args = ['page' => $admin_pages[$task_tipo]];

    if ($task_tipo === 'revisar_calidad_catalogo' && !empty($extra['regla'])) {
        $args['regla'] = sanitize_key($extra['regla']);
    }
    if ($task_tipo === 'asignar_regla_precio') {
        $gid = absint($extra['grupo_id'] ?? $referencia_id);
        if ($gid) {
            $args['assign'] = 'familia';
            $args['target_id'] = $gid;
            $args['grupo_id'] = $gid;
        }
    }
    if ($task_tipo === 'confirmar_tipo_documento' && $referencia_id) {
        $args['factura'] = (int) $referencia_id;
    }
    if (in_array($task_tipo, ['etiquetado', 'bodegaje', 'recepcion', 'devolucion'], true) && $referencia_id) {
        $args['factura'] = (int) $referencia_id;
    }

    return add_query_arg($args, admin_url('admin.php'));
}

