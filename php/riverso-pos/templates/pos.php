<?php
/**
 * Template: Punto de Venta (POS)
 * 
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = wp_get_current_user();
global $wpdb;

// Verificar sesión activa
$active_session = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}riverso_pos_sessions 
    WHERE user_id = %d AND status = 'open'",
    get_current_user_id()
));
?>

<div class="wrap riverso-pos-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-store"></span>
        <?php _e('Punto de Venta', 'riverso-pos'); ?>
    </h1>
    
    <?php if ($active_session): ?>
        <span class="pos-session-badge active">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php echo esc_html($active_session->register_name); ?> - Abierta
        </span>
    <?php else: ?>
        <span class="pos-session-badge inactive">
            <span class="dashicons dashicons-warning"></span>
            Sin sesión activa
        </span>
    <?php endif; ?>
    
    <hr class="wp-header-end">
    
    <!-- Tabs -->
    <nav class="nav-tab-wrapper wp-clearfix">
        <a href="#pos-sale" class="nav-tab nav-tab-active" data-tab="pos-sale">
            <span class="dashicons dashicons-cart"></span> Venta
        </a>
        <a href="#pos-sessions" class="nav-tab" data-tab="pos-sessions">
            <span class="dashicons dashicons-clipboard"></span> Sesiones
        </a>
        <a href="#pos-held" class="nav-tab" data-tab="pos-held">
            <span class="dashicons dashicons-clock"></span> En Espera
        </a>
        <a href="#pos-history" class="nav-tab" data-tab="pos-history">
            <span class="dashicons dashicons-list-view"></span> Historial
        </a>
    </nav>
    
    <!-- Tab: Venta -->
    <div id="pos-sale" class="tab-content active">
        <?php if (!$active_session): ?>
            <div class="pos-no-session">
                <div class="pos-no-session-icon">
                    <span class="dashicons dashicons-lock"></span>
                </div>
                <h2><?php _e('Debes abrir una sesión de caja', 'riverso-pos'); ?></h2>
                <p><?php _e('Para realizar ventas, primero debes abrir una sesión de caja.', 'riverso-pos'); ?></p>
                <button type="button" class="button button-primary button-hero" id="btn-open-session-main">
                    <span class="dashicons dashicons-unlock"></span>
                    <?php _e('Abrir Caja', 'riverso-pos'); ?>
                </button>
            </div>
        <?php else: ?>
            <div class="pos-container">
                <!-- Panel izquierdo: Productos -->
                <div class="pos-products-panel">
                    <div class="pos-search-box">
                        <input type="text" id="pos-product-search" 
                               placeholder="<?php _e('Buscar: SKU, código proveedor, código barra, nombre...', 'riverso-pos'); ?>"
                               autocomplete="off">
                        <span class="pos-search-icon dashicons dashicons-search"></span>
                        <div class="search-hint">Busca por SKU interno, código de proveedor, código de barra o nombre del producto</div>
                    </div>
                    
                    <div id="pos-search-results" class="pos-search-results"></div>
                    
                    <!-- Carrito -->
                    <div class="pos-cart">
                        <div class="pos-cart-header">
                            <h3>
                                <span class="dashicons dashicons-cart"></span>
                                <?php _e('Carrito', 'riverso-pos'); ?>
                                <span id="pos-cart-count" class="cart-count">0</span>
                            </h3>
                            <button type="button" id="btn-clear-cart" class="button button-small" title="Vaciar carrito">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                        
                        <div id="pos-cart-items" class="pos-cart-items">
                            <div class="pos-cart-empty">
                                <span class="dashicons dashicons-cart"></span>
                                <p><?php _e('El carrito está vacío', 'riverso-pos'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Panel derecho: Totales y Pago -->
                <div class="pos-checkout-panel">
                    <!-- Cliente -->
                    <div class="pos-customer-box">
                        <h4>
                            <span class="dashicons dashicons-admin-users"></span>
                            <?php _e('Cliente', 'riverso-pos'); ?>
                        </h4>
                        <div class="pos-customer-search">
                            <input type="text" id="pos-customer-search" 
                                   placeholder="<?php _e('Buscar cliente...', 'riverso-pos'); ?>">
                            <button type="button" id="btn-new-customer" class="button" title="Nuevo cliente">
                                <span class="dashicons dashicons-plus-alt"></span>
                            </button>
                        </div>
                        <div id="pos-customer-results" class="pos-customer-results"></div>
                        <div id="pos-selected-customer" class="pos-selected-customer" style="display:none;">
                            <span class="customer-name"></span>
                            <button type="button" class="button-link remove-customer">&times;</button>
                        </div>
                        <input type="hidden" id="pos-customer-id" value="0">
                        <input type="hidden" id="pos-customer-name" value="Cliente Anónimo">
                    </div>
                    
                    <!-- Descuento -->
                    <div class="pos-discount-box">
                        <h4>
                            <span class="dashicons dashicons-tag"></span>
                            <?php _e('Descuento', 'riverso-pos'); ?>
                        </h4>
                        <div class="pos-discount-row">
                            <select id="pos-discount-type">
                                <option value="">Sin descuento</option>
                                <option value="percentage">Porcentaje (%)</option>
                                <option value="fixed">Monto fijo ($)</option>
                            </select>
                            <input type="number" id="pos-discount-value" value="0" min="0" step="1" disabled>
                            <button type="button" id="btn-apply-discount" class="button" disabled>
                                Aplicar
                            </button>
                        </div>
                    </div>
                    
                    <!-- Totales -->
                    <div class="pos-totals-box">
                        <div class="pos-total-row">
                            <span><?php _e('Subtotal:', 'riverso-pos'); ?></span>
                            <span id="pos-subtotal">$0</span>
                        </div>
                        <div class="pos-total-row discount" id="pos-discount-row" style="display:none;">
                            <span><?php _e('Descuento:', 'riverso-pos'); ?></span>
                            <span id="pos-discount-amount">-$0</span>
                        </div>
                        <div class="pos-total-row tax">
                            <span><?php _e('IVA (19%):', 'riverso-pos'); ?></span>
                            <span id="pos-tax">$0</span>
                        </div>
                        <div class="pos-total-row total">
                            <span><?php _e('TOTAL:', 'riverso-pos'); ?></span>
                            <span id="pos-total">$0</span>
                        </div>
                    </div>
                    
                    <!-- Método de pago -->
                    <div class="pos-payment-box">
                        <h4>
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php _e('Método de Pago', 'riverso-pos'); ?>
                        </h4>
                        <div class="pos-payment-methods">
                            <label class="payment-method active">
                                <input type="radio" name="payment_method" value="cash" checked>
                                <span class="dashicons dashicons-money-alt"></span>
                                <span>Efectivo</span>
                            </label>
                            <label class="payment-method">
                                <input type="radio" name="payment_method" value="card">
                                <span class="dashicons dashicons-credit-card"></span>
                                <span>Tarjeta</span>
                            </label>
                            <label class="payment-method">
                                <input type="radio" name="payment_method" value="transfer">
                                <span class="dashicons dashicons-bank"></span>
                                <span>Transfer</span>
                            </label>
                        </div>
                        <input type="text" id="pos-payment-reference" 
                               placeholder="<?php _e('Referencia (opcional)', 'riverso-pos'); ?>" 
                               style="display:none;">
                    </div>
                    
                    <!-- Notas -->
                    <div class="pos-notes-box">
                        <input type="text" id="pos-notes" placeholder="<?php _e('Notas de la venta...', 'riverso-pos'); ?>">
                    </div>
                    
                    <!-- Botones de acción -->
                    <div class="pos-actions">
                        <button type="button" id="btn-hold-order" class="button button-secondary">
                            <span class="dashicons dashicons-clock"></span>
                            <?php _e('Guardar', 'riverso-pos'); ?>
                        </button>
                        <button type="button" id="btn-complete-sale" class="button button-primary button-hero" disabled>
                            <span class="dashicons dashicons-yes"></span>
                            <?php _e('Completar Venta', 'riverso-pos'); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Tab: Sesiones -->
    <div id="pos-sessions" class="tab-content">
        <div class="pos-sessions-header">
            <h2><?php _e('Sesiones de Caja', 'riverso-pos'); ?></h2>
            <?php if (!$active_session): ?>
                <button type="button" id="btn-open-session" class="button button-primary">
                    <span class="dashicons dashicons-unlock"></span>
                    <?php _e('Abrir Sesión', 'riverso-pos'); ?>
                </button>
            <?php else: ?>
                <button type="button" id="btn-close-session" class="button button-secondary" 
                        data-session-id="<?php echo esc_attr($active_session->id); ?>">
                    <span class="dashicons dashicons-lock"></span>
                    <?php _e('Cerrar Sesión', 'riverso-pos'); ?>
                </button>
            <?php endif; ?>
        </div>
        
        <table class="wp-list-table widefat fixed striped" id="pos-sessions-table">
            <thead>
                <tr>
                    <th><?php _e('ID', 'riverso-pos'); ?></th>
                    <th><?php _e('Caja', 'riverso-pos'); ?></th>
                    <th><?php _e('Usuario', 'riverso-pos'); ?></th>
                    <th><?php _e('Apertura', 'riverso-pos'); ?></th>
                    <th><?php _e('Cierre', 'riverso-pos'); ?></th>
                    <th><?php _e('Monto Inicial', 'riverso-pos'); ?></th>
                    <th><?php _e('Ventas', 'riverso-pos'); ?></th>
                    <th><?php _e('Total', 'riverso-pos'); ?></th>
                    <th><?php _e('Estado', 'riverso-pos'); ?></th>
                    <th><?php _e('Acciones', 'riverso-pos'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="10" class="loading"><?php _e('Cargando...', 'riverso-pos'); ?></td></tr>
            </tbody>
        </table>
    </div>
    
    <!-- Tab: En Espera -->
    <div id="pos-held" class="tab-content">
        <h2><?php _e('Órdenes en Espera', 'riverso-pos'); ?></h2>
        
        <div id="pos-held-orders" class="pos-held-grid">
            <div class="loading"><?php _e('Cargando...', 'riverso-pos'); ?></div>
        </div>
    </div>
    
    <!-- Tab: Historial -->
    <div id="pos-history" class="tab-content">
        <h2><?php _e('Historial de Ventas POS', 'riverso-pos'); ?></h2>
        
        <div class="pos-history-filters">
            <input type="date" id="pos-history-date" value="<?php echo date('Y-m-d'); ?>">
            <button type="button" id="btn-load-history" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Cargar', 'riverso-pos'); ?>
            </button>
        </div>
        
        <table class="wp-list-table widefat fixed striped" id="pos-history-table">
            <thead>
                <tr>
                    <th><?php _e('# Orden', 'riverso-pos'); ?></th>
                    <th><?php _e('Cliente', 'riverso-pos'); ?></th>
                    <th><?php _e('Productos', 'riverso-pos'); ?></th>
                    <th><?php _e('Total', 'riverso-pos'); ?></th>
                    <th><?php _e('Pago', 'riverso-pos'); ?></th>
                    <th><?php _e('Estado', 'riverso-pos'); ?></th>
                    <th><?php _e('Fecha', 'riverso-pos'); ?></th>
                    <th><?php _e('Acciones', 'riverso-pos'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="8"><?php _e('Selecciona una fecha para ver el historial', 'riverso-pos'); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Abrir Sesión -->
<div id="modal-open-session" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2><?php _e('Abrir Sesión de Caja', 'riverso-pos'); ?></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="form-group">
                <label><?php _e('Nombre de Caja', 'riverso-pos'); ?></label>
                <input type="text" id="session-register-name" value="Caja 1">
            </div>
            <div class="form-group">
                <label><?php _e('Monto Inicial en Caja', 'riverso-pos'); ?></label>
                <input type="number" id="session-opening-amount" value="0" min="0" step="100">
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" onclick="document.getElementById('modal-open-session').style.display='none'">
                <?php _e('Cancelar', 'riverso-pos'); ?>
            </button>
            <button type="button" class="button button-primary" id="btn-confirm-open-session">
                <span class="dashicons dashicons-unlock"></span>
                <?php _e('Abrir Caja', 'riverso-pos'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Cerrar Sesión -->
<div id="modal-close-session" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2><?php _e('Cerrar Sesión de Caja', 'riverso-pos'); ?></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="pos-close-session-info">
                <div class="info-row">
                    <span><?php _e('Monto inicial:', 'riverso-pos'); ?></span>
                    <span id="close-opening-amount">$0</span>
                </div>
                <div class="info-row">
                    <span><?php _e('Ventas totales:', 'riverso-pos'); ?></span>
                    <span id="close-sales-total">$0</span>
                </div>
                <div class="info-row expected">
                    <span><?php _e('Monto esperado:', 'riverso-pos'); ?></span>
                    <span id="close-expected-amount">$0</span>
                </div>
            </div>
            <div class="form-group">
                <label><?php _e('Monto en Caja al Cerrar', 'riverso-pos'); ?></label>
                <input type="number" id="session-closing-amount" value="0" min="0" step="100">
            </div>
            <div class="form-group" id="close-difference-row" style="display:none;">
                <label><?php _e('Diferencia:', 'riverso-pos'); ?></label>
                <span id="close-difference" class="difference">$0</span>
            </div>
            <div class="form-group">
                <label><?php _e('Notas de cierre', 'riverso-pos'); ?></label>
                <textarea id="session-close-notes" rows="3"></textarea>
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" onclick="document.getElementById('modal-close-session').style.display='none'">
                <?php _e('Cancelar', 'riverso-pos'); ?>
            </button>
            <button type="button" class="button button-primary" id="btn-confirm-close-session">
                <span class="dashicons dashicons-lock"></span>
                <?php _e('Cerrar Caja', 'riverso-pos'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Nuevo Cliente -->
<div id="modal-new-customer" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content">
        <div class="riverso-modal-header">
            <h2><?php _e('Nuevo Cliente', 'riverso-pos'); ?></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div class="form-group">
                <label><?php _e('Nombre', 'riverso-pos'); ?> *</label>
                <input type="text" id="new-customer-name" required>
            </div>
            <div class="form-group">
                <label><?php _e('Email', 'riverso-pos'); ?></label>
                <input type="email" id="new-customer-email">
            </div>
            <div class="form-group">
                <label><?php _e('Teléfono', 'riverso-pos'); ?></label>
                <input type="text" id="new-customer-phone">
            </div>
            <div class="form-group">
                <label><?php _e('RUT', 'riverso-pos'); ?></label>
                <input type="text" id="new-customer-rut" placeholder="12.345.678-9">
            </div>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" onclick="document.getElementById('modal-new-customer').style.display='none'">
                <?php _e('Cancelar', 'riverso-pos'); ?>
            </button>
            <button type="button" class="button button-primary" id="btn-save-customer">
                <span class="dashicons dashicons-yes"></span>
                <?php _e('Guardar Cliente', 'riverso-pos'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Venta Exitosa -->
<div id="modal-sale-success" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content success-modal">
        <div class="success-icon">
            <span class="dashicons dashicons-yes-alt"></span>
        </div>
        <h2><?php _e('¡Venta Completada!', 'riverso-pos'); ?></h2>
        <p class="order-number">Orden #<span id="success-order-number"></span></p>
        <p class="order-total">Total: <span id="success-order-total"></span></p>
        <div class="success-actions">
            <button type="button" class="button" onclick="document.getElementById('modal-sale-success').style.display='none'">
                <?php _e('Cerrar', 'riverso-pos'); ?>
            </button>
            <button type="button" class="button button-primary" id="btn-new-sale">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Nueva Venta', 'riverso-pos'); ?>
            </button>
        </div>
    </div>
</div>

<style>
.riverso-pos-wrap {
    max-width: 100%;
    margin-right: 20px;
}

.pos-session-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 13px;
    margin-left: 15px;
    vertical-align: middle;
}

.pos-session-badge.active {
    background: #d4edda;
    color: #155724;
}

.pos-session-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.tab-content {
    display: none;
    padding: 20px 0;
}

.tab-content.active {
    display: block;
}

/* POS Sin Sesión */
.pos-no-session {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    margin-top: 20px;
}

.pos-no-session-icon .dashicons {
    font-size: 80px;
    width: 80px;
    height: 80px;
    color: #999;
}

.pos-no-session h2 {
    margin: 20px 0 10px;
}

/* POS Container */
.pos-container {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 20px;
    margin-top: 20px;
}

/* Panel Productos */
.pos-products-panel {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    min-height: 600px;
}

.pos-search-box {
    position: relative;
    margin-bottom: 15px;
}

.pos-search-box input {
    width: 100%;
    padding: 12px 15px 12px 40px;
    font-size: 16px;
    border: 2px solid #ddd;
    border-radius: 8px;
}

.pos-search-box input:focus {
    border-color: #2271b1;
    outline: none;
}

.pos-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #999;
}

.search-hint {
    font-size: 11px;
    color: #999;
    margin-top: 5px;
    padding-left: 5px;
}

/* Resultados de búsqueda */
.pos-search-results {
    position: absolute;
    width: calc(100% - 40px);
    max-height: 450px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 100;
    display: none;
}

.pos-search-results.show {
    display: block;
}

.search-results-header {
    padding: 8px 15px;
    background: #f0f6fc;
    font-size: 12px;
    color: #2271b1;
    border-bottom: 1px solid #ddd;
    position: sticky;
    top: 0;
}

.pos-product-item {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    transition: background 0.2s;
}

.pos-product-item:hover {
    background: #f0f6fc;
}

.pos-product-item.out-of-stock {
    background: #fff5f5;
    opacity: 0.7;
}

.pos-product-item.low-stock {
    background: #fffbeb;
}

.pos-product-item img {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 6px;
    margin-right: 12px;
    border: 1px solid #eee;
}

.pos-product-item .product-info {
    flex: 1;
    min-width: 0;
}

.pos-product-item .product-name {
    font-weight: 500;
    color: #1d2327;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pos-product-item .product-sku {
    font-size: 12px;
    color: #666;
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pos-product-item .product-extra {
    font-size: 11px;
    margin-top: 4px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.pos-product-item .stock-badge {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
}

.pos-product-item .stock-badge.ok {
    background: #d4edda;
    color: #155724;
}

.pos-product-item .stock-badge.warning {
    background: #fff3cd;
    color: #856404;
}

.pos-product-item .stock-badge.danger {
    background: #f8d7da;
    color: #721c24;
}

.pos-product-item .location-badge {
    background: #e3f2fd;
    color: #1565c0;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.pos-product-item .location-badge .dashicons {
    font-size: 12px;
    width: 12px;
    height: 12px;
}

.pos-product-item .supplier-code {
    background: #f3e5f5;
    color: #7b1fa2;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 10px;
}

.pos-product-item .match-source {
    color: #999;
    font-style: italic;
}

.pos-product-item .product-price-col {
    text-align: right;
    min-width: 80px;
}

.pos-product-item .product-price {
    font-weight: 600;
    color: #2271b1;
    font-size: 15px;
}

.pos-product-item .no-sell {
    color: #dc3545;
    font-size: 10px;
}

/* Carrito */
.pos-cart {
    flex: 1;
    display: flex;
    flex-direction: column;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.pos-cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    background: #f0f0f1;
    border-bottom: 1px solid #ddd;
}

.pos-cart-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.cart-count {
    background: #2271b1;
    color: #fff;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
}

.pos-cart-items {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
}

.pos-cart-empty {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.pos-cart-empty .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #ddd;
}

.cart-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border: 1px solid #eee;
    border-radius: 6px;
    margin-bottom: 8px;
    background: #fff;
}

.cart-item .item-info {
    flex: 1;
    min-width: 0;
}

.cart-item .item-name {
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cart-item .item-price {
    font-size: 13px;
    color: #666;
}

.cart-item .item-qty {
    display: flex;
    align-items: center;
    gap: 5px;
    margin: 0 15px;
}

.cart-item .item-qty input {
    width: 50px;
    text-align: center;
    padding: 5px;
}

.cart-item .item-total {
    font-weight: 600;
    min-width: 80px;
    text-align: right;
}

.cart-item .item-remove {
    color: #dc3545;
    cursor: pointer;
    padding: 5px;
}

/* Panel Checkout */
.pos-checkout-panel {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    flex-direction: column;
}

.pos-checkout-panel h4 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 12px 0;
    color: #1d2327;
    font-size: 14px;
}

.pos-customer-box,
.pos-discount-box,
.pos-payment-box,
.pos-notes-box {
    margin-bottom: 20px;
}

.pos-customer-search {
    display: flex;
    gap: 8px;
}

.pos-customer-search input {
    flex: 1;
}

.pos-customer-results {
    max-height: 150px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    display: none;
    margin-top: 5px;
}

.pos-customer-results.show {
    display: block;
}

.customer-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}

.customer-item:hover {
    background: #f0f6fc;
}

.pos-selected-customer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: #d4edda;
    border-radius: 4px;
    margin-top: 8px;
}

.pos-discount-row {
    display: flex;
    gap: 8px;
}

.pos-discount-row select {
    flex: 1;
}

.pos-discount-row input {
    width: 80px;
}

/* Totales */
.pos-totals-box {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.pos-total-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.pos-total-row.total {
    border-bottom: none;
    padding-top: 12px;
    margin-top: 5px;
    border-top: 2px solid #ddd;
    font-size: 20px;
    font-weight: 700;
    color: #2271b1;
}

.pos-total-row.discount span:last-child {
    color: #dc3545;
}

/* Métodos de pago */
.pos-payment-methods {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.payment-method {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.payment-method:hover {
    border-color: #2271b1;
}

.payment-method.active {
    border-color: #2271b1;
    background: #f0f6fc;
}

.payment-method input {
    display: none;
}

.payment-method .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    margin-bottom: 5px;
}

/* Acciones */
.pos-actions {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 10px;
    margin-top: auto;
}

.pos-actions .button-hero {
    padding: 15px 30px;
    font-size: 16px;
}

/* Sesiones */
.pos-sessions-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

/* Órdenes en espera */
.pos-held-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
}

.held-order-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
}

.held-order-card h4 {
    margin: 0 0 10px 0;
    display: flex;
    justify-content: space-between;
}

.held-order-card .total {
    font-size: 18px;
    font-weight: 600;
    color: #2271b1;
}

.held-order-card .actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
}

/* Historial */
.pos-history-filters {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

/* Modales */
.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}

.riverso-modal-content {
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 450px;
    max-height: 90vh;
    overflow: auto;
}

.riverso-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}

.riverso-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.riverso-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.riverso-modal-body {
    padding: 20px;
}

.riverso-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
}

/* Modal Success */
.success-modal {
    text-align: center;
    padding: 40px 30px;
}

.success-icon .dashicons {
    font-size: 80px;
    width: 80px;
    height: 80px;
    color: #28a745;
}

.success-modal h2 {
    color: #28a745;
    margin: 15px 0;
}

.success-modal .order-number {
    font-size: 24px;
    font-weight: 600;
}

.success-modal .order-total {
    font-size: 20px;
    color: #2271b1;
}

.success-actions {
    margin-top: 25px;
    display: flex;
    justify-content: center;
    gap: 15px;
}

/* Cierre de sesión */
.pos-close-session-info {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
}

.pos-close-session-info .info-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
}

.pos-close-session-info .info-row.expected {
    font-weight: 600;
    border-top: 1px solid #ddd;
    padding-top: 10px;
    margin-top: 5px;
}

.difference.negative {
    color: #dc3545;
}

.difference.positive {
    color: #28a745;
}

@media (max-width: 1200px) {
    .pos-container {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce("riverso_pos_nonce"); ?>';
    const ajaxurl = '<?php echo admin_url("admin-ajax.php"); ?>';
    let cart = [];
    let activeSessionId = <?php echo $active_session ? $active_session->id : 0; ?>;
    let selectedCustomerId = 0;
    let selectedCustomerName = 'Cliente Anónimo';
    let searchTimeout;
    
    // Tabs
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $('#' + tab).addClass('active');
        
        if (tab === 'pos-sessions') loadSessions();
        if (tab === 'pos-held') loadHeldOrders();
    });
    
    // Búsqueda de productos (mejorada)
    $('#pos-product-search').on('input', function() {
        const search = $(this).val().trim();
        clearTimeout(searchTimeout);
        
        if (search.length < 1) {
            $('#pos-search-results').removeClass('show').html('');
            return;
        }
        
        // Mostrar indicador de carga
        $('#pos-search-results').html('<div style="padding:15px;text-align:center;"><span class="spinner is-active" style="float:none;"></span> Buscando...</div>').addClass('show');
        
        searchTimeout = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'riverso_pos_search_products',
                nonce: nonce,
                search: search,
                include_out_of_stock: 'true'
            }, function(response) {
                if (response.success && response.data.products.length) {
                    let html = '';
                    response.data.products.forEach(function(product) {
                        // Determinar estado de stock
                        let stockClass = '';
                        let stockText = '';
                        if (product.stock_status === 'outofstock' || product.stock_quantity === 0) {
                            stockClass = 'out-of-stock';
                            stockText = '<span class="stock-badge danger">Sin stock</span>';
                        } else if (product.stock_quantity !== null && product.stock_quantity <= 5) {
                            stockClass = 'low-stock';
                            stockText = '<span class="stock-badge warning">' + product.stock_quantity + ' unid.</span>';
                        } else {
                            stockText = '<span class="stock-badge ok">' + (product.stock_display || '∞') + '</span>';
                        }
                        
                        // Info adicional
                        let extraInfo = '';
                        if (product.location) {
                            extraInfo += '<span class="location-badge" title="Ubicación"><span class="dashicons dashicons-location"></span>' + product.location + '</span>';
                        }
                        if (product.supplier_codes && product.supplier_codes.length > 0) {
                            extraInfo += '<span class="supplier-code" title="Código proveedor">' + product.supplier_codes[0] + '</span>';
                        }
                        if (product.match_source) {
                            extraInfo += '<span class="match-source">' + product.match_source + '</span>';
                        }
                        
                        html += `
                            <div class="pos-product-item ${stockClass}" data-product='${JSON.stringify(product).replace(/'/g, "&#39;")}'>
                                <img src="${product.image || '<?php echo wc_placeholder_img_src("thumbnail"); ?>'}" alt="">
                                <div class="product-info">
                                    <div class="product-name">${product.name}</div>
                                    <div class="product-sku">
                                        <strong>SKU:</strong> ${product.sku || 'N/A'} 
                                        ${stockText}
                                    </div>
                                    ${extraInfo ? '<div class="product-extra">' + extraInfo + '</div>' : ''}
                                </div>
                                <div class="product-price-col">
                                    <div class="product-price">$${formatNumber(product.price)}</div>
                                    ${!product.can_sell ? '<small class="no-sell">No disponible</small>' : ''}
                                </div>
                            </div>
                        `;
                    });
                    
                    // Añadir contador de resultados
                    html = '<div class="search-results-header">' + response.data.count + ' productos encontrados para "' + response.data.search + '"</div>' + html;
                    
                    $('#pos-search-results').html(html).addClass('show');
                } else {
                    $('#pos-search-results').html(`
                        <div style="padding:20px;text-align:center;color:#666;">
                            <span class="dashicons dashicons-search" style="font-size:32px;width:32px;height:32px;margin-bottom:10px;display:block;color:#ddd;"></span>
                            No se encontraron productos para "<strong>${search}</strong>"
                            <br><small>Intenta con SKU, código de barra o código de proveedor</small>
                        </div>
                    `).addClass('show');
                }
            });
        }, 250);
    });
    
    // Click fuera cierra resultados
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.pos-search-box').length) {
            $('#pos-search-results').removeClass('show');
        }
        if (!$(e.target).closest('.pos-customer-search').length) {
            $('#pos-customer-results').removeClass('show');
        }
    });
    
    // Agregar producto al carrito
    $(document).on('click', '.pos-product-item', function() {
        const product = $(this).data('product');
        addToCart(product);
        $('#pos-product-search').val('').focus();
        $('#pos-search-results').removeClass('show');
    });
    
    function addToCart(product) {
        const existingIndex = cart.findIndex(item => item.product_id === product.id);
        
        if (existingIndex > -1) {
            cart[existingIndex].quantity += 1;
        } else {
            cart.push({
                product_id: product.id,
                name: product.name,
                sku: product.sku,
                price: product.price,
                quantity: 1
            });
        }
        
        renderCart();
        updateTotals();
    }
    
    function renderCart() {
        if (cart.length === 0) {
            $('#pos-cart-items').html(`
                <div class="pos-cart-empty">
                    <span class="dashicons dashicons-cart"></span>
                    <p>El carrito está vacío</p>
                </div>
            `);
            $('#pos-cart-count').text('0');
            $('#btn-complete-sale').prop('disabled', true);
            return;
        }
        
        let html = '';
        let count = 0;
        
        cart.forEach(function(item, index) {
            const lineTotal = item.price * item.quantity;
            count += item.quantity;
            html += `
                <div class="cart-item" data-index="${index}">
                    <div class="item-info">
                        <div class="item-name">${item.name}</div>
                        <div class="item-price">$${formatNumber(item.price)} c/u</div>
                    </div>
                    <div class="item-qty">
                        <button type="button" class="button button-small qty-minus">-</button>
                        <input type="number" value="${item.quantity}" min="1" class="item-qty-input">
                        <button type="button" class="button button-small qty-plus">+</button>
                    </div>
                    <div class="item-total">$${formatNumber(lineTotal)}</div>
                    <span class="item-remove dashicons dashicons-dismiss"></span>
                </div>
            `;
        });
        
        $('#pos-cart-items').html(html);
        $('#pos-cart-count').text(count);
        $('#btn-complete-sale').prop('disabled', false);
    }
    
    // Modificar cantidad
    $(document).on('click', '.qty-minus', function() {
        const index = $(this).closest('.cart-item').data('index');
        if (cart[index].quantity > 1) {
            cart[index].quantity--;
            renderCart();
            updateTotals();
        }
    });
    
    $(document).on('click', '.qty-plus', function() {
        const index = $(this).closest('.cart-item').data('index');
        cart[index].quantity++;
        renderCart();
        updateTotals();
    });
    
    $(document).on('change', '.item-qty-input', function() {
        const index = $(this).closest('.cart-item').data('index');
        const qty = parseInt($(this).val()) || 1;
        cart[index].quantity = Math.max(1, qty);
        renderCart();
        updateTotals();
    });
    
    // Eliminar item
    $(document).on('click', '.item-remove', function() {
        const index = $(this).closest('.cart-item').data('index');
        cart.splice(index, 1);
        renderCart();
        updateTotals();
    });
    
    // Vaciar carrito
    $('#btn-clear-cart').on('click', function() {
        if (confirm('¿Vaciar el carrito?')) {
            cart = [];
            renderCart();
            updateTotals();
        }
    });
    
    // Actualizar totales
    function updateTotals() {
        const discountType = $('#pos-discount-type').val();
        const discountValue = parseFloat($('#pos-discount-value').val()) || 0;
        
        $.post(ajaxurl, {
            action: 'riverso_pos_get_cart_totals',
            nonce: nonce,
            items: JSON.stringify(cart),
            discount_type: discountType,
            discount_value: discountValue
        }, function(response) {
            if (response.success) {
                const data = response.data;
                $('#pos-subtotal').text('$' + formatNumber(data.subtotal));
                $('#pos-tax').text('$' + formatNumber(data.tax_total));
                $('#pos-total').text('$' + formatNumber(data.total));
                
                if (data.discount_amount > 0) {
                    $('#pos-discount-row').show();
                    $('#pos-discount-amount').text('-$' + formatNumber(data.discount_amount));
                } else {
                    $('#pos-discount-row').hide();
                }
            }
        });
    }
    
    // Búsqueda de clientes
    $('#pos-customer-search').on('input', function() {
        const search = $(this).val().trim();
        clearTimeout(searchTimeout);
        
        if (search.length < 2) {
            $('#pos-customer-results').removeClass('show');
            return;
        }
        
        searchTimeout = setTimeout(function() {
            $.post(ajaxurl, {
                action: 'riverso_pos_search_customers',
                nonce: nonce,
                search: search
            }, function(response) {
                if (response.success && response.data.customers.length) {
                    let html = '';
                    response.data.customers.forEach(function(customer) {
                        html += `
                            <div class="customer-item" data-id="${customer.id}" data-name="${customer.name}">
                                <strong>${customer.name}</strong><br>
                                <small>${customer.email || ''} ${customer.phone || ''}</small>
                            </div>
                        `;
                    });
                    $('#pos-customer-results').html(html).addClass('show');
                } else {
                    $('#pos-customer-results').removeClass('show');
                }
            });
        }, 300);
    });
    
    // Seleccionar cliente
    $(document).on('click', '.customer-item', function() {
        selectedCustomerId = $(this).data('id');
        selectedCustomerName = $(this).data('name');
        $('#pos-customer-id').val(selectedCustomerId);
        $('#pos-customer-name').val(selectedCustomerName);
        $('#pos-customer-search').hide();
        $('#pos-selected-customer').show().find('.customer-name').text(selectedCustomerName);
        $('#pos-customer-results').removeClass('show');
    });
    
    // Quitar cliente
    $(document).on('click', '.remove-customer', function() {
        selectedCustomerId = 0;
        selectedCustomerName = 'Cliente Anónimo';
        $('#pos-customer-id').val(0);
        $('#pos-customer-name').val(selectedCustomerName);
        $('#pos-customer-search').val('').show();
        $('#pos-selected-customer').hide();
    });
    
    // Nuevo cliente
    $('#btn-new-customer').on('click', function() {
        $('#new-customer-name, #new-customer-email, #new-customer-phone, #new-customer-rut').val('');
        $('#modal-new-customer').show();
    });
    
    $('#btn-save-customer').on('click', function() {
        const name = $('#new-customer-name').val().trim();
        if (!name) {
            alert('El nombre es requerido');
            return;
        }
        
        $.post(ajaxurl, {
            action: 'riverso_pos_create_customer',
            nonce: nonce,
            name: name,
            email: $('#new-customer-email').val(),
            phone: $('#new-customer-phone').val(),
            rut: $('#new-customer-rut').val()
        }, function(response) {
            if (response.success) {
                selectedCustomerId = response.data.customer.id;
                selectedCustomerName = response.data.customer.name;
                $('#pos-customer-id').val(selectedCustomerId);
                $('#pos-customer-name').val(selectedCustomerName);
                $('#pos-customer-search').hide();
                $('#pos-selected-customer').show().find('.customer-name').text(selectedCustomerName);
                $('#modal-new-customer').hide();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Descuento
    $('#pos-discount-type').on('change', function() {
        const hasDiscount = $(this).val() !== '';
        $('#pos-discount-value').prop('disabled', !hasDiscount);
        $('#btn-apply-discount').prop('disabled', !hasDiscount);
        if (!hasDiscount) {
            $('#pos-discount-value').val(0);
            updateTotals();
        }
    });
    
    $('#btn-apply-discount').on('click', function() {
        updateTotals();
    });
    
    // Método de pago
    $('input[name="payment_method"]').on('change', function() {
        $('.payment-method').removeClass('active');
        $(this).closest('.payment-method').addClass('active');
        
        const method = $(this).val();
        if (method === 'card' || method === 'transfer') {
            $('#pos-payment-reference').show().attr('placeholder', 
                method === 'card' ? 'Últimos 4 dígitos' : 'N° Transferencia');
        } else {
            $('#pos-payment-reference').hide().val('');
        }
    });
    
    // Completar venta
    $('#btn-complete-sale').on('click', function() {
        if (cart.length === 0) {
            alert('El carrito está vacío');
            return;
        }
        
        if (!activeSessionId) {
            alert('Debes abrir una sesión de caja');
            return;
        }
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Procesando...');
        
        $.post(ajaxurl, {
            action: 'riverso_pos_create_order',
            nonce: nonce,
            items: JSON.stringify(cart),
            customer_id: selectedCustomerId,
            customer_name: selectedCustomerName,
            payment_method: $('input[name="payment_method"]:checked').val(),
            payment_reference: $('#pos-payment-reference').val(),
            discount_type: $('#pos-discount-type').val(),
            discount_value: $('#pos-discount-value').val(),
            notes: $('#pos-notes').val(),
            session_id: activeSessionId
        }, function(response) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Completar Venta');
            
            if (response.success) {
                $('#success-order-number').text(response.data.order_number);
                $('#success-order-total').text('$' + formatNumber(response.data.total));
                $('#modal-sale-success').show();
                
                // Reset
                cart = [];
                renderCart();
                updateTotals();
                selectedCustomerId = 0;
                selectedCustomerName = 'Cliente Anónimo';
                $('#pos-customer-id').val(0);
                $('#pos-selected-customer').hide();
                $('#pos-customer-search').val('').show();
                $('#pos-discount-type').val('');
                $('#pos-discount-value').val(0).prop('disabled', true);
                $('#pos-notes').val('');
                $('input[name="payment_method"][value="cash"]').prop('checked', true).change();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Nueva venta
    $('#btn-new-sale').on('click', function() {
        $('#modal-sale-success').hide();
        $('#pos-product-search').focus();
    });
    
    // Abrir sesión
    $('#btn-open-session, #btn-open-session-main').on('click', function() {
        $('#modal-open-session').show();
    });
    
    $('#btn-confirm-open-session').on('click', function() {
        $.post(ajaxurl, {
            action: 'riverso_pos_open_session',
            nonce: nonce,
            register_name: $('#session-register-name').val(),
            opening_amount: $('#session-opening-amount').val()
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Cerrar sesión
    $('#btn-close-session').on('click', function() {
        const sessionId = $(this).data('session-id');
        // Cargar info de la sesión
        $('#close-opening-amount').text('$' + formatNumber(<?php echo $active_session ? $active_session->opening_amount : 0; ?>));
        $('#modal-close-session').show();
        
        // Calcular ventas
        $.post(ajaxurl, {
            action: 'riverso_pos_get_session_orders',
            nonce: nonce,
            session_id: sessionId
        }, function(response) {
            if (response.success) {
                let salesTotal = 0;
                response.data.orders.forEach(order => {
                    if (order.status === 'completed') {
                        salesTotal += parseFloat(order.total);
                    }
                });
                $('#close-sales-total').text('$' + formatNumber(salesTotal));
                const opening = <?php echo $active_session ? $active_session->opening_amount : 0; ?>;
                const expected = opening + salesTotal;
                $('#close-expected-amount').text('$' + formatNumber(expected));
                $('#session-closing-amount').val(Math.round(expected));
            }
        });
    });
    
    $('#session-closing-amount').on('input', function() {
        const closing = parseFloat($(this).val()) || 0;
        const expected = parseFloat($('#close-expected-amount').text().replace(/[^0-9.-]/g, '')) || 0;
        const diff = closing - expected;
        
        $('#close-difference-row').show();
        $('#close-difference').text((diff >= 0 ? '+' : '') + '$' + formatNumber(diff))
            .removeClass('negative positive')
            .addClass(diff < 0 ? 'negative' : diff > 0 ? 'positive' : '');
    });
    
    $('#btn-confirm-close-session').on('click', function() {
        $.post(ajaxurl, {
            action: 'riverso_pos_close_session',
            nonce: nonce,
            session_id: activeSessionId,
            closing_amount: $('#session-closing-amount').val(),
            notes: $('#session-close-notes').val()
        }, function(response) {
            if (response.success) {
                alert('Sesión cerrada. Diferencia: $' + formatNumber(response.data.difference));
                location.reload();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Cargar sesiones
    function loadSessions() {
        $.post(ajaxurl, {
            action: 'riverso_pos_get_sessions',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                let html = '';
                response.data.sessions.forEach(function(session) {
                    const statusBadge = session.status === 'open' 
                        ? '<span class="badge badge-success">Abierta</span>'
                        : '<span class="badge badge-secondary">Cerrada</span>';
                    html += `
                        <tr>
                            <td>${session.id}</td>
                            <td>${session.register_name}</td>
                            <td>${session.user_name}</td>
                            <td>${session.opened_at}</td>
                            <td>${session.closed_at || '-'}</td>
                            <td>$${formatNumber(session.opening_amount)}</td>
                            <td>${session.orders_count}</td>
                            <td>$${formatNumber(session.total_sales)}</td>
                            <td>${statusBadge}</td>
                            <td>
                                <button type="button" class="button button-small btn-view-session-orders" data-id="${session.id}">
                                    Ver Ventas
                                </button>
                            </td>
                        </tr>
                    `;
                });
                $('#pos-sessions-table tbody').html(html || '<tr><td colspan="10">No hay sesiones</td></tr>');
            }
        });
    }
    
    // Cargar órdenes en espera
    function loadHeldOrders() {
        $.post(ajaxurl, {
            action: 'riverso_pos_get_pending_orders',
            nonce: nonce
        }, function(response) {
            if (response.success) {
                let html = '';
                response.data.held_orders.forEach(function(order) {
                    html += `
                        <div class="held-order-card">
                            <h4>
                                ${order.customer_name || 'Sin cliente'}
                                <span class="time">${order.created_at}</span>
                            </h4>
                            <p class="total">$${formatNumber(order.total)}</p>
                            ${order.notes ? '<p><small>' + order.notes + '</small></p>' : ''}
                            <div class="actions">
                                <button type="button" class="button button-primary btn-resume-order" data-id="${order.id}">
                                    Retomar
                                </button>
                                <button type="button" class="button btn-delete-held" data-id="${order.id}">
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    `;
                });
                $('#pos-held-orders').html(html || '<p>No hay órdenes en espera</p>');
            }
        });
    }
    
    // Retomar orden
    $(document).on('click', '.btn-resume-order', function() {
        const heldId = $(this).data('id');
        $.post(ajaxurl, {
            action: 'riverso_pos_resume_order',
            nonce: nonce,
            held_id: heldId
        }, function(response) {
            if (response.success) {
                cart = response.data.cart_data;
                if (response.data.customer_id) {
                    selectedCustomerId = response.data.customer_id;
                    selectedCustomerName = response.data.customer_name;
                    $('#pos-customer-id').val(selectedCustomerId);
                    $('#pos-selected-customer').show().find('.customer-name').text(selectedCustomerName);
                    $('#pos-customer-search').hide();
                }
                if (response.data.notes) {
                    $('#pos-notes').val(response.data.notes);
                }
                renderCart();
                updateTotals();
                $('.nav-tab[data-tab="pos-sale"]').click();
            }
        });
    });
    
    // Guardar en espera
    $('#btn-hold-order').on('click', function() {
        if (cart.length === 0) {
            alert('El carrito está vacío');
            return;
        }
        
        let subtotal = 0;
        cart.forEach(item => subtotal += item.price * item.quantity);
        
        $.post(ajaxurl, {
            action: 'riverso_pos_hold_order',
            nonce: nonce,
            session_id: activeSessionId,
            customer_id: selectedCustomerId,
            customer_name: selectedCustomerName,
            cart_data: JSON.stringify(cart),
            total: subtotal,
            notes: $('#pos-notes').val()
        }, function(response) {
            if (response.success) {
                alert('Orden guardada en espera');
                cart = [];
                renderCart();
                updateTotals();
            } else {
                alert(response.data.message);
            }
        });
    });
    
    // Cerrar modales
    $('.riverso-modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });
    
    // Helpers
    function formatNumber(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
    
    // Focus inicial
    if (activeSessionId) {
        $('#pos-product-search').focus();
    }
});
</script>
