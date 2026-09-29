-- Fase 58: descuentos del modo avanzado en cada línea (P2)
-- price_discount: porcentaje sobre el precio (0–100)
-- margin_discount: porcentaje del margen restante (0–100)
-- El monto en dinero sigue en discount_amount, recalculado al guardar.
-- Idempotente: el activador PHP usa add_column_if_missing.

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `price_discount` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `unit_cost`,
  ADD COLUMN IF NOT EXISTS `margin_discount` DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER `price_discount`;
