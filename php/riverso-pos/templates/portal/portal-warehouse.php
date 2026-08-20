<?php
/**
 * Bodega e inventario — Portal interno
 */

if (!defined('ABSPATH')) {
    exit;
}

$wh_embed = !empty($riverso_wh_embed);
if ($wh_embed) {
    if (!current_user_can('riverso_view_stock') && !current_user_can('riverso_view_warehouse') && !current_user_can('manage_options')) {
        echo '<div style="padding:20px;color:#d32f2f;">Sin permisos para acceder a esta sección</div>';
        return;
    }
} elseif (!current_user_can('riverso_view_warehouse') && !current_user_can('manage_options')) {
    echo '<div style="padding:20px;color:#d32f2f;">Sin permisos para acceder a esta sección</div>';
    return;
}

$nonce = wp_create_nonce('riverso_pos_nonce');
$can_edit = current_user_can('riverso_edit_warehouse') || current_user_can('riverso_edit_stock') || current_user_can('manage_options');
$can_count = current_user_can('riverso_do_inventory') || current_user_can('riverso_edit_stock') || current_user_can('manage_options');
$can_orders = current_user_can('riverso_manage_inventory_orders') || current_user_can('manage_options');
$can_sort_orders = current_user_can('riverso_manage_sort_orders') || current_user_can('riverso_edit_stock') || current_user_can('manage_options');
$can_history = current_user_can('riverso_view_inventory_history') || $can_count || current_user_can('manage_options');
$can_stock_status = $can_count || $can_history;
$location_types = class_exists('Riverso_Warehouse_Module') ? Riverso_Warehouse_Module::LOCATION_TYPES : [
    'pasillo' => 'Pasillo', 'estante' => 'Estante', 'rack' => 'Rack', 'piso' => 'Piso',
    'meson' => 'Mesón', 'vitrina' => 'Vitrina', 'bodega_ext' => 'Bodega Externa',
];
?>

<div class="portal-warehouse-section">
    <style>
        .portal-warehouse-section {
            max-width: 1200px;
            --border: #c3c4c7;
            --primary: #2271b1;
            --text-secondary: #646970;
            --bg-light: #f6f7f7;
            --warning: #dba617;
        }
        .portal-warehouse-section .btn {
            display: inline-block; padding: 6px 12px; border-radius: 4px; cursor: pointer;
            font-size: 13px; border: 1px solid #c3c4c7; background: #f6f7f7; color: #1d2327; text-decoration: none;
        }
        .portal-warehouse-section .btn-primary { background: #2271b1; color: #fff; border-color: #2271b1; }
        .portal-warehouse-section .btn-secondary { background: #f6f7f7; color: #1d2327; }
        .portal-warehouse-section .btn-danger { background: #d32f2f; color: #fff; border-color: #d32f2f; }
        .portal-warehouse-section .btn-sm { font-size: 12px; padding: 4px 10px; }
        .wh-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
        .wh-tab { padding: 10px 18px; border: none; background: none; cursor: pointer; font-size: 14px; font-weight: 600; color: var(--text-secondary); border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .wh-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .wh-panel { display: none; }
        .wh-panel.active { display: block; }
        .wh-toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
        .wh-toolbar input, .wh-toolbar select, .wh-field input, .wh-field select, .wh-field textarea {
            padding: 8px 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px;
        }
        .wh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
        .wh-card { background: var(--bg-light); padding: 14px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
        .wh-card:hover { border-color: var(--primary); }
        .wh-card.inactive { opacity: .85; background: #fafafa; border-color: #ddd; }
        .wh-card .code { font-weight: 700; font-size: 16px; }
        .wh-card .meta { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }
        .wh-card-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .wh-actions { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
        .wh-action { display: flex; flex-direction: column; gap: 6px; padding: 18px; background: white; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; text-align: left; }
        .wh-action:hover { border-color: var(--primary); box-shadow: 0 2px 8px rgba(25,118,210,.12); }
        .wh-action strong { font-size: 15px; }
        .wh-action span { font-size: 13px; color: var(--text-secondary); }
        .scan-box { background: white; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .scan-input { width: 100%; font-size: 20px; padding: 12px 14px !important; letter-spacing: .04em; }
        .scan-loc { background: #e3f2fd; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .scan-loc .loc-name { font-size: 20px; font-weight: 700; }
        .last-item { border: 2px solid var(--primary); background: #f3f8ff; border-radius: 8px; padding: 12px; margin: 12px 0; }
        .last-item.abierto { border-color: var(--warning); background: #fff8e1; }
        .item-row { display: grid; grid-template-columns: 1fr 110px 110px 90px 160px; gap: 8px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
        .item-row.abierto { opacity: .75; background: #fff8e1; }
        .item-row.editing { background: #e3f2fd; opacity: 1; }
        .item-row .row-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
        .item-row input[type="number"] { width: 90px; padding: 6px 8px; }
        .item-head { font-size: 12px; color: var(--text-secondary); font-weight: 600; }
        .wh-summary { display: flex; gap: 16px; flex-wrap: wrap; margin: 12px 0; }
        .wh-chip { background: var(--bg-light); border-radius: 20px; padding: 6px 12px; font-size: 13px; }
        .wh-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100000; align-items: center; justify-content: center; }
        .wh-modal.open { display: flex; }
        .wh-modal-card { background: white; border-radius: 8px; padding: 20px; width: min(520px, 94vw); max-height: 90vh; overflow: auto; }
        .wh-field { margin-bottom: 10px; }
        .wh-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .wh-table { width: 100%; border-collapse: collapse; background: white; }
        .wh-table th, .wh-table td { padding: 10px; border-bottom: 1px solid var(--border); text-align: left; font-size: 13px; }
        .wh-table th { background: #f5f5f5; }
        .badge-open { background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-ok { background: #e8f5e9; color: #2e7d32; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-off { background: #eceff1; color: #546e7a; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .wh-loc-sec.active { background: var(--primary, #1976d2); color: #fff; border-color: transparent; }
        .suggest { border: 1px solid var(--border); background: white; max-height: 180px; overflow: auto; display: none; }
        .suggest div { padding: 8px 10px; cursor: pointer; font-size: 13px; }
        .suggest div:hover { background: #f5f5f5; }
        .qty-row { display: flex; gap: 8px; align-items: center; margin: 10px 0; flex-wrap: wrap; }
        .hidden { display: none !important; }
        .btn-danger { background: #d32f2f; color: #fff; border: none; }
        .btn-danger:hover { background: #b71c1c; }
        .wh-alarm { display: none; border-radius: 8px; padding: 12px 14px; margin: 10px 0; font-weight: 600; font-size: 14px; }
        .wh-alarm.show { display: block; }
        .wh-alarm.error { background: #ffebee; border: 2px solid #c62828; color: #b71c1c; }
        .wh-alarm.warn { background: #fff8e1; border: 2px solid #f9a825; color: #e65100; }
        .wh-warn-list { max-height: 240px; overflow: auto; margin: 10px 0 14px; border: 1px solid #ffe082; border-radius: 6px; }
        .wh-mov-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        .scan-loc.unknown { background: #eceff1; }
        .scan-loc .loc-tools { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .scan-loc .loc-tools select, .scan-loc .loc-tools input { min-width: 180px; }
        .loc-notice { font-size: 12px; color: #1565c0; margin-top: 6px; font-weight: 600; }
        .qty-row .qty-direct { font-size: 16px; font-weight: 700; width: 110px !important; padding: 8px 10px !important; }
        <?php if (!empty($wh_embed)): ?>
        .wh-tabs { display: none !important; }
        .wh-panel { display: none !important; }
        #wh-panel-inventario.wh-panel { display: block !important; }
        <?php endif; ?>
    </style>

    <div class="wh-tabs">
        <button type="button" class="wh-tab active" data-wh-tab="ubicaciones">Ubicaciones</button>
        <?php if ($can_count): ?>
        <button type="button" class="wh-tab" data-wh-tab="inventario">Inventario</button>
        <?php endif; ?>
        <?php if ($can_history): ?>
        <button type="button" class="wh-tab" data-wh-tab="historial">Historial</button>
        <?php endif; ?>
        <button type="button" class="wh-tab" data-wh-tab="movimientos">Movimientos</button>
        <?php if ($can_stock_status): ?>
        <button type="button" class="wh-tab" data-wh-tab="stock-status">Estado de stock</button>
        <?php endif; ?>
    </div>

    <div class="wh-panel<?php echo empty($wh_embed) ? ' active' : ''; ?>" id="wh-panel-ubicaciones">
        <div class="wh-toolbar">
            <input type="search" id="wh-loc-search" placeholder="Buscar código, nombre, zona..." style="flex:1;min-width:180px;">
            <select id="wh-loc-tipo">
                <option value="">Todos los tipos</option>
                <?php foreach ($location_types as $k => $label): ?>
                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="wh-loc-estado">
                <option value="">Todas</option>
                <option value="1">Activas</option>
                <option value="0">Desactivadas</option>
            </select>
            <?php if ($can_edit): ?>
            <button type="button" class="btn btn-primary" id="wh-btn-new-loc">Nueva ubicación</button>
            <?php endif; ?>
        </div>
        <div class="wh-grid" id="wh-loc-grid"><div style="padding:20px;color:var(--text-secondary);">Cargando...</div></div>
    </div>

    <?php if ($can_count): ?>
    <div class="wh-panel<?php echo !empty($wh_embed) ? ' active' : ''; ?>" id="wh-panel-inventario">
        <div id="wh-inv-menu">
            <div class="wh-actions">
                <button type="button" class="wh-action" data-inv="general">
                    <strong>Hacer inventario</strong>
                    <span>Recorre varios lugares: escanea el código del lugar y luego los productos.</span>
                </button>
                <button type="button" class="wh-action" data-inv="lugar">
                    <strong>Hacer inventario de lugar</strong>
                    <span>Cuenta solo un lugar seleccionado y cierra con resumen.</span>
                </button>
                <button type="button" class="wh-action" data-inv="producto">
                    <strong>Hacer inventario de producto</strong>
                    <span>Solo productos locales con SKU local. El lugar puede quedar desconocido.</span>
                </button>
                <button type="button" class="wh-action" data-inv="ordenar">
                    <strong>Ordenar producto</strong>
                    <span>Registrar el traslado de un producto a su lugar preferido.</span>
                </button>
                <button type="button" class="wh-action" data-inv="ordenes">
                    <strong>Orden de inventariar</strong>
                    <span>Crear o continuar órdenes pendientes de conteo.</span>
                </button>
                <button type="button" class="wh-action" data-inv="sort-orders">
                    <strong>Orden de ordenar</strong>
                    <span>Listas de productos a mover desde recepción o bodega a su lugar.</span>
                </button>
            </div>
            <div id="wh-open-counts" style="margin-top:20px;"></div>
        </div>

        <div id="wh-inv-setup" class="hidden scan-box"></div>
        <div id="wh-inv-scan" class="hidden"></div>
        <div id="wh-inv-summary" class="hidden scan-box"></div>
        <div id="wh-inv-orders" class="hidden"></div>
        <div id="wh-inv-sort-orders" class="hidden"></div>
    </div>
    <?php endif; ?>

    <?php if ($can_history): ?>
    <div class="wh-panel" id="wh-panel-historial">
        <div class="wh-toolbar">
            <select id="wh-hist-estado">
                <option value="">Todos</option>
                <option value="abierto">Abiertos</option>
                <option value="cerrado">Cerrados</option>
            </select>
            <button type="button" class="btn btn-secondary" id="wh-hist-reload">Actualizar</button>
        </div>
        <table class="wh-table">
            <thead>
                <tr><th>ID</th><th>Nombre</th><th>Tipo</th><th>Estado</th><th>Lugar</th><th>Inicio</th><th></th></tr>
            </thead>
            <tbody id="wh-hist-body"><tr><td colspan="7">Cargando...</td></tr></tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="wh-panel" id="wh-panel-movimientos">
        <div class="wh-toolbar">
            <input type="search" id="wh-mov-q" placeholder="Buscar SKU o nombre..." style="flex:1;min-width:160px;">
            <select id="wh-mov-tipo">
                <option value="">Todos los tipos</option>
                <?php
                $mov_types = class_exists('Riverso_Warehouse_Module') ? Riverso_Warehouse_Module::MOVEMENT_TYPES : [];
                foreach ($mov_types as $k => $m):
                ?>
                <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($m['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" id="wh-mov-desde">
            <input type="date" id="wh-mov-hasta">
            <button type="button" class="btn btn-secondary btn-sm" id="wh-mov-reload">Actualizar</button>
        </div>
        <div style="background:white;border:1px solid var(--border);border-radius:8px;overflow:auto;">
            <table class="wh-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Producto</th>
                        <th>Cant.</th>
                        <th>Antes</th>
                        <th>Después</th>
                        <th>Ubicación</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody id="wh-mov-body"><tr><td colspan="8">Cargando...</td></tr></tbody>
            </table>
        </div>
    </div>

    <?php if ($can_stock_status): ?>
    <div class="wh-panel" id="wh-panel-stock-status">
        <div class="wh-toolbar">
            <input type="search" id="wh-stock-q" placeholder="Buscar SKU o nombre..." style="flex:1;min-width:180px;">
            <select id="wh-stock-investado">
                <option value="">Estado inventariado: Todos</option>
                <option value="exacto">Exacto</option>
                <option value="al_menos">Al menos</option>
                <option value="desconocido">Desconocido</option>
            </select>
            <select id="wh-stock-confestado">
                <option value="">Estado confianza: Todos</option>
                <option value="confiable">Confiable</option>
                <option value="poco_confiable">Poco confiable</option>
                <option value="dudoso">Dudoso</option>
            </select>
            <select id="wh-stock-alerta">
                <option value="">Alerta: Todos</option>
                <option value="1">Solo con alerta</option>
                <option value="0">Sin alerta</option>
            </select>
            <button type="button" class="btn btn-secondary btn-sm" id="wh-stock-reload">Actualizar</button>
        </div>
        <div id="wh-stock-table" style="background:white;border:1px solid var(--border);border-radius:8px;padding:12px;">
            Cargando...
        </div>
    </div>
    <?php endif; ?>

    <div class="wh-modal" id="wh-stock-modal">
        <div class="wh-modal-card">
            <h3 style="margin:0 0 6px;">Límites de stock</h3>
            <p id="wh-stock-modal-prod" class="meta" style="margin:0 0 14px;"></p>
            <input type="hidden" id="wh-stock-modal-id">
            <div class="wh-field">
                <label>Stock mínimo (aviso para encargar)</label>
                <input type="number" id="wh-stock-min" min="0" step="1" placeholder="Vacío = sin aviso">
            </div>
            <div class="wh-field">
                <label>Stock crítico</label>
                <input type="number" id="wh-stock-crit" min="0" step="1" placeholder="Vacío = sin crítico">
                <p class="meta" style="margin:6px 0 0;">El crítico nunca puede ser mayor que el mínimo. Si bajás el mínimo, el crítico se iguala. Si subís el crítico por encima del mínimo, el mínimo se iguala.</p>
            </div>
            <div id="wh-stock-modal-hint" class="loc-notice" style="display:none;"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
                <button type="button" class="btn btn-secondary" id="wh-stock-modal-cancel">Cancelar</button>
                <button type="button" class="btn btn-primary" id="wh-stock-modal-save">Guardar</button>
            </div>
        </div>
    </div>

    <div class="wh-modal" id="wh-close-warn-modal">
        <div class="wh-modal-card" style="width:min(720px,94vw);">
            <h3 id="wh-close-warn-title" style="margin:0 0 8px;color:#e65100;">Se va a modificar el stock</h3>
            <p id="wh-close-warn-intro" style="margin:0 0 10px;font-size:14px;">Revisá los cambios antes de aceptar. Podés volver a escanear.</p>
            <div id="wh-close-warn-missing-wrap" class="wh-warn-list" style="display:none;margin-bottom:12px;">
                <p style="margin:8px 10px;font-weight:600;">No escaneados — quedarán en 0</p>
                <table class="wh-table" style="margin:0;">
                    <thead><tr><th>SKU</th><th>Producto</th><th>Stock actual</th><th>Quedará</th></tr></thead>
                    <tbody id="wh-close-warn-missing"></tbody>
                </table>
            </div>
            <div id="wh-close-warn-diffs-wrap" class="wh-warn-list" style="display:none;">
                <p style="margin:8px 10px;font-weight:600;">Cantidades distintas al saldo actual</p>
                <table class="wh-table" style="margin:0;">
                    <thead><tr><th>SKU</th><th>Producto</th><th>Stock actual</th><th>Contado</th></tr></thead>
                    <tbody id="wh-close-warn-diffs"></tbody>
                </table>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
                <button type="button" class="btn btn-secondary" id="wh-close-warn-back">Volver a escanear</button>
                <button type="button" class="btn btn-primary" id="wh-close-warn-accept">Aceptar y aplicar</button>
            </div>
        </div>
    </div>

    <div class="wh-modal" id="wh-loc-modal">
        <div class="wh-modal-card">
            <h3 id="wh-loc-modal-title" style="margin:0 0 12px;">Ubicación</h3>
            <input type="hidden" id="wh-loc-id">
            <div class="wh-field"><label>Código *</label><input type="text" id="wh-loc-codigo" placeholder="Ej: A1-E3" required></div>
            <div class="wh-field"><label>Código de barras del lugar *</label><input type="text" id="wh-loc-barcode" placeholder="Escanear o escribir" required></div>
            <div class="wh-field"><label>Nombre (opcional)</label><input type="text" id="wh-loc-nombre"></div>
            <div class="wh-field">
                <label>Tipo (opcional)</label>
                <select id="wh-loc-tipo-edit">
                    <?php foreach ($location_types as $k => $label): ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wh-field"><label>Zona (opcional)</label><input type="text" id="wh-loc-zona" placeholder="Sector / pasillo"></div>
            <div class="wh-field"><label>Descripción (opcional)</label><textarea id="wh-loc-desc" rows="2" style="width:100%;"></textarea></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" id="wh-loc-cancel">Cancelar</button>
                <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary" id="wh-loc-save">Guardar</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="wh-modal" id="wh-link-modal">
        <div class="wh-modal-card">
            <h3 style="margin:0 0 12px;">Código no registrado</h3>
            <p id="wh-link-msg" style="margin:0 0 12px;">Este código no está registrado para este producto.</p>
            <div class="wh-field">
                <label>Cantidad del envase (unidades por código)</label>
                <input type="number" id="wh-link-pack-qty" min="1" step="1" value="1">
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin:0 0 14px;">
                Vincular crea el envase y el código de barras en el SKU local, y suma al conteo.
                Ignorar rechaza el código y no suma.
            </p>
            <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary" id="wh-link-ignore">Ignorar y rechazar</button>
                <button type="button" class="btn btn-primary" id="wh-link-bind">Vincular y sumar</button>
            </div>
        </div>
    </div>

    <div class="wh-modal" id="wh-loc-detail-modal">
        <div class="wh-modal-card" style="width:min(720px,94vw);">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                <div>
                    <h3 id="wh-loc-detail-title" style="margin:0 0 4px;">Lugar</h3>
                    <div id="wh-loc-detail-meta" class="meta"></div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="wh-loc-detail-close">Cerrar</button>
            </div>
            <div class="wh-toolbar" style="margin-top:12px;">
                <button type="button" class="btn btn-secondary btn-sm wh-loc-sec active" data-sec="inv">Inventario actual</button>
                <button type="button" class="btn btn-secondary btn-sm wh-loc-sec" data-sec="pref">Productos preferidos</button>
            </div>
            <div id="wh-loc-sec-inv">
                <table class="wh-table">
                    <thead><tr><th>SKU</th><th>Producto</th><th>Cantidad</th><th>Último conteo</th></tr></thead>
                    <tbody id="wh-loc-inv-body"><tr><td colspan="4">Cargando...</td></tr></tbody>
                </table>
            </div>
            <div id="wh-loc-sec-pref" class="hidden">
                <?php if ($can_edit): ?>
                <div class="wh-field">
                    <label>Asignar producto que prefiere este lugar</label>
                    <input type="text" id="wh-loc-pref-q" placeholder="Buscar SKU o nombre">
                    <div class="suggest" id="wh-loc-pref-sug"></div>
                </div>
                <label style="display:flex;gap:6px;align-items:center;margin:0 0 10px;font-size:13px;">
                    <input type="checkbox" id="wh-loc-pref-primary" checked> Marcar como lugar preferido principal
                </label>
                <?php endif; ?>
                <div id="wh-loc-pref-list">Cargando...</div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
    const canCount = <?php echo $can_count ? 'true' : 'false'; ?>;
    const canStockStatus = <?php echo $can_stock_status ? 'true' : 'false'; ?>;
    const canOrders = <?php echo $can_orders ? 'true' : 'false'; ?>;
    const canSortOrders = <?php echo $can_sort_orders ? 'true' : 'false'; ?>;
    const whEmbed = <?php echo !empty($wh_embed) ? 'true' : 'false'; ?>;
    const types = <?php echo wp_json_encode($location_types); ?>;
    const movementTypes = <?php echo wp_json_encode(class_exists('Riverso_Warehouse_Module') ? Riverso_Warehouse_Module::MOVEMENT_TYPES : new stdClass()); ?>;

    const state = {
        locations: [],
        count: null,
        location: null,
        lockedLocation: false,
        lockedProduct: null,
        items: [],
        lastItem: null,
        summary: null,
        abierto: false,
        qty: '',
        mode: null,
        orderId: null,
        orderTarget: null,
        pendingScan: null,
        editingId: null,
        detailLoc: null,
        sortOrder: null,
        draftSortProduct: null,
        stockStatusPage: 1,
        stockStatusPerPage: 20,
        stockStatusLoaded: false
    };

    function post(action, data) {
        return $.post(ajaxUrl, Object.assign({ action, nonce }, data || {}));
    }
    function esc(s) {
        return $('<div>').text(s == null ? '' : s).html();
    }
    function showPanel(id) {
        $('.wh-panel').removeClass('active');
        $('#wh-panel-' + id).addClass('active');
        $('.wh-tab').removeClass('active');
        $('.wh-tab[data-wh-tab="' + id + '"]').addClass('active');
    }
    function toast(msg, ok) {
        if (!ok) alert(msg);
    }

    $('.wh-tab').on('click', function() {
        const tab = $(this).data('wh-tab');
        showPanel(tab);
        if (tab === 'historial') loadHistory();
        if (tab === 'inventario') loadOpenCounts();
        if (tab === 'ubicaciones') loadLocations();
        if (tab === 'stock-status') loadStockStatus();
        if (tab === 'movimientos') loadMovements();
    });

    function loadLocations() {
        const payload = {
            search: $('#wh-loc-search').val(),
            tipo: $('#wh-loc-tipo').val()
        };
        const estado = $('#wh-loc-estado').val();
        if (estado !== '') payload.activo = estado;
        post('riverso_inventory_get_locations', payload).done(function(r) {
            if (!r.success) { $('#wh-loc-grid').html('Error al cargar'); return; }
            state.locations = r.data.locations || [];
            renderLocations();
        });
    }

    function renderLocations() {
        if (!state.locations.length) {
            $('#wh-loc-grid').html('<div style="padding:20px;color:var(--text-secondary);">No hay ubicaciones.</div>');
            return;
        }
        $('#wh-loc-grid').html(state.locations.map(function(l) {
            const active = parseInt(l.activo, 10) === 1;
            const isUnknown = String(l.codigo) === '?';
            let actions = '<button type="button" class="btn btn-secondary btn-sm wh-loc-inv" data-id="' + l.id + '">Inventario actual</button>' +
                '<button type="button" class="btn btn-secondary btn-sm wh-loc-pref" data-id="' + l.id + '">Preferidos</button>';
            if (canEdit && !isUnknown) {
                actions += '<button type="button" class="btn btn-secondary btn-sm wh-loc-edit" data-id="' + l.id + '">Editar</button>';
                if (active) {
                    actions += '<button type="button" class="btn btn-secondary btn-sm wh-loc-off" data-id="' + l.id + '">Desactivar</button>';
                } else {
                    actions += '<button type="button" class="btn btn-primary btn-sm wh-loc-on" data-id="' + l.id + '">Reactivar</button>';
                    actions += '<button type="button" class="btn btn-danger btn-sm wh-loc-del" data-id="' + l.id + '">Eliminar</button>';
                }
            }
            return '<div class="wh-card' + (active ? '' : ' inactive') + '" data-id="' + l.id + '">' +
                '<div class="code">' + esc(l.codigo) +
                ' <span class="' + (isUnknown ? 'badge-off' : (active ? 'badge-ok' : 'badge-off')) + '">' + (isUnknown ? 'Desconocido' : (active ? 'Activa' : 'Desactivada')) + '</span></div>' +
                '<div>' + esc(l.nombre || '') + '</div>' +
                '<div class="meta">' + esc(types[l.tipo] || l.tipo || '') +
                (l.zona ? ' · ' + esc(l.zona) : '') +
                (l.barcode ? '<br>BC: ' + esc(l.barcode) : '') +
                '<br>' + esc(l.preferidos_count || 0) + ' productos preferidos' +
                '</div>' +
                '<div class="wh-card-actions">' + actions + '</div></div>';
        }).join(''));
    }

    $('#wh-loc-search, #wh-loc-tipo, #wh-loc-estado').on('change keyup', function() { loadLocations(); });
    $('#wh-loc-grid').on('click', '.wh-card', function(e) {
        if ($(e.target).closest('button').length) return;
        const loc = state.locations.find(l => String(l.id) === String($(this).data('id')));
        if (loc) openLocDetail(loc, 'inv');
    });
    $('#wh-btn-new-loc').on('click', function() { openLocModal(null); });
    $('#wh-loc-cancel').on('click', function() { $('#wh-loc-modal').removeClass('open'); });

    function openLocModal(loc) {
        $('#wh-loc-id').val(loc ? loc.id : '');
        $('#wh-loc-codigo').val(loc ? loc.codigo : '');
        $('#wh-loc-nombre').val(loc ? loc.nombre : '');
        $('#wh-loc-tipo-edit').val(loc ? loc.tipo : 'estante');
        $('#wh-loc-zona').val(loc ? (loc.zona || '') : '');
        $('#wh-loc-barcode').val(loc ? (loc.barcode || '') : '');
        $('#wh-loc-desc').val(loc ? (loc.descripcion || '') : '');
        $('#wh-loc-modal-title').text(loc ? 'Editar ubicación' : 'Nueva ubicación');
        $('#wh-loc-modal').addClass('open');
        $('#wh-loc-codigo').trigger('focus');
    }

    $('#wh-loc-save').on('click', function() {
        const codigo = $('#wh-loc-codigo').val().trim();
        const barcode = $('#wh-loc-barcode').val().trim();
        if (!codigo) { alert('El código es obligatorio'); $('#wh-loc-codigo').trigger('focus'); return; }
        if (!barcode) { alert('El código de barras del lugar es obligatorio'); $('#wh-loc-barcode').trigger('focus'); return; }
        post('riverso_inventory_save_location', {
            id: $('#wh-loc-id').val(),
            codigo: codigo,
            nombre: $('#wh-loc-nombre').val(),
            tipo: $('#wh-loc-tipo-edit').val(),
            zona: $('#wh-loc-zona').val(),
            barcode: barcode,
            descripcion: $('#wh-loc-desc').val()
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            $('#wh-loc-modal').removeClass('open');
            loadLocations();
        });
    });

    function findLoc(id) {
        return (state.locations || []).find(function(l) { return String(l.id) === String(id); });
    }
    function showLocSection(sec) {
        $('.wh-loc-sec').removeClass('active');
        $('.wh-loc-sec[data-sec="' + sec + '"]').addClass('active');
        $('#wh-loc-sec-inv').toggleClass('hidden', sec !== 'inv');
        $('#wh-loc-sec-pref').toggleClass('hidden', sec !== 'pref');
    }
    function openLocDetail(loc, sec) {
        state.detailLoc = loc;
        $('#wh-loc-detail-title').text(loc.codigo + (loc.nombre ? ' · ' + loc.nombre : ''));
        $('#wh-loc-detail-meta').text((parseInt(loc.activo, 10) === 1 ? 'Activa' : 'Desactivada') +
            (loc.zona ? ' · ' + loc.zona : '') + (loc.barcode ? ' · BC ' + loc.barcode : ''));
        showLocSection(sec || 'inv');
        $('#wh-loc-detail-modal').addClass('open');
        $('#wh-loc-inv-body').html('<tr><td colspan="4">Cargando...</td></tr>');
        $('#wh-loc-pref-list').text('Cargando...');
        $('#wh-loc-pref-q').val('');
        $('#wh-loc-pref-sug').hide();
        post('riverso_inventory_get_location_overview', { id: loc.id }).done(function(r) {
            if (!r.success) {
                $('#wh-loc-inv-body').html('<tr><td colspan="4">' + esc((r.data && r.data.message) || 'Error') + '</td></tr>');
                return;
            }
            renderLocOverview(r.data);
        });
    }
    function renderLocOverview(data) {
        const inv = data.inventario || [];
        $('#wh-loc-inv-body').html(inv.length
            ? inv.map(function(p) {
                return '<tr><td>' + esc(p.canonical_sku) + '</td><td>' + esc(p.nombre_canonico) + '</td><td>' +
                    esc(p.cantidad_contada) + '</td><td>' + esc(p.fecha_conteo || '') +
                    (p.conteo_nombre ? ' · ' + esc(p.conteo_nombre) : '') + '</td></tr>';
            }).join('')
            : '<tr><td colspan="4">Aún no hay conteos cerrados en este lugar.</td></tr>');
        const pref = data.preferidos || [];
        if (!pref.length) {
            $('#wh-loc-pref-list').html('<p style="color:var(--text-secondary);">Ningún producto prefiere este lugar todavía.</p>');
            return;
        }
        $('#wh-loc-pref-list').html(pref.map(function(p) {
            const star = parseInt(p.es_preferido, 10) ? '★ Principal' : '☆';
            let actions = '';
            if (canEdit) {
                if (!parseInt(p.es_preferido, 10)) {
                    actions += ' <button type="button" class="btn btn-secondary btn-sm wh-pref-primary" data-pid="' + p.producto_base_id + '">Hacer principal</button>';
                }
                actions += ' <button type="button" class="btn btn-secondary btn-sm wh-pref-remove" data-pid="' + p.producto_base_id + '">Quitar</button>';
            }
            return '<div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">' +
                '<div><strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico || '') +
                ' <span class="meta">' + star + '</span></div><div>' + actions + '</div></div>';
        }).join(''));
    }
    function reloadLocDetail() {
        if (!state.detailLoc) return;
        post('riverso_inventory_get_location_overview', { id: state.detailLoc.id }).done(function(r) {
            if (r.success) renderLocOverview(r.data);
        });
        loadLocations();
    }
    $(document).on('click', '.wh-loc-inv', function(e) {
        e.stopPropagation();
        const loc = findLoc($(this).data('id'));
        if (loc) openLocDetail(loc, 'inv');
    });
    $(document).on('click', '.wh-loc-pref', function(e) {
        e.stopPropagation();
        const loc = findLoc($(this).data('id'));
        if (loc) openLocDetail(loc, 'pref');
    });
    $(document).on('click', '.wh-loc-edit', function(e) {
        e.stopPropagation();
        const loc = findLoc($(this).data('id'));
        if (loc) openLocModal(loc);
    });
    $(document).on('click', '.wh-loc-off', function(e) {
        e.stopPropagation();
        const id = $(this).data('id');
        if (!confirm('¿Desactivar esta ubicación? Dejará de usarse en conteos, pero podrás reactivarla.')) return;
        post('riverso_inventory_set_location_status', { id: id, activo: 0 }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo desactivar'); return; }
            loadLocations();
        });
    });
    $(document).on('click', '.wh-loc-on', function(e) {
        e.stopPropagation();
        post('riverso_inventory_set_location_status', { id: $(this).data('id'), activo: 1 }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo reactivar'); return; }
            loadLocations();
        });
    });
    $(document).on('click', '.wh-loc-del', function(e) {
        e.stopPropagation();
        if (!confirm('¿Eliminar permanentemente esta ubicación? Esta acción no se puede deshacer.')) return;
        post('riverso_inventory_delete_location', { id: $(this).data('id') }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo eliminar'); return; }
            $('#wh-loc-detail-modal').removeClass('open');
            loadLocations();
        });
    });
    $('#wh-loc-detail-close').on('click', function() { $('#wh-loc-detail-modal').removeClass('open'); });
    $(document).on('click', '.wh-loc-sec', function() { showLocSection($(this).data('sec')); });
    $(document).on('input', '#wh-loc-pref-q', function() {
        const q = $(this).val();
        if (q.length < 2) { $('#wh-loc-pref-sug').hide(); return; }
        post('riverso_inventory_search_products', { q: q, solo_sku_local: 1 }).done(function(r) {
            const list = (r.success && r.data.products) || [];
            if (!list.length) { $('#wh-loc-pref-sug').hide(); return; }
            $('#wh-loc-pref-sug').html(list.map(function(p) {
                return '<div data-id="' + p.id + '"><strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico) + '</div>';
            }).join('')).show();
        });
    });
    $(document).on('click', '#wh-loc-pref-sug div', function() {
        if (!state.detailLoc) return;
        const pid = $(this).data('id');
        $('#wh-loc-pref-sug').hide();
        $('#wh-loc-pref-q').val('');
        post('riverso_inventory_save_preferred_location', {
            producto_base_id: pid,
            ubicacion_id: state.detailLoc.id,
            es_preferido: $('#wh-loc-pref-primary').is(':checked') ? 1 : 0
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo asignar'); return; }
            reloadLocDetail();
        });
    });
    $(document).on('click', '.wh-pref-remove', function() {
        if (!state.detailLoc) return;
        post('riverso_inventory_remove_preferred_location', {
            producto_base_id: $(this).data('pid'),
            ubicacion_id: state.detailLoc.id
        }).done(function() { reloadLocDetail(); });
    });
    $(document).on('click', '.wh-pref-primary', function() {
        if (!state.detailLoc) return;
        post('riverso_inventory_set_primary_location', {
            producto_base_id: $(this).data('pid'),
            ubicacion_id: state.detailLoc.id
        }).done(function() { reloadLocDetail(); });
    });

    function hideInvViews() {
        $('#wh-inv-menu, #wh-inv-setup, #wh-inv-scan, #wh-inv-summary, #wh-inv-orders, #wh-inv-sort-orders').addClass('hidden');
    }
    function showInvMenu() {
        hideInvViews();
        state.orderId = null;
        state.orderTarget = null;
        $('#wh-inv-menu').removeClass('hidden');
        loadOpenCounts();
    }

    $('[data-inv]').on('click', function() {
        const mode = $(this).data('inv');
        if (mode === 'ordenes') { showOrders(); return; }
        if (mode === 'sort-orders') { showSortOrders(); return; }
        if (mode === 'ordenar') { showSortSetup(); return; }
        state.orderId = null;
        state.orderTarget = null;
        showCountSetup(mode);
    });

    function loadOpenCounts() {
        if (!canCount) return;
        post('riverso_inventory_list_counts', { estado: 'abierto', per_page: 10 }).done(function(r) {
            if (!r.success) return;
            const rows = r.data.counts || [];
            if (!rows.length) { $('#wh-open-counts').html(''); return; }
            $('#wh-open-counts').html('<h3 style="margin:0 0 8px;">Conteos abiertos</h3>' +
                '<table class="wh-table"><thead><tr><th>Nombre</th><th>Tipo</th><th>Lugar</th><th></th></tr></thead><tbody>' +
                rows.map(function(c) {
                    return '<tr><td>' + esc(c.nombre) + '</td><td>' + esc(c.tipo_conteo) + '</td><td>' +
                        esc(c.ubicacion_codigo || '—') + '</td><td><button class="btn btn-primary btn-sm wh-resume" data-id="' + c.id + '">Continuar</button>' +
                        (c.tipo_conteo === 'producto' || c.tipo_conteo === 'lugar' ? ' <button class="btn btn-danger btn-sm wh-abort-open" data-id="' + c.id + '">Abortar</button>' : '') +
                        '</td></tr>';
                }).join('') + '</tbody></table>');
        });
    }
    window.riversoWhLoadOpenCounts = loadOpenCounts;

    $('#wh-open-counts').on('click', '.wh-resume', function() {
        resumeCount($(this).data('id'));
    });
    $('#wh-open-counts').on('click', '.wh-abort-open', function() {
        const id = $(this).data('id');
        if (!confirm('¿Estás seguro de abortar este conteo? Se eliminará y no quedará registrado.')) return;
        post('riverso_inventory_abort_count', { id: id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo abortar'); return; }
            loadOpenCounts();
        });
    });

    function alarmBeep() {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'square';
            osc.frequency.value = 880;
            gain.gain.value = 0.08;
            osc.start();
            setTimeout(function() { osc.stop(); ctx.close(); }, 280);
        } catch (e) {}
    }
    function showAlarm(kind, msg) {
        const $el = $('#wh-alarm');
        if (!$el.length) { alert(msg); return; }
        $el.removeClass('error warn').addClass('show ' + (kind === 'warn' ? 'warn' : 'error')).text(msg);
        setTimeout(function() { $el.removeClass('show'); }, 8000);
    }

    function showCountSetup(mode) {
        state.mode = mode;
        hideInvViews();
        if (!state.locations.length) loadLocations();
        const fromOrder = state.orderTarget || null;
        let html = '<button type="button" class="btn btn-secondary btn-sm wh-back">← Volver</button>';
        html += '<h3 style="margin:12px 0;">' + ({
            general: 'Hacer inventario',
            lugar: 'Inventario de lugar',
            producto: 'Inventario de producto'
        }[mode] || 'Inventario') + '</h3>';
        if (state.orderId) {
            html += '<div class="wh-chip" style="margin-bottom:10px;">Orden #' + esc(state.orderId) + '</div>';
        }

        if (mode === 'lugar') {
            html += '<div class="wh-field"><label>Escanea o elige el lugar</label>' +
                '<input type="text" id="wh-setup-loc-code" class="scan-input" placeholder="Código o barcode del lugar"></div>' +
                '<div class="wh-field"><label>O selección manual</label><select id="wh-setup-loc"><option value="">— Seleccionar —</option></select></div>' +
                '<div id="wh-setup-loc-sel" class="meta"></div>';
        } else if (mode === 'producto') {
            state.lockedProduct = (fromOrder && fromOrder.product) ? fromOrder.product : null;
            html += '<p>Solo productos locales con SKU local. El lugar puede quedar desconocido.</p>' +
                '<div class="wh-field"><label>Buscar producto (SKU local)</label>' +
                '<input type="text" id="wh-setup-prod-q" placeholder="SKU o nombre">' +
                '<div class="suggest" id="wh-setup-prod-sug"></div></div>' +
                '<div id="wh-setup-prod-sel" class="meta"></div>';
        } else {
            html += '<p>Escanea el código de un lugar para comenzar. Puedes cambiar de lugar en cualquier momento.</p>' +
                '<div class="wh-field"><label>Lugar inicial (opcional)</label>' +
                '<input type="text" id="wh-setup-loc-code" class="scan-input" placeholder="Código o barcode del lugar"></div>' +
                '<select id="wh-setup-loc"><option value="">— Elegir después —</option></select>';
        }
        html += '<div style="margin-top:12px;"><button type="button" class="btn btn-primary" id="wh-setup-start">Comenzar</button></div>';
        $('#wh-inv-setup').html(html).removeClass('hidden');
        function applySetupTarget() {
            fillLocationSelect($('#wh-setup-loc'));
            if (mode === 'lugar' && fromOrder && fromOrder.location) {
                $('#wh-setup-loc').val(String(fromOrder.location.id));
                $('#wh-setup-loc-sel').text('Lugar de la orden: ' + fromOrder.location.codigo + ' · ' + (fromOrder.location.nombre || ''));
            }
            if (mode === 'producto' && state.lockedProduct) {
                $('#wh-setup-prod-sel').text('Producto de la orden: ' + state.lockedProduct.sku + ' · ' + state.lockedProduct.nombre);
            }
        }
        applySetupTarget();
        ensureLocations(applySetupTarget);
        $('#wh-setup-loc-code').trigger('focus');
    }

    function fillLocationSelect($sel) {
        if (!$sel.length) return;
        const current = $sel.val();
        const opts = ['<option value="">— Seleccionar —</option>'].concat(
            state.locations.map(l => '<option value="' + l.id + '">' + esc(l.codigo + ' · ' + (l.nombre || '')) + '</option>')
        );
        $sel.html(opts.join(''));
        if (current) $sel.val(current);
    }

    $(document).on('click', '.wh-back', showInvMenu);

    $(document).on('keydown', '#wh-setup-loc-code', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = $(this).val().trim();
        if (!code) return;
        post('riverso_inventory_find_location_by_barcode', { barcode: code }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Lugar no encontrado'); return; }
            $('#wh-setup-loc').val(r.data.location.id);
            startCount();
        });
    });

    $(document).on('input', '#wh-setup-prod-q', function() {
        const q = $(this).val();
        if (q.length < 2) { $('#wh-setup-prod-sug').hide(); return; }
        post('riverso_inventory_search_products', { q, solo_sku_local: 1 }).done(function(r) {
            const list = (r.success && r.data.products) || [];
            if (!list.length) { $('#wh-setup-prod-sug').hide(); return; }
            $('#wh-setup-prod-sug').html(list.map(p =>
                '<div data-id="' + p.id + '" data-sku="' + esc(p.canonical_sku) + '" data-name="' + esc(p.nombre_canonico) + '">' +
                '<strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico) + '</div>'
            ).join('')).show();
        });
    });
    $(document).on('click', '#wh-setup-prod-sug div', function() {
        state.lockedProduct = { id: $(this).data('id'), sku: $(this).data('sku'), nombre: $(this).data('name') };
        $('#wh-setup-prod-sel').text(state.lockedProduct.sku + ' · ' + state.lockedProduct.nombre);
        $('#wh-setup-prod-sug').hide();
    });
    $(document).on('click', '#wh-setup-start', startCount);

    function startCount() {
        if (state.mode === 'producto' && !state.lockedProduct) {
            alert('Selecciona un producto local con SKU local');
            return;
        }
        if (state.mode === 'lugar' && !$('#wh-setup-loc').val()) {
            alert('Selecciona un lugar para inventariar');
            return;
        }
        const payload = {
            tipo_conteo: state.mode,
            ubicacion_id: $('#wh-setup-loc').val() || '',
            producto_base_id: state.lockedProduct ? state.lockedProduct.id : '',
            orden_id: state.orderId || ''
        };
        post('riverso_inventory_start_count', payload).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo iniciar'); return; }
            enterScan(r.data.count);
        });
    }

    function resumeCount(id) {
        post('riverso_inventory_get_count', { id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            enterScan(r.data.count, r.data.summary);
        });
    }

    function enterScan(count, summary) {
        state.count = count;
        state.mode = count.tipo_conteo;
        state.lockedLocation = count.tipo_conteo === 'lugar';
        state.lockedProduct = count.producto_base_id ? {
            id: count.producto_base_id, sku: count.producto_sku, nombre: count.producto_nombre
        } : state.lockedProduct;
        state.location = count.ubicacion_id ? {
            id: count.ubicacion_id, codigo: count.ubicacion_codigo, nombre: count.ubicacion_nombre
        } : null;
        state.items = count.items || [];
        state.lastItem = state.items[0] || null;
        state.summary = summary || null;
        state.abierto = false;
        state.qty = '';
        renderScan();
        refreshSummary();
    }

    function renderScan() {
        hideInvViews();
        const loc = state.location;
        let html = '<div class="scan-box">';
        html += '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:10px;">' +
            '<button type="button" class="btn btn-secondary btn-sm wh-back">← Menú</button>' +
            '<div>' +
            (state.mode === 'producto' || state.mode === 'lugar' ? '<button type="button" class="btn btn-danger btn-sm" id="wh-btn-abort">Abortar conteo</button> ' : '') +
            '<button type="button" class="btn btn-secondary btn-sm" id="wh-btn-view-summary">Ver resumen</button> ' +
            '<button type="button" class="btn btn-primary btn-sm wh-btn-finish">Terminar conteo</button></div></div>';
        html += '<div class="scan-loc' + (loc ? '' : ' unknown') + '"><div><div style="font-size:12px;color:var(--text-secondary);">Lugar actual — los siguientes ingresos van aquí</div>' +
            '<div class="loc-name">' + (loc
                ? esc(loc.codigo + ' · ' + (loc.nombre || ''))
                : (state.mode === 'producto' ? 'Lugar desconocido' : 'Sin lugar — escanea un código de lugar')) + '</div>' +
            '<div class="loc-notice" id="wh-loc-notice"></div></div>';
        if (!state.lockedLocation) {
            html += '<div class="loc-tools">' +
                '<select id="wh-current-loc"></select>' +
                '<input type="text" id="wh-loc-scan" placeholder="Pistolear lugar" autocomplete="off" style="width:160px;">' +
                '</div>';
        }
        html += '</div>';
        if (state.lockedProduct) {
            html += '<div class="wh-chip">Producto: ' + esc(state.lockedProduct.sku + ' · ' + state.lockedProduct.nombre) + '</div>';
        }
        html += '<div id="wh-alarm" class="wh-alarm"></div>';
        html += '<input type="text" id="wh-scan" class="scan-input" placeholder="' +
            (state.mode === 'producto'
                ? 'SKU o barcode del producto, o pistolear un lugar para cambiar...'
                : 'Escanear código de barras...') +
            '" autocomplete="off">';
        html += '<div class="qty-row">' +
            '<label>Cantidad <input type="number" id="wh-qty" class="' + (state.mode === 'producto' ? 'qty-direct' : '') +
            '" min="1" step="1" style="width:90px;" placeholder="1"></label>' +
            (state.mode === 'producto' ? '<button type="button" class="btn btn-primary btn-sm" id="wh-btn-add-qty">Agregar</button>' : '') +
            '<label><input type="checkbox" id="wh-abierto"> Abierta / incompleta</label>' +
            '<span class="wh-chip" id="wh-live-sum">0 ítems · 0 uds</span>' +
            '</div>';
        html += '<div id="wh-last"></div>';
        html += '<div class="item-row item-head"><div>Producto</div><div>Cant.</div><div>Lugar</div><div>Estado</div><div></div></div>';
        html += '<div id="wh-items"></div>';
        html += '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);">' +
            '<button type="button" class="btn btn-primary wh-btn-finish">Terminar conteo</button></div>';
        html += '</div>';
        $('#wh-inv-scan').html(html).removeClass('hidden');
        renderItems();
        fillCurrentLocPicker();
        ensureLocations(function() { fillCurrentLocPicker(); });
        $('#wh-scan').trigger('focus');
    }

    function ensureLocations(cb) {
        post('riverso_inventory_get_locations', { activo: 1 }).done(function(r) {
            if (r.success) state.locations = r.data.locations || [];
            if (cb) cb();
        });
    }
    function fillCurrentLocPicker() {
        const $sel = $('#wh-current-loc');
        if (!$sel.length) return;
        const emptyLabel = state.mode === 'producto' ? 'Lugar desconocido' : '— Elegir lugar —';
        const opts = ['<option value="">' + emptyLabel + '</option>'].concat(
            (state.locations || []).map(function(l) {
                return '<option value="' + l.id + '">' + esc(l.codigo + ' · ' + (l.nombre || '')) + '</option>';
            })
        );
        $sel.html(opts.join(''));
        if (state.location && state.location.id) $sel.val(String(state.location.id));
    }

    function renderItems() {
        const last = state.lastItem;
        if (last) {
            $('#wh-last').html(
                '<div class="last-item' + (last.es_abierto ? ' abierto' : '') + '">' +
                '<strong>Último:</strong> ' + esc(last.sku) + ' · ' + esc(last.nombre) +
                ' <span style="margin-left:8px;">' + esc(last.envase_label) + '</span>' +
                '<div class="qty-row">' +
                '<input type="number" id="wh-last-qty" value="' + esc(last.cantidad_contada) + '" step="1" style="width:100px;">' +
                '<button type="button" class="btn btn-secondary btn-sm" id="wh-last-save">Guardar cantidad</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" id="wh-last-open">' + (last.es_abierto ? 'Marcar completa' : 'Marcar abierta') + '</button>' +
                '<button type="button" class="btn btn-secondary btn-sm" id="wh-last-del">Eliminar</button>' +
                '</div></div>'
            );
        } else {
            $('#wh-last').html('');
        }
        $('#wh-items').html((state.items || []).map(function(it) {
            const editing = String(state.editingId) === String(it.id);
            const open = parseInt(it.es_abierto, 10);
            if (editing) {
                return '<div class="item-row editing' + (open ? ' abierto' : '') + '">' +
                    '<div><strong>' + esc(it.sku) + '</strong><br>' + esc(it.nombre) + '<div class="meta">' + esc(it.envase_label) + '</div></div>' +
                    '<div><input type="number" class="wh-edit-qty" data-id="' + it.id + '" value="' + esc(it.cantidad_contada) + '" min="0" step="1"></div>' +
                    '<div>' + esc(it.ubicacion_codigo) + '</div>' +
                    '<div><label style="font-size:12px;"><input type="checkbox" class="wh-edit-open" data-id="' + it.id + '"' + (open ? ' checked' : '') + '> Abierta</label></div>' +
                    '<div class="row-actions">' +
                    '<button type="button" class="btn btn-primary btn-sm wh-item-save" data-id="' + it.id + '">Guardar</button>' +
                    '<button type="button" class="btn btn-secondary btn-sm wh-item-cancel">Cancelar</button>' +
                    '</div></div>';
            }
            return '<div class="item-row' + (open ? ' abierto' : '') + '">' +
                '<div><strong>' + esc(it.sku) + '</strong><br>' + esc(it.nombre) + '<div class="meta">' + esc(it.envase_label) + '</div></div>' +
                '<div>' + esc(it.cantidad_contada) + '</div>' +
                '<div>' + esc(it.ubicacion_codigo) + '</div>' +
                '<div>' + (open ? '<span class="badge-open">Abierta</span>' : '<span class="badge-ok">OK</span>') + '</div>' +
                '<div class="row-actions">' +
                '<button type="button" class="btn btn-secondary btn-sm wh-item-edit" data-id="' + it.id + '">Editar</button>' +
                '<button type="button" class="btn btn-secondary btn-sm wh-item-del" data-id="' + it.id + '">✕</button>' +
                '</div></div>';
        }).join(''));
        const s = state.summary;
        if (s) $('#wh-live-sum').text((s.total_items || 0) + ' ítems · ' + (s.total_unidades || 0) + ' uds · ' + (s.total_abiertos || 0) + ' abiertas');
    }

    function refreshSummary(cb) {
        if (!state.count) return;
        post('riverso_inventory_get_count', { id: state.count.id }).done(function(r) {
            if (!r.success) return;
            state.count = r.data.count;
            state.items = r.data.count.items || [];
            state.lastItem = state.items[0] || null;
            state.summary = r.data.summary;
            renderItems();
            if (cb) cb();
        });
    }

    $(document).on('keydown', '#wh-scan', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        handleScan($(this).val().trim());
        $(this).val('');
    });

    function handleScan(code) {
        if (!code || !state.count) return;
        const qty = $('#wh-qty').val();
        const abierto = $('#wh-abierto').is(':checked') ? 1 : 0;
        post('riverso_inventory_find_location_by_barcode', { barcode: code }).done(function(r) {
            if (r.success && r.data && r.data.location) {
                if (state.lockedLocation) {
                    alert('Este conteo está limitado a un solo lugar');
                    return;
                }
                changeLocation(r.data.location);
                return;
            }
            if (state.mode === 'producto') {
                addItem(code, qty, abierto);
                return;
            }
            post('riverso_inventory_decode_barcode', { barcode: code }).done(function(res) {
                if (!res.success) { alert((res.data && res.data.message) || 'Código no reconocido'); return; }
                if (res.data.kind === 'location') {
                    changeLocation(res.data.location);
                    return;
                }
                addItem(code, qty, abierto);
            });
        });
    }

    function addItem(code, qty, abierto, extra) {
        if (state.mode !== 'producto' && !state.location) {
            alert('Escanea primero un lugar');
            return;
        }
        const data = Object.assign({
            conteo_id: state.count.id,
            barcode: code,
            ubicacion_id: state.location ? state.location.id : '',
            es_abierto: abierto
        }, extra || {});
        if (qty) data.cantidad = qty;
        post('riverso_inventory_add_count_item', data).done(function(r) {
            if (!r.success) {
                const err = (r.data && r.data.code) || '';
                if (err === 'other_product') {
                    alarmBeep();
                    showAlarm('warn', (r.data && r.data.message) || 'El código pertenece a otro producto');
                    return;
                }
                if (err === 'unknown_sku') {
                    alarmBeep();
                    showAlarm('error', (r.data && r.data.message) || 'SKU no registrado para este producto');
                    openLinkModal(r.data, code, qty, abierto);
                    return;
                }
                if (err === 'ignored') {
                    showAlarm('warn', (r.data && r.data.message) || 'Código ignorado. No se sumó.');
                    return;
                }
                alarmBeep();
                showAlarm('error', (r.data && r.data.message) || 'No se pudo registrar');
                return;
            }
            state.lastItem = r.data.item;
            state.summary = r.data.summary;
            $('#wh-qty').val('');
            $('#wh-alarm').removeClass('show');
            refreshSummary();
        });
    }

    function openLinkModal(data, code, qty, abierto) {
        state.pendingScan = { code: code || (data && data.barcode) || '', qty: qty || '', abierto: abierto || 0 };
        const sku = (data && data.sku_local) || (state.lockedProduct && state.lockedProduct.sku) || '';
        const name = (data && data.producto_nombre) || (state.lockedProduct && state.lockedProduct.nombre) || '';
        $('#wh-link-msg').html(
            'El código <strong>' + esc(state.pendingScan.code) + '</strong> no está registrado para ' +
            '<strong>' + esc(sku) + '</strong> ' + esc(name) + '.'
        );
        $('#wh-link-pack-qty').val((data && data.suggested_pack_qty) || 1);
        $('#wh-link-modal').addClass('open');
        $('#wh-link-pack-qty').trigger('focus');
    }
    function closeLinkModal() {
        state.pendingScan = null;
        $('#wh-link-modal').removeClass('open');
    }
    $(document).on('click', '#wh-link-ignore', function() {
        if (!state.pendingScan) { closeLinkModal(); return; }
        const pending = state.pendingScan;
        post('riverso_inventory_add_count_item', {
            conteo_id: state.count.id,
            barcode: pending.code,
            ignorar: 1
        }).always(function() {
            closeLinkModal();
            showAlarm('warn', 'Código ignorado. No se sumó.');
        });
    });
    $(document).on('click', '#wh-link-bind', function() {
        if (!state.pendingScan) return;
        const pending = state.pendingScan;
        const packQty = $('#wh-link-pack-qty').val() || 1;
        closeLinkModal();
        addItem(pending.code, pending.qty, pending.abierto, {
            vincular: 1,
            cantidad_envase: packQty
        });
    });
    $(document).on('click', '#wh-btn-add-qty', function() {
        const qty = $('#wh-qty').val();
        if (!qty) { alert('Ingresa una cantidad'); return; }
        const sku = state.lockedProduct && state.lockedProduct.sku;
        if (!sku) { alert('No hay un producto seleccionado'); return; }
        addItem(sku, qty, $('#wh-abierto').is(':checked') ? 1 : 0);
    });
    $(document).on('keydown', '#wh-qty', function(e) {
        if (e.key !== 'Enter' || state.mode !== 'producto') return;
        e.preventDefault();
        $('#wh-btn-add-qty').trigger('click');
    });

    function changeLocation(loc) {
        const qty = $('#wh-qty').val();
        const abierto = $('#wh-abierto').is(':checked');
        const payload = { conteo_id: state.count.id };
        if (!loc) payload.clear = 1;
        else payload.ubicacion_id = loc.id;
        post('riverso_inventory_change_count_location', payload).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo cambiar de lugar'); return; }
            state.location = r.data.location || null;
            renderScan();
            $('#wh-qty').val(qty);
            $('#wh-abierto').prop('checked', abierto);
            const label = state.location
                ? ((state.location.codigo || '') + ' · ' + (state.location.nombre || ''))
                : 'Lugar desconocido';
            $('#wh-loc-notice').text('Lugar cambiado a ' + label.trim() + '. Los siguientes ingresos quedan aquí.');
            refreshSummary();
            $('#wh-scan').trigger('focus');
        });
    }

    $(document).on('change', '#wh-current-loc', function() {
        const id = $(this).val();
        if (!id) {
            if (state.mode === 'producto') changeLocation(null);
            else fillCurrentLocPicker();
            return;
        }
        const loc = (state.locations || []).find(function(l) { return String(l.id) === String(id); });
        if (loc) changeLocation(loc);
    });
    $(document).on('keydown', '#wh-loc-scan', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = $(this).val().trim();
        $(this).val('');
        if (!code) return;
        post('riverso_inventory_find_location_by_barcode', { barcode: code }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Lugar no encontrado'); return; }
            changeLocation(r.data.location);
        });
    });

    $(document).on('click', '.wh-item-edit', function() {
        state.editingId = $(this).data('id');
        renderItems();
        $('.wh-edit-qty[data-id="' + state.editingId + '"]').trigger('focus').select();
    });
    $(document).on('click', '.wh-item-cancel', function() {
        state.editingId = null;
        renderItems();
    });
    $(document).on('click', '.wh-item-save', function() {
        const id = $(this).data('id');
        const qty = $('.wh-edit-qty[data-id="' + id + '"]').val();
        const abierto = $('.wh-edit-open[data-id="' + id + '"]').is(':checked') ? 1 : 0;
        post('riverso_inventory_update_count_item', {
            item_id: id,
            cantidad_contada: qty,
            es_abierto: abierto
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo guardar'); return; }
            state.editingId = null;
            refreshSummary();
        });
    });
    $(document).on('keydown', '.wh-edit-qty', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).closest('.item-row').find('.wh-item-save').trigger('click');
        }
        if (e.key === 'Escape') {
            state.editingId = null;
            renderItems();
        }
    });

    $(document).on('click', '#wh-last-save', function() {
        if (!state.lastItem) return;
        post('riverso_inventory_update_count_item', {
            item_id: state.lastItem.id,
            cantidad_contada: $('#wh-last-qty').val()
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            refreshSummary();
        });
    });
    $(document).on('click', '#wh-last-open', function() {
        if (!state.lastItem) return;
        post('riverso_inventory_update_count_item', {
            item_id: state.lastItem.id,
            es_abierto: state.lastItem.es_abierto ? 0 : 1
        }).done(function() { refreshSummary(); });
    });
    $(document).on('click', '#wh-last-del, .wh-item-del', function() {
        const id = $(this).data('id') || (state.lastItem && state.lastItem.id);
        if (!id) return;
        if (!confirm('¿Eliminar este registro?')) return;
        post('riverso_inventory_delete_count_item', { item_id: id }).done(function() { refreshSummary(); });
    });

    $(document).on('click', '#wh-btn-view-summary', function() {
        refreshSummary(function() { renderSummary(false); });
    });
    function doCloseCount() {
        if (!state.count) return;
        post('riverso_inventory_close_count', { id: state.count.id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo terminar el conteo'); return; }
            state.count = r.data.count;
            state.summary = r.data.summary;
            state.editingId = null;
            renderSummary(true);
        });
    }
    function finishCount() {
        if (!state.count) return;
        post('riverso_inventory_preview_close', { id: state.count.id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo revisar el conteo'); return; }
            const data = r.data || {};
            const missing = data.missing || [];
            const diffs = data.diffs || [];
            if (!missing.length && !diffs.length) {
                doCloseCount();
                return;
            }
            $('#wh-close-warn-title').text(missing.length
                ? 'Se va a modificar el stock de este lugar'
                : 'Se va a modificar el stock');
            $('#wh-close-warn-intro').text(missing.length
                ? 'Hay productos que no se escanearon y quedarán en 0. También se aplicarán las cantidades contadas distintas al saldo actual.'
                : 'Las cantidades contadas son distintas al saldo actual. Revisá antes de aceptar.');
            if (missing.length) {
                $('#wh-close-warn-missing').html(missing.map(function(it) {
                    return '<tr><td>' + esc(it.canonical_sku) + '</td><td>' + esc(it.nombre_canonico) +
                        '</td><td>' + esc(it.cantidad_sistema) + '</td><td><strong>0</strong></td></tr>';
                }).join(''));
                $('#wh-close-warn-missing-wrap').show();
            } else {
                $('#wh-close-warn-missing-wrap').hide();
            }
            if (diffs.length) {
                $('#wh-close-warn-diffs').html(diffs.map(function(it) {
                    return '<tr><td>' + esc(it.canonical_sku) + '</td><td>' + esc(it.nombre_canonico) +
                        '</td><td>' + esc(it.cantidad_sistema) + '</td><td><strong>' + esc(it.cantidad_contada) + '</strong></td></tr>';
                }).join(''));
                $('#wh-close-warn-diffs-wrap').show();
            } else {
                $('#wh-close-warn-diffs-wrap').hide();
            }
            $('#wh-close-warn-modal').addClass('open');
        }).fail(function() {
            alert('No se pudo revisar el conteo');
        });
    }
    $(document).on('click', '.wh-btn-finish', finishCount);
    $(document).on('click', '#wh-close-warn-back', function() {
        $('#wh-close-warn-modal').removeClass('open');
    });
    $(document).on('click', '#wh-close-warn-accept', function() {
        $('#wh-close-warn-modal').removeClass('open');
        doCloseCount();
    });

    function abortCount() {
        if (!state.count) return;
        if (!confirm('¿Estás seguro de abortar este conteo? Se eliminará y no quedará registrado.')) return;
        post('riverso_inventory_abort_count', { id: state.count.id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo abortar'); return; }
            state.count = null;
            state.items = [];
            state.lastItem = null;
            state.summary = null;
            state.location = null;
            state.lockedProduct = null;
            state.orderId = null;
            showInvMenu();
        });
    }
    $(document).on('click', '#wh-btn-abort', abortCount);

    function renderSummary(closed) {
        hideInvViews();
        const s = state.summary || { items: [], items_abiertos: [], por_lugar: [] };
        let html = '<button type="button" class="btn btn-secondary btn-sm" id="wh-sum-back">' + (closed ? '← Menú' : '← Seguir escaneando') + '</button>';
        if (!closed && (state.mode === 'producto' || state.mode === 'lugar')) {
            html += ' <button type="button" class="btn btn-danger btn-sm" id="wh-btn-abort">Abortar conteo</button>';
        }
        if (!closed) {
            html += ' <button type="button" class="btn btn-primary btn-sm wh-btn-finish">Terminar conteo</button>';
        }
        html += '<h3>' + (closed ? 'Inventario finalizado' : 'Resumen parcial') + '</h3>';
        html += '<div class="wh-summary">' +
            '<span class="wh-chip"><strong>' + (s.total_items || 0) + '</strong> ítems</span>' +
            '<span class="wh-chip"><strong>' + (s.total_unidades || 0) + '</strong> unidades</span>' +
            '<span class="wh-chip"><strong>' + (s.total_abiertos || 0) + '</strong> abiertas (no suman)</span></div>';
        html += '<h4>Por lugar</h4><table class="wh-table"><thead><tr><th>Lugar</th><th>Ítems</th><th>Unidades</th><th>Abiertas</th></tr></thead><tbody>';
        (s.por_lugar || []).forEach(function(p) {
            html += '<tr><td>' + esc((p.ubicacion_codigo || '—') + ' ' + (p.ubicacion_nombre || '')) + '</td><td>' + p.items + '</td><td>' + p.unidades + '</td><td>' + p.abiertos + '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<h4>Confirmados</h4><table class="wh-table"><thead><tr><th>SKU</th><th>Producto</th><th>Lugar</th><th>Cant.</th></tr></thead><tbody>';
        (s.items || []).forEach(function(it) {
            html += '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.nombre) + '</td><td>' + esc(it.ubicacion_codigo) + '</td><td>' + esc(it.cantidad_contada) + '</td></tr>';
        });
        html += '</tbody></table>';
        if ((s.items_abiertos || []).length) {
            html += '<h4>Abiertas / incompletas (no suman al total)</h4><table class="wh-table"><thead><tr><th>SKU</th><th>Producto</th><th>Lugar</th><th>Cant.</th></tr></thead><tbody>';
            s.items_abiertos.forEach(function(it) {
                html += '<tr><td>' + esc(it.sku) + '</td><td>' + esc(it.nombre) + '</td><td>' + esc(it.ubicacion_codigo) + '</td><td>' + esc(it.cantidad_contada) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        if (!closed) {
            html += '<div style="display:flex;justify-content:flex-end;margin-top:16px;">' +
                '<button type="button" class="btn btn-primary wh-btn-finish">Terminar conteo</button></div>';
        }
        $('#wh-inv-summary').html(html).removeClass('hidden');
        $('#wh-sum-back').on('click', function() {
            if (closed) showInvMenu();
            else { renderScan(); refreshSummary(); }
        });
    }

    function showSortSetup() {
        hideInvViews();
        $('#wh-inv-setup').html(
            '<button type="button" class="btn btn-secondary btn-sm wh-back">← Volver</button>' +
            '<h3>Ordenar producto</h3>' +
            '<div class="wh-field"><label>Buscar producto</label><input type="text" id="wh-sort-q" placeholder="SKU o nombre"><div class="suggest" id="wh-sort-sug"></div></div>' +
            '<div id="wh-sort-info"></div>' +
            '<div class="wh-field"><label>Lugar origen (opcional)</label><select id="wh-sort-from"><option value="">—</option></select></div>' +
            '<div class="wh-field"><label>Cantidad</label><input type="number" id="wh-sort-qty" min="0" step="1" value="1"></div>' +
            '<button type="button" class="btn btn-primary" id="wh-sort-go">Registrar traslado a lugar preferido</button>'
        ).removeClass('hidden');
        fillLocationSelect($('#wh-sort-from'));
        let selected = null;
        $(document).off('input.whsort').on('input.whsort', '#wh-sort-q', function() {
            const q = $(this).val();
            if (q.length < 2) return;
            post('riverso_inventory_search_products', { q }).done(function(r) {
                const list = (r.success && r.data.products) || [];
                $('#wh-sort-sug').html(list.map(p =>
                    '<div data-id="' + p.id + '"><strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico) + '</div>'
                ).join('')).toggle(!!list.length);
            });
        });
        $(document).off('click.whsort').on('click.whsort', '#wh-sort-sug div', function() {
            selected = $(this).data('id');
            $('#wh-sort-q').val($(this).text());
            $('#wh-sort-sug').hide();
            post('riverso_inventory_get_product_locations', { producto_base_id: selected }).done(function(r) {
                if (!r.success) return;
                const pref = (r.data.preferidas || []).find(p => parseInt(p.es_preferido, 10) === 1);
                const actuales = (r.data.actuales || []).map(a => a.codigo).join(', ') || '—';
                $('#wh-sort-info').html(
                    '<p>Preferido: <strong>' + esc(pref ? (pref.codigo + ' · ' + pref.nombre) : 'sin asignar') + '</strong></p>' +
                    '<p>Lugares actuales: ' + esc(actuales) + '</p>'
                );
            });
        });
        $(document).off('click.whsortgo').on('click.whsortgo', '#wh-sort-go', function() {
            if (!selected) { alert('Selecciona un producto'); return; }
            post('riverso_inventory_move_product', {
                producto_base_id: selected,
                ubicacion_origen_id: $('#wh-sort-from').val(),
                cantidad: $('#wh-sort-qty').val()
            }).done(function(r) {
                alert((r.success && r.data && r.data.message) || (r.data && r.data.message) || 'Listo');
                if (r.success) showInvMenu();
            });
        });
    }

    function showOrders() {
        hideInvViews();
        state.draftOrder = { loc: null, prod: null };
        let html = '<button type="button" class="btn btn-secondary btn-sm wh-back">← Volver</button> ';
        if (canOrders) html += '<button type="button" class="btn btn-primary btn-sm" id="wh-ord-new">Nueva orden</button>';
        html += '<h3>Órdenes de inventariar</h3><div id="wh-ord-list">Cargando...</div><div id="wh-ord-form"></div>';
        $('#wh-inv-orders').html(html).removeClass('hidden');
        loadOrders();
    }

    function orderTitle(o) {
        return o.titulo && String(o.titulo).trim() ? o.titulo : ('Orden #' + o.id);
    }
    function orderTargetLabel(o) {
        const it = (o.items && o.items[0]) || {};
        if (o.tipo === 'lugar') {
            return (it.ubicacion_codigo || '—') + (it.ubicacion_nombre ? ' · ' + it.ubicacion_nombre : '');
        }
        if (o.tipo === 'producto') {
            return (it.canonical_sku || '—') + (it.nombre_canonico ? ' · ' + it.nombre_canonico : '');
        }
        return 'General';
    }

    function loadOrders() {
        post('riverso_inventory_list_orders', {}).done(function(r) {
            if (!r.success) { $('#wh-ord-list').text('Error'); return; }
            const rows = r.data.orders || [];
            if (!rows.length) { $('#wh-ord-list').html('<p>No hay órdenes.</p>'); return; }
            let t = '<table class="wh-table"><thead><tr><th>ID</th><th>Título</th><th>Tipo</th><th>Destino</th><th>Estado</th><th></th></tr></thead><tbody>';
            rows.forEach(function(o) {
                const it = (o.items && o.items[0]) || {};
                t += '<tr><td><strong>#' + o.id + '</strong></td><td>' + esc(orderTitle(o)) + '</td><td>' + esc(o.tipo) +
                    '</td><td>' + esc(orderTargetLabel(o)) + '</td><td>' + esc(o.estado) + '</td><td>';
                if (o.estado === 'pendiente' || o.estado === 'en_progreso') {
                    t += '<button class="btn btn-primary btn-sm wh-ord-run" data-id="' + o.id + '" data-tipo="' + esc(o.tipo) + '"' +
                        ' data-loc-id="' + esc(it.ubicacion_id || '') + '" data-loc-code="' + esc(it.ubicacion_codigo || '') +
                        '" data-loc-name="' + esc(it.ubicacion_nombre || '') + '" data-prod-id="' + esc(it.producto_base_id || '') +
                        '" data-prod-sku="' + esc(it.canonical_sku || '') + '" data-prod-name="' + esc(it.nombre_canonico || '') +
                        '">Inventariar</button> ';
                }
                if (canOrders && o.estado !== 'completada' && o.estado !== 'cancelada') {
                    t += '<button class="btn btn-secondary btn-sm wh-ord-cancel" data-id="' + o.id + '">Cancelar</button>';
                }
                t += '</td></tr>';
            });
            $('#wh-ord-list').html(t + '</tbody></table>');
        });
    }

    function renderOrderTargetFields() {
        const tipo = $('#wh-ord-tipo').val();
        $('#wh-ord-loc-wrap').toggleClass('hidden', tipo !== 'lugar');
        $('#wh-ord-prod-wrap').toggleClass('hidden', tipo !== 'producto');
    }
    $(document).on('click', '#wh-ord-new', function() {
        state.draftOrder = { loc: null, prod: null };
        $('#wh-ord-form').html(
            '<div class="scan-box" style="margin-top:12px;"><h4>Nueva orden</h4>' +
            '<div class="wh-field"><label>Título (opcional)</label><input type="text" id="wh-ord-title" placeholder="Si lo dejas vacío se usa Orden #ID"></div>' +
            '<div class="wh-field"><label>Tipo</label><select id="wh-ord-tipo">' +
            '<option value="general">General</option><option value="lugar">Lugar</option><option value="producto">Producto</option></select></div>' +
            '<div class="wh-field" id="wh-ord-loc-wrap">' +
            '<label>Lugar *</label><input type="text" id="wh-ord-loc-q" placeholder="Buscar código, nombre o pistolear barcode">' +
            '<div class="suggest" id="wh-ord-loc-sug"></div>' +
            '<div id="wh-ord-loc-sel" class="meta"></div></div>' +
            '<div class="wh-field hidden" id="wh-ord-prod-wrap">' +
            '<label>Producto *</label><input type="text" id="wh-ord-prod-q" placeholder="Buscar SKU o nombre">' +
            '<div class="suggest" id="wh-ord-prod-sug"></div>' +
            '<div id="wh-ord-prod-sel" class="meta"></div></div>' +
            '<div class="wh-field"><label>Descripción (opcional)</label><textarea id="wh-ord-desc" rows="2" style="width:100%;"></textarea></div>' +
            '<button type="button" class="btn btn-primary" id="wh-ord-save">Guardar</button></div>'
        );
        renderOrderTargetFields();
        $('#wh-ord-title').trigger('focus');
    });
    $(document).on('change', '#wh-ord-tipo', renderOrderTargetFields);
    $(document).on('input', '#wh-ord-loc-q', function() {
        const q = $(this).val();
        if (q.length < 1) { $('#wh-ord-loc-sug').hide(); return; }
        post('riverso_inventory_get_locations', { search: q, activo: 1 }).done(function(r) {
            const list = (r.success && r.data.locations) || [];
            if (!list.length) { $('#wh-ord-loc-sug').hide(); return; }
            $('#wh-ord-loc-sug').html(list.map(function(l) {
                return '<div data-id="' + l.id + '" data-code="' + esc(l.codigo) + '" data-name="' + esc(l.nombre || '') + '">' +
                    '<strong>' + esc(l.codigo) + '</strong> ' + esc(l.nombre || '') + '</div>';
            }).join('')).show();
        });
    });
    $(document).on('keydown', '#wh-ord-loc-q', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = $(this).val().trim();
        if (!code) return;
        post('riverso_inventory_find_location_by_barcode', { barcode: code }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Lugar no encontrado'); return; }
            const loc = r.data.location;
            state.draftOrder.loc = { id: loc.id, codigo: loc.codigo, nombre: loc.nombre || '' };
            $('#wh-ord-loc-sel').text(loc.codigo + ' · ' + (loc.nombre || ''));
            $('#wh-ord-loc-q').val('');
            $('#wh-ord-loc-sug').hide();
        });
    });
    $(document).on('click', '#wh-ord-loc-sug div', function() {
        state.draftOrder.loc = { id: $(this).data('id'), codigo: $(this).data('code'), nombre: $(this).data('name') };
        $('#wh-ord-loc-sel').text(state.draftOrder.loc.codigo + ' · ' + (state.draftOrder.loc.nombre || ''));
        $('#wh-ord-loc-q').val('');
        $('#wh-ord-loc-sug').hide();
    });
    $(document).on('input', '#wh-ord-prod-q', function() {
        const q = $(this).val();
        if (q.length < 2) { $('#wh-ord-prod-sug').hide(); return; }
        post('riverso_inventory_search_products', { q: q, solo_sku_local: 1 }).done(function(r) {
            const list = (r.success && r.data.products) || [];
            if (!list.length) { $('#wh-ord-prod-sug').hide(); return; }
            $('#wh-ord-prod-sug').html(list.map(function(p) {
                return '<div data-id="' + p.id + '" data-sku="' + esc(p.canonical_sku) + '" data-name="' + esc(p.nombre_canonico) + '">' +
                    '<strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico) + '</div>';
            }).join('')).show();
        });
    });
    $(document).on('click', '#wh-ord-prod-sug div', function() {
        state.draftOrder.prod = { id: $(this).data('id'), sku: $(this).data('sku'), nombre: $(this).data('name') };
        $('#wh-ord-prod-sel').text(state.draftOrder.prod.sku + ' · ' + state.draftOrder.prod.nombre);
        $('#wh-ord-prod-q').val('');
        $('#wh-ord-prod-sug').hide();
    });
    $(document).on('click', '#wh-ord-save', function() {
        const tipo = $('#wh-ord-tipo').val();
        if (tipo === 'lugar' && !(state.draftOrder && state.draftOrder.loc)) {
            alert('Selecciona un lugar para la orden');
            return;
        }
        if (tipo === 'producto' && !(state.draftOrder && state.draftOrder.prod)) {
            alert('Selecciona un producto para la orden');
            return;
        }
        const payload = {
            titulo: $('#wh-ord-title').val(),
            tipo: tipo,
            descripcion: $('#wh-ord-desc').val()
        };
        if (tipo === 'lugar') payload.ubicacion_id = state.draftOrder.loc.id;
        if (tipo === 'producto') payload.producto_base_id = state.draftOrder.prod.id;
        post('riverso_inventory_save_order', payload).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.draftOrder = { loc: null, prod: null };
            $('#wh-ord-form').html('');
            loadOrders();
        });
    });
    $(document).on('click', '.wh-ord-cancel', function() {
        post('riverso_inventory_update_order_status', { id: $(this).data('id'), estado: 'cancelada' }).done(loadOrders);
    });
    $(document).on('click', '.wh-ord-run', function() {
        const $btn = $(this);
        state.orderId = $btn.data('id');
        const tipo = $btn.data('tipo') || 'general';
        const locId = $btn.data('loc-id');
        const prodId = $btn.data('prod-id');
        state.orderTarget = {
            location: locId ? { id: locId, codigo: $btn.data('loc-code') || '', nombre: $btn.data('loc-name') || '' } : null,
            product: prodId ? { id: prodId, sku: $btn.data('prod-sku') || '', nombre: $btn.data('prod-name') || '' } : null
        };
        showCountSetup(tipo);
    });

    function sortOrderTitle(o) {
        return o.titulo && String(o.titulo).trim() ? o.titulo : ('Orden #' + o.id);
    }
    function sortOriginLabel(o) {
        if (o.origen_codigo) return o.origen_codigo + (o.origen_nombre ? ' · ' + o.origen_nombre : '');
        return '? (desconocido)';
    }
    function sortDestLabel(item) {
        if (item.destino_codigo) return item.destino_codigo + (item.destino_nombre ? ' · ' + item.destino_nombre : '');
        return '?';
    }
    function sortOrderCanComplete(order) {
        const items = order.items || [];
        if (!items.length) return false;
        return items.every(function(it) {
            if (it.estado === 'omitido' || it.estado === 'completado') return true;
            return !!it.ubicacion_destino_id;
        }) && items.some(function(it) { return it.estado === 'pendiente'; });
    }

    function showSortOrders() {
        hideInvViews();
        state.sortOrder = null;
        let html = '<button type="button" class="btn btn-secondary btn-sm wh-back">← Volver</button> ';
        if (canSortOrders) html += '<button type="button" class="btn btn-primary btn-sm" id="wh-sord-new">Nueva orden</button>';
        html += '<h3>Órdenes de ordenar</h3><div id="wh-sord-list">Cargando...</div>';
        $('#wh-inv-sort-orders').html(html).removeClass('hidden');
        loadSortOrders();
    }

    function loadSortOrders() {
        post('riverso_sort_orders_list', {}).done(function(r) {
            if (!r.success) { $('#wh-sord-list').text('Error'); return; }
            const rows = r.data.orders || [];
            if (!rows.length) { $('#wh-sord-list').html('<p>No hay órdenes de ordenar.</p>'); return; }
            let t = '<table class="wh-table"><thead><tr><th>ID</th><th>Título</th><th>Origen</th><th>Items</th><th>Estado</th><th></th></tr></thead><tbody>';
            rows.forEach(function(o) {
                const canComplete = sortOrderCanComplete(o);
                t += '<tr><td><strong>#' + o.id + '</strong></td><td>' + esc(sortOrderTitle(o)) + '</td><td>' +
                    esc(sortOriginLabel(o)) + '</td><td>' + esc(String(o.items_count || (o.items || []).length)) +
                    '</td><td>' + esc(o.estado) + '</td><td>';
                t += '<button class="btn btn-secondary btn-sm wh-sord-open" data-id="' + o.id + '">Abrir</button> ';
                t += '<button class="btn btn-secondary btn-sm wh-sord-print" data-id="' + o.id + '">Imprimir</button> ';
                if (canSortOrders && canComplete && o.estado !== 'completada' && o.estado !== 'cancelada') {
                    t += '<button class="btn btn-primary btn-sm wh-sord-complete" data-id="' + o.id + '">Completar</button> ';
                }
                if (canSortOrders && o.estado !== 'completada' && o.estado !== 'cancelada') {
                    t += '<button class="btn btn-secondary btn-sm wh-sord-cancel" data-id="' + o.id + '">Cancelar</button>';
                }
                t += '</td></tr>';
            });
            $('#wh-sord-list').html(t + '</tbody></table>');
        });
    }

    function printSortOrder(id) {
        post('riverso_sort_orders_print', { orden_id: id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'No se pudo generar la impresión'); return; }
            const w = window.open('', '_blank');
            if (!w) { alert('Permite ventanas emergentes para imprimir'); return; }
            w.document.write(r.data.html);
            w.document.close();
        });
    }

    function openSortOrder(id) {
        if (!state.locations.length) loadLocations();
        post('riverso_sort_orders_list', { id: id }).done(function(r) {
            if (!r.success || !(r.data.orders && r.data.orders[0])) {
                alert((r.data && r.data.message) || 'Orden no encontrada');
                return;
            }
            state.sortOrder = r.data.orders[0];
            renderSortOrderEditor();
        });
    }

    function renderSortOrderEditor() {
        const o = state.sortOrder;
        if (!o) return;
        const editable = canSortOrders && o.estado !== 'completada' && o.estado !== 'cancelada';
        let html = '<button type="button" class="btn btn-secondary btn-sm" id="wh-sord-back-list">← Lista</button> ';
        html += '<button type="button" class="btn btn-secondary btn-sm wh-sord-print" data-id="' + o.id + '">Imprimir</button> ';
        if (editable && sortOrderCanComplete(o)) {
            html += '<button type="button" class="btn btn-primary btn-sm" id="wh-sord-complete">Completar orden</button> ';
        }
        html += '<h3 style="margin:12px 0;">' + esc(sortOrderTitle(o)) + ' <span class="badge-open">' + esc(o.estado) + '</span></h3>';
        html += '<div class="scan-box"><div class="wh-field"><label>Título (opcional)</label>';
        html += '<input type="text" id="wh-sord-title" value="' + esc(o.titulo || '') + '"' + (editable ? '' : ' disabled') + '></div>';
        html += '<div class="wh-field"><label>Lugar origen</label>';
        html += '<select id="wh-sord-origen"' + (editable ? '' : ' disabled') + '><option value="">Cargando...</option></select>';
        html += '<p class="meta">Usa ? si los productos vienen de un lugar desconocido (recepción).</p></div>';
        html += '<div class="wh-field"><label>Notas</label>';
        html += '<textarea id="wh-sord-notas" rows="2" style="width:100%;"' + (editable ? '' : ' disabled') + '>' +
            esc(o.notas || '') + '</textarea></div>';
        if (editable) {
            html += '<button type="button" class="btn btn-secondary btn-sm" id="wh-sord-save-header">Guardar cabecera</button> ';
            html += '<button type="button" class="btn btn-secondary btn-sm" id="wh-sord-fill-dest">Auto-rellenar destinos</button>';
        }
        html += '</div>';

        if (editable) {
            html += '<div class="scan-box" style="margin-top:12px;"><h4>Agregar producto</h4>';
            html += '<div class="wh-field"><label>Buscar producto</label>';
            html += '<input type="text" id="wh-sord-prod-q" placeholder="SKU o nombre">';
            html += '<div class="suggest" id="wh-sord-prod-sug"></div></div>';
            html += '<div class="qty-row"><label>Cantidad</label>';
            html += '<input type="number" id="wh-sord-prod-qty" min="1" step="1" value="1" class="qty-direct">';
            html += '<button type="button" class="btn btn-primary" id="wh-sord-add-item">Agregar</button></div></div>';
        }

        html += '<div class="scan-box" style="margin-top:12px;"><h4>Productos (' + (o.items || []).length + ')</h4>';
        html += '<table class="wh-table"><thead><tr><th>Producto</th><th>Cant.</th><th>Destino</th><th>Estado</th><th></th></tr></thead>';
        html += '<tbody id="wh-sord-items">';
        (o.items || []).forEach(function(it) {
            html += renderSortItemRow(it, editable);
        });
        if (!(o.items || []).length) {
            html += '<tr><td colspan="5">Sin productos. Agrega ítems para mover.</td></tr>';
        }
        html += '</tbody></table></div>';

        $('#wh-inv-sort-orders').html(html).removeClass('hidden');
        ensureLocations(function() {
            const sel = $('#wh-sord-origen');
            if (!sel.length) return;
            const current = o.ubicacion_origen_id ? String(o.ubicacion_origen_id) : '';
            const opts = ['<option value="">? (desconocido)</option>'].concat(
                state.locations.filter(function(l) { return parseInt(l.activo, 10) === 1; }).map(function(l) {
                    return '<option value="' + l.id + '">' + esc(l.codigo + ' · ' + (l.nombre || '')) + '</option>';
                })
            );
            sel.html(opts.join(''));
            sel.val(current);
        });
    }

    function renderSortItemRow(it, editable) {
        const destOpts = ['<option value="">? (pendiente)</option>'].concat(
            state.locations.filter(function(l) { return parseInt(l.activo, 10) === 1; }).map(function(l) {
                return '<option value="' + l.id + '"' + (String(it.ubicacion_destino_id) === String(l.id) ? ' selected' : '') +
                    '>' + esc(l.codigo + ' · ' + (l.nombre || '')) + '</option>';
            })
        );
        let actions = '';
        if (editable && it.estado === 'pendiente') {
            actions += '<button type="button" class="btn btn-secondary btn-sm wh-sord-item-save" data-id="' + it.id + '">Guardar</button> ';
            actions += '<button type="button" class="btn btn-secondary btn-sm wh-sord-item-omit" data-id="' + it.id + '">Omitir</button> ';
            actions += '<button type="button" class="btn btn-danger btn-sm wh-sord-item-del" data-id="' + it.id + '">Quitar</button>';
        }
        return '<tr data-item-id="' + it.id + '">' +
            '<td><strong>' + esc(it.canonical_sku) + '</strong><br>' + esc(it.nombre_canonico) + '</td>' +
            '<td>' + (editable && it.estado === 'pendiente'
                ? '<input type="number" class="wh-sord-item-qty" data-id="' + it.id + '" min="1" value="' + parseInt(it.cantidad, 10) + '" style="width:70px;">'
                : esc(String(it.cantidad))) + '</td>' +
            '<td>' + (editable && it.estado === 'pendiente'
                ? '<select class="wh-sord-item-dest" data-id="' + it.id + '">' + destOpts.join('') + '</select>'
                : esc(sortDestLabel(it))) + '</td>' +
            '<td>' + esc(it.estado) + '</td>' +
            '<td>' + actions + '</td></tr>';
    }

    function refreshSortOrderEditor() {
        if (!state.sortOrder) return;
        openSortOrder(state.sortOrder.id);
    }

    function ensureLocations(cb) {
        if (state.locations.length) {
            if (cb) cb();
            return;
        }
        post('riverso_inventory_get_locations', { activo: 1 }).done(function(r) {
            if (r.success) state.locations = r.data.locations || [];
            if (cb) cb();
        });
    }

    $(document).on('click', '#wh-sord-new', function() {
        post('riverso_sort_orders_save', { titulo: '', ubicacion_origen_id: '' }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            openSortOrder(r.data.id);
        });
    });
    $(document).on('click', '#wh-sord-back-list', showSortOrders);
    $(document).on('click', '.wh-sord-open', function() { openSortOrder($(this).data('id')); });
    $(document).on('click', '.wh-sord-print', function() { printSortOrder($(this).data('id')); });
    $(document).on('click', '.wh-sord-cancel', function() {
        if (!confirm('¿Cancelar esta orden de ordenar?')) return;
        post('riverso_sort_orders_cancel', { orden_id: $(this).data('id') }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            loadSortOrders();
        });
    });
    $(document).on('click', '.wh-sord-complete, #wh-sord-complete', function() {
        const id = $(this).data('id') || (state.sortOrder && state.sortOrder.id);
        if (!id) return;
        if (!confirm('¿Completar la orden y registrar los movimientos de stock?')) return;
        post('riverso_sort_orders_complete', { orden_id: id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            alert((r.data && r.data.message) || 'Orden completada');
            if (state.sortOrder && String(state.sortOrder.id) === String(id)) {
                state.sortOrder = r.data.order;
                renderSortOrderEditor();
            } else {
                loadSortOrders();
            }
        });
    });
    $(document).on('click', '#wh-sord-save-header', function() {
        if (!state.sortOrder) return;
        post('riverso_sort_orders_save', {
            id: state.sortOrder.id,
            titulo: $('#wh-sord-title').val(),
            notas: $('#wh-sord-notas').val(),
            ubicacion_origen_id: $('#wh-sord-origen').val()
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            renderSortOrderEditor();
        });
    });
    $(document).on('click', '#wh-sord-fill-dest', function() {
        if (!state.sortOrder) return;
        post('riverso_sort_orders_fill_destinations', { orden_id: state.sortOrder.id }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            renderSortOrderEditor();
            if (r.data.pending) {
                alert((r.data.message || 'Listo') + '. Quedan ' + r.data.pending + ' sin lugar preferido.');
            }
        });
    });
    $(document).on('input', '#wh-sord-prod-q', function() {
        const q = $(this).val();
        if (q.length < 2) { $('#wh-sord-prod-sug').hide(); return; }
        post('riverso_inventory_search_products', { q: q }).done(function(r) {
            const list = (r.success && r.data.products) || [];
            if (!list.length) { $('#wh-sord-prod-sug').hide(); return; }
            $('#wh-sord-prod-sug').html(list.map(function(p) {
                return '<div data-id="' + p.id + '" data-sku="' + esc(p.canonical_sku) + '" data-name="' + esc(p.nombre_canonico) + '">' +
                    '<strong>' + esc(p.canonical_sku) + '</strong> ' + esc(p.nombre_canonico) + '</div>';
            }).join('')).show();
        });
    });
    $(document).on('click', '#wh-sord-prod-sug div', function() {
        state.draftSortProduct = {
            id: $(this).data('id'),
            sku: $(this).data('sku'),
            nombre: $(this).data('name')
        };
        $('#wh-sord-prod-q').val(state.draftSortProduct.sku + ' · ' + state.draftSortProduct.nombre);
        $('#wh-sord-prod-sug').hide();
    });
    $(document).on('click', '#wh-sord-add-item', function() {
        if (!state.sortOrder || !state.draftSortProduct) {
            alert('Selecciona un producto');
            return;
        }
        post('riverso_sort_orders_add_item', {
            orden_id: state.sortOrder.id,
            producto_base_id: state.draftSortProduct.id,
            cantidad: $('#wh-sord-prod-qty').val() || 1
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            state.draftSortProduct = null;
            $('#wh-sord-prod-q').val('');
            renderSortOrderEditor();
        });
    });
    $(document).on('click', '.wh-sord-item-save', function() {
        const id = $(this).data('id');
        post('riverso_sort_orders_update_item', {
            id: id,
            cantidad: $('.wh-sord-item-qty[data-id="' + id + '"]').val(),
            ubicacion_destino_id: $('.wh-sord-item-dest[data-id="' + id + '"]').val()
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            renderSortOrderEditor();
        });
    });
    $(document).on('click', '.wh-sord-item-omit', function() {
        post('riverso_sort_orders_update_item', { id: $(this).data('id'), estado: 'omitido' }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            renderSortOrderEditor();
        });
    });
    $(document).on('click', '.wh-sord-item-del', function() {
        if (!confirm('¿Quitar este producto de la orden?')) return;
        post('riverso_sort_orders_remove_item', { id: $(this).data('id') }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            state.sortOrder = r.data.order;
            renderSortOrderEditor();
        });
    });

    function loadHistory() {
        post('riverso_inventory_list_counts', { estado: $('#wh-hist-estado').val(), per_page: 30 }).done(function(r) {
            if (!r.success) return;
            const rows = r.data.counts || [];
            if (!rows.length) { $('#wh-hist-body').html('<tr><td colspan="7">Sin registros</td></tr>'); return; }
            $('#wh-hist-body').html(rows.map(function(c) {
                return '<tr><td>' + c.id + '</td><td>' + esc(c.nombre) + '</td><td>' + esc(c.tipo_conteo) + '</td><td>' +
                    esc(c.estado) + '</td><td>' + esc(c.ubicacion_codigo || '—') + '</td><td>' + esc(c.iniciado_en) +
                    '</td><td><button class="btn btn-secondary btn-sm wh-hist-open" data-id="' + c.id + '">Ver</button></td></tr>';
            }).join(''));
        });
    }

    function invLabelEstadoInv(v) {
        if (v === 'exacto') return '<span class="badge-ok">Exacto</span>';
        if (v === 'al_menos') return '<span class="badge-open">Al menos</span>';
        if (v === 'desconocido') return '<span class="badge-off">Desconocido</span>';
        return esc(v || '');
    }
    function invLabelEstadoConf(v) {
        if (v === 'confiable') return '<span class="badge-ok">Confiable</span>';
        if (v === 'poco_confiable') return '<span class="badge-open">Poco confiable</span>';
        if (v === 'dudoso') return '<span class="badge-off">Dudoso</span>';
        return esc(v || '');
    }

    function loadStockStatus() {
        if (!canStockStatus) return;
        state.stockStatusLoaded = true;
        const payload = {
            search: $('#wh-stock-q').val(),
            estado_inventariado: $('#wh-stock-investado').val(),
            estado_confianza: $('#wh-stock-confestado').val(),
            alerta: $('#wh-stock-alerta').val(),
            page: state.stockStatusPage,
            per_page: state.stockStatusPerPage
        };
        post('riverso_stock_status_list', payload).done(function(r) {
            if (!r.success) { $('#wh-stock-table').html('Error al cargar'); return; }
            const items = r.data.items || [];
            if (!items.length) {
                $('#wh-stock-table').html('<div style="color:var(--text-secondary);padding:10px;">Sin resultados</div>');
                return;
            }
            $('#wh-stock-table').html(
                '<table class="wh-table">' +
                    '<thead><tr>' +
                        '<th>SKU</th><th>Producto</th><th>Stock</th><th>Min</th><th>Critico</th>' +
                        '<th>Inventariado</th><th>Confianza</th><th>Último conteo</th>' +
                        (canEdit ? '<th></th>' : '') +
                    '</tr></thead>' +
                    '<tbody>' +
                    items.map(function(it) {
                        const alertTxt = it.alerta === 1 ? '<span class="badge-open">Alerta</span>' : '';
                        const critTxt = it.critico === 1 ? '<span class="badge-off">Critico</span>' : '';
                        const badgeAlerts = alertTxt + (it.alerta === 1 && it.critico === 1 ? ' ' : '') + critTxt;
                        return '<tr' + (it.critico === 1 ? ' style="background:#ffebee;"' : (it.alerta === 1 ? ' style="background:#fff8e1;"' : '')) + '>' +
                            '<td><strong>' + esc(it.canonical_sku) + '</strong></td>' +
                            '<td>' + esc(it.nombre_canonico) + '</td>' +
                            '<td>' + esc(it.stock_total) + (badgeAlerts ? '<br>' + badgeAlerts : '') + '</td>' +
                            '<td>' + (it.stock_minimo == null ? '—' : esc(it.stock_minimo)) + '</td>' +
                            '<td>' + (it.stock_critico == null ? '—' : esc(it.stock_critico)) + '</td>' +
                            '<td>' + invLabelEstadoInv(it.estado_inventariado) + '</td>' +
                            '<td>' + invLabelEstadoConf(it.estado_confianza) + '</td>' +
                            '<td>' + (it.ultimo_conteo_fecha ? esc(it.ultimo_conteo_fecha) : '—') + '</td>' +
                            (canEdit ? '<td style="text-align:right;"><button type="button" class="btn btn-secondary btn-sm wh-stock-edit" data-id="' + it.id + '" data-sku="' + esc(it.canonical_sku) + '" data-name="' + esc(it.nombre_canonico) + '" data-min="' + (it.stock_minimo == null ? '' : it.stock_minimo) + '" data-crit="' + (it.stock_critico == null ? '' : it.stock_critico) + '">Editar límites</button></td>' : '') +
                            '</tr>';
                    }).join('') +
                    '</tbody></table>'
            );
        });
    }

    $('#wh-stock-reload').on('click', loadStockStatus);

    function parseLimitVal(raw) {
        const s = String(raw == null ? '' : raw).trim();
        if (s === '') return null;
        const n = parseInt(s, 10);
        return isNaN(n) ? null : Math.max(0, n);
    }
    function showStockHint(msg) {
        const $h = $('#wh-stock-modal-hint');
        if (!msg) { $h.hide().text(''); return; }
        $h.text(msg).show();
    }
    function syncStockLimits(changed) {
        const min = parseLimitVal($('#wh-stock-min').val());
        const crit = parseLimitVal($('#wh-stock-crit').val());
        if (min == null && crit == null) { showStockHint(''); return 'minimo'; }
        if (min != null && crit != null && crit > min) {
            if (changed === 'critico') {
                $('#wh-stock-min').val(crit);
                showStockHint('El mínimo se igualó al crítico.');
            } else {
                $('#wh-stock-crit').val(min);
                showStockHint('El crítico se igualó al mínimo.');
            }
        } else {
            showStockHint('');
        }
        return changed;
    }

    let stockLastChanged = 'minimo';
    $(document).on('click', '.wh-stock-edit', function() {
        const $btn = $(this);
        $('#wh-stock-modal-id').val($btn.data('id'));
        $('#wh-stock-modal-prod').text(($btn.data('sku') || '') + ' · ' + ($btn.data('name') || ''));
        $('#wh-stock-min').val($btn.attr('data-min') || '');
        $('#wh-stock-crit').val($btn.attr('data-crit') || '');
        stockLastChanged = 'minimo';
        showStockHint('');
        $('#wh-stock-modal').addClass('open');
        $('#wh-stock-min').trigger('focus');
    });
    $('#wh-stock-min').on('input change', function() {
        stockLastChanged = 'minimo';
        syncStockLimits('minimo');
    });
    $('#wh-stock-crit').on('input change', function() {
        stockLastChanged = 'critico';
        syncStockLimits('critico');
    });
    $('#wh-stock-modal-cancel').on('click', function() { $('#wh-stock-modal').removeClass('open'); });
    $('#wh-stock-modal-save').on('click', function() {
        syncStockLimits(stockLastChanged);
        const min = parseLimitVal($('#wh-stock-min').val());
        const crit = parseLimitVal($('#wh-stock-crit').val());
        post('riverso_stock_status_save_config', {
            producto_base_id: $('#wh-stock-modal-id').val(),
            stock_minimo: min == null ? '' : min,
            stock_critico: crit == null ? '' : crit,
            last_changed: stockLastChanged
        }).done(function(r) {
            if (!r.success) { alert((r.data && r.data.message) || 'Error'); return; }
            $('#wh-stock-modal').removeClass('open');
            loadStockStatus();
        });
    });

    $('#wh-hist-reload, #wh-hist-estado').on('click change', loadHistory);
    $('#wh-hist-body').on('click', '.wh-hist-open', function() {
        post('riverso_inventory_get_count', { id: $(this).data('id') }).done(function(r) {
            if (!r.success) return;
            state.count = r.data.count;
            state.summary = r.data.summary;
            showPanel('inventario');
            renderSummary(r.data.count.estado !== 'abierto');
        });
    });

    function loadMovements() {
        const tbody = $('#wh-mov-body');
        tbody.html('<tr><td colspan="8">Cargando...</td></tr>');
        post('riverso_get_movements', {
            tipo: $('#wh-mov-tipo').val(),
            fecha_desde: $('#wh-mov-desde').val(),
            fecha_hasta: $('#wh-mov-hasta').val(),
            search: $('#wh-mov-q').val(),
            limit: 80
        }).done(function(r) {
            if (!r.success) {
                tbody.html('<tr><td colspan="8">' + esc((r.data && r.data.message) || 'Error') + '</td></tr>');
                return;
            }
            const types = r.data.types || movementTypes || {};
            const items = r.data.movements || [];
            if (!items.length) {
                tbody.html('<tr><td colspan="8">Sin movimientos</td></tr>');
                return;
            }
            tbody.html(items.map(function(m) {
                const type = types[m.tipo] || {};
                const color = type.color || '#546e7a';
                const label = type.label || m.tipo;
                const prod = (m.canonical_sku ? '<code>' + esc(m.canonical_sku) + '</code> ' : '') + esc(m.nombre_canonico || m.product_id);
                const loc = m.ubicacion_destino_codigo || m.ubicacion_origen_codigo || '—';
                const fecha = (m.created_at || '').replace(' ', '<br>');
                return '<tr>' +
                    '<td>' + fecha + '</td>' +
                    '<td><span class="wh-mov-badge" style="background:' + color + '22;color:' + color + '">' + esc(label) + '</span></td>' +
                    '<td>' + prod + '</td>' +
                    '<td>' + parseFloat(m.cantidad || 0).toFixed(0) + '</td>' +
                    '<td>' + parseFloat(m.stock_anterior || 0).toFixed(0) + '</td>' +
                    '<td>' + parseFloat(m.stock_nuevo || 0).toFixed(0) + '</td>' +
                    '<td>' + esc(loc) + '</td>' +
                    '<td>' + esc(m.usuario_nombre || '—') + '</td>' +
                    '</tr>';
            }).join(''));
        }).fail(function() {
            tbody.html('<tr><td colspan="8">Error de conexión</td></tr>');
        });
    }
    let movTimer = null;
    $('#wh-mov-reload, #wh-mov-tipo, #wh-mov-desde, #wh-mov-hasta').on('click change', loadMovements);
    $('#wh-mov-q').on('keyup', function() {
        clearTimeout(movTimer);
        movTimer = setTimeout(loadMovements, 300);
    });

    loadLocations();
    if (whEmbed) loadOpenCounts();
});
</script>
