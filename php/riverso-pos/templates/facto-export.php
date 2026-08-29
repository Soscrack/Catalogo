<?php
/**
 * Export Excel a FACTO (CRUD por planilla).
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$chunk_size = Riverso_Facto_Export_Service::CHUNK_SIZE;
?>
<div class="wrap riverso-facto-export-wrap">
    <h1><?php esc_html_e('Export Excel a FACTO', 'riverso-pos'); ?></h1>
    <p class="description">
        Genera archivos <code>.xlsx</code> en el formato de importación de FACTO.
        Máximo <?php echo (int) $chunk_size; ?> productos por archivo.
        El modo <strong>Reemplazar</strong> solo está disponible si el catálogo completo cabe en un solo archivo.
    </p>

    <div id="fe-pending-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;border-left:4px solid #d63638;">
        <h2 style="margin-top:0;">Pendientes de export a FACTO</h2>
        <p id="fe-pending-message" class="description"></p>
        <div id="fe-pending-samples" style="margin-top:10px;"></div>
        <p style="margin-top:12px;">
            <button type="button" class="button button-primary" id="fe-export-pending-btn">Exportar solo pendientes</button>
        </p>
    </div>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;">
        <h2 style="margin-top:0;">Opciones de export</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="fe-modo">Modo FACTO</label></th>
                <td>
                    <select id="fe-modo" class="regular-text">
                        <option value="update_only">Solo actualizar (SKUs ya en FACTO)</option>
                        <option value="upsert" selected>Agregar y actualizar</option>
                        <option value="replace">Reemplazar (catálogo completo ≤ <?php echo (int) $chunk_size; ?>)</option>
                    </select>
                    <p class="description" id="fe-modo-help"></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="fe-sku">Filtrar SKU</label></th>
                <td>
                    <input type="text" id="fe-sku" class="regular-text" placeholder="Ej: 222433 o 222433,29068">
                    <p class="description">Opcional. Uno o varios SKUs separados por coma (útil para prueba piloto).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Alcance</th>
                <td>
                    <label><input type="checkbox" id="fe-include-archived"> Incluir archivados</label><br>
                    <label><input type="checkbox" id="fe-pending-only"> Solo pendientes de export a FACTO</label><br>
                    <label><input type="checkbox" id="fe-hydrate-facto" checked> Completar desde FACTO (precio, categoría; stock mínimo local; stock total Riverso → Bodega general)</label><br>
                    <label><input type="checkbox" id="fe-only-changed"> Solo filas cambiadas desde último lote aplicado</label><br>
                    <label><input type="checkbox" id="fe-include-stock"> Incluir stock (stock total Riverso en Bodega general; otras bodegas vacías salvo hidratación FACTO)</label>
                    <p class="description">Las columnas de bodega van vacías salvo que marques «Incluir stock» o «Completar desde FACTO». Bodega general usa el stock total inventariado en Riverso.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="fe-tanda">Tanda</label></th>
                <td>
                    <input type="number" id="fe-tanda" min="1" value="1" class="small-text">
                    <span id="fe-tandas-label" class="description"></span>
                </td>
            </tr>
        </table>
        <p>
            <button type="button" class="button" id="fe-preview-btn">Vista previa</button>
            <button type="button" class="button button-primary" id="fe-download-btn" disabled>Descargar Excel</button>
        </p>
    </div>

    <div id="fe-preview-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;">
        <h2 style="margin-top:0;">Vista previa</h2>
        <div id="fe-preview-summary"></div>
        <div id="fe-preview-errors" style="margin-top:12px;color:#b32d2e;"></div>
        <div id="fe-preview-warnings" style="margin-top:8px;color:#856404;"></div>
    </div>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;">
        <h2 style="margin-top:0;">Lotes recientes</h2>
        <p class="description">Tras importar el Excel en FACTO, marca el lote como aplicado para trazabilidad e incremental.</p>
        <table class="widefat striped" id="fe-batches-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Modo</th>
                    <th>Tanda</th>
                    <th>Filas</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="fe-batches-body">
                <tr><td colspan="7">Cargando…</td></tr>
            </tbody>
        </table>
    </div>

    <div id="fe-batch-diff-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;">
        <h2 style="margin-top:0;">Detalle del lote <span id="fe-diff-batch-label"></span></h2>
        <div id="fe-batch-diff-summary" class="description"></div>
        <div id="fe-batch-diff-table" style="margin-top:12px;max-height:420px;overflow:auto;"></div>
        <p style="margin-top:12px;">
            <button type="button" class="button" id="fe-batch-diff-close">Cerrar</button>
        </p>
    </div>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;background:#fff8e1;border-left:4px solid #f9a825;">
        <h2 style="margin-top:0;">Piloto SKU 222433</h2>
        <ol style="margin-left:18px;">
            <li>En <a href="<?php echo esc_url(admin_url('admin.php?page=riverso-pos-products')); ?>">Productos</a>, abrir SKU <strong>222433</strong> y confirmar: IVA <em>afecto</em>, precio asignado, categoría/marca si aplica.</li>
            <li>Filtrar SKU <code>222433</code>, modo <em>Solo actualizar</em>, vista previa y descargar.</li>
            <li>Importar en FACTO con <strong>Solo actualizar</strong>.</li>
            <li>Re-exportar desde FACTO y comparar con <code>tools/compare_facto_export.py</code>.</li>
        </ol>
    </div>
</div>

<script>
(function($){
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    let lastPreview = null;
    let lastBatchId = null;
    let lastPendingData = null;

    const modoHelp = {
        update_only: 'Actualiza productos que ya tienen mapa Riverso ↔ FACTO.',
        upsert: 'Crea productos nuevos y actualiza los existentes. Use tandas si supera <?php echo (int) $chunk_size; ?> filas.',
        replace: 'Reemplaza todo el catálogo FACTO por el del archivo. Bloqueado si hay más de <?php echo (int) $chunk_size; ?> productos.'
    };

    function filters() {
        return {
            modo: $('#fe-modo').val(),
            sku: $('#fe-sku').val(),
            tanda: $('#fe-tanda').val() || 1,
            include_archived: $('#fe-include-archived').is(':checked') ? 1 : 0,
            include_stock: $('#fe-include-stock').is(':checked') ? 1 : 0,
            only_changed: $('#fe-only-changed').is(':checked') ? 1 : 0,
            pending_only: $('#fe-pending-only').is(':checked') ? 1 : 0,
            hydrate_from_facto: $('#fe-hydrate-facto').is(':checked') ? 1 : 0
        };
    }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : s).html();
    }

    function renderPending(data) {
        const total = parseInt(data.pending_total || 0, 10);
        if (total <= 0) {
            $('#fe-pending-panel').hide();
            return;
        }
        $('#fe-pending-panel').show();
        $('#fe-pending-message').text(data.message || '');
        let html = '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>SKU</th><th>Nombre</th><th>Marca</th></tr></thead><tbody>';
        (data.samples || []).forEach(function(row) {
            html += '<tr><td><code>' + esc(row.sku) + '</code></td><td>' + esc(row.nombre) + '</td><td>' + esc(row.marca || '—') + '</td></tr>';
        });
        html += '</tbody></table>';
        if (total > (data.samples || []).length) {
            html += '<p class="description">… y ' + (total - data.samples.length) + ' más.</p>';
        }
        $('#fe-pending-samples').html(html);
    }

    function applyPendingExportFilters(pending) {
        pending = pending || {};
        const total = parseInt(pending.pending_total || 0, 10);
        const mapped = parseInt(pending.pending_mapped || 0, 10);
        const modo = pending.recommended_modo || (mapped > 0 ? 'update_only' : (total > 0 ? 'upsert' : 'update_only'));

        $('#fe-sku').val('');
        $('#fe-tanda').val(1);
        $('#fe-tandas-label').text('');
        $('#fe-modo').val(modo);
        $('#fe-pending-only').prop('checked', true);
        $('#fe-only-changed').prop('checked', false);
        $('#fe-include-archived').prop('checked', false);
        $('#fe-include-stock').prop('checked', false);
        $('#fe-hydrate-facto').prop('checked', modo === 'update_only');
    }

    function loadPending() {
        $.post(ajaxurl, { action: 'riverso_facto_export_pending', nonce }, function(r) {
            if (!r.success) {
                return;
            }
            lastPendingData = r.data || {};
            renderPending(lastPendingData);
        });
    }

    function refreshModoHelp() {
        const m = $('#fe-modo').val();
        $('#fe-modo-help').text(modoHelp[m] || '');
        if (m === 'update_only') {
            $('#fe-hydrate-facto').prop('checked', true);
        }
    }

    function batchEstadoLabel(b) {
        if (b.estado === 'aplicado') {
            return '<span style="color:green;">Aplicado</span>';
        }
        if (b.estado === 'supersedido') {
            const by = b.superseded_by_batch_id ? (' por lote #' + b.superseded_by_batch_id) : '';
            return '<span style="color:#856404;">Supersedido' + esc(by) + '</span>';
        }
        return esc(b.estado || 'generado');
    }

    function batchActions(b) {
        let html = '<button type="button" class="button button-small fe-view-diff" data-id="' + b.id + '">Ver cambios</button> ';
        if (b.can_mark_applied) {
            html += '<button type="button" class="button button-small fe-mark-applied" data-id="' + b.id + '">Marcar aplicado</button>';
        } else if (b.can_unmark_applied) {
            html += '<button type="button" class="button button-small fe-unmark-applied" data-id="' + b.id + '">Desmarcar</button>';
        }
        return html;
    }

    function renderBatchDiff(data) {
        const b = data.batch || {};
        $('#fe-diff-batch-label').text('#' + b.id);
        let summary = 'Lote ' + esc(b.modo || '') + ' · ' + (data.total_items || 0) + ' SKU(s)';
        if (data.changed_count > 0) {
            summary += ' · ' + data.changed_count + ' con cambios vs lote aplicado anterior';
        }
        if (b.estado === 'supersedido' && data.superseded_by) {
            summary += '<br><strong>Supersedido por lote #' + data.superseded_by + '</strong>';
            if ((data.overlap_skus || []).length) {
                summary += ' (SKUs solapados: ' + (data.overlap_skus || []).map(function(s){ return esc(s); }).join(', ') + ')';
            }
        }
        if (!data.has_payload) {
            summary += '<br><em>Este lote no tiene snapshot de campos (generado antes de v1.6.42). Solo se muestran SKU y hash.</em>';
        }
        $('#fe-batch-diff-summary').html(summary);

        let html = '<table class="widefat striped"><thead><tr><th>SKU</th><th>Nombre</th><th>Campos</th><th>Diff vs aplicado previo</th></tr></thead><tbody>';
        (data.items || []).forEach(function(it) {
            let fields = '';
            if (it.fields && Object.keys(it.fields).length) {
                fields = Object.keys(it.fields).map(function(k) {
                    return esc(k) + ': ' + esc(it.fields[k]);
                }).join('<br>');
            } else {
                fields = '<span class="description">—</span>';
            }
            let diffs = '';
            if (it.diffs && it.diffs.length) {
                diffs = it.diffs.map(function(d) {
                    return esc(d.field) + ': ' + esc(d.before) + ' → ' + esc(d.after);
                }).join('<br>');
            } else if (data.has_payload) {
                diffs = '<span class="description">Sin cambios vs aplicado previo</span>';
            } else {
                diffs = '<span class="description">hash ' + esc(it.row_hash || '').substring(0, 12) + '…</span>';
            }
            html += '<tr><td><code>' + esc(it.sku) + '</code></td><td>' + esc(it.nombre || '—') + '</td><td>' + fields + '</td><td>' + diffs + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#fe-batch-diff-table').html(html);
        $('#fe-batch-diff-panel').show();
        $('html, body').animate({ scrollTop: $('#fe-batch-diff-panel').offset().top - 60 }, 200);
    }

    function loadBatches() {
        $.post(ajaxurl, { action: 'riverso_facto_export_batches', nonce }, function(r) {
            const $body = $('#fe-batches-body').empty();
            if (!r.success || !r.data.batches || !r.data.batches.length) {
                $body.append('<tr><td colspan="7">Sin lotes aún.</td></tr>');
                return;
            }
            r.data.batches.forEach(function(b) {
                $body.append(
                    '<tr>' +
                    '<td>' + b.id + '</td>' +
                    '<td>' + esc(b.modo) + '</td>' +
                    '<td>' + b.tanda + ' / ' + b.tandas_total + '</td>' +
                    '<td>' + b.total_filas + '</td>' +
                    '<td>' + batchEstadoLabel(b) + '</td>' +
                    '<td>' + esc(b.created_at || '') + '</td>' +
                    '<td>' + batchActions(b) + '</td>' +
                    '</tr>'
                );
            });
        });
    }

    $('#fe-modo').on('change', refreshModoHelp);
    refreshModoHelp();
    loadPending();
    loadBatches();

    $('#fe-export-pending-btn').on('click', function() {
        if (!lastPendingData || parseInt(lastPendingData.pending_total || 0, 10) <= 0) {
            alert('No hay productos pendientes de export.');
            return;
        }
        applyPendingExportFilters(lastPendingData);
        refreshModoHelp();
        $('html, body').animate({ scrollTop: $('#fe-preview-btn').offset().top - 80 }, 200);
        $('#fe-preview-btn').trigger('click');
    });

    $('#fe-preview-btn').on('click', function() {
        const f = filters();
        $.post(ajaxurl, Object.assign({ action: 'riverso_facto_export_preview', nonce }, f), function(r) {
            $('#fe-preview-panel').show();
            if (!r.success) {
                $('#fe-preview-summary').html('<p style="color:#b32d2e;">' + esc(r.data && r.data.message ? r.data.message : 'Error') + '</p>');
                $('#fe-download-btn').prop('disabled', true);
                return;
            }
            lastPreview = r.data;
            const d = r.data;
            let html = '';
            if (d.total <= 0) {
                html += '<p style="color:#b32d2e;margin:0 0 10px;"><strong>Sin filas para exportar.</strong> ' + esc(d.empty_hint || '') + '</p>';
            }
            html += '<ul style="margin:0;">' +
                '<li><strong>Total filas:</strong> ' + d.total + '</li>' +
                '<li><strong>Tandas:</strong> ' + (d.tandas || 0) + ' (máx. ' + d.chunk_size + ' por archivo)</li>' +
                '<li><strong>Con mapa FACTO:</strong> ' + d.mapped_count + '</li>' +
                '</ul>';
            if (d.replace_blocked) {
                html += '<p style="color:#b32d2e;margin-top:10px;"><strong>Reemplazar bloqueado:</strong> ' + esc(d.replace_reason) + '</p>';
            }
            if (d.pending && d.pending.pending_total > 0) {
                html += '<p style="margin-top:10px;"><strong>Pendientes de export:</strong> ' + d.pending.pending_total + '</p>';
            }
            if ((d.hydrated_count || 0) > 0) {
                html += '<p style="margin-top:10px;"><strong>Filas completadas desde FACTO:</strong> ' + d.hydrated_count + ' (precio/marca/categoría remota + tus cambios locales encima).</p>';
            }
            $('#fe-preview-summary').html(html);
            if (d.pending) {
                renderPending(d.pending);
            }
            const errs = (d.sample_errors || []).map(function(e) {
                return 'SKU ' + esc(e.sku || '?') + ': ' + esc(e.message);
            }).join('<br>');
            $('#fe-preview-errors').html(errs ? ('<strong>Errores:</strong><br>' + errs) : '');
            const warns = (d.validation && d.validation.warnings && d.validation.warnings.length)
                ? d.validation.warnings.map(function(w){ return esc(w); }).join('<br>')
                : '';
            $('#fe-preview-warnings').html(warns ? ('<strong>Advertencias:</strong><br>' + warns) : '');
            $('#fe-tandas-label').text(d.tandas > 1 ? (' de ' + d.tandas) : '');
            $('#fe-download-btn').prop('disabled', !d.can_download || d.total <= 0);
        });
    });

    $('#fe-download-btn').on('click', function() {
        if (!lastPreview || !lastPreview.can_download || lastPreview.total <= 0) {
            alert('Primero ejecuta Vista previa con al menos una fila exportable.');
            return;
        }
        const f = filters();
        const $form = $('<form method="post" action="' + ajaxurl + '" target="_blank"></form>');
        Object.assign(f, { action: 'riverso_facto_export_download', nonce });
        Object.keys(f).forEach(function(k) {
            $form.append($('<input type="hidden">').attr('name', k).val(f[k]));
        });
        $('body').append($form);
        $form.submit();
        $form.remove();
        setTimeout(loadBatches, 1500);
    });

    $(document).on('click', '.fe-mark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Confirmas que importaste este lote en FACTO? Los lotes anteriores con SKUs solapados quedarán supersedidos.')) return;
        $.post(ajaxurl, { action: 'riverso_facto_export_mark_applied', nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
            loadPending();
        });
    });

    $(document).on('click', '.fe-unmark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Desmarcar este lote? Los SKUs volverán a pendiente salvo que estén en otro lote aplicado.')) return;
        $.post(ajaxurl, { action: 'riverso_facto_export_unmark_applied', nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
            loadPending();
        });
    });

    $(document).on('click', '.fe-view-diff', function() {
        const id = $(this).data('id');
        $.post(ajaxurl, { action: 'riverso_facto_export_batch_diff', nonce, batch_id: id }, function(r) {
            if (!r.success) {
                alert(r.data && r.data.message ? r.data.message : 'Error al cargar detalle');
                return;
            }
            renderBatchDiff(r.data);
        });
    });

    $('#fe-batch-diff-close').on('click', function() {
        $('#fe-batch-diff-panel').hide();
    });
})(jQuery);
</script>
