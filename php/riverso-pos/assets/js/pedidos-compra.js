/**
 * Pedidos de compra: armar → cotización → revisión de precios → recepción.
 */
(function () {
  "use strict";

  var cfg = window.riversoPedidos || {};
  var root = document.getElementById("riverso-pc");
  if (!root || !cfg.ajaxUrl) return;

  var VIEWS = ["lista", "armar", "ficha"];
  var MARKS = {
    igual: "Igual",
    precio: "Precio",
    cantidad: "Cantidad",
    solo_pedido: "Solo pedido",
    solo_cotizacion: "Solo cotización",
  };

  var state = {
    view: "lista",
    filter: "",
    search: "",
    needs: [], // necesidades de la bandeja, con el proveedor que la persona eligió
    drafts: {},
    supplierNames: {},
    order: null,
    suppliers: null,
    modalOk: null,
    toastTimer: null,
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
    return (Number(n) || 0).toLocaleString("es-CL", { maximumFractionDigits: 3 });
  }

  function money(n) {
    if (n === null || n === undefined) return "—";
    return "$" + (Number(n) || 0).toLocaleString("es-CL", { maximumFractionDigits: 2 });
  }

  function orDash(n) {
    return n === null || n === undefined ? "—" : qty(n);
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var val = data[k];
      if (val !== null && typeof val === "object") val = JSON.stringify(val);
      body.append(k, val == null ? "" : val);
    });
    return fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || "Error en la solicitud.");
        }
        return res.data || {};
      });
  }

  function errorText(err) {
    if (err && err.name === "TypeError") return "Sin conexión. No se guardó: inténtalo de nuevo.";
    return err && err.message ? err.message : String(err);
  }

  function toast(text, actionLabel, action) {
    var el = $("pc-toast");
    el.innerHTML = "<span>" + esc(text) + "</span>";
    if (actionLabel && action) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "av-toast-action";
      btn.textContent = actionLabel;
      btn.addEventListener("click", function () {
        el.hidden = true;
        action();
      });
      el.appendChild(btn);
    }
    el.hidden = false;
    clearTimeout(state.toastTimer);
    state.toastTimer = setTimeout(function () {
      el.hidden = true;
    }, actionLabel ? 7000 : 3200);
  }

  function copyText(text, doneText) {
    var done = function () {
      toast(doneText || "Copiado");
    };
    var fallback = function () {
      var area = document.createElement("textarea");
      area.value = text;
      area.style.position = "fixed";
      area.style.opacity = "0";
      document.body.appendChild(area);
      area.select();
      try {
        document.execCommand("copy");
      } catch (e) {
        /* sin portapapeles */
      }
      document.body.removeChild(area);
      done();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  }

  function unitOptions(selected, placeholder) {
    var html = placeholder ? '<option value="">' + esc(placeholder) + "</option>" : "";
    Object.keys(cfg.units || {}).forEach(function (key) {
      html += '<option value="' + esc(key) + '"' + (selected === key ? " selected" : "") + ">" + esc(cfg.units[key]) + "</option>";
    });
    return html;
  }

  /**
   * Caja de familia (el código es una caja de N): compras escribe el total de unidades y aquí se
   * muestra cuántas cajas son (o, si se pidió en cajas, cuántas unidades).
   */
  function packHint(cantidadTexto, unidad, envase) {
    var env = Number(envase) || 0;
    if (env <= 1) return "";
    // Misma lectura que el servidor: la coma es decimal ("20.000" = 20, no veinte mil).
    var n = parseFloat(String(cantidadTexto == null ? "" : cantidadTexto).replace(",", "."));
    if (!(n > 0)) return "Caja de " + qty(env) + ": escribe el total de unidades.";
    if (unidad === "unidad") {
      var cajas = n / env;
      var whole = Math.abs(cajas - Math.round(cajas)) < 0.0005;
      return "= " + qty(cajas) + " caja" + (cajas === 1 ? "" : "s") + " de " + qty(env) + (whole ? "" : " ⚠ no calza con cajas enteras");
    }
    return "= " + qty(n * env) + " unidades (cajas de " + qty(env) + ")";
  }

  function quantityText(cantidad, unidadLabel) {
    if (cantidad === null || cantidad === undefined) return "cantidad por definir";
    return qty(cantidad) + " " + (unidadLabel || "");
  }

  function tag(text, kind) {
    return '<span class="av-tag' + (kind ? " av-tag-" + kind : "") + '">' + esc(text) + "</span>";
  }

  /* ===================== Diálogo ===================== */

  function openModal(title, bodyHtml, okLabel, onOk) {
    $("pc-modal-title").textContent = title;
    $("pc-modal-body").innerHTML = bodyHtml;
    modalError("");
    var ok = $("pc-modal-ok");
    ok.textContent = okLabel || "Guardar";
    ok.hidden = !onOk;
    ok.disabled = false;
    state.modalOk = onOk || null;
    $("pc-modal").hidden = false;
    var first = $("pc-modal-body").querySelector("input, select, textarea");
    if (first) first.focus();
  }

  function closeModal() {
    $("pc-modal").hidden = true;
    $("pc-modal-body").innerHTML = "";
    state.modalOk = null;
  }

  function modalError(text) {
    var el = $("pc-modal-error");
    el.textContent = text || "";
    el.hidden = !text;
  }

  /** Corre una acción del diálogo: si resulta, lo cierra y sigue; si no, muestra el error ahí mismo. */
  function runModal(promise, then) {
    var ok = $("pc-modal-ok");
    ok.disabled = true;
    promise
      .then(function (data) {
        closeModal();
        if (then) then(data);
      })
      .catch(function (err) {
        ok.disabled = false;
        modalError(errorText(err));
      });
  }

  /* ===================== Navegación ===================== */

  function setLocation(params, replace) {
    var url = new URL(cfg.baseUrl, window.location.href);
    Object.keys(params || {}).forEach(function (k) {
      if (params[k]) url.searchParams.set(k, params[k]);
    });
    if (url.toString() === window.location.href) return;
    window.history[replace ? "replaceState" : "pushState"]({}, "", url.toString());
  }

  function showView(view) {
    if (view === "armar" && !cfg.canManage) view = "lista";
    state.view = view;
    VIEWS.forEach(function (v) {
      var section = $("pc-view-" + v);
      if (section) section.hidden = v !== view;
    });
    window.scrollTo(0, 0);
  }

  function route(replace) {
    var params = new URL(window.location.href).searchParams;
    var orderId = parseInt(params.get("pedido") || "0", 10);
    if (orderId > 0) {
      showView("ficha");
      loadOrder(orderId);
    } else if (params.get("vista") === "armar" && cfg.canManage) {
      showView("armar");
      loadTray();
    } else {
      showView("lista");
      loadList();
    }
    if (replace) setLocation(orderId > 0 ? { pedido: orderId } : state.view === "armar" ? { vista: "armar" } : {}, true);
  }

  function go(params) {
    setLocation(params);
    route();
  }

  /* ===================== Lista ===================== */

  function loadList() {
    var list = $("pc-list");
    list.innerHTML = '<p class="av-empty">Cargando…</p>';
    var filter = state.filter;
    var search = state.search;
    post(cfg.actions.list, { estado: filter, buscar: search })
      .then(function (data) {
        if (filter !== state.filter || search !== state.search) return;
        renderFilters(data.counts || {});
        renderList(data.pedidos || []);
      })
      .catch(function (err) {
        list.innerHTML = '<p class="av-empty">' + esc(errorText(err)) + "</p>";
      });
  }

  function renderFilters(counts) {
    var html = '<button type="button" class="av-chip' + (state.filter === "" ? " is-active" : "") + '" data-estado="">Todos</button>';
    Object.keys(cfg.states || {}).forEach(function (key) {
      html +=
        '<button type="button" class="av-chip' + (state.filter === key ? " is-active" : "") + '" data-estado="' + esc(key) + '">' +
        esc(cfg.states[key]) + " <span>" + (counts[key] ? counts[key] : "") + "</span></button>";
    });
    $("pc-filters").innerHTML = html;
  }

  function stateTag(estado, label) {
    var kind = { borrador: "warn", aceptada: "ok", recibida: "ok", cancelada: "off" }[estado] || "";
    return tag(label, kind);
  }

  function renderList(orders) {
    var list = $("pc-list");
    if (!orders.length) {
      list.innerHTML = '<p class="av-empty">' + (state.filter || state.search ? "Nada que mostrar." : "Todavía no hay pedidos.") + "</p>";
      return;
    }
    list.innerHTML =
      '<div class="pc-table-wrap"><table class="pc-table pc-table-click"><thead><tr><th>Pedido</th><th>Proveedor</th><th>Estado</th><th>Fecha</th><th class="pc-num">Líneas</th></tr></thead><tbody>' +
      orders
        .map(function (o) {
          return (
            '<tr data-order="' + o.id + '" tabindex="0"><td><strong>' + esc(o.numero) + "</strong>" +
            (o.sin_pedido_previo ? " " + tag("sin pedido previo") : "") + "</td>" +
            "<td>" + esc(o.proveedor_nombre) + "</td>" +
            "<td>" + stateTag(o.estado, o.estado_label) + "</td>" +
            "<td>" + esc(o.fecha) + "</td>" +
            '<td class="pc-num">' + (o.lineas === null ? "" : o.lineas) + "</td></tr>"
          );
        })
        .join("") +
      "</tbody></table></div>";
  }

  /* ===================== Armar ===================== */

  function loadTray() {
    var tray = $("pc-tray");
    if (!tray) return;
    tray.innerHTML = '<p class="av-empty">Cargando…</p>';
    post(cfg.actions.tray, {})
      .then(function (data) {
        state.needs = [];
        state.drafts = {};
        (data.grupos || []).forEach(function (g) {
          state.supplierNames[g.proveedor_id] = g.nombre;
          if (g.borrador) state.drafts[g.proveedor_id] = g.borrador;
          g.necesidades.forEach(function (n) {
            n.elegido = g.proveedor_id; // proveedor elegido: parte de la propuesta y la persona lo cambia
            n.marcada = false;
            n.proveedores.forEach(function (s) {
              state.supplierNames[s.proveedor_id] = s.nombre;
            });
            state.needs.push(n);
          });
        });
        renderTray();
      })
      .catch(function (err) {
        tray.innerHTML = '<p class="av-empty">' + esc(errorText(err)) + "</p>";
      });
  }

  function candidateFor(need, supplierId) {
    for (var i = 0; i < need.proveedores.length; i++) {
      if (need.proveedores[i].proveedor_id === supplierId) return need.proveedores[i];
    }
    return null;
  }

  /** Guarda en la necesidad lo que la persona escribió, para no perderlo al volver a dibujar. */
  function syncNeedInputs() {
    Array.prototype.forEach.call(root.querySelectorAll("#pc-tray .pc-need"), function (row) {
      var need = findNeed(row.getAttribute("data-key"));
      if (!need) return;
      var check = row.querySelector("[data-need-check]");
      var cantidad = row.querySelector("[data-need-qty]");
      var unidad = row.querySelector("[data-need-unit]");
      if (check) need.marcada = check.checked;
      if (cantidad) need.cantidadTexto = cantidad.value;
      if (unidad) need.unidadElegida = unidad.value;
    });
  }

  function findNeed(key) {
    for (var i = 0; i < state.needs.length; i++) {
      if (state.needs[i].clave === key) return state.needs[i];
    }
    return null;
  }

  function renderTray() {
    var tray = $("pc-tray");
    var groups = {};
    var order = [];
    var ensure = function (id) {
      if (!groups[id]) {
        groups[id] = [];
        order.push(id);
      }
    };
    state.needs.forEach(function (n) {
      ensure(n.elegido);
      groups[n.elegido].push(n);
    });
    Object.keys(state.drafts).forEach(function (id) {
      ensure(parseInt(id, 10));
    });
    if (!order.length) {
      tray.innerHTML = '<p class="av-empty">No hay avisos ni alertas por pedir.</p>';
      return;
    }
    order.sort(function (a, b) {
      if ((a === 0) !== (b === 0)) return a === 0 ? 1 : -1;
      return String(state.supplierNames[a] || "").localeCompare(String(state.supplierNames[b] || ""));
    });
    tray.innerHTML = order
      .map(function (id) {
        var needs = groups[id];
        var draft = state.drafts[id];
        var name = id ? state.supplierNames[id] || "Proveedor" : "Sin proveedor";
        var head =
          '<header class="av-group-head"><h2>' + esc(name) + ' <span class="av-badge">' + needs.length + "</span></h2>" +
          (draft
            ? '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-open-order="' + draft.id + '">Borrador ' + esc(draft.numero) + " · " + draft.lineas + " líneas</button>"
            : "") +
          "</header>";
        var foot = "";
        if (needs.length) {
          foot = id
            ? '<div class="pc-group-foot"><button type="button" class="av-link-btn" data-check-all="' + id + '">Marcar todas</button>' +
              '<button type="button" class="av-btn" data-confirm="' + id + '">' + (draft ? "Agregar al borrador " + esc(draft.numero) : "Crear borrador de " + esc(name)) + "</button></div>"
            : '<p class="av-hint">Elige proveedor en cada línea para poder pedirla.</p>';
        }
        return '<section class="av-group pc-group" data-supplier="' + id + '">' + head + needs.map(needRow).join("") + foot + "</section>";
      })
      .join("");
  }

  function needRow(n) {
    var tags = [];
    if (n.nivel === "emparejamiento") tags.push(tag("Emparejado" + (n.grupo_nombre ? ": " + n.grupo_nombre : "")));
    if (n.nivel === "familia") tags.push(tag("Familia"));
    if (n.nivel === "sin_identificar") tags.push(tag("Sin identificar", "warn"));
    if (n.sin_stock) tags.push(tag("No queda", "warn"));
    if (n.alerta) {
      tags.push(tag((n.alerta.critico ? "Stock crítico: " : "Bajo mínimo: ") + qty(n.alerta.stock) + " / mín " + orDash(n.alerta.minimo), "warn"));
      if (n.alerta.dudosa) tags.push(tag("Stock dudoso: sin conteo reciente", "off"));
    }
    if (n.marca) tags.push(tag(n.marca, "warn"));
    if (n.unidades_mezcladas) tags.push(tag("Avisos en unidades distintas", "warn"));

    var who = n.avisos
      .map(function (a) {
        return esc(a.creado_nombre || "") + " · " + esc(a.hace) + (a.cantidad !== null ? " · " + esc(quantityText(a.cantidad, a.unidad_label)) : "") +
          (a.cantidad_confirmada ? " ✓" : "") + (a.nota ? " · «" + esc(a.nota) + "»" : "");
      })
      .join("<br>");
    if (!n.avisos.length) who = "Alerta de stock, sin aviso.";

    var controls;
    if (n.en_pedido) {
      var p = n.en_pedido;
      controls =
        '<div class="pc-need-line">' + tag("Ya en pedido " + p.numero + " · " + p.estado_label, "ok") +
        ' <button type="button" class="av-link-btn" data-open-order="' + p.orden_id + '">Abrir</button>' +
        (p.estado === "borrador" ? ' <button type="button" class="av-link-btn" data-move="' + esc(n.clave) + '">Mover a otro proveedor</button>' : "") +
        "</div>" +
        (n.avisos.length ? '<div class="pc-need-line"><button type="button" class="av-btn av-btn-sm" data-join="' + esc(n.clave) + '">Sumar el aviso a ese pedido</button></div>' : "");
    } else {
      var cand = candidateFor(n, n.elegido);
      // Caja de familia: por defecto el total de unidades (como pide compras).
      var unit = n.unidadElegida !== undefined
        ? n.unidadElegida
        : n.unidad || (cand && cand.envase ? "unidad" : cand && cfg.units[cand.unidad] ? cand.unidad : "");
      var value = n.cantidadTexto !== undefined ? n.cantidadTexto : n.cantidad !== null ? String(n.cantidad).replace(".", ",") : "";
      var options = '<option value="0"' + (n.elegido === 0 ? " selected" : "") + ">Sin proveedor</option>";
      var listed = {};
      n.proveedores.forEach(function (s) {
        listed[s.proveedor_id] = true;
        options +=
          '<option value="' + s.proveedor_id + '"' + (n.elegido === s.proveedor_id ? " selected" : "") + ">" + esc(s.nombre) +
          (s.codigo ? " — " + esc(s.codigo) : "") + (s.factor ? " (×" + qty(s.factor) + ")" : "") + "</option>";
      });
      if (n.elegido && !listed[n.elegido]) {
        options += '<option value="' + n.elegido + '" selected>' + esc(state.supplierNames[n.elegido] || "Proveedor") + "</option>";
      }
      options += '<option value="otro">Otro proveedor…</option>';
      var hint = "";
      if (n.cantidad_fuente === "ultima_compra") hint = "Última compra a este proveedor.";
      else if (n.cantidad_confirmada) hint = "Cantidad confirmada por compras.";
      else if (n.cantidad !== null && n.avisos.filter(function (a) { return a.cantidad !== null; }).length > 1) hint = "Se propone la mayor, no la suma.";
      controls =
        '<div class="pc-need-controls">' +
        '<input type="text" inputmode="decimal" autocomplete="off" placeholder="Cantidad" data-need-qty value="' + esc(value) + '">' +
        '<select data-need-unit aria-label="Unidad">' + unitOptions(unit, "¿en qué?") + "</select>" +
        '<select data-need-supplier aria-label="Proveedor">' + options + "</select>" +
        "</div>" +
        (cand && cand.envase
          ? '<div class="av-sub pc-pack-hint" data-need-pack="' + esc(cand.envase) + '">' + esc(packHint(value, unit, cand.envase)) + "</div>"
          : "") +
        (hint ? '<div class="av-sub">' + esc(hint) + "</div>" : "");
    }

    return (
      '<article class="pc-need" data-key="' + esc(n.clave) + '">' +
      '<label class="pc-need-check"><input type="checkbox" data-need-check' + (n.marcada ? " checked" : "") + (n.elegido && !n.en_pedido ? "" : " disabled") + "></label>" +
      (n.foto ? '<button type="button" class="av-card-photo" data-photo="' + esc(n.foto) + '"><img src="' + esc(n.foto) + '" alt="Foto" loading="lazy"></button>' : "") +
      '<div class="av-card-body"><strong>' + esc(n.titulo) + "</strong>" +
      (n.sku ? '<div class="av-sub">SKU ' + esc(n.sku) + "</div>" : "") +
      (tags.length ? '<div class="av-card-line">' + tags.join(" ") + "</div>" : "") +
      '<div class="av-sub">' + who + "</div>" + controls +
      "</div></article>"
    );
  }

  function loadSuppliers() {
    if (state.suppliers) return Promise.resolve(state.suppliers);
    return post(cfg.actions.suppliers, {}).then(function (data) {
      state.suppliers = data.proveedores || [];
      state.suppliers.forEach(function (s) {
        state.supplierNames[s.id] = s.nombre;
      });
      return state.suppliers;
    });
  }

  function supplierSelect(id, selected) {
    var html = '<select id="' + id + '"><option value="">Elige proveedor</option>';
    (state.suppliers || []).forEach(function (s) {
      html += '<option value="' + s.id + '"' + (selected === s.id ? " selected" : "") + ">" + esc(s.nombre) + "</option>";
    });
    return html + "</select>";
  }

  /** "Otro proveedor…": cualquiera de la lista, aunque el producto todavía no tenga vínculo con él. */
  function chooseOtherSupplier(need) {
    loadSuppliers()
      .then(function () {
        openModal("Proveedor", '<p class="av-sub">' + esc(need.titulo) + "</p>" + supplierSelect("pc-m-supplier", 0), "Elegir", function () {
          var id = parseInt($("pc-m-supplier").value || "0", 10);
          if (!id) {
            modalError("Elige un proveedor.");
            return;
          }
          need.elegido = id;
          closeModal();
          renderTray();
        });
      })
      .catch(function (err) {
        toast(errorText(err));
      });
  }

  function confirmGroup(supplierId) {
    syncNeedInputs();
    var picked = state.needs.filter(function (n) {
      return n.elegido === supplierId && n.marcada && !n.en_pedido;
    });
    if (!picked.length) {
      toast("Marca al menos una línea.");
      return;
    }
    sendNeeds(supplierId, picked);
  }

  function needLine(n, supplierId) {
    var cand = candidateFor(n, supplierId);
    return {
      clave: n.clave,
      aviso_ids: n.avisos.map(function (a) {
        return a.id;
      }),
      producto_base_id: n.producto_base_id,
      pp_id: cand ? cand.pp_id : 0,
      cantidad: n.en_pedido ? "" : n.cantidadTexto !== undefined ? n.cantidadTexto : n.cantidad === null ? "" : n.cantidad,
      unidad: n.unidadElegida !== undefined ? n.unidadElegida : n.unidad || (cand && cfg.units[cand.unidad] ? cand.unidad : ""),
    };
  }

  function sendNeeds(supplierId, needs) {
    var lines = needs.map(function (n) {
      return needLine(n, supplierId);
    });
    post(cfg.actions.confirm, { proveedor_id: supplierId, lineas: lines })
      .then(function (data) {
        var rejected = data.rechazadas || [];
        var taken = lines.length - rejected.length;
        if (rejected.length) {
          var first = findNeed(rejected[0].clave);
          openModal(
            "Algunas líneas no entraron",
            "<ul class=\"pc-list\">" +
              rejected
                .map(function (r) {
                  var need = findNeed(r.clave);
                  return "<li><strong>" + esc(need ? need.titulo : "Línea") + ":</strong> " + esc(r.motivo) + "</li>";
                })
                .join("") +
              "</ul>" + (taken > 0 && data.pedido ? '<p class="av-sub">Las otras ' + taken + " quedaron en el pedido " + esc(data.pedido.numero) + ".</p>" : ""),
            "",
            null
          );
          if (first) first.marcada = false;
        } else {
          toast(taken + (taken === 1 ? " línea" : " líneas") + " en el pedido " + data.pedido.numero, "Abrir", function () {
            go({ pedido: data.pedido.id });
          });
        }
        loadTray();
      })
      .catch(function (err) {
        toast(errorText(err));
      });
  }

  /** Emparejado ya puesto en un borrador: pasarlo al de otro proveedor lo saca del anterior. */
  function moveNeed(need) {
    loadSuppliers()
      .then(function () {
        var options = "";
        need.proveedores.forEach(function (s) {
          if (s.proveedor_id === need.en_pedido.proveedor_id) return;
          options += '<option value="' + s.proveedor_id + ":" + s.pp_id + '">' + esc(s.nombre) + (s.codigo ? " — " + esc(s.codigo) : "") + "</option>";
        });
        (state.suppliers || []).forEach(function (s) {
          if (s.id === need.en_pedido.proveedor_id || candidateFor(need, s.id)) return;
          options += '<option value="' + s.id + ':0">' + esc(s.nombre) + "</option>";
        });
        openModal(
          "Mover a otro proveedor",
          '<p class="av-sub">' + esc(need.titulo) + " · hoy en el pedido " + esc(need.en_pedido.numero) + " de " + esc(need.en_pedido.proveedor_nombre) + "</p>" +
            '<select id="pc-m-move">' + options + "</select>",
          "Mover",
          function () {
            var parts = String($("pc-m-move").value || "").split(":");
            runModal(
              post(cfg.actions.moveLine, { linea_id: need.en_pedido.item_id, proveedor_id: parts[0], pp_id: parts[1] || 0 }),
              function (data) {
                toast("Movida al borrador " + data.pedido.numero + " de " + data.pedido.proveedor_nombre);
                loadTray();
              }
            );
          }
        );
      })
      .catch(function (err) {
        toast(errorText(err));
      });
  }

  /* ===================== Ficha ===================== */

  function loadOrder(id) {
    $("pc-order").innerHTML = '<p class="av-empty">Cargando…</p>';
    post(cfg.actions.get, { id: id })
      .then(function (data) {
        setOrder(data.pedido);
      })
      .catch(function (err) {
        $("pc-order").innerHTML = '<p class="av-empty">' + esc(errorText(err)) + "</p>";
      });
  }

  function setOrder(order) {
    state.order = order;
    renderOrder();
  }

  /** Acción sobre el pedido abierto: el servidor devuelve la ficha actualizada. */
  function orderAction(action, data, doneText) {
    return post(action, data)
      .then(function (res) {
        if (res.pedido && state.order && res.pedido.id === state.order.id) setOrder(res.pedido);
        if (doneText) toast(doneText);
        return res;
      })
      .catch(function (err) {
        toast(errorText(err));
        throw err;
      });
  }

  function renderOrder() {
    var o = state.order;
    if (!o) return;
    var actions = "";
    if (cfg.canManage) {
      if (o.puede.editar) actions += '<button type="button" class="av-btn av-btn-ghost" data-order-act="agregar">Agregar línea</button>';
      if (o.puede.enviar) actions += '<button type="button" class="av-btn" data-order-act="enviar">Enviar pedido</button>';
      if (!o.puede.editar && o.estado !== "cancelada") actions += '<button type="button" class="av-btn av-btn-ghost" data-order-act="texto">Copiar pedido</button>';
      if (o.puede.cancelar) actions += '<button type="button" class="av-btn av-btn-danger" data-order-act="cancelar">' + (o.puede.editar ? "Descartar borrador" : "Cancelar pedido") + "</button>";
    }
    $("pc-order").innerHTML =
      '<header class="pc-order-head"><div><h2>Pedido ' + esc(o.numero) + " " + stateTag(o.estado, o.estado_label) +
      (o.sin_pedido_previo ? " " + tag("sin pedido previo") : "") + "</h2>" +
      '<div class="pc-order-supplier">' + esc(o.proveedor_nombre) + (o.enviado_en ? ' <span class="av-sub">· enviado el ' + esc(o.enviado_en.substr(0, 10)) + "</span>" : "") + "</div></div>" +
      '<div class="pc-order-actions">' + actions + "</div></header>" +
      zone("1", "Pedido", "Lo que le pedimos al proveedor.", zonePedido(o)) +
      zone("2", "Cotización", "Lo que el proveedor contesta. Puede no calzar con lo pedido.", zoneCotizacion(o)) +
      zone("3", "Revisión de precios", "El correo al proveedor, solo si corresponde.", zoneRevision(o)) +
      zone("4", "Recepción", "Lo aceptado es lo que se espera. El stock sube en Recepción, no aquí.", zoneRecepcion(o));
  }

  function zone(number, title, hint, body) {
    return (
      '<section class="pc-zone"><header class="pc-zone-head"><span class="pc-zone-n">' + number + "</span><div><h3>" + esc(title) +
      '</h3><div class="av-sub">' + esc(hint) + "</div></div></header>" + body + "</section>"
    );
  }

  function findLine(id) {
    var lines = (state.order && state.order.lineas) || [];
    for (var i = 0; i < lines.length; i++) if (lines[i].id === id) return lines[i];
    return null;
  }

  /* ---------- Zona 1: Pedido ---------- */

  function zonePedido(o) {
    if (!o.lineas.length) return '<p class="av-empty">El pedido no tiene líneas.</p>';
    var canDrop = cfg.canManage && ["enviada", "en_cotizacion", "en_revision"].indexOf(o.estado) !== -1;
    return (
      '<div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>Código</th><th>Producto</th><th class="pc-num">Cantidad</th><th class="pc-num">Precio</th><th></th></tr></thead><tbody>' +
      o.lineas
        .map(function (l) {
          var extra = [];
          if (!l.identificado) extra.push(tag("Sin identificar", "warn"));
          if (l.nivel === "emparejamiento") extra.push(tag("Emparejado"));
          if (l.nivel === "familia") extra.push(tag("Familia"));
          if (l.origen !== "aviso") extra.push(tag(l.origen_label));
          if (l.estado === "no_comprada") extra.push(tag("No se compra", "off"));
          var who = l.avisos
            .map(function (a) {
              return esc(a.creado_nombre) + " · " + esc(a.hace);
            })
            .join(", ");
          var acts = "";
          if (cfg.canManage && o.puede.editar) {
            acts =
              '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-line-act="editar" data-line="' + l.id + '">Editar</button> ' +
              '<button type="button" class="av-btn av-btn-sm av-btn-danger" data-line-act="quitar" data-line="' + l.id + '">Quitar</button>';
          } else if (canDrop && l.estado === "pendiente" && !o.cotizacion.vinculada) {
            acts = '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-line-act="no-comprar" data-line="' + l.id + '">No se compra</button>';
          }
          return (
            '<tr class="' + (l.estado === "no_comprada" ? "pc-row-off" : "") + '"><td>' + esc(l.codigo_proveedor || "—") + "</td>" +
            '<td><div class="pc-prod">' +
            (l.foto ? '<button type="button" class="av-card-photo pc-thumb" data-photo="' + esc(l.foto) + '"><img src="' + esc(l.foto) + '" alt="Foto" loading="lazy"></button>' : "") +
            "<div><strong>" + esc(l.titulo) + "</strong>" + (l.sku ? ' <span class="av-sub">SKU ' + esc(l.sku) + "</span>" : "") +
            (extra.length ? '<div class="av-card-line">' + extra.join(" ") + "</div>" : "") +
            (who ? '<div class="av-sub">Avisó: ' + who + "</div>" : "") +
            "</div></div></td>" +
            '<td class="pc-num">' + esc(quantityText(l.cantidad, l.unidad_label)) +
            (l.envase && l.cantidad !== null
              ? '<div class="av-sub">' + esc(packHint(l.cantidad, l.unidad, l.envase)) + "</div>"
              : l.equivalencia ? '<div class="av-sub">' + qty(l.equivalencia) + " unidades</div>" : "") +
            "</td>" +
            '<td class="pc-num">' + money(l.precio) + "</td>" +
            '<td class="pc-acts">' + acts + "</td></tr>"
          );
        })
        .join("") +
      "</tbody></table></div>"
    );
  }

  function dialogEditLine(line) {
    openModal(
      "Editar línea",
      '<p class="av-sub">' + esc(line.titulo) + "</p>" +
        (line.identificado ? "" : '<label for="pc-m-desc">Descripción</label><input type="text" id="pc-m-desc" maxlength="255" value="' + esc(line.titulo) + '">') +
        '<label for="pc-m-qty">Cantidad</label><div class="av-qty-row"><input type="text" id="pc-m-qty" inputmode="decimal" autocomplete="off" value="' +
        (line.cantidad !== null ? esc(String(line.cantidad).replace(".", ",")) : "") + '"><select id="pc-m-unit" aria-label="Unidad">' + unitOptions(line.unidad, "¿en qué?") + "</select></div>" +
        (line.envase ? '<div class="av-sub pc-pack-hint" id="pc-m-pack"></div>' : "") +
        '<label for="pc-m-price">Precio unitario <span class="av-sub">Opcional</span></label><input type="text" id="pc-m-price" inputmode="decimal" autocomplete="off" value="' +
        (line.precio !== null ? esc(String(line.precio).replace(".", ",")) : "") + '">',
      "Guardar",
      function () {
        var data = { linea_id: line.id, cantidad: $("pc-m-qty").value.trim(), unidad: $("pc-m-unit").value, precio: $("pc-m-price").value.trim() };
        if ($("pc-m-desc")) data.descripcion = $("pc-m-desc").value.trim();
        runModal(post(cfg.actions.updateLine, data), function (res) {
          setOrder(res.pedido);
        });
      }
    );
    bindModalPackHint(line.envase);
  }

  /** Aviso «= N cajas» bajo la cantidad del modal (agregar/editar línea). */
  function bindModalPackHint(envase) {
    var box = $("pc-m-pack");
    if (!box) return;
    var paint = function () {
      box.textContent = packHint($("pc-m-qty").value, $("pc-m-unit").value, envase);
    };
    $("pc-m-qty").addEventListener("input", paint);
    $("pc-m-unit").addEventListener("change", paint);
    paint();
  }

  function dialogAddLine() {
    var o = state.order;
    var found = [];
    var picked = null;
    openModal(
      "Agregar línea",
      '<div class="av-search"><input type="text" id="pc-m-q" placeholder="Código, SKU o nombre" autocomplete="off"><button type="button" class="av-btn" id="pc-m-search">Buscar</button></div>' +
        '<div class="av-candidates" id="pc-m-results"></div>' +
        '<div id="pc-m-picked" class="av-product" hidden></div>' +
        '<label for="pc-m-desc">O descríbelo, si no tiene código</label><input type="text" id="pc-m-desc" maxlength="255" placeholder="Ej: hilo 1/4 métrico">' +
        '<label for="pc-m-qty">Cantidad</label><div class="av-qty-row"><input type="text" id="pc-m-qty" inputmode="decimal" autocomplete="off"><select id="pc-m-unit" aria-label="Unidad">' + unitOptions("", "¿en qué?") + "</select></div>" +
        '<div class="av-sub pc-pack-hint" id="pc-m-pack" hidden></div>',
      "Agregar",
      function () {
        var data = { id: o.id, cantidad: $("pc-m-qty").value.trim(), unidad: $("pc-m-unit").value };
        if (picked) {
          data.producto_base_id = picked.producto_base_id;
          var cand = null;
          (picked.proveedores || []).forEach(function (s) {
            if (s.proveedor_id === o.proveedor_id) cand = s;
          });
          if (cand) data.pp_id = cand.pp_id;
        } else {
          data.descripcion = $("pc-m-desc").value.trim();
        }
        runModal(post(cfg.actions.addLine, data), function (res) {
          setOrder(res.pedido);
        });
      }
    );
    var run = function () {
      var q = $("pc-m-q").value.trim();
      if (!q) return;
      $("pc-m-results").innerHTML = '<p class="av-empty">Buscando…</p>';
      post(cfg.actions.resolve, { q: q, proveedor_id: o.proveedor_id })
        .then(function (data) {
          found = (data.candidatos || []).filter(function (c) {
            return c.producto_base_id > 0;
          });
          $("pc-m-results").innerHTML = found.length
            ? found
                .map(function (c, i) {
                  return '<button type="button" class="av-cand" data-pick="' + i + '"><span class="av-cand-text"><strong>' + esc(c.nombre) + '</strong><span class="av-sub">SKU ' + esc(c.sku) + "</span></span></button>";
                })
                .join("")
            : '<p class="av-empty">Sin resultados.</p>';
        })
        .catch(function (err) {
          modalError(errorText(err));
        });
    };
    $("pc-m-search").addEventListener("click", run);
    $("pc-m-q").addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        run();
      }
    });
    $("pc-m-results").addEventListener("click", function (e) {
      var btn = e.target.closest("[data-pick]");
      if (!btn) return;
      picked = found[parseInt(btn.getAttribute("data-pick"), 10)] || null;
      if (!picked) return;
      $("pc-m-results").innerHTML = "";
      $("pc-m-picked").hidden = false;
      $("pc-m-picked").innerHTML = "<strong>" + esc(picked.nombre) + '</strong> <span class="av-sub">SKU ' + esc(picked.sku) + "</span>";
      $("pc-m-desc").value = "";
      $("pc-m-desc").disabled = true;
      var cand = null;
      (picked.proveedores || []).forEach(function (s) {
        if (s.proveedor_id === o.proveedor_id) cand = s;
      });
      if (cand && cand.envase) {
        $("pc-m-unit").value = "unidad";
        $("pc-m-pack").hidden = false;
        bindModalPackHint(cand.envase);
      } else if (cand && cfg.units[cand.unidad]) {
        $("pc-m-unit").value = cand.unidad;
      }
      $("pc-m-qty").focus();
    });
  }

  function showOrderText(text, title) {
    openModal(
      title,
      '<p class="av-sub">Cópialo al correo, el portal o el WhatsApp del proveedor. El sistema no lo envía.</p><textarea id="pc-m-text" rows="12" readonly>' + esc(text) + "</textarea>",
      "Copiar",
      function () {
        copyText($("pc-m-text").value, "Pedido copiado");
      }
    );
  }

  /* ---------- Zona 2: Cotización ---------- */

  function zoneCotizacion(o) {
    var c = o.cotizacion;
    if (!c.vinculada) {
      if (o.estado === "borrador") return '<p class="av-empty">Envía el pedido primero.</p>';
      if (!o.puede.cotizar) return '<p class="av-empty">Este pedido no tiene cotización.</p>';
      var upload = '<a class="av-btn av-btn-sm av-btn-ghost" href="' + esc(cfg.quotesUrl) + '" target="_blank" rel="noopener">Cargar en Cotizaciones Proveedores ↗</a>';
      if (!c.candidatas.length) {
        return '<p class="av-empty">Todavía no hay cotización de este proveedor sin pedido.</p><div class="pc-zone-foot">' + upload +
          ' <button type="button" class="av-btn av-btn-sm av-btn-ghost" data-order-act="recargar">Actualizar</button></div>';
      }
      return (
        '<ul class="pc-list">' +
        c.candidatas
          .map(function (q) {
            return (
              "<li><strong>" + esc(q.numero) + "</strong> · " + esc(q.fecha || "sin fecha") + " · " + q.items + " líneas" + (q.versiones > 1 ? " · " + q.versiones + " versiones" : "") +
              (cfg.canDecide ? ' <button type="button" class="av-btn av-btn-sm" data-link-quote="' + q.id + '">Vincular</button>' : "") + "</li>"
            );
          })
          .join("") +
        '</ul><div class="pc-zone-foot">' + upload + "</div>"
      );
    }

    var unmatchedQuote = c.filas.filter(function (r) {
      return r.tipo === "solo_cotizacion";
    });
    var head =
      '<div class="pc-zone-bar"><div><strong>' + esc(c.numero) + "</strong>" + (c.versiones > 1 ? " · versión " + c.version + " de " + c.versiones : "") +
      (c.fecha ? ' <span class="av-sub">· ' + esc(c.fecha) + "</span>" : "") + "</div><div>" +
      '<a class="av-link-btn" href="' + esc(cfg.quotesUrl + "?quote=" + c.id) + '" target="_blank" rel="noopener">Abrir cotización ↗</a>' +
      (c.abierta && cfg.canDecide ? ' <button type="button" class="av-link-btn" data-order-act="desvincular">Desvincular</button>' : "") +
      "</div></div>";

    var rows = c.filas
      .map(function (r) {
        var marks = r.marcas
          .map(function (m) {
            return tag(MARKS[m] || m, m === "igual" ? "ok" : "warn");
          })
          .join(" ");
        var ours = r.linea
          ? "<strong>" + esc(r.linea.titulo) + "</strong>" + (r.linea.identificado ? "" : " " + tag("Sin identificar", "warn")) +
            '<div class="av-sub">' + esc(r.linea.codigo || "sin código") + " · " + esc(quantityText(r.linea.cantidad || null, r.linea.unidad_label)) + "</div>"
          : '<span class="av-sub">No la pedimos</span>';
        var theirs = '<span class="av-sub">No vino</span>';
        if (r.cot) {
          var price = r.decision === "add" ? "" : " · " + money(r.cot.costo);
          var delta = "";
          if (r.cot.sube) delta = ' <span class="pc-up">▲ ' + money(r.cot.diferencia) + " (antes " + money(r.cot.costo_anterior) + ")</span>";
          else if (r.cot.baja) delta = ' <span class="pc-down">▼ ' + money(Math.abs(r.cot.diferencia)) + " (antes " + money(r.cot.costo_anterior) + ")</span>";
          theirs =
            "<strong>" + esc(r.cot.descripcion) + "</strong>" +
            '<div class="av-sub">' + esc(r.cot.codigo || "sin código") + " · " + qty(r.cot.cantidad) + " " + esc(r.cot.unidad) + price + delta + "</div>";
        }
        var decision = '<div class="pc-decision">' + tag(r.decision_label, r.decidida ? (r.decision === "accepted" || r.decision === "accepted_increase" ? "ok" : "") : "warn") + "</div>";
        var buttons = "";
        if (c.abierta && cfg.canDecide) {
          buttons = r.opciones
            .filter(function (op) {
              return op.valor !== r.decision || !r.decidida;
            })
            .map(function (op) {
              return (
                '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-decide="' + esc(op.valor) + '" data-row-line="' + (r.linea ? r.linea.id : 0) +
                '" data-row-cot="' + (r.cot ? r.cot.id : 0) + '">' + esc(op.label) + "</button>"
              );
            })
            .join(" ");
          if (r.tipo === "solo_pedido" && !r.cot && unmatchedQuote.length && r.linea.estado !== "no_comprada") {
            buttons += ' <button type="button" class="av-btn av-btn-sm av-btn-ghost" data-match="' + r.linea.id + '">Calzar con una línea…</button>';
          }
          if (r.linea && r.cot && r.decision !== "add") {
            buttons += ' <button type="button" class="av-link-btn" data-unmatch="' + r.linea.id + '">Soltar calce</button>';
          }
        }
        return '<tr class="' + (r.decidida ? "" : "pc-row-pending") + '"><td>' + marks + "</td><td>" + ours + "</td><td>" + theirs + "</td><td>" + decision + '<div class="pc-acts">' + buttons + "</div></td></tr>";
      })
      .join("");

    var foot = "";
    if (c.abierta) {
      foot =
        '<div class="pc-zone-foot">' +
        (c.bloqueo ? '<span class="pc-block">' + esc(c.bloqueo) + "</span>" : '<span class="av-sub">Todas las diferencias tienen decisión.</span>') +
        (cfg.canApprove ? ' <button type="button" class="av-btn" data-order-act="aceptar"' + (c.puede_aceptar ? "" : " disabled") + ">Aceptar cotización</button>" : "") +
        "</div>";
    }
    return (
      head +
      '<div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>Marca</th><th>Pedimos</th><th>Cotizan</th><th>Decisión</th></tr></thead><tbody>' + rows + "</tbody></table></div>" + foot
    );
  }

  function dialogMatch(lineId) {
    var c = state.order.cotizacion;
    var options = c.filas
      .filter(function (r) {
        return r.tipo === "solo_cotizacion";
      })
      .map(function (r) {
        return '<option value="' + r.cot.id + '">' + esc((r.cot.codigo ? r.cot.codigo + " — " : "") + r.cot.descripcion + " · " + qty(r.cot.cantidad) + " " + r.cot.unidad) + "</option>";
      })
      .join("");
    var line = findLine(lineId);
    openModal(
      "Calzar con una línea de la cotización",
      '<p class="av-sub">' + esc(line ? line.titulo : "") + '</p><select id="pc-m-match">' + options + "</select>" +
        (line && !line.identificado ? '<p class="av-sub">La línea no tiene producto: toma el de la cotización.</p>' : ""),
      "Calzar",
      function () {
        runModal(post(cfg.actions.match, { linea_id: lineId, cot_item_id: $("pc-m-match").value }), function (res) {
          setOrder(res.pedido);
        });
      }
    );
  }

  /* ---------- Zona 3: Revisión de precios ---------- */

  function zoneRevision(o) {
    var c = o.cotizacion;
    var note = '<p class="av-sub">El precio de venta no se revisa aquí: se revisa al procesar el folio de la factura.</p>';
    if (!c.vinculada) return '<p class="av-empty">Sin cotización todavía.</p>';
    if (!c.abierta && !c.revision_enviada_en) {
      return '<p class="av-empty">' + (o.estado === "cancelada" ? "Pedido cancelado." : "La cotización se aceptó sin mandar revisión.") + "</p>" + note;
    }
    if (c.pendientes > 0 && !c.revision_enviada_en) {
      return '<p class="av-empty">Decide primero las diferencias de la cotización.</p>' + note;
    }
    if (c.para_revision === 0) {
      return '<p class="av-empty">No corresponde: no hay nada que pedirle al proveedor.</p>' + note;
    }
    var status = c.revision_enviada_en
      ? tag("Revisión enviada el " + c.revision_enviada_en.substr(0, 10), "ok") + (c.abierta && c.esperando > 0 ? " " + tag("Esperando respuesta del proveedor", "warn") : "")
      : tag("Revisión por mandar", "warn");
    return (
      '<div class="pc-zone-bar"><div>' + status + ' <span class="av-sub">' + c.para_revision + (c.para_revision === 1 ? " línea" : " líneas") + " para revisar con el proveedor.</span></div>" +
      (c.abierta && cfg.canDecide ? '<div><button type="button" class="av-btn" data-order-act="revision">Preparar revisión</button></div>' : "") + "</div>" +
      '<p class="av-sub">Si el proveedor contesta con otra cotización, cárgala como versión nueva: el pedido vuelve a "en cotización" contra ella. Si contesta sin documento, cambia la decisión de la línea.</p>' + note
    );
  }

  /** El borrador es el mismo de Cotizaciones Proveedores: precios anteriores, quitar, agregar y cantidades. */
  function dialogRevision() {
    var o = state.order;
    var body = new FormData();
    body.append("action", cfg.claimDraftAction);
    body.append("nonce", cfg.posNonce);
    body.append("id", o.cotizacion.id);
    body.append("compare_base", "auto");
    fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || "No se pudo preparar la revisión.");
        var sent = !!o.cotizacion.revision_enviada_en;
        openModal(
          "Revisión de precios",
          '<p class="av-sub">Cópiala y mándala por el canal de siempre. El sistema no la envía.</p>' +
            '<label for="pc-m-subject">Asunto</label><input type="text" id="pc-m-subject" value="' + esc(res.data.subject || "Revisión de precios") + '">' +
            '<label for="pc-m-body">Mensaje</label><textarea id="pc-m-body" rows="14">' + esc(res.data.simple || res.data.draft || "") + "</textarea>" +
            '<div class="pc-acts"><button type="button" class="av-btn av-btn-ghost" id="pc-m-copy">Copiar</button></div>',
          sent ? "" : "Marcar revisión enviada",
          sent
            ? null
            : function () {
                runModal(post(cfg.actions.revisionSent, { id: o.id }), function (data) {
                  setOrder(data.pedido);
                  toast("Revisión marcada como enviada");
                });
              }
        );
        $("pc-m-copy").addEventListener("click", function () {
          copyText("Asunto: " + $("pc-m-subject").value + "\n\n" + $("pc-m-body").value, "Revisión copiada");
        });
      })
      .catch(function (err) {
        toast(errorText(err));
      });
  }

  /* ---------- Zona 4: Recepción ---------- */

  function zoneRecepcion(o) {
    var r = o.recepcion;
    if (!r.espera) return '<p class="av-empty">' + (o.estado === "cancelada" ? "Pedido cancelado." : "El pedido todavía no espera mercadería.") + "</p>";
    var docs = "";
    if (r.documentos.length) {
      docs =
        '<ul class="pc-list">' +
        r.documentos
          .map(function (d) {
            return (
              "<li><strong>" + esc(d.label) + "</strong> · " + esc(d.fecha) + " · " + tag(d.estado_label, d.cerrado ? "ok" : "warn") +
              ' <span class="av-sub">' + esc(d.origen_label) + "</span>" +
              (cfg.canReceive ? ' <a class="av-btn av-btn-sm" href="' + esc(cfg.receptionUrl + "?doc=" + d.id) + '" target="_blank" rel="noopener">' + (d.cerrado ? "Ver en Recepción ↗" : "Recibir ↗") + "</a>" : "") +
              (cfg.canManage ? ' <button type="button" class="av-link-btn" data-unlink-doc="' + d.id + '">Desvincular</button>' : "") + "</li>"
            );
          })
          .join("") +
        "</ul>";
    } else {
      docs = '<p class="av-empty">Esperando factura o guía. Sin documento no hay nada que recibir.</p>';
    }
    var proposals = "";
    if (r.propuestos.length && cfg.canManage) {
      proposals =
        '<div class="pc-proposals"><div class="av-sub">' + (r.documentos.length ? "Otros documentos de este proveedor sin pedido:" : "Documentos de este proveedor sin pedido:") + '</div><ul class="pc-list">' +
        r.propuestos
          .slice(0, 6)
          .map(function (d) {
            return (
              "<li><strong>" + esc(d.label) + "</strong> · " + esc(d.fecha) +
              (d.calzan ? " · " + tag("trae " + d.calzan + (d.calzan === 1 ? " código" : " códigos") + " del pedido", "ok") : ' <span class="av-sub">no trae códigos del pedido</span>') +
              ' <button type="button" class="av-btn av-btn-sm av-btn-ghost" data-link-doc="' + d.id + '">Vincular</button></li>'
            );
          })
          .join("") +
        "</ul></div>";
    }
    var rows = r.filas
      .map(function (f) {
        var kind = f.estado === "cerrada" ? (f.resto > 0 ? "warn" : "ok") : f.estado === "no_comprada" ? "off" : "";
        var rest = "";
        if (f.resto > 0) {
          rest = f.resto_pedido
            ? tag("Resto ya pedido", "ok")
            : cfg.canManage
              ? '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-rest="' + f.linea_id + '">Pedir el resto (' + qty(f.resto) + ")</button>"
              : tag("Faltan " + qty(f.resto), "warn");
        }
        return (
          '<tr class="' + (f.estado === "no_comprada" ? "pc-row-off" : "") + '"><td><strong>' + esc(f.titulo) + '</strong><div class="av-sub">' + esc(f.codigo || "sin código") + " · " + esc(f.unidad_label) + "</div></td>" +
          '<td class="pc-num">' + orDash(f.pedido) + '</td><td class="pc-num">' + orDash(f.cotizado) + '</td><td class="pc-num">' + orDash(f.aceptado) + '</td><td class="pc-num"><strong>' + qty(f.recibido) + "</strong></td>" +
          "<td>" + tag(f.estado_label, kind) + " " + rest + "</td></tr>"
        );
      })
      .join("");
    return (
      docs + proposals +
      '<div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>Producto</th><th class="pc-num">Pedido</th><th class="pc-num">Cotizado</th><th class="pc-num">Aceptado</th><th class="pc-num">Recibido</th><th>Estado</th></tr></thead><tbody>' +
      rows + "</tbody></table></div>"
    );
  }

  function dialogRest(lineId) {
    var o = state.order;
    loadSuppliers()
      .then(function () {
        openModal(
          "Pedir el resto",
          '<p class="av-sub">Queda una línea nueva en el borrador del proveedor que elijas, apuntando a esta.</p>' + supplierSelect("pc-m-supplier", o.proveedor_id),
          "Pedir",
          function () {
            var id = parseInt($("pc-m-supplier").value || "0", 10);
            if (!id) {
              modalError("Elige un proveedor.");
              return;
            }
            runModal(post(cfg.actions.rest, { linea_id: lineId, proveedor_id: id }), function (data) {
              toast("Resto en el borrador " + data.pedido.numero, "Abrir", function () {
                go({ pedido: data.pedido.id });
              });
              loadOrder(o.id);
            });
          }
        );
      })
      .catch(function (err) {
        toast(errorText(err));
      });
  }

  /* ===================== Eventos ===================== */

  function onOrderAct(act) {
    var o = state.order;
    if (!o) return;
    if (act === "agregar") dialogAddLine();
    else if (act === "recargar") loadOrder(o.id);
    else if (act === "enviar") {
      if (!window.confirm("¿Enviar el pedido? Desde aquí las líneas pedidas ya no se reescriben.")) return;
      orderAction(cfg.actions.send, { id: o.id }).then(function (res) {
        showOrderText(res.texto, "Pedido enviado: copia el texto");
      }, function () {});
    } else if (act === "texto") {
      post(cfg.actions.text, { id: o.id }).then(function (res) {
        showOrderText(res.texto, "Pedido " + o.numero);
      }, function (err) {
        toast(errorText(err));
      });
    } else if (act === "cancelar") {
      if (!window.confirm(o.puede.editar ? "¿Descartar el borrador? Sus avisos vuelven a la bandeja." : "¿Cancelar el pedido? Sus avisos vuelven a la bandeja.")) return;
      orderAction(cfg.actions.cancel, { id: o.id }, "Pedido cancelado").catch(function () {});
    } else if (act === "desvincular") {
      if (!window.confirm("¿Desvincular la cotización? Se pierden los calces y lo aceptado en este pedido.")) return;
      orderAction(cfg.actions.unlinkQuote, { id: o.id }).catch(function () {});
    } else if (act === "aceptar") {
      if (!window.confirm("¿Aceptar la cotización? Lo aceptado queda como lo que Recepción espera.")) return;
      orderAction(cfg.actions.accept, { id: o.id }, "Cotización aceptada").catch(function () {});
    } else if (act === "revision") dialogRevision();
  }

  root.addEventListener("click", function (e) {
    var t = e.target;
    var nav = t.closest("[data-nav]");
    if (nav) return go({});
    if (t.id === "pc-armar-btn") return go({ vista: "armar" });
    var chip = t.closest("#pc-filters .av-chip");
    if (chip) {
      state.filter = chip.getAttribute("data-estado");
      return loadList();
    }
    var row = t.closest("[data-order]");
    if (row) return go({ pedido: row.getAttribute("data-order") });
    var open = t.closest("[data-open-order]");
    if (open) return go({ pedido: open.getAttribute("data-open-order") });
    var photo = t.closest("[data-photo]");
    if (photo) return openModal("Foto del aviso", '<img class="av-modal-photo" src="' + esc(photo.getAttribute("data-photo")) + '" alt="Foto del aviso">', "", null);

    // Armar
    var confirmBtn = t.closest("[data-confirm]");
    if (confirmBtn) return confirmGroup(parseInt(confirmBtn.getAttribute("data-confirm"), 10));
    var all = t.closest("[data-check-all]");
    if (all) {
      syncNeedInputs();
      var supplier = parseInt(all.getAttribute("data-check-all"), 10);
      state.needs.forEach(function (n) {
        if (n.elegido === supplier) n.marcada = true;
      });
      return renderTray();
    }
    var join = t.closest("[data-join]");
    if (join) {
      // Un aviso de algo que ya está pedido se suma a esa línea: queda "ingresado" sin pedirlo dos veces.
      var joined = findNeed(join.getAttribute("data-join"));
      if (joined) sendNeeds(joined.en_pedido.proveedor_id, [joined]);
      return;
    }
    var move = t.closest("[data-move]");
    if (move) {
      var need = findNeed(move.getAttribute("data-move"));
      if (need) moveNeed(need);
      return;
    }

    // Ficha
    var orderAct = t.closest("[data-order-act]");
    if (orderAct) return onOrderAct(orderAct.getAttribute("data-order-act"));
    var lineAct = t.closest("[data-line-act]");
    if (lineAct) {
      var line = findLine(parseInt(lineAct.getAttribute("data-line"), 10));
      var act = lineAct.getAttribute("data-line-act");
      if (!line) return;
      if (act === "editar") dialogEditLine(line);
      else if (act === "quitar") {
        if (!window.confirm("¿Quitar «" + line.titulo + "»? Sus avisos vuelven a la bandeja.")) return;
        orderAction(cfg.actions.removeLine, { linea_id: line.id }).catch(function () {});
      } else if (act === "no-comprar") {
        if (!window.confirm("¿Dejar «" + line.titulo + "» sin comprar? La necesidad vuelve a la bandeja.")) return;
        orderAction(cfg.actions.dropLine, { linea_id: line.id }).catch(function () {});
      }
      return;
    }
    var linkQuote = t.closest("[data-link-quote]");
    if (linkQuote) return void orderAction(cfg.actions.linkQuote, { id: state.order.id, cotizacion_id: linkQuote.getAttribute("data-link-quote") }).catch(function () {});
    var decide = t.closest("[data-decide]");
    if (decide) {
      decide.disabled = true;
      return void orderAction(cfg.actions.decide, {
        id: state.order.id,
        linea_id: decide.getAttribute("data-row-line"),
        cot_item_id: decide.getAttribute("data-row-cot"),
        decision: decide.getAttribute("data-decide"),
      }).catch(function () {
        decide.disabled = false;
      });
    }
    var match = t.closest("[data-match]");
    if (match) return dialogMatch(parseInt(match.getAttribute("data-match"), 10));
    var unmatch = t.closest("[data-unmatch]");
    if (unmatch) return void orderAction(cfg.actions.match, { linea_id: unmatch.getAttribute("data-unmatch"), cot_item_id: 0 }).catch(function () {});
    var linkDoc = t.closest("[data-link-doc]");
    if (linkDoc) return void orderAction(cfg.actions.linkDoc, { id: state.order.id, factura_id: linkDoc.getAttribute("data-link-doc"), origen: "propuesto" }, "Documento vinculado").catch(function () {});
    var unlinkDoc = t.closest("[data-unlink-doc]");
    if (unlinkDoc) {
      if (!window.confirm("¿Desvincular el documento de este pedido?")) return;
      return void orderAction(cfg.actions.unlinkDoc, { id: state.order.id, factura_id: unlinkDoc.getAttribute("data-unlink-doc") }).catch(function () {});
    }
    var rest = t.closest("[data-rest]");
    if (rest) return dialogRest(parseInt(rest.getAttribute("data-rest"), 10));

    if (t.id === "pc-modal") closeModal();
  });

  function refreshNeedPackHint(target) {
    var row = target.closest(".pc-need");
    var hint = row && row.querySelector("[data-need-pack]");
    if (!hint) return;
    hint.textContent = packHint(
      row.querySelector("[data-need-qty]").value,
      row.querySelector("[data-need-unit]").value,
      hint.getAttribute("data-need-pack")
    );
  }

  root.addEventListener("input", function (e) {
    if (e.target.matches && e.target.matches("[data-need-qty]")) refreshNeedPackHint(e.target);
  });

  root.addEventListener("change", function (e) {
    if (e.target.matches && e.target.matches("[data-need-unit]")) {
      refreshNeedPackHint(e.target);
      return;
    }
    var select = e.target.closest("[data-need-supplier]");
    if (!select) return;
    syncNeedInputs();
    var need = findNeed(select.closest(".pc-need").getAttribute("data-key"));
    if (!need) return;
    if (select.value === "otro") {
      select.value = String(need.elegido);
      chooseOtherSupplier(need);
      return;
    }
    need.elegido = parseInt(select.value, 10) || 0;
    if (!need.elegido) need.marcada = false;
    // Cada proveedor vende en su unidad: al cambiarlo, la unidad vuelve a proponerse.
    delete need.unidadElegida;
    renderTray();
  });

  root.addEventListener("keydown", function (e) {
    if (e.key !== "Enter") return;
    var row = e.target.closest("tr[data-order]");
    if (row) go({ pedido: row.getAttribute("data-order") });
  });

  var search = $("pc-search");
  if (search) {
    var searchTimer = null;
    search.addEventListener("input", function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.search = search.value.trim();
        loadList();
      }, 300);
    });
  }

  $("pc-modal-cancel").addEventListener("click", closeModal);
  $("pc-modal-ok").addEventListener("click", function () {
    if (state.modalOk) state.modalOk();
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !$("pc-modal").hidden) closeModal();
  });
  window.addEventListener("popstate", function () {
    route();
  });

  route(true);
})();
