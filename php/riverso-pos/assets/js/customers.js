(function () {
  'use strict';

  var cfg = window.RIVERSO_CUSTOMERS || {};
  var root = document.getElementById('riverso-customers');
  if (!root) return;

  var state = {
    page: 1,
    perPage: 25,
    search: '',
    status: 'active',
    loading: false,
  };

  var els = {
    listView: document.getElementById('cust-list-view'),
    formView: document.getElementById('cust-form-view'),
    tbody: document.getElementById('cust-tbody'),
    search: document.getElementById('cust-filter-search'),
    status: document.getElementById('cust-filter-status'),
    apply: document.getElementById('cust-apply-filters'),
    clear: document.getElementById('cust-clear-filters'),
    newBtn: document.getElementById('cust-new'),
    back: document.getElementById('cust-back-list'),
    cancel: document.getElementById('cust-cancel'),
    form: document.getElementById('cust-form'),
    formTitle: document.getElementById('cust-form-title'),
    cardTitle: document.getElementById('cust-card-general-title'),
    formMsg: document.getElementById('cust-form-msg'),
    saveBtn: document.getElementById('cust-save'),
    pagination: document.getElementById('cust-pagination'),
    paginationInfo: document.getElementById('cust-pagination-info'),
    paginationButtons: document.getElementById('cust-pagination-buttons'),
    id: document.getElementById('cust-id'),
    nombreFantasia: document.getElementById('cust-nombre-fantasia'),
    hasContacto: document.getElementById('cust-has-contacto'),
    sectionContacto: document.getElementById('cust-section-contacto'),
    primerNombre: document.getElementById('cust-primer-nombre'),
    apellidoPaterno: document.getElementById('cust-apellido-paterno'),
    contactoTelefono: document.getElementById('cust-contacto-telefono'),
    contactoEmail: document.getElementById('cust-contacto-email'),
    hasFacturacion: document.getElementById('cust-has-facturacion'),
    sectionFacturacion: document.getElementById('cust-section-facturacion'),
    pais: document.getElementById('cust-pais'),
    tipoId: document.getElementById('cust-tipo-id'),
    rut: document.getElementById('cust-rut'),
    razonSocial: document.getElementById('cust-razon-social'),
    direccion: document.getElementById('cust-direccion'),
    comuna: document.getElementById('cust-comuna'),
    ciudad: document.getElementById('cust-ciudad'),
    giro: document.getElementById('cust-giro'),
    facturacionTelefono: document.getElementById('cust-facturacion-telefono'),
    codigoPostal: document.getElementById('cust-codigo-postal'),
    hasDatosExtra: document.getElementById('cust-has-datos-extra'),
    sectionExtra: document.getElementById('cust-section-extra'),
    activo: document.getElementById('cust-activo'),
  };

  function canEdit() {
    return !!(cfg.caps && cfg.caps.edit);
  }

  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce || '');
    Object.keys(data || {}).forEach(function (key) {
      var val = data[key];
      if (val === undefined || val === null) return;
      if (typeof val === 'boolean') {
        body.append(key, val ? '1' : '0');
      } else if (typeof val === 'object') {
        body.append(key, JSON.stringify(val));
      } else {
        body.append(key, String(val));
      }
    });
    return fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    }).then(function (res) {
      return res.json();
    });
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showList() {
    els.listView.hidden = false;
    els.formView.hidden = true;
  }

  function showForm() {
    els.listView.hidden = true;
    els.formView.hidden = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function setFormMsg(text, ok) {
    if (!els.formMsg) return;
    if (!text) {
      els.formMsg.hidden = true;
      els.formMsg.textContent = '';
      els.formMsg.className = 'cust-form-msg';
      return;
    }
    els.formMsg.hidden = false;
    els.formMsg.textContent = text;
    els.formMsg.className = 'cust-form-msg ' + (ok ? 'is-ok' : 'is-error');
  }

  function syncSections() {
    toggleSection(els.hasContacto, els.sectionContacto);
    toggleSection(els.hasFacturacion, els.sectionFacturacion);
    toggleSection(els.hasDatosExtra, els.sectionExtra);
  }

  function toggleSection(checkbox, section) {
    if (!checkbox || !section) return;
    if (checkbox.checked) {
      section.classList.remove('is-collapsed');
    } else {
      section.classList.add('is-collapsed');
    }
  }

  function collectDatosExtra() {
    var names = root.querySelectorAll('.cust-extra-nombre');
    var values = root.querySelectorAll('.cust-extra-valor');
    var out = [];
    for (var i = 0; i < 4; i++) {
      var nombre = names[i] ? String(names[i].value || '').trim() : '';
      var valor = values[i] ? String(values[i].value || '').trim() : '';
      out.push({ nombre: nombre, valor: valor });
    }
    return out;
  }

  function fillDatosExtra(items) {
    var names = root.querySelectorAll('.cust-extra-nombre');
    var values = root.querySelectorAll('.cust-extra-valor');
    for (var i = 0; i < 4; i++) {
      var item = items && items[i] ? items[i] : {};
      if (names[i]) names[i].value = item.nombre || '';
      if (values[i]) values[i].value = item.valor || '';
    }
  }

  function resetForm() {
    els.form.reset();
    els.id.value = '0';
    els.hasContacto.checked = true;
    els.hasFacturacion.checked = true;
    els.hasDatosExtra.checked = true;
    els.activo.checked = true;
    els.pais.value = 'CHILE';
    els.tipoId.value = 'RUT_CLIENTE';
    els.codigoPostal.value = '0';
    els.comuna.value = '';
    fillDatosExtra([]);
    syncSections();
    setFormMsg('');
    setFormMode('create');
    var openWrap = document.getElementById('cust-open-contacts-wrap');
    if (openWrap) openWrap.hidden = true;
  }

  function setFormMode(mode) {
    var title = mode === 'edit' ? 'Modificar cliente' : 'Crear cliente';
    els.formTitle.textContent = title;
    els.cardTitle.textContent = title;
  }

  function fillForm(customer) {
    resetForm();
    if (!customer) return;
    setFormMode('edit');
    els.id.value = String(customer.id || 0);
    var openWrap = document.getElementById('cust-open-contacts-wrap');
    if (openWrap) openWrap.hidden = !(parseInt(els.id.value, 10) > 0);
    els.nombreFantasia.value = customer.nombre_fantasia || '';
    els.hasContacto.checked = customer.has_contacto !== false;
    els.primerNombre.value = customer.primer_nombre || '';
    els.apellidoPaterno.value = customer.apellido_paterno || '';
    els.contactoTelefono.value = customer.contacto_telefono || '';
    els.contactoEmail.value = customer.contacto_email || '';
    els.hasFacturacion.checked = customer.has_facturacion !== false;
    els.pais.value = customer.pais || 'CHILE';
    els.tipoId.value = customer.tipo_identificacion || 'RUT_CLIENTE';
    els.rut.value = customer.rut || '';
    els.razonSocial.value = customer.razon_social || '';
    els.direccion.value = customer.direccion || '';
    els.comuna.value = customer.comuna || '';
    els.ciudad.value = customer.ciudad || '';
    els.giro.value = customer.giro || '';
    els.facturacionTelefono.value = customer.facturacion_telefono || '';
    els.codigoPostal.value = customer.codigo_postal != null && customer.codigo_postal !== '' ? customer.codigo_postal : '0';
    els.hasDatosExtra.checked = customer.has_datos_extra !== false;
    fillDatosExtra(customer.datos_extra || []);
    els.activo.checked = customer.activo !== false;
    syncSections();
  }

  function validateClient() {
    if (!String(els.nombreFantasia.value || '').trim()) {
      return 'El nombre de fantasía es obligatorio';
    }
    if (els.hasContacto.checked) {
      if (!String(els.primerNombre.value || '').trim()) {
        return 'El primer nombre del contacto es obligatorio';
      }
      if (!String(els.apellidoPaterno.value || '').trim()) {
        return 'El apellido paterno del contacto es obligatorio';
      }
    }
    return '';
  }

  function loadList() {
    if (state.loading) return;
    state.loading = true;
    els.tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">Cargando…</td></tr>';

    post(cfg.actions.list, {
      search: state.search,
      status: state.status,
      page: state.page,
      per_page: state.perPage,
    })
      .then(function (res) {
        state.loading = false;
        if (!res || !res.success) {
          els.tbody.innerHTML =
            '<tr><td colspan="6" class="cust-empty">' +
            escapeHtml((res && res.data && res.data.message) || 'No se pudo cargar') +
            '</td></tr>';
          return;
        }
        renderRows(res.data.customers || []);
        renderPagination(res.data);
      })
      .catch(function () {
        state.loading = false;
        els.tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">Error de conexión</td></tr>';
      });
  }

  function renderRows(rows) {
    if (!rows.length) {
      els.tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">No hay clientes para mostrar</td></tr>';
      return;
    }
    var html = rows
      .map(function (c) {
        var actions = '';
        if (canEdit()) {
          actions =
            '<button type="button" class="cust-btn cust-btn-link" data-edit="' +
            escapeHtml(c.id) +
            '">Editar</button>';
        } else {
          actions =
            '<button type="button" class="cust-btn cust-btn-link" data-edit="' +
            escapeHtml(c.id) +
            '">Ver</button>';
        }
        actions +=
          '<button type="button" class="cust-btn cust-btn-link" data-contacts="' +
          escapeHtml(c.id) +
          '" data-client-name="' +
          escapeHtml(c.nombre_fantasia) +
          '">Contactos</button>';
        return (
          '<tr>' +
          '<td><strong>' +
          escapeHtml(c.nombre_fantasia) +
          '</strong></td>' +
          '<td>' +
          escapeHtml(c.rut || '—') +
          '</td>' +
          '<td>' +
          escapeHtml(c.contacto_nombre || '—') +
          '</td>' +
          '<td>' +
          escapeHtml(c.contacto_email || '—') +
          '</td>' +
          '<td>' +
          escapeHtml(c.contacto_telefono || c.facturacion_telefono || '—') +
          '</td>' +
          '<td>' +
          actions +
          '</td>' +
          '</tr>'
        );
      })
      .join('');
    els.tbody.innerHTML = html;
  }

  function renderPagination(data) {
    var total = data.total || 0;
    var pages = data.pages || 0;
    var page = data.page || 1;
    if (total <= 0 || pages <= 1) {
      els.pagination.hidden = true;
      return;
    }
    els.pagination.hidden = false;
    els.paginationInfo.textContent =
      'Mostrando página ' + page + ' de ' + pages + ' (' + total + ' clientes)';
    var buttons = '';
    if (page > 1) {
      buttons +=
        '<button type="button" class="cust-btn cust-btn-secondary" data-page="' +
        (page - 1) +
        '">Anterior</button>';
    }
    if (page < pages) {
      buttons +=
        '<button type="button" class="cust-btn cust-btn-secondary" data-page="' +
        (page + 1) +
        '">Siguiente</button>';
    }
    els.paginationButtons.innerHTML = buttons;
  }

  function openNew() {
    if (!canEdit()) return;
    resetForm();
    showForm();
  }

  function openEdit(id) {
    post(cfg.actions.get, { id: id })
      .then(function (res) {
        if (!res || !res.success || !res.data.customer) {
          window.alert((res && res.data && res.data.message) || 'Cliente no encontrado');
          return;
        }
        fillForm(res.data.customer);
        if (!canEdit()) {
          Array.prototype.forEach.call(els.form.elements, function (el) {
            el.disabled = true;
          });
          if (els.back) els.back.disabled = false;
          if (els.cancel) els.cancel.disabled = false;
        }
        showForm();
      })
      .catch(function () {
        window.alert('Error de conexión');
      });
  }

  function saveForm(e) {
    e.preventDefault();
    if (!canEdit()) return;
    var err = validateClient();
    if (err) {
      setFormMsg(err, false);
      return;
    }
    if (els.saveBtn) els.saveBtn.disabled = true;
    setFormMsg('');

    post(cfg.actions.save, {
      id: els.id.value || 0,
      nombre_fantasia: els.nombreFantasia.value,
      has_contacto: els.hasContacto.checked,
      primer_nombre: els.primerNombre.value,
      apellido_paterno: els.apellidoPaterno.value,
      contacto_telefono: els.contactoTelefono.value,
      contacto_email: els.contactoEmail.value,
      has_facturacion: els.hasFacturacion.checked,
      pais: els.pais.value,
      tipo_identificacion: els.tipoId.value,
      rut: els.rut.value,
      razon_social: els.razonSocial.value,
      direccion: els.direccion.value,
      comuna: els.comuna.value,
      ciudad: els.ciudad.value,
      giro: els.giro.value,
      facturacion_telefono: els.facturacionTelefono.value,
      codigo_postal: els.codigoPostal.value,
      has_datos_extra: els.hasDatosExtra.checked,
      datos_extra: collectDatosExtra(),
      activo: els.activo.checked,
    })
      .then(function (res) {
        if (els.saveBtn) els.saveBtn.disabled = false;
        if (!res || !res.success) {
          setFormMsg((res && res.data && res.data.message) || 'No se pudo guardar', false);
          return;
        }
        setFormMsg(res.data.message || 'Guardado', true);
        if (res.data.customer) {
          fillForm(res.data.customer);
        }
        loadList();
        setTimeout(function () {
          showList();
        }, 600);
      })
      .catch(function () {
        if (els.saveBtn) els.saveBtn.disabled = false;
        setFormMsg('Error de conexión', false);
      });
  }

  // Events
  if (els.apply) {
    els.apply.addEventListener('click', function () {
      state.search = (els.search && els.search.value) || '';
      state.status = (els.status && els.status.value) || 'active';
      state.page = 1;
      loadList();
    });
  }
  if (els.clear) {
    els.clear.addEventListener('click', function () {
      if (els.search) els.search.value = '';
      if (els.status) els.status.value = 'active';
      state.search = '';
      state.status = 'active';
      state.page = 1;
      loadList();
    });
  }
  if (els.search) {
    els.search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (els.apply) els.apply.click();
      }
    });
  }
  if (els.newBtn) els.newBtn.addEventListener('click', openNew);
  if (els.back) {
    els.back.addEventListener('click', function () {
      showList();
    });
  }
  if (els.cancel) {
    els.cancel.addEventListener('click', function () {
      showList();
    });
  }
  if (els.form) els.form.addEventListener('submit', saveForm);

  [els.hasContacto, els.hasFacturacion, els.hasDatosExtra].forEach(function (cb) {
    if (cb) cb.addEventListener('change', syncSections);
  });

  els.tbody.addEventListener('click', function (e) {
    var contactsBtn = e.target.closest('[data-contacts]');
    if (contactsBtn) {
      if (typeof window.openClientContacts === 'function') {
        window.openClientContacts(
          contactsBtn.getAttribute('data-contacts'),
          contactsBtn.getAttribute('data-client-name')
        );
      }
      return;
    }
    var btn = e.target.closest('[data-edit]');
    if (!btn) return;
    openEdit(btn.getAttribute('data-edit'));
  });

  if (els.paginationButtons) {
    els.paginationButtons.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-page]');
      if (!btn) return;
      state.page = parseInt(btn.getAttribute('data-page'), 10) || 1;
      loadList();
    });
  }

  syncSections();
  loadList();
  initContacts();
  root.setAttribute('data-ready', '1');

  function initContacts() {
    var panel = document.getElementById('cust-panel-contactos');
    if (!panel) return;

    var ctState = { clienteId: 0, clienteName: '', readonly: false };
    var searchView = document.getElementById('ct-search-view');
    var formView = document.getElementById('ct-form-view');
    var tbody = document.getElementById('ct-tbody');
    var form = document.getElementById('ct-form');
    var listMsg = document.getElementById('ct-list-msg');
    var formMsg = document.getElementById('ct-form-msg');
    var banner = document.getElementById('ct-client-banner');
    var bannerText = document.getElementById('ct-client-banner-text');
    var newBtn = document.getElementById('ct-new');
    var picks = document.getElementById('ct-cliente-picks');
    var pickTimer = null;

    function val(id) {
      var el = document.getElementById(id);
      return el ? el.value : '';
    }
    function setVal(id, value) {
      var el = document.getElementById(id);
      if (el) el.value = value == null ? '' : value;
    }
    function setMsg(el, text, ok) {
      if (!el) return;
      if (!text) {
        el.hidden = true;
        el.textContent = '';
        el.className = 'cust-form-msg';
        return;
      }
      el.hidden = false;
      el.textContent = text;
      el.className = 'cust-form-msg ' + (ok ? 'is-ok' : 'is-error');
    }
    function showSearch() {
      if (searchView) searchView.hidden = false;
      if (formView) formView.hidden = true;
      ctState.readonly = false;
    }
    function showContactForm() {
      if (searchView) searchView.hidden = true;
      if (formView) formView.hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function showTab(name) {
      var clientes = document.getElementById('cust-panel-clientes');
      var tabClientes = document.getElementById('cust-tab-clientes');
      var tabContactos = document.getElementById('cust-tab-contactos');
      var onClientes = name !== 'contactos';
      if (clientes) clientes.hidden = !onClientes;
      panel.hidden = onClientes;
      if (tabClientes) {
        tabClientes.classList.toggle('is-active', onClientes);
        tabClientes.setAttribute('aria-selected', onClientes ? 'true' : 'false');
      }
      if (tabContactos) {
        tabContactos.classList.toggle('is-active', !onClientes);
        tabContactos.setAttribute('aria-selected', !onClientes ? 'true' : 'false');
      }
      if (!onClientes) showSearch();
    }
    function paintClientBanner() {
      var locked = ctState.clienteId > 0;
      if (banner) banner.hidden = !locked;
      if (bannerText) {
        bannerText.textContent = locked ? 'Contactos de ' + ctState.clienteName : '';
      }
      if (newBtn) newBtn.hidden = !canEdit();
    }
    function ensureOption(selectId, value) {
      var select = document.getElementById(selectId);
      if (!select) return;
      var found = false;
      Array.prototype.forEach.call(select.options, function (opt) {
        if (opt.value === value) found = true;
      });
      if (!found && value) {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value;
        select.appendChild(opt);
      }
      select.value = value || '';
    }
    function searchContacts() {
      if (!tbody) return;
      tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">Cargando…</td></tr>';
      setMsg(listMsg, '');
      post(cfg.actions.contactsSearch, {
        cliente_id: ctState.clienteId || 0,
        primer_nombre: val('ct-filter-nombre'),
        segundo_nombre: val('ct-filter-segundo'),
        apellido_paterno: val('ct-filter-paterno'),
        apellido_materno: val('ct-filter-materno'),
        email: val('ct-filter-email'),
        solo_activos: !!(document.getElementById('ct-filter-activo') && document.getElementById('ct-filter-activo').checked),
      }).then(function (res) {
        if (!res || !res.success) {
          tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">No se pudo buscar</td></tr>';
          setMsg(listMsg, (res && res.data && res.data.message) || 'No se pudo buscar', false);
          return;
        }
        renderContacts(res.data.contacts || []);
      }).catch(function () {
        tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">Error de conexión</td></tr>';
      });
    }
    function renderContacts(rows) {
      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="cust-empty">No hay contactos. Si buscas uno inhabilitado, desmarca Activo.</td></tr>';
        return;
      }
      tbody.innerHTML = rows.map(function (c) {
        var actions =
          '<button type="button" class="cust-btn cust-btn-link" data-ct-open="' + escapeHtml(c.id) + '" data-ct-mode="view">Detalles</button>';
        if (canEdit()) {
          actions +=
            '<button type="button" class="cust-btn cust-btn-link" data-ct-open="' + escapeHtml(c.id) + '" data-ct-mode="edit">Editar</button>' +
            '<button type="button" class="cust-btn cust-btn-link cust-btn-danger" data-ct-delete="' + escapeHtml(c.id) + '" data-ct-email="' + escapeHtml(c.email || c.nombre) + '">Eliminar</button>';
        }
        return (
          '<tr>' +
          '<td><strong>' + escapeHtml(c.nombre || '—') + '</strong>' +
          (c.es_primario ? ' <span class="cust-badge">Primario</span>' : '') +
          '</td>' +
          '<td>' + escapeHtml(c.email || '—') + '</td>' +
          '<td>' + escapeHtml(c.telefono || '—') + '</td>' +
          '<td>' + escapeHtml(c.cliente_nombre || '—') + '</td>' +
          '<td>' + (c.activo ? 'Activo' : '<span class="cust-badge cust-badge-off">Inhabilitado</span>') + '</td>' +
          '<td>' + actions + '</td>' +
          '</tr>'
        );
      }).join('');
    }
    function resetContact(clienteId, clienteName) {
      if (form) form.reset();
      setVal('ct-id', '0');
      setVal('ct-cliente-id', clienteId || '0');
      setVal('ct-cliente-buscar', '');
      setVal('ct-lab-pais', 'CHILE');
      setVal('ct-part-pais', 'CHILE');
      setVal('ct-nac-dia', '0');
      setVal('ct-nac-mes', '0');
      setVal('ct-nac-anio', '0');
      var activo = document.getElementById('ct-activo');
      if (activo) activo.checked = true;
      var chosen = document.getElementById('ct-cliente-chosen');
      if (chosen) {
        chosen.textContent = clienteName ? 'Cliente: ' + clienteName : '';
      }
      if (picks) picks.hidden = true;
      var advanced = document.getElementById('ct-advanced');
      var toggle = document.getElementById('ct-toggle-advanced');
      if (advanced) advanced.hidden = true;
      if (toggle) toggle.textContent = 'Mostrar opciones avanzadas';
      setMsg(formMsg, '');
      setContactMode(false);
      var buscar = document.getElementById('ct-cliente-buscar');
      if (buscar) buscar.disabled = !!clienteId;
    }
    function setContactMode(readonly) {
      ctState.readonly = !!readonly;
      var title = document.getElementById('ct-form-title');
      if (title) {
        title.textContent = readonly ? 'Detalles del contacto' : (val('ct-id') === '0' || val('ct-id') === '' ? 'Crear contacto' : 'Editar contacto');
      }
      if (!form) return;
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.id === 'ct-back' || el.id === 'ct-cancel' || el.id === 'ct-toggle-advanced') return;
        el.disabled = !!readonly;
      });
      var save = document.getElementById('ct-save');
      if (save) save.hidden = !!readonly;
    }
    function fillContact(c, readonly) {
      resetContact(c.cliente_id, c.cliente_nombre);
      setVal('ct-id', c.id);
      setVal('ct-primer-nombre', c.primer_nombre);
      setVal('ct-segundo-nombre', c.segundo_nombre);
      setVal('ct-apellido-paterno', c.apellido_paterno);
      setVal('ct-apellido-materno', c.apellido_materno);
      setVal('ct-fono-oficina', c.fono_oficina);
      setVal('ct-fono-movil', c.fono_movil);
      setVal('ct-email', c.email);
      setVal('ct-observaciones', c.observaciones);
      setVal('ct-nac-dia', c.nacimiento_dia || 0);
      setVal('ct-nac-mes', c.nacimiento_mes || 0);
      setVal('ct-nac-anio', c.nacimiento_anio || 0);
      setVal('ct-lab-calle', c.lab_calle);
      setVal('ct-lab-numero', c.lab_numero);
      setVal('ct-lab-depto', c.lab_depto);
      setVal('ct-lab-ciudad', c.lab_ciudad);
      ensureOption('ct-lab-comuna', c.lab_comuna);
      setVal('ct-lab-pais', c.lab_pais || 'CHILE');
      setVal('ct-lab-postal', c.lab_codigo_postal);
      setVal('ct-part-calle', c.part_calle);
      setVal('ct-part-numero', c.part_numero);
      setVal('ct-part-depto', c.part_depto);
      setVal('ct-part-ciudad', c.part_ciudad);
      ensureOption('ct-part-comuna', c.part_comuna);
      setVal('ct-part-pais', c.part_pais || 'CHILE');
      setVal('ct-part-postal', c.part_codigo_postal);
      var activo = document.getElementById('ct-activo');
      if (activo) activo.checked = !!c.activo;
      var chosen = document.getElementById('ct-cliente-chosen');
      if (chosen) chosen.textContent = c.cliente_nombre ? 'Cliente: ' + c.cliente_nombre : '';
      setContactMode(readonly || !canEdit());
      var buscar = document.getElementById('ct-cliente-buscar');
      if (buscar) buscar.disabled = true;
      if ((c.nacimiento_dia || c.lab_calle || c.part_calle)) {
        var advanced = document.getElementById('ct-advanced');
        var toggle = document.getElementById('ct-toggle-advanced');
        if (advanced) advanced.hidden = false;
        if (toggle) toggle.textContent = 'Ocultar opciones avanzadas';
      }
    }
    function openContact(id, mode) {
      post(cfg.actions.contactsGet, { id: id }).then(function (res) {
        if (!res || !res.success || !res.data.contact) {
          setMsg(listMsg, (res && res.data && res.data.message) || 'Contacto no encontrado', false);
          return;
        }
        fillContact(res.data.contact, mode === 'view');
        showContactForm();
      }).catch(function () {
        setMsg(listMsg, 'Error de conexión', false);
      });
    }
    function saveContact(e) {
      e.preventDefault();
      if (!canEdit() || ctState.readonly) return;
      var saveBtn = document.getElementById('ct-save');
      if (saveBtn) saveBtn.disabled = true;
      setMsg(formMsg, '');
      post(cfg.actions.contactsSave, {
        id: val('ct-id') || 0,
        cliente_id: val('ct-cliente-id') || 0,
        primer_nombre: val('ct-primer-nombre'),
        segundo_nombre: val('ct-segundo-nombre'),
        apellido_paterno: val('ct-apellido-paterno'),
        apellido_materno: val('ct-apellido-materno'),
        email: val('ct-email'),
        fono_oficina: val('ct-fono-oficina'),
        fono_movil: val('ct-fono-movil'),
        observaciones: val('ct-observaciones'),
        nacimiento_dia: val('ct-nac-dia') || 0,
        nacimiento_mes: val('ct-nac-mes') || 0,
        nacimiento_anio: val('ct-nac-anio') || 0,
        lab_calle: val('ct-lab-calle'),
        lab_numero: val('ct-lab-numero'),
        lab_depto: val('ct-lab-depto'),
        lab_ciudad: val('ct-lab-ciudad'),
        lab_comuna: val('ct-lab-comuna'),
        lab_pais: val('ct-lab-pais'),
        lab_codigo_postal: val('ct-lab-postal'),
        part_calle: val('ct-part-calle'),
        part_numero: val('ct-part-numero'),
        part_depto: val('ct-part-depto'),
        part_ciudad: val('ct-part-ciudad'),
        part_comuna: val('ct-part-comuna'),
        part_pais: val('ct-part-pais'),
        part_codigo_postal: val('ct-part-postal'),
        activo: !!(document.getElementById('ct-activo') && document.getElementById('ct-activo').checked),
      }).then(function (res) {
        if (saveBtn) saveBtn.disabled = false;
        if (!res || !res.success) {
          setMsg(formMsg, (res && res.data && res.data.message) || 'No se pudo guardar', false);
          return;
        }
        setMsg(listMsg, res.data.message || 'Contacto guardado', true);
        showSearch();
        searchContacts();
      }).catch(function () {
        if (saveBtn) saveBtn.disabled = false;
        setMsg(formMsg, 'Error de conexión', false);
      });
    }

    window.openClientContacts = function (id, name) {
      ctState.clienteId = parseInt(id, 10) || 0;
      ctState.clienteName = name || '';
      var activo = document.getElementById('ct-filter-activo');
      if (activo) activo.checked = false;
      paintClientBanner();
      showTab('contactos');
      searchContacts();
    };

    document.querySelectorAll('.cust-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab');
        if (name === 'clientes') {
          ctState.clienteId = 0;
          ctState.clienteName = '';
          paintClientBanner();
        }
        showTab(name);
      });
    });
    var searchBtn = document.getElementById('ct-search');
    if (searchBtn) searchBtn.addEventListener('click', searchContacts);
    var clearClient = document.getElementById('ct-clear-client');
    if (clearClient) {
      clearClient.addEventListener('click', function () {
        ctState.clienteId = 0;
        ctState.clienteName = '';
        var activo = document.getElementById('ct-filter-activo');
        if (activo) activo.checked = true;
        paintClientBanner();
        searchContacts();
      });
    }
    if (newBtn) {
      newBtn.addEventListener('click', function () {
        resetContact(ctState.clienteId, ctState.clienteName);
        var title = document.getElementById('ct-form-title');
        if (title) title.textContent = 'Crear contacto';
        showContactForm();
      });
    }
    ['ct-back', 'ct-cancel'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('click', showSearch);
    });
    if (form) form.addEventListener('submit', saveContact);
    var toggle = document.getElementById('ct-toggle-advanced');
    if (toggle) {
      toggle.addEventListener('click', function () {
        var advanced = document.getElementById('ct-advanced');
        if (!advanced) return;
        advanced.hidden = !advanced.hidden;
        toggle.textContent = advanced.hidden ? 'Mostrar opciones avanzadas' : 'Ocultar opciones avanzadas';
      });
    }
    if (tbody) {
      tbody.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-ct-open]');
        if (openBtn) {
          openContact(openBtn.getAttribute('data-ct-open'), openBtn.getAttribute('data-ct-mode'));
          return;
        }
        var del = e.target.closest('[data-ct-delete]');
        if (!del) return;
        var label = del.getAttribute('data-ct-email') || 'este contacto';
        if (!window.confirm('¿Eliminar ' + label + '? Después de eliminarlo, el correo se puede volver a guardar.')) return;
        post(cfg.actions.contactsDelete, { id: del.getAttribute('data-ct-delete') }).then(function (res) {
          if (!res || !res.success) {
            setMsg(listMsg, (res && res.data && res.data.message) || 'No se pudo eliminar', false);
            return;
          }
          setMsg(listMsg, res.data.message || 'Contacto eliminado', true);
          searchContacts();
        }).catch(function () {
          setMsg(listMsg, 'Error de conexión', false);
        });
      });
    }
    var clienteBuscar = document.getElementById('ct-cliente-buscar');
    if (clienteBuscar) {
      clienteBuscar.addEventListener('input', function () {
        var q = clienteBuscar.value.trim();
        if (pickTimer) window.clearTimeout(pickTimer);
        if (q.length < 2) {
          if (picks) picks.hidden = true;
          return;
        }
        pickTimer = window.setTimeout(function () {
          post(cfg.actions.list, { search: q, status: 'all', page: 1, per_page: 8 }).then(function (res) {
            if (!picks || !res || !res.success) return;
            var rows = res.data.customers || [];
            if (!rows.length) {
              picks.hidden = true;
              return;
            }
            picks.innerHTML = rows.map(function (c) {
              return '<button type="button" data-pick-id="' + escapeHtml(c.id) + '" data-pick-name="' + escapeHtml(c.nombre_fantasia) + '">' +
                escapeHtml(c.nombre_fantasia) + (c.rut ? ' · ' + escapeHtml(c.rut) : '') + '</button>';
            }).join('');
            picks.hidden = false;
          });
        }, 250);
      });
    }
    if (picks) {
      picks.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pick-id]');
        if (!btn) return;
        setVal('ct-cliente-id', btn.getAttribute('data-pick-id'));
        var chosen = document.getElementById('ct-cliente-chosen');
        if (chosen) chosen.textContent = 'Cliente: ' + (btn.getAttribute('data-pick-name') || '');
        if (clienteBuscar) clienteBuscar.value = btn.getAttribute('data-pick-name') || '';
        picks.hidden = true;
      });
    }
    var openMine = document.getElementById('cust-open-contacts');
    if (openMine) {
      openMine.addEventListener('click', function () {
        var id = parseInt(els.id.value, 10) || 0;
        if (!id) return;
        window.openClientContacts(id, els.nombreFantasia ? els.nombreFantasia.value : '');
      });
    }
  }
})();
