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

$scan_gemini_key = riverso_get_scan_config('gemini_api_key', '');
$scan_gemini_model = riverso_get_scan_config('gemini_model', 'gemini-3.6-flash');
$scan_r2_key = riverso_get_scan_config('r2_access_key_id', '');
$scan_r2_secret = riverso_get_scan_config('r2_secret_access_key', '');
$scan_r2_endpoint = riverso_get_scan_config('r2_endpoint', '');
$scan_r2_bucket = riverso_get_scan_config('r2_bucket', 'riverso-documentos');
$scan_r2_prefix = riverso_get_scan_config('r2_prefix', '');
$scan_receptor_rut = riverso_get_scan_config('expected_receptor_rut', '76.443.852-3');

$facto_enabled = (int) riverso_get_facto_config('enabled', 0);
$facto_sync_enabled = (int) riverso_get_facto_config('sync_enabled', 0);
$facto_base_url = riverso_get_facto_config('base_url', 'https://apifacto.com/v1');
$facto_client_id = riverso_get_facto_config('client_id', '');
$facto_client_secret = riverso_get_facto_config('client_secret', '');
$facto_username = riverso_get_facto_config('username', '');
$facto_password = riverso_get_facto_config('password', '');
$facto_account_id = riverso_get_facto_config('account_id', '');
$facto_price_list_id = riverso_get_facto_config('price_list_id', 1);
$facto_location_id = riverso_get_facto_config('location_id', 1);
$facto_currency_id = riverso_get_facto_config('currency_id', 39);
$facto_tax_type_id = riverso_get_facto_config('tax_type_id', 387);
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

        <h2 class="title">Escaneos PDF / Imagen (Gemini + Cloudflare R2)</h2>
        <p class="description">Las constantes en <code>wp-config.php</code> tienen prioridad sobre estos campos.</p>
        <table class="form-table">
            <tr>
                <th scope="row">Gemini API Key</th>
                <td>
                    <input type="password" name="scan_gemini_api_key" class="regular-text"
                           value="<?php echo esc_attr($scan_gemini_key ? riverso_mask_secret($scan_gemini_key) : ''); ?>"
                           placeholder="<?php echo $scan_gemini_key ? 'Configurada (wp-config o settings)' : 'RIVERSO_GEMINI_API_KEY'; ?>" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">Modelo Gemini</th>
                <td>
                    <input type="text" name="scan_gemini_model" class="regular-text"
                           value="<?php echo esc_attr($scan_gemini_model); ?>" placeholder="gemini-3.6-flash">
                </td>
            </tr>
            <tr>
                <th scope="row">R2 Access Key ID</th>
                <td>
                    <input type="text" name="scan_r2_access_key_id" class="regular-text"
                           value="<?php echo esc_attr($scan_r2_key ? riverso_mask_secret($scan_r2_key) : ''); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">R2 Secret Access Key</th>
                <td>
                    <input type="password" name="scan_r2_secret_access_key" class="regular-text"
                           value="<?php echo esc_attr($scan_r2_secret ? riverso_mask_secret($scan_r2_secret) : ''); ?>" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">R2 Endpoint</th>
                <td>
                    <input type="url" name="scan_r2_endpoint" class="large-text"
                           value="<?php echo esc_attr($scan_r2_endpoint); ?>"
                           placeholder="https://....r2.cloudflarestorage.com">
                </td>
            </tr>
            <tr>
                <th scope="row">R2 Bucket</th>
                <td>
                    <input type="text" name="scan_r2_bucket" class="regular-text"
                           value="<?php echo esc_attr($scan_r2_bucket); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">Prefijo R2 (opcional)</th>
                <td>
                    <input type="text" name="scan_r2_prefix" class="regular-text"
                           value="<?php echo esc_attr($scan_r2_prefix); ?>" placeholder="prod/">
                </td>
            </tr>
            <tr>
                <th scope="row">RUT receptor esperado</th>
                <td>
                    <input type="text" name="scan_expected_receptor_rut" class="regular-text"
                           value="<?php echo esc_attr($scan_receptor_rut); ?>">
                    <p class="description">RUT de la empresa para validar que el documento escaneado nos corresponde.</p>
                </td>
            </tr>
        </table>

        <h2 class="title">Integración FACTO (facturación)</h2>
        <p class="description">
            Sincroniza el catálogo local hacia FACTO. Las constantes <code>RIVERSO_FACTO_*</code> en
            <code>wp-config.php</code> tienen prioridad sobre estos campos.
            El SKU se define al crear el producto en FACTO y <strong>no se puede renombrar</strong> vía API.
        </p>
        <table class="form-table">
            <tr>
                <th scope="row">Habilitar módulo</th>
                <td>
                    <label>
                        <input type="checkbox" name="facto_enabled" value="1" <?php checked($facto_enabled, 1); ?>>
                        Mostrar/activar integración FACTO
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Sync automático</th>
                <td>
                    <label>
                        <input type="checkbox" name="facto_sync_enabled" value="1" <?php checked($facto_sync_enabled, 1); ?>>
                        Encolar create/update/archive de productos hacia FACTO
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Base URL</th>
                <td>
                    <input type="url" name="facto_base_url" class="large-text"
                           value="<?php echo esc_attr($facto_base_url); ?>"
                           placeholder="https://apifacto.com/v1">
                </td>
            </tr>
            <tr>
                <th scope="row">Client ID</th>
                <td>
                    <input type="text" name="facto_client_id" class="regular-text"
                           value="<?php echo esc_attr($facto_client_id); ?>" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">Client Secret</th>
                <td>
                    <input type="password" name="facto_client_secret" class="regular-text"
                           value="<?php echo esc_attr($facto_client_secret ? riverso_mask_secret($facto_client_secret) : ''); ?>"
                           autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">Username API</th>
                <td>
                    <input type="text" name="facto_username" class="regular-text"
                           value="<?php echo esc_attr($facto_username); ?>" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">Password API</th>
                <td>
                    <input type="password" name="facto_password" class="regular-text"
                           value="<?php echo esc_attr($facto_password ? riverso_mask_secret($facto_password) : ''); ?>"
                           autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">Account ID</th>
                <td>
                    <input type="text" name="facto_account_id" class="regular-text"
                           value="<?php echo esc_attr($facto_account_id); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">Price list ID</th>
                <td>
                    <input type="number" name="facto_price_list_id" class="small-text"
                           value="<?php echo esc_attr($facto_price_list_id); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">Location ID</th>
                <td>
                    <input type="number" name="facto_location_id" class="small-text"
                           value="<?php echo esc_attr($facto_location_id); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">Currency ID / Tax type ID</th>
                <td>
                    <input type="number" name="facto_currency_id" class="small-text"
                           value="<?php echo esc_attr($facto_currency_id); ?>" title="currency_id">
                    <input type="number" name="facto_tax_type_id" class="small-text"
                           value="<?php echo esc_attr($facto_tax_type_id); ?>" title="tax_type_id">
                    <p class="description">Chile tipico: currency 39 (CLP), tax 387 (IVA 19%).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Acciones</th>
                <td>
                    <button type="button" class="button" id="btn-facto-test">Probar conexión</button>
                    <button type="button" class="button" id="btn-facto-reconcile">Reconciliar SKUs (backfill)</button>
                    <button type="button" class="button" id="btn-facto-outbox">Procesar outbox ahora</button>
                    <span id="facto-action-status" style="margin-left:10px;"></span>
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

        const invoiceData = {
            action: 'riverso_save_invoice_settings',
            nonce: nonce,
            auto_inventory_on_approve: $('input[name="auto_inventory_on_approve"]').is(':checked') ? 1 : 0,
            create_reception_task_on_upload: $('input[name="create_reception_task_on_upload"]').is(':checked') ? 1 : 0,
            create_link_task_on_upload: $('input[name="create_link_task_on_upload"]').is(':checked') ? 1 : 0,
            prorate_shipping_to_products: $('input[name="prorate_shipping_to_products"]').is(':checked') ? 1 : 0,
            default_intake_mode: $('select[name="default_intake_mode"]').val()
        };

        const scanSecretVal = function(sel) {
            const v = $(sel).val();
            return (v && /\*{4,}/.test(v)) ? '' : v;
        };
        const scanData = {
            action: 'riverso_save_scan_settings',
            nonce: nonce,
            scan_gemini_api_key: scanSecretVal('input[name="scan_gemini_api_key"]'),
            scan_gemini_model: $('input[name="scan_gemini_model"]').val(),
            scan_r2_access_key_id: scanSecretVal('input[name="scan_r2_access_key_id"]'),
            scan_r2_secret_access_key: scanSecretVal('input[name="scan_r2_secret_access_key"]'),
            scan_r2_endpoint: $('input[name="scan_r2_endpoint"]').val(),
            scan_r2_bucket: $('input[name="scan_r2_bucket"]').val(),
            scan_r2_prefix: $('input[name="scan_r2_prefix"]').val(),
            scan_expected_receptor_rut: $('input[name="scan_expected_receptor_rut"]').val()
        };

        const factoData = {
            action: 'riverso_save_facto_settings',
            nonce: nonce,
            facto_enabled: $('input[name="facto_enabled"]').is(':checked') ? 1 : 0,
            facto_sync_enabled: $('input[name="facto_sync_enabled"]').is(':checked') ? 1 : 0,
            facto_base_url: $('input[name="facto_base_url"]').val(),
            facto_client_id: $('input[name="facto_client_id"]').val(),
            facto_client_secret: $('input[name="facto_client_secret"]').val(),
            facto_username: $('input[name="facto_username"]').val(),
            facto_password: $('input[name="facto_password"]').val(),
            facto_account_id: $('input[name="facto_account_id"]').val(),
            facto_price_list_id: $('input[name="facto_price_list_id"]').val(),
            facto_location_id: $('input[name="facto_location_id"]').val(),
            facto_currency_id: $('input[name="facto_currency_id"]').val(),
            facto_tax_type_id: $('input[name="facto_tax_type_id"]').val()
        };

        $.when(
            $.post(ajaxurl, invoiceData),
            $.post(ajaxurl, scanData),
            $.post(ajaxurl, factoData)
        ).done(function(r1, r2, r3) {
            btn.prop('disabled', false);
            const ok1 = r1[0].success;
            const ok2 = r2[0].success;
            const ok3 = r3[0].success;
            if (ok1 && ok2 && ok3) {
                $('#settings-save-status').html('<span style="color:#00a32a;">✓ Configuración guardada</span>');
            } else {
                const msg = (!ok1 ? (r1[0].data?.message || 'Error facturas') : '') +
                    (!ok2 ? ' ' + (r2[0].data?.message || 'Error escaneos') : '') +
                    (!ok3 ? ' ' + (r3[0].data?.message || 'Error FACTO') : '');
                $('#settings-save-status').html('<span style="color:#d63638;">' + msg + '</span>');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            $('#settings-save-status').html('<span style="color:#d63638;">Error de red</span>');
        });
    });

    function factoAction(action, label) {
        const $status = $('#facto-action-status');
        $status.text(label + '...');
        $.post(ajaxurl, { action: action, nonce: nonce }).done(function(resp) {
            if (resp.success) {
                const extra = resp.data && resp.data.message ? resp.data.message : JSON.stringify(resp.data || {});
                $status.html('<span style="color:#00a32a;">✓ ' + extra + '</span>');
            } else {
                $status.html('<span style="color:#d63638;">' + (resp.data?.message || 'Error') + '</span>');
            }
        }).fail(function() {
            $status.html('<span style="color:#d63638;">Error de red</span>');
        });
    }

    function factoReconcile(page, totals) {
        const $status = $('#facto-action-status');
        const $btn = $('#btn-facto-reconcile');
        $btn.prop('disabled', true);
        $status.text('Reconciliando... página ' + page + (totals.page_count ? '/' + totals.page_count : ''));

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            timeout: 120000,
            data: {
                action: 'riverso_facto_reconcile',
                nonce: nonce,
                page: page,
                pages: 5
            }
        }).done(function(resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $status.html('<span style="color:#d63638;">' + (resp.data?.message || 'Error') + '</span>');
                return;
            }
            const d = resp.data || {};
            totals.linked += Number(d.linked || 0);
            totals.skipped += Number(d.skipped || 0);
            totals.only_facto += Number(d.only_facto_count || 0);
            if (d.page_count) totals.page_count = d.page_count;
            if (Array.isArray(d.only_facto_sample) && totals.sample.length < 50) {
                totals.sample = totals.sample.concat(d.only_facto_sample).slice(0, 50);
            }

            if (d.done) {
                $btn.prop('disabled', false);
                const msg = 'Listo. Vinculados: ' + totals.linked +
                    ' | Solo FACTO: ' + totals.only_facto +
                    ' | Sin SKU/omitidos: ' + totals.skipped +
                    ' | Páginas: ' + (d.page || '?') + '/' + (totals.page_count || '?');
                $status.html('<span style="color:#00a32a;">✓ ' + msg + '</span>');
                return;
            }

            if (!d.next_page) {
                $btn.prop('disabled', false);
                $status.html('<span style="color:#d63638;">Reconciliación incompleta (sin next_page)</span>');
                return;
            }

            $status.text(
                'Reconciliando... ' + totals.linked + ' vinculados · página ' +
                d.next_page + '/' + (totals.page_count || '?')
            );
            setTimeout(function() { factoReconcile(d.next_page, totals); }, 250);
        }).fail(function(xhr) {
            $btn.prop('disabled', false);
            const hint = xhr && xhr.statusText ? (' (' + xhr.status + ' ' + xhr.statusText + ')') : '';
            $status.html('<span style="color:#d63638;">Error de red' + hint + '. Puedes reintentar; el map ya guardado se conserva.</span>');
        });
    }

    $('#btn-facto-test').on('click', function() {
        factoAction('riverso_facto_test_connection', 'Probando');
    });
    $('#btn-facto-reconcile').on('click', function() {
        if (!confirm('¿Reconciliar SKUs locales con FACTO? Se hará por lotes para evitar timeouts.')) return;
        factoReconcile(1, { linked: 0, skipped: 0, only_facto: 0, page_count: null, sample: [] });
    });
    $('#btn-facto-outbox').on('click', function() {
        factoAction('riverso_facto_process_outbox', 'Procesando outbox');
    });
});
</script>
