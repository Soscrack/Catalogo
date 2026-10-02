-- Fase 63: tabla de clientes comerciales (solo clientes, no proveedores)
-- Prefijo real: {wp_}riverso_clientes
--
-- El activador PHP aplica esto de forma idempotente (dbDelta + ensure).

CREATE TABLE IF NOT EXISTS `{prefix}clientes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre_fantasia` VARCHAR(255) NOT NULL,
  `has_contacto` TINYINT(1) NOT NULL DEFAULT 1,
  `primer_nombre` VARCHAR(100) DEFAULT NULL,
  `apellido_paterno` VARCHAR(100) DEFAULT NULL,
  `contacto_telefono` VARCHAR(50) DEFAULT NULL,
  `contacto_email` VARCHAR(191) DEFAULT NULL,
  `has_facturacion` TINYINT(1) NOT NULL DEFAULT 1,
  `pais` VARCHAR(50) NOT NULL DEFAULT 'CHILE',
  `tipo_identificacion` VARCHAR(50) NOT NULL DEFAULT 'RUT_CLIENTE',
  `rut` VARCHAR(20) DEFAULT NULL,
  `razon_social` VARCHAR(255) DEFAULT NULL,
  `direccion` VARCHAR(255) DEFAULT NULL,
  `comuna` VARCHAR(100) DEFAULT NULL,
  `ciudad` VARCHAR(100) DEFAULT NULL,
  `giro` VARCHAR(255) DEFAULT NULL,
  `facturacion_telefono` VARCHAR(50) DEFAULT NULL,
  `codigo_postal` VARCHAR(20) DEFAULT '0',
  `has_datos_extra` TINYINT(1) NOT NULL DEFAULT 1,
  `datos_extra` LONGTEXT DEFAULT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clientes_fantasia` (`nombre_fantasia`),
  KEY `idx_clientes_rut` (`rut`),
  KEY `idx_clientes_email` (`contacto_email`),
  KEY `idx_clientes_activo` (`activo`)
);
