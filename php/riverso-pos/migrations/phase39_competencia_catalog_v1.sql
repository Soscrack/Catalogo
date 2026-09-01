-- Fase 39: catálogo de competencia (staging + matching supervisado)
-- Placeholder {prefix} = wp_riverso_

CREATE TABLE IF NOT EXISTS `{prefix}competencia_fuentes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(50) NOT NULL,
    `nombre` VARCHAR(120) NOT NULL,
    `base_url` VARCHAR(255) DEFAULT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_categorias` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fuente_id` BIGINT UNSIGNED NOT NULL,
    `id_division` VARCHAR(32) NOT NULL DEFAULT '',
    `id_seccion` VARCHAR(32) NOT NULL DEFAULT '',
    `id_categoria` VARCHAR(32) NOT NULL,
    `nombre_division` VARCHAR(255) DEFAULT NULL,
    `nombre_seccion` VARCHAR(255) DEFAULT NULL,
    `nombre_categoria` VARCHAR(255) DEFAULT NULL,
    `link_imagen` VARCHAR(500) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_fuente_categoria` (`fuente_id`, `id_categoria`),
    KEY `idx_seccion` (`fuente_id`, `id_seccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_productos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `fuente_id` BIGINT UNSIGNED NOT NULL,
    `id_externo` VARCHAR(32) NOT NULL,
    `codigo_externo` VARCHAR(100) DEFAULT NULL,
    `codigo_normalizado` VARCHAR(100) DEFAULT NULL,
    `nombre` VARCHAR(500) DEFAULT NULL,
    `slug` VARCHAR(255) DEFAULT NULL,
    `url_producto` VARCHAR(500) DEFAULT NULL,
    `marca` VARCHAR(120) DEFAULT NULL,
    `id_marca` VARCHAR(32) DEFAULT NULL,
    `fabricante` VARCHAR(120) DEFAULT NULL,
    `categoria_id` BIGINT UNSIGNED DEFAULT NULL,
    `id_division` VARCHAR(32) DEFAULT NULL,
    `id_seccion` VARCHAR(32) DEFAULT NULL,
    `id_categoria` VARCHAR(32) DEFAULT NULL,
    `nombre_division` VARCHAR(255) DEFAULT NULL,
    `nombre_seccion` VARCHAR(255) DEFAULT NULL,
    `nombre_categoria` VARCHAR(255) DEFAULT NULL,
    `unidad_min_venta` VARCHAR(32) DEFAULT NULL,
    `tipo_unidad` VARCHAR(20) DEFAULT NULL,
    `peso` VARCHAR(64) DEFAULT NULL,
    `stock` VARCHAR(64) DEFAULT NULL,
    `situacion` VARCHAR(20) DEFAULT NULL,
    `imagen_principal` VARCHAR(500) DEFAULT NULL,
    `capturado_at` DATE DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_fuente_externo` (`fuente_id`, `id_externo`),
    KEY `idx_codigo_norm` (`codigo_normalizado`),
    KEY `idx_marca` (`marca`),
    KEY `idx_categoria` (`categoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_precios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `snapshot_fecha` DATE NOT NULL,
    `precio` DECIMAL(18,6) DEFAULT NULL,
    `precio_lista` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_unitario` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_total` DECIMAL(18,6) DEFAULT NULL,
    `cantidad_min` INT UNSIGNED DEFAULT NULL,
    `iva` DECIMAL(8,4) DEFAULT NULL,
    `costo` DECIMAL(18,6) DEFAULT NULL,
    `moneda` VARCHAR(10) DEFAULT 'CLP',
    `oculto` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_snapshot` (`producto_id`, `snapshot_fecha`),
    KEY `idx_snapshot` (`snapshot_fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_medios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sha256` CHAR(64) NOT NULL,
    `tipo` VARCHAR(20) NOT NULL DEFAULT 'foto',
    `subtipo` VARCHAR(32) DEFAULT NULL,
    `url_origen` VARCHAR(1000) DEFAULT NULL,
    `ruta_local` VARCHAR(500) DEFAULT NULL,
    `r2_key` VARCHAR(500) DEFAULT NULL,
    `bytes` BIGINT UNSIGNED DEFAULT 0,
    `mime` VARCHAR(120) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_sha256` (`sha256`),
    KEY `idx_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_producto_medio` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `medio_id` BIGINT UNSIGNED NOT NULL,
    `es_principal` TINYINT(1) NOT NULL DEFAULT 0,
    `tipo_multimedia` VARCHAR(20) DEFAULT NULL,
    `subtipo` VARCHAR(32) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_medio` (`producto_id`, `medio_id`),
    KEY `idx_medio` (`medio_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_atributos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `titulo` VARCHAR(120) NOT NULL,
    `valor` VARCHAR(500) DEFAULT NULL,
    `orden` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_producto` (`producto_id`),
    KEY `idx_titulo` (`producto_id`, `titulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{prefix}competencia_match` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_competencia_id` BIGINT UNSIGNED NOT NULL,
    `producto_base_id` BIGINT UNSIGNED DEFAULT NULL,
    `metodo` VARCHAR(32) NOT NULL DEFAULT 'manual',
    `score` DECIMAL(5,2) DEFAULT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'sugerido',
    `revisado_por` BIGINT UNSIGNED DEFAULT NULL,
    `revisado_at` DATETIME DEFAULT NULL,
    `nota` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_competencia` (`producto_competencia_id`),
    KEY `idx_estado` (`estado`),
    KEY `idx_producto_base` (`producto_base_id`),
    KEY `idx_metodo` (`metodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
