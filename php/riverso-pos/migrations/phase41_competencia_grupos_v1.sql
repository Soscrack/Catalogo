-- Fase 41: competencia_grupos_dimafi (id_grupo_externo + descripcion)
-- Placeholder {prefix} = wp_riverso_

-- Columnas idempotentes (usamos information_schema con DATABASE()).

SET @has := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = '{prefix}competencia_productos'
      AND column_name = 'id_grupo_externo'
);

SET @sql := IF(
    @has = 0,
    'ALTER TABLE `{prefix}competencia_productos` ADD COLUMN `id_grupo_externo` VARCHAR(32) DEFAULT NULL AFTER `id_externo`',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = '{prefix}competencia_productos'
      AND column_name = 'descripcion'
);

SET @sql := IF(
    @has = 0,
    'ALTER TABLE `{prefix}competencia_productos` ADD COLUMN `descripcion` MEDIUMTEXT DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Index opcional para búsquedas futuras.
SET @has_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = '{prefix}competencia_productos'
      AND index_name = 'idx_grupo_externo'
);

SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE `{prefix}competencia_productos` ADD KEY `idx_grupo_externo` (`id_grupo_externo`)',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed de fuente DIMAFI.
INSERT INTO `{prefix}competencia_fuentes` (slug, nombre, base_url, activo)
VALUES ('dimafi', 'DIMAFI', 'https://www.dimafi.cl', 1)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  base_url = VALUES(base_url),
  activo = VALUES(activo);

