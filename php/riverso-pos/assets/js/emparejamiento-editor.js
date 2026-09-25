/**
 * Editor de Emparejamientos (Categorías + Procesar folios).
 * Modal crear/editar tipo Familias: draft, avisos, Guardar todo.
 */
(function($) {
    'use strict';

    var cfg = window.riversoEmparejamiento || {};
    var ajaxUrl = cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = cfg.nonce || '';

    function esc(v) {
        return $('<div>').text(v == null ? '' : v).html();
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = nonce;
        return $.post(ajaxUrl, data);
    }

    function money(n) {
        if (n == null || n === '') return '—';
        return Number(n).toLocaleString('es-CL', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function slugifyCodigoPreview(nombre) {
        var s = String(nombre || '');
        try {
            if (typeof s.normalize === 'function') {
                s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
        } catch (e) {}
        s = s.toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toUpperCase()
            .slice(0, 40);
        return s ? ('EMP-' + s) : '';
    }

    function ensureStyles() {
        if ($('#riverso-emp-editor-styles').length) return;
        $('head').append(
            '<style id="riverso-emp-editor-styles">' +
            '.riverso-emp-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;display:flex;align-items:center;justify-content:center;}' +
            '.riverso-emp-modal-panel{background:#fff;border-radius:8px;max-width:920px;width:96%;max-height:90vh;overflow:auto;padding:18px 20px;box-shadow:0 8px 28px rgba(0,0,0,.2);}' +
            '.riverso-emp-warn{margin:10px 0;padding:10px 12px;border-radius:4px;border-left:4px solid #dba617;background:#fff8e1;font-size:13px;}' +
            '.riverso-emp-warn.is-error{border-left-color:#b32d2e;background:#fcebea;}' +
            '.riverso-emp-warn ul{margin:6px 0 0 18px;}' +
            '.riverso-emp-member-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 10px;border:1px solid #e2e4e7;border-radius:4px;margin-bottom:6px;background:#fafafa;}' +
            '.riverso-emp-flag-block{margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:6px;background:#f9f9f9;}' +
            '.riverso-emp-create-member{margin-top:14px;padding:12px;border:1px solid #c8e6c9;border-radius:4px;background:#f1f8e9;}' +
            '.riverso-emp-name-suggestions{margin-top:6px;min-height:0;}' +
            '.riverso-emp-cand-row{display:flex;gap:6px;align-items:center;}' +
            '.riverso-emp-cand-row .emp-cand-q{flex:1;min-width:180px;padding:6px;}' +
            '.riverso-emp-adv-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100050;display:flex;align-items:center;justify-content:center;}' +
            '.riverso-emp-adv-panel{background:#fff;border-radius:8px;max-width:960px;width:96%;max-height:92vh;overflow:auto;padding:16px 18px;box-shadow:0 10px 32px rgba(0,0,0,.25);}' +
            '.riverso-emp-adv-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:10px 0 12px;}' +
            '.riverso-emp-adv-filters label{font-size:12px;font-weight:600;display:block;}' +
            '.riverso-emp-adv-filters input{width:100%;padding:6px;box-sizing:border-box;margin-top:3px;font-weight:400;}' +
            '.riverso-emp-adv-grid{width:100%;border-collapse:collapse;font-size:13px;}' +
            '.riverso-emp-adv-grid th,.riverso-emp-adv-grid td{border:1px solid #e2e4e7;padding:7px 8px;text-align:left;vertical-align:top;}' +
            '.riverso-emp-adv-grid th{background:#f6f7f7;}' +
            '.riverso-emp-adv-grid tr.is-selectable{cursor:pointer;}' +
            '.riverso-emp-adv-grid tr.is-selectable:hover{background:#f0f6fc;}' +
            '.riverso-emp-adv-grid tr.is-disabled{opacity:.55;}' +
            '.riverso-emp-code-hint{font-size:11px;color:#666;display:block;}' +
            '.riverso-emp-preview-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:8px 0;}' +
            '.riverso-emp-preview-toolbar .button.is-active{background:#2271b1;border-color:#2271b1;color:#fff;}' +
            '.riverso-emp-origin-btn{margin-left:4px;padding:0 5px;min-height:20px;line-height:1.2;font-size:11px;cursor:pointer;}' +
            '.emp-price-preview-box{overflow-x:auto;}' +
            '.riverso-emp-conflict-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100060;display:flex;align-items:center;justify-content:center;}' +
            '.riverso-emp-conflict-panel{background:#fff;border-radius:8px;max-width:960px;width:96%;max-height:92vh;overflow:auto;padding:16px 18px;box-shadow:0 10px 32px rgba(0,0,0,.25);}' +
            '.riverso-emp-conflict-opt{display:block;margin:6px 0;padding:8px 10px;border:1px solid #ddd;border-radius:4px;background:#fafafa;}' +
            '.riverso-emp-conflict-opt.is-selected{border-color:#2271b1;background:#f0f6fc;}' +
            '.riverso-emp-margin-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100055;display:flex;align-items:center;justify-content:center;}' +
            '.riverso-emp-margin-panel{background:#fff;border-radius:8px;max-width:960px;width:96%;max-height:92vh;overflow:auto;padding:16px 18px;box-shadow:0 10px 32px rgba(0,0,0,.25);}' +
            '</style>'
        );
    }

    function closeAll() {
        closeConflictPanel();
        closeMarginPreviewPanel();
        $('.riverso-emp-adv-overlay').remove();
        $('.riverso-emp-modal-overlay').remove();
    }

    function closeAdvancedSearch() {
        $('.riverso-emp-adv-overlay').remove();
    }

    function closeConflictPanel() {
        $('.riverso-emp-conflict-overlay').remove();
    }

    function closeMarginPreviewPanel() {
        $('.riverso-emp-margin-overlay').remove();
    }

    function empIsExento(iva) {
        return String(iva || 'afecto').toLowerCase() === 'exento';
    }

    function empPickCostPair(bases, mode) {
        mode = mode || 'tras_dr';
        if (!bases || typeof bases !== 'object') return null;
        var key = mode === 'referencia' ? 'referencia'
            : (mode === 'tras_dr_flete' ? 'tras_dr_flete'
            : (mode === 'tras_dr_folio' ? 'tras_dr_folio' : 'tras_dr'));
        var pair = bases[key];
        if (!pair && key === 'tras_dr_folio') pair = bases.tras_dr;
        if (!pair && key === 'tras_dr_flete') pair = bases.tras_dr_folio || bases.tras_dr;
        if (!pair && key === 'referencia') pair = bases.tras_dr;
        if (!pair || typeof pair !== 'object') return null;
        return {
            neto: pair.neto != null && pair.neto !== '' && !isNaN(pair.neto) ? Number(pair.neto) : null,
            bruto: pair.bruto != null && pair.bruto !== '' && !isNaN(pair.bruto) ? Number(pair.bruto) : null
        };
    }

    function empOriginHint(origen, kindLabel) {
        origen = origen || {};
        var label = origen.label || '';
        if (!label || label === '—') return '';
        var parts = [kindLabel ? (kindLabel + ': ' + label) : label];
        if (origen.folio) parts.push('Folio ' + origen.folio);
        if (origen.fecha) parts.push(String(origen.fecha).slice(0, 10));
        var title = parts.join(' · ');
        return ' <button type="button" class="button-link riverso-emp-origin-btn emp-origin-hint" title="' +
            esc(title) + '"' +
            (origen.url ? ' data-url="' + esc(origen.url) + '"' : '') +
            ' data-label="' + esc(title) + '" aria-label="' + esc(title) + '">?</button>';
    }

    function empFmtFactor(f) {
        if (f == null || f === '' || isNaN(f)) return '—';
        return Number(f).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    }

    function computePreviewRowDisplay(r, viewMode, costMode) {
        viewMode = viewMode || 'neto';
        costMode = costMode || 'tras_dr';
        var iva = r.iva_tipo || 'afecto';
        var pair = empPickCostPair(r.c_ref_bases, costMode);
        var cNeto = pair && pair.neto != null ? pair.neto
            : (r.c_ref_neto != null ? Number(r.c_ref_neto) : null);
        var cBruto = pair && pair.bruto != null ? pair.bruto
            : (r.c_ref_bruto != null ? Number(r.c_ref_bruto)
                : (r.c_ref != null ? Number(r.c_ref) : null));
        if (cNeto == null && cBruto != null && !empIsExento(iva)) {
            cNeto = Math.round((cBruto / 1.19) * 10000) / 10000;
        }
        if (cBruto == null && cNeto != null && !empIsExento(iva)) {
            cBruto = Math.round((cNeto * 1.19) * 10000) / 10000;
        }

        var pActBruto = r.p_asignado_actual != null ? Number(r.p_asignado_actual) : null;
        var pNewBruto = r.p_asignado_nuevo != null ? Number(r.p_asignado_nuevo) : null;
        var pActNeto = r.p_neto_actual != null ? Number(r.p_neto_actual)
            : (pActBruto != null && !empIsExento(iva) ? Math.round((pActBruto / 1.19) * 10000) / 10000 : pActBruto);
        var pNewNeto = r.p_neto_nuevo != null ? Number(r.p_neto_nuevo)
            : (pNewBruto != null && !empIsExento(iva) ? Math.round((pNewBruto / 1.19) * 10000) / 10000 : pNewBruto);

        var showNeto = viewMode === 'neto' || empIsExento(iva);
        var cShow = showNeto ? cNeto : cBruto;
        var pActShow = showNeto ? pActNeto : pActBruto;
        var pNewShow = showNeto ? pNewNeto : pNewBruto;
        var margen = (pNewNeto != null && cNeto != null)
            ? Math.round((pNewNeto - cNeto) * 1000) / 1000 : null;
        var factor = (pNewNeto != null && cNeto != null && cNeto > 0)
            ? Math.round((pNewNeto / cNeto) * 10000) / 10000 : null;

        return {
            cShow: cShow,
            pActShow: pActShow,
            pNewShow: pNewShow,
            margen: margen,
            factor: factor,
            alerta: !!r.alerta_margen
        };
    }

    function renderPreviewTable(preview, opts) {
        opts = opts || {};
        var viewMode = opts.viewMode || 'neto';
        var costMode = opts.costMode || 'tras_dr';
        if (!preview || !preview.length) {
            return '<p style="color:#666;margin:8px 0;">Sin miembros para previsualizar.</p>';
        }
        var margenLabel = viewMode === 'bruto' ? 'Margen' : 'Margen neto';
        var html = '<table class="widefat striped" style="margin-top:8px;"><thead><tr>' +
            '<th>SKU</th><th>Nombre</th><th>Costo</th><th>P actual</th><th>P nuevo</th>' +
            '<th>' + margenLabel + '</th><th>Factor</th><th></th>' +
            '</tr></thead><tbody>';
        preview.forEach(function(r) {
            var d = computePreviewRowDisplay(r, viewMode, costMode);
            var alert = d.alerta ? ' <span style="color:#b32d2e;">⚠</span>' : '';
            html += '<tr>' +
                '<td>' + esc(r.sku || '') + '</td>' +
                '<td>' + esc(r.nombre || '') + '</td>' +
                '<td>' + money(d.cShow) + empOriginHint(r.origen_costo, 'Costo') + '</td>' +
                '<td>' + money(d.pActShow) + empOriginHint(r.origen_precio, 'Precio') + '</td>' +
                '<td><strong>' + money(d.pNewShow) + '</strong></td>' +
                '<td>' + money(d.margen) + alert + '</td>' +
                '<td>' + empFmtFactor(d.factor) + '</td>' +
                '<td></td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderPreviewBox($modal, preview, bannerHtml) {
        preview = preview || [];
        $modal.data('previewRows', preview);
        var viewMode = $modal.data('previewViewMode') || 'neto';
        var costMode = $modal.data('previewCostMode') || 'tras_dr';
        $modal.data('previewViewMode', viewMode);
        $modal.data('previewCostMode', costMode);

        var toolbar =
            '<div class="riverso-emp-preview-toolbar">' +
            '<span class="button-group emp-preview-view" role="group" aria-label="Vista montos">' +
            '<button type="button" class="button emp-preview-view-btn' + (viewMode === 'neto' ? ' is-active' : '') +
            '" data-view="neto">Neto</button>' +
            '<button type="button" class="button emp-preview-view-btn' + (viewMode === 'bruto' ? ' is-active' : '') +
            '" data-view="bruto">Bruto</button>' +
            '</span>' +
            '<span class="button-group emp-preview-cost" role="group" aria-label="Base de costo">' +
            '<button type="button" class="button emp-preview-cost-btn' + (costMode === 'referencia' ? ' is-active' : '') +
            '" data-cost="referencia" title="Precio lista / antes de D/R">Referencia</button>' +
            '<button type="button" class="button emp-preview-cost-btn' + (costMode === 'tras_dr' ? ' is-active' : '') +
            '" data-cost="tras_dr" title="Tras descuento y recargo de fila">Tras D/R</button>' +
            '<button type="button" class="button emp-preview-cost-btn' + (costMode === 'tras_dr_folio' ? ' is-active' : '') +
            '" data-cost="tras_dr_folio" title="Tras D/R de fila y folio">Tras D/R folio</button>' +
            '<button type="button" class="button emp-preview-cost-btn' + (costMode === 'tras_dr_flete' ? ' is-active' : '') +
            '" data-cost="tras_dr_flete" title="Tras D/R más flete">Más flete</button>' +
            '</span>' +
            '</div>';

        $modal.find('.emp-price-preview-box').html(
            (bannerHtml || '') + toolbar +
            renderPreviewTable(preview, { viewMode: viewMode, costMode: costMode })
        );
    }

    function refreshPreviewBox($modal) {
        var rows = $modal.data('previewRows') || [];
        if (!rows.length) return;
        renderPreviewBox($modal, rows);
    }

    /**
     * Deduplica opciones de conflicto por p_asignado y prioriza candidate → existing → custom.
     */
    function buildConflictPriceChoices(conflict) {
        var seen = {};
        var choices = [];
        function add(opt) {
            if (!opt || opt.p_asignado == null || !(Number(opt.p_asignado) > 0)) return;
            var key = String(Math.round(Number(opt.p_asignado) * 1000) / 1000);
            if (seen[key]) return;
            seen[key] = true;
            choices.push({
                source: opt.source || 'member',
                p_asignado: Number(opt.p_asignado),
                label: opt.label || ('$' + opt.p_asignado),
                producto_base_id: opt.producto_base_id || 0
            });
        }
        var opts = (conflict && conflict.options) || [];
        opts.forEach(function(o) {
            if (o.source === 'candidate') add(o);
        });
        opts.forEach(function(o) {
            if (o.source !== 'candidate') add(o);
        });
        (conflict && conflict.existing_prices || []).forEach(function(p) {
            add({ source: 'group', p_asignado: p, label: 'Precio actual del emparejamiento' });
        });
        return choices;
    }

    function conflictMemberIds(conflict, candidateId) {
        var ids = [];
        var seen = {};
        function push(id) {
            id = parseInt(id, 10) || 0;
            if (!id || seen[id]) return;
            seen[id] = true;
            ids.push(id);
        }
        ((conflict && conflict.members) || []).forEach(function(m) {
            push(m.producto_base_id);
        });
        push(candidateId);
        return ids;
    }

    /**
     * Panel de resolución de conflicto de precios (folios / alta).
     * opts: { emparejamientoId, productoBaseId, conflict, sku, nombre, onDone }
     */
    function openPriceConflict(opts) {
        opts = opts || {};
        ensureStyles();
        closeConflictPanel();

        var empId = parseInt(opts.emparejamientoId, 10) || 0;
        var pid = parseInt(opts.productoBaseId, 10) || 0;
        if (!empId || !pid) {
            window.alert('Datos de conflicto incompletos');
            return;
        }

        function mount(conflict) {
            conflict = conflict || {};
            var choices = buildConflictPriceChoices(conflict);
            var defaultP = choices.length ? choices[0].p_asignado : '';
            var $panel = $(
                '<div class="riverso-emp-conflict-overlay">' +
                '<div class="riverso-emp-conflict-panel">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;">' +
                '<h3 style="margin:0;">Conflicto de precios</h3>' +
                '<button type="button" class="button emp-conflict-close">Cerrar</button>' +
                '</div>' +
                '<p style="margin:0 0 10px;font-size:13px;color:#666;">Elegí el precio compartido para todos los miembros. Podés previsualizar márgenes antes de aplicar.</p>' +
                '<dl class="riverso-emp-conflict-meta" style="display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13px;margin:0 0 12px;">' +
                '<dt style="margin:0;color:#666;">SKU</dt><dd style="margin:0;"><code>' + esc(opts.sku || ('#' + pid)) + '</code></dd>' +
                '<dt style="margin:0;color:#666;">Producto</dt><dd style="margin:0;">' + esc(opts.nombre || '—') + '</dd>' +
                '</dl>' +
                '<div class="emp-conflict-choices"></div>' +
                '<div style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">' +
                '<label style="font-size:12px;">Precio a aplicar (bruto)<br>' +
                '<input type="number" step="0.01" min="0" class="emp-conflict-price-input" style="width:140px;padding:6px;"></label>' +
                '<button type="button" class="button emp-conflict-preview-btn">Vista previa márgenes</button>' +
                '</div>' +
                '<div class="emp-price-preview-box"></div>' +
                '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
                '<button type="button" class="button emp-conflict-cancel">Cancelar</button>' +
                '<button type="button" class="button button-primary emp-conflict-apply">Aplicar y agregar</button>' +
                '</div>' +
                '</div></div>'
            );

            $panel.data('previewViewMode', 'neto');
            $panel.data('previewCostMode', 'tras_dr');
            $panel.data('empId', empId);
            $panel.data('productoBaseId', pid);
            $panel.data('conflict', conflict);
            $panel.data('onDone', typeof opts.onDone === 'function' ? opts.onDone : null);

            var $choices = $panel.find('.emp-conflict-choices');
            var html = '';
            choices.forEach(function(c, i) {
                html += '<label class="riverso-emp-conflict-opt' + (i === 0 ? ' is-selected' : '') + '">' +
                    '<input type="radio" name="emp-conflict-choice" value="' + esc(String(c.p_asignado)) + '"' +
                    (i === 0 ? ' checked' : '') + '> <strong>' + esc(c.label) + '</strong> — ' +
                    money(c.p_asignado) + '</label>';
            });
            html += '<label class="riverso-emp-conflict-opt">' +
                '<input type="radio" name="emp-conflict-choice" value="__custom__"> ' +
                '<strong>Otro precio</strong> <span style="color:#666;font-size:12px;">(escribí abajo)</span></label>';
            $choices.html(html);
            if (defaultP !== '') {
                $panel.find('.emp-conflict-price-input').val(defaultP);
            }

            function selectedPrice() {
                var v = $panel.find('input[name="emp-conflict-choice"]:checked').val();
                if (v === '__custom__') {
                    return parseFloat($panel.find('.emp-conflict-price-input').val(), 10);
                }
                return parseFloat(v, 10);
            }

            function loadPreview() {
                var p = selectedPrice();
                var ids = conflictMemberIds(conflict, pid);
                if (!(p > 0)) {
                    $panel.find('.emp-price-preview-box').html(
                        '<p style="color:#666;">Indicá un precio válido para previsualizar.</p>'
                    );
                    return;
                }
                if (!ids.length) {
                    $panel.find('.emp-price-preview-box').html(
                        '<p style="color:#666;">Sin miembros para previsualizar.</p>'
                    );
                    return;
                }
                $panel.find('.emp-price-preview-box').html('<p style="color:#999;">Calculando…</p>');
                post('riverso_emparejamientos_preview_members', {
                    producto_base_ids: ids,
                    p_asignado: p
                }).done(function(res) {
                    renderPreviewBox($panel, (res.data && res.data.preview) || []);
                }).fail(function() {
                    $panel.find('.emp-price-preview-box').html(
                        '<p style="color:#b32d2e;">Error al calcular vista previa</p>'
                    );
                });
            }

            $panel.on('click', function(e) {
                if (e.target === $panel[0]) {
                    closeConflictPanel();
                    var cb = $panel.data('onDone');
                    if (typeof cb === 'function') cb(false, { cancelled: true });
                }
            });
            $panel.on('click', '.emp-conflict-close, .emp-conflict-cancel', function() {
                closeConflictPanel();
                var cb = $panel.data('onDone');
                if (typeof cb === 'function') cb(false, { cancelled: true });
            });
            $panel.on('change', 'input[name="emp-conflict-choice"]', function() {
                $panel.find('.riverso-emp-conflict-opt').removeClass('is-selected');
                $(this).closest('.riverso-emp-conflict-opt').addClass('is-selected');
                var v = $(this).val();
                if (v !== '__custom__') {
                    $panel.find('.emp-conflict-price-input').val(v);
                } else {
                    $panel.find('.emp-conflict-price-input').focus();
                }
                loadPreview();
            });
            $panel.on('change input', '.emp-conflict-price-input', function() {
                var custom = $panel.find('input[name="emp-conflict-choice"][value="__custom__"]');
                if (custom.length && !custom.is(':checked')) {
                    custom.prop('checked', true);
                    $panel.find('.riverso-emp-conflict-opt').removeClass('is-selected');
                    custom.closest('.riverso-emp-conflict-opt').addClass('is-selected');
                }
                loadPreview();
            });
            $panel.on('click', '.emp-conflict-preview-btn', loadPreview);
            $panel.on('click', '.emp-preview-view-btn', function() {
                $panel.data('previewViewMode', $(this).data('view') || 'neto');
                refreshPreviewBox($panel);
            });
            $panel.on('click', '.emp-preview-cost-btn', function() {
                $panel.data('previewCostMode', $(this).data('cost') || 'tras_dr');
                refreshPreviewBox($panel);
            });
            $panel.on('click', '.emp-origin-hint', function(e) {
                e.preventDefault();
                var url = $(this).attr('data-url') || '';
                var label = $(this).attr('data-label') || $(this).attr('title') || '';
                if (url) {
                    window.open(url, '_blank', 'noopener');
                    return;
                }
                if (label) window.alert(label);
            });
            $panel.on('click', '.emp-conflict-apply', function() {
                var p = selectedPrice();
                if (!(p > 0)) {
                    window.alert('Indicá un precio válido');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Aplicando…');
                $panel.find('.emp-conflict-cancel').prop('disabled', true);
                post('riverso_emparejamientos_resolve_conflict', {
                    emparejamiento_id: empId,
                    producto_base_id: pid,
                    p_asignado: p,
                    confirm: 1
                }).done(function(r) {
                    $btn.prop('disabled', false).text('Aplicar y agregar');
                    $panel.find('.emp-conflict-cancel').prop('disabled', false);
                    if (!r || !r.success) {
                        window.alert((r && r.data && r.data.message) || 'No se pudo aplicar');
                        return;
                    }
                    var cb = $panel.data('onDone');
                    closeConflictPanel();
                    if (typeof cb === 'function') cb(true, r.data || {});
                }).fail(function() {
                    $btn.prop('disabled', false).text('Aplicar y agregar');
                    $panel.find('.emp-conflict-cancel').prop('disabled', false);
                    window.alert('Error de red');
                });
            });

            $('body').append($panel);
            loadPreview();
        }

        if (opts.conflict && (opts.conflict.has_conflict || (opts.conflict.options && opts.conflict.options.length))) {
            mount(opts.conflict);
            return;
        }
        // Sin payload: pedir preview sin confirmar para obtener conflict + preview base.
        post('riverso_emparejamientos_resolve_conflict', {
            emparejamiento_id: empId,
            producto_base_id: pid,
            p_asignado: opts.suggestedPrice || 1,
            confirm: 0
        }).done(function(r) {
            var conflict = (r.data && r.data.conflict) || opts.conflict || {};
            mount(conflict);
        }).fail(function() {
            mount(opts.conflict || {});
        });
    }

    /**
     * Vista previa de márgenes para un emparejamiento.
     * opts: {
     *   title, suggestedPrice, editable, showEdit, onDone(ok, data)
     * }
     * editable=true: input de precio + Aplicar a todos (apply_price confirm=1).
     */
    function openMarginPreview(emparejamientoId, opts) {
        opts = opts || {};
        var empId = parseInt(emparejamientoId, 10) || 0;
        if (!empId) {
            window.alert('Emparejamiento no válido');
            return;
        }
        ensureStyles();
        closeMarginPreviewPanel();
        var editable = !!opts.editable;
        var title = opts.title || (editable ? 'Cambios que se van a realizar' : 'Precio emparejado');
        var suggested = opts.suggestedPrice != null && opts.suggestedPrice !== ''
            ? Number(opts.suggestedPrice) : '';
        var onDone = typeof opts.onDone === 'function' ? opts.onDone : null;
        var doneFired = false;

        function finish(ok, data) {
            if (doneFired) return;
            doneFired = true;
            closeMarginPreviewPanel();
            if (onDone) onDone(!!ok, data || {});
        }

        var editableBlock = editable
            ? '<p style="margin:0 0 10px;font-size:13px;color:#666;">Revisá márgenes de todos los miembros. Podés cambiar el precio antes de aplicar.</p>' +
              '<div style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">' +
              '<label style="font-size:12px;">Precio a aplicar (bruto)<br>' +
              '<input type="number" step="0.01" min="0" class="emp-margin-price-input" style="width:140px;padding:6px;"' +
              (suggested !== '' && !isNaN(suggested) ? ' value="' + esc(String(suggested)) + '"' : '') +
              '></label>' +
              '<button type="button" class="button emp-margin-preview-btn">Vista previa márgenes</button>' +
              '</div>'
            : '';

        var footerBtns = '';
        if (editable) {
            footerBtns =
                '<button type="button" class="button emp-margin-cancel">Cancelar</button>' +
                '<button type="button" class="button button-primary emp-margin-apply">Aplicar a todos</button>';
        } else {
            if (opts.showEdit) {
                footerBtns += '<button type="button" class="button button-primary emp-margin-edit">Editar emparejamiento</button>';
            }
            footerBtns += '<button type="button" class="button emp-margin-close">Cerrar</button>';
        }

        var $panel = $(
            '<div class="riverso-emp-margin-overlay">' +
            '<div class="riverso-emp-margin-panel">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;">' +
            '<h3 style="margin:0;">' + esc(title) + '</h3>' +
            '<button type="button" class="button emp-margin-close">' + (editable ? 'Cancelar' : 'Cerrar') + '</button>' +
            '</div>' +
            '<p class="emp-margin-meta description" style="margin:0 0 10px;"></p>' +
            editableBlock +
            '<div class="emp-price-preview-box"><p style="color:#999;">Cargando…</p></div>' +
            '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
            footerBtns +
            '</div></div></div>'
        );
        $panel.data('previewViewMode', 'neto');
        $panel.data('previewCostMode', 'tras_dr');
        $panel.data('empId', empId);
        $panel.data('memberIds', []);

        function currentPrice() {
            if (editable) {
                return parseFloat($panel.find('.emp-margin-price-input').val(), 10);
            }
            return suggested !== '' && !isNaN(suggested) ? suggested : null;
        }

        function loadPreview() {
            var ids = $panel.data('memberIds') || [];
            if (!ids.length) {
                $panel.find('.emp-price-preview-box').html(
                    '<p style="color:#666;">Este emparejamiento no tiene miembros.</p>'
                );
                return;
            }
            var p = currentPrice();
            var payload = { producto_base_ids: ids };
            if (p != null && p > 0) {
                payload.p_asignado = p;
            } else {
                payload.p_asignado = '';
            }
            $panel.find('.emp-price-preview-box').html('<p style="color:#999;">Calculando…</p>');
            post('riverso_emparejamientos_preview_members', payload).done(function(res) {
                renderPreviewBox($panel, (res.data && res.data.preview) || []);
            }).fail(function() {
                $panel.find('.emp-price-preview-box').html(
                    '<p style="color:#b32d2e;">Error al cargar márgenes</p>'
                );
            });
        }

        $panel.on('click', function(e) {
            if (e.target === $panel[0]) {
                if (editable) finish(false, { cancelled: true });
                else closeMarginPreviewPanel();
            }
        });
        $panel.on('click', '.emp-margin-close, .emp-margin-cancel', function() {
            if (editable) finish(false, { cancelled: true });
            else closeMarginPreviewPanel();
        });
        $panel.on('click', '.emp-margin-edit', function() {
            closeMarginPreviewPanel();
            window.RiversoEmparejamientoEditor.open(empId);
        });
        $panel.on('click', '.emp-preview-view-btn', function() {
            $panel.data('previewViewMode', $(this).data('view') || 'neto');
            refreshPreviewBox($panel);
        });
        $panel.on('click', '.emp-preview-cost-btn', function() {
            $panel.data('previewCostMode', $(this).data('cost') || 'tras_dr');
            refreshPreviewBox($panel);
        });
        $panel.on('click', '.emp-origin-hint', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-url') || '';
            var label = $(this).attr('data-label') || $(this).attr('title') || '';
            if (url) {
                window.open(url, '_blank', 'noopener');
                return;
            }
            if (label) window.alert(label);
        });
        if (editable) {
            $panel.on('click', '.emp-margin-preview-btn', loadPreview);
            $panel.on('change', '.emp-margin-price-input', loadPreview);
            $panel.on('click', '.emp-margin-apply', function() {
                var p = currentPrice();
                if (!(p > 0)) {
                    window.alert('Indicá un precio válido');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Aplicando…');
                $panel.find('.emp-margin-cancel, .emp-margin-close').prop('disabled', true);

                function failApply(msg) {
                    $btn.prop('disabled', false).text('Aplicar a todos');
                    $panel.find('.emp-margin-cancel, .emp-margin-close').prop('disabled', false);
                    window.alert(msg || 'No se pudo aplicar');
                }

                function okApply(data) {
                    $btn.prop('disabled', false).text('Aplicar a todos');
                    $panel.find('.emp-margin-cancel, .emp-margin-close').prop('disabled', false);
                    finish(true, data || { p_asignado: p });
                }

                if (typeof opts.applyAction === 'function') {
                    opts.applyAction(p, function(err, data) {
                        if (err) {
                            failApply(typeof err === 'string' ? err : (err && err.message) || 'No se pudo aplicar');
                            return;
                        }
                        okApply(data);
                    });
                    return;
                }

                post('riverso_emparejamientos_apply_price', {
                    emparejamiento_id: empId,
                    p_asignado: p,
                    confirm: 1
                }).done(function(r) {
                    if (!r || !r.success) {
                        failApply((r && r.data && r.data.message) || 'No se pudo aplicar');
                        return;
                    }
                    okApply(r.data || { p_asignado: p });
                }).fail(function() {
                    failApply('Error de red');
                });
            });
        }

        $('body').append($panel);
        post('riverso_emparejamientos_get', { id: empId }).done(function(r) {
            if (!r.success || !r.data.emparejamiento) {
                $panel.find('.emp-price-preview-box').html(
                    '<p style="color:#b32d2e;">' + esc((r.data && r.data.message) || 'No se pudo cargar') + '</p>'
                );
                return;
            }
            var emp = r.data.emparejamiento;
            $panel.find('.emp-margin-meta').html(
                '<strong>' + esc(emp.nombre || '') + '</strong> <code>' + esc(emp.codigo || '') + '</code>' +
                (Number(emp.emparejar_precios) ? ' · precios' : '') +
                (Number(emp.emparejar_stock) ? ' · stock' : '')
            );
            var ids = (emp.miembros || []).map(function(m) {
                return parseInt(m.producto_base_id, 10);
            }).filter(Boolean);
            $panel.data('memberIds', ids);
            if (!ids.length) {
                $panel.find('.emp-price-preview-box').html(
                    '<p style="color:#666;">Este emparejamiento no tiene miembros.</p>'
                );
                return;
            }
            // Si no hay precio sugerido en modo editable, tomar el del primer miembro con precio.
            if (editable && (!(suggested > 0))) {
                var fromMember = null;
                (emp.miembros || []).forEach(function(m) {
                    if (fromMember == null && m.p_asignado != null && Number(m.p_asignado) > 0) {
                        fromMember = Number(m.p_asignado);
                    }
                });
                if (fromMember != null) {
                    $panel.find('.emp-margin-price-input').val(fromMember);
                }
            }
            loadPreview();
        });
    }

    /**
     * Rellena p_asignado / c_ref de miembros que vienen sin precio (p. ej. alta desde folio).
     */
    function hydrateMembersPrices($modal) {
        var list = getMembers($modal);
        if (!list.length) return;
        var need = list.some(function(m) {
            return m.p_asignado == null || m.c_ref == null;
        });
        if (!need) return;
        post('riverso_emparejamientos_preview_members', {
            producto_base_ids: memberIds($modal),
            p_asignado: ''
        }).done(function(res) {
            var rows = (res.data && res.data.preview) || [];
            if (!rows.length) return;
            var byId = {};
            rows.forEach(function(r) {
                byId[parseInt(r.producto_base_id, 10)] = r;
            });
            var updated = list.map(function(m) {
                var r = byId[parseInt(m.producto_base_id, 10)];
                if (!r) return m;
                var pair = empPickCostPair(r.c_ref_bases, 'tras_dr');
                var c = pair && pair.bruto != null ? pair.bruto
                    : (r.c_ref_bruto != null ? Number(r.c_ref_bruto)
                        : (r.c_ref != null ? Number(r.c_ref) : m.c_ref));
                return $.extend({}, m, {
                    p_asignado: m.p_asignado != null ? m.p_asignado
                        : (r.p_asignado_actual != null ? Number(r.p_asignado_actual) : null),
                    c_ref: m.c_ref != null ? m.c_ref : c
                });
            });
            setMembers($modal, updated);
            refreshMembersUI($modal);
            maybeLoadConflict($modal);
            refreshWarnings($modal);
        });
    }

    function loadList(search, $container) {
        $container = $container || $('#emparejamientos-list');
        if (!$container.length) return;
        $container.html('<p style="color:#999;text-align:center;">Cargando…</p>');
        post('riverso_emparejamientos_list', { search: search || '' }).done(function(r) {
            if (!r.success) {
                $container.html('<p style="color:#b32d2e;">' + esc((r.data && r.data.message) || 'Error') + '</p>');
                return;
            }
            var items = (r.data && r.data.items) || [];
            if (!items.length) {
                $container.html('<p style="color:#999;text-align:center;">Sin emparejamientos</p>');
                return;
            }
            var html = '';
            items.forEach(function(it) {
                var flags = [];
                if (Number(it.emparejar_precios)) flags.push('Precios');
                if (Number(it.emparejar_stock)) flags.push('Stock');
                html += '<div class="emp-row" data-id="' + it.id + '" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:10px 12px;margin-bottom:8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">' +
                    '<div style="flex:1;min-width:200px;">' +
                    '<strong>' + esc(it.nombre) + '</strong> <code style="font-size:11px;">' + esc(it.codigo) + '</code><br>' +
                    '<span style="font-size:12px;color:#555;">' + (it.miembros_count || 0) + ' miembros · ' + flags.join(' + ') + '</span>' +
                    '</div>' +
                    '<button type="button" class="button button-small emp-open" data-id="' + it.id + '">Abrir</button>' +
                    '<button type="button" class="button button-small emp-delete" data-id="' + it.id + '" style="color:#b32d2e;">Eliminar</button>' +
                    '</div>';
            });
            $container.html(html);
        });
    }

    function openCreate(onCreated, createOpts) {
        createOpts = createOpts || {};
        var seedMembers = Array.isArray(createOpts.seedMembers) ? createOpts.seedMembers : [];
        var suggestedNombre = (createOpts.suggestedNombre || '').trim();
        openEditor({
            id: 0,
            nombre: suggestedNombre,
            codigo: '',
            emparejar_precios: 1,
            emparejar_stock: 0,
            stock_minimo: null,
            stock_critico: null,
            precios_usados: 0,
            stock_usados: 0,
            miembros: seedMembers.map(function(m) {
                return {
                    producto_base_id: parseInt(m.producto_base_id, 10) || 0,
                    canonical_sku: m.canonical_sku || '',
                    nombre_canonico: m.nombre_canonico || '',
                    is_unitario: !!m.is_unitario,
                    p_asignado: m.p_asignado != null ? m.p_asignado : null,
                    c_ref: m.c_ref != null ? m.c_ref : null,
                    pending: true
                };
            }).filter(function(m) { return m.producto_base_id > 0; })
        }, {
            isCreate: true,
            onCreated: onCreated,
            nombreTouched: !!suggestedNombre,
            allowSingleMember: !!createOpts.allowSingleMember
        });
    }

    function openEditor(emp, opts) {
        opts = opts || {};
        var isCreate = !!opts.isCreate || !(emp && emp.id);
        ensureStyles();
        closeAll();

        var members = (emp.miembros || []).map(function(m) {
            return {
                producto_base_id: parseInt(m.producto_base_id, 10),
                canonical_sku: m.canonical_sku || '',
                nombre_canonico: m.nombre_canonico || '',
                is_unitario: !!m.is_unitario,
                p_asignado: m.p_asignado,
                c_ref: m.c_ref,
                pending: !!m.pending || isCreate
            };
        });

        var $modal = $(
            '<div class="riverso-emp-modal-overlay">' +
            '<div class="riverso-emp-modal-panel">' +
            '<h3 class="riverso-emp-title" style="margin:0 0 12px;">' +
            (isCreate ? 'Crear emparejamiento' : 'Editar emparejamiento') + '</h3>' +

            '<div class="riverso-emp-warnings"></div>' +

            '<label style="display:block;margin-bottom:10px;"><strong>Nombre</strong><br>' +
            '<input type="text" class="large-text emp-edit-nombre" value="' + esc(emp.nombre || '') + '" ' +
            'placeholder="Ej. Tornillo M6 x 40 (sustitutos)" style="width:100%;padding:6px;box-sizing:border-box;">' +
            '<div class="riverso-emp-name-suggestions"></div></label>' +

            '<label style="display:block;margin-bottom:10px;"><strong>Código</strong> <span style="color:#888;font-weight:normal;">(opcional)</span><br>' +
            '<input type="text" class="large-text emp-edit-codigo" value="' + esc(emp.codigo || '') + '" ' +
            (isCreate ? '' : 'disabled ') +
            'placeholder="Se genera del nombre si lo dejas vacío" style="width:100%;padding:6px;box-sizing:border-box;">' +
            '<small class="emp-codigo-hint" style="color:#888;font-size:12px;"></small></label>' +

            '<div style="display:flex;gap:18px;flex-wrap:wrap;margin:12px 0;">' +
            '<label><input type="checkbox" class="emp-flag-precios"' +
            (Number(emp.emparejar_precios) ? ' checked' : '') + '> <strong>Emparejar precios</strong></label>' +
            '<label><input type="checkbox" class="emp-flag-stock"' +
            (Number(emp.emparejar_stock) ? ' checked' : '') + '> <strong>Emparejar stock</strong></label>' +
            '</div>' +
            '<div class="emp-confirm-disable-wrap" style="display:none;margin:0 0 10px;">' +
            '<label style="font-size:13px;"><input type="checkbox" class="emp-confirm-disable"> ' +
            'Confirmo desactivar un flag que ya se usó (no revierte cambios previos)</label></div>' +

            '<div class="riverso-emp-flag-block emp-block-precios">' +
            '<strong>Precios compartidos</strong>' +
            '<p style="margin:4px 0 8px;font-size:12px;color:#666;">Mismo <code>p_asignado</code> unitario; el costo de cada SKU se mantiene.</p>' +
            '<div class="emp-conflict-options" style="margin-bottom:8px;"></div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">' +
            '<label>Precio a aplicar<br><input type="number" step="0.01" class="emp-price-input" style="width:140px;"></label>' +
            '<button type="button" class="button emp-price-preview-btn" style="align-self:flex-end;">Vista previa márgenes</button>' +
            '</div>' +
            '<div class="emp-price-preview-box"></div>' +
            '</div>' +

            '<div class="riverso-emp-flag-block emp-block-stock">' +
            '<strong>Stock sumado (alertas)</strong>' +
            '<p style="margin:4px 0 8px;font-size:12px;color:#666;">No mezcla inventario propio; solo suma para encargar.</p>' +
            '<div style="display:flex;gap:12px;flex-wrap:wrap;">' +
            '<label>Stock mínimo<br><input type="number" class="emp-stock-min" style="width:100px;" value="' +
            esc(emp.stock_minimo == null ? '' : emp.stock_minimo) + '"></label>' +
            '<label>Stock crítico<br><input type="number" class="emp-stock-crit" style="width:100px;" value="' +
            esc(emp.stock_critico == null ? '' : emp.stock_critico) + '"></label>' +
            '</div>' +
            '<div class="emp-stock-summary" style="margin-top:8px;font-size:13px;color:#555;"></div>' +
            '</div>' +

            '<p style="margin:14px 0 6px;"><strong>Miembros</strong> ' +
            '<span class="emp-member-count" style="color:#666;font-weight:normal;"></span></p>' +
            '<div class="emp-members-wrap"></div>' +

            '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;">' +
            '<strong>Agregar miembro existente</strong>' +
            '<div class="riverso-emp-cand-row" style="margin-top:8px;">' +
            '<input type="search" class="emp-cand-q" placeholder="Barcode, SKU o código proveedor…" ' +
            'autocomplete="off">' +
            '<button type="button" class="button emp-cand-search">Buscar</button>' +
            '<button type="button" class="button emp-cand-advanced" title="Buscador avanzado" ' +
            'aria-label="Buscador avanzado"><span class="dashicons dashicons-search" ' +
            'style="line-height:1.4;margin-top:2px;"></span></button>' +
            '</div>' +
            '<div class="emp-cand-results"></div>' +
            '</div>' +

            '<div class="riverso-emp-create-member">' +
            '<strong style="display:block;margin-bottom:8px;">Crear miembro</strong>' +
            '<p style="margin:0 0 8px;font-size:12px;color:#666;">Crea un SKU local suelto (sin familia) y lo agrega a este emparejamiento.</p>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">' +
            '<label style="font-size:12px;">SKU<br>' +
            '<input type="text" class="emp-new-sku" placeholder="Vacío = siguiente" maxlength="6" inputmode="numeric" ' +
            'style="width:110px;padding:6px;box-sizing:border-box;"> ' +
            '<button type="button" class="button button-small emp-new-sku-gen">Generar SKU</button></label>' +
            '<label style="font-size:12px;flex:1;min-width:180px;">Nombre<br>' +
            '<input type="text" class="emp-new-nombre" style="width:100%;padding:6px;box-sizing:border-box;" ' +
            'placeholder="Se sugiere del emparejamiento"></label>' +
            '<button type="button" class="button button-primary emp-new-submit">Crear miembro</button>' +
            '</div></div>' +

            '<div style="display:flex;gap:8px;justify-content:space-between;margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
            (isCreate
                ? '<span></span>'
                : '<button type="button" class="button emp-delete-current" style="color:#b71c1c;border-color:#e57373;">Eliminar</button>') +
            '<div style="display:flex;gap:8px;">' +
            '<button type="button" class="button emp-modal-close">Cerrar</button>' +
            '<button type="button" class="button button-primary emp-save-all">Guardar todo</button>' +
            '</div></div>' +

            '</div></div>'
        );

        $modal.data('empId', parseInt(emp.id || 0, 10) || 0);
        $modal.data('isCreate', isCreate);
        $modal.data('members', members);
        $modal.data('nombreTouched', !!opts.nombreTouched || (!isCreate && !!(emp.nombre || '').trim()));
        $modal.data('codigoTouched', !isCreate || !!(emp.codigo));
        $modal.data('newNombreTouched', false);
        $modal.data('preciosUsados', !!Number(emp.precios_usados));
        $modal.data('stockUsados', !!Number(emp.stock_usados));
        $modal.data('origPrecios', !!Number(emp.emparejar_precios));
        $modal.data('origStock', !!Number(emp.emparejar_stock));
        $modal.data('allowSingleMember', !!opts.allowSingleMember);
        if (typeof opts.onCreated === 'function') {
            $modal.data('onCreated', opts.onCreated);
        }
        if (emp.stock && emp.stock.stock_unidades != null) {
            $modal.find('.emp-stock-summary').html(
                'Stock sumado actual: <strong>' + money(emp.stock.stock_unidades) + ' u</strong>' +
                (emp.stock.alerta ? ' · <span style="color:#dba617;">Alerta</span>' : '') +
                (emp.stock.critico ? ' · <span style="color:#b32d2e;">Crítico</span>' : '')
            );
        }

        $('body').append($modal);
        bindModal($modal);
        $modal.data('previewViewMode', 'neto');
        $modal.data('previewCostMode', 'tras_dr');
        refreshMembersUI($modal);
        syncFlagBlocks($modal);
        refreshWarnings($modal);
        refreshNameSuggestions($modal);
        suggestNewMemberNombre($modal, true);
        hydrateMembersPrices($modal);

        if (isCreate) {
            $modal.find('.emp-edit-nombre').focus();
        }
    }

    function getMembers($modal) {
        return $modal.data('members') || [];
    }

    function setMembers($modal, list) {
        $modal.data('members', list || []);
    }

    function memberIds($modal) {
        return getMembers($modal).map(function(m) {
            return parseInt(m.producto_base_id, 10);
        }).filter(Boolean);
    }

    function refreshMembersUI($modal) {
        var list = getMembers($modal);
        $modal.find('.emp-member-count').text('(' + list.length + ')');
        if (!list.length) {
            $modal.find('.emp-members-wrap').html(
                '<p style="color:#999;font-size:13px;">Agregá al menos 2 miembros (buscar o crear).</p>'
            );
            return;
        }
        var html = '';
        list.forEach(function(m) {
            html += '<div class="riverso-emp-member-row" data-pid="' + m.producto_base_id + '">' +
                '<div style="flex:1;min-width:180px;">' +
                '<code>' + esc(m.canonical_sku || '') + '</code> ' + esc(m.nombre_canonico || '') +
                (m.is_unitario ? ' <em style="color:#2271b1;">(U)</em>' : '') +
                (m.pending ? ' <span style="color:#666;font-size:11px;">pendiente</span>' : '') +
                '<br><span style="font-size:12px;color:#666;">P ' + money(m.p_asignado) +
                ' · C ' + money(m.c_ref) + '</span>' +
                '</div>' +
                '<button type="button" class="button button-small emp-remove-member" data-pid="' +
                m.producto_base_id + '">Quitar</button>' +
                '</div>';
        });
        $modal.find('.emp-members-wrap').html(html);
    }

    function syncFlagBlocks($modal) {
        var precios = $modal.find('.emp-flag-precios').is(':checked');
        var stock = $modal.find('.emp-flag-stock').is(':checked');
        $modal.find('.emp-block-precios').toggle(precios);
        $modal.find('.emp-block-stock').toggle(stock);

        var needConfirm = false;
        if ($modal.data('preciosUsados') && $modal.data('origPrecios') && !precios) needConfirm = true;
        if ($modal.data('stockUsados') && $modal.data('origStock') && !stock) needConfirm = true;
        $modal.find('.emp-confirm-disable-wrap').toggle(needConfirm);
        if (!needConfirm) {
            $modal.find('.emp-confirm-disable').prop('checked', false);
        }
    }

    function collectWarnings($modal) {
        var errors = [];
        var warnings = [];
        var nombre = ($modal.find('.emp-edit-nombre').val() || '').trim();
        var precios = $modal.find('.emp-flag-precios').is(':checked');
        var stock = $modal.find('.emp-flag-stock').is(':checked');
        var members = getMembers($modal);

        if (!nombre) errors.push('Falta el nombre del emparejamiento.');
        if (!precios && !stock) errors.push('Activá Emparejar precios y/o Emparejar stock.');
        if (members.length < 2) {
            if ($modal.data('allowSingleMember') && members.length >= 1) {
                warnings.push('Idealmente 2+ miembros; podés guardar con 1 y completar después.');
            } else {
                errors.push('Se necesitan al menos 2 miembros.');
            }
        }
        if ($modal.find('.emp-confirm-disable-wrap').is(':visible') &&
            !$modal.find('.emp-confirm-disable').is(':checked')) {
            errors.push('Confirmá desactivar el flag que ya se usó (checkbox abajo de los flags).');
        }

        if (stock) {
            var min = $modal.find('.emp-stock-min').val();
            var crit = $modal.find('.emp-stock-crit').val();
            if (min === '' && crit === '') {
                warnings.push('Stock activo sin umbrales: podés guardar, pero no habrá alerta de grupo.');
            }
            if (min !== '' && crit !== '' && parseInt(crit, 10) > parseInt(min, 10)) {
                errors.push('Stock crítico no puede ser mayor que el mínimo.');
            }
        }

        return { errors: errors, warnings: warnings };
    }

    function refreshWarnings($modal, conflict) {
        var w = collectWarnings($modal);
        if (conflict && conflict.has_conflict) {
            w.errors.push('Hay conflicto de precios: elegí el precio compartido antes de guardar.');
        }
        var $box = $modal.find('.riverso-emp-warnings').empty();
        if (!w.errors.length && !w.warnings.length) return;
        var cls = w.errors.length ? 'riverso-emp-warn is-error' : 'riverso-emp-warn';
        var html = '<div class="' + cls + '"><strong>' +
            (w.errors.length ? 'Antes de guardar' : 'Avisos') + '</strong><ul>';
        w.errors.forEach(function(m) { html += '<li>' + esc(m) + '</li>'; });
        w.warnings.forEach(function(m) { html += '<li>' + esc(m) + '</li>'; });
        html += '</ul></div>';
        $box.html(html);
    }

    function refreshNameSuggestions($modal) {
        var ids = memberIds($modal);
        var $wrap = $modal.find('.riverso-emp-name-suggestions');
        if (!ids.length) {
            $wrap.empty();
            return;
        }
        $wrap.html('<span style="font-size:12px;color:#999;">Sugerencias…</span>');
        post('riverso_emparejamientos_suggest_names', { producto_base_ids: ids }).done(function(r) {
            if (!r.success) {
                $wrap.empty();
                return;
            }
            var items = (r.data && r.data.suggestions) || [];
            if (!items.length) {
                $wrap.empty();
                return;
            }
            var html = '<div style="font-size:12px;color:#666;margin-bottom:4px;">Sugerencias (clic para usar):</div>' +
                '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
            items.forEach(function(it) {
                html += '<button type="button" class="button button-small emp-name-pick" data-name="' +
                    esc(it.label || '') + '" style="max-width:100%;white-space:normal;text-align:left;height:auto;line-height:1.3;padding:4px 8px;">' +
                    esc(it.label || '') + '</button>';
            });
            html += '</div>';
            $wrap.html(html);
        }).fail(function() { $wrap.empty(); });
    }

    function suggestNewMemberNombre($modal, force) {
        if (!force && $modal.data('newNombreTouched')) return;
        var base = ($modal.find('.emp-edit-nombre').val() || '').trim();
        if (!base) {
            var members = getMembers($modal);
            if (members.length) {
                base = members[0].nombre_canonico || '';
            }
        }
        $modal.find('.emp-new-nombre').val(base);
    }

    function addMemberLocal($modal, member) {
        var list = getMembers($modal);
        var pid = parseInt(member.producto_base_id, 10);
        if (!pid) return;
        if (list.some(function(m) { return parseInt(m.producto_base_id, 10) === pid; })) {
            return;
        }
        list.push(member);
        setMembers($modal, list);
        refreshMembersUI($modal);
        refreshNameSuggestions($modal);
        refreshWarnings($modal);
        if (!$modal.data('nombreTouched')) {
            // no auto-fill nombre from first member if empty — chips handle it
        }
        suggestNewMemberNombre($modal, false);
        maybeLoadConflict($modal);
    }

    function maybeLoadConflict($modal) {
        if (!$modal.find('.emp-flag-precios').is(':checked')) {
            $modal.find('.emp-conflict-options').empty();
            return;
        }
        var ids = memberIds($modal);
        if (ids.length < 2) {
            $modal.find('.emp-conflict-options').empty();
            return;
        }
        post('riverso_emparejamientos_preview_members', {
            producto_base_ids: ids
        }).done(function(r) {
            if (!r.success) return;
            var conflict = (r.data && r.data.conflict) || {};
            renderConflictOptions($modal, conflict);
            refreshWarnings($modal, conflict);
            if (!$modal.find('.emp-price-input').val() && conflict.existing_prices && conflict.existing_prices.length === 1) {
                $modal.find('.emp-price-input').val(conflict.existing_prices[0]);
            }
        });
    }

    function renderConflictOptions($modal, conflict) {
        var $wrap = $modal.find('.emp-conflict-options').empty();
        if (!conflict || !conflict.has_conflict) return;
        var opts = conflict.options || [];
        var html = '<div class="riverso-emp-warn"><strong>Conflicto de precios</strong>' +
            '<p style="margin:4px 0;">Elegí el precio que compartirán todos los miembros:</p>';
        opts.forEach(function(o, i) {
            html += '<label style="display:block;margin:4px 0;">' +
                '<input type="radio" name="emp-conflict-price" value="' + esc(String(o.p_asignado)) + '"' +
                (i === 0 ? ' checked' : '') + '> ' +
                esc(o.label) + ' — <strong>' + money(o.p_asignado) + '</strong></label>';
        });
        if (conflict.missing_ids && conflict.missing_ids.length) {
            html += '<p style="margin:6px 0 0;font-size:12px;">Hay miembros sin precio; se les asignará el elegido.</p>';
        }
        html += '</div>';
        $wrap.html(html);
        if (opts.length) {
            $modal.find('.emp-price-input').val(opts[0].p_asignado);
        }
        $wrap.find('input[name="emp-conflict-price"]').on('change', function() {
            $modal.find('.emp-price-input').val($(this).val());
        });
    }

    function candidateAddAttrs(it) {
        return 'data-pid="' + it.id + '" data-sku="' + esc(it.sku) + '" data-nombre="' +
            esc(it.nombre) + '" data-unitario="' + (it.is_unitario ? 1 : 0) +
            '" data-p="' + esc(it.p_asignado == null ? '' : it.p_asignado) +
            '" data-c="' + esc(it.c_ref == null ? '' : it.c_ref) + '"';
    }

    function candidateStatus(it, empId, currentIds) {
        var taken = it.emparejamiento_id && Number(it.emparejamiento_id) !== Number(empId);
        var already = currentIds.indexOf(parseInt(it.id, 10)) >= 0;
        if (taken) {
            return { disabled: true, label: 'Ya en otro emparejamiento' };
        }
        if (already) {
            return { disabled: true, label: 'Ya agregado' };
        }
        return { disabled: false, label: '' };
    }

    function formatCodeHints(it) {
        var parts = [];
        if (it.barcodes && it.barcodes.length) {
            parts.push('BC: ' + it.barcodes.slice(0, 2).join(', '));
        }
        if (it.codigos_proveedor && it.codigos_proveedor.length) {
            parts.push('Prov: ' + it.codigos_proveedor.slice(0, 2).join(', '));
        }
        return parts.length
            ? '<span class="riverso-emp-code-hint">' + esc(parts.join(' · ')) + '</span>'
            : '';
    }

    function renderQuickCandidateList(items, empId, currentIds) {
        if (!items.length) {
            return '<p style="color:#666;">Sin resultados elegibles</p>';
        }
        var html = '<ul style="margin:8px 0;padding-left:18px;">';
        items.forEach(function(it) {
            var st = candidateStatus(it, empId, currentIds);
            html += '<li style="margin-bottom:6px;">' +
                '<code>' + esc(it.sku) + '</code> ' + esc(it.nombre) +
                (it.is_unitario ? ' <em>(unitario)</em>' : '') +
                formatCodeHints(it) +
                (st.disabled
                    ? ' <span style="color:#666;">' + esc(st.label) + '</span>'
                    : ' <button type="button" class="button button-small emp-add-cand" ' +
                      candidateAddAttrs(it) + '>Agregar</button>') +
                '</li>';
        });
        html += '</ul>';
        return html;
    }

    function renderAdvancedGrid(items, empId, currentIds) {
        if (!items.length) {
            return '<p style="color:#666;margin:12px 0;">Sin resultados. Probá otros filtros.</p>';
        }
        var html = '<table class="riverso-emp-adv-grid"><thead><tr>' +
            '<th>SKU</th><th>Nombre</th><th>Barcode</th><th>Cód. proveedor</th><th>Precio</th><th></th>' +
            '</tr></thead><tbody>';
        items.forEach(function(it) {
            var st = candidateStatus(it, empId, currentIds);
            var rowClass = st.disabled ? 'is-disabled' : 'is-selectable';
            html += '<tr class="' + rowClass + '" ' + candidateAddAttrs(it) +
                (st.disabled ? ' data-disabled="1"' : '') + '>' +
                '<td><code>' + esc(it.sku || '') + '</code>' +
                (it.is_unitario ? '<br><em style="font-size:11px;color:#2271b1;">unitario</em>' : '') +
                '</td>' +
                '<td>' + esc(it.nombre || '') + '</td>' +
                '<td style="font-size:12px;">' + esc((it.barcodes || []).slice(0, 3).join(', ') || '—') + '</td>' +
                '<td style="font-size:12px;">' + esc((it.codigos_proveedor || []).slice(0, 3).join(', ') || '—') + '</td>' +
                '<td>' + money(it.p_asignado) + '</td>' +
                '<td>' + (st.disabled
                    ? '<span style="font-size:12px;color:#666;">' + esc(st.label) + '</span>'
                    : '<button type="button" class="button button-small emp-adv-pick">Seleccionar</button>') +
                '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function runQuickCandidateSearch($modal) {
        var q = ($modal.find('.emp-cand-q').val() || '').trim();
        if (q === '' || (q.length < 2 && !/^\d+$/.test(q))) {
            $modal.find('.emp-cand-results').html(
                '<p style="color:#666;">Escribí barcode, SKU o código proveedor (mín. 2 caracteres).</p>'
            );
            return;
        }
        $modal.find('.emp-cand-results').html('<p style="color:#999;">Buscando…</p>');
        post('riverso_emparejamientos_search_candidates', { q: q, mode: 'quick' }).done(function(r) {
            var items = (r.data && r.data.items) || [];
            $modal.find('.emp-cand-results').html(
                renderQuickCandidateList(items, $modal.data('empId') || 0, memberIds($modal))
            );
        }).fail(function() {
            $modal.find('.emp-cand-results').html('<p style="color:#b32d2e;">Error de búsqueda</p>');
        });
    }

    function pickCandidateIntoModal($modal, $el) {
        var $src = $el.is('[data-pid]') ? $el : $el.closest('[data-pid]');
        if (!$src.length || $src.attr('data-disabled') === '1') return false;
        addMemberLocal($modal, {
            producto_base_id: parseInt($src.attr('data-pid'), 10),
            canonical_sku: String($src.attr('data-sku') || ''),
            nombre_canonico: String($src.attr('data-nombre') || ''),
            is_unitario: !!parseInt($src.attr('data-unitario'), 10),
            p_asignado: $src.attr('data-p') !== '' && $src.attr('data-p') != null
                ? parseFloat($src.attr('data-p'), 10) : null,
            c_ref: $src.attr('data-c') !== '' && $src.attr('data-c') != null
                ? parseFloat($src.attr('data-c'), 10) : null,
            pending: true
        });
        return true;
    }

    function openAdvancedSearch($parentModal) {
        ensureStyles();
        closeAdvancedSearch();
        var $adv = $(
            '<div class="riverso-emp-adv-overlay">' +
            '<div class="riverso-emp-adv-panel">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px;">' +
            '<h3 style="margin:0;">Buscador avanzado</h3>' +
            '<button type="button" class="button emp-adv-close">Cerrar</button>' +
            '</div>' +
            '<p style="margin:0 0 8px;font-size:13px;color:#666;">Filtrá por uno o más campos. Enter busca; clic o Seleccionar agrega el miembro.</p>' +
            '<div class="riverso-emp-adv-filters">' +
            '<label>Nombre<input type="search" class="emp-adv-nombre" autocomplete="off"></label>' +
            '<label>SKU<input type="search" class="emp-adv-sku" autocomplete="off"></label>' +
            '<label>Código de barras<input type="search" class="emp-adv-barcode" autocomplete="off"></label>' +
            '<label>Código proveedor<input type="search" class="emp-adv-proveedor" autocomplete="off"></label>' +
            '</div>' +
            '<div style="display:flex;gap:8px;margin-bottom:12px;">' +
            '<button type="button" class="button button-primary emp-adv-search">Buscar</button>' +
            '<button type="button" class="button emp-adv-clear">Limpiar</button>' +
            '</div>' +
            '<div class="emp-adv-results"><p style="color:#999;">Completá al menos un filtro y buscá.</p></div>' +
            '</div></div>'
        );
        $adv.data('parentModal', $parentModal);
        $('body').append($adv);

        function runAdvSearch() {
            var payload = {
                mode: 'advanced',
                nombre: ($adv.find('.emp-adv-nombre').val() || '').trim(),
                sku: ($adv.find('.emp-adv-sku').val() || '').trim(),
                barcode: ($adv.find('.emp-adv-barcode').val() || '').trim(),
                codigo_proveedor: ($adv.find('.emp-adv-proveedor').val() || '').trim(),
                limit: 50
            };
            if (!payload.nombre && !payload.sku && !payload.barcode && !payload.codigo_proveedor) {
                $adv.find('.emp-adv-results').html(
                    '<p style="color:#666;">Indicá al menos un filtro.</p>'
                );
                return;
            }
            $adv.find('.emp-adv-results').html('<p style="color:#999;">Buscando…</p>');
            post('riverso_emparejamientos_search_candidates', payload).done(function(r) {
                var items = (r.data && r.data.items) || [];
                $adv.find('.emp-adv-results').html(
                    renderAdvancedGrid(items, $parentModal.data('empId') || 0, memberIds($parentModal))
                );
            }).fail(function() {
                $adv.find('.emp-adv-results').html('<p style="color:#b32d2e;">Error de búsqueda</p>');
            });
        }

        $adv.on('click', function(e) {
            if (e.target === $adv[0]) closeAdvancedSearch();
        });
        $adv.on('click', '.emp-adv-close', function() { closeAdvancedSearch(); });
        $adv.on('click', '.emp-adv-search', runAdvSearch);
        $adv.on('click', '.emp-adv-clear', function() {
            $adv.find('.emp-adv-nombre, .emp-adv-sku, .emp-adv-barcode, .emp-adv-proveedor').val('');
            $adv.find('.emp-adv-results').html('<p style="color:#999;">Completá al menos un filtro y buscá.</p>');
            $adv.find('.emp-adv-nombre').focus();
        });
        $adv.on('keydown', '.emp-adv-nombre, .emp-adv-sku, .emp-adv-barcode, .emp-adv-proveedor', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                runAdvSearch();
            }
        });
        $adv.on('click', '.emp-adv-pick', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (pickCandidateIntoModal($parentModal, $(this))) {
                var $row = $(this).closest('tr');
                $row.attr('data-disabled', '1').removeClass('is-selectable').addClass('is-disabled');
                $(this).replaceWith('<span style="font-size:12px;color:#666;">Agregado</span>');
            }
        });
        $adv.on('click', 'tr.is-selectable', function(e) {
            if ($(e.target).closest('button').length) return;
            if (pickCandidateIntoModal($parentModal, $(this))) {
                $(this).attr('data-disabled', '1').removeClass('is-selectable').addClass('is-disabled');
                $(this).find('.emp-adv-pick').replaceWith(
                    '<span style="font-size:12px;color:#666;">Agregado</span>'
                );
            }
        });
        $adv.find('.emp-adv-nombre').focus();
    }

    function bindModal($modal) {
        $modal.on('click', function(e) {
            if (e.target === $modal[0]) closeAll();
        });
        $modal.on('click', '.emp-modal-close', function() { closeAll(); });

        $modal.on('change', '.emp-flag-precios, .emp-flag-stock', function() {
            syncFlagBlocks($modal);
            refreshWarnings($modal);
            maybeLoadConflict($modal);
        });
        $modal.on('change', '.emp-confirm-disable', function() {
            refreshWarnings($modal);
        });
        $modal.on('input', '.emp-edit-nombre', function() {
            $modal.data('nombreTouched', true);
            if (!$modal.data('codigoTouched') && $modal.data('isCreate')) {
                var preview = slugifyCodigoPreview($(this).val());
                $modal.find('.emp-edit-codigo').attr('placeholder', preview
                    ? ('Se generará: ' + preview)
                    : 'Se genera del nombre si lo dejas vacío');
                $modal.find('.emp-codigo-hint').text(preview ? ('Vista previa: ' + preview) : '');
            }
            suggestNewMemberNombre($modal, false);
            refreshWarnings($modal);
        });
        $modal.on('input', '.emp-edit-codigo', function() {
            $modal.data('codigoTouched', ($(this).val() || '').trim() !== '');
        });
        $modal.on('input', '.emp-stock-min, .emp-stock-crit', function() {
            refreshWarnings($modal);
        });

        $modal.on('click', '.emp-name-pick', function(ev) {
            ev.preventDefault();
            var name = $(this).attr('data-name') || '';
            if (!name) return;
            $modal.find('.emp-edit-nombre').val(name);
            $modal.data('nombreTouched', true);
            $modal.find('.emp-edit-nombre').trigger('input');
        });

        $modal.on('click', '.emp-remove-member', function() {
            var pid = parseInt($(this).data('pid'), 10);
            setMembers($modal, getMembers($modal).filter(function(m) {
                return parseInt(m.producto_base_id, 10) !== pid;
            }));
            refreshMembersUI($modal);
            refreshNameSuggestions($modal);
            refreshWarnings($modal);
            maybeLoadConflict($modal);
        });

        $modal.on('click', '.emp-cand-search', function() {
            runQuickCandidateSearch($modal);
        });
        $modal.on('keydown', '.emp-cand-q', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                runQuickCandidateSearch($modal);
            }
        });
        $modal.on('click', '.emp-cand-advanced', function() {
            openAdvancedSearch($modal);
        });

        $modal.on('click', '.emp-add-cand', function() {
            if (pickCandidateIntoModal($modal, $(this))) {
                $(this).prop('disabled', true).text('Agregado');
            }
        });

        $modal.on('input', '.emp-new-nombre', function() {
            $modal.data('newNombreTouched', ($(this).val() || '').trim() !== '');
        });

        $modal.on('click', '.emp-new-sku-gen', function() {
            post('riverso_products_next_sku', {}).done(function(r) {
                if (r.success && r.data && r.data.next_sku) {
                    $modal.find('.emp-new-sku').val(String(r.data.next_sku));
                }
            });
        });

        $modal.on('click', '.emp-new-submit', function() {
            var $btn = $(this);
            var sku = ($modal.find('.emp-new-sku').val() || '').trim();
            var nombre = ($modal.find('.emp-new-nombre').val() || '').trim();
            if (!nombre) {
                refreshWarnings($modal);
                $modal.find('.riverso-emp-warnings').prepend(
                    '<div class="riverso-emp-warn is-error">Indicá el nombre del producto nuevo.</div>'
                );
                return;
            }
            if (sku !== '' && !/^\d{1,6}$/.test(sku)) {
                $modal.find('.riverso-emp-warnings').html(
                    '<div class="riverso-emp-warn is-error">SKU Local debe ser numérico y máximo 6 dígitos.</div>'
                );
                return;
            }
            var payload = { nombre: nombre };
            if (sku) payload.canonical_sku = sku;
            // En edición, no agregamos de inmediato: queda pending hasta Guardar todo
            // (mismo criterio que familias en create; en edit también pending para un save atómico).
            $btn.prop('disabled', true).text('Creando…');
            post('riverso_emparejamientos_create_member', payload).done(function(resp) {
                $btn.prop('disabled', false).text('Crear miembro');
                if (!resp.success) {
                    $modal.find('.riverso-emp-warnings').html(
                        '<div class="riverso-emp-warn is-error">' +
                        esc((resp.data && resp.data.message) || 'No se pudo crear') + '</div>'
                    );
                    return;
                }
                var prod = (resp.data && resp.data.product) || {};
                var pid = parseInt((resp.data && resp.data.producto_base_id) || prod.producto_base_id || 0, 10);
                if (!pid) {
                    $modal.find('.riverso-emp-warnings').html(
                        '<div class="riverso-emp-warn is-error">Producto creado sin id.</div>'
                    );
                    return;
                }
                addMemberLocal($modal, {
                    producto_base_id: pid,
                    canonical_sku: prod.canonical_sku || sku,
                    nombre_canonico: prod.nombre_canonico || nombre,
                    is_unitario: false,
                    p_asignado: prod.p_asignado != null ? prod.p_asignado : null,
                    c_ref: null,
                    pending: true
                });
                $modal.data('newNombreTouched', false);
                $modal.find('.emp-new-sku').val('');
                suggestNewMemberNombre($modal, true);
            }).fail(function() {
                $btn.prop('disabled', false).text('Crear miembro');
            });
        });

        $modal.on('click', '.emp-price-preview-btn', function() {
            var p = parseFloat($modal.find('.emp-price-input').val(), 10);
            var ids = memberIds($modal);
            if (!ids.length) {
                $modal.find('.emp-price-preview-box').html(
                    '<p style="color:#666;margin:8px 0;">Agregá miembros para previsualizar.</p>'
                );
                return;
            }
            $modal.find('.emp-price-preview-box').html('<p style="color:#999;">Calculando…</p>');
            post('riverso_emparejamientos_preview_members', {
                producto_base_ids: ids,
                p_asignado: isNaN(p) ? '' : p
            }).done(function(res) {
                renderPreviewBox($modal, (res.data && res.data.preview) || []);
                if (res.data && res.data.conflict) {
                    renderConflictOptions($modal, res.data.conflict);
                    refreshWarnings($modal, res.data.conflict);
                }
            }).fail(function() {
                $modal.find('.emp-price-preview-box').html(
                    '<p style="color:#b32d2e;">Error al calcular vista previa</p>'
                );
            });
        });

        $modal.on('click', '.emp-preview-view-btn', function() {
            var view = $(this).data('view') || 'neto';
            $modal.data('previewViewMode', view);
            refreshPreviewBox($modal);
        });
        $modal.on('click', '.emp-preview-cost-btn', function() {
            var cost = $(this).data('cost') || 'tras_dr';
            $modal.data('previewCostMode', cost);
            refreshPreviewBox($modal);
        });
        $modal.on('click', '.emp-origin-hint', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-url') || '';
            var label = $(this).attr('data-label') || $(this).attr('title') || '';
            if (url) {
                window.open(url, '_blank', 'noopener');
                return;
            }
            if (label) {
                window.alert(label);
            }
        });

        $modal.on('click', '.emp-save-all', function() {
            saveAll($modal);
        });

        $modal.on('click', '.emp-delete-current', function() {
            var id = $modal.data('empId');
            if (!id) return;
            // confirm nativo solo para acción destructiva irreversible
            if (!window.confirm('¿Eliminar este emparejamiento?')) return;
            post('riverso_emparejamientos_delete', { id: id }).done(function() {
                closeAll();
                loadList($('#emparejamientos-search').val());
            });
        });
    }

    function saveAll($modal) {
        var w = collectWarnings($modal);
        if (w.errors.length) {
            refreshWarnings($modal);
            return;
        }
        var ids = memberIds($modal);
        var precios = $modal.find('.emp-flag-precios').is(':checked');
        var $btn = $modal.find('.emp-save-all').prop('disabled', true).text('Guardando…');

        function doSave(pAsignado) {
            var data = {
                id: $modal.data('empId') || 0,
                nombre: ($modal.find('.emp-edit-nombre').val() || '').trim(),
                codigo: ($modal.find('.emp-edit-codigo').val() || '').trim(),
                emparejar_precios: precios ? 1 : 0,
                emparejar_stock: $modal.find('.emp-flag-stock').is(':checked') ? 1 : 0,
                stock_minimo: $modal.find('.emp-stock-min').val(),
                stock_critico: $modal.find('.emp-stock-crit').val(),
                member_ids: ids,
                confirm_disable: $modal.find('.emp-confirm-disable').is(':checked') ? 1 : 0
            };
            if (pAsignado != null && pAsignado > 0) {
                data.p_asignado = pAsignado;
            }
            post('riverso_emparejamientos_save', data).done(function(r) {
                $btn.prop('disabled', false).text('Guardar todo');
                if (!r.success) {
                    if (r.data && r.data.code === 'price_conflict') {
                        renderConflictOptions($modal, (r.data.data) || {});
                        refreshWarnings($modal, r.data.data);
                        return;
                    }
                    if (r.data && (r.data.code === 'confirm_disable_precios' || r.data.code === 'confirm_disable_stock')) {
                        $modal.find('.emp-confirm-disable-wrap').show();
                        refreshWarnings($modal);
                        return;
                    }
                    $modal.find('.riverso-emp-warnings').html(
                        '<div class="riverso-emp-warn is-error">' +
                        esc((r.data && r.data.message) || 'No se pudo guardar') + '</div>'
                    );
                    return;
                }
                var emp = r.data.emparejamiento;
                var onCreated = $modal.data('onCreated');
                closeAll();
                loadList($('#emparejamientos-search').val());
                if (typeof onCreated === 'function') {
                    onCreated(emp);
                } else if (emp && emp.id) {
                    openEditor(emp, { isCreate: false });
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Guardar todo');
            });
        }

        if (!precios) {
            doSave(null);
            return;
        }

        var selected = $modal.find('input[name="emp-conflict-price"]:checked').val();
        var typed = parseFloat($modal.find('.emp-price-input').val(), 10);
        var p = selected ? parseFloat(selected, 10) : typed;

        post('riverso_emparejamientos_preview_members', {
            producto_base_ids: ids,
            p_asignado: (!isNaN(p) && p > 0) ? p : ''
        }).done(function(res) {
            var conflict = (res.data && res.data.conflict) || {};
            if (conflict.has_conflict && (!(p > 0))) {
                renderConflictOptions($modal, conflict);
                refreshWarnings($modal, conflict);
                $btn.prop('disabled', false).text('Guardar todo');
                return;
            }
            if (p > 0) {
                renderPreviewBox(
                    $modal,
                    (res.data && res.data.preview) || [],
                    '<div class="riverso-emp-warn"><strong>Se aplicará este precio a todos los miembros.</strong></div>'
                );
            }
            doSave(p > 0 ? p : null);
        }).fail(function() {
            $btn.prop('disabled', false).text('Guardar todo');
        });
    }

    // API pública (folio / categorías / productos)
    window.RiversoEmparejamientoEditor = {
        open: function(id) {
            post('riverso_emparejamientos_get', { id: id }).done(function(r) {
                if (!r.success || !r.data.emparejamiento) {
                    window.alert((r.data && r.data.message) || 'No se pudo cargar');
                    return;
                }
                openEditor(r.data.emparejamiento, { isCreate: false });
            });
        },
        openCreate: openCreate,
        openPriceConflict: openPriceConflict,
        openMarginPreview: openMarginPreview,
        loadList: loadList,
        close: closeAll,
        answerNeed: function(productoBaseId, decision, cb) {
            post('riverso_emparejamientos_answer_need', {
                producto_base_id: productoBaseId,
                decision: decision
            }).done(function(r) {
                if (typeof cb === 'function') cb(r);
            });
        },
        createAndAssign: function(opts, cb) {
            post('riverso_emparejamientos_create_and_assign', opts || {}).done(function(r) {
                if (typeof cb === 'function') cb(r);
            });
        },
        addMember: function(empId, pid, opts, cb) {
            var data = $.extend({
                emparejamiento_id: empId,
                producto_base_id: pid
            }, opts || {});
            post('riverso_emparejamientos_add_member', data).done(function(r) {
                if (typeof cb === 'function') cb(r);
            });
        },
        list: function(search, cb) {
            post('riverso_emparejamientos_list', { search: search || '' }).done(function(r) {
                if (typeof cb === 'function') cb(r);
            });
        },
        renderPreviewTable: renderPreviewTable
    };

    $(function() {
        if ($('#emparejamientos-list').length) {
            loadList();
            $('#emparejamientos-add-new').on('click', function() {
                openCreate(function() { loadList($('#emparejamientos-search').val()); });
            });
            var t = null;
            $('#emparejamientos-search').on('input', function() {
                var v = $(this).val();
                clearTimeout(t);
                t = setTimeout(function() { loadList(v); }, 280);
            });
            $(document).on('click', '.emp-open', function() {
                window.RiversoEmparejamientoEditor.open($(this).data('id'));
            });
            $(document).on('click', '.emp-delete', function() {
                var id = $(this).data('id');
                if (!window.confirm('¿Eliminar este emparejamiento?')) return;
                post('riverso_emparejamientos_delete', { id: id }).done(function() {
                    loadList($('#emparejamientos-search').val());
                });
            });
        }
    });
})(jQuery);
