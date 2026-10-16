-- Fase 82: Asistente de Descuento (2)
-- Prefijo real: {prefix} = wp_riverso_
--
-- URL de competencia deshabilitable: no cuenta para descuentos (la página tiene un precio viejo).
-- El refresco nocturno Sande/DIMAFI sigue leyendo; si el precio cambia respecto al congelado
-- (deshabilitado_precio) deja precio_cambio_at para revisar. deshabilitado_hasta: vuelve a contar después.
-- discount_review: marca «Revisar descuento» de «Aplicar descuentos sugeridos».
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase82_discount_assistant_urls).

ALTER TABLE `{prefix}competencia_productos`
  ADD COLUMN IF NOT EXISTS `deshabilitado` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `deshabilitado_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deshabilitado_por` BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deshabilitado_motivo` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deshabilitado_hasta` DATE NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deshabilitado_precio` DECIMAL(18,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `precio_cambio_at` DATETIME NULL DEFAULT NULL;

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `discount_review` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `{prefix}billing_draft_lines`
  ADD COLUMN IF NOT EXISTS `discount_review` TINYINT(1) NOT NULL DEFAULT 0;
