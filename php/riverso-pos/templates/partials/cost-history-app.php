<?php
/**
 * Cost History App — pestañas compartidas (wp-admin y portal /interno/cost-history/)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($suppliers) || !is_array($suppliers)) {
    global $wpdb;
    $suppliers_table = $wpdb->prefix . 'riverso_proveedores';
    $suppliers = $wpdb->get_results("SELECT id, nombre, rut FROM {$suppliers_table} WHERE activo = 1 ORDER BY nombre", ARRAY_A) ?: [];
}

if (!isset($stats) || !is_array($stats)) {
    if (!class_exists('Riverso_Cost_History_Module')) {
        require_once RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-history-module.php';
    }
    $lookup = RIVERSO_POS_PLUGIN_DIR . 'modules/costs/class-cost-lookup-service.php';
    if (file_exists($lookup) && !class_exists('Riverso_Cost_Lookup_Service')) {
        require_once $lookup;
    }
    $stats = Riverso_Cost_History_Module::get_instance()->get_stats();
}

$riverso_cost_history_context = isset($riverso_cost_history_context) ? $riverso_cost_history_context : 'admin';
?>

<div class="riverso-cost-history-app" data-context="<?php echo esc_attr($riverso_cost_history_context); ?>">
    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="explorer">
            <span class="dashicons dashicons-search"></span> Buscar producto
        </a>
        <a href="#" class="nav-tab" data-tab="history">
            <span class="dashicons dashicons-list-view"></span> Historial
        </a>
        <a href="#" class="nav-tab" data-tab="analysis">
            <span class="dashicons dashicons-chart-area"></span> Análisis por folio
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
        <a href="#" class="nav-tab" data-tab="quotes-wip">
            <span class="dashicons dashicons-media-text"></span> Cotizaciones aprobadas
            <span class="alert-badge" style="background:#996800;">WIP</span>
        </a>
    </nav>

    <!-- Tab: Explorer (buscar por SKU / barcode / código proveedor) -->
    <div class="tab-content" id="tab-explorer">
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/cost-explorer.php'; ?>
    </div>

    <!-- Tab: Cotizaciones aprobadas (WIP) -->
    <div class="tab-content" id="tab-quotes-wip" style="display: none;">
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/cost-quotes-wip.php'; ?>
    </div>
    
    <!-- Tab: History -->
    <div class="tab-content" id="tab-history" style="display: none;">
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
                        <th class="column-date sortable" data-sort="document_date">Fecha documento</th>
                        <th class="column-date sortable" data-sort="created_at">Fecha ingreso</th>
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
                        <td colspan="10">
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
    
    <!-- Tab: Analysis by invoice folio -->
    <div class="tab-content" id="tab-analysis" style="display: none;">
        <div class="analysis-container">
            <div class="analysis-search">
                <h3>Analizar factura de productos</h3>
                <p class="description" style="margin-top:0;">
                    Busca un folio (o proveedor/RUT). Se compara el costo de cada línea con la última facturación anterior
                    y, cuando esté listo, con la última cotización aprobada (WIP).
                </p>
                <div class="search-row">
                    <input type="text" id="analysis-folio-search" placeholder="Folio, proveedor o RUT..." class="large-text" autocomplete="off">
                    <button type="button" class="button button-primary" id="btn-analyze-folio">
                        <span class="dashicons dashicons-search"></span> Buscar
                    </button>
                </div>
                <div id="folio-search-results" class="search-results"></div>
            </div>

            <div id="folio-analysis-results" style="display: none;">
                <div class="analysis-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <div>
                        <h3 id="folio-analysis-title">—</h3>
                        <div class="analysis-sku" id="folio-analysis-meta">—</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" id="folio-toggle-decimals" checked>
                            Costos con decimales <em>(hasta 3)</em>
                        </label>
                        <button type="button" class="button" id="btn-print-folio-analysis" disabled>
                            <span class="dashicons dashicons-printer" style="vertical-align:middle;margin-top:3px;"></span>
                            Imprimir
                        </button>
                    </div>
                </div>

                <div class="cost-doc-grid" id="folio-analysis-header" style="margin-bottom:16px;"></div>

                <div class="analysis-card full-width" style="padding:0;overflow:auto;">
                    <table class="wp-list-table widefat fixed striped" id="folio-analysis-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th style="width:110px;">Código</th>
                                <th>Descripción</th>
                                <th style="width:100px;text-align:right;">Costo factura</th>
                                <th style="width:140px;">Última facturación</th>
                                <th style="width:140px;">Última cotización <span class="wip-inline">WIP</span></th>
                                <th style="width:90px;">Tendencia</th>
                                <th style="width:100px;text-align:right;">Diferencia</th>
                                <th style="width:80px;text-align:right;">Dif. %</th>
                            </tr>
                        </thead>
                        <tbody id="folio-analysis-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="folio-recent-grid" id="folio-recent-panel">
                <div class="folio-recent-header">
                    <h3>Últimos folios</h3>
                    <p class="description" style="margin:0;">Haz clic en un folio para analizarlo. Filtra y ordena por fecha de documento, fecha de ingreso u origen.</p>
                </div>

                <div class="cost-filters folio-recent-filters">
                    <div class="filter-row">
                    <div class="filter-group">
                        <label>Campo fecha</label>
                        <select id="folio-date-field">
                            <option value="fecha_emision">Fecha documento</option>
                            <option value="created_at">Fecha ingreso</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Desde</label>
                        <input type="date" id="folio-date-from">
                    </div>
                    <div class="filter-group">
                        <label>Hasta</label>
                        <input type="date" id="folio-date-to">
                    </div>
                    <div class="filter-group">
                        <label>Origen (proveedor)</label>
                        <select id="folio-origen">
                            <option value="">Todos</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo esc_attr($s['id']); ?>">
                                    <?php echo esc_html($s['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" id="folio-recent-search" placeholder="Folio, RUT…">
                    </div>
                    <div class="filter-group">
                        <label>Orden</label>
                        <select id="folio-order">
                            <option value="DESC">Más recientes</option>
                            <option value="ASC">Más antiguos</option>
                        </select>
                    </div>
                    <div class="filter-group filter-actions">
                        <button type="button" class="button button-primary" id="btn-folio-recent-apply">Filtrar</button>
                        <button type="button" class="button" id="btn-folio-recent-clear">Limpiar</button>
                    </div>
                    </div>
                </div>

                <div class="analysis-card full-width" style="padding:0;overflow:auto;">
                    <table class="wp-list-table widefat fixed striped" id="folio-recent-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="folio" style="width:90px;">Folio</th>
                                <th>Origen</th>
                                <th class="sortable" data-sort="fecha_emision" style="width:120px;">Fecha doc.</th>
                                <th class="sortable" data-sort="created_at" style="width:140px;">Fecha ingreso</th>
                                <th style="width:80px;">Ítems</th>
                                <th class="sortable" data-sort="monto_total" style="width:110px;text-align:right;">Total</th>
                                <th style="width:90px;">Estado</th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody id="folio-recent-body">
                            <tr class="loading-row"><td colspan="8"><span class="spinner is-active"></span> Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="table-pagination" id="folio-recent-pagination" style="display:none;">
                    <button type="button" class="button" id="btn-folio-prev" disabled>&laquo; Anterior</button>
                    <span id="folio-page-info">Página 1</span>
                    <button type="button" class="button" id="btn-folio-next">Siguiente &raquo;</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tab: Alerts -->
    <div class="tab-content" id="tab-alerts" style="display: none;">
        <div class="alerts-header">
            <h3>
                <span class="dashicons dashicons-warning"></span>
                Alertas de costos
            </h3>
            <p class="description" id="alerts-description">
                Alzas de costo de compra respecto a la última factura del mismo par (proveedor + código).
            </p>
        </div>

        <div class="alerts-shortcuts" id="alerts-shortcuts">
            <button type="button" class="button button-primary alerts-shortcut" data-mode="up0" data-min-pct="0">
                Subidas &gt; 0%
            </button>
            <button type="button" class="button alerts-shortcut" data-mode="up10" data-min-pct="10">
                Subidas &gt; 10%
            </button>
            <button type="button" class="button alerts-shortcut" data-mode="margin">
                Margen bajo
            </button>
        </div>

        <div class="alerts-folio-search analysis-search" style="margin-bottom:16px;">
            <label for="alerts-folio-search"><strong>Buscar folio</strong></label>
            <div class="search-row">
                <input type="text" id="alerts-folio-search" placeholder="Folio, proveedor o RUT..." class="large-text" autocomplete="off">
                <button type="button" class="button button-primary" id="btn-alerts-folio-search">
                    <span class="dashicons dashicons-search"></span> Buscar
                </button>
            </div>
            <div id="alerts-folio-search-results" class="search-results"></div>
            <p class="description" style="margin-top:6px;">Abre el análisis comparativo de la factura en la pestaña Análisis.</p>
        </div>

        <div class="alerts-controls" id="alerts-increase-filters">
            <label>
                Desde
                <input type="date" id="alert-date-from">
            </label>
            <label>
                Hasta
                <input type="date" id="alert-date-to">
            </label>
            <label>
                Highlight alza ≥
                <select id="alert-highlight-pct">
                    <option value="5">5%</option>
                    <option value="10" selected>10%</option>
                    <option value="15">15%</option>
                    <option value="20">20%</option>
                </select>
            </label>
            <label>
                Umbral margen venta
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

        <div class="alerts-controls" id="alerts-margin-filters" style="display:none;">
            <label>
                Umbral de margen:
                <select id="alert-margin-threshold">
                    <option value="1.3">30% (1.3x)</option>
                    <option value="1.5" selected>33% (1.5x)</option>
                    <option value="2.0">50% (2.0x)</option>
                </select>
            </label>
            <button type="button" class="button" id="btn-refresh-margin-alerts">
                <span class="dashicons dashicons-update"></span> Actualizar
            </button>
        </div>

        <div class="table-container alerts-table-scroll" id="alerts-increases-wrap">
            <table class="wp-list-table widefat striped" id="alerts-increases-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Origen / Folio</th>
                        <th style="text-align:right">Costo</th>
                        <th style="text-align:right">Δ %</th>
                        <th style="text-align:right">Margen</th>
                        <th style="width:150px;"></th>
                    </tr>
                </thead>
                <tbody id="alerts-increases-body">
                    <tr class="loading-row">
                        <td colspan="6">
                            <span class="spinner is-active"></span> Cargando…
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="table-container" id="alerts-margin-wrap" style="display:none;">
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
            <h2 id="cost-detail-title">Detalle de Costo</h2>
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

<!-- Modal análisis por folio (desde Alertas) -->
<div id="alerts-folio-modal" class="rce-modal" hidden>
    <div class="rce-modal-backdrop" data-alerts-folio-close></div>
    <div class="rce-modal-dialog rce-evo-dialog" role="dialog" aria-modal="true" aria-labelledby="alerts-folio-title">
        <div class="rce-modal-header">
            <div>
                <h2 id="alerts-folio-title">Análisis de folio</h2>
                <div class="rce-evo-subtitle" id="alerts-folio-meta">—</div>
            </div>
            <button type="button" class="rce-modal-close" data-alerts-folio-close aria-label="Cerrar">&times;</button>
        </div>
        <div class="rce-modal-body">
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" id="alerts-folio-toggle-decimals" checked>
                    Costos con decimales <em>(hasta 3)</em>
                </label>
                <button type="button" class="button" id="btn-alerts-folio-print" disabled>
                    <span class="dashicons dashicons-printer" style="vertical-align:middle;margin-top:3px;"></span>
                    Imprimir
                </button>
            </div>
            <div class="cost-doc-grid" id="alerts-folio-header" style="margin-bottom:16px;"></div>
            <div style="overflow:auto;max-width:100%;">
                <table class="wp-list-table widefat striped" id="alerts-folio-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="width:100px;">Código</th>
                            <th>Descripción</th>
                            <th style="width:100px;text-align:right;">Costo factura</th>
                            <th style="width:130px;">Última facturación</th>
                            <th style="width:120px;">Última cotización <span class="wip-inline">WIP</span></th>
                            <th style="width:80px;">Tendencia</th>
                            <th style="width:90px;text-align:right;">Diferencia</th>
                            <th style="width:70px;text-align:right;">Dif. %</th>
                        </tr>
                    </thead>
                    <tbody id="alerts-folio-body"></tbody>
                </table>
            </div>
        </div>
        <div class="rce-modal-footer">
            <button type="button" class="button" data-alerts-folio-close>Cerrar</button>
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

.column-date { width: 110px; }
.column-sku { width: 120px; }
.column-supplier { width: 150px; }
.column-cost, .column-price { width: 100px; text-align: right; }
.column-margin { width: 80px; text-align: center; }
.column-source { width: 100px; }
.column-actions { width: 120px; text-align: center; }

.cost-doc-ref {
    font-size: 11px;
    color: #646970;
    margin-top: 2px;
}

.cost-doc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.cost-doc-field label {
    display: block;
    font-size: 11px;
    color: #646970;
    text-transform: uppercase;
}

.cost-doc-field span {
    font-weight: 600;
}

.cost-doc-items tr.is-highlight td {
    background: #fff8e5;
    outline: 2px solid #dba617;
    outline-offset: -2px;
}

.wip-inline {
    display: inline-block;
    background: #fcf0e3;
    color: #996800;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 8px;
    vertical-align: middle;
}

#folio-analysis-table tr.trend-up td,
#folio-analysis-table td.trend-up {
    background: #fcf0f1;
}

#folio-analysis-table tr.trend-down td,
#folio-analysis-table td.trend-down {
    background: #edfaef;
}

#folio-analysis-table td.trend-up strong,
#folio-analysis-table td.trend-up {
    color: #d63638;
}

#folio-analysis-table td.trend-down strong,
#folio-analysis-table td.trend-down {
    color: #00a32a;
}

#alerts-folio-table tr.trend-up td,
#alerts-folio-table td.trend-up {
    background: #fcf0f1;
    color: #b32d2e;
}

#alerts-folio-table tr.trend-down td,
#alerts-folio-table td.trend-down {
    background: #edfaef;
    color: #00a32a;
}

#alerts-folio-table td.trend-up strong,
#alerts-folio-table td.trend-up {
    color: #b32d2e;
}

#alerts-folio-table td.trend-down strong,
#alerts-folio-table td.trend-down {
    color: #00a32a;
}

.folio-recent-grid {
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px solid #dcdcde;
}

.folio-recent-header {
    margin-bottom: 12px;
}

.folio-recent-header h3 {
    margin: 0 0 4px;
}

.folio-recent-filters {
    margin-bottom: 12px;
}

#folio-recent-table tr.folio-row-active td {
    background: #e8f4fc !important;
}

#folio-recent-table th.sortable {
    cursor: pointer;
    user-select: none;
}

#folio-recent-table th.sortable:hover {
    color: #2271b1;
}

.cost-doc-ref {
    font-size: 11px;
    color: #646970;
    margin-top: 2px;
}

#folio-search-results .search-result-item {
    display: block;
}

#folio-search-results .product-sku {
    display: block;
    font-size: 12px;
    color: #646970;
    margin-top: 2px;
}

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
    margin-bottom: 12px;
}

.alerts-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 5px 0;
}

.alerts-shortcuts {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.alerts-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 16px;
    align-items: flex-end;
    margin-bottom: 14px;
}

.alerts-controls label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
}

#alerts-increases-table tr.alert-highlight-up td {
    background: #fcf0f1 !important;
    font-weight: 600;
}

#alerts-increases-table tr.alert-highlight-margin td {
    background: #fff8e5 !important;
}

#alerts-increases-table tr.alert-highlight-up.alert-highlight-margin td {
    background: #fcebea !important;
}

#alerts-increases-table td.trend-up {
    color: #b32d2e;
    font-weight: 700;
}

.alerts-table-scroll {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    background: #fff;
}

#alerts-increases-table {
    width: 100%;
    min-width: 640px;
    table-layout: auto;
    margin: 0;
    border: 0;
}

#alerts-increases-table th,
#alerts-increases-table td {
    font-size: 12px;
    padding: 6px 8px;
    vertical-align: top;
    word-break: break-word;
}

#alerts-increases-table .alert-prod-code {
    font-size: 11px;
    color: #646970;
    margin-top: 2px;
}

#alerts-increases-table .alert-cost-stack {
    line-height: 1.35;
    white-space: nowrap;
}

#alerts-increases-table .alert-actions {
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: stretch;
}

#alerts-increases-table .alert-actions .button {
    width: 100%;
    text-align: center;
    white-space: nowrap;
}

.riverso-cost-history-app,
.riverso-cost-history-app .tab-content,
#tab-alerts {
    max-width: 100%;
    overflow-x: hidden;
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

/* Portal: emulate wp-admin tabs / tables / buttons */
.riverso-cost-history-app[data-context="portal"] .nav-tab-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    border-bottom: 1px solid #c3c4c7;
    margin: 0 0 16px;
    padding: 0;
}

.riverso-cost-history-app[data-context="portal"] .nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    text-decoration: none;
    color: #50575e;
    background: #f0f0f1;
    border: 1px solid #c3c4c7;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    font-size: 13px;
    line-height: 1.4;
}

.riverso-cost-history-app[data-context="portal"] .nav-tab:hover {
    color: #1d2327;
    background: #fff;
}

.riverso-cost-history-app[data-context="portal"] .nav-tab-active {
    background: #fff;
    color: #1d2327;
    border-bottom: 1px solid #fff;
    margin-bottom: -1px;
    font-weight: 600;
}

.riverso-cost-history-app[data-context="portal"] .button,
.riverso-cost-history-app[data-context="portal"] .button-primary,
.riverso-cost-history-app[data-context="portal"] .button-secondary,
.riverso-cost-history-app[data-context="portal"] .button-small {
    display: inline-block;
    font-size: 13px;
    line-height: 2;
    min-height: 30px;
    margin: 0;
    padding: 0 10px;
    cursor: pointer;
    border-radius: 3px;
    border: 1px solid #c3c4c7;
    background: #f6f7f7;
    color: #2c3338;
    text-decoration: none;
    vertical-align: top;
}

.riverso-cost-history-app[data-context="portal"] .button-primary {
    background: #2271b1;
    border-color: #2271b1;
    color: #fff;
}

.riverso-cost-history-app[data-context="portal"] .button-primary:hover {
    background: #135e96;
    border-color: #135e96;
    color: #fff;
}

.riverso-cost-history-app[data-context="portal"] .button:disabled,
.riverso-cost-history-app[data-context="portal"] .button[disabled] {
    opacity: 0.6;
    cursor: default;
}

.riverso-cost-history-app[data-context="portal"] .wp-list-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border: 1px solid #c3c4c7;
}

.riverso-cost-history-app[data-context="portal"] .wp-list-table th,
.riverso-cost-history-app[data-context="portal"] .wp-list-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #dcdcde;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
}

.riverso-cost-history-app[data-context="portal"] .wp-list-table thead th {
    background: #f6f7f7;
    font-weight: 600;
}

.riverso-cost-history-app[data-context="portal"] .wp-list-table.striped tbody tr:nth-child(odd) {
    background: #f9f9f9;
}

.riverso-cost-history-app[data-context="portal"] .large-text,
.riverso-cost-history-app[data-context="portal"] input[type="text"],
.riverso-cost-history-app[data-context="portal"] input[type="date"],
.riverso-cost-history-app[data-context="portal"] input[type="number"],
.riverso-cost-history-app[data-context="portal"] select,
.riverso-cost-history-app[data-context="portal"] textarea {
    border: 1px solid #8c8f94;
    border-radius: 3px;
    padding: 4px 8px;
    font-size: 13px;
    line-height: 1.5;
    background: #fff;
    color: #2c3338;
    max-width: 100%;
}

.riverso-cost-history-app[data-context="portal"] .description {
    color: #646970;
    font-size: 13px;
}

.riverso-cost-history-app[data-context="portal"] .spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid #c3c4c7;
    border-top-color: #2271b1;
    border-radius: 50%;
    animation: rce-spin 0.7s linear infinite;
    vertical-align: middle;
}

.riverso-cost-history-app[data-context="portal"] .spinner.is-active {
    visibility: visible;
}

@keyframes rce-spin {
    to { transform: rotate(360deg); }
}

.riverso-cost-history-app[data-context="portal"] code {
    background: #f0f0f1;
    padding: 1px 4px;
    border-radius: 2px;
    font-size: 12px;
}
</style>

<script>
jQuery(document).ready(function($) {
    if (typeof window.ajaxurl === 'undefined' || !window.ajaxurl) {
        window.ajaxurl = (window.riversoCostHistory && riversoCostHistory.ajax_url)
            ? riversoCostHistory.ajax_url
            : '';
    }
    var ajaxurl = window.ajaxurl;
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let currentPage = 1;
    let currentSort = { field: 'document_date', order: 'DESC' };
    let selectedProductId = null;
    let historyById = {};
    
    // Tab switching (scoped to this app)
    $('.riverso-cost-history-app > .nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        const $app = $(this).closest('.riverso-cost-history-app');

        $app.find('> .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $app.find('> .tab-content').hide();
        $app.find('#tab-' + tab).show();

        if (tab === 'alerts') {
            initAlertsDefaults();
            refreshAlertsView();
        }
        if (tab === 'history') {
            loadHistory();
        }
        if (tab === 'analysis') {
            loadRecentFolios();
        }
    });
    
    // History se carga al abrir la pestaña (explorer es la default)
    
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
        $tbody.html('<tr class="loading-row"><td colspan="10"><span class="spinner is-active"></span> Cargando...</td></tr>');
        
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
                $tbody.html('<tr><td colspan="10">Error: ' + response.data + '</td></tr>');
            }
        });
    }
    
    function tipoDteLabel(tipo) {
        const map = { 33: 'Factura', 34: 'Exenta', 52: 'Guía', 61: 'N. Crédito' };
        return map[parseInt(tipo, 10)] || ('DTE ' + tipo);
    }

    function sourceLabel(type) {
        const map = {
            invoice: 'Factura',
            quote: 'Cotización',
            manual: 'Manual',
            import: 'Importación'
        };
        return map[type] || type || '-';
    }

    function formatDateTime(val) {
        if (!val) return '-';
        // "YYYY-MM-DD HH:MM:SS" o "YYYY-MM-DDTHH:MM:SS"
        return String(val).replace('T', ' ').substring(0, 16);
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    
    function renderHistory(entries) {
        const $tbody = $('#cost-history-body');
        
        if (entries.length === 0) {
            $tbody.html('<tr><td colspan="10" style="text-align:center;padding:30px;">No hay registros</td></tr>');
            return;
        }
        
        let html = '';
        historyById = {};
        entries.forEach(function(entry) {
            historyById[entry.id] = entry;
            const marginClass = entry.margin_alert ? 'margin-danger' : 
                               (entry.margin > 40 ? 'margin-good' : 'margin-warning');
            const sourceClass = 'source-' + entry.source_type;
            const isInvoice = entry.source_type === 'invoice';
            const canDelete = entry.can_delete !== false && !isInvoice;
            const hasDoc = entry.has_document || (isInvoice && entry.source_document_id);
            const ingreso = entry.created_at_fmt || formatDateTime(entry.created_at);

            let actions = '';
            actions += `<button type="button" class="button button-small btn-view-detail" data-id="${entry.id}" title="${hasDoc ? 'Ver documento de origen' : 'Ver detalle'}">
                    <span class="dashicons dashicons-visibility"></span> Ver
                </button>`;
            if (canDelete) {
                actions += ` <button type="button" class="button button-small btn-delete-cost" data-id="${entry.id}" title="Eliminar registro manual">
                    <span class="dashicons dashicons-trash"></span>
                </button>`;
            }
            
            html += `<tr data-id="${entry.id}">
                <td>
                    <div>${entry.document_date || '-'}</div>
                    ${entry.factura_folio ? `<div class="cost-doc-ref">${escapeHtml(tipoDteLabel(entry.factura_tipo_dte))} ${escapeHtml(entry.factura_folio)}</div>` : ''}
                </td>
                <td>${ingreso}</td>
                <td>${escapeHtml(entry.product_name || '-')}</td>
                <td><code>${escapeHtml(entry.product_sku || '-')}</code></td>
                <td>${escapeHtml(entry.supplier_name || '-')}</td>
                <td style="text-align:right">$${formatNumber(entry.unit_cost || entry.cost)}</td>
                <td style="text-align:right">${entry.current_price ? '$' + formatNumber(entry.current_price) : '-'}</td>
                <td style="text-align:center">
                    ${entry.margin !== null ? 
                        `<span class="margin-badge ${marginClass}">${entry.margin}%</span>` : '-'}
                </td>
                <td><span class="source-badge ${sourceClass}">${escapeHtml(sourceLabel(entry.source_type))}</span></td>
                <td style="text-align:center" class="column-actions">${actions}</td>
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
    
    // Ver documento / detalle
    $(document).on('click', '.btn-view-detail', function() {
        const id = $(this).data('id');
        const entry = historyById[id];
        if (!entry) {
            alert('Registro no encontrado en la página actual');
            return;
        }

        if (entry.source_type === 'invoice' && entry.source_document_id) {
            openInvoiceDocument(
                entry.source_document_id,
                entry.supplier_code || '',
                entry.source_item_id || null
            );
            return;
        }

        showEntryDetail(entry);
    });

    function openInvoiceDocument(facturaId, highlightCode, itemId) {
        const $modal = $('#cost-detail-modal');
        const $content = $('#cost-detail-content');
        $('#cost-detail-title').text('Documento de origen');
        $content.html('<p><span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span> Cargando factura…</p>');
        $modal.css('display', 'flex');

        $.post(ajaxurl, {
            action: 'riverso_cost_get_document',
            nonce: nonce,
            factura_id: facturaId
        }, function(res) {
            if (!res || !res.success) {
                $content.html('<p>Error: ' + escapeHtml((res && res.data) || 'No se pudo cargar el documento') + '</p>');
                return;
            }
            renderInvoiceInModal(res.data, highlightCode, itemId);
        }).fail(function() {
            $content.html('<p>Error de red al cargar el documento</p>');
        });
    }

    function renderInvoiceInModal(doc, highlightCode, itemId) {
        $('#cost-detail-title').text(
            tipoDteLabel(doc.tipo_dte) + ' N° ' + (doc.folio || '')
        );

        let html = '<div class="cost-doc-grid">';
        html += docField('Proveedor', doc.proveedor_nombre);
        html += docField('RUT', doc.rut_emisor);
        html += docField('Fecha documento', doc.fecha_emision);
        html += docField('Estado', doc.estado);
        html += docField('Neto', '$' + formatNumber(doc.monto_neto));
        html += docField('IVA', '$' + formatNumber(doc.monto_iva));
        html += docField('Total', '$' + formatNumber(doc.monto_total));
        html += '</div>';

        html += '<table class="wp-list-table widefat fixed striped cost-doc-items"><thead><tr>';
        html += '<th>#</th><th>Código</th><th>Descripción</th>';
        html += '<th style="text-align:right">Cant.</th><th style="text-align:right">Costo unit.</th><th style="text-align:right">Total</th>';
        html += '</tr></thead><tbody>';

        (doc.items || []).forEach(function(it) {
            const codeMatch = highlightCode && String(it.codigo_proveedor || '').toLowerCase() === String(highlightCode).toLowerCase();
            const idMatch = itemId && parseInt(it.id, 10) === parseInt(itemId, 10);
            const isHit = codeMatch || idMatch;
            html += '<tr' + (isHit ? ' class="is-highlight"' : '') + '>';
            html += '<td>' + escapeHtml(it.numero_linea) + '</td>';
            html += '<td><code>' + escapeHtml(it.codigo_proveedor || '—') + '</code></td>';
            html += '<td>' + escapeHtml(it.nombre || '—') + '</td>';
            html += '<td style="text-align:right">' + escapeHtml(it.cantidad) + '</td>';
            html += '<td style="text-align:right">$' + formatNumber(it.costo_unitario) + '</td>';
            html += '<td style="text-align:right">$' + formatNumber(it.monto_total) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';

        $('#cost-detail-content').html(html);
        const $hit = $('#cost-detail-content tr.is-highlight').first();
        if ($hit.length) {
            $hit[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function docField(label, value) {
        return '<div class="cost-doc-field"><label>' + escapeHtml(label) + '</label><span>' +
            escapeHtml(value == null || value === '' ? '—' : value) + '</span></div>';
    }

    function showEntryDetail(entry) {
        const $modal = $('#cost-detail-modal');
        const $content = $('#cost-detail-content');
        $('#cost-detail-title').text('Detalle de costo');
        if (!entry) {
            $content.html('<p>Sin detalle disponible.</p>');
            $modal.css('display', 'flex');
            return;
        }
        let html = '<div class="cost-doc-grid">';
        html += docField('Producto', entry.product_name);
        html += docField('SKU', entry.product_sku);
        html += docField('Proveedor', entry.supplier_name);
        html += docField('Código proveedor', entry.supplier_code);
        html += docField('Costo', '$' + formatNumber(entry.unit_cost || entry.cost));
        html += docField('Fecha documento', entry.document_date);
        html += docField('Fecha ingreso', formatDateTime(entry.created_at));
        html += docField('Origen', sourceLabel(entry.source_type));
        html += docField('Registrado por', entry.created_by_name);
        html += docField('Notas', entry.notes || '—');
        html += '</div>';
        $content.html(html);
        $modal.css('display', 'flex');
    }

    $(document).on('click', '#cost-detail-modal .modal-close', function(e) {
        e.preventDefault();
        $('#cost-detail-modal').hide();
    });
    $('#cost-detail-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
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
    
    // Análisis por folio de factura de productos
    let searchTimeout;
    let folioShowDecimals = true;
    let lastFolioAnalysis = null;
    let selectedFolioId = null;
    let folioRecentPage = 1;
    let folioRecentSort = { field: 'fecha_emision', order: 'DESC' };
    let folioRecentTotalPages = 1;

    function formatCostMoney(n) {
        if (n === null || n === undefined || n === '' || isNaN(n)) return '—';
        const num = Number(n);
        if (!isFinite(num)) return '—';
        if (!folioShowDecimals) {
            return '$' + Math.round(num).toLocaleString('es-CL');
        }
        const rounded = Math.round(num * 1000) / 1000;
        const fixed = rounded.toFixed(3).replace(/\.?0+$/, '');
        const parts = fixed.split('.');
        const intPart = Number(parts[0]).toLocaleString('es-CL');
        return parts.length > 1 ? ('$' + intPart + ',' + parts[1]) : ('$' + intPart);
    }

    function formatDeltaMoney(n) {
        if (n === null || n === undefined || isNaN(n)) return '—';
        const sign = n > 0 ? '+' : '';
        return sign + formatCostMoney(n).replace('$', '$');
    }

    function formatDeltaPct(n) {
        if (n === null || n === undefined || isNaN(n)) return '—';
        const sign = n > 0 ? '+' : '';
        return sign + Number(n).toFixed(1) + '%';
    }

    function trendLabel(t) {
        if (t === 'subio') return 'Subió';
        if (t === 'bajo') return 'Bajó';
        if (t === 'se_mantuvo') return 'Se mantuvo';
        return '—';
    }

    function trendClass(t) {
        if (t === 'subio') return 'trend-up';
        if (t === 'bajo') return 'trend-down';
        return '';
    }

    $('#analysis-folio-search').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $.trim($(this).val());
        if (query.length < 1) {
            $('#folio-search-results').removeClass('active').empty();
            return;
        }
        searchTimeout = setTimeout(function() {
            searchFolios(query);
        }, 280);
    });

    $('#analysis-folio-search').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimeout);
            searchFolios($.trim($(this).val()));
        }
    });

    $('#btn-analyze-folio').on('click', function() {
        searchFolios($.trim($('#analysis-folio-search').val()));
    });

    $('#folio-toggle-decimals').on('change', function() {
        folioShowDecimals = $(this).is(':checked');
        if (lastFolioAnalysis) {
            renderFolioAnalysis(lastFolioAnalysis);
        }
    });

    function searchFolios(term) {
        const $box = $('#folio-search-results');
        if (!term) return;
        $box.html('<div class="search-result-item">Buscando…</div>').addClass('active');
        $.post(ajaxurl, {
            action: 'riverso_cost_search_invoices',
            nonce: nonce,
            term: term,
            limit: 15
        }, function(res) {
            if (!res || !res.success) {
                $box.html('<div class="search-result-item">Error: ' + escapeHtml((res && res.data) || 'búsqueda') + '</div>');
                return;
            }
            const results = res.data.results || [];
            if (!results.length) {
                $box.html('<div class="search-result-item">Sin facturas de productos</div>');
                return;
            }
            let html = '';
            results.forEach(function(r) {
                html += `<button type="button" class="search-result-item folio-result" data-id="${r.id}" style="width:100%;text-align:left;border:0;background:#fff;cursor:pointer;">
                    <span class="product-name">${escapeHtml(tipoDteLabel(r.tipo_dte))} ${escapeHtml(r.folio)} — ${escapeHtml(r.proveedor_nombre || '')}</span>
                    <span class="product-sku">${escapeHtml(r.fecha_emision || '')} · $${formatNumber(r.monto_total)} · ${escapeHtml(r.estado || '')}</span>
                </button>`;
            });
            $box.html(html);
        }).fail(function() {
            $box.html('<div class="search-result-item">Error de red</div>');
        });
    }

    $(document).on('click', '.folio-result', function() {
        const id = $(this).data('id');
        $('#folio-search-results').removeClass('active').empty();
        loadFolioAnalysis(id);
    });

    function loadFolioAnalysis(facturaId) {
        selectedFolioId = parseInt(facturaId, 10) || null;
        $('#folio-analysis-results').show();
        $('#folio-analysis-title').text('Cargando…');
        $('#folio-analysis-meta').text('');
        $('#folio-analysis-header').empty();
        $('#folio-analysis-body').html('<tr><td colspan="9" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> Analizando…</td></tr>');
        $('#btn-print-folio-analysis').prop('disabled', true);
        $('html, body').animate({ scrollTop: $('#folio-analysis-results').offset().top - 40 }, 200);

        $.post(ajaxurl, {
            action: 'riverso_cost_analyze_invoice',
            nonce: nonce,
            factura_id: facturaId
        }, function(res) {
            if (!res || !res.success) {
                $('#folio-analysis-body').html('<tr><td colspan="9">Error: ' + escapeHtml((res && res.data) || 'análisis') + '</td></tr>');
                return;
            }
            lastFolioAnalysis = res.data;
            folioShowDecimals = $('#folio-toggle-decimals').is(':checked');
            renderFolioAnalysis(res.data);
            $('#btn-print-folio-analysis').prop('disabled', false);
        }).fail(function() {
            $('#folio-analysis-body').html('<tr><td colspan="9">Error de red</td></tr>');
            $('#btn-print-folio-analysis').prop('disabled', true);
        });
    }

    function renderFolioAnalysis(data) {
        const inv = data.invoice || {};
        $('#folio-analysis-title').text(tipoDteLabel(inv.tipo_dte) + ' N° ' + (inv.folio || ''));
        $('#folio-analysis-meta').text(
            (inv.proveedor_nombre || '') + ' · RUT ' + (inv.rut_emisor || '—') +
            ' · Doc. ' + (inv.fecha_emision || '—') +
            ' · Ingreso ' + formatDateTime(inv.created_at)
        );

        let header = '';
        header += docField('Proveedor', inv.proveedor_nombre);
        header += docField('Fecha documento', inv.fecha_emision);
        header += docField('Fecha ingreso', formatDateTime(inv.created_at));
        header += docField('Estado', inv.estado);
        header += docField('Neto', formatCostMoney(inv.monto_neto));
        header += docField('Total', formatCostMoney(inv.monto_total));
        $('#folio-analysis-header').html(header);

        const rows = data.rows || [];
        if (!rows.length) {
            $('#folio-analysis-body').html('<tr><td colspan="9" style="text-align:center;padding:20px;">Sin líneas de producto</td></tr>');
            $('#btn-print-folio-analysis').prop('disabled', true);
            return;
        }

        let html = '';
        rows.forEach(function(row) {
            const tClass = trendClass(row.trend);
            const changed = row.trend === 'subio' || row.trend === 'bajo';
            const prevInv = row.prev_invoice;
            let prevInvHtml = '—';
            if (prevInv && prevInv.costo_unitario !== null && prevInv.costo_unitario !== undefined) {
                prevInvHtml = `<div><strong>${formatCostMoney(prevInv.costo_unitario)}</strong></div>` +
                    `<div class="cost-doc-ref">${escapeHtml(tipoDteLabel(prevInv.tipo_dte))} ${escapeHtml(prevInv.folio)} · ${escapeHtml(prevInv.fecha_emision || '')}</div>`;
            }

            // WIP cotización
            let prevQuoteHtml = '—';
            if (row.prev_quote && row.prev_quote.costo_unitario != null) {
                prevQuoteHtml = formatCostMoney(row.prev_quote.costo_unitario);
            }

            const deltaCls = row.trend === 'subio' ? 'trend-up' : (row.trend === 'bajo' ? 'trend-down' : '');
            let deltaText = '—';
            if (row.delta !== null && row.delta !== undefined) {
                // formatCostMoney always prefixes $; for signed delta keep sign outside
                if (row.delta < 0) deltaText = '-' + formatCostMoney(Math.abs(row.delta));
                else if (row.delta > 0) deltaText = '+' + formatCostMoney(Math.abs(row.delta));
                else deltaText = formatCostMoney(0);
            }

            html += `<tr class="${tClass}${changed ? ' has-change' : ''}">
                <td>${escapeHtml(row.numero_linea)}</td>
                <td><code>${escapeHtml(row.codigo_proveedor || '—')}</code></td>
                <td>${escapeHtml(row.nombre || '—')}</td>
                <td style="text-align:right">${formatCostMoney(row.costo_actual)}</td>
                <td>${prevInvHtml}</td>
                <td>${prevQuoteHtml}</td>
                <td class="${tClass}"><strong>${escapeHtml(trendLabel(row.trend))}</strong></td>
                <td style="text-align:right" class="${deltaCls}">${deltaText}</td>
                <td style="text-align:right" class="${deltaCls}">${formatDeltaPct(row.delta_pct)}</td>
            </tr>`;
        });
        $('#folio-analysis-body').html(html);
        $('#btn-print-folio-analysis').prop('disabled', false);
    }

    function buildFolioPrintHtml(data) {
        const inv = data.invoice || {};
        const rows = data.rows || [];
        const title = tipoDteLabel(inv.tipo_dte) + ' N° ' + (inv.folio || '');
        const printedAt = new Date().toLocaleString('es-CL');

        let rowsHtml = '';
        rows.forEach(function(row) {
            const changed = row.trend === 'subio' || row.trend === 'bajo';
            const rowClass = changed ? 'changed' : '';
            const prevInv = row.prev_invoice;
            let prevInvText = '—';
            if (prevInv && prevInv.costo_unitario != null) {
                prevInvText = formatCostMoney(prevInv.costo_unitario) +
                    ' (' + tipoDteLabel(prevInv.tipo_dte) + ' ' + (prevInv.folio || '') +
                    ' · ' + (prevInv.fecha_emision || '') + ')';
            }
            let prevQuoteText = '—';
            if (row.prev_quote && row.prev_quote.costo_unitario != null) {
                prevQuoteText = formatCostMoney(row.prev_quote.costo_unitario);
            }
            let deltaText = '—';
            if (row.delta !== null && row.delta !== undefined) {
                if (row.delta < 0) deltaText = '-' + formatCostMoney(Math.abs(row.delta));
                else if (row.delta > 0) deltaText = '+' + formatCostMoney(Math.abs(row.delta));
                else deltaText = formatCostMoney(0);
            }

            rowsHtml += '<tr class="' + rowClass + '">' +
                '<td>' + escapeHtml(row.numero_linea) + '</td>' +
                '<td>' + escapeHtml(row.codigo_proveedor || '—') + '</td>' +
                '<td>' + escapeHtml(row.nombre || '—') + '</td>' +
                '<td class="num">' + escapeHtml(formatCostMoney(row.costo_actual)) + '</td>' +
                '<td>' + escapeHtml(prevInvText) + '</td>' +
                '<td>' + escapeHtml(prevQuoteText) + '</td>' +
                '<td class="chg">' + escapeHtml(trendLabel(row.trend)) + '</td>' +
                '<td class="num chg">' + escapeHtml(deltaText) + '</td>' +
                '<td class="num chg">' + escapeHtml(formatDeltaPct(row.delta_pct)) + '</td>' +
                '</tr>';
        });

        return `<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<title>Análisis ${escapeHtml(title)}</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;color:#111;}
h1{margin:0 0 8px;font-size:22px;}
.meta{margin-bottom:16px;font-size:13px;color:#444;}
.meta div{margin:2px 0;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th,td{border:1px solid #ccc;padding:6px 8px;text-align:left;vertical-align:top;}
th{background:#f5f5f5;}
td.num{text-align:right;white-space:nowrap;}
tr.changed td{font-weight:700;color:#000;}
tr.changed td.chg{text-decoration:underline;}
.legend{margin-top:12px;font-size:12px;color:#444;}
@media print{
  body{margin:12mm;color:#000;}
  *{color:#000 !important;background:#fff !important;}
  th{background:#fff !important;}
  .no-print{display:none;}
  tr.changed td{font-weight:700;}
}
</style></head><body>
<h1>Análisis de costos — ${escapeHtml(title)}</h1>
<div class="meta">
  <div><strong>Proveedor:</strong> ${escapeHtml(inv.proveedor_nombre || '—')}</div>
  <div><strong>RUT:</strong> ${escapeHtml(inv.rut_emisor || '—')}</div>
  <div><strong>Fecha documento:</strong> ${escapeHtml(inv.fecha_emision || '—')}</div>
  <div><strong>Fecha ingreso:</strong> ${escapeHtml(formatDateTime(inv.created_at))}</div>
  <div><strong>Estado:</strong> ${escapeHtml(inv.estado || '—')} · <strong>Neto:</strong> ${escapeHtml(formatCostMoney(inv.monto_neto))} · <strong>Total:</strong> ${escapeHtml(formatCostMoney(inv.monto_total))}</div>
  <div><strong>Generado:</strong> ${escapeHtml(printedAt)}</div>
</div>
<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Código</th>
      <th>Descripción</th>
      <th>Costo factura</th>
      <th>Última facturación</th>
      <th>Última cotización</th>
      <th>Tendencia</th>
      <th>Diferencia</th>
      <th>Dif. %</th>
    </tr>
  </thead>
  <tbody>${rowsHtml}</tbody>
</table>
<div class="legend"><strong>Nota:</strong> las filas en negrita (y diferencia subrayada) indican cambio de costo respecto a la última facturación.</div>
<p class="no-print" style="margin-top:20px;">
  <button type="button" onclick="window.print()">Imprimir</button>
</p>
</body></html>`;
    }

    $('#btn-print-folio-analysis').on('click', function() {
        if (!lastFolioAnalysis) {
            return;
        }
        folioShowDecimals = $('#folio-toggle-decimals').is(':checked');
        const html = buildFolioPrintHtml(lastFolioAnalysis);
        const w = window.open('', '_blank');
        if (!w) {
            alert('Permite ventanas emergentes para imprimir');
            return;
        }
        w.document.write(html);
        w.document.close();
    });

    // --- Grid inferior: últimos folios ---
    function loadRecentFolios() {
        const $tbody = $('#folio-recent-body');
        $tbody.html('<tr class="loading-row"><td colspan="8"><span class="spinner is-active"></span> Cargando…</td></tr>');

        const dateField = $('#folio-date-field').val() || 'fecha_emision';
        // Si el usuario ordena por una columna de fecha, alinear el campo de filtro
        if (folioRecentSort.field === 'fecha_emision' || folioRecentSort.field === 'created_at') {
            // keep sort; date filter uses date_field independently
        }

        $.post(ajaxurl, {
            action: 'riverso_cost_list_recent_invoices',
            nonce: nonce,
            date_field: dateField,
            date_from: $('#folio-date-from').val(),
            date_to: $('#folio-date-to').val(),
            proveedor_id: $('#folio-origen').val(),
            search: $('#folio-recent-search').val(),
            orderby: folioRecentSort.field,
            order: folioRecentSort.order,
            limit: 25,
            offset: (folioRecentPage - 1) * 25
        }, function(res) {
            if (!res || !res.success) {
                $tbody.html('<tr><td colspan="8">Error: ' + escapeHtml((res && res.data) || 'listado') + '</td></tr>');
                return;
            }
            renderRecentFolios(res.data.items || []);
            folioRecentTotalPages = Math.max(1, parseInt(res.data.pages, 10) || 1);
            $('#folio-page-info').text('Página ' + folioRecentPage + ' de ' + folioRecentTotalPages + ' (' + (res.data.total || 0) + ')');
            $('#folio-recent-pagination').toggle((res.data.total || 0) > 25);
            $('#btn-folio-prev').prop('disabled', folioRecentPage <= 1);
            $('#btn-folio-next').prop('disabled', folioRecentPage >= folioRecentTotalPages);
        }).fail(function() {
            $tbody.html('<tr><td colspan="8">Error de red</td></tr>');
        });
    }

    function renderRecentFolios(items) {
        const $tbody = $('#folio-recent-body');
        if (!items.length) {
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:20px;">Sin folios con estos filtros</td></tr>');
            return;
        }
        let html = '';
        items.forEach(function(r) {
            const active = selectedFolioId === r.id ? ' folio-row-active' : '';
            html += `<tr class="folio-recent-row${active}" data-id="${r.id}" style="cursor:pointer;">
                <td><strong>${escapeHtml(tipoDteLabel(r.tipo_dte))} ${escapeHtml(r.folio)}</strong></td>
                <td>${escapeHtml(r.origen || r.proveedor_nombre || '—')}<div class="cost-doc-ref">${escapeHtml(r.rut_emisor || '')}</div></td>
                <td>${escapeHtml(r.fecha_emision || '—')}</td>
                <td>${escapeHtml(formatDateTime(r.created_at))}</td>
                <td>${escapeHtml(r.items_count)}</td>
                <td style="text-align:right">$${formatNumber(r.monto_total)}</td>
                <td>${escapeHtml(r.estado || '—')}</td>
                <td><button type="button" class="button button-small btn-analyze-recent" data-id="${r.id}">Analizar</button></td>
            </tr>`;
        });
        $tbody.html(html);
    }

    $('#btn-folio-recent-apply').on('click', function() {
        folioRecentPage = 1;
        folioRecentSort.field = $('#folio-date-field').val() || 'fecha_emision';
        folioRecentSort.order = $('#folio-order').val() || 'DESC';
        loadRecentFolios();
    });

    $('#btn-folio-recent-clear').on('click', function() {
        $('#folio-date-from, #folio-date-to, #folio-recent-search').val('');
        $('#folio-origen').val('');
        $('#folio-date-field').val('fecha_emision');
        $('#folio-order').val('DESC');
        folioRecentSort = { field: 'fecha_emision', order: 'DESC' };
        folioRecentPage = 1;
        loadRecentFolios();
    });

    $('#folio-recent-search').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            folioRecentPage = 1;
            loadRecentFolios();
        }
    });

    $('#folio-recent-table th.sortable').on('click', function() {
        const field = $(this).data('sort');
        if (folioRecentSort.field === field) {
            folioRecentSort.order = folioRecentSort.order === 'DESC' ? 'ASC' : 'DESC';
        } else {
            folioRecentSort.field = field;
            folioRecentSort.order = 'DESC';
        }
        if (field === 'fecha_emision' || field === 'created_at') {
            $('#folio-date-field').val(field);
        }
        $('#folio-order').val(folioRecentSort.order);
        folioRecentPage = 1;
        loadRecentFolios();
    });

    $('#btn-folio-prev').on('click', function() {
        if (folioRecentPage > 1) {
            folioRecentPage--;
            loadRecentFolios();
        }
    });

    $('#btn-folio-next').on('click', function() {
        if (folioRecentPage < folioRecentTotalPages) {
            folioRecentPage++;
            loadRecentFolios();
        }
    });

    $(document).on('click', '.folio-recent-row', function(e) {
        if ($(e.target).closest('button').length) return;
        const id = $(this).data('id');
        selectedFolioId = id;
        $('.folio-recent-row').removeClass('folio-row-active');
        $(this).addClass('folio-row-active');
        loadFolioAnalysis(id);
    });

    $(document).on('click', '.btn-analyze-recent', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        selectedFolioId = id;
        $('.folio-recent-row').removeClass('folio-row-active');
        $(this).closest('tr').addClass('folio-row-active');
        loadFolioAnalysis(id);
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
        if (!$(e.target).closest('.search-results, #analysis-folio-search, #btn-analyze-folio, #alerts-folio-search, #btn-alerts-folio-search, #add-product-search').length) {
            $('.search-results').removeClass('active');
        }
    });
    
    // Alerts tab
    let alertsMode = 'up0';
    let alertsMinPct = 0;

    function initAlertsDefaults() {
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - 30);
        if (!$('#alert-date-to').val()) {
            $('#alert-date-to').val(to.toISOString().slice(0, 10));
        }
        if (!$('#alert-date-from').val()) {
            $('#alert-date-from').val(from.toISOString().slice(0, 10));
        }
    }

    function setAlertsMode(mode, minPct) {
        alertsMode = mode || 'up0';
        alertsMinPct = typeof minPct === 'number' ? minPct : parseFloat(minPct) || 0;
        $('.alerts-shortcut').removeClass('button-primary');
        $('.alerts-shortcut[data-mode="' + alertsMode + '"]').addClass('button-primary');

        if (alertsMode === 'margin') {
            $('#alerts-description').text(
                'Productos donde el precio de venta es menor al umbral × último costo registrado.'
            );
            $('#alerts-increase-filters').hide();
            $('#alerts-increases-wrap').hide();
            $('#alerts-margin-filters').show();
            $('#alerts-margin-wrap').show();
        } else {
            $('#alerts-description').text(
                'Alzas de costo de compra respecto a la última factura del mismo par (proveedor + código).'
            );
            $('#alerts-margin-filters').hide();
            $('#alerts-margin-wrap').hide();
            $('#alerts-increase-filters').show();
            $('#alerts-increases-wrap').show();
        }
    }

    function refreshAlertsView() {
        if (alertsMode === 'margin') {
            loadAlerts();
        } else {
            loadCostIncreases(alertsMinPct);
        }
    }

    function loadCostIncreases(minPct) {
        const $tbody = $('#alerts-increases-body');
        $tbody.html('<tr class="loading-row"><td colspan="6"><span class="spinner is-active"></span> Cargando alzas…</td></tr>');

        $.post(ajaxurl, {
            action: 'riverso_cost_list_increases',
            nonce: nonce,
            min_pct: minPct,
            date_from: $('#alert-date-from').val(),
            date_to: $('#alert-date-to').val(),
            margin_threshold: $('#alert-threshold').val() || 1.5,
            limit: 100
        }, function(res) {
            if (!res || !res.success) {
                $tbody.html('<tr><td colspan="6">Error: ' + escapeHtml((res && res.data) || 'carga') + '</td></tr>');
                return;
            }
            renderCostIncreases(res.data.items || []);
        }).fail(function() {
            $tbody.html('<tr><td colspan="6">Error de red</td></tr>');
        });
    }

    function renderCostIncreases(items) {
        const $tbody = $('#alerts-increases-body');
        const highlightPct = parseFloat($('#alert-highlight-pct').val()) || 10;

        if (!items.length) {
            $tbody.html('<tr><td colspan="6" style="text-align:center;padding:30px;">Sin alzas de costo en el rango</td></tr>');
            return;
        }

        let html = '';
        items.forEach(function(row) {
            const upHl = row.delta_pct >= highlightPct;
            const marginHl = !!row.margin_alert;
            const cls = (upHl ? ' alert-highlight-up' : '') + (marginHl ? ' alert-highlight-margin' : '');
            let marginHtml = '—';
            if (row.margin_pct !== null && row.margin_pct !== undefined) {
                const mCls = marginHl ? 'margin-danger' : '';
                marginHtml = '<span class="margin-badge ' + mCls + '">' + escapeHtml(String(row.margin_pct)) + '%</span>';
                if (row.sale_price != null) {
                    marginHtml += '<div class="alert-prod-code">PV $' + formatNumber(row.sale_price) + '</div>';
                }
            }

            html += `<tr class="${cls.trim()}" data-factura="${row.factura_id}">
                <td>
                    <div>${escapeHtml(row.nombre || '—')}</div>
                    <div class="alert-prod-code"><code>${escapeHtml(row.codigo_proveedor || '—')}</code></div>
                </td>
                <td>
                    <div>${escapeHtml(row.proveedor_nombre || '—')}</div>
                    <div class="alert-prod-code">${escapeHtml(tipoDteLabel(row.tipo_dte))} ${escapeHtml(row.folio)} · ${escapeHtml(row.fecha_emision || '')}</div>
                </td>
                <td style="text-align:right" class="alert-cost-stack">
                    <div>$${formatNumber(row.costo_anterior)}</div>
                    <div><strong>→ $${formatNumber(row.costo_actual)}</strong></div>
                    <div class="trend-up">+$${formatNumber(row.delta)}</div>
                </td>
                <td style="text-align:right" class="trend-up">+${escapeHtml(String(row.delta_pct))}%</td>
                <td style="text-align:center">${marginHtml}</td>
                <td>
                    <div class="alert-actions">
                        <button type="button" class="button button-small btn-alert-open-evolution"
                            data-proveedor="${escapeHtml(row.proveedor_id)}"
                            data-codigo="${escapeHtml(row.codigo_proveedor || '')}"
                            data-nombre="${escapeHtml(row.nombre || '')}"
                            data-sku="${escapeHtml(row.sku_local || '')}">Ver evolución</button>
                        <button type="button" class="button button-small btn-alert-open-folio" data-id="${row.factura_id}">Ver folio</button>
                    </div>
                </td>
            </tr>`;
        });
        $tbody.html(html);
    }

    function openEvolutionFromAlerts(opts) {
        opts = opts || {};
        opts.doc_type = opts.doc_type || 'factura';

        if (!window.riversoCostExplorer) {
            alert('Explorador de costos no disponible.');
            return;
        }
        if (opts.proveedor_id && opts.codigo_proveedor) {
            riversoCostExplorer.openEvolutionModal(opts);
            return;
        }
        if (opts.term) {
            riversoCostExplorer.openEvolutionModalByTerm(opts.term, opts);
            return;
        }
        alert('No se pudo abrir la evolución para este ítem.');
    }

    function switchCostTab(tab) {
        const $app = $('.riverso-cost-history-app').first();
        $app.find('> .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $app.find('> .nav-tab-wrapper .nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $app.find('> .tab-content').hide();
        $app.find('#tab-' + tab).show();
    }

    function openAnalysisFromAlerts(facturaId) {
        openFolioAnalysisModal(facturaId);
    }

    function closeFolioAnalysisModal() {
        $('#alerts-folio-modal').attr('hidden', true);
        if ($('#rce-evo-modal').is('[hidden]') && $('#rce-doc-modal').is('[hidden]')) {
            $('body').css('overflow', '');
        }
    }

    function openFolioAnalysisModal(facturaId) {
        const $modal = $('#alerts-folio-modal');
        if (!$modal.length) {
            switchCostTab('analysis');
            loadRecentFolios();
            loadFolioAnalysis(facturaId);
            return;
        }
        if (!$modal.parent().is('body')) {
            $modal.appendTo('body');
        }
        selectedFolioId = parseInt(facturaId, 10) || null;
        $('#alerts-folio-title').text('Cargando…');
        $('#alerts-folio-meta').text('');
        $('#alerts-folio-header').empty();
        $('#alerts-folio-body').html('<tr><td colspan="9" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> Analizando…</td></tr>');
        $('#btn-alerts-folio-print').prop('disabled', true);
        $modal.removeAttr('hidden');
        $('body').css('overflow', 'hidden');

        $.post(ajaxurl, {
            action: 'riverso_cost_analyze_invoice',
            nonce: nonce,
            factura_id: facturaId
        }, function(res) {
            if (!res || !res.success) {
                $('#alerts-folio-body').html('<tr><td colspan="9">Error: ' + escapeHtml((res && res.data) || 'análisis') + '</td></tr>');
                return;
            }
            lastFolioAnalysis = res.data;
            folioShowDecimals = $('#alerts-folio-toggle-decimals').is(':checked');
            renderFolioAnalysisModal(res.data);
            $('#btn-alerts-folio-print').prop('disabled', false);
        }).fail(function() {
            $('#alerts-folio-body').html('<tr><td colspan="9">Error de red</td></tr>');
            $('#btn-alerts-folio-print').prop('disabled', true);
        });
    }

    function renderFolioAnalysisModal(data) {
        const inv = data.invoice || {};
        $('#alerts-folio-title').text(tipoDteLabel(inv.tipo_dte) + ' N° ' + (inv.folio || ''));
        $('#alerts-folio-meta').text(
            (inv.proveedor_nombre || '') + ' · RUT ' + (inv.rut_emisor || '—') +
            ' · Doc. ' + (inv.fecha_emision || '—') +
            ' · Ingreso ' + formatDateTime(inv.created_at)
        );

        let header = '';
        header += docField('Proveedor', inv.proveedor_nombre);
        header += docField('Fecha documento', inv.fecha_emision);
        header += docField('Fecha ingreso', formatDateTime(inv.created_at));
        header += docField('Estado', inv.estado);
        header += docField('Neto', formatCostMoney(inv.monto_neto));
        header += docField('Total', formatCostMoney(inv.monto_total));
        $('#alerts-folio-header').html(header);

        const rows = data.rows || [];
        if (!rows.length) {
            $('#alerts-folio-body').html('<tr><td colspan="9" style="text-align:center;padding:20px;">Sin líneas de producto</td></tr>');
            $('#btn-alerts-folio-print').prop('disabled', true);
            return;
        }

        let html = '';
        rows.forEach(function(row) {
            const tClass = trendClass(row.trend);
            const changed = row.trend === 'subio' || row.trend === 'bajo';
            const prevInv = row.prev_invoice;
            let prevInvHtml = '—';
            if (prevInv && prevInv.costo_unitario !== null && prevInv.costo_unitario !== undefined) {
                prevInvHtml = `<div><strong>${formatCostMoney(prevInv.costo_unitario)}</strong></div>` +
                    `<div class="cost-doc-ref">${escapeHtml(tipoDteLabel(prevInv.tipo_dte))} ${escapeHtml(prevInv.folio)} · ${escapeHtml(prevInv.fecha_emision || '')}</div>`;
            }
            let prevQuoteHtml = '—';
            if (row.prev_quote && row.prev_quote.costo_unitario != null) {
                prevQuoteHtml = formatCostMoney(row.prev_quote.costo_unitario);
            }
            const deltaCls = row.trend === 'subio' ? 'trend-up' : (row.trend === 'bajo' ? 'trend-down' : '');
            let deltaText = '—';
            if (row.delta !== null && row.delta !== undefined) {
                if (row.delta < 0) deltaText = '-' + formatCostMoney(Math.abs(row.delta));
                else if (row.delta > 0) deltaText = '+' + formatCostMoney(Math.abs(row.delta));
                else deltaText = formatCostMoney(0);
            }
            html += `<tr class="${tClass}${changed ? ' has-change' : ''}">
                <td>${escapeHtml(row.numero_linea)}</td>
                <td><code>${escapeHtml(row.codigo_proveedor || '—')}</code></td>
                <td>${escapeHtml(row.nombre || '—')}</td>
                <td style="text-align:right">${formatCostMoney(row.costo_actual)}</td>
                <td>${prevInvHtml}</td>
                <td>${prevQuoteHtml}</td>
                <td class="${tClass}"><strong>${escapeHtml(trendLabel(row.trend))}</strong></td>
                <td style="text-align:right" class="${deltaCls}">${deltaText}</td>
                <td style="text-align:right" class="${deltaCls}">${formatDeltaPct(row.delta_pct)}</td>
            </tr>`;
        });
        $('#alerts-folio-body').html(html);
        $('#btn-alerts-folio-print').prop('disabled', false);
    }

    $(document).on('click', '[data-alerts-folio-close]', closeFolioAnalysisModal);

    $('#alerts-folio-toggle-decimals').on('change', function() {
        folioShowDecimals = $(this).is(':checked');
        if (lastFolioAnalysis && !$('#alerts-folio-modal').is('[hidden]')) {
            renderFolioAnalysisModal(lastFolioAnalysis);
        }
    });

    $('#btn-alerts-folio-print').on('click', function() {
        if (!lastFolioAnalysis) return;
        folioShowDecimals = $('#alerts-folio-toggle-decimals').is(':checked');
        const html = buildFolioPrintHtml(lastFolioAnalysis);
        const w = window.open('', '_blank');
        if (!w) {
            alert('Permite ventanas emergentes para imprimir');
            return;
        }
        w.document.write(html);
        w.document.close();
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$('#alerts-folio-modal').is('[hidden]')) {
            closeFolioAnalysisModal();
        }
    });

    function searchAlertsFolios(term) {
        const $box = $('#alerts-folio-search-results');
        if (!term) return;
        $box.html('<div class="search-result-item">Buscando…</div>').addClass('active');
        $.post(ajaxurl, {
            action: 'riverso_cost_search_invoices',
            nonce: nonce,
            term: term,
            limit: 15
        }, function(res) {
            if (!res || !res.success) {
                $box.html('<div class="search-result-item">Error: ' + escapeHtml((res && res.data) || 'búsqueda') + '</div>');
                return;
            }
            const results = res.data.results || [];
            if (!results.length) {
                $box.html('<div class="search-result-item">Sin facturas de productos</div>');
                return;
            }
            let html = '';
            results.forEach(function(r) {
                html += `<button type="button" class="search-result-item alerts-folio-result" data-id="${r.id}" style="width:100%;text-align:left;border:0;background:#fff;cursor:pointer;">
                    <span class="product-name">${escapeHtml(tipoDteLabel(r.tipo_dte))} ${escapeHtml(r.folio)} — ${escapeHtml(r.proveedor_nombre || '')}</span>
                    <span class="product-sku">${escapeHtml(r.fecha_emision || '')} · $${formatNumber(r.monto_total)} · ${escapeHtml(r.estado || '')}</span>
                </button>`;
            });
            $box.html(html);
        }).fail(function() {
            $box.html('<div class="search-result-item">Error de red</div>');
        });
    }

    $('.alerts-shortcut').on('click', function() {
        const mode = $(this).data('mode');
        const minPct = parseFloat($(this).data('min-pct'));
        setAlertsMode(mode, isNaN(minPct) ? 0 : minPct);
        refreshAlertsView();
    });

    $('#btn-refresh-alerts').on('click', function() {
        if (alertsMode !== 'margin') {
            loadCostIncreases(alertsMinPct);
        }
    });

    $('#alert-date-from, #alert-date-to, #alert-threshold, #alert-highlight-pct').on('change', function() {
        if (alertsMode !== 'margin') {
            loadCostIncreases(alertsMinPct);
        }
    });

    $('#btn-refresh-margin-alerts, #alert-margin-threshold').on('change click', function() {
        if (alertsMode === 'margin') {
            loadAlerts();
        }
    });

    $(document).on('click', '.btn-alert-open-folio', function(e) {
        e.stopPropagation();
        openAnalysisFromAlerts($(this).data('id'));
    });

    $(document).on('click', '.btn-alert-open-evolution', function(e) {
        e.stopPropagation();
        const $btn = $(this);
        openEvolutionFromAlerts({
            proveedor_id: $btn.data('proveedor') || null,
            codigo_proveedor: $btn.data('codigo') || null,
            nombre: $btn.data('nombre') || '',
            sku_local: $btn.data('sku') || '',
            term: $btn.data('term') || $btn.data('sku') || '',
        });
    });

    $(document).on('click', '#alerts-increases-body tr[data-factura]', function(e) {
        if ($(e.target).closest('button').length) return;
        // no auto-open on row click (hay dos acciones)
    });

    $('#alerts-folio-search').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchAlertsFolios($.trim($(this).val()));
        }
    });

    $('#btn-alerts-folio-search').on('click', function() {
        searchAlertsFolios($.trim($('#alerts-folio-search').val()));
    });

    $(document).on('click', '.alerts-folio-result', function() {
        const id = $(this).data('id');
        $('#alerts-folio-search-results').removeClass('active').empty();
        openAnalysisFromAlerts(id);
    });

    setAlertsMode('up0', 0);

    function loadAlerts() {
        const threshold = $('#alert-margin-threshold').val() || $('#alert-threshold').val();
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
            $tbody.html('<tr><td colspan="8" style="text-align:center;padding:30px;">No hay alertas de margen bajo</td></tr>');
            return;
        }

        let html = '';
        alerts.forEach(function(alert) {
            const sku = alert.sku || '';
            html += `<tr>
                <td>${alert.product_name || '-'}</td>
                <td><code>${sku || '-'}</code></td>
                <td>${alert.supplier_name || '-'}</td>
                <td style="text-align:right">$${formatNumber(alert.latest_cost)}</td>
                <td style="text-align:right">$${formatNumber(alert.current_price)}</td>
                <td style="text-align:center">
                    <span class="margin-badge margin-danger">${alert.margin_percent}%</span>
                </td>
                <td>${alert.cost_date}</td>
                <td style="white-space:nowrap;">
                    <button type="button" class="button button-small btn-alert-open-evolution"
                        data-term="${escapeHtml(sku || alert.product_name || '')}"
                        data-sku="${escapeHtml(sku)}">Ver evolución</button>
                    <a href="<?php echo admin_url('post.php?action=edit&post='); ?>${alert.product_id}"
                       class="button button-small" target="_blank">
                        Editar precio
                    </a>
                </td>
            </tr>`;
        });

        $tbody.html(html);
    }

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
                $('.riverso-cost-history-app > .nav-tab-wrapper .nav-tab[data-tab="history"]').click();
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
</div><!-- .riverso-cost-history-app -->
