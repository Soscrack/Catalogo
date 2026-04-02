<?php
/**
 * Schema de base de datos para Riverso POS
 * 
 * Este archivo define las tablas personalizadas del plugin.
 * Se ejecuta durante la activación del plugin.
 * 
 * Uso: Incluir en la clase de activación del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crea todas las tablas personalizadas del plugin
 */
function riverso_pos_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix . 'riverso_';
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    // =========================================
    // TABLA: PROVEEDORES
    // =========================================
    $table_proveedores = $prefix . 'proveedores';
    $sql_proveedores = "CREATE TABLE $table_proveedores (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rut VARCHAR(20) NOT NULL,
        razon_social VARCHAR(255) NOT NULL,
        giro VARCHAR(255) DEFAULT NULL,
        direccion VARCHAR(255) DEFAULT NULL,
        comuna VARCHAR(100) DEFAULT NULL,
        ciudad VARCHAR(100) DEFAULT NULL,
        telefono VARCHAR(50) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        contacto VARCHAR(255) DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        activo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY rut (rut),
        KEY activo (activo)
    ) $charset_collate;";
    
    dbDelta($sql_proveedores);
    
    // =========================================
    // TABLA: CÓDIGOS DE PRODUCTOS
    // Mapeo entre SKU local, código proveedor y código de barras
    // =========================================
    $table_codigos = $prefix . 'codigos';
    $sql_codigos = "CREATE TABLE $table_codigos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sku_local VARCHAR(100) DEFAULT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        codigo_proveedor VARCHAR(100) NOT NULL,
        codigo_tipo VARCHAR(20) DEFAULT 'INT1',
        codigo_barras VARCHAR(50) DEFAULT NULL,
        proveedor_id BIGINT UNSIGNED DEFAULT NULL,
        nombre_proveedor VARCHAR(255) DEFAULT NULL,
        unidad_medida VARCHAR(20) DEFAULT NULL,
        factor_conversion DECIMAL(10,4) DEFAULT 1.0000,
        precio_referencia DECIMAL(12,2) DEFAULT NULL,
        verificado TINYINT(1) DEFAULT 0,
        verificado_por BIGINT UNSIGNED DEFAULT NULL,
        verificado_at DATETIME DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY codigo_proveedor_proveedor (codigo_proveedor, proveedor_id),
        KEY sku_local (sku_local),
        KEY product_id (product_id),
        KEY proveedor_id (proveedor_id),
        KEY codigo_barras (codigo_barras),
        KEY verificado (verificado)
    ) $charset_collate;";
    
    dbDelta($sql_codigos);
    
    // =========================================
    // TABLA: HISTORIAL DE CÓDIGOS
    // Auditoría de cambios en códigos
    // =========================================
    $table_codigos_historial = $prefix . 'codigos_historial';
    $sql_codigos_historial = "CREATE TABLE $table_codigos_historial (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo_id BIGINT UNSIGNED NOT NULL,
        accion ENUM('crear', 'actualizar', 'eliminar', 'verificar', 'desvincular') NOT NULL,
        campo_modificado VARCHAR(50) DEFAULT NULL,
        valor_anterior TEXT DEFAULT NULL,
        valor_nuevo TEXT DEFAULT NULL,
        usuario_id BIGINT UNSIGNED DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY codigo_id (codigo_id),
        KEY usuario_id (usuario_id),
        KEY accion (accion),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    dbDelta($sql_codigos_historial);
    
    // =========================================
    // TABLA: FACTURAS/DOCUMENTOS DTE
    // =========================================
    $table_facturas = $prefix . 'facturas';
    $sql_facturas = "CREATE TABLE $table_facturas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tipo_dte INT NOT NULL,
        folio VARCHAR(50) NOT NULL,
        proveedor_id BIGINT UNSIGNED DEFAULT NULL,
        rut_emisor VARCHAR(20) NOT NULL,
        razon_social_emisor VARCHAR(255) DEFAULT NULL,
        fecha_emision DATE NOT NULL,
        fecha_vencimiento DATE DEFAULT NULL,
        monto_neto DECIMAL(12,2) DEFAULT 0,
        monto_iva DECIMAL(12,2) DEFAULT 0,
        monto_total DECIMAL(12,2) DEFAULT 0,
        estado ENUM('pendiente', 'procesando', 'recibido', 'parcial', 'rechazado', 'anulado') DEFAULT 'pendiente',
        xml_path VARCHAR(255) DEFAULT NULL,
        xml_hash VARCHAR(64) DEFAULT NULL,
        items_total INT DEFAULT 0,
        items_vinculados INT DEFAULT 0,
        procesado_por BIGINT UNSIGNED DEFAULT NULL,
        procesado_at DATETIME DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tipo_folio_rut (tipo_dte, folio, rut_emisor),
        KEY proveedor_id (proveedor_id),
        KEY rut_emisor (rut_emisor),
        KEY estado (estado),
        KEY fecha_emision (fecha_emision)
    ) $charset_collate;";
    
    dbDelta($sql_facturas);
    
    // =========================================
    // TABLA: ITEMS DE FACTURA
    // =========================================
    $table_factura_items = $prefix . 'factura_items';
    $sql_factura_items = "CREATE TABLE $table_factura_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        factura_id BIGINT UNSIGNED NOT NULL,
        numero_linea INT NOT NULL,
        codigo_proveedor VARCHAR(100) DEFAULT NULL,
        codigo_tipo VARCHAR(20) DEFAULT NULL,
        nombre VARCHAR(255) NOT NULL,
        descripcion TEXT DEFAULT NULL,
        cantidad DECIMAL(12,4) NOT NULL,
        unidad VARCHAR(20) DEFAULT NULL,
        precio_unitario DECIMAL(12,4) DEFAULT 0,
        descuento_porcentaje DECIMAL(5,2) DEFAULT NULL,
        descuento_monto DECIMAL(12,2) DEFAULT NULL,
        monto_total DECIMAL(12,2) DEFAULT 0,
        codigo_id BIGINT UNSIGNED DEFAULT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        estado ENUM('pendiente', 'vinculado', 'recibido', 'parcial', 'faltante', 'excedente') DEFAULT 'pendiente',
        cantidad_recibida DECIMAL(12,4) DEFAULT NULL,
        notas TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY factura_id (factura_id),
        KEY codigo_proveedor (codigo_proveedor),
        KEY codigo_id (codigo_id),
        KEY product_id (product_id),
        KEY estado (estado)
    ) $charset_collate;";
    
    dbDelta($sql_factura_items);
    
    // =========================================
    // TABLA: TAREAS
    // =========================================
    $table_tareas = $prefix . 'tareas';
    $sql_tareas = "CREATE TABLE $table_tareas (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        tipo ENUM('etiquetado', 'reordenar', 'codigo_faltante', 'verificar_stock', 'admin', 'otro') NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        descripcion TEXT DEFAULT NULL,
        prioridad ENUM('baja', 'normal', 'alta', 'urgente') DEFAULT 'normal',
        estado ENUM('pendiente', 'en_progreso', 'completada', 'bloqueada', 'cancelada') DEFAULT 'pendiente',
        factura_id BIGINT UNSIGNED DEFAULT NULL,
        factura_item_id BIGINT UNSIGNED DEFAULT NULL,
        product_id BIGINT UNSIGNED DEFAULT NULL,
        codigo_id BIGINT UNSIGNED DEFAULT NULL,
        ubicacion_id BIGINT UNSIGNED DEFAULT NULL,
        asignado_a BIGINT UNSIGNED DEFAULT NULL,
        creado_por BIGINT UNSIGNED DEFAULT NULL,
        fecha_limite DATETIME DEFAULT NULL,
        completado_por BIGINT UNSIGNED DEFAULT NULL,
        completado_at DATETIME DEFAULT NULL,
        metadata JSON DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY tipo (tipo),
        KEY estado (estado),
        KEY prioridad (prioridad),
        KEY asignado_a (asignado_a),
        KEY factura_id (factura_id),
        KEY product_id (product_id),
        KEY fecha_limite (fecha_limite)
    ) $charset_collate;";
    
    dbDelta($sql_tareas);
    
    // =========================================
    // TABLA: UBICACIONES DE BODEGA
    // =========================================
    $table_ubicaciones = $prefix . 'ubicaciones';
    $sql_ubicaciones = "CREATE TABLE $table_ubicaciones (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo VARCHAR(50) NOT NULL,
        nombre VARCHAR(100) DEFAULT NULL,
        pasillo VARCHAR(10) DEFAULT NULL,
        estante VARCHAR(10) DEFAULT NULL,
        nivel VARCHAR(10) DEFAULT NULL,
        posicion VARCHAR(10) DEFAULT NULL,
        tipo ENUM('estante', 'piso', 'colgado', 'refrigerado', 'otro') DEFAULT 'estante',
        capacidad_max INT DEFAULT NULL,
        activo TINYINT(1) DEFAULT 1,
        notas TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY codigo (codigo),
        KEY pasillo (pasillo),
        KEY activo (activo)
    ) $charset_collate;";
    
    dbDelta($sql_ubicaciones);
    
    // =========================================
    // TABLA: PRODUCTOS EN UBICACIONES
    // =========================================
    $table_producto_ubicacion = $prefix . 'producto_ubicacion';
    $sql_producto_ubicacion = "CREATE TABLE $table_producto_ubicacion (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED DEFAULT NULL,
        ubicacion_id BIGINT UNSIGNED NOT NULL,
        cantidad INT DEFAULT 0,
        es_principal TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY producto_ubicacion (product_id, variation_id, ubicacion_id),
        KEY product_id (product_id),
        KEY ubicacion_id (ubicacion_id),
        KEY es_principal (es_principal)
    ) $charset_collate;";
    
    dbDelta($sql_producto_ubicacion);
    
    // =========================================
    // TABLA: MOVIMIENTOS DE INVENTARIO
    // =========================================
    $table_movimientos = $prefix . 'movimientos';
    $sql_movimientos = "CREATE TABLE $table_movimientos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        variation_id BIGINT UNSIGNED DEFAULT NULL,
        tipo ENUM('entrada', 'salida', 'ajuste', 'transferencia', 'devolucion') NOT NULL,
        cantidad DECIMAL(12,4) NOT NULL,
        cantidad_anterior DECIMAL(12,4) DEFAULT NULL,
        cantidad_posterior DECIMAL(12,4) DEFAULT NULL,
        ubicacion_origen_id BIGINT UNSIGNED DEFAULT NULL,
        ubicacion_destino_id BIGINT UNSIGNED DEFAULT NULL,
        factura_id BIGINT UNSIGNED DEFAULT NULL,
        order_id BIGINT UNSIGNED DEFAULT NULL,
        referencia VARCHAR(100) DEFAULT NULL,
        motivo TEXT DEFAULT NULL,
        usuario_id BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY tipo (tipo),
        KEY factura_id (factura_id),
        KEY order_id (order_id),
        KEY usuario_id (usuario_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    dbDelta($sql_movimientos);
    
    // Guardar versión del schema
    update_option('riverso_pos_db_version', '1.0.0');
    
    return true;
}

/**
 * Elimina todas las tablas del plugin (para desinstalación)
 */
function riverso_pos_drop_tables() {
    global $wpdb;
    
    $prefix = $wpdb->prefix . 'riverso_';
    
    $tables = [
        'movimientos',
        'producto_ubicacion',
        'ubicaciones',
        'tareas',
        'factura_items',
        'facturas',
        'codigos_historial',
        'codigos',
        'proveedores'
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}");
    }
    
    delete_option('riverso_pos_db_version');
}

/**
 * Verifica si las tablas existen
 */
function riverso_pos_tables_exist() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'riverso_codigos';
    $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    
    return ($result === $table);
}

// =========================================
// HELPERS PARA OPERACIONES CRUD
// =========================================

/**
 * Inserta o actualiza un código de proveedor
 */
function riverso_upsert_codigo($data) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'riverso_codigos';
    
    // Verificar si existe
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table WHERE codigo_proveedor = %s AND proveedor_id = %d",
        $data['codigo_proveedor'],
        $data['proveedor_id'] ?? 0
    ));
    
    if ($existing) {
        // Actualizar
        $wpdb->update($table, $data, ['id' => $existing->id]);
        riverso_log_codigo_change($existing->id, 'actualizar', $data);
        return $existing->id;
    } else {
        // Insertar
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
        riverso_log_codigo_change($id, 'crear', $data);
        return $id;
    }
}

/**
 * Registra un cambio en el historial de códigos
 */
function riverso_log_codigo_change($codigo_id, $accion, $data = [], $campo = null, $valor_anterior = null) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'riverso_codigos_historial';
    
    $wpdb->insert($table, [
        'codigo_id' => $codigo_id,
        'accion' => $accion,
        'campo_modificado' => $campo,
        'valor_anterior' => $valor_anterior,
        'valor_nuevo' => is_array($data) ? json_encode($data) : $data,
        'usuario_id' => get_current_user_id(),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

/**
 * Busca un código por código de proveedor
 */
function riverso_buscar_codigo($codigo_proveedor, $proveedor_rut = null) {
    global $wpdb;
    
    $table_codigos = $wpdb->prefix . 'riverso_codigos';
    $table_proveedores = $wpdb->prefix . 'riverso_proveedores';
    
    $sql = "SELECT c.*, p.rut as proveedor_rut, p.razon_social as proveedor_nombre
            FROM $table_codigos c
            LEFT JOIN $table_proveedores p ON c.proveedor_id = p.id
            WHERE c.codigo_proveedor = %s";
    
    $params = [$codigo_proveedor];
    
    if ($proveedor_rut) {
        $sql .= " AND p.rut = %s";
        $params[] = $proveedor_rut;
    }
    
    return $wpdb->get_row($wpdb->prepare($sql, $params));
}

/**
 * Obtiene o crea un proveedor por RUT
 */
function riverso_get_or_create_proveedor($rut, $razon_social = null) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'riverso_proveedores';
    
    $proveedor = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE rut = %s",
        $rut
    ));
    
    if ($proveedor) {
        return $proveedor;
    }
    
    // Crear nuevo
    $wpdb->insert($table, [
        'rut' => $rut,
        'razon_social' => $razon_social ?: 'Proveedor ' . $rut
    ]);
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE id = %d",
        $wpdb->insert_id
    ));
}

/**
 * Importa una factura desde datos parseados
 */
function riverso_importar_factura($factura_data) {
    global $wpdb;
    
    $table_facturas = $wpdb->prefix . 'riverso_facturas';
    $table_items = $wpdb->prefix . 'riverso_factura_items';
    
    // Obtener o crear proveedor
    $proveedor = riverso_get_or_create_proveedor(
        $factura_data['emisor']['rut'],
        $factura_data['emisor']['razon_social']
    );
    
    // Verificar si la factura ya existe
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM $table_facturas WHERE tipo_dte = %d AND folio = %s AND rut_emisor = %s",
        $factura_data['tipo_dte'],
        $factura_data['folio'],
        $factura_data['emisor']['rut']
    ));
    
    if ($existing) {
        return ['success' => false, 'message' => 'Factura ya existe', 'id' => $existing->id];
    }
    
    // Insertar factura
    $wpdb->insert($table_facturas, [
        'tipo_dte' => $factura_data['tipo_dte'],
        'folio' => $factura_data['folio'],
        'proveedor_id' => $proveedor->id,
        'rut_emisor' => $factura_data['emisor']['rut'],
        'razon_social_emisor' => $factura_data['emisor']['razon_social'],
        'fecha_emision' => $factura_data['fecha_emision'],
        'fecha_vencimiento' => $factura_data['fecha_vencimiento'] ?? null,
        'monto_neto' => $factura_data['totales']['monto_neto'] ?? 0,
        'monto_iva' => $factura_data['totales']['iva'] ?? 0,
        'monto_total' => $factura_data['totales']['monto_total'] ?? 0,
        'items_total' => count($factura_data['items']),
        'xml_path' => $factura_data['archivo_origen'] ?? null
    ]);
    
    $factura_id = $wpdb->insert_id;
    
    // Insertar items
    $items_vinculados = 0;
    foreach ($factura_data['items'] as $item) {
        // Buscar código existente
        $codigo = riverso_buscar_codigo($item['codigo_valor'] ?? '', $factura_data['emisor']['rut']);
        
        $wpdb->insert($table_items, [
            'factura_id' => $factura_id,
            'numero_linea' => $item['numero_linea'],
            'codigo_proveedor' => $item['codigo_valor'] ?? null,
            'codigo_tipo' => $item['codigo_tipo'] ?? null,
            'nombre' => $item['nombre'],
            'descripcion' => $item['descripcion'] ?? null,
            'cantidad' => $item['cantidad'],
            'unidad' => $item['unidad'] ?? null,
            'precio_unitario' => $item['precio_unitario'],
            'descuento_porcentaje' => $item['descuento_porcentaje'] ?? null,
            'descuento_monto' => $item['descuento_monto'] ?? null,
            'monto_total' => $item['monto_item'],
            'codigo_id' => $codigo ? $codigo->id : null,
            'product_id' => $codigo ? $codigo->product_id : null,
            'estado' => $codigo ? 'vinculado' : 'pendiente'
        ]);
        
        if ($codigo) {
            $items_vinculados++;
        }
    }
    
    // Actualizar contador de items vinculados
    $wpdb->update($table_facturas, 
        ['items_vinculados' => $items_vinculados],
        ['id' => $factura_id]
    );
    
    return [
        'success' => true,
        'id' => $factura_id,
        'items_total' => count($factura_data['items']),
        'items_vinculados' => $items_vinculados
    ];
}
