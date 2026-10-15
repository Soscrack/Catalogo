<?php
/**
 * Prueba de integración: Recepción de códigos de caja de familia (fase 80).
 * La factura cuenta unidades (o cajas) y el stock entra en cajas del producto vinculado;
 * si no calza con cajas enteras, quien recibe indica cajas + sueltas (al unitario).
 * Uso: php -d extension=mysqli -d extension=mbstring tests/recepcion-cajas/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

function riverso_event_publish() {}
function absint($v) { return abs((int) $v); }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function get_option($k, $d = false) { return $GLOBALS['TEST_OPTIONS'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['TEST_OPTIONS'][$k] = $v; return true; }
function sanitize_textarea_field($s) { return trim((string) $s); }

/* ---------- Esquema base (lo que usa Recepción y el servicio de unidades) ---------- */
$wpdb->query("CREATE TABLE {$p}producto_base (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_sku VARCHAR(100) DEFAULT NULL,
    nombre_canonico VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, woocommerce_product_id BIGINT DEFAULT NULL,
    woocommerce_variation_id BIGINT DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}proveedores (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL, telefono VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_proveedor (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL, codigo_proveedor VARCHAR(100) NOT NULL, factor_conversion DECIMAL(10,4) DEFAULT 1.0000,
    es_preferido TINYINT(1) DEFAULT 0, activo TINYINT(1) DEFAULT 1, match_estado VARCHAR(20) DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_groups (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nombre VARCHAR(255),
    es_producto_unitario TINYINT(1) DEFAULT 0, unit_producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    tipo_sustitucion VARCHAR(20) DEFAULT 'exacta', activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}envases (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    cantidad_unidades DECIMAL(12,4) NOT NULL DEFAULT 1, codigo_proveedor VARCHAR(100) DEFAULT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}codigo_barra (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(100) NOT NULL, cantidad DECIMAL(12,4) DEFAULT 1, factor_a_unidad_base DECIMAL(12,4) DEFAULT 1,
    activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}tareas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tipo VARCHAR(50) NOT NULL,
    referencia_tipo VARCHAR(50), referencia_id BIGINT UNSIGNED, estado VARCHAR(20) DEFAULT 'pendiente', completado_en DATETIME NULL,
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}facturas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tipo_dte INT NOT NULL, folio VARCHAR(50) NOT NULL,
    proveedor_id BIGINT UNSIGNED DEFAULT NULL, fecha_emision DATE NOT NULL, monto_total DECIMAL(12,2) DEFAULT 0,
    rut_emisor VARCHAR(20) DEFAULT '', razon_social_emisor VARCHAR(255) DEFAULT '', documento_subtipo VARCHAR(30) DEFAULT 'productos',
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}factura_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, factura_id BIGINT UNSIGNED NOT NULL,
    numero_linea INT DEFAULT 1, codigo_proveedor VARCHAR(100) DEFAULT NULL, nombre VARCHAR(255) NOT NULL, cantidad DECIMAL(12,4) NOT NULL,
    unidad VARCHAR(20) DEFAULT NULL, item_tipo VARCHAR(20) DEFAULT 'producto', costo_neto_final DECIMAL(14,4) DEFAULT NULL,
    monto_total DECIMAL(14,4) DEFAULT NULL, precio_unitario DECIMAL(14,4) DEFAULT NULL, sku_local VARCHAR(100) DEFAULT NULL,
    product_id BIGINT DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}ubicaciones (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo VARCHAR(50) NOT NULL, nombre VARCHAR(100),
    tipo VARCHAR(20) DEFAULT NULL, descripcion TEXT, capacidad INT DEFAULT 0, activo TINYINT(1) DEFAULT 1, barcode VARCHAR(100),
    zona VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_ubicacion (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED NOT NULL,
    ubicacion_id BIGINT UNSIGNED NOT NULL, cantidad DECIMAL(12,4) DEFAULT 0, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}movimientos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED, tipo VARCHAR(30),
    cantidad DECIMAL(12,4), stock_anterior DECIMAL(12,4), stock_nuevo DECIMAL(12,4), ubicacion_origen BIGINT UNSIGNED NULL,
    ubicacion_destino BIGINT UNSIGNED NULL, referencia_tipo VARCHAR(50), referencia_id BIGINT UNSIGNED NULL, notas TEXT,
    usuario_id BIGINT UNSIGNED, created_at DATETIME, lote_id BIGINT UNSIGNED NULL, PRIMARY KEY (id)) $cc");

require RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
require RIVERSO_POS_PLUGIN_DIR . 'inventory/movements/class-movement.php';
require RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
require RIVERSO_POS_PLUGIN_DIR . 'purchases/reception/class-purchase-reception-service.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . (!$cond && $detail !== '' ? "\n         → " . $detail : '') . "\n";
}
function throws(callable $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}
function line_of(array $doc, $code, $suelto = false) {
    foreach ($doc['lines'] as $l) {
        if ($l['codigo_proveedor'] === $code && (bool) $l['suelto'] === $suelto) {
            return $l;
        }
    }
    return null;
}
function balance($pb, $loc = null) {
    global $wpdb, $p;
    return (float) $wpdb->get_var("SELECT COALESCE(SUM(cantidad), 0) FROM {$p}producto_ubicacion WHERE product_id = " . (int) $pb .
        ($loc ? ' AND ubicacion_id = ' . (int) $loc : ''));
}

/* ---------- Datos: familia CRS 6x2 (unitario 272 + caja 500 247610), como TECBOLT ---------- */
$wpdb->insert("{$p}proveedores", ['nombre' => 'TECBOLT SA']);
$prov = $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '272', 'nombre_canonico' => 'CRS 6 x 2 Zinc. (B23TAD)']);
$unit = $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '247610', 'nombre_canonico' => 'CRS 6 x 2 (Caja 500)']);
$box = $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '29248', 'nombre_canonico' => 'Pintura spray REX blanco']);
$spray = $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_groups", ['nombre' => 'CRS 6x2', 'es_producto_unitario' => 1, 'unit_producto_base_id' => $unit]);
$grupo = $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $grupo, 'producto_base_id' => $unit]);
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $grupo, 'producto_base_id' => $box]);
$wpdb->insert("{$p}envases", ['producto_base_id' => $box, 'cantidad_unidades' => 500]);
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $box, 'proveedor_id' => $prov, 'codigo_proveedor' => 'B23TAD', 'match_estado' => 'VERIFIED']);
$pp_box = $wpdb->insert_id;
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $spray, 'proveedor_id' => $prov, 'codigo_proveedor' => 'CSBN-006', 'factor_conversion' => 6]);
$wpdb->insert("{$p}ubicaciones", ['codigo' => 'BOD-1', 'nombre' => 'Bodega', 'tipo' => 'bodega']);
$bodega = $wpdb->insert_id;

function factura($folio, array $lines) {
    global $wpdb, $p, $prov;
    $wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => $folio, 'proveedor_id' => $prov, 'fecha_emision' => '2026-10-01']);
    $id = $wpdb->insert_id;
    $n = 1;
    foreach ($lines as $l) {
        $wpdb->insert("{$p}factura_items", [
            'factura_id' => $id, 'numero_linea' => $n++, 'codigo_proveedor' => $l[0], 'nombre' => $l[0] . ' ítem',
            'cantidad' => $l[1], 'unidad' => 'U', 'costo_neto_final' => $l[2],
        ]);
    }
    return $id;
}
function item_id($factura_id, $code) {
    global $wpdb, $p;
    return (int) $wpdb->get_var("SELECT id FROM {$p}factura_items WHERE factura_id = " . (int) $factura_id . " AND codigo_proveedor = '" . $code . "'");
}

$svc = Riverso_Purchase_Reception_Service::get_instance();
$units = Riverso_Unit_Product_Service::get_instance();

echo "Migración\n";
check('ready() crea recepción y las columnas de cajas', $svc->ready() === true);
$cols = $wpdb->get_col("SHOW COLUMNS FROM {$p}recepcion_lineas");
check('  recepcion_lineas.unidades_envase y es_suelto', in_array('unidades_envase', $cols, true) && in_array('es_suelto', $cols, true));
Riverso_POS_Activator::ensure_reception_packs();
check('  idempotente', count(array_keys($wpdb->get_col("SHOW COLUMNS FROM {$p}recepcion_lineas"), 'es_suelto', true)) === 1);
$rx = $svc->location_id();

echo "Caja de familia sin confirmar\n";
$f1 = factura('750061', [['B23TAD', 20000, 106700]]); // 20.000 U a $5,335
$doc = $svc->get_document($f1);
$l = line_of($doc, 'B23TAD');
check('la línea pide confirmar en Procesar folios', $l['pendiente_confirmar'] === true && strpos($l['folio_url'], 'factura_id=' . $f1) !== false, json_encode($l));
check('  y apunta a la caja 247610', $l['producto_base_id'] === $box && $l['unidades_envase'] == 500);
$err = throws(function () use ($svc, $f1) {
    $svc->receive($f1, [['factura_item_id' => item_id($f1, 'B23TAD'), 'cantidad' => 40]]);
});
check('recibir se rechaza hasta confirmar', strpos($err, 'falta confirmar') !== false, $err);
check('  y no entra stock', balance($box) == 0);
check('purchase_units_confirmed: sin tarea = no', $units->purchase_units_confirmed($pp_box) === false);

echo "Confirmado: la factura cuenta unidades (factor 1)\n";
$wpdb->insert("{$p}tareas", ['tipo' => 'confirmar_unidades_compra', 'referencia_tipo' => 'producto_proveedor', 'referencia_id' => $pp_box, 'estado' => 'completada']);
check('purchase_units_confirmed: tarea completada = sí', $units->purchase_units_confirmed($pp_box) === true);
$ctx = $units->resolve_purchase_units($box, 'B23TAD', $prov);
check('resolve_purchase_units: caja de familia sigue sin apply_factor (flujos antiguos)', $ctx['is_family_pack'] === true && $ctx['apply_factor'] === false && $ctx['factor'] == 1);
check('  e informa invoice_factor y el unitario', $ctx['invoice_factor'] == 1 && (int) $ctx['unit_producto_base_id'] === $unit, json_encode($ctx));
$doc = $svc->get_document($f1);
$l = line_of($doc, 'B23TAD');
check('20.000 U → se esperan 40 cajas', $l['esperada'] == 40 && $l['total_unidades'] == 20000 && !$l['requiere_reparto'], json_encode([$l['esperada'], $l['total_unidades']]));
check('  leer el código suma 1 caja', $l['por_escaneo'] == 1);
$doc = $svc->receive($f1, [['factura_item_id' => item_id($f1, 'B23TAD'), 'cantidad' => 40]]);
check('recibir 40 → 40 cajas de 247610 en Recepción', balance($box, $rx) == 40 && balance($unit) == 0, balance($box) . ' / ' . balance($unit));
check('  documento recibido', $doc['estado'] === 'recibida');
$rl = $wpdb->get_row("SELECT * FROM {$p}recepcion_lineas WHERE factura_item_id = " . item_id($f1, 'B23TAD'), ARRAY_A);
check('  costo por caja = 5,335 × 500', abs((float) $rl['costo_unitario'] - 2667.5) < 0.01 && (float) $rl['unidades_envase'] == 500, $rl['costo_unitario']);

echo "No calza con cajas enteras: reparto manual\n";
$f2 = factura('750062', [['B23TAD', 20250, 108033.75]]);
$doc = $svc->get_document($f2);
$l = line_of($doc, 'B23TAD');
check('20.250 U → requiere reparto (40,5 cajas)', $l['requiere_reparto'] === true && $l['esperada'] == 40.5 && (int) $l['unidad_pb'] === $unit && $l['unidad_sku'] === '272', json_encode($l));
$item2 = item_id($f2, 'B23TAD');
$err = throws(function () use ($svc, $f2, $item2) {
    $svc->receive($f2, [['factura_item_id' => $item2, 'cantidad' => 40]]);
});
check('sin reparto se rechaza', strpos($err, 'no calza') !== false, $err);
$err = throws(function () use ($svc, $f2, $item2) {
    $svc->receive($f2, [['factura_item_id' => $item2, 'cantidad' => 40, 'reparto' => ['cajas' => 40, 'sueltas' => 200]]]);
});
check('reparto que no suma 20.250 se rechaza', strpos($err, 'pero el documento dice') !== false, $err);
$err = throws(function () use ($svc, $f2, $item2) {
    $svc->receive($f2, [['factura_item_id' => $item2, 'cantidad' => 40, 'reparto' => ['cajas' => 40.5, 'sueltas' => 0]]]);
});
check('cajas no enteras se rechazan', strpos($err, 'enteras') !== false, $err);
check('  nada se registró', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}recepcion_lineas WHERE factura_item_id = {$item2}") === 0);
$reparto = ['cajas' => 40, 'sueltas' => 250];
$doc = $svc->receive($f2, [
    ['factura_item_id' => $item2, 'parte' => 'envase', 'cantidad' => 40, 'reparto' => $reparto],
    ['factura_item_id' => $item2, 'parte' => 'suelto', 'cantidad' => 250, 'reparto' => $reparto],
]);
check('40 cajas a 247610 y 250 sueltas al unitario 272', balance($box, $rx) == 80 && balance($unit, $rx) == 250, balance($box) . ' / ' . balance($unit));
$caja = line_of($doc, 'B23TAD');
$suelta = line_of($doc, 'B23TAD', true);
check('  dos líneas del mismo ítem (cajas y sueltas)', $caja && $suelta && $caja['esperada'] == 40 && $suelta['esperada'] == 250 && $suelta['producto_base_id'] === $unit && $suelta['key'] !== $caja['key']);
check('  costo de la suelta por unidad', abs((float) $wpdb->get_var("SELECT costo_unitario FROM {$p}recepcion_lineas WHERE es_suelto = 1") - 5.335) < 0.001);
check('  documento recibido', $doc['estado'] === 'recibida');

echo "Parcial y cierre con faltante\n";
$f3 = factura('750063', [['B23TAD', 20000, 106700]]);
$doc = $svc->receive($f3, [['factura_item_id' => item_id($f3, 'B23TAD'), 'cantidad' => 30]], true);
$claim = $wpdb->get_row("SELECT * FROM {$p}reclamo_lineas ORDER BY id DESC LIMIT 1", ARRAY_A);
check('faltan 10 cajas: reclamo en cajas con costo por caja', $claim && (float) $claim['cantidad'] == 10 && abs((float) $claim['costo_unitario'] - 2667.5) < 0.01 && (int) $claim['producto_base_id'] === $box, json_encode($claim));

echo "Reparto con faltante: cada parte por separado\n";
$f4 = factura('750064', [['B23TAD', 20250, 108033.75]]);
$item4 = item_id($f4, 'B23TAD');
$svc->receive($f4, [['factura_item_id' => $item4, 'parte' => 'suelto', 'cantidad' => 200, 'reparto' => $reparto]], true);
$claims = $wpdb->get_results("SELECT producto_base_id, cantidad FROM {$p}reclamo_lineas WHERE factura_item_id = {$item4} ORDER BY producto_base_id", ARRAY_A);
check('recibió 200 sueltas: reclamo de 40 cajas y 50 sueltas', count($claims) === 2 && (float) $claims[0]['cantidad'] == 50 && (float) $claims[1]['cantidad'] == 40, json_encode($claims));

echo "La factura cuenta cajas (factor 500)\n";
$wpdb->update("{$p}producto_proveedor", ['factor_conversion' => 500], ['id' => $pp_box]);
$f5 = factura('750065', [['B23TAD', 40, 106700]]); // 40 cajas a $2.667,5
$l = line_of($svc->get_document($f5), 'B23TAD');
check('40 cajas → se esperan 40 cajas', $l['esperada'] == 40 && $l['total_unidades'] == 20000, json_encode([$l['esperada'], $l['total_unidades']]));
$svc->receive($f5, [['factura_item_id' => item_id($f5, 'B23TAD'), 'cantidad' => 40]]);
$rl = $wpdb->get_row("SELECT costo_unitario FROM {$p}recepcion_lineas WHERE factura_item_id = " . item_id($f5, 'B23TAD'), ARRAY_A);
check('  costo por caja 2.667,5', abs((float) $rl['costo_unitario'] - 2667.5) < 0.01, $rl['costo_unitario']);
$wpdb->update("{$p}producto_proveedor", ['factor_conversion' => 1], ['id' => $pp_box]);

echo "Pregunta reabierta (tarea abierta) vuelve a bloquear\n";
$wpdb->insert("{$p}tareas", ['tipo' => 'confirmar_unidades_compra', 'referencia_tipo' => 'producto_proveedor', 'referencia_id' => $pp_box, 'estado' => 'pendiente']);
$f6 = factura('750066', [['B23TAD', 500, 2667.5]]);
check('con tarea abierta, pendiente de confirmar', line_of($svc->get_document($f6), 'B23TAD')['pendiente_confirmar'] === true);
echo "Asignación manual al unitario\n";
$doc = $svc->receive($f6, [['factura_item_id' => item_id($f6, 'B23TAD'), 'producto_base_id' => $unit, 'cantidad' => 500]]);
$l = line_of($doc, 'B23TAD');
check('asignado a mano: entra la cantidad del documento al unitario, sin envase', $l['producto_base_id'] === $unit && $l['esperada'] == 500 && $l['unidades_envase'] == 1 && $l['asignado_manual'] === true, json_encode($l));

echo "Código normal (paquete de 6) no cambia\n";
$f7 = factura('1292924', [['CSBN-006', 2, 17654]]);
$l = line_of($svc->get_document($f7), 'CSBN-006');
check('2 paquetes × 6 → 12 unidades, sin envase', $l['esperada'] == 12 && $l['unidades_envase'] == 1 && !$l['pendiente_confirmar'] && $l['por_escaneo'] == 6, json_encode($l));

echo "Abrir caja descuenta la caja en el stock nuevo\n";
$antes = balance($box, $rx);
Riverso_Movement::create('apertura', $box, 1, ['ubicacion_origen' => $rx, 'referencia_tipo' => 'apertura']);
check('movimiento apertura resta 1 caja del lugar', balance($box, $rx) == $antes - 1, $antes . ' → ' . balance($box, $rx));

echo $fails ? "\n{$fails} prueba(s) fallaron\n" : "\nTodo OK\n";
exit($fails ? 1 : 0);
