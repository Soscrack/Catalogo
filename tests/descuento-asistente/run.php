<?php
/**
 * Prueba de integración: Asistente de Descuento (fase 81).
 * Actitud efectiva de competidores (vínculo → dominio), qué precios cuentan (tipo y vigencia)
 * e historial al aceptar una cotización y al emitir el documento.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/descuento-asistente/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

function get_option($k, $d = false) { return $GLOBALS['TEST_OPTIONS'][$k] ?? $d; }
function update_option($k, $v, $autoload = null) { $GLOBALS['TEST_OPTIONS'][$k] = $v; return true; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error {
    private $msg;
    public function __construct($code = '', $msg = '') { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}

$failures = 0;
function check($cond, $label) {
    global $failures;
    echo ($cond ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$cond) $failures++;
}

/* ---------- Esquema base ---------- */
$wpdb->query("CREATE TABLE {$p}competencia_fuentes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(50) NOT NULL,
    nombre VARCHAR(120) NOT NULL, base_url VARCHAR(255) DEFAULT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}competencia_productos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, fuente_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(500) DEFAULT NULL, slug VARCHAR(255) DEFAULT NULL, url_producto VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}competencia_precios (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_id BIGINT UNSIGNED NOT NULL,
    snapshot_fecha DATE NOT NULL, precio_bruto_unitario DECIMAL(18,6) DEFAULT NULL, precio_bruto_total DECIMAL(18,6) DEFAULT NULL,
    cantidad_min INT UNSIGNED DEFAULT NULL, actualizado_at DATETIME DEFAULT NULL, oculto TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}competencia_match (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_competencia_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED DEFAULT NULL, tipo_match VARCHAR(20) DEFAULT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'sugerido',
    revisado_por BIGINT UNSIGNED DEFAULT NULL, revisado_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}customer_quote_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}billing_draft_lines (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, draft_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}producto_base (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, canonical_sku VARCHAR(100) DEFAULT NULL,
    nombre_canonico VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_groups (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, nombre VARCHAR(255),
    activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}equivalence_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, grupo_id BIGINT UNSIGNED NOT NULL,
    producto_base_id BIGINT UNSIGNED NOT NULL, activo TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}dte_issued (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, folio VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id)) $cc");

require RIVERSO_POS_PLUGIN_DIR . 'includes/class-activator.php';
require RIVERSO_POS_PLUGIN_DIR . 'modules/competencia/class-competencia-match-service.php';
require RIVERSO_POS_PLUGIN_DIR . 'modules/discount-assistant/class-discount-assistant-service.php';

Riverso_POS_Activator::ensure_discount_assistant_schema();
$cols = function ($table) use ($wpdb) { return $wpdb->get_col("SHOW COLUMNS FROM {$table}"); };

echo "== Esquema (fase 81) ==\n";
check(in_array('actitud', $cols("{$p}competencia_match"), true), 'competencia_match.actitud');
check(in_array('discount_origin', $cols("{$p}customer_quote_items"), true), 'customer_quote_items.discount_origin');
check(in_array('discount_origin', $cols("{$p}billing_draft_lines"), true), 'billing_draft_lines.discount_origin');
check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}descuento_historial") === 0, 'tabla descuento_historial');
check(in_array('deshabilitado_precio', $cols("{$p}competencia_productos"), true)
    && in_array('precio_cambio_at', $cols("{$p}competencia_productos"), true), 'fase 82: URL deshabilitable');
check(in_array('discount_review', $cols("{$p}customer_quote_items"), true)
    && in_array('discount_review', $cols("{$p}billing_draft_lines"), true), 'fase 82: discount_review en líneas');
check(in_array('usar_oculto', $cols("{$p}competencia_productos"), true)
    && in_array('usar_oculto_at', $cols("{$p}competencia_productos"), true), 'fase 83: precio oculto «Usar siempre»');
check($wpdb->get_var("SELECT actitud FROM {$p}competencia_competidores WHERE dominio = 'sodimac.cl'") === 'codicioso', 'Sodimac sembrado como codicioso');
check($wpdb->get_var("SELECT actitud FROM {$p}competencia_competidores WHERE dominio = 'sande.cl'") === 'competitivo', 'Sande sembrado como competitivo');
// Idempotente: una segunda corrida no duplica ni pisa.
$wpdb->query("UPDATE {$p}competencia_competidores SET actitud = 'sin_clasificar' WHERE dominio = 'dimafi.cl'");
$GLOBALS['TEST_OPTIONS']['riverso_pos_phase81_discount_assistant'] = '0';
Riverso_POS_Activator::ensure_discount_assistant_schema();
check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}competencia_competidores") === 3, 'segunda corrida no duplica competidores');
check($wpdb->get_var("SELECT actitud FROM {$p}competencia_competidores WHERE dominio = 'dimafi.cl'") === 'sin_clasificar', 'segunda corrida no pisa lo marcado');

/* ---------- Competencia de un SKU ---------- */
$wpdb->insert("{$p}competencia_fuentes", ['slug' => 'sande', 'nombre' => 'Sande', 'base_url' => 'https://www.sande.cl']);
$sande = $wpdb->insert_id;
$wpdb->insert("{$p}competencia_fuentes", ['slug' => 'manual', 'nombre' => 'Ingreso manual']);
$manual = $wpdb->insert_id;
$now = current_time('mysql');
$old = gmdate('Y-m-d H:i:s', strtotime($now) - 120 * 86400);
$pb = 7;
$add = function ($fuente, $url, $slug, $tipo, $unit, $total, $qty, $when, $estado = 'confirmado') use ($wpdb, $p, $pb) {
    $wpdb->insert("{$p}competencia_productos", ['fuente_id' => $fuente, 'nombre' => 'Tornillo', 'slug' => $slug, 'url_producto' => $url]);
    $id = $wpdb->insert_id;
    $wpdb->insert("{$p}competencia_precios", ['producto_id' => $id, 'snapshot_fecha' => substr($when, 0, 10),
        'precio_bruto_unitario' => $unit, 'precio_bruto_total' => $total, 'cantidad_min' => $qty, 'actualizado_at' => $when]);
    $wpdb->insert("{$p}competencia_match", ['producto_competencia_id' => $id, 'producto_base_id' => $pb,
        'tipo_match' => $tipo, 'estado' => $estado]);
    return $id;
};
$cSande = $add($sande, null, 'tornillo-x', 'exacto', 36, 36, 1, $now);
$cSodimac = $add($manual, 'https://www.sodimac.cl/p/123', '123', 'exacto_envase', 38, 3800, 100, $now);
$cOtro = $add($manual, 'https://www.otro.cl/x', 'x', 'exacto', 40, 40, 1, $now);
$cSimilar = $add($manual, 'https://www.sodimac.cl/p/999', '999', 'similar', 30, 30, 1, $now);
$cViejo = $add($sande, 'https://www.sande.cl/producto/viejo', 'viejo', 'exacto', 31, 31, 1, $old);
$add($manual, 'https://www.otro.cl/rechazado', 'r', 'exacto', 10, 10, 1, $now, 'rechazado');

echo "== Actitud y vigencia ==\n";
$r = Riverso_Discount_Assistant_Service::set_link_attitude($cOtro, 'competitivo');
check(!is_wp_error($r) && $r['actitud'] === 'competitivo', 'actitud del vínculo guardada');
check(is_wp_error(Riverso_Discount_Assistant_Service::set_link_attitude(99999, 'codicioso')), 'vínculo inexistente rechazado');
$d = Riverso_Discount_Assistant_Service::set_domain_attitude('https://www.otro.cl/cualquier', 'codicioso', 'Otro SA');
check(!is_wp_error($d) && $d['dominio'] === 'otro.cl', 'dominio desde URL (sin www)');

$list = Riverso_Discount_Assistant_Service::competitors_for_product($pb);
$by = [];
foreach ($list as $row) $by[$row['producto_competencia_id']] = $row;
check(count($list) === 5, 'solo vínculos confirmados (5)');
check($by[$cSande]['dominio'] === 'sande.cl' && $by[$cSande]['actitud'] === 'competitivo' && $by[$cSande]['cuenta'], 'Sande: dominio desde la fuente, competitivo, cuenta');
check($by[$cSodimac]['actitud'] === 'codicioso' && $by[$cSodimac]['cuenta'] && abs($by[$cSodimac]['precio_unitario'] - 38) < 0.001, 'Sodimac exacto envase: codicioso, $38/u, cuenta');
check($by[$cOtro]['actitud_dominio'] === 'codicioso' && $by[$cOtro]['actitud'] === 'competitivo', 'la actitud del vínculo manda sobre la del dominio');
check(!$by[$cSimilar]['cuenta'] && strpos($by[$cSimilar]['motivo'], 'referencia') !== false, 'similar: solo referencia');
check(!$by[$cViejo]['cuenta'] && $by[$cViejo]['desactualizado'], 'más de 90 días: desactualizado, no cuenta');
Riverso_Discount_Assistant_Service::set_link_attitude($cOtro, 'otro');
$list = Riverso_Discount_Assistant_Service::competitors_for_product($pb);
foreach ($list as $row) $by[$row['producto_competencia_id']] = $row;
check($by[$cOtro]['actitud_vinculo'] === 'sin_clasificar' && $by[$cOtro]['actitud'] === 'codicioso', '«otro» = sin clasificar: usa la del dominio');

$domains = [];
foreach (Riverso_Discount_Assistant_Service::list_domains() as $row) $domains[$row['dominio']] = $row;
check(isset($domains['otro.cl']) && $domains['otro.cl']['vinculos'] === 1 && $domains['otro.cl']['nombre'] === 'Otro SA', 'competidores: otro.cl con 1 vínculo y nombre');
check(isset($domains['sodimac.cl']) && $domains['sodimac.cl']['vinculos'] === 2, 'competidores: sodimac.cl con 2 vínculos');
check(isset($domains['dimafi.cl']) && $domains['dimafi.cl']['vinculos'] === 0, 'competidores: sembrado sin vínculos también aparece');

echo "== URL deshabilitada y precio oculto ==\n";
require RIVERSO_POS_PLUGIN_DIR . 'cli/competencia-deshabilitados.php';
$state = function ($id) use ($pb) {
    foreach (Riverso_Discount_Assistant_Service::competitors_for_product($pb) as $row) {
        if ($row['producto_competencia_id'] === $id) return $row;
    }
    return null;
};
$r = Riverso_Discount_Assistant_Service::set_url_enabled($cSande, false, 'Página con precio viejo');
check(!is_wp_error($r), 'deshabilitar URL');
$row = $state($cSande);
check($row['deshabilitado'] && !$row['cuenta'] && strpos($row['motivo'], 'Página con precio viejo') !== false, 'deshabilitada: no cuenta y muestra el motivo');
check(abs((float) $wpdb->get_var("SELECT deshabilitado_precio FROM {$p}competencia_productos WHERE id = {$cSande}") - 36) < 0.001, 'congela el precio vigente');
$cli = new mysqli('127.0.0.1', 'root', '', 'riverso_test', 33077);
check(riverso_cli_mark_disabled_price_changes($cli, $p, $sande, '2026-10-17 02:00:00') === 0, 'nocturno: mismo precio, no marca');
$wpdb->query("UPDATE {$p}competencia_precios SET precio_bruto_unitario = 34 WHERE producto_id = {$cSande}");
check(riverso_cli_mark_disabled_price_changes($cli, $p, $sande, '2026-10-17 02:00:00') === 1, 'nocturno: precio distinto, marca «precio cambió»');
check(riverso_cli_mark_disabled_price_changes($cli, $p, $sande, '2026-10-18 02:00:00') === 0, 'nocturno: no vuelve a marcar');
$row = $state($cSande);
check($row['precio_cambio'] === '2026-10-17' && strpos($row['motivo'], 'revisar') !== false, 'asistente avisa «El precio cambió… revisar»');
check(!$row['cuenta'], 'sigue sin contar hasta habilitarla');
Riverso_Discount_Assistant_Service::set_url_enabled($cSande, true);
$row = $state($cSande);
check(!$row['deshabilitado'] && $row['cuenta'] && $row['precio_cambio'] === '', 'habilitar: vuelve a contar y limpia el aviso');
check(is_wp_error(Riverso_Discount_Assistant_Service::set_url_enabled($cSande, false, '', '2020-01-01')), '«hasta» en el pasado rechazado');
check(!is_wp_error(Riverso_Discount_Assistant_Service::set_url_enabled($cSande, false, '', '2099-01-01')), '«hasta» futuro aceptado');
check(!$state($cSande)['cuenta'], 'con «hasta» futuro no cuenta');
$wpdb->query("UPDATE {$p}competencia_productos SET deshabilitado_hasta = '2020-01-01' WHERE id = {$cSande}");
$row = $state($cSande);
check($row['deshabilitado_marcado'] && !$row['deshabilitado'] && $row['cuenta'], '«hasta» vencido: vuelve a contar solo');
Riverso_Discount_Assistant_Service::set_url_enabled($cSande, true);
$wpdb->query("UPDATE {$p}competencia_precios SET oculto = 1 WHERE producto_id = {$cOtro}");
$row = $state($cOtro);
check(!$row['cuenta'] && $row['oculto'] && strpos($row['motivo'], 'oculto') !== false, 'precio oculto por el competidor: no cuenta');
check(!$row['usar_oculto'] && $row['motivo_oculto_usado'] === '' && strpos($row['motivo_oculto_sin_usar'], 'oculto') !== false,
    'oculto: el asistente recibe el motivo si se usa «esta vez» (cuenta) y si no');
check(!is_wp_error(Riverso_Discount_Assistant_Service::set_hidden_price_use($cOtro, true)), '«Usar siempre» guardado');
$row = $state($cOtro);
check($row['cuenta'] && $row['usar_oculto'] && $row['motivo'] === '' && $row['usar_oculto_desde'] !== '', '«Usar siempre»: el oculto cuenta');
$wpdb->query("UPDATE {$p}competencia_precios SET actualizado_at = '{$old}' WHERE producto_id = {$cOtro}");
$row = $state($cOtro);
check(!$row['cuenta'] && strpos($row['motivo'], 'desactualizado') !== false
    && strpos($row['motivo_oculto_sin_usar'], 'oculto') !== false, '«Usar siempre» no salta la vigencia de 90 días');
$wpdb->query("UPDATE {$p}competencia_precios SET actualizado_at = '{$now}' WHERE producto_id = {$cOtro}");
check(!is_wp_error(Riverso_Discount_Assistant_Service::set_hidden_price_use($cOtro, false)), '«Dejar de usar para siempre» guardado');
$row = $state($cOtro);
check(!$row['cuenta'] && !$row['usar_oculto'] && $row['usar_oculto_desde'] === '', 'dejar de usar para siempre: vuelve a no contar');
$wpdb->query("UPDATE {$p}competencia_precios SET oculto = 0 WHERE producto_id = {$cOtro}");
check(is_wp_error(Riverso_Discount_Assistant_Service::set_hidden_price_use($cOtro, true)), 'precio no oculto: «Usar siempre» rechazado');
check(is_wp_error(Riverso_Discount_Assistant_Service::set_hidden_price_use(99999, false)), 'producto de competencia inexistente rechazado');
$row = $state($cOtro);
check($row['cuenta'] && $row['motivo_oculto_usado'] === $row['motivo_oculto_sin_usar'], 'sin ocultar: ambos motivos iguales');
$batch = Riverso_Discount_Assistant_Service::competitors_for_products([$pb, $pb, 999, 0]);
check(count($batch) === 2 && count($batch[$pb]) === 5 && $batch[999] === [], 'consulta de varios productos (sin repetidos)');

echo "== Familia y sugeridos ==\n";
$wpdb->insert("{$p}producto_base", ['id' => 7, 'canonical_sku' => '326', 'nombre_canonico' => 'Tuerca Hex 1/2-13']);
$wpdb->insert("{$p}producto_base", ['id' => 8, 'canonical_sku' => '20593', 'nombre_canonico' => 'Tuerca Hex 1/2-13 caja 100']);
$wpdb->insert("{$p}producto_base", ['id' => 9, 'canonical_sku' => '777', 'nombre_canonico' => 'Miembro inactivo']);
$wpdb->insert("{$p}equivalence_groups", ['nombre' => 'Tuerca Hex 1/2-13']);
$grupo = $wpdb->insert_id;
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $grupo, 'producto_base_id' => 7]);
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $grupo, 'producto_base_id' => 8]);
$wpdb->insert("{$p}equivalence_members", ['grupo_id' => $grupo, 'producto_base_id' => 9, 'activo' => 0]);
$addTo = function ($pbTarget, $url, $tipo, $unit, $estado) use ($wpdb, $p, $manual, $now) {
    $wpdb->insert("{$p}competencia_productos", ['fuente_id' => $manual, 'nombre' => 'Tuerca', 'slug' => md5($url), 'url_producto' => $url]);
    $id = $wpdb->insert_id;
    $wpdb->insert("{$p}competencia_precios", ['producto_id' => $id, 'snapshot_fecha' => substr($now, 0, 10),
        'precio_bruto_unitario' => $unit, 'precio_bruto_total' => $unit * 100, 'cantidad_min' => 100, 'actualizado_at' => $now]);
    $wpdb->insert("{$p}competencia_match", ['producto_competencia_id' => $id, 'producto_base_id' => $pbTarget,
        'tipo_match' => $tipo, 'estado' => $estado]);
    return $id;
};
$cFam = $addTo(8, 'https://www.dimafi.cl/caja-100', 'exacto', 50, 'confirmado');
$cSug = $addTo(7, 'https://www.sande.cl/producto/caja-100-b206th1', null, 53.15, 'sugerido');
$cSugOtro = $addTo(8, 'https://www.sande.cl/producto/otro', null, 60, 'sugerido');
$cInactivo = $addTo(9, 'https://www.otro.cl/inactivo', 'exacto', 45, 'confirmado');
$byId = [];
foreach (Riverso_Discount_Assistant_Service::competitors_for_product($pb) as $row) $byId[$row['producto_competencia_id']] = $row;
check(isset($byId[$cFam]) && $byId[$cFam]['via_familia']['sku'] === '20593' && $byId[$cFam]['cuenta'], 'vínculo de otro miembro de la familia: aparece «vía familia» y cuenta');
check(isset($byId[$cSug]) && $byId[$cSug]['sugerido'] && !$byId[$cSug]['cuenta'] && $byId[$cSug]['via_familia'] === null, 'sugerido del SKU: aparece y no cuenta hasta confirmarlo');
check(!isset($byId[$cSugOtro]), 'sugeridos de otros miembros no se muestran');
check(!isset($byId[$cInactivo]), 'miembros inactivos de la familia no cuentan');
check(count($byId) === 7, 'el SKU ve sus 5 vínculos + 1 de familia + 1 sugerido');
$r = Riverso_Discount_Assistant_Service::set_link_attitude($cSug, 'competitivo');
check(is_wp_error($r), 'un sugerido no acepta actitud hasta confirmarlo');
$wpdb->query("UPDATE {$p}competencia_match SET estado = 'confirmado', tipo_match = 'exacto_envase' WHERE producto_competencia_id = {$cSug}");
foreach (Riverso_Discount_Assistant_Service::competitors_for_product($pb) as $row) $byId[$row['producto_competencia_id']] = $row;
check(!$byId[$cSug]['sugerido'] && $byId[$cSug]['cuenta'], 'al confirmarlo (exacto envase) cuenta');

echo "== Ajustes ==\n";
$s = Riverso_Discount_Assistant_Service::get_settings();
check($s['volume_units'] == 1000 && $s['volume_amount'] == 75000 && $s['volume_amount_alone'] == 120000
    && $s['floor_factor'] == 1.6 && $s['round_step'] === 50 && $s['stale_days'] === 90, 'valores por defecto');
$GLOBALS['TEST_OPTIONS']['riverso_discount_assistant_settings'] = ['volume_units' => 2000, 'volume_amount' => 75000, 'stale_days' => 60];
$s = Riverso_Discount_Assistant_Service::get_settings();
check($s['volume_units'] == 1000 && $s['stale_days'] === 60, 'ajustes antiguos: 2.000 u. pasa a 1.000, el resto se conserva');
$GLOBALS['TEST_OPTIONS']['riverso_discount_assistant_settings'] = ['volume_units' => 2000, 'volume_amount_alone' => 120000];
check(Riverso_Discount_Assistant_Service::get_settings()['volume_units'] == 2000, 'ajustes nuevos: 2.000 u. elegido a mano se respeta');
unset($GLOBALS['TEST_OPTIONS']['riverso_discount_assistant_settings']);
check(is_wp_error(Riverso_Discount_Assistant_Service::save_settings(['volume_amount_alone' => '-1'])), 'monto solo no puede ser negativo');
check(is_wp_error(Riverso_Discount_Assistant_Service::save_settings(['min_ratio_num' => '1,9'])), 'A debe ser menor que B');
$saved = Riverso_Discount_Assistant_Service::save_settings(['volume_units' => '1500', 'stale_days' => '60']);
check(!is_wp_error($saved) && $saved['volume_units'] == 1500 && $saved['stale_days'] === 60, 'guarda volumen y vigencia');
$list = Riverso_Discount_Assistant_Service::competitors_for_product($pb, $saved);
Riverso_Discount_Assistant_Service::save_settings(['volume_units' => '2000', 'stale_days' => '90']);

echo "== Historial ==\n";
$quote = [
    'id' => 55, 'quote_number' => 'COT-55', 'customer_name' => 'Constructora Sur',
    'lines' => [
        ['producto_base_id' => $pb, 'sku' => 'TOR-1', 'description' => 'Tornillo 8x1', 'quantity' => 5, 'units_per_pack' => 500,
            'unit_cost' => 20, 'discount_amount' => 20050, 'line_net' => 89950, 'discount_anchor' => 'final_total',
            'discount_anchor_value' => 89950, 'discount_origin' => 'sugerido'],
        ['producto_base_id' => 8, 'sku' => 'X', 'description' => 'Sin descuento', 'quantity' => 1, 'unit_cost' => 10,
            'discount_amount' => 0, 'line_net' => 100],
    ],
];
check(Riverso_Discount_Assistant_Service::record_quote_accepted($quote) === 1, 'aceptar: guarda solo líneas con descuento');
Riverso_Discount_Assistant_Service::record_quote_accepted($quote);
check((int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}descuento_historial") === 1, 'aceptar de nuevo reemplaza (no duplica)');
$h = Riverso_Discount_Assistant_Service::history_for_product($pb);
check(count($h) === 1 && $h[0]['unidades'] == 2500 && $h[0]['bruto'] == 110000, 'unidades = cantidad × envase; bruto = final + descuento');
check(abs($h[0]['descuento_pct'] - 18.2273) < 0.001 && abs($h[0]['factor_final'] - 1.799) < 0.0001, '% descuento y factor final');
check(abs($h[0]['precio_final_unitario'] - 35.98) < 0.0001 && $h[0]['origen'] === 'sugerido' && $h[0]['documento'] === 'Cotización COT-55', 'unitario final, origen y documento');

$wpdb->insert("{$p}dte_issued", ['folio' => '4512']);
$dte = $wpdb->insert_id;
$draft = [
    'id' => 9, 'quote_id' => 55, 'dte_id' => $dte, 'document_type_id' => 33, 'receiver_legal_name' => 'Constructora Sur SpA',
    'lines' => [
        ['producto_base_id' => $pb, 'sku' => 'TOR-1', 'description' => 'Tornillo 8x1', 'quantity' => 5, 'units_per_pack' => 500,
            'unit_cost' => 20, 'discount_amount' => 25000, 'line_total_bruto' => 85000, 'discount_anchor' => 'factor',
            'discount_anchor_value' => 1.7, 'discount_origin' => 'bogus'],
    ],
];
check(Riverso_Discount_Assistant_Service::record_draft_finalized($draft) === 1, 'emitir: guarda la línea con descuento');
$h = Riverso_Discount_Assistant_Service::history_for_product($pb);
check(count($h) === 1 && $h[0]['evento'] === 'emitida' && $h[0]['documento'] === 'Factura 4512', 'emitir reemplaza lo de la aceptación (Factura con folio)');
check($h[0]['origen'] === 'manual' && abs($h[0]['factor_final'] - 1.7) < 0.0001, 'origen desconocido → manual; factor 1,7');
check($h[0]['cliente'] === 'Constructora Sur SpA', 'cliente del documento');
$hist = Riverso_Discount_Assistant_Service::list_history(['origen' => 'manual']);
check($hist['total'] === 1 && count($hist['rows']) === 1, 'Centro de Precios: filtro por origen');
check(Riverso_Discount_Assistant_Service::list_history(['origen' => 'sugerido'])['total'] === 0, 'filtro sin resultados');
check(Riverso_Discount_Assistant_Service::list_history(['search' => 'Constructora'])['total'] === 1, 'búsqueda por cliente');

echo $failures ? "\n{$failures} prueba(s) fallaron\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
