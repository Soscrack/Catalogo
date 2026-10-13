<?php
/**
 * Fase 001: cabecera y líneas del esbozo original.
 * Estados históricos (draft/sent/viewed/…) viven en `status` como texto.
 * La fase 002 agrega los campos de venta y normaliza los estados.
 */

declare(strict_types=1);

final class Riverso_POS_Phase_001_Customer_Quotes_Base implements Riverso_POS_Migration_Phase {
    public function id(): string {
        return '001_customer_quotes_base';
    }

    public function up(Riverso_POS_Database $db): void {
        $quotes = $db->table('customer_quotes');
        $items = $db->table('customer_quote_items');
        if ($db->dialect() === 'sqlite') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ' . $quotes . ' (
                    id integer PRIMARY KEY AUTOINCREMENT,
                    quote_number text NOT NULL UNIQUE,
                    customer_name text NOT NULL DEFAULT \'\',
                    status text NOT NULL DEFAULT \'draft\',
                    total numeric NOT NULL DEFAULT 0,
                    notes text NULL,
                    created_at text NOT NULL,
                    updated_at text NOT NULL
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS ' . $quotes . '_status ON ' . $quotes . ' (status)');
            $db->exec(
                'CREATE TABLE IF NOT EXISTS ' . $items . ' (
                    id integer PRIMARY KEY AUTOINCREMENT,
                    quote_id integer NOT NULL,
                    product_id integer NULL,
                    sku text NOT NULL DEFAULT \'\',
                    description text NULL,
                    quantity numeric NOT NULL DEFAULT 1,
                    unit_price numeric NOT NULL DEFAULT 0,
                    line_total numeric NOT NULL DEFAULT 0
                )'
            );
            $db->exec('CREATE INDEX IF NOT EXISTS ' . $items . '_quote_id ON ' . $items . ' (quote_id)');
            return;
        }

        $charset = $db->charset_collate();
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $quotes . ' (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quote_number varchar(32) NOT NULL,
                customer_name varchar(191) NOT NULL DEFAULT \'\',
                status varchar(20) NOT NULL DEFAULT \'draft\',
                total decimal(14,2) NOT NULL DEFAULT 0,
                notes text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY quote_number (quote_number),
                KEY status (status)
            ) ' . $charset
        );
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $items . ' (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                quote_id bigint(20) unsigned NOT NULL,
                product_id bigint(20) unsigned NULL,
                sku varchar(64) NOT NULL DEFAULT \'\',
                description text NULL,
                quantity decimal(14,3) NOT NULL DEFAULT 1,
                unit_price decimal(14,2) NOT NULL DEFAULT 0,
                line_total decimal(14,2) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                KEY quote_id (quote_id)
            ) ' . $charset
        );
    }
}
