<?php
/**
 * App Recepción de compras: recibir → ordenar → reclamar.
 *
 * @var array<string, mixed> $riverso_rx
 */
if (!defined('ABSPATH')) {
    exit;
}

$surface = (isset($riverso_rx['surface']) && $riverso_rx['surface'] === 'admin') ? 'admin' : 'portal';
$asset_base = rtrim((string) ($riverso_rx['assetBase'] ?? ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/reception.js';
$css_path = RIVERSO_POS_PLUGIN_DIR . 'assets/css/reception.css';
$js_ver = is_file($js_path) ? (string) filemtime($js_path) : $version;
$css_ver = is_file($css_path) ? (string) filemtime($css_path) : $version;
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<link rel="stylesheet" href="<?php echo esc_url($asset_base . '/css/reception.css?ver=' . $css_ver); ?>">

<div class="wrap riverso-rx-wrap<?php echo $surface === 'portal' ? ' riverso-rx-wrap--portal' : ''; ?>">
    <div id="riverso-rx" class="riverso-rx" data-surface="<?php echo esc_attr($surface); ?>">

        <!-- Lista de documentos -->
        <section id="rx-list-view" hidden>
            <div class="rx-top">
                <h1>Recepción</h1>
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-claims-btn">
                    Reclamos a proveedores <span class="rx-badge" id="rx-claims-count" hidden></span>
                </button>
                <button type="button" class="rx-btn" id="rx-ingest-btn">Ingresar documento</button>
                <input type="file" id="rx-ingest-file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.xml,application/pdf,image/*,text/xml" multiple hidden>
            </div>
            <p class="rx-hint">Lo recibido entra a la zona <strong>Recepción</strong>; después se ordena a su lugar. Si al ordenar falta algo o viene en mal estado, se reclama al proveedor.</p>

            <!-- Documento que llega con el pedido: escaneo (PDF/imagen) o XML -->
            <div class="rx-drop" id="rx-drop" tabindex="0" role="button" aria-label="Ingresar documento">
                <strong class="rx-drop-desk">Arrastra aquí la factura o guía que llegó con el pedido</strong>
                <strong class="rx-drop-touch">Toca para fotografiar o elegir la factura o guía que llegó con el pedido</strong>
                <span class="rx-sub">PDF o foto del documento escaneado, o el XML. Si ya estaba ingresado se abre directo (sin volver a procesar con IA).</span>
            </div>
            <div id="rx-ingest-list" class="rx-ingest-list"></div>

            <details class="rx-inbox" id="rx-inbox" hidden>
                <summary class="rx-inbox-head">
                    <strong>Escaneados sin ingresar</strong> <span class="rx-sub" id="rx-inbox-total"></span>
                </summary>
                <div id="rx-inbox-list"></div>
            </details>

            <div class="rx-chips" id="rx-filters" role="tablist">
                <button type="button" class="rx-chip" data-filter="pendientes">Pendientes <span></span></button>
                <button type="button" class="rx-chip" data-filter="parciales">Parciales <span></span></button>
                <button type="button" class="rx-chip" data-filter="por_ordenar">Por ordenar <span></span></button>
                <button type="button" class="rx-chip" data-filter="recibidas">Recibidas <span></span></button>
                <button type="button" class="rx-chip" data-filter="ignoradas">Ignoradas <span></span></button>
                <button type="button" class="rx-chip" data-filter="todas">Todas <span></span></button>
            </div>

            <div class="rx-toolbar">
                <input type="search" id="rx-search" placeholder="Buscar folio, proveedor o RUT" autocomplete="off">
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-ignore-selected" hidden>Ignorar seleccionados</button>
            </div>

            <div class="rx-table-wrap">
                <table class="rx-table">
                    <thead>
                        <tr>
                            <th class="rx-col-check"><input type="checkbox" id="rx-check-all" aria-label="Seleccionar todos"></th>
                            <th>Documento</th>
                            <th>Proveedor</th>
                            <th>Fecha</th>
                            <th class="num">Ítems</th>
                            <th>Recepción</th>
                            <th class="num">Por ordenar</th>
                            <th class="rx-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody id="rx-list-body">
                        <tr><td colspan="8" class="rx-empty">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="rx-pager" id="rx-pager"></div>
        </section>

        <!-- Documento -->
        <section id="rx-doc-view" hidden>
            <div class="rx-top">
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-back">← Documentos</button>
                <h1 id="rx-doc-title"></h1>
                <span class="rx-state" id="rx-doc-state"></span>
                <a class="rx-btn" id="rx-process-folio" target="_blank" rel="noopener" hidden>Procesar folio ↗</a>
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-refresh" title="Recargar después de vincular productos">Actualizar</button>
            </div>
            <div class="rx-doc-meta" id="rx-doc-meta"></div>
            <div class="rx-notice" id="rx-doc-notice" hidden></div>

            <div class="rx-tabs" role="tablist">
                <button type="button" class="rx-tab" data-tab="recibir">Recibir</button>
                <button type="button" class="rx-tab" data-tab="ordenar">Ordenar <span class="rx-badge" id="rx-order-count" hidden></span></button>
                <button type="button" class="rx-tab" data-tab="reclamos">Reclamos <span class="rx-badge" id="rx-doc-claims-count" hidden></span></button>
            </div>

            <div class="rx-panel" id="rx-tab-recibir">
                <div class="rx-scan">
                    <input type="text" id="rx-scan-input" placeholder="Leer código de barra o escribir código y Enter" autocomplete="off">
                    <button type="button" class="rx-btn rx-btn-ghost" id="rx-add-product">+ Producto no incluido</button>
                </div>
                <div class="rx-scan-status" id="rx-scan-status" aria-live="polite"></div>
                <div class="rx-table-wrap">
                    <table class="rx-table rx-lines">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Producto</th>
                                <th class="num">Documento</th>
                                <th class="num">Esperado</th>
                                <th class="num">Ya recibido</th>
                                <th class="num">Recibir ahora</th>
                            </tr>
                        </thead>
                        <tbody id="rx-receive-body"></tbody>
                    </table>
                </div>
                <div class="rx-actions" id="rx-receive-actions">
                    <button type="button" class="rx-btn rx-btn-ghost" id="rx-fill-all">Recibir todo</button>
                    <button type="button" class="rx-btn rx-btn-ghost" id="rx-clear">Limpiar</button>
                    <span class="rx-spacer"></span>
                    <button type="button" class="rx-btn rx-btn-secondary" id="rx-confirm-close">Confirmar y cerrar con faltante</button>
                    <button type="button" class="rx-btn" id="rx-confirm">Confirmar recepción</button>
                </div>
            </div>

            <div class="rx-panel" id="rx-tab-ordenar" hidden>
                <div class="rx-order-tools">
                    <label>Destino para todo
                        <select id="rx-order-all-dest"></select>
                    </label>
                    <input type="text" id="rx-loc-scan" placeholder="Leer código de lugar" autocomplete="off">
                    <span class="rx-spacer"></span>
                    <button type="button" class="rx-btn" id="rx-order-all">Ordenar todo</button>
                </div>
                <div class="rx-table-wrap">
                    <table class="rx-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="num">En Recepción</th>
                                <th>Destino</th>
                                <th class="num">Cantidad</th>
                                <th class="rx-col-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="rx-order-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="rx-panel" id="rx-tab-reclamos" hidden>
                <div id="rx-doc-claims"></div>
            </div>

            <div class="rx-doc-footer">
                <a class="rx-link" id="rx-open-invoice" target="_blank" rel="noopener">Ver documento de compra</a>
                <span class="rx-spacer"></span>
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-restore-doc" hidden>Volver a pendiente</button>
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-cancel-rec" hidden>Anular recepción</button>
                <button type="button" class="rx-btn rx-btn-danger" id="rx-ignore-doc" hidden>Ignorar documento</button>
            </div>
        </section>

        <!-- Reclamos -->
        <section id="rx-claims-view" hidden>
            <div class="rx-top">
                <button type="button" class="rx-btn rx-btn-ghost" id="rx-claims-back">← Documentos</button>
                <h1>Reclamos a proveedores</h1>
            </div>
            <div class="rx-chips" id="rx-claim-filters">
                <button type="button" class="rx-chip" data-estado="abiertos">Abiertos</button>
                <button type="button" class="rx-chip" data-estado="por_enviar">Por enviar</button>
                <button type="button" class="rx-chip" data-estado="enviado">Enviados</button>
                <button type="button" class="rx-chip" data-estado="resuelto">Resueltos</button>
                <button type="button" class="rx-chip" data-estado="descartado">Descartados</button>
                <button type="button" class="rx-chip" data-estado="todos">Todos</button>
            </div>
            <div id="rx-claims-list"></div>
        </section>
    </div>
</div>

<script>
window.riversoReception = <?php echo wp_json_encode($riverso_rx, $json_flags); ?>;
</script>
<script src="<?php echo esc_url($asset_base . '/js/reception.js?ver=' . $js_ver); ?>"></script>
