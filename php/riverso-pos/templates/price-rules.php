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
    <p>Reglas por tramos de cantidad, con fórmulas tipo calculadora. <code>P</code> es el precio asignado.
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
            <?php if ($can_manage): ?>
            <p><button class="button button-primary" id="rule-new">Nueva regla</button></p>
            <?php endif; ?>
        </div>

        <div class="rpr-editor-wrap">
            <h2>Editor de tramos</h2>
            <div id="rule-editor" class="rpr-editor">
                <p>Selecciona o crea una regla.</p>
            </div>
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
    let previewTimer = null;
    let allRules = [];
    let calcVisible = localStorage.getItem('rpr-calc-visible') === '1';

    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function describeFormula(f){
        return String(f || '')
            .replace(/\bT100\s*\(/gi, 'techo_centena(')
            .replace(/\bT50\s*\(/gi, 'techo_cincuentena(')
            .replace(/\bT10\s*\(/gi, 'techo_decena(')
            .replace(/\btecho_centana\s*\(/gi, 'techo_centena(')
            .replace(/\bMAX\s*\(/gi, 'máximo(')
            .replace(/\bMIN\s*\(/gi, 'mínimo(')
            .replace(/\bP\b/g, 'precio');
    }

    function loadRules(){
        $.post(ajaxurl, {action:'riverso_price_rules_list', nonce}, function(r){
            if (!r.success){ $('#rules-tbody').html('<tr><td colspan="5">Error</td></tr>'); return; }
            allRules = r.data.rules || [];
            renderRuleList();
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
            {label:'P', insert:'P', cls:'rpr-calc-var', title:'Precio asignado'},
            {label:'T10()', wrap:'T10', cls:'rpr-calc-fn', title:'techo_decena: techo a 10'},
            {label:'T50()', wrap:'T50', cls:'rpr-calc-fn', title:'techo_cincuentena: techo a 50'},
            {label:'T100()', wrap:'T100', cls:'rpr-calc-fn', title:'techo_centena: techo a 100'},
            {label:'7', insert:'7'}, {label:'8', insert:'8'}, {label:'9', insert:'9'}, {label:'÷', insert:'/', cls:'rpr-calc-op'},
            {label:'4', insert:'4'}, {label:'5', insert:'5'}, {label:'6', insert:'6'}, {label:'×', insert:'*', cls:'rpr-calc-op'},
            {label:'1', insert:'1'}, {label:'2', insert:'2'}, {label:'3', insert:'3'}, {label:'−', insert:'-', cls:'rpr-calc-op'},
            {label:'0', insert:'0'}, {label:'.', insert:'.'}, {label:',', insert:',', title:'Separador de argumentos (MAX, MIN)'}, {label:'+', insert:'+', cls:'rpr-calc-op'},
            {label:'(', insert:'('}, {label:')', insert:')'},
            {label:'MAX', wrap:'MAX', cls:'rpr-calc-fn', title:'Máximo: MAX(a, b)'},
            {label:'⌫', action:'back', cls:'rpr-calc-danger', title:'Borrar último carácter'},
            {label:'C', action:'clear', cls:'rpr-calc-danger', title:'Borrar fórmula'},
        ];
        return `<div class="rpr-calc" aria-label="Calculadora de fórmulas">
            <div class="rpr-calc-head">
                <strong>Calculadora</strong>
                <button type="button" class="rpr-calc-close" id="rpr-calc-hide" title="Ocultar calculadora">×</button>
            </div>
            <div class="rpr-calc-legend">
                <span><b>T10()</b> techo_decena</span>
                <span><b>T50()</b> techo_cincuentena</span>
                <span><b>T100()</b> techo_centena</span>
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
            <p class="rpr-calc-hint">Selecciona la expresión y pulsa T10 / T50 / T100 para envolverla. Ejemplo: <code>T10(P*3)</code></p>
        </div>`;
    }

    function tierRow(t){
        t = t || {qty_min:'', qty_max:'', formula:'P', total_minimo:''};
        const formula = t.formula || 'P';
        return `<tr class="tier-row">
            <td><input type="number" class="t-min small-text" value="${esc(t.qty_min ?? '')}" min="0"></td>
            <td><input type="number" class="t-max small-text" value="${esc(t.qty_max ?? '')}" placeholder="∞" min="0"></td>
            <td>
                <input type="text" class="t-formula-input" value="${esc(formula)}" spellcheck="false" autocomplete="off" placeholder="T10(P*3)">
                <div class="rpr-formula-hint">${esc(describeFormula(formula))}</div>
            </td>
            <td><input type="number" step="0.01" class="t-minp small-text" value="${esc(t.total_minimo ?? '')}" placeholder="—"></td>
            <td class="rpr-preview" data-empty="—">—</td>
            <td>${canManage ? '<button type="button" class="button button-small tier-del" title="Quitar tramo">×</button>' : ''}</td>
        </tr>`;
    }

    function renderEditor(rule){
        current = rule;
        const editable = canManage;
        const tiersHtml = (rule.tiers || []).map(tierRow).join('');
        const assigns = (rule.assignments || []).map(a => `${a.target_tipo}#${a.target_id}`).join(', ') || 'ninguna';
        $('#rule-editor').html(`
            <p class="rpr-meta"><strong>${esc(rule.codigo)}</strong> v${esc(rule.version)} — ${esc(rule.estado)}</p>
            <p><label>Nombre: <input type="text" id="rule-nombre" value="${esc(rule.nombre || '')}" class="regular-text" ${editable?'':'disabled'}></label></p>
            <div class="rpr-editor-grid ${calcVisible ? '' : 'calc-hidden'}">
                <div class="rpr-tiers-wrap">
                    <table class="widefat striped rpr-tiers">
                        <thead><tr>
                            <th>Qty min</th>
                            <th>Qty max</th>
                            <th>Fórmula</th>
                            <th>Piso</th>
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
            <p><strong>Asignaciones:</strong> ${esc(assigns)}</p>
            ${editable ? `<p>
                <select id="assign-tipo"><option value="producto">producto_base_id</option><option value="familia">grupo_id</option><option value="categoria">categoria_id</option></select>
                <input type="number" id="assign-id" class="small-text" placeholder="ID">
                <button type="button" class="button" id="rule-assign">Asignar</button>
            </p>` : ''}
            <hr>
            <p class="rpr-sim"><strong>Simular:</strong>
                p_asignado <input type="number" id="sim-p" class="small-text" value="10" step="0.01">
                qty <input type="number" id="sim-q" class="small-text" value="1">
                <button type="button" class="button" id="rule-sim">Calcular</button>
                <span id="sim-result"></span>
            </p>
        `);
        const first = document.querySelector('.t-formula-input');
        if (first) {
            formulaFocus = first;
            $(first).closest('.tier-row').addClass('is-active');
        }
        schedulePreview();
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
            });
        });
        return tiers;
    }

    function openRule(id){
        $.post(ajaxurl, {action:'riverso_price_rule_get', nonce, rule_id:id}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            renderEditor(r.data.rule);
        });
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
        const selected = val.slice(start, end);
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
        if (!formulaFocus) {
            formulaFocus = document.querySelector('.t-formula-input');
        }
        const el = formulaFocus;
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
        const formulas = [];
        $rows.each(function(){
            const f = $(this).find('.t-formula-input').val() || '';
            formulas.push(f);
            $(this).find('.rpr-formula-hint').text(describeFormula(f));
        });
        $.post(ajaxurl, {
            action: 'riverso_price_rule_eval_formulas',
            nonce,
            p_asignado: $('#sim-p').val() || 0,
            formulas: JSON.stringify(formulas),
        }, function(r){
            if (!r.success || !r.data.results) return;
            $rows.each(function(i){
                const res = r.data.results[i] || r.data.results[String(i)];
                const $cell = $(this).find('.rpr-preview');
                const $input = $(this).find('.t-formula-input');
                $input.removeClass('is-invalid');
                if (!res) { $cell.text('—'); return; }
                if (!res.ok) {
                    $input.addClass('is-invalid');
                    $cell.html('<span class="rpr-err">' + esc(res.hint || 'error') + '</span>');
                    return;
                }
                if (res.value === null || res.value === undefined) {
                    $cell.text('—');
                    return;
                }
                let v = Number(res.value);
                const floorRaw = $(this).find('.t-minp').val();
                if (floorRaw !== '' && floorRaw != null && !isNaN(parseFloat(floorRaw))) {
                    v = Math.max(v, parseFloat(floorRaw));
                }
                v = Math.round(v * 100) / 100;
                $cell.text(v.toLocaleString('es-CL'));
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
        $('.tier-row').removeClass('is-active');
        $(this).closest('.tier-row').addClass('is-active');
    });
    $(document).on('input', '.t-formula-input', function(){
        $(this).closest('.tier-row').find('.rpr-formula-hint').text(describeFormula(this.value));
        schedulePreview();
    });
    $(document).on('input', '#sim-p, .t-minp', schedulePreview);

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
        });
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
            const unit = Number(r.data.price);
            const qty = Number(r.data.qty);
            const total = r.data.total != null ? Number(r.data.total) : unit * qty;
            const fmt = v => v.toLocaleString('es-CL');
            $('#sim-result').html(
                ' &rarr; <span class="rpr-sim-unit">' + fmt(unit) + ' c/u</span>' +
                ' &times; ' + fmt(qty) +
                ' = <strong class="rpr-sim-total">Total ' + fmt(total) + '</strong>'
            );
        });
    });

    loadRules();
    const urlId = new URLSearchParams(window.location.search).get('id');
    if (urlId) openRule(urlId);
});
</script>
