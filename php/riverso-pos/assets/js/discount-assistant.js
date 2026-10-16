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
        volume_units: 1000,
        volume_amount: 75000,
        volume_amount_alone: 120000,
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
        if (raw && Number(raw.volume_amount_alone) === 0) s.volume_amount_alone = 0;
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
     * «Usar esta vez» / «Dejar de usar ahora» de precios ocultos por el competidor: solo en el
     * navegador, por documento abierto (lo comparten el asistente de la línea y «Aplicar
     * descuentos sugeridos»). docKey: la cotización abierta o un texto fijo (un borrador por página).
     * Valor por producto_competencia_id: true usar, false no usar aunque esté «Usar siempre».
     */
    var hiddenSession = typeof Map === "function" ? new Map() : null;

    function hiddenOverrides(docKey) {
        if (!hiddenSession) return {};
        var key = docKey === undefined || docKey === null ? "" : docKey;
        if (!hiddenSession.has(key)) hiddenSession.set(key, {});
        return hiddenSession.get(key);
    }

    /**
     * Aplica «esta vez» sobre lo que manda el servidor («Usar siempre» = usar_oculto). Cada precio
     * oculto queda con oculto_uso: no | esta_vez | siempre | siempre_no_ahora.
     */
    function withHiddenUse(competitors, overrides) {
        overrides = overrides || {};
        return (competitors || []).map(function (c) {
            if (!c.oculto || c.motivo_oculto_usado === undefined) return c;
            var o = overrides[c.producto_competencia_id];
            var use = o === true || (o !== false && !!c.usar_oculto);
            var copy = {};
            Object.keys(c).forEach(function (k) { copy[k] = c[k]; });
            copy.motivo = use ? c.motivo_oculto_usado : c.motivo_oculto_sin_usar;
            copy.cuenta = copy.motivo === "";
            copy.oculto_uso = c.usar_oculto ? (use ? "siempre" : "siempre_no_ahora") : (use ? "esta_vez" : "no");
            return copy;
        });
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
        // (más de N unidades y más de $X) o más de $Y sin importar unidades (Y = 0 la desactiva).
        out.volume.byUnits = out.volume.unitsOk && out.volume.amountOk;
        out.volume.byAmount = s.volume_amount_alone > 0 && G > s.volume_amount_alone;
        out.volume.ok = out.volume.byUnits || out.volume.byAmount;
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

        /**
         * close: cómo queda el monto que se aplica (cifra cerrada en múltiplos de step):
         * "down" piso, "up" techo (no bajar del factor), "below" justo bajo (codicioso),
         * "exact" ya cerrado, null no se aplica (0 % quita el descuento; el actual es referencia).
         */
        function marker(key, label, value, close) {
            var apply = null;
            if (close === "down") apply = floorStep(value, step);
            else if (close === "up") apply = ceilStep(value, step);
            else if (close === "below") apply = belowStep(value, step);
            else if (close === "exact") apply = value;
            var m = {
                key: key,
                label: label,
                value: round2(value),
                pct: G > 0 ? (1 - value / G) * 100 : 0,
                factor: value / base,
                apply: apply === null ? null : round2(apply),
                applyPct: apply === null ? null : (1 - apply / G) * 100,
                applyFactor: apply === null ? null : apply / base,
                suggested: false
            };
            out.markers.push(m);
            return m;
        }

        marker("zero", "Sin descuento (0 %)", G, null);
        marker("min", "Descuento mínimo (" + LD.formatNumber(minDiscountPct(s), 2) + " %)", ceilMin, "down");
        marker("max", "Descuento máximo · factor " + LD.formatNumber(s.floor_factor, 2)
            + " (recargo " + LD.formatNumber((s.floor_factor - 1) * 100, 0) + " %)", floor, "up");
        marker("desired", "Deseado · factor " + LD.formatNumber(s.desired_factor, 2), desired, "down");
        var finalTotal = Number(input.finalTotal);
        if (isFinite(finalTotal) && finalTotal > 0 && Math.abs(finalTotal - G) >= 0.005) {
            marker("current", "Precio actual de la línea", finalTotal, null);
        }

        (input.competitors || []).forEach(function (c) {
            var unit = Number(c.precio_unitario);
            var item = { c: c, total: unit > 0 ? round2(unit * U) : null };
            var classified = c.actitud === "codicioso" || c.actitud === "competitivo";
            if (c.cuenta && item.total !== null && classified) out.counted.push(item);
            else out.reference.push(item);
        });
        // Un precio por competidor: el más barato por unidad (lo que pagaría el cliente, p. ej. granel).
        // Sus otras presentaciones (caja de 100, vía familia…) quedan como referencia.
        var compKey = function (c) { return String(c.dominio || c.competidor || "").toLowerCase(); };
        var best = {};
        out.counted.forEach(function (i) {
            var k = compKey(i.c);
            if (!best[k] || i.total < best[k].total) best[k] = i;
        });
        out.duplicates = {};
        out.counted = out.counted.filter(function (i) {
            var keep = best[compKey(i.c)];
            if (keep === i) return true;
            i.reason = "Otra presentación de " + (i.c.competidor || i.c.dominio || "este competidor")
                + ": cuenta la más barata (" + LD.formatUnitPrice(keep.c.precio_unitario) + "/u).";
            if (i.c.producto_competencia_id) out.duplicates[i.c.producto_competencia_id] = i.reason;
            out.reference.push(i);
            return false;
        });
        var byTotal = function (a, b) { return a.total - b.total; };
        var greedy = out.counted.filter(function (i) { return i.c.actitud === "codicioso"; }).sort(byTotal);
        var comp = out.counted.filter(function (i) { return i.c.actitud === "competitivo"; }).sort(byTotal);
        if (greedy.length) {
            marker("greedy", "Codicioso más barato · " + (greedy[0].c.competidor || ""), greedy[0].total, "below");
        }
        if (comp.length) {
            marker("comp_min", (comp.length > 1 ? "Competitivo más barato · " : "Competitivo · ")
                + (comp[0].c.competidor || ""), comp[0].total, "down");
        }
        if (comp.length > 1) {
            var last = comp[comp.length - 1];
            marker("comp_max", "Competitivo más caro · " + (last.c.competidor || ""), last.total, "down");
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
            // Cifra cerrada hacia arriba: no baja del factor del piso.
            value = ceilStep(floor, step);
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
            var added = marker("suggested", "Sugerido", value, "exact");
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
     * config.docKey(): documento abierto, para «Usar esta vez» de precios ocultos.
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

        function overrides() {
            return hiddenOverrides(config.docKey ? config.docKey() : null);
        }

        /** Competencia del SKU con «Usar esta vez» del documento aplicado. */
        function competitors() {
            return withHiddenUse(st.context ? st.context.competidores || [] : [], overrides());
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
                competitors: competitors()
            }, st.settings);
        }

        /** Repinta el asistente y, si está abierto, el modal Competencia. */
        function refreshAll() {
            render();
            if (modal.isOpen()) modal.render();
        }

        function apply(m, res) {
            if (m.suggested && res.suggestion) {
                module.setAnchor("final_total", res.suggestion.value, "sugerido");
                return;
            }
            if (m.key === "zero") {
                module.setAnchor(null);
                return;
            }
            // Siempre el monto final cerrado (múltiplo de 50), no la cifra exacta del marcador.
            var origin = m.key === "max" ? "maximo" : (m.key === "min" ? "minimo" : "marcador");
            module.setAnchor("final_total", m.apply, origin);
        }

        function canApply(m, res) {
            if (m.key === "zero") return true;
            return m.apply !== null && m.apply > 0 && m.apply < res.gross - 0.005;
        }

        /* --- Aviso de volumen junto a «Descuentos» --- */
        function paintFlag(res) {
            if (!config.flag) return;
            var ok = !!(res && res.volume && res.volume.ok);
            config.flag.hidden = !ok;
            config.flag.textContent = ok ? "Volumen: revisar descuento" : "";
            config.flag.title = ok ? volumeText(res) : "";
        }

        function volumeText(res) {
            var v = res.volume;
            var s = res.settings;
            var units = LD.formatNumber(v.units, 0) + " u.";
            if (v.byUnits) {
                return "Cumple el criterio de volumen: " + units + " > " + LD.formatNumber(s.volume_units, 0)
                    + " y " + LD.formatMoney(v.amount) + " > " + LD.formatMoney(s.volume_amount) + ".";
            }
            if (v.byAmount) {
                return "Cumple el criterio de volumen: " + LD.formatMoney(v.amount) + " > " + LD.formatMoney(s.volume_amount_alone) + ".";
            }
            return "No cumple el criterio de volumen: pide más de " + LD.formatNumber(s.volume_units, 0) + " u. y más de "
                + LD.formatMoney(s.volume_amount)
                + (s.volume_amount_alone > 0 ? ", o más de " + LD.formatMoney(s.volume_amount_alone) : "")
                + " (la línea tiene " + units + " y " + LD.formatMoney(v.amount) + ").";
        }

        /** Resumen corto del desplegable (visible aunque esté minimizado). */
        function briefText(res) {
            if (st.loading && res.status !== "no_cost") return "Cargando competencia…";
            switch (res.status) {
                case "ok":
                    var applied = st.snap && Math.abs(Number(st.snap.figures.finalTotal) - res.suggestion.value) < 0.5;
                    return "Sugerido " + money(res.suggestion.value) + " (−" + pct(res.suggestion.pct) + ")"
                        + (applied ? " · aplicado" : "");
                case "no_info":
                    return "Falta información";
                case "no_discount":
                    return "Sin descuento disponible";
                case "no_cost":
                    return "Sin costo";
                default:
                    return "";
            }
        }

        /* --- Barra --- */
        function renderBar(res) {
            // Puntito «R» bajo la barra por cada precio de competidor (abre su URL): los que cuentan
            // en verde (competitivo) o rojo (codicioso), también un oculto usado; gris los de referencia.
            var withUrl = function (i) { return i.total !== null && i.total > 0 && i.c.url; };
            var refs = res.counted.filter(withUrl).map(function (i) { return { i: i, active: true }; })
                .concat(res.reference.filter(withUrl).map(function (i) { return { i: i, active: false }; }));
            var values = res.markers.map(function (m) { return m.value; })
                .concat(refs.map(function (r) { return r.i.total; }));
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
                        + " (−" + pct(m.pct) + ", factor " + factor(m.factor) + ")"
                        + (m.suggested && res.suggestion ? " · aplica " + money(res.suggestion.value)
                            : (m.apply !== null && Math.abs(m.apply - m.value) >= 0.005 ? " · aplica " + money(m.apply) : "")),
                    disabled: !canApply(m, res),
                    onclick: function () { apply(m, res); }
                }, [
                    h("span", { className: "cq-da-mk-dot" }),
                    h("span", { className: "cq-da-mk-label", text: (m.suggested ? "★ " : "") + SHORT[m.key] + out })
                ]);
                bar.appendChild(btn);
            });

            var refRows = [];
            refs.slice().sort(function (x, y) { return x.i.total - y.i.total; }).forEach(function (r) {
                var i = r.i;
                var c = i.c;
                var hidden = c.oculto_uso === "esta_vez" ? " (precio oculto, usado esta vez)"
                    : (c.oculto_uso === "siempre" ? " (precio oculto, usado siempre)" : "");
                var p = pos(i.total);
                var row = 0;
                while (row < 3 && refRows[row] !== undefined && p - refRows[row] < 3.5) row++;
                if (row > 2) row = 2;
                refRows[row] = p;
                var edge = i.total < lo + pad ? " ◂" : (i.total > hi - pad ? " ▸" : "");
                bar.appendChild(h("a", {
                    // Color = actitud (cuenta igual); borde = origen del oculto: punteado esta vez, doble siempre.
                    className: "cq-da-ref" + (r.active ? (c.actitud === "codicioso" ? " is-active is-greedy" : " is-active") : "")
                        + (r.active && c.oculto_uso === "esta_vez" ? " is-hidden-once" : "")
                        + (r.active && c.oculto_uso === "siempre" ? " is-hidden-always" : "")
                        + (c.deshabilitado ? " is-disabled" : "")
                        + (c.precio_cambio ? " is-changed" : ""),
                    href: c.url,
                    target: "_blank",
                    rel: "noopener noreferrer",
                    style: "left:" + p + "%;--row:" + row,
                    title: (r.active ? "Cuenta · " + (c.actitud_label || "") + hidden : "Referencia") + " · "
                        + (c.competidor || c.dominio || "") + " · " + (c.tipo_match_label || "")
                        + " · " + LD.formatUnitPrice(c.precio_unitario) + "/u = " + money(i.total)
                        + (c.fecha ? " · " + c.fecha : "") + ((i.reason || c.motivo) ? " · " + (i.reason || c.motivo) : "") + edge
                        + " · Abrir su página",
                    "aria-label": "Abrir página de " + (c.competidor || c.dominio || "competidor")
                        + (r.active ? " (cuenta)" : " (referencia)"),
                    text: "R"
                }));
            });
            if (refRows.length) {
                bar.classList.add("has-refs");
                bar.style.setProperty("--ref-rows", String(refRows.length));
            }
            return bar;
        }

        /* --- Lista de marcadores --- */
        function renderList(res) {
            var list = h("ul", { className: "cq-da-list" });
            res.markers.slice().sort(function (x, y) { return y.value - x.value; }).forEach(function (m) {
                // Sugerido fusionado con su marcador: la fila muestra el monto sugerido.
                var merged = m.suggested && m.key !== "suggested" && res.suggestion;
                // Cada fila muestra lo que se aplica: la cifra cerrada (múltiplo de 50).
                var shown = merged ? res.suggestion
                    : (m.apply !== null ? { value: m.apply, pct: m.applyPct, factor: m.applyFactor } : m);
                var label = m.label;
                var exact = Math.abs(m.value - shown.value) < 0.005;
                if (merged) {
                    label = exact
                        ? m.label + " · sugerido"
                        : "Sugerido · " + m.label.charAt(0).toLowerCase() + m.label.slice(1) + " (" + money(m.value) + ")";
                } else if (!exact) {
                    label = m.label + " (" + money(m.value) + ")";
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

        /** Desplegable «Asistente de descuento» dentro de Descuentos (se arma una vez). */
        var panel = null;

        function ensurePanel() {
            if (panel) return panel;
            var brief = h("span", { className: "cq-da-brief" });
            var body = h("div", { className: "cq-da-body" });
            var details = h("details", { className: "cq-da-details" }, [
                h("summary", { className: "cq-da-summary" }, [
                    h("span", { className: "cq-da-title", text: "Asistente de descuento" }),
                    brief
                ]),
                body
            ]);
            details.addEventListener("toggle", function () {
                if (details.open) {
                    render();
                    loadContext(false);
                }
            });
            root.innerHTML = "";
            root.appendChild(details);
            panel = { details: details, brief: brief, body: body };
            return panel;
        }

        function render() {
            if (!st.snap) return;
            var res = evaluate();
            st.result = res;
            paintFlag(res);
            if (config.details && !config.details.open) return;
            var p = ensurePanel();
            root.hidden = !res || res.status === "empty";
            if (root.hidden) return;
            p.brief.textContent = briefText(res);
            p.brief.className = "cq-da-brief cq-da-brief--" + (st.loading ? "loading" : res.status);
            if (!p.details.open) return;

            var body = p.body;
            body.innerHTML = "";
            var vol = res.volume;
            body.appendChild(h("div", { className: "cq-da-head" }, [
                h("p", { className: "cq-da-volume" + (vol.ok ? " is-on" : ""), text: volumeText(res) }),
                st.pb ? h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Competencia",
                    onclick: function () { modal.open(); } }) : null
            ]));

            body.appendChild(h("p", { className: "cq-da-status cq-da-status--" + res.status, text: statusText(res) }));
            if (res.status === "no_cost") return;

            if (st.loading) body.appendChild(h("p", { className: "cq-modal-hint", text: "Cargando competencia…" }));
            if (st.error) body.appendChild(h("p", { className: "cq-modal-hint is-warn", text: st.error }));
            if (!st.pb) {
                body.appendChild(h("p", { className: "cq-modal-hint",
                    text: "Línea sin producto local: no hay competencia ni historial." }));
            }

            body.appendChild(renderBar(res));
            if (res.reasons.length && res.status === "ok") {
                body.appendChild(h("p", { className: "cq-modal-hint cq-da-reasons", text: res.reasons.join(" ") }));
            }
            res.warnings.forEach(function (w) {
                body.appendChild(h("p", { className: "cq-modal-hint is-warn", text: w }));
            });
            body.appendChild(renderList(res));

            if (st.context) {
                var refs = res.reference.length;
                body.appendChild(h("p", { className: "cq-modal-hint", text: "Competencia: " + res.counted.length
                    + (res.counted.length === 1 ? " precio considerado" : " precios considerados")
                    + (refs ? " · " + refs + " solo de referencia (sugeridos, similares, sin clasificar, otras presentaciones del mismo competidor, deshabilitados, ocultos o con más de "
                        + res.settings.stale_days + " días)" : "") + "." }));
                // Precios ocultos por el competidor que sí se están usando.
                var hidden = res.counted.filter(function (i) { return i.c.oculto; }).map(function (i) {
                    return (i.c.competidor || i.c.dominio || "competidor") + (i.c.oculto_uso === "esta_vez" ? " (esta vez)" : " (siempre)");
                });
                if (hidden.length) {
                    body.appendChild(h("p", { className: "cq-modal-hint is-warn",
                        text: "Incluye precios ocultos en la web del competidor: " + hidden.join(", ") + "." }));
                }
            }
            var hist = renderHistory();
            if (hist) body.appendChild(hist);
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
                            h("div", { className: "cq-da-comp-head-actions" }, [
                                cfg.competenciaUrl
                                    ? h("a", { className: "cq-btn cq-btn-small", href: cfg.competenciaUrl, target: "_blank",
                                        rel: "noopener", text: "Abrir Competencia" })
                                    : null,
                                h("button", { type: "button", className: "cq-modal-close", "aria-label": "Cerrar", text: "×",
                                    onclick: close })
                            ])
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

            var disableOpen = 0;

            /** Uso de la URL: deshabilitada no cuenta para descuentos (precio viejo en la página). */
            function usageCell(c, canManage) {
                var parts = [];
                if (c.deshabilitado_marcado) {
                    var text = "Deshabilitada" + (c.deshabilitado_desde ? " desde " + c.deshabilitado_desde : "")
                        + (c.deshabilitado_hasta ? " hasta " + c.deshabilitado_hasta : "")
                        + (!c.deshabilitado ? " (vencido: vuelve a contar)" : "");
                    parts.push(h("div", { className: "cq-modal-hint", text: text }));
                    if (c.precio_cambio) {
                        parts.push(h("span", { className: "cq-da-badge is-warn", text: "Precio cambió el " + c.precio_cambio + ", revisar" }));
                    }
                    if (canManage) {
                        parts.push(h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Habilitar",
                            onclick: function () {
                                changed(post(actions.setUrlEnabled, { producto_competencia_id: c.producto_competencia_id, habilitado: 1 }));
                            } }));
                    }
                } else {
                    parts.push(h("span", { className: "cq-modal-hint", text: "Habilitada" }));
                    if (canManage) {
                        parts.push(h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Deshabilitar",
                            title: "No usar este precio en los descuentos (por ejemplo, la página tiene un precio viejo)",
                            onclick: function () {
                                disableOpen = disableOpen === c.producto_competencia_id ? 0 : c.producto_competencia_id;
                                render();
                            } }));
                    }
                }
                return h("td", { className: "cq-da-usage" }, parts.concat(hiddenParts(c, canManage)));
            }

            var stopOpen = 0;

            function whoOf(c) {
                return c.competidor || c.dominio || "el competidor";
            }

            function priceOf(c) {
                return c.precio_unitario !== null ? " (" + LD.formatUnitPrice(c.precio_unitario) + "/u)" : "";
            }

            /** «Esta vez»: solo este documento, en el navegador. on: true usar, false no usar, null quitar. */
            function setOnce(c, on) {
                var ov = overrides();
                if (on === null) delete ov[c.producto_competencia_id];
                else ov[c.producto_competencia_id] = on;
                stopOpen = 0;
                setMsg("");
                refreshAll();
            }

            /** «Siempre»: marca guardada en la competencia; quita lo de «esta vez» de este documento. */
            function setAlways(c, on) {
                stopOpen = 0;
                changed(post(actions.setHiddenUse, { producto_competencia_id: c.producto_competencia_id, usar: on ? 1 : 0 })
                    .then(function () { delete overrides()[c.producto_competencia_id]; }));
            }

            function askAlways(c) {
                var who = whoOf(c);
                if (window.confirm("¿Seguro que quieres usar siempre el precio oculto de " + who + priceOf(c) + "?\n\n"
                    + "Contará en los descuentos de todas las cotizaciones y borradores aunque " + who
                    + " lo siga ocultando en su web, hasta que elijas «Dejar de usar para siempre».")) {
                    setAlways(c, true);
                }
            }

            function askForever(c) {
                var who = whoOf(c);
                if (window.confirm("¿Seguro que quieres dejar de usar para siempre el precio oculto de " + who + priceOf(c) + "?\n\n"
                    + "Dejará de contar en los descuentos de todas las cotizaciones y borradores mientras " + who + " lo oculte.")) {
                    setAlways(c, false);
                }
            }

            /**
             * Precio oculto por el competidor (Sande «ocultarPrecio»): no cuenta salvo «Usar esta vez»
             * (este documento) o «Usar siempre» (todos). «Dejar de usar» de un «siempre» pregunta si
             * es solo ahora o para siempre.
             */
            function hiddenParts(c, canManage) {
                if (!c.oculto || !c.oculto_uso) return [];
                var mode = c.oculto_uso;
                var who = whoOf(c);
                var texts = {
                    no: "Oculto en la web de " + who + ": no se usa.",
                    esta_vez: "Oculto en la web: se usa esta vez (este documento).",
                    siempre: "Oculto en la web: se usa siempre" + (c.usar_oculto_desde ? " (desde " + c.usar_oculto_desde + ")" : "") + ".",
                    siempre_no_ahora: "Oculto en la web: «Usar siempre», pero no en este documento."
                };
                // Otro motivo (vínculo sugerido, similar, desactualizado, deshabilitado) le gana al oculto:
                // aunque se use, no cuenta hasta resolverlo.
                var blocked = c.motivo_oculto_usado || "";
                var parts = [
                    h("span", { className: "cq-da-badge is-hidden-price", text: "Precio oculto" }),
                    h("div", { className: "cq-modal-hint", text: texts[mode] || "" }),
                    blocked ? h("span", { className: "cq-da-badge is-warn",
                        text: (mode === "esta_vez" || mode === "siempre" ? "Igual no cuenta: " : "Aunque lo uses, no contará: ") + blocked })
                        : null
                ];
                var btn = function (text, title, onclick, primary) {
                    return h("button", { type: "button", className: "cq-btn cq-btn-small" + (primary ? " cq-btn-primary" : ""),
                        text: text, title: title, onclick: onclick });
                };
                var useOnce = btn("Usar esta vez", "Cuenta solo en el análisis de este documento", function () {
                    var text = mode === "siempre_no_ahora"
                        ? "¿Seguro que quieres volver a usar en este documento el precio oculto de " + who + priceOf(c) + "?"
                        : "¿Seguro que quieres usar esta vez el precio oculto de " + who + priceOf(c) + "?\n\n"
                            + who + " lo oculta en su web, así que puede no ser el precio que ofrece hoy. "
                            + "Contará solo en el análisis de este documento mientras lo tengas abierto."
                            + (blocked ? "\n\nOjo: igual no contará hasta resolver esto: " + blocked : "");
                    if (window.confirm(text)) setOnce(c, mode === "siempre_no_ahora" ? null : true);
                }, true);
                var useAlways = canManage ? btn("Usar siempre", "Cuenta en todas las cotizaciones y borradores", function () {
                    askAlways(c);
                }) : null;
                if (mode === "no") {
                    parts.push(useOnce, useAlways);
                } else if (mode === "esta_vez") {
                    parts.push(btn("Dejar de usar", "Deja de contar en este documento", function () {
                        if (window.confirm("¿Seguro que quieres dejar de usar el precio oculto de " + who + " en este documento?")) {
                            setOnce(c, null);
                        }
                    }), useAlways);
                } else if (mode === "siempre") {
                    parts.push(btn("Dejar de usar", "Solo ahora o para siempre", function () {
                        stopOpen = stopOpen === c.producto_competencia_id ? 0 : c.producto_competencia_id;
                        render();
                    }));
                } else {
                    parts.push(useOnce, canManage ? btn("Dejar de usar para siempre",
                        "Quita «Usar siempre»: deja de contar en todas las cotizaciones y borradores",
                        function () { askForever(c); }) : null);
                }
                if (!canManage && mode !== "esta_vez") {
                    parts.push(h("div", { className: "cq-modal-hint",
                        text: "«Usar siempre» requiere el permiso «Gestionar catálogo competencia»." }));
                }
                return [h("div", { className: "cq-da-hidden" }, parts)];
            }

            /** «Dejar de usar» de un precio oculto con «Usar siempre»: ¿ahora o para siempre? */
            function stopRow(c, canManage) {
                var who = whoOf(c);
                return h("tr", { className: "cq-da-ci-expand" }, [h("td", { colspan: "9" }, [
                    h("div", { className: "cq-da-ci-link" }, [
                        h("p", { className: "cq-modal-hint", text: "¿Dejar de usar el precio oculto de " + who + priceOf(c)
                            + " solo ahora (en este documento) o para siempre (en todas las cotizaciones y borradores)?" }),
                        h("p", { className: "cq-da-comp-actions" }, [
                            h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Dejar de usar ahora",
                                onclick: function () { setOnce(c, false); } }),
                            canManage ? h("button", { type: "button", className: "cq-btn", text: "Dejar de usar para siempre",
                                onclick: function () { askForever(c); } }) : null,
                            h("button", { type: "button", className: "cq-btn", text: "Cancelar",
                                onclick: function () { stopOpen = 0; render(); } })
                        ])
                    ])
                ])]);
            }

            function disableRow(c) {
                var motivo = h("input", { type: "text", value: "Precio desactualizado en la página", maxlength: "255",
                    "aria-label": "Motivo" });
                var hasta = h("input", { type: "date", "aria-label": "Deshabilitar hasta (opcional)" });
                return h("tr", { className: "cq-da-ci-expand" }, [h("td", { colspan: "9" }, [
                    h("div", { className: "cq-da-ci-link" }, [
                        h("p", { className: "cq-modal-hint", text: "No contará para los descuentos. El refresco nocturno (Sande, DIMAFI) sigue leyendo su precio y avisa «Precio cambió, revisar» si cambia." }),
                        h("div", { className: "cq-da-ci-link-fields" }, [
                            h("label", { className: "cq-float-field" }, [h("span", { text: "Motivo" }), motivo]),
                            h("label", { className: "cq-float-field" }, [h("span", { text: "Hasta (opcional)" }), hasta])
                        ]),
                        h("p", { className: "cq-da-comp-actions" }, [
                            h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Deshabilitar",
                                onclick: function () {
                                    disableOpen = 0;
                                    changed(post(actions.setUrlEnabled, {
                                        producto_competencia_id: c.producto_competencia_id,
                                        habilitado: 0,
                                        motivo: motivo.value,
                                        hasta: hasta.value
                                    }));
                                } }),
                            h("button", { type: "button", className: "cq-btn", text: "Cancelar",
                                onclick: function () { disableOpen = 0; render(); } })
                        ])
                    ])
                ])]);
            }

            var confirmOpen = 0;

            /** Confirmar un vínculo sugerido por coincidencias: tipo y actitud. */
            function confirmRow(c) {
                var tipo = selectOf(TIPOS, c.cantidad_min > 1 ? "exacto_envase" : "exacto", { "aria-label": "Tipo de match" });
                var act = selectOf(ACTITUDES, "sin_clasificar", { "aria-label": "Actitud" });
                return h("tr", { className: "cq-da-ci-expand" }, [h("td", { colspan: "9" }, [
                    h("div", { className: "cq-da-ci-link" }, [
                        h("p", { className: "cq-modal-hint", text: "Confirma el vínculo con este SKU. "
                            + (c.cantidad_min > 1 ? "Es una presentación de " + c.cantidad_min + " u.: lo normal es «Exacto diferente U de envase». " : "")
                            + "Sin clasificar usa la actitud del competidor." }),
                        h("div", { className: "cq-da-ci-link-fields" }, [
                            h("label", { className: "cq-float-field" }, [h("span", { text: "Tipo de match" }), tipo]),
                            h("label", { className: "cq-float-field" }, [h("span", { text: "Actitud" }), act])
                        ]),
                        h("p", { className: "cq-da-comp-actions" }, [
                            h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Confirmar vínculo",
                                onclick: function () {
                                    confirmOpen = 0;
                                    changed(post(actions.confirmMatch, {
                                        producto_competencia_id: c.producto_competencia_id,
                                        producto_base_id: st.pb,
                                        tipo_match: tipo.value,
                                        actitud: act.value
                                    }));
                                } }),
                            h("button", { type: "button", className: "cq-btn", text: "Cancelar",
                                onclick: function () { confirmOpen = 0; render(); } })
                        ])
                    ])
                ])]);
            }

            function renderTable(list, canManage) {
                if (!list.length) {
                    return h("p", { className: "cq-modal-hint", text: "Este producto no tiene competidores vinculados." });
                }
                var tbody = h("tbody");
                var dupes = st.result && st.result.duplicates ? st.result.duplicates : {};
                list.forEach(function (c) {
                    // La actitud del vínculo se edita cuando está confirmado.
                    var linkSel = selectOf(ACTITUDES, c.actitud_vinculo, { disabled: !canManage || c.sugerido,
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
                    var dupe = dupes[c.producto_competencia_id];
                    var counts = c.cuenta && !dupe;
                    var estado = [h("span", { text: counts ? "Cuenta · " + c.actitud_label
                        : (dupe || c.motivo || "Sin clasificar: no cuenta") })];
                    if (c.sugerido && canManage) {
                        estado.push(h("button", { type: "button", className: "cq-btn cq-btn-small cq-btn-primary", text: "Confirmar",
                            onclick: function () {
                                confirmOpen = confirmOpen === c.producto_competencia_id ? 0 : c.producto_competencia_id;
                                render();
                            } }));
                    }
                    tbody.appendChild(h("tr", { className: (counts ? "" : "is-reference") + (c.sugerido ? " is-suggested" : "") }, [
                        h("td", { text: c.competidor || c.dominio || "—" }),
                        h("td", {}, [
                            c.url
                                ? h("a", { href: c.url, target: "_blank", rel: "noopener", text: c.nombre || c.url })
                                : (c.nombre || "—"),
                            c.via_familia ? h("div", { className: "cq-modal-hint",
                                text: "Vía familia: " + (c.via_familia.sku || "#" + c.via_familia.producto_base_id)
                                    + " " + (c.via_familia.nombre || "") }) : null
                        ]),
                        h("td", { text: c.sugerido ? "Sugerido" : c.tipo_match_label }),
                        h("td", { className: "cq-num", text: unit }),
                        h("td", { text: (c.fecha || "—") + (c.dias !== null ? " (" + c.dias + " d)" : "") }),
                        h("td", {}, [linkSel]),
                        h("td", {}, [domSel]),
                        usageCell(c, canManage),
                        h("td", { className: "cq-da-usage" }, estado)
                    ]));
                    if (canManage && disableOpen === c.producto_competencia_id) tbody.appendChild(disableRow(c));
                    if (c.oculto_uso === "siempre" && stopOpen === c.producto_competencia_id) tbody.appendChild(stopRow(c, canManage));
                    if (canManage && c.sugerido && confirmOpen === c.producto_competencia_id) tbody.appendChild(confirmRow(c));
                });
                return h("div", { className: "cq-da-comp-table-wrap" }, [
                    h("table", { className: "cq-da-comp-table" }, [
                        h("thead", {}, [h("tr", {}, ["Competidor", "Producto", "Tipo", "Precio unit. bruto", "Fecha",
                            "Actitud vínculo", "Actitud competidor", "Uso", "Estado"].map(function (t) {
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

            /** Nombre del producto local (o de la línea) para buscar en Google y en fuentes internas. */
            function productName() {
                var p = st.context && st.context.producto;
                var line = st.snap ? st.snap.line : {};
                return String((p && p.nombre) || line.description || line.sku || "").trim();
            }

            function buscadorUrl(q) {
                if (!cfg.buscadorUrl) return "";
                return cfg.buscadorUrl + (cfg.buscadorUrl.indexOf("?") >= 0 ? "&" : "?") + "q=" + encodeURIComponent(q || "");
            }

            /* --- Fuentes internas: catálogo scrapeado (Sande, DIMAFI) e ingresos manuales --- */
            var internal = (function () {
                var ps = { page: 1, expand: 0, last: null, seq: 0, timer: null, unitCtx: null };
                var q = h("input", { type: "search", placeholder: "Palabra, código o URL…", "aria-label": "Buscar en fuentes internas" });
                var modo = selectOf([["alguna", "Contiene alguna palabra"], ["todas", "Contiene todas las palabras"]], "alguna",
                    { "aria-label": "Filtro" });
                var fuente = selectOf([["", "Todas"], ["sande", "Sande"], ["dimafi", "DIMAFI"], ["manual", "Manual"]], "",
                    { "aria-label": "Fuente" });
                var hide = h("input", { type: "checkbox", checked: true });
                var status = h("p", { className: "cq-modal-hint", role: "status" });
                var panelMsg = h("p", { className: "cq-modal-hint", role: "status" });
                var tbody = h("tbody");
                var pageLabel = h("span", { className: "cq-modal-hint", text: "—" });
                var prev = h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Anterior", disabled: true,
                    onclick: function () { if (ps.page > 1) { ps.page--; ps.expand = 0; search(true); } } });
                var next = h("button", { type: "button", className: "cq-btn cq-btn-small", text: "Siguiente", disabled: true,
                    onclick: function () { ps.page++; ps.expand = 0; search(true); } });
                var openBuscador = h("a", { className: "cq-btn cq-btn-small", target: "_blank", rel: "noopener",
                    text: "Abrir en Buscador", href: "#" });
                var el = h("div", { className: "cq-da-ci", hidden: true }, [
                    h("h4", { text: "Fuentes internas" }),
                    h("p", { className: "cq-modal-hint", text: "Busca en el catálogo scrapeado (Sande, DIMAFI) y en ingresos manuales, y vincula al SKU de esta línea." }),
                    h("div", { className: "cq-da-ci-filters" }, [
                        h("label", { className: "cq-float-field cq-da-ci-q" }, [h("span", { text: "Buscar" }), q]),
                        h("label", { className: "cq-float-field" }, [h("span", { text: "Filtro" }), modo]),
                        h("label", { className: "cq-float-field" }, [h("span", { text: "Fuente" }), fuente]),
                        h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Buscar",
                            onclick: function () { ps.page = 1; ps.expand = 0; search(true); } })
                    ]),
                    h("label", { className: "cq-da-ci-hide" }, [hide, " Ocultar ya vinculados a este SKU"]),
                    status,
                    panelMsg,
                    h("div", { className: "cq-da-comp-table-wrap" }, [
                        h("table", { className: "cq-da-comp-table" }, [
                            h("thead", {}, [h("tr", {}, ["Fuente", "Producto", "Precio/u", "Estado", "Acción"].map(function (t) {
                                return h("th", { scope: "col", text: t });
                            }))]),
                            tbody
                        ])
                    ]),
                    h("p", { className: "cq-da-ci-pager" }, [prev, pageLabel, next, openBuscador])
                ]);

                q.addEventListener("input", function () { ps.page = 1; ps.expand = 0; search(false); });
                q.addEventListener("keydown", function (event) {
                    if (event.key === "Enter") {
                        event.preventDefault();
                        ps.page = 1;
                        ps.expand = 0;
                        search(true);
                    }
                });
                modo.addEventListener("change", function () { ps.page = 1; ps.expand = 0; search(true); });
                fuente.addEventListener("change", function () { ps.page = 1; ps.expand = 0; search(true); });
                hide.addEventListener("change", function () { ps.expand = 0; if (ps.last) renderRows(); });

                function setPanelMsg(text, warn) {
                    panelMsg.textContent = text || "";
                    panelMsg.classList.toggle("is-warn", !!warn);
                }

                function emptyRow(text) {
                    tbody.innerHTML = "";
                    tbody.appendChild(h("tr", {}, [h("td", { colspan: "5", className: "cq-modal-hint", text: text })]));
                }

                function search(immediate) {
                    clearTimeout(ps.timer);
                    var run = function () {
                        var text = String(q.value || "").trim();
                        openBuscador.href = buscadorUrl(text) || "#";
                        if (text.length < 2) {
                            emptyRow("Escribe al menos 2 caracteres.");
                            status.textContent = "";
                            pageLabel.textContent = "—";
                            prev.disabled = true;
                            next.disabled = true;
                            return;
                        }
                        var mySeq = ++ps.seq;
                        status.textContent = "Buscando…";
                        emptyRow("Cargando…");
                        post(actions.internalSearch, {
                            search: text,
                            coincidencia: modo.value,
                            fuente: fuente.value,
                            page: ps.page
                        }).then(function (data) {
                            if (mySeq !== ps.seq) return;
                            ps.last = data;
                            renderRows();
                        }).catch(function (error) {
                            if (mySeq !== ps.seq) return;
                            status.textContent = error.message;
                            emptyRow(error.message);
                        });
                    };
                    if (immediate) run();
                    else ps.timer = setTimeout(run, 350);
                }

                function price(r) {
                    var u = Number(r.precio_bruto_unitario);
                    return r.precio_bruto_unitario === null || r.precio_bruto_unitario === undefined || !isFinite(u)
                        ? "—" : LD.formatUnitPrice(u);
                }

                function renderRows() {
                    var data = ps.last || {};
                    var rows = data.rows || [];
                    var total = data.total || 0;
                    var pages = Math.max(1, Math.ceil(total / (data.per_page || 10)));
                    var pb = st.pb;
                    var filtered = rows.filter(function (r) {
                        return !(hide.checked && r.match_estado === "confirmado" && Number(r.producto_base_id) === pb);
                    });
                    pageLabel.textContent = "Página " + ps.page + " / " + pages + " (" + total + " productos)";
                    prev.disabled = ps.page <= 1;
                    next.disabled = ps.page >= pages;
                    status.textContent = filtered.length
                        ? filtered.length + " en esta página" + (filtered.length < rows.length ? " (ocultos los ya vinculados)" : "")
                        : "Sin resultados";
                    if (!filtered.length) {
                        emptyRow("Sin resultados");
                        return;
                    }
                    tbody.innerHTML = "";
                    filtered.forEach(function (r) {
                        var id = Number(r.id) || 0;
                        var rowPb = Number(r.producto_base_id) || 0;
                        var estado;
                        var action = null;
                        if (r.match_estado === "confirmado" && rowPb === pb) {
                            estado = h("span", { className: "cq-da-badge is-ok", text: "Ya vinculado a este SKU" });
                        } else {
                            var reassign = r.match_estado === "confirmado" && rowPb > 0;
                            if (reassign) estado = "Vinculado a " + (r.canonical_sku || "#" + rowPb);
                            else if (r.match_estado === "sugerido") estado = "Sugerido" + (r.canonical_sku ? " → " + r.canonical_sku : "");
                            else if (r.match_estado === "rechazado") estado = "Rechazado";
                            else estado = "Sin vincular";
                            action = h("button", { type: "button",
                                className: "cq-btn cq-btn-small" + (reassign ? "" : " cq-btn-primary"),
                                text: reassign ? "Reasignar aquí" : "Vincular a este SKU",
                                onclick: function () {
                                    ps.expand = ps.expand === id ? 0 : id;
                                    ps.unitCtx = null;
                                    setPanelMsg("");
                                    renderRows();
                                } });
                        }
                        var code = String(r.codigo_externo || "").trim();
                        tbody.appendChild(h("tr", {}, [
                            h("td", {}, [h("span", { className: "cq-da-badge cq-da-badge--" + (r.fuente_slug || "otro"),
                                text: r.fuente_nombre || r.fuente_slug || "—" })]),
                            h("td", {}, [
                                r.url_producto
                                    ? h("a", { href: r.url_producto, target: "_blank", rel: "noopener", text: r.nombre || r.url_producto })
                                    : (r.nombre || "—"),
                                code ? h("div", {}, [h("code", { text: code })]) : null
                            ]),
                            h("td", { className: "cq-num", text: price(r) }),
                            h("td", {}, [estado]),
                            h("td", {}, [action || "—"])
                        ]));
                        if (ps.expand === id) {
                            tbody.appendChild(linkPanel(r, r.match_estado === "confirmado" && rowPb > 0 && rowPb !== pb));
                        }
                    });
                }

                /** Validación (familia y unidades) + tipo, actitud y nota antes de confirmar. */
                function linkPanel(r, reassign) {
                    var id = Number(r.id) || 0;
                    var info = h("p", { className: "cq-modal-hint", text: "Cargando validación…" });
                    var blockers = h("div", { className: "cq-da-ci-blockers", hidden: true });
                    var warn = h("p", { className: "cq-modal-hint is-warn", hidden: true });
                    var tipo = selectOf([["", "— seleccionar —"]].concat(TIPOS), "", { "aria-label": "Tipo de match" });
                    var act = selectOf(ACTITUDES, "sin_clasificar", { "aria-label": "Actitud" });
                    var nota = h("textarea", { rows: "2", "aria-label": "Nota" });
                    var confirmBtn = h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Confirmar vínculo",
                        disabled: true });

                    function paintWarn() {
                        var ctx = ps.unitCtx || {};
                        var text = "";
                        if (tipo.value === "exacto_envase" && (ctx.family_status === "unknown" || ctx.family_status === "missing")) {
                            text = "Advertencia familia: " + (ctx.family_warning || "revisa el estado de familia.");
                        } else if (tipo.value && tipo.value !== "exacto_envase" && ctx.units_differ) {
                            text = "Unidades distintas: local " + (ctx.local_unit_label || "1") + " vs competencia "
                                + (ctx.cantidad_min || 1) + " u.";
                        }
                        warn.textContent = text;
                        warn.hidden = !text;
                    }
                    tipo.addEventListener("change", paintWarn);

                    post(actions.confirmPreflight, { producto_competencia_id: id, producto_base_id: st.pb }).then(function (d) {
                        ps.unitCtx = d.unit_context || null;
                        var comp = d.sande || {};
                        var local = d.local || {};
                        info.textContent = "Competencia: " + (comp.codigo ? comp.codigo + " " : "") + (comp.nombre || "")
                            + " (" + (comp.cantidad_min || (ps.unitCtx && ps.unitCtx.cantidad_min) || 1) + " u) · Local: "
                            + (local.canonical_sku || "") + " " + (local.nombre_canonico || "");
                        var list = d.blockers || [];
                        blockers.innerHTML = "";
                        if (list.length || d.message) {
                            if (d.message) blockers.appendChild(h("strong", { text: d.message }));
                            if (list.length) {
                                blockers.appendChild(h("ul", {}, list.map(function (b) {
                                    return h("li", {}, [b.url
                                        ? h("a", { href: b.url, target: "_blank", rel: "noopener", text: b.label || b.tipo })
                                        : (b.label || b.tipo)]);
                                })));
                            }
                            blockers.hidden = false;
                        }
                        confirmBtn.disabled = !d.can_confirm;
                        confirmBtn.hidden = !d.can_confirm;
                        paintWarn();
                    }).catch(function (error) {
                        info.textContent = error.message;
                    });

                    confirmBtn.addEventListener("click", function () {
                        if (!tipo.value) {
                            setPanelMsg("Debes seleccionar el tipo de match.", true);
                            return;
                        }
                        if (reassign && !window.confirm("Este producto ya está vinculado a " + (r.canonical_sku || "otro SKU")
                            + ". ¿Confirmas la reasignación a este SKU?")) {
                            return;
                        }
                        var ctx = ps.unitCtx || {};
                        if (tipo.value !== "exacto_envase" && ctx.units_differ && !window.confirm(
                            "Las unidades son distintas (local " + (ctx.local_unit_label || "1") + " vs competencia "
                            + (ctx.cantidad_min || 1) + " u). ¿Confirmas de todos modos?\n\n"
                            + "Si es el mismo producto con otro envase, cancela y elige «Exacto diferente U de envase».")) {
                            return;
                        }
                        confirmBtn.disabled = true;
                        confirmBtn.textContent = "Guardando…";
                        post(actions.confirmMatch, {
                            producto_competencia_id: id,
                            producto_base_id: st.pb,
                            tipo_match: tipo.value,
                            actitud: act.value,
                            nota: nota.value || ""
                        }).then(function () {
                            ps.expand = 0;
                            ps.unitCtx = null;
                            setPanelMsg("Vínculo confirmado con este SKU.");
                            loadContext(true);
                            search(true);
                        }).catch(function (error) {
                            confirmBtn.disabled = false;
                            confirmBtn.textContent = "Confirmar vínculo";
                            setPanelMsg(error.message, true);
                        });
                    });

                    return h("tr", { className: "cq-da-ci-expand" }, [h("td", { colspan: "5" }, [
                        h("div", { className: "cq-da-ci-link" }, [
                            info,
                            blockers,
                            warn,
                            h("div", { className: "cq-da-ci-link-fields" }, [
                                h("label", { className: "cq-float-field" }, [h("span", { text: "Tipo de match *" }), tipo]),
                                h("label", { className: "cq-float-field" }, [h("span", { text: "Actitud" }), act]),
                                h("label", { className: "cq-float-field cq-da-ci-nota" }, [h("span", { text: "Nota (opcional)" }), nota])
                            ]),
                            h("p", { className: "cq-da-comp-actions" }, [
                                confirmBtn,
                                h("button", { type: "button", className: "cq-btn", text: "Cancelar",
                                    onclick: function () { ps.expand = 0; renderRows(); } })
                            ])
                        ])
                    ])]);
                }

                return {
                    el: el,
                    open: function (name) {
                        el.hidden = false;
                        q.value = name || "";
                        modo.value = "alguna";
                        ps.page = 1;
                        ps.expand = 0;
                        setPanelMsg("");
                        search(true);
                        if (el.scrollIntoView) el.scrollIntoView({ behavior: "smooth", block: "start" });
                    },
                    reset: function () {
                        clearTimeout(ps.timer);
                        ps.seq++;
                        ps.page = 1;
                        ps.expand = 0;
                        ps.last = null;
                        el.hidden = true;
                        q.value = "";
                        fuente.value = "";
                        hide.checked = true;
                        status.textContent = "";
                        setPanelMsg("");
                        emptyRow("Pulsa «Buscar en Fuentes Internas» para buscar.");
                    }
                };
            })();

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
                body.appendChild(renderTable(competitors(), canManage));
                if (canManage) body.appendChild(renderForm());
                body.appendChild(h("p", { className: "cq-da-comp-actions" }, [
                    h("button", { type: "button", className: "cq-btn", text: "Buscar en Google", onclick: function () {
                        var name = productName();
                        if (!name) {
                            setMsg("No hay nombre de producto para buscar.", true);
                            return;
                        }
                        window.open("https://www.google.com/search?q=" + encodeURIComponent(name), "_blank", "noopener,noreferrer");
                    } }),
                    canManage ? h("button", { type: "button", className: "cq-btn", text: "Buscar en Fuentes Internas",
                        onclick: function () { internal.open(productName()); } }) : null
                ]));
                // El panel conserva la búsqueda al recargar la competencia (se mueve, no se rehace).
                if (canManage) body.appendChild(internal.el);
                else body.appendChild(h("p", { className: "cq-modal-hint", text: "Solo lectura: editar competencia requiere el permiso «Gestionar catálogo competencia»." }));
            }

            return {
                open: function () {
                    build();
                    setMsg("");
                    disableOpen = 0;
                    confirmOpen = 0;
                    stopOpen = 0;
                    internal.reset();
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
                // Minimizado por defecto en cada línea, como Descuentos.
                if (panel) {
                    panel.details.open = false;
                    panel.body.innerHTML = "";
                    panel.brief.textContent = "";
                }
            },
            /** Abre Descuentos y el asistente (marca «Revisar descuento» de la tabla). */
            reveal: function () {
                if (config.details) config.details.open = true;
                ensurePanel().details.open = true;
                render();
                loadContext(false);
            }
        };
    }

    /**
     * Marca «Revisar descuento» en la fila de la tabla (la dejó «Aplicar descuentos sugeridos»).
     * opts.onOpen: abre Editar línea con el asistente; opts.onDismiss: quita la marca.
     */
    function reviewBadge(opts) {
        opts = opts || {};
        return h("span", { className: "cq-da-review" }, [
            h("button", { type: "button", className: "cq-da-review-open", text: "Revisar descuento",
                disabled: !opts.onOpen,
                title: "Necesita descuento manual: falta información para sugerirlo. Abre el asistente.",
                onclick: function (event) {
                    event.stopPropagation();
                    if (opts.onOpen) opts.onOpen();
                } }),
            opts.onDismiss ? h("button", { type: "button", className: "cq-da-review-x", text: "×",
                "aria-label": "Quitar marca Revisar descuento",
                onclick: function (event) {
                    event.stopPropagation();
                    opts.onDismiss();
                } }) : null
        ]);
    }

    /**
     * «Aplicar descuentos sugeridos» (junto a Modo avanzado): evalúa todas las líneas con las
     * reglas del asistente y aplica el sugerido donde se puede.
     *
     * config.cfg: discountAssistant de la página. config.button: botón que abre el modal.
     * config.getLines(): líneas del documento. config.lineInfo(line): { gross, units, cost,
     *   finalTotal, discount } antes de aplicar. config.applySuggestion(line, monto final).
     * config.setReview(line, bool). config.done(): repinta y guarda. config.canEdit().
     * config.docKey(): documento abierto (el mismo que el asistente de la línea).
     */
    function createBulk(config) {
        var cfg = config.cfg || {};
        var actions = cfg.actions || {};
        if (!config.button || !actions.contextBatch) return null;
        var st = { rows: [], loading: false, error: "", seq: 0 };
        var el = null;
        var body = null;
        var foot = null;
        var summary = null;

        function money(v) {
            return LD.formatMoney(round2(v));
        }

        function post(data) {
            var form = new FormData();
            form.append("action", actions.contextBatch);
            form.append("nonce", cfg.nonce || "");
            Object.keys(data).forEach(function (k) { form.append(k, String(data[k])); });
            return fetch(cfg.ajaxUrl, { method: "POST", body: form, credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || "No se pudo leer la competencia.");
                    }
                    return json.data || {};
                });
        }

        function close() {
            if (!el) return;
            el.hidden = true;
            el.setAttribute("aria-hidden", "true");
        }

        function build() {
            if (el) return;
            body = h("div", { className: "cq-modal-body cq-da-bulk-body" });
            summary = h("p", { className: "cq-modal-hint cq-da-bulk-summary", role: "status" });
            foot = h("footer", { className: "cq-modal-foot cq-da-bulk-foot" });
            el = h("div", { className: "cq-modal cq-da-comp-modal cq-da-bulk-modal", hidden: true, "aria-hidden": "true" }, [
                h("div", { className: "cq-modal-backdrop", onclick: close }),
                h("div", { className: "cq-modal-dialog cq-modal-wide", role: "dialog", "aria-modal": "true",
                    "aria-label": "Aplicar descuentos sugeridos" }, [
                    h("header", { className: "cq-modal-head" }, [
                        h("h3", { text: "Aplicar descuentos sugeridos" }),
                        h("button", { type: "button", className: "cq-modal-close", "aria-label": "Cerrar", text: "×", onclick: close })
                    ]),
                    body,
                    summary,
                    foot
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

        /** Estado de una línea: apply (se aplica), done (ya tiene el sugerido), manual o na (no aplica). */
        function classify(line, index, comps, settings) {
            var info = config.lineInfo(line) || {};
            var row = { line: line, index: index, info: info, ignore: false, kind: "na", reason: "", res: null };
            var pb = Number(line.producto_base_id) || 0;
            if (!pb) {
                row.reason = "Línea sin producto local.";
                return row;
            }
            var res = compute({
                gross: info.gross,
                units: info.units,
                cost: info.cost,
                finalTotal: info.finalTotal,
                // Precios ocultos: lo elegido «esta vez» en el asistente de este documento.
                competitors: withHiddenUse(comps[pb] || comps[String(pb)] || [],
                    hiddenOverrides(config.docKey ? config.docKey() : null))
            }, settings);
            row.res = res;
            if (res.status === "empty") {
                row.reason = "Sin cantidad o precio.";
            } else if (!res.volume.ok) {
                row.reason = "No cumple el criterio de volumen.";
            } else if (res.status === "no_discount") {
                row.reason = "Sin descuento disponible (factor actual " + LD.formatNumber(res.factorBefore, 2) + ").";
            } else if (res.status === "no_cost") {
                row.kind = "manual";
                row.reason = "Sin costo guardado.";
            } else if (res.status === "no_info") {
                row.kind = "manual";
                row.reason = "Falta información: sin competidores codiciosos ni competitivos vigentes.";
            } else if (res.status === "ok") {
                var already = Math.abs((Number(info.finalTotal) || 0) - res.suggestion.value) < 0.5;
                row.kind = already ? "done" : "apply";
                row.reason = already ? "Ya tiene el sugerido." : "";
            }
            return row;
        }

        var LABELS = {
            apply: "Aplicado",
            done: "Aplicado",
            manual: "Necesita manual (falta información)",
            na: "No aplica para descuento"
        };

        function paintSummary() {
            var count = { apply: 0, done: 0, manual: 0, na: 0, ignored: 0 };
            var before = 0;
            var after = 0;
            var ready = !st.loading && !st.error;
            st.rows.forEach(function (r) {
                if (r.ignore) count.ignored++;
                else count[r.kind]++;
                var current = Number(r.info.finalTotal) || 0;
                before += current;
                after += r.kind === "apply" && !r.ignore ? r.res.suggestion.value : current;
            });
            summary.textContent = ready && st.rows.length
                ? count.apply + " se aplican · " + count.done + " ya tienen el sugerido · " + count.manual
                    + " necesitan manual · " + count.na + " no aplican"
                    + (count.ignored ? " · " + count.ignored + " ignoradas" : "") + ". Total de las líneas: " + money(before)
                    + " → " + money(after) + "."
                : "";
        }

        function renderFoot() {
            foot.innerHTML = "";
            var hasApply = st.rows.some(function (r) { return r.kind === "apply" && !r.ignore; });
            var hasManual = st.rows.some(function (r) { return r.kind === "manual" && !r.ignore; });
            var busy = st.loading || !!st.error;
            foot.appendChild(h("button", { type: "button", className: "cq-btn", text: "Cancelar", onclick: close }));
            foot.appendChild(h("button", { type: "button", className: "cq-btn cq-btn-primary", text: "Aplicar",
                disabled: busy || !hasApply, onclick: function () { commit(false); } }));
            foot.appendChild(h("button", { type: "button", className: "cq-btn cq-btn-primary",
                text: "Aplicar y marcar a los que no se pudo automático",
                title: "Aplica los sugeridos y deja «Revisar descuento» en las líneas que necesitan descuento manual",
                disabled: busy || (!hasApply && !hasManual), onclick: function () { commit(true); } }));
        }

        function renderRows() {
            body.innerHTML = "";
            if (st.loading) {
                body.appendChild(h("p", { className: "cq-modal-hint", text: "Leyendo la competencia de " + st.rows.length + " líneas…" }));
                return;
            }
            if (st.error) {
                body.appendChild(h("p", { className: "cq-modal-hint is-warn", text: st.error }));
                return;
            }
            if (!st.rows.length) {
                body.appendChild(h("p", { className: "cq-modal-hint", text: "El documento no tiene líneas." }));
                return;
            }
            var tbody = h("tbody");
            st.rows.forEach(function (r) {
                var line = r.line;
                var result;
                if (r.kind === "apply" || r.kind === "done") {
                    var sug = r.res.suggestion;
                    result = money(sug.value) + " (−" + LD.formatNumber(sug.pct, 2) + " %, ×" + LD.formatNumber(sug.factor, 3) + ")";
                    if (r.kind === "apply" && Number(r.info.discount) > 0) {
                        result += " · reemplaza el descuento actual (−" + money(r.info.discount) + ")";
                    }
                    if (r.reason) result += " · " + r.reason;
                } else {
                    result = r.reason;
                }
                var tr = h("tr", { className: "cq-da-bulk-row is-" + r.kind });
                var ignoreCell = "—";
                if (r.kind === "apply" || r.kind === "manual") {
                    var ignore = h("input", { type: "checkbox", "aria-label": "Ignorar esta línea" });
                    ignore.checked = r.ignore;
                    ignore.addEventListener("change", function () {
                        r.ignore = ignore.checked;
                        tr.classList.toggle("is-ignored", r.ignore);
                        paintSummary();
                        renderFoot();
                    });
                    ignoreCell = h("label", { className: "cq-da-ci-hide" }, [ignore, " Ignorar"]);
                }
                [
                    h("td", {}, [h("code", { text: line.sku || "—" }), h("div", { text: line.description || "" })]),
                    h("td", { className: "cq-num", text: LD.formatNumber(r.info.units || 0, 0) }),
                    h("td", { className: "cq-num", text: money(r.info.finalTotal || 0) }),
                    h("td", {}, [h("span", { className: "cq-da-badge cq-da-badge--" + r.kind, text: LABELS[r.kind] })]),
                    h("td", { text: result }),
                    h("td", {}, [ignoreCell])
                ].forEach(function (td) { tr.appendChild(td); });
                tbody.appendChild(tr);
            });
            body.appendChild(h("p", { className: "cq-modal-hint", text: "Usa las mismas reglas del asistente. "
                + "Las líneas que ya tienen descuento se reemplazan por el sugerido; marca «Ignorar» para dejarlas como están." }));
            body.appendChild(h("div", { className: "cq-da-comp-table-wrap" }, [
                h("table", { className: "cq-da-comp-table" }, [
                    h("thead", {}, [h("tr", {}, ["Producto", "Unidades", "Precio actual", "Estado", "Cómo quedaría", ""].map(function (t) {
                        return h("th", { scope: "col", text: t });
                    }))]),
                    tbody
                ])
            ]));
        }

        function render() {
            renderRows();
            paintSummary();
            renderFoot();
        }

        function commit(mark) {
            st.rows.forEach(function (r) {
                if (r.ignore) return;
                if (r.kind === "apply") {
                    config.applySuggestion(r.line, r.res.suggestion.value);
                    config.setReview(r.line, false);
                } else if (r.kind === "done") {
                    config.setReview(r.line, false);
                } else if (r.kind === "manual" && mark) {
                    config.setReview(r.line, true);
                }
            });
            close();
            config.done();
        }

        function open() {
            build();
            el.hidden = false;
            el.setAttribute("aria-hidden", "false");
            var lines = (config.getLines() || []).slice();
            st.seq++;
            if (config.canEdit && !config.canEdit()) {
                st.rows = [];
                st.loading = false;
                st.error = "El documento no es editable.";
                render();
                return;
            }
            st.rows = lines.map(function (line, index) { return { line: line, index: index, info: {}, kind: "na" }; });
            st.loading = true;
            st.error = "";
            render();
            var ids = [];
            lines.forEach(function (line) {
                var pb = Number(line.producto_base_id) || 0;
                if (pb && ids.indexOf(pb) < 0) ids.push(pb);
            });
            var mySeq = st.seq;
            var request = ids.length ? post({ producto_base_ids: ids.join(",") }) : Promise.resolve({ competidores: {} });
            request.then(function (data) {
                if (mySeq !== st.seq) return;
                var settings = data.settings || cfg.settings;
                var comps = data.competidores || {};
                st.rows = lines.map(function (line, index) { return classify(line, index, comps, settings); });
                st.loading = false;
                render();
            }).catch(function (error) {
                if (mySeq !== st.seq) return;
                st.loading = false;
                st.error = error.message;
                render();
            });
        }

        config.button.addEventListener("click", open);
        return { open: open, close: close };
    }

    global.RiversoDiscountAssistant = {
        compute: compute,
        settingsOf: settingsOf,
        withHiddenUse: withHiddenUse,
        create: create,
        createBulk: createBulk,
        reviewBadge: reviewBadge
    };
})(typeof window !== "undefined" ? window : globalThis);
