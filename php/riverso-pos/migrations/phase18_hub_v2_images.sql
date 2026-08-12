-- Fase 18: Hub v2 - Agregar campo imagen_id a producto_base
-- Permite asignar una imagen de la media librería de WordPress a cada producto

ALTER TABLE `{prefix}producto_base`
  ADD COLUMN IF NOT EXISTS `imagen_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID de attachment (imagen) de WordPress',
  ADD KEY `idx_imagen_id` (`imagen_id`);
