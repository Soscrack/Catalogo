-- Fase 81: Asistente de Descuento
-- Prefijo real: {prefix} = wp_riverso_
--
-- competencia_match.actitud: actitud del vínculo (codicioso | competitivo | sin_clasificar).
-- competencia_competidores: actitud por dominio del competidor; la del vínculo manda,
--   y si el vínculo está «sin clasificar» se usa la del dominio.
-- discount_origin: de dónde salió el descuento de la línea
--   (sugerido | maximo | minimo | marcador | historial | manual).
-- descuento_historial: descuentos de cotizaciones aceptadas y documentos emitidos.
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase81_discount_assistant).

ALTER TABLE `{prefix}competencia_match`
  ADD COLUMN IF NOT EXISTS `actitud` VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `discount_origin` VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE `{prefix}billing_draft_lines`
  ADD COLUMN IF NOT EXISTS `discount_origin` VARCHAR(16) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_competidores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dominio` VARCHAR(191) NOT NULL,
    `nombre` VARCHAR(120) DEFAULT NULL,
    `actitud` VARCHAR(16) NOT NULL DEFAULT 'sin_clasificar',
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_dominio` (`dominio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}descuento_historial` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    `sku` VARCHAR(64) DEFAULT NULL,
    `descripcion` VARCHAR(255) DEFAULT NULL,
    `evento` VARCHAR(16) NOT NULL DEFAULT 'aceptada' COMMENT 'aceptada | emitida',
    `cotizacion_id` BIGINT UNSIGNED DEFAULT NULL,
    `borrador_id` BIGINT UNSIGNED DEFAULT NULL,
    `dte_id` BIGINT UNSIGNED DEFAULT NULL,
    `documento` VARCHAR(80) DEFAULT NULL,
    `cliente` VARCHAR(191) DEFAULT NULL,
    `cantidad` DECIMAL(14,3) NOT NULL DEFAULT 0,
    `unidades` DECIMAL(14,3) NOT NULL DEFAULT 0,
    `costo_unitario` DECIMAL(14,4) DEFAULT NULL,
    `bruto` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `descuento_monto` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `descuento_pct` DECIMAL(8,4) NOT NULL DEFAULT 0,
    `total_final` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `precio_final_unitario` DECIMAL(16,4) DEFAULT NULL,
    `factor_final` DECIMAL(10,4) DEFAULT NULL,
    `discount_anchor` VARCHAR(16) DEFAULT NULL,
    `discount_anchor_value` DECIMAL(18,6) DEFAULT NULL,
    `origen` VARCHAR(16) DEFAULT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_producto` (`producto_base_id`, `created_at`),
    KEY `idx_cotizacion` (`cotizacion_id`),
    KEY `idx_borrador` (`borrador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{prefix}competencia_competidores` (dominio, nombre, actitud) VALUES
  ('sande.cl', 'Sande', 'competitivo'),
  ('dimafi.cl', 'DIMAFI', 'competitivo'),
  ('sodimac.cl', 'Sodimac', 'codicioso');
