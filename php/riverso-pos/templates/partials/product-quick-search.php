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
                <h2 id="pqs-v-nombre" class="pqs-view-only" style="margin:0;"></h2>
                <div class="pqs-edit-only pqs-nombre-edit" style="display:none;">
                    <input type="text" id="pqs-edit-nombre" class="regular-text" style="min-width:280px;max-width:100%;">
                    <button type="button" class="button button-small button-primary" id="pqs-save-nombre">Guardar nombre</button>
                </div>
                <div id="pqs-v-precio-bruto" class="pqs-hero-price">
                    <span class="pqs-hero-price-value">—</span>
                    <span class="pqs-hero-price-label">bruto</span>
                </div>
            </div>
            <div class="pqs-viewer-actions">
                <?php if ($can_manage_pqs): ?>
                <button type="button" class="button button-primary" id="pqs-btn-edit">✎ Editar</button>
                <button type="button" class="button" id="pqs-btn-exit-edit" style="display:none;">Salir de edición</button>
                <a href="#" class="button" id="pqs-btn-ficha" style="display:none;">Ficha completa</a>
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
                    <div class="pqs-edit-only" id="pqs-edit-barcodes" style="display:none;">
                        <div class="pqs-task-form" style="margin-top:6px;">
                            <input type="text" id="pqs-add-barcode" placeholder="Nuevo código de barras">
                            <button type="button" class="button button-small button-primary" id="pqs-btn-add-barcode">Agregar</button>
                        </div>
                    </div>
                </div>
                <div class="pqs-field" id="pqs-field-supplier-codes" style="display:none;">
                    <span class="pqs-label">Códigos internos / proveedor</span>
                    <div id="pqs-v-supplier-codes" class="pqs-value"></div>
                </div>
                <div id="pqs-barcode-warnings" class="pqs-value" style="display:none;"></div>
                <div class="pqs-field">
                    <span class="pqs-label">Proveedores</span>
                    <div id="pqs-v-suppliers" class="pqs-value"></div>
                    <div class="pqs-edit-only" id="pqs-edit-suppliers" style="display:none;">
                        <div class="pqs-task-form" style="margin-top:6px;">
                            <input type="text" id="pqs-sup-search" placeholder="Proveedor…">
                            <input type="hidden" id="pqs-sup-id" value="">
                            <input type="text" id="pqs-sup-code" placeholder="Código proveedor">
                            <button type="button" class="button button-small button-primary" id="pqs-btn-add-supplier">Vincular</button>
                            <div id="pqs-sup-suggestions" class="pqs-sup-suggestions" style="display:none;width:100%;"></div>
                        </div>
                    </div>
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
                        <span class="pqs-label">Precio <span class="pqs-help" id="pqs-help-precio" title="">?</span> <a class="pqs-folio-link" id="pqs-folio-precio" href="#" hidden></a></span>
                        <div id="pqs-v-precio" class="pqs-value pqs-num pqs-view-only"></div>
                        <div class="pqs-edit-only" id="pqs-edit-precio-wrap" style="display:none;">
                            <label class="pqs-edit-precio-label" for="pqs-edit-precio">
                                Precio manual (<span id="pqs-edit-precio-mode">neto</span>)
                            </label>
                            <div class="pqs-task-form" style="margin-top:4px;">
                                <input type="number" id="pqs-edit-precio" step="0.01" min="0" style="width:120px;">
                                <button type="button" class="button button-small button-primary" id="pqs-save-precio">Guardar</button>
                            </div>
                            <small class="pqs-edit-hint">Se guarda como origen Manual.</small>
                        </div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Coste <span class="pqs-help" id="pqs-help-costo" title="">?</span> <a class="pqs-folio-link" id="pqs-folio-costo" href="#" hidden></a></span>
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
                        <div id="pqs-v-stock-min" class="pqs-value pqs-num pqs-view-only"></div>
                        <div class="pqs-edit-only pqs-stock-edit" style="display:none;">
                            <input type="number" id="pqs-edit-stock-min" min="0" step="1" style="width:80px;">
                        </div>
                    </div>
                    <div class="pqs-field">
                        <span class="pqs-label">Crítico</span>
                        <div id="pqs-v-stock-crit" class="pqs-value pqs-num pqs-view-only"></div>
                        <div class="pqs-edit-only pqs-stock-edit" style="display:none;">
                            <input type="number" id="pqs-edit-stock-crit" min="0" step="1" style="width:80px;">
                            <button type="button" class="button button-small button-primary" id="pqs-save-stock-cfg">Guardar</button>
                        </div>
                    </div>
                </div>
                <div class="pqs-field" style="margin-top:8px;">
                    <span class="pqs-label">Lugares preferidos</span>
                    <div id="pqs-v-loc-pref" class="pqs-value"></div>
                    <div class="pqs-edit-only" id="pqs-edit-locations" style="display:none;">
                        <div class="pqs-task-form" style="margin-top:6px;">
                            <input type="text" id="pqs-loc-search" placeholder="Buscar ubicación…">
                            <input type="hidden" id="pqs-loc-id" value="">
                            <button type="button" class="button button-small button-primary" id="pqs-btn-add-loc">Agregar</button>
                            <div id="pqs-loc-suggestions" class="pqs-sup-suggestions" style="display:none;width:100%;"></div>
                        </div>
                    </div>
                </div>
                <div class="pqs-field">
                    <span class="pqs-label">Lugares actuales</span>
                    <div id="pqs-v-loc-act" class="pqs-value"></div>
                </div>
            </div>

            <div class="pqs-card" id="pqs-card-relations">
                <h3>
                    Familia / Emparejamiento
                    <button type="button" class="button button-small pqs-edit-only" id="pqs-btn-refresh-relations" style="display:none;margin-left:8px;">Actualizar</button>
                </h3>
                <div id="pqs-v-family" class="pqs-relation-block"></div>
                <div id="pqs-v-emparejamiento" class="pqs-relation-block"></div>
            </div>

            <div class="pqs-card pqs-card-wide" id="pqs-card-tasks">
                <h3>Tareas <span id="pqs-tasks-count" class="pqs-badge"></span></h3>
                <div id="pqs-v-tasks" class="pqs-tasks"></div>
            </div>
        </div>
    </div>

    <div id="pqs-related" class="pqs-related" style="display:none;">
        <h3 id="pqs-related-title">Otras coincidencias</h3>
        <table class="wp-list-table widefat fixed striped pqs-grid">
            <thead>
                <tr>
                    <th style="width:36%">Nombre</th>
                    <th style="width:14%">SKU</th>
                    <th style="width:25%">Barcode</th>
                    <th style="width:25%">Código proveedor</th>
                </tr>
            </thead>
            <tbody id="pqs-related-tbody"></tbody>
        </table>
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

<!-- Modal confirmación -->
<div id="pqs-confirm-modal" class="pqs-modal" style="display:none;">
    <div class="pqs-modal-dialog pqs-confirm-dialog">
        <div class="pqs-modal-header">
            <h2 id="pqs-confirm-title" style="margin:0;">¿Estás seguro?</h2>
            <button type="button" class="button" id="pqs-confirm-close">✕</button>
        </div>
        <div class="pqs-modal-body">
            <div id="pqs-confirm-body"></div>
            <label id="pqs-confirm-check-wrap" class="pqs-confirm-check" style="display:none;">
                <input type="checkbox" id="pqs-confirm-check"> <span>Entiendo las consecuencias</span>
            </label>
        </div>
        <div class="pqs-modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid #ddd;">
            <button type="button" class="button" id="pqs-confirm-cancel">Cancelar</button>
            <button type="button" class="button button-primary" id="pqs-confirm-ok">Confirmar</button>
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
