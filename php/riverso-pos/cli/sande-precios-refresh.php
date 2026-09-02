#!/usr/bin/env php
<?php
/**
 * Refresco diario de precios Sande (sin wp-load.php).
 *
 * Uso (crontab America/Santiago 02:00):
 *   php sande-precios-refresh.php
 *   php sande-precios-refresh.php --dry-run
 *   php sande-precios-refresh.php --force-historial
 *
 * Todos los días:
 *   1. UPSERT precio vigente (1 fila por producto).
 *   2. Copia vigente → historial con fecha de hoy.
 *   3. Borra snapshots diarios intermedios; conserva siempre el 01 y el 16 de cada mes.
 *
 * --force-historial: conservado por compatibilidad (el historial diario ya es el comportamiento por defecto).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

date_default_timezone_set('America/Santiago');

const SANDE_API = 'https://sandeonline.cl:2082/taskfocus/maestros/api';
const SANDE_PROGRAMA = '639745';
const SANDE_EMPRESA = '1';
const SANDE_USER = '1';
const SANDE_SECCION = '237184';
const SANDE_MARCA = '165';
const SANDE_PRODUCT_URL = 'https://www.sande.cl/producto';
const IVA_FACTOR = 1.19;
const USER_AGENT = 'RiversoCatalogBot/1.0 (+https://riverso.cl; competencia-precios)';

$opts = [
    'dry_run'          => false,
    'force_historial'  => false,
    'seccion'          => SANDE_SECCION,
    'marca'            => SANDE_MARCA,
    'wp_config'        => null,
    'from_json'        => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry_run'] = true;
    } elseif ($arg === '--force-historial') {
        $opts['force_historial'] = true;
    } elseif (strpos($arg, '--seccion=') === 0) {
        $opts['seccion'] = substr($arg, 10);
    } elseif (strpos($arg, '--marca=') === 0) {
        $opts['marca'] = substr($arg, 8);
    } elseif (strpos($arg, '--wp-config=') === 0) {
        $opts['wp_config'] = substr($arg, 12);
    } elseif (strpos($arg, '--from-json=') === 0) {
        $opts['from_json'] = substr($arg, 12);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Uso: php sande-precios-refresh.php [--dry-run] [--force-historial] [--seccion=ID] [--marca=ID] [--from-json=PATH] [--wp-config=PATH]\n";
        exit(0);
    }
}

function sande_log_file() {
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $env = getenv('RIVERSO_SANDE_LOG');
    if ($env) {
        $path = $env;
        return $path;
    }
    // wp-content/uploads/riverso-logs/ (visible también bajo chroot Plesk)
    $path = dirname(__DIR__) . '/../../uploads/riverso-logs/sande-precios.log';
    return $path;
}

function log_msg($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    $log = sande_log_file();
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

function http_json_post($url, array $payload, $timeout = 600) {
    $ch = curl_init($url);
    $body = json_encode($payload);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; RiversoCatalogBot/1.0; +https://riverso.cl)',
            'Origin: https://www.sande.cl',
            'Referer: https://www.sande.cl/mcatalogo',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException("cURL error $errno: $err");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code desde API Sande");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Respuesta JSON inválida de Sande');
    }
    return $data;
}

function parse_cl_decimal($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $s = trim((string) $value);
    if ($s === '') {
        return null;
    }
    if (preg_match('/,\\d/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    if (!is_numeric($s)) {
        return null;
    }
    return (float) $s;
}

function js_round($value) {
    if ($value >= 0) {
        return (int) floor($value + 0.5);
    }
    return (int) ceil($value - 0.5);
}

function min_venta_qty(array $p) {
    $raw = $p['uniMinVta'] ?? $p['idMinUniVta'] ?? 1;
    $n = parse_cl_decimal($raw);
    if ($n === null || $n < 1) {
        return 1;
    }
    return (int) $n;
}

function catalog_unit_net(array $p) {
    $lista = parse_cl_decimal($p['precioProductoL'] ?? null);
    if ($lista !== null) {
        return $lista;
    }
    return parse_cl_decimal($p['precioProducto'] ?? null);
}

function norm_code($value) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $value)));
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

$started = microtime(true);
log_msg('Inicio refresh precios Sande' . ($opts['dry_run'] ? ' [DRY-RUN]' : ''));

try {
    $wp_config = find_wp_config($opts['wp_config']);
    if (!$wp_config) {
        throw new RuntimeException('No se encontró wp-config.php');
    }
    $cfg = parse_wp_config($wp_config);
    $prefix = $cfg['prefix'];
    log_msg("DB={$cfg['name']} prefix={$prefix}");

    if (!empty($opts['from_json'])) {
        $path = $opts['from_json'];
        if (!is_file($path)) {
            throw new RuntimeException("No existe --from-json=$path");
        }
        log_msg("Leyendo productos desde $path");
        $raw = file_get_contents($path);
        $rows = json_decode($raw, true);
        if (!is_array($rows)) {
            throw new RuntimeException('JSON inválido en --from-json');
        }
        log_msg('JSON con ' . count($rows) . ' productos');
    } else {
        log_msg('Llamando listarProductosT3 seccion=' . $opts['seccion'] . ' …');
        $rows = http_json_post(
            SANDE_API . '/MaestroProducto/listarProductosT3',
            [
                'idEmpresa'   => SANDE_EMPRESA,
                'idUser'      => SANDE_USER,
                'idPrograma'  => SANDE_PROGRAMA,
                'tipoProducto'=> '0',
                'idCategoria' => '0',
                'proveedor'   => '0',
                'marca'       => '0',
                'descripProd' => '',
                'nPalabras'   => '0',
                'idSeccion'   => $opts['seccion'],
            ]
        );
        log_msg('API devolvió ' . count($rows) . ' productos');
    }

    $marca = (string) $opts['marca'];
    if ($marca !== '') {
        $rows = array_values(array_filter($rows, function ($p) use ($marca) {
            return (string) ($p['idMarca'] ?? '') === $marca;
        }));
        log_msg('Tras filtro marca ' . $marca . ': ' . count($rows));
    }

    if (count($rows) < 100) {
        throw new RuntimeException('Demasiados pocos productos (' . count($rows) . '); abortando para no corromper vigente');
    }

    $today = date('Y-m-d');
    $now_scl = date('Y-m-d H:i:s');
    $day = (int) date('j');
    $keep_as_quincenal = ($day === 1 || $day === 16);
    log_msg("Hoy={$today} {$now_scl} América/Santiago día={$day} historial=diario" . ($keep_as_quincenal ? ' (se conserva 01/16)' : ' (se rotará)'));

    if ($opts['dry_run']) {
        $sample = $rows[0];
        $neto = catalog_unit_net($sample);
        $qty = min_venta_qty($sample);
        log_msg('DRY-RUN sample codigo=' . ($sample['codigoProducto'] ?? '') .
            ' neto=' . $neto . ' qty=' . $qty .
            ' bruto_u=' . ($neto !== null ? round($neto * IVA_FACTOR, 6) : 'null'));
        log_msg('DRY-RUN OK en ' . round(microtime(true) - $started, 1) . 's');
        exit(0);
    }

    $db = db_connect($cfg);

    $fuente = $db->query("SELECT id FROM `{$prefix}competencia_fuentes` WHERE slug = 'sande' LIMIT 1");
    $fuente_row = $fuente->fetch_assoc();
    if (!$fuente_row) {
        throw new RuntimeException('Fuente sande no existe; correr migración phase39 primero');
    }
    $fuente_id = (int) $fuente_row['id'];

    $hist_table = $db->query(
        "SHOW TABLES LIKE '" . $db->real_escape_string($prefix . 'competencia_precios_historial') . "'"
    );
    if (!$hist_table || $hist_table->num_rows === 0) {
        throw new RuntimeException('Falta tabla competencia_precios_historial; correr phase40');
    }

    $updated = 0;
    $created = 0;
    $db->begin_transaction();

    foreach ($rows as $p) {
        $ext_id = trim((string) ($p['idProducto'] ?? ''));
        if ($ext_id === '') {
            continue;
        }
        $codigo = trim((string) ($p['codigoProducto'] ?? ''));
        $slug = trim((string) ($p['slug'] ?? ''));
        $url = $slug !== '' ? SANDE_PRODUCT_URL . '/' . $slug : '';
        $qty = min_venta_qty($p);
        $neto = catalog_unit_net($p);
        $bruto_u = $neto !== null ? round($neto * IVA_FACTOR, 6) : null;
        $bruto_t = $neto !== null ? js_round($neto * $qty * IVA_FACTOR) : null;
        $precio = parse_cl_decimal($p['precioProducto'] ?? null);
        $precio_lista = parse_cl_decimal($p['precioProductoL'] ?? null);
        $costo = parse_cl_decimal($p['costoProducto'] ?? ($p['precioCosto'] ?? null));
        $oculto = ((string) ($p['ocultarPrecio'] ?? '0') === '1') ? 1 : 0;
        $moneda = $p['moneda'] ?? $p['idMoneda'] ?? 'CLP';
        if ($moneda === '' || $moneda === null) {
            $moneda = 'CLP';
        }

        $sql_prod = "INSERT INTO `{$prefix}competencia_productos`
            (fuente_id, id_externo, codigo_externo, codigo_normalizado, nombre, slug, url_producto,
             marca, id_marca, fabricante, id_division, id_seccion, id_categoria,
             nombre_division, nombre_seccion, nombre_categoria,
             unidad_min_venta, tipo_unidad, peso, stock, situacion, imagen_principal, capturado_at)
            VALUES (
             {$fuente_id},
             " . sql_escape($db, $ext_id) . ",
             " . sql_escape($db, $codigo) . ",
             " . sql_escape($db, norm_code($codigo)) . ",
             " . sql_escape($db, $p['nombreProducto'] ?? '') . ",
             " . sql_escape($db, $slug) . ",
             " . sql_escape($db, $url) . ",
             " . sql_escape($db, $p['nombreMarca'] ?? '') . ",
             " . sql_escape($db, (string) ($p['idMarca'] ?? '')) . ",
             " . sql_escape($db, $p['brand'] ?? '') . ",
             " . sql_escape($db, (string) ($p['idDivision'] ?? '')) . ",
             " . sql_escape($db, (string) ($p['idSeccion'] ?? '')) . ",
             " . sql_escape($db, (string) ($p['idCategoria'] ?? '')) . ",
             " . sql_escape($db, $p['nombreDivision'] ?? '') . ",
             " . sql_escape($db, $p['nombreSeccion'] ?? '') . ",
             " . sql_escape($db, $p['nombreCategoria'] ?? '') . ",
             " . sql_escape($db, (string) ($p['uniMinVta'] ?? $p['idMinUniVta'] ?? '')) . ",
             " . sql_escape($db, $p['tipoUnidad'] ?? '') . ",
             " . sql_escape($db, (string) ($p['peso'] ?? '')) . ",
             " . sql_escape($db, (string) ($p['stockt'] ?? $p['stocke'] ?? '')) . ",
             " . sql_escape($db, $p['situacion'] ?? '') . ",
             " . sql_escape($db, str_replace('\\', '/', (string) ($p['link'] ?? ''))) . ",
             " . sql_escape($db, $today) . "
            )
            ON DUPLICATE KEY UPDATE
             codigo_externo=VALUES(codigo_externo),
             codigo_normalizado=VALUES(codigo_normalizado),
             nombre=VALUES(nombre),
             slug=VALUES(slug),
             url_producto=VALUES(url_producto),
             marca=VALUES(marca),
             unidad_min_venta=VALUES(unidad_min_venta),
             tipo_unidad=VALUES(tipo_unidad),
             stock=VALUES(stock),
             situacion=VALUES(situacion),
             capturado_at=VALUES(capturado_at),
             updated_at=CURRENT_TIMESTAMP";
        $db->query($sql_prod);
        if ($db->affected_rows === 1) {
            $created++;
        }

        $sql_precio = "INSERT INTO `{$prefix}competencia_precios`
            (producto_id, snapshot_fecha, precio, precio_lista, precio_bruto_unitario, precio_bruto_total,
             cantidad_min, iva, costo, moneda, oculto, actualizado_at)
            SELECT cp.id,
             " . sql_escape($db, $today) . ",
             " . sql_escape($db, $precio) . ",
             " . sql_escape($db, $precio_lista) . ",
             " . sql_escape($db, $bruto_u) . ",
             " . sql_escape($db, $bruto_t) . ",
             " . sql_escape($db, $qty) . ",
             " . sql_escape($db, IVA_FACTOR) . ",
             " . sql_escape($db, $costo) . ",
             " . sql_escape($db, $moneda) . ",
             {$oculto},
             " . sql_escape($db, $now_scl) . "
            FROM `{$prefix}competencia_productos` cp
            WHERE cp.fuente_id = {$fuente_id} AND cp.id_externo = " . sql_escape($db, $ext_id) . "
            ON DUPLICATE KEY UPDATE
             snapshot_fecha=VALUES(snapshot_fecha),
             precio=VALUES(precio),
             precio_lista=VALUES(precio_lista),
             precio_bruto_unitario=VALUES(precio_bruto_unitario),
             precio_bruto_total=VALUES(precio_bruto_total),
             cantidad_min=VALUES(cantidad_min),
             iva=VALUES(iva),
             costo=VALUES(costo),
             moneda=VALUES(moneda),
             oculto=VALUES(oculto),
             actualizado_at=VALUES(actualizado_at)";
        $db->query($sql_precio);
        $updated++;
    }

    $sql_hist = "INSERT INTO `{$prefix}competencia_precios_historial`
        (producto_id, snapshot_fecha, precio, precio_lista, precio_bruto_unitario, precio_bruto_total,
         cantidad_min, iva, moneda)
        SELECT pr.producto_id, " . sql_escape($db, $today) . ", pr.precio, pr.precio_lista,
               pr.precio_bruto_unitario, pr.precio_bruto_total, pr.cantidad_min, pr.iva, pr.moneda
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
         moneda=VALUES(moneda)";
    $db->query($sql_hist);
    $historial_rows = $db->affected_rows;

    // Conservar hoy + todos los 01 y 16; borrar el resto de días intermedios.
    $sql_prune = "DELETE h FROM `{$prefix}competencia_precios_historial` h
        INNER JOIN `{$prefix}competencia_productos` cp ON cp.id = h.producto_id
        WHERE cp.fuente_id = {$fuente_id}
          AND h.snapshot_fecha <> " . sql_escape($db, $today) . "
          AND DAY(h.snapshot_fecha) NOT IN (1, 16)";
    $db->query($sql_prune);
    $historial_pruned = $db->affected_rows;

    $db->commit();
    $db->close();

    log_msg("OK productos_api={$updated} productos_nuevos≈{$created} historial_upsert={$historial_rows} historial_borrados={$historial_pruned} en " .
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
