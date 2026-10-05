(function () {
  "use strict";

  var cfg = window.RIVERSO_BILLING || {};
  var state = {
    step: 1,
    quoteId: cfg.quoteId || 0,
    estimatedFolio: null,
    lines: [],
    docType: 37,
    receiverMode: "locked", // locked | found | manual
    comunas: [],
    comunaActiveIndex: -1,
    draftId: cfg.draftId || 0,
    draftStatus: "draft",
    dteId: 0,
    boletaTab: "detalles",
    refs: [],
    payments: [],
    selectedQuoteRef: null,
    draftSaveTimer: null,
    linesReady: false,
    advanced: false,
    advScope: "todo",
    advContains: [],
    advResults: [],
    sii: {
      rut: "",
      razon_social: "",
      actividades: [],
      designs: [],
      selectedCode: "",
    },
  };

  var Lines = window.RiversoBillingLines || null;

  var DETAIL_FIELDS = [
    "bill-recv-name",
    "bill-recv-giro",
    "bill-recv-address",
    "bill-recv-comuna",
    "bill-recv-city",
    "bill-recv-phone",
    "bill-recv-postal",
  ];

  var TYPE_LABELS = {
    2: "FACTURA ELECTRÓNICA",
    32: "FACTURA EXENTA ELECTRÓNICA",
    37: "BOLETA ELECTRÓNICA",
    41: "BOLETA EXENTA ELECTRÓNICA",
  };

  function $(id) {
    return document.getElementById(id);
  }

  function money(n) {
    var v = Math.round((Number(n) || 0) * 100) / 100;
    return "$" + v.toLocaleString("es-CL", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var val = data[k];
      if (val === undefined || val === null) return;
      if (typeof val === "object") {
        body.append(k, JSON.stringify(val));
      } else {
        body.append(k, String(val));
      }
    });
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json || !json.success) {
          var msg = (json && json.data && json.data.message) ? json.data.message : "Error en la solicitud";
          throw new Error(msg);
        }
        return json.data || {};
      });
  }

  function showAlert(msg, isError) {
    var el = $("bill-alerts");
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || "";
    el.style.background = isError ? "#fef2f2" : "#f0fdf4";
    el.style.color = isError ? "#991b1b" : "#166534";
    el.style.borderColor = isError ? "#fecaca" : "#bbf7d0";
  }

  function showMissingPopup(title, message, missing) {
    var overlay = $("bill-missing-modal");
    if (!overlay) {
      // Fallback si el markup no está.
      window.alert((title ? title + "\n\n" : "") + (message || ""));
      return;
    }
    var titleEl = $("bill-missing-title");
    var msgEl = $("bill-missing-msg");
    var listEl = $("bill-missing-list");
    if (titleEl) titleEl.textContent = title || "Datos incompletos";
    if (msgEl) msgEl.textContent = message || "";
    if (listEl) {
      listEl.innerHTML = "";
      (missing || []).forEach(function (item) {
        var li = document.createElement("li");
        li.textContent = item;
        listEl.appendChild(li);
      });
      listEl.hidden = !(missing && missing.length);
    }
    overlay.hidden = false;
    overlay.setAttribute("aria-hidden", "false");
    var ok = $("bill-missing-ok");
    if (ok) {
      try { ok.focus(); } catch (e) { /* ignore */ }
    }
  }

  function hideMissingPopup() {
    var overlay = $("bill-missing-modal");
    if (!overlay) return;
    overlay.hidden = true;
    overlay.setAttribute("aria-hidden", "true");
  }

  function currentTypeId() {
    var sel = $("bill-doc-type");
    var v = sel ? parseInt(sel.value, 10) : 37;
    return v === 2 || v === 37 ? v : 37;
  }

  function updateFolioCard() {
    var type = currentTypeId();
    state.docType = type;
    var typeEl = $("bill-folio-type");
    if (typeEl) typeEl.textContent = TYPE_LABELS[type] || "DOCUMENTO";
    var num = $("bill-folio-est");
    if (num) num.textContent = state.estimatedFolio != null ? String(state.estimatedFolio) : "—";
    var recv = $("bill-receiver-card");
    if (recv) {
      // Boleta: ocultar receptor y no usarlo. Factura: visible y obligatorio.
      recv.hidden = type !== 2;
    }
    var note = $("bill-cession-note");
    if (note) {
      var pay = $("bill-payment");
      note.hidden = !(pay && pay.value === "0");
    }
  }

  function emptyReceiverPayload() {
    return {
      customer_id: 0,
      receiver_rut: "",
      receiver_legal_name: "",
      receiver_activity: "",
      receiver_activity_code: "",
      receiver_address: "",
      receiver_district: "",
      receiver_city: "",
      receiver_phone: "",
      receiver_postal: "0",
    };
  }

  function estimateFolio() {
    var type = currentTypeId();
    if (!cfg.factoConfigured) {
      state.estimatedFolio = null;
      updateFolioCard();
      return;
    }
    post(cfg.actions.estimateFolio, { document_type_id: type })
      .then(function (data) {
        state.estimatedFolio = data.estimated_folio != null ? data.estimated_folio : null;
        updateFolioCard();
        var unusedEl = $("bill-folio-unused");
        if (unusedEl) {
          if (data.unused != null) {
            unusedEl.hidden = false;
            unusedEl.textContent = "Folios restantes estimados en CAF: " + data.unused;
          } else {
            unusedEl.hidden = true;
          }
        }
      })
      .catch(function () {
        state.estimatedFolio = null;
        updateFolioCard();
      });
  }

  function collectReceiver() {
    var rutEl = $("bill-recv-rut");
    var postal = (($("bill-recv-postal") || {}).value || "").trim();
    return {
      customer_id: parseInt(($("bill-customer-id") || {}).value || "0", 10) || 0,
      receiver_rut: (rutEl && rutEl.value) || "",
      receiver_legal_name: ($("bill-recv-name") || {}).value || "",
      receiver_activity: ($("bill-recv-giro") || {}).value || "",
      receiver_activity_code: ($("bill-recv-activity-code") || {}).value || "",
      receiver_address: ($("bill-recv-address") || {}).value || "",
      receiver_district: ($("bill-recv-comuna") || {}).value || "",
      receiver_city: ($("bill-recv-city") || {}).value || "",
      receiver_phone: ($("bill-recv-phone") || {}).value || "",
      receiver_postal: postal !== "" ? postal : "0",
    };
  }

  function fieldHasValue(id) {
    var el = $(id);
    if (!el) return false;
    var v = (el.value || "").trim();
    if (id === "bill-recv-postal" && (v === "" || v === "0")) return false;
    return v !== "";
  }

  function setFieldLocked(id, locked) {
    if (id === "bill-recv-comuna") {
      setComunaLocked(!!locked);
      return;
    }
    var el = $(id);
    if (!el) return;
    if (el.tagName === "SELECT") {
      el.disabled = !!locked;
    } else {
      el.readOnly = !!locked;
    }
    if (locked) {
      el.classList.add("bill-locked");
    } else {
      el.classList.remove("bill-locked");
    }
  }

  function setComunaLocked(locked) {
    var combo = $("bill-comuna-combo");
    var trigger = $("bill-comuna-trigger");
    if (combo) {
      combo.setAttribute("data-locked", locked ? "1" : "0");
      if (locked) {
        combo.classList.add("bill-locked");
        closeComunaDropdown();
      } else {
        combo.classList.remove("bill-locked");
      }
    }
    if (trigger) trigger.disabled = !!locked;
  }

  function loadComunasList() {
    if (state.comunas && state.comunas.length) return state.comunas;
    var raw = $("bill-comunas-data");
    var list = [];
    if (raw && raw.textContent) {
      try {
        list = JSON.parse(raw.textContent);
      } catch (e) {
        list = [];
      }
    }
    if (!Array.isArray(list)) list = [];
    state.comunas = list;
    return list;
  }

  function normComuna(s) {
    return String(s || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim();
  }

  function resolveComunaName(name) {
    var want = normComuna(name);
    if (!want) return "";
    var list = loadComunasList();
    for (var i = 0; i < list.length; i++) {
      if (normComuna(list[i]) === want) return list[i];
    }
    return String(name || "").trim();
  }

  function syncComunaDisplay() {
    var val = (($("bill-recv-comuna") || {}).value || "").trim();
    var text = $("bill-comuna-value-text");
    if (text) text.textContent = val;
  }

  function setComunaValue(comuna) {
    var resolved = resolveComunaName(comuna);
    if ($("bill-recv-comuna")) $("bill-recv-comuna").value = resolved;
    syncComunaDisplay();
    renderComunaList((($("bill-comuna-search") || {}).value || "").trim());
  }

  function closeComunaDropdown() {
    var combo = $("bill-comuna-combo");
    var drop = $("bill-comuna-dropdown");
    var trigger = $("bill-comuna-trigger");
    if (drop) drop.hidden = true;
    if (combo) combo.classList.remove("is-open");
    if (trigger) trigger.setAttribute("aria-expanded", "false");
  }

  function openComunaDropdown() {
    var combo = $("bill-comuna-combo");
    if (!combo || combo.getAttribute("data-locked") === "1") return;
    var drop = $("bill-comuna-dropdown");
    var trigger = $("bill-comuna-trigger");
    var search = $("bill-comuna-search");
    if (drop) drop.hidden = false;
    if (combo) combo.classList.add("is-open");
    if (trigger) trigger.setAttribute("aria-expanded", "true");
    renderComunaList(search ? search.value : "");
    if (search) {
      search.value = search.value || "";
      setTimeout(function () {
        try { search.focus(); search.select(); } catch (e) { /* ignore */ }
      }, 0);
    }
  }

  function renderComunaList(query) {
    var listEl = $("bill-comuna-list");
    if (!listEl) return;
    var selected = (($("bill-recv-comuna") || {}).value || "").trim();
    var q = normComuna(query);
    var items = loadComunasList().filter(function (name) {
      return !q || normComuna(name).indexOf(q) !== -1;
    });
    listEl.innerHTML = "";
    if (!items.length) {
      var empty = document.createElement("li");
      empty.className = "is-empty";
      empty.textContent = "Sin resultados";
      listEl.appendChild(empty);
      state.comunaActiveIndex = -1;
      return;
    }
    items.forEach(function (name, idx) {
      var li = document.createElement("li");
      li.setAttribute("role", "option");
      li.setAttribute("data-value", name);
      li.textContent = name;
      if (name === selected) li.classList.add("is-selected");
      if (idx === 0 && q) {
        li.classList.add("is-active");
        state.comunaActiveIndex = 0;
      }
      li.addEventListener("mousedown", function (e) {
        e.preventDefault();
        setComunaValue(name);
        closeComunaDropdown();
      });
      li.addEventListener("mouseenter", function () {
        var prev = listEl.querySelectorAll("li.is-active");
        for (var i = 0; i < prev.length; i++) prev[i].classList.remove("is-active");
        li.classList.add("is-active");
        state.comunaActiveIndex = idx;
      });
      listEl.appendChild(li);
    });
    if (!q) state.comunaActiveIndex = -1;
  }

  function moveComunaActive(delta) {
    var listEl = $("bill-comuna-list");
    if (!listEl) return;
    var items = listEl.querySelectorAll("li[data-value]");
    if (!items.length) return;
    var idx = state.comunaActiveIndex;
    if (idx < 0) idx = delta > 0 ? 0 : items.length - 1;
    else idx = (idx + delta + items.length) % items.length;
    state.comunaActiveIndex = idx;
    for (var i = 0; i < items.length; i++) {
      items[i].classList.toggle("is-active", i === idx);
    }
    if (items[idx] && items[idx].scrollIntoView) {
      items[idx].scrollIntoView({ block: "nearest" });
    }
  }

  function pickComunaActive() {
    var listEl = $("bill-comuna-list");
    if (!listEl) return;
    var active = listEl.querySelector("li.is-active[data-value]") || listEl.querySelector("li.is-selected[data-value]");
    if (!active) return;
    setComunaValue(active.getAttribute("data-value") || "");
    closeComunaDropdown();
  }

  function initComunaCombo() {
    loadComunasList();
    syncComunaDisplay();
    var trigger = $("bill-comuna-trigger");
    var search = $("bill-comuna-search");
    var combo = $("bill-comuna-combo");
    if (trigger) {
      trigger.addEventListener("click", function () {
        if (combo && combo.getAttribute("data-locked") === "1") return;
        var drop = $("bill-comuna-dropdown");
        if (drop && !drop.hidden) closeComunaDropdown();
        else openComunaDropdown();
      });
    }
    if (search) {
      search.addEventListener("input", function () {
        renderComunaList(search.value);
      });
      search.addEventListener("keydown", function (e) {
        if (e.key === "ArrowDown") {
          e.preventDefault();
          moveComunaActive(1);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          moveComunaActive(-1);
        } else if (e.key === "Enter") {
          e.preventDefault();
          pickComunaActive();
        } else if (e.key === "Escape") {
          e.preventDefault();
          closeComunaDropdown();
        }
      });
    }
    document.addEventListener("mousedown", function (e) {
      if (!combo || combo.contains(e.target)) return;
      closeComunaDropdown();
    });
  }

  function lockAllDetails() {
    DETAIL_FIELDS.forEach(function (id) {
      setFieldLocked(id, true);
    });
    state.receiverMode = "locked";
  }

  function unlockAllDetails() {
    DETAIL_FIELDS.forEach(function (id) {
      setFieldLocked(id, false);
    });
    state.receiverMode = "manual";
  }

  function applyLocksAfterFill() {
    // Campos con valor quedan bloqueados; vacíos editables.
    DETAIL_FIELDS.forEach(function (id) {
      setFieldLocked(id, fieldHasValue(id));
    });
    state.receiverMode = "found";
  }

  function clearReceiverDetails(keepRut) {
    if ($("bill-customer-id")) $("bill-customer-id").value = "0";
    if ($("bill-recv-activity-code")) $("bill-recv-activity-code").value = "";
    if (!keepRut && $("bill-recv-rut")) $("bill-recv-rut").value = "";
    if ($("bill-recv-rut-display")) $("bill-recv-rut-display").value = keepRut ? (($("bill-recv-rut") || {}).value || "") : "";
    ["bill-recv-name", "bill-recv-giro", "bill-recv-address", "bill-recv-city", "bill-recv-phone", "bill-recv-postal"].forEach(function (id) {
      if ($(id)) $(id).value = "";
    });
    setComunaValue("");
    clearSiiPanel();
    lockAllDetails();
  }

  function clearSiiPanel() {
    state.sii = { rut: "", razon_social: "", actividades: [], designs: [], selectedCode: "" };
    var panel = $("bill-sii-panel");
    var box = $("bill-sii-activities");
    var status = $("bill-sii-status");
    if (panel) panel.hidden = true;
    if (box) box.innerHTML = "";
    if (status) status.textContent = "";
  }

  function applyDesign(design, opts) {
    opts = opts || {};
    if (!design) return;
    if (opts.forceName || !fieldHasValue("bill-recv-name")) {
      if ($("bill-recv-name") && design.razon_social) {
        $("bill-recv-name").value = design.razon_social;
      }
    }
    if ($("bill-recv-giro") && design.activity_glosa) {
      $("bill-recv-giro").value = design.activity_glosa;
    }
    if ($("bill-recv-activity-code")) {
      $("bill-recv-activity-code").value = design.activity_code || "";
    }
    if (opts.forceAddress || !fieldHasValue("bill-recv-address")) {
      if ($("bill-recv-address") && design.direccion) $("bill-recv-address").value = design.direccion;
    }
    if (opts.forceAddress || !fieldHasValue("bill-recv-comuna")) {
      if (design.comuna) setComunaValue(design.comuna);
    }
    if (opts.forceAddress || !fieldHasValue("bill-recv-city")) {
      if ($("bill-recv-city") && design.ciudad) $("bill-recv-city").value = design.ciudad;
    }
    if (opts.forceAddress || !fieldHasValue("bill-recv-phone")) {
      if ($("bill-recv-phone") && design.telefono) $("bill-recv-phone").value = design.telefono;
    }
    if (opts.forceAddress || !fieldHasValue("bill-recv-postal")) {
      if ($("bill-recv-postal") && design.codigo_postal && design.codigo_postal !== "0") {
        $("bill-recv-postal").value = design.codigo_postal;
      }
    }
  }

  function selectActivity(act) {
    if (!act) return;
    state.sii.selectedCode = act.codigo || "";
    if ($("bill-recv-activity-code")) $("bill-recv-activity-code").value = act.codigo || "";
    if ($("bill-recv-giro")) $("bill-recv-giro").value = act.glosa || "";
    if (act.design) {
      applyDesign(act.design, { forceAddress: true });
    }
    renderSiiActivities();
    applyLocksAfterFill();
  }

  function renderSiiActivities() {
    var panel = $("bill-sii-panel");
    var box = $("bill-sii-activities");
    if (!panel || !box) return;
    var list = state.sii.actividades || [];
    if (!list.length) {
      panel.hidden = true;
      box.innerHTML = "";
      return;
    }
    panel.hidden = false;
    box.innerHTML = "";
    list.forEach(function (act) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "bill-sii-chip" + (state.sii.selectedCode === act.codigo ? " is-selected" : "");
      var meta = [];
      if (act.categoria) meta.push(act.categoria);
      if (act.afecta_iva) meta.push("Afecta IVA");
      btn.innerHTML =
        '<span class="bill-sii-chip-code">' + escAttr(act.codigo || "") + "</span>" +
        '<span class="bill-sii-chip-glosa">' + escAttr(act.glosa || "") + "</span>" +
        (meta.length ? '<span class="bill-sii-chip-meta">' + escAttr(meta.join(" · ")) + "</span>" : "") +
        (act.has_design ? '<span class="bill-sii-badge">Diseño guardado</span>' : "");
      btn.addEventListener("click", function () {
        selectActivity(act);
      });
      box.appendChild(btn);
    });
  }

  function lookupSii(rut, opts) {
    opts = opts || {};
    if (!cfg.actions || !cfg.actions.lookupSii) {
      return Promise.resolve(null);
    }
    var status = $("bill-sii-status");
    var panel = $("bill-sii-panel");
    if (panel) panel.hidden = false;
    if (status) status.textContent = "Consultando SII…";
    return post(cfg.actions.lookupSii, { rut: rut })
      .then(function (data) {
        state.sii.rut = data.rut || rut;
        state.sii.razon_social = data.razon_social || "";
        state.sii.actividades = data.actividades || [];
        state.sii.designs = data.designs || [];
        if (status) {
          status.textContent = (data.from_cache ? "Caché SII · " : "SII · ") +
            (state.sii.actividades.length ? state.sii.actividades.length + " actividad(es)" : "sin actividades");
        }
        if (!fieldHasValue("bill-recv-name") && state.sii.razon_social) {
          if ($("bill-recv-name")) $("bill-recv-name").value = state.sii.razon_social;
        }
        // Si hay un solo diseño o una sola actividad con diseño, preseleccionar.
        var preselect = null;
        if (state.sii.actividades.length === 1) {
          preselect = state.sii.actividades[0];
        } else {
          var withDesign = state.sii.actividades.filter(function (a) { return a.has_design; });
          if (withDesign.length === 1) preselect = withDesign[0];
        }
        if (preselect && !fieldHasValue("bill-recv-giro")) {
          selectActivity(preselect);
        } else {
          renderSiiActivities();
          applyLocksAfterFill();
        }
        if (!opts.silent && state.sii.razon_social && !opts.hadLocal) {
          showAlert("Datos SII: " + state.sii.razon_social + ". Elige una actividad económica.");
        }
        return data;
      })
      .catch(function (err) {
        if (status) status.textContent = "SII no disponible";
        if (panel && !(state.sii.actividades || []).length) {
          panel.hidden = true;
        }
        if (!opts.silent && !opts.hadLocal) {
          showAlert((err && err.message) ? (err.message + " Completa los datos manualmente.") : "No se pudo consultar el SII. Completa manualmente.", true);
        }
        return null;
      });
  }

  function syncRutDisplay() {
    var rut = (($("bill-recv-rut") || {}).value || "").trim();
    if ($("bill-recv-rut-display")) $("bill-recv-rut-display").value = rut;
  }

  /**
   * Valida RUT chileno (mismo algoritmo que riverso_validate_rut).
   */
  function isValidRut(rut) {
    var clean = String(rut || "").replace(/[^0-9kK]/g, "");
    if (clean.length < 2) return false;
    var dv = clean.slice(-1).toUpperCase();
    var numero = clean.slice(0, -1);
    if (!/^[0-9]+$/.test(numero)) return false;
    var suma = 0;
    var multiplo = 2;
    for (var i = numero.length - 1; i >= 0; i--) {
      suma += parseInt(numero.charAt(i), 10) * multiplo;
      multiplo = multiplo < 7 ? multiplo + 1 : 2;
    }
    var resto = suma % 11;
    var calc = 11 - resto;
    var dvCalc;
    if (calc === 11) dvCalc = "0";
    else if (calc === 10) dvCalc = "K";
    else dvCalc = String(calc);
    return dv === dvCalc;
  }

  function showInvalidRutPopup() {
    showMissingPopup(
      "RUT no válido",
      "El RUT ingresado no es válido. Revisa el número y el dígito verificador.",
      ["RUT"]
    );
  }

  function validateStep1() {
    var type = currentTypeId();
    if (!type) {
      return { ok: false, title: "Tipo de documento", message: "Selecciona un tipo de documento habilitado.", missing: [] };
    }
    var issue = (($("bill-issue-date") || {}).value || "").trim();
    if (!issue || !/^\d{4}-\d{2}-\d{2}$/.test(issue)) {
      return { ok: false, title: "Datos incompletos", message: "Indica la fecha de emisión.", missing: ["Fecha de emisión"] };
    }
    if (!$("bill-payment")) {
      return { ok: false, title: "Datos incompletos", message: "Indica la forma de pago.", missing: ["Condiciones de pago"] };
    }

    if (type === 2) {
      var r = collectReceiver();
      var missing = [];
      if (!String(r.receiver_rut || "").trim()) missing.push("RUT");
      if (!String(r.receiver_legal_name || "").trim()) missing.push("Razón social");
      if (!String(r.receiver_activity || "").trim()) missing.push("Giro / actividad");
      if (!String(r.receiver_address || "").trim()) missing.push("Dirección");
      if (!String(r.receiver_district || "").trim()) missing.push("Comuna");
      if (!String(r.receiver_city || "").trim()) missing.push("Ciudad");
      if (!String(r.receiver_phone || "").trim()) missing.push("Teléfono");
      if (missing.length) {
        return {
          ok: false,
          title: "Faltan datos del receptor",
          message: "Para emitir una factura electrónica debes completar todos los datos obligatorios de facturación.",
          missing: missing,
        };
      }
      if (!isValidRut(r.receiver_rut)) {
        return {
          ok: false,
          title: "RUT no válido",
          message: "El RUT ingresado no es válido. Revisa el número y el dígito verificador.",
          missing: ["RUT"],
        };
      }
    }
    return { ok: true };
  }

  function emptyLine() {
    return { sku: "", description: "", quantity: 1, unit_price_bruto: 0, afecto: true };
  }

  function renderLines() {
    var tbody = $("bill-lines-body");
    if (!tbody) return;
    tbody.innerHTML = "";
    state.lines.forEach(function (line, idx) {
      var tr = document.createElement("tr");
      tr.innerHTML =
        '<td><input type="text" data-k="sku" data-i="' + idx + '" value="' + escAttr(line.sku) + '"></td>' +
        '<td><input type="text" data-k="description" data-i="' + idx + '" value="' + escAttr(line.description) + '"></td>' +
        '<td style="width:80px"><input type="number" min="0.001" step="0.001" data-k="quantity" data-i="' + idx + '" value="' + escAttr(line.quantity) + '"></td>' +
        '<td style="width:110px"><input type="number" min="0" step="1" data-k="unit_price_bruto" data-i="' + idx + '" value="' + escAttr(line.unit_price_bruto) + '"></td>' +
        '<td style="width:70px;text-align:center"><input type="checkbox" data-k="afecto" data-i="' + idx + '"' + (line.afecto ? " checked" : "") + "></td>" +
        '<td class="bill-line-total" data-i="' + idx + '">' + money((Number(line.quantity) || 0) * (Number(line.unit_price_bruto) || 0)) + "</td>" +
        '<td><button type="button" class="bill-btn bill-btn-secondary" data-rm="' + idx + '">×</button></td>';
      tbody.appendChild(tr);
    });
    recalcTotalsLocal();
  }

  function escAttr(v) {
    return String(v == null ? "" : v)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;");
  }

  function recalcTotalsLocal() {
    var net = 0;
    var iva = 0;
    var total = 0;
    state.lines.forEach(function (line) {
      var qty = Number(line.quantity) || 0;
      var bruto = Number(line.unit_price_bruto) || 0;
      var lineBruto = Math.round(qty * bruto * 100) / 100;
      total += lineBruto;
      if (line.afecto) {
        var lineNet = Math.round((lineBruto / 1.19) * 100) / 100;
        net += lineNet;
        iva += Math.round((lineBruto - lineNet) * 100) / 100;
      } else {
        net += lineBruto;
      }
    });
    net = Math.round(net * 100) / 100;
    iva = Math.round(iva * 100) / 100;
    total = Math.round(total * 100) / 100;
    if ($("bill-tot-net")) $("bill-tot-net").textContent = money(net);
    if ($("bill-tot-iva")) $("bill-tot-iva").textContent = money(iva);
    if ($("bill-tot-total")) $("bill-tot-total").textContent = money(total);
  }

  function syncLineFromInput(el) {
    var i = parseInt(el.getAttribute("data-i"), 10);
    var k = el.getAttribute("data-k");
    if (!state.lines[i] || !k) return;
    if (k === "afecto") {
      state.lines[i].afecto = !!el.checked;
    } else if (k === "quantity" || k === "unit_price_bruto") {
      state.lines[i][k] = parseFloat(el.value) || 0;
    } else {
      state.lines[i][k] = el.value;
    }
    var tot = document.querySelector('.bill-line-total[data-i="' + i + '"]');
    if (tot) {
      var line = state.lines[i];
      tot.textContent = money((Number(line.quantity) || 0) * (Number(line.unit_price_bruto) || 0));
    }
    recalcTotalsLocal();
  }

  function payloadBase() {
    var type = currentTypeId();
    var r = type === 2 ? collectReceiver() : emptyReceiverPayload();
    return Object.assign({
      document_type_id: type,
      issue_date: ($("bill-issue-date") || {}).value || cfg.todayDate,
      payment_conditions: ($("bill-payment") || {}).value || "0",
      quote_id: state.quoteId || 0,
      lines: state.lines,
    }, r);
  }

  /** Payload de previsualización: en boleta usa fecha/pago del paso 2. */
  function previewPayload() {
    var type = currentTypeId();
    var base = payloadBase();
    if (type === 37) {
      base.issue_date = ($("bill-boleta-issue-date") || {}).value
        || ($("bill-issue-date") || {}).value
        || cfg.todayDate;
      base.payment_conditions = ($("bill-boleta-payment") || {}).value
        || ($("bill-payment") || {}).value
        || "0";
    }
    if (state.estimatedFolio != null) {
      base.estimated_folio = String(state.estimatedFolio);
    }
    return base;
  }

  function hasPreviewLines() {
    return (state.lines || []).some(function (line) {
      return line && (Number(line.quantity) || 0) > 0;
    });
  }

  function closeAllPreviewMenus() {
    document.querySelectorAll(".bill-preview-menu").forEach(function (menu) {
      menu.hidden = true;
    });
    document.querySelectorAll(".bill-preview-toggle").forEach(function (btn) {
      btn.setAttribute("aria-expanded", "false");
    });
  }

  function togglePreviewMenu(toggleBtn) {
    if (!toggleBtn) return;
    var menuId = toggleBtn.getAttribute("aria-controls");
    var menu = menuId ? $(menuId) : null;
    if (!menu) return;
    var willOpen = !!menu.hidden;
    closeAllPreviewMenus();
    if (willOpen) {
      menu.hidden = false;
      toggleBtn.setAttribute("aria-expanded", "true");
    }
  }

  function openPdfPreview(data) {
    if (!data || !data.preview_pdf_base64) {
      showAlert(
        data && data.error_message
          ? String(data.error_message)
          : "Vista previa OK, pero FACTO no devolvió PDF.",
        true
      );
      return;
    }
    if (data.error_message) {
      showAlert(String(data.error_message), true);
      return;
    }
    showAlert("Vista previa OK (no se guardó ni envió al SII).");
    try {
      var w = window.open("", "_blank");
      if (w) {
        w.document.write(
          '<iframe src="data:application/pdf;base64,' +
            data.preview_pdf_base64 +
            '" style="width:100%;height:100%;border:0"></iframe>'
        );
      }
    } catch (e) { /* ignore */ }
  }

  function openHtmlPreview(html) {
    try {
      var w = window.open("", "_blank");
      if (w) {
        w.document.open();
        w.document.write(html);
        w.document.close();
      }
    } catch (e) {
      showAlert("No se pudo abrir la carta en una nueva pestaña.", true);
    }
  }

  function runPreview(kind) {
    if (!hasPreviewLines()) {
      showMissingPopup("Previsualizar", "Agrega al menos una línea con cantidad.", ["Líneas del documento"]);
      return;
    }
    var payload = previewPayload();
    if (kind === "pdf" || kind === "thermal50") {
      // FACTO asigna folio real con draft_preview; no llamar a la API.
      showAlert(
        "Vista previa PDF oficial / térmica detenida: FACTO asigna folio aunque se pida borrador. Usa Carta por familia o Carta por producto. La emisión solo con el botón Emitir.",
        true
      );
      return;
    }
    var template = kind === "product" ? "product" : "family";
    var action = (cfg.actions && cfg.actions.previewHtml) || "riverso_billing_preview_html";
    showAlert("Generando carta…");
    post(action, Object.assign({}, payload, { template: template }))
      .then(function (data) {
        if (!data || !data.html) {
          throw new Error("La carta llegó vacía.");
        }
        showAlert("Carta lista (borrador, no se envió al SII).");
        openHtmlPreview(data.html);
      })
      .catch(function (err) {
        showAlert(err.message || "Error al generar la carta", true);
      });
  }

  function goStep(n) {
    state.step = n;
    if ($("bill-step-1")) $("bill-step-1").hidden = n !== 1;
    if ($("bill-step-2")) $("bill-step-2").hidden = n !== 2;
    if ($("bill-done")) $("bill-done").hidden = n !== 3;
    if (n === 2) {
      syncStep2Mode();
    }
    showAlert("");
  }

  function syncStep2Mode() {
    var type = currentTypeId();
    var inv = $("bill-step-2-invoice");
    var bol = $("bill-step-2-boleta");
    if (inv) inv.hidden = type === 37;
    if (bol) bol.hidden = type !== 37;
    if (type === 37) {
      if ($("bill-boleta-folio-est")) {
        $("bill-boleta-folio-est").textContent = state.estimatedFolio != null ? String(state.estimatedFolio) : "—";
      }
      renderBoletaLines();
      renderRefs();
      renderPagosPanel();
      setBoletaTab(state.boletaTab || "detalles");
    }
  }

  function boletaTotals() {
    if (Lines && typeof Lines.getCommercialTotals === "function") {
      return Lines.getCommercialTotals();
    }
    var net = 0;
    var exento = 0;
    var iva = 0;
    var total = 0;
    state.lines.forEach(function (line) {
      var qty = Number(line.quantity) || 0;
      var bruto = Number(line.unit_price_bruto != null ? line.unit_price_bruto : line.unit_price) || 0;
      var lineBruto = line.line_total_bruto != null
        ? Number(line.line_total_bruto)
        : Math.round(qty * bruto * 100) / 100;
      total += lineBruto;
      if (line.afecto !== false) {
        var lineNet = Math.round((lineBruto / 1.19) * 100) / 100;
        net += lineNet;
        iva += Math.round((lineBruto - lineNet) * 100) / 100;
      } else {
        exento += lineBruto;
      }
    });
    return {
      net_amount: Math.round(net * 100) / 100,
      exempt_amount: Math.round(exento * 100) / 100,
      tax_amount: Math.round(iva * 100) / 100,
      total_amount: Math.round(total * 100) / 100,
    };
  }

  function scheduleDraftSave(ms) {
    if (state.draftSaveTimer) {
      clearTimeout(state.draftSaveTimer);
    }
    state.draftSaveTimer = setTimeout(function () {
      state.draftSaveTimer = null;
      saveDraft({ silent: true }).catch(function () { /* ignore autosave errors */ });
    }, ms == null ? 400 : ms);
  }

  function renderBoletaLines() {
    if (Lines && typeof Lines.render === "function") {
      Lines.render();
      return;
    }
    var tbody = $("bill-boleta-lines-body");
    if (!tbody) return;
    tbody.innerHTML = "";
  }

  function setBoletaTab(name) {
    state.boletaTab = name;
    var tabs = document.querySelectorAll("#bill-boleta-tabs .bill-tab");
    for (var i = 0; i < tabs.length; i++) {
      var on = tabs[i].getAttribute("data-tab") === name;
      tabs[i].classList.toggle("is-active", on);
      tabs[i].setAttribute("aria-selected", on ? "true" : "false");
    }
    var panels = document.querySelectorAll("#bill-step-2-boleta .bill-tab-panel");
    for (var j = 0; j < panels.length; j++) {
      panels[j].hidden = panels[j].getAttribute("data-panel") !== name;
    }
  }

  function renderRefs() {
    var list = $("bill-refs-list");
    if (!list) return;
    list.innerHTML = "";
    if (!state.refs.length) {
      list.innerHTML = "<li class='bill-hint' style='border:0'>Sin referencias</li>";
      return;
    }
    state.refs.forEach(function (ref, idx) {
      var li = document.createElement("li");
      var label = ref.ref_label || (ref.ref_type === "quote"
        ? ("Cotización " + (ref.quote_number || ("#" + ref.quote_id)))
        : ((ref.ref_doc_type || "Folio") + " " + (ref.ref_folio || "")));
      li.innerHTML = "<span>" + escAttr(label) + "</span>";
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "bill-btn bill-btn-secondary";
      btn.textContent = "Quitar";
      btn.addEventListener("click", function () {
        state.refs.splice(idx, 1);
        renderRefs();
      });
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  function canPay() {
    return state.draftStatus === "emitted" || state.draftStatus === "closed_local" || state.dteId > 0;
  }

  function paymentApplied(p) {
    var applied = Number(p.amount_applied);
    if (applied > 0) return applied;
    return Math.max(0, (Number(p.amount_paid) || 0) - (Number(p.change_amount) || 0));
  }

  function unpaidAmount() {
    var totalDue = boletaTotals().total_amount;
    if (state.dteTotal) totalDue = Number(state.dteTotal) || totalDue;
    var paid = (state.payments || []).reduce(function (s, p) { return s + paymentApplied(p); }, 0);
    return Math.max(0, Math.round(totalDue - paid));
  }

  function syncStatusHtml(p) {
    var st = p.facto_sync_status || "off";
    if (st === "ok") return '<span class="bill-sync bill-sync-ok" title="En FACTO">FACTO ok</span>';
    if (st === "pending" || st === "sending") return '<span class="bill-sync bill-sync-pending">Pendiente FACTO</span>';
    if (st === "error") {
      return '<span class="bill-sync bill-sync-error">Error</span> <button type="button" class="bill-btn bill-btn-secondary bill-pay-retry" data-id="' + p.id + '">Reintentar</button>';
    }
    if (st === "unknown") {
      return '<span class="bill-sync bill-sync-unknown">Desconocido</span> <button type="button" class="bill-btn bill-btn-secondary bill-pay-retry" data-id="' + p.id + '" data-unknown="1">Revisar / reintentar</button>';
    }
    return "";
  }

  function fillPagosBody(bodyId, cobrosId, pagosId, impagoId) {
    var body = $(bodyId);
    if (!body) return;
    body.innerHTML = "";
    var totalDue = boletaTotals().total_amount;
    if (state.dteTotal) totalDue = Number(state.dteTotal) || totalDue;
    var totalPaid = 0;
    var due = unpaidAmount();
    var trC = document.createElement("tr");
    var payBtn = due > 0
      ? '<button type="button" class="bill-btn bill-btn-primary bill-pago-open">Pagar (' + money(due) + ")</button>"
      : "";
    trC.innerHTML =
      "<td>COBRO</td><td>" + escAttr(($("bill-boleta-issue-date") || $("bill-issue-date") || {}).value || "") + "</td>" +
      "<td>Cobro contado</td><td></td><td></td><td></td><td></td><td></td><td>" + money(totalDue) + "</td>" +
      "<td>" + payBtn + ' <button type="button" class="bill-btn bill-btn-wip" disabled>Borrar [WIP]</button></td>';
    body.appendChild(trC);
    (state.payments || []).forEach(function (p) {
      var applied = paymentApplied(p);
      totalPaid += applied;
      var tr = document.createElement("tr");
      tr.innerHTML =
        "<td>PAGO</td><td>" + escAttr(p.pay_date || "") + "</td>" +
        "<td>" + escAttr(p.method || "") + "</td><td>" + escAttr(p.caja || "") + "</td>" +
        "<td>" + escAttr(p.cheque_numero || "") + "</td><td>" + escAttr(p.cheque_titular || "") + "</td>" +
        "<td>" + escAttr(p.cheque_banco || "") + "</td>" +
        "<td>" + escAttr(p.notes || "") + (p.facto_payment_id ? " · Asociado a P" + p.facto_payment_id : "") + "</td>" +
        "<td>-" + money(applied) + "</td>" +
        '<td>' + syncStatusHtml(p) + ' <button type="button" class="bill-btn bill-btn-danger bill-pay-del" data-id="' + p.id + '">Borrar</button></td>';
      body.appendChild(tr);
    });
    if ($(cobrosId)) $(cobrosId).textContent = money(totalDue);
    if ($(pagosId)) {
      $(pagosId).textContent = money(totalPaid);
      $(pagosId).classList.toggle("is-zero", totalPaid <= 0);
    }
    if ($(impagoId)) $(impagoId).textContent = money(Math.max(0, totalDue - totalPaid));
    body.querySelectorAll(".bill-pago-open").forEach(function (btn) {
      btn.addEventListener("click", openPagoModal);
    });
    body.querySelectorAll(".bill-pay-retry").forEach(function (btn) {
      btn.addEventListener("click", function () {
        retryPayment(parseInt(btn.getAttribute("data-id"), 10) || 0, btn.getAttribute("data-unknown") === "1");
      });
    });
    body.querySelectorAll(".bill-pay-del").forEach(function (btn) {
      btn.addEventListener("click", function () {
        deletePayment(parseInt(btn.getAttribute("data-id"), 10) || 0);
      });
    });
  }

  function renderPagosPanel() {
    var open = canPay();
    if ($("bill-pagos-draft-msg")) $("bill-pagos-draft-msg").hidden = open;
    if ($("bill-pagos-emitted")) $("bill-pagos-emitted").hidden = !open;
    if (open) fillPagosBody("bill-pagos-body", "bill-pagos-cobros", "bill-pagos-pagos", "bill-pagos-impago");
    if (state.dteId) {
      if ($("bill-done-pagos")) $("bill-done-pagos").hidden = false;
      fillPagosBody("bill-done-pagos-body", "bill-done-pagos-cobros", "bill-done-pagos-pagos", "bill-done-pagos-impago");
    }
  }

  function applyPagoCajas(boxes) {
    boxes = boxes || [];
    cfg.cashBoxes = boxes;
    ["bill-pago-caja", "bill-emit-caja"].forEach(function (id) {
      var sel = $(id);
      if (!sel) return;
      if (!boxes.length) {
        sel.innerHTML = '<option value="">No hay cajas abiertas</option>';
        sel.disabled = true;
        return;
      }
      sel.disabled = false;
      sel.innerHTML = boxes
        .map(function (b) {
          return '<option value="' + String(b.id) + '" data-nombre="' + escAttr(b.nombre) + '">' + escAttr(b.nombre) + "</option>";
        })
        .join("");
    });
  }

  function paymentMethods() {
    return cfg.paymentMethods || [];
  }

  function fillPagoMethods() {
    var html = paymentMethods().map(function (m) {
      return '<option value="' + String(m.id) + '">' + escAttr(m.nombre) + "</option>";
    }).join("");
    ["bill-pago-method", "bill-emit-method"].forEach(function (id) {
      if ($(id)) $(id).innerHTML = html || '<option value="">Sin métodos</option>';
    });
    toggleChequeFields("bill-pago-method", "bill-pago-cheque");
    toggleChequeFields("bill-emit-method", "bill-emit-cheque");
  }

  function methodById(id) {
    id = parseInt(id, 10) || 0;
    return paymentMethods().filter(function (m) { return Number(m.id) === id; })[0] || null;
  }

  function toggleChequeFields(selectId, wrapId) {
    var m = methodById(($(selectId) || {}).value);
    var wrap = $(wrapId);
    if (wrap) wrap.hidden = !(m && m.requiere_cheque);
  }

  function fillPagoCajas() {
    applyPagoCajas(cfg.cashBoxes || []);
    if (!cfg.actions || !cfg.actions.cashBoxes) return Promise.resolve();
    return post(cfg.actions.cashBoxes, {})
      .then(function (data) {
        applyPagoCajas(data.cashBoxes || []);
      })
      .catch(function () {
        /* mantener snapshot de página */
      });
  }

  function openPagoModal() {
    var due = unpaidAmount();
    if (due <= 0) {
      showAlert("El documento ya está pagado.");
      return;
    }
    if ($("bill-pago-due")) $("bill-pago-due").value = money(due);
    if ($("bill-pago-paid")) $("bill-pago-paid").value = String(Math.round(due));
    if ($("bill-pago-vuelto")) $("bill-pago-vuelto").value = "0";
    if ($("bill-pago-notes")) $("bill-pago-notes").value = "";
    ["bill-pago-cheque-num", "bill-pago-cheque-tit", "bill-pago-cheque-banco"].forEach(function (id) {
      if ($(id)) $(id).value = "";
    });
    fillPagoMethods();
    fillPagoCajas();
    if ($("bill-pago-modal")) {
      $("bill-pago-modal").hidden = false;
      $("bill-pago-modal").setAttribute("aria-hidden", "false");
    }
  }

  function closePagoModal() {
    if ($("bill-pago-modal")) {
      $("bill-pago-modal").hidden = true;
      $("bill-pago-modal").setAttribute("aria-hidden", "true");
    }
  }

  function saveDraft(opts) {
    opts = opts || {};
    var t = boletaTotals();
    var data = {
      draft_id: state.draftId || 0,
      status: state.draftStatus || "draft",
      issue_date: ($("bill-boleta-issue-date") || {}).value || cfg.todayDate,
      due_date: ($("bill-boleta-due-date") || {}).value || "",
      payment_conditions: ($("bill-boleta-payment") || {}).value || "0",
      sale_state: "VENTA: Concretada",
      quote_id: state.quoteId || 0,
      net_amount: t.net_amount,
      exempt_amount: t.exempt_amount,
      tax_amount: t.tax_amount,
      total_amount: t.total_amount,
      lines: state.lines,
      refs: state.refs,
    };
    return post(cfg.actions.draftSave, data).then(function (res) {
      if (res.draft) {
        if (opts.silent) {
          applyDraftMeta(res.draft);
        } else {
          applyDraft(res.draft);
        }
      }
      if (!opts.silent) showAlert("Borrador guardado.");
      return res;
    });
  }

  /** Autoguardado: solo id/metadatos; no pisa precios ni vuelve a pedir la regla. */
  function applyDraftMeta(draft) {
    if (!draft) return;
    state.draftId = draft.id || 0;
    state.draftStatus = draft.status || "draft";
    if ($("bill-draft-id")) $("bill-draft-id").value = String(state.draftId);
    if (draft.issue_date && $("bill-boleta-issue-date")) $("bill-boleta-issue-date").value = draft.issue_date;
    if ($("bill-boleta-due-date") && draft.due_date !== undefined) {
      $("bill-boleta-due-date").value = draft.due_date || "";
    }
    if (draft.payment_conditions && $("bill-boleta-payment")) {
      $("bill-boleta-payment").value = draft.payment_conditions;
    }
    if (draft.quote_id) state.quoteId = draft.quote_id;
    if ($("bill-draft-banner-text")) {
      $("bill-draft-banner-text").textContent = bannerText(state.draftStatus);
    }
  }

  function bannerText(status) {
    if (status === "emitted") return "DOC EMITIDO";
    if (status === "closed_local") return "DOC CERRADO (sin SII)";
    return "DOC EN BORRADOR";
  }

  function applyDraft(draft) {
    state.draftId = draft.id || 0;
    state.draftStatus = draft.status || "draft";
    if (draft.dte_id) state.dteId = draft.dte_id;
    if ($("bill-draft-id")) $("bill-draft-id").value = String(state.draftId);
    if (draft.issue_date && $("bill-boleta-issue-date")) $("bill-boleta-issue-date").value = draft.issue_date;
    if ($("bill-boleta-due-date")) $("bill-boleta-due-date").value = draft.due_date || "";
    if (draft.payment_conditions && $("bill-boleta-payment")) $("bill-boleta-payment").value = draft.payment_conditions;
    state.lines = (draft.lines || []).map(function (l) {
      var unit = Number(l.unit_price != null ? l.unit_price : l.unit_price_bruto) || 0;
      return {
        sku: l.sku || "",
        description: l.description || "",
        quantity: l.quantity || 1,
        unit_price: unit,
        unit_price_bruto: Number(l.unit_price_bruto != null ? l.unit_price_bruto : unit) || 0,
        line_total_bruto: l.line_total_bruto != null ? Number(l.line_total_bruto) : null,
        afecto: l.afecto !== false,
        product_id: l.product_id || null,
        producto_base_id: l.producto_base_id || null,
        units_per_pack: l.units_per_pack != null ? Number(l.units_per_pack) : 1,
        family_mode: l.family_mode || "",
        packaging: l.packaging || "",
        grupo_id: l.grupo_id || null,
        unit_cost: l.unit_cost != null ? l.unit_cost : null,
        price_mode: l.price_mode || "auto",
        price_ref: l.price_ref != null ? l.price_ref : null,
        price_total: l.price_total != null ? l.price_total : null,
        price_discount: Number(l.price_discount) || 0,
        margin_discount: Number(l.margin_discount) || 0,
        discount_amount: Number(l.discount_amount) || 0,
        rule_total: l.rule_total != null ? l.rule_total : null,
        rule_adjusted: !!l.rule_adjusted,
        _rule_total: l._rule_total != null ? l._rule_total : (l.rule_adjusted ? l.rule_total : null),
        _rule_adjusted: !!(l._rule_adjusted || l.rule_adjusted),
        _family: l._family || {},
      };
    });
    state.refs = draft.refs || [];
    state.payments = draft.payments || [];
    if (draft.quote_id) state.quoteId = draft.quote_id;
    if ($("bill-draft-banner-text")) {
      $("bill-draft-banner-text").textContent = bannerText(state.draftStatus);
    }
    if (Lines && typeof Lines.hydrateAfterLoad === "function") {
      Lines.hydrateAfterLoad();
    } else {
      renderBoletaLines();
    }
    renderRefs();
    renderPagosPanel();
  }

  function createBoletaDraftAndOpen() {
    // Sync issue/payment from step 1.
    if ($("bill-boleta-issue-date") && $("bill-issue-date")) {
      $("bill-boleta-issue-date").value = $("bill-issue-date").value || cfg.todayDate;
    }
    if ($("bill-boleta-payment") && $("bill-payment")) {
      $("bill-boleta-payment").value = $("bill-payment").value || "0";
    }
    if (state.quoteId && !state.refs.some(function (r) { return r.ref_type === "quote" && Number(r.quote_id) === Number(state.quoteId); })) {
      state.refs.push({
        ref_type: "quote",
        quote_id: state.quoteId,
        quote_number: "",
        ref_label: "Cotización #" + state.quoteId,
      });
    }
    if (!state.lines.length) state.lines = [];
    var btn = $("bill-to-step-2");
    if (btn) btn.disabled = true;
    showAlert("Creando borrador…");
    return saveDraft({ silent: true })
      .then(function () {
        goStep(2);
        showAlert("Borrador listo. Agrega productos en Detalles.");
      })
      .catch(function (e) {
        showAlert(e.message || "No se pudo crear el borrador", true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function lookupProduct(q) {
    q = (q || "").trim();
    if (!q) return Promise.resolve([]);
    return post(cfg.actions.productLookup, { q: q }).then(function (data) {
      return data.products || [];
    });
  }

  function addProductLine(p) {
    if (Lines && typeof Lines.addCatalogProduct === "function") {
      return Lines.addCatalogProduct(p);
    }
    var unit = Number(p.unit_price != null ? p.unit_price : p.unit_price_bruto) || 0;
    state.lines.push({
      sku: p.sku || "",
      description: p.description || p.sku || "Producto",
      quantity: 1,
      unit_price: unit,
      unit_price_bruto: unit,
      afecto: p.afecto !== false,
      product_id: p.product_id || null,
      producto_base_id: p.producto_base_id || null,
      units_per_pack: p.units_per_pack != null ? Number(p.units_per_pack) : 1,
      _family: p.family || {},
      price_mode: "auto",
    });
    renderBoletaLines();
  }

  var MANUAL_IVA = 1.19;

  function parseClNumber(value) {
    if (value == null || value === "") return 0;
    var s = String(value).trim().replace(/\s/g, "").replace(/\./g, "").replace(",", ".");
    var n = parseFloat(s);
    return isFinite(n) ? n : 0;
  }

  function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
  }

  function round3(n) {
    return Math.round((Number(n) || 0) * 1000) / 1000;
  }

  function clampRate(value) {
    var n = Number(value);
    if (!isFinite(n) || n < 0) return 0;
    if (n > 100) return 100;
    return n;
  }

  function formatModalNumber(value, decimals) {
    var n = Number(value) || 0;
    var d = decimals != null ? decimals : 2;
    return new Intl.NumberFormat("es-CL", {
      minimumFractionDigits: 0,
      maximumFractionDigits: d
    }).format(n);
  }

  function setManualHint(text, isError) {
    var el = $("bill-manual-hint");
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("is-error", !!isError && !!text);
  }

  function resetManualForm() {
    if ($("bill-manual-qty")) $("bill-manual-qty").value = "1";
    if ($("bill-manual-unit")) $("bill-manual-unit").value = "";
    if ($("bill-manual-concepto")) $("bill-manual-concepto").value = "";
    if ($("bill-manual-desc-larga")) $("bill-manual-desc-larga").value = "";
    if ($("bill-manual-desc-wrap")) $("bill-manual-desc-wrap").hidden = true;
    if ($("bill-manual-desc-toggle")) $("bill-manual-desc-toggle").setAttribute("aria-expanded", "false");
    if ($("bill-manual-unit-neto")) $("bill-manual-unit-neto").value = "0";
    if ($("bill-manual-unit-bruto")) $("bill-manual-unit-bruto").value = "0";
    if ($("bill-manual-adj-type")) $("bill-manual-adj-type").value = "descuento";
    if ($("bill-manual-pct")) $("bill-manual-pct").value = "0";
    if ($("bill-manual-total-neto")) $("bill-manual-total-neto").value = "0";
    if ($("bill-manual-total-bruto")) $("bill-manual-total-bruto").value = "0";
  }

  function openManualModal() {
    resetManualForm();
    setManualHint("");
    if ($("bill-manual-modal")) {
      $("bill-manual-modal").hidden = false;
      $("bill-manual-modal").setAttribute("aria-hidden", "false");
    }
    var concepto = $("bill-manual-concepto");
    if (concepto) concepto.focus();
    else if ($("bill-manual-qty")) $("bill-manual-qty").focus();
  }

  function closeManualModal() {
    if ($("bill-manual-modal")) {
      $("bill-manual-modal").hidden = true;
      $("bill-manual-modal").setAttribute("aria-hidden", "true");
    }
    setManualHint("");
  }

  function toggleManualDescLarga() {
    var wrap = $("bill-manual-desc-wrap");
    var toggle = $("bill-manual-desc-toggle");
    if (!wrap || !toggle) return;
    var open = wrap.hidden;
    wrap.hidden = !open;
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    if (open && $("bill-manual-desc-larga")) $("bill-manual-desc-larga").focus();
  }

  function syncManualTotals() {
    var qty = parseClNumber(($("bill-manual-qty") || {}).value);
    if (!(qty > 0)) qty = 0;
    var unitBruto = parseClNumber(($("bill-manual-unit-bruto") || {}).value);
    if (unitBruto < 0) unitBruto = 0;
    var pct = clampRate(parseClNumber(($("bill-manual-pct") || {}).value));
    var adj = (($("bill-manual-adj-type") || {}).value) || "descuento";
    var effectiveBruto = unitBruto;
    if (adj === "recargo" && pct > 0) {
      effectiveBruto = round2(unitBruto * (1 + pct / 100));
    }
    var totalBruto = round2(qty * effectiveBruto);
    if (adj === "descuento" && pct > 0) {
      totalBruto = round2(totalBruto * (1 - pct / 100));
    }
    if (totalBruto < 0) totalBruto = 0;
    var totalNeto = round2(totalBruto / MANUAL_IVA);
    if ($("bill-manual-total-neto")) $("bill-manual-total-neto").value = formatModalNumber(totalNeto, 2);
    if ($("bill-manual-total-bruto")) $("bill-manual-total-bruto").value = formatModalNumber(totalBruto, 2);
  }

  function onManualPriceInput(sourceKey) {
    if (sourceKey === "unit-neto" && $("bill-manual-unit-neto") && $("bill-manual-unit-bruto")) {
      var neto = parseClNumber($("bill-manual-unit-neto").value);
      if (neto < 0) neto = 0;
      $("bill-manual-unit-bruto").value = formatModalNumber(round2(neto * MANUAL_IVA), 2);
    } else if (sourceKey === "unit-bruto" && $("bill-manual-unit-bruto") && $("bill-manual-unit-neto")) {
      var bruto = parseClNumber($("bill-manual-unit-bruto").value);
      if (bruto < 0) bruto = 0;
      $("bill-manual-unit-neto").value = formatModalNumber(round2(bruto / MANUAL_IVA), 2);
    }
    syncManualTotals();
  }

  function buildManualDescription() {
    var concepto = String(($("bill-manual-concepto") || {}).value || "").trim().replace(/\s+/g, " ");
    var unit = String(($("bill-manual-unit") || {}).value || "").trim();
    var larga = String(($("bill-manual-desc-larga") || {}).value || "").trim().replace(/\s+/g, " ");
    var parts = [];
    if (concepto) {
      parts.push(unit ? (concepto + " (" + unit + ")") : concepto);
    }
    if (larga) parts.push(larga);
    var desc = parts.join(" — ");
    if (desc.length > 500) desc = desc.slice(0, 500);
    return desc;
  }

  function saveManualLine() {
    var qty = parseClNumber(($("bill-manual-qty") || {}).value);
    if (!(qty > 0)) {
      setManualHint("La cantidad debe ser mayor a cero.", true);
      if ($("bill-manual-qty")) $("bill-manual-qty").focus();
      return;
    }
    var concepto = String(($("bill-manual-concepto") || {}).value || "").trim();
    if (!concepto) {
      setManualHint("El concepto es obligatorio.", true);
      if ($("bill-manual-concepto")) $("bill-manual-concepto").focus();
      return;
    }
    var description = buildManualDescription();
    if (!description) {
      setManualHint("El concepto es obligatorio.", true);
      if ($("bill-manual-concepto")) $("bill-manual-concepto").focus();
      return;
    }
    var unitBruto = parseClNumber(($("bill-manual-unit-bruto") || {}).value);
    if (unitBruto < 0) unitBruto = 0;
    var pct = clampRate(parseClNumber(($("bill-manual-pct") || {}).value));
    var adj = (($("bill-manual-adj-type") || {}).value) || "descuento";
    var priceDiscount = 0;
    if (adj === "recargo" && pct > 0) {
      unitBruto = round2(unitBruto * (1 + pct / 100));
    } else if (adj === "descuento" && pct > 0) {
      priceDiscount = pct;
    }
    var payload = {
      sku: "",
      description: description,
      quantity: round3(qty),
      unit_price: round2(unitBruto),
      price_discount: priceDiscount,
      afecto: true,
    };
    if (Lines && typeof Lines.addManualLine === "function") {
      Lines.addManualLine(payload);
    } else {
      addProductLine(payload);
    }
    closeManualModal();
    showAlert("Detalle manual agregado.", false);
  }

  function ensureCustomerThenContinue() {
    var check = validateStep1();
    if (!check.ok) {
      showMissingPopup(check.title, check.message, check.missing);
      return;
    }
    var type = currentTypeId();

    if (type === 37) {
      createBoletaDraftAndOpen();
      return;
    }

    var r = collectReceiver();
    var btn = $("bill-to-step-2");
    if (btn) btn.disabled = true;
    showAlert("Validando y guardando receptor…");
    post(cfg.actions.ensureCustomer, Object.assign({}, r, { document_type_id: type }))
      .then(function (data) {
        if (data.customer && data.customer.id && $("bill-customer-id")) {
          $("bill-customer-id").value = String(data.customer.id);
        }
        if (data.created) {
          showAlert("Cliente creado para próximas emisiones.");
        } else {
          showAlert("");
        }
        if (!state.lines.length) {
          state.lines = [emptyLine()];
          renderLines();
        }
        goStep(2);
      })
      .catch(function (e) {
        showMissingPopup(
          "No se pudo continuar",
          e.message || "No se pudo validar el receptor",
          []
        );
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function searchByName() {
    var q = (($("bill-name-search") || {}).value || "").trim();
    if (!q) {
      showAlert("Ingresa nombre, apodo o razón social.", true);
      return;
    }
    var box = $("bill-customer-results");
    if (box) {
      box.hidden = false;
      box.innerHTML = "<div style='padding:10px'>Buscando…</div>";
    }
    post(cfg.actions.searchCustomers, { search: q, mode: "name" })
      .then(function (data) {
        var list = data.customers || [];
        if (!box) return;
        if (!list.length) {
          box.innerHTML = "<div style='padding:10px'>Sin resultados en clientes locales</div>";
          return;
        }
        box.innerHTML = "";
        list.forEach(function (c) {
          var btn = document.createElement("button");
          btn.type = "button";
          btn.textContent = (c.razon_social || c.nombre_fantasia || "Cliente") +
            (c.rut ? " · " + c.rut : "") +
            (c.has_facturacion ? "" : " (sin facturación)");
          btn.addEventListener("click", function () {
            fillReceiver(c, true);
            box.hidden = true;
            showAlert("");
            if (c.rut) {
              lookupSii(c.rut, { silent: true, hadLocal: true });
            }
          });
          box.appendChild(btn);
        });
      })
      .catch(function (err) {
        showAlert(err.message || "Error al buscar", true);
        if (box) box.hidden = true;
      });
  }

  function lookupRut(opts) {
    opts = opts || {};
    var rut = (($("bill-recv-rut") || {}).value || "").trim();
    syncRutDisplay();
    if (!rut) {
      clearReceiverDetails(false);
      if (!opts.silent) showAlert("Ingresa un RUT para buscar.", true);
      return Promise.resolve();
    }
    if (!isValidRut(rut)) {
      clearSiiPanel();
      if ($("bill-customer-id")) $("bill-customer-id").value = "0";
      ["bill-recv-name", "bill-recv-giro", "bill-recv-address", "bill-recv-city", "bill-recv-phone", "bill-recv-postal"].forEach(function (id) {
        if ($(id)) $(id).value = "";
      });
      setComunaValue("");
      lockAllDetails();
      if (!opts.silent) showInvalidRutPopup();
      return Promise.resolve();
    }
    clearSiiPanel();
    if ($("bill-recv-activity-code")) $("bill-recv-activity-code").value = "";
    return post(cfg.actions.lookupRut, { rut: rut })
      .then(function (data) {
        var hadLocal = !!(data.found && data.customer);
        if (hadLocal) {
          fillReceiver(data.customer, true);
          if (!opts.silent) showAlert("Cliente encontrado en Riverso. Consultando actividades SII…");
        } else {
          if ($("bill-customer-id")) $("bill-customer-id").value = "0";
          if (data.rut && $("bill-recv-rut")) $("bill-recv-rut").value = data.rut;
          syncRutDisplay();
          ["bill-recv-name", "bill-recv-giro", "bill-recv-address", "bill-recv-city", "bill-recv-phone", "bill-recv-postal"].forEach(function (id) {
            if ($(id)) $(id).value = "";
          });
          setComunaValue("");
          unlockAllDetails();
          if (!opts.silent) {
            showAlert("RUT no está en clientes locales. Consultando SII…");
          }
        }
        var rutForSii = (data.rut || rut);
        return lookupSii(rutForSii, { silent: opts.silent, hadLocal: hadLocal });
      })
      .catch(function (err) {
        if (!opts.silent) {
          var msg = (err && err.message) ? err.message : "Error al buscar RUT";
          if (/rut no v[aá]lido/i.test(msg)) {
            showInvalidRutPopup();
          } else {
            showAlert(msg, true);
          }
        }
      });
  }

  function fillReceiver(c, applyLocks) {
    if ($("bill-customer-id")) $("bill-customer-id").value = String(c.id || 0);
    if ($("bill-recv-rut")) $("bill-recv-rut").value = c.rut || "";
    syncRutDisplay();
    if ($("bill-recv-name")) $("bill-recv-name").value = c.razon_social || c.nombre_fantasia || "";
    if ($("bill-recv-giro")) $("bill-recv-giro").value = c.giro || "";
    if ($("bill-recv-address")) $("bill-recv-address").value = c.direccion || "";
    setComunaValue(c.comuna || "");
    if ($("bill-recv-city")) $("bill-recv-city").value = c.ciudad || "";
    if ($("bill-recv-phone")) $("bill-recv-phone").value = c.facturacion_telefono || c.telefono || "";
    if ($("bill-recv-postal")) {
      var postal = c.codigo_postal || "";
      $("bill-recv-postal").value = (postal === "0" ? "" : postal);
    }
    if (applyLocks !== false) {
      applyLocksAfterFill();
    }
  }

  function loadDraftIfAny() {
    var draftId = Number(cfg.draftId || state.draftId || 0) || 0;
    if (!draftId) return false;
    var action = (cfg.actions && cfg.actions.draftGet) || "riverso_billing_draft_get";
    showAlert("Cargando borrador…");
    post(action, { draft_id: draftId })
      .then(function (data) {
        if (!data || !data.draft) {
          throw new Error("Borrador no encontrado.");
        }
        if ($("bill-doc-type")) {
          $("bill-doc-type").value = String(data.draft.document_type_id || 37);
        }
        state.docType = Number(data.draft.document_type_id || 37) || 37;
        applyDraft(data.draft);
        updateFolioCard();
        goStep(2);
        showAlert("Borrador #" + draftId + " cargado.");
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo cargar el borrador", true);
        if (!state.lines.length) {
          state.lines = [emptyLine()];
          renderLines();
        }
        lockAllDetails();
      });
    return true;
  }

  function loadQuoteIfAny() {
    if (!state.quoteId) {
      if (!state.lines.length) {
        state.lines = [emptyLine()];
        renderLines();
      }
      lockAllDetails();
      return;
    }
    post(cfg.actions.loadQuote, { quote_id: state.quoteId })
      .then(function (data) {
        if (data.already_emitted) {
          showAlert("Esta cotización ya tiene DTE folio " + (data.already_emitted.folio || "") + ".", true);
        }
        var q = data.quote || {};
        var meta = $("bill-quote-meta");
        if (meta) {
          meta.hidden = false;
          meta.textContent = "Cotización " + (q.quote_number || "#" + q.id) +
            (q.customer_name ? " · " + q.customer_name : "");
        }
        if (q.issue_date && $("bill-issue-date")) {
          $("bill-issue-date").value = q.issue_date;
        }
        if (data.receiver) {
          fillReceiver({
            id: data.receiver.id,
            rut: data.receiver.rut,
            razon_social: data.receiver.razon_social,
            giro: data.receiver.giro,
            direccion: data.receiver.direccion,
            comuna: data.receiver.comuna,
            ciudad: data.receiver.ciudad,
            facturacion_telefono: data.receiver.telefono,
            codigo_postal: data.receiver.codigo_postal,
          }, true);
          if (data.receiver.rut && $("bill-doc-type")) {
            $("bill-doc-type").value = "2";
            updateFolioCard();
            estimateFolio();
          }
          if (data.receiver.rut) {
            lookupSii(data.receiver.rut, { silent: true, hadLocal: true });
          }
        } else {
          lockAllDetails();
        }
        state.lines = (data.lines || []).map(function (l) {
          var unit = Number(l.unit_price_bruto != null ? l.unit_price_bruto : l.unit_price) || 0;
          return {
            sku: l.sku || "",
            description: l.description || "",
            quantity: l.quantity || 1,
            unit_price: unit,
            unit_price_bruto: unit,
            afecto: l.afecto !== false,
            product_id: l.product_id || null,
            producto_base_id: l.producto_base_id || null,
            units_per_pack: l.units_per_pack != null ? Number(l.units_per_pack) : 1,
            _family: l._family || l.family || {},
            price_mode: "auto",
            price_discount: 0,
            margin_discount: 0,
            discount_amount: 0,
          };
        });
        if (!state.lines.length) state.lines = [];
        renderLines();
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo cargar la cotización", true);
        state.lines = [emptyLine()];
        renderLines();
        lockAllDetails();
      });
  }

  function updatePagoVuelto() {
    var dueTxt = (($("bill-pago-due") || {}).value || "").replace(/[^0-9]/g, "");
    var due = parseFloat(dueTxt) || 0;
    var paid = parseFloat(($("bill-pago-paid") || {}).value) || 0;
    var m = methodById(($("bill-pago-method") || {}).value);
    var vuelto = m && m.permite_vuelto ? Math.max(0, Math.round(paid - due)) : 0;
    if ($("bill-pago-vuelto")) $("bill-pago-vuelto").value = String(vuelto);
  }

  function applyPaymentsPayload(res) {
    if (res.draft) applyDraft(res.draft);
    if (res.payments) state.payments = res.payments;
    renderPagosPanel();
  }

  function savePago() {
    if (!state.draftId && !state.dteId) {
      showAlert("Guarda o emite el documento primero.", true);
      return;
    }
    var due = unpaidAmount();
    var paid = parseFloat(($("bill-pago-paid") || {}).value) || 0;
    var m = methodById(($("bill-pago-method") || {}).value);
    if (!m) {
      showMissingPopup("Pago", "Selecciona un método de pago.", []);
      return;
    }
    if (!(m.permite_vuelto) && paid > due) {
      showMissingPopup("Pago", "Este método no permite vuelto. El monto no puede superar el saldo.", []);
      return;
    }
    var cajaSel = $("bill-pago-caja");
    var cajaId = cajaSel ? parseInt(cajaSel.value, 10) || 0 : 0;
    if (!cajaId) {
      showMissingPopup("Pago", "No hay cajas abiertas con permiso para pagar. Abre una caja en Manejo de Caja.", []);
      return;
    }
    var cajaNombre = "";
    if (cajaSel && cajaSel.selectedOptions && cajaSel.selectedOptions[0]) {
      cajaNombre = cajaSel.selectedOptions[0].getAttribute("data-nombre") || cajaSel.selectedOptions[0].textContent || "";
    }
    var vuelto = m.permite_vuelto ? Math.max(0, paid - due) : 0;
    post(cfg.actions.draftPayment, {
      draft_id: state.draftId,
      dte_id: state.dteId,
      pay_date: ($("bill-pago-fecha") || {}).value || cfg.todayDate,
      caja_id: cajaId,
      caja: cajaNombre,
      method_id: m.id,
      amount_due: due,
      amount_paid: paid,
      change_amount: vuelto,
      notes: ($("bill-pago-notes") || {}).value || "",
      cheque_numero: ($("bill-pago-cheque-num") || {}).value || "",
      cheque_titular: ($("bill-pago-cheque-tit") || {}).value || "",
      cheque_banco: ($("bill-pago-cheque-banco") || {}).value || "",
    })
      .then(function (res) {
        applyPaymentsPayload(res);
        var left = unpaidAmount();
        if (left > 0) {
          openPagoModal();
          showAlert("Pago registrado. Quedan " + money(left) + ".");
        } else {
          closePagoModal();
          showAlert("Pago registrado. Documento saldado.");
        }
      })
      .catch(function (err) {
        showMissingPopup("Pago", err.message || "No se pudo registrar", []);
      });
  }

  function retryPayment(id, unknown) {
    if (!id) return;
    if (unknown && !window.confirm("Reintentar un pago desconocido puede duplicarlo en FACTO. ¿Continuar?")) return;
    post(cfg.actions.paymentRetry, { payment_id: id, confirm_unknown: unknown ? 1 : 0 })
      .then(function (res) {
        applyPaymentsPayload(res);
        showAlert((res.sync && res.sync.message) || "Sincronización actualizada.");
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo reintentar", true);
      });
  }

  function deletePayment(id) {
    if (!id) return;
    if (!window.confirm("¿Borrar este pago? Se revertirá el movimiento de caja.")) return;
    post(cfg.actions.paymentDelete, { payment_id: id })
      .then(function (res) {
        applyPaymentsPayload(res);
        showAlert("Pago borrado.");
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo borrar", true);
      });
  }

  function syncEmitPayFieldsEnabled() {
    var on = $("bill-emit-mark-paid") && $("bill-emit-mark-paid").checked;
    var wrap = $("bill-emit-pay-fields");
    if (wrap) {
      wrap.classList.toggle("is-disabled", !on);
      wrap.querySelectorAll("input, select, textarea").forEach(function (el) {
        el.disabled = !on;
      });
    }
    if (on) {
      toggleChequeFields("bill-emit-method", "bill-emit-cheque");
      var m = methodById(($("bill-emit-method") || {}).value);
      var chequeOn = !!(m && m.requiere_cheque);
      ["bill-emit-cheque-num", "bill-emit-cheque-tit", "bill-emit-cheque-banco"].forEach(function (id) {
        if ($(id)) $(id).disabled = !chequeOn;
      });
    } else if ($("bill-emit-cheque")) {
      $("bill-emit-cheque").hidden = true;
    }
  }

  function openEmitModal() {
    fillPagoMethods();
    fillPagoCajas();
    var type = currentTypeId();
    if ($("bill-emit-close-local")) {
      $("bill-emit-close-local").disabled = type !== 37;
      $("bill-emit-close-local").title = type !== 37
        ? "Solo disponible en boleta"
        : "";
    }
    if ($("bill-emit-email-to") && !$("bill-emit-email-to").value) {
      var email = (($("bill-recv-email") || {}).value || "").trim();
      $("bill-emit-email-to").value = email;
    }
    syncEmitPayFieldsEnabled();
    if ($("bill-emit-modal")) {
      $("bill-emit-modal").hidden = false;
      $("bill-emit-modal").setAttribute("aria-hidden", "false");
    }
  }

  function closeEmitModal() {
    if ($("bill-emit-modal")) {
      $("bill-emit-modal").hidden = true;
      $("bill-emit-modal").setAttribute("aria-hidden", "true");
    }
  }

  function emitPayload() {
    var payload = previewPayload();
    payload.draft_id = state.draftId || 0;
    payload.mark_paid = $("bill-emit-mark-paid") && $("bill-emit-mark-paid").checked ? 1 : 0;
    payload.send_email = $("bill-emit-email") && $("bill-emit-email").checked ? 1 : 0;
    payload.email_to = ($("bill-emit-email-to") || {}).value || "";
    payload.email_extra = ($("bill-emit-email-extra") || {}).value || "";
    payload.pay_caja_id = ($("bill-emit-caja") || {}).value || "";
    payload.pay_method_id = ($("bill-emit-method") || {}).value || "";
    payload.pay_notes = ($("bill-emit-notes") || {}).value || "";
    payload.cheque_numero = ($("bill-emit-cheque-num") || {}).value || "";
    payload.cheque_titular = ($("bill-emit-cheque-tit") || {}).value || "";
    payload.cheque_banco = ($("bill-emit-cheque-banco") || {}).value || "";
    return payload;
  }

  function showEmitResult(data) {
    var dte = data.dte || {};
    state.dteId = dte.id || state.dteId;
    state.dteTotal = dte.totals && dte.totals.total_amount ? dte.totals.total_amount : state.dteTotal;
    if (data.draft) applyDraft(data.draft);
    if (data.payments) state.payments = data.payments;
    goStep(3);
    if ($("bill-done-title")) {
      $("bill-done-title").textContent = data.idempotent ? "Documento ya emitido" : "Documento emitido";
    }
    if ($("bill-done-msg")) $("bill-done-msg").textContent = data.message || "";
    if ($("bill-done-folio")) {
      $("bill-done-folio").textContent = (dte.document_type_label || "") + " N° " + (dte.folio || "—");
    }
    renderPagosPanel();
    if ($("bill-emit-print") && $("bill-emit-print").checked) {
      runPreview("pdf");
    }
  }

  function hasEmitableLines() {
    return (state.lines || []).some(function (line) {
      if (!line) return false;
      var desc = String(line.description || line.concepto || "").trim();
      var sku = String(line.sku || "").trim();
      var qty = Number(line.quantity) || 0;
      var price = Number(line.unit_price_bruto != null ? line.unit_price_bruto : line.unit_price) || 0;
      return !!(desc || sku) && qty > 0 && price > 0;
    });
  }

  function confirmEmit() {
    if (!hasEmitableLines()) {
      showAlert("Agrega líneas al documento.", true);
      return;
    }
    if ($("bill-emit-mark-paid") && $("bill-emit-mark-paid").checked) {
      var cajaId = parseInt(($("bill-emit-caja") || {}).value, 10) || 0;
      var methodId = parseInt(($("bill-emit-method") || {}).value, 10) || 0;
      if (!cajaId) {
        showMissingPopup("Pago", "Selecciona una caja abierta para marcar como pagado.", []);
        return;
      }
      if (!methodId) {
        showMissingPopup("Pago", "Selecciona un método de pago.", []);
        return;
      }
      var m = methodById(methodId);
      if (m && m.requiere_cheque) {
        var n = (($("bill-emit-cheque-num") || {}).value || "").trim();
        var t = (($("bill-emit-cheque-tit") || {}).value || "").trim();
        var b = (($("bill-emit-cheque-banco") || {}).value || "").trim();
        if (!n || !t || !b) {
          showMissingPopup("Pago", "Completa número, titular y banco del cheque.", []);
          return;
        }
      }
    }
    var btn = $("bill-emit-confirm");
    if (btn) btn.disabled = true;
    showAlert("Emitiendo…");
    var send = function () {
      return post(cfg.actions.emit, emitPayload());
    };
    var chain = currentTypeId() === 37 && !state.draftId
      ? saveDraft({ silent: true }).then(send)
      : send();
    chain
      .then(function (data) {
        closeEmitModal();
        showEmitResult(data);
      })
      .catch(function (err) {
        showAlert(err.message || "Error al emitir", true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function confirmCloseLocal() {
    if (currentTypeId() !== 37) {
      showAlert("Cerrar sin enviar solo está disponible en boleta.", true);
      return;
    }
    var ok = window.confirm(
      "Esta opción se utiliza para documentos que ya enviaste al S.I.I. y estás registrando de manera referencial ¿Está seguro que desea cerrar y NO enviar al S.I.I.?"
    );
    if (!ok) return;
    var btn = $("bill-emit-close-local");
    if (btn) btn.disabled = true;
    saveDraft({ silent: true })
      .then(function () {
        var data = {
          draft_id: state.draftId,
          document_type_id: 37,
          issue_date: ($("bill-boleta-issue-date") || {}).value || cfg.todayDate,
          due_date: ($("bill-boleta-due-date") || {}).value || "",
          payment_conditions: ($("bill-boleta-payment") || {}).value || "0",
          quote_id: state.quoteId || 0,
          lines: state.lines,
          refs: state.refs,
        };
        return post(cfg.actions.closeLocal, data);
      })
      .then(function (res) {
        closeEmitModal();
        if (res.draft) applyDraft(res.draft);
        setBoletaTab("pagos");
        showAlert(res.message || "Documento cerrado.");
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo cerrar", true);
      })
      .finally(function () {
        if (btn) btn.disabled = currentTypeId() !== 37;
      });
  }

  function bind() {
    if (Lines && typeof Lines.init === "function") {
      Lines.init({
        cfg: cfg,
        post: post,
        getLines: function () { return state.lines; },
        scheduleDraftSave: scheduleDraftSave,
        showAlert: showAlert,
        isAdvanced: function () { return !!state.advanced; },
      });
      state.linesReady = true;
    }
    var root = $("riverso-billing");
    if (!root) return;

    initComunaCombo();

    if ($("bill-doc-type")) {
      $("bill-doc-type").addEventListener("change", function () {
        updateFolioCard();
        estimateFolio();
      });
    }
    if ($("bill-payment")) {
      $("bill-payment").addEventListener("change", updateFolioCard);
    }
    if ($("bill-missing-ok")) {
      $("bill-missing-ok").addEventListener("click", hideMissingPopup);
    }
    if ($("bill-missing-modal")) {
      $("bill-missing-modal").addEventListener("click", function (e) {
        if (e.target === $("bill-missing-modal")) hideMissingPopup();
      });
    }
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") hideMissingPopup();
    });
    if ($("bill-rut-search-btn")) {
      $("bill-rut-search-btn").addEventListener("click", function () {
        lookupRut();
      });
    }
    if ($("bill-recv-rut")) {
      $("bill-recv-rut").addEventListener("blur", function () {
        lookupRut({ silent: false });
      });
      $("bill-recv-rut").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          lookupRut();
        }
      });
      $("bill-recv-rut").addEventListener("input", function () {
        syncRutDisplay();
        if ($("bill-customer-id")) $("bill-customer-id").value = "0";
      });
    }
    if ($("bill-name-search-btn")) {
      $("bill-name-search-btn").addEventListener("click", searchByName);
    }
    if ($("bill-name-search")) {
      $("bill-name-search").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          searchByName();
        }
      });
    }
    if ($("bill-to-step-2")) {
      $("bill-to-step-2").addEventListener("click", ensureCustomerThenContinue);
    }
    document.querySelectorAll(".bill-back-step-1").forEach(function (btn) {
      btn.addEventListener("click", function () { goStep(1); });
    });
    if ($("bill-add-line")) {
      $("bill-add-line").addEventListener("click", function () {
        state.lines.push(emptyLine());
        renderLines();
      });
    }
    if ($("bill-lines-body")) {
      $("bill-lines-body").addEventListener("input", function (e) {
        var t = e.target;
        if (t && t.getAttribute("data-k")) syncLineFromInput(t);
      });
      $("bill-lines-body").addEventListener("change", function (e) {
        var t = e.target;
        if (t && t.getAttribute("data-k")) syncLineFromInput(t);
      });
      $("bill-lines-body").addEventListener("click", function (e) {
        var t = e.target;
        if (t && t.getAttribute("data-rm") != null) {
          var i = parseInt(t.getAttribute("data-rm"), 10);
          state.lines.splice(i, 1);
          if (!state.lines.length) state.lines.push(emptyLine());
          renderLines();
        }
      });
    }

    // Boleta editor
    var tabs = $("bill-boleta-tabs");
    if (tabs) {
      tabs.addEventListener("click", function (e) {
        var t = e.target.closest(".bill-tab");
        if (!t) return;
        setBoletaTab(t.getAttribute("data-tab"));
      });
    }
    function clearProductSearch() {
      if ($("bill-product-search")) $("bill-product-search").value = "";
      var box = $("bill-product-results");
      if (box) {
        box.innerHTML = "";
        box.hidden = true;
      }
    }

    function setAdvancedMode(on) {
      state.advanced = !!on;
      var root = $("riverso-billing");
      if (root) root.classList.toggle("is-advanced", state.advanced);
      if ($("bill-advanced")) $("bill-advanced").checked = state.advanced;
      renderBoletaLines();
      if (state.advanced && Lines && typeof Lines.refreshLineStock === "function") {
        Lines.refreshLineStock();
      }
    }

    function setAdvHint(text, isError) {
      var el = $("bill-adv-hint");
      if (!el) return;
      el.textContent = text || "";
      el.classList.toggle("is-error", !!isError && !!text);
    }

    function setAdvScope(scope) {
      if (scope !== "descripcion" && scope !== "codigos") scope = "todo";
      state.advScope = scope;
      var modal = $("bill-advanced-modal");
      if (!modal) return;
      var chips = modal.querySelectorAll(".cq-chip[data-scope]");
      Array.prototype.forEach.call(chips, function (chip) {
        var active = chip.getAttribute("data-scope") === scope;
        chip.classList.toggle("is-active", active);
        chip.setAttribute("aria-selected", active ? "true" : "false");
      });
      var q = $("bill-adv-q");
      if (q) {
        if (scope === "descripcion") q.placeholder = "Descripción (mín. 2 caracteres)";
        else if (scope === "codigos") q.placeholder = "SKU, proveedor o código de barras";
        else q.placeholder = "Todo: descripción o códigos";
      }
    }

    function renderAdvContainsTags() {
      var box = $("bill-adv-contains-tags");
      if (!box) return;
      box.innerHTML = "";
      (state.advContains || []).forEach(function (word, index) {
        var tag = document.createElement("span");
        tag.className = "cq-contains-tag";
        var label = document.createElement("span");
        label.textContent = word;
        var remove = document.createElement("button");
        remove.type = "button";
        remove.className = "cq-contains-tag-remove";
        remove.setAttribute("aria-label", "Quitar «" + word + "»");
        remove.textContent = "×";
        remove.addEventListener("click", function () {
          state.advContains.splice(index, 1);
          renderAdvContainsTags();
          if ((($("bill-adv-q") || {}).value || "").trim() || state.advContains.length) {
            searchAdvanced();
          } else {
            state.advResults = [];
            renderAdvResults();
            setAdvHint("");
          }
        });
        tag.appendChild(label);
        tag.appendChild(remove);
        box.appendChild(tag);
      });
    }

    function addAdvContainsWord() {
      var input = $("bill-adv-contains");
      if (!input) return;
      var word = String(input.value || "").trim().replace(/\s+/g, " ");
      if (!word) return;
      if (word.length < 2) {
        setAdvHint("Cada palabra debe tener al menos 2 caracteres.", true);
        return;
      }
      var exists = (state.advContains || []).some(function (w) {
        return w.toLowerCase() === word.toLowerCase();
      });
      if (!exists) state.advContains.push(word);
      input.value = "";
      renderAdvContainsTags();
      input.focus();
      if ((($("bill-adv-q") || {}).value || "").trim() || state.advContains.length) {
        searchAdvanced();
      }
    }

    function renderAdvResults() {
      var list = $("bill-adv-results");
      if (!list) return;
      list.innerHTML = "";
      (state.advResults || []).forEach(function (product) {
        var li = document.createElement("li");
        li.className = "cq-result";
        var info = document.createElement("div");
        var sku = document.createElement("div");
        sku.className = "cq-result-sku";
        sku.textContent = (product.sku || "") + " · " + (product.description || "");
        var meta = document.createElement("div");
        meta.className = "cq-result-meta";
        meta.textContent = "Proveedor " + (product.supplier_code || "—") +
          " · Barras " + (product.barcode || "—");
        var price = product.unit_price != null ? product.unit_price : product.unit_price_bruto;
        if (price) meta.textContent += " · " + money(price);
        info.appendChild(sku);
        info.appendChild(meta);
        li.appendChild(info);
        var addBtn = document.createElement("button");
        addBtn.type = "button";
        addBtn.className = "cq-btn cq-btn-primary";
        addBtn.textContent = "Agregar";
        addBtn.addEventListener("click", function () {
          addProductLine(product);
          setAdvHint("Producto agregado.", false);
        });
        li.appendChild(addBtn);
        list.appendChild(li);
      });
    }

    function searchAdvanced() {
      var qEl = $("bill-adv-q");
      var query = (qEl ? qEl.value : "").trim();
      var scope = state.advScope || "todo";
      var contains = (state.advContains || []).slice();
      if (!query && !contains.length) {
        setAdvHint("Ingresa un término de búsqueda o agrega una palabra.", true);
        state.advResults = [];
        renderAdvResults();
        return;
      }
      if (!query && contains.length) query = contains[0];
      if (scope === "descripcion" && query.length < 2 && !contains.length) {
        setAdvHint("Escribe al menos 2 caracteres para buscar por descripción.", true);
        state.advResults = [];
        renderAdvResults();
        return;
      }
      setAdvHint("Buscando…");
      post(cfg.actions.productLookup, {
        q: query,
        mode: "advanced",
        scope: scope,
        contains: JSON.stringify(contains),
      }).then(function (data) {
        state.advResults = data.products || [];
        renderAdvResults();
        if (!state.advResults.length) {
          setAdvHint(data.hint || ("Sin resultados para «" + query + "»."), true);
        } else {
          setAdvHint(state.advResults.length + " resultado(s). Elige Agregar — el modal no agrega solo.");
        }
      }).catch(function (err) {
        setAdvHint(err.message || "Error en la búsqueda", true);
      });
    }

    function openAdvancedSearch() {
      var modal = $("bill-advanced-modal");
      if (!modal) return;
      var prefill = (($("bill-product-search") || {}).value || "").trim();
      if ($("bill-adv-q")) {
        $("bill-adv-q").value = prefill;
      }
      setAdvScope(state.advScope || "todo");
      state.advResults = [];
      renderAdvResults();
      renderAdvContainsTags();
      setAdvHint("");
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
      if ($("bill-adv-q")) {
        $("bill-adv-q").focus();
        $("bill-adv-q").select();
      }
      if (prefill || (state.advContains && state.advContains.length)) {
        searchAdvanced();
      }
    }

    function closeAdvancedSearch() {
      var modal = $("bill-advanced-modal");
      if (!modal) return;
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
      setAdvHint("");
    }

    function runProductSearch() {
      var q = (($("bill-product-search") || {}).value || "").trim();
      var box = $("bill-product-results");
      if (!q) {
        openAdvancedSearch();
        return;
      }
      if (box) {
        box.hidden = false;
        box.innerHTML = "<li>Buscando…</li>";
      }
      lookupProduct(q)
        .then(function (products) {
          if (!box) return;
          if (!products.length) {
            box.innerHTML = "<li>Sin resultados</li>";
            return;
          }
          if (products.length === 1) {
            addProductLine(products[0]);
            if ($("bill-product-search")) $("bill-product-search").value = "";
            box.hidden = true;
            return;
          }
          box.innerHTML = "";
          products.forEach(function (p) {
            var li = document.createElement("li");
            var price = p.unit_price != null ? p.unit_price : p.unit_price_bruto;
            li.textContent = (p.description || "") + (p.sku ? " · " + p.sku : "") +
              (price ? " · " + money(price) : "");
            li.addEventListener("click", function () {
              addProductLine(p);
              if ($("bill-product-search")) $("bill-product-search").value = "";
              box.hidden = true;
            });
            box.appendChild(li);
          });
        })
        .catch(function (err) {
          if (box) box.innerHTML = "<li>" + escAttr(err.message || "Error") + "</li>";
        });
    }
    if ($("bill-product-add")) $("bill-product-add").addEventListener("click", runProductSearch);
    if ($("bill-search-clear")) $("bill-search-clear").addEventListener("click", clearProductSearch);
    if ($("bill-advanced")) {
      $("bill-advanced").addEventListener("change", function () {
        setAdvancedMode($("bill-advanced").checked);
      });
    }
    if ($("bill-product-search")) {
      $("bill-product-search").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          runProductSearch();
        }
      });
    }
    // Modal búsqueda avanzada
    document.querySelectorAll("[data-bill-adv-close]").forEach(function (el) {
      el.addEventListener("click", closeAdvancedSearch);
    });
    var advModal = $("bill-advanced-modal");
    if (advModal) {
      advModal.addEventListener("click", function (e) {
        var chip = e.target.closest(".cq-chip[data-scope]");
        if (chip) {
          setAdvScope(chip.getAttribute("data-scope"));
          if ((($("bill-adv-q") || {}).value || "").trim() || state.advContains.length) {
            searchAdvanced();
          }
        }
      });
    }
    if ($("bill-adv-search-btn")) $("bill-adv-search-btn").addEventListener("click", searchAdvanced);
    if ($("bill-adv-q")) {
      $("bill-adv-q").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          searchAdvanced();
        }
      });
    }
    if ($("bill-adv-contains-add")) $("bill-adv-contains-add").addEventListener("click", addAdvContainsWord);
    if ($("bill-adv-contains")) {
      $("bill-adv-contains").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          addAdvContainsWord();
        }
      });
    }
    if ($("bill-product-manual")) $("bill-product-manual").addEventListener("click", openManualModal);
    if ($("bill-manual-modal")) {
      $("bill-manual-modal").addEventListener("click", function (e) {
        if (e.target && e.target.getAttribute("data-bill-manual-close") === "1") {
          closeManualModal();
        }
      });
    }
    if ($("bill-manual-desc-toggle")) {
      $("bill-manual-desc-toggle").addEventListener("click", toggleManualDescLarga);
    }
    if ($("bill-manual-add")) $("bill-manual-add").addEventListener("click", saveManualLine);
    [
      { id: "bill-manual-qty", key: "qty" },
      { id: "bill-manual-unit-neto", key: "unit-neto" },
      { id: "bill-manual-unit-bruto", key: "unit-bruto" },
      { id: "bill-manual-pct", key: "pct" }
    ].forEach(function (item) {
      var el = $(item.id);
      if (!el) return;
      el.addEventListener("input", function () { onManualPriceInput(item.key); });
      el.addEventListener("change", function () { onManualPriceInput(item.key); });
    });
    if ($("bill-manual-adj-type")) {
      $("bill-manual-adj-type").addEventListener("change", syncManualTotals);
    }
    if ($("bill-manual-concepto")) {
      $("bill-manual-concepto").addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          saveManualLine();
        }
      });
    }
    if ($("bill-draft-save")) {
      $("bill-draft-save").addEventListener("click", function () {
        saveDraft().catch(function (e) {
          showAlert(e.message || "Error al guardar", true);
        });
      });
    }
    if ($("bill-ref-add")) {
      $("bill-ref-add").addEventListener("click", function () {
        if ($("bill-refs-form")) $("bill-refs-form").hidden = false;
      });
    }
    if ($("bill-ref-cancel")) {
      $("bill-ref-cancel").addEventListener("click", function () {
        if ($("bill-refs-form")) $("bill-refs-form").hidden = true;
        state.selectedQuoteRef = null;
      });
    }
    if ($("bill-ref-type")) {
      $("bill-ref-type").addEventListener("change", function () {
        var isQuote = $("bill-ref-type").value === "quote";
        if ($("bill-ref-quote-wrap")) $("bill-ref-quote-wrap").hidden = !isQuote;
        if ($("bill-ref-folio-wrap")) $("bill-ref-folio-wrap").hidden = isQuote;
      });
    }
    if ($("bill-ref-quote-q")) {
      $("bill-ref-quote-q").addEventListener("keydown", function (e) {
        if (e.key !== "Enter") return;
        e.preventDefault();
        var q = $("bill-ref-quote-q").value.trim();
        var box = $("bill-ref-quote-results");
        if (!q || !box) return;
        box.hidden = false;
        box.innerHTML = "<li>Buscando…</li>";
        post(cfg.actions.searchQuotes, { q: q })
          .then(function (data) {
            var list = data.quotes || [];
            if (!list.length) {
              box.innerHTML = "<li>Sin cotizaciones</li>";
              return;
            }
            box.innerHTML = "";
            list.forEach(function (qt) {
              var li = document.createElement("li");
              li.textContent = (qt.quote_number || ("#" + qt.id)) + (qt.customer_name ? " · " + qt.customer_name : "");
              li.addEventListener("click", function () {
                state.selectedQuoteRef = qt;
                $("bill-ref-quote-q").value = qt.quote_number || String(qt.id);
                box.hidden = true;
              });
              box.appendChild(li);
            });
          })
          .catch(function (err) {
            box.innerHTML = "<li>" + escAttr(err.message || "Error") + "</li>";
          });
      });
    }
    if ($("bill-ref-save")) {
      $("bill-ref-save").addEventListener("click", function () {
        var type = ($("bill-ref-type") || {}).value || "quote";
        if (type === "quote") {
          var qt = state.selectedQuoteRef;
          if (!qt) {
            showAlert("Selecciona una cotización de la búsqueda.", true);
            return;
          }
          state.refs.push({
            ref_type: "quote",
            quote_id: qt.id,
            quote_number: qt.quote_number || "",
            ref_label: "Cotización " + (qt.quote_number || ("#" + qt.id)),
          });
        } else {
          var folio = (($("bill-ref-folio") || {}).value || "").trim();
          if (!folio) {
            showAlert("Indica el folio de referencia.", true);
            return;
          }
          state.refs.push({
            ref_type: "folio",
            ref_doc_type: "Otro",
            ref_folio: folio,
            ref_label: folio,
          });
        }
        state.selectedQuoteRef = null;
        if ($("bill-refs-form")) $("bill-refs-form").hidden = true;
        if ($("bill-ref-quote-q")) $("bill-ref-quote-q").value = "";
        if ($("bill-ref-folio")) $("bill-ref-folio").value = "";
        renderRefs();
      });
    }
    if ($("bill-pago-add-charge")) {
      $("bill-pago-add-charge").addEventListener("click", openPagoModal);
    }
    if ($("bill-pago-close")) $("bill-pago-close").addEventListener("click", closePagoModal);
    if ($("bill-pago-cancel")) $("bill-pago-cancel").addEventListener("click", closePagoModal);
    if ($("bill-pago-method")) {
      $("bill-pago-method").addEventListener("change", function () {
        toggleChequeFields("bill-pago-method", "bill-pago-cheque");
        updatePagoVuelto();
      });
    }
    if ($("bill-pago-paid")) {
      $("bill-pago-paid").addEventListener("input", updatePagoVuelto);
    }
    if ($("bill-pago-save")) {
      $("bill-pago-save").addEventListener("click", savePago);
    }
    if ($("bill-emit") || $("bill-boleta-emit")) {
      [$("bill-emit"), $("bill-boleta-emit")].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener("click", function () {
          openEmitModal();
        });
      });
    }
    if ($("bill-emit-close")) $("bill-emit-close").addEventListener("click", closeEmitModal);
    if ($("bill-emit-modal")) {
      $("bill-emit-modal").addEventListener("click", function (e) {
        if (e.target === $("bill-emit-modal")) closeEmitModal();
      });
    }
    if ($("bill-emit-mark-paid")) {
      $("bill-emit-mark-paid").addEventListener("change", syncEmitPayFieldsEnabled);
    }
    if ($("bill-emit-method")) {
      $("bill-emit-method").addEventListener("change", function () {
        if ($("bill-emit-mark-paid") && $("bill-emit-mark-paid").checked) {
          toggleChequeFields("bill-emit-method", "bill-emit-cheque");
          syncEmitPayFieldsEnabled();
        }
      });
    }
    if ($("bill-emit-confirm")) $("bill-emit-confirm").addEventListener("click", confirmEmit);
    if ($("bill-emit-close-local")) $("bill-emit-close-local").addEventListener("click", confirmCloseLocal);
    if ($("bill-done-pago-open")) $("bill-done-pago-open").addEventListener("click", openPagoModal);

    document.querySelectorAll(".bill-preview-toggle").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        togglePreviewMenu(btn);
      });
    });
    document.querySelectorAll(".bill-preview-item").forEach(function (item) {
      item.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var kind = item.getAttribute("data-preview") || "pdf";
        closeAllPreviewMenus();
        runPreview(kind);
      });
    });
    document.addEventListener("click", function (e) {
      var t = e.target;
      if (t && t.closest && t.closest(".bill-preview-wrap")) return;
      closeAllPreviewMenus();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        closeAllPreviewMenus();
        if ($("bill-emit-modal") && !$("bill-emit-modal").hidden) {
          closeEmitModal();
        }
      }
    });
    fillPagoMethods();
    if ($("bill-done-new")) {
      $("bill-done-new").addEventListener("click", function () {
        window.location.href = cfg.portalUrl || "/interno/facturacion/";
      });
    }

    updateFolioCard();
    estimateFolio();
    lockAllDetails();
    if (!loadDraftIfAny()) {
      loadQuoteIfAny();
    }
    root.setAttribute("data-ready", "1");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();
