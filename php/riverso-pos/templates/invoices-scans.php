<?php
/**
 * Partial: Bandeja de escaneos PDF/imagen
 */
if (!defined('ABSPATH')) {
    exit;
}
$can_process = current_user_can('riverso_process_scans')
    || current_user_can('riverso_process_invoices')
    || current_user_can('riverso_create_invoices');

$scan_dte_options = [
    33  => 'Factura electrónica',
    34  => 'Factura exenta electrónica',
    39  => 'Boleta electrónica',
    41  => 'Boleta exenta electrónica',
    46  => 'Factura de compra electrónica',
    52  => 'Guía de despacho electrónica',
    56  => 'Nota de débito electrónica',
    61  => 'Nota de crédito electrónica',
    110 => 'Factura de exportación electrónica',
];
?>

<div id="panel-scans" class="invoices-tab-panel" style="display:none;">

    <div class="scan-usage-panel" id="scan-usage-panel">
        <strong>Uso IA este mes:</strong>
        <span id="scan-usage-text">Cargando…</span>
    </div>

    <?php if ($can_process): ?>
    <p>
        <button type="button" class="button button-primary" id="btn-upload-scan">
            <span class="dashicons dashicons-upload"></span> Subir PDF o Imagen
        </button>
    </p>
    <?php endif; ?>

    <div class="riverso-filters">
        <select id="scan-filter-estado">
            <option value="">Todos los estados</option>
            <option value="pendiente">Pendiente revisión</option>
            <option value="revisado">Revisado</option>
            <option value="confirmado">Confirmado</option>
            <option value="duplicado">Duplicado / adjunto</option>
            <option value="descartado">Descartado</option>
        </select>
        <input type="date" id="scan-filter-desde">
        <input type="date" id="scan-filter-hasta">
        <input type="search" id="scan-filter-search" placeholder="Buscar folio, proveedor o archivo…">
        <button type="button" class="button" id="scan-btn-filter">Filtrar</button>
    </div>

    <div class="riverso-filters invoices-list-controls">
        <label class="invoices-control">Mostrar
            <select id="scan-per-page"><option>10</option><option selected>20</option><option>50</option></select>
        </label>
        <label class="invoices-control">Ordenar
            <select id="scan-orderby">
                <option value="created_at" selected>Fecha ingreso</option>
                <option value="fecha_emision">Fecha documento</option>
                <option value="folio">Folio</option>
                <option value="monto_total">Total</option>
                <option value="confianza">Confianza</option>
            </select>
        </label>
        <select id="scan-order"><option value="DESC">Desc</option><option value="ASC">Asc</option></select>
    </div>

    <table class="wp-list-table widefat striped riverso-data-table" id="scans-table">
        <thead>
            <tr>
                <th>Archivo</th>
                <th>Págs</th>
                <th>Tipo / Folio</th>
                <th>Proveedor</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Conf.</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="scans-tbody">
            <tr><td colspan="9">Cargando…</td></tr>
        </tbody>
    </table>
    <div class="tablenav bottom">
        <span id="scan-pagination-info"></span>
        <button type="button" class="button" id="scan-prev">Anterior</button>
        <button type="button" class="button" id="scan-next">Siguiente</button>
    </div>
</div>

<!-- Modal upload escaneos -->
<div id="modal-upload-scan" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content" style="max-width:720px;">
        <div class="riverso-modal-header">
            <h2>Subir escaneo (PDF / imagen)</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <ul class="scan-upload-tabs">
                <li><button type="button" class="button scan-tab active" data-tab="single">Carga simple</button></li>
                <li><button type="button" class="button scan-tab" data-tab="bulk">Carga masiva</button></li>
            </ul>
            <div id="scan-tab-single">
                <p class="description" style="margin-bottom:12px;">
                    Suba <strong>un PDF o imagen</strong>. Arrástrelo a la zona o use el botón para buscar.
                </p>
                <input type="file" id="scan-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff" style="display:none;">
                <div class="upload-area" id="scan-dropzone-single">
                    <span class="dashicons dashicons-upload" style="font-size:48px;width:48px;height:48px;"></span>
                    <p>Arrastra el PDF o imagen aquí</p>
                    <p class="description">PDF, JPG, PNG, WEBP o TIFF</p>
                </div>
                <div class="scan-upload-toolbar">
                    <button type="button" class="button button-primary" id="btn-scan-browse-single">
                        <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                    </button>
                    <button type="button" class="button button-primary" id="btn-scan-upload-one" disabled>Procesar con IA</button>
                </div>
                <p id="scan-single-file-name" class="description scan-file-name"></p>
                <div id="scan-single-result" class="scan-upload-status" aria-live="polite"></div>
            </div>
            <div id="scan-tab-bulk" style="display:none;">
                <p class="description" style="margin-bottom:12px;">
                    Seleccione <strong>varios PDF o imágenes</strong>. Se procesarán en secuencia con IA.
                </p>
                <input type="file" id="scan-bulk-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff" multiple style="display:none;">
                <div class="upload-area" id="scan-dropzone-bulk">
                    <span class="dashicons dashicons-media-default" style="font-size:48px;width:48px;height:48px;"></span>
                    <p>Arrastra varios PDF o imágenes aquí</p>
                    <p class="description">PDF, JPG, PNG, WEBP o TIFF</p>
                </div>
                <div class="scan-upload-toolbar">
                    <button type="button" class="button button-primary" id="btn-scan-browse-bulk">
                        <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                    </button>
                    <button type="button" class="button button-primary" id="btn-scan-start-bulk" disabled>Iniciar cola</button>
                </div>
                <div id="scan-bulk-queue"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal detalle escaneo -->
<div id="modal-scan-detail" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content scan-detail-modal">
        <div class="riverso-modal-header">
            <h2>Revisar documento escaneado</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body scan-detail-body">
            <div class="scan-detail-viewer">
                <iframe id="scan-pdf-viewer" title="Vista previa documento"></iframe>
            </div>
            <div class="scan-detail-form">
                <div id="scan-validation-alerts"></div>
                <table class="form-table scan-edit-form">
                    <tr>
                        <th><label for="se-tipo-dte">Tipo DTE</label></th>
                        <td>
                            <select id="se-tipo-dte" class="regular-text scan-tipo-dte-select">
                                <?php foreach ($scan_dte_options as $code => $label) : ?>
                                    <option value="<?php echo esc_attr($code); ?>">
                                        <?php echo esc_html($code . ' — ' . $label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" id="se-tipo-dte-hint"></p>
                        </td>
                    </tr>
                    <tr><th><label for="se-folio">Folio</label></th><td><input type="text" id="se-folio" class="regular-text"></td></tr>
                    <tr><th><label for="se-rut-emisor">RUT emisor</label></th><td><input type="text" id="se-rut-emisor" class="regular-text"></td></tr>
                    <tr><th><label for="se-razon">Razón social</label></th><td><input type="text" id="se-razon" class="large-text"></td></tr>
                    <tr><th><label for="se-fecha">Fecha emisión</label></th><td><input type="date" id="se-fecha"></td></tr>
                    <tr>
                        <th>Montos</th>
                        <td>
                            <div class="scan-totales-grid">
                                <label class="scan-total-field">
                                    <span>Neto</span>
                                    <input type="number" id="se-neto" class="scan-monto-input" step="1" min="0">
                                </label>
                                <label class="scan-total-field">
                                    <span>IVA</span>
                                    <input type="number" id="se-iva" class="scan-monto-input" step="1" min="0">
                                </label>
                                <label class="scan-total-field">
                                    <span>Total</span>
                                    <input type="number" id="se-total" class="scan-monto-input" step="1" min="0">
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr><th>Tipo ingreso</th>
                        <td class="scan-ingreso-fields">
                            <select id="se-doc-tipo">
                                <option value="productos">Productos</option>
                                <option value="envio">Envío</option>
                                <option value="gastos">Gastos</option>
                                <option value="guia_despacho">Guía despacho</option>
                                <option value="nota_credito">Nota crédito</option>
                            </select>
                            <select id="se-modo-ingreso">
                                <option value="solo_costos" selected>Solo costos</option>
                                <option value="recepcion">Recepción</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            <div class="scan-detail-items-section">
                <div class="scan-items-toolbar">
                    <h3>Ítems</h3>
                    <button type="button" class="button button-small" id="btn-scan-items-expand" aria-expanded="false">
                        Expandir tabla
                    </button>
                </div>
                <div id="se-items-wrap" class="scan-items-table-wrap">
                    <p class="description scan-items-empty">Sin ítems detectados</p>
                </div>
                <h3>Referencias</h3>
                <div id="se-refs-wrap" class="scan-refs-wrap"></div>
                <p class="submit scan-detail-actions">
                    <button type="button" class="button" id="btn-scan-save-edit">Guardar cambios</button>
                    <button type="button" class="button button-primary" id="btn-scan-confirm">Confirmar e ingresar</button>
                    <button type="button" class="button" id="btn-scan-discard">Descartar</button>
                </p>
            </div>
        </div>
    </div>
</div>

<style>
.invoices-tab-nav { margin: 12px 0 20px; border-bottom: 1px solid #c3c4c7; }
.invoices-tab-nav button { margin: 0 4px 0 0; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.invoices-tab-nav button.active { background: #fff; border-bottom-color: #fff; font-weight: 600; }
.invoices-tab-badge {
    display: inline-block;
    min-width: 1.35em;
    margin-left: 6px;
    padding: 0 6px;
    border-radius: 999px;
    background: #d63638;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.55;
    vertical-align: middle;
}
.invoices-tab-badge[hidden] { display: none !important; }
.scan-usage-panel { background: #f0f6fc; border: 1px solid #c3d9f0; padding: 8px 12px; margin-bottom: 12px; border-radius: 4px; }
.scan-conf-green { color: #008a20; font-weight: 600; }
.scan-conf-amber { color: #996800; font-weight: 600; }
.scan-conf-red { color: #d63638; font-weight: 600; }
.scan-detail-modal {
    max-width: min(1480px, 96vw) !important;
    width: 100%;
}
.scan-detail-body {
    display: grid;
    grid-template-columns: minmax(320px, 1fr) minmax(360px, 1fr);
    grid-template-rows: auto auto;
    gap: 16px;
    align-items: start;
}
.scan-detail-viewer {
    min-height: 520px;
}
#scan-pdf-viewer {
    width: 100%;
    height: 520px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #f6f7f7;
}
.scan-detail-form {
    max-height: 520px;
    overflow-y: auto;
    padding-right: 4px;
}
.scan-detail-form .form-table th {
    width: 130px;
    padding: 10px 10px 10px 0;
    vertical-align: top;
}
.scan-detail-form .form-table td {
    padding: 8px 0;
}
.scan-tipo-dte-select {
    min-width: 100%;
    max-width: 100%;
}
.scan-totales-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}
.scan-total-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-weight: 600;
    font-size: 12px;
    color: #50575e;
}
.scan-monto-input {
    width: 100%;
    min-width: 0;
    font-size: 14px;
    padding: 6px 8px;
    box-sizing: border-box;
}
.scan-ingreso-fields select {
    min-width: 140px;
    margin-right: 8px;
    margin-bottom: 4px;
}
.scan-detail-items-section {
    grid-column: 1 / -1;
    border-top: 1px solid #dcdcde;
    padding-top: 12px;
}
.scan-items-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
}
.scan-items-toolbar h3 {
    margin: 0;
}
.scan-items-table-wrap {
    max-height: 240px;
    overflow: auto;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #fff;
    margin-bottom: 16px;
}
.scan-detail-body.scan-items-expanded .scan-items-table-wrap {
    max-height: min(60vh, 640px);
}
.scan-detail-body.scan-items-expanded #scan-pdf-viewer {
    height: 360px;
}
.scan-detail-body.scan-items-expanded .scan-detail-form {
    max-height: 360px;
}
table.scan-items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
table.scan-items-table thead th {
    position: sticky;
    top: 0;
    background: #f0f0f1;
    border-bottom: 1px solid #c3c4c7;
    padding: 8px 10px;
    text-align: left;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1;
}
table.scan-items-table tbody td {
    border-bottom: 1px solid #f0f0f1;
    padding: 6px 8px;
    vertical-align: top;
}
table.scan-items-table tbody tr:hover {
    background: #f6f7f7;
}
table.scan-items-table .col-num {
    width: 36px;
    text-align: center;
    color: #646970;
}
table.scan-items-table .col-codigo {
    width: 110px;
}
table.scan-items-table .col-cant,
table.scan-items-table .col-precio,
table.scan-items-table .col-monto {
    width: 100px;
}
table.scan-items-table .col-desc {
    min-width: 220px;
}
.scan-items-table input,
.scan-items-table textarea {
    width: 100%;
    box-sizing: border-box;
    font-size: 13px;
}
.scan-items-table textarea.si-desc {
    min-height: 2.4em;
    resize: vertical;
    line-height: 1.35;
}
.scan-items-table .scan-item-num {
    display: inline-block;
    padding-top: 6px;
}
.scan-refs-wrap {
    margin-bottom: 12px;
    font-size: 13px;
}
.scan-detail-actions {
    margin: 0;
    padding-top: 8px;
    border-top: 1px solid #dcdcde;
}
.scan-items-empty {
    margin: 12px;
}
.scan-upload-tabs { list-style: none; display: flex; gap: 8px; padding: 0; margin-bottom: 12px; }
.scan-upload-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
    justify-content: center;
}
.scan-file-name {
    margin-top: 10px;
    text-align: center;
    font-weight: 500;
}
.scan-upload-status {
    margin-top: 12px;
    min-height: 1.5em;
}
.scan-upload-status .notice {
    margin: 0;
    padding: 10px 12px;
}
.scan-upload-status.is-busy .notice {
    border-left-color: #2271b1;
}
#scan-bulk-queue .bulk-item { padding: 4px 0; border-bottom: 1px solid #eee; font-size: 12px; }
@media (max-width: 1200px) {
    .scan-detail-body {
        grid-template-columns: 1fr;
    }
    .scan-detail-viewer,
    .scan-detail-form {
        max-height: none;
    }
    .scan-totales-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo esc_js(wp_create_nonce('riverso_pos_nonce')); ?>';
    const scanDteLabels = <?php echo wp_json_encode($scan_dte_options); ?>;
    let scanPage = 1;
    let scanDetailId = 0;
    let scanDetailRaw = null;

    function fmtMoney(n) {
        return '$' + Number(n || 0).toLocaleString('es-CL');
    }

    function confClass(level) {
        return 'scan-conf-' + (level || 'red');
    }

    function loadScanUsage() {
        $.post(ajaxurl, { action: 'riverso_scan_usage', nonce }, function(r) {
            if (!r.success) return;
            const d = r.data;
            $('#scan-usage-text').text(
                (d.archivos || 0) + ' archivos · ' + (d.llamadas || 0) + ' llamadas Gemini · ' +
                (d.tokens_in || 0) + ' tok in · ' + (d.reutilizados || 0) + ' reutilizados sin costo'
            );
        });
    }

    function loadScans() {
        $('#scans-tbody').html('<tr><td colspan="9">Cargando…</td></tr>');
        $.post(ajaxurl, {
            action: 'riverso_scan_list',
            nonce,
            page: scanPage,
            per_page: $('#scan-per-page').val(),
            orderby: $('#scan-orderby').val(),
            order: $('#scan-order').val(),
            estado: $('#scan-filter-estado').val(),
            search: $('#scan-filter-search').val(),
            fecha_desde: $('#scan-filter-desde').val(),
            fecha_hasta: $('#scan-filter-hasta').val()
        }, function(r) {
            if (!r.success) {
                $('#scans-tbody').html('<tr><td colspan="9">Error</td></tr>');
                return;
            }
            const items = r.data.items || [];
            if (!items.length) {
                $('#scans-tbody').html('<tr><td colspan="9">Sin documentos en bandeja</td></tr>');
            } else {
                let html = '';
                items.forEach(function(row) {
                    html += '<tr>' +
                        '<td title="' + escAttr(row.nombre_original) + '">' + escHtml(trunc(row.nombre_original, 28)) + '</td>' +
                        '<td>' + escHtml(row.paginas_label) + '</td>' +
                        '<td>' + escHtml(row.tipo_label) + ' ' + escHtml(row.folio || '—') + '</td>' +
                        '<td>' + escHtml(trunc(row.razon_social_emisor || '—', 24)) + '</td>' +
                        '<td>' + escHtml(row.fecha_emision || '—') + '</td>' +
                        '<td>' + fmtMoney(row.monto_total) + '</td>' +
                        '<td class="' + confClass(row.confianza_level) + '">' + Math.round((row.confianza || 0) * 100) + '%</td>' +
                        '<td>' + escHtml(row.estado_revision) +
                        (row.factura_id ? ' · <a href="#" class="scan-link-factura" data-id="' + row.factura_id + '">#' + escHtml(row.factura_folio || row.factura_id) + '</a>' : '') + '</td>' +
                        '<td><button type="button" class="button button-small btn-scan-view" data-id="' + row.id + '">Ver</button></td>' +
                        '</tr>';
                });
                $('#scans-tbody').html(html);
            }
            $('#scan-pagination-info').text('Pág. ' + r.data.page + ' / ' + Math.max(1, r.data.pages) + ' (' + r.data.total + ')');
            loadScanUsage();
            if (typeof window.refreshInvoicesTabBadges === 'function') {
                window.refreshInvoicesTabBadges();
            }
        });
    }
    window.riversoReloadScans = function() {
        scanPage = 1;
        loadScans();
    };

    $(document).on('click', '.scan-link-factura', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        $('.invoices-main-tab[data-panel="panel-xml"]').trigger('click');
        $.post(ajaxurl, { action: 'riverso_get_invoice', nonce, factura_id: id }, function(r) {
            if (r.success) {
                window.showInvoiceDetail(r.data);
                window.showRiversoModal($('#modal-invoice-detail'));
            }
        });
    });

    function escHtml(s) { return $('<div>').text(s || '').html(); }
    function escAttr(s) { return escHtml(s).replace(/"/g, '&quot;'); }
    function trunc(s, n) { s = s || ''; return s.length > n ? s.slice(0, n) + '…' : s; }

    function scanDteLabel(code) {
        const n = parseInt(code, 10);
        return scanDteLabels[n] || 'Tipo DTE ' + n;
    }

    function ensureTipoDteOption(code) {
        const n = parseInt(code, 10);
        if (!n) return;
        const $sel = $('#se-tipo-dte');
        if ($sel.find('option[value="' + n + '"]').length) return;
        $sel.append($('<option>', {
            value: String(n),
            text: n + ' — ' + scanDteLabel(n)
        }));
    }

    function updateTipoDteHint() {
        const n = parseInt($('#se-tipo-dte').val(), 10);
        $('#se-tipo-dte-hint').text(n ? scanDteLabel(n) : '');
    }

    function autoResizeDesc($el) {
        $el.each(function() {
            this.style.height = 'auto';
            this.style.height = Math.max(40, this.scrollHeight) + 'px';
        });
    }

    function setScanItemsExpanded(expanded) {
        const $body = $('#modal-scan-detail .scan-detail-body');
        const $btn = $('#btn-scan-items-expand');
        $body.toggleClass('scan-items-expanded', expanded);
        $btn.attr('aria-expanded', expanded ? 'true' : 'false')
            .text(expanded ? 'Contraer tabla' : 'Expandir tabla');
    }

    window.riversoInitScansPanel = function() {
        loadScans();
        loadScanUsage();
    };

    $('#scan-btn-filter, #scan-per-page, #scan-orderby, #scan-order').on('change click', function() {
        if ($(this).is('button')) scanPage = 1;
        loadScans();
    });
    $('#scan-prev').on('click', function() { if (scanPage > 1) { scanPage--; loadScans(); } });
    $('#scan-next').on('click', function() { scanPage++; loadScans(); });

    $('.scan-tab').on('click', function() {
        $('.scan-tab').removeClass('active');
        $(this).addClass('active');
        const t = $(this).data('tab');
        $('#scan-tab-single').toggle(t === 'single');
        $('#scan-tab-bulk').toggle(t === 'bulk');
    });

    const scanDropSingle = $('#scan-dropzone-single');
    const scanDropBulk = $('#scan-dropzone-bulk');
    const scanFileInput = $('#scan-file-input');
    const scanBulkInput = $('#scan-bulk-input');
    const scanAcceptRe = /\.(pdf|jpe?g|png|webp|tiff?)$/i;

    function isScanFile(file) {
        return file && scanAcceptRe.test(file.name || '');
    }

    function setScanInputFiles(input, fileList) {
        if (!input || !fileList || !fileList.length) return false;
        const dt = new DataTransfer();
        Array.from(fileList).forEach(function(f) { dt.items.add(f); });
        input.files = dt.files;
        return input.files.length > 0;
    }

    function showScanFileError(msg) {
        setScanUploadStatus('error', msg);
    }

    function setScanUploadStatus(type, message) {
        const $el = $('#scan-single-result');
        $el.removeClass('is-busy');
        if (!message) {
            $el.empty();
            return;
        }
        const noticeClass = type === 'success' ? 'notice-success'
            : (type === 'error' ? 'notice-error'
                : (type === 'busy' ? 'notice-info' : 'notice-warning'));
        if (type === 'busy') {
            $el.addClass('is-busy');
        }
        $el.html(
            '<div class="notice ' + noticeClass + ' inline"><p>' +
            (type === 'busy' ? '<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' : '') +
            escHtml(message) +
            '</p></div>'
        );
    }

    function setScanSingleFile(file) {
        if (!file) {
            scanFileInput.val('');
            $('#scan-single-file-name').text('');
            $('#btn-scan-upload-one').prop('disabled', true);
            return;
        }
        if (!isScanFile(file)) {
            showScanFileError('Tipo no permitido. Use PDF, JPG, PNG, WEBP o TIFF.');
            return;
        }
        if (setScanInputFiles(scanFileInput[0], [file])) {
            $('#scan-single-file-name').text(file.name);
            $('#btn-scan-upload-one').prop('disabled', false);
            setScanUploadStatus('', '');
        }
    }

    scanFileInput.on('change', function() {
        const f = this.files && this.files[0];
        if (f) setScanSingleFile(f);
        else {
            $('#scan-single-file-name').text('');
            $('#btn-scan-upload-one').prop('disabled', true);
        }
    });

    $('#btn-scan-browse-single').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        scanFileInput.val('');
        scanFileInput[0].click();
    });

    scanDropSingle.on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    });
    scanDropSingle.on('dragleave', function() {
        $(this).removeClass('dragover');
    });
    scanDropSingle.on('click', function(e) {
        if ($(e.target).closest('#btn-scan-browse-single, #btn-scan-upload-one').length) return;
        e.preventDefault();
        scanFileInput.val('');
        scanFileInput[0].click();
    });
    scanDropSingle.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (!files.length) return;
        setScanSingleFile(files[0]);
    });

    let scanBulkQueue = [];

    function renderScanBulkQueue() {
        const $q = $('#scan-bulk-queue').empty();
        if (!scanBulkQueue.length) {
            $('#btn-scan-start-bulk').prop('disabled', true);
            return;
        }
        scanBulkQueue.forEach(function(f, i) {
            $q.append('<div class="bulk-item" data-i="' + i + '">' + escHtml(f.name) + ' <span class="st">pendiente</span></div>');
        });
        $('#btn-scan-start-bulk').prop('disabled', false);
    }

    function setScanBulkFiles(fileList) {
        scanBulkQueue = Array.from(fileList || []).filter(isScanFile);
        if (fileList && fileList.length && !scanBulkQueue.length) {
            showScanFileError('Ningún archivo válido. Use PDF, JPG, PNG, WEBP o TIFF.');
        }
        if (scanBulkQueue.length && setScanInputFiles(scanBulkInput[0], scanBulkQueue)) {
            renderScanBulkQueue();
        } else if (!scanBulkQueue.length) {
            scanBulkInput.val('');
            renderScanBulkQueue();
        }
    }

    scanBulkInput.on('change', function() {
        if (this.files && this.files.length) setScanBulkFiles(this.files);
    });

    $('#btn-scan-browse-bulk').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        scanBulkInput.val('');
        scanBulkInput[0].click();
    });

    scanDropBulk.on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    });
    scanDropBulk.on('dragleave', function() {
        $(this).removeClass('dragover');
    });
    scanDropBulk.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) setScanBulkFiles(files);
    });

    function resetScanUploadModal() {
        scanFileInput.val('');
        scanBulkInput.val('');
        scanBulkQueue = [];
        scanDropSingle.removeClass('dragover');
        scanDropBulk.removeClass('dragover');
        $('#scan-single-file-name').text('');
        setScanUploadStatus('', '');
        $('#scan-bulk-queue').empty();
        $('#btn-scan-upload-one, #btn-scan-start-bulk').prop('disabled', true);
    }

    $('#btn-upload-scan').on('click', function() {
        resetScanUploadModal();
        window.showRiversoModal($('#modal-upload-scan'));
    });

    function pollScanArchivo(archivoId, onStatus) {
        return new Promise(function(resolve) {
            let attempts = 0;
            const maxAttempts = 90; // ~3 min @ 2s
            const timer = setInterval(function() {
                attempts++;
                $.post(ajaxurl, {
                    action: 'riverso_scan_archivo_status',
                    nonce: nonce,
                    archivo_id: archivoId
                }).done(function(r) {
                    if (!r || !r.success || !r.data) {
                        return;
                    }
                    const d = r.data;
                    if (d.done) {
                        clearInterval(timer);
                        if (d.estado === 'error') {
                            if (typeof onStatus === 'function') {
                                onStatus('error', d.message || d.error_mensaje || 'Error al procesar con IA');
                            }
                            resolve({ ok: false, data: d });
                        } else {
                            if (typeof onStatus === 'function') {
                                onStatus('success', d.message || 'Procesado correctamente');
                            }
                            resolve({ ok: true, data: d });
                        }
                    } else if (typeof onStatus === 'function' && attempts % 3 === 0) {
                        onStatus('busy', d.message || 'Procesando con IA…');
                    }
                }).fail(function() {
                    // Seguir intentando; el proxy puede fallar puntual
                });

                if (attempts >= maxAttempts) {
                    clearInterval(timer);
                    if (typeof onStatus === 'function') {
                        onStatus('error', 'Tiempo de espera agotado. Revisa la bandeja de escaneos en unos minutos.');
                    }
                    resolve({ ok: false, timedOut: true });
                }
            }, 2000);
        });
    }

    function uploadScanFile(file, onStatus) {
        const dfd = $.Deferred();
        const fd = new FormData();
        fd.append('action', 'riverso_scan_upload');
        fd.append('nonce', nonce);
        fd.append('scan_file', file);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            timeout: 60000
        }).done(function(r) {
            if (!r || !r.success) {
                const msg = (r && r.data && r.data.message) ? r.data.message : 'Error al procesar';
                if (typeof onStatus === 'function') onStatus('error', msg);
                dfd.reject(r);
                return;
            }
            if (r.data && r.data.async && r.data.archivo_id) {
                if (typeof onStatus === 'function') {
                    onStatus('busy', r.data.message || 'Procesando con IA en segundo plano…');
                }
                pollScanArchivo(r.data.archivo_id, onStatus).then(function(result) {
                    if (result && result.ok) {
                        dfd.resolve(result.data);
                    } else {
                        dfd.reject(result);
                    }
                });
                return;
            }
            if (typeof onStatus === 'function') {
                onStatus('success', (r.data && r.data.message) ? r.data.message : 'Procesado correctamente');
            }
            dfd.resolve(r.data);
        }).fail(function(xhr) {
            let msg = 'Error de conexión o tiempo de espera';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            } else if (xhr && xhr.status) {
                msg = 'Error HTTP ' + xhr.status + ' al subir el archivo';
            }
            if (typeof onStatus === 'function') onStatus('error', msg);
            dfd.reject(xhr);
        });

        return dfd.promise();
    }

    $('#btn-scan-upload-one').on('click', function() {
        const f = scanFileInput[0].files[0];
        if (!f) {
            setScanUploadStatus('error', 'Seleccione un archivo primero');
            return;
        }
        const $btn = $(this).prop('disabled', true);
        setScanUploadStatus('busy', 'Subiendo archivo…');
        uploadScanFile(f, function(type, message) {
            setScanUploadStatus(type, message);
        }).always(function() {
            $btn.prop('disabled', !scanFileInput[0].files.length);
            loadScans();
        });
    });

    $('#btn-scan-start-bulk').on('click', async function() {
        const btn = $(this).prop('disabled', true);
        for (let i = 0; i < scanBulkQueue.length; i++) {
            const $row = $('#scan-bulk-queue .bulk-item[data-i="' + i + '"] .st');
            $row.text('procesando…');
            try {
                await new Promise(function(resolve) {
                    uploadScanFile(scanBulkQueue[i], function(type) {
                        $row.text(type === 'success' ? '✓' : (type === 'busy' ? 'IA…' : '✗'));
                    }).always(resolve);
                });
            } catch (e) {
                $row.text('✗');
            }
        }
        btn.prop('disabled', false);
        loadScans();
    });

    $(document).on('click', '.btn-scan-view', function() {
        const id = $(this).data('id');
        openScanDetail(id);
    });

    function loadScanViewer(fileUrl) {
        const viewer = $('#scan-pdf-viewer');
        viewer.removeAttr('srcdoc').attr('src', 'about:blank');
        if (!fileUrl) {
            viewer.attr('srcdoc', '<p style="font-family:sans-serif;padding:16px;color:#666;">Vista previa no disponible.</p>');
            return;
        }
        viewer.attr('src', fileUrl);
    }

    function openScanDetail(id) {
        scanDetailId = id;
        setScanItemsExpanded(false);
        $('#scan-pdf-viewer').attr('src', 'about:blank');
        $.post(ajaxurl, { action: 'riverso_scan_get', nonce, id }, function(r) {
            if (!r.success) { alert(r.data?.message || 'Error'); return; }
            const p = r.data.payload || {};
            scanDetailRaw = p.raw || {};
            if (p.normalized) {
                scanDetailRaw.tipo_dte = p.normalized.tipo_dte;
                scanDetailRaw.folio = p.normalized.folio;
                scanDetailRaw.emisor = p.normalized.emisor;
                scanDetailRaw.fecha_emision = p.normalized.fecha_emision;
                scanDetailRaw.totales = p.normalized.totales;
                scanDetailRaw.items = (p.raw && p.raw.items) || scanDetailRaw.items;
                scanDetailRaw.referencias = (p.raw && p.raw.referencias) || scanDetailRaw.referencias;
            }
            fillScanForm(scanDetailRaw, r.data);
            loadScanViewer(r.data.file_url || '');
            window.showRiversoModal($('#modal-scan-detail'));
        });
    }

    function fillScanForm(raw, data) {
        const tipoDte = parseInt(raw.tipo_dte, 10) || 33;
        ensureTipoDteOption(tipoDte);
        $('#se-tipo-dte').val(String(tipoDte));
        updateTipoDteHint();
        $('#se-folio').val(raw.folio || '');
        $('#se-rut-emisor').val((raw.emisor && raw.emisor.rut) || '');
        $('#se-razon').val((raw.emisor && raw.emisor.razon_social) || '');
        $('#se-fecha').val(raw.fecha_emision || '');
        const t = raw.totales || {};
        $('#se-neto').val(t.neto || 0);
        $('#se-iva').val(t.iva || 0);
        $('#se-total').val(t.total || 0);

        const val = (data.documento && data.documento.validacion) || {};
        let alerts = '';
        (val.errors || []).forEach(function(e) { alerts += '<div class="notice notice-error inline"><p>' + escHtml(e) + '</p></div>'; });
        (val.warnings || []).forEach(function(w) { alerts += '<div class="notice notice-warning inline"><p>' + escHtml(w) + '</p></div>'; });
        $('#scan-validation-alerts').html(alerts);

        const items = raw.items || [];
        if (!items.length) {
            $('#se-items-wrap').html('<p class="description scan-items-empty">Sin ítems detectados</p>');
        } else {
            let itemsHtml = '<table class="scan-items-table"><thead><tr>' +
                '<th class="col-num">#</th>' +
                '<th class="col-codigo">Código</th>' +
                '<th class="col-desc">Descripción</th>' +
                '<th class="col-cant">Cantidad</th>' +
                '<th class="col-precio">P. unitario</th>' +
                '<th class="col-monto">Monto</th>' +
                '</tr></thead><tbody>';
            items.forEach(function(it, idx) {
                itemsHtml += '<tr class="scan-item-row" data-idx="' + idx + '">' +
                    '<td class="col-num"><span class="scan-item-num">' + (idx + 1) + '</span></td>' +
                    '<td class="col-codigo"><input type="text" class="si-codigo" value="' + escAttr(it.codigo || '') + '" placeholder="SKU / código"></td>' +
                    '<td class="col-desc"><textarea class="si-desc" rows="2" placeholder="Descripción del ítem">' + escHtml(it.descripcion || '') + '</textarea></td>' +
                    '<td class="col-cant"><input type="number" class="si-cant" value="' + escAttr(it.cantidad || 0) + '" step="any" min="0"></td>' +
                    '<td class="col-precio"><input type="number" class="si-precio" value="' + escAttr(it.precio_unitario || 0) + '" step="any" min="0"></td>' +
                    '<td class="col-monto"><input type="number" class="si-monto" value="' + escAttr(it.monto_total || 0) + '" step="any" min="0"></td>' +
                    '</tr>';
            });
            itemsHtml += '</tbody></table>';
            $('#se-items-wrap').html(itemsHtml);
            autoResizeDesc($('#se-items-wrap .si-desc'));
        }

        let refsHtml = '';
        (raw.referencias || []).forEach(function(ref) {
            refsHtml += '<div>' + escHtml(ref.tipo) + ' ' + escHtml(ref.folio) + ' ' + escHtml(ref.fecha || '') + '</div>';
        });
        $('#se-refs-wrap').html(refsHtml || '<p class="description">Sin referencias</p>');
    }

    $('#se-tipo-dte').on('change', updateTipoDteHint);

    $('#btn-scan-items-expand').on('click', function() {
        const expanded = !$('#modal-scan-detail .scan-detail-body').hasClass('scan-items-expanded');
        setScanItemsExpanded(expanded);
        autoResizeDesc($('#se-items-wrap .si-desc'));
    });

    $(document).on('input', '#se-items-wrap .si-desc', function() {
        autoResizeDesc($(this));
    });

    function collectScanFormData() {
        const raw = $.extend(true, {}, scanDetailRaw || {});
        raw.tipo_dte = parseInt($('#se-tipo-dte').val(), 10) || 33;
        raw.folio = $('#se-folio').val();
        raw.fecha_emision = $('#se-fecha').val();
        raw.emisor = raw.emisor || {};
        raw.emisor.rut = $('#se-rut-emisor').val();
        raw.emisor.razon_social = $('#se-razon').val();
        raw.totales = raw.totales || {};
        raw.totales.neto = parseFloat($('#se-neto').val()) || 0;
        raw.totales.iva = parseFloat($('#se-iva').val()) || 0;
        raw.totales.total = parseFloat($('#se-total').val()) || 0;
        raw.totales.tasa_iva = raw.totales.tasa_iva || 19;
        raw.items = [];
        $('#se-items-wrap .scan-item-row').each(function(i) {
            raw.items.push({
                numero: i + 1,
                codigo: $(this).find('.si-codigo').val(),
                descripcion: $(this).find('.si-desc').val(),
                cantidad: parseFloat($(this).find('.si-cant').val()) || 0,
                precio_unitario: parseFloat($(this).find('.si-precio').val()) || 0,
                monto_total: parseFloat($(this).find('.si-monto').val()) || 0,
                confianza: 0.9
            });
        });
        raw.confianza_global = 0.9;
        return raw;
    }

    $('#btn-scan-save-edit').on('click', function() {
        const data = collectScanFormData();
        $.post(ajaxurl, {
            action: 'riverso_scan_update', nonce,
            id: scanDetailId,
            data: JSON.stringify(data)
        }, function(r) {
            alert(r.success ? r.data.message : (r.data?.message || 'Error'));
            if (r.success) loadScans();
        });
    });

    $('#btn-scan-confirm').on('click', function() {
        const data = collectScanFormData();
        $.post(ajaxurl, {
            action: 'riverso_scan_update', nonce,
            id: scanDetailId,
            data: JSON.stringify(data)
        }).then(function() {
            return $.post(ajaxurl, {
                action: 'riverso_scan_confirm', nonce,
                id: scanDetailId,
                documento_tipo: $('#se-doc-tipo').val(),
                modo_ingreso: $('#se-modo-ingreso').val()
            });
        }).done(function(r) {
            alert(r.success ? r.data.message : (r.data?.message || 'Error'));
            if (r.success) {
                window.hideRiversoModal($('#modal-scan-detail'));
                loadScans();
            }
        });
    });

    $('#btn-scan-discard').on('click', function() {
        if (!confirm('¿Descartar este documento?')) return;
        $.post(ajaxurl, { action: 'riverso_scan_discard', nonce, id: scanDetailId }, function(r) {
            if (r.success) {
                window.hideRiversoModal($('#modal-scan-detail'));
                loadScans();
            }
        });
    });
});
</script>
