<?php
/**
 * Partial: Búsqueda rápida de productos locales.
 *
 * @package Riverso_POS
 */
if (!defined('ABSPATH')) {
    exit;
}
$can_manage_pqs = !empty($can_manage);
?>
<div class="pqs-wrap">
    <p class="pqs-intro">Búsqueda rápida solo en productos locales. Escaneá o escribí SKU, código de barras o código de proveedor.</p>

    <div class="pqs-search-bar">
        <input type="text" id="pqs-input" class="regular-text pqs-input" placeholder="SKU local, código de barras o código proveedor" autocomplete="off" autofocus>
        <button type="button" class="button button-primary" id="pqs-btn-search">Buscar</button>
        <button type="button" class="button" id="pqs-btn-lupa" title="Búsqueda por nombre, proveedor, código…">
            <span class="dashicons dashicons-search" style="margin-top:3px;"></span>
        </button>
    </div>

    <div id="pqs-status" class="pqs-status" style="display:none;"></div>

    <div id="pqs-results" class="pqs-results" style="display:none;">
        <table class="wp-list-table widefat fixed striped pqs-grid">
            <thead>
                <tr>
                    <th style="width:12%">SKU</th>
                    <th style="width:28%">Nombre</th>
                    <th style="width:16%">Proveedor</th>
                    <th style="width:12%">Cód. prov.</th>
                    <th style="width:12%">Barcode</th>
                    <th style="width:10%">Precio</th>
                    <th style="width:10%">Stock</th>
                </tr>
            </thead>
            <tbody id="pqs-results-tbody"></tbody>
        </table>
    </div>

    <div id="pqs-viewer" class="pqs-viewer" style="display:none;">
        <div class="pqs-viewer-header">
            <div class="pqs-viewer-title">
                <code id="pqs-v-sku"></code>
                <h2 id="pqs-v-nombre" style="margin:0;"></h2>
            </div>
            <div class="pqs-viewer-actions">
                <?php if ($can_manage_pqs): ?>
                <button type="button" class="button button-primary" id="pqs-btn-edit">✎ Editar</button>
                <?php endif; ?>
                <button type="button" class="button" id="pqs-btn-close-viewer">Cerrar</button>
            </div>
        </div>

        <div id="pqs-alerts" class="pqs-alerts"></div>

        <div class="pqs-cards">
            <div class="pqs-card" id="pqs-card-codes">
                <h3>Códigos</h3>
                <div class="pqs-field">
                    <span class="pqs-label">Códigos de barra</span>
                    <div id="pqs-v-barcodes" class="pqs-value"></div>
                </div>
                <div class="pqs-field">
                    <span class="pqs-label">Proveedores</span>
                    <div id="pqs-v-suppliers" class="pqs-value"></div>
                </div>
            </div>

            <div class="pqs-card" id="pqs-card-pricing">
                <h3>
                    Precio / Coste / Margen
                    <span class="pqs-toggles">
                        <button type="button" class="button button-small pqs-view-mode" data-mode="neto">Neto</button>
                        <button type="button" class="button button-small pqs-view-mode" data-mode="bruto">Bruto</button>
                    </span>
                </h3>
                <div class="pqs-cost-modes">
                    <button type="button" class="button button-small pqs-cost-mode" data-cost="referencia">Referencia</button>
                    <button type="button" class="button button-small pqs-cost-mode" data-cost="tras_dr">Tras D/R</button>
                    <button type="button" class="button button-small pqs-cost-mode" data-cost="tras_dr_folio">Tras D/R folio</button>
                    <button type="button" class="button button-small pqs-cost-mode" data-cost="tras_dr_flete">Con flete</button>
                </div>
                <div class="pqs-pricing-grid">
                    <div class="pqs-field">
                        <span class="pqs-label">Precio <span class="pqs-help" id="pqs-help-precio" title="">?</span></span>
                        <div id="pqs-v-precio" class="pqs-value pqs-num"></div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Coste <span class="pqs-help" id="pqs-help-costo" title="">?</span></span>
                        <div id="pqs-v-costo" class="pqs-value pqs-num"></div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Margen</span>
                        <div id="pqs-v-margen" class="pqs-value pqs-num"></div>
                    </div>
                </div>
            </div>

            <div class="pqs-card" id="pqs-card-stock">
                <h3>Stock</h3>
                <div class="pqs-pricing-grid">
                    <div class="pqs-field">
                        <span class="pqs-label">Actual <span class="pqs-help" id="pqs-help-stock" title="">?</span></span>
                        <div id="pqs-v-stock" class="pqs-value"></div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Mínimo</span>
                        <div id="pqs-v-stock-min" class="pqs-value pqs-num"></div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Crítico</span>
                        <div id="pqs-v-stock-crit" class="pqs-value pqs-num"></div>
                    </div>
                </div>
                <div class="pqs-field" style="margin-top:8px;">
                    <span class="pqs-label">Lugares preferidos</span>
                    <div id="pqs-v-loc-pref" class="pqs-value"></div>
                </div>
                <div class="pqs-field">
                    <span class="pqs-label">Lugares actuales</span>
                    <div id="pqs-v-loc-act" class="pqs-value"></div>
                </div>
            </div>

            <div class="pqs-card" id="pqs-card-relations">
                <h3>Familia / Emparejamiento</h3>
                <div id="pqs-v-family" class="pqs-relation-block"></div>
                <div id="pqs-v-emparejamiento" class="pqs-relation-block"></div>
            </div>

            <div class="pqs-card pqs-card-wide" id="pqs-card-tasks">
                <h3>Tareas <span id="pqs-tasks-count" class="pqs-badge"></span></h3>
                <div id="pqs-v-tasks" class="pqs-tasks"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Lupa -->
<div id="pqs-lupa-modal" class="pqs-modal" style="display:none;">
    <div class="pqs-modal-dialog">
        <div class="pqs-modal-header">
            <h2 style="margin:0;">Búsqueda avanzada</h2>
            <button type="button" class="button" id="pqs-lupa-close">✕</button>
        </div>
        <div class="pqs-modal-body">
            <div class="pqs-lupa-bar">
                <select id="pqs-lupa-field">
                    <option value="todos">Todos</option>
                    <option value="nombre">Nombre</option>
                    <option value="proveedor">Proveedor</option>
                    <option value="codigo_proveedor">Código proveedor</option>
                    <option value="sku">SKU</option>
                    <option value="barcode">Código de barras</option>
                </select>
                <input type="text" id="pqs-lupa-input" class="regular-text" placeholder="Buscar…" autocomplete="off">
                <button type="button" class="button button-primary" id="pqs-lupa-search">Buscar</button>
            </div>
            <div id="pqs-lupa-status" class="pqs-status" style="display:none;"></div>
            <table class="wp-list-table widefat fixed striped pqs-grid">
                <thead>
                    <tr>
                        <th style="width:12%">SKU</th>
                        <th style="width:30%">Nombre</th>
                        <th style="width:18%">Proveedor</th>
                        <th style="width:14%">Cód. prov.</th>
                        <th style="width:14%">Barcode</th>
                        <th style="width:12%">Stock</th>
                    </tr>
                </thead>
                <tbody id="pqs-lupa-tbody"><tr><td colspan="6" style="color:#666;">Escribí y buscá…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Popover miembros -->
<div id="pqs-members-popover" class="pqs-popover" style="display:none;">
    <div class="pqs-popover-header">
        <strong id="pqs-members-title">Miembros</strong>
        <button type="button" class="button-link" id="pqs-members-close">✕</button>
    </div>
    <div id="pqs-members-body" class="pqs-popover-body"></div>
    <div class="pqs-popover-footer">
        <a href="#" id="pqs-members-link" target="_blank">Abrir en Categorías/Familias</a>
    </div>
</div>
