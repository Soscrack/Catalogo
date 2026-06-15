<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_prices');
$can_approve = current_user_can('riverso_approve_prices');
?>
<div class="wrap">
    <h1>Precios</h1>
    <p>Costo de referencia (c_ref), precio de referencia (p_ref = 1.8 &times; c_ref) y precio asignado por canal.</p>

    <div style="display:flex; gap:8px; margin:12px 0; align-items:center;">
        <label>Canal:
            <select id="pricing-canal">
                <option value="local">Local</option>
                <option value="online">Online</option>
            </select>
        </label>
        <button class="button" id="pricing-btn-reload">Actualizar</button>
        <label style="margin-left:auto;">
            <input type="checkbox" id="pricing-only-alerts"> Solo alertas de margen
        </label>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>SKU</th>
                <th>Producto</th>
                <th>c_ref</th>
                <th>p_ref</th>
                <th>p_asignado</th>
                <th>Estado</th>
                <th>Alerta</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody id="pricing-tbody">
            <tr><td colspan="8">Cargando...</td></tr>
        </tbody>
    </table>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canApprove = <?php echo $can_approve ? 'true' : 'false'; ?>;

    function fmt(v){ return (v === null || v === undefined || v === '') ? '-' : Number(v).toLocaleString('es-CL'); }

    function render(items){
        if (!items || !items.length){
            $('#pricing-tbody').html('<tr><td colspan="8">Sin precios registrados.</td></tr>');
            return;
        }
        const onlyAlerts = $('#pricing-only-alerts').is(':checked');
        const rows = items.filter(it => !onlyAlerts || parseInt(it.alerta_margen) === 1).map(it => {
            const alerta = parseInt(it.alerta_margen) === 1
                ? '<span style="color:#b32d2e;font-weight:bold;">Margen bajo</span>' : '-';
            const assignInput = canManage
                ? `<input type="number" step="0.01" min="0" class="small-text pricing-assigned" data-id="${it.id}" value="${it.p_asignado || ''}" style="width:90px;">`
                : fmt(it.p_asignado);
            let actions = '';
            if (canManage){
                actions += `<button class="button button-small pricing-save" data-id="${it.id}">Guardar</button> `;
                actions += `<button class="button button-small pricing-recalc" data-base="${it.producto_base_id}" data-canal="${it.canal}" data-var="${it.woocommerce_variation_id}">Recalcular</button> `;
            }
            if (canApprove){
                actions += `<button class="button button-small button-primary pricing-approve" data-id="${it.id}">Aprobar</button>`;
            }
            return `<tr>
                <td>${it.canonical_sku || '-'}</td>
                <td>${it.nombre_canonico || '-'}</td>
                <td>${fmt(it.c_ref)}</td>
                <td>${fmt(it.p_ref)}</td>
                <td>${assignInput}</td>
                <td>${it.estado_aprobacion}</td>
                <td>${alerta}</td>
                <td>${actions || '-'}</td>
            </tr>`;
        }).join('');
        $('#pricing-tbody').html(rows || '<tr><td colspan="8">Sin resultados.</td></tr>');
    }

    function load(){
        $.post(ajaxurl, {action:'riverso_pricing_list', nonce, canal: $('#pricing-canal').val()}, function(r){
            if (!r.success){ $('#pricing-tbody').html('<tr><td colspan="8">Error</td></tr>'); return; }
            render(r.data.items);
        });
    }

    $('#pricing-btn-reload, #pricing-canal').on('change click', function(e){ if (e.type==='click' && this.id==='pricing-canal') return; load(); });
    $('#pricing-only-alerts').on('change', load);

    $(document).on('click', '.pricing-save', function(){
        const id = $(this).data('id');
        const val = $(`.pricing-assigned[data-id="${id}"]`).val();
        if (!val || Number(val) <= 0){ alert('Ingresa un precio asignado válido'); return; }
        $.post(ajaxurl, {action:'riverso_pricing_set_assigned', nonce, precio_id:id, p_asignado:val}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    });

    $(document).on('click', '.pricing-recalc', function(){
        $.post(ajaxurl, {
            action:'riverso_pricing_recalc', nonce,
            producto_base_id: $(this).data('base'),
            canal: $(this).data('canal'),
            woocommerce_variation_id: $(this).data('var')
        }, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    });

    $(document).on('click', '.pricing-approve', function(){
        const id = $(this).data('id');
        $.post(ajaxurl, {action:'riverso_pricing_approve', nonce, precio_id:id}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    });

    load();
});
</script>
