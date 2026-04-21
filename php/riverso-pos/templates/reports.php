<?php
/**
 * Template: Reportes
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap riverso-pos-wrap">
    <h1>
        <span class="dashicons dashicons-chart-area"></span>
        <?php _e('Reportes', 'riverso-pos'); ?>
    </h1>
    
    <hr class="wp-header-end">
    
    <!-- Filtros -->
    <div class="reports-filters">
        <div class="filter-group">
            <label><?php _e('Tipo de Reporte', 'riverso-pos'); ?></label>
            <select id="report-type">
                <optgroup label="Ventas">
                    <option value="sales_summary">Resumen de Ventas</option>
                    <option value="sales_by_day">Ventas por Día</option>
                    <option value="sales_by_product">Ventas por Producto</option>
                    <option value="sales_by_cashier">Ventas por Cajero</option>
                    <option value="pos_sessions">Sesiones POS</option>
                </optgroup>
                <optgroup label="Compras">
                    <option value="invoices">Facturas Recibidas</option>
                    <option value="costs">Historial de Costos</option>
                </optgroup>
                <optgroup label="Inventario">
                    <option value="stock">Stock Actual</option>
                    <option value="low_stock">Stock Bajo</option>
                </optgroup>
                <optgroup label="Operaciones">
                    <option value="tasks">Tareas</option>
                </optgroup>
            </select>
        </div>
        
        <div class="filter-group">
            <label><?php _e('Desde', 'riverso-pos'); ?></label>
            <input type="date" id="date-from" value="<?php echo date('Y-m-01'); ?>">
        </div>
        
        <div class="filter-group">
            <label><?php _e('Hasta', 'riverso-pos'); ?></label>
            <input type="date" id="date-to" value="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <div class="filter-group">
            <label>&nbsp;</label>
            <button type="button" id="btn-generate-report" class="button button-primary">
                <span class="dashicons dashicons-chart-bar"></span>
                <?php _e('Generar Reporte', 'riverso-pos'); ?>
            </button>
        </div>
        
        <div class="filter-group">
            <label>&nbsp;</label>
            <button type="button" id="btn-export-csv" class="button" disabled>
                <span class="dashicons dashicons-download"></span>
                <?php _e('Exportar CSV', 'riverso-pos'); ?>
            </button>
        </div>
    </div>
    
    <!-- Área de resultados -->
    <div id="report-results" class="report-results">
        <div class="report-placeholder">
            <span class="dashicons dashicons-chart-area"></span>
            <p><?php _e('Selecciona un tipo de reporte y haz clic en "Generar Reporte"', 'riverso-pos'); ?></p>
        </div>
    </div>
</div>

<style>
.reports-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    background: #fff;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 500;
    margin-bottom: 5px;
    color: #1d2327;
}

.filter-group select,
.filter-group input {
    min-width: 180px;
    padding: 8px 12px;
}

.filter-group .button {
    height: 38px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.report-results {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    min-height: 400px;
}

.report-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 80px 20px;
    color: #666;
}

.report-placeholder .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #ddd;
    margin-bottom: 20px;
}

.report-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
}

.report-loading .spinner {
    float: none;
    margin: 0 10px 0 0;
}

/* Resumen de ventas */
.sales-summary {
    padding: 20px;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
    color: #fff;
    padding: 20px;
    border-radius: 10px;
}

.summary-card.pos {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
}

.summary-card.online {
    background: linear-gradient(135deg, #6f42c1 0%, #5a2d9e 100%);
}

.summary-card h3 {
    margin: 0 0 15px 0;
    font-size: 14px;
    text-transform: uppercase;
    opacity: 0.9;
}

.summary-card .amount {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 5px;
}

.summary-card .count {
    font-size: 14px;
    opacity: 0.8;
}

.payment-breakdown {
    margin-top: 20px;
}

.payment-breakdown h4 {
    margin: 0 0 15px 0;
}

.payment-bar {
    display: flex;
    height: 30px;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 10px;
}

.payment-bar div {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #fff;
    min-width: 50px;
}

.payment-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.payment-legend span {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 13px;
}

.payment-legend .dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

/* Tabla de reporte */
.report-table-container {
    padding: 20px;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    padding: 10px 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.report-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #1d2327;
}

.report-table tr:hover {
    background: #f9f9f9;
}

.report-table .number {
    text-align: right;
    font-family: monospace;
}

.report-table .status-badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 12px;
}

/* Gráfico */
.report-chart {
    padding: 20px;
}

.chart-container {
    position: relative;
    height: 300px;
}

/* Stock bajo alert */
.low-stock-alert {
    background: #f8d7da;
    color: #721c24;
    padding: 5px 10px;
    border-radius: 4px;
    font-weight: 500;
}

.stock-ok {
    color: #28a745;
}

/* Totales */
.report-totals {
    background: #f8f9fa;
    padding: 15px 20px;
    border-top: 2px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 30px;
}

.report-totals .total-item {
    text-align: right;
}

.report-totals .total-label {
    font-size: 12px;
    color: #666;
}

.report-totals .total-value {
    font-size: 18px;
    font-weight: 700;
    color: #2271b1;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce("riverso_pos_nonce"); ?>';
    const ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
    let currentChart = null;
    let currentData = null;
    
    const paymentColors = {
        'cash': '#28a745',
        'card': '#007bff',
        'transfer': '#6f42c1',
        'bacs': '#17a2b8',
        'cod': '#ffc107',
        'cheque': '#fd7e14',
        'other': '#6c757d'
    };
    
    // Generar reporte
    $('#btn-generate-report').on('click', function() {
        const reportType = $('#report-type').val();
        const dateFrom = $('#date-from').val();
        const dateTo = $('#date-to').val();
        
        $('#report-results').html(`
            <div class="report-loading">
                <span class="spinner is-active"></span>
                <span>Generando reporte...</span>
            </div>
        `);
        
        $.post(ajaxurl, {
            action: 'riverso_get_report',
            nonce: nonce,
            report_type: reportType,
            date_from: dateFrom,
            date_to: dateTo
        }, function(response) {
            if (response.success) {
                currentData = response.data;
                renderReport(response.data.report_type, response.data.data);
                $('#btn-export-csv').prop('disabled', false);
            } else {
                $('#report-results').html(`
                    <div class="report-placeholder">
                        <span class="dashicons dashicons-warning"></span>
                        <p>${response.data.message}</p>
                    </div>
                `);
            }
        });
    });
    
    function renderReport(type, data) {
        let html = '';
        
        switch (type) {
            case 'sales_summary':
                html = renderSalesSummary(data);
                break;
            case 'sales_by_day':
                html = renderSalesByDay(data);
                break;
            case 'sales_by_product':
            case 'sales_by_cashier':
            case 'pos_sessions':
            case 'stock':
            case 'low_stock':
            case 'invoices':
            case 'costs':
            case 'tasks':
                html = renderTable(type, data);
                break;
            default:
                html = '<div class="report-placeholder"><p>Reporte no disponible</p></div>';
        }
        
        $('#report-results').html(html);
        
        // Inicializar gráficos si es necesario
        if (type === 'sales_by_day') {
            renderDayChart(data);
        }
    }
    
    function renderSalesSummary(data) {
        const total = data.total;
        const pos = data.pos;
        const online = data.online;
        
        let html = `
            <div class="sales-summary">
                <div class="summary-cards">
                    <div class="summary-card">
                        <h3>Total Ventas</h3>
                        <div class="amount">$${formatNumber(total.amount)}</div>
                        <div class="count">${total.count} ventas</div>
                    </div>
                    <div class="summary-card pos">
                        <h3>Ventas POS</h3>
                        <div class="amount">$${formatNumber(pos.amount)}</div>
                        <div class="count">${pos.count} ventas</div>
                    </div>
                    <div class="summary-card online">
                        <h3>Ventas Online</h3>
                        <div class="amount">$${formatNumber(online.amount)}</div>
                        <div class="count">${online.count} ventas</div>
                    </div>
                </div>
        `;
        
        if (data.by_payment && data.by_payment.length > 0) {
            let totalPayments = data.by_payment.reduce((sum, p) => sum + parseFloat(p.total), 0);
            
            html += `
                <div class="payment-breakdown">
                    <h4>Por Método de Pago</h4>
                    <div class="payment-bar">
            `;
            
            data.by_payment.forEach(function(p) {
                const percent = (parseFloat(p.total) / totalPayments * 100).toFixed(1);
                const color = paymentColors[p.method] || paymentColors.other;
                if (percent > 5) {
                    html += `<div style="width:${percent}%;background:${color}">${percent}%</div>`;
                }
            });
            
            html += `</div><div class="payment-legend">`;
            
            data.by_payment.forEach(function(p) {
                const color = paymentColors[p.method] || paymentColors.other;
                html += `
                    <span>
                        <span class="dot" style="background:${color}"></span>
                        ${getPaymentLabel(p.method)}: $${formatNumber(p.total)} (${p.count})
                    </span>
                `;
            });
            
            html += `</div></div>`;
        }
        
        html += `</div>`;
        return html;
    }
    
    function renderSalesByDay(data) {
        let html = `
            <div class="report-chart">
                <canvas id="sales-chart"></canvas>
            </div>
            <div class="report-table-container">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th class="number">Cantidad</th>
                            <th class="number">Total</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        let grandTotal = 0;
        let grandCount = 0;
        
        data.forEach(function(row) {
            grandTotal += parseFloat(row.total);
            grandCount += parseInt(row.count);
            html += `
                <tr>
                    <td>${formatDate(row.date)}</td>
                    <td class="number">${row.count}</td>
                    <td class="number">$${formatNumber(row.total)}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
            <div class="report-totals">
                <div class="total-item">
                    <div class="total-label">Total Ventas</div>
                    <div class="total-value">${grandCount}</div>
                </div>
                <div class="total-item">
                    <div class="total-label">Total</div>
                    <div class="total-value">$${formatNumber(grandTotal)}</div>
                </div>
            </div>
        `;
        
        return html;
    }
    
    function renderDayChart(data) {
        if (currentChart) currentChart.destroy();
        
        const ctx = document.getElementById('sales-chart');
        if (!ctx) return;
        
        currentChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => formatDate(d.date)),
                datasets: [{
                    label: 'Ventas ($)',
                    data: data.map(d => parseFloat(d.total)),
                    backgroundColor: 'rgba(34, 113, 177, 0.7)',
                    borderColor: 'rgba(34, 113, 177, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + formatNumber(value);
                            }
                        }
                    }
                }
            }
        });
    }
    
    function renderTable(type, data) {
        let columns = [];
        let rows = [];
        let showTotals = false;
        let totals = {};
        
        switch (type) {
            case 'sales_by_product':
                columns = ['Producto', 'Cantidad', 'Total'];
                rows = data.map(r => [r.product_name, r.quantity, '$' + formatNumber(r.total)]);
                break;
                
            case 'sales_by_cashier':
                columns = ['Cajero', 'Ventas', 'Total'];
                rows = data.map(r => [r.cashier_name || 'Sin asignar', r.count, '$' + formatNumber(r.total)]);
                break;
                
            case 'pos_sessions':
                columns = ['ID', 'Caja', 'Usuario', 'Apertura', 'Cierre', 'Ventas', 'Total', 'Estado'];
                rows = data.map(r => [
                    r.id,
                    r.register_name,
                    r.user_name,
                    formatDateTime(r.opened_at),
                    r.closed_at ? formatDateTime(r.closed_at) : '-',
                    r.orders_count,
                    '$' + formatNumber(r.total_sales),
                    `<span class="status-badge" style="background:${r.status === 'open' ? '#28a745' : '#6c757d'};color:#fff">${r.status === 'open' ? 'Abierta' : 'Cerrada'}</span>`
                ]);
                break;
                
            case 'stock':
                columns = ['SKU', 'Producto', 'Stock', 'Precio', 'Estado'];
                rows = data.map(r => [
                    r.sku || '-',
                    r.name,
                    r.stock || 0,
                    r.price ? '$' + formatNumber(r.price) : '-',
                    r.stock_status === 'instock' ? '<span class="stock-ok">En stock</span>' : '<span class="low-stock-alert">Sin stock</span>'
                ]);
                break;
                
            case 'low_stock':
                columns = ['SKU', 'Producto', 'Stock Actual', 'Precio'];
                rows = data.map(r => [
                    r.sku || '-',
                    r.name,
                    `<span class="low-stock-alert">${r.stock}</span>`,
                    r.price ? '$' + formatNumber(r.price) : '-'
                ]);
                break;
                
            case 'invoices':
                showTotals = true;
                totals = data.totales;
                columns = ['Folio', 'Proveedor', 'Fecha', 'Neto', 'IVA', 'Total', 'Estado'];
                rows = data.facturas.map(r => [
                    r.folio,
                    r.proveedor_nombre || r.razon_social_emisor,
                    formatDate(r.fecha_emision),
                    '$' + formatNumber(r.monto_neto),
                    '$' + formatNumber(r.monto_iva),
                    '$' + formatNumber(r.monto_total),
                    r.estado
                ]);
                break;
                
            case 'costs':
                columns = ['Fecha', 'Producto', 'Proveedor', 'Costo Anterior', 'Costo Nuevo', 'Cambio'];
                rows = data.map(r => {
                    const prev = parseFloat(r.previous_cost) || 0;
                    const curr = parseFloat(r.new_cost) || 0;
                    const diff = curr - prev;
                    const diffClass = diff > 0 ? 'style="color:#dc3545"' : diff < 0 ? 'style="color:#28a745"' : '';
                    return [
                        formatDate(r.recorded_at),
                        r.product_name,
                        r.supplier_name || '-',
                        '$' + formatNumber(prev),
                        '$' + formatNumber(curr),
                        `<span ${diffClass}>${diff >= 0 ? '+' : ''}$${formatNumber(diff)}</span>`
                    ];
                });
                break;
                
            case 'tasks':
                columns = ['Título', 'Tipo', 'Prioridad', 'Asignado', 'Estado', 'Creado'];
                rows = data.tareas.map(r => [
                    r.titulo,
                    r.tipo,
                    r.prioridad,
                    r.asignado_nombre || '-',
                    r.estado,
                    formatDate(r.created_at)
                ]);
                break;
        }
        
        let html = `
            <div class="report-table-container">
                <table class="report-table">
                    <thead>
                        <tr>
                            ${columns.map(c => `<th>${c}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.length === 0 ? '<tr><td colspan="' + columns.length + '">No hay datos</td></tr>' : ''}
                        ${rows.map(r => `<tr>${r.map(c => `<td>${c}</td>`).join('')}</tr>`).join('')}
                    </tbody>
                </table>
            </div>
        `;
        
        if (showTotals && totals) {
            html += `
                <div class="report-totals">
                    <div class="total-item">
                        <div class="total-label">Facturas</div>
                        <div class="total-value">${totals.count}</div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Total Neto</div>
                        <div class="total-value">$${formatNumber(totals.total_neto)}</div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Total IVA</div>
                        <div class="total-value">$${formatNumber(totals.total_iva)}</div>
                    </div>
                    <div class="total-item">
                        <div class="total-label">Total</div>
                        <div class="total-value">$${formatNumber(totals.total)}</div>
                    </div>
                </div>
            `;
        }
        
        return html;
    }
    
    // Helpers
    function formatNumber(num) {
        return Math.round(parseFloat(num) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('es-CL');
    }
    
    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('es-CL') + ' ' + date.toLocaleTimeString('es-CL', {hour: '2-digit', minute: '2-digit'});
    }
    
    function getPaymentLabel(method) {
        const labels = {
            'cash': 'Efectivo',
            'card': 'Tarjeta',
            'transfer': 'Transferencia',
            'bacs': 'Banco',
            'cod': 'Contra entrega',
            'cheque': 'Cheque'
        };
        return labels[method] || method;
    }
    
    // Export CSV
    $('#btn-export-csv').on('click', function() {
        if (!currentData) return;
        
        // Simple CSV export
        let csv = '';
        const type = currentData.report_type;
        const data = currentData.data;
        
        // Build CSV based on report type
        // ... (simplified for brevity)
        
        alert('Exportación en desarrollo. Usa la tabla para copiar datos.');
    });
});
</script>
