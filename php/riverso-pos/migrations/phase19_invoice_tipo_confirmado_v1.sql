-- Fase 19: Confirmar tipo de documento - Agregar control de confirmación de tipo_documento
-- Permite marcar si el tipo de documento (documento_subtipo) ha sido confirmado por usuario
-- Al subir, se inicia con tipo_confirmado = 0 y se genera tarea de confirmación

ALTER TABLE `{prefix}facturas`
  ADD COLUMN IF NOT EXISTS `tipo_confirmado` TINYINT(1) DEFAULT 0 COMMENT 'Si el documento_subtipo fue confirmado manualmente (0=pendiente, 1=confirmado)',
  ADD KEY `idx_tipo_confirmado` (`tipo_confirmado`);

-- Marcar todas las facturas existentes como confirmadas (ya fueron procesadas)
UPDATE `{prefix}facturas` SET `tipo_confirmado` = 1 WHERE `tipo_confirmado` IS NULL OR `tipo_confirmado` = 0;
