/**
 * Recepción de compras: recibir (zona Recepción) → ordenar → reclamar.
 */
(function () {
  "use strict";

  var cfg = window.riversoReception || {};
  var root = document.getElementById("riverso-rx");
  if (!root || !cfg.ajaxUrl) return;

  var EPS = 0.0001;
  var state = {
    view: "list",
    filter: "pendientes",
    search: "",
    page: 1,
    perPage: 25,
    total: 0,
    items: [],
    selected: {},
    doc: null,
    tab: "recibir",
    draft: {}, // key → cantidad a recibir ahora
    assign: {}, // key → {producto_base_id, nombre, sku} (línea sin vincular)
    extras: [], // productos no incluidos aún no confirmados
    destinations: null,
    orderDest: {}, // linea_id → ubicacion_id
    claimFilter: "abiertos",
    scanCache: {},
    busy: false,
  };

  /* ===================== Utilidades ===================== */

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

  function qty(n) {
    var v = Number(n) || 0;
    return v.toLocaleString("es-CL", { maximumFractionDigits: 4 });
  }

  function money(n) {
    if (n === null || n === undefined) return "—";
    var v = Math.round(Number(n) || 0);
    var str = String(Math.abs(v)).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return (v < 0 ? "-$" : "$") + str;
  }

  function round4(n) {
    return Math.round((Number(n) || 0) * 10000) / 10000;
  }

  function post(action, data) {
    return request(action, data, cfg.nonce);
  }

  /** Acciones de Facturas/Escaneos (otro nonce). Acepta archivos (File). */
  function postScan(action, data) {
    return request(action, data, cfg.scanNonce);
  }

  function request(action, data, nonce) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var val = data[k];
      if (val === true) val = "1";
      if (val === false) val = "0";
      if (typeof Blob !== "undefined" && val instanceof Blob) {
        body.append(k, val, val.name || k);
        return;
      }
      if (val !== null && typeof val === "object") val = JSON.stringify(val);
      body.append(k, val == null ? "" : val);
    });
    return fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success) {
          var error = new Error((res && res.data && res.data.message) || "Error en la solicitud.");
          error.data = (res && res.data) || {};
          throw error;
        }
        var data = res.data || {};
        // Los reclamos recibidos se guardan para "Copiar texto" (la tarjeta solo tiene el id).
        if (data.claims) rememberClaims(data.claims);
        if (data.doc && data.doc.claims) rememberClaims(data.doc.claims);
        if (data.claim && data.claim.id) rememberClaims([data.claim]);
        return data;
      });
  }

  function fail(err) {
    alert(err && err.message ? err.message : String(err));
  }

  function withBusy(btn, promise) {
    if (btn) btn.disabled = true;
    state.busy = true;
    return promise.finally(function () {
      if (btn) btn.disabled = false;
      state.busy = false;
    });
  }

  function storageGet(key) {
    try {
      var raw = window.localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      if (value === null) window.localStorage.removeItem(key);
      else window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      /* sin almacenamiento: el borrador vive solo en memoria */
    }
  }

  /* ===================== Navegación ===================== */

  function urlFor(params) {
    var url = new URL(cfg.baseUrl, window.location.href);
    Object.keys(params || {}).forEach(function (k) {
      if (params[k] !== null && params[k] !== undefined && params[k] !== "") {
        url.searchParams.set(k, params[k]);
      }
    });
    return url.toString();
  }

  function setUrl(params, replace) {
    var url = urlFor(params);
    if (url === window.location.href) return;
    try {
      window.history[replace ? "replaceState" : "pushState"]({}, "", url);
    } catch (e) {
      /* sin history API */
    }
  }

  function readLocation() {
    var params = new URLSearchParams(window.location.search);
    var doc = parseInt(params.get("doc") || "0", 10);
    if (doc > 0) return { view: "doc", id: doc, tab: params.get("tab") || "" };
    if (params.get("vista") === "reclamos") return { view: "claims" };
    return { view: "list" };
  }

  function route(loc) {
    if (loc.view === "doc") openDoc(loc.id, loc.tab, true);
    else if (loc.view === "claims") showClaims(true);
    else showList(true);
  }

  function showView(name) {
    state.view = name;
    $("rx-list-view").hidden = name !== "list";
    $("rx-doc-view").hidden = name !== "doc";
    $("rx-claims-view").hidden = name !== "claims";
  }

  window.addEventListener("popstate", function () {
    route(readLocation());
  });

  /* ===================== Lista ===================== */

  function showList(fromHistory) {
    showView("list");
    if (!fromHistory) setUrl({});
    loadList();
    loadInbox();
  }

  function loadList() {
    var body = $("rx-list-body");
    body.innerHTML = '<tr><td colspan="8" class="rx-empty">Cargando…</td></tr>';
    return post(cfg.actions.list, {
      filtro: state.filter,
      buscar: state.search,
      pagina: state.page,
      por_pagina: state.perPage,
    })
      .then(function (data) {
        state.items = data.items || [];
        state.total = data.total || 0;
        state.selected = {};
        renderFilters(data.counts || {});
        renderClaimsBadge(data.claims_open || 0);
        renderList();
      })
      .catch(function (err) {
        body.innerHTML = '<tr><td colspan="8" class="rx-empty">' + esc(err.message) + "</td></tr>";
      });
  }

  function renderFilters(counts) {
    root.querySelectorAll("#rx-filters .rx-chip").forEach(function (chip) {
      var key = chip.getAttribute("data-filter");
      chip.classList.toggle("is-active", key === state.filter);
      var span = chip.querySelector("span");
      if (span) span.textContent = counts[key] != null ? "(" + counts[key] + ")" : "";
    });
  }

  function renderClaimsBadge(n) {
    var badge = $("rx-claims-count");
    badge.hidden = !n;
    badge.textContent = n || "";
  }

  function stateChip(estado, label) {
    return '<span class="rx-state rx-state--' + esc(estado) + '">' + esc(label) + "</span>";
  }

  function renderList() {
    var body = $("rx-list-body");
    if (!state.items.length) {
      body.innerHTML =
        '<tr><td colspan="8" class="rx-empty">' +
        (state.search
          ? "No se encontró «" + esc(state.search) + "». Si el documento llegó con el pedido, arrástralo arriba o usa «Ingresar documento»."
          : "No hay documentos en esta vista.") +
        "</td></tr>";
      renderPager();
      updateSelectionUi();
      return;
    }
    body.innerHTML = state.items
      .map(function (it) {
        var pending = it.estado === "pendiente";
        var action = "Ver";
        if (pending) action = "Recibir";
        else if (it.estado === "parcial") action = "Continuar";
        else if (it.por_ordenar > EPS) action = "Ordenar";
        var extra = "";
        if (it.cubierta_por) extra = '<div class="rx-sub">con ' + esc(it.cubierta_por.label) + "</div>";
        else if (it.estado === "ignorada" && it.motivo) extra = '<div class="rx-sub">' + esc(it.motivo) + "</div>";
        var buttons =
          '<button type="button" class="rx-btn rx-btn-sm" data-open="' + it.id + '">' + action + "</button>";
        if (pending) {
          buttons += ' <button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-ignore="' + it.id + '">Ignorar</button>';
        }
        if (it.estado === "ignorada" || it.estado === "cubierta") {
          buttons +=
            ' <button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-restore="' + it.id + '" data-estado="' + it.estado + '">' +
            (it.estado === "cubierta" ? "Recibir igual" : "Volver a pendiente") +
            "</button>";
        }
        return (
          '<tr data-row="' + it.id + '">' +
          '<td class="rx-col-check">' +
          (pending ? '<input type="checkbox" data-check="' + it.id + '"' + (state.selected[it.id] ? " checked" : "") + ">" : "") +
          "</td>" +
          "<td><strong>" + esc(it.tipo_label) + " N° " + esc(it.folio) + "</strong></td>" +
          "<td>" + esc(it.proveedor) + '<div class="rx-sub">' + esc(it.rut) + "</div></td>" +
          "<td>" + esc(it.fecha) + "</td>" +
          '<td class="num">' + it.items + "</td>" +
          "<td>" + stateChip(it.estado, it.estado_label) + extra + "</td>" +
          '<td class="num">' + (it.por_ordenar > EPS ? qty(it.por_ordenar) : "—") + "</td>" +
          '<td class="rx-col-actions">' + buttons + "</td>" +
          "</tr>"
        );
      })
      .join("");
    renderPager();
    updateSelectionUi();
  }

  function renderPager() {
    var pages = Math.max(1, Math.ceil(state.total / state.perPage));
    var pager = $("rx-pager");
    if (pages <= 1) {
      pager.innerHTML = state.total ? '<span class="rx-sub">' + state.total + " documentos</span>" : "";
      return;
    }
    pager.innerHTML =
      '<button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-page="' + (state.page - 1) + '"' + (state.page <= 1 ? " disabled" : "") + ">‹ Anterior</button>" +
      '<span class="rx-sub">Página ' + state.page + " de " + pages + " · " + state.total + " documentos</span>" +
      '<button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-page="' + (state.page + 1) + '"' + (state.page >= pages ? " disabled" : "") + ">Siguiente ›</button>";
  }

  function selectedIds() {
    return Object.keys(state.selected)
      .filter(function (k) {
        return state.selected[k];
      })
      .map(Number);
  }

  function updateSelectionUi() {
    var ids = selectedIds();
    var btn = $("rx-ignore-selected");
    btn.hidden = !ids.length;
    btn.textContent = "Ignorar seleccionados (" + ids.length + ")";
    var all = $("rx-check-all");
    var pendings = state.items.filter(function (it) {
      return it.estado === "pendiente";
    });
    all.disabled = !pendings.length;
    all.checked = pendings.length > 0 && ids.length === pendings.length;
  }

  function ignoreDocs(ids, label) {
    if (!ids.length) return Promise.resolve();
    var question =
      ids.length === 1
        ? "¿Seguro que quieres ignorar " + label + "?\n\nNo se recibirá stock por este documento. Puedes volver a dejarlo pendiente desde Ignoradas."
        : "¿Seguro que quieres ignorar " + ids.length + " documentos?\n\nNo se recibirá stock por ellos. Puedes volver a dejarlos pendientes desde Ignoradas.";
    if (!window.confirm(question)) return Promise.resolve(false);
    return post(cfg.actions.ignore, { ids: ids }).then(function () {
      return true;
    });
  }

  function restoreDoc(id, estado) {
    var question =
      estado === "cubierta"
        ? "Este despacho ya se recibió con otro documento.\n\n¿Recibir igual este documento? Lo que recibas se sumará al stock otra vez."
        : "¿Volver a dejar este documento pendiente de recepción?";
    if (!window.confirm(question)) return Promise.resolve(false);
    return post(cfg.actions.restore, { id: id }).then(function () {
      return true;
    });
  }

  $("rx-filters").addEventListener("click", function (e) {
    var chip = e.target.closest(".rx-chip");
    if (!chip) return;
    state.filter = chip.getAttribute("data-filter");
    state.page = 1;
    loadList();
  });

  var searchTimer = null;
  $("rx-search").addEventListener("input", function (e) {
    clearTimeout(searchTimer);
    var value = e.target.value.trim();
    searchTimer = setTimeout(function () {
      state.search = value;
      state.page = 1;
      loadList();
      loadInbox();
    }, 300);
  });

  $("rx-pager").addEventListener("click", function (e) {
    var btn = e.target.closest("[data-page]");
    if (!btn || btn.disabled) return;
    state.page = parseInt(btn.getAttribute("data-page"), 10) || 1;
    loadList();
  });

  $("rx-list-body").addEventListener("click", function (e) {
    var t = e.target;
    var check = t.closest("[data-check]");
    if (check) {
      state.selected[check.getAttribute("data-check")] = check.checked;
      updateSelectionUi();
      return;
    }
    var ignore = t.closest("[data-ignore]");
    if (ignore) {
      var id = Number(ignore.getAttribute("data-ignore"));
      var it = findItem(id);
      ignoreDocs([id], it ? it.tipo_label + " N° " + it.folio + " de " + it.proveedor : "este documento")
        .then(function (done) {
          if (done) loadList();
        })
        .catch(fail);
      return;
    }
    var restore = t.closest("[data-restore]");
    if (restore) {
      restoreDoc(Number(restore.getAttribute("data-restore")), restore.getAttribute("data-estado"))
        .then(function (done) {
          if (done) loadList();
        })
        .catch(fail);
      return;
    }
    var open = t.closest("[data-open]");
    if (open) {
      openDoc(Number(open.getAttribute("data-open")));
      return;
    }
    var row = t.closest("[data-row]");
    if (row && !t.closest("input,button,a")) openDoc(Number(row.getAttribute("data-row")));
  });

  function findItem(id) {
    for (var i = 0; i < state.items.length; i++) {
      if (state.items[i].id === id) return state.items[i];
    }
    return null;
  }

  $("rx-check-all").addEventListener("change", function (e) {
    state.items.forEach(function (it) {
      if (it.estado === "pendiente") state.selected[it.id] = e.target.checked;
    });
    renderList();
  });

  $("rx-ignore-selected").addEventListener("click", function () {
    ignoreDocs(selectedIds(), "")
      .then(function (done) {
        if (done) loadList();
      })
      .catch(fail);
  });

  $("rx-claims-btn").addEventListener("click", function () {
    showClaims();
  });

  /* ===================== Ingresar documento ===================== */

  var SCAN_POLL_MS = 2000;
  var SCAN_POLL_MAX = 90; // ~3 min, igual que Facturas → Escaneos
  state.scanDocs = {};
  state.justIngested = 0;

  function docTypeFor(tipoDte) {
    tipoDte = Number(tipoDte) || 0;
    if (tipoDte === 52) return "guia_despacho";
    if (tipoDte === 61) return "nota_credito";
    return "productos";
  }

  function folioUrl(facturaId) {
    return cfg.procesarFolioUrl + facturaId;
  }

  function folioLinkHtml(facturaId) {
    if (!cfg.canProcessFolio) return "";
    return ' <a class="rx-btn rx-btn-sm rx-btn-ghost" href="' + esc(folioUrl(facturaId)) + '" target="_blank" rel="noopener">Procesar folio ↗</a>';
  }

  /** La pestaña se abre en el clic: si se abre después de la respuesta, el navegador la bloquea. */
  function openFolioWindow() {
    if (!cfg.canProcessFolio) return null;
    try {
      return window.open("about:blank", "_blank");
    } catch (e) {
      return null;
    }
  }

  function isXml(file) {
    return /\.xml$/i.test(file.name || "") || file.type === "text/xml" || file.type === "application/xml";
  }

  function readText(file) {
    if (file.text) return file.text();
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () {
        resolve(String(reader.result || ""));
      };
      reader.onerror = reject;
      reader.readAsText(file);
    });
  }

  /** Normaliza filas de Escaneos (riverso_scan_*) y de la bandeja de Recepción. */
  function normScan(r) {
    var v = r.validacion || {};
    var estado = r.estado || r.estado_revision || "";
    return {
      id: Number(r.id),
      tipo_dte: Number(r.tipo_dte) || 0,
      tipo_label: r.tipo_label || "Documento",
      folio: r.folio || "",
      proveedor: r.proveedor || r.razon_social_emisor || "",
      rut: r.rut || r.rut_emisor || "",
      fecha: r.fecha || r.fecha_emision || "",
      monto_total: Number(r.monto_total) || 0,
      items: r.items != null ? Number(r.items) : null,
      confianza: r.confianza_level || r.confianza || "",
      validacion_ok: r.validacion_ok !== undefined ? !!r.validacion_ok : v.ok === undefined ? true : !!v.ok,
      estado: estado,
      factura_id: Number(r.factura_id) || 0,
      needs_ingreso: r.needs_ingreso !== undefined ? !!r.needs_ingreso : estado === "pendiente" || estado === "revisado",
    };
  }

  function scanDocHtml(d) {
    var meta = [esc(d.proveedor || "Proveedor no leído")];
    if (d.rut) meta.push(esc(d.rut));
    if (d.fecha) meta.push(esc(d.fecha));
    if (d.monto_total) meta.push(money(d.monto_total));
    if (d.items != null) meta.push(d.items + " ítem" + (d.items === 1 ? "" : "s"));
    var head =
      '<div class="rx-scan-doc-title"><strong>' + esc(d.tipo_label) + " N° " + esc(d.folio || "sin folio") + "</strong> " +
      '<span class="rx-sub">' + meta.join(" · ") + "</span></div>";
    var body;
    if (d.tipo_dte === 61) {
      body = '<div class="rx-warn">Nota de crédito: se ingresa en Facturas, no en Recepción.</div>';
    } else if (d.factura_id && !d.needs_ingreso) {
      body =
        '<div class="rx-scan-doc-actions"><span>Ya estaba ingresado.</span> ' +
        '<button type="button" class="rx-btn rx-btn-sm" data-open-doc="' + d.factura_id + '">Abrir recepción</button>' +
        folioLinkHtml(d.factura_id) + "</div>";
    } else if (d.estado === "duplicado") {
      body = '<div class="rx-sub">Este documento ya se había escaneado: búscalo en «Escaneados sin ingresar».</div>';
    } else {
      var tipo = docTypeFor(d.tipo_dte);
      var warn =
        d.confianza === "baja" || !d.validacion_ok
          ? '<div class="rx-warn">La lectura tiene dudas: conviene revisarlo en Facturas → Escaneos antes de ingresar.</div>'
          : "";
      body =
        warn +
        '<div class="rx-scan-doc-actions">' +
        '<select data-scan-tipo="' + d.id + '" aria-label="Tipo de documento">' +
        '<option value="productos"' + (tipo === "productos" ? " selected" : "") + ">Factura de productos</option>" +
        '<option value="guia_despacho"' + (tipo === "guia_despacho" ? " selected" : "") + ">Guía de despacho</option>" +
        "</select>" +
        (cfg.canProcessFolio
          ? '<label class="rx-check"><input type="checkbox" data-scan-folio="' + d.id + '" checked> Abrir Procesar folio</label>'
          : "") +
        '<button type="button" class="rx-btn rx-btn-sm" data-scan-ingest="' + d.id + '">Ingresar y recibir</button>' +
        '<a class="rx-link" href="' + esc(cfg.scansUrl) + '" target="_blank" rel="noopener">Revisar en Facturas</a>' +
        "</div>";
    }
    return '<div class="rx-scan-doc" data-scan-doc="' + d.id + '">' + head + body + "</div>";
  }

  function ingestCard(title) {
    var el = document.createElement("div");
    el.className = "rx-ingest is-busy";
    el.innerHTML =
      '<div class="rx-ingest-head"><strong></strong>' +
      '<button type="button" class="rx-modal-x" data-dismiss aria-label="Quitar">×</button></div>' +
      '<div class="rx-ingest-body"></div>';
    el.querySelector("strong").textContent = title;
    var list = $("rx-ingest-list");
    list.insertBefore(el, list.firstChild);
    return el;
  }

  function cardBody(card, html, cls) {
    card.querySelector(".rx-ingest-body").innerHTML = html;
    card.className = "rx-ingest" + (cls ? " " + cls : "");
  }

  function busyHtml(text) {
    return '<span class="rx-spinner" aria-hidden="true"></span> ' + esc(text);
  }

  function processFiles(fileList) {
    var files = Array.prototype.slice.call(fileList || []);
    if (!files.length) return;
    var single = files.length === 1;
    // En secuencia, como la carga masiva de Escaneos.
    files.reduce(function (chain, file) {
      return chain.then(function () {
        return ingestFile(file, single);
      });
    }, Promise.resolve());
  }

  function ingestFile(file, single) {
    var card = ingestCard(file.name || "Documento");
    cardBody(card, busyHtml(isXml(file) ? "Leyendo XML…" : "Subiendo…"), "is-busy");
    var job = isXml(file) ? ingestXml(file, card, single) : ingestScan(file, card, single);
    return job.catch(function (err) {
      cardBody(card, '<div class="rx-warn">' + esc(err && err.message ? err.message : String(err)) + "</div>", "is-error");
    });
  }

  function ingestedCard(card, facturaId, message, single) {
    cardBody(
      card,
      '<div class="rx-scan-doc-actions"><span>' + esc(message) + "</span> " +
        '<button type="button" class="rx-btn rx-btn-sm" data-open-doc="' + facturaId + '">Abrir recepción</button>' +
        folioLinkHtml(facturaId) + "</div>",
      "is-ok"
    );
    if (single) {
      state.justIngested = facturaId;
      openDoc(facturaId, "recibir");
    }
  }

  function ingestXml(file, card, single) {
    return readText(file)
      .then(function (text) {
        var tipo = 0;
        try {
          var xml = new DOMParser().parseFromString(text, "application/xml");
          var node = xml.getElementsByTagName("TipoDTE")[0];
          tipo = node ? parseInt(node.textContent, 10) || 0 : 0;
        } catch (e) {
          tipo = 0;
        }
        if (tipo === 61) throw new Error("Es una nota de crédito: se ingresa en Facturas, no en Recepción.");
        return postScan(cfg.actions.xmlUpload, {
          xml_file: file,
          documento_tipo: docTypeFor(tipo),
          upload_mode: "single",
          modo_ingreso: "solo_costos",
          proveedor_modo: "xml",
        });
      })
      .then(
        function (data) {
          var fid = Number(data.factura_id) || 0;
          if (!fid) throw new Error(data.message || "No se pudo ingresar el XML.");
          ingestedCard(card, fid, data.message || "XML ingresado.", single);
        },
        function (err) {
          var fid = err && err.data ? Number(err.data.factura_id) || 0 : 0;
          if (fid) {
            ingestedCard(card, fid, "Ya estaba ingresado.", single);
            return;
          }
          throw err;
        }
      );
  }

  function ingestScan(file, card, single) {
    return postScan(cfg.actions.scanUpload, { scan_file: file })
      .then(function (data) {
        if (data.async && data.archivo_id) {
          cardBody(card, busyHtml("Leyendo el documento con IA… puede tardar un par de minutos."), "is-busy");
          return pollScan(data.archivo_id);
        }
        return data;
      })
      .then(function (data) {
        var docs = (data.documentos || []).filter(Boolean).map(normScan);
        if (!docs.length) throw new Error("No se encontró ningún documento en el archivo.");
        docs.forEach(function (d) {
          state.scanDocs[d.id] = d;
        });
        var note = data.reutilizado ? '<div class="rx-sub">Este archivo ya se había procesado: sin costo de IA.</div>' : "";
        cardBody(card, note + docs.map(scanDocHtml).join(""), "is-ok");
        loadInbox();
        // Un solo documento y ya ingresado: abrir su recepción directo.
        var ready = docs.filter(function (d) {
          return d.factura_id && !d.needs_ingreso && d.tipo_dte !== 61;
        });
        if (single && docs.length === 1 && ready.length === 1) {
          openDoc(ready[0].factura_id, "recibir");
        }
      });
  }

  function pollScan(archivoId) {
    return new Promise(function (resolve, reject) {
      var attempts = 0;
      function retry(message) {
        if (attempts >= SCAN_POLL_MAX) {
          reject(new Error(message));
          return;
        }
        setTimeout(tick, SCAN_POLL_MS);
      }
      function tick() {
        attempts++;
        postScan(cfg.actions.scanStatus, { archivo_id: archivoId }).then(
          function (d) {
            if (!d.done) {
              retry("La IA está tardando. Revisa «Escaneados sin ingresar» en unos minutos.");
            } else if (d.estado === "error") {
              reject(new Error(d.message || "Error al leer el documento con IA."));
            } else {
              resolve(d);
            }
          },
          function () {
            retry("Sin respuesta del servidor. Revisa «Escaneados sin ingresar» en unos minutos.");
          }
        );
      }
      setTimeout(tick, SCAN_POLL_MS);
    });
  }

  function ingestScanDoc(id, container, btn) {
    var d = state.scanDocs[id];
    if (!d) return;
    var sel = container ? container.querySelector('[data-scan-tipo="' + id + '"]') : null;
    var check = container ? container.querySelector('[data-scan-folio="' + id + '"]') : null;
    var win = check && check.checked ? openFolioWindow() : null;
    withBusy(
      btn,
      postScan(cfg.actions.scanConfirm, {
        id: id,
        documento_tipo: sel ? sel.value : docTypeFor(d.tipo_dte),
        modo_ingreso: "solo_costos",
      })
    )
      .then(function (data) {
        var fid = Number(data.factura_id) || 0;
        if (!fid) throw new Error(data.message || "No se pudo ingresar el documento.");
        if (win) win.location.href = folioUrl(fid);
        state.justIngested = fid;
        loadInbox();
        openDoc(fid, "recibir");
      })
      .catch(function (err) {
        if (win) win.close();
        fail(err);
      });
  }

  function loadInbox() {
    if (!cfg.actions.scanInbox) return;
    post(cfg.actions.scanInbox, { buscar: state.search })
      .then(function (data) {
        var items = (data.items || []).map(normScan);
        items.forEach(function (d) {
          state.scanDocs[d.id] = d;
        });
        var inbox = $("rx-inbox");
        inbox.hidden = !items.length;
        // Minimizado por defecto; se despliega solo al buscar algo que esté ahí.
        inbox.open = !!state.search && items.length > 0;
        $("rx-inbox-total").textContent =
          data.total > items.length ? "(últimos " + items.length + " de " + data.total + ")" : "(" + (data.total || 0) + ")";
        $("rx-inbox-list").innerHTML = items.map(scanDocHtml).join("");
      })
      .catch(function () {
        $("rx-inbox").hidden = true;
      });
  }

  function onScanDocClick(e) {
    var dismiss = e.target.closest("[data-dismiss]");
    if (dismiss) {
      var card = dismiss.closest(".rx-ingest");
      if (card) card.parentNode.removeChild(card);
      return;
    }
    var open = e.target.closest("[data-open-doc]");
    if (open) {
      openDoc(Number(open.getAttribute("data-open-doc")), "recibir");
      return;
    }
    var ingest = e.target.closest("[data-scan-ingest]");
    if (ingest) {
      ingestScanDoc(Number(ingest.getAttribute("data-scan-ingest")), ingest.closest("[data-scan-doc]"), ingest);
    }
  }

  $("rx-ingest-list").addEventListener("click", onScanDocClick);
  $("rx-inbox-list").addEventListener("click", onScanDocClick);

  $("rx-ingest-btn").addEventListener("click", function () {
    $("rx-ingest-file").click();
  });
  $("rx-drop").addEventListener("click", function () {
    $("rx-ingest-file").click();
  });
  $("rx-drop").addEventListener("keydown", function (e) {
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      $("rx-ingest-file").click();
    }
  });
  $("rx-ingest-file").addEventListener("change", function (e) {
    processFiles(e.target.files);
    e.target.value = "";
  });

  // Se puede soltar en cualquier parte de la lista (no solo en el recuadro).
  var dragDepth = 0;
  var listView = $("rx-list-view");
  listView.addEventListener("dragenter", function (e) {
    if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], "Files") === -1) return;
    e.preventDefault();
    dragDepth++;
    $("rx-drop").classList.add("is-dragover");
  });
  listView.addEventListener("dragover", function (e) {
    if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], "Files") === -1) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = "copy";
  });
  listView.addEventListener("dragleave", function () {
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) $("rx-drop").classList.remove("is-dragover");
  });
  listView.addEventListener("drop", function (e) {
    if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
    e.preventDefault();
    dragDepth = 0;
    $("rx-drop").classList.remove("is-dragover");
    processFiles(e.dataTransfer.files);
  });

  /* ===================== Documento ===================== */

  function draftKey(id) {
    return "riverso-rx-draft-" + id;
  }

  function saveDraft() {
    if (!state.doc) return;
    var hasData =
      Object.keys(state.draft).some(function (k) {
        return state.draft[k] > EPS;
      }) || state.extras.length;
    storageSet(draftKey(state.doc.id), hasData ? { draft: state.draft, assign: state.assign, extras: state.extras } : null);
  }

  function openDoc(id, tab, fromHistory) {
    showView("doc");
    if (!fromHistory) setUrl({ doc: id });
    $("rx-doc-title").textContent = "Cargando…";
    $("rx-doc-meta").textContent = "";
    $("rx-receive-body").innerHTML = "";
    var stored = storageGet(draftKey(id)) || {};
    state.draft = stored.draft || {};
    state.assign = stored.assign || {};
    state.extras = stored.extras || [];
    state.orderDest = {};
    return Promise.all([post(cfg.actions.get, { id: id }), loadDestinations()])
      .then(function (res) {
        setDoc(res[0].doc, tab);
        var input = $("rx-scan-input");
        if (state.tab === "recibir" && input && !input.disabled) input.focus();
      })
      .catch(function (err) {
        $("rx-doc-title").textContent = "Documento";
        fail(err);
      });
  }

  function loadDestinations() {
    if (state.destinations) return Promise.resolve(state.destinations);
    return post(cfg.actions.destinations, {}).then(function (data) {
      state.destinations = data.locations || [];
      return state.destinations;
    });
  }

  function setDoc(doc, tab) {
    state.doc = doc;
    // El borrador no puede apuntar a líneas que ya no existen.
    var keys = {};
    doc.lines.forEach(function (l) {
      keys[l.key] = true;
    });
    Object.keys(state.draft).forEach(function (k) {
      if (!keys[k] && k.charAt(0) !== "x") delete state.draft[k];
    });
    renderDoc();
    var defaultTab = doc.can_receive ? "recibir" : orderPending().length ? "ordenar" : doc.claims.length ? "reclamos" : "recibir";
    selectTab(tab || defaultTab);
  }

  function renderDoc() {
    var doc = state.doc;
    $("rx-doc-title").textContent = doc.tipo_label + " N° " + doc.folio;
    var st = $("rx-doc-state");
    st.className = "rx-state rx-state--" + doc.estado;
    st.textContent = doc.estado_label;
    $("rx-doc-meta").innerHTML =
      "<span><strong>" + esc(doc.proveedor) + "</strong> · " + esc(doc.rut) + "</span>" +
      "<span>Emitido " + esc(doc.fecha) + "</span>" +
      "<span>Total " + money(doc.monto_total) + "</span>" +
      (doc.completada_en ? "<span>Recibido " + esc(doc.completada_en) + "</span>" : "");

    var notices = [];
    if (doc.cubierta_por) {
      notices.push(
        'Este despacho ya se recibió con <a href="#" data-doc-link="' + doc.cubierta_por.id + '">' + esc(doc.cubierta_por.label) + "</a>: no se vuelve a sumar stock."
      );
    } else if (doc.estado === "ignorada") {
      notices.push("Documento ignorado" + (doc.motivo ? ": " + esc(doc.motivo) : "") + ". No se recibe stock por él.");
    }
    var others = (doc.related || []).filter(function (r) {
      return !doc.cubierta_por || r.id !== doc.cubierta_por.id;
    });
    if (others.length) {
      notices.push(
        "Mismo despacho: " +
          others
            .map(function (r) {
              return '<a href="#" data-doc-link="' + r.id + '">' + esc(r.label) + "</a> (" + esc(r.estado_label) + ")";
            })
            .join(", ") +
          ". Recibe solo uno de ellos."
      );
    }
    var unlinked = doc.can_receive
      ? doc.lines.filter(function (l) {
          return !l.extra && !l.producto_base_id && !state.assign[l.key];
        }).length
      : 0;
    if (unlinked) {
      notices.push(
        unlinked + " línea" + (unlinked === 1 ? "" : "s") + " sin vincular a un producto: " +
          (cfg.canProcessFolio ? "vincúlala" + (unlinked === 1 ? "" : "s") + " en <strong>Procesar folio</strong> y pulsa <strong>Actualizar</strong>, o " : "") +
          "asígna" + (unlinked === 1 ? "la" : "las") + " aquí con «Asignar producto»."
      );
    }
    var notice = $("rx-doc-notice");
    notice.hidden = !notices.length;
    notice.innerHTML = notices.join("<br>");

    var folioBtn = $("rx-process-folio");
    folioBtn.hidden = !cfg.canProcessFolio;
    folioBtn.href = folioUrl(doc.id);
    // Destacado recién ingresado o con líneas por vincular; si no, discreto.
    folioBtn.classList.toggle("rx-btn-ghost", state.justIngested !== doc.id && !unlinked);

    var open = $("rx-open-invoice");
    open.href = cfg.invoicesUrl + (cfg.invoicesUrl.indexOf("?") === -1 ? "?" : "&") + "factura=" + doc.id;
    $("rx-ignore-doc").hidden = doc.estado !== "pendiente";
    $("rx-cancel-rec").hidden = !doc.can_cancel;
    $("rx-restore-doc").hidden = doc.estado !== "ignorada" && doc.estado !== "cubierta";
    $("rx-restore-doc").textContent = doc.estado === "cubierta" ? "Recibir igual" : "Volver a pendiente";

    var orderN = orderPending().length;
    var oc = $("rx-order-count");
    oc.hidden = !orderN;
    oc.textContent = orderN || "";
    var openClaims = doc.claims.filter(function (c) {
      return c.estado === "por_enviar" || c.estado === "enviado";
    }).length;
    var cc = $("rx-doc-claims-count");
    cc.hidden = !openClaims;
    cc.textContent = openClaims || "";

    renderReceive();
    renderOrder();
    renderDocClaims();
  }

  function selectTab(tab) {
    if (["recibir", "ordenar", "reclamos"].indexOf(tab) === -1) tab = "recibir";
    state.tab = tab;
    root.querySelectorAll(".rx-tab").forEach(function (btn) {
      btn.classList.toggle("is-active", btn.getAttribute("data-tab") === tab);
    });
    $("rx-tab-recibir").hidden = tab !== "recibir";
    $("rx-tab-ordenar").hidden = tab !== "ordenar";
    $("rx-tab-reclamos").hidden = tab !== "reclamos";
  }

  root.querySelector(".rx-tabs").addEventListener("click", function (e) {
    var btn = e.target.closest(".rx-tab");
    if (btn) selectTab(btn.getAttribute("data-tab"));
  });

  $("rx-doc-notice").addEventListener("click", function (e) {
    var link = e.target.closest("[data-doc-link]");
    if (!link) return;
    e.preventDefault();
    openDoc(Number(link.getAttribute("data-doc-link")));
  });

  $("rx-back").addEventListener("click", function () {
    showList();
  });

  // Después de vincular productos en Procesar folio, recargar sin perder lo leído.
  $("rx-refresh").addEventListener("click", function () {
    if (state.doc) openDoc(state.doc.id, state.tab, true);
  });

  /* ----- Recibir ----- */

  function receiveLines() {
    var lines = state.doc.lines.slice();
    state.extras.forEach(function (x) {
      var exists = lines.some(function (l) {
        return l.extra && l.producto_base_id === x.producto_base_id;
      });
      if (!exists) {
        lines.push({
          key: "x" + x.producto_base_id,
          factura_item_id: 0,
          linea_id: 0,
          numero_linea: 0,
          codigo_proveedor: "",
          nombre_doc: "",
          cantidad_doc: 0,
          unidad_doc: "",
          factor: 1,
          producto_base_id: x.producto_base_id,
          producto: x.nombre,
          sku: x.sku,
          extra: true,
          esperada: 0,
          recibida: 0,
          por_recibir: 0,
          pending_extra: true,
        });
      }
    });
    return lines;
  }

  function linePb(line) {
    var a = state.assign[line.key];
    return a ? a.producto_base_id : line.producto_base_id;
  }

  function canAssign(line) {
    return !line.extra && !line.linea_id;
  }

  function renderReceive() {
    var doc = state.doc;
    var editable = doc.can_receive;
    $("rx-scan-input").disabled = !editable;
    $("rx-add-product").disabled = !editable;
    $("rx-receive-actions").hidden = !editable;
    var lines = receiveLines();
    if (!lines.length) {
      $("rx-receive-body").innerHTML = '<tr><td colspan="6" class="rx-empty">El documento no tiene productos.</td></tr>';
      return;
    }
    $("rx-receive-body").innerHTML = lines
      .map(function (l) {
        var assigned = state.assign[l.key];
        var pb = linePb(l);
        var name = assigned ? assigned.nombre : l.producto;
        var sku = assigned ? assigned.sku : l.sku;
        var product;
        if (l.extra) {
          product = "<strong>" + esc(name) + '</strong> <span class="rx-tag">No incluido en el documento</span>';
          if (sku) product += '<div class="rx-sub">SKU ' + esc(sku) + "</div>";
        } else {
          product = "<strong>" + esc(l.nombre_doc) + "</strong>";
          var sub = [];
          if (l.codigo_proveedor) sub.push("Cód. " + esc(l.codigo_proveedor));
          if (pb) {
            sub.push("→ " + esc(name) + (sku ? " (SKU " + esc(sku) + ")" : ""));
          }
          if (sub.length) product += '<div class="rx-sub">' + sub.join(" · ") + "</div>";
          if (!pb) {
            product += '<div class="rx-warn">Sin vincular a un producto</div>';
          }
          if (assigned || l.asignado_manual) product += '<span class="rx-tag">Asignado en recepción</span>';
          if (editable && canAssign(l)) {
            product +=
              ' <button type="button" class="rx-link-btn" data-assign="' + esc(l.key) + '">' + (pb ? "Cambiar producto" : "Asignar producto") + "</button>";
          }
        }
        var docQty = l.extra ? "—" : qty(l.cantidad_doc) + " " + esc(l.unidad_doc || "") + (l.factor > 1 ? '<div class="rx-sub">× ' + qty(l.factor) + " por unidad</div>" : "");
        var now = state.draft[l.key] || 0;
        var cls = lineClass(l);
        var input = editable
          ? '<input type="number" class="rx-qty" min="0" step="any" data-qty="' + esc(l.key) + '" value="' + (now > EPS ? round4(now) : "") + '"' + (pb ? "" : " disabled") + ' placeholder="0">' +
            (!l.extra && l.por_recibir > EPS ? '<div class="rx-sub">faltan ' + qty(l.por_recibir) + "</div>" : "")
          : "—";
        return (
          '<tr class="' + cls + '" data-line="' + esc(l.key) + '">' +
          "<td>" + (l.numero_linea || "") + "</td>" +
          "<td>" + product + "</td>" +
          '<td class="num">' + docQty + "</td>" +
          '<td class="num">' + (l.extra ? "—" : qty(l.esperada)) + "</td>" +
          '<td class="num">' + qty(l.recibida) + "</td>" +
          '<td class="num">' + input + "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function lineByKey(key) {
    var lines = receiveLines();
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].key === key) return lines[i];
    }
    return null;
  }

  function setDraft(key, value, rerender) {
    var v = round4(value);
    if (v > EPS) state.draft[key] = v;
    else delete state.draft[key];
    saveDraft();
    if (rerender !== false) renderReceive();
  }

  function flashLine(key) {
    var row = root.querySelector('[data-line="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
    if (!row) return;
    row.classList.remove("is-flash");
    void row.offsetWidth;
    row.classList.add("is-flash");
    if (row.scrollIntoView) row.scrollIntoView({ block: "nearest" });
  }

  function scanStatus(html, isErr) {
    var el = $("rx-scan-status");
    el.innerHTML = html || "";
    el.classList.toggle("is-error", !!isErr);
  }

  function lineClass(l) {
    var now = state.draft[l.key] || 0;
    if (now > EPS) {
      if (l.extra || now > l.por_recibir + EPS) return "is-over";
      return Math.abs(now - l.por_recibir) <= EPS ? "is-full" : "is-partial";
    }
    return !l.extra && l.por_recibir <= EPS && l.esperada > EPS ? "is-done" : "";
  }

  // Sin redibujar la tabla: así no se pierde el foco al pasar con Tab a la línea siguiente.
  $("rx-receive-body").addEventListener("change", function (e) {
    var input = e.target.closest("[data-qty]");
    if (!input) return;
    var key = input.getAttribute("data-qty");
    setDraft(key, parseFloat(String(input.value).replace(",", ".")) || 0, false);
    var line = lineByKey(key);
    var row = input.closest("tr");
    if (line && row) row.className = lineClass(line);
  });

  $("rx-receive-body").addEventListener("click", function (e) {
    var btn = e.target.closest("[data-assign]");
    if (!btn) return;
    var key = btn.getAttribute("data-assign");
    var line = lineByKey(key);
    pickProduct("Asignar producto a: " + (line ? line.nombre_doc : ""), line ? line.codigo_proveedor || line.nombre_doc : "").then(function (p) {
      if (!p) return;
      state.assign[key] = p;
      saveDraft();
      renderReceive();
    });
  });

  /** Suma lo leído a la primera línea del producto que aún tenga pendiente. */
  function addToProduct(pb, units, label) {
    var lines = receiveLines().filter(function (l) {
      return linePb(l) === pb;
    });
    if (!lines.length) return false;
    var target = lines[0];
    for (var i = 0; i < lines.length; i++) {
      var left = lines[i].por_recibir - (state.draft[lines[i].key] || 0);
      if (!lines[i].extra && left > EPS) {
        target = lines[i];
        break;
      }
    }
    addToLine(target, units, label);
    return true;
  }

  function addToLine(line, units, label) {
    var now = round4((state.draft[line.key] || 0) + units);
    setDraft(line.key, now);
    flashLine(line.key);
    var name = label || line.nombre_doc || line.producto;
    var expected = line.extra ? "" : " de " + qty(line.por_recibir);
    scanStatus("+" + qty(units) + " · <strong>" + esc(name) + "</strong> · " + qty(now) + expected);
  }

  function handleScan(code) {
    code = String(code || "").trim();
    if (!code || !state.doc || !state.doc.can_receive) return;
    var lines = receiveLines();
    // Código de proveedor del documento → una unidad del documento (caja × factor).
    for (var i = 0; i < lines.length; i++) {
      var l = lines[i];
      if (!l.extra && l.codigo_proveedor && l.codigo_proveedor.toLowerCase() === code.toLowerCase() && linePb(l)) {
        addToLine(l, l.factor || 1);
        return;
      }
    }
    for (var j = 0; j < lines.length; j++) {
      var s = lines[j];
      var sku = (state.assign[s.key] && state.assign[s.key].sku) || s.sku;
      if (sku && sku.toLowerCase() === code.toLowerCase()) {
        addToLine(s, 1);
        return;
      }
    }
    var cached = state.scanCache[code];
    var lookup = cached
      ? Promise.resolve(cached)
      : post(cfg.actions.scan, { id: state.doc.id, code: code }).then(function (data) {
          state.scanCache[code] = data;
          return data;
        });
    scanStatus("Buscando " + esc(code) + "…");
    lookup
      .then(function (data) {
        if (data.lineas && data.lineas.length) {
          var line = lines.filter(function (x) {
            return x.factura_item_id === data.lineas[0].factura_item_id;
          })[0];
          if (line && linePb(line)) {
            addToLine(line, line.factor || 1);
            return;
          }
        }
        var products = data.productos || [];
        for (var k = 0; k < products.length; k++) {
          if (addToProduct(products[k].producto_base_id, 1, products[k].nombre)) return;
        }
        if (!products.length) {
          scanStatus("No se encontró el código <strong>" + esc(code) + "</strong>.", true);
          return;
        }
        var p = products[0];
        var choose = products.length === 1 ? Promise.resolve(p) : pickProduct("El código " + code + " no está en el documento. ¿Qué producto es?", "", products);
        choose.then(function (chosen) {
          if (!chosen) return;
          if (!window.confirm("“" + chosen.nombre + "” no está en el documento.\n\n¿Agregarlo como producto no incluido?")) {
            scanStatus("Código " + esc(code) + " no agregado.", true);
            return;
          }
          addExtra(chosen);
          addToProduct(chosen.producto_base_id, 1, chosen.nombre);
        });
      })
      .catch(function (err) {
        scanStatus(esc(err.message), true);
      });
  }

  function addExtra(p) {
    var exists = receiveLines().some(function (l) {
      return l.extra && l.producto_base_id === p.producto_base_id;
    });
    if (!exists) {
      state.extras.push({ producto_base_id: p.producto_base_id, nombre: p.nombre, sku: p.sku });
      saveDraft();
    }
    renderReceive();
  }

  $("rx-scan-input").addEventListener("keydown", function (e) {
    if (e.key !== "Enter") return;
    e.preventDefault();
    var value = e.target.value;
    e.target.value = "";
    handleScan(value);
  });

  $("rx-add-product").addEventListener("click", function () {
    pickProduct("Producto no incluido en el documento", "").then(function (p) {
      if (!p) return;
      addExtra(p);
      var key = "x" + p.producto_base_id;
      var line = lineByKey(key) || receiveLines().filter(function (l) {
        return l.extra && l.producto_base_id === p.producto_base_id;
      })[0];
      if (line) {
        var input = root.querySelector('[data-qty="' + line.key + '"]');
        if (input) input.focus();
      }
    });
  });

  $("rx-fill-all").addEventListener("click", function () {
    receiveLines().forEach(function (l) {
      if (!l.extra && linePb(l) && l.por_recibir > EPS) state.draft[l.key] = round4(l.por_recibir);
    });
    saveDraft();
    renderReceive();
    var missing = receiveLines().filter(function (l) {
      return !l.extra && !linePb(l) && l.por_recibir > EPS;
    }).length;
    scanStatus(
      missing
        ? "Se completaron las líneas vinculadas. " + missing + " línea" + (missing === 1 ? "" : "s") + " sin vincular: asigna el producto para recibirlas."
        : "Se completaron todas las líneas con lo esperado.",
      !!missing
    );
  });

  $("rx-clear").addEventListener("click", function () {
    if (!window.confirm("¿Limpiar las cantidades leídas en esta recepción?")) return;
    state.draft = {};
    state.extras = [];
    saveDraft();
    renderReceive();
    scanStatus("");
  });

  function receivePayload() {
    var out = [];
    receiveLines().forEach(function (l) {
      var n = state.draft[l.key] || 0;
      if (n <= EPS) return;
      out.push({
        factura_item_id: l.factura_item_id || 0,
        linea_id: l.extra && l.linea_id ? l.linea_id : 0,
        producto_base_id: linePb(l) || 0,
        cantidad: round4(n),
      });
    });
    return out;
  }

  function confirmReceive(close) {
    var payload = receivePayload();
    var lines = receiveLines();
    if (!payload.length && !close) {
      alert("No hay cantidades para recibir. Lee los códigos, escribe las cantidades o usa «Recibir todo».");
      return;
    }
    var over = lines.filter(function (l) {
      var n = state.draft[l.key] || 0;
      return n > EPS && (l.extra || n > l.por_recibir + EPS);
    }).length;
    var missing = lines.filter(function (l) {
      return !l.extra && l.por_recibir - (state.draft[l.key] || 0) > EPS;
    }).length;
    var question;
    if (close) {
      question =
        "¿Confirmar y cerrar la recepción?\n\n" +
        (missing
          ? missing + " línea" + (missing === 1 ? "" : "s") + " con faltante quedará" + (missing === 1 ? "" : "n") + " en un reclamo al proveedor (sin entrar al stock)."
          : "No hay faltantes.");
    } else {
      question = "¿Confirmar la recepción? Lo recibido entra a la zona Recepción.";
      if (missing) question += "\n\nQuedan " + missing + " línea" + (missing === 1 ? "" : "s") + " por recibir: el documento quedará Parcial.";
    }
    if (over) question += "\n\nAtención: " + over + " línea" + (over === 1 ? "" : "s") + " recibe más de lo esperado.";
    if (!window.confirm(question)) return;
    var btn = close ? $("rx-confirm-close") : $("rx-confirm");
    withBusy(btn, post(cfg.actions.receive, { id: state.doc.id, lines: payload, cerrar: !!close }))
      .then(function (data) {
        state.draft = {};
        state.assign = {};
        state.extras = [];
        storageSet(draftKey(state.doc.id), null);
        scanStatus("");
        setDoc(data.doc, orderPendingOf(data.doc).length ? "ordenar" : "recibir");
      })
      .catch(fail);
  }

  $("rx-confirm").addEventListener("click", function () {
    confirmReceive(false);
  });
  $("rx-confirm-close").addEventListener("click", function () {
    confirmReceive(true);
  });

  /* ----- Ordenar ----- */

  function orderPendingOf(doc) {
    return (doc.lines || []).filter(function (l) {
      return l.linea_id && l.en_recepcion > EPS;
    });
  }

  function orderPending() {
    return state.doc ? orderPendingOf(state.doc) : [];
  }

  function destOptions(selected) {
    var opts = ['<option value="">— Elegir lugar —</option>'];
    (state.destinations || []).forEach(function (d) {
      opts.push('<option value="' + d.id + '"' + (Number(selected) === d.id ? " selected" : "") + ">" + esc(d.label) + "</option>");
    });
    return opts.join("");
  }

  function renderOrder() {
    var rows = orderPending();
    $("rx-order-all-dest").innerHTML = destOptions($("rx-order-all-dest").value);
    var canOrder = state.doc.estado === "parcial" || state.doc.estado === "recibida";
    $("rx-order-all").disabled = !rows.length;
    if (!rows.length) {
      $("rx-order-body").innerHTML =
        '<tr><td colspan="5" class="rx-empty">' + (canOrder ? "No queda nada en Recepción para este documento." : "Primero recibe el documento.") + "</td></tr>";
      return;
    }
    $("rx-order-body").innerHTML = rows
      .map(function (l) {
        var dest = state.orderDest[l.linea_id] || (l.sugerencia ? l.sugerencia.id : "");
        var name = l.producto || l.nombre_doc;
        return (
          '<tr data-order-line="' + l.linea_id + '">' +
          "<td><strong>" + esc(name) + "</strong>" +
          '<div class="rx-sub">' + (l.sku ? "SKU " + esc(l.sku) : "") + (l.codigo_proveedor ? " · Cód. " + esc(l.codigo_proveedor) : "") + "</div>" +
          (l.sugerencia ? '<div class="rx-sub">Sugerido: ' + esc(l.sugerencia.label) + "</div>" : "") +
          "</td>" +
          '<td class="num">' + qty(l.en_recepcion) + "</td>" +
          '<td><select data-dest="' + l.linea_id + '">' + destOptions(dest) + "</select></td>" +
          '<td class="num"><input type="number" class="rx-qty" min="0" step="any" data-order-qty="' + l.linea_id + '" value="' + round4(l.en_recepcion) + '"></td>' +
          '<td class="rx-col-actions">' +
          '<button type="button" class="rx-btn rx-btn-sm" data-order="' + l.linea_id + '">Ordenar</button> ' +
          '<button type="button" class="rx-btn rx-btn-sm rx-btn-warn" data-claim="' + l.linea_id + '">Reclamar</button>' +
          "</td></tr>"
        );
      })
      .join("");
  }

  function orderLine(id) {
    var rows = orderPending();
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].linea_id === id) return rows[i];
    }
    return null;
  }

  function rowQty(id) {
    var input = root.querySelector('[data-order-qty="' + id + '"]');
    return input ? parseFloat(String(input.value).replace(",", ".")) || 0 : 0;
  }

  function sendOrder(moves, btn) {
    if (!moves.length) return;
    withBusy(btn, post(cfg.actions.order, { id: state.doc.id, moves: moves }))
      .then(function (data) {
        state.orderDest = {};
        setDoc(data.doc, "ordenar");
      })
      .catch(fail);
  }

  $("rx-order-body").addEventListener("change", function (e) {
    var sel = e.target.closest("[data-dest]");
    if (sel) state.orderDest[sel.getAttribute("data-dest")] = Number(sel.value) || "";
  });

  $("rx-order-body").addEventListener("click", function (e) {
    var orderBtn = e.target.closest("[data-order]");
    if (orderBtn) {
      var id = Number(orderBtn.getAttribute("data-order"));
      var sel = root.querySelector('[data-dest="' + id + '"]');
      var dest = sel ? Number(sel.value) : 0;
      var n = rowQty(id);
      if (!dest) {
        alert("Elige el lugar de destino.");
        return;
      }
      if (n <= EPS) {
        alert("Indica la cantidad a ordenar.");
        return;
      }
      sendOrder([{ linea_id: id, ubicacion_id: dest, cantidad: n }], orderBtn);
      return;
    }
    var claimBtn = e.target.closest("[data-claim]");
    if (claimBtn) openClaimModal(orderLine(Number(claimBtn.getAttribute("data-claim"))));
  });

  $("rx-order-all-dest").addEventListener("change", function (e) {
    var value = Number(e.target.value) || "";
    if (!value) return;
    orderPending().forEach(function (l) {
      state.orderDest[l.linea_id] = value;
    });
    renderOrder();
    $("rx-order-all-dest").value = String(value);
  });

  $("rx-loc-scan").addEventListener("keydown", function (e) {
    if (e.key !== "Enter") return;
    e.preventDefault();
    var code = e.target.value.trim().toLowerCase();
    e.target.value = "";
    if (!code) return;
    var found = (state.destinations || []).filter(function (d) {
      return (d.barcode && d.barcode.toLowerCase() === code) || d.codigo.toLowerCase() === code;
    })[0];
    if (!found) {
      alert("No se encontró el lugar «" + code + "».");
      return;
    }
    $("rx-order-all-dest").value = String(found.id);
    $("rx-order-all-dest").dispatchEvent(new Event("change"));
  });

  $("rx-order-all").addEventListener("click", function () {
    var moves = [];
    var missing = 0;
    orderPending().forEach(function (l) {
      var sel = root.querySelector('[data-dest="' + l.linea_id + '"]');
      var dest = sel ? Number(sel.value) : 0;
      var n = rowQty(l.linea_id);
      if (n <= EPS) return;
      if (!dest) {
        missing++;
        return;
      }
      moves.push({ linea_id: l.linea_id, ubicacion_id: dest, cantidad: n });
    });
    if (missing) {
      alert(missing + " producto" + (missing === 1 ? " no tiene" : "s no tienen") + " lugar de destino.");
      return;
    }
    if (!moves.length) return;
    if (!window.confirm("¿Ordenar " + moves.length + " producto" + (moves.length === 1 ? "" : "s") + " desde Recepción a sus lugares?")) return;
    sendOrder(moves, $("rx-order-all"));
  });

  /* ----- Reclamar ----- */

  var claimModal = null;

  function ensureClaimModal() {
    if (claimModal) return claimModal;
    var motivos = cfg.motivos || {};
    var el = document.createElement("div");
    el.className = "rx-modal-overlay";
    el.hidden = true;
    el.innerHTML =
      '<div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rx-claim-title">' +
      '<div class="rx-modal-header"><h3 id="rx-claim-title">Reclamar al proveedor</h3><button type="button" class="rx-modal-x" data-close aria-label="Cerrar">×</button></div>' +
      '<div class="rx-modal-body">' +
      '<p class="rx-claim-product"></p>' +
      '<fieldset class="rx-field"><legend>Motivo</legend>' +
      Object.keys(motivos)
        .map(function (k, i) {
          return '<label class="rx-radio"><input type="radio" name="rx-claim-motivo" value="' + esc(k) + '"' + (i === 0 ? " checked" : "") + "> " + esc(motivos[k]) + "</label>";
        })
        .join("") +
      "</fieldset>" +
      '<label class="rx-field"><span>Cantidad</span><input type="number" min="0" step="any" class="rx-claim-qty"></label>' +
      '<label class="rx-field"><span>Nota para el proveedor (opcional)</span><textarea rows="2" class="rx-claim-notes" placeholder="Ej.: caja rota, venía otro modelo"></textarea></label>' +
      '<p class="rx-hint">La cantidad sale del stock (zona Recepción) y queda un reclamo por enviar al proveedor para que emita la nota de crédito.</p>' +
      "</div>" +
      '<div class="rx-modal-footer"><button type="button" class="rx-btn rx-btn-ghost" data-close>Cancelar</button><button type="button" class="rx-btn rx-btn-warn rx-claim-submit">Registrar reclamo</button></div>' +
      "</div>";
    document.body.appendChild(el);
    el.addEventListener("click", function (e) {
      if (e.target === el || e.target.closest("[data-close]")) el.hidden = true;
    });
    el.querySelector(".rx-claim-submit").addEventListener("click", submitClaim);
    claimModal = el;
    return el;
  }

  function openClaimModal(line) {
    if (!line) return;
    var el = ensureClaimModal();
    el.dataset.line = line.linea_id;
    el.dataset.max = line.en_recepcion;
    el.querySelector(".rx-claim-product").innerHTML =
      "<strong>" + esc(line.producto || line.nombre_doc) + "</strong><br><span class=\"rx-sub\">En Recepción: " + qty(line.en_recepcion) + "</span>";
    el.querySelector(".rx-claim-qty").value = round4(rowQty(line.linea_id) || line.en_recepcion);
    el.querySelector(".rx-claim-qty").max = line.en_recepcion;
    el.querySelector(".rx-claim-notes").value = "";
    var first = el.querySelector('input[name="rx-claim-motivo"]');
    if (first) first.checked = true;
    el.hidden = false;
    el.querySelector(".rx-claim-qty").focus();
  }

  function submitClaim() {
    var el = claimModal;
    var n = parseFloat(String(el.querySelector(".rx-claim-qty").value).replace(",", ".")) || 0;
    var max = Number(el.dataset.max) || 0;
    if (n <= EPS) {
      alert("Indica la cantidad a reclamar.");
      return;
    }
    if (n > max + EPS) {
      alert("No puedes reclamar más de lo que queda en Recepción (" + qty(max) + ").");
      return;
    }
    var motivo = el.querySelector('input[name="rx-claim-motivo"]:checked');
    var claim = {
      linea_id: Number(el.dataset.line),
      motivo: motivo ? motivo.value : "faltante",
      cantidad: n,
      notas: el.querySelector(".rx-claim-notes").value,
    };
    withBusy(el.querySelector(".rx-claim-submit"), post(cfg.actions.claim, { id: state.doc.id, claims: [claim] }))
      .then(function (data) {
        el.hidden = true;
        setDoc(data.doc, "ordenar");
      })
      .catch(fail);
  }

  /* ----- Pie del documento ----- */

  $("rx-ignore-doc").addEventListener("click", function () {
    var doc = state.doc;
    ignoreDocs([doc.id], doc.tipo_label + " N° " + doc.folio + " de " + doc.proveedor)
      .then(function (done) {
        if (done) {
          storageSet(draftKey(doc.id), null);
          openDoc(doc.id, "", true);
        }
      })
      .catch(fail);
  });

  $("rx-restore-doc").addEventListener("click", function () {
    var id = state.doc.id;
    restoreDoc(id, state.doc.estado)
      .then(function (done) {
        if (done) openDoc(id, "recibir", true);
      })
      .catch(fail);
  });

  $("rx-cancel-rec").addEventListener("click", function () {
    if (!window.confirm("¿Anular la recepción?\n\nSe descuenta de la zona Recepción todo lo recibido con este documento y vuelve a quedar pendiente.")) return;
    withBusy($("rx-cancel-rec"), post(cfg.actions.cancel, { id: state.doc.id }))
      .then(function (data) {
        setDoc(data.doc, "recibir");
      })
      .catch(fail);
  });

  /* ===================== Selector de productos ===================== */

  var pickerModal = null;
  var pickerResolve = null;

  function ensurePicker() {
    if (pickerModal) return pickerModal;
    var el = document.createElement("div");
    el.className = "rx-modal-overlay";
    el.hidden = true;
    el.innerHTML =
      '<div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rx-picker-title">' +
      '<div class="rx-modal-header"><h3 id="rx-picker-title">Producto</h3><button type="button" class="rx-modal-x" data-close aria-label="Cerrar">×</button></div>' +
      '<div class="rx-modal-body">' +
      '<input type="search" class="rx-picker-input" placeholder="Nombre, SKU, código de barra o de proveedor" autocomplete="off">' +
      '<div class="rx-picker-results"></div>' +
      "</div></div>";
    document.body.appendChild(el);
    el.addEventListener("click", function (e) {
      if (e.target === el || e.target.closest("[data-close]")) closePicker(null);
      var pick = e.target.closest("[data-pick]");
      if (pick) closePicker(JSON.parse(pick.getAttribute("data-pick")));
    });
    var timer = null;
    el.querySelector(".rx-picker-input").addEventListener("input", function (e) {
      clearTimeout(timer);
      var term = e.target.value.trim();
      timer = setTimeout(function () {
        searchPicker(term);
      }, 300);
    });
    pickerModal = el;
    return el;
  }

  function renderPickerResults(products) {
    var box = pickerModal.querySelector(".rx-picker-results");
    if (!products.length) {
      box.innerHTML = '<p class="rx-empty">Sin resultados.</p>';
      return;
    }
    box.innerHTML = products
      .map(function (p) {
        return (
          "<button type=\"button\" class=\"rx-picker-item\" data-pick='" + esc(JSON.stringify(p)).replace(/'/g, "&#39;") + "'>" +
          "<strong>" + esc(p.nombre) + "</strong>" + (p.sku ? ' <span class="rx-sub">SKU ' + esc(p.sku) + "</span>" : "") +
          "</button>"
        );
      })
      .join("");
  }

  function searchPicker(term) {
    if (term.length < 2) {
      pickerModal.querySelector(".rx-picker-results").innerHTML = "";
      return;
    }
    pickerModal.querySelector(".rx-picker-results").innerHTML = '<p class="rx-empty">Buscando…</p>';
    post(cfg.actions.search, { q: term })
      .then(function (data) {
        renderPickerResults(data.productos || []);
      })
      .catch(function (err) {
        pickerModal.querySelector(".rx-picker-results").innerHTML = '<p class="rx-empty">' + esc(err.message) + "</p>";
      });
  }

  function pickProduct(title, term, initial) {
    var el = ensurePicker();
    el.querySelector("#rx-picker-title").textContent = title || "Producto";
    var input = el.querySelector(".rx-picker-input");
    input.value = term || "";
    el.hidden = false;
    input.focus();
    if (initial && initial.length) renderPickerResults(initial);
    else if (term) searchPicker(term);
    else el.querySelector(".rx-picker-results").innerHTML = "";
    return new Promise(function (resolve) {
      pickerResolve = resolve;
    });
  }

  function closePicker(value) {
    if (pickerModal) pickerModal.hidden = true;
    if (pickerResolve) {
      var fn = pickerResolve;
      pickerResolve = null;
      fn(value);
    }
  }

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    if (pickerModal && !pickerModal.hidden) closePicker(null);
    if (claimModal && !claimModal.hidden) claimModal.hidden = true;
    if (resolveModal && !resolveModal.hidden) resolveModal.hidden = true;
  });

  /* ===================== Reclamos ===================== */

  function showClaims(fromHistory) {
    showView("claims");
    if (!fromHistory) setUrl({ vista: "reclamos" });
    loadClaims();
  }

  function loadClaims() {
    root.querySelectorAll("#rx-claim-filters .rx-chip").forEach(function (chip) {
      chip.classList.toggle("is-active", chip.getAttribute("data-estado") === state.claimFilter);
    });
    var box = $("rx-claims-list");
    box.innerHTML = '<p class="rx-empty">Cargando…</p>';
    post(cfg.actions.claims, { estado: state.claimFilter })
      .then(function (data) {
        renderClaimsBadge(data.open || 0);
        box.innerHTML = data.claims && data.claims.length
          ? data.claims.map(function (c) {
              return claimCard(c, true);
            }).join("")
          : '<p class="rx-empty">No hay reclamos en esta vista.</p>';
      })
      .catch(function (err) {
        box.innerHTML = '<p class="rx-empty">' + esc(err.message) + "</p>";
      });
  }

  function renderDocClaims() {
    var claims = state.doc.claims || [];
    $("rx-doc-claims").innerHTML = claims.length
      ? claims.map(function (c) {
          return claimCard(c, false);
        }).join("")
      : '<p class="rx-empty">Sin reclamos. Se registran desde Ordenar con el botón «Reclamar».</p>';
  }

  function claimCard(c, showDoc) {
    var open = c.estado === "por_enviar" || c.estado === "enviado";
    var lines = c.lineas
      .map(function (l) {
        return (
          "<tr><td>" + esc(l.nombre) + '<div class="rx-sub">' + [l.sku ? "SKU " + esc(l.sku) : "", l.codigo_proveedor ? "Cód. " + esc(l.codigo_proveedor) : ""].filter(Boolean).join(" · ") + "</div>" +
          (l.notas ? '<div class="rx-sub">' + esc(l.notas) + "</div>" : "") + "</td>" +
          "<td>" + esc(l.motivo_label) + "</td>" +
          '<td class="num">' + qty(l.cantidad) + "</td>" +
          '<td class="num">' + money(l.monto) + "</td></tr>"
        );
      })
      .join("");
    var actions = '<button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-claim-copy="' + c.id + '">Copiar texto</button>';
    if (c.proveedor_email) {
      actions += ' <a class="rx-btn rx-btn-sm rx-btn-ghost" href="' + esc(mailtoFor(c)) + '">Enviar por correo</a>';
    }
    if (c.estado === "por_enviar") actions += ' <button type="button" class="rx-btn rx-btn-sm" data-claim-op="enviar" data-claim-id="' + c.id + '">Marcar enviado</button>';
    if (open) {
      actions += ' <button type="button" class="rx-btn rx-btn-sm" data-claim-op="resolver" data-claim-id="' + c.id + '">Resolver con nota de crédito</button>';
      actions += ' <button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-claim-op="descartar" data-claim-id="' + c.id + '">Descartar</button>';
    } else {
      actions += ' <button type="button" class="rx-btn rx-btn-sm rx-btn-ghost" data-claim-op="reabrir" data-claim-id="' + c.id + '">Reabrir</button>';
    }
    var dates = ["Creado " + esc(c.creado)];
    if (c.enviado_en) dates.push("enviado " + esc(c.enviado_en));
    if (c.resuelto_en) dates.push((c.estado === "descartado" ? "descartado " : "resuelto ") + esc(c.resuelto_en));
    return (
      '<article class="rx-claim" data-claim-card="' + c.id + '">' +
      '<header class="rx-claim-head">' +
      "<div><strong>" + esc(c.proveedor) + "</strong> · " +
      (showDoc ? '<a href="#" data-doc-open="' + c.factura_id + '">' + esc(c.documento) + "</a>" : esc(c.documento)) +
      '<div class="rx-sub">' + dates.join(" · ") + (c.nota_credito ? " · " + esc(c.nota_credito) : "") + "</div></div>" +
      stateChip("claim-" + c.estado, c.estado_label) +
      "</header>" +
      '<div class="rx-table-wrap"><table class="rx-table rx-table-compact"><thead><tr><th>Producto</th><th>Motivo</th><th class="num">Cantidad</th><th class="num">Neto</th></tr></thead><tbody>' +
      lines +
      '</tbody><tfoot><tr><td colspan="3" class="num">Total neto estimado</td><td class="num"><strong>' + money(c.total_neto) + "</strong></td></tr></tfoot></table></div>" +
      '<label class="rx-field"><span>Notas internas</span><textarea rows="1" data-claim-notes="' + c.id + '">' + esc(c.notas) + "</textarea></label>" +
      '<div class="rx-claim-actions">' + actions + "</div>" +
      "</article>"
    );
  }

  function claimText(c) {
    var lines = [
      "Reclamo de recepción",
      "Proveedor: " + c.proveedor + (c.rut ? " (" + c.rut + ")" : ""),
      "Documento: " + c.documento + " del " + c.fecha_documento,
      "",
    ];
    c.lineas.forEach(function (l) {
      var code = l.codigo_proveedor ? " (cód. " + l.codigo_proveedor + ")" : "";
      lines.push("- " + l.motivo_label + ": " + l.nombre + code + " · " + qty(l.cantidad) + " un." + (l.notas ? " · " + l.notas : ""));
    });
    lines.push("");
    if (c.total_neto) lines.push("Total neto estimado: " + money(c.total_neto));
    lines.push("Solicitamos la nota de crédito correspondiente. Gracias.");
    return lines.join("\n");
  }

  function mailtoFor(c) {
    return "mailto:" + encodeURIComponent(c.proveedor_email) + "?subject=" + encodeURIComponent("Reclamo " + c.documento) + "&body=" + encodeURIComponent(claimText(c));
  }

  var claimsById = {};

  function rememberClaims(list) {
    (list || []).forEach(function (c) {
      claimsById[c.id] = c;
    });
  }

  function findClaim(id) {
    if (claimsById[id]) return claimsById[id];
    var all = (state.doc && state.doc.claims) || [];
    for (var i = 0; i < all.length; i++) if (all[i].id === id) return all[i];
    return null;
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    var area = document.createElement("textarea");
    area.value = text;
    document.body.appendChild(area);
    area.select();
    try {
      document.execCommand("copy");
    } finally {
      document.body.removeChild(area);
    }
    return Promise.resolve();
  }

  var resolveModal = null;

  function ensureResolveModal() {
    if (resolveModal) return resolveModal;
    var el = document.createElement("div");
    el.className = "rx-modal-overlay";
    el.hidden = true;
    el.innerHTML =
      '<div class="rx-modal" role="dialog" aria-modal="true" aria-labelledby="rx-resolve-title">' +
      '<div class="rx-modal-header"><h3 id="rx-resolve-title">Resolver reclamo</h3><button type="button" class="rx-modal-x" data-close aria-label="Cerrar">×</button></div>' +
      '<div class="rx-modal-body">' +
      '<label class="rx-field"><span>Nota de crédito recibida</span><select class="rx-resolve-nc"></select></label>' +
      '<p class="rx-hint">Si la nota de crédito aún no está cargada en Facturas, puedes resolver sin asociarla.</p>' +
      "</div>" +
      '<div class="rx-modal-footer"><button type="button" class="rx-btn rx-btn-ghost" data-close>Cancelar</button><button type="button" class="rx-btn rx-resolve-submit">Resolver</button></div>' +
      "</div>";
    document.body.appendChild(el);
    el.addEventListener("click", function (e) {
      if (e.target === el || e.target.closest("[data-close]")) el.hidden = true;
    });
    el.querySelector(".rx-resolve-submit").addEventListener("click", function () {
      var nc = Number(el.querySelector(".rx-resolve-nc").value) || 0;
      claimOp(Number(el.dataset.claim), "resolver", { nota_credito_id: nc }, el.querySelector(".rx-resolve-submit")).then(function (ok) {
        if (ok) el.hidden = true;
      });
    });
    resolveModal = el;
    return el;
  }

  function openResolve(claimId) {
    var el = ensureResolveModal();
    el.dataset.claim = claimId;
    var sel = el.querySelector(".rx-resolve-nc");
    sel.innerHTML = '<option value="">Cargando…</option>';
    el.hidden = false;
    post(cfg.actions.creditNotes, { claim_id: claimId })
      .then(function (data) {
        var notes = data.notes || [];
        sel.innerHTML =
          '<option value="">Sin asociar nota de crédito</option>' +
          notes
            .map(function (n, i) {
              return '<option value="' + n.id + '"' + (i === 0 && n.refiere ? " selected" : "") + ">" + esc(n.label) + "</option>";
            })
            .join("");
      })
      .catch(function (err) {
        sel.innerHTML = '<option value="">' + esc(err.message) + "</option>";
      });
  }

  function claimOp(id, op, extra, btn) {
    var data = Object.assign({ claim_id: id, op: op }, extra || {});
    return withBusy(btn, post(cfg.actions.claimUpdate, data))
      .then(function () {
        refreshAfterClaim();
        return true;
      })
      .catch(function (err) {
        fail(err);
        return false;
      });
  }

  function refreshAfterClaim() {
    if (state.view === "claims") loadClaims();
    else if (state.view === "doc" && state.doc) {
      post(cfg.actions.get, { id: state.doc.id })
        .then(function (data) {
          setDoc(data.doc, "reclamos");
        })
        .catch(fail);
    }
  }

  function onClaimClick(e) {
    var docOpen = e.target.closest("[data-doc-open]");
    if (docOpen) {
      e.preventDefault();
      openDoc(Number(docOpen.getAttribute("data-doc-open")), "reclamos");
      return;
    }
    var copy = e.target.closest("[data-claim-copy]");
    if (copy) {
      var c = findClaim(Number(copy.getAttribute("data-claim-copy")));
      if (!c) return;
      copyText(claimText(c)).then(function () {
        copy.textContent = "Copiado ✓";
        setTimeout(function () {
          copy.textContent = "Copiar texto";
        }, 1500);
      });
      return;
    }
    var opBtn = e.target.closest("[data-claim-op]");
    if (!opBtn) return;
    var id = Number(opBtn.getAttribute("data-claim-id"));
    var op = opBtn.getAttribute("data-claim-op");
    if (op === "resolver") {
      openResolve(id);
      return;
    }
    var questions = {
      enviar: "¿Marcar el reclamo como enviado al proveedor?",
      descartar: "¿Descartar el reclamo?\n\nEl stock ya descontado no vuelve; si el producto apareció, regístralo con un conteo.",
      reabrir: "¿Reabrir el reclamo?",
    };
    if (questions[op] && !window.confirm(questions[op])) return;
    claimOp(id, op, {}, opBtn);
  }

  function onClaimNotes(e) {
    var area = e.target.closest("[data-claim-notes]");
    if (!area) return;
    post(cfg.actions.claimUpdate, { claim_id: area.getAttribute("data-claim-notes"), op: "notas", notas: area.value }).catch(fail);
  }

  $("rx-claims-list").addEventListener("click", onClaimClick);
  $("rx-doc-claims").addEventListener("click", onClaimClick);
  $("rx-claims-list").addEventListener("change", onClaimNotes);
  $("rx-doc-claims").addEventListener("change", onClaimNotes);

  $("rx-claim-filters").addEventListener("click", function (e) {
    var chip = e.target.closest(".rx-chip");
    if (!chip) return;
    state.claimFilter = chip.getAttribute("data-estado");
    loadClaims();
  });

  $("rx-claims-back").addEventListener("click", function () {
    showList();
  });

  /* ===================== Inicio ===================== */

  route(readLocation());
})();
