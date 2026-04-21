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
$nonce = wp_create_nonce('riverso_pos_nonce');

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
                                <button class="btn btn-primary btn-sm" onclick="alert('TODO: Completar tarea')">
                                    Completar
                                </button>
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
                        <a href="<?php echo admin_url('admin.php?page=riverso-invoices'); ?>" class="quick-action">
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
        <!-- Facturas -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Facturas Recibidas</h2>
                <?php if (current_user_can('riverso_create_invoices')): ?>
                <button class="btn btn-primary" onclick="subirFactura()">
                    <span class="dashicons dashicons-upload"></span> Subir XML
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--bg-light);">
                            <th style="padding: 12px; text-align: left;">Folio</th>
                            <th style="padding: 12px; text-align: left;">Proveedor</th>
                            <th style="padding: 12px; text-align: left;">Fecha</th>
                            <th style="padding: 12px; text-align: right;">Total</th>
                            <th style="padding: 12px; text-align: center;">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $facturas = $wpdb->get_results(
                            "SELECT f.*, p.nombre as proveedor_nombre FROM {$prefix}facturas f
                             LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id
                             ORDER BY f.created_at DESC LIMIT 20", ARRAY_A);
                        foreach ($facturas as $f): ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding: 12px;"><?php echo esc_html($f['folio']); ?></td>
                            <td style="padding: 12px;"><?php echo esc_html($f['proveedor_nombre'] ?? 'Sin proveedor'); ?></td>
                            <td style="padding: 12px;"><?php echo date_i18n('d/m/Y', strtotime($f['fecha_emision'] ?? $f['created_at'])); ?></td>
                            <td style="padding: 12px; text-align: right;">$<?php echo number_format($f['total'] ?? 0, 0, ',', '.'); ?></td>
                            <td style="padding: 12px; text-align: center;">
                                <span class="badge badge-<?php echo esc_attr($f['estado']); ?>"><?php echo esc_html(ucfirst($f['estado'])); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                <h2 class="section-title">Escáner de Códigos</h2>
            </div>
            <div class="section-body">
                <div style="max-width: 500px; margin: 0 auto; text-align: center;">
                    <input type="text" id="barcode-input" placeholder="Escanea o ingresa código..." 
                           style="width: 100%; padding: 20px; font-size: 24px; text-align: center; border: 2px solid var(--primary); border-radius: 8px;"
                           autofocus>
                    <div id="barcode-result" style="margin-top: 30px;"></div>
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

<script>
// Nonce para AJAX
const riversoNonce = '<?php echo $nonce; ?>';
const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

// Completar tarea
function completarTarea(id) {
    if (!confirm('¿Marcar tarea como completada?')) return;
    
    fetch(ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'riverso_complete_task',
            nonce: riversoNonce,
            task_id: id
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.data?.message || 'Error al completar tarea');
        }
    });
}

// Crear tarea
function crearTarea() {
    window.location.href = '<?php echo admin_url('admin.php?page=riverso-tasks&action=new'); ?>';
}

// Barcode scanner
document.getElementById('barcode-input')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const code = this.value.trim();
        if (code) {
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'riverso_lookup_barcode',
                    nonce: riversoNonce,
                    code: code
                })
            })
            .then(r => r.json())
            .then(data => {
                const resultDiv = document.getElementById('barcode-result');
                if (data.success && data.data.product) {
                    const p = data.data.product;
                    resultDiv.innerHTML = `
                        <div style="background: var(--bg-light); padding: 20px; border-radius: 8px; text-align: left;">
                            <h3>${p.name}</h3>
                            <p>SKU: <strong>${p.sku}</strong></p>
                            <p>Precio: <strong>$${p.price}</strong></p>
                            <p>Stock: <strong>${p.stock}</strong></p>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `<div style="color: var(--danger);">❌ Producto no encontrado: ${code}</div>`;
                }
            });
            this.value = '';
        }
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
