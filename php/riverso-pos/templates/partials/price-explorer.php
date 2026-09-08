<?php
/**
 * Explorador de precios: búsqueda + paneles local/online/familia/competencia.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="riverso-price-explorer" id="riverso-price-explorer">
    <div class="rce-search-panel">
        <div class="rpe-search-toolbar">
            <label class="rce-search-label" for="rpe-search-input">Buscar producto</label>
            <div class="rpe-view-toggle" role="group" aria-label="Vista de montos">
                <button type="button" class="button rpe-view-btn is-active" data-view="bruto">Bruto</button>
                <button type="button" class="button rpe-view-btn" data-view="neto">Neto</button>
            </div>
        </div>
        <div class="rce-search-row">
            <input type="text" id="rpe-search-input" class="rce-search-input"
                   placeholder="SKU local, código de barras, código proveedor o nombre..."
                   autocomplete="off">
            <button type="button" class="button button-primary" id="rpe-btn-search">Buscar</button>
        </div>
        <div id="rpe-search-results" class="rce-search-results" hidden></div>
        <p class="rce-hint">Montos de referencia (<code>c_ref</code>, <code>p_ref</code>, asignado) son <strong>bruto</strong> comercial. Neto = bruto ÷ 1,19 (4 decimales). Margen = P bruto ÷ C bruto.</p>
    </div>

    <div id="rpe-empty-state" class="rce-empty-state">
        <p>Escribe un código o nombre para ver precios, márgenes e historial.</p>
    </div>

    <div id="rpe-product-panel" class="rce-product-panel" hidden>
        <div class="rce-product-header">
            <div>
                <h3 id="rpe-product-name" class="rce-product-name">—</h3>
                <div class="rce-product-meta">
                    <span>SKU: <code id="rpe-product-sku">—</code></span>
                </div>
            </div>
            <div class="rce-header-actions">
                <button type="button" class="button" id="rpe-btn-clear">Nueva búsqueda</button>
            </div>
        </div>

        <div class="rpe-channel-grid">
            <div class="rpe-channel-card" data-canal="local">
                <h4>Precio local</h4>
                <p class="description" style="margin-top:0;">c_ref / p_ref / asignado = bruto. Neto = bruto ÷ 1,19. Margen = P bruto ÷ C bruto.</p>
                <dl class="rpe-dl">
                    <dt>c_ref <span class="rpe-view-hint" data-hint-for="costo"></span></dt>
                    <dd>
                        <span id="rpe-local-cref" class="rpe-primary-amt">—</span>
                        <span id="rpe-local-cref-alt" class="rpe-alt-amt"></span>
                        <span id="rpe-local-origen-costo" class="rpe-origin-badge"></span>
                    </dd>
                    <dt>p_ref <span class="rpe-view-hint" data-hint-for="pref"></span></dt>
                    <dd>
                        <span id="rpe-local-pref" class="rpe-primary-amt">—</span>
                        <span id="rpe-local-pref-alt" class="rpe-alt-amt"></span>
                    </dd>
                    <dt>p_asignado <span class="rpe-view-hint" data-hint-for="precio"></span></dt>
                    <dd>
                        <input type="number" step="0.001" min="0" id="rpe-local-asignado" class="small-text" style="width:120px;" title="Siempre bruto (TPV)">
                        <span id="rpe-local-asignado-view" class="rpe-primary-amt" style="display:none;"></span>
                        <span id="rpe-local-asignado-alt" class="rpe-alt-amt"></span>
                        <span id="rpe-local-origen-precio" class="rpe-origin-badge"></span>
                    </dd>
                    <dt>Venta (neto)</dt><dd id="rpe-local-neto">—</dd>
                    <dt>Margen (P bruto / C bruto)</dt><dd id="rpe-local-margen">—</dd>
                    <dt>Estado</dt><dd id="rpe-local-estado">—</dd>
                </dl>
                <button type="button" class="button button-primary rpe-save" data-canal="local">Guardar local</button>
            </div>
            <div class="rpe-channel-card" data-canal="online">
                <h4>Precio online <span id="rpe-online-badge" class="rpe-badge rpe-badge-off">No en uso</span></h4>
                <p class="description" style="margin-top:0;">Mismo criterio bruto/neto. Guardar no activa uso en POS/Woo.</p>
                <dl class="rpe-dl">
                    <dt>c_ref <span class="rpe-view-hint" data-hint-for="costo"></span></dt>
                    <dd>
                        <span id="rpe-online-cref" class="rpe-primary-amt">—</span>
                        <span id="rpe-online-cref-alt" class="rpe-alt-amt"></span>
                        <span id="rpe-online-origen-costo" class="rpe-origin-badge"></span>
                    </dd>
                    <dt>p_ref <span class="rpe-view-hint" data-hint-for="pref"></span></dt>
                    <dd>
                        <span id="rpe-online-pref" class="rpe-primary-amt">—</span>
                        <span id="rpe-online-pref-alt" class="rpe-alt-amt"></span>
                    </dd>
                    <dt>p_asignado <span class="rpe-view-hint" data-hint-for="precio"></span></dt>
                    <dd>
                        <input type="number" step="0.001" min="0" id="rpe-online-asignado" class="small-text" style="width:120px;" title="Siempre bruto (TPV)">
                        <span id="rpe-online-asignado-view" class="rpe-primary-amt" style="display:none;"></span>
                        <span id="rpe-online-asignado-alt" class="rpe-alt-amt"></span>
                        <span id="rpe-online-origen-precio" class="rpe-origin-badge"></span>
                    </dd>
                    <dt>Venta (neto)</dt><dd id="rpe-online-neto">—</dd>
                    <dt>Margen (P bruto / C bruto)</dt><dd id="rpe-online-margen">—</dd>
                    <dt>Estado</dt><dd id="rpe-online-estado">—</dd>
                </dl>
                <div class="rpe-online-actions">
                    <button type="button" class="button rpe-save" data-canal="online">Guardar online (sin usar)</button>
                    <button type="button" class="button button-primary" id="rpe-copy-local">Dejar precio Online igual que local</button>
                    <button type="button" class="button" id="rpe-activate-online">Activar precio online</button>
                </div>
            </div>
        </div>

        <div class="rpe-section" id="rpe-family-section" hidden>
            <h4>Familia <span id="rpe-family-name"></span></h4>
            <p class="description" id="rpe-family-meta"></p>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Miembro</th>
                        <th style="text-align:right">Uds. envase</th>
                        <th style="text-align:right">Precio unitario (bruto)</th>
                        <th style="text-align:right">Total presentación (bruto)</th>
                        <th style="text-align:right">Costo u.</th>
                        <th style="text-align:right">Margen u.</th>
                    </tr>
                </thead>
                <tbody id="rpe-family-body"></tbody>
            </table>
        </div>

        <div class="rpe-section" id="rpe-comp-section" hidden>
            <div class="rpe-comp-head">
                <h4>Competencia</h4>
                <a class="button button-small" id="rpe-comp-admin-link" href="#" target="_blank" rel="noopener">Abrir Competencia</a>
            </div>
            <p class="description" id="rpe-comp-empty" hidden>Sin mapeos ni sugerencias para este SKU.</p>

            <div id="rpe-comp-mapeados-wrap" hidden>
                <h5 class="rpe-comp-subtitle">Mapeados</h5>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Fuente</th>
                            <th>Producto rival</th>
                            <th>Código</th>
                            <th>Tipo</th>
                            <th style="text-align:right">Nuestro P</th>
                            <th style="text-align:right">Rival P/u</th>
                            <th style="text-align:right">Δ</th>
                            <th>Actualizado</th>
                        </tr>
                    </thead>
                    <tbody id="rpe-comp-mapeados-body"></tbody>
                </table>
            </div>

            <div id="rpe-comp-sugeridos-wrap" hidden>
                <h5 class="rpe-comp-subtitle">
                    Sugeridos
                    <span class="description" style="font-weight:normal;"> — confirmar en Competencia</span>
                </h5>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Fuente</th>
                            <th>Producto rival</th>
                            <th>Código</th>
                            <th>Score / método</th>
                            <th style="text-align:right">Nuestro P</th>
                            <th style="text-align:right">Rival P/u</th>
                            <th style="text-align:right">Δ</th>
                        </tr>
                    </thead>
                    <tbody id="rpe-comp-sugeridos-body"></tbody>
                </table>
            </div>

            <div id="rpe-comp-familia-wrap" hidden>
                <h5 class="rpe-comp-subtitle">Comparación por miembro (familia unitaria)</h5>
                <p class="description">Precio unitario bruto de cada miembro vs P/u de cada rival.</p>
                <div id="rpe-comp-familia-body"></div>
            </div>
        </div>

        <div class="rpe-section">
            <h4>Historial de precio propio</h4>
            <div class="rpe-chart-wrap">
                <canvas id="rpe-chart" height="120"></canvas>
            </div>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Canal</th>
                        <th>Origen</th>
                        <th style="text-align:right">Anterior</th>
                        <th style="text-align:right">Nuevo</th>
                        <th style="text-align:right">Margen u.</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody id="rpe-hist-body"></tbody>
            </table>
        </div>
    </div>
</div>

<div id="rpe-save-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpe-save-backdrop"></div>
    <div class="rpf-modal-card rpe-save-card">
        <div class="rpf-modal-head">
            <h3 style="margin:0;">Confirmar guardar precio</h3>
            <button type="button" class="button rpe-save-cancel">Cancelar</button>
        </div>
        <div class="rpf-modal-body">
            <p class="rpe-save-meta" id="rpe-save-meta">—</p>
            <table class="widefat striped rpe-save-diff">
                <tbody>
                    <tr>
                        <th scope="row">Precio (bruto)</th>
                        <td id="rpe-save-precio-bruto">—</td>
                    </tr>
                    <tr>
                        <th scope="row">Precio (neto)</th>
                        <td id="rpe-save-precio-neto">—</td>
                    </tr>
                    <tr>
                        <th scope="row">Costo</th>
                        <td id="rpe-save-costo">—</td>
                    </tr>
                    <tr>
                        <th scope="row">Margen (P bruto / C bruto)</th>
                        <td id="rpe-save-margen">—</td>
                    </tr>
                </tbody>
            </table>
            <div class="rpe-save-actions">
                <button type="button" class="button rpe-save-cancel">Cancelar</button>
                <button type="button" class="button button-primary" id="rpe-save-confirm">Guardar</button>
            </div>
        </div>
    </div>
</div>
