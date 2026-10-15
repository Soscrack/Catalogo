-- Fase 79: módulo Descuentos en borradores de boleta/factura
-- Prefijo real: {wp_}riverso_billing_draft_lines
--
-- discount_anchor / discount_anchor_value guardan el campo que fijó el vendedor
-- (price_pct, margin_pct, amount, final_unit, final_total, factor) y su valor exacto,
-- igual que en las líneas de cotización (fase 77). discount_amount sigue siendo el
-- monto en pesos que usa la emisión.
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase79_billing_draft_discount_anchor).

ALTER TABLE `{prefix}billing_draft_lines`
  ADD COLUMN IF NOT EXISTS `discount_anchor` VARCHAR(16) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `discount_anchor_value` DECIMAL(18,6) NULL DEFAULT NULL;
