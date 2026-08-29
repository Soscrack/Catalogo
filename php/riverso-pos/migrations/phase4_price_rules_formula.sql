-- Columna formula en tramos de reglas de precio (calculadora T10/T50/T100).
-- Aplicado vía Riverso_Price_Rules_Module::maybe_upgrade_schema() / dbDelta.

ALTER TABLE `{prefix}price_rule_tiers`
    ADD COLUMN `formula` VARCHAR(500) NULL DEFAULT NULL AFTER `redondeo`;
