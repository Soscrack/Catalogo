/**
 * Facturación · Configuración de impresión directa ("Imprimir Ya!").
 * Requiere print-quick.js (window.RiversoPrint).
 */
(function () {
  "use strict";

  var rp = window.RiversoPrint;
  var cfg = window.RIVERSO_PRINT || {};
  var REFRESH_MS = 10000;
  var state = { config: null, showVirtual: false, dialog: null };

  var SCALE_LABELS = { ajustar: "Ajustar al papel", porcentaje: "Porcentaje", real: "Tamaño real (100%)" };
  var DUPLEX_LABELS = { no: "Una cara", largo: "Doble cara · borde largo", corto: "Doble cara · borde corto" };
  var ORIENT_LABELS = { auto: "Automática", vertical: "Vertical", horizontal: "Horizontal" };
  var ORIGIN_LABELS = { boton: "Imprimir Ya!", emision: "Al emitir", reintento: "Reintento", alternativa: "Alternativa", prueba: "Prueba" };

  function $(id) {
    return document.getElementById(id);
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function action(key, fallback) {
    return (cfg.actions && cfg.actions[key]) || fallback;
  }

  function canManage() {
    return !!(state.config && state.config.canManage);
  }

  function showAlert(msg, isError) {
    var el = $("rp-alerts");
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || "";
    el.style.background = isError ? "#fef2f2" : "#f0fdf4";
    el.style.color = isError ? "#991b1b" : "#166534";
    el.style.borderColor = isError ? "#fecaca" : "#bbf7d0";
  }

  function load(silent) {
    return rp.post(action("config", "riverso_print_config"), {})
      .then(function (data) {
        state.config = data;
        render();
      })
      .catch(function (err) {
        if (!silent) showAlert(err.message || "No se pudo cargar la configuración.", true);
      });
  }

  function admin(op, fields) {
    return rp.post(action("admin", "riverso_print_admin"), Object.assign({ op: op }, fields || {})).then(function (data) {
      if (data.config) {
        state.config = data.config;
        render();
      }
      return data;
    });
  }

  function badge(kind, text) {
    return '<span class="rp-badge is-' + kind + '">' + esc(text) + "</span>";
  }

  function emptyRow(cols, text) {
    return '<tr><td colspan="' + cols + '" class="rp-empty">' + esc(text) + "</td></tr>";
  }

  function table(head, rows) {
    return '<table class="bill-table rp-table"><thead><tr>' +
      head.map(function (h) { return "<th>" + esc(h) + "</th>"; }).join("") +
      "</tr></thead><tbody>" + rows + "</tbody></table>";
  }

  function btn(label, attrs, cls) {
    return '<button type="button" class="rp-mini ' + (cls || "") + '" ' + attrs + ">" + esc(label) + "</button>";
  }

  function find(list, id) {
    id = Number(id) || 0;
    for (var i = 0; i < (list || []).length; i++) {
      if (Number(list[i].id) === id) return list[i];
    }
    return null;
  }

  // ───────────────────────── Render ─────────────────────────

  function render() {
    if (!state.config) return;
    renderStationSelect();
    renderAgents();
    renderPrinters();
    renderPresets();
    renderRoutes();
    renderStations();
    renderJobs();
  }

  function renderStationSelect() {
    var sel = $("rp-station-select");
    if (!sel) return;
    var current = rp.station();
    var stations = state.config.stations || [];
    if (current && !find(stations, current.id)) {
      rp.setStation(null);
      current = null;
    }
    sel.innerHTML = '<option value="0">Sin estación (usa el ruteo general)</option>' +
      stations.map(function (s) {
        return '<option value="' + s.id + '"' + (current && current.id === s.id ? " selected" : "") + ">" + esc(s.nombre) + "</option>";
      }).join("");
  }

  function renderAgents() {
    var agents = state.config.agents || [];
    var rows = agents.map(function (a) {
      var status = a.online
        ? badge("ok", "En línea")
        : badge("error", "Desconectado") + '<div class="rp-sub">' + esc(a.last_seen ? "Última vez: " + a.last_seen : "Nunca se conectó") + "</div>";
      var actions = canManage()
        ? btn("Renombrar", 'data-rp="agent-rename" data-id="' + a.id + '"') +
          btn("Nuevo token", 'data-rp="agent-token" data-id="' + a.id + '"') +
          btn("Revocar", 'data-rp="agent-revoke" data-id="' + a.id + '"', "is-danger")
        : "";
      return "<tr><td><strong>" + esc(a.nombre) + "</strong></td><td>" + status + "</td><td>" +
        esc(a.hostname || "—") + (a.last_ip ? '<div class="rp-sub">' + esc(a.last_ip) + "</div>" : "") + "</td><td>" +
        esc(a.version || "—") + "</td><td>…" + esc(a.token_hint) + '</td><td class="rp-actions">' + actions + "</td></tr>";
    }).join("");
    $("rp-agents").innerHTML = table(["Hub", "Estado", "Equipo", "Versión", "Token", ""],
      rows || emptyRow(6, "Aún no hay hubs. Agrega uno y pega su token en Riverso Print Hub (PC1)."));
  }

  function printerStatus(p) {
    if (!p.presente) return badge("muted", "No aparece en el PC");
    switch (p.estado) {
      case "listo":
        return badge("ok", "Lista") + (p.estado_detalle ? '<div class="rp-sub">' + esc(p.estado_detalle) + "</div>" : "");
      case "offline":
        return badge("error", "Apagada / desconectada") + (p.estado_detalle ? '<div class="rp-sub">' + esc(p.estado_detalle) + "</div>" : "");
      case "error":
        return badge("warn", "Con problema") + (p.estado_detalle ? '<div class="rp-sub">' + esc(p.estado_detalle) + "</div>" : "");
      default:
        return badge("muted", "Sin datos");
    }
  }

  function renderPrinters() {
    var printers = state.config.printers || [];
    var hidden = 0;
    var rows = printers.filter(function (p) {
      var visible = state.showVirtual || (!p.es_virtual && p.activo);
      if (!visible) hidden++;
      return visible;
    }).map(function (p) {
      var name = "<strong>" + esc(p.label) + "</strong>" +
        (p.alias ? '<div class="rp-sub">' + esc(p.system_name) + "</div>" : "") +
        (p.es_predeterminada ? '<div class="rp-sub">Predeterminada de Windows</div>' : "");
      var where = esc(p.port_name || "—") +
        (p.host || p.host_detectado ? '<div class="rp-sub">IP ' + esc(p.host || p.host_detectado) + (p.host ? "" : " (detectada)") + "</div>" : "");
      return "<tr" + (p.activo ? "" : ' class="is-off"') + "><td>" + name + "</td><td>" + esc(p.agent) + "</td><td>" + printerStatus(p) +
        "</td><td>" + where + "</td><td>" + (p.activo ? "Sí" : "No") + '</td><td class="rp-actions">' +
        (canManage() ? btn("Editar", 'data-rp="printer-edit" data-id="' + p.id + '"') : "") + "</td></tr>";
    }).join("");
    var empty = printers.length
      ? "Todas las impresoras están ocultas (virtuales o desactivadas)."
      : "El hub todavía no reporta impresoras. Aparecen solas cuando Riverso Print Hub se conecta.";
    $("rp-printers").innerHTML = table(["Impresora", "Hub", "Estado", "Puerto / IP", "Activa", ""], rows || emptyRow(6, empty)) +
      (hidden && !state.showVirtual ? '<p class="bill-hint">' + hidden + " impresora(s) oculta(s): virtuales o desactivadas.</p>" : "");
  }

  function presetSummary(p) {
    if (p.modo === "escpos") {
      return "ESC/POS directo · " + p.ancho_puntos + " puntos · " +
        (p.escala_modo === "porcentaje" ? p.escala_pct + "%" : SCALE_LABELS[p.escala_modo] || p.escala_modo) +
        (p.avance_mm ? " · avance " + p.avance_mm + " mm" : "") + " · " + p.copias + (p.copias === 1 ? " copia" : " copias");
    }
    var scale = p.escala_modo === "porcentaje" ? p.escala_pct + "%" : SCALE_LABELS[p.escala_modo] || p.escala_modo;
    return (p.papel || "Papel predeterminado") + " · " + scale + " · " + p.copias + (p.copias === 1 ? " copia" : " copias") +
      " · " + (p.color ? "color" : "B/N") + (p.duplex !== "no" ? " · " + DUPLEX_LABELS[p.duplex] : "");
  }

  function renderPresets() {
    var presets = state.config.presets || [];
    var rows = presets.map(function (p) {
      var st = p.status || {};
      var status = !p.activo ? badge("muted", "Desactivado") : st.ok ? badge("ok", "Disponible") : badge("error", "No disponible") +
        '<div class="rp-sub">' + esc(st.message || "") + "</div>";
      var actions = canManage()
        ? btn("Probar", 'data-rp="preset-test" data-id="' + p.id + '"') +
          btn("Editar", 'data-rp="preset-edit" data-id="' + p.id + '"') +
          btn("Eliminar", 'data-rp="preset-delete" data-id="' + p.id + '"', "is-danger")
        : "";
      return "<tr" + (p.activo ? "" : ' class="is-off"') + "><td><strong>" + esc(p.nombre) + "</strong></td><td>" + esc(p.printer_label) +
        "</td><td>" + esc(presetSummary(p)) + "</td><td>" + status + '</td><td class="rp-actions">' + actions + "</td></tr>";
    }).join("");
    $("rp-presets").innerHTML = table(["Preset", "Impresora", "Ajustes", "Estado", ""],
      rows || emptyRow(5, "Sin presets. Crea uno por cada forma de imprimir (ej: Boleta térmica, Factura carta)."));
  }

  function renderRoutes() {
    var routes = state.config.routes || [];
    var rows = routes.map(function (r) {
      var actions = canManage()
        ? btn("Editar", 'data-rp="route-edit" data-id="' + r.id + '"') +
          btn("Eliminar", 'data-rp="route-delete" data-id="' + r.id + '"', "is-danger")
        : "";
      return "<tr><td><strong>" + esc(r.document_type) + "</strong></td><td>" + esc(r.station) + "</td><td>" + esc(r.preset) +
        "</td><td>" + esc(r.fallback || "—") + '</td><td class="rp-actions">' + actions + "</td></tr>";
    }).join("");
    $("rp-routes").innerHTML = table(["Documento", "Estación", "Preset principal", "Alternativa", ""],
      rows || emptyRow(5, "Sin reglas: «Imprimir Ya!» no sabe dónde imprimir cada documento."));
  }

  function renderStations() {
    var stations = state.config.stations || [];
    var rows = stations.map(function (s) {
      var actions = canManage()
        ? btn("Renombrar", 'data-rp="station-edit" data-id="' + s.id + '"') +
          btn("Eliminar", 'data-rp="station-delete" data-id="' + s.id + '"', "is-danger")
        : "";
      return "<tr><td><strong>" + esc(s.nombre) + '</strong></td><td class="rp-actions">' + actions + "</td></tr>";
    }).join("");
    $("rp-stations").innerHTML = table(["Estación", ""],
      rows || emptyRow(2, "Opcional. Crea estaciones (Caja 1, Celular bodega) si distintos dispositivos deben imprimir en impresoras distintas."));
  }

  function jobStatus(j) {
    if (j.ok) return badge("ok", "Impreso");
    if (!j.final) return badge("busy", j.estado_label);
    if (j.estado === "cancelado") return badge("muted", "Cancelado");
    return badge("error", j.estado_label) + (j.message ? '<div class="rp-sub">' + esc(j.message) + "</div>" : "");
  }

  function renderJobs() {
    var jobs = state.config.jobs || [];
    var rows = jobs.map(function (j) {
      return "<tr><td>" + esc(j.created_at) + "</td><td>" + esc(j.titulo) + "</td><td>" + esc(j.preset || "—") +
        (j.printer ? '<div class="rp-sub">' + esc(j.printer) + "</div>" : "") + "</td><td>" + esc(ORIGIN_LABELS[j.origen] || j.origen) +
        "</td><td>" + jobStatus(j) + "</td></tr>";
    }).join("");
    $("rp-jobs").innerHTML = table(["Hora", "Documento", "Preset", "Origen", "Estado"], rows || emptyRow(5, "Todavía no hay trabajos."));
  }

  // ───────────────────────── Diálogos ─────────────────────────

  function closeDialog() {
    if (state.dialog) {
      state.dialog.remove();
      state.dialog = null;
    }
  }

  /**
   * @param {{title: string, body: string, submit?: string, danger?: boolean, cancel?: boolean, onSubmit: Function, onMount?: Function}} o
   */
  function openDialog(o) {
    closeDialog();
    var wrap = document.createElement("div");
    wrap.className = "riverso-bill bill-modal-overlay rp-dialog";
    wrap.setAttribute("role", "dialog");
    wrap.setAttribute("aria-modal", "true");
    wrap.innerHTML =
      '<form class="bill-modal rp-dialog-box" novalidate>' +
      '<div class="bill-modal-header"><h3>' + esc(o.title) + "</h3></div>" +
      '<div class="bill-modal-body">' + o.body + '<p class="rp-dialog-error" hidden></p></div>' +
      '<div class="bill-modal-footer">' +
      (o.cancel === false ? "" : '<button type="button" class="bill-btn bill-btn-secondary" data-dialog-cancel>Cancelar</button>') +
      '<button type="submit" class="bill-btn ' + (o.danger ? "bill-btn-danger" : "bill-btn-primary") + '">' + esc(o.submit || "Guardar") + "</button>" +
      "</div></form>";
    var host = document.querySelector(".riverso-bill-wrap") || document.body;
    host.appendChild(wrap);
    state.dialog = wrap;
    var form = wrap.querySelector("form");
    var errEl = wrap.querySelector(".rp-dialog-error");
    wrap.addEventListener("click", function (e) {
      if (e.target === wrap || e.target.closest("[data-dialog-cancel]")) closeDialog();
    });
    wrap.addEventListener("keydown", function (e) {
      if (e.key === "Escape") closeDialog();
    });
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var submitBtn = form.querySelector('button[type="submit"]');
      submitBtn.disabled = true;
      errEl.hidden = true;
      Promise.resolve()
        .then(function () {
          return o.onSubmit(formValues(form), form);
        })
        .then(function (keepOpen) {
          if (keepOpen !== true) closeDialog();
        })
        .catch(function (err) {
          errEl.textContent = err.message || "No se pudo guardar.";
          errEl.hidden = false;
        })
        .finally(function () {
          submitBtn.disabled = false;
        });
    });
    if (typeof o.onMount === "function") o.onMount(form);
    var first = form.querySelector("input:not([type=hidden]):not([readonly]), select");
    if (first) first.focus();
    return form;
  }

  function formValues(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) return;
      out[el.name] = el.type === "checkbox" ? (el.checked ? 1 : 0) : el.value;
    });
    return out;
  }

  function field(label, inner, cls) {
    return '<label class="bill-field ' + (cls || "") + '"><span>' + esc(label) + "</span>" + inner + "</label>";
  }

  function input(name, value, attrs) {
    return '<input name="' + name + '" value="' + esc(value == null ? "" : value) + '" ' + (attrs || 'type="text"') + ">";
  }

  function select(name, options, selected) {
    return '<select name="' + name + '">' + options.map(function (o) {
      return '<option value="' + esc(o.value) + '"' + (String(o.value) === String(selected) ? " selected" : "") + ">" + esc(o.label) + "</option>";
    }).join("") + "</select>";
  }

  function check(name, label, checked) {
    return '<label class="bill-check"><input type="checkbox" name="' + name + '"' + (checked ? " checked" : "") + "> <span>" + esc(label) + "</span></label>";
  }

  function confirmDialog(title, text, submit, onSubmit) {
    openDialog({ title: title, body: "<p>" + esc(text) + "</p>", submit: submit, danger: true, onSubmit: onSubmit });
  }

  function copyText(text, btnEl) {
    var done = function () {
      if (btnEl) {
        btnEl.textContent = "Copiado";
        setTimeout(function () { btnEl.textContent = "Copiar"; }, 1500);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {});
    }
  }

  // ── Hubs ──

  function showToken(token, nombre) {
    var server = (state.config && state.config.serverUrl) || cfg.serverUrl || window.location.origin;
    openDialog({
      title: "Conectar «" + nombre + "»",
      submit: "Listo",
      cancel: false,
      body:
        "<p>En el PC con las impresoras abre <strong>Riverso Print Hub</strong> → <em>Configurar…</em> y pega estos datos:</p>" +
        '<div class="rp-secret"><span>Servidor</span><code>' + esc(server) + '</code><button type="button" class="rp-mini" data-copy="' + esc(server) + '">Copiar</button></div>' +
        '<div class="rp-secret"><span>Token</span><code>' + esc(token) + '</code><button type="button" class="rp-mini" data-copy="' + esc(token) + '">Copiar</button></div>' +
        '<p class="bill-hint">El token se muestra solo esta vez. Si lo pierdes, genera uno nuevo (el anterior deja de funcionar).</p>',
      onSubmit: function () {},
      onMount: function (form) {
        form.addEventListener("click", function (e) {
          var b = e.target.closest("[data-copy]");
          if (b) copyText(b.getAttribute("data-copy"), b);
        });
      },
    });
  }

  function agentNew() {
    openDialog({
      title: "Agregar hub",
      submit: "Crear",
      body: field("Nombre", input("nombre", "", 'type="text" maxlength="100" placeholder="PC1 Caja" required')),
      onSubmit: function (v) {
        return admin("agent_create", { nombre: v.nombre }).then(function (d) {
          setTimeout(function () { showToken(d.token, v.nombre); }, 0);
        });
      },
    });
  }

  function agentRename(id) {
    var a = find(state.config.agents, id);
    if (!a) return;
    openDialog({
      title: "Renombrar hub",
      body: field("Nombre", input("nombre", a.nombre, 'type="text" maxlength="100" required')),
      onSubmit: function (v) {
        return admin("agent_rename", { id: id, nombre: v.nombre });
      },
    });
  }

  function agentToken(id) {
    var a = find(state.config.agents, id);
    if (!a) return;
    confirmDialog("Nuevo token", "El token actual de «" + a.nombre + "» dejará de funcionar y habrá que pegar el nuevo en el hub.", "Generar", function () {
      return admin("agent_token", { id: id }).then(function (d) {
        setTimeout(function () { showToken(d.token, a.nombre); }, 0);
      });
    });
  }

  function agentRevoke(id) {
    var a = find(state.config.agents, id);
    if (!a) return;
    confirmDialog("Revocar hub", "«" + a.nombre + "» dejará de recibir trabajos y sus impresoras quedarán desactivadas. Los presets que las usan dejarán de funcionar.", "Revocar", function () {
      return admin("agent_revoke", { id: id });
    });
  }

  // ── Impresoras ──

  function printerEdit(id) {
    var p = find(state.config.printers, id);
    if (!p) return;
    openDialog({
      title: "Impresora",
      body:
        '<p class="bill-hint">' + esc(p.system_name) + " · " + esc(p.agent) + "</p>" +
        field("Nombre para mostrar", input("alias", p.alias, 'type="text" maxlength="100" placeholder="' + esc(p.system_name) + '"')) +
        field("IP para revisar si está encendida (opcional)", input("host", p.host, 'type="text" maxlength="100" placeholder="' + esc(p.host_detectado || "192.168.1.50") + '"')) +
        '<p class="bill-hint">Solo impresoras de red. El hub la usa para avisar «apagada» antes de imprimir.' +
        (p.host_detectado ? " Detectada: " + esc(p.host_detectado) + "." : "") + "</p>" +
        check("activo", "Activa (se puede usar en presets)", p.activo),
      onSubmit: function (v) {
        return admin("printer_save", { id: id, alias: v.alias, host: v.host, activo: v.activo });
      },
    });
  }

  // ── Presets ──

  function printerOptions(selectedId) {
    var printers = (state.config.printers || []).filter(function (p) {
      return (p.activo && p.presente) || p.id === Number(selectedId);
    });
    return printers.map(function (p) {
      return { value: p.id, label: p.agent + " · " + p.label + (p.presente ? "" : " (no aparece)") };
    });
  }

  function paperOptions(printerId, selected) {
    var p = find(state.config.printers, printerId);
    var papers = p ? p.papeles || [] : [];
    var opts = [{ value: "", label: "(Predeterminado de la impresora)" }];
    papers.forEach(function (name) { opts.push({ value: name, label: name }); });
    if (selected && papers.indexOf(selected) === -1) opts.push({ value: selected, label: selected + " (no reportado)" });
    return opts;
  }

  function presetDialog(preset) {
    var p = preset || {
      id: 0, nombre: "", printer_id: 0, modo: "driver", papel: "", escala_modo: "ajustar", escala_pct: 100, copias: 1,
      color: true, duplex: "no", orientacion: "auto", ancho_puntos: 384, avance_mm: 10, activo: true,
    };
    var printers = printerOptions(p.printer_id);
    if (!printers.length) {
      showAlert("No hay impresoras activas. Conecta un hub primero.", true);
      return;
    }
    var printerId = p.printer_id || printers[0].value;
    var body =
      (p.id ? "" : '<div class="rp-quick-fill"><span>Valores rápidos:</span>' +
        '<button type="button" class="rp-mini" data-fill="boleta">Boleta térmica 58 mm</button>' +
        '<button type="button" class="rp-mini" data-fill="factura">Factura carta</button></div>') +
      '<div class="bill-grid bill-grid-2">' +
      field("Nombre", input("nombre", p.nombre, 'type="text" maxlength="100" placeholder="Boleta térmica" required')) +
      field("Impresora", select("printer_id", printers, printerId)) +
      field("Modo", select("modo", [
        { value: "driver", label: "Driver de Windows (PDF)" },
        { value: "escpos", label: "ESC/POS directo (térmica)" },
      ], p.modo)) +
      field("Papel", '<select name="papel"></select>', "rp-only-driver") +
      field("Escala", select("escala_modo", [
        { value: "ajustar", label: SCALE_LABELS.ajustar },
        { value: "porcentaje", label: SCALE_LABELS.porcentaje },
        { value: "real", label: SCALE_LABELS.real },
      ], p.escala_modo)) +
      field("Porcentaje", input("escala_pct", p.escala_pct, 'type="number" min="10" max="400" step="1"'), "rp-only-pct") +
      field("Copias", input("copias", p.copias, 'type="number" min="1" max="20" step="1"')) +
      field("Dúplex", select("duplex", [
        { value: "no", label: DUPLEX_LABELS.no },
        { value: "largo", label: DUPLEX_LABELS.largo },
        { value: "corto", label: DUPLEX_LABELS.corto },
      ], p.duplex), "rp-only-driver") +
      field("Orientación", select("orientacion", [
        { value: "auto", label: ORIENT_LABELS.auto },
        { value: "vertical", label: ORIENT_LABELS.vertical },
        { value: "horizontal", label: ORIENT_LABELS.horizontal },
      ], p.orientacion), "rp-only-driver") +
      field("Ancho imprimible (puntos)", input("ancho_puntos", p.ancho_puntos, 'type="number" min="128" max="1024" step="8"'), "rp-only-escpos") +
      field("Avance final (mm)", input("avance_mm", p.avance_mm, 'type="number" min="0" max="100" step="1"'), "rp-only-escpos") +
      "</div>" +
      '<p class="bill-hint rp-only-escpos">58 mm = 384 puntos · 80 mm = 576 puntos. Con «Ajustar al papel» se recortan los márgenes blancos del PDF y el contenido usa todo el ancho. El avance deja el timbre pasado la barra de corte.</p>' +
      '<p class="bill-hint rp-only-driver">«Ajustar al papel» achica la página para que quepa completa. «Porcentaje» replica la escala personalizada del diálogo de Chrome (ej: 85%).</p>' +
      '<div class="rp-checks">' + check("color", "Color", p.color) + check("activo", "Activo", p.activo) + "</div>";

    openDialog({
      title: p.id ? "Editar preset" : "Nuevo preset",
      body: body,
      onSubmit: function (v) {
        v.id = p.id;
        return admin("preset_save", v);
      },
      onMount: function (form) {
        var papel = p.papel || "";
        function fillPapers(selected) {
          var sel = form.elements.papel;
          sel.innerHTML = paperOptions(form.elements.printer_id.value, selected).map(function (o) {
            return '<option value="' + esc(o.value) + '"' + (o.value === selected ? " selected" : "") + ">" + esc(o.label) + "</option>";
          }).join("");
        }
        function sync() {
          var mode = form.elements.modo.value;
          form.querySelectorAll(".rp-only-driver").forEach(function (el) { el.hidden = mode !== "driver"; });
          form.querySelectorAll(".rp-only-escpos").forEach(function (el) { el.hidden = mode !== "escpos"; });
          form.querySelectorAll(".rp-only-pct").forEach(function (el) { el.hidden = form.elements.escala_modo.value !== "porcentaje"; });
        }
        function findPaper(re) {
          var printer = find(state.config.printers, form.elements.printer_id.value);
          var papers = printer ? printer.papeles || [] : [];
          for (var i = 0; i < papers.length; i++) {
            if (re.test(papers[i])) return papers[i];
          }
          return "";
        }
        fillPapers(papel);
        sync();
        form.elements.printer_id.addEventListener("change", function () { fillPapers(form.elements.papel.value); });
        form.elements.modo.addEventListener("change", sync);
        form.elements.escala_modo.addEventListener("change", sync);
        form.addEventListener("click", function (e) {
          var b = e.target.closest("[data-fill]");
          if (!b) return;
          var kind = b.getAttribute("data-fill");
          var printers = state.config.printers || [];
          var pick = function (re) {
            for (var i = 0; i < printers.length; i++) {
              if (printers[i].activo && printers[i].presente && re.test(printers[i].system_name)) return printers[i].id;
            }
            return 0;
          };
          if (kind === "boleta") {
            form.elements.nombre.value = form.elements.nombre.value || "Boleta térmica";
            var pos = pick(/pos|58|térmic|thermal|receipt/i);
            if (pos) form.elements.printer_id.value = String(pos);
            form.elements.modo.value = "driver";
            fillPapers(findPaper(/58/));
            form.elements.escala_modo.value = "porcentaje";
            form.elements.escala_pct.value = 85;
            form.elements.duplex.value = "no";
            form.elements.color.checked = false;
            form.elements.ancho_puntos.value = 384;
            form.elements.avance_mm.value = 10;
          } else {
            form.elements.nombre.value = form.elements.nombre.value || "Factura carta";
            var canon = pick(/^(?!.*(fax|copiar|copy)).*(canon|gx7)/i);
            if (canon) form.elements.printer_id.value = String(canon);
            form.elements.modo.value = "driver";
            fillPapers(findPaper(/carta|letter|8\.5/i));
            form.elements.escala_modo.value = "ajustar";
            form.elements.duplex.value = "largo";
            form.elements.color.checked = true;
          }
          form.elements.copias.value = 1;
          sync();
        });
      },
    });
  }

  function presetTest(id) {
    rp.post(action("test", "riverso_print_test"), { preset_id: id })
      .then(function (data) {
        rp.toast("busy", data.job.titulo, "Enviando página de prueba…");
        return rp.track(data.job.id, { onDone: function () { load(true); } });
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo enviar la prueba.", true);
      });
  }

  function presetDelete(id) {
    var p = find(state.config.presets, id);
    if (!p) return;
    confirmDialog("Eliminar preset", "¿Eliminar «" + p.nombre + "»?", "Eliminar", function () {
      return admin("preset_delete", { id: id });
    });
  }

  // ── Ruteo ──

  function routeDialog(route) {
    var presets = state.config.presets || [];
    if (!presets.length) {
      showAlert("Primero crea al menos un preset.", true);
      return;
    }
    var r = route || { id: 0, document_type_id: (state.config.documentTypes[0] || {}).id, station_id: 0, preset_id: presets[0].id, fallback_preset_id: 0 };
    var presetOpts = presets.map(function (p) { return { value: p.id, label: p.nombre + " · " + p.printer_label }; });
    openDialog({
      title: r.id ? "Editar regla" : "Nueva regla",
      body:
        field("Documento", select("document_type_id", state.config.documentTypes.map(function (t) {
          return { value: t.id, label: t.label };
        }), r.document_type_id)) +
        field("Estación", select("station_id", [{ value: 0, label: "Todas (regla general)" }].concat((state.config.stations || []).map(function (s) {
          return { value: s.id, label: s.nombre };
        })), r.station_id)) +
        field("Preset principal", select("preset_id", presetOpts, r.preset_id)) +
        field("Alternativa (se ofrece si la principal falla)", select("fallback_preset_id", [{ value: 0, label: "Ninguna" }].concat(presetOpts), r.fallback_preset_id)),
      onSubmit: function (v) {
        return admin("route_save", v).then(function () {
          // Si cambió tipo o estación, la regla anterior queda sola: se elimina.
          if (r.id && (Number(v.document_type_id) !== r.document_type_id || Number(v.station_id) !== r.station_id)) {
            return admin("route_delete", { id: r.id });
          }
        });
      },
    });
  }

  function routeDelete(id) {
    var r = find(state.config.routes, id);
    if (!r) return;
    confirmDialog("Eliminar regla", "¿Eliminar la regla de " + r.document_type + " (" + r.station + ")?", "Eliminar", function () {
      return admin("route_delete", { id: id });
    });
  }

  // ── Estaciones ──

  function stationDialog(st) {
    var s = st || { id: 0, nombre: "" };
    openDialog({
      title: s.id ? "Renombrar estación" : "Nueva estación",
      body: field("Nombre", input("nombre", s.nombre, 'type="text" maxlength="100" placeholder="Caja 1" required')),
      onSubmit: function (v) {
        return admin("station_save", { id: s.id, nombre: v.nombre }).then(function () {
          var current = rp.station();
          if (current && current.id === s.id) rp.setStation({ id: s.id, nombre: v.nombre });
        });
      },
    });
  }

  function stationDelete(id) {
    var s = find(state.config.stations, id);
    if (!s) return;
    confirmDialog("Eliminar estación", "Se eliminan también sus reglas de ruteo. Los dispositivos con «" + s.nombre + "» vuelven al ruteo general.", "Eliminar", function () {
      return admin("station_delete", { id: id });
    });
  }

  // ───────────────────────── Eventos ─────────────────────────

  function bind() {
    var root = $("riverso-print-config");
    if (!root || !rp) return;

    root.addEventListener("click", function (e) {
      var b = e.target.closest("[data-rp]");
      if (!b) return;
      var id = Number(b.getAttribute("data-id")) || 0;
      switch (b.getAttribute("data-rp")) {
        case "refresh":
          load();
          break;
        case "agent-new":
          agentNew();
          break;
        case "agent-rename":
          agentRename(id);
          break;
        case "agent-token":
          agentToken(id);
          break;
        case "agent-revoke":
          agentRevoke(id);
          break;
        case "printer-edit":
          printerEdit(id);
          break;
        case "preset-new":
          presetDialog(null);
          break;
        case "preset-edit":
          presetDialog(find(state.config.presets, id));
          break;
        case "preset-test":
          presetTest(id);
          break;
        case "preset-delete":
          presetDelete(id);
          break;
        case "route-new":
          routeDialog(null);
          break;
        case "route-edit":
          routeDialog(find(state.config.routes, id));
          break;
        case "route-delete":
          routeDelete(id);
          break;
        case "station-new":
          stationDialog(null);
          break;
        case "station-edit":
          stationDialog(find(state.config.stations, id));
          break;
        case "station-delete":
          stationDelete(id);
          break;
      }
    });

    $("rp-station-select").addEventListener("change", function (e) {
      var st = find((state.config || {}).stations, e.target.value);
      rp.setStation(st ? { id: st.id, nombre: st.nombre } : null);
      showAlert(st ? "Este dispositivo quedó como «" + st.nombre + "»." : "Este dispositivo usa el ruteo general.");
    });

    $("rp-show-virtual").addEventListener("change", function (e) {
      state.showVirtual = e.target.checked;
      renderPrinters();
    });

    setInterval(function () {
      if (!state.dialog && !document.hidden) load(true);
    }, REFRESH_MS);

    root.setAttribute("data-ready", "1");
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();
