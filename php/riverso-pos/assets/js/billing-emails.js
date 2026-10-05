/**
 * Lista de correos de receptor para emitir / reenviar DTE.
 * window.RiversoBillingEmails.mount(container, { rut, persist, cfg, post })
 */
(function (window) {
  "use strict";

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function isEmail(v) {
    var s = String(v || "").trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
  }

  function mount(container, opts) {
    opts = opts || {};
    if (!container) {
      return { getSelected: function () { return []; }, destroy: function () {} };
    }

    var cfg = opts.cfg || window.RIVERSO_BILLING || {};
    var post = typeof opts.post === "function" ? opts.post : null;
    var rut = String(opts.rut || "").trim();
    var persist = !!opts.persist && !!rut && !!post;
    var emails = [];
    var nextLocalId = -1;
    var busy = false;

    container.classList.add("bill-email-widget");
    container.innerHTML =
      '<div class="bill-email-head">' +
      '<span class="bill-email-title">Enviar por correo</span>' +
      '<span class="bill-email-hint">PDF y XML</span>' +
      "</div>" +
      '<ul class="bill-email-list" role="list"></ul>' +
      '<p class="bill-email-empty" hidden>Sin correos. Escribe uno y pulsa Enter.</p>' +
      '<div class="bill-email-add-row">' +
      '<input type="email" class="bill-email-input" placeholder="correo@empresa.cl — Enter para agregar" autocomplete="email" enterkeyhint="done">' +
      '<button type="button" class="bill-btn bill-btn-primary bill-email-add-btn" title="Agregar correo">+</button>' +
      "</div>" +
      '<p class="bill-email-error" hidden></p>';

    var listEl = container.querySelector(".bill-email-list");
    var emptyEl = container.querySelector(".bill-email-empty");
    var inputEl = container.querySelector(".bill-email-input");
    var addBtn = container.querySelector(".bill-email-add-btn");
    var errorEl = container.querySelector(".bill-email-error");

    function setError(msg) {
      if (!errorEl) return;
      errorEl.hidden = !msg;
      errorEl.textContent = msg || "";
    }

    function action(name) {
      return (cfg.actions && cfg.actions[name]) || "";
    }

    function getSelected() {
      return emails
        .filter(function (e) { return e && e.is_selected && e.email; })
        .map(function (e) { return String(e.email); });
    }

    function render() {
      if (!listEl) return;
      listEl.innerHTML = "";
      emails.forEach(function (item, idx) {
        var li = document.createElement("li");
        li.className = "bill-email-row";
        li.setAttribute("data-idx", String(idx));
        li.innerHTML =
          '<label class="bill-email-check">' +
          '<input type="checkbox" data-email-toggle="' + esc(item.id) + '"' +
          (item.is_selected ? " checked" : "") +
          (busy ? " disabled" : "") +
          ">" +
          '<span class="bill-email-addr">' + esc(item.email) + "</span>" +
          (item.suggested ? '<span class="bill-email-badge">sugerido</span>' : "") +
          "</label>" +
          '<button type="button" class="bill-email-x" data-email-del="' + esc(item.id) +
          '" title="Eliminar correo" aria-label="Eliminar ' + esc(item.email) + '"' +
          (busy ? " disabled" : "") + ">×</button>";
        listEl.appendChild(li);
      });
      if (emptyEl) emptyEl.hidden = emails.length > 0;
    }

    function setEmails(list) {
      emails = (list || []).map(function (e) {
        return {
          id: e.id != null ? e.id : nextLocalId--,
          email: String(e.email || "").trim().toLowerCase(),
          is_selected: e.is_selected !== false,
          suggested: !!e.suggested,
        };
      });
      render();
    }

    function load() {
      setError("");
      if (!persist) {
        setEmails([]);
        return Promise.resolve();
      }
      busy = true;
      render();
      return post(action("emailsList") || "riverso_billing_emails_list", { rut: rut })
        .then(function (data) {
          var list = (data && data.emails) || [];
          if ((!list || !list.length) && data && data.suggestion && data.suggestion.email) {
            list = [data.suggestion];
          }
          setEmails(list);
        })
        .catch(function (err) {
          setError((err && err.message) || "No se pudieron cargar los correos.");
          setEmails([]);
        })
        .finally(function () {
          busy = false;
          render();
        });
    }

    function addEmail() {
      var value = String((inputEl && inputEl.value) || "").trim().toLowerCase();
      setError("");
      if (!value) return;
      if (!isEmail(value)) {
        setError("Correo no válido.");
        if (inputEl) inputEl.focus();
        return;
      }
      var exists = emails.some(function (e) { return e.email === value; });
      if (exists) {
        emails.forEach(function (e) {
          if (e.email === value) e.is_selected = true;
        });
        if (inputEl) inputEl.value = "";
        render();
        if (persist) {
          var existing = emails.find(function (e) { return e.email === value; });
          if (existing && existing.id > 0) {
            post(action("emailToggle") || "riverso_billing_email_toggle", {
              id: existing.id,
              selected: 1,
            }).catch(function () { /* ignore */ });
          }
        }
        return;
      }
      if (!persist) {
        emails.push({ id: nextLocalId--, email: value, is_selected: true });
        if (inputEl) inputEl.value = "";
        render();
        if (inputEl) inputEl.focus();
        return;
      }
      busy = true;
      render();
      post(action("emailAdd") || "riverso_billing_email_add", { rut: rut, email: value })
        .then(function (data) {
          setEmails((data && data.emails) || []);
          if (inputEl) inputEl.value = "";
        })
        .catch(function (err) {
          setError((err && err.message) || "No se pudo agregar.");
        })
        .finally(function () {
          busy = false;
          render();
          if (inputEl) inputEl.focus();
        });
    }

    function toggleEmail(id, selected) {
      var item = emails.find(function (e) { return String(e.id) === String(id); });
      if (!item) return;
      item.is_selected = !!selected;
      render();
      if (!persist || !(item.id > 0) || item.suggested) {
        if (persist && item.suggested && selected) {
          busy = true;
          post(action("emailAdd") || "riverso_billing_email_add", { rut: rut, email: item.email })
            .then(function (data) { setEmails((data && data.emails) || []); })
            .catch(function (err) { setError((err && err.message) || "No se pudo guardar."); })
            .finally(function () { busy = false; render(); });
        }
        return;
      }
      post(action("emailToggle") || "riverso_billing_email_toggle", {
        id: item.id,
        selected: selected ? 1 : 0,
      }).catch(function (err) {
        setError((err && err.message) || "No se pudo actualizar.");
        load();
      });
    }

    function deleteEmail(id) {
      var item = emails.find(function (e) { return String(e.id) === String(id); });
      if (!item) return;
      if (!window.confirm("¿Eliminar el correo " + item.email + " de este cliente?")) {
        return;
      }
      if (!persist || !(item.id > 0) || item.suggested) {
        emails = emails.filter(function (e) { return String(e.id) !== String(id); });
        render();
        return;
      }
      busy = true;
      render();
      post(action("emailDelete") || "riverso_billing_email_delete", { id: item.id })
        .then(function (data) {
          setEmails((data && data.emails) || []);
        })
        .catch(function (err) {
          setError((err && err.message) || "No se pudo eliminar.");
        })
        .finally(function () {
          busy = false;
          render();
        });
    }

    function onClick(e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var del = t.closest("[data-email-del]");
      if (del) {
        e.preventDefault();
        deleteEmail(del.getAttribute("data-email-del"));
        return;
      }
      if (t === addBtn || (t.closest && t.closest(".bill-email-add-btn"))) {
        e.preventDefault();
        addEmail();
      }
    }

    function onChange(e) {
      var t = e.target;
      if (!t || !t.getAttribute) return;
      if (t.getAttribute("data-email-toggle") != null) {
        toggleEmail(t.getAttribute("data-email-toggle"), !!t.checked);
      }
    }

    function onKey(e) {
      if (e.key === "Enter") {
        e.preventDefault();
        addEmail();
      }
    }

    container.addEventListener("click", onClick);
    container.addEventListener("change", onChange);
    if (inputEl) inputEl.addEventListener("keydown", onKey);

    load();

    return {
      getSelected: getSelected,
      reload: load,
      destroy: function () {
        container.removeEventListener("click", onClick);
        container.removeEventListener("change", onChange);
        if (inputEl) inputEl.removeEventListener("keydown", onKey);
        container.innerHTML = "";
      },
    };
  }

  window.RiversoBillingEmails = { mount: mount };
})(window);
