<?php
/**
 * Prueba de integración: agregar un código de barra que ya existe (Procesar folios → Barcode).
 * Nunca se duplica: se ofrece actualizar (mismo producto), mover (traspaso dentro de la familia)
 * o reemplazar (otro producto). El producto que lo pierde no lo borra: queda en desuso y desactivado.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/barcodes-conflicto/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

function riverso_event_publish() {}
function absint($v) { return abs((int) $v); }
function add_action() {}
function add_filter() {}
function sanitize_textarea_field($s) { return trim((string) $s); }
class WP_Error { private $m; public function __construct($c = "", $m = "") { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error($v) { return $v instanceof WP_Error; }

$wpdb->query("CREATE TABLE {$p}producto_base (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_sku VARCHAR(100) DEFAULT NULL,
    nombre_canonico VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_groups (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo_grupo VARCHAR(50), nombre VARCHAR(255),
    es_producto_unitario TINYINT(1) DEFAULT 0, unit_producto_base_id BIGINT UNSIGNED DEFAULT NULL,
    tipo_sustitucion VARCHAR(20) DEFAULT 'exacta', activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}envases (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    cantidad_unidades DECIMAL(12,4) NOT NULL DEFAULT 1, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}tareas (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tipo VARCHAR(50) NOT NULL, referencia_tipo VARCHAR(50),
    referencia_id BIGINT UNSIGNED, estado VARCHAR(20) DEFAULT 'pendiente', datos_extra LONGTEXT, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}codigo_barra (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo VARCHAR(50) NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'ean13', producto_base_id BIGINT UNSIGNED DEFAULT NULL, proveedor_id BIGINT UNSIGNED DEFAULT NULL,
    cantidad DECIMAL(10,3) NOT NULL DEFAULT 1, unidad_medida VARCHAR(20) NOT NULL DEFAULT 'unidad', envase_id BIGINT UNSIGNED DEFAULT NULL,
    factor_a_unidad_base DECIMAL(10,3) NOT NULL DEFAULT 1, activo TINYINT(1) NOT NULL DEFAULT 1, estado VARCHAR(20) NOT NULL DEFAULT 'verificado',
    motivo_estado VARCHAR(255) DEFAULT NULL, estado_por BIGINT UNSIGNED DEFAULT NULL, estado_at DATETIME DEFAULT NULL,
    origen_datos VARCHAR(50) NOT NULL DEFAULT 'manual', requires_human_review TINYINT(1) NOT NULL DEFAULT 0, sku_local VARCHAR(100) DEFAULT NULL,
    pending_sku VARCHAR(100) DEFAULT NULL, legacy_ref LONGTEXT DEFAULT NULL, conflicto TINYINT(1) NOT NULL DEFAULT 0,
    migrado_de_tabla VARCHAR(50) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)) $cc");

$wpdb->query("CREATE TABLE {$p}codigos_historial (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, codigo_id BIGINT UNSIGNED NOT NULL,
    accion VARCHAR(20) NOT NULL, campo_modificado VARCHAR(50) DEFAULT NULL, valor_anterior TEXT DEFAULT NULL, valor_nuevo TEXT DEFAULT NULL,
    usuario_id BIGINT UNSIGNED DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)) $cc");

require RIVERSO_POS_PLUGIN_DIR . 'catalog/barcodes/class-barcode-model.php';
require RIVERSO_POS_PLUGIN_DIR . 'modules/families/class-unit-product-service.php';
require RIVERSO_POS_PLUGIN_DIR . 'modules/products/class-product-module.php';

$fails = 0;
function check($label, $cond, $detail = '') {
    global $fails;
    if (!$cond) {
        $fails++;
    }
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . (!$cond && $detail !== '' ? "\n         → " . $detail : '') . "\n";
}
function rows($codigo) {
    global $wpdb, $p;
    return $wpdb->get_results("SELECT id, producto_base_id, activo, estado, cantidad, tipo, motivo_estado, legacy_ref
        FROM {$p}codigo_barra WHERE codigo = '" . $codigo . "' ORDER BY id", ARRAY_A);
}
function active_on($codigo) {
    return array_values(array_map(function ($r) { return (int) $r['producto_base_id']; }, array_filter(rows($codigo), function ($r) {
        return (int) $r['activo'] === 1;
    })));
}

// Producto sin abrir el constructor (registra hooks): solo la lógica de conflictos.
$ref = new ReflectionClass('Riverso_Product_Module');
$mod = $ref->newInstanceWithoutConstructor();
$conflict = $ref->getMethod('barcode_conflict');
$conflict->setAccessible(true);
$resolve = $ref->getMethod('resolve_barcode_conflict');
$resolve->setAccessible(true);
function fields($codigo, $cantidad = 1, $motivo = '') {
    return ['codigo' => $codigo, 'tipo' => 'ean13', 'cantidad' => $cantidad, 'unidad_medida' => 'unidad', 'proveedor_id' => null, 'motivo' => $motivo];
}

// Familia CRS 6x2: unitario 272 + caja 500 (247610). Y un producto de otra familia (29248).
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '272', 'nombre_canonico' => 'CRS 6 x 2 Zinc.']);
$unit = $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '247610', 'nombre_canonico' => 'CRS 6 x 2 (Caja 500)']);
$box = $wpdb->insert_id;
$wpdb->insert("{$p}producto_base", ['canonical_sku' => '29248', 'nombre_canonico' => 'Pintura spray REX']);
$spray = $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_groups", ['codigo_grupo' => 'F27', 'nombre' => 'CRS 6x2', 'es_producto_unitario' => 1, 'unit_producto_base_id' => $unit]);
$g = $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $g, 'producto_base_id' => $unit]);
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $g, 'producto_base_id' => $box]);
$wpdb->insert("{$p}envases", ['producto_base_id' => $box, 'cantidad_unidades' => 500]);

echo "create() ya no duplica códigos propuestos\n";
// Como en producción: EAN de la caja cargado en el unitario, dos veces, propuesto, del sistema antiguo.
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7809831098070', 'producto_base_id' => $unit, 'estado' => 'propuesto', 'activo' => 1, 'legacy_ref' => 'tpv:1']);
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7809831098070', 'producto_base_id' => $unit, 'estado' => 'propuesto', 'activo' => 1, 'legacy_ref' => 'tpv:2']);
check('create() rechaza un código propuesto activo en otro producto', Riverso_Barcode_Model::create('7809831098070', 'ean13', $box, 500, 'unidad') === false);
check('  y no agrega filas', count(rows('7809831098070')) === 2);

echo "Traspaso dentro de la familia (unitario → caja)\n";
$c = $conflict->invoke($mod, '7809831098070', $box);
check('detecta el conflicto y su relación: unitario de la familia', $c && $c['opciones'] === ['mover'] && $c['ocurrencias'][0]['relacion'] === 'misma_familia' && $c['ocurrencias'][0]['rol'] === 'unitario' && $c['destino_rol'] === 'caja de 500', json_encode($c));
check('  el mensaje dice dónde está', strpos($c['mensaje'], 'SKU 272') !== false && strpos($c['mensaje'], 'unitario de la familia F27 CRS 6x2') !== false, $c['mensaje']);
$id = $resolve->invoke($mod, $c, 'mover', fields('7809831098070'));
$all = rows('7809831098070');
check('queda una sola fila activa, en la caja', active_on('7809831098070') === [$box], json_encode($all));
$kept = array_values(array_filter($all, function ($r) use ($id) { return (int) $r['id'] === $id; }))[0];
check('  la misma fila se movió (no se creó otra) con cantidad 500 y verificada', count($all) === 2 && (float) $kept['cantidad'] == 500 && $kept['estado'] === 'verificado' && $kept['tipo'] === 'ean13' && $kept['legacy_ref'] === '', json_encode($kept));
$old = array_values(array_filter($all, function ($r) use ($id) { return (int) $r['id'] !== $id; }))[0];
check('  el duplicado del unitario queda en desuso y desactivado, con motivo (no se borra)', (int) $old['activo'] === 0 && $old['estado'] === 'en_desuso' && strpos($old['motivo_estado'], 'Traspaso dentro de la familia') !== false, json_encode($old));
check('  el escáner ya no lo sugiere en el unitario', count(Riverso_Barcode_Model::active_occurrences('7809831098070')) === 1);

echo "Reemplazo desde otro producto\n";
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7800000000017', 'producto_base_id' => $spray, 'estado' => 'verificado', 'activo' => 1]);
$c = $conflict->invoke($mod, '7800000000017', $unit);
check('otro producto: se ofrece reemplazar', $c['opciones'] === ['reemplazar'] && $c['ocurrencias'][0]['relacion'] === 'otro_producto');
$id = $resolve->invoke($mod, $c, 'reemplazar', fields('7800000000017', 1, 'Etiqueta equivocada en la caja'));
$all = rows('7800000000017');
check('queda activo solo en el producto nuevo', active_on('7800000000017') === [$unit] && count($all) === 2, json_encode($all));
check('  el producto que lo pierde: en desuso, desactivado, con motivo', (int) $all[0]['activo'] === 0 && $all[0]['estado'] === 'en_desuso' && strpos($all[0]['motivo_estado'], 'Reemplazado: pasó a SKU 272') !== false && strpos($all[0]['motivo_estado'], 'Etiqueta equivocada') !== false, $all[0]['motivo_estado']);

echo "Ya estaba en el mismo producto\n";
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7809831199272', 'producto_base_id' => $unit, 'estado' => 'propuesto', 'activo' => 1]);
$wpdb->insert("{$p}codigo_barra", ['codigo' => '7809831199272', 'producto_base_id' => $unit, 'estado' => 'propuesto', 'activo' => 1]);
$c = $conflict->invoke($mod, '7809831199272', $unit);
check('mismo producto: se ofrece actualizar', $c['opciones'] === ['actualizar']);
$resolve->invoke($mod, $c, 'actualizar', fields('7809831199272'));
$all = rows('7809831199272');
check('queda una fila activa verificada y el repetido sale', active_on('7809831199272') === [$unit] && $all[0]['estado'] === 'verificado' && (int) $all[1]['activo'] === 0 && $all[1]['estado'] === 'en_desuso', json_encode($all));

check('cada retiro queda en el historial de códigos', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}codigos_historial WHERE accion = 'retiro_codigo_barra'") === 3);

echo "Sin conflicto\n";
check('un código nuevo no tiene conflicto', $conflict->invoke($mod, '7801234567890', $box) === null);
check('la fila desactivada no cuenta: el conflicto apunta al dueño actual', $conflict->invoke($mod, '7800000000017', $spray)['ocurrencias'][0]['producto_base_id'] === $unit);

echo $fails ? "\n{$fails} prueba(s) fallaron\n" : "\nTodo OK\n";
exit($fails ? 1 : 0);
