-- Tope de alza del total (máx ΔT) en tramos de reglas de precio.
-- T se limita a P × Q + máx ΔT antes de la fórmula T. Acepta número o fórmula en P.
-- Aplicado vía Riverso_Price_Rules_Module::maybe_upgrade_schema() / dbDelta.

ALTER TABLE `{prefix}price_rule_tiers`
    ADD COLUMN `max_delta_t` VARCHAR(500) NULL DEFAULT NULL AFTER `formula_total`;
