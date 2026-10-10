/**
 * Avisos de compra: avisar que falta → bandeja por proveedor → ingresado.
 */
(function () {
  "use strict";

  var cfg = window.riversoAvisos || {};
  var root = document.getElementById("riverso-av");
  if (!root || !cfg.ajaxUrl) return;

  var VIEWS = ["avisar", "mios", "bandeja"];
  var PHOTO_MAX_SIDE = 1600;
  var SUPPLIER_KEY = "riverso_av_proveedor";

  var state = {
    view: "avisar",
    query: "",
    origen: "teclado",
    candidates: [],
    candidate: null, // null con el formulario abierto = sin identificar
    formOpen: false,
    photo: null,
    photoUrl: null,
    suppliers: null,
    trayFilter: "abiertos",
    traySearch: "",
    trayGroups: [],
    scan: null,
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

  function parseQty(text) {
    var value = parseFloat(String(text || "").replace(",", "."));
    return isFinite(value) && value > 0 ? value : 0;
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var val = data[k];
      if (val === true) val = "1";
      if (val === false) val = "0";
      if (typeof Blob !== "undefined" && val instanceof Blob) {
        body.append(k, val, val.name || "foto.jpg");
        return;
      }
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

  /** fetch rechaza con TypeError cuando no hay red: el aviso no se pierde, se reintenta. */
  function errorText(err) {
    if (err && err.name === "TypeError") return "Sin conexión. No se envió: inténtalo de nuevo.";
    return err && err.message ? err.message : String(err);
  }

  function toast(text, actionLabel, action) {
    var el = $("av-toast");
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

  function storageGet(key) {
    try {
      return window.localStorage.getItem(key) || "";
    } catch (e) {
      return "";
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* sin almacenamiento: no se recuerda el proveedor */
    }
  }

  /** En el teléfono el foco abre el teclado y tapa los botones: solo se enfoca con mouse. */
  function focusQuery() {
    if (window.matchMedia && window.matchMedia("(pointer: coarse)").matches) return;
    $("av-q").focus();
  }

  function quantityText(n) {
    if (n.cantidad === null || n.cantidad === undefined) return "";
    var text = qty(n.cantidad) + " " + (n.unidad_label || n.unidad || "");
    if (n.equivalencia) text += " (" + qty(n.equivalencia) + " unidades)";
    return text;
  }

  /* ===================== Navegación ===================== */

  function setView(view, replace) {
    if (VIEWS.indexOf(view) === -1 || (view === "bandeja" && !cfg.canManage)) view = "avisar";
    state.view = view;
    VIEWS.forEach(function (v) {
      var section = $("av-view-" + v);
      if (section) section.hidden = v !== view;
    });
    Array.prototype.forEach.call(root.querySelectorAll(".av-tab"), function (tab) {
      tab.classList.toggle("is-active", tab.getAttribute("data-view") === view);
    });
    if (view !== "avisar") stopScanner();

    var url = new URL(cfg.baseUrl, window.location.href);
    if (view !== "avisar") url.searchParams.set("vista", view);
    if (replace) window.history.replaceState({ view: view }, "", url.toString());
    else window.history.pushState({ view: view }, "", url.toString());

    if (view === "mios") loadMine();
    if (view === "bandeja") loadTray();
    if (view === "avisar" && !state.formOpen) focusQuery();
  }

  function updateCounts(counts) {
    if (!counts) return;
    cfg.counts = counts;
    var badge = $("av-open-count");
    if (badge) {
      badge.textContent = counts.abiertos || "";
      badge.hidden = !counts.abiertos;
    }
    Array.prototype.forEach.call(root.querySelectorAll("#av-tray-filters .av-chip"), function (chip) {
      var span = chip.querySelector("span");
      if (!span) return;
      var n = counts[chip.getAttribute("data-estado")];
      span.textContent = n ? n : "";
    });
  }

  /* ===================== Identificar ===================== */

  function setStatus(text, kind) {
    var el = $("av-status");
    el.textContent = text || "";
    el.className = "av-status" + (kind ? " is-" + kind : "");
  }

  function search(text, origen) {
    var q = String(text || "").trim();
    if (!q) return;
    state.query = q;
    state.origen = origen || "teclado";
    closeForm();
    $("av-candidates").innerHTML = "";
    setStatus("Buscando…");
    post(cfg.actions.resolve, { q: q, origen: state.origen })
      .then(function (data) {
        if (state.query !== q) return;
        state.candidates = data.candidatos || [];
        if (!state.candidates.length) {
          setStatus("No encontré «" + q + "».", "warn");
          renderNoMatch();
          return;
        }
        if (data.exactos === 1 && state.candidates.length === 1) {
          setStatus("");
          openForm(state.candidates[0]);
          return;
        }
        setStatus(
          data.exactos > 1 ? "Ese código calza con más de un producto. Elige cuál es." : "Elige el producto.",
          data.exactos > 1 ? "warn" : ""
        );
        renderCandidates();
      })
      .catch(function (err) {
        setStatus(errorText(err), "error");
      });
  }

  function candidateMeta(c) {
    var parts = [];
    if (c.sku) parts.push("SKU " + c.sku);
    var supplier = (c.proveedores || [])[0];
    if (supplier && supplier.codigo) parts.push(supplier.nombre + " " + supplier.codigo);
    // "SKU" o "Nombre" como origen no agregan nada a lo que ya se ve.
    if (c.fuente !== "sku" && c.fuente !== "nombre") parts.push(c.fuente_label);
    return parts.join(" · ");
  }

  function candidateHtml(c) {
    return (
      (c.imagen ? '<img class="av-thumb" src="' + esc(c.imagen) + '" alt="">' : '<span class="av-thumb av-thumb-empty"></span>') +
      '<span class="av-cand-text"><strong>' + esc(c.nombre) + "</strong>" +
      '<span class="av-sub">' + esc(candidateMeta(c)) + "</span></span>"
    );
  }

  function renderCandidates() {
    var html = state.candidates
      .map(function (c, i) {
        return '<button type="button" class="av-cand" data-index="' + i + '">' + candidateHtml(c) + "</button>";
      })
      .join("");
    html += '<button type="button" class="av-cand av-cand-none" data-index="-1">Ninguno de estos: avisar sin identificar</button>';
    $("av-candidates").innerHTML = html;
  }

  function renderNoMatch() {
    $("av-candidates").innerHTML =
      '<button type="button" class="av-cand av-cand-none" data-index="-1">Avisar igual, sin identificar (con foto)</button>';
  }

  /* ===================== Formulario ===================== */

  function loadSuppliers() {
    if (state.suppliers) return Promise.resolve(state.suppliers);
    return post(cfg.actions.suppliers, {})
      .then(function (data) {
        state.suppliers = data.proveedores || [];
        return state.suppliers;
      })
      .catch(function () {
        return [];
      });
  }

  function fillUnits(select) {
    var html = '<option value="">¿en qué?</option>';
    Object.keys(cfg.units || {}).forEach(function (key) {
      html += '<option value="' + esc(key) + '">' + esc(cfg.units[key]) + "</option>";
    });
    select.innerHTML = html;
  }

  function fillSuppliers(select, own, selected, suggested) {
    var html = '<option value="">No sé</option>';
    var seen = {};
    (own || []).forEach(function (s) {
      seen[s.proveedor_id] = true;
      html += '<option value="' + s.proveedor_id + '">' + esc(s.nombre + (s.codigo ? " · " + s.codigo : "")) + "</option>";
    });
    var others = (state.suppliers || []).filter(function (s) {
      return !seen[s.id];
    });
    // El proveedor del último aviso de este producto va primero, aunque la lista aún no cargue.
    if (suggested && !seen[suggested.id]) {
      others = others.filter(function (s) {
        return s.id !== suggested.id;
      });
      others.unshift({ id: suggested.id, nombre: suggested.nombre + " · último aviso" });
    }
    if (others.length) {
      html += '<optgroup label="' + (own && own.length ? "Otro proveedor" : "Proveedores") + '">';
      others.forEach(function (s) {
        html += '<option value="' + s.id + '">' + esc(s.nombre) + "</option>";
      });
      html += "</optgroup>";
    }
    select.innerHTML = html;
    select.value = selected ? String(selected) : "";
    if (select.selectedIndex < 0) select.value = "";
  }

  function defaultSupplier(c) {
    var own = (c && c.proveedores) || [];
    if (!own.length) {
      // Sin vínculo con proveedor: el del último aviso de este producto, si lo hubo.
      if (c) return c.proveedor_sugerido ? c.proveedor_sugerido.id : "";
      return storageGet(SUPPLIER_KEY);
    }
    var hit = own.filter(function (s) {
      return s.calzo;
    })[0];
    if (!hit && own.length === 1) hit = own[0];
    if (!hit) {
      hit = own.filter(function (s) {
        return s.preferido;
      })[0];
    }
    return hit ? hit.proveedor_id : "";
  }

  function selectedOwnSupplier() {
    var id = parseInt($("av-supplier").value, 10) || 0;
    var own = (state.candidate && state.candidate.proveedores) || [];
    return own.filter(function (s) {
      return s.proveedor_id === id;
    })[0];
  }

  /** Unidades por envase: lo que dice el vínculo con el proveedor o el código de barras leído. */
  function knownFactor() {
    var supplier = selectedOwnSupplier();
    if (supplier && supplier.factor) return { value: supplier.factor, origen: "proveedor" };
    if (state.candidate && state.candidate.pack_qty) return { value: state.candidate.pack_qty, origen: "barra" };
    return null;
  }

  function updateEquivalence() {
    var el = $("av-equiv");
    var factor = knownFactor();
    var cantidad = parseQty($("av-cantidad").value);
    var unidad = $("av-unidad").value;
    if (!factor) {
      el.textContent = "";
      return;
    }
    if (cantidad && unidad && unidad !== "unidad") {
      el.textContent = qty(cantidad) + " × " + qty(factor.value) + " = " + qty(cantidad * factor.value) + " unidades";
    } else {
      el.textContent = "El envase trae " + qty(factor.value) + " unidades.";
    }
  }

  function renderDupes(c) {
    var html = "";
    ((c && c.abiertos) || []).forEach(function (n) {
      var q = quantityText(n);
      html +=
        '<div class="av-dupe">' +
        "<span>Ya avisado por <strong>" + esc(n.creado_nombre || "alguien") + "</strong> " + esc(n.hace) +
        (q ? ": " + esc(q) : "") + (n.apoyos ? " · +" + n.apoyos : "") + ".</span>" +
        '<button type="button" class="av-btn av-btn-sm" data-join="' + n.id + '">Sumarme a ese aviso</button>' +
        "</div>";
    });
    ((c && c.recientes) || []).forEach(function (n) {
      html +=
        '<div class="av-dupe av-dupe-info"><span>Ya pedido ' + esc(n.ingresado_hace) +
        (n.ingresado_nombre ? " por <strong>" + esc(n.ingresado_nombre) + "</strong>" : "") +
        (quantityText(n) ? ": " + esc(quantityText(n)) : "") +
        ". Avisa de nuevo solo si ya llegó y se acabó.</span></div>";
    });
    $("av-dupes").innerHTML = html;
  }

  function openForm(c) {
    state.candidate = c || null;
    state.formOpen = true;
    $("av-candidates").innerHTML = "";
    $("av-form-error").hidden = true;

    var product = $("av-product");
    if (c) {
      product.innerHTML =
        '<div class="av-product-main">' + candidateHtml(c) + "</div>" +
        '<button type="button" class="av-link-btn" id="av-not-this">No es este</button>';
    } else {
      product.innerHTML =
        '<div class="av-product-main"><span class="av-thumb av-thumb-empty"></span>' +
        '<span class="av-cand-text"><strong>Sin identificar</strong>' +
        '<span class="av-sub">' + (state.query ? "Código o texto: " + esc(state.query) + ". " : "") +
        "Quien arma el pedido lo reconoce por la foto.</span></span></div>";
    }
    renderDupes(c);

    $("av-texto-field").hidden = !!c;
    // Un texto sin números es una descripción, no un código.
    $("av-texto").value = !c && state.query && !/\d/.test(state.query) ? state.query : "";
    $("av-sin-stock").checked = false;
    $("av-cantidad").value = "";
    $("av-nota").value = "";
    fillUnits($("av-unidad"));
    clearPhoto();

    var supplier = $("av-supplier");
    var suggested = c ? c.proveedor_sugerido : null;
    fillSuppliers(supplier, c ? c.proveedores : [], defaultSupplier(c), suggested);
    loadSuppliers().then(function () {
      if (state.candidate !== (c || null) || !state.formOpen) return;
      fillSuppliers(supplier, c ? c.proveedores : [], supplier.value || defaultSupplier(c), suggested);
      applySupplierUnit();
    });
    applySupplierUnit();

    $("av-form").hidden = false;
    if (!c) $("av-texto").focus();
    $("av-form").scrollIntoView({ block: "start", behavior: "smooth" });
  }

  /** Si el vínculo con el proveedor dice en qué se compra, se propone esa unidad. */
  function applySupplierUnit() {
    var supplier = selectedOwnSupplier();
    var unit = supplier ? String(supplier.unidad || "").toLowerCase() : "";
    if (unit && cfg.units[unit] && !$("av-unidad").value) $("av-unidad").value = unit;
    updateEquivalence();
  }

  function closeForm() {
    state.formOpen = false;
    state.candidate = null;
    $("av-form").hidden = true;
    clearPhoto();
  }

  function resetAfterSend() {
    closeForm();
    state.query = "";
    state.candidates = [];
    $("av-q").value = "";
    $("av-candidates").innerHTML = "";
    setStatus("");
    focusQuery();
  }

  function formError(text) {
    var el = $("av-form-error");
    el.textContent = text || "";
    el.hidden = !text;
  }

  function formData() {
    var c = state.candidate;
    var cantidad = $("av-cantidad").value.trim();
    var unidad = $("av-unidad").value;
    var data = {
      producto_base_id: c ? c.producto_base_id : 0,
      producto_proveedor_id: c ? c.pp_id || 0 : 0,
      proveedor_id: $("av-supplier").value,
      codigo_leido: state.query,
      origen_codigo: state.origen,
      match_fuente: c ? c.fuente : "",
      texto: c ? "" : $("av-texto").value.trim(),
      sin_stock: $("av-sin-stock").checked,
      cantidad: cantidad,
      unidad: unidad,
      nota: $("av-nota").value.trim(),
    };
    var factor = knownFactor();
    if (factor && factor.origen === "barra" && cantidad && unidad !== "unidad") {
      data.factor = factor.value;
      data.factor_origen = "barra";
    }
    return data;
  }

  function validateQuantity(cantidadText, unidad) {
    if (!cantidadText) return "";
    if (!parseQty(cantidadText)) return "La cantidad debe ser un número mayor que cero.";
    if (!unidad) return "Indica en qué va la cantidad: cajas, unidades…";
    return "";
  }

  function submitForm(event) {
    event.preventDefault();
    var data = formData();
    var problem = validateQuantity(data.cantidad, data.unidad);
    if (!problem && !state.candidate && !data.texto && !state.photo && !state.query) {
      problem = "Escribe qué falta o toma una foto.";
    }
    if (problem) {
      formError(problem);
      return;
    }
    formError("");
    if (state.photo) data.foto = state.photo;

    var btn = $("av-submit");
    btn.disabled = true;
    post(cfg.actions.create, data)
      .then(function () {
        if (!state.candidate && data.proveedor_id) storageSet(SUPPLIER_KEY, data.proveedor_id);
        resetAfterSend();
        toast("Aviso enviado");
        if (cfg.counts) updateCounts({ abiertos: (cfg.counts.abiertos || 0) + 1, sin_identificar: cfg.counts.sin_identificar });
      })
      .catch(function (err) {
        formError(errorText(err));
      })
      .then(function () {
        btn.disabled = false;
      });
  }

  function joinNotice(id) {
    var data = formData();
    var problem = validateQuantity(data.cantidad, data.unidad);
    if (problem) {
      formError(problem);
      return;
    }
    post(cfg.actions.join, {
      id: id,
      cantidad: data.cantidad,
      unidad: data.unidad,
      sin_stock: data.sin_stock,
      nota: data.nota,
    })
      .then(function () {
        resetAfterSend();
        toast("Te sumaste al aviso");
      })
      .catch(function (err) {
        formError(errorText(err));
      });
  }

  /* ===================== Foto ===================== */

  /** Las fotos del teléfono pesan varios MB; se reducen antes de subirlas. */
  function compressPhoto(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, PHOTO_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(
          function (blob) {
            // Una foto que ya venía chica (reenviada por WhatsApp) no mejora al recodificarla.
            var keepOriginal = !blob || (file.type === "image/jpeg" && scale === 1 && file.size <= blob.size);
            resolve(keepOriginal ? file : blob);
          },
          "image/jpeg",
          0.78
        );
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        resolve(file);
      };
      img.src = url;
    });
  }

  function clearPhoto() {
    if (state.photoUrl) URL.revokeObjectURL(state.photoUrl);
    state.photo = null;
    state.photoUrl = null;
    $("av-foto-input").value = "";
    $("av-foto-preview").hidden = true;
    $("av-foto-preview").innerHTML = "";
    $("av-foto-btn").textContent = "Tomar foto";
  }

  function onPhotoPicked() {
    var file = $("av-foto-input").files && $("av-foto-input").files[0];
    if (!file) return;
    compressPhoto(file).then(function (blob) {
      clearPhoto();
      state.photo = blob;
      state.photoUrl = URL.createObjectURL(blob);
      $("av-foto-preview").innerHTML =
        '<img src="' + esc(state.photoUrl) + '" alt="Foto del aviso">' +
        '<button type="button" class="av-link-btn" id="av-foto-remove">Quitar foto</button>';
      $("av-foto-preview").hidden = false;
      $("av-foto-btn").textContent = "Cambiar foto";
    });
  }

  /* ===================== Cámara ===================== */

  function scanStatus(text) {
    $("av-scan-status").textContent = text || "";
  }

  function hideScanner() {
    state.scan = null;
    $("av-scanner").hidden = true;
    $("av-scan-choices").hidden = true;
  }

  /** La lectura la hace el lector compartido (barcode-scanner.js) sobre el video de esta pantalla. */
  function startScanner() {
    if (state.scan || !window.RiversoBarcodeScanner) return;
    closeForm();
    $("av-candidates").innerHTML = "";
    setStatus("");
    $("av-scan-choices").hidden = true;
    $("av-scanner").hidden = false;
    state.scan = window.RiversoBarcodeScanner.start({
      video: $("av-video"),
      onStatus: scanStatus,
      onChoices: showScanChoices,
      onCode: function (code) {
        hideScanner();
        $("av-q").value = code;
        search(code, "camara");
      },
      onError: function (message) {
        hideScanner();
        setStatus(message, "error");
      },
    });
  }

  function showScanChoices(codes) {
    var box = $("av-scan-choices");
    box.innerHTML =
      codes
        .map(function (code) {
          return '<button type="button" class="av-btn av-btn-ghost" data-code="' + esc(code) + '">' + esc(code) + "</button>";
        })
        .join("") + '<button type="button" class="av-link-btn" data-code="">Seguir escaneando</button>';
    box.hidden = false;
  }

  function stopScanner() {
    if (!state.scan) return;
    state.scan.stop();
    hideScanner();
  }

  /* ===================== Tarjetas ===================== */

  function stateTag(n) {
    if (n.estado === "ingresado") {
      return '<span class="av-tag av-tag-ok">Ingresado' + (n.ingresado_nombre ? " por " + esc(n.ingresado_nombre) : "") +
        (n.ingresado_hace ? " " + esc(n.ingresado_hace) : "") + "</span>";
    }
    if (n.estado === "descartado") {
      return '<span class="av-tag av-tag-off">Descartado' + (n.motivo_label ? ": " + esc(n.motivo_label) : "") + "</span>";
    }
    return '<span class="av-tag">Abierto</span>';
  }

  function noticeCard(n, mode) {
    var meta = [];
    if (n.sku) meta.push("SKU " + n.sku);
    if (n.codigo_proveedor) meta.push("Cód. " + n.codigo_proveedor);
    if (!n.identificado && n.codigo_leido && n.codigo_leido !== n.titulo) meta.push("Leído: " + n.codigo_leido);
    if (mode === "mios" && n.proveedor_nombre) meta.push(n.proveedor_nombre);

    var q = quantityText(n);
    var quantity = q
      ? '<span class="av-qty">' + esc(q) + "</span> " +
        '<span class="av-sub">' + (n.cantidad_confirmada ? "confirmada por " + esc(n.confirmo_nombre) : "propuesta") + "</span>"
      : '<span class="av-sub">Sin cantidad</span>';

    var actions = "";
    if (mode === "bandeja") {
      if (n.estado === "abierto") {
        actions =
          '<button type="button" class="av-btn av-btn-sm" data-act="ingresar">Ingresado</button>' +
          '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="cantidad">Cantidad</button>' +
          '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="proveedor">Proveedor</button>' +
          (n.identificado ? "" : '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="identificar">Identificar</button>') +
          '<button type="button" class="av-btn av-btn-sm av-btn-danger" data-act="descartar">Descartar</button>';
      } else {
        actions = '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="reabrir">Reabrir</button>';
      }
    } else if (n.estado === "abierto" && n.creado_por === cfg.userId) {
      actions = '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="retirar">Retirar aviso</button>';
    }

    return (
      '<article class="av-card' + (n.identificado ? "" : " av-card-unknown") + '" data-id="' + n.id + '">' +
      (n.foto
        ? '<button type="button" class="av-card-photo" data-act="foto" data-src="' + esc(n.foto) + '"><img src="' + esc(n.foto) + '" alt="Foto" loading="lazy"></button>'
        : "") +
      '<div class="av-card-body">' +
      "<strong>" + esc(n.titulo) + "</strong>" +
      (meta.length ? '<div class="av-sub">' + esc(meta.join(" · ")) + "</div>" : "") +
      '<div class="av-card-line">' + quantity +
      (n.sin_stock ? ' <span class="av-tag av-tag-warn">No queda</span>' : "") +
      (n.identificado ? "" : ' <span class="av-tag av-tag-warn">Sin identificar</span>') +
      (n.apoyos ? ' <span class="av-tag">+' + n.apoyos + " avisan lo mismo</span>" : "") +
      (mode === "bandeja" && n.estado === "abierto" ? "" : " " + stateTag(n)) +
      "</div>" +
      (n.nota ? '<div class="av-card-note">' + esc(n.nota) + "</div>" : "") +
      '<div class="av-sub">' + esc(n.creado_nombre || "") + " · " + esc(n.hace) + "</div>" +
      (actions ? '<div class="av-card-actions">' + actions + "</div>" : "") +
      "</div></article>"
    );
  }

  /* ===================== Mis avisos ===================== */

  function loadMine() {
    var list = $("av-mine-list");
    list.innerHTML = '<p class="av-empty">Cargando…</p>';
    post(cfg.actions.mine, {})
      .then(function (data) {
        var items = data.avisos || [];
        list.innerHTML = items.length
          ? items
              .map(function (n) {
                return noticeCard(n, "mios");
              })
              .join("")
          : '<p class="av-empty">Todavía no has avisado nada.</p>';
      })
      .catch(function (err) {
        list.innerHTML = '<p class="av-empty">' + esc(errorText(err)) + "</p>";
      });
  }

  /* ===================== Bandeja ===================== */

  function loadTray() {
    var tray = $("av-tray");
    if (!tray) return;
    Array.prototype.forEach.call(root.querySelectorAll("#av-tray-filters .av-chip"), function (chip) {
      chip.classList.toggle("is-active", chip.getAttribute("data-estado") === state.trayFilter);
    });
    tray.innerHTML = '<p class="av-empty">Cargando…</p>';
    var filter = state.trayFilter;
    var searchText = state.traySearch;
    post(cfg.actions.list, { estado: filter, buscar: searchText })
      .then(function (data) {
        if (filter !== state.trayFilter || searchText !== state.traySearch) return;
        state.trayGroups = data.grupos || [];
        updateCounts(data.counts);
        renderTray();
      })
      .catch(function (err) {
        tray.innerHTML = '<p class="av-empty">' + esc(errorText(err)) + "</p>";
      });
  }

  function renderTray() {
    var tray = $("av-tray");
    if (!state.trayGroups.length) {
      tray.innerHTML = '<p class="av-empty">' +
        (state.trayFilter === "abiertos" ? "No hay avisos por ingresar." : "Nada que mostrar.") + "</p>";
      return;
    }
    tray.innerHTML = state.trayGroups
      .map(function (g) {
        return (
          '<section class="av-group" data-supplier="' + g.proveedor_id + '">' +
          '<header class="av-group-head"><h2>' + esc(g.nombre) + ' <span class="av-badge">' + g.avisos.length + "</span></h2>" +
          '<button type="button" class="av-btn av-btn-sm av-btn-ghost" data-act="copiar">Copiar lista</button></header>' +
          g.avisos
            .map(function (n) {
              return noticeCard(n, "bandeja");
            })
            .join("") +
          "</section>"
        );
      })
      .join("");
  }

  function findNotice(id) {
    for (var i = 0; i < state.trayGroups.length; i++) {
      for (var j = 0; j < state.trayGroups[i].avisos.length; j++) {
        if (state.trayGroups[i].avisos[j].id === id) return state.trayGroups[i].avisos[j];
      }
    }
    return null;
  }

  function updateNotice(id, op, data) {
    return post(cfg.actions.update, Object.assign({ id: id, op: op }, data || {})).then(function (res) {
      updateCounts(res.counts);
      return res.aviso;
    });
  }

  /** Texto para pegar en el correo, el portal o el WhatsApp del proveedor. */
  function copyGroup(supplierId) {
    var group = state.trayGroups.filter(function (g) {
      return g.proveedor_id === supplierId;
    })[0];
    if (!group) return;
    var lines = group.avisos.map(function (n) {
      var parts = [];
      if (n.codigo_proveedor) parts.push(n.codigo_proveedor);
      parts.push(n.titulo);
      parts.push(quantityText(n) || "cantidad por definir");
      return parts.join(" — ");
    });
    var text = group.nombre + "\n" + lines.join("\n");
    var done = function () {
      toast("Lista copiada (" + lines.length + ")");
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {
        fallbackCopy(text);
        done();
      });
    } else {
      fallbackCopy(text);
      done();
    }
  }

  function fallbackCopy(text) {
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
  }

  /* ===================== Diálogos ===================== */

  function openModal(title, bodyHtml, okLabel, onOk) {
    $("av-modal-title").textContent = title;
    $("av-modal-body").innerHTML = bodyHtml;
    $("av-modal-error").hidden = true;
    var ok = $("av-modal-ok");
    ok.textContent = okLabel || "Guardar";
    ok.hidden = !onOk;
    ok.disabled = false;
    state.modalOk = onOk || null;
    $("av-modal").hidden = false;
    var first = $("av-modal-body").querySelector("input, select, textarea");
    if (first) first.focus();
  }

  function closeModal() {
    $("av-modal").hidden = true;
    $("av-modal-body").innerHTML = "";
    state.modalOk = null;
  }

  function modalError(text) {
    var el = $("av-modal-error");
    el.textContent = text || "";
    el.hidden = !text;
  }

  function runModal(promise) {
    var ok = $("av-modal-ok");
    ok.disabled = true;
    promise
      .then(function () {
        closeModal();
        loadTray();
      })
      .catch(function (err) {
        ok.disabled = false;
        modalError(errorText(err));
      });
  }

  function quantityFields(n) {
    var options = '<option value="">¿en qué?</option>';
    Object.keys(cfg.units || {}).forEach(function (key) {
      options += '<option value="' + esc(key) + '"' + (n.unidad === key ? " selected" : "") + ">" + esc(cfg.units[key]) + "</option>";
    });
    return (
      '<div class="av-qty-row"><input type="text" id="av-m-cantidad" inputmode="decimal" autocomplete="off" placeholder="Cantidad" value="' +
      (n.cantidad !== null ? esc(String(n.cantidad).replace(".", ",")) : "") + '">' +
      '<select id="av-m-unidad" aria-label="Unidad">' + options + "</select></div>"
    );
  }

  function dialogQuantity(n) {
    openModal("Cantidad a pedir", '<p class="av-sub">' + esc(n.titulo) + "</p>" + quantityFields(n), "Confirmar", function () {
      var cantidad = $("av-m-cantidad").value.trim();
      var unidad = $("av-m-unidad").value;
      var problem = cantidad ? validateQuantity(cantidad, unidad) : "Escribe la cantidad.";
      if (problem) {
        modalError(problem);
        return;
      }
      runModal(updateNotice(n.id, "cantidad", { cantidad: cantidad, unidad: unidad }));
    });
  }

  function dialogDiscard(n) {
    var options = "";
    Object.keys(cfg.discardReasons || {}).forEach(function (key) {
      options += '<option value="' + esc(key) + '">' + esc(cfg.discardReasons[key]) + "</option>";
    });
    openModal(
      "Descartar aviso",
      '<p class="av-sub">' + esc(n.titulo) + '</p><label for="av-m-motivo">Motivo</label><select id="av-m-motivo">' + options + "</select>",
      "Descartar",
      function () {
        runModal(updateNotice(n.id, "descartar", { motivo: $("av-m-motivo").value }));
      }
    );
  }

  function dialogSupplier(n) {
    loadSuppliers().then(function () {
      openModal("Proveedor del aviso", '<p class="av-sub">' + esc(n.titulo) + '</p><select id="av-m-proveedor"></select>', "Guardar", function () {
        runModal(updateNotice(n.id, "proveedor", { proveedor_id: $("av-m-proveedor").value }));
      });
      fillSuppliers($("av-m-proveedor"), [], n.proveedor_id);
    });
  }

  function dialogIdentify(n) {
    openModal(
      "Identificar producto",
      (n.foto ? '<img class="av-modal-photo" src="' + esc(n.foto) + '" alt="Foto del aviso">' : "") +
        '<p class="av-sub">' + esc(n.titulo) + "</p>" +
        '<div class="av-search"><input type="text" id="av-m-q" placeholder="Código, SKU o nombre" autocomplete="off" value="' +
        esc(n.codigo_leido || "") + '"><button type="button" class="av-btn" id="av-m-search">Buscar</button></div>' +
        '<div class="av-candidates" id="av-m-results"></div>',
      "",
      null
    );
    var found = [];
    var run = function () {
      var q = $("av-m-q").value.trim();
      if (!q) return;
      $("av-m-results").innerHTML = '<p class="av-empty">Buscando…</p>';
      post(cfg.actions.resolve, { q: q, origen: "teclado" })
        .then(function (data) {
          found = (data.candidatos || []).filter(function (c) {
            return c.producto_base_id > 0;
          });
          $("av-m-results").innerHTML = found.length
            ? found
                .map(function (c, i) {
                  return '<button type="button" class="av-cand" data-pick="' + i + '">' + candidateHtml(c) + "</button>";
                })
                .join("")
            : '<p class="av-empty">Sin resultados.</p>';
        })
        .catch(function (err) {
          modalError(errorText(err));
        });
    };
    $("av-m-search").addEventListener("click", run);
    $("av-m-q").addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        run();
      }
    });
    $("av-m-results").addEventListener("click", function (e) {
      var btn = e.target.closest("[data-pick]");
      if (!btn) return;
      var pick = found[parseInt(btn.getAttribute("data-pick"), 10)];
      if (pick) runModal(updateNotice(n.id, "identificar", { producto_base_id: pick.producto_base_id }));
    });
    if (n.codigo_leido) run();
  }

  function onCardAction(card, act, target) {
    var id = parseInt(card.getAttribute("data-id"), 10);
    if (act === "foto") {
      openModal("Foto del aviso", '<img class="av-modal-photo" src="' + esc(target.getAttribute("data-src")) + '" alt="Foto del aviso">', "", null);
      return;
    }
    if (act === "retirar") {
      if (!window.confirm("¿Retirar este aviso?")) return;
      updateNotice(id, "descartar", { motivo: "error" })
        .then(function () {
          toast("Aviso retirado");
          loadMine();
        })
        .catch(function (err) {
          toast(errorText(err));
        });
      return;
    }
    var n = findNotice(id);
    if (!n) return;
    if (act === "ingresar") {
      target.disabled = true;
      updateNotice(id, "ingresar", {})
        .then(function () {
          loadTray();
          toast("Ingresado: " + n.titulo, "Deshacer", function () {
            updateNotice(id, "reabrir", {}).then(loadTray, function (err) {
              toast(errorText(err));
            });
          });
        })
        .catch(function (err) {
          target.disabled = false;
          toast(errorText(err));
        });
    } else if (act === "reabrir") {
      updateNotice(id, "reabrir", {}).then(loadTray, function (err) {
        toast(errorText(err));
      });
    } else if (act === "cantidad") {
      dialogQuantity(n);
    } else if (act === "descartar") {
      dialogDiscard(n);
    } else if (act === "proveedor") {
      dialogSupplier(n);
    } else if (act === "identificar") {
      dialogIdentify(n);
    }
  }

  /* ===================== Eventos ===================== */

  root.addEventListener("click", function (e) {
    var tab = e.target.closest(".av-tab");
    if (tab) {
      setView(tab.getAttribute("data-view"));
      return;
    }
    var cand = e.target.closest("#av-candidates .av-cand");
    if (cand) {
      var index = parseInt(cand.getAttribute("data-index"), 10);
      setStatus("");
      openForm(index >= 0 ? state.candidates[index] : null);
      return;
    }
    var join = e.target.closest("[data-join]");
    if (join) {
      joinNotice(parseInt(join.getAttribute("data-join"), 10));
      return;
    }
    var choice = e.target.closest("#av-scan-choices [data-code]");
    if (choice) {
      var code = choice.getAttribute("data-code");
      $("av-scan-choices").hidden = true;
      if (state.scan && code) state.scan.choose(code);
      else if (state.scan) state.scan.resume();
      return;
    }
    var chip = e.target.closest("#av-tray-filters .av-chip");
    if (chip) {
      state.trayFilter = chip.getAttribute("data-estado");
      loadTray();
      return;
    }
    var actBtn = e.target.closest("[data-act]");
    if (actBtn) {
      var act = actBtn.getAttribute("data-act");
      if (act === "copiar") {
        copyGroup(parseInt(actBtn.closest(".av-group").getAttribute("data-supplier"), 10));
        return;
      }
      var card = actBtn.closest(".av-card");
      if (card) onCardAction(card, act, actBtn);
      return;
    }
    if (e.target.id === "av-not-this") {
      // Vuelve a la lista si había más candidatos; si no, queda sin identificar con el código leído.
      if (state.candidates.length > 1) {
        closeForm();
        renderCandidates();
      } else {
        openForm(null);
      }
      return;
    }
    if (e.target.id === "av-foto-remove") {
      clearPhoto();
      return;
    }
    if (e.target.id === "av-modal") closeModal();
  });

  $("av-search-btn").addEventListener("click", function () {
    stopScanner();
    search($("av-q").value, "teclado");
  });
  $("av-q").addEventListener("keydown", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      stopScanner();
      search($("av-q").value, "teclado");
    }
  });
  $("av-scan-btn").addEventListener("click", startScanner);
  $("av-scan-close").addEventListener("click", stopScanner);
  $("av-unknown-btn").addEventListener("click", function () {
    stopScanner();
    state.query = $("av-q").value.trim();
    state.origen = "teclado";
    state.candidates = [];
    setStatus("");
    openForm(null);
  });
  $("av-form").addEventListener("submit", submitForm);
  $("av-cancel").addEventListener("click", function () {
    closeForm();
    focusQuery();
  });
  $("av-foto-btn").addEventListener("click", function () {
    $("av-foto-input").click();
  });
  $("av-foto-input").addEventListener("change", onPhotoPicked);
  $("av-form").addEventListener("input", function () {
    formError("");
  });
  $("av-cantidad").addEventListener("input", updateEquivalence);
  $("av-unidad").addEventListener("change", updateEquivalence);
  $("av-supplier").addEventListener("change", applySupplierUnit);
  $("av-modal-cancel").addEventListener("click", closeModal);
  $("av-modal-ok").addEventListener("click", function () {
    if (state.modalOk) state.modalOk();
  });

  var traySearch = $("av-tray-search");
  if (traySearch) {
    var searchTimer = null;
    traySearch.addEventListener("input", function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        state.traySearch = traySearch.value.trim();
        loadTray();
      }, 300);
    });
  }

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) stopScanner();
  });
  window.addEventListener("popstate", function () {
    setView(new URL(window.location.href).searchParams.get("vista") || "avisar", true);
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !$("av-modal").hidden) closeModal();
  });

  updateCounts(cfg.counts);
  setView(new URL(window.location.href).searchParams.get("vista") || "avisar", true);
})();
