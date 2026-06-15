-- Fase 4: Reglas de precio por tramos (versionadas)
-- Migracion incremental no destructiva para Riverso POS.
-- Las tablas se crean via Riverso_Price_Rules_Module::create_tables() (dbDelta).

CREATE TABLE IF NOT EXISTS `{prefix}price_rules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo` VARCHAR(50) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'borrador',
    `aprobado_por` BIGINT UNSIGNED DEFAULT NULL,
    `aprobado_at` DATETIME DEFAULT NULL,
    `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_codigo_version` (`codigo`, `version`),
    KEY `idx_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}price_rule_tiers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_id` BIGINT UNSIGNED NOT NULL,
    `qty_min` INT NOT NULL DEFAULT 1,
    `qty_max` INT DEFAULT NULL,
    `formula_tipo` VARCHAR(20) NOT NULL DEFAULT 'multiplicador',
    `multiplicador` DECIMAL(8,4) DEFAULT NULL,
    `addendo` DECIMAL(12,2) DEFAULT NULL,
    `redondeo` VARCHAR(20) NOT NULL DEFAULT 'ninguno',
    `total_minimo` DECIMAL(12,2) DEFAULT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_rule` (`rule_id`),
    KEY `idx_orden` (`rule_id`, `orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}price_rule_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_id` BIGINT UNSIGNED NOT NULL,
    `target_tipo` VARCHAR(20) NOT NULL,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_target` (`target_tipo`, `target_id`),
    KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
