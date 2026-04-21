<?php
/**
 * Template: Log de Auditoría
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('riverso_view_audit')) {
    wp_die('No tienes permisos para ver esta página.');
}

require_once RIVERSO_POS_PLUGIN_DIR . 'includes/class-audit.php';

// Obtener filtros
$filters = [
    'action'      => isset($_GET['action_filter']) ? sanitize_text_field($_GET['action_filter']) : '',
    'entity_type' => isset($_GET['entity_type']) ? sanitize_text_field($_GET['entity_type']) : '',
    'user_id'     => isset($_GET['user_id']) ? intval($_GET['user_id']) : 0,
    'date_from'   => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
    'date_to'     => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
    'search'      => isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '',
];

$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 50;

$result = Riverso_POS_Audit::get_logs($filters, $page, $per_page);
$stats = Riverso_POS_Audit::get_stats(30);

// Obtener usuarios para filtro
$users = get_users(['role__in' => ['administrator', 'riverso_admin', 'riverso_ventas', 'riverso_bodega', 'riverso_compras', 'riverso_recepciones', 'riverso_editor']]);
?>

<div class="wrap riverso-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-visibility" style="margin-right: 8px;"></span>
        Log de Auditoría
    </h1>
    
    <hr class="wp-header-end">
    
    <!-- Estadísticas rápidas -->
    <div class="audit-stats" style="display: flex; gap: 20px; margin: 20px 0; flex-wrap: wrap;">
        <div class="stat-card" style="background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); min-width: 150px;">
            <div style="font-size: 28px; font-weight: bold; color: #2271b1;"><?php echo number_format($stats['total']); ?></div>
            <div style="color: #666; font-size: 12px;">Acciones (30 días)</div>
        </div>
        <?php if (!empty($stats['by_action'])): ?>
        <div class="stat-card" style="background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 14px; font-weight: bold; color: #1d2327;">Top Acciones</div>
            <div style="font-size: 12px; color: #666;">
                <?php foreach (array_slice($stats['by_action'], 0, 3) as $action): ?>
                    <?php echo esc_html(Riverso_POS_Audit::ACTIONS[$action->action] ?? $action->action); ?>: <?php echo $action->count; ?><br>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($stats['by_user'])): ?>
        <div class="stat-card" style="background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="font-size: 14px; font-weight: bold; color: #1d2327;">Usuarios Activos</div>
            <div style="font-size: 12px; color: #666;">
                <?php foreach (array_slice($stats['by_user'], 0, 3) as $user): ?>
                    <?php echo esc_html($user->user_name); ?>: <?php echo $user->count; ?><br>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Filtros -->
    <form method="get" class="audit-filters" style="background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <input type="hidden" name="page" value="riverso-pos-audit">
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Tipo de Acción</label>
                <select name="action_filter" style="min-width: 180px;">
                    <option value="">Todas las acciones</option>
                    <?php foreach (Riverso_POS_Audit::ACTIONS as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['action'], $key); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Tipo de Entidad</label>
                <select name="entity_type" style="min-width: 150px;">
                    <option value="">Todas las entidades</option>
                    <?php foreach (Riverso_POS_Audit::ENTITIES as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['entity_type'], $key); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Usuario</label>
                <select name="user_id" style="min-width: 150px;">
                    <option value="">Todos los usuarios</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user->ID; ?>" <?php selected($filters['user_id'], $user->ID); ?>>
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Desde</label>
                <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>">
            </div>
            
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Hasta</label>
                <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>">
            </div>
            
            <div>
                <label style="display: block; font-size: 12px; margin-bottom: 3px;">Buscar</label>
                <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Nombre, detalles..." style="width: 200px;">
            </div>
            
            <div>
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-search" style="vertical-align: middle;"></span> Filtrar
                </button>
                <a href="<?php echo admin_url('admin.php?page=riverso-pos-audit'); ?>" class="button">Limpiar</a>
            </div>
        </div>
    </form>
    
    <!-- Tabla de resultados -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 140px;">Fecha/Hora</th>
                <th style="width: 120px;">Usuario</th>
                <th style="width: 150px;">Acción</th>
                <th style="width: 100px;">Entidad</th>
                <th>Detalles</th>
                <th style="width: 100px;">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($result['items'])): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        No hay registros de auditoría con los filtros seleccionados.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $log): ?>
                    <tr>
                        <td>
                            <strong><?php echo date_i18n('d/m/Y', strtotime($log->created_at)); ?></strong><br>
                            <span style="color: #666; font-size: 12px;"><?php echo date_i18n('H:i:s', strtotime($log->created_at)); ?></span>
                        </td>
                        <td>
                            <?php echo esc_html($log->user_name); ?>
                            <?php if ($log->user_role && $log->user_role !== 'none'): ?>
                                <br><span style="font-size: 11px; color: #666;"><?php echo esc_html($log->user_role); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="action-badge action-<?php echo esc_attr(str_replace('_', '-', $log->action)); ?>">
                                <?php echo esc_html($log->action_label); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo esc_html($log->entity_label); ?>
                            <?php if ($log->entity_id): ?>
                                <br><span style="font-size: 11px; color: #666;">#<?php echo $log->entity_id; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log->entity_name): ?>
                                <strong><?php echo esc_html($log->entity_name); ?></strong><br>
                            <?php endif; ?>
                            <?php if ($log->details): ?>
                                <span style="font-size: 12px; color: #666;"><?php echo esc_html($log->details); ?></span>
                            <?php endif; ?>
                            <?php if ($log->old_value_decoded || $log->new_value_decoded): ?>
                                <button type="button" class="button button-small btn-show-changes" 
                                        data-old='<?php echo esc_attr(json_encode($log->old_value_decoded)); ?>'
                                        data-new='<?php echo esc_attr(json_encode($log->new_value_decoded)); ?>'
                                        style="margin-top: 5px;">
                                    Ver cambios
                                </button>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 11px; color: #666;">
                            <?php echo esc_html($log->ip_address); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Paginación -->
    <?php if ($result['pages'] > 1): ?>
        <div class="tablenav" style="margin-top: 15px;">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo number_format($result['total']); ?> registros</span>
                <span class="pagination-links">
                    <?php
                    $base_url = add_query_arg([
                        'page' => 'riverso-pos-audit',
                        'action_filter' => $filters['action'],
                        'entity_type' => $filters['entity_type'],
                        'user_id' => $filters['user_id'],
                        'date_from' => $filters['date_from'],
                        'date_to' => $filters['date_to'],
                        'search' => $filters['search'],
                    ], admin_url('admin.php'));
                    
                    if ($page > 1): ?>
                        <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base_url)); ?>">
                            <span aria-hidden="true">‹</span>
                        </a>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?php echo $page; ?> de <span class="total-pages"><?php echo $result['pages']; ?></span>
                        </span>
                    </span>
                    
                    <?php if ($page < $result['pages']): ?>
                        <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base_url)); ?>">
                            <span aria-hidden="true">›</span>
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal cambios -->
<div id="changes-modal" class="riverso-modal" style="display: none;">
    <div class="riverso-modal-content" style="max-width: 800px;">
        <div class="riverso-modal-header">
            <h2>Detalles del Cambio</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div id="changes-content" style="padding: 20px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div>
                    <h3 style="margin-top: 0; color: #d63638;">Valor Anterior</h3>
                    <pre id="old-value" style="background: #fef2f2; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px; font-size: 12px;"></pre>
                </div>
                <div>
                    <h3 style="margin-top: 0; color: #00a32a;">Valor Nuevo</h3>
                    <pre id="new-value" style="background: #f0fdf4; padding: 15px; border-radius: 4px; overflow: auto; max-height: 400px; font-size: 12px;"></pre>
                </div>
            </div>
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
}
.riverso-modal-close:hover {
    color: #d63638;
}
.action-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    background: #e5e7eb;
    color: #374151;
}
.action-badge[class*="created"], .action-badge[class*="registered"] {
    background: #d1fae5;
    color: #065f46;
}
.action-badge[class*="updated"], .action-badge[class*="changed"], .action-badge[class*="adjusted"] {
    background: #dbeafe;
    color: #1e40af;
}
.action-badge[class*="deleted"], .action-badge[class*="rejected"], .action-badge[class*="voided"] {
    background: #fee2e2;
    color: #991b1b;
}
.action-badge[class*="approved"], .action-badge[class*="completed"] {
    background: #dcfce7;
    color: #166534;
}
.action-badge[class*="assigned"] {
    background: #fef3c7;
    color: #92400e;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Modal cambios
    $('.btn-show-changes').on('click', function() {
        const oldVal = $(this).data('old');
        const newVal = $(this).data('new');
        
        $('#old-value').text(oldVal ? JSON.stringify(oldVal, null, 2) : '(Sin valor anterior)');
        $('#new-value').text(newVal ? JSON.stringify(newVal, null, 2) : '(Sin valor nuevo)');
        
        $('#changes-modal').show();
    });
    
    $('.riverso-modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    $('.riverso-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
});
</script>
