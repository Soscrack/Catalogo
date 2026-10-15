<?php
/**
 * Prueba de integración del servicio de pedidos de compra contra MySQL local.
 * Recorre el ciclo: avisos → borrador → envío → cotización → revisión → aceptación → recepción.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/pedidos/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

/* ---------- Esquema base (definiciones del activador, reducidas a lo que se usa) ---------- */
$wpdb->query("CREATE TABLE {$p}producto_base (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_sku VARCHAR(100) DEFAULT NULL,
    nombre_canonico VARCHAR(255) DEFAULT NULL, estado VARCHAR(20) DEFAULT 'activo', deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id), UNIQUE KEY ux_canonical_sku (canonical_sku)) $cc");
$wpdb->query("CREATE TABLE {$p}proveedores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, rut VARCHAR(20) NOT NULL, nombre VARCHAR(255) NOT NULL,
    activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_proveedor (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    grupo_id BIGINT UNSIGNED DEFAULT NULL, proveedor_id BIGINT UNSIGNED NOT NULL,
    codigo_proveedor VARCHAR(100) NOT NULL, codigo_barras_proveedor VARCHAR(50) DEFAULT NULL,
    nombre_proveedor VARCHAR(255) DEFAULT NULL, unidad_compra VARCHAR(20) DEFAULT NULL,
    factor_conversion DECIMAL(10,4) DEFAULT 1.0000, es_preferido TINYINT(1) DEFAULT 0, activo TINYINT(1) DEFAULT 1,
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_groups (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nombre VARCHAR(255),
    unit_producto_base_id BIGINT UNSIGNED DEFAULT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}emparejamientos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo VARCHAR(40), nombre VARCHAR(191),
    emparejar_stock TINYINT(1) NOT NULL DEFAULT 0, stock_minimo INT DEFAULT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}emparejamiento_miembros (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, emparejamiento_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE wp_users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, display_name VARCHAR(250), PRIMARY KEY (ID)) $cc");
// Tabla de OC como la crea el activador en producción: proveedor obligatorio, sin las columnas nuevas.
$wpdb->query("CREATE TABLE {$p}ordenes_compra (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, numero VARCHAR(50) DEFAULT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL, estado VARCHAR(30) NOT NULL DEFAULT 'borrador', cotizacion_id BIGINT UNSIGNED DEFAULT NULL,
    total DECIMAL(12,2) DEFAULT 0, notas TEXT DEFAULT NULL, creado_por BIGINT UNSIGNED DEFAULT NULL, enviado_en DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}cotizaciones_recibidas (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, proveedor_id BIGINT UNSIGNED NULL,
    numero_documento VARCHAR(100) NULL, fecha_documento DATE NULL, estado VARCHAR(50) DEFAULT 'draft', total DECIMAL(15,2) DEFAULT 0,
    version_group_id BIGINT UNSIGNED NULL, version_n SMALLINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL) $cc");
$wpdb->query("CREATE TABLE {$p}cotizacion_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cotizacion_id BIGINT UNSIGNED NOT NULL,
    linea INT UNSIGNED DEFAULT 1, codigo_proveedor VARCHAR(100) NULL, descripcion TEXT NULL, cantidad DECIMAL(15,4) DEFAULT 1,
    unidad VARCHAR(20) DEFAULT 'UN', costo_neto DECIMAL(15,4) DEFAULT 0, producto_id BIGINT UNSIGNED NULL, sku_match VARCHAR(100) NULL,
    decision_status VARCHAR(20) NOT NULL DEFAULT 'pending', costo_anterior DECIMAL(15,4) NULL, diferencia_costo DECIMAL(15,4) NULL,
    created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL) $cc");
$wpdb->query("CREATE TABLE {$p}facturas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tipo_dte INT NOT NULL, folio VARCHAR(50) NOT NULL,
    proveedor_id BIGINT UNSIGNED DEFAULT NULL, fecha_emision DATE NOT NULL, monto_total DECIMAL(12,2) DEFAULT 0, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}factura_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, factura_id BIGINT UNSIGNED NOT NULL,
    codigo_proveedor VARCHAR(100) DEFAULT NULL, nombre VARCHAR(255) NOT NULL, cantidad DECIMAL(12,4) NOT NULL, unidad VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}factura_referencias (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, factura_id BIGINT UNSIGNED NOT NULL,
    tipo_doc_ref INT DEFAULT NULL, folio_ref VARCHAR(50) NOT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}recepciones (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, factura_id BIGINT UNSIGNED NOT NULL,
    estado VARCHAR(12) NOT NULL DEFAULT 'parcial', PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}recepcion_lineas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, recepcion_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, cantidad_recibida DECIMAL(12,4) NOT NULL DEFAULT 0,
    cantidad_reclamada DECIMAL(12,4) NOT NULL DEFAULT 0, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}reclamos_proveedor (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, factura_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}reclamo_lineas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reclamo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NULL, motivo VARCHAR(20) NOT NULL DEFAULT 'faltante', cantidad DECIMAL(12,4) NOT NULL DEFAULT 0, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_stock_config (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    stock_minimo INT DEFAULT NULL, stock_critico INT DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_ubicacion (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 0, PRIMARY KEY (id)) $cc");

/* ---------- Datos ---------- */
foreach ([[1, 'Pedro Bodega'], [2, 'Ana Mesón'], [3, 'Carla Compras']] as $u) {
    $wpdb->insert('wp_users', ['ID' => $u[0], 'display_name' => $u[1]]);
}
foreach ([[1, 'FIJACIONES MAMUT'], [2, 'STEELFIX SPA'], [3, 'WURTH CHILE LTDA']] as $s) {
    $wpdb->insert("{$p}proveedores", ['id' => $s[0], 'rut' => '76.000.00' . $s[0] . '-' . $s[0], 'nombre' => $s[1]]);
}
$products = [
    'tarugo' => ['752', 'TARUGO DE NYLON M-5 X 25'],
    'perno' => ['900', 'PERNO HEX 1/2 X 2'],
    'golilla' => ['901', 'GOLILLA PLANA 1/2'],
    'tuerca' => ['902', 'TUERCA HEX 1/2'],
    'broca' => ['22306', 'BROCA HSS 11 MM'],
    'ancla_a' => ['28690', 'ANCLAJE 3/8 X 3 ZINC STEELFIX'],
    'ancla_b' => ['28691', 'ANCLAJE 3/8 X 3 ZINC MAMUT'],
    'silicona' => ['5000', 'SILICONA NEUTRA 280 ML'],
    'disco' => ['6000', 'DISCO CORTE 4 1/2'],
];
$pb = [];
foreach ($products as $key => $row) {
    $wpdb->insert("{$p}producto_base", ['canonical_sku' => $row[0], 'nombre_canonico' => $row[1]]);
    $pb[$key] = (int) $wpdb->insert_id;
}
$link = function ($product, $supplier, $code, $unit = 'caja', $factor = 1, $preferred = 0) use ($wpdb, $p, $pb) {
    $wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $pb[$product], 'proveedor_id' => $supplier,
        'codigo_proveedor' => $code, 'unidad_compra' => $unit, 'factor_conversion' => $factor, 'es_preferido' => $preferred]);
    return (int) $wpdb->insert_id;
};
$link('tarugo', 1, '01TRN', 'caja', 100);
$link('perno', 1, '02PH', 'caja', 50);
$link('golilla', 1, '03GP', 'caja', 1);
$link('tuerca', 1, '04TH', 'caja', 100);
$link('broca', 3, '0617 401 100', 'unidad', 1);
$pp_ancla_a = $link('ancla_a', 2, '000-139', 'caja', 50);
$pp_ancla_b = $link('ancla_b', 1, '14RLBC', 'caja', 50);
$link('silicona', 3, 'SIL-280', 'unidad', 1);
$link('disco', 3, 'DC-412', 'caja', 25);
// Emparejamiento de stock: el mismo anclaje lo distribuyen Steelfix y Mamut.
$wpdb->insert("{$p}emparejamientos", ['codigo' => 'EMP-1', 'nombre' => 'Anclaje 3/8 x 3 zinc', 'emparejar_stock' => 1, 'stock_minimo' => 200]);
$emp = (int) $wpdb->insert_id;
$wpdb->insert("{$p}emparejamiento_miembros", ['emparejamiento_id' => $emp, 'producto_base_id' => $pb['ancla_a']]);
$wpdb->insert("{$p}emparejamiento_miembros", ['emparejamiento_id' => $emp, 'producto_base_id' => $pb['ancla_b']]);

// OC anterior, creada por "cotización → OC": su línea trae un ID de WooCommerce (4455) como producto.
$wpdb->insert("{$p}cotizaciones_recibidas", ['proveedor_id' => 3, 'numero_documento' => 'VIEJA', 'estado' => 'converted_to_expected']);
$q_old = (int) $wpdb->insert_id;
$wpdb->insert("{$p}cotizacion_items", ['cotizacion_id' => $q_old, 'codigo_proveedor' => 'SIL-280', 'descripcion' => 'Silicona', 'cantidad' => 12, 'producto_id' => 4455, 'sku_match' => '5000']);
$wpdb->insert("{$p}cotizacion_items", ['cotizacion_id' => $q_old, 'codigo_proveedor' => 'ZZZ', 'descripcion' => 'Sin SKU', 'cantidad' => 1, 'producto_id' => 4456]);
$wpdb->insert("{$p}ordenes_compra", ['numero' => 'OC-20260901-AbCd', 'proveedor_id' => 3, 'estado' => 'borrador', 'cotizacion_id' => $q_old]);
$oc_old = (int) $wpdb->insert_id;
$wpdb->query("CREATE TABLE {$p}ordenes_compra_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, orden_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED DEFAULT NULL, producto_proveedor_id BIGINT UNSIGNED DEFAULT NULL, descripcion VARCHAR(255) DEFAULT NULL,
    cantidad DECIMAL(12,3) NOT NULL DEFAULT 0, cantidad_recibida DECIMAL(12,3) NOT NULL DEFAULT 0, unidad VARCHAR(30) DEFAULT 'unidad',
    precio_unitario DECIMAL(12,4) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) $cc");
$wpdb->insert("{$p}ordenes_compra_items", ['orden_id' => $oc_old, 'producto_base_id' => 4455, 'descripcion' => 'Silicona', 'cantidad' => 12]);
$wpdb->insert("{$p}ordenes_compra_items", ['orden_id' => $oc_old, 'producto_base_id' => 4456, 'descripcion' => 'Sin SKU', 'cantidad' => 1]);

/* ---------- Dobles de los módulos que calculan las alertas ---------- */
$GLOBALS['TEST_CONFIDENCE'] = [];
$GLOBALS['TEST_PAIRING_STOCK'] = null;
class Riverso_Inventory_Count_Module {
    public static function get_instance() { return new self(); }
    public function get_stock_status_for_product($id) {
        return ['estado_confianza' => $GLOBALS['TEST_CONFIDENCE'][$id] ?? 'poco_confiable'];
    }
}
class Riverso_Emparejamiento_Module {
    public static function get_instance() { return new self(); }
    public function list_stock_alerts() {
        global $wpdb;
        if ($GLOBALS['TEST_PAIRING_STOCK'] === null) return [];
        $p = $wpdb->prefix . 'riverso_';
        $members = $wpdb->get_results("SELECT m.producto_base_id, pb.canonical_sku AS sku FROM {$p}emparejamiento_miembros m
            INNER JOIN {$p}producto_base pb ON pb.id = m.producto_base_id WHERE m.emparejamiento_id = 1 ORDER BY m.id", ARRAY_A);
        return [['id' => 1, 'nombre' => 'Anclaje 3/8 x 3 zinc', 'stock_unidades' => $GLOBALS['TEST_PAIRING_STOCK'], 'stock_minimo' => 200,
            'alerta' => $GLOBALS['TEST_PAIRING_STOCK'] <= 200 ? 1 : 0, 'critico' => 0, 'detalle' => $members]];
    }
}

// Envase de los miembros de familia (caja de 500 → se pide en unidades y se muestran cajas).
$wpdb->query("CREATE TABLE {$p}envases (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    cantidad_unidades DECIMAL(12,4) NOT NULL DEFAULT 1, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");

/* ---------- Código bajo prueba ---------- */
require RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
require RIVERSO_POS_PLUGIN_DIR . 'purchases/notices/class-purchase-notice-service.php';
require RIVERSO_POS_PLUGIN_DIR . 'purchases/purchase_orders/class-purchase-order-service.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if (!$cond) $fails++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function throws($fn) {
    try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
    return '';
}
function group_of($tray, $supplier_id) {
    foreach ($tray['grupos'] as $g) if ($g['proveedor_id'] === $supplier_id) return $g;
    return null;
}
function need_of($tray, $key) {
    foreach ($tray['grupos'] as $g) foreach ($g['necesidades'] as $n) if ($n['clave'] === $key) return $n + ['_grupo' => $g['proveedor_id']];
    return null;
}
function line_of($order, $sku) {
    foreach ($order['lineas'] as $l) if ($l['sku'] === $sku) return $l;
    return null;
}
function row_of($order, $key) {
    foreach ($order['cotizacion']['filas'] as $r) if ($r['clave'] === $key) return $r;
    return null;
}
function as_line($need, array $extra = []) {
    return $extra + ['clave' => $need['clave'], 'aviso_ids' => array_column($need['avisos'], 'id'),
        'producto_base_id' => $need['producto_base_id'], 'cantidad' => $need['cantidad'], 'unidad' => $need['unidad']];
}

$avisos = Riverso_Purchase_Notice_Service::get_instance();
$svc = Riverso_Purchase_Order_Service::get_instance();

echo "\n== Migración ==\n";
check('ready() agrega columnas y la tabla de documentos', $svc->ready() === true);
$cols = $wpdb->get_col("SHOW COLUMNS FROM {$p}ordenes_compra_items");
check('líneas con origen, calce y aceptado', !array_diff(['codigo_proveedor', 'factor', 'origen', 'origen_item_id', 'emparejamiento_id', 'grupo_id', 'cotizacion_item_id', 'cantidad_aceptada', 'precio_aceptado', 'estado'], $cols));
check('pedido con revision_enviada_en', in_array('revision_enviada_en', $wpdb->get_col("SHOW COLUMNS FROM {$p}ordenes_compra"), true));
Riverso_POS_Activator::ensure_purchase_orders();
check('la migración es idempotente', (int) $wpdb->get_var("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'riverso_test' AND table_name = '{$p}orden_compra_documentos'") === 1);
$legacy = $wpdb->get_results("SELECT producto_base_id, origen FROM {$p}ordenes_compra_items WHERE orden_id = {$oc_old} ORDER BY id", ARRAY_A);
check('OC anterior: el ID de WooCommerce se traduce por el SKU de la cotización, o queda sin producto', (int) $legacy[0]['producto_base_id'] === $pb['silicona'] && $legacy[1]['producto_base_id'] === null && $legacy[0]['origen'] === 'legado', json_encode($legacy));
$old = $svc->get_order($oc_old);
check('  aparece en la lista como "sin pedido previo" y conserva su número', $old['sin_pedido_previo'] === true && $old['numero'] === 'N° OC-20260901-AbCd');
check('  no cuenta como borrador del proveedor ni como mercadería en camino', group_of($svc->tray(), 3) === null);
$wpdb->query("DELETE FROM {$p}ordenes_compra_items WHERE orden_id = {$oc_old}");
$wpdb->query("DELETE FROM {$p}ordenes_compra WHERE id = {$oc_old}");

echo "\n== Armar: una línea por producto, la cantidad no se suma ==\n";
$GLOBALS['TEST_USER'] = 1;
$a1 = $avisos->create(['producto_base_id' => $pb['tarugo'], 'cantidad' => '3', 'unidad' => 'caja']);
$GLOBALS['TEST_USER'] = 2;
$a2 = $avisos->create(['producto_base_id' => $pb['tarugo'], 'cantidad' => '5', 'unidad' => 'caja', 'sin_stock' => true]);
$a3 = $avisos->create(['producto_base_id' => $pb['perno'], 'cantidad' => '2', 'unidad' => 'caja']);
$a4 = $avisos->create(['producto_base_id' => $pb['golilla'], 'cantidad' => '2', 'unidad' => 'caja']);
$a5 = $avisos->create(['producto_base_id' => $pb['golilla'], 'cantidad' => '300', 'unidad' => 'unidad']);
$a6 = $avisos->create(['producto_base_id' => $pb['tuerca']]);
$a7 = $avisos->create(['texto' => 'hilo 1/4 métrico', 'nota' => 'no quedan']);
$tray = $svc->tray();
$mamut = group_of($tray, 1);
check('agrupa por proveedor', $mamut && $mamut['nombre'] === 'FIJACIONES MAMUT' && count($mamut['necesidades']) === 4, $mamut ? count($mamut['necesidades']) . ' necesidades' : 'sin grupo');
$n = need_of($tray, 'p' . $pb['tarugo']);
check('dos avisos del mismo producto = una necesidad', $n && count($n['avisos']) === 2);
check('  se propone la mayor, no la suma', $n['cantidad'] == 5 && $n['unidad'] === 'caja' && !$n['cantidad_confirmada'], json_encode([$n['cantidad'], $n['unidad']]));
check('  marca "no queda"', $n['sin_stock'] === true);
$n = need_of($tray, 'p' . $pb['golilla']);
check('unidades distintas sin factor: no se propone cantidad', $n['cantidad'] === null && $n['unidades_mezcladas'] === true);
check('aviso sin identificar queda en "Sin proveedor"', need_of($tray, 'a' . $a7['id'])['_grupo'] === 0);
$GLOBALS['TEST_USER'] = 3;
$avisos->update($a1['id'], 'cantidad', ['cantidad' => '4', 'unidad' => 'caja'], true);
$n = need_of($svc->tray(), 'p' . $pb['tarugo']);
check('la cantidad que confirmó compras manda aunque sea menor', $n['cantidad'] == 4 && $n['cantidad_confirmada'] === true);

echo "\n== Confirmar: borrador por proveedor, avisos ingresados ==\n";
check('"Sin proveedor" no se confirma', throws(function () use ($svc, $tray, $a7) { $svc->confirm(0, [as_line(need_of($tray, 'a' . $a7['id']))]); }) !== '');
check('cantidad sin unidad se rechaza en su línea', count($svc->confirm(1, [['producto_base_id' => $pb['perno'], 'aviso_ids' => [], 'cantidad' => '2', 'unidad' => '']])['rechazadas']) === 1);
$wpdb->query("DELETE FROM {$p}ordenes_compra");
$tray = $svc->tray();
$res = $svc->confirm(1, [
    as_line(need_of($tray, 'p' . $pb['tarugo'])),
    as_line(need_of($tray, 'p' . $pb['perno'])),
    as_line(need_of($tray, 'p' . $pb['golilla']), ['cantidad' => '2', 'unidad' => 'caja']),
    as_line(need_of($tray, 'p' . $pb['tuerca'])),
]);
$order = $res['pedido'];
check('crea el borrador con las cuatro líneas', $order['estado'] === 'borrador' && count($order['lineas']) === 4 && !$res['rechazadas'], json_encode($res['rechazadas']));
check('número corto y correlativo', $order['numero'] === 'N° ' . $order['id']);
$l = line_of($order, '752');
check('la línea toma código, unidad y factor del vínculo', $l['codigo_proveedor'] === '01TRN' && $l['cantidad'] == 4 && $l['unidad'] === 'caja' && $l['factor'] == 100 && $l['equivalencia'] == 400 && $l['origen'] === 'aviso');
check('  y muestra quién avisó', count($l['avisos']) === 2 && $l['avisos'][0]['creado_nombre'] === 'Pedro Bodega');
$n1 = $avisos->get($a1['id']);
check('los avisos quedan ingresados apuntando a la línea', $n1['estado'] === 'ingresado' && $n1['orden_compra_id'] === $order['id'] && $n1['orden_compra_item_id'] === $l['id'] && $n1['ingresado_nombre'] === 'Carla Compras');
check('  y salen de la bandeja', need_of($svc->tray(), 'p' . $pb['tarugo']) === null);
check('línea sin cantidad en el borrador', line_of($order, '902')['cantidad'] === null);
$again = $svc->confirm(1, [['clave' => 'x', 'aviso_ids' => [$a3['id']], 'producto_base_id' => $pb['perno']]]);
check('un aviso ya ingresado se rechaza diciendo quién fue', count($again['rechazadas']) === 1 && strpos($again['rechazadas'][0]['motivo'], 'Carla Compras') !== false, $again['rechazadas'][0]['motivo'] ?? '');
check('un borrador abierto por proveedor', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ordenes_compra WHERE proveedor_id = 1 AND estado = 'borrador'") === 1);

echo "\n== Borrador: editar, quitar, agregar ==\n";
$order = $svc->update_line(line_of($order, '902')['id'], ['cantidad' => '1', 'unidad' => 'caja', 'precio' => '1500,5']);
check('editar cantidad y precio', line_of($order, '902')['cantidad'] == 1 && line_of($order, '902')['precio'] == 1500.5);
$order = $svc->remove_line(line_of($order, '901')['id']);
check('quitar la línea devuelve sus avisos a abierto', count($order['lineas']) === 3 && $avisos->get($a4['id'])['estado'] === 'abierto' && $avisos->get($a4['id'])['orden_compra_item_id'] === 0);
check('  y la necesidad vuelve a la bandeja sin marca', ($n = need_of($svc->tray(), 'p' . $pb['golilla'])) && $n['marca'] === '');
check('no se agrega dos veces el mismo producto', strpos(throws(function () use ($svc, $order, $pb) { $svc->add_line($order['id'], ['producto_base_id' => $pb['tarugo']]); }), 'Ya está') !== false);
$order = $svc->add_line($order['id'], ['descripcion' => 'Hilo 1/4 métrico (ver foto)', 'cantidad' => '10', 'unidad' => 'unidad']);
check('línea a mano sin producto', count($order['lineas']) === 4 && end($order['lineas'])['identificado'] === false && end($order['lineas'])['origen'] === 'manual');
$hilo_id = end($order['lineas'])['id'];

echo "\n== Emparejado: una necesidad, cualquiera de sus proveedores ==\n";
$GLOBALS['TEST_USER'] = 1;
$e1 = $avisos->create(['producto_base_id' => $pb['ancla_a'], 'cantidad' => '4', 'unidad' => 'caja']);
check('el aviso propone el proveedor de su producto', $e1['proveedor_nombre'] === 'STEELFIX SPA');
$GLOBALS['TEST_USER'] = 3;
$tray = $svc->tray();
$n = need_of($tray, 'e' . $emp);
check('aparece una vez, como necesidad del emparejamiento', $n && $n['nivel'] === 'emparejamiento' && $n['_grupo'] === 2);
check('  con los proveedores de todos sus miembros', count($n['proveedores']) === 2 && array_column($n['proveedores'], 'proveedor_id') == [2, 1], json_encode(array_column($n['proveedores'], 'nombre')));
$res = $svc->confirm(1, [as_line($n)]);
$l = line_of($res['pedido'], '28691');
check('pedirlo a Mamut fija el producto, código y factor de ese vínculo', $l && $l['codigo_proveedor'] === '14RLBC' && $l['emparejamiento_id'] === $emp && $l['nivel'] === 'emparejamiento', json_encode($res['rechazadas']));
$GLOBALS['TEST_USER'] = 2;
$e2 = $avisos->create(['producto_base_id' => $pb['ancla_b']]);
$GLOBALS['TEST_USER'] = 3;
$n = need_of($svc->tray(), 'e' . $emp);
check('otro aviso del grupo: se muestra en el pedido donde ya está', $n && $n['en_pedido']['orden_id'] === $order['id'] && $n['_grupo'] === 1);
$res = $svc->confirm(2, [as_line($n)]);
check('  no se ofrece para otro proveedor', count($res['rechazadas']) === 1 && strpos($res['rechazadas'][0]['motivo'], 'Ya está en el pedido') !== false, $res['rechazadas'][0]['motivo'] ?? '');
$res = $svc->confirm(1, [as_line($n)]);
check('  en el mismo borrador se suma a la línea que ya existe', !$res['rechazadas'] && count(line_of($res['pedido'], '28691')['avisos']) === 2);
$moved = $svc->move_line($l['id'], 2);
$l2 = line_of($moved, '28690');
check('moverla la saca del borrador anterior', $moved['proveedor_id'] === 2 && $l2 && $l2['codigo_proveedor'] === '000-139' && line_of($svc->get_order($order['id']), '28691') === null);
check('  y sus avisos siguen ingresados, ahora en el otro pedido', $avisos->get($e1['id'])['orden_compra_id'] === $moved['id'] && $avisos->get($e1['id'])['estado'] === 'ingresado');
$steel_order = $moved['id'];

echo "\n== Enviar ==\n";
$order = $svc->get_order($order['id']);
check('sin cantidad no se envía', strpos(throws(function () use ($svc, $order, $pb, $wpdb, $p) {
    $wpdb->query("UPDATE {$p}ordenes_compra_items SET cantidad = 0 WHERE orden_id = {$order['id']} AND producto_base_id = {$pb['tuerca']}");
    $svc->send($order['id']);
}), 'Falta la cantidad') !== false);
$svc->update_line(line_of($order, '902')['id'], ['cantidad' => '1', 'unidad' => 'caja']);
$sent = $svc->send($order['id']);
check('pasa a enviado con fecha', $sent['estado'] === 'enviada' && $sent['enviado_en'] !== '');
check('el texto lleva número, código, cantidad y unidad', strpos($sent['texto'], 'Pedido N° ' . $order['id']) === 0 && strpos($sent['texto'], '01TRN — TARUGO DE NYLON M-5 X 25 — 4 cajas') !== false && strpos($sent['texto'], 'orden de compra') !== false, str_replace("\n", ' | ', $sent['texto']));
check('enviado: las líneas pedidas no se reescriben', throws(function () use ($svc, $sent) { $svc->update_line($sent['lineas'][0]['id'], ['cantidad' => '9', 'unidad' => 'caja']); }) !== '');
check('  ni se mueven a otro proveedor', strpos(throws(function () use ($svc, $sent) { $svc->move_line($sent['lineas'][0]['id'], 2); }), 'ya se envió') !== false);
check('no hay cotización: ofrece vincular', $sent['cotizacion']['vinculada'] === false && $sent['recepcion']['esperando_documento'] === true);

echo "\n== Cotización: no calza con el pedido ==\n";
$wpdb->insert("{$p}cotizaciones_recibidas", ['proveedor_id' => 1, 'numero_documento' => 'COT-88', 'fecha_documento' => '2026-10-11', 'estado' => 'under_review']);
$q1 = (int) $wpdb->insert_id;
$wpdb->query("UPDATE {$p}cotizaciones_recibidas SET version_group_id = {$q1}, version_n = 1 WHERE id = {$q1}");
$qi = function ($quote, $line, $code, $desc, $qty, $unit, $cost, $prev, $decision, $sku = null) use ($wpdb, $p) {
    $wpdb->insert("{$p}cotizacion_items", ['cotizacion_id' => $quote, 'linea' => $line, 'codigo_proveedor' => $code, 'descripcion' => $desc,
        'cantidad' => $qty, 'unidad' => $unit, 'costo_neto' => $cost, 'costo_anterior' => $prev,
        'diferencia_costo' => $prev === null ? null : $cost - $prev, 'decision_status' => $decision, 'sku_match' => $sku, 'producto_id' => 987654]);
    return (int) $wpdb->insert_id;
};
$i_tarugo = $qi($q1, 1, '01-TRN', 'Tarugo nylon M5x25 cj 100', 4, 'CJ', 2500, 2500, 'accepted', '752');   // igual (código con guion)
$i_perno = $qi($q1, 2, '02PH', 'Perno hex 1/2x2', 2, 'CJ', 9900, 9000, 'claim', '900');                      // precio sube
$i_tuerca = $qi($q1, 3, 'XX-OTRO', 'Tuerca hex 1/2', 300, 'UN', 30, 30, 'accepted', '902');                  // calza por SKU, otra cantidad
$i_extra = $qi($q1, 4, '09ZZ', 'Brocha 2 pulgadas', 12, 'UN', 800, null, 'accepted', null);                  // no la pedimos
$wpdb->insert("{$p}cotizaciones_recibidas", ['proveedor_id' => 2, 'numero_documento' => 'OTRA', 'estado' => 'under_review']);
$q_other = (int) $wpdb->insert_id;
check('ofrece las cotizaciones del proveedor sin pedido', array_column($svc->get_order($order['id'])['cotizacion']['candidatas'], 'id') === [$q1]);
check('una cotización de otro proveedor no se vincula', strpos(throws(function () use ($svc, $order, $q_other) { $svc->link_quote($order['id'], $q_other); }), 'otro proveedor') !== false);
$o = $svc->link_quote($order['id'], $q1);
check('vincular pasa a "en cotización"', $o['estado'] === 'en_cotizacion' && $o['cotizacion']['vinculada'] && $o['cotizacion']['numero'] === 'COT-88');
$tarugo = line_of($o, '752'); $perno = line_of($o, '900'); $tuerca = line_of($o, '902');
$r = row_of($o, 'l' . $tarugo['id']);
check('igual: calza por código sin guion y no pide decisión', $r['tipo'] === 'igual' && $r['cot']['id'] === $i_tarugo && $r['decidida'] === true);
$r = row_of($o, 'l' . $perno['id']);
check('precio: el alza queda para reclamar al proveedor', $r['marcas'] === ['precio'] && $r['decision'] === 'claim' && $r['cot']['sube'] === true && $r['decidida'] === true);
$r = row_of($o, 'l' . $tuerca['id']);
check('cantidad: calza por SKU y no se da por igual aunque la cotización la tenga aceptada', $r['cot']['id'] === $i_tuerca && $r['marcas'] === ['cantidad'] && $r['decidida'] === false, json_encode($r['marcas']));
$r = row_of($o, 'l' . $hilo_id);
check('solo pedido: no vino en la cotización', $r['tipo'] === 'solo_pedido' && $r['cot'] === null && $r['decidida'] === false);
$r = row_of($o, 'c' . $i_extra);
check('solo cotización: vino sin pedirla y espera decisión', $r['tipo'] === 'solo_cotizacion' && $r['linea'] === null && $r['decidida'] === false);
check('el ID de WooCommerce de la cotización no llega a producto_base_id', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ordenes_compra_items WHERE producto_base_id = 987654") === 0);
check('cuenta las diferencias sin decidir y lo que espera al proveedor', $o['cotizacion']['pendientes'] === 3 && $o['cotizacion']['esperando'] === 1 && $o['cotizacion']['puede_aceptar'] === false, json_encode([$o['cotizacion']['pendientes'], $o['cotizacion']['esperando']]));
check('no se acepta con diferencias pendientes', strpos(throws(function () use ($svc, $order) { $svc->accept($order['id']); }), 'sin decidir') !== false);

echo "\n== Decidir cada diferencia ==\n";
check('no se reclama un precio que no subió', throws(function () use ($svc, $order, $tarugo) { $svc->decide($order['id'], ['linea_id' => $tarugo['id'], 'decision' => 'claim']); }) !== '');
$o = $svc->decide($order['id'], ['linea_id' => $tuerca['id'], 'decision' => 'fix_qty']);
check('cantidad: pedir que la corrijan', row_of($o, 'l' . $tuerca['id'])['decision'] === 'fix_qty' && $wpdb->get_var("SELECT decision_status FROM {$p}cotizacion_items WHERE id = {$i_tuerca}") === 'fix_qty');
$o = $svc->decide($order['id'], ['linea_id' => $hilo_id, 'decision' => 'add']);
$r = row_of($o, 'l' . $hilo_id);
check('solo pedido: pedir que la agreguen crea la línea "add" en la cotización', $r['decision'] === 'add' && $r['cot'] !== null && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}cotizacion_items WHERE cotizacion_id = {$q1} AND decision_status = 'add'") === 1);
$o = $svc->decide($order['id'], ['cot_item_id' => $i_extra, 'decision' => 'remove']);
check('solo cotización: pedir que la quiten', row_of($o, 'c' . $i_extra)['decidida'] === true);
check('todo decidido, pero hay líneas esperando al proveedor', $o['cotizacion']['pendientes'] === 0 && $o['cotizacion']['esperando'] === 3 && $o['cotizacion']['revision_por_mandar'] === true && $o['cotizacion']['puede_aceptar'] === false, json_encode([$o['cotizacion']['pendientes'], $o['cotizacion']['esperando']]));
check('con una línea en claim el pedido no queda aceptado', strpos(throws(function () use ($svc, $order) { $svc->accept($order['id']); }), 'esperando respuesta') !== false);
$rq = $svc->requested_quantities($q1);
check('el correo de revisión sabe qué cantidad se pidió', isset($rq[$i_tuerca]) && $rq[$i_tuerca]['cantidad'] == 1 && $rq[$i_tuerca]['unidad'] === 'cajas');
$o = $svc->mark_revision_sent($order['id']);
check('marcar la revisión enviada pasa a "en revisión"', $o['estado'] === 'en_revision' && $o['revision_enviada_en'] !== '' && $o['cotizacion']['revision_por_mandar'] === false);

echo "\n== Llega la versión nueva de la cotización ==\n";
$wpdb->insert("{$p}cotizaciones_recibidas", ['proveedor_id' => 1, 'numero_documento' => 'COT-88-B', 'fecha_documento' => '2026-10-13', 'estado' => 'under_review', 'version_group_id' => $q1, 'version_n' => 2]);
$q2 = (int) $wpdb->insert_id;
$j_tarugo = $qi($q2, 1, '01TRN', 'Tarugo nylon M5x25 cj 100', 4, 'CJ', 2500, 2500, 'accepted', '752');
$j_perno = $qi($q2, 2, '02PH', 'Perno hex 1/2x2', 2, 'CJ', 9000, 9000, 'accepted', '900');     // volvió al precio anterior
$j_tuerca = $qi($q2, 3, '04TH', 'Tuerca hex 1/2 cj 100', 1, 'CJ', 3000, 3000, 'accepted', '902'); // cantidad corregida
$j_extra = $qi($q2, 4, '09ZZ', 'Brocha 2 pulgadas', 12, 'UN', 800, null, 'accepted', null);       // sigue viniendo
$j_hilo = $qi($q2, 5, 'H14', 'Hilo 1/4 metrico', 10, 'UN', 450, null, 'pending', '5000');         // la agregaron
$o = $svc->get_order($order['id']);
check('el pedido sigue a la versión final y vuelve a "en cotización"', $o['cotizacion']['id'] === $q2 && $o['cotizacion']['versiones'] === 2 && $o['estado'] === 'en_cotizacion' && $o['revision_enviada_en'] === '');
check('lo que pedimos quitar y sigue viniendo se marca de nuevo como quitar', $wpdb->get_var("SELECT decision_status FROM {$p}cotizacion_items WHERE id = {$j_extra}") === 'remove' && row_of($o, 'c' . $j_extra)['decidida'] === true);
check('el diff se rehace contra la versión nueva', row_of($o, 'l' . $perno['id'])['tipo'] === 'igual' && row_of($o, 'l' . $tuerca['id'])['tipo'] === 'igual' && row_of($o, 'l' . $tuerca['id'])['cot']['id'] === $j_tuerca);
check('la línea agregada llega sin calzar', row_of($o, 'l' . $hilo_id)['tipo'] === 'solo_pedido' && row_of($o, 'c' . $j_hilo) !== null);
$o = $svc->set_match($hilo_id, $j_hilo);
$hilo = null; foreach ($o['lineas'] as $ln) if ($ln['id'] === $hilo_id) $hilo = $ln;
check('calce a mano: la línea sin producto toma el de la cotización', $hilo['identificado'] === true && $hilo['sku'] === '5000' && $hilo['codigo_proveedor'] === 'H14');
check('  y queda pendiente de decisión', row_of($o, 'l' . $hilo_id)['decision'] === 'pending' && $o['cotizacion']['pendientes'] === 1);
$o = $svc->decide($order['id'], ['linea_id' => $hilo_id, 'decision' => 'accepted']);
check('todo decidido y nada esperando: se puede aceptar', $o['cotizacion']['puede_aceptar'] === true && $o['cotizacion']['bloqueo'] === '');

echo "\n== Aceptar ==\n";
$o = $svc->accept($order['id']);
check('el pedido queda aceptado y la cotización aprobada', $o['estado'] === 'aceptada' && $wpdb->get_var("SELECT estado FROM {$p}cotizaciones_recibidas WHERE id = {$q2}") === 'approved');
$l = line_of($o, '900');
check('lo aceptado es la cantidad y el costo cotizados', $l['estado'] === 'aceptada' && $l['cantidad_aceptada'] == 2 && $l['precio_aceptado'] == 9000);
check('total del pedido', (float) $wpdb->get_var("SELECT total FROM {$p}ordenes_compra WHERE id = {$order['id']}") == 4 * 2500 + 2 * 9000 + 3000 + 10 * 450);
check('aceptado: ya no se decide', throws(function () use ($svc, $order, $perno) { $svc->decide($order['id'], ['linea_id' => $perno['id'], 'decision' => 'remove']); }) !== '');
check('order_for_quote encuentra el pedido desde cualquier versión', ($svc->order_for_quote($q1)['id'] ?? 0) == $order['id']);
check('"convertir a llegada esperada" no crea una segunda OC para esa cotización', strpos(throws(function () use ($svc, $q2) { $svc->create_from_quote($q2); }), 'ya está en el pedido') !== false);

echo "\n== Cotización que llega sin pedido previo ==\n";
$wpdb->insert("{$p}cotizaciones_recibidas", ['proveedor_id' => 3, 'numero_documento' => 'W-77', 'estado' => 'approved']);
$q3 = (int) $wpdb->insert_id;
$qi($q3, 1, '0617401100', 'Broca HSS 11', 6, 'UN', 4200, 4200, 'accepted', '22306');
$qi($q3, 2, 'NO-EXISTE', 'Producto sin calce', 1, 'UN', 100, null, 'accepted', null);
$qi($q3, 3, 'SIL-280', 'Silicona', 24, 'UN', 1900, 1900, 'remove', '5000');
$direct = $svc->create_from_quote($q3);
check('nace un pedido aceptado, marcado "sin pedido previo"', $direct['estado'] === 'aceptada' && $direct['sin_pedido_previo'] === true && count($direct['lineas']) === 2 && $direct['numero'] === 'N° ' . $direct['id']);
$l = line_of($direct, '22306');
check('el producto sale del SKU, no del ID de WooCommerce', $l && $l['producto_base_id'] === $pb['broca'] && $l['estado'] === 'aceptada' && $l['cantidad_aceptada'] == 6 && $l['precio_aceptado'] == 4200 && $l['origen'] === 'cotizacion');
check('la línea sin SKU queda sin producto', $direct['lineas'][1]['identificado'] === false && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ordenes_compra_items WHERE producto_base_id = 987654") === 0);
$svc->cancel($direct['id']);

echo "\n== Lo que el proveedor no cotiza vuelve a la bandeja ==\n";
$steel = $svc->get_order($steel_order);
$svc->update_line($steel['lineas'][0]['id'], ['cantidad' => '4', 'unidad' => 'caja']);
$steel = $svc->send($steel_order);
$steel = $svc->drop_line($steel['lineas'][0]['id']);
check('la línea queda "no se compra", con constancia', $steel['lineas'][0]['estado'] === 'no_comprada' && count($steel['lineas']) === 1);
check('sus avisos vuelven a abierto', $avisos->get($e1['id'])['estado'] === 'abierto' && $avisos->get($e2['id'])['estado'] === 'abierto');
$n = need_of($svc->tray(), 'e' . $emp);
check('  con la marca del proveedor que no lo cotizó', $n && $n['marca'] === 'No lo cotizó STEELFIX SPA' && $n['en_pedido'] === null, $n ? $n['marca'] : 'sin necesidad');
$res = $svc->confirm(1, [as_line($n, ['cantidad' => '4', 'unidad' => 'caja'])]);
check('y se puede pedir al otro proveedor del emparejado', !$res['rechazadas'] && line_of($res['pedido'], '28691') !== null && $res['pedido']['id'] !== $order['id']);
$mamut2 = $res['pedido']['id'];

echo "\n== Recepción: documento ↔ pedido ==\n";
$wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => '5001', 'proveedor_id' => 1, 'fecha_emision' => '2026-10-15', 'monto_total' => 35000]);
$f1 = (int) $wpdb->insert_id;
foreach ([['01TRN', 'Tarugo', 4], ['02PH', 'Perno', 2], ['04TH', 'Tuerca', 1]] as $it) {
    $wpdb->insert("{$p}factura_items", ['factura_id' => $f1, 'codigo_proveedor' => $it[0], 'nombre' => $it[1], 'cantidad' => $it[2], 'unidad' => 'CJ']);
}
$o = $svc->get_order($order['id']);
check('sin vínculo, propone el documento del proveedor que trae sus códigos', $o['recepcion']['esperando_documento'] === true && ($o['recepcion']['propuestos'][0]['id'] ?? 0) === $f1 && $o['recepcion']['propuestos'][0]['calzan'] === 3);
$wpdb->insert("{$p}factura_referencias", ['factura_id' => $f1, 'tipo_doc_ref' => 801, 'folio_ref' => '000' . $order['id']]);
$o = $svc->get_order($order['id']);
check('la factura que cita el número del pedido se vincula sola', count($o['recepcion']['documentos']) === 1 && $o['recepcion']['documentos'][0]['origen'] === 'referencia' && $o['recepcion']['esperando_documento'] === false);
check('  y sigue aceptado: todavía no se recibe nada', $o['estado'] === 'aceptada');
$wpdb->insert("{$p}recepciones", ['factura_id' => $f1, 'estado' => 'parcial']);
$rx = (int) $wpdb->insert_id;
$wpdb->insert("{$p}recepcion_lineas", ['recepcion_id' => $rx, 'producto_base_id' => $pb['tarugo'], 'cantidad_recibida' => 400]);
$wpdb->insert("{$p}recepcion_lineas", ['recepcion_id' => $rx, 'producto_base_id' => $pb['perno'], 'cantidad_recibida' => 50]);
$svc->recalc_for_document($f1);
$o = $svc->get_order($order['id']);
check('lo recibido se recalcula desde Recepción, en la unidad de la línea', line_of($o, '752')['cantidad_recibida'] == 4 && line_of($o, '752')['estado'] === 'cerrada' && line_of($o, '900')['cantidad_recibida'] == 1 && line_of($o, '900')['estado'] === 'aceptada', json_encode([line_of($o, '752')['cantidad_recibida'], line_of($o, '900')['cantidad_recibida']]));
check('pasa a recepción parcial', $o['estado'] === 'recibida_parcial');
check('recibido en parte: no se cancela', strpos(throws(function () use ($svc, $order) { $svc->cancel($order['id']); }), 'ya no se puede cancelar') !== false);
// Recepción cierra el documento con faltante del perno; la tuerca llega completa.
$wpdb->query("UPDATE {$p}recepciones SET estado = 'recibida' WHERE id = {$rx}");
$wpdb->insert("{$p}recepcion_lineas", ['recepcion_id' => $rx, 'producto_base_id' => $pb['tuerca'], 'cantidad_recibida' => 100]);
$svc->recalc_for_document($f1);
$o = $svc->get_order($order['id']);
$fila = null; foreach ($o['recepcion']['filas'] as $f) if ($f['linea_id'] === line_of($o, '900')['id']) $fila = $f;
check('documento cerrado con faltante: la línea se cierra y muestra el resto', $fila['estado'] === 'cerrada' && $fila['estado_label'] === 'Cerrada con faltante' && $fila['resto'] == 1 && $fila['pedido'] == 2 && $fila['aceptado'] == 2 && $fila['recibido'] == 1);
check('la línea que no vino en ningún documento sigue esperando', line_of($o, '5000')['estado'] === 'aceptada' && $o['estado'] === 'recibida_parcial');

echo "\n== Pedir el resto ==\n";
$rest = $svc->request_rest(line_of($o, '900')['id'], 1);
$rl = line_of($rest, '900');
check('deja una línea nueva en el borrador elegido, apuntando a la original', $rest['id'] === $mamut2 && $rl['origen'] === 'resto' && $rl['origen_item_id'] === line_of($o, '900')['id'] && $rl['cantidad'] == 1 && $rl['codigo_proveedor'] === '02PH');
check('el resto no se pide dos veces', strpos(throws(function () use ($svc, $o) { $svc->request_rest(line_of($o, '900')['id'], 1); }), 'ya está pedido') !== false);
$o = $svc->get_order($order['id']);
$fila = null; foreach ($o['recepcion']['filas'] as $f) if ($f['linea_id'] === line_of($o, '900')['id']) $fila = $f;
check('la ficha sabe que el resto ya se pidió', $fila['resto_pedido'] === true);
// Segunda factura con lo que faltaba, vinculada a mano.
$wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => '5002', 'proveedor_id' => 1, 'fecha_emision' => '2026-10-18']);
$f2 = (int) $wpdb->insert_id;
$wpdb->insert("{$p}recepciones", ['factura_id' => $f2, 'estado' => 'recibida']);
$wpdb->insert("{$p}recepcion_lineas", ['recepcion_id' => $wpdb->insert_id, 'producto_base_id' => $pb['silicona'], 'cantidad_recibida' => 10]);
check('un documento de otro proveedor no se vincula', throws(function () use ($svc, $order, $wpdb, $p) {
    $wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => '9', 'proveedor_id' => 3, 'fecha_emision' => '2026-10-18']);
    $svc->link_document($order['id'], (int) $wpdb->insert_id);
}) !== '');
$o = $svc->link_document($order['id'], $f2, 'propuesto');
check('con todo recibido o cerrado el pedido queda recibido', $o['estado'] === 'recibida' && line_of($o, '5000')['cantidad_recibida'] == 10, $o['estado']);
$o = $svc->unlink_document($order['id'], $f2);
check('desvincular el documento devuelve la línea a esperar', $o['estado'] === 'recibida_parcial' && line_of($o, '5000')['estado'] === 'aceptada' && line_of($o, '5000')['cantidad_recibida'] == 0);
$svc->link_document($order['id'], $f2);

echo "\n== Alertas de bajo inventario ==\n";
$wpdb->insert("{$p}producto_stock_config", ['producto_base_id' => $pb['broca'], 'stock_minimo' => 10]);
$wpdb->insert("{$p}producto_stock_config", ['producto_base_id' => $pb['disco'], 'stock_minimo' => 50, 'stock_critico' => 5]);
$wpdb->insert("{$p}producto_stock_config", ['producto_base_id' => $pb['silicona'], 'stock_minimo' => 5]);
$wpdb->insert("{$p}producto_ubicacion", ['product_id' => $pb['broca'], 'cantidad' => 3]);
$wpdb->insert("{$p}producto_ubicacion", ['product_id' => $pb['disco'], 'cantidad' => 4]);
$wpdb->insert("{$p}producto_ubicacion", ['product_id' => $pb['silicona'], 'cantidad' => 40]);
$GLOBALS['TEST_CONFIDENCE'] = [$pb['disco'] => 'confiable'];
// Última compra del disco a Wurth: 6 cajas, por factura.
$wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => '700', 'proveedor_id' => 3, 'fecha_emision' => '2026-09-01']);
$wpdb->insert("{$p}factura_items", ['factura_id' => $wpdb->insert_id, 'codigo_proveedor' => 'DC-412', 'nombre' => 'Disco', 'cantidad' => 6, 'unidad' => 'CJ']);
$tray = $svc->tray();
$n = need_of($tray, 'p' . $pb['disco']);
check('alerta confiable: entra a la bandeja con la última cantidad comprada', $n && $n['origen'] === 'alerta' && !$n['avisos'] && $n['alerta']['dudosa'] === false && $n['alerta']['critico'] === true && $n['cantidad'] == 6 && $n['unidad'] === 'caja' && $n['cantidad_fuente'] === 'ultima_compra' && $n['_grupo'] === 3, json_encode($n ? [$n['cantidad'], $n['unidad'], $n['alerta']] : null));
$n = need_of($tray, 'p' . $pb['broca']);
check('alerta dudosa: se muestra sin cantidad', $n && $n['alerta']['dudosa'] === true && $n['cantidad'] === null);
check('sobre el mínimo no hay alerta', need_of($tray, 'p' . $pb['silicona']) === null);
check('el emparejado con pedido abierto no se ofrece como alerta', ($GLOBALS['TEST_PAIRING_STOCK'] = 120) && need_of($svc->tray(), 'e' . $emp) === null);
$res = $svc->confirm(3, [as_line(need_of($tray, 'p' . $pb['disco']))]);
$l = line_of($res['pedido'], '6000');
check('una alerta confirmada es una línea de origen alerta', $l && $l['origen'] === 'alerta' && $l['cantidad'] == 6 && !$res['rechazadas'], json_encode($res['rechazadas']));
check('en camino: la alerta deja de ofrecerse', need_of($svc->tray(), 'p' . $pb['disco']) === null);
$svc->cancel($res['pedido']['id']);
check('si el pedido se cancela, la alerta vuelve', need_of($svc->tray(), 'p' . $pb['disco']) !== null);
$svc->cancel($mamut2);
$n = need_of($svc->tray(), 'e' . $emp);
check('emparejamiento bajo su mínimo, sin pedido: alerta en el nivel del grupo', $n && $n['nivel'] === 'emparejamiento' && $n['alerta']['nivel'] === 'emparejamiento' && $n['alerta']['stock'] == 120 && count($n['avisos']) === 2);

echo "\n== Cancelar ==\n";
check('al cancelar un borrador sus avisos vuelven a abierto', $avisos->get($e1['id'])['estado'] === 'abierto' && $svc->get_order($mamut2)['estado'] === 'cancelada');
check('un pedido cancelado no se reabre con decide', throws(function () use ($svc, $mamut2) { $svc->decide($mamut2, ['linea_id' => 1, 'decision' => 'accepted']); }) !== '');
$list = $svc->list_orders();
check('la lista trae los pedidos con su estado y cuenta por estado', count($list['pedidos']) === 5 && $list['counts']['cancelada'] === 3 && $list['counts']['recibida'] === 1, json_encode($list['counts']));
check('filtro por estado y búsqueda por proveedor', count($svc->list_orders(['estado' => 'recibida'])['pedidos']) === 1 && count($svc->list_orders(['buscar' => 'steelfix'])['pedidos']) === 1);

echo "\n== Caja de familia: se pide en unidades ==\n";
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '272', 'nombre_canonico' => 'CRS 6 x 2 Zinc.']);
$crs = (int) $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '247610', 'nombre_canonico' => 'CRS 6 x 2 (Caja 500)']);
$crs_box = (int) $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_groups", ['nombre' => 'CRS 6x2', 'unit_producto_base_id' => $crs, 'activo' => 1]);
$crs_g = (int) $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $crs_g, 'producto_base_id' => $crs]);
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $crs_g, 'producto_base_id' => $crs_box]);
$wpdb->insert("{$p}envases", ['producto_base_id' => $crs_box, 'cantidad_unidades' => 500]);
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $crs_box, 'proveedor_id' => 3, 'codigo_proveedor' => 'B23TAD', 'unidad_compra' => 'CJ']);
$crs_pp = (int) $wpdb->insert_id;
$notices = Riverso_Purchase_Notice_Service::get_instance();
check('Avisos sabe que la caja trae 500 y el unitario no es caja', $notices->pack_units($crs_box) == 500 && $notices->pack_units($crs) === null);
$draft = $svc->create_draft(3);
$draft_id = is_array($draft) ? (int) $draft['id'] : (int) $draft;
$o = $svc->add_line($draft_id, ['producto_base_id' => $crs_box, 'pp_id' => $crs_pp]);
$l = line_of($o, '247610');
check('sin cantidad, la unidad por defecto es unidades (aunque el código diga CJ)', $l && $l['unidad'] === 'unidad' && $l['envase'] == 500, json_encode($l ? [$l['unidad'], $l['envase']] : null));
$o = $svc->update_line($l['id'], ['cantidad' => '20000', 'unidad' => 'unidad']);
$l = line_of($o, '247610');
check('20.000 unidades = 40 cajas de 500', $l['cantidad'] == 20000 && $l['cajas'] == 40 && $l['equivalencia'] === null, json_encode([$l['cantidad'], $l['cajas']]));
$o = $svc->update_line($l['id'], ['cantidad' => '40', 'unidad' => 'caja']);
$l = line_of($o, '247610');
check('pedida en cajas: 40 cajas = 20.000 unidades', $l['cajas'] === null && $l['equivalencia'] == 20000);
$o = $svc->update_line($l['id'], ['cantidad' => '20000', 'unidad' => 'unidad']);
$svc->send($draft_id);
$wpdb->insert("{$p}facturas", ['tipo_dte' => 33, 'folio' => '750061', 'proveedor_id' => 3, 'fecha_emision' => '2026-10-20']);
$crs_f = (int) $wpdb->insert_id;
$wpdb->insert("{$p}recepciones", ['factura_id' => $crs_f, 'estado' => 'recibida']);
// Recepción ingresa cajas del producto vinculado (20.000 U facturadas = 40 cajas).
$wpdb->insert("{$p}recepcion_lineas", ['recepcion_id' => $wpdb->insert_id, 'producto_base_id' => $crs_box, 'cantidad_recibida' => 40]);
$o = $svc->link_document($draft_id, $crs_f);
$l = line_of($o, '247610');
check('40 cajas recibidas completan las 20.000 unidades pedidas', $l['cantidad_recibida'] == 20000 && $o['estado'] === 'recibida', json_encode([$l['cantidad_recibida'], $l['estado'], $o['estado']]));

echo "\nconsultas ejecutadas: {$wpdb->queries}\n";
echo $fails ? "RESULTADO: {$fails} FALLAS\n" : "RESULTADO: todo ok\n";
exit($fails ? 1 : 0);
