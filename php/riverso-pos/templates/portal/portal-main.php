<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riverso - Portal Interno</title>
    <?php wp_head(); ?>
    <style>
        :root {
            --primary: #1976d2;
            --primary-dark: #1565c0;
            --secondary: #424242;
            --success: #4caf50;
            --warning: #ff9800;
            --danger: #f44336;
            --bg-light: #f5f5f5;
            --bg-white: #ffffff;
            --text-primary: #212121;
            --text-secondary: #757575;
            --border: #e0e0e0;
            --sidebar-width: 240px;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
        }
        
        /* Layout */
        .portal-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .portal-sidebar {
            width: var(--sidebar-width);
            background: var(--secondary);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 20px;
            background: rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .sidebar-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .sidebar-subtitle {
            font-size: 12px;
            opacity: 0.7;
        }
        
        .sidebar-nav {
            padding: 15px 0;
        }
        
        .nav-section {
            padding: 10px 20px 5px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.5;
        }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-item.active {
            background: var(--primary);
            color: white;
        }
        
        .nav-item .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
        }
        
        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px 20px;
            background: rgba(0,0,0,0.2);
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .user-role {
            font-size: 12px;
            opacity: 0.7;
        }
        
        .btn-logout {
            display: block;
            width: 100%;
            padding: 8px;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            cursor: pointer;
            border-radius: 4px;
            font-size: 13px;
            text-align: center;
            text-decoration: none;
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.2);
        }
        
        /* Main Content */
        .portal-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
        }
        
        /* Header */
        .portal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        /* Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .stat-card .stat-icon.blue { background: #e3f2fd; color: #1976d2; }
        .stat-card .stat-icon.green { background: #e8f5e9; color: #4caf50; }
        .stat-card .stat-icon.orange { background: #fff3e0; color: #ff9800; }
        .stat-card .stat-icon.purple { background: #f3e5f5; color: #9c27b0; }
        
        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
        }
        
        .stat-card .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 5px;
        }
        
        /* Content Sections */
        .content-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .section-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .section-body {
            padding: 20px;
        }
        
        /* Tasks List */
        .task-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .task-item:last-child {
            border-bottom: none;
        }
        
        .task-priority {
            width: 4px;
            height: 40px;
            border-radius: 2px;
            margin-right: 15px;
        }
        
        .task-priority.urgente { background: #f44336; }
        .task-priority.alta { background: #ff9800; }
        .task-priority.normal { background: #2196f3; }
        .task-priority.baja { background: #9e9e9e; }
        
        .task-content {
            flex: 1;
        }
        
        .task-title {
            font-weight: 500;
            margin-bottom: 3px;
        }
        
        .task-meta {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .task-actions {
            display: flex;
            gap: 8px;
        }
        
        /* Buttons */
        .btn {
            padding: 8px 16px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--bg-light);
            color: var(--text-primary);
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
            background: var(--bg-light);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.2s;
        }
        
        .quick-action:hover {
            background: var(--primary);
            color: white;
        }
        
        .quick-action .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            margin-bottom: 10px;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        
        .empty-state .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            opacity: 0.5;
            margin-bottom: 10px;
        }
        
        /* Loading */
        .loading {
            text-align: center;
            padding: 60px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .portal-sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .portal-main {
                margin-left: 0;
            }
            
            .sidebar-footer {
                position: relative;
            }
        }

        /* Tablas portal */
        .portal-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        .portal-table th,
        .portal-table td {
            padding: 12px;
            text-align: left;
            vertical-align: middle;
            white-space: normal;
            word-break: normal;
            writing-mode: horizontal-tb;
        }
        .portal-table thead th {
            background: var(--bg-light);
            white-space: nowrap;
            font-weight: 600;
            font-size: 13px;
        }
        .portal-table th.col-proveedor,
        .portal-table td.col-proveedor { min-width: 160px; }
        .portal-table th.col-desc,
        .portal-table td.col-desc { min-width: 200px; }

        .portal-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .portal-filters select,
        .portal-filters input[type="date"] {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            min-width: 150px;
        }

        .portal-upload-area {
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 32px;
            text-align: center;
            transition: all .2s;
        }
        .portal-upload-area.dragover {
            border-color: var(--primary);
            background: #e3f2fd;
        }
        .portal-upload-toolbar {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .portal-mode-toggle {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
            flex-wrap: wrap;
            font-size: 14px;
        }

        .portal-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .portal-modal-overlay.open { display: flex; }
        .portal-modal {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            max-width: 620px;
            max-height: 90vh;
            overflow: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,.25);
        }
        .portal-modal.large { max-width: 960px; }
        .portal-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-light);
        }
        .portal-modal-body { padding: 20px; }
        .portal-modal-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .portal-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .portal-badge-recibido { background: #e3f2fd; color: #1565c0; }
        .portal-badge-parcial { background: #fff3e0; color: #ef6c00; }
        .portal-badge-procesado { background: #e8f5e9; color: #2e7d32; }
        .portal-badge-pendiente { background: #fafafa; color: #666; }
        .portal-badge-vinculado { background: #e8f5e9; color: #2e7d32; }
        .portal-badge-sin_vincular { background: #fff3e0; color: #e65100; }

        .bulk-queue {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        .bulk-queue-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        .bulk-queue-item:last-child { border-bottom: none; }
        .bulk-queue-item .bulk-status { font-size: 12px; color: var(--text-secondary); }
        .bulk-queue-item.ok .bulk-status { color: var(--success); }
        .bulk-queue-item.err .bulk-status { color: var(--danger); }
        .bulk-queue-item.run .bulk-status { color: var(--primary); }
    </style>
</head>
<body>
<?php
// Obtener datos del usuario
$user = wp_get_current_user();
$user_role = Riverso_POS_Permissions::get_riverso_role();
$role_name = Riverso_POS_Permissions::ROLES[$user_role]['name'] ?? ($user_role === 'administrator' ? 'Administrador' : 'Usuario');
$modules = Riverso_POS_Permissions::get_accessible_modules();
$current_page = get_query_var('riverso_portal', 'dashboard');
$catalog_initial_product = 0;
$catalog_initial_hash = '';
$nonce = wp_create_nonce('riverso_pos_nonce');
$default_intake_mode = riverso_get_setting('default_intake_mode', 'recepcion');

// Obtener estadísticas
global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';
$user_id = get_current_user_id();

$stats = [
    'tareas_pendientes' => $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}tareas WHERE (asignado_a = %d OR asignado_a IS NULL) AND estado NOT IN ('completada', 'cancelada')",
        $user_id
    )) ?? 0,
    'tareas_hoy' => $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}tareas WHERE completado_en >= CURDATE() AND estado = 'completada'"
    ) ?? 0,
];

if (current_user_can('riverso_view_invoices')) {
    $stats['facturas_pendientes'] = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pendiente'") ?? 0;
}

if (current_user_can('riverso_view_warehouse')) {
    $stats['ubicaciones_activas'] = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ubicaciones WHERE activo = 1") ?? 0;
}

// Obtener tareas pendientes
$tareas = $wpdb->get_results($wpdb->prepare(
    "SELECT t.*, u.display_name as creador_nombre FROM {$prefix}tareas t
     LEFT JOIN {$wpdb->users} u ON t.creado_por = u.ID
     WHERE (t.asignado_a = %d OR t.asignado_a IS NULL) AND t.estado NOT IN ('completada', 'cancelada')
     ORDER BY FIELD(t.prioridad, 'urgente', 'alta', 'normal', 'baja'), t.created_at DESC LIMIT 5",
    $user_id
), ARRAY_A) ?? [];
?>

<div class="portal-wrapper">
    <!-- Sidebar -->
    <aside class="portal-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">R</div>
            <div>
                <div class="sidebar-title">Riverso</div>
                <div class="sidebar-subtitle">Portal Interno</div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">Menú</div>
            
            <?php foreach ($modules as $key => $module): ?>
            <a href="<?php echo home_url('/interno/' . $key . '/'); ?>" 
               class="nav-item <?php echo $current_page === $key ? 'active' : ''; ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($module['icon']); ?>"></span>
                <?php echo esc_html($module['label']); ?>
            </a>
            <?php endforeach; ?>
            
            <div class="nav-section" style="margin-top: 20px;">WordPress</div>
            <a href="<?php echo admin_url(); ?>" class="nav-item" target="_blank">
                <span class="dashicons dashicons-admin-generic"></span>
                Ir a WP Admin
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user->display_name, 0, 1)); ?></div>
                <div>
                    <div class="user-name"><?php echo esc_html($user->display_name); ?></div>
                    <div class="user-role"><?php echo esc_html($role_name); ?></div>
                </div>
            </div>
            <a href="<?php echo wp_logout_url(home_url()); ?>" class="btn-logout">
                <span class="dashicons dashicons-exit" style="font-size: 16px; vertical-align: middle;"></span>
                Cerrar Sesión
            </a>
        </div>
    </aside>
    
    <!-- Main Content -->
    <main class="portal-main">
        <div class="portal-header">
            <h1 class="page-title">
                <?php
                switch ($current_page) {
                    case 'dashboard': echo 'Dashboard'; break;
                    case 'tasks': echo 'Mis Tareas'; break;
                    case 'catalog': echo 'Catálogo'; break;
                    case 'warehouse': echo 'Bodega'; break;
                    case 'invoices': echo 'Facturas'; break;
                    case 'pos': echo 'Punto de Venta'; break;
                    case 'quotes': echo 'Cotizaciones'; break;
                    default: echo 'Dashboard';
                }
                ?>
            </h1>
            <div class="header-actions">
                <span style="color: var(--text-secondary); font-size: 14px;">
                    <?php echo date_i18n('l, j \d\e F Y'); ?>
                </span>
            </div>
        </div>
        
        <?php if ($current_page === 'dashboard'): ?>
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <span class="dashicons dashicons-clipboard"></span>
                </div>
                <div class="stat-value"><?php echo intval($stats['tareas_pendientes']); ?></div>
                <div class="stat-label">Tareas Pendientes</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon green">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="stat-value"><?php echo intval($stats['tareas_hoy']); ?></div>
                <div class="stat-label">Completadas Hoy</div>
            </div>
            
            <?php if (isset($stats['facturas_pendientes'])): ?>
            <div class="stat-card">
                <div class="stat-icon orange">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                </div>
                <div class="stat-value"><?php echo intval($stats['facturas_pendientes']); ?></div>
                <div class="stat-label">Facturas Pendientes</div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($stats['ubicaciones_activas'])): ?>
            <div class="stat-card">
                <div class="stat-icon purple">
                    <span class="dashicons dashicons-store"></span>
                </div>
                <div class="stat-value"><?php echo intval($stats['ubicaciones_activas']); ?></div>
                <div class="stat-label">Ubicaciones Activas</div>
            </div>
            <?php endif; ?>
        </div>
        
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <!-- Tareas Pendientes -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Tareas Pendientes</h2>
                    <a href="<?php echo home_url('/interno/tasks/'); ?>" class="btn btn-secondary btn-sm">Ver todas</a>
                </div>
                <div class="section-body">
                    <?php if (empty($tareas)): ?>
                    <div class="empty-state">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p>No tienes tareas pendientes</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($tareas as $tarea): ?>
                        <div class="task-item">
                            <div class="task-priority <?php echo esc_attr($tarea['prioridad']); ?>"></div>
                            <div class="task-content">
                                <div class="task-title"><?php echo esc_html($tarea['titulo']); ?></div>
                                <div class="task-meta">
                                    <?php echo esc_html($tarea['tipo']); ?> 
                                    • <?php echo date_i18n('j M', strtotime($tarea['created_at'])); ?>
                                    <?php if ($tarea['fecha_limite']): ?>
                                        • Límite: <?php echo date_i18n('j M', strtotime($tarea['fecha_limite'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="task-actions">
                                <?php 
                                    $target_url = riverso_resolve_task_target($tarea);
                                    if ($target_url):
                                ?>
                                    <a href="<?php echo esc_url($target_url); ?>" class="btn btn-info btn-sm">
                                        Ir a la tarea
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Accesos Rápidos -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Accesos Rápidos</h2>
                </div>
                <div class="section-body">
                    <div class="quick-actions">
                        <?php if (current_user_can('riverso_use_pos')): ?>
                        <a href="<?php echo home_url('/interno/pos/'); ?>" class="quick-action">
                            <span class="dashicons dashicons-cart"></span>
                            <span>Nueva Venta</span>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (current_user_can('riverso_create_tasks')): ?>
                        <a href="<?php echo admin_url('admin.php?page=riverso-tasks'); ?>" class="quick-action">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <span>Nueva Tarea</span>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (current_user_can('riverso_view_invoices')): ?>
                        <a href="<?php echo home_url('/interno/invoices/'); ?>" class="quick-action">
                            <span class="dashicons dashicons-upload"></span>
                            <span>Subir Factura</span>
                        </a>
                        <?php endif; ?>
                        
                        <?php if (current_user_can('riverso_view_warehouse')): ?>
                        <a href="<?php echo admin_url('admin.php?page=riverso-warehouse'); ?>" class="quick-action">
                            <span class="dashicons dashicons-store"></span>
                            <span>Ver Bodega</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'tasks'): ?>
        <!-- Página Tareas -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Mis Tareas</h2>
                <?php if (current_user_can('riverso_create_tasks')): ?>
                <button class="btn btn-primary" onclick="crearTarea()">
                    <span class="dashicons dashicons-plus-alt"></span> Nueva Tarea
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body" id="tasks-list">
                <?php
                $all_tasks = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.*, u.display_name as creador_nombre, ua.display_name as asignado_nombre
                     FROM {$prefix}tareas t
                     LEFT JOIN {$wpdb->users} u ON t.creado_por = u.ID
                     LEFT JOIN {$wpdb->users} ua ON t.asignado_a = ua.ID
                     WHERE (t.asignado_a = %d OR t.asignado_a IS NULL OR t.creado_por = %d)
                     ORDER BY FIELD(t.estado, 'en_progreso', 'pendiente', 'completada', 'cancelada'),
                              FIELD(t.prioridad, 'urgente', 'alta', 'normal', 'baja'), t.created_at DESC
                     LIMIT 50",
                    $user_id, $user_id
                ), ARRAY_A);
                
                if (empty($all_tasks)): ?>
                <div class="empty-state">
                    <span class="dashicons dashicons-clipboard"></span>
                    <p>No hay tareas</p>
                </div>
                <?php else: 
                    foreach ($all_tasks as $t): ?>
                <div class="task-item" data-id="<?php echo $t['id']; ?>">
                    <div class="task-priority <?php echo esc_attr($t['prioridad']); ?>"></div>
                    <div class="task-content">
                        <div class="task-title"><?php echo esc_html($t['titulo']); ?></div>
                        <div class="task-meta">
                            <span class="task-status <?php echo esc_attr($t['estado']); ?>"><?php echo esc_html(ucfirst($t['estado'])); ?></span>
                            • <?php echo esc_html($t['tipo']); ?>
                            <?php if ($t['asignado_nombre']): ?>
                                • Asignado a: <?php echo esc_html($t['asignado_nombre']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($t['estado'] !== 'completada' && $t['estado'] !== 'cancelada'): ?>
                    <div class="task-actions">
                        <?php 
                            $target_url = riverso_resolve_task_target($t);
                            if ($target_url):
                        ?>
                            <a href="<?php echo esc_url($target_url); ?>" class="btn btn-info btn-sm">Ir a la tarea</a>
                        <?php endif; ?>
                        <?php if (current_user_can('riverso_complete_tasks')): ?>
                        <button class="btn btn-primary btn-sm" onclick="completarTarea(<?php echo $t['id']; ?>)">Completar</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                    <?php endforeach;
                endif; ?>
            </div>
        </div>
        
        <?php elseif ($current_page === 'pos'): ?>
        <!-- Punto de Venta -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Punto de Venta</h2>
            </div>
            <div class="section-body">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    <div>
                        <div class="search-box" style="margin-bottom: 20px;">
                            <input type="text" id="pos-search" placeholder="Buscar producto por SKU o nombre..." 
                                   class="large-input" style="width: 100%; padding: 12px; font-size: 16px; border: 1px solid var(--border); border-radius: 4px;">
                        </div>
                        <div id="pos-results" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                    <div class="cart-panel" style="background: var(--bg-light); padding: 20px; border-radius: 8px;">
                        <h3 style="margin-bottom: 15px;">🛒 Carrito</h3>
                        <div id="pos-cart"></div>
                        <div class="cart-total" style="border-top: 2px solid var(--border); padding-top: 15px; margin-top: 15px;">
                            <div style="display: flex; justify-content: space-between; font-size: 20px; font-weight: bold;">
                                <span>Total:</span>
                                <span id="cart-total">$0</span>
                            </div>
                        </div>
                        <button class="btn btn-primary" style="width: 100%; margin-top: 15px; padding: 15px;" onclick="procesarVenta()">
                            Procesar Venta
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'warehouse'): ?>
        <!-- Bodega -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Ubicaciones de Bodega</h2>
                <?php if (current_user_can('riverso_edit_warehouse')): ?>
                <button class="btn btn-primary" onclick="nuevaUbicacion()">
                    <span class="dashicons dashicons-plus-alt"></span> Nueva Ubicación
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div class="locations-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                    <?php
                    $ubicaciones = $wpdb->get_results("SELECT * FROM {$prefix}ubicaciones WHERE activo = 1 ORDER BY codigo", ARRAY_A);
                    foreach ($ubicaciones as $ub): ?>
                    <div class="location-card" style="background: var(--bg-light); padding: 15px; border-radius: 8px;">
                        <div style="font-weight: bold; font-size: 18px;"><?php echo esc_html($ub['codigo']); ?></div>
                        <div style="color: var(--text-secondary); font-size: 14px;"><?php echo esc_html($ub['nombre']); ?></div>
                        <div style="margin-top: 10px; font-size: 12px; color: var(--text-secondary);">
                            <?php echo esc_html($ub['tipo']); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'invoices'): ?>
        <!-- Facturas (portal interno) -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Facturas DTE</h2>
                <?php if (current_user_can('riverso_process_invoices') || current_user_can('riverso_create_invoices')): ?>
                <button class="btn btn-primary" onclick="subirFactura()">
                    <span class="dashicons dashicons-upload"></span> Subir XML
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div class="portal-filters">
                    <select id="portal-filter-estado">
                        <option value="">Todos los estados</option>
                        <option value="recibido">Recibido</option>
                        <option value="parcial">Parcial</option>
                        <option value="procesado">Procesado</option>
                        <option value="rechazado">Rechazado</option>
                        <option value="sin_vincular">Flete sin asignar</option>
                        <option value="vinculado">Flete vinculado</option>
                    </select>
                    <select id="portal-filter-proveedor">
                        <option value="">Todos los proveedores</option>
                        <?php
                        $proveedores_list = $wpdb->get_results("SELECT id, nombre FROM {$prefix}proveedores WHERE activo = 1 ORDER BY nombre");
                        foreach ($proveedores_list as $prov) {
                            echo '<option value="' . esc_attr($prov->id) . '">' . esc_html($prov->nombre) . '</option>';
                        }
                        ?>
                    </select>
                    <input type="date" id="portal-filter-desde">
                    <input type="date" id="portal-filter-hasta">
                    <button type="button" class="btn btn-secondary" onclick="portalLoadInvoices(1)">Filtrar</button>
                </div>

                <div style="overflow-x:auto;">
                    <table class="portal-table" id="portal-invoices-table">
                        <thead>
                            <tr>
                                <th>Folio</th>
                                <th>Tipo</th>
                                <th class="col-proveedor">Proveedor</th>
                                <th>Fecha</th>
                                <th style="text-align:right;">Total</th>
                                <th>Items</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="portal-invoices-list">
                            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-secondary);">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="portal-invoices-pagination" style="margin-top:12px;font-size:13px;color:var(--text-secondary);"></div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'suppliers'): ?>
        <!-- Proveedores -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Proveedores</h2>
                <?php if (current_user_can('riverso_edit_suppliers')): ?>
                <button class="btn btn-primary" onclick="nuevoProveedor()">
                    <span class="dashicons dashicons-plus-alt"></span> Nuevo Proveedor
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                    <?php
                    $proveedores = $wpdb->get_results("SELECT * FROM {$prefix}proveedores WHERE activo = 1 ORDER BY nombre", ARRAY_A);
                    foreach ($proveedores as $prov): ?>
                    <div class="supplier-card" style="background: var(--bg-light); padding: 20px; border-radius: 8px;">
                        <div style="font-weight: bold; font-size: 16px;"><?php echo esc_html($prov['nombre']); ?></div>
                        <div style="color: var(--text-secondary); font-size: 14px; margin-top: 5px;">
                            RUT: <?php echo esc_html($prov['rut']); ?>
                        </div>
                        <?php if (!empty($prov['telefono'])): ?>
                        <div style="margin-top: 10px; font-size: 13px;">
                            📞 <?php echo esc_html($prov['telefono']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($prov['email'])): ?>
                        <div style="font-size: 13px;">
                            ✉️ <?php echo esc_html($prov['email']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'barcodes'): ?>
        <!-- Códigos de Barra -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Buscador de Códigos de Barra</h2>
            </div>
            <div class="section-body">
                <div style="max-width: 760px; margin: 0 auto;">
                    <p style="color:var(--text-secondary);margin-bottom:12px;text-align:center;">
                        Busca en la tienda local por código de barra, SKU o nombre de producto.
                    </p>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="text" id="barcode-input" placeholder="Escanea, pega un código, SKU o nombre..."
                               style="flex:1; padding:18px; font-size:20px; text-align:center; border:2px solid var(--primary); border-radius:8px;"
                               autocomplete="off" autofocus>
                        <button type="button" id="barcode-search-btn" class="btn btn-primary" style="padding:18px 24px;">Buscar</button>
                    </div>
                    <div id="barcode-stats" style="margin-top:12px;font-size:12px;color:var(--text-secondary);text-align:center;"></div>
                    <div id="barcode-result" style="margin-top: 24px;"></div>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'codes'): ?>
        <!-- Códigos Proveedor -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Vinculación Códigos Proveedor → SKU</h2>
            </div>
            <div class="section-body">
                <div style="margin-bottom: 20px;">
                    <input type="text" id="code-search" placeholder="Buscar código proveedor o SKU..." 
                           style="width: 100%; max-width: 400px; padding: 10px; border: 1px solid var(--border); border-radius: 4px;">
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--bg-light);">
                            <th style="padding: 12px; text-align: left;">Código Proveedor</th>
                            <th style="padding: 12px; text-align: left;">Proveedor</th>
                            <th style="padding: 12px; text-align: left;">SKU Local</th>
                            <th style="padding: 12px; text-align: center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $codigos = $wpdb->get_results(
                            "SELECT c.*, p.nombre as proveedor_nombre FROM {$prefix}codigos c
                             LEFT JOIN {$prefix}proveedores p ON c.proveedor_id = p.id
                             ORDER BY c.created_at DESC LIMIT 50", ARRAY_A);
                        foreach ($codigos as $c): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 12px; font-family: monospace;"><?php echo esc_html($c['codigo_proveedor']); ?></td>
                            <td style="padding: 12px;"><?php echo esc_html($c['proveedor_nombre'] ?? '-'); ?></td>
                            <td style="padding: 12px; font-family: monospace;"><?php echo esc_html($c['sku_local'] ?? '-'); ?></td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($c['sku_local']): ?>
                                <span style="color: var(--success);">✓ Vinculado</span>
                                <?php else: ?>
                                <span style="color: var(--warning);">⚠ Pendiente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php elseif ($current_page === 'customer-quotes'): ?>
        <!-- Cotizaciones a Clientes -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Cotizaciones a Clientes</h2>
                <?php if (current_user_can('riverso_create_quotes')): ?>
                <button class="btn btn-primary" onclick="nuevaCotizacion()">
                    <span class="dashicons dashicons-plus-alt"></span> Nueva Cotización
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <p style="color: var(--text-secondary);">Gestiona las cotizaciones enviadas a clientes.</p>
                <a href="<?php echo admin_url('admin.php?page=riverso-customer-quotes'); ?>" class="btn btn-secondary" style="margin-top: 15px;">
                    Ver en WP Admin
                </a>
            </div>
        </div>
        
        <?php elseif ($current_page === 'received-quotes'): ?>
        <!-- Cotizaciones Recibidas (Proveedores) -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Cotizaciones de Proveedores</h2>
                <?php if (current_user_can('riverso_create_received_quotes')): ?>
                <button class="btn btn-primary" onclick="ingresarCotizacion()">
                    <span class="dashicons dashicons-plus-alt"></span> Ingresar Cotización
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <p style="color: var(--text-secondary);">Gestiona las cotizaciones recibidas de proveedores.</p>
                <a href="<?php echo admin_url('admin.php?page=riverso-received-quotes'); ?>" class="btn btn-secondary" style="margin-top: 15px;">
                    Ver en WP Admin
                </a>
            </div>
        </div>
        
        <?php elseif ($current_page === 'cost-history'): ?>
        <!-- Historial de Costos -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Historial de Costos</h2>
            </div>
            <div class="section-body">
                <p style="color: var(--text-secondary);">Revisa el historial de cambios de precios y costos de productos.</p>
                <a href="<?php echo admin_url('admin.php?page=riverso-cost-history'); ?>" class="btn btn-secondary" style="margin-top: 15px;">
                    Ver en WP Admin
                </a>
            </div>
        </div>
        
        <?php elseif ($current_page === 'employees'): ?>
        <!-- Empleados -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Gestión de Empleados</h2>
            </div>
            <div class="section-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                    <?php
                    $empleados = get_users(['role__in' => ['riverso_cotizador', 'riverso_vendedor', 'riverso_editor', 'administrator']]);
                    foreach ($empleados as $emp): 
                        $emp_role = Riverso_POS_Permissions::get_riverso_role($emp->ID);
                        $emp_role_name = Riverso_POS_Permissions::ROLES[$emp_role]['name'] ?? ($emp_role === 'administrator' ? 'Administrador' : 'Usuario');
                    ?>
                    <div class="employee-card" style="background: var(--bg-light); padding: 20px; border-radius: 8px; display: flex; align-items: center; gap: 15px;">
                        <div class="user-avatar" style="width: 50px; height: 50px; font-size: 20px;"><?php echo strtoupper(substr($emp->display_name, 0, 1)); ?></div>
                        <div>
                            <div style="font-weight: bold;"><?php echo esc_html($emp->display_name); ?></div>
                            <div style="font-size: 13px; color: var(--text-secondary);"><?php echo esc_html($emp_role_name); ?></div>
                            <div style="font-size: 12px; color: var(--text-secondary);"><?php echo esc_html($emp->user_email); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'catalog'): ?>
        <?php
        global $wpdb;
        $prefix = $wpdb->prefix . 'riverso_';
        if (!empty($_GET['pp'])) {
            $catalog_initial_product = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pb.woocommerce_product_id FROM {$prefix}producto_proveedor pp
                 INNER JOIN {$prefix}producto_base pb ON pb.id = pp.producto_base_id
                 WHERE pp.id = %d LIMIT 1",
                absint($_GET['pp'])
            ));
            $catalog_initial_hash = 'codigos';
        } elseif (!empty($_GET['base'])) {
            $catalog_initial_product = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT woocommerce_product_id FROM {$prefix}producto_base WHERE id = %d LIMIT 1",
                absint($_GET['base'])
            ));
        }
        ?>
        <!-- Catálogo MAMUT -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Catálogo MAMUT</h2>
                <input type="text" id="catalog-search" placeholder="Buscar producto..." style="padding:8px 12px;border:1px solid var(--border);border-radius:4px;min-width:240px;">
            </div>
            <div class="section-body">
                <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px;align-items:start;">
                    <div>
                        <div id="catalog-list" style="max-height:70vh;overflow-y:auto;border:1px solid var(--border);border-radius:8px;"></div>
                    </div>
                    <div id="catalog-editor" style="background:var(--bg-light);padding:20px;border-radius:8px;min-height:400px;">
                        <div class="empty-state">
                            <span class="dashicons dashicons-category"></span>
                            <p>Selecciona un producto para editar</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="catalog-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;padding:20px;">
            <div style="background:#fff;border-radius:8px;max-width:560px;width:100%;max-height:80vh;overflow:auto;padding:20px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <h3 id="catalog-modal-title" style="margin:0;">Detalle</h3>
                    <button type="button" id="catalog-modal-close" class="btn btn-secondary btn-sm">Cerrar</button>
                </div>
                <div id="catalog-modal-body"></div>
            </div>
        </div>

        <?php elseif ($current_page === 'reports'): ?>
        <!-- Reportes -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Reportes</h2>
            </div>
            <div class="section-body">
                <div class="quick-actions">
                    <a href="<?php echo admin_url('admin.php?page=riverso-reports&report=ventas'); ?>" class="quick-action">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <span>Ventas</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=riverso-reports&report=stock'); ?>" class="quick-action">
                        <span class="dashicons dashicons-archive"></span>
                        <span>Stock</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=riverso-reports&report=tareas'); ?>" class="quick-action">
                        <span class="dashicons dashicons-clipboard"></span>
                        <span>Tareas</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=riverso-reports&report=proveedores'); ?>" class="quick-action">
                        <span class="dashicons dashicons-groups"></span>
                        <span>Proveedores</span>
                    </a>
                </div>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Página no encontrada -->
        <div class="content-section">
            <div class="section-body">
                <div class="empty-state">
                    <span class="dashicons dashicons-warning"></span>
                    <h3>Página no encontrada</h3>
                    <p>El módulo "<?php echo esc_html($current_page); ?>" no existe o no tienes acceso.</p>
                    <p style="margin-top: 15px;">
                        <a href="<?php echo home_url('/interno/'); ?>" class="btn btn-primary">Volver al Dashboard</a>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal subir factura XML (portal) -->
<div id="portal-upload-modal" class="portal-modal-overlay">
    <div class="portal-modal large" id="portal-upload-modal-inner">
        <div class="portal-modal-header">
            <h3 style="margin:0;">Subir factura XML</h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="cerrarSubirFactura()">&times;</button>
        </div>
        <div class="portal-modal-body">
            <div id="portal-step-select">
                <div class="portal-mode-toggle">
                    <label><input type="radio" name="portal-upload-mode" value="single" checked> Un XML (con preview)</label>
                    <label><input type="radio" name="portal-upload-mode" value="bulk"> Carga masiva</label>
                </div>
                <div id="portal-upload-single">
                    <p style="color:var(--text-secondary);font-size:14px;margin-bottom:12px;">
                        El sistema detectará si es productos o transportista antes de procesar.
                    </p>
                    <input type="file" id="portal-xml-file" accept=".xml" style="display:none;">
                    <div class="portal-upload-area" id="portal-dropzone">
                        <span class="dashicons dashicons-upload" style="font-size:40px;width:40px;height:40px;color:var(--text-secondary);"></span>
                        <p style="margin-top:8px;color:var(--text-secondary);">Arrastra el XML aquí</p>
                    </div>
                    <div class="portal-upload-toolbar">
                        <button type="button" class="btn btn-primary" id="portal-btn-browse">
                            <span class="dashicons dashicons-open-folder"></span> Buscar archivos
                        </button>
                    </div>
                    <p id="portal-file-name" style="text-align:center;font-size:13px;color:var(--text-secondary);margin-top:8px;"></p>
                </div>
                <div id="portal-upload-bulk" style="display:none;">
                    <p style="color:var(--text-secondary);font-size:14px;margin-bottom:12px;">
                        Varios XML en secuencia. Los fletes quedan sin asignar hasta vincularlos manualmente.
                    </p>
                    <input type="file" id="portal-xml-bulk" accept=".xml" multiple style="display:none;">
                    <div class="portal-upload-area" id="portal-bulk-dropzone">
                        <span class="dashicons dashicons-media-default" style="font-size:40px;width:40px;height:40px;"></span>
                        <p style="margin-top:8px;color:var(--text-secondary);">Arrastra varios XML aquí</p>
                    </div>
                    <div class="portal-upload-toolbar">
                        <button type="button" class="btn btn-primary" id="portal-btn-browse-bulk">Buscar archivos XML</button>
                        <button type="button" class="btn btn-primary" id="portal-btn-start-bulk" disabled>Procesar todos</button>
                    </div>
                    <div id="portal-bulk-queue" class="bulk-queue" style="display:none;margin-top:12px;"></div>
                </div>
            </div>
            <div id="portal-step-confirm" style="display:none;">
                <div id="portal-intake-gaps" style="display:none;margin-bottom:12px;padding:10px 12px;background:#fff8e5;border:1px solid #f0c36d;border-radius:6px;font-size:13px;"></div>
                <div id="portal-xml-preview" style="padding:12px;background:var(--bg-light);border-radius:6px;font-size:13px;margin-bottom:12px;">
                    <div id="portal-preview-summary"></div>
                    <div style="overflow-x:auto;margin-top:10px;">
                        <table class="portal-table" id="portal-preview-items" style="font-size:12px;">
                            <thead><tr><th>#</th><th class="col-desc">Descripción</th><th>Tipo</th><th style="text-align:right;">Monto</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <strong>Tipo de documento</strong>
                    <p id="portal-detection-motivo" style="font-size:12px;color:var(--text-secondary);margin:6px 0;"></p>
                    <label><input type="radio" name="portal-documento-tipo" value="productos"> Productos</label><br>
                    <label><input type="radio" name="portal-documento-tipo" value="envio"> Transportista / flete</label>
                </div>
                <div id="portal-link-wrap" style="display:none;margin-bottom:12px;">
                    <label>Vincular a factura de productos <em>(opcional)</em></label>
                    <select id="portal-link-factura" style="width:100%;margin-top:6px;padding:8px;">
                        <option value="">— Dejar sin asignar —</option>
                    </select>
                </div>
                <div id="portal-modo-wrap" style="margin-bottom:12px;">
                    <label><input type="radio" name="portal-modo" value="recepcion" <?php echo $default_intake_mode === 'recepcion' ? 'checked' : ''; ?>> Recepción completa</label><br>
                    <label><input type="radio" name="portal-modo" value="solo_costos" <?php echo $default_intake_mode === 'solo_costos' ? 'checked' : ''; ?>> Solo costos (sin bodega)</label>
                </div>
                <label style="font-size:13px;font-weight:600;">Proveedor</label>
                <input type="text" id="portal-prov-nombre" placeholder="Razón social" style="width:100%;margin:6px 0;padding:8px;border:1px solid var(--border);border-radius:4px;">
                <input type="text" id="portal-prov-rut" placeholder="RUT" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;" readonly>
            </div>
            <div id="portal-upload-result" style="margin-top:12px;font-size:14px;"></div>
        </div>
        <div class="portal-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="cerrarSubirFactura()">Cancelar</button>
            <button type="button" class="btn btn-secondary" id="portal-btn-change" style="display:none;" onclick="resetPortalUpload()">Cambiar archivo</button>
            <button type="button" class="btn btn-primary" id="portal-btn-confirm" style="display:none;" onclick="procesarSubirFactura()">Confirmar</button>
        </div>
    </div>
</div>

<!-- Modal detalle factura (portal) -->
<div id="portal-detail-modal" class="portal-modal-overlay">
    <div class="portal-modal large">
        <div class="portal-modal-header">
            <h3 style="margin:0;">Factura <span id="portal-detail-folio"></span></h3>
            <button type="button" class="btn btn-secondary btn-sm" onclick="cerrarDetalleFactura()">&times;</button>
        </div>
        <div class="portal-modal-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;font-size:14px;">
                <div><strong>Proveedor:</strong> <span id="portal-detail-proveedor"></span></div>
                <div><strong>RUT:</strong> <span id="portal-detail-rut"></span></div>
                <div><strong>Fecha:</strong> <span id="portal-detail-fecha"></span></div>
                <div><strong>Total:</strong> <span id="portal-detail-total"></span></div>
            </div>

            <div id="portal-detail-shipping-section" style="display:none;margin-bottom:16px;padding:12px;background:#f0f6fc;border-radius:6px;">
                <strong>Fletes vinculados</strong>
                <div id="portal-detail-shipping-linked" style="margin-top:8px;"></div>
                <div id="portal-detail-shipping-assign" style="margin-top:12px;display:none;">
                    <label><strong>Asignar flete pendiente</strong></label>
                    <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
                        <select id="portal-detail-assign-flete-id" style="flex:1;min-width:200px;padding:8px;"></select>
                        <button type="button" class="btn btn-primary btn-sm" id="portal-btn-assign-flete">Vincular flete</button>
                    </div>
                </div>
            </div>

            <div id="portal-detail-envio-section" style="display:none;margin-bottom:16px;padding:12px;background:#fff8e5;border-radius:6px;">
                <strong>Vincular a facturas de productos</strong>
                <p class="description" style="margin:6px 0 8px;">Un mismo flete puede repartirse entre varias facturas de productos.</p>
                <div id="portal-detail-envio-linked" style="margin-bottom:10px;"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <select id="portal-detail-envio-target-id" style="flex:1;min-width:200px;padding:8px;"></select>
                    <button type="button" class="btn btn-primary btn-sm" id="portal-btn-envio-assign">Vincular</button>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="portal-btn-envio-unassign-all" style="margin-top:8px;display:none;">Desvincular todas</button>
            </div>

            <div style="overflow-x:auto;">
                <table class="portal-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cód. Prov.</th>
                            <th class="col-desc">Descripción</th>
                            <th>Cant.</th>
                            <th style="text-align:right;">Precio</th>
                            <th style="text-align:right;">Total</th>
                            <th>SKU Local</th>
                            <th>SKU Online</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="portal-detail-items"></tbody>
                </table>
            </div>
        </div>
        <div class="portal-modal-footer">
            <button type="button" class="btn btn-secondary" onclick="cerrarDetalleFactura()">Cerrar</button>
        </div>
    </div>
</div>

<script>
// Nonce para AJAX
const riversoNonce = '<?php echo $nonce; ?>';
const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
const canDeleteInvoices = <?php echo (current_user_can('riverso_process_invoices') || current_user_can('riverso_create_invoices')) ? 'true' : 'false'; ?>;
const canProcessInvoices = canDeleteInvoices;
let portalDetailFacturaId = null;

function completarTarea(id) {
    if (!confirm('¿Marcar tarea como completada?')) return;
    fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'riverso_complete_task', nonce: riversoNonce, task_id: id})
    }).then(r => r.json()).then(data => {
        if (data.success) location.reload();
        else alert(data.data?.message || 'Error al completar tarea');
    });
}

function crearTarea() {
    window.location.href = '<?php echo admin_url('admin.php?page=riverso-tasks&action=new'); ?>';
}

let portalPreviewData = null;
let portalBulkFiles = [];
const portalDefaultModo = '<?php echo esc_js($default_intake_mode); ?>';
const portalOnInvoicesPage = <?php echo $current_page === 'invoices' ? 'true' : 'false'; ?>;

function portalSetInputFiles(input, fileList) {
    if (!input || !fileList || !fileList.length) return false;
    const dt = new DataTransfer();
    Array.from(fileList).forEach(f => dt.items.add(f));
    input.files = dt.files;
    return input.files.length > 0;
}

function resetPortalUpload() {
    portalPreviewData = null;
    portalBulkFiles = [];
    const xmlFile = document.getElementById('portal-xml-file');
    const xmlBulk = document.getElementById('portal-xml-bulk');
    if (xmlFile) xmlFile.value = '';
    if (xmlBulk) xmlBulk.value = '';
    const singleRadio = document.querySelector('input[name="portal-upload-mode"][value="single"]');
    if (singleRadio) singleRadio.checked = true;
    document.getElementById('portal-upload-single').style.display = 'block';
    document.getElementById('portal-upload-bulk').style.display = 'none';
    document.getElementById('portal-step-select').style.display = 'block';
    document.getElementById('portal-step-confirm').style.display = 'none';
    document.getElementById('portal-btn-change').style.display = 'none';
    document.getElementById('portal-btn-confirm').style.display = 'none';
    document.getElementById('portal-upload-result').textContent = '';
    document.getElementById('portal-file-name').textContent = '';
    const gaps = document.getElementById('portal-intake-gaps');
    if (gaps) { gaps.style.display = 'none'; gaps.innerHTML = ''; }
    const previewBody = document.querySelector('#portal-preview-items tbody');
    if (previewBody) previewBody.innerHTML = '';
    const bq = document.getElementById('portal-bulk-queue');
    if (bq) { bq.style.display = 'none'; bq.innerHTML = ''; }
    document.getElementById('portal-btn-start-bulk')?.setAttribute('disabled', 'disabled');
}

function subirFactura() {
    resetPortalUpload();
    document.getElementById('portal-upload-modal')?.classList.add('open');
}

function cerrarSubirFactura() {
    document.getElementById('portal-upload-modal')?.classList.remove('open');
}

function cerrarDetalleFactura() {
    document.getElementById('portal-detail-modal')?.classList.remove('open');
    portalDetailFacturaId = null;
}

function portalUpdateTipoUi() {
    const tipo = document.querySelector('input[name="portal-documento-tipo"]:checked')?.value;
    document.getElementById('portal-link-wrap').style.display = tipo === 'envio' ? 'block' : 'none';
    document.getElementById('portal-modo-wrap').style.display = tipo === 'envio' ? 'none' : 'block';
}

document.querySelectorAll('input[name="portal-documento-tipo"]').forEach(el => {
    el.addEventListener('change', portalUpdateTipoUi);
});

document.querySelectorAll('input[name="portal-upload-mode"]').forEach(el => {
    el.addEventListener('change', function() {
        const bulk = this.value === 'bulk';
        document.getElementById('portal-upload-single').style.display = bulk ? 'none' : 'block';
        document.getElementById('portal-upload-bulk').style.display = bulk ? 'block' : 'none';
        document.getElementById('portal-step-confirm').style.display = 'none';
        document.getElementById('portal-btn-change').style.display = 'none';
        document.getElementById('portal-btn-confirm').style.display = 'none';
        document.getElementById('portal-upload-result').textContent = '';
    });
});

function portalPreviewFile(file) {
    const fd = new FormData();
    fd.append('action', 'riverso_preview_invoice_xml');
    fd.append('nonce', riversoNonce);
    fd.append('xml_file', file);
    return fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json());
}

function portalUploadFile(file, fields) {
    const fd = new FormData();
    fd.append('action', 'riverso_upload_invoice');
    fd.append('nonce', riversoNonce);
    fd.append('xml_file', file);
    Object.entries(fields || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
    return fetch(ajaxUrl, { method: 'POST', body: fd }).then(r => r.json());
}

function portalShowConfirm(d) {
    portalPreviewData = d;
    const det = d.detection || {};
    const tipoSugerido = det.tipo === 'mixto' ? 'productos' : (det.tipo || 'productos');

    document.getElementById('portal-preview-summary').innerHTML =
        `<strong>${d.emisor?.razon_social || '—'}</strong> · RUT ${d.emisor?.rut || '—'}<br>` +
        `Folio <strong>${d.folio}</strong> · Fecha ${d.fecha_emision || '—'} · Total <strong>$${Number(d.total || 0).toLocaleString('es-CL')}</strong>`;

    const tbody = document.querySelector('#portal-preview-items tbody');
    if (tbody) {
        tbody.innerHTML = '';
        const items = d.items_preview || [];
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);">Sin líneas de detalle</td></tr>';
        } else {
            items.slice(0, 15).forEach(it => {
                const badge = it.tipo === 'envio'
                    ? '<span style="color:#b45309;">Flete</span>'
                    : '<span style="color:#15803d;">Producto</span>';
                tbody.innerHTML += `<tr>
                    <td>${it.linea}</td>
                    <td>${it.nombre}</td>
                    <td>${badge}</td>
                    <td style="text-align:right;">$${Number(it.monto || 0).toLocaleString('es-CL')}</td>
                </tr>`;
            });
            if (items.length > 15) {
                tbody.innerHTML += `<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);">… y ${items.length - 15} líneas más</td></tr>`;
            }
        }
    }

    document.getElementById('portal-detection-motivo').innerHTML =
        `<strong>Detección (${det.confianza || '—'}):</strong> ${det.motivo || ''}`;

    const tipoRadio = document.querySelector(`input[name="portal-documento-tipo"][value="${tipoSugerido}"]`);
    if (tipoRadio) {
        tipoRadio.checked = true;
    } else {
        document.querySelector('input[name="portal-documento-tipo"][value="productos"]').checked = true;
    }
    portalUpdateTipoUi();

    const link = document.getElementById('portal-link-factura');
    link.innerHTML = '<option value="">— Dejar sin asignar —</option>';
    (d.facturas_productos || []).forEach(f => {
        link.innerHTML += `<option value="${f.id}">Folio ${f.folio} — ${f.proveedor_nombre || ''}</option>`;
    });
    document.getElementById('portal-prov-nombre').value = d.proveedor_existente?.nombre || d.emisor?.razon_social || '';
    document.getElementById('portal-prov-rut').value = d.emisor?.rut || '';

    const gapsEl = document.getElementById('portal-intake-gaps');
    const gaps = d.missing_gaps || [];
    if (gapsEl) {
        if (gaps.length) {
            gapsEl.innerHTML = '<strong style="color:#9a6700;">Complete antes de confirmar:</strong><ul style="margin:6px 0 0 18px;">' +
                gaps.map(g => `<li>${g.message || g.label || ''}</li>`).join('') + '</ul>';
            gapsEl.style.display = 'block';
        } else {
            gapsEl.style.display = 'none';
            gapsEl.innerHTML = '';
        }
    }

    document.getElementById('portal-step-select').style.display = 'none';
    document.getElementById('portal-step-confirm').style.display = 'block';
    document.getElementById('portal-btn-change').style.display = 'inline-block';
    document.getElementById('portal-btn-confirm').style.display = 'inline-block';

    const modalInner = document.getElementById('portal-upload-modal-inner');
    if (modalInner) modalInner.scrollTop = 0;
}

function procesarSubirFactura() {
    const fileInput = document.getElementById('portal-xml-file');
    const result = document.getElementById('portal-upload-result');
    const tipo = document.querySelector('input[name="portal-documento-tipo"]:checked')?.value || 'productos';
    if (!fileInput?.files?.length) {
        result.innerHTML = '<span style="color:var(--danger);">Seleccione un XML.</span>';
        return;
    }
    result.textContent = 'Procesando...';
    portalUploadFile(fileInput.files[0], {
        documento_tipo: tipo,
        link_to_factura_id: document.getElementById('portal-link-factura').value || '',
        modo_ingreso: document.querySelector('input[name="portal-modo"]:checked')?.value || portalDefaultModo,
        proveedor_modo: 'xml',
        proveedor_nombre: document.getElementById('portal-prov-nombre')?.value || '',
        proveedor_rut: document.getElementById('portal-prov-rut')?.value || ''
    }).then(data => {
        if (data.success) {
            const extra = data.data?.resumen?.documento_tipo === 'envio' && !data.data?.resumen?.vinculado_a_factura
                ? ' (sin asignar)'
                : '';
            result.innerHTML = `<span style="color:var(--success);">✓ ${data.data.message}${extra}</span>`;
            if (portalOnInvoicesPage) {
                setTimeout(() => { cerrarSubirFactura(); portalLoadInvoices(1); }, 1200);
            } else {
                setTimeout(() => location.reload(), 1800);
            }
        } else if (data.data?.needs_input) {
            alert((data.data.gaps || []).map(g => g.message).join('\n') || data.data.message);
        } else {
            result.innerHTML = `<span style="color:var(--danger);">${data.data?.message || 'Error'}</span>`;
        }
    }).catch(() => { result.innerHTML = '<span style="color:var(--danger);">Error de conexión</span>'; });
}

function portalSetBulkFiles(fileList) {
    portalBulkFiles = Array.from(fileList).filter(f => /\.xml$/i.test(f.name));
    const q = document.getElementById('portal-bulk-queue');
    const btn = document.getElementById('portal-btn-start-bulk');
    if (!q) return;
    q.innerHTML = '';
    if (!portalBulkFiles.length) {
        q.style.display = 'none';
        btn?.setAttribute('disabled', 'disabled');
        return;
    }
    portalBulkFiles.forEach((f, i) => {
        q.innerHTML += `<div class="bulk-queue-item" data-pidx="${i}"><span>${f.name}</span><span class="bulk-status">Pendiente</span></div>`;
    });
    q.style.display = 'block';
    btn?.removeAttribute('disabled');
}

async function portalProcessBulk() {
    if (!portalBulkFiles.length) return;
    const result = document.getElementById('portal-upload-result');
    const btn = document.getElementById('portal-btn-start-bulk');
    btn?.setAttribute('disabled', 'disabled');
    result.textContent = 'Procesando carga masiva…';
    let ok = 0, err = 0;
    for (let i = 0; i < portalBulkFiles.length; i++) {
        const file = portalBulkFiles[i];
        const row = document.querySelector(`.bulk-queue-item[data-pidx="${i}"]`);
        if (row) { row.classList.add('run'); row.querySelector('.bulk-status').textContent = 'Analizando…'; }
        const preview = await portalPreviewFile(file);
        if (!preview.success) {
            if (row) { row.classList.remove('run'); row.classList.add('err'); row.querySelector('.bulk-status').textContent = preview.data?.message || 'Error'; }
            err++; continue;
        }
        const tipo = preview.data.detection?.tipo === 'envio' ? 'envio' : 'productos';
        if (row) row.querySelector('.bulk-status').textContent = 'Subiendo…';
        const emisor = preview.data.emisor || {};
        const upload = await portalUploadFile(file, {
            documento_tipo: tipo,
            modo_ingreso: tipo === 'envio' ? 'solo_costos' : portalDefaultModo,
            proveedor_modo: 'xml',
            proveedor_nombre: emisor.razon_social || '',
            proveedor_rut: emisor.rut || '',
            link_to_factura_id: ''
        });
        if (upload.success) {
            const note = tipo === 'envio' ? ' (sin asignar)' : '';
            if (row) { row.classList.remove('run'); row.classList.add('ok'); row.querySelector('.bulk-status').textContent = '✓ Folio ' + (upload.data?.resumen?.folio || '') + note; }
            ok++;
        } else {
            if (row) { row.classList.remove('run'); row.classList.add('err'); row.querySelector('.bulk-status').textContent = upload.data?.message || 'Error'; }
            err++;
        }
    }
    result.innerHTML = `<span style="color:var(--success);">Terminado: ${ok} OK, ${err} error/omitidos.</span>`;
    btn?.removeAttribute('disabled');
    if (portalOnInvoicesPage) portalLoadInvoices(1);
}

function portalLoadInvoices(page) {
    const tbody = document.getElementById('portal-invoices-list');
    if (!tbody) return;
    const body = new URLSearchParams({
        action: 'riverso_get_invoices_list',
        nonce: riversoNonce,
        page: page || 1,
        estado: document.getElementById('portal-filter-estado')?.value || '',
        proveedor_id: document.getElementById('portal-filter-proveedor')?.value || '',
        fecha_desde: document.getElementById('portal-filter-desde')?.value || '',
        fecha_hasta: document.getElementById('portal-filter-hasta')?.value || ''
    });
    fetch(ajaxUrl, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                tbody.innerHTML = `<tr><td colspan="8">${res.data?.message || 'Error'}</td></tr>`;
                return;
            }
            if (!Array.isArray(res.data.facturas)) {
                tbody.innerHTML = '<tr><td colspan="8">Error: respuesta inválida del servidor</td></tr>';
                return;
            }
            const tipos = {33:'Factura',34:'F.Exenta',52:'Guía',61:'N.Crédito'};
            if (!res.data.facturas.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;">No hay facturas</td></tr>';
                return;
            }
            tbody.innerHTML = res.data.facturas.map(f => {
                const isEnvio = f.documento_subtipo === 'envio';
                const tipoLabel = isEnvio ? '<span style="color:#b45309;font-weight:600;">Flete</span>' : '<span style="color:#15803d;">Productos</span>';
                const vinculadas = parseInt(f.facturas_vinculadas || 0, 10);
                const itemsCol = isEnvio
                    ? (vinculadas > 0 ? `${vinculadas} factura(s)` : 'Sin asignar')
                    : `${f.items_vinculados}/${f.total_items}` + (parseInt(f.fletes_vinculados) > 0 ? ` · ${f.fletes_vinculados} flete(s)` : '');
                const estadoLabel = (f.estado || '').replace(/_/g, ' ');
                const linkBtn = (canProcessInvoices && isEnvio)
                    ? `<button type="button" class="btn btn-primary btn-sm" style="margin-left:4px;" onclick="portalVincularFlete(${f.id})" title="Vincular a facturas de productos">Vincular</button>`
                    : '';
                return `
                <tr>
                    <td><strong>${f.folio}</strong></td>
                    <td>${tipoLabel}</td>
                    <td class="col-proveedor">${f.proveedor_nombre || '—'}</td>
                    <td>${f.fecha_emision || ''}</td>
                    <td style="text-align:right;">$${Number(f.monto_total).toLocaleString('es-CL')}</td>
                    <td>${itemsCol}</td>
                    <td><span class="portal-badge portal-badge-${f.estado}">${estadoLabel}</span></td>
                    <td>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="portalVerFactura(${f.id})">Ver</button>
                        ${linkBtn}
                        ${(canDeleteInvoices && f.can_delete) ? `<button type="button" class="btn btn-sm" style="color:#b32d2e;margin-left:4px;" onclick="portalEliminarFactura(${f.id}, '${String(f.folio).replace(/'/g, "\\'")}')" title="Eliminar subida">Eliminar</button>` : ''}
                    </td>
                </tr>`;
            }).join('');
            const pag = document.getElementById('portal-invoices-pagination');
            if (pag) pag.textContent = `Página ${res.data.page} de ${res.data.total_pages} (${res.data.total} facturas)`;
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="8">Error de conexión al cargar facturas</td></tr>';
        });
}

function portalRenderInvoiceDetail(f) {
    portalDetailFacturaId = f.id;
    document.getElementById('portal-detail-folio').textContent = '#' + f.folio;
    document.getElementById('portal-detail-proveedor').textContent = f.proveedor_nombre || '';
    document.getElementById('portal-detail-rut').textContent = f.proveedor_rut || '';
    document.getElementById('portal-detail-fecha').textContent = f.fecha_emision || '';
    document.getElementById('portal-detail-total').textContent = '$' + Number(f.monto_total).toLocaleString('es-CL');

    const isEnvio = f.documento_subtipo === 'envio';
    const shipSection = document.getElementById('portal-detail-shipping-section');
    const envioSection = document.getElementById('portal-detail-envio-section');
    shipSection.style.display = 'none';
    envioSection.style.display = 'none';

    if (isEnvio) {
        envioSection.style.display = 'block';
        const vinculadas = f.facturas_productos_vinculadas || [];
        const linkedEl = document.getElementById('portal-detail-envio-linked');
        if (vinculadas.length) {
            linkedEl.innerHTML = '<ul style="margin:0;padding-left:18px;">' + vinculadas.map(fp => `
                <li>Folio <strong>${fp.folio}</strong> — ${fp.proveedor_nombre || ''} — $${Number(fp.monto_total || 0).toLocaleString('es-CL')}
                    ${fp.monto_asignado ? `<span style="color:#666;"> (flete: $${Number(fp.monto_asignado).toLocaleString('es-CL')})</span>` : ''}
                    ${canProcessInvoices ? `<button type="button" class="btn btn-secondary btn-sm portal-btn-unassign-producto" data-productos-id="${fp.id}" style="margin-left:6px;">Desvincular</button>` : ''}
                </li>`).join('') + '</ul>';
        } else {
            linkedEl.innerHTML = '<p style="margin:0;color:#666;">Sin facturas de productos vinculadas.</p>';
        }

        const target = document.getElementById('portal-detail-envio-target-id');
        target.innerHTML = '<option value="">— Seleccionar factura de productos —</option>';
        (f.facturas_productos_disponibles || []).forEach(fp => {
            if (!vinculadas.some(v => String(v.id) === String(fp.id))) {
                target.innerHTML += `<option value="${fp.id}">Folio ${fp.folio} — ${fp.proveedor_nombre || ''} — $${Number(fp.monto_total || 0).toLocaleString('es-CL')}</option>`;
            }
        });
        document.getElementById('portal-btn-envio-unassign-all').style.display = (canProcessInvoices && vinculadas.length > 1) ? 'inline-block' : 'none';
    } else {
        const fletes = f.fletes_vinculados || [];
        const pendientes = f.fletes_sin_vincular || [];
        if (fletes.length || pendientes.length) {
            shipSection.style.display = 'block';
            let html = '';
            if (fletes.length) {
                html += '<ul style="margin:0;padding-left:18px;">' + fletes.map(fl => `
                    <li>Folio <strong>${fl.folio}</strong> — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}
                        ${canProcessInvoices ? `<button type="button" class="btn btn-secondary btn-sm portal-btn-unassign-flete" data-envio-id="${fl.id}" style="margin-left:6px;">Desvincular</button>` : ''}
                    </li>`).join('') + '</ul>';
                if (f.costo_envio_vinculado) {
                    html += `<p style="margin:8px 0 0;color:#666;">Total fletes: <strong>$${Number(f.costo_envio_vinculado).toLocaleString('es-CL')}</strong></p>`;
                }
            } else {
                html = '<p style="margin:0;color:#666;">Sin fletes vinculados.</p>';
            }
            document.getElementById('portal-detail-shipping-linked').innerHTML = html;

            const assignWrap = document.getElementById('portal-detail-shipping-assign');
            const sel = document.getElementById('portal-detail-assign-flete-id');
            if (pendientes.length && canProcessInvoices) {
                assignWrap.style.display = 'block';
                sel.innerHTML = '<option value="">— Seleccionar flete pendiente —</option>' +
                    pendientes.map(fl => `<option value="${fl.id}">Folio ${fl.folio} — ${fl.proveedor_nombre || ''} — $${Number(fl.monto_total || 0).toLocaleString('es-CL')}</option>`).join('');
            } else {
                assignWrap.style.display = 'none';
            }
        }
    }

    document.getElementById('portal-detail-items').innerHTML = (f.items || []).map(it => `
        <tr>
            <td>${it.linea || it.numero_linea || ''}</td>
            <td><code>${it.codigo_proveedor || '—'}</code></td>
            <td class="col-desc">${it.descripcion || it.nombre || ''}</td>
            <td>${it.cantidad}</td>
            <td style="text-align:right;">$${Number(it.precio_unitario).toLocaleString('es-CL')}</td>
            <td style="text-align:right;">$${Number(it.monto_total).toLocaleString('es-CL')}</td>
            <td>${it.sku_local || '—'}</td>
            <td><code>${it.sku_online || '—'}</code></td>
            <td><span class="portal-badge portal-badge-${it.estado}">${it.estado}</span></td>
        </tr>
    `).join('');
}

function portalReloadInvoiceDetail() {
    if (!portalDetailFacturaId) return;
    portalVerFactura(portalDetailFacturaId);
}

function portalVincularFlete(envioId) {
    portalVerFactura(envioId);
    setTimeout(() => {
        document.getElementById('portal-detail-envio-target-id')?.focus();
    }, 300);
}

function portalVerFactura(id) {
    fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action: 'riverso_get_invoice', nonce: riversoNonce, factura_id: id })
    }).then(r => r.json()).then(res => {
        if (!res.success) { alert(res.data?.message || 'Error'); return; }
        portalRenderInvoiceDetail(res.data);
        document.getElementById('portal-detail-modal')?.classList.add('open');
    });
}

function portalAssignShipping(facturaProductosId, facturaEnvioId) {
    return fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'riverso_assign_shipping_invoice',
            nonce: riversoNonce,
            factura_productos_id: facturaProductosId,
            factura_envio_id: facturaEnvioId
        })
    }).then(r => r.json());
}

function portalUnassignShipping(facturaEnvioId, facturaProductosId) {
    const body = new URLSearchParams({
        action: 'riverso_unassign_shipping_invoice',
        nonce: riversoNonce,
        factura_envio_id: facturaEnvioId
    });
    if (facturaProductosId) body.append('factura_productos_id', facturaProductosId);
    return fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body
    }).then(r => r.json());
}

document.getElementById('portal-btn-assign-flete')?.addEventListener('click', () => {
    const envioId = document.getElementById('portal-detail-assign-flete-id')?.value;
    if (!envioId) { alert('Seleccione un flete'); return; }
    portalAssignShipping(portalDetailFacturaId, envioId).then(res => {
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(1); }
        else alert(res.data?.message || 'Error al vincular');
    });
});

document.getElementById('portal-btn-envio-assign')?.addEventListener('click', () => {
    const targetId = document.getElementById('portal-detail-envio-target-id')?.value;
    if (!targetId) { alert('Seleccione una factura de productos'); return; }
    portalAssignShipping(targetId, portalDetailFacturaId).then(res => {
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(1); }
        else alert(res.data?.message || 'Error al vincular');
    });
});

document.getElementById('portal-btn-envio-unassign-all')?.addEventListener('click', () => {
    if (!confirm('¿Desvincular este flete de TODAS las facturas de productos?')) return;
    portalUnassignShipping(portalDetailFacturaId).then(res => {
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(1); }
        else alert(res.data?.message || 'Error');
    });
});

document.getElementById('portal-detail-modal')?.addEventListener('click', e => {
    const prodBtn = e.target.closest('.portal-btn-unassign-producto');
    if (prodBtn) {
        if (!confirm('¿Desvincular esta factura del flete?')) return;
        portalUnassignShipping(portalDetailFacturaId, prodBtn.dataset.productosId).then(res => {
            if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(1); }
            else alert(res.data?.message || 'Error');
        });
        return;
    }
    const fleteBtn = e.target.closest('.portal-btn-unassign-flete');
    if (fleteBtn) {
        if (!confirm('¿Desvincular este flete?')) return;
        portalUnassignShipping(fleteBtn.dataset.envioId, portalDetailFacturaId).then(res => {
            if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(1); }
            else alert(res.data?.message || 'Error');
        });
    }
});

function portalEliminarFactura(id, folio) {
    if (!confirm(`¿Eliminar la factura folio ${folio}?\n\nSe revertirá la subida, ítems, costos y tareas asociadas. Esta acción quedará registrada en auditoría.`)) {
        return;
    }
    fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ action: 'riverso_delete_invoice', nonce: riversoNonce, factura_id: id })
    }).then(r => r.json()).then(res => {
        if (res.success) {
            portalLoadInvoices(1);
        } else {
            alert(res.data?.message || 'Error al eliminar');
        }
    }).catch(() => alert('Error de conexión'));
}

(function initPortalInvoicesUi() {
    const xmlFile = document.getElementById('portal-xml-file');
    const xmlBulk = document.getElementById('portal-xml-bulk');
    const dropzone = document.getElementById('portal-dropzone');
    const bulkDrop = document.getElementById('portal-bulk-dropzone');

    document.getElementById('portal-btn-browse')?.addEventListener('click', e => {
        e.preventDefault();
        e.stopPropagation();
        xmlFile.value = '';
        xmlFile.click();
    });
    dropzone?.addEventListener('click', e => {
        if (e.target.closest('#portal-btn-browse')) return;
        e.preventDefault();
        xmlFile.value = '';
        xmlFile.click();
    });
    document.getElementById('portal-btn-browse-bulk')?.addEventListener('click', e => {
        e.preventDefault();
        xmlBulk.value = '';
        xmlBulk.click();
    });
    document.getElementById('portal-btn-start-bulk')?.addEventListener('click', portalProcessBulk);

    xmlFile?.addEventListener('change', function() {
        if (!this.files?.length) return;
        document.getElementById('portal-file-name').textContent = 'Analizando: ' + this.files[0].name + '…';
        document.getElementById('portal-upload-result').textContent = '';
        portalPreviewFile(this.files[0]).then(data => {
            if (!data.success) {
                document.getElementById('portal-file-name').textContent = '';
                alert(data.data?.message || 'Error al leer XML');
                return;
            }
            document.getElementById('portal-file-name').textContent = 'Archivo: ' + this.files[0].name;
            portalShowConfirm(data.data);
        }).catch(() => {
            document.getElementById('portal-file-name').textContent = '';
            alert('Error de conexión al analizar XML');
        });
    });
    xmlBulk?.addEventListener('change', function() {
        if (this.files?.length) portalSetBulkFiles(this.files);
    });

    function bindDrop(el, onFiles) {
        if (!el) return;
        el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('dragover'); });
        el.addEventListener('dragleave', () => el.classList.remove('dragover'));
        el.addEventListener('drop', e => {
            e.preventDefault();
            el.classList.remove('dragover');
            if (e.dataTransfer.files?.length) onFiles(e.dataTransfer.files);
        });
    }
    bindDrop(dropzone, files => {
        if (portalSetInputFiles(xmlFile, files)) {
            xmlFile.dispatchEvent(new Event('change'));
        }
    });
    bindDrop(bulkDrop, files => portalSetBulkFiles(files));

    if (portalOnInvoicesPage) portalLoadInvoices(1);
})();

(function() {
    const listEl = document.getElementById('catalog-list');
    const editorEl = document.getElementById('catalog-editor');
    const searchEl = document.getElementById('catalog-search');
    const modalEl = document.getElementById('catalog-modal');
    const modalBody = document.getElementById('catalog-modal-body');
    const modalTitle = document.getElementById('catalog-modal-title');
    if (!listEl || !editorEl) return;

    let selectedId = 0;
    let currentItem = null;
    let providersCache = null;
    const catalogInitialProductId = <?php echo (int) $catalog_initial_product; ?>;
    const catalogInitialHash = <?php echo wp_json_encode($catalog_initial_hash); ?>;

    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function post(action, params) {
        const body = new URLSearchParams({action, nonce: riversoNonce, ...params});
        return fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body}).then(r => r.json());
    }

    function showModal(title, html) {
        if (!modalEl) return;
        modalTitle.textContent = title;
        modalBody.innerHTML = html;
        modalEl.style.display = 'flex';
    }

    function hideModal() {
        if (modalEl) modalEl.style.display = 'none';
    }

    document.getElementById('catalog-modal-close')?.addEventListener('click', hideModal);
    modalEl?.addEventListener('click', e => { if (e.target === modalEl) hideModal(); });

    function loadList(search) {
        listEl.innerHTML = '<div class="loading">Cargando...</div>';
        post('riverso_catalog_list', {search: search || '', limit: 100, offset: 0}).then(data => {
            if (!data.success) {
                listEl.innerHTML = '<div class="empty-state"><p>' + esc(data.data?.message || 'Error') + '</p></div>';
                return;
            }
            const items = data.data.items || [];
            if (!items.length) {
                listEl.innerHTML = '<div class="empty-state"><p>Sin productos</p></div>';
                return;
            }
            listEl.innerHTML = items.map(it => `
                <div class="catalog-item" data-id="${it.product_id}" style="padding:12px;border-bottom:1px solid var(--border);cursor:pointer;${selectedId==it.product_id?'background:#e3f2fd;':''}">
                    <div style="font-weight:600;">${esc(it.name)}</div>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">
                        ${esc((it.category_path||[]).join(' > ') || 'Sin categoría')}
                        • ${it.variations_count} SKU • ${esc(it.status)} • ${esc(it.publication_stage)}
                    </div>
                </div>`).join('');
            listEl.querySelectorAll('.catalog-item').forEach(el => el.addEventListener('click', () => loadProduct(el.dataset.id)));
        });
    }

    function gateBadge(status) {
        const colors = {approved:'#4caf50', pending:'#ff9800', rejected:'#f44336'};
        return `<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;background:${colors[status]||'#999'};color:#fff;">${esc(status)}</span>`;
    }

    function renderCodesPanel(codes, bases) {
        const panel = document.getElementById('codes-panel');
        if (!panel) return;
        const baseLabels = {};
        (bases || []).forEach(b => {
            baseLabels[b.id] = b.variation_label || b.canonical_sku || ('Base #' + b.id);
        });
        if (!codes.length) {
            panel.innerHTML = '<p style="margin:0;color:var(--text-secondary);">Sin códigos de proveedor vinculados</p>';
            return;
        }
        panel.innerHTML = codes.map(c => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #ddd;gap:8px;">
                <span>
                    <span style="font-size:11px;color:#666;display:block;">${esc(baseLabels[c.producto_base_id] || 'Variación')}</span>
                    <strong>${esc(c.proveedor_nombre || 'Proveedor')}</strong>: ${esc(c.codigo_proveedor)}
                </span>
                <button type="button" class="btn btn-sm btn-danger catalog-code-unlink" data-pp="${c.id}">Quitar</button>
            </div>`).join('');
        panel.querySelectorAll('.catalog-code-unlink').forEach(btn => btn.addEventListener('click', () => {
            if (!confirm('¿Desvincular este código?')) return;
            post('riverso_catalog_code_unlink', {pp_id: btn.dataset.pp}).then(d => {
                alert(d.success ? 'Código desvinculado' : (d.data?.message || 'Error'));
                if (d.success) loadProduct(selectedId);
            });
        }));
    }

    function renderVariationsPanel(bases) {
        const panel = document.getElementById('variations-panel');
        if (!panel) return;
        if (!bases.length) {
            panel.innerHTML = '<p style="color:var(--text-secondary);">Sin variaciones base</p>';
            return;
        }
        panel.innerHTML = bases.map(b => {
            const label = b.variation_label || b.nombre_canonico || ('Variación #' + b.id);
            const codes = (b.provider_codes || []).map(c =>
                `<span style="display:inline-block;background:#eee;border-radius:4px;padding:2px 6px;margin:2px;font-size:11px;">${esc(c.proveedor_nombre||'Prov')}: ${esc(c.codigo_proveedor)}</span>`
            ).join('') || '<span style="color:#999;font-size:12px;">Sin códigos proveedor</span>';
            return `
            <div class="variation-block" data-base-id="${b.id}" style="border:1px solid var(--border);border-radius:6px;padding:12px;margin-bottom:10px;background:#fff;">
                <div style="font-weight:600;margin-bottom:4px;">${esc(label)}</div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">
                    Base #${b.id} • SKU: ${esc(b.canonical_sku || '—')}
                    ${b.needs_local_sku ? ' • <strong style="color:#f44336;">Pendiente SKU local</strong>' : ''}
                </div>
                <div style="margin-bottom:8px;">${codes}</div>
                <div style="font-size:13px;font-weight:600;margin-bottom:6px;">Asignar SKU local</div>
                <div style="display:flex;gap:6px;margin-bottom:6px;">
                    <input type="text" class="var-sku-search" data-base="${b.id}" placeholder="Buscar SKU/nombre/barcode..." style="flex:1;padding:6px;border:1px solid var(--border);border-radius:4px;">
                    <button type="button" class="btn btn-secondary btn-sm var-sku-search-btn" data-base="${b.id}">Buscar</button>
                </div>
                <div class="var-sku-results" data-base="${b.id}" style="max-height:90px;overflow-y:auto;margin-bottom:6px;"></div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" class="var-sku-pick" data-base="${b.id}" placeholder="SKU local" style="flex:1;padding:6px;border:1px solid var(--border);border-radius:4px;">
                    <label style="font-size:11px;white-space:nowrap;"><input type="checkbox" class="var-sku-create" data-base="${b.id}"> Crear</label>
                    <button type="button" class="btn btn-primary btn-sm var-sku-assign-btn" data-base="${b.id}">Asignar</button>
                </div>
            </div>`;
        }).join('');

        panel.querySelectorAll('.var-sku-search-btn').forEach(btn => btn.addEventListener('click', () => {
            const baseId = btn.dataset.base;
            const q = panel.querySelector(`.var-sku-search[data-base="${baseId}"]`)?.value.trim();
            if (!q || q.length < 2) { alert('Escribe al menos 2 caracteres'); return; }
            post('riverso_catalog_search_local_sku', {q}).then(d => {
                const results = panel.querySelector(`.var-sku-results[data-base="${baseId}"]`);
                if (!d.success || !results) return;
                const items = d.data.items || [];
                results.innerHTML = items.length ? items.map(it => `
                    <div class="var-sku-pick-row" data-base="${baseId}" data-sku="${esc(it.sku)}" style="padding:4px;border-bottom:1px solid #ddd;cursor:pointer;font-size:12px;">
                        <strong>${esc(it.sku)}</strong> — ${esc(it.nombre)}
                    </div>`).join('') : '<p style="font-size:12px;">Sin resultados</p>';
                results.querySelectorAll('.var-sku-pick-row').forEach(el => el.addEventListener('click', () => {
                    const pick = panel.querySelector(`.var-sku-pick[data-base="${el.dataset.base}"]`);
                    if (pick) pick.value = el.dataset.sku;
                }));
            });
        }));

        panel.querySelectorAll('.var-sku-assign-btn').forEach(btn => btn.addEventListener('click', () => {
            const baseId = btn.dataset.base;
            const sku = panel.querySelector(`.var-sku-pick[data-base="${baseId}"]`)?.value.trim();
            const crear = panel.querySelector(`.var-sku-create[data-base="${baseId}"]`)?.checked;
            if (!sku) { alert('Indica un SKU local'); return; }
            post('riverso_catalog_assign_local_sku', {base_id: baseId, sku_local: sku, crear_nuevo: crear ? 1 : 0}).then(d => {
                alert(d.success ? 'SKU local asignado' : (d.data?.message || 'Error'));
                if (d.success) loadProduct(selectedId);
            });
        }));
    }

    function bindSupplierPicker() {
        const searchEl = document.getElementById('code-link-proveedor-search');
        const idEl = document.getElementById('code-link-proveedor-id');
        const resultsEl = document.getElementById('code-link-proveedor-results');
        const selectedEl = document.getElementById('code-link-proveedor-selected');
        if (!searchEl || !idEl) return;

        let searchTimer = null;
        searchEl.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const q = searchEl.value.trim();
            idEl.value = '';
            if (selectedEl) selectedEl.textContent = '';
            if (q.length < 2) {
                if (resultsEl) resultsEl.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(() => {
                post('riverso_catalog_search_suppliers', {search: q, limit: 15}).then(d => {
                    if (!d.success || !resultsEl) return;
                    const items = d.data.suppliers || [];
                    resultsEl.innerHTML = items.length ? items.map(s => `
                        <div class="supplier-pick" data-id="${s.id}" data-nombre="${esc(s.nombre)}" style="padding:6px;border-bottom:1px solid #ddd;cursor:pointer;font-size:13px;">
                            <strong>${esc(s.nombre)}</strong> <span style="color:#666;">(${esc(s.rut)})</span>
                        </div>`).join('') : '<p style="font-size:12px;padding:6px;">Sin proveedores. Crea uno nuevo abajo.</p>';
                    resultsEl.querySelectorAll('.supplier-pick').forEach(el => el.addEventListener('click', () => {
                        idEl.value = el.dataset.id;
                        searchEl.value = el.dataset.nombre;
                        if (selectedEl) selectedEl.textContent = 'Seleccionado: ' + el.dataset.nombre;
                        resultsEl.innerHTML = '';
                    }));
                });
            }, 250);
        });

        document.getElementById('code-link-new-proveedor')?.addEventListener('click', () => {
            const nombre = prompt('Nombre del proveedor:');
            if (!nombre) return;
            const rut = prompt('RUT del proveedor (sin puntos):');
            if (!rut) return;
            post('riverso_catalog_create_supplier', {nombre, rut}).then(d => {
                if (!d.success) { alert(d.data?.message || 'Error'); return; }
                const s = d.data.supplier;
                idEl.value = s.id;
                searchEl.value = s.nombre;
                if (selectedEl) selectedEl.textContent = 'Seleccionado: ' + s.nombre + (s.existing ? ' (existente)' : ' (nuevo)');
                alert(s.existing ? 'Proveedor ya existía, seleccionado.' : 'Proveedor creado.');
            });
        });
    }

    function bindCodeLinkForm() {
        document.getElementById('catalog-code-link-btn')?.addEventListener('click', () => {
            const baseId = document.getElementById('code-link-base')?.value;
            const proveedorId = document.getElementById('code-link-proveedor-id')?.value;
            const codigo = document.getElementById('code-link-codigo')?.value.trim();
            if (!baseId || !proveedorId || !codigo) {
                alert('Selecciona variación, proveedor (buscar por nombre) y código');
                return;
            }
            post('riverso_catalog_code_link', {base_id: baseId, proveedor_id: proveedorId, codigo}).then(d => {
                alert(d.success ? 'Código vinculado' : (d.data?.message || 'Error'));
                if (d.success) loadProduct(selectedId);
            });
        });
    }

    function loadCategoryTree() {
        post('riverso_category_tree', {parent_id: 0}).then(d => {
            const panel = document.getElementById('category-tree-panel');
            if (!d.success || !panel) { alert(d.data?.message || 'Error'); return; }
            const renderTree = (items, indent = 0) => items.map(t =>
                `<div style="margin-left:${indent*12}px;display:flex;gap:6px;align-items:center;padding:2px 0;">
                    <span>${esc(t.name)} (${t.count})</span>
                    <button type="button" class="btn btn-link btn-sm cat-rename" data-id="${t.id}" data-name="${esc(t.name)}" style="padding:0;font-size:11px;">Renombrar</button>
                    <button type="button" class="btn btn-link btn-sm cat-add-child" data-id="${t.id}" style="padding:0;font-size:11px;">+ Hijo</button>
                </div>${renderTree(t.children||[], indent+1)}`
            ).join('');
            panel.innerHTML = renderTree(d.data.tree) +
                `<div style="margin-top:8px;"><button type="button" class="btn btn-sm btn-secondary" id="cat-add-root">+ Categoría raíz</button></div>`;
            panel.querySelectorAll('.cat-rename').forEach(btn => btn.addEventListener('click', () => {
                const newName = prompt('Nuevo nombre (global afecta todos los productos):', btn.dataset.name);
                if (!newName) return;
                const scope = confirm('¿Aplicar globalmente a todos los productos?\nOK=Global, Cancelar=Solo este producto') ? 'global' : 'local';
                post('riverso_category_rename', {term_id: btn.dataset.id, new_name: newName, scope, product_id: selectedId}).then(r => {
                    alert(r.success ? 'Categoría renombrada' : (r.data?.message || 'Error'));
                    if (r.success) { loadCategoryTree(); loadProduct(selectedId); }
                });
            }));
            panel.querySelectorAll('.cat-add-child').forEach(btn => btn.addEventListener('click', () => {
                const name = prompt('Nombre de subcategoría:');
                if (!name) return;
                post('riverso_category_create', {parent_id: btn.dataset.id, name}).then(r => {
                    alert(r.success ? 'Categoría creada' : (r.data?.message || 'Error'));
                    if (r.success) loadCategoryTree();
                });
            }));
            document.getElementById('cat-add-root')?.addEventListener('click', () => {
                const name = prompt('Nombre categoría raíz:');
                if (!name) return;
                post('riverso_category_create', {parent_id: 0, name}).then(r => {
                    alert(r.success ? 'Categoría creada' : (r.data?.message || 'Error'));
                    if (r.success) loadCategoryTree();
                });
            });
        });
    }

    function bindLocalSkuSection(item) {
        renderVariationsPanel(item.bases || []);
    }

    function showGateContext(gate) {
        post('riverso_gate_context', {product_id: selectedId, gate}).then(d => {
            if (!d.success) { alert(d.data?.message || 'Error'); return; }
            const ctx = d.data.context;
            let html = '';
            if (ctx.gate === 'human_product_review') {
                html = `<p><strong>Producto:</strong> ${esc(ctx.product_name)}</p>
                    <p><strong>Códigos proveedor:</strong> ${ctx.codes_count}</p>
                    <p><strong>Variaciones base:</strong> ${ctx.bases_count}</p>`;
            } else if (ctx.gate === 'human_category_review') {
                html = `<p><strong>Ruta:</strong> ${esc((ctx.current_path||[]).join(' > ') || 'Sin categoría')}</p>`;
            } else if (ctx.gate === 'human_attribute_review') {
                html = '<ul>' + (ctx.attributes||[]).map(a => `<li>${esc(a.name)}: ${a.count} opciones</li>`).join('') + '</ul>';
            } else if (ctx.gate === 'human_price_review') {
                html = '<table style="width:100%;font-size:13px;border-collapse:collapse;"><tr><th>c_ref</th><th>p_asignado</th><th>Margen</th></tr>' +
                    (ctx.prices||[]).map(p => `<tr><td>${esc(p.c_ref)}</td><td><input type="number" class="price-assign-input" data-id="${p.id}" value="${esc(p.p_asignado||'')}" style="width:90px;"></td><td>${p.margin_pct}%${p.alerta?' ⚠':''}</td></tr>`).join('') +
                    '</table>';
                if (ctx.recent_costs?.length) {
                    html += '<p style="margin-top:12px;"><strong>Últimos costos:</strong></p><ul>' +
                        ctx.recent_costs.slice(0,5).map(c => `<li>$${esc(c.unit_cost)} (${esc(c.document_date)}) — ${esc(c.source_type||'')}</li>`).join('') + '</ul>';
                }
                html += '<button type="button" class="btn btn-secondary" id="price-save-assigned" style="margin-top:10px;">Guardar precios asignados</button>';
            }
            showModal(ctx.label || 'Detalle gate', html);
            document.getElementById('price-save-assigned')?.addEventListener('click', () => {
                const inputs = modalBody.querySelectorAll('.price-assign-input');
                const saves = [...inputs].map(inp => post('riverso_pricing_set_assigned', {precio_id: inp.dataset.id, p_asignado: inp.value}));
                Promise.all(saves).then(() => { alert('Precios guardados'); hideModal(); loadProduct(selectedId); });
            });
        });
    }

    function categoryLevelsHtml(path) {
        const levels = path && path.length ? path : [''];
        return levels.map((value, index) => `
            <div class="catalog-category-level" style="display:grid;grid-template-columns:90px 1fr auto;gap:8px;align-items:end;margin-bottom:8px;">
                <label style="font-size:12px;">Nivel ${index + 1}</label>
                <input type="text" class="catalog-cat-level-input" value="${esc(value || '')}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;">
                <button type="button" class="btn btn-danger btn-sm catalog-remove-level" ${levels.length === 1 ? 'disabled' : ''}>Quitar</button>
            </div>
        `).join('');
    }

    function bindCategoryLevelControls() {
        const wrap = document.getElementById('catalog-category-levels');
        if (!wrap) return;

        document.getElementById('catalog-add-level')?.addEventListener('click', () => {
            const current = [...wrap.querySelectorAll('.catalog-cat-level-input')].map(input => input.value);
            current.push('');
            wrap.innerHTML = categoryLevelsHtml(current);
            bindCategoryLevelControls();
        });

        wrap.querySelectorAll('.catalog-remove-level').forEach((btn, index) => {
            btn.addEventListener('click', () => {
                const current = [...wrap.querySelectorAll('.catalog-cat-level-input')].map(input => input.value);
                if (current.length <= 1) return;
                current.splice(index, 1);
                wrap.innerHTML = categoryLevelsHtml(current);
                bindCategoryLevelControls();
            });
        });
    }

    function renderEditor(item) {
        currentItem = item;
        selectedId = item.product_id;
        const path = item.category_path || [];
        const gates = [['human_product_review','Producto'],['human_price_review','Precio'],['human_category_review','Categoría'],['human_attribute_review','Atributos']];
        const firstBase = (item.bases && item.bases[0]) ? item.bases[0] : {};
        const basesOptions = (item.bases || []).map(b => {
            const lbl = b.variation_label || b.canonical_sku || ('Base #' + b.id);
            return `<option value="${b.id}">${esc(lbl)}</option>`;
        }).join('');
        const attrsHtml = (item.attributes || []).filter(a => !String(a.name).startsWith('pa_')).map(attr => `
            <div style="margin-bottom:12px;">
                <label style="font-weight:600;display:block;margin-bottom:4px;">${esc(attr.name)}</label>
                <textarea class="catalog-attr" data-name="${esc(attr.name)}" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;">${esc((attr.options||[]).join(', '))}</textarea>
            </div>`).join('');

        editorEl.innerHTML = `
            <h3 style="margin-bottom:16px;">Editar producto #${item.product_id}</h3>
            <div style="margin-bottom:12px;"><label style="font-weight:600;display:block;margin-bottom:4px;">Nombre</label>
                <input type="text" id="catalog-name" value="${esc(item.name)}" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;"></div>
            <h4 style="margin:16px 0 8px;">Ruta de categoría (mayor a menor)</h4>
            <div id="catalog-category-levels" style="margin-bottom:8px;">
                ${categoryLevelsHtml(path)}
            </div>
            <button type="button" class="btn btn-secondary btn-sm" id="catalog-add-level" style="margin-bottom:16px;">+ Agregar nivel</button>
            <button class="btn btn-primary" id="catalog-save">Guardar nombre y categoría</button>

            <h4 style="margin:20px 0 8px;">Variaciones — SKU local y códigos proveedor</h4>
            <div id="variations-panel"></div>

            <h4 style="margin:16px 0 8px;">Árbol de Categorías</h4>
            <div id="category-tree-panel" style="background:#f5f5f5;padding:10px;border-radius:6px;margin-bottom:8px;max-height:200px;overflow-y:auto;font-size:12px;"></div>
            <button type="button" class="btn btn-secondary btn-sm" id="catalog-load-category-tree">Cargar / refrescar árbol</button>

            <h4 id="codigos" style="margin:16px 0 8px;">Vincular código de proveedor</h4>
            <div id="codes-panel" style="background:#f5f5f5;padding:10px;border-radius:6px;margin-bottom:8px;max-height:150px;overflow-y:auto;font-size:12px;"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                <div><label style="font-size:12px;">Variación</label><select id="code-link-base" style="width:100%;padding:6px;">${basesOptions}</select></div>
                <div><label style="font-size:12px;">Código proveedor</label><input type="text" id="code-link-codigo" placeholder="Código del proveedor" style="width:100%;padding:6px;"></div>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-size:12px;">Proveedor (buscar por nombre)</label>
                <input type="text" id="code-link-proveedor-search" placeholder="Escribe nombre o RUT..." style="width:100%;padding:6px;margin-top:4px;">
                <input type="hidden" id="code-link-proveedor-id" value="">
                <div id="code-link-proveedor-results" style="border:1px solid var(--border);border-radius:4px;background:#fff;"></div>
                <p id="code-link-proveedor-selected" style="font-size:12px;color:var(--text-secondary);margin:4px 0;"></p>
                <div style="display:flex;gap:8px;margin-top:6px;">
                    <button type="button" class="btn btn-secondary btn-sm" id="code-link-new-proveedor">+ Crear proveedor</button>
                    <a href="<?php echo esc_url(home_url('/interno/suppliers/')); ?>" target="_blank" class="btn btn-link btn-sm">Gestionar proveedores</a>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="catalog-code-link-btn">Vincular código</button>

            <h4 style="margin:16px 0 8px;">Atributos</h4>${attrsHtml || '<p style="color:var(--text-secondary);">Sin atributos editables</p>'}
            <button class="btn btn-secondary" id="catalog-save-attrs" style="margin-top:8px;">Guardar atributos</button>

            <h4 style="margin:16px 0 8px;">Gates (${item.variations_count} SKU)</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">
                ${gates.map(([key,label]) => `<div style="background:#fff;padding:10px;border-radius:6px;border:1px solid var(--border);">
                    <div style="font-size:13px;margin-bottom:6px;">${label} ${gateBadge(firstBase[key]||'pending')}</div>
                    ${(firstBase[key]||'pending') === 'pending' ? `
                        <button type="button" class="btn btn-sm btn-info catalog-gate-view" data-gate="${key}">Ver detalles</button>
                        <button type="button" class="btn btn-sm btn-primary catalog-gate-approve" data-gate="${key}">Aprobar</button>
                        <button type="button" class="btn btn-sm btn-danger catalog-gate-reject" data-gate="${key}">Rechazar</button>
                    ` : `<span style="font-size:12px;">${firstBase[key] === 'approved' ? 'Aprobado' : 'Rechazado'}</span>`}
                </div>`).join('')}
            </div>
            <?php if (current_user_can('riverso_publish_products')): ?>
            <button class="btn btn-secondary" id="catalog-authorize">Autorizar publicación</button>
            <button class="btn btn-primary" id="catalog-publish" style="margin-left:8px;">Publicar</button>
            <?php endif; ?>
            <p style="margin-top:12px;font-size:12px;color:var(--text-secondary);">Estado: ${esc(item.status)} • Etapa: ${esc(item.publication_stage)}</p>`;

        renderCodesPanel(item.codes || [], item.bases || []);
        bindLocalSkuSection(item);
        bindSupplierPicker();
        bindCodeLinkForm();
        bindCategoryLevelControls();
        loadCategoryTree();

        document.getElementById('catalog-save')?.addEventListener('click', () => {
            const categoryPath = [...editorEl.querySelectorAll('.catalog-cat-level-input')]
                .map(input => input.value.trim())
                .filter(Boolean);
            post('riverso_catalog_save', {
                product_id: selectedId,
                name: document.getElementById('catalog-name')?.value || '',
                category_path: JSON.stringify(categoryPath)
            }).then(d => { alert(d.success?'Guardado':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        });
        document.getElementById('catalog-load-category-tree')?.addEventListener('click', loadCategoryTree);
        document.getElementById('catalog-save-attrs')?.addEventListener('click', () => {
            const attrs = {};
            editorEl.querySelectorAll('.catalog-attr').forEach(el => { attrs[el.dataset.name] = el.value.split(',').map(s=>s.trim()).filter(Boolean); });
            post('riverso_catalog_save_attributes', {product_id: selectedId, attributes: JSON.stringify(attrs)}).then(d => { alert(d.success?'Atributos guardados':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        });
        editorEl.querySelectorAll('.catalog-gate-view').forEach(btn => btn.addEventListener('click', () => showGateContext(btn.dataset.gate)));
        editorEl.querySelectorAll('.catalog-gate-approve').forEach(btn => btn.addEventListener('click', () => {
            post('riverso_catalog_approve_gate', {product_id: selectedId, gate: btn.dataset.gate, status: 'approved'}).then(d => { alert(d.success?'Gate aprobado':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        }));
        editorEl.querySelectorAll('.catalog-gate-reject').forEach(btn => btn.addEventListener('click', () => {
            if (!confirm('¿Rechazar? Se creará una tarea de revisión.')) return;
            post('riverso_catalog_approve_gate', {product_id: selectedId, gate: btn.dataset.gate, status: 'rejected'}).then(d => { alert(d.success?'Gate rechazado':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        }));
        document.getElementById('catalog-authorize')?.addEventListener('click', () => {
            if (!confirm('¿Autorizar publicación?')) return;
            post('riverso_catalog_authorize', {product_id: selectedId}).then(d => { alert(d.success?'Autorizado':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        });
        document.getElementById('catalog-publish')?.addEventListener('click', () => {
            if (!confirm('¿Publicar en la tienda?')) return;
            post('riverso_catalog_publish', {product_id: selectedId}).then(d => { alert(d.success?'Publicado':(d.data?.message||'Error')); if(d.success) loadProduct(selectedId); });
        });

        if (catalogInitialHash === 'codigos' || window.location.hash === '#codigos') {
            document.getElementById('codigos')?.scrollIntoView({behavior:'smooth'});
        }
    }

    function loadProduct(id) {
        selectedId = parseInt(id, 10);
        editorEl.innerHTML = '<div class="loading">Cargando producto...</div>';
        post('riverso_catalog_get', {product_id: id}).then(data => {
            if (!data.success) { editorEl.innerHTML = '<div class="empty-state"><p>' + esc(data.data?.message || 'Error') + '</p></div>'; return; }
            renderEditor(data.data.item);
            loadList(searchEl?.value || '');
        });
    }

    searchEl?.addEventListener('input', () => loadList(searchEl.value));
    loadList('');
    if (catalogInitialProductId) {
        loadProduct(catalogInitialProductId);
    }
})();

// Buscador de códigos de barra / tienda local
(function() {
    const input = document.getElementById('barcode-input');
    const button = document.getElementById('barcode-search-btn');
    const resultDiv = document.getElementById('barcode-result');
    const statsDiv = document.getElementById('barcode-stats');
    if (!input || !resultDiv) return;

    function escBarcode(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderStats(stats) {
        if (!statsDiv || !stats) return;
        statsDiv.textContent = `${stats.productos || 0} productos locales · ${stats.barcodes || 0} códigos · ${stats.productos_con_barcode || 0} productos con código`;
    }

    function renderProduct(product) {
        const barcodes = (product.barcodes || []).map(b =>
            `<li><code>${escBarcode(b.barcode)}</code>${b.fecha ? ` <small>(${escBarcode(b.fecha)})</small>` : ''}</li>`
        ).join('');
        const matched = product.matched_barcode
            ? `<p style="margin:6px 0;color:var(--success);"><strong>Código encontrado:</strong> <code>${escBarcode(product.matched_barcode)}</code></p>`
            : '';
        const stockColor = Number(product.stock || 0) > 0 ? 'var(--success)' : 'var(--danger)';

        return `
            <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:18px;margin-bottom:12px;text-align:left;">
                <h3 style="margin:0 0 8px;">${escBarcode(product.nombre)}</h3>
                ${matched}
                <p style="margin:8px 0;">
                    <strong>SKU local:</strong> <code>${escBarcode(product.sku)}</code><br>
                    <strong>Precio:</strong> ${escBarcode(product.precio_formateado || '')}<br>
                    <strong>Stock:</strong> <span style="color:${stockColor};font-weight:600;">${escBarcode(product.stock)}</span>
                </p>
                <details ${(product.barcodes || []).length <= 6 ? 'open' : ''}>
                    <summary>Códigos asociados (${(product.barcodes || []).length})</summary>
                    <ul style="margin:8px 0 0 18px;">${barcodes || '<li>Sin códigos asociados.</li>'}</ul>
                </details>
            </div>
        `;
    }

    function searchBarcode() {
        const query = input.value.trim();
        if (!query) {
            resultDiv.innerHTML = '<div style="color:var(--warning);text-align:center;">Ingresa un código, SKU o nombre.</div>';
            return;
        }

        resultDiv.innerHTML = '<div class="loading" style="text-align:center;">Buscando...</div>';
        fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'riverso_tienda_local_search',
                nonce: riversoNonce,
                query
            })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                renderStats(data.data?.stats);
                resultDiv.innerHTML = `<div style="background:#fff;border:1px solid var(--danger);border-radius:8px;padding:18px;color:var(--danger);text-align:center;">
                    ${escBarcode(data.data?.message || 'Producto local no encontrado')}<br>
                    <small>Consulta: ${escBarcode(query)}</small>
                </div>`;
                return;
            }

            renderStats(data.data.stats);
            const items = (data.data.items || []).filter(Boolean);
            const intro = data.data.type === 'name' && items.length > 1
                ? `<p style="color:var(--text-secondary);text-align:center;margin-bottom:12px;">Se encontraron ${items.length} coincidencias por nombre.</p>`
                : '';
            resultDiv.innerHTML = intro + items.map(renderProduct).join('');
            if (data.data.type === 'barcode') {
                input.value = '';
            }
        })
        .catch(() => {
            resultDiv.innerHTML = '<div style="color:var(--danger);text-align:center;">Error buscando producto local.</div>';
        });
    }

    button?.addEventListener('click', searchBarcode);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchBarcode();
        }
    });
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
