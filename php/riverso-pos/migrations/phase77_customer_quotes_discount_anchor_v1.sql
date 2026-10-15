-- Fase 77: módulo Descuentos en líneas de cotización
-- Prefijo real: {wp_}riverso_customer_quote_items
--
-- price_discount / margin_discount pasan a 4 decimales.
-- discount_anchor / discount_anchor_value guardan el campo que fijó el vendedor
-- (price_pct, margin_pct, amount, final_unit, final_total, factor) y su valor exacto.
-- discount_amount sigue siendo el monto en pesos que leen PDF y facturación.
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase77_customer_quotes_discount_anchor).

ALTER TABLE `{prefix}customer_quote_items`
  MODIFY COLUMN `price_discount` DECIMAL(8,4) NOT NULL DEFAULT 0,
  MODIFY COLUMN `margin_discount` DECIMAL(8,4) NOT NULL DEFAULT 0;

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `discount_anchor` VARCHAR(16) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `discount_anchor_value` DECIMAL(18,6) NULL DEFAULT NULL;
