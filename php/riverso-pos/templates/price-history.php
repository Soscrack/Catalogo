<?php
/**
 * Shell Centro de Precios
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Riverso_Price_History_Module')) {
    require_once RIVERSO_POS_PLUGIN_DIR . 'modules/pricing/class-price-history-module.php';
}

$stats = Riverso_Price_History_Module::get_instance()->get_stats();
?>

<div class="wrap riverso-price-history">
    <h1>
        <span class="dashicons dashicons-tag"></span>
        Centro de Precios
    </h1>

    <div class="cost-stats-grid price-stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format_i18n($stats['entries_this_month']); ?></span>
                <span class="stat-label">Cambios este mes</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-products"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format_i18n($stats['products_tracked']); ?></span>
                <span class="stat-label">Productos con historial</span>
            </div>
        </div>
        <div class="stat-card <?php echo $stats['margin_alerts'] > 0 ? 'danger' : 'success'; ?>">
            <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format_i18n($stats['margin_alerts']); ?></span>
                <span class="stat-label">Alertas de margen</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-media-spreadsheet"></span></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format_i18n($stats['folios_analyzed']); ?></span>
                <span class="stat-label">Folios analizados</span>
            </div>
        </div>
    </div>

    <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/price-history-app.php'; ?>
</div>
