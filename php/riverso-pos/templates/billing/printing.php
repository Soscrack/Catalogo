<?php
/**
 * Facturación · Configuración de impresión directa ("Imprimir Ya!").
 *
 * Hubs (PC con impresoras), impresoras detectadas, presets, ruteo por tipo de documento
 * y estaciones. Quien solo imprime ve el estado y elige la estación de su dispositivo;
 * editar requiere riverso_manage_printing.
 *
 * @var array<string, mixed> $riverso_billing
 */
$surface = (isset($riverso_billing['surface']) && $riverso_billing['surface'] === 'admin') ? 'admin' : 'portal';
$asset_base = rtrim((string) (isset($riverso_billing['assetBase']) ? $riverso_billing['assetBase'] : ''), '/');
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$plugin_dir = defined('RIVERSO_POS_PLUGIN_DIR') ? RIVERSO_POS_PLUGIN_DIR : '';
$asset_ver = function ($rel) use ($plugin_dir, $version) {
    $path = $plugin_dir . 'assets/' . $rel;
    return is_file($path) ? (string) filemtime($path) : $version;
};

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

if (!class_exists('Riverso_Print_Module')) {
    echo '<p>El módulo de impresión no está disponible.</p>';
    return;
}
$print_cfg = Riverso_Print_Module::get_instance()->client_config();
$can_manage = !empty($print_cfg['canManage']);
?>
<link rel="stylesheet" href="<?php echo esc_attr($asset_base); ?>/css/billing.css?ver=<?php echo esc_attr($asset_ver('css/billing.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_attr($asset_base); ?>/css/printing.css?ver=<?php echo esc_attr($asset_ver('css/printing.css')); ?>">

<div class="wrap riverso-bill-wrap riverso-bill-wrap--<?php echo esc_attr($surface); ?>">
    <div id="riverso-print-config" class="riverso-bill rp-config" data-ready="0">

        <div class="rp-config-top">
            <div>
                <h1 class="rp-config-title">Impresión directa</h1>
                <p class="bill-hint">«Imprimir Ya!» envía el documento al hub del PC que tiene las impresoras y lo imprime con el preset, sin diálogo. Funciona desde cualquier PC o celular con sesión iniciada.</p>
            </div>
            <a class="bill-btn bill-btn-secondary" href="<?php echo esc_url($riverso_billing['searchUrl'] ?? home_url('/interno/facturacion/?vista=buscar')); ?>">← Documentos</a>
        </div>

        <div id="rp-alerts" class="bill-alerts" hidden></div>

        <section class="bill-card">
            <div class="bill-card-head"><h2>ESTE DISPOSITIVO</h2></div>
            <div class="rp-station-row">
                <label class="bill-field rp-station-field">
                    <span>Estación</span>
                    <select id="rp-station-select">
                        <option value="0">Sin estación (usa el ruteo general)</option>
                    </select>
                </label>
                <p class="bill-hint">Sirve para que cada caja o celular use su propia impresora. Se guarda solo en este navegador.</p>
            </div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>HUBS DE IMPRESIÓN</h2>
                <?php if ($can_manage) : ?>
                <button type="button" class="bill-btn" data-rp="agent-new">+ Agregar hub</button>
                <?php endif; ?>
            </div>
            <p class="bill-hint">Riverso Print Hub es el programa del PC que tiene las impresoras (PC1). Revisa la cola cada 2 segundos y avisa el estado de cada impresora.</p>
            <div id="rp-agents" class="bill-table-wrap"></div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>IMPRESORAS</h2>
                <label class="bill-check rp-inline-check"><input type="checkbox" id="rp-show-virtual"> <span>Mostrar virtuales y desactivadas</span></label>
            </div>
            <div id="rp-printers" class="bill-table-wrap"></div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>PRESETS</h2>
                <?php if ($can_manage) : ?>
                <button type="button" class="bill-btn" data-rp="preset-new">+ Nuevo preset</button>
                <?php endif; ?>
            </div>
            <p class="bill-hint">Un preset es una impresora con su papel, escala, copias, color y dúplex. Es lo mismo que eliges hoy en el diálogo de imprimir, pero guardado.</p>
            <div id="rp-presets" class="bill-table-wrap"></div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>RUTEO POR DOCUMENTO</h2>
                <?php if ($can_manage) : ?>
                <button type="button" class="bill-btn" data-rp="route-new">+ Nueva regla</button>
                <?php endif; ?>
            </div>
            <p class="bill-hint">Qué preset usa «Imprimir Ya!» según el tipo de documento. La alternativa se ofrece en el aviso de emergencia si la principal falla. Una regla de estación tiene prioridad sobre la general.</p>
            <div id="rp-routes" class="bill-table-wrap"></div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>ESTACIONES</h2>
                <?php if ($can_manage) : ?>
                <button type="button" class="bill-btn" data-rp="station-new">+ Nueva estación</button>
                <?php endif; ?>
            </div>
            <div id="rp-stations" class="bill-table-wrap"></div>
        </section>

        <section class="bill-card">
            <div class="bill-card-head">
                <h2>ÚLTIMOS TRABAJOS</h2>
                <button type="button" class="bill-btn" data-rp="refresh">Actualizar</button>
            </div>
            <div id="rp-jobs" class="bill-table-wrap"></div>
        </section>
    </div>
</div>

<script>
window.RIVERSO_PRINT = <?php echo riverso_pos_billing_json($print_cfg); ?>;
</script>
<script src="<?php echo esc_attr($asset_base); ?>/js/print-quick.js?ver=<?php echo esc_attr($asset_ver('js/print-quick.js')); ?>"></script>
<script src="<?php echo esc_attr($asset_base); ?>/js/print-config.js?ver=<?php echo esc_attr($asset_ver('js/print-config.js')); ?>"></script>
