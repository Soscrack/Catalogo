-- Fase 30: catálogo maestro de tipos de envase
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}envase_tipos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(30) NOT NULL,
    `nombre` VARCHAR(80) NOT NULL,
    `descripcion` VARCHAR(255) DEFAULT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `orden` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{prefix}envase_tipos` (`slug`, `nombre`, `descripcion`, `activo`, `orden`)
VALUES
    ('envase', 'Envase', 'Unidad de venta o compra cerrada', 1, 10),
    ('caja', 'Caja', 'Caja con varias unidades', 1, 20),
    ('balde', 'Balde', 'Balde o contenedor', 1, 30);
