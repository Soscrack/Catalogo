-- Fase 50: inbox unificado + campos extra de cotizaciones de compra
-- Prefijo real: {wp_}riverso_

ALTER TABLE wp_riverso_cotizaciones_recibidas
  MODIFY COLUMN tipo_fuente ENUM('pdf','excel','text','manual','email','whatsapp') DEFAULT 'manual';

ALTER TABLE wp_riverso_cotizaciones_recibidas
  ADD COLUMN fecha_validez DATE NULL AFTER fecha_documento,
  ADD COLUMN tasa_iva DECIMAL(5,2) DEFAULT 19 AFTER impuesto,
  ADD COLUMN descuento_pct DECIMAL(8,4) NULL AFTER tasa_iva,
  ADD COLUMN descuento_monto DECIMAL(15,4) NULL AFTER descuento_pct,
  ADD COLUMN condiciones_pago VARCHAR(255) NULL AFTER descuento_monto,
  ADD COLUMN origen_mensaje_id BIGINT UNSIGNED NULL AFTER archivo_original,
  ADD COLUMN origen_canal VARCHAR(20) NULL AFTER origen_mensaje_id;

ALTER TABLE wp_riverso_cotizacion_items
  ADD COLUMN precio_lista DECIMAL(15,4) NULL AFTER unidad,
  ADD COLUMN descuento_pct DECIMAL(8,4) NULL AFTER precio_lista,
  ADD COLUMN descuento_monto DECIMAL(15,4) NULL AFTER descuento_pct,
  ADD COLUMN tasa_iva DECIMAL(5,2) NULL AFTER descuento_monto;

ALTER TABLE wp_riverso_ordenes_compra
  ADD COLUMN cotizacion_id BIGINT UNSIGNED NULL AFTER proveedor_id;
