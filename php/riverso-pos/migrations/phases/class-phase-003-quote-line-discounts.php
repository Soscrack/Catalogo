<?php
/**
 * Fase 003: descuentos del modo avanzado en cada línea.
 * - price_discount: porcentaje sobre el precio (0–100)
 * - margin_discount: porcentaje del margen restante (0–100)
 * El monto en dinero sigue en discount_amount (fase 002), recalculado al guardar.
 * Si las columnas ya existen, no se vuelven a crear.
 */

declare(strict_types=1);

final class Riverso_POS_Phase_003_Quote_Line_Discounts implements Riverso_POS_Migration_Phase {
    public function id(): string {
        return '003_quote_line_discounts';
    }

    public function up(Riverso_POS_Database $db): void {
        $items = $db->table('customer_quote_items');
        $this->add_column($db, $items, 'price_discount', 'decimal(8,2) NOT NULL DEFAULT 0', 'numeric NOT NULL DEFAULT 0');
        $this->add_column($db, $items, 'margin_discount', 'decimal(8,2) NOT NULL DEFAULT 0', 'numeric NOT NULL DEFAULT 0');
    }

    private function add_column(
        Riverso_POS_Database $db,
        string $table,
        string $column,
        string $mysql_def,
        string $sqlite_def
    ): void {
        $columns = $db->columns($table);
        if (in_array($column, $columns, true)) {
            return;
        }
        $definition = $db->dialect() === 'sqlite' ? $sqlite_def : $mysql_def;
        $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
