<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
$can_review = current_user_can('riverso_review_products') || $can_manage;
?>
<div class="wrap">
    <h1>Productos</h1>
    <p>Gestión del catálogo canónico interno. Las eliminaciones son lógicas y todo cambio queda auditado.</p>

    <div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
        <select id="products-status">
            <option value="active">Activos</option>
            <option value="archived">Archivados</option>
            <option value="deleted">Eliminados</option>
        </select>
        <input type="text" id="products-search" class="regular-text" placeholder="SKU o nombre">
        <button class="button" id="products-reload">Actualizar</button>
        <?php if ($can_manage): ?>
            <button class="button button-primary" id="products-new">Nuevo producto</button>
        <?php endif; ?>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID</th><th>SKU</th><th>Nombre</th><th>Estado</th><th>Gates</th><th>Pipeline</th><th>Woo</th><th>Acciones</th>
            </tr>
        </thead>
        <tbody id="products-tbody"><tr><td colspan="8">Cargando...</td></tr></tbody>
    </table>

    <?php if ($can_manage): ?>
    <div id="product-editor" style="display:none; margin-top:18px; background:#fff; border:1px solid #ccd0d4; padding:14px;">
        <h2 id="product-editor-title">Producto</h2>
        <input type="hidden" id="product-id">
        <table class="form-table">
            <tr><th>SKU canónico</th><td><input type="text" id="product-sku" class="regular-text"></td></tr>
            <tr><th>Nombre</th><td><input type="text" id="product-name" class="large-text"></td></tr>
            <tr><th>Unidad base</th><td><input type="text" id="product-unit" class="regular-text" value="unidad"></td></tr>
            <tr><th>Estado</th><td><input type="text" id="product-state" class="regular-text" value="activo"></td></tr>
            <tr><th>Código abierto</th><td><input type="text" id="product-open-code" class="regular-text"></td></tr>
            <tr><th>Flags</th><td>
                <label><input type="checkbox" id="product-decimal"> Permite decimal</label><br>
                <label><input type="checkbox" id="product-ean13" checked> Permite EAN13 personalizado</label><br>
                <label><input type="checkbox" id="product-open-stock"> Habilitar stock abierto</label><br>
                <label><input type="checkbox" id="product-review"> Requiere revisión humana</label>
            </td></tr>
        </table>
        <p>
            <button class="button button-primary" id="product-save">Guardar</button>
            <button class="button" id="product-cancel">Cancelar</button>
        </p>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canReview = <?php echo $can_review ? 'true' : 'false'; ?>;

    function esc(v){ return $('<div>').text(v === null || v === undefined ? '' : v).html(); }
    function gate(v){ return v === 'approved' ? 'Aprobado' : (v === 'rejected' ? 'Rechazado' : 'Pendiente'); }

    function gatesHtml(it){
        return [
            ['human_product_review', 'Producto'],
            ['human_price_review', 'Precio'],
            ['human_category_review', 'Categoría'],
            ['human_attribute_review', 'Atributos']
        ].map(g => {
            const val = it[g[0]] || 'pending';
            const btn = canReview && val !== 'approved'
                ? ` <button class="button button-small product-gate" data-id="${it.id}" data-gate="${g[0]}">Aprobar</button>`
                : '';
            return `<div><strong>${g[1]}:</strong> ${gate(val)}${btn}</div>`;
        }).join('');
    }

    function render(items){
        if (!items || !items.length){
            $('#products-tbody').html('<tr><td colspan="8">Sin productos.</td></tr>');
            return;
        }
        $('#products-tbody').html(items.map(it => {
            let actions = `<button class="button button-small product-edit" data-id="${it.id}">Editar</button> `;
            if (canManage) {
                if (it.deleted_at || it.archived_at) {
                    actions += `<button class="button button-small product-restore" data-id="${it.id}">Restaurar</button>`;
                } else {
                    actions += `<button class="button button-small product-archive" data-id="${it.id}">Archivar</button> `;
                    actions += `<button class="button button-small product-delete" data-id="${it.id}">Eliminar</button>`;
                }
            }
            return `<tr>
                <td>${it.id}</td>
                <td><code>${esc(it.canonical_sku || '-')}</code></td>
                <td>${esc(it.nombre_canonico || '-')}</td>
                <td>${esc(it.estado || '-')}</td>
                <td>${gatesHtml(it)}</td>
                <td>${esc(it.publication_stage || '-')}</td>
                <td>${parseInt(it.woocommerce_product_id || 0) ? it.woocommerce_product_id : '-'}</td>
                <td>${actions}</td>
            </tr>`;
        }).join(''));
    }

    function load(){
        $('#products-tbody').html('<tr><td colspan="8">Cargando...</td></tr>');
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            status: $('#products-status').val(),
            search: $('#products-search').val()
        }, function(r){
            if (!r.success){ $('#products-tbody').html('<tr><td colspan="8">Error cargando.</td></tr>'); return; }
            render(r.data.items);
        });
    }

    function resetEditor(){
        $('#product-id').val('');
        $('#product-sku').val('');
        $('#product-name').val('');
        $('#product-unit').val('unidad');
        $('#product-state').val('activo');
        $('#product-open-code').val('');
        $('#product-decimal').prop('checked', false);
        $('#product-ean13').prop('checked', true);
        $('#product-open-stock').prop('checked', false);
        $('#product-review').prop('checked', false);
    }

    $('#products-reload, #products-status').on('click change', load);
    $('#products-search').on('keyup', function(e){ if (e.key === 'Enter') load(); });

    $('#products-new').on('click', function(){
        resetEditor();
        $('#product-editor-title').text('Nuevo producto');
        $('#product-editor').show();
    });

    $(document).on('click', '.product-edit', function(){
        $.post(ajaxurl, {action:'riverso_products_get', nonce, id:$(this).data('id')}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            const it = r.data.item;
            $('#product-id').val(it.id);
            $('#product-sku').val(it.canonical_sku || '');
            $('#product-name').val(it.nombre_canonico || '');
            $('#product-unit').val(it.unidad_base || 'unidad');
            $('#product-state').val(it.estado || 'activo');
            $('#product-open-code').val(it.codigo_abierto || '');
            $('#product-decimal').prop('checked', parseInt(it.permite_decimal || 0) === 1);
            $('#product-ean13').prop('checked', parseInt(it.permite_ean13_personalizado || 0) === 1);
            $('#product-open-stock').prop('checked', parseInt(it.stock_abierto_habilitado || 0) === 1);
            $('#product-review').prop('checked', parseInt(it.requires_human_review || 0) === 1);
            $('#product-editor-title').text('Editar producto #' + it.id);
            $('#product-editor').show();
        });
    });

    $('#product-save').on('click', function(){
        $.post(ajaxurl, {
            action:'riverso_products_save',
            nonce,
            id: $('#product-id').val(),
            canonical_sku: $('#product-sku').val(),
            nombre_canonico: $('#product-name').val(),
            unidad_base: $('#product-unit').val(),
            estado: $('#product-state').val(),
            codigo_abierto: $('#product-open-code').val(),
            permite_decimal: $('#product-decimal').is(':checked') ? 1 : 0,
            permite_ean13_personalizado: $('#product-ean13').is(':checked') ? 1 : 0,
            stock_abierto_habilitado: $('#product-open-stock').is(':checked') ? 1 : 0,
            requires_human_review: $('#product-review').is(':checked') ? 1 : 0
        }, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            $('#product-editor').hide();
            load();
        });
    });

    $('#product-cancel').on('click', function(){ $('#product-editor').hide(); });

    function lifecycle(action, id, confirmText){
        if (confirmText && !confirm(confirmText)) return;
        $.post(ajaxurl, {action, nonce, id}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    }

    $(document).on('click', '.product-archive', function(){ lifecycle('riverso_products_archive', $(this).data('id'), '¿Archivar producto?'); });
    $(document).on('click', '.product-delete', function(){ lifecycle('riverso_products_soft_delete', $(this).data('id'), '¿Eliminar lógicamente producto?'); });
    $(document).on('click', '.product-restore', function(){ lifecycle('riverso_products_restore', $(this).data('id')); });
    $(document).on('click', '.product-gate', function(){
        $.post(ajaxurl, {action:'riverso_products_approve_gate', nonce, id:$(this).data('id'), gate:$(this).data('gate'), status:'approved'}, function(r){
            if (!r.success){ alert(r.data.message || 'Error'); return; }
            load();
        });
    });

    load();
});
</script>
