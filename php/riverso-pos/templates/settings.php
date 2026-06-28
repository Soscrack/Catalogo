<?php
/**
 * Template: Configuración Riverso POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$auto_inventory = riverso_get_setting('auto_inventory_on_approve', true);
$reception_task = riverso_get_setting('create_reception_task_on_upload', true);
$prorate_shipping = riverso_get_setting('prorate_shipping_to_products', true);
$link_tasks = riverso_get_setting('create_link_task_on_upload', true);
$default_intake_mode = riverso_get_setting('default_intake_mode', 'recepcion');
?>

<div class="wrap riverso-settings">
    <h1><span class="dashicons dashicons-admin-generic"></span> Configuración Riverso POS</h1>

    <form id="riverso-settings-form">
        <h2 class="title">Ingreso de facturas XML</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Inventario automático</th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_inventory_on_approve" value="1" <?php checked($auto_inventory); ?>>
                        Al aprobar factura, crear lotes y registrar entrada de stock en WooCommerce
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Tarea de recepción</th>
                <td>
                    <label>
                        <input type="checkbox" name="create_reception_task_on_upload" value="1" <?php checked($reception_task); ?>>
                        Crear tarea de ordenar/recibir mercadería al subir XML de productos
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Tareas de vinculación</th>
                <td>
                    <label>
                        <input type="checkbox" name="create_link_task_on_upload" value="1" <?php checked($link_tasks); ?>>
                        Crear tarea «Vincular código proveedor → SKU local» para ítems sin mapeo al subir XML
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Modo de ingreso por defecto</th>
                <td>
                    <select name="default_intake_mode">
                        <option value="recepcion" <?php selected($default_intake_mode, 'recepcion'); ?>>Recepción completa</option>
                        <option value="solo_costos" <?php selected($default_intake_mode, 'solo_costos'); ?>>Solo registrar costos (sin bodega)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Prorrateo de envío</th>
                <td>
                    <label>
                        <input type="checkbox" name="prorate_shipping_to_products" value="1" <?php checked($prorate_shipping); ?>>
                        Distribuir costo de flete entre ítems de producto para calcular precio baseline (costo landed)
                    </label>
                    <p class="description">
                        El envío puede venir en el mismo XML (líneas detectadas por palabras clave) o en un XML separado del transportista.
                    </p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" id="btn-save-settings">Guardar cambios</button>
            <span id="settings-save-status" style="margin-left: 10px;"></span>
        </p>
    </form>
</div>

<script>
jQuery(function($) {
    const nonce = '<?php echo esc_js(wp_create_nonce('riverso_pos_nonce')); ?>';

    $('#riverso-settings-form').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#btn-save-settings');
        btn.prop('disabled', true);
        $('#settings-save-status').text('Guardando...');

        $.post(ajaxurl, {
            action: 'riverso_save_invoice_settings',
            nonce: nonce,
            auto_inventory_on_approve: $('input[name="auto_inventory_on_approve"]').is(':checked') ? 1 : 0,
            create_reception_task_on_upload: $('input[name="create_reception_task_on_upload"]').is(':checked') ? 1 : 0,
            create_link_task_on_upload: $('input[name="create_link_task_on_upload"]').is(':checked') ? 1 : 0,
            prorate_shipping_to_products: $('input[name="prorate_shipping_to_products"]').is(':checked') ? 1 : 0,
            default_intake_mode: $('select[name="default_intake_mode"]').val()
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                $('#settings-save-status').html('<span style="color:#00a32a;">✓ ' + response.data.message + '</span>');
            } else {
                $('#settings-save-status').html('<span style="color:#d63638;">' + (response.data?.message || 'Error') + '</span>');
            }
        });
    });
});
</script>
