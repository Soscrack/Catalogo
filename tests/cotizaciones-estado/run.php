<?php
/**
 * Prueba de integración del estado «Esperando respuesta» (cotizaciones de venta) contra MySQL local.
 * Uso: php -d extension=mysqli -d extension=mbstring tests/cotizaciones-estado/run.php
 * (mismo MySQL desechable que tests/avisos: ver tests/avisos/bootstrap.php)
 */
require dirname(__DIR__) . '/avisos/bootstrap.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$cc = $wpdb->get_charset_collate();

function wp_date($format, $ts = null) { return date($format, $ts === null ? time() : $ts); }

/** Reservas simuladas: misma tabla que lee release_stock(). */
class Riverso_Reservation_Service {
    public static function get_instance() { return new self(); }
    public function reserve($base_id, $qty, array $args) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'riverso_reservas', [
            'producto_base_id' => (int) $base_id,
            'cantidad' => (float) $qty,
            'referencia_tipo' => $args['referencia_tipo'],
            'referencia_id' => (int) $args['referencia_id'],
            'estado' => 'activa',
        ]);
    }
}

require RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-exception.php';
require RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-status.php';
require RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-quote-totals.php';
require RIVERSO_POS_PLUGIN_DIR . 'sales/customer_quotes/class-customer-quote-repository.php';

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

/** Ejecuta y devuelve el mensaje de Riverso_Quote_Exception, o null si no falló. */
function fails(callable $fn) {
    try {
        $fn();
    } catch (Riverso_Quote_Exception $e) {
        return $e->getMessage();
    }
    return null;
}

/* ---------- Esquema y datos ---------- */
$wpdb->query("CREATE TABLE {$p}customer_quotes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_number VARCHAR(50) NOT NULL,
    customer_id BIGINT UNSIGNED DEFAULT NULL, customer_name VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) DEFAULT 'draft', quote_type VARCHAR(20) NOT NULL DEFAULT 'venta',
    validity_days INT UNSIGNED DEFAULT NULL, validity_terms TEXT NULL, total DECIMAL(12,2) DEFAULT 0,
    net_total DECIMAL(14,2) NOT NULL DEFAULT 0, discount_total DECIMAL(12,2) DEFAULT 0,
    margin_percent DECIMAL(8,2) NULL, profit_total DECIMAL(14,2) NULL, valid_until DATE DEFAULT NULL,
    notes TEXT, order_id BIGINT UNSIGNED DEFAULT NULL, channel VARCHAR(16) NOT NULL DEFAULT 'local',
    issue_date DATE DEFAULT NULL, created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}customer_quote_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED DEFAULT NULL, sku VARCHAR(100) DEFAULT NULL, name VARCHAR(500) NOT NULL DEFAULT '',
    quantity DECIMAL(14,3) NOT NULL DEFAULT 1, unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,2) DEFAULT NULL, discount_amount DECIMAL(12,2) DEFAULT 0,
    line_total DECIMAL(14,2) NOT NULL DEFAULT 0, sort_order INT DEFAULT 0,
    producto_base_id BIGINT UNSIGNED DEFAULT NULL, PRIMARY KEY (id)) $cc");
$wpdb->query("CREATE TABLE {$p}reservas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, producto_base_id BIGINT UNSIGNED NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL, referencia_tipo VARCHAR(40) NOT NULL, referencia_id BIGINT UNSIGNED NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'activa', released_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) $cc");

function new_quote($status, $with_line = true) {
    global $wpdb, $p;
    static $n = 0;
    $n++;
    $wpdb->insert("{$p}customer_quotes", [
        'quote_number' => 'T-' . $n, 'customer_name' => 'Cliente', 'status' => $status,
        'net_total' => 1000, 'issue_date' => date('Y-m-d'), 'validity_days' => 10,
    ]);
    $id = (int) $wpdb->insert_id;
    if ($with_line) {
        $wpdb->insert("{$p}customer_quote_items", [
            'quote_id' => $id, 'name' => 'Producto', 'quantity' => 2, 'unit_price' => 500,
            'line_total' => 1000, 'producto_base_id' => 7,
        ]);
    }
    return $id;
}

function active_reservations($id) {
    global $wpdb, $p;
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}reservas WHERE referencia_id = " . (int) $id . " AND estado = 'activa'");
}

/* ---------- Estados ---------- */
echo "== estados ==\n";
check(Riverso_Quote_Status::label('awaiting') === 'Esperando respuesta', 'nombre visible de awaiting');
check(Riverso_Quote_Status::normalize_legacy('awaiting') === 'awaiting', 'awaiting no se lee como Borrador');
check(Riverso_Quote_Status::normalize_legacy('sent') === 'listed', 'sent (legado) sigue siendo Aprobada');
check(!Riverso_Quote_Status::is_editable('awaiting'), 'Esperando respuesta no es editable');
foreach (['draft', 'listed', 'rejected', 'cancelled'] as $from) {
    check(Riverso_Quote_Status::can_transition($from, 'awaiting'), "{$from} → awaiting permitido");
}
check(!Riverso_Quote_Status::can_transition('invoiced', 'awaiting'), 'facturada no pasa a awaiting');
$from_awaiting = Riverso_Quote_Status::allowed_targets('awaiting');
sort($from_awaiting);
check($from_awaiting === ['cancelled', 'draft', 'listed', 'rejected'], 'desde awaiting: aprobar, rechazar, anular, borrador');

/* ---------- Repositorio ---------- */
echo "== repositorio ==\n";
$repo = new Riverso_Customer_Quote_Repository();

// Correo desde Borrador → Esperando respuesta.
$q = new_quote('draft');
check($repo->mark_awaiting_after_email($q) === true, 'correo: Borrador pasa a Esperando');
$found = $repo->find($q);
check($found['status'] === 'awaiting' && $found['status_label'] === 'Esperando respuesta', 'queda awaiting con su nombre');
check($found['editable'] === false, 'awaiting bloquea edición');
check(active_reservations($q) === 0, 'awaiting no reserva stock');
check($repo->mark_awaiting_after_email($q) === false, 'reenviar una awaiting no cambia nada');

// Esperando → Aprobada reserva; Aprobada → Esperando libera.
$repo->transition($q, 'listed');
check(active_reservations($q) === 1, 'aprobar desde awaiting reserva stock');
check($repo->mark_awaiting_after_email($q) === false, 'correo con Aprobada no cambia el estado');
check($repo->find($q)['status'] === 'listed', 'sigue Aprobada tras el correo');
$repo->transition($q, 'awaiting');
check(active_reservations($q) === 0, 'Aprobada → awaiting libera la reserva');
$repo->transition($q, 'draft');
check($repo->find($q)['status'] === 'draft', 'awaiting → Borrador');

// Rechazada → Esperando directo.
$r = new_quote('rejected');
$repo->transition($r, 'awaiting');
check($repo->find($r)['status'] === 'awaiting', 'Rechazada → awaiting directo');

// Facturar desde Esperando.
$b = new_quote('awaiting');
check($repo->approve_for_billing($b) === true && $repo->find($b)['status'] === 'listed', 'cargar en Facturación aprueba una awaiting');

// Sin productos no pasa a Esperando.
$e = new_quote('draft', false);
check(fails(function () use ($repo, $e) { $repo->transition($e, 'awaiting'); }) !== null, 'awaiting manual exige productos');
check($repo->mark_awaiting_after_email($e) === false, 'correo de borrador vacío no cambia el estado');

// Facturada no cambia.
$i = new_quote('invoiced');
check(fails(function () use ($repo, $i) { $repo->transition($i, 'awaiting'); }) !== null, 'facturada no pasa a awaiting');

// Filtro de lista por estado.
$list = $repo->list_quotes(['status' => 'awaiting']);
$ids = array_map(function ($row) { return (int) $row['id']; }, isset($list['items']) ? $list['items'] : $list);
check(in_array($r, $ids, true) && !in_array($b, $ids, true), 'filtro de lista por Esperando respuesta');

echo $failures ? "\n{$failures} fallas\n" : "\nTodo OK\n";
exit($failures ? 1 : 0);
