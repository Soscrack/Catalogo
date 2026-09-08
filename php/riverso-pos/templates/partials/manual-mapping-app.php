<?php
/**
 * Partial: Mapeo manual (Proveedor + Código → SKU) con preview de historial de costos.
 * Reutilizable en wp-admin y portal /interno/manual-mapping/
 */

if (!defined('ABSPATH')) {
    exit;
}

$riverso_manual_mapping_context = isset($riverso_manual_mapping_context)
    ? $riverso_manual_mapping_context
    : 'admin';

$cost_history_url = $riverso_manual_mapping_context === 'portal'
    ? home_url('/interno/cost-history/')
    : admin_url('admin.php?page=riverso-pos-costs');

$pre_proveedor = isset($_GET['proveedor_id']) ? absint($_GET['proveedor_id']) : 0;
$pre_codigo = isset($_GET['codigo']) ? sanitize_text_field(wp_unslash($_GET['codigo'])) : '';
if ($pre_codigo === '' && isset($_GET['codigo_proveedor'])) {
    $pre_codigo = sanitize_text_field(wp_unslash($_GET['codigo_proveedor']));
}
?>

<div class="riverso-manual-mapping"
     id="riverso-manual-mapping"
     data-context="<?php echo esc_attr($riverso_manual_mapping_context); ?>"
     data-cost-url="<?php echo esc_url($cost_history_url); ?>"
     data-pre-proveedor="<?php echo esc_attr((string) $pre_proveedor); ?>"
     data-pre-codigo="<?php echo esc_attr($pre_codigo); ?>">

    <div class="rmm-layout">
        <div class="rmm-form-panel">
            <h3 class="rmm-section-title">Vincular código a SKU</h3>
            <p class="rmm-hint">
                Elegí un proveedor y un código (p. ej. de folios antiguos). Al guardar, el mapeo queda
                verificado para cotizaciones, facturas futuras y el historial de costos de ese código.
            </p>

            <div class="rmm-field">
                <label for="rmm-proveedor-search">Proveedor *</label>
                <input type="text" id="rmm-proveedor-search" class="rmm-input" placeholder="Buscar por nombre o RUT..." autocomplete="off">
                <div id="rmm-proveedor-results" class="rmm-picker" hidden></div>
                <div id="rmm-proveedor-selected" class="rmm-picked" hidden></div>
                <input type="hidden" id="rmm-proveedor-id">
            </div>

            <div class="rmm-field">
                <label for="rmm-codigo-search">Código proveedor *</label>
                <input type="text" id="rmm-codigo-search" class="rmm-input" placeholder="Buscar o escribir código..." autocomplete="off" disabled>
                <div id="rmm-codigo-results" class="rmm-picker" hidden></div>
                <div id="rmm-codigo-selected" class="rmm-picked" hidden></div>
                <input type="hidden" id="rmm-codigo">
            </div>

            <div class="rmm-field">
                <label for="rmm-sku-search">SKU local *</label>
                <input type="text" id="rmm-sku-search" class="rmm-input" placeholder="Buscar por SKU o nombre..." autocomplete="off" disabled>
                <div id="rmm-sku-results" class="rmm-picker" hidden></div>
                <div id="rmm-sku-selected" class="rmm-picked" hidden></div>
                <input type="hidden" id="rmm-sku">
            </div>

            <div class="rmm-field">
                <label for="rmm-audit">Motivo (auditoría)</label>
                <textarea id="rmm-audit" class="rmm-input" rows="2" placeholder="Opcional: queda registrado en auditoría"></textarea>
            </div>

            <div id="rmm-conflict" class="rmm-conflict" hidden></div>
            <div id="rmm-error" class="rmm-error" hidden></div>
            <div id="rmm-success" class="rmm-success" hidden></div>

            <div class="rmm-actions">
                <button type="button" class="button" id="rmm-btn-clear">Limpiar</button>
                <button type="button" class="button" id="rmm-btn-unlink" disabled>Desvincular</button>
                <button type="button" class="button button-primary" id="rmm-btn-save" disabled>Guardar mapeo</button>
            </div>
        </div>

        <div class="rmm-preview-panel">
            <div class="rmm-preview-header">
                <h3 class="rmm-section-title">Historial de costos del código</h3>
                <a href="<?php echo esc_url($cost_history_url); ?>" class="button button-small" id="rmm-link-costs" target="_blank" rel="noopener">
                    Abrir Historial de Costos
                </a>
            </div>

            <div id="rmm-preview-empty" class="rmm-empty">
                Seleccioná proveedor y código para ver el historial de costos de ese par.
            </div>

            <div id="rmm-preview-body" hidden>
                <div class="rmm-meta" id="rmm-meta"></div>
                <div class="rmm-summary" id="rmm-summary"></div>
                <div class="rmm-timeline" id="rmm-timeline"></div>
            </div>
        </div>
    </div>

    <div class="rmm-unmapped-section">
        <div class="rmm-preview-header">
            <h3 class="rmm-section-title">Códigos recientes sin SKU</h3>
            <button type="button" class="button button-small" id="rmm-btn-refresh-unmapped">Actualizar</button>
        </div>
        <p class="rmm-hint">Pares vistos en facturas sin SKU local — útil para folios antiguos.</p>
        <div id="rmm-unmapped-list" class="rmm-unmapped-list">
            <p class="rmm-muted">Cargando…</p>
        </div>
    </div>
</div>

<style>
.riverso-manual-mapping { max-width: 1200px; }
.rmm-layout {
    display: grid;
    grid-template-columns: minmax(280px, 380px) 1fr;
    gap: 20px;
    margin-bottom: 28px;
}
@media (max-width: 900px) {
    .rmm-layout { grid-template-columns: 1fr; }
}
.rmm-form-panel, .rmm-preview-panel, .rmm-unmapped-section {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 6px;
    padding: 16px 18px;
}
.rmm-section-title { margin: 0 0 8px; font-size: 15px; }
.rmm-hint { color: #646970; font-size: 13px; margin: 0 0 14px; }
.rmm-muted { color: #646970; font-size: 13px; }
.rmm-field { margin-bottom: 14px; position: relative; }
.rmm-field label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
.rmm-input {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 8px 10px;
}
.rmm-picker {
    position: absolute;
    z-index: 20;
    left: 0; right: 0;
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    max-height: 220px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.rmm-picker-item {
    display: block;
    width: 100%;
    text-align: left;
    border: 0;
    background: transparent;
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f1;
}
.rmm-picker-item:hover { background: #f6f7f7; }
.rmm-picker-item .rmm-pi-main { font-weight: 600; font-size: 13px; }
.rmm-picker-item .rmm-pi-meta { font-size: 12px; color: #646970; }
.rmm-picked {
    margin-top: 6px;
    padding: 6px 8px;
    background: #edfaef;
    border-left: 3px solid #00a32a;
    font-size: 13px;
}
.rmm-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.rmm-error, .rmm-conflict {
    margin: 10px 0;
    padding: 8px 10px;
    background: #fcf0f1;
    border-left: 4px solid #d63638;
    font-size: 13px;
}
.rmm-success {
    margin: 10px 0;
    padding: 8px 10px;
    background: #edfaef;
    border-left: 4px solid #00a32a;
    font-size: 13px;
}
.rmm-preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
}
.rmm-empty {
    padding: 24px 8px;
    color: #646970;
    text-align: center;
    font-size: 13px;
}
.rmm-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 16px;
    margin-bottom: 12px;
    font-size: 13px;
}
.rmm-meta code { background: #f0f0f1; padding: 1px 6px; border-radius: 3px; }
.rmm-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    background: #f0f0f1;
    font-size: 12px;
}
.rmm-badge.warn { background: #fcf9e8; color: #996800; }
.rmm-badge.ok { background: #edfaef; color: #007017; }
.rmm-summary {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 8px;
    margin-bottom: 14px;
}
.rmm-stat {
    background: #f6f7f7;
    border-radius: 4px;
    padding: 8px 10px;
}
.rmm-stat .rmm-stat-label { font-size: 11px; color: #646970; text-transform: uppercase; }
.rmm-stat .rmm-stat-value { font-size: 15px; font-weight: 600; margin-top: 2px; }
.rmm-timeline-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rmm-timeline-table th,
.rmm-timeline-table td {
    border-bottom: 1px solid #f0f0f1;
    padding: 6px 8px;
    text-align: left;
}
.rmm-timeline-table th { color: #646970; font-weight: 600; }
.rmm-unmapped-list { margin-top: 8px; }
.rmm-unmapped-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f1;
    font-size: 13px;
}
.rmm-unmapped-item:last-child { border-bottom: 0; }
.rmm-unmapped-meta { color: #646970; font-size: 12px; }
</style>
