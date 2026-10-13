<?php

declare(strict_types=1);

require_once __DIR__ . '/class-database.php';
require_once __DIR__ . '/class-pdo-database.php';
require_once __DIR__ . '/class-wpdb-database.php';
require_once __DIR__ . '/../sales/customer_quotes/class-quote-exception.php';
require_once __DIR__ . '/../sales/customer_quotes/class-quote-status.php';
require_once __DIR__ . '/../sales/customer_quotes/class-quote-totals.php';
require_once __DIR__ . '/../migrations/class-migration-phase.php';
require_once __DIR__ . '/../migrations/class-migration-runner.php';
require_once __DIR__ . '/../migrations/phases/class-phase-001-customer-quotes-base.php';
require_once __DIR__ . '/../migrations/phases/class-phase-002-sale-quote-fields.php';
require_once __DIR__ . '/../migrations/phases/class-phase-003-quote-line-discounts.php';
require_once __DIR__ . '/../sales/customer_quotes/class-customer-quote-repository.php';
require_once __DIR__ . '/../catalog/class-catalog-match.php';
require_once __DIR__ . '/../catalog/class-catalog-reader.php';
require_once __DIR__ . '/../catalog/class-memory-catalog-reader.php';
require_once __DIR__ . '/../catalog/class-woo-catalog-reader.php';
require_once __DIR__ . '/../catalog/products/class-product-lookup.php';
require_once __DIR__ . '/../catalog/barcodes/class-barcode-lookup.php';
require_once __DIR__ . '/../catalog/suppliers/class-supplier-code-lookup.php';
require_once __DIR__ . '/../catalog/class-catalog-product-lookup.php';
require_once __DIR__ . '/../sales/customer_quotes/class-customer-quote-module.php';
require_once __DIR__ . '/../portal/class-customer-quotes-portal.php';

function riverso_pos_migrations(): array {
    return [
        new Riverso_POS_Phase_001_Customer_Quotes_Base(),
        new Riverso_POS_Phase_002_Sale_Quote_Fields(),
        new Riverso_POS_Phase_003_Quote_Line_Discounts(),
    ];
}

function riverso_pos_migration_runner(?Riverso_POS_Database $db = null): Riverso_POS_Migration_Runner {
    if ($db === null) {
        global $wpdb;
        $db = new Riverso_POS_Wpdb_Database($wpdb);
    }
    return new Riverso_POS_Migration_Runner($db, riverso_pos_migrations());
}

function riverso_pos_catalog_lookup(Riverso_POS_Catalog_Reader $reader): Riverso_POS_Catalog_Product_Lookup {
    return new Riverso_POS_Catalog_Product_Lookup(
        new Riverso_POS_Product_Lookup($reader),
        new Riverso_POS_Barcode_Lookup($reader),
        new Riverso_POS_Supplier_Code_Lookup($reader)
    );
}

function riverso_pos_customer_quote_module(?Riverso_POS_Database $db = null, ?Riverso_POS_Catalog_Reader $reader = null, bool $enforce_auth = true): Riverso_POS_Customer_Quote_Module {
    if ($db === null) {
        global $wpdb;
        $db = new Riverso_POS_Wpdb_Database($wpdb);
    }
    if ($reader === null) {
        $reader = new Riverso_POS_Woo_Catalog_Reader();
    }
    return new Riverso_POS_Customer_Quote_Module(
        new Riverso_POS_Customer_Quote_Repository($db),
        riverso_pos_catalog_lookup($reader),
        $enforce_auth
    );
}
