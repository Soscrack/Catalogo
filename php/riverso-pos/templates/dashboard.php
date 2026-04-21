<?php
/**
 * Template: Dashboard Mejorado
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';
$current_user = wp_get_current_user();
$user_roles = $current_user->roles;

// Obtener estadísticas básicas
$stats = [
    'facturas_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pendiente'") ?: 0,
    'facturas_hoy' => $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}facturas WHERE DATE(created_at) = %s",
        current_time('Y-m-d')
    )) ?: 0,
    'codigos_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigos WHERE verificado = 0") ?: 0,
    'tareas_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}tareas WHERE estado IN ('pendiente', 'en_progreso')") ?: 0,
    'tareas_urgentes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}tareas WHERE estado = 'pendiente' AND prioridad = 'urgente'") ?: 0,
];

// Stats avanzadas
$stats_avanzadas = [
    'cotizaciones_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}customer_quotes WHERE status IN ('draft', 'sent')") ?: 0,
    'pos_ventas_hoy' => $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_riverso_pos_sale'
        WHERE p.post_type = 'shop_order' AND pm.meta_value = 'yes' AND DATE(p.post_date) = %s",
        current_time('Y-m-d')
    )) ?: 0,
    'pos_total_hoy' => $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(pm2.meta_value), 0) FROM {$wpdb->posts} p 
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_riverso_pos_sale'
        INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
        WHERE p.post_type = 'shop_order' AND pm.meta_value = 'yes' AND DATE(p.post_date) = %s",
        current_time('Y-m-d')
    )) ?: 0,
    'sesion_activa' => $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}pos_sessions WHERE user_id = %d AND status = 'open'",
        get_current_user_id()
    )),
    'proveedores_total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}proveedores WHERE activo = 1") ?: 0,
    'empleados_total' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}empleados WHERE activo = 1") ?: 0,
];

// Ventas de los últimos 7 días para gráfico
$ventas_semana = $wpdb->get_results("
    SELECT DATE(p.post_date) as fecha, 
           COUNT(*) as cantidad,
           COALESCE(SUM(pm.meta_value), 0) as total
    FROM {$wpdb->posts} p 
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
    WHERE p.post_type = 'shop_order' 
    AND p.post_status IN ('wc-completed', 'wc-processing')
    AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(p.post_date)
    ORDER BY fecha ASC
");

// Últimas facturas
$ultimas_facturas = $wpdb->get_results("
    SELECT f.*, p.razon_social as proveedor_nombre 
    FROM {$prefix}facturas f 
    LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id 
    ORDER BY f.created_at DESC 
    LIMIT 5
");

// Tareas recientes del usuario o todas si es admin
$where_tareas = current_user_can('riverso_manage_settings') 
    ? "estado IN ('pendiente', 'en_progreso')" 
    : $wpdb->prepare("estado IN ('pendiente', 'en_progreso') AND (asignado_a = %d OR asignado_a IS NULL)", get_current_user_id());

$tareas_recientes = $wpdb->get_results("
    SELECT t.*, u.display_name as asignado_nombre
    FROM {$prefix}tareas t
    LEFT JOIN {$wpdb->users} u ON t.asignado_a = u.ID
    WHERE $where_tareas
    ORDER BY FIELD(prioridad, 'urgente', 'alta', 'normal', 'baja'), created_at DESC 
    LIMIT 10
");

// Cotizaciones recientes
$cotizaciones_recientes = $wpdb->get_results("
    SELECT * FROM {$prefix}customer_quotes 
    WHERE status IN ('draft', 'sent', 'viewed')
    ORDER BY created_at DESC 
    LIMIT 5
");

// Actividad reciente (audit log)
$actividad_reciente = $wpdb->get_results("
    SELECT a.*, u.display_name 
    FROM {$prefix}audit_log a
    LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
    ORDER BY a.created_at DESC 
    LIMIT 10
");
?>

<div class="wrap riverso-pos-wrap dashboard-wrap">
    <h1>
        <span class="dashicons dashicons-store"></span>
        <?php _e('Riverso POS - Dashboard', 'riverso-pos'); ?>
        <span class="welcome-user">
            <?php printf(__('Hola, %s', 'riverso-pos'), $current_user->display_name); ?>
        </span>
    </h1>
    
    <!-- Cards de estadísticas principales -->
    <div class="riverso-stats-grid stats-main">
        <?php if (current_user_can('riverso_create_orders')): ?>
        <div class="riverso-stat-card highlight <?php echo $stats_avanzadas['sesion_activa'] ? 'active' : 'inactive'; ?>">
            <div class="stat-icon" style="background: <?php echo $stats_avanzadas['sesion_activa'] ? '#28a745' : '#dc3545'; ?>;">
                <span class="dashicons dashicons-store"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number">$<?php echo number_format($stats_avanzadas['pos_total_hoy'], 0, ',', '.'); ?></span>
                <span class="stat-label">
                    <?php _e('Ventas POS Hoy', 'riverso-pos'); ?>
                    <small>(<?php echo $stats_avanzadas['pos_ventas_hoy']; ?> ventas)</small>
                </span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-pos'); ?>" class="stat-link">
                <?php echo $stats_avanzadas['sesion_activa'] ? 'Ir a POS' : 'Abrir Caja'; ?>
            </a>
        </div>
        <?php endif; ?>
        
        <?php if (current_user_can('riverso_process_invoices')): ?>
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #f0ad4e;">
                <span class="dashicons dashicons-media-text"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['facturas_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Facturas Pendientes', 'riverso-pos'); ?></span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-invoices'); ?>" class="stat-link">Ver</a>
        </div>
        <?php endif; ?>
        
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #5bc0de;">
                <span class="dashicons dashicons-clipboard"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['tareas_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Tareas Pendientes', 'riverso-pos'); ?></span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks'); ?>" class="stat-link">Ver</a>
        </div>
        
        <?php if ($stats['tareas_urgentes'] > 0): ?>
        <div class="riverso-stat-card alert pulse">
            <div class="stat-icon" style="background: #dc3545;">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['tareas_urgentes']; ?></span>
                <span class="stat-label"><?php _e('Tareas Urgentes', 'riverso-pos'); ?></span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks&prioridad=urgente'); ?>" class="stat-link">Ver</a>
        </div>
        <?php endif; ?>
        
        <?php if (current_user_can('riverso_view_quotes')): ?>
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #6f42c1;">
                <span class="dashicons dashicons-format-aside"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats_avanzadas['cotizaciones_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Cotizaciones Pendientes', 'riverso-pos'); ?></span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-customer-quotes'); ?>" class="stat-link">Ver</a>
        </div>
        <?php endif; ?>
        
        <?php if (current_user_can('riverso_manage_codes')): ?>
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #17a2b8;">
                <span class="dashicons dashicons-tag"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['codigos_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Códigos Sin Vincular', 'riverso-pos'); ?></span>
            </div>
            <a href="<?php echo admin_url('admin.php?page=riverso-pos-codes'); ?>" class="stat-link">Ver</a>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="riverso-dashboard-grid">
        <!-- Gráfico de Ventas -->
        <?php if (current_user_can('riverso_view_orders')): ?>
        <div class="riverso-panel chart-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-chart-bar"></span>
                    <?php _e('Ventas Últimos 7 Días', 'riverso-pos'); ?>
                </h2>
            </div>
            <div class="panel-body">
                <canvas id="ventas-chart" height="200"></canvas>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Tareas Pendientes -->
        <div class="riverso-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php _e('Mis Tareas', 'riverso-pos'); ?>
                </h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks'); ?>" class="button">
                    <?php _e('Ver todas', 'riverso-pos'); ?>
                </a>
            </div>
            <div class="panel-body">
                <?php if (empty($tareas_recientes)): ?>
                    <p class="no-items success-msg">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php _e('¡No tienes tareas pendientes!', 'riverso-pos'); ?>
                    </p>
                <?php else: ?>
                    <ul class="task-list modern">
                        <?php foreach ($tareas_recientes as $tarea): 
                            $prioridades = riverso_get_task_priorities();
                            $tipos = riverso_get_task_types();
                            $prioridad = $prioridades[$tarea->prioridad] ?? ['label' => $tarea->prioridad, 'color' => '#777'];
                            $tipo = $tipos[$tarea->tipo] ?? ['label' => $tarea->tipo, 'icon' => 'marker'];
                        ?>
                        <li class="task-item priority-<?php echo $tarea->prioridad; ?>">
                            <span class="task-icon dashicons dashicons-<?php echo $tipo['icon']; ?>"></span>
                            <div class="task-content">
                                <strong><?php echo esc_html($tarea->titulo); ?></strong>
                                <span class="task-meta">
                                    <span class="priority-badge" style="background: <?php echo $prioridad['color']; ?>">
                                        <?php echo $prioridad['label']; ?>
                                    </span>
                                    <?php echo $tipo['label']; ?>
                                    <?php if ($tarea->asignado_nombre): ?>
                                        <span class="assignee">→ <?php echo esc_html($tarea->asignado_nombre); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks&action=complete&id=' . $tarea->id); ?>" 
                               class="button button-small complete-task" title="Completar">
                                <span class="dashicons dashicons-yes"></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Últimas Facturas -->
        <?php if (current_user_can('riverso_process_invoices')): ?>
        <div class="riverso-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-media-text"></span>
                    <?php _e('Últimas Facturas', 'riverso-pos'); ?>
                </h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-invoices'); ?>" class="button">
                    <?php _e('Ver todas', 'riverso-pos'); ?>
                </a>
            </div>
            <div class="panel-body">
                <?php if (empty($ultimas_facturas)): ?>
                    <p class="no-items"><?php _e('No hay facturas registradas', 'riverso-pos'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped compact">
                        <thead>
                            <tr>
                                <th><?php _e('Folio', 'riverso-pos'); ?></th>
                                <th><?php _e('Proveedor', 'riverso-pos'); ?></th>
                                <th><?php _e('Total', 'riverso-pos'); ?></th>
                                <th><?php _e('Estado', 'riverso-pos'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_facturas as $factura): 
                                $estados = riverso_get_invoice_statuses();
                                $estado = $estados[$factura->estado] ?? ['label' => $factura->estado, 'color' => '#777'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($factura->folio); ?></strong>
                                    <br><small><?php echo riverso_get_dte_name($factura->tipo_dte); ?></small>
                                </td>
                                <td><?php echo esc_html($factura->proveedor_nombre ?: $factura->razon_social_emisor); ?></td>
                                <td><?php echo riverso_format_clp($factura->monto_total); ?></td>
                                <td>
                                    <span class="status-badge" style="background: <?php echo $estado['color']; ?>">
                                        <?php echo $estado['label']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Cotizaciones a Clientes -->
        <?php if (current_user_can('riverso_view_quotes') && !empty($cotizaciones_recientes)): ?>
        <div class="riverso-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-format-aside"></span>
                    <?php _e('Cotizaciones Pendientes', 'riverso-pos'); ?>
                </h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-customer-quotes'); ?>" class="button">
                    <?php _e('Ver todas', 'riverso-pos'); ?>
                </a>
            </div>
            <div class="panel-body">
                <table class="wp-list-table widefat fixed striped compact">
                    <thead>
                        <tr>
                            <th><?php _e('N°', 'riverso-pos'); ?></th>
                            <th><?php _e('Cliente', 'riverso-pos'); ?></th>
                            <th><?php _e('Total', 'riverso-pos'); ?></th>
                            <th><?php _e('Vence', 'riverso-pos'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cotizaciones_recientes as $cot): ?>
                        <tr>
                            <td><strong><?php echo esc_html($cot->quote_number); ?></strong></td>
                            <td><?php echo esc_html($cot->customer_name); ?></td>
                            <td><?php echo riverso_format_clp($cot->total); ?></td>
                            <td>
                                <?php 
                                if ($cot->valid_until) {
                                    $vence = strtotime($cot->valid_until);
                                    $hoy = strtotime('today');
                                    $diff = ($vence - $hoy) / 86400;
                                    $class = $diff < 0 ? 'expired' : ($diff <= 1 ? 'warning' : '');
                                    echo '<span class="vence-badge ' . $class . '">' . date('d/m', $vence) . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Actividad Reciente -->
        <?php if (current_user_can('riverso_manage_settings')): ?>
        <div class="riverso-panel activity-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-backup"></span>
                    <?php _e('Actividad Reciente', 'riverso-pos'); ?>
                </h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-audit'); ?>" class="button">
                    <?php _e('Ver log', 'riverso-pos'); ?>
                </a>
            </div>
            <div class="panel-body">
                <?php if (empty($actividad_reciente)): ?>
                    <p class="no-items"><?php _e('No hay actividad reciente', 'riverso-pos'); ?></p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($actividad_reciente as $act): ?>
                        <li class="activity-item">
                            <span class="activity-time"><?php echo human_time_diff(strtotime($act->created_at), current_time('timestamp')); ?></span>
                            <span class="activity-user"><?php echo esc_html($act->display_name ?: 'Sistema'); ?></span>
                            <span class="activity-action"><?php echo esc_html($act->action); ?></span>
                            <span class="activity-module"><?php echo esc_html($act->module); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Acciones Rápidas -->
    <div class="riverso-panel actions-panel">
        <div class="panel-header">
            <h2>
                <span class="dashicons dashicons-admin-tools"></span>
                <?php _e('Acciones Rápidas', 'riverso-pos'); ?>
            </h2>
        </div>
        <div class="panel-body">
            <div class="quick-actions modern">
                <?php if (current_user_can('riverso_create_orders')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-pos'); ?>" class="quick-action-btn primary">
                    <span class="dashicons dashicons-cart"></span>
                    <?php _e('Punto de Venta', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_view_quotes')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-customer-quotes&action=new'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-format-aside"></span>
                    <?php _e('Nueva Cotización', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_process_invoices')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-invoices&action=upload'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Subir Factura XML', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_manage_codes')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-codes'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-tag"></span>
                    <?php _e('Vincular Códigos', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_create_tasks')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks&action=new'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Nueva Tarea', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_edit_products')): ?>
                <a href="<?php echo admin_url('post-new.php?post_type=product'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-products"></span>
                    <?php _e('Nuevo Producto', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_manage_products')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-barcodes'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-barcode"></span>
                    <?php _e('Códigos de Barra', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
                
                <?php if (current_user_can('riverso_manage_warehouse')): ?>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-warehouse'); ?>" class="quick-action-btn">
                    <span class="dashicons dashicons-archive"></span>
                    <?php _e('Bodega', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.dashboard-wrap .welcome-user {
    float: right;
    font-size: 14px;
    font-weight: normal;
    color: #666;
}

.stats-main {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) !important;
}

.riverso-stat-card {
    position: relative;
}

.riverso-stat-card.highlight {
    grid-column: span 2;
}

.riverso-stat-card .stat-link {
    position: absolute;
    top: 10px;
    right: 10px;
    font-size: 12px;
    color: #2271b1;
    text-decoration: none;
}

.riverso-stat-card .stat-link:hover {
    text-decoration: underline;
}

.riverso-stat-card.active {
    border: 2px solid #28a745;
}

.riverso-stat-card.inactive {
    border: 2px solid #dc3545;
}

.riverso-stat-card.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4); }
    50% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
}

.riverso-dashboard-grid {
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)) !important;
}

.chart-panel {
    grid-column: span 2;
}

.task-list.modern .task-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-left: 4px solid #ddd;
    margin-bottom: 8px;
    background: #f9f9f9;
    border-radius: 0 8px 8px 0;
    transition: all 0.2s;
}

.task-list.modern .task-item:hover {
    background: #f0f6fc;
}

.task-list.modern .task-item.priority-urgente {
    border-left-color: #dc3545;
    background: #fff5f5;
}

.task-list.modern .task-item.priority-alta {
    border-left-color: #fd7e14;
}

.task-list.modern .task-item .task-content {
    flex: 1;
}

.task-list.modern .task-item .assignee {
    color: #666;
    font-style: italic;
}

.task-list.modern .complete-task {
    padding: 5px 8px;
}

.success-msg {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #28a745;
    font-weight: 500;
}

.success-msg .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.vence-badge {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
    background: #e9ecef;
}

.vence-badge.warning {
    background: #fff3cd;
    color: #856404;
}

.vence-badge.expired {
    background: #f8d7da;
    color: #721c24;
}

.activity-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.activity-item {
    display: flex;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

.activity-time {
    color: #999;
    min-width: 80px;
}

.activity-user {
    font-weight: 500;
}

.activity-action {
    color: #2271b1;
}

.activity-module {
    color: #666;
    font-size: 12px;
}

.quick-actions.modern {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
}

.quick-action-btn.primary {
    background: #2271b1;
    color: #fff;
}

.quick-action-btn.primary:hover {
    background: #135e96;
    color: #fff;
}

.table.compact td, .table.compact th {
    padding: 6px 8px;
}

@media (max-width: 1200px) {
    .chart-panel {
        grid-column: span 1;
    }
    
    .riverso-stat-card.highlight {
        grid-column: span 1;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
jQuery(document).ready(function($) {
    // Gráfico de ventas
    <?php if (current_user_can('riverso_view_orders')): ?>
    const ctx = document.getElementById('ventas-chart');
    if (ctx) {
        const labels = [];
        const dataCantidad = [];
        const dataTotal = [];
        
        // Llenar últimos 7 días
        const hoy = new Date();
        for (let i = 6; i >= 0; i--) {
            const fecha = new Date(hoy);
            fecha.setDate(fecha.getDate() - i);
            const fechaStr = fecha.toISOString().split('T')[0];
            labels.push(fecha.toLocaleDateString('es-CL', { weekday: 'short', day: 'numeric' }));
            
            // Buscar datos
            let encontrado = false;
            <?php foreach ($ventas_semana as $v): ?>
            if ('<?php echo $v->fecha; ?>' === fechaStr) {
                dataCantidad.push(<?php echo $v->cantidad; ?>);
                dataTotal.push(<?php echo $v->total; ?>);
                encontrado = true;
            }
            <?php endforeach; ?>
            if (!encontrado) {
                dataCantidad.push(0);
                dataTotal.push(0);
            }
        }
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Ventas ($)',
                    data: dataTotal,
                    backgroundColor: 'rgba(34, 113, 177, 0.7)',
                    borderColor: 'rgba(34, 113, 177, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Cantidad',
                    data: dataCantidad,
                    type: 'line',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString('es-CL');
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
