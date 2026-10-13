<?php
/**
 * App Pedidos de compra: armar → cotización → revisión de precios → recepción.
 *
 * @var array<string, mixed> $riverso_pc
 */
if (!defined('ABSPATH')) {
    exit;
}

$asset_base = rtrim(RIVERSO_POS_PLUGIN_URL, '/') . '/assets';
$version = defined('RIVERSO_POS_VERSION') ? RIVERSO_POS_VERSION : '0.1.0';
$asset_ver = function ($relative) use ($version) {
    $path = RIVERSO_POS_PLUGIN_DIR . 'assets/' . $relative;
    return is_file($path) ? (string) filemtime($path) : $version;
};
$json_flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$can_manage = !empty($riverso_pc['canManage']);
?>
<?php // Botones, etiquetas, diálogo y aviso flotante son los de Avisos de compra. ?>
<link rel="stylesheet" href="<?php echo esc_url($asset_base . '/css/avisos.css?ver=' . $asset_ver('css/avisos.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url($asset_base . '/css/pedidos-compra.css?ver=' . $asset_ver('css/pedidos-compra.css')); ?>">

<div class="wrap riverso-pc-wrap">
    <div id="riverso-pc" class="riverso-av riverso-pc">

        <!-- Lista de pedidos -->
        <section id="pc-view-lista" hidden>
            <div class="pc-toolbar">
                <input type="search" id="pc-search" placeholder="Buscar por número o proveedor" autocomplete="off">
                <?php if ($can_manage): ?>
                <button type="button" class="av-btn" id="pc-armar-btn">Armar pedido</button>
                <?php endif; ?>
            </div>
            <div class="av-chips" id="pc-filters"></div>
            <div id="pc-list"></div>
        </section>

        <?php if ($can_manage): ?>
        <!-- Armar: necesidades abiertas por proveedor -->
        <section id="pc-view-armar" hidden>
            <div class="pc-head">
                <button type="button" class="av-link-btn" data-nav="lista">← Pedidos</button>
                <h2>Armar pedido</h2>
            </div>
            <p class="av-hint">Avisos y alertas abiertos, por proveedor. El sistema propone; nada entra a un pedido hasta que lo confirmas.</p>
            <div id="pc-tray"></div>
        </section>
        <?php endif; ?>

        <!-- Ficha del pedido -->
        <section id="pc-view-ficha" hidden>
            <div class="pc-head">
                <button type="button" class="av-link-btn" data-nav="lista">← Pedidos</button>
            </div>
            <div id="pc-order"></div>
        </section>

        <div class="av-toast" id="pc-toast" role="status" hidden></div>

        <div class="av-modal-overlay" id="pc-modal" hidden>
            <div class="av-modal pc-modal" role="dialog" aria-modal="true" aria-labelledby="pc-modal-title">
                <h2 id="pc-modal-title"></h2>
                <div id="pc-modal-body"></div>
                <div class="av-modal-error" id="pc-modal-error" role="alert" hidden></div>
                <div class="av-actions">
                    <button type="button" class="av-btn av-btn-ghost" id="pc-modal-cancel">Cerrar</button>
                    <button type="button" class="av-btn" id="pc-modal-ok">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.riversoPedidos = <?php echo wp_json_encode($riverso_pc, $json_flags); ?>;
</script>
<script src="<?php echo esc_url($asset_base . '/js/pedidos-compra.js?ver=' . $asset_ver('js/pedidos-compra.js')); ?>"></script>
