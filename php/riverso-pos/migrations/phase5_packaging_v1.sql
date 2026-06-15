-- Fase 5: Embolsado / producto abierto
-- Migracion incremental no destructiva para Riverso POS.
-- Las tablas se crean via Riverso_Packaging_Module::create_tables() (dbDelta).
-- La columna producto_base.stock_abierto se agrega desde class-activator.php.

ALTER TABLE `{prefix}producto_base`
    ADD COLUMN `stock_abierto` DECIMAL(12,4) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `{prefix}envases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `sku_envase` VARCHAR(100) DEFAULT NULL,
    `woocommerce_variation_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `cantidad_unidades` DECIMAL(12,4) NOT NULL DEFAULT 1,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_base` (`producto_base_id`),
    UNIQUE KEY `ux_sku_envase` (`sku_envase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}aperturas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `envase_id` BIGINT UNSIGNED DEFAULT NULL,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `lote_id` BIGINT UNSIGNED DEFAULT NULL,
    `cantidad_envases` DECIMAL(12,4) NOT NULL DEFAULT 1,
    `cantidad_abierta` DECIMAL(12,4) NOT NULL,
    `costo_unitario` DECIMAL(12,4) DEFAULT NULL,
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `fecha` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_base` (`producto_base_id`),
    KEY `idx_envase` (`envase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}bolsas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `cantidad` DECIMAL(12,4) NOT NULL,
    `sku_bolsa` VARCHAR(100) DEFAULT NULL,
    `ean13` VARCHAR(20) DEFAULT NULL,
    `costo_unitario` DECIMAL(12,4) DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'generada',
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_base` (`producto_base_id`),
    KEY `idx_ean` (`ean13`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
