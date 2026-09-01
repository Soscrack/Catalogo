<?php
/**
 * Export Excel catálogo TPV local (CRUD por planilla).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
?>
<div class="wrap riverso-tpv-export-wrap">
    <h1><?php esc_html_e('Export catálogo TPV', 'riverso-pos'); ?></h1>
    <p class="description">
        Genera un archivo <code>.xlsx</code> con dos hojas (<strong>Productos</strong> y <strong>CodigosBarra</strong>)
        para el programa TPV local (ventas sin conexión). Incluye nombres, marcas, proveedores, precios y códigos de barra.
        <strong>No incluye inventario.</strong> La columna <strong>Accion</strong> indica CREAR, EDITAR o ELIMINAR respecto al último lote aplicado.
    </p>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;">
        <h2 style="margin-top:0;">Opciones de export</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="te-sku">Filtrar SKU</label></th>
                <td>
                    <input type="text" id="te-sku" class="regular-text" placeholder="Ej: 222433 o 222433,29068">
                    <p class="description">Opcional. Uno o varios SKUs separados por coma.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Alcance</th>
                <td>
                    <label><input type="checkbox" id="te-only-changed"> Solo cambios desde último lote aplicado</label>
                    <p class="description">Si no hay lote aplicado previo, la primera descarga exporta todo como CREAR.</p>
                </td>
            </tr>
        </table>
        <p>
            <button type="button" class="button" id="te-preview-btn">Vista previa</button>
            <button type="button" class="button button-primary" id="te-download-btn" disabled>Descargar Excel</button>
        </p>
    </div>

    <div id="te-preview-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;">
        <h2 style="margin-top:0;">Vista previa</h2>
        <div id="te-preview-summary"></div>
        <div id="te-preview-samples" style="margin-top:12px;"></div>
    </div>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;">
        <h2 style="margin-top:0;">Lotes recientes</h2>
        <p class="description">Tras importar el Excel en el TPV local, marca el lote como aplicado para calcular el delta en la próxima exportación.</p>
        <table class="widefat striped" id="te-batches-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Productos</th>
                    <th>Códigos</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="te-batches-body">
                <tr><td colspan="6">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function($){
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    let lastPreview = null;

    function esc(s) {
        return $('<div/>').text(s == null ? '' : s).html();
    }

    function filters() {
        return {
            sku: $('#te-sku').val(),
            only_changed: $('#te-only-changed').is(':checked') ? 1 : 0
        };
    }

    function accionStyle(accion) {
        if (accion === 'CREAR') return 'color:#2271b1;font-weight:600;';
        if (accion === 'EDITAR') return 'color:#00a32a;font-weight:600;';
        if (accion === 'ELIMINAR') return 'color:#d63638;font-weight:600;';
        return '';
    }

    function renderSamples(data) {
        let html = '';
        if ((data.sample_productos || []).length) {
            html += '<h3>Productos (muestra)</h3><table class="widefat striped"><thead><tr><th>Acción</th><th>SKU</th><th>Nombre</th></tr></thead><tbody>';
            (data.sample_productos || []).forEach(function(row) {
                html += '<tr><td><span style="' + accionStyle(row.accion) + '">' + esc(row.accion) + '</span></td><td><code>' + esc(row.sku) + '</code></td><td>' + esc(row.nombre) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        if ((data.sample_barcodes || []).length) {
            html += '<h3 style="margin-top:14px;">Códigos de barra (muestra)</h3><table class="widefat striped"><thead><tr><th>Acción</th><th>SKU</th><th>Código</th></tr></thead><tbody>';
            (data.sample_barcodes || []).forEach(function(row) {
                html += '<tr><td><span style="' + accionStyle(row.accion) + '">' + esc(row.accion) + '</span></td><td><code>' + esc(row.sku) + '</code></td><td><code>' + esc(row.codigo) + '</code></td></tr>';
            });
            html += '</tbody></table>';
        }
        $('#te-preview-samples').html(html || '<p class="description">Sin muestras.</p>');
    }

    function loadBatches() {
        $.post(ajaxurl, { action: 'riverso_tpv_export_batches', nonce }, function(r) {
            const $body = $('#te-batches-body').empty();
            if (!r.success || !r.data.batches || !r.data.batches.length) {
                $body.append('<tr><td colspan="6">Sin lotes aún.</td></tr>');
                return;
            }
            r.data.batches.forEach(function(b) {
                let actions = '';
                if (b.can_mark_applied) {
                    actions = '<button type="button" class="button button-small te-mark-applied" data-id="' + b.id + '">Marcar aplicado</button>';
                }
                const estado = b.estado === 'aplicado'
                    ? '<span style="color:green;">Aplicado</span>'
                    : esc(b.estado || 'generado');
                $body.append(
                    '<tr>' +
                    '<td>' + b.id + '</td>' +
                    '<td>' + b.total_productos + '</td>' +
                    '<td>' + b.total_barcodes + '</td>' +
                    '<td>' + estado + '</td>' +
                    '<td>' + esc(b.created_at || '') + '</td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>'
                );
            });
        });
    }

    loadBatches();

    $('#te-preview-btn').on('click', function() {
        $.post(ajaxurl, Object.assign({ action: 'riverso_tpv_export_preview', nonce }, filters()), function(r) {
            $('#te-preview-panel').show();
            if (!r.success) {
                $('#te-preview-summary').html('<p style="color:#b32d2e;">' + esc(r.data && r.data.message ? r.data.message : 'Error') + '</p>');
                $('#te-download-btn').prop('disabled', true);
                return;
            }
            lastPreview = r.data;
            const d = r.data;
            let html = '';
            if (d.total <= 0) {
                html += '<p style="color:#b32d2e;margin:0 0 10px;"><strong>Sin filas para exportar.</strong> ' + esc(d.empty_hint || '') + '</p>';
            }
            html += '<ul style="margin:0;">' +
                '<li><strong>Productos:</strong> ' + d.total_productos + ' (CREAR ' + d.create_productos + ', EDITAR ' + d.update_productos + ', ELIMINAR ' + d.delete_productos + ')</li>' +
                '<li><strong>Códigos de barra:</strong> ' + d.total_barcodes + ' (CREAR ' + d.create_barcodes + ', EDITAR ' + d.update_barcodes + ', ELIMINAR ' + d.delete_barcodes + ')</li>' +
                '<li><strong>Total filas:</strong> ' + d.total + '</li>' +
                '</ul>';
            if (!d.has_last_applied_batch) {
                html += '<p class="description" style="margin-top:10px;">Primera exportación: todas las filas activas saldrán como <strong>CREAR</strong>.</p>';
            }
            $('#te-preview-summary').html(html);
            renderSamples(d);
            $('#te-download-btn').prop('disabled', !d.can_download || d.total <= 0);
        });
    });

    $('#te-download-btn').on('click', function() {
        if (!lastPreview || !lastPreview.can_download || lastPreview.total <= 0) {
            alert('Primero ejecuta Vista previa con al menos una fila exportable.');
            return;
        }
        const $form = $('<form method="post" action="' + ajaxurl + '" target="_blank"></form>');
        const f = Object.assign(filters(), { action: 'riverso_tpv_export_download', nonce });
        Object.keys(f).forEach(function(k) {
            $form.append($('<input type="hidden">').attr('name', k).val(f[k]));
        });
        $('body').append($form);
        $form.submit();
        $form.remove();
        setTimeout(loadBatches, 1500);
    });

    $(document).on('click', '.te-mark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Confirmas que importaste este lote en el TPV local?')) return;
        $.post(ajaxurl, { action: 'riverso_tpv_export_mark_applied', nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
        });
    });
})(jQuery);
</script>
