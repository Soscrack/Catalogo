<?php
/**
 * Fase 002: campos de cotización de venta que el esbozo no tenía.
 * - tipo venta|referencia
 * - cliente opcional (customer_id)
 * - validez en días y condiciones
 * - totales neto, descuentos, margen y utilidad
 * - códigos de proveedor y de barras en la línea (vienen del catálogo)
 * - remapeo de estados legado → draft|listed|invoiced
 */

declare(strict_types=1);

final class Riverso_POS_Phase_002_Sale_Quote_Fields implements Riverso_POS_Migration_Phase {
    public function id(): string {
        return '002_sale_quote_fields';
    }

    public function up(Riverso_POS_Database $db): void {
        $quotes = $db->table('customer_quotes');
        $items = $db->table('customer_quote_items');

        $this->add_column($db, $quotes, 'customer_id', 'bigint(20) unsigned NULL', 'integer NULL');
        $this->add_column($db, $quotes, 'quote_type', "varchar(20) NOT NULL DEFAULT 'venta'", "text NOT NULL DEFAULT 'venta'");
        $this->add_column($db, $quotes, 'validity_days', 'int unsigned NULL', 'integer NULL');
        $this->add_column($db, $quotes, 'validity_terms', 'text NULL', 'text NULL');
        $this->add_column($db, $quotes, 'net_total', 'decimal(14,2) NOT NULL DEFAULT 0', 'numeric NOT NULL DEFAULT 0');
        $this->add_column($db, $quotes, 'discount_total', 'decimal(14,2) NOT NULL DEFAULT 0', 'numeric NOT NULL DEFAULT 0');
        $this->add_column($db, $quotes, 'margin_percent', 'decimal(8,2) NULL', 'numeric NULL');
        $this->add_column($db, $quotes, 'profit_total', 'decimal(14,2) NULL', 'numeric NULL');

        $this->add_column($db, $items, 'supplier_code', 'varchar(64) NULL', 'text NULL');
        $this->add_column($db, $items, 'barcode', 'varchar(64) NULL', 'text NULL');
        $this->add_column($db, $items, 'unit_cost', 'decimal(14,2) NULL', 'numeric NULL');
        $this->add_column($db, $items, 'discount_amount', 'decimal(14,2) NOT NULL DEFAULT 0', 'numeric NOT NULL DEFAULT 0');
        $this->add_column($db, $items, 'sort_order', 'int NOT NULL DEFAULT 0', 'integer NOT NULL DEFAULT 0');

        $this->add_index($db, $quotes, $quotes . '_customer_id', 'customer_id');
        $this->add_index($db, $quotes, $quotes . '_quote_type', 'quote_type');

        $rows = $db->fetch_all('SELECT id, status, total, net_total FROM ' . $quotes);
        foreach ($rows as $row) {
            $next = Riverso_POS_Quote_Status::normalize_legacy((string) $row['status']);
            $net = (float) ($row['net_total'] ?? 0);
            $legacy_total = (float) ($row['total'] ?? 0);
            $patch = [];
            if ($next !== (string) $row['status']) {
                $patch['status'] = $next;
            }
            if ($net == 0.0 && $legacy_total != 0.0) {
                $patch['net_total'] = round($legacy_total, 2);
            }
            if ($patch !== []) {
                $db->update($quotes, $patch, 'id = ?', [(int) $row['id']]);
            }
        }
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

    private function add_index(Riverso_POS_Database $db, string $table, string $index, string $column): void {
        if ($db->dialect() === 'sqlite') {
            $db->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' (' . $column . ')');
            return;
        }
        $rows = $db->fetch_all('SHOW INDEX FROM ' . $table);
        foreach ($rows as $row) {
            if (($row['Key_name'] ?? '') === $index) {
                return;
            }
        }
        $db->exec('ALTER TABLE ' . $table . ' ADD INDEX ' . $index . ' (' . $column . ')');
    }
}
