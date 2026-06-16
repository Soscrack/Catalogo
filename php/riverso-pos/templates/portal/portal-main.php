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
$catalog_initial_product = 0;
$catalog_initial_hash = '';
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

<script>
// Nonce para AJAX
const riversoNonce = '<?php echo $nonce; ?>';
const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

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
