-- ============================================
-- RIVERSO POS - Schema de Base de Datos
-- ============================================
-- Para WordPress/WooCommerce
-- Prefijo: {prefix}riverso_
-- ============================================

-- =========================================
-- TABLA: PROVEEDORES
-- Información de proveedores/emisores de facturas
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_proveedores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rut` VARCHAR(20) NOT NULL COMMENT 'RUT del proveedor (ej: 76141242-6)',
    `razon_social` VARCHAR(255) NOT NULL,
    `giro` VARCHAR(255) DEFAULT NULL,
    `direccion` VARCHAR(255) DEFAULT NULL,
    `comuna` VARCHAR(100) DEFAULT NULL,
    `ciudad` VARCHAR(100) DEFAULT NULL,
    `telefono` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `contacto` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre de contacto',
    `notas` TEXT DEFAULT NULL,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `rut` (`rut`),
    KEY `activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: CÓDIGOS DE PRODUCTOS
-- Mapeo entre SKU local ↔ código proveedor ↔ código de barras
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_codigos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku_local` VARCHAR(100) DEFAULT NULL COMMENT 'SKU en WooCommerce',
    `product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID producto WooCommerce',
    `codigo_proveedor` VARCHAR(100) NOT NULL COMMENT 'Código del proveedor',
    `codigo_tipo` VARCHAR(20) DEFAULT 'INT1' COMMENT 'Tipo: INT1, EAN, UPC, etc.',
    `codigo_barras` VARCHAR(50) DEFAULT NULL COMMENT 'Código de barras EAN/UPC',
    `proveedor_id` BIGINT UNSIGNED DEFAULT NULL,
    `nombre_proveedor` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre del producto según proveedor',
    `unidad_medida` VARCHAR(20) DEFAULT NULL COMMENT 'Unidad: UN, KG, LT, etc.',
    `factor_conversion` DECIMAL(10,4) DEFAULT 1.0000 COMMENT 'Factor para convertir unidad proveedor a local',
    `precio_referencia` DECIMAL(12,2) DEFAULT NULL COMMENT 'Último precio conocido',
    `verificado` TINYINT(1) DEFAULT 0 COMMENT 'Si fue verificado manualmente',
    `verificado_por` BIGINT UNSIGNED DEFAULT NULL,
    `verificado_at` DATETIME DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `codigo_proveedor_proveedor` (`codigo_proveedor`, `proveedor_id`),
    KEY `sku_local` (`sku_local`),
    KEY `product_id` (`product_id`),
    KEY `proveedor_id` (`proveedor_id`),
    KEY `codigo_barras` (`codigo_barras`),
    KEY `verificado` (`verificado`),
    CONSTRAINT `fk_codigos_proveedor` FOREIGN KEY (`proveedor_id`) 
        REFERENCES `{prefix}riverso_proveedores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: HISTORIAL DE CÓDIGOS
-- Auditoría completa de cambios
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_codigos_historial` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_id` BIGINT UNSIGNED NOT NULL,
    `accion` ENUM('crear', 'actualizar', 'eliminar', 'verificar', 'desvincular') NOT NULL,
    `campo_modificado` VARCHAR(50) DEFAULT NULL,
    `valor_anterior` TEXT DEFAULT NULL,
    `valor_nuevo` TEXT DEFAULT NULL,
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID usuario WordPress',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `codigo_id` (`codigo_id`),
    KEY `usuario_id` (`usuario_id`),
    KEY `accion` (`accion`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: FACTURAS/DOCUMENTOS DTE
-- Documentos tributarios electrónicos recibidos
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_facturas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_dte` INT NOT NULL COMMENT '33=Factura, 52=Guía, 61=NC',
    `folio` VARCHAR(50) NOT NULL,
    `proveedor_id` BIGINT UNSIGNED DEFAULT NULL,
    `rut_emisor` VARCHAR(20) NOT NULL,
    `razon_social_emisor` VARCHAR(255) DEFAULT NULL,
    `fecha_emision` DATE NOT NULL,
    `fecha_vencimiento` DATE DEFAULT NULL,
    `monto_neto` DECIMAL(12,2) DEFAULT 0,
    `monto_iva` DECIMAL(12,2) DEFAULT 0,
    `monto_total` DECIMAL(12,2) DEFAULT 0,
    `estado` ENUM('pendiente', 'procesando', 'recibido', 'parcial', 'rechazado', 'anulado') DEFAULT 'pendiente',
    `xml_path` VARCHAR(255) DEFAULT NULL COMMENT 'Ruta al archivo XML',
    `xml_hash` VARCHAR(64) DEFAULT NULL COMMENT 'SHA256 del XML',
    `items_total` INT DEFAULT 0,
    `items_vinculados` INT DEFAULT 0 COMMENT 'Items con código vinculado',
    `procesado_por` BIGINT UNSIGNED DEFAULT NULL,
    `procesado_at` DATETIME DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tipo_folio_rut` (`tipo_dte`, `folio`, `rut_emisor`),
    KEY `proveedor_id` (`proveedor_id`),
    KEY `rut_emisor` (`rut_emisor`),
    KEY `estado` (`estado`),
    KEY `fecha_emision` (`fecha_emision`),
    CONSTRAINT `fk_facturas_proveedor` FOREIGN KEY (`proveedor_id`) 
        REFERENCES `{prefix}riverso_proveedores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: ITEMS DE FACTURA
-- Detalle de cada línea de la factura
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_factura_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `factura_id` BIGINT UNSIGNED NOT NULL,
    `numero_linea` INT NOT NULL,
    `codigo_proveedor` VARCHAR(100) DEFAULT NULL,
    `codigo_tipo` VARCHAR(20) DEFAULT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `descripcion` TEXT DEFAULT NULL,
    `cantidad` DECIMAL(12,4) NOT NULL,
    `unidad` VARCHAR(20) DEFAULT NULL,
    `precio_unitario` DECIMAL(12,4) DEFAULT 0,
    `descuento_porcentaje` DECIMAL(5,2) DEFAULT NULL,
    `descuento_monto` DECIMAL(12,2) DEFAULT NULL,
    `monto_total` DECIMAL(12,2) DEFAULT 0,
    `codigo_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Vínculo a tabla códigos',
    `product_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Producto WooCommerce',
    `estado` ENUM('pendiente', 'vinculado', 'recibido', 'parcial', 'faltante', 'excedente') DEFAULT 'pendiente',
    `cantidad_recibida` DECIMAL(12,4) DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `factura_id` (`factura_id`),
    KEY `codigo_proveedor` (`codigo_proveedor`),
    KEY `codigo_id` (`codigo_id`),
    KEY `product_id` (`product_id`),
    KEY `estado` (`estado`),
    CONSTRAINT `fk_items_factura` FOREIGN KEY (`factura_id`) 
        REFERENCES `{prefix}riverso_facturas` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_items_codigo` FOREIGN KEY (`codigo_id`) 
        REFERENCES `{prefix}riverso_codigos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: TAREAS OPERATIVAS
-- Sistema de tareas para empleados
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_tareas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` ENUM('etiquetado', 'reordenar', 'codigo_faltante', 'verificar_stock', 'admin', 'otro') NOT NULL,
    `titulo` VARCHAR(255) NOT NULL,
    `descripcion` TEXT DEFAULT NULL,
    `prioridad` ENUM('baja', 'normal', 'alta', 'urgente') DEFAULT 'normal',
    `estado` ENUM('pendiente', 'en_progreso', 'completada', 'bloqueada', 'cancelada') DEFAULT 'pendiente',
    `factura_id` BIGINT UNSIGNED DEFAULT NULL,
    `factura_item_id` BIGINT UNSIGNED DEFAULT NULL,
    `product_id` BIGINT UNSIGNED DEFAULT NULL,
    `codigo_id` BIGINT UNSIGNED DEFAULT NULL,
    `ubicacion_id` BIGINT UNSIGNED DEFAULT NULL,
    `asignado_a` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Usuario WordPress asignado',
    `creado_por` BIGINT UNSIGNED DEFAULT NULL,
    `fecha_limite` DATETIME DEFAULT NULL,
    `completado_por` BIGINT UNSIGNED DEFAULT NULL,
    `completado_at` DATETIME DEFAULT NULL,
    `metadata` JSON DEFAULT NULL COMMENT 'Datos adicionales específicos del tipo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `tipo` (`tipo`),
    KEY `estado` (`estado`),
    KEY `prioridad` (`prioridad`),
    KEY `asignado_a` (`asignado_a`),
    KEY `factura_id` (`factura_id`),
    KEY `product_id` (`product_id`),
    KEY `fecha_limite` (`fecha_limite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: UBICACIONES DE BODEGA
-- Definición de espacios físicos
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_ubicaciones` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL COMMENT 'Ej: A-01-03 (Pasillo A, Estante 1, Nivel 3)',
    `nombre` VARCHAR(100) DEFAULT NULL,
    `pasillo` VARCHAR(10) DEFAULT NULL,
    `estante` VARCHAR(10) DEFAULT NULL,
    `nivel` VARCHAR(10) DEFAULT NULL,
    `posicion` VARCHAR(10) DEFAULT NULL,
    `tipo` ENUM('estante', 'piso', 'colgado', 'refrigerado', 'otro') DEFAULT 'estante',
    `capacidad_max` INT DEFAULT NULL COMMENT 'Capacidad máxima en unidades',
    `activo` TINYINT(1) DEFAULT 1,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `codigo` (`codigo`),
    KEY `pasillo` (`pasillo`),
    KEY `activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: PRODUCTOS EN UBICACIONES
-- Asignación de productos a ubicaciones
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_producto_ubicacion` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL COMMENT 'Producto WooCommerce',
    `variation_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Variación si aplica',
    `ubicacion_id` BIGINT UNSIGNED NOT NULL,
    `cantidad` INT DEFAULT 0 COMMENT 'Cantidad en esta ubicación',
    `es_principal` TINYINT(1) DEFAULT 0 COMMENT 'Ubicación principal del producto',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `producto_ubicacion` (`product_id`, `variation_id`, `ubicacion_id`),
    KEY `product_id` (`product_id`),
    KEY `ubicacion_id` (`ubicacion_id`),
    KEY `es_principal` (`es_principal`),
    CONSTRAINT `fk_produbi_ubicacion` FOREIGN KEY (`ubicacion_id`) 
        REFERENCES `{prefix}riverso_ubicaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- TABLA: MOVIMIENTOS DE INVENTARIO
-- Registro de todos los movimientos de stock
-- =========================================
CREATE TABLE IF NOT EXISTS `{prefix}riverso_movimientos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` BIGINT UNSIGNED NOT NULL,
    `variation_id` BIGINT UNSIGNED DEFAULT NULL,
    `tipo` ENUM('entrada', 'salida', 'ajuste', 'transferencia', 'devolucion') NOT NULL,
    `cantidad` DECIMAL(12,4) NOT NULL COMMENT 'Positivo=entrada, Negativo=salida',
    `cantidad_anterior` DECIMAL(12,4) DEFAULT NULL,
    `cantidad_posterior` DECIMAL(12,4) DEFAULT NULL,
    `ubicacion_origen_id` BIGINT UNSIGNED DEFAULT NULL,
    `ubicacion_destino_id` BIGINT UNSIGNED DEFAULT NULL,
    `factura_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Factura de compra relacionada',
    `order_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Pedido WooCommerce relacionado',
    `referencia` VARCHAR(100) DEFAULT NULL COMMENT 'Referencia externa',
    `motivo` TEXT DEFAULT NULL,
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `product_id` (`product_id`),
    KEY `tipo` (`tipo`),
    KEY `factura_id` (`factura_id`),
    KEY `order_id` (`order_id`),
    KEY `usuario_id` (`usuario_id`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================
-- VISTAS ÚTILES
-- =========================================

-- Vista: Códigos pendientes de verificación
CREATE OR REPLACE VIEW `{prefix}riverso_v_codigos_pendientes` AS
SELECT 
    c.id,
    c.codigo_proveedor,
    c.codigo_tipo,
    c.nombre_proveedor,
    c.sku_local,
    p.razon_social as proveedor,
    p.rut as proveedor_rut,
    c.created_at
FROM `{prefix}riverso_codigos` c
LEFT JOIN `{prefix}riverso_proveedores` p ON c.proveedor_id = p.id
WHERE c.verificado = 0 AND c.sku_local IS NULL;

-- Vista: Resumen de facturas por estado
CREATE OR REPLACE VIEW `{prefix}riverso_v_facturas_resumen` AS
SELECT 
    f.estado,
    COUNT(*) as total,
    SUM(f.monto_total) as monto_total,
    SUM(f.items_total) as items_total,
    SUM(f.items_vinculados) as items_vinculados
FROM `{prefix}riverso_facturas` f
GROUP BY f.estado;

-- Vista: Tareas pendientes por usuario
CREATE OR REPLACE VIEW `{prefix}riverso_v_tareas_pendientes` AS
SELECT 
    t.asignado_a,
    t.tipo,
    COUNT(*) as total,
    SUM(CASE WHEN t.prioridad = 'urgente' THEN 1 ELSE 0 END) as urgentes,
    SUM(CASE WHEN t.prioridad = 'alta' THEN 1 ELSE 0 END) as altas
FROM `{prefix}riverso_tareas` t
WHERE t.estado IN ('pendiente', 'en_progreso')
GROUP BY t.asignado_a, t.tipo;
