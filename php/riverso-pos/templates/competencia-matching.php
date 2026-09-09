<?php
/**
 * Competencia — matching por fuente + Ingreso Manual + Fuentes.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$vista = isset($_GET['vista']) ? sanitize_key(wp_unslash($_GET['vista'])) : '';
$fuente = isset($_GET['fuente']) ? sanitize_key(wp_unslash($_GET['fuente'])) : 'sande';
if ($fuente === '') {
    $fuente = 'sande';
}

if (in_array($vista, ['ingreso', 'fuentes'], true)) {
    $modo = $vista;
} else {
    $modo = 'matching';
    $vista = '';
}

$fuente_nombre = ($fuente === 'dimafi') ? 'DIMAFI' : 'Sande';
$base_url = admin_url('admin.php?page=riverso-pos-competencia');
$ingreso_url = add_query_arg('vista', 'ingreso', $base_url);
$fuentes_url = add_query_arg('vista', 'fuentes', $base_url);
$now_display = current_time('Y-m-d H:i:s');
?>
<div class="wrap riverso-competencia-wrap">
    <h1><?php esc_html_e('Competencia', 'riverso-pos'); ?></h1>
    <p class="description">
        Matching supervisado entre catálogos de competencia y productos Riverso.
        Ningún vínculo se confirma sin acción humana.
    </p>

    <h2 class="nav-tab-wrapper" style="margin-top:16px;">
        <a href="<?php echo esc_url(add_query_arg('fuente', 'sande', $base_url)); ?>"
           class="nav-tab <?php echo ($modo === 'matching' && $fuente === 'sande') ? 'nav-tab-active' : ''; ?>">Sande</a>
        <a href="<?php echo esc_url(add_query_arg('fuente', 'dimafi', $base_url)); ?>"
           class="nav-tab <?php echo ($modo === 'matching' && $fuente === 'dimafi') ? 'nav-tab-active' : ''; ?>">DIMAFI</a>
        <a href="#" class="nav-tab" style="opacity:.55;cursor:not-allowed;"
           title="<?php esc_attr_e('Próximamente', 'riverso-pos'); ?>"
           onclick="return false;">Otras fuentes <span class="description">[WIP]</span></a>
        <a href="<?php echo esc_url($ingreso_url); ?>"
           class="nav-tab <?php echo $modo === 'ingreso' ? 'nav-tab-active' : ''; ?>">Ingreso Manual</a>
        <a href="<?php echo esc_url($fuentes_url); ?>"
           class="nav-tab <?php echo $modo === 'fuentes' ? 'nav-tab-active' : ''; ?>">Fuentes</a>
    </h2>

<?php if ($modo === 'ingreso') : ?>

    <div class="card" style="max-width:1200px;padding:16px 20px;margin-top:12px;">
        <h2 style="margin-top:0;">Ingreso Manual</h2>
        <p class="description" style="margin-top:0;">
            Vincula un SKU local con una URL de competidor y registra el precio. La fuente se detecta por el dominio (Sande, DIMAFI u otra → Manual).
        </p>
        <div id="ci-form-msg" style="display:none;margin-bottom:12px;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;align-items:end;">
            <label style="grid-column:1 / -1;">
                Buscar SKU local
                <input type="search" id="ci-sku-search" class="regular-text" style="width:100%;" placeholder="SKU, nombre o código…">
            </label>
            <ul id="ci-sku-results" style="grid-column:1 / -1;max-height:160px;overflow:auto;margin:0;padding-left:18px;display:none;"></ul>
            <div style="grid-column:1 / -1;padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1;">
                <strong>SKU seleccionado:</strong>
                <span id="ci-selected-label" class="description">Ninguno</span>
                <input type="hidden" id="ci-producto-base-id" value="">
                <input type="hidden" id="ci-producto-nombre" value="">
            </div>
            <label style="grid-column:1 / -1;">
                URL del competidor
                <input type="url" id="ci-url" class="regular-text" style="width:100%;" placeholder="https://…">
            </label>
            <label>
                Precio total (bruto)
                <input type="number" id="ci-precio" class="regular-text" style="width:100%;" min="0" step="1" placeholder="0">
            </label>
            <label>
                Unidad (cantidad mín.)
                <input type="number" id="ci-unidad" class="regular-text" style="width:100%;" min="1" step="1" value="1">
            </label>
            <label>
                Tipo de match <span style="color:#d63638;">*</span>
                <select id="ci-tipo-match" style="width:100%;">
                    <option value="">— seleccionar —</option>
                                        <option value="exacto">Exacto (mismo producto)</option>
                    <option value="exacto_envase">Exacto diferente U de envase</option>
                    <option value="similar">Similar (equivalente funcional)</option>
                    <option value="otro">Otro</option>
                </select>
                <div id="ci-tipo-warnings" style="display:none;margin-top:8px;"></div>
            </label>
            <label>
                Fecha de ingreso
                <input type="text" class="regular-text" style="width:100%;" value="<?php echo esc_attr($now_display); ?>" readonly>
            </label>
            <label style="grid-column:1 / -1;">
                Nota (opcional)
                <textarea id="ci-nota" class="large-text" rows="2" style="width:100%;"></textarea>
            </label>
        </div>
        <p style="margin:16px 0 0;">
            <button type="button" class="button button-primary" id="ci-save-btn">Guardar y confirmar</button>
            <button type="button" class="button" id="ci-google-btn">Buscar en Google</button>
            <button type="button" class="button" id="ci-clear-btn">Limpiar</button>
        </p>
    </div>

    <div class="card" style="max-width:1200px;padding:16px 20px;margin-top:16px;">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
            <label>
                Filtro
                <select id="ci-filtro" class="regular-text">
                    <option value="todos">Todos</option>
                    <option value="sugerencias">Con sugerencias</option>
                    <option value="sin_mapeo">Sin ningún mapeo</option>
                    <option value="con_vinculo">Con al menos un vínculo</option>
                </select>
            </label>
            <label style="flex:1;min-width:220px;">
                Buscar en tabla
                <input type="search" id="ci-table-search" class="regular-text" style="width:100%;" placeholder="SKU o nombre…">
            </label>
            <button type="button" class="button" id="ci-refresh-btn">Actualizar</button>
        </div>
    </div>

    <div class="card" style="max-width:1200px;padding:0;margin-top:16px;overflow:hidden;">
        <table class="widefat striped" id="ci-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Nombre</th>
                    <th>Marca</th>
                    <th>Sugerencias</th>
                    <th>Vinculados</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="ci-tbody">
                <tr><td colspan="6">Cargando…</td></tr>
            </tbody>
        </table>
        <p style="padding:12px 16px;margin:0;">
            <button type="button" class="button" id="ci-prev-btn" disabled>Anterior</button>
            <span id="ci-page-label" style="margin:0 12px;">Página 1</span>
            <button type="button" class="button" id="ci-next-btn" disabled>Siguiente</button>
        </p>
    </div>

<script>
(function($) {
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    let page = 1;
    let searchTimer = null;
    let skuTimer = null;
    let listXhr = null;
    let skuXhr = null;
    const SEARCH_DEBOUNCE_MS = 800;
    const SEARCH_MIN_CHARS = 2;

    let ciUnitContext = null;
    function tipoMatchLabel(t) {
        const map = {
            exacto: 'Exacto',
            exacto_envase: 'Exacto diferente U de envase',
            similar: 'Similar',
            otro: 'Otro'
        };
        return map[t] || t || '—';
    }
    function badgeU(show) {
        return show
            ? ' <span style="display:inline-block;padding:0 6px;border-radius:8px;background:#2271b1;color:#fff;font-size:11px;font-weight:600;">U</span>'
            : '';
    }
    function renderTipoWarnings($box, ctx, tipo) {
        if (!$box || !$box.length) return;
        ctx = ctx || {};
        const parts = [];
        if (tipo === 'exacto_envase') {
            if (ctx.family_status === 'unknown' || ctx.family_status === 'missing') {
                parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;">'
                    + '<strong>Advertencia familia:</strong> ' + esc(ctx.family_warning || 'Revisa el estado de familia.')
                    + '</div>');
            }
            if (ctx.badge_u || ctx.is_unitario) {
                parts.push('<div style="padding:8px 10px;background:#edf5fb;border-left:4px solid #2271b1;">'
                    + 'Producto local unitario' + badgeU(true)
                    + ' — se puede relacionar con cualquier unidad de envase de competencia ('
                    + esc(String(ctx.cantidad_min || 1)) + ' u) con este tipo.'
                    + '</div>');
            }
        } else if (tipo && tipo !== 'exacto_envase' && ctx.units_differ) {
            parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;">'
                + '<strong>Unidades distintas:</strong> local '
                + esc(ctx.local_unit_label || '1') + badgeU(!!ctx.badge_u)
                + ' vs competencia ' + esc(String(ctx.cantidad_min || 1)) + ' u. '
                + 'Si es el mismo producto con otro envase, usa “Exacto diferente U de envase”.'
                + '</div>');
        }
        if (!parts.length) {
            $box.hide().empty();
            return;
        }
        $box.html(parts.join('')).show();
    }
    function confirmUnitsIfNeeded(tipo, ctx) {
        ctx = ctx || {};
        if (!tipo || tipo === 'exacto_envase' || !ctx.units_differ) {
            return true;
        }
        return window.confirm(
            'Las unidades son distintas (local ' + (ctx.local_unit_label || '1')
            + ' vs competencia ' + (ctx.cantidad_min || 1)
            + ' u). ¿Confirmas de todos modos?\n\nSi es el mismo producto con otro envase, cancela y elige “Exacto diferente U de envase”.'
        );
    }
    function refreshCiUnitContext() {
        const pb = parseInt($('#ci-producto-base-id').val() || '0', 10);
        const unidad = parseInt($('#ci-unidad').val() || '1', 10) || 1;
        const tipo = $('#ci-tipo-match').val() || '';
        if (pb <= 0) {
            ciUnitContext = null;
            renderTipoWarnings($('#ci-tipo-warnings'), null, tipo);
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_competencia_unit_context',
            nonce,
            producto_base_id: pb,
            cantidad_min: unidad
        }).done(function(res) {
            if (!res.success) return;
            ciUnitContext = res.data.unit_context || null;
            $('#ci-selected-u').html(badgeU(!!(ciUnitContext && ciUnitContext.badge_u)));
            renderTipoWarnings($('#ci-tipo-warnings'), ciUnitContext, $('#ci-tipo-match').val() || '');
        });
    }
    $(document).on('change input', '#ci-unidad, #ci-tipo-match', function() {
        if ($(this).is('#ci-unidad')) {
            refreshCiUnitContext();
        } else {
            renderTipoWarnings($('#ci-tipo-warnings'), ciUnitContext, $('#ci-tipo-match').val() || '');
        }
    });

    function esc(s) {
        return $('<div/>').text(s || '').html();
    }
    function escAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;');
    }
    function abortXhr(xhr) {
        if (xhr && xhr.readyState !== 4) xhr.abort();
    }
    function googleUrl(nombre) {
        return 'https://www.google.com/search?q=' + encodeURIComponent(nombre || '');
    }
    function fuenteBadge(f) {
        const slug = (f.slug || '').toLowerCase();
        const label = f.nombre || f.slug || '?';
        let color = '#646970';
        if (slug === 'sande') color = '#2271b1';
        else if (slug === 'dimafi') color = '#8c5e00';
        else if (slug === 'manual') color = '#007017';
        return '<span style="display:inline-block;margin:0 4px 4px 0;padding:1px 8px;border-radius:10px;background:' +
            color + ';color:#fff;font-size:11px;">' + esc(label) + '</span>';
    }
    function selectSku(id, sku, nombre) {
        $('#ci-producto-base-id').val(id);
        $('#ci-producto-nombre').val(nombre || '');
        $('#ci-selected-label').html('<code>' + esc(sku) + '</code> — ' + esc(nombre || '') + ' <span id="ci-selected-u"></span>');
        $('#ci-sku-results').hide().empty();
        $('#ci-sku-search').val('');
        refreshCiUnitContext();
    }
    function showMsg(html, isError) {
        const $m = $('#ci-form-msg').show().html(html);
        $m.css({
            padding: '10px 12px',
            borderLeft: '4px solid ' + (isError ? '#d63638' : '#00a32a'),
            background: isError ? '#fcf0f1' : '#edfaef'
        });
    }
    function clearForm() {
        $('#ci-producto-base-id').val('');
        $('#ci-producto-nombre').val('');
        $('#ci-selected-label').text('Ninguno').attr('class', 'description');
        $('#ci-url').val('');
        $('#ci-precio').val('');
        $('#ci-unidad').val('1');
        $('#ci-tipo-match').val('');
        $('#ci-nota').val('');
        $('#ci-form-msg').hide().empty();
        $('#ci-sku-results').hide().empty();
        $('#ci-tipo-warnings').hide().empty();
        ciUnitContext = null;
    }

    function loadSkus() {
        abortXhr(listXhr);
        $('#ci-tbody').html('<tr><td colspan="6">Cargando…</td></tr>');
        listXhr = $.post(ajaxurl, {
            action: 'riverso_competencia_list_skus',
            nonce,
            filtro: $('#ci-filtro').val(),
            search: $('#ci-table-search').val(),
            page,
            per_page: 25
        }).done(function(res) {
            if (!res.success) {
                $('#ci-tbody').html('<tr><td colspan="6">' + esc((res.data && res.data.message) || 'Error') + '</td></tr>');
                return;
            }
            const rows = res.data.rows || [];
            const total = res.data.total || 0;
            const perPage = res.data.per_page || 25;
            const pages = Math.max(1, Math.ceil(total / perPage));
            $('#ci-page-label').text('Página ' + page + ' / ' + pages + ' (' + total + ' SKUs)');
            $('#ci-prev-btn').prop('disabled', page <= 1);
            $('#ci-next-btn').prop('disabled', page >= pages);
            if (!rows.length) {
                $('#ci-tbody').html('<tr><td colspan="6">Sin resultados</td></tr>');
                return;
            }
            const html = rows.map(function(r) {
                const badges = (r.fuentes || []).map(fuenteBadge).join('') || '<span class="description">—</span>';
                const skuLocal = r.sku_local || r.canonical_sku || '';
                const skuOnline = r.sku_online || '';
                const nombreCell =
                    esc(r.nombre_canonico || '') +
                    '<br><span class="description">SKU local: <code>' + esc(skuLocal || '—') + '</code></span>' +
                    '<br><span class="description">SKU online: <code>' + esc(skuOnline || '—') + '</code></span>';
                const sugCount = Number(r.sugerencias || 0);
                const sugCell = sugCount > 0
                    ? '<button type="button" class="button ci-ver-sug" data-id="' + r.id +
                      '" data-sku="' + escAttr(skuLocal) +
                      '" data-nombre="' + escAttr(r.nombre_canonico || '') + '">Ver (' + sugCount + ')</button>'
                    : '<span class="description">0</span>';
                return '<tr>' +
                    '<td><code>' + esc(skuLocal) + '</code></td>' +
                    '<td>' + nombreCell + '</td>' +
                    '<td>' + esc(r.marca || '—') + '</td>' +
                    '<td>' + sugCell + '</td>' +
                    '<td>' + badges + (r.vinculados ? ' <span class="description">(' + r.vinculados + ')</span>' : '') + '</td>' +
                    '<td style="white-space:nowrap;">' +
                    '<button type="button" class="button button-primary ci-pick" data-id="' + r.id +
                    '" data-sku="' + escAttr(skuLocal) +
                    '" data-nombre="' + escAttr(r.nombre_canonico || '') + '">Seleccionar</button> ' +
                    '<a class="button" href="' + escAttr(googleUrl(r.nombre_canonico)) +
                    '" target="_blank" rel="noopener noreferrer">Google</a>' +
                    '</td></tr>';
            }).join('');
            $('#ci-tbody').html(html);
        }).fail(function() {
            $('#ci-tbody').html('<tr><td colspan="6">Error al cargar SKUs</td></tr>');
        });
    }

    function runSkuSearch(q) {
        q = (q || '').trim();
        if (!q || q.length < SEARCH_MIN_CHARS) {
            $('#ci-sku-results').hide().empty();
            return;
        }
        abortXhr(skuXhr);
        skuXhr = $.post(ajaxurl, { action: 'riverso_competencia_search_local', nonce, search: q })
            .done(function(res) {
                if (!res.success) return;
                const items = (res.data.products || []).map(function(p) {
                    return '<li style="margin-bottom:6px;"><button type="button" class="button ci-pick" data-id="' + p.id +
                        '" data-sku="' + escAttr(p.canonical_sku || '') +
                        '" data-nombre="' + escAttr(p.nombre_canonico || '') + '">' +
                        esc(p.canonical_sku) + ' — ' + esc(p.nombre_canonico) + '</button></li>';
                }).join('');
                $('#ci-sku-results').html(items || '<li>Sin resultados</li>').show();
            });
    }

    $('#ci-sku-search').on('input', function() {
        clearTimeout(skuTimer);
        const q = $(this).val();
        skuTimer = setTimeout(function() { runSkuSearch(q); }, SEARCH_DEBOUNCE_MS);
    });
    $('#ci-sku-search').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            clearTimeout(skuTimer);
            runSkuSearch($(this).val());
        }
    });

    $(document).on('click', '.ci-pick', function() {
        const $b = $(this);
        selectSku($b.data('id'), $b.data('sku'), $b.data('nombre'));
    });

    $('#ci-google-btn').on('click', function() {
        const nombre = $('#ci-producto-nombre').val() || $('#ci-sku-search').val();
        if (!nombre) {
            alert('Selecciona un SKU o escribe un nombre para buscar.');
            return;
        }
        window.open(googleUrl(nombre), '_blank', 'noopener,noreferrer');
    });

    $('#ci-clear-btn').on('click', clearForm);

    $('#ci-save-btn').on('click', function() {
        const $btn = $(this);
        const tipo = $('#ci-tipo-match').val() || '';
        if (!tipo) {
            alert('Debes seleccionar el tipo de match.');
            return;
        }
        const runSave = function() {
        $btn.prop('disabled', true).text('Guardando…');
        $('#ci-form-msg').hide();
        $.post(ajaxurl, {
            action: 'riverso_competencia_manual_ingreso',
            nonce,
            producto_base_id: $('#ci-producto-base-id').val(),
            url: $('#ci-url').val(),
            precio_total: $('#ci-precio').val(),
            unidad: $('#ci-unidad').val() || 1,
            tipo_match: $('#ci-tipo-match').val(),
            nota: $('#ci-nota').val()
        }).done(function(res) {
            $btn.prop('disabled', false).text('Guardar y confirmar');
            if (!res.success) {
                let html = '<strong>' + esc((res.data && res.data.message) || 'Error al guardar') + '</strong>';
                const blockers = (res.data && res.data.blockers) || [];
                if (blockers.length) {
                    html += '<ul style="margin:8px 0 0;padding-left:18px;">';
                    blockers.forEach(function(b) {
                        html += '<li>' + (b.url
                            ? '<a href="' + escAttr(b.url) + '" target="_blank" rel="noopener noreferrer">' + esc(b.label || b.tipo) + '</a>'
                            : esc(b.label || b.tipo)) + '</li>';
                    });
                    html += '</ul>';
                }
                if (res.data && res.data.unit_hint) {
                    html += '<p class="description" style="margin:8px 0 0;">' + esc(res.data.unit_hint) + '</p>';
                }
                showMsg(html, true);
                return;
            }
            const d = res.data || {};
            showMsg(
                'Vínculo confirmado (' + esc(d.fuente_slug || 'manual') + ')' +
                (d.revisado_at ? ' · ' + esc(d.revisado_at) : '') + '.',
                false
            );
            $('#ci-url').val('');
            $('#ci-precio').val('');
            $('#ci-unidad').val('1');
            $('#ci-tipo-match').val('');
            $('#ci-nota').val('');
            loadSkus();
        }).fail(function(xhr) {
            $btn.prop('disabled', false).text('Guardar y confirmar');
            let msg = 'Error de red al guardar.';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            showMsg(esc(msg), true);
        });
        };
        const doConfirm = function() {
            if (!confirmUnitsIfNeeded(tipo, ciUnitContext)) {
                return;
            }
            runSave();
        };
        const pb = parseInt($('#ci-producto-base-id').val() || '0', 10);
        const unidad = parseInt($('#ci-unidad').val() || '1', 10) || 1;
        if (pb > 0 && !ciUnitContext) {
            $.post(ajaxurl, {
                action: 'riverso_competencia_unit_context',
                nonce,
                producto_base_id: pb,
                cantidad_min: unidad
            }).done(function(res) {
                if (res.success) {
                    ciUnitContext = res.data.unit_context || null;
                    renderTipoWarnings($('#ci-tipo-warnings'), ciUnitContext, tipo);
                }
                doConfirm();
            }).fail(function() { doConfirm(); });
            return;
        }
        doConfirm();
    });

    $('#ci-filtro').on('change', function() { page = 1; loadSkus(); });
    $('#ci-refresh-btn').on('click', function() { page = 1; loadSkus(); });
    $('#ci-prev-btn').on('click', function() { if (page > 1) { page--; loadSkus(); } });
    $('#ci-next-btn').on('click', function() { page++; loadSkus(); });
    $('#ci-table-search').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { page = 1; loadSkus(); }, SEARCH_DEBOUNCE_MS);
    });
    $('#ci-table-search').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            clearTimeout(searchTimer);
            page = 1;
            loadSkus();
        }
    });

    loadSkus();

    let sugPbId = 0;
    let sugAction = { type: '', id: 0, pb: 0 };
    let sugUnitContext = null;

    function fmtSugPrice(row) {
        const brutoU = row.precio_bruto_unitario;
        const brutoT = row.precio_bruto_total;
        const qty = Number(row.cantidad_min || 0);
        const parts = [];
        if (brutoU !== null && brutoU !== undefined && brutoU !== '') {
            const u = Number(brutoU);
            if (!isNaN(u)) {
                parts.push(u.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 3 }) + ' / u');
            }
        }
        if (brutoT !== null && brutoT !== undefined && brutoT !== '') {
            const t = Number(brutoT);
            if (!isNaN(t)) {
                const pack = qty > 1 ? ' / ' + qty.toLocaleString('es-CL') + ' u' : '';
                parts.push(t.toLocaleString('es-CL', { maximumFractionDigits: 0 }) + pack);
            }
        }
        return parts.join(' · ') || '—';
    }

    function renderSugBlockers(blockers, message, unitHint) {
        const $box = $('#ci-sug-action-blockers').empty().hide();
        const $hint = $('#ci-sug-action-hint').empty().hide();
        if (message) {
            $box.append('<p style="margin:0 0 8px;"><strong>' + esc(message) + '</strong></p>');
        }
        if (blockers && blockers.length) {
            const ul = $('<ul style="margin:0;padding-left:18px;"></ul>');
            blockers.forEach(function(b) {
                const link = b.url
                    ? '<a href="' + escAttr(b.url) + '" target="_blank" rel="noopener noreferrer">' + esc(b.label || b.tipo) + '</a>'
                    : esc(b.label || b.tipo);
                ul.append('<li>' + link + ' <span class="description">(' + esc(b.estado || '') + ')</span></li>');
            });
            $box.append(ul);
            $box.show();
        } else if (message) {
            $box.show();
        }
        if (unitHint) {
            $hint.text(unitHint).show();
        }
    }

    function loadSugerencias(pbId, sku, nombre) {
        sugPbId = pbId;
        $('#ci-sug-title').text('Sugerencias — ' + (sku || ('#' + pbId)));
        $('#ci-sug-meta').html(esc(nombre || '') + ' · Cargando…');
        $('#ci-sug-tbody').html('<tr><td colspan="5">Cargando…</td></tr>');
        $('#ci-sug-modal').show();
        $.post(ajaxurl, {
            action: 'riverso_competencia_list_sugerencias',
            nonce,
            producto_base_id: pbId
        }).done(function(res) {
            if (!res.success) {
                $('#ci-sug-meta').text((res.data && res.data.message) || 'Error');
                $('#ci-sug-tbody').html('<tr><td colspan="5">Sin datos</td></tr>');
                return;
            }
            const p = res.data.producto || {};
            const rows = res.data.rows || [];
            $('#ci-sug-meta').html(
                '<code>' + esc(p.canonical_sku || sku || '') + '</code> — ' + esc(p.nombre_canonico || nombre || '') +
                ' · ' + rows.length + ' sugerencia(s)'
            );
            if (!rows.length) {
                $('#ci-sug-tbody').html('<tr><td colspan="5">Sin sugerencias pendientes</td></tr>');
                return;
            }
            const html = rows.map(function(r) {
                const nombreComp = r.url_producto
                    ? '<a href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">' + esc(r.nombre || '') + '</a>'
                    : esc(r.nombre || '—');
                const actions = [];
                if (r.url_producto) {
                    actions.push('<a class="button" href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">Ver</a>');
                }
                actions.push(
                    '<button type="button" class="button button-primary ci-sug-confirm" data-id="' + r.id +
                    '" data-pb="' + pbId +
                    '" data-codigo="' + escAttr((r.codigo_externo || '').trim()) +
                    '" data-nombre="' + escAttr(r.nombre || '') +
                    '" data-fuente="' + escAttr(r.fuente_nombre || r.fuente_slug || '') +
                    '">Confirmar</button>'
                );
                actions.push(
                    '<button type="button" class="button ci-sug-reject" data-id="' + r.id +
                    '" data-codigo="' + escAttr((r.codigo_externo || '').trim()) +
                    '" data-nombre="' + escAttr(r.nombre || '') +
                    '" data-fuente="' + escAttr(r.fuente_nombre || r.fuente_slug || '') +
                    '">Rechazar</button>'
                );
                return '<tr>' +
                    '<td>' + fuenteBadge({ slug: r.fuente_slug, nombre: r.fuente_nombre }) + '</td>' +
                    '<td><code>' + esc((r.codigo_externo || '').trim()) + '</code><br>' + nombreComp +
                    (r.nombre_categoria ? '<br><span class="description">' + esc(r.nombre_categoria) + '</span>' : '') +
                    '</td>' +
                    '<td>' + esc(fmtSugPrice(r)) + '</td>' +
                    '<td>' + esc(r.score || '') + ' / ' + esc(r.metodo || '—') + '</td>' +
                    '<td style="white-space:nowrap;">' + actions.join(' ') + '</td>' +
                    '</tr>';
            }).join('');
            $('#ci-sug-tbody').html(html);
        }).fail(function() {
            $('#ci-sug-meta').text('Error al cargar sugerencias');
            $('#ci-sug-tbody').html('<tr><td colspan="5">Error</td></tr>');
        });
    }

    $(document).on('click', '.ci-ver-sug', function() {
        const $b = $(this);
        loadSugerencias($b.data('id'), $b.data('sku'), $b.data('nombre'));
    });

    $(document).on('click', '#ci-sug-close', function(e) {
        e.preventDefault();
        $('#ci-sug-modal').hide();
    });
    $(document).on('click', '#ci-sug-modal', function(e) {
        if (e.target === this) {
            $('#ci-sug-modal').hide();
        }
    });

    function closeSugAction() {
        $('#ci-sug-action-modal').hide();
        sugAction = { type: '', id: 0, pb: 0 };
    }

    $(document).on('click', '.ci-sug-confirm', function() {
        const $btn = $(this);
        sugAction = { type: 'confirm', id: $btn.data('id'), pb: $btn.data('pb') };
        $('#ci-sug-action-title').text('Confirmar vínculo');
        $('#ci-sug-action-summary').html(
            '<p><strong>' + esc($btn.data('fuente') || '') + ':</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p class="description">Cargando validación de familia…</p>'
        );
        $('#ci-sug-action-nota').val('');
        $('#ci-sug-action-nota-wrap').show();
        $('#ci-sug-action-tipo').val('');
        $('#ci-sug-action-tipo-error').hide();
        $('#ci-sug-action-tipo-wrap').show();
        $('#ci-sug-action-submit').prop('disabled', true).text('Confirmar').show();
        renderSugBlockers([], '', '');
        $('#ci-sug-action-modal').show();

        $.post(ajaxurl, {
            action: 'riverso_competencia_confirm_preflight',
            nonce,
            producto_competencia_id: sugAction.id,
            producto_base_id: sugAction.pb
        }, function(res) {
            if (!res.success) {
                $('#ci-sug-action-summary').html('<p class="description">No se pudo validar el match.</p>');
                return;
            }
            const d = res.data;
            const sande = d.sande || {};
            const local = d.local || {};
            sugUnitContext = d.unit_context || null;
            const localBadge = (local.badge_u || (sugUnitContext && sugUnitContext.badge_u)) ? badgeU(true) : '';
            $('#ci-sug-action-summary').html(
                '<p><strong>' + esc($btn.data('fuente') || '') + ':</strong> <code>' + esc(sande.codigo || $btn.data('codigo') || '') + '</code> — ' + esc(sande.nombre || $btn.data('nombre') || '')
                + ' <span class="description">(' + esc(String((sande.cantidad_min || (sugUnitContext && sugUnitContext.cantidad_min) || 1))) + ' u)</span></p>' +
                '<p><strong>Local:</strong> <code>' + esc(local.canonical_sku || '') + '</code> — ' + esc(local.nombre_canonico || '') + localBadge
                + ' <span class="description">unidad ' + esc((local.unit_label || (sugUnitContext && sugUnitContext.local_unit_label) || '1')) + '</span></p>' +
                (d.can_confirm ? '<p>¿Confirmas que es el mismo producto?</p>' : '')
            );
            renderSugBlockers(d.blockers || [], d.message || '', d.unit_hint || '');
            renderTipoWarnings($('#ci-sug-action-tipo-warnings'), sugUnitContext, $('#ci-sug-action-tipo').val() || '');
            if (d.can_confirm) {
                $('#ci-sug-action-submit').prop('disabled', false).show();
                $('#ci-sug-action-nota-wrap').show();
            } else {
                $('#ci-sug-action-submit').hide();
                $('#ci-sug-action-nota-wrap').hide();
            }
        });
    });

    $(document).on('change', '#ci-sug-action-tipo', function() {
        renderTipoWarnings($('#ci-sug-action-tipo-warnings'), sugUnitContext, $(this).val() || '');
    });

    $(document).on('click', '.ci-sug-reject', function() {
        const $btn = $(this);
        sugAction = { type: 'reject', id: $btn.data('id'), pb: 0 };
        $('#ci-sug-action-title').text('Rechazar sugerencia');
        $('#ci-sug-action-summary').html(
            '<p><strong>' + esc($btn.data('fuente') || '') + ':</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p>Al rechazar, el producto sale de la bandeja y no se volverá a sugerir automáticamente.</p>'
        );
        renderSugBlockers([], '', '');
        $('#ci-sug-action-nota').val('');
        $('#ci-sug-action-nota-wrap').show();
        $('#ci-sug-action-tipo-wrap').hide();
        $('#ci-sug-action-submit').prop('disabled', false).text('Rechazar').show();
        $('#ci-sug-action-modal').show();
    });

    $(document).on('click', '#ci-sug-action-cancel', function(e) {
        e.preventDefault();
        closeSugAction();
    });
    $(document).on('click', '#ci-sug-action-modal', function(e) {
        if (e.target === this) closeSugAction();
    });

    $(document).on('click', '#ci-sug-action-submit', function() {
        if (!sugAction.type || !sugAction.id) return;
        const $btn = $(this).prop('disabled', true);
        const nota = $('#ci-sug-action-nota').val();
        if (sugAction.type === 'confirm') {
            const tipo_match = $('#ci-sug-action-tipo').val();
            if (!tipo_match) {
                $('#ci-sug-action-tipo-error').show();
                $btn.prop('disabled', false);
                return;
            }
            $('#ci-sug-action-tipo-error').hide();
            if (!confirmUnitsIfNeeded(tipo_match, sugUnitContext)) {
                $btn.prop('disabled', false);
                return;
            }
            $.post(ajaxurl, {
                action: 'riverso_competencia_confirm_match',
                nonce,
                producto_competencia_id: sugAction.id,
                producto_base_id: sugAction.pb,
                nota,
                tipo_match
            }, function(res) {
                $btn.prop('disabled', false);
                if (!res.success) {
                    renderSugBlockers(
                        res.data && res.data.blockers ? res.data.blockers : [],
                        (res.data && res.data.message) || 'Error al confirmar',
                        (res.data && res.data.unit_hint) || ''
                    );
                    $btn.hide();
                    return;
                }
                closeSugAction();
                loadSugerencias(sugPbId, '', '');
                loadSkus();
            });
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_competencia_reject_match',
            nonce,
            producto_competencia_id: sugAction.id,
            nota
        }, function(res) {
            $btn.prop('disabled', false);
            if (!res.success) {
                alert((res.data && res.data.message) || 'Error al rechazar');
                return;
            }
            closeSugAction();
            loadSugerencias(sugPbId, '', '');
            loadSkus();
        });
    });
})(jQuery);
</script>

<div id="ci-sug-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;">
    <div style="background:#fff;max-width:960px;margin:5vh auto;padding:20px;border-radius:6px;max-height:90vh;overflow:auto;">
        <h2 style="margin-top:0;" id="ci-sug-title">Sugerencias</h2>
        <div id="ci-sug-meta" class="description" style="margin-bottom:12px;"></div>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Fuente</th>
                    <th>Producto competidor</th>
                    <th>Precio</th>
                    <th>Score / Método</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="ci-sug-tbody">
                <tr><td colspan="5">Cargando…</td></tr>
            </tbody>
        </table>
        <p style="margin-top:16px;">
            <button type="button" class="button" id="ci-sug-close">Cerrar</button>
        </p>
    </div>
</div>

<div id="ci-sug-action-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100001;">
    <div style="background:#fff;max-width:560px;margin:8vh auto;padding:20px;border-radius:6px;">
        <h2 style="margin-top:0;" id="ci-sug-action-title">Confirmar match</h2>
        <div id="ci-sug-action-summary" style="margin-bottom:12px;"></div>
        <div id="ci-sug-action-blockers" style="display:none;margin-bottom:12px;padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638;"></div>
        <div id="ci-sug-action-hint" class="description" style="display:none;margin-bottom:12px;"></div>
        <label id="ci-sug-action-tipo-wrap" style="display:block;margin-bottom:12px;">
            Tipo de match <span style="color:#d63638;">*</span>
            <select id="ci-sug-action-tipo" style="width:100%;margin-top:4px;">
                <option value="">— seleccionar —</option>
                                <option value="exacto">Exacto (mismo producto)</option>
                <option value="exacto_envase">Exacto diferente U de envase</option>
                <option value="similar">Similar (equivalente funcional)</option>
                <option value="otro">Otro</option>
            </select>
            <span id="ci-sug-action-tipo-error" style="display:none;color:#d63638;font-size:12px;">Debes seleccionar el tipo de match.</span>
            <div id="ci-sug-action-tipo-warnings" style="display:none;margin-top:8px;"></div>
        </label>
        <label id="ci-sug-action-nota-wrap" style="display:block;margin-bottom:12px;">
            Nota (opcional)
            <textarea id="ci-sug-action-nota" class="large-text" rows="2" style="width:100%;"></textarea>
        </label>
        <p style="margin:0;">
            <button type="button" class="button button-primary" id="ci-sug-action-submit">Confirmar</button>
            <button type="button" class="button" id="ci-sug-action-cancel">Cancelar</button>
        </p>
    </div>
</div>

<?php elseif ($modo === 'fuentes') : ?>

    <div class="card" style="max-width:1200px;padding:16px 20px;margin-top:12px;">
        <h2 style="margin-top:0;">Fuentes vinculadas</h2>
        <p class="description" style="margin-top:0;">
            Todos los vínculos confirmados, con el tipo de fuente (Sande, DIMAFI, Manual u otras).
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
            <label>
                Fuente
                <select id="cf-fuente" class="regular-text">
                    <option value="">Todas</option>
                    <option value="sande">Sande</option>
                    <option value="dimafi">DIMAFI</option>
                    <option value="manual">Manual</option>
                </select>
            </label>
            <label style="flex:1;min-width:220px;">
                Buscar
                <input type="search" id="cf-search" class="regular-text" style="width:100%;" placeholder="SKU, nombre o URL…">
            </label>
            <button type="button" class="button" id="cf-refresh-btn">Actualizar</button>
        </div>
    </div>

    <div class="card" style="max-width:1200px;padding:0;margin-top:16px;overflow:hidden;">
        <table class="widefat striped" id="cf-table">
            <thead>
                <tr>
                    <th>SKU local</th>
                    <th>Nombre local</th>
                    <th>Fuente</th>
                    <th>Producto / URL</th>
                    <th>Precio bruto</th>
                    <th>Unidad</th>
                    <th>Tipo</th>
                    <th>Ingreso</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="cf-tbody">
                <tr><td colspan="9">Cargando…</td></tr>
            </tbody>
        </table>
        <p style="padding:12px 16px;margin:0;">
            <button type="button" class="button" id="cf-prev-btn" disabled>Anterior</button>
            <span id="cf-page-label" style="margin:0 12px;">Página 1</span>
            <button type="button" class="button" id="cf-next-btn" disabled>Siguiente</button>
        </p>
    </div>

<div id="cf-edit-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;">
    <div style="background:#fff;max-width:560px;margin:8vh auto;padding:20px;border-radius:6px;">
        <h2 style="margin-top:0;" id="cf-edit-title">Editar fuente</h2>
        <div id="cf-edit-summary" style="margin-bottom:12px;"></div>
        <div id="cf-edit-form">
            <p id="cf-edit-refresh-warn" class="description" style="display:none;margin:0 0 12px;color:#8c5e00;">
                Este precio puede ser sobrescrito por el próximo refresh automático de la fuente.
            </p>
            <label style="display:block;margin-bottom:10px;">
                Precio bruto total
                <input type="number" id="cf-edit-precio" class="regular-text" style="width:100%;" min="0" step="1">
            </label>
            <label style="display:block;margin-bottom:10px;">
                Unidad (cantidad mínima)
                <input type="number" id="cf-edit-unidad" class="regular-text" style="width:100%;" min="1" step="1">
            </label>
            <p class="description" id="cf-edit-unitario" style="margin:0 0 12px;"></p>
            <label style="display:block;margin-bottom:10px;">
                Tipo de match
                <select id="cf-edit-tipo" class="regular-text" style="width:100%;">
                    <option value="exacto">Exacto</option>
                    <option value="exacto_envase">Exacto diferente U de envase</option>
                    <option value="similar">Similar</option>
                    <option value="otro">Otro</option>
                </select>
            </label>
        </div>
        <div id="cf-edit-confirm" style="display:none;margin-bottom:12px;">
            <p style="margin:0 0 8px;"><strong>¿Estás seguro de guardar estos cambios?</strong></p>
            <div id="cf-edit-diff" style="background:#f6f7f7;padding:10px 12px;border-left:4px solid #2271b1;"></div>
        </div>
        <div id="cf-edit-delete-confirm" style="display:none;margin-bottom:12px;">
            <p style="margin:0;">¿Estás seguro de eliminar esta fuente? El vínculo dejará de aparecer aquí.</p>
        </div>
        <div id="cf-edit-msg" style="display:none;margin-bottom:12px;"></div>
        <p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="button button-primary" id="cf-edit-save">Guardar</button>
            <button type="button" class="button button-primary" id="cf-edit-confirm-save" style="display:none;">Confirmar cambios</button>
            <button type="button" class="button" id="cf-edit-cancel">Cancelar</button>
            <button type="button" class="button" id="cf-edit-delete" style="margin-left:auto;color:#b32d2e;border-color:#b32d2e;">Eliminar</button>
            <button type="button" class="button button-primary" id="cf-edit-confirm-delete" style="display:none;margin-left:auto;background:#b32d2e;border-color:#b32d2e;">Confirmar eliminación</button>
        </p>
    </div>
</div>

<script>
(function($) {
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    let page = 1;
    let searchTimer = null;
    let listXhr = null;
    const SEARCH_DEBOUNCE_MS = 800;
    let editCtx = null;
    let editMode = 'edit'; // edit | confirm-save | confirm-delete

    function esc(s) {
        return $('<div/>').text(s || '').html();
    }
    function escAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;');
    }
    function abortXhr(xhr) {
        if (xhr && xhr.readyState !== 4) xhr.abort();
    }
    function fmtMoney(val) {
        if (val === null || val === undefined || val === '') return '—';
        const n = Number(val);
        if (isNaN(n)) return '—';
        return n.toLocaleString('es-CL', { maximumFractionDigits: 0 });
    }
    function fuenteLabel(slug, nombre) {
        const s = (slug || '').toLowerCase();
        let color = '#646970';
        if (s === 'sande') color = '#2271b1';
        else if (s === 'dimafi') color = '#8c5e00';
        else if (s === 'manual') color = '#007017';
        return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;background:' +
            color + ';color:#fff;font-size:11px;">' + esc(nombre || slug || '—') + '</span>';
    }

    function loadList() {
        abortXhr(listXhr);
        $('#cf-tbody').html('<tr><td colspan="9">Cargando…</td></tr>');
        listXhr = $.post(ajaxurl, {
            action: 'riverso_competencia_list_fuentes',
            nonce,
            fuente: $('#cf-fuente').val(),
            search: $('#cf-search').val(),
            page,
            per_page: 25
        }).done(function(res) {
            if (!res.success) {
                $('#cf-tbody').html('<tr><td colspan="9">' + esc((res.data && res.data.message) || 'Error') + '</td></tr>');
                return;
            }
            const rows = res.data.rows || [];
            const total = res.data.total || 0;
            const perPage = res.data.per_page || 25;
            const pages = Math.max(1, Math.ceil(total / perPage));
            $('#cf-page-label').text('Página ' + page + ' / ' + pages + ' (' + total + ' vínculos)');
            $('#cf-prev-btn').prop('disabled', page <= 1);
            $('#cf-next-btn').prop('disabled', page >= pages);
            if (!rows.length) {
                $('#cf-tbody').html('<tr><td colspan="9">Sin resultados</td></tr>');
                return;
            }
            const html = rows.map(function(r) {
                const sku = r.url_local
                    ? '<a href="' + escAttr(r.url_local) + '" target="_blank" rel="noopener noreferrer"><code>' + esc(r.canonical_sku) + '</code></a>'
                    : '<code>' + esc(r.canonical_sku || '—') + '</code>';
                const prod = r.url_producto
                    ? '<a href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">' + esc(r.nombre_competencia || r.url_producto) + '</a>'
                    : esc(r.nombre_competencia || '—');
                const codigo = (r.codigo_externo || '').trim()
                    ? '<br><code>' + esc(r.codigo_externo.trim()) + '</code>'
                    : '';
                const actions = [];
                actions.push(
                    '<button type="button" class="button cf-edit-btn"' +
                    ' data-id="' + escAttr(r.producto_competencia_id) + '"' +
                    ' data-sku="' + escAttr(r.canonical_sku || '') + '"' +
                    ' data-nombre="' + escAttr(r.nombre_canonico || '') + '"' +
                    ' data-fuente-slug="' + escAttr(r.fuente_slug || '') + '"' +
                    ' data-fuente-nombre="' + escAttr(r.fuente_nombre || '') + '"' +
                    ' data-producto="' + escAttr(r.nombre_competencia || '') + '"' +
                    ' data-url="' + escAttr(r.url_producto || '') + '"' +
                    ' data-precio="' + escAttr(r.precio_bruto_total != null ? r.precio_bruto_total : '') + '"' +
                    ' data-unidad="' + escAttr(r.cantidad_min || 1) + '"' +
                    ' data-tipo="' + escAttr(r.tipo_match || '') + '"' +
                    '>Editar</button>'
                );
                if (r.url_producto) {
                    actions.push('<a class="button" href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">Abrir URL</a>');
                }
                if (r.url_local) {
                    actions.push('<a class="button" href="' + escAttr(r.url_local) + '" target="_blank" rel="noopener noreferrer">Ver local</a>');
                }
                return '<tr>' +
                    '<td>' + sku + '</td>' +
                    '<td>' + esc(r.nombre_canonico || '—') + '</td>' +
                    '<td>' + fuenteLabel(r.fuente_slug, r.fuente_nombre) + '</td>' +
                    '<td>' + prod + codigo + '</td>' +
                    '<td><strong>' + fmtMoney(r.precio_bruto_total) + '</strong>' +
                    (r.precio_bruto_unitario ? '<br><span class="description">' + fmtMoney(r.precio_bruto_unitario) + ' / u</span>' : '') +
                    '</td>' +
                    '<td>' + esc(r.cantidad_min || '1') + '</td>' +
                    '<td>' + esc(({exacto:'Exacto',exacto_envase:'Exacto diferente U de envase',similar:'Similar',otro:'Otro'}[r.tipo_match] || r.tipo_match || '—')) + '</td>' +
                    '<td>' + esc(r.revisado_at || '—') + '</td>' +
                    '<td style="white-space:nowrap;">' + (actions.join(' ') || '—') + '</td>' +
                    '</tr>';
            }).join('');
            $('#cf-tbody').html(html);
        }).fail(function() {
            $('#cf-tbody').html('<tr><td colspan="9">Error al cargar</td></tr>');
        });
    }

    function setEditMsg(text, isError) {
        if (!text) {
            $('#cf-edit-msg').hide().empty();
            return;
        }
        $('#cf-edit-msg')
            .css('color', isError ? '#b32d2e' : '#007017')
            .html(esc(text))
            .show();
    }

    function updateUnitarioHint() {
        const precio = Number($('#cf-edit-precio').val());
        const unidad = Math.max(1, parseInt($('#cf-edit-unidad').val(), 10) || 1);
        if (!precio || precio <= 0) {
            $('#cf-edit-unitario').text('');
            return;
        }
        $('#cf-edit-unitario').text(fmtMoney(precio / unidad) + ' / u (derivado)');
    }

    function setEditMode(mode) {
        editMode = mode;
        $('#cf-edit-form').toggle(mode === 'edit');
        $('#cf-edit-confirm').toggle(mode === 'confirm-save');
        $('#cf-edit-delete-confirm').toggle(mode === 'confirm-delete');
        $('#cf-edit-save').toggle(mode === 'edit');
        $('#cf-edit-confirm-save').toggle(mode === 'confirm-save');
        $('#cf-edit-delete').toggle(mode === 'edit');
        $('#cf-edit-confirm-delete').toggle(mode === 'confirm-delete');
        if (mode === 'confirm-save') {
            $('#cf-edit-title').text('Confirmar cambios');
        } else if (mode === 'confirm-delete') {
            $('#cf-edit-title').text('Eliminar fuente');
        } else {
            $('#cf-edit-title').text('Editar fuente');
        }
    }

    function closeEditModal() {
        $('#cf-edit-modal').hide();
        editCtx = null;
        editMode = 'edit';
        setEditMsg('');
        $('#cf-edit-save, #cf-edit-confirm-save, #cf-edit-delete, #cf-edit-confirm-delete').prop('disabled', false);
    }

    function tipoMatchLabel(tipo) {
        return ({exacto:'Exacto',exacto_envase:'Exacto diferente U de envase',similar:'Similar',otro:'Otro'}[tipo] || tipo || '—');
    }

    function openEditModal($btn) {
        editCtx = {
            id: parseInt($btn.attr('data-id'), 10) || 0,
            sku: $btn.attr('data-sku') || '',
            nombre: $btn.attr('data-nombre') || '',
            fuenteSlug: ($btn.attr('data-fuente-slug') || '').toLowerCase(),
            fuenteNombre: $btn.attr('data-fuente-nombre') || '',
            producto: $btn.attr('data-producto') || '',
            url: $btn.attr('data-url') || '',
            precio: Number($btn.attr('data-precio')) || 0,
            unidad: Math.max(1, parseInt($btn.attr('data-unidad'), 10) || 1),
            tipo: $btn.attr('data-tipo') || ''
        };
        const prodHtml = editCtx.url
            ? '<a href="' + escAttr(editCtx.url) + '" target="_blank" rel="noopener noreferrer">' + esc(editCtx.producto || editCtx.url) + '</a>'
            : esc(editCtx.producto || '—');
        $('#cf-edit-summary').html(
            '<div><strong>SKU:</strong> <code>' + esc(editCtx.sku || '—') + '</code></div>' +
            '<div><strong>Local:</strong> ' + esc(editCtx.nombre || '—') + '</div>' +
            '<div style="margin-top:4px;"><strong>Fuente:</strong> ' + fuenteLabel(editCtx.fuenteSlug, editCtx.fuenteNombre) + '</div>' +
            '<div style="margin-top:4px;"><strong>Producto:</strong> ' + prodHtml + '</div>'
        );
        $('#cf-edit-precio').val(editCtx.precio > 0 ? editCtx.precio : '');
        $('#cf-edit-unidad').val(editCtx.unidad);
        const tipoVal = ['exacto','exacto_envase','similar','otro'].indexOf(editCtx.tipo) >= 0 ? editCtx.tipo : 'exacto';
        $('#cf-edit-tipo').val(tipoVal);
        $('#cf-edit-refresh-warn').toggle(editCtx.fuenteSlug === 'sande' || editCtx.fuenteSlug === 'dimafi');
        updateUnitarioHint();
        setEditMsg('');
        setEditMode('edit');
        $('#cf-edit-modal').show();
    }

    function buildDiffHtml(before, after) {
        const lines = [];
        if (before.precio !== after.precio) {
            lines.push('<div><strong>Precio bruto:</strong> ' + fmtMoney(before.precio) + ' → <strong>' + fmtMoney(after.precio) + '</strong></div>');
        }
        if (before.unidad !== after.unidad) {
            lines.push('<div><strong>Unidad:</strong> ' + esc(String(before.unidad)) + ' → <strong>' + esc(String(after.unidad)) + '</strong></div>');
        }
        if (before.tipo !== after.tipo) {
            lines.push('<div><strong>Tipo:</strong> ' + esc(tipoMatchLabel(before.tipo)) + ' → <strong>' + esc(tipoMatchLabel(after.tipo)) + '</strong></div>');
        }
        if (before.precio !== after.precio || before.unidad !== after.unidad) {
            lines.push('<div class="description" style="margin-top:6px;">Unitario: ' +
                fmtMoney(before.unitario) + ' / u → <strong>' + fmtMoney(after.unitario) + ' / u</strong></div>');
        }
        return lines.join('');
    }

    function readEditValues() {
        const precio = Number($('#cf-edit-precio').val());
        const unidad = Math.max(1, parseInt($('#cf-edit-unidad').val(), 10) || 1);
        return {
            precio: precio,
            unidad: unidad,
            unitario: (precio > 0 && unidad > 0) ? (precio / unidad) : 0,
            tipo: $('#cf-edit-tipo').val() || ''
        };
    }

    $('#cf-fuente').on('change', function() { page = 1; loadList(); });
    $('#cf-refresh-btn').on('click', function() { page = 1; loadList(); });
    $('#cf-prev-btn').on('click', function() { if (page > 1) { page--; loadList(); } });
    $('#cf-next-btn').on('click', function() { page++; loadList(); });
    $('#cf-search').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { page = 1; loadList(); }, SEARCH_DEBOUNCE_MS);
    });
    $('#cf-search').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            clearTimeout(searchTimer);
            page = 1;
            loadList();
        }
    });

    $(document).on('click', '.cf-edit-btn', function() {
        openEditModal($(this));
    });

    $('#cf-edit-precio, #cf-edit-unidad').on('input change', updateUnitarioHint);

    $('#cf-edit-cancel, #cf-edit-modal').on('click', function(e) {
        if (e.target !== this) return;
        if (editMode === 'confirm-save' || editMode === 'confirm-delete') {
            setEditMode('edit');
            setEditMsg('');
            return;
        }
        closeEditModal();
    });

    $('#cf-edit-save').on('click', function() {
        if (!editCtx) return;
        const after = readEditValues();
        if (!(after.precio > 0)) {
            setEditMsg('El precio total debe ser mayor a 0.', true);
            return;
        }
        if (!after.tipo) {
            setEditMsg('Debes seleccionar el tipo de match.', true);
            return;
        }
        const before = {
            precio: editCtx.precio,
            unidad: editCtx.unidad,
            unitario: editCtx.precio > 0 ? (editCtx.precio / editCtx.unidad) : 0,
            tipo: editCtx.tipo || ''
        };
        if (after.precio === before.precio && after.unidad === before.unidad && after.tipo === before.tipo) {
            setEditMsg('No hay cambios que guardar.', false);
            return;
        }
        $('#cf-edit-diff').html(buildDiffHtml(before, after));
        setEditMsg('');
        setEditMode('confirm-save');
    });

    $('#cf-edit-confirm-save').on('click', function() {
        if (!editCtx) return;
        const after = readEditValues();
        const $btn = $(this).prop('disabled', true);
        setEditMsg('Guardando…', false);
        $.post(ajaxurl, {
            action: 'riverso_competencia_update_fuente_precio',
            nonce,
            producto_competencia_id: editCtx.id,
            precio_total: after.precio,
            unidad: after.unidad,
            tipo_match: after.tipo
        }).done(function(res) {
            if (!res.success) {
                setEditMsg((res.data && res.data.message) || 'Error al guardar', true);
                $btn.prop('disabled', false);
                setEditMode('edit');
                return;
            }
            closeEditModal();
            loadList();
        }).fail(function() {
            setEditMsg('Error de red al guardar', true);
            $btn.prop('disabled', false);
            setEditMode('edit');
        });
    });

    $('#cf-edit-delete').on('click', function() {
        if (!editCtx) return;
        setEditMsg('');
        setEditMode('confirm-delete');
    });

    $('#cf-edit-confirm-delete').on('click', function() {
        if (!editCtx) return;
        const $btn = $(this).prop('disabled', true);
        setEditMsg('Eliminando…', false);
        $.post(ajaxurl, {
            action: 'riverso_competencia_eliminar_fuente',
            nonce,
            producto_competencia_id: editCtx.id
        }).done(function(res) {
            if (!res.success) {
                setEditMsg((res.data && res.data.message) || 'Error al eliminar', true);
                $btn.prop('disabled', false);
                setEditMode('edit');
                return;
            }
            closeEditModal();
            loadList();
        }).fail(function() {
            setEditMsg('Error de red al eliminar', true);
            $btn.prop('disabled', false);
            setEditMode('edit');
        });
    });

    loadList();
})(jQuery);
</script>

<?php elseif (!in_array($fuente, ['sande', 'dimafi'], true)) : ?>
    <div class="notice notice-info"><p><?php esc_html_e('Esta fuente aún no está disponible.', 'riverso-pos'); ?></p></div>
<?php else : ?>

    <nav class="nav-tab-wrapper" style="margin-top:8px;" id="cm-seccion-tabs">
        <a href="#" class="nav-tab nav-tab-active" data-seccion="revisar">Por revisar</a>
        <a href="#" class="nav-tab" data-seccion="vinculados">Vinculados</a>
        <a href="#" class="nav-tab" data-seccion="rechazados">Rechazados</a>
        <a href="#" class="nav-tab" data-seccion="historial">Historial</a>
    </nav>

    <div class="card" style="max-width:1200px;padding:16px 20px;margin-top:12px;">
        <div id="cm-stats" class="description" style="margin-bottom:12px;">Cargando estadísticas…</div>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;">
            <label id="cm-metodo-wrap">
                Método
                <select id="cm-metodo" class="regular-text">
                    <option value="">Todos</option>
                    <option value="codigo_exacto">Código exacto</option>
                    <option value="codigo_prefijo">Código prefijo</option>
                    <option value="sku_mapping">SKU mapping</option>
                    <option value="similitud">Similitud</option>
                    <option value="manual">Manual</option>
                </select>
            </label>
            <label id="cm-hist-filtro-wrap" style="display:none;">
                Alcance
                <select id="cm-hist-filtro" class="regular-text">
                    <option value="todos">Todos</option>
                    <option value="vinculados">Solo vinculados</option>
                </select>
            </label>
            <label style="flex:1;min-width:220px;">
                Buscar
                <input type="search" id="cm-search" class="regular-text" style="width:100%;" placeholder="Código o nombre…">
            </label>
            <button type="button" class="button" id="cm-refresh-btn">Actualizar</button>
            <button type="button" class="button button-primary" id="cm-suggest-btn">Generar sugerencias</button>
        </div>
    </div>

    <div class="card" style="max-width:1200px;padding:0;margin-top:16px;overflow:hidden;">
        <table class="widefat striped" id="cm-table">
            <thead>
                <tr id="cm-thead-match">
                    <th><?php echo esc_html($fuente_nombre); ?></th>
                    <th>Precio <?php echo esc_html($fuente_nombre); ?></th>
                    <th id="cm-th-local">Sugerencia local</th>
                    <th>Score / Método</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
                <tr id="cm-thead-hist" style="display:none;">
                    <th><?php echo esc_html($fuente_nombre); ?></th>
                    <th>Vigente (bruto/u)</th>
                    <th>Actualizado</th>
                    <th>Último snapshot</th>
                    <th>Anterior (01/16)</th>
                    <th>Variación</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody id="cm-tbody">
                <tr><td colspan="6">Cargando…</td></tr>
            </tbody>
        </table>
        <p style="padding:12px 16px;margin:0;">
            <button type="button" class="button" id="cm-prev-btn" disabled>Anterior</button>
            <span id="cm-page-label" style="margin:0 12px;">Página 1</span>
            <button type="button" class="button" id="cm-next-btn" disabled>Siguiente</button>
        </p>
    </div>
<?php endif; ?>
</div>

<?php if ($modo === 'matching' && in_array($fuente, ['sande', 'dimafi'], true)) : ?>

<div id="cm-manual-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;">
    <div style="background:#fff;max-width:560px;margin:8vh auto;padding:20px;border-radius:6px;">
        <h2 style="margin-top:0;">Buscar producto local</h2>
        <input type="search" id="cm-manual-search" class="regular-text" style="width:100%;" placeholder="SKU o nombre…">
        <ul id="cm-manual-results" style="max-height:280px;overflow:auto;margin:12px 0;padding-left:18px;"></ul>
        <p>
            <button type="button" class="button" id="cm-manual-close">Cerrar</button>
        </p>
    </div>
</div>

<div id="cm-action-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100001;">
    <div style="background:#fff;max-width:560px;margin:8vh auto;padding:20px;border-radius:6px;">
        <h2 style="margin-top:0;" id="cm-action-title">Confirmar match</h2>
        <div id="cm-action-summary" style="margin-bottom:12px;"></div>
        <div id="cm-action-blockers" style="display:none;margin-bottom:12px;padding:10px 12px;background:#fcf0f1;border-left:4px solid #d63638;"></div>
        <div id="cm-action-hint" class="description" style="display:none;margin-bottom:12px;"></div>
        <label id="cm-action-tipo-wrap" style="display:block;margin-bottom:12px;">
            Tipo de match <span style="color:#d63638;">*</span>
            <select id="cm-action-tipo" style="width:100%;margin-top:4px;">
                <option value="">— seleccionar —</option>
                                <option value="exacto">Exacto (mismo producto)</option>
                <option value="exacto_envase">Exacto diferente U de envase</option>
                <option value="similar">Similar (equivalente funcional)</option>
                <option value="otro">Otro</option>
            </select>
            <span id="cm-action-tipo-error" style="display:none;color:#d63638;font-size:12px;">Debes seleccionar el tipo de match.</span>
            <div id="cm-action-tipo-warnings" style="display:none;margin-top:8px;"></div>
        </label>
        <label id="cm-action-nota-wrap" style="display:block;margin-bottom:12px;">
            Nota (opcional)
            <textarea id="cm-action-nota" class="large-text" rows="2" style="width:100%;"></textarea>
        </label>
        <p style="margin:0;">
            <button type="button" class="button button-primary" id="cm-action-submit">Confirmar</button>
            <button type="button" class="button" id="cm-action-cancel">Cancelar</button>
        </p>
    </div>
</div>

<div id="cm-hist-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100002;">
    <div style="background:#fff;max-width:640px;margin:6vh auto;padding:20px;border-radius:6px;max-height:88vh;overflow:auto;">
        <h2 style="margin-top:0;" id="cm-hist-modal-title">Historial de precios</h2>
        <div id="cm-hist-modal-meta" class="description" style="margin-bottom:12px;"></div>
        <table class="widefat striped" id="cm-hist-series-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Bruto / u</th>
                    <th>Bruto total</th>
                    <th>Cant. mín</th>
                    <th>Δ %</th>
                </tr>
            </thead>
            <tbody id="cm-hist-series-body">
                <tr><td colspan="5">Cargando…</td></tr>
            </tbody>
        </table>
        <p style="margin-top:16px;">
            <button type="button" class="button" id="cm-hist-modal-close">Cerrar</button>
        </p>
    </div>
</div>

<script>
(function($) {
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    const fuente = <?php echo wp_json_encode($fuente); ?>;
    const fuenteNombre = <?php echo wp_json_encode($fuente_nombre); ?>;
    let page = 1;
    let seccion = 'revisar';
    let manualCompetenciaId = 0;
    let actionCtx = { type: '', id: 0, pb: 0 };
    let listXhr = null;
    let statsXhr = null;
    let manualXhr = null;
    let searchTimer = null;
    let lastSearchKey = null;
    const SEARCH_DEBOUNCE_MS = 800;
    const SEARCH_MIN_CHARS = 2;
    let cmUnitContext = null;
    function tipoMatchLabel(t) {
        const map = { exacto: 'Exacto', exacto_envase: 'Exacto diferente U de envase', similar: 'Similar', otro: 'Otro' };
        return map[t] || t || '—';
    }
    function badgeU(show) {
        return show ? ' <span style="display:inline-block;padding:0 6px;border-radius:8px;background:#2271b1;color:#fff;font-size:11px;font-weight:600;">U</span>' : '';
    }
    function renderTipoWarnings($box, ctx, tipo) {
        if (!$box || !$box.length) return;
        ctx = ctx || {};
        const parts = [];
        if (tipo === 'exacto_envase') {
            if (ctx.family_status === 'unknown' || ctx.family_status === 'missing') {
                parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;"><strong>Advertencia familia:</strong> ' + esc(ctx.family_warning || 'Revisa el estado de familia.') + '</div>');
            }
            if (ctx.badge_u || ctx.is_unitario) {
                parts.push('<div style="padding:8px 10px;background:#edf5fb;border-left:4px solid #2271b1;">Producto local unitario' + badgeU(true) + ' — se puede relacionar con cualquier unidad de envase (' + esc(String(ctx.cantidad_min || 1)) + ' u) con este tipo.</div>');
            }
        } else if (tipo && tipo !== 'exacto_envase' && ctx.units_differ) {
            parts.push('<div style="padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;"><strong>Unidades distintas:</strong> local ' + esc(ctx.local_unit_label || '1') + badgeU(!!ctx.badge_u) + ' vs competencia ' + esc(String(ctx.cantidad_min || 1)) + ' u. Si es el mismo producto con otro envase, usa “Exacto diferente U de envase”.</div>');
        }
        if (!parts.length) { $box.hide().empty(); return; }
        $box.html(parts.join('')).show();
    }
    function confirmUnitsIfNeeded(tipo, ctx) {
        ctx = ctx || {};
        if (!tipo || tipo === 'exacto_envase' || !ctx.units_differ) return true;
        return window.confirm('Las unidades son distintas (local ' + (ctx.local_unit_label || '1') + ' vs competencia ' + (ctx.cantidad_min || 1) + ' u). ¿Confirmas de todos modos?\n\nSi es el mismo producto con otro envase, cancela y elige “Exacto diferente U de envase”.');
    }

    function abortXhr(xhr) {
        if (xhr && xhr.readyState !== 4) {
            xhr.abort();
        }
    }

    function ajaxFailMessage(xhr, fallback) {
        if (xhr && xhr.statusText === 'abort') {
            return '';
        }
        if (xhr && xhr.status === 403) {
            return 'Sin permisos para esta acción.';
        }
        if (xhr && xhr.status === 0) {
            return 'No se pudo conectar con el servidor.';
        }
        return fallback || 'Error al cargar datos.';
    }

    function tableColspan() {
        return seccion === 'historial' ? 7 : 6;
    }

    function showTableLoading() {
        $('#cm-tbody').html('<tr><td colspan="' + tableColspan() + '">Cargando…</td></tr>');
    }

    function showTableError(message) {
        $('#cm-tbody').html('<tr><td colspan="' + tableColspan() + '">' + esc(message) + '</td></tr>');
    }

    function esc(s) {
        return $('<div/>').text(s || '').html();
    }

    function escAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;');
    }

    function verLink(url, label) {
        if (!url) return '';
        return '<a class="button" href="' + escAttr(url) + '" target="_blank" rel="noopener noreferrer">' + esc(label) + '</a>';
    }

    function fmtPrice(row) {
        const brutoU = row.precio_bruto_unitario;
        const brutoT = row.precio_bruto_total;
        const qty = Number(row.cantidad_min || 0);
        if ((brutoU === null || brutoU === undefined || brutoU === '') &&
            (brutoT === null || brutoT === undefined || brutoT === '')) {
            if (!row.precio && row.precio !== 0) return '—';
        }
        const parts = [];
        if (brutoU !== null && brutoU !== undefined && brutoU !== '') {
            const u = Number(brutoU);
            if (!isNaN(u)) {
                parts.push('<strong>' + u.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 3 }) + '</strong> / u');
            }
        }
        if (brutoT !== null && brutoT !== undefined && brutoT !== '') {
            const t = Number(brutoT);
            if (!isNaN(t)) {
                const pack = qty > 1 ? ' / ' + qty.toLocaleString('es-CL') + ' u' : '';
                parts.push(t.toLocaleString('es-CL', { maximumFractionDigits: 0 }) + pack);
            }
        }
        if (!parts.length && (row.precio || row.precio === 0)) {
            const n = Number(row.precio);
            if (!isNaN(n)) {
                parts.push(n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' <span class="description">(neto)</span>');
            }
        }
        let html = parts.join('<br>');
        if (row.precio_oculto === '1' || row.precio_oculto === 1) {
            html += ' <span class="description">(oculto web)</span>';
        }
        return html || '—';
    }

    function fmtMoney(val, digits) {
        if (val === null || val === undefined || val === '') return '—';
        const n = Number(val);
        if (isNaN(n)) return '—';
        const d = typeof digits === 'number' ? digits : 3;
        return n.toLocaleString('es-CL', { minimumFractionDigits: Math.min(2, d), maximumFractionDigits: d });
    }

    function fmtPct(val) {
        if (val === null || val === undefined || val === '') return '—';
        const n = Number(val);
        if (isNaN(n)) return '—';
        const sign = n > 0 ? '+' : '';
        const color = n > 0 ? '#b32d2e' : (n < 0 ? '#007017' : 'inherit');
        return '<span style="color:' + color + ';">' + sign + n.toLocaleString('es-CL', {
            minimumFractionDigits: 1, maximumFractionDigits: 2
        }) + '%</span>';
    }

    function syncSeccionUi() {
        const isHist = seccion === 'historial';
        $('#cm-seccion-tabs .nav-tab').removeClass('nav-tab-active');
        $('#cm-seccion-tabs .nav-tab[data-seccion="' + seccion + '"]').addClass('nav-tab-active');
        $('#cm-th-local').text(seccion === 'vinculados' ? 'Producto local' : 'Sugerencia local');
        $('#cm-metodo-wrap').toggle(seccion === 'revisar');
        $('#cm-suggest-btn').toggle(seccion === 'revisar');
        $('#cm-hist-filtro-wrap').toggle(isHist);
        $('#cm-thead-match').toggle(!isHist);
        $('#cm-thead-hist').toggle(isHist);
        $('#cm-stats').toggle(!isHist);
    }

    function loadStats() {
        if (seccion === 'historial') return;
        abortXhr(statsXhr);
        statsXhr = $.post(ajaxurl, { action: 'riverso_competencia_stats', nonce, fuente })
            .done(function(res) {
                if (!res.success) return;
                const parts = (res.data.stats || []).map(s => esc(s.estado) + ': ' + s.total);
                $('#cm-stats').html('Totales ' + fuenteNombre + ' — ' + (parts.join(' · ') || 'sin datos'));
            })
            .fail(function(xhr) {
                const msg = ajaxFailMessage(xhr, 'No se pudieron cargar las estadísticas.');
                if (msg) {
                    $('#cm-stats').text(msg);
                }
            });
    }

    function loadList() {
        if (seccion === 'historial') {
            loadHistorial();
            return;
        }
        showTableLoading();
        abortXhr(listXhr);
        listXhr = $.post(ajaxurl, {
            action: 'riverso_competencia_list',
            nonce,
            fuente,
            seccion,
            metodo: seccion === 'revisar' ? $('#cm-metodo').val() : '',
            search: $('#cm-search').val(),
            page,
            per_page: 25
        }).done(function(res) {
            if (!res.success) {
                showTableError((res.data && res.data.message) || 'Error al cargar');
                return;
            }
            const rows = res.data.rows || [];
            const total = res.data.total || 0;
            const perPage = res.data.per_page || 25;
            const pages = Math.max(1, Math.ceil(total / perPage));
            $('#cm-page-label').text('Página ' + page + ' / ' + pages + ' (' + total + ' filas)');
            $('#cm-prev-btn').prop('disabled', page <= 1);
            $('#cm-next-btn').prop('disabled', page >= pages);

            if (!rows.length) {
                $('#cm-tbody').html('<tr><td colspan="6">Sin resultados</td></tr>');
                return;
            }

            const html = rows.map(function(r) {
                const nombreSande = r.url_producto
                    ? '<a href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">' + esc(r.nombre) + '</a>'
                    : esc(r.nombre);
                let localNombre = '<span class="description">Sin sugerencia</span>';
                if (r.canonical_sku || r.nombre_canonico) {
                    const sku = r.canonical_sku
                        ? (r.url_local
                            ? '<strong><a href="' + escAttr(r.url_local) + '" target="_blank" rel="noopener noreferrer">' + esc(r.canonical_sku) + '</a></strong>'
                            : '<strong>' + esc(r.canonical_sku) + '</strong>')
                        : '';
                    localNombre = sku + (sku ? '<br>' : '') + esc(r.nombre_canonico || '');
                    if (seccion === 'vinculados' && r.revisado_at) {
                        localNombre += '<br><span class="description">Confirmado: ' + esc(r.revisado_at) + '</span>';
                    }
                }

                const actions = [];
                actions.push(verLink(r.url_producto, 'Ver'));
                if (r.url_local) {
                    actions.push(verLink(r.url_local, 'Ver local'));
                }
                if (seccion === 'revisar') {
                    if (r.match_estado !== 'confirmado' && r.producto_base_id) {
                        actions.push(
                            '<button type="button" class="button button-primary cm-confirm" data-id="' + r.id +
                            '" data-pb="' + r.producto_base_id +
                            '" data-codigo="' + escAttr((r.codigo_externo || '').trim()) +
                            '" data-nombre="' + escAttr(r.nombre || '') +
                            '" data-sku="' + escAttr(r.canonical_sku || '') +
                            '" data-local="' + escAttr(r.nombre_canonico || '') +
                            '">Confirmar</button>'
                        );
                    }
                    if (r.match_estado !== 'rechazado') {
                        actions.push(
                            '<button type="button" class="button cm-reject" data-id="' + r.id +
                            '" data-codigo="' + escAttr((r.codigo_externo || '').trim()) +
                            '" data-nombre="' + escAttr(r.nombre || '') +
                            '">Rechazar</button>'
                        );
                    }
                    actions.push('<button type="button" class="button cm-manual" data-id="' + r.id + '">Buscar manual</button>');
                }

                return '<tr>' +
                    '<td><code>' + esc((r.codigo_externo || '').trim()) + '</code><br>' + nombreSande +
                    '<br><span class="description">' + esc(r.nombre_categoria) + '</span></td>' +
                    '<td>' + fmtPrice(r) + '</td>' +
                    '<td>' + localNombre + '</td>' +
                    '<td>' + esc(r.score || '') + ' / ' + esc(r.metodo || '—') + '</td>' +
                    '<td>' + esc(r.match_estado || 'pendiente') + (r.tipo_match ? '<br><span class="description">' + esc(tipoMatchLabel(r.tipo_match)) + '</span>' : '') + '</td>' +
                    '<td style="white-space:nowrap;">' + actions.filter(Boolean).join(' ') + '</td>' +
                    '</tr>';
            }).join('');
            $('#cm-tbody').html(html);
        }).fail(function(xhr) {
            const msg = ajaxFailMessage(xhr, 'Error al cargar la lista.');
            if (msg) {
                showTableError(msg);
            }
        });
    }

    function loadHistorial() {
        showTableLoading();
        abortXhr(listXhr);
        listXhr = $.post(ajaxurl, {
            action: 'riverso_competencia_price_history',
            nonce,
            fuente,
            search: $('#cm-search').val(),
            solo_vinculados: $('#cm-hist-filtro').val() === 'vinculados' ? 1 : 0,
            page,
            per_page: 25
        }).done(function(res) {
            if (!res.success) {
                showTableError((res.data && res.data.message) || 'Error al cargar');
                return;
            }
            const rows = res.data.rows || [];
            const total = res.data.total || 0;
            const perPage = res.data.per_page || 25;
            const pages = Math.max(1, Math.ceil(total / perPage));
            $('#cm-page-label').text('Página ' + page + ' / ' + pages + ' (' + total + ' filas)');
            $('#cm-prev-btn').prop('disabled', page <= 1);
            $('#cm-next-btn').prop('disabled', page >= pages);

            if (!rows.length) {
                $('#cm-tbody').html('<tr><td colspan="7">Sin resultados</td></tr>');
                return;
            }

            const html = rows.map(function(r) {
                const nombreSande = r.url_producto
                    ? '<a href="' + escAttr(r.url_producto) + '" target="_blank" rel="noopener noreferrer">' + esc(r.nombre) + '</a>'
                    : esc(r.nombre);
                const vigente = fmtMoney(r.precio_bruto_unitario, 3);
                const actualizado = esc(r.actualizado_at || r.snapshot_fecha || '—');
                const last = r.hist_fecha
                    ? esc(r.hist_fecha) + '<br><strong>' + fmtMoney(r.hist_precio_bruto_unitario, 3) + '</strong>'
                    : '—';
                const prev = r.hist_prev_fecha
                    ? esc(r.hist_prev_fecha) + '<br>' + fmtMoney(r.hist_prev_precio_bruto_unitario, 3)
                    : '—';
                return '<tr>' +
                    '<td><code>' + esc((r.codigo_externo || '').trim()) + '</code><br>' + nombreSande + '</td>' +
                    '<td><strong>' + vigente + '</strong></td>' +
                    '<td>' + actualizado + '</td>' +
                    '<td>' + last + '</td>' +
                    '<td>' + prev + '</td>' +
                    '<td>' + fmtPct(r.variacion_pct) + '</td>' +
                    '<td><button type="button" class="button cm-hist-detail" data-id="' + r.id + '">Serie</button></td>' +
                    '</tr>';
            }).join('');
            $('#cm-tbody').html(html);
        }).fail(function(xhr) {
            const msg = ajaxFailMessage(xhr, 'Error al cargar el historial.');
            if (msg) {
                showTableError(msg);
            }
        });
    }

    function openHistSeries(id) {
        $('#cm-hist-modal-title').text('Historial de precios');
        $('#cm-hist-modal-meta').text('Cargando…');
        $('#cm-hist-series-body').html('<tr><td colspan="5">Cargando…</td></tr>');
        $('#cm-hist-modal').show();
        $.post(ajaxurl, {
            action: 'riverso_competencia_price_series',
            nonce,
            producto_competencia_id: id
        }, function(res) {
            if (!res.success) {
                $('#cm-hist-modal-meta').text((res.data && res.data.message) || 'Error');
                $('#cm-hist-series-body').html('<tr><td colspan="5">Sin datos</td></tr>');
                return;
            }
            const p = res.data.producto || {};
            const series = res.data.series || [];
            $('#cm-hist-modal-title').text(
                'Historial — ' + ((p.codigo_externo || '').trim() || ('#' + p.id))
            );
            $('#cm-hist-modal-meta').html(
                esc(p.nombre || '') +
                '<br>Vigente: <strong>' + fmtMoney(p.precio_bruto_unitario, 3) + '</strong> / u' +
                (p.actualizado_at ? ' · actualizado ' + esc(p.actualizado_at) : '')
            );
            if (!series.length) {
                $('#cm-hist-series-body').html('<tr><td colspan="5">Sin snapshots aún</td></tr>');
                return;
            }
            const html = series.map(function(pt) {
                return '<tr>' +
                    '<td>' + esc(pt.snapshot_fecha) + '</td>' +
                    '<td><strong>' + fmtMoney(pt.precio_bruto_unitario, 3) + '</strong></td>' +
                    '<td>' + fmtMoney(pt.precio_bruto_total, 0) + '</td>' +
                    '<td>' + esc(pt.cantidad_min || '—') + '</td>' +
                    '<td>' + fmtPct(pt.delta_pct) + '</td>' +
                    '</tr>';
            }).join('');
            $('#cm-hist-series-body').html(html);
        });
    }

    function closeActionModal() {
        $('#cm-action-modal').hide();
        actionCtx = { type: '', id: 0, pb: 0 };
    }

    function renderBlockers(blockers, message, unitHint) {
        const $box = $('#cm-action-blockers').empty().hide();
        const $hint = $('#cm-action-hint').empty().hide();
        if (message) {
            $box.append('<p style="margin:0 0 8px;"><strong>' + esc(message) + '</strong></p>');
        }
        if (blockers && blockers.length) {
            const ul = $('<ul style="margin:0;padding-left:18px;"></ul>');
            blockers.forEach(function(b) {
                const link = b.url
                    ? '<a href="' + escAttr(b.url) + '" target="_blank" rel="noopener noreferrer">' + esc(b.label || b.tipo) + '</a>'
                    : esc(b.label || b.tipo);
                ul.append('<li>' + link + ' <span class="description">(' + esc(b.estado || '') + ')</span></li>');
            });
            $box.append(ul);
            $box.show();
        } else if (message) {
            $box.show();
        }
        if (unitHint) {
            $hint.text(unitHint).show();
        }
    }

    $('#cm-seccion-tabs').on('click', '.nav-tab', function(e) {
        e.preventDefault();
        const next = $(this).data('seccion');
        if (!next || next === seccion) return;
        seccion = next;
        page = 1;
        syncSeccionUi();
        loadList();
    });

    $('#cm-refresh-btn').on('click', function() {
        lastSearchKey = null;
        page = 1;
        loadList();
        loadStats();
    });
    $('#cm-prev-btn').on('click', function() { if (page > 1) { page--; loadList(); } });
    $('#cm-next-btn').on('click', function() { page++; loadList(); });
    $('#cm-hist-filtro').on('change', function() {
        page = 1;
        lastSearchKey = null;
        loadList();
    });
    $('#cm-metodo').on('change', function() {
        if (seccion !== 'revisar') return;
        page = 1;
        lastSearchKey = null;
        loadList();
    });

    function applySearch(immediate) {
        clearTimeout(searchTimer);
        const run = function() {
            const term = ($('#cm-search').val() || '').trim();
            if (!immediate && term.length > 0 && term.length < SEARCH_MIN_CHARS) {
                return;
            }
            const key = seccion + '|' + ($('#cm-metodo').val() || '') + '|' + term + '|' + ($('#cm-hist-filtro').val() || '');
            if (!immediate && key === lastSearchKey) return;
            lastSearchKey = key;
            page = 1;
            loadList();
        };
        if (immediate) {
            run();
            return;
        }
        searchTimer = setTimeout(run, SEARCH_DEBOUNCE_MS);
    }
    $('#cm-search').on('input', function() { applySearch(false); });
    $('#cm-search').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            applySearch(true);
        }
    });

    $(document).on('click', '.cm-hist-detail', function() {
        openHistSeries($(this).data('id'));
    });
    $('#cm-hist-modal-close, #cm-hist-modal').on('click', function(e) {
        if (e.target === this) $('#cm-hist-modal').hide();
    });

    $('#cm-suggest-btn').on('click', function() {
        const $btn = $(this).prop('disabled', true).text('Generando…');
        $.post(ajaxurl, { action: 'riverso_competencia_suggest', nonce, limit: 500, fuente }, function(res) {
            $btn.prop('disabled', false).text('Generar sugerencias');
            if (!res.success) {
                alert(res.data && res.data.message ? res.data.message : 'Error');
                return;
            }
            alert('Procesados: ' + res.data.processed + ', sugeridos: ' + res.data.suggested + ', sin match: ' + res.data.skipped);
            page = 1;
            loadList();
            loadStats();
        });
    });

    $(document).on('click', '.cm-confirm', function() {
        const $btn = $(this);
        actionCtx = {
            type: 'confirm',
            id: $btn.data('id'),
            pb: $btn.data('pb')
        };
        $('#cm-action-title').text('Confirmar vínculo');
        $('#cm-action-summary').html(
            '<p><strong>' + esc(fuenteNombre) + ':</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p><strong>Local:</strong> <code>' + esc($btn.data('sku') || '') + '</code> — ' + esc($btn.data('local') || '') + '</p>' +
            '<p class="description">Cargando validación de familia…</p>'
        );
        $('#cm-action-nota').val('');
        $('#cm-action-nota-wrap').show();
        $('#cm-action-tipo').val('');
        $('#cm-action-tipo-error').hide();
        $('#cm-action-tipo-wrap').show();
        $('#cm-action-submit').prop('disabled', true).text('Confirmar').show();
        renderBlockers([], '', '');
        $('#cm-action-modal').show();

        $.post(ajaxurl, {
            action: 'riverso_competencia_confirm_preflight',
            nonce,
            producto_competencia_id: actionCtx.id,
            producto_base_id: actionCtx.pb
        }, function(res) {
            if (!res.success) {
                $('#cm-action-summary').html('<p class="description">No se pudo validar el match.</p>');
                return;
            }
            const d = res.data;
            const sande = d.sande || {};
            const local = d.local || {};
            cmUnitContext = d.unit_context || null;
            const localBadge = (local.badge_u || (cmUnitContext && cmUnitContext.badge_u)) ? badgeU(true) : '';
            $('#cm-action-summary').html(
                '<p><strong>' + esc(fuenteNombre) + ':</strong> <code>' + esc(sande.codigo || '') + '</code> — ' + esc(sande.nombre || '')
                + ' <span class="description">(' + esc(String(sande.cantidad_min || (cmUnitContext && cmUnitContext.cantidad_min) || 1)) + ' u)</span></p>' +
                '<p><strong>Local:</strong> <code>' + esc(local.canonical_sku || '') + '</code> — ' + esc(local.nombre_canonico || '') + localBadge
                + ' <span class="description">unidad ' + esc(local.unit_label || (cmUnitContext && cmUnitContext.local_unit_label) || '1') + '</span></p>' +
                (d.can_confirm ? '<p>¿Confirmas que es el mismo producto?</p>' : '')
            );
            renderBlockers(d.blockers || [], d.message || '', d.unit_hint || '');
            renderTipoWarnings($('#cm-action-tipo-warnings'), cmUnitContext, $('#cm-action-tipo').val() || '');
            if (d.can_confirm) {
                $('#cm-action-submit').prop('disabled', false).show();
                $('#cm-action-nota-wrap').show();
            } else {
                $('#cm-action-submit').hide();
                $('#cm-action-nota-wrap').hide();
            }
        });
    });

    $('#cm-action-tipo').on('change', function() {
        renderTipoWarnings($('#cm-action-tipo-warnings'), cmUnitContext, $(this).val() || '');
    });

    $(document).on('click', '.cm-reject', function() {
        const $btn = $(this);
        actionCtx = { type: 'reject', id: $btn.data('id'), pb: 0 };
        $('#cm-action-title').text('Rechazar sugerencia');
        $('#cm-action-summary').html(
            '<p><strong>' + esc(fuenteNombre) + ':</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p>Al rechazar, el producto sale de la bandeja y no se volverá a sugerir automáticamente.</p>'
        );
        renderBlockers([], '', '');
        $('#cm-action-nota').val('');
        $('#cm-action-nota-wrap').show();
        $('#cm-action-tipo-wrap').hide();
        $('#cm-action-submit').prop('disabled', false).text('Rechazar').show();
        $('#cm-action-modal').show();
    });

    $('#cm-action-cancel, #cm-action-modal').on('click', function(e) {
        if (e.target === this) closeActionModal();
    });

    $('#cm-action-submit').on('click', function() {
        if (!actionCtx.type || !actionCtx.id) return;
        const $btn = $(this).prop('disabled', true);
        const nota = $('#cm-action-nota').val();
        if (actionCtx.type === 'confirm' || actionCtx.type === 'confirm_manual') {
            const tipo_match = $('#cm-action-tipo').val();
            if (!tipo_match) {
                $('#cm-action-tipo-error').show();
                $btn.prop('disabled', false);
                return;
            }
            $('#cm-action-tipo-error').hide();
            if (!confirmUnitsIfNeeded(tipo_match, cmUnitContext)) {
                $btn.prop('disabled', false);
                return;
            }
            $.post(ajaxurl, {
                action: 'riverso_competencia_confirm_match',
                nonce,
                producto_competencia_id: actionCtx.id,
                producto_base_id: actionCtx.pb,
                nota,
                tipo_match
            }, function(res) {
                $btn.prop('disabled', false);
                if (!res.success) {
                    renderBlockers(res.data && res.data.blockers ? res.data.blockers : [], (res.data && res.data.message) || 'Error al confirmar', (res.data && res.data.unit_hint) || '');
                    $btn.hide();
                    return;
                }
                closeActionModal();
                loadList();
                loadStats();
            });
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_competencia_reject_match',
            nonce,
            producto_competencia_id: actionCtx.id,
            nota
        }, function(res) {
            $btn.prop('disabled', false);
            if (!res.success) {
                alert((res.data && res.data.message) || 'Error al rechazar');
                return;
            }
            closeActionModal();
            loadList();
            loadStats();
        });
    });

    $(document).on('click', '.cm-manual', function() {
        manualCompetenciaId = $(this).data('id');
        $('#cm-manual-search').val('');
        $('#cm-manual-results').empty();
        $('#cm-manual-modal').show();
    });

    $('#cm-manual-close, #cm-manual-modal').on('click', function(e) {
        if (e.target === this) $('#cm-manual-modal').hide();
    });

    let manualTimer = null;
    function runManualSearch(q) {
        q = (q || '').trim();
        if (!q || q.length < SEARCH_MIN_CHARS) {
            $('#cm-manual-results').empty();
            return;
        }
        abortXhr(manualXhr);
        manualXhr = $.post(ajaxurl, { action: 'riverso_competencia_search_local', nonce, search: q })
            .done(function(res) {
                if (!res.success) return;
                const items = (res.data.products || []).map(function(p) {
                    return '<li style="margin-bottom:8px;"><button type="button" class="button cm-pick-local" data-pb="' + p.id + '">' +
                        esc(p.canonical_sku) + ' — ' + esc(p.nombre_canonico) + '</button></li>';
                }).join('');
                $('#cm-manual-results').html(items || '<li>Sin resultados</li>');
            })
            .fail(function() {
                $('#cm-manual-results').html('<li>Error al buscar</li>');
            });
    }
    $('#cm-manual-search').on('input', function() {
        clearTimeout(manualTimer);
        const q = $(this).val();
        manualTimer = setTimeout(function() { runManualSearch(q); }, SEARCH_DEBOUNCE_MS);
    });
    $('#cm-manual-search').on('keydown', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            clearTimeout(manualTimer);
            runManualSearch($(this).val());
        }
    });

    $(document).on('click', '.cm-pick-local', function() {
        const pb = $(this).data('pb');
        const localLabel = $(this).text();
        $('#cm-manual-modal').hide();
        actionCtx = { type: 'confirm_manual', id: manualCompetenciaId, pb: pb };
        $('#cm-action-title').text('Confirmar vínculo manual');
        $('#cm-action-summary').html(
            '<p><strong>Producto local seleccionado:</strong> ' + esc(localLabel) + '</p>' +
            '<p>Elige el tipo de relación con el producto de ' + esc(fuenteNombre) + '.</p>'
        );
        $('#cm-action-nota').val('');
        $('#cm-action-nota-wrap').show();
        $('#cm-action-tipo').val('');
        $('#cm-action-tipo-error').hide();
        $('#cm-action-tipo-wrap').show();
        renderBlockers([], '', '');
        $('#cm-action-submit').prop('disabled', false).text('Confirmar').show();
        $('#cm-action-modal').show();
    });

    syncSeccionUi();
    loadList();
    loadStats();
})(jQuery);
</script>
<?php endif; ?>
