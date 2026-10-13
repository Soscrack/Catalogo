#!/usr/bin/env php
<?php
/**
 * Importación diaria del Inbox FACTO (equivalente al botón "Procesar últimos 3 días").
 *
 * Uso (Plesk → Scheduled Tasks → "Run a PHP script", 03:00 America/Santiago;
 * el chroot del dominio no trae PHP, así que "Run a command" no sirve):
 *   php facto-inbox-import.php
 *
 * Log: wp-content/uploads/riverso-logs/facto-inbox.log (lo escribe el propio script).
 *   php facto-inbox-import.php --days=3
 *   php facto-inbox-import.php --desde=2026-10-01 --hasta=2026-10-07
 *   php facto-inbox-import.php --dry-run
 *
 * A diferencia de los crons de precios, carga WordPress (wp-load.php): reutiliza
 * Riverso_Facto_Inbox_Import (cliente FACTO, parser DTE, save/merge de facturas).
 *
 * - Rango por defecto: hoy-N .. hoy (N = --days, por defecto 3).
 * - Reprocesa siempre (force_reprocess), igual que el botón: captura documentos nuevos
 *   y es idempotente sobre facturas ya mapeadas.
 * - Si FACTO falla en una página, reintenta hasta 3 veces; luego deja la corrida en
 *   estado "error" (reanudable desde el historial en wp-admin).
 *
 * Exit codes: 0 ok · 1 fallo fatal · 2 terminó con errores de documentos · 3 ya en ejecución.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

const FACTO_CRON_PAGES_PER_BATCH = 3;
const FACTO_CRON_MAX_RETRIES     = 3;
const FACTO_CRON_RETRY_SLEEP     = 30;
const FACTO_CRON_LOCK            = 'riverso_facto_inbox_cron';

$opts = [
    'days'    => 3,
    'desde'   => null,
    'hasta'   => null,
    'dry_run' => false,
    'wp_load' => null,
    'host'    => getenv('RIVERSO_SITE_HOST') ?: 'riverso.cl',
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif (strpos($arg, '--days=') === 0) {
        $opts['days'] = max(0, min(90, (int) substr($arg, 7)));
    } elseif (strpos($arg, '--desde=') === 0) {
        $opts['desde'] = substr($arg, 8);
    } elseif (strpos($arg, '--hasta=') === 0) {
        $opts['hasta'] = substr($arg, 8);
    } elseif (strpos($arg, '--wp-load=') === 0) {
        $opts['wp_load'] = substr($arg, 10);
    } elseif (strpos($arg, '--host=') === 0) {
        $opts['host'] = substr($arg, 7);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php facto-inbox-import.php [--days=3] [--desde=YYYY-MM-DD --hasta=YYYY-MM-DD] [--dry-run] [--wp-load=PATH] [--host=riverso.cl]\n";
        exit(0);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function facto_log_file() {
    $env = getenv('RIVERSO_FACTO_LOG');
    if ($env) {
        return $env;
    }
    // wp-content/uploads/riverso-logs/ (Plesk "Run a PHP script" descarta stdout)
    return dirname(__DIR__) . '/../../uploads/riverso-logs/facto-inbox.log';
}

function log_write($line) {
    $log = facto_log_file();
    $dir = dirname($log);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

function log_msg($msg) {
    $ts = function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $line = '[' . $ts . '] [facto-inbox] ' . $msg . PHP_EOL;
    echo $line;
    log_write($line);
}

// Va a stderr: con "Notify: Errors only" Plesk envía estos mensajes por correo.
function log_err($msg) {
    $ts = function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    $line = '[' . $ts . '] [facto-inbox] ERROR ' . $msg . PHP_EOL;
    fwrite(STDERR, $line);
    log_write($line);
}

function find_wp_load($explicit = null) {
    if ($explicit && is_file($explicit)) {
        return $explicit;
    }
    $dir = dirname(__DIR__); // riverso-pos
    for ($i = 0; $i < 6; $i++) {
        $candidate = $dir . '/wp-load.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function valid_ymd($value) {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

// ---------------------------------------------------------------------------
// Bootstrap WordPress
// ---------------------------------------------------------------------------

date_default_timezone_set('America/Santiago');

// Warnings de otros plugins al cargar WP irían a stderr y Plesk mandaría correo diario.
// Solo se reportan nuestros errores y los fatales.
@ini_set('display_errors', '0');
@ini_set('error_log', dirname(facto_log_file()) . '/facto-inbox-php.log');
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_err('Fatal: ' . $err['message'] . ' en ' . $err['file'] . ':' . $err['line']);
    }
});

$wp_load = find_wp_load($opts['wp_load']);
if (!$wp_load) {
    log_err('No se encontró wp-load.php (usa --wp-load=PATH).');
    exit(1);
}

$_SERVER['HTTP_HOST']       = $opts['host'];
$_SERVER['SERVER_NAME']     = $opts['host'];
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTPS']           = 'on';

define('WP_USE_THEMES', false);
define('RIVERSO_CLI_CRON', true);

require_once $wp_load;

@set_time_limit(0);
@ini_set('memory_limit', '512M');

if (!function_exists('riverso_facto_is_configured') || !riverso_facto_is_configured()) {
    log_err('FACTO no configurado o plugin riverso-pos inactivo.');
    exit(1);
}

if (!class_exists('Riverso_Facto_Inbox_Import')) {
    $facto_dir = dirname(__DIR__) . '/modules/integrations/facto/';
    require_once $facto_dir . 'class-facto-client.php';
    require_once $facto_dir . 'class-facto-inbox-import.php';
}

// ---------------------------------------------------------------------------
// Rango
// ---------------------------------------------------------------------------

if ($opts['desde'] !== null || $opts['hasta'] !== null) {
    $desde = $opts['desde'];
    $hasta = $opts['hasta'] ?: wp_date('Y-m-d');
    if (!valid_ymd($desde) || !valid_ymd($hasta)) {
        log_err('Fechas inválidas; usa --desde=YYYY-MM-DD --hasta=YYYY-MM-DD.');
        exit(1);
    }
} else {
    $hasta = wp_date('Y-m-d');
    $desde = wp_date('Y-m-d', strtotime('-' . (int) $opts['days'] . ' days', current_time('timestamp', true)));
}

// ---------------------------------------------------------------------------
// Lock global (evita corridas solapadas de cron)
// ---------------------------------------------------------------------------

global $wpdb;
$got_lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', FACTO_CRON_LOCK, 0));
if ($got_lock !== 1) {
    log_err('Otra importación cron está en ejecución; se omite.');
    exit(3);
}
register_shutdown_function(function () {
    global $wpdb;
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', FACTO_CRON_LOCK));
});

// ---------------------------------------------------------------------------
// Importación
// ---------------------------------------------------------------------------

$importer = new Riverso_Facto_Inbox_Import();

log_msg(sprintf('Inicio rango %s → %s%s', $desde, $hasta, $opts['dry_run'] ? ' (dry-run)' : ''));

$estimate = null;
for ($attempt = 1; $attempt <= FACTO_CRON_MAX_RETRIES; $attempt++) {
    $estimate = $importer->estimate_range($desde, $hasta);
    if (!is_wp_error($estimate)) {
        break;
    }
    log_err(sprintf('Estimación intento %d/%d: %s', $attempt, FACTO_CRON_MAX_RETRIES, $estimate->get_error_message()));
    if ($attempt < FACTO_CRON_MAX_RETRIES) {
        sleep(FACTO_CRON_RETRY_SLEEP);
    }
}
if (is_wp_error($estimate)) {
    exit(1);
}

log_msg($estimate['message'] ?? '');

if (empty($estimate['pages'])) {
    log_msg('Sin documentos en el rango. Nada que importar.');
    exit(0);
}
if ($opts['dry_run']) {
    exit(0);
}

$run_id = $importer->create_run(
    $estimate['fecha_desde'],
    $estimate['fecha_hasta'],
    $estimate['page_from'],
    $estimate['page_to']
);
if ($run_id <= 0) {
    log_err('No se pudo crear la corrida: ' . $wpdb->last_error);
    exit(1);
}
log_msg('Corrida #' . $run_id . ' creada.');

$page     = max(1, (int) $estimate['page_from']);
$page_to  = (int) $estimate['page_to'];
$retries  = 0;
$result   = null;
$all_errors = [];

while (true) {
    @set_time_limit(0);
    $result = $importer->import_batch($run_id, $desde, $hasta, $page, FACTO_CRON_PAGES_PER_BATCH, true);
    if (is_wp_error($result)) {
        log_err('Lote página ' . $page . ': ' . $result->get_error_message());
        $wpdb->update($wpdb->prefix . 'riverso_facto_inbox_runs', [
            'state'       => 'error',
            'finished_at' => current_time('mysql'),
            'last_error'  => $result->get_error_message(),
        ], ['id' => $run_id]);
        exit(1);
    }

    foreach ((array) ($result['errors'] ?? []) as $e) {
        $all_errors[] = $e;
    }
    log_msg((string) ($result['message'] ?? ''));

    if (!empty($result['done'])) {
        break;
    }

    // Igual que el JS cuando hay next_page; si hubo errores import_batch no lo entrega:
    // "page" es la última página procesada, así que continuar en page+1 salta
    // errores de documentos y reintenta la página cuya descarga falló.
    $next = !empty($result['next_page']) ? (int) $result['next_page'] : ((int) $result['page'] + 1);

    if ($next <= $page) {
        $retries++;
        if ($retries >= FACTO_CRON_MAX_RETRIES) {
            $msg = 'Página ' . $next . ' falló ' . FACTO_CRON_MAX_RETRIES . ' veces; corrida queda en error (reanudable en wp-admin).';
            log_err($msg);
            $wpdb->update($wpdb->prefix . 'riverso_facto_inbox_runs', [
                'state'       => 'error',
                'finished_at' => current_time('mysql'),
            ], ['id' => $run_id]);
            exit(1);
        }
        log_msg(sprintf('Reintento %d/%d de la página %d en %ds…', $retries, FACTO_CRON_MAX_RETRIES, $next, FACTO_CRON_RETRY_SLEEP));
        sleep(FACTO_CRON_RETRY_SLEEP);
    } else {
        $retries = 0;
    }

    if ($page_to > 0 && $next > $page_to) {
        // Rango agotado con errores de documentos en el último lote: cerrar corrida.
        $wpdb->update($wpdb->prefix . 'riverso_facto_inbox_runs', [
            'state'       => empty($all_errors) ? 'done' : 'error',
            'finished_at' => current_time('mysql'),
        ], ['id' => $run_id]);
        break;
    }

    $page = $next;
    usleep(250000);
}

$t = $result['totals'] ?? [];
log_msg(sprintf(
    'Fin corrida #%d. Importados: %d · Fusionados: %d · Duplicados: %d · Omitidos: %d · Errores: %d · Páginas: %d',
    $run_id,
    (int) ($t['docs_imported'] ?? 0),
    (int) ($t['docs_merged'] ?? 0),
    (int) ($t['docs_duplicate'] ?? 0),
    (int) ($t['docs_skipped'] ?? 0),
    (int) ($t['docs_error'] ?? 0),
    (int) ($t['pages_scanned'] ?? 0)
));

if (!empty($all_errors)) {
    foreach (array_slice(array_unique($all_errors), 0, 20) as $e) {
        log_err($e);
    }
    exit(2);
}
exit(0);
