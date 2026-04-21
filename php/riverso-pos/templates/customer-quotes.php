<?php
/**
 * Template: Cotizaciones a Clientes
 */

if (!defined('ABSPATH')) {
    exit;
}

$quote_states = Riverso_Customer_Quote_Module::QUOTE_STATES;
?>

<div class="wrap riverso-customer-quotes">
    <h1>
        <span class="dashicons dashicons-media-document"></span>
        Cotizaciones a Clientes
        <button type="button" class="page-title-action" id="btn-new-quote">
            <span class="dashicons dashicons-plus-alt"></span> Nueva Cotización
        </button>
    </h1>

    <!-- Stats -->
    <div class="quote-stats">
        <div class="stat-card">
            <span class="stat-number" id="stat-total">-</span>
            <span class="stat-label">Total</span>
        </div>
        <div class="stat-card">
            <span class="stat-number" id="stat-draft">-</span>
            <span class="stat-label">Borradores</span>
        </div>
        <div class="stat-card info">
            <span class="stat-number" id="stat-sent">-</span>
            <span class="stat-label">Enviadas</span>
        </div>
        <div class="stat-card success">
            <span class="stat-number" id="stat-converted">-</span>
            <span class="stat-label">Convertidas</span>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <select id="filter-status">
            <option value="">Todos los estados</option>
            <?php foreach ($quote_states as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="search-quote" placeholder="Buscar por número o cliente...">
        <button type="button" class="button" id="btn-search">Buscar</button>
    </div>

    <!-- Quotes List -->
    <div class="quotes-container">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">Número</th>
                    <th>Cliente</th>
                    <th style="width: 120px;">Total</th>
                    <th style="width: 100px;">Estado</th>
                    <th style="width: 100px;">Válida hasta</th>
                    <th style="width: 100px;">Fecha</th>
                    <th style="width: 150px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="quotes-list">
                <tr><td colspan="7" style="text-align: center; padding: 40px;">Cargando...</td></tr>
            </tbody>
        </table>
        
        <div class="pagination-wrap">
            <span id="pagination-info"></span>
            <div class="pagination-buttons">
                <button type="button" class="button" id="btn-prev" disabled>← Anterior</button>
                <button type="button" class="button" id="btn-next">Siguiente →</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Nueva/Editar Cotización -->
<div id="modal-quote" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <div class="riverso-modal-header">
            <h2 id="modal-quote-title">Nueva Cotización</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <input type="hidden" id="quote-id" value="">
            
            <div class="quote-form-grid">
                <!-- Customer Info -->
                <div class="form-section">
                    <h4>Datos del Cliente</h4>
                    <div class="form-row">
                        <div class="form-field">
                            <label>Nombre *</label>
                            <input type="text" id="quote-customer-name" required>
                        </div>
                        <div class="form-field">
                            <label>RUT</label>
                            <input type="text" id="quote-customer-rut" placeholder="12.345.678-9">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field">
                            <label>Email</label>
                            <input type="email" id="quote-customer-email">
                        </div>
                        <div class="form-field">
                            <label>Teléfono</label>
                            <input type="text" id="quote-customer-phone">
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Dirección</label>
                        <textarea id="quote-customer-address" rows="2"></textarea>
                    </div>
                </div>
                
                <!-- Quote Settings -->
                <div class="form-section">
                    <h4>Configuración</h4>
                    <div class="form-row">
                        <div class="form-field">
                            <label>Vigencia (días)</label>
                            <input type="number" id="quote-valid-days" value="3" min="1" max="30">
                        </div>
                        <div class="form-field">
                            <label>Descuento Global</label>
                            <div class="input-group">
                                <select id="quote-discount-type">
                                    <option value="percent">%</option>
                                    <option value="fixed">$</option>
                                </select>
                                <input type="number" id="quote-discount-value" value="0" min="0" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Notas (visibles para cliente)</label>
                        <textarea id="quote-notes" rows="2"></textarea>
                    </div>
                    <div class="form-field">
                        <label>Notas internas</label>
                        <textarea id="quote-internal-notes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Products -->
            <div class="form-section products-section">
                <h4>Productos</h4>
                <div class="product-search">
                    <input type="text" id="product-search" placeholder="Buscar producto por SKU o nombre...">
                    <button type="button" class="button" id="btn-add-custom">Agregar Item Manual</button>
                </div>
                <div id="product-search-results" style="display: none;"></div>
                
                <table class="wp-list-table widefat fixed" id="quote-items-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th style="width: 80px;">Cant.</th>
                            <th style="width: 100px;">Precio</th>
                            <th style="width: 80px;">Desc. %</th>
                            <th style="width: 100px;">Subtotal</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="quote-items-list">
                        <tr class="empty-row"><td colspan="6" style="text-align: center;">Agrega productos a la cotización</td></tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>Subtotal:</strong></td>
                            <td><span id="quote-subtotal">$0</span></td>
                            <td></td>
                        </tr>
                        <tr id="row-discount" style="display: none;">
                            <td colspan="4" style="text-align: right;">Descuento:</td>
                            <td><span id="quote-discount-total">-$0</span></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>IVA (19%):</strong></td>
                            <td><span id="quote-tax">$0</span></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong style="font-size: 16px;">TOTAL:</strong></td>
                            <td><strong style="font-size: 18px;" id="quote-total">$0</strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-quote">Cancelar</button>
            <button type="button" class="button" id="btn-save-draft">Guardar Borrador</button>
            <button type="button" class="button button-primary" id="btn-save-send">Guardar y Enviar</button>
        </div>
    </div>
</div>

<!-- Modal: Ver Cotización -->
<div id="modal-view-quote" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 800px;">
        <div class="riverso-modal-header">
            <h2>Cotización <span id="view-quote-number"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body" id="view-quote-content">
            <!-- Content loaded dynamically -->
        </div>
        <div class="riverso-modal-footer" id="view-quote-actions">
            <!-- Actions loaded dynamically -->
        </div>
    </div>
</div>

<!-- Modal: Agregar Item Manual -->
<div id="modal-custom-item" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 500px;">
        <div class="riverso-modal-header">
            <h2>Agregar Item Manual</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="form-field">
                <label>Nombre *</label>
                <input type="text" id="custom-item-name" required>
            </div>
            <div class="form-field">
                <label>Descripción</label>
                <textarea id="custom-item-description" rows="2"></textarea>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>Cantidad *</label>
                    <input type="number" id="custom-item-qty" value="1" min="1">
                </div>
                <div class="form-field">
                    <label>Precio Unitario *</label>
                    <input type="number" id="custom-item-price" min="0" step="1">
                </div>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-custom">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-add-custom-item">Agregar</button>
        </div>
    </div>
</div>

<style>
.quote-stats {
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

.stat-card.info {
    border-color: #2196f3;
    background: #e3f2fd;
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

.filters-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.filters-bar input {
    min-width: 250px;
}

.quotes-container {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.pagination-wrap {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-top: 1px solid #eee;
}

.pagination-buttons {
    display: flex;
    gap: 10px;
}

.quote-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-section {
    background: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 15px;
}

.form-section h4 {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-field {
    flex: 1;
}

.form-field {
    margin-bottom: 12px;
}

.form-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 13px;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
}

.input-group {
    display: flex;
    gap: 5px;
}

.input-group select {
    width: 60px;
}

.input-group input {
    flex: 1;
}

.products-section {
    grid-column: 1 / -1;
}

.product-search {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.product-search input {
    flex: 1;
}

#product-search-results {
    border: 1px solid #ddd;
    border-radius: 4px;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 15px;
    background: white;
}

#product-search-results .result-item {
    padding: 10px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

#product-search-results .result-item:hover {
    background: #f5f5f5;
}

#quote-items-table tbody input {
    width: 100%;
    padding: 4px;
}

#quote-items-table tfoot td {
    padding: 8px;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.status-draft { background: #f5f5f5; color: #666; }
.status-sent { background: #e3f2fd; color: #1565c0; }
.status-viewed { background: #fff3e0; color: #ef6c00; }
.status-accepted { background: #e8f5e9; color: #388e3c; }
.status-rejected { background: #ffebee; color: #c62828; }
.status-expired { background: #fafafa; color: #999; }
.status-converted { background: #e8f5e9; color: #2e7d32; }

.quote-number {
    font-family: monospace;
    font-weight: 600;
}

.view-quote-customer {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.view-quote-customer h4 {
    margin: 0 0 10px 0;
}

.view-quote-items {
    margin: 20px 0;
}

.view-quote-totals {
    text-align: right;
    padding: 15px;
    background: #f5f5f5;
    border-radius: 6px;
}

.view-quote-totals .total-line {
    margin: 5px 0;
}

.view-quote-totals .grand-total {
    font-size: 20px;
    font-weight: 700;
    color: #2e7d32;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 2px solid #ddd;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    const quoteStates = <?php echo wp_json_encode($quote_states); ?>;
    let currentPage = 1;
    let totalPages = 1;
    let quoteItems = [];

    // Load stats
    function loadStats() {
        $.post(ajaxurl, {action: 'riverso_get_quote_stats', nonce: nonce}, function(response) {
            if (response.success) {
                $('#stat-total').text(response.data.total || 0);
                $('#stat-draft').text(response.data.draft || 0);
                $('#stat-sent').text(response.data.sent || 0);
                $('#stat-converted').text(response.data.converted || 0);
            }
        });
    }

    // Load quotes
    function loadQuotes() {
        $.post(ajaxurl, {
            action: 'riverso_get_customer_quotes',
            nonce: nonce,
            status: $('#filter-status').val(),
            search: $('#search-quote').val(),
            page: currentPage
        }, function(response) {
            const tbody = $('#quotes-list');
            tbody.empty();
            
            if (!response.success || !response.data.quotes.length) {
                tbody.html('<tr><td colspan="7" style="text-align: center; padding: 40px;">No hay cotizaciones</td></tr>');
                return;
            }
            
            totalPages = response.data.pages;
            $('#pagination-info').text(`Página ${currentPage} de ${totalPages}`);
            $('#btn-prev').prop('disabled', currentPage <= 1);
            $('#btn-next').prop('disabled', currentPage >= totalPages);
            
            response.data.quotes.forEach(function(q) {
                tbody.append(`
                    <tr>
                        <td><span class="quote-number">${q.quote_number}</span></td>
                        <td>
                            <strong>${escapeHtml(q.customer_name)}</strong>
                            ${q.customer_email ? `<br><small>${q.customer_email}</small>` : ''}
                        </td>
                        <td><strong>$${formatNumber(q.total)}</strong></td>
                        <td><span class="status-badge status-${q.status}">${quoteStates[q.status] || q.status}</span></td>
                        <td>${q.valid_until || '-'}</td>
                        <td>${q.created_at.split(' ')[0]}</td>
                        <td>
                            <button class="button button-small btn-view-quote" data-id="${q.id}">Ver</button>
                            ${q.status === 'draft' ? `<button class="button button-small btn-edit-quote" data-id="${q.id}">Editar</button>` : ''}
                            ${['draft', 'sent', 'accepted'].includes(q.status) ? `<button class="button button-small btn-convert-quote" data-id="${q.id}">Pedido</button>` : ''}
                        </td>
                    </tr>
                `);
            });
        });
    }

    // Search and filter
    $('#btn-search, #filter-status').on('click change', function() {
        currentPage = 1;
        loadQuotes();
    });

    $('#btn-prev').on('click', function() { if (currentPage > 1) { currentPage--; loadQuotes(); } });
    $('#btn-next').on('click', function() { if (currentPage < totalPages) { currentPage++; loadQuotes(); } });

    // New quote
    $('#btn-new-quote').on('click', function() {
        $('#quote-id').val('');
        $('#modal-quote-title').text('Nueva Cotización');
        $('#quote-customer-name, #quote-customer-email, #quote-customer-phone, #quote-customer-rut, #quote-customer-address').val('');
        $('#quote-valid-days').val(3);
        $('#quote-discount-type').val('percent');
        $('#quote-discount-value').val(0);
        $('#quote-notes, #quote-internal-notes').val('');
        quoteItems = [];
        renderQuoteItems();
        $('#modal-quote').show();
    });

    // Product search
    $('#product-search').on('keyup', debounce(function() {
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
                    results.html('<div style="padding: 15px;">No se encontraron productos</div>');
                } else {
                    response.data.products.forEach(function(p) {
                        results.append(`
                            <div class="result-item" data-product='${JSON.stringify(p)}'>
                                <div>
                                    <strong>${escapeHtml(p.name)}</strong><br>
                                    <small>SKU: ${p.sku}</small>
                                </div>
                                <div style="text-align: right;">
                                    <strong>$${formatNumber(p.price || 0)}</strong>
                                </div>
                            </div>
                        `);
                    });
                }
                results.show();
            }
        });
    }, 300));

    // Add product from search
    $(document).on('click', '#product-search-results .result-item', function() {
        const product = $(this).data('product');
        quoteItems.push({
            product_id: product.id,
            sku: product.sku,
            name: product.name,
            quantity: 1,
            unit_price: parseFloat(product.price) || 0,
            discount_percent: 0,
            tax_percent: 19
        });
        renderQuoteItems();
        $('#product-search').val('');
        $('#product-search-results').hide();
    });

    // Custom item
    $('#btn-add-custom').on('click', function() {
        $('#custom-item-name').val('');
        $('#custom-item-description').val('');
        $('#custom-item-qty').val(1);
        $('#custom-item-price').val('');
        $('#modal-custom-item').show();
    });

    $('#btn-add-custom-item').on('click', function() {
        const name = $('#custom-item-name').val().trim();
        const price = parseFloat($('#custom-item-price').val()) || 0;
        
        if (!name) {
            alert('Nombre requerido');
            return;
        }
        
        quoteItems.push({
            product_id: null,
            sku: '',
            name: name,
            description: $('#custom-item-description').val(),
            quantity: parseInt($('#custom-item-qty').val()) || 1,
            unit_price: price,
            discount_percent: 0,
            tax_percent: 19
        });
        renderQuoteItems();
        $('#modal-custom-item').hide();
    });

    // Render items
    function renderQuoteItems() {
        const tbody = $('#quote-items-list');
        tbody.empty();
        
        if (!quoteItems.length) {
            tbody.html('<tr class="empty-row"><td colspan="6" style="text-align: center;">Agrega productos a la cotización</td></tr>');
            updateTotals();
            return;
        }
        
        quoteItems.forEach(function(item, index) {
            const subtotal = item.quantity * item.unit_price * (1 - item.discount_percent / 100);
            tbody.append(`
                <tr data-index="${index}">
                    <td>
                        <strong>${escapeHtml(item.name)}</strong>
                        ${item.sku ? `<br><small>SKU: ${item.sku}</small>` : ''}
                    </td>
                    <td><input type="number" class="item-qty" value="${item.quantity}" min="1"></td>
                    <td><input type="number" class="item-price" value="${item.unit_price}" min="0" step="1"></td>
                    <td><input type="number" class="item-discount" value="${item.discount_percent}" min="0" max="100" step="1"></td>
                    <td>$${formatNumber(subtotal)}</td>
                    <td>
                        <button type="button" class="button button-small btn-remove-item">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            `);
        });
        
        updateTotals();
    }

    // Update item values
    $(document).on('change', '.item-qty, .item-price, .item-discount', function() {
        const row = $(this).closest('tr');
        const index = row.data('index');
        
        quoteItems[index].quantity = parseInt(row.find('.item-qty').val()) || 1;
        quoteItems[index].unit_price = parseFloat(row.find('.item-price').val()) || 0;
        quoteItems[index].discount_percent = parseFloat(row.find('.item-discount').val()) || 0;
        
        renderQuoteItems();
    });

    // Remove item
    $(document).on('click', '.btn-remove-item', function() {
        const index = $(this).closest('tr').data('index');
        quoteItems.splice(index, 1);
        renderQuoteItems();
    });

    // Update totals
    function updateTotals() {
        let subtotal = 0;
        let taxTotal = 0;
        
        quoteItems.forEach(function(item) {
            const itemSubtotal = item.quantity * item.unit_price * (1 - item.discount_percent / 100);
            const itemTax = itemSubtotal * (item.tax_percent / 100);
            subtotal += itemSubtotal;
            taxTotal += itemTax;
        });
        
        const discountType = $('#quote-discount-type').val();
        const discountValue = parseFloat($('#quote-discount-value').val()) || 0;
        let discountTotal = 0;
        
        if (discountType === 'percent') {
            discountTotal = subtotal * (discountValue / 100);
        } else {
            discountTotal = discountValue;
        }
        
        const total = subtotal - discountTotal + taxTotal;
        
        $('#quote-subtotal').text('$' + formatNumber(subtotal));
        $('#quote-discount-total').text('-$' + formatNumber(discountTotal));
        $('#row-discount').toggle(discountTotal > 0);
        $('#quote-tax').text('$' + formatNumber(taxTotal));
        $('#quote-total').text('$' + formatNumber(total));
    }

    $('#quote-discount-type, #quote-discount-value').on('change input', updateTotals);

    // Save quote
    function saveQuote(send) {
        const customerName = $('#quote-customer-name').val().trim();
        if (!customerName) {
            alert('Nombre de cliente requerido');
            return;
        }
        
        if (!quoteItems.length) {
            alert('Agrega al menos un producto');
            return;
        }
        
        const quoteId = $('#quote-id').val();
        
        // First create/update quote
        const quoteData = {
            action: quoteId ? 'riverso_update_customer_quote' : 'riverso_create_customer_quote',
            nonce: nonce,
            quote_id: quoteId,
            customer_name: customerName,
            customer_email: $('#quote-customer-email').val(),
            customer_phone: $('#quote-customer-phone').val(),
            customer_rut: $('#quote-customer-rut').val(),
            customer_address: $('#quote-customer-address').val(),
            valid_days: $('#quote-valid-days').val(),
            discount_type: $('#quote-discount-type').val(),
            discount_value: $('#quote-discount-value').val(),
            notes: $('#quote-notes').val(),
            internal_notes: $('#quote-internal-notes').val()
        };
        
        $.post(ajaxurl, quoteData, function(response) {
            if (!response.success) {
                alert(response.data.message);
                return;
            }
            
            const newQuoteId = response.data.quote_id || quoteId;
            
            // Add items
            let itemsAdded = 0;
            quoteItems.forEach(function(item) {
                $.post(ajaxurl, {
                    action: 'riverso_add_quote_item',
                    nonce: nonce,
                    quote_id: newQuoteId,
                    product_id: item.product_id || 0,
                    sku: item.sku || '',
                    name: item.name,
                    description: item.description || '',
                    quantity: item.quantity,
                    unit_price: item.unit_price,
                    discount_percent: item.discount_percent,
                    tax_percent: item.tax_percent
                }, function() {
                    itemsAdded++;
                    if (itemsAdded === quoteItems.length) {
                        if (send) {
                            // Send quote
                            $.post(ajaxurl, {
                                action: 'riverso_send_customer_quote',
                                nonce: nonce,
                                quote_id: newQuoteId,
                                method: 'email'
                            }, function(sendResponse) {
                                if (sendResponse.success) {
                                    alert('Cotización guardada y enviada');
                                } else {
                                    alert('Cotización guardada pero error al enviar: ' + sendResponse.data.message);
                                }
                                $('#modal-quote').hide();
                                loadQuotes();
                                loadStats();
                            });
                        } else {
                            alert('Cotización guardada');
                            $('#modal-quote').hide();
                            loadQuotes();
                            loadStats();
                        }
                    }
                });
            });
        });
    }

    $('#btn-save-draft').on('click', function() { saveQuote(false); });
    $('#btn-save-send').on('click', function() { saveQuote(true); });

    // View quote
    $(document).on('click', '.btn-view-quote', function() {
        const quoteId = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_get_customer_quote',
            nonce: nonce,
            quote_id: quoteId
        }, function(response) {
            if (!response.success) {
                alert(response.data.message);
                return;
            }
            
            const q = response.data;
            $('#view-quote-number').text(q.quote_number);
            
            let itemsHtml = '';
            q.items.forEach(function(item) {
                itemsHtml += `<tr><td>${escapeHtml(item.name)}</td><td style="text-align:center">${item.quantity}</td><td style="text-align:right">$${formatNumber(item.unit_price)}</td><td style="text-align:right">$${formatNumber(item.total)}</td></tr>`;
            });
            
            let content = `
                <div class="view-quote-customer">
                    <h4>Cliente: ${escapeHtml(q.customer_name)}</h4>
                    ${q.customer_email ? `<p>Email: ${q.customer_email}</p>` : ''}
                    ${q.customer_phone ? `<p>Teléfono: ${q.customer_phone}</p>` : ''}
                    ${q.customer_rut ? `<p>RUT: ${q.customer_rut}</p>` : ''}
                </div>
                <div class="view-quote-items">
                    <table class="wp-list-table widefat fixed striped">
                        <thead><tr><th>Producto</th><th style="width:80px;text-align:center">Cant.</th><th style="width:100px;text-align:right">Precio</th><th style="width:100px;text-align:right">Total</th></tr></thead>
                        <tbody>${itemsHtml}</tbody>
                    </table>
                </div>
                <div class="view-quote-totals">
                    <div class="total-line">Subtotal: $${formatNumber(q.subtotal)}</div>
                    ${q.discount_total > 0 ? `<div class="total-line">Descuento: -$${formatNumber(q.discount_total)}</div>` : ''}
                    <div class="total-line">IVA: $${formatNumber(q.tax_total)}</div>
                    <div class="grand-total">Total: $${formatNumber(q.total)}</div>
                </div>
                ${q.notes ? `<p style="margin-top:15px"><strong>Notas:</strong> ${escapeHtml(q.notes)}</p>` : ''}
                <p style="margin-top:10px">
                    <strong>Estado:</strong> <span class="status-badge status-${q.status}">${quoteStates[q.status]}</span><br>
                    <strong>Vigencia:</strong> ${q.valid_days} días (hasta ${q.valid_until})
                </p>
            `;
            
            let actions = '<button type="button" class="button" onclick="jQuery(\'#modal-view-quote\').hide()">Cerrar</button>';
            
            if (['draft', 'sent', 'accepted'].includes(q.status)) {
                actions += ` <button type="button" class="button button-primary btn-convert-quote" data-id="${q.id}">Convertir a Pedido</button>`;
            }
            
            $('#view-quote-content').html(content);
            $('#view-quote-actions').html(actions);
            $('#modal-view-quote').show();
        });
    });

    // Convert to order
    $(document).on('click', '.btn-convert-quote', function() {
        if (!confirm('¿Convertir esta cotización en pedido?')) return;
        
        const quoteId = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_convert_quote_to_order',
            nonce: nonce,
            quote_id: quoteId
        }, function(response) {
            if (response.success) {
                alert('Pedido creado exitosamente');
                if (response.data.order_url) {
                    window.open(response.data.order_url, '_blank');
                }
                $('#modal-view-quote').hide();
                loadQuotes();
                loadStats();
            } else {
                alert(response.data.message);
            }
        });
    });

    // Close modals
    $('.riverso-modal-close, #btn-cancel-quote, #btn-cancel-custom').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });

    // Helpers
    function formatNumber(num) {
        return Math.round(num).toLocaleString('es-CL');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Initial load
    loadStats();
    loadQuotes();
});
</script>
