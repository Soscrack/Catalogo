-- Fase 27: Órdenes de ordenar (traslados de productos a su lugar)
-- Migracion incremental no destructiva para Riverso POS.
-- Aplicada via class-activator.php (create_phase27_sort_orders)
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}ordenes_ordenar` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `titulo` VARCHAR(150) DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    `ubicacion_origen_id` BIGINT UNSIGNED DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `creado_por` BIGINT UNSIGNED DEFAULT NULL,
    `asignado_a` BIGINT UNSIGNED DEFAULT NULL,
    `completado_en` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_origen` (`ubicacion_origen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}orden_ordenar_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `orden_id` BIGINT UNSIGNED NOT NULL,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `cantidad` INT NOT NULL DEFAULT 1,
    `ubicacion_destino_id` BIGINT UNSIGNED DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    `movement_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orden` (`orden_id`),
    KEY `idx_producto` (`producto_base_id`),
    KEY `idx_destino` (`ubicacion_destino_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
