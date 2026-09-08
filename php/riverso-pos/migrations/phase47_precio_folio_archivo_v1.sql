-- Fase 47: archivado de folios en precio_folio_proceso
-- Prefijo real: {prefix} = wp_riverso_

ALTER TABLE `{prefix}precio_folio_proceso`
    ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `completed_at`,
    ADD COLUMN `archived_by` BIGINT UNSIGNED DEFAULT NULL AFTER `archived_at`,
    ADD KEY `idx_archived_at` (`archived_at`);
