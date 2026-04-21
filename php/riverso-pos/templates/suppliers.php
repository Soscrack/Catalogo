<?php
/**
 * Template: Gestión de Proveedores
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('riverso_view_suppliers')) {
    wp_die('No tienes permisos para ver esta página.');
}

$can_edit = current_user_can('riverso_edit_suppliers');
?>

<div class="wrap riverso-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-businessman" style="margin-right: 8px;"></span>
        Proveedores
    </h1>
    
    <?php if ($can_edit): ?>
    <button type="button" class="page-title-action" id="btn-new-supplier">
        <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
        Nuevo Proveedor
    </button>
    <?php endif; ?>
    
    <hr class="wp-header-end">
    
    <!-- Filtros -->
    <div class="riverso-filters" style="margin: 20px 0; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <div>
            <label for="filter-status">Estado:</label>
            <select id="filter-status" style="margin-left: 5px;">
                <option value="all">Todos</option>
                <option value="active" selected>Activos</option>
                <option value="inactive">Inactivos</option>
            </select>
        </div>
        <div>
            <input type="text" id="filter-search" placeholder="Buscar por nombre, RUT, email..." style="width: 300px;">
        </div>
        <button type="button" class="button" id="btn-search">
            <span class="dashicons dashicons-search" style="vertical-align: middle;"></span> Buscar
        </button>
        <button type="button" class="button" id="btn-clear-filters">Limpiar</button>
    </div>
    
    <!-- Tabla -->
    <table class="wp-list-table widefat fixed striped" id="suppliers-table">
        <thead>
            <tr>
                <th style="width: 120px;">RUT</th>
                <th>Nombre</th>
                <th style="width: 180px;">Contacto</th>
                <th style="width: 150px;">Email</th>
                <th style="width: 100px;">Teléfono</th>
                <th style="width: 80px;">Códigos</th>
                <th style="width: 80px;">Estado</th>
                <th style="width: 120px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="suppliers-body">
            <tr><td colspan="8" style="text-align: center;">Cargando...</td></tr>
        </tbody>
    </table>
    
    <!-- Paginación -->
    <div id="suppliers-pagination" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
        <span id="pagination-info"></span>
        <div id="pagination-buttons"></div>
    </div>
</div>

<!-- Modal Proveedor -->
<div id="supplier-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 700px;">
        <div class="riverso-modal-header">
            <h2 id="modal-title">Nuevo Proveedor</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <form id="supplier-form">
            <input type="hidden" name="id" id="supplier-id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div>
                    <label for="supplier-rut"><strong>RUT *</strong></label>
                    <input type="text" id="supplier-rut" name="rut" required style="width: 100%;" placeholder="12345678-9">
                </div>
                <div>
                    <label for="supplier-nombre"><strong>Nombre / Razón Social *</strong></label>
                    <input type="text" id="supplier-nombre" name="nombre" required style="width: 100%;">
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label for="supplier-giro">Giro</label>
                <input type="text" id="supplier-giro" name="giro" style="width: 100%;">
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 15px; margin-top: 15px;">
                <div>
                    <label for="supplier-direccion">Dirección</label>
                    <input type="text" id="supplier-direccion" name="direccion" style="width: 100%;">
                </div>
                <div>
                    <label for="supplier-comuna">Comuna</label>
                    <input type="text" id="supplier-comuna" name="comuna" style="width: 100%;">
                </div>
                <div>
                    <label for="supplier-ciudad">Ciudad</label>
                    <input type="text" id="supplier-ciudad" name="ciudad" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 15px;">
                <div>
                    <label for="supplier-contacto">Contacto</label>
                    <input type="text" id="supplier-contacto" name="contacto" style="width: 100%;" placeholder="Nombre persona contacto">
                </div>
                <div>
                    <label for="supplier-email">Email</label>
                    <input type="email" id="supplier-email" name="email" style="width: 100%;">
                </div>
                <div>
                    <label for="supplier-telefono">Teléfono</label>
                    <input type="text" id="supplier-telefono" name="telefono" style="width: 100%;">
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label for="supplier-notas">Notas</label>
                <textarea id="supplier-notas" name="notas" rows="3" style="width: 100%;"></textarea>
            </div>
            
            <div style="margin-top: 15px;">
                <label>
                    <input type="checkbox" id="supplier-activo" name="activo" value="1" checked>
                    Proveedor activo
                </label>
            </div>
            
            <div class="riverso-modal-footer" style="margin-top: 20px;">
                <button type="button" class="button riverso-modal-close">Cancelar</button>
                <button type="submit" class="button button-primary">Guardar Proveedor</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ver Detalles -->
<div id="supplier-detail-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 800px;">
        <div class="riverso-modal-header">
            <h2 id="detail-title">Detalles del Proveedor</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div id="supplier-detail-content">
            <!-- Se llena dinámicamente -->
        </div>
    </div>
</div>

<style>
.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.riverso-modal-content {
    background: #fff;
    border-radius: 8px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
}
.riverso-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    background: #f7f7f7;
    border-radius: 8px 8px 0 0;
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
    line-height: 1;
}
.riverso-modal-close:hover {
    color: #d63638;
}
#supplier-form {
    padding: 20px;
}
.riverso-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}
#supplier-detail-content {
    padding: 20px;
}
.detail-section {
    margin-bottom: 20px;
}
.detail-section h3 {
    margin: 0 0 10px 0;
    padding-bottom: 5px;
    border-bottom: 2px solid #2271b1;
    font-size: 14px;
}
.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}
.detail-item {
    padding: 8px;
    background: #f9f9f9;
    border-radius: 4px;
}
.detail-item label {
    display: block;
    font-size: 11px;
    color: #666;
    text-transform: uppercase;
}
.detail-item span {
    font-weight: 500;
}
.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}
.status-active {
    background: #d4edda;
    color: #155724;
}
.status-inactive {
    background: #f8d7da;
    color: #721c24;
}
</style>

<script>
jQuery(document).ready(function($) {
    let currentPage = 1;
    const perPage = 25;
    
    function loadSuppliers() {
        const status = $('#filter-status').val();
        const search = $('#filter-search').val();
        
        $('#suppliers-body').html('<tr><td colspan="8" style="text-align:center;">Cargando...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'riverso_get_suppliers',
            nonce: riverso_pos.nonce,
            status: status,
            search: search,
            page: currentPage,
            per_page: perPage
        }, function(response) {
            if (response.success) {
                renderSuppliers(response.data.suppliers);
                renderPagination(response.data);
            } else {
                $('#suppliers-body').html('<tr><td colspan="8" style="text-align:center;color:#d63638;">Error: ' + (response.data?.message || 'Error desconocido') + '</td></tr>');
            }
        });
    }
    
    function renderSuppliers(suppliers) {
        if (!suppliers || suppliers.length === 0) {
            $('#suppliers-body').html('<tr><td colspan="8" style="text-align:center;">No hay proveedores</td></tr>');
            return;
        }
        
        let html = '';
        suppliers.forEach(function(s) {
            const statusClass = s.activo == 1 ? 'status-active' : 'status-inactive';
            const statusText = s.activo == 1 ? 'Activo' : 'Inactivo';
            
            html += `<tr data-id="${s.id}">
                <td><strong>${formatRut(s.rut)}</strong></td>
                <td>${escapeHtml(s.nombre)}</td>
                <td>${escapeHtml(s.contacto || '-')}</td>
                <td>${s.email ? '<a href="mailto:' + s.email + '">' + s.email + '</a>' : '-'}</td>
                <td>${escapeHtml(s.telefono || '-')}</td>
                <td style="text-align:center;">${s.codigos_count || 0}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>
                    <button type="button" class="button button-small btn-view" title="Ver detalles">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                    <?php if ($can_edit): ?>
                    <button type="button" class="button button-small btn-edit" title="Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button type="button" class="button button-small btn-toggle" title="${s.activo == 1 ? 'Desactivar' : 'Activar'}">
                        <span class="dashicons dashicons-${s.activo == 1 ? 'no' : 'yes'}"></span>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>`;
        });
        
        $('#suppliers-body').html(html);
    }
    
    function renderPagination(data) {
        $('#pagination-info').text(`Mostrando ${data.suppliers.length} de ${data.total} proveedores (Página ${data.page} de ${data.pages})`);
        
        let buttons = '';
        if (data.page > 1) {
            buttons += '<button type="button" class="button" data-page="' + (data.page - 1) + '">&laquo; Anterior</button> ';
        }
        if (data.page < data.pages) {
            buttons += '<button type="button" class="button" data-page="' + (data.page + 1) + '">Siguiente &raquo;</button>';
        }
        $('#pagination-buttons').html(buttons);
    }
    
    function formatRut(rut) {
        if (!rut) return '-';
        rut = rut.replace(/[^0-9kK]/g, '').toUpperCase();
        if (rut.length < 2) return rut;
        const dv = rut.slice(-1);
        const num = rut.slice(0, -1);
        return num.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Event handlers
    $('#btn-search').on('click', function() {
        currentPage = 1;
        loadSuppliers();
    });
    
    $('#filter-search').on('keypress', function(e) {
        if (e.which === 13) {
            currentPage = 1;
            loadSuppliers();
        }
    });
    
    $('#filter-status').on('change', function() {
        currentPage = 1;
        loadSuppliers();
    });
    
    $('#btn-clear-filters').on('click', function() {
        $('#filter-status').val('active');
        $('#filter-search').val('');
        currentPage = 1;
        loadSuppliers();
    });
    
    $('#pagination-buttons').on('click', 'button', function() {
        currentPage = $(this).data('page');
        loadSuppliers();
    });
    
    // Modal handlers
    $('#btn-new-supplier').on('click', function() {
        $('#modal-title').text('Nuevo Proveedor');
        $('#supplier-form')[0].reset();
        $('#supplier-id').val('');
        $('#supplier-activo').prop('checked', true);
        $('#supplier-modal').show();
    });
    
    $('.riverso-modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    $('.riverso-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Editar
    $('#suppliers-body').on('click', '.btn-edit', function() {
        const id = $(this).closest('tr').data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_get_supplier',
            nonce: riverso_pos.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                const s = response.data.supplier;
                $('#modal-title').text('Editar Proveedor');
                $('#supplier-id').val(s.id);
                $('#supplier-rut').val(formatRut(s.rut));
                $('#supplier-nombre').val(s.nombre);
                $('#supplier-giro').val(s.giro || '');
                $('#supplier-direccion').val(s.direccion || '');
                $('#supplier-comuna').val(s.comuna || '');
                $('#supplier-ciudad').val(s.ciudad || '');
                $('#supplier-contacto').val(s.contacto || '');
                $('#supplier-email').val(s.email || '');
                $('#supplier-telefono').val(s.telefono || '');
                $('#supplier-notas').val(s.notas || '');
                $('#supplier-activo').prop('checked', s.activo == 1);
                $('#supplier-modal').show();
            } else {
                alert('Error: ' + (response.data?.message || 'Error al cargar'));
            }
        });
    });
    
    // Ver detalles
    $('#suppliers-body').on('click', '.btn-view', function() {
        const id = $(this).closest('tr').data('id');
        
        $.post(ajaxurl, {
            action: 'riverso_get_supplier',
            nonce: riverso_pos.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                const s = response.data.supplier;
                let html = `
                    <div class="detail-section">
                        <h3>Información General</h3>
                        <div class="detail-grid">
                            <div class="detail-item"><label>RUT</label><span>${formatRut(s.rut)}</span></div>
                            <div class="detail-item"><label>Nombre</label><span>${escapeHtml(s.nombre)}</span></div>
                            <div class="detail-item"><label>Giro</label><span>${escapeHtml(s.giro || '-')}</span></div>
                            <div class="detail-item"><label>Estado</label><span class="status-badge ${s.activo == 1 ? 'status-active' : 'status-inactive'}">${s.activo == 1 ? 'Activo' : 'Inactivo'}</span></div>
                        </div>
                    </div>
                    <div class="detail-section">
                        <h3>Contacto</h3>
                        <div class="detail-grid">
                            <div class="detail-item"><label>Persona Contacto</label><span>${escapeHtml(s.contacto || '-')}</span></div>
                            <div class="detail-item"><label>Email</label><span>${s.email ? '<a href="mailto:' + s.email + '">' + s.email + '</a>' : '-'}</span></div>
                            <div class="detail-item"><label>Teléfono</label><span>${escapeHtml(s.telefono || '-')}</span></div>
                        </div>
                    </div>
                    <div class="detail-section">
                        <h3>Dirección</h3>
                        <div class="detail-grid">
                            <div class="detail-item"><label>Dirección</label><span>${escapeHtml(s.direccion || '-')}</span></div>
                            <div class="detail-item"><label>Comuna</label><span>${escapeHtml(s.comuna || '-')}</span></div>
                            <div class="detail-item"><label>Ciudad</label><span>${escapeHtml(s.ciudad || '-')}</span></div>
                        </div>
                    </div>`;
                
                if (s.notas) {
                    html += `<div class="detail-section">
                        <h3>Notas</h3>
                        <p>${escapeHtml(s.notas)}</p>
                    </div>`;
                }
                
                if (s.codigos && s.codigos.length > 0) {
                    html += `<div class="detail-section">
                        <h3>Códigos de Productos (${s.codigos.length})</h3>
                        <table class="wp-list-table widefat" style="font-size:12px;">
                            <thead><tr><th>Código Proveedor</th><th>SKU Local</th><th>Producto</th></tr></thead>
                            <tbody>`;
                    s.codigos.slice(0, 10).forEach(function(c) {
                        html += `<tr>
                            <td>${escapeHtml(c.codigo_proveedor)}</td>
                            <td>${escapeHtml(c.sku_local || '-')}</td>
                            <td>${escapeHtml(c.product_name || '-')}</td>
                        </tr>`;
                    });
                    if (s.codigos.length > 10) {
                        html += `<tr><td colspan="3" style="text-align:center;color:#666;">... y ${s.codigos.length - 10} más</td></tr>`;
                    }
                    html += '</tbody></table></div>';
                }
                
                $('#detail-title').text(s.nombre);
                $('#supplier-detail-content').html(html);
                $('#supplier-detail-modal').show();
            }
        });
    });
    
    // Toggle activo
    $('#suppliers-body').on('click', '.btn-toggle', function() {
        const $tr = $(this).closest('tr');
        const id = $tr.data('id');
        const currentStatus = $tr.find('.status-badge').hasClass('status-active');
        const newStatus = currentStatus ? 0 : 1;
        const action = newStatus ? 'activar' : 'desactivar';
        
        if (!confirm(`¿${action.charAt(0).toUpperCase() + action.slice(1)} este proveedor?`)) {
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_toggle_supplier',
            nonce: riverso_pos.nonce,
            id: id,
            active: newStatus
        }, function(response) {
            if (response.success) {
                loadSuppliers();
            } else {
                alert('Error: ' + (response.data?.message || 'Error al actualizar'));
            }
        });
    });
    
    // Guardar
    $('#supplier-form').on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');
        
        const formData = new FormData(this);
        formData.append('action', 'riverso_save_supplier');
        formData.append('nonce', riverso_pos.nonce);
        formData.append('activo', $('#supplier-activo').is(':checked') ? 1 : 0);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#supplier-modal').hide();
                    loadSuppliers();
                } else {
                    alert('Error: ' + (response.data?.message || 'Error al guardar'));
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Guardar Proveedor');
            }
        });
    });
    
    // Cargar inicial
    loadSuppliers();
});
</script>
