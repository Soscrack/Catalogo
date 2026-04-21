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
    
    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="history">
            <span class="dashicons dashicons-list-view"></span> Historial
        </a>
        <a href="#" class="nav-tab" data-tab="analysis">
            <span class="dashicons dashicons-chart-area"></span> Análisis
        </a>
        <a href="#" class="nav-tab" data-tab="alerts">
            <span class="dashicons dashicons-warning"></span> Alertas
            <?php if ($stats['margin_alerts'] > 0): ?>
                <span class="alert-badge"><?php echo $stats['margin_alerts']; ?></span>
            <?php endif; ?>
        </a>
        <a href="#" class="nav-tab" data-tab="add">
            <span class="dashicons dashicons-plus-alt"></span> Registrar Costo
        </a>
    </nav>
    
    <!-- Tab: History -->
    <div class="tab-content" id="tab-history">
        <!-- Filters -->
        <div class="cost-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" id="filter-search" placeholder="Producto, código, proveedor...">
                </div>
                
                <div class="filter-group">
                    <label>Proveedor</label>
                    <select id="filter-supplier">
                        <option value="">Todos</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo esc_attr($sup['id']); ?>">
                                <?php echo esc_html($sup['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Origen</label>
                    <select id="filter-source">
                        <option value="">Todos</option>
                        <option value="manual">Manual</option>
                        <option value="invoice">Factura</option>
                        <option value="quote">Cotización</option>
                        <option value="import">Importación</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Desde</label>
                    <input type="date" id="filter-date-from">
                </div>
                
                <div class="filter-group">
                    <label>Hasta</label>
                    <input type="date" id="filter-date-to">
                </div>
                
                <div class="filter-group filter-actions">
                    <button type="button" class="button" id="btn-filter-apply">
                        <span class="dashicons dashicons-search"></span> Filtrar
                    </button>
                    <button type="button" class="button" id="btn-filter-clear">
                        <span class="dashicons dashicons-dismiss"></span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- History Table -->
        <div class="table-container">
            <table class="wp-list-table widefat fixed striped" id="cost-history-table">
                <thead>
                    <tr>
                        <th class="column-date sortable" data-sort="document_date">Fecha</th>
                        <th class="column-product">Producto</th>
                        <th class="column-sku">SKU</th>
                        <th class="column-supplier">Proveedor</th>
                        <th class="column-cost sortable" data-sort="cost">Costo</th>
                        <th class="column-price">Precio Venta</th>
                        <th class="column-margin">Margen</th>
                        <th class="column-source">Origen</th>
                        <th class="column-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody id="cost-history-body">
                    <tr class="loading-row">
                        <td colspan="9">
                            <span class="spinner is-active"></span> Cargando historial...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num" id="history-count">0 items</span>
                <span class="pagination-links">
                    <button class="button" id="btn-prev-page" disabled>&laquo; Anterior</button>
                    <span class="paging-input">
                        Página <span id="current-page">1</span> de <span id="total-pages">1</span>
                    </span>
                    <button class="button" id="btn-next-page" disabled>Siguiente &raquo;</button>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Tab: Analysis -->
    <div class="tab-content" id="tab-analysis" style="display: none;">
        <div class="analysis-container">
            <div class="analysis-search">
                <h3>Analizar Producto</h3>
                <div class="search-row">
                    <input type="text" id="analysis-product-search" placeholder="Buscar por nombre o SKU..." class="large-text">
                    <button type="button" class="button button-primary" id="btn-analyze">
                        <span class="dashicons dashicons-chart-area"></span> Analizar
                    </button>
                </div>
                <div id="product-search-results" class="search-results"></div>
            </div>
            
            <div id="analysis-results" style="display: none;">
                <div class="analysis-header">
                    <h3 id="analysis-product-name">-</h3>
                    <span class="analysis-sku" id="analysis-product-sku">-</span>
                </div>
                
                <div class="analysis-grid">
                    <div class="analysis-card">
                        <h4>Resumen General</h4>
                        <div class="analysis-stats" id="overall-stats">
                            <div class="stat-row">
                                <span>Total registros:</span>
                                <span id="stat-total">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Costo mínimo:</span>
                                <span id="stat-min">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Costo máximo:</span>
                                <span id="stat-max">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Costo promedio:</span>
                                <span id="stat-avg">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Primer registro:</span>
                                <span id="stat-first-date">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Último registro:</span>
                                <span id="stat-last-date">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="analysis-card">
                        <h4>Precio y Margen Actual</h4>
                        <div class="analysis-stats" id="margin-stats">
                            <div class="stat-row">
                                <span>Precio de venta:</span>
                                <span id="stat-price">-</span>
                            </div>
                            <div class="stat-row">
                                <span>Costo más bajo (actual):</span>
                                <span id="stat-lowest-cost">-</span>
                            </div>
                            <div class="stat-row highlight">
                                <span>Margen:</span>
                                <span id="stat-margin">-</span>
                            </div>
                            <div id="margin-alert" class="margin-alert" style="display: none;">
                                <span class="dashicons dashicons-warning"></span>
                                Margen por debajo del umbral (33%)
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="analysis-card full-width">
                    <h4>Comparación por Proveedor</h4>
                    <table class="wp-list-table widefat fixed striped" id="supplier-comparison-table">
                        <thead>
                            <tr>
                                <th>Proveedor</th>
                                <th>Registros</th>
                                <th>Costo Mín</th>
                                <th>Costo Máx</th>
                                <th>Costo Prom</th>
                                <th>Último Costo</th>
                                <th>Primera Compra</th>
                                <th>Última Compra</th>
                            </tr>
                        </thead>
                        <tbody id="supplier-comparison-body">
                        </tbody>
                    </table>
                </div>
                
                <div class="analysis-card full-width">
                    <h4>Evolución de Costos</h4>
                    <div class="chart-controls">
                        <select id="chart-period">
                            <option value="6">Últimos 6 meses</option>
                            <option value="12" selected>Último año</option>
                            <option value="24">Últimos 2 años</option>
                        </select>
                    </div>
                    <div id="cost-chart-container">
                        <canvas id="cost-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tab: Alerts -->
    <div class="tab-content" id="tab-alerts" style="display: none;">
        <div class="alerts-header">
            <h3>
                <span class="dashicons dashicons-warning"></span>
                Productos con Margen Bajo
            </h3>
            <p class="description">
                Productos donde el precio de venta es menor a 1.5x el último costo registrado (margen < 33%).
            </p>
        </div>
        
        <div class="alerts-controls">
            <label>
                Umbral de margen:
                <select id="alert-threshold">
                    <option value="1.3">30% (1.3x)</option>
                    <option value="1.5" selected>33% (1.5x)</option>
                    <option value="2.0">50% (2.0x)</option>
                </select>
            </label>
            <button type="button" class="button" id="btn-refresh-alerts">
                <span class="dashicons dashicons-update"></span> Actualizar
            </button>
        </div>
        
        <div class="table-container">
            <table class="wp-list-table widefat fixed striped" id="alerts-table">
                <thead>
                    <tr>
                        <th class="column-product">Producto</th>
                        <th class="column-sku">SKU</th>
                        <th class="column-supplier">Proveedor</th>
                        <th class="column-cost">Último Costo</th>
                        <th class="column-price">Precio Venta</th>
                        <th class="column-margin">Margen %</th>
                        <th class="column-date">Fecha Costo</th>
                        <th class="column-actions">Acciones</th>
                    </tr>
                </thead>
                <tbody id="alerts-body">
                    <tr class="loading-row">
                        <td colspan="8">
                            <span class="spinner is-active"></span> Cargando alertas...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Tab: Add Cost -->
    <div class="tab-content" id="tab-add" style="display: none;">
        <div class="add-cost-container">
            <h3>
                <span class="dashicons dashicons-plus-alt"></span>
                Registrar Costo Manualmente
            </h3>
            
            <form id="add-cost-form" class="cost-form">
                <div class="form-row">
                    <div class="form-group large">
                        <label for="add-product">Producto *</label>
                        <input type="text" id="add-product-search" placeholder="Buscar producto..." class="large-text">
                        <input type="hidden" id="add-product-id" name="product_id">
                        <div id="add-product-results" class="search-results"></div>
                        <div id="add-product-selected" class="selected-product" style="display: none;">
                            <span class="product-name"></span>
                            <span class="product-sku"></span>
                            <button type="button" class="button-link clear-product">×</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="add-supplier">Proveedor</label>
                        <select id="add-supplier" name="supplier_id">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo esc_attr($sup['id']); ?>">
                                    <?php echo esc_html($sup['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add-supplier-code">Código Proveedor</label>
                        <input type="text" id="add-supplier-code" name="supplier_code" placeholder="Ej: ABC-123">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="add-cost">Costo Total *</label>
                        <input type="number" id="add-cost" name="cost" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add-quantity">Cantidad</label>
                        <input type="number" id="add-quantity" name="quantity" value="1" step="0.01" min="0.01">
                    </div>
                    
                    <div class="form-group">
                        <label for="add-unit-cost">Costo Unitario</label>
                        <input type="text" id="add-unit-cost" readonly class="readonly">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="add-date">Fecha Documento *</label>
                        <input type="date" id="add-date" name="document_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="add-source">Origen</label>
                        <select id="add-source" name="source_type">
                            <option value="manual">Manual</option>
                            <option value="invoice">Factura</option>
                            <option value="quote">Cotización</option>
                            <option value="import">Importación</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="add-currency">Moneda</label>
                        <select id="add-currency" name="currency">
                            <option value="CLP" selected>CLP</option>
                            <option value="USD">USD</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group full">
                        <label for="add-notes">Notas</label>
                        <textarea id="add-notes" name="notes" rows="3" placeholder="Observaciones opcionales..."></textarea>
                    </div>
                </div>
                
                <!-- Cost Comparison Preview -->
                <div id="cost-comparison-preview" class="comparison-preview" style="display: none;">
                    <h4>Comparación con costo anterior</h4>
                    <div class="comparison-content">
                        <div class="comparison-item">
                            <span class="label">Costo anterior:</span>
                            <span class="value" id="prev-cost">-</span>
                        </div>
                        <div class="comparison-item">
                            <span class="label">Fecha anterior:</span>
                            <span class="value" id="prev-date">-</span>
                        </div>
                        <div class="comparison-item highlight">
                            <span class="label">Diferencia:</span>
                            <span class="value" id="cost-diff">-</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-saved"></span> Guardar Costo
                    </button>
                    <button type="reset" class="button button-large">Limpiar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Product Detail Modal -->
<div id="cost-detail-modal" class="riverso-modal" style="display: none;">
    <div class="modal-content large">
        <div class="modal-header">
            <h2>Detalle de Costo</h2>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div id="cost-detail-content">
                <!-- Filled by JS -->
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="button modal-close">Cerrar</button>
        </div>
    </div>
</div>

<style>
.riverso-cost-history h1 {
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Stats Grid */
.cost-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.stat-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.stat-card .stat-icon {
    background: #f0f0f1;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-card .stat-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #2271b1;
}

.stat-card .stat-content {
    display: flex;
    flex-direction: column;
}

.stat-card .stat-number {
    font-size: 24px;
    font-weight: 600;
    color: #1d2327;
}

.stat-card .stat-label {
    font-size: 13px;
    color: #646970;
}

.stat-card.warning .stat-icon { background: #fcf0e3; }
.stat-card.warning .stat-icon .dashicons { color: #d63638; }
.stat-card.danger .stat-icon { background: #fcf0f1; }
.stat-card.danger .stat-icon .dashicons { color: #d63638; }
.stat-card.danger .stat-number { color: #d63638; }
.stat-card.success .stat-icon { background: #edfaef; }
.stat-card.success .stat-icon .dashicons { color: #00a32a; }

/* Tabs */
.nav-tab-wrapper {
    margin-bottom: 20px;
}

.nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.nav-tab .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.alert-badge {
    background: #d63638;
    color: #fff;
    border-radius: 10px;
    padding: 1px 7px;
    font-size: 11px;
    margin-left: 5px;
}

/* Filters */
.cost-filters {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 500;
    color: #646970;
}

.filter-group input[type="text"],
.filter-group input[type="date"],
.filter-group select {
    min-width: 150px;
}

.filter-actions {
    display: flex;
    gap: 5px;
    flex-direction: row;
    align-items: center;
}

/* Table */
.table-container {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

#cost-history-table th.sortable {
    cursor: pointer;
}

#cost-history-table th.sortable:hover {
    background: #f0f0f1;
}

.column-date { width: 100px; }
.column-sku { width: 120px; }
.column-supplier { width: 150px; }
.column-cost, .column-price { width: 100px; text-align: right; }
.column-margin { width: 80px; text-align: center; }
.column-source { width: 100px; }
.column-actions { width: 100px; text-align: center; }

.margin-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
}

.margin-good { background: #edfaef; color: #00a32a; }
.margin-warning { background: #fcf0e3; color: #996800; }
.margin-danger { background: #fcf0f1; color: #d63638; }

.source-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    background: #f0f0f1;
    color: #646970;
}

.source-invoice { background: #e7f5fe; color: #0073aa; }
.source-quote { background: #fef8e7; color: #996800; }
.source-import { background: #f0e7fe; color: #7c3aed; }

.loading-row td {
    text-align: center;
    padding: 30px;
}

.loading-row .spinner {
    float: none;
    margin: 0 10px 0 0;
}

/* Analysis Tab */
.analysis-container {
    max-width: 1200px;
}

.analysis-search {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.search-row {
    display: flex;
    gap: 10px;
}

.search-row input {
    flex: 1;
}

.search-results {
    position: absolute;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 100;
    width: 100%;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    display: none;
}

.search-results.active {
    display: block;
}

.search-result-item {
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f1;
}

.search-result-item:hover {
    background: #f0f6fc;
}

.search-result-item .product-name {
    font-weight: 500;
}

.search-result-item .product-sku {
    font-size: 12px;
    color: #646970;
}

.analysis-header {
    margin-bottom: 20px;
}

.analysis-header h3 {
    margin: 0 0 5px 0;
}

.analysis-sku {
    color: #646970;
    font-size: 14px;
}

.analysis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.analysis-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.analysis-card.full-width {
    grid-column: 1 / -1;
}

.analysis-card h4 {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f1;
}

.analysis-stats .stat-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f1;
}

.analysis-stats .stat-row:last-child {
    border-bottom: none;
}

.analysis-stats .stat-row.highlight {
    background: #f0f6fc;
    margin: 10px -20px -20px;
    padding: 15px 20px;
    border-radius: 0 0 4px 4px;
}

.margin-alert {
    background: #fcf0f1;
    color: #d63638;
    padding: 10px;
    border-radius: 4px;
    margin-top: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-controls {
    margin-bottom: 15px;
}

#cost-chart-container {
    height: 300px;
    position: relative;
}

/* Alerts Tab */
.alerts-header {
    margin-bottom: 20px;
}

.alerts-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 5px 0;
}

.alerts-controls {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 20px;
}

/* Add Cost Form */
.add-cost-container {
    max-width: 800px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 25px;
}

.add-cost-container h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 20px 0;
    padding-bottom: 15px;
    border-bottom: 1px solid #f0f0f1;
}

.cost-form .form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.cost-form .form-group {
    flex: 1;
    position: relative;
}

.cost-form .form-group.large {
    flex: 2;
}

.cost-form .form-group.full {
    flex: 1 0 100%;
}

.cost-form label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.cost-form input[type="text"],
.cost-form input[type="number"],
.cost-form input[type="date"],
.cost-form select,
.cost-form textarea {
    width: 100%;
}

.cost-form input.readonly {
    background: #f0f0f1;
}

.selected-product {
    background: #f0f6fc;
    padding: 10px 15px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

.selected-product .product-name {
    font-weight: 500;
}

.selected-product .product-sku {
    color: #646970;
    font-size: 13px;
}

.selected-product .clear-product {
    margin-left: auto;
    font-size: 20px;
    color: #646970;
}

.comparison-preview {
    background: #f0f6fc;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px 20px;
    margin-bottom: 20px;
}

.comparison-preview h4 {
    margin: 0 0 15px 0;
}

.comparison-content {
    display: flex;
    gap: 30px;
}

.comparison-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.comparison-item .label {
    font-size: 12px;
    color: #646970;
}

.comparison-item .value {
    font-weight: 600;
}

.comparison-item.highlight .value.increase {
    color: #d63638;
}

.comparison-item.highlight .value.decrease {
    color: #00a32a;
}

.form-actions {
    display: flex;
    gap: 10px;
    padding-top: 20px;
    border-top: 1px solid #f0f0f1;
}

/* Modal */
.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: #fff;
    border-radius: 4px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}

.modal-content.large {
    max-width: 900px;
}

.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ccd0d4;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #646970;
}

.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ccd0d4;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Responsive */
@media (max-width: 782px) {
    .cost-stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .cost-form .form-row {
        flex-direction: column;
    }
    
    .analysis-grid {
        grid-template-columns: 1fr;
    }
    
    .comparison-content {
        flex-direction: column;
        gap: 15px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let currentPage = 1;
    let currentSort = { field: 'document_date', order: 'DESC' };
    let selectedProductId = null;
    let analysisProductId = null;
    
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').hide();
        $('#tab-' + tab).show();
        
        if (tab === 'alerts') {
            loadAlerts();
        }
    });
    
    // Load history on init
    loadHistory();
    
    // Filter handlers
    $('#btn-filter-apply').on('click', function() {
        currentPage = 1;
        loadHistory();
    });
    
    $('#btn-filter-clear').on('click', function() {
        $('#filter-search, #filter-supplier, #filter-source, #filter-date-from, #filter-date-to').val('');
        currentPage = 1;
        loadHistory();
    });
    
    // Sorting
    $('#cost-history-table th.sortable').on('click', function() {
        const field = $(this).data('sort');
        if (currentSort.field === field) {
            currentSort.order = currentSort.order === 'DESC' ? 'ASC' : 'DESC';
        } else {
            currentSort.field = field;
            currentSort.order = 'DESC';
        }
        loadHistory();
    });
    
    // Pagination
    $('#btn-prev-page').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            loadHistory();
        }
    });
    
    $('#btn-next-page').on('click', function() {
        currentPage++;
        loadHistory();
    });
    
    function loadHistory() {
        const $tbody = $('#cost-history-body');
        $tbody.html('<tr class="loading-row"><td colspan="9"><span class="spinner is-active"></span> Cargando...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'riverso_get_cost_history',
            nonce: nonce,
            search: $('#filter-search').val(),
            supplier_id: $('#filter-supplier').val(),
            source_type: $('#filter-source').val(),
            date_from: $('#filter-date-from').val(),
            date_to: $('#filter-date-to').val(),
            orderby: currentSort.field,
            order: currentSort.order,
            limit: 50,
            offset: (currentPage - 1) * 50
        }, function(response) {
            if (response.success) {
                renderHistory(response.data.history);
                updatePagination(response.data.total, response.data.pages);
            } else {
                $tbody.html('<tr><td colspan="9">Error: ' + response.data + '</td></tr>');
            }
        });
    }
    
    function renderHistory(entries) {
        const $tbody = $('#cost-history-body');
        
        if (entries.length === 0) {
            $tbody.html('<tr><td colspan="9" style="text-align:center;padding:30px;">No hay registros</td></tr>');
            return;
        }
        
        let html = '';
        entries.forEach(function(entry) {
            const marginClass = entry.margin_alert ? 'margin-danger' : 
                               (entry.margin > 40 ? 'margin-good' : 'margin-warning');
            const sourceClass = 'source-' + entry.source_type;
            
            html += `<tr data-id="${entry.id}">
                <td>${entry.document_date}</td>
                <td>${entry.product_name || '-'}</td>
                <td><code>${entry.product_sku || '-'}</code></td>
                <td>${entry.supplier_name || '-'}</td>
                <td style="text-align:right">$${formatNumber(entry.cost)}</td>
                <td style="text-align:right">${entry.current_price ? '$' + formatNumber(entry.current_price) : '-'}</td>
                <td style="text-align:center">
                    ${entry.margin !== null ? 
                        `<span class="margin-badge ${marginClass}">${entry.margin}%</span>` : '-'}
                </td>
                <td><span class="source-badge ${sourceClass}">${entry.source_type}</span></td>
                <td style="text-align:center">
                    <button type="button" class="button button-small btn-view-detail" data-id="${entry.id}">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                    <button type="button" class="button button-small btn-delete-cost" data-id="${entry.id}">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
        });
        
        $tbody.html(html);
    }
    
    function updatePagination(total, pages) {
        $('#history-count').text(total + ' registros');
        $('#current-page').text(currentPage);
        $('#total-pages').text(pages);
        $('#btn-prev-page').prop('disabled', currentPage <= 1);
        $('#btn-next-page').prop('disabled', currentPage >= pages);
    }
    
    // Delete cost entry
    $(document).on('click', '.btn-delete-cost', function() {
        const id = $(this).data('id');
        if (!confirm('¿Eliminar este registro de costo?')) return;
        
        $.post(ajaxurl, {
            action: 'riverso_delete_cost_entry',
            nonce: nonce,
            id: id
        }, function(response) {
            if (response.success) {
                loadHistory();
            } else {
                alert('Error: ' + response.data);
            }
        });
    });
    
    // Product search for analysis
    let searchTimeout;
    $('#analysis-product-search').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val();
        
        if (query.length < 2) {
            $('#product-search-results').removeClass('active');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query, '#product-search-results', function(product) {
                analysisProductId = product.id;
                $('#analysis-product-search').val(product.name);
                $('#product-search-results').removeClass('active');
                loadProductAnalysis(product.id);
            });
        }, 300);
    });
    
    function searchProducts(query, resultsContainer, onSelect) {
        $.post(ajaxurl, {
            action: 'woocommerce_json_search_products',
            security: '<?php echo wp_create_nonce('search-products'); ?>',
            term: query,
            limit: 10
        }, function(products) {
            const $results = $(resultsContainer);
            let html = '';
            
            for (const id in products) {
                html += `<div class="search-result-item" data-id="${id}">
                    <span class="product-name">${products[id]}</span>
                </div>`;
            }
            
            $results.html(html).addClass('active');
            
            $results.find('.search-result-item').on('click', function() {
                const productId = $(this).data('id');
                const productName = $(this).find('.product-name').text();
                onSelect({ id: productId, name: productName });
            });
        });
    }
    
    // Hide search results on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.search-results, input[type="text"]').length) {
            $('.search-results').removeClass('active');
        }
    });
    
    function loadProductAnalysis(productId) {
        $.post(ajaxurl, {
            action: 'riverso_get_product_cost_analysis',
            nonce: nonce,
            product_id: productId
        }, function(response) {
            if (response.success) {
                renderAnalysis(response.data);
                $('#analysis-results').show();
            } else {
                alert('Error: ' + response.data);
            }
        });
    }
    
    function renderAnalysis(data) {
        if (data.product) {
            $('#analysis-product-name').text(data.product.name);
            $('#analysis-product-sku').text('SKU: ' + (data.product.sku || '-'));
            $('#stat-price').text('$' + formatNumber(data.product.price));
            $('#stat-lowest-cost').text(data.product.lowest_current_cost ? '$' + formatNumber(data.product.lowest_current_cost) : '-');
            $('#stat-margin').text(data.product.margin !== null ? data.product.margin + '%' : '-');
            
            if (data.product.margin_alert) {
                $('#margin-alert').show();
            } else {
                $('#margin-alert').hide();
            }
        }
        
        if (data.overall) {
            $('#stat-total').text(data.overall.total_entries);
            $('#stat-min').text('$' + formatNumber(data.overall.min_cost));
            $('#stat-max').text('$' + formatNumber(data.overall.max_cost));
            $('#stat-avg').text('$' + formatNumber(Math.round(data.overall.avg_cost)));
            $('#stat-first-date').text(data.overall.first_date || '-');
            $('#stat-last-date').text(data.overall.last_date || '-');
        }
        
        // Supplier comparison
        let suppHtml = '';
        if (data.by_supplier && data.by_supplier.length > 0) {
            data.by_supplier.forEach(function(s) {
                suppHtml += `<tr>
                    <td>${s.supplier_name || 'Sin proveedor'}</td>
                    <td>${s.entry_count}</td>
                    <td>$${formatNumber(s.min_cost)}</td>
                    <td>$${formatNumber(s.max_cost)}</td>
                    <td>$${formatNumber(Math.round(s.avg_cost))}</td>
                    <td><strong>$${formatNumber(s.latest_cost)}</strong></td>
                    <td>${s.first_date}</td>
                    <td>${s.last_date}</td>
                </tr>`;
            });
        } else {
            suppHtml = '<tr><td colspan="8" style="text-align:center">Sin datos</td></tr>';
        }
        $('#supplier-comparison-body').html(suppHtml);
        
        // Load chart
        loadCostChart(data.product.id);
    }
    
    function loadCostChart(productId) {
        const months = $('#chart-period').val();
        
        $.post(ajaxurl, {
            action: 'riverso_get_cost_chart_data',
            nonce: nonce,
            product_id: productId,
            months: months
        }, function(response) {
            if (response.success) {
                renderChart(response.data);
            }
        });
    }
    
    let costChart = null;
    function renderChart(data) {
        const ctx = document.getElementById('cost-chart');
        if (!ctx) return;
        
        if (costChart) {
            costChart.destroy();
        }
        
        if (typeof Chart === 'undefined') {
            $('#cost-chart-container').html('<p>Chart.js no está cargado</p>');
            return;
        }
        
        const datasets = data.map((supplier, idx) => ({
            label: supplier.supplier_name,
            data: supplier.data.map(d => ({ x: d.date, y: d.cost })),
            borderColor: getColor(idx),
            backgroundColor: getColor(idx, 0.1),
            fill: false,
            tension: 0.1
        }));
        
        costChart = new Chart(ctx, {
            type: 'line',
            data: { datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: { unit: 'month' }
                    },
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return '$' + formatNumber(value);
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': $' + formatNumber(context.raw.y);
                            }
                        }
                    }
                }
            }
        });
    }
    
    function getColor(index, alpha = 1) {
        const colors = [
            `rgba(33, 113, 177, ${alpha})`,
            `rgba(0, 163, 42, ${alpha})`,
            `rgba(214, 54, 56, ${alpha})`,
            `rgba(153, 104, 0, ${alpha})`,
            `rgba(124, 58, 237, ${alpha})`
        ];
        return colors[index % colors.length];
    }
    
    // Chart period change
    $('#chart-period').on('change', function() {
        if (analysisProductId) {
            loadCostChart(analysisProductId);
        }
    });
    
    // Alerts tab
    function loadAlerts() {
        const threshold = $('#alert-threshold').val();
        const $tbody = $('#alerts-body');
        
        $tbody.html('<tr class="loading-row"><td colspan="8"><span class="spinner is-active"></span> Cargando...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'riverso_get_margin_alerts',
            nonce: nonce,
            threshold: threshold
        }, function(response) {
            if (response.success) {
                renderAlerts(response.data.alerts);
            } else {
                $tbody.html('<tr><td colspan="8">Error: ' + response.data + '</td></tr>');
            }
        });
    }
    
    function renderAlerts(alerts) {
        const $tbody = $('#alerts-body');
        
        if (alerts.length === 0) {
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:30px;">🎉 No hay alertas de margen bajo</td></tr>');
            return;
        }
        
        let html = '';
        alerts.forEach(function(alert) {
            html += `<tr>
                <td>${alert.product_name || '-'}</td>
                <td><code>${alert.sku || '-'}</code></td>
                <td>${alert.supplier_name || '-'}</td>
                <td style="text-align:right">$${formatNumber(alert.latest_cost)}</td>
                <td style="text-align:right">$${formatNumber(alert.current_price)}</td>
                <td style="text-align:center">
                    <span class="margin-badge margin-danger">${alert.margin_percent}%</span>
                </td>
                <td>${alert.cost_date}</td>
                <td>
                    <a href="<?php echo admin_url('post.php?action=edit&post='); ?>${alert.product_id}" 
                       class="button button-small" target="_blank">
                        Editar precio
                    </a>
                </td>
            </tr>`;
        });
        
        $tbody.html(html);
    }
    
    $('#btn-refresh-alerts, #alert-threshold').on('change click', loadAlerts);
    
    // Add cost form
    $('#add-product-search').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val();
        
        if (query.length < 2) {
            $('#add-product-results').removeClass('active');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            searchProducts(query, '#add-product-results', function(product) {
                selectedProductId = product.id;
                $('#add-product-id').val(product.id);
                $('#add-product-search').hide();
                $('#add-product-selected').show()
                    .find('.product-name').text(product.name);
                $('#add-product-results').removeClass('active');
            });
        }, 300);
    });
    
    $('.clear-product').on('click', function() {
        selectedProductId = null;
        $('#add-product-id').val('');
        $('#add-product-search').val('').show();
        $('#add-product-selected').hide();
        $('#cost-comparison-preview').hide();
    });
    
    // Calculate unit cost
    $('#add-cost, #add-quantity').on('input', function() {
        const cost = parseFloat($('#add-cost').val()) || 0;
        const qty = parseFloat($('#add-quantity').val()) || 1;
        $('#add-unit-cost').val(qty > 0 ? '$' + formatNumber(cost / qty) : '-');
        
        // Check comparison
        if (selectedProductId && cost > 0) {
            checkCostComparison(cost / qty);
        }
    });
    
    function checkCostComparison(unitCost) {
        $.post(ajaxurl, {
            action: 'riverso_get_cost_comparison',
            nonce: nonce,
            product_id: selectedProductId,
            supplier_id: $('#add-supplier').val(),
            cost: unitCost
        }, function(response) {
            if (response.success && response.data.status !== 'first_entry') {
                const data = response.data;
                $('#prev-cost').text('$' + formatNumber(data.previous_cost));
                $('#prev-date').text(data.previous_date);
                
                const diffText = (data.percentage >= 0 ? '+' : '') + data.percentage + '%';
                const diffClass = data.percentage > 0 ? 'increase' : 'decrease';
                $('#cost-diff').text(diffText).removeClass('increase decrease').addClass(diffClass);
                
                $('#cost-comparison-preview').show();
            } else {
                $('#cost-comparison-preview').hide();
            }
        });
    }
    
    // Submit form
    $('#add-cost-form').on('submit', function(e) {
        e.preventDefault();
        
        if (!selectedProductId) {
            alert('Seleccione un producto');
            return;
        }
        
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner is-active"></span> Guardando...');
        
        $.post(ajaxurl, {
            action: 'riverso_add_cost_entry',
            nonce: nonce,
            product_id: selectedProductId,
            supplier_id: $('#add-supplier').val(),
            supplier_code: $('#add-supplier-code').val(),
            cost: $('#add-cost').val(),
            quantity: $('#add-quantity').val(),
            currency: $('#add-currency').val(),
            document_date: $('#add-date').val(),
            source_type: $('#add-source').val(),
            notes: $('#add-notes').val()
        }, function(response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Guardar Costo');
            
            if (response.success) {
                alert('✓ ' + response.data.message);
                $('#add-cost-form')[0].reset();
                $('.clear-product').click();
                
                // Switch to history tab
                $('.nav-tab[data-tab="history"]').click();
            } else {
                alert('Error: ' + response.data);
            }
        });
    });
    
    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
});
</script>
