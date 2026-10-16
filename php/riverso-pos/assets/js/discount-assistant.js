/**
 * Asistente de Descuento del módulo Descuentos (Editar línea en cotizaciones y
 * borradores de boleta/factura).
 *
 * Montos de la línea en bruto. C = costo unitario, U = unidades facturables, G = bruto
 * antes del descuento.
 * - Descuento máximo (piso): C × U × 1,6.
 * - Descuento mínimo: G × 1,6 / 1,8 (11,11 %). Si el piso no queda bajo él: sin descuento.
 * - Deseado: C × U × 1,7.
 * - Objetivo según competitivos: deseado si queda entre ellos; su promedio si todos están
 *   bajo el deseado; justo bajo el más barato (múltiplo de 50) si todos están sobre.
 * - Techo: el menor entre el descuento mínimo y el múltiplo de 50 bajo el codicioso más barato.
 * - El piso gana siempre. Se redondea a 50 hacia abajo (hacia arriba al tocar el piso).
 * Cuentan solo vínculos Exacto / Exacto diferente U de envase con precio vigente (el
 * servidor marca `cuenta`); sin codiciosos ni competitivos no hay sugerencia.
 */
(function (global) {
    "use strict";

    var LD = global.RiversoLineDiscounts;

    var DEFAULTS = {
        volume_units: 2000,
        volume_amount: 75000,
        floor_factor: 1.6,
        desired_factor: 1.7,
        min_ratio_num: 1.6,
        min_ratio_den: 1.8,
        round_step: 50,
        stale_days: 90
    };

    var ACTITUDES = [
        ["sin_clasificar", "Sin clasificar"],
        ["competitivo", "Competitivo"],
        ["codicioso", "Codicioso"]
    ];

    var TIPOS = [
        ["exacto", "Exacto"],
        ["exacto_envase", "Exacto diferente U de envase"],
        ["similar", "Similar"],
        ["otro", "Otro"]
    ];

    function round2(n) {
        return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
    }

    function settingsOf(raw) {
        var s = {};
        Object.keys(DEFAULTS).forEach(function (key) {
            var v = raw ? Number(raw[key]) : NaN;
            s[key] = isFinite(v) && v > 0 ? v : DEFAULTS[key];
        });
        if (raw && Number(raw.volume_units) === 0) s.volume_units = 0;
        if (raw && Number(raw.volume_amount) === 0) s.volume_amount = 0;
        if (!(s.min_ratio_num < s.min_ratio_den)) {
            s.min_ratio_num = DEFAULTS.min_ratio_num;
            s.min_ratio_den = DEFAULTS.min_ratio_den;
        }
        s.round_step = Math.max(1, Math.round(s.round_step));
        return s;
    }

    /** Mayor múltiplo de step estrictamente bajo x. */
    function belowStep(x, step) {
        return Math.ceil(x / step - 1e-9) * step - step;
    }

    function floorStep(x, step) {
        return Math.floor(x / step + 1e-9) * step;
    }

    function ceilStep(x, step) {
        return Math.ceil(x / step - 1e-9) * step;
    }

    function minDiscountPct(s) {
        return (1 - s.min_ratio_num / s.min_ratio_den) * 100;
    }

    /**
     * Reglas del asistente (sin DOM).
     *
     * input: { gross, units, cost, finalTotal, competitors: [{ precio_unitario, actitud,
     *          cuenta, competidor, ... }] }
     * status: empty | no_cost | no_discount | no_info | ok
     */
    function compute(input, rawSettings) {
        var s = settingsOf(rawSettings);
        var G = round2(input.gross);
        var U = Number(input.units) || 0;
        var C = input.cost === null || input.cost === undefined ? null : Number(input.cost);
        var out = {
            status: "",
            settings: s,
            gross: G,
            units: U,
            cost: C,
            markers: [],
            suggestion: null,
            reasons: [],
            warnings: [],
            counted: [],
            reference: []
        };
        out.volume = {
            units: U,
            amount: G,
            unitsOk: U > s.volume_units,
            amountOk: G > s.volume_amount
        };
        out.volume.ok = out.volume.unitsOk && out.volume.amountOk;
        if (!(G > 0) || !(U > 0)) {
            out.status = "empty";
            return out;
        }
        if (C === null || !(C > 0)) {
            out.status = "no_cost";
            return out;
        }

        var base = C * U;
        var step = s.round_step;
        var floor = round2(base * s.floor_factor);
        var ceilMin = round2(G * s.min_ratio_num / s.min_ratio_den);
        var desired = round2(base * s.desired_factor);
        out.base = base;
        out.floor = floor;
        out.ceilMin = ceilMin;
        out.desired = desired;
        out.factorBefore = G / base;
        out.hasInterval = floor < ceilMin;

        function marker(key, label, value, extra) {
            var m = {
                key: key,
                label: label,
                value: round2(value),
                pct: G > 0 ? (1 - value / G) * 100 : 0,
                factor: value / base,
                suggested: false
            };
            if (extra) Object.keys(extra).forEach(function (k) { m[k] = extra[k]; });
            out.markers.push(m);
            return m;
        }

        marker("zero", "Sin descuento (0 %)", G);
        marker("min", "Descuento mínimo (" + LD.formatNumber(minDiscountPct(s), 2) + " %)", ceilMin);
        marker("max", "Descuento máximo · factor " + LD.formatNumber(s.floor_factor, 2)
            + " (recargo " + LD.formatNumber((s.floor_factor - 1) * 100, 0) + " %)", floor);
        marker("desired", "Deseado · factor " + LD.formatNumber(s.desired_factor, 2), desired);
        var finalTotal = Number(input.finalTotal);
        if (isFinite(finalTotal) && finalTotal > 0 && Math.abs(finalTotal - G) >= 0.005) {
            marker("current", "Precio actual de la línea", finalTotal);
        }

        (input.competitors || []).forEach(function (c) {
            var unit = Number(c.precio_unitario);
            var item = { c: c, total: unit > 0 ? round2(unit * U) : null };
            var classified = c.actitud === "codicioso" || c.actitud === "competitivo";
            if (c.cuenta && item.total !== null && classified) out.counted.push(item);
            else out.reference.push(item);
        });
        var byTotal = function (a, b) { return a.total - b.total; };
        var greedy = out.counted.filter(function (i) { return i.c.actitud === "codicioso"; }).sort(byTotal);
        var comp = out.counted.filter(function (i) { return i.c.actitud === "competitivo"; }).sort(byTotal);
        if (greedy.length) {
            marker("greedy", "Codicioso más barato · " + (greedy[0].c.competidor || ""), greedy[0].total);
        }
        if (comp.length) {
            marker("comp_min", (comp.length > 1 ? "Competitivo más barato · " : "Competitivo · ")
                + (comp[0].c.competidor || ""), comp[0].total);
        }
        if (comp.length > 1) {
            var last = comp[comp.length - 1];
            marker("comp_max", "Competitivo más caro · " + (last.c.competidor || ""), last.total);
        }

        if (!out.hasInterval) {
            out.status = "no_discount";
            return out;
        }
        if (!greedy.length && !comp.length) {
            out.status = "no_info";
            return out;
        }

        // 1) Objetivo según la competencia competitiva.
        var target = desired;
        var source = "desired";
        if (comp.length) {
            var mMin = comp[0].total;
            var mMax = comp[comp.length - 1].total;
            if (desired >= mMin && desired <= mMax) {
                out.reasons.push("El deseado queda entre los competitivos: se usa el deseado.");
            } else if (mMax < desired) {
                var sum = 0;
                comp.forEach(function (i) { sum += i.total; });
                target = sum / comp.length;
                source = "comp_avg";
                out.reasons.push("Todos los competitivos están bajo el deseado: se usa su promedio.");
            } else {
                target = belowStep(mMin, step);
                source = "comp_min";
                out.reasons.push("Todos los competitivos están sobre el deseado: se sube hasta justo bajo el más barato.");
            }
        } else {
            out.reasons.push("Sin competitivos de referencia: se parte del deseado.");
        }

        // 2) Techo: descuento mínimo y codicioso más barato.
        var ceil = ceilMin;
        var ceilSource = "min";
        if (greedy.length) {
            var kCap = belowStep(greedy[0].total, step);
            if (kCap < ceil) {
                ceil = kCap;
                ceilSource = "greedy";
            }
        }
        if (target > ceil) {
            target = ceil;
            source = ceilSource;
            out.reasons.push(ceilSource === "greedy"
                ? "Se baja para quedar bajo el codicioso más barato."
                : "Se limita al descuento mínimo.");
        } else if (greedy.length) {
            out.reasons.push("Queda bajo el codicioso más barato.");
        }

        // 3) Piso (factor 1,6) y redondeo.
        var value = floorStep(target, step);
        if (value < floor) {
            var up = ceilStep(floor, step);
            value = up <= ceilMin ? up : floor;
            source = "max";
            out.reasons.push("Se respeta el descuento máximo (factor " + LD.formatNumber(s.floor_factor, 2) + ").");
            if (ceilSource === "greedy" && ceil < floor) {
                out.warnings.push("El codicioso más barato está bajo el factor "
                    + LD.formatNumber(s.floor_factor, 2) + ": no se puede quedar bajo él.");
            }
        }
        value = round2(value);

        var suggestion = {
            value: value,
            pct: (1 - value / G) * 100,
            factor: value / base,
            source: source,
            markerKey: null
        };
        var same = null;
        out.markers.forEach(function (m) {
            if (m.key === source && Math.abs(m.value - value) <= step + 0.005) same = m;
        });
        if (same) {
            same.suggested = true;
            suggestion.markerKey = same.key;
        } else {
            var added = marker("suggested", "Sugerido", value);
            added.suggested = true;
            suggestion.markerKey = "suggested";
        }
        out.suggestion = suggestion;
        out.status = "ok";
        return out;
    }

    /* ===== DOM ===== */

    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === "text") el.textContent = v;
                else if (k === "className") el.className = v;
                else if (k === "hidden") el.hidden = !!v;
                else if (k.indexOf("on") === 0 && typeof v === "function") el.addEventListener(k.slice(2), v);
                else el.setAttribute(k, v === true ? "" : String(v));
            });
        }
        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) return;
            el.appendChild(typeof child === "string" ? document.createTextNode(child) : child);
        });
        return el;
    }

    function selectOf(options, value, attrs) {
        var sel = h("select", attrs);
        options.forEach(function (o) {
            var opt = h("option", { value: o[0], text: o[1] });
            if (o[0] === value) opt.selected = true;
            sel.appendChild(opt);
        });
        return sel;
    }

    var SHORT = {
        zero: "0 %",
        min: "Mín",
        max: "Máx",
        desired: "Deseado",
        current: "Actual",
        greedy: "Codicioso",
        comp_min: "Comp.",
        comp_max: "Comp. ↑",
        suggested: "Sugerido"
    };

    /**
     * config.module: módulo Descuentos (RiversoLineDiscounts.createModule).
     * config.details / flag / container: <details> Descuentos, aviso del resumen y caja.
     * config.cfg: { ajaxUrl, nonce, actions, settings, canManageCompetencia }.
     * config.toView: bruto → vista Neto/Bruto del modal.
     */
    function create(config) {
        var module = config.module;
        var cfg = config.cfg || {};
        var actions = cfg.actions || {};
        var toView = config.toView || function (v) { return v; };
        var st = {
            settings: settingsOf(cfg.settings),
            pb: 0,
            context: null,
            loading: false,
            error: "",
            seq: 0,
            snap: null,
            result: null
        };
        var root = config.container;
        if (!module || !root) return { open: function () {} };

        function money(v) {
            return LD.formatMoney(round2(toView(v)));
        }

        function pct(v) {
            return LD.formatNumber(v, 2) + " %";
        }

        function factor(v) {
            return v === null || v === undefined || !isFinite(v) ? "—" : LD.formatNumber(v, 3);
        }

        function post(action, data) {
            var body = new FormData();
            body.append("action", action);
            body.append("nonce", cfg.nonce || "");
            Object.keys(data || {}).forEach(function (k) { body.append(k, data[k] == null ? "" : String(data[k])); });
            return fetch(cfg.ajaxUrl, { method: "POST", body: body, credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || "No se pudo completar la acción.");
                    }
                    return json.data || {};
                });
        }

        function loadContext(force) {
            if (!st.pb || !actions.context) {
                st.context = null;
                render();
                return;
            }
            if (st.context && !force) return;
            var mySeq = ++st.seq;
            var pb = st.pb;
            st.loading = true;
            st.error = "";
            render();
            post(actions.context, { producto_base_id: pb }).then(function (data) {
                if (mySeq !== st.seq) return;
                st.loading = false;
                st.context = data;
                if (data.settings) st.settings = settingsOf(data.settings);
                render();
                if (modal.isOpen()) modal.render();
            }).catch(function (error) {
                if (mySeq !== st.seq) return;
                st.loading = false;
                st.error = error.message;
                render();
            });
        }

        function evaluate() {
            var snap = st.snap;
            if (!snap) return null;
            var f = snap.figures;
            return compute({
                gross: f.gross,
                units: f.units,
                cost: f.cost,
                finalTotal: f.finalTotal,
                competitors: st.context ? st.context.competidores || [] : []
            }, st.settings);
        }

        function apply(m, res) {
            var s = res.settings;
            if (m.suggested && res.suggestion) {
                module.setAnchor("final_total", res.suggestion.value, "sugerido");
                return;
            }
            switch (m.key) {
                case "zero":
                    module.setAnchor(null);
                    break;
                case "max":
                    module.setAnchor("factor", s.floor_factor, "maximo");
                    break;
                case "min":
                    module.setAnchor("price_pct", minDiscountPct(s), "minimo");
                    break;
                case "desired":
                    module.setAnchor("factor", s.desired_factor, "marcador");
                    break;
                default:
                    module.setAnchor("final_total", m.value, "marcador");
            }
        }

        function canApply(m, res) {
            if (m.key === "current") return false;
            if (m.key === "zero") return true;
            return m.value > 0 && m.value < res.gross - 0.005;
        }

        /* --- Aviso de volumen junto a «Descuentos» --- */
        function paintFlag(res) {
            if (!config.flag) return;
            var ok = !!(res && res.volume && res.volume.ok);
            config.flag.hidden = !ok;
            config.flag.textContent = ok ? "Volumen: revisar descuento" : "";
            config.flag.title = ok
                ? "Más de " + LD.formatNumber(res.settings.volume_units, 0) + " u. y más de "
                    + LD.formatMoney(res.settings.volume_amount) + ": aplica el criterio de descuento por volumen."
                : "";
        }

        /* --- Barra --- */
        function renderBar(res) {
            var values = res.markers.map(function (m) { return m.value; });
            var lo = Math.min.apply(null, values);
            var hi = Math.max.apply(null, values);
            lo = Math.max(lo, res.floor * 0.75);
            hi = Math.min(hi, res.gross * 1.25);
            var pad = (hi - lo) * 0.05 || res.gross * 0.05;
            lo -= pad;
            hi += pad;
            function pos(v) {
                var p = (v - lo) / (hi - lo) * 100;
                return Math.max(0, Math.min(100, p));
            }
            var bar = h("div", { className: "cq-da-bar", role: "img",
                "aria-label": "Rango de descuento recomendado y marcadores" });
            bar.appendChild(h("div", { className: "cq-da-track" }));
            var a = pos(Math.min(res.floor, res.ceilMin));
            var b = pos(res.ceilMin);
            var g = pos(res.gross);
            if (res.hasInterval) {
                bar.appendChild(h("div", { className: "cq-da-range", style: "left:" + a + "%;width:" + Math.max(0.5, b - a) + "%",
                    title: "Descuento recomendado: de " + money(res.floor) + " a " + money(res.ceilMin) }));
            }
            bar.appendChild(h("div", { className: "cq-da-ext", style: "left:" + b + "%;width:" + Math.max(0, g - b) + "%",
                title: "Hasta 0 % de descuento" }));

            var sorted = res.markers.slice().sort(function (x, y) { return x.value - y.value; });
            var lastByRow = [];
            sorted.forEach(function (m) {
                var p = pos(m.value);
                var row = 0;
                while (row < 3 && lastByRow[row] !== undefined && p - lastByRow[row] < 12) row++;
                if (row > 2) row = 2;
                lastByRow[row] = p;
                var out = m.value < lo + pad ? " ◂" : (m.value > hi - pad ? " ▸" : "");
                var btn = h("button", {
                    type: "button",
                    className: "cq-da-mk cq-da-mk--" + m.key + (m.suggested ? " is-suggested" : "")
                        + (p < 10 ? " is-start" : (p > 90 ? " is-end" : "")),
                    style: "left:" + p + "%;--row:" + row,
                    title: (m.suggested ? "Sugerido · " : "") + m.label + ": " + money(m.value)
                        + " (−" + pct(m.pct) + ", factor " + factor(m.factor) + ")",
                    disabled: !canApply(m, res),
                    onclick: function () { apply(m, res); }
                }, [
                    h("span", { className: "cq-da-mk-dot" }),
                    h("span", { className: "cq-da-mk-label", text: (m.suggested ? "★ " : "") + SHORT[m.key] + out })
                ]);
                bar.appendChild(btn);
            });
            return bar;
        }

        /* --- Lista de marcadores --- */
        function renderList(res) {
            var list = h("ul", { className: "cq-da-list" });
            res.markers.slice().sort(function (x, y) { return y.value - x.value; }).forEach(function (m) {
                // Sugerido fusionado con su marcador: la fila muestra el monto sugerido.
                var merged = m.suggested && m.key !== "suggested" && res.suggestion;
                var shown = merged ? res.suggestion : m;
                var label = m.label;
                if (merged) {
                    label = Math.abs(m.value - res.suggestion.value) < 0.005
                        ? m.label + " · sugerido"
                        : "Sugerido · " + m.label.charAt(0).toLowerCase() + m.label.slice(1) + " (" + money(m.value) + ")";
                }
                list.appendChild(h("li", { className: "cq-da-row cq-da-row--" + m.key + (m.suggested ? " is-suggested" : "") }, [
                    h("span", { className: "cq-da-swatch" }),
                    h("span", { className: "cq-da-row-label", text: label }),
                    h("span", { className: "cq-da-row-num", text: money(shown.value) }),
                    h("span", { className: "cq-da-row-num", text: m.key === "zero" ? "0 %" : "−" + pct(shown.pct) }),
                    h("span", { className: "cq-da-row-num", text: "×" + factor(shown.factor) }),
                    canApply(m, res)
                        ? h("button", { type: "button", className: "cq-btn cq-btn-small", text: m.key === "zero" ? "Quitar" : "Aplicar",
                            onclick: function () { apply(m, res); } })
                        : h("span")
                ]));
            });
            return list;
        }

        function renderHistory() {
            var rows = st.context && st.context.historial ? st.context.historial : [];
            if (!rows.length) return null;
            var hasCost = st.snap && st.snap.figures.cost !== null && st.snap.figures.cost > 0;
            var box = h("details", { className: "cq-da-history" }, [
                h("summary", { text: "Descuentos anteriores de este producto (" + rows.length + ")" })
            ]);
            var list = h("ul", { className: "cq-da-hist-list" });
            rows.forEach(function (r) {
                var info = r.fecha + " · " + r.documento + (r.cliente ? " · " + r.cliente : "")
                    + " · " + LD.formatNumber(r.unidades, 0) + " u. · −" + pct(r.descuento_pct)
                    + " · factor " + factor(r.factor_final)
                    + (r.precio_final_unitario !== null ? " · " + LD.formatUnitPrice(toView(r.precio_final_unitario)) + "/u" : "")
                    + " · " + r.origen_label;
                list.appendChild(h("li", { className: "cq-da-hist-row" }, [
                    h("span", { className: "cq-da-hist-info", text: info }),
                    h("span", { className: "cq-da-hist-actions" }, [
                        h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Mismo %",
                            title: "Aplica −" + pct(r.descuento_pct) + " sobre el precio",
                            onclick: function () { module.setAnchor("price_pct", r.descuento_pct, "historial"); } }),
                        h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Mismo factor",
                            title: "Precio final = costo × " + factor(r.factor_final),
                            disabled: !(hasCost && r.factor_final > 0),
                            onclick: function () { module.setAnchor("factor", r.factor_final, "historial"); } }),
                        h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Mismo precio unit.",
                            disabled: !(r.precio_final_unitario > 0),
                            onclick: function () { module.setAnchor("final_unit", r.precio_final_unitario, "historial"); } })
                    ])
                ]));
            });
            box.appendChild(list);
            return box;
        }

        function statusText(res) {
            switch (res.status) {
                case "no_cost":
                    return "Sin costo guardado: el asistente necesita el costo unitario.";
                case "no_discount":
                    return "Sin descuento disponible: el factor actual (" + factor(res.factorBefore)
                        + ") no deja espacio entre el descuento mínimo y el factor " + LD.formatNumber(res.settings.floor_factor, 2) + ".";
                case "no_info":
                    return "Falta información: no hay competidores codiciosos ni competitivos vigentes. Se muestran los extremos y el deseado como referencia.";
                case "ok":
                    var applied = st.snap && Math.abs(Number(st.snap.figures.finalTotal) - res.suggestion.value) < 0.5;
                    return "Sugerido: " + money(res.suggestion.value) + " (−" + pct(res.suggestion.pct)
                        + ", factor " + factor(res.suggestion.factor) + ")" + (applied ? " · aplicado." : ".");
                default:
                    return "";
            }
        }

        function render() {
            if (!st.snap) return;
            var res = evaluate();
            st.result = res;
            paintFlag(res);
            if (config.details && !config.details.open) return;
            root.innerHTML = "";
            if (!res || res.status === "empty") return;

            var head = h("div", { className: "cq-da-head" }, [
                h("strong", { className: "cq-da-title", text: "Asistente de descuento" }),
                st.pb ? h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Competencia",
                    onclick: function () { modal.open(); } }) : null
            ]);
            root.appendChild(head);

            var vol = res.volume;
            root.appendChild(h("p", { className: "cq-da-volume" + (vol.ok ? " is-on" : ""),
                text: (vol.ok ? "Cumple el criterio de volumen: " : "No cumple el criterio de volumen: ")
                    + LD.formatNumber(vol.units, 0) + " u. " + (vol.unitsOk ? ">" : "≤") + " "
                    + LD.formatNumber(res.settings.volume_units, 0) + " y " + LD.formatMoney(vol.amount) + " "
                    + (vol.amountOk ? ">" : "≤") + " " + LD.formatMoney(res.settings.volume_amount) + "." }));

            var status = h("p", { className: "cq-da-status cq-da-status--" + res.status, text: statusText(res) });
            root.appendChild(status);
            if (res.status === "no_cost") return;

            if (st.loading) root.appendChild(h("p", { className: "cq-modal-hint", text: "Cargando competencia…" }));
            if (st.error) root.appendChild(h("p", { className: "cq-modal-hint is-warn", text: st.error }));
            if (!st.pb) {
                root.appendChild(h("p", { className: "cq-modal-hint",
                    text: "Línea sin producto local: no hay competencia ni historial." }));
            }

            root.appendChild(renderBar(res));
            if (res.reasons.length && res.status === "ok") {
                root.appendChild(h("p", { className: "cq-modal-hint cq-da-reasons", text: res.reasons.join(" ") }));
            }
            res.warnings.forEach(function (w) {
                root.appendChild(h("p", { className: "cq-modal-hint is-warn", text: w }));
            });
            root.appendChild(renderList(res));

            if (st.context) {
                var refs = res.reference.length;
                root.appendChild(h("p", { className: "cq-modal-hint", text: "Competencia: " + res.counted.length
                    + (res.counted.length === 1 ? " precio considerado" : " precios considerados")
                    + (refs ? " · " + refs + " solo de referencia (similar, sin clasificar, sin precio o con más de "
                        + res.settings.stale_days + " días)" : "") + "." }));
            }
            var hist = renderHistory();
            if (hist) root.appendChild(hist);
        }

        /* --- Modal Competencia --- */
        var modal = (function () {
            var el = null;
            var body = null;
            var msg = null;

            function setMsg(text, warn) {
                if (!msg) return;
                msg.textContent = text || "";
                msg.classList.toggle("is-warn", !!warn);
            }

            function close() {
                if (!el) return;
                el.hidden = true;
                el.setAttribute("aria-hidden", "true");
            }

            function build() {
                if (el) return;
                body = h("div", { className: "cq-modal-body cq-da-comp-body" });
                msg = h("p", { className: "cq-modal-hint cq-da-comp-msg", role: "status" });
                el = h("div", { className: "cq-modal cq-da-comp-modal", hidden: true, "aria-hidden": "true" }, [
                    h("div", { className: "cq-modal-backdrop", onclick: close }),
                    h("div", { className: "cq-modal-dialog cq-modal-wide", role: "dialog", "aria-modal": "true",
                        "aria-label": "Competencia" }, [
                        h("header", { className: "cq-modal-head" }, [
                            h("h3", { text: "Competencia" }),
                            h("button", { type: "button", className: "cq-modal-close", "aria-label": "Cerrar", text: "×",
                                onclick: close })
                        ]),
                        body,
                        msg
                    ])
                ]);
                document.body.appendChild(el);
                document.addEventListener("keydown", function (event) {
                    if (event.key === "Escape" && el && !el.hidden) {
                        event.stopPropagation();
                        event.preventDefault();
                        close();
                    }
                }, true);
            }

            function changed(promise) {
                setMsg("Guardando…");
                return promise.then(function () {
                    setMsg("Guardado.");
                    loadContext(true);
                }).catch(function (error) {
                    setMsg(error.message, true);
                });
            }

            function renderTable(list, canManage) {
                if (!list.length) {
                    return h("p", { className: "cq-modal-hint", text: "Este producto no tiene competidores vinculados." });
                }
                var tbody = h("tbody");
                list.forEach(function (c) {
                    var linkSel = selectOf(ACTITUDES, c.actitud_vinculo, { disabled: !canManage,
                        "aria-label": "Actitud de este vínculo" });
                    linkSel.addEventListener("change", function () {
                        changed(post(actions.setLinkAttitude, {
                            producto_competencia_id: c.producto_competencia_id,
                            actitud: linkSel.value
                        }));
                    });
                    var domSel = selectOf(ACTITUDES, c.actitud_dominio, { disabled: !canManage || !c.dominio,
                        "aria-label": "Actitud del competidor" });
                    domSel.addEventListener("change", function () {
                        changed(post(actions.setDomainAttitude, { dominio: c.dominio, actitud: domSel.value }));
                    });
                    var unit = c.precio_unitario !== null
                        ? LD.formatUnitPrice(c.precio_unitario) + (c.cantidad_min > 1 ? " (" + LD.formatMoney(c.precio_total)
                            + " / " + c.cantidad_min + " u.)" : "")
                        : "—";
                    tbody.appendChild(h("tr", { className: c.cuenta ? "" : "is-reference" }, [
                        h("td", { text: c.competidor || c.dominio || "—" }),
                        h("td", {}, [c.url
                            ? h("a", { href: c.url, target: "_blank", rel: "noopener", text: c.nombre || c.url })
                            : (c.nombre || "—")]),
                        h("td", { text: c.tipo_match_label }),
                        h("td", { className: "cq-num", text: unit }),
                        h("td", { text: (c.fecha || "—") + (c.dias !== null ? " (" + c.dias + " d)" : "") }),
                        h("td", {}, [linkSel]),
                        h("td", {}, [domSel]),
                        h("td", { text: c.cuenta ? "Cuenta · " + c.actitud_label : (c.motivo || "Sin clasificar: no cuenta") })
                    ]));
                });
                return h("div", { className: "cq-da-comp-table-wrap" }, [
                    h("table", { className: "cq-da-comp-table" }, [
                        h("thead", {}, [h("tr", {}, ["Competidor", "Producto", "Tipo", "Precio unit. bruto", "Fecha",
                            "Actitud vínculo", "Actitud competidor", "Estado"].map(function (t) {
                            return h("th", { scope: "col", text: t });
                        }))]),
                        tbody
                    ])
                ]);
            }

            function renderForm() {
                var url = h("input", { type: "url", placeholder: "https://…", required: true, "aria-label": "URL del competidor" });
                var price = h("input", { type: "text", inputmode: "decimal", required: true, "aria-label": "Precio total bruto" });
                var units = h("input", { type: "number", min: "1", step: "1", value: "1", "aria-label": "Unidades del precio" });
                var tipo = selectOf(TIPOS, "exacto", { "aria-label": "Tipo de vínculo" });
                var act = selectOf(ACTITUDES, "sin_clasificar", { "aria-label": "Actitud" });
                var form = h("form", { className: "cq-da-comp-add" }, [
                    h("h4", { text: "Agregar competidor" }),
                    h("label", { className: "cq-float-field" }, [h("span", { text: "URL del producto" }), url]),
                    h("label", { className: "cq-float-field" }, [h("span", { text: "Precio total (bruto)" }), price]),
                    h("label", { className: "cq-float-field" }, [h("span", { text: "Unidades de ese precio" }), units]),
                    h("label", { className: "cq-float-field" }, [h("span", { text: "Tipo" }), tipo]),
                    h("label", { className: "cq-float-field" }, [h("span", { text: "Actitud" }), act]),
                    h("button", { type: "submit", className: "cq-btn cq-btn-primary", text: "Agregar" })
                ]);
                form.addEventListener("submit", function (event) {
                    event.preventDefault();
                    var amount = Number(String(price.value || "").replace(/\./g, "").replace(",", "."));
                    if (!(amount > 0)) {
                        setMsg("Ingresa el precio total.", true);
                        return;
                    }
                    changed(post(actions.addCompetitor, {
                        producto_base_id: st.pb,
                        url: url.value,
                        precio_total: amount,
                        unidad: units.value || 1,
                        tipo_match: tipo.value,
                        actitud: act.value
                    })).then(function () {
                        if (!msg.classList.contains("is-warn")) form.reset();
                    });
                });
                return form;
            }

            function render() {
                if (!el) return;
                body.innerHTML = "";
                var line = st.snap ? st.snap.line : {};
                body.appendChild(h("p", { className: "cq-da-comp-product" }, [
                    h("code", { text: line.sku || "" }), " " + (line.description || "")
                ]));
                body.appendChild(h("p", { className: "cq-modal-hint", text: "Cuentan para la sugerencia los vínculos Exacto y "
                    + "Exacto diferente U de envase con precio de hasta " + st.settings.stale_days + " días. "
                    + "La actitud del vínculo manda; si está sin clasificar se usa la del competidor (dominio)." }));
                if (!st.context) {
                    body.appendChild(h("p", { className: "cq-modal-hint", text: st.loading ? "Cargando…" : (st.error || "") }));
                    return;
                }
                var canManage = !!st.context.canManageCompetencia;
                body.appendChild(renderTable(st.context.competidores || [], canManage));
                if (canManage) body.appendChild(renderForm());
                else body.appendChild(h("p", { className: "cq-modal-hint", text: "Solo lectura: editar competencia requiere el permiso «Gestionar catálogo competencia»." }));
            }

            return {
                open: function () {
                    build();
                    setMsg("");
                    el.hidden = false;
                    el.setAttribute("aria-hidden", "false");
                    render();
                    loadContext(false);
                },
                close: close,
                render: render,
                isOpen: function () { return !!(el && !el.hidden); }
            };
        })();

        module.onRefresh(function (snap) {
            st.snap = snap;
            render();
        });
        if (config.details) {
            config.details.addEventListener("toggle", function () {
                if (config.details.open) {
                    render();
                    loadContext(false);
                }
            });
        }

        return {
            /** Al abrir el modal de línea: producto de la línea (recarga competencia si cambió). */
            open: function (line) {
                var pb = line && line.producto_base_id ? Number(line.producto_base_id) || 0 : 0;
                if (pb !== st.pb) {
                    st.pb = pb;
                    st.context = null;
                    st.error = "";
                    st.seq++;
                }
                modal.close();
                root.innerHTML = "";
            }
        };
    }

    global.RiversoDiscountAssistant = {
        compute: compute,
        settingsOf: settingsOf,
        create: create
    };
})(typeof window !== "undefined" ? window : globalThis);
