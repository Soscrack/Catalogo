<?php
/**
 * Facturación · Buscar documentos tributarios (portal).
 *
 * @var array<string, mixed> $riverso_billing
 */
$surface = (isset($riverso_billing['surface']) && $riverso_billing['surface'] === 'admin') ? 'admin' : 'portal';
$asset_base = rtrim((string) (isset($riverso_billing['assetBase']) ? $riverso_billing['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/billing-search.js';
$css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/billing.css';
$js_ver = (is_string($js_path) && is_file($js_path)) ? (string) filemtime($js_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;
$search_users = isset($riverso_billing['searchUsers']) && is_array($riverso_billing['searchUsers'])
    ? $riverso_billing['searchUsers']
    : [];

if (!function_exists('riverso_pos_billing_json')) {
    function riverso_pos_billing_json($data) {
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
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/billing.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">

<div class="wrap riverso-bill-wrap riverso-bill-wrap--<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?> riverso-bill-wrap--search">
    <div id="riverso-billing-search" class="riverso-bill bill-search" data-ready="0">

        <div id="bill-search-alerts" class="bill-alerts" hidden></div>

        <section class="bill-search-card" aria-labelledby="bill-search-basic-title">
            <header class="bill-search-card-head">
                <h2 id="bill-search-basic-title">Filtros de búsqueda básicos</h2>
            </header>
            <div class="bill-search-card-body">
                <div class="bill-search-grid">
                    <label class="bill-search-field">
                        <span class="bill-search-label">Fecha de emisión desde</span>
                        <input type="date" id="bs-date-from" class="bill-search-input">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Fecha de emisión hasta</span>
                        <input type="date" id="bs-date-to" class="bill-search-input">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Tipo de identificación</span>
                        <select id="bs-id-type" class="bill-search-input" disabled title="Solo RUT por ahora">
                            <option value="rut" selected>RUT Cliente/Proveedor</option>
                        </select>
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">RUT Cliente/Proveedor</span>
                        <input type="text" id="bs-rut" class="bill-search-input" autocomplete="off" placeholder="">
                    </label>

                    <label class="bill-search-field">
                        <span class="bill-search-label">Cliente/Proveedor</span>
                        <input type="text" id="bs-receiver" class="bill-search-input" autocomplete="off">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Número de Documento</span>
                        <input type="text" id="bs-doc-number" class="bill-search-input" autocomplete="off">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Estado de pago</span>
                        <select id="bs-payment-status" class="bill-search-input">
                            <option value="all" selected>Todos</option>
                            <option value="paid">Pagado</option>
                            <option value="unpaid">Impago</option>
                            <option value="partial">Parcial</option>
                        </select>
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Estado de documentos</span>
                        <select id="bs-doc-status" class="bill-search-input">
                            <option value="" selected>Todos</option>
                            <option value="draft">Borrador</option>
                            <option value="closed_local">Cerrado sin SII</option>
                            <option value="emitted">Emitido</option>
                            <option value="error">Con error</option>
                        </select>
                    </label>

                    <label class="bill-search-field bill-search-field--wip">
                        <span class="bill-search-label">Estado Op. Documento <span class="bill-search-wip">[WIP]</span></span>
                        <select id="bs-op-status" class="bill-search-input" disabled>
                            <option value="" selected>Todos</option>
                        </select>
                    </label>
                </div>

                <div class="bill-search-scopes">
                    <label class="bill-search-scope">
                        <input type="checkbox" id="bs-scope-issued" checked>
                        <span>Buscar en documentos emitidos</span>
                    </label>
                    <label class="bill-search-scope bill-search-scope--wip" title="En desarrollo">
                        <input type="checkbox" id="bs-scope-received" disabled>
                        <span>Buscar en documentos recibidos <span class="bill-search-wip">[WIP]</span></span>
                    </label>
                    <label class="bill-search-scope">
                        <input type="checkbox" id="bs-scope-drafts" checked>
                        <span>Incluir borradores locales</span>
                    </label>
                </div>
            </div>
        </section>

        <section class="bill-search-card bill-search-advanced" aria-labelledby="bill-search-adv-title">
            <button type="button" class="bill-search-card-head bill-search-adv-toggle" id="bs-adv-toggle" aria-expanded="false" aria-controls="bs-adv-body">
                <h2 id="bill-search-adv-title">Filtros avanzados</h2>
                <span class="bill-search-chevron" aria-hidden="true">▾</span>
            </button>
            <div class="bill-search-card-body" id="bs-adv-body" hidden>
                <div class="bill-search-grid">
                    <label class="bill-search-field">
                        <span class="bill-search-label">Valor Total del documento (mín.)</span>
                        <input type="number" id="bs-total-min" class="bill-search-input" min="0" step="1">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Valor Total del documento (máx.)</span>
                        <input type="number" id="bs-total-max" class="bill-search-input" min="0" step="1">
                    </label>
                    <label class="bill-search-field bill-search-field--wip">
                        <span class="bill-search-label">Encargado <span class="bill-search-wip">[WIP]</span></span>
                        <select id="bs-encargado" class="bill-search-input" disabled>
                            <option value="">Cualquier responsable</option>
                        </select>
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Ingresado por</span>
                        <select id="bs-created-by" class="bill-search-input">
                            <option value="">Cualquier usuario</option>
                            <?php foreach ($search_users as $su): ?>
                            <option value="<?php echo (int) $su['id']; ?>"><?php echo esc_html((string) $su['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="bill-search-field bill-search-field--wip">
                        <span class="bill-search-label">Vendedor <span class="bill-search-wip">[WIP]</span></span>
                        <select id="bs-vendedor" class="bill-search-input" disabled>
                            <option value="">Cualquier responsable</option>
                        </select>
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Folio desde</span>
                        <input type="text" id="bs-folio-from" class="bill-search-input" inputmode="numeric">
                    </label>
                    <label class="bill-search-field">
                        <span class="bill-search-label">Folio hasta</span>
                        <input type="text" id="bs-folio-to" class="bill-search-input" inputmode="numeric">
                    </label>
                    <label class="bill-search-field bill-search-field--wip">
                        <span class="bill-search-label">Num envío SII <span class="bill-search-wip">[WIP]</span></span>
                        <input type="text" id="bs-sii-envio" class="bill-search-input" disabled>
                    </label>

                    <label class="bill-search-field">
                        <span class="bill-search-label">Estado envío SII</span>
                        <select id="bs-sii-status" class="bill-search-input">
                            <option value="" selected>** Seleccione **</option>
                            <option value="ok">Aceptado / OK</option>
                            <option value="pending">Pendiente</option>
                            <option value="error">Con error</option>
                        </select>
                    </label>
                    <label class="bill-search-field bill-search-check bill-search-field--wip">
                        <input type="checkbox" id="bs-include-contact" disabled>
                        <span>Incluir contacto de documento <span class="bill-search-wip">[WIP]</span></span>
                    </label>
                    <label class="bill-search-field bill-search-check bill-search-field--wip">
                        <input type="checkbox" id="bs-envio-masivo" disabled>
                        <span>Envío masivo <span class="bill-search-wip">[WIP]</span></span>
                    </label>
                    <label class="bill-search-field bill-search-field--wip">
                        <span class="bill-search-label">Estado cesión SII <span class="bill-search-wip">[WIP]</span></span>
                        <select id="bs-cesion" class="bill-search-input" disabled>
                            <option value="" selected>** Seleccione **</option>
                        </select>
                    </label>
                </div>
            </div>
        </section>

        <div class="bill-search-actions">
            <button type="button" class="bill-btn bill-btn-success" id="bs-search">Buscar</button>
            <button type="button" class="bill-btn bill-btn-secondary" id="bs-reset">Nueva Búsqueda</button>
        </div>

        <section class="bill-search-results" id="bs-results" hidden>
            <div class="bill-search-results-head">
                <h2>RESULTADO DE LA BÚSQUEDA</h2>
                <div class="bill-search-results-tools">
                    <div class="bill-search-select-count" id="bs-selected-count">0 seleccionados</div>
                    <div class="bill-search-bulk-wrap">
                        <button type="button" class="bill-btn bill-btn-secondary bill-search-bulk-btn" id="bs-bulk-toggle" aria-haspopup="menu" aria-expanded="false">
                            Acciones masivas ▾
                        </button>
                        <div class="bill-search-bulk-menu" id="bs-bulk-menu" hidden role="menu">
                            <button type="button" class="bill-search-bulk-item" role="menuitem" disabled>Exportar [WIP]</button>
                            <button type="button" class="bill-search-bulk-item" role="menuitem" disabled>Enviar correo [WIP]</button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="bs-groups" class="bill-search-groups"></div>
            <p class="bill-search-empty" id="bs-empty" hidden>No se encontraron documentos con esos filtros.</p>
        </section>
    </div>
</div>

<script>
window.RIVERSO_BILLING = <?php echo riverso_pos_billing_json($riverso_billing); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/billing-search.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
