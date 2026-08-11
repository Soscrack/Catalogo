<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
$can_review = current_user_can('riverso_review_products') || $can_manage;
?>
<div class="wrap">
    <h1>Hub de Productos</h1>
    <p>Gestión centralizada: crear Local, vincular Online, asignar códigos proveedor, agregar barcodes y monitorear completitud.</p>

    <div style="display:flex; gap:8px; align-items:center; margin:12px 0; flex-wrap:wrap;">
        <select id="products-status">
            <option value="active">Activos</option>
            <option value="archived">Archivados</option>
            <option value="deleted">Eliminados</option>
        </select>
        <select id="products-completeness">
            <option value="todos">Completitud: Todos</option>
            <option value="completo">Completitud: Completo</option>
            <option value="publicado">Completitud: Publicado</option>
            <option value="falta_online">Completitud: Falta Online</option>
            <option value="falta_codigo">Completitud: Falta Código</option>
            <option value="solo_online">Completitud: Solo Online</option>
            <option value="solo_online_publicado">Completitud: Solo Online Publicado</option>
            <option value="incompleto">Completitud: Incompleto</option>
        </select>
        <input type="text" id="products-search" class="regular-text" placeholder="SKU, nombre, código proveedor o barcode">
        <button class="button" id="products-reload">Actualizar</button>
        <?php if ($can_manage): ?>
            <button class="button button-primary" id="products-new">Nuevo producto local</button>
        <?php endif; ?>
    </div>

    <div style="margin:12px 0; display:flex; justify-content:space-between; align-items:center;">
        <span id="products-counter" style="color:#666; font-size:13px;">Mostrando 0 de 0</span>
        <span class="dashicons dashicons-editor-help" id="completeness-help" style="cursor:pointer; color:#2271b1; font-size:20px;" title="Ver ayuda de completitud"></span>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:8%">ID</th>
                <th style="width:12%">SKU</th>
                <th style="width:20%">Nombre</th>
                <th style="width:15%">
                    Completitud 
                    <span class="dashicons dashicons-editor-help" id="completeness-col-help" style="cursor:pointer; color:#2271b1;" title="Ver ayuda"></span>
                </th>
                <th style="width:12%">Códigos</th>
                <th style="width:10%">Woo</th>
                <th style="width:13%">Acciones</th>
            </tr>
        </thead>
        <tbody id="products-tbody"><tr><td colspan="7">Cargando...</td></tr></tbody>
    </table>

    <div style="margin-top:12px; display:flex; gap:8px; justify-content:center;">
        <button class="button" id="products-prev" style="display:none;">← Anterior</button>
        <span id="products-page-info" style="align-self:center; color:#666;"></span>
        <button class="button" id="products-next" style="display:none;">Siguiente →</button>
    </div>

    <?php if ($can_manage): ?>
    <div id="product-editor" style="display:none; margin-top:18px; background:#fff; border:1px solid #ccd0d4; padding:14px;">
        <h2 id="product-editor-title">Producto</h2>
        <input type="hidden" id="product-id">
        <table class="form-table">
            <tr><th>SKU canónico</th><td><input type="text" id="product-sku" class="regular-text"></td></tr>
            <tr><th>Nombre</th><td><input type="text" id="product-name" class="large-text"></td></tr>
            <tr><th>Unidad base</th><td><input type="text" id="product-unit" class="regular-text" value="unidad"></td></tr>
            <tr><th>Flags</th><td>
                <label><input type="checkbox" id="product-decimal"> Permite decimal</label><br>
                <label><input type="checkbox" id="product-ean13" checked> Permite EAN13 personalizado</label><br>
                <label><input type="checkbox" id="product-open-stock"> Habilitar stock abierto</label>
            </td></tr>
        </table>
        <p>
            <button class="button button-primary" id="product-save">Guardar producto</button>
            <button class="button" id="product-cancel">Cancelar</button>
        </p>
    </div>

    <div id="product-detail-panel" style="display:none; margin-top:18px; background:#fff; border:1px solid #ccd0d4; padding:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 id="detail-title" style="margin:0;">Detalle del producto</h2>
            <button class="button" id="detail-close">Cerrar</button>
        </div>

        <div style="border-bottom:1px solid #ddd; margin-bottom:12px;">
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                <a href="#" class="detail-tab active" data-tab="local" title="Datos del producto local">Local <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Información del producto local (SKU, nombre, unidad)"></span></a>
                <a href="#" class="detail-tab" data-tab="online" title="Crear o vincular contraparte WooCommerce">Online <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Vincular o crear producto en WooCommerce"></span></a>
                <a href="#" class="detail-tab" data-tab="suppliers" title="Asignar códigos de proveedores">Códigos <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Códigos de proveedores para comprar este producto"></span></a>
                <a href="#" class="detail-tab" data-tab="barcodes" title="Gestionar códigos EAN13">Barcodes <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Códigos de barra del producto"></span></a>
                <a href="#" class="detail-tab" data-tab="tasks" title="Tareas pendientes">Tasks <span class="dashicons dashicons-editor-help" style="font-size:14px; margin-left:2px; cursor:pointer;" title="Tareas automáticas para completar el producto"></span></a>
            </div>
        </div>

        <!-- TAB: LOCAL -->
        <div class="detail-tab-content" id="tab-local">
            <table class="form-table">
                <tr><th>SKU</th><td><code id="local-sku">-</code></td></tr>
                <tr><th>Nombre</th><td id="local-name">-</td></tr>
                <tr><th>Unidad base</th><td id="local-unit">-</td></tr>
                <tr><th>Origen</th><td id="local-origen">-</td></tr>
                <tr><th>Estado</th><td id="local-estado">-</td></tr>
            </table>
        </div>

        <!-- TAB: ONLINE -->
        <div class="detail-tab-content" id="tab-online" style="display:none;">
            <div id="online-missing-code-banner" style="display:none; background:#fff3cd; border:1px solid #ffc107; border-radius:3px; padding:12px; margin-bottom:12px;">
                <strong>⚠️ Falta código proveedor</strong>
                <p style="margin:6px 0 0 0; font-size:13px;">Este producto tiene contraparte WooCommerce pero no tiene código proveedor asignado.</p>
                <button class="button button-primary" id="online-assign-code-btn" style="margin-top:8px;">Asignar código ahora</button>
            </div>

            <p>Vincular o crear contraparte WooCommerce.</p>
            <div style="margin:12px 0;">
                <h4>Buscar y vincular producto existente</h4>
                <input type="text" id="woo-search" class="regular-text" placeholder="Buscar producto WooCommerce">
                <div id="woo-results" style="display:none; border:1px solid #ddd; max-height:180px; overflow:auto; margin-top:6px;"></div>
                <input type="hidden" id="woo-selected-id">
                <div id="woo-selected-display" style="margin-top:6px; color:#2271b1;"></div>
            </div>
            <table class="form-table">
                <tr><th>Woo ID</th><td id="online-woo-id">-</td></tr>
                <tr><th>Estado match</th><td id="online-match-estado">-</td></tr>
            </table>
            <p>
                <button class="button button-primary" id="online-link-btn" style="display:none;">Vincular producto WooCommerce</button>
            </p>
            <hr style="margin:16px 0;">
            <p>
                <button class="button button-primary" id="online-create-btn" style="display:none;">Crear nuevo producto WooCommerce</button>
            </p>
        </div>

        <!-- TAB: CÓDIGOS PROVEEDOR -->
        <div class="detail-tab-content" id="tab-suppliers" style="display:none;">
            <p>Buscar y asignar códigos proveedor.</p>
            <div style="margin:12px 0;">
                <input type="text" id="supplier-code-search" class="regular-text" placeholder="Código proveedor (p.ej. 123456)">
                <div id="supplier-search-results" style="display:none; border:1px solid #ddd; max-height:180px; overflow:auto; margin-top:6px;"></div>
                <input type="hidden" id="supplier-id-select">
                <input type="hidden" id="supplier-code-select">
            </div>
            <div style="margin:12px 0;">
                <label>Motivo auditoría:</label>
                <textarea id="supplier-audit-reason" class="large-text" rows="2" placeholder="Describe por qué asignas este código..."></textarea>
            </div>
            <p>
                <button class="button button-primary" id="supplier-link-btn">Asignar código proveedor</button>
            </p>
            <div id="suppliers-list" style="margin-top:12px;"></div>
        </div>

        <!-- TAB: BARCODES -->
        <div class="detail-tab-content" id="tab-barcodes" style="display:none;">
            <p>Agregar códigos de barra EAN13 y gestionar legacy.</p>
            <div style="margin:12px 0;">
                <input type="text" id="barcode-new" class="regular-text" placeholder="Código EAN13">
                <textarea id="barcode-audit-reason" class="large-text" rows="2" placeholder="Motivo auditoría (opcional)"></textarea>
                <button class="button button-primary" id="barcode-add-btn">Agregar código de barra</button>
            </div>
            <div id="barcodes-list" style="margin-top:12px;"></div>
        </div>

        <!-- TAB: TASKS -->
        <div class="detail-tab-content" id="tab-tasks" style="display:none;">
            <div id="tasks-list" style="margin-top:12px;"></div>
            <p id="tasks-empty" style="color:#666;">Sin tareas activas.</p>
        </div>
    </div>
    <!-- HELP PANEL: COMPLETITUD -->
    <div id="help-completeness" style="display:none; margin-top:18px; background:#f9f9f9; border-left:4px solid #2271b1; padding:12px; border-radius:2px;">
        <h3 style="margin-top:0;">Guía de Completitud</h3>
        <dl style="margin:8px 0; font-size:13px;">
            <dt><strong style="background:#28a745; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Producto Completo</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local (SKU + nombre) + contraparte WooCommerce (publicado o no) + al menos un código proveedor.</dd>

            <dt><strong style="background:#007bff; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Producto Publicado</strong></dt>
            <dd style="margin:4px 0 8px 0;">Completo y además está publicado en WooCommerce (aprobado y visible).</dd>

            <dt><strong style="background:#ffc107; color:#333; padding:2px 6px; border-radius:2px; display:inline-block;">Falta Online</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local completo pero sin vínculo a WooCommerce.</dd>

            <dt><strong style="background:#fd7e14; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Falta Código</strong></dt>
            <dd style="margin:4px 0 8px 0;">Producto local + online pero sin código proveedor asignado.</dd>

            <dt><strong style="background:#6f42c1; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Solo Online</strong></dt>
            <dd style="margin:4px 0 8px 0;">Existe en WooCommerce pero no tiene datos locales (SKU/nombre) completos.</dd>

            <dt><strong style="background:#dc3545; color:white; padding:2px 6px; border-radius:2px; display:inline-block;">Incompleto</strong></dt>
            <dd style="margin:4px 0 8px 0;">No tiene SKU o nombre local definidos.</dd>
        </dl>
        <button class="button" id="help-close" style="margin-top:8px;">Cerrar</button>
    </div>

    <!-- MODAL: CREAR PRODUCTO ONLINE -->
    <div id="create-online-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; display:flex; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:4px; padding:20px; max-width:500px; width:90%;">
            <h2 style="margin-top:0;">Crear producto WooCommerce</h2>
            <p style="color:#666;">Selecciona el tipo de producto que deseas crear.</p>

            <div style="background:#f0f8ff; border:1px solid #2271b1; border-radius:3px; padding:10px; margin:12px 0; font-size:12px; color:#333;">
                <strong>Nota:</strong> El producto se creará en estado borrador. Requiere revisión humana y aprobación antes de ser publicado en el sitio.
            </div>

            <table class="form-table">
                <tr>
                    <th>
                        Tipo de producto
                        <span class="dashicons dashicons-editor-help" style="cursor:pointer; color:#2271b1; font-size:16px;" title="Simple: sin variantes. Variable: con variantes de Nominal y/o Largo"></span>
                    </th>
                    <td>
                        <label><input type="radio" name="create-type" value="simple" checked> Producto Simple (sin variantes)</label><br>
                        <label><input type="radio" name="create-type" value="variable"> Producto Variable (con Nominal x Largo)</label>
                    </td>
                </tr>
                <tr><th>Nombre</th><td><input type="text" id="create-name" class="large-text"></td></tr>
                <tr><th>SKU</th><td><input type="text" id="create-sku" class="regular-text"></td></tr>
                <tr id="create-variable-attrs" style="display:none;">
                    <th colspan="2">
                        <h4 style="margin:0 0 8px 0;">Atributos de variación (opcional)</h4>
                        <label>Nominal: <input type="text" id="create-nominal" class="regular-text" placeholder="p.ej. 10mm"></label><br>
                        <label>Largo: <input type="text" id="create-largo" class="regular-text" placeholder="p.ej. 100cm"></label>
                    </th>
                </tr>
            </table>

            <p>
                <button class="button button-primary" id="create-online-submit">Crear producto</button>
                <button class="button" id="create-online-cancel">Cancelar</button>
            </p>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.detail-tab {
    padding: 8px 12px;
    border-bottom: 2px solid transparent;
    text-decoration: none;
    color: #2271b1;
    cursor: pointer;
    display: inline-block;
    font-size: 14px;
}
.detail-tab.active {
    border-bottom-color: #2271b1;
    font-weight: bold;
}
.detail-tab-content {
    animation: fadeIn 0.2s;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
#create-online-modal {
    display: none !important;
}
#create-online-modal.show {
    display: flex !important;
}
#help-completeness {
    display: none !important;
}
#help-completeness.show {
    display: block !important;
}
.help-icon {
    cursor: pointer;
    color: #2271b1;
    font-size: 16px;
    display: inline-block;
}
.help-icon:hover {
    color: #135e96;
}
.completeness-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}
.completeness-badge.completo {
    background: #28a745;
    color: white;
}
.completeness-badge.publicado {
    background: #007bff;
    color: white;
}
.completeness-badge.falta_online {
    background: #ffc107;
    color: #333;
}
.completeness-badge.falta_codigo {
    background: #fd7e14;
    color: white;
}
.completeness-badge.solo_online {
    background: #6f42c1;
    color: white;
}
.completeness-badge.solo_online_publicado {
    background: #17a2b8;
    color: white;
}
.completeness-badge.incompleto {
    background: #dc3545;
    color: white;
}
.supplier-code-item {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.barcode-item {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 6px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.task-item {
    padding: 8px;
    border-left: 4px solid #2271b1;
    margin-bottom: 8px;
    background: #f9f9f9;
}
.woo-result-item, .supplier-result-item {
    padding: 8px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
}
.woo-result-item:hover, .supplier-result-item:hover {
    background: #f5f5f5;
}
</style>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
    const canReview = <?php echo $can_review ? 'true' : 'false'; ?>;

    let currentProduct = null;
    let searchTimeout = null;
    let currentOffset = 0;
    let currentLimit = 50;
    let currentTotal = 0;
    let currentPages = 0;

    function esc(v){ return $('<div>').text(v === null || v === undefined ? '' : v).html(); }

    function completenessLabel(cat) {
        const labels = {
            'completo': 'Producto Completo',
            'publicado': 'Producto Publicado',
            'falta_online': 'Falta Online',
            'falta_codigo': 'Falta Código',
            'solo_online': 'Solo Online',
            'solo_online_publicado': 'Solo Online Publicado',
            'incompleto': 'Incompleto'
        };
        return labels[cat] || cat;
    }

    function render(items){
        if (!items || !items.length){
            $('#products-tbody').html('<tr><td colspan="7">Sin productos.</td></tr>');
            updatePagination();
            return;
        }
        $('#products-tbody').html(items.map(it => {
            let actions = `<button class="button button-small product-view" data-id="${it.id}">Ver</button>`;
            if (canManage) {
                actions += ` <button class="button button-small product-edit" data-id="${it.id}">Editar</button>`;
                if (!it.archived_at && !it.deleted_at) {
                    actions += ` <button class="button button-small product-archive" data-id="${it.id}">Archivar</button>`;
                } else if (it.archived_at) {
                    actions += ` <button class="button button-small product-restore" data-id="${it.id}">Restaurar</button>`;
                }
            }
            const cat = it.completeness_category || 'incompleto';
            const wooId = parseInt(it.woocommerce_product_id || 0) ? it.woocommerce_product_id : '-';
            let codigos = it.codigos_proveedor ? it.codigos_proveedor.split(',').slice(0,2).join(',') : '-';
            
            // Agregar badge "Falta código" clicable si corresponde
            const hasOnline = !!it.woocommerce_product_id;
            const hasCode = parseInt(it.proveedores_count || 0) > 0;
            if (hasOnline && !hasCode && cat === 'falta_codigo') {
                codigos = `<span class="completeness-badge falta_codigo" style="cursor:pointer; padding:4px 8px; display:inline-block;" data-product-id="${it.id}" title="Ir a Códigos">Falta código</span>`;
            }

            return `<tr>
                <td>${it.id}</td>
                <td><code>${esc(it.canonical_sku || '-')}</code></td>
                <td>${esc(it.nombre_canonico || '-')}</td>
                <td><span class="completeness-badge ${cat}">${completenessLabel(cat)}</span></td>
                <td>${codigos}</td>
                <td>${wooId}</td>
                <td>${actions}</td>
            </tr>`;
        }).join(''));
        updatePagination();
    }

    function updatePagination() {
        const showing = Math.min(currentLimit, currentTotal - currentOffset);
        const startItem = currentTotal === 0 ? 0 : currentOffset + 1;
        const endItem = currentOffset + showing;
        $('#products-counter').text(`Mostrando ${startItem} a ${endItem} de ${currentTotal}`);

        const currentPage = Math.floor(currentOffset / currentLimit) + 1;
        $('#products-page-info').text(`Página ${currentPage} de ${currentPages || 1}`);

        if (currentOffset > 0) {
            $('#products-prev').show();
        } else {
            $('#products-prev').hide();
        }

        if (currentOffset + currentLimit < currentTotal) {
            $('#products-next').show();
        } else {
            $('#products-next').hide();
        }
    }

    function load(){
        $('#products-tbody').html('<tr><td colspan="7">Cargando...</td></tr>');
        $.post(ajaxurl, {
            action: 'riverso_products_list',
            nonce,
            status: $('#products-status').val(),
            search: $('#products-search').val(),
            completeness: $('#products-completeness').val(),
            offset: currentOffset,
            limit: currentLimit
        }, function(r){
            if (!r.success){ 
                $('#products-tbody').html('<tr><td colspan="7">Error cargando.</td></tr>'); 
                return; 
            }
            currentTotal = r.data.total || 0;
            currentPages = r.data.pages || 0;
            render(r.data.items || []);
        });
    }

    function loadPage(offset) {
        currentOffset = Math.max(0, offset);
        load();
    }

    function resetEditor(){
        $('#product-id').val('');
        $('#product-sku').val('');
        $('#product-name').val('');
        $('#product-unit').val('unidad');
        $('#product-decimal').prop('checked', false);
        $('#product-ean13').prop('checked', true);
        $('#product-open-stock').prop('checked', false);
    }

    function showDetail(product) {
        currentProduct = product;
        $('#detail-title').text(`Producto: ${product.nombre_canonico} (SKU ${product.canonical_sku})`);
        
        // Local tab
        $('#local-sku').text(product.canonical_sku);
        $('#local-name').text(product.nombre_canonico);
        $('#local-unit').text(product.unidad_base);
        $('#local-origen').text(product.origen_datos || 'manual');
        $('#local-estado').text(product.estado);

        // Online tab
        $('#online-woo-id').text(product.woocommerce_product_id || '-');
        $('#online-match-estado').text(product.match_estado_online || 'UNMATCHED');
        $('#woo-selected-id').val('');
        $('#woo-selected-display').text('');
        
        // Mostrar/ocultar banner de falta código
        const hasOnline = !!product.woocommerce_product_id;
        const hasCode = parseInt(product.proveedores_count || 0) > 0;
        if (hasOnline && !hasCode) {
            $('#online-missing-code-banner').show();
        } else {
            $('#online-missing-code-banner').hide();
        }
        
        if (!product.woocommerce_product_id) {
            $('#online-link-btn').show();
            $('#online-create-btn').show();
        } else {
            $('#online-link-btn').hide();
            $('#online-create-btn').hide();
        }

        // Suppliers tab
        $('#supplier-id-select').val('');
        $('#supplier-code-select').val('');
        $('#supplier-audit-reason').val('');
        renderSuppliers(product.proveedores || []);
        updateSupplierLinkBtnState();

        // Barcodes tab
        renderBarcodes(product.barcodes || []);

        // Tasks tab
        renderTasks(product.tasks || []);

        $('#product-detail-panel').show();
        $('html, body').animate({scrollTop: $('#product-detail-panel').offset().top - 40}, 300);
    }

    function updateSupplierLinkBtnState() {
        const code = $('#supplier-code-select').val();
        const supplierId = $('#supplier-id-select').val();
        
        if (code && supplierId) {
            $('#supplier-link-btn').prop('disabled', false).css('opacity', '1');
        } else {
            $('#supplier-link-btn').prop('disabled', true).css('opacity', '0.5');
        }
    }

    function renderSuppliers(suppliers) {
        let html = '';
        if (suppliers.length > 0) {
            html += '<h4 style="margin-top:0;">Códigos asignados:</h4>';
            suppliers.forEach(s => {
                html += `<div class="supplier-code-item">
                    <div>
                        <strong>${esc(s.codigo_proveedor)}</strong> 
                        (${esc(s.proveedor_nombre || 'Proveedor')})<br>
                        <small>${esc(s.nombre_proveedor || '')}</small>
                    </div>
                </div>`;
            });
        }
        $('#suppliers-list').html(html || '<p style="color:#666;">Sin códigos asignados.</p>');
    }

    function renderBarcodes(barcodes) {
        let html = '';
        if (barcodes && barcodes.length > 0) {
            html += '<h4 style="margin-top:0;">Códigos activos:</h4>';
            barcodes.forEach(b => {
                html += `<div class="barcode-item">
                    <code>${esc(b.codigo)}</code> 
                    <button class="button button-small barcode-remove" data-barcode="${esc(b.codigo)}">Desactivar</button>
                </div>`;
            });
        }
        $('#barcodes-list').html(html || '<p style="color:#666;">Sin códigos de barra.</p>');
    }

    function renderTasks(tasks) {
        let html = '';
        if (tasks && tasks.length > 0) {
            html = tasks.map(t => {
                let goToBtn = '';
                // Agregar botón "Ir" para navegación
                if (t.tipo === 'crear_contraparte_online') {
                    goToBtn = '<button class="button button-small task-goto" data-tab="online">Ir a Online</button>';
                } else if (t.tipo === 'relacionar_producto_proveedor') {
                    goToBtn = '<button class="button button-small task-goto" data-tab="suppliers">Ir a Códigos</button>';
                }
                return `
                <div class="task-item">
                    <strong>${esc(t.titulo)}</strong>
                    <br><small>${esc(t.tipo)} | ${esc(t.estado)} | Prioridad: ${esc(t.prioridad)}</small>
                    ${goToBtn ? '<div style="margin-top:6px;">' + goToBtn + '</div>' : ''}
                </div>
            `}).join('');
            $('#tasks-empty').hide();
        } else {
            $('#tasks-empty').show();
        }
        $('#tasks-list').html(html);
    }

    // Evento: cambiar tab en detalle
    $(document).on('click', '.detail-tab', function(e){
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.detail-tab').removeClass('active');
        $(this).addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-' + tab).show();
    });

    // Evento: mostrar ayuda de completitud
    $('#completeness-help, #completeness-col-help').on('click', function(e){
        e.preventDefault();
        $('#help-completeness').toggleClass('show');
    });

    $('#help-close').on('click', function(){
        $('#help-completeness').removeClass('show');
    });

    let wooSearchTimeout = null;

    // Evento: buscar Woo con debounce
    $(document).on('keyup', '#woo-search', function(){
        const q = $(this).val();
        if (q.length < 2) {
            $('#woo-results').hide();
            clearTimeout(wooSearchTimeout);
            return;
        }
        clearTimeout(wooSearchTimeout);
        wooSearchTimeout = setTimeout(function(){
            $.post(ajaxurl, {
                action: 'riverso_products_search_woo',
                nonce,
                s: q
            }, function(r){
                if (!r.success) return;
                const html = r.data.results.map(p => 
                    `<div class="woo-result-item" data-id="${p.id}">${esc(p.name)} (SKU: ${esc(p.sku)})</div>`
                ).join('');
                $('#woo-results').html(html).show();
            });
        }, 300);
    });

    $(document).on('click', '.woo-result-item', function(){
        const id = $(this).data('id');
        const text = $(this).text();
        $('#woo-selected-id').val(id);
        $('#woo-selected-display').text('Seleccionado: ' + text);
        $('#woo-results').hide();
    });

    // Evento: buscar código proveedor
    $(document).on('keyup', '#supplier-code-search', function(){
        const q = $(this).val();
        if (q.length < 2) {
            $('#supplier-search-results').hide();
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_search_code',
            nonce,
            code: q
        }, function(r){
            if (!r.success) return;
            const results = Array.isArray(r.data.results) ? r.data.results : [r.data.results];
            const html = results.map(c => {
                const label = c.nombre_proveedor || c.codigo_proveedor || 'N/A';
                return `<div class="supplier-result-item" data-code="${esc(c.codigo_proveedor)}" data-supplier-id="${c.proveedor_id || 0}">${esc(label)}</div>`;
            }).join('');
            $('#supplier-search-results').html(html).show();
        });
    });

    $(document).on('click', '.supplier-result-item', function(){
        const code = $(this).data('code');
        const supplierId = $(this).data('supplier-id');
        $('#supplier-code-select').val(code);
        $('#supplier-id-select').val(supplierId);
        $('#supplier-search-results').hide();
        updateSupplierLinkBtnState();
    });

    // Evento: vincular código proveedor
    $('#supplier-link-btn').on('click', function(){
        const productId = currentProduct.id;
        const code = $('#supplier-code-select').val();
        const supplierId = $('#supplier-id-select').val();
        const reason = $('#supplier-audit-reason').val();

        if (!code || !supplierId) {
            alert('Selecciona un código proveedor primero');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_products_link_supplier',
            nonce,
            product_id: productId,
            supplier_code: code,
            supplier_id: supplierId,
            audit_reason: reason
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            showDetail(r.data.item);
            load();
        });
    });

    // Evento: vincular WooCommerce
    $('#online-link-btn').on('click', function(){
        const productId = currentProduct.id;
        const wooId = $('#woo-selected-id').val();

        if (!wooId) {
            alert('Selecciona un producto WooCommerce primero');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_products_set_online',
            nonce,
            product_id: productId,
            woo_id: wooId
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            showDetail(r.data.item);
            load();
        });
    });

    // Evento: abrir modal crear online
    $('#online-create-btn').on('click', function(){
        $('#create-name').val(currentProduct.nombre_canonico || '');
        $('#create-sku').val(currentProduct.canonical_sku || '');
        $('#create-nominal').val('');
        $('#create-largo').val('');
        $('input[name="create-type"][value="simple"]').prop('checked', true);
        $('#create-variable-attrs').hide();
        $('#create-online-modal').addClass('show');
    });

    // Evento: cambiar tipo de producto
    $(document).on('change', 'input[name="create-type"]', function(){
        if ($(this).val() === 'variable') {
            $('#create-variable-attrs').show();
        } else {
            $('#create-variable-attrs').hide();
        }
    });

    // Evento: crear producto online
    $('#create-online-submit').on('click', function(){
        const productId = currentProduct.id;
        const productType = $('input[name="create-type"]:checked').val();
        const name = $('#create-name').val();
        const sku = $('#create-sku').val();

        if (!name || !sku) {
            alert('Nombre y SKU son requeridos');
            return;
        }

        const data = {
            action: 'riverso_products_create_online',
            nonce,
            product_id: productId,
            product_type: productType,
            woo_name: name,
            woo_sku: sku,
        };

        if (productType === 'variable') {
            data.nominal = $('#create-nominal').val();
            data.largo = $('#create-largo').val();
        }

        $.post(ajaxurl, data, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            $('#create-online-modal').removeClass('show');
            showDetail(r.data.item);
            load();
        });
    });

    // Evento: ir a tab códigos desde banner
    $('#online-assign-code-btn').on('click', function(){
        $('.detail-tab').removeClass('active');
        $('[data-tab="suppliers"]').addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-suppliers').show();
        $('html, body').animate({scrollTop: $('#tab-suppliers').offset().top - 40}, 300);
    });

    // Evento: ir a tab desde task
    $(document).on('click', '.task-goto', function(){
        const tab = $(this).data('tab');
        $('.detail-tab').removeClass('active');
        $('[data-tab="' + tab + '"]').addClass('active');
        $('.detail-tab-content').hide();
        $('#tab-' + tab).show();
        $('html, body').animate({scrollTop: $('#tab-' + tab).offset().top - 40}, 300);
    });

    // Evento: agregar barcode
    $('#barcode-add-btn').on('click', function(){
        const productId = currentProduct.id;
        const barcode = $('#barcode-new').val();
        const reason = $('#barcode-audit-reason').val();

        if (!barcode) {
            alert('Ingresa un código de barra');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_products_add_barcode',
            nonce,
            product_id: productId,
            barcode: barcode,
            audit_reason: reason
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            $('#barcode-new').val('');
            $('#barcode-audit-reason').val('');
            showDetail(r.data.item);
        });
    });

    $(document).on('click', '.barcode-remove', function(){
        const barcode = $(this).data('barcode');
        const reason = prompt('Motivo de remoción:');
        if (reason === null) return;

        $.post(ajaxurl, {
            action: 'riverso_products_remove_barcode',
            nonce,
            product_id: currentProduct.id,
            barcode: barcode,
            audit_reason: reason
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            showDetail(r.data.item);
        });
    });

    // Evento: ver producto
    $(document).on('click', '.product-view, .completeness-badge[data-product-id]', function(){
        const productId = $(this).attr('data-product-id') || $(this).data('id');
        const tab = $(this).hasClass('completeness-badge') ? 'suppliers' : undefined;
        
        $.post(ajaxurl, {
            action: 'riverso_products_get',
            nonce,
            id: productId
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            showDetail(r.data.item);
            
            // Si se hizo click en el badge "Falta código", ir al tab de suppliers
            if (tab) {
                setTimeout(function(){
                    $('.detail-tab').removeClass('active');
                    $('[data-tab="' + tab + '"]').addClass('active');
                    $('.detail-tab-content').hide();
                    $('#tab-' + tab).show();
                }, 100);
            }
        });
    });

    // Evento: editar producto
    $(document).on('click', '.product-edit', function(){
        $.post(ajaxurl, {
            action: 'riverso_products_get',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            const it = r.data.item;
            $('#product-id').val(it.id);
            $('#product-sku').val(it.canonical_sku);
            $('#product-name').val(it.nombre_canonico);
            $('#product-unit').val(it.unidad_base);
            $('#product-decimal').prop('checked', parseInt(it.permite_decimal) === 1);
            $('#product-ean13').prop('checked', parseInt(it.permite_ean13_personalizado) === 1);
            $('#product-open-stock').prop('checked', parseInt(it.stock_abierto_habilitado) === 1);
            $('#product-editor-title').text('Editar: ' + it.nombre_canonico);
            $('#product-editor').show();
        });
    });

    // Evento: nuevo producto
    $('#products-new').on('click', function(){
        resetEditor();
        $('#product-editor-title').text('Nuevo producto local');
        $('#product-editor').show();
    });

    // Evento: guardar producto
    $('#product-save').on('click', function(){
        const id = $('#product-id').val();
        const payload = {
            action: 'riverso_products_save',
            nonce,
            id: id || 0,
            canonical_sku: $('#product-sku').val(),
            nombre_canonico: $('#product-name').val(),
            unidad_base: $('#product-unit').val(),
            permite_decimal: $('#product-decimal').is(':checked') ? 1 : 0,
            permite_ean13_personalizado: $('#product-ean13').is(':checked') ? 1 : 0,
            stock_abierto_habilitado: $('#product-open-stock').is(':checked') ? 1 : 0,
        };

        $.post(ajaxurl, payload, function(r){
            if (!r.success) {
                alert('Error: ' + r.data.message);
                return;
            }
            alert(r.data.message);
            $('#product-editor').hide();
            resetEditor();
            load();
        });
    });

    $('#product-cancel').on('click', function(){
        $('#product-editor').hide();
        resetEditor();
    });

    $('#detail-close').on('click', function(){
        $('#product-detail-panel').hide();
        currentProduct = null;
    });

    // Evento: archivar
    $(document).on('click', '.product-archive', function(){
        if (!confirm('¿Archivar este producto?')) return;
        $.post(ajaxurl, {
            action: 'riverso_products_archive',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) { alert('Error: ' + r.data.message); return; }
            alert(r.data.message);
            load();
        });
    });

    // Evento: restaurar
    $(document).on('click', '.product-restore', function(){
        if (!confirm('¿Restaurar este producto?')) return;
        $.post(ajaxurl, {
            action: 'riverso_products_restore',
            nonce,
            id: $(this).data('id')
        }, function(r){
            if (!r.success) { alert('Error: ' + r.data.message); return; }
            alert(r.data.message);
            load();
        });
    });

    // Iniciar
    $('#products-reload, #products-status, #products-completeness').on('click change', function(){
        currentOffset = 0;
        load();
    });

    $('#products-search').on('keyup', function(){
        currentOffset = 0;
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(load, 300);
    });

    $('#products-prev').on('click', function(){
        loadPage(currentOffset - currentLimit);
    });

    $('#products-next').on('click', function(){
        loadPage(currentOffset + currentLimit);
    });

    load();
});
</script>
