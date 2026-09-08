-- Fase 45: fuente "manual" + índice por URL para ingreso manual.
-- Sustituye {prefix} por el prefijo real (ej. nExLU_riverso_).

INSERT INTO `{prefix}competencia_fuentes` (slug, nombre, base_url, activo)
VALUES ('manual', 'Ingreso manual', NULL, 1)
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    activo = 1;

-- Índice para lookup por URL (idempotente si ya existe).
-- En MySQL 5.7/8 no hay IF NOT EXISTS para índices; el activator lo aplica con guard.
ALTER TABLE `{prefix}competencia_productos`
  ADD INDEX `idx_url_producto` (`url_producto`(191));
