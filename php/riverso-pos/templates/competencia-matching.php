<?php
/**
 * Competencia — pestañas por fuente + matching Sande supervisado.
 *
 * @package Riverso_POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$fuente = isset($_GET['fuente']) ? sanitize_key(wp_unslash($_GET['fuente'])) : 'sande';
if ($fuente === '') {
    $fuente = 'sande';
}
$base_url = admin_url('admin.php?page=riverso-pos-competencia');
?>
<div class="wrap riverso-competencia-wrap">
    <h1><?php esc_html_e('Competencia', 'riverso-pos'); ?></h1>
    <p class="description">
        Matching supervisado entre catálogos de competencia y productos Riverso.
        Ningún vínculo se confirma sin acción humana.
    </p>

    <h2 class="nav-tab-wrapper" style="margin-top:16px;">
        <a href="<?php echo esc_url(add_query_arg('fuente', 'sande', $base_url)); ?>"
           class="nav-tab <?php echo $fuente === 'sande' ? 'nav-tab-active' : ''; ?>">Sande</a>
        <a href="#" class="nav-tab" style="opacity:.55;cursor:not-allowed;"
           title="<?php esc_attr_e('Próximamente', 'riverso-pos'); ?>"
           onclick="return false;">Otras fuentes <span class="description">[WIP]</span></a>
    </h2>

    <?php if ($fuente !== 'sande') : ?>
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
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
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
                    <th>Sande</th>
                    <th>Precio Sande</th>
                    <th id="cm-th-local">Sugerencia local</th>
                    <th>Score / Método</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
                <tr id="cm-thead-hist" style="display:none;">
                    <th>Sande</th>
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

<?php if ($fuente === 'sande') : ?>
<script>
(function($) {
    const nonce = <?php echo wp_json_encode($nonce); ?>;
    const fuente = 'sande';
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
                $('#cm-stats').html('Totales Sande — ' + (parts.join(' · ') || 'sin datos'));
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
                    '<td>' + esc(r.match_estado || 'pendiente') + '</td>' +
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
        $.post(ajaxurl, { action: 'riverso_competencia_suggest', nonce, limit: 500 }, function(res) {
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
            '<p><strong>Sande:</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p><strong>Local:</strong> <code>' + esc($btn.data('sku') || '') + '</code> — ' + esc($btn.data('local') || '') + '</p>' +
            '<p class="description">Cargando validación de familia…</p>'
        );
        $('#cm-action-nota').val('');
        $('#cm-action-nota-wrap').show();
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
            $('#cm-action-summary').html(
                '<p><strong>Sande:</strong> <code>' + esc(sande.codigo || '') + '</code> — ' + esc(sande.nombre || '') + '</p>' +
                '<p><strong>Local:</strong> <code>' + esc(local.canonical_sku || '') + '</code> — ' + esc(local.nombre_canonico || '') + '</p>' +
                (d.can_confirm
                    ? '<p>¿Confirmas que es el mismo producto?</p>'
                    : '')
            );
            renderBlockers(d.blockers || [], d.message || '', d.unit_hint || '');
            if (d.can_confirm) {
                $('#cm-action-submit').prop('disabled', false).show();
                $('#cm-action-nota-wrap').show();
            } else {
                $('#cm-action-submit').hide();
                $('#cm-action-nota-wrap').hide();
            }
        });
    });

    $(document).on('click', '.cm-reject', function() {
        const $btn = $(this);
        actionCtx = { type: 'reject', id: $btn.data('id'), pb: 0 };
        $('#cm-action-title').text('Rechazar sugerencia');
        $('#cm-action-summary').html(
            '<p><strong>Sande:</strong> <code>' + esc($btn.data('codigo') || '') + '</code> — ' + esc($btn.data('nombre') || '') + '</p>' +
            '<p>Al rechazar, el producto sale de la bandeja y no se volverá a sugerir automáticamente.</p>'
        );
        renderBlockers([], '', '');
        $('#cm-action-nota').val('');
        $('#cm-action-nota-wrap').show();
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
        if (actionCtx.type === 'confirm') {
            $.post(ajaxurl, {
                action: 'riverso_competencia_confirm_match',
                nonce,
                producto_competencia_id: actionCtx.id,
                producto_base_id: actionCtx.pb,
                nota
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
        $.post(ajaxurl, {
            action: 'riverso_competencia_manual_match',
            nonce,
            producto_competencia_id: manualCompetenciaId,
            producto_base_id: pb
        }, function(res) {
            if (!res.success) { alert('Error'); return; }
            $('#cm-manual-modal').hide();
            loadList();
            loadStats();
        });
    });

    syncSeccionUi();
    loadList();
    loadStats();
})(jQuery);
</script>
<?php endif; ?>
