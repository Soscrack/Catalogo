/**
 * Impresión directa ("Imprimir Ya!").
 *
 * Crea el trabajo en la cola del servidor, sigue su estado (el hub local lo imprime) y,
 * si algo falla, muestra el aviso de emergencia con reintento, impresoras alternativas
 * y el diálogo normal del navegador como último recurso.
 *
 * Uso: window.RiversoPrint.printDte(dteId, { fallbackDialog: fn })
 */
(function () {
  "use strict";

  var cfg = window.RIVERSO_PRINT || {};
  var STATION_KEY = "riverso.print.station";
  var POLL_MS = 1000;
  var TRACK_LIMIT_MS = 180000;
  var tracking = {};

  function action(key, fallback) {
    return (cfg.actions && cfg.actions[key]) || fallback;
  }

  function post(act, data) {
    var body = new FormData();
    body.append("action", act);
    body.append("nonce", cfg.nonce || "");
    Object.keys(data || {}).forEach(function (k) {
      var v = data[k];
      if (v === undefined || v === null) return;
      body.append(k, typeof v === "boolean" ? (v ? "1" : "0") : v);
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
          throw new Error((json && json.data && json.data.message) || "Error en la solicitud.");
        }
        return json.data || {};
      });
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // ── Estación de este dispositivo (se guarda en el navegador) ──

  function station() {
    try {
      var st = JSON.parse(window.localStorage.getItem(STATION_KEY) || "null");
      return st && st.id ? { id: Number(st.id) || 0, nombre: String(st.nombre || "") } : null;
    } catch (e) {
      return null;
    }
  }

  function setStation(st) {
    try {
      if (st && st.id) {
        window.localStorage.setItem(STATION_KEY, JSON.stringify({ id: st.id, nombre: st.nombre || "" }));
      } else {
        window.localStorage.removeItem(STATION_KEY);
      }
    } catch (e) {
      // Sin almacenamiento: se usa el ruteo general.
    }
  }

  function stationId() {
    var st = station();
    return st ? st.id : 0;
  }

  // ── Aviso flotante de progreso ──

  var toastEl = null;
  var toastTimer = 0;

  function toast(kind, title, text) {
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.className = "rp-toast";
      toastEl.setAttribute("role", "status");
      toastEl.setAttribute("aria-live", "polite");
      document.body.appendChild(toastEl);
      toastEl.addEventListener("click", function (e) {
        if (e.target.closest(".rp-toast-x")) hideToast();
      });
    }
    clearTimeout(toastTimer);
    var icon = kind === "ok" ? "✓" : kind === "error" ? "!" : "";
    toastEl.className = "rp-toast is-" + kind;
    toastEl.innerHTML =
      '<span class="rp-toast-icon" aria-hidden="true">' + (kind === "busy" ? '<span class="rp-spin"></span>' : icon) + "</span>" +
      '<span class="rp-toast-body"><strong>' + esc(title) + "</strong>" + (text ? "<span>" + esc(text) + "</span>" : "") + "</span>" +
      '<button type="button" class="rp-toast-x" aria-label="Cerrar">×</button>';
    toastEl.hidden = false;
    if (kind === "ok") {
      toastTimer = setTimeout(hideToast, 5000);
    }
  }

  function hideToast() {
    clearTimeout(toastTimer);
    if (toastEl) toastEl.hidden = true;
  }

  function progressText(job) {
    var where = job.printer ? "«" + job.printer + "»" : "la impresora";
    switch (job.estado) {
      case "pendiente":
        return "En cola · esperando al hub" + (job.hub ? " «" + job.hub + "»" : "") + "…";
      case "tomado":
        return job.detalle || "Preparando…";
      case "imprimiendo":
        return "Imprimiendo en " + where + "…";
      case "impreso":
        return "Impreso en " + where + ".";
      default:
        return job.estado_label || "";
    }
  }

  // ── Aviso de emergencia ──

  var modalEl = null;

  function closeEmergency() {
    if (modalEl) {
      modalEl.remove();
      modalEl = null;
    }
    document.removeEventListener("keydown", onModalKey);
  }

  function onModalKey(e) {
    if (e.key === "Escape") closeEmergency();
  }

  function showEmergency(job, ctx) {
    closeEmergency();
    hideToast();
    var canRetry = !!ctx.dteId;
    var alts = canRetry ? job.alternatives || [] : [];
    var altHtml = "";
    if (alts.length) {
      altHtml = '<div class="rp-alts"><span class="rp-alts-label">Imprimir en otra impresora:</span>' +
        alts.map(function (a) {
          return '<button type="button" class="rp-btn ' + (a.recommended ? "rp-btn-primary" : "") + '" data-rp-alt="' + esc(a.preset_id) + '">' +
            esc(a.label) + (a.printer && a.printer !== a.label ? ' <small>(' + esc(a.printer) + ")</small>" : "") +
            "</button>";
        }).join("") + "</div>";
    } else if (canRetry) {
      altHtml = '<p class="rp-muted">No hay otra impresora disponible en este momento.</p>';
    }
    modalEl = document.createElement("div");
    modalEl.className = "rp-modal-overlay";
    modalEl.setAttribute("role", "alertdialog");
    modalEl.setAttribute("aria-modal", "true");
    modalEl.setAttribute("aria-labelledby", "rp-modal-title");
    modalEl.innerHTML =
      '<div class="rp-modal" role="document">' +
      '<div class="rp-modal-head"><span class="rp-modal-icon" aria-hidden="true">⚠</span>' +
      '<div><h3 id="rp-modal-title">No se pudo imprimir</h3>' +
      '<div class="rp-muted">' + esc(job.titulo || "") + (job.preset ? " · " + esc(job.preset) : "") + "</div></div></div>" +
      '<div class="rp-modal-body"><p class="rp-msg">' + esc(job.message || "Error desconocido.") + "</p>" + altHtml + "</div>" +
      '<div class="rp-modal-foot">' +
      (cfg.canManage && cfg.configUrl ? '<a class="rp-link" href="' + esc(cfg.configUrl) + '">Configurar impresión</a>' : "<span></span>") +
      '<div class="rp-foot-actions">' +
      '<button type="button" class="rp-btn" data-rp-close>Cerrar</button>' +
      (ctx.fallbackDialog ? '<button type="button" class="rp-btn" data-rp-dialog>Imprimir normal</button>' : "") +
      (canRetry ? '<button type="button" class="rp-btn rp-btn-strong" data-rp-retry>Reintentar</button>' : "") +
      "</div></div></div>";
    document.body.appendChild(modalEl);
    document.addEventListener("keydown", onModalKey);

    modalEl.addEventListener("click", function (e) {
      if (e.target === modalEl || e.target.closest("[data-rp-close]")) {
        closeEmergency();
        return;
      }
      if (e.target.closest("[data-rp-dialog]")) {
        closeEmergency();
        ctx.fallbackDialog();
        return;
      }
      if (e.target.closest("[data-rp-retry]")) {
        closeEmergency();
        printDte(ctx.dteId, { presetId: job.preset_id || 0, origen: "reintento", fallbackDialog: ctx.fallbackDialog });
        return;
      }
      var alt = e.target.closest("[data-rp-alt]");
      if (alt) {
        closeEmergency();
        printDte(ctx.dteId, {
          presetId: Number(alt.getAttribute("data-rp-alt")) || 0,
          origen: "alternativa",
          fallbackDialog: ctx.fallbackDialog,
        });
      }
    });
    var first = modalEl.querySelector("[data-rp-retry]") || modalEl.querySelector("[data-rp-close]");
    if (first) first.focus();
  }

  // ── Seguimiento ──

  function finish(job, ctx) {
    if (job.ok) {
      toast("ok", job.titulo || "Impreso", progressText(job));
    } else {
      showEmergency(job, ctx);
    }
    if (typeof ctx.onDone === "function") ctx.onDone(job);
    return job;
  }

  function track(jobId, ctx) {
    ctx = ctx || {};
    jobId = Number(jobId) || 0;
    if (!jobId) return Promise.resolve(null);
    if (tracking[jobId]) return tracking[jobId];
    var started = Date.now();
    var promise = new Promise(function (resolve) {
      function tick() {
        post(action("job", "riverso_print_job"), { job_id: jobId })
          .then(function (data) {
            var job = data.job;
            if (job.final) {
              delete tracking[jobId];
              resolve(finish(job, ctx));
              return;
            }
            toast("busy", job.titulo || "Imprimiendo", progressText(job));
            if (Date.now() - started > TRACK_LIMIT_MS) {
              delete tracking[jobId];
              toast("error", job.titulo || "Impresión", "Sin confirmación del hub. Revisa la impresora antes de reintentar.");
              resolve(job);
              return;
            }
            setTimeout(tick, POLL_MS);
          })
          .catch(function () {
            // Corte de red momentáneo: se sigue intentando hasta el límite.
            if (Date.now() - started > TRACK_LIMIT_MS) {
              delete tracking[jobId];
              toast("error", "Impresión", "No se pudo consultar el estado del trabajo.");
              resolve(null);
              return;
            }
            setTimeout(tick, POLL_MS * 2);
          });
      }
      tick();
    });
    tracking[jobId] = promise;
    return promise;
  }

  function handle(job, ctx) {
    if (job.final) return Promise.resolve(finish(job, ctx));
    toast("busy", job.titulo || "Imprimiendo", progressText(job));
    return track(job.id, ctx);
  }

  /**
   * @param {number} dteId
   * @param {{presetId?: number, origen?: string, fallbackDialog?: Function, onDone?: Function}} opts
   */
  function printDte(dteId, opts) {
    opts = opts || {};
    var ctx = { dteId: dteId, fallbackDialog: opts.fallbackDialog, onDone: opts.onDone };
    toast("busy", "Imprimir Ya!", "Enviando a la impresora…");
    return post(action("quick", "riverso_print_quick"), {
      dte_id: dteId,
      station_id: stationId(),
      preset_id: opts.presetId || 0,
      origen: opts.origen || "boton",
    })
      .then(function (data) {
        return handle(data.job, ctx);
      })
      .catch(function (err) {
        hideToast();
        showEmergency({ titulo: "Imprimir Ya!", message: err.message || "No se pudo enviar el trabajo.", alternatives: [] }, {
          fallbackDialog: ctx.fallbackDialog,
        });
        return null;
      });
  }

  /** Qué preset usará "Imprimir Ya!" para un tipo de documento en esta estación. */
  function describe(documentTypeId) {
    return post(action("overview", "riverso_print_overview"), {
      document_type_id: documentTypeId,
      station_id: stationId(),
    });
  }

  window.RiversoPrint = {
    config: cfg,
    post: post,
    printDte: printDte,
    track: track,
    describe: describe,
    station: station,
    setStation: setStation,
    toast: toast,
    showEmergency: showEmergency,
  };
})();
