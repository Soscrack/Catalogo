<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_publish = current_user_can('riverso_publish_products');
?>
<div class="wrap">
    <h1>Publicación WooCommerce</h1>
    <p>Los productos solo se publican cuando producto, precio, categoría y atributos están aprobados por humanos.</p>

    <div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
        <input type="text" id="publish-search" class="regular-text" placeholder="SKU o nombre">
        <button class="button" id="publish-reload">Actualizar</button>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID</th><th>SKU</th><th>Producto</th><th>Woo</th><th>Gates</th><th>Pipeline</th><th>Acciones</th>
            </tr>
        </thead>
        <tbody id="publish-tbody"><tr><td colspan="7">Cargando...</td></tr></tbody>
    </table>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canPublish = <?php echo $can_publish ? 'true' : 'false'; ?>;
    const gates = [
        ['human_product_review', 'Producto'],
        ['human_price_review', 'Precio'],
        ['human_category_review', 'Categoría'],
        ['human_attribute_review', 'Atributos']
    ];

    function esc(v){ return $('<div>').text(v === null || v === undefined ? '' : v).html(); }
    function approved(it){ return gates.every(g => (it[g[0]] || 'pending') === 'approved'); }
    function gateHtml(it){
        return gates.map(g => {
            const val = it[g[0]] || 'pending';
            const label = val === 'approved' ? 'Aprobado' : (val === 'rejected' ? 'Rechazado' : 'Pendiente');
            const btn = val !== 'approved'
                ? ` <button class="button button-small publish-gate" data-id="${it.id}" data-gate="${g[0]}">Aprobar</button>`
                : '';
            return `<div><strong>${g[1]}:</strong> ${label}${btn}</div>`;
        }).join('');
    }

    function render(items){
        if (!items || !items.length){
            $('#publish-tbody').html('<tr><td colspan="7">Sin productos.</td></tr>');
            return;
        }
        $('#publish-tbody').html(items.map(it => {
            let actions = '';
            if (canPublish) {
                actions += `<button class="button button-small publish-authorize" data-id="${it.id}">Autorizar</button> `;
                actions += `<button class="button button-small button-primary publish-product" data-id="${it.id}" ${approved(it) ? '' : 'disabled'}>Publicar</button>`;
            }
            return `<tr>
                <td>${it.id}</td>
                <td><code>${esc(it.canonical_sku || '-')}</code></td>
                <td>${esc(it.nombre_canonico || '-')}</td>
                <td>${parseInt(it.woocommerce_product_id || 0) ? it.woocommerce_product_id : '-'}</td>
                <td>${gateHtml(it)}</td>
                <td>${esc(it.publication_stage || '-')}</td>
                <td>${actions}</td>
            </tr>`;
        }).join(''));
    }

    function load(){
        $.post(ajaxurl, {
            action:'riverso_products_list',
            nonce,
            status:'active',
            search: $('#publish-search').val(),
            limit:100
        }, function(r){
            if (!r.success){ $('#publish-tbody').html('<tr><td colspan="7">Error cargando.</td></tr>'); return; }
            render(r.data.items);
        });
    }

    $('#publish-reload').on('click', load);
    $('#publish-search').on('keyup', function(e){ if (e.key === 'Enter') load(); });

    $(document).on('click', '.publish-gate', function(){
        $.post(ajaxurl, {action:'riverso_products_approve_gate', nonce, id:$(this).data('id'), gate:$(this).data('gate'), status:'approved'}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    });

    $(document).on('click', '.publish-authorize', function(){
        $.post(ajaxurl, {action:'riverso_publish_authorize', nonce, producto_base_id:$(this).data('id')}, function(r){
            if (!r.success){ alert(r.data.message || 'Faltan aprobaciones'); return; }
            load();
        });
    });

    $(document).on('click', '.publish-product', function(){
        if (!confirm('¿Publicar producto en WooCommerce?')) return;
        $.post(ajaxurl, {action:'riverso_publish_product', nonce, producto_base_id:$(this).data('id')}, function(r){
            if (!r.success){ alert(r.data.message || 'Error publicando'); return; }
            load();
        });
    });

    load();
});
</script>
