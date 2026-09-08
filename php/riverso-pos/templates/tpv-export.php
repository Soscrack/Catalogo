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
        para el programa TPV local (ventas sin conexión). Incluye nombres, precios (3 decimales) y códigos de barra.
        <strong>No incluye inventario.</strong> De una familia unitaria solo va el producto unitario; los códigos de barra de los hijos se asignan a ese unitario.
        Los códigos de barra salen solo como <strong>CREAR</strong>. Un cambio de SKU sale como <strong>CAMBIAR_SKU</strong> (SKU + SKU_Anterior).
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
                    <label><input type="checkbox" id="te-only-changed" checked> Solo cambios</label>
                    <p class="description">Compara contra el último lote aplicado o, si no hay, el catálogo TPV legacy. Omite filas sin cambios reales.</p>
                </td>
            </tr>
        </table>
        <div style="margin-top:8px;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#f0f6fc;border-radius:2px;">
            <p style="margin:0 0 10px;"><strong>Ver qué columnas cambian</strong></p>
            <p class="description" style="margin:0 0 12px;">
                Compara el catálogo actual con el último lote TPV aplicado (o el legacy) y muestra
                <strong>antes → después</strong> por columna (Nombre, Precio, SKU, Código de barra).
            </p>
            <p style="margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="button" class="button button-primary button-hero" id="te-view-changes-btn" style="font-size:14px;min-height:36px;">
                    Ver cambios
                </button>
                <button type="button" class="button" id="te-preview-btn">Vista previa (mismo cálculo)</button>
                <button type="button" class="button button-primary" id="te-download-btn" disabled>Descargar Excel</button>
            </p>
        </div>
        <p class="description" id="te-status-line" style="margin-top:8px;"></p>
    </div>

    <div id="te-preview-panel" class="card" style="max-width:1100px;padding:16px 20px;margin-top:16px;display:none;border-left:4px solid #2271b1;">
        <h2 style="margin-top:0;">Cambios detectados (columnas)</h2>
        <div id="te-preview-summary"></div>
        <div id="te-preview-toolbar" style="display:none;margin-top:12px;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="search" id="te-change-q" class="regular-text" placeholder="Buscar SKU, nombre o código" style="max-width:280px;">
            <span class="button-group">
                <button type="button" class="button button-primary te-accion-filter" data-accion="">Todos</button>
                <button type="button" class="button te-accion-filter" data-accion="CREAR">CREAR</button>
                <button type="button" class="button te-accion-filter" data-accion="EDITAR">EDITAR</button>
                <button type="button" class="button te-accion-filter" data-accion="CAMBIAR_SKU">CAMBIAR_SKU</button>
                <button type="button" class="button te-accion-filter" data-accion="ELIMINAR">ELIMINAR</button>
            </span>
        </div>
        <div id="te-preview-samples" style="margin-top:12px;"></div>
        <p style="margin-top:14px;">
            <button type="button" class="button button-primary" id="te-download-btn-2" disabled>Descargar Excel</button>
        </p>
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
    let previewAccion = '';

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
        if (accion === 'CAMBIAR_SKU') return 'color:#996800;font-weight:600;';
        return '';
    }

    function renderDiffs(row) {
        const diffs = row.diffs || [];
        if (diffs.length) {
            return '<table class="widefat" style="margin:0;background:transparent;border:0;box-shadow:none;"><tbody>' +
                diffs.map(function(d) {
                    const from = d.antes === '' || d.antes == null ? '—' : d.antes;
                    const to = d.despues === '' || d.despues == null ? '—' : d.despues;
                    return '<tr><td style="padding:2px 6px;width:90px;"><strong>' + esc(d.campo) + '</strong></td>' +
                        '<td style="padding:2px 6px;"><code>' + esc(from) + '</code> → <code>' + esc(to) + '</code></td></tr>';
                }).join('') +
                '</tbody></table>';
        }
        if (row.accion === 'CREAR') return '<span class="description">Nuevo en TPV</span>';
        if (row.accion === 'ELIMINAR') return '<span class="description">Se elimina del TPV</span>';
        if (row.accion === 'CAMBIAR_SKU') {
            return '<strong>SKU</strong>: <code>' + esc(row.sku_anterior || '—') + '</code> → <code>' + esc(row.sku || '—') + '</code>';
        }
        if (row.accion === 'EDITAR') return '<span class="description">Cambio detectado</span>';
        return '<span class="description">—</span>';
    }

    function rowMatches(row, q, accion) {
        if (accion && row.accion !== accion) return false;
        if (!q) return true;
        const hay = ((row.sku || '') + ' ' + (row.sku_anterior || '') + ' ' + (row.nombre || '') + ' ' + (row.codigo || '')).toLowerCase();
        return hay.indexOf(q) !== -1;
    }

    function renderChangeTable(title, rows, columns, truncated, totalChanged) {
        const q = ($('#te-change-q').val() || '').toLowerCase().trim();
        const filtered = (rows || []).filter(function(row) { return rowMatches(row, q, previewAccion); });
        let html = '<h3 style="margin-top:16px;">' + title + '</h3>';
        if (!filtered.length) {
            html += '<p class="description">Ninguna fila coincide con el filtro.</p>';
            return html;
        }
        html += '<div style="max-height:480px;overflow:auto;"><table class="widefat striped"><thead><tr>';
        columns.forEach(function(col) { html += '<th>' + col.label + '</th>'; });
        html += '</tr></thead><tbody>';
        filtered.forEach(function(row) {
            html += '<tr>';
            columns.forEach(function(col) { html += '<td>' + col.cell(row) + '</td>'; });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        if (truncated) {
            html += '<p class="description">Mostrando ' + rows.length + ' de ' + totalChanged + ' cambios. El Excel incluye el listado completo.</p>';
        }
        return html;
    }

    function setDownloadEnabled(enabled) {
        $('#te-download-btn, #te-download-btn-2').prop('disabled', !enabled);
    }

    function renderSamples(data) {
        const productCols = [
            { label: 'Acción', cell: function(row) { return '<span style="' + accionStyle(row.accion) + '">' + esc(row.accion) + '</span>'; } },
            { label: 'SKU', cell: function(row) { return '<code>' + esc(row.sku) + '</code>'; } },
            { label: 'SKU anterior', cell: function(row) { return row.sku_anterior ? ('<code>' + esc(row.sku_anterior) + '</code>') : '—'; } },
            { label: 'Nombre', cell: function(row) { return esc(row.nombre); } },
            { label: 'Precio', cell: function(row) { return esc(row.precio || '—'); } },
            { label: 'Columnas que cambian', cell: renderDiffs }
        ];
        const barcodeCols = [
            { label: 'Acción', cell: function(row) { return '<span style="' + accionStyle(row.accion) + '">' + esc(row.accion) + '</span>'; } },
            { label: 'SKU', cell: function(row) { return '<code>' + esc(row.sku) + '</code>'; } },
            { label: 'Código', cell: function(row) { return '<code>' + esc(row.codigo) + '</code>'; } },
            { label: 'Columnas que cambian', cell: renderDiffs }
        ];
        const products = data.preview_productos || [];
        const barcodes = data.preview_barcodes || [];
        const changedTotal = (data.changed_productos || 0) + (data.changed_barcodes || 0);
        let html = '';
        if (products.length || data.preview_productos_truncated) {
            html += renderChangeTable(
                'Productos',
                products,
                productCols,
                !!data.preview_productos_truncated,
                data.changed_productos || products.length
            );
        }
        if (barcodes.length || data.preview_barcodes_truncated) {
            html += renderChangeTable(
                'Códigos de barra',
                barcodes,
                barcodeCols,
                !!data.preview_barcodes_truncated,
                data.changed_barcodes || barcodes.length
            );
        }
        $('#te-preview-toolbar').css('display', changedTotal > 0 ? 'flex' : 'none');
        if (!html) {
            if ((data.total || 0) <= 0) {
                html = '<p class="description">' + esc(data.empty_hint || 'No hay filas para exportar con estos filtros.') + '</p>';
            } else {
                html = '<p class="description">Hay filas en el Excel, pero ninguna con cambio real respecto al baseline (solo EDITAR sin diff). Marca <strong>Solo cambios</strong> o revisa el resumen arriba.</p>';
            }
        }
        $('#te-preview-samples').html(html);
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
                } else if (b.blocked_by_newer) {
                    actions = '<span class="description">Hay un lote posterior ya aplicado</span>';
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

    function applyPreview(d) {
        lastPreview = d;
        const changedTotal = (d.changed_productos || 0) + (d.changed_barcodes || 0);
        const withheld = Array.isArray(d.withheld_family_pending) ? d.withheld_family_pending : [];
        const familyPending = d.skipped_family_pending || 0;
        let html = '';

        if (familyPending > 0) {
            html += '<div style="margin:0 0 12px;padding:12px 14px;border:1px solid #dba617;border-left:4px solid #dba617;background:#fff8e5;border-radius:2px;">';
            html += '<p style="margin:0 0 8px;color:#996800;"><strong>Productos creados pero no exportables a TPV</strong></p>';
            html += '<p class="description" style="margin:0 0 10px;">' +
                esc(d.empty_hint || ('Hay ' + familyPending + ' producto(s) retenidos: falta resolver si son familia.')) +
                '</p>';
            if (withheld.length) {
                html += '<table class="widefat striped" style="margin:0;"><thead><tr>' +
                    '<th>SKU</th><th>Nombre</th><th>Motivo</th><th></th></tr></thead><tbody>';
                withheld.forEach(function(row) {
                    const link = row.url
                        ? ('<a class="button button-small" href="' + esc(row.url) + '">Resolver tarea</a>')
                        : '—';
                    html += '<tr>' +
                        '<td><code>' + esc(row.sku || '') + '</code></td>' +
                        '<td>' + esc(row.nombre || '') + '</td>' +
                        '<td>' + esc(row.motivo || '¿Necesita familia?') + '</td>' +
                        '<td>' + link + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table>';
                if (familyPending > withheld.length) {
                    html += '<p class="description" style="margin:8px 0 0;">Mostrando ' + withheld.length +
                        ' de ' + familyPending + ' retenidos.</p>';
                }
            }
            html += '</div>';
        } else if (d.total <= 0) {
            html += '<p style="color:#b32d2e;margin:0 0 10px;"><strong>Sin filas para exportar.</strong> ' + esc(d.empty_hint || '') + '</p>';
        } else if (changedTotal <= 0 && $('#te-only-changed').is(':checked')) {
            html += '<p style="color:#856404;margin:0 0 10px;"><strong>Sin cambios reales</strong> respecto al baseline. Desmarca «Solo cambios» para ver/exportar el catálogo completo, o no hay nada nuevo que enviar al TPV.</p>';
        }

        html += '<ul style="margin:0;">' +
            '<li><strong>Cambios reales:</strong> ' + changedTotal +
                ' (productos ' + (d.changed_productos || 0) +
                ': CREAR ' + (d.create_productos || 0) +
                ', EDITAR ' + (d.update_productos || 0) +
                ', CAMBIAR_SKU ' + (d.change_sku_productos || 0) +
                ', ELIMINAR ' + (d.delete_productos || 0) +
                '; códigos ' + (d.changed_barcodes || 0) + ')</li>' +
            '<li><strong>Filas en Excel:</strong> productos ' + (d.total_productos || 0) + ', códigos ' + (d.total_barcodes || 0) + '</li>' +
            '</ul>';
        if ((d.skipped_family_pending || 0) + (d.skipped_family_children || 0) > 0) {
            html += '<p class="description" style="margin-top:10px;">Omitidos: ' +
                (d.skipped_family_pending || 0) + ' por tarea de familia pendiente, ' +
                (d.skipped_family_children || 0) + ' hijos de familia unitaria.</p>';
        }
        if (d.baseline === 'legacy') {
            html += '<p class="description" style="margin-top:10px;">Baseline: <strong>TPV legacy</strong> (' + (d.legacy_product_count || 0) + ' SKUs).</p>';
        } else if (d.has_last_applied_batch) {
            html += '<p class="description" style="margin-top:10px;">Baseline: último lote TPV marcado como aplicado.</p>';
        } else {
            html += '<p class="description" style="margin-top:10px;">Sin baseline: filas activas como <strong>CREAR</strong>.</p>';
        }
        $('#te-preview-summary').html(html);
        renderSamples(d);
        const canDl = !!(d.can_download && d.total > 0);
        setDownloadEnabled(canDl);
        $('#te-status-line').html(canDl
            ? ('Listo para descargar: <strong>' + d.total + '</strong> fila(s).')
            : (familyPending > 0
                ? '<span style="color:#996800;">Hay altas retenidas por familia pendiente — resolvé la tarea para poder CREAR en TPV.</span>'
                : 'Nada descargable con estos filtros.'));
    }

    function runPreview(opts) {
        opts = opts || {};
        const $btns = $('#te-preview-btn, #te-view-changes-btn').prop('disabled', true);
        $('#te-preview-panel').show();
        $('#te-preview-summary').html('<p class="description">Calculando cambios…</p>');
        $('#te-preview-samples').empty();
        $('#te-preview-toolbar').hide();
        $('#te-status-line').text('Calculando…');
        setDownloadEnabled(false);

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            dataType: 'json',
            timeout: 180000,
            data: Object.assign({ action: 'riverso_tpv_export_preview', nonce: nonce }, filters())
        }).done(function(r) {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) ? r.data.message : 'Error al calcular la vista previa';
                $('#te-preview-summary').html('<p style="color:#b32d2e;">' + esc(msg) + '</p>');
                $('#te-status-line').html('<span style="color:#b32d2e;">' + esc(msg) + '</span>');
                setDownloadEnabled(false);
                return;
            }
            applyPreview(r.data || {});
            if (opts.scroll !== false) {
                $('html, body').animate({ scrollTop: $('#te-preview-panel').offset().top - 60 }, 200);
            }
        }).fail(function(xhr) {
            let msg = 'No se pudo calcular la vista previa';
            if (xhr && xhr.statusText === 'timeout') {
                msg = 'La vista previa tardó demasiado (timeout). Reintenta o filtra por SKU.';
            } else if (xhr && xhr.status) {
                msg += ' (HTTP ' + xhr.status + ')';
            }
            $('#te-preview-summary').html('<p style="color:#b32d2e;">' + esc(msg) + '</p>');
            $('#te-status-line').html('<span style="color:#b32d2e;">' + esc(msg) + '</span>');
            setDownloadEnabled(false);
        }).always(function() {
            $btns.prop('disabled', false);
        });
    }

    function doDownload() {
        if (!lastPreview || !lastPreview.can_download || lastPreview.total <= 0) {
            alert('Primero pulsa «Ver cambios» o «Vista previa» con al menos una fila exportable.');
            return;
        }
        const $form = $('<form method="post" action="' + ajaxurl + '" target="_blank"></form>');
        const f = Object.assign(filters(), { action: 'riverso_tpv_export_download', nonce: nonce });
        Object.keys(f).forEach(function(k) {
            $form.append($('<input type="hidden">').attr('name', k).val(f[k]));
        });
        $('body').append($form);
        $form.submit();
        $form.remove();
        $('#te-status-line').text('Descarga iniciada. Si el navegador la bloqueó, permite ventanas emergentes.');
        setTimeout(loadBatches, 1500);
    }

    loadBatches();

    $('#te-view-changes-btn, #te-preview-btn').on('click', function() {
        runPreview({ scroll: true });
    });
    $('#te-only-changed').on('change', function() {
        runPreview({ scroll: false });
    });
    // Carga automática al abrir la página
    runPreview({ scroll: false });

    $('#te-change-q').on('input', function() {
        if (lastPreview) renderSamples(lastPreview);
    });

    $(document).on('click', '.te-accion-filter', function() {
        previewAccion = $(this).data('accion') || '';
        $('.te-accion-filter').removeClass('button-primary');
        $(this).addClass('button-primary');
        if (lastPreview) renderSamples(lastPreview);
    });

    $('#te-download-btn, #te-download-btn-2').on('click', doDownload);

    $(document).on('click', '.te-mark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Confirmas que importaste este lote en el TPV local?')) return;
        $.post(ajaxurl, { action: 'riverso_tpv_export_mark_applied', nonce: nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
            runPreview({ scroll: false });
        });
    });
})(jQuery);
</script>
