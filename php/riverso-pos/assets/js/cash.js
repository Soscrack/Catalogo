/**
 * Cobranza · Manejo de Caja + Cuentas bancarias y efectivo
 */
(function () {
  "use strict";

  var cfg = window.riversoCash || {};
  var root = document.getElementById("riverso-cash");
  if (!root || !cfg.ajaxUrl) return;

  var view = root.getAttribute("data-view") || cfg.view || "manejo";
  var state = {
    items: [],
    filtered: [],
    page: 1,
    pageSize: 10,
    search: "",
    pending: [],
    // accounts
    accounts: [],
    accFiltered: [],
    accPage: 1,
    accPageSize: 10,
    accSearch: "",
    // edit / perms
    editId: 0,
    perms: [],
    permFiltered: [],
    permPage: 1,
    permPageSize: 10,
    permSearch: "",
    arqueoCajaId: 0,
    arqueoTipo: "",
  };

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

  function money(n) {
    if (n === null || n === undefined) return "—";
    var v = Math.round(Number(n) || 0);
    var neg = v < 0;
    v = Math.abs(v);
    var str = String(v).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    return (neg ? "-$" : "$") + str;
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var val = data[k];
      if (val === true) val = "1";
      if (val === false) val = "0";
      body.append(k, val == null ? "" : val);
    });
    return fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (!res || !res.success) {
          var msg =
            (res && res.data && res.data.message) || "Error en la solicitud.";
          throw new Error(msg);
        }
        return res.data || {};
      });
  }

  function toast(msg, isErr) {
    if (window.alert) alert(msg);
  }

  function closeDropdowns() {
    root.querySelectorAll(".cash-dropdown.open").forEach(function (el) {
      el.classList.remove("open");
    });
  }

  document.addEventListener("click", function (e) {
    if (!e.target.closest(".cash-dropdown")) closeDropdowns();
  });

  /* ========== MANEJO DE CAJA ========== */
  function loadManejo() {
    post(cfg.actions.list, {}).then(function (data) {
      state.items = data.items || [];
      state.pending = data.pending || [];
      renderPending();
      applyManejoFilter();
      if (cfg.arqueoId) {
        highlightArqueo(cfg.arqueoId);
      }
    }).catch(function (err) {
      var body = $("cash-manejo-body");
      if (body) body.innerHTML = '<tr><td colspan="8" class="cash-empty">' + esc(err.message) + "</td></tr>";
    });
  }

  function renderPending() {
    var box = $("cash-pending-box");
    if (!box) return;
    if (!state.pending.length) {
      box.hidden = true;
      box.innerHTML = "";
      return;
    }
    box.hidden = false;
    var html = "<h3>Arqueos por certificar</h3>";
    state.pending.forEach(function (p) {
      html +=
        '<div class="cash-alert-row" data-arqueo-id="' +
        p.id +
        '">' +
        "<div><strong>" +
        esc(p.caja_nombre || "Caja") +
        "</strong> — " +
        esc(p.tipo) +
        " · " +
        money(p.monto_efectivo) +
        " · " +
        esc(p.fecha) +
        "</div>" +
        '<div style="display:flex;gap:6px">' +
        '<button type="button" class="cash-btn cash-btn-sm cash-approve" data-id="' +
        p.id +
        '">Aprobar</button>' +
        '<button type="button" class="cash-btn cash-btn-sm cash-btn-secondary cash-reject" data-id="' +
        p.id +
        '">Rechazar</button>' +
        "</div></div>";
    });
    box.innerHTML = html;
    box.querySelectorAll(".cash-approve").forEach(function (btn) {
      btn.addEventListener("click", function () {
        post(cfg.actions.approveArqueo, { arqueo_id: btn.getAttribute("data-id") })
          .then(function () {
            loadManejo();
          })
          .catch(function (e) {
            toast(e.message, true);
          });
      });
    });
    box.querySelectorAll(".cash-reject").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (!confirm("¿Rechazar este arqueo?")) return;
        post(cfg.actions.rejectArqueo, { arqueo_id: btn.getAttribute("data-id") })
          .then(function () {
            loadManejo();
          })
          .catch(function (e) {
            toast(e.message, true);
          });
      });
    });
  }

  function highlightArqueo(id) {
    var box = $("cash-pending-box");
    if (!box) return;
    var row = box.querySelector('[data-arqueo-id="' + id + '"]');
    if (row) {
      row.style.outline = "2px solid #fb8c00";
      row.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  }

  function applyManejoFilter() {
    var q = (state.search || "").toLowerCase().trim();
    state.filtered = state.items.filter(function (it) {
      if (!q) return true;
      return String(it.nombre || "")
        .toLowerCase()
        .indexOf(q) !== -1;
    });
    state.page = 1;
    renderManejoTable();
  }

  function renderManejoTable() {
    var body = $("cash-manejo-body");
    var foot = $("cash-manejo-foot");
    var pager = $("cash-manejo-pager");
    if (!body) return;

    var totalPages = Math.max(1, Math.ceil(state.filtered.length / state.pageSize));
    if (state.page > totalPages) state.page = totalPages;
    var start = (state.page - 1) * state.pageSize;
    var pageItems = state.filtered.slice(start, start + state.pageSize);

    if (!pageItems.length) {
      body.innerHTML = '<tr><td colspan="8" class="cash-empty">Sin cajas</td></tr>';
    } else {
      body.innerHTML = pageItems
        .map(function (it) {
          var badge =
            it.estado === "abierta"
              ? '<span class="cash-badge cash-badge-open">Abierta</span>'
              : '<span class="cash-badge cash-badge-closed">Cerrada</span>';
          if (it.pending_arqueo) {
            badge +=
              ' <span class="cash-badge cash-badge-pending">Pendiente</span>';
          }
          var openLabel = it.estado === "abierta" ? "Cerrar cuenta" : "Abrir cuenta";
          var openCls = it.estado === "abierta" ? "cash-opt-close" : "";
          var canOC = !!it.can_abrir_cerrar && !it.pending_arqueo;
          return (
            "<tr data-id=\"" +
            it.id +
            '">' +
            "<td>" +
            esc(it.nombre) +
            "</td>" +
            '<td class="cash-money">' +
            money(it.saldo_efectivo) +
            "</td>" +
            '<td class="cash-money">' +
            money(it.docs_recibidos) +
            "</td>" +
            '<td class="cash-money">' +
            money(it.docs_emitidos) +
            "</td>" +
            '<td class="cash-money">' +
            money(it.total) +
            "</td>" +
            '<td class="cash-money">' +
            money(it.transferencia_pendiente) +
            "</td>" +
            "<td>" +
            badge +
            "</td>" +
            '<td class="cash-actions">' +
            '<div class="cash-dropdown">' +
            '<button type="button" class="cash-btn cash-btn-sm cash-dd-toggle">⚙ Opciones ▾</button>' +
            '<div class="cash-dropdown-menu">' +
            '<button type="button" class="' +
            openCls +
            ' cash-opt-oc" data-id="' +
            it.id +
            '" data-tipo="' +
            (it.estado === "abierta" ? "cierre" : "apertura") +
            '"' +
            (canOC ? "" : " disabled") +
            ">" +
            openLabel +
            "</button>" +
            '<button type="button" class="cash-opt-wip" disabled>Transferir efectivo [WIP]</button>' +
            '<button type="button" class="cash-opt-wip" disabled>Ver cheques [WIP]</button>' +
            '<button type="button" class="cash-opt-wip" disabled>Calendario cheques [WIP]</button>' +
            '<button type="button" class="cash-opt-movs" data-id="' +
            it.id +
            '" data-nombre="' +
            esc(it.nombre) +
            '">Ver movimientos</button>' +
            '<button type="button" class="cash-opt-wip" disabled>Desglose de Caja [WIP]</button>' +
            "</div></div></td></tr>"
          );
        })
        .join("");
    }

    // Totals over filtered (only visible balances)
    var sumE = 0,
      sumR = 0,
      sumI = 0,
      sumT = 0,
      any = false;
    state.filtered.forEach(function (it) {
      if (it.can_ver_saldo) {
        any = true;
        sumE += Number(it.saldo_efectivo) || 0;
        sumR += Number(it.docs_recibidos) || 0;
        sumI += Number(it.docs_emitidos) || 0;
        sumT += Number(it.total) || 0;
      }
    });
    if (foot) {
      foot.innerHTML =
        "<tr><td>Total</td>" +
        '<td class="cash-money">' +
        (any ? money(sumE) : "—") +
        "</td>" +
        '<td class="cash-money">' +
        (any ? money(sumR) : "—") +
        "</td>" +
        '<td class="cash-money">' +
        (any ? money(sumI) : "—") +
        "</td>" +
        '<td class="cash-money">' +
        (any ? money(sumT) : "—") +
        "</td>" +
        "<td></td><td></td><td></td></tr>";
    }

    renderPager(pager, state.page, totalPages, function (p) {
      state.page = p;
      renderManejoTable();
    });

    body.querySelectorAll(".cash-dd-toggle").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        var dd = btn.closest(".cash-dropdown");
        var was = dd.classList.contains("open");
        closeDropdowns();
        if (!was) dd.classList.add("open");
      });
    });
    body.querySelectorAll(".cash-opt-oc").forEach(function (btn) {
      btn.addEventListener("click", function () {
        closeDropdowns();
        openArqueoModal(
          parseInt(btn.getAttribute("data-id"), 10),
          btn.getAttribute("data-tipo")
        );
      });
    });
    body.querySelectorAll(".cash-opt-movs").forEach(function (btn) {
      btn.addEventListener("click", function () {
        closeDropdowns();
        openMovsModal(
          parseInt(btn.getAttribute("data-id"), 10),
          btn.getAttribute("data-nombre") || ""
        );
      });
    });
  }

  function renderPager(el, page, totalPages, onPage) {
    if (!el) return;
    var html =
      '<button type="button" data-p="' +
      (page - 1) +
      '"' +
      (page <= 1 ? " disabled" : "") +
      ">Anterior</button>";
    for (var i = 1; i <= totalPages; i++) {
      html +=
        '<button type="button" data-p="' +
        i +
        '" class="' +
        (i === page ? "active" : "") +
        '">' +
        i +
        "</button>";
    }
    html +=
      '<button type="button" data-p="' +
      (page + 1) +
      '"' +
      (page >= totalPages ? " disabled" : "") +
      ">Siguiente</button>";
    el.innerHTML = html;
    el.querySelectorAll("button").forEach(function (b) {
      b.addEventListener("click", function () {
        var p = parseInt(b.getAttribute("data-p"), 10);
        if (!isNaN(p) && p >= 1 && p <= totalPages) onPage(p);
      });
    });
  }

  function openArqueoModal(cajaId, tipo) {
    state.arqueoCajaId = cajaId;
    state.arqueoTipo = tipo;
    $("cash-arqueo-caja-id").value = cajaId;
    $("cash-arqueo-tipo").value = tipo;
    $("cash-arqueo-efectivo").value = "0";
    $("cash-arqueo-docs-rec").value = "0";
    $("cash-arqueo-docs-emi").value = "0";
    $("cash-arqueo-total").value = "0";
    $("cash-arqueo-now").checked = true;
    $("cash-arqueo-fecha-wrap").hidden = true;
    $("cash-arqueo-submit").textContent =
      tipo === "cierre" ? "Cerrar cuenta" : "Abrir cuenta";

    // Prefill with system saldo if closing
    var item = state.items.find(function (x) {
      return x.id === cajaId;
    });
    if (item && item.can_ver_saldo && tipo === "cierre") {
      $("cash-arqueo-efectivo").value = String(Math.round(Number(item.saldo_efectivo) || 0));
      updateArqueoTotal();
    }

    post(cfg.actions.certificadores, { caja_id: cajaId })
      .then(function (data) {
        var sel = $("cash-arqueo-cert");
        var opts =
          '<option value="' +
          (cfg.currentUserId || 0) +
          '">** Yo mismo certificaré este arqueo **</option>';
        (data.certificadores || []).forEach(function (c) {
          if (c.id === cfg.currentUserId) return;
          opts +=
            '<option value="' + c.id + '">' + esc(c.name) + "</option>";
        });
        sel.innerHTML = opts;
        showModal("cash-arqueo-modal");
      })
      .catch(function (e) {
        toast(e.message, true);
      });
  }

  function updateArqueoTotal() {
    var e = parseFloat(($("cash-arqueo-efectivo") || {}).value) || 0;
    var r = parseFloat(($("cash-arqueo-docs-rec") || {}).value) || 0;
    var i = parseFloat(($("cash-arqueo-docs-emi") || {}).value) || 0;
    if ($("cash-arqueo-total")) $("cash-arqueo-total").value = String(e + r - i);
  }

  function submitArqueo() {
    var monto = parseFloat(($("cash-arqueo-efectivo") || {}).value);
    if (isNaN(monto) || monto < 0) {
      toast("Ingresa un monto de efectivo válido (≥ 0).", true);
      return;
    }
    var useNow = ($("cash-arqueo-now") || {}).checked;
    var fecha = "";
    if (!useNow) {
      fecha = (($("cash-arqueo-fecha") || {}).value || "").replace("T", " ");
      if (!fecha) {
        toast("Indica fecha y hora.", true);
        return;
      }
    }
    var payload = {
      caja_id: state.arqueoCajaId,
      tipo: state.arqueoTipo,
      monto_efectivo: monto,
      docs_recibidos: parseFloat(($("cash-arqueo-docs-rec") || {}).value) || 0,
      docs_emitidos: parseFloat(($("cash-arqueo-docs-emi") || {}).value) || 0,
      certificador_id: (($("cash-arqueo-cert") || {}).value || cfg.currentUserId),
      fecha: fecha,
    };
    var btn = $("cash-arqueo-submit");
    if (btn) btn.disabled = true;
    post(cfg.actions.arqueo, payload)
      .then(function (data) {
        hideModal("cash-arqueo-modal");
        if (data.estado === "pendiente") {
          toast("Arqueo enviado a certificación. No tomará efecto hasta ser aprobado.");
        }
        loadManejo();
      })
      .catch(function (e) {
        toast(e.message, true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function openMovsModal(cajaId, nombre) {
    $("cash-movs-title").textContent = "Movimientos — " + nombre;
    $("cash-movs-body").innerHTML =
      '<tr><td colspan="6" class="cash-empty">Cargando…</td></tr>';
    showModal("cash-movs-modal");
    post(cfg.actions.movimientos, { caja_id: cajaId })
      .then(function (data) {
        var movs = data.movimientos || [];
        if (!data.can_ver_saldo) {
          $("cash-movs-body").innerHTML =
            '<tr><td colspan="6" class="cash-empty">No tienes permiso para ver el saldo.</td></tr>';
          return;
        }
        if (!movs.length) {
          $("cash-movs-body").innerHTML =
            '<tr><td colspan="6" class="cash-empty">Sin movimientos</td></tr>';
          return;
        }
        var tipoLabel = {
          ajuste_arqueo: "Ajuste arqueo",
          ingreso_pago: "Ingreso pago",
          egreso: "Egreso",
          transferencia: "Transferencia",
        };
        $("cash-movs-body").innerHTML = movs
          .map(function (m) {
            return (
              "<tr><td>" +
              esc(m.fecha) +
              "</td><td>" +
              esc(tipoLabel[m.tipo] || m.tipo) +
              "</td><td>" +
              esc(m.detalle) +
              "</td><td>" +
              esc(m.usuario_nombre) +
              "</td><td class=\"cash-money\">" +
              money(m.monto) +
              "</td><td class=\"cash-money\">" +
              money(m.saldo_acumulado) +
              "</td></tr>"
            );
          })
          .join("");
      })
      .catch(function (e) {
        $("cash-movs-body").innerHTML =
          '<tr><td colspan="6" class="cash-empty">' + esc(e.message) + "</td></tr>";
      });
  }

  function bindManejo() {
    var search = $("cash-search");
    if (search) {
      search.addEventListener("input", function () {
        state.search = search.value;
        applyManejoFilter();
      });
    }
    var ps = $("cash-page-size");
    if (ps) {
      ps.addEventListener("change", function () {
        state.pageSize = parseInt(ps.value, 10) || 10;
        state.page = 1;
        renderManejoTable();
      });
    }
    var now = $("cash-arqueo-now");
    if (now) {
      now.addEventListener("change", function () {
        $("cash-arqueo-fecha-wrap").hidden = !!now.checked;
      });
    }
    var ef = $("cash-arqueo-efectivo");
    if (ef) ef.addEventListener("input", updateArqueoTotal);
    var closeA = $("cash-arqueo-close");
    if (closeA) closeA.addEventListener("click", function () {
      hideModal("cash-arqueo-modal");
    });
    var sub = $("cash-arqueo-submit");
    if (sub) sub.addEventListener("click", submitArqueo);
    var closeM = $("cash-movs-close");
    if (closeM) closeM.addEventListener("click", function () {
      hideModal("cash-movs-modal");
    });
    loadManejo();
  }

  /* ========== CUENTAS ========== */
  function loadAccounts() {
    post(cfg.actions.accountsList, {})
      .then(function (data) {
        state.accounts = data.items || [];
        applyAccFilter();
        if (cfg.editId) {
          openEdit(cfg.editId);
        }
      })
      .catch(function (err) {
        var body = $("cash-acc-body");
        if (body)
          body.innerHTML =
            '<tr><td colspan="3" class="cash-empty">' + esc(err.message) + "</td></tr>";
      });
  }

  function applyAccFilter() {
    var q = (state.accSearch || "").toLowerCase().trim();
    state.accFiltered = state.accounts.filter(function (it) {
      if (!q) return true;
      return (
        String(it.nombre || "")
          .toLowerCase()
          .indexOf(q) !== -1 ||
        String(it.tipo_label || "")
          .toLowerCase()
          .indexOf(q) !== -1
      );
    });
    state.accPage = 1;
    renderAccTable();
  }

  function renderAccTable() {
    var body = $("cash-acc-body");
    var pager = $("cash-acc-pager");
    if (!body) return;
    var totalPages = Math.max(
      1,
      Math.ceil(state.accFiltered.length / state.accPageSize)
    );
    if (state.accPage > totalPages) state.accPage = totalPages;
    var start = (state.accPage - 1) * state.accPageSize;
    var pageItems = state.accFiltered.slice(start, start + state.accPageSize);
    if (!pageItems.length) {
      body.innerHTML = '<tr><td colspan="3" class="cash-empty">Sin cajas</td></tr>';
    } else {
      body.innerHTML = pageItems
        .map(function (it) {
          return (
            "<tr><td>" +
            esc(it.nombre) +
            "</td><td>" +
            esc(it.tipo_label) +
            '</td><td class="cash-actions">' +
            '<button type="button" class="cash-btn cash-btn-orange cash-edit" data-id="' +
            it.id +
            '" title="Editar">✎</button>' +
            '<button type="button" class="cash-btn cash-btn-danger cash-del" data-id="' +
            it.id +
            '" title="Eliminar">−</button>' +
            "</td></tr>"
          );
        })
        .join("");
    }
    renderPager(pager, state.accPage, totalPages, function (p) {
      state.accPage = p;
      renderAccTable();
    });
    body.querySelectorAll(".cash-edit").forEach(function (btn) {
      btn.addEventListener("click", function () {
        openEdit(parseInt(btn.getAttribute("data-id"), 10));
      });
    });
    body.querySelectorAll(".cash-del").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var id = parseInt(btn.getAttribute("data-id"), 10);
        if (!confirm("¿Eliminar esta caja?")) return;
        post(cfg.actions.delete, { id: id })
          .then(function () {
            loadAccounts();
          })
          .catch(function (e) {
            toast(e.message, true);
          });
      });
    });
  }

  function showList() {
    var list = $("cash-accounts-list-view");
    var edit = $("cash-accounts-edit-view");
    if (list) list.hidden = false;
    if (edit) edit.hidden = true;
    state.editId = 0;
  }

  function openEdit(id) {
    state.editId = id;
    post(cfg.actions.get, { id: id })
      .then(function (data) {
        var c = data.caja;
        if (!c) throw new Error("Caja no encontrada");
        $("cash-edit-id").value = c.id;
        $("cash-edit-nombre").value = c.nombre;
        $("cash-edit-tipo").value = c.tipo;
        var list = $("cash-accounts-list-view");
        var edit = $("cash-accounts-edit-view");
        if (list) list.hidden = true;
        if (edit) edit.hidden = false;
        loadPermisos(id);
        // Update URL without reload when possible
        try {
          var u = new URL(window.location.href);
          u.searchParams.set("editar", String(id));
          window.history.replaceState({}, "", u.toString());
        } catch (e) {}
      })
      .catch(function (e) {
        toast(e.message, true);
      });
  }

  function loadPermisos(cajaId) {
    post(cfg.actions.getPermisos, { caja_id: cajaId })
      .then(function (data) {
        state.perms = data.permisos || [];
        applyPermFilter();
      })
      .catch(function (e) {
        $("cash-perm-body").innerHTML =
          '<tr><td colspan="6" class="cash-empty">' + esc(e.message) + "</td></tr>";
      });
  }

  function applyPermFilter() {
    var q = (state.permSearch || "").toLowerCase().trim();
    state.permFiltered = state.perms.filter(function (p) {
      if (!q) return true;
      return String(p.name || "")
        .toLowerCase()
        .indexOf(q) !== -1;
    });
    state.permPage = 1;
    renderPermTable();
  }

  function renderPermTable() {
    var body = $("cash-perm-body");
    var pager = $("cash-perm-pager");
    if (!body) return;
    var totalPages = Math.max(
      1,
      Math.ceil(state.permFiltered.length / state.permPageSize)
    );
    if (state.permPage > totalPages) state.permPage = totalPages;
    var start = (state.permPage - 1) * state.permPageSize;
    var pageItems = state.permFiltered.slice(start, start + state.permPageSize);
    if (!pageItems.length) {
      body.innerHTML =
        '<tr><td colspan="6" class="cash-empty">Sin usuarios</td></tr>';
    } else {
      body.innerHTML = pageItems
        .map(function (p) {
          return (
            '<tr data-user="' +
            p.user_id +
            '"><td>' +
            esc(p.name) +
            "</td>" +
            permCb(p, "ver_saldo") +
            permCb(p, "pagar") +
            permCb(p, "borrar_pago") +
            permCb(p, "transferir") +
            permCb(p, "abrir_cerrar") +
            "</tr>"
          );
        })
        .join("");
    }
    renderPager(pager, state.permPage, totalPages, function (p) {
      state.permPage = p;
      renderPermTable();
    });
    body.querySelectorAll("input[type=checkbox]").forEach(function (cb) {
      cb.addEventListener("change", function () {
        savePermRow(parseInt(cb.closest("tr").getAttribute("data-user"), 10));
      });
    });
  }

  function permCb(p, key) {
    return (
      '<td style="text-align:center"><input type="checkbox" data-perm="' +
      key +
      '"' +
      (p[key] ? " checked" : "") +
      "></td>"
    );
  }

  function savePermRow(userId) {
    var tr = root.querySelector('tr[data-user="' + userId + '"]');
    if (!tr) return;
    var flags = {
      caja_id: state.editId,
      user_id: userId,
      ver_saldo: 0,
      pagar: 0,
      borrar_pago: 0,
      transferir: 0,
      abrir_cerrar: 0,
    };
    tr.querySelectorAll("input[type=checkbox]").forEach(function (cb) {
      flags[cb.getAttribute("data-perm")] = cb.checked ? 1 : 0;
    });
    post(cfg.actions.setPermiso, flags).catch(function (e) {
      toast(e.message, true);
    });
  }

  function bindAccounts() {
    var addBtn = $("cash-add-btn");
    if (addBtn) {
      addBtn.addEventListener("click", function () {
        $("cash-add-nombre").value = "";
        $("cash-add-tipo").selectedIndex = 0;
        showModal("cash-add-modal");
      });
    }
    var addClose = $("cash-add-close");
    if (addClose)
      addClose.addEventListener("click", function () {
        hideModal("cash-add-modal");
      });
    var addSub = $("cash-add-submit");
    if (addSub) {
      addSub.addEventListener("click", function () {
        var nombre = (($("cash-add-nombre") || {}).value || "").trim();
        if (!nombre) {
          toast("El nombre es obligatorio.", true);
          return;
        }
        addSub.disabled = true;
        post(cfg.actions.create, {
          nombre: nombre,
          tipo: (($("cash-add-tipo") || {}).value || "fisica"),
        })
          .then(function (data) {
            hideModal("cash-add-modal");
            loadAccounts();
            if (data.caja && data.caja.id) {
              openEdit(data.caja.id);
            }
          })
          .catch(function (e) {
            toast(e.message, true);
          })
          .finally(function () {
            addSub.disabled = false;
          });
      });
    }
    var back = $("cash-edit-back");
    if (back) {
      back.addEventListener("click", function (e) {
        e.preventDefault();
        showList();
        try {
          var u = new URL(window.location.href);
          u.searchParams.delete("editar");
          window.history.replaceState({}, "", u.toString());
        } catch (err) {}
        loadAccounts();
      });
    }
    var save = $("cash-edit-save");
    if (save) {
      save.addEventListener("click", function () {
        post(cfg.actions.update, {
          id: state.editId,
          nombre: (($("cash-edit-nombre") || {}).value || "").trim(),
          tipo: (($("cash-edit-tipo") || {}).value || "fisica"),
        })
          .then(function () {
            toast("Caja guardada.");
            loadAccounts();
          })
          .catch(function (e) {
            toast(e.message, true);
          });
      });
    }
    var search = $("cash-acc-search");
    if (search) {
      search.addEventListener("input", function () {
        state.accSearch = search.value;
        applyAccFilter();
      });
    }
    var ps = $("cash-acc-page-size");
    if (ps) {
      ps.addEventListener("change", function () {
        state.accPageSize = parseInt(ps.value, 10) || 10;
        state.accPage = 1;
        renderAccTable();
      });
    }
    var psearch = $("cash-perm-search");
    if (psearch) {
      psearch.addEventListener("input", function () {
        state.permSearch = psearch.value;
        applyPermFilter();
      });
    }
    var pps = $("cash-perm-page-size");
    if (pps) {
      pps.addEventListener("change", function () {
        state.permPageSize = parseInt(pps.value, 10) || 10;
        state.permPage = 1;
        renderPermTable();
      });
    }
    loadAccounts();
  }

  function showModal(id) {
    var el = $(id);
    if (!el) return;
    el.hidden = false;
    el.setAttribute("aria-hidden", "false");
  }

  function hideModal(id) {
    var el = $(id);
    if (!el) return;
    el.hidden = true;
    el.setAttribute("aria-hidden", "true");
  }

  // Close modal on backdrop click
  ["cash-arqueo-modal", "cash-movs-modal", "cash-add-modal"].forEach(function (id) {
    var el = $(id);
    if (!el) return;
    el.addEventListener("click", function (e) {
      if (e.target === el) hideModal(id);
    });
  });

  if (view === "accounts") {
    bindAccounts();
  } else {
    bindManejo();
  }
})();
