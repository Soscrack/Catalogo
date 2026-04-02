<?php
/**
 * Template: Dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$prefix = $wpdb->prefix . 'riverso_';

// Obtener estadísticas
$stats = [
    'facturas_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}facturas WHERE estado = 'pendiente'"),
    'facturas_hoy' => $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}facturas WHERE DATE(created_at) = %s",
        current_time('Y-m-d')
    )),
    'codigos_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}codigos WHERE verificado = 0"),
    'tareas_pendientes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}tareas WHERE estado IN ('pendiente', 'en_progreso')"),
    'tareas_urgentes' => $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}tareas WHERE estado = 'pendiente' AND prioridad = 'urgente'"),
];

// Últimas facturas
$ultimas_facturas = $wpdb->get_results("
    SELECT f.*, p.razon_social as proveedor_nombre 
    FROM {$prefix}facturas f 
    LEFT JOIN {$prefix}proveedores p ON f.proveedor_id = p.id 
    ORDER BY f.created_at DESC 
    LIMIT 5
");

// Tareas recientes
$tareas_recientes = $wpdb->get_results("
    SELECT * FROM {$prefix}tareas 
    WHERE estado IN ('pendiente', 'en_progreso') 
    ORDER BY FIELD(prioridad, 'urgente', 'alta', 'normal', 'baja'), created_at DESC 
    LIMIT 10
");
?>

<div class="wrap riverso-pos-wrap">
    <h1>
        <span class="dashicons dashicons-store"></span>
        <?php _e('Riverso POS', 'riverso-pos'); ?>
    </h1>
    
    <!-- Cards de estadísticas -->
    <div class="riverso-stats-grid">
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #f0ad4e;">
                <span class="dashicons dashicons-media-text"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['facturas_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Facturas Pendientes', 'riverso-pos'); ?></span>
            </div>
        </div>
        
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #5bc0de;">
                <span class="dashicons dashicons-tag"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['codigos_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Códigos Sin Vincular', 'riverso-pos'); ?></span>
            </div>
        </div>
        
        <div class="riverso-stat-card">
            <div class="stat-icon" style="background: #5cb85c;">
                <span class="dashicons dashicons-clipboard"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['tareas_pendientes']; ?></span>
                <span class="stat-label"><?php _e('Tareas Pendientes', 'riverso-pos'); ?></span>
            </div>
        </div>
        
        <div class="riverso-stat-card <?php echo $stats['tareas_urgentes'] > 0 ? 'alert' : ''; ?>">
            <div class="stat-icon" style="background: #d9534f;">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="stat-content">
                <span class="stat-number"><?php echo $stats['tareas_urgentes']; ?></span>
                <span class="stat-label"><?php _e('Urgentes', 'riverso-pos'); ?></span>
            </div>
        </div>
    </div>
    
    <div class="riverso-dashboard-grid">
        <!-- Últimas Facturas -->
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
                    <table class="wp-list-table widefat fixed striped">
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
        
        <!-- Tareas Pendientes -->
        <div class="riverso-panel">
            <div class="panel-header">
                <h2>
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php _e('Tareas Pendientes', 'riverso-pos'); ?>
                </h2>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-tasks'); ?>" class="button">
                    <?php _e('Ver todas', 'riverso-pos'); ?>
                </a>
            </div>
            <div class="panel-body">
                <?php if (empty($tareas_recientes)): ?>
                    <p class="no-items"><?php _e('No hay tareas pendientes', 'riverso-pos'); ?></p>
                <?php else: ?>
                    <ul class="task-list">
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
                                </span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Acciones Rápidas -->
    <div class="riverso-panel">
        <div class="panel-header">
            <h2>
                <span class="dashicons dashicons-admin-tools"></span>
                <?php _e('Acciones Rápidas', 'riverso-pos'); ?>
            </h2>
        </div>
        <div class="panel-body">
            <div class="quick-actions">
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
                    <span class="dashicons dashicons-cart"></span>
                    <?php _e('Nuevo Producto', 'riverso-pos'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
