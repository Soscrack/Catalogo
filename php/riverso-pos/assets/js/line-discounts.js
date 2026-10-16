/**
 * Descuentos y «Otra regla» compartidos por los editores de líneas
 * (cotizaciones y borradores de boleta/factura).
 *
 * Valor fijado: el campo que escribió el vendedor (price_pct, margin_pct, amount,
 * final_unit, final_total, factor) y su valor exacto. El monto en pesos se deriva
 * de él con el bruto actual; espejo de Riverso_Quote_Totals::discount_from_anchor.
 *
 * Cada editor entrega sus propias funciones de línea (bruto, unidades, costo, cifras)
 * y sus elementos; aquí no se conoce la forma de la cotización ni del borrador.
 */
(function (global) {
    "use strict";

    var ANCHORS = ["price_pct", "margin_pct", "amount", "final_unit", "final_total", "factor"];

    /** Campos editables del módulo Descuentos, cada uno con el valor que fija. */
    var FIELDS = [
        { anchor: "price_pct", kind: "rate", label: "% precio" },
        { anchor: "margin_pct", kind: "rate", label: "% margen", needsCost: true },
        { anchor: "amount", kind: "money", label: "monto" },
        { anchor: "factor", kind: "factor", label: "factor", needsCost: true },
        { anchor: "final_unit", kind: "unit", label: "final unitario" },
        { anchor: "final_total", kind: "money", label: "monto final" }
    ];

    var IVA_FACTOR = 1.19;

    /** Origen del descuento (Asistente de Descuento). */
    var ORIGIN_LABELS = {
        sugerido: "sugerido",
        maximo: "máximo",
        minimo: "mínimo",
        marcador: "marcador",
        historial: "repetido",
        manual: "manual"
    };

    function normalizeOrigin(value) {
        var origin = String(value || "").toLowerCase();
        return Object.prototype.hasOwnProperty.call(ORIGIN_LABELS, origin) ? origin : null;
    }

    function round2(number) {
        return Math.round((number + Number.EPSILON) * 100) / 100;
    }

    function round3(number) {
        return Math.round((number + Number.EPSILON) * 1000) / 1000;
    }

    function round4(number) {
        return Math.round((number + Number.EPSILON) * 10000) / 10000;
    }

    function round6(number) {
        return Math.round((number + Number.EPSILON) * 1000000) / 1000000;
    }

    /** Porcentaje entre 0 y 100 con 4 decimales. */
    function clampRate(value) {
        var rate = round4(Number(value) || 0);
        if (rate < 0) return 0;
        if (rate > 100) return 100;
        return rate;
    }

    function normalizeAnchor(value) {
        var anchor = String(value || "").toLowerCase();
        return ANCHORS.indexOf(anchor) >= 0 ? anchor : null;
    }

    function formatNumber(value, decimals) {
        return new Intl.NumberFormat("es-CL", {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals == null ? 2 : decimals
        }).format(Number(value) || 0);
    }

    /** Porcentajes y factores: hasta 4 decimales. */
    function formatRate(value) {
        return formatNumber(value, 4);
    }

    function formatMoney(value) {
        var number = Number(value) || 0;
        var cents = Math.round(number * 100) % 100 !== 0;
        return new Intl.NumberFormat("es-CL", {
            style: "currency",
            currency: "CLP",
            minimumFractionDigits: cents ? 2 : 0,
            maximumFractionDigits: cents ? 2 : 0
        }).format(number);
    }

    /** Precio unitario: los decimales que tiene (2 a 4), sin ceros de relleno. */
    function formatUnitPrice(value) {
        var number = Number(value) || 0;
        var scaled = Math.round(number * 10000);
        var decimals = 0;
        if (scaled % 10000 !== 0) {
            decimals = scaled % 10 !== 0 ? 4 : (scaled % 100 !== 0 ? 3 : 2);
        }
        return new Intl.NumberFormat("es-CL", {
            style: "currency",
            currency: "CLP",
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    /**
     * Margen en pesos antes del descuento (bruto − unidades × costo).
     * bases: { gross, billable, cost } con cost a 2 decimales o null.
     */
    function marginMoney(bases) {
        if (!bases || bases.cost === null || bases.cost === undefined) return null;
        return round2(round2(bases.gross) - round2(bases.billable * bases.cost));
    }

    /**
     * Descuento en pesos desde el valor fijado. null si el campo necesita costo y no hay.
     */
    function discountFromAnchor(bases, anchor, value) {
        var v = Number(value);
        if (!isFinite(v) || !bases) return null;
        var gross = round2(bases.gross);
        var units = Number(bases.billable) || 0;
        var cost = bases.cost === undefined ? null : bases.cost;
        var discount;
        switch (anchor) {
            case "price_pct":
                discount = round2(gross * clampRate(v) / 100);
                break;
            case "margin_pct":
                if (cost === null) return null;
                var margin = marginMoney(bases);
                discount = margin > 0 ? round2(margin * clampRate(v) / 100) : 0;
                break;
            case "amount":
                discount = round2(v);
                break;
            case "final_unit":
                discount = round2(gross - round2(v * units));
                break;
            case "final_total":
                discount = round2(gross - round2(v));
                break;
            case "factor":
                if (cost === null || !(cost > 0)) return null;
                discount = round2(gross - round2(v * cost * units));
                break;
            default:
                return null;
        }
        if (discount < 0) return 0;
        if (discount > gross) return gross;
        return discount;
    }

    /**
     * Recalcula monto y % desde el valor fijado con el bruto actual.
     * false si la línea no tiene fijado o le falta el costo (queda el monto guardado).
     */
    function applyAnchor(line, bases) {
        if (!line) return false;
        var anchor = normalizeAnchor(line.discount_anchor);
        var value = line.discount_anchor_value;
        if (!anchor || value === null || value === undefined || value === "") return false;
        var discount = discountFromAnchor(bases, anchor, value);
        if (discount === null) return false;
        var margin = marginMoney(bases);
        line.discount_amount = discount;
        line.price_discount = bases.gross > 0 && discount > 0 ? clampRate(discount / bases.gross * 100) : 0;
        line.margin_discount = margin !== null && margin > 0 && discount > 0
            ? clampRate(discount / margin * 100)
            : 0;
        return true;
    }

    /** Valor fijado al abrir una línea: el guardado o, en líneas antiguas, el monto (como antes). */
    function initialAnchor(line, hasCost) {
        var anchor = normalizeAnchor(line.discount_anchor);
        var value = line.discount_anchor_value;
        if (anchor && value !== null && value !== undefined && value !== "") {
            return { anchor: anchor, value: Number(value) };
        }
        if (Number(line.discount_amount) > 0) {
            return { anchor: "amount", value: round2(Number(line.discount_amount)) };
        }
        if (Number(line.price_discount) > 0) {
            return { anchor: "price_pct", value: clampRate(line.price_discount) };
        }
        if (Number(line.margin_discount) > 0 && hasCost) {
            return { anchor: "margin_pct", value: clampRate(line.margin_discount) };
        }
        return { anchor: null, value: null };
    }

    /**
     * Montos que emite el SII para una línea (espejo de Riverso_Billing_Totals::build):
     * cada línea va en pesos enteros; el IVA es el bruto entero menos el neto entero.
     */
    function siiLine(lineBruto, qty, afecto) {
        var bruto = round2(Number(lineBruto) || 0);
        var q = round3(Number(qty) || 0);
        if (!(q > 0)) return { gross: 0, net: 0, tax: 0 };
        if (afecto === false) {
            var exento = Math.round(bruto);
            return { gross: exento, net: exento, tax: 0 };
        }
        var unitNet = round6(bruto / IVA_FACTOR / q);
        var net = Math.round(q * unitNet);
        var gross = Math.round(bruto);
        var tax = Math.max(0, gross - net);
        return { gross: net + tax, net: net, tax: tax };
    }

    /**
     * Celda de precio de la tabla: monto final, unitario final y, si hay descuento,
     * el precio de lista entre paréntesis. opts.sii: el monto es el entero que se emite.
     */
    function paintPriceFigures(container, line, figures, opts) {
        opts = opts || {};
        var billable = Number(figures.billable) || 0;
        var exact = Number(figures.lineNet) || 0;
        var shown = opts.sii ? siiLine(exact, opts.qty != null ? opts.qty : line.quantity, line.afecto).gross : exact;
        var finalUnit = billable > 0 ? shown / billable : (Number(line.unit_price) || 0);
        var listUnit = Number(line.unit_price) || 0;
        var gross = container.querySelector(".cq-line-gross");
        if (gross) {
            gross.textContent = formatMoney(shown);
            if (opts.sii) {
                gross.title = "Monto que se emitirá al SII (pesos enteros)"
                    + (Math.abs(shown - exact) >= 0.005 ? ". Exacto: " + formatMoney(exact) : "");
            } else {
                gross.title = "Monto final de la línea (con IVA, con descuento)";
            }
        }
        var unitHint = container.querySelector(".cq-line-unit-hint");
        if (unitHint) {
            unitHint.textContent = formatUnitPrice(round4(finalUnit)) + " / ud";
        }
        var listHint = container.querySelector(".cq-line-list-hint");
        if (listHint) {
            var hasDiscount = Number(figures.discount) > 0;
            listHint.hidden = !hasDiscount;
            listHint.textContent = hasDiscount
                ? "(lista " + formatMoney(figures.gross) + " · " + formatUnitPrice(round4(listUnit)) + "/ud)"
                : "";
        }
    }

    /** Nodos de la celda de precio (sin pintar). */
    function buildPriceCellNodes(td) {
        var gross = document.createElement("div");
        gross.className = "cq-line-gross";
        var unitHint = document.createElement("div");
        unitHint.className = "cq-line-unit-hint";
        var listHint = document.createElement("div");
        listHint.className = "cq-line-list-hint";
        listHint.title = "Precio de lista, antes del descuento";
        td.appendChild(gross);
        td.appendChild(unitHint);
        td.appendChild(listHint);
    }

    /**
     * Módulo Descuentos del modal de línea.
     *
     * config.els: price_pct, margin_pct, amount, factor, final_unit, final_total (inputs),
     *             cost, factorBefore (solo lectura), details, brief, status.
     * config.baseDraft(): copia de la línea con la cantidad/precio del modal, o null.
     * config.bases(line): { gross, billable, cost } antes del descuento.
     * config.figures(line): { discount, lineNet } con el descuento aplicado.
     * config.listUnit(line): precio de lista unitario que muestra la tabla.
     * config.toView / config.toBruto: conversión Neto/Bruto del modal.
     * config.parse(text): número en formato es-CL.
     * config.formatPercent(value).
     * config.sii: avisa el monto entero que se emitirá (borradores de boleta/factura).
     *
     * Origen (Asistente de Descuento): sugerido, maximo, minimo, marcador, historial o manual.
     * Escribir en un campo lo deja manual; setAnchor lo fija con el origen que diga el asistente.
     */
    function createModule(config) {
        var els = config.els || {};
        var st = { anchor: null, value: null, origin: null, syncing: false };
        var listeners = [];

        function fields() {
            return FIELDS.map(function (field) {
                return {
                    anchor: field.anchor,
                    kind: field.kind,
                    label: field.label,
                    needsCost: !!field.needsCost,
                    el: els[field.anchor] || null
                };
            });
        }

        function draft() {
            var d = config.baseDraft();
            if (!d) return null;
            d.discount_anchor = st.anchor || null;
            d.discount_anchor_value = st.anchor ? st.value : null;
            if (!st.anchor) {
                d.discount_amount = 0;
                d.price_discount = 0;
                d.margin_discount = 0;
            } else {
                // Sin costo (margen/factor) queda el monto guardado.
                applyAnchor(d, config.bases(d));
            }
            return d;
        }

        function figuresOf(d) {
            var bases = config.bases(d);
            var fig = config.figures(d);
            var cost = bases.cost === undefined ? null : bases.cost;
            var units = Number(bases.billable) || 0;
            var gross = Number(bases.gross) || 0;
            var finalTotal = Number(fig.lineNet) || 0;
            var listUnit = config.listUnit ? Number(config.listUnit(d)) : 0;
            if (!(listUnit > 0)) listUnit = units > 0 ? gross / units : 0;
            var margin = marginMoney(bases);
            return {
                cost: cost,
                units: units,
                gross: gross,
                discount: Number(fig.discount) || 0,
                finalTotal: finalTotal,
                factorBefore: cost > 0 && listUnit > 0 ? listUnit / cost : null,
                values: {
                    price_pct: gross > 0 ? fig.discount / gross * 100 : 0,
                    margin_pct: cost !== null && margin > 0 ? fig.discount / margin * 100 : null,
                    amount: fig.discount,
                    factor: cost > 0 && units > 0 ? finalTotal / units / cost : null,
                    final_unit: units > 0 ? finalTotal / units : null,
                    final_total: finalTotal
                }
            };
        }

        function formatField(field, value) {
            if (value === null || value === undefined || !isFinite(Number(value))) return "";
            if (field.kind === "money") return formatNumber(config.toView(value), 2);
            if (field.kind === "unit") return formatNumber(config.toView(value), 4);
            return formatNumber(value, 4);
        }

        /** Monto final que pide el valor fijado (solo objetivos de precio). */
        function targetFinal(f) {
            var v = Number(st.value);
            if (!st.anchor || !isFinite(v)) return null;
            if (st.anchor === "final_total") return round2(v);
            if (st.anchor === "final_unit") return round2(v * f.units);
            if (st.anchor === "factor" && f.cost > 0) return round2(v * f.cost * f.units);
            return null;
        }

        /** Pinta el módulo. force=true también reescribe el campo con foco. */
        function refresh(force) {
            var d = draft();
            if (!d) return;
            var f = figuresOf(d);
            var hasCost = f.cost !== null;
            var sii = config.sii ? siiLine(f.finalTotal, d.quantity, d.afecto).gross : null;
            st.syncing = true;
            fields().forEach(function (field) {
                if (!field.el) return;
                var label = field.el.closest ? field.el.closest("[data-anchor]") : null;
                var unavailable = field.needsCost && !(hasCost && (field.anchor !== "factor" || f.cost > 0));
                field.el.disabled = unavailable;
                field.el.hidden = unavailable;
                if (label) {
                    label.classList.toggle("is-anchor", st.anchor === field.anchor);
                    var na = label.querySelector(".cq-dm-na");
                    if (na) na.hidden = !unavailable;
                }
                if (unavailable) {
                    field.el.value = "";
                    return;
                }
                if (!force && document.activeElement === field.el) return;
                var value = st.anchor === field.anchor && st.value !== null
                    ? st.value
                    : f.values[field.anchor];
                field.el.value = formatField(field, value);
            });
            st.syncing = false;

            if (els.cost) {
                els.cost.value = hasCost ? formatNumber(config.toView(f.cost), 4) : "—";
            }
            if (els.factorBefore) {
                els.factorBefore.value = f.factorBefore !== null ? formatNumber(f.factorBefore, 4) : "—";
            }

            var anchorField = null;
            fields().forEach(function (field) {
                if (field.anchor === st.anchor) anchorField = field;
            });
            if (els.brief) {
                var brief = f.discount > 0
                    ? "−" + formatMoney(config.toView(f.discount)) + " (" + formatRate(f.values.price_pct) + " %) → "
                        + formatMoney(config.toView(sii !== null ? sii : f.finalTotal))
                    : "Sin descuento";
                if (anchorField && f.discount > 0) brief += " · fijado: " + anchorField.label;
                if (f.discount > 0 && st.origin && st.origin !== "manual") brief += " · " + ORIGIN_LABELS[st.origin];
                els.brief.textContent = brief;
            }
            listeners.forEach(function (fn) { fn({ line: d, figures: f }); });

            if (!els.status) return;
            var parts = [];
            var warn = false;
            if (hasCost) {
                var profit = round2(f.finalTotal - round2(f.units * f.cost));
                var marginPct = f.finalTotal > 0 ? round2(profit / f.finalTotal * 100) : 0;
                parts.push("Utilidad " + formatMoney(config.toView(profit)) + " · margen " + config.formatPercent(marginPct) + ".");
                if (f.values.factor !== null && f.values.factor < 1) {
                    parts.push("Bajo el costo.");
                    warn = true;
                }
            } else {
                parts.push("Sin costo guardado: margen y factor no disponibles.");
            }
            if (sii !== null && Math.abs(sii - f.finalTotal) >= 0.005) {
                parts.push("Se emitirá " + formatMoney(sii) + " (el SII usa pesos enteros).");
            }
            var target = targetFinal(f);
            if (target !== null && target > round2(f.gross) + 0.005) {
                parts.push("El objetivo supera el precio de lista: no se aplica descuento. Para subir el precio usa «Ignorar regla — precio final».");
                warn = true;
            }
            els.status.textContent = parts.join(" ");
            els.status.classList.toggle("is-warn", warn);
        }

        /** Escribir en un campo lo deja fijado con el valor tal cual (montos pasan a bruto). */
        function onInput(field) {
            if (st.syncing || !field.el) return;
            var raw = String(field.el.value || "").trim();
            st.origin = raw === "" ? null : "manual";
            if (raw === "") {
                st.anchor = null;
                st.value = null;
            } else {
                var value = config.parse(raw);
                if (field.kind === "money" || field.kind === "unit") value = config.toBruto(value);
                if (field.kind === "rate") value = clampRate(value);
                if (!(value >= 0)) value = 0;
                st.anchor = field.anchor;
                st.value = round6(value);
            }
            refresh(false);
        }

        // Solo escribir fija un campo; recorrerlos con Tab no cambia el valor fijado.
        fields().forEach(function (field) {
            if (!field.el) return;
            field.el.addEventListener("input", function () { onInput(field); });
            field.el.addEventListener("blur", function () { refresh(true); });
        });

        return {
            /** Al abrir el modal: valor fijado de la línea y módulo minimizado. */
            open: function (line) {
                var init = initialAnchor(line, config.bases(line).cost !== null);
                st.anchor = init.anchor;
                st.value = init.value;
                st.origin = init.anchor ? (normalizeOrigin(line.discount_origin) || "manual") : null;
                if (els.details) els.details.open = false;
            },
            refresh: refresh,
            draft: draft,
            /** Fija un campo desde el asistente (anchor null quita el descuento). */
            setAnchor: function (anchor, value, origin) {
                anchor = normalizeAnchor(anchor);
                var v = Number(value);
                if (!anchor || !isFinite(v)) {
                    st.anchor = null;
                    st.value = null;
                    st.origin = null;
                } else {
                    st.anchor = anchor;
                    st.value = round6(anchor === "price_pct" || anchor === "margin_pct" ? clampRate(v) : Math.max(0, v));
                    st.origin = normalizeOrigin(origin) || "manual";
                }
                refresh(true);
            },
            /** Cifras actuales del modal: { line, figures } (null sin línea). */
            snapshot: function () {
                var d = draft();
                return d ? { line: d, figures: figuresOf(d) } : null;
            },
            /** fn({ line, figures }) en cada repintado del módulo. */
            onRefresh: function (fn) {
                if (typeof fn === "function") listeners.push(fn);
            },
            /** Copia a la línea el valor fijado y el monto derivado (al guardar el modal). */
            writeTo: function (line) {
                var d = draft();
                line.discount_anchor = st.anchor || null;
                line.discount_anchor_value = st.anchor ? st.value : null;
                line.discount_origin = d && st.anchor && Number(d.discount_amount) > 0 ? (st.origin || "manual") : null;
                // «Revisar descuento» se cumple al dejarle un descuento a la línea.
                if (line.discount_origin) line.discount_review = false;
                if (d && st.anchor) {
                    line.discount_amount = round2(Number(d.discount_amount) || 0);
                    line.price_discount = clampRate(d.price_discount);
                    line.margin_discount = config.bases(d).cost !== null ? clampRate(d.margin_discount) : 0;
                } else {
                    line.price_discount = 0;
                    line.margin_discount = 0;
                    line.discount_amount = 0;
                }
            }
        };
    }

    /**
     * «Otra regla»: sugerencias al escribir y modal de búsqueda (lupa).
     *
     * config.els: input, searchBtn, suggest, chosen, modal, modalQ, modalHint, modalResults.
     * config.search(q): Promise con { rules: [{ codigo, nombre, version, resumen }] }.
     * config.onChoose(rule): el editor deja la regla en la línea y recalcula.
     * El elemento de fondo del modal lleva data-rule-search-close para cerrarlo.
     */
    function createRulePicker(config) {
        var els = config.els || {};
        var seq = 0;
        var timer = null;
        var suggestions = [];

        function hideSuggest() {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            suggestions = [];
            if (els.suggest) {
                els.suggest.hidden = true;
                els.suggest.innerHTML = "";
            }
        }

        function choose(rule) {
            hideSuggest();
            closeModal();
            if (els.input) els.input.value = rule.codigo;
            config.onChoose(rule);
        }

        /** Busca reglas aprobadas y las pinta en `list`. */
        function run(query, list, hint, max) {
            if (!list) return;
            var mySeq = ++seq;
            if (hint) hint.textContent = "Buscando…";
            config.search(String(query || "").trim()).then(function (data) {
                if (mySeq !== seq) return;
                var rules = data && Array.isArray(data.rules) ? data.rules : [];
                if (max) rules = rules.slice(0, max);
                if (list === els.suggest) suggestions = rules;
                list.innerHTML = "";
                rules.forEach(function (rule) {
                    var li = document.createElement("li");
                    li.className = "cq-result cq-rule-result";
                    var info = document.createElement("div");
                    var title = document.createElement("div");
                    title.className = "cq-result-sku";
                    title.textContent = rule.codigo + " · " + (rule.nombre || "");
                    var meta = document.createElement("div");
                    meta.className = "cq-result-meta";
                    meta.textContent = "Tramos: " + (rule.resumen || "—") + " · v" + rule.version;
                    info.appendChild(title);
                    info.appendChild(meta);
                    var pick = document.createElement("button");
                    pick.type = "button";
                    pick.className = "cq-btn cq-btn-primary cq-btn-small";
                    pick.textContent = "Elegir";
                    pick.addEventListener("click", function () { choose(rule); });
                    li.appendChild(info);
                    li.appendChild(pick);
                    list.appendChild(li);
                });
                list.hidden = list === els.suggest && rules.length === 0;
                if (hint) {
                    hint.textContent = rules.length
                        ? rules.length + (rules.length === 1 ? " regla aprobada." : " reglas aprobadas.")
                        : "Sin reglas aprobadas para «" + String(query || "").trim() + "».";
                }
            }).catch(function (error) {
                if (mySeq !== seq) return;
                if (hint) hint.textContent = error && error.message ? error.message : "No se pudo buscar.";
            });
        }

        function openModal(prefill) {
            if (!els.modal) return;
            hideSuggest();
            els.modal.hidden = false;
            els.modal.setAttribute("aria-hidden", "false");
            if (els.modalQ) {
                els.modalQ.value = String(prefill || "").trim();
                els.modalQ.focus();
            }
            run(els.modalQ ? els.modalQ.value : "", els.modalResults, els.modalHint);
        }

        function closeModal() {
            if (!els.modal || els.modal.hidden) return;
            els.modal.hidden = true;
            els.modal.setAttribute("aria-hidden", "true");
        }

        if (els.input) {
            els.input.addEventListener("input", function () {
                clearTimeout(timer);
                var q = String(els.input.value || "").trim();
                if (!q) {
                    hideSuggest();
                    return;
                }
                timer = setTimeout(function () { run(q, els.suggest, null, 8); }, 250);
            });
            els.input.addEventListener("keydown", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    if (suggestions[0]) choose(suggestions[0]);
                }
            });
        }
        if (els.searchBtn) {
            els.searchBtn.addEventListener("click", function () {
                openModal(els.input ? els.input.value : "");
            });
        }
        if (els.modal) {
            els.modal.addEventListener("click", function (event) {
                var t = event.target;
                if (t && t.getAttribute && t.getAttribute("data-rule-search-close")) closeModal();
            });
        }
        if (els.modalQ) {
            var modalTimer = null;
            els.modalQ.addEventListener("input", function () {
                clearTimeout(modalTimer);
                modalTimer = setTimeout(function () {
                    run(els.modalQ.value, els.modalResults, els.modalHint);
                }, 250);
            });
        }

        return {
            /** Texto bajo el buscador: regla elegida, sin regla o regla que ya no existe. */
            render: function (rule, missing) {
                if (els.input && document.activeElement !== els.input) {
                    els.input.value = rule ? rule.codigo : "";
                }
                if (!els.chosen) return;
                els.chosen.classList.toggle("is-warn", !!(rule && missing));
                if (!rule) {
                    els.chosen.textContent = "Sin regla elegida.";
                } else if (missing) {
                    els.chosen.textContent = "La regla " + rule.codigo
                        + " no está aprobada o no existe: se usa la regla asignada. Elige otra.";
                } else {
                    els.chosen.textContent = "Regla elegida: " + rule.codigo + (rule.nombre ? " — " + rule.nombre : "")
                        + ". Sigue su última versión aprobada.";
                }
            },
            reset: function (rule) {
                hideSuggest();
                closeModal();
                if (els.input) els.input.value = rule ? rule.codigo : "";
            },
            focus: function () {
                if (els.input) els.input.focus();
            },
            hideSuggest: hideSuggest,
            closeModal: closeModal,
            isModalOpen: function () {
                return !!(els.modal && !els.modal.hidden);
            }
        };
    }

    global.RiversoLineDiscounts = {
        ANCHORS: ANCHORS,
        round2: round2,
        round4: round4,
        round6: round6,
        clampRate: clampRate,
        normalizeAnchor: normalizeAnchor,
        normalizeOrigin: normalizeOrigin,
        formatNumber: formatNumber,
        formatRate: formatRate,
        formatMoney: formatMoney,
        formatUnitPrice: formatUnitPrice,
        marginMoney: marginMoney,
        discountFromAnchor: discountFromAnchor,
        applyAnchor: applyAnchor,
        initialAnchor: initialAnchor,
        siiLine: siiLine,
        buildPriceCellNodes: buildPriceCellNodes,
        paintPriceFigures: paintPriceFigures,
        createModule: createModule,
        createRulePicker: createRulePicker
    };
})(window);
