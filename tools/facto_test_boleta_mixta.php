<?php
/**
 * Prueba: una boleta de $4 con 4 pagos de $1 en FACTO. Uso: php facto_test_boleta_mixta.php WP_PATH dry|emit
 */
$wp_path = $argv[1] ?? '';
$mode = $argv[2] ?? 'dry';
require $wp_path . '/wp-load.php';

$pagos = [
    ['caja' => 'Efectivo', 'cash_account_id' => '1', 'metodo' => 'Efectivo', 'payment_type_id' => '1'],
    ['caja' => 'Tarjeta', 'cash_account_id' => '2', 'metodo' => 'Tarjeta de débito', 'payment_type_id' => '5'],
    ['caja' => 'Tarjeta', 'cash_account_id' => '2', 'metodo' => 'Tarjeta de crédito', 'payment_type_id' => '4'],
    ['caja' => 'Transferencias', 'cash_account_id' => '5', 'metodo' => 'Transferencia electrónica bancaria', 'payment_type_id' => '10'],
];

$module = Riverso_Billing_Module::get_instance();
$build = new ReflectionMethod($module, 'build_facto_payload_from_request');
$build->setAccessible(true);
$client_m = new ReflectionMethod($module, 'facto_client');
$client_m->setAccessible(true);
$client = $client_m->invoke($module);

$today = current_time('Y-m-d');
$_POST = [
    'document_type_id' => '37',
    'issue_date' => $today,
    'payment_conditions' => '0',
    'lines' => wp_json_encode([[
        'description' => 'Prueba pago mixto (4 medios de $1)',
        'sku' => 'PRUEBA',
        'quantity' => 1,
        'unit_price_bruto' => 4,
        'afecto' => true,
    ]]),
];
$built = $build->invoke($module, false);
if (is_wp_error($built)) {
    echo 'ERROR payload: ' . $built->get_error_message() . PHP_EOL;
    exit(1);
}
$total = (float) $built['totals']['total_amount'];
echo "tipo {$built['document_type_id']} neto {$built['totals']['net_amount']} iva {$built['totals']['taxes_amount']} total $total" . PHP_EOL;
if ($total !== 4.0 || (int) $built['document_type_id'] !== 37) {
    echo 'ABORT: total distinto de 4 CLP o tipo distinto de boleta 37' . PHP_EOL;
    exit(1);
}
if ($mode !== 'emit') {
    echo 'FIN (dry)' . PHP_EOL;
    exit(0);
}

$resp = $client->create_document($built['payload'], true);
if (is_wp_error($resp)) {
    echo 'ERROR emisión: ' . $resp->get_error_message() . ' ' . wp_json_encode($resp->get_error_data()) . PHP_EOL;
    exit(1);
}
$doc_id = (int) ($resp['document_id'] ?? ($resp['header']['document_id'] ?? 0));
$folio = (string) ($resp['header']['document_number'] ?? ($resp['document_number'] ?? ''));
$status = $resp['result']['status'] ?? null;
echo "EMITIDA doc_id=$doc_id folio=$folio status=" . var_export($status, true) . ' total_facto=' . ($resp['totals']['total_amount'] ?? '?') . ' ' . ($resp['result']['error_message'] ?? '') . PHP_EOL;

global $wpdb;
$wpdb->insert($wpdb->prefix . 'riverso_dte_issued', [
    'document_type_id' => 37,
    'document_type_label' => 'Boleta electrónica',
    'facto_document_id' => $doc_id ?: null,
    'folio' => $folio ?: null,
    'issue_date' => $today,
    'payment_conditions' => '0',
    'net_amount' => $built['totals']['net_amount'],
    'taxes_amount' => $built['totals']['taxes_amount'],
    'total_amount' => $total,
    'facto_status' => $status,
    'facto_error' => 'PRUEBA 4 CLP pago mixto FACTO',
    'response_json' => wp_json_encode(['header' => $resp['header'] ?? null, 'totals' => $resp['totals'] ?? null, 'result' => $resp['result'] ?? null]),
    'created_by' => 1,
]);
if ($doc_id <= 0) {
    echo 'ABORT: sin document_id' . PHP_EOL;
    exit(1);
}

$sum = 0;
foreach ($pagos as $i => $c) {
    $n = $i + 1;
    $pay = $client->request('POST', 'payments', [
        'payment_date' => $today,
        'document_id' => (string) $doc_id,
        'payment_type_id' => $c['payment_type_id'],
        'payment_amount' => 1,
        'payment_details' => 'Prueba mixto ' . $n . '/4 ' . $c['metodo'],
        'cash_account_id' => $c['cash_account_id'],
    ]);
    if (is_wp_error($pay)) {
        echo "[$n] ERROR pago {$c['metodo']}: " . $pay->get_error_message() . ' ' . wp_json_encode($pay->get_error_data()) . PHP_EOL;
        continue;
    }
    $sum += (float) ($pay['payment_amount'] ?? 0);
    echo "[$n] PAGO OK id=" . ($pay['payment_id'] ?? '?') . " {$c['caja']} / {$c['metodo']} monto=" . ($pay['payment_amount'] ?? '?') . PHP_EOL;
}
echo "Total pagado registrado en FACTO: $sum de $total" . PHP_EOL;
echo 'FIN (emit)' . PHP_EOL;
