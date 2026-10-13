<?php
/**
 * Portal local sin WordPress, para revisar la cotización de venta.
 * php -S 127.0.0.1:8765 php/riverso-pos/dev/server.php
 */

declare(strict_types=1);

define('RIVERSO_POS_DEV', true);
require dirname(__DIR__) . '/riverso-pos.php';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($uri, '/assets/')) {
    $path = dirname(__DIR__) . $uri;
    if (!is_file($path)) {
        http_response_code(404);
        echo 'No encontrado';
        exit;
    }
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($ext === 'css' ? 'text/css' : 'text/javascript') . '; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

$stamp = RIVERSO_POS_VERSION . '-p2';
$db_path = sys_get_temp_dir() . '/riverso-cotizaciones-preview.sqlite';
$stamp_path = $db_path . '.stamp';
if (!is_file($stamp_path) || file_get_contents($stamp_path) !== $stamp) {
    if (is_file($db_path)) {
        unlink($db_path);
    }
    file_put_contents($stamp_path, $stamp);
}

$pdo = new PDO('sqlite:' . $db_path);
$db = new Riverso_POS_Pdo_Database($pdo, 'wp_');
riverso_pos_migration_runner($db)->migrate();
$reader = new Riverso_POS_Memory_Catalog_Reader(require __DIR__ . '/catalog-seed.php');
$module = riverso_pos_customer_quote_module($db, $reader, false);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $module->dispatch_ajax();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$module->render([
    'ajaxUrl' => '/',
    'nonce' => 'dev',
    'assetBase' => '/assets',
    'standalone' => true,
    'currentUserName' => 'Vendedor local',
]);
