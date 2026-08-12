-- Fase 17: Descuentos, recargos, impuestos específicos y costos neto/bruto
-- Prefijo real: {prefix} = {$wpdb->prefix}riverso_  → tablas facturas / factura_items

ALTER TABLE `{prefix}facturas`
  ADD COLUMN IF NOT EXISTS `tasa_iva` DECIMAL(8,4) DEFAULT NULL COMMENT 'TasaIVA del DTE',
  ADD COLUMN IF NOT EXISTS `impuestos_adicionales` LONGTEXT DEFAULT NULL COMMENT 'JSON ImptoReten del DTE';

ALTER TABLE `{prefix}factura_items`
  ADD COLUMN IF NOT EXISTS `descuento_porcentaje` DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `descuento_monto` DECIMAL(12,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recargo_porcentaje` DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recargo_monto` DECIMAL(12,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cod_imp_adic` VARCHAR(10) DEFAULT NULL COMMENT 'CodImpAdic SII',
  ADD COLUMN IF NOT EXISTS `impuesto_especifico_tasa` DECIMAL(8,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `impuesto_especifico_monto` DECIMAL(12,4) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `costo_neto_base` DECIMAL(12,4) DEFAULT NULL COMMENT 'Neto antes de descuentos/recargos',
  ADD COLUMN IF NOT EXISTS `costo_bruto_base` DECIMAL(12,4) DEFAULT NULL COMMENT 'Bruto antes de descuentos/recargos',
  ADD COLUMN IF NOT EXISTS `costo_neto_final` DECIMAL(12,4) DEFAULT NULL COMMENT 'Neto después de descuentos/recargos',
  ADD COLUMN IF NOT EXISTS `costo_bruto_final` DECIMAL(12,4) DEFAULT NULL COMMENT 'Bruto después + imp. específico';

-- Backfill documentos ya cargados (sin XML): base = qty*precio, final = monto_total
UPDATE `{prefix}factura_items` fi
LEFT JOIN `{prefix}facturas` f ON f.id = fi.factura_id
SET
  fi.costo_neto_base = COALESCE(fi.costo_neto_base, ROUND(fi.cantidad * fi.precio_unitario, 4)),
  fi.costo_neto_final = COALESCE(fi.costo_neto_final, ROUND(fi.monto_total, 4)),
  fi.costo_bruto_base = COALESCE(
    fi.costo_bruto_base,
    ROUND((fi.cantidad * fi.precio_unitario) * (1 + COALESCE(f.tasa_iva, 19) / 100) + COALESCE(fi.impuesto_especifico_monto, 0), 4)
  ),
  fi.costo_bruto_final = COALESCE(
    fi.costo_bruto_final,
    ROUND(fi.monto_total * (1 + COALESCE(f.tasa_iva, 19) / 100) + COALESCE(fi.impuesto_especifico_monto, 0), 4)
  )
WHERE fi.costo_neto_final IS NULL
   OR fi.costo_neto_base IS NULL
   OR fi.costo_bruto_final IS NULL
   OR fi.costo_bruto_base IS NULL;
