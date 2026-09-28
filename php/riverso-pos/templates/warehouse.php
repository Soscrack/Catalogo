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
        <?php if (current_user_can('riverso_do_inventory') || current_user_can('riverso_edit_stock') || current_user_can('manage_options')): ?>
        <a href="#" class="nav-tab" data-tab="inventario">Inventario</a>
        <?php endif; ?>
        <a href="#" class="nav-tab" data-tab="movimientos">Movimientos</a>
        <a href="#" class="nav-tab" data-tab="buscar">Buscar Producto</a>
        <a href="#" class="nav-tab" data-tab="stock-status">Estado de stock</a>
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
                    <option value="">Todas</option>
                    <option value="1">Activas</option>
                    <option value="0">Desactivadas</option>
                </select>
                <input type="text" id="search-ubicacion" placeholder="Buscar código o nombre...">
            </div>
            <?php if (current_user_can('riverso_edit_stock') || current_user_can('riverso_edit_warehouse') || current_user_can('manage_options')): ?>
            <button type="button" class="button button-primary" id="btn-new-location">
                <span class="dashicons dashicons-plus-alt"></span> Nueva Ubicación
            </button>
            <?php endif; ?>
        </div>

        <div class="locations-grid" id="locations-grid">
            <!-- Cargado via JS -->
        </div>
    </div>

    <?php if (current_user_can('riverso_do_inventory') || current_user_can('riverso_edit_stock') || current_user_can('manage_options')): ?>
    <div id="tab-inventario" class="tab-content" style="display: none;">
        <?php
        $riverso_wh_embed = true;
        include RIVERSO_POS_PLUGIN_DIR . 'templates/portal/portal-warehouse.php';
        ?>
    </div>
    <?php endif; ?>

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
            <?php if (current_user_can('riverso_edit_stock') || current_user_can('riverso_edit_warehouse') || current_user_can('manage_options')): ?>
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

    <!-- Tab: Estado de stock -->
    <div id="tab-stock-status" class="tab-content" style="display: none;">
        <div class="tab-header">
            <div class="filters">
                <input type="search" id="stock-status-q" placeholder="Buscar SKU o nombre..." class="regular-text">
                <select id="stock-status-inv">
                    <option value="">Inventariado: Todos</option>
                    <option value="exacto">Exacto</option>
                    <option value="al_menos">Al menos</option>
                    <option value="desconocido">Desconocido</option>
                </select>
                <select id="stock-status-conf">
                    <option value="">Confianza: Todos</option>
                    <option value="confiable">Confiable</option>
                    <option value="poco_confiable">Poco confiable</option>
                    <option value="dudoso">Dudoso</option>
                </select>
                <select id="stock-status-alerta">
                    <option value="">Alerta: Todas</option>
                    <option value="1">Solo con alerta</option>
                    <option value="0">Sin alerta</option>
                </select>
                <button type="button" class="button" id="btn-stock-status-reload">Actualizar</button>
            </div>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Stock</th>
                    <th>Mín.</th>
                    <th>Crítico</th>
                    <th>Inventariado</th>
                    <th>Confianza</th>
                    <th>Último conteo</th>
                    <?php if (current_user_can('riverso_edit_stock') || current_user_can('manage_options')): ?>
                    <th></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="stock-status-body">
                <tr><td colspan="9">Cargando...</td></tr>
            </tbody>
        </table>
        <h3 style="margin-top:24px;">Emparejamientos (stock sumado)</h3>
        <p style="color:#666;margin-top:0;">Alertas del grupo sin mezclar el stock propio de cada SKU. Umbrales se editan en Categorías → Emparejamientos.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Stock sumado</th>
                    <th>Mín.</th>
                    <th>Crítico</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody id="stock-emp-body">
                <tr><td colspan="6">—</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Límites de stock -->
<div id="modal-stock-limits" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2>Límites de stock</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p id="stock-limits-prod" style="margin-top:0;color:#555;"></p>
            <input type="hidden" id="stock-limits-id" value="">
            <div class="form-field">
                <label for="stock-limits-min">Stock mínimo (aviso para encargar)</label>
                <input type="number" id="stock-limits-min" min="0" step="1" placeholder="Vacío = sin aviso">
            </div>
            <div class="form-field">
                <label for="stock-limits-crit">Stock crítico</label>
                <input type="number" id="stock-limits-crit" min="0" step="1" placeholder="Vacío = sin crítico">
                <p class="description" style="margin:6px 0 0;">El crítico nunca puede ser mayor que el mínimo. Si baja el mínimo, el crítico se iguala. Si sube el crítico por encima del mínimo, el mínimo se iguala.</p>
            </div>
            <p id="stock-limits-hint" style="display:none;color:#1565c0;font-weight:600;"></p>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-stock-limits">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-stock-limits">Guardar</button>
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

<div id="modal-loc-detail" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content loc-detail-content">
        <div class="riverso-modal-header">
            <div>
                <h2 id="loc-detail-title">Lugar</h2>
                <p id="loc-detail-meta" style="margin:4px 0 0;color:#666;font-size:13px;"></p>
            </div>
            <button type="button" class="riverso-modal-close" aria-label="Cerrar">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p style="margin:0 0 12px;">
                <button type="button" class="button loc-detail-sec button-primary" data-sec="inv">Inventario actual</button>
                <button type="button" class="button loc-detail-sec" data-sec="pref">Productos preferidos</button>
            </p>
            <div id="loc-detail-inv">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Último conteo</th>
                        </tr>
                    </thead>
                    <tbody id="loc-detail-inv-body">
                        <tr><td colspan="4">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="loc-detail-pref" style="display:none;">
                <?php if (current_user_can('riverso_edit_warehouse') || current_user_can('riverso_edit_stock') || current_user_can('manage_options')): ?>
                <div class="form-field">
                    <label for="loc-detail-pref-q">Asignar producto que prefiere este lugar</label>
                    <input type="text" id="loc-detail-pref-q" placeholder="Buscar SKU o nombre" autocomplete="off">
                    <div id="loc-detail-pref-sug" class="loc-detail-suggest"></div>
                </div>
                <label style="display:flex;gap:6px;align-items:center;margin:0 0 12px;">
                    <input type="checkbox" id="loc-detail-pref-primary" checked> Marcar como lugar preferido principal
                </label>
                <?php endif; ?>
                <div id="loc-detail-pref-list">Cargando...</div>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-loc-detail">Cerrar</button>
        </div>
    </div>
</div>

<div id="modal-loc-print" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width:560px;">
        <div class="riverso-modal-header">
            <div>
                <h2>Imprimir etiqueta de lugar</h2>
                <p id="loc-print-meta" style="margin:4px 0 0;color:#666;font-size:13px;"></p>
            </div>
            <button type="button" class="riverso-modal-close" aria-label="Cerrar">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="form-field">
                <label for="loc-print-copias">Copias</label>
                <input type="number" id="loc-print-copias" min="1" max="9999" value="1" style="width:100px;">
            </div>
            <p id="loc-print-choose">
                <button type="button" class="button button-primary" id="btn-loc-print-now">Imprimir ya</button>
                <button type="button" class="button" id="btn-loc-print-queue">Agregar a orden de impresión</button>
            </p>
            <p id="loc-print-msg" style="color:#b45309;font-weight:600;display:none;"></p>
            <div id="loc-print-orders" style="display:none;">
                <p style="margin:0 0 8px;">
                    <button type="button" class="button button-primary" id="btn-loc-print-new">Crear nueva orden con este lugar</button>
                </p>
                <div id="loc-print-orders-list">Cargando...</div>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-loc-print">Cerrar</button>
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
    flex-wrap: wrap;
    gap: 5px;
}

.loc-detail-content {
    max-width: 760px;
}

.loc-detail-suggest {
    border: 1px solid #ddd;
    background: #fff;
    max-height: 180px;
    overflow: auto;
    display: none;
    margin-top: 4px;
}

.loc-detail-suggest div {
    padding: 8px 10px;
    cursor: pointer;
}

.loc-detail-suggest div:hover {
    background: #f5f5f5;
}

.loc-pref-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
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

.wh-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.wh-badge-ok { background:#e8f5e9; color:#2e7d32; }
.wh-badge-warn { background:#fff3e0; color:#e65100; }
.wh-badge-off { background:#eceff1; color:#546e7a; }
.wh-badge-err { background:#ffebee; color:#c62828; }
.location-card.unknown { border-color: #90a4ae; background: #eceff1; }

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
    const canEditStock = <?php echo (current_user_can('riverso_edit_stock') || current_user_can('manage_options')) ? 'true' : 'false'; ?>;
    const canEditLocations = <?php echo (current_user_can('riverso_edit_warehouse') || current_user_can('riverso_edit_stock') || current_user_can('manage_options')) ? 'true' : 'false'; ?>;
    const canCreatePrint = <?php echo (current_user_can('riverso_create_print_orders') || current_user_can('manage_options')) ? 'true' : 'false'; ?>;
    const canPrintLabels = <?php echo (current_user_can('riverso_print_orders') || current_user_can('riverso_print_labels') || current_user_can('manage_options')) ? 'true' : 'false'; ?>;
    const printOrdersUrl = <?php echo wp_json_encode(admin_url('admin.php?page=riverso-pos-print-orders')); ?>;
    let currentProductId = null;
    let locationsCache = [];
    let detailLoc = null;

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
        } else if (tab === 'stock-status') {
            loadStockStatus();
        } else if (tab === 'inventario' && typeof window.riversoWhLoadOpenCounts === 'function') {
            window.riversoWhLoadOpenCounts();
        }
    });

    // ========== UBICACIONES ==========
    function loadLocations(silent) {
        const estadoFilter = $('#filter-estado-ubicacion').val();
        if (silent !== true) {
            $('#locations-grid').html('<p style="padding: 40px; text-align: center; color: #666;"><span class="spinner is-active" style="float: none;"></span><br>Cargando ubicaciones...</p>');
        }
        
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
            const isUnknown = String(loc.codigo) === '?';
            const isActive = parseInt(loc.activo) === 1;
            const cardClass = (isUnknown ? 'unknown ' : '') + (isActive ? '' : 'inactive');
            const statusClass = isUnknown ? 'inactive' : (isActive ? 'active' : 'inactive');
            const statusText = isUnknown ? 'Desconocido' : (isActive ? 'Activa' : 'Desactivada');
            
            let actions = `
                <button type="button" class="button button-small btn-loc-inv" data-id="${loc.id}">Inventario actual</button>
                <button type="button" class="button button-small btn-loc-pref" data-id="${loc.id}">Preferidos</button>
            `;
            if (!isUnknown && canCreatePrint) {
                actions += `<button type="button" class="button button-small btn-loc-print" data-id="${loc.id}"><span class="dashicons dashicons-printer" style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span> Imprimir</button>`;
            }
            if (!isUnknown) {
                if (isActive) {
                    actions += `
                        <button class="button button-small btn-edit-location">Editar</button>
                        <button class="button button-small btn-deactivate-location">Desactivar</button>
                    `;
                } else {
                    actions += `
                        <button class="button button-small btn-activate-location" style="background: #4caf50; color: white; border-color: #4caf50;">Reactivar</button>
                        <button class="button button-small btn-delete-permanent" style="background: #d32f2f; color: white; border-color: #d32f2f;">Eliminar</button>
                    `;
                }
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
                    <div class="location-name">${loc.nombre || loc.codigo}</div>
                    <div class="location-products">
                        <span class="dashicons dashicons-archive"></span> ${loc.productos_count || 0} productos
                        · ${loc.preferidos_count || 0} preferidos
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

    function escHtml(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function showLocDetailSection(sec) {
        $('.loc-detail-sec').removeClass('button-primary');
        $('.loc-detail-sec[data-sec="' + sec + '"]').addClass('button-primary');
        $('#loc-detail-inv').toggle(sec === 'inv');
        $('#loc-detail-pref').toggle(sec === 'pref');
    }

    function renderLocOverview(data) {
        const inv = data.inventario || [];
        $('#loc-detail-inv-body').html(inv.length
            ? inv.map(function(p) {
                const when = (p.fecha_conteo || '') + (p.conteo_nombre ? ' · ' + p.conteo_nombre : '');
                return '<tr><td>' + escHtml(p.canonical_sku) + '</td><td>' + escHtml(p.nombre_canonico) + '</td><td>' +
                    escHtml(p.cantidad_contada) + '</td><td>' + escHtml(when) + '</td></tr>';
            }).join('')
            : '<tr><td colspan="4">Aún no hay stock en este lugar.</td></tr>');

        const pref = data.preferidos || [];
        if (!pref.length) {
            $('#loc-detail-pref-list').html('<p style="color:#666;margin:0;">Ningún producto prefiere este lugar todavía.</p>');
            return;
        }
        $('#loc-detail-pref-list').html(pref.map(function(p) {
            const star = parseInt(p.es_preferido, 10) ? '★ Principal' : '☆';
            let rowActions = '';
            if (canEditLocations) {
                if (!parseInt(p.es_preferido, 10)) {
                    rowActions += ' <button type="button" class="button button-small btn-pref-primary" data-pid="' + p.producto_base_id + '">Hacer principal</button>';
                }
                rowActions += ' <button type="button" class="button button-small btn-pref-remove" data-pid="' + p.producto_base_id + '">Quitar</button>';
            }
            return '<div class="loc-pref-row"><div><strong>' + escHtml(p.canonical_sku) + '</strong> ' +
                escHtml(p.nombre_canonico || '') + ' <span style="color:#666;">' + star + '</span></div><div>' +
                rowActions + '</div></div>';
        }).join(''));
    }

    function loadLocOverview() {
        if (!detailLoc) return;
        $.post(ajaxurl, {
            action: 'riverso_inventory_get_location_overview',
            nonce: nonce,
            id: detailLoc.id
        }, function(response) {
            if (!response.success) {
                const msg = (response.data && response.data.message) || 'Error';
                $('#loc-detail-inv-body').html('<tr><td colspan="4">' + escHtml(msg) + '</td></tr>');
                $('#loc-detail-pref-list').text(msg);
                return;
            }
            renderLocOverview(response.data || {});
        }).fail(function() {
            $('#loc-detail-inv-body').html('<tr><td colspan="4">Error de conexión</td></tr>');
        });
    }

    function openLocDetail(loc, sec) {
        detailLoc = loc;
        $('#loc-detail-title').text(loc.codigo + (loc.nombre ? ' · ' + loc.nombre : ''));
        const status = String(loc.codigo) === '?' ? 'Desconocido' : (parseInt(loc.activo, 10) === 1 ? 'Activa' : 'Desactivada');
        $('#loc-detail-meta').text(status + (loc.zona ? ' · ' + loc.zona : '') + (loc.barcode ? ' · BC ' + loc.barcode : ''));
        showLocDetailSection(sec || 'inv');
        $('#loc-detail-inv-body').html('<tr><td colspan="4">Cargando...</td></tr>');
        $('#loc-detail-pref-list').text('Cargando...');
        $('#loc-detail-pref-q').val('');
        $('#loc-detail-pref-sug').hide().empty();
        $('#modal-loc-detail').css('display', 'flex');
        loadLocOverview();
    }

    $(document).on('click', '.btn-loc-inv', function(e) {
        e.preventDefault();
        const loc = locationsCache.find(l => String(l.id) === String($(this).data('id')));
        if (loc) openLocDetail(loc, 'inv');
    });
    $(document).on('click', '.btn-loc-pref', function(e) {
        e.preventDefault();
        const loc = locationsCache.find(l => String(l.id) === String($(this).data('id')));
        if (loc) openLocDetail(loc, 'pref');
    });
    $(document).on('click', '.loc-detail-sec', function() {
        showLocDetailSection($(this).data('sec'));
    });

    let prefSearchTimer = null;
    $(document).on('input', '#loc-detail-pref-q', function() {
        const q = $(this).val();
        clearTimeout(prefSearchTimer);
        if (q.length < 2) {
            $('#loc-detail-pref-sug').hide().empty();
            return;
        }
        prefSearchTimer = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'riverso_inventory_search_products',
                nonce: nonce,
                q: q,
                solo_sku_local: 1
            }, function(response) {
                const list = (response.success && response.data.products) || [];
                if (!list.length) {
                    $('#loc-detail-pref-sug').hide().empty();
                    return;
                }
                $('#loc-detail-pref-sug').html(list.map(function(p) {
                    return '<div data-id="' + p.id + '"><strong>' + escHtml(p.canonical_sku) + '</strong> ' + escHtml(p.nombre_canonico) + '</div>';
                }).join('')).show();
            });
        }, 250);
    });

    $(document).on('click', '#loc-detail-pref-sug div', function() {
        if (!detailLoc) return;
        const pid = $(this).data('id');
        $('#loc-detail-pref-sug').hide().empty();
        $('#loc-detail-pref-q').val('');
        $.post(ajaxurl, {
            action: 'riverso_inventory_save_preferred_location',
            nonce: nonce,
            producto_base_id: pid,
            ubicacion_id: detailLoc.id,
            es_preferido: $('#loc-detail-pref-primary').is(':checked') ? 1 : 0
        }, function(response) {
            if (!response.success) {
                alert((response.data && response.data.message) || 'No se pudo asignar');
                return;
            }
            loadLocOverview();
            loadLocations(true);
        });
    });

    $(document).on('click', '.btn-pref-remove', function() {
        if (!detailLoc) return;
        $.post(ajaxurl, {
            action: 'riverso_inventory_remove_preferred_location',
            nonce: nonce,
            producto_base_id: $(this).data('pid'),
            ubicacion_id: detailLoc.id
        }, function(response) {
            if (!response.success) {
                alert((response.data && response.data.message) || 'No se pudo quitar');
                return;
            }
            loadLocOverview();
            loadLocations(true);
        });
    });

    $(document).on('click', '.btn-pref-primary', function() {
        if (!detailLoc) return;
        $.post(ajaxurl, {
            action: 'riverso_inventory_set_primary_location',
            nonce: nonce,
            producto_base_id: $(this).data('pid'),
            ubicacion_id: detailLoc.id
        }, function(response) {
            if (!response.success) {
                alert((response.data && response.data.message) || 'No se pudo marcar como principal');
                return;
            }
            loadLocOverview();
            loadLocations(true);
        });
    });

    // ========== IMPRIMIR ETIQUETA DE LUGAR ==========
    let printLoc = null;

    function locPrintItem() {
        const codigo = String(printLoc.codigo || '').trim();
        return {
            sku: String(printLoc.barcode || '').trim() || codigo,
            nombre: String(printLoc.nombre || '').trim() || codigo,
            copias: Math.max(1, parseInt($('#loc-print-copias').val(), 10) || 1),
            cantidad_ean: 1,
            modo: 'CodigoLugar',
            color: 'BN'
        };
    }

    function locPrintMsg(text) {
        $('#loc-print-msg').text(text || '').toggle(!!text);
    }

    function createLocOrder() {
        return $.post(ajaxurl, {
            action: 'riverso_print_orders_create',
            nonce: nonce,
            tipo: 'etiqueta_lugar',
            prioridad: 0,
            notas: 'Etiqueta de lugar ' + printLoc.codigo,
            items: JSON.stringify([locPrintItem()])
        });
    }

    $(document).on('click', '.btn-loc-print', function() {
        printLoc = locationsCache.find(l => String(l.id) === String($(this).data('id')));
        if (!printLoc) return;
        const item = locPrintItem();
        $('#loc-print-meta').text('Etiqueta: ' + item.nombre + ' · Código de barras: ' + item.sku);
        $('#loc-print-copias').val(1);
        $('#btn-loc-print-now').toggle(canPrintLabels);
        $('#loc-print-choose').show();
        $('#loc-print-orders').hide();
        locPrintMsg('');
        $('#modal-loc-print').css('display', 'flex');
    });

    async function labelPrinterReady() {
        if (typeof RiversoLabelPrint === 'undefined') return false;
        const healthy = await RiversoLabelPrint.checkAgent();
        if (!healthy) return false;
        await RiversoLabelPrint.loadPrinters();
        return RiversoLabelPrint.getPrinters().some(p => p.isBrother);
    }

    $('#btn-loc-print-now').on('click', async function() {
        if (!printLoc) return;
        const $btn = $(this).prop('disabled', true).text('Revisando impresora...');
        const reset = () => $btn.prop('disabled', false).text('Imprimir ya');
        let ready = false;
        try { ready = await labelPrinterReady(); } catch (e) { ready = false; }
        if (!ready) {
            reset();
            locPrintMsg('No hay impresora de etiquetas disponible en este computador. Agrega el lugar a una orden de impresión.');
            showLocPrintOrders();
            return;
        }
        $btn.text('Imprimiendo...');
        try {
            const res = await createLocOrder();
            if (!res.success) throw new Error((res.data && res.data.message) || 'No se pudo crear la orden');
            const order = res.data.order;
            const item = locPrintItem();
            const printer = RiversoLabelPrint.getPreferred() || '';
            await RiversoLabelPrint.print([{
                nombre: item.nombre,
                sku: item.sku,
                cantidad: 1,
                precio: null,
                copias: item.copias,
                modo: 'CodigoLugar',
                color: 'BN',
                ean13: null,
                printerName: printer || null
            }]);
            const mark = await $.post(ajaxurl, {
                action: 'riverso_print_orders_mark_printed',
                nonce: nonce,
                id: order.id,
                impresora_nombre: printer
            });
            reset();
            if (!mark.success) {
                alert('Se imprimió, pero no se pudo registrar: ' + ((mark.data && mark.data.message) || 'error'));
            }
            $('#modal-loc-print').hide();
        } catch (err) {
            reset();
            locPrintMsg(err && err.message ? err.message : 'Error de impresión');
        }
    });

    $('#btn-loc-print-queue').on('click', function() {
        locPrintMsg('');
        showLocPrintOrders();
    });

    function showLocPrintOrders() {
        $('#loc-print-choose').hide();
        $('#loc-print-orders').show();
        $('#loc-print-orders-list').text('Cargando...');
        $.post(ajaxurl, {
            action: 'riverso_print_orders_list',
            nonce: nonce,
            editable: 1,
            page: 1,
            per_page: 10
        }, function(res) {
            const orders = (res.success && res.data.items) || [];
            if (!orders.length) {
                $('#loc-print-orders-list').html('<p style="color:#666;margin:0;">No hay órdenes abiertas. Crea una nueva.</p>');
                return;
            }
            $('#loc-print-orders-list').html(orders.map(function(o, i) {
                const tag = i === 0 ? ' <span class="wh-badge wh-badge-ok">Última</span>' : '';
                return '<div class="loc-pref-row"><div><code>' + escHtml(o.numero_orden) + '</code>' + tag +
                    '<br><span style="color:#666;font-size:12px;">' + escHtml(o.estado_label) + ' · ' + escHtml(o.tipo_label) +
                    ' · ' + escHtml(o.total_items) + ' ítems · ' + escHtml(o.created_at || '') + '</span></div><div>' +
                    '<a class="button button-small" target="_blank" href="' + printOrdersUrl + '&order_id=' + o.id + '">Ver</a> ' +
                    '<button type="button" class="button button-small button-primary btn-loc-print-add" data-id="' + o.id + '">Agregar</button>' +
                    '</div></div>';
            }).join(''));
        }).fail(function() {
            $('#loc-print-orders-list').text('Error de conexión');
        });
    }

    $(document).on('click', '.btn-loc-print-add', function() {
        if (!printLoc) return;
        const id = $(this).data('id');
        const item = locPrintItem();
        $.post(ajaxurl, { action: 'riverso_print_orders_get', nonce: nonce, id: id }, function(res) {
            if (!res.success) { alert((res.data && res.data.message) || 'Error'); return; }
            const dup = (res.data.order.items || []).some(it => it.sku === item.sku && it.modo === 'CodigoLugar');
            if (dup && !confirm('Este lugar ya está en la orden ' + res.data.order.numero_orden + '. ¿Agregarlo de nuevo?')) return;
            $.post(ajaxurl, Object.assign({ action: 'riverso_print_orders_add_item', nonce: nonce, orden_id: id }, item), function(r) {
                if (!r.success) { alert((r.data && r.data.message) || 'No se pudo agregar'); return; }
                $('#modal-loc-print').hide();
                alert('Lugar agregado a la orden ' + r.data.order.numero_orden);
            });
        });
    });

    $('#btn-loc-print-new').on('click', function() {
        if (!printLoc) return;
        createLocOrder().done(function(res) {
            if (!res.success) { alert((res.data && res.data.message) || 'No se pudo crear la orden'); return; }
            $('#modal-loc-print').hide();
            alert('Orden ' + res.data.order.numero_orden + ' creada con este lugar');
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
                    <td>${m.canonical_sku ? (m.canonical_sku + ' · ') : ''}${m.nombre_canonico || m.product_id}</td>
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

    function stockInvBadge(v) {
        if (v === 'exacto') return '<span class="wh-badge wh-badge-ok">Exacto</span>';
        if (v === 'al_menos') return '<span class="wh-badge wh-badge-warn">Al menos</span>';
        return '<span class="wh-badge wh-badge-off">Desconocido</span>';
    }
    function stockConfBadge(v) {
        if (v === 'confiable') return '<span class="wh-badge wh-badge-ok">Confiable</span>';
        if (v === 'poco_confiable') return '<span class="wh-badge wh-badge-warn">Poco confiable</span>';
        if (v === 'dudoso') return '<span class="wh-badge wh-badge-err">Dudoso</span>';
        return '<span class="wh-badge wh-badge-off">' + (v || '') + '</span>';
    }

    function loadStockStatus() {
        const tbody = $('#stock-status-body');
        tbody.html('<tr><td colspan="9">Cargando...</td></tr>');
        $.post(ajaxurl, {
            action: 'riverso_stock_status_list',
            nonce: nonce,
            search: $('#stock-status-q').val(),
            estado_inventariado: $('#stock-status-inv').val(),
            estado_confianza: $('#stock-status-conf').val(),
            alerta: $('#stock-status-alerta').val(),
            page: 1,
            per_page: 50
        }, function(response) {
            if (!response.success) {
                tbody.html('<tr><td colspan="9">Error: ' + ((response.data && response.data.message) || 'No se pudo cargar') + '</td></tr>');
                return;
            }
            const items = response.data.items || [];
            if (!items.length) {
                tbody.html('<tr><td colspan="9">Sin resultados</td></tr>');
                return;
            }
            tbody.html(items.map(function(it) {
                const alerts = [];
                if (parseInt(it.alerta, 10) === 1) alerts.push('<span class="wh-badge wh-badge-warn">Alerta</span>');
                if (parseInt(it.critico, 10) === 1) alerts.push('<span class="wh-badge wh-badge-err">Crítico</span>');
                const bg = parseInt(it.critico, 10) === 1 ? 'background:#ffebee;' : (parseInt(it.alerta, 10) === 1 ? 'background:#fff8e1;' : '');
                const editBtn = canEditStock
                    ? '<button type="button" class="button button-small btn-stock-edit" data-id="' + it.id +
                      '" data-sku="' + String(it.canonical_sku || '').replace(/"/g, '&quot;') +
                      '" data-name="' + String(it.nombre_canonico || '').replace(/"/g, '&quot;') +
                      '" data-min="' + (it.stock_minimo == null ? '' : it.stock_minimo) +
                      '" data-crit="' + (it.stock_critico == null ? '' : it.stock_critico) + '">Editar límites</button>'
                    : '';
                return '<tr style="' + bg + '">' +
                    '<td><code>' + (it.canonical_sku || '') + '</code></td>' +
                    '<td>' + (it.nombre_canonico || '') + '</td>' +
                    '<td>' + it.stock_total + (alerts.length ? '<br>' + alerts.join(' ') : '') + '</td>' +
                    '<td>' + (it.stock_minimo == null ? '—' : it.stock_minimo) + '</td>' +
                    '<td>' + (it.stock_critico == null ? '—' : it.stock_critico) + '</td>' +
                    '<td>' + stockInvBadge(it.estado_inventariado) + '</td>' +
                    '<td>' + stockConfBadge(it.estado_confianza) + '</td>' +
                    '<td>' + (it.ultimo_conteo_fecha || '—') + '</td>' +
                    (canEditStock ? '<td>' + editBtn + '</td>' : '') +
                    '</tr>';
            }).join(''));

            const empBody = $('#stock-emp-body');
            const emps = response.data.emparejamientos || [];
            if (!emps.length) {
                empBody.html('<tr><td colspan="6">Sin emparejamientos de stock</td></tr>');
            } else {
                empBody.html(emps.map(function(e) {
                    const alerts = [];
                    if (parseInt(e.alerta, 10) === 1) alerts.push('<span class="wh-badge wh-badge-warn">Alerta</span>');
                    if (parseInt(e.critico, 10) === 1) alerts.push('<span class="wh-badge wh-badge-err">Crítico</span>');
                    const bg = parseInt(e.critico, 10) === 1 ? 'background:#ffebee;' : (parseInt(e.alerta, 10) === 1 ? 'background:#fff8e1;' : '');
                    const link = 'admin.php?page=riverso-pos-categories&tab=emparejamientos&emparejamiento_id=' + e.id;
                    return '<tr style="' + bg + '">' +
                        '<td><a href="' + link + '"><code>' + (e.codigo || '') + '</code></a></td>' +
                        '<td>' + (e.nombre || '') + '</td>' +
                        '<td>' + (e.stock_unidades != null ? e.stock_unidades : '—') + '</td>' +
                        '<td>' + (e.stock_minimo == null ? '—' : e.stock_minimo) + '</td>' +
                        '<td>' + (e.stock_critico == null ? '—' : e.stock_critico) + '</td>' +
                        '<td>' + (alerts.length ? alerts.join(' ') : '—') + '</td>' +
                        '</tr>';
                }).join(''));
            }
        }).fail(function() {
            tbody.html('<tr><td colspan="9">Error de conexión</td></tr>');
        });
    }

    $('#btn-stock-status-reload, #stock-status-inv, #stock-status-conf, #stock-status-alerta').on('click change', loadStockStatus);
    $('#stock-status-q').on('keyup', debounce(loadStockStatus, 300));

    function parseLimitVal(raw) {
        const s = String(raw == null ? '' : raw).trim();
        if (s === '') return null;
        const n = parseInt(s, 10);
        return isNaN(n) ? null : Math.max(0, n);
    }
    function showStockHint(msg) {
        const $h = $('#stock-limits-hint');
        if (!msg) { $h.hide().text(''); return; }
        $h.text(msg).show();
    }
    function syncStockLimits(changed) {
        const min = parseLimitVal($('#stock-limits-min').val());
        const crit = parseLimitVal($('#stock-limits-crit').val());
        if (min != null && crit != null && crit > min) {
            if (changed === 'critico') {
                $('#stock-limits-min').val(crit);
                showStockHint('El mínimo se igualó al crítico.');
            } else {
                $('#stock-limits-crit').val(min);
                showStockHint('El crítico se igualó al mínimo.');
            }
        } else {
            showStockHint('');
        }
    }
    let stockLastChanged = 'minimo';
    $(document).on('click', '.btn-stock-edit', function() {
        const $btn = $(this);
        $('#stock-limits-id').val($btn.data('id'));
        $('#stock-limits-prod').text(($btn.data('sku') || '') + ' · ' + ($btn.data('name') || ''));
        $('#stock-limits-min').val($btn.attr('data-min') || '');
        $('#stock-limits-crit').val($btn.attr('data-crit') || '');
        stockLastChanged = 'minimo';
        showStockHint('');
        $('#modal-stock-limits').show();
        $('#stock-limits-min').trigger('focus');
    });
    $('#stock-limits-min').on('input change', function() {
        stockLastChanged = 'minimo';
        syncStockLimits('minimo');
    });
    $('#stock-limits-crit').on('input change', function() {
        stockLastChanged = 'critico';
        syncStockLimits('critico');
    });
    $('#btn-save-stock-limits').on('click', function() {
        syncStockLimits(stockLastChanged);
        const min = parseLimitVal($('#stock-limits-min').val());
        const crit = parseLimitVal($('#stock-limits-crit').val());
        $.post(ajaxurl, {
            action: 'riverso_stock_status_save_config',
            nonce: nonce,
            producto_base_id: $('#stock-limits-id').val(),
            stock_minimo: min == null ? '' : min,
            stock_critico: crit == null ? '' : crit,
            last_changed: stockLastChanged
        }, function(response) {
            if (!response.success) {
                alert((response.data && response.data.message) || 'Error');
                return;
            }
            $('#modal-stock-limits').hide();
            loadStockStatus();
        });
    });

    // Cerrar modales
    $('.riverso-modal-close, #btn-cancel-location, #btn-cancel-movement, #btn-cancel-stock-limits, #btn-close-loc-detail, #btn-close-loc-print').on('click', function() {
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
