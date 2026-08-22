<?php
/**
 * Partial WIP: Cotizaciones aprobadas recibidas
 */

if (!defined('ABSPATH')) {
    exit;
}

$received_quotes_url = admin_url('admin.php?page=riverso-pos-received-quotes');
?>
<div class="rce-wip-block">
    <span class="rce-wip-badge">En desarrollo</span>
    <h3>Cotizaciones Aprobadas Recibidas</h3>
    <p>
        Aquí se listarán las cotizaciones de proveedores en estado
        <strong>aprobada</strong> (<code>approved</code>) desde
        <code>wp_riverso_cotizaciones_recibidas</code>, con sus ítems y costos.
        Por ahora puedes gestionarlas en la pantalla de Cotizaciones Recibidas.
    </p>
    <a class="button button-secondary" href="<?php echo esc_url($received_quotes_url); ?>" target="_blank" rel="noopener">
        Abrir Cotizaciones Recibidas
    </a>
</div>
<style>
.rce-wip-block {
    background: #fff;
    border: 1px dashed #c3c4c7;
    border-radius: 6px;
    padding: 24px 20px;
    text-align: center;
    max-width: 700px;
}
.rce-wip-badge {
    display: inline-block;
    background: #fcf0e3;
    color: #996800;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 10px;
    border-radius: 10px;
    margin-bottom: 10px;
}
.rce-wip-block h3 { margin: 0 0 8px; }
.rce-wip-block p { color: #646970; margin: 0 0 14px; }
.rce-wip-block .button {
    display: inline-block;
    padding: 6px 12px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #f6f7f7;
    color: #1d2327;
    text-decoration: none;
    font-size: 13px;
}
</style>
