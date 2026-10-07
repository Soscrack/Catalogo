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
$surface = (isset($riverso_cq['surface']) && $riverso_cq['surface'] === 'portal') ? 'portal' : 'admin';
$asset_base = rtrim((string) (isset($riverso_cq['assetBase']) ? $riverso_cq['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
// Bustear caché del navegador cuando cambian JS/CSS sin depender solo del número de plugin.
$js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/customer-quotes.js';
$css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/customer-quotes.css';
$css_portal_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/customer-quotes-portal.css';
$css_customers_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/customers.css';
$js_ver = (is_string($js_path) && is_file($js_path)) ? (string) filemtime($js_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;
$css_portal_ver = (is_string($css_portal_path) && is_file($css_portal_path)) ? (string) filemtime($css_portal_path) : $version;
$css_customers_ver = (is_string($css_customers_path) && is_file($css_customers_path)) ? (string) filemtime($css_customers_path) : $version;
$cq_comunas = (isset($riverso_cq['comunas']) && is_array($riverso_cq['comunas'])) ? $riverso_cq['comunas'] : [];

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
    <link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customers.css?ver=<?php echo htmlspecialchars($css_customers_ver, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="riverso-cq-body">
<?php else: ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customers.css?ver=<?php echo htmlspecialchars($css_customers_ver, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($surface === 'portal'): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes-portal.css?ver=<?php echo htmlspecialchars($css_portal_ver, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
<?php endif; ?>
<div class="wrap riverso-cq-wrap<?php echo $surface === 'portal' ? ' riverso-cq-wrap--portal' : ''; ?>">
    <div id="riverso-cq" class="riverso-cq<?php echo $surface === 'portal' ? ' riverso-cq--portal' : ''; ?>" data-ready="0" data-surface="<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>">
        <section id="cq-list-view" class="cq-view" aria-labelledby="cq-list-title">
            <header class="cq-top">
                <div>
                    <h1 id="cq-list-title">Buscar Cotizaciones</h1>
                    <p class="cq-lead">Filtra cotizaciones de venta y ábrelas en esta ventana o en una nueva.</p>
                </div>
                <button type="button" class="cq-btn cq-btn-primary" id="cq-new">Nueva cotización</button>
            </header>

            <div class="cq-search-card" id="cq-search-form">
                <h2 class="cq-search-card-title">BUSCAR COTIZACIONES</h2>
                <div class="cq-search-grid">
                    <label class="cq-field">
                        <span>Número Cotización</span>
                        <input type="text" id="cq-filter-number" autocomplete="off">
                    </label>
                    <label class="cq-field">
                        <span>Nombre Cliente</span>
                        <input type="text" id="cq-filter-customer" autocomplete="off">
                    </label>
                    <label class="cq-field cq-field-disabled" title="Aún no se captura email en cotizaciones">
                        <span>Email Cliente</span>
                        <input type="email" id="cq-filter-email" disabled placeholder="Próximamente">
                    </label>
                    <label class="cq-field cq-field-disabled" title="Aún no se captura teléfono en cotizaciones">
                        <span>Fono Cliente</span>
                        <input type="text" id="cq-filter-phone" disabled placeholder="Próximamente">
                    </label>

                    <label class="cq-field">
                        <span>Estado Cotización</span>
                        <select id="cq-status-filter">
                            <option value="all">** Todas **</option>
                            <option value="draft">Borrador</option>
                            <option value="listed">Aprobada</option>
                            <option value="invoiced">Facturada</option>
                            <option value="rejected">Rechazada</option>
                            <option value="cancelled">Anulada</option>
                        </select>
                    </label>
                    <label class="cq-field" title="Según los documentos de venta y pagos registrados en Facturación">
                        <span>Estado de Venta</span>
                        <select id="cq-sale-status-filter">
                            <option value="all">** Todas **</option>
                            <option value="none">Sin documento</option>
                            <option value="billing">En facturación</option>
                            <option value="unpaid">Por cobrar</option>
                            <option value="partial">Pago parcial</option>
                            <option value="paid">Pagada</option>
                        </select>
                    </label>
                    <label class="cq-field cq-field-wide">
                        <span>Responsable</span>
                        <select id="cq-filter-responsable">
                            <option value="0">** Cualquier Responsable **</option>
                        </select>
                    </label>

                    <label class="cq-field">
                        <span>Fecha desde</span>
                        <input type="date" id="cq-date-from" autocomplete="off">
                    </label>
                    <label class="cq-field">
                        <span>Fecha hasta</span>
                        <input type="date" id="cq-date-to" autocomplete="off">
                    </label>
                    <label class="cq-field cq-field-disabled" title="Fecha de cierre aún no modelada">
                        <span>Cierre desde</span>
                        <input type="date" id="cq-close-from" disabled>
                    </label>
                    <label class="cq-field cq-field-disabled" title="Fecha de cierre aún no modelada">
                        <span>Cierre hasta</span>
                        <input type="date" id="cq-close-to" disabled>
                    </label>
                </div>
                <div class="cq-search-footer">
                    <div class="cq-search-checks">
                        <label class="cq-check">
                            <input type="checkbox" id="cq-include-quotes" checked>
                            <span>Cotizaciones</span>
                        </label>
                        <label class="cq-check cq-field-disabled" title="Pedidos POS no se listan en este buscador">
                            <input type="checkbox" id="cq-include-pos" disabled>
                            <span>Pedidos POS</span>
                        </label>
                    </div>
                    <button type="button" class="cq-btn cq-btn-search" id="cq-apply-filters">Buscar</button>
                </div>
            </div>

            <div class="cq-results-panel" id="cq-results-panel" hidden>
                <div class="cq-results-head">
                    <h2 class="cq-results-title">RESULTADO DE LA BÚSQUEDA</h2>
                    <div class="cq-results-meta">
                        <span id="cq-results-count" class="cq-results-count">Cotizaciones mostradas: 0</span>
                        <div class="cq-order-wrap">
                            <button type="button" class="cq-btn cq-btn-order" id="cq-order-btn" aria-haspopup="true" aria-expanded="false">
                                Ordenar: Fecha ↓
                            </button>
                            <div class="cq-order-menu" id="cq-order-menu" hidden role="menu">
                                <button type="button" role="menuitem" data-order-by="date" data-order-dir="DESC">Fecha descendente</button>
                                <button type="button" role="menuitem" data-order-by="date" data-order-dir="ASC">Fecha ascendente</button>
                                <button type="button" role="menuitem" data-order-by="number" data-order-dir="ASC">Número</button>
                                <button type="button" role="menuitem" data-order-by="customer" data-order-dir="ASC">Cliente</button>
                                <button type="button" role="menuitem" data-order-by="amount" data-order-dir="DESC">Monto</button>
                                <button type="button" role="menuitem" data-order-by="status" data-order-dir="ASC">Estado</button>
                            </div>
                        </div>
                        <button type="button" class="cq-btn cq-btn-export" id="cq-export-excel" title="Descargar CSV (abre en Excel)">
                            Exportar excel
                        </button>
                    </div>
                </div>
                <div class="cq-results-toolbar">
                    <label class="cq-page-size">
                        Mostrar
                        <select id="cq-page-size">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        registros
                    </label>
                    <label class="cq-table-search">
                        Buscar:
                        <input type="search" id="cq-table-search" autocomplete="off">
                    </label>
                </div>
                <div class="cq-table-wrap">
                    <table class="cq-table cq-results-table">
                        <thead>
                            <tr>
                                <th scope="col" class="cq-col-expand"></th>
                                <th scope="col">Número cotización</th>
                                <th scope="col">Fecha creación</th>
                                <th scope="col">Cliente Nombre</th>
                                <th scope="col">Responsable</th>
                                <th scope="col">Estado Cotización</th>
                                <th scope="col">Estado de Venta</th>
                                <th scope="col">DTE's Folios Asociados</th>
                                <th scope="col" class="cq-num">Monto</th>
                                <th scope="col">Ver cotización</th>
                            </tr>
                        </thead>
                        <tbody id="cq-list-body"></tbody>
                    </table>
                </div>
                <div class="cq-pager" id="cq-pager">
                    <button type="button" class="cq-btn" id="cq-page-prev">Anterior</button>
                    <span id="cq-page-numbers" class="cq-page-numbers"></span>
                    <button type="button" class="cq-btn" id="cq-page-next">Siguiente</button>
                </div>
            </div>
            <p id="cq-empty" class="cq-empty" hidden>No hay cotizaciones con esos filtros.</p>
            <!-- Compat: filtros legacy ocultos -->
            <select id="cq-type-filter" hidden aria-hidden="true">
                <option value="all" selected>Todos</option>
            </select>
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
                    <span id="cq-transitions" class="cq-transitions"></span>
                    <button type="button" class="cq-btn cq-btn-danger" id="cq-delete" hidden>Borrar</button>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-invoice" hidden>Facturar</button>
                    <a id="cq-order-link" class="cq-link cq-order-link" href="#" target="_blank" rel="noopener" hidden>Ver pedido</a>
                    <button type="button" class="cq-btn" id="cq-import" hidden title="Importar desde cotización recibida">Importar</button>
                    <label class="cq-pdf-template" title="Plantilla del PDF">
                        <select id="cq-pdf-template" aria-label="Plantilla PDF">
                            <option value="family">PDF: por familia</option>
                            <option value="product">PDF: por producto</option>
                        </select>
                    </label>
                    <button type="button" class="cq-btn" id="cq-pdf" title="Abrir cotización en PDF">PDF</button>
                    <button type="button" class="cq-btn" id="cq-options" title="Opciones (próximamente)">Opciones</button>
                </div>
            </header>

            <div class="cq-card">
                <div class="cq-header-grid">
                    <label class="cq-field">
                        <span>Nº cotización</span>
                        <input type="text" id="cq-quote-number" readonly tabindex="-1" placeholder="Reservando…">
                    </label>
                    <label class="cq-field">
                        <span>Fecha emisión</span>
                        <input type="date" id="cq-issue-date" autocomplete="off">
                    </label>
                    <label class="cq-field">
                        <span>Vendedor</span>
                        <input type="text" id="cq-seller" readonly tabindex="-1" placeholder="—">
                    </label>
                    <div class="cq-field cq-customer-field">
                        <span>Cliente registrado <small>(opcional)</small></span>
                        <div class="cq-customer-row">
                            <input type="text" id="cq-customer" maxlength="191" autocomplete="off" placeholder="Nombre del cliente">
                            <button type="button" class="cq-btn cq-btn-customer-action" id="cq-customer-search" title="Buscar cliente" aria-label="Buscar cliente">
                                <span aria-hidden="true">🔍</span>
                            </button>
                            <button type="button" class="cq-btn cq-btn-customer-action" id="cq-customer-new" title="Crear cliente" aria-label="Crear cliente">
                                <span aria-hidden="true">+</span>
                            </button>
                        </div>
                        <button type="button" class="cq-link cq-customer-clear" id="cq-customer-clear" hidden>Quitar cliente</button>
                    </div>
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
                    <div>
                        <dt>Bruto</dt>
                        <dd id="cq-total-net">$0</dd>
                        <dd id="cq-total-neto-hint" class="cq-total-neto-hint">(Neto: $0)</dd>
                    </div>
                    <div><dt>Descuentos</dt><dd id="cq-total-discount">$0</dd></div>
                    <div><dt>Margen</dt><dd id="cq-total-margin">—</dd></div>
                    <div>
                        <dt>Utilidad</dt>
                        <dd id="cq-total-profit">—</dd>
                        <dd id="cq-total-profit-neto-hint" class="cq-total-neto-hint" hidden>(Neto: —)</dd>
                    </div>
                </dl>
            </div>

            <div class="cq-card" id="cq-associated-card" hidden>
                <h2 class="cq-associated-title">DOCUMENTOS ASOCIADOS</h2>
                <ul class="cq-associated-list" id="cq-associated-list"></ul>
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
                        <button type="button" class="cq-btn cq-btn-manual" id="cq-manual" title="Agregar producto sin SKU">Manual</button>
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
                            <div class="cq-contains-filter">
                                <label class="cq-contains-label" for="cq-modal-contains">Contiene palabra:</label>
                                <div class="cq-contains-row">
                                    <input type="text" id="cq-modal-contains" autocomplete="off" placeholder="Escribe y Enter…" enterkeyhint="done">
                                    <button type="button" class="cq-btn" id="cq-modal-contains-add">Agregar</button>
                                </div>
                                <div id="cq-modal-contains-tags" class="cq-contains-tags" aria-live="polite"></div>
                            </div>
                            <div class="cq-search-row">
                                <input type="search" id="cq-modal-q" autocomplete="off" placeholder="Buscar…" enterkeyhint="search">
                                <button type="button" class="cq-btn cq-btn-primary" id="cq-modal-search-btn">Buscar</button>
                            </div>
                            <p id="cq-modal-hint" class="cq-modal-hint" role="status"></p>
                            <ul id="cq-modal-results" class="cq-results cq-modal-results"></ul>
                            <div class="cq-pager cq-modal-pager" id="cq-modal-pager" hidden>
                                <button type="button" class="cq-btn" id="cq-modal-page-prev">Anterior</button>
                                <span id="cq-modal-page-numbers" class="cq-page-numbers"></span>
                                <button type="button" class="cq-btn" id="cq-modal-page-next">Siguiente</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="cq-manual-modal" class="cq-modal" hidden aria-hidden="true">
                    <div class="cq-modal-backdrop" data-cq-manual-close="1"></div>
                    <div class="cq-modal-dialog cq-manual-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-manual-title">
                        <header class="cq-modal-head">
                            <h3 id="cq-manual-title">Agregar detalle</h3>
                            <button type="button" class="cq-modal-close" id="cq-manual-close" aria-label="Cerrar" data-cq-manual-close="1">×</button>
                        </header>
                        <div class="cq-modal-body cq-manual-modal-body">
                            <label class="cq-float-field cq-manual-full">
                                <span>Afecto a IVA (*)</span>
                                <select id="cq-manual-iva" disabled>
                                    <option value="si" selected>SI</option>
                                </select>
                            </label>
                            <label class="cq-float-field cq-manual-full">
                                <span>Impuesto adicional</span>
                                <select id="cq-manual-extra-tax" disabled>
                                    <option value="" selected>** Ninguno **</option>
                                </select>
                            </label>
                            <div class="cq-manual-row">
                                <label class="cq-float-field">
                                    <span>Cantidad (*)</span>
                                    <input type="text" id="cq-manual-qty" inputmode="decimal" autocomplete="off" value="1">
                                </label>
                                <label class="cq-float-field">
                                    <span>Unidad</span>
                                    <select id="cq-manual-unit">
                                        <option value="">** Unidad (opcional) **</option>
                                        <option value="Unidad">Unidad</option>
                                        <option value="Kg">Kg</option>
                                        <option value="Metro">Metro</option>
                                        <option value="Hora">Hora</option>
                                        <option value="Servicio">Servicio</option>
                                        <option value="Caja">Caja</option>
                                        <option value="Pack">Pack</option>
                                    </select>
                                </label>
                            </div>
                            <div class="cq-manual-row cq-manual-concepto-row">
                                <label class="cq-float-field cq-manual-concepto-field">
                                    <span>Concepto (*)</span>
                                    <input type="text" id="cq-manual-concepto" autocomplete="off" maxlength="500">
                                </label>
                                <button type="button" class="cq-btn cq-btn-desc-larga" id="cq-manual-desc-toggle" title="Descripción larga" aria-expanded="false" aria-controls="cq-manual-desc-wrap">Desc. Larga</button>
                            </div>
                            <div id="cq-manual-desc-wrap" class="cq-manual-desc-wrap" hidden>
                                <label class="cq-float-field cq-manual-full">
                                    <span>Descripción larga</span>
                                    <textarea id="cq-manual-desc-larga" rows="3" maxlength="500"></textarea>
                                </label>
                            </div>
                            <label class="cq-float-field cq-manual-full">
                                <span>Precio unitario</span>
                                <input type="text" id="cq-manual-unit-neto" inputmode="decimal" autocomplete="off" value="0">
                            </label>
                            <label class="cq-float-field cq-manual-full">
                                <span>Precio unitario con impuestos</span>
                                <input type="text" id="cq-manual-unit-bruto" inputmode="decimal" autocomplete="off" value="0">
                            </label>
                            <div class="cq-manual-row">
                                <label class="cq-float-field">
                                    <span>Descuento/Recargo</span>
                                    <select id="cq-manual-adj-type">
                                        <option value="descuento" selected>Descuento</option>
                                        <option value="recargo">Recargo</option>
                                    </select>
                                </label>
                                <label class="cq-float-field cq-manual-pct-field">
                                    <span>Porcentaje</span>
                                    <div class="cq-manual-pct-wrap">
                                        <input type="text" id="cq-manual-pct" inputmode="decimal" autocomplete="off" value="0">
                                        <span class="cq-manual-pct-suffix" aria-hidden="true">%</span>
                                    </div>
                                </label>
                            </div>
                            <label class="cq-float-field cq-manual-full">
                                <span>Precio total</span>
                                <input type="text" id="cq-manual-total-neto" inputmode="decimal" autocomplete="off" value="0" readonly>
                            </label>
                            <label class="cq-float-field cq-manual-full">
                                <span>Precio total con impuestos</span>
                                <input type="text" id="cq-manual-total-bruto" inputmode="decimal" autocomplete="off" value="0" readonly>
                            </label>
                            <p id="cq-manual-hint" class="cq-modal-hint" role="status"></p>
                        </div>
                        <footer class="cq-modal-foot cq-manual-modal-foot">
                            <button type="button" class="cq-btn cq-btn-manual-add" id="cq-manual-add">Agregar</button>
                        </footer>
                    </div>
                </div>
                <div class="cq-table-wrap">
                    <table class="cq-table">
                        <thead>
                            <tr>
                                <th scope="col">Detalle</th>
                                <th scope="col" class="cq-num">Cantidad</th>
                                <th scope="col" class="cq-num">Total bruto</th>
                                <th scope="col" class="cq-num cq-advanced" title="Porcentaje de descuento sobre el bruto de la línea (equivalente al dscto margen)">Dscto precio</th>
                                <th scope="col" class="cq-num cq-advanced" title="Mismo descuento expresado como % del margen bruto − costo (equivalente al dscto precio)">Dscto margen</th>
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
                <p id="cq-lines-empty" class="cq-empty">Agrega productos con la búsqueda por SKU, código de proveedor o código de barras, o con el botón Manual.</p>
            </div>

            <footer class="cq-footer">
                <p id="cq-message" class="cq-message" role="status"></p>
                <div class="cq-footer-actions">
                    <span id="cq-save-status" class="cq-save-status" aria-live="polite"></span>
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
                <footer class="cq-modal-foot cq-family-modal-foot">
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-family-open-admin" disabled title="Abrir editor de familia en Categorías">
                        Abrir Familia
                    </button>
                </footer>
            </div>
        </div>
        <div id="cq-line-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-line-close="1"></div>
            <div class="cq-modal-dialog cq-line-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-line-title">
                <header class="cq-modal-head">
                    <div>
                        <h3 id="cq-line-title">Editar línea</h3>
                        <p id="cq-line-subtitle" class="cq-line-modal-sub"></p>
                    </div>
                    <button type="button" class="cq-modal-close" id="cq-line-close" aria-label="Cerrar" data-cq-line-close="1">×</button>
                </header>
                <div class="cq-modal-body cq-line-modal-body">
                    <label class="cq-float-field cq-line-desc-field" id="cq-line-desc-field">
                        <span>Nombre en documento</span>
                        <input type="text" id="cq-line-desc" maxlength="255" autocomplete="off">
                        <em class="cq-float-hint">Así aparecerá en el PDF y en el documento emitido</em>
                    </label>
                    <div class="cq-line-tax-toggle" role="group" aria-label="Visualización Neto o Bruto">
                        <button type="button" class="cq-tax-btn" id="cq-line-tax-neto" data-tax-view="neto">Neto</button>
                        <button type="button" class="cq-tax-btn is-active" id="cq-line-tax-bruto" data-tax-view="bruto">Bruto</button>
                    </div>
                    <p id="cq-line-family-note" class="cq-modal-hint" hidden></p>
                    <div class="cq-line-price-modes" id="cq-line-price-modes">
                        <label class="cq-line-mode-check" id="cq-line-mode-manual-wrap">
                            <input type="checkbox" id="cq-line-mode-manual">
                            <span>Precio manual</span>
                        </label>
                        <div class="cq-line-mode-radios" id="cq-line-mode-radios" hidden>
                            <label><input type="radio" name="cq-line-mode" value="auto"> <span id="cq-line-mode-auto-label">Usar regla</span></label>
                            <label id="cq-line-mode-std-wrap" hidden><input type="radio" name="cq-line-mode" value="std"> <span id="cq-line-mode-std-label">Regla estándar ferretería (R-1)</span></label>
                            <label><input type="radio" name="cq-line-mode" value="ref"> Cambiar precio de referencia (P)</label>
                            <label><input type="radio" name="cq-line-mode" value="manual"> Ignorar regla — precio final</label>
                        </div>
                    </div>
                    <p id="cq-line-rule-info" class="cq-line-rule-info" hidden></p>
                    <div class="cq-line-fields">
                        <label class="cq-float-field" id="cq-line-qty-field">
                            <span>Cantidad</span>
                            <input type="text" id="cq-line-qty" inputmode="decimal" autocomplete="off">
                            <em id="cq-line-qty-hint" class="cq-float-hint"></em>
                        </label>
                        <label class="cq-float-field" id="cq-line-pref-field" hidden>
                            <span>Precio de referencia (P)</span>
                            <input type="text" id="cq-line-pref" inputmode="decimal" autocomplete="off">
                            <em id="cq-line-pref-hint" class="cq-float-hint"></em>
                        </label>
                        <label class="cq-float-field" id="cq-line-unit-field">
                            <span id="cq-line-unit-label">Precio unitario</span>
                            <input type="text" id="cq-line-unit" inputmode="decimal" autocomplete="off">
                            <em id="cq-line-unit-hint" class="cq-float-hint"></em>
                        </label>
                        <label class="cq-float-field" id="cq-line-total-field">
                            <span id="cq-line-total-label">Precio total</span>
                            <input type="text" id="cq-line-total" inputmode="decimal" autocomplete="off">
                            <em id="cq-line-total-hint" class="cq-float-hint"></em>
                        </label>
                        <label class="cq-float-field">
                            <span>Descuento precio %</span>
                            <input type="text" id="cq-line-price-discount" inputmode="decimal" autocomplete="off">
                        </label>
                        <label class="cq-float-field" id="cq-line-margin-field">
                            <span>
                                Descuento margen %
                                <abbr id="cq-line-margin-help" class="cq-help-tip" title="Costo no encontrado" hidden>?</abbr>
                            </span>
                            <input type="text" id="cq-line-margin-discount" inputmode="decimal" autocomplete="off">
                            <em id="cq-line-margin-na" class="cq-margin-na-text" hidden>—</em>
                        </label>
                        <label class="cq-float-field">
                            <span>Monto de descuento</span>
                            <input type="text" id="cq-line-discount-amount" inputmode="decimal" autocomplete="off">
                        </label>
                        <label class="cq-float-field cq-manual-full">
                            <span>Monto final</span>
                            <input type="text" id="cq-line-final-amount" readonly tabindex="-1" autocomplete="off">
                        </label>
                    </div>
                    <div class="cq-line-by-amount" id="cq-line-by-amount">
                        <p class="cq-line-by-amount-row">
                            <strong>Por monto:</strong>
                            Monto $ <input type="text" id="cq-line-monto" class="cq-line-monto-input" inputmode="decimal" autocomplete="off" placeholder="1000">
                            <button type="button" class="cq-btn cq-btn-small" id="cq-line-by-amount-btn">¿Cuánto entregar?</button>
                        </p>
                        <p class="cq-modal-hint">Total deseado antes de descuentos. Calcula la cantidad a entregar con la regla activa.</p>
                        <div id="cq-line-by-amount-result" class="cq-line-by-amount-result"></div>
                    </div>
                    <p id="cq-line-discount-hint" class="cq-line-preview" role="status"></p>
                    <p id="cq-line-preview" class="cq-line-preview" role="status"></p>
                </div>
                <footer class="cq-modal-foot cq-line-modal-foot">
                    <button type="button" class="cq-btn" id="cq-line-cancel" data-cq-line-close="1">Cancelar</button>
                    <button type="button" class="cq-btn cq-btn-save-line" id="cq-line-save">Guardar cambios</button>
                </footer>
            </div>
        </div>

        <div id="cq-customer-search-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-cust-search-close="1"></div>
            <div class="cq-modal-dialog cq-customer-search-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-cust-search-title">
                <header class="cq-modal-head">
                    <h3 id="cq-cust-search-title">Buscar cliente</h3>
                    <button type="button" class="cq-modal-close" id="cq-cust-search-close" aria-label="Cerrar" data-cq-cust-search-close="1">×</button>
                </header>
                <div class="cq-modal-body">
                    <label class="cq-field cq-field-wide">
                        <span>Nombre Interno Entidad</span>
                        <input type="text" id="cq-cust-search-nombre" autocomplete="off" placeholder="Nombre Interno Entidad">
                    </label>
                    <h4 class="cq-cust-section-title">Facturación</h4>
                    <div class="cq-cust-search-grid">
                        <label class="cq-field">
                            <span>RUT</span>
                            <input type="text" id="cq-cust-search-rut" autocomplete="off" placeholder="RUT">
                        </label>
                        <label class="cq-field">
                            <span>Nombre o Razón Social</span>
                            <input type="text" id="cq-cust-search-razon" autocomplete="off" placeholder="Nombre o Razón Social">
                        </label>
                    </div>
                    <div class="cq-cust-search-actions">
                        <button type="button" class="cq-btn cq-btn-customer-search" id="cq-cust-search-btn">Buscar</button>
                    </div>
                    <p id="cq-cust-search-hint" class="cq-modal-hint">Por favor seleccione parámetros para realizar la búsqueda</p>
                    <div id="cq-cust-search-results-wrap" hidden>
                        <h4 class="cq-cust-section-title">Búsqueda de entidades</h4>
                        <label class="cq-cust-local-filter">
                            <span>Buscar:</span>
                            <input type="text" id="cq-cust-search-local" autocomplete="off">
                        </label>
                        <div class="cq-cust-table-wrap">
                            <table class="cq-cust-table" id="cq-cust-search-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>RUT</th>
                                        <th>Cliente</th>
                                        <th>Seleccionar</th>
                                    </tr>
                                </thead>
                                <tbody id="cq-cust-search-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="cq-customer-new-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-cust-new-close="1"></div>
            <div class="cq-modal-dialog cq-customer-new-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-cust-new-title">
                <header class="cq-modal-head">
                    <h3 id="cq-cust-new-title">Crear cliente</h3>
                    <button type="button" class="cq-modal-close" id="cq-cust-new-close" aria-label="Cerrar" data-cq-cust-new-close="1">×</button>
                </header>
                <div class="cq-modal-body cq-customer-new-body">
                    <form id="cq-cust-new-form" class="riverso-cust" novalidate>
                        <?php
                        $comunas = $cq_comunas;
                        $show_footer = false;
                        $can_edit = true;
                        $form_fields = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'templates/customers/form-fields.php';
                        if (is_string($form_fields) && is_file($form_fields)) {
                            include $form_fields;
                        }
                        ?>
                    </form>
                </div>
                <footer class="cq-modal-foot">
                    <button type="button" class="cq-btn" id="cq-cust-new-cancel" data-cq-cust-new-close="1">Cancelar</button>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-cust-new-save">Guardar cliente</button>
                </footer>
            </div>
        </div>

        <p id="cq-list-message" class="cq-message" role="status"></p>
    </div>
</div>
<script>window.RIVERSO_CQ = <?php echo riverso_pos_cq_json($riverso_cq); ?>;</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customer-quotes.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($standalone): ?>
</body>
</html>
<?php endif; ?>
