-- Fase 9: Ingreso XML — envío vs producto, costos landed, lotes
-- Aplicada vía class-activator.php (add_column_if_missing).

ALTER TABLE `{prefix}riverso_facturas`
    ADD COLUMN IF NOT EXISTS `documento_subtipo` VARCHAR(20) NOT NULL DEFAULT 'productos',
    ADD COLUMN IF NOT EXISTS `factura_productos_id` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `costo_envio_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `envio_prorrateado` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `{prefix}riverso_factura_items`
    ADD COLUMN IF NOT EXISTS `item_tipo` VARCHAR(20) NOT NULL DEFAULT 'producto',
    ADD COLUMN IF NOT EXISTS `codigo_tipo` VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `costo_envio_prorrateado` DECIMAL(12,4) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `costo_landed_unitario` DECIMAL(12,4) DEFAULT NULL;

ALTER TABLE `{prefix}riverso_lotes`
    ADD COLUMN IF NOT EXISTS `costo_envio_unitario` DECIMAL(12,4) NOT NULL DEFAULT 0;
