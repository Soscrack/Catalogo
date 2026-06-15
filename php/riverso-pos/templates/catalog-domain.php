<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
?>
<div class="wrap">
    <h1>Catalogo Canonico</h1>
    <p>Buscador por codigo de barra/proveedor y editor auditado de vinculos.</p>

    <div style="display:flex; gap:8px; margin:12px 0;">
        <input type="text" id="domain-search-code" class="regular-text" placeholder="Codigo de barra, codigo proveedor o SKU">
        <select id="domain-search-supplier"><option value="">Todos los proveedores</option></select>
        <button class="button button-primary" id="domain-btn-search">Buscar</button>
    </div>

    <div id="domain-result" style="display:none; background:#fff; border:1px solid #ddd; padding:12px; margin-bottom:12px;"></div>

    <h2>Editor con protocolo de auditoria</h2>
    <table class="form-table">
        <tr><th>Link ID</th><td><input type="number" id="domain-link-id" class="small-text"></td></tr>
        <tr><th>Proveedor</th><td><select id="domain-supplier-id"></select></td></tr>
        <tr><th>Codigo proveedor</th><td><input type="text" id="domain-supplier-code" class="regular-text"></td></tr>
        <tr><th>Codigo de barra proveedor</th><td><input type="text" id="domain-supplier-barcode" class="regular-text"></td></tr>
        <tr><th>Descripcion proveedor</th><td><textarea id="domain-supplier-description" rows="2" class="large-text"></textarea></td></tr>
        <tr><th>Producto Woo</th><td>
            <input type="text" id="domain-product-search" class="regular-text" placeholder="Buscar producto por nombre o SKU">
            <div id="domain-product-results" style="display:none; border:1px solid #ddd; max-height:180px; overflow:auto;"></div>
            <input type="hidden" id="domain-product-id">
            <input type="hidden" id="domain-variation-id">
            <input type="hidden" id="domain-internal-sku">
            <div id="domain-product-selected" style="margin-top:6px; color:#2271b1;"></div>
        </td></tr>
        <tr><th>Notas</th><td><textarea id="domain-notes" rows="2" class="large-text"></textarea></td></tr>
        <tr><th>Motivo de auditoria (obligatorio)</th><td><textarea id="domain-audit-reason" rows="2" class="large-text" placeholder="Describe por que cambias este vinculo..."></textarea></td></tr>
    </table>
    <p><button class="button button-primary" id="domain-btn-save">Guardar mapeo</button></p>

    <h2>Historial de auditoria</h2>
    <div id="domain-audit-list" style="background:#fff; border:1px solid #ddd; padding:12px;">Sin registros</div>

    <?php if (current_user_can('riverso_manage_matching')): ?>
    <hr>
    <h2>Matching de productos (revision)</h2>
    <p>Flujo: UNMATCHED &rarr; AUTO_MATCH &rarr; HUMAN_REVIEW &rarr; VERIFIED / REJECTED.</p>
    <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
        <select id="match-filter-estado">
            <option value="">Todos los estados</option>
            <option value="UNMATCHED">UNMATCHED</option>
            <option value="AUTO_MATCH">AUTO_MATCH</option>
            <option value="HUMAN_REVIEW">HUMAN_REVIEW</option>
            <option value="VERIFIED">VERIFIED</option>
            <option value="REJECTED">REJECTED</option>
        </select>
        <button class="button" id="match-reload">Actualizar</button>
        <button class="button button-secondary" id="match-run-all">Ejecutar matching automatico</button>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th>ID</th><th>Codigo proveedor</th><th>Nombre proveedor</th>
            <th>Producto base (SKU)</th><th>Estado</th><th>Score</th><th>Origen</th><th>Acciones</th>
        </tr></thead>
        <tbody id="match-tbody"><tr><td colspan="8">Sin datos. Pulsa Actualizar.</td></tr></tbody>
    </table>
    <?php endif; ?>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';

    function loadSuppliers() {
        $.post(ajaxurl, {action:'riverso_get_providers', nonce}, function(r){
            if (!r.success || !r.data.providers) return;
            const opts = ['<option value="">Seleccionar proveedor</option>'];
            const optsSearch = ['<option value="">Todos los proveedores</option>'];
            r.data.providers.forEach(p => {
                opts.push(`<option value="${p.id}">${p.nombre}</option>`);
                optsSearch.push(`<option value="${p.id}">${p.nombre}</option>`);
            });
            $('#domain-supplier-id').html(opts.join(''));
            $('#domain-search-supplier').html(optsSearch.join(''));
        });
    }

    function renderResult(data){
        const p = data.product || {};
        const d = data.domain || {};
        const lots = (data.lots || []).map(l => `<li>${l.lote_codigo} | disp: ${l.cantidad_disponible} | costo: ${l.costo_unitario || '-'}</li>`).join('');
        $('#domain-result').html(`
            <strong>Origen:</strong> ${data.source || '-'}<br>
            <strong>Producto:</strong> ${p.name || '-'} (${p.sku || '-'})<br>
            <strong>Proveedor/Codigo:</strong> ${d.proveedor_id || '-'} / ${d.codigo_proveedor || '-'}<br>
            <strong>IDs canonicos:</strong> base=${d.producto_base_id || '-'} proveedor=${d.id || d.producto_proveedor_id || '-'}<br>
            <strong>Lotes:</strong>
            <ul>${lots || '<li>Sin lotes</li>'}</ul>
        `).show();
    }

    function loadAudit(entityId){
        $.post(ajaxurl, {action:'riverso_domain_get_audit', nonce, entity_id: entityId || ''}, function(r){
            if (!r.success || !r.data.items || !r.data.items.length) {
                $('#domain-audit-list').text('Sin registros');
                return;
            }
            const html = r.data.items.map(i => `
                <div style="border-bottom:1px solid #eee; padding:8px 0;">
                    <strong>${i.action_label || i.action}</strong> - ${i.user_name || 'Sistema'} - ${i.created_at}<br>
                    <small>${i.details || ''}</small>
                </div>
            `).join('');
            $('#domain-audit-list').html(html);
        });
    }

    $('#domain-btn-search').on('click', function(){
        const code = $('#domain-search-code').val().trim();
        if (!code) return;
        $.post(ajaxurl, {
            action:'riverso_domain_search_barcode',
            nonce,
            code,
            supplier_id: $('#domain-search-supplier').val()
        }, function(r){
            if (!r.success) {
                $('#domain-result').html(`<span style="color:#b32d2e;">${r.data || 'No encontrado'}</span>`).show();
                return;
            }
            const data = r.data;
            renderResult(data);
            const d = data.domain || {};
            const p = data.product || {};
            $('#domain-link-id').val(d.supplier_link_id || '');
            $('#domain-supplier-id').val(d.proveedor_id || $('#domain-search-supplier').val() || '');
            $('#domain-supplier-code').val(d.codigo_proveedor || '');
            $('#domain-supplier-barcode').val(d.codigo_barras_proveedor || '');
            $('#domain-supplier-description').val(d.nombre_proveedor || '');
            $('#domain-product-id').val(d.woocommerce_product_id || p.id || '');
            $('#domain-variation-id').val(d.woocommerce_variation_id || '');
            $('#domain-internal-sku').val(d.canonical_sku || p.sku || '');
            $('#domain-product-selected').text(p.name ? `${p.name} (${p.sku || '-'})` : '');
            loadAudit(d.supplier_link_id || '');
        });
    });

    $('#domain-product-search').on('keyup', function(){
        const term = $(this).val().trim();
        if (term.length < 2) { $('#domain-product-results').hide(); return; }
        $.post(ajaxurl, {action:'riverso_search_product_for_link', nonce, term}, function(r){
            if (!r.success || !r.data.length) { $('#domain-product-results').hide(); return; }
            const html = r.data.map(x => `<div class="domain-product-item" data-id="${x.ID}" data-name="${x.name}" data-sku="${x.sku || ''}" style="padding:6px; cursor:pointer;">${x.name} <small>(${x.sku || '-'})</small></div>`).join('');
            $('#domain-product-results').html(html).show();
        });
    });

    $(document).on('click', '.domain-product-item', function(){
        $('#domain-product-id').val($(this).data('id'));
        $('#domain-variation-id').val(0);
        $('#domain-internal-sku').val($(this).data('sku') || '');
        $('#domain-product-selected').text(`${$(this).data('name')} (${($(this).data('sku') || '-')})`);
        $('#domain-product-results').hide();
    });

    $('#domain-btn-save').on('click', function(){
        const payload = {
            action: 'riverso_domain_update_mapping',
            nonce,
            link_id: $('#domain-link-id').val(),
            supplier_id: $('#domain-supplier-id').val(),
            supplier_code: $('#domain-supplier-code').val().trim(),
            supplier_barcode: $('#domain-supplier-barcode').val().trim(),
            supplier_description: $('#domain-supplier-description').val().trim(),
            product_id: $('#domain-product-id').val(),
            variation_id: $('#domain-variation-id').val(),
            internal_sku: $('#domain-internal-sku').val().trim(),
            notes: $('#domain-notes').val().trim(),
            audit_reason: $('#domain-audit-reason').val().trim()
        };
        $.post(ajaxurl, payload, function(r){
            if (!r.success) { alert(r.data || 'Error guardando'); return; }
            alert(r.data.message || 'Guardado');
            if (r.data.link_id) {
                $('#domain-link-id').val(r.data.link_id);
                loadAudit(r.data.link_id);
            }
            $('#domain-audit-reason').val('');
        });
    });

    // ===================== Matching de productos =====================
    function renderMatches(items){
        if (!items || !items.length){
            $('#match-tbody').html('<tr><td colspan="8">Sin resultados.</td></tr>');
            return;
        }
        $('#match-tbody').html(items.map(it => {
            const estado = it.match_estado || 'UNMATCHED';
            return `<tr>
                <td>${it.id}</td>
                <td>${it.codigo_proveedor || '-'}</td>
                <td>${it.nombre_proveedor || '-'}</td>
                <td>${it.nombre_canonico || '-'} <small>(${it.canonical_sku || '-'})</small></td>
                <td>${estado}</td>
                <td>${it.match_score === null ? '-' : it.match_score}</td>
                <td>${it.match_origen || '-'}</td>
                <td>
                    <button class="button button-small match-run" data-id="${it.id}">Re-evaluar</button>
                    <button class="button button-small button-primary match-verify" data-id="${it.id}">Verificar</button>
                    <button class="button button-small match-reject" data-id="${it.id}">Rechazar</button>
                </td>
            </tr>`;
        }).join(''));
    }

    function loadMatches(){
        if (!$('#match-tbody').length) return;
        $.post(ajaxurl, {action:'riverso_matching_list', nonce, estado:$('#match-filter-estado').val()}, function(r){
            if (!r.success){ $('#match-tbody').html('<tr><td colspan="8">Error</td></tr>'); return; }
            renderMatches(r.data.items);
        });
    }

    $('#match-reload, #match-filter-estado').on('click change', loadMatches);

    $('#match-run-all').on('click', function(){
        $.post(ajaxurl, {action:'riverso_matching_run_all', nonce, limit:200}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert('Procesados: ' + r.data.processed);
            loadMatches();
        });
    });

    $(document).on('click', '.match-run', function(){
        $.post(ajaxurl, {action:'riverso_matching_run', nonce, pp_id:$(this).data('id')}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            loadMatches();
        });
    });

    $(document).on('click', '.match-verify', function(){
        $.post(ajaxurl, {action:'riverso_matching_set_state', nonce, pp_id:$(this).data('id'), estado:'VERIFIED'}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            loadMatches();
        });
    });

    $(document).on('click', '.match-reject', function(){
        $.post(ajaxurl, {action:'riverso_matching_set_state', nonce, pp_id:$(this).data('id'), estado:'REJECTED'}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            loadMatches();
        });
    });

    loadSuppliers();
});
</script>
