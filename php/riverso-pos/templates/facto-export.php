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
        <strong>CREAR</strong> y <strong>EDITAR</strong> se cubren con los modos de abajo; <strong>ELIMINAR</strong> en FACTO no va en este Excel (queda fuera de alcance).
        Un <strong>cambio de SKU</strong> (SKU anterior → SKU nuevo) no se aplica por CRUD: usa el panel <em>Cambios de SKU</em> y el archivo de instrucciones aparte.
    </p>

    <div id="fe-pending-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;border-left:4px solid #d63638;">
        <h2 style="margin-top:0;">Pendientes de export a FACTO</h2>
        <p id="fe-pending-message" class="description"></p>
        <div id="fe-pending-samples" style="margin-top:10px;"></div>
        <p style="margin-top:12px;">
            <button type="button" class="button button-primary" id="fe-export-pending-btn">Exportar solo pendientes</button>
        </p>
    </div>

    <div id="fe-sku-changes-panel" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;border-left:4px solid #f9a825;background:#fffdf5;">
        <h2 style="margin-top:0;">Cambios de SKU (manual en FACTO)</h2>
        <p class="description" style="margin-bottom:8px;">
            FACTO no permite renombrar SKU por API ni por el Excel CRUD. Estos casos van en un <strong>archivo aparte</strong>:
            debes cambiar <code>SKU_Anterior</code> → <code>SKU_Nuevo</code> a mano en FACTO.
        </p>
        <p id="fe-sku-changes-message" class="description"></p>
        <div id="fe-sku-changes-samples" style="margin-top:10px;"></div>
        <p style="margin-top:12px;">
            <button type="button" class="button" id="fe-view-sku-changes-btn">Ver cambios de SKU</button>
            <button type="button" class="button button-primary" id="fe-download-sku-changes-btn">Descargar instrucciones SKU</button>
        </p>
    </div>

    <div id="fe-sku-changes-detail" class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;display:none;">
        <h2 style="margin-top:0;">Detalle cambios de SKU</h2>
        <div id="fe-sku-changes-detail-table" style="max-height:420px;overflow:auto;"></div>
        <p style="margin-top:12px;">
            <button type="button" class="button" id="fe-sku-changes-detail-close">Cerrar</button>
        </p>
    </div>

    <div class="card" style="max-width:920px;padding:16px 20px;margin-top:16px;">
        <h2 style="margin-top:0;">Opciones de export</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="fe-modo">Modo FACTO</label></th>
                <td>
                    <select id="fe-modo" class="regular-text">
                        <option value="update_only">EDITAR — Solo actualizar (SKUs ya en FACTO)</option>
                        <option value="upsert" selected>CREAR + EDITAR — Agregar y actualizar</option>
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
        <div style="margin-top:8px;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#f0f6fc;border-radius:2px;">
            <p style="margin:0 0 10px;"><strong>Ver qué columnas cambian</strong></p>
            <p class="description" style="margin:0 0 12px;">
                Compara el export actual con el último lote FACTO marcado como aplicado y muestra
                <strong>antes → después</strong> por columna (Nombre, Precio, Categoría, Stock mínimo, etc.).
            </p>
            <p style="margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="button" class="button button-primary button-hero" id="fe-view-changes-btn" style="font-size:14px;min-height:36px;">
                    Ver cambios
                </button>
                <button type="button" class="button" id="fe-preview-btn">Vista previa (mismo cálculo)</button>
                <button type="button" class="button button-primary" id="fe-download-btn" disabled>Descargar Excel</button>
            </p>
        </div>
        <p class="description" id="fe-status-line" style="margin-top:8px;"></p>
    </div>

    <div id="fe-preview-panel" class="card" style="max-width:1100px;padding:16px 20px;margin-top:16px;display:none;border-left:4px solid #2271b1;">
        <h2 style="margin-top:0;">Cambios detectados (columnas)</h2>
        <div id="fe-preview-summary"></div>
        <div id="fe-preview-toolbar" style="display:none;margin-top:12px;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="search" id="fe-change-q" class="regular-text" placeholder="Buscar SKU o nombre" style="max-width:280px;">
            <span class="button-group">
                <button type="button" class="button button-primary fe-accion-filter" data-accion="">Todos</button>
                <button type="button" class="button fe-accion-filter" data-accion="CREAR">CREAR</button>
                <button type="button" class="button fe-accion-filter" data-accion="EDITAR">EDITAR</button>
            </span>
        </div>
        <div id="fe-preview-samples" style="margin-top:12px;"></div>
        <div id="fe-preview-errors" style="margin-top:12px;color:#b32d2e;"></div>
        <div id="fe-preview-warnings" style="margin-top:8px;color:#856404;"></div>
        <p style="margin-top:14px;">
            <button type="button" class="button button-primary" id="fe-download-btn-2" disabled>Descargar Excel</button>
        </p>
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
    let lastSkuChanges = null;
    let previewAccion = '';

    const modoHelp = {
        update_only: 'EDITAR: actualiza productos que ya tienen mapa Riverso ↔ FACTO.',
        upsert: 'CREAR + EDITAR: crea productos nuevos y actualiza los existentes. Use tandas si supera <?php echo (int) $chunk_size; ?> filas.',
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

    function setDownloadEnabled(enabled) {
        $('#fe-download-btn, #fe-download-btn-2').prop('disabled', !enabled);
    }

    function accionStyle(accion) {
        if (accion === 'CREAR') return 'color:#2271b1;font-weight:600;';
        if (accion === 'EDITAR') return 'color:#00a32a;font-weight:600;';
        return '';
    }

    function renderDiffs(row) {
        const diffs = row.diffs || [];
        if (diffs.length) {
            return '<table class="widefat" style="margin:0;background:transparent;border:0;box-shadow:none;"><tbody>' +
                diffs.map(function(d) {
                    const from = d.antes === '' || d.antes == null ? '—' : d.antes;
                    const to = d.despues === '' || d.despues == null ? '—' : d.despues;
                    return '<tr><td style="padding:2px 6px;min-width:110px;"><strong>' + esc(d.campo) + '</strong></td>' +
                        '<td style="padding:2px 6px;"><code>' + esc(from) + '</code> → <code>' + esc(to) + '</code></td></tr>';
                }).join('') +
                '</tbody></table>';
        }
        if (row.accion === 'CREAR') return '<span class="description">Nuevo en FACTO</span>';
        return '<span class="description">—</span>';
    }

    function renderChangeSamples(data) {
        const q = ($('#fe-change-q').val() || '').toLowerCase().trim();
        const rows = (data.preview_rows || []).filter(function(row) {
            if (previewAccion && row.accion !== previewAccion) return false;
            if (!q) return true;
            const hay = ((row.sku || '') + ' ' + (row.sku_local || '') + ' ' + (row.nombre || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        const changed = parseInt(data.changed_count || 0, 10);
        $('#fe-preview-toolbar').css('display', changed > 0 ? 'flex' : 'none');

        if (!changed) {
            let msg = 'No hay diferencias de columnas respecto al último lote aplicado.';
            if (!data.has_baseline) {
                msg = 'Sin lote aplicado como baseline: todas las filas del export se tratan como nuevas o sin historial local. Marca un lote como aplicado tras importar en FACTO.';
            } else if ((data.total || 0) > 0) {
                msg = 'Hay ' + data.total + ' fila(s) en el Excel, pero ninguna con cambio de columna vs el baseline (o están filtradas). Marca «Solo filas cambiadas» / «Solo pendientes» según corresponda.';
            }
            $('#fe-preview-samples').html('<p class="description">' + esc(msg) + '</p>');
            return;
        }

        let html = '<div style="max-height:480px;overflow:auto;"><table class="widefat striped"><thead><tr>' +
            '<th>Acción</th><th>SKU</th><th>Nombre</th><th>Precio total</th><th>Columnas que cambian</th>' +
            '</tr></thead><tbody>';
        if (!rows.length) {
            html += '<tr><td colspan="5">Ninguna fila coincide con el filtro.</td></tr>';
        } else {
            rows.forEach(function(row) {
                html += '<tr>' +
                    '<td><span style="' + accionStyle(row.accion) + '">' + esc(row.accion) + '</span></td>' +
                    '<td><code>' + esc(row.sku) + '</code></td>' +
                    '<td>' + esc(row.nombre || '—') + '</td>' +
                    '<td>' + esc(row.precio || '—') + '</td>' +
                    '<td>' + renderDiffs(row) + '</td>' +
                    '</tr>';
            });
        }
        html += '</tbody></table></div>';
        if (data.preview_truncated) {
            html += '<p class="description">Mostrando ' + (data.preview_rows || []).length + ' de ' + changed + ' cambios. El Excel incluye el listado completo.</p>';
        }
        $('#fe-preview-samples').html(html);
    }

    function renderPending(data) {
        const total = parseInt(data.pending_total || 0, 10);
        if (total <= 0) {
            $('#fe-pending-panel').hide();
            return;
        }
        $('#fe-pending-panel').show();
        $('#fe-pending-message').text(data.message || '');
        let html = '<table class="widefat striped" style="max-width:100%;"><thead><tr><th>Acción</th><th>SKU</th><th>Nombre</th><th>Marca</th></tr></thead><tbody>';
        (data.samples || []).forEach(function(row) {
            const accion = row.accion || '—';
            const accionStyle = accion === 'CREAR' ? 'color:#2271b1;font-weight:600;' : (accion === 'EDITAR' ? 'color:#00a32a;font-weight:600;' : '');
            html += '<tr><td><span style="' + accionStyle + '">' + esc(accion) + '</span></td><td><code>' + esc(row.sku) + '</code></td><td>' + esc(row.nombre) + '</td><td>' + esc(row.marca || '—') + '</td></tr>';
        });
        html += '</tbody></table>';
        if (total > (data.samples || []).length) {
            html += '<p class="description">… y ' + (total - data.samples.length) + ' más.</p>';
        }
        $('#fe-pending-samples').html(html);
    }

    function renderSkuChangesPanel(data) {
        lastSkuChanges = data || {};
        const total = parseInt(lastSkuChanges.total || 0, 10);
        if (total <= 0) {
            $('#fe-sku-changes-panel').hide();
            return;
        }
        $('#fe-sku-changes-panel').show();
        $('#fe-sku-changes-message').text(lastSkuChanges.message || '');
        let html = '<table class="widefat striped"><thead><tr><th>SKU anterior</th><th>SKU nuevo</th><th>Nombre</th><th>ID FACTO</th></tr></thead><tbody>';
        (lastSkuChanges.samples || []).slice(0, 8).forEach(function(row) {
            html += '<tr><td><code>' + esc(row.sku_anterior) + '</code></td><td><code>' + esc(row.sku_nuevo) + '</code></td><td>' + esc(row.nombre || '—') + '</td><td>' + esc(row.facto_product_id || '—') + '</td></tr>';
        });
        html += '</tbody></table>';
        if (total > Math.min(8, (lastSkuChanges.samples || []).length)) {
            html += '<p class="description">… y más. Usa «Ver cambios de SKU» para el listado completo.</p>';
        }
        $('#fe-sku-changes-samples').html(html);
    }

    function renderSkuChangesDetail(data) {
        const rows = data.samples || [];
        let html = '<table class="widefat striped"><thead><tr><th>SKU anterior</th><th>SKU nuevo</th><th>Nombre</th><th>ID FACTO</th><th>Instrucción</th></tr></thead><tbody>';
        if (!rows.length) {
            html += '<tr><td colspan="5">Sin cambios de SKU.</td></tr>';
        } else {
            rows.forEach(function(row) {
                html += '<tr>' +
                    '<td><code>' + esc(row.sku_anterior) + '</code></td>' +
                    '<td><code>' + esc(row.sku_nuevo) + '</code></td>' +
                    '<td>' + esc(row.nombre || '—') + '</td>' +
                    '<td>' + esc(row.facto_product_id || '—') + '</td>' +
                    '<td>' + esc(row.instruccion || '') + '</td>' +
                    '</tr>';
            });
        }
        html += '</tbody></table>';
        if ((data.total || 0) > rows.length) {
            html += '<p class="description">Mostrando ' + rows.length + ' de ' + data.total + '. Descarga el Excel para el listado completo.</p>';
        }
        $('#fe-sku-changes-detail-table').html(html);
        $('#fe-sku-changes-detail').show();
        $('html, body').animate({ scrollTop: $('#fe-sku-changes-detail').offset().top - 60 }, 200);
    }

    function loadSkuChanges() {
        $.post(ajaxurl, { action: 'riverso_facto_export_sku_changes', nonce }, function(r) {
            if (!r.success) {
                return;
            }
            renderSkuChangesPanel(r.data || {});
        });
    }

    function applyPendingExportFilters(pending) {
        pending = pending || {};
        const total = parseInt(pending.pending_total || 0, 10);
        const mapped = parseInt(pending.pending_mapped || 0, 10);
        const createCount = parseInt(pending.pending_create || 0, 10);
        const modo = pending.recommended_modo || (createCount > 0 ? 'upsert' : (mapped > 0 ? 'update_only' : 'upsert'));

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
        } else if (b.blocked_by_newer) {
            html += '<span class="description">Hay un lote posterior ya aplicado</span>';
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

    function applyPreview(d) {
        lastPreview = d;
        let html = '';
        if (d.total <= 0) {
            html += '<p style="color:#b32d2e;margin:0 0 10px;"><strong>Sin filas para exportar.</strong> ' + esc(d.empty_hint || '') + '</p>';
        }
        html += '<ul style="margin:0;">' +
            '<li><strong>Cambios de columnas:</strong> ' + (d.changed_count || 0) +
                ' (CREAR ' + (d.create_count || 0) + ', EDITAR ' + (d.update_count || 0) + ')</li>' +
            '<li><strong>Total filas Excel:</strong> ' + d.total + '</li>' +
            '<li><strong>Tandas:</strong> ' + (d.tandas || 0) + ' (máx. ' + d.chunk_size + ' por archivo)</li>' +
            '<li><strong>Con mapa FACTO:</strong> ' + d.mapped_count + '</li>' +
            '</ul>';
        if (d.replace_blocked) {
            html += '<p style="color:#b32d2e;margin-top:10px;"><strong>Reemplazar bloqueado:</strong> ' + esc(d.replace_reason) + '</p>';
        }
        if (d.pending && d.pending.pending_total > 0) {
            const pc = parseInt(d.pending.pending_create || 0, 10);
            const pe = parseInt(d.pending.pending_mapped || 0, 10);
            html += '<p style="margin-top:10px;"><strong>Pendientes de export:</strong> ' + d.pending.pending_total + ' (' + pc + ' CREAR, ' + pe + ' EDITAR)</p>';
        }
        if (d.sku_changes && d.sku_changes.total > 0) {
            html += '<p style="margin-top:10px;color:#856404;"><strong>Cambios de SKU (manual):</strong> ' + d.sku_changes.total +
                ' — no van en el Excel CRUD; usa «Ver cambios de SKU» / «Descargar instrucciones SKU».</p>';
            renderSkuChangesPanel(d.sku_changes);
        }
        if ((d.hydrated_count || 0) > 0) {
            html += '<p style="margin-top:10px;"><strong>Filas completadas desde FACTO:</strong> ' + d.hydrated_count + ' (precio/marca/categoría remota + tus cambios locales encima).</p>';
        }
        if (d.has_baseline) {
            html += '<p class="description" style="margin-top:10px;">Baseline: lotes FACTO marcados como aplicados.</p>';
        } else {
            html += '<p class="description" style="margin-top:10px;">Sin baseline aplicado: no hay historial local de columnas para comparar.</p>';
        }
        $('#fe-preview-summary').html(html);
        if (d.pending) {
            renderPending(d.pending);
        }
        renderChangeSamples(d);
        const errs = (d.sample_errors || []).map(function(e) {
            return 'SKU ' + esc(e.sku || '?') + ': ' + esc(e.message);
        }).join('<br>');
        $('#fe-preview-errors').html(errs ? ('<strong>Errores:</strong><br>' + errs) : '');
        const warns = (d.validation && d.validation.warnings && d.validation.warnings.length)
            ? d.validation.warnings.map(function(w){ return esc(w); }).join('<br>')
            : '';
        $('#fe-preview-warnings').html(warns ? ('<strong>Advertencias:</strong><br>' + warns) : '');
        $('#fe-tandas-label').text(d.tandas > 1 ? (' de ' + d.tandas) : '');
        const canDl = !!(d.can_download && d.total > 0);
        setDownloadEnabled(canDl);
        $('#fe-status-line').html(canDl
            ? ('Listo para descargar: <strong>' + d.total + '</strong> fila(s).')
            : 'Nada descargable con estos filtros.');
    }

    function runPreview(opts) {
        opts = opts || {};
        const $btns = $('#fe-preview-btn, #fe-view-changes-btn').prop('disabled', true);
        $('#fe-preview-panel').show();
        $('#fe-preview-summary').html('<p class="description">Calculando cambios…</p>');
        $('#fe-preview-samples').empty();
        $('#fe-preview-toolbar').hide();
        $('#fe-preview-errors, #fe-preview-warnings').empty();
        $('#fe-status-line').text('Calculando…');
        setDownloadEnabled(false);

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            dataType: 'json',
            timeout: 180000,
            data: Object.assign({ action: 'riverso_facto_export_preview', nonce: nonce }, filters())
        }).done(function(r) {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) ? r.data.message : 'Error al calcular la vista previa';
                $('#fe-preview-summary').html('<p style="color:#b32d2e;">' + esc(msg) + '</p>');
                $('#fe-status-line').html('<span style="color:#b32d2e;">' + esc(msg) + '</span>');
                setDownloadEnabled(false);
                return;
            }
            applyPreview(r.data || {});
            if (opts.scroll !== false) {
                $('html, body').animate({ scrollTop: $('#fe-preview-panel').offset().top - 60 }, 200);
            }
        }).fail(function(xhr) {
            let msg = 'No se pudo calcular la vista previa';
            if (xhr && xhr.statusText === 'timeout') {
                msg = 'La vista previa tardó demasiado (timeout). Reintenta o filtra por SKU.';
            } else if (xhr && xhr.status) {
                msg += ' (HTTP ' + xhr.status + ')';
            }
            $('#fe-preview-summary').html('<p style="color:#b32d2e;">' + esc(msg) + '</p>');
            $('#fe-status-line').html('<span style="color:#b32d2e;">' + esc(msg) + '</span>');
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
        const f = filters();
        const $form = $('<form method="post" action="' + ajaxurl + '" target="_blank"></form>');
        Object.assign(f, { action: 'riverso_facto_export_download', nonce });
        Object.keys(f).forEach(function(k) {
            $form.append($('<input type="hidden">').attr('name', k).val(f[k]));
        });
        $('body').append($form);
        $form.submit();
        $form.remove();
        $('#fe-status-line').text('Descarga iniciada. Si el navegador la bloqueó, permite ventanas emergentes.');
        setTimeout(loadBatches, 1500);
    }

    $('#fe-modo').on('change', refreshModoHelp);
    refreshModoHelp();
    loadPending();
    loadSkuChanges();
    loadBatches();

    $('#fe-view-sku-changes-btn').on('click', function() {
        $.post(ajaxurl, { action: 'riverso_facto_export_sku_changes', nonce }, function(r) {
            if (!r.success) {
                alert(r.data && r.data.message ? r.data.message : 'Error al cargar cambios de SKU');
                return;
            }
            lastSkuChanges = r.data || {};
            renderSkuChangesPanel(lastSkuChanges);
            renderSkuChangesDetail(lastSkuChanges);
        });
    });

    $('#fe-download-sku-changes-btn').on('click', function() {
        const $form = $('<form method="post" action="' + ajaxurl + '" target="_blank"></form>');
        $form.append($('<input type="hidden">').attr('name', 'action').val('riverso_facto_export_sku_changes_download'));
        $form.append($('<input type="hidden">').attr('name', 'nonce').val(nonce));
        $('body').append($form);
        $form.submit();
        $form.remove();
    });

    $('#fe-sku-changes-detail-close').on('click', function() {
        $('#fe-sku-changes-detail').hide();
    });

    $('#fe-export-pending-btn').on('click', function() {
        if (!lastPendingData || parseInt(lastPendingData.pending_total || 0, 10) <= 0) {
            alert('No hay productos pendientes de export.');
            return;
        }
        applyPendingExportFilters(lastPendingData);
        refreshModoHelp();
        $('html, body').animate({ scrollTop: $('#fe-view-changes-btn').offset().top - 80 }, 200);
        runPreview({ scroll: true });
    });

    $('#fe-view-changes-btn, #fe-preview-btn').on('click', function() {
        runPreview({ scroll: true });
    });

    $('#fe-change-q').on('input', function() {
        if (lastPreview) renderChangeSamples(lastPreview);
    });

    $(document).on('click', '.fe-accion-filter', function() {
        previewAccion = $(this).data('accion') || '';
        $('.fe-accion-filter').removeClass('button-primary');
        $(this).addClass('button-primary');
        if (lastPreview) renderChangeSamples(lastPreview);
    });

    $('#fe-download-btn, #fe-download-btn-2').on('click', doDownload);

    $(document).on('click', '.fe-mark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Confirmas que importaste este lote en FACTO? Los lotes anteriores con SKUs solapados quedarán supersedidos.')) return;
        $.post(ajaxurl, { action: 'riverso_facto_export_mark_applied', nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
            loadPending();
            loadSkuChanges();
        });
    });

    $(document).on('click', '.fe-unmark-applied', function() {
        const id = $(this).data('id');
        if (!confirm('¿Desmarcar este lote? Los SKUs volverán a pendiente salvo que estén en otro lote aplicado.')) return;
        $.post(ajaxurl, { action: 'riverso_facto_export_unmark_applied', nonce, batch_id: id }, function(r) {
            alert(r.success ? (r.data.message || 'OK') : (r.data.message || 'Error'));
            loadBatches();
            loadPending();
            loadSkuChanges();
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
