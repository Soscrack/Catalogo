<?php
/**
 * Template: Recepción de Facturas
 * Interface para el proceso de recepción física de mercadería
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permisos
if (!current_user_can('riverso_receive_items')) {
    wp_die('No tienes permisos para acceder a esta página.');
}

global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';

// Estadísticas
$stats = [
    'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado IN ('uploaded', 'pending_reception', 'recibido')"),
    'in_reception' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'in_reception'"),
    'pending_approval' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pending_approval'"),
];
?>

<div class="wrap riverso-reception">
    <h1>
        <span class="dashicons dashicons-clipboard"></span>
        Recepción de Mercadería
    </h1>
    
    <!-- Stats Cards -->
    <div class="reception-stats">
        <div class="stat-card pending">
            <span class="stat-number"><?php echo $stats['pending']; ?></span>
            <span class="stat-label">Pendientes</span>
        </div>
        <div class="stat-card in-progress">
            <span class="stat-number"><?php echo $stats['in_reception']; ?></span>
            <span class="stat-label">En Recepción</span>
        </div>
        <div class="stat-card waiting">
            <span class="stat-number"><?php echo $stats['pending_approval']; ?></span>
            <span class="stat-label">Por Aprobar</span>
        </div>
    </div>
    
    <!-- Búsqueda rápida -->
    <div class="search-section">
        <h2>Buscar Factura para Recibir</h2>
        <div class="search-row">
            <div class="search-field">
                <label for="search-folio">N° Factura (Folio)</label>
                <input type="text" id="search-folio" placeholder="Ej: 12345">
            </div>
            <div class="search-field">
                <label for="search-proveedor">Proveedor</label>
                <input type="text" id="search-proveedor" placeholder="Nombre o RUT">
            </div>
            <button type="button" class="button button-primary" id="btn-search">
                <span class="dashicons dashicons-search"></span> Buscar
            </button>
        </div>
        
        <div id="search-results" style="display: none;">
            <h3>Resultados</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Proveedor</th>
                        <th>Fecha</th>
                        <th>Total</th>
                        <th>Items</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="search-results-body"></tbody>
            </table>
        </div>
    </div>
    
    <!-- Facturas en recepción activa -->
    <?php if ($stats['in_reception'] > 0): ?>
    <div class="active-receptions">
        <h2>Recepciones en Proceso</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Proveedor</th>
                    <th>Iniciada</th>
                    <th>Por</th>
                    <th>Progreso</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $in_progress = $wpdb->get_results(
                    "SELECT f.*, p.nombre as proveedor_nombre, u.display_name as started_by_name,
                            (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id) as total_items,
                            (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id AND item_status != 'pending') as done_items
                     FROM {$prefix}facturas f
                     JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                     LEFT JOIN {$wpdb->users} u ON f.reception_started_by = u.ID
                     WHERE f.estado = 'in_reception'
                     ORDER BY f.reception_started_at DESC"
                );
                foreach ($in_progress as $inv):
                    $progress = $inv->total_items > 0 ? round(($inv->done_items / $inv->total_items) * 100) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html($inv->folio); ?></strong></td>
                    <td><?php echo esc_html($inv->proveedor_nombre); ?></td>
                    <td><?php echo esc_html($inv->reception_started_at); ?></td>
                    <td><?php echo esc_html($inv->started_by_name); ?></td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                        <span class="progress-text"><?php echo $inv->done_items; ?>/<?php echo $inv->total_items; ?> (<?php echo $progress; ?>%)</span>
                    </td>
                    <td>
                        <button type="button" class="button btn-continue-reception" data-id="<?php echo $inv->id; ?>">
                            Continuar
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- Facturas pendientes de aprobación -->
    <?php if ($stats['pending_approval'] > 0 && current_user_can('riverso_approve_invoices')): ?>
    <div class="pending-approval">
        <h2>Pendientes de Aprobación</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Proveedor</th>
                    <th>Recepción Completada</th>
                    <th>Total Items</th>
                    <th>Con Discrepancias</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pending_approval = $wpdb->get_results(
                    "SELECT f.*, p.nombre as proveedor_nombre,
                            (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id) as total_items,
                            (SELECT COUNT(*) FROM {$prefix}factura_items WHERE factura_id = f.id AND item_status IN ('missing', 'extra', 'modified', 'rejected')) as issue_items
                     FROM {$prefix}facturas f
                     JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                     WHERE f.estado = 'pending_approval'
                     ORDER BY f.reception_completed_at DESC"
                );
                foreach ($pending_approval as $inv):
                ?>
                <tr>
                    <td><strong><?php echo esc_html($inv->folio); ?></strong></td>
                    <td><?php echo esc_html($inv->proveedor_nombre); ?></td>
                    <td><?php echo esc_html($inv->reception_completed_at); ?></td>
                    <td><?php echo $inv->total_items; ?></td>
                    <td>
                        <?php if ($inv->issue_items > 0): ?>
                            <span class="badge warning"><?php echo $inv->issue_items; ?> items</span>
                        <?php else: ?>
                            <span class="badge success">Sin discrepancias</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button btn-review-approval" data-id="<?php echo $inv->id; ?>">
                            Revisar
                        </button>
                        <button type="button" class="button button-primary btn-quick-approve" data-id="<?php echo $inv->id; ?>">
                            Aprobar
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal: Recepción de Items -->
<div id="modal-reception" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content riverso-modal-xlarge">
        <div class="riverso-modal-header">
            <h2>Recepción - Factura <span id="reception-folio"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="reception-info">
                <div class="info-item">
                    <label>Proveedor:</label>
                    <span id="reception-proveedor"></span>
                </div>
                <div class="info-item">
                    <label>Fecha Factura:</label>
                    <span id="reception-fecha"></span>
                </div>
                <div class="info-item">
                    <label>Total:</label>
                    <span id="reception-total"></span>
                </div>
            </div>
            
            <div class="reception-progress">
                <div class="progress-bar large">
                    <div class="progress" id="reception-progress-bar" style="width: 0%"></div>
                </div>
                <span id="reception-progress-text">0/0 items procesados</span>
            </div>
            
            <table class="wp-list-table widefat fixed" id="reception-items-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th style="width: 100px;">Código</th>
                        <th>Descripción</th>
                        <th style="width: 80px;">Facturado</th>
                        <th style="width: 80px;">Recibido</th>
                        <th style="width: 120px;">Estado</th>
                        <th style="width: 150px;">Notas</th>
                        <th style="width: 100px;">Acción</th>
                    </tr>
                </thead>
                <tbody id="reception-items-body"></tbody>
            </table>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-reception">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-complete-reception" disabled>
                <span class="dashicons dashicons-yes"></span> Completar Recepción
            </button>
        </div>
    </div>
</div>

<!-- Modal: Aprobación -->
<div id="modal-approval" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content riverso-modal-large">
        <div class="riverso-modal-header">
            <h2>Aprobar Factura <span id="approval-folio"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="approval-summary" id="approval-summary">
                <!-- Filled by JS -->
            </div>
            
            <div class="approval-notes">
                <label for="approval-notes">Notas de aprobación:</label>
                <textarea id="approval-notes" rows="3" placeholder="Observaciones opcionales..."></textarea>
            </div>
            
            <div class="approval-effects">
                <h4>Al aprobar se ejecutará:</h4>
                <ul>
                    <li>✓ Registro de costos en historial</li>
                    <li>✓ Generación de tareas de etiquetado</li>
                    <li>✓ Tareas de vinculación para códigos sin match</li>
                </ul>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="btn-cancel-approval">Cancelar</button>
            <button type="button" class="button button-primary" id="btn-confirm-approval">
                <span class="dashicons dashicons-yes-alt"></span> Confirmar Aprobación
            </button>
        </div>
    </div>
</div>

<style>
.riverso-reception h1 {
    display: flex;
    align-items: center;
    gap: 10px;
}

.reception-stats {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px 30px;
    text-align: center;
    min-width: 150px;
}

.stat-card .stat-number {
    display: block;
    font-size: 32px;
    font-weight: 600;
    line-height: 1;
}

.stat-card .stat-label {
    display: block;
    font-size: 13px;
    color: #646970;
    margin-top: 5px;
}

.stat-card.pending .stat-number { color: #996800; }
.stat-card.in-progress .stat-number { color: #2271b1; }
.stat-card.waiting .stat-number { color: #00a32a; }

.search-section, .active-receptions, .pending-approval {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.search-section h2, .active-receptions h2, .pending-approval h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f1;
}

.search-row {
    display: flex;
    gap: 15px;
    align-items: flex-end;
    flex-wrap: wrap;
}

.search-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.search-field label {
    font-size: 12px;
    font-weight: 500;
    color: #646970;
}

.search-field input {
    width: 200px;
    padding: 8px 12px;
}

#search-results {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f0f0f1;
}

.progress-bar {
    background: #f0f0f1;
    border-radius: 3px;
    height: 8px;
    overflow: hidden;
}

.progress-bar.large {
    height: 12px;
}

.progress-bar .progress {
    background: #2271b1;
    height: 100%;
    transition: width 0.3s ease;
}

.progress-text {
    font-size: 12px;
    color: #646970;
}

.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.badge.warning { background: #fcf0e3; color: #996800; }
.badge.success { background: #edfaef; color: #00a32a; }
.badge.danger { background: #fcf0f1; color: #d63638; }

/* Modal styles */
.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.riverso-modal-content {
    background: #fff;
    border-radius: 4px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.riverso-modal-large { max-width: 900px; }
.riverso-modal-xlarge { max-width: 1100px; }

.riverso-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ccd0d4;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.riverso-modal-header h2 { margin: 0; }

.riverso-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #646970;
}

.riverso-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.riverso-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ccd0d4;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.reception-info {
    display: flex;
    gap: 30px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f1;
}

.reception-info .info-item label {
    font-size: 12px;
    color: #646970;
    display: block;
}

.reception-info .info-item span {
    font-weight: 500;
}

.reception-progress {
    margin-bottom: 20px;
}

#reception-items-table td {
    vertical-align: middle;
}

.item-status-select {
    width: 100%;
    padding: 4px;
}

.item-qty-input {
    width: 60px;
    padding: 4px;
    text-align: center;
}

.item-notes-input {
    width: 100%;
    padding: 4px;
    font-size: 12px;
}

.item-row.processed {
    background: #f0f6fc;
}

.item-row.has-issue {
    background: #fcf0f1;
}

.status-pending { color: #646970; }
.status-received_ok { color: #00a32a; }
.status-modified { color: #996800; }
.status-missing { color: #d63638; }
.status-extra { color: #9b59b6; }
.status-rejected { color: #d63638; }
.status-approved { color: #00a32a; font-weight: 600; }

.approval-summary {
    background: #f0f6fc;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.approval-notes {
    margin-bottom: 20px;
}

.approval-notes label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.approval-notes textarea {
    width: 100%;
}

.approval-effects {
    background: #edfaef;
    padding: 15px;
    border-radius: 4px;
}

.approval-effects h4 {
    margin: 0 0 10px 0;
}

.approval-effects ul {
    margin: 0;
    padding-left: 20px;
}
</style>

<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let currentInvoiceId = null;
    let currentItems = [];
    
    // Búsqueda de facturas
    $('#btn-search').on('click', function() {
        const folio = $('#search-folio').val();
        const proveedor = $('#search-proveedor').val();
        
        if (!folio && !proveedor) {
            alert('Ingrese folio o proveedor para buscar');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_search_invoice',
            nonce: nonce,
            folio: folio,
            proveedor: proveedor
        }, function(response) {
            if (response.success) {
                renderSearchResults(response.data.invoices);
            } else {
                alert(response.data.message || 'Error en búsqueda');
            }
        });
    });
    
    function renderSearchResults(invoices) {
        const $results = $('#search-results');
        const $tbody = $('#search-results-body');
        
        if (invoices.length === 0) {
            $tbody.html('<tr><td colspan="7" style="text-align:center">No se encontraron facturas</td></tr>');
        } else {
            let html = '';
            invoices.forEach(function(inv) {
                const canStart = ['uploaded', 'pending_reception', 'recibido'].includes(inv.estado);
                html += `<tr>
                    <td><strong>${inv.folio}</strong></td>
                    <td>${inv.proveedor_nombre}</td>
                    <td>${inv.fecha_emision}</td>
                    <td>$${formatNumber(inv.monto_total)}</td>
                    <td>${inv.total_items} items</td>
                    <td><span class="badge ${inv.estado}">${inv.estado}</span></td>
                    <td>
                        ${canStart ? 
                            `<button type="button" class="button button-primary btn-start-reception" data-id="${inv.id}">Iniciar Recepción</button>` :
                            (inv.estado === 'in_reception' ? 
                                `<button type="button" class="button btn-continue-reception" data-id="${inv.id}">Continuar</button>` : 
                                '-')}
                    </td>
                </tr>`;
            });
            $tbody.html(html);
        }
        
        $results.show();
    }
    
    // Iniciar recepción
    $(document).on('click', '.btn-start-reception', function() {
        const invoiceId = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_start_reception',
            nonce: nonce,
            factura_id: invoiceId
        }, function(response) {
            if (response.success) {
                loadReceptionModal(invoiceId);
            } else {
                alert(response.data.message || 'Error al iniciar recepción');
            }
        });
    });
    
    // Continuar recepción
    $(document).on('click', '.btn-continue-reception', function() {
        const invoiceId = $(this).data('id');
        loadReceptionModal(invoiceId);
    });
    
    function loadReceptionModal(invoiceId) {
        currentInvoiceId = invoiceId;
        
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: invoiceId
        }, function(response) {
            if (response.success) {
                const data = response.data;
                currentItems = data.items;
                
                $('#reception-folio').text(data.folio);
                $('#reception-proveedor').text(data.proveedor_nombre);
                $('#reception-fecha').text(data.fecha_emision);
                $('#reception-total').text('$' + formatNumber(data.monto_total));
                
                renderReceptionItems(data.items);
                updateProgress();
                
                $('#modal-reception').show();
            }
        });
    }
    
    function renderReceptionItems(items) {
        const $tbody = $('#reception-items-body');
        let html = '';
        
        items.forEach(function(item, idx) {
            const status = item.item_status || 'pending';
            const isProcessed = status !== 'pending';
            const hasIssue = ['missing', 'extra', 'modified', 'rejected'].includes(status);
            const rowClass = isProcessed ? (hasIssue ? 'item-row has-issue' : 'item-row processed') : 'item-row';
            
            html += `<tr class="${rowClass}" data-item-id="${item.id}">
                <td>${item.linea}</td>
                <td><code>${item.codigo_proveedor || '-'}</code></td>
                <td>${item.descripcion}</td>
                <td style="text-align:center">${item.cantidad}</td>
                <td>
                    <input type="number" class="item-qty-input" value="${item.qty_received || item.cantidad}" 
                           step="0.01" min="0" data-original="${item.cantidad}">
                </td>
                <td>
                    <select class="item-status-select">
                        <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pendiente</option>
                        <option value="received_ok" ${status === 'received_ok' ? 'selected' : ''}>Recibido OK</option>
                        <option value="modified" ${status === 'modified' ? 'selected' : ''}>Modificado</option>
                        <option value="missing" ${status === 'missing' ? 'selected' : ''}>Faltante</option>
                        <option value="extra" ${status === 'extra' ? 'selected' : ''}>Sobrante</option>
                        <option value="rejected" ${status === 'rejected' ? 'selected' : ''}>Rechazado</option>
                    </select>
                </td>
                <td>
                    <input type="text" class="item-notes-input" value="${item.item_notes || ''}" placeholder="Notas...">
                </td>
                <td>
                    <button type="button" class="button button-small btn-save-item" data-id="${item.id}">
                        <span class="dashicons dashicons-saved"></span>
                    </button>
                </td>
            </tr>`;
        });
        
        $tbody.html(html);
    }
    
    // Auto-detect status based on quantity
    $(document).on('change', '.item-qty-input', function() {
        const $row = $(this).closest('tr');
        const received = parseFloat($(this).val()) || 0;
        const original = parseFloat($(this).data('original')) || 0;
        const $status = $row.find('.item-status-select');
        
        if (received === original) {
            $status.val('received_ok');
        } else if (received === 0) {
            $status.val('missing');
        } else if (received < original) {
            $status.val('modified');
        } else if (received > original) {
            $status.val('extra');
        }
    });
    
    // Guardar item
    $(document).on('click', '.btn-save-item', function() {
        const $row = $(this).closest('tr');
        const itemId = $row.data('item-id');
        const qtyReceived = $row.find('.item-qty-input').val();
        const status = $row.find('.item-status-select').val();
        const notes = $row.find('.item-notes-input').val();
        
        const $btn = $(this);
        $btn.prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'riverso_update_item_reception',
            nonce: nonce,
            item_id: itemId,
            qty_received: qtyReceived,
            item_status: status,
            notes: notes
        }, function(response) {
            $btn.prop('disabled', false);
            
            if (response.success) {
                // Update row appearance
                $row.removeClass('item-row processed has-issue');
                $row.addClass('item-row processed');
                if (['missing', 'extra', 'modified', 'rejected'].includes(status)) {
                    $row.addClass('has-issue');
                }
                
                // Update local data
                const item = currentItems.find(i => i.id == itemId);
                if (item) {
                    item.item_status = status;
                    item.qty_received = qtyReceived;
                }
                
                updateProgress();
                
                if (response.data.all_items_processed) {
                    $('#btn-complete-reception').prop('disabled', false);
                }
            } else {
                alert(response.data.message || 'Error al guardar');
            }
        });
    });
    
    function updateProgress() {
        const total = currentItems.length;
        const done = currentItems.filter(i => i.item_status && i.item_status !== 'pending').length;
        const percent = total > 0 ? Math.round((done / total) * 100) : 0;
        
        $('#reception-progress-bar').css('width', percent + '%');
        $('#reception-progress-text').text(`${done}/${total} items procesados`);
        
        $('#btn-complete-reception').prop('disabled', done < total);
    }
    
    // Completar recepción
    $('#btn-complete-reception').on('click', function() {
        if (!confirm('¿Completar recepción? La factura pasará a estado "Pendiente de Aprobación".')) return;
        
        $.post(ajaxurl, {
            action: 'riverso_complete_reception',
            nonce: nonce,
            factura_id: currentInvoiceId
        }, function(response) {
            if (response.success) {
                alert('✓ ' + response.data.message);
                $('#modal-reception').hide();
                location.reload();
            } else {
                alert(response.data.message || 'Error al completar');
            }
        });
    });
    
    // Cerrar modal
    $('.riverso-modal-close, #btn-cancel-reception').on('click', function() {
        $('#modal-reception').hide();
    });
    
    // Aprobar factura
    $(document).on('click', '.btn-quick-approve, .btn-review-approval', function() {
        const invoiceId = $(this).data('id');
        currentInvoiceId = invoiceId;
        
        // Load invoice summary for approval
        $.post(ajaxurl, {
            action: 'riverso_get_invoice',
            nonce: nonce,
            factura_id: invoiceId
        }, function(response) {
            if (response.success) {
                const data = response.data;
                
                $('#approval-folio').text(data.folio);
                
                const items = data.items || [];
                const okItems = items.filter(i => ['received_ok', 'approved'].includes(i.item_status)).length;
                const modifiedItems = items.filter(i => i.item_status === 'modified').length;
                const missingItems = items.filter(i => i.item_status === 'missing').length;
                const rejectedItems = items.filter(i => i.item_status === 'rejected').length;
                
                let summaryHtml = `
                    <h4>Resumen de Factura ${data.folio}</h4>
                    <p><strong>Proveedor:</strong> ${data.proveedor_nombre}</p>
                    <p><strong>Total:</strong> $${formatNumber(data.monto_total)}</p>
                    <hr>
                    <p><strong>Items recibidos OK:</strong> ${okItems}</p>
                    <p><strong>Items modificados:</strong> ${modifiedItems}</p>
                    <p><strong>Items faltantes:</strong> ${missingItems}</p>
                    <p><strong>Items rechazados:</strong> ${rejectedItems}</p>
                `;
                
                $('#approval-summary').html(summaryHtml);
                $('#approval-notes').val('');
                $('#modal-approval').show();
            }
        });
    });
    
    // Confirmar aprobación
    $('#btn-confirm-approval').on('click', function() {
        const notes = $('#approval-notes').val();
        const $btn = $(this);
        
        $btn.prop('disabled', true).text('Procesando...');
        
        $.post(ajaxurl, {
            action: 'riverso_approve_invoice',
            nonce: nonce,
            factura_id: currentInvoiceId,
            notes: notes
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span> Confirmar Aprobación');
            
            if (response.success) {
                alert(`✓ ${response.data.message}\n\nCostos registrados: ${response.data.costs_recorded}\nTareas creadas: ${response.data.tasks_created}`);
                $('#modal-approval').hide();
                location.reload();
            } else {
                alert(response.data.message || 'Error al aprobar');
            }
        });
    });
    
    $('#btn-cancel-approval, #modal-approval .riverso-modal-close').on('click', function() {
        $('#modal-approval').hide();
    });
    
    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
});
</script>
