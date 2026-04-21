<?php
/**
 * Template: Gestión de Códigos de Barra
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap riverso-barcodes">
    <h1>
        <span class="dashicons dashicons-barcode"></span>
        Códigos de Barra
    </h1>

    <!-- Stats -->
    <div class="barcode-stats">
        <div class="stat-card">
            <span class="stat-number" id="stat-total">-</span>
            <span class="stat-label">Total Códigos</span>
        </div>
        <div class="stat-card success">
            <span class="stat-number" id="stat-linked">-</span>
            <span class="stat-label">Vinculados</span>
        </div>
        <div class="stat-card warning">
            <span class="stat-number" id="stat-unlinked">-</span>
            <span class="stat-label">Sin Vincular</span>
        </div>
        <div class="stat-card">
            <span class="stat-number" id="stat-recent">-</span>
            <span class="stat-label">Últimos 7 días</span>
        </div>
    </div>

    <!-- Scanner Section -->
    <div class="scanner-section">
        <h3><span class="dashicons dashicons-screenoptions"></span> Escáner Rápido</h3>
        <div class="scanner-input">
            <input type="text" id="scanner-input" placeholder="Escanea o escribe código de barra..." autofocus>
            <button type="button" class="button button-primary" id="btn-search-scan">
                <span class="dashicons dashicons-search"></span> Buscar
            </button>
        </div>
        <div id="scanner-result" style="display: none;">
            <!-- Results appear here -->
        </div>
    </div>

    <!-- Tabs -->
    <div class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="todos">Todos los Códigos</a>
        <a href="#" class="nav-tab" data-tab="sin-vincular">Sin Vincular</a>
        <a href="#" class="nav-tab" data-tab="importar">Importar</a>
    </div>

    <!-- Tab: Todos -->
    <div id="tab-todos" class="tab-content">
        <div class="toolbar">
            <div class="filters">
                <select id="filter-status">
                    <option value="all">Todos</option>
                    <option value="linked">Vinculados</option>
                    <option value="unlinked">Sin vincular</option>
                </select>
                <input type="text" id="search-barcode" placeholder="Buscar código o SKU...">
                <button type="button" class="button" id="btn-search">Buscar</button>
            </div>
            <button type="button" class="button button-primary" id="btn-add-barcode">
                <span class="dashicons dashicons-plus-alt"></span> Agregar Código
            </button>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Código de Barra</th>
                    <th style="width: 120px;">SKU</th>
                    <th>Producto</th>
                    <th style="width: 100px;">Tipo</th>
                    <th style="width: 100px;">Fuente</th>
                    <th style="width: 120px;">Fecha</th>
                    <th style="width: 100px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="barcodes-list"></tbody>
        </table>
        
        <div class="pagination-wrap">
            <span id="pagination-info"></span>
            <div class="pagination-buttons">
                <button type="button" class="button" id="btn-prev" disabled>← Anterior</button>
                <button type="button" class="button" id="btn-next">Siguiente →</button>
            </div>
        </div>
    </div>

    <!-- Tab: Sin vincular -->
    <div id="tab-sin-vincular" class="tab-content" style="display: none;">
        <p class="description">
            Códigos de barra importados que no están vinculados a ningún producto. 
            Asigna un producto a cada código.
        </p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Código de Barra</th>
                    <th style="width: 120px;">SKU Sugerido</th>
                    <th style="width: 100px;">Tipo</th>
                    <th>Buscar Producto</th>
                    <th style="width: 100px;">Acción</th>
                </tr>
            </thead>
            <tbody id="unlinked-list"></tbody>
        </table>
    </div>

    <!-- Tab: Importar -->
    <div id="tab-importar" class="tab-content" style="display: none;">
        <div class="import-section">
            <h3>Importar desde CSV</h3>
            <p class="description">
                Sube un archivo CSV con columnas: <code>sku</code>, <code>barcode</code><br>
                Separador: punto y coma (;) o coma (,)
            </p>
            
            <div class="import-form">
                <input type="file" id="import-file" accept=".csv">
                <button type="button" class="button" id="btn-preview-import">Vista Previa</button>
            </div>
            
            <div id="import-preview" style="display: none;">
                <h4>Vista Previa (primeras 20 filas)</h4>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Código de Barra</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="preview-list"></tbody>
                </table>
                <p id="preview-summary"></p>
                <button type="button" class="button button-primary button-large" id="btn-confirm-import">
                    Confirmar Importación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Agregar Código -->
<div id="modal-add-barcode" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 500px;">
        <div class="riverso-modal-header">
            <h2>Agregar Código de Barra</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-add-barcode">
                <div class="form-field">
                    <label>Código de Barra *</label>
                    <input type="text" id="new-barcode" name="barcode" required placeholder="Ej: 7801234567890">
                    <span id="barcode-validation"></span>
                </div>
                
                <div class="form-field">
                    <label>Buscar Producto</label>
                    <input type="text" id="search-product" placeholder="Buscar por SKU o nombre...">
                    <div id="product-search-results"></div>
                </div>
                
                <div id="selected-product-info" style="display: none;">
                    <div class="selected-product">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span id="selected-product-name"></span>
                        <code id="selected-product-sku"></code>
                        <button type="button" class="button button-small" id="btn-clear-product">×</button>
                    </div>
                </div>
                
                <input type="hidden" id="new-product-id">
                <input type="hidden" id="new-variation-id">
                
                <div class="form-field">
                    <label>Tipo de Código</label>
                    <select id="new-barcode-type" name="barcode_type">
                        <option value="EAN13">EAN-13</option>
                        <option value="EAN8">EAN-8</option>
                        <option value="UPC">UPC-A</option>
                        <option value="CODE128">Code 128</option>
                        <option value="INTERNAL">Interno</option>
                    </select>
                </div>
                
                <div class="form-field">
                    <label>
                        <input type="checkbox" id="new-is-primary" checked>
                        Código principal del producto
                    </label>
                </div>
                
                <div class="form-field">
                    <label>Notas</label>
                    <textarea id="new-notes" name="notes" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-add">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-save-barcode">Guardar</button>
        </div>
    </div>
</div>

<!-- Modal: Producto encontrado por escáner -->
<div id="modal-scan-result" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 500px;">
        <div class="riverso-modal-header">
            <h2>Producto Encontrado</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div id="scan-product-details">
                <!-- Product details appear here -->
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-scan">Cerrar</button>
            <button type="button" class="button button-primary" id="btn-add-to-cart" style="display: none;">
                <span class="dashicons dashicons-cart"></span> Agregar a Venta
            </button>
        </div>
    </div>
</div>

<style>
.barcode-stats {
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
    min-width: 130px;
}

.stat-card.success {
    border-color: #4caf50;
    background: #e8f5e9;
}

.stat-card.warning {
    border-color: #ff9800;
    background: #fff8e1;
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

.scanner-section {
    background: #f0f7ff;
    border: 2px solid #2196f3;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.scanner-section h3 {
    margin: 0 0 15px 0;
    color: #1565c0;
}

.scanner-input {
    display: flex;
    gap: 10px;
}

.scanner-input input {
    flex: 1;
    font-size: 18px;
    padding: 12px 15px;
    border: 2px solid #2196f3;
    border-radius: 6px;
}

.scanner-input input:focus {
    outline: none;
    border-color: #1565c0;
    box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.2);
}

.scanner-input button {
    padding: 12px 25px;
    font-size: 16px;
}

#scanner-result {
    margin-top: 15px;
    padding: 15px;
    background: white;
    border-radius: 6px;
}

.scan-found {
    display: flex;
    align-items: center;
    gap: 15px;
}

.scan-found img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.scan-found .product-info h4 {
    margin: 0 0 5px 0;
}

.scan-found .product-info .sku {
    font-family: monospace;
    background: #f5f5f5;
    padding: 2px 8px;
    border-radius: 3px;
}

.scan-found .product-info .price {
    font-size: 20px;
    font-weight: 700;
    color: #4caf50;
    margin-top: 5px;
}

.scan-not-found {
    color: #f44336;
    display: flex;
    align-items: center;
    gap: 10px;
}

.scan-not-found .dashicons {
    font-size: 24px;
}

.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.filters {
    display: flex;
    gap: 10px;
}

.filters input {
    min-width: 200px;
}

.tab-content {
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    padding: 20px;
}

.pagination-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.pagination-buttons {
    display: flex;
    gap: 10px;
}

.import-section {
    max-width: 600px;
}

.import-form {
    display: flex;
    gap: 15px;
    align-items: center;
    margin: 20px 0;
}

#import-preview {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

.form-field {
    margin-bottom: 15px;
}

.form-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.form-field input[type="text"],
.form-field select,
.form-field textarea {
    width: 100%;
}

#product-search-results {
    border: 1px solid #ddd;
    border-radius: 4px;
    max-height: 200px;
    overflow-y: auto;
    margin-top: 5px;
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

.selected-product {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #e8f5e9;
    border-radius: 4px;
    margin-top: 10px;
}

.selected-product .dashicons {
    color: #4caf50;
}

#barcode-validation {
    font-size: 12px;
    margin-top: 3px;
    display: block;
}

#barcode-validation.valid {
    color: #4caf50;
}

#barcode-validation.invalid {
    color: #f44336;
}

.status-linked { color: #4caf50; }
.status-unlinked { color: #ff9800; }

.barcode-code {
    font-family: monospace;
    font-size: 14px;
    background: #f5f5f5;
    padding: 3px 8px;
    border-radius: 3px;
}

.quick-assign {
    display: flex;
    gap: 5px;
}

.quick-assign input {
    width: 120px;
    padding: 4px 8px;
    font-size: 12px;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let currentPage = 1;
    let totalPages = 1;
    let importData = [];

    // Load stats
    function loadStats() {
        $.post(ajaxurl, {action: 'riverso_get_barcode_stats', nonce: nonce}, function(response) {
            if (response.success) {
                $('#stat-total').text(response.data.total || 0);
                $('#stat-linked').text(response.data.linked || 0);
                $('#stat-unlinked').text(response.data.unlinked || 0);
                $('#stat-recent').text(response.data.recent || 0);
            }
        });
    }

    // Tabs
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').hide();
        $('#tab-' + $(this).data('tab')).show();
        
        if ($(this).data('tab') === 'sin-vincular') loadUnlinked();
    });

    // Scanner
    $('#scanner-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            searchBarcode($(this).val());
        }
    });

    $('#btn-search-scan').on('click', function() {
        searchBarcode($('#scanner-input').val());
    });

    function searchBarcode(barcode) {
        if (!barcode) return;
        
        $.post(ajaxurl, {
            action: 'riverso_search_barcode',
            nonce: nonce,
            barcode: barcode
        }, function(response) {
            const result = $('#scanner-result');
            result.show();
            
            if (response.success) {
                const p = response.data.product;
                let html = '<div class="scan-found">';
                if (p.image) {
                    html += `<img src="${p.image}" alt="">`;
                }
                html += '<div class="product-info">';
                html += `<h4>${p.name}</h4>`;
                html += `<code class="sku">${p.sku}</code>`;
                if (p.price) {
                    html += `<div class="price">$${parseFloat(p.price).toLocaleString('es-CL')}</div>`;
                }
                if (p.stock !== null) {
                    html += `<div>Stock: ${p.stock}</div>`;
                }
                html += '</div></div>';
                result.html(html);
                
                // Play success sound (optional)
                // new Audio('/wp-content/plugins/riverso-pos/assets/sounds/beep.mp3').play();
            } else {
                result.html(`
                    <div class="scan-not-found">
                        <span class="dashicons dashicons-warning"></span>
                        <div>
                            <strong>Código no encontrado:</strong> ${barcode}<br>
                            <button class="button button-small" id="btn-create-barcode-task">
                                Crear tarea para asignar producto
                            </button>
                        </div>
                    </div>
                `);
            }
            
            // Clear input for next scan
            $('#scanner-input').val('').focus();
        });
    }

    // Create task for unassigned barcode
    $(document).on('click', '#btn-create-barcode-task', function() {
        const barcode = $('#scanner-input').val() || $(this).closest('.scan-not-found').find('strong').next().text().trim();
        // This would create a task - integrate with task module
        alert('Tarea creada para asignar código: ' + barcode);
    });

    // Load barcodes
    function loadBarcodes() {
        $.post(ajaxurl, {
            action: 'riverso_get_barcodes',
            nonce: nonce,
            page: currentPage,
            per_page: 50,
            filter: $('#filter-status').val(),
            search: $('#search-barcode').val()
        }, function(response) {
            const tbody = $('#barcodes-list');
            tbody.empty();
            
            if (!response.success || !response.data.barcodes.length) {
                tbody.html('<tr><td colspan="7" style="text-align: center;">No hay códigos de barra</td></tr>');
                return;
            }
            
            totalPages = response.data.pages;
            $('#pagination-info').text(`Página ${currentPage} de ${totalPages} (${response.data.total} total)`);
            $('#btn-prev').prop('disabled', currentPage <= 1);
            $('#btn-next').prop('disabled', currentPage >= totalPages);
            
            response.data.barcodes.forEach(function(b) {
                tbody.append(`
                    <tr>
                        <td><code class="barcode-code">${b.barcode}</code></td>
                        <td>${b.sku || '-'}</td>
                        <td>${b.product_name || '<span class="status-unlinked">Sin vincular</span>'}</td>
                        <td>${b.barcode_type}</td>
                        <td>${b.source}</td>
                        <td>${b.created_at ? b.created_at.split(' ')[0] : '-'}</td>
                        <td>
                            <button class="button button-small btn-delete-barcode" data-id="${b.id}">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </td>
                    </tr>
                `);
            });
        });
    }

    $('#btn-search').on('click', function() {
        currentPage = 1;
        loadBarcodes();
    });

    $('#filter-status').on('change', function() {
        currentPage = 1;
        loadBarcodes();
    });

    $('#btn-prev').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadBarcodes();
        }
    });

    $('#btn-next').on('click', function() {
        if (currentPage < totalPages) {
            currentPage++;
            loadBarcodes();
        }
    });

    // Delete barcode
    $(document).on('click', '.btn-delete-barcode', function() {
        if (!confirm('¿Eliminar este código de barra?')) return;
        
        const id = $(this).data('id');
        $.post(ajaxurl, {
            action: 'riverso_delete_barcode',
            nonce: nonce,
            id: id
        }, function(response) {
            if (response.success) {
                loadBarcodes();
                loadStats();
            } else {
                alert(response.data.message);
            }
        });
    });

    // Load unlinked
    function loadUnlinked() {
        $.post(ajaxurl, {
            action: 'riverso_get_unassigned_barcodes',
            nonce: nonce,
            page: 1,
            per_page: 100
        }, function(response) {
            const tbody = $('#unlinked-list');
            tbody.empty();
            
            if (!response.success || !response.data.barcodes.length) {
                tbody.html('<tr><td colspan="5" style="text-align: center;">✓ No hay códigos sin vincular</td></tr>');
                return;
            }
            
            response.data.barcodes.forEach(function(b) {
                tbody.append(`
                    <tr data-barcode-id="${b.id}">
                        <td><code class="barcode-code">${b.barcode}</code></td>
                        <td>${b.sku || '-'}</td>
                        <td>${b.barcode_type}</td>
                        <td>
                            <div class="quick-assign">
                                <input type="text" class="quick-sku-input" placeholder="SKU del producto">
                                <button class="button button-small btn-quick-assign">Asignar</button>
                            </div>
                        </td>
                        <td>
                            <button class="button button-small btn-search-assign" data-id="${b.id}">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                        </td>
                    </tr>
                `);
            });
        });
    }

    // Quick assign
    $(document).on('click', '.btn-quick-assign', function() {
        const row = $(this).closest('tr');
        const barcodeId = row.data('barcode-id');
        const sku = row.find('.quick-sku-input').val().trim();
        
        if (!sku) {
            alert('Ingresa un SKU');
            return;
        }
        
        // Search product by SKU first
        $.post(ajaxurl, {
            action: 'riverso_search_products_warehouse',
            nonce: nonce,
            search: sku
        }, function(response) {
            if (response.success && response.data.products.length > 0) {
                const p = response.data.products[0];
                assignBarcode(barcodeId, p.id, row);
            } else {
                alert('No se encontró producto con ese SKU');
            }
        });
    });

    function assignBarcode(barcodeId, productId, row) {
        $.post(ajaxurl, {
            action: 'riverso_assign_barcode',
            nonce: nonce,
            barcode_id: barcodeId,
            product_id: productId
        }, function(response) {
            if (response.success) {
                row.fadeOut(300, function() { $(this).remove(); });
                loadStats();
            } else {
                alert(response.data.message);
            }
        });
    }

    // Add barcode modal
    $('#btn-add-barcode').on('click', function() {
        $('#form-add-barcode')[0].reset();
        $('#selected-product-info').hide();
        $('#new-product-id, #new-variation-id').val('');
        $('#product-search-results').hide();
        $('#modal-add-barcode').show();
    });

    // Barcode validation
    $('#new-barcode').on('input', function() {
        const barcode = $(this).val().trim();
        const validation = $('#barcode-validation');
        
        if (barcode.length < 4) {
            validation.text('').removeClass('valid invalid');
            return;
        }
        
        // Simple validation
        if (barcode.length === 13 && /^\d+$/.test(barcode)) {
            validation.text('✓ EAN-13 válido').addClass('valid').removeClass('invalid');
            $('#new-barcode-type').val('EAN13');
        } else if (barcode.length === 8 && /^\d+$/.test(barcode)) {
            validation.text('✓ EAN-8 válido').addClass('valid').removeClass('invalid');
            $('#new-barcode-type').val('EAN8');
        } else if (barcode.length === 12 && /^\d+$/.test(barcode)) {
            validation.text('✓ UPC-A válido').addClass('valid').removeClass('invalid');
            $('#new-barcode-type').val('UPC');
        } else {
            validation.text('Código interno').addClass('valid').removeClass('invalid');
            $('#new-barcode-type').val('INTERNAL');
        }
    });

    // Product search in modal
    $('#search-product').on('keyup', debounce(function() {
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
                
                if (!response.data.products.length) {
                    results.html('<div style="padding: 10px;">No se encontraron productos</div>');
                } else {
                    response.data.products.forEach(function(p) {
                        results.append(`
                            <div class="result-item" data-id="${p.id}" data-name="${p.name}" data-sku="${p.sku}">
                                <strong>${p.name}</strong><br>
                                <code>${p.sku}</code>
                            </div>
                        `);
                    });
                }
                results.show();
            }
        });
    }, 300));

    $(document).on('click', '#product-search-results .result-item', function() {
        $('#new-product-id').val($(this).data('id'));
        $('#selected-product-name').text($(this).data('name'));
        $('#selected-product-sku').text($(this).data('sku'));
        $('#selected-product-info').show();
        $('#product-search-results').hide();
        $('#search-product').val('');
    });

    $('#btn-clear-product').on('click', function() {
        $('#new-product-id, #new-variation-id').val('');
        $('#selected-product-info').hide();
    });

    // Save barcode
    $('#btn-save-barcode').on('click', function() {
        const barcode = $('#new-barcode').val().trim();
        if (!barcode) {
            alert('Código de barra requerido');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_add_barcode',
            nonce: nonce,
            barcode: barcode,
            product_id: $('#new-product-id').val(),
            variation_id: $('#new-variation-id').val(),
            barcode_type: $('#new-barcode-type').val(),
            is_primary: $('#new-is-primary').is(':checked') ? 1 : 0,
            notes: $('#new-notes').val()
        }, function(response) {
            if (response.success) {
                $('#modal-add-barcode').hide();
                loadBarcodes();
                loadStats();
            } else {
                alert(response.data.message);
            }
        });
    });

    // Import CSV
    $('#btn-preview-import').on('click', function() {
        const file = $('#import-file')[0].files[0];
        if (!file) {
            alert('Selecciona un archivo CSV');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const lines = e.target.result.split('\n');
            const preview = $('#preview-list');
            preview.empty();
            importData = [];
            
            // Detect separator
            const firstLine = lines[0];
            const separator = firstLine.includes(';') ? ';' : ',';
            
            // Skip header
            for (let i = 1; i < Math.min(lines.length, 21); i++) {
                const parts = lines[i].split(separator);
                if (parts.length >= 2) {
                    const sku = parts[0].trim();
                    const barcode = parts[1].trim();
                    
                    if (barcode) {
                        importData.push({sku: sku, barcode: barcode});
                        preview.append(`
                            <tr>
                                <td>${sku}</td>
                                <td><code>${barcode}</code></td>
                                <td>Pendiente</td>
                            </tr>
                        `);
                    }
                }
            }
            
            // Count total from all lines
            let total = 0;
            for (let i = 1; i < lines.length; i++) {
                const parts = lines[i].split(separator);
                if (parts.length >= 2 && parts[1].trim()) {
                    total++;
                    if (i > 20) {
                        importData.push({
                            sku: parts[0].trim(),
                            barcode: parts[1].trim()
                        });
                    }
                }
            }
            
            $('#preview-summary').text(`Total: ${total} códigos de barra para importar`);
            $('#import-preview').show();
        };
        reader.readAsText(file);
    });

    $('#btn-confirm-import').on('click', function() {
        if (!importData.length) {
            alert('No hay datos para importar');
            return;
        }
        
        $(this).prop('disabled', true).text('Importando...');
        
        $.post(ajaxurl, {
            action: 'riverso_bulk_import_barcodes',
            nonce: nonce,
            barcodes: importData
        }, function(response) {
            $('#btn-confirm-import').prop('disabled', false).text('Confirmar Importación');
            
            if (response.success) {
                alert(response.data.message);
                $('#import-preview').hide();
                $('#import-file').val('');
                importData = [];
                loadStats();
                loadBarcodes();
            } else {
                alert(response.data.message);
            }
        });
    });

    // Close modals
    $('.riverso-modal-close, #btn-cancel-add, #btn-close-scan').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Initial load
    loadStats();
    loadBarcodes();
    
    // Auto-focus scanner on page load
    $('#scanner-input').focus();
});
</script>
