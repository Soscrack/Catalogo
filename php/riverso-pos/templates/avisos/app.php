<?php
/**
 * App Avisos de compra: avisar que falta → bandeja por proveedor → ingresado.
 *
 * @var array<string, mixed> $riverso_av
 */
if (!defined('ABSPATH')) {
    exit;
}

$asset_base = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets';
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/avisos.js';
$css_path = RIVERSO_POS_PLUGIN_DIR . 'assets/css/avisos.css';
$js_ver = is_file($js_path) ? (string) filemtime($js_path) : $version;
$scanner_path = RIVERSO_POS_PLUGIN_DIR . 'assets/js/barcode-scanner.js';
$scanner_ver = is_file($scanner_path) ? (string) filemtime($scanner_path) : $version;
$css_ver = is_file($css_path) ? (string) filemtime($css_path) : $version;
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$can_manage = !empty($riverso_av['canManage']);
?>
<link rel="stylesheet" href="<?php echo esc_url($asset_base . '/css/avisos.css?ver=' . $css_ver); ?>">

<div class="wrap riverso-av-wrap">
    <div id="riverso-av" class="riverso-av">

        <div class="av-tabs" role="tablist">
            <button type="button" class="av-tab" data-view="avisar">Avisar</button>
            <button type="button" class="av-tab" data-view="mios">Mis avisos</button>
            <?php if ($can_manage): ?>
            <button type="button" class="av-tab" data-view="bandeja">Por ingresar <span class="av-badge" id="av-open-count" hidden></span></button>
            <?php endif; ?>
        </div>

        <!-- Avisar que falta -->
        <section id="av-view-avisar" hidden>
            <div class="av-search">
                <input type="text" id="av-q" placeholder="Código, SKU o nombre" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="search">
                <button type="button" class="av-btn" id="av-search-btn">Buscar</button>
            </div>
            <div class="av-search-actions">
                <button type="button" class="av-btn av-btn-big" id="av-scan-btn">Escanear código de barras</button>
                <button type="button" class="av-btn av-btn-big av-btn-ghost" id="av-unknown-btn">No tiene código</button>
            </div>

            <div class="av-scanner" id="av-scanner" hidden>
                <div class="av-scanner-view">
                    <video id="av-video" playsinline muted></video>
                    <div class="av-scanner-frame" aria-hidden="true"></div>
                </div>
                <div class="av-scanner-status" id="av-scan-status" aria-live="polite"></div>
                <div class="av-scanner-choices" id="av-scan-choices" hidden></div>
                <button type="button" class="av-btn av-btn-ghost" id="av-scan-close">Cerrar cámara</button>
            </div>

            <div class="av-status" id="av-status" aria-live="polite"></div>
            <div class="av-candidates" id="av-candidates"></div>

            <form class="av-form" id="av-form" hidden novalidate>
                <div class="av-product" id="av-product"></div>
                <div id="av-dupes"></div>

                <div class="av-field" id="av-texto-field" hidden>
                    <label for="av-texto">¿Qué falta?</label>
                    <input type="text" id="av-texto" maxlength="255" placeholder="Ej: hilo 1/4 métrico" autocomplete="off">
                </div>

                <div class="av-field">
                    <label for="av-supplier">Proveedor</label>
                    <select id="av-supplier"></select>
                </div>

                <label class="av-check">
                    <input type="checkbox" id="av-sin-stock"> No queda nada
                </label>

                <div class="av-field">
                    <label for="av-cantidad">¿Cuánto pedir? <span class="av-sub">Opcional: lo puede definir quien arma el pedido.</span></label>
                    <div class="av-qty-row">
                        <input type="text" id="av-cantidad" inputmode="decimal" autocomplete="off" placeholder="Cantidad">
                        <select id="av-unidad" aria-label="Unidad"></select>
                    </div>
                    <div class="av-sub" id="av-equiv"></div>
                </div>

                <div class="av-field">
                    <input type="file" id="av-foto-input" accept="image/*" capture="environment" hidden>
                    <button type="button" class="av-btn av-btn-ghost" id="av-foto-btn">Tomar foto</button>
                    <div class="av-foto-preview" id="av-foto-preview" hidden></div>
                </div>

                <div class="av-field">
                    <label for="av-nota">Nota <span class="av-sub">Opcional</span></label>
                    <textarea id="av-nota" rows="2" maxlength="1000"></textarea>
                </div>

                <div class="av-form-error" id="av-form-error" role="alert" hidden></div>
                <div class="av-actions">
                    <button type="button" class="av-btn av-btn-ghost" id="av-cancel">Cancelar</button>
                    <button type="submit" class="av-btn av-btn-big" id="av-submit">Enviar aviso</button>
                </div>
            </form>
        </section>

        <!-- Mis avisos -->
        <section id="av-view-mios" hidden>
            <p class="av-hint">Lo que avisaste y en qué quedó.</p>
            <div id="av-mine-list"></div>
        </section>

        <?php if ($can_manage): ?>
        <!-- Bandeja de quien arma el pedido -->
        <section id="av-view-bandeja" hidden>
            <div class="av-chips" id="av-tray-filters">
                <button type="button" class="av-chip" data-estado="abiertos">Abiertos <span></span></button>
                <button type="button" class="av-chip" data-estado="sin_identificar">Sin identificar <span></span></button>
                <button type="button" class="av-chip" data-estado="ingresados">Ingresados</button>
                <button type="button" class="av-chip" data-estado="descartados">Descartados</button>
            </div>
            <div class="av-toolbar">
                <input type="search" id="av-tray-search" placeholder="Buscar producto, código o texto" autocomplete="off">
            </div>
            <div id="av-tray"></div>
        </section>
        <?php endif; ?>

        <div class="av-toast" id="av-toast" role="status" hidden></div>

        <div class="av-modal-overlay" id="av-modal" hidden>
            <div class="av-modal" role="dialog" aria-modal="true" aria-labelledby="av-modal-title">
                <h2 id="av-modal-title"></h2>
                <div id="av-modal-body"></div>
                <div class="av-modal-error" id="av-modal-error" role="alert" hidden></div>
                <div class="av-actions">
                    <button type="button" class="av-btn av-btn-ghost" id="av-modal-cancel">Cancelar</button>
                    <button type="button" class="av-btn" id="av-modal-ok">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.riversoAvisos = <?php echo wp_json_encode($riverso_av, $json_flags); ?>;
</script>
<script src="<?php echo esc_url($asset_base . '/js/barcode-scanner.js?ver=' . $scanner_ver); ?>"></script>
<script src="<?php echo esc_url($asset_base . '/js/avisos.js?ver=' . $js_ver); ?>"></script>
