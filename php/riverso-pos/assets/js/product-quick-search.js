/**
 * Búsqueda rápida — Hub de Productos
 */
(function ($) {
    'use strict';

    var cfg = window.riversoProductQuickSearch || {};
    var ajaxurl = cfg.ajax_url || window.ajaxurl || '';
    var nonce = cfg.nonce || '';
    var canManage = !!cfg.can_manage;
    var canManagePrices = !!cfg.can_manage_prices;
    var canManageFamilies = !!cfg.can_manage_families;
    var canEditStock = !!cfg.can_edit_stock;
    var canEditLocations = !!cfg.can_edit_locations;
    var ivaFactor = Number(cfg.iva_factor) > 0 ? Number(cfg.iva_factor) : 1.19;

    var state = {
        productId: 0,
        summary: null,
        editing: false,
        lastQuery: '',
        related: [],
        viewMode: localStorage.getItem('pqs_view_mode') || 'neto',
        costMode: localStorage.getItem('pqs_cost_mode') || 'referencia',
    };

    var confirmDeferred = null;

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

    /**
     * Modal de confirmación. opts: {title, bodyHtml, okLabel, danger, requireCheck}
     * @returns {jQuery.Promise}
     */
    function pqsConfirm(opts) {
        opts = opts || {};
        if (confirmDeferred) {
            confirmDeferred.reject('superseded');
        }
        var d = $.Deferred();
        confirmDeferred = d;

        $('#pqs-confirm-title').text(opts.title || '¿Estás seguro?');
        $('#pqs-confirm-body').html(opts.bodyHtml || '');
        $('#pqs-confirm-ok').text(opts.okLabel || 'Confirmar');
        $('#pqs-confirm-modal').toggleClass('is-danger', !!opts.danger);
        $('#pqs-confirm-dialog, #pqs-confirm-modal .pqs-confirm-dialog').toggleClass('is-danger', !!opts.danger);

        var $checkWrap = $('#pqs-confirm-check-wrap');
        var $check = $('#pqs-confirm-check');
        if (opts.requireCheck) {
            $checkWrap.show();
            $check.prop('checked', false);
            $('#pqs-confirm-ok').prop('disabled', true);
        } else {
            $checkWrap.hide();
            $('#pqs-confirm-ok').prop('disabled', false);
        }

        $('#pqs-confirm-modal').css('display', 'flex');
        return d.promise();
    }

    function closeConfirm(ok) {
        $('#pqs-confirm-modal').hide();
        var d = confirmDeferred;
        confirmDeferred = null;
        if (!d) {
            return;
        }
        if (ok) {
            d.resolve(true);
        } else {
            d.reject('cancel');
        }
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

    function setFolioLink($el, origin) {
        if (!$el || !$el.length) {
            return;
        }
        var url = origin && origin.folio_url ? String(origin.folio_url) : '';
        var folio = origin && origin.folio ? String(origin.folio) : '';
        if (!url) {
            $el.attr('hidden', true).attr('href', '#').text('');
            return;
        }
        $el.removeAttr('hidden')
            .attr('href', url)
            .attr('title', 'Abrir revisión de folio')
            .text(folio ? ('Ver folio #' + folio) : 'Ver folio');
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

    function ivaTipo() {
        return (state.summary && state.summary.product && state.summary.product.facto_iva_tipo) || 'afecto';
    }

    function toBruto(value) {
        var n = Number(value);
        if (!(n > 0)) {
            return 0;
        }
        if (state.viewMode !== 'neto' || ivaTipo() === 'exento') {
            return Math.round(n * 10000) / 10000;
        }
        return Math.round(n * ivaFactor * 10000) / 10000;
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
        if (state.editing) {
            $('#pqs-edit-precio').val(price != null ? price : '');
            $('#pqs-edit-precio-mode').text(state.viewMode === 'bruto' ? 'bruto' : 'neto');
        }
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
        setFolioLink($('#pqs-folio-precio'), pricing && pricing.origen_precio);
        var costOrigin = originTitle(
            pricing && pricing.origen_costo,
            pricing && pricing.costo_bases_meta
        );
        if (pricing && pricing.costo_bases_meta && pricing.costo_bases_meta.folio) {
            costOrigin += ' · Base: ' + state.costMode;
        }
        $('#pqs-help-costo').attr('title', costOrigin);
        setFolioLink($('#pqs-folio-costo'), pricing && pricing.origen_costo);
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

    function showWizard() {
        var ctx = state.summary && state.summary.barcode_remap_context;
        return !!(ctx && ctx.show_wizard);
    }

    function renderLocations(list, $el, kind) {
        if (!list || !list.length) {
            $el.html('<span style="color:#666;">—</span>');
            return;
        }
        $el.html(list.map(function (loc) {
            var label = (loc.codigo || '') + (loc.nombre ? ' — ' + loc.nombre : '');
            var extra = '';
            var actions = '';
            if (kind === 'pref') {
                if (loc.es_preferido == 1 || loc.es_preferido === '1') {
                    extra = ' <span class="pqs-badge is-exacto">preferido</span>';
                }
                if (state.editing && canEditLocations) {
                    var uid = loc.ubicacion_id || loc.id;
                    actions = '<span class="pqs-loc-actions">' +
                        (loc.es_preferido == 1 || loc.es_preferido === '1'
                            ? ''
                            : '<button type="button" class="button button-small pqs-loc-primary" data-ubicacion-id="' +
                              esc(uid) + '">Principal</button>') +
                        '<button type="button" class="button button-small pqs-loc-remove" data-ubicacion-id="' +
                        esc(uid) + '" style="color:#b32d2e;">Quitar</button></span>';
                }
            }
            if (kind === 'act') {
                var qty = loc.cantidad != null ? loc.cantidad : loc.cantidad_contada;
                extra = ' <strong>(' + esc(qty) + ')</strong>';
                if (loc.es_principal == 1 || loc.es_principal === '1') {
                    extra += ' <span class="pqs-badge">principal</span>';
                }
            }
            return '<div class="pqs-supplier-row">' + esc(label) + extra + actions + '</div>';
        }).join(''));
    }

    function renderRelation($el, data, type) {
        var canEditRel = state.editing && (
            (type === 'family' && canManageFamilies) ||
            (type === 'emp' && canManage)
        );
        var emptyLabel = type === 'family' ? 'Sin familia asignada' : 'Sin emparejamiento';

        if (!data) {
            $el.addClass('empty');
            var emptyHtml = '<div>' + emptyLabel + '</div>';
            if (canEditRel) {
                emptyHtml += relationEditControls(type, null);
            }
            $el.html(emptyHtml);
            return;
        }

        $el.removeClass('empty');
        var idAttr = type === 'family' ? data.grupo_id : data.id;
        var html =
            '<div><strong>' + esc(data.codigo || '') + '</strong> · ' + esc(data.nombre || '') +
            ' <span class="pqs-badge">' + esc(data.miembros_count || 0) + ' miembros</span></div>' +
            '<div style="margin-top:6px;">' +
            '<button type="button" class="button button-small pqs-btn-members" data-type="' + esc(type) +
            '" data-id="' + esc(idAttr) + '">Vista rápida</button>';

        if (canEditRel) {
            if (type === 'family') {
                html +=
                    '<button type="button" class="button button-small pqs-rel-edit-family" data-grupo-id="' +
                    esc(data.grupo_id) + '">Editar familia</button>' +
                    '<button type="button" class="button button-small pqs-rel-remove-family" data-member-id="' +
                    esc(data.member_id || 0) + '" style="color:#b32d2e;">Quitar de la familia</button>';
            } else {
                html +=
                    '<button type="button" class="button button-small pqs-rel-edit-emp" data-emp-id="' +
                    esc(data.id) + '">Editar emparejamiento</button>' +
                    '<button type="button" class="button button-small pqs-rel-remove-emp" data-emp-id="' +
                    esc(data.id) + '" style="color:#b32d2e;">Quitar</button>';
            }
        }
        html += '</div>';
        if (canEditRel) {
            html += relationEditControls(type, data);
        }
        $el.html(html);
    }

    function relationEditControls(type, data) {
        if (data) {
            return '';
        }
        var kind = type === 'family' ? 'family' : 'emp';
        return (
            '<div class="pqs-relation-edit">' +
            '<div class="pqs-task-form">' +
            '<input type="text" class="pqs-rel-search" data-kind="' + kind + '" placeholder="' +
            (kind === 'family' ? 'Buscar familia…' : 'Buscar emparejamiento…') + '">' +
            '<input type="hidden" class="pqs-rel-pick-id" value="">' +
            '<button type="button" class="button button-small button-primary pqs-rel-assign" data-kind="' +
            kind + '">Asignar</button>' +
            '<button type="button" class="button button-small pqs-rel-create" data-kind="' +
            kind + '">Crear…</button>' +
            '<div class="pqs-rel-suggestions pqs-sup-suggestions" style="display:none;width:100%;"></div>' +
            '</div></div>'
        );
    }

    function renderBarcodesEditable(barcodes) {
        var list = (barcodes || []).filter(function (b) { return !b.inactivo; });
        if (!list.length) {
            return '<span style="color:#666;">—</span>';
        }
        return list.map(function (b) {
            var legacy = !!b.is_legacy;
            var remove = '';
            if (state.editing && canManage && !legacy) {
                remove = '<button type="button" class="button-link pqs-chip-remove" data-barcode-id="' +
                    esc(b.id) + '" title="Desactivar" style="color:#b32d2e;">×</button>';
            }
            var badge = legacy
                ? ' <span class="pqs-badge" style="background:#9c27b0;">Legacy</span>'
                : '';
            return '<span class="pqs-chip-row"><span class="pqs-chip">' + esc(b.codigo) +
                badge + remove + '</span></span>';
        }).join('');
    }

    function renderSuppliersEditable(suppliers) {
        if (!suppliers || !suppliers.length) {
            return '<span style="color:#666;">—</span>';
        }
        return suppliers.map(function (s) {
            var v = s.vinculo || {};
            var badge = v.badge || '';
            var tip = v.tooltip || '';
            var badgeHtml = badge
                ? ' <span class="pqs-badge" style="background:#ff6b35;">' + esc(badge) + '</span>'
                : '';
            var helpHtml = tip
                ? ' <span class="pqs-help" title="' + esc(tip) + '">?</span>'
                : '';
            var remove = '';
            if (state.editing && canManage && s.id) {
                remove = ' <button type="button" class="button-link pqs-sup-remove" data-pp-id="' +
                    esc(s.id) + '" style="color:#b32d2e;" title="Rechazar vínculo">×</button>';
            }
            return '<div class="pqs-supplier-row">' +
                esc(s.display_name || s.proveedor_nombre || '') +
                ' · <code>' + esc(s.codigo_proveedor || '—') + '</code>' +
                badgeHtml + helpHtml + remove +
                '</div>';
        }).join('');
    }

    function renderLegacyTaskActions(t) {
        var lb = t.legacy_barcode || {};
        var codigo = lb.codigo || '';
        var bid = lb.id || 0;
        var html = '';
        if (codigo) {
            html +=
                '<div class="pqs-legacy-info">Código: <code>' + esc(codigo) + '</code>' +
                (lb.tipo ? ' · tipo ' + esc(lb.tipo) : '') +
                (lb.estado ? ' · ' + esc(lb.estado) : '') +
                (lb.origen_datos ? ' · origen ' + esc(lb.origen_datos) : '') +
                '</div>';
        }
        if (!bid) {
            html += '<p style="color:#d63638;margin:0 0 6px;">No se pudo resolver el barcode_id de esta tarea.</p>';
            return html;
        }
        html += '<div class="pqs-task-form">';
        if (showWizard()) {
            html += '<button type="button" class="button button-small button-primary pqs-legacy-mapear" data-barcode-id="' +
                esc(bid) + '" data-codigo="' + esc(codigo) + '">Mapear…</button>';
        }
        html +=
            '<button type="button" class="button button-small button-primary pqs-legacy-confirm" data-barcode-id="' +
            esc(bid) + '" data-codigo="' + esc(codigo) + '">Confirmar</button>' +
            '<button type="button" class="button button-small pqs-legacy-reject" data-barcode-id="' +
            esc(bid) + '" data-codigo="' + esc(codigo) + '" style="color:#b32d2e;">Rechazar</button>' +
            '</div>';
        return html;
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
                if (tipo === 'confirmar_barcode_legacy') {
                    html += renderLegacyTaskActions(t);
                } else if (tipo === 'barcode_faltante') {
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
                if (tipo !== 'confirmar_barcode_legacy') {
                    html += '<button type="button" class="button button-small pqs-task-complete">Completar</button>';
                }
            } else if (t.target_url) {
                html += '<a class="button button-small" href="' + esc(t.target_url) + '">Ir</a>';
            }

            html += '</div></div>';
            return html;
        }).join(''));
    }

    function setEditing(on) {
        state.editing = !!on;
        var $v = $('#pqs-viewer');
        $v.toggleClass('pqs-editing', state.editing);
        $('#pqs-btn-edit').toggle(!state.editing && canManage);
        $('#pqs-btn-exit-edit').toggle(state.editing);
        $('#pqs-btn-ficha').toggle(state.editing);

        if (state.editing && state.summary) {
            $('#pqs-edit-nombre').val(state.summary.product.nombre || '');
            var stock = state.summary.stock || {};
            $('#pqs-edit-stock-min').val(stock.stock_minimo != null ? stock.stock_minimo : '');
            $('#pqs-edit-stock-crit').val(stock.stock_critico != null ? stock.stock_critico : '');
            $('.pqs-stock-edit').toggle(canEditStock);
            $('#pqs-edit-locations').toggle(canEditLocations);
            $('#pqs-edit-barcodes, #pqs-edit-suppliers').toggle(canManage);
            $('#pqs-edit-precio-wrap').toggle(canManagePrices);
            $('#pqs-btn-refresh-relations').toggle(canManage || canManageFamilies);
            updatePricingUi();
            $('#pqs-v-barcodes').html(renderBarcodesEditable(state.summary.barcodes));
            $('#pqs-v-suppliers').html(renderSuppliersEditable(state.summary.suppliers));
            renderLocations((state.summary.locations && state.summary.locations.preferidas) || [], $('#pqs-v-loc-pref'), 'pref');
            renderRelation($('#pqs-v-family'), state.summary.family, 'family');
            renderRelation($('#pqs-v-emparejamiento'), state.summary.emparejamiento, 'emp');
            watchEditorClose();
        } else if (state.summary) {
            stopWatchEditorClose();
            renderSummary(state.summary);
        }
    }

    function renderSummary(data) {
        state.summary = data;
        state.productId = data.product && data.product.id ? data.product.id : 0;
        if (typeof data.can_manage === 'boolean') {
            canManage = data.can_manage;
        }
        if (typeof data.can_manage_prices === 'boolean') {
            canManagePrices = data.can_manage_prices;
        }
        if (typeof data.can_manage_families === 'boolean') {
            canManageFamilies = data.can_manage_families;
        }
        if (typeof data.can_edit_stock === 'boolean') {
            canEditStock = data.can_edit_stock;
        }
        if (typeof data.can_edit_locations === 'boolean') {
            canEditLocations = data.can_edit_locations;
        }

        $('#pqs-results').hide();
        $('#pqs-viewer').show();

        $('#pqs-v-sku').text(data.product.canonical_sku || '');
        $('#pqs-v-nombre').text(data.product.nombre || '');
        if (state.editing) {
            $('#pqs-edit-nombre').val(data.product.nombre || '');
        }

        var alerts = data.alerts || [];
        var $alerts = $('#pqs-alerts');
        if (!alerts.length) {
            $alerts.html('<div class="pqs-alert" style="border-left-color:#00a32a;background:#edfaef;color:#1e4620;">Sin alertas activas.</div>');
        } else {
            $alerts.html(alerts.map(function (a) {
                return '<div class="pqs-alert is-' + esc(a.level || 'warning') + '">' + esc(a.message) + '</div>';
            }).join(''));
        }

        $('#pqs-v-barcodes').html(renderBarcodesEditable(data.barcodes));

        var supplierCodes = data.supplier_codes_as_barcode || [];
        if (supplierCodes.length) {
            $('#pqs-field-supplier-codes').show();
            $('#pqs-v-supplier-codes').html(supplierCodes.map(function (b) {
                return '<span class="pqs-chip">' + esc(b.codigo) + '</span>';
            }).join(''));
        } else {
            $('#pqs-field-supplier-codes').hide();
            $('#pqs-v-supplier-codes').empty();
        }

        var warnings = data.barcode_warnings || [];
        if (warnings.length) {
            $('#pqs-barcode-warnings').show().html(warnings.map(function (w) {
                return '<div class="pqs-alert is-warning" style="margin-top:6px;">' + esc(w.message) + '</div>';
            }).join(''));
        } else {
            $('#pqs-barcode-warnings').hide().empty();
        }

        $('#pqs-v-suppliers').html(renderSuppliersEditable(data.suppliers));

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
        if (state.editing) {
            $('#pqs-edit-stock-min').val(stock.stock_minimo != null ? stock.stock_minimo : '');
            $('#pqs-edit-stock-crit').val(stock.stock_critico != null ? stock.stock_critico : '');
        }

        renderLocations((data.locations && data.locations.preferidas) || [], $('#pqs-v-loc-pref'), 'pref');
        renderLocations((data.locations && data.locations.actuales) || [], $('#pqs-v-loc-act'), 'act');

        renderRelation($('#pqs-v-family'), data.family, 'family');
        renderRelation($('#pqs-v-emparejamiento'), data.emparejamiento, 'emp');

        renderTasks(data.tasks);

        $('#pqs-btn-edit').toggle(!state.editing && canManage);
        $('#pqs-btn-exit-edit').toggle(state.editing);
        $('#pqs-btn-ficha').toggle(state.editing);
        $('#pqs-viewer').toggleClass('pqs-editing', state.editing);
        if (state.editing) {
            $('.pqs-stock-edit').toggle(canEditStock);
            $('#pqs-edit-locations').toggle(canEditLocations);
            $('#pqs-edit-barcodes, #pqs-edit-suppliers').toggle(canManage);
            $('#pqs-edit-precio-wrap').toggle(canManagePrices);
            $('#pqs-btn-refresh-relations').toggle(canManage || canManageFamilies);
        }
    }

    function highlightMatch(text, query) {
        var raw = text == null ? '' : String(text);
        if (!query || !raw) {
            return esc(raw);
        }
        var lower = raw.toLowerCase();
        var q = String(query).toLowerCase();
        var idx = lower.indexOf(q);
        if (idx < 0) {
            return esc(raw);
        }
        return esc(raw.slice(0, idx)) + '<mark>' + esc(raw.slice(idx, idx + q.length)) + '</mark>' +
            esc(raw.slice(idx + q.length));
    }

    function renderRelated(items, query) {
        state.related = items || [];
        state.lastQuery = query || '';
        var $box = $('#pqs-related');
        if (!state.related.length) {
            $box.hide();
            return;
        }
        $('#pqs-related-title').text(
            'Otras coincidencias que contienen «' + (query || '') + '» (' + state.related.length + ')'
        );
        $('#pqs-related-tbody').html(state.related.map(function (it) {
            return (
                '<tr class="pqs-related-row" data-id="' + esc(it.id) + '">' +
                '<td>' + highlightMatch(it.nombre, query) + '</td>' +
                '<td><code>' + highlightMatch(it.canonical_sku, query) + '</code></td>' +
                '<td><code>' + highlightMatch(it.barcode || '—', query) + '</code></td>' +
                '<td><code>' + highlightMatch(it.codigo_proveedor || '—', query) + '</code></td>' +
                '</tr>'
            );
        }).join(''));
        $box.show();
    }

    function hideRelated() {
        state.related = [];
        $('#pqs-related').hide();
        $('#pqs-related-tbody').empty();
    }

    var editorWatch = null;
    function watchEditorClose() {
        stopWatchEditorClose();
        var hadOverlay = false;
        editorWatch = setInterval(function () {
            var has =
                $('#riverso-family-editor-overlay, #riverso-emp-editor-overlay, .riverso-family-modal, .riverso-emp-modal').length > 0 ||
                $('body > .riverso-family-editor-backdrop, body > .riverso-emp-backdrop').length > 0;
            // Detect common overlay ids used by editors
            has = has || $('[id*="family-editor"], [id*="emparejamiento-editor"], .rfe-overlay, .ree-overlay').filter(':visible').length > 0;
            if (has) {
                hadOverlay = true;
            } else if (hadOverlay) {
                hadOverlay = false;
                refreshViewer();
            }
        }, 800);
    }

    function stopWatchEditorClose() {
        if (editorWatch) {
            clearInterval(editorWatch);
            editorWatch = null;
        }
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
        state.editing = false;
        stopWatchEditorClose();
        post('riverso_products_quick_lookup', { code: code }).then(function (r) {
            if (!r || !r.success) {
                setStatus($('#pqs-status'), (r && r.data && r.data.message) || 'Error', 'error');
                hideRelated();
                return;
            }
            var items = r.data.items || [];
            var related = r.data.related || [];
            if (!items.length && !related.length) {
                setStatus($('#pqs-status'), 'Sin coincidencias para «' + code + '»', 'empty');
                $('#pqs-results').hide();
                hideRelated();
                return;
            }
            if (items.length === 1) {
                setStatus($('#pqs-status'), related.length
                    ? ('1 exacta · ' + related.length + ' otras coincidencias')
                    : '');
                loadSummary(items[0].id);
                renderRelated(related, code);
                return;
            }
            if (items.length > 1) {
                setStatus($('#pqs-status'), items.length + ' coincidencias exactas — seleccioná una');
                renderGridRows(items, '#pqs-results-tbody');
                $('#pqs-results').show();
                renderRelated(related, code);
                return;
            }
            // Solo related
            setStatus($('#pqs-status'), related.length + ' coincidencias parciales — seleccioná una', 'empty');
            $('#pqs-results').hide();
            renderRelated(related, code);
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

    function currentSku() {
        return (state.summary && state.summary.product && state.summary.product.canonical_sku) || '';
    }

    function linkSupplier(productId, supplierId, supplierCode, force) {
        var payload = {
            product_id: productId,
            supplier_id: supplierId,
            supplier_code: supplierCode,
            audit_reason: 'Vinculado desde Búsqueda rápida',
        };
        if (force) {
            payload.force = 1;
        }
        return post('riverso_products_link_supplier', payload).then(function (r) {
            if (r && r.success) {
                return r;
            }
            var data = (r && r.data) || {};
            if (data.conflict || data.already_linked) {
                var owner = data.owner || {};
                var msg = data.message || 'El código ya está vinculado a otro producto.';
                return pqsConfirm({
                    title: 'Código ya vinculado',
                    bodyHtml: '<p>' + esc(msg) + '</p>' +
                        (owner.canonical_sku
                            ? '<p>Dueño actual: <code>' + esc(owner.canonical_sku) + '</code> · ' +
                              esc(owner.nombre_canonico || '') + '</p>'
                            : '') +
                        '<p>¿Forzar la reasignación a este producto?</p>',
                    okLabel: 'Forzar',
                    danger: true,
                    requireCheck: true,
                }).then(function () {
                    return linkSupplier(productId, supplierId, supplierCode, true);
                });
            }
            return $.Deferred().reject(data.message || 'Error al vincular').promise();
        });
    }

    function savePrice(confirmEmp) {
        var raw = $('#pqs-edit-precio').val();
        var bruto = toBruto(raw);
        if (!(bruto > 0)) {
            alert('Ingresá un precio válido');
            return $.Deferred().reject().promise();
        }
        var payload = {
            producto_base_id: state.productId,
            canal: 'local',
            p_asignado: bruto,
        };
        if (confirmEmp) {
            payload.confirm_emparejamiento = 1;
        }
        return post('riverso_price_save_assigned', payload).then(function (r) {
            if (r && r.success) {
                return refreshViewer();
            }
            var data = (r && r.data) || {};
            if (data.code === 'emparejamiento_confirm') {
                var emp = data.emparejamiento || {};
                var preview = data.preview || {};
                var members = preview.members || preview.miembros || [];
                var listHtml = members.length
                    ? '<ul>' + members.slice(0, 12).map(function (m) {
                        return '<li><code>' + esc(m.canonical_sku || m.sku || '') + '</code> · ' +
                            esc(m.nombre_canonico || m.nombre || '') + '</li>';
                    }).join('') + (members.length > 12 ? '<li>…y ' + (members.length - 12) + ' más</li>' : '') + '</ul>'
                    : '<p>Se actualizarán todos los miembros del emparejamiento.</p>';
                return pqsConfirm({
                    title: 'Producto emparejado',
                    bodyHtml: '<p>' + esc(data.message || '') + '</p>' +
                        '<p><strong>' + esc(emp.codigo || '') + '</strong> · ' + esc(emp.nombre || '') + '</p>' +
                        listHtml +
                        '<p>Nuevo precio bruto: <strong>' + fmtMoney(bruto) + '</strong></p>',
                    okLabel: 'Guardar en el grupo',
                    danger: true,
                    requireCheck: true,
                }).then(function () {
                    return savePrice(true);
                });
            }
            alert(data.message || 'No se pudo guardar el precio');
            return $.Deferred().reject().promise();
        });
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
            state.editing = false;
            loadSummary($(this).data('id'));
        });
        $(document).on('click', '#pqs-lupa-tbody .pqs-lupa-row', function () {
            var id = $(this).data('id');
            closeLupa();
            state.editing = false;
            loadSummary(id);
        });

        $('#pqs-btn-close-viewer').on('click', function () {
            $('#pqs-viewer').hide();
            state.productId = 0;
            state.summary = null;
            state.editing = false;
            $('#pqs-viewer').removeClass('pqs-editing');
        });

        $('#pqs-btn-edit').on('click', function () {
            if (!state.productId || !canManage) {
                return;
            }
            setEditing(true);
        });
        $('#pqs-btn-exit-edit').on('click', function () {
            setEditing(false);
        });
        $('#pqs-btn-ficha').on('click', function (e) {
            e.preventDefault();
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

        // Confirm modal
        $('#pqs-confirm-cancel, #pqs-confirm-close').on('click', function () {
            closeConfirm(false);
        });
        $('#pqs-confirm-modal').on('click', function (e) {
            if (e.target === this) {
                closeConfirm(false);
            }
        });
        $('#pqs-confirm-check').on('change', function () {
            $('#pqs-confirm-ok').prop('disabled', !$(this).is(':checked'));
        });
        $('#pqs-confirm-ok').on('click', function () {
            if ($(this).prop('disabled')) {
                return;
            }
            closeConfirm(true);
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

        // —— Legacy resolver ——
        $(document).on('click', '.pqs-legacy-mapear', function () {
            var barcodeId = $(this).data('barcode-id');
            var codigo = $(this).data('codigo') || '';
            if (!barcodeId || !state.productId) {
                return;
            }
            if (!window.RiversoProductsHub || typeof window.RiversoProductsHub.openBarcodeRemap !== 'function') {
                alert('Wizard de mapeo no disponible. Abrí la ficha completa.');
                return;
            }
            window.RiversoProductsHub.openBarcodeRemap(state.productId, barcodeId, function () {
                refreshViewer();
            });
        });

        $(document).on('click', '.pqs-legacy-confirm', function () {
            var barcodeId = $(this).data('barcode-id');
            var codigo = $(this).data('codigo') || '';
            if (!barcodeId || !state.productId) {
                return;
            }
            var sku = currentSku();
            var withWizard = showWizard();
            var body = withWizard
                ? '<p>Se confirmará el código legacy <code>' + esc(codigo) + '</code> en el SKU <code>' +
                  esc(sku) + '</code> como unitario (qty 1).</p><p>Si era legacy, quedará como código de proveedor interno verificado y se cerrará la tarea.</p>'
                : '<p>Se aceptará el código legacy <code>' + esc(codigo) + '</code> del SKU <code>' +
                  esc(sku) + '</code> como <strong>Código de Proveedor</strong> interno verificado.</p>' +
                  '<p>Quedará editable y se cerrará la tarea.</p>';

            pqsConfirm({
                title: 'Confirmar código legacy',
                bodyHtml: body,
                okLabel: 'Sí, confirmar',
            }).then(function () {
                if (withWizard) {
                    return post('riverso_products_barcode_remap', {
                        barcode_id: barcodeId,
                        product_id: state.productId,
                        accion: 'keep_unit',
                        verify: 1,
                        audit_reason: 'Confirmado desde Búsqueda rápida',
                    });
                }
                return post('riverso_products_accept_legacy_barcode', {
                    barcode_id: barcodeId,
                    product_id: state.productId,
                    audit_reason: 'Aceptado desde Búsqueda rápida',
                });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo confirmar');
                    return;
                }
                refreshViewer();
            }).fail(function (reason) {
                if (reason !== 'cancel' && reason !== 'superseded') {
                    /* noop */
                }
            });
        });

        $(document).on('click', '.pqs-legacy-reject', function () {
            var barcodeId = $(this).data('barcode-id');
            var codigo = $(this).data('codigo') || '';
            if (!barcodeId || !state.productId) {
                return;
            }
            var sku = currentSku();
            pqsConfirm({
                title: 'Rechazar código legacy',
                bodyHtml:
                    '<p>Se <strong>rechazará y eliminará</strong> el código <code>' + esc(codigo) +
                    '</code> y sus duplicados del SKU <code>' + esc(sku) + '</code>.</p>' +
                    '<p>La tarea se cerrará. Esta acción no se puede deshacer fácilmente.</p>',
                okLabel: 'Sí, rechazar',
                danger: true,
                requireCheck: true,
            }).then(function () {
                return post('riverso_products_reject_legacy_barcode', {
                    barcode_id: barcodeId,
                    product_id: state.productId,
                    audit_reason: 'Rechazado desde Búsqueda rápida',
                });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo rechazar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
        });

        // —— Tasks genéricas ——
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
            var $form = $(this).closest('.pqs-task-form, #pqs-edit-suppliers');
            $form.find('.pqs-task-sup-id, #pqs-sup-id').val($(this).data('id'));
            $form.find('.pqs-task-sup-search, #pqs-sup-search').val($(this).data('name'));
            $form.find('.pqs-sup-suggestions, #pqs-sup-suggestions').hide();
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
            linkSupplier(state.productId, sid, code, false).then(function () {
                return post('riverso_products_complete_task', {
                    tarea_id: $task.data('task-id'),
                });
            }).then(function () {
                refreshViewer();
            }).fail(function (msg) {
                if (msg && msg !== 'cancel' && msg !== 'superseded') {
                    alert(typeof msg === 'string' ? msg : 'Error al asignar código');
                }
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

        // —— Edición inline ——
        $('#pqs-save-nombre').on('click', function () {
            var nombre = ($('#pqs-edit-nombre').val() || '').trim();
            if (!nombre || !state.productId) {
                return;
            }
            post('riverso_products_quick_update_name', {
                producto_id: state.productId,
                nombre: nombre,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo guardar');
                    return;
                }
                if (r.data.summary) {
                    renderSummary(r.data.summary);
                } else {
                    refreshViewer();
                }
            });
        });

        $('#pqs-btn-add-barcode').on('click', function () {
            var code = ($('#pqs-add-barcode').val() || '').trim();
            if (!code || !state.productId) {
                return;
            }
            post('riverso_products_add_barcode', {
                product_id: state.productId,
                barcode: code,
                audit_reason: 'Agregado desde Búsqueda rápida',
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'Error al agregar');
                    return;
                }
                $('#pqs-add-barcode').val('');
                refreshViewer();
            });
        });

        $(document).on('click', '.pqs-chip-remove', function () {
            var barcodeId = $(this).data('barcode-id');
            var code = $(this).closest('.pqs-chip').clone().children().remove().end().text().trim();
            pqsConfirm({
                title: 'Desactivar código de barras',
                bodyHtml: '<p>¿Desactivar el código <code>' + esc(code || barcodeId) +
                    '</code> del SKU <code>' + esc(currentSku()) + '</code>?</p>',
                okLabel: 'Desactivar',
                danger: true,
            }).then(function () {
                return post('riverso_products_remove_barcode', {
                    barcode_id: barcodeId,
                    product_id: state.productId,
                    audit_reason: 'Desactivado desde Búsqueda rápida',
                });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo desactivar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
        });

        $('#pqs-sup-search').on('input', function () {
            var $input = $(this);
            var q = ($input.val() || '').trim();
            clearTimeout($input.data('to'));
            if (q.length < 2) {
                $('#pqs-sup-suggestions').hide();
                return;
            }
            $input.data('to', setTimeout(function () {
                post('riverso_search_suppliers', { search: q, limit: 10 }).then(function (r) {
                    var list = (r && r.success && (r.data.suppliers || r.data.items || [])) || [];
                    if (!list.length) {
                        $('#pqs-sup-suggestions').hide();
                        return;
                    }
                    $('#pqs-sup-suggestions').html(list.slice(0, 8).map(function (s) {
                        var id = s.id || s.proveedor_id;
                        var name = s.nombre || s.name || '';
                        return '<button type="button" class="button button-small pqs-pick-sup" data-id="' +
                            esc(id) + '" data-name="' + esc(name) + '" style="margin:2px;">' +
                            esc(name) + '</button>';
                    }).join('')).show();
                });
            }, 300));
        });

        $('#pqs-btn-add-supplier').on('click', function () {
            var sid = $('#pqs-sup-id').val();
            var code = ($('#pqs-sup-code').val() || '').trim();
            if (!sid || !code || !state.productId) {
                alert('Seleccioná proveedor e ingresá código');
                return;
            }
            linkSupplier(state.productId, sid, code, false).then(function () {
                $('#pqs-sup-code').val('');
                $('#pqs-sup-id').val('');
                $('#pqs-sup-search').val('');
                return refreshViewer();
            }).fail(function (msg) {
                if (msg && msg !== 'cancel' && msg !== 'superseded') {
                    alert(typeof msg === 'string' ? msg : 'Error al vincular');
                }
            });
        });

        $(document).on('click', '.pqs-sup-remove', function () {
            var ppId = $(this).data('pp-id');
            var rowText = $(this).closest('.pqs-supplier-row').text().replace('×', '').trim();
            pqsConfirm({
                title: 'Quitar código de proveedor',
                bodyHtml: '<p>¿Rechazar el vínculo <strong>' + esc(rowText) +
                    '</strong> del SKU <code>' + esc(currentSku()) + '</code>?</p>' +
                    '<p>Quedará inactivo.</p>',
                okLabel: 'Rechazar vínculo',
                danger: true,
                requireCheck: true,
            }).then(function () {
                return post('riverso_codes_reject', { pp_id: ppId });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo rechazar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
        });

        $('#pqs-save-precio').on('click', function () {
            savePrice(false).fail(function () { /* cancel / error already shown */ });
        });

        $('#pqs-save-stock-cfg').on('click', function () {
            if (!canEditStock) {
                return;
            }
            post('riverso_stock_status_save_config', {
                producto_base_id: state.productId,
                stock_minimo: $('#pqs-edit-stock-min').val(),
                stock_critico: $('#pqs-edit-stock-crit').val(),
                last_changed: 'minimo',
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo guardar');
                    return;
                }
                refreshViewer();
            });
        });

        $('#pqs-loc-search').on('input', function () {
            var $input = $(this);
            var q = ($input.val() || '').trim();
            clearTimeout($input.data('to'));
            if (q.length < 1) {
                $('#pqs-loc-suggestions').hide();
                return;
            }
            $input.data('to', setTimeout(function () {
                post('riverso_inventory_get_locations', { search: q, activo: 1 }).then(function (r) {
                    var list = (r && r.success && (r.data.locations || [])) || [];
                    if (!list.length) {
                        $('#pqs-loc-suggestions').html('<span style="color:#666;padding:4px;">Sin resultados</span>').show();
                        return;
                    }
                    $('#pqs-loc-suggestions').html(list.slice(0, 10).map(function (loc) {
                        var label = (loc.codigo || '') + (loc.nombre ? ' — ' + loc.nombre : '');
                        return '<button type="button" class="button button-small pqs-pick-loc" data-id="' +
                            esc(loc.id) + '" data-label="' + esc(label) + '" style="margin:2px;display:block;">' +
                            esc(label) + '</button>';
                    }).join('')).show();
                });
            }, 280));
        });

        $(document).on('click', '.pqs-pick-loc', function () {
            $('#pqs-loc-id').val($(this).data('id'));
            $('#pqs-loc-search').val($(this).data('label'));
            $('#pqs-loc-suggestions').hide();
        });

        $('#pqs-btn-add-loc').on('click', function () {
            var uid = $('#pqs-loc-id').val();
            if (!uid || !state.productId) {
                alert('Seleccioná una ubicación');
                return;
            }
            post('riverso_inventory_save_preferred_location', {
                producto_base_id: state.productId,
                ubicacion_id: uid,
                es_preferido: 0,
                prioridad: 100,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo agregar');
                    return;
                }
                $('#pqs-loc-id').val('');
                $('#pqs-loc-search').val('');
                refreshViewer();
            });
        });

        $(document).on('click', '.pqs-loc-remove', function () {
            var uid = $(this).data('ubicacion-id');
            pqsConfirm({
                title: 'Quitar lugar preferido',
                bodyHtml: '<p>¿Quitar esta ubicación preferida del SKU <code>' +
                    esc(currentSku()) + '</code>?</p>',
                okLabel: 'Quitar',
                danger: true,
            }).then(function () {
                return post('riverso_inventory_remove_preferred_location', {
                    producto_base_id: state.productId,
                    ubicacion_id: uid,
                });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo quitar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
        });

        $(document).on('click', '.pqs-loc-primary', function () {
            var uid = $(this).data('ubicacion-id');
            post('riverso_inventory_set_primary_location', {
                producto_base_id: state.productId,
                ubicacion_id: uid,
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo marcar');
                    return;
                }
                refreshViewer();
            });
        });

        // —— Otras coincidencias ——
        $(document).on('click', '#pqs-related-tbody .pqs-related-row', function () {
            state.editing = false;
            loadSummary($(this).data('id'));
        });

        // —— Familia / Emparejamiento ——
        $('#pqs-btn-refresh-relations').on('click', function () {
            refreshViewer();
        });

        function seedMember() {
            var p = (state.summary && state.summary.product) || {};
            return {
                producto_base_id: state.productId,
                canonical_sku: p.canonical_sku || '',
                sku_local: p.canonical_sku || '',
                nombre_canonico: p.nombre || '',
                es_local: true,
            };
        }

        $(document).on('input', '.pqs-rel-search', function () {
            var $input = $(this);
            var kind = $input.data('kind');
            var $wrap = $input.closest('.pqs-relation-edit');
            var q = ($input.val() || '').trim();
            clearTimeout($input.data('to'));
            if (q.length < 2 && !(kind === 'family' && /^\d+$/.test(q))) {
                $wrap.find('.pqs-rel-suggestions').hide();
                return;
            }
            $input.data('to', setTimeout(function () {
                var action = kind === 'family'
                    ? 'riverso_families_list'
                    : 'riverso_emparejamientos_list';
                post(action, { search: q }).then(function (r) {
                    var list = [];
                    if (r && r.success) {
                        list = kind === 'family'
                            ? (r.data.families || [])
                            : (r.data.items || r.data.emparejamientos || []);
                    }
                    var $sug = $wrap.find('.pqs-rel-suggestions');
                    if (!list.length) {
                        $sug.html('<span style="color:#666;padding:4px;">Sin resultados</span>').show();
                        return;
                    }
                    $sug.html(list.slice(0, 10).map(function (it) {
                        var id = kind === 'family' ? it.id : it.id;
                        var code = kind === 'family' ? (it.codigo_grupo || '') : (it.codigo || '');
                        var name = it.nombre || '';
                        var label = (code ? code + ' · ' : '') + name;
                        return '<button type="button" class="button button-small pqs-rel-pick" data-kind="' +
                            esc(kind) + '" data-id="' + esc(id) + '" data-label="' + esc(label) +
                            '" style="margin:2px;display:block;">' + esc(label) +
                            (it.miembros_count != null ? ' (' + esc(it.miembros_count) + ')' : '') +
                            '</button>';
                    }).join('')).show();
                });
            }, 280));
        });

        $(document).on('click', '.pqs-rel-pick', function () {
            var $wrap = $(this).closest('.pqs-relation-edit');
            $wrap.find('.pqs-rel-pick-id').val($(this).data('id'));
            $wrap.find('.pqs-rel-search').val($(this).data('label'));
            $wrap.find('.pqs-rel-suggestions').hide();
        });

        $(document).on('click', '.pqs-rel-assign', function () {
            var kind = $(this).data('kind');
            var $wrap = $(this).closest('.pqs-relation-edit');
            var rid = parseInt($wrap.find('.pqs-rel-pick-id').val(), 10) || 0;
            if (!rid || !state.productId) {
                alert(kind === 'family' ? 'Seleccioná una familia' : 'Seleccioná un emparejamiento');
                return;
            }
            var label = $wrap.find('.pqs-rel-search').val() || String(rid);
            if (kind === 'family') {
                pqsConfirm({
                    title: 'Asignar a familia',
                    bodyHtml: '<p>¿Agregar el SKU <code>' + esc(currentSku()) +
                        '</code> a la familia <strong>' + esc(label) + '</strong>?</p>',
                    okLabel: 'Asignar',
                }).then(function () {
                    return post('riverso_families_add_member', {
                        grupo_id: rid,
                        producto_base_id: state.productId,
                    });
                }).then(function (r) {
                    if (!r || !r.success) {
                        alert((r && r.data && r.data.message) || 'No se pudo asignar');
                        return;
                    }
                    refreshViewer();
                }).fail(function () { /* cancel */ });
                return;
            }

            post('riverso_emparejamientos_add_member', {
                emparejamiento_id: rid,
                producto_base_id: state.productId,
            }).then(function (r) {
                if (r && r.success) {
                    refreshViewer();
                    return;
                }
                var data = (r && r.data) || {};
                if (data.code === 'price_conflict') {
                    if (window.RiversoEmparejamientoEditor &&
                        typeof window.RiversoEmparejamientoEditor.openPriceConflict === 'function') {
                        window.RiversoEmparejamientoEditor.openPriceConflict({
                            emparejamientoId: rid,
                            productoBaseId: state.productId,
                            conflict: data.data || {},
                            sku: currentSku(),
                            nombre: (state.summary.product && state.summary.product.nombre) || '',
                            onDone: function () {
                                refreshViewer();
                            },
                        });
                        return;
                    }
                }
                alert(data.message || 'No se pudo asignar');
            });
        });

        $(document).on('click', '.pqs-rel-create', function () {
            var kind = $(this).data('kind');
            var seed = seedMember();
            if (kind === 'family') {
                if (!window.RiversoFamilyEditor || typeof window.RiversoFamilyEditor.openCreate !== 'function') {
                    alert('Editor de familias no disponible');
                    return;
                }
                window.RiversoFamilyEditor.openCreate(function () {
                    refreshViewer();
                }, {
                    nombre: seed.nombre_canonico || '',
                    pendingMembers: [seed],
                });
                watchEditorClose();
                return;
            }
            if (!window.RiversoEmparejamientoEditor ||
                typeof window.RiversoEmparejamientoEditor.openCreate !== 'function') {
                alert('Editor de emparejamientos no disponible');
                return;
            }
            window.RiversoEmparejamientoEditor.openCreate(function () {
                refreshViewer();
            }, {
                suggestedNombre: seed.nombre_canonico || '',
                seedMembers: [seed],
                allowSingleMember: true,
            });
            watchEditorClose();
        });

        $(document).on('click', '.pqs-rel-edit-family', function () {
            var gid = $(this).data('grupo-id');
            if (!window.RiversoFamilyEditor || typeof window.RiversoFamilyEditor.openEdit !== 'function') {
                alert('Editor de familias no disponible');
                return;
            }
            window.RiversoFamilyEditor.openEdit(gid);
            watchEditorClose();
        });

        $(document).on('click', '.pqs-rel-edit-emp', function () {
            var eid = $(this).data('emp-id');
            if (!window.RiversoEmparejamientoEditor || typeof window.RiversoEmparejamientoEditor.open !== 'function') {
                alert('Editor de emparejamientos no disponible');
                return;
            }
            window.RiversoEmparejamientoEditor.open(eid);
            watchEditorClose();
        });

        $(document).on('click', '.pqs-rel-remove-family', function () {
            var mid = $(this).data('member-id');
            if (!mid) {
                alert('No se pudo resolver el miembro de familia');
                return;
            }
            pqsConfirm({
                title: 'Quitar de la familia',
                bodyHtml: '<p>¿Quitar el SKU <code>' + esc(currentSku()) +
                    '</code> de su familia actual?</p>',
                okLabel: 'Quitar',
                danger: true,
                requireCheck: true,
            }).then(function () {
                return post('riverso_families_remove_member', { member_id: mid });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo quitar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
        });

        $(document).on('click', '.pqs-rel-remove-emp', function () {
            var eid = $(this).data('emp-id');
            pqsConfirm({
                title: 'Quitar del emparejamiento',
                bodyHtml: '<p>¿Quitar el SKU <code>' + esc(currentSku()) +
                    '</code> de su emparejamiento actual?</p>',
                okLabel: 'Quitar',
                danger: true,
                requireCheck: true,
            }).then(function () {
                return post('riverso_emparejamientos_remove_member', {
                    emparejamiento_id: eid,
                    producto_base_id: state.productId,
                });
            }).then(function (r) {
                if (!r || !r.success) {
                    alert((r && r.data && r.data.message) || 'No se pudo quitar');
                    return;
                }
                refreshViewer();
            }).fail(function () { /* cancel */ });
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
        refresh: refreshViewer,
    };

    window.RiversoProductQuickSearch = api;

    $(function () {
        api.init();
    });
})(jQuery);
