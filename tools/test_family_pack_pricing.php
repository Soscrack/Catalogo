<?php
/**
 * Prueba local de fórmulas pack/kit (sin WordPress).
 * Uso: php tools/test_family_pack_pricing.php
 */

function techo50($valor) {
    $valor = (float) $valor;
    if ($valor <= 0) {
        return 0.0;
    }
    return (float) (ceil($valor / 50) * 50);
}

function pack_precio($P, $d, $Q) {
    return techo50(($P * (1 - $d)) * $Q);
}

function pack_margen($P, $c_ref, $d, $Q, $iva = 1.19) {
    $neto0 = $P / $iva;
    $factor0 = $neto0 / $c_ref;
    $margen1 = ($factor0 - 1) * (1 - $d);
    $factor1 = 1 + $margen1;
    $bruto1 = ($c_ref * $factor1) * $iva;
    return techo50($bruto1 * $Q);
}

$P = 1000;
$d = 0.10;
$Q = 6;
$total = pack_precio($P, $d, $Q);
// (1000*0.9)*6 = 5400 → T50 = 5400
assert(abs($total - 5400) < 0.01, 'pack precio 6');

$Q2 = 6;
$total2 = pack_precio(1190, 0.05, $Q2);
// 1190*0.95*6 = 6783 → T50 = 6800
assert(abs($total2 - 6800) < 0.01, "pack precio redondeo got $total2");

// Dos packs: 2 * T50(...), no T50 sobre 12
$two = 2 * pack_precio($P, $d, 6);
assert(abs($two - 10800) < 0.01, 'dos packs');

echo "OK pack pricing tests\n";
