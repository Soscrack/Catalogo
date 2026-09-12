<?php
/**
 * Cotizaciones aprobadas recibidas — listado + análisis.
 */

if (!defined('ABSPATH')) {
    exit;
}

$received_quotes_url = admin_url('admin.php?page=riverso-pos-received-quotes');
$nonce = wp_create_nonce('riverso_pos_nonce');
?>
<div class="rce-approved-quotes" data-nonce="<?php echo esc_attr($nonce); ?>">
    <h3>Cotizaciones aprobadas</h3>
    <p class="description">Compara contra facturas o contra otra cotización aprobada. Si no hay match, se usa costo legacy.</p>
    <div class="filter-row" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;">
        <input type="search" id="aq-buscar" placeholder="Folio o proveedor…">
        <button type="button" class="button" id="aq-load">Buscar</button>
        <a class="button" href="<?php echo esc_url($received_quotes_url); ?>">Abrir cotizaciones</a>
    </div>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Proveedor</th>
                <th>Folio</th>
                <th>Fecha</th>
                <th>Fuente</th>
                <th>Ítems</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="aq-body"><tr><td colspan="8">Cargando…</td></tr></tbody>
    </table>
    <div id="aq-analysis" style="margin-top:16px;"></div>
</div>
<script>
jQuery(function($) {
    const ajaxurl = window.ajaxurl || <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    function loadApproved() {
        $.post(ajaxurl, {
            action: 'riverso_cost_list_approved_quotes',
            nonce: nonce,
            buscar: $('#aq-buscar').val()
        }, function(r) {
            const rows = (r.success && r.data) ? r.data : [];
            if (!rows.length) {
                $('#aq-body').html('<tr><td colspan="8">No hay cotizaciones aprobadas</td></tr>');
                return;
            }
            let html = '';
            rows.forEach(function(q) {
                html += '<tr>'
                    + '<td>' + q.id + '</td>'
                    + '<td>' + (q.proveedor_nombre || '—') + '</td>'
                    + '<td>' + (q.numero_documento || '—') + '</td>'
                    + '<td>' + (q.fecha_documento || '—') + '</td>'
                    + '<td>' + (q.tipo_fuente || '—') + '</td>'
                    + '<td>' + (q.items || 0) + '</td>'
                    + '<td>' + (q.total || 0) + '</td>'
                    + '<td><button type="button" class="button button-small aq-analyze" data-id="' + q.id + '">Analizar</button></td>'
                    + '</tr>';
            });
            $('#aq-body').html(html);
        });
    }
    $('#aq-load').on('click', loadApproved);
    $(document).on('click', '.aq-analyze', function() {
        const id = $(this).data('id');
        $.post(ajaxurl, { action: 'riverso_cost_analyze_quote', nonce: nonce, cotizacion_id: id, compare_base: 'auto' }, function(r) {
            if (!r.success) { $('#aq-analysis').text(r.data); return; }
            const d = r.data;
            let html = '<h4>Análisis cotización ' + (d.quote && d.quote.folio ? d.quote.folio : id) + '</h4>';
            html += '<table class="widefat striped"><thead><tr><th>Código</th><th>Nuevo</th><th>Factura ref</th><th>Cotización ref</th><th>Legacy</th><th>Δ%</th></tr></thead><tbody>';
            (d.rows || []).forEach(function(row) {
                const inv = row.prev_invoice;
                const q = row.prev_quote;
                const lg = row.legacy;
                html += '<tr class="' + (row.trend === 'subio' ? 'trend-up' : '') + '">'
                    + '<td>' + (row.codigo_proveedor || '') + '</td>'
                    + '<td>' + (row.costo_actual != null ? row.costo_actual : '—') + '</td>'
                    + '<td>' + (inv ? (inv.costo_unitario + ' · ' + (inv.folio || '') + ' · ' + (inv.fecha_emision || '')) : '—') + '</td>'
                    + '<td>' + (q ? (q.costo_unitario + ' · ' + (q.folio || '') + ' · ' + (q.fecha_emision || '')) : '—') + '</td>'
                    + '<td>' + (lg ? (lg.costo_unitario + ' · ' + (lg.fecha_emision || '')) : '—') + '</td>'
                    + '<td>' + (row.delta_pct != null ? row.delta_pct + '%' : '—') + '</td></tr>';
            });
            html += '</tbody></table>';
            html += '<p><button type="button" class="button" id="aq-claim" data-id="' + id + '">Preparar reclamo</button></p>';
            html += '<textarea id="aq-claim-text" rows="8" style="width:100%;display:none;"></textarea>';
            $('#aq-analysis').html(html);
        });
    });
    $(document).on('click', '#aq-claim', function() {
        const id = $(this).data('id');
        $.post(ajaxurl, { action: 'riverso_cost_claim_draft', nonce: nonce, cotizacion_id: id, compare_base: 'auto' }, function(r) {
            if (!r.success) { alert(r.data); return; }
            $('#aq-claim-text').val(r.data.draft).show();
        });
    });
    loadApproved();
});
</script>
