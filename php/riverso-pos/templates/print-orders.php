<?php
/**
 * Template: Órdenes de Impresión
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('riverso_view_print_orders') && !current_user_can('riverso_print_labels') && !current_user_can('manage_options')) {
    wp_die(__('No tienes permisos para ver órdenes de impresión.', 'riverso-pos'));
}

$can_create  = current_user_can('riverso_create_print_orders') || current_user_can('manage_options');
$can_approve = current_user_can('riverso_approve_print_orders') || current_user_can('manage_options');
$can_print   = current_user_can('riverso_print_orders') || current_user_can('riverso_print_labels') || current_user_can('manage_options');
$can_cancel  = current_user_can('riverso_cancel_print_orders') || current_user_can('manage_options');
$can_edit_price = current_user_can('riverso_edit_print_order_price') || current_user_can('manage_options');

$estados = Riverso_Print_Order_Module::ESTADOS;
$tipos   = Riverso_Print_Order_Module::TIPOS;
$modos   = Riverso_Print_Order_Module::MODOS;
$colores = Riverso_Print_Order_Module::COLORES;
?>

<div class="wrap riverso-pos-wrap riverso-print-orders">
    <h1>
        <span class="dashicons dashicons-printer"></span>
        Impresiones
        <?php if ($can_create): ?>
        <button type="button" class="page-title-action" id="btn-new-order">Nueva orden</button>
        <?php endif; ?>
    </h1>

    <div class="nav-tab-wrapper">
        <a href="#listado" class="nav-tab nav-tab-active" data-tab="listado">Listado</a>
        <a href="#editor" class="nav-tab" data-tab="editor"><?php echo $can_create ? 'Crear / Editar' : 'Detalle'; ?></a>
        <a href="#stats" class="nav-tab" data-tab="stats">Estadísticas</a>
    </div>

    <div class="po-tab-panel" id="tab-listado">
        <div class="po-filters">
            <input type="search" id="po-search" placeholder="Buscar número, SKU, solicitante...">
            <select id="po-filter-estado">
                <option value="">Todos los estados</option>
                <?php foreach ($estados as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="po-filter-tipo">
                <option value="">Todos los tipos</option>
                <?php foreach ($tipos as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" id="po-date-from" title="Desde">
            <input type="date" id="po-date-to" title="Hasta">
            <button type="button" class="button" id="po-btn-filter">Filtrar</button>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:140px;">Número</th>
                    <th style="width:110px;">Estado</th>
                    <th>Tipo</th>
                    <th style="width:80px;">Ítems</th>
                    <th style="width:80px;">Copias</th>
                    <th>Solicitante</th>
                    <th style="width:140px;">Fecha</th>
                    <th style="width:280px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="po-list-body">
                <tr><td colspan="8" style="text-align:center;padding:40px;">Cargando...</td></tr>
            </tbody>
        </table>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span id="po-pagination"></span>
            </div>
        </div>
    </div>

    <div class="po-tab-panel" id="tab-editor" style="display:none;">
        <div class="po-editor-card">
            <div class="po-editor-top">
                <h2 style="margin:0;font-size:16px;">Orden</h2>
                <button type="button" class="button po-close-order">Cerrar</button>
            </div>
            <input type="hidden" id="po-order-id" value="">
            <div class="po-editor-grid">
                <div>
                    <label>Tipo de etiqueta</label>
                    <select id="po-tipo" <?php echo $can_create ? '' : 'disabled'; ?>>
                        <?php foreach ($tipos as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Prioridad</label>
                    <label class="po-check">
                        <input type="checkbox" id="po-prioridad" <?php echo $can_create ? '' : 'disabled'; ?>> Urgente
                    </label>
                </div>
                <div>
                    <label>Estado</label>
                    <div id="po-editor-estado"><span class="po-badge po-badge-borrador">Borrador</span></div>
                </div>
                <div>
                    <label>Número</label>
                    <div id="po-editor-numero" class="po-muted">Se asigna al guardar</div>
                </div>
            </div>
            <div>
                <label>Notas</label>
                <textarea id="po-notas" rows="2" placeholder="Observaciones de la orden" <?php echo $can_create ? '' : 'readonly'; ?>></textarea>
            </div>

            <?php if ($can_create): ?>
            <div class="po-search-box">
                <label>Agregar producto</label>
                <div class="po-search-row">
                    <input type="search" id="po-product-q" placeholder="SKU, código de barra o nombre...">
                    <button type="button" class="button" id="po-product-search">Buscar</button>
                    <button type="button" class="button" id="po-product-clear">Borrar búsqueda</button>
                </div>
                <div id="po-product-results"></div>
            </div>
            <?php endif; ?>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Nombre en etiqueta</th>
                        <th style="width:90px;">Cant. EAN</th>
                        <th style="width:80px;">Copias</th>
                        <th style="width:140px;">Modo</th>
                        <th style="width:80px;">Color</th>
                        <th style="width:120px;">EAN13</th>
                        <th style="width:220px;">Precio</th>
                        <?php if ($can_create): ?><th style="width:70px;"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="po-items-body">
                    <tr class="po-empty"><td colspan="9">Sin productos. Busca y agrega ítems.</td></tr>
                </tbody>
            </table>
            <p class="po-muted" style="margin-top:8px;">Los cambios de nombre, copias, modo, color y EAN aplican solo a esta impresión, no al producto. El SKU no se modifica. El precio se muestra con 2 decimales; usa <strong>Redondear</strong> o <strong>Desredondear</strong> en esta impresión. Cambiar el precio a otro valor requiere permiso.</p>

            <div class="po-editor-actions">
                <button type="button" class="button po-close-order">Cerrar</button>
                <?php if ($can_create): ?>
                <button type="button" class="button button-primary po-workflow-btn" id="po-save">Guardar borrador</button>
                <button type="button" class="button po-workflow-btn" id="po-submit">Enviar</button>
                <button type="button" class="button" id="po-use-as-draft" style="display:none;">Usar como borrador</button>
                <?php endif; ?>
                <?php if ($can_approve): ?>
                <button type="button" class="button po-workflow-btn" id="po-approve">Aprobar</button>
                <button type="button" class="button po-workflow-btn" id="po-return">Devolver a borrador</button>
                <?php endif; ?>
                <?php if ($can_print): ?>
                <button type="button" class="button button-primary po-workflow-btn" id="po-print">Imprimir</button>
                <button type="button" class="button button-primary" id="po-reprint" style="display:none;">Volver a imprimir</button>
                <?php endif; ?>
                <?php if ($can_create || $can_cancel): ?>
                <button type="button" class="button po-workflow-btn" id="po-cancel">Cancelar orden</button>
                <?php endif; ?>
                <span id="po-editor-msg" class="po-muted"></span>
            </div>
        </div>
    </div>

    <div class="po-tab-panel" id="tab-stats" style="display:none;">
        <div class="riverso-stats-grid" id="po-stats-cards"></div>
        <h2>Impresiones recientes</h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Número</th>
                    <th>Ítems</th>
                    <th>Copias</th>
                    <th>Solicitante</th>
                    <th>Impresora</th>
                    <th>Impreso</th>
                </tr>
            </thead>
            <tbody id="po-stats-recent">
                <tr><td colspan="6">Cargando...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="po-cancel-modal" class="riverso-modal" style="display:none;">
    <div class="riverso-modal-content" style="max-width:440px;">
        <div class="riverso-modal-header">
            <h2>Cancelar orden</h2>
        </div>
        <div class="riverso-modal-body">
            <p>Puedes indicar un motivo (opcional). <strong>Volver</strong> cierra este cuadro sin cancelar la orden.</p>
            <label for="po-cancel-motivo">Motivo</label>
            <textarea id="po-cancel-motivo" rows="3" style="width:100%;margin-top:6px;" placeholder="Opcional"></textarea>
        </div>
        <div class="riverso-modal-footer">
            <button type="button" class="button" id="po-cancel-back">Volver</button>
            <button type="button" class="button button-primary" id="po-cancel-confirm">Cancelar orden</button>
        </div>
    </div>
</div>

<style>
.riverso-print-orders .po-filters { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; align-items:center; }
.riverso-print-orders .po-filters input[type="search"] { min-width:240px; }
.po-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
.po-badge-borrador { background:#e5e7eb; color:#374151; }
.po-badge-pendiente { background:#fef3c7; color:#92400e; }
.po-badge-aprobada { background:#dbeafe; color:#1e40af; }
.po-badge-impresa { background:#d1fae5; color:#065f46; }
.po-badge-cancelada { background:#fee2e2; color:#991b1b; }
.po-urgent { color:#b91c1c; font-weight:700; }
.po-editor-card { background:#fff; border:1px solid #ccd0d4; padding:20px; margin-top:16px; border-radius:4px; }
.po-editor-top { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px; }
.po-editor-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:12px; }
.po-editor-card label { display:block; font-weight:600; margin-bottom:4px; }
.po-editor-card select, .po-editor-card textarea, .po-editor-card input[type="search"], .po-editor-card input[type="text"] { width:100%; }
.po-check { font-weight:400; }
.po-search-box { margin:16px 0; }
.po-search-row { display:flex; gap:8px; }
.po-search-row input { flex:1; }
.po-product-hit { padding:8px 10px; border:1px solid #e1e1e1; border-radius:4px; margin-top:6px; display:flex; justify-content:space-between; align-items:center; background:#f9fafb; }
.po-editor-actions { margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.po-muted { color:#646970; }
.po-empty td { text-align:center; color:#646970; padding:20px !important; }
.po-actions .button { margin:0 2px 4px 0; }
.po-precio-cell { display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
.po-precio-view { font-variant-numeric: tabular-nums; }
</style>

<script>
jQuery(function($) {
    const canCreate = <?php echo $can_create ? 'true' : 'false'; ?>;
    const canApprove = <?php echo $can_approve ? 'true' : 'false'; ?>;
    const canPrint = <?php echo $can_print ? 'true' : 'false'; ?>;
    const canCancel = <?php echo $can_cancel ? 'true' : 'false'; ?>;
    const canEditPrice = <?php echo $can_edit_price ? 'true' : 'false'; ?>;
    const modos = <?php echo wp_json_encode(array_values($modos)); ?>;
    const colores = <?php echo wp_json_encode(array_values($colores)); ?>;
    const tipos = <?php echo wp_json_encode($tipos); ?>;

    let page = 1;
    let currentOrder = null;
    let editorItems = [];
    let cancelCallback = null;

    function closeCancelModal() {
        cancelCallback = null;
        $('#po-cancel-modal').hide();
    }

    function askCancelMotivo(onConfirm) {
        cancelCallback = onConfirm;
        $('#po-cancel-motivo').val('');
        $('#po-cancel-modal').show();
        setTimeout(function() { $('#po-cancel-motivo').trigger('focus'); }, 50);
    }

    $('#po-cancel-back').on('click', closeCancelModal);
    $('#po-cancel-modal').on('click', function(e) {
        if (e.target === this) closeCancelModal();
    });
    $('#po-cancel-confirm').on('click', function() {
        const cb = cancelCallback;
        const motivo = $('#po-cancel-motivo').val() || '';
        closeCancelModal();
        if (typeof cb === 'function') cb(motivo);
    });

    function post(action, data) {
        return $.post(riverso_pos.ajax_url, Object.assign({
            action: action,
            nonce: riverso_pos.nonce
        }, data || {}));
    }

    function esc(s) {
        return $('<div>').text(s == null ? '' : String(s)).html()
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function badge(estado, label) {
        return '<span class="po-badge po-badge-' + esc(estado) + '">' + esc(label || estado) + '</span>';
    }

    function switchTab(tab) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.nav-tab[data-tab="' + tab + '"]').addClass('nav-tab-active');
        $('.po-tab-panel').hide();
        $('#tab-' + tab).show();
        if (tab === 'stats') loadStats();
        if (tab === 'listado') loadList();
    }

    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        switchTab($(this).data('tab'));
    });

    function loadList() {
        post('riverso_print_orders_list', {
            page: page,
            per_page: 20,
            search: $('#po-search').val(),
            estado: $('#po-filter-estado').val(),
            tipo: $('#po-filter-tipo').val(),
            date_from: $('#po-date-from').val(),
            date_to: $('#po-date-to').val()
        }).done(function(res) {
            if (!res.success) {
                $('#po-list-body').html('<tr><td colspan="8">' + esc(res.data && res.data.message || 'Error') + '</td></tr>');
                return;
            }
            const items = res.data.items || [];
            if (!items.length) {
                $('#po-list-body').html('<tr><td colspan="8" style="text-align:center;padding:30px;">No hay órdenes.</td></tr>');
            } else {
                $('#po-list-body').html(items.map(renderRow).join(''));
            }
            const pages = res.data.pages || 1;
            let nav = 'Página ' + page + ' de ' + pages + ' (' + res.data.total + ') ';
            if (page > 1) nav += '<a href="#" class="po-page" data-page="' + (page - 1) + '">«</a> ';
            if (page < pages) nav += '<a href="#" class="po-page" data-page="' + (page + 1) + '">»</a>';
            $('#po-pagination').html(nav);
        }).fail(function() {
            $('#po-list-body').html('<tr><td colspan="8">Error de red.</td></tr>');
        });
    }

    function renderRow(o) {
        const urgent = Number(o.prioridad) ? ' <span class="po-urgent">URGENT</span>' : '';
        const actions = [];
        actions.push('<button type="button" class="button button-small po-open" data-id="' + o.id + '">Abrir</button>');
        if (canApprove && o.estado === 'pendiente') {
            actions.push('<button type="button" class="button button-small po-do-approve" data-id="' + o.id + '">Aprobar</button>');
        }
        if (canPrint && (o.estado === 'aprobada' || o.estado === 'borrador' || o.estado === 'pendiente')) {
            actions.push('<button type="button" class="button button-small po-do-print" data-id="' + o.id + '">Imprimir</button>');
        }
        if (canCancel && o.estado !== 'impresa' && o.estado !== 'cancelada') {
            actions.push('<button type="button" class="button button-small po-do-cancel" data-id="' + o.id + '">Cancelar</button>');
        }
        if (canCreate) {
            actions.push('<button type="button" class="button button-small po-do-dup" data-id="' + o.id + '">Duplicar</button>');
        }
        return '<tr>' +
            '<td><code>' + esc(o.numero_orden) + '</code>' + urgent + '</td>' +
            '<td>' + badge(o.estado, o.estado_label) + '</td>' +
            '<td>' + esc(o.tipo_label || o.tipo) + '</td>' +
            '<td>' + esc(o.total_items) + '</td>' +
            '<td>' + esc(o.total_copias) + '</td>' +
            '<td>' + esc(o.solicitado_por_nombre) + '</td>' +
            '<td>' + esc(o.created_at || '') + '</td>' +
            '<td class="po-actions">' + actions.join(' ') + '</td>' +
            '</tr>';
    }

    $('#po-btn-filter').on('click', function() { page = 1; loadList(); });
    $('#po-search').on('keydown', function(e) { if (e.key === 'Enter') { page = 1; loadList(); } });
    $(document).on('click', '.po-page', function(e) {
        e.preventDefault();
        page = parseInt($(this).data('page'), 10) || 1;
        loadList();
    });

    function resetEditor() {
        currentOrder = null;
        editorItems = [];
        $('#po-order-id').val('');
        $('#po-tipo').val('etiqueta_producto');
        $('#po-prioridad').prop('checked', false);
        $('#po-notas').val('');
        $('#po-editor-numero').text('Se asigna al guardar');
        $('#po-editor-estado').html(badge('borrador', 'Borrador'));
        $('#po-product-results').empty();
        $('#po-product-q').val('');
        renderItems();
        $('#po-editor-msg').text('');
        syncEditorChrome();
    }

    function fillEditor(order) {
        currentOrder = order;
        editorItems = (order.items || []).map(function(it) { return Object.assign({}, it); });
        $('#po-order-id').val(order.id);
        $('#po-tipo').val(order.tipo);
        $('#po-prioridad').prop('checked', Number(order.prioridad) === 1);
        $('#po-notas').val(order.notas || '');
        $('#po-editor-numero').text(order.numero_orden);
        $('#po-editor-estado').html(badge(order.estado, order.estado_label));
        renderItems();
        $('#po-editor-msg').text('');
        syncEditorChrome();
    }

    function isLockedOrder() {
        const estado = currentOrder && currentOrder.estado;
        return estado === 'impresa' || estado === 'cancelada';
    }

    function syncEditorChrome() {
        const estado = currentOrder && currentOrder.estado;
        const locked = estado === 'impresa' || estado === 'cancelada';
        const printed = estado === 'impresa';
        $('.po-workflow-btn').toggle(!locked);
        $('#po-use-as-draft').toggle(!!(locked && canCreate));
        $('#po-reprint').toggle(!!(printed && canPrint));
        $('#po-tipo, #po-prioridad, #po-notas').prop('disabled', locked);
        $('.po-search-box').toggle(!locked && canCreate);
    }

    function modoOptions(selected) {
        return modos.map(function(m) {
            return '<option value="' + m + '"' + (m === selected ? ' selected' : '') + '>' + m + '</option>';
        }).join('');
    }

    function colorOptions(selected) {
        return colores.map(function(c) {
            return '<option value="' + c + '"' + (c === selected ? ' selected' : '') + '>' + c + '</option>';
        }).join('');
    }

    function toPrice2(v) {
        if (v === null || v === undefined || v === '') return null;
        const n = Number(v);
        if (!isFinite(n) || n < 0) return null;
        return Math.round(n * 100) / 100;
    }

    function formatPrice2(v) {
        const n = toPrice2(v);
        if (n === null) return '—';
        return n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function priceInputValue(v) {
        const n = toPrice2(v);
        return n === null ? '' : n.toFixed(2);
    }

    function priceHasFraction(v) {
        const n = toPrice2(v);
        return n !== null && Math.abs(n - Math.round(n)) > 0.0001;
    }

    function canUnroundPrice(it) {
        const orig = toPrice2(it && it.precio_original);
        const cur = toPrice2(it && it.precio);
        return orig !== null && cur !== null && Math.abs(orig - cur) > 0.0001;
    }

    function renderItems() {
        if (!editorItems.length) {
            $('#po-items-body').html('<tr class="po-empty"><td colspan="9">Sin productos. Busca y agrega ítems.</td></tr>');
            return;
        }
        $('#po-items-body').html(editorItems.map(function(it, idx) {
            const precioVal = priceInputValue(it.precio);
            const precioLabel = formatPrice2(it.precio);
            if (isLockedOrder()) {
                return '<tr data-idx="' + idx + '">' +
                    '<td><code>' + esc(it.sku) + '</code></td>' +
                    '<td>' + esc(it.nombre || '') + '</td>' +
                    '<td>' + esc(it.cantidad_ean || 100) + '</td>' +
                    '<td>' + esc(it.copias || 1) + '</td>' +
                    '<td>' + esc(it.modo || 'BolsaCOD') + '</td>' +
                    '<td>' + esc(it.color || 'BN') + '</td>' +
                    '<td>' + esc(it.ean13 || '—') + '</td>' +
                    '<td>' + esc(precioLabel) + '</td>' +
                    (canCreate ? '<td></td>' : '') +
                    '</tr>';
            }
            const precioOpen = !!it.precioUnlocked;
            let precioHtml = '<span class="po-precio-view">' + esc(precioLabel) + '</span>';
            if (canEditPrice) {
                precioHtml =
                    '<span class="po-precio-view"' + (precioOpen ? ' style="display:none;"' : '') + '>' + esc(precioLabel) + '</span>' +
                    '<input type="number" min="0" step="0.01" class="po-it-precio" value="' + esc(precioVal) + '" style="width:100px;' + (precioOpen ? '' : 'display:none;') + '">';
            }
            if (canCreate && priceHasFraction(it.precio)) {
                precioHtml += ' <button type="button" class="button-link po-round-precio">Redondear</button>';
            }
            if (canCreate && canUnroundPrice(it)) {
                precioHtml += ' <button type="button" class="button-link po-unround-precio">Desredondear</button>';
            }
            if (canEditPrice && !precioOpen) {
                precioHtml += ' <button type="button" class="button-link po-unlock-precio">Cambiar precio</button>';
            }
            return '<tr data-idx="' + idx + '">' +
                '<td><code title="El SKU no se puede cambiar">' + esc(it.sku) + '</code></td>' +
                '<td><input type="text" class="po-it-nombre" value="' + esc(it.nombre || '') + '" style="width:100%;min-width:160px;"></td>' +
                '<td><input type="number" min="1" max="99999" class="po-it-ean" value="' + esc(it.cantidad_ean || 100) + '" style="width:80px;"></td>' +
                '<td><input type="number" min="1" max="9999" class="po-it-copias" value="' + esc(it.copias || 1) + '" style="width:70px;"></td>' +
                '<td><select class="po-it-modo">' + modoOptions(it.modo || 'BolsaCOD') + '</select></td>' +
                '<td><select class="po-it-color">' + colorOptions(it.color || 'BN') + '</select></td>' +
                '<td><input type="text" class="po-it-ean13" maxlength="13" value="' + esc(it.ean13 || '') + '" style="width:110px;" placeholder="Opcional"></td>' +
                '<td><div class="po-precio-cell">' + precioHtml + '</div></td>' +
                (canCreate ? '<td><button type="button" class="button-link-delete po-it-del">Quitar</button></td>' : '') +
                '</tr>';
        }).join(''));
    }

    function collectItems() {
        if (isLockedOrder()) {
            return editorItems.map(function(it, i) {
                return {
                    id: it.id || 0,
                    sku: it.sku,
                    nombre: it.nombre,
                    precio: it.precio,
                    precio_original: it.precio_original == null || it.precio_original === '' ? null : toPrice2(it.precio_original),
                    cantidad_ean: it.cantidad_ean,
                    copias: it.copias,
                    modo: it.modo,
                    color: it.color,
                    ean13: it.ean13 || '',
                    orden_posicion: i
                };
            });
        }
        $('#po-items-body tr').each(function() {
            const idx = parseInt($(this).data('idx'), 10);
            if (isNaN(idx) || !editorItems[idx]) return;
            editorItems[idx].nombre = $(this).find('.po-it-nombre').val() || editorItems[idx].nombre;
            editorItems[idx].cantidad_ean = parseInt($(this).find('.po-it-ean').val(), 10) || 100;
            editorItems[idx].copias = parseInt($(this).find('.po-it-copias').val(), 10) || 1;
            editorItems[idx].modo = $(this).find('.po-it-modo').val();
            editorItems[idx].color = $(this).find('.po-it-color').val();
            editorItems[idx].ean13 = $(this).find('.po-it-ean13').val() || '';
            if (canEditPrice && editorItems[idx].precioUnlocked) {
                const p = $(this).find('.po-it-precio').val();
                const next = p === '' ? null : toPrice2(p);
                const cur = toPrice2(editorItems[idx].precio);
                const orig = toPrice2(editorItems[idx].precio_original);
                editorItems[idx].precio = next;
                if (next !== null && orig !== null && Math.abs(next - orig) > 0.0001 && (cur === null || Math.abs(next - cur) > 0.0001)) {
                    editorItems[idx].precio_original = null;
                }
            }
        });
        return editorItems.map(function(it, i) {
            return {
                id: it.id || 0,
                sku: it.sku,
                nombre: it.nombre,
                precio: it.precio,
                precio_original: it.precio_original == null || it.precio_original === '' ? null : toPrice2(it.precio_original),
                cantidad_ean: it.cantidad_ean,
                copias: it.copias,
                modo: it.modo,
                color: it.color,
                ean13: it.ean13 || '',
                orden_posicion: i
            };
        });
    }

    function payloadBase() {
        return {
            tipo: $('#po-tipo').val(),
            prioridad: $('#po-prioridad').is(':checked') ? 1 : 0,
            notas: $('#po-notas').val(),
            items: JSON.stringify(collectItems())
        };
    }

    function openOrder(id) {
        post('riverso_print_orders_get', { id: id }).done(function(res) {
            if (!res.success) {
                alert(res.data && res.data.message || 'Error');
                return;
            }
            fillEditor(res.data.order);
            switchTab('editor');
        });
    }

    $('#btn-new-order').on('click', function() {
        resetEditor();
        switchTab('editor');
        const editor = document.getElementById('tab-editor');
        if (editor) editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    $(document).on('click', '.po-open', function() { openOrder($(this).data('id')); });

    $(document).on('click', '.po-it-del', function() {
        const idx = parseInt($(this).closest('tr').data('idx'), 10);
        collectItems();
        editorItems.splice(idx, 1);
        renderItems();
    });

    $(document).on('click', '.po-unlock-precio', function() {
        if (!canEditPrice) return;
        if (!confirm('El nuevo precio se usará solo en esta impresión. El producto en catálogo no cambia.')) {
            return;
        }
        const idx = parseInt($(this).closest('tr').data('idx'), 10);
        collectItems();
        if (editorItems[idx]) {
            editorItems[idx].precioUnlocked = true;
        }
        renderItems();
        $('#po-items-body tr[data-idx="' + idx + '"] .po-it-precio').trigger('focus');
    });

    $(document).on('click', '.po-round-precio', function() {
        if (!canCreate) return;
        const idx = parseInt($(this).closest('tr').data('idx'), 10);
        collectItems();
        if (!editorItems[idx] || editorItems[idx].precio == null || editorItems[idx].precio === '') return;
        if (!priceHasFraction(editorItems[idx].precio_original)) {
            editorItems[idx].precio_original = toPrice2(editorItems[idx].precio);
        }
        editorItems[idx].precio = Math.round(Number(editorItems[idx].precio));
        renderItems();
    });

    $(document).on('click', '.po-unround-precio', function() {
        if (!canCreate) return;
        const idx = parseInt($(this).closest('tr').data('idx'), 10);
        collectItems();
        if (!editorItems[idx] || !canUnroundPrice(editorItems[idx])) return;
        editorItems[idx].precio = toPrice2(editorItems[idx].precio_original);
        renderItems();
    });

    $('#po-product-search').on('click', searchProducts);
    $('#po-product-clear').on('click', function() {
        $('#po-product-q').val('');
        $('#po-product-results').empty();
    });
    $('#po-product-q').on('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); searchProducts(); } });

    function searchProducts() {
        const q = $('#po-product-q').val().trim();
        if (!q) return;
        $('#po-product-results').text('Buscando...');
        post('riverso_tienda_local_search', { query: q }).done(function(res) {
            const items = (res.success && res.data && res.data.items) ? res.data.items.filter(Boolean) : [];
            if (!items.length) {
                $('#po-product-results').html('<p class="po-muted">Sin resultados.</p>');
                return;
            }
            $('#po-product-results').html(items.map(function(p) {
                return '<div class="po-product-hit">' +
                    '<div><strong>' + esc(p.nombre) + '</strong><br><code>' + esc(p.sku) + '</code> · ' + esc(p.precio_formateado || p.precio || '') + '</div>' +
                    '<button type="button" class="button po-add-prod" data-sku="' + esc(p.sku) + '" data-nombre="' + esc(p.nombre) + '" data-precio="' + esc(p.precio || 0) + '">Agregar</button>' +
                    '</div>';
            }).join(''));
        }).fail(function() {
            $('#po-product-results').html('<p class="po-muted">Error buscando.</p>');
        });
    }

    $(document).on('click', '.po-add-prod', function() {
        collectItems();
        editorItems.push({
            sku: $(this).data('sku'),
            nombre: $(this).data('nombre'),
            precio: parseFloat($(this).data('precio')) || null,
            cantidad_ean: 100,
            copias: 1,
            modo: defaultModo(),
            color: 'BN'
        });
        renderItems();
    });

    function defaultModo() {
        const map = {
            etiqueta_producto: 'BolsaCOD',
            bolsa: 'Bolsa',
            etiqueta_simple: 'EtiquetaSimple',
            etiqueta_logo: 'EtiquetaLogo'
        };
        return map[$('#po-tipo').val()] || 'BolsaCOD';
    }

    function saveOrder() {
        const data = payloadBase();
        const id = $('#po-order-id').val();
        const action = id ? 'riverso_print_orders_update' : 'riverso_print_orders_create';
        if (id) data.id = id;
        $('#po-editor-msg').text('Guardando...');
        return post(action, data).then(function(res) {
            if (!res.success) {
                $('#po-editor-msg').text(res.data && res.data.message || 'Error');
                return $.Deferred().reject(res).promise();
            }
            fillEditor(res.data.order);
            $('#po-editor-msg').text('Guardado ' + res.data.order.numero_orden);
            return res.data.order;
        });
    }

    function editorHasContent() {
        collectItems();
        if (editorItems.length) return true;
        return ($('#po-notas').val() || '').trim() !== '';
    }

    function canSaveDraftOnClose() {
        if (!canCreate) return false;
        const estado = currentOrder && currentOrder.estado;
        if (!estado) return true;
        return estado === 'borrador' || estado === 'pendiente' || estado === 'aprobada';
    }

    function closeEditor() {
        const goList = function() {
            resetEditor();
            switchTab('listado');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };
        if (canSaveDraftOnClose() && editorHasContent()) {
            $('#po-editor-msg').text('Guardando borrador...');
            saveOrder().then(goList);
            return;
        }
        goList();
    }

    $(document).on('click', '.po-close-order', function() {
        closeEditor();
    });

    $('#po-save').on('click', function() { saveOrder(); });

    $('#po-submit').on('click', function() {
        saveOrder().then(function(order) {
            return post('riverso_print_orders_submit', { id: order.id });
        }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            fillEditor(res.data.order);
            $('#po-editor-msg').text('Enviada a pendiente');
        });
    });

    $('#po-approve').on('click', function() {
        const id = $('#po-order-id').val();
        if (!id) return;
        post('riverso_print_orders_approve', { id: id }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            fillEditor(res.data.order);
        });
    });

    $('#po-return').on('click', function() {
        const id = $('#po-order-id').val();
        if (!id) return;
        post('riverso_print_orders_return_draft', { id: id }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            fillEditor(res.data.order);
        });
    });

    $('#po-cancel').on('click', function() {
        const id = $('#po-order-id').val();
        if (!id) {
            resetEditor();
            switchTab('listado');
            return;
        }
        if (!canCancel) return;
        askCancelMotivo(function(motivo) {
            post('riverso_print_orders_cancel', { id: id, motivo: motivo }).done(function(res) {
                if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
                fillEditor(res.data.order);
            });
        });
    });

    function jobsFromOrder(order) {
        return (order.items || []).map(function(it) {
            return {
                nombre: it.nombre,
                sku: it.sku,
                cantidad: it.cantidad_ean || 100,
                precio: it.precio == null || it.precio === '' ? null : Math.round(Number(it.precio)),
                copias: it.copias || 1,
                modo: it.modo || 'BolsaCOD',
                color: it.color || 'BN',
                ean13: it.ean13 || null,
                printerName: (typeof RiversoLabelPrint !== 'undefined' && RiversoLabelPrint.getPreferred) ? RiversoLabelPrint.getPreferred() : null
            };
        });
    }

    function printOrder(order) {
        if (typeof RiversoLabelPrint === 'undefined') {
            alert('El módulo de impresión no está cargado. Recarga la página.');
            return;
        }
        const jobs = jobsFromOrder(order);
        if (!jobs.length) {
            alert('La orden no tiene ítems');
            return;
        }
        if (!RiversoLabelPrint.isHealthy()) {
            alert('Agente de impresión no disponible. Asegúrate de que EtiquetadorRS.exe está ejecutándose en este PC.');
            return;
        }
        const printer = RiversoLabelPrint.getPreferred() || '';
        if (!confirm('Imprimir ' + jobs.length + ' producto(s) / ' + order.total_copias + ' copias' + (printer ? ' en ' + printer : '') + '?')) {
            return;
        }
        const reprint = order.estado === 'impresa';
        RiversoLabelPrint.print(jobs).then(function() {
            if (reprint) {
                resetEditor();
                switchTab('listado');
                return $.Deferred().resolve(null).promise();
            }
            return post('riverso_print_orders_mark_printed', {
                id: order.id,
                impresora_nombre: printer
            });
        }).then(function(res) {
            if (!res) return;
            if (!res.success) {
                alert('Se imprimió, pero no se pudo registrar: ' + (res.data && res.data.message || 'error'));
                return;
            }
            resetEditor();
            switchTab('listado');
        }).catch(function(err) {
            alert(err && err.message ? err.message : 'Error de impresión');
        });
    }

    function ensureAndPrint(id) {
        const run = function(order) {
            if (order.estado === 'borrador' || order.estado === 'pendiente' || order.estado === 'aprobada') {
                printOrder(order);
            } else {
                alert('Esta orden no se puede imprimir.');
            }
        };
        if (currentOrder && String(currentOrder.id) === String(id)) {
            saveOrder().then(run);
        } else {
            post('riverso_print_orders_get', { id: id }).done(function(res) {
                if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
                run(res.data.order);
            });
        }
    }

    $('#po-print').on('click', function() {
        const id = $('#po-order-id').val();
        if (!id) {
            saveOrder().then(function(order) { printOrder(order); });
            return;
        }
        ensureAndPrint(id);
    });

    $('#po-reprint').on('click', function() {
        if (!currentOrder || currentOrder.estado !== 'impresa') return;
        printOrder(currentOrder);
    });

    $('#po-use-as-draft').on('click', function() {
        const id = $('#po-order-id').val();
        if (!id || !canCreate) return;
        post('riverso_print_orders_duplicate', { id: id }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            fillEditor(res.data.order);
            switchTab('editor');
            $('#po-editor-msg').text('Nuevo borrador ' + res.data.order.numero_orden);
        });
    });

    $(document).on('click', '.po-do-print', function() {
        ensureAndPrint($(this).data('id'));
    });
    $(document).on('click', '.po-do-approve', function() {
        post('riverso_print_orders_approve', { id: $(this).data('id') }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            loadList();
        });
    });
    $(document).on('click', '.po-do-cancel', function() {
        const id = $(this).data('id');
        askCancelMotivo(function(motivo) {
            post('riverso_print_orders_cancel', { id: id, motivo: motivo }).done(function(res) {
                if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
                loadList();
            });
        });
    });
    $(document).on('click', '.po-do-dup', function() {
        post('riverso_print_orders_duplicate', { id: $(this).data('id') }).done(function(res) {
            if (!res.success) { alert(res.data && res.data.message || 'Error'); return; }
            fillEditor(res.data.order);
            switchTab('editor');
        });
    });

    function loadStats() {
        post('riverso_print_orders_get_stats').done(function(res) {
            if (!res.success) return;
            const s = res.data;
            $('#po-stats-cards').html(
                card('Pendientes', s.pendientes, '#f59e0b') +
                card('Aprobadas', s.aprobadas, '#2563eb') +
                card('Impresas hoy', s.impresa_hoy, '#16a34a') +
                card('Creadas hoy', s.creadas_hoy, '#7c3aed') +
                card('Canceladas', s.canceladas, '#dc2626') +
                card('Borradores', s.by_estado && s.by_estado.borrador || 0, '#6b7280')
            );
            const rec = s.recientes || [];
            if (!rec.length) {
                $('#po-stats-recent').html('<tr><td colspan="6">Sin impresiones recientes.</td></tr>');
                return;
            }
            $('#po-stats-recent').html(rec.map(function(o) {
                return '<tr><td><code>' + esc(o.numero_orden) + '</code></td><td>' + esc(o.total_items) +
                    '</td><td>' + esc(o.total_copias) + '</td><td>' + esc(o.solicitado_por_nombre) +
                    '</td><td>' + esc(o.impresora_nombre || '—') + '</td><td>' + esc(o.impreso_en || '') + '</td></tr>';
            }).join(''));
        });
    }

    function card(label, value, color) {
        return '<div class="riverso-stat-card"><div><div class="stat-value" style="font-size:28px;font-weight:700;color:' + color + ';">' +
            esc(value) + '</div><div class="stat-label">' + esc(label) + '</div></div></div>';
    }

    loadList();
});
</script>
