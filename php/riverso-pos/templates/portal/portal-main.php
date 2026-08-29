<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Riverso - Portal Interno</title>
    <?php wp_head(); ?>
    <script>
    window.riversoWhenJQuery = function(fn) {
        if (window.jQuery) { window.jQuery(fn); return; }
        var n = 0;
        var t = setInterval(function() {
            if (window.jQuery) { clearInterval(t); window.jQuery(fn); }
            else if (++n > 80) { clearInterval(t); }
        }, 50);
    };
    window.riversoSwitchBarcodeTab = function(tab) {
        ['search', 'products', 'sku', 'pending', 'conflicts', 'tipos'].forEach(function(name) {
            var el = document.getElementById('barcode-tab-' + name);
            if (el) el.style.display = name === tab ? 'block' : 'none';
        });
        document.querySelectorAll('#barcode-tabs .barcode-tab').forEach(function(btn) {
            var on = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('btn-primary', !!on);
            btn.classList.toggle('btn-secondary', !on);
        });
        document.dispatchEvent(new CustomEvent('riverso-barcode-tab', { detail: tab }));
    };
    </script>
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
            display: flex;
            flex-direction: column;
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
            flex: 1;
            overflow-y: auto;
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
            position: relative;
            padding: 15px 20px;
            background: rgba(0,0,0,0.2);
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }

        .portal-menu-toggle {
            display: none;
            border: 1px solid var(--border);
            background: white;
            border-radius: 8px;
            width: 42px;
            height: 42px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .portal-sidebar-backdrop {
            display: none;
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
            align-items: center;
            flex-wrap: wrap;
        }

        .task-type-badge {
            font-size: 11px;
            color: #666;
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 6px;
        }

        .task-category-badge {
            font-size: 11px;
            color: #1565c0;
            background: #e3f2fd;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 6px;
        }

        .task-no-target {
            font-size: 11px;
            color: #b71c1c;
            background: #ffebee;
            padding: 2px 8px;
            border-radius: 3px;
        }

        .portal-task-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .portal-task-tabs a {
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            color: var(--text-secondary);
            background: var(--bg-light);
            border: 1px solid var(--border);
            font-size: 13px;
        }

        .portal-task-tabs a.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
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
            .portal-menu-toggle { display: inline-flex; }
            .portal-sidebar {
                width: min(280px, 86vw);
                transform: translateX(-100%);
                transition: transform 0.2s ease;
            }
            .portal-sidebar.open { transform: translateX(0); }
            .portal-sidebar-backdrop {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.4);
                z-index: 90;
            }
            .portal-sidebar-backdrop.open { display: block; }
            .portal-main {
                margin-left: 0;
                padding: 16px;
            }
            .sidebar-footer {
                position: relative;
            }
            .portal-header {
                align-items: center;
                gap: 12px;
            }
            .page-title { font-size: 22px; }
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
        .portal-filters input[type="date"],
        .portal-filters input[type="search"] {
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            min-width: 150px;
        }
        #portal-filter-search {
            min-width: 220px;
        }
        .portal-invoices-control {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .portal-invoices-pagination {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-secondary);
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
        #portal-detail-modal .portal-modal.large {
            width: 96%;
            max-width: min(1480px, 96vw);
        }
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
        .portal-badge-gasto { background: #f3e8ff; color: #6b21a8; }

        .portal-tipo-pending-badge {
            display: inline-block;
            background: #fbbf24;
            color: #78350f;
            padding: 1px 6px;
            border-radius: 3px;
            font-weight: 600;
            font-size: 10px;
            letter-spacing: .02em;
        }

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
        .barcode-tabs {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            position: relative;
            z-index: 3;
        }
        .barcode-tabs .barcode-tab {
            border-radius: 999px;
            border: 1px solid var(--border);
            background: white;
            color: var(--text-primary);
        }
        .barcode-tabs .barcode-tab.btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .barcode-layout {
            width: 100%;
            max-width: none;
            margin: 0;
        }
        .barcode-section-body {
            overflow-x: auto;
        }
        .portal-table tr.barcode-product-row {
            cursor: pointer;
        }
        .portal-table tr.barcode-product-row:hover {
            background: #e3f2fd;
        }
        .barcode-assoc-list {
            list-style: none;
            margin: 8px 0 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .barcode-assoc-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fafafa;
        }
        .barcode-assoc-row.is-unverified {
            background: #fff8e1;
            border-color: #ffcc80;
        }
        .barcode-assoc-row.is-verified {
            background: #e8f5e9;
            border-color: #a5d6a7;
        }
        .barcode-assoc-row.is-matched {
            background: #e3f2fd;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.25);
            order: -1;
        }
        .barcode-assoc-row .barcode-matched-flag {
            background: var(--primary);
            color: #fff;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .barcode-assoc-row .barcode-assoc-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .barcode-assoc-row .barcode-assoc-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
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

foreach ($tareas as &$tarea_row) {
    if (class_exists('Riverso_Task_Module')) {
        Riverso_Task_Module::enrich_task($tarea_row, 'portal');
    } else {
        $tarea_row['target_url'] = riverso_resolve_task_target($tarea_row, 'portal');
    }
}
unset($tarea_row);

$portal_task_types = class_exists('Riverso_Task_Module') ? Riverso_Task_Module::TASK_TYPES : [];
$portal_task_categories = riverso_get_task_categories();
$portal_task_type_labels = riverso_get_task_types();
$portal_task_type_categories = class_exists('Riverso_Task_Module') ? Riverso_Task_Module::TASK_TYPE_CATEGORY : [];
?>

<div class="portal-wrapper">
    <div class="portal-sidebar-backdrop" id="portal-sidebar-backdrop"></div>
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
            <button type="button" class="portal-menu-toggle" id="portal-menu-toggle" aria-label="Abrir menú">
                <span class="dashicons dashicons-menu"></span>
            </button>
            <h1 class="page-title">
                <?php
                $page_title = $modules[$current_page]['label'] ?? null;
                echo esc_html($page_title ?: ($current_page === 'dashboard' ? 'Dashboard' : ucfirst(str_replace('-', ' ', (string) $current_page))));
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
                        <?php foreach ($tareas as $tarea):
                            $tipo_info = $portal_task_type_labels[$tarea['tipo']] ?? ['label' => $tarea['tipo']];
                        ?>
                        <div class="task-item">
                            <div class="task-priority <?php echo esc_attr($tarea['prioridad']); ?>"></div>
                            <div class="task-content">
                                <div class="task-title"><?php echo esc_html($tarea['titulo']); ?></div>
                                <div class="task-meta">
                                    <span class="task-type-badge"><?php echo esc_html($tipo_info['label']); ?></span>
                                    <?php if (!empty($tarea['categoria_label'])): ?>
                                        <span class="task-category-badge"><?php echo esc_html($tarea['categoria_label']); ?></span>
                                    <?php endif; ?>
                                    • <?php echo esc_html(date_i18n('j M', strtotime($tarea['created_at']))); ?>
                                    <?php if ($tarea['fecha_limite']): ?>
                                        • Límite: <?php echo esc_html(date_i18n('j M', strtotime($tarea['fecha_limite']))); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="task-actions">
                                <?php if (!empty($tarea['target_url'])): ?>
                                    <a href="<?php echo esc_url($tarea['target_url']); ?>" class="btn btn-primary btn-sm">
                                        Ir a realizar
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
                        <a href="<?php echo esc_url(admin_url('admin.php?page=riverso-pos-tasks&action=new')); ?>" class="quick-action">
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
                        <a href="<?php echo home_url('/interno/warehouse/'); ?>" class="quick-action">
                            <span class="dashicons dashicons-store"></span>
                            <span>Ver Bodega</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'tasks'): ?>
        <?php
        $portal_task_tab = sanitize_key($_GET['tab'] ?? 'todas');
        $portal_task_filter_categoria = sanitize_key($_GET['categoria'] ?? '');
        $portal_task_filter_tipo = sanitize_key($_GET['tipo'] ?? '');
        $portal_task_filter_prioridad = sanitize_key($_GET['prioridad'] ?? '');

        $portal_task_query = [
            'limit' => 50,
            'portal_scope_user' => $user_id,
        ];
        if ($portal_task_tab === 'mis-tareas') {
            $portal_task_query['asignado_a'] = $user_id;
            unset($portal_task_query['portal_scope_user']);
        } elseif ($portal_task_tab === 'sin-asignar') {
            $portal_task_query['sin_asignar'] = true;
            unset($portal_task_query['portal_scope_user']);
        } elseif ($portal_task_tab === 'completadas') {
            $portal_task_query['estado'] = 'completada';
            $portal_task_query['include_completed'] = true;
            unset($portal_task_query['portal_scope_user']);
        }
        if ($portal_task_filter_categoria) {
            $portal_task_query['categoria'] = $portal_task_filter_categoria;
        }
        if ($portal_task_filter_tipo) {
            $portal_task_query['tipo'] = $portal_task_filter_tipo;
        }
        if ($portal_task_filter_prioridad) {
            $portal_task_query['prioridad'] = $portal_task_filter_prioridad;
        }

        $all_tasks = class_exists('Riverso_Task_Module')
            ? Riverso_Task_Module::get_instance()->get_tasks($portal_task_query)
            : [];

        foreach ($all_tasks as &$portal_task_row) {
            if (class_exists('Riverso_Task_Module')) {
                Riverso_Task_Module::enrich_task($portal_task_row, 'portal');
            }
        }
        unset($portal_task_row);

        $portal_tasks_base_url = home_url('/interno/tasks/');
        $portal_task_tab_links = function($tab) use ($portal_tasks_base_url, $portal_task_filter_categoria, $portal_task_filter_tipo, $portal_task_filter_prioridad) {
            return add_query_arg(array_filter([
                'tab' => $tab !== 'todas' ? $tab : null,
                'categoria' => $portal_task_filter_categoria ?: null,
                'tipo' => $portal_task_filter_tipo ?: null,
                'prioridad' => $portal_task_filter_prioridad ?: null,
            ]), $portal_tasks_base_url);
        };
        ?>
        <!-- Página Tareas -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Tareas</h2>
                <?php if (current_user_can('riverso_create_tasks')): ?>
                <button class="btn btn-primary" onclick="crearTarea()">
                    <span class="dashicons dashicons-plus-alt"></span> Nueva Tarea
                </button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div class="portal-task-tabs">
                    <a href="<?php echo esc_url($portal_task_tab_links('todas')); ?>" class="<?php echo $portal_task_tab === 'todas' ? 'active' : ''; ?>">Todas</a>
                    <a href="<?php echo esc_url($portal_task_tab_links('mis-tareas')); ?>" class="<?php echo $portal_task_tab === 'mis-tareas' ? 'active' : ''; ?>">Mis Tareas</a>
                    <a href="<?php echo esc_url($portal_task_tab_links('sin-asignar')); ?>" class="<?php echo $portal_task_tab === 'sin-asignar' ? 'active' : ''; ?>">Sin Asignar</a>
                    <a href="<?php echo esc_url($portal_task_tab_links('completadas')); ?>" class="<?php echo $portal_task_tab === 'completadas' ? 'active' : ''; ?>">Completadas</a>
                </div>

                <form method="get" action="<?php echo esc_url($portal_tasks_base_url); ?>" class="portal-filters" style="margin-bottom: 16px;">
                    <?php if ($portal_task_tab !== 'todas'): ?>
                        <input type="hidden" name="tab" value="<?php echo esc_attr($portal_task_tab); ?>">
                    <?php endif; ?>
                    <select name="categoria" id="portal-filter-categoria">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($portal_task_categories as $cat_key => $cat_label): ?>
                            <option value="<?php echo esc_attr($cat_key); ?>" <?php selected($portal_task_filter_categoria, $cat_key); ?>>
                                <?php echo esc_html($cat_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tipo" id="portal-filter-tipo">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($portal_task_types as $type_key => $type_label): ?>
                            <option value="<?php echo esc_attr($type_key); ?>"
                                    data-categoria="<?php echo esc_attr($portal_task_type_categories[$type_key] ?? 'otros'); ?>"
                                    <?php selected($portal_task_filter_tipo, $type_key); ?>>
                                <?php echo esc_html($type_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="prioridad">
                        <option value="">Todas las prioridades</option>
                        <?php foreach (Riverso_Task_Module::PRIORITIES as $prio_key => $prio_data): ?>
                            <option value="<?php echo esc_attr($prio_key); ?>" <?php selected($portal_task_filter_prioridad, $prio_key); ?>>
                                <?php echo esc_html($prio_data['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary btn-sm">Filtrar</button>
                    <a href="<?php echo esc_url($portal_task_tab_links($portal_task_tab)); ?>" class="btn btn-secondary btn-sm">Limpiar</a>
                </form>

                <div id="tasks-list">
                <?php if (empty($all_tasks)): ?>
                <div class="empty-state">
                    <span class="dashicons dashicons-clipboard"></span>
                    <p>No hay tareas</p>
                </div>
                <?php else:
                    foreach ($all_tasks as $t):
                        $tipo_info = $portal_task_type_labels[$t['tipo']] ?? ['label' => $t['tipo']];
                ?>
                <div class="task-item" data-id="<?php echo (int) $t['id']; ?>">
                    <div class="task-priority <?php echo esc_attr($t['prioridad']); ?>"></div>
                    <div class="task-content">
                        <div class="task-title"><?php echo esc_html($t['titulo']); ?></div>
                        <div class="task-meta">
                            <span class="task-status <?php echo esc_attr($t['estado']); ?>"><?php echo esc_html(ucfirst($t['estado'])); ?></span>
                            <span class="task-type-badge"><?php echo esc_html($tipo_info['label']); ?></span>
                            <?php if (!empty($t['categoria_label'])): ?>
                                <span class="task-category-badge"><?php echo esc_html($t['categoria_label']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($t['asignado_nombre'])): ?>
                                • Asignado a: <?php echo esc_html($t['asignado_nombre']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($t['estado'] !== 'completada' && $t['estado'] !== 'cancelada'): ?>
                    <div class="task-actions">
                        <?php if (!empty($t['target_url'])): ?>
                            <a href="<?php echo esc_url($t['target_url']); ?>" class="btn btn-primary btn-sm">Ir a realizar</a>
                        <?php else: ?>
                            <span class="task-no-target">Sin destino</span>
                        <?php endif; ?>
                        <?php if (!empty($t['allow_complete']) && current_user_can('riverso_complete_tasks')): ?>
                        <button class="btn btn-secondary btn-sm" onclick="completarTarea(<?php echo (int) $t['id']; ?>)">Completar</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                    <?php endforeach;
                endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function() {
            const categoria = document.getElementById('portal-filter-categoria');
            const tipo = document.getElementById('portal-filter-tipo');
            if (!categoria || !tipo) return;
            function syncTipoOptions() {
                const cat = categoria.value;
                Array.from(tipo.options).forEach(function(opt) {
                    if (!opt.value) return;
                    opt.hidden = !!(cat && opt.dataset.categoria !== cat);
                });
                if (tipo.value) {
                    const selected = tipo.options[tipo.selectedIndex];
                    if (selected && selected.hidden) tipo.value = '';
                }
            }
            categoria.addEventListener('change', syncTipoOptions);
            syncTipoOptions();
        })();
        </script>
        
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
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/portal/portal-warehouse.php'; ?>
        
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
                    <select id="portal-filter-tipo-confirmado">
                        <option value="">Todos los tipos</option>
                        <option value="0">Tipo pendiente de confirmar</option>
                        <option value="1">Tipos confirmados</option>
                    </select>
                    <input type="search" id="portal-filter-search" placeholder="Buscar folio o monto…" autocomplete="off">
                    <button type="button" class="btn btn-secondary" onclick="portalLoadInvoices(1)">Filtrar</button>
                    <label class="portal-invoices-control">
                        Mostrar
                        <select id="portal-invoices-per-page">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                    <label class="portal-invoices-control">
                        Ordenar por
                        <select id="portal-invoices-orderby">
                            <option value="created_at" selected>Fecha de ingreso</option>
                            <option value="fecha_emision">Fecha del documento</option>
                            <option value="folio">Folio</option>
                            <option value="monto_total">Monto total</option>
                            <option value="proveedor_nombre">Proveedor</option>
                            <option value="estado">Estado</option>
                            <option value="tipo_dte">Tipo DTE</option>
                        </select>
                    </label>
                    <label class="portal-invoices-control">
                        Dirección
                        <select id="portal-invoices-order">
                            <option value="DESC" selected>Descendente</option>
                            <option value="ASC">Ascendente</option>
                        </select>
                    </label>
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
                <div id="portal-invoices-pagination" class="portal-invoices-pagination">
                    <button type="button" class="btn btn-secondary" id="portal-invoices-prev" style="display:none;">← Anterior</button>
                    <span id="portal-invoices-page-info"></span>
                    <button type="button" class="btn btn-secondary" id="portal-invoices-next" style="display:none;">Siguiente →</button>
                </div>
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
                <h2 class="section-title">Códigos de barra (mapeo propio)</h2>
            </div>
            <div class="section-body barcode-section-body">
                <div class="barcode-layout">
                    <p style="color:var(--text-secondary);margin-bottom:12px;text-align:center;">
                        La fuente de verdad es el mapeo verificado. Legacy aparece como sugerencia: acepta, rechaza o ignora.
                    </p>
                    <div id="barcode-stat-cards" class="stats-grid" style="margin-bottom:16px;"></div>
                    <div class="barcode-tabs" id="barcode-tabs">
                        <button type="button" class="btn btn-secondary btn-sm barcode-tab" data-tab="search">Buscar</button>
                        <button type="button" class="btn btn-primary btn-sm barcode-tab" data-tab="products">Productos</button>
                        <button type="button" class="btn btn-secondary btn-sm barcode-tab" data-tab="sku">Por SKU</button>
                        <button type="button" class="btn btn-secondary btn-sm barcode-tab" data-tab="pending">Pendientes</button>
                        <button type="button" class="btn btn-secondary btn-sm barcode-tab" data-tab="conflicts">Conflictos</button>
                        <button type="button" class="btn btn-secondary btn-sm barcode-tab" data-tab="tipos">Tipos de envase</button>
                    </div>
                    <script>
                    (function() {
                        var bar = document.getElementById('barcode-tabs');
                        if (!bar) return;
                        bar.addEventListener('click', function(e) {
                            var btn = e.target.closest('.barcode-tab');
                            if (!btn) return;
                            e.preventDefault();
                            window.riversoSwitchBarcodeTab(btn.getAttribute('data-tab'));
                        });
                    })();
                    </script>
                    <div id="barcode-tab-search" style="display:none;">
                        <div style="display:flex;gap:10px;align-items:center;">
                            <input type="text" id="barcode-input" placeholder="Escanea, pega un código, SKU o nombre..."
                                   style="flex:1; padding:18px; font-size:20px; text-align:center; border:2px solid var(--primary); border-radius:8px;"
                                   autocomplete="off" autofocus>
                            <button type="button" id="barcode-search-btn" class="btn btn-primary" style="padding:18px 24px;">Buscar</button>
                        </div>
                        <div id="barcode-stats" style="margin-top:12px;font-size:12px;color:var(--text-secondary);text-align:center;"></div>
                        <div id="barcode-result" style="margin-top: 24px;"></div>
                    </div>
                    <div id="barcode-tab-products">
                        <p style="color:var(--text-secondary);margin:0 0 12px;">Listado de productos locales. Escribe el código de barra en la fila y pulsa Asignar.</p>
                        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                            <input type="text" id="barcode-product-search" placeholder="Buscar producto por SKU local, nombre o código..." style="flex:1;min-width:220px;padding:10px;border:1px solid var(--border);border-radius:6px;">
                            <select id="barcode-product-filter" style="padding:10px;border:1px solid var(--border);border-radius:6px;">
                                <option value="all">Todos los locales</option>
                                <option value="sin_codigo">Sin código</option>
                                <option value="con_codigo">Con código</option>
                            </select>
                            <button type="button" class="btn btn-primary" id="barcode-product-search-btn">Buscar</button>
                        </div>
                        <div id="barcode-product-list"></div>
                        <div id="barcode-product-pager" style="display:flex;gap:8px;justify-content:center;margin-top:12px;"></div>
                        <div id="barcode-product-detail"></div>
                    </div>
                    <div id="barcode-tab-sku" style="display:none;">
                        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                            <input type="text" id="barcode-sku-search" placeholder="Filtrar SKU o código..." style="flex:1;min-width:180px;padding:10px;border:1px solid var(--border);border-radius:6px;">
                            <button type="button" class="btn btn-secondary" id="barcode-sku-search-btn">Buscar</button>
                            <button type="button" class="btn btn-primary" id="barcode-add-open">Agregar código</button>
                        </div>
                        <div id="barcode-sku-list"></div>
                        <div id="barcode-sku-detail"></div>
                    </div>
                    <div id="barcode-tab-pending" style="display:none;">
                        <div id="barcode-pending-list"></div>
                    </div>
                    <div id="barcode-tab-conflicts" style="display:none;">
                        <div id="barcode-conflict-list"></div>
                    </div>
                    <div id="barcode-tab-tipos" style="display:none;">
                        <form id="barcode-tipo-form" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end;">
                            <input type="text" id="barcode-tipo-nombre" placeholder="Nombre (ej. Saco)" required style="padding:8px;border:1px solid var(--border);border-radius:6px;">
                            <input type="text" id="barcode-tipo-slug" placeholder="slug" style="padding:8px;border:1px solid var(--border);border-radius:6px;">
                            <button type="submit" class="btn btn-primary">Crear tipo</button>
                        </form>
                        <div id="barcode-tipos-list"></div>
                    </div>
                </div>
            </div>
        </div>
        <div id="barcode-edit-modal" class="portal-modal-overlay">
            <div class="portal-modal">
                <div class="portal-modal-header">
                    <h3>Código en mapeo interno</h3>
                    <button type="button" class="btn btn-secondary btn-sm" id="barcode-edit-close">Cerrar</button>
                </div>
                <div class="portal-modal-body">
                    <input type="hidden" id="bc-edit-id">
                    <input type="hidden" id="bc-edit-pb">
                    <label>Código</label>
                    <input type="text" id="bc-edit-codigo" style="width:100%;margin-bottom:8px;padding:8px;">
                    <label>SKU local</label>
                    <input type="text" id="bc-edit-sku" style="width:100%;margin-bottom:8px;padding:8px;">
                    <label>Tipo de envase</label>
                    <select id="bc-edit-tipo" style="width:100%;margin-bottom:8px;padding:8px;"></select>
                    <label>Cantidad</label>
                    <input type="number" id="bc-edit-cantidad" value="1" min="1" style="width:100%;margin-bottom:8px;padding:8px;">
                    <label>Estado</label>
                    <select id="bc-edit-estado" style="width:100%;margin-bottom:8px;padding:8px;">
                        <option value="propuesto">Propuesto</option>
                        <option value="verificado">Verificado</option>
                    </select>
                    <label><input type="checkbox" id="bc-edit-create-envase"> Crear envase si no existe</label>
                </div>
                <div class="portal-modal-footer">
                    <button type="button" class="btn btn-secondary" id="barcode-edit-cancel">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="barcode-edit-save">Guardar</button>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'codes'): ?>
        <?php if (!current_user_can('riverso_manage_codes')): ?>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Códigos Proveedor</h2>
            </div>
            <div class="section-body">
                <div class="empty-state">
                    <span class="dashicons dashicons-lock"></span>
                    <p>No tienes permiso para gestionar códigos de proveedor.</p>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Códigos Proveedor -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Vinculación Códigos Proveedor → SKU</h2>
            </div>
            <div class="section-body">
                <div class="stats-grid" id="codes-stat-cards" style="margin-bottom:16px;"></div>

                <div class="codes-tabs" id="codes-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
                    <button type="button" class="btn btn-primary btn-sm codes-tab" data-tab="todos">Todos los códigos</button>
                    <button type="button" class="btn btn-secondary btn-sm codes-tab" data-tab="pendientes">Pendientes de factura</button>
                    <button type="button" class="btn btn-secondary btn-sm codes-tab" data-tab="proveedores">Proveedores</button>
                </div>

                <div id="codes-tab-todos">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;margin-bottom:16px;">
                        <div style="position:relative;">
                            <input type="text" id="codes-proveedor-search" placeholder="Filtrar por proveedor..." autocomplete="off"
                                   style="padding:10px;border:1px solid var(--border);border-radius:4px;min-width:220px;">
                            <div id="codes-proveedor-results" class="codes-picker"></div>
                            <input type="hidden" id="codes-proveedor-id">
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-clear-proveedor" style="display:none;">Quitar proveedor</button>
                        <select id="codes-estado" style="padding:10px;border:1px solid var(--border);border-radius:4px;">
                            <option value="">Todos los estados</option>
                            <option value="vinculado">Solo vinculados</option>
                            <option value="pendiente">Solo pendientes</option>
                            <option value="por_confirmar">Por confirmar</option>
                        </select>
                        <select id="codes-origen" style="padding:10px;border:1px solid var(--border);border-radius:4px;">
                            <option value="">Todos los orígenes</option>
                            <option value="catalogo">Catálogo</option>
                            <option value="legacy">Legacy</option>
                            <option value="manual">Manual</option>
                            <option value="factura">Facturación</option>
                        </select>
                        <input type="text" id="code-search" placeholder="Buscar código, SKU o descripción..." autocomplete="off"
                               style="flex:1;min-width:240px;padding:10px;border:1px solid var(--border);border-radius:4px;">
                        <span id="codes-status" style="align-self:center;color:var(--text-secondary);font-size:12px;"></span>
                    </div>

                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Código Proveedor</th>
                                <th class="col-desc">Descripción</th>
                                <th class="col-proveedor">Proveedor</th>
                                <th>SKU Local</th>
                                <th>Origen</th>
                                <th>Ingreso</th>
                                <th style="text-align:center;">Estado</th>
                                <th style="text-align:center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="codes-list"></tbody>
                    </table>

                    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-prev" disabled>&laquo; Anterior</button>
                        <span id="codes-page-info" style="color:var(--text-secondary);font-size:13px;"></span>
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-next" disabled>Siguiente &raquo;</button>
                    </div>
                </div>

                <div id="codes-tab-pendientes" style="display:none;">
                    <p style="color:var(--text-secondary);margin-bottom:12px;">
                        Items de factura sin SKU local asignado.
                    </p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;margin-bottom:16px;">
                        <div style="position:relative;">
                            <input type="text" id="codes-pending-proveedor-search" placeholder="Filtrar por proveedor..." autocomplete="off"
                                   style="padding:10px;border:1px solid var(--border);border-radius:4px;min-width:220px;">
                            <div id="codes-pending-proveedor-results" class="codes-picker"></div>
                            <input type="hidden" id="codes-pending-proveedor-id">
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-clear-pending-proveedor" style="display:none;">Quitar proveedor</button>
                        <input type="text" id="codes-pending-search" placeholder="Buscar código, descripción o folio..." autocomplete="off"
                               style="flex:1;min-width:240px;padding:10px;border:1px solid var(--border);border-radius:4px;">
                        <span id="codes-pending-status" style="align-self:center;color:var(--text-secondary);font-size:12px;"></span>
                    </div>
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>Código Proveedor</th>
                                <th class="col-desc">Descripción</th>
                                <th class="col-proveedor">Proveedor</th>
                                <th>Factura</th>
                                <th style="text-align:center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="codes-pending-list"></tbody>
                    </table>
                    <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-pending-prev" disabled>&laquo; Anterior</button>
                        <span id="codes-pending-page-info" style="color:var(--text-secondary);font-size:13px;"></span>
                        <button type="button" class="btn btn-secondary btn-sm" id="codes-pending-next" disabled>Siguiente &raquo;</button>
                    </div>
                </div>

                <div id="codes-tab-proveedores" style="display:none;">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
                        <input type="text" id="codes-providers-search" placeholder="Buscar por nombre, RUT o apodo..." autocomplete="off"
                               style="flex:1;min-width:240px;padding:10px;border:1px solid var(--border);border-radius:4px;">
                        <span id="codes-providers-status" style="color:var(--text-secondary);font-size:12px;"></span>
                    </div>
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>RUT</th>
                                <th>Nombre</th>
                                <th>Apodos</th>
                                <th>Contacto</th>
                                <th style="text-align:center;">Códigos</th>
                                <th style="text-align:center;">Estado</th>
                            </tr>
                        </thead>
                        <tbody id="codes-providers-list"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="codes-edit-modal" class="portal-modal-overlay">
            <div class="portal-modal">
                <div class="portal-modal-header">
                    <h3>Editar código de proveedor</h3>
                    <button type="button" class="btn btn-secondary btn-sm" id="codes-edit-close">Cerrar</button>
                </div>
                <div class="portal-modal-body">
                    <input type="hidden" id="codes-edit-id">

                    <label>Código proveedor</label>
                    <input type="text" id="codes-edit-codigo" style="width:100%;margin-bottom:12px;padding:8px;">

                    <label>Proveedor</label>
                    <div style="position:relative;margin-bottom:4px;">
                        <input type="text" id="codes-edit-proveedor-search" autocomplete="off"
                               placeholder="Buscar proveedor por nombre o RUT..." style="width:100%;padding:8px;">
                        <div id="codes-edit-proveedor-results" class="codes-picker"></div>
                    </div>
                    <div id="codes-edit-proveedor-hint" style="font-size:12px;color:var(--success);margin-bottom:12px;"></div>
                    <input type="hidden" id="codes-edit-proveedor-id">

                    <label>Descripción del proveedor</label>
                    <input type="text" id="codes-edit-descripcion" style="width:100%;margin-bottom:12px;padding:8px;">

                    <label>Origen</label>
                    <input type="text" id="codes-edit-origen" readonly style="width:100%;margin-bottom:12px;padding:8px;background:#f6f7f7;">

                    <label>Fecha de ingreso</label>
                    <input type="text" id="codes-edit-fecha" readonly style="width:100%;margin-bottom:12px;padding:8px;background:#f6f7f7;">

                    <label>SKU local vinculado</label>
                    <div style="position:relative;margin-bottom:4px;">
                        <input type="text" id="codes-edit-sku-search" autocomplete="off"
                               placeholder="Buscar por SKU o nombre..." style="width:100%;padding:8px;">
                        <div id="codes-edit-sku-results" class="codes-picker"></div>
                    </div>
                    <div id="codes-edit-sku-hint" style="font-size:12px;color:var(--success);margin-bottom:6px;"></div>
                    <input type="hidden" id="codes-edit-base-id">
                    <button type="button" class="btn btn-secondary btn-sm" id="codes-edit-unlink" style="margin-bottom:12px;">
                        Desvincular SKU
                    </button>

                    <label>Notas</label>
                    <textarea id="codes-edit-notas" rows="2" style="width:100%;margin-bottom:12px;padding:8px;"></textarea>

                    <label style="display:block;margin-bottom:12px;">
                        <input type="checkbox" id="codes-edit-activo"> Código activo
                    </label>

                    <label>Motivo del cambio</label>
                    <textarea id="codes-edit-audit" rows="2" placeholder="Queda registrado en auditoría"
                              style="width:100%;padding:8px;"></textarea>

                    <div id="codes-edit-confirm-box" style="display:none;margin-top:14px;padding:12px;background:#fff8e5;border:1px solid #f0c36d;border-radius:4px;">
                        <div style="color:#6e4e00;font-size:13px;line-height:1.4;">
                            <strong style="display:inline-block;margin-right:8px;background:#f0c36d;color:#3c2f00;padding:2px 8px;border-radius:3px;font-size:11px;text-transform:uppercase;">Por confirmar</strong>
                            <span id="codes-edit-task-hint"></span>
                        </div>
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="btn btn-primary btn-sm" id="codes-edit-confirm">Confirmar vínculo</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="codes-edit-reject">Rechazar código</button>
                        </div>
                    </div>

                    <div id="codes-edit-error" style="display:none;margin-top:12px;padding:8px 10px;background:#fdecea;border-left:4px solid var(--danger, #d63638);"></div>
                </div>
                <div class="portal-modal-footer">
                    <button type="button" class="btn btn-secondary" id="codes-edit-cancel">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="codes-edit-save">Guardar</button>
                </div>
            </div>
        </div>

        <div id="codes-link-modal" class="portal-modal-overlay">
            <div class="portal-modal">
                <div class="portal-modal-header">
                    <h3>Vincular código de factura</h3>
                    <button type="button" class="btn btn-secondary btn-sm" id="codes-link-close">Cerrar</button>
                </div>
                <div class="portal-modal-body">
                    <input type="hidden" id="codes-link-item-id">
                    <div id="codes-link-summary" style="background:var(--bg-light);padding:12px;border-radius:6px;margin-bottom:16px;font-size:13px;"></div>

                    <label>SKU local</label>
                    <div style="position:relative;margin-bottom:4px;">
                        <input type="text" id="codes-link-sku-search" autocomplete="off"
                               placeholder="Buscar por SKU o nombre..." style="width:100%;padding:8px;">
                        <div id="codes-link-sku-results" class="codes-picker"></div>
                    </div>
                    <div id="codes-link-sku-hint" style="font-size:12px;color:var(--success);margin-bottom:12px;"></div>
                    <input type="hidden" id="codes-link-sku">

                    <label style="display:block;">
                        <input type="checkbox" id="codes-link-save-mapping" checked> Guardar mapeo para futuras facturas
                    </label>

                    <div id="codes-link-error" style="display:none;margin-top:12px;padding:8px 10px;background:#fdecea;border-left:4px solid var(--danger, #d63638);"></div>
                </div>
                <div class="portal-modal-footer">
                    <button type="button" class="btn btn-secondary" id="codes-link-cancel">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="codes-link-save">Vincular</button>
                </div>
            </div>
        </div>

        <style>
        .codes-picker {
            display: none;
            position: absolute;
            z-index: 30;
            left: 0;
            right: 0;
            min-width: 240px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 4px;
            max-height: 220px;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }
        .codes-picker .codes-pick {
            padding: 8px 10px;
            cursor: pointer;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        .codes-picker .codes-pick:hover { background: var(--bg-light); }
        .codes-picker .codes-pick-empty {
            padding: 10px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .codes-picker .matched-apodo {
            display: block;
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
        }
        .apodo-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: center;
        }
        .apodo-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #e8f0fe;
            color: #1a73e8;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 12px;
        }
        .apodo-chip button {
            border: none;
            background: transparent;
            color: #1a73e8;
            cursor: pointer;
            padding: 0;
            line-height: 1;
            font-size: 14px;
        }
        .apodo-input {
            min-width: 90px;
            max-width: 130px;
            padding: 4px 6px;
            font-size: 12px;
            border: 1px solid var(--border);
            border-radius: 4px;
        }
        </style>

        <script>
        (function() {
            const codesNonce = '<?php echo esc_js($nonce); ?>';
            const codesAjaxUrl = '<?php echo esc_url_raw(admin_url('admin-ajax.php')); ?>';

            function esc(s) {
                return String(s ?? '')
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function post(action, params) {
                const body = new URLSearchParams({action, nonce: codesNonce, ...params});
                return fetch(codesAjaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body
                }).then(r => r.json());
            }

            function debounce(fn, wait) {
                let timer = null;
                return function(...args) {
                    clearTimeout(timer);
                    timer = setTimeout(() => fn.apply(this, args), wait);
                };
            }

            const listEl = document.getElementById('codes-list');
            if (!listEl) return;

            let page = 1;
            let totalPages = 1;
            let requestId = 0;

            // --- Stats ---
            function loadStats() {
                post('riverso_get_codes_stats', {}).then(d => {
                    if (!d.success) return;
                    const s = d.data;
                    document.getElementById('codes-stat-cards').innerHTML = `
                        <div class="stat-card">
                            <div class="stat-icon blue"><span class="dashicons dashicons-tag"></span></div>
                            <div class="stat-value">${s.total || 0}</div>
                            <div class="stat-label">Total códigos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon orange"><span class="dashicons dashicons-warning"></span></div>
                            <div class="stat-value">${s.pending || 0}</div>
                            <div class="stat-label">Pendientes</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="background:#fffbeb;color:#78350f;"><span class="dashicons dashicons-flag"></span></div>
                            <div class="stat-value">${s.por_confirmar || 0}</div>
                            <div class="stat-label">Por confirmar</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon green"><span class="dashicons dashicons-yes-alt"></span></div>
                            <div class="stat-value">${s.linked || 0}</div>
                            <div class="stat-label">Vinculados</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon purple"><span class="dashicons dashicons-groups"></span></div>
                            <div class="stat-value">${s.providers || 0}</div>
                            <div class="stat-label">Proveedores</div>
                        </div>`;
                });
            }

            // --- Tabs ---
            document.getElementById('codes-tabs').addEventListener('click', e => {
                const btn = e.target.closest('.codes-tab');
                if (!btn) return;
                const tab = btn.dataset.tab;

                document.querySelectorAll('.codes-tab').forEach(el => {
                    el.classList.toggle('btn-primary', el === btn);
                    el.classList.toggle('btn-secondary', el !== btn);
                });
                ['todos', 'pendientes', 'proveedores'].forEach(name => {
                    document.getElementById('codes-tab-' + name).style.display = name === tab ? '' : 'none';
                });

                if (tab === 'pendientes') loadPending();
                if (tab === 'proveedores') loadProviders();
            });

            // --- Listado principal ---
            function loadCodes(targetPage) {
                page = Math.max(1, targetPage || 1);
                const currentRequest = ++requestId;
                document.getElementById('codes-status').textContent = 'Buscando...';

                post('riverso_get_all_codes', {
                    proveedor_id: document.getElementById('codes-proveedor-id').value,
                    estado: document.getElementById('codes-estado').value,
                    origen: document.getElementById('codes-origen')?.value || '',
                    search: document.getElementById('code-search').value,
                    page: page,
                    per_page: 50
                }).then(d => {
                    // Descartar respuestas de búsquedas ya superadas.
                    if (currentRequest !== requestId) return;

                    if (!d.success) {
                        document.getElementById('codes-status').textContent = d.data?.message || 'Error al buscar';
                        listEl.innerHTML = '<tr><td colspan="8" style="text-align:center;">No se pudo cargar el listado</td></tr>';
                        setPagination(0, 0);
                        return;
                    }

                    const codes = d.data.codes || [];
                    const total = d.data.total || 0;
                    totalPages = d.data.total_pages || 1;
                    document.getElementById('codes-status').textContent =
                        total + (total === 1 ? ' resultado' : ' resultados');

                    if (!codes.length) {
                        listEl.innerHTML = '<tr><td colspan="8" style="text-align:center;">No hay códigos que coincidan</td></tr>';
                        setPagination(0, 0);
                        return;
                    }

                    listEl.innerHTML = codes.map(c => {
                        const needs = !!c.needs_confirm;
                        let estado = c.producto_base_id
                            ? '<span style="color:var(--success);">✓ Vinculado</span>'
                            : '<span style="color:var(--warning);">⚠ Pendiente</span>';
                        if (needs) {
                            estado = '<span style="display:inline-block;background:#f0c36d;color:#3c2f00;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">Por confirmar</span>';
                        }
                        const confirmBtns = needs
                            ? `<button type="button" class="btn btn-primary btn-sm codes-confirm-btn" data-id="${c.id}">Confirmar</button>
                               <button type="button" class="btn btn-secondary btn-sm codes-reject-btn" data-id="${c.id}">Rechazar</button>`
                            : '';
                        const fecha = c.fecha_ingreso ? String(c.fecha_ingreso).split(' ')[0] : '-';
                        const rowStyle = needs ? 'background:#fffbeb;' : '';
                        let skuHtml = esc(c.sku_local || '-');
                        if (needs && c.sku_local) {
                            skuHtml = '<span style="display:inline-block;background:#fbbf24;color:#78350f;padding:1px 6px;border-radius:3px;font-weight:600;font-size:10px;letter-spacing:0.02em;">POR CONFIRMAR</span><br><small>' + esc(c.sku_local) + '</small>';
                        } else if (needs) {
                            skuHtml = '<span style="display:inline-block;background:#fbbf24;color:#78350f;padding:1px 6px;border-radius:3px;font-weight:600;font-size:10px;letter-spacing:0.02em;">POR CONFIRMAR</span>';
                        }
                        return `<tr style="${rowStyle}">
                        <td style="font-family:monospace;">${esc(c.codigo_proveedor)}</td>
                        <td class="col-desc">${esc(c.descripcion_proveedor || '-')}</td>
                        <td class="col-proveedor">${esc(c.proveedor_nombre || '-')}</td>
                        <td style="font-family:monospace;">${skuHtml}</td>
                        <td><small>${esc(c.origen_label || c.origen_datos || '-')}</small></td>
                        <td><small>${esc(fecha)}</small></td>
                        <td style="text-align:center;">${estado}</td>
                        <td style="text-align:center;white-space:nowrap;">
                            ${confirmBtns}
                            <button type="button" class="btn btn-secondary btn-sm codes-edit-btn" data-id="${c.id}">Editar</button>
                        </td>
                    </tr>`;
                    }).join('');

                    setPagination(page, totalPages);
                });
            }

            function setPagination(current, pages) {
                document.getElementById('codes-page-info').textContent =
                    pages > 0 ? `Página ${current} de ${pages}` : '';
                document.getElementById('codes-prev').disabled = current <= 1;
                document.getElementById('codes-next').disabled = current >= pages;
            }

            document.getElementById('code-search').addEventListener('input', debounce(() => loadCodes(1), 300));
            document.getElementById('codes-estado').addEventListener('change', () => loadCodes(1));
            const origenEl = document.getElementById('codes-origen');
            if (origenEl) origenEl.addEventListener('change', () => loadCodes(1));
            document.getElementById('codes-prev').addEventListener('click', () => loadCodes(page - 1));
            document.getElementById('codes-next').addEventListener('click', () => loadCodes(page + 1));

            listEl.addEventListener('click', e => {
                const confirmBtn = e.target.closest('.codes-confirm-btn');
                const rejectBtn = e.target.closest('.codes-reject-btn');
                if (confirmBtn) {
                    if (!confirm('¿Confirmar este vínculo?')) return;
                    post('riverso_codes_confirm', { pp_id: confirmBtn.dataset.id }).then(d => {
                        if (!d.success) { alert(d.data?.message || 'Error'); return; }
                        loadCodes(page);
                    });
                    return;
                }
                if (rejectBtn) {
                    if (!confirm('¿Rechazar este código? Quedará inactivo.')) return;
                    post('riverso_codes_reject', { pp_id: rejectBtn.dataset.id }).then(d => {
                        if (!d.success) { alert(d.data?.message || 'Error'); return; }
                        loadCodes(page);
                    });
                }
            });

            // --- Autocompletado reutilizable ---
            function bindPicker(searchId, resultsId, options) {
                const searchEl = document.getElementById(searchId);
                const resultsEl = document.getElementById(resultsId);
                if (!searchEl || !resultsEl) return;

                searchEl.addEventListener('input', debounce(() => {
                    const q = searchEl.value.trim();
                    if (options.onType) options.onType();

                    if (q.length < 2) {
                        resultsEl.innerHTML = '';
                        resultsEl.style.display = 'none';
                        return;
                    }

                    post(options.action, options.params(q)).then(d => {
                        if (!d.success) return;
                        const items = options.extract(d) || [];
                        resultsEl.innerHTML = items.length
                            ? items.map(options.render).join('')
                            : `<div class="codes-pick-empty">${esc(options.emptyText)}</div>`;
                        resultsEl.style.display = 'block';
                    });
                }, 300));

                resultsEl.addEventListener('click', e => {
                    const pick = e.target.closest('.codes-pick');
                    if (!pick) return;
                    options.onPick(pick.dataset);
                    resultsEl.innerHTML = '';
                    resultsEl.style.display = 'none';
                });

                document.addEventListener('click', e => {
                    if (!searchEl.contains(e.target) && !resultsEl.contains(e.target)) {
                        resultsEl.style.display = 'none';
                    }
                });
            }

            const supplierPickerOptions = (onPick, onType) => ({
                action: 'riverso_search_suppliers',
                params: q => ({search: q, limit: 15}),
                extract: d => d.data.suppliers,
                emptyText: 'Sin proveedores para esa búsqueda.',
                render: s => {
                    const apodoHint = s.matched_apodo
                        ? `<span class="matched-apodo">Apodo: ${esc(s.matched_apodo)}</span>`
                        : '';
                    return `<div class="codes-pick" data-id="${s.id}" data-nombre="${esc(s.nombre)}">
                        <strong>${esc(s.nombre)}</strong> <span style="color:var(--text-secondary);">${esc(s.rut || '')}</span>
                        ${apodoHint}
                    </div>`;
                },
                onPick,
                onType
            });

            const skuPickerOptions = (onPick) => ({
                action: 'riverso_search_sku_catalog',
                params: q => ({search: q}),
                extract: d => d.data.products,
                emptyText: 'Sin SKU que coincidan.',
                render: p => `<div class="codes-pick" data-id="${p.id}" data-sku="${esc(p.canonical_sku)}" data-nombre="${esc(p.nombre_canonico || '')}">
                        <strong>${esc(p.canonical_sku)}</strong><br>
                        <span style="color:var(--text-secondary);font-size:12px;">${esc(p.nombre_canonico || '')}</span>
                    </div>`,
                onPick
            });

            // Filtro de proveedor
            bindPicker('codes-proveedor-search', 'codes-proveedor-results', supplierPickerOptions(
                data => {
                    document.getElementById('codes-proveedor-id').value = data.id;
                    document.getElementById('codes-proveedor-search').value = data.nombre;
                    document.getElementById('codes-clear-proveedor').style.display = '';
                    loadCodes(1);
                },
                () => {
                    document.getElementById('codes-proveedor-id').value = '';
                    document.getElementById('codes-clear-proveedor').style.display = 'none';
                }
            ));

            document.getElementById('codes-clear-proveedor').addEventListener('click', () => {
                document.getElementById('codes-proveedor-id').value = '';
                document.getElementById('codes-proveedor-search').value = '';
                document.getElementById('codes-clear-proveedor').style.display = 'none';
                loadCodes(1);
            });

            // --- Pendientes de factura ---
            let pendingPage = 1;
            let pendingTotalPages = 1;
            let pendingRequestId = 0;

            function setPendingPagination(current, pages) {
                document.getElementById('codes-pending-page-info').textContent =
                    pages > 0 ? `Página ${current} de ${pages}` : '';
                document.getElementById('codes-pending-prev').disabled = current <= 1;
                document.getElementById('codes-pending-next').disabled = current >= pages;
            }

            function loadPending(targetPage) {
                pendingPage = Math.max(1, targetPage || 1);
                const currentRequest = ++pendingRequestId;
                const tbody = document.getElementById('codes-pending-list');
                document.getElementById('codes-pending-status').textContent = 'Buscando...';
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Cargando...</td></tr>';

                post('riverso_get_pending_codes', {
                    proveedor_id: document.getElementById('codes-pending-proveedor-id').value,
                    search: document.getElementById('codes-pending-search').value,
                    page: pendingPage,
                    per_page: 50
                }).then(d => {
                    if (currentRequest !== pendingRequestId) return;

                    if (!d.success) {
                        document.getElementById('codes-pending-status').textContent = d.data?.message || 'Error';
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No se pudo cargar</td></tr>';
                        setPendingPagination(0, 0);
                        return;
                    }

                    const items = d.data.items || [];
                    const total = d.data.total || 0;
                    pendingTotalPages = d.data.total_pages || 1;
                    document.getElementById('codes-pending-status').textContent =
                        total + (total === 1 ? ' resultado' : ' resultados');

                    if (!items.length) {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Sin códigos pendientes que coincidan</td></tr>';
                        setPendingPagination(0, 0);
                        return;
                    }

                    tbody.innerHTML = items.map(i => `<tr>
                        <td style="font-family:monospace;">${esc(i.codigo_proveedor || '-')}</td>
                        <td class="col-desc">${esc(i.descripcion || '-')}</td>
                        <td class="col-proveedor">${esc(i.proveedor_nombre || '-')}</td>
                        <td>#${esc(i.folio)}</td>
                        <td style="text-align:center;">
                            <button type="button" class="btn btn-primary btn-sm codes-link-btn"
                                    data-id="${i.id}"
                                    data-codigo="${esc(i.codigo_proveedor || '')}"
                                    data-descripcion="${esc(i.descripcion || '')}"
                                    data-proveedor="${esc(i.proveedor_nombre || '')}">Vincular</button>
                        </td>
                    </tr>`).join('');

                    setPendingPagination(pendingPage, pendingTotalPages);
                });
            }

            bindPicker('codes-pending-proveedor-search', 'codes-pending-proveedor-results', supplierPickerOptions(
                data => {
                    document.getElementById('codes-pending-proveedor-id').value = data.id;
                    document.getElementById('codes-pending-proveedor-search').value = data.nombre;
                    document.getElementById('codes-clear-pending-proveedor').style.display = '';
                    loadPending(1);
                },
                () => {
                    document.getElementById('codes-pending-proveedor-id').value = '';
                    document.getElementById('codes-clear-pending-proveedor').style.display = 'none';
                }
            ));

            document.getElementById('codes-clear-pending-proveedor').addEventListener('click', () => {
                document.getElementById('codes-pending-proveedor-id').value = '';
                document.getElementById('codes-pending-proveedor-search').value = '';
                document.getElementById('codes-clear-pending-proveedor').style.display = 'none';
                loadPending(1);
            });

            document.getElementById('codes-pending-search').addEventListener('input', debounce(() => loadPending(1), 300));
            document.getElementById('codes-pending-prev').addEventListener('click', () => loadPending(pendingPage - 1));
            document.getElementById('codes-pending-next').addEventListener('click', () => loadPending(pendingPage + 1));

            // --- Proveedores ---
            function renderApodosCell(providerId, apodos) {
                const chips = (apodos || []).map(a => `
                    <span class="apodo-chip">
                        ${esc(a)}
                        <button type="button" class="codes-remove-apodo" data-id="${providerId}" data-apodo="${esc(a)}" title="Quitar">&times;</button>
                    </span>`).join('');
                return `<div class="apodo-chips" data-provider-id="${providerId}">
                    ${chips}
                    <input type="text" class="apodo-input" data-id="${providerId}" placeholder="+ apodo" autocomplete="off">
                </div>`;
            }

            function loadProviders() {
                const tbody = document.getElementById('codes-providers-list');
                const search = document.getElementById('codes-providers-search').value;
                document.getElementById('codes-providers-status').textContent = 'Buscando...';
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Cargando...</td></tr>';

                post('riverso_get_providers', {search}).then(d => {
                    const providers = d.success ? (d.data.providers || []) : [];
                    document.getElementById('codes-providers-status').textContent =
                        providers.length + (providers.length === 1 ? ' resultado' : ' resultados');

                    if (!providers.length) {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Sin proveedores</td></tr>';
                        return;
                    }
                    tbody.innerHTML = providers.map(p => `<tr data-provider-id="${p.id}">
                        <td>${esc(p.rut)}</td>
                        <td><strong>${esc(p.nombre)}</strong></td>
                        <td>${renderApodosCell(p.id, p.apodos || [])}</td>
                        <td>${esc(p.contacto || p.email || '-')}</td>
                        <td style="text-align:center;">${p.codigos_count || 0}</td>
                        <td style="text-align:center;">${parseInt(p.activo, 10) === 1
                            ? '<span style="color:var(--success);">Activo</span>'
                            : '<span style="color:var(--text-secondary);">Inactivo</span>'}</td>
                    </tr>`).join('');
                });
            }

            document.getElementById('codes-providers-search').addEventListener('input', debounce(loadProviders, 300));

            function addProviderApodo(providerId, apodo, input) {
                apodo = String(apodo || '').trim();
                if (!apodo) return;
                post('riverso_add_provider_alias', {
                    proveedor_id: providerId,
                    apodo: apodo
                }).then(d => {
                    if (!d.success) {
                        alert(d.data?.message || 'No se pudo agregar el apodo');
                        return;
                    }
                    const cell = input.closest('td');
                    cell.innerHTML = renderApodosCell(providerId, d.data.apodos || []);
                    cell.querySelector('.apodo-input')?.focus();
                });
            }

            document.getElementById('codes-providers-list').addEventListener('keydown', e => {
                if (!e.target.classList.contains('apodo-input')) return;
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    addProviderApodo(e.target.dataset.id, e.target.value.replace(/,/g, ''), e.target);
                }
            });

            document.getElementById('codes-providers-list').addEventListener('focusout', e => {
                if (!e.target.classList.contains('apodo-input')) return;
                const val = e.target.value.trim();
                if (val) {
                    addProviderApodo(e.target.dataset.id, val, e.target);
                }
            });

            document.getElementById('codes-providers-list').addEventListener('click', e => {
                const btn = e.target.closest('.codes-remove-apodo');
                if (!btn) return;
                const providerId = btn.dataset.id;
                const apodo = btn.dataset.apodo;
                const cell = btn.closest('td');
                post('riverso_remove_provider_alias', {
                    proveedor_id: providerId,
                    apodo: apodo
                }).then(d => {
                    if (!d.success) {
                        alert(d.data?.message || 'No se pudo eliminar el apodo');
                        return;
                    }
                    cell.innerHTML = renderApodosCell(providerId, d.data.apodos || []);
                });
            });

            loadStats();
            loadCodes(1);

            // --- Modal de edición ---
            const editModal = document.getElementById('codes-edit-modal');

            function setEditSku(baseId, sku, nombre) {
                document.getElementById('codes-edit-base-id').value = baseId || '';
                document.getElementById('codes-edit-sku-hint').textContent = baseId
                    ? 'Vinculado a ' + sku + (nombre ? ' — ' + nombre : '')
                    : 'Sin SKU local vinculado';
                document.getElementById('codes-edit-unlink').style.display = baseId ? '' : 'none';
            }

            function closeEditModal() {
                editModal.classList.remove('open');
            }

            document.getElementById('codes-edit-close').addEventListener('click', closeEditModal);
            document.getElementById('codes-edit-cancel').addEventListener('click', closeEditModal);
            editModal.addEventListener('click', e => { if (e.target === editModal) closeEditModal(); });

            listEl.addEventListener('click', e => {
                const btn = e.target.closest('.codes-edit-btn');
                if (!btn) return;

                document.getElementById('codes-edit-error').style.display = 'none';
                document.getElementById('codes-edit-sku-search').value = '';
                document.getElementById('codes-edit-audit').value = '';
                const confirmBox = document.getElementById('codes-edit-confirm-box');
                const taskHint = document.getElementById('codes-edit-task-hint');
                if (confirmBox) confirmBox.style.display = 'none';
                if (taskHint) taskHint.textContent = '';

                post('riverso_codes_get', {pp_id: btn.dataset.id}).then(d => {
                    if (!d.success) {
                        alert(d.data?.message || 'No se pudo cargar el código');
                        return;
                    }
                    const c = d.data.code;
                    document.getElementById('codes-edit-id').value = c.id;
                    document.getElementById('codes-edit-codigo').value = c.codigo_proveedor || '';
                    document.getElementById('codes-edit-descripcion').value = c.nombre_proveedor || '';
                    document.getElementById('codes-edit-origen').value = c.origen_label || c.origen_datos || '-';
                    document.getElementById('codes-edit-fecha').value = c.fecha_ingreso || c.created_at || '-';
                    document.getElementById('codes-edit-notas').value = c.notas || '';
                    document.getElementById('codes-edit-activo').checked = parseInt(c.activo, 10) === 1;
                    document.getElementById('codes-edit-proveedor-id').value = c.proveedor_id || '';
                    document.getElementById('codes-edit-proveedor-search').value = c.proveedor_nombre || '';
                    document.getElementById('codes-edit-proveedor-hint').textContent =
                        c.proveedor_nombre ? 'Proveedor: ' + c.proveedor_nombre : 'Sin proveedor asignado';
                    setEditSku(c.producto_base_id, c.canonical_sku, c.nombre_canonico);

                    const needsConfirm = !!c.needs_confirm;
                    if (confirmBox) {
                        confirmBox.style.display = needsConfirm ? 'block' : 'none';
                    }
                    if (needsConfirm && taskHint) {
                        let hint = 'Este vínculo viene de catálogo/legacy y requiere revisión humana.';
                        if (c.has_open_task && c.open_task) {
                            hint = 'Hay una tarea abierta: «' + (c.open_task.titulo || 'Confirmar código proveedor') + '» (#' + c.open_task.id + ').';
                        } else if (c.has_open_task) {
                            hint = 'Hay una tarea de confirmación asignada a este código.';
                        }
                        taskHint.textContent = hint;
                    }

                    editModal.classList.add('open');
                });
            });

            bindPicker('codes-edit-proveedor-search', 'codes-edit-proveedor-results', supplierPickerOptions(
                data => {
                    document.getElementById('codes-edit-proveedor-id').value = data.id;
                    document.getElementById('codes-edit-proveedor-search').value = data.nombre;
                    document.getElementById('codes-edit-proveedor-hint').textContent = 'Proveedor: ' + data.nombre;
                },
                () => {
                    document.getElementById('codes-edit-proveedor-id').value = '';
                    document.getElementById('codes-edit-proveedor-hint').textContent = 'Selecciona un proveedor';
                }
            ));

            bindPicker('codes-edit-sku-search', 'codes-edit-sku-results', skuPickerOptions(data => {
                setEditSku(data.id, data.sku, data.nombre);
                document.getElementById('codes-edit-sku-search').value = '';
            }));

            document.getElementById('codes-edit-unlink').addEventListener('click', () => setEditSku(0, '', ''));

            function closeEditAfterReview() {
                editModal.classList.remove('open');
                loadCodes(page);
            }

            const editConfirmBtn = document.getElementById('codes-edit-confirm');
            if (editConfirmBtn) {
                editConfirmBtn.addEventListener('click', () => {
                    const id = document.getElementById('codes-edit-id').value;
                    if (!id || !confirm('¿Confirmar este vínculo de código a SKU local?')) return;
                    post('riverso_codes_confirm', {
                        pp_id: id,
                        audit_reason: document.getElementById('codes-edit-audit').value
                    }).then(d => {
                        if (!d.success) { alert(d.data?.message || 'Error al confirmar'); return; }
                        closeEditAfterReview();
                    });
                });
            }

            const editRejectBtn = document.getElementById('codes-edit-reject');
            if (editRejectBtn) {
                editRejectBtn.addEventListener('click', () => {
                    const id = document.getElementById('codes-edit-id').value;
                    if (!id || !confirm('¿Rechazar este código? Quedará inactivo.')) return;
                    post('riverso_codes_reject', {
                        pp_id: id,
                        audit_reason: document.getElementById('codes-edit-audit').value
                    }).then(d => {
                        if (!d.success) { alert(d.data?.message || 'Error al rechazar'); return; }
                        closeEditAfterReview();
                    });
                });
            }

            document.getElementById('codes-edit-save').addEventListener('click', function() {
                const errorEl = document.getElementById('codes-edit-error');
                const codigo = document.getElementById('codes-edit-codigo').value.trim();
                const proveedorId = document.getElementById('codes-edit-proveedor-id').value;

                function fail(message) {
                    errorEl.textContent = message;
                    errorEl.style.display = 'block';
                }

                errorEl.style.display = 'none';
                if (!codigo) { fail('El código no puede quedar vacío'); return; }
                if (!proveedorId) { fail('Selecciona un proveedor'); return; }

                this.disabled = true;
                const button = this;
                post('riverso_codes_update', {
                    pp_id: document.getElementById('codes-edit-id').value,
                    codigo_proveedor: codigo,
                    proveedor_id: proveedorId,
                    nombre_proveedor: document.getElementById('codes-edit-descripcion').value,
                    notas: document.getElementById('codes-edit-notas').value,
                    activo: document.getElementById('codes-edit-activo').checked ? 1 : 0,
                    producto_base_id: document.getElementById('codes-edit-base-id').value || 0,
                    audit_reason: document.getElementById('codes-edit-audit').value
                }).then(d => {
                    button.disabled = false;
                    if (!d.success) { fail(d.data?.message || 'No se pudo guardar'); return; }
                    closeEditModal();
                    loadCodes(page);
                    loadStats();
                }).catch(() => {
                    button.disabled = false;
                    fail('Error de conexión al guardar');
                });
            });

            // --- Modal de vinculación de pendientes ---
            const linkModal = document.getElementById('codes-link-modal');

            function closeLinkModal() {
                linkModal.classList.remove('open');
            }

            document.getElementById('codes-link-close').addEventListener('click', closeLinkModal);
            document.getElementById('codes-link-cancel').addEventListener('click', closeLinkModal);
            linkModal.addEventListener('click', e => { if (e.target === linkModal) closeLinkModal(); });

            document.getElementById('codes-pending-list').addEventListener('click', e => {
                const btn = e.target.closest('.codes-link-btn');
                if (!btn) return;

                document.getElementById('codes-link-item-id').value = btn.dataset.id;
                document.getElementById('codes-link-sku').value = '';
                document.getElementById('codes-link-sku-search').value = '';
                document.getElementById('codes-link-sku-hint').textContent = '';
                document.getElementById('codes-link-error').style.display = 'none';
                document.getElementById('codes-link-summary').innerHTML = `
                    <div><strong>Código:</strong> ${esc(btn.dataset.codigo || '-')}</div>
                    <div><strong>Descripción:</strong> ${esc(btn.dataset.descripcion || '-')}</div>
                    <div><strong>Proveedor:</strong> ${esc(btn.dataset.proveedor || '-')}</div>`;
                linkModal.classList.add('open');
            });

            bindPicker('codes-link-sku-search', 'codes-link-sku-results', skuPickerOptions(data => {
                document.getElementById('codes-link-sku').value = data.sku;
                document.getElementById('codes-link-sku-search').value = data.sku;
                document.getElementById('codes-link-sku-hint').textContent =
                    data.nombre ? 'Seleccionado: ' + data.nombre : 'Seleccionado';
            }));

            document.getElementById('codes-link-save').addEventListener('click', function() {
                const errorEl = document.getElementById('codes-link-error');
                const sku = document.getElementById('codes-link-sku').value;
                const button = this;

                function fail(message) {
                    errorEl.textContent = message;
                    errorEl.style.display = 'block';
                }

                errorEl.style.display = 'none';
                if (!sku) { fail('Selecciona un SKU local'); return; }

                function send(force) {
                    button.disabled = true;
                    post('riverso_link_code', {
                        item_id: document.getElementById('codes-link-item-id').value,
                        sku_local: sku,
                        crear_mapeo: document.getElementById('codes-link-save-mapping').checked ? 1 : 0,
                        force: force ? 1 : 0
                    }).then(d => {
                        button.disabled = false;
                        if (d.success) {
                            closeLinkModal();
                            loadPending();
                            loadCodes(page);
                            loadStats();
                            return;
                        }
                        const data = d.data || {};
                        if (data.conflict && !force) {
                            if (confirm((data.message || 'Conflicto de SKU') + '\n\n¿Reasignar de todas formas?')) {
                                send(true);
                            }
                            return;
                        }
                        fail(data.message || 'No se pudo vincular');
                    }).catch(() => {
                        button.disabled = false;
                        fail('Error de conexión al vincular');
                    });
                }
                send(false);
            });
        })();
        </script>
        <?php endif; ?>
        
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
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-received-quotes'); ?>" class="btn btn-secondary" style="margin-top: 15px;">
                    Ver en WP Admin
                </a>
                <div style="margin-top: 24px;">
                    <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/cost-quotes-wip.php'; ?>
                </div>
            </div>
        </div>
        
        <?php elseif ($current_page === 'cost-history'): ?>
        <!-- Historial de Costos -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Historial de Costos</h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-costs'); ?>" class="btn btn-secondary" target="_blank" rel="noopener">
                    WP Admin
                </a>
            </div>
            <div class="section-body">
                <?php if (!current_user_can('riverso_view_costs')): ?>
                    <p style="color: var(--text-secondary);">No tienes permiso para ver el historial de costos.</p>
                <?php else: ?>
                    <?php
                    if (!class_exists('Riverso_Cost_History_Module')) {
                        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-history-module.php';
                    }
                    $lookup_svc = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
                    if (file_exists($lookup_svc) && !class_exists('Riverso_Cost_Lookup_Service')) {
                        require_once $lookup_svc;
                    }
                    Riverso_Cost_History_Module::get_instance();
                    $riverso_cost_history_context = 'portal';
                    include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/cost-history-app.php';
                    ?>
                <?php endif; ?>
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
                <input type="text" id="catalog-search" placeholder="Buscar por SKU catálogo, SKU local o nombre..." style="padding:8px 12px;border:1px solid var(--border);border-radius:4px;min-width:320px;">
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

        <?php elseif ($current_page === 'products'): ?>
        <!-- Productos (Hub) -->
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/portal/portal-products.php'; ?>

        <?php elseif ($current_page === 'categories'): ?>
        <!-- Categorías y Familias -->
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/portal/portal-categories.php'; ?>

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
        
        <?php elseif ($current_page === 'impresiones'): ?>
        <?php
        $po_can_create  = current_user_can('riverso_create_print_orders') || current_user_can('manage_options');
        $po_can_approve = current_user_can('riverso_approve_print_orders') || current_user_can('manage_options');
        $po_can_print   = current_user_can('riverso_print_orders') || current_user_can('riverso_print_labels') || current_user_can('manage_options');
        $po_can_cancel  = current_user_can('riverso_cancel_print_orders') || current_user_can('manage_options');
        $po_can_edit_price = current_user_can('riverso_edit_print_order_price') || current_user_can('manage_options');
        $po_mine_only   = !$po_can_approve;
        $po_tipos = class_exists('Riverso_Print_Order_Module') ? Riverso_Print_Order_Module::TIPOS : [
            'etiqueta_producto' => 'Etiqueta producto',
            'bolsa' => 'Bolsa',
            'etiqueta_simple' => 'Etiqueta simple',
            'etiqueta_logo' => 'Etiqueta con logo',
        ];
        $po_modos = class_exists('Riverso_Print_Order_Module') ? Riverso_Print_Order_Module::MODOS : ['BolsaCOD'];
        $po_colores = class_exists('Riverso_Print_Order_Module') ? Riverso_Print_Order_Module::COLORES : ['BN', 'RN'];
        ?>
        <style>
            .po-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
            .po-badge-borrador { background:#e5e7eb; color:#374151; }
            .po-badge-pendiente { background:#fef3c7; color:#92400e; }
            .po-badge-aprobada { background:#dbeafe; color:#1e40af; }
            .po-badge-impresa { background:#d1fae5; color:#065f46; }
            .po-badge-cancelada { background:#fee2e2; color:#991b1b; }
            .po-toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
            .po-toolbar input, .po-toolbar select { padding:8px 10px; border:1px solid var(--border); border-radius:4px; }
            .po-hit { padding:10px; border:1px solid var(--border); border-radius:8px; margin-top:8px; display:flex; justify-content:space-between; gap:10px; background:var(--bg-light); }
            .po-editor-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:12px; }
            #po-portal-editor { scroll-margin-top: 16px; }
        </style>
        <div class="stats-grid" id="po-portal-stats"></div>
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Órdenes de impresión</h2>
                <?php if ($po_can_create): ?>
                <button type="button" class="btn btn-primary" id="po-portal-new">Nueva orden</button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <div class="po-toolbar">
                    <input type="search" id="po-portal-search" placeholder="Número, SKU o solicitante..." style="min-width:220px;">
                    <select id="po-portal-estado">
                        <option value="">Todos los estados</option>
                        <option value="borrador">Borrador</option>
                        <option value="pendiente">Pendiente</option>
                        <option value="aprobada">Aprobada</option>
                        <option value="impresa">Impresa</option>
                        <option value="cancelada">Cancelada</option>
                    </select>
                    <button type="button" class="btn btn-secondary" id="po-portal-filter">Buscar</button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="portal-table" id="po-portal-table">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Estado</th>
                                <th>Tipo</th>
                                <th>Ítems</th>
                                <th>Copias</th>
                                <th>Solicitante</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="po-portal-body">
                            <tr><td colspan="8" style="text-align:center;padding:24px;">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="po-portal-pages" style="margin-top:12px;color:var(--text-secondary);font-size:13px;"></div>
            </div>
        </div>

        <div class="content-section" id="po-portal-editor" style="display:none;">
            <div class="section-header">
                <h2 class="section-title" id="po-portal-editor-title">Nueva orden</h2>
                <button type="button" class="btn btn-secondary po-close-order">Cerrar</button>
            </div>
            <div class="section-body">
                <input type="hidden" id="po-portal-id" value="">
                <div class="po-editor-grid">
                    <div>
                        <label>Tipo</label>
                        <select id="po-portal-tipo" style="width:100%;padding:8px;">
                            <?php foreach ($po_tipos as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Prioridad</label>
                        <label style="display:flex;gap:6px;align-items:center;margin-top:8px;">
                            <input type="checkbox" id="po-portal-prioridad"> Urgente
                        </label>
                    </div>
                    <div>
                        <label>Estado</label>
                        <div id="po-portal-estado-badge" style="margin-top:8px;"><span class="po-badge po-badge-borrador">Borrador</span></div>
                    </div>
                    <div>
                        <label>Número</label>
                        <div id="po-portal-numero" style="margin-top:8px;color:var(--text-secondary);">Se asigna al guardar</div>
                    </div>
                </div>
                <label>Notas</label>
                <textarea id="po-portal-notas" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;margin:6px 0 14px;"></textarea>

                <?php if ($po_can_create): ?>
                <div id="po-portal-add-box">
                <label>Agregar producto</label>
                <div style="display:flex;gap:8px;margin:6px 0;flex-wrap:wrap;">
                    <input type="search" id="po-portal-q" placeholder="SKU, código o nombre..." style="flex:1;min-width:180px;padding:10px;border:1px solid var(--border);border-radius:4px;">
                    <button type="button" class="btn btn-secondary" id="po-portal-q-btn">Buscar</button>
                    <button type="button" class="btn btn-secondary" id="po-portal-q-clear">Borrar búsqueda</button>
                </div>
                <div id="po-portal-hits"></div>
                </div>
                <?php endif; ?>

                <div style="overflow-x:auto;margin-top:12px;">
                    <table class="portal-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Nombre en etiqueta</th>
                                <th>Cant. EAN</th>
                                <th>Copias</th>
                                <th>Modo</th>
                                <th>Color</th>
                                <th>EAN13</th>
                                <th>Precio</th>
                                <?php if ($po_can_create): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="po-portal-items">
                            <tr><td colspan="9" style="text-align:center;color:var(--text-secondary);">Sin productos</td></tr>
                        </tbody>
                    </table>
                </div>
                <p style="margin-top:8px;font-size:13px;color:var(--text-secondary);">Los cambios de nombre, copias, modo, color y EAN aplican solo a esta impresión. El SKU no se modifica. El precio se muestra con 2 decimales; usa <strong>Redondear</strong> o <strong>Desredondear</strong> en esta impresión. Cambiar el precio a otro valor requiere permiso.</p>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;align-items:center;">
                    <button type="button" class="btn btn-secondary po-close-order">Cerrar</button>
                    <?php if ($po_can_create): ?>
                    <button type="button" class="btn btn-primary po-workflow-btn" id="po-portal-save">Guardar borrador</button>
                    <button type="button" class="btn btn-secondary po-workflow-btn" id="po-portal-submit">Enviar</button>
                    <button type="button" class="btn btn-primary" id="po-portal-use-as-draft" style="display:none;">Usar como borrador</button>
                    <?php endif; ?>
                    <?php if ($po_can_approve): ?>
                    <button type="button" class="btn btn-secondary po-workflow-btn" id="po-portal-approve">Aprobar</button>
                    <?php endif; ?>
                    <?php if ($po_can_print): ?>
                    <button type="button" class="btn btn-primary po-workflow-btn" id="po-portal-print">Imprimir</button>
                    <button type="button" class="btn btn-primary" id="po-portal-reprint" style="display:none;">Volver a imprimir</button>
                    <?php endif; ?>
                    <?php if ($po_can_create || $po_can_cancel): ?>
                    <button type="button" class="btn btn-secondary po-workflow-btn" id="po-portal-cancel">Cancelar</button>
                    <?php endif; ?>
                    <span id="po-portal-msg" style="font-size:13px;color:var(--text-secondary);"></span>
                </div>
            </div>
        </div>
        <script>
        window.riversoPrintOrders = {
            canCreate: <?php echo $po_can_create ? 'true' : 'false'; ?>,
            canApprove: <?php echo $po_can_approve ? 'true' : 'false'; ?>,
            canPrint: <?php echo $po_can_print ? 'true' : 'false'; ?>,
            canCancel: <?php echo $po_can_cancel ? 'true' : 'false'; ?>,
            canEditPrice: <?php echo $po_can_edit_price ? 'true' : 'false'; ?>,
            mine: <?php echo $po_mine_only ? 'true' : 'false'; ?>,
            modos: <?php echo wp_json_encode(array_values($po_modos)); ?>,
            colores: <?php echo wp_json_encode(array_values($po_colores)); ?>
        };
        </script>
        <div id="po-cancel-modal" class="portal-modal-overlay">
            <div class="portal-modal" style="max-width:440px;">
                <div class="portal-modal-header">
                    <h3 style="margin:0;">Cancelar orden</h3>
                </div>
                <div class="portal-modal-body">
                    <p style="margin:0 0 12px;color:var(--text-secondary);font-size:14px;">
                        Puedes indicar un motivo (opcional). <strong>Volver</strong> cierra este cuadro sin cancelar la orden.
                    </p>
                    <label for="po-cancel-motivo">Motivo</label>
                    <textarea id="po-cancel-motivo" rows="3" style="width:100%;margin-top:6px;padding:8px;border:1px solid var(--border);border-radius:4px;" placeholder="Opcional"></textarea>
                </div>
                <div class="portal-modal-footer">
                    <button type="button" class="btn btn-secondary" id="po-cancel-back">Volver</button>
                    <button type="button" class="btn btn-primary" id="po-cancel-confirm">Cancelar orden</button>
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

(function() {
    const sidebar = document.querySelector('.portal-sidebar');
    const backdrop = document.getElementById('portal-sidebar-backdrop');
    const toggle = document.getElementById('portal-menu-toggle');
    const closeMenu = () => {
        sidebar?.classList.remove('open');
        backdrop?.classList.remove('open');
    };
    toggle?.addEventListener('click', () => {
        const open = sidebar?.classList.toggle('open');
        backdrop?.classList.toggle('open', !!open);
    });
    backdrop?.addEventListener('click', closeMenu);
})();
const canManageBarcodes = <?php echo (current_user_can('riverso_manage_products') || current_user_can('riverso_assign_barcodes')) ? 'true' : 'false'; ?>;
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
    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=riverso-pos-tasks&action=new')); ?>';
}

let portalPreviewData = null;
let portalBulkFiles = [];
const portalDefaultModo = '<?php echo esc_js($default_intake_mode); ?>';
const portalOnInvoicesPage = <?php echo $current_page === 'invoices' ? 'true' : 'false'; ?>;
let portalInvoicesPage = 1;

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
            upload_mode: 'bulk',
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

function portalTipoPendiente(factura) {
    return Number(factura && factura.tipo_confirmado) === 0;
}

function portalLoadInvoices(page) {
    const tbody = document.getElementById('portal-invoices-list');
    if (!tbody) return;
    if (page === undefined || page === null || page === '') {
        page = portalInvoicesPage;
    }
    portalInvoicesPage = Math.max(1, parseInt(page, 10) || 1);
    const body = new URLSearchParams({
        action: 'riverso_get_invoices_list',
        nonce: riversoNonce,
        page: portalInvoicesPage,
        per_page: document.getElementById('portal-invoices-per-page')?.value || 20,
        orderby: document.getElementById('portal-invoices-orderby')?.value || 'created_at',
        order: document.getElementById('portal-invoices-order')?.value || 'DESC',
        estado: document.getElementById('portal-filter-estado')?.value || '',
        proveedor_id: document.getElementById('portal-filter-proveedor')?.value || '',
        fecha_desde: document.getElementById('portal-filter-desde')?.value || '',
        fecha_hasta: document.getElementById('portal-filter-hasta')?.value || '',
        tipo_confirmado: document.getElementById('portal-filter-tipo-confirmado')?.value || '',
        search: document.getElementById('portal-filter-search')?.value || ''
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
            const totalPages = Math.max(1, parseInt(res.data.total_pages || 1, 10));
            const pageNum = parseInt(res.data.page || 1, 10);
            if (pageNum > totalPages) {
                portalLoadInvoices(totalPages);
                return;
            }
            if (!res.data.facturas.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:32px;">No hay facturas</td></tr>';
                portalRenderInvoicePagination(res.data);
                return;
            }
            tbody.innerHTML = res.data.facturas.map(f => {
                const isEnvio = f.documento_subtipo === 'envio';
                const isNc = f.documento_subtipo === 'nota_credito' || Number(f.tipo_dte) === 61;
                const isGuia = f.documento_subtipo === 'guia_despacho' || Number(f.tipo_dte) === 52;
                const isGastos = f.documento_subtipo === 'gastos';
                let tipoLabel = isNc
                    ? '<span style="color:#1d4ed8;font-weight:600;">N. Crédito</span>'
                    : (isEnvio
                        ? '<span style="color:#b45309;font-weight:600;">Flete</span>'
                        : (isGuia
                            ? '<span style="color:#0e7490;font-weight:600;">Guía</span>'
                            : (isGastos
                                ? '<span style="color:#6b21a8;font-weight:600;">Gastos</span>'
                                : '<span style="color:#15803d;">Productos</span>')));
                if (portalTipoPendiente(f)) {
                    tipoLabel = `<span class="portal-tipo-pending-badge">POR CONFIRMAR</span><br><small>${tipoLabel}</small>`;
                }
                const vinculadas = parseInt(f.facturas_vinculadas || 0, 10);
                const itemsCol = isNc
                    ? (f.estado === 'sin_vincular' ? 'Folio origen pendiente' : 'Vinculada')
                    : (isEnvio
                        ? (vinculadas > 0 ? `${vinculadas} factura(s)` : 'Sin asignar')
                        : (isGastos
                            ? 'Sin inventario'
                            : (isGuia
                                ? `${f.items_vinculados}/${f.total_items} · Solo costos`
                                : `${f.items_vinculados}/${f.total_items}` +
                                  (parseInt(f.fletes_vinculados) > 0 ? ` · ${f.fletes_vinculados} flete(s)` : ''))));
                const estadoLabel = isNc && f.estado === 'sin_vincular'
                    ? 'NC sin folio'
                    : (f.estado || '').replace(/_/g, ' ');
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
            portalRenderInvoicePagination(res.data);
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="8">Error de conexión al cargar facturas</td></tr>';
        });
}

function portalRenderInvoicePagination(data) {
    const total = parseInt(data.total || 0, 10);
    const page = parseInt(data.page || 1, 10);
    const totalPages = Math.max(1, parseInt(data.total_pages || 1, 10));
    portalInvoicesPage = page;
    const info = document.getElementById('portal-invoices-page-info');
    const prev = document.getElementById('portal-invoices-prev');
    const next = document.getElementById('portal-invoices-next');
    if (info) {
        info.textContent = total === 0
            ? 'Sin facturas'
            : `Página ${page} de ${totalPages} (${total} facturas)`;
    }
    if (prev) prev.style.display = page > 1 ? '' : 'none';
    if (next) next.style.display = (page < totalPages && total > 0) ? '' : 'none';
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
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(); }
        else alert(res.data?.message || 'Error al vincular');
    });
});

document.getElementById('portal-btn-envio-assign')?.addEventListener('click', () => {
    const targetId = document.getElementById('portal-detail-envio-target-id')?.value;
    if (!targetId) { alert('Seleccione una factura de productos'); return; }
    portalAssignShipping(targetId, portalDetailFacturaId).then(res => {
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(); }
        else alert(res.data?.message || 'Error al vincular');
    });
});

document.getElementById('portal-btn-envio-unassign-all')?.addEventListener('click', () => {
    if (!confirm('¿Desvincular este flete de TODAS las facturas de productos?')) return;
    portalUnassignShipping(portalDetailFacturaId).then(res => {
        if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(); }
        else alert(res.data?.message || 'Error');
    });
});

document.getElementById('portal-detail-modal')?.addEventListener('click', e => {
    const prodBtn = e.target.closest('.portal-btn-unassign-producto');
    if (prodBtn) {
        if (!confirm('¿Desvincular esta factura del flete?')) return;
        portalUnassignShipping(portalDetailFacturaId, prodBtn.dataset.productosId).then(res => {
            if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(); }
            else alert(res.data?.message || 'Error');
        });
        return;
    }
    const fleteBtn = e.target.closest('.portal-btn-unassign-flete');
    if (fleteBtn) {
        if (!confirm('¿Desvincular este flete?')) return;
        portalUnassignShipping(fleteBtn.dataset.envioId, portalDetailFacturaId).then(res => {
            if (res.success) { portalReloadInvoiceDetail(); portalLoadInvoices(); }
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
            portalLoadInvoices();
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

    ['portal-invoices-per-page', 'portal-invoices-orderby', 'portal-invoices-order'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => portalLoadInvoices(1));
    });
    document.getElementById('portal-invoices-prev')?.addEventListener('click', () => {
        portalLoadInvoices(portalInvoicesPage - 1);
    });
    document.getElementById('portal-invoices-next')?.addEventListener('click', () => {
        portalLoadInvoices(portalInvoicesPage + 1);
    });
    const portalSearch = document.getElementById('portal-filter-search');
    let portalSearchTimeout = null;
    portalSearch?.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            portalLoadInvoices(1);
        }
    });
    portalSearch?.addEventListener('input', () => {
        clearTimeout(portalSearchTimeout);
        portalSearchTimeout = setTimeout(() => portalLoadInvoices(1), 400);
    });

    if (portalOnInvoicesPage) {
        const invoiceParams = new URLSearchParams(window.location.search);
        const facturaDeepLink = parseInt(invoiceParams.get('factura') || '0', 10);
        portalLoadInvoices(1);
        if (facturaDeepLink) {
            setTimeout(function() { portalVerFactura(facturaDeepLink); }, 400);
        }
    }
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
                            ${s.matched_apodo ? `<div style="font-size:11px;color:#888;">Apodo: ${esc(s.matched_apodo)}</div>` : ''}
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
                if (d.success) {
                    alert('Código vinculado');
                    loadProduct(selectedId);
                    return;
                }
                if (d.data?.conflict && confirm((d.data.message || 'Conflicto de SKU') + '\n\n¿Reasignar de todas formas? El dueño anterior perderá este SKU.')) {
                    post('riverso_catalog_code_link', {base_id: baseId, proveedor_id: proveedorId, codigo, force: 1}).then(d2 => {
                        alert(d2.success ? 'Código reasignado' : (d2.data?.message || 'Error'));
                        if (d2.success) loadProduct(selectedId);
                    });
                    return;
                }
                alert(d.data?.message || 'Error');
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

// Buscador de códigos de barra / mapeo propio
(function() {
    const input = document.getElementById('barcode-input');
    const button = document.getElementById('barcode-search-btn');
    const resultDiv = document.getElementById('barcode-result');
    const statsDiv = document.getElementById('barcode-stats');
    if (!resultDiv && !document.getElementById('barcode-pending-list')) return;
    let lastBarcodeQuery = '';

    function escBarcode(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function postAction(action, extra) {
        const body = new URLSearchParams(Object.assign({ action, nonce: riversoNonce }, extra || {}));
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body
        }).then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error(text.slice(0, 180) || 'Respuesta inválida');
            }
        });
    }

    function renderStats(stats) {
        if (!statsDiv || !stats) {
            if (stats) renderStatCards(stats);
            return;
        }
        const mapping = stats.verificados != null
            ? ` · mapeo: ${stats.verificados || 0} verificados / ${stats.propuestos || 0} pendientes / ${stats.conflictos || 0} conflictos`
            : '';
        statsDiv.textContent = `${stats.productos || 0} productos locales · ${stats.barcodes || 0} códigos legacy${mapping}`;
        renderStatCards(stats);
    }

    function isUnverifiedBarcode(b, product) {
        if (!b || (b.estado || '') === 'verificado' || b.trusted) return false;
        const code = String(b.barcode || b.codigo || '');
        const sku = String((product && (product.sku_local || product.sku)) || '');
        if (sku && code && code.toLowerCase() === sku.toLowerCase()) return false;
        return true;
    }

    function barcodeRowActions(b, product) {
        if (!canManageBarcodes || !isUnverifiedBarcode(b, product)) return '';
        const id = b.id || 0;
        const code = escBarcode(b.barcode || b.codigo || '');
        const sku = escBarcode(product.sku_local || product.sku || '');
        const rowPb = escBarcode(b.producto_base_id || '');
        const pb = escBarcode(product.producto_base_id || b.producto_base_id || '');
        return `<div class="barcode-assoc-actions">
            <button type="button" class="btn btn-primary btn-sm btn-barcode-accept-one"
                data-id="${escBarcode(id)}" data-code="${code}" data-sku="${sku}" data-pb="${pb}" data-row-pb="${rowPb}">Aceptar</button>
            <button type="button" class="btn btn-secondary btn-sm btn-barcode-reject-one"
                data-id="${escBarcode(id)}" data-code="${code}" data-sku="${sku}" data-pb="${pb}">Rechazar</button>
        </div>`;
    }

    function renderBarcodeList(product) {
        const rows = (product.barcodes || []).slice();
        if (!rows.length) {
            return '<p style="color:var(--text-secondary);margin:8px 0 0;">Sin códigos asociados.</p>';
        }
        const highlight = product.arrived_by_barcode ? String(product.matched_barcode || '').trim() : '';
        if (highlight) {
            rows.sort((a, b) => {
                const am = String(a.barcode || '') === highlight ? 0 : 1;
                const bm = String(b.barcode || '') === highlight ? 0 : 1;
                return am - bm;
            });
        }
        const unverified = rows.filter(b => isUnverifiedBarcode(b, product)).length;
        const notice = unverified
            ? `<p style="margin:8px 0 0;font-size:13px;color:#ef6c00;">Este producto tiene ${unverified} código${unverified === 1 ? '' : 's'} no verificado${unverified === 1 ? '' : 's'}. Acepta o rechaza cada uno por separado.</p>`
            : '';
        const items = rows.map(b => {
            const unverifiedRow = isUnverifiedBarcode(b, product);
            const matched = !!(highlight && String(b.barcode) === highlight);
            const badge = !unverifiedRow
                ? '<span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:11px;">Verificado</span>'
                : (b.conflicto
                    ? '<span style="background:#ffebee;color:#c62828;padding:2px 8px;border-radius:10px;font-size:11px;">Conflicto</span>'
                    : '<span style="background:#fff8e1;color:#ef6c00;padding:2px 8px;border-radius:10px;font-size:11px;">No verificado</span>');
            const rowClass = [
                'barcode-assoc-row',
                unverifiedRow ? 'is-unverified' : 'is-verified',
                matched ? 'is-matched' : ''
            ].filter(Boolean).join(' ');
            return `<li class="${rowClass}">
                <div class="barcode-assoc-meta">
                    <code>${escBarcode(b.barcode)}</code>
                    ${badge}
                    ${matched ? '<span class="barcode-matched-flag">Código buscado</span>' : ''}
                    ${b.fecha ? `<small>(${escBarcode(b.fecha)})</small>` : ''}
                </div>
                ${barcodeRowActions(b, product)}
            </li>`;
        }).join('');
        return `${notice}<ul class="barcode-assoc-list">${items}</ul>`;
    }

    function mappingButtons(product, opts) {
        opts = opts || {};
        const arrivedByBarcode = !!(opts.arrivedByBarcode || product.arrived_by_barcode);
        if (!canManageBarcodes || !arrivedByBarcode || product.trusted) return '';
        const pending = product.pending_sku ? escBarcode(product.pending_sku) : '';
        const code = escBarcode(product.matched_barcode || '');
        const sku = escBarcode(product.sku_local || product.sku || '');
        const id = product.codigo_id || 0;
        if (!id && !product.has_unverified) {
            return `<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">
                <button type="button" class="btn btn-primary btn-sm btn-barcode-upsert" data-code="${code}" data-sku="${sku}" data-pb="${escBarcode(product.producto_base_id || '')}">Agregar al mapeo y aprobar</button>
                <button type="button" class="btn btn-secondary btn-sm btn-barcode-ignore">Ignorar</button>
            </div>`;
        }
        return `<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <button type="button" class="btn btn-secondary btn-sm btn-barcode-ignore">Ignorar</button>
            <input type="text" class="barcode-reassign-q" data-id="${escBarcode(id)}" data-code="${code}" placeholder="Reasignar a SKU..." style="padding:6px 8px;border:1px solid var(--border);border-radius:4px;min-width:160px;">
            ${pending ? `<button type="button" class="btn btn-secondary btn-sm btn-barcode-pending" data-id="${escBarcode(id)}" data-code="${code}" data-sku="${pending}">Esperar ${pending}</button>` : ''}
            <div class="barcode-reassign-results" data-id="${escBarcode(id)}"></div>
        </div>`;
    }

    function renderProduct(product, opts) {
        opts = opts || {};
        const arrivedByBarcode = !!(opts.arrivedByBarcode || product.arrived_by_barcode);
        if (arrivedByBarcode && !product.matched_barcode && opts.query) {
            product.matched_barcode = opts.query;
            product.arrived_by_barcode = true;
        }
        const matched = arrivedByBarcode && product.matched_barcode
            ? `<p style="margin:6px 0;"><strong>Código:</strong> <code>${escBarcode(product.matched_barcode)}</code></p>`
            : '';
        const stockColor = Number(product.stock || 0) > 0 ? 'var(--success)' : 'var(--danger)';
        const trusted = !!product.trusted;
        const hasUnverified = (product.barcodes || []).some(b => isUnverifiedBarcode(b, product));
        const border = opts.conflict ? 'var(--danger)' : (hasUnverified ? 'var(--warning)' : (trusted ? 'var(--success)' : 'var(--border)'));
        const badge = opts.conflict
            ? '<span style="background:#ffebee;color:#c62828;padding:2px 8px;border-radius:10px;font-size:11px;">Conflicto</span>'
            : (arrivedByBarcode && trusted
                ? '<span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:11px;">Verificado</span>'
                : (arrivedByBarcode && hasUnverified
                    ? '<span style="background:#fff8e1;color:#ef6c00;padding:2px 8px;border-radius:10px;font-size:11px;">Tiene códigos no verificados</span>'
                    : (hasUnverified
                        ? '<span style="background:#fff8e1;color:#ef6c00;padding:2px 8px;border-radius:10px;font-size:11px;">Tiene códigos no verificados</span>'
                        : '')));
        const extra = [
            product.sku_local ? `SKU local legacy: <code>${escBarcode(product.sku_local)}</code>` : '',
            product.pending_sku ? `SKU pendiente: <code>${escBarcode(product.pending_sku)}</code>` : '',
            product.tipo_envase ? `Envase: ${escBarcode(product.tipo_envase)} × ${escBarcode(product.cantidad || 1)}` : (product.cantidad ? `Cantidad: ${escBarcode(product.cantidad)}` : ''),
            product.origen ? `Origen: ${escBarcode(product.origen)}` : '',
            product.advertencia ? escBarcode(product.advertencia) : '',
        ].filter(Boolean).join('<br>');
        const barcodeCount = (product.barcodes || []).length;

        return `
            <div style="background:#fff;border:1px solid ${border};border-radius:8px;padding:18px;margin-bottom:12px;text-align:left;">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                    <h3 style="margin:0 0 8px;">${escBarcode(product.nombre)}</h3>
                    ${badge}
                </div>
                ${matched}
                <p style="margin:8px 0;">
                    <strong>SKU:</strong> <code>${escBarcode(product.sku)}</code><br>
                    ${product.precio_formateado ? `<strong>Precio:</strong> ${escBarcode(product.precio_formateado)}<br>` : ''}
                    <strong>Stock:</strong> <span style="color:${stockColor};font-weight:600;">${escBarcode(product.stock)}</span>
                </p>
                ${extra ? `<p style="font-size:12px;color:var(--text-secondary);">${extra}</p>` : ''}
                <details ${(hasUnverified || barcodeCount <= 6) ? 'open' : ''}>
                    <summary>Códigos asociados (${barcodeCount})</summary>
                    ${renderBarcodeList(product)}
                </details>
                ${mappingButtons(product, { arrivedByBarcode })}
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <button type="button" class="btn-print-label" data-sku="${escBarcode(product.sku)}" data-nombre="${escBarcode(product.nombre)}" data-precio="${product.precio || 0}" style="
                        padding:8px 16px;background:#4CAF50;color:white;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:500;">
                        🖨️ Imprimir
                    </button>
                </div>
            </div>
        `;
    }

    function searchBarcode(forceQuery) {
        const query = (forceQuery != null ? forceQuery : input.value).trim();
        if (!query) {
            resultDiv.innerHTML = '<div style="color:var(--warning);text-align:center;">Ingresa un código, SKU o nombre.</div>';
            return;
        }
        lastBarcodeQuery = query;

        resultDiv.innerHTML = '<div class="loading" style="text-align:center;">Buscando...</div>';
        postAction('riverso_tienda_local_search', { query })
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
            const type = data.data.type;
            let intro = '';
            if (type === 'conflict') {
                intro = '<div style="background:#ffebee;border:1px solid #ef9a9a;border-radius:8px;padding:12px;margin-bottom:12px;text-align:center;"><strong>Conflicto.</strong> Este código puede apuntar a SKUs distintos. Aprueba el correcto, rechaza el resto, o ignora.</div>';
            } else if (type === 'suggestion') {
                intro = '<div style="background:#fff8e1;border:1px solid #ffcc80;border-radius:8px;padding:12px;margin-bottom:12px;text-align:center;"><strong>Sugerencia legacy (no verificada).</strong> ¿Resolver ahora o ignorar?</div>';
            } else if (type === 'name' && items.length > 1) {
                intro = `<p style="color:var(--text-secondary);text-align:center;margin-bottom:12px;">Se encontraron ${items.length} coincidencias por nombre.</p>`;
            }
            resultDiv.innerHTML = intro + (items.length ? items.map(p => renderProduct(p, {
                conflict: type === 'conflict',
                arrivedByBarcode: type === 'barcode' || type === 'suggestion' || type === 'conflict' || !!p.arrived_by_barcode,
                query
            })).join('') : '<p style="text-align:center;">Sin resultados.</p>');
            if (type === 'barcode') {
                input.value = '';
            }
        })
        .catch(() => {
            resultDiv.innerHTML = '<div style="color:var(--danger);text-align:center;">Error buscando producto local.</div>';
        });
    }

    function loadTabData(tab) {
        try {
            if (tab === 'pending') loadPending();
            else if (tab === 'conflicts') loadConflicts();
            else if (tab === 'sku') loadSkuList();
            else if (tab === 'tipos') loadTipos();
            else if (tab === 'products') loadProductList();
        } catch (err) {
            const target = document.getElementById('barcode-tab-' + tab);
            if (target) {
                const box = target.querySelector('[id$="-list"]') || target;
                box.innerHTML = '<p style="color:var(--danger);">Error al abrir la pestaña. Recarga la página.</p>';
            }
            console.error(err);
        }
    }
    function switchTab(tab) {
        window.riversoSwitchBarcodeTab(tab);
    }

    let skuDetailById = {};
    let envaseTipos = [];

    function renderStatCards(stats) {
        const box = document.getElementById('barcode-stat-cards');
        if (!box || !stats) return;
        const cards = [
            ['Verificados', stats.verificados || 0, 'green'],
            ['Pendientes', stats.propuestos || 0, 'orange'],
            ['Conflictos', stats.conflictos || 0, 'purple'],
            ['Sin producto', stats.pendiente_sku || 0, 'blue'],
        ];
        box.innerHTML = cards.map(([label, value, color]) =>
            `<div class="stat-card"><div class="stat-icon ${color}"><span class="dashicons dashicons-tag"></span></div><div class="stat-value">${escBarcode(value)}</div><div class="stat-label">${label}</div></div>`
        ).join('');
    }

    function fillTipoSelect(selected) {
        const sel = document.getElementById('bc-edit-tipo');
        if (!sel) return;
        sel.innerHTML = envaseTipos.filter(t => Number(t.activo) === 1).map(t =>
            `<option value="${escBarcode(t.slug)}" ${t.slug === selected ? 'selected' : ''}>${escBarcode(t.nombre)}</option>`
        ).join('');
    }

    function loadTiposCatalog(cb) {
        postAction('riverso_envase_tipos_list').then(data => {
            envaseTipos = data.success ? (data.data.items || []) : [];
            fillTipoSelect();
            if (cb) cb(envaseTipos);
        });
    }

    function loadSkuList() {
        const target = document.getElementById('barcode-sku-list');
        if (!target) return;
        target.innerHTML = '<div class="loading">Cargando mapeo interno...</div>';
        postAction('riverso_barcode_list_by_sku', {
            search: document.getElementById('barcode-sku-search')?.value || '',
            page: 1
        }).then(data => {
            if (!data.success) {
                target.innerHTML = `<p style="color:var(--danger);">${escBarcode(data.data?.message || 'Error')}</p>`;
                return;
            }
            const items = data.data.items || [];
            if (!items.length) {
                target.innerHTML = '<p style="text-align:center;color:var(--text-secondary);">Sin códigos en mapeo interno. Revisa Pendientes o agrega uno.</p>';
                return;
            }
            target.innerHTML = `<table class="portal-table"><thead><tr>
                <th>SKU</th><th>Producto</th><th>Códigos</th><th>Verif.</th><th>Prop.</th><th>Conf.</th><th></th>
            </tr></thead><tbody>${items.map(row => `<tr>
                <td><code>${escBarcode(row.sku_key)}</code></td>
                <td>${escBarcode(row.nombre || '')}</td>
                <td>${escBarcode(row.barcodes)}</td>
                <td>${escBarcode(row.verificados)}</td>
                <td>${escBarcode(row.propuestos)}</td>
                <td>${escBarcode(row.conflictos)}</td>
                <td><button type="button" class="btn btn-secondary btn-sm btn-sku-open" data-sku="${escBarcode(row.sku_key)}">Ver</button></td>
            </tr>`).join('')}</tbody></table>`;
        }).catch(() => {
            target.innerHTML = '<p style="color:var(--danger);">No se pudo cargar el mapeo interno.</p>';
        });
    }

    function loadSkuDetail(sku, opts) {
        opts = opts || {};
        const box = document.getElementById(opts.target || 'barcode-sku-detail');
        if (!box) return;
        box.innerHTML = '<div class="loading">Cargando detalle...</div>';
        const payload = {};
        if (opts.producto_base_id) payload.producto_base_id = opts.producto_base_id;
        if (sku) payload.sku = sku;
        postAction('riverso_barcode_get_sku_detail', payload).then(data => {
            if (!data.success) {
                box.innerHTML = `<p style="color:var(--danger);">${escBarcode(data.data?.message || 'Error')}</p>`;
                return;
            }
            skuDetailById = {};
            (data.data.items || []).forEach(it => { skuDetailById[it.id] = it; });
            const title = opts.nombre ? `${escBarcode(opts.nombre)} <code>${escBarcode(sku || '')}</code>` : `<code>${escBarcode(sku || '')}</code>`;
            const addBtn = (canManageBarcodes && (opts.producto_base_id || sku))
                ? `<button type="button" class="btn btn-primary btn-sm btn-product-add" data-pb="${escBarcode(opts.producto_base_id || '')}" data-sku="${escBarcode(sku || '')}" data-nombre="${escBarcode(opts.nombre || '')}">Agregar código de barra</button>`
                : '';
            const rows = (data.data.items || []).map(it => {
                const envase = it.tipo_envase ? `${it.tipo_envase} × ${it.envase_cantidad || it.cantidad || 1}` : '—';
                return `<tr>
                    <td><code>${escBarcode(it.codigo)}</code></td>
                    <td>${escBarcode(it.estado)}${it.conflicto ? ' / conflicto' : ''}</td>
                    <td>${escBarcode(envase)}</td>
                    <td>${escBarcode(it.cantidad || 1)}</td>
                    <td>${escBarcode(it.updated_at || '')}</td>
                    <td>
                        ${it.estado !== 'verificado' && it.id ? `<button type="button" class="btn btn-primary btn-sm btn-barcode-approve" data-id="${it.id}">Aceptar</button> <button type="button" class="btn btn-secondary btn-sm btn-barcode-reject" data-id="${it.id}">Rechazar</button>` : ''}
                        <button type="button" class="btn btn-secondary btn-sm btn-map-edit" data-id="${it.id}">Editar</button>
                    </td>
                </tr>`;
            }).join('');
            box.innerHTML = `<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin:16px 0 8px;flex-wrap:wrap;">
                    <h3 style="margin:0;">Códigos de ${title}</h3>
                    ${addBtn}
                </div>
                <table class="portal-table"><thead><tr>
                    <th>Código</th><th>Estado</th><th>Envase</th><th>Cant.</th><th>Modificado</th><th></th>
                </tr></thead><tbody>${rows || '<tr><td colspan="6">Este producto aún no tiene códigos. Agrega el primero.</td></tr>'}</tbody></table>`;
        });
    }

    let productPage = 1;
    let currentProduct = null;

    function loadProductList(page) {
        if (page) productPage = page;
        const target = document.getElementById('barcode-product-list');
        const pager = document.getElementById('barcode-product-pager');
        if (!target) return;
        target.innerHTML = '<div class="loading">Cargando productos...</div>';
        postAction('riverso_barcode_list_products', {
            search: document.getElementById('barcode-product-search')?.value || '',
            filter: document.getElementById('barcode-product-filter')?.value || 'all',
            page: productPage,
            per_page: 40
        }).then(data => {
            if (!data.success) {
                target.innerHTML = `<p style="color:var(--danger);">${escBarcode(data.data?.message || 'Error')}</p>`;
                return;
            }
            const items = data.data.items || [];
            const pages = data.data.pages || 1;
            productPage = data.data.page || 1;
            if (!items.length) {
                target.innerHTML = '<p style="text-align:center;color:var(--text-secondary);">No hay productos. Prueba otro filtro o limpia la búsqueda.</p>';
                if (pager) pager.innerHTML = '';
                return;
            }
            target.innerHTML = `<p style="color:var(--text-secondary);margin-bottom:8px;">${escBarcode(data.data.total)} productos locales</p>
                <table class="portal-table"><thead><tr>
                    <th>SKU local</th><th>Producto</th><th>Códigos actuales</th><th>Asignar código de barra</th>
                </tr></thead><tbody>${items.map(row => {
                    const sample = row.barcode_sample ? escBarcode(row.barcode_sample) : 'Sin código';
                    const assign = canManageBarcodes ? `<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <input type="text" class="barcode-inline-code" placeholder="Escanear o pegar código" data-pb="${row.id}" data-sku="${escBarcode(row.canonical_sku)}" data-nombre="${escBarcode(row.nombre_canonico || '')}" style="min-width:160px;flex:1;padding:8px;border:1px solid var(--border);border-radius:6px;">
                        <button type="button" class="btn btn-primary btn-sm btn-product-inline-assign" data-pb="${row.id}" data-sku="${escBarcode(row.canonical_sku)}" data-nombre="${escBarcode(row.nombre_canonico || '')}">Asignar</button>
                    </div>` : '';
                    return `<tr class="barcode-product-row" data-id="${row.id}" data-sku="${escBarcode(row.canonical_sku)}" data-nombre="${escBarcode(row.nombre_canonico || '')}">
                    <td><code>${escBarcode(row.canonical_sku || '—')}</code></td>
                    <td>${escBarcode(row.nombre_canonico || '')}</td>
                    <td><span>${escBarcode(row.barcodes || 0)}</span>${row.barcode_sample ? `<div style="font-size:12px;color:var(--text-secondary);max-width:240px;overflow:hidden;text-overflow:ellipsis;">${sample}</div>` : '<div style="font-size:12px;color:var(--warning);">Sin código</div>'}</td>
                    <td>
                        ${assign}
                        <button type="button" class="btn btn-secondary btn-sm btn-product-open" data-id="${row.id}" data-sku="${escBarcode(row.canonical_sku)}" data-nombre="${escBarcode(row.nombre_canonico || '')}" style="margin-top:6px;">Ver códigos</button>
                    </td>
                </tr>`;
                }).join('')}</tbody></table>`;
            if (pager) {
                pager.innerHTML = `
                    <button type="button" class="btn btn-secondary btn-sm" id="barcode-product-prev" ${productPage <= 1 ? 'disabled' : ''}>Anterior</button>
                    <span>Página ${productPage} / ${pages}</span>
                    <button type="button" class="btn btn-secondary btn-sm" id="barcode-product-next" ${productPage >= pages ? 'disabled' : ''}>Siguiente</button>`;
            }
        }).catch(() => {
            target.innerHTML = '<p style="color:var(--danger);">No se pudo cargar productos.</p>';
        });
    }

    function openProduct(row) {
        currentProduct = row;
        loadSkuDetail(row.sku, {
            producto_base_id: row.id,
            nombre: row.nombre,
            target: 'barcode-product-detail'
        });
        document.getElementById('barcode-product-detail')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function loadTipos() {
        const target = document.getElementById('barcode-tipos-list');
        if (!target) return;
        loadTiposCatalog(items => {
            if (!items.length) {
                target.innerHTML = '<p>Sin tipos. Se crean Envase, Caja y Balde en la migración.</p>';
                return;
            }
            target.innerHTML = `<table class="portal-table"><thead><tr><th>Slug</th><th>Nombre</th><th>Activo</th><th></th></tr></thead><tbody>${
                items.map(t => `<tr>
                    <td><code>${escBarcode(t.slug)}</code></td>
                    <td>${escBarcode(t.nombre)}</td>
                    <td>${Number(t.activo) === 1 ? 'Sí' : 'No'}</td>
                    <td><button type="button" class="btn btn-secondary btn-sm btn-tipo-toggle" data-id="${t.id}" data-activo="${Number(t.activo) === 1 ? 0 : 1}">${Number(t.activo) === 1 ? 'Desactivar' : 'Activar'}</button></td>
                </tr>`).join('')
            }</tbody></table>`;
        });
    }

    function openEdit(item) {
        item = item || {};
        document.getElementById('bc-edit-id').value = item.id || '';
        const pbField = document.getElementById('bc-edit-pb');
        if (pbField) pbField.value = item.producto_base_id || '';
        document.getElementById('bc-edit-codigo').value = item.codigo || item.matched_barcode || '';
        document.getElementById('bc-edit-sku').value = item.sku_local || item.pending_sku || item.sku || '';
        document.getElementById('bc-edit-cantidad').value = item.cantidad || 1;
        document.getElementById('bc-edit-estado').value = item.estado === 'verificado' ? 'verificado' : 'propuesto';
        document.getElementById('bc-edit-create-envase').checked = false;
        loadTiposCatalog(() => fillTipoSelect(item.tipo_envase));
        document.getElementById('barcode-edit-modal')?.classList.add('open');
    }

    function closeEdit() {
        document.getElementById('barcode-edit-modal')?.classList.remove('open');
    }

    function renderGroupList(target, groups, emptyText) {
        if (!target) return;
        if (!groups || !groups.length) {
            target.innerHTML = `<p style="text-align:center;color:var(--text-secondary);">${escBarcode(emptyText)}</p>`;
            return;
        }
        target.innerHTML = groups.map(g => {
            const items = (g.items || []).map(it => renderProduct({
                sku: it.canonical_sku || it.sku_local || it.pending_sku || '',
                nombre: it.nombre_canonico || it.canonical_sku || 'Sin producto',
                precio: 0,
                stock: '',
                matched_barcode: g.codigo,
                barcodes: [],
                trusted: it.estado === 'verificado',
                codigo_id: it.id,
                producto_base_id: it.producto_base_id,
                pending_sku: it.pending_sku,
                sku_local: it.sku_local,
                origen: it.origen,
                advertencia: it.motivo,
                cantidad: it.cantidad,
                tipo_envase: it.tipo_envase,
            }, { conflict: !!g.conflicto })).join('');
            return `<div style="margin-bottom:18px;"><h4 style="margin:0 0 8px;font-family:monospace;">${escBarcode(g.codigo)}</h4>${items}</div>`;
        }).join('');
    }

    function loadPending() {
        const target = document.getElementById('barcode-pending-list');
        if (!target) return;
        target.innerHTML = '<div class="loading">Cargando pendientes...</div>';
        postAction('riverso_barcode_list_pending').then(data => {
            if (!data.success) {
                target.innerHTML = `<p style="color:var(--danger);">${escBarcode(data.data?.message || 'Error')}</p>`;
                return;
            }
            renderGroupList(target, data.data.items, 'No hay códigos pendientes.');
        }).catch(() => {
            target.innerHTML = '<p style="color:var(--danger);">No se pudo cargar pendientes. Recarga la página.</p>';
        });
    }

    function loadConflicts() {
        const target = document.getElementById('barcode-conflict-list');
        if (!target) return;
        target.innerHTML = '<div class="loading">Cargando conflictos...</div>';
        postAction('riverso_barcode_list_conflicts').then(data => {
            if (!data.success) {
                target.innerHTML = `<p style="color:var(--danger);">${escBarcode(data.data?.message || 'Error')}</p>`;
                return;
            }
            const shared = (data.data.shared_sku || []).map(s =>
                `<li><code>${escBarcode(s.sku_local)}</code> → ${escBarcode(s.barcodes)} códigos: ${escBarcode(s.codigos)}</li>`
            ).join('');
            const sharedBlock = shared
                ? `<div style="background:#fff8e1;border:1px solid #ffcc80;border-radius:8px;padding:12px;margin-bottom:16px;">
                    <strong>SKU local compartido por varios códigos</strong> (revisar, p.ej. 145 → 45ATPF vs 45ATPF-G)
                    <ul style="margin:8px 0 0 18px;">${shared}</ul>
                   </div>`
                : '';
            target.innerHTML = sharedBlock + '<div id="barcode-conflict-groups"></div>';
            renderGroupList(document.getElementById('barcode-conflict-groups'), data.data.items, 'No hay conflictos de producto divergente.');
        }).catch(() => {
            target.innerHTML = '<p style="color:var(--danger);">No se pudo cargar conflictos. Recarga la página.</p>';
        });
    }

    function loadMappingStats() {
        postAction('riverso_barcode_mapping_stats').then(data => {
            if (data.success) renderStats(Object.assign({}, data.data));
        });
        postAction('riverso_tienda_local_search', { query: '__stats__' }).catch(() => {});
    }

    document.addEventListener('riverso-barcode-tab', function(e) {
        if (e.detail) loadTabData(e.detail);
    });
    button?.addEventListener('click', searchBarcode);
    input?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchBarcode();
        }
    });

    function refreshAfterMapping() {
        if (lastBarcodeQuery) searchBarcode(lastBarcodeQuery);
        loadPending();
        loadConflicts();
        if (currentProduct) {
            loadSkuDetail(currentProduct.sku, {
                producto_base_id: currentProduct.id,
                nombre: currentProduct.nombre,
                target: 'barcode-product-detail'
            });
        }
        loadMappingStats();
    }

    function acceptOneBarcode(btn) {
        const id = parseInt(btn.dataset.id || '0', 10) || 0;
        const code = btn.dataset.code || '';
        const sku = btn.dataset.sku || '';
        const pb = btn.dataset.pb || '';
        const rowPb = btn.dataset.rowPb || '';
        let req;
        if (id && rowPb) {
            req = postAction('riverso_barcode_approve', { codigo_id: id });
        } else if (id && pb) {
            req = postAction('riverso_barcode_assign', { codigo_id: id, codigo: code, producto_base_id: pb, verify: '1' });
        } else {
            req = postAction('riverso_barcode_upsert', {
                codigo: code,
                sku_local: sku,
                producto_base_id: pb,
                estado: pb ? 'verificado' : 'propuesto',
                cantidad: 1,
                create_envase: 0
            });
        }
        btn.disabled = true;
        req.then(data => {
            if (!data.success) {
                alert(data.data?.message || 'No se pudo aceptar este código');
                btn.disabled = false;
                return;
            }
            refreshAfterMapping();
        }).catch(() => {
            alert('No se pudo aceptar este código');
            btn.disabled = false;
        });
    }

    function rejectOneBarcode(btn) {
        const id = parseInt(btn.dataset.id || '0', 10) || 0;
        const motivo = prompt('Motivo de rechazo (opcional):') || 'Rechazado desde portal.';
        const req = id
            ? postAction('riverso_barcode_reject', { codigo_id: id, motivo })
            : postAction('riverso_barcode_upsert', {
                codigo: btn.dataset.code || '',
                sku_local: btn.dataset.sku || '',
                producto_base_id: btn.dataset.pb || '',
                estado: 'rechazado',
                motivo,
                cantidad: 1,
                create_envase: 0
            });
        btn.disabled = true;
        req.then(data => {
            if (!data.success) {
                alert(data.data?.message || 'No se pudo rechazar este código');
                btn.disabled = false;
                return;
            }
            refreshAfterMapping();
        }).catch(() => {
            alert('No se pudo rechazar este código');
            btn.disabled = false;
        });
    }

    document.addEventListener('click', function(e) {
        const acceptOne = e.target.closest('.btn-barcode-accept-one');
        if (acceptOne) {
            acceptOneBarcode(acceptOne);
            return;
        }
        const rejectOne = e.target.closest('.btn-barcode-reject-one');
        if (rejectOne) {
            rejectOneBarcode(rejectOne);
            return;
        }
        const approve = e.target.closest('.btn-barcode-approve');
        if (approve) {
            postAction('riverso_barcode_approve', { codigo_id: approve.dataset.id }).then(data => {
                alert(data.data?.message || (data.success ? 'OK' : 'Error'));
                refreshAfterMapping();
            });
            return;
        }
        const reject = e.target.closest('.btn-barcode-reject');
        if (reject) {
            const motivo = prompt('Motivo de rechazo (opcional):') || 'Rechazado desde portal.';
            postAction('riverso_barcode_reject', { codigo_id: reject.dataset.id, motivo }).then(data => {
                alert(data.data?.message || (data.success ? 'OK' : 'Error'));
                refreshAfterMapping();
            });
            return;
        }
        const waitSku = e.target.closest('.btn-barcode-pending');
        if (waitSku) {
            postAction('riverso_barcode_assign', {
                codigo_id: waitSku.dataset.id,
                codigo: waitSku.dataset.code,
                pending_sku: waitSku.dataset.sku
            }).then(data => {
                alert(data.data?.message || (data.success ? 'OK' : 'Error'));
                loadPending();
                loadConflicts();
            });
            return;
        }
        const pick = e.target.closest('.btn-barcode-pick');
        if (pick) {
            postAction('riverso_barcode_assign', {
                codigo_id: pick.dataset.id,
                codigo: pick.dataset.code,
                producto_base_id: pick.dataset.pid,
                verify: '1'
            }).then(data => {
                alert(data.data?.message || (data.success ? 'OK' : 'Error'));
                if (input?.value) searchBarcode();
                loadPending();
                loadConflicts();
            });
            return;
        }

        const ignore = e.target.closest('.btn-barcode-ignore');
        if (ignore) {
            if (resultDiv) resultDiv.innerHTML = '<p style="text-align:center;color:var(--text-secondary);">Ignorado. El código no se verificó.</p>';
            return;
        }
        const upsert = e.target.closest('.btn-barcode-upsert');
        if (upsert) {
            postAction('riverso_barcode_upsert', {
                codigo: upsert.dataset.code,
                sku_local: upsert.dataset.sku,
                producto_base_id: upsert.dataset.pb || '',
                estado: upsert.dataset.pb ? 'verificado' : 'propuesto',
                cantidad: 1,
                create_envase: 0
            }).then(data => {
                alert(data.data?.message || (data.success ? 'OK' : 'Error'));
                if (input?.value) searchBarcode();
                loadPending();
                loadSkuList();
            });
            return;
        }
        const skuOpen = e.target.closest('.btn-sku-open');
        if (skuOpen) {
            loadSkuDetail(skuOpen.dataset.sku);
            return;
        }
        const productOpen = e.target.closest('.btn-product-open');
        if (productOpen) {
            openProduct({
                id: productOpen.dataset.id,
                sku: productOpen.dataset.sku,
                nombre: productOpen.dataset.nombre
            });
            return;
        }
        const productAdd = e.target.closest('.btn-product-add');
        if (productAdd) {
            currentProduct = {
                id: productAdd.dataset.pb,
                sku: productAdd.dataset.sku,
                nombre: productAdd.dataset.nombre
            };
            openEdit({
                producto_base_id: productAdd.dataset.pb,
                sku_local: productAdd.dataset.sku,
                sku: productAdd.dataset.sku,
                estado: 'verificado',
                cantidad: 1
            });
            return;
        }
        const inlineAssign = e.target.closest('.btn-product-inline-assign');
        if (inlineAssign) {
            const input = inlineAssign.closest('td')?.querySelector('.barcode-inline-code');
            const codigo = (input?.value || '').trim();
            if (!codigo) {
                alert('Escribe o escanea el código de barra.');
                input?.focus();
                return;
            }
            postAction('riverso_barcode_upsert', {
                codigo,
                sku_local: inlineAssign.dataset.sku,
                producto_base_id: inlineAssign.dataset.pb,
                estado: 'verificado',
                cantidad: 1,
                tipo_envase: 'envase',
                create_envase: 1
            }).then(data => {
                alert(data.data?.message || (data.success ? 'Código asignado' : 'Error'));
                if (data.success) {
                    if (input) input.value = '';
                    loadProductList();
                    openProduct({
                        id: inlineAssign.dataset.pb,
                        sku: inlineAssign.dataset.sku,
                        nombre: inlineAssign.dataset.nombre
                    });
                }
            }).catch(() => alert('No se pudo asignar el código.'));
            return;
        }
        const productRow = e.target.closest('.barcode-product-row');
        if (productRow && !e.target.closest('button') && !e.target.closest('input')) {
            openProduct({
                id: productRow.dataset.id,
                sku: productRow.dataset.sku,
                nombre: productRow.dataset.nombre
            });
            return;
        }
        const mapEdit = e.target.closest('.btn-map-edit');
        if (mapEdit) {
            openEdit(skuDetailById[mapEdit.dataset.id] || { id: mapEdit.dataset.id });
            return;
        }
        const tipoToggle = e.target.closest('.btn-tipo-toggle');
        if (tipoToggle) {
            postAction('riverso_envase_tipo_toggle', { id: tipoToggle.dataset.id, activo: tipoToggle.dataset.activo }).then(loadTipos);
            return;
        }

        const printBtn = e.target.closest('.btn-print-label');
        if (!printBtn) return;
        const sku = printBtn.dataset.sku;
        const nombre = printBtn.dataset.nombre;
        const precio = parseInt(printBtn.dataset.precio) || null;
        if (typeof RiversoLabelPrint !== 'undefined') {
            RiversoLabelPrint.showPrintDialog({
                sku, nombre, precio, cantidad: 100, copias: 1, modo: 'BolsaCOD', color: 'BN'
            });
        } else {
            alert('El módulo de impresión no está cargado. Recarga la página o contacta soporte.');
        }
    });

    document.addEventListener('input', function(e) {
        const q = e.target.closest('.barcode-reassign-q');
        if (!q) return;
        const box = document.querySelector('.barcode-reassign-results[data-id="' + q.dataset.id + '"]');
        if (!box) return;
        const term = q.value.trim();
        if (term.length < 2) {
            box.innerHTML = '';
            return;
        }
        postAction('riverso_barcode_search_products', { query: term }).then(data => {
            if (!data.success) return;
            box.innerHTML = (data.data.items || []).map(p =>
                `<button type="button" class="btn btn-secondary btn-sm btn-barcode-pick" data-id="${escBarcode(q.dataset.id)}" data-code="${escBarcode(q.dataset.code)}" data-pid="${escBarcode(p.id)}">${escBarcode(p.canonical_sku)} — ${escBarcode(p.nombre_canonico)}</button>`
            ).join(' ');
        });
    });

    if (statsDiv) {
        postAction('riverso_barcode_mapping_stats').then(data => {
            if (data.success) renderStats(data.data);
        });
    }
    document.getElementById('barcode-sku-search-btn')?.addEventListener('click', loadSkuList);
    document.getElementById('barcode-sku-search')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); loadSkuList(); }
    });
    document.getElementById('barcode-product-search-btn')?.addEventListener('click', () => loadProductList(1));
    document.getElementById('barcode-product-search')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); loadProductList(1); }
    });
    document.getElementById('barcode-product-filter')?.addEventListener('change', () => loadProductList(1));
    document.getElementById('barcode-product-list')?.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        const input = e.target.closest('.barcode-inline-code');
        if (!input) return;
        e.preventDefault();
        input.closest('td')?.querySelector('.btn-product-inline-assign')?.click();
    });
    document.getElementById('barcode-product-pager')?.addEventListener('click', function(e) {
        if (e.target.id === 'barcode-product-prev') loadProductList(productPage - 1);
        if (e.target.id === 'barcode-product-next') loadProductList(productPage + 1);
    });
    document.getElementById('barcode-add-open')?.addEventListener('click', () => openEdit({}));
    document.getElementById('barcode-edit-close')?.addEventListener('click', closeEdit);
    document.getElementById('barcode-edit-cancel')?.addEventListener('click', closeEdit);
    document.getElementById('barcode-edit-save')?.addEventListener('click', function() {
        postAction('riverso_barcode_upsert', {
            codigo_id: document.getElementById('bc-edit-id').value,
            codigo: document.getElementById('bc-edit-codigo').value,
            sku_local: document.getElementById('bc-edit-sku').value,
            producto_base_id: document.getElementById('bc-edit-pb')?.value || '',
            tipo_envase: document.getElementById('bc-edit-tipo').value,
            cantidad: document.getElementById('bc-edit-cantidad').value,
            estado: document.getElementById('bc-edit-estado').value,
            create_envase: document.getElementById('bc-edit-create-envase').checked ? 1 : 0
        }).then(data => {
            alert(data.data?.message || (data.success ? 'Guardado' : 'Error'));
            if (data.success) {
                closeEdit();
                loadSkuList();
                loadProductList();
                if (currentProduct) {
                    openProduct(currentProduct);
                }
                loadMappingStats();
            }
        });
    });
    document.getElementById('barcode-tipo-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        postAction('riverso_envase_tipo_create', {
            nombre: document.getElementById('barcode-tipo-nombre').value,
            slug: document.getElementById('barcode-tipo-slug').value
        }).then(data => {
            if (!data.success) {
                alert(data.data?.message || 'Error');
                return;
            }
            document.getElementById('barcode-tipo-nombre').value = '';
            document.getElementById('barcode-tipo-slug').value = '';
            loadTipos();
        });
    });
    if (document.getElementById('barcode-tab-products')) {
        const activeBtn = document.querySelector('#barcode-tabs .barcode-tab.btn-primary');
        loadTabData(activeBtn ? activeBtn.getAttribute('data-tab') : 'products');
    }
})();

(function() {
    const body = document.getElementById('po-portal-body');
    if (!body || typeof riversoPrintOrders === 'undefined') return;
    const cfg = riversoPrintOrders;
    const modos = cfg.modos || ['BolsaCOD'];
    const colores = cfg.colores || ['BN', 'RN'];
    let page = 1;
    let current = null;
    let items = [];
    let cancelCallback = null;
    const cancelModal = document.getElementById('po-cancel-modal');
    const cancelMotivo = document.getElementById('po-cancel-motivo');

    function closeCancelModal() {
        cancelCallback = null;
        cancelModal?.classList.remove('open');
    }

    function askCancelMotivo(onConfirm) {
        cancelCallback = onConfirm;
        if (cancelMotivo) cancelMotivo.value = '';
        cancelModal?.classList.add('open');
        setTimeout(() => cancelMotivo?.focus(), 50);
    }

    document.getElementById('po-cancel-back')?.addEventListener('click', closeCancelModal);
    cancelModal?.addEventListener('click', e => {
        if (e.target === cancelModal) closeCancelModal();
    });
    document.getElementById('po-cancel-confirm')?.addEventListener('click', () => {
        const cb = cancelCallback;
        const motivo = cancelMotivo?.value || '';
        closeCancelModal();
        if (typeof cb === 'function') cb(motivo);
    });

    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function post(action, params) {
        const bodyParams = Object.assign({ action: action, nonce: riversoNonce }, params || {});
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(bodyParams)
        }).then(r => r.json());
    }

    function badge(estado, label) {
        return `<span class="po-badge po-badge-${esc(estado)}">${esc(label || estado)}</span>`;
    }

    function loadStats() {
        const el = document.getElementById('po-portal-stats');
        if (!el) return;
        post('riverso_print_orders_get_stats').then(res => {
            if (!res.success) return;
            const s = res.data;
            el.innerHTML = [
                ['Pendientes', s.pendientes, 'orange'],
                ['Aprobadas', s.aprobadas, 'blue'],
                ['Impresas hoy', s.impresa_hoy, 'green'],
                ['Creadas hoy', s.creadas_hoy, 'purple']
            ].map(([label, value, color]) => `
                <div class="stat-card">
                    <div class="stat-icon ${color}"><span class="dashicons dashicons-printer"></span></div>
                    <div class="stat-value">${esc(value)}</div>
                    <div class="stat-label">${esc(label)}</div>
                </div>`).join('');
        });
    }

    function loadList() {
        post('riverso_print_orders_list', {
            page: page,
            per_page: 20,
            search: document.getElementById('po-portal-search')?.value || '',
            estado: document.getElementById('po-portal-estado')?.value || '',
            mine: cfg.mine ? 1 : 0
        }).then(res => {
            if (!res.success) {
                body.innerHTML = `<tr><td colspan="8">${esc(res.data?.message || 'Error')}</td></tr>`;
                return;
            }
            const rows = res.data.items || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;">No hay órdenes.</td></tr>';
            } else {
                body.innerHTML = rows.map(o => {
                    const actions = [`<button type="button" class="btn btn-secondary btn-sm po-open" data-id="${o.id}">Abrir</button>`];
                    if (cfg.canApprove && o.estado === 'pendiente') {
                        actions.push(`<button type="button" class="btn btn-secondary btn-sm po-approve" data-id="${o.id}">Aprobar</button>`);
                    }
                    if (cfg.canPrint && ['borrador','pendiente','aprobada'].includes(o.estado)) {
                        actions.push(`<button type="button" class="btn btn-primary btn-sm po-print" data-id="${o.id}">Imprimir</button>`);
                    }
                    if (cfg.canCancel && o.estado !== 'impresa' && o.estado !== 'cancelada') {
                        actions.push(`<button type="button" class="btn btn-secondary btn-sm po-cancel" data-id="${o.id}">Cancelar</button>`);
                    }
                    return `<tr>
                        <td><code>${esc(o.numero_orden)}</code>${Number(o.prioridad) ? ' <strong style="color:#b91c1c;">!</strong>' : ''}</td>
                        <td>${badge(o.estado, o.estado_label)}</td>
                        <td>${esc(o.tipo_label || o.tipo)}</td>
                        <td>${esc(o.total_items)}</td>
                        <td>${esc(o.total_copias)}</td>
                        <td>${esc(o.solicitado_por_nombre || '')}</td>
                        <td>${esc(o.created_at || '')}</td>
                        <td style="white-space:nowrap;">${actions.join(' ')}</td>
                    </tr>`;
                }).join('');
            }
            const pages = res.data.pages || 1;
            const nav = document.getElementById('po-portal-pages');
            if (nav) {
                nav.innerHTML = `Página ${page} de ${pages} (${res.data.total}) ` +
                    (page > 1 ? `<a href="#" data-page="${page - 1}" class="po-page">Anterior</a> ` : '') +
                    (page < pages ? `<a href="#" data-page="${page + 1}" class="po-page">Siguiente</a>` : '');
            }
        }).catch(() => {
            body.innerHTML = '<tr><td colspan="8">Error de red.</td></tr>';
        });
    }

    function defaultModo() {
        const map = { etiqueta_producto: 'BolsaCOD', bolsa: 'Bolsa', etiqueta_simple: 'EtiquetaSimple', etiqueta_logo: 'EtiquetaLogo' };
        return map[document.getElementById('po-portal-tipo')?.value] || 'BolsaCOD';
    }

    function toPrice2(v) {
        if (v === null || v === undefined || v === '') return null;
        const n = Number(v);
        if (!isFinite(n) || n < 0) return null;
        return Math.round(n * 100) / 100;
    }

    function formatPrice2(v) {
        const n = toPrice2(v);
        if (n === null) return '—';
        return n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function priceInputValue(v) {
        const n = toPrice2(v);
        return n === null ? '' : n.toFixed(2);
    }

    function priceHasFraction(v) {
        const n = toPrice2(v);
        return n !== null && Math.abs(n - Math.round(n)) > 0.0001;
    }

    function canUnroundPrice(it) {
        const orig = toPrice2(it && it.precio_original);
        const cur = toPrice2(it && it.precio);
        return orig !== null && cur !== null && Math.abs(orig - cur) > 0.0001;
    }

    function isLockedOrder() {
        const estado = current && current.estado;
        return estado === 'impresa' || estado === 'cancelada';
    }

    function syncEditorChrome() {
        const estado = current && current.estado;
        const locked = estado === 'impresa' || estado === 'cancelada';
        const printed = estado === 'impresa';
        document.querySelectorAll('#po-portal-editor .po-workflow-btn').forEach(btn => {
            btn.style.display = locked ? 'none' : '';
        });
        const useDraft = document.getElementById('po-portal-use-as-draft');
        if (useDraft) useDraft.style.display = locked && cfg.canCreate ? '' : 'none';
        const reprint = document.getElementById('po-portal-reprint');
        if (reprint) reprint.style.display = printed && cfg.canPrint ? '' : 'none';
        const tipo = document.getElementById('po-portal-tipo');
        const prio = document.getElementById('po-portal-prioridad');
        const notas = document.getElementById('po-portal-notas');
        if (tipo) tipo.disabled = locked;
        if (prio) prio.disabled = locked;
        if (notas) notas.readOnly = locked;
        const addBox = document.getElementById('po-portal-add-box');
        if (addBox) addBox.style.display = !locked && cfg.canCreate ? '' : 'none';
    }

    function renderItems() {
        const tbody = document.getElementById('po-portal-items');
        if (!tbody) return;
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-secondary);">Sin productos</td></tr>';
            return;
        }
        tbody.innerHTML = items.map((it, idx) => {
            const precioLabel = formatPrice2(it.precio);
            if (isLockedOrder()) {
                return `<tr data-idx="${idx}">
                    <td><code>${esc(it.sku)}</code></td>
                    <td>${esc(it.nombre || '')}</td>
                    <td>${esc(it.cantidad_ean || 100)}</td>
                    <td>${esc(it.copias || 1)}</td>
                    <td>${esc(it.modo || 'BolsaCOD')}</td>
                    <td>${esc(it.color || 'BN')}</td>
                    <td>${esc(it.ean13 || '—')}</td>
                    <td>${esc(precioLabel)}</td>
                    ${cfg.canCreate ? '<td></td>' : ''}
                </tr>`;
            }
            const modoOpts = modos.map(m => `<option value="${esc(m)}"${m === (it.modo || 'BolsaCOD') ? ' selected' : ''}>${esc(m)}</option>`).join('');
            const colorOpts = colores.map(c => `<option value="${esc(c)}"${c === (it.color || 'BN') ? ' selected' : ''}>${esc(c)}</option>`).join('');
            const precioVal = priceInputValue(it.precio);
            const precioOpen = !!it.precioUnlocked;
            let precioHtml = `<span class="po-precio-view">${esc(precioLabel)}</span>`;
            if (cfg.canEditPrice) {
                precioHtml = `<span class="po-precio-view"${precioOpen ? ' style="display:none;"' : ''}>${esc(precioLabel)}</span>` +
                    `<input type="number" min="0" step="0.01" class="po-precio" value="${esc(precioVal)}" style="width:100px;padding:6px;${precioOpen ? '' : 'display:none;'}">`;
            }
            if (cfg.canCreate && priceHasFraction(it.precio)) {
                precioHtml += ` <button type="button" class="btn btn-secondary btn-sm po-round-precio">Redondear</button>`;
            }
            if (cfg.canCreate && canUnroundPrice(it)) {
                precioHtml += ` <button type="button" class="btn btn-secondary btn-sm po-unround-precio">Desredondear</button>`;
            }
            if (cfg.canEditPrice && !precioOpen) {
                precioHtml += ` <button type="button" class="btn btn-secondary btn-sm po-unlock-precio">Cambiar precio</button>`;
            }
            return `<tr data-idx="${idx}">
                <td><code title="El SKU no se puede cambiar">${esc(it.sku)}</code></td>
                <td><input type="text" class="po-nombre" value="${esc(it.nombre || '')}" style="width:100%;min-width:140px;padding:6px;"></td>
                <td><input type="number" min="1" class="po-ean" value="${esc(it.cantidad_ean || 100)}" style="width:80px;padding:6px;"></td>
                <td><input type="number" min="1" class="po-copias" value="${esc(it.copias || 1)}" style="width:70px;padding:6px;"></td>
                <td><select class="po-modo" style="padding:6px;">${modoOpts}</select></td>
                <td><select class="po-color" style="padding:6px;">${colorOpts}</select></td>
                <td><input type="text" class="po-ean13" maxlength="13" value="${esc(it.ean13 || '')}" style="width:110px;padding:6px;" placeholder="Opcional"></td>
                <td>${precioHtml}</td>
                ${cfg.canCreate ? `<td><button type="button" class="btn btn-secondary btn-sm po-del">Quitar</button></td>` : ''}
            </tr>`;
        }).join('');
    }

    function collectItems() {
        if (isLockedOrder()) {
            return items.map((it, i) => ({
                id: it.id || 0,
                sku: it.sku,
                nombre: it.nombre,
                precio: it.precio,
                precio_original: it.precio_original == null || it.precio_original === '' ? null : toPrice2(it.precio_original),
                cantidad_ean: it.cantidad_ean,
                copias: it.copias,
                modo: it.modo,
                color: it.color,
                ean13: it.ean13 || '',
                orden_posicion: i
            }));
        }
        document.querySelectorAll('#po-portal-items tr[data-idx]').forEach(tr => {
            const idx = parseInt(tr.dataset.idx, 10);
            if (!items[idx]) return;
            items[idx].nombre = tr.querySelector('.po-nombre')?.value || items[idx].nombre;
            items[idx].cantidad_ean = parseInt(tr.querySelector('.po-ean')?.value, 10) || 100;
            items[idx].copias = parseInt(tr.querySelector('.po-copias')?.value, 10) || 1;
            items[idx].modo = tr.querySelector('.po-modo')?.value || 'BolsaCOD';
            items[idx].color = tr.querySelector('.po-color')?.value || 'BN';
            items[idx].ean13 = tr.querySelector('.po-ean13')?.value || '';
            if (cfg.canEditPrice && items[idx].precioUnlocked) {
                const p = tr.querySelector('.po-precio')?.value;
                const next = p === '' ? null : toPrice2(p);
                const cur = toPrice2(items[idx].precio);
                const orig = toPrice2(items[idx].precio_original);
                items[idx].precio = next;
                if (next !== null && orig !== null && Math.abs(next - orig) > 0.0001 && (cur === null || Math.abs(next - cur) > 0.0001)) {
                    items[idx].precio_original = null;
                }
            }
        });
        return items.map((it, i) => ({
            id: it.id || 0,
            sku: it.sku,
            nombre: it.nombre,
            precio: it.precio,
            precio_original: it.precio_original == null || it.precio_original === '' ? null : toPrice2(it.precio_original),
            cantidad_ean: it.cantidad_ean,
            copias: it.copias,
            modo: it.modo,
            color: it.color,
            ean13: it.ean13 || '',
            orden_posicion: i
        }));
    }

    function scrollToEditor() {
        requestAnimationFrame(() => {
            document.getElementById('po-portal-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function scrollToList() {
        requestAnimationFrame(() => {
            const target = document.querySelector('.portal-header') || document.getElementById('po-portal-stats');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    function clearProductSearch() {
        const q = document.getElementById('po-portal-q');
        const hits = document.getElementById('po-portal-hits');
        if (q) q.value = '';
        if (hits) hits.innerHTML = '';
    }

    function fill(order) {
        current = order;
        items = (order.items || []).map(it => Object.assign({}, it));
        document.getElementById('po-portal-id').value = order.id;
        document.getElementById('po-portal-tipo').value = order.tipo;
        document.getElementById('po-portal-prioridad').checked = Number(order.prioridad) === 1;
        document.getElementById('po-portal-notas').value = order.notas || '';
        document.getElementById('po-portal-numero').textContent = order.numero_orden;
        document.getElementById('po-portal-estado-badge').innerHTML = badge(order.estado, order.estado_label);
        document.getElementById('po-portal-editor-title').textContent = order.numero_orden;
        document.getElementById('po-portal-editor').style.display = '';
        renderItems();
        document.getElementById('po-portal-msg').textContent = '';
        syncEditorChrome();
    }

    function resetEditor() {
        current = null;
        items = [];
        document.getElementById('po-portal-id').value = '';
        document.getElementById('po-portal-tipo').value = 'etiqueta_producto';
        document.getElementById('po-portal-prioridad').checked = false;
        document.getElementById('po-portal-notas').value = '';
        document.getElementById('po-portal-numero').textContent = 'Se asigna al guardar';
        document.getElementById('po-portal-estado-badge').innerHTML = badge('borrador', 'Borrador');
        document.getElementById('po-portal-editor-title').textContent = 'Nueva orden';
        document.getElementById('po-portal-editor').style.display = '';
        clearProductSearch();
        renderItems();
        syncEditorChrome();
        scrollToEditor();
    }

    function save() {
        const id = document.getElementById('po-portal-id').value;
        const payload = {
            tipo: document.getElementById('po-portal-tipo').value,
            prioridad: document.getElementById('po-portal-prioridad').checked ? 1 : 0,
            notas: document.getElementById('po-portal-notas').value,
            items: JSON.stringify(collectItems())
        };
        if (id) payload.id = id;
        document.getElementById('po-portal-msg').textContent = 'Guardando...';
        return post(id ? 'riverso_print_orders_update' : 'riverso_print_orders_create', payload).then(res => {
            if (!res.success) {
                document.getElementById('po-portal-msg').textContent = res.data?.message || 'Error';
                throw new Error(res.data?.message || 'Error');
            }
            fill(res.data.order);
            document.getElementById('po-portal-msg').textContent = 'Guardado ' + res.data.order.numero_orden;
            loadList();
            return res.data.order;
        });
    }

    function editorHasContent() {
        collectItems();
        if (items.length) return true;
        return (document.getElementById('po-portal-notas')?.value || '').trim() !== '';
    }

    function canSaveDraftOnClose() {
        if (!cfg.canCreate) return false;
        const estado = current && current.estado;
        if (!estado) return true;
        return estado === 'borrador' || estado === 'pendiente' || estado === 'aprobada';
    }

    function hideEditor() {
        current = null;
        items = [];
        document.getElementById('po-portal-editor').style.display = 'none';
        document.getElementById('po-portal-msg').textContent = '';
        clearProductSearch();
        loadList();
        scrollToList();
    }

    function closeEditor() {
        if (canSaveDraftOnClose() && editorHasContent()) {
            document.getElementById('po-portal-msg').textContent = 'Guardando borrador...';
            save().then(() => hideEditor()).catch(() => {});
            return;
        }
        hideEditor();
    }

    function jobsFrom(order) {
        const printer = (typeof RiversoLabelPrint !== 'undefined' && RiversoLabelPrint.getPreferred) ? RiversoLabelPrint.getPreferred() : null;
        return (order.items || []).map(it => ({
            nombre: it.nombre,
            sku: it.sku,
            cantidad: it.cantidad_ean || 100,
            precio: it.precio == null || it.precio === '' ? null : Math.round(Number(it.precio)),
            copias: it.copias || 1,
            modo: it.modo || 'BolsaCOD',
            color: it.color || 'BN',
            ean13: it.ean13 || null,
            printerName: printer
        }));
    }

    function printOrder(order) {
        if (typeof RiversoLabelPrint === 'undefined') {
            alert('El módulo de impresión no está cargado.');
            return;
        }
        const jobs = jobsFrom(order);
        if (!jobs.length) {
            alert('La orden no tiene ítems');
            return;
        }
        if (!RiversoLabelPrint.isHealthy()) {
            alert('Agente de impresión no disponible. Ejecuta EtiquetadorRS.exe en este PC.');
            return;
        }
        const printer = RiversoLabelPrint.getPreferred() || '';
        if (!confirm(`Imprimir ${jobs.length} producto(s) / ${order.total_copias} copias${printer ? ' en ' + printer : ''}?`)) return;
        const reprint = order.estado === 'impresa';
        RiversoLabelPrint.print(jobs).then(() => {
            if (reprint) {
                hideEditor();
                loadStats();
                return null;
            }
            return post('riverso_print_orders_mark_printed', {
                id: order.id,
                impresora_nombre: printer
            });
        }).then(res => {
            if (!res) return;
            if (!res.success) {
                alert('Se imprimió, pero no se pudo registrar: ' + (res.data?.message || 'error'));
                return;
            }
            hideEditor();
            loadStats();
        }).catch(err => alert(err && err.message ? err.message : 'Error de impresión'));
    }

    function openOrder(id) {
        post('riverso_print_orders_get', { id }).then(res => {
            if (!res.success) { alert(res.data?.message || 'Error'); return; }
            fill(res.data.order);
            scrollToEditor();
        });
    }

    document.getElementById('po-portal-new')?.addEventListener('click', resetEditor);
    document.getElementById('po-portal-editor')?.addEventListener('click', e => {
        if (e.target.closest('.po-close-order')) closeEditor();
    });
    document.getElementById('po-portal-filter')?.addEventListener('click', () => { page = 1; loadList(); });
    document.getElementById('po-portal-search')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { page = 1; loadList(); }
    });
    document.getElementById('po-portal-pages')?.addEventListener('click', e => {
        const a = e.target.closest('.po-page');
        if (!a) return;
        e.preventDefault();
        page = parseInt(a.dataset.page, 10) || 1;
        loadList();
    });
    body.addEventListener('click', e => {
        const open = e.target.closest('.po-open');
        const approve = e.target.closest('.po-approve');
        const printBtn = e.target.closest('.po-print');
        const cancel = e.target.closest('.po-cancel');
        if (open) openOrder(open.dataset.id);
        if (approve) {
            post('riverso_print_orders_approve', { id: approve.dataset.id }).then(res => {
                if (!res.success) { alert(res.data?.message || 'Error'); return; }
                loadList();
            });
        }
        if (printBtn) {
            post('riverso_print_orders_get', { id: printBtn.dataset.id }).then(res => {
                if (!res.success) { alert(res.data?.message || 'Error'); return; }
                printOrder(res.data.order);
            });
        }
        if (cancel) {
            askCancelMotivo(motivo => {
                post('riverso_print_orders_cancel', { id: cancel.dataset.id, motivo }).then(res => {
                    if (!res.success) { alert(res.data?.message || 'Error'); return; }
                    loadList();
                });
            });
        }
    });

    document.getElementById('po-portal-items')?.addEventListener('click', e => {
        const del = e.target.closest('.po-del');
        const unlock = e.target.closest('.po-unlock-precio');
        const roundBtn = e.target.closest('.po-round-precio');
        const unroundBtn = e.target.closest('.po-unround-precio');
        if (unlock) {
            if (!cfg.canEditPrice) return;
            if (!confirm('El nuevo precio se usará solo en esta impresión. El producto en catálogo no cambia.')) return;
            collectItems();
            const idx = parseInt(unlock.closest('tr').dataset.idx, 10);
            if (items[idx]) items[idx].precioUnlocked = true;
            renderItems();
            document.querySelector(`#po-portal-items tr[data-idx="${idx}"] .po-precio`)?.focus();
            return;
        }
        if (roundBtn) {
            if (!cfg.canCreate) return;
            collectItems();
            const idx = parseInt(roundBtn.closest('tr').dataset.idx, 10);
            if (!items[idx] || items[idx].precio == null || items[idx].precio === '') return;
            if (!priceHasFraction(items[idx].precio_original)) {
                items[idx].precio_original = toPrice2(items[idx].precio);
            }
            items[idx].precio = Math.round(Number(items[idx].precio));
            renderItems();
            return;
        }
        if (unroundBtn) {
            if (!cfg.canCreate) return;
            collectItems();
            const idx = parseInt(unroundBtn.closest('tr').dataset.idx, 10);
            if (!items[idx] || !canUnroundPrice(items[idx])) return;
            items[idx].precio = toPrice2(items[idx].precio_original);
            renderItems();
            return;
        }
        if (!del) return;
        collectItems();
        const idx = parseInt(del.closest('tr').dataset.idx, 10);
        items.splice(idx, 1);
        renderItems();
    });

    document.getElementById('po-portal-q-btn')?.addEventListener('click', searchProd);
    document.getElementById('po-portal-q-clear')?.addEventListener('click', clearProductSearch);
    document.getElementById('po-portal-q')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); searchProd(); }
    });

    function searchProd() {
        const q = document.getElementById('po-portal-q')?.value.trim();
        const hits = document.getElementById('po-portal-hits');
        if (!q || !hits) return;
        hits.textContent = 'Buscando...';
        post('riverso_tienda_local_search', { query: q }).then(res => {
            const found = (res.success && res.data && res.data.items) ? res.data.items.filter(Boolean) : [];
            if (!found.length) {
                hits.innerHTML = '<p style="color:var(--text-secondary);">Sin resultados.</p>';
                return;
            }
            hits.innerHTML = found.map(p => `
                <div class="po-hit">
                    <div><strong>${esc(p.nombre)}</strong><br><code>${esc(p.sku)}</code> · ${esc(p.precio_formateado || p.precio || '')}</div>
                    <button type="button" class="btn btn-primary btn-sm po-add" data-sku="${esc(p.sku)}" data-nombre="${esc(p.nombre)}" data-precio="${esc(p.precio || 0)}">Agregar</button>
                </div>`).join('');
        }).catch(() => { hits.textContent = 'Error buscando.'; });
    }

    document.getElementById('po-portal-hits')?.addEventListener('click', e => {
        const btn = e.target.closest('.po-add');
        if (!btn) return;
        collectItems();
        items.push({
            sku: btn.dataset.sku,
            nombre: btn.dataset.nombre,
            precio: parseFloat(btn.dataset.precio) || null,
            cantidad_ean: 100,
            copias: 1,
            modo: defaultModo(),
            color: 'BN'
        });
        renderItems();
    });

    document.getElementById('po-portal-save')?.addEventListener('click', () => save().catch(() => {}));
    document.getElementById('po-portal-submit')?.addEventListener('click', () => {
        save().then(order => post('riverso_print_orders_submit', { id: order.id })).then(res => {
            if (!res.success) { alert(res.data?.message || 'Error'); return; }
            fill(res.data.order);
            loadList();
        }).catch(() => {});
    });
    document.getElementById('po-portal-approve')?.addEventListener('click', () => {
        const id = document.getElementById('po-portal-id').value;
        if (!id) return;
        post('riverso_print_orders_approve', { id }).then(res => {
            if (!res.success) { alert(res.data?.message || 'Error'); return; }
            fill(res.data.order);
            loadList();
        });
    });
    document.getElementById('po-portal-cancel')?.addEventListener('click', () => {
        const id = document.getElementById('po-portal-id').value;
        if (!id) {
            hideEditor();
            return;
        }
        if (!cfg.canCancel) return;
        askCancelMotivo(motivo => {
            post('riverso_print_orders_cancel', { id, motivo }).then(res => {
                if (!res.success) { alert(res.data?.message || 'Error'); return; }
                fill(res.data.order);
                loadList();
            });
        });
    });
    document.getElementById('po-portal-print')?.addEventListener('click', () => {
        save().then(order => printOrder(order)).catch(() => {});
    });
    document.getElementById('po-portal-reprint')?.addEventListener('click', () => {
        if (!current || current.estado !== 'impresa') return;
        printOrder(current);
    });
    document.getElementById('po-portal-use-as-draft')?.addEventListener('click', () => {
        const id = document.getElementById('po-portal-id').value;
        if (!id || !cfg.canCreate) return;
        post('riverso_print_orders_duplicate', { id }).then(res => {
            if (!res.success) { alert(res.data?.message || 'Error'); return; }
            fill(res.data.order);
            loadList();
            document.getElementById('po-portal-msg').textContent = 'Nuevo borrador ' + res.data.order.numero_orden;
        });
    });

    loadStats();
    loadList();
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
