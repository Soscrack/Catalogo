-- Fase 38: decisión humana sobre necesidad de familia por producto.
ALTER TABLE `{prefix}producto_base`
    ADD COLUMN IF NOT EXISTS `familia_decision` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'NULL=sin responder, no_requiere, requiere';
