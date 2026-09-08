/**
 * Mapeo manual: (Proveedor, Código-Proveedor) → SKU local
 */
(function ($) {
    'use strict';

    var cfg = window.riversoManualMapping || {};
    var ajaxUrl = cfg.ajax_url || (window.riverso_pos && riverso_pos.ajax_url) || '';
    var nonce = cfg.nonce || (window.riverso_pos && riverso_pos.nonce) || '';

    var state = {
        proveedorId: 0,
        proveedorNombre: '',
        codigo: '',
        descripcion: '',
        sku: '',
        skuNombre: '',
        currentSku: null,
        force: false,
        timers: {},
    };

    function post(action, data) {
        return $.post(ajaxUrl, $.extend({
            action: action,
            nonce: nonce,
        }, data || {}));
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMoney(n) {
        if (n == null || n === '' || isNaN(n)) {
            return '—';
        }
        return Number(n).toLocaleString('es-CL', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 3,
        });
    }

    function hidePickers() {
        $('.rmm-picker').attr('hidden', true).empty();
    }

    function setError(msg) {
        var $el = $('#rmm-error');
        if (!msg) {
            $el.attr('hidden', true).empty();
            return;
        }
        $el.removeAttr('hidden').text(msg);
        $('#rmm-success').attr('hidden', true);
    }

    function setSuccess(msg) {
        var $el = $('#rmm-success');
        if (!msg) {
            $el.attr('hidden', true).empty();
            return;
        }
        $el.removeAttr('hidden').text(msg);
        $('#rmm-error').attr('hidden', true);
    }

    function setConflict(data) {
        var $el = $('#rmm-conflict');
        if (!data) {
            $el.attr('hidden', true).empty();
            state.force = false;
            return;
        }
        var msg = data.message || 'Conflicto de mapeo';
        var html = '<div>' + escapeHtml(msg) + '</div>';
        html += '<button type="button" class="button button-small" id="rmm-btn-force" style="margin-top:8px;">Forzar reasignación</button>';
        $el.removeAttr('hidden').html(html);
    }

    function updateSaveState() {
        var ready = state.proveedorId && state.codigo && state.sku;
        $('#rmm-btn-save').prop('disabled', !ready);
        $('#rmm-btn-unlink').prop('disabled', !(state.proveedorId && state.codigo && state.currentSku));
        $('#rmm-codigo-search').prop('disabled', !state.proveedorId);
        $('#rmm-sku-search').prop('disabled', !(state.proveedorId && state.codigo));
    }

    function bindPicker($input, $results, fetchFn, onSelect) {
        var timerKey = $input.attr('id');
        $input.on('input', function () {
            var term = $.trim($input.val());
            clearTimeout(state.timers[timerKey]);
            if (term.length < 1) {
                $results.attr('hidden', true).empty();
                return;
            }
            state.timers[timerKey] = setTimeout(function () {
                fetchFn(term).done(function (items) {
                    if (!items || !items.length) {
                        $results.html('<div class="rmm-picker-item"><span class="rmm-pi-meta">Sin resultados</span></div>')
                            .removeAttr('hidden');
                        return;
                    }
                    var html = '';
                    items.forEach(function (item) {
                        html +=
                            '<button type="button" class="rmm-picker-item" data-json="' +
                            escapeHtml(JSON.stringify(item)) + '">' +
                            '<div class="rmm-pi-main">' + escapeHtml(item.label || '') + '</div>' +
                            (item.meta ? '<div class="rmm-pi-meta">' + escapeHtml(item.meta) + '</div>' : '') +
                            '</button>';
                    });
                    $results.html(html).removeAttr('hidden');
                });
            }, 250);
        });

        $results.on('click', '.rmm-picker-item', function () {
            var raw = $(this).attr('data-json');
            if (!raw) {
                return;
            }
            try {
                onSelect(JSON.parse(raw));
            } catch (e) { /* ignore */ }
            $results.attr('hidden', true).empty();
        });
    }

    function selectProveedor(item) {
        state.proveedorId = item.id;
        state.proveedorNombre = item.nombre || item.label || '';
        $('#rmm-proveedor-id').val(item.id);
        $('#rmm-proveedor-search').val(state.proveedorNombre);
        $('#rmm-proveedor-selected')
            .removeAttr('hidden')
            .html('<strong>' + escapeHtml(state.proveedorNombre) + '</strong>' +
                (item.rut ? ' · ' + escapeHtml(item.rut) : ''));
        // Reset código/sku al cambiar proveedor
        state.codigo = '';
        state.descripcion = '';
        state.sku = '';
        state.skuNombre = '';
        state.currentSku = null;
        $('#rmm-codigo').val('');
        $('#rmm-codigo-search').val('').prop('disabled', false);
        $('#rmm-codigo-selected').attr('hidden', true).empty();
        $('#rmm-sku').val('');
        $('#rmm-sku-search').val('').prop('disabled', true);
        $('#rmm-sku-selected').attr('hidden', true).empty();
        hidePreview();
        setConflict(null);
        setError(null);
        setSuccess(null);
        updateSaveState();
        loadUnmapped();
    }

    function selectCodigo(item) {
        state.codigo = item.codigo_proveedor || item.codigo || '';
        state.descripcion = item.descripcion || '';
        $('#rmm-codigo').val(state.codigo);
        $('#rmm-codigo-search').val(state.codigo);
        var meta = [];
        if (state.descripcion) {
            meta.push(state.descripcion);
        }
        if (item.sku_local) {
            meta.push('SKU actual: ' + item.sku_local);
        }
        $('#rmm-codigo-selected')
            .removeAttr('hidden')
            .html('<strong>' + escapeHtml(state.codigo) + '</strong>' +
                (meta.length ? '<div class="rmm-pi-meta">' + escapeHtml(meta.join(' · ')) + '</div>' : ''));
        state.sku = '';
        state.skuNombre = '';
        $('#rmm-sku').val('');
        $('#rmm-sku-search').val('').prop('disabled', false);
        $('#rmm-sku-selected').attr('hidden', true).empty();
        setConflict(null);
        setError(null);
        setSuccess(null);
        updateSaveState();
        loadPreview();
    }

    function selectSku(item) {
        state.sku = item.sku || item.canonical_sku || '';
        state.skuNombre = item.nombre || item.name || '';
        $('#rmm-sku').val(state.sku);
        $('#rmm-sku-search').val(state.sku);
        $('#rmm-sku-selected')
            .removeAttr('hidden')
            .html('<strong>' + escapeHtml(state.sku) + '</strong>' +
                (state.skuNombre ? ' — ' + escapeHtml(state.skuNombre) : ''));
        setConflict(null);
        setError(null);
        updateSaveState();
    }

    function hidePreview() {
        $('#rmm-preview-body').attr('hidden', true);
        $('#rmm-preview-empty').removeAttr('hidden');
    }

    function loadPreview() {
        if (!state.proveedorId || !state.codigo) {
            hidePreview();
            return;
        }
        $('#rmm-preview-empty').text('Cargando historial…').removeAttr('hidden');
        $('#rmm-preview-body').attr('hidden', true);

        post('riverso_manual_map_preview', {
            proveedor_id: state.proveedorId,
            codigo_proveedor: state.codigo,
            limit_per_pair: 10,
            doc_type: 'factura',
        }).done(function (res) {
            if (!res || !res.success) {
                $('#rmm-preview-empty').text((res && res.data && res.data.message) || 'Error al cargar preview');
                return;
            }
            renderPreview(res.data);
        }).fail(function () {
            $('#rmm-preview-empty').text('Error de red al cargar historial');
        });
    }

    function renderPreview(data) {
        state.currentSku = data.current_sku || null;
        state.descripcion = data.descripcion || state.descripcion;
        if (data.proveedor && data.proveedor.nombre) {
            state.proveedorNombre = data.proveedor.nombre;
            $('#rmm-proveedor-search').val(state.proveedorNombre);
            $('#rmm-proveedor-selected')
                .removeAttr('hidden')
                .html('<strong>' + escapeHtml(state.proveedorNombre) + '</strong>' +
                    (data.proveedor.rut ? ' · ' + escapeHtml(data.proveedor.rut) : ''));
        }
        updateSaveState();

        // Prefill SKU if already mapped
        if (data.current_sku && !state.sku) {
            state.sku = data.current_sku;
            state.skuNombre = (data.product && data.product.nombre) || '';
            $('#rmm-sku').val(state.sku);
            $('#rmm-sku-search').val(state.sku);
            $('#rmm-sku-selected')
                .removeAttr('hidden')
                .html('<strong>' + escapeHtml(state.sku) + '</strong>' +
                    (state.skuNombre ? ' — ' + escapeHtml(state.skuNombre) : '') +
                    ' <span class="rmm-badge ok">mapeo actual</span>');
            updateSaveState();
        }

        var stats = data.stats || {};
        var metaHtml =
            '<span>Código: <code>' + escapeHtml(data.codigo_proveedor) + '</code></span>' +
            '<span>Proveedor: ' + escapeHtml((data.proveedor && data.proveedor.nombre) || state.proveedorNombre) + '</span>';
        if (data.descripcion) {
            metaHtml += '<span>' + escapeHtml(data.descripcion) + '</span>';
        }
        if (data.current_sku) {
            metaHtml += '<span class="rmm-badge ok">SKU: ' + escapeHtml(data.current_sku) + '</span>';
        } else {
            metaHtml += '<span class="rmm-badge warn">Sin SKU</span>';
        }
        if (stats.items_sin_sku) {
            metaHtml += '<span class="rmm-badge warn">' + stats.items_sin_sku + ' ítems sin SKU</span>';
        }
        $('#rmm-meta').html(metaHtml);

        var summary = {};
        if (data.summary) {
            if (Array.isArray(data.summary.by_pair) && data.summary.by_pair.length) {
                summary = data.summary.by_pair[0];
            } else if (data.summary.highlight) {
                summary = data.summary.highlight;
            } else if (Array.isArray(data.summary) && data.summary.length) {
                summary = data.summary[0];
            }
        }

        var sumHtml = '';
        sumHtml += '<div class="rmm-stat"><div class="rmm-stat-label">Facturas</div><div class="rmm-stat-value">' +
            (stats.facturas || 0) + '</div></div>';
        sumHtml += '<div class="rmm-stat"><div class="rmm-stat-label">Último costo</div><div class="rmm-stat-value">' +
            formatMoney(summary.ultimo_costo != null ? summary.ultimo_costo : summary.last_cost) + '</div></div>';
        sumHtml += '<div class="rmm-stat"><div class="rmm-stat-label">Desde</div><div class="rmm-stat-value">' +
            escapeHtml(stats.primera_fecha || '—') + '</div></div>';
        sumHtml += '<div class="rmm-stat"><div class="rmm-stat-label">Hasta</div><div class="rmm-stat-value">' +
            escapeHtml(stats.ultima_fecha || '—') + '</div></div>';
        $('#rmm-summary').html(sumHtml);

        var timeline = data.timeline || [];
        var docs = [];
        timeline.forEach(function (block) {
            (block.documents || []).forEach(function (d) {
                docs.push(d);
            });
        });

        var tHtml = '';
        if (!docs.length) {
            tHtml = '<p class="rmm-muted">Sin documentos de costo para este código.</p>';
        } else {
            tHtml = '<table class="rmm-timeline-table"><thead><tr>' +
                '<th>Fecha</th><th>Folio</th><th>Costo u.</th><th>SKU</th><th>Descripción</th>' +
                '</tr></thead><tbody>';
            docs.forEach(function (d) {
                tHtml += '<tr>' +
                    '<td>' + escapeHtml(d.fecha_emision || d.fecha || '—') + '</td>' +
                    '<td>' + escapeHtml(d.folio || '—') + '</td>' +
                    '<td>' + formatMoney(d.costo_unitario != null ? d.costo_unitario : d.unit_cost) + '</td>' +
                    '<td>' + escapeHtml(d.sku_local || '—') + '</td>' +
                    '<td>' + escapeHtml(d.nombre || d.descripcion || '') + '</td>' +
                    '</tr>';
            });
            tHtml += '</tbody></table>';
        }
        $('#rmm-timeline').html(tHtml);

        $('#rmm-preview-empty').attr('hidden', true);
        $('#rmm-preview-body').removeAttr('hidden');

        // Deep-link to cost history with this pair
        var costUrl = $('#riverso-manual-mapping').data('cost-url') || cfg.cost_history_url || '';
        if (costUrl) {
            var sep = costUrl.indexOf('?') >= 0 ? '&' : '?';
            $('#rmm-link-costs').attr(
                'href',
                costUrl + sep + 'proveedor_id=' + encodeURIComponent(state.proveedorId) +
                '&codigo=' + encodeURIComponent(state.codigo)
            );
        }
    }

    function loadUnmapped() {
        var $list = $('#rmm-unmapped-list');
        $list.html('<p class="rmm-muted">Cargando…</p>');
        post('riverso_manual_map_unmapped', {
            proveedor_id: state.proveedorId || 0,
            limit: 25,
        }).done(function (res) {
            if (!res || !res.success) {
                $list.html('<p class="rmm-muted">No se pudieron cargar pares sin SKU.</p>');
                return;
            }
            var pairs = (res.data && res.data.pairs) || [];
            if (!pairs.length) {
                $list.html('<p class="rmm-muted">No hay códigos sin SKU recientes.</p>');
                return;
            }
            var html = '';
            pairs.forEach(function (p) {
                html +=
                    '<div class="rmm-unmapped-item">' +
                    '<div>' +
                    '<strong>' + escapeHtml(p.codigo_proveedor) + '</strong> · ' +
                    escapeHtml(p.proveedor_nombre || ('#' + p.proveedor_id)) +
                    (p.descripcion ? '<div class="rmm-unmapped-meta">' + escapeHtml(p.descripcion) + '</div>' : '') +
                    '<div class="rmm-unmapped-meta">' +
                    escapeHtml(p.ultima_fecha || '') + ' · ' + (p.facturas || 0) + ' factura(s)' +
                    '</div>' +
                    '</div>' +
                    '<button type="button" class="button button-small rmm-pick-unmapped"' +
                    ' data-proveedor="' + escapeHtml(p.proveedor_id) + '"' +
                    ' data-nombre="' + escapeHtml(p.proveedor_nombre || '') + '"' +
                    ' data-codigo="' + escapeHtml(p.codigo_proveedor) + '"' +
                    ' data-desc="' + escapeHtml(p.descripcion || '') + '">Usar</button>' +
                    '</div>';
            });
            $list.html(html);
        }).fail(function () {
            $list.html('<p class="rmm-muted">Error de red</p>');
        });
    }

    function doAssign(opts) {
        opts = opts || {};
        // Si el usuario escribió el código sin elegir del picker
        if (!state.codigo) {
            var typed = $.trim($('#rmm-codigo-search').val() || '');
            if (typed && state.proveedorId) {
                state.codigo = typed;
                $('#rmm-codigo').val(typed);
            }
        }
        if (!state.sku && !opts.clear) {
            var typedSku = $.trim($('#rmm-sku-search').val() || '');
            if (typedSku) {
                state.sku = typedSku;
                $('#rmm-sku').val(typedSku);
            }
        }
        if (!state.proveedorId || !state.codigo) {
            setError('Seleccioná proveedor y código');
            return;
        }
        if (!opts.clear && !state.sku) {
            setError('Seleccioná un SKU local');
            return;
        }

        $('#rmm-btn-save').prop('disabled', true).text('Guardando…');
        setError(null);
        setSuccess(null);

        post('riverso_manual_map_assign', {
            proveedor_id: state.proveedorId,
            codigo_proveedor: state.codigo,
            sku_local: opts.clear ? '' : state.sku,
            clear: opts.clear ? 1 : 0,
            force: state.force || opts.force ? 1 : 0,
            descripcion: state.descripcion || '',
            audit_reason: $('#rmm-audit').val() || '',
        }).done(function (res) {
            $('#rmm-btn-save').text('Guardar mapeo');
            if (!res || !res.success) {
                var data = (res && res.data) || {};
                if (data.conflict) {
                    setConflict(data);
                    updateSaveState();
                    return;
                }
                setError(data.message || 'Error al guardar');
                updateSaveState();
                return;
            }
            state.force = false;
            setConflict(null);
            setSuccess((res.data && res.data.message) || 'Mapeo guardado');
            if (opts.clear) {
                state.currentSku = null;
                state.sku = '';
                $('#rmm-sku').val('');
                $('#rmm-sku-search').val('');
                $('#rmm-sku-selected').attr('hidden', true).empty();
            } else {
                state.currentSku = state.sku;
            }
            updateSaveState();
            loadPreview();
            loadUnmapped();
        }).fail(function () {
            $('#rmm-btn-save').text('Guardar mapeo');
            setError('Error de red al guardar');
            updateSaveState();
        });
    }

    function clearForm() {
        state = {
            proveedorId: 0,
            proveedorNombre: '',
            codigo: '',
            descripcion: '',
            sku: '',
            skuNombre: '',
            currentSku: null,
            force: false,
            timers: state.timers || {},
        };
        $('#rmm-proveedor-id').val('');
        $('#rmm-proveedor-search').val('');
        $('#rmm-proveedor-selected').attr('hidden', true).empty();
        $('#rmm-codigo').val('');
        $('#rmm-codigo-search').val('').prop('disabled', true);
        $('#rmm-codigo-selected').attr('hidden', true).empty();
        $('#rmm-sku').val('');
        $('#rmm-sku-search').val('').prop('disabled', true);
        $('#rmm-sku-selected').attr('hidden', true).empty();
        $('#rmm-audit').val('');
        hidePickers();
        hidePreview();
        setConflict(null);
        setError(null);
        setSuccess(null);
        updateSaveState();
        loadUnmapped();
    }

    function preloadFromQuery() {
        var $root = $('#riverso-manual-mapping');
        var pid = parseInt($root.data('pre-proveedor'), 10) || 0;
        var codigo = String($root.data('pre-codigo') || '');
        if (!pid) {
            return;
        }
        selectProveedor({
            id: pid,
            nombre: 'Proveedor #' + pid,
            label: 'Proveedor #' + pid,
        });
        if (codigo) {
            selectCodigo({
                codigo_proveedor: codigo,
                descripcion: '',
            });
        }
        // Refinar nombre del proveedor desde el preview / búsqueda por id en lista
        post('riverso_search_suppliers', { search: String(pid), limit: 20 })
            .done(function (res) {
                var list = (res && res.success && res.data.suppliers) || [];
                var found = null;
                (list || []).forEach(function (s) {
                    if (parseInt(s.id, 10) === pid) {
                        found = s;
                    }
                });
                if (found) {
                    state.proveedorNombre = found.nombre || state.proveedorNombre;
                    $('#rmm-proveedor-search').val(state.proveedorNombre);
                    $('#rmm-proveedor-selected')
                        .removeAttr('hidden')
                        .html('<strong>' + escapeHtml(state.proveedorNombre) + '</strong>' +
                            (found.rut ? ' · ' + escapeHtml(found.rut) : ''));
                }
            });
    }

    function init() {
        if (!$('#riverso-manual-mapping').length) {
            return;
        }

        bindPicker($('#rmm-proveedor-search'), $('#rmm-proveedor-results'), function (term) {
            var d = $.Deferred();
            post('riverso_search_suppliers', { search: term, term: term, q: term })
                .done(function (res) {
                    var list = [];
                    if (res && res.success) {
                        list = res.data.suppliers || res.data.results || res.data || [];
                    }
                    if (!Array.isArray(list)) {
                        list = [];
                    }
                    d.resolve(list.map(function (s) {
                        return {
                            id: parseInt(s.id, 10),
                            nombre: s.nombre || s.name,
                            rut: s.rut || '',
                            label: (s.nombre || s.name || '') + (s.rut ? ' (' + s.rut + ')' : ''),
                            meta: s.rut || '',
                        };
                    }));
                })
                .fail(function () { d.resolve([]); });
            return d.promise();
        }, selectProveedor);

        bindPicker($('#rmm-codigo-search'), $('#rmm-codigo-results'), function (term) {
            var d = $.Deferred();
            if (!state.proveedorId) {
                d.resolve([]);
                return d.promise();
            }
            post('riverso_manual_map_search_codes', {
                proveedor_id: state.proveedorId,
                term: term,
            }).done(function (res) {
                var codes = (res && res.success && res.data.codes) || [];
                d.resolve(codes.map(function (c) {
                    var meta = [];
                    if (c.descripcion) {
                        meta.push(c.descripcion);
                    }
                    if (c.sku_local) {
                        meta.push('SKU: ' + c.sku_local);
                    }
                    if (c.docs_count) {
                        meta.push(c.docs_count + ' docs');
                    }
                    return {
                        codigo_proveedor: c.codigo_proveedor,
                        descripcion: c.descripcion,
                        sku_local: c.sku_local,
                        label: c.codigo_proveedor,
                        meta: meta.join(' · '),
                    };
                }));
            }).fail(function () { d.resolve([]); });
            return d.promise();
        }, selectCodigo);

        // Allow free-typing a code then Enter / blur
        $('#rmm-codigo-search').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var code = $.trim($(this).val());
                if (code && state.proveedorId) {
                    selectCodigo({ codigo_proveedor: code });
                    hidePickers();
                }
            }
        });

        bindPicker($('#rmm-sku-search'), $('#rmm-sku-results'), function (term) {
            var d = $.Deferred();
            post('riverso_search_sku_catalog', { search: term, term: term, q: term })
                .done(function (res) {
                    var list = [];
                    if (res && res.success) {
                        list = res.data.products || res.data.results || res.data || [];
                    }
                    if (!Array.isArray(list)) {
                        list = [];
                    }
                    d.resolve(list.map(function (p) {
                        var sku = p.sku || p.canonical_sku || p.sku_local || '';
                        var nombre = p.nombre || p.name || p.nombre_canonico || '';
                        return {
                            sku: sku,
                            nombre: nombre,
                            label: sku + (nombre ? ' — ' + nombre : ''),
                            meta: nombre,
                        };
                    }));
                })
                .fail(function () { d.resolve([]); });
            return d.promise();
        }, selectSku);

        $('#rmm-btn-save').on('click', function () {
            doAssign();
        });
        $('#rmm-btn-unlink').on('click', function () {
            if (!window.confirm('¿Desvincular el SKU de este código proveedor?')) {
                return;
            }
            doAssign({ clear: true });
        });
        $('#rmm-btn-clear').on('click', clearForm);
        $('#rmm-btn-refresh-unmapped').on('click', loadUnmapped);

        $(document).on('click', '#rmm-btn-force', function () {
            state.force = true;
            doAssign({ force: true });
        });

        $(document).on('click', '.rmm-pick-unmapped', function () {
            var $btn = $(this);
            selectProveedor({
                id: parseInt($btn.data('proveedor'), 10),
                nombre: $btn.data('nombre') || ('Proveedor #' + $btn.data('proveedor')),
                label: $btn.data('nombre') || '',
            });
            selectCodigo({
                codigo_proveedor: String($btn.data('codigo') || ''),
                descripcion: String($btn.data('desc') || ''),
            });
            $('html, body').animate({ scrollTop: $('#riverso-manual-mapping').offset().top - 40 }, 200);
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.rmm-field').length) {
                hidePickers();
            }
        });

        updateSaveState();
        loadUnmapped();
        preloadFromQuery();
    }

    $(init);
})(jQuery);
