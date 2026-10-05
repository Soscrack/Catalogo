<?php
/**
 * Prueba: boletas de $1 + POST /payments en FACTO. Uso: php facto_test_boletas.php WP_PATH dry|emit
 */
$wp_path = $argv[1] ?? '';
$mode = $argv[2] ?? 'dry';
require $wp_path . '/wp-load.php';

$combos = [
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
foreach ($combos as $i => $c) {
    $n = $i + 1;
    $_POST = [
        'document_type_id' => '37',
        'issue_date' => $today,
        'payment_conditions' => '0',
        'lines' => wp_json_encode([[
            'description' => 'Prueba ' . $n . ' - ' . $c['caja'] . ' / ' . $c['metodo'],
            'sku' => 'PRUEBA',
            'quantity' => 1,
            'unit_price_bruto' => 1,
            'afecto' => true,
        ]]),
    ];
    $built = $build->invoke($module, false);
    if (is_wp_error($built)) {
        echo "[$n] ERROR payload: " . $built->get_error_message() . PHP_EOL;
        exit(1);
    }
    $total = (float) $built['totals']['total_amount'];
    echo "[$n] {$c['caja']} / {$c['metodo']} -> tipo {$built['document_type_id']} neto {$built['totals']['net_amount']} iva {$built['totals']['taxes_amount']} total $total" . PHP_EOL;
    if ($total !== 1.0 || (int) $built['document_type_id'] !== 37) {
        echo "[$n] ABORT: total distinto de 1 CLP o tipo distinto de boleta 37" . PHP_EOL;
        exit(1);
    }
    if ($mode !== 'emit') {
        continue;
    }

    $resp = $client->create_document($built['payload'], true);
    if (is_wp_error($resp)) {
        echo "[$n] ERROR emisión: " . $resp->get_error_message() . ' ' . wp_json_encode($resp->get_error_data()) . PHP_EOL;
        exit(1);
    }
    $doc_id = (int) ($resp['document_id'] ?? ($resp['header']['document_id'] ?? 0));
    $folio = (string) ($resp['header']['document_number'] ?? ($resp['document_number'] ?? ''));
    $status = $resp['result']['status'] ?? null;
    $err = $resp['result']['error_message'] ?? '';
    $facto_total = $resp['totals']['total_amount'] ?? '?';
    echo "[$n] EMITIDA doc_id=$doc_id folio=$folio status=" . var_export($status, true) . " total_facto=$facto_total $err" . PHP_EOL;

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
        'facto_error' => $err ?: 'PRUEBA 1 CLP pagos FACTO',
        'response_json' => wp_json_encode(['header' => $resp['header'] ?? null, 'totals' => $resp['totals'] ?? null, 'result' => $resp['result'] ?? null]),
        'created_by' => 1,
    ]);

    if ($doc_id <= 0) {
        echo "[$n] ABORT: sin document_id" . PHP_EOL;
        exit(1);
    }

    $pay = $client->request('POST', 'payments', [
        'payment_date' => $today,
        'document_id' => (string) $doc_id,
        'payment_type_id' => $c['payment_type_id'],
        'payment_amount' => 1,
        'payment_details' => 'Prueba Riverso ' . $c['metodo'],
        'cash_account_id' => $c['cash_account_id'],
    ]);
    if (is_wp_error($pay)) {
        echo "[$n] ERROR pago: " . $pay->get_error_message() . ' ' . wp_json_encode($pay->get_error_data()) . PHP_EOL;
        exit(1);
    }
    echo "[$n] PAGO OK: " . wp_json_encode($pay) . PHP_EOL;
    $pid = $pay['payment_id'] ?? ($pay['id'] ?? null);
    if ($pid) {
        $get = $client->request('GET', 'payments/' . rawurlencode((string) $pid));
        echo "[$n] GET pago: " . (is_wp_error($get) ? 'ERROR ' . $get->get_error_message() : wp_json_encode($get)) . PHP_EOL;
    }
}
echo "FIN ($mode)" . PHP_EOL;
