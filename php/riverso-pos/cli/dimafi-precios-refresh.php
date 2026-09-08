#!/usr/bin/env php
<?php
/**
 * Refresco diario de precios DIMAFI (sin wp-load.php).
 *
 * Uso (crontab America/Santiago 02:00):
 *   php dimafi-precios-refresh.php
 *   php dimafi-precios-refresh.php --dry-run
 *
 * Todos los días:
 *   1. Pagina https://www.dimafi.cl/collections/all/products.json (Shopify público).
 *   2. UPSERT precio vigente en competencia_precios (1 fila por variante).
 *   3. Copia vigente → competencia_precios_historial con fecha de hoy.
 *   4. Borra snapshots diarios intermedios; conserva siempre el 01 y el 16 de cada mes.
 *
 * Abort-guard: si Shopify devuelve < 50 variantes, no toca la BD.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

date_default_timezone_set('America/Santiago');

const DIMAFI_BASE_URL = 'https://www.dimafi.cl';
const DIMAFI_LIMIT    = 250;
const IVA_FACTOR      = 1.19;
const FUENTE_SLUG     = 'dimafi';
const USER_AGENT      = 'RiversoCatalogBot/1.0 (+https://riverso.cl; competencia-precios)';
const MIN_VARIANTS    = 50;

$opts = [
    'dry_run'   => false,
    'wp_config' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif (strpos($arg, '--wp-config=') === 0) {
        $opts['wp_config'] = substr($arg, 12);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php dimafi-precios-refresh.php [--dry-run] [--wp-config=PATH]\n";
        exit(0);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dimafi_log_file() {
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $env = getenv('RIVERSO_DIMAFI_LOG');
    if ($env) {
        $path = $env;
        return $path;
    }
    $path = dirname(__DIR__) . '/../../uploads/riverso-logs/dimafi-precios.log';
    return $path;
}

function log_msg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    $log = dimafi_log_file();
    $dir = dirname($log);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

function find_wp_config($explicit = null) {
    if ($explicit && is_file($explicit)) {
        return $explicit;
    }
    $dir = dirname(__DIR__); // riverso-pos
    for ($i = 0; $i < 6; $i++) {
        $candidate = $dir . '/wp-config.php';
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

function parse_wp_config($path) {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("No se pudo leer $path");
    }
    $grab = function ($name) use ($content) {
        if (!preg_match("/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $content, $m)) {
            throw new RuntimeException("No se encontró $name en wp-config.php");
        }
        return $m[1];
    };
    if (!preg_match('/\\$table_prefix\\s*=\\s*[\'"]([^\'"]*)[\'"]/', $content, $pm)) {
        throw new RuntimeException('No se encontró table_prefix en wp-config.php');
    }
    return [
        'host'   => $grab('DB_HOST'),
        'user'   => $grab('DB_USER'),
        'pass'   => $grab('DB_PASSWORD'),
        'name'   => $grab('DB_NAME'),
        'prefix' => $pm[1] . 'riverso_',
    ];
}

function db_connect(array $cfg) {
    $host = $cfg['host'];
    $port = 3306;
    if (strpos($host, ':') !== false) {
        list($host, $port) = explode(':', $host, 2);
        $port = (int) $port;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $cfg['user'], $cfg['pass'], $cfg['name'], $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function sql_escape(mysqli $db, $value) {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . $db->real_escape_string((string) $value) . "'";
}

/**
 * Infiere cantidad mínima de venta desde el título del producto.
 * Ej: "TORNILLO VOLCANITA (500 UDS)" → 500
 */
function pack_qty_from_title($title) {
    if (!$title) {
        return 1;
    }
    if (preg_match('/\((\d+)\s*U(?:D)?S\.?\)/i', $title, $m)) {
        return max(1, (int) $m[1]);
    }
    if (preg_match('/\b(\d+)\s*UDS?\b/i', $title, $m)) {
        return max(1, (int) $m[1]);
    }
    return 1;
}

function js_round($value) {
    if ($value >= 0) {
        return (int) floor($value + 0.5);
    }
    return (int) ceil($value - 0.5);
}

/**
 * HTTP GET con cURL: reintentos con backoff.
 * Devuelve array PHP o lanza RuntimeException.
 */
function http_json_get($url, $timeout = 60, $retries = 3) {
    $last_err  = null;
    $last_code = 0;
    for ($attempt = 0; $attempt < $retries; $attempt++) {
        if ($attempt > 0) {
            sleep(min(pow(2, $attempt), 20));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: ' . USER_AGENT,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err) {
            $last_err = "cURL: $err";
            continue;
        }
        if ($code === 429) {
            // Rate limit: espera más antes del retry.
            sleep(15 + $attempt * 10);
            $last_code = $code;
            continue;
        }
        if ($code < 200 || $code >= 300) {
            $last_code = $code;
            $last_err  = "HTTP $code";
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $last_err = 'JSON inválido';
            continue;
        }
        return $data;
    }
    throw new RuntimeException("GET $url falló tras $retries intentos: " . ($last_err ?? "HTTP $last_code"));
}

/**
 * Obtiene TODAS las variantes del catálogo paginando products.json.
 * Devuelve array de rows: ['variant_id', 'product_title', 'price', 'compare_at_price', 'available']
 */
function fetch_all_variants() {
    $variants = [];
    $page     = 1;
    while (true) {
        $url  = DIMAFI_BASE_URL . '/collections/all/products.json?limit=' . DIMAFI_LIMIT . '&page=' . $page;
        $data = http_json_get($url);
        $products = $data['products'] ?? [];
        if (empty($products)) {
            break;
        }
        foreach ($products as $p) {
            $title = (string) ($p['title'] ?? '');
            $qty   = pack_qty_from_title($title);
            foreach ($p['variants'] ?? [] as $v) {
                $vid = (string) ($v['id'] ?? '');
                if ($vid === '') {
                    continue;
                }
                $price_raw   = $v['price'] ?? null;
                $compare_raw = $v['compare_at_price'] ?? null;
                $price   = ($price_raw !== null && $price_raw !== '') ? (float) $price_raw : null;
                $compare = ($compare_raw !== null && $compare_raw !== '') ? (float) $compare_raw : null;
                $variants[] = [
                    'variant_id'       => $vid,
                    'product_title'    => $title,
                    'price'            => $price,
                    'compare_at_price' => $compare,
                    'available'        => !empty($v['available']),
                    'cantidad_min'     => $qty,
                ];
            }
        }
        $page++;
        if ($page > 30) {
            break; // seguridad
        }
        // Pausa suave para no generar 429.
        usleep(150000); // 150 ms
    }
    return $variants;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$started = microtime(true);
log_msg('Inicio refresh precios DIMAFI' . ($opts['dry_run'] ? ' [DRY-RUN]' : ''));

try {
    // 1. Credenciales BD.
    $wp_config = find_wp_config($opts['wp_config']);
    if (!$wp_config) {
        throw new RuntimeException('No se encontró wp-config.php');
    }
    $cfg    = parse_wp_config($wp_config);
    $prefix = $cfg['prefix'];
    log_msg("DB={$cfg['name']} prefix={$prefix}");

    // 2. Scrape Shopify.
    log_msg('Paginando ' . DIMAFI_BASE_URL . '/collections/all/products.json …');
    $variants = fetch_all_variants();
    log_msg('Total variantes obtenidas: ' . count($variants));

    // 3. Abort guard.
    if (count($variants) < MIN_VARIANTS) {
        throw new RuntimeException(
            'Demasiadas pocas variantes (' . count($variants) . '); abortando para no corromper vigente'
        );
    }

    // 4. DRY-RUN: muestra sample y sale.
    if ($opts['dry_run']) {
        $sample = $variants[0];
        $bruto_u = $sample['price'] !== null ? round($sample['price'] * IVA_FACTOR, 6) : null;
        log_msg('DRY-RUN sample variant_id=' . $sample['variant_id'] .
            ' price=' . $sample['price'] .
            ' bruto_u=' . $bruto_u .
            ' qty=' . $sample['cantidad_min']);
        log_msg('DRY-RUN OK en ' . round(microtime(true) - $started, 1) . 's');
        exit(0);
    }

    // 5. Conectar BD.
    $db = db_connect($cfg);

    // 6. Verificar fuente dimafi.
    $fuente_res = $db->query(
        "SELECT id FROM `{$prefix}competencia_fuentes` WHERE slug = 'dimafi' LIMIT 1"
    );
    $fuente_row = $fuente_res ? $fuente_res->fetch_assoc() : null;
    if (!$fuente_row) {
        throw new RuntimeException('Fuente dimafi no existe; correr migración phase41 primero');
    }
    $fuente_id = (int) $fuente_row['id'];

    // 7. Verificar tabla historial.
    $hist_table = $db->query(
        "SHOW TABLES LIKE '" . $db->real_escape_string($prefix . 'competencia_precios_historial') . "'"
    );
    if (!$hist_table || $hist_table->num_rows === 0) {
        throw new RuntimeException('Falta tabla competencia_precios_historial; correr phase40');
    }

    $today   = date('Y-m-d');
    $now_scl = date('Y-m-d H:i:s');
    $day     = (int) date('j');
    $keep    = ($day === 1 || $day === 16);
    log_msg("Hoy={$today} día={$day} historial=diario" . ($keep ? ' (se conserva 01/16)' : ' (se rotará)'));

    $updated = 0;
    $db->begin_transaction();

    foreach ($variants as $v) {
        $variant_id = $v['variant_id'];
        $price      = $v['price'];
        $compare    = $v['compare_at_price'];
        $qty        = (int) $v['cantidad_min'];
        $bruto_u    = $price !== null ? round($price * IVA_FACTOR, 6) : null;
        $bruto_t    = $price !== null ? js_round($price * $qty * IVA_FACTOR) : null;

        // UPSERT precio vigente.
        // La clave única en competencia_precios después de phase40 es (producto_id) — 1 fila por producto.
        $db->query("INSERT INTO `{$prefix}competencia_precios`
            (producto_id, snapshot_fecha, precio, precio_lista,
             precio_bruto_unitario, precio_bruto_total,
             cantidad_min, iva, costo, moneda, oculto, actualizado_at)
            SELECT cp.id,
             " . sql_escape($db, $today) . ",
             " . sql_escape($db, $price) . ",
             " . sql_escape($db, $compare) . ",
             " . sql_escape($db, $bruto_u) . ",
             " . sql_escape($db, $bruto_t) . ",
             " . sql_escape($db, $qty) . ",
             " . sql_escape($db, IVA_FACTOR) . ",
             NULL,
             'CLP',
             0,
             " . sql_escape($db, $now_scl) . "
            FROM `{$prefix}competencia_productos` cp
            WHERE cp.fuente_id = {$fuente_id}
              AND cp.id_externo = " . sql_escape($db, $variant_id) . "
            ON DUPLICATE KEY UPDATE
             snapshot_fecha=VALUES(snapshot_fecha),
             precio=VALUES(precio),
             precio_lista=VALUES(precio_lista),
             precio_bruto_unitario=VALUES(precio_bruto_unitario),
             precio_bruto_total=VALUES(precio_bruto_total),
             cantidad_min=VALUES(cantidad_min),
             iva=VALUES(iva),
             moneda=VALUES(moneda),
             oculto=VALUES(oculto),
             actualizado_at=VALUES(actualizado_at)");
        $updated++;
    }

    // 8. Historial: copia vigente de hoy.
    $db->query("INSERT INTO `{$prefix}competencia_precios_historial`
        (producto_id, snapshot_fecha, precio, precio_lista,
         precio_bruto_unitario, precio_bruto_total, cantidad_min, iva, moneda)
        SELECT pr.producto_id,
               " . sql_escape($db, $today) . ",
               pr.precio, pr.precio_lista,
               pr.precio_bruto_unitario, pr.precio_bruto_total,
               pr.cantidad_min, pr.iva, pr.moneda
        FROM `{$prefix}competencia_precios` pr
        INNER JOIN `{$prefix}competencia_productos` cp ON cp.id = pr.producto_id
        WHERE cp.fuente_id = {$fuente_id}
        ON DUPLICATE KEY UPDATE
         precio=VALUES(precio),
         precio_lista=VALUES(precio_lista),
         precio_bruto_unitario=VALUES(precio_bruto_unitario),
         precio_bruto_total=VALUES(precio_bruto_total),
         cantidad_min=VALUES(cantidad_min),
         iva=VALUES(iva),
         moneda=VALUES(moneda)");
    $historial_rows = $db->affected_rows;

    // 9. Prune: conserva hoy + días 01 y 16; borra resto.
    $db->query("DELETE h FROM `{$prefix}competencia_precios_historial` h
        INNER JOIN `{$prefix}competencia_productos` cp ON cp.id = h.producto_id
        WHERE cp.fuente_id = {$fuente_id}
          AND h.snapshot_fecha <> " . sql_escape($db, $today) . "
          AND DAY(h.snapshot_fecha) NOT IN (1, 16)");
    $historial_pruned = $db->affected_rows;

    $db->commit();
    $db->close();

    log_msg("OK variantes={$updated} historial_upsert={$historial_rows} historial_borrados={$historial_pruned} en " .
        round(microtime(true) - $started, 1) . 's');
    exit(0);

} catch (Throwable $e) {
    log_msg('ERROR: ' . $e->getMessage());
    if (isset($db) && $db instanceof mysqli) {
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
        }
        $db->close();
    }
    exit(1);
}
