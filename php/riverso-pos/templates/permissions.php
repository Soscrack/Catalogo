<?php
/**
 * Template: Gestión de Permisos
 * Permite editar capacidades individuales para cada rol y usuario
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permisos
if (!current_user_can('manage_options') && !current_user_can('riverso_manage_permissions')) {
    wp_die('No tienes permisos para acceder a esta página.');
}
?>

<div class="wrap riverso-pos-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-admin-network"></span>
        Gestión de Permisos
    </h1>
    
    <p class="description">
        Configura los permisos por rol o por usuario individual. Los cambios se aplican inmediatamente.
        <br><strong>🔒 riverso_manage_permissions</strong> no puede ser removido del Administrador.
    </p>
    
    <!-- Selector de modo -->
    <div class="permissions-mode-selector">
        <button type="button" class="button button-primary" id="mode-roles">📋 Por Roles</button>
        <button type="button" class="button" id="mode-users">👤 Por Usuario</button>
    </div>
    
    <div id="permissions-loading" style="text-align: center; padding: 50px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p>Cargando permisos...</p>
    </div>
    
    <!-- Sección de Roles -->
    <div id="roles-section" style="display: none;">
        <div class="nav-tab-wrapper riverso-permissions-tabs" id="role-tabs"></div>
        <div class="riverso-permissions-content" id="permissions-content"></div>
    </div>
    
    <!-- Sección de Usuarios -->
    <div id="users-section" style="display: none;">
        <div class="user-search-box">
            <h3>🔍 Buscar Empleado</h3>
            <div class="search-row">
                <input type="text" id="user-search-input" placeholder="Buscar por nombre, email o usuario..." class="regular-text">
                <button type="button" class="button" id="btn-search-users">Buscar</button>
            </div>
            <div id="user-search-results"></div>
        </div>
        
        <div id="user-permissions-panel" style="display: none;">
            <div class="user-info-header">
                <h3>
                    <span class="dashicons dashicons-admin-users"></span>
                    <span id="selected-user-name"></span>
                </h3>
                <p class="user-meta">
                    <span id="selected-user-email"></span> | 
                    Rol: <strong id="selected-user-role"></strong>
                </p>
            </div>
            <div class="riverso-permissions-content" id="user-permissions-content"></div>
        </div>
    </div>
</div>

<style>
.riverso-pos-wrap {
    max-width: 1400px;
}

.permissions-mode-selector {
    margin: 20px 0;
    display: flex;
    gap: 10px;
}

.permissions-mode-selector .button {
    padding: 8px 20px;
    font-size: 14px;
}

.riverso-permissions-tabs {
    margin-bottom: 0;
    border-bottom: 1px solid #c3c4c7;
}

.riverso-permissions-tabs .nav-tab {
    cursor: pointer;
    border-bottom: none;
}

.riverso-permissions-tabs .nav-tab.nav-tab-active {
    background: #fff;
    border-bottom-color: #fff;
}

.riverso-permissions-content {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-top: none;
    padding: 20px;
}

/* User search section */
.user-search-box {
    background: #fff;
    border: 1px solid #c3c4c7;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.user-search-box h3 {
    margin: 0 0 15px;
}

.search-row {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.search-row input {
    flex: 1;
    max-width: 400px;
}

#user-search-results {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
    max-height: 300px;
    overflow-y: auto;
}

.user-result-item {
    display: flex;
    align-items: center;
    padding: 12px;
    background: #f9f9f9;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.user-result-item:hover {
    background: #f0f6fc;
    border-color: #2271b1;
}

.user-result-item.selected {
    background: #e7f3ff;
    border-color: #2271b1;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 12px;
    background: #ddd;
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: 600;
    color: #1d2327;
}

.user-email {
    font-size: 12px;
    color: #666;
}

.user-role-badge {
    font-size: 11px;
    padding: 2px 8px;
    background: #2271b1;
    color: #fff;
    border-radius: 10px;
}

/* User permissions panel */
.user-info-header {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-bottom: none;
    padding: 15px 20px;
    border-radius: 4px 4px 0 0;
}

.user-info-header h3 {
    margin: 0 0 5px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.user-meta {
    margin: 0;
    color: #666;
    font-size: 13px;
}

#users-section .riverso-permissions-content {
    border-radius: 0 0 4px 4px;
}

/* Permissions grid */
.permissions-group {
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.permissions-group:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.permissions-group h3 {
    margin: 0 0 15px;
    padding: 8px 12px;
    background: #f0f0f1;
    border-radius: 4px;
    font-size: 14px;
}

.permissions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 10px;
}

.permission-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 4px;
    background: #f9f9f9;
    transition: background 0.2s;
}

.permission-item:hover {
    background: #f0f6fc;
}

.permission-item.protected {
    background: #fcf0f0;
    border: 1px solid #ffaaaa;
}

.permission-item.protected.granted {
    background: #f0fcf0;
    border: 1px solid #aaffaa;
}

.permission-item.inherited {
    background: #fffbeb;
    border: 1px dashed #d4a72c;
}

.permission-item input[type="checkbox"] {
    margin-right: 10px;
    width: 18px;
    height: 18px;
}

.permission-item input[type="checkbox"]:disabled {
    cursor: not-allowed;
}

.permission-label {
    flex: 1;
}

.permission-key {
    font-family: monospace;
    font-size: 11px;
    color: #666;
    display: block;
}

.permission-desc {
    font-size: 13px;
    color: #333;
}

.inherited-badge {
    font-size: 10px;
    color: #996800;
    margin-left: 5px;
}

.role-tab-admin {
    color: #d63638;
    font-weight: bold;
}

.role-summary, .user-summary {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
    flex-wrap: wrap;
}

.role-summary-item, .user-summary-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.role-summary-count, .user-summary-count {
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
}

.permissions-bulk-actions {
    margin-bottom: 15px;
    display: flex;
    gap: 10px;
}

.permissions-bulk-actions button {
    padding: 6px 12px;
}

.toast-message {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 4px;
    color: #fff;
    font-weight: 500;
    z-index: 9999;
    animation: slideIn 0.3s ease;
}

.toast-message.success { background: #00a32a; }
.toast-message.error { background: #d63638; }

.no-results {
    padding: 20px;
    text-align: center;
    color: #666;
    font-style: italic;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>

<script>
jQuery(document).ready(function($) {
    let permissionsData = null;
    let activeRole = 'administrator';
    let activeMode = 'roles';
    let selectedUserId = null;
    let selectedUserData = null;
    
    // Cargar permisos
    function loadPermissions() {
        $.ajax({
            url: riverso_pos.ajax_url,
            type: 'POST',
            data: {
                action: 'riverso_get_all_permissions',
                nonce: riverso_pos.nonce
            },
            success: function(response) {
                if (response.success) {
                    permissionsData = response.data;
                    renderTabs();
                    renderPermissions(activeRole);
                    $('#permissions-loading').hide();
                    $('#roles-section').show();
                } else {
                    showToast(response.data.message || 'Error cargando permisos', 'error');
                }
            },
            error: function() {
                showToast('Error de conexión', 'error');
            }
        });
    }
    
    // Cambiar modo
    $('#mode-roles').on('click', function() {
        activeMode = 'roles';
        $(this).addClass('button-primary');
        $('#mode-users').removeClass('button-primary');
        $('#roles-section').show();
        $('#users-section').hide();
    });
    
    $('#mode-users').on('click', function() {
        activeMode = 'users';
        $(this).addClass('button-primary');
        $('#mode-roles').removeClass('button-primary');
        $('#roles-section').hide();
        $('#users-section').show();
        // Cargar usuarios al entrar
        searchUsers('');
    });
    
    // Buscar usuarios
    function searchUsers(query) {
        $.ajax({
            url: riverso_pos.ajax_url,
            type: 'POST',
            data: {
                action: 'riverso_search_wp_users',
                nonce: riverso_pos.nonce,
                search: query
            },
            success: function(response) {
                if (response.success) {
                    renderUserResults(response.data.users);
                } else {
                    $('#user-search-results').html('<p class="no-results">Error buscando usuarios</p>');
                }
            }
        });
    }
    
    function renderUserResults(users) {
        if (!users || users.length === 0) {
            $('#user-search-results').html('<p class="no-results">No se encontraron usuarios</p>');
            return;
        }
        
        let html = '';
        users.forEach(function(user) {
            const selectedClass = selectedUserId == user.id ? 'selected' : '';
            const roleLabel = user.role_label || user.role || 'Sin rol';
            const displayName = user.name || user.display_name || user.user_login || 'Usuario';
            const userEmail = user.email || user.user_email || '';
            const avatarUrl = user.avatar || 'https://www.gravatar.com/avatar/?d=mp&s=40';
            html += `
                <div class="user-result-item ${selectedClass}" data-user-id="${user.id}">
                    <img src="${avatarUrl}" class="user-avatar">
                    <div class="user-info">
                        <div class="user-name">${displayName}</div>
                        <div class="user-email">${userEmail}</div>
                    </div>
                    <span class="user-role-badge">${roleLabel}</span>
                </div>
            `;
        });
        $('#user-search-results').html(html);
    }
    
    // Buscar al escribir
    let searchTimeout;
    $('#user-search-input').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val();
        searchTimeout = setTimeout(function() {
            searchUsers(query);
        }, 300);
    });
    
    $('#btn-search-users').on('click', function() {
        searchUsers($('#user-search-input').val());
    });
    
    // Seleccionar usuario
    $(document).on('click', '.user-result-item', function() {
        const userId = $(this).data('user-id');
        $('.user-result-item').removeClass('selected');
        $(this).addClass('selected');
        loadUserPermissions(userId);
    });
    
    // Cargar permisos de usuario
    function loadUserPermissions(userId) {
        $.ajax({
            url: riverso_pos.ajax_url,
            type: 'POST',
            data: {
                action: 'riverso_get_user_permissions',
                nonce: riverso_pos.nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    selectedUserId = userId;
                    selectedUserData = response.data;
                    renderUserPermissions(response.data);
                    $('#user-permissions-panel').show();
                } else {
                    showToast(response.data.message || 'Error cargando permisos', 'error');
                }
            }
        });
    }
    
    function renderUserPermissions(userData) {
        // Header
        $('#selected-user-name').text(userData.display_name);
        $('#selected-user-email').text(userData.email || '');
        $('#selected-user-role').text(userData.roles.join(', ') || 'Sin rol');
        
        const groups = permissionsData.groups;
        const caps = permissionsData.capabilities;
        const protectedCap = permissionsData.protected;
        const userCaps = userData.capabilities;
        const isAdmin = userData.roles.includes('administrator');
        
        // Contar permisos
        const totalCaps = Object.keys(caps).length;
        const grantedCaps = Object.values(userCaps).filter(v => v).length;
        
        let html = `
            <div class="user-summary">
                <div class="user-summary-item">
                    <span class="user-summary-count">${grantedCaps}</span>
                    <span>de ${totalCaps} permisos activos</span>
                </div>
                <div class="user-summary-item">
                    <span style="font-size: 12px; color: #666;">
                        Los permisos se suman a los del rol base. Puedes agregar permisos extra individuales.
                    </span>
                </div>
            </div>
        `;
        
        for (const [groupName, groupCaps] of Object.entries(groups)) {
            html += `
                <div class="permissions-group">
                    <h3>${groupName}</h3>
                    <div class="permissions-grid">
            `;
            
            for (const cap of groupCaps) {
                const isGranted = userCaps[cap];
                const isProtected = (cap === protectedCap && isAdmin);
                const disabledAttr = isProtected ? 'disabled' : '';
                const checkedAttr = isGranted ? 'checked' : '';
                const protectedClass = isProtected ? 'protected' : '';
                const grantedClass = isGranted ? 'granted' : '';
                
                html += `
                    <div class="permission-item user-perm ${protectedClass} ${grantedClass}">
                        <input type="checkbox" 
                               id="user-cap-${cap}" 
                               data-cap="${cap}" 
                               ${checkedAttr} 
                               ${disabledAttr}>
                        <label for="user-cap-${cap}" class="permission-label">
                            <span class="permission-desc">${caps[cap]}</span>
                            <span class="permission-key">${cap}</span>
                        </label>
                    </div>
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
        }
        
        $('#user-permissions-content').html(html);
    }
    
    // Toggle permiso de usuario
    $(document).on('change', '.user-perm input[type="checkbox"]', function() {
        const $checkbox = $(this);
        const cap = $checkbox.data('cap');
        const granted = $checkbox.is(':checked');
        const $item = $checkbox.closest('.permission-item');
        
        $.ajax({
            url: riverso_pos.ajax_url,
            type: 'POST',
            data: {
                action: 'riverso_update_user_capability',
                nonce: riverso_pos.nonce,
                user_id: selectedUserId,
                capability: cap,
                granted: granted
            },
            success: function(response) {
                if (response.success) {
                    selectedUserData.capabilities[cap] = granted;
                    
                    if (granted) {
                        $item.addClass('granted');
                    } else {
                        $item.removeClass('granted');
                    }
                    
                    // Actualizar contador
                    const grantedCaps = Object.values(selectedUserData.capabilities).filter(v => v).length;
                    $('.user-summary-count').first().text(grantedCaps);
                    
                    showToast('Permiso actualizado', 'success');
                } else {
                    $checkbox.prop('checked', !granted);
                    showToast(response.data.message || 'Error', 'error');
                }
            },
            error: function() {
                $checkbox.prop('checked', !granted);
                showToast('Error de conexión', 'error');
            }
        });
    });
    
    // === ROLES SECTION ===
    
    // Renderizar tabs de roles
    function renderTabs() {
        let html = '';
        for (const [roleKey, roleData] of Object.entries(permissionsData.roles)) {
            const isActive = roleKey === activeRole ? ' nav-tab-active' : '';
            const isAdmin = roleData.is_admin ? ' role-tab-admin' : '';
            html += `<a class="nav-tab${isActive}${isAdmin}" data-role="${roleKey}">${roleData.name}</a>`;
        }
        $('#role-tabs').html(html);
    }
    
    // Renderizar permisos para un rol
    function renderPermissions(roleKey) {
        const roleData = permissionsData.roles[roleKey];
        const groups = permissionsData.groups;
        const caps = permissionsData.capabilities;
        const protectedCap = permissionsData.protected;
        
        const totalCaps = Object.keys(caps).length;
        const grantedCaps = Object.values(roleData.capabilities).filter(v => v).length;
        
        let html = `
            <div class="role-summary">
                <div class="role-summary-item">
                    <span class="role-summary-count">${grantedCaps}</span>
                    <span>de ${totalCaps} permisos activos</span>
                </div>
            </div>
            <div class="permissions-bulk-actions">
                <button type="button" class="button" id="btn-grant-all">Activar todos</button>
                <button type="button" class="button" id="btn-revoke-all">Desactivar todos</button>
            </div>
        `;
        
        for (const [groupName, groupCaps] of Object.entries(groups)) {
            html += `
                <div class="permissions-group">
                    <h3>${groupName}</h3>
                    <div class="permissions-grid">
            `;
            
            for (const cap of groupCaps) {
                const isGranted = roleData.capabilities[cap];
                const isProtected = (cap === protectedCap && roleData.is_admin);
                const disabledAttr = isProtected ? 'disabled' : '';
                const checkedAttr = isGranted ? 'checked' : '';
                const protectedClass = isProtected ? 'protected' : '';
                const grantedClass = isGranted ? 'granted' : '';
                
                html += `
                    <div class="permission-item role-perm ${protectedClass} ${grantedClass}">
                        <input type="checkbox" 
                               id="cap-${cap}" 
                               data-cap="${cap}" 
                               ${checkedAttr} 
                               ${disabledAttr}>
                        <label for="cap-${cap}" class="permission-label">
                            <span class="permission-desc">${caps[cap]}</span>
                            <span class="permission-key">${cap}</span>
                        </label>
                    </div>
                `;
            }
            
            html += `
                    </div>
                </div>
            `;
        }
        
        $('#permissions-content').html(html);
    }
    
    // Cambiar tab de rol
    $(document).on('click', '.riverso-permissions-tabs .nav-tab', function() {
        $('.riverso-permissions-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        activeRole = $(this).data('role');
        renderPermissions(activeRole);
    });
    
    // Toggle permiso de rol
    $(document).on('change', '.role-perm input[type="checkbox"]', function() {
        const $checkbox = $(this);
        const cap = $checkbox.data('cap');
        const granted = $checkbox.is(':checked');
        const $item = $checkbox.closest('.permission-item');
        
        $.ajax({
            url: riverso_pos.ajax_url,
            type: 'POST',
            data: {
                action: 'riverso_update_role_capability',
                nonce: riverso_pos.nonce,
                role: activeRole,
                capability: cap,
                granted: granted
            },
            success: function(response) {
                if (response.success) {
                    permissionsData.roles[activeRole].capabilities[cap] = granted;
                    
                    if (granted) {
                        $item.addClass('granted');
                    } else {
                        $item.removeClass('granted');
                    }
                    
                    updateRoleCounter();
                    showToast('Permiso actualizado', 'success');
                } else {
                    $checkbox.prop('checked', !granted);
                    showToast(response.data.message || 'Error', 'error');
                }
            },
            error: function() {
                $checkbox.prop('checked', !granted);
                showToast('Error de conexión', 'error');
            }
        });
    });
    
    // Activar todos (rol)
    $(document).on('click', '#btn-grant-all', function() {
        if (!confirm('¿Activar todos los permisos para este rol?')) return;
        
        $('.role-perm input[type="checkbox"]:not(:disabled)').each(function() {
            const $cb = $(this);
            if (!$cb.is(':checked')) {
                $cb.prop('checked', true).trigger('change');
            }
        });
    });
    
    // Desactivar todos (rol)
    $(document).on('click', '#btn-revoke-all', function() {
        if (!confirm('¿Desactivar todos los permisos para este rol?')) return;
        
        $('.role-perm input[type="checkbox"]:not(:disabled)').each(function() {
            const $cb = $(this);
            if ($cb.is(':checked')) {
                $cb.prop('checked', false).trigger('change');
            }
        });
    });
    
    function updateRoleCounter() {
        const grantedCaps = Object.values(permissionsData.roles[activeRole].capabilities).filter(v => v).length;
        $('.role-summary .role-summary-count').text(grantedCaps);
    }
    
    // Toast message
    function showToast(message, type) {
        const $toast = $('<div class="toast-message ' + type + '">' + message + '</div>');
        $('body').append($toast);
        setTimeout(function() {
            $toast.fadeOut(300, function() { $(this).remove(); });
        }, 2000);
    }
    
    // Iniciar
    loadPermissions();
});
</script>
