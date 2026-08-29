<?php
if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('riverso_manage_manufacturing')) {
    wp_die('No tienes permisos para acceder a Manufactura.');
}
$nonce = wp_create_nonce('riverso_pos_nonce');
?>
<div class="wrap" id="mfg-app">
    <h1>Manufactura <span class="rce-wip-badge" style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:4px;font-size:12px;">WIP</span></h1>
    <p>Proceso <strong>Embolsar</strong>: abrir caja → embolsar unidades sueltas → etiquetar / imprimir.</p>

    <div id="mfg-step-nav" style="display:flex;gap:8px;margin:16px 0;">
        <button type="button" class="button mfg-step-btn active" data-step="1">1. Seleccionar familia</button>
        <button type="button" class="button mfg-step-btn" data-step="2" disabled>2. Abrir caja</button>
        <button type="button" class="button mfg-step-btn" data-step="3" disabled>3. Embolsar</button>
        <button type="button" class="button mfg-step-btn" data-step="4" disabled>4. Etiquetar</button>
    </div>

    <div id="mfg-step-1" class="mfg-panel">
        <h2>Familias con producto unitario</h2>
        <div id="mfg-families-list"><p>Cargando…</p></div>
    </div>

    <div id="mfg-step-2" class="mfg-panel" style="display:none;">
        <h2>Abrir envase cerrado</h2>
        <p id="mfg-selected-family"></p>
        <div id="mfg-envases-list"></div>
    </div>

    <div id="mfg-step-3" class="mfg-panel" style="display:none;">
        <h2>Generar bolsa</h2>
        <p>Stock abierto unitario: <strong id="mfg-open-stock">—</strong></p>
        <label>Cantidad <input type="number" step="1" min="1" id="mfg-bolsa-qty" class="small-text" value="100"></label>
        <button type="button" class="button button-primary" id="mfg-bolsa-run">Generar bolsa</button>
        <div id="mfg-bolsa-result" style="margin-top:12px;"></div>
    </div>

    <div id="mfg-step-4" class="mfg-panel" style="display:none;">
        <h2>Etiquetar / Imprimir</h2>
        <p id="mfg-print-info"></p>
        <label>Copias <input type="number" min="1" id="mfg-print-copias" class="small-text" value="1"></label>
        <button type="button" class="button button-primary" id="mfg-print-run">Crear orden de impresión</button>
        <div id="mfg-print-result" style="margin-top:12px;"></div>
    </div>
</div>

<script>
jQuery(function($){
    const nonce = '<?php echo esc_js($nonce); ?>';
    let state = { ordenId: 0, grupoId: 0, unitId: 0, familyName: '', bolsaId: 0, ean13: '' };

    function post(action, data){
        return $.post(ajaxurl, Object.assign({ action, nonce }, data || {}));
    }

    function goStep(n){
        $('.mfg-panel').hide();
        $('#mfg-step-' + n).show();
        $('.mfg-step-btn').removeClass('active');
        $('.mfg-step-btn[data-step="'+n+'"]').addClass('active');
        for (let i = 1; i <= 4; i++) {
            $('.mfg-step-btn[data-step="'+i+'"]').prop('disabled', i > n && !state.ordenId);
        }
    }

    function loadFamilies(){
        post('riverso_manufacturing_families').done(function(r){
            if (!r.success) { $('#mfg-families-list').html('<p>Error al cargar familias</p>'); return; }
            const rows = (r.data.families || []).map(function(f){
                const envCount = (f.envases || []).length;
                return '<div style="border:1px solid #ddd;padding:12px;margin-bottom:8px;border-radius:4px;background:#fff;">' +
                    '<strong>' + f.nombre + '</strong> <code>' + (f.canonical_sku || '') + '</code><br>' +
                    '<small>Unitario: ' + (f.nombre_canonico || '—') + ' · Stock abierto: ' + Number(f.stock_abierto||0).toLocaleString('es-CL') +
                    ' · Envases abribles: ' + envCount + '</small><br>' +
                    '<button type="button" class="button button-primary mfg-pick-family" style="margin-top:8px;" ' +
                    'data-id="' + f.id + '" data-unit="' + f.unit_producto_base_id + '" data-name="' + f.nombre + '">' +
                    (envCount ? 'Iniciar Embolsar' : 'Seleccionar (sin envases)') + '</button></div>';
            }).join('');
            $('#mfg-families-list').html(rows || '<p>No hay familias con producto unitario configurado.</p>');
        });
    }

    function renderEnvases(envases){
        if (!envases.length) {
            return '<p>No hay envases abribles. Configura presentaciones en la familia.</p>';
        }
        return envases.map(function(e){
            return '<div style="border:1px solid #eee;padding:10px;margin-bottom:8px;border-radius:4px;">' +
                '<strong>' + (e.tipo_envase || 'envase') + '</strong> · ' + e.cantidad_unidades + ' u · SKU ' + (e.miembro_sku || '') +
                '<br><button type="button" class="button mfg-open-envase" data-envase="' + e.id + '">Abrir 1 caja</button></div>';
        }).join('');
    }

    $(document).on('click', '.mfg-pick-family', function(){
        const $b = $(this);
        state.grupoId = parseInt($b.data('id'), 10);
        state.unitId = parseInt($b.data('unit'), 10);
        state.familyName = $b.data('name');
        post('riverso_manufacturing_start', { grupo_id: state.grupoId, tipo_proceso: 'embolsar' }).done(function(r){
            if (!r.success) { alert(r.data.message || 'Error'); return; }
            state.ordenId = r.data.orden_id;
            $('#mfg-selected-family').text(state.familyName + ' (orden #' + state.ordenId + ')');
            post('riverso_manufacturing_families').done(function(r2){
                const fam = (r2.data.families || []).find(function(x){ return parseInt(x.id,10) === state.grupoId; });
                $('#mfg-envases-list').html(renderEnvases(fam ? fam.envases : []));
                goStep(2);
            });
        });
    });

    $(document).on('click', '.mfg-open-envase', function(){
        const envaseId = $(this).data('envase');
        post('riverso_manufacturing_open', { orden_id: state.ordenId, envase_id: envaseId, cantidad_envases: 1 }).done(function(r){
            if (!r.success) { alert(r.data.message || 'Error al abrir'); return; }
            alert('Abiertas ' + r.data.unidades_abiertas + ' unidades. Stock abierto: ' + r.data.stock_abierto);
            $('#mfg-open-stock').text(Number(r.data.stock_abierto).toLocaleString('es-CL'));
            goStep(3);
        });
    });

    $('#mfg-bolsa-run').on('click', function(){
        post('riverso_manufacturing_bolsa', { orden_id: state.ordenId, cantidad: $('#mfg-bolsa-qty').val() }).done(function(r){
            if (!r.success) { alert(r.data.message || 'Error'); return; }
            state.bolsaId = r.data.bolsa_id;
            state.ean13 = r.data.ean13 || '';
            $('#mfg-bolsa-result').html('Bolsa <code>' + r.data.sku_bolsa + '</code> · EAN13 <strong>' + (r.data.ean13 || '—') + '</strong>');
            $('#mfg-print-info').text('Bolsa ' + r.data.sku_bolsa + ' · EAN13 ' + (r.data.ean13 || '—'));
            goStep(4);
        });
    });

    $('#mfg-print-run').on('click', function(){
        post('riverso_manufacturing_print', { orden_id: state.ordenId, bolsa_id: state.bolsaId, copias: $('#mfg-print-copias').val() }).done(function(r){
            if (!r.success) { alert(r.data.message || 'Error'); return; }
            $('#mfg-print-result').html('Orden de impresión #' + r.data.print_order_id + ' creada. Ve a <a href="admin.php?page=riverso-pos-print-orders">Impresiones</a> para imprimir.');
        });
    });

    loadFamilies();
});
</script>
