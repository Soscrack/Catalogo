<?php
/**
 * Template: Gestión de Empleados
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('riverso_manage_users') && !current_user_can('riverso_manage_system')) {
    wp_die('No tienes permisos para ver esta página.');
}

require_once RIVERSO_POS_PLUGIN_DIR . 'modules/employees/class-employee-module.php';
?>

<div class="wrap riverso-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-groups" style="margin-right: 8px;"></span>
        Gestión de Empleados
    </h1>
    
    <button type="button" class="page-title-action" id="btn-new-employee">
        <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
        Agregar Empleado
    </button>
    
    <hr class="wp-header-end">
    
    <!-- Estadísticas -->
    <div id="employee-stats" class="stats-cards" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
        <div class="stat-card">
            <div class="stat-value" id="stat-total">-</div>
            <div class="stat-label">Empleados Activos</div>
        </div>
        <div class="stat-card" id="roles-breakdown">
            <div class="stat-label">Por Rol</div>
            <div class="stat-mini" id="stat-roles">Cargando...</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="employee-filters" style="margin: 20px 0; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <div>
            <label for="filter-status">Estado:</label>
            <select id="filter-status" style="margin-left: 5px;">
                <option value="all" selected>Todos</option>
                <option value="activo">Activos</option>
                <option value="vacaciones">Vacaciones</option>
                <option value="licencia">Licencia</option>
                <option value="inactivo">Inactivos</option>
                <option value="sin_perfil">Sin perfil</option>
            </select>
        </div>
        <div>
            <label for="filter-departamento">Departamento:</label>
            <select id="filter-departamento" style="margin-left: 5px;">
                <option value="">Todos</option>
            </select>
        </div>
        <div>
            <input type="text" id="filter-search" placeholder="Buscar por nombre, email, RUT..." style="width: 280px;">
        </div>
        <button type="button" class="button" id="btn-search">
            <span class="dashicons dashicons-search" style="vertical-align: middle;"></span> Buscar
        </button>
        <button type="button" class="button" id="btn-refresh">
            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
        </button>
    </div>
    
    <!-- Tabla -->
    <table class="wp-list-table widefat fixed striped" id="employees-table">
        <thead>
            <tr>
                <th style="width: 200px;">Empleado</th>
                <th style="width: 150px;">Rol</th>
                <th style="width: 120px;">Cargo</th>
                <th style="width: 120px;">Departamento</th>
                <th style="width: 100px;">Estado</th>
                <th style="width: 80px;">Tareas</th>
                <th style="width: 130px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="employees-body">
            <tr><td colspan="7" style="text-align: center;">Cargando...</td></tr>
        </tbody>
    </table>
</div>

<!-- Modal Empleado -->
<div id="employee-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 800px;">
        <div class="riverso-modal-header">
            <h2 id="modal-title">Editar Empleado</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <form id="employee-form">
            <input type="hidden" name="user_id" id="emp-user-id">
            
            <div class="modal-tabs">
                <button type="button" class="tab-btn active" data-tab="general">General</button>
                <button type="button" class="tab-btn" data-tab="laboral">Laboral</button>
                <button type="button" class="tab-btn" data-tab="actividad">Actividad</button>
            </div>
            
            <!-- Tab General -->
            <div class="tab-content active" id="tab-general">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                    <div>
                        <h4 style="margin: 0 0 15px 0; color: #1d2327;">Datos Personales</h4>
                        
                        <div style="margin-bottom: 15px;">
                            <label><strong>Nombre a mostrar *</strong></label>
                            <input type="text" id="emp-display-name" name="display_name" required style="width: 100%;">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                            <div>
                                <label>Nombre</label>
                                <input type="text" id="emp-first-name" name="first_name" style="width: 100%;">
                            </div>
                            <div>
                                <label>Apellido</label>
                                <input type="text" id="emp-last-name" name="last_name" style="width: 100%;">
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>RUT</label>
                            <input type="text" id="emp-rut" name="rut" placeholder="12345678-9" style="width: 100%;">
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>Email</label>
                            <input type="email" id="emp-email" disabled style="width: 100%; background: #f0f0f0;">
                            <small style="color: #666;">El email se modifica desde WordPress</small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>Teléfono Personal</label>
                            <input type="text" id="emp-telefono" name="telefono_personal" style="width: 100%;">
                        </div>
                        
                        <div>
                            <label>Contacto de Emergencia</label>
                            <input type="text" id="emp-emergencia" name="contacto_emergencia" placeholder="Nombre - Teléfono" style="width: 100%;">
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="margin: 0 0 15px 0; color: #1d2327;">Rol en el Sistema</h4>
                        
                        <div style="margin-bottom: 15px;">
                            <label><strong>Rol Riverso</strong></label>
                            <select id="emp-role" name="role" style="width: 100%;">
                                <option value="">-- Sin rol asignado --</option>
                                <option value="riverso_admin">Administrador Riverso</option>
                                <option value="riverso_ventas">Vendedor</option>
                                <option value="riverso_bodega">Operador Bodega</option>
                                <option value="riverso_compras">Operador Compras</option>
                                <option value="riverso_recepciones">Recepcionista</option>
                                <option value="riverso_editor">Editor Catálogo</option>
                                <option value="riverso_cotizador">Cotizador</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label><strong>Estado</strong></label>
                            <select id="emp-estado" name="estado" style="width: 100%;">
                                <option value="activo">Activo</option>
                                <option value="vacaciones">Vacaciones</option>
                                <option value="licencia">Licencia</option>
                                <option value="inactivo">Inactivo</option>
                            </select>
                        </div>
                        
                        <div class="role-capabilities" style="background: #f9f9f9; padding: 15px; border-radius: 6px; margin-top: 20px;">
                            <h5 style="margin: 0 0 10px 0;">Permisos del rol:</h5>
                            <div id="role-caps-list" style="font-size: 12px; color: #666;">
                                Selecciona un rol para ver sus permisos
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Laboral -->
            <div class="tab-content" id="tab-laboral" style="display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                    <div>
                        <div style="margin-bottom: 15px;">
                            <label><strong>Cargo</strong></label>
                            <input type="text" id="emp-cargo" name="cargo" style="width: 100%;" placeholder="Ej: Jefe de Bodega">
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label><strong>Departamento</strong></label>
                            <input type="text" id="emp-departamento" name="departamento" style="width: 100%;" list="departamentos-list" placeholder="Ej: Operaciones">
                            <datalist id="departamentos-list"></datalist>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>Supervisor</label>
                            <select id="emp-supervisor" name="supervisor_id" style="width: 100%;">
                                <option value="">-- Sin supervisor --</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <div style="margin-bottom: 15px;">
                            <label>Fecha de Ingreso</label>
                            <input type="date" id="emp-fecha-ingreso" name="fecha_ingreso" style="width: 100%;">
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>Tipo de Contrato</label>
                            <select id="emp-contrato" name="tipo_contrato" style="width: 100%;">
                                <option value="indefinido">Indefinido</option>
                                <option value="plazo_fijo">Plazo Fijo</option>
                                <option value="honorarios">Honorarios</option>
                                <option value="practica">Práctica</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label>Jornada</label>
                            <select id="emp-jornada" name="jornada" style="width: 100%;">
                                <option value="completa">Completa</option>
                                <option value="parcial">Parcial</option>
                                <option value="flexible">Flexible</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 15px;">
                    <label>Notas</label>
                    <textarea id="emp-notas" name="notas" rows="3" style="width: 100%;"></textarea>
                </div>
            </div>
            
            <!-- Tab Actividad -->
            <div class="tab-content" id="tab-actividad" style="display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                    <div>
                        <h4 style="margin: 0 0 10px 0;">Tareas Recientes</h4>
                        <div id="emp-tareas-list" style="max-height: 300px; overflow-y: auto;">
                            <p style="color: #666;">Sin tareas</p>
                        </div>
                    </div>
                    <div>
                        <h4 style="margin: 0 0 10px 0;">Actividad de Auditoría</h4>
                        <div id="emp-audit-list" style="max-height: 300px; overflow-y: auto;">
                            <p style="color: #666;">Sin actividad registrada</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="riverso-modal-footer" style="margin-top: 20px;">
                <button type="button" class="button riverso-modal-close">Cancelar</button>
                <button type="submit" class="button button-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Nuevo Empleado -->
<div id="new-employee-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 600px;">
        <div class="riverso-modal-header">
            <h2>➕ Agregar Nuevo Empleado</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div style="padding: 20px;">
            <p style="margin-bottom: 20px; color: #666;">
                Busca un usuario existente de WordPress o crea uno nuevo para asignarle un rol de empleado.
            </p>
            
            <!-- Opción 1: Buscar usuario existente -->
            <div class="new-emp-section" style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">
                <h3 style="margin: 0 0 15px 0; font-size: 15px;">
                    <span class="dashicons dashicons-search" style="color: #2271b1;"></span>
                    Buscar Usuario Existente
                </h3>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="search-wp-user" placeholder="Buscar por nombre, email..." style="flex: 1;">
                    <button type="button" class="button" id="btn-search-user">Buscar</button>
                </div>
                <div id="wp-users-results" style="margin-top: 15px; max-height: 250px; overflow-y: auto;">
                    <p style="color:#666; text-align: center;"><span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Cargando usuarios...</p>
                </div>
            </div>
            
            <!-- Opción 2: Crear nuevo usuario -->
            <div class="new-emp-section" style="padding: 20px; background: #f0f6fc; border-radius: 8px; border: 1px solid #c5d9ed;">
                <h3 style="margin: 0 0 15px 0; font-size: 15px;">
                    <span class="dashicons dashicons-admin-users" style="color: #2271b1;"></span>
                    Crear Nuevo Usuario
                </h3>
                <form id="create-user-form">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label><strong>Nombre de Usuario *</strong></label>
                            <input type="text" name="user_login" id="new-user-login" required style="width: 100%;">
                        </div>
                        <div>
                            <label><strong>Email *</strong></label>
                            <input type="email" name="user_email" id="new-user-email" required style="width: 100%;">
                        </div>
                        <div>
                            <label><strong>Nombre</strong></label>
                            <input type="text" name="first_name" id="new-user-fname" style="width: 100%;">
                        </div>
                        <div>
                            <label><strong>Apellido</strong></label>
                            <input type="text" name="last_name" id="new-user-lname" style="width: 100%;">
                        </div>
                        <div>
                            <label><strong>Contraseña *</strong></label>
                            <input type="password" name="user_pass" id="new-user-pass" required style="width: 100%;">
                        </div>
                        <div>
                            <label><strong>Rol Riverso *</strong></label>
                            <select name="riverso_role" id="new-user-role" required style="width: 100%;">
                                <option value="">-- Seleccionar --</option>
                                <option value="riverso_admin">Administrador Riverso</option>
                                <option value="riverso_ventas">Vendedor</option>
                                <option value="riverso_bodega">Operador Bodega</option>
                                <option value="riverso_compras">Operador Compras</option>
                                <option value="riverso_recepciones">Recepcionista</option>
                                <option value="riverso_editor">Editor Catálogo</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 20px; text-align: right;">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span>
                            Crear Empleado
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Asignar Rol -->
<div id="assign-role-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 450px;">
        <div class="riverso-modal-header">
            <h2>🔄 Cambiar Rol</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div style="padding: 20px;">
            <input type="hidden" id="assign-role-user-id">
            <p id="assign-role-user-name" style="font-size: 16px; margin-bottom: 20px;"></p>
            
            <label><strong>Nuevo Rol:</strong></label>
            <select id="assign-role-select" style="width: 100%; margin-top: 8px; padding: 10px;">
                <option value="">-- Sin rol Riverso --</option>
                <option value="riverso_admin">👑 Administrador Riverso - Acceso total</option>
                <option value="riverso_ventas">🛒 Vendedor - POS y cotizaciones</option>
                <option value="riverso_bodega">📦 Operador Bodega - Etiquetado y recepción</option>
                <option value="riverso_compras">📋 Operador Compras - Facturas y proveedores</option>
                <option value="riverso_recepciones">✅ Recepcionista - Validación</option>
                <option value="riverso_editor">✏️ Editor Catálogo - Productos</option>
            </select>
            
            <div id="role-description" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 5px; font-size: 13px; color: #666;">
                Selecciona un rol para ver su descripción.
            </div>
            
            <div style="margin-top: 20px; text-align: right;">
                <button type="button" class="button riverso-modal-close">Cancelar</button>
                <button type="button" class="button button-primary" id="btn-confirm-role">Asignar Rol</button>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card {
    background: #fff;
    padding: 20px 25px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    min-width: 150px;
}
.stat-value {
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
}
.stat-label {
    color: #666;
    font-size: 13px;
    margin-top: 5px;
}
.stat-mini {
    font-size: 12px;
    color: #666;
    line-height: 1.6;
}
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
}
.riverso-modal-close:hover {
    color: #d63638;
}
#employee-form {
    padding: 0 20px 20px 20px;
}
.riverso-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}
.modal-tabs {
    display: flex;
    gap: 5px;
    border-bottom: 2px solid #ddd;
    padding-top: 15px;
}
.tab-btn {
    padding: 10px 20px;
    border: none;
    background: #f0f0f0;
    cursor: pointer;
    border-radius: 6px 6px 0 0;
    font-size: 13px;
}
.tab-btn.active {
    background: #2271b1;
    color: #fff;
}
.tab-btn:hover:not(.active) {
    background: #e0e0e0;
}
.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
}
.status-activo { background: #d4edda; color: #155724; }
.status-vacaciones { background: #fff3cd; color: #856404; }
.status-licencia { background: #cce5ff; color: #004085; }
.status-inactivo { background: #f8d7da; color: #721c24; }
.role-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    background: #e9ecef;
    color: #495057;
}
.role-riverso_admin { background: #dc3545; color: #fff; }
.role-riverso_ventas { background: #28a745; color: #fff; }
.role-riverso_bodega { background: #17a2b8; color: #fff; }
.role-riverso_compras { background: #ffc107; color: #212529; }
.role-riverso_recepciones { background: #6f42c1; color: #fff; }
.role-riverso_editor { background: #fd7e14; color: #fff; }
.task-item, .audit-item {
    padding: 8px;
    border-bottom: 1px solid #eee;
    font-size: 12px;
}
.task-item:last-child, .audit-item:last-child {
    border-bottom: none;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Cache de empleados para supervisor select
    let employeesCache = [];
    
    function loadEmployees() {
        const status = $('#filter-status').val();
        const departamento = $('#filter-departamento').val();
        const search = $('#filter-search').val();
        
        $('#employees-body').html('<tr><td colspan="7" style="text-align:center;">Cargando...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'riverso_get_employees',
            nonce: riverso_pos.nonce,
            status: status,
            departamento: departamento,
            search: search
        }, function(response) {
            if (response.success) {
                renderEmployees(response.data.employees);
                updateDepartamentos(response.data.departamentos);
                employeesCache = response.data.employees;
                updateSupervisorSelect();
            } else {
                $('#employees-body').html('<tr><td colspan="7" style="text-align:center;color:#d63638;">Error al cargar</td></tr>');
            }
        });
    }
    
    function loadStats() {
        $.post(ajaxurl, {
            action: 'riverso_get_employee_stats',
            nonce: riverso_pos.nonce
        }, function(response) {
            if (response.success) {
                const s = response.data;
                $('#stat-total').text(s.total_activos);
                
                let rolesHtml = '';
                s.por_rol.forEach(function(r) {
                    rolesHtml += r.name + ': ' + r.count + '<br>';
                });
                $('#stat-roles').html(rolesHtml || 'Sin datos');
            }
        });
    }
    
    function renderEmployees(employees) {
        if (!employees || employees.length === 0) {
            $('#employees-body').html('<tr><td colspan="7" style="text-align:center;">No hay empleados con los filtros seleccionados</td></tr>');
            return;
        }
        
        let html = '';
        employees.forEach(function(e) {
            const statusClass = 'status-' + (e.estado || 'activo');
            const roleClass = 'role-' + (e.role || 'none');
            const hasProfile = e.employee_id ? '' : ' style="background: #fffbcc;"';
            
            html += `<tr data-user-id="${e.user_id}"${hasProfile}>
                <td>
                    <strong>${escapeHtml(e.display_name)}</strong><br>
                    <span style="color: #666; font-size: 12px;">${escapeHtml(e.user_email)}</span>
                </td>
                <td><span class="role-badge ${roleClass}">${escapeHtml(e.role_name)}</span></td>
                <td>${escapeHtml(e.cargo || '-')}</td>
                <td>${escapeHtml(e.departamento || '-')}</td>
                <td><span class="status-badge ${statusClass}">${escapeHtml(e.estado || 'Sin perfil')}</span></td>
                <td style="text-align:center;">${e.tareas_pendientes || 0}</td>
                <td>
                    <button type="button" class="button button-small btn-edit" title="Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    ${e.employee_id ? `<button type="button" class="button button-small btn-delete" title="Eliminar perfil">
                        <span class="dashicons dashicons-trash"></span>
                    </button>` : ''}
                </td>
            </tr>`;
        });
        
        $('#employees-body').html(html);
    }
    
    function updateDepartamentos(deps) {
        const current = $('#filter-departamento').val();
        let options = '<option value="">Todos</option>';
        deps.forEach(function(d) {
            options += `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`;
        });
        $('#filter-departamento').html(options).val(current);
        
        // También para datalist
        let datalist = '';
        deps.forEach(function(d) {
            datalist += `<option value="${escapeHtml(d)}">`;
        });
        $('#departamentos-list').html(datalist);
    }
    
    function updateSupervisorSelect() {
        let options = '<option value="">-- Sin supervisor --</option>';
        employeesCache.forEach(function(e) {
            if (e.estado === 'activo' || !e.estado) {
                options += `<option value="${e.user_id}">${escapeHtml(e.display_name)}</option>`;
            }
        });
        $('#emp-supervisor').html(options);
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Cargar empleado para edición
    function loadEmployee(userId) {
        $.post(ajaxurl, {
            action: 'riverso_get_employee',
            nonce: riverso_pos.nonce,
            user_id: userId
        }, function(response) {
            if (response.success) {
                const e = response.data.employee;
                
                $('#emp-user-id').val(e.user_id);
                $('#emp-display-name').val(e.display_name);
                $('#emp-first-name').val(e.first_name || '');
                $('#emp-last-name').val(e.last_name || '');
                $('#emp-email').val(e.user_email);
                $('#emp-rut').val(formatRut(e.rut || ''));
                $('#emp-telefono').val(e.telefono_personal || '');
                $('#emp-emergencia').val(e.contacto_emergencia || '');
                $('#emp-role').val(e.role || '');
                $('#emp-estado').val(e.estado || 'activo');
                $('#emp-cargo').val(e.cargo || '');
                $('#emp-departamento').val(e.departamento || '');
                $('#emp-supervisor').val(e.supervisor_id || '');
                $('#emp-fecha-ingreso').val(e.fecha_ingreso || '');
                $('#emp-contrato').val(e.tipo_contrato || 'indefinido');
                $('#emp-jornada').val(e.jornada || 'completa');
                $('#emp-notas').val(e.notas || '');
                
                // Tareas
                let tareasHtml = '';
                if (e.tareas_recientes && e.tareas_recientes.length > 0) {
                    e.tareas_recientes.forEach(function(t) {
                        tareasHtml += `<div class="task-item">
                            <strong>${escapeHtml(t.titulo)}</strong><br>
                            <span style="color:#666;">${t.tipo} - ${t.estado} - ${t.created_at}</span>
                        </div>`;
                    });
                } else {
                    tareasHtml = '<p style="color:#666;">Sin tareas recientes</p>';
                }
                $('#emp-tareas-list').html(tareasHtml);
                
                // Auditoría
                let auditHtml = '';
                if (e.audit_history && e.audit_history.length > 0) {
                    e.audit_history.forEach(function(a) {
                        auditHtml += `<div class="audit-item">
                            <strong>${escapeHtml(a.action)}</strong>: ${escapeHtml(a.entity_name || a.entity_type)}<br>
                            <span style="color:#666;">${a.created_at}</span>
                        </div>`;
                    });
                } else {
                    auditHtml = '<p style="color:#666;">Sin actividad registrada</p>';
                }
                $('#emp-audit-list').html(auditHtml);
                
                $('#modal-title').text('Editar: ' + e.display_name);
                showTab('general');
                $('#employee-modal').show();
            } else {
                alert('Error al cargar empleado');
            }
        });
    }
    
    function formatRut(rut) {
        if (!rut) return '';
        rut = rut.replace(/[^0-9kK]/g, '').toUpperCase();
        if (rut.length < 2) return rut;
        const dv = rut.slice(-1);
        const num = rut.slice(0, -1);
        return num.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }
    
    function showTab(tabId) {
        $('.tab-btn').removeClass('active');
        $(`.tab-btn[data-tab="${tabId}"]`).addClass('active');
        $('.tab-content').hide();
        $(`#tab-${tabId}`).show();
    }
    
    // Event handlers
    $('#btn-search, #btn-refresh').on('click', loadEmployees);
    
    $('#filter-search').on('keypress', function(e) {
        if (e.which === 13) loadEmployees();
    });
    
    $('#filter-status, #filter-departamento').on('change', loadEmployees);
    
    // Tabs
    $('.tab-btn').on('click', function() {
        showTab($(this).data('tab'));
    });
    
    // Editar
    $('#employees-body').on('click', '.btn-edit', function() {
        const userId = $(this).closest('tr').data('user-id');
        loadEmployee(userId);
    });
    
    // Eliminar perfil
    $('#employees-body').on('click', '.btn-delete', function() {
        const userId = $(this).closest('tr').data('user-id');
        if (!confirm('¿Eliminar el perfil de empleado? (El usuario de WordPress no se elimina)')) {
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_delete_employee',
            nonce: riverso_pos.nonce,
            user_id: userId
        }, function(response) {
            if (response.success) {
                loadEmployees();
                loadStats();
            } else {
                alert('Error: ' + (response.data?.message || 'Error al eliminar'));
            }
        });
    });
    
    // Cerrar modal
    $('.riverso-modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    $('.riverso-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Rol change - mostrar permisos
    $('#emp-role').on('change', function() {
        const role = $(this).val();
        const caps = {
            'riverso_admin': ['Acceso total', 'Administrar sistema', 'Gestionar usuarios', 'Ver auditoría', 'Aprobar todo'],
            'riverso_ventas': ['Usar POS', 'Crear ventas', 'Emitir cotizaciones', 'Ver stock', 'Aplicar descuentos'],
            'riverso_bodega': ['Ver tareas', 'Etiquetar productos', 'Bodeguear', 'Ver ubicaciones'],
            'riverso_compras': ['Ingresar cotizaciones', 'Ingresar facturas', 'Ver costos', 'Gestionar proveedores'],
            'riverso_recepciones': ['Registrar recepción', 'Validar cantidades', 'Generar incidencias'],
            'riverso_editor': ['Editar productos', 'Editar SKUs', 'Asignar códigos de barra'],
            'riverso_cotizador': ['Crear cotizaciones', 'Ver productos', 'Ver stock'],
        };
        
        const perms = caps[role] || [];
        if (perms.length > 0) {
            $('#role-caps-list').html('• ' + perms.join('<br>• '));
        } else {
            $('#role-caps-list').html('Selecciona un rol para ver sus permisos');
        }
    });
    
    // Guardar
    $('#employee-form').on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Guardando...');
        
        const formData = new FormData(this);
        formData.append('action', 'riverso_save_employee');
        formData.append('nonce', riverso_pos.nonce);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#employee-modal').hide();
                    loadEmployees();
                    loadStats();
                } else {
                    alert('Error: ' + (response.data?.message || 'Error al guardar'));
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Guardar Cambios');
            }
        });
    });
    
    // === NUEVO EMPLEADO ===
    
    // Función para cargar usuarios WP
    function loadWpUsers(search = '') {
        $('#wp-users-results').html('<p style="color:#666; text-align: center;"><span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Cargando...</p>');
        
        $.post(ajaxurl, {
            action: 'riverso_search_wp_users',
            nonce: riverso_pos.nonce,
            search: search
        }, function(response) {
            if (response.success && response.data.users.length > 0) {
                let html = '';
                response.data.users.forEach(function(u) {
                    const roleText = u.role ? `<span class="role-badge role-${u.role}">${u.role.replace('riverso_', '')}</span>` : '<span style="color:#999;">Sin rol Riverso</span>';
                    html += `
                        <div class="user-result" style="display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee;">
                            <div>
                                <strong>${escapeHtml(u.name)}</strong><br>
                                <span style="color:#666; font-size: 12px;">${escapeHtml(u.email)}</span><br>
                                ${roleText}
                            </div>
                            <button type="button" class="button button-small btn-assign-role" data-id="${u.id}" data-name="${escapeHtml(u.name)}">
                                ${u.role ? 'Cambiar Rol' : 'Asignar Rol'}
                            </button>
                        </div>
                    `;
                });
                $('#wp-users-results').html(html);
            } else {
                $('#wp-users-results').html('<p style="color:#666; text-align:center;">No se encontraron usuarios</p>');
            }
        });
    }
    
    // Abrir modal nuevo empleado - cargar usuarios automáticamente
    $('#btn-new-employee').on('click', function() {
        $('#search-wp-user').val('');
        $('#create-user-form')[0].reset();
        $('#new-employee-modal').show();
        loadWpUsers(''); // Cargar todos los usuarios
    });
    
    // Buscar usuarios WP
    $('#btn-search-user').on('click', function() {
        const search = $('#search-wp-user').val().trim();
        loadWpUsers(search);
    });
    
    $('#search-wp-user').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#btn-search-user').click();
        }
    });
    
    // Click en "Asignar Rol" de búsqueda
    $(document).on('click', '.btn-assign-role', function() {
        const userId = $(this).data('id');
        const userName = $(this).data('name');
        
        $('#assign-role-user-id').val(userId);
        $('#assign-role-user-name').html('Usuario: <strong>' + userName + '</strong>');
        $('#assign-role-select').val('');
        $('#role-description').text('Selecciona un rol para ver su descripción.');
        
        $('#new-employee-modal').hide();
        $('#assign-role-modal').show();
    });
    
    // Mostrar descripción del rol
    $('#assign-role-select').on('change', function() {
        const descriptions = {
            'riverso_admin': 'Acceso total al sistema. Puede ver auditoría, gestionar usuarios, aprobar todo tipo de documentos y configurar el sistema.',
            'riverso_ventas': 'Acceso al POS para realizar ventas, crear cotizaciones a clientes, ver stock y aplicar descuentos autorizados.',
            'riverso_bodega': 'Operaciones de bodega: etiquetar productos, organizar ubicaciones, registrar recepciones físicas.',
            'riverso_compras': 'Gestión de compras: ingresar cotizaciones y facturas de proveedores, ver historial de costos, gestionar proveedores.',
            'riverso_recepciones': 'Validar recepciones de mercadería, aprobar cantidades, generar incidencias cuando hay diferencias.',
            'riverso_editor': 'Editar productos del catálogo, modificar SKUs, asignar códigos de barra y gestionar atributos.'
        };
        
        const role = $(this).val();
        $('#role-description').text(descriptions[role] || 'Selecciona un rol para ver su descripción.');
    });
    
    // Confirmar asignación de rol
    $('#btn-confirm-role').on('click', function() {
        const userId = $('#assign-role-user-id').val();
        const role = $('#assign-role-select').val();
        
        if (!role) {
            alert('Selecciona un rol');
            return;
        }
        
        $(this).prop('disabled', true).text('Asignando...');
        
        $.post(ajaxurl, {
            action: 'riverso_assign_role',
            nonce: riverso_pos.nonce,
            user_id: userId,
            role: role
        }, function(response) {
            $('#btn-confirm-role').prop('disabled', false).text('Asignar Rol');
            
            if (response.success) {
                alert('Rol asignado correctamente');
                $('#assign-role-modal').hide();
                loadEmployees();
                loadStats();
            } else {
                alert('Error: ' + (response.data?.message || 'Error al asignar rol'));
            }
        });
    });
    
    // Crear nuevo usuario
    $('#create-user-form').on('submit', function(e) {
        e.preventDefault();
        
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> Creando...');
        
        $.post(ajaxurl, {
            action: 'riverso_create_employee',
            nonce: riverso_pos.nonce,
            user_login: $('#new-user-login').val(),
            user_email: $('#new-user-email').val(),
            first_name: $('#new-user-fname').val(),
            last_name: $('#new-user-lname').val(),
            user_pass: $('#new-user-pass').val(),
            riverso_role: $('#new-user-role').val()
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-plus" style="vertical-align: middle;"></span> Crear Empleado');
            
            if (response.success) {
                alert('Empleado creado correctamente');
                $('#new-employee-modal').hide();
                $('#create-user-form')[0].reset();
                loadEmployees();
                loadStats();
            } else {
                alert('Error: ' + (response.data?.message || 'Error al crear usuario'));
            }
        });
    });
    
    // Cargar inicial
    loadEmployees();
    loadStats();
});
</script>
