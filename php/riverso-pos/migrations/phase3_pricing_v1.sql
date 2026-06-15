-- Fase 3: Motor de precios (LOCAL / ONLINE)
-- Migracion incremental no destructiva para Riverso POS.
-- La tabla se crea via Riverso_Pricing_Module::create_tables() (dbDelta).

CREATE TABLE IF NOT EXISTS `{prefix}precios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_base_id` BIGINT UNSIGNED NOT NULL,
    `canal` VARCHAR(10) NOT NULL DEFAULT 'local',
    `woocommerce_variation_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `c_ref` DECIMAL(12,4) DEFAULT NULL,
    `p_ref` DECIMAL(12,2) DEFAULT NULL,
    `p_asignado` DECIMAL(12,2) DEFAULT NULL,
    `factor_minimo` DECIMAL(5,2) NOT NULL DEFAULT 1.30,
    `factor_objetivo` DECIMAL(5,2) NOT NULL DEFAULT 1.80,
    `factor_maximo_referencia` DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    `estado_aprobacion` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    `alerta_margen` TINYINT(1) NOT NULL DEFAULT 0,
    `regla_id` BIGINT UNSIGNED DEFAULT NULL,
    `aprobado_por` BIGINT UNSIGNED DEFAULT NULL,
    `aprobado_at` DATETIME DEFAULT NULL,
    `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_precio_canal` (`producto_base_id`, `canal`, `woocommerce_variation_id`),
    KEY `idx_canal` (`canal`),
    KEY `idx_alerta` (`alerta_margen`),
    KEY `idx_estado` (`estado_aprobacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
