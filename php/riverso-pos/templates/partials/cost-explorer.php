<?php
/**
 * Partial: Explorador de historial de costos por par (proveedor, código proveedor)
 * Reutilizable en wp-admin y portal /interno/cost-history/
 */

if (!defined('ABSPATH')) {
    exit;
}

$received_quotes_url = admin_url('admin.php?page=riverso-pos-received-quotes');
?>

<div class="riverso-cost-explorer" id="riverso-cost-explorer">
    <div class="rce-search-panel">
        <label class="rce-search-label" for="rce-search-input">
            Buscar producto
        </label>
        <div class="rce-search-row">
            <input type="text"
                   id="rce-search-input"
                   class="rce-search-input"
                   placeholder="SKU local, código de barras, código proveedor o nombre..."
                   autocomplete="off">
            <button type="button" class="button button-primary rce-btn-search" id="rce-btn-search">
                Buscar
            </button>
        </div>
        <div id="rce-search-results" class="rce-search-results" hidden></div>
        <p class="rce-hint">Busca por SKU local, EAN/código de barra o código de proveedor. El historial se arma desde las facturas XML cargadas.</p>
    </div>

    <div id="rce-empty-state" class="rce-empty-state">
        <p>Escribe un código o nombre para ver el historial de costos por proveedor.</p>
    </div>

    <div id="rce-product-panel" class="rce-product-panel" hidden>
        <div class="rce-product-header">
            <div>
                <h3 id="rce-product-name" class="rce-product-name">—</h3>
                <div class="rce-product-meta">
                    <span>SKU: <code id="rce-product-sku">—</code></span>
                    <span id="rce-product-pairs-count" class="rce-badge">0 pares</span>
                </div>
            </div>
            <div class="rce-header-actions">
                <label class="rce-decimals-toggle" title="Mostrar u ocultar hasta 3 decimales en costos">
                    <input type="checkbox" id="rce-toggle-decimals" checked>
                    Costos con decimales <em>(hasta 3)</em>
                </label>
                <button type="button" class="button rce-btn-clear" id="rce-btn-clear">Nueva búsqueda</button>
            </div>
        </div>

        <div id="rce-highlight" class="rce-highlight" hidden>
            <div class="rce-highlight-label">Último costo</div>
            <div class="rce-highlight-value" id="rce-highlight-cost">—</div>
            <div class="rce-highlight-meta">
                <span id="rce-highlight-pair">—</span>
                <span id="rce-highlight-date">—</span>
                <span id="rce-highlight-folio">—</span>
                <span id="rce-highlight-variation" class="rce-variation"></span>
            </div>
        </div>

        <div class="rce-section">
            <div class="rce-section-header">
                <h4>Historial por par (proveedor + código)</h4>
                <div class="rce-folio-controls">
                    <label for="rce-doc-type">Tipo</label>
                    <select id="rce-doc-type" class="rce-doc-type" title="Tipo de documento">
                        <option value="factura" selected>Factura</option>
                        <option value="guias">Guías</option>
                        <option value="cotizaciones">Cotizaciones</option>
                        <option value="todos">Todos</option>
                    </select>
                    <label for="rce-folio-limit">Mostrar</label>
                    <select id="rce-folio-limit" class="rce-folio-limit" title="Documentos por par">
                        <option value="3" selected>Últimas 3</option>
                        <option value="10">Últimas 10</option>
                        <option value="25">Últimas 25</option>
                        <option value="50">Últimas 50</option>
                        <option value="all">Todas</option>
                    </select>
                    <span class="rce-section-note" id="rce-folio-note">por par</span>
                </div>
            </div>
            <div id="rce-pairs-container" class="rce-pairs-container">
                <p class="rce-muted">Cargando…</p>
            </div>
        </div>

        <div class="rce-section">
            <div class="rce-section-header">
                <h4>Evolución del costo</h4>
                <select id="rce-chart-months" class="rce-chart-months">
                    <option value="6">Últimos 6 meses</option>
                    <option value="12">Último año</option>
                    <option value="24" selected>Últimos 2 años</option>
                    <option value="60">Últimos 5 años</option>
                </select>
            </div>
            <div class="rce-chart-wrap">
                <canvas id="rce-cost-chart" height="280"></canvas>
            </div>
            <p id="rce-chart-empty" class="rce-muted" hidden>Sin datos de costo para graficar.</p>
        </div>
    </div>
</div>

<!-- Modal documento -->
<div id="rce-doc-modal" class="rce-modal" hidden>
    <div class="rce-modal-backdrop" data-rce-close></div>
    <div class="rce-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rce-doc-title">
        <div class="rce-modal-header">
            <h2 id="rce-doc-title">Documento</h2>
            <button type="button" class="rce-modal-close" data-rce-close aria-label="Cerrar">&times;</button>
        </div>
        <div class="rce-modal-body" id="rce-doc-body">
            <p class="rce-muted">Cargando…</p>
        </div>
        <div class="rce-modal-footer">
            <button type="button" class="button" data-rce-close>Cerrar</button>
        </div>
    </div>
</div>

<!-- Modal evolución (desde Alertas u otros atajos) -->
<div id="rce-evo-modal" class="rce-modal" hidden>
    <div class="rce-modal-backdrop" data-rce-evo-close></div>
    <div class="rce-modal-dialog rce-evo-dialog" role="dialog" aria-modal="true" aria-labelledby="rce-evo-title">
        <div class="rce-modal-header">
            <div>
                <h2 id="rce-evo-title">Evolución de costo</h2>
                <div class="rce-evo-subtitle" id="rce-evo-subtitle">—</div>
            </div>
            <button type="button" class="rce-modal-close" data-rce-evo-close aria-label="Cerrar">&times;</button>
        </div>
        <div class="rce-modal-body" id="rce-evo-body">
            <div class="rce-folio-controls rce-evo-controls">
                <label for="rce-evo-doc-type">Tipo de documento</label>
                <select id="rce-evo-doc-type" title="Tipo de documento">
                    <option value="factura" selected>Factura</option>
                    <option value="guias">Guías</option>
                    <option value="todos">Todos</option>
                    <option value="cotizaciones">Cotizaciones</option>
                </select>
                <label for="rce-evo-folio-limit">Mostrar</label>
                <select id="rce-evo-folio-limit" title="Documentos por par">
                    <option value="3" selected>Últimas 3</option>
                    <option value="10">Últimas 10</option>
                    <option value="25">Últimas 25</option>
                    <option value="50">Últimas 50</option>
                    <option value="all">Todas</option>
                </select>
                <label for="rce-evo-months">Periodo</label>
                <select id="rce-evo-months">
                    <option value="6">Últimos 6 meses</option>
                    <option value="12">Último año</option>
                    <option value="24" selected>Últimos 2 años</option>
                    <option value="60">Últimos 5 años</option>
                </select>
            </div>
            <div id="rce-evo-highlight" class="rce-highlight" hidden style="margin-bottom:12px;">
                <div class="rce-highlight-label">Último costo</div>
                <div class="rce-highlight-value" id="rce-evo-highlight-cost">—</div>
                <div class="rce-highlight-meta">
                    <span id="rce-evo-highlight-pair">—</span>
                    <span id="rce-evo-highlight-date">—</span>
                    <span id="rce-evo-highlight-variation" class="rce-variation"></span>
                </div>
            </div>
            <div id="rce-evo-pairs" class="rce-pairs-container">
                <p class="rce-muted">Cargando…</p>
            </div>
            <div class="rce-section" style="margin-top:16px;">
                <h4 style="margin:0 0 8px;">Evolución del costo</h4>
                <div class="rce-chart-wrap" style="height:240px;">
                    <canvas id="rce-evo-chart"></canvas>
                </div>
                <p id="rce-evo-chart-empty" class="rce-muted" hidden>Sin datos de costo para graficar.</p>
            </div>
        </div>
        <div class="rce-modal-footer">
            <button type="button" class="button" data-rce-evo-close>Cerrar</button>
        </div>
    </div>
</div>

<style>
.riverso-cost-explorer {
    --rce-border: #ccd0d4;
    --rce-bg: #fff;
    --rce-muted: #646970;
    --rce-accent: #2271b1;
    --rce-highlight: #f0f6fc;
    --rce-success: #00a32a;
    --rce-danger: #d63638;
    max-width: 1100px;
}

.riverso-cost-explorer .button,
.rce-modal .button {
    display: inline-block;
    padding: 6px 12px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #f6f7f7;
    color: #1d2327;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.4;
}

.riverso-cost-explorer .button-primary,
.rce-modal .button-primary {
    background: var(--rce-accent);
    border-color: var(--rce-accent);
    color: #fff;
}

.riverso-cost-explorer .button-small {
    padding: 2px 8px;
    font-size: 12px;
}

.riverso-cost-explorer .button-secondary {
    background: #f6f7f7;
}

.rce-search-panel {
    background: var(--rce-bg);
    border: 1px solid var(--rce-border);
    border-radius: 6px;
    padding: 16px 18px;
    margin-bottom: 16px;
}

.rce-search-label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.rce-search-row {
    display: flex;
    gap: 8px;
    align-items: center;
}

.rce-search-input {
    flex: 1;
    min-height: 36px;
    padding: 6px 12px;
    font-size: 14px;
}

.rce-hint {
    margin: 8px 0 0;
    color: var(--rce-muted);
    font-size: 12px;
}

.rce-search-results {
    margin-top: 8px;
    border: 1px solid var(--rce-border);
    border-radius: 4px;
    max-height: 320px;
    overflow-y: auto;
    background: #fff;
}

.rce-search-results[hidden] { display: none !important; }

.rce-result-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 10px 14px;
    border: 0;
    border-bottom: 1px solid #f0f0f1;
    background: #fff;
    cursor: pointer;
}

.rce-result-item:hover,
.rce-result-item:focus {
    background: var(--rce-highlight);
    outline: none;
}

.rce-result-item .rce-result-name {
    font-weight: 600;
    display: block;
}

.rce-result-item .rce-result-meta {
    font-size: 12px;
    color: var(--rce-muted);
    margin-top: 2px;
}

.rce-empty-state {
    background: var(--rce-bg);
    border: 1px dashed var(--rce-border);
    border-radius: 6px;
    padding: 40px 20px;
    text-align: center;
    color: var(--rce-muted);
}

.rce-product-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.rce-product-panel[hidden],
.rce-empty-state[hidden],
.rce-highlight[hidden],
#rce-chart-empty[hidden] {
    display: none !important;
}

.rce-product-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    background: var(--rce-bg);
    border: 1px solid var(--rce-border);
    border-radius: 6px;
    padding: 14px 18px;
}

.rce-header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    justify-content: flex-end;
}

.rce-decimals-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--rce-muted);
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}

.rce-decimals-toggle em {
    font-style: normal;
    opacity: 0.85;
}

.rce-decimals-toggle input {
    margin: 0;
}

.rce-product-name {
    margin: 0 0 6px;
    font-size: 18px;
}

.rce-product-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    color: var(--rce-muted);
    font-size: 13px;
}

.rce-badge {
    display: inline-block;
    background: #f0f0f1;
    border-radius: 10px;
    padding: 2px 8px;
    font-size: 11px;
    color: #1d2327;
}

.rce-highlight {
    background: linear-gradient(135deg, #e7f5fe 0%, #f0f6fc 100%);
    border: 1px solid #72aee6;
    border-radius: 8px;
    padding: 18px 20px;
}

.rce-highlight-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--rce-accent);
    font-weight: 600;
}

.rce-highlight-value {
    font-size: 32px;
    font-weight: 700;
    color: #1d2327;
    margin: 4px 0 8px;
}

.rce-highlight-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    font-size: 13px;
    color: var(--rce-muted);
}

.rce-variation.up { color: var(--rce-danger); font-weight: 600; }
.rce-variation.down { color: var(--rce-success); font-weight: 600; }

.rce-section {
    background: var(--rce-bg);
    border: 1px solid var(--rce-border);
    border-radius: 6px;
    padding: 14px 18px 18px;
}

.rce-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f0f0f1;
}

.rce-section-header h4 {
    margin: 0;
}

.rce-section-note {
    font-size: 12px;
    color: var(--rce-muted);
}

.rce-folio-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.rce-folio-controls label {
    font-size: 12px;
    color: var(--rce-muted);
    margin: 0;
}

.rce-folio-limit,
.rce-doc-type {
    min-width: 120px;
}

.rce-pair-more {
    padding: 10px 12px;
    border-top: 1px solid #f0f0f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: #fafafa;
}

.rce-pair-more .rce-muted {
    margin: 0;
}

.rce-pair-card {
    border: 1px solid #e2e4e7;
    border-radius: 6px;
    margin-bottom: 12px;
    overflow: hidden;
}

.rce-pair-card:last-child { margin-bottom: 0; }

.rce-pair-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 14px;
    background: #f6f7f7;
    border-bottom: 1px solid #e2e4e7;
}

.rce-pair-title {
    font-weight: 600;
}

.rce-pair-code {
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    color: var(--rce-muted);
}

.rce-pair-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: 12px;
    color: var(--rce-muted);
}

.rce-pair-stats strong {
    color: #1d2327;
}

.rce-docs-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.rce-docs-table th,
.rce-docs-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #f0f0f1;
}

.rce-docs-table th {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--rce-muted);
    background: #fafafa;
}

.rce-docs-table tr.is-latest td {
    background: #edfaef;
    font-weight: 600;
}

.rce-docs-table .rce-num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.rce-btn-doc {
    padding: 2px 8px;
    font-size: 12px;
    cursor: pointer;
}

.rce-muted {
    color: var(--rce-muted);
    font-size: 13px;
}

.rce-chart-wrap {
    position: relative;
    height: 280px;
}

.rce-chart-months {
    min-width: 140px;
}

/* Modal */
.rce-modal {
    position: fixed;
    inset: 0;
    z-index: 100050;
    display: flex;
    align-items: center;
    justify-content: center;
}

.rce-modal[hidden] { display: none !important; }

.rce-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
}

.rce-modal-dialog {
    position: relative;
    background: #fff;
    border-radius: 6px;
    width: min(920px, 94vw);
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
}

.rce-modal-dialog.rce-evo-dialog {
    width: min(980px, 96vw);
}

.rce-evo-subtitle {
    font-size: 13px;
    color: var(--rce-muted);
    margin-top: 2px;
}

.rce-evo-controls {
    margin-bottom: 12px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    padding: 10px 12px;
    background: #f6f7f7;
    border: 1px solid var(--rce-border);
    border-radius: 4px;
}

.rce-evo-controls label {
    font-size: 12px;
    color: var(--rce-muted);
    margin: 0;
}

.rce-evo-controls select {
    min-width: 120px;
}

.rce-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid var(--rce-border);
}

.rce-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.rce-modal-close {
    background: none;
    border: 0;
    font-size: 24px;
    cursor: pointer;
    line-height: 1;
    color: var(--rce-muted);
}

.rce-modal-body {
    padding: 16px 18px;
    overflow-y: auto;
    flex: 1;
}

.rce-modal-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--rce-border);
    text-align: right;
}

.rce-doc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}

.rce-doc-field label {
    display: block;
    font-size: 11px;
    color: var(--rce-muted);
    text-transform: uppercase;
}

.rce-doc-field span {
    font-weight: 600;
}

.rce-doc-items {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.rce-doc-items th,
.rce-doc-items td {
    padding: 7px 10px;
    border-bottom: 1px solid #f0f0f1;
    text-align: left;
}

.rce-doc-items th {
    background: #f6f7f7;
    font-size: 11px;
    text-transform: uppercase;
    color: var(--rce-muted);
}

.rce-doc-items tr.is-highlight td {
    background: #fff8e5;
    outline: 2px solid #dba617;
    outline-offset: -2px;
}

.rce-doc-items .rce-num { text-align: right; }

/* WIP block (reused) */
.rce-wip-block {
    background: #fff;
    border: 1px dashed #c3c4c7;
    border-radius: 6px;
    padding: 24px 20px;
    text-align: center;
    max-width: 700px;
}

.rce-wip-badge {
    display: inline-block;
    background: #fcf0e3;
    color: #996800;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 10px;
    border-radius: 10px;
    margin-bottom: 10px;
}

.rce-wip-block h3 {
    margin: 0 0 8px;
}

.rce-wip-block p {
    color: var(--rce-muted);
    margin: 0 0 14px;
}

@media (max-width: 782px) {
    .rce-search-row { flex-direction: column; align-items: stretch; }
    .rce-product-header { flex-direction: column; }
    .rce-pair-head { flex-direction: column; }
    .rce-docs-table { display: block; overflow-x: auto; }
}
</style>
