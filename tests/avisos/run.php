<?php
/**
 * Prueba de integración del servicio de avisos contra MySQL local.
 * Usa el snapshot local (CodigosBarra/*.csv, data/sku_mapping.json) como catálogo.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/avisos/run.php
 */
require __DIR__ . '/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

/* ---------- Esquema base (definiciones del activador; deleted_at lo agrega una fase posterior) ---------- */
$wpdb->query("CREATE TABLE {$p}producto_base (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, woocommerce_product_id BIGINT UNSIGNED DEFAULT NULL,
    woocommerce_variation_id BIGINT UNSIGNED DEFAULT NULL, canonical_sku VARCHAR(100) DEFAULT NULL,
    nombre_canonico VARCHAR(255) DEFAULT NULL, unidad_base VARCHAR(20) DEFAULT 'unidad',
    estado VARCHAR(20) DEFAULT 'activo', deleted_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id), UNIQUE KEY ux_canonical_sku (canonical_sku)) $cc");
$wpdb->query("CREATE TABLE {$p}proveedores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, rut VARCHAR(20) NOT NULL, nombre VARCHAR(255) NOT NULL,
    activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY rut (rut)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_proveedor (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    grupo_id BIGINT UNSIGNED DEFAULT NULL, proveedor_id BIGINT UNSIGNED NOT NULL,
    codigo_proveedor VARCHAR(100) NOT NULL, codigo_barras_proveedor VARCHAR(50) DEFAULT NULL,
    nombre_proveedor VARCHAR(255) DEFAULT NULL, unidad_compra VARCHAR(20) DEFAULT NULL,
    factor_conversion DECIMAL(10,4) DEFAULT 1.0000, es_preferido TINYINT(1) DEFAULT 0, activo TINYINT(1) DEFAULT 1,
    PRIMARY KEY (id), UNIQUE KEY ux_proveedor_codigo (proveedor_id, codigo_proveedor),
    KEY idx_producto_base (producto_base_id)) $cc");
$wpdb->query("CREATE TABLE {$p}codigo_barra (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo VARCHAR(50) NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'ean13',
    producto_base_id BIGINT UNSIGNED DEFAULT NULL, proveedor_id BIGINT UNSIGNED DEFAULT NULL,
    cantidad DECIMAL(10,3) NOT NULL DEFAULT 1, unidad_medida VARCHAR(20) NOT NULL DEFAULT 'unidad',
    envase_id BIGINT UNSIGNED DEFAULT NULL, factor_a_unidad_base DECIMAL(10,3) NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1, estado VARCHAR(20) NOT NULL DEFAULT 'verificado',
    motivo_estado VARCHAR(255) DEFAULT NULL, origen_datos VARCHAR(50) NOT NULL DEFAULT 'manual',
    sku_local VARCHAR(100) DEFAULT NULL, pending_sku VARCHAR(100) DEFAULT NULL, legacy_ref LONGTEXT DEFAULT NULL,
    conflicto TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (id), KEY idx_codigo (codigo)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}barcodes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id BIGINT, variation_id BIGINT,
    sku VARCHAR(100), barcode VARCHAR(50), is_active TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}tienda_local_barcodes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, sku VARCHAR(100),
    barcode VARCHAR(50), barcode_norm VARCHAR(50), PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}tienda_local_productos (sku VARCHAR(100) NOT NULL, nombre VARCHAR(255), PRIMARY KEY (sku)) $cc");
$wpdb->query("CREATE TABLE {$p}codigos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, supplier_product_id BIGINT, product_base_id BIGINT,
    proveedor_id BIGINT, codigo_proveedor VARCHAR(100), activo TINYINT(1) DEFAULT 1, factor_conversion DECIMAL(10,4) DEFAULT 1,
    unidad_medida VARCHAR(20), PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}ean_aliases (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, payload VARCHAR(50), producto_base_id BIGINT,
    activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE wp_users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, display_name VARCHAR(250), PRIMARY KEY (ID)) $cc");
// Tabla de OC como la crea hoy el activador: sin fecha_emision.
$wpdb->query("CREATE TABLE {$p}ordenes_compra (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, numero VARCHAR(50) DEFAULT NULL,
    proveedor_id BIGINT UNSIGNED NOT NULL, estado VARCHAR(30) NOT NULL DEFAULT 'borrador', cotizacion_id BIGINT UNSIGNED DEFAULT NULL,
    total DECIMAL(12,2) DEFAULT 0, notas TEXT DEFAULT NULL, creado_por BIGINT UNSIGNED DEFAULT NULL, PRIMARY KEY (id)) $cc");

/* ---------- Datos: snapshot local de abril + vínculos de proveedor de ejemplo ---------- */
foreach ([[1, 'Pedro Bodega'], [2, 'Ana Mesón'], [3, 'Carla Compras']] as $u) {
    $wpdb->insert('wp_users', ['ID' => $u[0], 'display_name' => $u[1]]);
}
foreach ([[1, '76.000.001-1', 'FIJACIONES MAMUT'], [2, '76.000.002-2', 'STEELFIX SPA'], [3, '76.000.003-3', 'WURTH CHILE LTDA'], [4, '76.000.004-4', 'OTRO PROVEEDOR']] as $s) {
    $wpdb->insert("{$p}proveedores", ['id' => $s[0], 'rut' => $s[1], 'nombre' => $s[2]]);
}

$sku_to_id = [];
$fh = fopen($repo . '/CodigosBarra/productos_2026-04-01.csv', 'r');
fgetcsv($fh, 0, ';', '"', '');
while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
    $sku = trim($r[0], "\xEF\xBB\xBF \t");
    if ($sku === '' || isset($sku_to_id[$sku])) continue;
    $wpdb->insert("{$p}producto_base", ['canonical_sku' => $sku, 'nombre_canonico' => $r[1]]);
    $sku_to_id[$sku] = $wpdb->insert_id;
}
fclose($fh);
$fh = fopen($repo . '/CodigosBarra/codigos_barras_2026-04-01.csv', 'r');
fgetcsv($fh, 0, ';', '"', '');
$n_bar = 0;
while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
    $sku = trim($r[0], "\xEF\xBB\xBF \t");
    if (!isset($sku_to_id[$sku]) || trim($r[1]) === '') continue;
    // La caja Mamut de 100 se guarda con su cantidad, como hace el mapeo de barras real.
    $wpdb->insert("{$p}codigo_barra", ['codigo' => trim($r[1]), 'producto_base_id' => $sku_to_id[$sku],
        'cantidad' => strpos($r[1], '780983') === 0 ? 100 : 1]);
    $n_bar++;
}
fclose($fh);

$map = json_decode(file_get_contents($repo . '/data/sku_mapping.json'), true);
$n_pp = 0;
foreach ($map as $code => $sku) {
    if (!isset($sku_to_id[$sku])) continue;
    $wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $sku_to_id[$sku], 'proveedor_id' => 1,
        'codigo_proveedor' => $code, 'unidad_compra' => 'caja', 'factor_conversion' => 100]);
    $n_pp++;
}
// Contraparte online de un producto Mamut: su SKU es el código del proveedor.
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '01TRN', 'nombre_canonico' => 'TARUGO DE NYLON M-5 X 25 (online)']);
$online_id = $wpdb->insert_id;
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7809831999999', 'producto_base_id' => $online_id]);

// Producto de catálogo sin SKU: en producción un vínculo Mamut (aquí 08TFC) cuelga de él, no del producto local.
$wpdb->insert("{$p}producto_base", ['canonical_sku' => null, 'nombre_canonico' => 'TIRAFONDO 5/16-9 X 2 (catálogo)']);
$wpdb->query("UPDATE {$p}producto_proveedor SET producto_base_id = {$wpdb->insert_id} WHERE codigo_proveedor = '08TFC'");

$steel = $sku_to_id['28690'];      // CRS 8-9 x 3 Zinc Steell: en el POS antiguo su "barra" es 000-139
$broca = $sku_to_id['22306'];      // Broca HSS 11 mm WURTH: su "barra" antigua es 0617401100
$vidrio = $sku_to_id['24574'];
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $steel, 'proveedor_id' => 2, 'codigo_proveedor' => '000-139', 'factor_conversion' => 1]);
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $broca, 'proveedor_id' => 3, 'codigo_proveedor' => '0617 401 100', 'codigo_barras_proveedor' => '4099999000017']);
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => null, 'proveedor_id' => 2, 'codigo_proveedor' => '999-001', 'nombre_proveedor' => 'WEDGE ANCHOR 1/2x4-1/4']);
// Un producto con dos proveedores, uno preferido.
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $vidrio, 'proveedor_id' => 2, 'codigo_proveedor' => 'VID-01']);
$wpdb->insert("{$p}producto_proveedor", ['producto_base_id' => $vidrio, 'proveedor_id' => 4, 'codigo_proveedor' => 'V100', 'es_preferido' => 1]);

echo "seed: productos=" . count($sku_to_id) . " barras=$n_bar pp_mamut=$n_pp\n";

/* ---------- Código bajo prueba ---------- */
require RIVERSO_POS_PLUGIN_DIR . 'includes/helpers-mamut-sku.php';
require RIVERSO_POS_PLUGIN_DIR . 'catalog/barcodes/class-barcode-model.php';
require RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
require RIVERSO_POS_PLUGIN_DIR . 'purchases/notices/class-purchase-notice-service.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if (!$cond) $fails++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? "  [$detail]" : '') . "\n";
}
function brief($res) {
    return implode(' | ', array_map(function ($c) {
        return ($c['sku'] ?: 'pp' . $c['pp_id']) . ':' . $c['fuente'] . ($c['pack_qty'] ? ' x' . $c['pack_qty'] : '');
    }, $res['candidatos']));
}
function throws($fn) {
    try { $fn(); } catch (Exception $e) { return $e->getMessage(); }
    return '';
}

$svc = Riverso_Purchase_Notice_Service::get_instance();

echo "\n== Migración ==\n";
check('ready() crea las tablas vía activador', $svc->ready() === true);
$cols = $wpdb->get_col("SHOW COLUMNS FROM {$p}ordenes_compra");
check('ordenes_compra gana fecha_emision y fecha_esperada', in_array('fecha_emision', $cols, true) && in_array('fecha_esperada', $cols, true));
Riverso_POS_Activator::ensure_purchase_notices();
check('la migración es idempotente', count($wpdb->get_col("SHOW COLUMNS FROM {$p}avisos_compra")) > 20);

echo "\n== Identificar ==\n";
$r = $svc->resolve('7809831018696', 'camara');
check('EAN Mamut → SKU 752 por barra, caja de 100', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '752' && $r['candidatos'][0]['fuente'] === 'barra' && $r['candidatos'][0]['pack_qty'] == 100, brief($r));
check('  trae el proveedor y su código', ($r['candidatos'][0]['proveedores'][0]['codigo'] ?? '') === '01TRN' && $r['candidatos'][0]['proveedores'][0]['factor'] == 100);
$r = $svc->resolve('01trn');
check('código corto Mamut en minúsculas → SKU 752 (y no la contraparte online)', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '752', brief($r));
$r = $svc->resolve('7809831999999', 'camara');
check('barra de la contraparte online → producto local', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '752', brief($r));
$r = $svc->resolve('45ATPFG');
check('45ATPFG sin guion encuentra 45ATPF-G', $r['exactos'] === 1 && $r['candidatos'][0]['fuente'] === 'codigo_proveedor', brief($r));
foreach (['000-139', '000139', '000 139'] as $q) {
    $r = $svc->resolve($q);
    check("Steelfix «{$q}» → producto con código 000-139", $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '28690', brief($r));
}
$r = $svc->resolve('139');
check('«139» no cuenta como coincidencia exacta del código 000-139', $r['exactos'] === 0, brief($r));
foreach (['100-420', '100420'] as $q) {
    $r = $svc->resolve($q);
    check("Steelfix «{$q}» sin vínculo de proveedor: sale de la barra antigua", $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '478' && $r['candidatos'][0]['fuente'] === 'barra', brief($r));
}
$r = $svc->resolve('0672 200 040');
check('Würth con espacios, guardado como barra sin espacios', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '24787', brief($r));
foreach (['0617401100', '0617 401 100'] as $q) {
    $r = $svc->resolve($q);
    check("Würth «{$q}» → broca 11 mm", $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '22306', brief($r));
}
$r = $svc->resolve('4099999000017', 'camara');
check('barra guardada solo en producto_proveedor', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '22306', brief($r));
$r = $svc->resolve('24574');
check('número manuscrito = SKU local', $r['exactos'] === 1 && $r['candidatos'][0]['fuente'] === 'sku' && count($r['candidatos'][0]['proveedores']) === 2, brief($r));
check('  dos proveedores, el preferido marcado', $r['candidatos'][0]['proveedores'][0]['preferido'] === true);
$r = $svc->resolve('2000279001003', 'camara');
check('etiqueta propia Riverso → SKU 279', $r['exactos'] >= 1 && $r['candidatos'][0]['sku'] === '279', brief($r));
$r = $svc->resolve('999-001');
check('código de proveedor sin producto vinculado igual aparece', $r['exactos'] === 1 && $r['candidatos'][0]['producto_base_id'] === 0 && $r['candidatos'][0]['pp_id'] > 0, brief($r) . ' → ' . ($r['candidatos'][0]['nombre'] ?? ''));
$r = $svc->resolve('08TFC');
check('vínculo colgado de un producto de catálogo sin SKU → un solo candidato, el local', $r['exactos'] === 1 && $r['candidatos'][0]['sku'] === '21690' && ($r['candidatos'][0]['proveedores'][0]['codigo'] ?? '') === '08TFC', brief($r));
$r = $svc->resolve('tarugo nylon 25');
check('búsqueda por palabras del nombre', $r['exactos'] === 0 && count($r['candidatos']) >= 1 && $r['candidatos'][0]['fuente'] === 'nombre', brief($r));
$r = $svc->resolve('https://q.me-qr.com/lOYkPrgq', 'camara');
check('un QR con URL no identifica nada', count($r['candidatos']) === 0);
$r = $svc->resolve("x' OR '1'='1");
check('texto con comillas no rompe la consulta', is_array($r['candidatos']));

echo "\n== Crear, sumar, duplicados ==\n";
$GLOBALS['TEST_USER'] = 1;
check('cantidad sin unidad se rechaza', strpos(throws(function () use ($svc, $sku_to_id) {
    $svc->create(['producto_base_id' => $sku_to_id['752'], 'cantidad' => '300']);
}), 'unidad') !== false);
check('aviso vacío se rechaza', throws(function () use ($svc) { $svc->create([]); }) !== '');
$a = $svc->create(['producto_base_id' => $sku_to_id['752'], 'codigo_leido' => '7809831018696', 'origen_codigo' => 'camara', 'match_fuente' => 'barra', 'cantidad' => '3', 'unidad' => 'caja', 'sin_stock' => true]);
check('aviso con producto: proveedor y equivalencia salen del vínculo', $a['proveedor_nombre'] === 'FIJACIONES MAMUT' && $a['codigo_proveedor'] === '01TRN' && $a['equivalencia'] == 300 && $a['sin_stock'] === true, json_encode([$a['proveedor_nombre'], $a['codigo_proveedor'], $a['equivalencia']]));
$b = $svc->create(['producto_base_id' => $sku_to_id['752']]);
check('aviso sin cantidad queda sin cantidad', $b['cantidad'] === null && $b['estado'] === 'abierto');
$r = $svc->resolve('01TRN');
check('al volver a buscar aparece como ya avisado', count($r['candidatos'][0]['abiertos']) === 2 && $r['candidatos'][0]['abiertos'][0]['creado_nombre'] === 'Pedro Bodega');
$GLOBALS['TEST_USER'] = 2;
$j = $svc->join($b['id'], ['cantidad' => '2', 'unidad' => 'caja']);
check('sumarse completa la cantidad que faltaba y cuenta el apoyo', $j['apoyos'] === 1 && $j['cantidad'] == 2 && $j['unidad'] === 'caja');
$u = $svc->create(['texto' => 'hilo 1/4 métrico', 'proveedor_id' => 2, 'nota' => 'no quedan']);
check('sin identificar con proveedor elegido', $u['identificado'] === false && $u['titulo'] === 'hilo 1/4 métrico' && $u['proveedor_nombre'] === 'STEELFIX SPA');
$v = $svc->create(['producto_base_id' => $vidrio]);
check('dos proveedores sin elegir → el preferido', $v['proveedor_nombre'] === 'OTRO PROVEEDOR');
$w = $svc->create(['producto_proveedor_id' => $r2 = (int) $wpdb->get_var("SELECT id FROM {$p}producto_proveedor WHERE codigo_proveedor='999-001'"), 'codigo_leido' => '999-001']);
check('aviso por código de proveedor sin producto', $w['identificado'] === false && $w['codigo_proveedor'] === '999-001' && $w['titulo'] === 'WEDGE ANCHOR 1/2x4-1/4', $w['titulo']);

echo "\n== Bandeja ==\n";
$list = $svc->list_notices(['estado' => 'abiertos']);
$names = array_column($list['grupos'], 'nombre');
check('agrupa por proveedor', $names === ['FIJACIONES MAMUT', 'OTRO PROVEEDOR', 'STEELFIX SPA'], implode(', ', $names));
check('contadores', $list['counts'] === ['abiertos' => 5, 'sin_identificar' => 2], json_encode($list['counts']));
check('filtro sin identificar', count($svc->list_notices(['estado' => 'sin_identificar'])['grupos'][0]['avisos']) === 2);
check('búsqueda en la bandeja', count($svc->list_notices(['buscar' => 'tarugo'])['grupos']) === 1);
check('bodega no puede ingresar', throws(function () use ($svc, $a) { $svc->update($a['id'], 'ingresar', [], false); }) !== '');
check('quien no lo creó no puede retirarlo', throws(function () use ($svc, $a) { $svc->update($a['id'], 'descartar', ['motivo' => 'error'], false); }) !== '');
$own = $svc->update($u['id'], 'descartar', [], false);
check('quien avisó retira su aviso', $own['estado'] === 'descartado' && $own['motivo_descarte'] === 'error');
$GLOBALS['TEST_USER'] = 3;
$q = $svc->update($b['id'], 'cantidad', ['cantidad' => '5', 'unidad' => 'caja'], true);
check('compras confirma la cantidad', $q['cantidad'] == 5 && $q['cantidad_confirmada'] === true && $q['confirmo_nombre'] === 'Carla Compras');
$i = $svc->update($a['id'], 'ingresar', [], true);
check('ingresado guarda quién y cuándo', $i['estado'] === 'ingresado' && $i['ingresado_nombre'] === 'Carla Compras' && $i['ingresado_hace'] !== '');
check('no se ingresa dos veces', throws(function () use ($svc, $a) { $svc->update($a['id'], 'ingresar', [], true); }) !== '');
$r = $svc->resolve('01TRN');
check('ahora aparece como ya pedido', count($r['candidatos'][0]['recientes']) === 1 && count($r['candidatos'][0]['abiertos']) === 1);
$re = $svc->update($a['id'], 'reabrir', [], true);
check('reabrir limpia el ingreso', $re['estado'] === 'abierto' && $re['ingresado_nombre'] === '');
$id = $svc->update($w['id'], 'identificar', ['producto_base_id' => $steel], true);
check('identificar asigna el producto', $id['identificado'] === true && $id['sku'] === '28690');
$pr = $svc->update($v['id'], 'proveedor', ['proveedor_id' => 2], true);
check('cambiar proveedor toma el código de ese proveedor', $pr['proveedor_nombre'] === 'STEELFIX SPA' && $pr['codigo_proveedor'] === 'VID-01');
$pr = $svc->update($v['id'], 'proveedor', ['proveedor_id' => 0], true);
check('quitar proveedor', $pr['proveedor_id'] === 0 && $pr['codigo_proveedor'] === '');
$GLOBALS['TEST_USER'] = 2;
$mine = $svc->mine(2);
check('mis avisos incluye los creados y los apoyados', count($mine) === 4, count($mine) . ' avisos');
check('lista de proveedores, primero los de más productos', $svc->suppliers()[0]['nombre'] === 'FIJACIONES MAMUT' && count($svc->suppliers('wurth')) === 1);
check('eventos registrados', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}aviso_compra_eventos") >= 12, $wpdb->get_var("SELECT GROUP_CONCAT(tipo ORDER BY id) FROM {$p}aviso_compra_eventos"));

echo "\n== Proveedor sugerido ==\n";
$r = $svc->resolve('100420');
check('producto sin vínculo y sin avisos: no sugiere proveedor', $r['candidatos'][0]['proveedores'] === [] && $r['candidatos'][0]['proveedor_sugerido'] === null);
$s1 = $svc->create(['producto_base_id' => $sku_to_id['478'], 'proveedor_id' => 2]);
$r = $svc->resolve('100420');
check('después de un aviso, sugiere el proveedor de ese aviso', ($r['candidatos'][0]['proveedor_sugerido']['nombre'] ?? '') === 'STEELFIX SPA');
$svc->update($s1['id'], 'descartar', [], false);
$r = $svc->resolve('100420');
check('un aviso descartado no cuenta como sugerencia', $r['candidatos'][0]['proveedor_sugerido'] === null);

echo "\nconsultas ejecutadas: {$wpdb->queries}\n";
echo $fails ? "RESULTADO: {$fails} FALLAS\n" : "RESULTADO: todo ok\n";
exit($fails ? 1 : 0);
