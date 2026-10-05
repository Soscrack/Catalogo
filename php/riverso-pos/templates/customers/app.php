<?php
/**
 * App compartida de Clientes (admin + portal).
 *
 * @var array<string, mixed> $riverso_customers
 */
if (!defined('ABSPATH') && empty($riverso_customers['standalone'])) {
    // En WP el módulo define ABSPATH.
}

$surface = (isset($riverso_customers['surface']) && $riverso_customers['surface'] === 'portal') ? 'portal' : 'admin';
$asset_base = rtrim((string) (isset($riverso_customers['assetBase']) ? $riverso_customers['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$js_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/js/customers.js';
$css_path = (defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '') . 'assets/css/customers.css';
$js_ver = (is_string($js_path) && is_file($js_path)) ? (string) filemtime($js_path) : $version;
$css_ver = (is_string($css_path) && is_file($css_path)) ? (string) filemtime($css_path) : $version;
$can_edit = !empty($riverso_customers['caps']['edit']);
$comunas = isset($riverso_customers['comunas']) && is_array($riverso_customers['comunas'])
    ? $riverso_customers['comunas']
    : [];

if (!function_exists('riverso_pos_customers_json')) {
    function riverso_pos_customers_json($data) {
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
<link rel="stylesheet" href="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/css/customers.css?ver=<?php echo htmlspecialchars($css_ver, ENT_QUOTES, 'UTF-8'); ?>">

<div class="wrap riverso-cust-wrap<?php echo $surface === 'portal' ? ' riverso-cust-wrap--portal' : ''; ?>">
    <div id="riverso-customers" class="riverso-cust" data-surface="<?php echo htmlspecialchars($surface, ENT_QUOTES, 'UTF-8'); ?>" data-ready="0">

        <section id="cust-list-view" class="cust-view" aria-labelledby="cust-list-title">
            <header class="cust-top">
                <div>
                    <h1 id="cust-list-title">Clientes</h1>
                    <p class="cust-lead">Busca y administra clientes comerciales.</p>
                </div>
                <?php if ($can_edit): ?>
                <button type="button" class="cust-btn cust-btn-primary" id="cust-new">Nuevo cliente</button>
                <?php endif; ?>
            </header>

            <div class="cust-search-card">
                <div class="cust-search-row">
                    <label class="cust-field cust-field-grow">
                        <span>Buscar</span>
                        <input type="text" id="cust-filter-search" placeholder="Nombre de fantasía, RUT, razón social o email…" autocomplete="off">
                    </label>
                    <label class="cust-field">
                        <span>Estado</span>
                        <select id="cust-filter-status">
                            <option value="active" selected>Activos</option>
                            <option value="inactive">Inactivos</option>
                            <option value="all">Todos</option>
                        </select>
                    </label>
                    <div class="cust-search-actions">
                        <button type="button" class="cust-btn cust-btn-search" id="cust-apply-filters">Buscar</button>
                        <button type="button" class="cust-btn cust-btn-secondary" id="cust-clear-filters">Limpiar</button>
                    </div>
                </div>
            </div>

            <div class="cust-table-wrap">
                <table class="cust-table" id="cust-table">
                    <thead>
                        <tr>
                            <th>Nombre de fantasía</th>
                            <th>RUT</th>
                            <th>Contacto</th>
                            <th>Email</th>
                            <th>Teléfono</th>
                            <th class="cust-th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="cust-tbody">
                        <tr><td colspan="6" class="cust-empty">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="cust-pagination" id="cust-pagination" hidden>
                <span id="cust-pagination-info"></span>
                <div id="cust-pagination-buttons"></div>
            </div>
        </section>

        <section id="cust-form-view" class="cust-view" hidden aria-labelledby="cust-form-title">
            <header class="cust-top">
                <div>
                    <h1 id="cust-form-title">Crear cliente</h1>
                    <p class="cust-lead">Completa los datos del cliente. Los campos con (*) son obligatorios.</p>
                </div>
                <button type="button" class="cust-btn cust-btn-secondary" id="cust-back-list">Volver al listado</button>
            </header>

            <form id="cust-form" novalidate>
                <?php
                $show_footer = true;
                include RIVERSO_POS_PLUGIN_DIR . 'templates/customers/form-fields.php';
                ?>
            </form>
        </section>
    </div>
</div>

<script>
window.RIVERSO_CUSTOMERS = <?php echo riverso_pos_customers_json($riverso_customers); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customers.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
