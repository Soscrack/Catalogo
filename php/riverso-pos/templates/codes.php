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
        <div class="stat-card confirm">
            <span class="stat-number" id="stat-por-confirmar">-</span>
            <span class="stat-label">Por confirmar</span>
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

        <div class="filters-bar">
            <div class="supplier-filter">
                <input type="text" id="pending-proveedor-search" placeholder="Filtrar por proveedor..." autocomplete="off">
                <div id="pending-proveedor-results" class="picker-results"></div>
                <input type="hidden" id="pending-proveedor">
            </div>
            <button type="button" class="button" id="btn-clear-pending-proveedor" style="display: none;">Quitar proveedor</button>
            <input type="text" id="search-pending" placeholder="Buscar código, descripción o folio...">
            <span id="pending-search-status" class="search-status"></span>
        </div>
        
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

        <div class="codes-pagination">
            <button type="button" class="button" id="pending-prev-page" disabled>&laquo; Anterior</button>
            <span id="pending-page-info"></span>
            <button type="button" class="button" id="pending-next-page" disabled>Siguiente &raquo;</button>
        </div>
    </div>

    <!-- Tab: Todos los códigos -->
    <div id="tab-todos" class="tab-content" style="display: none;">
        <div class="filters-bar">
            <div class="supplier-filter">
                <input type="text" id="filter-proveedor-search" placeholder="Filtrar por proveedor..." autocomplete="off">
                <div id="filter-proveedor-results" class="picker-results"></div>
                <input type="hidden" id="filter-proveedor">
            </div>
            <button type="button" class="button" id="btn-clear-proveedor" style="display: none;">Quitar proveedor</button>
            <select id="filter-estado">
                <option value="">Todos los estados</option>
                <option value="vinculado">Solo vinculados</option>
                <option value="pendiente">Solo pendientes</option>
                <option value="por_confirmar">Por confirmar</option>
            </select>
            <select id="filter-origen">
                <option value="">Todos los orígenes</option>
                <option value="catalogo">Catálogo</option>
                <option value="legacy">Legacy</option>
                <option value="manual">Manual</option>
                <option value="factura">Facturación</option>
            </select>
            <input type="text" id="search-codigo" placeholder="Buscar código, SKU o descripción...">
            <button type="button" class="button" id="btn-search-codes">Buscar</button>
            <span id="codes-search-status" class="search-status"></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 110px;">Código Prov.</th>
                    <th>Descripción Proveedor</th>
                    <th style="width: 100px;">SKU Local</th>
                    <th style="width: 120px;">Proveedor</th>
                    <th style="width: 90px;">Origen</th>
                    <th style="width: 95px;">Fecha ingreso</th>
                    <th style="width: 90px;">Estado</th>
                    <th style="width: 160px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="all-codes-list"></tbody>
        </table>
        
        <div class="codes-pagination">
            <button type="button" class="button" id="codes-prev-page" disabled>&laquo; Anterior</button>
            <span id="codes-page-info"></span>
            <button type="button" class="button" id="codes-next-page" disabled>Siguiente &raquo;</button>
        </div>
    </div>

    <!-- Tab: Proveedores -->
    <div id="tab-proveedores" class="tab-content" style="display: none;">
        <div class="filters-bar" style="margin-bottom: 15px;">
            <button type="button" class="button button-primary" id="btn-new-provider">
                <span class="dashicons dashicons-plus-alt"></span> Nuevo Proveedor
            </button>
            <input type="text" id="search-providers" placeholder="Buscar por nombre, RUT o apodo..." style="min-width: 260px;">
            <span id="providers-search-status" class="search-status"></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">RUT</th>
                    <th>Nombre</th>
                    <th style="width: 220px;">Apodos</th>
                    <th style="width: 150px;">Contacto</th>
                    <th style="width: 80px;">Códigos</th>
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

<!-- Modal: Editar código -->
<div id="modal-edit-code" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 600px;">
        <div class="riverso-modal-header">
            <h2>Editar Código de Proveedor</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <input type="hidden" id="edit-code-id">

            <div class="form-field">
                <label>Código Proveedor *</label>
                <input type="text" id="edit-code-codigo">
            </div>

            <div class="form-field">
                <label>Proveedor *</label>
                <input type="text" id="edit-code-proveedor-search" placeholder="Buscar proveedor por nombre o RUT..." autocomplete="off">
                <div id="edit-code-proveedor-results" class="picker-results"></div>
                <div id="edit-code-proveedor-selected" class="picked-hint"></div>
                <input type="hidden" id="edit-code-proveedor-id">
            </div>

            <div class="form-field">
                <label>Origen</label>
                <input type="text" id="edit-code-origen" readonly style="background:#f6f7f7;">
            </div>

            <div class="form-field">
                <label>Fecha de ingreso</label>
                <input type="text" id="edit-code-fecha" readonly style="background:#f6f7f7;">
            </div>

            <div class="form-field">
                <label>Descripción del proveedor</label>
                <input type="text" id="edit-code-descripcion">
            </div>

            <div class="form-field">
                <label>SKU Local vinculado</label>
                <input type="text" id="edit-code-sku-search" placeholder="Buscar por SKU o nombre..." autocomplete="off">
                <div id="edit-code-sku-results" class="picker-results"></div>
                <div id="edit-code-sku-selected" class="picked-hint"></div>
                <input type="hidden" id="edit-code-base-id">
                <button type="button" class="button button-small" id="edit-code-unlink-sku" style="margin-top: 6px;">
                    Desvincular SKU
                </button>
            </div>

            <div class="form-field">
                <label>Notas</label>
                <textarea id="edit-code-notas" rows="2"></textarea>
            </div>

            <div class="form-field">
                <label>
                    <input type="checkbox" id="edit-code-activo"> Código activo
                </label>
            </div>

            <div class="form-field">
                <label>Motivo del cambio</label>
                <textarea id="edit-code-audit" rows="2" placeholder="Queda registrado en auditoría"></textarea>
            </div>

            <div id="edit-code-confirm-box" class="edit-code-confirm-box" style="display:none;">
                <div class="edit-code-confirm-banner">
                    <strong>Por confirmar</strong>
                    <span id="edit-code-task-hint"></span>
                </div>
                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="button button-primary" id="edit-code-btn-confirm">Confirmar vínculo</button>
                    <button type="button" class="button" id="edit-code-btn-reject">Rechazar código</button>
                </div>
            </div>

            <div id="edit-code-error" class="edit-code-error"></div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button riverso-modal-close">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-code">Guardar</button>
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

.stat-card.confirm {
    border-color: #f0c36d;
    background: #fffbeb;
}

.stat-card.confirm .stat-number {
    color: #78350f;
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
    align-items: flex-start;
    flex-wrap: wrap;
}

.filters-bar input {
    min-width: 200px;
}

.supplier-filter {
    position: relative;
}

.picker-results {
    display: none;
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    min-width: 240px;
    background: #fff;
    border: 1px solid #ddd;
    max-height: 220px;
    overflow-y: auto;
    box-shadow: 0 2px 6px rgba(0, 0, 0, .12);
}

.picker-results .picker-item {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f1;
}

.picker-results .picker-item:hover {
    background: #f6f7f7;
}

.picker-results .picker-empty {
    padding: 10px;
    color: #8c8f94;
}

.picked-hint {
    margin-top: 4px;
    font-size: 12px;
    color: #007017;
}

.search-status {
    align-self: center;
    color: #8c8f94;
    font-size: 12px;
}

.codes-pagination {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 15px;
}

.codes-pagination span {
    color: #50575e;
    font-size: 13px;
}

.edit-code-error {
    display: none;
    margin-top: 10px;
    padding: 8px 10px;
    background: #fcf0f1;
    border-left: 4px solid #d63638;
}

.edit-code-confirm-box {
    margin-top: 14px;
    padding: 12px;
    background: #fff8e5;
    border: 1px solid #f0c36d;
    border-radius: 4px;
}

.edit-code-confirm-banner {
    color: #6e4e00;
    font-size: 13px;
    line-height: 1.4;
}

.edit-code-confirm-banner strong {
    display: inline-block;
    margin-right: 8px;
    background: #f0c36d;
    color: #3c2f00;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    text-transform: uppercase;
}

tr.code-row-por-confirmar {
    background: #fffbeb !important;
}

tr.code-row-por-confirmar td {
    border-top: 1px solid #f0c36d;
}

.badge-por-confirmar {
    display: inline-block;
    background: #f0c36d;
    color: #3c2f00;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}

.sku-pending-badge {
    display: inline-block;
    background: #fbbf24;
    color: #78350f;
    padding: 1px 6px;
    border-radius: 3px;
    font-weight: 600;
    font-size: 10px;
    letter-spacing: 0.02em;
}

#modal-edit-code .form-field textarea {
    width: 100%;
}

.apodo-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}

.apodo-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #e8f0fe;
    color: #1a73e8;
    border-radius: 12px;
    padding: 2px 8px;
    font-size: 12px;
}

.apodo-chip button {
    border: none;
    background: transparent;
    color: #1a73e8;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    font-size: 14px;
}

.apodo-input {
    min-width: 100px;
    max-width: 140px;
    padding: 2px 6px;
    font-size: 12px;
}

.picker-item .matched-apodo {
    display: block;
    font-size: 11px;
    color: #8c8f94;
    margin-top: 2px;
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

    function esc(v) { return $('<div>').text(v === null || v === undefined ? '' : v).html(); }
    // esc() no escapa comillas dobles, necesarias al interpolar en atributos.
    function escAttr(v) { return esc(v).replace(/"/g, '&quot;'); }

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
                $('#stat-por-confirmar').text(response.data.por_confirmar || 0);
                $('#stat-linked').text(response.data.linked || 0);
                $('#stat-providers').text(response.data.providers || 0);
            }
        });
    }

    // Cargar códigos pendientes
    let pendingPage = 1;
    let pendingTotalPages = 1;
    let pendingRequestId = 0;

    function loadPendingCodes(page) {
        pendingPage = Math.max(1, page || 1);
        const requestId = ++pendingRequestId;
        $('#pending-search-status').text('Buscando...');

        $.post(ajaxurl, {
            action: 'riverso_get_pending_codes',
            nonce: nonce,
            proveedor_id: $('#pending-proveedor').val(),
            search: $('#search-pending').val(),
            page: pendingPage,
            per_page: 50
        }, function(response) {
            if (requestId !== pendingRequestId) return;

            const tbody = $('#pending-codes-list');
            tbody.empty();

            if (!response.success) {
                $('#pending-search-status').text(response.data?.message || 'Error al buscar');
                tbody.html('<tr><td colspan="6" style="text-align: center;">No se pudo cargar el listado</td></tr>');
                updatePendingPagination(0, 0);
                return;
            }

            const items = response.data.items || [];
            const total = response.data.total || 0;
            pendingTotalPages = response.data.total_pages || 1;
            $('#pending-search-status').text(total + (total === 1 ? ' resultado' : ' resultados'));

            if (!items.length) {
                tbody.html('<tr><td colspan="6" style="text-align: center; padding: 40px;">✓ No hay códigos pendientes que coincidan</td></tr>');
                updatePendingPagination(0, 0);
                return;
            }

            items.forEach(function(item) {
                tbody.append(`
                    <tr data-item-id="${item.id}">
                        <td><code class="code-badge">${esc(item.codigo_proveedor || '-')}</code></td>
                        <td>${esc(item.descripcion)}</td>
                        <td>${esc(item.proveedor_nombre || '-')}</td>
                        <td>#${esc(item.folio)}</td>
                        <td>
                            <div class="link-input">
                                <input type="text" class="quick-sku-input" placeholder="SKU local">
                                <button class="button button-small btn-quick-link">OK</button>
                            </div>
                        </td>
                        <td>
                            <button class="button button-small btn-open-link-modal"
                                data-item="${item.id}"
                                data-codigo="${escAttr(item.codigo_proveedor || '')}"
                                data-descripcion="${escAttr(item.descripcion)}"
                                data-proveedor="${escAttr(item.proveedor_nombre || '')}">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                        </td>
                    </tr>
                `);
            });

            updatePendingPagination(pendingPage, pendingTotalPages);
        });
    }

    function updatePendingPagination(page, totalPages) {
        $('#pending-page-info').text(totalPages > 0 ? `Página ${page} de ${totalPages}` : '');
        $('#pending-prev-page').prop('disabled', page <= 1);
        $('#pending-next-page').prop('disabled', page >= totalPages);
    }

    $('#pending-prev-page').on('click', function() { loadPendingCodes(pendingPage - 1); });
    $('#pending-next-page').on('click', function() { loadPendingCodes(pendingPage + 1); });
    $('#search-pending').on('keyup', debounce(function() { loadPendingCodes(1); }, 300));

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
                            <div class="result-item" data-id="${p.id}" data-sku="${escAttr(p.sku)}" data-name="${escAttr(p.name)}">
                                <span>${esc(p.name)}</span>
                                <code>${esc(p.sku)}</code>
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
        function send(force) {
            $.post(ajaxurl, {
                action: 'riverso_link_code',
                nonce: nonce,
                item_id: itemId,
                sku_local: sku,
                crear_mapeo: createMapping ? 1 : 0,
                force: force ? 1 : 0
            }, function(response) {
                if (response.success) {
                    if (callback) callback();
                    return;
                }
                const data = response.data || {};
                if (data.conflict && !force) {
                    if (confirm((data.message || 'Conflicto de SKU') + '\n\n¿Reasignar de todas formas?')) {
                        send(true);
                    }
                    return;
                }
                alert(data.message || 'Error vinculando código');
            });
        }
        send(false);
    }

    // Cargar todos los códigos
    let codesPage = 1;
    let codesTotalPages = 1;
    let codesRequestId = 0;

    function loadAllCodes(page) {
        codesPage = Math.max(1, page || 1);
        const requestId = ++codesRequestId;
        $('#codes-search-status').text('Buscando...');

        $.post(ajaxurl, {
            action: 'riverso_get_all_codes',
            nonce: nonce,
            proveedor_id: $('#filter-proveedor').val(),
            estado: $('#filter-estado').val(),
            origen: $('#filter-origen').val(),
            search: $('#search-codigo').val(),
            page: codesPage,
            per_page: 50
        }, function(response) {
            // Ignorar respuestas de búsquedas ya superadas.
            if (requestId !== codesRequestId) return;

            const tbody = $('#all-codes-list');
            tbody.empty();

            if (!response.success) {
                $('#codes-search-status').text(response.data?.message || 'Error al buscar');
                tbody.html('<tr><td colspan="8" style="text-align: center;">No se pudo cargar el listado</td></tr>');
                updateCodesPagination(0, 0);
                return;
            }

            const codes = response.data.codes || [];
            const total = response.data.total || 0;
            codesTotalPages = response.data.total_pages || 1;

            $('#codes-search-status').text(total + (total === 1 ? ' resultado' : ' resultados'));

            if (!codes.length) {
                tbody.html('<tr><td colspan="8" style="text-align: center;">No hay códigos que coincidan</td></tr>');
                updateCodesPagination(0, 0);
                return;
            }

            codes.forEach(function(code) {
                const vinculado = !!code.producto_base_id;
                const needsConfirm = !!code.needs_confirm;
                let estadoHtml = vinculado
                    ? '<span class="status-activo">Vinculado</span>'
                    : '<span class="status-inactivo">Pendiente</span>';
                if (needsConfirm) {
                    estadoHtml = '<span class="badge-por-confirmar">Por confirmar</span>';
                }
                const confirmBtns = needsConfirm
                    ? `<button class="button button-small button-primary btn-confirm-code" data-id="${code.id}" title="Confirmar">Confirmar</button>
                       <button class="button button-small btn-reject-code" data-id="${code.id}" title="Rechazar">Rechazar</button>`
                    : '';
                const rowClass = needsConfirm ? 'code-row-por-confirmar' : '';
                let skuHtml = `<strong>${esc(code.sku_local || '-')}</strong>`;
                if (needsConfirm && code.sku_local) {
                    skuHtml = `<span class="sku-pending-badge">POR CONFIRMAR</span><br><small><strong>${esc(code.sku_local)}</strong></small>`;
                } else if (needsConfirm) {
                    skuHtml = `<span class="sku-pending-badge">POR CONFIRMAR</span>`;
                }
                tbody.append(`
                    <tr class="${rowClass}">
                        <td><code>${esc(code.codigo_proveedor)}</code></td>
                        <td>${esc(code.descripcion_proveedor || '-')}</td>
                        <td>${skuHtml}</td>
                        <td>${esc(code.proveedor_nombre || '-')}</td>
                        <td><small>${esc(code.origen_label || code.origen_datos || '-')}</small></td>
                        <td>${code.fecha_ingreso ? esc(String(code.fecha_ingreso).split(' ')[0]) : '-'}</td>
                        <td>${estadoHtml}</td>
                        <td style="white-space:nowrap;">
                            ${confirmBtns}
                            <button class="button button-small btn-edit-code" data-id="${code.id}">Editar</button>
                        </td>
                    </tr>
                `);
            });

            updateCodesPagination(codesPage, codesTotalPages);
        });
    }

    function updateCodesPagination(page, totalPages) {
        $('#codes-page-info').text(totalPages > 0 ? `Página ${page} de ${totalPages}` : '');
        $('#codes-prev-page').prop('disabled', page <= 1);
        $('#codes-next-page').prop('disabled', page >= totalPages);
    }

    $('#btn-search-codes').on('click', function() { loadAllCodes(1); });
    $('#codes-prev-page').on('click', function() { loadAllCodes(codesPage - 1); });
    $('#codes-next-page').on('click', function() { loadAllCodes(codesPage + 1); });
    $('#filter-estado').on('change', function() { loadAllCodes(1); });
    $('#filter-origen').on('change', function() { loadAllCodes(1); });

    $(document).on('click', '.btn-confirm-code', function() {
        const id = $(this).data('id');
        if (!confirm('¿Confirmar este vínculo de código a SKU local?')) return;
        $.post(ajaxurl, {
            action: 'riverso_codes_confirm',
            nonce: nonce,
            pp_id: id
        }, function(r) {
            if (!r.success) {
                alert(r.data?.message || 'Error al confirmar');
                return;
            }
            loadAllCodes(codesPage);
        });
    });

    $(document).on('click', '.btn-reject-code', function() {
        const id = $(this).data('id');
        if (!confirm('¿Rechazar este código? Quedará inactivo.')) return;
        $.post(ajaxurl, {
            action: 'riverso_codes_reject',
            nonce: nonce,
            pp_id: id
        }, function(r) {
            if (!r.success) {
                alert(r.data?.message || 'Error al rechazar');
                return;
            }
            loadAllCodes(codesPage);
        });
    });

    // Búsqueda al escribir
    $('#search-codigo').on('keyup', debounce(function() {
        loadAllCodes(1);
    }, 300));

    // Filtro de proveedor con autocompletado
    function bindSupplierPicker(searchSel, resultsSel, hiddenSel, onPick) {
        let timer = null;

        $(searchSel).on('input', function() {
            clearTimeout(timer);
            const q = $(this).val().trim();
            $(hiddenSel).val('');
            if (typeof onPick === 'function') onPick(null);

            if (q.length < 2) {
                $(resultsSel).empty().hide();
                return;
            }

            timer = setTimeout(function() {
                $.post(ajaxurl, {
                    action: 'riverso_search_suppliers',
                    nonce: nonce,
                    search: q,
                    limit: 15
                }, function(response) {
                    if (!response.success) return;
                    const items = response.data.suppliers || [];
                    const html = items.length
                        ? items.map(s => {
                            const apodoHint = s.matched_apodo
                                ? `<span class="matched-apodo">Apodo: ${esc(s.matched_apodo)}</span>`
                                : '';
                            return `<div class="picker-item" data-id="${s.id}" data-nombre="${escAttr(s.nombre)}">
                                <strong>${esc(s.nombre)}</strong> <span style="color:#666;">${esc(s.rut || '')}</span>
                                ${apodoHint}
                            </div>`;
                        }).join('')
                        : '<div class="picker-empty">Sin proveedores para esa búsqueda.</div>';
                    $(resultsSel).html(html).show();
                });
            }, 300);
        });

        $(document).on('click', resultsSel + ' .picker-item', function() {
            const id = $(this).data('id');
            const nombre = String($(this).data('nombre') || '');
            $(hiddenSel).val(id);
            $(searchSel).val(nombre);
            $(resultsSel).empty().hide();
            if (typeof onPick === 'function') onPick({id: id, nombre: nombre});
        });
    }

    bindSupplierPicker('#filter-proveedor-search', '#filter-proveedor-results', '#filter-proveedor', function(picked) {
        $('#btn-clear-proveedor').toggle(!!picked);
        loadAllCodes(1);
    });

    $('#btn-clear-proveedor').on('click', function() {
        $('#filter-proveedor').val('');
        $('#filter-proveedor-search').val('');
        $(this).hide();
        loadAllCodes(1);
    });

    bindSupplierPicker('#pending-proveedor-search', '#pending-proveedor-results', '#pending-proveedor', function(picked) {
        $('#btn-clear-pending-proveedor').toggle(!!picked);
        loadPendingCodes(1);
    });

    $('#btn-clear-pending-proveedor').on('click', function() {
        $('#pending-proveedor').val('');
        $('#pending-proveedor-search').val('');
        $(this).hide();
        loadPendingCodes(1);
    });

    // --- Modal de edición de código ---

    let editSkuTimer = null;

    function setEditCodeSku(baseId, sku, nombre) {
        $('#edit-code-base-id').val(baseId || '');
        $('#edit-code-sku-selected').text(
            baseId ? 'Vinculado a ' + sku + (nombre ? ' — ' + nombre : '') : 'Sin SKU local vinculado'
        );
        $('#edit-code-unlink-sku').toggle(!!baseId);
    }

    $(document).on('click', '.btn-edit-code', function() {
        const id = $(this).data('id');

        $('#edit-code-error').hide().empty();
        $('#edit-code-sku-search').val('');
        $('#edit-code-sku-results').empty().hide();
        $('#edit-code-proveedor-results').empty().hide();
        $('#edit-code-audit').val('');

        $.post(ajaxurl, {action: 'riverso_codes_get', nonce: nonce, pp_id: id}, function(response) {
            if (!response.success) {
                alert(response.data?.message || 'No se pudo cargar el código');
                return;
            }

            const code = response.data.code;
            $('#edit-code-id').val(code.id);
            $('#edit-code-codigo').val(code.codigo_proveedor || '');
            $('#edit-code-descripcion').val(code.nombre_proveedor || '');
            $('#edit-code-origen').val(code.origen_label || code.origen_datos || '-');
            $('#edit-code-fecha').val(code.fecha_ingreso || code.created_at || '-');
            $('#edit-code-notas').val(code.notas || '');
            $('#edit-code-activo').prop('checked', parseInt(code.activo, 10) === 1);
            $('#edit-code-proveedor-id').val(code.proveedor_id || '');
            $('#edit-code-proveedor-search').val(code.proveedor_nombre || '');
            $('#edit-code-proveedor-selected').text(
                code.proveedor_nombre ? 'Proveedor: ' + code.proveedor_nombre : 'Sin proveedor asignado'
            );
            setEditCodeSku(code.producto_base_id, code.canonical_sku, code.nombre_canonico);

            const needsConfirm = !!code.needs_confirm;
            $('#edit-code-confirm-box').toggle(needsConfirm);
            if (needsConfirm) {
                let hint = 'Este vínculo viene de catálogo/legacy y requiere revisión humana.';
                if (code.has_open_task && code.open_task) {
                    hint = 'Hay una tarea abierta: «' + (code.open_task.titulo || 'Confirmar código proveedor') + '» (#' + code.open_task.id + ').';
                } else if (code.has_open_task) {
                    hint = 'Hay una tarea de confirmación asignada a este código.';
                }
                $('#edit-code-task-hint').text(hint);
            }

            $('#modal-edit-code').show();
        });
    });

    function closeEditAfterReview() {
        $('#modal-edit-code').hide();
        loadAllCodes(codesPage);
    }

    $('#edit-code-btn-confirm').on('click', function() {
        const id = $('#edit-code-id').val();
        if (!id || !confirm('¿Confirmar este vínculo de código a SKU local?')) return;
        $.post(ajaxurl, {
            action: 'riverso_codes_confirm',
            nonce: nonce,
            pp_id: id,
            audit_reason: $('#edit-code-audit').val()
        }, function(r) {
            if (!r.success) {
                alert(r.data?.message || 'Error al confirmar');
                return;
            }
            closeEditAfterReview();
        });
    });

    $('#edit-code-btn-reject').on('click', function() {
        const id = $('#edit-code-id').val();
        if (!id || !confirm('¿Rechazar este código? Quedará inactivo.')) return;
        $.post(ajaxurl, {
            action: 'riverso_codes_reject',
            nonce: nonce,
            pp_id: id,
            audit_reason: $('#edit-code-audit').val()
        }, function(r) {
            if (!r.success) {
                alert(r.data?.message || 'Error al rechazar');
                return;
            }
            closeEditAfterReview();
        });
    });

    bindSupplierPicker('#edit-code-proveedor-search', '#edit-code-proveedor-results', '#edit-code-proveedor-id', function(picked) {
        $('#edit-code-proveedor-selected').text(picked ? 'Proveedor: ' + picked.nombre : 'Selecciona un proveedor');
    });

    $('#edit-code-sku-search').on('input', function() {
        clearTimeout(editSkuTimer);
        const q = $(this).val().trim();

        if (q.length < 2) {
            $('#edit-code-sku-results').empty().hide();
            return;
        }

        editSkuTimer = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'riverso_search_sku_catalog',
                nonce: nonce,
                search: q
            }, function(response) {
                if (!response.success) return;
                const items = response.data.products || [];
                const html = items.length
                    ? items.map(p => `<div class="picker-item edit-sku-pick"
                            data-id="${p.id}"
                            data-sku="${escAttr(p.canonical_sku)}"
                            data-nombre="${escAttr(p.nombre_canonico || '')}">
                            <strong>${esc(p.canonical_sku)}</strong><br>
                            <small style="color:#666;">${esc(p.nombre_canonico || '')}</small>
                        </div>`).join('')
                    : '<div class="picker-empty">Sin SKU que coincidan.</div>';
                $('#edit-code-sku-results').html(html).show();
            });
        }, 300);
    });

    $(document).on('click', '.edit-sku-pick', function() {
        setEditCodeSku(
            $(this).data('id'),
            String($(this).data('sku') || ''),
            String($(this).data('nombre') || '')
        );
        $('#edit-code-sku-search').val('');
        $('#edit-code-sku-results').empty().hide();
    });

    $('#edit-code-unlink-sku').on('click', function() {
        setEditCodeSku(0, '', '');
    });

    $('#btn-save-code').on('click', function() {
        const button = $(this);
        $('#edit-code-error').hide().empty();

        const codigo = $('#edit-code-codigo').val().trim();
        const proveedorId = $('#edit-code-proveedor-id').val();

        if (!codigo) {
            $('#edit-code-error').text('El código no puede quedar vacío').show();
            return;
        }
        if (!proveedorId) {
            $('#edit-code-error').text('Selecciona un proveedor').show();
            return;
        }

        button.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'riverso_codes_update',
            nonce: nonce,
            pp_id: $('#edit-code-id').val(),
            codigo_proveedor: codigo,
            proveedor_id: proveedorId,
            nombre_proveedor: $('#edit-code-descripcion').val(),
            notas: $('#edit-code-notas').val(),
            activo: $('#edit-code-activo').is(':checked') ? 1 : 0,
            producto_base_id: $('#edit-code-base-id').val() || 0,
            audit_reason: $('#edit-code-audit').val()
        }, function(response) {
            button.prop('disabled', false);
            if (!response.success) {
                $('#edit-code-error').text(response.data?.message || 'No se pudo guardar').show();
                return;
            }
            $('#modal-edit-code').hide();
            loadAllCodes(codesPage);
            loadStats();
        }).fail(function() {
            button.prop('disabled', false);
            $('#edit-code-error').text('Error de conexión al guardar').show();
        });
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.supplier-filter, #filter-proveedor-results, #pending-proveedor-results').length) {
            $('#filter-proveedor-results, #pending-proveedor-results').hide();
        }
        if (!$(e.target).closest('#edit-code-proveedor-search, #edit-code-proveedor-results').length) {
            $('#edit-code-proveedor-results').hide();
        }
        if (!$(e.target).closest('#edit-code-sku-search, #edit-code-sku-results').length) {
            $('#edit-code-sku-results').hide();
        }
    });

    // Cargar proveedores
    function renderApodosCell(providerId, apodos) {
        const chips = (apodos || []).map(a => `
            <span class="apodo-chip">
                ${esc(a)}
                <button type="button" class="btn-remove-apodo" data-id="${providerId}" data-apodo="${escAttr(a)}" title="Quitar">&times;</button>
            </span>`).join('');
        return `<div class="apodo-chips" data-provider-id="${providerId}">
            ${chips}
            <input type="text" class="apodo-input" data-id="${providerId}" placeholder="+ apodo" autocomplete="off">
        </div>`;
    }

    function loadProviders() {
        const search = $('#search-providers').val() || '';
        $('#providers-search-status').text('Buscando...');

        $.post(ajaxurl, {
            action: 'riverso_get_providers',
            nonce: nonce,
            search: search
        }, function(response) {
            const tbody = $('#providers-list');
            tbody.empty();

            if (!response.success || !response.data.providers.length) {
                $('#providers-search-status').text('0 resultados');
                tbody.html('<tr><td colspan="7" style="text-align: center;">No hay proveedores</td></tr>');
                return;
            }

            const providers = response.data.providers;
            $('#providers-search-status').text(providers.length + (providers.length === 1 ? ' resultado' : ' resultados'));

            providers.forEach(function(p) {
                tbody.append(`
                    <tr data-provider-id="${p.id}">
                        <td>${esc(p.rut)}</td>
                        <td><strong>${esc(p.nombre)}</strong></td>
                        <td>${renderApodosCell(p.id, p.apodos || [])}</td>
                        <td>${esc(p.contacto || p.email || '-')}</td>
                        <td>${p.codigos_count || 0}</td>
                        <td><span class="status-${p.activo ? 'activo' : 'inactivo'}">${p.activo ? 'Activo' : 'Inactivo'}</span></td>
                        <td>
                            <button class="button button-small btn-edit-provider" data-id="${p.id}">Editar</button>
                        </td>
                    </tr>
                `);
            });
        });
    }

    $('#search-providers').on('keyup', debounce(function() {
        loadProviders();
    }, 300));

    function addProviderApodo(providerId, apodo, input) {
        apodo = String(apodo || '').trim();
        if (!apodo) return;

        $.post(ajaxurl, {
            action: 'riverso_add_provider_alias',
            nonce: nonce,
            proveedor_id: providerId,
            apodo: apodo
        }, function(response) {
            if (!response.success) {
                alert(response.data?.message || 'No se pudo agregar el apodo');
                return;
            }
            const cell = input.closest('td');
            cell.html(renderApodosCell(providerId, response.data.apodos || []));
            cell.find('.apodo-input').trigger('focus');
        });
    }

    $(document).on('keydown', '.apodo-input', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addProviderApodo($(this).data('id'), $(this).val().replace(/,/g, ''), $(this));
        }
    });

    $(document).on('blur', '.apodo-input', function() {
        const val = $(this).val().trim();
        if (val) {
            addProviderApodo($(this).data('id'), val, $(this));
        }
    });

    $(document).on('click', '.btn-remove-apodo', function() {
        const providerId = $(this).data('id');
        const apodo = String($(this).data('apodo') || '');
        const cell = $(this).closest('td');

        $.post(ajaxurl, {
            action: 'riverso_remove_provider_alias',
            nonce: nonce,
            proveedor_id: providerId,
            apodo: apodo
        }, function(response) {
            if (!response.success) {
                alert(response.data?.message || 'No se pudo eliminar el apodo');
                return;
            }
            cell.html(renderApodosCell(providerId, response.data.apodos || []));
        });
    });

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
