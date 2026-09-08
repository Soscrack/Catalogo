-- Fase 46: ingreso híbrido en Procesar folios
-- Prefijo real: {prefix} = wp_riverso_
-- Ítems de factura marcados como ya ingresados (omitidos del workflow).

ALTER TABLE `{prefix}precio_folio_proceso`
    ADD COLUMN `items_omitidos_json` LONGTEXT DEFAULT NULL
        COMMENT 'IDs factura_items ya ingresados (híbrido)' AFTER `blockers_json`;
