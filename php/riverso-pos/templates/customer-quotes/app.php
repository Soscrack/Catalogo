<?php
/**
 * Portal de cotizaciones de venta (es-CL).
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
                    <p class="cq-lead">Borrador y lista.</p>
                </div>
                <button type="button" class="cq-btn cq-btn-primary" id="cq-new">Nueva cotización</button>
            </header>
            <div class="cq-toolbar">
                <label for="cq-status-filter">Estado</label>
                <select id="cq-status-filter">
                    <option value="all">Todas</option>
                    <option value="draft">Borrador</option>
                    <option value="listed">Lista</option>
                </select>
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
                    <button type="button" class="cq-btn" id="cq-transition" hidden>Pasar a lista</button>
                </div>
            </header>

            <div class="cq-card">
                <div class="cq-header-grid">
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
                    <label for="cq-search">Buscar producto</label>
                    <div class="cq-search-row">
                        <input type="search" id="cq-search" autocomplete="off" placeholder="SKU, código proveedor o código de barras" enterkeyhint="search">
                        <button type="button" class="cq-btn" id="cq-search-btn">Buscar</button>
                    </div>
                    <ul id="cq-results" class="cq-results" hidden></ul>
                </div>
                <div class="cq-table-wrap">
                    <table class="cq-table">
                        <thead>
                            <tr>
                                <th scope="col">Detalle</th>
                                <th scope="col" class="cq-num">Cantidad</th>
                                <th scope="col" class="cq-num">Precio</th>
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
        <p id="cq-list-message" class="cq-message" role="status"></p>
    </div>
</div>
<script>window.RIVERSO_CQ = <?php echo riverso_pos_cq_json($riverso_cq); ?>;</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customer-quotes.js?ver=<?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php if ($standalone): ?>
</body>
</html>
<?php endif; ?>