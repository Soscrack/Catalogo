<?php
/**
 * Diagnóstico y reparación del folio 47970 (stub SII/FACTO + escaneo con detalle).
 *
 * Uso en el servidor (después del deploy del fix de merge):
 *   php tests/repair_folio_47970.php              # dry-run (solo informa)
 *   php tests/repair_folio_47970.php --apply      # aplica merge y unifica
 *   php tests/repair_folio_47970.php --folio=47970 --apply
 *
 * @package Riverso_POS
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

$wp_load = getenv('WP_LOAD') ?: '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
if (!file_exists($wp_load)) {
    // Fallback relativo al repo (dev)
    $alt = dirname(__DIR__) . '/wp-load.php';
    if (file_exists($alt)) {
        $wp_load = $alt;
    }
}
if (!file_exists($wp_load)) {
    fwrite(STDERR, "No se encontró wp-load.php. Defina WP_LOAD=\n");
    exit(1);
}

require $wp_load;

$apply = in_array('--apply', $argv, true);
$folio = '47970';
foreach ($argv as $arg) {
    if (strpos($arg, '--folio=') === 0) {
        $folio = substr($arg, strlen('--folio='));
    }
}

global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';

echo "=== Folio {$folio} (" . ($apply ? 'APPLY' : 'DRY-RUN') . ") ===\n";

$facturas = $wpdb->get_results($wpdb->prepare(
    "SELECT id, folio, tipo_dte, rut_emisor, origen_ingreso, monto_neto, monto_iva, monto_total,
            estado, items_total, modo_ingreso, documento_subtipo, xml_hash, created_at
     FROM {$prefix}facturas
     WHERE CAST(folio AS CHAR) = %s OR folio LIKE %s
     ORDER BY id ASC",
    $folio,
    '%' . $wpdb->esc_like($folio) . '%'
), ARRAY_A);

echo "facturas: " . count($facturas) . "\n";
foreach ($facturas as $r) {
    $fid = (int) $r['id'];
    $stub = function_exists('riverso_factura_db_is_sii_rescued_stub')
        && riverso_factura_db_is_sii_rescued_stub($fid);
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . " stub=" . ($stub ? 'yes' : 'no') . "\n";
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, numero_linea, nombre, descripcion, cantidad, codigo_proveedor, precio_unitario
         FROM {$prefix}factura_items WHERE factura_id = %d ORDER BY numero_linea LIMIT 30",
        $fid
    ), ARRAY_A);
    echo "  items=" . count($items) . "\n";
    foreach ($items as $it) {
        echo '  - L' . ($it['numero_linea'] ?? '?') . ' '
            . ($it['nombre'] ?? '') . ' | ' . ($it['descripcion'] ?? '')
            . ' cant=' . ($it['cantidad'] ?? '') . ' cod=' . ($it['codigo_proveedor'] ?? '') . "\n";
    }
}

$scans = $wpdb->get_results($wpdb->prepare(
    "SELECT id, folio, rut_emisor, tipo_dte, estado_revision, factura_id, monto_total, archivo_id, created_at
     FROM {$prefix}documentos_escaneados
     WHERE CAST(folio AS CHAR) = %s OR folio LIKE %s
     ORDER BY id ASC",
    $folio,
    '%' . $wpdb->esc_like($folio) . '%'
), ARRAY_A);

echo "scans: " . count($scans) . "\n";
foreach ($scans as $s) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
}

if (empty($facturas)) {
    echo "Sin facturas para folio {$folio}. Nada que reparar.\n";
    exit(0);
}

// Elegir canónica: preferir origen facto/xml con stub; si hay varias, la más antigua con xml_hash
$canonical = null;
$duplicates = [];
foreach ($facturas as $r) {
    $fid = (int) $r['id'];
    $stub = function_exists('riverso_factura_db_is_sii_rescued_stub')
        && riverso_factura_db_is_sii_rescued_stub($fid);
    $origen = $r['origen_ingreso'] ?? '';
    $score = 0;
    if (in_array($origen, ['facto', 'xml', 'ambos'], true)) {
        $score += 10;
    }
    if (!empty($r['xml_hash'])) {
        $score += 5;
    }
    if ($stub) {
        $score += 3;
    }
    $r['_score'] = $score;
    $r['_stub'] = $stub;
    if ($canonical === null || $score > $canonical['_score']
        || ($score === $canonical['_score'] && (int) $r['id'] < (int) $canonical['id'])) {
        if ($canonical) {
            $duplicates[] = $canonical;
        }
        $canonical = $r;
    } else {
        $duplicates[] = $r;
    }
}

echo "\nCanónica elegida: #" . $canonical['id']
    . " origen=" . ($canonical['origen_ingreso'] ?? '')
    . " stub=" . (!empty($canonical['_stub']) ? 'yes' : 'no') . "\n";
if ($duplicates) {
    echo "Duplicadas a consolidar: " . implode(', ', array_map(static function ($d) {
        return '#' . $d['id'];
    }, $duplicates)) . "\n";
}

// Escaneo con detalle (normalized con ítems reales)
$scan_detail = null;
foreach ($scans as $s) {
    $full = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}documentos_escaneados WHERE id = %d",
        (int) $s['id']
    ), ARRAY_A);
    if (!$full) {
        continue;
    }
    $payload = json_decode($full['datos_json'] ?? '{}', true) ?: [];
    $normalized = $payload['normalized'] ?? null;
    if (!$normalized || empty($normalized['items']) || !is_array($normalized['items'])) {
        continue;
    }
    if (function_exists('riverso_factura_data_is_sii_rescued_stub')
        && riverso_factura_data_is_sii_rescued_stub($normalized)) {
        continue;
    }
    $scan_detail = $full;
    $scan_detail['_normalized'] = $normalized;
    $scan_detail['_payload'] = $payload;
    break;
}

if (!$scan_detail) {
    echo "No hay escaneo con detalle de ítems para aplicar.\n";
    exit(0);
}

echo "Escaneo con detalle: #" . $scan_detail['id']
    . " estado=" . $scan_detail['estado_revision']
    . " items=" . count($scan_detail['_normalized']['items']) . "\n";

$canonical_id = (int) $canonical['id'];
$is_stub = !empty($canonical['_stub']);

if (!$is_stub) {
    echo "La factura canónica ya tiene detalle (no es stub). Solo se vinculará el escaneo como respaldo.\n";
}

if (!$apply) {
    echo "\nDRY-RUN: para aplicar ejecute con --apply\n";
    echo "Acciones previstas:\n";
    if ($is_stub) {
        echo "  - merge_scan_into_factura(#{$canonical_id}, escaneo #{$scan_detail['id']})\n";
    }
    echo "  - marcar escaneo #{$scan_detail['id']} como confirmado → factura #{$canonical_id}\n";
    echo "  - origen_ingreso → ambos\n";
    foreach ($duplicates as $d) {
        echo "  - consolidar/eliminar duplicada #{$d['id']} → #{$canonical_id} (si mismo DTE y sin recepción)\n";
    }
    exit(0);
}

// APPLY
if (!class_exists('Riverso_Invoice_Module')) {
    fwrite(STDERR, "Riverso_Invoice_Module no disponible\n");
    exit(1);
}

$invoices = new Riverso_Invoice_Module();
$options = [
    'documento_subtipo' => $canonical['documento_subtipo'] ?: 'productos',
    'modo_ingreso'      => $canonical['modo_ingreso'] ?: 'solo_costos',
    'tipo_confirmado'   => 1,
];

if ($is_stub) {
    $merge = $invoices->merge_scan_into_factura($canonical_id, $scan_detail['_normalized'], $options);
    if (is_wp_error($merge)) {
        fwrite(STDERR, 'ERROR merge: ' . $merge->get_error_message() . "\n");
        exit(1);
    }
    echo "Merge OK: " . ($merge['message'] ?? 'items_updated') . "\n";
} else {
    echo "Skip merge (factura no stub)\n";
}

// Adjuntar PDF del escaneo
$archivo = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$prefix}documentos_archivos WHERE id = %d",
    (int) $scan_detail['archivo_id']
), ARRAY_A);
if ($archivo && !empty($archivo['r2_key_original']) && function_exists('riverso_factura_attach_scan_meta')) {
    $raw = $scan_detail['_payload']['raw'] ?? [];
    riverso_factura_attach_scan_meta(
        $canonical_id,
        $archivo['r2_key_original'],
        is_array($raw) ? $raw : [],
        $archivo['archivo_hash'] ?? ''
    );
    echo "PDF adjuntado a factura #{$canonical_id}\n";
}

if (function_exists('riverso_factura_mark_scan_attached')) {
    riverso_factura_mark_scan_attached($canonical_id);
}

$wpdb->update("{$prefix}documentos_escaneados", [
    'estado_revision' => 'confirmado',
    'factura_id'      => $canonical_id,
], ['id' => (int) $scan_detail['id']]);
echo "Escaneo #{$scan_detail['id']} → confirmado\n";

// Re-apuntar otros escaneos del folio a la canónica
foreach ($scans as $s) {
    if ((int) $s['id'] === (int) $scan_detail['id']) {
        continue;
    }
    $wpdb->update("{$prefix}documentos_escaneados", [
        'factura_id' => $canonical_id,
    ], ['id' => (int) $s['id']]);
}

// Consolidar facturas duplicadas del mismo DTE lógico (mismo folio + RUT dígitos)
$canon_rut = function_exists('riverso_factura_rut_digits')
    ? riverso_factura_rut_digits($canonical['rut_emisor'] ?? '')
    : preg_replace('/[^0-9K]/', '', strtoupper((string) ($canonical['rut_emisor'] ?? '')));
$canon_tipo = (int) ($canonical['tipo_dte'] ?? 0);

foreach ($duplicates as $d) {
    $dup_id = (int) $d['id'];
    $dup_rut = function_exists('riverso_factura_rut_digits')
        ? riverso_factura_rut_digits($d['rut_emisor'] ?? '')
        : preg_replace('/[^0-9K]/', '', strtoupper((string) ($d['rut_emisor'] ?? '')));
    if ($dup_rut !== $canon_rut || (int) ($d['tipo_dte'] ?? 0) !== $canon_tipo) {
        echo "Skip consolidar #{$dup_id}: RUT/tipo distinto\n";
        continue;
    }

    $received = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(cantidad_recibida), 0) FROM {$prefix}factura_items WHERE factura_id = %d",
        $dup_id
    ));
    if ($received > 0) {
        echo "Skip borrar #{$dup_id}: tiene recepción (cantidad_recibida={$received})\n";
        continue;
    }

    // Re-apuntar proceso de precios / escaneos / maps FACTO
    $proc_table = $prefix . 'precio_folio_proceso';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $proc_table)) === $proc_table) {
        $wpdb->update($proc_table, ['factura_id' => $canonical_id], ['factura_id' => $dup_id]);
    }
    $wpdb->update("{$prefix}documentos_escaneados", ['factura_id' => $canonical_id], ['factura_id' => $dup_id]);
    $map_table = $prefix . 'facto_inbox_map';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map_table)) === $map_table) {
        $wpdb->update($map_table, ['factura_id' => $canonical_id], ['factura_id' => $dup_id]);
    }

    $item_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$prefix}factura_items WHERE factura_id = %d",
        $dup_id
    ));
    if ($item_ids) {
        $in = implode(',', array_map('intval', $item_ids));
        $wpdb->query(
            "DELETE FROM {$prefix}tareas
             WHERE referencia_tipo = 'factura_item' AND referencia_id IN ({$in})"
        );
    }
    $wpdb->delete("{$prefix}cost_history", [
        'source_type' => 'invoice',
        'source_document_id' => $dup_id,
    ], ['%s', '%d']);
    $wpdb->delete("{$prefix}factura_items", ['factura_id' => $dup_id], ['%d']);
    $wpdb->delete("{$prefix}facturas", ['id' => $dup_id], ['%d']);
    echo "Duplicada #{$dup_id} eliminada (consolidada en #{$canonical_id})\n";
}

// Verificar resultado
$stub_after = function_exists('riverso_factura_db_is_sii_rescued_stub')
    && riverso_factura_db_is_sii_rescued_stub($canonical_id);
$origen = $wpdb->get_var($wpdb->prepare(
    "SELECT origen_ingreso FROM {$prefix}facturas WHERE id = %d",
    $canonical_id
));
$items_n = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = %d",
    $canonical_id
));
$left = $wpdb->get_results($wpdb->prepare(
    "SELECT id, origen_ingreso, rut_emisor FROM {$prefix}facturas
     WHERE CAST(folio AS CHAR) = %s OR folio LIKE %s",
    $folio,
    '%' . $wpdb->esc_like($folio) . '%'
), ARRAY_A);

echo "\nResultado: factura #{$canonical_id} origen={$origen} items={$items_n} stub="
    . ($stub_after ? 'yes' : 'no') . "\n";
echo "Facturas restantes folio {$folio}: " . count($left) . "\n";
foreach ($left as $r) {
    echo '  #' . $r['id'] . ' origen=' . $r['origen_ingreso'] . ' rut=' . $r['rut_emisor'] . "\n";
}

echo "Done.\n";
