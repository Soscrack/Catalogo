<?php
/**
 * Template: Gestión de Códigos de Proveedor
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap riverso-codes">
    <h1>
        <span class="dashicons dashicons-tag"></span>
        Códigos de Proveedor
    </h1>

    <!-- Stats -->
    <div class="codes-stats">
        <div class="stat-card">
            <span class="stat-number" id="stat-total">-</span>
            <span class="stat-label">Total Códigos</span>
        </div>
        <div class="stat-card warning">
            <span class="stat-number" id="stat-pending">-</span>
            <span class="stat-label">Pendientes</span>
        </div>
        <div class="stat-card success">
            <span class="stat-number" id="stat-linked">-</span>
            <span class="stat-label">Vinculados</span>
        </div>
        <div class="stat-card">
            <span class="stat-number" id="stat-providers">-</span>
            <span class="stat-label">Proveedores</span>
        </div>
    </div>

    <!-- Tabs -->
    <div class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="pendientes">Pendientes de Vincular</a>
        <a href="#" class="nav-tab" data-tab="todos">Todos los Códigos</a>
        <a href="#" class="nav-tab" data-tab="proveedores">Proveedores</a>
    </div>

    <!-- Tab: Pendientes -->
    <div id="tab-pendientes" class="tab-content">
        <p class="description">
            Items de facturas sin código local asignado. Busca el producto correspondiente y vincúlalo.
        </p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">Código Prov.</th>
                    <th>Descripción</th>
                    <th style="width: 150px;">Proveedor</th>
                    <th style="width: 100px;">Factura</th>
                    <th style="width: 200px;">Vincular SKU</th>
                    <th style="width: 80px;">Acción</th>
                </tr>
            </thead>
            <tbody id="pending-codes-list"></tbody>
        </table>
    </div>

    <!-- Tab: Todos los códigos -->
    <div id="tab-todos" class="tab-content" style="display: none;">
        <div class="filters-bar">
            <select id="filter-proveedor">
                <option value="">Todos los proveedores</option>
            </select>
            <input type="text" id="search-codigo" placeholder="Buscar código o SKU...">
            <button type="button" class="button" id="btn-search-codes">Buscar</button>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">Código Prov.</th>
                    <th>Descripción Proveedor</th>
                    <th style="width: 120px;">SKU Local</th>
                    <th style="width: 150px;">Proveedor</th>
                    <th style="width: 100px;">Fecha</th>
                    <th style="width: 80px;">Estado</th>
                    <th style="width: 100px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="all-codes-list"></tbody>
        </table>
    </div>

    <!-- Tab: Proveedores -->
    <div id="tab-proveedores" class="tab-content" style="display: none;">
        <button type="button" class="button button-primary" id="btn-new-provider" style="margin-bottom: 15px;">
            <span class="dashicons dashicons-plus-alt"></span> Nuevo Proveedor
        </button>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">RUT</th>
                    <th>Nombre</th>
                    <th style="width: 150px;">Contacto</th>
                    <th style="width: 100px;">Códigos</th>
                    <th style="width: 80px;">Estado</th>
                    <th style="width: 100px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="providers-list"></tbody>
        </table>
    </div>
</div>

<!-- Modal: Vincular código -->
<div id="modal-link-code" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 600px;">
        <div class="riverso-modal-header">
            <h2>Vincular Código de Proveedor</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="code-info-box">
                <div class="info-row">
                    <label>Código Proveedor:</label>
                    <span id="link-codigo" class="code-badge"></span>
                </div>
                <div class="info-row">
                    <label>Descripción:</label>
                    <span id="link-descripcion"></span>
                </div>
                <div class="info-row">
                    <label>Proveedor:</label>
                    <span id="link-proveedor"></span>
                </div>
            </div>
            
            <div class="search-section">
                <h4>Buscar Producto en WooCommerce</h4>
                <input type="text" id="link-search-product" class="large-text" placeholder="Buscar por SKU o nombre...">
                <div id="link-search-results"></div>
            </div>
            
            <div id="link-selected-product" style="display: none;">
                <h4>Producto Seleccionado</h4>
                <div class="selected-product-info">
                    <span class="dashicons dashicons-yes-alt" style="color: #4caf50;"></span>
                    <span id="link-product-name"></span>
                    <span id="link-product-sku" class="sku-badge"></span>
                </div>
            </div>
            
            <input type="hidden" id="link-item-id">
            <input type="hidden" id="link-product-id">
            <input type="hidden" id="link-sku-local">
            
            <div class="form-field" style="margin-top: 15px;">
                <label>
                    <input type="checkbox" id="link-save-mapping" checked>
                    Guardar mapeo para futuras facturas
                </label>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-skip-code">Omitir</button>
            <button type="button" class="button button-primary" id="btn-confirm-link" disabled>
                Vincular Código
            </button>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Proveedor -->
<div id="modal-provider" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2>Nuevo Proveedor</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-provider">
                <div class="form-field">
                    <label>RUT *</label>
                    <input type="text" id="prov-rut" name="rut" required placeholder="12.345.678-9">
                </div>
                <div class="form-field">
                    <label>Nombre / Razón Social *</label>
                    <input type="text" id="prov-nombre" name="nombre" required>
                </div>
                <div class="form-field">
                    <label>Giro</label>
                    <input type="text" id="prov-giro" name="giro">
                </div>
                <div class="form-field">
                    <label>Contacto</label>
                    <input type="text" id="prov-contacto" name="contacto">
                </div>
                <div class="form-field">
                    <label>Email</label>
                    <input type="email" id="prov-email" name="email">
                </div>
                <div class="form-field">
                    <label>Teléfono</label>
                    <input type="text" id="prov-telefono" name="telefono">
                </div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-provider">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-provider">Guardar</button>
        </div>
    </div>
</div>

<style>
.codes-stats {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px 25px;
    text-align: center;
    min-width: 120px;
}

.stat-card.warning {
    border-color: #ff9800;
    background: #fff8e1;
}

.stat-card.success {
    border-color: #4caf50;
    background: #e8f5e9;
}

.stat-number {
    display: block;
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.stat-label {
    font-size: 12px;
    color: #666;
}

.tab-content {
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    padding: 20px;
    min-height: 300px;
}

.filters-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.filters-bar input {
    min-width: 200px;
}

.code-badge {
    display: inline-block;
    font-family: monospace;
    font-size: 14px;
    font-weight: 600;
    background: #e3f2fd;
    color: #1565c0;
    padding: 3px 10px;
    border-radius: 3px;
}

.sku-badge {
    display: inline-block;
    font-family: monospace;
    font-size: 12px;
    background: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 10px;
}

.code-info-box {
    background: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    margin-bottom: 8px;
}

.info-row:last-child {
    margin-bottom: 0;
}

.info-row label {
    width: 130px;
    font-weight: 600;
    color: #666;
}

.search-section h4 {
    margin: 0 0 10px 0;
}

#link-search-results {
    border: 1px solid #ddd;
    border-radius: 4px;
    max-height: 200px;
    overflow-y: auto;
    margin-top: 5px;
    display: none;
}

#link-search-results .result-item {
    padding: 10px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
}

#link-search-results .result-item:hover {
    background: #f5f5f5;
}

.selected-product-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #e8f5e9;
    border-radius: 4px;
}

.link-input {
    display: flex;
    gap: 5px;
}

.link-input input {
    width: 120px;
    padding: 3px 6px;
    font-size: 12px;
}

.form-field {
    margin-bottom: 15px;
}

.form-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.form-field input {
    width: 100%;
}

.status-activo { color: #4caf50; }
.status-inactivo { color: #999; }
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';

    // Tabs
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').hide();
        $('#tab-' + $(this).data('tab')).show();
        
        if ($(this).data('tab') === 'pendientes') loadPendingCodes();
        if ($(this).data('tab') === 'todos') loadAllCodes();
        if ($(this).data('tab') === 'proveedores') loadProviders();
    });

    // Cargar estadísticas
    function loadStats() {
        $.post(ajaxurl, {action: 'riverso_get_codes_stats', nonce: nonce}, function(response) {
            if (response.success) {
                $('#stat-total').text(response.data.total || 0);
                $('#stat-pending').text(response.data.pending || 0);
                $('#stat-linked').text(response.data.linked || 0);
                $('#stat-providers').text(response.data.providers || 0);
            }
        });
    }

    // Cargar códigos pendientes
    function loadPendingCodes() {
        $.post(ajaxurl, {action: 'riverso_get_pending_codes', nonce: nonce}, function(response) {
            const tbody = $('#pending-codes-list');
            tbody.empty();
            
            if (!response.success || !response.data.items.length) {
                tbody.html('<tr><td colspan="6" style="text-align: center; padding: 40px;">✓ No hay códigos pendientes de vincular</td></tr>');
                return;
            }
            
            response.data.items.forEach(function(item) {
                tbody.append(`
                    <tr data-item-id="${item.id}">
                        <td><code class="code-badge">${item.codigo_proveedor || '-'}</code></td>
                        <td>${item.descripcion}</td>
                        <td>${item.proveedor_nombre || '-'}</td>
                        <td>#${item.folio}</td>
                        <td>
                            <div class="link-input">
                                <input type="text" class="quick-sku-input" placeholder="SKU local">
                                <button class="button button-small btn-quick-link">OK</button>
                            </div>
                        </td>
                        <td>
                            <button class="button button-small btn-open-link-modal" 
                                data-item="${item.id}"
                                data-codigo="${item.codigo_proveedor || ''}"
                                data-descripcion="${item.descripcion}"
                                data-proveedor="${item.proveedor_nombre || ''}">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                        </td>
                    </tr>
                `);
            });
        });
    }

    // Quick link
    $(document).on('click', '.btn-quick-link', function() {
        const row = $(this).closest('tr');
        const itemId = row.data('item-id');
        const sku = row.find('.quick-sku-input').val().trim();
        
        if (!sku) {
            alert('Ingresa un SKU');
            return;
        }
        
        linkCode(itemId, sku, true, function() {
            row.fadeOut(300, function() { $(this).remove(); });
            loadStats();
        });
    });

    // Modal de vinculación
    $(document).on('click', '.btn-open-link-modal', function() {
        $('#link-item-id').val($(this).data('item'));
        $('#link-codigo').text($(this).data('codigo'));
        $('#link-descripcion').text($(this).data('descripcion'));
        $('#link-proveedor').text($(this).data('proveedor'));
        $('#link-product-id').val('');
        $('#link-sku-local').val('');
        $('#link-selected-product').hide();
        $('#link-search-results').hide();
        $('#link-search-product').val('');
        $('#btn-confirm-link').prop('disabled', true);
        $('#modal-link-code').show();
    });

    // Búsqueda de producto
    $('#link-search-product').on('keyup', debounce(function() {
        const search = $(this).val();
        if (search.length < 2) {
            $('#link-search-results').hide();
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_search_products_warehouse',
            nonce: nonce,
            search: search
        }, function(response) {
            if (response.success) {
                const results = $('#link-search-results');
                results.empty();
                
                if (!response.data.products.length) {
                    results.html('<div style="padding: 15px; text-align: center; color: #666;">No se encontraron productos</div>');
                } else {
                    response.data.products.forEach(function(p) {
                        results.append(`
                            <div class="result-item" data-id="${p.id}" data-sku="${p.sku}" data-name="${p.name}">
                                <span>${p.name}</span>
                                <code>${p.sku}</code>
                            </div>
                        `);
                    });
                }
                results.show();
            }
        });
    }, 300));

    $(document).on('click', '#link-search-results .result-item', function() {
        $('#link-product-id').val($(this).data('id'));
        $('#link-sku-local').val($(this).data('sku'));
        $('#link-product-name').text($(this).data('name'));
        $('#link-product-sku').text($(this).data('sku'));
        $('#link-selected-product').show();
        $('#link-search-results').hide();
        $('#btn-confirm-link').prop('disabled', false);
    });

    $('#btn-confirm-link').on('click', function() {
        const itemId = $('#link-item-id').val();
        const sku = $('#link-sku-local').val();
        const saveMapping = $('#link-save-mapping').is(':checked');
        
        linkCode(itemId, sku, saveMapping, function() {
            $('#modal-link-code').hide();
            loadPendingCodes();
            loadStats();
        });
    });

    function linkCode(itemId, sku, createMapping, callback) {
        $.post(ajaxurl, {
            action: 'riverso_link_code',
            nonce: nonce,
            item_id: itemId,
            sku_local: sku,
            crear_mapeo: createMapping ? 1 : 0
        }, function(response) {
            if (response.success) {
                if (callback) callback();
            } else {
                alert(response.data.message || 'Error vinculando código');
            }
        });
    }

    // Cargar todos los códigos
    function loadAllCodes() {
        $.post(ajaxurl, {
            action: 'riverso_get_all_codes',
            nonce: nonce,
            proveedor_id: $('#filter-proveedor').val(),
            search: $('#search-codigo').val()
        }, function(response) {
            const tbody = $('#all-codes-list');
            tbody.empty();
            
            if (!response.success || !response.data.codes.length) {
                tbody.html('<tr><td colspan="7" style="text-align: center;">No hay códigos registrados</td></tr>');
                return;
            }
            
            response.data.codes.forEach(function(code) {
                tbody.append(`
                    <tr>
                        <td><code>${code.codigo_proveedor}</code></td>
                        <td>${code.descripcion_proveedor || '-'}</td>
                        <td><strong>${code.sku_local || '-'}</strong></td>
                        <td>${code.proveedor_nombre || '-'}</td>
                        <td>${code.created_at ? code.created_at.split(' ')[0] : '-'}</td>
                        <td><span class="status-${code.activo ? 'activo' : 'inactivo'}">${code.activo ? 'Activo' : 'Inactivo'}</span></td>
                        <td>
                            <button class="button button-small btn-edit-code" data-id="${code.id}">Editar</button>
                        </td>
                    </tr>
                `);
            });
        });
    }

    $('#btn-search-codes').on('click', loadAllCodes);

    // Cargar proveedores
    function loadProviders() {
        $.post(ajaxurl, {action: 'riverso_get_providers', nonce: nonce}, function(response) {
            const tbody = $('#providers-list');
            tbody.empty();
            
            // También actualizar select de filtro
            const select = $('#filter-proveedor');
            select.find('option:not(:first)').remove();
            
            if (!response.success || !response.data.providers.length) {
                tbody.html('<tr><td colspan="6" style="text-align: center;">No hay proveedores</td></tr>');
                return;
            }
            
            response.data.providers.forEach(function(p) {
                tbody.append(`
                    <tr>
                        <td>${p.rut}</td>
                        <td><strong>${p.nombre}</strong></td>
                        <td>${p.contacto || p.email || '-'}</td>
                        <td>${p.codigos_count || 0}</td>
                        <td><span class="status-${p.activo ? 'activo' : 'inactivo'}">${p.activo ? 'Activo' : 'Inactivo'}</span></td>
                        <td>
                            <button class="button button-small btn-edit-provider" data-id="${p.id}">Editar</button>
                        </td>
                    </tr>
                `);
                select.append(`<option value="${p.id}">${p.nombre}</option>`);
            });
        });
    }

    // Nuevo proveedor
    $('#btn-new-provider').on('click', function() {
        $('#form-provider')[0].reset();
        $('#modal-provider').show();
    });

    $('#btn-save-provider').on('click', function() {
        $.post(ajaxurl, {
            action: 'riverso_create_provider',
            nonce: nonce,
            rut: $('#prov-rut').val(),
            nombre: $('#prov-nombre').val(),
            giro: $('#prov-giro').val(),
            contacto: $('#prov-contacto').val(),
            email: $('#prov-email').val(),
            telefono: $('#prov-telefono').val()
        }, function(response) {
            if (response.success) {
                $('#modal-provider').hide();
                loadProviders();
                loadStats();
            } else {
                alert(response.data.message);
            }
        });
    });

    // Cerrar modales
    $('.riverso-modal-close, #btn-cancel-provider, #btn-skip-code').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Cargar inicial
    loadStats();
    loadPendingCodes();
    loadProviders();
});
</script>
