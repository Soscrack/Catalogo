<?php
/**
 * App Cuentas bancarias y efectivo.
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
$tipos = isset($riverso_cash['tipos']) && is_array($riverso_cash['tipos']) ? $riverso_cash['tipos'] : [];

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
    <div id="riverso-cash" class="riverso-cash" data-view="accounts" data-surface="<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Listado -->
        <section id="cash-accounts-list-view">
            <div class="cash-top">
                <h1>Cajas o cuentas</h1>
                <button type="button" class="cash-btn" id="cash-add-btn">+ Agregar caja</button>
            </div>

            <div class="cash-toolbar">
                <label>
                    Mostrar
                    <select id="cash-acc-page-size">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    registros
                </label>
                <label>
                    Buscar:
                    <input type="search" id="cash-acc-search" autocomplete="off">
                </label>
            </div>

            <div class="cash-table-wrap">
                <table class="cash-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo de caja</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="cash-acc-body">
                        <tr><td colspan="3" class="cash-empty">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cash-pager" id="cash-acc-pager"></div>

            <div class="cash-card" style="margin-top:24px">
                <h2>Métodos de pago</h2>
                <p class="cash-hint">IDs de FACTO usados al sincronizar cobros. Los métodos internos pueden ocultarse del modal.</p>
                <div class="cash-table-wrap">
                    <table class="cash-table">
                        <thead>
                            <tr>
                                <th>Método</th>
                                <th>ID FACTO</th>
                                <th>Visible</th>
                                <th>Cheque</th>
                                <th>Vuelto</th>
                            </tr>
                        </thead>
                        <tbody id="cash-methods-body">
                            <tr><td colspan="5" class="cash-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Editar -->
        <section id="cash-accounts-edit-view" hidden>
            <a href="#" class="cash-back" id="cash-edit-back">← Volver al listado</a>
            <div class="cash-card">
                <h2>Editar caja o cuenta</h2>
                <div class="cash-grid-2">
                    <label class="cash-field">
                        <span>Nombre</span>
                        <input type="text" id="cash-edit-nombre" maxlength="128">
                    </label>
                    <label class="cash-field">
                        <span>ID caja FACTO</span>
                        <input type="text" id="cash-edit-facto" maxlength="16" placeholder="Ej. 1">
                    </label>
                    <label class="cash-field">
                        <span>Tipo de caja</span>
                        <select id="cash-edit-tipo">
                            <?php foreach ($tipos as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="button" class="cash-btn" id="cash-edit-save">Guardar</button>
                <input type="hidden" id="cash-edit-id" value="">
            </div>

            <div class="cash-card">
                <h2>Permisos</h2>
                <div class="cash-toolbar">
                    <label>
                        Mostrar
                        <select id="cash-perm-page-size">
                            <option value="10" selected>10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        registros
                    </label>
                    <label>
                        Buscar:
                        <input type="search" id="cash-perm-search" autocomplete="off">
                    </label>
                </div>
                <div class="cash-table-wrap">
                    <table class="cash-table cash-perm-table">
                        <thead>
                            <tr>
                                <th>Asignar a</th>
                                <th>Ver saldo</th>
                                <th>Pagar</th>
                                <th>Borrar Pago</th>
                                <th>Transferir</th>
                                <th>Abrir / Cerrar caja o cuenta</th>
                            </tr>
                        </thead>
                        <tbody id="cash-perm-body">
                            <tr><td colspan="6" class="cash-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cash-pager" id="cash-perm-pager"></div>
            </div>
        </section>
    </div>
</div>

<!-- Modal Agregar -->
<div id="cash-add-modal" class="cash-modal-overlay" hidden aria-hidden="true">
    <div class="cash-modal" role="dialog" aria-modal="true">
        <div class="cash-modal-header">
            <h3>Agregar caja o cuenta</h3>
            <button type="button" class="cash-modal-x" id="cash-add-close" aria-label="Cerrar">×</button>
        </div>
        <div class="cash-modal-body">
            <div class="cash-grid-2">
                <label class="cash-field">
                    <span>Nombre</span>
                    <input type="text" id="cash-add-nombre" maxlength="128" placeholder="Nombre">
                </label>
                <label class="cash-field">
                    <span>Tipo de caja</span>
                    <select id="cash-add-tipo">
                        <?php foreach ($tipos as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
        <div class="cash-modal-footer">
            <button type="button" class="cash-btn" id="cash-add-submit">Agregar</button>
        </div>
    </div>
</div>

<script>
window.riversoCash = <?php echo riverso_pos_cash_json($riverso_cash); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/cash.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
