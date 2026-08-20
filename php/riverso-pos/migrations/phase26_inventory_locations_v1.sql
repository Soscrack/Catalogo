-- Fase 26: Ubicaciones preferidas, historial de conteos e inventario de bodega
-- Migracion incremental no destructiva para Riverso POS.
-- Aplicada via class-activator.php (create_phase26_inventory_locations)
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

ALTER TABLE `{prefix}ubicaciones`
    ADD COLUMN `barcode` VARCHAR(50) DEFAULT NULL,
    ADD COLUMN `zona` VARCHAR(50) DEFAULT NULL;

ALTER TABLE `{prefix}conteos`
    ADD COLUMN `nombre` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN `tipo_conteo` VARCHAR(30) NOT NULL DEFAULT 'general',
    ADD COLUMN `producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN `orden_id` BIGINT UNSIGNED DEFAULT NULL;

ALTER TABLE `{prefix}conteo_items`
    ADD COLUMN `ubicacion_id` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN `es_abierto` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `cantidad_manual` DECIMAL(12,4) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `{prefix}producto_ubicacion_preferida` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `ubicacion_id` BIGINT UNSIGNED NOT NULL,
    `es_preferido` TINYINT(1) NOT NULL DEFAULT 0,
    `prioridad` INT NOT NULL DEFAULT 100,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_ubicacion` (`producto_base_id`, `ubicacion_id`),
    KEY `idx_ubicacion` (`ubicacion_id`),
    KEY `idx_preferido` (`producto_base_id`, `es_preferido`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}producto_ubicacion_historial` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `ubicacion_id` BIGINT UNSIGNED NOT NULL,
    `conteo_id` BIGINT UNSIGNED DEFAULT NULL,
    `cantidad_contada` DECIMAL(12,4) NOT NULL DEFAULT 0,
    `fecha_conteo` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_producto_fecha` (`producto_base_id`, `fecha_conteo`),
    KEY `idx_ubicacion` (`ubicacion_id`),
    KEY `idx_conteo` (`conteo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}conteo_scan_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conteo_id` BIGINT UNSIGNED NOT NULL,
    `conteo_item_id` BIGINT UNSIGNED DEFAULT NULL,
    `ubicacion_id` BIGINT UNSIGNED DEFAULT NULL,
    `barcode_raw` VARCHAR(100) DEFAULT NULL,
    `tipo_barcode` VARCHAR(20) DEFAULT NULL,
    `producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    `envase_id` BIGINT UNSIGNED DEFAULT NULL,
    `cantidad_decodificada` DECIMAL(12,4) DEFAULT NULL,
    `es_abierto` TINYINT(1) NOT NULL DEFAULT 0,
    `accion` VARCHAR(20) NOT NULL DEFAULT 'scan',
    `usuario_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_conteo` (`conteo_id`),
    KEY `idx_item` (`conteo_item_id`),
    KEY `idx_producto` (`producto_base_id`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}ordenes_inventario` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `titulo` VARCHAR(150) DEFAULT NULL,
    `descripcion` TEXT DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    `tipo` VARCHAR(30) NOT NULL DEFAULT 'general',
    `prioridad` TINYINT(1) NOT NULL DEFAULT 0,
    `creado_por` BIGINT UNSIGNED DEFAULT NULL,
    `asignado_a` BIGINT UNSIGNED DEFAULT NULL,
    `fecha_programada` DATE DEFAULT NULL,
    `completado_en` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_tipo` (`tipo`),
    KEY `idx_asignado` (`asignado_a`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}orden_inventario_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `orden_id` BIGINT UNSIGNED NOT NULL,
    `ubicacion_id` BIGINT UNSIGNED DEFAULT NULL,
    `producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    `conteo_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orden` (`orden_id`),
    KEY `idx_ubicacion` (`ubicacion_id`),
    KEY `idx_producto` (`producto_base_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
