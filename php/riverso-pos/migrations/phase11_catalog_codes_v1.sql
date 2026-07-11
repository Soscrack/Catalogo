-- Fase 2: Catálogo y modelo unificado de códigos
-- Crea tabla unificada de códigos de barras con soporte para proveedor+cantidad+unidad+envase

-- Tabla unificada de códigos de barras
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_codigo_barra (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    tipo ENUM('ean13', 'supplier', 'internal') DEFAULT 'ean13',
    producto_base_id BIGINT UNSIGNED NOT NULL,
    proveedor_id BIGINT UNSIGNED DEFAULT NULL,
    cantidad DECIMAL(10, 3) NOT NULL,
    unidad_medida VARCHAR(20) NOT NULL,
    envase_id BIGINT UNSIGNED DEFAULT NULL,
    factor_a_unidad_base DECIMAL(10, 3) DEFAULT 1,
    activo TINYINT(1) DEFAULT 1,
    migrado_de_tabla VARCHAR(50) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_codigo (codigo),
    KEY idx_producto (producto_base_id),
    KEY idx_proveedor (proveedor_id),
    KEY idx_tipo (tipo),
    KEY idx_activo (activo),
    FOREIGN KEY (producto_base_id) REFERENCES {PREFIX}riverso_producto_base(id) ON DELETE CASCADE,
    FOREIGN KEY (proveedor_id) REFERENCES {PREFIX}riverso_proveedores(id) ON DELETE SET NULL,
    FOREIGN KEY (envase_id) REFERENCES {PREFIX}riverso_envases(id) ON DELETE SET NULL
) COLLATE utf8mb4_unicode_ci;

-- Índices para búsqueda rápida
CREATE INDEX IF NOT EXISTS idx_codigo_barra_codigo_tipo ON {PREFIX}riverso_codigo_barra(codigo, tipo);
CREATE INDEX IF NOT EXISTS idx_codigo_barra_producto_proveedor ON {PREFIX}riverso_codigo_barra(producto_base_id, proveedor_id);

-- Tabla de atributos (extraída de WooCommerce)
-- Esta tabla almacena atributos que aplican a múltiples productos
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_producto_atributo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    producto_base_id BIGINT UNSIGNED NOT NULL,
    atributo_nombre VARCHAR(100) NOT NULL,
    atributo_valor VARCHAR(255) NOT NULL,
    posicion INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_producto_atributo (producto_base_id, atributo_nombre),
    FOREIGN KEY (producto_base_id) REFERENCES {PREFIX}riverso_producto_base(id) ON DELETE CASCADE
) COLLATE utf8mb4_unicode_ci;

-- Tabla de equivalencias (productos intercambiables)
-- Un grupo de productos que pueden sustituirse entre sí
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_equivalencia_grupo (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(255) NOT NULL,
    descripcion TEXT DEFAULT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activo (activo)
) COLLATE utf8mb4_unicode_ci;

-- Membresía en grupos de equivalencia
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_equivalencia_miembro (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL,
    prioridad INT DEFAULT 0,
    factor_conversion DECIMAL(10, 3) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_grupo_producto (grupo_id, producto_base_id),
    KEY idx_grupo (grupo_id),
    FOREIGN KEY (grupo_id) REFERENCES {PREFIX}riverso_equivalencia_grupo(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_base_id) REFERENCES {PREFIX}riverso_producto_base(id) ON DELETE CASCADE
) COLLATE utf8mb4_unicode_ci;

-- Log de migración de códigos
CREATE TABLE IF NOT EXISTS {PREFIX}riverso_codigo_migracion_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo_barra_id BIGINT UNSIGNED NOT NULL,
    tabla_origen VARCHAR(50) NOT NULL,
    id_origen BIGINT UNSIGNED DEFAULT NULL,
    notas TEXT DEFAULT NULL,
    migrado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tabla_origen (tabla_origen),
    FOREIGN KEY (codigo_barra_id) REFERENCES {PREFIX}riverso_codigo_barra(id) ON DELETE CASCADE
) COLLATE utf8mb4_unicode_ci;
