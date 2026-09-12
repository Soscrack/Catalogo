<?php
/**
 * Limpia ítems clonados por carrera de merge XML.
 *
 *   php tests/repair_cloned_invoice_items.php
 *   php tests/repair_cloned_invoice_items.php --apply
 *
 * @package Riverso_POS
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Solo CLI\n");
    exit(1);
}

$wp_load = getenv('WP_LOAD') ?: '/var/www/vhosts/riverso.cl/httpdocs/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "No se encontró wp-load.php. Defina WP_LOAD=\n");
    exit(1);
}

require $wp_load;

if (!class_exists('Riverso_Invoice_Module')) {
    fwrite(STDERR, "Riverso_Invoice_Module no disponible\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$mod = new Riverso_Invoice_Module();
$result = $mod->dedupe_cloned_factura_items(0, $apply);

echo ($apply ? "=== APPLY ===\n" : "=== DRY-RUN ===\n");
echo 'facturas=' . (int) ($result['facturas'] ?? 0)
    . ' deleted=' . (int) ($result['deleted'] ?? 0) . "\n";
foreach ($result['details'] ?? [] as $row) {
    echo sprintf(
        "  folio %s (#%d) keep=%d remove=%d ids=%s\n",
        $row['folio'] ?? '',
        (int) ($row['factura_id'] ?? 0),
        (int) ($row['kept'] ?? 0),
        (int) ($row['deleted'] ?? 0),
        implode(',', $row['removed_ids'] ?? [])
    );
}

if (!$apply) {
    echo "Para aplicar: php tests/repair_cloned_invoice_items.php --apply\n";
}
