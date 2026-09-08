-- Fase 44: workflow Procesar folios (asignación de precios desde factura)
-- Prefijo real: {prefix} = wp_riverso_

CREATE TABLE IF NOT EXISTS `{prefix}precio_folio_proceso` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `factura_id` BIGINT UNSIGNED NOT NULL,
    `estado` VARCHAR(40) NOT NULL DEFAULT 'pendiente',
    `estado_manual` VARCHAR(40) NULL DEFAULT NULL COMMENT 'Override: ingresada_manual|anulada',
    `blockers_json` LONGTEXT DEFAULT NULL,
    `items_omitidos_json` LONGTEXT DEFAULT NULL COMMENT 'IDs factura_items ya ingresados (híbrido)',
    `started_by` BIGINT UNSIGNED DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_factura` (`factura_id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_estado_manual` (`estado_manual`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
