-- Fase 73: impresión directa ("Imprimir Ya!")
-- Hubs locales, impresoras reportadas, presets, ruteo por tipo de documento y cola de trabajos.
-- Aplicada vía class-activator.php (create_phase73_printing). Fechas en UTC escritas desde PHP.
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}print_agents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `token_hint` VARCHAR(8) NOT NULL DEFAULT '',
  `estado` VARCHAR(12) NOT NULL DEFAULT 'activo',
  `hostname` VARCHAR(100) NULL DEFAULT NULL,
  `version` VARCHAR(20) NULL DEFAULT NULL,
  `last_ip` VARCHAR(45) NULL DEFAULT NULL,
  `last_seen_at` DATETIME NULL DEFAULT NULL,
  `printers_at` DATETIME NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_print_agent_token` (`token_hash`)
);

CREATE TABLE IF NOT EXISTS `{prefix}print_printers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NOT NULL,
  `system_name` VARCHAR(191) NOT NULL,
  `alias` VARCHAR(100) NULL DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `es_virtual` TINYINT(1) NOT NULL DEFAULT 0,
  `presente` TINYINT(1) NOT NULL DEFAULT 1,
  `es_predeterminada` TINYINT(1) NOT NULL DEFAULT 0,
  `driver_name` VARCHAR(191) NULL DEFAULT NULL,
  `port_name` VARCHAR(191) NULL DEFAULT NULL,
  `host` VARCHAR(100) NULL DEFAULT NULL,
  `host_detectado` VARCHAR(100) NULL DEFAULT NULL,
  `estado` VARCHAR(16) NOT NULL DEFAULT 'desconocido',
  `estado_detalle` VARCHAR(255) NULL DEFAULT NULL,
  `papeles` TEXT NULL,
  `estado_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_print_printer` (`agent_id`, `system_name`)
);

CREATE TABLE IF NOT EXISTS `{prefix}print_presets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `printer_id` BIGINT UNSIGNED NOT NULL,
  `modo` VARCHAR(10) NOT NULL DEFAULT 'driver' COMMENT 'driver | escpos',
  `papel` VARCHAR(191) NULL DEFAULT NULL,
  `escala_modo` VARCHAR(12) NOT NULL DEFAULT 'ajustar' COMMENT 'ajustar | porcentaje | real',
  `escala_pct` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `copias` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `color` TINYINT(1) NOT NULL DEFAULT 1,
  `duplex` VARCHAR(6) NOT NULL DEFAULT 'no' COMMENT 'no | largo | corto',
  `orientacion` VARCHAR(12) NOT NULL DEFAULT 'auto',
  `ancho_puntos` SMALLINT UNSIGNED NOT NULL DEFAULT 384,
  `avance_mm` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_print_preset_printer` (`printer_id`)
);

CREATE TABLE IF NOT EXISTS `{prefix}print_stations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE IF NOT EXISTS `{prefix}print_routes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_type_id` INT NOT NULL COMMENT 'FACTO: 37 boleta, 2 factura, 41 boleta exenta, 32 factura exenta',
  `station_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = todas',
  `preset_id` BIGINT UNSIGNED NOT NULL,
  `fallback_preset_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_print_route` (`document_type_id`, `station_id`)
);

CREATE TABLE IF NOT EXISTS `{prefix}print_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `printer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `preset_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `fallback_preset_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `origen` VARCHAR(12) NOT NULL DEFAULT 'boton' COMMENT 'boton | emision | reintento | alternativa | prueba',
  `source_type` VARCHAR(12) NOT NULL DEFAULT 'dte' COMMENT 'dte | prueba',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `document_type_id` INT NOT NULL DEFAULT 0,
  `titulo` VARCHAR(150) NULL DEFAULT NULL,
  `station_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ajustes` TEXT NULL COMMENT 'Copia JSON del preset al encolar',
  `estado` VARCHAR(12) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | tomado | imprimiendo | impreso | error | vencido | cancelado',
  `detalle` VARCHAR(255) NULL DEFAULT NULL,
  `error_code` VARCHAR(32) NULL DEFAULT NULL,
  `error_msg` VARCHAR(255) NULL DEFAULT NULL,
  `requested_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NULL DEFAULT NULL,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `claimed_at` DATETIME NULL DEFAULT NULL,
  `touched_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_print_job_claim` (`agent_id`, `estado`),
  KEY `idx_print_job_estado` (`estado`),
  KEY `idx_print_job_source` (`source_type`, `source_id`)
);
