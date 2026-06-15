-- Fase 2: Gobernanza transversal + Matching progresivo
-- Migracion incremental no destructiva para Riverso POS.
-- Las columnas se agregan de forma idempotente desde class-activator.php
-- (add_column_if_missing / add_index_if_missing). Este archivo es el espejo
-- declarativo de esos cambios.

-- === Gobernanza: created_by_system / requires_human_review / review_status ===
ALTER TABLE `{prefix}producto_base`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `review_status` VARCHAR(20) NOT NULL DEFAULT 'aprobado',
    ADD KEY `idx_requires_review` (`requires_human_review`);

ALTER TABLE `{prefix}producto_proveedor`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `review_status` VARCHAR(20) NOT NULL DEFAULT 'aprobado',
    ADD KEY `idx_requires_review` (`requires_human_review`);

ALTER TABLE `{prefix}equivalence_groups`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `review_status` VARCHAR(20) NOT NULL DEFAULT 'aprobado';

ALTER TABLE `{prefix}equivalence_members`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `review_status` VARCHAR(20) NOT NULL DEFAULT 'aprobado';

ALTER TABLE `{prefix}barcodes`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `review_status` VARCHAR(20) NOT NULL DEFAULT 'aprobado';

ALTER TABLE `{prefix}tareas`
    ADD COLUMN `created_by_system` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `requires_human_review` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `{prefix}audit_log`
    ADD COLUMN `actor_type` VARCHAR(20) NOT NULL DEFAULT 'user';

-- === Matching progresivo en producto_proveedor ===
ALTER TABLE `{prefix}producto_proveedor`
    ADD COLUMN `match_estado` VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED',
    ADD COLUMN `match_score` INT DEFAULT NULL,
    ADD COLUMN `match_origen` VARCHAR(20) DEFAULT NULL,
    ADD COLUMN `matched_at` DATETIME DEFAULT NULL,
    ADD KEY `idx_match_estado` (`match_estado`);
