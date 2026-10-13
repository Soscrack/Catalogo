<?php
/**
 * Clientes y contactos del portal interno.
 */

if (!defined('ABSPATH')) {
    exit;
}

$can_view = current_user_can('riverso_view_customers') || current_user_can('manage_options');
$can_edit = current_user_can('riverso_edit_customers') || current_user_can('manage_options');
$year_now = (int) gmdate('Y');
?>

<?php if (!$can_view): ?>
<div class="content-section">
    <div class="section-body">
        <div class="empty-state">
            <span class="dashicons dashicons-lock"></span>
            <p>No tienes permiso para ver clientes.</p>
        </div>
    </div>
</div>
<?php else: ?>

<style>
    .cust-tabs { display:flex; gap:8px; margin-bottom:16px; }
    .cust-tabs button {
        border:1px solid var(--border); background:#fff; border-radius:999px;
        padding:8px 16px; cursor:pointer; font-size:14px;
    }
    .cust-tabs button.active { background:var(--primary); color:#fff; border-color:var(--primary); }
    .cust-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:16px; margin-bottom:16px; }
    .cust-card h3 { margin:0 0 12px; font-size:14px; letter-spacing:.04em; }
    .cust-grid { display:grid; gap:12px; }
    .cust-grid.cols-2 { grid-template-columns:1fr 1fr; }
    .cust-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }
    .cust-grid label { display:block; font-size:12px; margin-bottom:4px; color:var(--text-secondary); }
    .cust-grid input, .cust-grid select, .cust-grid textarea {
        width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:4px; background:#fff;
    }
    .cust-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:12px; flex-wrap:wrap; }
    .cust-btn { border:none; border-radius:4px; padding:8px 14px; cursor:pointer; color:#fff; font-size:13px; }
    .cust-btn:disabled { opacity:.6; cursor:default; }
    .cust-btn-search, .cust-btn-create { background:#5cb85c; }
    .cust-btn-edit { background:#f0ad4e; }
    .cust-btn-del { background:#d9534f; }
    .cust-btn-detail, .cust-btn-advanced { background:#6f42c1; }
    .cust-btn-ghost { background:var(--bg-light); color:var(--text-primary); border:1px solid var(--border); }
    .cust-table { width:100%; border-collapse:collapse; font-size:13px; }
    .cust-table th { background:#222; color:#fff; text-align:left; padding:8px; white-space:nowrap; }
    .cust-table td { padding:8px; border-bottom:1px solid var(--border); vertical-align:middle; }
    .cust-table .cust-row-actions { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
    .cust-pager { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:10px; flex-wrap:wrap; }
    .cust-check { color:#2e7d32; }
    .cust-off { color:#c62828; font-weight:600; }
    .cust-note { color:var(--text-secondary); font-size:13px; margin:0 0 12px; }
    .cust-advanced { display:none; margin-top:16px; }
    .cust-advanced.open { display:block; }
    .cust-advanced h4 { margin:16px 0 8px; font-size:13px; letter-spacing:.03em; }
    .cust-msg { margin-top:8px; font-size:13px; }
    .cust-msg.ok { color:#1b5e20; }
    .cust-msg.err { color:#b71c1c; }
    .cust-suggest { border:1px solid var(--border); border-radius:4px; margin-top:4px; background:#fff; max-height:180px; overflow:auto; }
    .cust-suggest button { display:block; width:100%; text-align:left; background:#fff; border:none; border-bottom:1px solid var(--border); padding:8px; cursor:pointer; }
    @media (max-width: 900px) {
        .cust-grid.cols-2, .cust-grid.cols-3 { grid-template-columns:1fr; }
    }
</style>

<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">Clientes</h2>
    </div>
    <div class="section-body">
        <div class="cust-tabs" role="tablist">
            <button type="button" class="active" data-cust-tab="clientes">Clientes</button>
            <button type="button" data-cust-tab="contactos">Contactos</button>
        </div>

        <div id="cust-panel-clientes">
            <div class="cust-card">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <input type="search" id="cu-term" placeholder="Buscar por nombre, correo, empresa o teléfono" style="flex:1; min-width:220px; padding:8px 10px; border:1px solid var(--border); border-radius:4px;">
                    <button type="button" class="cust-btn cust-btn-search" id="cu-search">Buscar</button>
                </div>
            </div>
            <div class="cust-card">
                <div class="cust-pager" style="margin-top:0; margin-bottom:8px;">
                    <label>Mostrar
                        <select id="cu-per">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        registros
                    </label>
                    <span id="cu-total"></span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="cust-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Empresa</th>
                                <th>Email</th>
                                <th>Teléfono</th>
                                <th>Contactos</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="cu-body"><tr><td colspan="6">Cargando...</td></tr></tbody>
                    </table>
                </div>
                <div class="cust-pager" id="cu-pager"></div>
            </div>
        </div>

        <div id="cust-panel-contactos" style="display:none;">
            <p class="cust-note">Con Activo marcado se listan solo contactos vigentes. Si uno pasa a inhabilitado, desmarca Activo y elimínalo desde este buscador. No vuelve a activarse solo al facturar o boletear.</p>
            <div class="cust-card">
                <h3>BUSCAR CONTACTO</h3>
                <div class="cust-grid cols-2">
                    <div><label for="ct-nombre">Nombre</label><input id="ct-nombre" type="text"></div>
                    <div><label for="ct-segundo">Segundo Nombre</label><input id="ct-segundo" type="text"></div>
                    <div><label for="ct-paterno">Apellido Paterno</label><input id="ct-paterno" type="text"></div>
                    <div><label for="ct-materno">Apellido Materno</label><input id="ct-materno" type="text"></div>
                    <div><label for="ct-email">Email</label><input id="ct-email" type="text"></div>
                </div>
                <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">
                    <label><input type="checkbox" id="ct-activo" checked> Activo</label>
                    <button type="button" class="cust-btn cust-btn-search" id="ct-search">Buscar</button>
                </div>
            </div>
            <div class="cust-card">
                <h3>RESULTADO BÚSQUEDA</h3>
                <div class="cust-pager" style="margin-top:0; margin-bottom:8px;">
                    <label>Mostrar
                        <select id="ct-per">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        registros
                    </label>
                    <span id="ct-total"></span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="cust-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Activo</th>
                                <th>Entidad</th>
                                <th>Cargo</th>
                                <th>Departamento</th>
                                <th>N° oficina</th>
                                <th>Teléfono móvil</th>
                                <th>Teléfono casa</th>
                                <th>Email</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="ct-body"><tr><td colspan="10">Usa Buscar para listar contactos.</td></tr></tbody>
                    </table>
                </div>
                <div class="cust-pager" id="ct-pager"></div>
            </div>
        </div>
    </div>
</div>

<div id="cust-client-modal" class="portal-modal-overlay">
    <div class="portal-modal large">
        <div class="portal-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px; border-bottom:1px solid var(--border);">
            <h3 id="cust-client-title" style="margin:0;">Contactos</h3>
            <button type="button" class="cust-btn cust-btn-ghost" data-close="cust-client-modal">Cerrar</button>
        </div>
        <div style="padding:16px;">
            <?php if ($can_edit): ?>
            <div style="text-align:right; margin-bottom:10px;">
                <button type="button" class="cust-btn cust-btn-create" id="cust-create">Crear contacto</button>
            </div>
            <?php endif; ?>
            <div style="overflow-x:auto;">
                <table class="cust-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Activo</th>
                            <th>Cargo</th>
                            <th>Departamento</th>
                            <th>Teléfono oficina</th>
                            <th>Móvil</th>
                            <th>Teléfono hogar</th>
                            <th>Email</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="cust-client-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="cust-edit-modal" class="portal-modal-overlay" style="z-index:10020;">
    <div class="portal-modal large">
        <div class="portal-modal-header" style="padding:16px; border-bottom:1px solid var(--border);">
            <h3 id="cust-edit-title" style="margin:0;">Editar contacto</h3>
        </div>
        <div style="padding:16px;">
            <div class="cust-grid cols-2" style="margin-bottom:12px;">
                <div>
                    <label for="f-customer-search">Cliente</label>
                    <input id="f-customer-search" type="search" placeholder="Buscar cliente para asociar" autocomplete="off">
                    <input type="hidden" id="f-customer-id" value="0">
                    <div id="f-customer-label" style="font-size:13px; margin-top:4px;"></div>
                    <div id="f-customer-suggest" class="cust-suggest" style="display:none;"></div>
                </div>
                <div style="align-self:end;">
                    <label><input type="checkbox" id="f-activo" checked> Activo</label>
                    <div class="cust-note" style="margin:4px 0 0;">Si lo dejas inhabilitado, hay que eliminarlo desde el buscador de Contactos para que el correo pueda usarse de nuevo.</div>
                </div>
            </div>
            <div class="cust-grid cols-3">
                <div><label for="f-primer">Primer Nombre</label><input id="f-primer" type="text"></div>
                <div><label for="f-paterno">Apellido Paterno</label><input id="f-paterno" type="text"></div>
                <div><label for="f-oficina">Fono Oficina</label><input id="f-oficina" type="text"></div>
                <div><label for="f-movil">Fono Móvil</label><input id="f-movil" type="text"></div>
                <div><label for="f-email">Email</label><input id="f-email" type="email"></div>
                <div><label for="f-hogar">Teléfono hogar</label><input id="f-hogar" type="text"></div>
                <div><label for="f-segundo">Segundo Nombre</label><input id="f-segundo" type="text"></div>
                <div><label for="f-materno">Apellido Materno</label><input id="f-materno" type="text"></div>
                <div><label for="f-cargo">Cargo</label><input id="f-cargo" type="text"></div>
                <div><label for="f-depto-of">Departamento</label><input id="f-depto-of" type="text"></div>
                <div><label for="f-num-of">Número oficina</label><input id="f-num-of" type="text"></div>
            </div>
            <div style="margin-top:12px;">
                <label for="f-obs">Observaciones</label>
                <textarea id="f-obs" rows="3" style="width:100%; padding:8px; border:1px solid var(--border); border-radius:4px;"></textarea>
            </div>
            <div id="cust-advanced" class="cust-advanced">
                <h4>FECHA DE NACIMIENTO</h4>
                <div class="cust-grid cols-3">
                    <div>
                        <label for="f-dia">Día</label>
                        <select id="f-dia"><option value="0">—</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?php echo $d; ?>"><?php echo $d; ?></option><?php endfor; ?></select>
                    </div>
                    <div>
                        <label for="f-mes">Mes</label>
                        <select id="f-mes"><option value="0">—</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?php echo $m; ?>"><?php echo $m; ?></option><?php endfor; ?></select>
                    </div>
                    <div>
                        <label for="f-anio">Año</label>
                        <select id="f-anio"><option value="0">—</option><?php for ($y = $year_now; $y >= 1900; $y--): ?><option value="<?php echo $y; ?>"><?php echo $y; ?></option><?php endfor; ?></select>
                    </div>
                </div>
                <h4>DIRECCIÓN LABORAL</h4>
                <div class="cust-grid cols-3">
                    <div><label for="f-lab-calle">Calle</label><input id="f-lab-calle" type="text"></div>
                    <div><label for="f-lab-numero">Número</label><input id="f-lab-numero" type="text"></div>
                    <div><label for="f-lab-depto">Depto / Oficina</label><input id="f-lab-depto" type="text"></div>
                    <div><label for="f-lab-ciudad">Ciudad</label><input id="f-lab-ciudad" type="text"></div>
                    <div><label for="f-lab-comuna">Comuna</label><input id="f-lab-comuna" type="text"></div>
                    <div><label for="f-lab-pais">País</label><input id="f-lab-pais" type="text"></div>
                    <div><label for="f-lab-cp">Código Postal</label><input id="f-lab-cp" type="text"></div>
                </div>
                <h4>DIRECCIÓN PARTICULAR</h4>
                <div class="cust-grid cols-3">
                    <div><label for="f-part-calle">Calle</label><input id="f-part-calle" type="text"></div>
                    <div><label for="f-part-numero">Número</label><input id="f-part-numero" type="text"></div>
                    <div><label for="f-part-depto">Depto / Oficina</label><input id="f-part-depto" type="text"></div>
                    <div><label for="f-part-ciudad">Ciudad</label><input id="f-part-ciudad" type="text"></div>
                    <div><label for="f-part-comuna">Comuna</label><input id="f-part-comuna" type="text"></div>
                    <div><label for="f-part-pais">País</label><input id="f-part-pais" type="text"></div>
                    <div><label for="f-part-cp">Código Postal</label><input id="f-part-cp" type="text"></div>
                </div>
            </div>
            <div id="cust-edit-msg" class="cust-msg"></div>
            <div class="cust-actions">
                <button type="button" class="cust-btn cust-btn-advanced" id="cust-toggle-advanced">Mostrar opciones avanzadas</button>
                <button type="button" class="cust-btn cust-btn-ghost" data-close="cust-edit-modal">Cancelar</button>
                <?php if ($can_edit): ?>
                <button type="button" class="cust-btn cust-btn-edit" id="cust-save">Editar contacto</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="cust-detail-modal" class="portal-modal-overlay" style="z-index:10030;">
    <div class="portal-modal large">
        <div class="portal-modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px; border-bottom:1px solid var(--border);">
            <h3 style="margin:0;">Detalles del contacto</h3>
            <button type="button" class="cust-btn cust-btn-ghost" data-close="cust-detail-modal">Cerrar</button>
        </div>
        <div id="cust-detail-body" style="padding:16px;"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const canEdit = <?php echo $can_edit ? 'true' : 'false'; ?>;
    let cuPage = 1;
    let ctPage = 1;
    let clientCustomer = null;
    let editingId = 0;
    let suggestTimer = null;

    const esc = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    function post(action, data) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', riversoNonce);
        Object.keys(data || {}).forEach((key) => {
            const value = data[key];
            body.set(key, value == null ? '' : String(value));
        });
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body }).then((res) => res.json());
    }

    function showTab(name) {
        document.querySelectorAll('[data-cust-tab]').forEach((btn) => {
            btn.classList.toggle('active', btn.getAttribute('data-cust-tab') === name);
        });
        document.getElementById('cust-panel-clientes').style.display = name === 'clientes' ? '' : 'none';
        document.getElementById('cust-panel-contactos').style.display = name === 'contactos' ? '' : 'none';
    }

    document.querySelectorAll('[data-cust-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const name = btn.getAttribute('data-cust-tab');
            showTab(name);
            if (name === 'contactos') loadContacts();
            if (name === 'clientes') loadCustomers();
        });
    });

    function activoCell(on) {
        return on
            ? '<span class="cust-check" title="Activo">&#10003;</span>'
            : '<span class="cust-off">Inhabilitado</span>';
    }

    function actionButtons(row, scope) {
        let html = '';
        if (canEdit) {
            html += '<button type="button" class="cust-btn cust-btn-edit" data-edit="' + row.id + '">Editar</button>';
        }
        html += '<button type="button" class="cust-btn cust-btn-detail" data-detail="' + row.id + '">Detalles</button>';
        if (canEdit) {
            html += '<button type="button" class="cust-btn cust-btn-del" data-del="' + row.id + '">Eliminar</button>';
        }
        return '<div class="cust-row-actions">' + html + '</div>';
    }

    function pager(el, total, page, per, go) {
        const pages = Math.max(1, Math.ceil(total / per));
        const prev = page > 1 ? '<button type="button" class="cust-btn cust-btn-ghost" data-go="' + (page - 1) + '">Anterior</button>' : '';
        const next = page < pages ? '<button type="button" class="cust-btn cust-btn-ghost" data-go="' + (page + 1) + '">Siguiente</button>' : '';
        el.innerHTML = prev + '<span>' + page + ' / ' + pages + '</span>' + next;
        el.querySelectorAll('[data-go]').forEach((btn) => {
            btn.addEventListener('click', () => go(parseInt(btn.getAttribute('data-go'), 10)));
        });
    }

    function loadCustomers() {
        const body = document.getElementById('cu-body');
        body.innerHTML = '<tr><td colspan="6">Cargando...</td></tr>';
        post('riverso_search_customer_directory', {
            term: document.getElementById('cu-term').value.trim(),
            page: cuPage,
            per_page: document.getElementById('cu-per').value
        }).then((res) => {
            if (!res.success) {
                body.innerHTML = '<tr><td colspan="6">' + esc((res.data && res.data.message) || 'No se pudo cargar') + '</td></tr>';
                return;
            }
            const data = res.data;
            document.getElementById('cu-total').textContent = data.total + ' clientes';
            if (!data.rows.length) {
                body.innerHTML = '<tr><td colspan="6">Sin clientes para esta búsqueda.</td></tr>';
            } else {
                body.innerHTML = data.rows.map((row) => (
                    '<tr><td>' + esc(row.display_name) + '</td><td>' + esc(row.company || '—') + '</td><td>' + esc(row.email || '—') +
                    '</td><td>' + esc(row.phone || '—') + '</td><td>' + esc(row.contact_count) +
                    '</td><td><button type="button" class="cust-btn cust-btn-detail" data-client="' + row.id + '" data-client-name="' + esc(row.company || row.display_name) + '">Contactos</button></td></tr>'
                )).join('');
            }
            pager(document.getElementById('cu-pager'), data.total, data.page, data.per_page, (page) => {
                cuPage = page;
                loadCustomers();
            });
        }).catch(() => {
            body.innerHTML = '<tr><td colspan="6">Error de conexión.</td></tr>';
        });
    }

    function loadContacts() {
        const body = document.getElementById('ct-body');
        body.dataset.loaded = '1';
        body.innerHTML = '<tr><td colspan="10">Cargando...</td></tr>';
        post('riverso_search_contacts', {
            nombre: document.getElementById('ct-nombre').value.trim(),
            segundo_nombre: document.getElementById('ct-segundo').value.trim(),
            apellido_paterno: document.getElementById('ct-paterno').value.trim(),
            apellido_materno: document.getElementById('ct-materno').value.trim(),
            email: document.getElementById('ct-email').value.trim(),
            activo: document.getElementById('ct-activo').checked ? 1 : 0,
            page: ctPage,
            per_page: document.getElementById('ct-per').value
        }).then((res) => {
            if (!res.success) {
                body.innerHTML = '<tr><td colspan="10">' + esc((res.data && res.data.message) || 'No se pudo buscar') + '</td></tr>';
                return;
            }
            const data = res.data;
            document.getElementById('ct-total').textContent = data.total + ' contactos';
            if (!data.rows.length) {
                body.innerHTML = '<tr><td colspan="10">Sin contactos. Si buscas uno inhabilitado, desmarca Activo.</td></tr>';
            } else {
                body.innerHTML = data.rows.map((row) => (
                    '<tr><td>' + esc(row.nombre) + '</td><td>' + activoCell(row.activo) + '</td><td>' + esc(row.entidad) +
                    '</td><td>' + esc(row.cargo || '—') + '</td><td>' + esc(row.departamento || '—') +
                    '</td><td>' + esc(row.numero_oficina || '—') + '</td><td>' + esc(row.fono_movil || '—') +
                    '</td><td>' + esc(row.fono_hogar || '—') + '</td><td>' + esc(row.email || '—') +
                    '</td><td>' + actionButtons(row, 'search') + '</td></tr>'
                )).join('');
            }
            pager(document.getElementById('ct-pager'), data.total, data.page, data.per_page, (page) => {
                ctPage = page;
                loadContacts();
            });
        }).catch(() => {
            body.innerHTML = '<tr><td colspan="10">Error de conexión.</td></tr>';
        });
    }

    function loadClientContacts() {
        if (!clientCustomer) return;
        const body = document.getElementById('cust-client-body');
        body.innerHTML = '<tr><td colspan="9">Cargando...</td></tr>';
        post('riverso_customer_contacts', { customer_id: clientCustomer.id }).then((res) => {
            if (!res.success) {
                body.innerHTML = '<tr><td colspan="9">' + esc((res.data && res.data.message) || 'No se pudo cargar') + '</td></tr>';
                return;
            }
            const rows = res.data.rows || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="9">Este cliente no tiene contactos.</td></tr>';
                return;
            }
            body.innerHTML = rows.map((row) => (
                '<tr><td>' + esc(row.nombre) + '</td><td>' + activoCell(row.activo) + '</td><td>' + esc(row.cargo || '—') +
                '</td><td>' + esc(row.departamento || '—') + '</td><td>' + esc(row.fono_oficina || '—') +
                '</td><td>' + esc(row.fono_movil || '—') + '</td><td>' + esc(row.fono_hogar || '—') +
                '</td><td>' + esc(row.email || '—') + '</td><td>' + actionButtons(row, 'client') + '</td></tr>'
            )).join('');
        });
    }

    function openModal(id) {
        document.getElementById(id).classList.add('open');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    document.querySelectorAll('[data-close]').forEach((btn) => {
        btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close')));
    });

    function setField(id, value) {
        const el = document.getElementById(id);
        if (el) el.value = value == null ? '' : value;
    }

    function emptyForm() {
        ['f-primer','f-segundo','f-paterno','f-materno','f-email','f-oficina','f-movil','f-hogar','f-cargo','f-depto-of','f-num-of','f-obs',
         'f-lab-calle','f-lab-numero','f-lab-depto','f-lab-ciudad','f-lab-comuna','f-lab-pais','f-lab-cp',
         'f-part-calle','f-part-numero','f-part-depto','f-part-ciudad','f-part-comuna','f-part-pais','f-part-cp'
        ].forEach((id) => setField(id, ''));
        setField('f-dia', '0');
        setField('f-mes', '0');
        setField('f-anio', '0');
        document.getElementById('f-activo').checked = true;
        document.getElementById('cust-advanced').classList.remove('open');
        document.getElementById('cust-toggle-advanced').textContent = 'Mostrar opciones avanzadas';
        document.getElementById('cust-edit-msg').textContent = '';
    }

    function fillForm(contact) {
        emptyForm();
        const map = {
            primer_nombre: 'f-primer', segundo_nombre: 'f-segundo', apellido_paterno: 'f-paterno', apellido_materno: 'f-materno',
            email: 'f-email', fono_oficina: 'f-oficina', fono_movil: 'f-movil', fono_hogar: 'f-hogar',
            cargo: 'f-cargo', departamento: 'f-depto-of', numero_oficina: 'f-num-of', observaciones: 'f-obs',
            fecha_nac_dia: 'f-dia', fecha_nac_mes: 'f-mes', fecha_nac_anio: 'f-anio',
            lab_calle: 'f-lab-calle', lab_numero: 'f-lab-numero', lab_depto: 'f-lab-depto', lab_ciudad: 'f-lab-ciudad',
            lab_comuna: 'f-lab-comuna', lab_pais: 'f-lab-pais', lab_codigo_postal: 'f-lab-cp',
            part_calle: 'f-part-calle', part_numero: 'f-part-numero', part_depto: 'f-part-depto', part_ciudad: 'f-part-ciudad',
            part_comuna: 'f-part-comuna', part_pais: 'f-part-pais', part_codigo_postal: 'f-part-cp'
        };
        Object.keys(map).forEach((key) => setField(map[key], contact[key] || (key.indexOf('fecha_') === 0 ? 0 : '')));
        document.getElementById('f-activo').checked = !!contact.activo;
        setCustomer(contact.customer_id, contact.entidad);
    }

    function setCustomer(id, label) {
        document.getElementById('f-customer-id').value = id || 0;
        document.getElementById('f-customer-label').textContent = label && id ? label : 'Sin cliente';
        document.getElementById('f-customer-search').value = '';
        document.getElementById('f-customer-suggest').style.display = 'none';
    }

    function openEdit(id) {
        editingId = id || 0;
        const title = document.getElementById('cust-edit-title');
        const save = document.getElementById('cust-save');
        if (!id) {
            emptyForm();
            if (clientCustomer) setCustomer(clientCustomer.id, clientCustomer.name);
            else setCustomer(0, '');
            title.textContent = 'Nuevo contacto' + (clientCustomer ? ' de ' + clientCustomer.name : '');
            if (save) save.textContent = 'Crear contacto';
            openModal('cust-edit-modal');
            return;
        }
        post('riverso_get_contact', { id: id }).then((res) => {
            if (!res.success) {
                alert((res.data && res.data.message) || 'No se pudo abrir el contacto');
                return;
            }
            fillForm(res.data);
            title.textContent = 'Editar contacto de ' + (res.data.entidad || '');
            if (save) save.textContent = 'Editar contacto';
            openModal('cust-edit-modal');
        });
    }

    function formPayload() {
        const val = (id) => document.getElementById(id).value.trim();
        return {
            id: editingId,
            customer_id: document.getElementById('f-customer-id').value,
            primer_nombre: val('f-primer'),
            segundo_nombre: val('f-segundo'),
            apellido_paterno: val('f-paterno'),
            apellido_materno: val('f-materno'),
            email: val('f-email'),
            fono_oficina: val('f-oficina'),
            fono_movil: val('f-movil'),
            fono_hogar: val('f-hogar'),
            cargo: val('f-cargo'),
            departamento: val('f-depto-of'),
            numero_oficina: val('f-num-of'),
            observaciones: val('f-obs'),
            activo: document.getElementById('f-activo').checked ? 1 : 0,
            fecha_nac_dia: document.getElementById('f-dia').value,
            fecha_nac_mes: document.getElementById('f-mes').value,
            fecha_nac_anio: document.getElementById('f-anio').value,
            lab_calle: val('f-lab-calle'), lab_numero: val('f-lab-numero'), lab_depto: val('f-lab-depto'),
            lab_ciudad: val('f-lab-ciudad'), lab_comuna: val('f-lab-comuna'), lab_pais: val('f-lab-pais'),
            lab_codigo_postal: val('f-lab-cp'),
            part_calle: val('f-part-calle'), part_numero: val('f-part-numero'), part_depto: val('f-part-depto'),
            part_ciudad: val('f-part-ciudad'), part_comuna: val('f-part-comuna'), part_pais: val('f-part-pais'),
            part_codigo_postal: val('f-part-cp'),
            origen: 'manual'
        };
    }

    function detailLine(label, value) {
        return '<div style="margin-bottom:6px;"><strong>' + esc(label) + ':</strong> ' + esc(value || '—') + '</div>';
    }

    function openDetails(id) {
        post('riverso_get_contact', { id: id }).then((res) => {
            if (!res.success) {
                alert((res.data && res.data.message) || 'No se pudo abrir el detalle');
                return;
            }
            const c = res.data;
            const nac = (c.fecha_nac_dia && c.fecha_nac_mes && c.fecha_nac_anio)
                ? (c.fecha_nac_dia + '/' + c.fecha_nac_mes + '/' + c.fecha_nac_anio) : '';
            const origen = { manual: 'Manual', boleta: 'Boleta', factura: 'Factura', venta: 'Venta' }[c.origen] || c.origen;
            document.getElementById('cust-detail-body').innerHTML =
                detailLine('Nombre', c.nombre) +
                detailLine('Cliente', c.entidad) +
                detailLine('Estado', c.activo ? 'Activo' : 'Inhabilitado') +
                detailLine('Origen', origen) +
                detailLine('Email', c.email) +
                detailLine('Fono oficina', c.fono_oficina) +
                detailLine('Fono móvil', c.fono_movil) +
                detailLine('Teléfono hogar', c.fono_hogar) +
                detailLine('Cargo', c.cargo) +
                detailLine('Departamento', c.departamento) +
                detailLine('Nacimiento', nac) +
                detailLine('Dirección laboral', [c.lab_calle, c.lab_numero, c.lab_depto, c.lab_comuna, c.lab_ciudad, c.lab_pais, c.lab_codigo_postal].filter(Boolean).join(', ')) +
                detailLine('Dirección particular', [c.part_calle, c.part_numero, c.part_depto, c.part_comuna, c.part_ciudad, c.part_pais, c.part_codigo_postal].filter(Boolean).join(', ')) +
                detailLine('Observaciones', c.observaciones);
            openModal('cust-detail-modal');
        });
    }

    function removeContact(id) {
        if (!confirm('¿Eliminar este contacto del buscador? No se puede deshacer. Si estaba inhabilitado, el correo podrá guardarse de nuevo al facturar o boletear.')) {
            return;
        }
        post('riverso_delete_contact', { id: id }).then((res) => {
            if (!res.success) {
                alert((res.data && res.data.message) || 'No se pudo eliminar');
                return;
            }
            loadContacts();
            loadCustomers();
            if (clientCustomer) loadClientContacts();
        });
    }

    document.body.addEventListener('click', (event) => {
        const clientBtn = event.target.closest('[data-client]');
        if (clientBtn) {
            clientCustomer = {
                id: parseInt(clientBtn.getAttribute('data-client'), 10),
                name: clientBtn.getAttribute('data-client-name') || 'Cliente'
            };
            document.getElementById('cust-client-title').textContent = 'Contactos de ' + clientCustomer.name;
            openModal('cust-client-modal');
            loadClientContacts();
            return;
        }
        const editBtn = event.target.closest('[data-edit]');
        if (editBtn) {
            openEdit(parseInt(editBtn.getAttribute('data-edit'), 10));
            return;
        }
        const detailBtn = event.target.closest('[data-detail]');
        if (detailBtn) {
            openDetails(parseInt(detailBtn.getAttribute('data-detail'), 10));
            return;
        }
        const delBtn = event.target.closest('[data-del]');
        if (delBtn) {
            removeContact(parseInt(delBtn.getAttribute('data-del'), 10));
        }
    });

    const createBtn = document.getElementById('cust-create');
    if (createBtn) createBtn.addEventListener('click', () => openEdit(0));

    document.getElementById('cust-toggle-advanced').addEventListener('click', () => {
        const box = document.getElementById('cust-advanced');
        const open = box.classList.toggle('open');
        document.getElementById('cust-toggle-advanced').textContent = open ? 'Ocultar opciones avanzadas' : 'Mostrar opciones avanzadas';
    });

    const saveBtn = document.getElementById('cust-save');
    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            const msg = document.getElementById('cust-edit-msg');
            msg.className = 'cust-msg';
            msg.textContent = 'Guardando...';
            saveBtn.disabled = true;
            post('riverso_save_contact', formPayload()).then((res) => {
                saveBtn.disabled = false;
                if (!res.success) {
                    msg.className = 'cust-msg err';
                    msg.textContent = (res.data && res.data.message) || 'No se pudo guardar';
                    return;
                }
                msg.className = 'cust-msg ok';
                msg.textContent = res.data.message || 'Contacto guardado.';
                if (res.data.contact && !res.data.contact.activo) {
                    document.getElementById('ct-activo').checked = false;
                    ctPage = 1;
                    showTab('contactos');
                }
                loadContacts();
                loadCustomers();
                if (clientCustomer) loadClientContacts();
            }).catch(() => {
                saveBtn.disabled = false;
                msg.className = 'cust-msg err';
                msg.textContent = 'Error de conexión.';
            });
        });
    }

    document.getElementById('f-customer-search').addEventListener('input', () => {
        clearTimeout(suggestTimer);
        const term = document.getElementById('f-customer-search').value.trim();
        const box = document.getElementById('f-customer-suggest');
        if (term.length < 2) {
            box.style.display = 'none';
            return;
        }
        suggestTimer = setTimeout(() => {
            post('riverso_search_customer_directory', { term: term, page: 1, per_page: 10 }).then((res) => {
                if (!res.success || !res.data.rows.length) {
                    box.style.display = 'none';
                    return;
                }
                box.innerHTML = res.data.rows.map((row) => (
                    '<button type="button" data-pick="' + row.id + '" data-pick-name="' + esc(row.company || row.display_name) + '">' +
                    esc(row.display_name) + (row.email ? ' · ' + esc(row.email) : '') + '</button>'
                )).join('');
                box.style.display = 'block';
            });
        }, 250);
    });

    document.getElementById('f-customer-suggest').addEventListener('click', (event) => {
        const btn = event.target.closest('[data-pick]');
        if (!btn) return;
        setCustomer(parseInt(btn.getAttribute('data-pick'), 10), btn.getAttribute('data-pick-name'));
    });

    document.getElementById('cu-search').addEventListener('click', () => { cuPage = 1; loadCustomers(); });
    document.getElementById('cu-term').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { cuPage = 1; loadCustomers(); }
    });
    document.getElementById('cu-per').addEventListener('change', () => { cuPage = 1; loadCustomers(); });
    document.getElementById('ct-search').addEventListener('click', () => { ctPage = 1; loadContacts(); });
    ['ct-nombre','ct-segundo','ct-paterno','ct-materno','ct-email'].forEach((id) => {
        document.getElementById(id).addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { ctPage = 1; loadContacts(); }
        });
    });
    document.getElementById('ct-per').addEventListener('change', () => { ctPage = 1; loadContacts(); });

    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'contactos') {
        showTab('contactos');
        loadContacts();
    } else {
        showTab('clientes');
    }
    loadCustomers();
});
</script>
<?php endif; ?>
