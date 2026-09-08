<?php
/**
 * Template: Mapeo manual (Proveedor + Código → SKU)
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap riverso-manual-mapping-page">
    <h1>
        <span class="dashicons dashicons-admin-links"></span>
        Mapeo manual
    </h1>
    <p class="description">
        Vinculá un par <strong>(Proveedor, Código-Proveedor)</strong> a un SKU local.
        Aplica a cotizaciones, facturas (incl. folios antiguos) e historial de costos de ese código.
    </p>

    <?php
    $riverso_manual_mapping_context = 'admin';
    include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/manual-mapping-app.php';
    ?>
</div>
