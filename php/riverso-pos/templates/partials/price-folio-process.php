<?php
/**
 * Pestaña Procesar folios — Centro de Precios.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$url_products = admin_url('admin.php?page=riverso-pos-products&from=precio-folio&need=sku');
$url_invoices = admin_url('admin.php?page=riverso-pos-invoices&from=precio-folio&need=sku');
$url_codes = admin_url('admin.php?page=riverso-pos-codes&from=precio-folio&need=sku');
$url_tasks = admin_url('admin.php?page=riverso-pos-tasks&from=precio-folio&need=familia');
?>
<div class="rpf-wrap">
    <div id="rpf-list-panel">
        <details class="rpf-guide" open>
            <summary class="rpf-guide-summary">
                <span class="dashicons dashicons-info-outline"></span>
                Antes de procesar — cadena de trabajo
            </summary>
            <div class="rpf-guide-body">
                <ol class="rpf-guide-steps">
                    <li>
                        <strong>El folio no se procesa</strong> si algún ítem no tiene SKU usable
                        (distinto del código proveedor) o si falta resolver familia.
                    </li>
                    <li>
                        <strong>SKU — primero buscar, después crear:</strong>
                        en el
                        <a href="<?php echo esc_url($url_products); ?>" target="_blank" rel="noopener">Hub de Productos</a>
                        buscá por código proveedor, nombre o SKU.
                        Si existe, vinculalo en la
                        <a href="<?php echo esc_url($url_invoices); ?>" target="_blank" rel="noopener">factura</a>
                        (la factura <em>no crea</em> productos).
                        Si no existe:
                        <ul>
                            <li><strong>Nuevo producto local</strong> — solo tienda física / TPV (desde el paso 3a, sin salir del folio).</li>
                            <li><strong>Crear/Vincular online</strong> — si ya está en WooCommerce o debe existir online.</li>
                        </ul>
                        Nunca uses el código del proveedor como SKU.
                        También podés revisar
                        <a href="<?php echo esc_url($url_codes); ?>" target="_blank" rel="noopener">Códigos</a>.
                    </li>
                    <li>
                        <strong>Familia:</strong> en la ficha del producto (tab Local) respondé
                        «¿Necesita familia?» o asigná una. Si no aplica packs, marcá
                        <code>no_requiere</code>. Seguimiento en
                        <a href="<?php echo esc_url($url_tasks); ?>" target="_blank" rel="noopener">Tareas</a>.
                    </li>
                    <li>
                        Volvé aquí y pulsá <strong>Actualizar</strong> (o «Ya resolví — actualizar»
                        dentro del folio) para que salga el ticket de error.
                    </li>
                </ol>
                <p class="rpf-guide-links description">
                    Atajos:
                    <a href="<?php echo esc_url($url_invoices); ?>" target="_blank" rel="noopener">Facturas</a> ·
                    <a href="<?php echo esc_url($url_products); ?>" target="_blank" rel="noopener">Productos</a> ·
                    <a href="<?php echo esc_url($url_codes); ?>" target="_blank" rel="noopener">Códigos</a> ·
                    <a href="<?php echo esc_url($url_tasks); ?>" target="_blank" rel="noopener">Tareas</a>
                </p>
            </div>
        </details>

        <div class="cost-filters rpf-list-filters">
            <div class="rpf-vista-nav" style="margin-bottom:10px;">
                <button type="button" class="button rpf-vista-btn is-active" data-vista="activos" id="rpf-vista-activos">Activos</button>
                <button type="button" class="button rpf-vista-btn" data-vista="archivados" id="rpf-vista-archivados">Archivados</button>
                <span id="rpf-archived-chip" class="rpf-count-chip" style="margin-left:8px;display:none;"></span>
            </div>
            <div class="filter-row">
                <div class="filter-group">
                    <label>Estado</label>
                    <select id="rpf-filter-estado">
                        <option value="">Todos</option>
                        <option value="pendiente">Pendiente de ingreso</option>
                        <option value="ingresando">Ingresando</option>
                        <option value="ingresada">Ingresada</option>
                        <option value="ingresada_manual">Ingresada anteriormente/manual</option>
                        <option value="anulada">Anulada</option>
                        <option value="con_error">Con Error</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Completitud</label>
                    <select id="rpf-filter-completitud">
                        <option value="">Todos</option>
                        <option value="completo">Completos</option>
                        <option value="parcial">Parciales</option>
                        <option value="ninguno">Ninguno</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Folio / proveedor</label>
                    <input type="text" id="rpf-filter-search" placeholder="Folio o proveedor">
                </div>
                <div class="filter-group">
                    <label>Producto</label>
                    <input type="text" id="rpf-filter-producto" placeholder="SKU, cód. proveedor, barras o nombre">
                </div>
                <div class="filter-group">
                    <label>Ordenar</label>
                    <select id="rpf-filter-order">
                        <option value="fecha_folio|DESC">Fecha folio ↓</option>
                        <option value="fecha_folio|ASC">Fecha folio ↑</option>
                        <option value="fecha_ingreso|DESC">Fecha ingreso ↓</option>
                        <option value="fecha_ingreso|ASC">Fecha ingreso ↑</option>
                    </select>
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="button" class="button button-primary" id="rpf-refresh">Actualizar</button>
                </div>
            </div>
            <div class="filter-row">
                <div class="filter-group">
                    <label>Fecha folio</label>
                    <div class="rpf-date-range">
                        <input type="date" id="rpf-filter-folio-desde" title="Desde">
                        <span class="description">–</span>
                        <input type="date" id="rpf-filter-folio-hasta" title="Hasta">
                    </div>
                </div>
                <div class="filter-group">
                    <label>Fecha ingreso</label>
                    <div class="rpf-date-range">
                        <input type="date" id="rpf-filter-ingreso-desde" title="Desde">
                        <span class="description">–</span>
                        <input type="date" id="rpf-filter-ingreso-hasta" title="Hasta">
                    </div>
                </div>
            </div>
        </div>
        <div id="rpf-counts" class="rpf-counts"></div>
        <table class="widefat striped" id="rpf-table">
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Fecha folio</th>
                    <th>Fecha ingreso</th>
                    <th>Proveedor</th>
                    <th>Estado</th>
                    <th>Completitud</th>
                    <th>Bloqueos</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="rpf-tbody">
                <tr><td colspan="9">Cargando…</td></tr>
            </tbody>
        </table>
        <div class="tablenav bottom">
            <button type="button" class="button" id="rpf-prev">Anterior</button>
            <span id="rpf-page-label" class="description" style="margin:0 8px;"></span>
            <button type="button" class="button" id="rpf-next">Siguiente</button>
        </div>
    </div>

    <div id="rpf-session-panel" style="display:none;">
        <p>
            <button type="button" class="button" id="rpf-back">← Volver a la lista</button>
            <button type="button" class="button" id="rpf-refresh-session" title="Reevaluar SKU/familia y precios">
                Ya resolví — actualizar
            </button>
            <button type="button" class="button button-primary" id="rpf-complete" style="display:none;">Marcar ingresada</button>
            <button type="button" class="button" id="rpf-archive-session" style="display:none;">Archivar</button>
        </p>
        <div id="rpf-session-header" class="rpf-session-header"></div>
        <div id="rpf-blockers" class="rpf-blockers" style="display:none;"></div>
        <div id="rpf-lines" style="overflow:auto;"></div>
    </div>
</div>

<div id="rpf-family-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop"></div>
    <div class="rpf-modal-card">
        <div class="rpf-modal-head">
            <h2 style="margin:0;">Familia — costos y regla</h2>
            <button type="button" class="button rpf-modal-close">Cerrar</button>
        </div>
        <div id="rpf-family-body" class="rpf-modal-body"></div>
    </div>
</div>

<div id="rpf-hybrid-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpf-hybrid-backdrop"></div>
    <div class="rpf-modal-card rpf-hybrid-card">
        <div class="rpf-modal-head">
            <h2 id="rpf-hybrid-title" style="margin:0;">Ingreso manual / híbrido</h2>
            <button type="button" class="button rpf-hybrid-close">Cerrar</button>
        </div>
        <div id="rpf-hybrid-body" class="rpf-modal-body"></div>
        <div id="rpf-hybrid-footer" class="rpf-hybrid-footer" style="display:none;"></div>
    </div>
</div>

<div id="rpf-create-local-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpf-create-local-backdrop"></div>
    <div class="rpf-modal-card rpf-create-local-card">
        <div class="rpf-modal-head">
            <h2 id="rpf-create-local-title" style="margin:0;">Nuevo producto local</h2>
            <button type="button" class="button rpf-create-local-close">Cerrar</button>
        </div>
        <div id="rpf-create-local-body" class="rpf-modal-body"></div>
        <div id="rpf-create-local-footer" class="rpf-create-local-footer"></div>
    </div>
</div>

<div id="rpf-answer-family-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpf-answer-family-backdrop"></div>
    <div class="rpf-modal-card rpf-answer-family-card">
        <div class="rpf-modal-head">
            <h2 id="rpf-answer-family-title" style="margin:0;">¿Necesita familia?</h2>
            <button type="button" class="button rpf-answer-family-close">Cerrar</button>
        </div>
        <div id="rpf-answer-family-body" class="rpf-modal-body"></div>
        <div id="rpf-answer-family-footer" class="rpf-create-local-footer"></div>
    </div>
</div>

<div id="rpf-comp-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpf-comp-backdrop"></div>
    <div class="rpf-modal-card rpf-comp-card">
        <div class="rpf-modal-head">
            <h2 id="rpf-comp-title" style="margin:0;">Competencia</h2>
            <div class="rpf-comp-head-actions">
                <a class="button button-small" id="rpf-comp-admin-link" href="#" target="_blank" rel="noopener">Abrir Competencia</a>
                <button type="button" class="button rpf-comp-close">Cerrar</button>
            </div>
        </div>
        <div class="rpf-modal-body">
            <p id="rpf-comp-product-label" class="description" style="margin-top:0;"></p>
            <p id="rpf-comp-loading" class="description" hidden>Cargando rivales…</p>
            <p id="rpf-comp-empty" class="description" hidden>Sin mapeos ni sugerencias para este SKU.</p>

            <div id="rpf-comp-mapeados-wrap" hidden>
                <h4 class="rpf-comp-subtitle">Mapeados</h4>
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
                    <tbody id="rpf-comp-mapeados-body"></tbody>
                </table>
            </div>

            <div id="rpf-comp-sugeridos-wrap" hidden>
                <h4 class="rpf-comp-subtitle">
                    Sugeridos
                    <span class="description" style="font-weight:normal;"> — confirmar en Competencia</span>
                </h4>
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
                    <tbody id="rpf-comp-sugeridos-body"></tbody>
                </table>
            </div>

            <div id="rpf-comp-manual-wrap" class="rpf-comp-manual" hidden>
                <h4 class="rpf-comp-subtitle">Agregar manual</h4>
                <p class="description" style="margin-top:0;">
                    Pegá la URL del competidor y el precio. La fuente se detecta por el dominio (Sande, DIMAFI u otra → Manual).
                </p>
                <div id="rpf-comp-form-msg" class="rpf-comp-form-msg" style="display:none;"></div>
                <div class="rpf-comp-form-grid">
                    <label class="rpf-comp-form-full">
                        URL del competidor
                        <input type="url" id="rpf-comp-url" class="regular-text" style="width:100%;" placeholder="https://…">
                    </label>
                    <label>
                        Precio total (bruto)
                        <input type="number" id="rpf-comp-precio" class="regular-text" style="width:100%;" min="0" step="1" placeholder="0">
                    </label>
                    <label>
                        Unidad (cantidad mín.)
                        <input type="number" id="rpf-comp-unidad" class="regular-text" style="width:100%;" min="1" step="1" value="1">
                    </label>
                    <label class="rpf-comp-form-full">
                        Tipo de match <span style="color:#d63638;">*</span>
                        <select id="rpf-comp-tipo-match" style="width:100%;">
                            <option value="">— seleccionar —</option>
                            <option value="exacto">Exacto (mismo producto)</option>
                            <option value="exacto_envase">Exacto diferente U de envase</option>
                            <option value="similar">Similar (equivalente funcional)</option>
                            <option value="otro">Otro</option>
                        </select>
                        <div id="rpf-comp-tipo-warnings" style="display:none;margin-top:8px;"></div>
                    </label>
                    <label class="rpf-comp-form-full">
                        Nota (opcional)
                        <textarea id="rpf-comp-nota" class="large-text" rows="2" style="width:100%;"></textarea>
                    </label>
                </div>
                <p class="rpf-comp-form-actions">
                    <button type="button" class="button button-primary" id="rpf-comp-save">Guardar y confirmar</button>
                    <button type="button" class="button" id="rpf-comp-google">Buscar en Google</button>
                </p>
            </div>
        </div>
    </div>
</div>

<div id="rpf-bc-modal" class="rpf-modal" style="display:none;" aria-hidden="true">
    <div class="rpf-modal-backdrop rpf-bc-backdrop"></div>
    <div class="rpf-modal-card rpf-comp-card">
        <div class="rpf-modal-head">
            <h2 id="rpf-bc-title" style="margin:0;">Barcodes</h2>
            <div class="rpf-comp-head-actions">
                <a class="button button-small" id="rpf-bc-product-link" href="#" target="_blank" rel="noopener">Abrir producto</a>
                <button type="button" class="button rpf-bc-close">Cerrar</button>
            </div>
        </div>
        <div class="rpf-modal-body">
            <p id="rpf-bc-product-label" class="description" style="margin-top:0;"></p>
            <p id="rpf-bc-loading" class="description" hidden>Cargando códigos…</p>
            <p id="rpf-bc-empty" class="description" hidden>Sin códigos de barra para este SKU.</p>
            <p id="rpf-bc-denied" class="description" hidden>
                No tenés permiso para ver barcodes aquí.
                Usá <a id="rpf-bc-denied-link" href="#" target="_blank" rel="noopener">Productos</a>.
            </p>

            <div id="rpf-bc-list-wrap" hidden>
                <h4 class="rpf-comp-subtitle">Códigos asociados</h4>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Tipo</th>
                            <th>Cantidad</th>
                            <th>Estado</th>
                            <th>Origen</th>
                        </tr>
                    </thead>
                    <tbody id="rpf-bc-list-body"></tbody>
                </table>
            </div>

            <div id="rpf-bc-form-wrap" class="rpf-comp-manual" hidden>
                <h4 class="rpf-comp-subtitle">Agregar código de barra</h4>
                <div id="rpf-bc-form-msg" class="rpf-comp-form-msg" style="display:none;"></div>
                <div class="rpf-comp-form-grid">
                    <label>
                        Tipo de código
                        <select id="rpf-bc-type" style="width:100%;">
                            <option value="ean13">EAN-13</option>
                            <option value="supplier">Código de Proveedor</option>
                            <option value="internal">Interno</option>
                        </select>
                    </label>
                    <label>
                        Código
                        <input type="text" id="rpf-bc-code" class="regular-text" style="width:100%;" placeholder="Ingrese código de barra" autocomplete="off">
                    </label>
                    <label id="rpf-bc-supplier-wrap" class="rpf-comp-form-full" hidden>
                        Proveedor (si aplica)
                        <select id="rpf-bc-proveedor" style="width:100%;">
                            <option value="">— Seleccione proveedor —</option>
                        </select>
                    </label>
                    <label>
                        Cantidad
                        <input type="number" id="rpf-bc-cantidad" class="regular-text" style="width:100%;" min="0" step="0.01" value="1">
                    </label>
                    <label>
                        Unidad
                        <select id="rpf-bc-unidad" style="width:100%;">
                            <option value="unidad">Unidad</option>
                            <option value="caja">Caja</option>
                            <option value="pallet">Pallet</option>
                            <option value="kg">Kilogramo</option>
                            <option value="lt">Litro</option>
                        </select>
                    </label>
                    <label>
                        Origen
                        <select id="rpf-bc-origen" style="width:100%;">
                            <option value="manual">Manual</option>
                            <option value="proveedor">Proveedor</option>
                            <option value="import">Importado</option>
                        </select>
                    </label>
                    <label class="rpf-comp-form-full">
                        Motivo (opcional)
                        <textarea id="rpf-bc-reason" class="large-text" rows="2" style="width:100%;" placeholder="Motivo auditoría o comentario"></textarea>
                    </label>
                </div>
                <p class="rpf-comp-form-actions">
                    <button type="button" class="button button-primary" id="rpf-bc-save">Agregar código de barra</button>
                </p>
            </div>
        </div>
    </div>
</div>
