-- Fase 62: fecha de emisión editable en cotizaciones de venta
-- Prefijo real: {wp_}riverso_customer_quotes
--
-- La fecha de emisión deja de depender solo de created_at.
-- El activador PHP aplica esto de forma idempotente (add_column_if_missing + UPDATE).

ALTER TABLE `{prefix}customer_quotes`
  ADD COLUMN IF NOT EXISTS `issue_date` DATE NULL DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_cq_issue_date` (`issue_date`);

UPDATE `{prefix}customer_quotes`
SET `issue_date` = DATE(`created_at`)
WHERE `issue_date` IS NULL AND `created_at` IS NOT NULL;
