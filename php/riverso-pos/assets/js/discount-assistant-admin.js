/**
 * Centro de Precios — pestañas del Asistente de Descuento: ajustes, competidores e historial.
 * price-history.js avisa el cambio de pestaña con el evento «riverso:ph-tab».
 */
(function ($) {
    "use strict";

    var cfg = window.riversoDiscountAssistantAdmin || {};
    var actions = cfg.actions || {};
    var loaded = { descuentos: false, descuentos_historial: false };
    var historyPage = 1;

    var ACTITUDES = [
        ["competitivo", "Competitivo"],
        ["codicioso", "Codicioso"],
        ["sin_clasificar", "Sin clasificar"]
    ];
    var EVENTOS = { aceptada: "Cotización aceptada", emitida: "Emitido" };

    function esc(value) {
        return $("<div>").text(value == null ? "" : String(value)).html();
    }

    function money(n) {
        return new Intl.NumberFormat("es-CL", { style: "currency", currency: "CLP", maximumFractionDigits: 0 })
            .format(Math.round(Number(n) || 0));
    }

    function num(n, decimals) {
        return new Intl.NumberFormat("es-CL", { maximumFractionDigits: decimals == null ? 2 : decimals })
            .format(Number(n) || 0);
    }

    function post(action, data) {
        var d = $.Deferred();
        $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data || {})).done(function (res) {
            if (res && res.success) {
                d.resolve(res.data || {});
            } else {
                d.reject((res && res.data && res.data.message) || "No se pudo completar la acción.");
            }
        }).fail(function () {
            d.reject("Error de red.");
        });
        return d.promise();
    }

    /* ===== Ajustes ===== */

    function paintMinPct() {
        var $f = $("#rda-settings-form");
        var a = parseFloat($f.find("[name=min_ratio_num]").val());
        var b = parseFloat($f.find("[name=min_ratio_den]").val());
        var text = a > 0 && b > a
            ? "Descuento mínimo resultante: " + num((1 - a / b) * 100, 4) + " % sobre el precio de la línea."
            : "A debe ser menor que B.";
        $("#rda-min-pct").text(text);
    }

    function bindSettings() {
        var $form = $("#rda-settings-form");
        $form.on("input", "[name=min_ratio_num], [name=min_ratio_den]", paintMinPct);
        $form.on("submit", function (event) {
            event.preventDefault();
            var data = {};
            $form.serializeArray().forEach(function (f) { data[f.name] = f.value; });
            $("#rda-settings-msg").text("Guardando…");
            post(actions.settingsSave, data).done(function (res) {
                $("#rda-settings-msg").text(res.message || "Guardado.");
            }).fail(function (msg) {
                $("#rda-settings-msg").text(msg);
            });
        });
        paintMinPct();
    }

    /* ===== Competidores ===== */

    function actitudSelect(value, disabled) {
        var html = '<select class="rda-actitud"' + (disabled ? " disabled" : "") + ' aria-label="Actitud">';
        ACTITUDES.forEach(function (a) {
            html += '<option value="' + a[0] + '"' + (a[0] === value ? " selected" : "") + ">" + a[1] + "</option>";
        });
        return html + "</select>";
    }

    function loadDomains() {
        var $body = $("#rda-domains-body");
        post(actions.domains, {}).done(function (data) {
            var rows = data.dominios || [];
            var canManage = !!data.canManageCompetencia;
            if (!rows.length) {
                $body.html('<tr><td colspan="4" class="description">Sin competidores registrados.</td></tr>');
                return;
            }
            $body.html(rows.map(function (r) {
                return '<tr data-dominio="' + esc(r.dominio) + '">' +
                    "<td>" + esc(r.nombre) + "</td>" +
                    "<td><code>" + esc(r.dominio) + "</code></td>" +
                    "<td>" + esc(r.vinculos) + "</td>" +
                    "<td>" + actitudSelect(r.actitud, !canManage) + "</td>" +
                    "</tr>";
            }).join(""));
            $("#rda-domain-add").toggle(canManage);
        }).fail(function (msg) {
            $body.html('<tr><td colspan="4" class="description">' + esc(msg) + "</td></tr>");
        });
    }

    function bindDomains() {
        $("#rda-domains-body").on("change", ".rda-actitud", function () {
            var dominio = $(this).closest("tr").data("dominio");
            $("#rda-domains-msg").text("Guardando…");
            post(actions.setDomainAttitude, { dominio: dominio, actitud: $(this).val() }).done(function () {
                $("#rda-domains-msg").text("Actitud de " + dominio + " guardada.");
            }).fail(function (msg) {
                $("#rda-domains-msg").text(msg);
                loadDomains();
            });
        });
        $("#rda-domain-add").on("submit", function (event) {
            event.preventDefault();
            var $f = $(this);
            post(actions.setDomainAttitude, {
                dominio: $f.find("[name=dominio]").val(),
                nombre: $f.find("[name=nombre]").val(),
                actitud: $f.find("[name=actitud]").val()
            }).done(function (res) {
                $("#rda-domains-msg").text("Competidor " + res.dominio + " guardado.");
                $f[0].reset();
                loadDomains();
            }).fail(function (msg) {
                $("#rda-domains-msg").text(msg);
            });
        });
    }

    /* ===== Historial ===== */

    function loadHistory(page) {
        historyPage = page || 1;
        var $body = $("#rda-history-body");
        $body.html('<tr><td colspan="11" class="description">Cargando…</td></tr>');
        post(actions.history, {
            page: historyPage,
            search: $("#rda-history-search").val() || "",
            origen: $("#rda-history-origen").val() || "",
            evento: $("#rda-history-evento").val() || ""
        }).done(function (data) {
            var rows = data.rows || [];
            if (!rows.length) {
                $body.html('<tr><td colspan="11" class="description">Sin descuentos registrados. Se guardan al aceptar una cotización o emitir un documento.</td></tr>');
            } else {
                $body.html(rows.map(function (r) {
                    return "<tr>" +
                        "<td>" + esc(r.fecha) + "</td>" +
                        "<td>" + esc(r.documento) + '<br><span class="description">' + esc(EVENTOS[r.evento] || r.evento) + "</span></td>" +
                        "<td>" + esc(r.cliente || "—") + "</td>" +
                        "<td><code>" + esc(r.sku) + "</code> " + esc(r.descripcion) + "</td>" +
                        "<td>" + num(r.unidades, 0) + "</td>" +
                        "<td>" + money(r.bruto) + "</td>" +
                        "<td>−" + money(r.descuento_monto) + " (" + num(r.descuento_pct, 2) + " %)</td>" +
                        "<td>" + money(r.total_final) + "</td>" +
                        "<td>" + (r.precio_final_unitario !== null ? num(r.precio_final_unitario, 4) : "—") + "</td>" +
                        "<td>" + (r.factor_final !== null ? num(r.factor_final, 3) : "—") + "</td>" +
                        "<td>" + esc(r.origen_label) + "</td>" +
                        "</tr>";
                }).join(""));
            }
            var pages = Math.max(1, Math.ceil((data.total || 0) / (data.per_page || 50)));
            var $p = $("#rda-history-pages").empty();
            $p.append('<span class="displaying-num">' + num(data.total || 0, 0) + " registros</span> ");
            if (pages > 1) {
                $('<button type="button" class="button">‹</button>').prop("disabled", historyPage <= 1)
                    .on("click", function () { loadHistory(historyPage - 1); }).appendTo($p);
                $p.append(" " + historyPage + " / " + pages + " ");
                $('<button type="button" class="button">›</button>').prop("disabled", historyPage >= pages)
                    .on("click", function () { loadHistory(historyPage + 1); }).appendTo($p);
            }
        }).fail(function (msg) {
            $body.html('<tr><td colspan="11" class="description">' + esc(msg) + "</td></tr>");
        });
    }

    function bindHistory() {
        $("#rda-history-go").on("click", function () { loadHistory(1); });
        $("#rda-history-search").on("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                loadHistory(1);
            }
        });
        $("#rda-history-origen, #rda-history-evento").on("change", function () { loadHistory(1); });
    }

    function onTab(tab) {
        if (tab === "descuentos" && !loaded.descuentos) {
            loaded.descuentos = true;
            loadDomains();
        }
        if (tab === "descuentos_historial" && !loaded.descuentos_historial) {
            loaded.descuentos_historial = true;
            loadHistory(1);
        }
    }

    $(function () {
        if (!$("#rda-settings").length && !$("#rda-history").length) return;
        bindSettings();
        bindDomains();
        bindHistory();
        $(document).on("riverso:ph-tab", function (event, tab) { onTab(tab); });
        // Carga directa con ?tab=descuentos (price-history.js puede haber cambiado de pestaña antes).
        onTab($(".riverso-price-history-app .nav-tab-active").data("tab"));
    });
})(jQuery);
