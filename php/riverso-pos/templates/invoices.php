<?php
/**
 * Template: Lista de Facturas
 */

if (!defined('ABSPATH')) {
    exit;
}

$default_intake_mode = riverso_get_setting('default_intake_mode', 'recepcion');
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
            <option value="sin_vincular">Flete sin asignar</option>
            <option value="vinculado">Flete vinculado</option>
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
    <table class="wp-list-table widefat striped riverso-data-table" id="invoices-table">
        <thead>
            <tr>
                <th style="width: 80px;">Folio</th>
                <th style="width: 80px;">Tipo</th>
                <th class="col-proveedor">Proveedor</th>
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
    <div class="riverso-modal-content" id="upload-modal-content">
        <div class="riverso-modal-header">
            <h2>Subir Factura XML</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-upload-invoice" enctype="multipart/form-data">
                <!-- Paso 1: seleccionar archivo -->
                <div id="upload-step-select">
                    <div class="upload-mode-toggle">
                        <label><input type="radio" name="upload_mode" value="single" checked> Un XML (con vista previa)</label>
                        <label><input type="radio" name="upload_mode" value="bulk"> Carga masiva</label>
                    </div>

                    <div id="upload-single-wrap">
                        <p class="description" style="margin-bottom: 12px;">
                            Suba <strong>un archivo XML</strong>. El sistema lo analizará y detectará si corresponde a
                            <strong>productos</strong> o a un <strong>transportista/flete</strong> antes de procesarlo.
                        </p>
                        <input type="file" id="xml-file-input" name="xml_file" accept=".xml" style="display: none;">
                        <div class="upload-area" id="upload-dropzone">
                            <span class="dashicons dashicons-upload" style="font-size: 48px; width: 48px; height: 48px;"></span>
                            <p>Arrastra el XML aquí</p>
                        </div>
                        <div class="upload-toolbar">
                            <button type="button" class="button button-primary" id="btn-browse-xml">
                                <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                            </button>
                        </div>
                        <p id="upload-file-name" class="description" style="margin-top:10px;text-align:center;"></p>
                    </div>

                    <div id="upload-bulk-wrap" style="display:none;">
                        <p class="description" style="margin-bottom: 12px;">
                            Seleccione <strong>varios XML</strong>. Se procesarán en secuencia con detección automática.
                            Los fletes quedan <strong>sin asignar</strong> hasta que los vincule manualmente.
                        </p>
                        <input type="file" id="xml-bulk-input" accept=".xml" multiple style="display: none;">
                        <div class="upload-area" id="bulk-dropzone">
                            <span class="dashicons dashicons-media-default" style="font-size: 48px; width: 48px; height: 48px;"></span>
                            <p>Arrastra varios XML aquí</p>
                        </div>
                        <div class="upload-toolbar">
                            <button type="button" class="button button-primary" id="btn-browse-xml-bulk">
                                <span class="dashicons dashicons-open-folder"></span> Buscar archivos XML
                            </button>
                            <button type="button" class="button button-primary" id="btn-start-bulk" disabled>
                                <span class="dashicons dashicons-controls-play"></span> Procesar todos
                            </button>
                        </div>
                        <div id="bulk-queue" class="bulk-queue" style="display:none;"></div>
                    </div>
                </div>

                <!-- Paso 2: preview y confirmación -->
                <div id="upload-step-confirm" style="display:none;">
                    <div id="intake-gaps-inline" style="display:none;margin-bottom:12px;padding:10px 12px;background:#fff8e5;border:1px solid #f0c36d;border-radius:6px;font-size:13px;"></div>
                    <div id="upload-xml-preview" style="padding:12px;background:#f0f6fc;border:1px solid #c3d9f0;border-radius:6px;margin-bottom:14px;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                            <span class="dashicons dashicons-visibility" style="color:#2271b1;"></span>
                            <strong>Vista previa — confirme antes de procesar</strong>
                        </div>
                        <div id="xml-preview-summary" style="font-size:13px;color:#1d2327;"></div>
                        <table class="wp-list-table widefat striped" id="xml-preview-items" style="margin-top:10px;font-size:12px;">
                            <thead><tr><th>#</th><th class="col-desc">Descripción</th><th>Tipo</th><th style="text-align:right;">Monto</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <h3 style="margin:0 0 8px;">Tipo de documento</h3>
                    <p id="detection-motivo" class="description" style="margin-bottom:8px;"></p>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="radio" name="documento_tipo" value="productos">
                        <span id="label-tipo-productos">Factura de productos</span>
                    </label>
                    <label style="display:block;margin-bottom:12px;">
                        <input type="radio" name="documento_tipo" value="envio">
                        <span id="label-tipo-envio">Factura de transportista / flete</span>
                    </label>

                    <div id="link-factura-wrap" style="display:none;margin-bottom:14px;padding:10px;background:#fff8e5;border-radius:4px;">
                        <label><strong>Vincular flete a factura de productos</strong> <em>(opcional — puede asignar después)</em></label>
                        <select id="link-factura-productos-id" style="width:100%;margin-top:6px;">
                            <option value="">— Dejar sin asignar por ahora —</option>
                        </select>
                    </div>

                    <div id="opciones-productos-wrap">
                        <h3 style="margin-bottom:8px;">Modo de ingreso</h3>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="radio" name="modo_ingreso" value="recepcion" <?php checked($default_intake_mode, 'recepcion'); ?>>
                            Recepción completa
                        </label>
                        <label style="display:block;margin-bottom:12px;">
                            <input type="radio" name="modo_ingreso" value="solo_costos" <?php checked($default_intake_mode, 'solo_costos'); ?>>
                            Solo registrar costos <em>(sin bodega)</em>
                        </label>
                    </div>

                    <h3 style="margin-bottom:8px;">Proveedor / emisor</h3>
                    <div style="margin-bottom:10px;">
                        <label><input type="radio" name="proveedor_modo" value="xml" checked> Datos del XML</label><br>
                        <label><input type="radio" name="proveedor_modo" value="existente"> Proveedor existente</label><br>
                        <label><input type="radio" name="proveedor_modo" value="nuevo"> Editar manualmente</label>
                    </div>
                    <div id="proveedor-select-wrap" style="display:none;margin-bottom:10px;">
                        <select id="proveedor-existente-id" style="width:100%;max-width:400px;">
                            <option value="">— Seleccionar —</option>
                        </select>
                    </div>
                    <div id="proveedor-form-wrap" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-width:640px;">
                        <div><label>RUT</label><input type="text" id="prov-rut" class="regular-text" style="width:100%" readonly></div>
                        <div><label>Nombre</label><input type="text" id="prov-nombre" class="regular-text" style="width:100%"></div>
                        <div><label>Giro</label><input type="text" id="prov-giro" class="regular-text" style="width:100%"></div>
                        <div><label>Comuna</label><input type="text" id="prov-comuna" class="regular-text" style="width:100%"></div>
                        <div style="grid-column:1/-1;"><label>Dirección</label><input type="text" id="prov-direccion" class="regular-text" style="width:100%"></div>
                    </div>
                    <p id="proveedor-status" class="description" style="margin-top:8px;"></p>
                </div>

                <div id="upload-result" style="margin-top: 15px;"></div>
            </form>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-upload">Cancelar</button>
            <button type="button" class="button" id="btn-change-xml" style="display:none;">Cambiar archivo</button>
            <button type="button" class="button button-primary" id="btn-process-upload" disabled style="display:none;">
                <span class="dashicons dashicons-yes"></span> Confirmar y procesar
            </button>
        </div>
    </div>
</div>

<!-- Modal: Completar datos faltantes -->
<div id="modal-intake-missing" class="riverso-modal riverso-modal-stacked" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 520px;">
        <div class="riverso-modal-header">
            <h2>Completar datos</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <p id="intake-missing-intro" class="description" style="margin-bottom: 12px;">
                Faltan datos para registrar esta factura en el sistema. Complete los campos y confirme.
            </p>
            <div id="intake-missing-fields"></div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-intake-missing-cancel">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-intake-missing-save">
                Guardar y continuar
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

            <div id="detail-shipping-section" style="display:none;margin-bottom:16px;padding:12px;background:#f0f6fc;border-radius:6px;">
                <h3 style="margin:0 0 10px;">Fletes vinculados</h3>
                <div id="detail-shipping-linked"></div>
                <div id="detail-shipping-assign" style="margin-top:12px;display:none;">
                    <label><strong>Asignar flete pendiente</strong></label>
                    <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
                        <select id="detail-assign-flete-id" style="flex:1;min-width:200px;"></select>
                        <button type="button" class="button button-primary" id="btn-assign-flete">Vincular flete</button>
                    </div>
                </div>
            </div>

            <div id="detail-envio-assign-section" style="display:none;margin-bottom:16px;padding:12px;background:#fff8e5;border-radius:6px;">
                <h3 style="margin:0 0 8px;">Vincular a facturas de productos</h3>
                <p class="description" style="margin-bottom:8px;">Un mismo flete puede repartirse entre varias facturas de productos.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="detail-envio-target-id" style="flex:1;min-width:200px;"></select>
                    <button type="button" class="button button-primary" id="btn-envio-assign">Vincular</button>
                </div>
                <div id="detail-envio-linked-info" style="margin-top:10px;display:none;"></div>
                <button type="button" class="button" id="btn-envio-unassign" style="margin-top:8px;display:none;">Desvincular todas</button>
            </div>
            
            <h3>Items</h3>
            <table class="wp-list-table widefat striped riverso-items-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th style="width: 120px;">Código Prov.</th>
                        <th class="col-desc">Descripción</th>
                        <th style="width: 60px;">Cant.</th>
                        <th style="width: 100px;">Precio</th>
                        <th style="width: 100px;">Total</th>
                        <th style="width: 120px;">SKU Local</th>
                        <th style="width: 100px;">SKU Online</th>
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
    max-width: 620px;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
}

.riverso-modal-large {
    max-width: 900px;
}

.riverso-modal-stacked {
    z-index: 100001;
}

#upload-modal-content.upload-modal-wide {
    max-width: 820px;
}

#upload-xml-preview {
    max-height: 280px;
    overflow-y: auto;
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
.status-sin_vincular { background: #fff3e0; color: #e65100; }

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
    const canDeleteInvoices = <?php echo (current_user_can('riverso_process_invoices') || current_user_can('riverso_create_invoices')) ? 'true' : 'false'; ?>;
    
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
            const deleteBtn = (canDeleteInvoices && f.can_delete)
                ? `<button class="button button-small btn-delete-invoice" data-id="${f.id}" data-folio="${f.folio}" title="Eliminar subida" style="color:#b32d2e;">
                        <span class="dashicons dashicons-trash"></span>
                   </button>`
                : '';
            const isEnvio = f.documento_subtipo === 'envio';
            const tipoLabel = isEnvio
                ? '<span style="color:#b45309;font-weight:600;">Flete</span>'
                : '<span style="color:#15803d;">Productos</span>';
            const vinculadas = parseInt(f.facturas_vinculadas || 0, 10);
            const itemsCol = isEnvio
                ? (vinculadas > 0 ? `${vinculadas} factura(s)` : 'Sin asignar')
                : `${f.items_vinculados}/${f.total_items}` +
                  (parseInt(f.fletes_vinculados) > 0 ? ` · ${f.fletes_vinculados} flete(s)` : '');
            const estadoLabel = (f.estado || '').replace(/_/g, ' ');
            const linkBtn = isEnvio
                ? `<button class="button button-small button-primary btn-view-invoice" data-id="${f.id}" title="Vincular a facturas de productos">Vincular</button>`
                : '';
            const row = $('<tr>');
            row.html(`
                <td><strong>${f.folio}</strong></td>
                <td>${tipoLabel}</td>
                <td class="col-proveedor">${f.proveedor_nombre}</td>
                <td>${f.fecha_emision}</td>
                <td style="text-align: right;">$${parseInt(f.monto_total).toLocaleString('es-CL')}</td>
                <td>${itemsCol}</td>
                <td><span class="status-badge status-${f.estado}">${estadoLabel}</span></td>
                <td>
                    <button class="button button-small btn-view-invoice" data-id="${f.id}">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                    ${linkBtn}
                    ${deleteBtn}
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
    function showRiversoModal($el) {
        $el.css('display', 'flex');
    }

    function hideRiversoModal($el) {
        $el.css('display', 'none');
    }

    function setInputFiles(input, fileList) {
        if (!input || !fileList || !fileList.length) return false;
        const dt = new DataTransfer();
        Array.from(fileList).forEach(f => dt.items.add(f));
        input.files = dt.files;
        return input.files.length > 0;
    }

    function resetUploadModal() {
        previewData = null;
        bulkFiles = [];
        fileInput.val('');
        $('#xml-bulk-input').val('');
        $('#bulk-queue').hide().empty();
        $('#btn-start-bulk').prop('disabled', true);
        $('input[name="upload_mode"][value="single"]').prop('checked', true);
        $('#upload-single-wrap').show();
        $('#upload-bulk-wrap').hide();
        $('#upload-step-select').show();
        $('#upload-step-confirm').hide();
        $('#upload-modal-content').removeClass('upload-modal-wide');
        $('#intake-gaps-inline').hide().empty();
        $('#btn-change-xml, #btn-process-upload').hide();
        $('#btn-process-upload').prop('disabled', true);
        $('#upload-file-name, #upload-result').empty();
        $('#xml-preview-items tbody').empty();
    }

    $('#btn-upload-invoice').on('click', function() {
        resetUploadModal();
        showRiversoModal($('#modal-upload-invoice'));
    });
    
    $('.riverso-modal-close, #btn-cancel-upload').on('click', function() {
        hideRiversoModal($(this).closest('.riverso-modal'));
    });

    $('#btn-change-xml').on('click', function() {
        resetUploadModal();
    });
    
    const dropzone = $('#upload-dropzone');
    const bulkDropzone = $('#bulk-dropzone');
    const fileInput = $('#xml-file-input');
    const bulkInput = $('#xml-bulk-input');
    let bulkFiles = [];

    $('input[name="upload_mode"]').on('change', function() {
        const isBulk = $(this).val() === 'bulk';
        $('#upload-single-wrap').toggle(!isBulk);
        $('#upload-bulk-wrap').toggle(isBulk);
        $('#upload-step-confirm').hide();
        $('#btn-change-xml, #btn-process-upload').hide();
        $('#upload-result').empty();
    });

    $('#btn-browse-xml').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        fileInput.val('');
        fileInput[0].click();
    });

    $('#btn-browse-xml-bulk').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        bulkInput.val('');
        bulkInput[0].click();
    });

    dropzone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
    dropzone.on('dragleave', function() { $(this).removeClass('dragover'); });
    dropzone.on('click', function(e) {
        if ($(e.target).closest('#btn-browse-xml').length) return;
        e.preventDefault();
        fileInput.val('');
        fileInput[0].click();
    });
    dropzone.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length && setInputFiles(fileInput[0], files)) {
            fileInput.trigger('change');
        }
    });

    bulkDropzone.on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
    bulkDropzone.on('dragleave', function() { $(this).removeClass('dragover'); });
    bulkDropzone.on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) setBulkFiles(files);
    });

    function setBulkFiles(fileList) {
        bulkFiles = Array.from(fileList).filter(f => /\.xml$/i.test(f.name));
        const $q = $('#bulk-queue').empty().show();
        if (!bulkFiles.length) {
            $q.hide();
            $('#btn-start-bulk').prop('disabled', true);
            return;
        }
        bulkFiles.forEach((f, i) => {
            $q.append(`<div class="bulk-queue-item" data-idx="${i}"><span>${f.name}</span><span class="bulk-status">Pendiente</span></div>`);
        });
        $('#btn-start-bulk').prop('disabled', false);
    }

    bulkInput.on('change', function() {
        if (this.files.length) setBulkFiles(this.files);
    });

    function uploadOneFile(file, extraFields) {
        return new Promise((resolve) => {
            const formData = new FormData();
            formData.append('action', 'riverso_upload_invoice');
            formData.append('nonce', nonce);
            formData.append('xml_file', file);
            Object.entries(extraFields || {}).forEach(([k, v]) => formData.append(k, v ?? ''));
            $.ajax({
                url: ajaxurl, type: 'POST', data: formData, processData: false, contentType: false,
                success: res => resolve(res),
                error: () => resolve({ success: false, data: { message: 'Error de conexión' } })
            });
        });
    }

    function previewOneFile(file) {
        return new Promise((resolve) => {
            const fd = new FormData();
            fd.append('action', 'riverso_preview_invoice_xml');
            fd.append('nonce', nonce);
            fd.append('xml_file', file);
            $.ajax({
                url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
                success: res => resolve(res),
                error: () => resolve({ success: false, data: { message: 'Error de conexión' } })
            });
        });
    }

    $('#btn-start-bulk').on('click', async function() {
        if (!bulkFiles.length) return;
        const btn = $(this);
        btn.prop('disabled', true);
        $('#upload-result').html('<div class="notice notice-info" style="padding:10px;">Procesando carga masiva…</div>');
        let ok = 0, err = 0;

        for (let i = 0; i < bulkFiles.length; i++) {
            const file = bulkFiles[i];
            const $row = $(`.bulk-queue-item[data-idx="${i}"]`).addClass('run');
            $row.find('.bulk-status').text('Analizando…');

            const preview = await previewOneFile(file);
            if (!preview.success) {
                $row.removeClass('run').addClass('err');
                $row.find('.bulk-status').text(preview.data?.message || 'Error preview');
                err++;
                continue;
            }

            const det = preview.data.detection || {};
            const tipo = det.tipo === 'envio' ? 'envio' : 'productos';

            const emisor = preview.data.emisor || {};
            $row.find('.bulk-status').text('Subiendo…');
            const upload = await uploadOneFile(file, {
                documento_tipo: tipo,
                modo_ingreso: tipo === 'envio' ? 'solo_costos' : ($('input[name="modo_ingreso"]:checked').val() || '<?php echo esc_js($default_intake_mode); ?>'),
                proveedor_modo: 'xml',
                proveedor_nombre: emisor.razon_social || '',
                proveedor_rut: emisor.rut || '',
                link_to_factura_id: ''
            });

            if (upload.success) {
                $row.removeClass('run').addClass('ok');
                const note = tipo === 'envio' ? ' (sin asignar)' : '';
                $row.find('.bulk-status').text('✓ Folio ' + (upload.data?.resumen?.folio || '') + note);
                ok++;
            } else {
                $row.removeClass('run').addClass('err');
                $row.find('.bulk-status').text(upload.data?.message || 'Error');
                err++;
            }
        }

        $('#upload-result').html(`<div class="notice notice-success" style="padding:10px;"><strong>Carga masiva terminada:</strong> ${ok} OK, ${err} con error/omitidos.</div>`);
        loadInvoices(1);
        btn.prop('disabled', false);
    });

    let previewData = null;

    function fillProveedorForm(emisor, existing) {
        const data = existing || {};
        $('#prov-rut').val(data.rut || emisor?.rut || '');
        $('#prov-nombre').val(data.nombre || emisor?.razon_social || '');
        $('#prov-giro').val(data.giro || emisor?.giro || '');
        $('#prov-comuna').val(data.comuna || emisor?.comuna || '');
        $('#prov-direccion').val(data.direccion || emisor?.direccion || '');
    }

    function setProveedorUiMode() {
        const modo = $('input[name="proveedor_modo"]:checked').val();
        $('#proveedor-select-wrap').toggle(modo === 'existente');
        $('#proveedor-form-wrap').toggle(modo !== 'existente');
        if (modo === 'xml' && previewData) {
            fillProveedorForm(previewData.emisor, previewData.proveedor_existente);
            $('#proveedor-status').text(
                previewData.proveedor_existente
                    ? '✓ Proveedor encontrado — se actualizará con datos del XML'
                    : 'Proveedor nuevo — se creará al confirmar'
            );
        }
        if (modo === 'nuevo') {
            $('#prov-rut').prop('readonly', false);
        } else {
            $('#prov-rut').prop('readonly', true);
        }
    }

    $('input[name="proveedor_modo"]').on('change', setProveedorUiMode);

    function updateTipoUi() {
        const tipo = $('input[name="documento_tipo"]:checked').val();
        const isEnvio = tipo === 'envio';
        $('#link-factura-wrap').toggle(isEnvio);
        $('#opciones-productos-wrap').toggle(!isEnvio);
    }

    $('input[name="documento_tipo"]').on('change', updateTipoUi);

    function renderInlineGaps(d) {
        const gaps = d.missing_gaps || [];
        const $banner = $('#intake-gaps-inline');
        if (!gaps.length) {
            $banner.hide().empty();
            return;
        }
        gaps.forEach(applyGapToForm);
        const items = gaps.map(g => `<li>${g.message || g.label || ''}</li>`).join('');
        $banner.html(
            `<strong style="color:#9a6700;">Complete estos datos antes de confirmar:</strong><ul style="margin:6px 0 0 18px;">${items}</ul>`
        ).show();
    }

    function showConfirmStep(d) {
        previewData = d;
        const det = d.detection || {};
        const tipoSugerido = det.tipo === 'mixto' ? 'productos' : (det.tipo || 'productos');

        $('#xml-preview-summary').html(
            `<strong>${d.emisor?.razon_social || '—'}</strong> · RUT ${d.emisor?.rut || '—'}<br>` +
            `Folio <strong>${d.folio}</strong> · Fecha ${d.fecha_emision || '—'} · Total <strong>$${Number(d.total || 0).toLocaleString('es-CL')}</strong>`
        );

        const $tbody = $('#xml-preview-items tbody').empty();
        const items = d.items_preview || [];
        if (!items.length) {
            $tbody.append('<tr><td colspan="4" style="text-align:center;color:#666;">Sin líneas de detalle en el XML</td></tr>');
        } else {
            items.slice(0, 15).forEach(it => {
                const badge = it.tipo === 'envio'
                    ? '<span style="color:#b45309;">Flete</span>'
                    : '<span style="color:#15803d;">Producto</span>';
                $tbody.append(`<tr>
                    <td>${it.linea}</td>
                    <td>${it.nombre}</td>
                    <td>${badge}</td>
                    <td style="text-align:right;">$${Number(it.monto || 0).toLocaleString('es-CL')}</td>
                </tr>`);
            });
            if (items.length > 15) {
                $tbody.append(`<tr><td colspan="4" style="text-align:center;color:#666;">… y ${items.length - 15} líneas más</td></tr>`);
            }
        }

        $('#detection-motivo').html(
            `<span class="dashicons dashicons-lightbulb" style="color:#dba617;"></span> ` +
            `<strong>Detección (${det.confianza || '—'}):</strong> ${det.motivo || ''}`
        );

        const $tipoRadio = $(`input[name="documento_tipo"][value="${tipoSugerido}"]`);
        if ($tipoRadio.length) {
            $tipoRadio.prop('checked', true);
        } else {
            $('input[name="documento_tipo"][value="productos"]').prop('checked', true);
        }
        updateTipoUi();

        const $link = $('#link-factura-productos-id').empty()
            .append('<option value="">— Dejar sin asignar por ahora —</option>');
        (d.facturas_productos || []).forEach(f => {
            $link.append(`<option value="${f.id}">Folio ${f.folio} — ${f.proveedor_nombre || 'Sin prov.'} — $${Number(f.monto_total || 0).toLocaleString('es-CL')}</option>`);
        });

        const $sel = $('#proveedor-existente-id').empty().append('<option value="">— Seleccionar —</option>');
        (d.proveedores || []).forEach(p => {
            $sel.append(`<option value="${p.id}">${p.nombre} (${p.rut})</option>`);
        });
        if (d.proveedor_existente) $sel.val(d.proveedor_existente.id);

        fillProveedorForm(d.emisor, d.proveedor_existente);
        setProveedorUiMode();

        if ((d.missing_gaps || []).some(g => g.field === 'nombre' && !d.proveedor_existente)) {
            $('input[name="proveedor_modo"][value="nuevo"]').prop('checked', true);
            setProveedorUiMode();
        }

        renderInlineGaps(d);

        $('#upload-step-select').hide();
        $('#upload-step-confirm').show();
        $('#upload-modal-content').addClass('upload-modal-wide');
        $('#btn-change-xml, #btn-process-upload').show();
        $('#btn-process-upload').prop('disabled', false);

        const $modalContent = $('#upload-modal-content');
        if ($modalContent.length) {
            $modalContent.scrollTop(0);
        }
    }

    function previewXmlFile() {
        if (!fileInput[0].files.length) return;
        const fd = new FormData();
        fd.append('action', 'riverso_preview_invoice_xml');
        fd.append('nonce', nonce);
        fd.append('xml_file', fileInput[0].files[0]);

        $('#upload-file-name').html('<span class="spinner is-active" style="float:none;margin-right:6px;"></span> Analizando: ' + fileInput[0].files[0].name + '…');
        $('#btn-process-upload').prop('disabled', true);

        $.ajax({
            url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false,
            success: function(res) {
                if (!res.success) {
                    $('#upload-file-name').html('<span style="color:#d63638;">' + (res.data?.message || 'Error al leer XML') + '</span>');
                    return;
                }
                previewData = res.data;
                $('#upload-file-name').text('Archivo: ' + fileInput[0].files[0].name);
                showConfirmStep(res.data);
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.data?.message || 'Error de conexión al analizar XML';
                $('#upload-file-name').html('<span style="color:#d63638;">' + msg + '</span>');
            }
        });
    }
    
    fileInput.on('change', function() {
        if (this.files.length) previewXmlFile();
    });
    
    function appendProveedorFields(formData) {
        const provModo = $('input[name="proveedor_modo"]:checked').val();
        formData.append('proveedor_modo', provModo || 'xml');
        if (provModo === 'existente') {
            formData.append('proveedor_id', $('#proveedor-existente-id').val() || '');
        } else {
            formData.append('proveedor_nombre', $('#prov-nombre').val());
            formData.append('proveedor_giro', $('#prov-giro').val());
            formData.append('proveedor_direccion', $('#prov-direccion').val());
            formData.append('proveedor_comuna', $('#prov-comuna').val());
            formData.append('proveedor_rut', $('#prov-rut').val());
        }
    }

    let pendingUploadAfterGaps = false;

    function applyGapToForm(gap) {
        if (gap.type === 'supplier') {
            if (gap.field === 'proveedor_id') {
                $('input[name="proveedor_modo"][value="existente"]').prop('checked', true);
            } else {
                $('input[name="proveedor_modo"][value="nuevo"]').prop('checked', true);
            }
            setProveedorUiMode();
            if (gap.field === 'nombre') $('#prov-nombre').focus();
            if (gap.field === 'rut') {
                $('#prov-rut').prop('readonly', false).focus();
            }
        }
        if (gap.type === 'link_factura') {
            $('input[name="documento_tipo"][value="envio"]').prop('checked', true);
            updateTipoUi();
            $('#link-factura-productos-id').focus();
        }
    }

    function showMissingDataModal(payload, retryUpload) {
        pendingUploadAfterGaps = !!retryUpload;
        const gaps = payload.gaps || [];
        const $fields = $('#intake-missing-fields').empty();

        gaps.forEach(gap => {
            $fields.append(`<div class="intake-gap-block" data-type="${gap.type}" data-field="${gap.field}" style="margin-bottom:14px;padding:10px;background:#fff8e5;border-radius:4px;">
                <strong>${gap.label || gap.field}</strong>
                <p class="description" style="margin:4px 0 8px;">${gap.message || ''}</p>
            </div>`);
        });

        gaps.forEach(applyGapToForm);

        if (!$('#upload-step-confirm').is(':visible') && previewData) {
            showConfirmStep(previewData);
        } else if (previewData) {
            renderInlineGaps({ missing_gaps: gaps });
        }

        showRiversoModal($('#modal-intake-missing'));
    }

    $('#btn-intake-missing-cancel, #modal-intake-missing .riverso-modal-close').on('click', function() {
        hideRiversoModal($('#modal-intake-missing'));
        pendingUploadAfterGaps = false;
    });

    $('#btn-intake-missing-save').on('click', function() {
        hideRiversoModal($('#modal-intake-missing'));
        if (pendingUploadAfterGaps) {
            pendingUploadAfterGaps = false;
            $('#btn-process-upload').trigger('click');
        }
    });

    function handleIntakeGapsIfAny(d, retryUpload) {
        if (d.missing_gaps && d.missing_gaps.length) {
            showMissingDataModal({ gaps: d.missing_gaps }, retryUpload);
            return true;
        }
        return false;
    }
    
    $('#btn-process-upload').on('click', function() {
        const tipo = $('input[name="documento_tipo"]:checked').val();

        const btn = $(this);
        btn.prop('disabled', true).text('Procesando...');
        
        const formData = new FormData();
        formData.append('action', 'riverso_upload_invoice');
        formData.append('nonce', nonce);
        formData.append('documento_tipo', tipo);
        formData.append('modo_ingreso', $('input[name="modo_ingreso"]:checked').val() || 'recepcion');
        formData.append('link_to_factura_id', $('#link-factura-productos-id').val() || '');
        formData.append('xml_file', fileInput[0].files[0]);
        appendProveedorFields(formData);
        
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
                            Folio: ${response.data.resumen.folio}
                            ${response.data.resumen.documento_tipo === 'envio'
                                ? (response.data.resumen.vinculado_a_factura
                                    ? '<br>✓ Flete vinculado a factura de productos'
                                    : '<br>⏳ Flete guardado sin asignar — vincúlelo desde el detalle')
                                : ''}
                            ${response.data.resumen.items ? '<br>Productos: ' + response.data.resumen.items : ''}
                            ${response.data.resumen.items_envio ? ' · Líneas flete: ' + response.data.resumen.items_envio : ''}
                            ${response.data.modo_ingreso === 'solo_costos' && response.data.resumen.documento_tipo !== 'envio' ? `<br>Costos: ${response.data.resumen.costos_registrados || 0} · Pendientes: ${response.data.resumen.costos_pendientes || 0}` : ''}
                        </div>
                    `);
                    loadInvoices(1);
                    setTimeout(() => hideRiversoModal($('#modal-upload-invoice')), 2000);
                } else {
                    if (response.data?.needs_input) {
                        showMissingDataModal(response.data, true);
                        result.html(`<div class="notice notice-warning" style="padding: 10px;">${response.data.message}</div>`);
                    } else {
                        result.html(`<div class="notice notice-error" style="padding: 10px;">${response.data.message}</div>`);
                    }
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Confirmar y procesar');
            }
        });
    });
    
    // Eliminar subida
    $(document).on('click', '.btn-delete-invoice', function() {
        const btn = $(this);
        const id = btn.data('id');
        const folio = btn.data('folio');
        if (!confirm(`¿Eliminar la factura folio ${folio}?\n\nSe revertirá la subida, ítems, costos y tareas asociadas. Esta acción quedará registrada en auditoría.`)) {
            return;
        }
        btn.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'riverso_delete_invoice',
            nonce: nonce,
            factura_id: id
        }, function(response) {
            if (response.success) {
                loadInvoices(1);
            } else {
                alert(response.data?.message || 'Error al eliminar');
                btn.prop('disabled', false);
            }
        }).fail(function() {
            alert('Error de conexión');
            btn.prop('disabled', false);
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
    
    let currentDetailFacturaId = null;

    function showInvoiceDetail(factura) {
        currentDetailFacturaId = factura.id;
        $('#detail-folio').text('#' + factura.folio);
        $('#detail-proveedor').text(factura.proveedor_nombre);
        $('#detail-rut').text(factura.proveedor_rut);
        $('#detail-fecha').text(factura.fecha_emision);
        $('#detail-total').text('$' + parseInt(factura.monto_total).toLocaleString('es-CL'));

        const isEnvio = factura.documento_subtipo === 'envio';
        const $shippingSection = $('#detail-shipping-section');
        const $envioSection = $('#detail-envio-assign-section');

        $shippingSection.hide();
        $envioSection.hide();

        if (isEnvio) {
            $envioSection.show();
            const vinculadas = factura.facturas_productos_vinculadas || [];
            const $target = $('#detail-envio-target-id').empty()
                .append('<option value="">— Seleccionar factura de productos —</option>');
            (factura.facturas_productos_disponibles || []).forEach(f => {
                if (!vinculadas.some(v => String(v.id) === String(f.id))) {
                    $target.append(`<option value="${f.id}">Folio ${f.folio} — ${f.proveedor_nombre || ''} — $${Number(f.monto_total || 0).toLocaleString('es-CL')}</option>`);
                }
            });

            if (vinculadas.length) {
                let html = '<ul style="margin:0;padding-left:18px;">';
                vinculadas.forEach(fp => {
                    html += `<li>Folio <strong>${fp.folio}</strong> — ${fp.proveedor_nombre || ''} — $${Number(fp.monto_total || 0).toLocaleString('es-CL')}`;
                    if (fp.monto_asignado) {
                        html += ` <span class="description">(flete asignado: $${Number(fp.monto_asignado).toLocaleString('es-CL')})</span>`;
                    }
                    html += `<button type="button" class="button button-small btn-unassign-producto" data-productos-id="${fp.id}" style="margin-left:8px;">Desvincular</button></li>`;
                });
                html += '</ul>';
                $('#detail-envio-linked-info').html(html).show();
                $('#btn-envio-unassign').toggle(vinculadas.length > 1).show();
            } else {
                $('#detail-envio-linked-info').hide().empty();
                $('#btn-envio-unassign').hide();
            }
            $('#detail-envio-target-id').closest('div').show();
            $('#btn-envio-assign').show();
        } else {
            const fletes = factura.fletes_vinculados || [];
            if (fletes.length || (factura.fletes_sin_vincular || []).length) {
                $shippingSection.show();
                let html = '';
                if (fletes.length) {
                    html += '<ul style="margin:0;padding-left:18px;">';
                    fletes.forEach(fl => {
                        html += `<li>Folio <strong>${fl.folio}</strong> — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}
                            <button type="button" class="button button-small btn-unassign-flete" data-envio-id="${fl.id}" style="margin-left:8px;">Desvincular</button></li>`;
                    });
                    html += '</ul>';
                    if (factura.costo_envio_vinculado) {
                        html += `<p class="description" style="margin:8px 0 0;">Total fletes vinculados: <strong>$${Number(factura.costo_envio_vinculado).toLocaleString('es-CL')}</strong></p>`;
                    }
                } else {
                    html = '<p class="description" style="margin:0;">Sin fletes vinculados.</p>';
                }
                $('#detail-shipping-linked').html(html);

                const pendientes = factura.fletes_sin_vincular || [];
                const $assignWrap = $('#detail-shipping-assign');
                const $sel = $('#detail-assign-flete-id').empty();
                if (pendientes.length) {
                    $assignWrap.show();
                    $sel.append('<option value="">— Seleccionar flete pendiente —</option>');
                    pendientes.forEach(fl => {
                        $sel.append(`<option value="${fl.id}">Folio ${fl.folio} — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}</option>`);
                    });
                } else {
                    $assignWrap.hide();
                }
            }
        }
        
        const tbody = $('#detail-items');
        tbody.empty();
        
        factura.items.forEach(function(item) {
            const row = $('<tr>');
            row.html(`
                <td>${item.linea}</td>
                <td><code>${item.codigo_proveedor || '-'}</code></td>
                <td class="col-desc">${item.descripcion}</td>
                <td style="text-align: right;">${item.cantidad}</td>
                <td style="text-align: right;">$${parseInt(item.precio_unitario).toLocaleString('es-CL')}</td>
                <td style="text-align: right;">$${parseInt(item.monto_total).toLocaleString('es-CL')}</td>
                <td>${item.sku_local ||
                    `<div class="link-sku-input">
                        <input type="text" class="sku-input" placeholder="SKU local">
                        <button class="button button-small btn-link-sku" data-item="${item.id}">OK</button>
                    </div>`
                }</td>
                <td><code style="color:#666;">${item.sku_online || '—'}</code></td>
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
        
        $('#modal-invoice-detail').css('display', 'flex');
    }

    function reloadInvoiceDetail() {
        if (!currentDetailFacturaId) return;
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: currentDetailFacturaId
        }, function(response) {
            if (response.success) showInvoiceDetail(response.data);
        });
    }

    $('#btn-assign-flete').on('click', function() {
        const envioId = $('#detail-assign-flete-id').val();
        if (!envioId) { alert('Seleccione un flete'); return; }
        $.post(ajaxurl, {
            action: 'riverso_assign_shipping_invoice',
            nonce: nonce,
            factura_productos_id: currentDetailFacturaId,
            factura_envio_id: envioId
        }, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al vincular');
            }
        });
    });

    $('#btn-envio-assign').on('click', function() {
        const targetId = $('#detail-envio-target-id').val();
        if (!targetId) { alert('Seleccione la factura de productos'); return; }
        $.post(ajaxurl, {
            action: 'riverso_assign_shipping_invoice',
            nonce: nonce,
            factura_productos_id: targetId,
            factura_envio_id: currentDetailFacturaId
        }, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error al vincular');
            }
        });
    });

    $(document).on('click', '.btn-unassign-flete, #btn-envio-unassign, .btn-unassign-producto', function() {
        const envioId = $(this).data('envio-id') || currentDetailFacturaId;
        const productosId = $(this).data('productos-id') || null;
        const isEnvioDetail = $('#detail-envio-assign-section').is(':visible');
        const msg = productosId
            ? '¿Desvincular esta factura de productos del flete?'
            : (isEnvioDetail ? '¿Desvincular este flete de TODAS las facturas de productos?' : '¿Desvincular este flete de la factura?');
        if (!confirm(msg)) return;
        const payload = {
            action: 'riverso_unassign_shipping_invoice',
            nonce: nonce,
            factura_envio_id: envioId
        };
        if (productosId) {
            payload.factura_productos_id = productosId;
        } else if (!isEnvioDetail && currentDetailFacturaId) {
            payload.factura_productos_id = currentDetailFacturaId;
        }
        $.post(ajaxurl, payload, function(res) {
            if (res.success) {
                reloadInvoiceDetail();
                loadInvoices();
            } else {
                alert(res.data?.message || 'Error');
            }
        });
    });
    
    $('#btn-close-detail').on('click', function() {
        $('#modal-invoice-detail').hide();
        currentDetailFacturaId = null;
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
