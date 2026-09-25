/**
 * Búsqueda rápida — Hub de Productos
 */
(function ($) {
    'use strict';

    var cfg = window.riversoProductQuickSearch || {};
    var ajaxurl = cfg.ajax_url || window.ajaxurl || '';
    var nonce = cfg.nonce || '';
    var canManage = !!cfg.can_manage;

    var state = {
        productId: 0,
        summary: null,
        viewMode: localStorage.getItem('pqs_view_mode') || 'neto',
        costMode: localStorage.getItem('pqs_cost_mode') || 'referencia',
    };

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function fmtMoney(n) {
        if (n === null || n === undefined || n === '' || isNaN(Number(n))) {
            return '—';
        }
        return Number(n).toLocaleString('es-CL', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = nonce;
        return $.post(ajaxurl, data);
    }

    function setStatus($el, msg, kind) {
        if (!msg) {
            $el.hide().text('');
            return;
        }
        $el.removeClass('is-error is-empty')
            .addClass(kind === 'error' ? 'is-error' : kind === 'empty' ? 'is-empty' : '')
            .text(msg)
            .show();
    }

    function renderGridRows(items, tbodySelector) {
        var $tb = $(tbodySelector);
        if (!items || !items.length) {
            $tb.html('<tr><td colspan="7">Sin resultados.</td></tr>');
            return;
        }
        $tb.html(items.map(function (it) {
            return (
                '<tr class="pqs-row" data-id="' + esc(it.id) + '">' +
                '<td><code>' + esc(it.canonical_sku) + '</code></td>' +
                '<td>' + esc(it.nombre) + '</td>' +
                '<td>' + esc(it.proveedor || '—') + '</td>' +
                '<td><code>' + esc(it.codigo_proveedor || '—') + '</code></td>' +
                '<td><code>' + esc(it.barcode || '—') + '</code></td>' +
                '<td>' + fmtMoney(it.precio) + '</td>' +
                '<td>' + (it.stock != null ? esc(it.stock) : '—') + '</td>' +
                '</tr>'
            );
        }).join(''));
    }

    function renderLupaRows(items) {
        var $tb = $('#pqs-lupa-tbody');
        if (!items || !items.length) {
            $tb.html('<tr><td colspan="6">Sin resultados.</td></tr>');
            return;
        }
        $tb.html(items.map(function (it) {
            return (
                '<tr class="pqs-lupa-row" data-id="' + esc(it.id) + '">' +
                '<td><code>' + esc(it.canonical_sku) + '</code></td>' +
                '<td>' + esc(it.nombre) + '</td>' +
                '<td>' + esc(it.proveedor || '—') + '</td>' +
                '<td><code>' + esc(it.codigo_proveedor || '—') + '</code></td>' +
                '<td><code>' + esc(it.barcode || '—') + '</code></td>' +
                '<td>' + (it.stock != null ? esc(it.stock) : '—') + '</td>' +
                '</tr>'
            );
        }).join(''));
    }

    function originTitle(origin, meta) {
        if (!origin || !origin.key) {
            return 'Sin origen registrado';
        }
        var parts = [origin.label || origin.key];
        if (origin.fecha) {
            parts.push('Fecha: ' + origin.fecha);
        }
        if (origin.folio) {
            parts.push('Folio: ' + origin.folio);
        }
        if (meta && meta.proveedor_nombre) {
            parts.push('Proveedor: ' + meta.proveedor_nombre);
        }
        return parts.join(' · ');
    }

    function pickCost(pricing, costMode, viewMode) {
        if (!pricing) {
            return null;
        }
        var bases = pricing.c_ref_bases || null;
        var base = bases && bases[costMode] ? bases[costMode] : null;
        if (base) {
            return viewMode === 'neto' ? base.neto : base.bruto;
        }
        // Fallback a c_ref
        return viewMode === 'neto'
            ? (pricing.c_ref_neto != null ? pricing.c_ref_neto : pricing.c_ref)
            : (pricing.c_ref_bruto != null ? pricing.c_ref_bruto : pricing.c_ref);
    }

    function pickPrice(pricing, viewMode) {
        if (!pricing) {
            return null;
        }
        return viewMode === 'neto'
            ? (pricing.p_neto != null ? pricing.p_neto : pricing.p_asignado)
            : pricing.p_asignado;
    }

    function calcMargin(price, cost) {
        if (price == null || cost == null || !(Number(cost) > 0)) {
            return { factor: null, unitario: null, pct: null };
        }
        var p = Number(price);
        var c = Number(cost);
        var factor = Math.round((p / c) * 10000) / 10000;
        return {
            factor: factor,
            unitario: Math.round((p - c) * 10000) / 10000,
            pct: Math.round((factor - 1) * 1000) / 10,
        };
    }

    function updatePricingUi() {
        var pricing = state.summary && state.summary.pricing;
        $('.pqs-view-mode').removeClass('is-active');
        $('.pqs-view-mode[data-mode="' + state.viewMode + '"]').addClass('is-active');
        $('.pqs-cost-mode').removeClass('is-active');
        $('.pqs-cost-mode[data-cost="' + state.costMode + '"]').addClass('is-active');

        var price = pickPrice(pricing, state.viewMode);
        var cost = pickCost(pricing, state.costMode, state.viewMode);
        var m = calcMargin(price, cost);

        $('#pqs-v-precio').text(fmtMoney(price));
        $('#pqs-v-costo').text(fmtMoney(cost));
        if (m.factor != null) {
            $('#pqs-v-margen').html(
                esc(m.factor.toFixed(2)) + '× · ' +
                fmtMoney(m.unitario) + ' · ' +
                esc(String(m.pct)) + '%'
            );
        } else {
            $('#pqs-v-margen').text('—');
        }

        $('#pqs-help-precio').attr('title', originTitle(pricing && pricing.origen_precio));
        var costOrigin = originTitle(
            pricing && pricing.origen_costo,
            pricing && pricing.costo_bases_meta
        );
        if (pricing && pricing.costo_bases_meta && pricing.costo_bases_meta.folio) {
            costOrigin += ' · Base: ' + state.costMode;
        }
        $('#pqs-help-costo').attr('title', costOrigin);
    }

    function stockHelpText(stock) {
        if (!stock) {
            return 'Sin datos de inventario';
        }
        var lines = [];
        var estado = stock.estado_inventariado || 'desconocido';
        var conf = stock.estado_confianza || '—';
        if (estado === 'exacto') {
            lines.push('Exacto: hay conteo cerrado por producto.');
        } else if (estado === 'al_menos') {
            lines.push('Al menos: stock inferido desde conteos por lugar.');
        } else {
            lines.push('Desconocido: sin conteos cerrados registrados.');
        }
        lines.push('Confianza: ' + conf);
        if (stock.ultimo_conteo_fecha) {
            lines.push('Último conteo: ' + stock.ultimo_conteo_fecha);
        }
        return lines.join(' · ');
    }

    function renderLocations(list, $el, kind) {
        if (!list || !list.length) {
            $el.html('<span style="color:#666;">—</span>');
            return;
        }
        $el.html(list.map(function (loc) {
            var label = (loc.codigo || '') + (loc.nombre ? ' — ' + loc.nombre : '');
            var extra = '';
            if (kind === 'pref' && (loc.es_preferido == 1 || loc.es_preferido === '1')) {
                extra = ' <span class="pqs-badge is-exacto">preferido</span>';
            }
            if (kind === 'act') {
                var qty = loc.cantidad != null ? loc.cantidad : loc.cantidad_contada;
                extra = ' <strong>(' + esc(qty) + ')</strong>';
                if (loc.es_principal == 1 || loc.es_principal === '1') {
                    extra += ' <span class="pqs-badge">principal</span>';
                }
            }
            return '<div class="pqs-supplier-row">' + esc(label) + extra + '</div>';
        }).join(''));
    }

    function renderRelation($el, data, type) {
        if (!data) {
            $el.addClass('empty').html(type === 'family'
                ? 'No pertenece a una familia exacta.'
                : 'No está emparejado.');
            return;
        }
        $el.removeClass('empty');
        var label = type === 'family' ? 'Es familia' : 'Emparejado';
        var name = data.nombre || data.codigo || ('#' + (data.grupo_id || data.id));
        var count = data.miembros_count != null ? data.miembros_count : '?';
        var idAttr = type === 'family' ? data.grupo_id : data.id;
        $el.html(
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<div><span class="pqs-badge is-exacto">' + esc(label) + '</span> ' +
            '<strong>' + esc(name) + '</strong> · ' + esc(count) + ' miembros</div>' +
            '<button type="button" class="button button-small pqs-btn-members" data-type="' + esc(type) +
            '" data-id="' + esc(idAttr) + '">Vista rápida</button></div>'
        );
    }

    function renderTasks(tasks) {
        var $box = $('#pqs-v-tasks');
        var list = tasks || [];
        $('#pqs-tasks-count').text(list.length ? String(list.length) : '').toggle(!!list.length);
        if (!list.length) {
            $box.html('<p style="color:#666;margin:0;">Sin tareas pendientes.</p>');
            return;
        }
        $box.html(list.map(function (t) {
            var tipo = t.tipo || '';
            var html =
                '<div class="pqs-task" data-task-id="' + esc(t.id) + '" data-tipo="' + esc(tipo) + '">' +
                '<div class="pqs-task-title">' + esc(t.titulo || tipo) + '</div>' +
                '<div class="pqs-task-meta">' + esc(tipo) +
                (t.prioridad ? ' · prioridad ' + esc(t.prioridad) : '') + '</div>' +
                '<div class="pqs-task-actions">';

            if (canManage) {
                if (tipo === 'barcode_faltante' || tipo === 'confirmar_barcode_legacy') {
                    html +=
                        '<div class="pqs-task-form">' +
                        '<input type="text" class="pqs-task-barcode" placeholder="Código de barras">' +
                        '<button type="button" class="button button-small button-primary pqs-task-do-barcode">Asignar</button>' +
                        '</div>';
                } else if (tipo === 'codigo_faltante' || tipo === 'confirmar_codigo_proveedor' || tipo === 'relacionar_producto_proveedor') {
                    html +=
                        '<div class="pqs-task-form">' +
                        '<input type="text" class="pqs-task-sup-search" placeholder="Proveedor…">' +
                        '<input type="hidden" class="pqs-task-sup-id" value="">' +
                        '<input type="text" class="pqs-task-sup-code" placeholder="Código proveedor">' +
                        '<button type="button" class="button button-small button-primary pqs-task-do-supplier">Asignar</button>' +
                        '<div class="pqs-sup-suggestions" style="display:none;width:100%;"></div>' +
                        '</div>';
                } else if (tipo === 'preguntar_familia') {
                    html +=
                        '<button type="button" class="button button-small pqs-task-family-need" data-answer="requiere">Necesita familia</button>' +
                        '<button type="button" class="button button-small pqs-task-family-need" data-answer="no_requiere">No requiere</button>';
                } else if (tipo === 'asignar_familia') {
                    html += '<button type="button" class="button button-small pqs-task-open-family">Abrir editor familia</button>';
                }

                if (t.target_url) {
                    html += '<a class="button button-small" href="' + esc(t.target_url) + '">Ir</a>';
                }
                html += '<button type="button" class="button button-small pqs-task-complete">Completar</button>';
            } else if (t.target_url) {
                html += '<a class="button button-small" href="' + esc(t.target_url) + '">Ir</a>';
            }

            html += '</div></div>';
            return html;
        }).join(''));
    }

    function renderSummary(data) {
        state.summary = data;
        state.productId = data.product && data.product.id ? data.product.id : 0;

        $('#pqs-results').hide();
        $('#pqs-viewer').show();

        $('#pqs-v-sku').text(data.product.canonical_sku || '');
        $('#pqs-v-nombre').text(data.product.nombre || '');

        var alerts = data.alerts || [];
        var $alerts = $('#pqs-alerts');
        if (!alerts.length) {
            $alerts.html('<div class="pqs-alert" style="border-left-color:#00a32a;background:#edfaef;color:#1e4620;">Sin alertas activas.</div>');
        } else {
            $alerts.html(alerts.map(function (a) {
                return '<div class="pqs-alert is-' + esc(a.level || 'warning') + '">' + esc(a.message) + '</div>';
            }).join(''));
        }

        var barcodes = (data.barcodes || []).filter(function (b) { return !b.inactivo; });
        if (!barcodes.length) {
            $('#pqs-v-barcodes').html('<span style="color:#666;">—</span>');
        } else {
            $('#pqs-v-barcodes').html(barcodes.map(function (b) {
                return '<span class="pqs-chip">' + esc(b.codigo) + '</span>';
            }).join(''));
        }

        var suppliers = data.suppliers || [];
        if (!suppliers.length) {
            $('#pqs-v-suppliers').html('<span style="color:#666;">—</span>');
        } else {
            $('#pqs-v-suppliers').html(suppliers.map(function (s) {
                return '<div class="pqs-supplier-row">' +
                    esc(s.display_name || s.proveedor_nombre || '') +
                    ' · <code>' + esc(s.codigo_proveedor || '—') + '</code></div>';
            }).join(''));
        }

        updatePricingUi();

        var stock = data.stock || {};
        var estado = stock.estado_inventariado || 'desconocido';
        $('#pqs-v-stock').html(
            '<strong>' + esc(stock.stock_total != null ? stock.stock_total : '—') + '</strong> ' +
            '<span class="pqs-badge is-' + esc(estado) + '">' + esc(estado.replace('_', ' ')) + '</span>'
        );
        $('#pqs-help-stock').attr('title', stockHelpText(stock));
        $('#pqs-v-stock-min').text(stock.stock_minimo != null ? stock.stock_minimo : '—');
        $('#pqs-v-stock-crit').text(stock.stock_critico != null ? stock.stock_critico : '—');

        renderLocations((data.locations && data.locations.preferidas) || [], $('#pqs-v-loc-pref'), 'pref');
        renderLocations((data.locations && data.locations.actuales) || [], $('#pqs-v-loc-act'), 'act');

        renderRelation($('#pqs-v-family'), data.family, 'family');
        renderRelation($('#pqs-v-emparejamiento'), data.emparejamiento, 'emp');

        renderTasks(data.tasks);
    }

    function loadSummary(id) {
        setStatus($('#pqs-status'), 'Cargando…');
        return post('riverso_products_quick_summary', { producto_base_id: id }).then(function (r) {
            if (!r || !r.success) {
                setStatus($('#pqs-status'), (r && r.data && r.data.message) || 'Error al cargar', 'error');
                return;
            }
            setStatus($('#pqs-status'), '');
            renderSummary(r.data);
        });
    }

    function doLookup() {
        var code = ($('#pqs-input').val() || '').trim();
        if (!code) {
            setStatus($('#pqs-status'), 'Ingresá un código', 'empty');
            return;
        }
        setStatus($('#pqs-status'), 'Buscando…');
        $('#pqs-viewer').hide();
        post('riverso_products_quick_lookup', { code: code }).then(function (r) {
            if (!r || !r.success) {
                setStatus($('#pqs-status'), (r && r.data && r.data.message) || 'Error', 'error');
                return;
            }
            var items = r.data.items || [];
            if (!items.length) {
                setStatus($('#pqs-status'), 'Sin coincidencias para «' + code + '»', 'empty');
                $('#pqs-results').hide();
                return;
            }
            if (items.length === 1) {
                setStatus($('#pqs-status'), '');
                loadSummary(items[0].id);
                return;
            }
            setStatus($('#pqs-status'), items.length + ' coincidencias — seleccioná una');
            renderGridRows(items, '#pqs-results-tbody');
            $('#pqs-results').show();
        });
    }

    function openLupa() {
        $('#pqs-lupa-modal').css('display', 'flex');
        $('#pqs-lupa-input').val($('#pqs-input').val() || '').focus();
    }

    function closeLupa() {
        $('#pqs-lupa-modal').hide();
    }

    function doLupaSearch() {
        var term = ($('#pqs-lupa-input').val() || '').trim();
        var field = $('#pqs-lupa-field').val() || 'todos';
        if (term.length < 2) {
            setStatus($('#pqs-lupa-status'), 'Escribí al menos 2 caracteres', 'empty');
            return;
        }
        setStatus($('#pqs-lupa-status'), 'Buscando…');
        post('riverso_products_quick_search', { term: term, field: field }).then(function (r) {
            if (!r || !r.success) {
                setStatus($('#pqs-lupa-status'), (r && r.data && r.data.message) || 'Error', 'error');
                return;
            }
            var items = r.data.items || [];
            setStatus($('#pqs-lupa-status'), items.length ? (items.length + ' resultados') : 'Sin resultados', items.length ? '' : 'empty');
            renderLupaRows(items);
        });
    }

    function showMembersPopover(type, id, anchor) {
        var $pop = $('#pqs-members-popover');
        $('#pqs-members-body').html('<p style="color:#666;">Cargando…</p>');
        var rect = anchor.getBoundingClientRect();
        $pop.css({
            display: 'flex',
            top: Math.min(window.innerHeight - 80, rect.bottom + 6) + 'px',
            left: Math.max(8, Math.min(rect.left, window.innerWidth - 440)) + 'px',
        });

        var action = type === 'family' ? 'riverso_families_get' : 'riverso_emparejamientos_get';
        var payload = type === 'family' ? { grupo_id: id } : { id: id };
        var catsUrl = (state.summary && state.summary.urls && state.summary.urls.categories_families) ||
            (cfg.categories_url || '');

        if (type === 'family') {
            $('#pqs-members-title').text('Miembros de familia');
            $('#pqs-members-link').attr('href', catsUrl + '&tab=families&grupo_id=' + id);
        } else {
            $('#pqs-members-title').text('Miembros emparejados');
            $('#pqs-members-link').attr('href', catsUrl + '&tab=emparejamientos&emparejamiento_id=' + id);
        }

        post(action, payload).then(function (r) {
            if (!r || !r.success) {
                $('#pqs-members-body').html('<p style="color:#d63638;">No se pudo cargar.</p>');
                return;
            }
            var members = [];
            if (type === 'family') {
                var fam = r.data.family || r.data.item || r.data;
                members = fam.members || fam.miembros || r.data.members || [];
            } else {
                var emp = r.data.emparejamiento || r.data.item || r.data;
                members = emp.members || emp.miembros || r.data.members || [];
            }
            if (!members.length) {
                $('#pqs-members-body').html('<p style="color:#666;">Sin miembros.</p>');
                return;
            }
            $('#pqs-members-body').html(members.map(function (m) {
                var sku = m.canonical_sku || m.sku || '';
                var nombre = m.nombre_canonico || m.nombre || '';
                var precio = m.p_asignado != null ? fmtMoney(m.p_asignado) : '—';
                return '<div class="pqs-member-row">' +
                    '<div><code>' + esc(sku) + '</code><br>' + esc(nombre) + '</div>' +
                    '<div style="text-align:right;">' + precio + '</div></div>';
            }).join(''));
        });
    }

    function refreshViewer() {
        if (state.productId) {
            return loadSummary(state.productId);
        }
        return $.Deferred().resolve().promise();
    }

    function bindEvents() {
        $('#pqs-btn-search').on('click', doLookup);
        $('#pqs-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doLookup();
            }
        });

        $('#pqs-btn-lupa').on('click', openLupa);
        $('#pqs-lupa-close').on('click', closeLupa);
        $('#pqs-lupa-modal').on('click', function (e) {
            if (e.target === this) {
                closeLupa();
            }
        });
        $('#pqs-lupa-search').on('click', doLupaSearch);
        $('#pqs-lupa-input').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                doLupaSearch();
            }
        });

        $(document).on('click', '#pqs-results-tbody .pqs-row', function () {
            loadSummary($(this).data('id'));
        });
        $(document).on('click', '#pqs-lupa-tbody .pqs-lupa-row', function () {
            var id = $(this).data('id');
            closeLupa();
            loadSummary(id);
        });

        $('#pqs-btn-close-viewer').on('click', function () {
            $('#pqs-viewer').hide();
            state.productId = 0;
            state.summary = null;
        });

        $('#pqs-btn-edit').on('click', function () {
            if (!state.productId) {
                return;
            }
            if (window.RiversoProductsHub && typeof window.RiversoProductsHub.openDetail === 'function') {
                window.RiversoProductsHub.openDetail(state.productId, { tab: 'local', edit: true });
            } else {
                window.location.href = 'admin.php?page=riverso-pos-products&tab=busqueda&action=detail&id=' +
                    state.productId + '&edit=1';
            }
        });

        $(document).on('click', '.pqs-view-mode', function () {
            state.viewMode = $(this).data('mode') || 'neto';
            localStorage.setItem('pqs_view_mode', state.viewMode);
            updatePricingUi();
        });
        $(document).on('click', '.pqs-cost-mode', function () {
            state.costMode = $(this).data('cost') || 'referencia';
            localStorage.setItem('pqs_cost_mode', state.costMode);
            updatePricingUi();
        });

        $(document).on('click', '.pqs-btn-members', function (e) {
            showMembersPopover($(this).data('type'), $(this).data('id'), this);
            e.stopPropagation();
        });
        $('#pqs-members-close').on('click', function () {
            $('#pqs-members-popover').hide();
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#pqs-members-popover, .pqs-btn-members').length) {
                $('#pqs-members-popover').hide();
            }
        });

        // Tasks
        $(document).on('click', '.pqs-task-complete', function () {
            var $task = $(this).closest('.pqs-task');
            var tid = $task.data('task-id');
            post('riverso_products_complete_task', { tarea_id: tid }).then(function (r) {
                if (r && r.success) {
                    refreshViewer();
                } else {
                    alert((r && r.data && r.data.message) || 'No se pudo completar');
                }
            });
        });

        $(document).on('click', '.pqs-task-do-barcode', function () {
            var $task = $(this).closest('.pqs-task');
            var code = ($task.find('.pqs-task-barcode').val() || '').trim();
            if (!code || !state.productId) {
                return;
            }
            post('riverso_products_add_barcode', {
                product_id: state.productId,
                barcode: code,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'Error al agregar barcode');
                    return;
                }
                var tid = $task.data('task-id');
                return post('riverso_products_complete_task', { tarea_id: tid });
            }).then(function () {
                refreshViewer();
            });
        });

        $(document).on('input', '.pqs-task-sup-search', function () {
            var $input = $(this);
            var $form = $input.closest('.pqs-task-form');
            var q = ($input.val() || '').trim();
            clearTimeout($input.data('to'));
            if (q.length < 2) {
                $form.find('.pqs-sup-suggestions').hide();
                return;
            }
            $input.data('to', setTimeout(function () {
                post('riverso_search_suppliers', { search: q, limit: 10 }).then(function (r) {
                    var list = (r && r.success && (r.data.suppliers || r.data.items || [])) || [];
                    if (!Array.isArray(list)) {
                        list = [];
                    }
                    var $sug = $form.find('.pqs-sup-suggestions');
                    if (!list.length) {
                        $sug.hide();
                        return;
                    }
                    $sug.html(list.slice(0, 8).map(function (s) {
                        var id = s.id || s.proveedor_id;
                        var name = s.nombre || s.name || '';
                        return '<button type="button" class="button button-small pqs-pick-sup" data-id="' +
                            esc(id) + '" data-name="' + esc(name) + '" style="margin:2px;">' +
                            esc(name) + '</button>';
                    }).join('')).show();
                });
            }, 300));
        });

        $(document).on('click', '.pqs-pick-sup', function () {
            var $form = $(this).closest('.pqs-task-form');
            $form.find('.pqs-task-sup-id').val($(this).data('id'));
            $form.find('.pqs-task-sup-search').val($(this).data('name'));
            $form.find('.pqs-sup-suggestions').hide();
        });

        $(document).on('click', '.pqs-task-do-supplier', function () {
            var $task = $(this).closest('.pqs-task');
            var $form = $task.find('.pqs-task-form');
            var sid = $form.find('.pqs-task-sup-id').val();
            var code = ($form.find('.pqs-task-sup-code').val() || '').trim();
            if (!sid || !code || !state.productId) {
                alert('Seleccioná proveedor e ingresá código');
                return;
            }
            post('riverso_products_assign_supplier_code', {
                producto_id: state.productId,
                supplier_id: sid,
                supplier_code: code,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'Error al asignar código');
                    return;
                }
                return post('riverso_products_complete_task', {
                    tarea_id: $task.data('task-id'),
                });
            }).then(function () {
                refreshViewer();
            });
        });

        $(document).on('click', '.pqs-task-family-need', function () {
            var answer = $(this).data('answer');
            var tid = $(this).closest('.pqs-task').data('task-id');
            post('riverso_products_answer_family_need', {
                product_id: state.productId,
                needs_family: answer === 'requiere' ? 1 : 0,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'Error');
                    return;
                }
                if (tid) {
                    return post('riverso_products_complete_task', { tarea_id: tid });
                }
            }).then(function () {
                refreshViewer();
            });
        });

        $(document).on('click', '.pqs-task-open-family', function () {
            if (window.RiversoFamilyEditor && typeof window.RiversoFamilyEditor.openCreate === 'function') {
                window.RiversoFamilyEditor.openCreate(null, { producto_base_id: state.productId });
            } else if (state.summary && state.summary.urls) {
                window.location.href = state.summary.urls.categories_families + '&tab=families';
            }
        });
    }

    var api = {
        init: function () {
            if (!$('#pqs-input').length) {
                return;
            }
            bindEvents();
            updatePricingUi();
        },
        loadSummary: loadSummary,
        lookup: doLookup,
    };

    window.RiversoProductQuickSearch = api;

    $(function () {
        api.init();
    });
})(jQuery);
