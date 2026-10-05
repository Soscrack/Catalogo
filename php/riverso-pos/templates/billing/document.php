<?php
/**
 * Facturación · Detalle de documento emitido (solo lectura).
 *
 * @var array<string, mixed> $riverso_billing
 */
$surface = (isset($riverso_billing['surface']) && $riverso_billing['surface'] === 'admin') ? 'admin' : 'portal';
$asset_base = rtrim((string) (isset($riverso_billing['assetBase']) ? $riverso_billing['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$plugin_dir = defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '';
$js_path = $plugin_dir . 'assets/js/billing-document.js';
$js_emails_path = $plugin_dir . 'assets/js/billing-emails.js';
$css_path = $plugin_dir . 'assets/css/billing.css';
$css_cq_path = $plugin_dir . 'assets/css/customer-quotes.css';
$js_ver = is_file($js_path) ? (string) filemtime($js_path) : $version;
$js_emails_ver = is_file($js_emails_path) ? (string) filemtime($js_emails_path) : $version;
$css_ver = is_file($css_path) ? (string) filemtime($css_path) : $version;
$css_cq_ver = is_file($css_cq_path) ? (string) filemtime($css_cq_path) : $version;
$issuer = isset($riverso_billing['issuer']['issuer']) && is_array($riverso_billing['issuer']['issuer'])
    ? $riverso_billing['issuer']['issuer']
    : [];
$issuer_rut = isset($issuer['tax_id_code']) ? (string) $issuer['tax_id_code'] : '';
$addr_bits = array_filter([
    $issuer['address'] ?? '',
    $issuer['district'] ?? '',
    $issuer['city'] ?? '',
]);

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
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customer-quotes.css?ver=<?php echo htmlspecialchars($css_cq_ver, ENT_QUOTES, 'UTF-8'); ?>">

<div class="wrap riverso-bill-wrap riverso-bill-wrap--<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>">
    <div id="riverso-billing-document" class="riverso-bill bill-doc" data-ready="0">

        <div class="bill-doc-topbar">
            <a class="bill-btn bill-btn-secondary" id="bd-back" href="<?php echo esc_url($riverso_billing['searchUrl'] ?? '#'); ?>">← Volver a la búsqueda</a>
        </div>

        <div id="bill-alerts" class="bill-alerts" hidden></div>

        <div id="bd-loading" class="bill-card bill-doc-loading">Cargando documento…</div>

        <div id="bd-content" hidden>
            <div class="bill-card bill-issuer-card">
                <div class="bill-card-head">
                    <h2>EMISOR Y DATOS DEL DOCUMENTO</h2>
                    <div class="bill-doc-toolbar" role="toolbar" aria-label="Acciones del documento">
                        <div class="bill-doc-toolbar-row">
                            <div class="bill-doc-split">
                                <button type="button" class="bill-doc-tool" data-doc-action="print-pdf" title="Imprimir PDF oficial">
                                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                                    <span>Imprimir</span>
                                </button>
                                <button type="button" class="bill-doc-tool bill-doc-caret" data-doc-menu="bd-menu-print" aria-haspopup="menu" aria-expanded="false" aria-label="Más opciones de impresión">▾</button>
                                <div class="bill-doc-menu" id="bd-menu-print" role="menu" hidden>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="print-pdf">PDF oficial</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="print-family">Carta por familia</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="print-product">Carta por producto</button>
                                </div>
                            </div>
                            <div class="bill-doc-split">
                                <button type="button" class="bill-doc-tool" data-doc-action="pdf-view" title="Ver PDF">
                                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/></svg>
                                    <span>PDF</span>
                                </button>
                                <button type="button" class="bill-doc-tool bill-doc-caret" data-doc-menu="bd-menu-pdf" aria-haspopup="menu" aria-expanded="false" aria-label="Más opciones de PDF">▾</button>
                                <div class="bill-doc-menu" id="bd-menu-pdf" role="menu" hidden>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="pdf-view">Ver PDF</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="pdf-download">Descargar PDF</button>
                                </div>
                            </div>
                            <div class="bill-doc-split">
                                <button type="button" class="bill-doc-tool" data-doc-action="xml-download" title="Descargar sobre XML">
                                    <span>SOBRE XML</span>
                                </button>
                                <button type="button" class="bill-doc-tool bill-doc-caret" data-doc-menu="bd-menu-xml" aria-haspopup="menu" aria-expanded="false" aria-label="Más opciones de XML">▾</button>
                                <div class="bill-doc-menu" id="bd-menu-xml" role="menu" hidden>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="xml-download">Descargar XML</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="xml-view">Ver XML</button>
                                </div>
                            </div>
                            <button type="button" class="bill-doc-tool" data-doc-action="email" title="Enviar por correo">
                                <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                <span>Email</span>
                            </button>
                        </div>
                        <div class="bill-doc-toolbar-row">
                            <div class="bill-doc-split">
                                <button type="button" class="bill-doc-tool" data-doc-menu="bd-menu-options" aria-haspopup="menu" aria-expanded="false">
                                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm14.71-9.04c.39-.39.39-1.02 0-1.41l-2.54-2.54a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 2.03-1.63z"/></svg>
                                    <span>OPCIONES ▾</span>
                                </button>
                                <div class="bill-doc-menu" id="bd-menu-options" role="menu" hidden>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="pay-link-copy" id="bd-opt-paylink-copy" hidden>Copiar enlace de pago</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="pay-link-open" id="bd-opt-paylink-open" hidden>Abrir enlace de pago</button>
                                    <button type="button" class="bill-doc-menu-item" role="menuitem" data-doc-action="refresh">Actualizar datos</button>
                                    <button type="button" class="bill-doc-menu-item is-wip" role="menuitem" disabled title="En desarrollo">Emitir nota de crédito [WIP]</button>
                                    <button type="button" class="bill-doc-menu-item is-wip" role="menuitem" disabled title="En desarrollo">Duplicar como borrador [WIP]</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bill-issuer-layout">
                    <div class="bill-issuer-info">
                        <div class="bill-issuer-name"><?php echo esc_html($issuer['legal_name'] ?? '—'); ?></div>
                        <div class="bill-issuer-line"><?php echo esc_html($addr_bits ? implode(', ', $addr_bits) : '—'); ?></div>
                        <div class="bill-issuer-line"><?php echo esc_html($issuer['activity'] ?? '—'); ?></div>
                        <div class="bill-issuer-line">Teléfono: <?php echo esc_html($issuer['phone'] ?? '—'); ?></div>
                    </div>
                    <div class="bill-draft-badge-card bill-doc-badge">
                        <div class="bill-draft-rut">ID Tributario <strong><?php echo esc_html($issuer_rut !== '' ? $issuer_rut : '—'); ?></strong></div>
                        <div class="bill-draft-type" id="bd-type">—</div>
                        <div class="bill-draft-folio">N° <span id="bd-folio">—</span></div>
                        <div class="bill-doc-closed">
                            <svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6z"/></svg>
                            <span>CERRADO</span>
                        </div>
                    </div>
                </div>

                <div class="bill-grid bill-grid-4 bill-draft-meta">
                    <div class="bill-field"><span>Fecha emisión</span><div class="bill-static-value" id="bd-issue-date">—</div></div>
                    <div class="bill-field"><span>Fecha de vencimiento</span><div class="bill-static-value" id="bd-due-date">—</div></div>
                    <div class="bill-field"><span>Condiciones de pago</span><div class="bill-static-value" id="bd-payment">—</div></div>
                    <div class="bill-field"><span>Ingresado por</span><div class="bill-static-value" id="bd-user">—</div></div>
                    <div class="bill-field"><span>Fecha cierre documento</span><div class="bill-static-value" id="bd-closed-at">—</div></div>
                    <div class="bill-field"><span>Vendedor</span><div class="bill-static-value" id="bd-seller">—</div></div>
                    <div class="bill-field"><span>Centro de costo</span><div class="bill-static-value">0001 - No clasificado</div></div>
                    <div class="bill-field">
                        <span>Estado envío SII</span>
                        <div class="bill-static-with-action">
                            <div class="bill-static-value" id="bd-sii">—</div>
                            <span class="bill-static-action bill-doc-sii-icon" id="bd-sii-icon" aria-hidden="true"></span>
                        </div>
                    </div>
                    <div class="bill-field"><span>Estado de venta</span><div class="bill-static-value" id="bd-sale-state">—</div></div>
                </div>
            </div>

            <div class="bill-card bill-step2-receiver-card" id="bd-receiver" hidden>
                <div class="bill-card-head">
                    <h2>RECEPTOR</h2>
                </div>
                <div class="bill-grid bill-grid-4 bill-draft-meta">
                    <div class="bill-field"><span>RUT</span><div class="bill-static-value" id="bd-recv-rut">—</div></div>
                    <div class="bill-field"><span>Razón social</span><div class="bill-static-value" id="bd-recv-name">—</div></div>
                    <div class="bill-field">
                        <span>Dirección</span>
                        <div class="bill-static-with-action">
                            <div class="bill-static-value" id="bd-recv-address">—</div>
                            <a class="bill-static-action" id="bd-recv-map" href="#" target="_blank" rel="noopener noreferrer" title="Ver en mapa" aria-label="Ver dirección en mapa" aria-disabled="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>
                            </a>
                        </div>
                    </div>
                    <div class="bill-field"><span>Comuna</span><div class="bill-static-value" id="bd-recv-comuna">—</div></div>
                    <div class="bill-field"><span>Ciudad</span><div class="bill-static-value" id="bd-recv-city">—</div></div>
                    <div class="bill-field"><span>Giro</span><div class="bill-static-value" id="bd-recv-giro">—</div></div>
                    <div class="bill-field"><span>Teléfono</span><div class="bill-static-value" id="bd-recv-phone">—</div></div>
                </div>
            </div>

            <div class="bill-card bill-tabs-card">
                <nav class="bill-tabs" id="bd-tabs" role="tablist">
                    <button type="button" class="bill-tab is-active" data-tab="detalles" role="tab" aria-selected="true">Detalles</button>
                    <button type="button" class="bill-tab" data-tab="referencias" role="tab" aria-selected="false">Referencias</button>
                    <button type="button" class="bill-tab" data-tab="pagos" role="tab" aria-selected="false">$ Pagos</button>
                </nav>

                <div class="bill-tab-panel" data-panel="detalles">
                    <p class="bill-hint" id="bd-lines-source" hidden></p>
                    <div class="cq-table-wrap bill-cq-table-wrap">
                        <table class="cq-table bill-cq-table">
                            <thead>
                                <tr>
                                    <th scope="col">Detalle</th>
                                    <th scope="col" class="cq-num">Cantidad</th>
                                    <th scope="col" class="cq-num">Precio unitario</th>
                                    <th scope="col" class="cq-num">Dscto</th>
                                    <th scope="col" class="cq-num">Total bruto</th>
                                </tr>
                            </thead>
                            <tbody id="bd-lines-body"></tbody>
                        </table>
                    </div>
                    <p id="bd-lines-empty" class="cq-empty" hidden>No hay detalle disponible para este documento.</p>
                    <div class="bill-boleta-foot">
                        <span></span>
                        <div class="bill-totals bill-totals-boleta">
                            <div><span>Neto</span><strong id="bd-net">$0</strong></div>
                            <div><span>Exento</span><strong id="bd-exempt">$0</strong></div>
                            <div><span>IVA</span><strong id="bd-iva">$0</strong></div>
                            <div class="bill-tot-grand"><span>Total</span><strong id="bd-total">$0</strong></div>
                        </div>
                    </div>
                </div>

                <div class="bill-tab-panel" data-panel="referencias" hidden>
                    <div class="bill-refs-head">
                        <h3>Referencias</h3>
                    </div>
                    <p class="bill-refs-subtitle">Referenciado por</p>
                    <div class="bill-table-wrap bill-refs-table-wrap">
                        <table class="bill-refs-table">
                            <thead>
                                <tr>
                                    <th>Tipo de Operación</th>
                                    <th>Razón</th>
                                    <th>Tipo de Documento</th>
                                    <th>Folio</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="bd-refs"></tbody>
                        </table>
                    </div>
                </div>

                <div class="bill-tab-panel" data-panel="pagos" hidden>
                    <div class="bill-pagos-actions">
                        <button type="button" class="bill-btn bill-btn-success" id="bd-pago-add">Agregar cobro</button>
                        <button type="button" class="bill-btn bill-btn-wip" disabled title="En desarrollo">Comprobante de pago [WIP]</button>
                    </div>
                    <div class="bill-table-wrap">
                        <table class="bill-table">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Fecha</th>
                                    <th>Método</th>
                                    <th>Caja</th>
                                    <th>Doc Num</th>
                                    <th>Doc Tit</th>
                                    <th>Banco Doc</th>
                                    <th>Detalles</th>
                                    <th>Cobro/Pagos</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="bd-pagos-body"></tbody>
                        </table>
                    </div>
                    <div class="bill-pagos-totals">
                        <span>Total cobros: <strong id="bd-pagos-cobros">$0</strong></span>
                        <span>Total Pagos: <strong id="bd-pagos-pagos">$0</strong></span>
                        <span>Monto impago: <strong id="bd-pagos-impago">$0</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="bill-email-modal" class="riverso-bill bill-modal-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="bill-email-title">
    <div class="bill-modal" role="document">
        <div class="bill-modal-header">
            <h3 id="bill-email-title">Enviar por correo</h3>
            <button type="button" class="bill-modal-x" id="bill-email-close" aria-label="Cerrar">×</button>
        </div>
        <div class="bill-modal-body">
            <p id="bill-email-doc-label" class="bill-hint"></p>
            <div id="bill-email-widget"></div>
        </div>
        <div class="bill-modal-footer">
            <button type="button" class="bill-btn bill-btn-secondary" id="bill-email-cancel">Cancelar</button>
            <button type="button" class="bill-btn bill-btn-primary" id="bill-email-send">Enviar</button>
        </div>
    </div>
</div>

<div id="bd-pago-modal" class="riverso-bill bill-modal-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="bd-pago-title">
    <div class="bill-modal bill-modal-lg" role="document">
        <div class="bill-modal-header">
            <h3 id="bd-pago-title">Registrar pago</h3>
            <button type="button" class="bill-modal-x" id="bd-pago-close" aria-label="Cerrar">×</button>
        </div>
        <div class="bill-modal-body">
            <div class="bill-grid bill-grid-2">
                <label class="bill-field">
                    <span>Fecha (*)</span>
                    <input type="date" id="bd-pago-fecha" value="<?php echo esc_attr($riverso_billing['todayDate'] ?? ''); ?>">
                </label>
                <label class="bill-field">
                    <span>Caja (*)</span>
                    <select id="bd-pago-caja">
                        <option value="">Cargando cajas…</option>
                    </select>
                </label>
                <label class="bill-field">
                    <span>Monto a pagar</span>
                    <input type="text" id="bd-pago-due" readonly>
                </label>
                <label class="bill-field">
                    <span>Método de pago (*)</span>
                    <select id="bd-pago-method"></select>
                </label>
                <label class="bill-field">
                    <span>Monto pagado (*)</span>
                    <input type="number" id="bd-pago-paid" min="0" step="1" value="">
                </label>
                <label class="bill-field">
                    <span>Vuelto</span>
                    <input type="text" id="bd-pago-vuelto" readonly value="0">
                </label>
            </div>
            <div id="bd-pago-cheque" class="bill-grid bill-grid-3" hidden>
                <label class="bill-field">
                    <span>Doc Num (*)</span>
                    <input type="text" id="bd-pago-cheque-num">
                </label>
                <label class="bill-field">
                    <span>Doc Tit (*)</span>
                    <input type="text" id="bd-pago-cheque-tit">
                </label>
                <label class="bill-field">
                    <span>Banco Doc (*)</span>
                    <input type="text" id="bd-pago-cheque-banco">
                </label>
            </div>
            <label class="bill-field">
                <span>Observaciones</span>
                <textarea id="bd-pago-notes" rows="3"></textarea>
            </label>
            <p class="bill-hint bill-doc-modal-error" id="bd-pago-error" hidden></p>
        </div>
        <div class="bill-modal-footer">
            <button type="button" class="bill-btn bill-btn-danger" id="bd-pago-cancel">Anular</button>
            <button type="button" class="bill-btn bill-btn-success" id="bd-pago-save">Registrar pago</button>
        </div>
    </div>
</div>

<div id="bd-pago-delete-modal" class="riverso-bill bill-modal-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="bd-pago-delete-title" aria-describedby="bd-pago-delete-msg">
    <div class="bill-modal bill-modal-confirm" role="document">
        <div class="bill-modal-header bill-confirm-header">
            <span class="bill-confirm-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <h3 id="bd-pago-delete-title">BORRAR PAGO</h3>
        </div>
        <div class="bill-modal-body">
            <p id="bd-pago-delete-msg"></p>
            <p class="bill-hint">Se revertirá el movimiento en la caja. Si el pago ya está en FACTO, se creará una tarea para borrarlo allá.</p>
        </div>
        <div class="bill-modal-footer">
            <button type="button" class="bill-btn bill-btn-secondary" id="bd-pago-delete-cancel">Cancelar</button>
            <button type="button" class="bill-btn bill-btn-danger" id="bd-pago-delete-ok">Borrar</button>
        </div>
    </div>
</div>

<script>
window.RIVERSO_BILLING = <?php echo riverso_pos_billing_json($riverso_billing); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/billing-emails.js?ver=<?php echo htmlspecialchars($js_emails_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/billing-document.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
