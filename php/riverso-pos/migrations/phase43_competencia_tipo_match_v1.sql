-- Fase 43: tipo_match en competencia_match
-- Agrega el campo tipo_match (exacto | similar | otro) que el operador elige
-- al confirmar un match. Idempotente.
-- Placeholder {prefix} = wp_riverso_

SET @has := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = '{prefix}competencia_match'
      AND column_name  = 'tipo_match'
);

SET @sql := IF(
    @has = 0,
    "ALTER TABLE `{prefix}competencia_match`
     ADD COLUMN `tipo_match` VARCHAR(20) DEFAULT NULL
     COMMENT 'exacto|similar|otro'
     AFTER `metodo`",
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
