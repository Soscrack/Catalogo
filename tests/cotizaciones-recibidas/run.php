<?php
/**
 * Prueba de integración de «Aprobar todo» (cotizaciones recibidas) contra MySQL local.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/cotizaciones-recibidas/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

/* ---------- Simulación de WordPress para los endpoints AJAX ---------- */
class Test_Json_Response extends Exception {
    public $ok;
    public $data;
    public function __construct($ok, $data) { parent::__construct('json'); $this->ok = $ok; $this->data = $data; }
}
function add_action($hook, $cb) {}
function check_ajax_referer($action, $field) { return true; }
function wp_send_json_success($data = null) { throw new Test_Json_Response(true, $data); }
function wp_send_json_error($data = null) { throw new Test_Json_Response(false, $data); }
function is_wp_error($v) { return $v instanceof WP_Error; }
class WP_Error { public function get_error_message() { return 'error'; } }

/** Referencias de costo fijas, en el formato de Riverso_Cost_Lookup_Service::analyze_quote(). */
class Riverso_Cost_Lookup_Service {
    public static $rows = [];
    public static function get_instance() { return new self(); }
    public function analyze_quote($id, $base) { return ['rows' => self::$rows]; }
}

require RIVERSO_POS_PLUGIN_DIR . 'modules/quotes/class-received-quote-module.php';
$module = Riverso_POS_Received_Quote_Module::get_instance();

$failures = 0;
function check($cond, $msg) {
    global $failures;
    if ($cond) {
        echo "OK  {$msg}\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$msg}\n");
}

/** Llama un endpoint y devuelve [ok, data]. */
function call_ajax($module, $method, array $post) {
    $_POST = $post;
    try {
        $module->$method();
    } catch (Test_Json_Response $r) {
        return [$r->ok, $r->data];
    }
    return [null, null];
}

/* ---------- Esquema y datos ---------- */
$wpdb->query("CREATE TABLE {$p}cotizaciones_recibidas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, estado VARCHAR(30) DEFAULT 'under_review',
    tipo_doc VARCHAR(30) DEFAULT 'cotizacion', tipo_confirmado TINYINT(1) DEFAULT 1, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}cotizacion_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, cotizacion_id BIGINT UNSIGNED NOT NULL, linea INT DEFAULT 0,
    codigo_proveedor VARCHAR(100) DEFAULT NULL, descripcion VARCHAR(255) DEFAULT NULL,
    cantidad DECIMAL(12,4) DEFAULT 1, costo_neto DECIMAL(14,4) DEFAULT 0,
    costo_anterior DECIMAL(14,4) DEFAULT NULL, diferencia_costo DECIMAL(14,4) DEFAULT NULL,
    decision_status VARCHAR(20) NOT NULL DEFAULT 'pending', updated_by BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id)) $cc");

$wpdb->insert("{$p}cotizaciones_recibidas", ['estado' => 'under_review']);
$qid = (int) $wpdb->insert_id;
$wpdb->insert("{$p}cotizaciones_recibidas", ['estado' => 'parsed', 'tipo_doc' => 'posible_cotizacion', 'tipo_confirmado' => 0]);
$qid_sin_confirmar = (int) $wpdb->insert_id;

// [linea, decisión, cantidad, costo cotización, referencia]
$seed = [
    1 => ['pending', 10, 120, 100],   // sube 20
    2 => ['claim', 5, 130, 100],      // sube 30, estaba en reclamo
    3 => ['pending', 1, 90, 100],     // baja
    4 => ['pending', 2, 70, null],    // sin referencia
    5 => ['remove', 1, 10, 5],        // decisión explícita: no se toca
    6 => ['accepted', 1, 10, 10],     // ya aprobado
    7 => ['fix_qty', 3, 10, 8],       // decisión explícita: no se toca
    8 => ['pending', 4, 50, 50],      // igual
];
$ids = [];
foreach ($seed as $linea => [$dec, $cant, $costo, $ref]) {
    $wpdb->insert("{$p}cotizacion_items", [
        'cotizacion_id' => $qid, 'linea' => $linea, 'codigo_proveedor' => 'C' . $linea,
        'descripcion' => 'Ítem ' . $linea, 'cantidad' => $cant, 'costo_neto' => $costo, 'decision_status' => $dec,
    ]);
    $ids[$linea] = (int) $wpdb->insert_id;
    Riverso_Cost_Lookup_Service::$rows[] = [
        'item_id' => $ids[$linea],
        'costo_actual' => (float) $costo,
        'reference_cost' => $ref,
        'reference_source' => $ref === null ? null : 'invoice',
        'delta' => $ref === null ? null : (float) ($costo - $ref),
        'delta_pct' => $ref === null ? null : (($costo - $ref) / $ref) * 100,
    ];
}
$decision = function ($linea) use ($wpdb, $p, $ids) {
    return $wpdb->get_var("SELECT decision_status FROM {$p}cotizacion_items WHERE id = " . $ids[$linea]);
};

/* ---------- Permisos y bloqueos ---------- */
echo "== permisos ==\n";
[$ok, $data] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid, 'dry_run' => 1]);
check($ok === false && $data['message'] === 'Sin permisos', 'sin permiso de edición: rechaza');
$GLOBALS['TEST_CAPS'] = ['riverso_edit_received_quotes' => true];
[$ok, $data] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid_sin_confirmar, 'dry_run' => 1]);
check($ok === false && strpos($data['message'], 'Confirme el tipo') === 0, 'posible cotización sin confirmar: rechaza');

/* ---------- Vista previa ---------- */
echo "== vista previa (dry_run) ==\n";
[$ok, $plan] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid, 'dry_run' => 1]);
check($ok === true, 'dry_run responde ok');
$s = $plan['summary'];
check($s['items'] === 8, '8 ítems en total');
check($s['to_approve'] === 5, '5 se aprueban (pendientes + reclamo)');
check($s['increases'] === 2, '2 con alza');
check(abs($s['increase_amount'] - (20 * 10 + 30 * 5)) < 0.001, 'impacto de alzas = 350');
check($s['claims_overridden'] === 1, '1 reclamo se anula');
check($s['decreases'] === 1 && $s['same'] === 1, '1 baja y 1 igual');
check($s['no_reference'] === 1, '1 sin costo anterior');
check($s['already_approved'] === 1, '1 ya aprobado');
check($s['kept'] === 2 && array_column($plan['kept'], 'decision_actual') === ['remove', 'fix_qty'], 'Quitar y Corregir cantidad se mantienen');
$nuevas = array_column($plan['changes'], 'decision_nueva', 'linea');
check($nuevas[1] === 'accepted_increase' && $nuevas[2] === 'accepted_increase', 'alzas → Aprobado aumento');
check($nuevas[3] === 'accepted' && $nuevas[4] === 'accepted' && $nuevas[8] === 'accepted', 'baja, sin referencia e igual → Aprobado');
check($decision(1) === 'pending' && $decision(2) === 'claim', 'dry_run no guarda nada');

/* ---------- Aplicar ---------- */
echo "== aplicar ==\n";
[$ok, $data] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid]);
check($ok === false, 'sin item_ids confirmados: rechaza');

// El usuario vio el plan sin la línea 8 (p. ej. se agregó después): esa no se toca.
$vistos = [$ids[1], $ids[2], $ids[3], $ids[4], $ids[5]];
[$ok, $data] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid, 'item_ids' => array_map('strval', $vistos)]);
check($ok === true && $data['applied'] === ['accepted' => 2, 'accepted_increase' => 2], 'aplica 4: 2 aprobados y 2 con aumento');
check($decision(1) === 'accepted_increase' && $decision(2) === 'accepted_increase', 'alzas guardadas como Aprobado aumento');
check($decision(3) === 'accepted' && $decision(4) === 'accepted', 'baja y sin referencia guardadas como Aprobado');
check($decision(5) === 'remove', 'Quitar no cambia aunque venga en item_ids');
check($decision(7) === 'fix_qty' && $decision(6) === 'accepted', 'Corregir cantidad y ya aprobado no cambian');
check($decision(8) === 'pending', 'ítem no visto en la confirmación queda Pendiente');
$row1 = $wpdb->get_row("SELECT costo_anterior, diferencia_costo, updated_by FROM {$p}cotizacion_items WHERE id = {$ids[1]}", ARRAY_A);
check((float) $row1['costo_anterior'] === 100.0 && (float) $row1['diferencia_costo'] === 20.0, 'guarda costo anterior y Δ');
check((int) $row1['updated_by'] === 1, 'registra el usuario');
$row4 = $wpdb->get_row("SELECT costo_anterior, diferencia_costo FROM {$p}cotizacion_items WHERE id = {$ids[4]}", ARRAY_A);
check($row4['costo_anterior'] === null && $row4['diferencia_costo'] === null, 'sin referencia: no inventa costo anterior');

[$ok, $plan2] = call_ajax($module, 'ajax_approve_all_items', ['id' => $qid, 'dry_run' => 1]);
check($plan2['summary']['to_approve'] === 1 && $plan2['changes'][0]['linea'] === 8, 'después solo queda la línea 8 por aprobar');

echo $failures ? "\n{$failures} prueba(s) fallaron\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
