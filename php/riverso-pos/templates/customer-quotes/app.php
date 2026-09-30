<?php
/**
 * Portal de cotizaciones de venta (es-CL). P0–P5a/P5b.
 *
 * @var array<string, mixed> $riverso_cq
 */
if (!defined('ABSPATH') && empty($riverso_cq['standalone'])) {
    // En WP el módulo define ABSPATH; en standalone se permite.
}

$standalone = !empty($riverso_cq['standalone']);
$asset_base = rtrim((string) (isset($riverso_cq['assetBase']) ? $riverso_cq['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';

if (!function_exists('riverso_pos_cq_json')) {
    function riverso_pos_cq_json($data) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (function_exists('wp_json_encode')) {
            $json = wp_json_encode($data, $flags);
            return is_string($json) ? $json : '{}';
        }
        $json = json_encode($data, $flags);
        return is_string($json) ? $json : '{}';
    }
}
?>
<?php if ($standalone): ?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cotizaciones de venta</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="riverso-cq-body">
<?php else: ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
<div class="wrap riverso-cq-wrap">
    <div id="riverso-cq" class="riverso-cq" data-ready="0">
        <section id="cq-list-view" class="cq-view" aria-labelledby="cq-list-title">
            <header class="cq-top">
                <div>
                    <h1 id="cq-list-title">Cotizaciones de venta</h1>
                    <p class="cq-lead">Borrador, lista y facturada.</p>
                </div>
                <button type="button" class="cq-btn cq-btn-primary" id="cq-new">Nueva cotización</button>
            </header>
            <div class="cq-toolbar">
                <label for="cq-status-filter">Estado</label>
                <select id="cq-status-filter">
                    <option value="all">Todas</option>
                    <option value="draft">Borrador</option>
                    <option value="listed">Lista</option>
                    <option value="invoiced">Facturada</option>
                </select>
                <label for="cq-type-filter">Tipo</label>
                <select id="cq-type-filter">
                    <option value="all">Todos</option>
                    <option value="venta">Venta</option>
                    <option value="referencia">Referencia</option>
                </select>
                <label for="cq-date-from">Desde</label>
                <input type="date" id="cq-date-from" autocomplete="off">
                <label for="cq-date-to">Hasta</label>
                <input type="date" id="cq-date-to" autocomplete="off">
                <button type="button" class="cq-btn" id="cq-apply-filters">Filtrar</button>
            </div>
            <div class="cq-table-wrap">
                <table class="cq-table">
                    <thead>
                        <tr>
                            <th scope="col">Número</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Cliente</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="cq-num">Neto</th>
                            <th scope="col" class="cq-num">Utilidad %</th>
                            <th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="cq-list-body"></tbody>
                </table>
            </div>
            <p id="cq-empty" class="cq-empty" hidden>No hay cotizaciones. Crea la primera.</p>
        </section>

        <section id="cq-editor-view" class="cq-view" hidden aria-labelledby="cq-editor-title">
            <header class="cq-top">
                <div>
                    <button type="button" class="cq-link" id="cq-back">← Cotizaciones</button>
                    <h1 id="cq-editor-title">Nueva cotización</h1>
                </div>
                <div class="cq-top-actions">
                    <span id="cq-status" class="cq-badge cq-badge-draft">Borrador</span>
                    <span id="cq-expired-badge" class="cq-expired-tag" hidden>Vencida</span>
                    <button type="button" class="cq-btn" id="cq-transition" hidden>Pasar a lista</button>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-invoice" hidden>Facturar</button>
                    <a id="cq-order-link" class="cq-link cq-order-link" href="#" target="_blank" rel="noopener" hidden>Ver pedido</a>
                    <button type="button" class="cq-btn" id="cq-import" hidden title="Importar desde cotización recibida">Importar</button>
                    <button type="button" class="cq-btn" id="cq-pdf" title="PDF (próximamente)">PDF</button>
                    <button type="button" class="cq-btn" id="cq-options" title="Opciones (próximamente)">Opciones</button>
                </div>
            </header>

            <div class="cq-card">
                <div class="cq-header-grid">
                    <label class="cq-field">
                        <span>Nº cotización</span>
                        <input type="text" id="cq-quote-number" readonly tabindex="-1" placeholder="Se asigna al guardar">
                    </label>
                    <label class="cq-field">
                        <span>Fecha emisión</span>
                        <input type="text" id="cq-issue-date" readonly tabindex="-1" placeholder="—">
                    </label>
                    <label class="cq-field">
                        <span>Vendedor</span>
                        <input type="text" id="cq-seller" readonly tabindex="-1" placeholder="—">
                    </label>
                    <label class="cq-field">
                        <span>Cliente <small>(opcional)</small></span>
                        <input type="text" id="cq-customer" maxlength="191" autocomplete="off" placeholder="Nombre del cliente">
                    </label>
                    <label class="cq-field">
                        <span>Tipo</span>
                        <select id="cq-type">
                            <option value="venta">Venta</option>
                            <option value="referencia">Referencia</option>
                        </select>
                    </label>
                    <div class="cq-field cq-channel-field">
                        <span>Canal</span>
                        <div class="cq-channel-toggle" role="group" aria-label="Canal Local u Online">
                            <button type="button" class="cq-channel-btn is-active" data-channel="local" id="cq-channel-local">Local</button>
                            <button type="button" class="cq-channel-btn" data-channel="online" id="cq-channel-online">Online</button>
                        </div>
                        <input type="hidden" id="cq-channel" value="local">
                    </div>
                    <label class="cq-field">
                        <span>Validez (días)</span>
                        <input type="number" id="cq-validity-days" min="0" max="3650" step="1" inputmode="numeric" placeholder="Opcional">
                    </label>
                    <label class="cq-field cq-field-wide">
                        <span>Condiciones de validez</span>
                        <input type="text" id="cq-validity-terms" maxlength="2000" placeholder="Opcional">
                    </label>
                </div>
                <dl class="cq-totals">
                    <div><dt>Neto</dt><dd id="cq-total-net">$0</dd></div>
                    <div><dt>Descuentos</dt><dd id="cq-total-discount">$0</dd></div>
                    <div><dt>Margen</dt><dd id="cq-total-margin">—</dd></div>
                    <div><dt>Utilidad</dt><dd id="cq-total-profit">—</dd></div>
                </dl>
            </div>

            <div class="cq-card">
                <div class="cq-search">
                    <div class="cq-search-head">
                        <label for="cq-search">Buscar producto</label>
                        <label class="cq-advanced-toggle" for="cq-advanced">
                            <input type="checkbox" id="cq-advanced">
                            <span>Modo avanzado</span>
                        </label>
                    </div>
                    <div class="cq-search-row">
                        <input type="search" id="cq-search" autocomplete="off" placeholder="SKU, barcode o código proveedor (según canal)" enterkeyhint="search">
                        <button type="button" class="cq-btn" id="cq-search-btn">Buscar</button>
                        <button type="button" class="cq-btn cq-btn-lupa" id="cq-lupa" title="Búsqueda avanzada" aria-label="Abrir búsqueda avanzada">
                            <span aria-hidden="true">🔍</span>
                        </button>
                    </div>
                    <ul id="cq-results" class="cq-results" hidden></ul>
                </div>

                <div id="cq-advanced-modal" class="cq-modal" hidden aria-hidden="true">
                    <div class="cq-modal-backdrop" data-cq-modal-close="1"></div>
                    <div class="cq-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-modal-title">
                        <header class="cq-modal-head">
                            <h3 id="cq-modal-title">Búsqueda avanzada</h3>
                            <button type="button" class="cq-modal-close" id="cq-modal-close" aria-label="Cerrar" data-cq-modal-close="1">×</button>
                        </header>
                        <div class="cq-modal-body">
                            <div class="cq-scope-chips" role="tablist" aria-label="Alcance de búsqueda">
                                <button type="button" class="cq-chip is-active" data-scope="todo" role="tab" aria-selected="true">Todo</button>
                                <button type="button" class="cq-chip" data-scope="descripcion" role="tab" aria-selected="false">Descripción</button>
                                <button type="button" class="cq-chip" data-scope="codigos" role="tab" aria-selected="false">Códigos</button>
                            </div>
                            <div class="cq-search-row">
                                <input type="search" id="cq-modal-q" autocomplete="off" placeholder="Buscar…" enterkeyhint="search">
                                <button type="button" class="cq-btn cq-btn-primary" id="cq-modal-search-btn">Buscar</button>
                            </div>
                            <p id="cq-modal-hint" class="cq-modal-hint" role="status"></p>
                            <ul id="cq-modal-results" class="cq-results cq-modal-results"></ul>
                        </div>
                    </div>
                </div>
                <div class="cq-table-wrap">
                    <table class="cq-table">
                        <thead>
                            <tr>
                                <th scope="col">Detalle</th>
                                <th scope="col" class="cq-num">Cantidad</th>
                                <th scope="col" class="cq-num">Precio</th>
                                <th scope="col" class="cq-num cq-advanced" title="Porcentaje de descuento sobre el precio de la línea">Dscto precio</th>
                                <th scope="col" class="cq-num cq-advanced" title="Porcentaje del margen que queda después del descuento de precio">Dscto margen</th>
                                <th scope="col" class="cq-num cq-advanced">Utilidad</th>
                                <th scope="col" class="cq-num cq-advanced" title="Stock en bodega (live)">Stock</th>
                                <th scope="col" class="cq-advanced" title="Confianza del inventario">Confianza</th>
                                <th scope="col" class="cq-advanced">Inventariar</th>
                                <th scope="col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="cq-lines"></tbody>
                    </table>
                </div>
                <p id="cq-lines-empty" class="cq-empty">Agrega productos con la búsqueda por SKU, código de proveedor o código de barras.</p>
            </div>

            <footer class="cq-footer">
                <p id="cq-message" class="cq-message" role="status"></p>
                <div class="cq-footer-actions">
                    <button type="button" class="cq-btn" id="cq-clear">Limpiar</button>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-save">Guardar</button>
                </div>
            </footer>
        </section>
        <div id="cq-import-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-import-close="1"></div>
            <div class="cq-modal-dialog cq-modal-wide" role="dialog" aria-modal="true" aria-labelledby="cq-import-title">
                <header class="cq-modal-head">
                    <h3 id="cq-import-title">Importar cotización recibida</h3>
                    <button type="button" class="cq-modal-close" id="cq-import-close" aria-label="Cerrar" data-cq-import-close="1">×</button>
                </header>
                <div class="cq-modal-body">
                    <div id="cq-import-step-list">
                        <p class="cq-modal-hint">Cotizaciones recibidas confirmadas (versión final del grupo).</p>
                        <ul id="cq-import-quote-list" class="cq-results cq-modal-results"></ul>
                        <p id="cq-import-list-empty" class="cq-empty" hidden>No hay cotizaciones recibidas confirmadas.</p>
                    </div>
                    <div id="cq-import-step-preview" hidden>
                        <p id="cq-import-preview-head" class="cq-modal-hint"></p>
                        <p id="cq-import-skipped" class="cq-modal-hint"></p>
                        <div class="cq-table-wrap">
                            <table class="cq-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><input type="checkbox" id="cq-import-check-all" title="Seleccionar todas"></th>
                                        <th scope="col">SKU</th>
                                        <th scope="col">Descripción</th>
                                        <th scope="col" class="cq-num">Cant.</th>
                                        <th scope="col" class="cq-num">Costo</th>
                                        <th scope="col" class="cq-num">P. catálogo</th>
                                    </tr>
                                </thead>
                                <tbody id="cq-import-lines"></tbody>
                            </table>
                        </div>
                        <div class="cq-modal-actions">
                            <button type="button" class="cq-btn" id="cq-import-back">← Volver</button>
                            <button type="button" class="cq-btn cq-btn-primary" id="cq-import-confirm">Agregar seleccionadas</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div id="cq-family-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-family-close="1"></div>
            <div class="cq-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-family-title">
                <header class="cq-modal-head">
                    <h3 id="cq-family-title">Cambiar presentación</h3>
                    <button type="button" class="cq-modal-close" id="cq-family-close" aria-label="Cerrar" data-cq-family-close="1">×</button>
                </header>
                <div class="cq-modal-body">
                    <p id="cq-family-hint" class="cq-modal-hint" role="status"></p>
                    <ul id="cq-family-members" class="cq-results cq-modal-results"></ul>
                </div>
            </div>
        </div>
        <p id="cq-list-message" class="cq-message" role="status"></p>
    </div>
</div>
<script>window.RIVERSO_CQ = <?php echo riverso_pos_cq_json($riverso_cq); ?>;</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customer-quotes.js?ver=<?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($standalone): ?>
</body>
</html>
<?php endif; ?>
