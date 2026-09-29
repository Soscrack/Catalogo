-- Fase 57: campos P0+P1 de cotizaciones de VENTA
-- Prefijo real: {wp_}riverso_customer_quotes / {wp_}riverso_customer_quote_items
--
-- Estados legado → draft|listed|invoiced:
--   draft, borrador, rejected, expired, cancelled, canceled → draft (Borrador)
--   sent, viewed, accepted, approved, listed, lista, converted → listed (Lista)
--   invoiced, billed, facturada → invoiced (Facturada)
--
-- El activador PHP aplica esto de forma idempotente (add_column_if_missing + UPDATE).

ALTER TABLE `{prefix}customer_quotes`
  ADD COLUMN IF NOT EXISTS `quote_type` VARCHAR(20) NOT NULL DEFAULT 'venta' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `validity_days` INT UNSIGNED NULL AFTER `quote_type`,
  ADD COLUMN IF NOT EXISTS `validity_terms` TEXT NULL AFTER `validity_days`,
  ADD COLUMN IF NOT EXISTS `net_total` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `total`,
  ADD COLUMN IF NOT EXISTS `margin_percent` DECIMAL(8,2) NULL AFTER `discount_total`,
  ADD COLUMN IF NOT EXISTS `profit_total` DECIMAL(14,2) NULL AFTER `margin_percent`;

ALTER TABLE `{prefix}customer_quote_items`
  ADD COLUMN IF NOT EXISTS `supplier_code` VARCHAR(64) NULL AFTER `sku`,
  ADD COLUMN IF NOT EXISTS `barcode` VARCHAR(64) NULL AFTER `supplier_code`,
  ADD COLUMN IF NOT EXISTS `unit_cost` DECIMAL(14,2) NULL AFTER `unit_price`,
  ADD COLUMN IF NOT EXISTS `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `total`;

-- Remapeo de estados (también lo hace el activador)
UPDATE `{prefix}customer_quotes`
SET `status` = CASE
  WHEN LOWER(`status`) IN ('invoiced','billed','facturada') THEN 'invoiced'
  WHEN LOWER(`status`) IN ('sent','viewed','accepted','approved','listed','lista','converted') THEN 'listed'
  ELSE 'draft'
END
WHERE LOWER(`status`) NOT IN ('draft','listed','invoiced');

UPDATE `{prefix}customer_quotes`
SET `net_total` = `total`
WHERE (`net_total` IS NULL OR `net_total` = 0) AND `total` <> 0;

UPDATE `{prefix}customer_quotes`
SET `validity_days` = `valid_days`
WHERE `validity_days` IS NULL AND `valid_days` IS NOT NULL;

UPDATE `{prefix}customer_quote_items`
SET `line_total` = `total`
WHERE (`line_total` IS NULL OR `line_total` = 0) AND `total` <> 0;