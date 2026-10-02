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
  root.setAttribute('data-ready', '1');
})();
