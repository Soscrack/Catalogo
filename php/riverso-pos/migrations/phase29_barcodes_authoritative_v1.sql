-- Fase 29: mapeo autoritativo de códigos de barra
-- Fuente de verdad: {prefix}codigo_barra con estado=verificado.
-- Legacy (tienda_local / riverso_barcodes) se importa como propuesto.
-- Placeholder {prefix} = {$wpdb->prefix}riverso_

ALTER TABLE `{prefix}codigo_barra`
    MODIFY COLUMN `producto_base_id` BIGINT UNSIGNED DEFAULT NULL
    COMMENT 'NULL si el SKU destino aún no existe en producto_base';

ALTER TABLE `{prefix}codigo_barra`
    ADD COLUMN IF NOT EXISTS `estado` VARCHAR(20) NOT NULL DEFAULT 'verificado',
    ADD COLUMN IF NOT EXISTS `motivo_estado` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `estado_por` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `estado_at` DATETIME DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `origen_datos` VARCHAR(50) NOT NULL DEFAULT 'legacy',
    ADD COLUMN IF NOT EXISTS `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `sku_local` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pending_sku` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `legacy_ref` LONGTEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `conflicto` TINYINT(1) NOT NULL DEFAULT 0;

-- UNIQUE(codigo) impide propuestas paralelas; la unicidad de verificado se valida en PHP.
-- El índice se elimina desde class-activator (drop_index_if_exists).

-- KEY idx_codigo_estado, idx_codigo_origen, idx_sku_local, idx_pending_sku, idx_conflicto
-- se agregan desde class-activator (add_index_if_missing).
