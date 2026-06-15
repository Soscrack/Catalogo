-- Fase 8: Gobernanza de publicación y soft match online
-- Migración incremental no destructiva para Riverso POS.
-- Las columnas se aplican de forma idempotente desde Riverso_POS_Activator::create_phase8_publication().

ALTER TABLE `{prefix}producto_base`
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL,
    ADD COLUMN `archived_at` DATETIME DEFAULT NULL,
    ADD COLUMN `human_product_review` VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN `human_price_review` VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN `human_category_review` VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN `human_attribute_review` VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN `publication_stage` VARCHAR(40) NOT NULL DEFAULT 'computer_created',
    ADD COLUMN `match_estado_online` VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED',
    ADD COLUMN `match_score_online` INT DEFAULT NULL,
    ADD COLUMN `match_origen_online` VARCHAR(20) DEFAULT NULL,
    ADD COLUMN `matched_online_at` DATETIME DEFAULT NULL,
    ADD COLUMN `woocommerce_candidate_id` BIGINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `{prefix}producto_base`
    ADD KEY `idx_deleted_at` (`deleted_at`),
    ADD KEY `idx_archived_at` (`archived_at`),
    ADD KEY `idx_publication_stage` (`publication_stage`),
    ADD KEY `idx_match_estado_online` (`match_estado_online`);
