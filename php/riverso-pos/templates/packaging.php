<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
?>
<div class="wrap">
    <h1>Embolsado y producto abierto</h1>
    <p>Define envases cerrados, ábrelos para generar stock suelto y crea bolsas personalizadas con su EAN13 propio.</p>

    <div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
        <label>Producto base ID: <input type="number" id="pk-base-id" class="small-text"></label>
        <button class="button" id="pk-load">Cargar</button>
        <span id="pk-stock" style="margin-left:12px;"></span>
    </div>

    <h2>Envases</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>ID</th><th>SKU envase</th><th>Unidades/envase</th><th>Variación WC</th><th>Acciones</th></tr></thead>
        <tbody id="pk-envases-tbody"><tr><td colspan="5">Carga un producto base.</td></tr></tbody>
    </table>

    <h3>Nuevo envase</h3>
    <p>
        SKU envase <input type="text" id="pk-env-sku" class="regular-text">
        Unidades por envase <input type="number" step="0.01" id="pk-env-units" class="small-text">
        Variación WC <input type="number" id="pk-env-var" class="small-text">
        <button class="button button-primary" id="pk-env-create">Crear envase</button>
    </p>

    <hr>
    <h2>Generar bolsa desde stock abierto</h2>
    <p>
        Cantidad <input type="number" step="0.01" id="pk-bolsa-qty" class="small-text">
        <button class="button button-primary" id="pk-bolsa-create">Generar bolsa</button>
        <span id="pk-bolsa-result"></span>
    </p>

    <h2>Bolsas generadas</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>ID</th><th>SKU bolsa</th><th>Cantidad</th><th>EAN13</th><th>Costo</th><th>Estado</th><th>Acción</th></tr></thead>
        <tbody id="pk-bolsas-tbody"><tr><td colspan="6">-</td></tr></tbody>
    </table>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';

    function baseId(){ return parseInt($('#pk-base-id').val()) || 0; }

    function loadStock(){
        if (!baseId()) return;
        $.post(ajaxurl, {action:'riverso_packaging_stock', nonce, producto_base_id:baseId()}, function(r){
            if (r.success) $('#pk-stock').html('Stock abierto: <strong>' + Number(r.data.stock_abierto).toLocaleString('es-CL') + '</strong>');
        });
    }

    function loadEnvases(){
        if (!baseId()) return;
        $.post(ajaxurl, {action:'riverso_packaging_envases', nonce, producto_base_id:baseId()}, function(r){
            if (!r.success){ return; }
            const rows = (r.data.envases||[]).map(e => `
                <tr>
                    <td>${e.id}</td>
                    <td>${e.sku_envase || '-'}</td>
                    <td>${e.cantidad_unidades}</td>
                    <td>${e.woocommerce_variation_id || '-'}</td>
                    <td>
                        <input type="number" step="0.01" value="1" class="small-text pk-open-qty" data-id="${e.id}">
                        <button class="button button-small pk-open" data-id="${e.id}">Abrir</button>
                    </td>
                </tr>`).join('');
            $('#pk-envases-tbody').html(rows || '<tr><td colspan="5">Sin envases.</td></tr>');
        });
    }

    function loadBolsas(){
        if (!baseId()) return;
        $.post(ajaxurl, {action:'riverso_packaging_bolsas', nonce, producto_base_id:baseId()}, function(r){
            if (!r.success){ return; }
            const rows = (r.data.bolsas||[]).map(b => `
                <tr>
                    <td>${b.id}</td><td>${b.sku_bolsa || '-'}</td><td>${b.cantidad}</td>
                    <td>${b.ean13 || '-'}</td><td>${b.costo_unitario || '-'}</td><td>${b.estado}</td>
                    <td style="text-align:center;">
                        <button type="button" class="button button-small btn-print-bolsa" 
                            data-sku="${b.sku_bolsa}" data-nombre="Bolsa ${b.sku_bolsa}" 
                            data-ean13="${b.ean13}" data-cantidad="${b.cantidad}">
                            🖨️ Imprimir
                        </button>
                    </td>
                </tr>`).join('');
            $('#pk-bolsas-tbody').html(rows || '<tr><td colspan="7">Sin bolsas.</td></tr>');
        });
    }

    function reloadAll(){ loadStock(); loadEnvases(); loadBolsas(); }

    $('#pk-load').on('click', reloadAll);

    $('#pk-env-create').on('click', function(){
        if (!baseId()){ alert('Indica el producto base'); return; }
        $.post(ajaxurl, {action:'riverso_packaging_create_envase', nonce,
            producto_base_id:baseId(),
            sku_envase:$('#pk-env-sku').val(),
            cantidad_unidades:$('#pk-env-units').val(),
            woocommerce_variation_id:$('#pk-env-var').val()
        }, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            loadEnvases();
        });
    });

    $(document).on('click', '.pk-open', function(){
        const id = $(this).data('id');
        const qty = $(`.pk-open-qty[data-id="${id}"]`).val();
        $.post(ajaxurl, {action:'riverso_packaging_open_envase', nonce, envase_id:id, cantidad_envases:qty}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            alert('Abierto. Unidades sueltas: ' + r.data.unidades_abiertas);
            reloadAll();
        });
    });

    $('#pk-bolsa-create').on('click', function(){
        if (!baseId()){ alert('Indica el producto base'); return; }
        $.post(ajaxurl, {action:'riverso_packaging_create_bolsa', nonce, producto_base_id:baseId(), cantidad:$('#pk-bolsa-qty').val()}, function(r){
            if (!r.success){ alert(r.data.message||'Error'); return; }
            $('#pk-bolsa-result').html(' EAN13: <strong>' + (r.data.ean13 || 'sin EAN') + '</strong>');
            reloadAll();
        });
    });

    // Manejador de botones de impresión de bolsas
    $(document).on('click', '.btn-print-bolsa', function(){
        const sku = $(this).data('sku');
        const nombre = $(this).data('nombre');
        const ean13 = $(this).data('ean13');
        const cantidad = parseInt($(this).data('cantidad')) || 100;

        if (typeof RiversoLabelPrint !== 'undefined') {
            RiversoLabelPrint.showPrintDialog({
                sku,
                nombre,
                precio: null,
                cantidad,
                copias: 1,
                modo: 'BolsaCOD',
                color: 'BN',
                ean13
            });
        } else {
            alert('⚠️ El módulo de impresión no está cargado. Recarga la página.');
        }
    });
});
</script>
