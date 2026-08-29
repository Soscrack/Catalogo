-- Fase 36: Producto unitario por familia + referencia legacy + órdenes de manufactura
-- Prefijo real: {prefix} = wp_riverso_

ALTER TABLE `{prefix}equivalence_groups`
    ADD COLUMN IF NOT EXISTS `es_producto_unitario` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `unit_producto_base_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `unit_config_at` DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `unit_config_by` BIGINT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `{prefix}producto_base`
    ADD COLUMN IF NOT EXISTS `es_unidad_minima` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `unit_of_grupo_id` BIGINT UNSIGNED NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `{prefix}legacy_precio_ref` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NOT NULL,
    `nombre` VARCHAR(255) DEFAULT NULL,
    `costo_neto` DECIMAL(12,4) DEFAULT NULL,
    `precio_neto` DECIMAL(12,2) DEFAULT NULL,
    `precio_total` DECIMAL(12,2) DEFAULT NULL,
    `codigo_barras` VARCHAR(50) DEFAULT NULL,
    `stock_bodega_general` DECIMAL(12,4) DEFAULT NULL,
    `stock_bodega_cajas` DECIMAL(12,4) DEFAULT NULL,
    `stock_otros` DECIMAL(12,4) DEFAULT NULL,
    `unidad` VARCHAR(20) DEFAULT NULL,
    `categoria` VARCHAR(255) DEFAULT NULL,
    `fuente` VARCHAR(64) NOT NULL DEFAULT 'legacy',
    `importado_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_sku_fuente` (`sku`, `fuente`),
    KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}manufactura_ordenes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo_proceso` VARCHAR(40) NOT NULL DEFAULT 'embolsar',
    `grupo_id` BIGINT UNSIGNED DEFAULT NULL,
    `unit_producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'borrador',
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_grupo` (`grupo_id`),
    KEY `idx_unit` (`unit_producto_base_id`),
    KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}manufactura_pasos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `orden_id` BIGINT UNSIGNED NOT NULL,
    `paso` VARCHAR(40) NOT NULL,
    `referencia_tipo` VARCHAR(40) DEFAULT NULL,
    `referencia_id` BIGINT UNSIGNED DEFAULT NULL,
    `detalle` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orden` (`orden_id`),
    KEY `idx_paso` (`paso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
