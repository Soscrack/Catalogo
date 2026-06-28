<?php
if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
$module = class_exists('Riverso_Tienda_Local_Module') ? Riverso_Tienda_Local_Module::get_instance() : null;
$stats = $module ? $module->get_stats() : ['productos' => 0, 'barcodes' => 0, 'productos_con_barcode' => 0];
?>

<div class="wrap riverso-tienda-local">
    <h1>Tienda Local</h1>
    <p>Busca productos del sistema local por código de barra, SKU o nombre. Los datos provienen de los CSV en <code>CodigosBarra/</code>.</p>

    <div class="riverso-local-stats" style="display:flex;gap:12px;margin:16px 0;">
        <div class="postbox" style="padding:12px 16px;min-width:150px;">
            <strong id="local-stat-productos"><?php echo esc_html(number_format_i18n($stats['productos'])); ?></strong><br>
            <span>Productos</span>
        </div>
        <div class="postbox" style="padding:12px 16px;min-width:150px;">
            <strong id="local-stat-barcodes"><?php echo esc_html(number_format_i18n($stats['barcodes'])); ?></strong><br>
            <span>Códigos</span>
        </div>
        <div class="postbox" style="padding:12px 16px;min-width:150px;">
            <strong id="local-stat-linked"><?php echo esc_html(number_format_i18n($stats['productos_con_barcode'])); ?></strong><br>
            <span>Con código</span>
        </div>
    </div>

    <div class="postbox" style="padding:18px;margin-top:12px;">
        <h2 style="margin-top:0;">Buscador rápido</h2>
        <form id="tienda-local-search-form" style="display:flex;gap:8px;align-items:center;max-width:760px;">
            <input type="text" id="tienda-local-query" class="regular-text" style="font-size:18px;line-height:2;flex:1;" placeholder="Escanea o escribe código de barra, SKU o nombre..." autofocus>
            <button type="submit" class="button button-primary">Buscar</button>
        </form>
        <p class="description">El lector de códigos puede enviar Enter automáticamente. La búsqueda tolera ceros a la izquierda.</p>
        <div id="tienda-local-result" style="margin-top:16px;"></div>
    </div>

    <?php if ($can_manage) : ?>
        <div class="postbox" style="padding:18px;margin-top:16px;">
            <h2 style="margin-top:0;">Importar / actualizar CSV</h2>
            <p>Si no adjuntas archivos, se usan las rutas por defecto del repositorio: <code>CodigosBarra/productos_2026-04-01.csv</code> y <code>CodigosBarra/codigos_barras_2026-04-01.csv</code>.</p>
            <form id="tienda-local-import-form" enctype="multipart/form-data">
                <p>
                    <label>Productos CSV:
                        <input type="file" name="productos_csv" accept=".csv,text/csv">
                    </label>
                </p>
                <p>
                    <label>Códigos CSV:
                        <input type="file" name="barcodes_csv" accept=".csv,text/csv">
                    </label>
                </p>
                <button type="submit" class="button">Importar / actualizar</button>
                <span id="tienda-local-import-status" style="margin-left:8px;"></span>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';

    function esc(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }

    function updateStats(stats) {
        if (!stats) {
            return;
        }
        $('#local-stat-productos').text(Number(stats.productos || 0).toLocaleString('es-CL'));
        $('#local-stat-barcodes').text(Number(stats.barcodes || 0).toLocaleString('es-CL'));
        $('#local-stat-linked').text(Number(stats.productos_con_barcode || 0).toLocaleString('es-CL'));
    }

    function stockLabel(stock) {
        const value = Number(stock || 0);
        const color = value < 0 ? '#b32d2e' : (value === 0 ? '#996800' : '#008a20');
        return `<strong style="color:${color};">${value.toLocaleString('es-CL')}</strong>`;
    }

    function renderProduct(product) {
        const matched = product.matched_barcode ? `<p><strong>Código leído:</strong> <code>${esc(product.matched_barcode)}</code></p>` : '';
        const barcodes = (product.barcodes || []).map(row => {
            return `<li><code>${esc(row.barcode)}</code>${row.fecha ? ` <span class="description">(${esc(row.fecha)})</span>` : ''}</li>`;
        }).join('');

        const printBtn = typeof RiversoLabelPrint !== 'undefined' ? `
            <button type="button" class="button button-small print-label-btn" 
                data-sku="${esc(product.sku)}" 
                data-nombre="${esc(product.nombre)}"
                data-precio="${product.precio || 0}"
                style="margin-top:8px;">
                🖨️ Imprimir etiqueta
            </button>
        ` : '';

        return `<div class="postbox" style="padding:14px;margin:12px 0;">
            <h3 style="margin-top:0;">${esc(product.nombre)}</h3>
            ${matched}
            <p>
                <strong>SKU:</strong> <code>${esc(product.sku)}</code><br>
                <strong>Precio:</strong> ${esc(product.precio_formateado)}<br>
                <strong>Stock:</strong> ${stockLabel(product.stock)}
            </p>
            <details ${product.barcodes && product.barcodes.length <= 6 ? 'open' : ''}>
                <summary>Códigos asociados (${(product.barcodes || []).length})</summary>
                <ul>${barcodes || '<li>Sin códigos asociados.</li>'}</ul>
            </details>
            ${printBtn}
        </div>`;
    }

    $('#tienda-local-search-form').on('submit', function(e){
        e.preventDefault();
        const query = $('#tienda-local-query').val().trim();
        if (!query) {
            $('#tienda-local-result').html('<div class="notice notice-warning inline"><p>Ingresa un código, SKU o nombre.</p></div>');
            return;
        }

        $('#tienda-local-result').html('<p>Buscando...</p>');
        $.post(ajaxurl, {
            action: 'riverso_tienda_local_search',
            nonce: nonce,
            query: query
        }).done(function(resp){
            if (!resp || !resp.success) {
                const message = resp && resp.data && resp.data.message ? resp.data.message : 'No encontrado';
                updateStats(resp && resp.data ? resp.data.stats : null);
                $('#tienda-local-result').html(`<div class="notice notice-error inline"><p>${esc(message)}</p></div>`);
                return;
            }

            updateStats(resp.data.stats);
            const items = (resp.data.items || []).filter(Boolean);
            const label = resp.data.type === 'name' && items.length > 1
                ? `<p class="description">Se encontraron ${items.length} coincidencias por nombre.</p>`
                : '';
            $('#tienda-local-result').html(label + items.map(renderProduct).join(''));
        }).fail(function(){
            $('#tienda-local-result').html('<div class="notice notice-error inline"><p>Error buscando producto local.</p></div>');
        });
    });

    $('#tienda-local-import-form').on('submit', function(e){
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('action', 'riverso_tienda_local_import');
        formData.append('nonce', nonce);

        $('#tienda-local-import-status').text('Importando...');
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function(resp){
            if (!resp || !resp.success) {
                const message = resp && resp.data && resp.data.message ? resp.data.message : 'Error importando';
                $('#tienda-local-import-status').text(message);
                return;
            }
            updateStats(resp.data.stats);
            $('#tienda-local-import-status').text(`Listo: ${resp.data.productos.imported} productos, ${resp.data.barcodes.imported} códigos.`);
        }).fail(function(){
            $('#tienda-local-import-status').text('Error importando.');
        });
    });

    // Manejador de botones de impresión
    $(document).on('click', '.print-label-btn', function(e) {
        e.preventDefault();
        if (typeof RiversoLabelPrint === 'undefined') {
            alert('Cliente de impresión no cargado');
            return;
        }

        const sku = $(this).data('sku');
        const nombre = $(this).data('nombre');
        const precio = $(this).data('precio');

        const defaultJob = {
            sku: sku,
            nombre: nombre,
            precio: precio ? parseInt(precio) : null,
            cantidad: 100,
            copias: 1,
            modo: 'BolsaCOD',
            color: 'BN'
        };

        RiversoLabelPrint.showPrintDialog(defaultJob);
    });
});
</script>
