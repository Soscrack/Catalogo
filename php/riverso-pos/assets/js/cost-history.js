/**
 * Riverso Cost Explorer — historial de costos por par (proveedor, código proveedor)
 * Requiere: jQuery, Chart.js 3.x, riversoCostHistory (wp_localize_script)
 */
(function ($) {
    'use strict';

    var cfg = window.riversoCostHistory || {};
    var ajaxUrl = cfg.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = cfg.nonce || '';

    var state = {
        product: null,
        highlightCode: null,
        chart: null,
        searchTimer: null,
        currentSelection: null,
        showDecimals: true,
        limitPerPair: 3,
        docType: 'factura',
        lastExplorerData: null,
        lastDocument: null,
        lastDocHighlight: null,
        lastChartData: null,
        evoSelection: null,
        evoChart: null,
        evoDocType: 'factura',
        evoLimit: 3,
    };

    var COLORS = [
        'rgba(33, 113, 177, 1)',
        'rgba(0, 163, 42, 1)',
        'rgba(214, 54, 56, 1)',
        'rgba(153, 104, 0, 1)',
        'rgba(124, 58, 237, 1)',
        'rgba(0, 124, 186, 1)',
        'rgba(214, 54, 120, 1)',
    ];

    /**
     * Costos: por defecto hasta 3 decimales (sin ceros finales).
     * Con decimales off → entero redondeado.
     */
    function formatMoney(n) {
        if (n === null || n === undefined || n === '' || isNaN(n)) {
            return '—';
        }
        var num = Number(n);
        if (!isFinite(num)) {
            return '—';
        }
        if (!state.showDecimals) {
            return '$' + Math.round(num).toLocaleString('es-CL');
        }
        var rounded = Math.round(num * 1000) / 1000;
        var fixed = rounded.toFixed(3).replace(/\.?0+$/, '');
        var parts = fixed.split('.');
        var intPart = Number(parts[0]).toLocaleString('es-CL');
        return parts.length > 1 ? ('$' + intPart + ',' + parts[1]) : ('$' + intPart);
    }

    function formatPct(n) {
        if (n === null || n === undefined || isNaN(n)) {
            return '';
        }
        var sign = n > 0 ? '+' : '';
        return sign + Number(n).toFixed(1) + '%';
    }

    function tipoDteLabel(tipo) {
        var map = { 33: 'Factura', 34: 'Exenta', 52: 'Guía', 61: 'N. Crédito' };
        return map[parseInt(tipo, 10)] || ('DTE ' + tipo);
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = nonce;
        return $.post(ajaxUrl, data);
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function init() {
        if (!$('#riverso-cost-explorer').length) {
            return;
        }

        // Evitar que el modal quede oculto al cambiar tabs (está dentro de #tab-explorer)
        var $modal = $('#rce-doc-modal');
        if ($modal.length && !$modal.parent().is('body')) {
            $modal.appendTo('body');
        }
        var $evoModal = $('#rce-evo-modal');
        if ($evoModal.length && !$evoModal.parent().is('body')) {
            $evoModal.appendTo('body');
        }

        $('#rce-search-input').on('input', function () {
            var term = $.trim($(this).val());
            clearTimeout(state.searchTimer);
            if (term.length < 2) {
                $('#rce-search-results').attr('hidden', true).empty();
                return;
            }
            state.searchTimer = setTimeout(function () {
                doSearch(term);
            }, 280);
        });

        $('#rce-search-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(state.searchTimer);
                doSearch($.trim($(this).val()));
            }
        });

        $('#rce-btn-search').on('click', function () {
            doSearch($.trim($('#rce-search-input').val()));
        });

        $('#rce-btn-clear').on('click', resetView);

        $('#rce-toggle-decimals').on('change', function () {
            state.showDecimals = $(this).is(':checked');
            refreshMoneyDisplay();
        });
        // Default: decimales ON
        state.showDecimals = $('#rce-toggle-decimals').is(':checked');

        $('#rce-chart-months').on('change', function () {
            if (!state.currentSelection) {
                return;
            }
            reloadChart();
        });

        $('#rce-folio-limit').on('change', function () {
            state.limitPerPair = resolveFolioLimit($(this).val());
            updateFolioNote();
            if (state.currentSelection) {
                reloadTimeline();
            }
        });
        state.limitPerPair = resolveFolioLimit($('#rce-folio-limit').val() || '3');
        updateFolioNote();

        $('#rce-doc-type').on('change', function () {
            state.docType = $(this).val() || 'factura';
            if (state.currentSelection) {
                reloadTimeline();
            }
        });
        state.docType = $('#rce-doc-type').val() || 'factura';

        $(document).on('click', '.rce-result-item', function () {
            var $el = $(this);
            selectResult({
                producto_base_id: $el.data('pb') || null,
                proveedor_id: $el.data('proveedor') || null,
                codigo_proveedor: $el.data('codigo') || null,
                nombre: $el.data('nombre') || '',
                canonical_sku: $el.data('sku') || '',
            });
        });

        $(document).on('click', '.rce-btn-doc', function () {
            var facturaId = $(this).data('factura');
            var highlightCode = $(this).data('codigo') || state.highlightCode;
            openDocument(facturaId, highlightCode);
        });

        $(document).on('click', '.rce-btn-more-folios', function () {
            var total = parseInt($(this).data('total'), 10) || 0;
            var next = parseInt($(this).data('next'), 10) || 0;
            if (next <= 0 && total > 0) {
                next = total;
            }
            if (next <= 0) {
                next = state.limitPerPair + 10;
            }
            state.limitPerPair = next;
            syncFolioSelect(next);
            updateFolioNote();
            reloadTimeline();
        });

        $(document).on('click', '[data-rce-close]', closeModal);

        $(document).on('click', '[data-rce-evo-close]', closeEvoModal);

        $('#rce-evo-doc-type, #rce-evo-folio-limit, #rce-evo-months').on('change', function () {
            if (state.evoSelection) {
                reloadEvoModal();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
                closeEvoModal();
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('#rce-search-results, #rce-search-input, #rce-btn-search').length) {
                $('#rce-search-results').attr('hidden', true);
            }
        });
    }

    function doSearch(term) {
        if (!term || term.length < 1) {
            return;
        }

        var $box = $('#rce-search-results');
        $box.removeAttr('hidden').html('<div class="rce-result-item" disabled>Buscando…</div>');

        post('riverso_cost_search_products', { term: term, limit: 15 })
            .done(function (res) {
                if (!res || !res.success) {
                    $box.html('<div class="rce-result-item">Error: ' + escapeHtml((res && res.data) || 'búsqueda') + '</div>');
                    return;
                }
                renderSearchResults(res.data.results || []);
            })
            .fail(function () {
                $box.html('<div class="rce-result-item">Error de red</div>');
            });
    }

    function renderSearchResults(results) {
        var $box = $('#rce-search-results');
        if (!results.length) {
            $box.html('<div class="rce-result-item">Sin resultados</div>');
            return;
        }

        var html = '';
        results.forEach(function (r) {
            var meta = [];
            if (r.canonical_sku) {
                meta.push('SKU: ' + r.canonical_sku);
            }
            if (r.codigo_proveedor) {
                meta.push('Cód: ' + r.codigo_proveedor);
            }
            if (r.proveedor_nombre) {
                meta.push(r.proveedor_nombre);
            }
            if (r.match_source) {
                meta.push(r.match_source);
            }
            if (r.pares_count) {
                meta.push(r.pares_count + ' par(es)');
            }

            html +=
                '<button type="button" class="rce-result-item"' +
                ' data-pb="' + escapeHtml(r.producto_base_id || '') + '"' +
                ' data-proveedor="' + escapeHtml(r.proveedor_id || '') + '"' +
                ' data-codigo="' + escapeHtml(r.codigo_proveedor || '') + '"' +
                ' data-nombre="' + escapeHtml(r.nombre || '') + '"' +
                ' data-sku="' + escapeHtml(r.canonical_sku || '') + '">' +
                '<span class="rce-result-name">' + escapeHtml(r.nombre || r.canonical_sku || r.codigo_proveedor || 'Sin nombre') + '</span>' +
                '<span class="rce-result-meta">' + escapeHtml(meta.join(' · ')) + '</span>' +
                '</button>';
        });
        $box.html(html);
    }

    function resolveFolioLimit(val) {
        if (val === 'all') {
            return 500;
        }
        var n = parseInt(val, 10);
        if (!n || n < 1) {
            return 3;
        }
        return Math.min(500, n);
    }

    function syncFolioSelect(limit) {
        var $sel = $('#rce-folio-limit');
        if (!$sel.length) {
            return;
        }
        var matched = false;
        $sel.find('option').each(function () {
            var v = $(this).attr('value');
            if (v === 'all') {
                if (limit >= 500) {
                    $sel.val('all');
                    matched = true;
                }
                return;
            }
            if (parseInt(v, 10) === limit) {
                $sel.val(v);
                matched = true;
            }
        });
        if (!matched && limit > 3 && limit < 500) {
            // Valor intermedio (p.ej. desde "Ver más"): dejar el más cercano inferior marcado no aplica;
            // usar "all" solo si es >= max; si no, seleccionar custom vía data.
            if (limit >= 50) {
                $sel.val('50');
            } else if (limit >= 25) {
                $sel.val('25');
            } else if (limit >= 10) {
                $sel.val('10');
            }
        }
    }

    function updateFolioNote() {
        var limit = state.limitPerPair;
        var text = limit >= 500 ? 'todas por par' : ('últimas ' + limit + ' por par');
        $('#rce-folio-note').text(text);
    }

    function buildTimelinePayload(extra) {
        var sel = state.currentSelection;
        if (!sel) {
            return null;
        }
        var payload = {
            limit_per_pair: state.limitPerPair || 3,
            months: $('#rce-chart-months').val() || 24,
            doc_type: state.docType || $('#rce-doc-type').val() || 'factura',
        };
        if (extra) {
            $.extend(payload, extra);
        }
        if (sel.producto_base_id) {
            payload.producto_base_id = sel.producto_base_id;
        } else {
            payload.proveedor_id = sel.proveedor_id;
            payload.codigo_proveedor = sel.codigo_proveedor;
        }
        return payload;
    }

    function reloadTimeline() {
        var payload = buildTimelinePayload();
        if (!payload) {
            return;
        }
        $('#rce-pairs-container').html('<p class="rce-muted">Cargando facturas…</p>');
        post('riverso_cost_get_timeline', payload)
            .done(function (res) {
                if (!res || !res.success) {
                    $('#rce-pairs-container').html(
                        '<p class="rce-muted">Error: ' + escapeHtml((res && res.data) || 'carga') + '</p>'
                    );
                    return;
                }
                renderExplorer(res.data);
            })
            .fail(function () {
                $('#rce-pairs-container').html('<p class="rce-muted">Error de red al cargar facturas</p>');
            });
    }

    function selectResult(sel) {
        state.currentSelection = sel;
        state.highlightCode = sel.codigo_proveedor || null;
        state.limitPerPair = resolveFolioLimit($('#rce-folio-limit').val() || '3');
        state.docType = $('#rce-doc-type').val() || 'factura';
        updateFolioNote();
        $('#rce-search-results').attr('hidden', true).empty();
        $('#rce-empty-state').attr('hidden', true);
        $('#rce-product-panel').removeAttr('hidden');
        $('#rce-pairs-container').html('<p class="rce-muted">Cargando historial…</p>');
        $('#rce-highlight').attr('hidden', true);

        post('riverso_cost_get_timeline', buildTimelinePayload())
            .done(function (res) {
                if (!res || !res.success) {
                    $('#rce-pairs-container').html(
                        '<p class="rce-muted">Error: ' + escapeHtml((res && res.data) || 'carga') + '</p>'
                    );
                    return;
                }
                renderExplorer(res.data);
            })
            .fail(function () {
                $('#rce-pairs-container').html('<p class="rce-muted">Error de red al cargar historial</p>');
            });
    }

    function refreshMoneyDisplay() {
        if (state.lastExplorerData) {
            renderExplorer(state.lastExplorerData);
        }
        if (state.lastDocument) {
            renderDocument(state.lastDocument, state.lastDocHighlight);
        }
    }

    function renderExplorer(data) {
        state.lastExplorerData = data;
        var product = data.product || {};
        state.product = product;

        $('#rce-product-name').text(product.nombre || 'Sin nombre');
        $('#rce-product-sku').text(product.canonical_sku || '—');
        $('#rce-product-pairs-count').text((data.pares || []).length + ' pares');

        renderHighlight(data.summary && data.summary.highlight);
        renderPairs(data.timeline || [], data.summary && data.summary.by_pair ? data.summary.by_pair : []);
        renderChart(data.chart || { labels: [], datasets: [] });
    }

    function renderHighlight(h) {
        var $box = $('#rce-highlight');
        if (!h || h.ultimo_costo === null || h.ultimo_costo === undefined) {
            $box.attr('hidden', true);
            return;
        }
        $box.removeAttr('hidden');
        $('#rce-highlight-cost').text(formatMoney(h.ultimo_costo));
        $('#rce-highlight-pair').text((h.proveedor_nombre || '') + ' / ' + (h.codigo_proveedor || ''));
        $('#rce-highlight-date').text(h.ultimo_fecha || '—');
        $('#rce-highlight-folio').text(h.ultimo_folio ? 'Folio ' + h.ultimo_folio : '');

        var $var = $('#rce-highlight-variation').removeClass('up down').text('');
        if (h.variacion_pct !== null && h.variacion_pct !== undefined) {
            var txt = formatPct(h.variacion_pct) + ' vs anterior';
            $var.text(txt);
            if (h.variacion_pct > 0) {
                $var.addClass('up');
            } else if (h.variacion_pct < 0) {
                $var.addClass('down');
            }
        }
    }

    function summaryForPair(summaries, provId, code) {
        for (var i = 0; i < summaries.length; i++) {
            if (
                parseInt(summaries[i].proveedor_id, 10) === parseInt(provId, 10) &&
                String(summaries[i].codigo_proveedor) === String(code)
            ) {
                return summaries[i];
            }
        }
        return null;
    }

    function renderPairs(timeline, summaries) {
        var $c = $('#rce-pairs-container');
        if (!timeline.length) {
            $c.html('<p class="rce-muted">No hay pares proveedor/código ni facturas asociadas.</p>');
            return;
        }

        var html = '';
        timeline.forEach(function (pair) {
            var sum = summaryForPair(summaries, pair.proveedor_id, pair.codigo_proveedor);
            var docs = pair.documents || [];

            html += '<div class="rce-pair-card">';
            html += '<div class="rce-pair-head">';
            html +=
                '<div><div class="rce-pair-title">' +
                escapeHtml(pair.proveedor_nombre || 'Proveedor') +
                '</div><div class="rce-pair-code">' +
                escapeHtml(pair.codigo_proveedor) +
                '</div></div>';

            if (sum) {
                html += '<div class="rce-pair-stats">';
                html += '<span>Último: <strong>' + formatMoney(sum.ultimo_costo) + '</strong></span>';
                if (sum.variacion_pct !== null && sum.variacion_pct !== undefined) {
                    html += '<span>' + escapeHtml(formatPct(sum.variacion_pct)) + '</span>';
                }
                html += '<span>Min ' + formatMoney(sum.min_costo) + '</span>';
                html += '<span>Max ' + formatMoney(sum.max_costo) + '</span>';
                html += '<span>' + (sum.total_documentos || 0) + ' docs</span>';
                html += '</div>';
            }
            html += '</div>';

            if (!docs.length) {
                html += '<p class="rce-muted" style="padding:12px">Sin documentos para este par con el filtro actual.</p>';
            } else {
                html += '<table class="rce-docs-table"><thead><tr>';
                html += '<th>Fecha</th><th>Documento</th><th>Descripción</th>';
                html += '<th class="rce-num">Cant.</th><th class="rce-num">Costo unit.</th><th></th>';
                html += '</tr></thead><tbody>';

                docs.forEach(function (d, idx) {
                    var cls = idx === 0 ? ' class="is-latest"' : '';
                    var docLabel = d.doc_label || (d.source_kind === 'quote' ? 'Cotización' : tipoDteLabel(d.tipo_dte));
                    html += '<tr' + cls + '>';
                    html += '<td>' + escapeHtml(d.fecha_emision || '—') + '</td>';
                    html +=
                        '<td>' +
                        escapeHtml(docLabel) +
                        ' ' +
                        escapeHtml(d.folio) +
                        '</td>';
                    html += '<td>' + escapeHtml(d.nombre || '—') + '</td>';
                    html += '<td class="rce-num">' + escapeHtml(d.cantidad) + '</td>';
                    html += '<td class="rce-num">' + formatMoney(d.costo_unitario) + '</td>';
                    if (d.source_kind === 'quote') {
                        html += '<td><span class="rce-muted">Cotiz.</span></td>';
                    } else if (d.factura_id) {
                        html +=
                            '<td><button type="button" class="button button-small rce-btn-doc" data-factura="' +
                            escapeHtml(d.factura_id) +
                            '" data-codigo="' +
                            escapeHtml(pair.codigo_proveedor) +
                            '">Ver</button></td>';
                    } else {
                        html += '<td></td>';
                    }
                    html += '</tr>';
                });
                html += '</tbody></table>';

                var totalDocs =
                    typeof pair.total_documentos === 'number'
                        ? pair.total_documentos
                        : sum && typeof sum.total_documentos === 'number'
                          ? sum.total_documentos
                          : docs.length;
                var shown = docs.length;
                var hasMore = pair.has_more === true || shown < totalDocs;
                if (hasMore && totalDocs > shown) {
                    var step = Math.min(totalDocs, Math.max(shown + 10, shown * 2));
                    var remaining = totalDocs - shown;
                    html += '<div class="rce-pair-more">';
                    html +=
                        '<p class="rce-muted">Mostrando ' +
                        shown +
                        ' de ' +
                        totalDocs +
                        ' documentos</p>';
                    html +=
                        '<button type="button" class="button button-small rce-btn-more-folios" data-total="' +
                        totalDocs +
                        '" data-next="' +
                        step +
                        '">Ver más (+' +
                        Math.min(remaining, step - shown) +
                        ')</button>';
                    if (step < totalDocs) {
                        html +=
                            ' <button type="button" class="button button-small rce-btn-more-folios" data-total="' +
                            totalDocs +
                            '" data-next="' +
                            totalDocs +
                            '">Ver todas</button>';
                    }
                    html += '</div>';
                } else if (totalDocs > 3) {
                    html +=
                        '<div class="rce-pair-more"><p class="rce-muted">Mostrando ' +
                        shown +
                        ' de ' +
                        totalDocs +
                        ' documentos</p></div>';
                }
            }
            html += '</div>';
        });

        $c.html(html);
    }

    function reloadChart() {
        if (!state.currentSelection) {
            return;
        }
        var sel = state.currentSelection;
        var payload = { months: $('#rce-chart-months').val() || 24 };
        payload.doc_type = state.docType || $('#rce-doc-type').val() || 'factura';
        if (sel.producto_base_id) {
            payload.producto_base_id = sel.producto_base_id;
        } else {
            payload.proveedor_id = sel.proveedor_id;
            payload.codigo_proveedor = sel.codigo_proveedor;
        }

        post('riverso_cost_get_chart', payload).done(function (res) {
            if (res && res.success) {
                renderChart(res.data);
            }
        });
    }

    function renderChart(chartData) {
        state.lastChartData = chartData;
        var canvas = document.getElementById('rce-cost-chart');
        var $empty = $('#rce-chart-empty');

        if (state.chart) {
            state.chart.destroy();
            state.chart = null;
        }

        if (!canvas || typeof Chart === 'undefined') {
            $empty.text(typeof Chart === 'undefined' ? 'Chart.js no está cargado.' : 'Sin canvas.').removeAttr('hidden');
            return;
        }

        var labels = (chartData && chartData.labels) || [];
        var datasetsIn = (chartData && chartData.datasets) || [];

        if (!labels.length || !datasetsIn.length) {
            $empty.removeAttr('hidden');
            $(canvas).parent().hide();
            return;
        }

        $empty.attr('hidden', true);
        $(canvas).parent().show();

        var datasets = datasetsIn.map(function (ds, idx) {
            var color = COLORS[idx % COLORS.length];
            return {
                label: ds.label,
                data: ds.data,
                borderColor: color,
                backgroundColor: color.replace(', 1)', ', 0.12)'),
                spanGaps: true,
                tension: 0.15,
                pointRadius: 3,
                pointHoverRadius: 5,
            };
        });

        state.chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        type: 'category',
                        ticks: {
                            maxRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 12,
                        },
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function (value) {
                                return formatMoney(value);
                            },
                        },
                    },
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = ctx.parsed.y;
                                if (v === null || v === undefined) {
                                    return ctx.dataset.label + ': —';
                                }
                                return ctx.dataset.label + ': ' + formatMoney(v);
                            },
                        },
                    },
                },
            },
        });
    }

    function openDocument(facturaId, highlightCode) {
        var $modal = $('#rce-doc-modal');
        var $body = $('#rce-doc-body');
        $body.html('<p class="rce-muted">Cargando documento…</p>');
        $modal.removeAttr('hidden');
        $('body').css('overflow', 'hidden');

        post('riverso_cost_get_document', { factura_id: facturaId })
            .done(function (res) {
                if (!res || !res.success) {
                    $body.html('<p class="rce-muted">Error: ' + escapeHtml((res && res.data) || 'documento') + '</p>');
                    return;
                }
                renderDocument(res.data, highlightCode);
            })
            .fail(function () {
                $body.html('<p class="rce-muted">Error de red</p>');
            });
    }

    function renderDocument(doc, highlightCode) {
        state.lastDocument = doc;
        state.lastDocHighlight = highlightCode;
        $('#rce-doc-title').text(
            tipoDteLabel(doc.tipo_dte) + ' N° ' + (doc.folio || '')
        );

        var html = '<div class="rce-doc-grid">';
        html += field('Proveedor', doc.proveedor_nombre);
        html += field('RUT', doc.rut_emisor);
        html += field('Fecha', doc.fecha_emision);
        html += field('Estado', doc.estado);
        html += field('Neto', formatMoney(doc.monto_neto));
        html += field('IVA', formatMoney(doc.monto_iva));
        html += field('Total', formatMoney(doc.monto_total));
        html += '</div>';

        html += '<table class="rce-doc-items"><thead><tr>';
        html += '<th>#</th><th>Código</th><th>Descripción</th>';
        html += '<th class="rce-num">Cant.</th><th class="rce-num">Costo unit.</th><th class="rce-num">Total</th>';
        html += '</tr></thead><tbody>';

        (doc.items || []).forEach(function (it) {
            var isHit =
                highlightCode &&
                String(it.codigo_proveedor || '').toLowerCase() === String(highlightCode).toLowerCase();
            html += '<tr' + (isHit ? ' class="is-highlight"' : '') + '>';
            html += '<td>' + escapeHtml(it.numero_linea) + '</td>';
            html += '<td><code>' + escapeHtml(it.codigo_proveedor || '—') + '</code></td>';
            html += '<td>' + escapeHtml(it.nombre || '—') + '</td>';
            html += '<td class="rce-num">' + escapeHtml(it.cantidad) + '</td>';
            html += '<td class="rce-num">' + formatMoney(it.costo_unitario) + '</td>';
            html += '<td class="rce-num">' + formatMoney(it.monto_total) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';

        $('#rce-doc-body').html(html);

        var $hit = $('#rce-doc-body tr.is-highlight').first();
        if ($hit.length) {
            $hit[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function field(label, value) {
        return (
            '<div class="rce-doc-field"><label>' +
            escapeHtml(label) +
            '</label><span>' +
            escapeHtml(value == null ? '—' : value) +
            '</span></div>'
        );
    }

    function closeModal() {
        $('#rce-doc-modal').attr('hidden', true);
        if ($('#rce-evo-modal').is('[hidden]')) {
            $('body').css('overflow', '');
        }
    }

    function closeEvoModal() {
        $('#rce-evo-modal').attr('hidden', true);
        if (state.evoChart) {
            state.evoChart.destroy();
            state.evoChart = null;
        }
        if ($('#rce-doc-modal').is('[hidden]')) {
            $('body').css('overflow', '');
        }
    }

    function resolveEvoLimit(val) {
        if (val === 'all') {
            return 500;
        }
        var n = parseInt(val, 10);
        return isNaN(n) ? 3 : Math.max(1, Math.min(500, n));
    }

    function openEvolutionModal(opts) {
        opts = opts || {};
        var $modal = $('#rce-evo-modal');
        if (!$modal.length) {
            return false;
        }

        state.evoSelection = {
            producto_base_id: opts.producto_base_id || null,
            proveedor_id: opts.proveedor_id || null,
            codigo_proveedor: opts.codigo_proveedor || null,
            nombre: opts.nombre || opts.codigo_proveedor || '',
            canonical_sku: opts.canonical_sku || opts.sku_local || '',
        };

        var docType = opts.doc_type || 'factura';
        if (['factura', 'guias', 'cotizaciones', 'todos'].indexOf(docType) < 0) {
            docType = 'factura';
        }
        $('#rce-evo-doc-type').val(docType);
        state.evoDocType = docType;
        state.evoLimit = resolveEvoLimit($('#rce-evo-folio-limit').val() || '3');

        var titleBits = [];
        if (state.evoSelection.nombre) {
            titleBits.push(state.evoSelection.nombre);
        }
        if (state.evoSelection.codigo_proveedor) {
            titleBits.push(state.evoSelection.codigo_proveedor);
        }
        $('#rce-evo-title').text('Evolución de costo');
        $('#rce-evo-subtitle').text(titleBits.join(' · ') || '—');
        $('#rce-evo-pairs').html('<p class="rce-muted">Cargando…</p>');
        $('#rce-evo-highlight').attr('hidden', true);
        $('#rce-evo-chart-empty').attr('hidden', true);

        $modal.removeAttr('hidden');
        $('body').css('overflow', 'hidden');
        reloadEvoModal();
        return true;
    }

    function openEvolutionModalByTerm(term, opts) {
        opts = opts || {};
        term = $.trim(term || '');
        if (!term) {
            return false;
        }
        var $modal = $('#rce-evo-modal');
        if (!$modal.length) {
            return false;
        }
        $('#rce-evo-title').text('Evolución de costo');
        $('#rce-evo-subtitle').text(term);
        $('#rce-evo-pairs').html('<p class="rce-muted">Buscando…</p>');
        $modal.removeAttr('hidden');
        $('body').css('overflow', 'hidden');

        post('riverso_cost_search_products', { term: term, limit: 5 })
            .done(function (res) {
                var results = (res && res.success && res.data && res.data.results) || [];
                if (!results.length) {
                    $('#rce-evo-pairs').html(
                        '<p class="rce-muted">Sin resultados para ' + escapeHtml(term) + '</p>'
                    );
                    return;
                }
                var r = results[0];
                openEvolutionModal({
                    producto_base_id: r.producto_base_id || null,
                    proveedor_id: r.proveedor_id || null,
                    codigo_proveedor: r.codigo_proveedor || null,
                    nombre: r.nombre || '',
                    canonical_sku: r.canonical_sku || '',
                    doc_type: opts.doc_type,
                });
            })
            .fail(function () {
                $('#rce-evo-pairs').html('<p class="rce-muted">Error de red al buscar</p>');
            });
        return true;
    }

    function reloadEvoModal() {
        if (!state.evoSelection) {
            return;
        }
        state.evoDocType = $('#rce-evo-doc-type').val() || 'factura';
        state.evoLimit = resolveEvoLimit($('#rce-evo-folio-limit').val() || '3');
        var months = $('#rce-evo-months').val() || 24;

        var payload = {
            limit_per_pair: state.evoLimit,
            months: months,
            doc_type: state.evoDocType,
        };
        if (state.evoSelection.producto_base_id) {
            payload.producto_base_id = state.evoSelection.producto_base_id;
        } else {
            payload.proveedor_id = state.evoSelection.proveedor_id;
            payload.codigo_proveedor = state.evoSelection.codigo_proveedor;
        }

        $('#rce-evo-pairs').html('<p class="rce-muted">Cargando…</p>');
        post('riverso_cost_get_timeline', payload)
            .done(function (res) {
                if (!res || !res.success) {
                    $('#rce-evo-pairs').html(
                        '<p class="rce-muted">Error: ' + escapeHtml((res && res.data) || 'carga') + '</p>'
                    );
                    return;
                }
                renderEvoModal(res.data);
            })
            .fail(function () {
                $('#rce-evo-pairs').html('<p class="rce-muted">Error de red</p>');
            });
    }

    function renderEvoModal(data) {
        var product = (data && data.product) || {};
        var subtitle = (product.nombre || state.evoSelection.nombre || '') +
            (product.canonical_sku ? ' · SKU ' + product.canonical_sku : '');
        if (subtitle) {
            $('#rce-evo-subtitle').text(subtitle);
        }

        var h = data.summary && data.summary.highlight;
        var $hl = $('#rce-evo-highlight');
        if (h && h.ultimo_costo != null) {
            $('#rce-evo-highlight-cost').text(formatMoney(h.ultimo_costo));
            $('#rce-evo-highlight-pair').text(
                (h.proveedor_nombre || '') + ' / ' + (h.codigo_proveedor || '')
            );
            $('#rce-evo-highlight-date').text(h.ultimo_fecha || '');
            var $v = $('#rce-evo-highlight-variation');
            if (h.variacion_pct != null) {
                $v.text(formatPct(h.variacion_pct)).removeClass('up down');
                if (h.variacion_pct > 0) {
                    $v.addClass('up');
                } else if (h.variacion_pct < 0) {
                    $v.addClass('down');
                }
            } else {
                $v.text('');
            }
            $hl.removeAttr('hidden');
        } else {
            $hl.attr('hidden', true);
        }

        // Reuse pair cards HTML into modal container
        var $c = $('#rce-evo-pairs');
        var timeline = data.timeline || [];
        var summaries = (data.summary && data.summary.by_pair) || [];
        if (!timeline.length) {
            $c.html('<p class="rce-muted">Sin documentos para este filtro.</p>');
        } else {
            // Temporarily swap container id usage by rendering into #rce-evo-pairs
            var prevId = 'rce-pairs-container';
            var htmlBuf = '';
            // Inline compact render (same structure as renderPairs)
            timeline.forEach(function (pair) {
                var sum = summaryForPair(summaries, pair.proveedor_id, pair.codigo_proveedor);
                var docs = pair.documents || [];
                htmlBuf += '<div class="rce-pair-card">';
                htmlBuf +=
                    '<div class="rce-pair-head"><div><div class="rce-pair-title">' +
                    escapeHtml(pair.proveedor_nombre || 'Proveedor') +
                    '</div><div class="rce-pair-code">' +
                    escapeHtml(pair.codigo_proveedor) +
                    '</div></div>';
                if (sum) {
                    htmlBuf +=
                        '<div class="rce-pair-stats"><span>Último: <strong>' +
                        formatMoney(sum.ultimo_costo) +
                        '</strong></span>';
                    if (sum.variacion_pct != null) {
                        htmlBuf += '<span>' + escapeHtml(formatPct(sum.variacion_pct)) + '</span>';
                    }
                    htmlBuf += '<span>' + (sum.total_documentos || 0) + ' docs</span></div>';
                }
                htmlBuf += '</div>';
                if (!docs.length) {
                    htmlBuf +=
                        '<p class="rce-muted" style="padding:12px">Sin documentos con el filtro actual.</p>';
                } else {
                    htmlBuf +=
                        '<table class="rce-docs-table"><thead><tr><th>Fecha</th><th>Documento</th><th>Descripción</th><th class="rce-num">Costo</th><th></th></tr></thead><tbody>';
                    docs.forEach(function (d, idx) {
                        var docLabel =
                            d.doc_label ||
                            (d.source_kind === 'quote' ? 'Cotización' : tipoDteLabel(d.tipo_dte));
                        htmlBuf += '<tr' + (idx === 0 ? ' class="is-latest"' : '') + '>';
                        htmlBuf += '<td>' + escapeHtml(d.fecha_emision || '—') + '</td>';
                        htmlBuf +=
                            '<td>' + escapeHtml(docLabel) + ' ' + escapeHtml(d.folio) + '</td>';
                        htmlBuf += '<td>' + escapeHtml(d.nombre || '—') + '</td>';
                        htmlBuf += '<td class="rce-num">' + formatMoney(d.costo_unitario) + '</td>';
                        if (d.source_kind === 'quote' || !d.factura_id) {
                            htmlBuf += '<td></td>';
                        } else {
                            htmlBuf +=
                                '<td><button type="button" class="button button-small rce-btn-doc" data-factura="' +
                                escapeHtml(d.factura_id) +
                                '" data-codigo="' +
                                escapeHtml(pair.codigo_proveedor) +
                                '">Ver</button></td>';
                        }
                        htmlBuf += '</tr>';
                    });
                    htmlBuf += '</tbody></table>';
                }
                htmlBuf += '</div>';
            });
            $c.html(htmlBuf);
            void prevId;
        }

        renderEvoChart(data.chart || { labels: [], datasets: [] });
    }

    function renderEvoChart(chartData) {
        var canvas = document.getElementById('rce-evo-chart');
        var $empty = $('#rce-evo-chart-empty');
        if (state.evoChart) {
            state.evoChart.destroy();
            state.evoChart = null;
        }
        if (!canvas || typeof Chart === 'undefined') {
            $empty.text(typeof Chart === 'undefined' ? 'Chart.js no está cargado.' : 'Sin canvas.').removeAttr('hidden');
            return;
        }
        var labels = (chartData && chartData.labels) || [];
        var datasetsIn = (chartData && chartData.datasets) || [];
        if (!labels.length || !datasetsIn.length) {
            $empty.removeAttr('hidden');
            $(canvas).parent().hide();
            return;
        }
        $empty.attr('hidden', true);
        $(canvas).parent().show();
        var datasets = datasetsIn.map(function (ds, idx) {
            var color = COLORS[idx % COLORS.length];
            return {
                label: ds.label,
                data: ds.data,
                borderColor: color,
                backgroundColor: color.replace(', 1)', ', 0.12)'),
                spanGaps: true,
                tension: 0.15,
                pointRadius: 3,
            };
        });
        state.evoChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: datasets.length > 1 } },
                scales: {
                    x: { ticks: { maxRotation: 45, autoSkip: true, maxTicksLimit: 10 } },
                    y: {
                        ticks: {
                            callback: function (v) {
                                return formatMoney(v);
                            },
                        },
                    },
                },
            },
        });
    }

    function resetView() {
        state.currentSelection = null;
        state.product = null;
        state.highlightCode = null;
        state.lastExplorerData = null;
        state.lastDocument = null;
        state.lastDocHighlight = null;
        state.lastChartData = null;
        state.limitPerPair = resolveFolioLimit($('#rce-folio-limit').val() || '3');
        updateFolioNote();
        if (state.chart) {
            state.chart.destroy();
            state.chart = null;
        }
        $('#rce-product-panel').attr('hidden', true);
        $('#rce-empty-state').removeAttr('hidden');
        $('#rce-search-input').val('').focus();
        $('#rce-search-results').attr('hidden', true).empty();
        closeModal();
    }

    /**
     * API pública: abrir evolución por par / producto (p.ej. desde Alertas).
     */
    window.riversoCostExplorer = {
        openPair: function (opts) {
            opts = opts || {};
            if (!$('#riverso-cost-explorer').length) {
                return false;
            }
            if (opts.doc_type && $('#rce-doc-type').length) {
                $('#rce-doc-type').val(opts.doc_type);
                state.docType = opts.doc_type;
            }
            selectResult({
                producto_base_id: opts.producto_base_id || null,
                proveedor_id: opts.proveedor_id || null,
                codigo_proveedor: opts.codigo_proveedor || null,
                nombre: opts.nombre || opts.codigo_proveedor || '',
                canonical_sku: opts.canonical_sku || opts.sku_local || '',
            });
            var el = document.getElementById('rce-product-panel');
            if (el) {
                setTimeout(function () {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 80);
            }
            return true;
        },
        openBySearchTerm: function (term, opts) {
            opts = opts || {};
            term = $.trim(term || '');
            if (!term || !$('#riverso-cost-explorer').length) {
                return false;
            }
            if (opts.doc_type && $('#rce-doc-type').length) {
                $('#rce-doc-type').val(opts.doc_type);
                state.docType = opts.doc_type;
            }
            $('#rce-search-input').val(term);
            $('#rce-pairs-container').html('<p class="rce-muted">Buscando…</p>');
            $('#rce-empty-state').attr('hidden', true);
            $('#rce-product-panel').removeAttr('hidden');
            post('riverso_cost_search_products', { term: term, limit: 5 })
                .done(function (res) {
                    var results = (res && res.success && res.data && res.data.results) || [];
                    if (!results.length) {
                        $('#rce-pairs-container').html(
                            '<p class="rce-muted">Sin resultados para ' + escapeHtml(term) + '</p>'
                        );
                        return;
                    }
                    var r = results[0];
                    selectResult({
                        producto_base_id: r.producto_base_id || null,
                        proveedor_id: r.proveedor_id || null,
                        codigo_proveedor: r.codigo_proveedor || null,
                        nombre: r.nombre || '',
                        canonical_sku: r.canonical_sku || '',
                    });
                })
                .fail(function () {
                    $('#rce-pairs-container').html('<p class="rce-muted">Error de red al buscar</p>');
                });
            return true;
        },
        openEvolutionModal: openEvolutionModal,
        openEvolutionModalByTerm: openEvolutionModalByTerm,
        closeEvolutionModal: closeEvoModal,
    };

    $(init);
})(jQuery);
