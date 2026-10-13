<?php
/**
 * App Manejo de Caja (Información de cajas).
 *
 * @var array<string, mixed> $riverso_cash
 */
if (!defined('ABSPATH') && empty($riverso_cash['standalone'])) {
    // En WP el módulo define ABSPATH.
}

$surface = (isset($riverso_cash['surface']) && $riverso_cash['surface'] === 'portal') ? 'portal' : 'admin';
$asset_base = rtrim((string) (isset($riverso_cash['assetBase']) ? $riverso_cash['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/cash.js';
$css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/cash.css';
$js_ver = (is_string($js_path) && is_file($js_path)) ? (string) filemtime($js_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;

if (!function_exists('riverso_pos_cash_json')) {
    function riverso_pos_cash_json($data) {
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
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/cash.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">

<div class="wrap riverso-cash-wrap<?php echo $surface === 'portal' ? ' riverso-cash-wrap--portal' : ''; ?>">
    <div id="riverso-cash" class="riverso-cash" data-view="manejo" data-surface="<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>">

        <div class="cash-top">
            <h1>Información de cajas</h1>
        </div>

        <div id="cash-pending-box" class="cash-alert" hidden></div>

        <div class="cash-toolbar">
            <label>
                Mostrar
                <select id="cash-page-size">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                registros
            </label>
            <label>
                Buscar:
                <input type="search" id="cash-search" placeholder="" autocomplete="off">
            </label>
        </div>

        <div class="cash-table-wrap">
            <table class="cash-table" id="cash-manejo-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Cuenta de efectivo</th>
                        <th>Documentos recibidos</th>
                        <th>Documentos emitidos</th>
                        <th>Total</th>
                        <th>Transferencia pendiente</th>
                        <th>Estado caja</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody id="cash-manejo-body">
                    <tr><td colspan="8" class="cash-empty">Cargando…</td></tr>
                </tbody>
                <tfoot id="cash-manejo-foot"></tfoot>
            </table>
        </div>
        <div class="cash-pager" id="cash-manejo-pager"></div>
    </div>
</div>

<!-- Modal Apertura / Cierre -->
<div id="cash-arqueo-modal" class="cash-modal-overlay" hidden aria-hidden="true">
    <div class="cash-modal" role="dialog" aria-modal="true" aria-labelledby="cash-arqueo-title">
        <div class="cash-modal-header">
            <h3 id="cash-arqueo-title">Apertura / Cierre caja</h3>
            <button type="button" class="cash-modal-x" id="cash-arqueo-close" aria-label="Cerrar">×</button>
        </div>
        <div class="cash-modal-body">
            <label class="cash-check">
                <input type="checkbox" id="cash-arqueo-now" checked>
                Registrar usando fecha y hora actual
            </label>
            <div id="cash-arqueo-fecha-wrap" class="cash-field" hidden>
                <span>Fecha y hora</span>
                <input type="datetime-local" id="cash-arqueo-fecha">
            </div>
            <label class="cash-field">
                <span>Efectivo / Tarjetas (*)</span>
                <input type="number" id="cash-arqueo-efectivo" min="0" step="1" value="0">
            </label>
            <p class="cash-field-hint">*Siempre abra su caja con un monto inicial</p>
            <label class="cash-field">
                <span>Documentos recibidos</span>
                <input type="number" id="cash-arqueo-docs-rec" value="0" readonly>
            </label>
            <label class="cash-field">
                <span>Documentos emitidos</span>
                <input type="number" id="cash-arqueo-docs-emi" value="0" readonly>
            </label>
            <label class="cash-field">
                <span>Total</span>
                <input type="number" id="cash-arqueo-total" value="0" readonly>
            </label>
            <p class="cash-help">
                Para realizar un arqueo de caja, puedes elegir hacerlo tú mismo si tienes los permisos,
                o seleccionar a un usuario que tenga los permisos correctos para que apruebe el arqueo.
                Si seleccionas otro usuario, el arqueo NO tomará efecto hasta ser aprobado.
            </p>
            <label class="cash-field">
                <span>Certificador</span>
                <select id="cash-arqueo-cert"></select>
            </label>
            <input type="hidden" id="cash-arqueo-caja-id" value="">
            <input type="hidden" id="cash-arqueo-tipo" value="">
        </div>
        <div class="cash-modal-footer">
            <button type="button" class="cash-btn" id="cash-arqueo-submit">Abrir cuenta</button>
        </div>
    </div>
</div>

<!-- Modal Movimientos -->
<div id="cash-movs-modal" class="cash-modal-overlay" hidden aria-hidden="true">
    <div class="cash-modal cash-modal-lg" role="dialog" aria-modal="true">
        <div class="cash-modal-header">
            <h3 id="cash-movs-title">Movimientos</h3>
            <button type="button" class="cash-modal-x" id="cash-movs-close" aria-label="Cerrar">×</button>
        </div>
        <div class="cash-modal-body">
            <div class="cash-table-wrap">
                <table class="cash-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Detalle</th>
                            <th>Usuario</th>
                            <th>Monto</th>
                            <th>Saldo</th>
                        </tr>
                    </thead>
                    <tbody id="cash-movs-body">
                        <tr><td colspan="6" class="cash-empty">Sin movimientos</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
window.riversoCash = <?php echo riverso_pos_cash_json($riverso_cash); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/cash.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
