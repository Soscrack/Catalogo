<?php
/**
 * Template: Lista de Facturas
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap riverso-invoices">
    <h1>
        <span class="dashicons dashicons-media-spreadsheet"></span>
        Facturas DTE
        <?php if (current_user_can('riverso_process_invoices')): ?>
        <button type="button" class="page-title-action" id="btn-upload-invoice">
            <span class="dashicons dashicons-upload"></span> Subir XML
        </button>
        <?php endif; ?>
    </h1>

    <!-- Filtros -->
    <div class="riverso-filters">
        <select id="filter-estado">
            <option value="">Todos los estados</option>
            <option value="recibido">Recibido</option>
            <option value="parcial">Parcial</option>
            <option value="procesado">Procesado</option>
            <option value="rechazado">Rechazado</option>
        </select>
        
        <select id="filter-proveedor">
            <option value="">Todos los proveedores</option>
            <?php
            global $wpdb;
            $prefix = $wpdb->prefix . 'riverso_';
            $proveedores = $wpdb->get_results("SELECT id, nombre FROM {$prefix}proveedores WHERE activo = 1 ORDER BY nombre");
            foreach ($proveedores as $prov) {
                echo '<option value="' . esc_attr($prov->id) . '">' . esc_html($prov->nombre) . '</option>';
            }
            ?>
        </select>
        
        <input type="date" id="filter-fecha-desde" placeholder="Desde">
        <input type="date" id="filter-fecha-hasta" placeholder="Hasta">
        
        <button type="button" class="button" id="btn-filter">
            <span class="dashicons dashicons-filter"></span> Filtrar
        </button>
    </div>

    <!-- Tabla de facturas -->
    <table class="wp-list-table widefat fixed striped" id="invoices-table">
        <thead>
            <tr>
                <th style="width: 80px;">Folio</th>
                <th style="width: 80px;">Tipo</th>
                <th>Proveedor</th>
                <th style="width: 100px;">Fecha</th>
                <th style="width: 120px;">Total</th>
                <th style="width: 100px;">Items</th>
                <th style="width: 100px;">Estado</th>
                <th style="width: 120px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="invoices-list">
            <tr class="loading-row">
                <td colspan="8" style="text-align: center; padding: 40px;">
                    <span class="spinner is-active" style="float: none;"></span>
                    Cargando facturas...
                </td>
            </tr>
        </tbody>
    </table>

    <!-- Paginación -->
    <div class="tablenav bottom">
        <div class="tablenav-pages" id="pagination-info">
        </div>
    </div>
</div>

<!-- Modal: Subir factura -->
<div id="modal-upload-invoice" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2>Subir Factura XML</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-upload-invoice" enctype="multipart/form-data">
                <div class="upload-area" id="upload-dropzone">
                    <span class="dashicons dashicons-upload" style="font-size: 48px; width: 48px; height: 48px;"></span>
                    <p>Arrastra un archivo XML aquí o haz clic para seleccionar</p>
                    <input type="file" id="xml-file-input" name="xml_file" accept=".xml" style="display: none;">
                </div>
                <div id="upload-preview" style="display: none; margin-top: 15px;">
                    <strong>Archivo seleccionado:</strong>
                    <span id="selected-filename"></span>
                </div>
                <div id="upload-result" style="margin-top: 15px;"></div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-upload">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-process-upload" disabled>
                <span class="dashicons dashicons-yes"></span> Procesar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Detalle de factura -->
<div id="modal-invoice-detail" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content riverso-modal-large">
        <div class="riverso-modal-header">
            <h2>Detalle de Factura <span id="detail-folio"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="invoice-header-info">
                <div class="info-grid">
                    <div class="info-item">
                        <label>Proveedor:</label>
                        <span id="detail-proveedor"></span>
                    </div>
                    <div class="info-item">
                        <label>RUT:</label>
                        <span id="detail-rut"></span>
                    </div>
                    <div class="info-item">
                        <label>Fecha:</label>
                        <span id="detail-fecha"></span>
                    </div>
                    <div class="info-item">
                        <label>Total:</label>
                        <span id="detail-total" class="amount"></span>
                    </div>
                </div>
            </div>
            
            <h3>Items</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th style="width: 120px;">Código Prov.</th>
                        <th>Descripción</th>
                        <th style="width: 60px;">Cant.</th>
                        <th style="width: 100px;">Precio</th>
                        <th style="width: 100px;">Total</th>
                        <th style="width: 120px;">SKU Local</th>
                        <th style="width: 100px;">Estado</th>
                        <th style="width: 80px;">Acción</th>
                    </tr>
                </thead>
                <tbody id="detail-items">
                </tbody>
            </table>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-close-detail">Cerrar</button>
        </div>
    </div>
</div>

<style>
.riverso-invoices .riverso-filters {
    display: flex;
    gap: 10px;
    margin: 15px 0;
    flex-wrap: wrap;
}

.riverso-invoices .riverso-filters select,
.riverso-invoices .riverso-filters input[type="date"] {
    min-width: 150px;
}

.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.riverso-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.riverso-modal-large {
    max-width: 900px;
}

.riverso-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    background: #f5f5f5;
}

.riverso-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.riverso-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #666;
    padding: 0;
    line-height: 1;
}

.riverso-modal-body {
    padding: 20px;
}

.riverso-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.upload-area {
    border: 2px dashed #ccc;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
}

.upload-area:hover,
.upload-area.dragover {
    border-color: #2271b1;
    background: #f0f7fc;
}

.upload-area .dashicons {
    color: #999;
}

.upload-area:hover .dashicons {
    color: #2271b1;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-item label {
    display: block;
    font-weight: 600;
    color: #666;
    font-size: 12px;
    margin-bottom: 3px;
}

.info-item span {
    font-size: 14px;
}

.info-item .amount {
    font-weight: 600;
    color: #2e7d32;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-recibido { background: #e3f2fd; color: #1565c0; }
.status-parcial { background: #fff3e0; color: #ef6c00; }
.status-procesado { background: #e8f5e9; color: #2e7d32; }
.status-rechazado { background: #ffebee; color: #c62828; }
.status-pendiente { background: #fafafa; color: #666; }
.status-vinculado { background: #e8f5e9; color: #2e7d32; }

.link-sku-input {
    display: flex;
    gap: 5px;
}

.link-sku-input input {
    width: 100px;
    padding: 3px 5px;
    font-size: 12px;
}

.link-sku-input button {
    padding: 2px 6px;
    font-size: 11px;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    
    // Cargar facturas
    function loadInvoices(page = 1) {
        const filters = {
            action: 'riverso_get_invoices_list',
            nonce: nonce,
            page: page,
            estado: $('#filter-estado').val(),
            proveedor_id: $('#filter-proveedor').val(),
            fecha_desde: $('#filter-fecha-desde').val(),
            fecha_hasta: $('#filter-fecha-hasta').val()
        };
        
        $.post(ajaxurl, filters, function(response) {
            if (response.success) {
                renderInvoices(response.data);
            } else {
                alert(response.data.message || 'Error cargando facturas');
            }
        });
    }
    
    function renderInvoices(data) {
        const tbody = $('#invoices-list');
        tbody.empty();
        
        if (!data.facturas.length) {
            tbody.html('<tr><td colspan="8" style="text-align: center; padding: 40px;">No hay facturas</td></tr>');
            return;
        }
        
        const tiposDTE = {33: 'Factura', 34: 'F.Exenta', 52: 'Guía', 61: 'N.Crédito'};
        
        data.facturas.forEach(function(f) {
            const row = $('<tr>');
            row.html(`
                <td><strong>${f.folio}</strong></td>
                <td>${tiposDTE[f.tipo_dte] || f.tipo_dte}</td>
                <td>${f.proveedor_nombre}</td>
                <td>${f.fecha_emision}</td>
                <td style="text-align: right;">$${parseInt(f.monto_total).toLocaleString('es-CL')}</td>
                <td>${f.items_vinculados}/${f.total_items}</td>
                <td><span class="status-badge status-${f.estado}">${f.estado}</span></td>
                <td>
                    <button class="button button-small btn-view-invoice" data-id="${f.id}">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                </td>
            `);
            tbody.append(row);
        });
        
        // Paginación
        $('#pagination-info').html(
            `Página ${data.page} de ${data.total_pages} (${data.total} facturas)`
        );
    }
    
    // Event handlers
    $('#btn-filter').on('click', function() {
        loadInvoices(1);
    });
    
    // Upload modal
    $('#btn-upload-invoice').on('click', function() {
        $('#modal-upload-invoice').show();
    });
    
    $('.riverso-modal-close, #btn-cancel-upload').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    // Dropzone
    const dropzone = $('#upload-dropzone');
    const fileInput = $('#xml-file-input');
    
    dropzone.on('click', function() {
        fileInput.click();
    });
    
    dropzone.on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    });
    
    dropzone.on('dragleave', function() {
        $(this).removeClass('dragover');
    });
    
    dropzone.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) {
            fileInput[0].files = files;
            fileInput.trigger('change');
        }
    });
    
    fileInput.on('change', function() {
        if (this.files.length) {
            $('#selected-filename').text(this.files[0].name);
            $('#upload-preview').show();
            $('#btn-process-upload').prop('disabled', false);
        }
    });
    
    // Procesar upload
    $('#btn-process-upload').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Procesando...');
        
        const formData = new FormData();
        formData.append('action', 'riverso_upload_invoice');
        formData.append('nonce', nonce);
        formData.append('xml_file', fileInput[0].files[0]);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                const result = $('#upload-result');
                if (response.success) {
                    result.html(`
                        <div class="notice notice-success" style="padding: 10px;">
                            <strong>✓ ${response.data.message}</strong><br>
                            Proveedor: ${response.data.resumen.proveedor}<br>
                            Folio: ${response.data.resumen.folio}<br>
                            Items: ${response.data.resumen.items}
                        </div>
                    `);
                    loadInvoices(1);
                    setTimeout(() => $('#modal-upload-invoice').hide(), 2000);
                } else {
                    result.html(`<div class="notice notice-error" style="padding: 10px;">${response.data.message}</div>`);
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Procesar');
            }
        });
    });
    
    // Ver detalle
    $(document).on('click', '.btn-view-invoice', function() {
        const id = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: id
        }, function(response) {
            if (response.success) {
                showInvoiceDetail(response.data);
            }
        });
    });
    
    function showInvoiceDetail(factura) {
        $('#detail-folio').text('#' + factura.folio);
        $('#detail-proveedor').text(factura.proveedor_nombre);
        $('#detail-rut').text(factura.proveedor_rut);
        $('#detail-fecha').text(factura.fecha_emision);
        $('#detail-total').text('$' + parseInt(factura.monto_total).toLocaleString('es-CL'));
        
        const tbody = $('#detail-items');
        tbody.empty();
        
        factura.items.forEach(function(item) {
            const row = $('<tr>');
            row.html(`
                <td>${item.linea}</td>
                <td><code>${item.codigo_proveedor || '-'}</code></td>
                <td>${item.descripcion}</td>
                <td style="text-align: right;">${item.cantidad}</td>
                <td style="text-align: right;">$${parseInt(item.precio_unitario).toLocaleString('es-CL')}</td>
                <td style="text-align: right;">$${parseInt(item.monto_total).toLocaleString('es-CL')}</td>
                <td>${item.sku_local || 
                    `<div class="link-sku-input">
                        <input type="text" class="sku-input" placeholder="SKU">
                        <button class="button button-small btn-link-sku" data-item="${item.id}">OK</button>
                    </div>`
                }</td>
                <td><span class="status-badge status-${item.estado}">${item.estado}</span></td>
                <td>
                    ${item.estado === 'vinculado' ? '' : 
                        `<button class="button button-small btn-reject-item" data-item="${item.id}" title="Rechazar">
                            <span class="dashicons dashicons-no"></span>
                        </button>`
                    }
                </td>
            `);
            tbody.append(row);
        });
        
        $('#modal-invoice-detail').show();
    }
    
    $('#btn-close-detail').on('click', function() {
        $('#modal-invoice-detail').hide();
    });
    
    // Vincular SKU
    $(document).on('click', '.btn-link-sku', function() {
        const btn = $(this);
        const itemId = btn.data('item');
        const sku = btn.siblings('.sku-input').val().trim();
        
        if (!sku) {
            alert('Ingresa un SKU');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_link_code',
            nonce: nonce,
            item_id: itemId,
            sku_local: sku,
            crear_mapeo: true
        }, function(response) {
            if (response.success) {
                btn.closest('td').text(sku);
                btn.closest('tr').find('.status-badge').removeClass('status-pendiente').addClass('status-vinculado').text('vinculado');
                loadInvoices();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Cargar al inicio
    loadInvoices(1);
});
</script>
