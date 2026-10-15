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
// Descuentos y «Otra regla» compartidos con facturación.
$line_discounts_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/line-discounts.js';
$line_discounts_ver = (is_string($line_discounts_path) && is_file($line_discounts_path)) ? (string) filemtime($line_discounts_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;
$css_portal_ver = (is_string($css_portal_path) && is_file($css_portal_path)) ? (string) filemtime($css_portal_path) : $version;
$css_customers_ver = (is_string($css_customers_path) && is_file($css_customers_path)) ? (string) filemtime($css_customers_path) : $version;
$scanner_js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/barcode-scanner.js';
$scanner_css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/barcode-scanner.css';
$scanner_js_ver = (is_string($scanner_js_path) && is_file($scanner_js_path)) ? (string) filemtime($scanner_js_path) : $version;
$scanner_css_ver = (is_string($scanner_css_path) && is_file($scanner_css_path)) ? (string) filemtime($scanner_css_path) : $version;
$cq_comunas = (isset($riverso_cq['comunas']) && is_array($riverso_cq['comunas'])) ? $riverso_cq['comunas'] : [];
// "Imprimir Ya!": solo si el módulo de impresión está activo.
$cq_print_config = (!$standalone && class_exists('Riverso_Print_Module'))
    ? Riverso_Print_Module::get_instance()->client_config()
    : null;
$print_js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/print-quick.js';
$print_css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/printing.css';
$print_js_ver = (is_string($print_js_path) && is_file($print_js_path)) ? (string) filemtime($print_js_path) : $version;
$print_css_ver = (is_string($print_css_path) && is_file($print_css_path)) ? (string) filemtime($print_css_path) : $version;

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
    <link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/barcode-scanner.css?ver=<?php echo htmlspecialchars($scanner_css_ver, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="riverso-cq-body">
<?php else: ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customers.css?ver=<?php echo htmlspecialchars($css_customers_ver, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/barcode-scanner.css?ver=<?php echo htmlspecialchars($scanner_css_ver, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($cq_print_config !== null): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/printing.css?ver=<?php echo htmlspecialchars($print_css_ver, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
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
                            <option value="awaiting">Esperando respuesta</option>
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
                    <label class="cq-advanced-toggle cq-seller-toggle" for="cq-seller-view" title="Muestra margen, utilidad, stock y costos. Mantenla apagada frente al cliente.">
                        <input type="checkbox" id="cq-seller-view" autocomplete="off">
                        <span>Interfaz vendedor</span>
                    </label>
                    <span id="cq-status" class="cq-badge cq-badge-draft">Borrador</span>
                    <span id="cq-expired-badge" class="cq-expired-tag" hidden>Vencida</span>
                    <div class="cq-order-wrap cq-state-wrap" id="cq-state-wrap" hidden>
                        <button type="button" class="cq-btn" id="cq-state-btn" aria-haspopup="true" aria-expanded="false">Estado ▾</button>
                        <div class="cq-order-menu cq-state-menu" id="cq-transitions" hidden role="menu"></div>
                    </div>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-invoice" hidden>Facturar</button>
                    <a id="cq-order-link" class="cq-link cq-order-link" href="#" target="_blank" rel="noopener" hidden>Ver pedido</a>
                    <button type="button" class="cq-btn" id="cq-import" hidden title="Importar desde cotización recibida">Importar</button>
                    <label class="cq-pdf-template" title="Plantilla del PDF">
                        <select id="cq-pdf-template" aria-label="Plantilla PDF">
                            <option value="family">PDF: por familia</option>
                            <option value="product">PDF: por producto</option>
                            <option value="family_detail">PDF: por familia – detalle</option>
                            <option value="product_detail">PDF: por producto – detalle</option>
                        </select>
                    </label>
                    <?php if ($cq_print_config !== null): ?>
                    <div class="cq-split cq-now-wrap">
                        <button type="button" class="cq-btn cq-btn-now" id="cq-print-now" title="Imprimir directo con el preset configurado">
                            <span class="cq-now-dot" id="cq-print-now-dot" aria-hidden="true"></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>
                            <span>Imprimir Ya!</span>
                        </button>
                        <button type="button" class="cq-btn cq-btn-now cq-btn-caret" id="cq-print-now-caret" aria-haspopup="menu" aria-expanded="false" aria-label="Opciones de Imprimir Ya!">▾</button>
                        <div class="cq-order-menu cq-now-menu" id="cq-print-now-menu" role="menu" hidden>
                            <div class="cq-now-note">Cargando impresoras…</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button type="button" class="cq-btn" id="cq-print" title="Imprimir con el diálogo del navegador">
                        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                        <span>Imprimir</span>
                    </button>
                    <button type="button" class="cq-btn" id="cq-pdf" title="Abrir cotización en PDF">PDF</button>
                    <button type="button" class="cq-btn cq-btn-icon" id="cq-email" title="Enviar por correo">
                        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
                        <span>Email</span>
                    </button>
                    <button type="button" class="cq-btn cq-btn-icon" id="cq-copy-link" title="Copiar al portapapeles el enlace para ver la cotización (no cambia el estado)">
                        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                        <span>Copiar link</span>
                    </button>
                    <div class="cq-order-wrap cq-options-wrap">
                        <button type="button" class="cq-btn" id="cq-options" aria-haspopup="true" aria-expanded="false">Opciones ▾</button>
                        <div class="cq-order-menu cq-options-menu" id="cq-options-menu" hidden role="menu">
                            <button type="button" role="menuitem" id="cq-opt-draft">Devolver a Borrador</button>
                            <button type="button" role="menuitem" id="cq-delete" class="cq-opt-danger">Borrar</button>
                            <p id="cq-options-note" class="cq-options-note" hidden></p>
                        </div>
                    </div>
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
                    <div class="cq-seller-only"><dt>Margen</dt><dd id="cq-total-margin">—</dd></div>
                    <div class="cq-seller-only">
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
                        <button type="button" class="cq-btn cq-btn-lupa" id="cq-scan" title="Escanear código de barras" aria-label="Escanear código de barras">
                            <span aria-hidden="true">📷</span>
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
                                <th scope="col" class="cq-num" title="Monto final con IVA y descuento aplicado; entre paréntesis, el precio de lista">Total bruto</th>
                                <th scope="col" class="cq-num cq-advanced" title="Porcentaje de descuento sobre el bruto de la línea (equivalente al dscto margen)">Dscto precio</th>
                                <th scope="col" class="cq-num cq-advanced" title="Mismo descuento expresado como % del margen bruto − costo (equivalente al dscto precio)">Dscto margen</th>
                                <th scope="col" class="cq-num cq-advanced cq-seller-only">Utilidad</th>
                                <th scope="col" class="cq-num cq-advanced cq-seller-only" title="Stock en bodega (live)">Stock</th>
                                <th scope="col" class="cq-advanced cq-seller-only" title="Confianza del inventario">Confianza</th>
                                <th scope="col" class="cq-advanced cq-seller-only">Inventariar</th>
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
                                        <th scope="col" class="cq-num cq-seller-only">Costo</th>
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
                            <label><input type="radio" name="cq-line-mode" value="rule"> Otra regla</label>
                            <div class="cq-line-rule-picker" id="cq-line-rule-picker" hidden>
                                <div class="cq-line-rule-picker-row">
                                    <input type="text" id="cq-line-rule-input" autocomplete="off" placeholder="Buscar regla por código o nombre" aria-label="Buscar regla">
                                    <button type="button" class="cq-btn cq-btn-customer-action" id="cq-line-rule-search-btn" title="Buscar reglas" aria-label="Buscar reglas">
                                        <span aria-hidden="true">🔍</span>
                                    </button>
                                </div>
                                <ul id="cq-line-rule-suggest" class="cq-results cq-rule-results" hidden></ul>
                                <p id="cq-line-rule-chosen" class="cq-modal-hint" role="status"></p>
                            </div>
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
                    </div>
                    <details class="cq-discounts" id="cq-line-discounts">
                        <summary class="cq-discounts-summary">
                            <span class="cq-discounts-title">Descuentos</span>
                            <span class="cq-discounts-brief" id="cq-line-discounts-brief"></span>
                        </summary>
                        <div class="cq-discounts-body">
                            <div class="cq-line-fields cq-discounts-ref">
                                <label class="cq-float-field">
                                    <span>Costo unitario</span>
                                    <input type="text" id="cq-dm-cost" readonly tabindex="-1" autocomplete="off">
                                </label>
                                <label class="cq-float-field">
                                    <span>Factor anterior</span>
                                    <input type="text" id="cq-dm-factor-before" readonly tabindex="-1" autocomplete="off">
                                    <em class="cq-float-hint">Precio de lista / costo</em>
                                </label>
                            </div>
                            <p class="cq-modal-hint cq-discounts-help">Todos son el mismo descuento. El campo que escribes queda <strong>fijado</strong>: si cambia la cantidad o el precio, se mantiene ese valor.</p>
                            <div class="cq-line-fields cq-discounts-fields">
                                <label class="cq-float-field" data-anchor="price_pct">
                                    <span>Descuento precio %</span>
                                    <input type="text" id="cq-line-price-discount" inputmode="decimal" autocomplete="off">
                                </label>
                                <label class="cq-float-field" data-anchor="margin_pct">
                                    <span>Descuento margen %</span>
                                    <input type="text" id="cq-line-margin-discount" inputmode="decimal" autocomplete="off">
                                    <em class="cq-margin-na-text cq-dm-na" title="Costo no encontrado" hidden>— sin costo</em>
                                </label>
                                <label class="cq-float-field" data-anchor="amount">
                                    <span>Monto de descuento</span>
                                    <input type="text" id="cq-line-discount-amount" inputmode="decimal" autocomplete="off">
                                </label>
                                <label class="cq-float-field" data-anchor="factor">
                                    <span>Factor objetivo</span>
                                    <input type="text" id="cq-dm-factor" inputmode="decimal" autocomplete="off">
                                    <em class="cq-margin-na-text cq-dm-na" title="Costo no encontrado" hidden>— sin costo</em>
                                    <em class="cq-float-hint">Final unitario / costo</em>
                                </label>
                                <label class="cq-float-field" data-anchor="final_unit">
                                    <span>Monto final unitario objetivo</span>
                                    <input type="text" id="cq-dm-final-unit" inputmode="decimal" autocomplete="off">
                                    <em class="cq-float-hint">Por unidad</em>
                                </label>
                                <label class="cq-float-field" data-anchor="final_total">
                                    <span>Monto final objetivo</span>
                                    <input type="text" id="cq-line-final-amount" inputmode="decimal" autocomplete="off">
                                </label>
                            </div>
                            <p id="cq-line-discount-hint" class="cq-line-preview cq-discounts-status" role="status"></p>
                        </div>
                    </details>
                    <div class="cq-line-by-amount" id="cq-line-by-amount">
                        <p class="cq-line-by-amount-row">
                            <strong>Por monto:</strong>
                            Monto $ <input type="text" id="cq-line-monto" class="cq-line-monto-input" inputmode="decimal" autocomplete="off" placeholder="1000">
                            <button type="button" class="cq-btn cq-btn-small" id="cq-line-by-amount-btn">¿Cuánto entregar?</button>
                        </p>
                        <p class="cq-modal-hint">Total deseado antes de descuentos. Calcula la cantidad a entregar con la regla activa.</p>
                        <div id="cq-line-by-amount-result" class="cq-line-by-amount-result"></div>
                    </div>
                    <p id="cq-line-preview" class="cq-line-preview" role="status"></p>
                </div>
                <footer class="cq-modal-foot cq-line-modal-foot">
                    <button type="button" class="cq-btn" id="cq-line-cancel" data-cq-line-close="1">Cancelar</button>
                    <button type="button" class="cq-btn cq-btn-save-line" id="cq-line-save">Guardar cambios</button>
                </footer>
            </div>
        </div>

        <div id="cq-rule-search-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-rule-search-close="1"></div>
            <div class="cq-modal-dialog cq-rule-search-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-rule-search-title">
                <header class="cq-modal-head">
                    <h3 id="cq-rule-search-title">Buscar regla</h3>
                    <button type="button" class="cq-modal-close" id="cq-rule-search-close" aria-label="Cerrar" data-rule-search-close="1">×</button>
                </header>
                <div class="cq-modal-body">
                    <label class="cq-field cq-field-wide">
                        <span>Código o nombre</span>
                        <input type="text" id="cq-rule-search-q" autocomplete="off" placeholder="Ej.: R-3, mayorista">
                    </label>
                    <p id="cq-rule-search-hint" class="cq-modal-hint" role="status">Reglas aprobadas (última versión de cada código).</p>
                    <ul id="cq-rule-search-results" class="cq-results cq-modal-results cq-rule-results"></ul>
                </div>
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

        <div id="cq-email-modal" class="cq-modal" hidden aria-hidden="true">
            <div class="cq-modal-backdrop" data-cq-email-close="1"></div>
            <div class="cq-modal-dialog cq-email-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-email-title">
                <header class="cq-modal-head">
                    <div>
                        <h3 id="cq-email-title">Enviar por correo</h3>
                        <p class="cq-email-sub" id="cq-email-sub"></p>
                    </div>
                    <button type="button" class="cq-modal-close" aria-label="Cerrar" data-cq-email-close="1">×</button>
                </header>
                <div class="cq-modal-body cq-email-body">
                    <div class="cq-email-section">
                        <span class="cq-email-label">Destinatarios</span>
                        <ul class="cq-email-list" id="cq-email-list" role="list"></ul>
                        <p class="cq-email-empty" id="cq-email-empty" hidden></p>
                        <div class="cq-email-add">
                            <input type="email" id="cq-email-input" placeholder="correo@empresa.cl — Enter para agregar" autocomplete="email" enterkeyhint="done">
                            <button type="button" class="cq-btn" id="cq-email-add">Agregar</button>
                        </div>
                    </div>
                    <label class="cq-field">
                        <span>Asunto</span>
                        <input type="text" id="cq-email-subject" maxlength="190">
                    </label>
                    <label class="cq-field">
                        <span>Mensaje</span>
                        <textarea id="cq-email-message" rows="7"></textarea>
                    </label>
                    <fieldset class="cq-email-mode">
                        <legend>Qué incluir en el correo</legend>
                        <label><input type="checkbox" id="cq-email-mode-pdf"> <span>PDF adjunto <small>(lo genera el hub de impresión de PC1)</small></span></label>
                        <label><input type="checkbox" id="cq-email-mode-link"> <span>Enlace para ver la cotización <small id="cq-email-link-days"></small></span></label>
                        <p class="cq-email-mode-note" id="cq-email-mode-note" hidden></p>
                    </fieldset>
                    <p class="cq-email-mode-note" id="cq-email-state-note" hidden>Al enviar, la cotización pasará a <strong>Esperando respuesta</strong>.</p>
                    <p class="cq-email-status" id="cq-email-status" role="status" hidden></p>
                </div>
                <footer class="cq-modal-foot cq-email-foot">
                    <button type="button" class="cq-btn" data-cq-email-close="1">Cancelar</button>
                    <button type="button" class="cq-btn" id="cq-email-use-link" hidden>Enviar solo con enlace</button>
                    <button type="button" class="cq-btn cq-btn-primary" id="cq-email-send">Enviar</button>
                </footer>
            </div>
        </div>

        <p id="cq-list-message" class="cq-message" role="status"></p>
    </div>
</div>
<script>window.RIVERSO_CQ = <?php echo riverso_pos_cq_json($riverso_cq); ?>;</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/barcode-scanner.js?ver=<?php echo htmlspecialchars($scanner_js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($cq_print_config !== null): ?>
<script>window.RIVERSO_PRINT = <?php echo riverso_pos_cq_json($cq_print_config); ?>;</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/print-quick.js?ver=<?php echo htmlspecialchars($print_js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/line-discounts.js?ver=<?php echo htmlspecialchars($line_discounts_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customer-quotes.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($standalone): ?>
</body>
</html>
<?php endif; ?>
