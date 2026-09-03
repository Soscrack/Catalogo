-- Fórmula de total (T) y piso de total en tramos de reglas de precio.
-- Aplicado vía Riverso_Price_Rules_Module::maybe_upgrade_schema() / dbDelta.

ALTER TABLE `{prefix}price_rule_tiers`
    ADD COLUMN `formula_total` VARCHAR(500) NULL DEFAULT NULL AFTER `formula`;

ALTER TABLE `{prefix}price_rule_tiers`
    ADD COLUMN `piso_total` DECIMAL(12,2) NULL DEFAULT NULL AFTER `total_minimo`;
