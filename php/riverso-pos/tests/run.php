<?php

declare(strict_types=1);

define('RIVERSO_POS_DEV', true);
require dirname(__DIR__) . '/riverso-pos.php';

$failures = 0;

function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "OK  {$message}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function memory_db(): Riverso_POS_Pdo_Database {
    $pdo = new PDO('sqlite::memory:');
    return new Riverso_POS_Pdo_Database($pdo, 'wp_');
}

function catalog(): Riverso_POS_Catalog_Product_Lookup {
    $reader = new Riverso_POS_Memory_Catalog_Reader(require dirname(__DIR__) . '/dev/catalog-seed.php');
    return riverso_pos_catalog_lookup($reader);
}

echo "== estados ==\n";
check(Riverso_POS_Quote_Status::normalize_legacy('sent') === 'listed', 'sent → listed');
check(Riverso_POS_Quote_Status::normalize_legacy('viewed') === 'listed', 'viewed → listed');
check(Riverso_POS_Quote_Status::normalize_legacy('accepted') === 'listed', 'accepted → listed');
check(Riverso_POS_Quote_Status::normalize_legacy('rejected') === 'draft', 'rejected → draft');
check(Riverso_POS_Quote_Status::normalize_legacy('invoiced') === 'invoiced', 'invoiced se conserva');
check(Riverso_POS_Quote_Status::label('listed') === 'Lista', 'etiqueta Lista');
check(Riverso_POS_Quote_Status::label('draft') === 'Borrador', 'etiqueta Borrador');
check(Riverso_POS_Quote_Status::can_transition('draft', 'listed'), 'borrador → lista');
check(Riverso_POS_Quote_Status::can_transition('listed', 'draft'), 'lista → borrador');
check(!Riverso_POS_Quote_Status::can_transition('draft', 'invoiced'), 'no factura desde borrador');
check(!Riverso_POS_Quote_Status::can_transition('invoiced', 'listed'), 'facturada no vuelve a lista');
check(Riverso_POS_Quote_Type::label('referencia') === 'Referencia', 'etiqueta Referencia');

echo "== totales ==\n";
$totals = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 2, 'unit_price' => 1000, 'unit_cost' => 600, 'discount_amount' => 0],
]);
check($totals['net_total'] === 2000.0, 'neto 2000');
check($totals['profit_total'] === 800.0, 'utilidad 800');
check($totals['margin_percent'] === 40.0, 'margen 40');
$mixed = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 400],
    ['quantity' => 1, 'unit_price' => 500, 'unit_cost' => null],
]);
check($mixed['profit_total'] === null && $mixed['margin_percent'] === null, 'margen desconocido si falta costo');
$discounted = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 400, 'discount_amount' => 100],
]);
check($discounted['net_total'] === 900.0 && $discounted['discount_total'] === 100.0, 'descuento baja el neto');
$kept = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 400, 'discount_amount' => 100, 'price_discount' => 0, 'margin_discount' => 0],
]);
check($kept['net_total'] === 900.0 && $kept['lines'][0]['discount_amount'] === 100.0, 'porcentajes en cero conservan el monto');
$rates = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 2, 'unit_price' => 890, 'unit_cost' => 510, 'price_discount' => 10, 'margin_discount' => 50, 'discount_amount' => 999],
]);
check($rates['discount_total'] === 469.0, 'los porcentajes mandan sobre el monto viejo');
check($rates['net_total'] === 1311.0, 'neto con dscto precio y margen');
check($rates['profit_total'] === 291.0, 'utilidad con ambos descuentos');
check($rates['margin_percent'] === 22.2, 'margen 22,2');
check($rates['lines'][0]['price_discount'] === 10.0 && $rates['lines'][0]['margin_discount'] === 50.0, 'guarda los porcentajes de la línea');
$no_cost = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => null, 'price_discount' => 0, 'margin_discount' => 25],
]);
check($no_cost['net_total'] === 1000.0 && $no_cost['discount_total'] === 0.0, 'dscto margen sin costo no inventa descuento');
check($no_cost['lines'][0]['margin_discount'] === 25.0 && $no_cost['profit_total'] === null, 'el porcentaje queda guardado');
$clamped = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 100, 'price_discount' => 150, 'margin_discount' => -4],
]);
check($clamped['lines'][0]['price_discount'] === 100.0 && $clamped['lines'][0]['margin_discount'] === 0.0, 'porcentajes entre 0 y 100');
check($clamped['net_total'] === 0.0, '100 % de dscto precio deja el neto en cero');
$empty = Riverso_POS_Quote_Totals::calculate([]);
check($empty['net_total'] === 0.0 && $empty['margin_percent'] === null, 'cotización vacía sin margen');
$healthy = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 950],
]);
check(Riverso_POS_Quote_Totals::alarm($healthy['profit_total'], $healthy['margin_percent']) === '', 'margen 5 % sin alarma');
$low_margin = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 960],
]);
check($low_margin['margin_percent'] === 4.0, 'margen 4 %');
check(Riverso_POS_Quote_Totals::alarm($low_margin['profit_total'], $low_margin['margin_percent']) === 'low', 'alarma ámbar bajo 5 %');
$negative = Riverso_POS_Quote_Totals::calculate([
    ['quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 1200],
]);
check(Riverso_POS_Quote_Totals::alarm($negative['profit_total'], $negative['margin_percent']) === 'neg', 'alarma roja con utilidad negativa');
check(Riverso_POS_Quote_Totals::alarm(null, null) === '', 'sin costo no hay alarma');

echo "== migración ==\n";
$db = memory_db();
$phase1 = new Riverso_POS_Phase_001_Customer_Quotes_Base();
$phase2 = new Riverso_POS_Phase_002_Sale_Quote_Fields();
$phase3 = new Riverso_POS_Phase_003_Quote_Line_Discounts();
$phase1->up($db);
$quotes = $db->table('customer_quotes');
check($quotes === 'wp_riverso_customer_quotes', 'tabla con prefijo wp_riverso_');
check($db->table('customer_quote_items') === 'wp_riverso_customer_quote_items', 'tabla de líneas');
$now = '2020-05-01 10:00:00';
foreach (['sent', 'viewed', 'rejected', 'invoiced'] as $index => $status) {
    $db->insert($quotes, [
        'quote_number' => 'COT-2020-000' . ($index + 1),
        'customer_name' => 'Cliente legado',
        'status' => $status,
        'total' => 1500,
        'notes' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
$phase2->up($db);
$phase2->up($db);
$columns = $db->columns($quotes);
foreach (['customer_id', 'quote_type', 'validity_days', 'validity_terms', 'net_total', 'discount_total', 'margin_percent', 'profit_total'] as $column) {
    check(in_array($column, $columns, true), "columna {$column}");
}
$item_columns = $db->columns($db->table('customer_quote_items'));
foreach (['supplier_code', 'barcode', 'unit_cost', 'discount_amount'] as $column) {
    check(in_array($column, $item_columns, true), "columna de línea {$column}");
}
check(!in_array('price_discount', $item_columns, true) && !in_array('margin_discount', $item_columns, true), 'fase 002 no crea los dsctos avanzados');
$phase3->up($db);
$phase3->up($db);
$item_columns = $db->columns($db->table('customer_quote_items'));
check(in_array('price_discount', $item_columns, true), 'columna price_discount');
check(in_array('margin_discount', $item_columns, true), 'columna margin_discount');
$mapped = [];
foreach ($db->fetch_all('SELECT status, net_total FROM ' . $quotes . ' ORDER BY id') as $row) {
    $mapped[$row['status']] = (float) $row['net_total'];
}
check(isset($mapped['listed'], $mapped['draft'], $mapped['invoiced']), 'estados legado remapeados');
check($mapped['listed'] === 1500.0, 'neto copia el total legado');
check(!isset($mapped['sent']) && !isset($mapped['viewed']), 'sent y viewed ya no quedan');

$fresh = memory_db();
$runner = new Riverso_POS_Migration_Runner($fresh, riverso_pos_migrations());
$applied = $runner->migrate();
check($applied === ['001_customer_quotes_base', '002_sale_quote_fields', '003_quote_line_discounts'], 'runner aplica fase 001, 002 y 003');
check($runner->migrate() === [], 'la migración es idempotente');

echo "== crud ==\n";
$repo = new Riverso_POS_Customer_Quote_Repository($fresh);
$saved = $repo->save([
    'customer_name' => '',
    'quote_type' => 'venta',
    'validity_days' => 10,
    'validity_terms' => 'Precios sujetos a stock.',
    'net_total' => 1,
    'lines' => [[
        'product_id' => 101,
        'sku' => 'B20TAD',
        'supplier_code' => '221',
        'barcode' => '7801111111111',
        'description' => 'Tornillo drywall rosca madera 6x1',
        'quantity' => 2,
        'unit_price' => 890,
        'unit_cost' => 510,
    ]],
]);
check(str_starts_with($saved['quote_number'], 'COT-' . gmdate('Y') . '-'), 'número de cotización');
check($saved['status'] === 'draft' && $saved['status_label'] === 'Borrador', 'nace en borrador');
check($saved['customer_name'] === '', 'cliente opcional');
check($saved['net_total'] === 1780.0, 'el servidor recalcula el neto');
check($saved['margin_percent'] === 42.7, 'margen de cabecera');
check($saved['lines'][0]['unit_price'] === 890.0, 'guarda el precio de línea');

$edited = $repo->save([
    'id' => $saved['id'],
    'customer_name' => 'Ana Pérez',
    'quote_type' => 'referencia',
    'validity_days' => 10,
    'lines' => [[
        'sku' => 'B20TAD',
        'description' => 'Tornillo drywall rosca madera 6x1',
        'quantity' => 3,
        'unit_price' => 1000,
        'unit_cost' => 510,
        'supplier_code' => '221',
        'barcode' => '7801111111111',
    ]],
]);
check($edited['quote_number'] === $saved['quote_number'], 'el número no cambia al editar');
check($edited['quote_type_label'] === 'Referencia', 'tipo referencia');
check($edited['net_total'] === 3000.0 && $edited['lines'][0]['quantity'] === 3.0, 'edita cantidad y precio');
check($edited['customer_name'] === 'Ana Pérez', 'guarda el cliente');

$listed = $repo->transition((int) $edited['id'], 'listed');
check($listed['status_label'] === 'Lista', 'pasa a lista');
$draft_again = $repo->transition((int) $edited['id'], 'draft');
check($draft_again['status'] === 'draft', 'vuelve a borrador');

$second = $repo->save([
    'customer_name' => 'Local',
    'quote_type' => 'venta',
    'lines' => [],
]);
check($second['quote_number'] !== $saved['quote_number'], 'correlativo siguiente');
$list = $repo->list_quotes();
check(count($list) === 2, 'la lista muestra las cotizaciones');
$by_number = [];
foreach ($list as $row) {
    $by_number[$row['quote_number']] = $row;
}
check($by_number[$saved['quote_number']]['line_count'] === 1, 'la lista cuenta las líneas');
check($by_number[$saved['quote_number']]['status_label'] === 'Borrador', 'la lista muestra Borrador');
check($by_number[$second['quote_number']]['customer_name'] === 'Local', 'la lista muestra el cliente');
check($edited['issue_date'] === $edited['created_at'] && $edited['issue_date'] !== '', 'la emisión es la fecha de creación');
check($edited['is_expired'] === false && $second['is_expired'] === false, 'sin vencer no marca vencida');
check($edited['seller_name'] === '', 'sin campo de vendedor el nombre queda vacío');
check(is_float($by_number[$saved['quote_number']]['margin_percent']), 'la lista incluye la utilidad porcentual');
$issued = substr((string) $edited['created_at'], 0, 10);
$only_ref = $repo->list_quotes(['quote_type' => 'referencia']);
check(count($only_ref) === 1 && $only_ref[0]['quote_number'] === $saved['quote_number'], 'filtra tipo referencia');
$only_venta = $repo->list_quotes(['quote_type' => 'venta', 'date_from' => $issued, 'date_to' => $issued]);
check(count($only_venta) === 1 && $only_venta[0]['customer_name'] === 'Local', 'filtra tipo y fecha');
check($repo->list_quotes(['date_from' => '1999-01-01', 'date_to' => '1999-01-02']) === [], 'un rango vacío no devuelve cotizaciones');

$threw = false;
try {
    $repo->transition((int) $edited['id'], 'invoiced');
} catch (Riverso_POS_Quote_Exception $error) {
    $threw = str_contains($error->getMessage(), 'Facturada');
}
check($threw, 'rechaza pasar a facturada');

$fresh->update($fresh->table('customer_quotes'), ['status' => 'invoiced'], 'id = ?', [(int) $second['id']]);
$blocked = false;
try {
    $repo->save([
        'id' => $second['id'],
        'lines' => [],
    ]);
} catch (Riverso_POS_Quote_Exception $error) {
    $blocked = str_contains($error->getMessage(), 'facturada');
}
check($blocked, 'no edita una facturada');

$with_discounts = $repo->save([
    'customer_name' => 'Descuentos',
    'quote_type' => 'venta',
    'lines' => [[
        'sku' => 'B20TAD',
        'description' => 'Tornillo drywall rosca madera 6x1',
        'quantity' => 2,
        'unit_price' => 890,
        'unit_cost' => 510,
        'price_discount' => 10,
        'margin_discount' => 50,
    ]],
]);
check($with_discounts['net_total'] === 1311.0, 'el servidor recalcula con dsctos');
check($with_discounts['discount_total'] === 469.0, 'total de descuentos persistido');
check($with_discounts['lines'][0]['price_discount'] === 10.0, 'persiste dscto precio');
check($with_discounts['lines'][0]['margin_discount'] === 50.0, 'persiste dscto margen');
check($with_discounts['lines'][0]['line_profit'] === 291.0, 'utilidad de la línea');
$reloaded = $repo->find((int) $with_discounts['id']);
check($reloaded !== null && $reloaded['lines'][0]['price_discount'] === 10.0 && $reloaded['lines'][0]['margin_discount'] === 50.0, 'los dsctos siguen al reabrir');
$qty_only = $repo->save([
    'id' => $with_discounts['id'],
    'customer_name' => 'Descuentos',
    'quote_type' => 'venta',
    'lines' => [[
        'sku' => 'B20TAD',
        'description' => 'Tornillo drywall rosca madera 6x1',
        'quantity' => 1,
        'unit_price' => 890,
        'unit_cost' => 510,
        'price_discount' => $reloaded['lines'][0]['price_discount'],
        'margin_discount' => $reloaded['lines'][0]['margin_discount'],
    ]],
]);
check($qty_only['lines'][0]['quantity'] === 1.0 && $qty_only['lines'][0]['price_discount'] === 10.0, 'editar cantidad no borra descuentos');

$low = $repo->save([
    'customer_name' => 'Margen bajo',
    'quote_type' => 'venta',
    'lines' => [[
        'sku' => 'LOW',
        'description' => 'Margen bajo',
        'quantity' => 1,
        'unit_price' => 1000,
        'unit_cost' => 960,
    ]],
]);
check(Riverso_POS_Quote_Totals::alarm($low['profit_total'], $low['margin_percent']) === 'low', 'cotización en ámbar');
$listed_low = $repo->transition((int) $low['id'], 'listed');
check($listed_low['status'] === 'listed', 'lista permitida con margen bajo');

$loss = $repo->save([
    'customer_name' => 'Pérdida',
    'quote_type' => 'venta',
    'lines' => [[
        'sku' => 'LOSS',
        'description' => 'Utilidad negativa',
        'quantity' => 1,
        'unit_price' => 1000,
        'unit_cost' => 1200,
    ]],
]);
check(Riverso_POS_Quote_Totals::alarm($loss['profit_total'], $loss['margin_percent']) === 'neg', 'cotización en rojo');
$listed_loss = $repo->transition((int) $loss['id'], 'listed');
check($listed_loss['status'] === 'listed', 'lista permitida con utilidad negativa');

$bad_qty = false;
try {
    $repo->save(['lines' => [['sku' => 'B20TAD', 'quantity' => 0, 'unit_price' => 10]]]);
} catch (Riverso_POS_Quote_Exception) {
    $bad_qty = true;
}
check($bad_qty, 'cantidad cero no se guarda');

$fresh->update($fresh->table('customer_quotes'), [
    'created_at' => '2020-01-01 08:00:00',
], 'id = ?', [(int) $edited['id']]);
$expired = $repo->find((int) $edited['id']);
check($expired !== null && $expired['is_expired'] === true, 'marca vencida si la emisión más la validez ya pasó');
check($expired['issue_date'] === '2020-01-01 08:00:00', 'la emisión sigue a created_at');

echo "== portal ==\n";
ob_start();
(new Riverso_POS_Customer_Quote_Module(new Riverso_POS_Customer_Quote_Repository($fresh), catalog(), false))->render([
    'ajaxUrl' => '/ajax',
    'nonce' => 'test',
    'assetBase' => '/assets',
    'standalone' => true,
    'currentUserName' => 'Vendedor local',
]);
$html = (string) ob_get_clean();
check(str_contains($html, 'id="cq-quote-number"') && str_contains($html, 'readonly'), 'cabecera con número de solo lectura');
check(str_contains($html, 'id="cq-issue-date"') && str_contains($html, 'id="cq-seller"'), 'cabecera con emisión y vendedor');
check(str_contains($html, 'Vendedor local'), 'el portal recibe el vendedor actual');
check(str_contains($html, 'id="cq-type-filter"') && str_contains($html, 'id="cq-date-from"') && str_contains($html, 'id="cq-apply-filters"'), 'filtros de tipo y fecha');
check(str_contains($html, 'Utilidad %'), 'columna de utilidad');
check(str_contains($html, 'id="cq-pdf"') && str_contains($html, 'id="cq-options"'), 'controles PDF y Opciones');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/customer-quotes.js');
check(str_contains($js, 'cq-qty-stepper') && str_contains($js, 'PDF de cotización: próximamente (stub P1b).'), 'steppers y stub PDF');

echo "== catálogo ==\n";
$lookup = catalog();
check(count($lookup->search('B20TAD')) === 1 && $lookup->search('b20tad')[0]['sku'] === 'B20TAD', 'busca por SKU');
check($lookup->search('445')[0]['supplier_code'] === '445', 'busca por código proveedor');
check($lookup->search('7803333333333')[0]['sku'] === '20ATHN', 'busca por código de barras');
check($lookup->search('') === [], 'búsqueda vacía');
check($lookup->search('NO-EXISTE') === [], 'sin resultados');
check(Riverso_POS_Woo_Catalog_Reader::meta_keys_for('sku') === ['_sku'], 'SKU WooCommerce');
check(in_array('_global_unique_id', Riverso_POS_Woo_Catalog_Reader::meta_keys_for('barcode'), true), 'barras WooCommerce');
check(in_array('_supplier_sku', Riverso_POS_Woo_Catalog_Reader::meta_keys_for('supplier_code'), true), 'código proveedor WooCommerce');

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "{$failures} pruebas fallaron\n");
    exit(1);
}
echo "Todas las pruebas pasaron\n";
