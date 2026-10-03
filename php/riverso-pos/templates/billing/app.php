<?php
/**
 * App Facturación · Emitir (portal).
 *
 * @var array<string, mixed> $riverso_billing
 */
$surface = (isset($riverso_billing['surface']) && $riverso_billing['surface'] === 'admin') ? 'admin' : 'portal';
$asset_base = rtrim((string) (isset($riverso_billing['assetBase']) ? $riverso_billing['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/billing.js';
$js_lines_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/billing-lines.js';
$css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/billing.css';
$css_cq_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/customer-quotes.css';
$js_ver = (is_string($js_path) && is_file($js_path)) ? (string) filemtime($js_path) : $version;
$js_lines_ver = (is_string($js_lines_path) && is_file($js_lines_path)) ? (string) filemtime($js_lines_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;
$css_cq_ver = (is_string($css_cq_path) && is_file($css_cq_path)) ? (string) filemtime($css_cq_path) : $version;
$issuer = isset($riverso_billing['issuer']['issuer']) && is_array($riverso_billing['issuer']['issuer'])
    ? $riverso_billing['issuer']['issuer']
    : [];
$issuer_rut = isset($issuer['tax_id_code']) ? (string) $issuer['tax_id_code'] : '';
$issuer_ok = !empty($riverso_billing['issuer']['ok']);
$comunas = [];
if (!class_exists('Riverso_Customer_Module')) {
    $cm = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'sales/customers/class-customer-module.php';
    if ($cm && file_exists($cm)) {
        require_once $cm;
    }
}
if (class_exists('Riverso_Customer_Module') && method_exists('Riverso_Customer_Module', 'chile_comunas')) {
    $comunas = Riverso_Customer_Module::chile_comunas();
}

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
    <div id="riverso-billing" class="riverso-bill" data-ready="0">

        <div id="bill-alerts" class="bill-alerts" hidden></div>

        <?php if (!$issuer_ok): ?>
        <div class="bill-banner bill-banner-warn">
            Configura los datos del emisor en Ajustes → FACTO (RUT, razón social, dirección, comuna, ciudad, teléfono y giro) antes de emitir.
            <?php if (!empty($riverso_billing['issuer']['missing'])): ?>
            <br>Falta: <?php echo esc_html(implode(', ', $riverso_billing['issuer']['missing'])); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <section id="bill-step-1" class="bill-step">
            <div class="bill-card">
                <div class="bill-card-head">
                    <h2>DATOS DEL DOCUMENTO</h2>
                    <div class="bill-folio-card" id="bill-folio-card" aria-live="polite">
                        <div class="bill-folio-rut">RUT: <strong id="bill-issuer-rut"><?php echo esc_html($issuer_rut !== '' ? $issuer_rut : '—'); ?></strong></div>
                        <div class="bill-folio-type" id="bill-folio-type">BOLETA ELECTRÓNICA</div>
                        <div class="bill-folio-num">N° <span id="bill-folio-est">—</span></div>
                        <div class="bill-folio-note">(DOC NO CREADO - FOLIO ESTIMADO)</div>
                    </div>
                </div>

                <div class="bill-grid bill-grid-3">
                    <label class="bill-field">
                        <span>Tipo de Documento (*)</span>
                        <select id="bill-doc-type">
                            <?php
                            $types = isset($riverso_billing['documentTypes']) && is_array($riverso_billing['documentTypes'])
                                ? $riverso_billing['documentTypes']
                                : [];
                            foreach ($types as $t):
                                $tid = (int) ($t['id'] ?? 0);
                                $enabled = !empty($t['enabled']);
                                $label = (string) ($t['label'] ?? '');
                                if (!empty($t['wip'])) {
                                    $label .= ' [WIP]';
                                }
                            ?>
                            <option value="<?php echo $enabled ? (int) $tid : ''; ?>"
                                <?php echo $tid === 37 ? 'selected' : ''; ?>
                                <?php echo $enabled ? '' : 'disabled'; ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="bill-field bill-field-check">
                        <span>&nbsp;</span>
                        <label class="bill-check">
                            <input type="checkbox" id="bill-folio-auto" checked disabled>
                            Folio automático
                        </label>
                    </label>
                    <label class="bill-field">
                        <span>Fecha emisión (*)</span>
                        <input type="date" id="bill-issue-date" value="<?php echo esc_attr($riverso_billing['todayDate'] ?? ''); ?>">
                    </label>
                </div>

                <div class="bill-grid bill-grid-3">
                    <label class="bill-field">
                        <span>Vendedor</span>
                        <select id="bill-seller" disabled>
                            <option selected>** Sin vendedor **</option>
                        </select>
                    </label>
                    <label class="bill-field">
                        <span>Condiciones de pago</span>
                        <select id="bill-payment">
                            <option value="0" selected>Contado</option>
                            <option value="30">30 días</option>
                            <option value="0,30">50% contado / 50% 30 días</option>
                        </select>
                    </label>
                    <label class="bill-field">
                        <span>Área de negocios / Centro de costo</span>
                        <select id="bill-cost-center" disabled>
                            <option selected>0001 - No clasificado</option>
                        </select>
                    </label>
                </div>

                <p class="bill-note-red" id="bill-cession-note">NOTA: El SII no permite CESIÓN para documentos al CONTADO</p>
                <p class="bill-hint" id="bill-folio-unused" hidden></p>
            </div>

            <div class="bill-card" id="bill-receiver-card" hidden>
                <h2>RECEPTOR</h2>
                <p class="bill-note-red">El RUT 55.555.555-5 es solo para casos especiales; para empresa extranjera emite un documento de exportación.</p>
                <p class="bill-hint">Ingrese una opción para buscar datos de facturación</p>

                <div class="bill-search-row bill-search-row-rut">
                    <label class="bill-field">
                        <span>Tipo de identificación</span>
                        <select id="bill-id-type" disabled>
                            <option selected>RUT Cliente/Proveed</option>
                        </select>
                    </label>
                    <label class="bill-field">
                        <span>RUT Cliente/Proveedor</span>
                        <input type="text" id="bill-recv-rut" placeholder="12.345.678-9" autocomplete="off">
                    </label>
                    <div class="bill-field bill-field-action">
                        <span>&nbsp;</span>
                        <button type="button" class="bill-btn bill-btn-primary" id="bill-rut-search-btn">Buscar RUT</button>
                    </div>
                </div>

                <div class="bill-search-row bill-search-row-name">
                    <label class="bill-field bill-field-grow">
                        <span>Nombre / apodo / razón social</span>
                        <input type="text" id="bill-name-search" placeholder="Buscar por nombre o razón social…" autocomplete="off">
                    </label>
                    <div class="bill-field bill-field-action">
                        <span>&nbsp;</span>
                        <button type="button" class="bill-btn bill-btn-primary" id="bill-name-search-btn">Buscar nombre</button>
                    </div>
                </div>
                <div id="bill-customer-results" class="bill-customer-results" hidden></div>

                <div id="bill-sii-panel" class="bill-sii-panel" hidden>
                    <div class="bill-sii-head">
                        <strong>Actividades económicas vigentes (SII)</strong>
                        <span id="bill-sii-status" class="bill-sii-status"></span>
                    </div>
                    <p class="bill-hint" id="bill-sii-hint">Elige una actividad para rellenar el giro. Si ya emitiste con esa actividad, se autocompleta la dirección.</p>
                    <div id="bill-sii-activities" class="bill-sii-activities"></div>
                </div>

                <input type="hidden" id="bill-customer-id" value="0">
                <input type="hidden" id="bill-recv-activity-code" value="">
                <div class="bill-grid bill-grid-3">
                    <label class="bill-field">
                        <span>RUT (confirmado)</span>
                        <input type="text" id="bill-recv-rut-display" readonly tabindex="-1" class="bill-locked" value="">
                    </label>
                    <label class="bill-field">
                        <span>Razón social</span>
                        <input type="text" id="bill-recv-name" class="bill-locked" readonly autocomplete="organization">
                    </label>
                    <label class="bill-field">
                        <span>Giro</span>
                        <input type="text" id="bill-recv-giro" class="bill-locked" readonly>
                    </label>
                </div>
                <div class="bill-grid bill-grid-3">
                    <label class="bill-field">
                        <span>Dirección</span>
                        <input type="text" id="bill-recv-address" class="bill-locked" readonly>
                    </label>
                    <div class="bill-field bill-field-comuna">
                        <div class="bill-comuna-combo bill-locked" id="bill-comuna-combo" data-locked="1">
                            <button type="button" class="bill-comuna-trigger" id="bill-comuna-trigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                <span class="bill-comuna-label">Comuna</span>
                                <span class="bill-comuna-value" id="bill-comuna-value-text"></span>
                                <span class="bill-comuna-chevron" aria-hidden="true">
                                    <svg width="12" height="8" viewBox="0 0 12 8" fill="none"><path d="M1 1.5L6 6.5L11 1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </button>
                            <input type="hidden" id="bill-recv-comuna" value="">
                            <div class="bill-comuna-dropdown" id="bill-comuna-dropdown" hidden>
                                <input type="text" class="bill-comuna-search" id="bill-comuna-search" placeholder="Buscar comuna…" autocomplete="off" aria-label="Buscar comuna">
                                <ul class="bill-comuna-list" id="bill-comuna-list" role="listbox"></ul>
                            </div>
                        </div>
                        <script type="application/json" id="bill-comunas-data"><?php
                            echo riverso_pos_billing_json(array_values($comunas));
                        ?></script>
                    </div>
                    <label class="bill-field">
                        <span>Ciudad</span>
                        <input type="text" id="bill-recv-city" class="bill-locked" readonly>
                    </label>
                </div>
                <div class="bill-grid bill-grid-2">
                    <label class="bill-field">
                        <span>Teléfono</span>
                        <input type="text" id="bill-recv-phone" class="bill-locked" readonly>
                    </label>
                    <label class="bill-field">
                        <span>Código postal</span>
                        <input type="text" id="bill-recv-postal" class="bill-locked" readonly value="">
                    </label>
                </div>
            </div>

            <div class="bill-step-footer">
                <span class="bill-step-label">Paso 1 de 2</span>
                <button type="button" class="bill-btn bill-btn-primary bill-btn-lg" id="bill-to-step-2">Crear documento y continuar</button>
            </div>
        </section>

        <section id="bill-step-2" class="bill-step" hidden>
            <!-- Factura: detalle simple -->
            <div id="bill-step-2-invoice">
                <div class="bill-card">
                    <div class="bill-card-head">
                        <h2>DETALLE DEL DOCUMENTO</h2>
                        <div class="bill-quote-meta" id="bill-quote-meta" hidden></div>
                    </div>
                    <div class="bill-table-wrap">
                        <table class="bill-table" id="bill-lines-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Descripción</th>
                                    <th>Cant.</th>
                                    <th>P. bruto</th>
                                    <th>Afecto</th>
                                    <th>Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="bill-lines-body"></tbody>
                        </table>
                    </div>
                    <button type="button" class="bill-btn bill-btn-secondary" id="bill-add-line">+ Línea</button>
                    <div class="bill-totals" id="bill-totals">
                        <div><span>Neto</span><strong id="bill-tot-net">$0</strong></div>
                        <div><span>IVA</span><strong id="bill-tot-iva">$0</strong></div>
                        <div class="bill-tot-grand"><span>Total</span><strong id="bill-tot-total">$0</strong></div>
                    </div>
                </div>
                <div class="bill-step-footer bill-step-footer-split">
                    <button type="button" class="bill-btn bill-btn-secondary bill-back-step-1">Volver</button>
                    <span class="bill-step-label">Paso 2 de 2</span>
                    <div class="bill-step-actions">
                        <div class="bill-preview-wrap">
                            <div class="bill-preview-menu" id="bill-preview-menu-invoice" hidden role="menu">
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="pdf">Previsualizar: PDF oficial</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="thermal50">Previsualizar: Formato térmico 50mm</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="family">Previsualizar: Carta por familia</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="product">Previsualizar: Carta por producto</button>
                            </div>
                            <button type="button" class="bill-btn bill-btn-primary bill-preview-toggle" id="bill-preview" aria-haspopup="menu" aria-expanded="false" aria-controls="bill-preview-menu-invoice">
                                <svg class="bill-preview-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                                <span>Previsualizar</span>
                                <span class="bill-preview-caret" aria-hidden="true">▾</span>
                            </button>
                        </div>
                        <button type="button" class="bill-btn bill-btn-primary bill-btn-lg" id="bill-emit">Emitir</button>
                    </div>
                </div>
            </div>

            <!-- Boleta: editor borrador -->
            <div id="bill-step-2-boleta" hidden>
                <div class="bill-card bill-issuer-card">
                    <div class="bill-card-head">
                        <h2>EMISOR Y DATOS DEL DOCUMENTO</h2>
                        <div class="bill-issuer-actions">
                            <button type="button" class="bill-btn bill-btn-secondary" disabled title="WIP">Opciones [WIP]</button>
                            <button type="button" class="bill-btn bill-btn-danger" id="bill-draft-delete" disabled title="WIP">Borrar [WIP]</button>
                        </div>
                    </div>
                    <div class="bill-issuer-layout">
                        <div class="bill-issuer-info">
                            <div class="bill-issuer-name" id="bill-boleta-issuer-name"><?php echo esc_html($issuer['legal_name'] ?? '—'); ?></div>
                            <div class="bill-issuer-line" id="bill-boleta-issuer-addr">
                                <?php
                                $addr_bits = array_filter([
                                    $issuer['address'] ?? '',
                                    $issuer['district'] ?? '',
                                    $issuer['city'] ?? '',
                                ]);
                                echo esc_html($addr_bits ? implode(', ', $addr_bits) : '—');
                                ?>
                            </div>
                            <div class="bill-issuer-line" id="bill-boleta-issuer-giro"><?php echo esc_html($issuer['activity'] ?? '—'); ?></div>
                            <div class="bill-issuer-line">Teléfono: <span id="bill-boleta-issuer-phone"><?php echo esc_html($issuer['phone'] ?? '—'); ?></span></div>
                        </div>
                        <div class="bill-draft-badge-card">
                            <div class="bill-draft-rut">RUT: <strong><?php echo esc_html($issuer_rut !== '' ? $issuer_rut : '—'); ?></strong></div>
                            <div class="bill-draft-type">BOLETA ELECTRÓNICA</div>
                            <div class="bill-draft-banner" id="bill-draft-banner">
                                <span id="bill-draft-banner-text">DOC EN BORRADOR</span>
                            </div>
                            <div class="bill-draft-folio">N° <span id="bill-boleta-folio-est">—</span></div>
                        </div>
                    </div>
                    <div class="bill-grid bill-grid-4 bill-draft-meta">
                        <label class="bill-field">
                            <span>Fecha emisión</span>
                            <input type="date" id="bill-boleta-issue-date" value="<?php echo esc_attr($riverso_billing['todayDate'] ?? ''); ?>">
                        </label>
                        <label class="bill-field">
                            <span>Fecha de vencimiento</span>
                            <input type="date" id="bill-boleta-due-date" value="">
                        </label>
                        <label class="bill-field">
                            <span>Condiciones de pago</span>
                            <select id="bill-boleta-payment">
                                <option value="0" selected>Contado</option>
                                <option value="30">30 días</option>
                            </select>
                        </label>
                        <label class="bill-field">
                            <span>Ingresado por</span>
                            <input type="text" id="bill-boleta-user" value="<?php echo esc_attr($riverso_billing['currentUserName'] ?? ''); ?>" readonly>
                        </label>
                        <label class="bill-field">
                            <span>Fecha cierre documento</span>
                            <input type="text" value="" readonly placeholder="—" class="bill-locked">
                        </label>
                        <label class="bill-field">
                            <span>Vendedor</span>
                            <input type="text" value="" readonly placeholder="[WIP]" class="bill-locked">
                        </label>
                        <label class="bill-field">
                            <span>Centro de costo</span>
                            <input type="text" value="0001 - No clasificado" readonly class="bill-locked">
                        </label>
                        <label class="bill-field">
                            <span>Estado de venta</span>
                            <select id="bill-boleta-sale-state" disabled>
                                <option selected>VENTA: Concretada</option>
                            </select>
                        </label>
                    </div>
                    <input type="hidden" id="bill-draft-id" value="0">
                </div>

                <div class="bill-card bill-tabs-card">
                    <nav class="bill-tabs" id="bill-boleta-tabs" role="tablist">
                        <button type="button" class="bill-tab is-active" data-tab="detalles" role="tab" aria-selected="true">Detalles</button>
                        <button type="button" class="bill-tab" data-tab="referencias" role="tab">Referencias</button>
                        <button type="button" class="bill-tab" data-tab="pagos" role="tab">$ Pagos</button>
                        <button type="button" class="bill-tab" data-tab="comentarios" role="tab">Comentarios</button>
                        <button type="button" class="bill-tab" data-tab="observaciones" role="tab">Observaciones</button>
                        <button type="button" class="bill-tab" data-tab="periodico" role="tab">Servicio Periódico</button>
                    </nav>

                    <div class="bill-tab-panel" id="bill-tab-detalles" data-panel="detalles">
                        <div class="bill-product-search-block">
                            <div class="bill-product-search-head">
                                <label for="bill-product-search">Buscar producto</label>
                                <label class="cq-advanced-toggle" for="bill-advanced">
                                    <input type="checkbox" id="bill-advanced">
                                    <span>Modo avanzado</span>
                                </label>
                            </div>
                            <div class="bill-product-search-row">
                                <input type="search" id="bill-product-search" placeholder="SKU, nombre o código de barras — Enter o + para buscar; + vacío abre lupa" autocomplete="off" enterkeyhint="search">
                                <button type="button" class="bill-btn bill-btn-primary" id="bill-product-add" title="Agregar / búsqueda avanzada si está vacío">+</button>
                                <button type="button" class="bill-btn bill-btn-secondary" id="bill-search-clear" title="Limpiar búsqueda">Limpiar búsqueda</button>
                                <button type="button" class="cq-btn cq-btn-manual" id="bill-product-manual" title="Agregar producto sin SKU">Manual</button>
                                <button type="button" class="bill-btn bill-btn-wip" disabled>Producto o servicio [WIP]</button>
                            </div>
                            <ul id="bill-product-results" class="bill-product-results" hidden></ul>
                        </div>
                        <div class="cq-table-wrap bill-cq-table-wrap">
                            <table class="cq-table bill-cq-table" id="bill-boleta-lines-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Detalle</th>
                                        <th scope="col" class="cq-num">Cantidad</th>
                                        <th scope="col" class="cq-num">Total bruto</th>
                                        <th scope="col" class="cq-num cq-advanced" title="Porcentaje de descuento sobre el bruto de la línea">Dscto precio</th>
                                        <th scope="col" class="cq-num cq-advanced" title="Mismo descuento como % del margen">Dscto margen</th>
                                        <th scope="col" class="cq-num cq-advanced">Utilidad</th>
                                        <th scope="col" class="cq-num cq-advanced" title="Stock en bodega (live)">Stock</th>
                                        <th scope="col">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="bill-boleta-lines-body"></tbody>
                            </table>
                        </div>
                        <p id="bill-lines-empty" class="cq-empty">Agrega productos con la búsqueda o con el botón Manual.</p>
                        <div class="bill-boleta-foot">
                            <button type="button" class="bill-btn bill-btn-wip" disabled>Agregar Descuento / Recargo [WIP]</button>
                            <div class="bill-totals bill-totals-boleta" id="bill-boleta-totals">
                                <div><span>Dscto</span><strong id="bill-boleta-discount">$0</strong></div>
                                <div><span>Margen</span><strong id="bill-boleta-margin">—</strong></div>
                                <div><span>Utilidad</span><strong id="bill-boleta-profit">—</strong></div>
                                <div><span>Neto</span><strong id="bill-boleta-net">$0</strong></div>
                                <div><span>Exento</span><strong id="bill-boleta-exento">$0</strong></div>
                                <div><span>IVA</span><strong id="bill-boleta-iva">$0</strong></div>
                                <div class="bill-tot-grand"><span>Total</span><strong id="bill-boleta-total">$0</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="bill-tab-panel" id="bill-tab-referencias" data-panel="referencias" hidden>
                        <div class="bill-refs-head">
                            <h3>Referencias</h3>
                            <button type="button" class="bill-btn bill-btn-primary" id="bill-ref-add">Agregar referencias</button>
                        </div>
                        <div id="bill-refs-form" class="bill-refs-form" hidden>
                            <div class="bill-grid bill-grid-3">
                                <label class="bill-field">
                                    <span>Tipo</span>
                                    <select id="bill-ref-type">
                                        <option value="quote">Cotización Riverso</option>
                                        <option value="folio">Otro folio</option>
                                    </select>
                                </label>
                                <label class="bill-field" id="bill-ref-quote-wrap">
                                    <span>Buscar cotización</span>
                                    <input type="text" id="bill-ref-quote-q" placeholder="Número de cotización…" autocomplete="off">
                                </label>
                                <label class="bill-field" id="bill-ref-folio-wrap" hidden>
                                    <span>Tipo doc. / Folio</span>
                                    <input type="text" id="bill-ref-folio" placeholder="Ej. Factura 1234" autocomplete="off">
                                </label>
                            </div>
                            <ul id="bill-ref-quote-results" class="bill-product-results" hidden></ul>
                            <div class="bill-step-actions">
                                <button type="button" class="bill-btn bill-btn-secondary" id="bill-ref-cancel">Cancelar</button>
                                <button type="button" class="bill-btn bill-btn-primary" id="bill-ref-save">Agregar</button>
                            </div>
                        </div>
                        <ul id="bill-refs-list" class="bill-refs-list"></ul>
                    </div>

                    <div class="bill-tab-panel" id="bill-tab-pagos" data-panel="pagos" hidden>
                        <div id="bill-pagos-draft-msg" class="bill-pagos-warn">
                            ATENCIÓN: Por favor cierre el documento antes de ingresar pagos asociados
                        </div>
                        <div id="bill-pagos-emitted" hidden>
                            <div class="bill-pagos-actions">
                                <button type="button" class="bill-btn bill-btn-primary" id="bill-pago-add-charge">Agregar cobro</button>
                                <button type="button" class="bill-btn bill-btn-wip" id="bill-pago-comprobante" disabled>Comprobante de pago [WIP]</button>
                            </div>
                            <div class="bill-table-wrap">
                                <table class="bill-table" id="bill-pagos-table">
                                    <thead>
                                        <tr>
                                            <th>Tipo</th>
                                            <th>Fecha</th>
                                            <th>Método</th>
                                            <th>Caja</th>
                                            <th>Detalles</th>
                                            <th>Cobro/Pagos</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bill-pagos-body"></tbody>
                                </table>
                            </div>
                            <div class="bill-pagos-totals" id="bill-pagos-totals">
                                <span>Total cobros: <strong id="bill-pagos-cobros">$0</strong></span>
                                <span>Total Pagos: <strong id="bill-pagos-pagos">$0</strong></span>
                                <span>Monto impago: <strong id="bill-pagos-impago">$0</strong></span>
                            </div>
                        </div>
                    </div>

                    <div class="bill-tab-panel" id="bill-tab-comentarios" data-panel="comentarios" hidden>
                        <p class="bill-hint">Comentarios [WIP]</p>
                    </div>
                    <div class="bill-tab-panel" id="bill-tab-observaciones" data-panel="observaciones" hidden>
                        <p class="bill-hint">Observaciones [WIP]</p>
                    </div>
                    <div class="bill-tab-panel" id="bill-tab-periodico" data-panel="periodico" hidden>
                        <p class="bill-hint">Servicio periódico [WIP]</p>
                    </div>
                </div>

                <div class="bill-step-footer bill-boleta-footer">
                    <button type="button" class="bill-btn bill-btn-secondary bill-back-step-1">Volver</button>
                    <span class="bill-step-label">Paso 2 de 2</span>
                    <div class="bill-step-actions bill-boleta-actions">
                        <button type="button" class="bill-btn bill-btn-primary bill-btn-lg" id="bill-boleta-emit" disabled>Emitir documento [WIP]</button>
                        <div class="bill-preview-wrap">
                            <div class="bill-preview-menu" id="bill-preview-menu-boleta" hidden role="menu">
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="pdf">Previsualizar: PDF oficial</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="thermal50">Previsualizar: Formato térmico 50mm</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="family">Previsualizar: Carta por familia</button>
                                <button type="button" class="bill-preview-item" role="menuitem" data-preview="product">Previsualizar: Carta por producto</button>
                            </div>
                            <button type="button" class="bill-btn bill-btn-primary bill-preview-toggle" id="bill-boleta-preview" aria-haspopup="menu" aria-expanded="false" aria-controls="bill-preview-menu-boleta">
                                <svg class="bill-preview-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                                <span>Previsualizar</span>
                                <span class="bill-preview-caret" aria-hidden="true">▾</span>
                            </button>
                        </div>
                        <button type="button" class="bill-btn bill-btn-secondary" id="bill-draft-save">Guardar borrador</button>
                    </div>
                </div>
            </div>
        </section>

        <section id="bill-done" class="bill-step" hidden>
            <div class="bill-card bill-card-success">
                <h2 id="bill-done-title">Documento emitido</h2>
                <p id="bill-done-msg"></p>
                <p id="bill-done-folio" class="bill-done-folio"></p>
                <div class="bill-step-actions">
                    <a class="bill-btn bill-btn-secondary" id="bill-done-quotes" href="<?php echo esc_url($riverso_billing['quotesUrl'] ?? '#'); ?>">Ir a cotizaciones</a>
                    <button type="button" class="bill-btn bill-btn-primary" id="bill-done-new">Emitir otro</button>
                </div>
            </div>
        </section>
    </div>

    <div id="bill-missing-modal" class="bill-modal-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="bill-missing-title">
        <div class="bill-modal" role="document">
            <div class="bill-modal-header">
                <h3 id="bill-missing-title">Datos incompletos</h3>
            </div>
            <div class="bill-modal-body">
                <p id="bill-missing-msg"></p>
                <ul id="bill-missing-list" class="bill-missing-list" hidden></ul>
            </div>
            <div class="bill-modal-footer">
                <button type="button" class="bill-btn bill-btn-primary" id="bill-missing-ok">Entendido</button>
            </div>
        </div>
    </div>

    <div id="bill-pago-modal" class="bill-modal-overlay" hidden aria-hidden="true" role="dialog" aria-modal="true">
        <div class="bill-modal bill-modal-lg" role="document">
            <div class="bill-modal-header">
                <h3>Registrar pago</h3>
                <button type="button" class="bill-modal-x" id="bill-pago-close" aria-label="Cerrar">×</button>
            </div>
            <div class="bill-modal-body">
                <div class="bill-grid bill-grid-2">
                    <label class="bill-field">
                        <span>Fecha (*)</span>
                        <input type="date" id="bill-pago-fecha" value="<?php echo esc_attr($riverso_billing['todayDate'] ?? ''); ?>">
                    </label>
                    <label class="bill-field">
                        <span>Caja (*)</span>
                        <select id="bill-pago-caja">
                            <option value="Efectivo">Efectivo</option>
                            <option value="Tarjeta">Tarjeta</option>
                            <option value="Transferencia">Transferencia</option>
                        </select>
                    </label>
                    <label class="bill-field">
                        <span>Monto a pagar</span>
                        <input type="text" id="bill-pago-due" readonly>
                    </label>
                    <label class="bill-field">
                        <span>Método de pago (*)</span>
                        <select id="bill-pago-method">
                            <option value="Efectivo">Efectivo</option>
                            <option value="Mercado Pago">Mercado Pago</option>
                            <option value="Transferencia">Transferencia</option>
                            <option value="Tarjeta">Tarjeta</option>
                        </select>
                    </label>
                    <label class="bill-field">
                        <span>Monto pagado (*)</span>
                        <input type="number" id="bill-pago-paid" min="0" step="1" value="">
                    </label>
                    <label class="bill-field">
                        <span>Vuelto</span>
                        <input type="text" id="bill-pago-vuelto" readonly value="0">
                    </label>
                </div>
                <label class="bill-field">
                    <span>Observaciones</span>
                    <textarea id="bill-pago-notes" rows="3"></textarea>
                </label>
            </div>
            <div class="bill-modal-footer">
                <button type="button" class="bill-btn bill-btn-danger" id="bill-pago-cancel">Anular</button>
                <button type="button" class="bill-btn bill-btn-primary" id="bill-pago-save">Registrar pago</button>
            </div>
        </div>
    </div>
</div>

<div id="bill-advanced-modal" class="cq-modal" hidden aria-hidden="true">
    <div class="cq-modal-backdrop" data-bill-adv-close="1"></div>
    <div class="cq-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bill-adv-title">
        <header class="cq-modal-head">
            <h3 id="bill-adv-title">Búsqueda avanzada</h3>
            <button type="button" class="cq-modal-close" aria-label="Cerrar" data-bill-adv-close="1">×</button>
        </header>
        <div class="cq-modal-body">
            <div class="cq-scope-chips" role="tablist" aria-label="Alcance de búsqueda">
                <button type="button" class="cq-chip is-active" data-scope="todo" role="tab" aria-selected="true">Todo</button>
                <button type="button" class="cq-chip" data-scope="descripcion" role="tab" aria-selected="false">Descripción</button>
                <button type="button" class="cq-chip" data-scope="codigos" role="tab" aria-selected="false">Códigos</button>
            </div>
            <div class="cq-contains-filter">
                <label class="cq-contains-label" for="bill-adv-contains">Contiene palabra:</label>
                <div class="cq-contains-row">
                    <input type="text" id="bill-adv-contains" autocomplete="off" placeholder="Escribe y Enter…" enterkeyhint="done">
                    <button type="button" class="cq-btn" id="bill-adv-contains-add">Agregar</button>
                </div>
                <div id="bill-adv-contains-tags" class="cq-contains-tags" aria-live="polite"></div>
            </div>
            <div class="cq-search-row">
                <input type="search" id="bill-adv-q" autocomplete="off" placeholder="Buscar…" enterkeyhint="search">
                <button type="button" class="cq-btn cq-btn-primary" id="bill-adv-search-btn">Buscar</button>
            </div>
            <p id="bill-adv-hint" class="cq-modal-hint" role="status"></p>
            <ul id="bill-adv-results" class="cq-results cq-modal-results"></ul>
        </div>
    </div>
</div>

<div id="bill-family-modal" class="cq-modal" hidden aria-hidden="true">
    <div class="cq-modal-backdrop" data-bill-family-close="1"></div>
    <div class="cq-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bill-family-title">
        <header class="cq-modal-head">
            <h3 id="bill-family-title">Cambiar presentación</h3>
            <button type="button" class="cq-modal-close" aria-label="Cerrar" data-bill-family-close="1">×</button>
        </header>
        <div class="cq-modal-body">
            <p id="bill-family-hint" class="cq-modal-hint" role="status"></p>
            <ul id="bill-family-members" class="cq-results cq-modal-results"></ul>
        </div>
        <footer class="cq-modal-foot cq-family-modal-foot">
            <button type="button" class="cq-btn cq-btn-primary" id="bill-family-open-admin" disabled title="Abrir editor de familia en Categorías">
                Abrir Familia
            </button>
        </footer>
    </div>
</div>

<div id="bill-line-modal" class="cq-modal" hidden aria-hidden="true">
    <div class="cq-modal-backdrop" data-bill-line-close="1"></div>
    <div class="cq-modal-dialog cq-line-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bill-line-title">
        <header class="cq-modal-head">
            <div>
                <h3 id="bill-line-title">Editar línea</h3>
                <p id="bill-line-subtitle" class="cq-line-modal-sub"></p>
            </div>
            <button type="button" class="cq-modal-close" aria-label="Cerrar" data-bill-line-close="1">×</button>
        </header>
        <div class="cq-modal-body cq-line-modal-body">
            <div class="cq-line-tax-toggle" role="group" aria-label="Visualización Neto o Bruto">
                <button type="button" class="cq-tax-btn" id="bill-line-tax-neto" data-tax-view="neto">Neto</button>
                <button type="button" class="cq-tax-btn is-active" id="bill-line-tax-bruto" data-tax-view="bruto">Bruto</button>
            </div>
            <p id="bill-line-family-note" class="cq-modal-hint" hidden></p>
            <div class="cq-line-price-modes" id="bill-line-price-modes">
                <label class="cq-line-mode-check" id="bill-line-mode-manual-wrap">
                    <input type="checkbox" id="bill-line-mode-manual">
                    <span>Precio manual</span>
                </label>
                <div class="cq-line-mode-radios" id="bill-line-mode-radios" hidden>
                    <label><input type="radio" name="bill-line-mode" value="auto"> Usar regla</label>
                    <label><input type="radio" name="bill-line-mode" value="ref"> Cambiar precio de referencia (P)</label>
                    <label><input type="radio" name="bill-line-mode" value="manual"> Ignorar regla — precio final</label>
                </div>
            </div>
            <p id="bill-line-rule-info" class="cq-line-rule-info" hidden></p>
            <div class="cq-line-fields">
                <label class="cq-float-field" id="bill-line-qty-field">
                    <span>Cantidad</span>
                    <input type="text" id="bill-line-qty" inputmode="decimal" autocomplete="off">
                    <em id="bill-line-qty-hint" class="cq-float-hint"></em>
                </label>
                <label class="cq-float-field" id="bill-line-pref-field" hidden>
                    <span>Precio de referencia (P)</span>
                    <input type="text" id="bill-line-pref" inputmode="decimal" autocomplete="off">
                    <em id="bill-line-pref-hint" class="cq-float-hint"></em>
                </label>
                <label class="cq-float-field" id="bill-line-unit-field">
                    <span id="bill-line-unit-label">Precio unitario</span>
                    <input type="text" id="bill-line-unit" inputmode="decimal" autocomplete="off">
                    <em id="bill-line-unit-hint" class="cq-float-hint"></em>
                </label>
                <label class="cq-float-field" id="bill-line-total-field">
                    <span id="bill-line-total-label">Precio total</span>
                    <input type="text" id="bill-line-total" inputmode="decimal" autocomplete="off">
                    <em id="bill-line-total-hint" class="cq-float-hint"></em>
                </label>
                <label class="cq-float-field">
                    <span>Descuento precio %</span>
                    <input type="text" id="bill-line-price-discount" inputmode="decimal" autocomplete="off">
                </label>
                <label class="cq-float-field" id="bill-line-margin-field">
                    <span>
                        Descuento margen %
                        <abbr id="bill-line-margin-help" class="cq-help-tip" title="Costo no encontrado" hidden>?</abbr>
                    </span>
                    <input type="text" id="bill-line-margin-discount" inputmode="decimal" autocomplete="off">
                    <em id="bill-line-margin-na" class="cq-margin-na-text" hidden>—</em>
                </label>
                <label class="cq-float-field">
                    <span>Monto de descuento</span>
                    <input type="text" id="bill-line-discount-amount" inputmode="decimal" autocomplete="off">
                </label>
                <label class="cq-float-field cq-manual-full">
                    <span>Monto final</span>
                    <input type="text" id="bill-line-final-amount" readonly tabindex="-1" autocomplete="off">
                </label>
            </div>
            <p id="bill-line-discount-hint" class="cq-line-preview" role="status"></p>
            <p id="bill-line-preview" class="cq-line-preview" role="status"></p>
        </div>
        <footer class="cq-modal-foot cq-line-modal-foot">
            <button type="button" class="cq-btn" data-bill-line-close="1">Cancelar</button>
            <button type="button" class="cq-btn cq-btn-save-line" id="bill-line-save">Guardar cambios</button>
        </footer>
    </div>
</div>

<div id="bill-manual-modal" class="cq-modal" hidden aria-hidden="true">
    <div class="cq-modal-backdrop" data-bill-manual-close="1"></div>
    <div class="cq-modal-dialog cq-manual-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="bill-manual-title">
        <header class="cq-modal-head">
            <h3 id="bill-manual-title">Agregar detalle</h3>
            <button type="button" class="cq-modal-close" id="bill-manual-close" aria-label="Cerrar" data-bill-manual-close="1">×</button>
        </header>
        <div class="cq-modal-body cq-manual-modal-body">
            <label class="cq-float-field cq-manual-full">
                <span>Afecto a IVA (*)</span>
                <select id="bill-manual-iva" disabled>
                    <option value="si" selected>SI</option>
                </select>
            </label>
            <label class="cq-float-field cq-manual-full">
                <span>Impuesto adicional</span>
                <select id="bill-manual-extra-tax" disabled>
                    <option value="" selected>** Ninguno **</option>
                </select>
            </label>
            <div class="cq-manual-row">
                <label class="cq-float-field">
                    <span>Cantidad (*)</span>
                    <input type="text" id="bill-manual-qty" inputmode="decimal" autocomplete="off" value="1">
                </label>
                <label class="cq-float-field">
                    <span>Unidad</span>
                    <select id="bill-manual-unit">
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
                    <input type="text" id="bill-manual-concepto" autocomplete="off" maxlength="500">
                </label>
                <button type="button" class="cq-btn cq-btn-desc-larga" id="bill-manual-desc-toggle" title="Descripción larga" aria-expanded="false" aria-controls="bill-manual-desc-wrap">Desc. Larga</button>
            </div>
            <div id="bill-manual-desc-wrap" class="cq-manual-desc-wrap" hidden>
                <label class="cq-float-field cq-manual-full">
                    <span>Descripción larga</span>
                    <textarea id="bill-manual-desc-larga" rows="3" maxlength="500"></textarea>
                </label>
            </div>
            <label class="cq-float-field cq-manual-full">
                <span>Precio unitario</span>
                <input type="text" id="bill-manual-unit-neto" inputmode="decimal" autocomplete="off" value="0">
            </label>
            <label class="cq-float-field cq-manual-full">
                <span>Precio unitario con impuestos</span>
                <input type="text" id="bill-manual-unit-bruto" inputmode="decimal" autocomplete="off" value="0">
            </label>
            <div class="cq-manual-row">
                <label class="cq-float-field">
                    <span>Descuento/Recargo</span>
                    <select id="bill-manual-adj-type">
                        <option value="descuento" selected>Descuento</option>
                        <option value="recargo">Recargo</option>
                    </select>
                </label>
                <label class="cq-float-field cq-manual-pct-field">
                    <span>Porcentaje</span>
                    <div class="cq-manual-pct-wrap">
                        <input type="text" id="bill-manual-pct" inputmode="decimal" autocomplete="off" value="0">
                        <span class="cq-manual-pct-suffix" aria-hidden="true">%</span>
                    </div>
                </label>
            </div>
            <label class="cq-float-field cq-manual-full">
                <span>Precio total</span>
                <input type="text" id="bill-manual-total-neto" inputmode="decimal" autocomplete="off" value="0" readonly>
            </label>
            <label class="cq-float-field cq-manual-full">
                <span>Precio total con impuestos</span>
                <input type="text" id="bill-manual-total-bruto" inputmode="decimal" autocomplete="off" value="0" readonly>
            </label>
            <p id="bill-manual-hint" class="cq-modal-hint" role="status"></p>
        </div>
        <footer class="cq-modal-foot cq-manual-modal-foot">
            <button type="button" class="cq-btn cq-btn-manual-add" id="bill-manual-add">Agregar</button>
        </footer>
    </div>
</div>

<script>
window.RIVERSO_BILLING = <?php echo riverso_pos_billing_json($riverso_billing); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/billing-lines.js?ver=<?php echo htmlspecialchars($js_lines_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/billing.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
