<?php
/**
 * Template: Gestión de Bodega
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/warehouse/class-warehouse-module.php';
$location_types = Riverso_Warehouse_Module::LOCATION_TYPES;
$movement_types = Riverso_Warehouse_Module::MOVEMENT_TYPES;
?>

<div class="wrap riverso-warehouse">
    <h1>
        <span class="dashicons dashicons-store"></span>
        Gestión de Bodega
    </h1>

    <!-- Tabs -->
    <div class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="ubicaciones">Ubicaciones</a>
        <a href="#" class="nav-tab" data-tab="movimientos">Movimientos</a>
        <a href="#" class="nav-tab" data-tab="buscar">Buscar Producto</a>
    </div>

    <!-- Tab: Ubicaciones -->
    <div id="tab-ubicaciones" class="tab-content">
        <div class="tab-header">
            <div class="filters">
                <select id="filter-tipo-ubicacion">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($location_types as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filter-estado-ubicacion">
                    <option value="1">Activas</option>
                    <option value="0">Desactivadas</option>
                    <option value="">Todas</option>
                </select>
                <input type="text" id="search-ubicacion" placeholder="Buscar código o nombre...">
            </div>
            <?php if (current_user_can('riverso_edit_stock')): ?>
            <button type="button" class="button button-primary" id="btn-new-location">
                <span class="dashicons dashicons-plus-alt"></span> Nueva Ubicación
            </button>
            <?php endif; ?>
        </div>

        <div class="locations-grid" id="locations-grid">
            <!-- Cargado via JS -->
        </div>
    </div>

    <!-- Tab: Movimientos -->
    <div id="tab-movimientos" class="tab-content" style="display: none;">
        <div class="tab-header">
            <div class="filters">
                <select id="filter-tipo-movimiento">
                    <option value="">Todos los tipos</option>
                    <?php foreach ($movement_types as $key => $m): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($m['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="filter-mov-desde" placeholder="Desde">
                <input type="date" id="filter-mov-hasta" placeholder="Hasta">
                <button type="button" class="button" id="btn-filter-movements">Filtrar</button>
            </div>
            <?php if (current_user_can('riverso_edit_stock')): ?>
            <button type="button" class="button button-primary" id="btn-new-movement">
                <span class="dashicons dashicons-update"></span> Registrar Movimiento
            </button>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat fixed striped" id="movements-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Stock Anterior</th>
                    <th>Stock Nuevo</th>
                    <th>Ubicación</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody id="movements-list"></tbody>
        </table>
    </div>

    <!-- Tab: Buscar Producto -->
    <div id="tab-buscar" class="tab-content" style="display: none;">
        <div class="search-product-box">
            <input type="text" id="product-search-input" placeholder="Buscar por SKU o nombre..." class="large-text">
            <div id="product-search-results"></div>
        </div>
        
        <div id="product-detail-panel" style="display: none;">
            <h3 id="product-detail-name"></h3>
            <div class="product-info-grid">
                <div class="info-item">
                    <label>SKU:</label>
                    <span id="product-detail-sku"></span>
                </div>
                <div class="info-item">
                    <label>Stock Total:</label>
                    <span id="product-detail-stock" class="stock-badge"></span>
                </div>
            </div>
            
            <h4>Ubicaciones</h4>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Cantidad</th>
                        <th>Posición</th>
                    </tr>
                </thead>
                <tbody id="product-locations-list"></tbody>
            </table>
            
            <div style="margin-top: 15px;">
                <button type="button" class="button" id="btn-assign-location">
                    <span class="dashicons dashicons-plus"></span> Asignar a Ubicación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nueva Ubicación -->
<div id="modal-location" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2>Nueva Ubicación</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-location">
                <input type="hidden" id="location-id" name="location_id" value="">
                
                <div class="form-field">
                    <label>Tipo *</label>
                    <select id="location-tipo" name="tipo" required>
                        <?php foreach ($location_types as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label>Nombre *</label>
                    <input type="text" id="location-nombre" name="nombre" required placeholder="Ej: Pasillo A, Estante 1">
                </div>
                
                <div class="form-field">
                    <label>Código (auto si vacío)</label>
                    <input type="text" id="location-codigo" name="codigo" placeholder="Ej: P-A01">
                </div>
                
                <div class="form-field">
                    <label>Descripción</label>
                    <textarea id="location-descripcion" name="descripcion" rows="2"></textarea>
                </div>
                
                <div class="form-field">
                    <label>Capacidad (productos)</label>
                    <input type="number" id="location-capacidad" name="capacidad" min="0" value="0">
                </div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-location">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-location">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Movimiento -->
<div id="modal-movement" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2>Registrar Movimiento</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-movement">
                <div class="form-field">
                    <label>Tipo de Movimiento *</label>
                    <select id="mov-tipo" name="tipo" required>
                        <?php foreach ($movement_types as $key => $m): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($m['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label>Producto *</label>
                    <input type="text" id="mov-product-search" placeholder="Buscar SKU o nombre...">
                    <input type="hidden" id="mov-product-id" name="product_id">
                    <div id="mov-product-selected" style="display: none; margin-top: 5px;"></div>
                </div>
                
                <div class="form-field">
                    <label>Cantidad *</label>
                    <input type="number" id="mov-cantidad" name="cantidad" min="0.01" step="0.01" required>
                </div>
                
                <div class="form-row" id="ubicacion-fields">
                    <div class="form-field">
                        <label>Ubicación Origen</label>
                        <select id="mov-ubicacion-origen" name="ubicacion_origen"></select>
                    </div>
                    <div class="form-field">
                        <label>Ubicación Destino</label>
                        <select id="mov-ubicacion-destino" name="ubicacion_destino"></select>
                    </div>
                </div>
                
                <div class="form-field">
                    <label>Notas</label>
                    <textarea id="mov-notas" name="notas" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-movement">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-movement">Registrar</button>
        </div>
    </div>
</div>

<style>
.riverso-warehouse .tab-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-top: none;
}

.riverso-warehouse .filters {
    display: flex;
    gap: 10px;
}

.riverso-warehouse .tab-content {
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    padding: 15px;
    min-height: 400px;
}

.locations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}

.location-card {
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px;
    background: #fafafa;
}

.location-card .location-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
}

.location-card .location-code {
    font-size: 18px;
    font-weight: 600;
    font-family: monospace;
    color: #1976d2;
}

.location-card .location-type {
    font-size: 11px;
    background: #e3f2fd;
    padding: 2px 6px;
    border-radius: 3px;
}

.location-card .location-name {
    font-weight: 500;
    margin-bottom: 5px;
}

.location-card .location-products {
    font-size: 13px;
    color: #666;
}

.location-card .location-actions {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
    display: flex;
    gap: 5px;
}

.location-card.inactive {
    background: #f5f5f5;
    opacity: 0.7;
    border-color: #ccc;
}

.location-card.inactive .location-code {
    color: #999;
}

.location-card .location-status {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 3px;
    margin-left: 5px;
}

.location-card .location-status.active {
    background: #e8f5e9;
    color: #2e7d32;
}

.location-card .location-status.inactive {
    background: #ffebee;
    color: #c62828;
}

.search-product-box {
    max-width: 500px;
    margin-bottom: 20px;
}

#product-search-results {
    margin-top: 5px;
    border: 1px solid #ddd;
    border-radius: 4px;
    max-height: 200px;
    overflow-y: auto;
    display: none;
}

#product-search-results .result-item {
    padding: 10px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}

#product-search-results .result-item:hover {
    background: #f5f5f5;
}

.product-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stock-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-weight: 600;
}

.stock-badge.in-stock { background: #e8f5e9; color: #2e7d32; }
.stock-badge.low-stock { background: #fff3e0; color: #ef6c00; }
.stock-badge.out-of-stock { background: #ffebee; color: #c62828; }

.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-field {
    flex: 1;
}

.form-field {
    margin-bottom: 15px;
}

.form-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    const locationTypes = <?php echo wp_json_encode($location_types); ?>;
    const movementTypes = <?php echo wp_json_encode($movement_types); ?>;
    let currentProductId = null;
    let locationsCache = [];

    // Tabs - cargar datos al cambiar de tab
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').hide();
        const tab = $(this).data('tab');
        $('#tab-' + tab).show();
        
        // Cargar datos según el tab
        if (tab === 'ubicaciones') {
            loadLocations();
        } else if (tab === 'movimientos') {
            loadMovements();
        }
    });

    // ========== UBICACIONES ==========
    function loadLocations() {
        const estadoFilter = $('#filter-estado-ubicacion').val();
        $('#locations-grid').html('<p style="padding: 40px; text-align: center; color: #666;"><span class="spinner is-active" style="float: none;"></span><br>Cargando ubicaciones...</p>');
        
        const data = {
            action: 'riverso_get_locations',
            nonce: nonce,
            tipo: $('#filter-tipo-ubicacion').val(),
            search: $('#search-ubicacion').val()
        };
        
        // Solo enviar filtro activo si tiene valor
        if (estadoFilter !== '') {
            data.activo = parseInt(estadoFilter);
        }
        
        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                locationsCache = response.data.locations || [];
                renderLocations(locationsCache);
                updateLocationSelects();
            } else {
                $('#locations-grid').html('<p style="padding: 40px; text-align: center; color: #d9534f;">Error: ' + (response.data?.message || 'Error al cargar ubicaciones') + '</p>');
            }
        }).fail(function() {
            $('#locations-grid').html('<p style="padding: 40px; text-align: center; color: #d9534f;">Error de conexión al servidor</p>');
        });
    }

    function renderLocations(locations) {
        const grid = $('#locations-grid');
        grid.empty();

        if (!locations.length) {
            grid.html('<p style="padding: 40px; text-align: center; color: #666;">No hay ubicaciones</p>');
            return;
        }

        locations.forEach(function(loc) {
            const isActive = parseInt(loc.activo) === 1;
            const cardClass = isActive ? '' : 'inactive';
            const statusClass = isActive ? 'active' : 'inactive';
            const statusText = isActive ? 'Activa' : 'Desactivada';
            
            let actions = '';
            if (isActive) {
                actions = `
                    <button class="button button-small btn-edit-location">Editar</button>
                    <button class="button button-small btn-deactivate-location">Desactivar</button>
                `;
            } else {
                actions = `
                    <button class="button button-small btn-activate-location" style="background: #4caf50; color: white; border-color: #4caf50;">Reactivar</button>
                    <button class="button button-small btn-delete-permanent" style="background: #d32f2f; color: white; border-color: #d32f2f;">Eliminar</button>
                `;
            }
            
            grid.append(`
                <div class="location-card ${cardClass}" data-id="${loc.id}">
                    <div class="location-header">
                        <span class="location-code">${loc.codigo}</span>
                        <span>
                            <span class="location-type">${locationTypes[loc.tipo] || loc.tipo}</span>
                            <span class="location-status ${statusClass}">${statusText}</span>
                        </span>
                    </div>
                    <div class="location-name">${loc.nombre}</div>
                    <div class="location-products">
                        <span class="dashicons dashicons-archive"></span> ${loc.productos_count} productos
                    </div>
                    <div class="location-actions">
                        ${actions}
                    </div>
                </div>
            `);
        });
    }

    function updateLocationSelects() {
        const options = '<option value="">Ninguna</option>' + 
            locationsCache.map(l => `<option value="${l.id}">${l.codigo} - ${l.nombre}</option>`).join('');
        $('#mov-ubicacion-origen, #mov-ubicacion-destino').html(options);
    }

    $('#filter-tipo-ubicacion, #filter-estado-ubicacion, #search-ubicacion').on('change keyup', debounce(loadLocations, 300));

    $('#btn-new-location').on('click', function() {
        $('#form-location')[0].reset();
        $('#location-id').val('');
        $('#modal-location').find('.riverso-modal-header h2').text('Nueva Ubicación');
        $('#modal-location').show();
    });

    $('#btn-save-location').on('click', function() {
        const $btn = $(this);
        const id = $('#location-id').val();
        const nombre = $('#location-nombre').val().trim();
        
        if (!nombre) {
            alert('El nombre es requerido');
            return;
        }
        
        $btn.prop('disabled', true).text('Guardando...');
        
        const data = {
            action: id ? 'riverso_update_location' : 'riverso_create_location',
            nonce: nonce,
            location_id: id,
            tipo: $('#location-tipo').val(),
            nombre: nombre,
            codigo: $('#location-codigo').val(),
            descripcion: $('#location-descripcion').val(),
            capacidad: $('#location-capacidad').val() || 0
        };

        $.post(ajaxurl, data, function(response) {
            $btn.prop('disabled', false).text('Guardar');
            if (response.success) {
                $('#modal-location').hide();
                loadLocations();
                alert(response.data.message || 'Ubicación guardada');
            } else {
                alert('Error: ' + (response.data?.message || 'Error desconocido'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Guardar');
            alert('Error de conexión al servidor');
        });
    });

    $(document).on('click', '.btn-edit-location', function() {
        const card = $(this).closest('.location-card');
        const id = card.data('id');
        const loc = locationsCache.find(l => l.id == id);
        if (loc) {
            $('#location-id').val(loc.id);
            $('#location-tipo').val(loc.tipo);
            $('#location-nombre').val(loc.nombre);
            $('#location-codigo').val(loc.codigo);
            $('#location-descripcion').val(loc.descripcion || '');
            $('#location-capacidad').val(loc.capacidad || 0);
            $('#modal-location').find('.riverso-modal-header h2').text('Editar Ubicación');
            $('#modal-location').show();
        }
    });

    $(document).on('click', '.btn-deactivate-location', function() {
        if (!confirm('¿Desactivar esta ubicación?')) return;
        const id = $(this).closest('.location-card').data('id');
        $.post(ajaxurl, {action: 'riverso_delete_location', nonce: nonce, location_id: id}, function(response) {
            if (response.success) {
                loadLocations();
            } else {
                alert('Error: ' + (response.data?.message || 'No se pudo desactivar'));
            }
        });
    });

    $(document).on('click', '.btn-activate-location', function() {
        const id = $(this).closest('.location-card').data('id');
        $.post(ajaxurl, {action: 'riverso_activate_location', nonce: nonce, location_id: id}, function(response) {
            if (response.success) {
                loadLocations();
                alert('Ubicación reactivada');
            } else {
                alert('Error: ' + (response.data?.message || 'No se pudo reactivar'));
            }
        });
    });

    $(document).on('click', '.btn-delete-permanent', function() {
        if (!confirm('¿ELIMINAR PERMANENTEMENTE esta ubicación? Esta acción no se puede deshacer.')) return;
        const id = $(this).closest('.location-card').data('id');
        $.post(ajaxurl, {action: 'riverso_delete_location', nonce: nonce, location_id: id, permanent: 1}, function(response) {
            if (response.success) {
                loadLocations();
                alert('Ubicación eliminada permanentemente');
            } else {
                alert('Error: ' + (response.data?.message || 'No se pudo eliminar'));
            }
        });
    });

    // ========== MOVIMIENTOS ==========
    function loadMovements() {
        $('#movements-list').html('<tr><td colspan="8" style="text-align: center;"><span class="spinner is-active" style="float: none;"></span> Cargando...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'riverso_get_movements',
            nonce: nonce,
            tipo: $('#filter-tipo-movimiento').val(),
            fecha_desde: $('#filter-mov-desde').val(),
            fecha_hasta: $('#filter-mov-hasta').val()
        }, function(response) {
            if (response.success) {
                renderMovements(response.data.movements || []);
            } else {
                $('#movements-list').html('<tr><td colspan="8" style="text-align: center; color: #d9534f;">Error al cargar movimientos</td></tr>');
            }
        }).fail(function() {
            $('#movements-list').html('<tr><td colspan="8" style="text-align: center; color: #d9534f;">Error de conexión</td></tr>');
        });
    }

    function renderMovements(movements) {
        const tbody = $('#movements-list');
        tbody.empty();

        if (!movements.length) {
            tbody.html('<tr><td colspan="8" style="text-align: center;">Sin movimientos</td></tr>');
            return;
        }

        movements.forEach(function(m) {
            const type = movementTypes[m.tipo] || {};
            tbody.append(`
                <tr>
                    <td>${m.created_at.split(' ')[0]}</td>
                    <td><span style="color: ${type.color || '#666'}">${type.label || m.tipo}</span></td>
                    <td>${m.product_id}</td>
                    <td style="text-align: right; font-weight: 600;">${parseFloat(m.cantidad).toFixed(0)}</td>
                    <td style="text-align: right;">${parseFloat(m.stock_anterior).toFixed(0)}</td>
                    <td style="text-align: right;">${parseFloat(m.stock_nuevo).toFixed(0)}</td>
                    <td>${m.ubicacion_destino_codigo || m.ubicacion_origen_codigo || '-'}</td>
                    <td>${m.usuario_nombre || '-'}</td>
                </tr>
            `);
        });
    }

    $('#btn-filter-movements').on('click', loadMovements);

    $('#btn-new-movement').on('click', function() {
        $('#form-movement')[0].reset();
        $('#mov-product-id').val('');
        $('#mov-product-selected').hide();
        $('#modal-movement').show();
    });

    // Búsqueda de producto en modal movimiento
    $('#mov-product-search').on('keyup', debounce(function() {
        const search = $(this).val();
        if (search.length < 2) return;

        $.post(ajaxurl, {
            action: 'riverso_search_products_warehouse',
            nonce: nonce,
            search: search
        }, function(response) {
            if (response.success && response.data.products.length) {
                const product = response.data.products[0];
                $('#mov-product-id').val(product.id);
                $('#mov-product-selected').html(`<strong>${product.sku}</strong> - ${product.name} (Stock: ${product.stock || 0})`).show();
            }
        });
    }, 300));

    $('#btn-save-movement').on('click', function() {
        const data = {
            action: 'riverso_record_movement',
            nonce: nonce,
            tipo: $('#mov-tipo').val(),
            product_id: $('#mov-product-id').val(),
            cantidad: $('#mov-cantidad').val(),
            ubicacion_origen: $('#mov-ubicacion-origen').val(),
            ubicacion_destino: $('#mov-ubicacion-destino').val(),
            notas: $('#mov-notas').val()
        };

        if (!data.product_id || !data.cantidad) {
            alert('Producto y cantidad requeridos');
            return;
        }

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                $('#modal-movement').hide();
                loadMovements();
            } else {
                alert(response.data.message);
            }
        });
    });

    // ========== BUSCAR PRODUCTO ==========
    $('#product-search-input').on('keyup', debounce(function() {
        const search = $(this).val();
        if (search.length < 2) {
            $('#product-search-results').hide();
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_search_products_warehouse',
            nonce: nonce,
            search: search
        }, function(response) {
            if (response.success) {
                const results = $('#product-search-results');
                results.empty();
                response.data.products.forEach(function(p) {
                    results.append(`<div class="result-item" data-id="${p.id}" data-name="${p.name}" data-sku="${p.sku}" data-stock="${p.stock}">
                        <strong>${p.sku}</strong> - ${p.name} (Stock: ${p.stock || 0})
                    </div>`);
                });
                results.show();
            }
        });
    }, 300));

    $(document).on('click', '.result-item', function() {
        currentProductId = $(this).data('id');
        $('#product-detail-name').text($(this).data('name'));
        $('#product-detail-sku').text($(this).data('sku'));
        const stock = $(this).data('stock') || 0;
        let stockClass = 'in-stock';
        if (stock <= 0) stockClass = 'out-of-stock';
        else if (stock < 10) stockClass = 'low-stock';
        $('#product-detail-stock').text(stock).attr('class', 'stock-badge ' + stockClass);
        
        $('#product-search-results').hide();
        $('#product-detail-panel').show();
        loadProductLocations(currentProductId);
    });

    function loadProductLocations(productId) {
        $.post(ajaxurl, {
            action: 'riverso_get_product_locations',
            nonce: nonce,
            product_id: productId
        }, function(response) {
            if (response.success) {
                const tbody = $('#product-locations-list');
                tbody.empty();
                if (!response.data.locations.length) {
                    tbody.html('<tr><td colspan="5" style="text-align: center;">Sin ubicaciones asignadas</td></tr>');
                    return;
                }
                response.data.locations.forEach(function(loc) {
                    tbody.append(`<tr>
                        <td><code>${loc.codigo}</code></td>
                        <td>${loc.nombre}</td>
                        <td>${locationTypes[loc.tipo] || loc.tipo}</td>
                        <td>${loc.cantidad}</td>
                        <td>${loc.posicion || '-'}</td>
                    </tr>`);
                });
            }
        });
    }

    // Cerrar modales
    $('.riverso-modal-close, #btn-cancel-location, #btn-cancel-movement').on('click', function() {
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
    loadLocations();
});
</script>
