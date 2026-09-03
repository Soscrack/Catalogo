<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_prices');
$can_approve = current_user_can('riverso_approve_prices');
?>
<div class="wrap riverso-price-rules">
    <h1>Reglas de Precio</h1>
    <p>Reglas por tramos de cantidad, con fórmulas tipo calculadora. <code>P</code> = precio asignado, <code>T</code> = total de línea (unitario × cantidad).
        Atajos: <strong>T10()</strong> techo_decena, <strong>T50()</strong> techo_cincuentena, <strong>T100()</strong> techo_centena.</p>

    <div class="rpr-layout">
        <div class="rpr-list">
            <h2>Reglas</h2>
            <p class="rpr-list-filters">
                <label><input type="checkbox" id="rules-show-archived"> Mostrar archivadas</label>
            </p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Código</th><th>Nombre</th><th>Ver.</th><th>Estado</th><th></th></tr></thead>
                <tbody id="rules-tbody"><tr><td colspan="5">Cargando...</td></tr></tbody>
            </table>
            <p>
                <button type="button" class="button" id="rpr-open-assign-view">Ver asignaciones</button>
                <button type="button" class="button" id="rpr-open-assign-search">Buscar asignaciones</button>
                <?php if ($can_manage): ?>
                <button class="button button-primary" id="rule-new">Nueva regla</button>
                <?php endif; ?>
            </p>
        </div>

        <div class="rpr-editor-wrap">
            <h2 class="rpr-editor-title">
                Editor de tramos
                <span class="dashicons dashicons-editor-help rpr-help-icon" id="rpr-tier-help" title="Ayuda: fórmulas P y T"></span>
            </h2>
            <div id="rpr-help-panel" class="rpr-help-panel" style="display:none;">
                <p><strong>Cómo funciona el cálculo por tramo</strong></p>
                <ol>
                    <li><code>P</code> = precio asignado del producto.</li>
                    <li><strong>Fórmula P</strong> → precio unitario inicial (ej. <code>T10(P*3)</code>).</li>
                    <li><strong>Piso u.</strong> → mínimo de precio unitario tras la fórmula P.</li>
                    <li>Se calcula <code>T = unitario × cantidad</code> (total de línea).</li>
                    <li><strong>Fórmula T</strong> → ajusta el total (ej. <code>T50(T)</code> redondea el total al techo de 50).</li>
                    <li><strong>Piso T</strong> → mínimo del total tras la fórmula T.</li>
                    <li>Se recalcula <strong>unitario = T final ÷ cantidad</strong> para que cuadre.</li>
                </ol>
                <p>Ejemplo: P=10, fórmula P=<code>T10(P*3)</code>→30, Q=7 → T=210 → fórmula T=<code>T50(T)</code>→250 → piso T=300 → <strong>Total 300</strong> (42,86 c/u).</p>
                <p>Dejar vacío fórmula T / piso T = sin ajuste sobre el total.</p>
            </div>
            <div id="rule-editor" class="rpr-editor">
                <p>Selecciona o crea una regla.</p>
            </div>
        </div>
    </div>
</div>

<div id="rpr-view-modal" class="riverso-modal" style="display:none;" aria-hidden="true">
    <div class="riverso-modal-content rpr-assign-modal-content">
        <div class="riverso-modal-header">
            <h2>Asignaciones de la regla</h2>
            <button type="button" class="riverso-modal-close" id="rpr-view-modal-close" aria-label="Cerrar">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="rpr-view-rule-picker">
                <div id="rpr-view-rule-selected" class="rpr-view-rule-selected">
                    <span class="description">Ninguna regla seleccionada.</span>
                </div>
                <label class="rpr-view-rule-search-label">
                    Cambiar regla
                    <input type="search" id="rpr-view-rule-q" class="regular-text" placeholder="Código o nombre (ej. R-1)…" autocomplete="off">
                </label>
                <div id="rpr-view-rule-suggest" class="rpr-assign-suggest" style="display:none;"></div>
            </div>
            <p id="rpr-view-rule-meta" class="description" style="display:none;"></p>
            <div class="rpr-assign-filters">
                <input type="search" id="rpr-view-q" class="regular-text" placeholder="Nombre, SKU o código de integrante…">
                <button type="button" class="button" id="rpr-view-search-btn">Filtrar</button>
            </div>
            <div id="rpr-view-list"></div>
            <?php if ($can_manage): ?>
            <p class="rpr-assign-form">
                <select id="assign-tipo">
                    <option value="producto">Producto</option>
                    <option value="familia">Familia unitaria</option>
                    <option value="categoria">Categoría</option>
                </select>
                <input type="search" id="assign-q" class="regular-text" placeholder="Buscar por nombre o código de integrante…">
                <input type="number" id="assign-id" class="small-text" placeholder="ID">
                <span id="assign-name" class="description"></span>
                <button type="button" class="button button-primary" id="rule-assign">Asignar</button>
            </p>
            <div id="assign-suggest" class="rpr-assign-suggest" style="display:none;"></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="rpr-assign-modal" class="riverso-modal" style="display:none;" aria-hidden="true">
    <div class="riverso-modal-content rpr-assign-modal-content">
        <div class="riverso-modal-header">
            <h2>Buscar asignaciones</h2>
            <button type="button" class="riverso-modal-close" id="rpr-assign-modal-close" aria-label="Cerrar">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p class="description">Familias unitarias y productos que ya tienen regla asignada.</p>
            <div class="rpr-assign-filters">
                <input type="search" id="rpr-assign-q" class="regular-text" placeholder="Nombre, SKU, código de integrante o regla…">
                <select id="rpr-assign-tipo">
                    <option value="all">Todas</option>
                    <option value="familia">Familias unitarias</option>
                    <option value="producto">Productos</option>
                </select>
                <select id="rpr-assign-rule-scope">
                    <option value="all">Todas las reglas</option>
                    <option value="current">Regla del editor</option>
                </select>
                <button type="button" class="button button-primary" id="rpr-assign-search-btn">Buscar</button>
            </div>
            <table class="wp-list-table widefat fixed striped rpr-assign-table">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>ID</th>
                        <th>Nombre / SKU</th>
                        <th>Regla</th>
                        <th>Enlace</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rpr-assign-tbody">
                    <tr><td colspan="5">Escribe y pulsa Buscar.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canApprove = <?php echo $can_approve ? 'true' : 'false'; ?>;
    let current = null;
    let formulaFocus = null;
    let formulaFocusKind = 'p';
    let previewTimer = null;
    let allRules = [];
    let calcVisible = localStorage.getItem('rpr-calc-visible') === '1';

    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function fmt(v){
        if (v === null || v === undefined || v === '') return '—';
        return Number(v).toLocaleString('es-CL');
    }

    function describeFormulaP(f){
        return String(f || '')
            .replace(/\bT100\s*\(/gi, 'techo_centena(')
            .replace(/\bT50\s*\(/gi, 'techo_cincuentena(')
            .replace(/\bT10\s*\(/gi, 'techo_decena(')
            .replace(/\btecho_centana\s*\(/gi, 'techo_centena(')
            .replace(/\bMAX\s*\(/gi, 'máximo(')
            .replace(/\bMIN\s*\(/gi, 'mínimo(')
            .replace(/\bP\b/g, 'precio');
    }

    function describeFormulaT(f){
        return String(f || '')
            .replace(/\bT100\s*\(/gi, 'techo_centena(')
            .replace(/\bT50\s*\(/gi, 'techo_cincuentena(')
            .replace(/\bT10\s*\(/gi, 'techo_decena(')
            .replace(/\btecho_centana\s*\(/gi, 'techo_centena(')
            .replace(/\bMAX\s*\(/gi, 'máximo(')
            .replace(/\bMIN\s*\(/gi, 'mínimo(')
            .replace(/\bT\b/g, 'total')
            .replace(/\bP\b/g, 'precio');
    }

    function formatBreakdownPreview(b){
        if (!b) return '—';
        const parts = [fmt(b.unitario0) + ' c/u'];
        if (b.adjusted || b.t_after_formula !== null) {
            parts.push('→ T ' + fmt(b.t0));
        }
        if (b.t_after_formula !== null && Math.abs(b.t_after_formula - b.t0) > 0.001) {
            parts.push('→ ' + fmt(b.t_after_formula));
        }
        if (Math.abs(b.t_final - (b.t_after_formula !== null ? b.t_after_formula : b.t0)) > 0.001) {
            parts.push('→ piso ' + fmt(b.t_final));
        }
        if (b.adjusted && Math.abs(b.unitario - b.unitario0) > 0.0001) {
            parts.push('→ ' + fmt(b.unitario) + ' c/u');
        } else if (!b.adjusted) {
            return fmt(b.unitario) + ' c/u';
        }
        return parts.join(' ');
    }

    function formatSimBreakdown(bd, qty){
        if (!bd) return '';
        const q = fmt(qty);
        let html = '<span class="rpr-sim-unit">' + fmt(bd.unitario0) + ' c/u</span> × ' + q + ' = T ' + fmt(bd.t0);
        if (bd.t_after_formula !== null && Math.abs(bd.t_after_formula - bd.t0) > 0.001) {
            html += ' → ' + fmt(bd.t_after_formula);
        }
        if (Math.abs(bd.t_final - (bd.t_after_formula !== null ? bd.t_after_formula : bd.t0)) > 0.001) {
            html += ' → piso T ' + fmt(bd.t_final);
        }
        html += ' = <strong class="rpr-sim-total">Total ' + fmt(bd.t_final) + '</strong>';
        html += ' <span class="rpr-sim-unit">(' + fmt(bd.unitario) + ' c/u)</span>';
        return html;
    }

    function tipoLabel(tipo){
        if (tipo === 'familia') return 'Familia unitaria';
        if (tipo === 'producto') return 'Producto';
        if (tipo === 'categoria') return 'Categoría';
        return tipo || '—';
    }

    function ruleLabel(rule){
        if (!rule) return '';
        return (rule.codigo || '') + ' v' + (rule.version || '') + ' · ' + (rule.nombre || '');
    }

    function renderSelectedRuleChip(rule){
        if (!rule || rule._new || !rule.id) {
            return `<div class="rpr-view-rule-chip is-empty">
                <strong>Regla seleccionada</strong>
                <span class="description">Ninguna. Busca una regla abajo para ver y gestionar sus asignaciones.</span>
            </div>`;
        }
        const n = (rule.assignments || []).length;
        return `<div class="rpr-view-rule-chip">
            <strong>Regla seleccionada</strong>
            <div class="rpr-view-rule-chip-main">
                <code>${esc(rule.codigo || '')}</code>
                <span>v${esc(rule.version || '')}</span>
                <span class="rpr-view-rule-chip-name">${esc(rule.nombre || '')}</span>
                <span class="rpr-view-rule-chip-estado">${esc(rule.estado || '')}</span>
            </div>
            <span class="description">${n} asignación${n === 1 ? '' : 'es'} en esta versión</span>
        </div>`;
    }

    function renderRuleSuggestList(q){
        const query = String(q || '').toLowerCase().trim();
        const list = (allRules || []).filter(rl => {
            if (rl.estado === 'archivada') return false;
            if (current && current.id && String(rl.id) === String(current.id)) return false;
            if (!query) return true;
            const hay = ((rl.codigo || '') + ' ' + (rl.nombre || '') + ' v' + (rl.version || '')).toLowerCase();
            return hay.indexOf(query) !== -1;
        }).slice(0, 20);
        if (!list.length) {
            return '<div class="rpr-suggest-empty">Sin reglas</div>';
        }
        return list.map(rl =>
            `<button type="button" class="rpr-suggest-item rpr-view-rule-item" data-id="${esc(String(rl.id))}">
                ${esc(ruleLabel(rl))} <span class="description">(${esc(rl.estado || '')})</span>
            </button>`
        ).join('');
    }

    function renderAssignmentsList(assignments){
        if (!assignments || !assignments.length) {
            return '<p class="description">Sin asignaciones.</p>';
        }
        const rows = assignments.map(a => {
            const nombre = a.nombre || a.label || ((a.target_tipo || '') + '#' + (a.target_id || ''));
            const link = a.url
                ? `<a href="${esc(a.url)}" target="_blank" rel="noopener">Abrir</a>`
                : '';
            return `<tr>
                <td>${esc(tipoLabel(a.target_tipo))}</td>
                <td><code>${esc(a.target_id)}</code></td>
                <td>${esc(nombre)}${a.sku ? `<br><code>${esc(a.sku)}</code>` : ''}</td>
                <td>${link || '—'}</td>
                <td>${canManage ? `<button type="button" class="button button-small rpr-use-assign" data-tipo="${esc(a.target_tipo)}" data-id="${esc(a.target_id)}">Usar para asignar</button>` : ''}</td>
            </tr>`;
        }).join('');
        return `<table class="wp-list-table widefat fixed striped rpr-assign-table">
            <thead><tr><th>Tipo</th><th>ID</th><th>Nombre / SKU</th><th>Enlace</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
    }

    function refreshViewModal(){
        $('#rpr-view-rule-selected').html(renderSelectedRuleChip(current));
        $('#rpr-view-rule-q').val('');
        $('#rpr-view-rule-suggest').hide().empty();
        if (!current || current._new || !current.id) {
            $('#rpr-view-list').html('<p class="description">Selecciona una regla para ver sus asignaciones.</p>');
            return;
        }
        $('#rpr-view-list').html(renderAssignmentsList(current.assignments || []));
    }

    function selectViewRule(ruleId){
        if (!ruleId) return;
        $('#rpr-view-list').html('<p class="description">Cargando regla…</p>');
        $('#rpr-view-rule-suggest').hide().empty();
        openRule(ruleId, { keepViewModal: true });
    }

    function openViewModal(){
        if (!allRules.length) {
            loadRules();
        }
        refreshViewModal();
        $('#rpr-view-modal').show().attr('aria-hidden', 'false');
        if (window._rprPendingAssign && $('#assign-tipo').length) {
            $('#assign-tipo').val(window._rprPendingAssign.tipo);
            $('#assign-id').val(window._rprPendingAssign.id);
            lookupAssignName();
        }
    }

    function closeViewModal(){
        $('#rpr-view-modal').hide().attr('aria-hidden', 'true');
    }

    function openAssignModal(opts){
        opts = opts || {};
        const $m = $('#rpr-assign-modal');
        $m.show().attr('aria-hidden', 'false');
        if (opts.tipo) $('#rpr-assign-tipo').val(opts.tipo);
        if (opts.scope) $('#rpr-assign-rule-scope').val(opts.scope);
        if (opts.q != null) $('#rpr-assign-q').val(opts.q);
        searchAssignments();
    }

    function closeAssignModal(){
        $('#rpr-assign-modal').hide().attr('aria-hidden', 'true');
    }

    function searchAssignments(){
        const $tb = $('#rpr-assign-tbody');
        $tb.html('<tr><td colspan="6">Buscando…</td></tr>');
        const scope = $('#rpr-assign-rule-scope').val();
        const ruleId = (scope === 'current' && current && current.id) ? current.id : 0;
        if (scope === 'current' && !ruleId) {
            $tb.html('<tr><td colspan="6">Abre una regla en el editor para filtrar por ella.</td></tr>');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_price_rule_search_assignments',
            nonce,
            search: $('#rpr-assign-q').val(),
            tipo: $('#rpr-assign-tipo').val(),
            rule_id: ruleId,
            limit: 50,
        }, function(r){
            if (!r.success) {
                $tb.html('<tr><td colspan="6">' + esc((r.data && r.data.message) || 'Error') + '</td></tr>');
                return;
            }
            const items = r.data.items || [];
            if (!items.length) {
                $tb.html('<tr><td colspan="6">Sin resultados.</td></tr>');
                return;
            }
            $tb.html(items.map(it => {
                const ruleTxt = esc((it.rule_codigo || '') + ' · ' + (it.rule_nombre || '') + ' v' + (it.rule_version || ''));
                const open = it.url ? `<a href="${esc(it.url)}" target="_blank" rel="noopener">Abrir</a>` : '—';
                const useBtn = canManage
                    ? `<button type="button" class="button button-small rpr-use-assign" data-tipo="${esc(it.target_tipo)}" data-id="${esc(it.target_id)}">Usar para asignar</button>`
                    : '';
                return `<tr>
                    <td>${esc(tipoLabel(it.target_tipo))}</td>
                    <td><code>${esc(it.target_id)}</code></td>
                    <td>${esc(it.nombre || it.label || '')}${it.sku ? '<br><code>' + esc(it.sku) + '</code>' : ''}</td>
                    <td>${ruleTxt}</td>
                    <td>${open}</td>
                    <td>${useBtn}</td>
                </tr>`;
            }).join(''));
        }).fail(function(){
            $tb.html('<tr><td colspan="6">Error de red.</td></tr>');
        });
    }

    function lookupAssignName(){
        const tipo = $('#assign-tipo').val();
        const id = $('#assign-id').val();
        const $name = $('#assign-name');
        if (!id || tipo === 'categoria') {
            $name.text('');
            return;
        }
        $name.text('…');
        $.post(ajaxurl, {
            action: 'riverso_price_rule_lookup_target',
            nonce, target_tipo: tipo, target_id: id,
        }, function(r){
            if (r.success && r.data) {
                $name.text(r.data.nombre || r.data.label || '');
            } else {
                $name.text('No encontrado');
            }
        }).fail(function(){ $name.text(''); });
    }

    let assignSuggestTimer = null;
    function searchAssignTargets(){
        const q = ($('#assign-q').val() || '').trim();
        const $box = $('#assign-suggest');
        if (q.length < 2 && !/^\d+$/.test(q)) {
            $box.hide().empty();
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_price_rule_search_targets',
            nonce,
            search: q,
            tipo: $('#assign-tipo').val(),
            limit: 20,
        }, function(r){
            if (!r.success || !(r.data.items || []).length) {
                $box.html('<div class="rpr-suggest-empty">Sin resultados</div>').show();
                return;
            }
            $box.html((r.data.items || []).map(it =>
                `<button type="button" class="rpr-suggest-item" data-tipo="${esc(it.target_tipo)}" data-id="${esc(it.target_id)}" data-nombre="${esc(it.nombre || '')}">
                    ${esc(it.label || '')}
                </button>`
            ).join('')).show();
        });
    }

    function applyAssignTarget(tipo, id){
        window._rprPendingAssign = { tipo: tipo, id: id };
        if (!$('#assign-tipo').length) {
            alert('No tienes permiso para asignar.');
            return;
        }
        $('#assign-tipo').val(tipo);
        $('#assign-id').val(id);
        closeAssignModal();
        openViewModal();
        lookupAssignName();
        const el = document.getElementById('assign-id');
        if (el) el.focus();
    }

    function loadRules(){
        $.post(ajaxurl, {action:'riverso_price_rules_list', nonce}, function(r){
            if (!r.success){ $('#rules-tbody').html('<tr><td colspan="5">Error</td></tr>'); return; }
            allRules = r.data.rules || [];
            renderRuleList();
            const $box = $('#rpr-view-rule-suggest');
            if ($box.is(':visible')) {
                $box.html(renderRuleSuggestList($('#rpr-view-rule-q').val()));
            }
        });
    }

    function renderRuleList(){
        const showArchived = $('#rules-show-archived').is(':checked');
        const rules = showArchived ? allRules : allRules.filter(rl => rl.estado !== 'archivada');
        if (!rules.length){
            $('#rules-tbody').html('<tr><td colspan="5">' + (allRules.length ? 'Sin reglas en este filtro' : 'Sin reglas') + '</td></tr>');
            return;
        }
        $('#rules-tbody').html(rules.map(rl => `
            <tr>
                <td>${esc(rl.codigo)}</td><td>${esc(rl.nombre)}</td><td>${esc(rl.version)}</td>
                <td>${esc(rl.estado)}</td>
                <td><button class="button button-small rule-open" data-id="${esc(rl.id)}">Abrir</button></td>
            </tr>`).join(''));
    }

    function calcPad(){
        const keys = [
            {label:'P', insert:'P', cls:'rpr-calc-var', title:'Precio asignado (fórmula P)'},
            {label:'T', insert:'T', cls:'rpr-calc-var rpr-calc-var-t', title:'Total de línea (fórmula T)'},
            {label:'T10()', wrap:'T10', cls:'rpr-calc-fn', title:'techo_decena'},
            {label:'T50()', wrap:'T50', cls:'rpr-calc-fn', title:'techo_cincuentena'},
            {label:'T100()', wrap:'T100', cls:'rpr-calc-fn', title:'techo_centena'},
            {label:'7', insert:'7'}, {label:'8', insert:'8'}, {label:'9', insert:'9'}, {label:'÷', insert:'/', cls:'rpr-calc-op'},
            {label:'4', insert:'4'}, {label:'5', insert:'5'}, {label:'6', insert:'6'}, {label:'×', insert:'*', cls:'rpr-calc-op'},
            {label:'1', insert:'1'}, {label:'2', insert:'2'}, {label:'3', insert:'3'}, {label:'−', insert:'-', cls:'rpr-calc-op'},
            {label:'0', insert:'0'}, {label:'.', insert:'.'}, {label:',', insert:',', title:'Separador MAX/MIN'}, {label:'+', insert:'+', cls:'rpr-calc-op'},
            {label:'(', insert:'('}, {label:')', insert:')'},
            {label:'MAX', wrap:'MAX', cls:'rpr-calc-fn', title:'Máximo'},
            {label:'⌫', action:'back', cls:'rpr-calc-danger', title:'Borrar'},
            {label:'C', action:'clear', cls:'rpr-calc-danger', title:'Borrar fórmula'},
        ];
        return `<div class="rpr-calc" aria-label="Calculadora de fórmulas">
            <div class="rpr-calc-head">
                <strong>Calculadora</strong>
                <button type="button" class="rpr-calc-close" id="rpr-calc-hide" title="Ocultar">×</button>
            </div>
            <div class="rpr-calc-legend">
                <span><b>P</b> precio · <b>T</b> total línea</span>
                <span><b>T10/T50/T100</b> techo 10/50/100</span>
            </div>
            <div class="rpr-calc-grid">
                ${keys.map(k => {
                    const attrs = [
                        k.insert != null ? `data-insert="${esc(k.insert)}"` : '',
                        k.wrap ? `data-wrap="${esc(k.wrap)}"` : '',
                        k.action ? `data-action="${esc(k.action)}"` : '',
                        k.title ? `title="${esc(k.title)}"` : '',
                    ].join(' ');
                    return `<button type="button" class="rpr-calc-btn ${k.cls || ''}" ${attrs}>${esc(k.label)}</button>`;
                }).join('')}
            </div>
            <p class="rpr-calc-hint">En <strong>Fórmula T</strong>, usa <code>T</code> y envuélvelo con T50: <code>T50(T)</code></p>
        </div>`;
    }

    function tierRow(t){
        t = t || {qty_min:'', qty_max:'', formula:'P', total_minimo:'', formula_total:'', piso_total:''};
        const formula = t.formula || 'P';
        const formulaTotal = t.formula_total || '';
        return `<tr class="tier-row">
            <td><input type="number" class="t-min small-text" value="${esc(t.qty_min ?? '')}" min="0"></td>
            <td><input type="number" class="t-max small-text" value="${esc(t.qty_max ?? '')}" placeholder="∞" min="0"></td>
            <td>
                <input type="text" class="t-formula-input" value="${esc(formula)}" spellcheck="false" autocomplete="off" placeholder="T10(P*3)">
                <div class="rpr-formula-hint rpr-hint-p">${esc(describeFormulaP(formula))}</div>
            </td>
            <td><input type="number" step="0.01" class="t-minp small-text" value="${esc(t.total_minimo ?? '')}" placeholder="—" title="Piso unitario"></td>
            <td>
                <input type="text" class="t-formula-total-input" value="${esc(formulaTotal)}" spellcheck="false" autocomplete="off" placeholder="T50(T)">
                <div class="rpr-formula-hint rpr-hint-t">${esc(describeFormulaT(formulaTotal))}</div>
            </td>
            <td><input type="number" step="0.01" class="t-piso-total small-text" value="${esc(t.piso_total ?? '')}" placeholder="—" title="Piso total"></td>
            <td class="rpr-preview" data-empty="—">—</td>
            <td>${canManage ? '<button type="button" class="button button-small tier-del" title="Quitar">×</button>' : ''}</td>
        </tr>`;
    }

    function renderEditor(rule){
        current = rule;
        const editable = canManage;
        const tiersHtml = (rule.tiers || []).map(tierRow).join('');
        const assignCount = (rule.assignments || []).length;
        $('#rule-editor').html(`
            <p class="rpr-meta"><strong>${esc(rule.codigo)}</strong> v${esc(rule.version)} — ${esc(rule.estado)}</p>
            <p><label>Nombre: <input type="text" id="rule-nombre" value="${esc(rule.nombre || '')}" class="regular-text" ${editable?'':'disabled'}></label></p>
            <div class="rpr-editor-grid ${calcVisible ? '' : 'calc-hidden'}">
                <div class="rpr-tiers-wrap">
                    <table class="widefat striped rpr-tiers">
                        <thead><tr>
                            <th>Qty min</th>
                            <th>Qty max</th>
                            <th>Fórmula P</th>
                            <th>Piso u.</th>
                            <th>Fórmula T</th>
                            <th>Piso T</th>
                            <th>Vista previa</th>
                            <th></th>
                        </tr></thead>
                        <tbody id="tiers-tbody">${tiersHtml || ''}</tbody>
                    </table>
                    ${editable ? `<p>
                        <button type="button" class="button" id="tier-add">+ Tramo</button>
                        <button type="button" class="button button-primary" id="rule-save">Guardar fórmulas</button>
                        <button type="button" class="button" id="rpr-calc-toggle">${calcVisible ? 'Ocultar calculadora' : 'Mostrar calculadora'}</button>
                    </p>` : ''}
                    ${canApprove && rule.estado !== 'aprobada' ? '<p><button type="button" class="button button-primary" id="rule-approve">Aprobar versión</button></p>' : ''}
                </div>
                ${editable ? calcPad() : ''}
            </div>
            <hr>
            <p class="rpr-assigns-block">
                <strong>Asignaciones</strong> (${assignCount})
                <button type="button" class="button button-small" id="rpr-open-assign-view-rule">Ver</button>
                <button type="button" class="button button-small" id="rpr-open-assign-search-rule">Búsqueda</button>
            </p>
            <hr>
            <p class="rpr-sim"><strong>Simular:</strong>
                p_asignado <input type="number" id="sim-p" class="small-text" value="10" step="0.01">
                qty <input type="number" id="sim-q" class="small-text" value="1" min="0.0001" step="any">
                <button type="button" class="button" id="rule-sim">Calcular</button>
                <span id="sim-result"></span>
            </p>
        `);
        const first = document.querySelector('.t-formula-input');
        if (first) {
            formulaFocus = first;
            formulaFocusKind = 'p';
            $(first).closest('.tier-row').addClass('is-active');
        }
        schedulePreview();
        refreshViewModal();
        if (window._rprPendingAssign && $('#assign-tipo').length) {
            $('#assign-tipo').val(window._rprPendingAssign.tipo);
            $('#assign-id').val(window._rprPendingAssign.id);
            lookupAssignName();
        }
    }

    function applyCalcVisibility(){
        $('.rpr-editor-grid').toggleClass('calc-hidden', !calcVisible);
        $('#rpr-calc-toggle').text(calcVisible ? 'Ocultar calculadora' : 'Mostrar calculadora');
        localStorage.setItem('rpr-calc-visible', calcVisible ? '1' : '0');
    }

    function setCalcVisible(visible){
        calcVisible = !!visible;
        applyCalcVisibility();
    }

    function collectTiers(){
        const tiers = [];
        $('#tiers-tbody .tier-row').each(function(){
            tiers.push({
                qty_min: $(this).find('.t-min').val(),
                qty_max: $(this).find('.t-max').val(),
                formula: $(this).find('.t-formula-input').val(),
                formula_tipo: 'formula',
                total_minimo: $(this).find('.t-minp').val(),
                formula_total: $(this).find('.t-formula-total-input').val(),
                piso_total: $(this).find('.t-piso-total').val(),
            });
        });
        return tiers;
    }

    function openRule(id, opts){
        opts = opts || {};
        $.post(ajaxurl, {action:'riverso_price_rule_get', nonce, rule_id:id}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            renderEditor(r.data.rule);
            if (opts.keepViewModal || $('#rpr-view-modal').is(':visible')) {
                refreshViewModal();
                $('#rpr-view-modal').show().attr('aria-hidden', 'false');
            }
        });
    }

    function activeFormulaInput(){
        if (formulaFocus && document.body.contains(formulaFocus)) {
            return formulaFocus;
        }
        if (formulaFocusKind === 't') {
            formulaFocus = document.querySelector('.t-formula-total-input');
        } else {
            formulaFocus = document.querySelector('.t-formula-input');
        }
        return formulaFocus;
    }

    function insertAtCursor(el, text){
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        el.value = el.value.slice(0, start) + text + el.value.slice(end);
        const caret = start + text.length;
        el.focus();
        el.setSelectionRange(caret, caret);
        $(el).trigger('input');
    }

    function wrapOrInsert(el, fnName){
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        const val = el.value;
        let selected = val.slice(start, end);
        if (!selected && el.classList.contains('t-formula-total-input')) {
            selected = 'T';
        }
        let next, caretStart, caretEnd;
        if (selected) {
            next = val.slice(0, start) + fnName + '(' + selected + ')' + val.slice(end);
            caretStart = start + fnName.length + 1;
            caretEnd = caretStart + selected.length;
        } else {
            next = val.slice(0, start) + fnName + '()' + val.slice(end);
            caretStart = caretEnd = start + fnName.length + 1;
        }
        el.value = next;
        el.focus();
        el.setSelectionRange(caretStart, caretEnd);
        $(el).trigger('input');
    }

    function applyCalc($btn){
        const el = activeFormulaInput();
        if (!el) { alert('Agrega un tramo para escribir la fórmula'); return; }
        el.focus();
        const action = $btn.data('action');
        if (action === 'clear') {
            el.value = '';
            el.focus();
            $(el).trigger('input');
            return;
        }
        if (action === 'back') {
            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? el.value.length;
            if (start !== end) {
                el.value = el.value.slice(0, start) + el.value.slice(end);
                el.setSelectionRange(start, start);
            } else if (start > 0) {
                el.value = el.value.slice(0, start - 1) + el.value.slice(end);
                el.setSelectionRange(start - 1, start - 1);
            }
            el.focus();
            $(el).trigger('input');
            return;
        }
        const wrap = $btn.data('wrap');
        if (wrap) {
            wrapOrInsert(el, String(wrap));
            return;
        }
        const insert = $btn.data('insert');
        if (insert != null) {
            if (insert === 'T' && el.classList.contains('t-formula-input')) {
                alert('T (total de línea) solo se usa en la columna Fórmula T');
                return;
            }
            if (insert === 'P' && el.classList.contains('t-formula-total-input')) {
                insertAtCursor(el, 'P');
                return;
            }
            insertAtCursor(el, String(insert));
        }
    }

    function schedulePreview(){
        clearTimeout(previewTimer);
        previewTimer = setTimeout(updatePreviews, 280);
    }

    function updatePreviews(){
        const $rows = $('#tiers-tbody .tier-row');
        if (!$rows.length) return;
        const tiers = collectTiers();
        $rows.each(function(i){
            const t = tiers[i] || {};
            $(this).find('.rpr-hint-p').text(describeFormulaP(t.formula));
            $(this).find('.rpr-hint-t').text(describeFormulaT(t.formula_total));
        });
        $.post(ajaxurl, {
            action: 'riverso_price_rule_eval_formulas',
            nonce,
            p_asignado: $('#sim-p').val() || 0,
            qty: $('#sim-q').val() || 1,
            tiers: JSON.stringify(tiers),
        }, function(r){
            if (!r.success || !r.data.results) return;
            $rows.each(function(i){
                const res = r.data.results[i] || r.data.results[String(i)];
                const $cell = $(this).find('.rpr-preview');
                $(this).find('.t-formula-input, .t-formula-total-input').removeClass('is-invalid');
                if (!res) { $cell.text('—'); return; }
                if (!res.ok) {
                    $(this).find('.t-formula-input, .t-formula-total-input').addClass('is-invalid');
                    $cell.html('<span class="rpr-err">' + esc(res.hint || 'error') + '</span>');
                    return;
                }
                const b = res.breakdown;
                $cell.html('<span class="rpr-preview-line">' + esc(formatBreakdownPreview(b)) + '</span>');
            });
        });
    }

    $(document).on('click', '.rule-open', function(){
        openRule($(this).data('id'));
    });

    $('#rule-new').on('click', function(){
        const codigo = prompt('Código de la regla (ej. R-2):');
        if (!codigo) return;
        renderEditor({codigo, nombre:'', version:'-', estado:'borrador', tiers:[{qty_min:1, qty_max:'', formula:'P'}], assignments:[], _new:true});
    });

    $(document).on('click', '#tier-add', function(){
        $('#tiers-tbody').append(tierRow());
        const el = document.querySelector('#tiers-tbody .tier-row:last-child .t-formula-input');
        if (el) {
            formulaFocus = el;
            formulaFocusKind = 'p';
            $('.tier-row').removeClass('is-active');
            $(el).closest('.tier-row').addClass('is-active');
            el.focus();
        }
        schedulePreview();
    });
    $(document).on('click', '.tier-del', function(){
        $(this).closest('tr').remove();
        schedulePreview();
    });

    $(document).on('focus', '.t-formula-input', function(){
        formulaFocus = this;
        formulaFocusKind = 'p';
        $('.tier-row').removeClass('is-active');
        $(this).closest('.tier-row').addClass('is-active');
    });
    $(document).on('focus', '.t-formula-total-input', function(){
        formulaFocus = this;
        formulaFocusKind = 't';
        $('.tier-row').removeClass('is-active');
        $(this).closest('.tier-row').addClass('is-active');
    });
    $(document).on('input', '.t-formula-input', function(){
        $(this).closest('.tier-row').find('.rpr-hint-p').text(describeFormulaP(this.value));
        schedulePreview();
    });
    $(document).on('input', '.t-formula-total-input', function(){
        $(this).closest('.tier-row').find('.rpr-hint-t').text(describeFormulaT(this.value));
        schedulePreview();
    });
    $(document).on('input', '#sim-p, #sim-q, .t-minp, .t-piso-total', schedulePreview);

    $(document).on('mousedown', '.rpr-calc-btn', function(e){
        e.preventDefault();
        applyCalc($(this));
    });

    $(document).on('click', '#rpr-calc-toggle', function(){
        setCalcVisible(!calcVisible);
    });
    $(document).on('click', '#rpr-calc-hide', function(){
        setCalcVisible(false);
    });
    $('#rules-show-archived').on('change', renderRuleList);

    $('#rpr-tier-help').on('click', function(e){
        e.preventDefault();
        $('#rpr-help-panel').slideToggle(150);
    });

    $(document).on('click', '#rule-save', function(){
        const payload = {
            action:'riverso_price_rule_save', nonce,
            nombre: $('#rule-nombre').val(),
            tiers: JSON.stringify(collectTiers()),
        };
        if (current && !current._new && current.id){ payload.rule_id = current.id; }
        else if (current){ payload.codigo = current.codigo; }
        $.post(ajaxurl, payload, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert(r.data.message);
            loadRules();
            openRule(r.data.rule_id);
        });
    });

    $(document).on('click', '#rule-approve', function(){
        if (!current || !current.id) return;
        $.post(ajaxurl, {action:'riverso_price_rule_approve', nonce, rule_id:current.id}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert(r.data.message); loadRules();
            openRule(current.id);
        });
    });

    $(document).on('click', '#rule-assign', function(){
        if (!current || !current.id) { alert('Guarda la regla primero'); return; }
        $.post(ajaxurl, {action:'riverso_price_rule_assign', nonce, rule_id:current.id, target_tipo:$('#assign-tipo').val(), target_id:$('#assign-id').val()}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert(r.data.message);
            openRule(current.id);
        });
    });

    $(document).on('click', '#rpr-open-assign-view, #rpr-open-assign-view-rule', function(){
        openViewModal();
    });
    $(document).on('click', '#rpr-view-modal-close', closeViewModal);
    $(document).on('click', '#rpr-view-modal', function(e){
        if (e.target === this) {
            closeViewModal();
            return;
        }
        if (!$(e.target).closest('.rpr-view-rule-picker').length) {
            $('#rpr-view-rule-suggest').hide();
        }
    });
    $(document).on('click', '#rpr-open-assign-search, #rpr-open-assign-search-rule', function(){
        const fromRule = $(this).attr('id') === 'rpr-open-assign-search-rule';
        openAssignModal({
            scope: fromRule && current && current.id ? 'current' : 'all',
            tipo: 'all',
        });
    });
    $(document).on('click', '#rpr-assign-modal-close', closeAssignModal);
    $(document).on('click', '#rpr-assign-modal', function(e){
        if (e.target === this) closeAssignModal();
    });
    $(document).on('click', '#rpr-assign-search-btn', searchAssignments);
    $(document).on('keydown', '#rpr-assign-q', function(e){
        if (e.key === 'Enter') { e.preventDefault(); searchAssignments(); }
    });
    let viewRuleSuggestTimer = null;
    $(document).on('focus input', '#rpr-view-rule-q', function(){
        const q = $(this).val();
        clearTimeout(viewRuleSuggestTimer);
        viewRuleSuggestTimer = setTimeout(function(){
            const $box = $('#rpr-view-rule-suggest');
            if (!allRules.length) {
                loadRules();
                $box.html('<div class="rpr-suggest-empty">Cargando reglas…</div>').show();
                return;
            }
            $box.html(renderRuleSuggestList(q)).show();
        }, 150);
    });
    $(document).on('keydown', '#rpr-view-rule-q', function(e){
        if (e.key === 'Escape') {
            $('#rpr-view-rule-suggest').hide().empty();
        }
    });
    $(document).on('click', '.rpr-view-rule-item', function(){
        const id = $(this).data('id');
        $('#rpr-view-rule-q').val('');
        selectViewRule(id);
    });

    $(document).on('click', '#rpr-view-search-btn', function(){
        const q = ($('#rpr-view-q').val() || '').trim();
        if (!q) { refreshViewModal(); return; }
        if (!current || !current.id) return;
        $('#rpr-view-list').html('<p class="description">Buscando…</p>');
        $.post(ajaxurl, {
            action: 'riverso_price_rule_search_assignments',
            nonce, search: q, tipo: 'all', rule_id: current.id, limit: 50,
        }, function(r){
            if (!r.success) {
                $('#rpr-view-list').html('<p class="description">' + esc((r.data && r.data.message) || 'Error') + '</p>');
                return;
            }
            $('#rpr-view-list').html(renderAssignmentsList(r.data.items || []));
        });
    });
    $(document).on('keydown', '#rpr-view-q', function(e){
        if (e.key === 'Enter') { e.preventDefault(); $('#rpr-view-search-btn').click(); }
    });
    $(document).on('input', '#assign-id', function(){
        clearTimeout(window._rprLookupTimer);
        window._rprLookupTimer = setTimeout(lookupAssignName, 250);
    });
    $(document).on('change', '#assign-tipo', function(){
        lookupAssignName();
        $('#assign-suggest').hide().empty();
    });
    $(document).on('input', '#assign-q', function(){
        clearTimeout(assignSuggestTimer);
        assignSuggestTimer = setTimeout(searchAssignTargets, 250);
    });
    $(document).on('keydown', '#assign-q', function(e){
        if (e.key === 'Enter') { e.preventDefault(); searchAssignTargets(); }
    });
    $(document).on('click', '.rpr-suggest-item', function(){
        const tipo = $(this).data('tipo');
        const id = $(this).data('id');
        const nombre = $(this).data('nombre') || '';
        $('#assign-tipo').val(tipo);
        $('#assign-id').val(id);
        $('#assign-name').text(nombre);
        $('#assign-q').val('');
        $('#assign-suggest').hide().empty();
    });
    $(document).on('click', '.rpr-use-assign', function(){
        applyAssignTarget($(this).data('tipo'), $(this).data('id'));
    });

    $(document).on('click', '#rule-sim', function(){
        const payload = {
            action:'riverso_price_rule_preview', nonce,
            p_asignado:$('#sim-p').val(),
            qty:$('#sim-q').val(),
            tiers: JSON.stringify(collectTiers()),
        };
        if (current && current.id) payload.rule_id = current.id;
        $.post(ajaxurl, payload, function(r){
            if (!r.success){ $('#sim-result').text(r.data && r.data.message ? r.data.message : 'Error'); return; }
            if (r.data.price === null) {
                $('#sim-result').html(' &rarr; <strong>sin tramo</strong>');
                return;
            }
            const qty = Number(r.data.qty);
            if (r.data.breakdown) {
                $('#sim-result').html(' &rarr; ' + formatSimBreakdown(r.data.breakdown, qty));
                return;
            }
            const unit = Number(r.data.price);
            const total = r.data.total != null ? Number(r.data.total) : unit * qty;
            $('#sim-result').html(
                ' &rarr; <span class="rpr-sim-unit">' + fmt(unit) + ' c/u</span> × ' + fmt(qty) +
                ' = <strong class="rpr-sim-total">Total ' + fmt(total) + '</strong>'
            );
        });
    });

    loadRules();
    const params = new URLSearchParams(window.location.search);
    const urlId = params.get('id');
    if (urlId) openRule(urlId);
    const assign = params.get('assign');
    const targetId = params.get('target_id') || params.get('grupo_id');
    if (assign && targetId) {
        window._rprPendingAssign = {
            tipo: assign === 'familia' ? 'familia' : assign,
            id: targetId,
        };
        openViewModal();
    }
});
</script>
