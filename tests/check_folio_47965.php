<?php
require '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';
$cols = $wpdb->get_col("SHOW COLUMNS FROM {$prefix}factura_items");
echo "item cols: " . implode(', ', $cols) . "\n";
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, folio, tipo_dte, rut_emisor, origen_ingreso, monto_neto, monto_iva, monto_total, estado, items_total
     FROM {$prefix}facturas WHERE folio = %s ORDER BY id DESC LIMIT 5",
    '47965'
), ARRAY_A);
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$prefix}factura_items WHERE factura_id = %d LIMIT 20",
        (int) $r['id']
    ), ARRAY_A);
    echo "items=" . count($items) . " stub=" . (riverso_factura_db_is_sii_rescued_stub((int)$r['id'])?'yes':'no') . "\n";
    foreach ($items as $it) {
        echo "  nombre=" . ($it['nombre'] ?? '') . " desc=" . ($it['descripcion'] ?? '') . " cant=" . ($it['cantidad'] ?? '') . "\n";
    }
}
$scans = $wpdb->get_results(
    "SELECT id, folio, rut_emisor, estado_revision, factura_id, tipo_dte
     FROM {$prefix}documentos_escaneados WHERE folio LIKE '%47965%'",
    ARRAY_A
);
echo "scans matching 47965: " . count($scans) . "\n";
foreach ($scans as $s) echo json_encode($s) . "\n";

// Also sample any factura with RESCATADO description
$stub_items = $wpdb->get_results(
    "SELECT fi.factura_id, f.folio, fi.nombre, fi.descripcion
     FROM {$prefix}factura_items fi
     JOIN {$prefix}facturas f ON f.id = fi.factura_id
     WHERE fi.nombre LIKE '%RESCATADO%' OR fi.descripcion LIKE '%RESCATADO%'
        OR fi.nombre LIKE '%SIN INFORMACION%' OR fi.descripcion LIKE '%SIN INFORMACION%'
     LIMIT 10",
    ARRAY_A
);
echo "stub-like items: " . count($stub_items) . "\n";
foreach ($stub_items as $s) echo json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
