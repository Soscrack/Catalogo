-- Fase 78: «Otra regla» en cotizaciones
-- Prefijo real: {wp_}riverso_
--
-- price_rule_codigo guarda el código de la regla aprobada que eligió el vendedor
-- (price_mode = 'rule'). Sigue la última versión aprobada de ese código, como R-1.
-- Va también en las líneas de borradores de factura para que facturar no vuelva
-- a la regla asignada.
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase78_quote_price_rule).

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `price_rule_codigo` VARCHAR(50) NULL DEFAULT NULL;

ALTER TABLE `{prefix}billing_draft_lines`
  ADD COLUMN IF NOT EXISTS `price_rule_codigo` VARCHAR(50) NULL DEFAULT NULL;
