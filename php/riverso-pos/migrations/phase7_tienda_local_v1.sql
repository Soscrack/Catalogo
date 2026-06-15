-- Fase 7: Buscador de tienda local
-- Migracion incremental no destructiva para Riverso POS.
-- Las tablas se crean via Riverso_Tienda_Local_Module::create_tables() (dbDelta).

CREATE TABLE IF NOT EXISTS `{prefix}tienda_local_productos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `precio` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `stock` INT NOT NULL DEFAULT 0,
    `fecha_scraping` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_sku_unique` (`sku`),
    KEY `idx_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}tienda_local_barcodes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sku` VARCHAR(100) NOT NULL,
    `barcode` VARCHAR(50) NOT NULL,
    `barcode_norm` VARCHAR(50) NOT NULL,
    `fecha` DATE DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_barcode_unique` (`barcode`),
    KEY `idx_barcode` (`barcode`),
    KEY `idx_norm` (`barcode_norm`),
    KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
