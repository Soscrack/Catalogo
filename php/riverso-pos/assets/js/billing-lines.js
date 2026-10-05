/**
 * Editor de líneas de boleta (patrón cotizaciones).
 */
(function (global) {
  "use strict";

  var BILL_IVA_FACTOR = 1.19;
  var host = null;
  var billEls = {};
  var state = {
    lineModal: {
      index: -1, mode: "auto", taxView: "bruto", previewSeq: 0,
      hasRule: false, isFamily: false, hasLocalProduct: false,
      catalogP: null, unitario0: null,
      ruleTotal: null, ruleCodigo: null, ruleNombre: null, stdRule: null,
      priceRef: null, unitGross: 0, totalGross: 0
    },
    familyLineIndex: -1,
    familyPriceSeq: 0,
    priceRecalcChain: Promise.resolve()
  };

  var cfgProxy = {
    get actions() { return (host && host.cfg && host.cfg.actions) || {}; }
  };

  function getLines() { return host.getLines(); }
  function scheduleDraftSave(ms) {
    if (host && host.scheduleDraftSave) host.scheduleDraftSave(ms);
  }
  function billSetMessage(msg, isError) {
    if (host && host.showAlert) host.showAlert(msg || "", !!isError);
  }
  function post(action, data) { return host.post(action, data); }
  function trackPriceRecalc(p) {
    var next = Promise.resolve(p).catch(function () {});
    state.priceRecalcChain = state.priceRecalcChain.then(function () { return next; });
    return next;
  }

    function lineGrupoId(line) {
        var fam = line && line._family ? line._family : null;
        var gid = fam && fam.grupo_id != null ? Number(fam.grupo_id) : 0;
        return gid > 0 ? gid : 0;
    }

    function lineUnitsPerPack(line) {
        var upp = line && line.units_per_pack != null ? Number(line.units_per_pack) : 0;
        if (!(upp > 0) && line && line._family && line._family.units_per_pack != null) {
            upp = Number(line._family.units_per_pack);
        }
        if (!(upp > 0)) {
            var member = currentFamilyMember(line);
            if (member && member.cantidad_unidades != null) {
                upp = Number(member.cantidad_unidades);
            }
        }
        return upp > 0 ? upp : 1;
    }

    function currentFamilyMember(line) {
        var fam = line && line._family ? line._family : null;
        var members = fam && Array.isArray(fam.members) ? fam.members : [];
        if (!members.length) return null;
        var pb = line.producto_base_id != null ? Number(line.producto_base_id) : 0;
        var sku = String(line.sku || "").toLowerCase();
        var found = null;
        members.forEach(function (m) {
            if (found) return;
            if (pb > 0 && Number(m.producto_base_id) === pb) {
                found = m;
                return;
            }
            if (sku && String(m.sku || "").toLowerCase() === sku) {
                found = m;
            }
        });
        return found;
    }

    function lineIsUnitario(line) {
        var member = currentFamilyMember(line);
        if (member) {
            return !!member.es_unitario;
        }
        return lineUnitsPerPack(line) <= 1.0001;
    }

    function familyUnitsQuoted(line) {
        return round3(Number(line.quantity || 0) * lineUnitsPerPack(line));
    }

    function totalFamilyUnitsQuoted(grupoId, lines) {
        lines = lines || getLines() || [];
        var total = 0;
        lines.forEach(function (line) {
            if (lineGrupoId(line) === grupoId) {
                total += familyUnitsQuoted(line);
            }
        });
        return round3(total);
    }

    function familyLineIndexes(grupoId, lines) {
        lines = lines || getLines() || [];
        var indexes = [];
        lines.forEach(function (line, i) {
            if (lineGrupoId(line) === grupoId && line.producto_base_id) {
                indexes.push(i);
            }
        });
        return indexes;
    }

    function buildLineRenderPlan(lines) {
        var plan = [];
        var used = {};
        var i;
        for (i = 0; i < lines.length; i++) {
            if (used[i]) continue;
            var line = lines[i];
            var gid = lineGrupoId(line);
            if (!gid) {
                plan.push({ type: "line", line: line, index: i });
                used[i] = true;
                continue;
            }
            var groupIndexes = [];
            var j;
            for (j = i; j < lines.length; j++) {
                if (used[j]) continue;
                if (lineGrupoId(lines[j]) === gid) {
                    groupIndexes.push(j);
                    used[j] = true;
                }
            }
            groupIndexes.sort(function (a, b) {
                var la = lines[a];
                var lb = lines[b];
                var ua = lineIsUnitario(la) ? 0 : 1;
                var ub = lineIsUnitario(lb) ? 0 : 1;
                if (ua !== ub) return ua - ub;
                var qa = lineUnitsPerPack(la);
                var qb = lineUnitsPerPack(lb);
                if (qa !== qb) return qb - qa;
                return a - b;
            });
            var totalUnits = 0;
            var totalGross = 0;
            var totalFinal = 0;
            var ruleTotal = null;
            var ruleAdjusted = false;
            groupIndexes.forEach(function (idx) {
                totalUnits += familyUnitsQuoted(lines[idx]);
                if (!ruleAdjusted && lines[idx]._rule_adjusted && lines[idx]._rule_total != null) {
                    ruleTotal = Number(lines[idx]._rule_total);
                    ruleAdjusted = true;
                }
            });
            totalUnits = round3(totalUnits);
            if (ruleAdjusted && ruleTotal != null && totalUnits > 0) {
                totalGross = round2(ruleTotal);
            } else {
                groupIndexes.forEach(function (idx) {
                    totalGross += lineFigures(lines[idx]).gross;
                });
                totalGross = round2(totalGross);
            }
            groupIndexes.forEach(function (idx) {
                totalFinal += lineFigures(lines[idx]).lineNet;
            });
            totalFinal = round2(totalFinal);
            var fam = line._family || {};
            plan.push({
                type: "header",
                grupoId: gid,
                familyName: fam.family_name || fam.family_code || ("Familia #" + gid),
                totalUnits: totalUnits,
                totalGross: totalGross,
                totalFinal: totalFinal,
                indexes: groupIndexes.slice()
            });
            groupIndexes.forEach(function (idx) {
                plan.push({ type: "line", line: lines[idx], index: idx, grouped: true });
            });
        }
        return plan;
    }

    function familyHeaderRow(item) {
        var tr = document.createElement("tr");
        tr.className = "cq-family-header";
        var td = document.createElement("td");
        td.colSpan = 8;
        var name = document.createElement("strong");
        name.textContent = item.familyName || "Familia";
        var meta = document.createElement("span");
        meta.className = "cq-family-header-meta";
        meta.textContent = familyHeaderMetaText(item);
        td.appendChild(name);
        td.appendChild(meta);
        tr.appendChild(td);
        return tr;
    }

    function familyHeaderMetaText(item) {
        var units = "Lleva " + formatPlain(item.totalUnits) + " uds";
        var money = formatMoney(item.totalGross != null ? item.totalGross : 0);
        var finalMoney = formatMoney(item.totalFinal != null ? item.totalFinal : 0);
        return units + " · Total " + money + " · Monto final " + finalMoney;
    }

    function buildLineRow(line, index, editable) {
        var tr = document.createElement("tr");
        tr.className = "cq-line" + (lineGrupoId(line) ? " cq-line-in-family" : "");
        tr.dataset.index = String(index);
        var detail = document.createElement("td");
        var skuRow = document.createElement("div");
        skuRow.className = "cq-sku-row";
        var skuText = String(line.sku || "").trim();
        if (skuText) {
            var sku = document.createElement("a");
            sku.className = "cq-sku cq-sku-link";
            sku.textContent = skuText;
            sku.href = productsQuickSearchUrl(line);
            sku.target = "_blank";
            sku.rel = "noopener noreferrer";
            sku.title = "Abrir en búsqueda rápida de Productos";
            sku.addEventListener("click", function (event) {
                // Evitar que otros handlers de la fila interfieran.
                event.stopPropagation();
            });
            skuRow.appendChild(sku);
        } else {
            var manualTag = document.createElement("span");
            manualTag.className = "cq-sku cq-sku-manual";
            manualTag.textContent = "Manual";
            manualTag.title = "Línea ingresada sin SKU";
            skuRow.appendChild(manualTag);
        }
        if (lineGrupoId(line) && Array.isArray((line._family || {}).members) && (line._family.members || []).length > 1) {
            var change = document.createElement("button");
            change.type = "button";
            change.className = "cq-text-btn cq-family-change";
            change.textContent = "Cambiar";
            change.disabled = !editable;
            change.title = "Elegir otro miembro de la familia";
            change.addEventListener("click", function () {
                openFamilyModal(index);
            });
            skuRow.appendChild(change);
        }
        detail.appendChild(skuRow);
        var desc = document.createElement("span");
        desc.className = "cq-desc";
        desc.textContent = line.description || "";
        detail.appendChild(desc);
        if (line.local_only || !line.product_id) {
            var tag = document.createElement("span");
            tag.className = "cq-local-only-tag";
            tag.textContent = "Solo local";
            tag.title = "Sin product_id WC — Facturar no aplica (P5a-6)";
            detail.appendChild(tag);
        }
        var packHint = presentationLabel(line);
        if (packHint) {
            var pack = document.createElement("span");
            pack.className = "cq-pack-label";
            pack.textContent = packHint;
            detail.appendChild(pack);
        }
        tr.appendChild(detail);
        tr.appendChild(qtyStepperCell(line, index, editable));
        tr.appendChild(priceCell(line, index, editable));
        tr.appendChild(inputCell(line, index, "price_discount", editable));
        tr.appendChild(inputCell(line, index, "margin_discount", editable));
        var utility = document.createElement("td");
        utility.className = "cq-num cq-advanced cq-line-profit";
        tr.appendChild(utility);
        tr.appendChild(stockCell(line));

        var actions = document.createElement("td");
        actions.className = "cq-actions";
        if (editable) {
            var edit = document.createElement("button");
            edit.type = "button";
            edit.className = "cq-text-btn";
            edit.textContent = "Editar";
            edit.addEventListener("click", function () {
                openLineModal(index);
            });
            var remove = document.createElement("button");
            remove.type = "button";
            remove.className = "cq-text-btn";
            remove.textContent = "Eliminar";
            remove.addEventListener("click", function () {
                if (!window.confirm("¿Eliminar esta línea?")) {
                    return;
                }
                var gid = lineGrupoId(getLines()[index] || line);
                getLines().splice(index, 1);
                if (gid) {
                    trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                        scheduleDraftSave(0);
                    });
                } else {
                    renderBoletaEditor();
                    renderBoletaEditorTotals();
                    scheduleDraftSave(0);
                }
            });
            actions.appendChild(edit);
            actions.appendChild(remove);
        }
        tr.appendChild(actions);
        return tr;
    }

    function presentationLabel(line) {
        if (!lineGrupoId(line)) return "";
        if (lineIsUnitario(line)) return "embolsado";
        var upp = lineUnitsPerPack(line);
        var qtyLabel = formatPlain(upp);
        return "envase ×" + qtyLabel;
    }

    function qtyDisplay(line) {
        var qty = Number(line.quantity || 0);
        if (!lineGrupoId(line)) {
            return formatQty(qty);
        }
        if (lineIsUnitario(line)) {
            return formatQty(qty);
        }
        return formatQty(qty) + "×" + formatPlain(lineUnitsPerPack(line));
    }

    function qtySuffix(line) {
        if (!lineGrupoId(line)) return "";
        if (lineIsUnitario(line)) return "embolsado";
        return "";
    }

    function qtyStepperCell(line, index, editable) {
        var td = document.createElement("td");
        td.className = "cq-num cq-qty-cell";
        var wrap = document.createElement("div");
        wrap.className = "cq-qty-stepper";
        var minus = document.createElement("button");
        minus.type = "button";
        minus.className = "cq-stepper-btn";
        minus.textContent = "\u2212";
        minus.setAttribute("aria-label", "Disminuir cantidad de " + (line.sku || line.description || "línea"));
        minus.disabled = !editable;
        var input = document.createElement("input");
        input.type = "text";
        input.inputMode = "decimal";
        input.dataset.field = "quantity";
        input.dataset.index = String(index);
        input.value = formatQty(line.quantity);
        input.setAttribute("aria-label", "Cantidad de " + (line.sku || line.description || "línea"));
        input.disabled = !editable;
        var plus = document.createElement("button");
        plus.type = "button";
        plus.className = "cq-stepper-btn";
        plus.textContent = "+";
        plus.setAttribute("aria-label", "Aumentar cantidad de " + (line.sku || line.description || "línea"));
        plus.disabled = !editable;
        function applyQty(next) {
            if (next < 0) next = 0;
            next = round3(next);
            getLines()[index].quantity = next;
            input.value = formatQty(next);
            updateQtyMeta(wrap, getLines()[index]);
            if (getLines()[index].producto_base_id) {
                trackPriceRecalc(recalcLocalLinePrice(getLines()[index], index)).then(function () {
                    scheduleDraftSave(0);
                });
                return;
            }
            renderBoletaEditorTotals();
            scheduleDraftSave(0);
        }
        function commitQty() {
            var parsed = parseClNumber(input.value);
            if (parsed < 0) parsed = 0;
            parsed = round3(parsed);
            var line = getLines()[index];
            if (!line) return;
            line.quantity = parsed;
            input.value = formatQty(parsed);
            updateQtyMeta(wrap, line);
            var gid = lineGrupoId(line);
            if (gid) {
                trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                    scheduleDraftSave(0);
                });
                return;
            }
            if (line.producto_base_id) {
                trackPriceRecalc(recalcLocalLinePrice(line, index)).then(function () {
                    scheduleDraftSave(0);
                });
                return;
            }
            renderBoletaEditorTotals();
            scheduleDraftSave(0);
        }
        // Evita que el clic en +/− quite el foco del input y dispare commitQty (doble regla).
        minus.addEventListener("mousedown", function (event) {
            event.preventDefault();
        });
        plus.addEventListener("mousedown", function (event) {
            event.preventDefault();
        });
        minus.addEventListener("click", function () {
            applyQty(Number(getLines()[index].quantity || 0) - 1);
        });
        plus.addEventListener("click", function () {
            applyQty(Number(getLines()[index].quantity || 0) + 1);
        });
        input.addEventListener("focus", function () {
            input.value = String(getLines()[index].quantity).replace(".", ",");
            input.select();
        });
        input.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                input.blur();
            }
        });
        input.addEventListener("blur", function () {
            commitQty();
        });
        wrap.appendChild(minus);
        wrap.appendChild(input);
        wrap.appendChild(plus);
        updateQtyMeta(wrap, line);
        td.appendChild(wrap);
        return td;
    }

    function priceCell(line, index, editable) {
        var td = document.createElement("td");
        td.className = "cq-num cq-price-cell";
        var figures = lineFigures(line);
        var gross = document.createElement("div");
        gross.className = "cq-line-gross";
        gross.textContent = formatMoney(figures.gross);
        gross.title = "Total bruto de la línea";
        var unitHint = document.createElement("div");
        unitHint.className = "cq-line-unit-hint";
        unitHint.textContent = formatUnitPrice(line.unit_price) + " / ud";
        td.appendChild(gross);
        td.appendChild(unitHint);
        var mode = linePriceMode(line);
        if (mode === "manual" || mode === "ref") {
            var tag = document.createElement("span");
            tag.className = "cq-price-mode-tag";
            tag.textContent = mode === "manual" ? "Manual" : "P ref.";
            tag.title = mode === "manual"
                ? "Precio final manual (sin regla)"
                : "Regla con precio de referencia P";
            td.appendChild(tag);
        }
        return td;
    }

    function updateQtyMeta(wrap, line) {
        if (!wrap) return;
        var existing = wrap.querySelector(".cq-qty-meta");
        if (existing) {
            existing.remove();
        }
        if (!lineGrupoId(line)) return;
        var meta = document.createElement("span");
        meta.className = "cq-qty-meta";
        if (lineIsUnitario(line)) {
            meta.textContent = "embolsado";
            meta.title = formatQty(line.quantity) + " embolsado";
        } else {
            meta.textContent = qtyDisplay(line);
            meta.title = "Envases × unidades por envase";
        }
        wrap.appendChild(meta);
    }

    function inputCell(line, index, field, editable) {
        var isRate = field === "price_discount" || field === "margin_discount";
        var td = document.createElement("td");
        td.className = "cq-num" + (isRate ? " cq-advanced cq-rate" : "");

        if (field === "margin_discount" && !lineHasUnitCost(line)) {
            td.appendChild(marginDiscountUnavailableNode());
            return td;
        }

        var input = document.createElement("input");
        input.type = "text";
        input.inputMode = "decimal";
        input.dataset.field = field;
        input.dataset.index = String(index);
        input.value = displayField(field, line);
        input.setAttribute("aria-label", fieldLabel(field) + (line.sku || line.description || "línea"));
        input.disabled = !editable;
        input.addEventListener("focus", function () {
            input.value = editField(field, getLines()[index]);
            input.select();
        });
        input.addEventListener("input", function () {
            var current = getLines()[index];
            if (!current) return;
            assignField(current, field, parseClNumber(input.value));
            refreshSiblingDiscountInput(trFromInput(input), current, field);
            renderBoletaEditorTotals();
            scheduleDraftSave(400);
        });
        input.addEventListener("blur", function () {
            var current = getLines()[index];
            if (!current) return;
            var parsed = parseClNumber(input.value);
            if (isRate) {
                parsed = clampRate(parsed);
            }
            assignField(current, field, parsed);
            input.value = displayField(field, current);
            refreshSiblingDiscountInput(trFromInput(input), current, field);
            renderBoletaEditorTotals();
            scheduleDraftSave(0);
        });
        td.appendChild(input);
        if (isRate) {
            var suffix = document.createElement("span");
            suffix.className = "cq-suffix";
            suffix.textContent = "%";
            suffix.setAttribute("aria-hidden", "true");
            td.appendChild(suffix);
        }
        return td;
    }

    function lineHasUnitCost(line) {
        if (!line) return false;
        return line.unit_cost !== null && line.unit_cost !== undefined && line.unit_cost !== "";
    }

    function marginDiscountUnavailableNode() {
        var wrap = document.createElement("span");
        wrap.className = "cq-margin-unavailable";
        var dash = document.createElement("span");
        dash.className = "cq-margin-na";
        dash.textContent = "—";
        var help = document.createElement("span");
        help.className = "cq-help-tip";
        help.textContent = "?";
        help.title = "Costo no encontrado";
        help.setAttribute("aria-label", "Costo no encontrado");
        wrap.appendChild(dash);
        wrap.appendChild(help);
        return wrap;
    }

    function trFromInput(input) {
        var el = input;
        while (el && el.tagName !== "TR") {
            el = el.parentNode;
        }
        return el;
    }

    function refreshSiblingDiscountInput(tr, line, editedField) {
        if (!tr || !line) return;
        var other = editedField === "price_discount" ? "margin_discount" : "price_discount";
        if (other === "margin_discount" && !lineHasUnitCost(line)) {
            return;
        }
        var sibling = tr.querySelector('input[data-field="' + other + '"]');
        if (!sibling || document.activeElement === sibling) return;
        sibling.value = displayField(other, line);
    }

    function fieldLabel(field) {
        if (field === "quantity") return "Cantidad de ";
        if (field === "unit_price") return "Precio de ";
        if (field === "price_discount") return "Descuento de precio de ";
        return "Descuento de margen de ";
    }

    function displayField(field, line) {
        if (field === "quantity") return formatQty(line.quantity);
        if (field === "unit_price") return formatPlain(line.unit_price);
        if (field === "price_discount") return formatPlain(line.price_discount || 0);
        return formatPlain(line.margin_discount || 0);
    }

    function editField(field, line) {
        var value = line.quantity;
        if (field === "unit_price") value = line.unit_price;
        if (field === "price_discount") value = line.price_discount || 0;
        if (field === "margin_discount") value = line.margin_discount || 0;
        return String(value).replace(".", ",");
    }

    function assignField(line, field, parsed) {
        if (!line) return;
        if (field === "quantity") line.quantity = parsed;
        else if (field === "unit_price") line.unit_price = parsed;
        else if (field === "price_discount") line.price_discount = parsed;
        else if (field === "margin_discount") line.margin_discount = parsed;
        if (field === "price_discount" || field === "margin_discount") {
            syncEquivalentDiscounts(line, field);
        } else {
            syncDiscountAmount(line, field);
        }
    }

    /**
     * Bruto G, costo C y margen en pesos M = G − C (antes del descuento).
     */
    function discountBases(line) {
        var figures = lineFiguresForDiscountBase(line);
        var gross = figures.gross;
        var billable = figures.billable;
        var hasCost = line.unit_cost !== null && line.unit_cost !== undefined && line.unit_cost !== "";
        var costTotal = hasCost ? round2(billable * round2(Number(line.unit_cost))) : null;
        var marginMoney = costTotal === null ? null : round2(gross - costTotal);
        return {
            gross: gross,
            billable: billable,
            costTotal: costTotal,
            marginMoney: marginMoney
        };
    }

    /** Bruto sin aplicar descuentos (para equivalencia). */
    function lineFiguresForDiscountBase(line) {
        var billable = lineBillableUnits(line);
        var price = Number(line.unit_price) || 0;
        var hasFixedTotal = (linePriceMode(line) === "manual" && line.price_total != null)
            || (line._rule_adjusted && line._rule_total != null);
        if (!hasFixedTotal) {
            price = round2(price);
        }
        var gross = familyProratedGross(line, billable);
        if (gross == null) {
            gross = round2(billable * price);
        }
        return { billable: billable, gross: gross };
    }

    /**
     * Un solo descuento en pesos; p y m son vistas equivalentes.
     * source: 'price_discount' | 'margin_discount' | 'discount_amount'
     */
    function syncEquivalentDiscounts(line, source) {
        if (!line) return;
        var bases = discountBases(line);
        var gross = bases.gross;
        var marginMoney = bases.marginMoney;
        var money = 0;
        if (source === "discount_amount") {
            money = round2(Number(line.discount_amount) || 0);
            if (money < 0) money = 0;
            if (gross > 0 && money > gross) money = gross;
            line.discount_amount = money;
            line.price_discount = gross > 0 && money > 0
                ? clampRate(round2(money / gross * 100))
                : 0;
            if (!lineHasUnitCost(line) || !(marginMoney != null && marginMoney > 0)) {
                line.margin_discount = 0;
            } else {
                line.margin_discount = money > 0
                    ? clampRate(round2(money / marginMoney * 100))
                    : 0;
            }
            return;
        }
        if (source === "margin_discount") {
            var m = clampRate(line.margin_discount);
            if (!(marginMoney != null && marginMoney > 0)) {
                line.margin_discount = 0;
                line.price_discount = 0;
                line.discount_amount = 0;
                return;
            }
            // Monto derivado una vez del % margen; no rearmar desde el % precio redondeado.
            money = m > 0 ? round2(marginMoney * m / 100) : 0;
            line.margin_discount = m;
            line.price_discount = gross > 0 && money > 0
                ? clampRate(round2(money / gross * 100))
                : 0;
        } else {
            var p = clampRate(line.price_discount);
            line.price_discount = p;
            money = gross > 0 && p > 0 ? round2(gross * p / 100) : 0;
            if (!lineHasUnitCost(line)) {
                line.margin_discount = 0;
            } else {
                line.margin_discount = (marginMoney != null && marginMoney > 0 && money > 0)
                    ? clampRate(round2(money / marginMoney * 100))
                    : 0;
            }
        }
        line.discount_amount = money;
        if (line.discount_amount < 0) line.discount_amount = 0;
        if (line.discount_amount > gross) line.discount_amount = gross;
    }

    function syncDiscountAmount(line, field) {
        var amount = round2(Number(line.discount_amount) || 0);
        if (amount > 0) {
            syncEquivalentDiscounts(line, "discount_amount");
            return;
        }
        var priceRate = clampRate(line.price_discount);
        var marginRate = clampRate(line.margin_discount);
        if (priceRate > 0) {
            syncEquivalentDiscounts(line, "price_discount");
            return;
        }
        if (marginRate > 0) {
            syncEquivalentDiscounts(line, "margin_discount");
            return;
        }
        if (field === "price_discount" || field === "margin_discount") {
            line.discount_amount = 0;
        }
    }

    function refreshLineFigures() {
        var rows = document.getElementById("bill-boleta-lines-body").querySelectorAll("tr.cq-line");
        Array.prototype.forEach.call(rows, function (tr) {
            var index = Number(tr.dataset.index);
            var line = (getLines() || [])[index];
            if (!line) return;
            var figures = lineFigures(line);
            var profitCell = tr.querySelector(".cq-line-profit");
            if (profitCell) {
                paintUtility(profitCell, figures);
            }
            var grossEl = tr.querySelector(".cq-line-gross");
            if (grossEl) {
                grossEl.textContent = formatMoney(figures.gross);
            }
            var unitHint = tr.querySelector(".cq-line-unit-hint");
            if (unitHint) {
                unitHint.textContent = formatUnitPrice(line.unit_price) + " / ud";
            }
        });
        refreshFamilyHeaders();
    }

    function refreshFamilyHeaders() {
        var headers = document.getElementById("bill-boleta-lines-body").querySelectorAll("tr.cq-family-header");
        if (!headers.length) return;
        var plan = buildLineRenderPlan(getLines() || []);
        var headerItems = plan.filter(function (item) { return item.type === "header"; });
        Array.prototype.forEach.call(headers, function (tr, i) {
            var item = headerItems[i];
            if (!item) return;
            var meta = tr.querySelector(".cq-family-header-meta");
            if (meta) {
                meta.textContent = familyHeaderMetaText(item);
            }
        });
    }

    function paintUtility(cell, figures) {
        var level = true ? alarmLevel(figures.lineProfit, figures.lineMargin) : "";
        cell.textContent = "";
        if (figures.lineProfit === null) {
            cell.textContent = "—";
            cell.title = "Sin costo no se calcula la utilidad";
        } else {
            var money = document.createElement("span");
            money.className = "cq-profit-money";
            money.textContent = formatMoney(figures.lineProfit);
            var rate = document.createElement("span");
            rate.className = "cq-profit-rate";
            rate.textContent = formatPercent(figures.lineMargin);
            cell.appendChild(money);
            cell.appendChild(rate);
            cell.title = level === "neg" ? "Utilidad negativa" : (level === "low" ? "Margen menor a 5 %" : "");
        }
        setAlarm(cell, level);
    }

    function setAlarm(el, level) {
        if (!el) return;
        el.classList.remove("cq-alarm-neg", "cq-alarm-low");
        var parent = el.parentElement;
        if (parent && parent.closest && parent.closest(".cq-totals")) {
            parent.classList.remove("cq-alarm-neg", "cq-alarm-low");
        }
        if (level === "neg" || level === "low") {
            var cls = level === "neg" ? "cq-alarm-neg" : "cq-alarm-low";
            el.classList.add(cls);
            if (parent && parent.closest && parent.closest(".cq-totals")) {
                parent.classList.add(cls);
            }
        }
        el.dataset.alarm = level || "";
    }

    function stockCell(line) {
        var td = document.createElement("td");
        td.className = "cq-num cq-advanced cq-stock-cell";
        var info = line && line._stock ? line._stock : null;
        if (!info || info.producto_base_id === null || info.producto_base_id === undefined) {
            td.textContent = "—";
            td.title = "Sin producto base mapeado";
            return td;
        }
        if (info.stock_total === null || info.stock_total === undefined) {
            td.textContent = "—";
            return td;
        }
        td.textContent = formatPlain(info.stock_total);
        if (info.critico) {
            td.title = "Stock crítico";
        } else if (info.alerta) {
            td.title = "Stock en alerta";
        }
        return td;
    }

    function refreshLineStock() {
        if (!host || !host.isAdvanced || !host.isAdvanced()) return Promise.resolve();
        var caps = (host.cfg && host.cfg.caps) || {};
        if (!caps.viewStock) return Promise.resolve();
        if (!cfgProxy.actions || !cfgProxy.actions.lineStock) return Promise.resolve();
        var lines = getLines() || [];
        var ids = [];
        var seen = {};
        lines.forEach(function (line) {
            var pid = line.product_id;
            if (pid !== null && pid !== undefined && pid !== "" && Number(pid) > 0) {
                var n = Number(pid);
                if (!seen[n]) {
                    seen[n] = true;
                    ids.push(n);
                }
            }
        });
        if (!ids.length) {
            lines.forEach(function (line) { line._stock = null; });
            renderBoletaEditor();
            return Promise.resolve();
        }
        return post(cfgProxy.actions.lineStock, { product_ids: JSON.stringify(ids) }).then(function (data) {
            var map = (data && data.stock) || {};
            (getLines() || []).forEach(function (line) {
                var key = line.product_id !== null && line.product_id !== undefined ? String(line.product_id) : "";
                line._stock = key && map[key] ? map[key] : null;
            });
            renderBoletaEditor();
        }).catch(function () {
            renderBoletaEditor();
        });
    }

    function calculate(lines) {
        var net = 0;
        var discounts = 0;
        var profit = 0;
        var profitKnown = lines.length > 0;
        lines.forEach(function (line) {
            var figures = lineFigures(line);
            if (figures.lineProfit === null) {
                profitKnown = false;
            } else {
                profit += figures.lineProfit;
            }
            net += figures.lineNet;
            discounts += figures.discount;
        });
        net = round2(net);
        discounts = round2(discounts);
        var profitTotal = profitKnown ? round2(profit) : null;
        var margin = null;
        if (profitTotal !== null) {
            margin = net > 0 ? round2((profitTotal / net) * 100) : 0;
        }
        return {
            net_total: net,
            discount_total: discounts,
            profit_total: profitTotal,
            margin_percent: margin
        };
    }

    function lineBillableUnits(line) {
        if (lineGrupoId(line)) {
            return familyUnitsQuoted(line);
        }
        var upp = Number(line.units_per_pack || 1);
        if (upp > 1.0001) {
            return round3(Number(line.quantity || 0) * upp);
        }
        return round3(Number(line.quantity) || 0);
    }

    /**
     * Si hay total fijo (regla ajustada o precio manual de familia), prorratea
     * por unidades facturables. La última línea del grupo absorbe el residuo.
     * @return {number|null}
     */
    function familyProratedGross(line, billable) {
        if (!line) return null;
        var ruleTotal = null;
        if (linePriceMode(line) === "manual" && line.price_total != null && line.price_total !== "") {
            ruleTotal = round2(Number(line.price_total));
        } else if (line._rule_adjusted && line._rule_total != null) {
            ruleTotal = round2(Number(line._rule_total));
        }
        if (ruleTotal == null) {
            return null;
        }
        var gid = lineGrupoId(line);
        if (!gid) {
            return ruleTotal;
        }
        var lines = getLines() || [];
        var indexes = familyLineIndexes(gid, lines);
        if (!indexes.length) {
            return ruleTotal;
        }
        var myPos = -1;
        var familyUnits = 0;
        var i;
        for (i = 0; i < indexes.length; i++) {
            familyUnits += familyUnitsQuoted(lines[indexes[i]]);
            if (lines[indexes[i]] === line) {
                myPos = i;
            }
        }
        familyUnits = round3(familyUnits);
        if (myPos < 0) {
            return round2(ruleTotal * (Number(billable) || 0) / (familyUnits || 1));
        }
        if (!(familyUnits > 0)) {
            return myPos === indexes.length - 1 ? ruleTotal : 0;
        }
        var allocated = 0;
        for (i = 0; i < indexes.length; i++) {
            var units = familyUnitsQuoted(lines[indexes[i]]);
            var share;
            if (i === indexes.length - 1) {
                share = round2(ruleTotal - allocated);
            } else {
                share = round2(ruleTotal * units / familyUnits);
                allocated = round2(allocated + share);
            }
            if (i === myPos) {
                return share;
            }
        }
        return round2(ruleTotal * (Number(billable) || 0) / familyUnits);
    }

    function lineFigures(line) {
        var qty = round3(Number(line.quantity) || 0);
        var billable = lineBillableUnits(line);
        var price = Number(line.unit_price) || 0;
        var hasFixedTotal = (linePriceMode(line) === "manual" && line.price_total != null)
            || (line._rule_adjusted && line._rule_total != null);
        if (!hasFixedTotal) {
            price = round2(price);
        }
        var priceRate = clampRate(line.price_discount);
        var marginRate = clampRate(line.margin_discount);
        var gross = familyProratedGross(line, billable);
        if (gross == null) {
            gross = round2(billable * price);
        }
        var hasCost = line.unit_cost !== null && line.unit_cost !== undefined && line.unit_cost !== "";
        var unitCost = hasCost ? round2(Number(line.unit_cost)) : null;
        var discount;
        var amountMoney = round2(Number(line.discount_amount) || 0);
        if (amountMoney > 0) {
            // Canónico: monto en pesos; % son vistas.
            discount = amountMoney;
        } else if (priceRate > 0) {
            discount = round2(gross * priceRate / 100);
        } else if (marginRate > 0 && unitCost !== null) {
            var marginBase = round2(gross - round2(billable * unitCost));
            discount = marginBase > 0 ? round2(marginBase * marginRate / 100) : 0;
        } else {
            discount = 0;
        }
        if (discount < 0) discount = 0;
        if (discount > gross) discount = gross;
        var lineNet = round2(gross - discount);
        var lineProfit = null;
        var lineMargin = null;
        if (unitCost !== null) {
            lineProfit = round2(lineNet - round2(billable * unitCost));
            lineMargin = lineNet > 0 ? round2((lineProfit / lineNet) * 100) : 0;
        }
        return {
            qty: qty,
            billable: billable,
            gross: gross,
            discount: discount,
            lineNet: lineNet,
            lineProfit: lineProfit,
            lineMargin: lineMargin
        };
    }

    function alarmLevel(profit, margin) {
        if (profit === null || profit === undefined || profit === "") return "";
        if (Number(profit) < 0) return "neg";
        if (margin !== null && margin !== undefined && margin !== "" && Number(margin) < 5) return "low";
        return "";
    }

    function clampRate(value) {
        var rate = round2(Number(value) || 0);
        if (rate < 0) return 0;
        if (rate > 100) return 100;
        return rate;
    }

    function parseClNumber(value) {
        if (typeof value === "number") return value;
        var text = String(value || "").trim().replace(/\s/g, "").replace(/^\$/, "");
        if (text === "") return 0;
        if (text.indexOf(",") !== -1) {
            text = text.replace(/\./g, "").replace(",", ".");
        } else if (/^\d{1,3}(\.\d{3})+$/.test(text)) {
            text = text.replace(/\./g, "");
        }
        var number = Number(text);
        return isFinite(number) ? number : 0;
    }

    function linePriceMode(line) {
        var mode = line && line.price_mode ? String(line.price_mode).toLowerCase() : "auto";
        if (mode === "manual" || mode === "ref" || mode === "std") return mode;
        return "auto";
    }

    function familyGroupPriceMode(grupoId, lines) {
        lines = lines || getLines() || [];
        var indexes = familyLineIndexes(grupoId, lines);
        var found = "auto";
        indexes.forEach(function (idx) {
            if (found !== "auto") return;
            var mode = linePriceMode(lines[idx]);
            if (mode !== "auto") found = mode;
        });
        return found;
    }

    function applyPriceModeToGroup(grupoId, mode, fields) {
        fields = fields || {};
        var lines = getLines() || [];
        familyLineIndexes(grupoId, lines).forEach(function (idx) {
            var line = lines[idx];
            if (!line) return;
            line.price_mode = mode;
            if (mode === "ref") {
                line.price_ref = fields.price_ref != null ? Number(fields.price_ref) : null;
                line.price_total = null;
            } else if (mode === "manual") {
                line.price_ref = null;
                line.price_total = fields.price_total != null ? Number(fields.price_total) : null;
                if (fields.unit_price != null) {
                    line.unit_price = Number(fields.unit_price);
                }
            } else {
                line.price_ref = null;
                line.price_total = null;
            }
        });
    }

    function inheritGroupPriceMode(target, source) {
        if (!target || !source) return;
        var mode = linePriceMode(source);
        target.price_mode = mode;
        target.price_ref = mode === "ref" && source.price_ref != null ? Number(source.price_ref) : null;
        target.price_total = mode === "manual" && source.price_total != null ? Number(source.price_total) : null;
        if (mode === "manual" && source.unit_price != null) {
            target.unit_price = Number(source.unit_price);
        }
    }

    function brutoToView(bruto) {
        var n = Number(bruto) || 0;
        if (state.lineModal.taxView === "neto") {
            return n / BILL_IVA_FACTOR;
        }
        return n;
    }

    function viewToBruto(value) {
        var n = Number(value) || 0;
        if (state.lineModal.taxView === "neto") {
            return n * BILL_IVA_FACTOR;
        }
        return n;
    }

    function formatModalNumber(value, decimals) {
        decimals = decimals == null ? 2 : decimals;
        var number = Number(value) || 0;
        return new Intl.NumberFormat("es-CL", {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals
        }).format(number);
    }

    function setLineTaxView(view) {
        state.lineModal.taxView = view === "neto" ? "neto" : "bruto";
        if (billEls.lineTaxNeto) billEls.lineTaxNeto.classList.toggle("is-active", state.lineModal.taxView === "neto");
        if (billEls.lineTaxBruto) billEls.lineTaxBruto.classList.toggle("is-active", state.lineModal.taxView === "bruto");
        refreshLineModalFields(true);
    }

    function setLineModalMode(mode) {
        if (mode !== "manual" && mode !== "ref" && mode !== "std") mode = "auto";
        state.lineModal.mode = mode;
        if (billEls.lineModeManual) {
            billEls.lineModeManual.checked = mode === "manual";
        }
        if (billEls.lineModeRadios) {
            Array.prototype.forEach.call(billEls.lineModeRadios.querySelectorAll('input[name="bill-line-mode"]'), function (radio) {
                radio.checked = radio.value === mode;
            });
        }
        updateLineModalModeUi();
        if (mode === "manual") {
            var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
            if (!(Number(state.lineModal.totalGross) > 0) || state.lineModal.isFamily) {
                state.lineModal.totalGross = round2((Number(state.lineModal.unitGross) || 0) * scale);
            }
            refreshLineModalFields(true);
        } else if (mode === "ref" || mode === "auto" || mode === "std") {
            scheduleLineModalPreview();
        } else {
            refreshLineModalFields(true);
        }
    }

    function updateLineModalModeUi() {
        var lm = state.lineModal;
        var editablePrice = lm.mode === "manual";
        var showPref = lm.mode === "ref";
        if (billEls.linePrefField) billEls.linePrefField.hidden = !showPref;
        if (billEls.lineUnit) billEls.lineUnit.disabled = !editablePrice;
        if (billEls.lineTotal) billEls.lineTotal.disabled = !editablePrice;
        if (billEls.linePref) billEls.linePref.disabled = !showPref;
        if (billEls.lineUnitLabel) {
            billEls.lineUnitLabel.textContent = lm.taxView === "neto" ? "Precio unitario (neto)" : "Precio unitario";
        }
        if (billEls.lineTotalLabel) {
            if (lm.isFamily && lm.mode === "manual") {
                billEls.lineTotalLabel.textContent = lm.taxView === "neto"
                    ? "Precio total familia (neto)"
                    : "Precio total familia";
            } else {
                billEls.lineTotalLabel.textContent = lm.taxView === "neto" ? "Precio total (neto)" : "Precio total";
            }
        }
    }

    function lineModalBillable() {
        var line = (getLines() || [])[state.lineModal.index];
        if (!line) return 1;
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        var draft = {};
        Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
        draft.quantity = qty;
        var units = lineBillableUnits(draft);
        return units > 0 ? units : 1;
    }

    function refreshLineModalFields(fromState) {
        var lm = state.lineModal;
        lm.syncing = true;
        updateLineModalModeUi();
        var unitBruto = Number(lm.unitGross) || 0;
        var totalBruto = Number(lm.totalGross) || 0;
        var unitView = brutoToView(unitBruto);
        var totalView = brutoToView(totalBruto);
        var otherUnit = lm.taxView === "bruto" ? (unitBruto / BILL_IVA_FACTOR) : unitBruto;
        var otherTotal = lm.taxView === "bruto" ? (totalBruto / BILL_IVA_FACTOR) : totalBruto;
        if (billEls.lineUnit && (fromState || document.activeElement !== billEls.lineUnit)) {
            billEls.lineUnit.value = formatModalNumber(unitView, 4);
        }
        if (billEls.lineTotal && (fromState || document.activeElement !== billEls.lineTotal)) {
            billEls.lineTotal.value = formatModalNumber(totalView, 2);
        }
        if (billEls.lineUnitHint) {
            billEls.lineUnitHint.textContent = lm.taxView === "bruto"
                ? ("Sin IVA: " + formatModalNumber(otherUnit, 4))
                : ("Con IVA: " + formatModalNumber(otherUnit, 4));
        }
        if (billEls.lineTotalHint) {
            billEls.lineTotalHint.textContent = lm.taxView === "bruto"
                ? ("Sin IVA: " + formatModalNumber(otherTotal, 2))
                : ("Con IVA: " + formatModalNumber(otherTotal, 2));
        }
        if (billEls.linePref && lm.mode === "ref" && (fromState || document.activeElement !== billEls.linePref)) {
            var pref = Number(lm.priceRef) || 0;
            billEls.linePref.value = formatModalNumber(brutoToView(pref), 4);
        }
        if (billEls.linePrefHint) {
            if (lm.mode === "ref") {
                var prefB = Number(lm.priceRef) || 0;
                var otherPref = lm.taxView === "bruto" ? (prefB / BILL_IVA_FACTOR) : prefB;
                billEls.linePrefHint.textContent = lm.taxView === "bruto"
                    ? ("Sin IVA: " + formatModalNumber(otherPref, 4))
                    : ("Con IVA: " + formatModalNumber(otherPref, 4));
            } else {
                billEls.linePrefHint.textContent = "";
            }
        }
        updateLineModalRuleInfo();
        updateLineModalFinalAmount();
        lm.syncing = false;
    }

    function updateLineModalFinalAmount() {
        if (!billEls.lineFinalAmount) return;
        var draft = lineModalDraftForDiscount();
        if (!draft) {
            billEls.lineFinalAmount.value = formatMoney(0);
            return;
        }
        billEls.lineFinalAmount.value = formatMoney(lineFigures(draft).lineNet);
    }

    function updateLineModalRuleInfo() {
        var lm = state.lineModal;
        if (!billEls.lineRuleInfo) return;
        var parts = [];
        if (lm.catalogP != null) parts.push("P catálogo: " + formatMoney(lm.catalogP));
        if (lm.unitario0 != null) parts.push("unitario0: " + formatUnitPrice(lm.unitario0));
        if (lm.ruleTotal != null) parts.push("T_final: " + formatMoney(lm.ruleTotal));
        if (parts.length) {
            billEls.lineRuleInfo.hidden = false;
            billEls.lineRuleInfo.textContent = parts.join(" · ");
        } else {
            billEls.lineRuleInfo.hidden = true;
            billEls.lineRuleInfo.textContent = "";
        }
        if (billEls.linePreview) {
            if (lm.mode === "ref") {
                billEls.linePreview.textContent = "Vista previa con P de referencia: unitario "
                    + formatUnitPrice(lm.unitGross) + " · total " + formatMoney(lm.totalGross);
            } else if (lm.mode === "manual") {
                billEls.linePreview.textContent = "Precio final fijo (se respeta al cambiar cantidad).";
            } else {
                billEls.linePreview.textContent = "Precio automático de catálogo/regla.";
            }
        }
    }

    function lineModalFamilyQty() {
        var line = (getLines() || [])[state.lineModal.index];
        if (!line) return lineModalBillable();
        var gid = lineGrupoId(line);
        if (!gid) return lineModalBillable();
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        var lines = (getLines() || []).slice();
        var draft = {};
        Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
        draft.quantity = qty;
        lines[state.lineModal.index] = draft;
        var total = totalFamilyUnitsQuoted(gid, lines);
        return total > 0 ? total : 1;
    }

    function onLineModalQtyInput(format) {
        if (state.lineModal.syncing) return;
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : "0");
        if (qty < 0) qty = 0;
        if (format && billEls.lineQty) {
            billEls.lineQty.value = formatQty(qty);
        }
        if (state.lineModal.mode === "manual") {
            var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
            // En familia el total fijo es de todo el grupo; sin familia, de la línea.
            if (state.lineModal.isFamily && state.lineModal.totalGross != null && Number(state.lineModal.totalGross) > 0) {
                state.lineModal.unitGross = scale > 0
                    ? round4(Number(state.lineModal.totalGross) / scale)
                    : 0;
            } else {
                state.lineModal.totalGross = round2((Number(state.lineModal.unitGross) || 0) * scale);
            }
            refreshLineModalFields(false);
            syncLineModalDiscountFields("price_discount", false);
            return;
        }
        scheduleLineModalPreview();
        syncLineModalDiscountFields("price_discount", false);
    }

    function onLineModalPrefInput(format) {
        if (state.lineModal.syncing) return;
        var view = parseClNumber(billEls.linePref ? billEls.linePref.value : "0");
        state.lineModal.priceRef = round4(viewToBruto(view));
        if (format && billEls.linePref) {
            billEls.linePref.value = formatModalNumber(brutoToView(state.lineModal.priceRef), 4);
        }
        scheduleLineModalPreview();
    }

    function onLineModalUnitInput(format) {
        if (state.lineModal.syncing || state.lineModal.mode !== "manual") return;
        var view = parseClNumber(billEls.lineUnit ? billEls.lineUnit.value : "0");
        state.lineModal.unitGross = round4(viewToBruto(view));
        var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
        state.lineModal.totalGross = round2(state.lineModal.unitGross * scale);
        if (format) refreshLineModalFields(true);
        else refreshLineModalFields(false);
        syncLineModalDiscountFields("price_discount", false);
    }

    function onLineModalTotalInput(format) {
        if (state.lineModal.syncing || state.lineModal.mode !== "manual") return;
        var view = parseClNumber(billEls.lineTotal ? billEls.lineTotal.value : "0");
        state.lineModal.totalGross = round2(viewToBruto(view));
        var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
        state.lineModal.unitGross = scale > 0 ? round4(state.lineModal.totalGross / scale) : 0;
        if (format) refreshLineModalFields(true);
        else refreshLineModalFields(false);
        syncLineModalDiscountFields("price_discount", false);
    }

    function lineModalDraftForDiscount() {
        var line = (getLines() || [])[state.lineModal.index];
        if (!line) return null;
        var draft = {};
        Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        draft.quantity = qty;
        if (state.lineModal.mode === "manual") {
            draft.unit_price = Number(state.lineModal.unitGross) || 0;
            draft.price_mode = "manual";
            if (state.lineModal.isFamily) {
                draft.price_total = Number(state.lineModal.totalGross) || null;
            } else {
                draft.price_total = round2(draft.unit_price * lineBillableUnits(draft));
            }
        } else if (state.lineModal.unitGross != null) {
            draft.unit_price = Number(state.lineModal.unitGross) || Number(line.unit_price) || 0;
        }
        draft.price_discount = clampRate(parseClNumber(billEls.linePriceDiscount ? billEls.linePriceDiscount.value : "0"));
        draft.margin_discount = clampRate(parseClNumber(billEls.lineMarginDiscount ? billEls.lineMarginDiscount.value : "0"));
        if (billEls.lineDiscountAmount) {
            draft.discount_amount = round2(parseClNumber(billEls.lineDiscountAmount.value));
        } else {
            draft.discount_amount = round2(Number(line.discount_amount) || 0);
        }
        return draft;
    }

    function syncLineModalDiscountFields(source, format) {
        var draft = lineModalDraftForDiscount();
        if (!draft) return;
        if (source === "discount_amount") {
            draft.discount_amount = round2(parseClNumber(billEls.lineDiscountAmount ? billEls.lineDiscountAmount.value : "0"));
            syncEquivalentDiscounts(draft, "discount_amount");
        } else if (source === "margin_discount") {
            draft.margin_discount = clampRate(parseClNumber(billEls.lineMarginDiscount ? billEls.lineMarginDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "margin_discount");
        } else {
            draft.price_discount = clampRate(parseClNumber(billEls.linePriceDiscount ? billEls.linePriceDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "price_discount");
        }
        state.lineModal.syncing = true;
        if (billEls.linePriceDiscount && (format || source !== "price_discount" || document.activeElement !== billEls.linePriceDiscount)) {
            billEls.linePriceDiscount.value = formatPlain(draft.price_discount || 0);
        }
        if (billEls.lineMarginDiscount && (format || source !== "margin_discount" || document.activeElement !== billEls.lineMarginDiscount)) {
            if (lineHasUnitCost(draft)) {
                billEls.lineMarginDiscount.value = formatPlain(draft.margin_discount || 0);
            } else {
                billEls.lineMarginDiscount.value = "";
            }
        }
        if (billEls.lineDiscountAmount && (format || source !== "discount_amount" || document.activeElement !== billEls.lineDiscountAmount)) {
            billEls.lineDiscountAmount.value = formatPlain(draft.discount_amount || 0);
        }
        state.lineModal.syncing = false;
        updateLineModalDiscountHint(draft);
        updateLineModalFinalAmount();
    }

    function onLineModalDiscountInput(source, format) {
        if (state.lineModal.syncing) return;
        var draft = lineModalDraftForDiscount();
        if (source === "margin_discount" && draft && !lineHasUnitCost(draft)) {
            updateLineModalDiscountHint(draft);
            return;
        }
        syncLineModalDiscountFields(source, !!format);
    }

    function updateLineModalDiscountHint(draft) {
        var line = draft || lineModalDraftForDiscount() || {};
        var hasCost = lineHasUnitCost(line);
        if (billEls.lineMarginDiscount) {
            billEls.lineMarginDiscount.hidden = !hasCost;
            billEls.lineMarginDiscount.disabled = !hasCost;
        }
        if (billEls.lineMarginNa) {
            billEls.lineMarginNa.hidden = hasCost;
        }
        if (billEls.lineMarginHelp) {
            billEls.lineMarginHelp.hidden = hasCost;
            billEls.lineMarginHelp.title = "Costo no encontrado";
        }
        if (!billEls.lineDiscountHint) return;
        if (!hasCost) {
            billEls.lineDiscountHint.textContent = "Sin costo guardado: solo se puede editar el dscto precio.";
            return;
        }
        var bases = discountBases(line);
        if (!(bases.marginMoney > 0)) {
            billEls.lineDiscountHint.textContent = "Sin margen para equivaler.";
            return;
        }
        billEls.lineDiscountHint.textContent = "Dscto precio, margen y monto son el mismo descuento (equivalentes).";
    }

    function scheduleLineModalPreview() {
        if (state.lineModal.debounceTimer) {
            clearTimeout(state.lineModal.debounceTimer);
        }
        state.lineModal.debounceTimer = setTimeout(function () {
            state.lineModal.debounceTimer = null;
            fetchLineModalPreview();
        }, 280);
    }

    function fetchLineModalPreview() {
        var line = (getLines() || [])[state.lineModal.index];
        if (!line || !cfgProxy.actions || !cfgProxy.actions.familyPrice) {
            refreshLineModalFields(true);
            return;
        }
        var pb = Number(line.producto_base_id) || 0;
        var gid = lineGrupoId(line);
        if (gid) {
            pb = familyPriceBaseId(gid) || pb;
        }
        if (!pb) {
            refreshLineModalFields(true);
            return;
        }
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        var draft;
        var familyQty;
        if (gid) {
            // Preview with draft qty for this line only.
            var lines = (getLines() || []).slice();
            var draftLine = {};
            Object.keys(line).forEach(function (key) { draftLine[key] = line[key]; });
            draftLine.quantity = qty;
            lines[state.lineModal.index] = draftLine;
            familyQty = totalFamilyUnitsQuoted(gid, lines);
            draft = draftLine;
        } else {
            draft = {};
            Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
            draft.quantity = qty;
            familyQty = lineBillableUnits(draft);
        }
        if (!(familyQty > 0)) familyQty = 1;
        var payload = {
            producto_base_id: String(pb),
            family_qty: String(familyQty),
            rule_mode: state.lineModal.mode === "std" ? "std" : (state.lineModal.mode === "manual" ? "manual" : "auto")
        };
        if (state.lineModal.mode === "ref") {
            payload.rule_mode = "auto";
            var pref = Number(state.lineModal.priceRef);
            if (!(pref > 0) && billEls.linePref) {
                pref = round4(viewToBruto(parseClNumber(billEls.linePref.value)));
            }
            if (pref > 0) {
                payload.p_ref = String(pref);
                state.lineModal.priceRef = pref;
            }
        }
        var seq = ++state.lineModal.previewSeq;
        post(cfgProxy.actions.familyPrice, payload).then(function (data) {
            if (seq !== state.lineModal.previewSeq) return;
            applyLineModalPreviewData(data, draft, gid, familyQty);
        }).catch(function () {
            if (seq !== state.lineModal.previewSeq) return;
            refreshLineModalFields(true);
        });
    }

    function applyLineModalPreviewData(data, draft, gid, familyQty) {
        var lm = state.lineModal;
        if (data.p_asignado != null) lm.catalogP = Number(data.p_asignado);
        else if (data.pricing && data.pricing.p_asignado != null) lm.catalogP = Number(data.pricing.p_asignado);
        if (data.unitario0 != null) lm.unitario0 = Number(data.unitario0);
        else if (data.pricing && data.pricing.unitario0 != null) lm.unitario0 = Number(data.pricing.unitario0);
        var ruleTotal = data.rule_total != null
            ? Number(data.rule_total)
            : (data.pricing && data.pricing.rule_total != null ? Number(data.pricing.rule_total) : null);
        var ruleAdjusted = !!(data.rule_adjusted || (data.pricing && data.pricing.rule_adjusted));
        lm.ruleCodigo = data.assigned_rule_codigo || (data.pricing && data.pricing.assigned_rule_codigo) || null;
        lm.ruleNombre = data.rule_nombre || (data.pricing && data.pricing.rule_nombre) || null;
        lm.stdRule = data.std_rule || (data.pricing && data.pricing.std_rule) || null;
        lm.hasRule = !!(data.has_rule || (data.pricing && data.pricing.has_rule) || ruleAdjusted || lm.ruleCodigo
            || (data.unit_price != null && lm.catalogP != null && Number(data.unit_price) !== Number(lm.catalogP)));
        if (ruleAdjusted && ruleTotal != null) lm.ruleTotal = ruleTotal;
        else lm.ruleTotal = ruleTotal;
        if (lm.mode !== "manual") {
            lm.unitGross = data.unit_price != null ? Number(data.unit_price) : 0;
            if (ruleAdjusted && ruleTotal != null && gid) {
                // Approximate this line share for preview.
                var billable = lineBillableUnits(draft);
                lm.totalGross = familyQty > 0
                    ? round2(ruleTotal * billable / familyQty)
                    : round2(ruleTotal);
            } else if (ruleAdjusted && ruleTotal != null) {
                lm.totalGross = round2(ruleTotal);
            } else {
                lm.totalGross = round2(lm.unitGross * lineBillableUnits(draft));
            }
        }
        configureLineModalModes(lm.hasRule, !!gid, lm.hasLocalProduct);
        refreshLineModalFields(true);
    }

    function configureLineModalModes(hasRule, isFamily, hasLocalProduct) {
        var lm = state.lineModal;
        lm.hasRule = !!hasRule;
        lm.isFamily = !!isFamily;
        if (hasLocalProduct != null) lm.hasLocalProduct = !!hasLocalProduct;
        if (billEls.lineFamilyNote) {
            if (isFamily) {
                billEls.lineFamilyNote.hidden = false;
                billEls.lineFamilyNote.textContent = "Los cambios de precio aplican a toda la familia en este borrador.";
            } else {
                billEls.lineFamilyNote.hidden = true;
                billEls.lineFamilyNote.textContent = "";
            }
        }
        var showRadios = !!lm.hasLocalProduct;
        if (showRadios) {
            if (billEls.lineModeManualWrap) billEls.lineModeManualWrap.hidden = true;
            if (billEls.lineModeRadios) billEls.lineModeRadios.hidden = false;
        } else {
            if (billEls.lineModeManualWrap) billEls.lineModeManualWrap.hidden = false;
            if (billEls.lineModeRadios) billEls.lineModeRadios.hidden = true;
            if (lm.mode === "ref" || lm.mode === "std") lm.mode = "auto";
        }
        updateRuleModeLabels();
        if (billEls.lineModeManual) billEls.lineModeManual.checked = lm.mode === "manual";
        if (billEls.lineModeRadios) {
            Array.prototype.forEach.call(billEls.lineModeRadios.querySelectorAll('input[name="bill-line-mode"]'), function (radio) {
                radio.checked = radio.value === lm.mode;
            });
        }
        updateLineModalModeUi();
    }

    function updateRuleModeLabels() {
        var lm = state.lineModal;
        if (billEls.lineModeAutoLabel) {
            if (lm.ruleCodigo) {
                billEls.lineModeAutoLabel.textContent = "Usar regla (" + lm.ruleCodigo + ")";
            } else {
                billEls.lineModeAutoLabel.textContent = "Usar regla (sin regla: precio lista)";
            }
        }
        var std = lm.stdRule;
        var showStd = !!(std && std.codigo);
        if (showStd && lm.mode !== "std" && lm.ruleCodigo
            && String(lm.ruleCodigo).toUpperCase() === String(std.codigo).toUpperCase()) {
            showStd = false;
        }
        if (billEls.lineModeStdWrap) billEls.lineModeStdWrap.hidden = !showStd;
        if (billEls.lineModeStdLabel && std) {
            billEls.lineModeStdLabel.textContent = (std.nombre || "Regla estándar") + " (" + std.codigo + ")";
        }
        if (billEls.lineByAmount) {
            billEls.lineByAmount.hidden = !lm.hasLocalProduct;
        }
    }

    function openLineModal(index) {
        if (!billEls.lineModal) return;
        var line = (getLines() || [])[index];
        if (!line || false) return;
        var lm = state.lineModal;
        lm.index = index;
        lm.taxView = "bruto";
        lm.mode = linePriceMode(line);
        lm.hasRule = false;
        lm.isFamily = !!lineGrupoId(line);
        lm.hasLocalProduct = !!(line.producto_base_id);
        lm.catalogP = null;
        lm.unitario0 = null;
        lm.ruleTotal = line._rule_total != null ? Number(line._rule_total) : null;
        lm.ruleCodigo = null;
        lm.ruleNombre = null;
        lm.stdRule = null;
        lm.priceRef = line.price_ref != null ? Number(line.price_ref) : (Number(line.unit_price) || 0);
        lm.unitGross = Number(line.unit_price) || 0;
        if (lm.mode === "manual" && line.price_total != null) {
            lm.totalGross = Number(line.price_total);
        } else if (lm.isFamily && line._rule_adjusted && line._rule_total != null) {
            lm.totalGross = Number(line._rule_total);
        } else {
            var figures = lineFigures(line);
            if (lm.isFamily) {
                // Estimar total de familia desde unitario × unidades del grupo.
                var famQty = totalFamilyUnitsQuoted(lineGrupoId(line));
                lm.totalGross = round2(lm.unitGross * (famQty > 0 ? famQty : 1));
            } else {
                lm.totalGross = Number(figures.gross) || 0;
            }
        }
        if (billEls.lineSubtitle) {
            billEls.lineSubtitle.textContent = (line.sku || "") + (line.description ? (" — " + line.description) : "");
        }
        if (billEls.lineDesc) {
            billEls.lineDesc.value = line.description || "";
        }
        if (billEls.lineQty) {
            billEls.lineQty.value = formatQty(line.quantity);
        }
        if (billEls.lineQtyHint) {
            var pack = presentationLabel(line);
            billEls.lineQtyHint.textContent = pack || "";
        }
        if (billEls.linePriceDiscount) {
            billEls.linePriceDiscount.value = formatPlain(line.price_discount || 0);
        }
        if (billEls.lineMarginDiscount) {
            billEls.lineMarginDiscount.value = lineHasUnitCost(line)
                ? formatPlain(line.margin_discount || 0)
                : "";
        }
        if (billEls.lineDiscountAmount) {
            billEls.lineDiscountAmount.value = formatPlain(line.discount_amount || 0);
        }
        updateLineModalDiscountHint(line);
        updateLineModalFinalAmount();
        setLineTaxView("bruto");
        if (billEls.lineByAmountResult) billEls.lineByAmountResult.innerHTML = "";
        if (billEls.lineMonto) billEls.lineMonto.value = "";
        configureLineModalModes(false, lm.isFamily, lm.hasLocalProduct);
        setLineModalMode(lm.mode);
        billEls.lineModal.hidden = false;
        billEls.lineModal.setAttribute("aria-hidden", "false");
        scheduleLineModalPreview();
        if (billEls.lineQty) billEls.lineQty.focus();
    }

    function closeLineModal() {
        if (state.lineModal.debounceTimer) {
            clearTimeout(state.lineModal.debounceTimer);
            state.lineModal.debounceTimer = null;
        }
        state.lineModal.index = -1;
        if (!billEls.lineModal) return;
        billEls.lineModal.hidden = true;
        billEls.lineModal.setAttribute("aria-hidden", "true");
    }

    function applyLineModal() {
        var index = state.lineModal.index;
        var line = (getLines() || [])[index];
        if (!line) {
            closeLineModal();
            return;
        }
        var qty = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
        if (qty < 0) qty = 0;
        qty = round3(qty);
        if (!(qty > 0)) {
            billSetMessage("La cantidad debe ser mayor a cero.", true);
            return;
        }
        var descEdited = billEls.lineDesc ? String(billEls.lineDesc.value || "").trim() : "";
        if (descEdited) {
            line.description = descEdited;
        }
        var mode = state.lineModal.mode;
        var draft = lineModalDraftForDiscount();
        if (draft) {
            var priceSrc = clampRate(parseClNumber(billEls.linePriceDiscount ? billEls.linePriceDiscount.value : "0"));
            var marginSrc = clampRate(parseClNumber(billEls.lineMarginDiscount ? billEls.lineMarginDiscount.value : "0"));
            var amountSrc = round2(parseClNumber(billEls.lineDiscountAmount ? billEls.lineDiscountAmount.value : "0"));
            if (document.activeElement === billEls.linePriceDiscount) {
                draft.price_discount = priceSrc;
                syncEquivalentDiscounts(draft, "price_discount");
            } else if (document.activeElement === billEls.lineMarginDiscount) {
                draft.margin_discount = marginSrc;
                syncEquivalentDiscounts(draft, "margin_discount");
            } else if (amountSrc > 0) {
                draft.discount_amount = amountSrc;
                syncEquivalentDiscounts(draft, "discount_amount");
            } else if (priceSrc > 0) {
                draft.price_discount = priceSrc;
                syncEquivalentDiscounts(draft, "price_discount");
            } else if (marginSrc > 0) {
                draft.margin_discount = marginSrc;
                syncEquivalentDiscounts(draft, "margin_discount");
            } else {
                draft.price_discount = 0;
                draft.margin_discount = 0;
                draft.discount_amount = 0;
            }
            line.price_discount = clampRate(draft.price_discount);
            line.margin_discount = lineHasUnitCost(draft) ? clampRate(draft.margin_discount) : 0;
            line.discount_amount = draft.discount_amount || 0;
        } else {
            line.price_discount = 0;
            line.margin_discount = 0;
            syncDiscountAmount(line, "price_discount");
        }
        line.quantity = qty;

        var gid = lineGrupoId(line);
        var unitPrice = Number(state.lineModal.unitGross) || 0;
        var totalGross = Number(state.lineModal.totalGross) || 0;
        var priceRef = Number(state.lineModal.priceRef) || 0;

        if (gid) {
            if (mode === "manual") {
                applyPriceModeToGroup(gid, "manual", {
                    unit_price: unitPrice,
                    price_total: round2(totalGross)
                });
            } else if (mode === "ref") {
                applyPriceModeToGroup(gid, "ref", { price_ref: priceRef });
            } else if (mode === "std") {
                applyPriceModeToGroup(gid, "std", {});
            } else {
                applyPriceModeToGroup(gid, "auto", {});
            }
            closeLineModal();
            trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                scheduleDraftSave(0);
            });
            billSetMessage("Línea actualizada (aplica a la familia).", false);
            return;
        }

        line.price_mode = mode;
        if (mode === "manual") {
            line.unit_price = unitPrice;
            line.price_total = round2(unitPrice * lineBillableUnits(line));
            line.price_ref = null;
            line._rule_adjusted = false;
            line._rule_total = null;
            line.rule_adjusted = false;
            line.rule_total = null;
        } else if (mode === "ref") {
            line.price_ref = priceRef;
            line.price_total = null;
        } else {
            line.price_ref = null;
            line.price_total = null;
        }
        closeLineModal();
        if (line.producto_base_id && mode !== "manual") {
            trackPriceRecalc(recalcLocalLinePrice(line, index)).then(function () {
                scheduleDraftSave(0);
            });
        } else {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            scheduleDraftSave(0);
        }
        billSetMessage("Línea actualizada.", false);
    }

    function openFamilyModal(index) {
        if (!billEls.familyModal) return;
        var line = (getLines() || [])[index];
        if (!line || false) return;
        var fam = line._family || {};
        var members = Array.isArray(fam.members) ? fam.members : [];
        if (!members.length) {
            billSetMessage("Esta línea no tiene miembros de familia para cambiar.", true);
            return;
        }
        state.familyLineIndex = index;
        if (billEls.familyTitle) {
            billEls.familyTitle.textContent = fam.family_name
                ? ("Cambiar: " + fam.family_name)
                : "Cambiar presentación";
        }
        if (billEls.familyHint) {
            billEls.familyHint.textContent = "SKU actual: " + (line.sku || "—")
                + ". Elegir reemplaza esta línea; Agregar suma 1 unidad. Sin SKU Local no se puede elegir ni agregar.";
        }
        renderFamilyMembers(line, members);
        updateFamilyOpenAdminButton(line);
        billEls.familyModal.hidden = false;
        billEls.familyModal.setAttribute("aria-hidden", "false");
    }

    function closeFamilyModal() {
        if (!billEls.familyModal) return;
        billEls.familyModal.hidden = true;
        billEls.familyModal.setAttribute("aria-hidden", "true");
        state.familyLineIndex = -1;
        if (billEls.familyMembers) {
            billEls.familyMembers.innerHTML = "";
        }
        if (billEls.familyOpenAdmin) {
            billEls.familyOpenAdmin.disabled = true;
            billEls.familyOpenAdmin.dataset.grupoId = "";
        }
    }

    function familyGrupoIdFromLine(line) {
        if (!line) return 0;
        var fam = line._family || {};
        if (fam.grupo_id != null && fam.grupo_id !== "") {
            return Number(fam.grupo_id) || 0;
        }
        return Number(line.grupo_id) || 0;
    }

    function updateFamilyOpenAdminButton(line) {
        if (!billEls.familyOpenAdmin) return;
        var gid = familyGrupoIdFromLine(line);
        billEls.familyOpenAdmin.dataset.grupoId = gid > 0 ? String(gid) : "";
        billEls.familyOpenAdmin.disabled = !(gid > 0);
        billEls.familyOpenAdmin.title = gid > 0
            ? "Abrir editor de familia en Categorías → Familias"
            : "Sin grupo de familia para abrir";
    }

    function productsQuickSearchUrl(line) {
        var sku = encodeURIComponent(String((line && line.sku) || ""));
        return "/wp-admin/admin.php?page=riverso-pos-products&quick=" + sku;
    }

    function openFamilyInAdmin() {
        if (!billEls.familyOpenAdmin || billEls.familyOpenAdmin.disabled) return;
        var gid = Number(billEls.familyOpenAdmin.dataset.grupoId || 0);
        if (!(gid > 0)) {
            billSetMessage("No hay familia asociada para abrir.", true);
            return;
        }
        var base = ((host && host.cfg && host.cfg.adminUrl) || "").replace(/\?.*$/, "");
        if (!base) {
            base = (window.ajaxurl || "/wp-admin/admin-ajax.php").replace(/admin-ajax\.php.*$/, "admin.php");
        }
        if (base.indexOf("admin.php") === -1) {
            base = base.replace(/\/?$/, "/") + "admin.php";
        }
        var url = base + (base.indexOf("?") >= 0 ? "&" : "?")
            + "page=riverso-pos-categories&tab=families&grupo_id=" + encodeURIComponent(String(gid));
        window.open(url, "_blank", "noopener");
    }

    function memberHasLocalSku(member) {
        if (!member) return false;
        var sku = String(member.sku_local || member.sku || "").trim();
        // Sin código usable no hay SKU local, aunque flags vengan mal.
        if (sku === "" || sku === "?") return false;
        if (member.has_local_sku === false || member.es_local === false) return false;
        return true;
    }

    function memberDisplayName(member) {
        if (!member) return "?";
        var sku = String(member.sku_local || member.sku || "").trim();
        var desc = String(member.description || member.label || "").trim();
        if (sku && desc) return sku + " · " + desc;
        return sku || desc || "?";
    }

    function renderFamilyMembers(line, members) {
        if (!billEls.familyMembers) return;
        billEls.familyMembers.innerHTML = "";
        var currentPb = line.producto_base_id != null ? Number(line.producto_base_id) : 0;
        var currentSku = String(line.sku || "").toLowerCase();
        members.forEach(function (member) {
            var li = document.createElement("li");
            li.className = "cq-result";
            var isCurrent = (currentPb > 0 && Number(member.producto_base_id) === currentPb)
                || (currentSku && String(member.sku || "").toLowerCase() === currentSku);
            if (isCurrent) {
                li.classList.add("is-current");
            }
            var hasLocal = memberHasLocalSku(member);
            var info = document.createElement("div");
            var sku = document.createElement("div");
            sku.className = "cq-result-sku";
            sku.textContent = (String(member.sku || "").trim() || "?") + " · " + (member.description || "");
            var meta = document.createElement("div");
            meta.className = "cq-result-meta";
            meta.textContent = member.es_unitario
                ? "Presentación: embolsado"
                : ("Presentación: " + (member.label || ("×" + formatPlain(member.cantidad_unidades || 1))));
            info.appendChild(sku);
            info.appendChild(meta);
            if (!hasLocal) {
                var warn = document.createElement("div");
                warn.className = "cq-result-meta cq-family-no-local";
                warn.textContent = "Sin SKU Local — no se puede elegir ni agregar";
                info.appendChild(warn);
                li.classList.add("cq-family-member-blocked");
            }
            li.appendChild(info);
            var actions = document.createElement("div");
            actions.className = "cq-family-actions";
            if (isCurrent) {
                var badge = document.createElement("span");
                badge.className = "cq-family-current";
                badge.textContent = "Actual";
                actions.appendChild(badge);
            } else {
                var pick = document.createElement("button");
                pick.type = "button";
                pick.className = "cq-btn cq-btn-primary";
                pick.textContent = "Elegir";
                pick.disabled = !hasLocal;
                pick.title = hasLocal
                    ? "Reemplazar esta línea con la presentación elegida"
                    : "Sin SKU Local: no se puede elegir";
                pick.addEventListener("click", function () {
                    if (!memberHasLocalSku(member)) {
                        billSetMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
                        return;
                    }
                    swapFamilyMember(state.familyLineIndex, member);
                });
                actions.appendChild(pick);
            }
            var addBtn = document.createElement("button");
            addBtn.type = "button";
            addBtn.className = "cq-btn";
            addBtn.textContent = "Agregar";
            addBtn.disabled = !hasLocal;
            addBtn.title = hasLocal
                ? "Agregar 1 unidad de esta presentación"
                : "Sin SKU Local: no se puede agregar";
            addBtn.addEventListener("click", function () {
                addFamilyMemberLine(member);
            });
            actions.appendChild(addBtn);
            li.appendChild(actions);
            billEls.familyMembers.appendChild(li);
        });
    }

    function addFamilyMemberLine(member) {
        if (!memberHasLocalSku(member)) {
            billSetMessage("No se puede agregar «" + memberDisplayName(member) + "»: sin SKU Local.", true);
            return;
        }
        var source = (getLines() || [])[state.familyLineIndex] || {};
        var fam = source._family || {};
        var upp = Number(member.cantidad_unidades || 1);
        if (!(upp > 0)) upp = 1;
        var mode = member.es_unitario ? "unitaria" : "pack";
        var pb = member.producto_base_id != null ? Number(member.producto_base_id) : 0;
        var sku = String(member.sku || "").trim();
        var lines = getLines() || [];
        var existing = lines.find(function (line) {
            if (pb > 0 && Number(line.producto_base_id) === pb) return true;
            return sku !== "" && String(line.sku || "").toLowerCase() === sku.toLowerCase();
        });
        closeFamilyModal();
        if (existing) {
            existing.quantity = round3(Number(existing.quantity || 0) + 1);
            if (existing.producto_base_id) {
                var idx = lines.indexOf(existing);
                trackPriceRecalc(recalcLocalLinePrice(existing, idx >= 0 ? idx : 0)).then(function () {
                    scheduleDraftSave(0);
                });
            } else {
                renderBoletaEditor();
                renderBoletaEditorTotals();
                scheduleDraftSave(0);
            }
            billSetMessage("Se sumó 1 a «" + (existing.sku || memberDisplayName(member)) + "».", false);
            return;
        }
        addCatalogProduct({
            product_id: member.product_id != null ? member.product_id : null,
            producto_base_id: pb > 0 ? pb : null,
            sku: sku,
            description: member.description || sku || "",
            unit_price: Number(source.unit_price || 0),
            unit_cost: source.unit_cost != null ? source.unit_cost : null,
            quantity: 1,
            units_per_pack: upp,
            family_mode: mode,
            packaging: mode,
            local_only: !member.product_id,
            family: fam,
            price_mode: linePriceMode(source),
            price_ref: source.price_ref != null ? source.price_ref : null,
            price_total: source.price_total != null ? source.price_total : null
        });
    }

    function swapFamilyMember(index, member) {
        var lines = getLines() || [];
        var line = lines[index];
        if (!line || !member) return;
        if (!memberHasLocalSku(member)) {
            billSetMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
            return;
        }
        var newSkuRaw = String(member.sku_local || member.sku || "").trim();
        if (!newSkuRaw) {
            billSetMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
            return;
        }
        var newSku = newSkuRaw.toLowerCase();
        var newPb = member.producto_base_id != null ? Number(member.producto_base_id) : 0;
        var dupIndex = -1;
        lines.forEach(function (other, i) {
            if (i === index || dupIndex >= 0) return;
            var sameSku = newSku && String(other.sku || "").toLowerCase() === newSku;
            var samePb = newPb > 0 && Number(other.producto_base_id) === newPb;
            if (sameSku || samePb) {
                dupIndex = i;
            }
        });
        var keepQty = Number(line.quantity || 0);
        var priceDiscount = Number(line.price_discount || 0);
        var marginDiscount = Number(line.margin_discount || 0);
        var discountAmount = Number(line.discount_amount || 0);
        var fam = line._family || {};
        if (dupIndex >= 0) {
            lines[dupIndex].quantity = round3(Number(lines[dupIndex].quantity || 0) + keepQty);
            lines.splice(index, 1);
            closeFamilyModal();
            var target = lines[dupIndex > index ? dupIndex - 1 : dupIndex];
            if (target && target.producto_base_id) {
                trackPriceRecalc(recalcLocalLinePrice(target, dupIndex > index ? dupIndex - 1 : dupIndex)).then(function () {
                    scheduleDraftSave(0);
                });
            } else {
                renderBoletaEditor();
                renderBoletaEditorTotals();
                scheduleDraftSave(0);
            }
            billSetMessage("Se sumó la cantidad al SKU ya presente en el borrador.", false);
            return;
        }
        line.producto_base_id = newPb > 0 ? newPb : null;
        line.product_id = member.product_id != null ? member.product_id : null;
        // Nunca conservar el SKU anterior: provoca envase distinto con el mismo código.
        line.sku = newSkuRaw;
        line.description = member.description || newSkuRaw;
        line.units_per_pack = Number(member.cantidad_unidades || 1) > 0 ? Number(member.cantidad_unidades) : 1;
        line.family_mode = member.es_unitario ? "unitaria" : "pack";
        line.packaging = line.family_mode;
        line.local_only = !line.product_id;
        line.price_discount = priceDiscount;
        line.margin_discount = marginDiscount;
        line.discount_amount = discountAmount;
        line._family = fam;
        // Conservar modo de precio del grupo (ya está en la línea).
        closeFamilyModal();
        if (line.producto_base_id) {
            trackPriceRecalc(recalcLocalLinePrice(line, index)).then(function () {
                scheduleDraftSave(0);
            });
        } else {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            scheduleDraftSave(0);
        }
        billSetMessage("Presentación cambiada a «" + newSkuRaw + "».", false);
    }

    function applyFamilyPriceResult(line, data) {
        if (!line || !data) return;
        if (data.unit_price != null) {
            line.unit_price = Number(data.unit_price);
        }
        if (data.pricing && data.pricing.unit_cost != null) {
            line.unit_cost = Number(data.pricing.unit_cost);
        }
        if (data.rule_total != null) {
            line._rule_total = Number(data.rule_total);
        } else if (data.pricing && data.pricing.rule_total != null) {
            line._rule_total = Number(data.pricing.rule_total);
        } else {
            line._rule_total = null;
        }
        line._rule_adjusted = !!(data.rule_adjusted
            || (data.pricing && data.pricing.rule_adjusted));
        if (!line._rule_adjusted) {
            line._rule_total = null;
            line.rule_total = null;
            line.rule_adjusted = false;
        } else {
            line.rule_total = line._rule_total;
            line.rule_adjusted = true;
        }
        if (data.family) {
            line._family = data.family;
            if (data.family.units_per_pack != null && !(Number(line.units_per_pack) > 0)) {
                line.units_per_pack = Number(data.family.units_per_pack);
            }
            var member = currentFamilyMember(line);
            if (member && member.cantidad_unidades != null) {
                line.units_per_pack = Number(member.cantidad_unidades) > 0 ? Number(member.cantidad_unidades) : 1;
            }
        }
    }

    function familyPriceBaseId(grupoId, lines, indexes) {
        lines = lines || getLines() || [];
        indexes = indexes || familyLineIndexes(grupoId, lines);
        var unitPb = 0;
        indexes.forEach(function (idx) {
            if (unitPb) return;
            var line = lines[idx];
            var fam = line && line._family ? line._family : {};
            if (fam.unitaria && fam.unitaria.unit_producto_base_id) {
                unitPb = Number(fam.unitaria.unit_producto_base_id) || 0;
            }
        });
        if (unitPb) return unitPb;
        indexes.forEach(function (idx) {
            if (unitPb) return;
            if (lineIsUnitario(lines[idx])) {
                unitPb = Number(lines[idx].producto_base_id) || 0;
            }
        });
        if (unitPb) return unitPb;
        return indexes.length ? (Number(lines[indexes[0]].producto_base_id) || 0) : 0;
    }

    function recalcFamilyPrices(grupoId) {
        if (!grupoId || !cfgProxy.actions || !cfgProxy.actions.familyPrice) {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }
        var lines = getLines() || [];
        var indexes = familyLineIndexes(grupoId, lines);
        if (!indexes.length) {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }
        var mode = familyGroupPriceMode(grupoId, lines);
        var familyQty = totalFamilyUnitsQuoted(grupoId, lines);
        if (!(familyQty > 0)) {
            familyQty = 1;
        }

        if (mode === "manual") {
            var sharedUnit = null;
            var sharedTotal = null;
            indexes.forEach(function (idx) {
                var line = lines[idx];
                if (!line) return;
                if (sharedUnit == null && line.unit_price != null) {
                    sharedUnit = Number(line.unit_price);
                }
                if (sharedTotal == null && line.price_total != null) {
                    sharedTotal = Number(line.price_total);
                }
            });
            if (sharedTotal == null && sharedUnit != null) {
                sharedTotal = round2(sharedUnit * familyQty);
            } else if (sharedTotal != null && familyQty > 0) {
                // Total fijo de familia: el unitario se deriva de T / Q.
                sharedUnit = round4(sharedTotal / familyQty);
            }
            indexes.forEach(function (idx) {
                var line = lines[idx];
                if (!line) return;
                line.price_mode = "manual";
                if (sharedUnit != null) line.unit_price = sharedUnit;
                if (sharedTotal != null) {
                    line.price_total = sharedTotal;
                    line._rule_total = sharedTotal;
                    line._rule_adjusted = true;
                    line.rule_total = sharedTotal;
                    line.rule_adjusted = true;
                } else {
                    line._rule_total = null;
                    line._rule_adjusted = false;
                    line.rule_total = null;
                    line.rule_adjusted = false;
                }
                line.price_ref = null;
            });
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }

        var priceBaseId = familyPriceBaseId(grupoId, lines, indexes);
        if (!priceBaseId) {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }
        var seq = ++state.familyPriceSeq;
        var payload = {
            producto_base_id: String(priceBaseId),
            family_qty: String(familyQty),
            rule_mode: mode === "std" ? "std" : (mode === "manual" ? "manual" : "auto")
        };
        if (mode === "ref") {
            payload.rule_mode = "auto";
            var pref = null;
            indexes.forEach(function (idx) {
                if (pref != null) return;
                if (lines[idx] && lines[idx].price_ref != null) {
                    pref = Number(lines[idx].price_ref);
                }
            });
            if (pref != null && pref > 0) {
                payload.p_ref = String(pref);
            }
        }
        return post(cfgProxy.actions.familyPrice, payload).then(function (data) {
            if (seq !== state.familyPriceSeq) return;
            var sharedPrice = data.unit_price != null ? Number(data.unit_price) : null;
            var sharedCost = data.pricing && data.pricing.unit_cost != null
                ? Number(data.pricing.unit_cost)
                : null;
            var ruleTotal = data.rule_total != null
                ? Number(data.rule_total)
                : (data.pricing && data.pricing.rule_total != null
                    ? Number(data.pricing.rule_total)
                    : null);
            var ruleAdjusted = !!(data.rule_adjusted
                || (data.pricing && data.pricing.rule_adjusted));
            indexes.forEach(function (idx) {
                var line = lines[idx];
                if (!line) return;
                line.price_mode = mode;
                if (mode === "ref") {
                    line.price_ref = payload.p_ref != null ? Number(payload.p_ref) : line.price_ref;
                    line.price_total = null;
                } else {
                    line.price_ref = null;
                    line.price_total = null;
                }
                if (sharedPrice != null) {
                    line.unit_price = sharedPrice;
                }
                if (sharedCost != null) {
                    line.unit_cost = sharedCost;
                }
                line._rule_total = ruleAdjusted ? ruleTotal : null;
                line._rule_adjusted = ruleAdjusted;
                line.rule_total = ruleAdjusted ? ruleTotal : null;
                line.rule_adjusted = ruleAdjusted;
                if (data.family) {
                    line._family = data.family;
                    var member = currentFamilyMember(line);
                    if (member && member.cantidad_unidades != null) {
                        line.units_per_pack = Number(member.cantidad_unidades) > 0
                            ? Number(member.cantidad_unidades)
                            : 1;
                    }
                }
            });
            renderBoletaEditor();
            renderBoletaEditorTotals();
        }).catch(function () {
            if (seq !== state.familyPriceSeq) return;
            renderBoletaEditor();
            renderBoletaEditorTotals();
        });
    }

    function recalcAllFamilyGroups() {
        var seen = {};
        var gids = [];
        (getLines() || []).forEach(function (line) {
            var gid = lineGrupoId(line);
            if (gid && !seen[gid]) {
                seen[gid] = true;
                gids.push(gid);
            }
        });
        var chain = Promise.resolve();
        gids.forEach(function (gid) {
            chain = chain.then(function () {
                return recalcFamilyPrices(gid);
            });
        });
        return chain;
    }

    function recalcLocalLinePrice(line, index) {
        var pb = line && line.producto_base_id;
        if (!pb || !cfgProxy.actions || !cfgProxy.actions.familyPrice) {
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }
        var gid = lineGrupoId(line);
        if (gid) {
            return recalcFamilyPrices(gid);
        }
        if (linePriceMode(line) === "manual") {
            var packs = Number(line.quantity || 1);
            var upp = lineUnitsPerPack(line);
            if (!(upp > 0)) { upp = 1; }
            // Sin familia: el unitario manual se mantiene; el total sigue a la cantidad.
            line.price_total = round2((Number(line.unit_price) || 0) * packs * upp);
            line._rule_adjusted = false;
            line._rule_total = null;
            line.rule_adjusted = false;
            line.rule_total = null;
            renderBoletaEditor();
            renderBoletaEditorTotals();
            return Promise.resolve();
        }
        var packs2 = Number(line.quantity || 1);
        var upp2 = lineUnitsPerPack(line);
        if (!(upp2 > 0)) { upp2 = 1; }
        var mode = linePriceMode(line);
        var seq = ++state.familyPriceSeq;
        var payload = {
            producto_base_id: String(pb),
            family_qty: String(packs2 * upp2),
            rule_mode: mode === "std" ? "std" : "auto"
        };
        if (mode === "ref" && line.price_ref != null && Number(line.price_ref) > 0) {
            payload.p_ref = String(line.price_ref);
            payload.rule_mode = "auto";
        }
        return post(cfgProxy.actions.familyPrice, payload).then(function (data) {
            if (seq !== state.familyPriceSeq) return;
            applyFamilyPriceResult(line, data);
            renderBoletaEditor();
            renderBoletaEditorTotals();
        }).catch(function () {
            if (seq !== state.familyPriceSeq) return;
            renderBoletaEditor();
            renderBoletaEditorTotals();
        });
    }

    function formatByAmountOpt(opt, label, exact) {
        if (!opt) return "";
        var exactTxt = exact ? " · exacto" : "";
        return '<button type="button" class="cq-btn cq-btn-small cq-qty-opt" data-qty="' + String(opt.qty) + '">'
            + label + " <strong>" + formatQty(opt.qty) + " u.</strong> → Total "
            + formatPlain(opt.total) + " (" + formatPlain(opt.unitario) + " c/u)"
            + exactTxt + "</button>";
    }

    function renderByAmountResult(bt) {
        if (!billEls.lineByAmountResult) return;
        if (!bt) {
            billEls.lineByAmountResult.innerHTML = "";
            return;
        }
        if (!bt.debajo) {
            var msg = bt.minimo
                ? ("El monto no alcanza para 1 u. (mínimo " + formatPlain(bt.minimo.total) + ")")
                : "Sin tramo aplicable";
            billEls.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">' + msg + "</span>"
                + (bt.arriba ? " " + formatByAmountOpt(bt.arriba, "Opción:") : "");
            return;
        }
        var html = formatByAmountOpt(bt.debajo, "Entregar", !!bt.exacto);
        if (!bt.exacto && bt.arriba) {
            html += " " + formatByAmountOpt(bt.arriba, "o");
        }
        billEls.lineByAmountResult.innerHTML = html;
    }

    function runLineByAmount() {
        var line = (getLines() || [])[state.lineModal.index];
        if (!line || !cfgProxy.actions || !cfgProxy.actions.familyPrice) return;
        var montoView = parseClNumber(billEls.lineMonto ? billEls.lineMonto.value : "");
        if (!(montoView > 0)) {
            if (billEls.lineByAmountResult) {
                billEls.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Ingresa un monto válido</span>';
            }
            return;
        }
        var monto = round2(viewToBruto(montoView));
        var pb = Number(line.producto_base_id) || 0;
        var gid = lineGrupoId(line);
        if (gid) pb = familyPriceBaseId(gid) || pb;
        if (!pb) {
            if (billEls.lineByAmountResult) {
                billEls.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Sin producto local</span>';
            }
            return;
        }
        var upp = lineUnitsPerPack(line);
        if (!(upp > 0)) upp = 1;
        var others = 0;
        if (gid) {
            var total = totalFamilyUnitsQuoted(gid);
            var thisUnits = lineBillableUnits(line);
            var qtyDraft = parseClNumber(billEls.lineQty ? billEls.lineQty.value : line.quantity);
            if (qtyDraft > 0) thisUnits = round3(qtyDraft * upp);
            others = Math.max(0, total - thisUnits);
        }
        var mode = state.lineModal.mode;
        if (mode === "manual") {
            var unit = Number(state.lineModal.unitGross) || 0;
            if (!(unit > 0)) {
                renderByAmountResult({ debajo: null, arriba: null, exacto: false, minimo: null });
                return;
            }
            var packPrice = unit * upp;
            var k = Math.floor(monto / packPrice);
            var minimo = { qty: 1, total: round2(packPrice), unitario: unit };
            if (k < 1) {
                renderByAmountResult({ debajo: null, arriba: minimo, exacto: false, minimo: minimo });
                return;
            }
            var debajo = { qty: k, total: round2(packPrice * k), unitario: unit };
            var exacto = Math.abs(debajo.total - monto) < 0.01;
            var arriba = exacto ? null : { qty: k + 1, total: round2(packPrice * (k + 1)), unitario: unit };
            renderByAmountResult({ debajo: debajo, arriba: arriba, exacto: exacto, minimo: minimo });
            return;
        }
        if (billEls.lineByAmountResult) {
            billEls.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Calculando…</span>';
        }
        var payload = {
            producto_base_id: String(pb),
            family_qty: String(Math.max(1, others + upp)),
            target_total: String(monto),
            units_per_pack: String(upp),
            others_units: String(others),
            rule_mode: mode === "std" ? "std" : "auto"
        };
        if (mode === "ref") {
            var pref = Number(state.lineModal.priceRef);
            if (!(pref > 0) && billEls.linePref) {
                pref = round4(viewToBruto(parseClNumber(billEls.linePref.value)));
            }
            if (pref > 0) payload.p_ref = String(pref);
        }
        post(cfgProxy.actions.familyPrice, payload).then(function (data) {
            renderByAmountResult(data.by_total);
            if (data.std_rule) state.lineModal.stdRule = data.std_rule;
            if (data.assigned_rule_codigo !== undefined) state.lineModal.ruleCodigo = data.assigned_rule_codigo || null;
            updateRuleModeLabels();
        }).catch(function () {
            if (billEls.lineByAmountResult) {
                billEls.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Error al calcular</span>';
            }
        });
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

    /** Precio unitario: hasta 4 decimales si la regla ajustó el total. */
    function formatUnitPrice(value) {
        var number = Number(value) || 0;
        var scaled = Math.round(number * 10000);
        var decimals = 0;
        if (scaled % 10000 !== 0) {
            decimals = scaled % 100 !== 0 ? 4 : (scaled % 1000 !== 0 ? 3 : 2);
        }
        return new Intl.NumberFormat("es-CL", {
            style: "currency",
            currency: "CLP",
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    function formatPlain(value) {
        return new Intl.NumberFormat("es-CL", {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(Number(value) || 0);
    }

    function formatQty(value) {
        return new Intl.NumberFormat("es-CL", {
            minimumFractionDigits: 0,
            maximumFractionDigits: 3
        }).format(Number(value) || 0);
    }

    function formatPercent(value) {
        if (value === null || value === undefined || value === "") return "—";
        return new Intl.NumberFormat("es-CL", {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(Number(value)) + " %";
    }

    function formatDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || "");
        if (!match) return "";
        return match[3] + "-" + match[2] + "-" + match[1];
    }

    function toDateInputValue(value) {
        var match = /^(\d{4}-\d{2}-\d{2})/.exec(value || "");
        return match ? match[1] : "";
    }

    function todayIsoDate() {
        if (cfg.todayDate && /^\d{4}-\d{2}-\d{2}$/.test(cfg.todayDate)) {
            return cfg.todayDate;
        }
        var d = new Date();
        var y = d.getFullYear();
        var m = d.getMonth() + 1;
        var day = d.getDate();
        return y + "-" + (m < 10 ? "0" : "") + m + "-" + (day < 10 ? "0" : "") + day;
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


  function cacheEls() {
    function g(id) { return document.getElementById(id); }
    billEls = {
      lineModal: g("bill-line-modal"),
      familyModal: g("bill-family-modal"),
      familyMembers: g("bill-family-members"),
      familyTitle: g("bill-family-title"),
      familyHint: g("bill-family-hint"),
      familyOpenAdmin: g("bill-family-open-admin"),
      lineSubtitle: g("bill-line-subtitle"),
      lineDesc: g("bill-line-desc"),
      lineQty: g("bill-line-qty"),
      lineQtyHint: g("bill-line-qty-hint"),
      linePref: g("bill-line-pref"),
      linePrefHint: g("bill-line-pref-hint"),
      lineUnit: g("bill-line-unit"),
      lineUnitHint: g("bill-line-unit-hint"),
      lineTotal: g("bill-line-total"),
      lineTotalHint: g("bill-line-total-hint"),
      linePriceDiscount: g("bill-line-price-discount"),
      lineMarginDiscount: g("bill-line-margin-discount"),
      lineDiscountAmount: g("bill-line-discount-amount"),
      lineFinalAmount: g("bill-line-final-amount"),
      lineModeManual: g("bill-line-mode-manual"),
      lineModeManualWrap: g("bill-line-mode-manual-wrap"),
      lineModeRadios: g("bill-line-mode-radios"),
      lineModeAutoLabel: g("bill-line-mode-auto-label"),
      lineModeStdWrap: g("bill-line-mode-std-wrap"),
      lineModeStdLabel: g("bill-line-mode-std-label"),
      lineFamilyNote: g("bill-line-family-note"),
      lineRuleInfo: g("bill-line-rule-info"),
      lineByAmount: g("bill-line-by-amount"),
      lineMonto: g("bill-line-monto"),
      lineByAmountBtn: g("bill-line-by-amount-btn"),
      lineByAmountResult: g("bill-line-by-amount-result"),
      linePreview: g("bill-line-preview"),
      lineDiscountHint: g("bill-line-discount-hint"),
      lineUnitLabel: g("bill-line-unit-label"),
      lineTotalLabel: g("bill-line-total-label"),
      linePrefField: g("bill-line-pref-field"),
      lineUnitField: g("bill-line-unit-field"),
      lineTotalField: g("bill-line-total-field"),
      lineMarginField: g("bill-line-margin-field"),
      lineMarginNa: g("bill-line-margin-na"),
      lineMarginHelp: g("bill-line-margin-help"),
      lineTaxNeto: g("bill-line-tax-neto"),
      lineTaxBruto: g("bill-line-tax-bruto"),
      net: g("bill-boleta-net"),
      discount: g("bill-boleta-discount"),
      margin: g("bill-boleta-margin"),
      profit: g("bill-boleta-profit")
    };
  }

  function renderBoletaEditor() {
    var tbody = document.getElementById("bill-boleta-lines-body");
    if (!tbody) return;
    tbody.innerHTML = "";
    var lines = getLines();
    buildLineRenderPlan(lines).forEach(function (item) {
      if (item.type === "header") tbody.appendChild(familyHeaderRow(item));
      else tbody.appendChild(buildLineRow(item.line, item.index, true));
    });
    var empty = document.getElementById("bill-lines-empty");
    if (empty) empty.hidden = lines.length > 0;
    renderBoletaEditorTotals();
  }

  function renderBoletaEditorTotals() {
    (getLines() || []).forEach(function (line) {
      if (!line) return;
      var amount = round2(Number(line.discount_amount) || 0);
      var p = clampRate(line.price_discount);
      var m = clampRate(line.margin_discount);
      // Preferir monto en pesos; no rearmar desde el % de 2 decimales.
      if (amount > 0) syncEquivalentDiscounts(line, "discount_amount");
      else if (p > 0) syncEquivalentDiscounts(line, "price_discount");
      else if (m > 0) syncEquivalentDiscounts(line, "margin_discount");
    });
    var totals = calculate(getLines() || []);
    var net = 0, exento = 0, iva = 0, total = 0;
    (getLines() || []).forEach(function (line) {
      var fig = lineFigures(line);
      var lineBruto = Number(fig.lineNet) || 0;
      total += lineBruto;
      if (line.afecto !== false) {
        var lineNet = Math.round((lineBruto / BILL_IVA_FACTOR) * 100) / 100;
        net += lineNet;
        iva += Math.round((lineBruto - lineNet) * 100) / 100;
      } else {
        exento += lineBruto;
      }
      var qty = Number(line.quantity) || 1;
      line.unit_price_bruto = qty > 0 ? Math.round((lineBruto / qty) * 1e6) / 1e6 : 0;
      line.line_total_bruto = Math.round(lineBruto * 100) / 100;
    });
    if (billEls.net) billEls.net.textContent = formatMoney(Math.round(net * 100) / 100);
    var exEl = document.getElementById("bill-boleta-exento");
    if (exEl) exEl.textContent = formatMoney(Math.round(exento * 100) / 100);
    var ivaEl = document.getElementById("bill-boleta-iva");
    if (ivaEl) ivaEl.textContent = formatMoney(Math.round(iva * 100) / 100);
    var totEl = document.getElementById("bill-boleta-total");
    if (totEl) totEl.textContent = formatMoney(Math.round(total * 100) / 100);
    if (billEls.discount) billEls.discount.textContent = formatMoney(totals.discount_total);
    if (billEls.margin) billEls.margin.textContent = formatPercent(totals.margin_percent);
    if (billEls.profit) {
      billEls.profit.textContent = totals.profit_total === null ? "—" : formatMoney(totals.profit_total);
    }
    refreshLineFigures();
  }

  function addCatalogProduct(product) {
    var lines = getLines();
    var sku = String(product.sku || "").toLowerCase();
    var addQty = product.quantity != null && product.quantity !== "" ? Number(product.quantity) : 1;
    if (!(addQty > 0)) addQty = 1;
    var existing = lines.find(function (line) {
      return String(line.sku || "").toLowerCase() === sku && sku !== "";
    });
    if (existing) {
      existing.quantity = round3(Number(existing.quantity || 0) + addQty);
      if (product.unit_cost != null && product.unit_cost !== "") existing.unit_cost = Number(product.unit_cost);
    } else {
      var fam = product.family || {};
      var mode = product.family_mode || fam.default_mode || "unitaria";
      var upp = product.units_per_pack != null ? Number(product.units_per_pack)
        : (fam.units_per_pack != null ? Number(fam.units_per_pack) : 1);
      if (!(upp > 0)) upp = 1;
      var unit = Number(product.unit_price != null ? product.unit_price : product.unit_price_bruto) || 0;
      lines.push({
        product_id: product.product_id != null ? product.product_id : null,
        producto_base_id: product.producto_base_id != null ? product.producto_base_id : null,
        sku: product.sku,
        description: product.description || product.sku,
        quantity: round3(addQty),
        unit_price: unit,
        unit_price_bruto: unit,
        unit_cost: (product.unit_cost === null || product.unit_cost === undefined || product.unit_cost === "")
          ? null : Number(product.unit_cost),
        price_discount: 0, margin_discount: 0, discount_amount: 0,
        family_mode: mode,
        packaging: product.packaging || fam.packaging || mode,
        units_per_pack: upp,
        local_only: !!product.local_only || !product.product_id,
        _family: fam,
        price_mode: product.price_mode || "auto",
        price_ref: product.price_ref != null ? Number(product.price_ref) : null,
        price_total: product.price_total != null ? Number(product.price_total) : null,
        afecto: product.afecto !== false
      });
    }
    var target = existing || lines[lines.length - 1];
    if (target && target.producto_base_id) {
      var idx = lines.indexOf(target);
      return trackPriceRecalc(recalcLocalLinePrice(target, idx >= 0 ? idx : 0)).then(function () {
        scheduleDraftSave(0);
      });
    }
    renderBoletaEditor();
    scheduleDraftSave(0);
    return Promise.resolve();
  }

  function addManualLine(data) {
    data = data || {};
    var unit = Number(data.unit_price || data.unit_price_bruto || 0);
    var priceDiscount = clampRate(data.price_discount || 0);
    var line = {
      product_id: null, producto_base_id: null,
      sku: data.sku || "",
      description: data.description || "Ítem manual",
      quantity: round3(Number(data.quantity) > 0 ? Number(data.quantity) : 1),
      unit_price: unit, unit_price_bruto: unit, unit_cost: null,
      price_discount: priceDiscount, margin_discount: 0, discount_amount: 0,
      family_mode: "", packaging: "", units_per_pack: 1,
      local_only: true, _family: {},
      price_mode: "manual", price_ref: null, price_total: null,
      afecto: data.afecto !== false
    };
    if (priceDiscount > 0) {
      syncEquivalentDiscounts(line, "price_discount");
    }
    getLines().push(line);
    renderBoletaEditor();
    scheduleDraftSave(0);
  }

  function hydrateAfterLoad() {
    cacheEls();
    renderBoletaEditor();
    return recalcAllFamilyGroups().then(function () {
      if (host && host.isAdvanced && host.isAdvanced()) {
        return refreshLineStock();
      }
    });
  }

  function bindModals() {
    cacheEls();
    document.querySelectorAll("[data-bill-line-close]").forEach(function (el) {
      el.addEventListener("click", closeLineModal);
    });
    document.querySelectorAll("[data-bill-family-close]").forEach(function (el) {
      el.addEventListener("click", closeFamilyModal);
    });
    if (billEls.familyOpenAdmin) {
      billEls.familyOpenAdmin.addEventListener("click", openFamilyInAdmin);
    }
    var save = document.getElementById("bill-line-save");
    if (save) save.addEventListener("click", applyLineModal);
    if (billEls.lineTaxNeto) billEls.lineTaxNeto.addEventListener("click", function () { setLineTaxView("neto"); });
    if (billEls.lineTaxBruto) billEls.lineTaxBruto.addEventListener("click", function () { setLineTaxView("bruto"); });
    if (billEls.lineModeManual) {
      billEls.lineModeManual.addEventListener("change", function () {
        setLineModalMode(billEls.lineModeManual.checked ? "manual" : "auto");
      });
    }
    if (billEls.lineModeRadios) {
      billEls.lineModeRadios.addEventListener("change", function (e) {
        if (e.target && e.target.name === "bill-line-mode") setLineModalMode(e.target.value);
      });
    }
    if (billEls.lineByAmountBtn) {
      billEls.lineByAmountBtn.addEventListener("click", runLineByAmount);
    }
    if (billEls.lineMonto) {
      billEls.lineMonto.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          runLineByAmount();
        }
      });
    }
    if (billEls.lineByAmountResult) {
      billEls.lineByAmountResult.addEventListener("click", function (e) {
        var btn = e.target.closest(".cq-qty-opt");
        if (!btn) return;
        var qty = Number(btn.getAttribute("data-qty"));
        if (!(qty > 0)) return;
        Array.prototype.forEach.call(billEls.lineByAmountResult.querySelectorAll(".cq-qty-opt"), function (b) {
          b.classList.toggle("is-selected", b === btn);
        });
        if (billEls.lineQty) {
          billEls.lineQty.value = formatQty(qty);
        }
        onLineModalQtyInput(true);
        scheduleLineModalPreview();
      });
    }
    if (billEls.lineQty) {
      billEls.lineQty.addEventListener("input", onLineModalQtyInput);
      billEls.lineQty.addEventListener("blur", onLineModalQtyInput);
    }
    if (billEls.linePref) billEls.linePref.addEventListener("input", onLineModalPrefInput);
    if (billEls.lineUnit) billEls.lineUnit.addEventListener("input", onLineModalUnitInput);
    if (billEls.lineTotal) billEls.lineTotal.addEventListener("input", onLineModalTotalInput);
    if (billEls.linePriceDiscount) {
      billEls.linePriceDiscount.addEventListener("input", function () { syncLineModalDiscountFields("price_discount"); });
    }
    if (billEls.lineMarginDiscount) {
      billEls.lineMarginDiscount.addEventListener("input", function () { syncLineModalDiscountFields("margin_discount"); });
    }
    if (billEls.lineDiscountAmount) {
      billEls.lineDiscountAmount.addEventListener("input", function () { syncLineModalDiscountFields("discount_amount"); });
    }
  }

  function init(hostApi) {
    host = hostApi;
    bindModals();
  }

  global.RiversoBillingLines = {
    init: init,
    render: renderBoletaEditor,
    hydrateAfterLoad: hydrateAfterLoad,
    addCatalogProduct: addCatalogProduct,
    addManualLine: addManualLine,
    recalcAllFamilyGroups: recalcAllFamilyGroups,
    refreshLineStock: refreshLineStock,
    getCommercialTotals: function () {
      var net = 0, exento = 0, iva = 0, total = 0;
      (getLines() || []).forEach(function (line) {
        var fig = lineFigures(line);
        var lineBruto = Number(fig.lineNet) || 0;
        total += lineBruto;
        if (line.afecto !== false) {
          var lineNet = Math.round((lineBruto / BILL_IVA_FACTOR) * 100) / 100;
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
        total_amount: Math.round(total * 100) / 100
      };
    }
  };
})(window);
