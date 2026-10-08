-- Fase 74: total de regla en líneas de cotización
-- Prefijo real: {wp_}riverso_customer_quote_items
--
-- rule_total / rule_adjusted guardan el T_final del motor de reglas (R-1, etc.).
-- Sin ellos el total se rearmaba desde un unitario de 2 decimales
-- (R-1 $500 en 6 u. → 83,33 × 6 = $499,98).
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase74_customer_quotes_rule_total).

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `rule_total` DECIMAL(14,2) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rule_adjusted` TINYINT(1) NOT NULL DEFAULT 0;

-- El unitario de una regla ajustada lleva 4 decimales.
ALTER TABLE `{prefix}customer_quote_items`
  MODIFY COLUMN `unit_price` DECIMAL(14,4) NOT NULL DEFAULT 0;
