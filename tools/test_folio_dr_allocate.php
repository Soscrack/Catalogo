<?php
/**
 * Test local (sin WP) del reparto D/R de folio.
 * Ejecutar: php tools/test_folio_dr_allocate.php
 */
declare(strict_types=1);

function normalize_dsc_rcg_global_entries(array $entries): array {
    $out = [];
    $n = 1;
    foreach ($entries as $raw) {
        $tpo_mov = strtoupper(trim((string) ($raw['tpo_mov'] ?? '')));
        if ($tpo_mov !== 'D' && $tpo_mov !== 'R') {
            continue;
        }
        $tpo_valor = trim((string) ($raw['tpo_valor'] ?? '$'));
        $valor = (float) ($raw['valor'] ?? 0);
        if ($valor == 0.0) {
            continue;
        }
        $out[] = [
            'nro' => (int) ($raw['nro'] ?? $n),
            'tpo_mov' => $tpo_mov,
            'tpo_valor' => $tpo_valor === '%' ? '%' : '$',
            'valor' => $valor,
            'glosa' => (string) ($raw['glosa'] ?? ''),
            'ind_exe' => null,
            'monto_calculado' => null,
        ];
        $n++;
    }
    return $out;
}

function allocate(array &$factura_data): void {
    $tasa_iva = (float) ($factura_data['totales']['tasa_iva'] ?? 19);
    $monto_neto = (float) ($factura_data['totales']['neto'] ?? 0);
    $entries = normalize_dsc_rcg_global_entries((array) ($factura_data['dsc_rcg_global'] ?? []));
    $product_idxs = [];
    $sum_lineas = 0.0;
    foreach ($factura_data['items'] as $idx => $item) {
        $product_idxs[] = $idx;
        $sum_lineas += (float) ($item['costo_neto_final'] ?? $item['monto'] ?? 0);
    }
    if (!$entries && $monto_neto > 0 && $sum_lineas > 0) {
        $gap = round($sum_lineas - $monto_neto, 2);
        if (abs($gap) >= 0.5) {
            $entries = [[
                'nro' => 1,
                'tpo_mov' => $gap > 0 ? 'D' : 'R',
                'tpo_valor' => '$',
                'valor' => abs($gap),
                'glosa' => 'inferido',
                'ind_exe' => null,
                'monto_calculado' => abs($gap),
            ]];
        }
    }
    $cuotas = array_fill_keys($product_idxs, 0.0);
    foreach ($entries as &$entry) {
        $sign = ($entry['tpo_mov'] === 'D') ? -1.0 : 1.0;
        $pool = 0.0;
        foreach ($product_idxs as $idx) {
            $pool += (float) ($factura_data['items'][$idx]['costo_neto_final'] ?? 0);
        }
        $amount = $entry['tpo_valor'] === '%'
            ? round($pool * ((float) $entry['valor']) / 100, 0)
            : round((float) $entry['valor'], 0);
        $entry['monto_calculado'] = $amount;
        $signed = $sign * $amount;
        $assigned = 0.0;
        $last = count($product_idxs) - 1;
        foreach ($product_idxs as $i => $idx) {
            $line = (float) $factura_data['items'][$idx]['costo_neto_final'];
            if ($i === $last) {
                $share = round($signed - $assigned, 4);
            } else {
                $share = round($signed * ($line / $pool), 4);
                $assigned += $share;
            }
            $cuotas[$idx] += $share;
        }
    }
    unset($entry);

    $sum_folio = 0.0;
    foreach ($product_idxs as $idx) {
        $neto_final = (float) $factura_data['items'][$idx]['costo_neto_final'];
        $cuota = round($cuotas[$idx], 4);
        $neto_folio = round($neto_final + $cuota, 4);
        $factura_data['items'][$idx]['dsc_rcg_global_cuota'] = $cuota;
        $factura_data['items'][$idx]['costo_neto_folio'] = $neto_folio;
        $sum_folio += $neto_folio;
    }
    $diff = round($monto_neto - $sum_folio, 4);
    if (abs($diff) >= 0.01 && abs($diff) <= 2.0) {
        $best = $product_idxs[0];
        $best_m = 0.0;
        foreach ($product_idxs as $idx) {
            $m = (float) $factura_data['items'][$idx]['costo_neto_final'];
            if ($m > $best_m) {
                $best_m = $m;
                $best = $idx;
            }
        }
        $factura_data['items'][$best]['costo_neto_folio'] = round(
            (float) $factura_data['items'][$best]['costo_neto_folio'] + $diff,
            4
        );
        $sum_folio = $monto_neto;
        $diff = 0.0;
    }
    $factura_data['sum_folio'] = $sum_folio;
    $factura_data['ok'] = abs($diff) < 1.0;
}

$pass = 0;
$fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "OK  $msg\n";
        $pass++;
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
}

// Caso 724006: 1 línea, DscRcgGlobal $1869
$f = [
    'totales' => ['neto' => 60427, 'tasa_iva' => 19],
    'dsc_rcg_global' => [['tpo_mov' => 'D', 'tpo_valor' => '$', 'valor' => 1869]],
    'items' => [
        ['costo_neto_final' => 62296, 'cantidad' => 2600],
    ],
];
allocate($f);
assert_true(abs($f['items'][0]['costo_neto_folio'] - 60427) < 0.01, '724006 folio neto = 60427');
assert_true(abs($f['items'][0]['costo_neto_folio'] / 2600 - 23.2412) < 0.01, '724006 unitario ~23.24');
assert_true($f['ok'], '724006 ok');

// Caso 729815: gap 27917
$f2 = [
    'totales' => ['neto' => 902638, 'tasa_iva' => 19],
    'dsc_rcg_global' => [['tpo_mov' => 'D', 'tpo_valor' => '$', 'valor' => 27917]],
    'items' => [
        ['costo_neto_final' => 5336],
        ['costo_neto_final' => 61940],
        ['costo_neto_final' => 863279], // resto para sumar 930555
    ],
];
allocate($f2);
$sum = array_sum(array_column($f2['items'], 'costo_neto_folio'));
assert_true(abs($sum - 902638) < 1.0, "729815 sum folio=$sum == 902638");
assert_true($f2['ok'], '729815 ok');

// Caso 62953: solo D/R fila, sin global
$f3 = [
    'totales' => ['neto' => 385320, 'tasa_iva' => 19],
    'dsc_rcg_global' => [],
    'items' => [
        ['costo_neto_final' => 53700],
        ['costo_neto_final' => 70200],
        ['costo_neto_final' => 261420],
    ],
];
allocate($f3);
assert_true(abs($f3['items'][0]['costo_neto_folio'] - 53700) < 0.01, '62953 folio = fila');
assert_true(abs(array_sum(array_column($f3['items'], 'costo_neto_folio')) - 385320) < 0.01, '62953 sum = neto');

// Inferencia por gap
$f4 = [
    'totales' => ['neto' => 100, 'tasa_iva' => 19],
    'dsc_rcg_global' => [],
    'items' => [
        ['costo_neto_final' => 60],
        ['costo_neto_final' => 50],
    ],
];
allocate($f4);
assert_true(abs(array_sum(array_column($f4['items'], 'costo_neto_folio')) - 100) < 0.01, 'gap inferido cuadra a 100');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
