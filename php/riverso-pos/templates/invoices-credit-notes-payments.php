<?php
/**
 * Template: Fragmentos para notas de crédito y pagos de facturas
 * 
 * Este archivo contiene las secciones UI nuevas que deben integrarse en invoices.php
 * - Sección de notas de crédito en modal de upload
 * - Sección de NC en detalle de factura
 * - Sección de pagos agrupados en detalle de factura
 * - Modal para crear ticket de pago
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- ============================================================================
     SECCIÓN 1: EN EL MODAL DE UPLOAD
     Agregar DESPUÉS de la sección "Proveedor / emisor" y ANTES del botón Submit
============================================================================ -->
<div id="credit-note-section" style="display:none;margin-top:20px;padding:14px;background:#f3f5f7;border-radius:6px;border-left:4px solid #2271b1;">
    <h3 style="margin-top:0;">Nota de Crédito</h3>
    <p class="description">
        Se detectó que este documento es una <strong>Nota de Crédito (TipoDTE=61)</strong>.
        Debe asociarse con una factura origen para aplicar el descuento.
    </p>
    
    <label style="display:block;margin-bottom:10px;">
        <strong>Factura origen a descontar:</strong>
    </label>
    <select id="credit-note-origin-factura-id" style="width:100%;max-width:400px;">
        <option value="">— Seleccionar factura —</option>
    </select>
    <p id="credit-note-resolution-msg" class="description" style="margin-top:6px;"></p>
    
    <label style="display:block;margin-top:12px;">
        <input type="checkbox" id="credit-note-reversa-inventario">
        <span>Aplicar reversa de inventario (deshacer entrada si aplica)</span>
    </label>
    
    <div id="credit-note-reversa-detail" style="display:none;margin-top:10px;padding:10px;background:#fff;border:1px solid #c3d9f0;border-radius:4px;font-size:13px;">
        <strong>Impacto de reversa:</strong>
        <ul id="credit-note-reversa-items" style="margin:8px 0;padding-left:20px;"></ul>
        <p class="description">La reversa deshará las entradas de inventario registradas por la factura origen.</p>
    </div>
    
    <div id="credit-note-balance" style="margin-top:14px;padding:10px;background:#fff8e5;border-radius:4px;border-left:4px solid #ffb900;">
        <strong>Saldo efectivo resultante:</strong>
        <div style="font-size:16px;font-weight:bold;margin-top:4px;">
            $<span id="credit-note-balance-amount">0</span>
        </div>
        <p id="credit-note-balance-warning" class="description" style="color:#b32d2d;margin-top:4px;"></p>
    </div>
</div>

<!-- ============================================================================
     SECCIÓN 2: EN EL DETALLE DE FACTURA (modal/pestañas)
     Agregar como nueva pestaña después de "Recepción" / "Aprobación"
============================================================================ -->
<div id="invoice-detail-tabs-credit-notes" style="display:none;">
    <h3>Notas de Crédito <span class="badge badge-info" id="credit-notes-count">0</span></h3>
    
    <table class="wp-list-table widefat striped" id="credit-notes-table" style="margin-top:12px;">
        <thead>
            <tr>
                <th style="width:80px;">Folio NC</th>
                <th style="width:100px;">Fecha</th>
                <th style="width:100px;">Tipo Ref</th>
                <th style="width:80px;">Folio Ref</th>
                <th style="width:120px;">Monto</th>
                <th style="width:100px;">Estado Reversa</th>
                <th style="width:120px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="credit-notes-list">
            <tr><td colspan="7" style="text-align:center;padding:30px;color:#666;">
                No hay notas de crédito vinculadas
            </td></tr>
        </tbody>
    </table>
</div>

<!-- ============================================================================
     SECCIÓN 3: EN EL DETALLE DE FACTURA
     Mostrar saldo efectivo al inicio del modal
============================================================================ -->
<div id="invoice-detail-saldo-efectivo" style="display:none;margin-bottom:14px;padding:12px;background:#e6f2ff;border-left:4px solid #0073aa;border-radius:4px;">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <p class="description" style="margin:0;">Total original</p>
            <p style="font-size:14px;margin:4px 0;">$<span id="detail-monto-original">0</span></p>
        </div>
        <span style="color:#666;">−</span>
        <div>
            <p class="description" style="margin:0;">Descuento por NC</p>
            <p style="font-size:14px;margin:4px 0;">$<span id="detail-descuento-nc">0</span></p>
        </div>
        <span style="color:#666;font-weight:bold;">=</span>
        <div style="border-left:2px solid #666;padding-left:12px;">
            <p class="description" style="margin:0;">Saldo Efectivo</p>
            <p style="font-size:18px;font-weight:bold;margin:4px 0;color:#0073aa;">$<span id="detail-saldo-efectivo">0</span></p>
        </div>
    </div>
</div>

<!-- ============================================================================
     SECCIÓN 4: EN EL DETALLE DE FACTURA
     Sección de pagos agrupados (tickets)
============================================================================ -->
<div id="invoice-detail-pagos" style="display:none;">
    <h3>Tickets de Pago <span class="badge" id="pagos-count">0</span></h3>
    
    <table class="wp-list-table widefat striped" id="pagos-table" style="margin-top:12px;">
        <thead>
            <tr>
                <th style="width:120px;">Ticket</th>
                <th style="width:100px;">Fecha</th>
                <th style="width:100px;">Monto</th>
                <th style="width:100px;">Estado</th>
                <th style="width:150px;">Documentos</th>
                <th style="width:120px;">Acciones</th>
            </tr>
        </thead>
        <tbody id="pagos-list">
            <tr><td colspan="6" style="text-align:center;padding:30px;color:#666;">
                Sin pagos registrados
            </td></tr>
        </tbody>
    </table>
    
    <div style="margin-top:14px;padding:12px;background:#f0f6fc;border-radius:4px;">
        <button type="button" class="button button-primary" id="btn-create-payment">
            <span class="dashicons dashicons-money"></span> Crear Ticket de Pago
        </button>
    </div>
</div>

<!-- ============================================================================
     MODAL 2: CREAR TICKET DE PAGO (multipares, con comprobante)
============================================================================ -->
<div id="modal-create-payment" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content" style="max-width:700px;">
        <div class="riverso-modal-header">
            <h2>Crear Ticket de Pago</h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <form id="form-create-payment" enctype="multipart/form-data">
                <h3>Seleccionar facturas a pagar</h3>
                <p class="description">Seleccione una o más facturas pendientes de pago. Se mostrará el saldo efectivo de cada una.</p>
                
                <div id="payment-facturas-list" style="max-height:300px;overflow-y:auto;border:1px solid #c3d9f0;border-radius:4px;padding:10px;">
                    <!-- Llenar dinámicamente con lista de facturas no pagadas -->
                </div>
                
                <div style="margin-top:14px;padding:12px;background:#e6f2ff;border-radius:4px;">
                    <strong>Total a pagar:</strong>
                    <p style="font-size:18px;font-weight:bold;color:#0073aa;">
                        $<span id="payment-total-preview">0</span>
                    </p>
                </div>
                
                <h3 style="margin-top:20px;">Comprobante de transferencia</h3>
                <p class="description">Suba una imagen JPG, PNG o WebP del comprobante de transferencia (máx 10 MB).</p>
                
                <div class="upload-area" id="payment-comprobante-drop" style="margin-bottom:12px;padding:30px;border:2px dashed #c3d9f0;border-radius:4px;text-align:center;background:#f9fafb;cursor:pointer;">
                    <span class="dashicons dashicons-media-document" style="font-size:48px;color:#c3d9f0;"></span>
                    <p style="margin:8px 0;color:#666;">Arrastra la imagen aquí o <strong>haz clic para buscar</strong></p>
                    <p class="description">JPG, PNG o WebP</p>
                </div>
                <input type="file" id="payment-comprobante-input" name="comprobante" accept="image/*" style="display:none;">
                <div id="payment-comprobante-preview" style="display:none;margin-bottom:12px;">
                    <strong>Archivo seleccionado:</strong> <span id="payment-comprobante-name"></span>
                </div>
                
                <label style="display:block;margin-bottom:12px;">
                    <strong>Fecha del pago</strong>
                    <input type="date" id="payment-fecha" name="fecha_pago" required style="width:200px;">
                </label>
                
                <label style="display:block;margin-bottom:12px;">
                    <strong>Notas (opcional)</strong>
                    <textarea id="payment-notas" name="notas" rows="3" style="width:100%;"></textarea>
                </label>
                
                <div style="display:flex;gap:8px;margin-top:20px;">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-money"></span> Crear Ticket
                    </button>
                    <button type="button" class="button button-secondary modal-close">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================================
     MODAL 3: VER DETALLES DEL TICKET DE PAGO
============================================================================ -->
<div id="modal-payment-detail" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content" style="max-width:700px;">
        <div class="riverso-modal-header">
            <h2>Detalle del Ticket <span id="payment-detail-numero"></span></h2>
            <button type="button" class="riverso-modal-close">&times;</button>
        </div>
        <div class="riverso-modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;">
                <div style="padding:12px;background:#f9fafb;border-radius:4px;">
                    <p class="description" style="margin:0;">Estado</p>
                    <p style="font-size:14px;font-weight:bold;margin:4px 0;" id="payment-detail-estado">—</p>
                </div>
                <div style="padding:12px;background:#f9fafb;border-radius:4px;">
                    <p class="description" style="margin:0;">Monto</p>
                    <p style="font-size:14px;font-weight:bold;margin:4px 0;">$<span id="payment-detail-monto">0</span></p>
                </div>
                <div style="padding:12px;background:#f9fafb;border-radius:4px;">
                    <p class="description" style="margin:0;">Fecha</p>
                    <p style="font-size:14px;font-weight:bold;margin:4px 0;" id="payment-detail-fecha">—</p>
                </div>
                <div style="padding:12px;background:#f9fafb;border-radius:4px;">
                    <p class="description" style="margin:0;">Documentos</p>
                    <p style="font-size:14px;font-weight:bold;margin:4px 0;" id="payment-detail-docs">—</p>
                </div>
            </div>
            
            <h3>Documentos incluidos</h3>
            <table class="wp-list-table widefat striped" id="payment-detail-docs-table" style="margin-bottom:20px;font-size:13px;">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Folio</th>
                        <th style="text-align:right;">Monto Pagado</th>
                    </tr>
                </thead>
                <tbody id="payment-detail-docs-list"></tbody>
            </table>
            
            <div id="payment-detail-comprobante-section" style="display:none;">
                <h3>Comprobante de transferencia</h3>
                <div style="margin-bottom:12px;">
                    <a href="#" id="payment-detail-comprobante-link" target="_blank" class="button">
                        <span class="dashicons dashicons-download"></span> Descargar comprobante
                    </a>
                </div>
                <div id="payment-detail-comprobante-preview" style="border:1px solid #c3d9f0;border-radius:4px;overflow:hidden;">
                    <!-- Previsualizacion de imagen -->
                </div>
            </div>
            
            <div style="margin-top:20px;padding:12px;background:#fff8e5;border-radius:4px;">
                <p class="description" id="payment-detail-notas"></p>
            </div>
            
            <div id="payment-detail-actions" style="display:flex;gap:8px;margin-top:20px;">
                <button type="button" class="button button-secondary" id="btn-cancel-payment">
                    Anular Ticket
                </button>
                <button type="button" class="button button-secondary modal-close">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================
     ESTILOS ADICIONALES
============================================================================ -->
<style>
.badge {
    display: inline-block;
    padding: 2px 8px;
    background: #c3d9f0;
    color: #2271b1;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
}

.badge-info {
    background: #0073aa;
    color: #fff;
}

#credit-note-reversa-detail ul li {
    list-style: disc;
}

#invoice-detail-saldo-efectivo {
    display: flex !important;
}

@media (max-width: 768px) {
    #invoice-detail-saldo-efectivo {
        flex-direction: column;
        gap: 10px;
    }
    
    #invoice-detail-saldo-efectivo > span {
        display: none;
    }
}
</style>
