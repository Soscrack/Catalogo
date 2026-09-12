/**
 * Riverso Centro de Precios
 * Requiere: jQuery, Chart.js 3.x, riversoPriceHistory
 */
(function ($) {
    'use strict';

    var cfg = window.riversoPriceHistory || {};
    var ajaxUrl = cfg.ajax_url || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = cfg.nonce || '';
    var canManage = !!cfg.can_manage;
    var canApprove = !!cfg.can_approve;
    var canCreateLocal = !!cfg.can_create_local;
    var canLinkSku = !!cfg.can_link_sku;
    var canAnswerFamily = !!cfg.can_answer_family;
    var canManageCompetencia = !!cfg.can_manage_competencia;
    var canViewBarcodes = !!cfg.can_view_barcodes;
    var canAssignBarcodes = !!cfg.can_assign_barcodes;
    var productsAdminUrl = cfg.products_admin_url || '';

    var explorerData = null;
    var chart = null;
    var searchTimer = null;
    var histPage = 1;
    var folioPage = 1;
    var analyzedPage = 1;
    var addProductId = 0;
    var rpfPage = 1;
    var rpfVista = 'activos';
    var rpfSession = null;
    var rpfFacturaId = 0;
    var rpfHybridFacturaId = 0;
    var rpfHybridLines = [];
    /** Contexto del modal Competencia en Procesar folios. */
    var rpfCompCtx = null;
    /** Contexto del modal Barcodes en Procesar folios. */
    var rpfBcCtx = null;
    /** Vista de montos en Buscar: 'bruto' (default) | 'neto' */
    var priceViewMode = 'bruto';
    /** Base de costo en Buscar: 'referencia' | 'tras_dr' (default). */
    var explorerCostMode = 'tras_dr';
    /** Vista de montos en Procesar folios: default neto (base del documento). */
    var rpfViewMode = 'neto';
    /** Base de costo: referencia | tras_dr | tras_dr_flete. Default tras D/R. */
    var rpfCostMode = 'tras_dr';
    /** Borradores de inputs al cambiar Neto/Bruto (item_id → {local, online, margin}). */
    var rpfDraftPrices = {};
    /** Evita ciclo precio ↔ margen. */
    var rpfSyncingMargin = false;
    /** Canal pendiente de confirmar en modal de guardar. */
    var pendingSaveCanal = null;
    /** Estado del modal 3a crear local + vincular. */
    var rpfCreateLocal = null;
    /** Estado del modal buscar producto existente y vincular. */
    var rpfSearchLink = null;
    var rpfSearchLinkTimer = null;
    /** Estado del modal responder familia. */
    var rpfAnswerFamily = null;

    function money(n) {
        if (n === null || n === undefined || n === '' || isNaN(n)) {
            return '—';
        }
        var num = Number(n);
        return '$' + num.toLocaleString('es-CL', { maximumFractionDigits: 4 });
    }

    function rpfRound4(n) {
        if (n === null || n === undefined || n === '' || isNaN(n)) {
            return null;
        }
        return Math.round(Number(n) * 10000) / 10000;
    }

    function rpfIsExento(ivaTipo) {
        return String(ivaTipo || 'afecto').toLowerCase() === 'exento';
    }

    /** Precio: base bruto → valor según modo de vista. */
    function rpfPriceDisplay(bruto, ivaTipo) {
        if (bruto === null || bruto === undefined || bruto === '' || isNaN(bruto)) {
            return null;
        }
        if (rpfViewMode === 'bruto' || rpfIsExento(ivaTipo)) {
            return rpfRound4(bruto);
        }
        return rpfRound4(Number(bruto) / 1.19);
    }

    /** Costo: base neto → valor según modo de vista. */
    function rpfCostDisplay(neto, ivaTipo) {
        if (neto === null || neto === undefined || neto === '' || isNaN(neto)) {
            return null;
        }
        if (rpfViewMode === 'neto' || rpfIsExento(ivaTipo)) {
            return rpfRound4(neto);
        }
        return rpfRound4(Number(neto) * 1.19);
    }

    /** Margen absoluto (base neto en backend) según modo. */
    function rpfMarginDisplay(margenNeto, ivaTipo) {
        if (margenNeto === null || margenNeto === undefined || margenNeto === '' || isNaN(margenNeto)) {
            return null;
        }
        if (rpfViewMode === 'neto' || rpfIsExento(ivaTipo)) {
            return rpfRound4(margenNeto);
        }
        return rpfRound4(Number(margenNeto) * 1.19);
    }

    /** Delta de costo (base neto) o de precio (base bruto) según kind. */
    function rpfDeltaDisplay(delta, kind, ivaTipo) {
        if (delta === null || delta === undefined || delta === '' || isNaN(delta)) {
            return null;
        }
        if (rpfIsExento(ivaTipo)) {
            return rpfRound4(delta);
        }
        if (kind === 'cost') {
            return rpfViewMode === 'bruto' ? rpfRound4(Number(delta) * 1.19) : rpfRound4(delta);
        }
        // price delta: base bruto
        return rpfViewMode === 'neto' ? rpfRound4(Number(delta) / 1.19) : rpfRound4(delta);
    }

    function rpfConvertTypedAmount(val, fromMode, toMode, ivaTipo) {
        if (val === null || val === undefined || val === '' || isNaN(val)) {
            return val;
        }
        if (fromMode === toMode || rpfIsExento(ivaTipo)) {
            return rpfRound4(val);
        }
        if (fromMode === 'neto' && toMode === 'bruto') {
            return rpfRound4(Number(val) * 1.19);
        }
        if (fromMode === 'bruto' && toMode === 'neto') {
            return rpfRound4(Number(val) / 1.19);
        }
        return rpfRound4(val);
    }

    function rpfAmountSuffix() {
        return rpfViewMode === 'neto' ? '(neto)' : '(bruto)';
    }

    function ensureRpfViewToggle() {
        if ($('#rpf-view-toggle').length) {
            return;
        }
        $('#rpf-refresh-session').after(
            '<span id="rpf-view-toggle" class="rpe-view-toggle rpf-view-toggle" role="group" aria-label="Vista de montos del folio" style="margin-left:10px;">' +
            '<button type="button" class="button rpe-view-btn rpf-view-btn" data-view="neto">Neto</button>' +
            '<button type="button" class="button rpe-view-btn rpf-view-btn" data-view="bruto">Bruto</button>' +
            '</span>' +
            '<span id="rpf-cost-toggle" class="rpe-view-toggle rpf-cost-toggle" role="group" aria-label="Base de costo" style="margin-left:10px;">' +
            '<button type="button" class="button rpf-cost-btn" data-cost="referencia" title="Precio lista / costo antes de D/R">Costo referencia</button>' +
            '<button type="button" class="button rpf-cost-btn" data-cost="tras_dr" title="Tras descuento y recargo">Costo tras Descuento/Recargo</button>' +
            '<button type="button" class="button rpf-cost-btn" data-cost="tras_dr_flete" title="Tras D/R más flete">Costo tras D/R + flete</button>' +
            '<button type="button" class="button" id="rpf-btn-flete" title="Ver y asignar flete del folio">Flete</button>' +
            '</span>'
        );
    }

    function rpfInvoiceFleteOk() {
        return !!(rpfSession && rpfSession.invoice && rpfSession.invoice.flete_ok);
    }

    function updateRpfViewToggle() {
        ensureRpfViewToggle();
        $('#rpf-view-toggle .rpf-view-btn').removeClass('is-active');
        $('#rpf-view-toggle .rpf-view-btn[data-view="' + rpfViewMode + '"]').addClass('is-active');
        var fleteOk = rpfInvoiceFleteOk();
        var $drf = $('#rpf-cost-toggle .rpf-cost-btn[data-cost="tras_dr_flete"]');
        $drf.prop('disabled', !fleteOk)
            .attr('title', fleteOk
                ? 'Tras D/R más flete'
                : 'Resuelva el flete del folio (botón Flete) antes de usar esta vista');
        if (!fleteOk && rpfCostMode === 'tras_dr_flete') {
            rpfCostMode = 'tras_dr';
        }
        $('#rpf-cost-toggle .rpf-cost-btn').removeClass('is-active');
        $('#rpf-cost-toggle .rpf-cost-btn[data-cost="' + rpfCostMode + '"]').addClass('is-active');
    }

    function rpfPickCostBase(bases, mode, fallback) {
        if (!bases || typeof bases !== 'object') {
            return fallback != null && fallback !== '' && !isNaN(fallback) ? Number(fallback) : null;
        }
        var key = mode === 'referencia' ? 'referencia'
            : (mode === 'tras_dr_flete' ? 'tras_dr_flete' : 'tras_dr');
        var v = bases[key];
        if (v === null || v === undefined || v === '' || isNaN(v)) {
            if (mode !== 'tras_dr_flete' && fallback != null && fallback !== '' && !isNaN(fallback)) {
                return Number(fallback);
            }
            return null;
        }
        return Number(v);
    }

    function rpfLineCostFolio(ln) {
        return rpfPickCostBase(ln.costo_folio_bases, rpfCostMode, ln.costo_folio);
    }

    function rpfLineCostAnterior(ln) {
        return rpfPickCostBase(ln.costo_anterior_bases, rpfCostMode, ln.costo_anterior);
    }

    function rpfInvoiceAdminUrl(fid) {
        fid = parseInt(fid, 10) || 0;
        if (fid <= 0) {
            return '';
        }
        if (rpfSession && rpfSession.invoice && Number(rpfSession.invoice.id) === fid && rpfSession.invoice.url_factura) {
            return rpfSession.invoice.url_factura;
        }
        var base = cfg.admin_url || '';
        if (!base && productsAdminUrl) {
            base = productsAdminUrl.replace(/admin\.php.*$/, '');
        }
        if (!base) {
            return '';
        }
        return base.replace(/\/?$/, '/') + 'admin.php?page=riverso-pos-invoices&factura=' + fid;
    }

    function rpfFleteWarningHtml(ln, which) {
        if (rpfCostMode !== 'tras_dr_flete') {
            return '';
        }
        var ok = which === 'folio' ? !!ln.flete_ok : ln.prior_flete_ok;
        if (ok === true) {
            return '';
        }
        if (which === 'anterior' && (ok === null || ok === undefined)) {
            return '';
        }
        var fid = which === 'folio'
            ? (rpfFacturaId || (rpfSession && rpfSession.invoice && rpfSession.invoice.id) || 0)
            : (ln.prior_factura_id || (ln.costo_anterior_origen && ln.costo_anterior_origen.factura_id) || 0);
        var url = rpfInvoiceAdminUrl(fid);
        var label = which === 'folio' ? 'Sin flete en folio actual' : 'Sin flete en folio anterior';
        var html = '<div class="rpf-chip rpf-chip-flete-warn" title="' + esc(label) + '">' + esc(label);
        if (which === 'folio') {
            html += ' <button type="button" class="button-link rpf-open-flete-modal">Asignar</button>';
        }
        if (url) {
            html += ' <a href="' + esc(url) + '" target="_blank" rel="noopener">Abrir folio</a>';
        }
        html += '</div>';
        return html;
    }

    function switchRpfViewMode(newMode) {
        if (newMode !== 'neto' && newMode !== 'bruto') {
            return;
        }
        if (newMode === rpfViewMode) {
            updateRpfViewToggle();
            return;
        }
        var drafts = {};
        $('#rpf-lines tr[data-item]').each(function () {
            var id = $(this).data('item');
            var iva = $(this).attr('data-iva') || 'afecto';
            var $local = $(this).find('.rpf-p-local');
            var $online = $(this).find('.rpf-p-online');
            var $margin = $(this).find('.rpf-m-act');
            var $factor = $(this).find('.rpf-f-act');
            if ($local.length) {
                drafts[id] = drafts[id] || {};
                drafts[id].local = rpfConvertTypedAmount($local.val(), rpfViewMode, newMode, iva);
            }
            if ($online.length) {
                drafts[id] = drafts[id] || {};
                drafts[id].online = rpfConvertTypedAmount($online.val(), rpfViewMode, newMode, iva);
            }
            if ($margin.length) {
                drafts[id] = drafts[id] || {};
                // Margen act. siempre en neto: no convertir.
                drafts[id].margin = $margin.val();
            }
            if ($factor.length) {
                drafts[id] = drafts[id] || {};
                // Factor P/C es adimensional: no convertir.
                drafts[id].factor = $factor.val();
            }
        });
        rpfViewMode = newMode;
        rpfDraftPrices = drafts;
        updateRpfViewToggle();
        if (rpfSession) {
            renderProcessLines(rpfSession.lines || [], !!rpfSession.is_hybrid);
        }
        rpfDraftPrices = {};
        if (rpfCompCtx && rpfCompCtx.lastData && $('#rpf-comp-modal').is(':visible')) {
            renderRpfCompetenciaTables(rpfCompCtx.lastData);
        }
    }

    function rpfCostNetoFromRow($row) {
        var c = $row.attr('data-costo-neto');
        if (c === '' || c == null || isNaN(c)) {
            return null;
        }
        return Number(c);
    }

    function rpfPriceDisplayToNeto(displayPrice, ivaTipo) {
        if (displayPrice === null || displayPrice === undefined || displayPrice === '' || isNaN(displayPrice)) {
            return null;
        }
        if (rpfViewMode === 'neto' || rpfIsExento(ivaTipo)) {
            return rpfRound4(displayPrice);
        }
        return rpfRound4(Number(displayPrice) / 1.19);
    }

    function rpfNetoToPriceDisplay(neto, ivaTipo) {
        if (neto === null || neto === undefined || neto === '' || isNaN(neto)) {
            return null;
        }
        if (rpfViewMode === 'neto' || rpfIsExento(ivaTipo)) {
            return rpfRound4(neto);
        }
        return rpfRound4(Number(neto) * 1.19);
    }

    function rpfUpdateMarginPct($row, margenNeto, costoNeto) {
        var $pct = $row.find('.rpf-m-pct');
        if (!$pct.length) {
            return;
        }
        if (margenNeto == null || isNaN(margenNeto) || costoNeto == null || Number(costoNeto) === 0) {
            $pct.text('');
            return;
        }
        var pct = Math.round((Number(margenNeto) / Number(costoNeto)) * 1000) / 10;
        $pct.text('(' + pct + '%)');
    }

    function rpfFactorFromNeto(pNeto, costoNeto) {
        if (pNeto == null || isNaN(pNeto) || costoNeto == null || isNaN(costoNeto) || Number(costoNeto) === 0) {
            return null;
        }
        return rpfRound4(Number(pNeto) / Number(costoNeto));
    }

    function rpfFmtFactor(f) {
        if (f === null || f === undefined || f === '' || isNaN(f)) {
            return '—';
        }
        return Number(f).toFixed(4) + '×';
    }

    /** Aplica P neto a inputs de precio, margen y factor (misma fila). */
    function rpfApplyNetoPrice($row, pNeto, opts) {
        opts = opts || {};
        var c = rpfCostNetoFromRow($row);
        var iva = $row.attr('data-iva') || 'afecto';
        var $p = $row.find('.rpf-p-local');
        var $m = $row.find('.rpf-m-act');
        var $f = $row.find('.rpf-f-act');
        var $online = $row.find('.rpf-p-online');

        if (!opts.skipPrice && $p.length) {
            var pDisp = rpfNetoToPriceDisplay(pNeto, iva);
            $p.val(pDisp != null ? pDisp : '');
            if ($online.length) {
                $online.val(pDisp != null ? pDisp : '');
            }
        }
        if (c != null) {
            var m = rpfRound4(Number(pNeto) - c);
            if (!opts.skipMargin && $m.length && !$m.prop('disabled')) {
                $m.val(m != null ? m : '');
                rpfUpdateMarginPct($row, m, c);
            }
            var f = rpfFactorFromNeto(pNeto, c);
            if (!opts.skipFactor && $f.length && !$f.prop('disabled')) {
                $f.val(f != null ? f : '');
            }
        }
    }

    function rpfSyncFromPrice($row) {
        if (rpfSyncingMargin) {
            return;
        }
        var c = rpfCostNetoFromRow($row);
        var iva = $row.attr('data-iva') || 'afecto';
        var pVal = $row.find('.rpf-p-local').val();
        if (pVal === '' || pVal == null || isNaN(pVal)) {
            return;
        }
        rpfSyncingMargin = true;
        var pNeto = rpfPriceDisplayToNeto(pVal, iva);
        rpfApplyNetoPrice($row, pNeto, { skipPrice: true });
        rpfSyncingMargin = false;
    }

    function rpfSyncFromMargin($row) {
        if (rpfSyncingMargin) {
            return;
        }
        var $m = $row.find('.rpf-m-act');
        if (!$m.length || $m.prop('disabled')) {
            return;
        }
        var c = rpfCostNetoFromRow($row);
        if (c == null) {
            return;
        }
        var mVal = $m.val();
        if (mVal === '' || mVal == null || isNaN(mVal)) {
            return;
        }
        rpfSyncingMargin = true;
        var pNeto = rpfRound4(c + Number(mVal));
        rpfApplyNetoPrice($row, pNeto, { skipMargin: true });
        rpfSyncingMargin = false;
    }

    function rpfSyncFromFactor($row) {
        if (rpfSyncingMargin) {
            return;
        }
        var $f = $row.find('.rpf-f-act');
        if (!$f.length || $f.prop('disabled')) {
            return;
        }
        var c = rpfCostNetoFromRow($row);
        if (c == null) {
            return;
        }
        var fVal = $f.val();
        if (fVal === '' || fVal == null || isNaN(fVal) || Number(fVal) <= 0) {
            return;
        }
        rpfSyncingMargin = true;
        var pNeto = rpfRound4(Number(fVal) * c);
        rpfApplyNetoPrice($row, pNeto, { skipFactor: true });
        rpfSyncingMargin = false;
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    function originLabel(origin) {
        if (!origin) {
            return '—';
        }
        if (typeof origin === 'string') {
            return origin || '—';
        }
        return origin.label || '—';
    }

    function originKey(origin) {
        if (!origin || typeof origin === 'string') {
            return '';
        }
        return origin.key || '';
    }

    function originBadgeHtml(origin) {
        var label = originLabel(origin);
        if (!label || label === '—') {
            return '';
        }
        return '<span class="rpe-origin-badge" data-key="' + esc(originKey(origin)) + '">' + esc(label) + '</span>';
    }

    function setOriginBadge($el, origin) {
        var label = originLabel(origin);
        var key = originKey(origin);
        if (!label || label === '—') {
            $el.text('').attr('data-key', '').hide();
            return;
        }
        $el.text(label).attr('data-key', key).show();
    }

    function pickExplorerCostPair(basesOrRow, mode) {
        mode = mode || explorerCostMode;
        var bases = null;
        if (basesOrRow && basesOrRow.c_ref_bases) {
            bases = basesOrRow.c_ref_bases;
        } else if (basesOrRow && (basesOrRow.referencia || basesOrRow.tras_dr)) {
            bases = basesOrRow;
        }
        if (!bases) {
            return null;
        }
        var key = mode === 'referencia' ? 'referencia' : 'tras_dr';
        var pair = bases[key];
        if (!pair && key === 'referencia') {
            pair = bases.tras_dr;
        }
        if (!pair || typeof pair !== 'object') {
            return null;
        }
        return pair;
    }

    function pickExplorerCostNetoBruto(row) {
        var pair = pickExplorerCostPair(row);
        if (pair) {
            return {
                neto: pair.neto != null ? Number(pair.neto) : null,
                bruto: pair.bruto != null ? Number(pair.bruto) : null
            };
        }
        var bruto = row && (row.c_ref_bruto != null ? row.c_ref_bruto : row.c_ref);
        var neto = row && row.c_ref_neto;
        if (neto == null && bruto != null) {
            var iva = (row.iva_tipo || (explorerData && explorerData.product && explorerData.product.facto_iva_tipo) || 'afecto');
            neto = String(iva).toLowerCase() === 'exento'
                ? Number(bruto)
                : Math.round((Number(bruto) / 1.19) * 10000) / 10000;
        }
        return {
            neto: neto != null ? Number(neto) : null,
            bruto: bruto != null ? Number(bruto) : null
        };
    }

    function updateViewHints() {
        var isBruto = priceViewMode === 'bruto';
        $('.rpe-view-hint[data-hint-for="costo"]').attr('data-label', isBruto ? '(bruto)' : '(neto)');
        $('.rpe-view-hint[data-hint-for="pref"]').attr('data-label', isBruto ? '(bruto)' : '(neto)');
        $('.rpe-view-hint[data-hint-for="precio"]').attr('data-label', isBruto ? '(bruto)' : '(neto)');
        var $explorerBtns = $('.rpe-view-toggle').not('#rpf-view-toggle, #rpf-cost-toggle').find('.rpe-view-btn');
        $explorerBtns.removeClass('is-active');
        $explorerBtns.filter('[data-view="' + priceViewMode + '"]').addClass('is-active');
        $('.rpe-cost-toggle .rpe-cost-btn').removeClass('is-active');
        $('.rpe-cost-toggle .rpe-cost-btn[data-cost="' + explorerCostMode + '"]').addClass('is-active');
    }

    function fillDualField(prefix, field, neto, bruto) {
        var primary = priceViewMode === 'bruto' ? bruto : neto;
        var secondary = priceViewMode === 'bruto' ? neto : bruto;
        var secLabel = priceViewMode === 'bruto' ? 'neto ' : 'bruto ';
        $('#' + prefix + '-' + field).text(money(primary));
        var $alt = $('#' + prefix + '-' + field + '-alt');
        if (secondary != null && secondary !== '' && !isNaN(Number(secondary)) && Number(secondary) !== Number(primary)) {
            $alt.text('(' + secLabel + money(secondary) + ')').show();
        } else if (secondary != null && !isNaN(Number(secondary))) {
            $alt.text('(' + secLabel + money(secondary) + ')').show();
        } else {
            $alt.text('').hide();
        }
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = nonce;
        return $.post(ajaxUrl, data);
    }

    function switchTab(tab) {
        $('.riverso-price-history-app .nav-tab').removeClass('nav-tab-active');
        $('.riverso-price-history-app .nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $('.riverso-price-history-app .tab-content').hide();
        $('#tab-' + tab).show();
        if (tab === 'process') {
            loadProcessList();
        }
        if (tab === 'history') {
            loadHistory();
        }
        if (tab === 'analysis') {
            loadRecentFolios();
        }
        if (tab === 'folios') {
            loadAnalyzed();
        }
        if (tab === 'alerts') {
            loadAlerts();
        }
    }

    /* ===== Procesar folios ===== */

    function deltaClass(d) {
        if (d === null || d === undefined || isNaN(d) || Number(d) === 0) {
            return 'rpf-flat';
        }
        return Number(d) > 0 ? 'rpf-up' : 'rpf-down';
    }

    function fmtDelta(d, pct) {
        if (d === null || d === undefined || isNaN(d)) {
            return '—';
        }
        var sign = Number(d) > 0 ? '+' : '';
        var s = sign + money(d);
        if (pct !== null && pct !== undefined && !isNaN(pct)) {
            s += ' (' + sign + Number(pct).toFixed(1) + '%)';
        }
        return s;
    }

    /** Hint (?) de origen para Costo ant. / P ant. */
    function rpfPriorOriginHint(origen, kindLabel) {
        origen = origen || {};
        var label = origen.label || '';
        if (!label || label === '—') {
            return '';
        }
        var title = (kindLabel ? kindLabel + ': ' : '') + label;
        var url = origen.url || '';
        var folio = origen.folio || '';
        return ' <button type="button" class="rpf-prior-origin" title="' + esc(title) + '"' +
            (url ? ' data-url="' + esc(url) + '"' : '') +
            (folio ? ' data-folio="' + esc(folio) + '"' : '') +
            ' data-label="' + esc(label) + '"' +
            ' aria-label="' + esc(title) + '">?</button>';
    }

    function estadoBadge(estado, label) {
        return '<span class="rpf-badge rpf-badge-' + esc(estado) + '">' + esc(label || estado) + '</span>';
    }

    function progressBadge(saved, total, status) {
        var s = parseInt(saved, 10) || 0;
        var t = parseInt(total, 10) || 0;
        var st = status || 'ninguno';
        if (t <= 0) {
            st = 'ninguno';
        } else if (s <= 0) {
            st = 'ninguno';
        } else if (s >= t) {
            st = 'completo';
        } else {
            st = 'parcial';
        }
        var labels = { ninguno: 'Ninguno', parcial: 'Parcial', completo: 'Completo' };
        return '<span class="rpf-progress" title="Confirmados desde este folio">' +
            '<code>' + s + '/' + t + '</code> ' +
            '<span class="rpf-progress-badge rpf-progress-' + esc(st) + '">' + esc(labels[st] || st) + '</span>' +
            '</span>';
    }

    function tipoChip(tipo) {
        var map = {
            sku: 'SKU',
            familia: 'Familia',
            familia_task: 'Tarea familia',
            factura: 'Factura'
        };
        var cls = 'rpf-tipo rpf-tipo-' + esc(tipo || 'otro');
        return '<span class="' + cls + '">' + esc(map[tipo] || tipo || '') + '</span>';
    }

    function renderBlockers(blockers) {
        if (!blockers || !blockers.length) {
            $('#rpf-blockers').hide().empty();
            return;
        }
        var wasOpen = $('#rpf-blockers-help').prop('open') === true;
        var n = blockers.length;
        var html = '<div class="notice notice-error inline rpf-blockers-notice">' +
            '<details class="rpf-blockers-help" id="rpf-blockers-help">' +
            '<summary class="rpf-blockers-help-summary">' +
            '<span class="dashicons dashicons-warning" aria-hidden="true"></span>' +
            '<span class="rpf-blockers-help-title">Hay que resolver esto antes de procesar precios</span>' +
            '<span class="rpf-blockers-count">' + n + '</span>' +
            '<span class="rpf-blockers-help-hint"></span>' +
            '</summary>' +
            '<div class="rpf-blockers-help-body">' +
            '<p>Seguí los pasos en orden. Cuando termines, pulsá <em>Ya resolví — actualizar</em>.</p>' +
            '<div class="rpf-blockers-cards">';
        blockers.forEach(function (b, idx) {
            html += '<div class="rpf-blocker-card" data-tipo="' + esc(b.tipo || '') + '"' +
                ' data-item="' + esc(b.item_id || '') + '"' +
                ' data-codigo="' + esc(b.codigo_proveedor || '') + '"' +
                ' data-nombre="' + esc(b.nombre || '') + '"' +
                ' data-producto="' + esc(b.producto_base_id || '') + '"' +
                ' data-sku="' + esc(b.sku || '') + '">';
            html += '<div class="rpf-blocker-head">' + tipoChip(b.tipo) +
                ' <strong>' + esc(b.message || 'Bloqueo') + '</strong>';
            if (b.codigo_proveedor) {
                html += ' <code>' + esc(b.codigo_proveedor) + '</code>';
            }
            if (b.sku) {
                html += ' <code>SKU ' + esc(b.sku) + '</code>';
            }
            html += '</div>';
            if (b.pasos && b.pasos.length) {
                html += '<ol class="rpf-blocker-pasos">';
                b.pasos.forEach(function (p) {
                    html += '<li>';
                    if (p.action === 'search_link' && canLinkSku) {
                        html += '<button type="button" class="button button-small button-primary rpf-search-link-open"' +
                            ' data-item="' + esc(b.item_id || '') + '"' +
                            ' data-codigo="' + esc(b.codigo_proveedor || '') + '"' +
                            ' data-nombre="' + esc(b.nombre || '') + '">' +
                            esc(p.label || 'Buscar') + '</button>';
                        if (p.url) {
                            html += ' <a class="button button-small" href="' + esc(p.url) + '" target="_blank" rel="noopener">Hub</a>';
                        }
                    } else if (p.action === 'create_local' && canCreateLocal) {
                        html += '<button type="button" class="button button-small button-primary rpf-create-local-open"' +
                            ' data-item="' + esc(b.item_id || '') + '"' +
                            ' data-codigo="' + esc(b.codigo_proveedor || '') + '"' +
                            ' data-nombre="' + esc(b.nombre || '') + '">' +
                            esc(p.label || 'Nuevo producto local') + '</button>';
                    } else if (p.action === 'answer_family' && canAnswerFamily) {
                        html += '<button type="button" class="button button-small button-primary rpf-answer-family-open"' +
                            ' data-producto="' + esc(b.producto_base_id || '') + '"' +
                            ' data-sku="' + esc(b.sku || '') + '"' +
                            ' data-nombre="' + esc(b.nombre || '') + '">' +
                            esc(p.label || 'Responder aquí') + '</button>';
                    } else if (p.url) {
                        html += '<a class="button button-small" href="' + esc(p.url) + '" target="_blank" rel="noopener">' +
                            esc(p.label || 'Abrir') + '</a>';
                    } else {
                        html += '<strong>' + esc(p.label || '') + '</strong>';
                    }
                    if (p.hint) {
                        html += '<span class="description rpf-paso-hint">' + esc(p.hint) + '</span>';
                    }
                    html += '</li>';
                });
                html += '</ol>';
            } else if (b.url) {
                html += '<p><a class="button button-small" href="' + esc(b.url) + '" target="_blank" rel="noopener">Abrir</a></p>';
            }
            html += '</div>';
        });
        html += '</div></div></details></div>';
        $('#rpf-blockers').html(html).show();
        if (wasOpen) {
            $('#rpf-blockers-help').attr('open', 'open');
        }
    }

    function openFolioSession(id) {
        return post('riverso_price_folio_process_get', { factura_id: id }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo abrir');
                return;
            }
            showSession(res.data);
        });
    }

    function refreshSession() {
        if (!rpfFacturaId) {
            loadProcessList();
            return;
        }
        $('#rpf-refresh-session').prop('disabled', true).text('Actualizando…');
        openFolioSession(rpfFacturaId).always(function () {
            $('#rpf-refresh-session').prop('disabled', false).text('Ya resolví — actualizar');
        });
    }

    function loadProcessList() {
        var $body = $('#rpf-tbody').html('<tr><td colspan="9">Cargando…</td></tr>');
        var orderRaw = String($('#rpf-filter-order').val() || 'fecha_folio|DESC').split('|');
        var orderby = orderRaw[0] || 'fecha_folio';
        var order = orderRaw[1] || 'DESC';
        post('riverso_price_folio_process_list', {
            page: rpfPage,
            vista: rpfVista || 'activos',
            estado: $('#rpf-filter-estado').val() || '',
            completitud: $('#rpf-filter-completitud').val() || '',
            search: $('#rpf-filter-search').val() || '',
            producto: $('#rpf-filter-producto').val() || '',
            fecha_folio_desde: $('#rpf-filter-folio-desde').val() || '',
            fecha_folio_hasta: $('#rpf-filter-folio-hasta').val() || '',
            fecha_ingreso_desde: $('#rpf-filter-ingreso-desde').val() || '',
            fecha_ingreso_hasta: $('#rpf-filter-ingreso-hasta').val() || '',
            orderby: orderby,
            order: order
        }).done(function (res) {
            if (!res || !res.success) {
                $body.html('<tr><td colspan="9">Error al cargar</td></tr>');
                return;
            }
            var d = res.data || {};
            var counts = d.counts || {};
            var labels = d.labels || {};
            var countHtml = Object.keys(labels).map(function (k) {
                return '<span class="rpf-count-chip">' + esc(labels[k]) + ': <strong>' + (counts[k] || 0) + '</strong></span>';
            }).join(' ');
            $('#rpf-counts').html(countHtml);
            var archivedCount = parseInt(d.archived_count, 10) || 0;
            if (rpfVista === 'activos') {
                $('#rpf-archived-chip').text('Archivados: ' + archivedCount).show();
            } else {
                $('#rpf-archived-chip').hide();
            }
            $('#rpf-page-label').text('Página ' + (d.page || 1) + ' / ' + (d.pages || 1) + ' (' + (d.total || 0) + ')');

            var rows = d.rows || [];
            if (!rows.length) {
                $body.html('<tr><td colspan="9">' + (rpfVista === 'archivados' ? 'Sin folios archivados' : 'Sin folios') + '</td></tr>');
                return;
            }
            $body.empty();
            rows.forEach(function (r) {
                var actions = '';
                var isArchived = !!r.is_archived || rpfVista === 'archivados';
                if (!isArchived && r.can_process && canManage) {
                    actions += '<button type="button" class="button button-small button-primary rpf-start" data-id="' + r.factura_id + '">Procesar</button> ';
                } else {
                    actions += '<button type="button" class="button button-small rpf-open" data-id="' + r.factura_id + '">Ver</button> ';
                }
                if (!isArchived && r.estado === 'con_error') {
                    actions += '<button type="button" class="button button-small rpf-que-falta" data-id="' + r.factura_id + '">Qué falta</button> ';
                }
                if (canManage) {
                    if (isArchived) {
                        actions += '<button type="button" class="button button-small rpf-archive" data-id="' + r.factura_id + '" data-unarchive="1">Desarchivar</button>';
                    } else {
                        if (r.estado === 'ingresada' || r.estado === 'ingresada_manual') {
                            actions += '<button type="button" class="button button-small rpf-archive" data-id="' + r.factura_id + '">Archivar</button> ';
                        }
                        // Manual / Anular: ocultos solo en Ingresada (cierre automático).
                        if (r.estado !== 'ingresada') {
                            actions += '<button type="button" class="button button-small rpf-manual" data-id="' + r.factura_id + '" data-estado="ingresada_manual" title="Ingreso manual o híbrido">Manual</button> ';
                            actions += '<button type="button" class="button button-small rpf-manual" data-id="' + r.factura_id + '" data-estado="anulada">Anular</button>';
                        }
                        if (r.is_manual) {
                            actions += ' <button type="button" class="button button-small rpf-manual" data-id="' + r.factura_id + '" data-estado="">Quitar override</button>';
                        }
                    }
                }
                var blockersCell = String(r.blockers_count || 0);
                if (r.estado === 'con_error' && (r.blockers_count || 0) > 0) {
                    blockersCell = '<span class="rpf-blockers-count">' + (r.blockers_count || 0) + '</span>';
                }
                var estadoCell = estadoBadge(r.estado, r.label);
                if (r.is_hybrid) {
                    estadoCell += ' <span class="rpf-hybrid-chip" title="Algunas filas ya ingresadas">Híbrido' +
                        (r.omitted_count ? ' (' + r.omitted_count + ')' : '') + '</span>';
                }
                var fechaIngreso = r.created_at ? String(r.created_at).substring(0, 10) : '';
                $body.append(
                    '<tr class="' + (r.estado === 'con_error' ? 'rpf-row-error' : '') + '">' +
                    '<td><code>' + esc(r.folio) + '</code></td>' +
                    '<td>' + esc(r.fecha_emision || '') + '</td>' +
                    '<td>' + esc(fechaIngreso) + '</td>' +
                    '<td>' + esc(r.proveedor_nombre || '—') + '</td>' +
                    '<td>' + estadoCell + '</td>' +
                    '<td>' + progressBadge(r.progress_saved, r.progress_total, r.progress_status) + '</td>' +
                    '<td>' + blockersCell + '</td>' +
                    '<td>' + money(r.monto_total) + '</td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>'
                );
            });
        }).fail(function () {
            $body.html('<tr><td colspan="9">Error de red</td></tr>');
        });
    }

    function showSession(data) {
        rpfSession = data;
        rpfFacturaId = (data.invoice && data.invoice.id) || 0;
        $('#rpf-list-panel').hide();
        $('#rpf-session-panel').show();
        var inv = data.invoice || {};
        var proc = data.proceso || {};
        $('#rpf-session-header').html(
            '<h2 style="margin:0 0 8px;">Folio <code>' + esc(inv.folio) + '</code> — ' + esc(inv.proveedor_nombre || '') + '</h2>' +
            '<p class="description" style="margin:0;">' + estadoBadge(proc.estado, proc.label) +
            (data.is_hybrid ? ' <span class="rpf-hybrid-chip">Híbrido' +
                (data.omitted_count ? ' (' + data.omitted_count + ' ya ingresadas)' : '') + '</span>' : '') +
            ' · Fecha ' + esc(inv.fecha_emision || '') +
            ' · Pendientes: ' + (data.pending_targets || 0) +
            ' · Montos en <strong>' + (rpfViewMode === 'neto' ? 'neto' : 'bruto') + '</strong></p>'
        );

        renderBlockers(data.blockers || []);
        $('#rpf-complete').toggle(!!(canManage && data.can_complete));
        if (data.is_hybrid && data.can_complete) {
            $('#rpf-complete').text('Completar folio híbrido');
        } else {
            $('#rpf-complete').text('Marcar ingresada');
        }
        var isArchived = !!(proc.is_archived || data.is_archived);
        var estadoProc = String(proc.estado || '');
        var canArchive = canManage && !isArchived && (estadoProc === 'ingresada' || estadoProc === 'ingresada_manual');
        var canUnarchive = canManage && isArchived;
        var $archBtn = $('#rpf-archive-session');
        if (canUnarchive) {
            $archBtn.text('Desarchivar').attr('data-unarchive', '1').show();
        } else if (canArchive) {
            $archBtn.text('Archivar').attr('data-unarchive', '0').show();
        } else {
            $archBtn.hide().attr('data-unarchive', '0');
        }
        if (isArchived) {
            var headerHtml = $('#rpf-session-header').html();
            $('#rpf-session-header').html(headerHtml +
                ' <span class="rpf-progress-badge rpf-progress-parcial" title="Folio archivado">Archivado</span>');
        }
        updateRpfViewToggle();
        renderProcessLines(data.lines || [], !!data.is_hybrid);
    }

    function renderProcessLines(lines, isHybrid) {
        if (!lines.length) {
            $('#rpf-lines').html('<p>Sin ítems de producto.</p>');
            renderRpfPdfFooter();
            return;
        }
        var suf = ' ' + rpfAmountSuffix();
        var html = '<table class="widefat striped rpf-lines-table"><thead><tr>';
        if (isHybrid && canManage) {
            html += '<th class="rpf-hybrid-check" title="Ya ingresada manualmente/anterior">✓</th>';
        }
        html += '<th>Línea</th><th>Código</th><th>SKU</th><th>Nombre</th>' +
            '<th>Costo ant. ' + suf + '</th><th>Costo folio ' + suf + '</th><th>Δ costo</th>' +
            '<th>P ant. ' + suf + '</th><th>P propuesto ' + suf + '</th><th>Δ precio</th>' +
            '<th>Margen ant. ' + suf + '</th><th>Margen act. (neto)</th>' +
            '<th>Factor ant.</th><th>Factor act.</th><th>Familia</th><th></th>' +
            '</tr></thead><tbody>';

        lines.forEach(function (ln) {
            var hybridOmitted = !!ln.hybrid_omitted;
            var blocked = !!ln.blocked && !hybridOmitted;
            var rowClass = (blocked || hybridOmitted) ? 'rpf-row-blocked' : '';
            if (hybridOmitted) rowClass += ' rpf-row-hybrid-omitted';
            var iva = ln.iva_tipo || 'afecto';
            var sku = ln.is_child ? (ln.unit_sku || ln.sku) + ' <span class="description">(unitario)</span>' : (ln.sku || '—');
            var draft = rpfDraftPrices[ln.item_id] || {};
            var displayLocal = draft.local != null && draft.local !== ''
                ? draft.local
                : rpfPriceDisplay(ln.precio_propuesto, iva);
            var displayOnline = draft.online != null && draft.online !== ''
                ? draft.online
                : rpfPriceDisplay(ln.precio_online_propuesto, iva);
            var costoFolioNeto = rpfLineCostFolio(ln);
            var costoAntNeto = rpfLineCostAnterior(ln);
            var costoDeltaRaw = (costoFolioNeto != null && costoAntNeto != null)
                ? (Number(costoFolioNeto) - Number(costoAntNeto))
                : null;
            if (costoDeltaRaw != null && costoDeltaRaw > 0) rowClass += ' rpf-cost-up';
            if (costoDeltaRaw != null && costoDeltaRaw < 0) rowClass += ' rpf-cost-down';
            var costoDeltaPct = (costoAntNeto != null && Number(costoAntNeto) !== 0 && costoFolioNeto != null)
                ? Math.round(((Number(costoFolioNeto) - Number(costoAntNeto)) / Math.abs(Number(costoAntNeto))) * 10000) / 100
                : null;
            var costoNetoBase = costoFolioNeto != null
                ? Number(costoFolioNeto)
                : (costoAntNeto != null ? Number(costoAntNeto) : null);
            var canEditPrice = canManage && !blocked && !hybridOmitted;
            var canEditMargin = canEditPrice && costoNetoBase != null && !isNaN(costoNetoBase);
            var priceInput = !canEditPrice
                ? money(displayLocal)
                : '<input type="number" step="0.0001" min="0" class="small-text rpf-p-local" data-item="' + ln.item_id + '" value="' +
                    (displayLocal != null ? displayLocal : '') + '">';
            if (canEditPrice && (ln.apply_mode || 'apply') === 'historial_only' && ln.precio_vigente != null) {
                priceInput += '<div class="description rpf-vigente-hint">Vigente: ' +
                    money(rpfPriceDisplay(ln.precio_vigente, iva)) + suf + '</div>';
            }
            var onlineInput = '';
            if (ln.requires_online && canEditPrice) {
                onlineInput = '<div class="description">Online <input type="number" step="0.0001" min="0" class="small-text rpf-p-online" data-item="' + ln.item_id + '" value="' +
                    (displayOnline != null ? displayOnline : '') + '"></div>';
            } else if (ln.requires_online) {
                onlineInput = '<div class="description">Online: ' + money(rpfPriceDisplay(ln.precio_online_actual, iva)) + '</div>';
            }
            var famBtn = (ln.es_familia_unitaria && ln.grupo_id)
                ? '<button type="button" class="button button-small rpf-family" data-grupo="' + ln.grupo_id + '" data-item="' + ln.item_id + '">Ver familia</button>'
                : '—';
            if (ln.family_rule) {
                famBtn += '<div class="description">' + esc(ln.family_rule.codigo || '') + ' ' + esc(ln.family_rule.nombre || '') + '</div>';
            }
            if (ln.producto_base_id) {
                famBtn += ' <button type="button" class="button button-small rpf-comp-open"' +
                    ' data-producto="' + ln.producto_base_id + '"' +
                    ' data-sku="' + esc(ln.sku || '') + '"' +
                    ' data-nombre="' + esc(ln.nombre || ln.descripcion || '') + '">Competencia</button>';
                famBtn += ' <button type="button" class="button button-small rpf-bc-open"' +
                    ' data-producto="' + ln.producto_base_id + '"' +
                    ' data-sku="' + esc(ln.sku || '') + '"' +
                    ' data-nombre="' + esc(ln.nombre || ln.descripcion || '') + '">Barcode</button>';
            }
            var saveBtn = '';
            var applyMode = ln.apply_mode || 'apply';
            var confirmedHere = !!ln.confirmed_from_folio;
            var chips = '';
            if (!blocked && !hybridOmitted) {
                if (ln.costo_unchanged) {
                    chips += '<span class="rpf-chip rpf-chip-cost-same" title="El costo del folio no cambió respecto al anterior">Costo sin cambio — confirmar precio</span>';
                }
                if (ln.has_local_price && !confirmedHere && applyMode === 'apply') {
                    chips += '<span class="rpf-chip rpf-chip-other-origin">Precio vigente (otro origen) — confirmar</span>';
                }
                if (applyMode === 'historial_only' && ln.newer_folio) {
                    var nf = ln.newer_folio;
                    var nfLabel = (nf.folio ? '#' + nf.folio : ('#' + (nf.factura_id || ''))) +
                        (nf.fecha ? ' · ' + nf.fecha : '');
                    chips += '<span class="rpf-chip rpf-chip-historial-only">' +
                        'Hay un folio más reciente (' + esc(nfLabel) + ') que ya fijó el precio. ' +
                        'Confirmar aquí solo deja constancia; no cambia el vigente.' +
                        (nf.url ? ' <a href="' + esc(nf.url) + '" target="_blank" rel="noopener">Abrir</a>' : '') +
                        '</span>';
                }
            }
            if (hybridOmitted) {
                saveBtn = '<span class="description">Ya ingresada (híbrido)</span>';
            } else if (!blocked && canManage) {
                if (applyMode === 'historial_only') {
                    saveBtn = '<button type="button" class="button button-small button-primary rpf-save-line" data-item="' + ln.item_id + '" data-mode="historial">Confirmar en historial</button>';
                } else {
                    var sameAsVigente = false;
                    if (ln.has_local_price && displayLocal != null && displayLocal !== '') {
                        var propDisp = rpfPriceDisplay(ln.precio_propuesto, iva);
                        if (propDisp != null && Math.abs(Number(displayLocal) - Number(propDisp)) < 0.00015) {
                            sameAsVigente = true;
                        }
                    }
                    var btnLabel = (ln.has_local_price && sameAsVigente) ? 'Confirmar' : 'Guardar';
                    saveBtn = '<button type="button" class="button button-small button-primary rpf-save-line" data-item="' + ln.item_id + '" data-mode="apply">' + btnLabel + '</button>';
                }
            } else if (blocked) {
                saveBtn = '<span class="description">' + esc(ln.block_reason || 'Bloqueado') + '</span>';
                if (canLinkSku && (ln.block_reason || '') === 'Sin SKU') {
                    saveBtn += ' <button type="button" class="button button-small button-primary rpf-search-link-open"' +
                        ' data-item="' + ln.item_id + '"' +
                        ' data-codigo="' + esc(ln.codigo_proveedor || '') + '"' +
                        ' data-nombre="' + esc(ln.nombre || ln.descripcion || '') + '">Buscar</button>';
                }
                if (canCreateLocal && (ln.block_reason || '') === 'Sin SKU') {
                    saveBtn += ' <button type="button" class="button button-small button-primary rpf-create-local-open"' +
                        ' data-item="' + ln.item_id + '"' +
                        ' data-codigo="' + esc(ln.codigo_proveedor || '') + '"' +
                        ' data-nombre="' + esc(ln.nombre || ln.descripcion || '') + '">Crear local</button>';
                }
                if (canAnswerFamily && ln.can_answer_family && ln.producto_base_id) {
                    saveBtn += ' <button type="button" class="button button-small button-primary rpf-answer-family-open"' +
                        ' data-producto="' + ln.producto_base_id + '"' +
                        ' data-sku="' + esc(ln.sku || '') + '"' +
                        ' data-nombre="' + esc(ln.nombre || ln.descripcion || '') + '">Responder aquí</button>';
                }
            }
            if (confirmedHere && !blocked && !hybridOmitted) {
                saveBtn += ' <span class="dashicons dashicons-yes" style="color:green;" title="Confirmado desde este folio"></span>';
            }
            if (chips) {
                saveBtn += '<div class="rpf-line-chips">' + chips + '</div>';
            }

            var costoDeltaShow = rpfDeltaDisplay(costoDeltaRaw, 'cost', iva);
            var precioDeltaShow = rpfDeltaDisplay(ln.precio_delta, 'price', iva);
            var margenAntShow = null;
            if (ln.precio_anterior != null && costoAntNeto != null) {
                var pAntForM = rpfIsExento(iva)
                    ? Number(ln.precio_anterior)
                    : Number(ln.precio_anterior) / 1.19;
                margenAntShow = rpfRound4(pAntForM - Number(costoAntNeto));
            } else {
                margenAntShow = rpfMarginDisplay(ln.margen_anterior, iva);
            }
            var margenActNeto = draft.margin != null && draft.margin !== ''
                ? draft.margin
                : (ln.margen_actual != null ? rpfRound4(ln.margen_actual) : null);
            var margenPct = '';
            if (margenActNeto != null && !isNaN(margenActNeto) && costoNetoBase != null && Number(costoNetoBase) !== 0) {
                margenPct = Math.round((Number(margenActNeto) / Number(costoNetoBase)) * 1000) / 10;
            }
            var margenActCell;
            if (canEditMargin) {
                margenActCell = '<input type="number" step="0.0001" class="small-text rpf-m-act" data-item="' + ln.item_id + '" value="' +
                    (margenActNeto != null ? margenActNeto : '') + '" title="Margen neto ($)"> ' +
                    '<span class="description rpf-m-pct">' + (margenPct !== '' ? '(' + margenPct + '%)' : '') + '</span>';
            } else if (canEditPrice && !canEditMargin) {
                margenActCell = '<span class="description" title="Sin costo de referencia">—</span>';
            } else {
                margenActCell = money(rpfMarginDisplay(ln.margen_actual, iva)) +
                    (ln.margen_actual_pct != null ? ' <span class="description">(' + ln.margen_actual_pct + '%)</span>' : '');
            }
            var margenDeltaRaw = (margenActNeto != null && margenAntShow != null && !isNaN(margenActNeto) && !isNaN(margenAntShow))
                ? (Number(margenActNeto) - Number(margenAntShow))
                : null;

            // Factor = P neto / C neto (= P bruto / C bruto si afecto).
            var pAntNeto = null;
            if (ln.precio_anterior != null && ln.precio_anterior !== '' && !isNaN(ln.precio_anterior)) {
                pAntNeto = rpfIsExento(iva)
                    ? rpfRound4(ln.precio_anterior)
                    : rpfRound4(Number(ln.precio_anterior) / 1.19);
            }
            var factorAnt = rpfFactorFromNeto(pAntNeto, costoAntNeto);
            var pActNetoForFactor = null;
            if (displayLocal != null && displayLocal !== '' && !isNaN(displayLocal)) {
                pActNetoForFactor = rpfPriceDisplayToNeto(displayLocal, iva);
            } else if (ln.precio_propuesto != null) {
                pActNetoForFactor = rpfIsExento(iva)
                    ? rpfRound4(ln.precio_propuesto)
                    : rpfRound4(Number(ln.precio_propuesto) / 1.19);
            }
            var factorAct = draft.factor != null && draft.factor !== ''
                ? draft.factor
                : rpfFactorFromNeto(pActNetoForFactor, costoNetoBase);
            var factorActCell;
            if (canEditMargin) {
                factorActCell = '<input type="number" step="0.0001" min="0" class="small-text rpf-f-act" data-item="' + ln.item_id + '" value="' +
                    (factorAct != null ? factorAct : '') + '" title="Factor precio/costo (P÷C)">';
            } else if (canEditPrice && !canEditMargin) {
                factorActCell = '<span class="description" title="Sin costo de referencia">—</span>';
            } else {
                factorActCell = rpfFmtFactor(factorAct);
            }
            var factorDeltaRaw = (factorAct != null && factorAnt != null && !isNaN(factorAct) && !isNaN(factorAnt))
                ? (Number(factorAct) - Number(factorAnt))
                : null;

            html += '<tr class="' + rowClass + '" data-item="' + ln.item_id + '" data-iva="' + esc(iva) + '"' +
                ' data-costo-neto="' + (costoNetoBase != null ? esc(String(costoNetoBase)) : '') + '">';
            if (isHybrid && canManage) {
                html += '<td class="rpf-hybrid-check">' +
                    '<input type="checkbox" class="rpf-session-omit-ticket" value="' + ln.item_id + '"' +
                    (hybridOmitted ? ' checked' : '') +
                    ' title="Marcar o desmarcar como ya ingresada">' +
                    '</td>';
            }
            html += '<td>' + ln.numero_linea + '</td>' +
                '<td><code>' + esc(ln.codigo_proveedor || '') + '</code></td>' +
                '<td><code>' + sku + '</code></td>' +
                '<td>' + esc(ln.nombre || ln.descripcion || '') +
                    (ln.legacy_used ? ' <span class="description">(legacy)</span>' : '') + '</td>' +
                '<td>' + money(rpfCostDisplay(costoAntNeto, iva)) +
                    rpfPriorOriginHint(ln.costo_anterior_origen, 'Costo anterior') +
                    rpfFleteWarningHtml(ln, 'anterior') + '</td>' +
                '<td>' + money(rpfCostDisplay(costoFolioNeto, iva)) +
                    rpfFleteWarningHtml(ln, 'folio') + '</td>' +
                '<td class="' + deltaClass(costoDeltaRaw) + '">' + fmtDelta(costoDeltaShow, costoDeltaPct) + '</td>' +
                '<td>' + money(rpfPriceDisplay(ln.precio_anterior, iva)) +
                    rpfPriorOriginHint(ln.precio_anterior_origen, 'Precio anterior') + '</td>' +
                '<td>' + priceInput + onlineInput + '</td>' +
                '<td class="' + deltaClass(ln.precio_delta) + '">' + fmtDelta(precioDeltaShow, ln.precio_delta_pct) + '</td>' +
                '<td>' + money(margenAntShow) + '</td>' +
                '<td class="' + deltaClass(margenDeltaRaw) + '">' + margenActCell + '</td>' +
                '<td>' + rpfFmtFactor(factorAnt) + '</td>' +
                '<td class="' + deltaClass(factorDeltaRaw) + '">' + factorActCell + '</td>' +
                '<td>' + famBtn + '</td>' +
                '<td>' + saveBtn + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        if (isHybrid && canManage) {
            html = '<p class="description rpf-hybrid-hint">Podés marcar o desmarcar filas ya ingresadas; el árbol de tareas se actualiza solo para las que faltan. Podés guardar filas válidas aunque otras tengan error.</p>' + html;
        }
        $('#rpf-lines').html(html);
        renderRpfPdfFooter();
    }

    function renderRpfPdfFooter() {
        var $wrap = $('#rpf-pdf-footer');
        if (!$wrap.length) {
            $('#rpf-lines').after(
                '<div id="rpf-pdf-footer" class="rpf-pdf-footer" style="display:none;margin-top:12px;">' +
                '<button type="button" class="button" id="rpf-btn-ver-pdf">Ver PDF</button>' +
                '<div id="rpf-pdf-viewer-wrap" style="display:none;margin-top:10px;">' +
                '<iframe id="rpf-pdf-viewer" title="Documento" style="width:100%;height:480px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;"></iframe>' +
                '</div></div>'
            );
            $wrap = $('#rpf-pdf-footer');
        }
        var adjuntos = (rpfSession && rpfSession.invoice && rpfSession.invoice.adjuntos) || [];
        var withUrl = adjuntos.filter(function (a) { return a && a.url; });
        if (!withUrl.length) {
            $wrap.hide();
            $('#rpf-pdf-viewer-wrap').hide();
            $('#rpf-pdf-viewer').attr('src', 'about:blank');
            return;
        }
        $wrap.show().data('adjuntos', withUrl);
        $('#rpf-btn-ver-pdf').text(withUrl.length > 1 ? 'Ver PDF / escaneos (' + withUrl.length + ')' : 'Ver PDF');
    }

    function closeRpfFleteModal() {
        $('#rpf-flete-modal').hide().attr('aria-hidden', 'true');
    }

    function openRpfFleteModal() {
        if (!rpfFacturaId) {
            return;
        }
        ensureRpfFleteModal();
        $('#rpf-flete-modal').css('display', 'flex').attr('aria-hidden', 'false');
        $('#rpf-flete-body').html('<p class="description">Cargando…</p>');
        post('riverso_get_invoice', { factura_id: rpfFacturaId }).done(function (res) {
            if (!res.success || !res.data) {
                $('#rpf-flete-body').html('<p class="description">No se pudo cargar la factura.</p>');
                return;
            }
            renderRpfFleteModalBody(res.data);
        }).fail(function () {
            $('#rpf-flete-body').html('<p class="description">Error de red.</p>');
        });
    }

    function ensureRpfFleteModal() {
        if ($('#rpf-flete-modal').length) {
            return;
        }
        $('body').append(
            '<div id="rpf-flete-modal" class="rpf-modal" style="display:none;" aria-hidden="true">' +
            '<div class="rpf-modal-backdrop rpf-flete-modal-backdrop"></div>' +
            '<div class="rpf-modal-card rpf-flete-card">' +
            '<div class="rpf-modal-head">' +
            '<h2 style="margin:0;">Flete del folio</h2>' +
            '<button type="button" class="button rpf-flete-modal-close">Cerrar</button>' +
            '</div>' +
            '<div id="rpf-flete-body" class="rpf-modal-body"></div>' +
            '</div></div>'
        );
    }

    function renderRpfFleteModalBody(factura) {
        var fletes = factura.fletes_vinculados || [];
        var isGratuito = parseInt(factura.flete_gratuito, 10) === 1;
        var montoManual = Number(factura.costo_envio_manual || 0);
        var html = '';
        if (isGratuito) {
            html += '<p><span class="rpf-chip rpf-chip-cost-same">Flete gratuito</span> ' +
                '<button type="button" class="button button-small" id="rpf-flete-btn-ungratis">Quitar</button></p>';
        } else if (montoManual > 0) {
            html += '<p><span class="rpf-chip">Flete manual $' + Number(montoManual).toLocaleString('es-CL') + '</span></p>';
        }
        if (fletes.length) {
            html += '<ul style="margin:0 0 12px;padding-left:18px;">';
            fletes.forEach(function (fl) {
                html += '<li>Folio <strong>' + esc(fl.folio) + '</strong> — ' + esc(fl.proveedor_nombre || '') +
                    ' — $' + Number(fl.monto_total || 0).toLocaleString('es-CL') +
                    ' <button type="button" class="button button-small rpf-flete-unassign" data-envio-id="' + fl.id + '">Desvincular</button></li>';
            });
            html += '</ul>';
        } else if (!isGratuito && montoManual <= 0) {
            html += '<p class="description">Sin fletes vinculados.</p>';
        }

        html += '<div style="margin:12px 0;padding-top:10px;border-top:1px solid #dcdcde;">' +
            '<label><strong>Buscar flete para vincular</strong></label>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:6px;">' +
            '<div class="folio-search-wrap" style="position:relative;flex:1;min-width:200px;">' +
            '<input type="text" id="rpf-flete-search" class="regular-text" style="width:100%;" placeholder="Folio, proveedor o RUT…" autocomplete="off">' +
            '<input type="hidden" id="rpf-flete-assign-id" value="">' +
            '<div id="rpf-flete-results" class="folio-search-results rpf-folio-results" style="display:none;"></div>' +
            '</div>' +
            '<label style="margin:0;">Desde<input type="date" id="rpf-flete-desde" style="display:block;margin-top:2px;"></label>' +
            '<label style="margin:0;">Hasta<input type="date" id="rpf-flete-hasta" style="display:block;margin-top:2px;"></label>' +
            '<button type="button" class="button" id="rpf-flete-btn-search">Buscar</button>' +
            '<button type="button" class="button button-primary" id="rpf-flete-btn-assign">Vincular</button>' +
            '</div>' +
            '<p id="rpf-flete-selected" class="description" style="margin-top:6px;"></p>' +
            '</div>';

        html += '<div style="margin:12px 0;padding-top:10px;border-top:1px solid #dcdcde;">' +
            '<label><strong>Flete manual</strong></label>' +
            '<div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;align-items:center;">' +
            '<input type="text" id="rpf-flete-manual-monto" class="regular-text" style="width:140px;" placeholder="Monto $" value="' +
            (montoManual > 0 ? String(Math.round(montoManual)) : '') + '">' +
            '<button type="button" class="button button-primary" id="rpf-flete-btn-manual">Guardar flete manual</button>' +
            '</div></div>';

        if (!isGratuito) {
            html += '<div style="margin-top:10px;">' +
                '<button type="button" class="button" id="rpf-flete-btn-gratis">Marcar flete gratuito</button>' +
                ' <span class="description">Sin documento — costo $0.</span></div>';
        }

        $('#rpf-flete-body').html(html);
        bindRpfFleteSearcher();
    }

    function bindRpfFleteSearcher() {
        var timer = null;
        function runSearch() {
            var q = $('#rpf-flete-search').val().trim();
            var desde = $('#rpf-flete-desde').val() || '';
            var hasta = $('#rpf-flete-hasta').val() || '';
            var $results = $('#rpf-flete-results');
            if (!q && !desde && !hasta) {
                $results.hide().empty();
                return;
            }
            post('riverso_search_invoice_folios', {
                q: q,
                tipos: 'envio',
                exclude_id: rpfFacturaId,
                exclude_linked_to: rpfFacturaId,
                fecha_desde: desde,
                fecha_hasta: hasta
            }).done(function (res) {
                if (!res.success) {
                    $results.html('<div class="folio-result-empty">Error al buscar</div>').show();
                    return;
                }
                var rows = (res.data && res.data.results) || [];
                if (!rows.length) {
                    $results.html('<div class="folio-result-empty">Sin resultados</div>').show();
                    return;
                }
                $results.empty();
                rows.forEach(function (f) {
                    var label = 'Folio ' + f.folio + ' · ' + (f.fecha_emision || '') +
                        ' · $' + Number(f.monto_total || 0).toLocaleString('es-CL') +
                        ' · ' + (f.proveedor_nombre || f.rut_emisor || '');
                    $('<button type="button" class="folio-result-item"></button>')
                        .text(label)
                        .on('click', function () {
                            $('#rpf-flete-assign-id').val(String(f.id));
                            $('#rpf-flete-search').val('Folio ' + f.folio);
                            $('#rpf-flete-selected').html('Seleccionado: <strong>' + esc(label) + '</strong>');
                            $results.hide().empty();
                        })
                        .appendTo($results);
                });
                $results.show();
            });
        }
        $('#rpf-flete-search').off('input.rpfFlete').on('input.rpfFlete', function () {
            clearTimeout(timer);
            timer = setTimeout(runSearch, 250);
        });
        $('#rpf-flete-btn-search, #rpf-flete-desde, #rpf-flete-hasta').off('click.rpfFlete change.rpfFlete')
            .on('click.rpfFlete change.rpfFlete', runSearch);
    }

    function rpfGoogleUrl(nombre) {
        return 'https://www.google.com/search?q=' + encodeURIComponent(nombre || '');
    }

    function rpfCompEscAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;');
    }

    function rpfCompShowMsg(html, isError) {
        var $msg = $('#rpf-comp-form-msg');
        if (!html) {
            $msg.hide().removeClass('is-error is-ok').empty();
            return;
        }
        $msg.html(html)
            .removeClass('is-error is-ok')
            .addClass(isError ? 'is-error' : 'is-ok')
            .show();
    }

    function rpfCompClearForm() {
        $('#rpf-comp-url').val('');
        $('#rpf-comp-precio').val('');
        $('#rpf-comp-unidad').val('1');
        $('#rpf-comp-tipo-match').val('');
        $('#rpf-comp-nota').val('');
        $('#rpf-comp-tipo-warnings').hide().empty();
        rpfCompShowMsg('');
        if (rpfCompCtx) {
            rpfCompCtx.unit_context = null;
        }
    }

    function rpfCompRenderTipoWarnings(ctx, tipo) {
        var $box = $('#rpf-comp-tipo-warnings');
        ctx = ctx || {};
        var parts = [];
        if (tipo === 'exacto_envase') {
            if (ctx.family_status === 'unknown' || ctx.family_status === 'missing') {
                parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;">' +
                    '<strong>Advertencia familia:</strong> ' + esc(ctx.family_warning || 'Revisa el estado de familia.') +
                    '</div>');
            }
            if (ctx.badge_u || ctx.is_unitario) {
                parts.push('<div style="padding:8px 10px;background:#edf5fb;border-left:4px solid #2271b1;">' +
                    'Producto local unitario — se puede relacionar con cualquier unidad de envase de competencia (' +
                    esc(String(ctx.cantidad_min || 1)) + ' u) con este tipo.</div>');
            }
        } else if (tipo && tipo !== 'exacto_envase' && ctx.units_differ) {
            parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;">' +
                '<strong>Unidades distintas:</strong> local ' + esc(ctx.local_unit_label || '1') +
                ' vs competencia ' + esc(String(ctx.cantidad_min || 1)) + ' u. ' +
                'Si es el mismo producto con otro envase, usa “Exacto diferente U de envase”.</div>');
        }
        if (!parts.length) {
            $box.hide().empty();
            return;
        }
        $box.html(parts.join('')).show();
    }

    function rpfCompConfirmUnitsIfNeeded(tipo, ctx) {
        ctx = ctx || {};
        if (!tipo || tipo === 'exacto_envase' || !ctx.units_differ) {
            return true;
        }
        return window.confirm(
            'Las unidades son distintas (local ' + (ctx.local_unit_label || '1') +
            ' vs competencia ' + (ctx.cantidad_min || 1) +
            ' u). ¿Confirmas de todos modos?\n\nSi es el mismo producto con otro envase, cancela y elige “Exacto diferente U de envase”.'
        );
    }

    function rpfCompRefreshUnitContext() {
        if (!rpfCompCtx || !rpfCompCtx.producto_base_id) {
            return;
        }
        var unidad = parseInt($('#rpf-comp-unidad').val() || '1', 10) || 1;
        var tipo = $('#rpf-comp-tipo-match').val() || '';
        post('riverso_competencia_unit_context', {
            producto_base_id: rpfCompCtx.producto_base_id,
            cantidad_min: unidad
        }).done(function (res) {
            if (!res.success) {
                return;
            }
            rpfCompCtx.unit_context = (res.data && res.data.unit_context) || null;
            rpfCompRenderTipoWarnings(rpfCompCtx.unit_context, tipo);
        });
    }

    function rpfCompRivalRow(r, kind, viewMode) {
        var nuestroBruto = r.nuestro_precio;
        var nuestroNeto = r.nuestro_precio_neto;
        var rivalBruto = r.rival_unitario != null ? r.rival_unitario
            : (r.precio_bruto_unitario != null ? r.precio_bruto_unitario : r.precio);
        var rivalNeto = r.rival_unitario_neto;
        if (rivalNeto == null && rivalBruto != null) {
            rivalNeto = Math.round((Number(rivalBruto) / 1.19) * 10000) / 10000;
        }
        var delta = r.delta;
        var deltaPct = r.delta_pct;
        if (viewMode === 'neto' && nuestroNeto != null && rivalNeto != null) {
            delta = Math.round((Number(nuestroNeto) - Number(rivalNeto)) * 10000) / 10000;
            deltaPct = Number(rivalNeto) > 0
                ? Math.round(((Number(nuestroNeto) / Number(rivalNeto)) - 1) * 10000) / 10000
                : null;
        }
        if (kind === 'sugerido') {
            var scoreMetodo = '';
            if (r.score != null) {
                scoreMetodo += Math.round(Number(r.score) * 100) / 100;
            }
            if (r.metodo) {
                scoreMetodo += (scoreMetodo ? ' · ' : '') + r.metodo;
            }
            return '<tr>' +
                '<td>' + esc(r.fuente_nombre || r.fuente_slug || '—') +
                    ' <span class="rpe-sug-badge">Sugerido</span></td>' +
                '<td>' + rivalNameHtml(r) + '</td>' +
                '<td><code>' + esc(r.codigo_externo || '') + '</code></td>' +
                '<td>' + esc(scoreMetodo || '—') + '</td>' +
                '<td style="text-align:right">' + amountPair(nuestroBruto, nuestroNeto, viewMode) + '</td>' +
                '<td style="text-align:right">' + amountPair(rivalBruto, rivalNeto, viewMode) + '</td>' +
                '<td style="text-align:right">' + formatDelta(delta, deltaPct) + '</td>' +
                '</tr>';
        }
        return '<tr>' +
            '<td>' + esc(r.fuente_nombre || r.fuente_slug || '—') + '</td>' +
            '<td>' + rivalNameHtml(r) + '</td>' +
            '<td><code>' + esc(r.codigo_externo || '') + '</code>' +
                (r.cantidad_min != null ? '<br><span class="description">envase ≥ ' + esc(String(r.cantidad_min)) + '</span>' : '') +
            '</td>' +
            '<td>' + esc(r.tipo_match_label || r.tipo_match || '—') + '</td>' +
            '<td style="text-align:right">' + amountPair(nuestroBruto, nuestroNeto, viewMode) + '</td>' +
            '<td style="text-align:right">' + amountPair(rivalBruto, rivalNeto, viewMode) + '</td>' +
            '<td style="text-align:right">' + formatDelta(delta, deltaPct) + '</td>' +
            '<td>' + esc(r.snapshot_fecha || r.actualizado_at || '—') + '</td>' +
            '</tr>';
    }

    function renderRpfCompetenciaTables(raw) {
        var data = normalizeCompetencia(raw);
        var mapeados = data.mapeados;
        var sugeridos = data.sugeridos;
        var hasAny = mapeados.length > 0 || sugeridos.length > 0;
        var viewMode = rpfViewMode;

        if (data.admin_url) {
            $('#rpf-comp-admin-link').attr('href', data.admin_url).show();
        } else {
            $('#rpf-comp-admin-link').attr('href', '#').hide();
        }

        $('#rpf-comp-empty').prop('hidden', hasAny);

        if (mapeados.length) {
            $('#rpf-comp-mapeados-wrap').removeAttr('hidden');
            $('#rpf-comp-mapeados-body').html(mapeados.map(function (r) {
                return rpfCompRivalRow(r, 'mapeado', viewMode);
            }).join(''));
        } else {
            $('#rpf-comp-mapeados-wrap').attr('hidden', true);
            $('#rpf-comp-mapeados-body').empty();
        }

        if (sugeridos.length) {
            $('#rpf-comp-sugeridos-wrap').removeAttr('hidden');
            $('#rpf-comp-sugeridos-body').html(sugeridos.map(function (r) {
                return rpfCompRivalRow(r, 'sugerido', viewMode);
            }).join(''));
        } else {
            $('#rpf-comp-sugeridos-wrap').attr('hidden', true);
            $('#rpf-comp-sugeridos-body').empty();
        }
    }

    function loadRpfCompetencia() {
        if (!rpfCompCtx || !rpfCompCtx.producto_base_id) {
            return;
        }
        $('#rpf-comp-loading').removeAttr('hidden');
        $('#rpf-comp-empty').attr('hidden', true);
        $('#rpf-comp-mapeados-wrap').attr('hidden', true);
        $('#rpf-comp-sugeridos-wrap').attr('hidden', true);
        post('riverso_price_get_explorer', {
            producto_base_id: rpfCompCtx.producto_base_id
        }).done(function (res) {
            $('#rpf-comp-loading').attr('hidden', true);
            if (!res.success) {
                $('#rpf-comp-empty').text((res.data && res.data.message) || 'Error al cargar competencia').removeAttr('hidden');
                return;
            }
            var data = res.data || {};
            if (data.product) {
                if (data.product.canonical_sku) {
                    rpfCompCtx.sku = data.product.canonical_sku;
                }
                if (data.product.nombre || data.product.nombre_canonico) {
                    rpfCompCtx.nombre = data.product.nombre || data.product.nombre_canonico;
                }
                $('#rpf-comp-product-label').html(
                    '<code>' + esc(rpfCompCtx.sku || '') + '</code> ' + esc(rpfCompCtx.nombre || '')
                );
            }
            rpfCompCtx.lastData = data.competencia || {};
            renderRpfCompetenciaTables(rpfCompCtx.lastData);
        }).fail(function () {
            $('#rpf-comp-loading').attr('hidden', true);
            $('#rpf-comp-empty').text('Error de red al cargar competencia').removeAttr('hidden');
        });
    }

    function closeRpfCompModal() {
        $('#rpf-comp-modal').hide().attr('aria-hidden', 'true');
        rpfCompCtx = null;
        rpfCompClearForm();
    }

    function openRpfCompModal(opts) {
        opts = opts || {};
        var pbId = parseInt(opts.producto_base_id, 10) || 0;
        if (pbId <= 0) {
            return;
        }
        rpfCompCtx = {
            producto_base_id: pbId,
            sku: opts.sku || '',
            nombre: opts.nombre || '',
            unit_context: null
        };
        rpfCompClearForm();
        $('#rpf-comp-title').text('Competencia');
        $('#rpf-comp-product-label').html(
            '<code>' + esc(rpfCompCtx.sku) + '</code> ' + esc(rpfCompCtx.nombre)
        );
        if (canManageCompetencia) {
            $('#rpf-comp-manual-wrap').removeAttr('hidden');
        } else {
            $('#rpf-comp-manual-wrap').attr('hidden', true);
        }
        $('#rpf-comp-modal').css('display', 'flex').attr('aria-hidden', 'false');
        loadRpfCompetencia();
        if (canManageCompetencia) {
            rpfCompRefreshUnitContext();
        }
    }

    function submitRpfCompManual() {
        if (!canManageCompetencia || !rpfCompCtx || !rpfCompCtx.producto_base_id) {
            return;
        }
        var tipo = $('#rpf-comp-tipo-match').val() || '';
        if (!tipo) {
            window.alert('Debes seleccionar el tipo de match.');
            return;
        }
        var url = ($('#rpf-comp-url').val() || '').trim();
        if (!url) {
            window.alert('Pegá la URL del competidor.');
            return;
        }
        var precio = $('#rpf-comp-precio').val();
        if (precio === '' || isNaN(precio) || Number(precio) <= 0) {
            window.alert('Ingresá un precio total bruto mayor a 0.');
            return;
        }

        var $btn = $('#rpf-comp-save');
        var runSave = function () {
            $btn.prop('disabled', true).text('Guardando…');
            rpfCompShowMsg('');
            post('riverso_competencia_manual_ingreso', {
                producto_base_id: rpfCompCtx.producto_base_id,
                url: url,
                precio_total: precio,
                unidad: $('#rpf-comp-unidad').val() || 1,
                tipo_match: tipo,
                nota: $('#rpf-comp-nota').val() || ''
            }).done(function (res) {
                $btn.prop('disabled', false).text('Guardar y confirmar');
                if (!res.success) {
                    var html = '<strong>' + esc((res.data && res.data.message) || 'Error al guardar') + '</strong>';
                    var blockers = (res.data && res.data.blockers) || [];
                    if (blockers.length) {
                        html += '<ul style="margin:8px 0 0;padding-left:18px;">';
                        blockers.forEach(function (b) {
                            html += '<li>' + (b.url
                                ? '<a href="' + rpfCompEscAttr(b.url) + '" target="_blank" rel="noopener noreferrer">' + esc(b.label || b.tipo) + '</a>'
                                : esc(b.label || b.tipo)) + '</li>';
                        });
                        html += '</ul>';
                    }
                    if (res.data && res.data.unit_hint) {
                        html += '<p class="description" style="margin:8px 0 0;">' + esc(res.data.unit_hint) + '</p>';
                    }
                    rpfCompShowMsg(html, true);
                    return;
                }
                var d = res.data || {};
                rpfCompShowMsg(
                    'Vínculo confirmado (' + esc(d.fuente_slug || 'manual') + ')' +
                    (d.revisado_at ? ' · ' + esc(d.revisado_at) : '') + '.',
                    false
                );
                $('#rpf-comp-url').val('');
                $('#rpf-comp-precio').val('');
                $('#rpf-comp-unidad').val('1');
                $('#rpf-comp-tipo-match').val('');
                $('#rpf-comp-nota').val('');
                $('#rpf-comp-tipo-warnings').hide().empty();
                if (rpfCompCtx) {
                    rpfCompCtx.unit_context = null;
                }
                loadRpfCompetencia();
            }).fail(function (xhr) {
                $btn.prop('disabled', false).text('Guardar y confirmar');
                var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
                var msg = data.message || 'Error de red al guardar.';
                var html = '<strong>' + esc(msg) + '</strong>';
                var blockers = data.blockers || [];
                if (blockers.length) {
                    html += '<ul style="margin:8px 0 0;padding-left:18px;">';
                    blockers.forEach(function (b) {
                        html += '<li>' + (b.url
                            ? '<a href="' + rpfCompEscAttr(b.url) + '" target="_blank" rel="noopener noreferrer">' + esc(b.label || b.tipo) + '</a>'
                            : esc(b.label || b.tipo)) + '</li>';
                    });
                    html += '</ul>';
                }
                if (data.unit_hint) {
                    html += '<p class="description" style="margin:8px 0 0;">' + esc(data.unit_hint) + '</p>';
                }
                rpfCompShowMsg(html, true);
            });
        };

        var doConfirm = function () {
            if (!rpfCompConfirmUnitsIfNeeded(tipo, rpfCompCtx && rpfCompCtx.unit_context)) {
                return;
            }
            runSave();
        };

        if (rpfCompCtx && !rpfCompCtx.unit_context) {
            post('riverso_competencia_unit_context', {
                producto_base_id: rpfCompCtx.producto_base_id,
                cantidad_min: parseInt($('#rpf-comp-unidad').val() || '1', 10) || 1
            }).done(function (res) {
                if (res.success && rpfCompCtx) {
                    rpfCompCtx.unit_context = (res.data && res.data.unit_context) || null;
                    rpfCompRenderTipoWarnings(rpfCompCtx.unit_context, tipo);
                }
                doConfirm();
            }).fail(function () {
                doConfirm();
            });
            return;
        }
        doConfirm();
    }

    function rpfBcProductUrl(productoBaseId) {
        var base = productsAdminUrl || '';
        if (!base) {
            return '#';
        }
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        return base + sep + 'action=detail&id=' + encodeURIComponent(productoBaseId) + '&tab=barcodes';
    }

    function rpfBcShowMsg(html, isError) {
        var $msg = $('#rpf-bc-form-msg');
        if (!html) {
            $msg.hide().removeClass('is-error is-ok').empty();
            return;
        }
        $msg.html(html)
            .removeClass('is-error is-ok')
            .addClass(isError ? 'is-error' : 'is-ok')
            .show();
    }

    function rpfBcClearForm() {
        $('#rpf-bc-type').val('ean13');
        $('#rpf-bc-code').val('');
        $('#rpf-bc-proveedor').html('<option value="">— Seleccione proveedor —</option>');
        $('#rpf-bc-supplier-wrap').attr('hidden', true);
        $('#rpf-bc-cantidad').val('1');
        $('#rpf-bc-unidad').val('unidad');
        $('#rpf-bc-origen').val('manual');
        $('#rpf-bc-reason').val('');
        rpfBcShowMsg('');
    }

    function rpfBcSyncSupplierVisibility() {
        var isSupplier = ($('#rpf-bc-type').val() || '') === 'supplier';
        if (isSupplier) {
            $('#rpf-bc-supplier-wrap').removeAttr('hidden');
        } else {
            $('#rpf-bc-supplier-wrap').attr('hidden', true);
        }
    }

    function rpfBcFillProveedores(proveedores) {
        var options = '<option value="">— Seleccione proveedor —</option>';
        (proveedores || []).forEach(function (p) {
            var id = p.proveedor_id || p.id || '';
            var name = p.proveedor_nombre || p.nombre || ('#' + id);
            if (id) {
                options += '<option value="' + esc(String(id)) + '">' + esc(name) + '</option>';
            }
        });
        $('#rpf-bc-proveedor').html(options);
    }

    function rpfBcRenderList(barcodes) {
        var list = barcodes || [];
        if (!list.length) {
            $('#rpf-bc-list-wrap').attr('hidden', true);
            $('#rpf-bc-list-body').empty();
            $('#rpf-bc-empty').removeAttr('hidden');
            return;
        }
        $('#rpf-bc-empty').attr('hidden', true);
        $('#rpf-bc-list-wrap').removeAttr('hidden');
        $('#rpf-bc-list-body').html(list.map(function (b) {
            var tipo = b.tipo || '—';
            var qty = (b.cantidad != null ? b.cantidad : 1) + ' ' + (b.unidad_medida || b.unidad || 'unidad');
            var estado = b.estado || '—';
            var origen = b.origen_datos || b.origen || '—';
            return '<tr>' +
                '<td><code>' + esc(b.codigo || '') + '</code></td>' +
                '<td>' + esc(tipo) + '</td>' +
                '<td>' + esc(String(qty)) + '</td>' +
                '<td>' + esc(estado) + '</td>' +
                '<td>' + esc(origen) + '</td>' +
                '</tr>';
        }).join(''));
    }

    function closeRpfBcModal() {
        $('#rpf-bc-modal').hide().attr('aria-hidden', 'true');
        rpfBcCtx = null;
        rpfBcClearForm();
    }

    function openRpfBcModal(opts) {
        opts = opts || {};
        var pbId = parseInt(opts.producto_base_id, 10) || 0;
        if (pbId <= 0) {
            return;
        }
        rpfBcCtx = {
            producto_base_id: pbId,
            sku: opts.sku || '',
            nombre: opts.nombre || ''
        };
        rpfBcClearForm();
        var productUrl = rpfBcProductUrl(pbId);
        $('#rpf-bc-title').text('Barcodes');
        $('#rpf-bc-product-label').html(
            '<code>' + esc(rpfBcCtx.sku) + '</code> ' + esc(rpfBcCtx.nombre)
        );
        $('#rpf-bc-product-link').attr('href', productUrl);
        $('#rpf-bc-denied-link').attr('href', productUrl);
        $('#rpf-bc-list-wrap').attr('hidden', true);
        $('#rpf-bc-list-body').empty();
        $('#rpf-bc-empty').attr('hidden', true);
        $('#rpf-bc-loading').attr('hidden', true);
        $('#rpf-bc-form-wrap').attr('hidden', true);

        if (!canViewBarcodes) {
            $('#rpf-bc-denied').removeAttr('hidden');
            $('#rpf-bc-modal').css('display', 'flex').attr('aria-hidden', 'false');
            return;
        }
        $('#rpf-bc-denied').attr('hidden', true);
        if (canAssignBarcodes) {
            $('#rpf-bc-form-wrap').removeAttr('hidden');
        }
        $('#rpf-bc-modal').css('display', 'flex').attr('aria-hidden', 'false');
        loadRpfBarcodes();
    }

    function loadRpfBarcodes() {
        if (!rpfBcCtx || !rpfBcCtx.producto_base_id || !canViewBarcodes) {
            return;
        }
        $('#rpf-bc-loading').removeAttr('hidden');
        $('#rpf-bc-empty').attr('hidden', true);
        $('#rpf-bc-list-wrap').attr('hidden', true);
        post('riverso_products_get', {
            id: rpfBcCtx.producto_base_id
        }).done(function (res) {
            $('#rpf-bc-loading').attr('hidden', true);
            if (!res.success) {
                $('#rpf-bc-empty').text((res.data && res.data.message) || 'Error al cargar barcodes').removeAttr('hidden');
                return;
            }
            var item = (res.data && res.data.item) || {};
            if (item.canonical_sku) {
                rpfBcCtx.sku = item.canonical_sku;
            }
            if (item.nombre_canonico || item.nombre) {
                rpfBcCtx.nombre = item.nombre_canonico || item.nombre;
            }
            $('#rpf-bc-product-label').html(
                '<code>' + esc(rpfBcCtx.sku || '') + '</code> ' + esc(rpfBcCtx.nombre || '')
            );
            rpfBcFillProveedores(item.proveedores || []);
            rpfBcRenderList(item.barcodes || []);
            rpfBcSyncSupplierVisibility();
        }).fail(function () {
            $('#rpf-bc-loading').attr('hidden', true);
            $('#rpf-bc-empty').text('Error de red al cargar barcodes').removeAttr('hidden');
        });
    }

    function submitRpfBcAdd() {
        if (!canAssignBarcodes || !rpfBcCtx || !rpfBcCtx.producto_base_id) {
            return;
        }
        var code = ($('#rpf-bc-code').val() || '').trim();
        if (!code) {
            window.alert('Ingresá el código de barra.');
            return;
        }
        var tipo = $('#rpf-bc-type').val() || 'ean13';
        var $btn = $('#rpf-bc-save');
        $btn.prop('disabled', true).text('Guardando…');
        rpfBcShowMsg('');
        post('riverso_products_add_barcode', {
            product_id: rpfBcCtx.producto_base_id,
            barcode: code,
            tipo: tipo,
            proveedor_id: tipo === 'supplier' ? ($('#rpf-bc-proveedor').val() || 0) : 0,
            cantidad: $('#rpf-bc-cantidad').val() || 1,
            unidad_medida: $('#rpf-bc-unidad').val() || 'unidad',
            origen_datos: $('#rpf-bc-origen').val() || 'manual',
            audit_reason: $('#rpf-bc-reason').val() || ''
        }).done(function (res) {
            $btn.prop('disabled', false).text('Agregar código de barra');
            if (!res.success) {
                rpfBcShowMsg('<strong>' + esc((res.data && res.data.message) || 'Error al guardar') + '</strong>', true);
                return;
            }
            rpfBcShowMsg('Código agregado.', false);
            $('#rpf-bc-code').val('');
            $('#rpf-bc-cantidad').val('1');
            $('#rpf-bc-reason').val('');
            var item = (res.data && res.data.item) || null;
            if (item && item.barcodes) {
                rpfBcFillProveedores(item.proveedores || []);
                rpfBcRenderList(item.barcodes);
            } else {
                loadRpfBarcodes();
            }
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Agregar código de barra');
            var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
            rpfBcShowMsg('<strong>' + esc(data.message || 'Error de red al guardar.') + '</strong>', true);
        });
    }

    function openFamilyModal(grupoId, itemId) {
        var $row = $('#rpf-lines tr[data-item="' + itemId + '"]');
        var p = $row.find('.rpf-p-local').val();
        var iva = $row.attr('data-iva') || 'afecto';
        // Preview de familia espera p_asignado bruto.
        if (p !== '' && p != null && !isNaN(p) && rpfViewMode === 'neto') {
            p = rpfConvertTypedAmount(p, 'neto', 'bruto', iva);
        }
        post('riverso_price_folio_process_family', {
            grupo_id: grupoId,
            p_asignado: p || ''
        }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo cargar la familia');
                return;
            }
            var d = res.data || {};
            var snap = d.snapshot || {};
            var prev = d.preview || {};
            var rule = d.rule || {};
            var html = '';
            html += '<p><strong>Costo unitario calc.:</strong> ' + money(snap.coste_calculado || (snap.coste && snap.coste.coste)) + '</p>';
            if (rule && rule.id) {
                html += '<h3>Regla ' + esc(rule.codigo || '') + ' — ' + esc(rule.nombre || '') + '</h3><ul>';
                (rule.tiers || []).forEach(function (t) {
                    html += '<li>' + esc(t.cantidad_desde ?? t.desde ?? '') + '–' + esc(t.cantidad_hasta ?? t.hasta ?? '∞') +
                        ': <code>' + esc(t.formula || '') + '</code></li>';
                });
                html += '</ul>';
            } else {
                html += '<p class="description">Sin regla de familia asignada.</p>';
            }
            html += '<h3>Integrantes (P = ' + money(prev.p_asignado) + ')</h3>';
            html += '<table class="widefat striped"><thead><tr><th>SKU</th><th>Qty</th><th>P/u regla</th><th>Total</th><th>Costo u.</th><th>Margen</th></tr></thead><tbody>';
            (prev.members || []).forEach(function (m) {
                html += '<tr><td><code>' + esc(m.canonical_sku) + '</code></td><td>' + esc(m.cantidad_unidades) +
                    '</td><td>' + money(m.precio_unitario_regla) + '</td><td>' + money(m.precio_total_presentacion) +
                    '</td><td>' + money(m.coste_unitario) + '</td><td>' + money(m.margen) + '</td></tr>';
            });
            html += '</tbody></table>';
            html += '<p class="description">Al guardar se actualiza solo el P del producto unitario; los hijos se calculan por la regla.</p>';
            $('#rpf-family-body').html(html);
            $('#rpf-family-modal').show().attr('aria-hidden', 'false');
        });
    }

    function backToList() {
        rpfSession = null;
        rpfFacturaId = 0;
        closeRpfFleteModal();
        $('#rpf-pdf-viewer-wrap').hide();
        $('#rpf-pdf-viewer').attr('src', 'about:blank');
        $('#rpf-pdf-footer').hide();
        $('#rpf-session-panel').hide();
        $('#rpf-list-panel').show();
        loadProcessList();
    }

    /* ===== Explorer ===== */

    function doSearch(term, $target) {
        if (!term || term.length < 2) {
            $target.empty().attr('hidden', true);
            return;
        }
        post('riverso_price_search_products', { term: term }).done(function (res) {
            if (!res || !res.success) {
                $target.html('<div class="rce-result">Sin resultados</div>').removeAttr('hidden');
                return;
            }
            var rows = (res.data && res.data.results) || [];
            if (!rows.length) {
                $target.html('<div class="rce-result">Sin resultados</div>').removeAttr('hidden');
                return;
            }
            var html = rows.map(function (r) {
                var local = r.local || {};
                var pBruto = local.p_asignado != null ? local.p_asignado : r.p_asignado;
                var pNeto = local.p_neto != null ? local.p_neto : r.p_neto;
                var costSrc = local.c_ref_bases ? local : (r.c_ref_bases ? r : local);
                var costs = pickExplorerCostNetoBruto($.extend({}, costSrc, {
                    c_ref_bases: local.c_ref_bases || r.c_ref_bases,
                    c_ref: local.c_ref != null ? local.c_ref : r.c_ref,
                    c_ref_bruto: local.c_ref_bruto != null ? local.c_ref_bruto : r.c_ref_bruto,
                    c_ref_neto: local.c_ref_neto != null ? local.c_ref_neto : r.c_ref_neto,
                    iva_tipo: r.facto_iva_tipo
                }));
                var cBruto = costs.bruto;
                var cNeto = costs.neto;
                var pShow = priceViewMode === 'bruto' ? pBruto : pNeto;
                var pAlt = priceViewMode === 'bruto' ? pNeto : pBruto;
                var cShow = priceViewMode === 'bruto' ? cBruto : cNeto;
                var cAlt = priceViewMode === 'bruto' ? cNeto : cBruto;
                var altP = priceViewMode === 'bruto' ? 'neto' : 'bruto';
                var altC = altP;
                var oPrecio = local.origen_precio || r.origen_precio;
                var oCosto = local.origen_costo || r.origen_costo;
                var meta = '<span class="rce-result-meta">' +
                    'Precio ' + money(pShow) +
                    (pAlt != null ? ' <span class="description">(' + altP + ' ' + money(pAlt) + ')</span>' : '') +
                    ' · Costo ' + money(cShow) +
                    (cAlt != null ? ' <span class="description">(' + altC + ' ' + money(cAlt) + ')</span>' : '') +
                    ' · Precio: ' + esc(originLabel(oPrecio)) +
                    ' · Costo: ' + esc(originLabel(oCosto)) +
                    '</span>';
                return '<button type="button" class="rce-result rpe-pick" data-id="' + r.producto_base_id + '">' +
                    '<strong>' + esc(r.nombre) + '</strong> <code>' + esc(r.canonical_sku) + '</code>' +
                    meta +
                    '</button>';
            }).join('');
            $target.html(html).removeAttr('hidden');
        });
    }

    function loadExplorer(id) {
        post('riverso_price_get_explorer', { producto_base_id: id }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo cargar');
                return;
            }
            renderExplorer(res.data);
        });
    }

    function renderExplorer(data) {
        explorerData = data;
        var p = data.product || {};
        $('#rpe-empty-state').hide();
        $('#rpe-product-panel').removeAttr('hidden');
        $('#rpe-product-name').text(p.nombre || '—');
        $('#rpe-product-sku').text(p.canonical_sku || '—');
        $('#rpe-search-results').empty().attr('hidden', true);
        updateViewHints();

        fillChannel('local', data.local || {});
        fillChannel('online', data.online || {});
        renderFamily(data.family);
        renderCompetencia(data.competencia || []);
        renderMiniHistory(data.history || []);
        renderChart(data.chart);
    }

    function refreshExplorerAmounts() {
        if (!explorerData) {
            return;
        }
        updateViewHints();
        fillChannel('local', explorerData.local || {});
        fillChannel('online', explorerData.online || {});
        renderFamily(explorerData.family);
        renderCompetencia(explorerData.competencia || []);
        var term = $('#rpe-search-input').val();
        if (term && term.length >= 2 && !$('#rpe-search-results').attr('hidden')) {
            doSearch(term, $('#rpe-search-results'));
        }
    }

    function netFromGross(bruto, ivaTipo) {
        if (bruto === null || bruto === undefined || bruto === '' || isNaN(bruto)) {
            return null;
        }
        if (String(ivaTipo || 'afecto').toLowerCase() === 'exento') {
            return Number(bruto);
        }
        return Math.round((Number(bruto) / 1.19) * 10000) / 10000;
    }

    function arrowDiff(beforeTxt, afterTxt) {
        return esc(beforeTxt) + ' → ' + esc(afterTxt);
    }

    function openSaveConfirmModal(canal) {
        if (!explorerData || !explorerData.product) {
            return;
        }
        pendingSaveCanal = canal;
        var row = explorerData[canal] || {};
        var product = explorerData.product;
        var ivaTipo = product.facto_iva_tipo || 'afecto';
        var newBrutoRaw = canal === 'online'
            ? $('#rpe-online-asignado').val()
            : $('#rpe-local-asignado').val();
        var newBruto = (newBrutoRaw === '' || isNaN(newBrutoRaw)) ? null : Number(newBrutoRaw);
        var oldBruto = row.p_asignado != null ? Number(row.p_asignado) : null;
        var oldNeto = row.p_neto != null ? Number(row.p_neto) : netFromGross(oldBruto, ivaTipo);
        var newNeto = netFromGross(newBruto, ivaTipo);
        var cPair = pickExplorerCostNetoBruto(row);
        var cBruto = cPair.bruto;
        var cNeto = cPair.neto;
        var origenCosto = originLabel(row.origen_costo);

        var oldFactor = (oldBruto != null && cBruto != null && Number(cBruto) > 0)
            ? Math.round((oldBruto / Number(cBruto)) * 10000) / 10000
            : null;
        var newFactor = (newBruto != null && cBruto != null && Number(cBruto) > 0)
            ? Math.round((newBruto / Number(cBruto)) * 10000) / 10000
            : null;

        var canalLabel = canal === 'online' ? 'Online' : 'Local';
        $('#rpe-save-meta').html(
            '<strong>' + esc(canalLabel) + '</strong> · SKU <code>' + esc(product.canonical_sku || '—') + '</code>' +
            '<br>' + esc(product.nombre || '')
        );
        $('#rpe-save-precio-bruto').html(arrowDiff(money(oldBruto), money(newBruto)));
        $('#rpe-save-precio-neto').html(arrowDiff(money(oldNeto), money(newNeto)));
        $('#rpe-save-costo').html(
            money(cBruto) + ' bruto / ' + money(cNeto) + ' neto' +
            ' <span class="description">(sin cambio)</span>' +
            (origenCosto && origenCosto !== '—'
                ? ' · origen <span class="rpe-origin-badge" data-key="' + esc(originKey(row.origen_costo)) + '">' + esc(origenCosto) + '</span>'
                : '')
        );
        $('#rpe-save-margen').html(arrowDiff(
            oldFactor != null ? (oldFactor.toFixed(4) + '×') : '—',
            newFactor != null ? (newFactor.toFixed(4) + '×') : '—'
        ));

        $('#rpe-save-modal').show().attr('aria-hidden', 'false');
        $('#rpe-save-confirm').prop('disabled', false).focus();
    }

    function closeSaveConfirmModal() {
        pendingSaveCanal = null;
        $('#rpe-save-modal').hide().attr('aria-hidden', 'true');
        $('#rpe-save-confirm').prop('disabled', false);
    }

    function fillChannel(canal, row) {
        var prefix = 'rpe-' + canal;
        var costs = pickExplorerCostNetoBruto(row || {});
        var cBruto = costs.bruto;
        var cNeto = costs.neto;
        // Por ahora: p_ref en pantalla = p_asignado (no usa p_ref persistido).
        var pRefBruto = row.p_asignado != null ? Number(row.p_asignado) : null;
        var pRefNeto = row.p_neto;
        if (pRefNeto == null && pRefBruto != null) {
            var ivaR = (row.iva_tipo || (explorerData && explorerData.product && explorerData.product.facto_iva_tipo) || 'afecto');
            pRefNeto = String(ivaR).toLowerCase() === 'exento'
                ? Number(pRefBruto)
                : Math.round((Number(pRefBruto) / 1.19) * 10000) / 10000;
        }
        fillDualField(prefix, 'cref', cNeto, cBruto);
        fillDualField(prefix, 'pref', pRefNeto, pRefBruto);

        // Input siempre guarda bruto; neto = bruto / 1.19 (4 dec).
        $('#' + prefix + '-asignado').val(row.p_asignado != null ? row.p_asignado : '');
        var neto = row.p_neto;
        if (neto == null && row.p_asignado != null) {
            var ivaTipo = (explorerData && explorerData.product && explorerData.product.facto_iva_tipo) || 'afecto';
            neto = String(ivaTipo).toLowerCase() === 'exento'
                ? Number(row.p_asignado)
                : Math.round((Number(row.p_asignado) / 1.19) * 10000) / 10000;
        }
        var $asignadoAlt = $('#' + prefix + '-asignado-alt');
        if (priceViewMode === 'bruto') {
            $asignadoAlt.text(neto != null ? '(neto ' + money(neto) + ')' : '').toggle(neto != null);
        } else {
            $asignadoAlt.text(row.p_asignado != null ? '(bruto ' + money(row.p_asignado) + ')' : '').toggle(row.p_asignado != null);
        }

        setOriginBadge($('#' + prefix + '-origen-costo'), row.origen_costo);
        setOriginBadge($('#' + prefix + '-origen-precio'), row.origen_precio);

        $('#' + prefix + '-neto').text(money(neto));
        var pBruto = row.p_asignado != null ? Number(row.p_asignado) : null;
        var factorMin = row.factor_minimo != null ? Number(row.factor_minimo) : 1.30;
        var factor = (pBruto != null && cBruto != null && Number(cBruto) > 0)
            ? Math.round((pBruto / Number(cBruto)) * 10000) / 10000
            : null;
        var alertaReal = factor != null && factor < factorMin;
        var margen = '—';
        if (factor != null && pBruto != null && cBruto != null) {
            margen = money(pBruto) + ' / ' + money(cBruto) + ' = ' + factor.toFixed(4) + '×';
            var delta = Math.round((pBruto - Number(cBruto)) * 10000) / 10000;
            margen += ' · Δ ' + money(delta);
        }
        $('#' + prefix + '-margen').text(margen);
        var estado = (row.estado_aprobacion || '—');
        if (alertaReal) {
            estado += ' · margen bajo (mín. ' + factorMin.toFixed(2) + '×)';
        }
        $('#' + prefix + '-estado').text(estado);
        if (canal === 'online') {
            var on = parseInt(row.en_uso, 10) === 1;
            $('#rpe-online-badge')
                .toggleClass('rpe-badge-on', on)
                .toggleClass('rpe-badge-off', !on)
                .text(on ? 'En uso' : 'No en uso');
        }
    }

    function renderFamily(family) {
        if (!family) {
            $('#rpe-family-section').attr('hidden', true);
            return;
        }
        $('#rpe-family-section').removeAttr('hidden');
        $('#rpe-family-name').text(family.nombre || '');
        var meta = family.regla_id ? ('Regla #' + family.regla_id) : 'Sin regla de familia asignada';
        meta += ' · P del unitario es bruto (TPV)';
        if (family.preview && family.preview.error) {
            meta += ' — ' + family.preview.error;
        }
        $('#rpe-family-meta').text(meta);
        var members = family.members || [];
        if (!members.length) {
            $('#rpe-family-body').html('<tr><td colspan="7">Sin miembros</td></tr>');
            return;
        }
        $('#rpe-family-body').html(members.map(function (m) {
            var unitBadge = parseInt(m.es_unidad_minima, 10) === 1
                ? ' <span class="rpe-unit-badge">Unitario</span>'
                : '';
            var costeNeto = m.coste_unitario_neto != null ? m.coste_unitario_neto : m.coste_unitario;
            var costeBruto = m.coste_unitario_bruto;
            var costeShow = priceViewMode === 'bruto' ? (costeBruto != null ? costeBruto : costeNeto) : costeNeto;
            var costeAlt = priceViewMode === 'bruto' ? costeNeto : costeBruto;
            var costeAltLabel = priceViewMode === 'bruto' ? 'neto' : 'bruto';
            var costeHtml = money(costeShow);
            if (costeAlt != null && Number(costeAlt) !== Number(costeShow)) {
                costeHtml += '<br><span class="description">(' + costeAltLabel + ' ' + money(costeAlt) + ')</span>';
            }
            return '<tr>' +
                '<td><code>' + esc(m.canonical_sku) + '</code></td>' +
                '<td>' + esc(m.nombre_canonico) + unitBadge + '</td>' +
                '<td style="text-align:right">' + (m.cantidad_unidades != null ? m.cantidad_unidades : '—') + '</td>' +
                '<td style="text-align:right">' + money(m.precio_unitario_regla) +
                    '<br><span class="description">bruto</span></td>' +
                '<td style="text-align:right">' + money(m.precio_total_presentacion) +
                    '<br><span class="description">bruto</span></td>' +
                '<td style="text-align:right">' + costeHtml + '</td>' +
                '<td style="text-align:right">' + money(m.margen) + '</td>' +
                '</tr>';
        }).join(''));
    }

    function deltaClass(delta) {
        if (delta == null || isNaN(delta)) {
            return '';
        }
        if (Number(delta) < 0) {
            return 'rpe-delta-better';
        }
        if (Number(delta) > 0) {
            return 'rpe-delta-worse';
        }
        return 'rpe-delta-equal';
    }

    function formatDelta(delta, deltaPct) {
        if (delta == null || isNaN(delta)) {
            return '—';
        }
        var sign = Number(delta) > 0 ? '+' : '';
        var txt = sign + money(delta);
        if (deltaPct != null && !isNaN(deltaPct)) {
            var pctSign = Number(deltaPct) > 0 ? '+' : '';
            txt += ' (' + pctSign + (Number(deltaPct) * 100).toFixed(1) + '%)';
        }
        return '<span class="' + deltaClass(delta) + '">' + txt + '</span>';
    }

    function rivalNameHtml(r) {
        if (r.url_producto) {
            return '<a href="' + esc(r.url_producto) + '" target="_blank" rel="noopener">' + esc(r.nombre || '') + '</a>';
        }
        return esc(r.nombre || '');
    }

    function amountPair(bruto, neto, viewMode) {
        viewMode = viewMode || priceViewMode;
        var show = viewMode === 'bruto' ? bruto : neto;
        var alt = viewMode === 'bruto' ? neto : bruto;
        var altLabel = viewMode === 'bruto' ? 'neto' : 'bruto';
        var html = money(show);
        if (alt != null && !isNaN(Number(alt)) && Number(alt) !== Number(show)) {
            html += '<br><span class="description">(' + altLabel + ' ' + money(alt) + ')</span>';
        }
        return html;
    }

    function normalizeCompetencia(data) {
        if (!data) {
            return { mapeados: [], sugeridos: [], comparacion_familia: null, admin_url: '' };
        }
        if (Array.isArray(data)) {
            return { mapeados: data, sugeridos: [], comparacion_familia: null, admin_url: '' };
        }
        return {
            mapeados: data.mapeados || [],
            sugeridos: data.sugeridos || [],
            comparacion_familia: data.comparacion_familia || null,
            admin_url: data.admin_url || ''
        };
    }

    function renderCompetencia(raw) {
        var data = normalizeCompetencia(raw);
        var mapeados = data.mapeados;
        var sugeridos = data.sugeridos;
        var familia = data.comparacion_familia;
        var hasAny = mapeados.length > 0 || sugeridos.length > 0;

        $('#rpe-comp-section').removeAttr('hidden');
        if (data.admin_url) {
            $('#rpe-comp-admin-link').attr('href', data.admin_url).show();
        } else {
            $('#rpe-comp-admin-link').attr('href', '#').hide();
        }

        $('#rpe-comp-empty').prop('hidden', hasAny);

        if (mapeados.length) {
            $('#rpe-comp-mapeados-wrap').removeAttr('hidden');
            $('#rpe-comp-mapeados-body').html(mapeados.map(function (r) {
                var nuestroBruto = r.nuestro_precio;
                var nuestroNeto = r.nuestro_precio_neto;
                var rivalBruto = r.rival_unitario != null ? r.rival_unitario
                    : (r.precio_bruto_unitario != null ? r.precio_bruto_unitario : r.precio);
                var rivalNeto = r.rival_unitario_neto;
                if (rivalNeto == null && rivalBruto != null) {
                    rivalNeto = Math.round((Number(rivalBruto) / 1.19) * 10000) / 10000;
                }
                var delta = r.delta;
                var deltaPct = r.delta_pct;
                if (priceViewMode === 'neto' && nuestroNeto != null && rivalNeto != null) {
                    delta = Math.round((Number(nuestroNeto) - Number(rivalNeto)) * 10000) / 10000;
                    deltaPct = Number(rivalNeto) > 0
                        ? Math.round(((Number(nuestroNeto) / Number(rivalNeto)) - 1) * 10000) / 10000
                        : null;
                }
                return '<tr>' +
                    '<td>' + esc(r.fuente_nombre || r.fuente_slug || '—') + '</td>' +
                    '<td>' + rivalNameHtml(r) + '</td>' +
                    '<td><code>' + esc(r.codigo_externo || '') + '</code>' +
                        (r.cantidad_min != null ? '<br><span class="description">envase ≥ ' + esc(String(r.cantidad_min)) + '</span>' : '') +
                    '</td>' +
                    '<td>' + esc(r.tipo_match_label || r.tipo_match || '—') + '</td>' +
                    '<td style="text-align:right">' + amountPair(nuestroBruto, nuestroNeto) + '</td>' +
                    '<td style="text-align:right">' + amountPair(rivalBruto, rivalNeto) + '</td>' +
                    '<td style="text-align:right">' + formatDelta(delta, deltaPct) + '</td>' +
                    '<td>' + esc(r.snapshot_fecha || r.actualizado_at || '—') + '</td>' +
                    '</tr>';
            }).join(''));
        } else {
            $('#rpe-comp-mapeados-wrap').attr('hidden', true);
            $('#rpe-comp-mapeados-body').empty();
        }

        if (sugeridos.length) {
            $('#rpe-comp-sugeridos-wrap').removeAttr('hidden');
            $('#rpe-comp-sugeridos-body').html(sugeridos.map(function (r) {
                var nuestroBruto = r.nuestro_precio;
                var nuestroNeto = r.nuestro_precio_neto;
                var rivalBruto = r.rival_unitario != null ? r.rival_unitario
                    : (r.precio_bruto_unitario != null ? r.precio_bruto_unitario : r.precio);
                var rivalNeto = r.rival_unitario_neto;
                if (rivalNeto == null && rivalBruto != null) {
                    rivalNeto = Math.round((Number(rivalBruto) / 1.19) * 10000) / 10000;
                }
                var delta = r.delta;
                var deltaPct = r.delta_pct;
                if (priceViewMode === 'neto' && nuestroNeto != null && rivalNeto != null) {
                    delta = Math.round((Number(nuestroNeto) - Number(rivalNeto)) * 10000) / 10000;
                    deltaPct = Number(rivalNeto) > 0
                        ? Math.round(((Number(nuestroNeto) / Number(rivalNeto)) - 1) * 10000) / 10000
                        : null;
                }
                var scoreMetodo = '';
                if (r.score != null) {
                    scoreMetodo += Math.round(Number(r.score) * 100) / 100;
                }
                if (r.metodo) {
                    scoreMetodo += (scoreMetodo ? ' · ' : '') + r.metodo;
                }
                return '<tr>' +
                    '<td>' + esc(r.fuente_nombre || r.fuente_slug || '—') +
                        ' <span class="rpe-sug-badge">Sugerido</span></td>' +
                    '<td>' + rivalNameHtml(r) + '</td>' +
                    '<td><code>' + esc(r.codigo_externo || '') + '</code></td>' +
                    '<td>' + esc(scoreMetodo || '—') + '</td>' +
                    '<td style="text-align:right">' + amountPair(nuestroBruto, nuestroNeto) + '</td>' +
                    '<td style="text-align:right">' + amountPair(rivalBruto, rivalNeto) + '</td>' +
                    '<td style="text-align:right">' + formatDelta(delta, deltaPct) + '</td>' +
                    '</tr>';
            }).join(''));
        } else {
            $('#rpe-comp-sugeridos-wrap').attr('hidden', true);
            $('#rpe-comp-sugeridos-body').empty();
        }

        if (familia && familia.length && hasAny) {
            $('#rpe-comp-familia-wrap').removeAttr('hidden');
            var html = familia.map(function (m) {
                var unitBadge = parseInt(m.es_unidad_minima, 10) === 1
                    ? ' <span class="rpe-unit-badge">Unitario</span>'
                    : '';
                var nuestroU = priceViewMode === 'bruto' ? m.precio_unitario : m.precio_unitario_neto;
                var rows = (m.rivales || []).map(function (riv) {
                    var rivalU = riv.rival_unitario;
                    var rivalNeto = rivalU != null
                        ? Math.round((Number(rivalU) / 1.19) * 10000) / 10000
                        : null;
                    var showRival = priceViewMode === 'bruto' ? rivalU : rivalNeto;
                    var delta = riv.delta;
                    var deltaPct = riv.delta_pct;
                    if (priceViewMode === 'neto' && m.precio_unitario_neto != null && rivalNeto != null) {
                        delta = Math.round((Number(m.precio_unitario_neto) - Number(rivalNeto)) * 10000) / 10000;
                        deltaPct = Number(rivalNeto) > 0
                            ? Math.round(((Number(m.precio_unitario_neto) / Number(rivalNeto)) - 1) * 10000) / 10000
                            : null;
                    }
                    return '<tr>' +
                        '<td>' + esc(riv.fuente_nombre || riv.fuente_slug || '—') +
                            (riv.es_sugerido ? ' <span class="rpe-sug-badge">Sugerido</span>' : '') +
                        '</td>' +
                        '<td>' + esc(riv.nombre || '') +
                            (riv.codigo_externo ? ' <code>' + esc(riv.codigo_externo) + '</code>' : '') +
                        '</td>' +
                        '<td style="text-align:right">' + money(showRival) +
                            (riv.cantidad_min != null ? '<br><span class="description">env ≥ ' + esc(String(riv.cantidad_min)) + '</span>' : '') +
                        '</td>' +
                        '<td style="text-align:right">' + formatDelta(delta, deltaPct) + '</td>' +
                        '</tr>';
                }).join('');
                return '<div class="rpe-comp-family-card">' +
                    '<div class="rpe-comp-family-title">' +
                        '<code>' + esc(m.canonical_sku) + '</code> ' + esc(m.nombre_canonico) + unitBadge +
                        ' · ' + (m.cantidad_unidades != null ? m.cantidad_unidades + ' uds' : '—') +
                        ' · P/u ' + money(nuestroU) +
                    '</div>' +
                    '<table class="wp-list-table widefat striped">' +
                        '<thead><tr>' +
                            '<th>Fuente</th><th>Rival</th>' +
                            '<th style="text-align:right">Rival P/u</th>' +
                            '<th style="text-align:right">Δ vs miembro</th>' +
                        '</tr></thead>' +
                        '<tbody>' + (rows || '<tr><td colspan="4">Sin rivales</td></tr>') + '</tbody>' +
                    '</table>' +
                '</div>';
            }).join('');
            $('#rpe-comp-familia-body').html(html);
        } else {
            $('#rpe-comp-familia-wrap').attr('hidden', true);
            $('#rpe-comp-familia-body').empty();
        }
    }

    function renderMiniHistory(rows) {
        if (!rows.length) {
            $('#rpe-hist-body').html('<tr><td colspan="7">Sin historial todavía</td></tr>');
            return;
        }
        $('#rpe-hist-body').html(rows.map(function (h) {
            var origen = h.source_type_label || h.source_type || '—';
            if (h.source_type === 'folio' && (!h.source_type_label || h.source_type_label === 'folio')) {
                origen = 'Revisión de folio';
            }
            return '<tr>' +
                '<td>' + esc(h.created_at) + '</td>' +
                '<td>' + esc(h.canal) + '</td>' +
                '<td>' + originBadgeHtml({ key: h.source_type_key || h.source_type, label: origen }) + '</td>' +
                '<td style="text-align:right">' + money(h.p_asignado_anterior) + '</td>' +
                '<td style="text-align:right">' + money(h.p_asignado_nuevo) + '</td>' +
                '<td style="text-align:right">' + money(h.margen_unitario) + '</td>' +
                '<td>' + esc(h.usuario_nombre || '') + '</td>' +
                '</tr>';
        }).join(''));
    }

    function renderChart(chartData) {
        var canvas = document.getElementById('rpe-chart');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        if (chart) {
            chart.destroy();
            chart = null;
        }
        var local = (chartData && chartData.local) || { labels: [], precios: [] };
        var online = (chartData && chartData.online) || { labels: [], precios: [] };
        var labels = local.labels.length >= online.labels.length ? local.labels : online.labels;
        if (!labels.length) {
            return;
        }
        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Local', data: local.precios, borderColor: 'rgba(33,113,177,1)', tension: 0.2 },
                    { label: 'Online', data: online.precios, borderColor: 'rgba(0,163,42,1)', tension: 0.2, borderDash: [4, 4] }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: false } }
            }
        });
    }

    function currentProductId() {
        return explorerData && explorerData.product ? explorerData.product.producto_base_id : 0;
    }

    function applyExplorer(res) {
        if (res && res.success && res.data && res.data.explorer) {
            renderExplorer(res.data.explorer);
            return;
        }
        if (currentProductId()) {
            loadExplorer(currentProductId());
        }
    }

    /* ===== History tab ===== */

    function loadHistory() {
        $('#ph-history-body').html('<tr><td colspan="9"><span class="spinner is-active"></span> Cargando…</td></tr>');
        post('riverso_price_get_history', {
            page: histPage,
            search: $('#ph-filter-search').val(),
            canal: $('#ph-filter-canal').val(),
            source_type: $('#ph-filter-source').val(),
            date_from: $('#ph-filter-from').val(),
            date_to: $('#ph-filter-to').val()
        }).done(function (res) {
            if (!res || !res.success) {
                $('#ph-history-body').html('<tr><td colspan="9">Error al cargar</td></tr>');
                return;
            }
            var rows = res.data.rows || [];
            if (!rows.length) {
                $('#ph-history-body').html('<tr><td colspan="9">Sin registros</td></tr>');
            } else {
                $('#ph-history-body').html(rows.map(function (h) {
                    return '<tr>' +
                        '<td>' + esc(h.created_at) + '</td>' +
                        '<td><code>' + esc(h.canonical_sku) + '</code></td>' +
                        '<td>' + esc(h.nombre_canonico) + '</td>' +
                        '<td>' + esc(h.canal) + '</td>' +
                        '<td>' + esc(h.source_type) + '</td>' +
                        '<td style="text-align:right">' + money(h.p_asignado_anterior) + '</td>' +
                        '<td style="text-align:right">' + money(h.p_asignado_nuevo) + '</td>' +
                        '<td style="text-align:right">' + money(h.margen_unitario) + '</td>' +
                        '<td>' + esc(h.usuario_nombre || '') + '</td>' +
                        '</tr>';
                }).join(''));
            }
            var pages = res.data.pages || 1;
            $('#ph-history-pagination').toggle(pages > 1);
            $('#ph-hist-page-info').text('Página ' + histPage + ' de ' + pages);
            $('#ph-hist-prev').prop('disabled', histPage <= 1);
            $('#ph-hist-next').prop('disabled', histPage >= pages);
        });
    }

    /* ===== Folio analysis ===== */

    function searchFolios(term) {
        var $box = $('#ph-folio-search-results');
        if (!term) {
            $box.empty();
            return;
        }
        post('riverso_price_search_invoices', { term: term }).done(function (res) {
            var rows = (res.data && res.data.results) || [];
            if (!rows.length) {
                $box.html('<div class="rce-result">Sin facturas</div>');
                return;
            }
            $box.html(rows.map(function (r) {
                return '<button type="button" class="rce-result ph-pick-folio" data-id="' + r.id + '">' +
                    '<strong>Folio ' + esc(r.folio) + '</strong> · ' + esc(r.proveedor_nombre) +
                    ' · ' + esc(r.fecha_emision) + '</button>';
            }).join(''));
        });
    }

    function analyzeFolio(id) {
        $('#ph-folio-results').show();
        $('#ph-folio-title').text('Analizando…');
        $('#ph-folio-body').html('<tr><td colspan="11">Cargando…</td></tr>');
        post('riverso_price_analyze_invoice', { factura_id: id }).done(function (res) {
            if (!res || !res.success) {
                $('#ph-folio-body').html('<tr><td colspan="11">' + esc((res && res.data && res.data.message) || 'Error') + '</td></tr>');
                return;
            }
            renderFolio(res.data);
        });
    }

    function renderFolio(data) {
        var inv = data.invoice || {};
        $('#ph-folio-title').text('Factura N° ' + (inv.folio || ''));
        $('#ph-folio-meta').text((inv.proveedor_nombre || '') + ' · ' + (inv.fecha_emision || ''));
        var summary = data.summary || {};
        $('#ph-folio-header').html(
            '<div class="cost-doc-field"><label>Ítems</label><span>' + (summary.items || 0) + '</span></div>' +
            '<div class="cost-doc-field"><label>Alertas margen</label><span>' + (summary.alerts || 0) + '</span></div>' +
            '<div class="cost-doc-field"><label>Margen promedio</label><span>' + money(summary.margen_promedio) + '</span></div>'
        );
        var rows = data.rows || [];
        $('#ph-folio-body').html(rows.map(function (r) {
            var alert = parseInt(r.alerta_margen, 10) === 1 ? '<span class="rpe-badge rpe-badge-off">Margen bajo</span>' : '—';
            var onlineBadge = parseInt(r.online_en_uso, 10) === 1 ? '' : ' <em>(inactivo)</em>';
            var prev = '—';
            if (r.prev_invoice && (r.prev_invoice.costo_unitario != null || r.prev_invoice.costo != null)) {
                prev = money(r.prev_invoice.costo_unitario != null ? r.prev_invoice.costo_unitario : r.prev_invoice.costo);
            } else if (r.reference_cost != null) {
                prev = money(r.reference_cost);
            }
            return '<tr class="' + (parseInt(r.alerta_margen, 10) === 1 ? 'trend-up' : '') + '">' +
                '<td>' + esc(r.numero_linea) + '</td>' +
                '<td><code>' + esc(r.codigo_proveedor) + '</code></td>' +
                '<td>' + esc(r.nombre) + '</td>' +
                '<td style="text-align:right">' + money(r.costo_actual) + '</td>' +
                '<td style="text-align:right">' + money(r.precio_local) + '</td>' +
                '<td style="text-align:right">' + money(r.precio_online) + onlineBadge + '</td>' +
                '<td style="text-align:right">' + money(r.margen_unitario) + '</td>' +
                '<td style="text-align:right">' + money(r.margen_total) + '</td>' +
                '<td>' + prev + '</td>' +
                '<td>' + esc(r.trend || '') + '</td>' +
                '<td>' + alert + '</td>' +
                '</tr>';
        }).join(''));
    }

    function loadRecentFolios() {
        post('riverso_price_list_recent_invoices', {
            page: folioPage,
            date_field: $('#ph-folio-date-field').val(),
            date_from: $('#ph-folio-from').val(),
            date_to: $('#ph-folio-to').val(),
            proveedor_id: $('#ph-folio-origen').val(),
            search: $('#ph-folio-recent-search').val()
        }).done(function (res) {
            var items = (res.data && res.data.items) || [];
            if (!items.length) {
                $('#ph-folio-recent-body').html('<tr><td colspan="6">Sin folios</td></tr>');
            } else {
                $('#ph-folio-recent-body').html(items.map(function (r) {
                    return '<tr>' +
                        '<td>' + esc(r.folio) + '</td>' +
                        '<td>' + esc(r.origen || r.proveedor_nombre) + '</td>' +
                        '<td>' + esc(r.fecha_emision) + '</td>' +
                        '<td>' + esc(r.items_count) + '</td>' +
                        '<td style="text-align:right">' + money(r.monto_total) + '</td>' +
                        '<td><button type="button" class="button button-small ph-pick-folio" data-id="' + r.id + '">Analizar</button></td>' +
                        '</tr>';
                }).join(''));
            }
            var pages = (res.data && res.data.pages) || 1;
            $('#ph-folio-recent-pagination').toggle(pages > 1);
            $('#ph-folio-page-info').text('Página ' + folioPage + ' de ' + pages);
            $('#ph-folio-prev').prop('disabled', folioPage <= 1);
            $('#ph-folio-next').prop('disabled', folioPage >= pages);
        });
    }

    function loadAnalyzed() {
        post('riverso_price_list_analyzed_folios', {
            page: analyzedPage,
            search: $('#ph-analyzed-search').val()
        }).done(function (res) {
            var rows = (res.data && res.data.rows) || [];
            if (!rows.length) {
                $('#ph-analyzed-body').html('<tr><td colspan="8">Aún no hay folios analizados</td></tr>');
            } else {
                $('#ph-analyzed-body').html(rows.map(function (r) {
                    var s = r.summary || {};
                    return '<tr>' +
                        '<td>' + esc(r.folio) + '</td>' +
                        '<td>' + esc(r.proveedor_nombre) + '</td>' +
                        '<td>' + esc(r.fecha_emision) + '</td>' +
                        '<td>' + esc(r.analyzed_at) + '</td>' +
                        '<td>' + esc(r.analyzed_by_name) + '</td>' +
                        '<td>' + esc(s.items) + '</td>' +
                        '<td>' + esc(s.alerts) + '</td>' +
                        '<td><button type="button" class="button button-small ph-open-analyzed" data-id="' + r.id + '">Ver</button></td>' +
                        '</tr>';
                }).join(''));
            }
            var total = (res.data && res.data.total) || 0;
            var per = (res.data && res.data.per_page) || 25;
            var pages = Math.max(1, Math.ceil(total / per));
            $('#ph-analyzed-pagination').toggle(pages > 1);
            $('#ph-analyzed-page-info').text('Página ' + analyzedPage + ' de ' + pages);
            $('#ph-analyzed-prev').prop('disabled', analyzedPage <= 1);
            $('#ph-analyzed-next').prop('disabled', analyzedPage >= pages);
        });
    }

    function loadAlerts() {
        post('riverso_price_alerts').done(function (res) {
            var rows = (res.data && res.data.alerts) || [];
            if (!rows.length) {
                $('#ph-alerts-body').html('<tr><td colspan="7">Sin alertas de margen</td></tr>');
                return;
            }
            $('#ph-alerts-body').html(rows.map(function (r) {
                var uso = r.canal === 'online'
                    ? (parseInt(r.en_uso, 10) === 1 ? 'En uso' : 'Guardado (no usado)')
                    : 'Local';
                return '<tr>' +
                    '<td><code>' + esc(r.canonical_sku) + '</code></td>' +
                    '<td>' + esc(r.nombre_canonico) + '</td>' +
                    '<td>' + esc(r.canal) + '</td>' +
                    '<td style="text-align:right">' + money(r.c_ref) + '</td>' +
                    '<td style="text-align:right">' + money(r.p_asignado) + '</td>' +
                    '<td>' + esc(r.estado_aprobacion) + '</td>' +
                    '<td>' + esc(uso) + '</td>' +
                    '</tr>';
            }).join(''));
        });
    }

    /* ===== Bind ===== */

    $(function () {
        $('.riverso-price-history-app .nav-tab').on('click', function (e) {
            e.preventDefault();
            switchTab($(this).data('tab'));
        });

        updateViewHints();

        $('.rpe-view-btn').on('click', function () {
            var view = $(this).data('view');
            if (view !== 'bruto' && view !== 'neto') {
                return;
            }
            // Ignorar botones del folio (se manejan por delegación).
            if ($(this).closest('#rpf-view-toggle').length) {
                return;
            }
            priceViewMode = view;
            refreshExplorerAmounts();
        });

        $(document).on('click', '.rpe-cost-toggle .rpe-cost-btn', function (e) {
            e.preventDefault();
            var mode = $(this).data('cost');
            if (mode !== 'referencia' && mode !== 'tras_dr') {
                return;
            }
            if (explorerCostMode === mode) {
                updateViewHints();
                return;
            }
            explorerCostMode = mode;
            refreshExplorerAmounts();
        });

        $(document).on('click', '#rpf-view-toggle .rpf-view-btn', function (e) {
            e.preventDefault();
            var view = $(this).data('view');
            switchRpfViewMode(view);
            if (rpfSession && $('#rpf-session-header .description').length) {
                var $d = $('#rpf-session-header .description');
                $d.html(String($d.html()).replace(
                    /Montos en <strong>[^<]+<\/strong>/,
                    'Montos en <strong>' + (rpfViewMode === 'neto' ? 'neto' : 'bruto') + '</strong>'
                ));
            }
        });

        $(document).on('click', '#rpf-cost-toggle .rpf-cost-btn', function (e) {
            e.preventDefault();
            if ($(this).prop('disabled')) {
                return;
            }
            var mode = $(this).data('cost');
            if (mode !== 'referencia' && mode !== 'tras_dr' && mode !== 'tras_dr_flete') {
                return;
            }
            if (mode === 'tras_dr_flete' && !rpfInvoiceFleteOk()) {
                return;
            }
            if (mode === rpfCostMode) {
                updateRpfViewToggle();
                return;
            }
            rpfCostMode = mode;
            updateRpfViewToggle();
            if (rpfSession) {
                renderProcessLines(rpfSession.lines || [], !!rpfSession.is_hybrid);
            }
        });

        $(document).on('click', '#rpf-btn-flete, .rpf-open-flete-modal', function (e) {
            e.preventDefault();
            openRpfFleteModal();
        });

        $(document).on('click', '#rpf-btn-ver-pdf', function (e) {
            e.preventDefault();
            var adjuntos = $('#rpf-pdf-footer').data('adjuntos') || [];
            if (!adjuntos.length) {
                return;
            }
            var $wrap = $('#rpf-pdf-viewer-wrap');
            var $iframe = $('#rpf-pdf-viewer');
            if ($wrap.is(':visible')) {
                $wrap.hide();
                $iframe.attr('src', 'about:blank');
                return;
            }
            $iframe.attr('src', adjuntos[0].url);
            $wrap.show();
        });

        $(document).on('click', '.rpf-flete-modal-close, .rpf-flete-modal-backdrop', function () {
            closeRpfFleteModal();
        });

        $(document).on('click', '#rpf-flete-btn-assign', function () {
            var envioId = $('#rpf-flete-assign-id').val();
            if (!envioId || !rpfFacturaId) {
                window.alert('Busque y seleccione un flete');
                return;
            }
            post('riverso_assign_shipping_invoice', {
                factura_productos_id: rpfFacturaId,
                factura_envio_id: envioId
            }).done(function (res) {
                if (!res.success) {
                    window.alert((res.data && res.data.message) || 'Error al vincular');
                    return;
                }
                closeRpfFleteModal();
                refreshSession();
            });
        });

        $(document).on('click', '#rpf-flete-btn-manual', function () {
            var monto = $('#rpf-flete-manual-monto').val();
            if (!rpfFacturaId) {
                return;
            }
            post('riverso_set_manual_shipping', {
                factura_id: rpfFacturaId,
                monto: monto
            }).done(function (res) {
                if (!res.success) {
                    window.alert((res.data && res.data.message) || 'Error al guardar flete manual');
                    return;
                }
                closeRpfFleteModal();
                refreshSession();
            });
        });

        $(document).on('click', '#rpf-flete-btn-gratis', function () {
            if (!rpfFacturaId) {
                return;
            }
            if (!window.confirm('¿Marcar esta factura como flete gratuito?')) {
                return;
            }
            post('riverso_mark_free_shipping', {
                factura_id: rpfFacturaId,
                gratuito: 1
            }).done(function (res) {
                if (!res.success) {
                    window.alert((res.data && res.data.message) || 'Error');
                    return;
                }
                closeRpfFleteModal();
                refreshSession();
            });
        });

        $(document).on('click', '#rpf-flete-btn-ungratis', function () {
            if (!rpfFacturaId) {
                return;
            }
            post('riverso_mark_free_shipping', {
                factura_id: rpfFacturaId,
                gratuito: 0
            }).done(function (res) {
                if (!res.success) {
                    window.alert((res.data && res.data.message) || 'Error');
                    return;
                }
                closeRpfFleteModal();
                refreshSession();
            });
        });

        $(document).on('click', '.rpf-flete-unassign', function () {
            var envioId = $(this).data('envio-id');
            if (!envioId || !rpfFacturaId) {
                return;
            }
            if (!window.confirm('¿Desvincular este flete?')) {
                return;
            }
            post('riverso_unassign_shipping_invoice', {
                factura_envio_id: envioId,
                factura_productos_id: rpfFacturaId
            }).done(function (res) {
                if (!res.success) {
                    window.alert((res.data && res.data.message) || 'Error');
                    return;
                }
                openRpfFleteModal();
                refreshSession();
            });
        });

        $('#rpe-search-input').on('input', function () {
            var term = $(this).val();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                doSearch(term, $('#rpe-search-results'));
            }, 280);
        });
        $('#rpe-btn-search').on('click', function () {
            doSearch($('#rpe-search-input').val(), $('#rpe-search-results'));
        });
        $(document).on('click', '#rpe-search-results .rpe-pick', function () {
            loadExplorer($(this).data('id'));
        });
        $('#rpe-btn-clear').on('click', function () {
            explorerData = null;
            $('#rpe-product-panel').attr('hidden', true);
            $('#rpe-empty-state').show();
            $('#rpe-search-input').val('');
        });

        $('#rpe-local-asignado, #rpe-online-asignado').on('input', function () {
            var canal = this.id.indexOf('online') !== -1 ? 'online' : 'local';
            var bruto = $(this).val();
            var ivaTipo = (explorerData && explorerData.product && explorerData.product.facto_iva_tipo) || 'afecto';
            var neto = '';
            if (bruto !== '' && !isNaN(bruto)) {
                neto = String(ivaTipo).toLowerCase() === 'exento'
                    ? Number(bruto)
                    : Math.round((Number(bruto) / 1.19) * 10000) / 10000;
            }
            $('#' + 'rpe-' + canal + '-neto').text(neto === '' ? '—' : money(neto));
            var $alt = $('#' + 'rpe-' + canal + '-asignado-alt');
            if (neto === '') {
                $alt.text('').hide();
            } else if (priceViewMode === 'bruto') {
                $alt.text('(neto ' + money(neto) + ')').show();
            } else {
                $alt.text('(bruto ' + money(bruto) + ')').show();
            }
            // Recalcular margen en vivo: P bruto / C bruto.
            var cBruto = null;
            if (explorerData && explorerData[canal]) {
                cBruto = explorerData[canal].c_ref_bruto != null
                    ? explorerData[canal].c_ref_bruto
                    : explorerData[canal].c_ref;
            }
            if (bruto !== '' && !isNaN(bruto) && cBruto != null && Number(cBruto) > 0) {
                var factor = Math.round((Number(bruto) / Number(cBruto)) * 10000) / 10000;
                var delta = Math.round((Number(bruto) - Number(cBruto)) * 10000) / 10000;
                $('#' + 'rpe-' + canal + '-margen').text(
                    money(bruto) + ' / ' + money(cBruto) + ' = ' + factor.toFixed(4) + '× · Δ ' + money(delta)
                );
            }
        });

        $('.rpe-save').on('click', function () {
            if (!canManage || !currentProductId()) {
                return;
            }
            var canal = $(this).data('canal');
            openSaveConfirmModal(canal);
        });

        $(document).on('click', '.rpe-save-cancel, .rpe-save-backdrop', function () {
            closeSaveConfirmModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#rpe-save-modal').is(':visible')) {
                closeSaveConfirmModal();
            }
        });

        $('#rpe-save-confirm').on('click', function () {
            if (!pendingSaveCanal || !canManage || !currentProductId()) {
                closeSaveConfirmModal();
                return;
            }
            var canal = pendingSaveCanal;
            var val = canal === 'online' ? $('#rpe-online-asignado').val() : $('#rpe-local-asignado').val();
            var $btn = $(this).prop('disabled', true);
            post('riverso_price_save_assigned', {
                producto_base_id: currentProductId(),
                canal: canal,
                p_asignado: val
            }).done(function (res) {
                $btn.prop('disabled', false);
                closeSaveConfirmModal();
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'No se pudo guardar');
                    return;
                }
                applyExplorer(res);
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('No se pudo guardar');
            });
        });

        $('#rpe-copy-local').on('click', function () {
            if (!canManage || !currentProductId()) {
                return;
            }
            post('riverso_price_copy_local_to_online', { producto_base_id: currentProductId() })
                .done(function (res) {
                    if (!res || !res.success) {
                        window.alert((res && res.data && res.data.message) || 'No se pudo copiar');
                        return;
                    }
                    applyExplorer(res);
                });
        });

        $('#rpe-activate-online').on('click', function () {
            if ((!canApprove && !canManage) || !currentProductId()) {
                return;
            }
            post('riverso_price_set_online_active', { producto_base_id: currentProductId(), sync_woo: 0 })
                .done(function (res) {
                    if (!res || !res.success) {
                        window.alert((res && res.data && res.data.message) || 'No se pudo activar');
                        return;
                    }
                    applyExplorer(res);
                });
        });

        $('#ph-btn-filter').on('click', function () {
            histPage = 1;
            loadHistory();
        });
        $('#ph-btn-filter-clear').on('click', function () {
            $('#ph-filter-search, #ph-filter-from, #ph-filter-to').val('');
            $('#ph-filter-canal, #ph-filter-source').val('');
            histPage = 1;
            loadHistory();
        });
        $('#ph-hist-prev').on('click', function () {
            histPage = Math.max(1, histPage - 1);
            loadHistory();
        });
        $('#ph-hist-next').on('click', function () {
            histPage += 1;
            loadHistory();
        });

        $('#ph-btn-search-folio').on('click', function () {
            searchFolios($('#ph-folio-search').val());
        });
        $('#ph-folio-search').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchFolios($(this).val());
            }
        });
        $(document).on('click', '.ph-pick-folio', function () {
            analyzeFolio($(this).data('id'));
            switchTab('analysis');
        });
        $('#ph-folio-recent-apply').on('click', function () {
            folioPage = 1;
            loadRecentFolios();
        });
        $('#ph-folio-prev').on('click', function () {
            folioPage = Math.max(1, folioPage - 1);
            loadRecentFolios();
        });
        $('#ph-folio-next').on('click', function () {
            folioPage += 1;
            loadRecentFolios();
        });

        $('#ph-analyzed-apply').on('click', function () {
            analyzedPage = 1;
            loadAnalyzed();
        });
        $('#ph-analyzed-prev').on('click', function () {
            analyzedPage = Math.max(1, analyzedPage - 1);
            loadAnalyzed();
        });
        $('#ph-analyzed-next').on('click', function () {
            analyzedPage += 1;
            loadAnalyzed();
        });
        $(document).on('click', '.ph-open-analyzed', function () {
            var id = $(this).data('id');
            post('riverso_price_list_analyzed_folios', { id: id }).done(function (res) {
                if (!res || !res.success || !res.data.payload) {
                    window.alert('No se pudo reabrir el análisis');
                    return;
                }
                switchTab('analysis');
                renderFolio(res.data.payload);
                $('#ph-folio-results').show();
            });
        });

        $('#ph-refresh-alerts').on('click', loadAlerts);

        var addTimer;
        $('#ph-add-search').on('input', function () {
            var term = $(this).val();
            clearTimeout(addTimer);
            addTimer = setTimeout(function () {
                doSearch(term, $('#ph-add-results'));
            }, 280);
        });
        $(document).on('click', '#ph-add-results .rpe-pick', function () {
            addProductId = $(this).data('id');
            $('#ph-add-selected').text('Seleccionado: ' + $(this).text());
            $('#ph-add-results').empty();
        });
        $('#ph-add-save').on('click', function () {
            if (!canManage || !addProductId) {
                window.alert('Selecciona un producto');
                return;
            }
            post('riverso_price_add_entry', {
                producto_base_id: addProductId,
                canal: $('#ph-add-canal').val(),
                p_asignado: $('#ph-add-price').val(),
                notas: $('#ph-add-notes').val()
            }).done(function (res) {
                if (!res || !res.success) {
                    $('#ph-add-msg').text((res && res.data && res.data.message) || 'Error');
                    return;
                }
                $('#ph-add-msg').text('Precio guardado.');
            });
        });

        $('#rpf-refresh').on('click', function () {
            rpfPage = 1;
            loadProcessList();
        });
        $(document).on('click', '.rpf-vista-btn', function () {
            var vista = $(this).data('vista') || 'activos';
            if (vista === rpfVista) return;
            rpfVista = vista;
            $('.rpf-vista-btn').removeClass('is-active button-primary');
            $(this).addClass('is-active button-primary');
            rpfPage = 1;
            loadProcessList();
        });
        $('#rpf-vista-activos').addClass('button-primary');
        $('#rpf-filter-estado, #rpf-filter-completitud, #rpf-filter-order').on('change', function () {
            rpfPage = 1;
            loadProcessList();
        });
        $('#rpf-filter-folio-desde, #rpf-filter-folio-hasta, #rpf-filter-ingreso-desde, #rpf-filter-ingreso-hasta')
            .on('change', function () {
                rpfPage = 1;
                loadProcessList();
            });
        var rpfListSearchTimer = null;
        $('#rpf-filter-search, #rpf-filter-producto').on('input', function () {
            clearTimeout(rpfListSearchTimer);
            rpfListSearchTimer = setTimeout(function () {
                rpfPage = 1;
                loadProcessList();
            }, 320);
        });
        $('#rpf-prev').on('click', function () {
            rpfPage = Math.max(1, rpfPage - 1);
            loadProcessList();
        });
        $('#rpf-next').on('click', function () {
            rpfPage += 1;
            loadProcessList();
        });
        $('#rpf-back').on('click', backToList);
        $('#rpf-refresh-session').on('click', refreshSession);
        $(document).on('click', '.rpf-start', function () {
            var id = $(this).data('id');
            post('riverso_price_folio_process_start', { factura_id: id }).done(function (res) {
                if (!res || !res.success) {
                    if (res && res.data && res.data.session) {
                        showSession(res.data.session);
                        return;
                    }
                    if (res && res.data && res.data.code === 'blocked') {
                        openFolioSession(id);
                        return;
                    }
                    window.alert((res && res.data && res.data.message) || 'No se pudo iniciar');
                    loadProcessList();
                    return;
                }
                showSession(res.data);
            });
        });
        $(document).on('click', '.rpf-open, .rpf-que-falta', function () {
            openFolioSession($(this).data('id'));
        });
        $(document).on('click', '.rpf-prior-origin', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var url = String($(this).data('url') || '');
            var label = String($(this).data('label') || $(this).attr('title') || '');
            var folio = String($(this).data('folio') || '');
            if (url) {
                if (window.confirm((folio ? 'Folio #' + folio + '\n' : '') + label + '\n\n¿Abrir ese folio?')) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                return;
            }
            if (label) {
                window.alert(label);
            }
        });
        $(document).on('click', '.rpf-manual', function () {
            if (!canManage) return;
            var id = $(this).data('id');
            var estado = $(this).data('estado');
            if (estado === 'ingresada_manual') {
                openHybridWizard(id);
                return;
            }
            var label = estado === 'anulada' ? 'Anulada' : 'automático';
            if (!window.confirm('¿Marcar folio como ' + label + '?')) return;
            post('riverso_price_folio_process_set_estado', { factura_id: id, estado: estado || '' }).done(function (res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'Error');
                    return;
                }
                loadProcessList();
            });
        });
        $(document).on('click', '.rpf-archive', function () {
            if (!canManage) return;
            var id = $(this).data('id');
            var unarchive = String($(this).data('unarchive') || '') === '1';
            var msg = unarchive
                ? '¿Desarchivar este folio? Volverá a la lista de Activos.'
                : '¿Archivar este folio? Podrás verlo en Archivados.';
            if (!window.confirm(msg)) return;
            post('riverso_price_folio_process_archive', {
                factura_id: id,
                unarchive: unarchive ? 1 : 0
            }).done(function (res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'Error');
                    return;
                }
                loadProcessList();
            });
        });
        $(document).on('click', '.rpf-save-line', function () {
            if (!canManage || !rpfFacturaId) return;
            var itemId = $(this).data('item');
            var $row = $('#rpf-lines tr[data-item="' + itemId + '"]');
            var pLocal = $row.find('.rpf-p-local').val();
            var pOnline = $row.find('.rpf-p-online').val();
            var $btn = $(this).prop('disabled', true);
            post('riverso_price_folio_process_save_line', {
                factura_id: rpfFacturaId,
                item_id: itemId,
                p_asignado: pLocal,
                p_online: pOnline || '',
                amount_mode: rpfViewMode
            }).done(function (res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'No se pudo guardar');
                    return;
                }
                if (res.data.session) {
                    showSession(res.data.session);
                }
                if (res.data.completed) {
                    window.alert('Folio completado: todos los productos tienen precio confirmado desde este folio.');
                } else if (res.data.historial_only || res.data.applied === false) {
                    window.alert('Constancia en historial guardada. El precio vigente no cambió (hay un folio más reciente).');
                }
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
        $(document).on('input', '.rpf-p-local', function () {
            var $row = $(this).closest('tr');
            rpfSyncFromPrice($row);
            var $online = $row.find('.rpf-p-online');
            if ($online.length && !rpfSyncingMargin) {
                $online.val($(this).val());
            }
        });
        $(document).on('input', '.rpf-m-act', function () {
            rpfSyncFromMargin($(this).closest('tr'));
        });
        $(document).on('input', '.rpf-f-act', function () {
            rpfSyncFromFactor($(this).closest('tr'));
        });
        $(document).on('click', '.rpf-family', function () {
            openFamilyModal($(this).data('grupo'), $(this).data('item'));
        });
        $('#rpf-complete').on('click', function () {
            if (!canManage || !rpfFacturaId) return;
            var isHybrid = !!(rpfSession && rpfSession.is_hybrid);
            if (isHybrid) {
                if (!window.confirm('¿Está seguro de que el folio está completamente procesado?')) {
                    return;
                }
            }
            post('riverso_price_folio_process_complete', { factura_id: rpfFacturaId }).done(function (res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'Aún incompleto');
                    return;
                }
                if (res.data && res.data.session) {
                    showSession(res.data.session);
                }
                window.alert(isHybrid
                    ? 'Folio híbrido marcado como completado.'
                    : 'Folio marcado como ingresada.');
                if (isHybrid) {
                    backToList();
                    loadProcessList();
                }
            });
        });
        $('#rpf-archive-session').on('click', function () {
            if (!canManage || !rpfFacturaId) return;
            var unarchive = String($(this).attr('data-unarchive') || '') === '1';
            var msg = unarchive
                ? '¿Desarchivar este folio?'
                : '¿Archivar este folio?';
            if (!window.confirm(msg)) return;
            var $btn = $(this).prop('disabled', true);
            post('riverso_price_folio_process_archive', {
                factura_id: rpfFacturaId,
                unarchive: unarchive ? 1 : 0
            }).done(function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'Error');
                    return;
                }
                window.alert(unarchive ? 'Folio desarchivado.' : 'Folio archivado.');
                if (!unarchive) {
                    backToList();
                    loadProcessList();
                    return;
                }
                openFolioSession(rpfFacturaId);
            }).fail(function () {
                $btn.prop('disabled', false);
                window.alert('Error de red');
            });
        });
        $(document).on('change', '.rpf-session-omit-ticket', function () {
            if (!canManage || !rpfFacturaId) return;
            var ids = [];
            $('#rpf-lines .rpf-session-omit-ticket:checked').each(function () {
                ids.push(parseInt($(this).val(), 10));
            });
            var $tickets = $('#rpf-lines .rpf-session-omit-ticket').prop('disabled', true);
            post('riverso_price_folio_process_update_omitidos', {
                factura_id: rpfFacturaId,
                item_ids: JSON.stringify(ids)
            }).done(function (res) {
                if (!res || !res.success) {
                    window.alert((res && res.data && res.data.message) || 'No se pudo actualizar');
                    refreshSession();
                    return;
                }
                if (res.data && res.data.session) {
                    showSession(res.data.session);
                } else {
                    refreshSession();
                }
            }).fail(function () {
                window.alert('Error de red');
                $tickets.prop('disabled', false);
            });
        });
        $('.rpf-modal-close, .rpf-modal-backdrop').on('click', function () {
            $('#rpf-family-modal').hide().attr('aria-hidden', 'true');
        });
        $(document).on('click', '.rpf-create-local-open', function () {
            if (!canCreateLocal || !rpfFacturaId) {
                return;
            }
            openCreateLocalModal({
                item_id: parseInt($(this).data('item'), 10) || 0,
                codigo_proveedor: String($(this).data('codigo') || ''),
                nombre: String($(this).data('nombre') || '')
            });
        });
        $(document).on('click', '.rpf-create-local-close, .rpf-create-local-backdrop', function () {
            closeCreateLocalModal();
        });
        $(document).on('click', '#rpf-create-local-cancel', function () {
            if (rpfCreateLocal && rpfCreateLocal.step === 'confirm') {
                renderCreateLocalForm();
                return;
            }
            closeCreateLocalModal();
        });
        $(document).on('click', '#rpf-create-local-save', function () {
            var name = ($('#rpf-create-local-name').val() || '').trim();
            if (!name) {
                window.alert('Escribí un nombre para el producto');
                $('#rpf-create-local-name').trigger('focus');
                return;
            }
            if (rpfCreateLocal) {
                rpfCreateLocal.nombre = name;
            }
            renderCreateLocalConfirm();
        });
        $(document).on('click', '#rpf-create-local-confirm', function () {
            submitCreateLocalAndLink();
        });
        $(document).on('click', '.rpf-search-link-open', function () {
            if (!canLinkSku || !rpfFacturaId) {
                return;
            }
            openSearchLinkModal({
                item_id: parseInt($(this).data('item'), 10) || 0,
                codigo_proveedor: String($(this).data('codigo') || ''),
                nombre: String($(this).data('nombre') || '')
            });
        });
        $(document).on('click', '.rpf-search-link-close, .rpf-search-link-backdrop', function () {
            closeSearchLinkModal();
        });
        $(document).on('click', '#rpf-search-link-cancel', function () {
            if (!rpfSearchLink) {
                return;
            }
            if (rpfSearchLink.step === 'confirm' || rpfSearchLink.step === 'detail') {
                rpfSearchLink.selected = null;
                rpfSearchLink.detail = null;
                renderSearchLinkSearch();
                return;
            }
            closeSearchLinkModal();
        });
        $(document).on('input', '#rpf-search-link-q', function () {
            if (!rpfSearchLink || rpfSearchLink.step !== 'search') {
                return;
            }
            rpfSearchLink.query = String($(this).val() || '');
            scheduleSearchLinkFetch(true);
        });
        $(document).on('click', '#rpf-search-link-go', function () {
            if (!rpfSearchLink) {
                return;
            }
            rpfSearchLink.query = String($('#rpf-search-link-q').val() || '');
            fetchSearchLinkResults(true);
        });
        $(document).on('click', '#rpf-search-link-more', function () {
            fetchSearchLinkResults(false);
        });
        $(document).on('click', '.rpf-search-link-view', function () {
            var id = parseInt($(this).data('id'), 10) || 0;
            if (!id) {
                return;
            }
            openSearchLinkDetail(id);
        });
        $(document).on('click', '.rpf-search-link-pick', function () {
            var id = parseInt($(this).data('id'), 10) || 0;
            var sku = String($(this).data('sku') || '');
            var nombre = String($(this).data('nombre') || '');
            if (!id || !sku) {
                window.alert('Este producto no tiene SKU local para vincular. Usá Crear local o Vincular online.');
                return;
            }
            rpfSearchLink.selected = {
                id: id,
                sku: sku,
                nombre: nombre
            };
            renderSearchLinkConfirm();
        });
        $(document).on('click', '#rpf-search-link-confirm', function () {
            submitSearchLink(false);
        });
        $(document).on('click', '#rpf-search-link-force', function () {
            submitSearchLink(true);
        });
        $(document).on('click', '.rpf-answer-family-open', function () {
            if (!canAnswerFamily) {
                return;
            }
            openAnswerFamilyModal({
                producto_base_id: parseInt($(this).data('producto'), 10) || 0,
                sku: String($(this).data('sku') || ''),
                nombre: String($(this).data('nombre') || '')
            });
        });
        $(document).on('click', '.rpf-answer-family-close, .rpf-answer-family-backdrop', function () {
            closeAnswerFamilyModal();
        });
        $(document).on('click', '#rpf-answer-family-cancel', function () {
            if (rpfAnswerFamily && rpfAnswerFamily.step === 'confirm') {
                renderAnswerFamilyForm();
                return;
            }
            closeAnswerFamilyModal();
        });
        $(document).on('click', '#rpf-answer-family-yes', function () {
            if (!rpfAnswerFamily) {
                return;
            }
            rpfAnswerFamily.needs_family = true;
            renderAnswerFamilyConfirm();
        });
        $(document).on('click', '#rpf-answer-family-no', function () {
            if (!rpfAnswerFamily) {
                return;
            }
            rpfAnswerFamily.needs_family = false;
            renderAnswerFamilyConfirm();
        });
        $(document).on('click', '#rpf-answer-family-confirm', function () {
            submitAnswerFamily();
        });
        $(document).on('click', '.rpf-comp-open', function () {
            openRpfCompModal({
                producto_base_id: parseInt($(this).data('producto'), 10) || 0,
                sku: String($(this).data('sku') || ''),
                nombre: String($(this).data('nombre') || '')
            });
        });
        $(document).on('click', '.rpf-comp-close, .rpf-comp-backdrop', function () {
            closeRpfCompModal();
        });
        $(document).on('click', '.rpf-bc-open', function () {
            openRpfBcModal({
                producto_base_id: parseInt($(this).data('producto'), 10) || 0,
                sku: String($(this).data('sku') || ''),
                nombre: String($(this).data('nombre') || '')
            });
        });
        $(document).on('click', '.rpf-bc-close, .rpf-bc-backdrop', function () {
            closeRpfBcModal();
        });
        $(document).on('change', '#rpf-bc-type', function () {
            rpfBcSyncSupplierVisibility();
        });
        $(document).on('click', '#rpf-bc-save', function () {
            submitRpfBcAdd();
        });
        $(document).on('click', '#rpf-comp-google', function () {
            var nombre = (rpfCompCtx && rpfCompCtx.nombre) || '';
            if (!nombre) {
                window.alert('No hay nombre de producto para buscar.');
                return;
            }
            window.open(rpfGoogleUrl(nombre), '_blank', 'noopener,noreferrer');
        });
        $(document).on('click', '#rpf-comp-save', function () {
            submitRpfCompManual();
        });
        $(document).on('change input', '#rpf-comp-unidad, #rpf-comp-tipo-match', function () {
            if (!rpfCompCtx || !canManageCompetencia) {
                return;
            }
            if ($(this).is('#rpf-comp-unidad')) {
                rpfCompRefreshUnitContext();
            } else {
                rpfCompRenderTipoWarnings(rpfCompCtx.unit_context, $('#rpf-comp-tipo-match').val() || '');
            }
        });
        $(document).on('click', '.rpf-hybrid-close, .rpf-hybrid-backdrop', function () {
            closeHybridWizard();
        });
        $(document).on('click', '#rpf-hybrid-choice-complete', function () {
            renderHybridConfirmStep();
        });
        $(document).on('click', '#rpf-hybrid-choice-partial', function () {
            loadHybridItemsStep();
        });
        $(document).on('click', '#rpf-hybrid-back-choice', function () {
            renderHybridChoiceStep();
        });
        $(document).on('click', '#rpf-hybrid-confirm-yes', function () {
            confirmHybridComplete();
        });
        $(document).on('click', '#rpf-hybrid-continue', function () {
            submitHybridPartial();
        });
        $(document).on('change', '.rpf-hybrid-ticket', function () {
            updateHybridContinueState();
        });
        $(document).on('click', '#rpf-hybrid-select-all', function () {
            $('#rpf-hybrid-items .rpf-hybrid-ticket').prop('checked', true);
            updateHybridContinueState();
        });
        $(document).on('click', '#rpf-hybrid-select-none', function () {
            $('#rpf-hybrid-items .rpf-hybrid-ticket').prop('checked', false);
            updateHybridContinueState();
        });

        loadProcessList();

        // Deep-link: ?tab=process&factura_id=N
        try {
            var params = new URLSearchParams(window.location.search || '');
            var deepTab = params.get('tab') || '';
            var deepFactura = parseInt(params.get('factura_id') || '0', 10) || 0;
            if (deepTab === 'process' || deepFactura > 0) {
                switchTab('process');
                if (deepFactura > 0) {
                    openFolioSession(deepFactura);
                }
            }
        } catch (err) {
            // ignore
        }
    });

    function closeHybridWizard() {
        rpfHybridFacturaId = 0;
        rpfHybridLines = [];
        $('#rpf-hybrid-modal').hide().attr('aria-hidden', 'true');
        $('#rpf-hybrid-body').empty();
        $('#rpf-hybrid-footer').hide().empty();
    }

    function sanitizeSupplierCodeForName(codigoProveedor) {
        var raw = String(codigoProveedor || '').trim();
        if (!/^0{5,}/.test(raw)) {
            return raw;
        }
        var stripped = raw.replace(/^0+/, '');
        return stripped || raw;
    }

    function suggestedLocalProductName(descripcion, codigoProveedor) {
        var desc = String(descripcion || '').trim();
        var code = sanitizeSupplierCodeForName(codigoProveedor);
        if (desc && code) {
            return desc + ' (' + code + ')';
        }
        return desc || code;
    }

    function closeCreateLocalModal() {
        rpfCreateLocal = null;
        $('#rpf-create-local-modal').hide().attr('aria-hidden', 'true');
        $('#rpf-create-local-body').empty();
        $('#rpf-create-local-footer').empty();
        $('#rpf-create-local-title').text('Nuevo producto local');
    }

    function openCreateLocalModal(opts) {
        opts = opts || {};
        var inv = (rpfSession && rpfSession.invoice) || {};
        var itemId = opts.item_id || 0;
        if (!itemId) {
            window.alert('Ítem no válido');
            return;
        }
        rpfCreateLocal = {
            step: 'form',
            item_id: itemId,
            codigo_proveedor: opts.codigo_proveedor || '',
            descripcion: opts.nombre || '',
            nombre: suggestedLocalProductName(opts.nombre, opts.codigo_proveedor),
            folio: inv.folio || '',
            sku: '',
            loadingSku: true
        };
        $('#rpf-create-local-modal').css('display', 'flex').attr('aria-hidden', 'false');
        renderCreateLocalForm();
        post('riverso_products_next_sku', {}).done(function (res) {
            if (!rpfCreateLocal) {
                return;
            }
            rpfCreateLocal.loadingSku = false;
            if (res && res.success && res.data && res.data.next_sku) {
                rpfCreateLocal.sku = String(res.data.next_sku);
            } else {
                rpfCreateLocal.sku = '';
                window.alert((res && res.data && res.data.message) || 'No se pudo obtener el próximo SKU');
            }
            if (rpfCreateLocal.step === 'form') {
                renderCreateLocalForm();
            }
        }).fail(function () {
            if (!rpfCreateLocal) {
                return;
            }
            rpfCreateLocal.loadingSku = false;
            rpfCreateLocal.sku = '';
            if (rpfCreateLocal.step === 'form') {
                renderCreateLocalForm();
            }
            window.alert('Error de red al pedir el SKU');
        });
    }

    function renderCreateLocalForm() {
        if (!rpfCreateLocal) {
            return;
        }
        rpfCreateLocal.step = 'form';
        $('#rpf-create-local-title').text('Nuevo producto local');
        var skuLabel = rpfCreateLocal.loadingSku
            ? 'Cargando…'
            : (rpfCreateLocal.sku || '—');
        $('#rpf-create-local-body').html(
            '<dl class="rpf-create-local-meta">' +
            '<dt>Folio</dt><dd><code>' + esc(rpfCreateLocal.folio || '—') + '</code></dd>' +
            '<dt>Código proveedor</dt><dd><code>' + esc(rpfCreateLocal.codigo_proveedor || '—') + '</code></dd>' +
            '<dt>Descripción de la fila</dt><dd>' + esc(rpfCreateLocal.descripcion || rpfCreateLocal.nombre || '—') + '</dd>' +
            '<dt>SKU local a crear</dt><dd><code id="rpf-create-local-sku-preview">' + esc(skuLabel) + '</code>' +
            ' <span class="description">(numérico, no uses el código proveedor)</span></dd>' +
            '</dl>' +
            '<div class="rpf-create-local-field">' +
            '<label for="rpf-create-local-name">Nombre del producto</label>' +
            '<input type="text" id="rpf-create-local-name" class="large-text" ' +
            'value="' + esc(rpfCreateLocal.nombre || '') + '" autocomplete="off">' +
            '<p class="description">Sugerido como descripción + (código proveedor); podés editarlo.</p>' +
            '</div>'
        );
        $('#rpf-create-local-footer').html(
            '<button type="button" class="button" id="rpf-create-local-cancel">Cancelar</button>' +
            '<button type="button" class="button button-primary" id="rpf-create-local-save"' +
            (rpfCreateLocal.loadingSku || !rpfCreateLocal.sku ? ' disabled' : '') + '>Guardar</button>'
        );
        setTimeout(function () {
            var $n = $('#rpf-create-local-name');
            if ($n.length) {
                $n.trigger('focus').select();
            }
        }, 50);
    }

    function renderCreateLocalConfirm() {
        if (!rpfCreateLocal) {
            return;
        }
        rpfCreateLocal.step = 'confirm';
        $('#rpf-create-local-title').text('Confirmar creación y vínculo');
        $('#rpf-create-local-body').html(
            '<p>Se van a aplicar estos cambios:</p>' +
            '<ul class="rpf-create-local-confirm">' +
            '<li>Crear producto local con SKU <code>' + esc(rpfCreateLocal.sku) + '</code></li>' +
            '<li>Nombre: <strong>' + esc(rpfCreateLocal.nombre) + '</strong></li>' +
            '<li>Vincular código proveedor <code>' + esc(rpfCreateLocal.codigo_proveedor) + '</code>' +
            ' del folio <code>' + esc(rpfCreateLocal.folio || '—') + '</code></li>' +
            '</ul>' +
            '<p class="rpf-create-local-sure">¿Estás seguro?</p>'
        );
        $('#rpf-create-local-footer').html(
            '<button type="button" class="button" id="rpf-create-local-cancel">Cancelar</button>' +
            '<button type="button" class="button button-primary" id="rpf-create-local-confirm">Crear y vincular</button>'
        );
    }

    function submitCreateLocalAndLink() {
        if (!rpfCreateLocal || !rpfFacturaId) {
            return;
        }
        var $btn = $('#rpf-create-local-confirm').prop('disabled', true).text('Creando…');
        $('#rpf-create-local-cancel').prop('disabled', true);
        post('riverso_price_folio_create_local_and_link', {
            factura_id: rpfFacturaId,
            item_id: rpfCreateLocal.item_id,
            nombre_canonico: rpfCreateLocal.nombre
        }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo crear y vincular');
                $btn.prop('disabled', false).text('Crear y vincular');
                $('#rpf-create-local-cancel').prop('disabled', false);
                return;
            }
            closeCreateLocalModal();
            if (res.data && res.data.session) {
                showSession(res.data.session);
            } else {
                refreshSession();
            }
        }).fail(function () {
            window.alert('Error de red');
            $btn.prop('disabled', false).text('Crear y vincular');
            $('#rpf-create-local-cancel').prop('disabled', false);
        });
    }

    function completenessLabelRpf(cat) {
        var labels = {
            completo: 'Producto Completo',
            publicado: 'Producto Publicado',
            falta_online: 'Falta Online',
            falta_codigo: 'Falta Código',
            solo_online: 'Solo Online',
            solo_online_publicado: 'Solo Online Publicado',
            incompleto: 'Incompleto'
        };
        return labels[cat] || cat || '—';
    }

    function closeSearchLinkModal() {
        if (rpfSearchLinkTimer) {
            clearTimeout(rpfSearchLinkTimer);
            rpfSearchLinkTimer = null;
        }
        rpfSearchLink = null;
        $('#rpf-search-link-modal').hide().attr('aria-hidden', 'true');
        $('#rpf-search-link-body').empty();
        $('#rpf-search-link-footer').empty();
        $('#rpf-search-link-title').text('Buscar y vincular SKU');
    }

    function openSearchLinkModal(opts) {
        opts = opts || {};
        var inv = (rpfSession && rpfSession.invoice) || {};
        var itemId = opts.item_id || 0;
        if (!itemId) {
            window.alert('Ítem no válido');
            return;
        }
        rpfSearchLink = {
            step: 'search',
            item_id: itemId,
            codigo_proveedor: opts.codigo_proveedor || '',
            descripcion: opts.nombre || '',
            folio: inv.folio || '',
            proveedor_nombre: inv.proveedor_nombre || '',
            query: opts.codigo_proveedor || opts.nombre || '',
            items: [],
            total: 0,
            offset: 0,
            limit: 20,
            loading: !!(opts.codigo_proveedor || opts.nombre),
            selected: null,
            detail: null,
            conflictMessage: ''
        };
        $('#rpf-search-link-modal').css('display', 'flex').attr('aria-hidden', 'false');
        renderSearchLinkSearch();
        fetchSearchLinkResults(true);
    }

    function scheduleSearchLinkFetch(reset) {
        if (rpfSearchLinkTimer) {
            clearTimeout(rpfSearchLinkTimer);
        }
        rpfSearchLinkTimer = setTimeout(function () {
            fetchSearchLinkResults(!!reset);
        }, 300);
    }

    function fetchSearchLinkResults(reset) {
        if (!rpfSearchLink) {
            return;
        }
        var q = String(rpfSearchLink.query || '').trim();
        if (reset) {
            rpfSearchLink.offset = 0;
            rpfSearchLink.items = [];
            rpfSearchLink.total = 0;
        }
        if (q === '') {
            rpfSearchLink.loading = false;
            if (rpfSearchLink.step === 'search') {
                renderSearchLinkSearch();
            }
            return;
        }
        rpfSearchLink.loading = true;
        if (rpfSearchLink.step === 'search') {
            renderSearchLinkSearch();
        }
        var reqOffset = rpfSearchLink.offset;
        var reqQuery = q;
        post('riverso_products_list', {
            search: q,
            status: 'active',
            completeness: 'todos',
            offset: reqOffset,
            limit: rpfSearchLink.limit
        }).done(function (res) {
            if (!rpfSearchLink || String(rpfSearchLink.query || '').trim() !== reqQuery) {
                return;
            }
            rpfSearchLink.loading = false;
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo buscar');
                if (rpfSearchLink.step === 'search') {
                    renderSearchLinkSearch();
                }
                return;
            }
            var data = res.data || {};
            var batch = data.items || [];
            if (reqOffset === 0) {
                rpfSearchLink.items = batch;
            } else {
                rpfSearchLink.items = rpfSearchLink.items.concat(batch);
            }
            rpfSearchLink.total = parseInt(data.total, 10) || rpfSearchLink.items.length;
            rpfSearchLink.offset = rpfSearchLink.items.length;
            if (rpfSearchLink.step === 'search') {
                renderSearchLinkSearch();
            }
        }).fail(function () {
            if (!rpfSearchLink) {
                return;
            }
            rpfSearchLink.loading = false;
            window.alert('Error de red al buscar');
            if (rpfSearchLink.step === 'search') {
                renderSearchLinkSearch();
            }
        });
    }

    function renderSearchLinkSearch() {
        if (!rpfSearchLink) {
            return;
        }
        rpfSearchLink.step = 'search';
        rpfSearchLink.conflictMessage = '';
        $('#rpf-search-link-title').text('Buscar y vincular SKU');

        if (!$('#rpf-search-link-q').length) {
            var hubLinkInit = '';
            if (productsAdminUrl) {
                hubLinkInit = '<a class="button" href="#" id="rpf-search-link-hub" target="_blank" rel="noopener">Abrir en Hub</a>';
            }
            $('#rpf-search-link-body').html(
                '<dl class="rpf-create-local-meta">' +
                '<dt>Proveedor</dt><dd>' + esc(rpfSearchLink.proveedor_nombre || '—') + '</dd>' +
                '<dt>Folio</dt><dd><code>' + esc(rpfSearchLink.folio || '—') + '</code></dd>' +
                '<dt>Código a vincular</dt><dd><code>' + esc(rpfSearchLink.codigo_proveedor || '—') + '</code></dd>' +
                '<dt>Detalle ítem</dt><dd>' + esc(rpfSearchLink.descripcion || '—') + '</dd>' +
                '</dl>' +
                '<div class="rpf-create-local-field rpf-search-link-field">' +
                '<label for="rpf-search-link-q">Buscar (código proveedor, barcode, nombre o SKU)</label>' +
                '<div class="rpf-search-link-qrow">' +
                '<input type="text" id="rpf-search-link-q" class="large-text" autocomplete="off" ' +
                'value="' + esc(rpfSearchLink.query || '') + '">' +
                '<button type="button" class="button" id="rpf-search-link-go">Buscar</button>' +
                '</div>' +
                '<p class="description">Se prellena con el código proveedor del ítem; podés editarlo.</p>' +
                '</div>' +
                '<div class="rpf-search-link-table-wrap">' +
                '<table class="widefat striped rpf-search-link-table">' +
                '<thead><tr>' +
                '<th>ID</th><th>SKU</th><th>Nombre</th><th>Completitud</th><th>Código proveedor</th><th>Acciones</th>' +
                '</tr></thead>' +
                '<tbody id="rpf-search-link-tbody"></tbody>' +
                '</table></div>' +
                '<p class="description" id="rpf-search-link-count"></p>'
            );
            $('#rpf-search-link-footer').html(
                '<button type="button" class="button" id="rpf-search-link-cancel">Cerrar</button>' +
                '<span id="rpf-search-link-more-wrap"></span>' +
                hubLinkInit
            );
            setTimeout(function () {
                var $q = $('#rpf-search-link-q');
                if ($q.length) {
                    $q.trigger('focus');
                }
            }, 50);
        } else {
            if (String($('#rpf-search-link-q').val() || '') !== String(rpfSearchLink.query || '')) {
                $('#rpf-search-link-q').val(rpfSearchLink.query || '');
            }
        }

        updateSearchLinkHubHref();
        renderSearchLinkTable();
    }

    function updateSearchLinkHubHref() {
        var $hub = $('#rpf-search-link-hub');
        if (!$hub.length || !productsAdminUrl || !rpfSearchLink) {
            return;
        }
        var hubUrl = productsAdminUrl +
            (productsAdminUrl.indexOf('?') >= 0 ? '&' : '?') +
            'from=precio-folio&need=sku&search=' + encodeURIComponent(rpfSearchLink.query || '');
        $hub.attr('href', hubUrl);
    }

    function renderSearchLinkTable() {
        if (!rpfSearchLink) {
            return;
        }
        var rows = '';
        if (rpfSearchLink.loading && !rpfSearchLink.items.length) {
            rows = '<tr><td colspan="6">Buscando…</td></tr>';
        } else if (!String(rpfSearchLink.query || '').trim()) {
            rows = '<tr><td colspan="6">Escribí código proveedor, barcode o nombre.</td></tr>';
        } else if (!rpfSearchLink.items.length) {
            rows = '<tr><td colspan="6">Sin resultados.</td></tr>';
        } else {
            rows = rpfSearchLink.items.map(function (it) {
                var sku = String(it.sku_local || it.canonical_sku || '').trim();
                var cat = it.completeness_category || 'incompleto';
                var canPick = !!sku;
                var actions = '<button type="button" class="button button-small rpf-search-link-view" data-id="' +
                    esc(String(it.id || '')) + '">Ver</button>';
                if (canLinkSku && canPick) {
                    actions += ' <button type="button" class="button button-small button-primary rpf-search-link-pick"' +
                        ' data-id="' + esc(String(it.id || '')) + '"' +
                        ' data-sku="' + esc(sku) + '"' +
                        ' data-nombre="' + esc(it.nombre_canonico || '') + '">Vincular</button>';
                } else if (!canPick) {
                    actions += ' <span class="description">Sin SKU local</span>';
                }
                return '<tr>' +
                    '<td>' + esc(String(it.id || '')) + '</td>' +
                    '<td>' + (sku ? '<code>' + esc(sku) + '</code>' : '—') + '</td>' +
                    '<td>' + esc(it.nombre_canonico || '—') + '</td>' +
                    '<td><span class="rpf-completeness-badge ' + esc(cat) + '">' +
                    esc(completenessLabelRpf(cat)) + '</span></td>' +
                    '<td><code>' + esc(it.codigos_proveedor || '—') + '</code></td>' +
                    '<td class="rpf-search-link-actions">' + actions + '</td>' +
                    '</tr>';
            }).join('');
        }
        $('#rpf-search-link-tbody').html(rows);
        if (rpfSearchLink.total) {
            $('#rpf-search-link-count').text(
                'Mostrando ' + rpfSearchLink.items.length + ' de ' + rpfSearchLink.total
            );
        } else {
            $('#rpf-search-link-count').text('');
        }
        var moreHtml = '';
        if (rpfSearchLink.items.length < rpfSearchLink.total) {
            moreHtml = '<button type="button" class="button" id="rpf-search-link-more"' +
                (rpfSearchLink.loading ? ' disabled' : '') + '>Cargar más</button>';
        }
        $('#rpf-search-link-more-wrap').html(moreHtml);
    }

    function openSearchLinkDetail(productId) {
        if (!rpfSearchLink || !productId) {
            return;
        }
        rpfSearchLink.step = 'detail';
        rpfSearchLink.detail = null;
        $('#rpf-search-link-title').text('Detalle del producto');
        $('#rpf-search-link-body').html('<p>Cargando detalle…</p>');
        $('#rpf-search-link-footer').html(
            '<button type="button" class="button" id="rpf-search-link-cancel">Volver</button>'
        );
        post('riverso_products_get', { id: productId }).done(function (res) {
            if (!rpfSearchLink || rpfSearchLink.step !== 'detail') {
                return;
            }
            if (!res || !res.success || !res.data || !res.data.item) {
                window.alert((res && res.data && res.data.message) || 'No se pudo cargar el producto');
                renderSearchLinkSearch();
                return;
            }
            rpfSearchLink.detail = res.data.item;
            renderSearchLinkDetail();
        }).fail(function () {
            window.alert('Error de red');
            if (rpfSearchLink) {
                renderSearchLinkSearch();
            }
        });
    }

    function renderSearchLinkDetail() {
        if (!rpfSearchLink || !rpfSearchLink.detail) {
            return;
        }
        var it = rpfSearchLink.detail;
        var sku = String(it.canonical_sku || it.sku_local || '').trim();
        var skuOnline = String(
            it.sku_online ||
            (it.online_details && it.online_details.sku) ||
            ''
        ).trim();
        var cat = it.completeness_category || 'incompleto';
        var codes = (it.proveedores || []).map(function (p) {
            return (p.codigo_proveedor || '') +
                (p.proveedor_nombre ? ' (' + p.proveedor_nombre + ')' : '');
        }).filter(Boolean).join(', ') || (it.codigos_proveedor || '—');
        var barcodes = (it.barcodes || []).map(function (b) {
            return b.codigo || b.code || '';
        }).filter(Boolean).join(', ') || '—';
        var hubUrl = productsAdminUrl
            ? (productsAdminUrl + (productsAdminUrl.indexOf('?') >= 0 ? '&' : '?') +
                'action=detail&id=' + encodeURIComponent(String(it.id || '')))
            : '';
        var footer = '<button type="button" class="button" id="rpf-search-link-cancel">Volver</button>';
        if (hubUrl) {
            footer += '<a class="button" href="' + esc(hubUrl) + '" target="_blank" rel="noopener">Abrir en Hub</a>';
        }
        if (canLinkSku && sku) {
            footer += '<button type="button" class="button button-primary rpf-search-link-pick"' +
                ' data-id="' + esc(String(it.id || '')) + '"' +
                ' data-sku="' + esc(sku) + '"' +
                ' data-nombre="' + esc(it.nombre_canonico || '') + '">Vincular</button>';
        } else if (!sku) {
            footer += '<span class="description">Sin SKU local — usá Crear local o Vincular online</span>';
        }
        $('#rpf-search-link-body').html(
            '<dl class="rpf-create-local-meta">' +
            '<dt>ID</dt><dd>' + esc(String(it.id || '—')) + '</dd>' +
            '<dt>Nombre</dt><dd>' + esc(it.nombre_canonico || '—') + '</dd>' +
            '<dt>SKU local</dt><dd><code>' + esc(sku || '—') + '</code></dd>' +
            '<dt>SKU online</dt><dd><code>' + esc(skuOnline || '—') + '</code></dd>' +
            '<dt>Completitud</dt><dd><span class="rpf-completeness-badge ' + esc(cat) + '">' +
            esc(completenessLabelRpf(cat)) + '</span></dd>' +
            '<dt>Códigos proveedor</dt><dd><code>' + esc(codes) + '</code></dd>' +
            '<dt>Barcodes</dt><dd><code>' + esc(barcodes) + '</code></dd>' +
            '</dl>'
        );
        $('#rpf-search-link-footer').html(footer);
    }

    function renderSearchLinkConfirm() {
        if (!rpfSearchLink || !rpfSearchLink.selected) {
            return;
        }
        rpfSearchLink.step = 'confirm';
        var sel = rpfSearchLink.selected;
        var conflictBlock = rpfSearchLink.conflictMessage
            ? '<div class="notice notice-warning inline"><p>' + esc(rpfSearchLink.conflictMessage) + '</p></div>'
            : '';
        $('#rpf-search-link-title').text('Confirmar vínculo');
        $('#rpf-search-link-body').html(
            conflictBlock +
            '<p>Se va a vincular el código del proveedor de este folio con el SKU seleccionado:</p>' +
            '<ul class="rpf-create-local-confirm">' +
            '<li><strong>Proveedor:</strong> ' + esc(rpfSearchLink.proveedor_nombre || '—') + '</li>' +
            '<li><strong>Folio:</strong> <code>' + esc(rpfSearchLink.folio || '—') + '</code></li>' +
            '<li><strong>Código proveedor:</strong> <code>' + esc(rpfSearchLink.codigo_proveedor || '—') + '</code></li>' +
            '<li><strong>SKU:</strong> <code>' + esc(sel.sku || '—') + '</code></li>' +
            '<li><strong>Producto:</strong> ' + esc(sel.nombre || '—') + '</li>' +
            '<li><strong>Detalle ítem:</strong> ' + esc(rpfSearchLink.descripcion || '—') + '</li>' +
            '</ul>' +
            '<p class="description">Se guardará el mapeo y se aplicará a ítems posteriores del mismo código.</p>' +
            '<p class="rpf-create-local-sure">¿Estás seguro?</p>'
        );
        var footer = '<button type="button" class="button" id="rpf-search-link-cancel">Cancelar</button>';
        if (rpfSearchLink.conflictMessage) {
            footer += '<button type="button" class="button button-primary" id="rpf-search-link-force">' +
                'Reasignar de todas formas</button>';
        } else {
            footer += '<button type="button" class="button button-primary" id="rpf-search-link-confirm">' +
                'Vincular</button>';
        }
        $('#rpf-search-link-footer').html(footer);
    }

    function submitSearchLink(force) {
        if (!rpfSearchLink || !rpfSearchLink.selected || !rpfFacturaId) {
            return;
        }
        var sel = rpfSearchLink.selected;
        var $btn = force ? $('#rpf-search-link-force') : $('#rpf-search-link-confirm');
        $btn.prop('disabled', true).text(force ? 'Reasignando…' : 'Vinculando…');
        $('#rpf-search-link-cancel').prop('disabled', true);
        var payload = {
            item_id: rpfSearchLink.item_id,
            sku_local: sel.sku,
            crear_mapeo: 1
        };
        if (force) {
            payload.force = 1;
        }
        post('riverso_link_code', payload).done(function (res) {
            if (res && res.success) {
                var msg = res.data && res.data.message;
                closeSearchLinkModal();
                if (msg && /ítems posteriores/.test(msg)) {
                    window.alert(msg);
                }
                refreshSession();
                return;
            }
            var data = (res && res.data) || {};
            if (data.conflict && !force) {
                rpfSearchLink.conflictMessage = data.message ||
                    'Conflicto de SKU. ¿Reasignar de todas formas? El dueño anterior perderá este SKU.';
                renderSearchLinkConfirm();
                return;
            }
            window.alert(data.message || 'No se pudo vincular');
            $btn.prop('disabled', false).text(force ? 'Reasignar de todas formas' : 'Vincular');
            $('#rpf-search-link-cancel').prop('disabled', false);
        }).fail(function () {
            window.alert('Error de red');
            $btn.prop('disabled', false).text(force ? 'Reasignar de todas formas' : 'Vincular');
            $('#rpf-search-link-cancel').prop('disabled', false);
        });
    }

    function closeAnswerFamilyModal() {
        rpfAnswerFamily = null;
        $('#rpf-answer-family-modal').hide().attr('aria-hidden', 'true');
        $('#rpf-answer-family-body').empty();
        $('#rpf-answer-family-footer').empty();
        $('#rpf-answer-family-title').text('¿Necesita familia?');
    }

    function openAnswerFamilyModal(opts) {
        opts = opts || {};
        var productId = opts.producto_base_id || 0;
        if (!productId) {
            window.alert('Producto no válido');
            return;
        }
        var inv = (rpfSession && rpfSession.invoice) || {};
        rpfAnswerFamily = {
            step: 'form',
            producto_base_id: productId,
            sku: opts.sku || '',
            nombre: opts.nombre || '',
            folio: inv.folio || '',
            needs_family: null
        };
        $('#rpf-answer-family-modal').css('display', 'flex').attr('aria-hidden', 'false');
        renderAnswerFamilyForm();
    }

    function renderAnswerFamilyForm() {
        if (!rpfAnswerFamily) {
            return;
        }
        rpfAnswerFamily.step = 'form';
        rpfAnswerFamily.needs_family = null;
        $('#rpf-answer-family-title').text('¿Necesita familia?');
        $('#rpf-answer-family-body').html(
            '<dl class="rpf-create-local-meta">' +
            '<dt>Folio</dt><dd><code>' + esc(rpfAnswerFamily.folio || '—') + '</code></dd>' +
            '<dt>SKU</dt><dd><code>' + esc(rpfAnswerFamily.sku || '—') + '</code></dd>' +
            '<dt>Producto</dt><dd>' + esc(rpfAnswerFamily.nombre || '—') + '</dd>' +
            '</dl>' +
            '<p class="rpf-answer-family-question">¿Este producto necesita familia?</p>' +
            '<p class="description">Sí = packs/equivalencias. No = queda solo (no_requiere).</p>'
        );
        $('#rpf-answer-family-footer').html(
            '<button type="button" class="button" id="rpf-answer-family-cancel">Cancelar</button>' +
            '<button type="button" class="button" id="rpf-answer-family-no">No, queda solo</button>' +
            '<button type="button" class="button button-primary" id="rpf-answer-family-yes">Sí, necesita</button>'
        );
    }

    function renderAnswerFamilyConfirm() {
        if (!rpfAnswerFamily || rpfAnswerFamily.needs_family === null) {
            return;
        }
        rpfAnswerFamily.step = 'confirm';
        var yes = !!rpfAnswerFamily.needs_family;
        $('#rpf-answer-family-title').text('Confirmar respuesta');
        $('#rpf-answer-family-body').html(
            '<dl class="rpf-create-local-meta">' +
            '<dt>SKU</dt><dd><code>' + esc(rpfAnswerFamily.sku || '—') + '</code></dd>' +
            '<dt>Producto</dt><dd>' + esc(rpfAnswerFamily.nombre || '—') + '</dd>' +
            '<dt>Respuesta</dt><dd><strong>' + (yes ? 'Sí, necesita familia' : 'No, queda solo') + '</strong></dd>' +
            '</dl>' +
            '<p class="rpf-create-local-sure">' +
            (yes
                ? '¿Estás seguro? Vas a marcar que este producto SÍ necesita familia.'
                : '¿Estás seguro? Vas a marcar que este producto NO necesita familia (queda solo).') +
            '</p>'
        );
        $('#rpf-answer-family-footer').html(
            '<button type="button" class="button" id="rpf-answer-family-cancel">Cancelar</button>' +
            '<button type="button" class="button button-primary" id="rpf-answer-family-confirm">Confirmar</button>'
        );
    }

    function submitAnswerFamily() {
        if (!rpfAnswerFamily || rpfAnswerFamily.needs_family === null) {
            return;
        }
        var $btn = $('#rpf-answer-family-confirm').prop('disabled', true).text('Guardando…');
        $('#rpf-answer-family-cancel').prop('disabled', true);
        post('riverso_products_answer_family_need', {
            product_id: rpfAnswerFamily.producto_base_id,
            needs_family: rpfAnswerFamily.needs_family ? 1 : 0
        }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo guardar la respuesta');
                $btn.prop('disabled', false).text('Confirmar');
                $('#rpf-answer-family-cancel').prop('disabled', false);
                return;
            }
            closeAnswerFamilyModal();
            if (rpfFacturaId) {
                refreshSession();
            }
        }).fail(function () {
            window.alert('Error de red');
            $btn.prop('disabled', false).text('Confirmar');
            $('#rpf-answer-family-cancel').prop('disabled', false);
        });
    }

    function openHybridWizard(facturaId) {
        rpfHybridFacturaId = facturaId;
        $('#rpf-hybrid-modal').css('display', 'flex').attr('aria-hidden', 'false');
        renderHybridChoiceStep();
    }

    function renderHybridChoiceStep() {
        $('#rpf-hybrid-title').text('Ingreso manual / híbrido');
        $('#rpf-hybrid-body').html(
            '<p class="rpf-hybrid-hint description">Elegí cómo está el folio respecto del ingreso de precios:</p>' +
            '<div class="rpf-hybrid-choice">' +
            '<button type="button" class="button button-primary" id="rpf-hybrid-choice-complete">' +
            'Folio ya procesado anteriormente completamente</button>' +
            '<button type="button" class="button" id="rpf-hybrid-choice-partial">' +
            'Folio con algunos productos faltantes de ingresar</button>' +
            '</div>'
        );
        $('#rpf-hybrid-footer').hide().empty();
    }

    function renderHybridConfirmStep() {
        $('#rpf-hybrid-title').text('Confirmar folio completo');
        $('#rpf-hybrid-body').html(
            '<p class="rpf-hybrid-confirm-msg"><strong>¿Está seguro de que el folio está completamente procesado?</strong></p>' +
            '<p class="description">Se marcará como <em>Ingresada anteriormente/manual</em> y no se podrá procesar hasta quitar el override.</p>'
        );
        $('#rpf-hybrid-footer').html(
            '<button type="button" class="button" id="rpf-hybrid-back-choice">Volver</button>' +
            '<button type="button" class="button button-primary" id="rpf-hybrid-confirm-yes">Sí, folio completamente procesado</button>'
        ).css('display', 'flex');
    }

    function confirmHybridComplete() {
        if (!rpfHybridFacturaId) return;
        var $btn = $('#rpf-hybrid-confirm-yes').prop('disabled', true);
        post('riverso_price_folio_process_set_estado', {
            factura_id: rpfHybridFacturaId,
            estado: 'ingresada_manual'
        }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'Error');
                $btn.prop('disabled', false);
                return;
            }
            closeHybridWizard();
            loadProcessList();
        }).fail(function () {
            window.alert('Error de red');
            $btn.prop('disabled', false);
        });
    }

    function loadHybridItemsStep() {
        if (!rpfHybridFacturaId) return;
        $('#rpf-hybrid-title').text('Marcar filas ya ingresadas');
        $('#rpf-hybrid-body').html('<p>Cargando líneas…</p>');
        $('#rpf-hybrid-footer').hide().empty();
        post('riverso_price_folio_process_hybrid_items', { factura_id: rpfHybridFacturaId }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudieron cargar las líneas');
                renderHybridChoiceStep();
                return;
            }
            rpfHybridLines = (res.data && res.data.lines) || [];
            renderHybridItemsStep(res.data || {});
        }).fail(function () {
            window.alert('Error de red');
            renderHybridChoiceStep();
        });
    }

    function renderHybridItemsStep(data) {
        var lines = data.lines || rpfHybridLines || [];
        if (!lines.length) {
            $('#rpf-hybrid-body').html('<p>Sin ítems de producto en este folio.</p>');
            $('#rpf-hybrid-footer').html(
                '<button type="button" class="button" id="rpf-hybrid-back-choice">Volver</button>'
            ).css('display', 'flex');
            return;
        }
        var html = '<p class="rpf-hybrid-hint description">Marcá con ticket las filas que <strong>ya están ingresadas</strong>. ' +
            'El árbol de tareas se mostrará solo para las que faltan.</p>';
        html += '<p><button type="button" class="button button-small" id="rpf-hybrid-select-all">Marcar todas</button> ' +
            '<button type="button" class="button button-small" id="rpf-hybrid-select-none">Desmarcar todas</button></p>';
        html += '<table class="widefat striped rpf-hybrid-items-table" id="rpf-hybrid-items"><thead><tr>' +
            '<th class="rpf-hybrid-check">✓</th><th>Línea</th><th>Código</th><th>Descripción</th><th>Cant.</th>' +
            '</tr></thead><tbody>';
        lines.forEach(function (ln) {
            html += '<tr>' +
                '<td class="rpf-hybrid-check"><input type="checkbox" class="rpf-hybrid-ticket" value="' +
                ln.item_id + '"' + (ln.ya_ingresada ? ' checked' : '') + '></td>' +
                '<td>' + esc(ln.numero_linea) + '</td>' +
                '<td><code>' + esc(ln.codigo_proveedor || '') + '</code></td>' +
                '<td>' + esc(ln.descripcion || '') + '</td>' +
                '<td>' + esc(ln.cantidad) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        $('#rpf-hybrid-body').html(html);
        $('#rpf-hybrid-footer').html(
            '<button type="button" class="button" id="rpf-hybrid-back-choice">Volver</button>' +
            '<button type="button" class="button button-primary" id="rpf-hybrid-continue">Continuar con las faltantes</button>'
        ).css('display', 'flex');
        updateHybridContinueState();
    }

    function getHybridCheckedIds() {
        var ids = [];
        $('#rpf-hybrid-items .rpf-hybrid-ticket:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function updateHybridContinueState() {
        var total = $('#rpf-hybrid-items .rpf-hybrid-ticket').length;
        var checked = $('#rpf-hybrid-items .rpf-hybrid-ticket:checked').length;
        var $btn = $('#rpf-hybrid-continue');
        if (!total) {
            $btn.prop('disabled', true);
            return;
        }
        if (checked >= total) {
            $btn.text('Todas marcadas → confirmar folio completo').prop('disabled', false);
        } else if (checked === 0) {
            $btn.text('Continuar (ninguna ya ingresada)').prop('disabled', false);
        } else {
            $btn.text('Continuar con las faltantes (' + (total - checked) + ')').prop('disabled', false);
        }
    }

    function submitHybridPartial() {
        if (!rpfHybridFacturaId) return;
        var total = $('#rpf-hybrid-items .rpf-hybrid-ticket').length;
        var ids = getHybridCheckedIds();
        if (total > 0 && ids.length >= total) {
            renderHybridConfirmStep();
            return;
        }
        var $btn = $('#rpf-hybrid-continue').prop('disabled', true).text('Guardando…');
        post('riverso_price_folio_process_start_hybrid', {
            factura_id: rpfHybridFacturaId,
            item_ids: JSON.stringify(ids)
        }).done(function (res) {
            if (!res || !res.success) {
                window.alert((res && res.data && res.data.message) || 'No se pudo iniciar el híbrido');
                $btn.prop('disabled', false);
                updateHybridContinueState();
                return;
            }
            var d = res.data || {};
            closeHybridWizard();
            if (d.completed_manual) {
                loadProcessList();
                return;
            }
            if (d.session) {
                showSession(d.session);
            } else {
                loadProcessList();
            }
        }).fail(function () {
            window.alert('Error de red');
            $btn.prop('disabled', false);
            updateHybridContinueState();
        });
    }
})(jQuery);
