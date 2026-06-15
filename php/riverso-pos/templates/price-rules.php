<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_prices');
$can_approve = current_user_can('riverso_approve_prices');
?>
<div class="wrap">
    <h1>Reglas de Precio</h1>
    <p>Reglas por tramos de cantidad, versionables y asignables a producto, familia o categoría.</p>

    <div style="display:flex; gap:24px; align-items:flex-start;">
        <div style="flex:1; min-width:320px;">
            <h2>Reglas</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Código</th><th>Nombre</th><th>Ver.</th><th>Estado</th><th></th></tr></thead>
                <tbody id="rules-tbody"><tr><td colspan="5">Cargando...</td></tr></tbody>
            </table>
            <?php if ($can_manage): ?>
            <p><button class="button button-primary" id="rule-new">Nueva regla</button></p>
            <?php endif; ?>
        </div>

        <div style="flex:1.4; min-width:420px;">
            <h2>Editor de tramos</h2>
            <div id="rule-editor" style="background:#fff; border:1px solid #ddd; padding:12px;">
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

    function loadRules(){
        $.post(ajaxurl, {action:'riverso_price_rules_list', nonce}, function(r){
            if (!r.success){ $('#rules-tbody').html('<tr><td colspan="5">Error</td></tr>'); return; }
            if (!r.data.rules.length){ $('#rules-tbody').html('<tr><td colspan="5">Sin reglas</td></tr>'); return; }
            $('#rules-tbody').html(r.data.rules.map(rl => `
                <tr>
                    <td>${rl.codigo}</td><td>${rl.nombre}</td><td>${rl.version}</td>
                    <td>${rl.estado}</td>
                    <td><button class="button button-small rule-open" data-id="${rl.id}">Abrir</button></td>
                </tr>`).join(''));
        });
    }

    function tierRow(t){
        t = t || {qty_min:'', qty_max:'', formula_tipo:'multiplicador', multiplicador:'', addendo:'', redondeo:'ninguno', total_minimo:''};
        return `<tr class="tier-row">
            <td><input type="number" class="t-min small-text" value="${t.qty_min ?? ''}"></td>
            <td><input type="number" class="t-max small-text" value="${t.qty_max ?? ''}" placeholder="∞"></td>
            <td><select class="t-formula">
                <option value="multiplicador" ${t.formula_tipo==='multiplicador'?'selected':''}>x</option>
                <option value="suma" ${t.formula_tipo==='suma'?'selected':''}>+</option>
                <option value="rango" ${t.formula_tipo==='rango'?'selected':''}>rango</option>
            </select></td>
            <td><input type="number" step="0.0001" class="t-mult small-text" value="${t.multiplicador ?? ''}"></td>
            <td><input type="number" step="0.01" class="t-add small-text" value="${t.addendo ?? ''}"></td>
            <td><select class="t-round">
                <option value="ninguno" ${t.redondeo==='ninguno'?'selected':''}>ninguno</option>
                <option value="techo_decena" ${t.redondeo==='techo_decena'?'selected':''}>techo_decena</option>
            </select></td>
            <td><input type="number" step="0.01" class="t-minp small-text" value="${t.total_minimo ?? ''}"></td>
            <td><button class="button button-small tier-del">x</button></td>
        </tr>`;
    }

    function renderEditor(rule){
        current = rule;
        const editable = canManage;
        const tiersHtml = (rule.tiers || []).map(tierRow).join('');
        const assigns = (rule.assignments || []).map(a => `${a.target_tipo}#${a.target_id}`).join(', ') || 'ninguna';
        $('#rule-editor').html(`
            <p><strong>${rule.codigo}</strong> v${rule.version} - ${rule.estado}</p>
            <p><label>Nombre: <input type="text" id="rule-nombre" value="${rule.nombre || ''}" class="regular-text" ${editable?'':'disabled'}></label></p>
            <table class="widefat striped">
                <thead><tr><th>Qty min</th><th>Qty max</th><th>Fórmula</th><th>x</th><th>+</th><th>Redondeo</th><th>Piso</th><th></th></tr></thead>
                <tbody id="tiers-tbody">${tiersHtml || ''}</tbody>
            </table>
            ${editable ? '<p><button class="button" id="tier-add">+ Tramo</button> <button class="button button-primary" id="rule-save">Guardar</button></p>' : ''}
            ${canApprove && rule.estado !== 'aprobada' ? '<p><button class="button button-primary" id="rule-approve">Aprobar versión</button></p>' : ''}
            <hr>
            <p><strong>Asignaciones:</strong> ${assigns}</p>
            ${editable ? `<p>
                <select id="assign-tipo"><option value="producto">producto_base_id</option><option value="familia">grupo_id</option><option value="categoria">categoria_id</option></select>
                <input type="number" id="assign-id" class="small-text" placeholder="ID">
                <button class="button" id="rule-assign">Asignar</button>
            </p>` : ''}
            <hr>
            <p><strong>Simular:</strong>
                p_asignado <input type="number" id="sim-p" class="small-text" value="10">
                qty <input type="number" id="sim-q" class="small-text" value="1">
                <button class="button" id="rule-sim">Calcular</button>
                <span id="sim-result"></span>
            </p>
        `);
    }

    function collectTiers(){
        const tiers = [];
        $('#tiers-tbody .tier-row').each(function(){
            tiers.push({
                qty_min: $(this).find('.t-min').val(),
                qty_max: $(this).find('.t-max').val(),
                formula_tipo: $(this).find('.t-formula').val(),
                multiplicador: $(this).find('.t-mult').val(),
                addendo: $(this).find('.t-add').val(),
                redondeo: $(this).find('.t-round').val(),
                total_minimo: $(this).find('.t-minp').val(),
            });
        });
        return tiers;
    }

    $(document).on('click', '.rule-open', function(){
        $.post(ajaxurl, {action:'riverso_price_rule_get', nonce, rule_id:$(this).data('id')}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            renderEditor(r.data.rule);
        });
    });

    $('#rule-new').on('click', function(){
        const codigo = prompt('Código de la regla (ej. R-2):');
        if (!codigo) return;
        renderEditor({codigo, nombre:'', version:'-', estado:'borrador', tiers:[], assignments:[], _new:true});
    });

    $(document).on('click', '#tier-add', function(){ $('#tiers-tbody').append(tierRow()); });
    $(document).on('click', '.tier-del', function(){ $(this).closest('tr').remove(); });

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
            $.post(ajaxurl, {action:'riverso_price_rule_get', nonce, rule_id:r.data.rule_id}, function(rr){
                if (rr.success) renderEditor(rr.data.rule);
            });
        });
    });

    $(document).on('click', '#rule-approve', function(){
        if (!current || !current.id) return;
        $.post(ajaxurl, {action:'riverso_price_rule_approve', nonce, rule_id:current.id}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert(r.data.message); loadRules();
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
        if (!current || !current.id) { alert('Guarda la regla primero'); return; }
        $.post(ajaxurl, {action:'riverso_price_rule_preview', nonce, rule_id:current.id, p_asignado:$('#sim-p').val(), qty:$('#sim-q').val()}, function(r){
            if (!r.success){ $('#sim-result').text('Error'); return; }
            $('#sim-result').html(' &rarr; <strong>' + (r.data.price === null ? 'sin tramo' : Number(r.data.price).toLocaleString('es-CL')) + '</strong>');
        });
    });

    loadRules();
});
</script>
