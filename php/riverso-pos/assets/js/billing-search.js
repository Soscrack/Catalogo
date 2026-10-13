/**
 * Facturación · Buscar documentos tributarios.
 */
(function () {
  "use strict";

  var cfg = window.RIVERSO_BILLING || {};
  var state = {
    groups: [],
    selected: {},
    pageSize: {},
    page: {},
    openMenus: null,
    emailApi: null,
    emailDteId: 0,
    emailRut: "",
  };

  function $(id) {
    return document.getElementById(id);
  }

  function showAlert(msg, isError) {
    var el = $("bill-search-alerts");
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = "";
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.classList.toggle("bill-alerts-error", !!isError);
  }

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v === undefined || v === null) return;
      if (typeof v === "boolean") {
        body.append(k, v ? "1" : "0");
        return;
      }
      body.append(k, v);
    });
    return fetch(cfg.ajaxUrl || "/wp-admin/admin-ajax.php", {
      method: "POST",
      body: body,
      credentials: "same-origin",
    }).then(function (r) {
      return r.json();
    }).then(function (json) {
      if (!json || !json.success) {
        var msg = (json && json.data && json.data.message) || "Error en la búsqueda.";
        throw new Error(msg);
      }
      return json.data || {};
    });
  }

  function money(n) {
    var v = Number(n) || 0;
    return "$" + v.toLocaleString("es-CL", { maximumFractionDigits: 0 });
  }

  function formatDate(iso) {
    if (!iso) return "—";
    var m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return iso;
    return m[3] + "-" + m[2] + "-" + m[1];
  }

  function formatFolio(folio) {
    if (!folio) return "—";
    var n = Number(String(folio).replace(/\D/g, ""));
    if (!n || !isFinite(n)) return String(folio);
    return n.toLocaleString("es-CL");
  }

  function collectFilters() {
    return {
      date_from: ($("bs-date-from") || {}).value || "",
      date_to: ($("bs-date-to") || {}).value || "",
      receiver_rut: ($("bs-rut") || {}).value || "",
      receiver_name: ($("bs-receiver") || {}).value || "",
      document_number: ($("bs-doc-number") || {}).value || "",
      payment_status: ($("bs-payment-status") || {}).value || "all",
      document_status: ($("bs-doc-status") || {}).value || "",
      total_min: ($("bs-total-min") || {}).value || "",
      total_max: ($("bs-total-max") || {}).value || "",
      created_by: ($("bs-created-by") || {}).value || "",
      folio_from: ($("bs-folio-from") || {}).value || "",
      folio_to: ($("bs-folio-to") || {}).value || "",
      sii_status: ($("bs-sii-status") || {}).value || "",
      include_issued: !!($("bs-scope-issued") && $("bs-scope-issued").checked),
      include_drafts: !!($("bs-scope-drafts") && $("bs-scope-drafts").checked),
    };
  }

  function resetFilters() {
    [
      "bs-date-from", "bs-date-to", "bs-rut", "bs-receiver", "bs-doc-number",
      "bs-total-min", "bs-total-max", "bs-folio-from", "bs-folio-to",
    ].forEach(function (id) {
      if ($(id)) $(id).value = "";
    });
    if ($("bs-payment-status")) $("bs-payment-status").value = "all";
    if ($("bs-doc-status")) $("bs-doc-status").value = "";
    if ($("bs-created-by")) $("bs-created-by").value = "";
    if ($("bs-sii-status")) $("bs-sii-status").value = "";
    if ($("bs-scope-issued")) $("bs-scope-issued").checked = true;
    if ($("bs-scope-drafts")) $("bs-scope-drafts").checked = true;
    state.groups = [];
    state.selected = {};
    state.page = {};
    state.pageSize = {};
    if ($("bs-results")) $("bs-results").hidden = true;
    if ($("bs-groups")) $("bs-groups").innerHTML = "";
    updateSelectedCount();
    showAlert("");
  }

  function selectedCount() {
    return Object.keys(state.selected).filter(function (k) {
      return state.selected[k];
    }).length;
  }

  function updateSelectedCount() {
    var el = $("bs-selected-count");
    if (el) el.textContent = selectedCount() + " seleccionados";
  }

  function rowKey(row) {
    return (row.source || "x") + ":" + (row.id || 0);
  }

  function editUrl(draftId) {
    var base = (cfg.portalUrl || "/interno/facturacion/").replace(/\?.*$/, "");
    if (base.slice(-1) !== "/") base += "/";
    return base + "?draft_id=" + encodeURIComponent(String(draftId));
  }

  function documentUrl(dteId) {
    var base = cfg.documentUrl || ((cfg.portalUrl || "/interno/facturacion/").replace(/\?.*$/, "") + "?vista=documento");
    return base + (base.indexOf("?") === -1 ? "?" : "&") + "dte_id=" + encodeURIComponent(String(dteId));
  }

  function openPdf(dteId) {
    var action = (cfg.actions && cfg.actions.documentPdf) || "riverso_billing_document_pdf";
    showAlert("Obteniendo PDF…");
    post(action, { dte_id: dteId })
      .then(function (data) {
        if (!data || !data.pdf_base64) {
          throw new Error("PDF vacío.");
        }
        showAlert("");
        var bin = atob(data.pdf_base64);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        var blob = new Blob([bytes], { type: "application/pdf" });
        var url = URL.createObjectURL(blob);
        window.open(url, "_blank");
        setTimeout(function () {
          URL.revokeObjectURL(url);
        }, 60000);
      })
      .catch(function (err) {
        showAlert(err.message || "No se pudo obtener el PDF", true);
      });
  }

  function closeEmailModal() {
    var modal = $("bill-email-modal");
    if (modal) {
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
    }
    if (state.emailApi && typeof state.emailApi.destroy === "function") {
      state.emailApi.destroy();
    }
    state.emailApi = null;
    state.emailDteId = 0;
    state.emailRut = "";
  }

  function openEmailModal(row) {
    if (!row || !row.dte_id) return;
    closeAllActionMenus();
    state.emailDteId = Number(row.dte_id) || 0;
    state.emailRut = String(row.receiver_rut || "").trim();
    var label = $("bill-email-doc-label");
    if (label) {
      label.textContent =
        (row.document_type_label || "Documento") +
        " N° " +
        (row.folio || "—") +
        (state.emailRut ? " · RUT " + state.emailRut : "");
    }
    var box = $("bill-email-widget");
    if (box && window.RiversoBillingEmails) {
      if (state.emailApi && typeof state.emailApi.destroy === "function") {
        state.emailApi.destroy();
      }
      state.emailApi = window.RiversoBillingEmails.mount(box, {
        rut: state.emailRut,
        persist: !!state.emailRut,
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

  function sendDocumentEmail() {
    if (!state.emailDteId) {
      showAlert("Documento inválido.", true);
      return;
    }
    var selected =
      state.emailApi && typeof state.emailApi.getSelected === "function"
        ? state.emailApi.getSelected()
        : [];
    if (!selected.length) {
      showAlert("Marca al menos un correo para enviar.", true);
      return;
    }
    var btn = $("bill-email-send");
    if (btn) btn.disabled = true;
    showAlert("Enviando correo…");
    var action = (cfg.actions && cfg.actions.documentEmail) || "riverso_billing_document_email";
    post(action, {
      dte_id: state.emailDteId,
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

  function findRowByDte(dteId) {
    var id = String(dteId);
    for (var g = 0; g < state.groups.length; g++) {
      var rows = (state.groups[g] && state.groups[g].rows) || [];
      for (var i = 0; i < rows.length; i++) {
        if (String(rows[i].dte_id) === id) return rows[i];
      }
    }
    return null;
  }

  function closeAllActionMenus() {
    document.querySelectorAll(".bill-search-action-menu").forEach(function (m) {
      m.hidden = true;
    });
    document.querySelectorAll(".bill-search-split-toggle").forEach(function (b) {
      b.setAttribute("aria-expanded", "false");
    });
  }

  function pageSlice(rows, groupKey) {
    var size = state.pageSize[groupKey] || 10;
    var page = state.page[groupKey] || 1;
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / size));
    if (page > pages) page = pages;
    state.page[groupKey] = page;
    var start = (page - 1) * size;
    return {
      rows: rows.slice(start, start + size),
      page: page,
      pages: pages,
      size: size,
      total: total,
      start: total ? start + 1 : 0,
      end: Math.min(start + size, total),
    };
  }

  function renderActions(row) {
    if (row.can_edit && row.draft_id) {
      return (
        '<a class="bill-search-edit-btn" href="' +
        editUrl(row.draft_id) +
        '" title="Editar borrador" aria-label="Editar borrador">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zm14.71-9.04c.39-.39.39-1.02 0-1.41l-2.54-2.54a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 2.03-1.63z"/></svg>' +
        "</a>"
      );
    }
    if (row.can_pdf && row.dte_id) {
      var menuId = "bs-menu-" + row.dte_id;
      return (
        '<div class="bill-search-split">' +
        '<a class="bill-search-split-main" href="' +
        escapeAttr(documentUrl(row.dte_id)) +
        '" title="Ver documento" aria-label="Ver documento">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg>' +
        '<span class="bill-search-pdf-mark">Ver</span>' +
        "</a>" +
        '<button type="button" class="bill-search-split-toggle" data-menu="' +
        menuId +
        '" aria-haspopup="menu" aria-expanded="false" aria-label="Más acciones">▾</button>' +
        '<div class="bill-search-action-menu" id="' +
        menuId +
        '" hidden role="menu">' +
        '<a class="bill-search-action-item" role="menuitem" href="' +
        escapeAttr(documentUrl(row.dte_id)) +
        '"><span class="bill-search-action-icon">▸</span> Ver documento</a>' +
        '<button type="button" class="bill-search-action-item" role="menuitem" data-pdf="' +
        row.dte_id +
        '"><span class="bill-search-action-icon">PDF</span> PDF</button>' +
        '<button type="button" class="bill-search-action-item" role="menuitem" data-email="' +
        row.dte_id +
        '"><span class="bill-search-action-icon">✉</span> Enviar por correo</button>' +
        '<button type="button" class="bill-search-action-item is-wip" role="menuitem" disabled title="En desarrollo">' +
        '<span class="bill-search-action-icon">⊘</span> Emitir nota crédito [WIP]</button>' +
        "</div></div>"
      );
    }
    return "";
  }

  function renderGroup(group) {
    var key = group.key || "g";
    if (!state.pageSize[key]) state.pageSize[key] = 10;
    if (!state.page[key]) state.page[key] = 1;
    var slice = pageSlice(group.rows || [], key);
    var title = (group.label || "Documentos").toUpperCase();

    var html = '<section class="bill-search-group" data-group="' + key + '">';
    html += '<div class="bill-search-group-head">';
    html += "<h3>" + escapeHtml(title) + "</h3>";
    html +=
      '<label class="bill-search-page-size">Mostrar <select data-page-size="' +
      key +
      '">' +
      [10, 25, 50].map(function (n) {
        return (
          '<option value="' +
          n +
          '"' +
          (slice.size === n ? " selected" : "") +
          ">" +
          n +
          "</option>"
        );
      }).join("") +
      "</select> registros</label>";
    html += "</div>";

    html += '<div class="bill-search-table-wrap"><table class="bill-search-table">';
    html +=
      "<thead><tr>" +
      '<th class="bill-search-col-check"><input type="checkbox" data-check-all="' +
      key +
      '" aria-label="Seleccionar todos"></th>' +
      "<th>Fecha</th><th>Folio</th><th>Receptor</th><th>ID Tributario</th>" +
      "<th>Neto</th><th>Impuestos</th><th>Total</th><th>Pagado</th><th>Impago</th>" +
      "<th>Detalle</th><th></th>" +
      "</tr></thead><tbody>";

    if (!slice.rows.length) {
      html += '<tr><td colspan="12" class="bill-search-td-empty">Sin registros</td></tr>';
    } else {
      slice.rows.forEach(function (row) {
        var rk = rowKey(row);
        var checked = state.selected[rk] ? " checked" : "";
        var unpaidClass = Number(row.unpaid_amount) <= 0.009 ? " is-zero" : "";
        html += "<tr>";
        html +=
          '<td class="bill-search-col-check"><input type="checkbox" data-row-key="' +
          escapeAttr(rk) +
          '"' +
          checked +
          "></td>";
        html += "<td>" + escapeHtml(formatDate(row.issue_date)) + "</td>";
        html += "<td>" + escapeHtml(formatFolio(row.folio)) + "</td>";
        html += "<td>" + escapeHtml(row.receiver_display || "—") + "</td>";
        html += "<td>" + escapeHtml(row.receiver_rut || "") + "</td>";
        html += "<td>" + escapeHtml(money(row.net_amount)) + "</td>";
        html += "<td>" + escapeHtml(money(row.taxes_amount)) + "</td>";
        html += "<td>" + escapeHtml(money(row.total_amount)) + "</td>";
        html += "<td>" + escapeHtml(money(row.paid_amount)) + "</td>";
        html +=
          '<td class="bill-search-unpaid' +
          unpaidClass +
          '">' +
          escapeHtml(money(row.unpaid_amount)) +
          "</td>";
        html +=
          '<td><span class="bill-search-badge">' +
          escapeHtml(row.sale_state || "VENTA: Concretada") +
          "</span></td>";
        html += '<td class="bill-search-col-actions">' + renderActions(row) + "</td>";
        html += "</tr>";
      });
    }

    html += "</tbody></table></div>";

    html += '<div class="bill-search-pager">';
    html +=
      '<span class="bill-search-pager-info">Mostrando ' +
      slice.start +
      " a " +
      slice.end +
      " de " +
      slice.total +
      "</span>";
    html += '<div class="bill-search-pager-btns">';
    html +=
      '<button type="button" class="bill-btn bill-btn-secondary" data-page-prev="' +
      key +
      '"' +
      (slice.page <= 1 ? " disabled" : "") +
      ">Anterior</button>";
    html +=
      '<span class="bill-search-pager-num">' +
      slice.page +
      " / " +
      slice.pages +
      "</span>";
    html +=
      '<button type="button" class="bill-btn bill-btn-secondary" data-page-next="' +
      key +
      '"' +
      (slice.page >= slice.pages ? " disabled" : "") +
      ">Siguiente</button>";
    html += "</div></div></section>";
    return html;
  }

  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, "&#39;");
  }

  function render() {
    var wrap = $("bs-groups");
    var empty = $("bs-empty");
    var results = $("bs-results");
    if (!wrap || !results) return;
    results.hidden = false;
    closeAllActionMenus();
    if (!state.groups.length) {
      wrap.innerHTML = "";
      if (empty) empty.hidden = false;
      updateSelectedCount();
      return;
    }
    if (empty) empty.hidden = true;
    wrap.innerHTML = state.groups.map(renderGroup).join("");
    updateSelectedCount();
  }

  function runSearch() {
    var filters = collectFilters();
    if (!filters.include_issued && !filters.include_drafts) {
      showAlert("Selecciona al menos emitidos o borradores.", true);
      return;
    }
    var action = (cfg.actions && cfg.actions.searchDocuments) || "riverso_billing_search_documents";
    var btn = $("bs-search");
    if (btn) btn.disabled = true;
    showAlert("Buscando…");
    post(action, filters)
      .then(function (data) {
        state.groups = data.groups || [];
        state.selected = {};
        state.page = {};
        showAlert(
          state.groups.length
            ? "Se encontraron " + (data.total || 0) + " documento(s)."
            : ""
        );
        render();
      })
      .catch(function (err) {
        showAlert(err.message || "Error al buscar", true);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  }

  function bind() {
    var root = $("riverso-billing-search");
    if (!root) return;

    if ($("bs-adv-toggle")) {
      $("bs-adv-toggle").addEventListener("click", function () {
        var body = $("bs-adv-body");
        var open = body && !body.hidden;
        if (body) body.hidden = open;
        $("bs-adv-toggle").setAttribute("aria-expanded", open ? "false" : "true");
        $("bs-adv-toggle").classList.toggle("is-open", !open);
      });
    }

    if ($("bs-search")) $("bs-search").addEventListener("click", runSearch);
    if ($("bs-reset")) $("bs-reset").addEventListener("click", resetFilters);

    if ($("bs-bulk-toggle")) {
      $("bs-bulk-toggle").addEventListener("click", function (e) {
        e.stopPropagation();
        var menu = $("bs-bulk-menu");
        if (!menu) return;
        var open = !menu.hidden;
        menu.hidden = open;
        $("bs-bulk-toggle").setAttribute("aria-expanded", open ? "false" : "true");
      });
    }

    document.addEventListener("click", function () {
      closeAllActionMenus();
      if ($("bs-bulk-menu")) $("bs-bulk-menu").hidden = true;
      if ($("bs-bulk-toggle")) $("bs-bulk-toggle").setAttribute("aria-expanded", "false");
    });

    var groupsEl = $("bs-groups");
    if (groupsEl) {
      groupsEl.addEventListener("click", function (e) {
        var t = e.target;
        if (!t || !t.closest) return;

        var pdfBtn = t.closest("[data-pdf]");
        if (pdfBtn && pdfBtn.getAttribute("data-pdf")) {
          e.preventDefault();
          e.stopPropagation();
          closeAllActionMenus();
          openPdf(pdfBtn.getAttribute("data-pdf"));
          return;
        }

        var emailBtn = t.closest("[data-email]");
        if (emailBtn && emailBtn.getAttribute("data-email")) {
          e.preventDefault();
          e.stopPropagation();
          closeAllActionMenus();
          openEmailModal(findRowByDte(emailBtn.getAttribute("data-email")));
          return;
        }

        var toggle = t.closest(".bill-search-split-toggle");
        if (toggle) {
          e.preventDefault();
          e.stopPropagation();
          var mid = toggle.getAttribute("data-menu");
          var menu = mid ? document.getElementById(mid) : null;
          var wasOpen = menu && !menu.hidden;
          closeAllActionMenus();
          if (menu && !wasOpen) {
            menu.hidden = false;
            toggle.setAttribute("aria-expanded", "true");
          }
          return;
        }

        var prev = t.closest("[data-page-prev]");
        if (prev) {
          var gk = prev.getAttribute("data-page-prev");
          state.page[gk] = Math.max(1, (state.page[gk] || 1) - 1);
          render();
          return;
        }
        var next = t.closest("[data-page-next]");
        if (next) {
          var gk2 = next.getAttribute("data-page-next");
          state.page[gk2] = (state.page[gk2] || 1) + 1;
          render();
        }
      });

      groupsEl.addEventListener("change", function (e) {
        var t = e.target;
        if (!t) return;
        if (t.getAttribute("data-page-size")) {
          var key = t.getAttribute("data-page-size");
          state.pageSize[key] = parseInt(t.value, 10) || 10;
          state.page[key] = 1;
          render();
          return;
        }
        if (t.getAttribute("data-check-all")) {
          var gkey = t.getAttribute("data-check-all");
          var group = state.groups.find(function (g) {
            return g.key === gkey;
          });
          var on = !!t.checked;
          (group && group.rows ? group.rows : []).forEach(function (row) {
            state.selected[rowKey(row)] = on;
          });
          render();
          return;
        }
        if (t.getAttribute("data-row-key")) {
          state.selected[t.getAttribute("data-row-key")] = !!t.checked;
          updateSelectedCount();
        }
      });
    }

    if ($("bill-email-close")) $("bill-email-close").addEventListener("click", closeEmailModal);
    if ($("bill-email-cancel")) $("bill-email-cancel").addEventListener("click", closeEmailModal);
    if ($("bill-email-send")) $("bill-email-send").addEventListener("click", sendDocumentEmail);
    if ($("bill-email-modal")) {
      $("bill-email-modal").addEventListener("click", function (e) {
        if (e.target === $("bill-email-modal")) closeEmailModal();
      });
    }
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && $("bill-email-modal") && !$("bill-email-modal").hidden) {
        closeEmailModal();
      }
    });

    root.setAttribute("data-ready", "1");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bind);
  } else {
    bind();
  }
})();
