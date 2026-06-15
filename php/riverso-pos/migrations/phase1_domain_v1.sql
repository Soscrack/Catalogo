-- Fase 1: Producto/Proveedor/Lote/Equivalencia
-- Migracion incremental no destructiva para Riverso POS.

CREATE TABLE IF NOT EXISTS `{prefix}riverso_producto_base` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `woocommerce_product_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `woocommerce_variation_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `canonical_sku` VARCHAR(100) DEFAULT NULL,
    `nombre_canonico` VARCHAR(255) DEFAULT NULL,
    `unidad_base` VARCHAR(20) DEFAULT 'unidad',
    `permite_decimal` TINYINT(1) DEFAULT 0,
    `permite_ean13_personalizado` TINYINT(1) DEFAULT 1,
    `stock_abierto_habilitado` TINYINT(1) DEFAULT 0,
    `codigo_abierto` VARCHAR(100) DEFAULT NULL,
    `estado` VARCHAR(20) DEFAULT 'activo',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_wc_ref` (`woocommerce_product_id`, `woocommerce_variation_id`),
    UNIQUE KEY `ux_canonical_sku` (`canonical_sku`),
    UNIQUE KEY `ux_codigo_abierto` (`codigo_abierto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}riverso_producto_proveedor` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `proveedor_id` BIGINT UNSIGNED NOT NULL,
    `supplier_link_id` BIGINT UNSIGNED DEFAULT NULL,
    `codigo_proveedor` VARCHAR(100) NOT NULL,
    `codigo_barras_proveedor` VARCHAR(50) DEFAULT NULL,
    `nombre_proveedor` VARCHAR(255) DEFAULT NULL,
    `unidad_compra` VARCHAR(20) DEFAULT NULL,
    `factor_conversion` DECIMAL(10,4) DEFAULT 1.0000,
    `precio_referencia` DECIMAL(12,2) DEFAULT NULL,
    `match_confidence` INT DEFAULT NULL,
    `es_preferido` TINYINT(1) DEFAULT 0,
    `activo` TINYINT(1) DEFAULT 1,
    `origen_datos` VARCHAR(50) DEFAULT 'manual',
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_proveedor_codigo` (`proveedor_id`, `codigo_proveedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}riverso_lotes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_proveedor_id` BIGINT UNSIGNED NOT NULL,
    `product_id` BIGINT UNSIGNED DEFAULT NULL,
    `variation_id` BIGINT UNSIGNED DEFAULT NULL,
    `lote_codigo` VARCHAR(100) NOT NULL,
    `fecha_recepcion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_vencimiento` DATE DEFAULT NULL,
    `cantidad_inicial` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `cantidad_disponible` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `costo_total` DECIMAL(12,2) DEFAULT NULL,
    `costo_unitario` DECIMAL(12,4) DEFAULT NULL,
    `moneda` VARCHAR(3) DEFAULT 'CLP',
    `estado` VARCHAR(20) DEFAULT 'abierto',
    `documento_tipo` VARCHAR(30) DEFAULT NULL,
    `documento_id` BIGINT UNSIGNED DEFAULT NULL,
    `documento_item_id` BIGINT UNSIGNED DEFAULT NULL,
    `origen_datos` VARCHAR(50) DEFAULT 'manual',
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_lote_proveedor` (`producto_proveedor_id`, `lote_codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}riverso_equivalence_groups` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo_grupo` VARCHAR(100) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `tipo_sustitucion` VARCHAR(20) DEFAULT 'compatible',
    `activo` TINYINT(1) DEFAULT 1,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_codigo_grupo` (`codigo_grupo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}riverso_equivalence_members` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grupo_id` BIGINT UNSIGNED NOT NULL,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `prioridad` INT DEFAULT 100,
    `es_reemplazo_preferido` TINYINT(1) DEFAULT 0,
    `activo` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_group_member` (`grupo_id`, `producto_base_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

