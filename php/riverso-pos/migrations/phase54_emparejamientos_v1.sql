-- Fase 54: Emparejamientos de precio y/o stock
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}emparejamientos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(40) NOT NULL,
    `nombre` VARCHAR(191) NOT NULL,
    `emparejar_precios` TINYINT(1) NOT NULL DEFAULT 0,
    `emparejar_stock` TINYINT(1) NOT NULL DEFAULT 0,
    `stock_minimo` INT DEFAULT NULL,
    `stock_critico` INT DEFAULT NULL,
    `precios_usados` TINYINT(1) NOT NULL DEFAULT 0,
    `stock_usados` TINYINT(1) NOT NULL DEFAULT 0,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `notas` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_codigo` (`codigo`),
    KEY `idx_activo` (`activo`),
    KEY `idx_precios` (`emparejar_precios`),
    KEY `idx_stock` (`emparejar_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}emparejamiento_miembros` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `emparejamiento_id` BIGINT UNSIGNED NOT NULL,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `prioridad` INT NOT NULL DEFAULT 100,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_emp_producto` (`emparejamiento_id`, `producto_base_id`),
    KEY `idx_emparejamiento` (`emparejamiento_id`),
    KEY `idx_producto` (`producto_base_id`),
    KEY `idx_producto_activo` (`producto_base_id`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
