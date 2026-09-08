<?php
/**
 * Centro de Precios — pestañas (wp-admin).
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$suppliers_table = $wpdb->prefix . 'riverso_proveedores';
$suppliers = $wpdb->get_results("SELECT id, nombre, rut FROM {$suppliers_table} WHERE activo = 1 ORDER BY nombre", ARRAY_A) ?: [];

if (!isset($stats) || !is_array($stats)) {
    $stats = class_exists('Riverso_Price_History_Module')
        ? Riverso_Price_History_Module::get_instance()->get_stats()
        : ['margin_alerts' => 0];
}

$can_manage = current_user_can('riverso_manage_prices');
$can_approve = current_user_can('riverso_approve_prices');
?>

<div class="riverso-price-history-app" data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>" data-can-approve="<?php echo $can_approve ? '1' : '0'; ?>">
    <nav class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="process">
            <span class="dashicons dashicons-yes-alt"></span> Procesar folios
        </a>
        <a href="#" class="nav-tab" data-tab="explorer">
            <span class="dashicons dashicons-search"></span> Buscar producto
        </a>
        <a href="#" class="nav-tab" data-tab="history">
            <span class="dashicons dashicons-list-view"></span> Historial
        </a>
        <a href="#" class="nav-tab" data-tab="analysis">
            <span class="dashicons dashicons-chart-area"></span> Análisis por folio
        </a>
        <a href="#" class="nav-tab" data-tab="folios">
            <span class="dashicons dashicons-archive"></span> Folios analizados
        </a>
        <a href="#" class="nav-tab" data-tab="alerts">
            <span class="dashicons dashicons-warning"></span> Alertas
            <?php if (!empty($stats['margin_alerts'])): ?>
                <span class="alert-badge"><?php echo (int) $stats['margin_alerts']; ?></span>
            <?php endif; ?>
        </a>
        <a href="#" class="nav-tab" data-tab="add">
            <span class="dashicons dashicons-plus-alt"></span> Registrar precio
        </a>
    </nav>

    <div class="tab-content" id="tab-process">
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/price-folio-process.php'; ?>
    </div>

    <div class="tab-content" id="tab-explorer" style="display:none;">
        <?php include RIVERSO_POS_PLUGIN_DIR . 'templates/partials/price-explorer.php'; ?>
    </div>

    <div class="tab-content" id="tab-history" style="display:none;">
        <div class="cost-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" id="ph-filter-search" placeholder="Producto, SKU, notas...">
                </div>
                <div class="filter-group">
                    <label>Canal</label>
                    <select id="ph-filter-canal">
                        <option value="">Todos</option>
                        <option value="local">Local</option>
                        <option value="online">Online</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Origen</label>
                    <select id="ph-filter-source">
                        <option value="">Todos</option>
                        <option value="manual">Manual</option>
                        <option value="recalc">Recálculo</option>
                        <option value="copy_local">Copia local</option>
                        <option value="system">Sistema</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Desde</label>
                    <input type="date" id="ph-filter-from">
                </div>
                <div class="filter-group">
                    <label>Hasta</label>
                    <input type="date" id="ph-filter-to">
                </div>
                <div class="filter-group filter-actions">
                    <button type="button" class="button" id="ph-btn-filter">Filtrar</button>
                    <button type="button" class="button" id="ph-btn-filter-clear">Limpiar</button>
                </div>
            </div>
        </div>
        <div class="table-container">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>SKU</th>
                        <th>Producto</th>
                        <th>Canal</th>
                        <th>Origen</th>
                        <th style="text-align:right">Anterior</th>
                        <th style="text-align:right">Nuevo</th>
                        <th style="text-align:right">Margen u.</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody id="ph-history-body">
                    <tr class="loading-row"><td colspan="9">Abre esta pestaña para cargar el historial.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="table-pagination" id="ph-history-pagination" style="display:none;">
            <button type="button" class="button" id="ph-hist-prev">&laquo; Anterior</button>
            <span id="ph-hist-page-info">Página 1</span>
            <button type="button" class="button" id="ph-hist-next">Siguiente &raquo;</button>
        </div>
    </div>

    <div class="tab-content" id="tab-analysis" style="display:none;">
        <div class="analysis-container">
            <div class="analysis-search">
                <h3>Analizar factura de compra</h3>
                <p class="description">Compara el costo de cada línea con el precio de venta local/online y el margen.</p>
                <div class="search-row">
                    <input type="text" id="ph-folio-search" placeholder="Folio, proveedor o RUT..." class="large-text" autocomplete="off">
                    <button type="button" class="button button-primary" id="ph-btn-search-folio">Buscar</button>
                </div>
                <div id="ph-folio-search-results" class="search-results"></div>
            </div>

            <div id="ph-folio-results" style="display:none;">
                <h3 id="ph-folio-title">—</h3>
                <p class="description" id="ph-folio-meta"></p>
                <div class="cost-doc-grid" id="ph-folio-header"></div>
                <div class="analysis-card full-width" style="padding:0;overflow:auto;">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Código</th>
                                <th>Descripción</th>
                                <th style="text-align:right">Costo factura</th>
                                <th style="text-align:right">Precio local</th>
                                <th style="text-align:right">Precio online</th>
                                <th style="text-align:right">Margen u.</th>
                                <th style="text-align:right">Margen total</th>
                                <th>Última factura</th>
                                <th>Tendencia</th>
                                <th>Alerta</th>
                            </tr>
                        </thead>
                        <tbody id="ph-folio-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="folio-recent-grid">
                <h3>Últimos folios</h3>
                <div class="cost-filters">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Campo fecha</label>
                            <select id="ph-folio-date-field">
                                <option value="fecha_emision">Fecha documento</option>
                                <option value="created_at">Fecha ingreso</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Desde</label>
                            <input type="date" id="ph-folio-from">
                        </div>
                        <div class="filter-group">
                            <label>Hasta</label>
                            <input type="date" id="ph-folio-to">
                        </div>
                        <div class="filter-group">
                            <label>Origen</label>
                            <select id="ph-folio-origen">
                                <option value="">Todos</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo esc_attr($s['id']); ?>"><?php echo esc_html($s['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Buscar</label>
                            <input type="text" id="ph-folio-recent-search" placeholder="Folio, RUT…">
                        </div>
                        <div class="filter-group filter-actions">
                            <button type="button" class="button button-primary" id="ph-folio-recent-apply">Filtrar</button>
                        </div>
                    </div>
                </div>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Origen</th>
                            <th>Fecha doc.</th>
                            <th>Ítems</th>
                            <th style="text-align:right">Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ph-folio-recent-body">
                        <tr><td colspan="6">Cargando…</td></tr>
                    </tbody>
                </table>
                <div class="table-pagination" id="ph-folio-recent-pagination" style="display:none;">
                    <button type="button" class="button" id="ph-folio-prev">&laquo; Anterior</button>
                    <span id="ph-folio-page-info">Página 1</span>
                    <button type="button" class="button" id="ph-folio-next">Siguiente &raquo;</button>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-folios" style="display:none;">
        <div class="cost-filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" id="ph-analyzed-search" placeholder="Folio o proveedor...">
                </div>
                <div class="filter-group filter-actions">
                    <button type="button" class="button" id="ph-analyzed-apply">Filtrar</button>
                </div>
            </div>
        </div>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Proveedor</th>
                    <th>Fecha doc.</th>
                    <th>Analizado</th>
                    <th>Por</th>
                    <th>Ítems</th>
                    <th>Alertas</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="ph-analyzed-body">
                <tr><td colspan="8">Cargando…</td></tr>
            </tbody>
        </table>
        <div class="table-pagination" id="ph-analyzed-pagination" style="display:none;">
            <button type="button" class="button" id="ph-analyzed-prev">&laquo; Anterior</button>
            <span id="ph-analyzed-page-info">Página 1</span>
            <button type="button" class="button" id="ph-analyzed-next">Siguiente &raquo;</button>
        </div>
    </div>

    <div class="tab-content" id="tab-alerts" style="display:none;">
        <div class="alerts-header">
            <h3>Alertas de margen bajo</h3>
            <p class="description">Alerta si el <strong>neto de venta</strong> (bruto ÷ 1,19 si afecto) queda bajo el factor mínimo × c_ref (neto).</p>
            <button type="button" class="button" id="ph-refresh-alerts">Actualizar</button>
        </div>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Producto</th>
                    <th>Canal</th>
                    <th style="text-align:right">c_ref (neto)</th>
                    <th style="text-align:right">p_asignado (bruto)</th>
                    <th>Estado</th>
                    <th>Uso</th>
                </tr>
            </thead>
            <tbody id="ph-alerts-body">
                <tr><td colspan="7">Cargando…</td></tr>
            </tbody>
        </table>
    </div>

    <div class="tab-content" id="tab-add" style="display:none;">
        <div class="analysis-search" style="max-width:640px;">
            <h3>Registro manual de precio</h3>
            <p class="description">El precio online se guarda inactivo: no se usa en POS ni se envía a WooCommerce hasta activarlo.</p>
            <p>
                <label>Buscar producto<br>
                    <input type="text" id="ph-add-search" class="large-text" placeholder="SKU o nombre..." autocomplete="off">
                </label>
            </p>
            <div id="ph-add-results" class="search-results"></div>
            <p id="ph-add-selected" class="description">Ningún producto seleccionado.</p>
            <p>
                <label>Canal
                    <select id="ph-add-canal">
                        <option value="local">Local</option>
                        <option value="online">Online (guardar sin usar)</option>
                    </select>
                </label>
            </p>
            <p>
                <label>Precio asignado (bruto)<br>
                    <input type="number" step="0.001" min="0" id="ph-add-price">
                </label>
            </p>
            <p>
                <label>Notas<br>
                    <textarea id="ph-add-notes" class="large-text" rows="2"></textarea>
                </label>
            </p>
            <p>
                <button type="button" class="button button-primary" id="ph-add-save" <?php echo $can_manage ? '' : 'disabled'; ?>>Guardar</button>
            </p>
            <div id="ph-add-msg"></div>
        </div>
    </div>
</div>
