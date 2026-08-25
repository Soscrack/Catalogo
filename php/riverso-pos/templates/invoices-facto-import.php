<?php
/**
 * Partial: Importación DTE desde Inbox FACTO
 */
if (!defined('ABSPATH')) {
    exit;
}

$can_import = current_user_can('riverso_process_invoices')
    || current_user_can('riverso_manage_settings')
    || current_user_can('manage_options');
$facto_ready = function_exists('riverso_facto_is_configured') && riverso_facto_is_configured();
?>

<div id="panel-facto-import" class="invoices-tab-panel" style="display:none;">

    <?php if (!$can_import): ?>
    <p class="description">No tienes permisos para importar desde FACTO.</p>
    <?php elseif (!$facto_ready): ?>
    <p class="description">
        FACTO no está configurado. Define las credenciales en <code>wp-config.php</code>
        (<code>RIVERSO_FACTO_*</code>) o en Ajustes → Integraciones FACTO.
    </p>
    <?php else: ?>

    <div class="facto-import-panel">
        <p class="description">
            Importa DTE recibidos en el Inbox de FACTO al pipeline de facturas Riverso.
            Solo lectura: no se aprueba ni acusa recibo en FACTO.
            Las facturas se registran con <strong>modo solo costos</strong> (sin recepción física).
        </p>

        <div class="facto-import-quick">
            <button type="button" class="button button-primary" id="btn-facto-import-quick-3d">
                Procesar últimos 3 días
            </button>
            <span class="description">Revisa el Inbox FACTO aunque ya hayas importado ese rango (captura documentos nuevos).</span>
        </div>

        <div class="riverso-filters facto-import-filters">
            <label>Desde <input type="date" id="facto-import-desde"></label>
            <label>Hasta <input type="date" id="facto-import-hasta"></label>
            <span class="facto-import-shortcuts">
                <button type="button" class="button button-small" data-range="mes-actual">Mes actual</button>
                <button type="button" class="button button-small" data-range="mes-anterior">Mes anterior</button>
                <button type="button" class="button button-small" data-range="ultimos-3-dias">Últimos 3 días</button>
                <button type="button" class="button button-small" data-range="ultimos-3">Últimos 3 meses</button>
            </span>
            <button type="button" class="button" id="btn-facto-import-estimate">Estimar</button>
            <button type="button" class="button button-primary" id="btn-facto-import-run" disabled>Importar</button>
        </div>

        <div id="facto-import-estimate-box" class="facto-import-estimate" style="display:none;"></div>
        <div id="facto-import-status" class="facto-import-status"></div>

        <h3>Intervalos procesados</h3>
        <table class="wp-list-table widefat striped" id="facto-import-runs-table">
            <thead>
                <tr>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Estado</th>
                    <th>Importados</th>
                    <th>Fusionados</th>
                    <th>Duplicados</th>
                    <th>Omitidos</th>
                    <th>Errores</th>
                    <th>Páginas</th>
                    <th>Ejecutado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="facto-import-runs-body">
                <tr><td colspan="11">Cargando…</td></tr>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<?php if ($can_import && $facto_ready): ?>
<style>
.facto-import-quick {
    display:flex; flex-wrap:wrap; gap:10px; align-items:center;
    margin:12px 0; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;
}
.facto-import-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:12px 0; }
.facto-import-filters label { display:inline-flex; align-items:center; gap:6px; }
.facto-import-shortcuts { display:inline-flex; gap:4px; flex-wrap:wrap; }
.facto-import-estimate {
    background:#f0f6fc; border:1px solid #c3d9ed; border-radius:4px;
    padding:12px 16px; margin:8px 0 12px; max-width:720px;
}
.facto-import-estimate .warn { color:#9a3412; font-weight:600; margin-top:8px; }
.facto-import-status { min-height:24px; margin:8px 0 16px; }
.facto-run-state-done { color:#166534; }
.facto-run-state-running { color:#0369a1; }
.facto-run-state-error { color:#b91c1c; }
.facto-run-state-cancelled { color:#6b7280; }
</style>
<script>
(function($) {
    const nonce = '<?php echo esc_js(wp_create_nonce('riverso_pos_nonce')); ?>';
    const ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    let estimateData = null;
    let importRunId = 0;
    let panelInitialized = false;

    function fmtDate(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function setRangeShortcut(kind) {
        const now = new Date();
        let desde, hasta;
        if (kind === 'mes-actual') {
            desde = new Date(now.getFullYear(), now.getMonth(), 1);
            hasta = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        } else if (kind === 'mes-anterior') {
            desde = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            hasta = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (kind === 'ultimos-3-dias') {
            hasta = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            desde = new Date(hasta);
            desde.setDate(desde.getDate() - 2);
        } else {
            hasta = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            desde = new Date(hasta);
            desde.setMonth(desde.getMonth() - 3);
        }
        $('#facto-import-desde').val(fmtDate(desde));
        $('#facto-import-hasta').val(fmtDate(hasta));
        estimateData = null;
        $('#btn-facto-import-run').prop('disabled', true);
        $('#facto-import-estimate-box').hide();
    }

    function setImportButtonsDisabled(disabled) {
        $('#btn-facto-import-run, #btn-facto-import-estimate, #btn-facto-import-quick-3d').prop('disabled', disabled);
    }

    function formatEta(seconds) {
        const s = Number(seconds || 0);
        if (s < 60) return s + ' s';
        const m = Math.floor(s / 60);
        const r = s % 60;
        return m + ' min' + (r ? ' ' + r + ' s' : '');
    }

    function renderEstimate(d) {
        estimateData = d;
        const $box = $('#facto-import-estimate-box');
        if (!d || d.pages === 0) {
            $box.html('<p>No hay documentos estimados en ese rango.</p>').show();
            $('#btn-facto-import-run').prop('disabled', true);
            return;
        }

        let html = '<p><strong>Estimación:</strong> ~' + (d.docs_estimate || 0) + ' documentos · ' +
            (d.pages || 0) + ' páginas (FACTO ' + (d.page_from || '?') + '–' + (d.page_to || '?') + ') · ~' +
            formatEta(d.eta_seconds) + '</p>';
        html += '<p class="description">' + (d.message || '') + '</p>';

        if (Array.isArray(d.overlapping_runs) && d.overlapping_runs.length) {
            html += '<p><strong>Solapamiento con corridas anteriores:</strong></p><ul>';
            d.overlapping_runs.forEach(function(r) {
                html += '<li>#' + r.id + ' ' + r.fecha_desde + ' → ' + r.fecha_hasta +
                    ' (' + r.state + ', importados: ' + (r.docs_imported || 0) + ')</li>';
            });
            html += '</ul><p class="description">Reprocesar es idempotente: no duplica facturas ya mapeadas.</p>';
        }

        if (d.needs_warning) {
            html += '<p class="warn">⚠ Rango amplio: más de 3 meses o más de 100 documentos estimados. ' +
                'La importación puede tardar ~' + formatEta(d.eta_seconds) + '.</p>';
        }

        $box.html(html).show();
        $('#btn-facto-import-run').prop('disabled', false);
    }

    function loadRuns() {
        $.post(ajaxUrl, {
            action: 'riverso_facto_inbox_runs',
            nonce: nonce
        }, function(resp) {
            const $body = $('#facto-import-runs-body');
            if (!resp.success || !Array.isArray(resp.data.runs)) {
                $body.html('<tr><td colspan="11">No se pudo cargar el historial.</td></tr>');
                return;
            }
            if (!resp.data.runs.length) {
                $body.html('<tr><td colspan="11">Sin corridas previas.</td></tr>');
                return;
            }
            const rows = resp.data.runs.map(function(r) {
                const cls = 'facto-run-state-' + (r.state || 'running');
                const pages = (r.page_from || '?') + '–' + (r.page_to || '?') +
                    ' (' + (r.pages_scanned || 0) + ' escaneadas)';
                const when = r.finished_at || r.started_at || '';
                let resume = '';
                if (r.state === 'running' || r.state === 'error') {
                    resume = '<button type="button" class="button button-small facto-resume-run" ' +
                        'data-id="' + r.id + '" data-desde="' + r.fecha_desde + '" data-hasta="' + r.fecha_hasta + '" ' +
                        'data-page="' + (parseInt(r.page_from, 10) + parseInt(r.pages_scanned || 0, 10)) + '">Reanudar</button>';
                }
                return '<tr>' +
                    '<td>' + r.fecha_desde + '</td>' +
                    '<td>' + r.fecha_hasta + '</td>' +
                    '<td class="' + cls + '">' + r.state + '</td>' +
                    '<td>' + (r.docs_imported || 0) + '</td>' +
                    '<td>' + (r.docs_merged || 0) + '</td>' +
                    '<td>' + (r.docs_duplicate || 0) + '</td>' +
                    '<td>' + (r.docs_skipped || 0) + '</td>' +
                    '<td>' + (r.docs_error || 0) + '</td>' +
                    '<td>' + pages + '</td>' +
                    '<td>' + when + '</td>' +
                    '<td>' + resume + '</td>' +
                    '</tr>';
            });
            $body.html(rows.join(''));
        }).fail(function(xhr) {
            const hint = xhr && xhr.responseText ? (' — ' + String(xhr.responseText).slice(0, 80)) : '';
            $('#facto-import-runs-body').html(
                '<tr><td colspan="11">Error al cargar historial' + hint + '</td></tr>'
            );
        });
    }

    function doEstimate() {
        const desde = $('#facto-import-desde').val();
        const hasta = $('#facto-import-hasta').val();
        const $status = $('#facto-import-status');
        $status.text('Estimando…');
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            timeout: 180000,
            data: {
                action: 'riverso_facto_inbox_estimate',
                nonce: nonce,
                fecha_desde: desde,
                fecha_hasta: hasta
            }
        }).done(function(resp) {
            if (!resp.success) {
                $status.html('<span style="color:#d63638;">' + (resp.data?.message || 'Error') + '</span>');
                return;
            }
            renderEstimate(resp.data);
            $status.text('');
        }).fail(function(xhr) {
            const hint = xhr && xhr.statusText ? (' (' + xhr.status + ' ' + xhr.statusText + ')') : '';
            $status.html('<span style="color:#d63638;">Error de red al estimar' + hint + '.</span>');
        });
    }

    function factoImportBatch(page, ctx) {
        const $status = $('#facto-import-status');
        setImportButtonsDisabled(true);
        $status.text('Importando… página ' + page + (ctx.page_to ? '/' + ctx.page_to : ''));

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            timeout: 120000,
            data: {
                action: 'riverso_facto_inbox_import',
                nonce: nonce,
                fecha_desde: ctx.desde,
                fecha_hasta: ctx.hasta,
                page: page,
                pages: 3,
                run_id: ctx.run_id || 0,
                force_reprocess: ctx.forceReprocess ? 1 : 0
            }
        }).done(function(resp) {
            if (!resp.success) {
                setImportButtonsDisabled(false);
                $status.html('<span style="color:#d63638;">' + (resp.data?.message || 'Error') + '</span>');
                loadRuns();
                return;
            }
            const d = resp.data || {};
            if (d.run_id) ctx.run_id = d.run_id;
            importRunId = ctx.run_id;

            const t = d.totals || {};
            ctx.totals.imported = t.docs_imported || 0;
            ctx.totals.merged = t.docs_merged || 0;
            ctx.totals.duplicate = t.docs_duplicate || 0;
            ctx.totals.skipped = t.docs_skipped || 0;
            ctx.totals.errors = t.docs_error || 0;

            if (d.done) {
                setImportButtonsDisabled(false);
                let msg = '✓ Listo. Importados: ' + ctx.totals.imported +
                    ' · Fusionados: ' + ctx.totals.merged +
                    ' · Duplicados: ' + ctx.totals.duplicate +
                    ' · Omitidos: ' + ctx.totals.skipped +
                    ' · Errores: ' + ctx.totals.errors;
                if (Array.isArray(d.errors) && d.errors.length) {
                    msg += '<br><span class="description">' + d.errors.slice(0, 5).join('; ') + '</span>';
                }
                $status.html('<span style="color:#00a32a;">' + msg + '</span>');
                loadRuns();
                return;
            }

            if (!d.next_page) {
                setImportButtonsDisabled(false);
                $status.html('<span style="color:#d63638;">Importación incompleta (sin next_page).</span>');
                loadRuns();
                return;
            }

            $status.text(
                'Importando… ' + ctx.totals.imported + ' nuevos · ' + ctx.totals.merged + ' fusionados · página ' +
                d.next_page + '/' + (ctx.page_to || d.page_count || '?')
            );
            setTimeout(function() { factoImportBatch(d.next_page, ctx); }, 250);
        }).fail(function(xhr) {
            setImportButtonsDisabled(false);
            const hint = xhr && xhr.statusText ? (' (' + xhr.status + ' ' + xhr.statusText + ')') : '';
            $status.html('<span style="color:#d63638;">Error de red' + hint + '. Puedes reanudar desde el historial.</span>');
            loadRuns();
        });
    }

    function startImport(opts) {
        opts = opts || {};
        const desde = opts.desde || $('#facto-import-desde').val();
        const hasta = opts.hasta || $('#facto-import-hasta').val();
        if (!desde || !hasta) {
            alert('Selecciona fecha desde y hasta.');
            return;
        }

        function run(page, runId, est) {
            if (est.needs_warning && !opts.skipConfirm && !opts.forceReprocess) {
                const mins = Math.max(1, Math.ceil((est.eta_seconds || 60) / 60));
                const ok = confirm(
                    'El rango es amplio (~' + (est.docs_estimate || '?') + ' documentos, ~' + mins +
                    ' min). ¿Continuar con la importación?'
                );
                if (!ok) return;
            }
            importRunId = runId || 0;
            factoImportBatch(page, {
                desde: desde,
                hasta: hasta,
                run_id: runId || 0,
                page_to: est.page_to,
                forceReprocess: !!opts.forceReprocess,
                totals: { imported: 0, merged: 0, duplicate: 0, skipped: 0, errors: 0 }
            });
        }

        if (opts.run_id) {
            const est = estimateData || {
                page_from: opts.page || 1,
                page_to: null,
                needs_warning: false,
                eta_seconds: 0,
                docs_estimate: 0
            };
            run(opts.page || 1, opts.run_id, est);
            return;
        }

        if (opts.forceReprocess) {
            $('#facto-import-status').text('Preparando importación reciente…');
            run(1, 0, {
                page_from: 1,
                page_to: null,
                needs_warning: false,
                eta_seconds: 0,
                docs_estimate: 0
            });
            return;
        }

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            timeout: 180000,
            data: {
                action: 'riverso_facto_inbox_estimate',
                nonce: nonce,
                fecha_desde: desde,
                fecha_hasta: hasta
            }
        }).done(function(resp) {
            if (!resp.success) {
                $('#facto-import-status').html('<span style="color:#d63638;">' + (resp.data?.message || 'Error') + '</span>');
                return;
            }
            renderEstimate(resp.data);
            if (!resp.data.pages) {
                $('#facto-import-status').text('No hay documentos en ese rango.');
                return;
            }
            run(resp.data.page_from || 1, 0, resp.data);
        });
    }

    window.riversoInitFactoImportPanel = function() {
        if (panelInitialized) {
            loadRuns();
            return;
        }
        panelInitialized = true;

        const now = new Date();
        setRangeShortcut('mes-anterior');
        loadRuns();

        $('.facto-import-shortcuts button').on('click', function() {
            setRangeShortcut($(this).data('range'));
        });

        $('#btn-facto-import-estimate').on('click', doEstimate);

        $('#btn-facto-import-run').on('click', function() {
            if (!estimateData) {
                doEstimate();
                return;
            }
            startImport({ skipConfirm: false });
        });

        $('#btn-facto-import-quick-3d').on('click', function() {
            setRangeShortcut('ultimos-3-dias');
            startImport({
                skipConfirm: true,
                forceReprocess: true
            });
        });

        $(document).on('click', '.facto-resume-run', function() {
            const $b = $(this);
            $('#facto-import-desde').val($b.data('desde'));
            $('#facto-import-hasta').val($b.data('hasta'));
            startImport({
                run_id: parseInt($b.data('id'), 10),
                page: parseInt($b.data('page'), 10) || 1,
                skipConfirm: true
            });
        });
    };
})(jQuery);
</script>
<?php endif; ?>
