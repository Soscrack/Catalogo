<?php
/**
 * Template: Cotizaciones Recibidas
 * Gestión de cotizaciones de proveedores con comparación de costos
 */

if (!defined('ABSPATH')) {
    exit;
}

$estados = Riverso_POS_Received_Quote_Module::ESTADOS;
$match_status = Riverso_POS_Received_Quote_Module::MATCH_STATUS;
$decision_status = Riverso_POS_Received_Quote_Module::DECISION_STATUS;
$source_types = Riverso_POS_Received_Quote_Module::SOURCE_TYPES;
?>

<div class="wrap riverso-pos-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-media-document"></span>
        Cotizaciones Recibidas
    </h1>
    <button type="button" class="page-title-action" id="btn-nueva-cotizacion">
        <span class="dashicons dashicons-plus-alt2"></span> Nueva Cotización
    </button>
    <button type="button" class="page-title-action" id="btn-subir-archivo">
        <span class="dashicons dashicons-upload"></span> Subir Archivo
    </button>
    <hr class="wp-header-end">

    <!-- Vista Lista -->
    <div id="vista-lista">
        <!-- Stats Cards -->
        <div class="riverso-stats-grid" id="stats-cards">
            <div class="stat-card">
                <div class="stat-icon bg-blue"><span class="dashicons dashicons-list-view"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-total">0</span>
                    <span class="stat-label">Total</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-orange"><span class="dashicons dashicons-edit"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-activas">0</span>
                    <span class="stat-label">Activas</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-yellow"><span class="dashicons dashicons-visibility"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-revision">0</span>
                    <span class="stat-label">En Revisión</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon bg-green"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-info">
                    <span class="stat-value" id="stat-aprobadas">0</span>
                    <span class="stat-label">Aprobadas</span>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="riverso-filters">
            <div class="filter-row">
                <input type="text" id="filtro-buscar" placeholder="Buscar por número o proveedor..." class="regular-text">
                <select id="filtro-estado">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estados as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filtro-proveedor">
                    <option value="">Todos los proveedores</option>
                </select>
                <input type="date" id="filtro-desde" placeholder="Desde">
                <input type="date" id="filtro-hasta" placeholder="Hasta">
                <button type="button" class="button" id="btn-filtrar">
                    <span class="dashicons dashicons-filter"></span> Filtrar
                </button>
                <button type="button" class="button" id="btn-limpiar-filtros">Limpiar</button>
            </div>
        </div>

        <!-- Tabla de Cotizaciones -->
        <table class="wp-list-table widefat fixed striped" id="tabla-cotizaciones">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Proveedor</th>
                    <th>Nº Documento</th>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th style="width:100px">Ítems</th>
                    <th style="text-align:right">Total</th>
                    <th style="width:120px">Estado</th>
                    <th style="width:150px">Acciones</th>
                </tr>
            </thead>
            <tbody id="lista-cotizaciones">
                <tr><td colspan="9" class="loading">Cargando cotizaciones...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- Vista Detalle/Editor -->
    <div id="vista-detalle" style="display:none;">
        <div class="riverso-detail-header">
            <button type="button" class="button" id="btn-volver-lista">
                <span class="dashicons dashicons-arrow-left-alt"></span> Volver
            </button>
            <h2 id="titulo-cotizacion">Nueva Cotización</h2>
            <div class="header-actions">
                <span class="estado-badge" id="badge-estado"></span>
            </div>
        </div>

        <!-- Datos generales -->
        <div class="riverso-card">
            <h3><span class="dashicons dashicons-info"></span> Datos Generales</h3>
            <form id="form-cotizacion">
                <input type="hidden" id="cotizacion-id" value="0">
                <div class="form-grid cols-4">
                    <div class="form-group">
                        <label for="proveedor_id">Proveedor</label>
                        <select id="proveedor_id" name="proveedor_id" class="regular-text">
                            <option value="">Seleccionar proveedor...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_documento">Nº Documento</label>
                        <input type="text" id="numero_documento" name="numero_documento" class="regular-text">
                    </div>
                    <div class="form-group">
                        <label for="fecha_documento">Fecha Documento</label>
                        <input type="date" id="fecha_documento" name="fecha_documento">
                    </div>
                    <div class="form-group">
                        <label for="moneda">Moneda</label>
                        <select id="moneda" name="moneda">
                            <option value="CLP">CLP - Peso Chileno</option>
                            <option value="USD">USD - Dólar</option>
                            <option value="EUR">EUR - Euro</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid cols-2">
                    <div class="form-group">
                        <label for="notas">Notas</label>
                        <textarea id="notas" name="notas" rows="2" class="large-text"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Archivo</label>
                        <div id="archivo-info">
                            <span class="no-archivo">Sin archivo adjunto</span>
                        </div>
                        <input type="file" id="archivo-upload" accept=".pdf,.xlsx,.xls,.csv,.txt" style="margin-top:5px;">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-saved"></span> Guardar
                    </button>
                    <button type="button" class="button" id="btn-match-todos" title="Buscar coincidencias automáticas">
                        <span class="dashicons dashicons-search"></span> Match Automático
                    </button>
                    <button type="button" class="button button-link-delete" id="btn-eliminar-cotizacion" style="float:right;">
                        <span class="dashicons dashicons-trash"></span> Eliminar
                    </button>
                </div>
            </form>
        </div>

        <!-- Totales -->
        <div class="riverso-totals-bar">
            <div class="total-item">
                <span class="label">Subtotal:</span>
                <span class="value" id="total-subtotal">$0</span>
            </div>
            <div class="total-item">
                <span class="label">IVA:</span>
                <span class="value" id="total-impuesto">$0</span>
            </div>
            <div class="total-item total-main">
                <span class="label">Total:</span>
                <span class="value" id="total-total">$0</span>
            </div>
            <div class="total-actions">
                <button type="button" class="button button-primary" id="btn-aprobar" style="display:none;">
                    <span class="dashicons dashicons-yes"></span> Aprobar Cotización
                </button>
                <button type="button" class="button" id="btn-ver-comparacion">
                    <span class="dashicons dashicons-chart-line"></span> Ver Comparación
                </button>
            </div>
        </div>

        <!-- Ítems de la cotización -->
        <div class="riverso-card">
            <div class="card-header">
                <h3><span class="dashicons dashicons-list-view"></span> Ítems de la Cotización</h3>
                <button type="button" class="button" id="btn-agregar-item">
                    <span class="dashicons dashicons-plus"></span> Agregar Ítem
                </button>
            </div>
            <table class="wp-list-table widefat fixed striped" id="tabla-items">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:120px">Cód. Proveedor</th>
                        <th>Descripción</th>
                        <th style="width:80px">Cant.</th>
                        <th style="width:100px;text-align:right">Costo Neto</th>
                        <th style="width:100px;text-align:right">Total</th>
                        <th style="width:120px">Match</th>
                        <th style="width:100px">Decisión</th>
                        <th style="width:100px">Δ Costo</th>
                        <th style="width:100px">Acciones</th>
                    </tr>
                </thead>
                <tbody id="lista-items">
                    <tr><td colspan="10" class="empty">Sin ítems. Agregue ítems manualmente o suba un archivo.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Editar Ítem -->
    <div id="modal-item" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:700px;">
            <div class="modal-header">
                <h3 id="modal-item-titulo">Agregar Ítem</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <form id="form-item">
                <input type="hidden" id="item-id" value="0">
                <div class="modal-body">
                    <div class="form-grid cols-3">
                        <div class="form-group">
                            <label for="item-codigo-proveedor">Código Proveedor</label>
                            <input type="text" id="item-codigo-proveedor" name="codigo_proveedor">
                        </div>
                        <div class="form-group">
                            <label for="item-codigo-barras">Código de Barras</label>
                            <input type="text" id="item-codigo-barras" name="codigo_barras">
                        </div>
                        <div class="form-group">
                            <label for="item-unidad">Unidad</label>
                            <select id="item-unidad" name="unidad">
                                <option value="UN">UN - Unidad</option>
                                <option value="CJ">CJ - Caja</option>
                                <option value="KG">KG - Kilogramo</option>
                                <option value="MT">MT - Metro</option>
                                <option value="LT">LT - Litro</option>
                                <option value="PAR">PAR - Par</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="item-descripcion">Descripción *</label>
                        <textarea id="item-descripcion" name="descripcion" rows="2" required></textarea>
                    </div>
                    <div class="form-grid cols-4">
                        <div class="form-group">
                            <label for="item-cantidad">Cantidad *</label>
                            <input type="number" id="item-cantidad" name="cantidad" value="1" min="0.0001" step="0.0001" required>
                        </div>
                        <div class="form-group">
                            <label for="item-costo-neto">Costo Neto *</label>
                            <input type="number" id="item-costo-neto" name="costo_neto" value="0" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="item-costo-impuesto">IVA</label>
                            <input type="number" id="item-costo-impuesto" name="costo_impuesto" value="0" min="0" step="0.01">
                        </div>
                        <div class="form-group">
                            <label for="item-costo-total">Total</label>
                            <input type="number" id="item-costo-total" name="costo_total" value="0" min="0" step="0.01" readonly>
                        </div>
                    </div>
                    <!-- Match info -->
                    <div id="item-match-info" style="display:none;" class="match-info-box">
                        <h4>Producto Vinculado</h4>
                        <div id="item-match-details"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="button" id="btn-buscar-match">
                        <span class="dashicons dashicons-search"></span> Buscar Match
                    </button>
                    <button type="submit" class="button button-primary">Guardar</button>
                    <button type="button" class="button modal-close">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Comparación de Costos -->
    <div id="modal-comparacion" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <h3>Comparación de Costos</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="comparison-summary" id="comparison-summary"></div>
                <table class="wp-list-table widefat striped" id="tabla-comparacion">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th style="text-align:right">Costo Anterior</th>
                            <th style="text-align:right">Costo Nuevo</th>
                            <th style="text-align:right">Diferencia</th>
                            <th style="text-align:right">%</th>
                        </tr>
                    </thead>
                    <tbody id="lista-comparacion"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="button modal-close">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Decisión de Ítem -->
    <div id="modal-decision" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Decisión del Ítem</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <form id="form-decision">
                <input type="hidden" id="decision-item-id" value="0">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Decisión</label>
                        <div class="decision-buttons">
                            <button type="button" class="button decision-btn" data-decision="accepted">
                                <span class="dashicons dashicons-yes"></span> Aceptar
                            </button>
                            <button type="button" class="button decision-btn" data-decision="modified">
                                <span class="dashicons dashicons-edit"></span> Modificado
                            </button>
                            <button type="button" class="button decision-btn" data-decision="rejected">
                                <span class="dashicons dashicons-no"></span> Rechazar
                            </button>
                        </div>
                        <input type="hidden" id="decision-value" value="">
                    </div>
                    <div class="form-group">
                        <label for="decision-notas">Notas (opcional)</label>
                        <textarea id="decision-notas" rows="2"></textarea>
                    </div>
                    <div class="form-group" id="decision-manual-match" style="display:none;">
                        <label for="decision-producto">Vincular Producto Manualmente</label>
                        <input type="text" id="decision-producto-search" placeholder="Buscar por SKU o nombre...">
                        <input type="hidden" id="decision-producto-id" value="">
                        <div id="decision-producto-result"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="button button-primary" disabled>Guardar Decisión</button>
                    <button type="button" class="button modal-close">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload modal -->
    <div id="modal-upload" class="riverso-modal" style="display:none;">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Subir Archivo de Cotización</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="upload-zone" id="upload-zone">
                    <span class="dashicons dashicons-upload"></span>
                    <p>Arrastra un archivo aquí o haz clic para seleccionar</p>
                    <p class="upload-formats">Formatos: PDF, Excel (.xlsx, .xls), CSV, TXT</p>
                    <input type="file" id="file-upload-input" accept=".pdf,.xlsx,.xls,.csv,.txt" style="display:none;">
                </div>
                <div id="upload-progress" style="display:none;">
                    <div class="progress-bar"><div class="progress-fill"></div></div>
                    <p class="progress-text">Subiendo archivo...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.riverso-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 15px;
}
.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stat-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #fff;
}
.bg-blue { background: #2271b1; }
.bg-orange { background: #d63638; }
.bg-yellow { background: #dba617; }
.bg-green { background: #00a32a; }
.stat-info { display: flex; flex-direction: column; }
.stat-value { font-size: 24px; font-weight: 600; line-height: 1; }
.stat-label { color: #666; font-size: 13px; }

.riverso-filters {
    background: #fff;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.filter-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-row input[type="text"],
.filter-row input[type="date"],
.filter-row select {
    max-width: 200px;
}

.riverso-card {
    background: #fff;
    border: 1px solid #ddd;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}
.riverso-card h3 {
    margin: 0 0 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.card-header h3 { margin: 0; }

.riverso-detail-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}
.riverso-detail-header h2 {
    flex: 1;
    margin: 0;
}
.header-actions {
    display: flex;
    gap: 10px;
}

.form-grid {
    display: grid;
    gap: 15px;
    margin-bottom: 15px;
}
.form-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.form-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
.form-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
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
.form-actions {
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.riverso-totals-bar {
    background: #f0f0f1;
    border: 1px solid #ddd;
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 30px;
}
.total-item {
    display: flex;
    flex-direction: column;
}
.total-item .label {
    font-size: 12px;
    color: #666;
}
.total-item .value {
    font-size: 18px;
    font-weight: 600;
}
.total-main .value {
    font-size: 24px;
    color: #00a32a;
}
.total-actions {
    margin-left: auto;
    display: flex;
    gap: 10px;
}

.estado-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}
.estado-draft { background: #e0e0e0; color: #333; }
.estado-uploaded { background: #dbeafe; color: #1e40af; }
.estado-parsed { background: #fef3c7; color: #92400e; }
.estado-under_review { background: #fef3c7; color: #92400e; }
.estado-approved { background: #d1fae5; color: #065f46; }
.estado-rejected { background: #fee2e2; color: #991b1b; }
.estado-converted_to_expected { background: #ddd6fe; color: #5b21b6; }
.estado-archived { background: #e5e7eb; color: #374151; }

.match-badge {
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
}
.match-pending { background: #f3f4f6; color: #6b7280; }
.match-matched { background: #d1fae5; color: #065f46; }
.match-not_found { background: #fee2e2; color: #991b1b; }
.match-ambiguous { background: #fef3c7; color: #92400e; }
.match-manual { background: #dbeafe; color: #1e40af; }

.decision-pending { color: #6b7280; }
.decision-accepted { color: #065f46; }
.decision-modified { color: #1e40af; }
.decision-rejected { color: #991b1b; }

.cost-up { color: #dc2626; }
.cost-down { color: #16a34a; }

.riverso-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
.modal-content {
    background: #fff;
    border-radius: 8px;
    width: 90%;
    max-height: 90vh;
    overflow: auto;
}
.modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 { margin: 0; }
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}
.modal-body { padding: 20px; }
.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.decision-buttons {
    display: flex;
    gap: 10px;
    margin: 10px 0;
}
.decision-btn.selected {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
}

.upload-zone {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.upload-zone:hover,
.upload-zone.dragover {
    border-color: #2271b1;
    background: #f0f6fc;
}
.upload-zone .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #999;
}
.upload-formats {
    font-size: 12px;
    color: #666;
}

.match-info-box {
    background: #f0f6fc;
    border: 1px solid #c3daf5;
    border-radius: 4px;
    padding: 15px;
    margin-top: 15px;
}
.match-info-box h4 {
    margin: 0 0 10px;
    color: #1e40af;
}

.comparison-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}
.comparison-summary .summary-item {
    background: #f9fafb;
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}
.comparison-summary .summary-value {
    font-size: 24px;
    font-weight: 600;
}

.loading, .empty {
    text-align: center;
    padding: 40px;
    color: #666;
}

@media (max-width: 1200px) {
    .riverso-stats-grid { grid-template-columns: repeat(2, 1fr); }
    .form-grid.cols-4 { grid-template-columns: repeat(2, 1fr); }
}
</style>

<script>
jQuery(document).ready(function($) {
    const nonce = '<?php echo wp_create_nonce('riverso_pos_nonce'); ?>';
    let cotizacionActual = null;
    let itemsActuales = [];
    let proveedoresCache = [];

    // Formatear moneda
    function formatMoney(val) {
        return '$' + parseFloat(val || 0).toLocaleString('es-CL', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    }

    // Cargar lista de cotizaciones
    function cargarCotizaciones() {
        $.post(ajaxurl, {
            action: 'riverso_get_received_quotes',
            nonce: nonce,
            estado: $('#filtro-estado').val(),
            proveedor_id: $('#filtro-proveedor').val(),
            buscar: $('#filtro-buscar').val(),
            fecha_desde: $('#filtro-desde').val(),
            fecha_hasta: $('#filtro-hasta').val()
        }, function(r) {
            if (r.success) {
                renderCotizaciones(r.data.quotes);
                renderStats(r.data.stats);
                if (r.data.proveedores) {
                    proveedoresCache = r.data.proveedores;
                    renderProveedoresSelect();
                }
            }
        });
    }

    function renderStats(stats) {
        $('#stat-total').text(stats.total || 0);
        $('#stat-activas').text(stats.activas || 0);
        $('#stat-revision').text(stats.en_revision || 0);
        $('#stat-aprobadas').text(stats.aprobadas || 0);
    }

    function renderCotizaciones(quotes) {
        const tbody = $('#lista-cotizaciones');
        if (!quotes.length) {
            tbody.html('<tr><td colspan="9" class="empty">No hay cotizaciones</td></tr>');
            return;
        }

        const estados = <?php echo json_encode($estados); ?>;
        const sourceTypes = <?php echo json_encode($source_types); ?>;

        let html = '';
        quotes.forEach(q => {
            const matchInfo = `${q.items_matched}/${q.total_items}`;
            const pendingBadge = q.items_pending > 0 ? `<span class="match-badge match-pending">${q.items_pending} pend.</span>` : '';
            
            html += `<tr data-id="${q.id}">
                <td>${q.id}</td>
                <td>${q.proveedor_nombre || '<em>Sin proveedor</em>'}</td>
                <td>${q.numero_documento || '-'}</td>
                <td>${q.fecha_documento || '-'}</td>
                <td>${sourceTypes[q.tipo_fuente] || q.tipo_fuente}</td>
                <td>${matchInfo} ${pendingBadge}</td>
                <td style="text-align:right">${formatMoney(q.total)}</td>
                <td><span class="estado-badge estado-${q.estado}">${estados[q.estado] || q.estado}</span></td>
                <td>
                    <button class="button button-small btn-ver" title="Ver/Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button class="button button-small btn-eliminar" title="Eliminar">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
        });
        tbody.html(html);
    }

    function renderProveedoresSelect() {
        let opts = '<option value="">Todos los proveedores</option>';
        proveedoresCache.forEach(p => {
            opts += `<option value="${p.id}">${p.nombre}</option>`;
        });
        $('#filtro-proveedor').html(opts);

        opts = '<option value="">Seleccionar proveedor...</option>';
        proveedoresCache.forEach(p => {
            opts += `<option value="${p.id}">${p.nombre}${p.rut ? ' - ' + p.rut : ''}</option>`;
        });
        $('#proveedor_id').html(opts);
    }

    // Ver/editar cotización
    function verCotizacion(id) {
        $.post(ajaxurl, {
            action: 'riverso_get_received_quote',
            nonce: nonce,
            id: id
        }, function(r) {
            if (r.success) {
                cotizacionActual = r.data.quote;
                itemsActuales = r.data.items;
                if (r.data.proveedores) {
                    proveedoresCache = r.data.proveedores;
                    renderProveedoresSelect();
                }
                mostrarDetalle();
            }
        });
    }

    function mostrarDetalle() {
        const q = cotizacionActual;
        $('#cotizacion-id').val(q ? q.id : 0);
        $('#titulo-cotizacion').text(q ? `Cotización #${q.id}` : 'Nueva Cotización');
        
        if (q) {
            $('#proveedor_id').val(q.proveedor_id || '');
            $('#numero_documento').val(q.numero_documento || '');
            $('#fecha_documento').val(q.fecha_documento || '');
            $('#moneda').val(q.moneda || 'CLP');
            $('#notas').val(q.notas || '');
            
            const estados = <?php echo json_encode($estados); ?>;
            $('#badge-estado').text(estados[q.estado] || q.estado).attr('class', 'estado-badge estado-' + q.estado);
            
            if (q.archivo_original) {
                $('#archivo-info').html(`<span class="dashicons dashicons-media-document"></span> ${q.archivo_original}`);
            } else {
                $('#archivo-info').html('<span class="no-archivo">Sin archivo adjunto</span>');
            }
            
            $('#total-subtotal').text(formatMoney(q.subtotal));
            $('#total-impuesto').text(formatMoney(q.impuesto));
            $('#total-total').text(formatMoney(q.total));
            
            // Mostrar botón aprobar si está en revisión
            if (q.estado === 'under_review' || q.estado === 'parsed') {
                $('#btn-aprobar').show();
            } else {
                $('#btn-aprobar').hide();
            }
        } else {
            $('#form-cotizacion')[0].reset();
            $('#badge-estado').text('Nueva').attr('class', 'estado-badge');
            $('#archivo-info').html('<span class="no-archivo">Sin archivo adjunto</span>');
            $('#total-subtotal, #total-impuesto, #total-total').text('$0');
            $('#btn-aprobar').hide();
        }
        
        renderItems();
        
        $('#vista-lista').hide();
        $('#vista-detalle').show();
    }

    function renderItems() {
        const tbody = $('#lista-items');
        if (!itemsActuales.length) {
            tbody.html('<tr><td colspan="10" class="empty">Sin ítems. Agregue ítems manualmente.</td></tr>');
            return;
        }

        const matchLabels = <?php echo json_encode($match_status); ?>;
        const decisionLabels = <?php echo json_encode($decision_status); ?>;

        let html = '';
        itemsActuales.forEach(item => {
            const diffClass = item.diferencia_costo > 0 ? 'cost-up' : (item.diferencia_costo < 0 ? 'cost-down' : '');
            const diffText = item.diferencia_porcentaje ? `${item.diferencia_porcentaje > 0 ? '+' : ''}${item.diferencia_porcentaje}%` : '-';
            
            html += `<tr data-id="${item.id}">
                <td>${item.linea}</td>
                <td>${item.codigo_proveedor || '-'}</td>
                <td>
                    ${item.descripcion || '-'}
                    ${item.producto_nombre ? `<br><small class="text-muted">→ ${item.producto_nombre}</small>` : ''}
                </td>
                <td>${parseFloat(item.cantidad).toLocaleString('es-CL')}</td>
                <td style="text-align:right">${formatMoney(item.costo_neto)}</td>
                <td style="text-align:right">${formatMoney(item.costo_total * item.cantidad)}</td>
                <td><span class="match-badge match-${item.match_status}">${matchLabels[item.match_status] || item.match_status}</span></td>
                <td><span class="decision-${item.decision_status}">${decisionLabels[item.decision_status] || item.decision_status}</span></td>
                <td class="${diffClass}">${diffText}</td>
                <td>
                    <button class="button button-small btn-editar-item" title="Editar">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button class="button button-small btn-decision-item" title="Decisión">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </button>
                    <button class="button button-small btn-eliminar-item" title="Eliminar">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            </tr>`;
        });
        tbody.html(html);
    }

    // Eventos de navegación
    $('#btn-volver-lista').on('click', function() {
        $('#vista-detalle').hide();
        $('#vista-lista').show();
        cargarCotizaciones();
    });

    $('#btn-nueva-cotizacion').on('click', function() {
        cotizacionActual = null;
        itemsActuales = [];
        mostrarDetalle();
    });

    $('#btn-filtrar').on('click', cargarCotizaciones);
    $('#btn-limpiar-filtros').on('click', function() {
        $('#filtro-buscar, #filtro-estado, #filtro-proveedor, #filtro-desde, #filtro-hasta').val('');
        cargarCotizaciones();
    });

    // Click en tabla
    $('#lista-cotizaciones').on('click', '.btn-ver', function() {
        const id = $(this).closest('tr').data('id');
        verCotizacion(id);
    });

    $('#lista-cotizaciones').on('click', '.btn-eliminar', function() {
        const id = $(this).closest('tr').data('id');
        if (confirm('¿Eliminar esta cotización?')) {
            $.post(ajaxurl, {
                action: 'riverso_delete_received_quote',
                nonce: nonce,
                id: id
            }, function(r) {
                if (r.success) {
                    cargarCotizaciones();
                } else {
                    alert(r.data.message);
                }
            });
        }
    });

    // Guardar cotización
    $('#form-cotizacion').on('submit', function(e) {
        e.preventDefault();
        const data = {
            action: 'riverso_save_received_quote',
            nonce: nonce,
            id: $('#cotizacion-id').val(),
            proveedor_id: $('#proveedor_id').val(),
            numero_documento: $('#numero_documento').val(),
            fecha_documento: $('#fecha_documento').val(),
            moneda: $('#moneda').val(),
            notas: $('#notas').val()
        };

        $.post(ajaxurl, data, function(r) {
            if (r.success) {
                $('#cotizacion-id').val(r.data.id);
                cotizacionActual = cotizacionActual || {};
                cotizacionActual.id = r.data.id;
                $('#titulo-cotizacion').text(`Cotización #${r.data.id}`);
                alert(r.data.message);
            } else {
                alert(r.data.message);
            }
        });
    });

    // Modal ítem
    $('#btn-agregar-item').on('click', function() {
        if (!$('#cotizacion-id').val() || $('#cotizacion-id').val() === '0') {
            alert('Primero guarde la cotización');
            return;
        }
        $('#modal-item-titulo').text('Agregar Ítem');
        $('#item-id').val(0);
        $('#form-item')[0].reset();
        $('#item-cantidad').val(1);
        $('#item-match-info').hide();
        $('#modal-item').show();
    });

    $('#lista-items').on('click', '.btn-editar-item', function() {
        const id = $(this).closest('tr').data('id');
        const item = itemsActuales.find(i => i.id == id);
        if (!item) return;

        $('#modal-item-titulo').text('Editar Ítem');
        $('#item-id').val(item.id);
        $('#item-codigo-proveedor').val(item.codigo_proveedor || '');
        $('#item-codigo-barras').val(item.codigo_barras || '');
        $('#item-unidad').val(item.unidad || 'UN');
        $('#item-descripcion').val(item.descripcion || '');
        $('#item-cantidad').val(item.cantidad);
        $('#item-costo-neto').val(item.costo_neto);
        $('#item-costo-impuesto').val(item.costo_impuesto);
        $('#item-costo-total').val(item.costo_total);

        if (item.producto_nombre) {
            $('#item-match-details').html(`<strong>${item.producto_nombre}</strong><br>SKU: ${item.sku_match || '-'}`);
            $('#item-match-info').show();
        } else {
            $('#item-match-info').hide();
        }

        $('#modal-item').show();
    });

    // Calcular total automáticamente
    $('#item-costo-neto, #item-costo-impuesto').on('input', function() {
        const neto = parseFloat($('#item-costo-neto').val()) || 0;
        const iva = parseFloat($('#item-costo-impuesto').val()) || 0;
        $('#item-costo-total').val((neto + iva).toFixed(2));
    });

    // Guardar ítem
    $('#form-item').on('submit', function(e) {
        e.preventDefault();
        $.post(ajaxurl, {
            action: 'riverso_save_quote_item',
            nonce: nonce,
            item_id: $('#item-id').val(),
            cotizacion_id: $('#cotizacion-id').val(),
            codigo_proveedor: $('#item-codigo-proveedor').val(),
            codigo_barras: $('#item-codigo-barras').val(),
            unidad: $('#item-unidad').val(),
            descripcion: $('#item-descripcion').val(),
            cantidad: $('#item-cantidad').val(),
            costo_neto: $('#item-costo-neto').val(),
            costo_impuesto: $('#item-costo-impuesto').val(),
            costo_total: $('#item-costo-total').val()
        }, function(r) {
            if (r.success) {
                $('#modal-item').hide();
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    // Eliminar ítem
    $('#lista-items').on('click', '.btn-eliminar-item', function() {
        const id = $(this).closest('tr').data('id');
        if (confirm('¿Eliminar este ítem?')) {
            $.post(ajaxurl, {
                action: 'riverso_delete_quote_item',
                nonce: nonce,
                item_id: id
            }, function(r) {
                if (r.success) {
                    verCotizacion($('#cotizacion-id').val());
                }
            });
        }
    });

    // Buscar match individual
    $('#btn-buscar-match').on('click', function() {
        const itemId = $('#item-id').val();
        if (!itemId || itemId === '0') {
            alert('Guarde el ítem primero para buscar coincidencias');
            return;
        }
        
        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Buscando...');
        
        $.post(ajaxurl, {
            action: 'riverso_match_quote_item',
            nonce: nonce,
            item_id: itemId
        }, function(r) {
            $('#btn-buscar-match').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Buscar Match');
            if (r.success) {
                if (r.data.matches && r.data.matches.length) {
                    const m = r.data.matches[0];
                    $('#item-match-details').html(`<strong>${m.nombre}</strong><br>SKU: ${m.sku || '-'}<br>Confianza: ${r.data.confidence}%`);
                    $('#item-match-info').show();
                } else {
                    $('#item-match-details').html('<em>No se encontraron coincidencias</em>');
                    $('#item-match-info').show();
                }
            }
        });
    });

    // Match automático de todos
    $('#btn-match-todos').on('click', function() {
        const cotizacionId = $('#cotizacion-id').val();
        if (!cotizacionId || cotizacionId === '0') {
            alert('Guarde la cotización primero');
            return;
        }

        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Procesando...');

        $.post(ajaxurl, {
            action: 'riverso_match_all_items',
            nonce: nonce,
            quote_id: cotizacionId
        }, function(r) {
            $('#btn-match-todos').prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Match Automático');
            if (r.success) {
                alert(r.data.message);
                verCotizacion(cotizacionId);
            } else {
                alert(r.data.message);
            }
        });
    });

    // Modal decisión
    $('#lista-items').on('click', '.btn-decision-item', function() {
        const id = $(this).closest('tr').data('id');
        const item = itemsActuales.find(i => i.id == id);
        if (!item) return;

        $('#decision-item-id').val(item.id);
        $('#decision-value').val('');
        $('#decision-notas').val(item.decision_notas || '');
        $('.decision-btn').removeClass('selected');
        
        if (item.match_status === 'not_found' || item.match_status === 'ambiguous') {
            $('#decision-manual-match').show();
        } else {
            $('#decision-manual-match').hide();
        }

        $('#form-decision button[type="submit"]').prop('disabled', true);
        $('#modal-decision').show();
    });

    $('.decision-btn').on('click', function() {
        $('.decision-btn').removeClass('selected');
        $(this).addClass('selected');
        $('#decision-value').val($(this).data('decision'));
        $('#form-decision button[type="submit"]').prop('disabled', false);
    });

    $('#form-decision').on('submit', function(e) {
        e.preventDefault();
        const decision = $('#decision-value').val();
        if (!decision) {
            alert('Seleccione una decisión');
            return;
        }

        $.post(ajaxurl, {
            action: 'riverso_set_item_decision',
            nonce: nonce,
            item_id: $('#decision-item-id').val(),
            decision: decision,
            notas: $('#decision-notas').val(),
            producto_id: $('#decision-producto-id').val()
        }, function(r) {
            if (r.success) {
                $('#modal-decision').hide();
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    // Aprobar cotización
    $('#btn-aprobar').on('click', function() {
        if (!confirm('¿Aprobar esta cotización? Todos los ítems deben tener una decisión.')) return;

        $.post(ajaxurl, {
            action: 'riverso_approve_received_quote',
            nonce: nonce,
            id: $('#cotizacion-id').val()
        }, function(r) {
            if (r.success) {
                alert(r.data.message);
                verCotizacion($('#cotizacion-id').val());
            } else {
                alert(r.data.message);
            }
        });
    });

    // Ver comparación de costos
    $('#btn-ver-comparacion').on('click', function() {
        const cotizacionId = $('#cotizacion-id').val();
        if (!cotizacionId || cotizacionId === '0') return;

        $.post(ajaxurl, {
            action: 'riverso_get_quote_comparison',
            nonce: nonce,
            id: cotizacionId
        }, function(r) {
            if (r.success) {
                const summary = r.data.summary;
                $('#comparison-summary').html(`
                    <div class="summary-item">
                        <div class="summary-value">${summary.total_items}</div>
                        <div>Con cambio de precio</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value cost-up">${summary.aumentos}</div>
                        <div>Aumentos</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value cost-down">${summary.disminuciones}</div>
                        <div>Disminuciones</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-value ${summary.mayor_aumento > 0 ? 'cost-up' : ''}">${summary.mayor_aumento ? '+' + summary.mayor_aumento + '%' : '-'}</div>
                        <div>Mayor aumento</div>
                    </div>
                `);

                let rows = '';
                r.data.items.forEach(item => {
                    const diffClass = item.diferencia_costo > 0 ? 'cost-up' : 'cost-down';
                    rows += `<tr>
                        <td>${item.producto_nombre || item.descripcion}</td>
                        <td style="text-align:right">${formatMoney(item.costo_anterior)}</td>
                        <td style="text-align:right">${formatMoney(item.costo_neto)}</td>
                        <td style="text-align:right" class="${diffClass}">${formatMoney(item.diferencia_costo)}</td>
                        <td style="text-align:right" class="${diffClass}">${item.diferencia_porcentaje > 0 ? '+' : ''}${item.diferencia_porcentaje}%</td>
                    </tr>`;
                });
                $('#lista-comparacion').html(rows || '<tr><td colspan="5" class="empty">No hay cambios de precio</td></tr>');
                $('#modal-comparacion').show();
            }
        });
    });

    // Upload
    $('#btn-subir-archivo').on('click', function() {
        $('#modal-upload').show();
    });

    $('#upload-zone').on('click', function() {
        $('#file-upload-input').click();
    });

    $('#upload-zone').on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    }).on('dragleave', function() {
        $(this).removeClass('dragover');
    }).on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('dragover');
        const files = e.originalEvent.dataTransfer.files;
        if (files.length) uploadFile(files[0]);
    });

    $('#file-upload-input').on('change', function() {
        if (this.files.length) uploadFile(this.files[0]);
    });

    function uploadFile(file) {
        const formData = new FormData();
        formData.append('action', 'riverso_upload_quote_file');
        formData.append('nonce', nonce);
        formData.append('file', file);
        formData.append('quote_id', $('#cotizacion-id').val() || 0);

        $('#upload-zone').hide();
        $('#upload-progress').show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(r) {
                $('#modal-upload').hide();
                $('#upload-zone').show();
                $('#upload-progress').hide();
                
                if (r.success) {
                    verCotizacion(r.data.id);
                } else {
                    alert(r.data.message);
                }
            },
            error: function() {
                $('#upload-zone').show();
                $('#upload-progress').hide();
                alert('Error al subir archivo');
            }
        });
    }

    // Cerrar modales
    $('.modal-close').on('click', function() {
        $(this).closest('.riverso-modal').hide();
    });

    // Spinner CSS
    $('<style>.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }</style>').appendTo('head');

    // Init
    cargarCotizaciones();
});
</script>
