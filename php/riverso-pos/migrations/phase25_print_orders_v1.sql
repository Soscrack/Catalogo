-- Fase 25: Órdenes de impresión
-- Gestión de órdenes de etiquetas (crear, aprobar, imprimir, cancelar)
-- Aplicada vía class-activator.php (create_phase25_print_orders)
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

CREATE TABLE IF NOT EXISTS `{prefix}ordenes_impresion` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `numero_orden` VARCHAR(20) NOT NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'borrador',
  `tipo` VARCHAR(30) NOT NULL DEFAULT 'etiqueta_producto',
  `prioridad` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=normal, 1=urgente',
  `notas` TEXT DEFAULT NULL,
  `solicitado_por` BIGINT UNSIGNED DEFAULT NULL,
  `solicitado_por_nombre` VARCHAR(100) DEFAULT NULL,
  `aprobado_por` BIGINT UNSIGNED DEFAULT NULL,
  `impreso_por` BIGINT UNSIGNED DEFAULT NULL,
  `impresora_nombre` VARCHAR(100) DEFAULT NULL,
  `impreso_en` DATETIME DEFAULT NULL,
  `cancelado_por` BIGINT UNSIGNED DEFAULT NULL,
  `cancelado_en` DATETIME DEFAULT NULL,
  `motivo_cancelacion` TEXT DEFAULT NULL,
  `total_items` INT NOT NULL DEFAULT 0,
  `total_copias` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_orden` (`numero_orden`),
  KEY `estado` (`estado`),
  KEY `solicitado_por` (`solicitado_por`),
  KEY `created_at` (`created_at`),
  KEY `tipo` (`tipo`)
);

CREATE TABLE IF NOT EXISTS `{prefix}orden_impresion_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `orden_id` BIGINT UNSIGNED NOT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `precio` DECIMAL(12,2) DEFAULT NULL,
  `precio_original` DECIMAL(12,2) DEFAULT NULL,
  `cantidad_ean` INT NOT NULL DEFAULT 100,
  `copias` INT NOT NULL DEFAULT 1,
  `modo` VARCHAR(30) NOT NULL DEFAULT 'BolsaCOD',
  `color` VARCHAR(10) NOT NULL DEFAULT 'BN',
  `ean13` VARCHAR(13) DEFAULT NULL,
  `impreso` TINYINT(1) NOT NULL DEFAULT 0,
  `orden_posicion` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `orden_id` (`orden_id`),
  KEY `sku` (`sku`)
);
