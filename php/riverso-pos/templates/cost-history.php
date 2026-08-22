<?php
/**
 * Cost History Template - Historial de Costos
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get suppliers for filter
global $wpdb;
$suppliers_table = $wpdb->prefix . 'riverso_proveedores';
$suppliers = $wpdb->get_results("SELECT id, nombre, rut FROM {$suppliers_table} WHERE activo = 1 ORDER BY nombre", ARRAY_A);

// Get stats
$cost_module = Riverso_Cost_History_Module::get_instance();
$stats = $cost_module->get_stats();
?>

<div class="wrap riverso-cost-history">
    <h1>
        <span class="dashicons dashicons-chart-line"></span>
        Historial de Costos
    </h1>
    
    <!-- Stats Cards -->
    <div class="cost-stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format($stats['entries_this_month']); ?></span>
                <span class="stat-label">Registros este mes</span>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-products"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format($stats['products_tracked']); ?></span>
                <span class="stat-label">Productos con historial</span>
            </div>
        </div>
        
        <div class="stat-card warning">
            <div class="stat-icon"><span class="dashicons dashicons-arrow-up-alt"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format($stats['price_increases']); ?></span>
                <span class="stat-label">Alzas >10% (30 días)</span>
            </div>
        </div>
        
        <div class="stat-card <?php echo $stats['margin_alerts'] > 0 ? 'danger' : 'success'; ?>">
            <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format($stats['margin_alerts']); ?></span>
                <span class="stat-label">Alertas de margen</span>
            </div>
        </div>
    </div>
    
    <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/cost-history-app.php'; ?>
</div>
