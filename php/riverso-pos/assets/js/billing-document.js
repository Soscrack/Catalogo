/**
 * Facturación · Detalle de documento emitido (solo lectura).
 */
(function () {
  "use strict";

  var cfg = window.RIVERSO_BILLING || {};
  var state = {
    doc: null,
    files: {},
    emailApi: null,
    deletePaymentId: 0,
  };

  var TYPE_LABELS = {
    2: "FACTURA ELECTRÓNICA EMITIDA",
    32: "FACTURA EXENTA ELECTRÓNICA EMITIDA",
    37: "BOLETA ELECTRÓNICA EMITIDA",
    41: "BOLETA EXENTA ELECTRÓNICA EMITIDA",
  };

  var PAYMENT_LABELS = {
    "0": "Contado",
    "30": "30 días",
    "0,30": "50% contado / 50% 30 días",
  };

  function $(id) {
    return document.getElementById(id);
  }

  function action(key, fallback) {
    return (cfg.actions && cfg.actions[key]) || fallback;
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

  function post(act, data) {
    var body = new FormData();
    body.append("action", act);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v === undefined || v === null) return;
      if (typeof v === "boolean") {
        body.append(k, v ? "1" : "0");
        return;
      }
      body.append(k, typeof v === "object" ? JSON.stringify(v) : v);
    });
    return fetch(cfg.ajaxUrl || "/wp-admin/admin-ajax.php", {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          var msg = (json && json.data && json.data.message) || "Error en la solicitud.";
          throw new Error(msg);
        }
        return json.data || {};
      });
  }

  function money(n) {
    var v = Math.round(Number(n) || 0);
    return "$" + v.toLocaleString("es-CL", { maximumFractionDigits: 0 });
  }

  function qty(n) {
    var v = Number(n) || 0;
    return v.toLocaleString("es-CL", { maximumFractionDigits: 3 });
  }

  function formatDate(iso) {
    if (!iso) return "—";
    var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?/);
    if (!m) return String(iso);
    return m[3] + "-" + m[2] + "-" + m[1] + (m[4] ? " " + m[4] : "");
  }

  function formatFolio(folio) {
    var n = Number(String(folio || "").replace(/\D/g, ""));
    if (!n || !isFinite(n)) return folio ? String(folio) : "—";
    return n.toLocaleString("es-CL");
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function setText(id, value) {
    var el = $(id);
    if (el) el.textContent = value === undefined || value === null || value === "" ? "—" : String(value);
  }

  function fileBaseName() {
    var d = state.doc || {};
    var kind = Number(d.document_type_id) === 2 || Number(d.document_type_id) === 32 ? "factura" : "boleta";
    return kind + "-" + (d.folio || d.dte_id || "documento");
  }

  function siiInfo(doc) {
    var st = doc.facto_status;
    if (st === null || st === undefined || st === 0) {
      return { label: "Enviado al SII", tone: "ok" };
    }
    if (st === 2) {
      return { label: "Creado, no enviado al SII", tone: "warn" };
    }
    return { label: doc.facto_error || "Error de envío (estado " + st + ")", tone: "error" };
  }

  function receiverIsVisible(doc) {
    var r = doc.receiver || {};
    var type = Number(doc.document_type_id);
    return type === 2 || type === 32 || !!String(r.rut || "").trim();
  }

  function renderHeader(doc) {
    setText("bd-type", TYPE_LABELS[Number(doc.document_type_id)] || String(doc.document_type_label || "Documento").toUpperCase() + " EMITIDO");
    setText("bd-folio", formatFolio(doc.folio));
    setText("bd-issue-date", formatDate(doc.issue_date));
    setText("bd-due-date", formatDate(doc.due_date));
    setText("bd-payment", PAYMENT_LABELS[String(doc.payment_conditions || "0")] || doc.payment_conditions);
    setText("bd-user", doc.created_by_name);
    setText("bd-closed-at", formatDate(doc.closed_at));
    setText("bd-seller", "");
    setText("bd-sale-state", doc.sale_state || "VENTA: Concretada");

    var sii = siiInfo(doc);
    setText("bd-sii", sii.label);
    var icon = $("bd-sii-icon");
    if (icon) {
      icon.className = "bill-static-action bill-doc-sii-icon is-" + sii.tone;
      icon.textContent = sii.tone === "ok" ? "✓" : sii.tone === "warn" ? "!" : "×";
      icon.title = doc.taxbureau_validation_status != null
        ? "Estado validación SII (FACTO): " + doc.taxbureau_validation_status
        : sii.label;
    }

    document.title = (TYPE_LABELS[Number(doc.document_type_id)] || "Documento") + " N° " + formatFolio(doc.folio);

    var hasPayLink = !!doc.payment_url;
    if ($("bd-opt-paylink-copy")) $("bd-opt-paylink-copy").hidden = !hasPayLink;
    if ($("bd-opt-paylink-open")) $("bd-opt-paylink-open").hidden = !hasPayLink;
  }

  function renderReceiver(doc) {
    var card = $("bd-receiver");
    if (!card) return;
    var show = receiverIsVisible(doc);
    card.hidden = !show;
    if (!show) return;
    var r = doc.receiver || {};
    setText("bd-recv-rut", r.rut);
    setText("bd-recv-name", r.legal_name);
    setText("bd-recv-address", r.address);
    setText("bd-recv-comuna", r.district);
    setText("bd-recv-city", r.city);
    setText("bd-recv-giro", r.activity);
    setText("bd-recv-phone", r.phone);
    var map = $("bd-recv-map");
    if (map) {
      var q = [r.address, r.district, r.city].filter(Boolean).join(", ");
      if (q) {
        map.href = "https://www.google.com/maps/search/?api=1&query=" + encodeURIComponent(q + ", Chile");
        map.setAttribute("aria-disabled", "false");
      } else {
        map.href = "#";
        map.setAttribute("aria-disabled", "true");
      }
    }
  }

  function renderLines(doc) {
    var body = $("bd-lines-body");
    var lines = doc.lines || [];
    if (body) {
      body.innerHTML = lines
        .map(function (l) {
          var detail = esc(l.description || "Ítem");
          if (l.sku) detail = '<span class="bill-doc-sku">' + esc(l.sku) + "</span> " + detail;
          if (!l.afecto) detail += ' <span class="bill-doc-exento">Exento</span>';
          return (
            "<tr>" +
            "<td>" + detail + "</td>" +
            '<td class="cq-num">' + esc(qty(l.quantity)) + "</td>" +
            '<td class="cq-num">' + esc(money(l.unit_price_bruto)) + "</td>" +
            '<td class="cq-num">' + (Number(l.discount_amount) > 0 ? esc(money(l.discount_amount)) : "—") + "</td>" +
            '<td class="cq-num">' + esc(money(l.line_total_bruto)) + "</td>" +
            "</tr>"
          );
        })
        .join("");
    }
    if ($("bd-lines-empty")) $("bd-lines-empty").hidden = lines.length > 0;
    var src = $("bd-lines-source");
    if (src) {
      src.hidden = doc.lines_source !== "facto";
      src.textContent = "Detalle obtenido desde FACTO (el documento no tiene borrador local asociado).";
    }
    var t = doc.totals || {};
    setText("bd-net", money(t.net_amount));
    setText("bd-exempt", money(t.exempt_amount));
    setText("bd-iva", money(t.taxes_amount));
    setText("bd-total", money(t.total_amount));
  }

  function renderRefs(doc) {
    var list = $("bd-refs");
    if (!list) return;
    var refs = doc.refs || [];
    if (!refs.length) {
      list.innerHTML = "<tr><td colspan='5' class='bill-refs-empty'>Sin referencias</td></tr>";
      return;
    }
    var quotesBase = (cfg.quotesUrl || "/interno/customer-quotes/").replace(/\?.*$/, "");
    list.innerHTML = refs
      .map(function (ref) {
        var isQuote = ref.ref_type === "quote";
        var docType = isQuote ? "Cotización" : (ref.ref_doc_type || "Documento");
        var folio = isQuote
          ? (ref.quote_number || ref.ref_folio || ref.quote_id || "")
          : (ref.ref_folio || "");
        var action = "";
        if (isQuote && ref.quote_id) {
          var href = quotesBase + "?quote=" + encodeURIComponent(String(ref.quote_id));
          action = '<a class="bill-btn bill-btn-ref-more" href="' + esc(href) + '">Ver cotización</a>';
        }
        return "<tr>" +
          "<td>Otras referencias</td>" +
          "<td>--</td>" +
          "<td>" + esc(docType) + "</td>" +
          "<td>" + esc(folio) + "</td>" +
          "<td class='bill-refs-actions'>" + action + "</td>" +
          "</tr>";
      })
      .join("");
  }

  function syncLabel(p) {
    var st = p.facto_sync_status || "off";
    if (st === "ok") return '<span class="bill-sync bill-sync-ok">FACTO ok</span>';
    if (st === "pending" || st === "sending") return '<span class="bill-sync bill-sync-pending">Pendiente FACTO</span>';
    if (st === "error") {
      return '<span class="bill-sync bill-sync-error" title="' + esc(p.facto_sync_error || "") + '">Error</span>' +
        ' <button type="button" class="bill-btn bill-btn-secondary bill-doc-pay-btn" data-pay-retry="' + p.id + '">Reintentar</button>';
    }
    if (st === "unknown") {
      return '<span class="bill-sync bill-sync-unknown">Desconocido</span>' +
        ' <button type="button" class="bill-btn bill-btn-secondary bill-doc-pay-btn" data-pay-retry="' + p.id + '" data-unknown="1">Revisar / reintentar</button>';
    }
    return "";
  }

  function paymentApplied(p) {
    var applied = Number(p.amount_applied);
    if (applied > 0) return applied;
    return Math.max(0, (Number(p.amount_paid) || 0) - (Number(p.change_amount) || 0));
  }

  function applyPayments(payments) {
    var doc = state.doc;
    if (!doc) return;
    doc.payments = payments || [];
    var paid = doc.payments.reduce(function (s, p) { return s + paymentApplied(p); }, 0);
    var total = Number((doc.totals || {}).total_amount) || 0;
    doc.paid_amount = Math.round(paid * 100) / 100;
    doc.unpaid_amount = Math.max(0, Math.round((total - paid) * 100) / 100);
    renderPagos(doc);
  }

  function unpaidAmount() {
    return Math.max(0, Math.round(Number((state.doc || {}).unpaid_amount) || 0));
  }

  var TRASH_ICON =
    '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>';

  function renderPagos(doc) {
    var body = $("bd-pagos-body");
    if (!body) return;
    var total = Number((doc.totals || {}).total_amount) || 0;
    var due = unpaidAmount();
    var payBtn = due > 0
      ? '<button type="button" class="bill-btn bill-btn-success bill-doc-pay-btn" data-pay-open="1">Pagar (' + esc(money(due)) + ")</button>"
      : "";
    var rows =
      "<tr><td>COBRO</td><td>" + esc(formatDate(doc.issue_date)) + "</td>" +
      "<td></td><td></td><td></td><td></td><td></td>" +
      "<td>Cobro " + esc((PAYMENT_LABELS[String(doc.payment_conditions || "0")] || "").toLowerCase()) + "</td>" +
      "<td>" + esc(money(total)) + "</td><td>" + payBtn + "</td></tr>";
    (doc.payments || []).forEach(function (p) {
      rows +=
        "<tr><td>" + (p.facto_payment_id ? "P" + esc(p.facto_payment_id) : "PAGO") + "</td>" +
        "<td>" + esc(formatDate(p.pay_date)) + "</td>" +
        "<td>" + esc(p.method) + "</td><td>" + esc(p.caja) + "</td>" +
        "<td>" + esc(p.cheque_numero) + "</td><td>" + esc(p.cheque_titular) + "</td>" +
        "<td>" + esc(p.cheque_banco) + "</td>" +
        "<td>" + esc(p.notes) + "</td>" +
        "<td>-" + esc(money(paymentApplied(p))) + "</td>" +
        '<td class="bill-doc-pay-actions">' +
        '<button type="button" class="bill-btn bill-btn-danger bill-doc-pay-btn" data-pay-del="' + p.id + '" title="Borrar pago" aria-label="Borrar pago">' + TRASH_ICON + "</button> " +
        syncLabel(p) +
        "</td></tr>";
    });
    body.innerHTML = rows;
    setText("bd-pagos-cobros", money(total));
    setText("bd-pagos-pagos", money(doc.paid_amount));
    setText("bd-pagos-impago", money(doc.unpaid_amount));
    var pagos = $("bd-pagos-pagos");
    if (pagos) pagos.classList.toggle("is-zero", (Number(doc.paid_amount) || 0) <= 0);
  }

  function render(doc) {
    renderHeader(doc);
    renderReceiver(doc);
    renderLines(doc);
    renderRefs(doc);
    renderPagos(doc);
    if ($("bd-loading")) $("bd-loading").hidden = true;
    if ($("bd-content")) $("bd-content").hidden = false;
  }

  function load() {
    var dteId = Number(cfg.dteId) || 0;
    if (!dteId) {
      if ($("bd-loading")) $("bd-loading").textContent = "Falta el identificador del documento.";
      return Promise.resolve();
    }
    return post(action("documentGet", "riverso_billing_document_get"), { dte_id: dteId })
      .then(function (data) {
        state.doc = data.document || null;
        if (!state.doc) throw new Error("Documento no encontrado.");
        render(state.doc);
        showAlert((data.warnings || []).join(" "), !!(data.warnings && data.warnings.length));
      })
      .catch(function (err) {
        if ($("bd-loading")) $("bd-loading").textContent = err.message || "No se pudo cargar el documento.";
        showAlert(err.message || "No se pudo cargar el documento.", true);
      });
  }

  function fetchFile(kind) {
    if (state.files[kind]) return Promise.resolve(state.files[kind]);
    var isPdf = kind === "pdf";
    showAlert(isPdf ? "Obteniendo PDF…" : "Obteniendo XML…");
    return post(
      isPdf ? action("documentPdf", "riverso_billing_document_pdf") : action("documentXml", "riverso_billing_document_xml"),
      { dte_id: state.doc.dte_id }
    ).then(function (data) {
      var b64 = isPdf ? data.pdf_base64 : data.xml_base64;
      if (!b64) throw new Error(isPdf ? "PDF vacío." : "XML vacío.");
      var bin = atob(b64);
      var bytes = new Uint8Array(bin.length);
      for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
      var blob = new Blob([bytes], { type: isPdf ? "application/pdf" : "application/xml" });
      state.files[kind] = URL.createObjectURL(blob);
      showAlert("");
      return state.files[kind];
    });
  }

  function openFile(kind) {
    // La pestaña se abre antes del fetch para que el navegador no la bloquee como popup.
    var w = window.open("", "_blank");
    fetchFile(kind)
      .then(function (url) {
        if (w) {
          w.location.href = url;
        } else {
          window.location.href = url;
        }
      })
      .catch(function (err) {
        if (w) w.close();
        showAlert(err.message || "No se pudo obtener el archivo", true);
      });
  }

  function downloadFile(kind) {
    fetchFile(kind)
      .then(function (url) {
        var a = document.createElement("a");
        a.href = url;
        a.download = fileBaseName() + (kind === "pdf" ? ".pdf" : ".xml");
        document.body.appendChild(a);
        a.click();
        a.remove();
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo descargar el archivo", true);
      });
  }

  function printPdf() {
    fetchFile("pdf")
      .then(function (url) {
        var frame = $("bd-print-frame");
        if (frame) frame.remove();
        frame = document.createElement("iframe");
        frame.id = "bd-print-frame";
        frame.className = "bill-doc-print-frame";
        frame.src = url;
        frame.onload = function () {
          try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
          } catch (e) {
            window.open(url, "_blank");
          }
        };
        document.body.appendChild(frame);
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo imprimir", true);
      });
  }

  function printLetter(template) {
    var doc = state.doc || {};
    var type = Number(doc.document_type_id);
    if (!(doc.lines || []).length) {
      showAlert("El documento no tiene detalle para generar la carta.", true);
      return;
    }
    var w = window.open("", "_blank");
    showAlert("Generando carta…");
    post(action("previewHtml", "riverso_billing_preview_html"), {
      template: template,
      document_type_id: type === 2 || type === 32 ? 2 : 37,
      issue_date: doc.issue_date || "",
      estimated_folio: doc.folio || "",
      receiver_legal_name: (doc.receiver || {}).legal_name || "",
      receiver_rut: (doc.receiver || {}).rut || "",
      receiver_phone: (doc.receiver || {}).phone || "",
      lines: doc.lines,
    })
      .then(function (data) {
        if (!data || !data.html) throw new Error("La carta llegó vacía.");
        showAlert("");
        if (w) {
          w.document.open();
          w.document.write(data.html);
          w.document.close();
        }
      })
      .catch(function (err) {
        if (w) w.close();
        showAlert(err.message || "No se pudo generar la carta", true);
      });
  }

  function copyPayLink() {
    var url = (state.doc || {}).payment_url;
    if (!url) return;
    var done = function () {
      showAlert("Enlace de pago copiado.");
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () {
        window.prompt("Copia el enlace de pago:", url);
      });
    } else {
      window.prompt("Copia el enlace de pago:", url);
    }
  }

  function openEmailModal() {
    var doc = state.doc;
    if (!doc) return;
    var rut = String((doc.receiver || {}).rut || "").trim();
    if ($("bill-email-doc-label")) {
      $("bill-email-doc-label").textContent =
        (doc.document_type_label || "Documento") + " N° " + formatFolio(doc.folio) + (rut ? " · RUT " + rut : "");
    }
    var box = $("bill-email-widget");
    if (box && window.RiversoBillingEmails) {
      if (state.emailApi && typeof state.emailApi.destroy === "function") state.emailApi.destroy();
      state.emailApi = window.RiversoBillingEmails.mount(box, {
        rut: rut,
        persist: !!rut,
        cfg: cfg,
        post: post,
      });
    }
    var modal = $("bill-email-modal");
    if (modal) {
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
    }
  }

  function closeEmailModal() {
    var modal = $("bill-email-modal");
    if (modal) {
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
    }
    if (state.emailApi && typeof state.emailApi.destroy === "function") state.emailApi.destroy();
    state.emailApi = null;
  }

  function sendEmail() {
    var selected = state.emailApi && typeof state.emailApi.getSelected === "function"
      ? state.emailApi.getSelected()
      : [];
    if (!selected.length) {
      showAlert("Marca al menos un correo para enviar.", true);
      return;
    }
    var btn = $("bill-email-send");
    if (btn) btn.disabled = true;
    showAlert("Enviando correo…");
    post(action("documentEmail", "riverso_billing_document_email"), {
      dte_id: state.doc.dte_id,
      emails: JSON.stringify(selected),
    })
      .then(function (data) {
        closeEmailModal();
        showAlert((data && data.message) || "Correo enviado.");
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo enviar el correo", true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function showModal(id, open) {
    var modal = $(id);
    if (!modal) return;
    modal.hidden = !open;
    modal.setAttribute("aria-hidden", open ? "false" : "true");
  }

  function paymentMethods() {
    return cfg.paymentMethods || [];
  }

  function methodById(id) {
    id = parseInt(id, 10) || 0;
    return paymentMethods().filter(function (m) { return Number(m.id) === id; })[0] || null;
  }

  function pagoError(msg) {
    var el = $("bd-pago-error");
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || "";
  }

  function fillPagoMethods() {
    var sel = $("bd-pago-method");
    if (!sel) return;
    var html = paymentMethods().map(function (m) {
      return '<option value="' + esc(m.id) + '">' + esc(m.nombre) + "</option>";
    }).join("");
    sel.innerHTML = html || '<option value="">Sin métodos</option>';
    toggleCheque();
  }

  function applyCajas(boxes) {
    boxes = boxes || [];
    cfg.cashBoxes = boxes;
    var sel = $("bd-pago-caja");
    if (!sel) return;
    if (!boxes.length) {
      sel.innerHTML = '<option value="">No hay cajas abiertas</option>';
      sel.disabled = true;
      return;
    }
    sel.disabled = false;
    sel.innerHTML = boxes.map(function (b) {
      return '<option value="' + esc(b.id) + '" data-nombre="' + esc(b.nombre) + '">' + esc(b.nombre) + "</option>";
    }).join("");
  }

  function fillPagoCajas() {
    applyCajas(cfg.cashBoxes || []);
    post(action("cashBoxes", "riverso_billing_cash_boxes"), {})
      .then(function (data) {
        applyCajas(data.cashBoxes || []);
      })
      .catch(function () {
        /* se mantiene la lista cargada con la página */
      });
  }

  function toggleCheque() {
    var m = methodById(($("bd-pago-method") || {}).value);
    if ($("bd-pago-cheque")) $("bd-pago-cheque").hidden = !(m && m.requiere_cheque);
    updateVuelto();
  }

  function updateVuelto() {
    var due = unpaidAmount();
    var paid = parseFloat(($("bd-pago-paid") || {}).value) || 0;
    var m = methodById(($("bd-pago-method") || {}).value);
    var vuelto = m && m.permite_vuelto ? Math.max(0, Math.round(paid - due)) : 0;
    if ($("bd-pago-vuelto")) $("bd-pago-vuelto").value = String(vuelto);
  }

  function openPagoModal() {
    var due = unpaidAmount();
    if (due <= 0) {
      showAlert("El documento ya está pagado.");
      return;
    }
    pagoError("");
    if ($("bd-pago-fecha")) $("bd-pago-fecha").value = cfg.todayDate || "";
    if ($("bd-pago-due")) $("bd-pago-due").value = money(due);
    if ($("bd-pago-paid")) $("bd-pago-paid").value = String(due);
    if ($("bd-pago-notes")) $("bd-pago-notes").value = "";
    ["bd-pago-cheque-num", "bd-pago-cheque-tit", "bd-pago-cheque-banco"].forEach(function (id) {
      if ($(id)) $(id).value = "";
    });
    fillPagoMethods();
    fillPagoCajas();
    showModal("bd-pago-modal", true);
    if ($("bd-pago-paid")) $("bd-pago-paid").focus();
  }

  function savePago() {
    var doc = state.doc;
    if (!doc) return;
    var due = unpaidAmount();
    var paid = parseFloat(($("bd-pago-paid") || {}).value) || 0;
    var m = methodById(($("bd-pago-method") || {}).value);
    var cajaSel = $("bd-pago-caja");
    var cajaId = cajaSel ? parseInt(cajaSel.value, 10) || 0 : 0;
    if (!cajaId) {
      pagoError("No hay cajas abiertas con permiso para pagar. Abre una caja en Manejo de Caja.");
      return;
    }
    if (!m) {
      pagoError("Selecciona un método de pago.");
      return;
    }
    if (paid <= 0) {
      pagoError("Ingresa el monto pagado.");
      return;
    }
    if (!m.permite_vuelto && paid > due) {
      pagoError("Este método no permite vuelto. El monto no puede superar " + money(due) + ".");
      return;
    }
    var cheque = {
      num: (($("bd-pago-cheque-num") || {}).value || "").trim(),
      tit: (($("bd-pago-cheque-tit") || {}).value || "").trim(),
      banco: (($("bd-pago-cheque-banco") || {}).value || "").trim(),
    };
    if (m.requiere_cheque && (!cheque.num || !cheque.tit || !cheque.banco)) {
      pagoError("Completa número, titular y banco del documento.");
      return;
    }
    var opt = cajaSel.selectedOptions && cajaSel.selectedOptions[0];
    var vuelto = m.permite_vuelto ? Math.max(0, paid - due) : 0;
    var btn = $("bd-pago-save");
    if (btn) btn.disabled = true;
    pagoError("");
    post(action("draftPayment", "riverso_billing_draft_payment"), {
      draft_id: doc.draft_id || 0,
      dte_id: doc.dte_id,
      pay_date: ($("bd-pago-fecha") || {}).value || cfg.todayDate,
      caja_id: cajaId,
      caja: opt ? opt.getAttribute("data-nombre") || opt.textContent : "",
      method_id: m.id,
      amount_due: due,
      amount_paid: paid,
      change_amount: vuelto,
      notes: ($("bd-pago-notes") || {}).value || "",
      cheque_numero: cheque.num,
      cheque_titular: cheque.tit,
      cheque_banco: cheque.banco,
    })
      .then(function (res) {
        applyPayments(res.payments);
        showModal("bd-pago-modal", false);
        var sync = res.sync && res.sync.ok === false ? " Sync FACTO: " + (res.sync.message || "error") + "." : "";
        var left = unpaidAmount();
        showAlert(
          (left > 0 ? "Pago registrado. Quedan " + money(left) + "." : "Pago registrado. Documento saldado.") + sync,
          !!sync
        );
      })
      .catch(function (err) {
        pagoError(err.message || "No se pudo registrar el pago.");
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function findPayment(id) {
    return ((state.doc || {}).payments || []).filter(function (p) { return Number(p.id) === Number(id); })[0] || null;
  }

  function openDeleteModal(id) {
    var p = findPayment(id);
    if (!p) return;
    state.deletePaymentId = Number(id);
    if ($("bd-pago-delete-msg")) {
      $("bd-pago-delete-msg").textContent =
        "¿Borrar el pago de " + money(paymentApplied(p)) + " (" + (p.method || "sin método") + ", " +
        formatDate(p.pay_date) + ")? Esta acción es PERMANENTE.";
    }
    showModal("bd-pago-delete-modal", true);
    if ($("bd-pago-delete-ok")) $("bd-pago-delete-ok").focus();
  }

  function confirmDelete() {
    var id = state.deletePaymentId;
    if (!id) return;
    var btn = $("bd-pago-delete-ok");
    if (btn) btn.disabled = true;
    post(action("paymentDelete", "riverso_billing_payment_delete"), { payment_id: id })
      .then(function (res) {
        applyPayments(res.payments);
        showModal("bd-pago-delete-modal", false);
        showAlert("Pago borrado.");
      })
      .catch(function (err) {
        showModal("bd-pago-delete-modal", false);
        showAlert(err.message || "No se pudo borrar el pago", true);
      })
      .finally(function () {
        state.deletePaymentId = 0;
        if (btn) btn.disabled = false;
      });
  }

  function retryPayment(id, unknown) {
    if (unknown && !window.confirm("Reintentar un pago en estado desconocido puede duplicarlo en FACTO. ¿Continuar?")) return;
    showAlert("Reintentando sincronización…");
    post(action("paymentRetry", "riverso_billing_payment_retry"), { payment_id: id, confirm_unknown: unknown ? 1 : 0 })
      .then(function (res) {
        applyPayments(res.payments);
        showAlert((res.sync && res.sync.message) || "Sincronización actualizada.", !!(res.sync && res.sync.ok === false));
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo reintentar", true);
      });
  }

  function closeMenus() {
    document.querySelectorAll(".bill-doc-menu").forEach(function (m) {
      m.hidden = true;
    });
    document.querySelectorAll("[data-doc-menu]").forEach(function (b) {
      b.setAttribute("aria-expanded", "false");
    });
  }

  function runAction(name) {
    if (!state.doc) return;
    switch (name) {
      case "print-pdf":
        printPdf();
        break;
      case "print-family":
        printLetter("family");
        break;
      case "print-product":
        printLetter("product");
        break;
      case "pdf-view":
        openFile("pdf");
        break;
      case "pdf-download":
        downloadFile("pdf");
        break;
      case "xml-view":
        openFile("xml");
        break;
      case "xml-download":
        downloadFile("xml");
        break;
      case "email":
        openEmailModal();
        break;
      case "pay-link-copy":
        copyPayLink();
        break;
      case "pay-link-open":
        if (state.doc.payment_url) window.open(state.doc.payment_url, "_blank", "noopener");
        break;
      case "refresh":
        showAlert("Actualizando…");
        load();
        break;
    }
  }

  function setTab(name) {
    document.querySelectorAll("#bd-tabs .bill-tab").forEach(function (b) {
      var on = b.getAttribute("data-tab") === name;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-selected", on ? "true" : "false");
    });
    document.querySelectorAll("#bd-content .bill-tab-panel").forEach(function (p) {
      p.hidden = p.getAttribute("data-panel") !== name;
    });
  }

  function bind() {
    var root = $("riverso-billing-document");
    if (!root) return;

    root.addEventListener("click", function (e) {
      var t = e.target;
      if (!t || !t.closest) return;

      var menuBtn = t.closest("[data-doc-menu]");
      if (menuBtn) {
        e.preventDefault();
        e.stopPropagation();
        var menu = $(menuBtn.getAttribute("data-doc-menu"));
        var wasOpen = menu && !menu.hidden;
        closeMenus();
        if (menu && !wasOpen) {
          menu.hidden = false;
          menuBtn.setAttribute("aria-expanded", "true");
        }
        return;
      }

      var actBtn = t.closest("[data-doc-action]");
      if (actBtn && !actBtn.disabled) {
        e.preventDefault();
        e.stopPropagation();
        closeMenus();
        runAction(actBtn.getAttribute("data-doc-action"));
        return;
      }

      if (t.closest("#bd-pago-add") || t.closest("[data-pay-open]")) {
        openPagoModal();
        return;
      }
      var delBtn = t.closest("[data-pay-del]");
      if (delBtn) {
        openDeleteModal(delBtn.getAttribute("data-pay-del"));
        return;
      }
      var retryBtn = t.closest("[data-pay-retry]");
      if (retryBtn) {
        retryPayment(parseInt(retryBtn.getAttribute("data-pay-retry"), 10) || 0, retryBtn.getAttribute("data-unknown") === "1");
        return;
      }

      var tab = t.closest("#bd-tabs .bill-tab");
      if (tab) {
        setTab(tab.getAttribute("data-tab"));
      }
    });

    ["bd-pago-close", "bd-pago-cancel"].forEach(function (id) {
      if ($(id)) $(id).addEventListener("click", function () { showModal("bd-pago-modal", false); });
    });
    if ($("bd-pago-save")) $("bd-pago-save").addEventListener("click", savePago);
    if ($("bd-pago-method")) $("bd-pago-method").addEventListener("change", toggleCheque);
    if ($("bd-pago-paid")) $("bd-pago-paid").addEventListener("input", updateVuelto);
    if ($("bd-pago-delete-cancel")) {
      $("bd-pago-delete-cancel").addEventListener("click", function () { showModal("bd-pago-delete-modal", false); });
    }
    if ($("bd-pago-delete-ok")) $("bd-pago-delete-ok").addEventListener("click", confirmDelete);
    ["bd-pago-modal", "bd-pago-delete-modal"].forEach(function (id) {
      if ($(id)) {
        $(id).addEventListener("click", function (e) {
          if (e.target === $(id)) showModal(id, false);
        });
      }
    });

    document.addEventListener("click", closeMenus);
    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      closeMenus();
      if ($("bill-email-modal") && !$("bill-email-modal").hidden) closeEmailModal();
      showModal("bd-pago-modal", false);
      showModal("bd-pago-delete-modal", false);
    });

    if ($("bill-email-close")) $("bill-email-close").addEventListener("click", closeEmailModal);
    if ($("bill-email-cancel")) $("bill-email-cancel").addEventListener("click", closeEmailModal);
    if ($("bill-email-send")) $("bill-email-send").addEventListener("click", sendEmail);
    if ($("bill-email-modal")) {
      $("bill-email-modal").addEventListener("click", function (e) {
        if (e.target === $("bill-email-modal")) closeEmailModal();
      });
    }

    root.setAttribute("data-ready", "1");
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();
