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
                <input type="hidden" id="cust-id" name="id" value="0">

                <div class="cust-card">
                    <h2 class="cust-card-title" id="cust-card-general-title">Crear cliente</h2>
                    <label class="cust-field">
                        <span>Nombre de fantasía (*)</span>
                        <input type="text" id="cust-nombre-fantasia" name="nombre_fantasia" required autocomplete="organization">
                    </label>
                    <p class="cust-hint">*Si no existe el nombre de fantasía puede usar algún nombre representativo.</p>
                </div>

                <div class="cust-card">
                    <label class="cust-section-toggle">
                        <input type="checkbox" id="cust-has-contacto" checked>
                        <span>Contacto Primario</span>
                    </label>
                    <p class="cust-hint">*El contacto primario es la persona con la que sueles tener contacto relacionado con el cliente que estás creando.</p>
                    <div class="cust-section-body" id="cust-section-contacto">
                        <div class="cust-grid-2">
                            <label class="cust-field">
                                <span>Primer Nombre (*)</span>
                                <input type="text" id="cust-primer-nombre" name="primer_nombre" autocomplete="given-name">
                            </label>
                            <label class="cust-field">
                                <span>Apellido Paterno (*)</span>
                                <input type="text" id="cust-apellido-paterno" name="apellido_paterno" autocomplete="family-name">
                            </label>
                            <label class="cust-field">
                                <span>Teléfono</span>
                                <input type="text" id="cust-contacto-telefono" name="contacto_telefono" autocomplete="tel">
                            </label>
                            <label class="cust-field">
                                <span>Email <span class="cust-info" title="Correo de contacto del cliente">i</span></span>
                                <input type="email" id="cust-contacto-email" name="contacto_email" autocomplete="email">
                            </label>
                        </div>
                    </div>
                </div>

                <div class="cust-card">
                    <label class="cust-section-toggle">
                        <input type="checkbox" id="cust-has-facturacion" checked>
                        <span>Datos de facturación</span>
                    </label>
                    <p class="cust-hint">*Si existen más datos de facturación, puede ingresarlos luego de crear al cliente, en la pantalla Modificar.</p>
                    <div class="cust-section-body" id="cust-section-facturacion">
                        <div class="cust-grid-2">
                            <label class="cust-field">
                                <span>País</span>
                                <select id="cust-pais" name="pais">
                                    <option value="CHILE" selected>CHILE</option>
                                </select>
                            </label>
                            <div class="cust-id-pair">
                                <label class="cust-field">
                                    <span>Tipo de identificación</span>
                                    <select id="cust-tipo-id" name="tipo_identificacion">
                                        <option value="RUT_CLIENTE" selected>RUT Cliente</option>
                                    </select>
                                </label>
                                <label class="cust-field">
                                    <span>RUT Cliente</span>
                                    <input type="text" id="cust-rut" name="rut" placeholder="12345678-9" autocomplete="off">
                                </label>
                            </div>
                            <label class="cust-field">
                                <span>Nombre o Razón Social</span>
                                <input type="text" id="cust-razon-social" name="razon_social" autocomplete="organization">
                            </label>
                            <label class="cust-field">
                                <span>Dirección</span>
                                <input type="text" id="cust-direccion" name="direccion" autocomplete="street-address">
                            </label>
                            <label class="cust-field">
                                <span>Comuna</span>
                                <select id="cust-comuna" name="comuna">
                                    <option value="">** Seleccione **</option>
                                    <?php foreach ($comunas as $comuna): ?>
                                    <option value="<?php echo htmlspecialchars((string) $comuna, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string) $comuna, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="cust-field">
                                <span>Ciudad</span>
                                <input type="text" id="cust-ciudad" name="ciudad" autocomplete="address-level2">
                            </label>
                            <label class="cust-field">
                                <span>Giro</span>
                                <input type="text" id="cust-giro" name="giro">
                            </label>
                            <label class="cust-field">
                                <span>Teléfono</span>
                                <input type="text" id="cust-facturacion-telefono" name="facturacion_telefono" autocomplete="tel">
                            </label>
                            <label class="cust-field">
                                <span>Código postal</span>
                                <input type="text" id="cust-codigo-postal" name="codigo_postal" value="0">
                            </label>
                        </div>
                    </div>
                </div>

                <div class="cust-card">
                    <label class="cust-section-toggle">
                        <input type="checkbox" id="cust-has-datos-extra" checked>
                        <span>Datos Adicionales Configurables</span>
                    </label>
                    <p class="cust-hint">*Estos datos adicionales configurables permiten ingresar datos extras, informativos al cliente.</p>
                    <div class="cust-section-body" id="cust-section-extra">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="cust-extra-row">
                            <span class="cust-extra-label">Dato Configurable <?php echo (int) $i; ?></span>
                            <div class="cust-grid-2">
                                <input type="text" class="cust-extra-nombre" data-index="<?php echo (int) ($i - 1); ?>" placeholder="Nombre Dato Configurable <?php echo (int) $i; ?>">
                                <input type="text" class="cust-extra-valor" data-index="<?php echo (int) ($i - 1); ?>" placeholder="Valor Dato Configurable <?php echo (int) $i; ?>">
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div class="cust-form-footer">
                    <label class="cust-check-inline">
                        <input type="checkbox" id="cust-activo" checked>
                        <span>Cliente activo</span>
                    </label>
                    <div class="cust-form-actions">
                        <button type="button" class="cust-btn cust-btn-secondary" id="cust-cancel">Cancelar</button>
                        <?php if ($can_edit): ?>
                        <button type="submit" class="cust-btn cust-btn-primary" id="cust-save">Guardar cliente</button>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="cust-form-msg" id="cust-form-msg" hidden></p>
            </form>
        </section>
    </div>
</div>

<script>
window.RIVERSO_CUSTOMERS = <?php echo riverso_pos_customers_json($riverso_customers); ?>;
</script>
<script src="<?php echo htmlspecialchars($asset_base, ENT_QUOTES, 'UTF-8'); ?>/js/customers.js?ver=<?php echo htmlspecialchars($js_ver, ENT_QUOTES, 'UTF-8'); ?>"></script>
