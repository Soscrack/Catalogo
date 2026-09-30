(function () {
    var cfg = window.RIVERSO_CQ || {};
    var state = {
        view: "list",
        quotes: [],
        quote: emptyQuote(),
        snapshot: "",
        results: [],
        advanced: false,
        modalScope: "todo",
        modalResults: [],
        familyLineIndex: -1,
        familyPriceSeq: 0,
        importReceivedId: 0,
        invoicing: false
    };

    var els = {
        listView: document.getElementById("cq-list-view"),
        editorView: document.getElementById("cq-editor-view"),
        listBody: document.getElementById("cq-list-body"),
        empty: document.getElementById("cq-empty"),
        filter: document.getElementById("cq-status-filter"),
        typeFilter: document.getElementById("cq-type-filter"),
        dateFrom: document.getElementById("cq-date-from"),
        dateTo: document.getElementById("cq-date-to"),
        applyFilters: document.getElementById("cq-apply-filters"),
        listMessage: document.getElementById("cq-list-message"),
        title: document.getElementById("cq-editor-title"),
        status: document.getElementById("cq-status"),
        quoteNumber: document.getElementById("cq-quote-number"),
        issueDate: document.getElementById("cq-issue-date"),
        seller: document.getElementById("cq-seller"),
        customer: document.getElementById("cq-customer"),
        type: document.getElementById("cq-type"),
        channel: document.getElementById("cq-channel"),
        channelLocal: document.getElementById("cq-channel-local"),
        channelOnline: document.getElementById("cq-channel-online"),
        validityDays: document.getElementById("cq-validity-days"),
        validityTerms: document.getElementById("cq-validity-terms"),
        net: document.getElementById("cq-total-net"),
        discount: document.getElementById("cq-total-discount"),
        margin: document.getElementById("cq-total-margin"),
        profit: document.getElementById("cq-total-profit"),
        search: document.getElementById("cq-search"),
        lupa: document.getElementById("cq-lupa"),
        advanced: document.getElementById("cq-advanced"),
        results: document.getElementById("cq-results"),
        modal: document.getElementById("cq-advanced-modal"),
        modalQ: document.getElementById("cq-modal-q"),
        modalSearchBtn: document.getElementById("cq-modal-search-btn"),
        modalResults: document.getElementById("cq-modal-results"),
        modalHint: document.getElementById("cq-modal-hint"),
        modalClose: document.getElementById("cq-modal-close"),
        lines: document.getElementById("cq-lines"),
        linesEmpty: document.getElementById("cq-lines-empty"),
        message: document.getElementById("cq-message"),
        transition: document.getElementById("cq-transition"),
        invoice: document.getElementById("cq-invoice"),
        orderLink: document.getElementById("cq-order-link"),
        importBtn: document.getElementById("cq-import"),
        importModal: document.getElementById("cq-import-modal"),
        importQuoteList: document.getElementById("cq-import-quote-list"),
        importListEmpty: document.getElementById("cq-import-list-empty"),
        importStepList: document.getElementById("cq-import-step-list"),
        importStepPreview: document.getElementById("cq-import-step-preview"),
        importPreviewHead: document.getElementById("cq-import-preview-head"),
        importSkipped: document.getElementById("cq-import-skipped"),
        importLines: document.getElementById("cq-import-lines"),
        importCheckAll: document.getElementById("cq-import-check-all"),
        importBack: document.getElementById("cq-import-back"),
        importConfirm: document.getElementById("cq-import-confirm"),
        familyModal: document.getElementById("cq-family-modal"),
        familyTitle: document.getElementById("cq-family-title"),
        familyHint: document.getElementById("cq-family-hint"),
        familyMembers: document.getElementById("cq-family-members"),
        familyClose: document.getElementById("cq-family-close"),
        pdf: document.getElementById("cq-pdf"),
        options: document.getElementById("cq-options"),
        expiredBadge: document.getElementById("cq-expired-badge"),
        save: document.getElementById("cq-save"),
        clear: document.getElementById("cq-clear")
    };

    document.getElementById("cq-new").addEventListener("click", function () {
        openEditor(emptyQuote());
    });
    document.getElementById("cq-back").addEventListener("click", showList);
    document.getElementById("cq-search-btn").addEventListener("click", searchProducts);
    if (els.channelLocal) els.channelLocal.addEventListener("click", function () { setChannel("local", true); });
    if (els.channelOnline) els.channelOnline.addEventListener("click", function () { setChannel("online", true); });
    els.search.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
            searchProducts();
        }
    });
    if (els.lupa) {
        els.lupa.addEventListener("click", openAdvancedSearch);
    }
    if (els.modalSearchBtn) {
        els.modalSearchBtn.addEventListener("click", searchAdvanced);
    }
    if (els.modalQ) {
        els.modalQ.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                searchAdvanced();
            }
        });
    }
    if (els.modal) {
        els.modal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-modal-close") === "1") {
                closeAdvancedSearch();
            }
        });
        var chips = els.modal.querySelectorAll(".cq-chip[data-scope]");
        Array.prototype.forEach.call(chips, function (chip) {
            chip.addEventListener("click", function () {
                setModalScope(chip.getAttribute("data-scope") || "todo");
            });
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;
        if (els.familyModal && !els.familyModal.hidden) {
            event.preventDefault();
            closeFamilyModal();
            return;
        }
        if (els.modal && !els.modal.hidden) {
            event.preventDefault();
            closeAdvancedSearch();
        }
    });
    els.filter.addEventListener("change", loadList);
    if (els.typeFilter) {
        els.typeFilter.addEventListener("change", loadList);
    }
    if (els.applyFilters) {
        els.applyFilters.addEventListener("click", loadList);
    }
    if (els.dateFrom) {
        els.dateFrom.addEventListener("change", loadList);
    }
    if (els.dateTo) {
        els.dateTo.addEventListener("change", loadList);
    }
    if (els.advanced) {
        els.advanced.addEventListener("change", function () {
            state.advanced = els.advanced.checked;
            document.getElementById("riverso-cq").classList.toggle("is-advanced", state.advanced);
            renderLines();
            renderTotals();
            if (state.advanced) {
                refreshLineStock();
            }
        });
    }
    els.save.addEventListener("click", saveQuote);
    els.clear.addEventListener("click", clearQuote);
    els.transition.addEventListener("click", transitionQuote);
    if (els.invoice) {
        els.invoice.addEventListener("click", invoiceQuote);
    }
    if (els.importBtn) {
        els.importBtn.addEventListener("click", openImportModal);
    }
    if (els.importModal) {
        els.importModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-import-close") === "1") {
                closeImportModal();
            }
        });
    }
    if (els.importBack) {
        els.importBack.addEventListener("click", showImportListStep);
    }
    if (els.importConfirm) {
        els.importConfirm.addEventListener("click", confirmImportLines);
    }
    if (els.familyModal) {
        els.familyModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-family-close") === "1") {
                closeFamilyModal();
            }
        });
    }
    if (els.familyClose) {
        els.familyClose.addEventListener("click", closeFamilyModal);
    }
    if (els.importCheckAll) {
        els.importCheckAll.addEventListener("change", function () {
            var on = !!els.importCheckAll.checked;
            var boxes = els.importLines ? els.importLines.querySelectorAll("input[type=checkbox][data-item-id]") : [];
            Array.prototype.forEach.call(boxes, function (box) { box.checked = on; });
        });
    }
    if (els.pdf) {
        els.pdf.addEventListener("click", function () {
            setMessage("PDF de cotización: próximamente (stub P1b).", false);
        });
    }
    if (els.options) {
        els.options.addEventListener("click", function () {
            setMessage("Opciones de cotización: próximamente (stub P1b).", false);
        });
    }
    ["input", "change"].forEach(function (eventName) {
        els.customer.addEventListener(eventName, syncHeader);
        els.type.addEventListener(eventName, syncHeader);
        els.validityDays.addEventListener(eventName, syncHeader);
        els.validityTerms.addEventListener(eventName, syncHeader);
    });

    showList();

    function emptyQuote() {
        return {
            id: null,
            quote_number: "",
            customer_id: null,
            customer_name: "",
            quote_type: "venta",
            channel: "local",
            status: "draft",
            status_label: "Borrador",
            validity_days: null,
            validity_terms: "",
            net_total: 0,
            discount_total: 0,
            margin_percent: null,
            profit_total: null,
            created_at: "",
            issue_date: "",
            seller_name: cfg.currentUserName || "",
            is_expired: false,
            editable: true,
            allowed_transitions: [],
            can_invoice: false,
            order_id: null,
            order_url: "",
            lines: []
        };
    }

    function showList() {
        state.view = "list";
        els.listView.hidden = false;
        els.editorView.hidden = true;
        loadList();
    }

    function openEditor(quote) {
        // Defensa: algunos payloads legados usan items en vez de lines.
        if ((!quote.lines || !quote.lines.length) && quote.items && quote.items.length) {
            quote.lines = quote.items;
        }
        if (!quote.lines) {
            quote.lines = [];
        }
        state.quote = quote;
        state.view = "editor";
        els.listView.hidden = true;
        els.editorView.hidden = false;
        els.results.hidden = true;
        els.results.innerHTML = "";
        els.search.value = "";
        setMessage("");
        paintEditor();
        state.snapshot = serialize(state.quote);
        if (state.advanced) {
            refreshLineStock();
        }
    }

    function loadList() {
        var fields = {};
        if (els.filter && els.filter.value && els.filter.value !== "all") {
            fields.status = String(els.filter.value);
        }
        if (els.typeFilter && els.typeFilter.value && els.typeFilter.value !== "all") {
            fields.quote_type = String(els.typeFilter.value);
        }
        if (els.dateFrom && els.dateFrom.value) {
            fields.date_from = String(els.dateFrom.value);
        }
        if (els.dateTo && els.dateTo.value) {
            fields.date_to = String(els.dateTo.value);
        }
        setListMessage("");
        post(cfg.actions.list, fields).then(function (data) {
            state.quotes = data.quotes || [];
            renderList();
        }).catch(function (error) {
            state.quotes = [];
            renderList();
            setListMessage(error.message, true);
        });
    }

    function renderList() {
        var rows = state.quotes || [];
        els.listBody.innerHTML = "";
        els.empty.hidden = rows.length !== 0;
        rows.forEach(function (quote) {
            var tr = document.createElement("tr");
            if (quote.is_expired) {
                tr.className = "cq-row-expired";
            }
            var numCell = cell(quote.quote_number);
            if (quote.is_expired) {
                var warn = document.createElement("span");
                warn.className = "cq-expired-tag";
                warn.textContent = "Vencida";
                warn.title = "Validez vencida";
                numCell.appendChild(document.createTextNode(" "));
                numCell.appendChild(warn);
            }
            tr.appendChild(numCell);
            tr.appendChild(cell(formatDate(quote.issue_date || quote.created_at || quote.updated_at)));
            tr.appendChild(cell(quote.customer_name || "Sin cliente"));
            tr.appendChild(cell(quote.quote_type_label || "Venta"));
            tr.appendChild(badgeCell(quote.status, quote.status_label));
            var net = cell(formatMoney(quote.net_total));
            net.className = "cq-num";
            tr.appendChild(net);
            var util = cell(formatPercent(quote.margin_percent));
            util.className = "cq-num";
            tr.appendChild(util);
            var actions = document.createElement("td");
            var open = document.createElement("button");
            open.type = "button";
            open.className = "cq-text-btn";
            open.textContent = "Abrir";
            open.addEventListener("click", function () {
                openQuote(quote.id);
            });
            actions.appendChild(open);
            tr.appendChild(actions);
            els.listBody.appendChild(tr);
        });
    }

    function openQuote(id) {
        post(cfg.actions.get, { id: String(id) }).then(function (data) {
            openEditor(data.quote);
        }).catch(function (error) {
            setListMessage(error.message, true);
        });
    }

    function paintEditor() {
        var quote = state.quote;
        els.title.textContent = quote.quote_number || "Nueva cotización";
        els.status.textContent = quote.status_label || "Borrador";
        els.status.className = "cq-badge cq-badge-" + (quote.status || "draft");
        if (els.expiredBadge) {
            var expired = !!quote.is_expired;
            els.expiredBadge.hidden = !expired;
        }
        if (els.quoteNumber) {
            els.quoteNumber.value = quote.quote_number || "";
        }
        if (els.issueDate) {
            els.issueDate.value = formatDate(quote.issue_date || quote.created_at) || (quote.id ? "" : "Al guardar");
        }
        if (els.seller) {
            els.seller.value = quote.seller_name || cfg.currentUserName || "";
        }
        els.customer.value = quote.customer_name || "";
        els.type.value = quote.quote_type || "venta";
        setChannel(quote.channel || "local", false);
        els.validityDays.value = quote.validity_days === null || quote.validity_days === undefined ? "" : String(quote.validity_days);
        els.validityTerms.value = quote.validity_terms || "";
        var editable = quote.editable !== false;
        [els.customer, els.type, els.validityDays, els.validityTerms, els.search].forEach(function (input) {
            input.disabled = !editable;
        });
        if (els.channelLocal) els.channelLocal.disabled = !editable;
        if (els.channelOnline) els.channelOnline.disabled = !editable;
        document.getElementById("cq-search-btn").disabled = !editable;
        if (els.lupa) {
            els.lupa.disabled = !editable;
        }
        els.save.hidden = !editable;
        els.clear.hidden = !editable;
        var transition = (quote.allowed_transitions || [])[0];
        if (quote.id && transition) {
            els.transition.hidden = false;
            els.transition.textContent = transition.label;
            els.transition.dataset.status = transition.status;
        } else {
            els.transition.hidden = true;
        }
        if (els.invoice) {
            var canInvoice = !!quote.can_invoice || (quote.status === "listed" && quote.quote_type === "venta" && !quote.order_id);
            els.invoice.hidden = !(quote.id && canInvoice);
            els.invoice.disabled = false;
        }
        if (els.orderLink) {
            if (quote.order_id && quote.order_url) {
                els.orderLink.hidden = false;
                els.orderLink.href = quote.order_url;
                els.orderLink.textContent = "Ver pedido #" + quote.order_id;
            } else {
                els.orderLink.hidden = true;
                els.orderLink.removeAttribute("href");
            }
        }
        if (els.importBtn) {
            els.importBtn.hidden = !editable;
        }
        renderLines();
        renderTotals();
        if (quote.status === "invoiced") {
            setMessage("Esta cotización está facturada y no se edita en este corte.", false);
        }
    }

    function renderLines() {
        els.lines.innerHTML = "";
        var lines = state.quote.lines || [];
        els.linesEmpty.hidden = lines.length !== 0;
        var editable = state.quote.editable !== false;
        var plan = buildLineRenderPlan(lines);
        plan.forEach(function (item) {
            if (item.type === "header") {
                els.lines.appendChild(familyHeaderRow(item));
                return;
            }
            els.lines.appendChild(buildLineRow(item.line, item.index, editable));
        });
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
        lines = lines || state.quote.lines || [];
        var total = 0;
        lines.forEach(function (line) {
            if (lineGrupoId(line) === grupoId) {
                total += familyUnitsQuoted(line);
            }
        });
        return round3(total);
    }

    function familyLineIndexes(grupoId, lines) {
        lines = lines || state.quote.lines || [];
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
            groupIndexes.forEach(function (idx) {
                totalUnits += familyUnitsQuoted(lines[idx]);
            });
            var fam = line._family || {};
            plan.push({
                type: "header",
                grupoId: gid,
                familyName: fam.family_name || fam.family_code || ("Familia #" + gid),
                totalUnits: round3(totalUnits),
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
        td.colSpan = 10;
        var name = document.createElement("strong");
        name.textContent = item.familyName || "Familia";
        var meta = document.createElement("span");
        meta.className = "cq-family-header-meta";
        meta.textContent = "Lleva " + formatPlain(item.totalUnits) + " uds";
        td.appendChild(name);
        td.appendChild(meta);
        tr.appendChild(td);
        return tr;
    }

    function buildLineRow(line, index, editable) {
        var tr = document.createElement("tr");
        tr.className = "cq-line" + (lineGrupoId(line) ? " cq-line-in-family" : "");
        tr.dataset.index = String(index);
        var detail = document.createElement("td");
        var skuRow = document.createElement("div");
        skuRow.className = "cq-sku-row";
        var sku = document.createElement("span");
        sku.className = "cq-sku";
        sku.textContent = line.sku;
        skuRow.appendChild(sku);
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
        tr.appendChild(inputCell(line, index, "unit_price", editable));
        tr.appendChild(inputCell(line, index, "price_discount", editable));
        tr.appendChild(inputCell(line, index, "margin_discount", editable));
        var utility = document.createElement("td");
        utility.className = "cq-num cq-advanced cq-line-profit";
        tr.appendChild(utility);
        tr.appendChild(stockCell(line));
        tr.appendChild(confianzaCell(line));
        tr.appendChild(inventariarCell(line));
        applyStockRowAlarm(tr, line);
        var actions = document.createElement("td");
        actions.className = "cq-actions";
        if (editable) {
            var edit = document.createElement("button");
            edit.type = "button";
            edit.className = "cq-text-btn";
            edit.textContent = "Editar";
            edit.addEventListener("click", function () {
                tr.classList.add("is-editing");
                var price = tr.querySelector('input[data-field="unit_price"]');
                if (price) {
                    price.focus();
                    price.select();
                }
            });
            var remove = document.createElement("button");
            remove.type = "button";
            remove.className = "cq-text-btn";
            remove.textContent = "Eliminar";
            remove.addEventListener("click", function () {
                if (!window.confirm("¿Eliminar esta línea?")) {
                    return;
                }
                var gid = lineGrupoId(state.quote.lines[index] || line);
                state.quote.lines.splice(index, 1);
                if (gid) {
                    recalcFamilyPrices(gid);
                } else {
                    renderLines();
                    renderTotals();
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
        minus.setAttribute("aria-label", "Disminuir cantidad de " + line.sku);
        minus.disabled = !editable;
        var input = document.createElement("input");
        input.type = "text";
        input.inputMode = "decimal";
        input.dataset.field = "quantity";
        input.dataset.index = String(index);
        input.value = formatQty(line.quantity);
        input.setAttribute("aria-label", "Cantidad de " + line.sku);
        input.disabled = !editable;
        var plus = document.createElement("button");
        plus.type = "button";
        plus.className = "cq-stepper-btn";
        plus.textContent = "+";
        plus.setAttribute("aria-label", "Aumentar cantidad de " + line.sku);
        plus.disabled = !editable;
        function applyQty(next) {
            if (next < 0) next = 0;
            next = round3(next);
            state.quote.lines[index].quantity = next;
            if (state.quote.lines[index].producto_base_id) {
                input.value = formatQty(next);
                updateQtyMeta(wrap, state.quote.lines[index]);
                recalcLocalLinePrice(state.quote.lines[index], index);
                return;
            }
            input.value = formatQty(next);
            updateQtyMeta(wrap, state.quote.lines[index]);
            renderTotals();
        }
        minus.addEventListener("click", function () {
            applyQty(Number(state.quote.lines[index].quantity || 0) - 1);
        });
        plus.addEventListener("click", function () {
            applyQty(Number(state.quote.lines[index].quantity || 0) + 1);
        });
        input.addEventListener("focus", function () {
            input.value = String(state.quote.lines[index].quantity).replace(".", ",");
            input.select();
        });
        input.addEventListener("input", function () {
            state.quote.lines[index].quantity = parseClNumber(input.value);
            updateQtyMeta(wrap, state.quote.lines[index]);
            renderTotals();
        });
        input.addEventListener("blur", function () {
            var parsed = parseClNumber(input.value);
            state.quote.lines[index].quantity = parsed;
            input.value = formatQty(parsed);
            updateQtyMeta(wrap, state.quote.lines[index]);
            renderTotals();
        });
        wrap.appendChild(minus);
        wrap.appendChild(input);
        wrap.appendChild(plus);
        updateQtyMeta(wrap, line);
        td.appendChild(wrap);
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
        var input = document.createElement("input");
        input.type = "text";
        input.inputMode = "decimal";
        input.dataset.field = field;
        input.dataset.index = String(index);
        input.value = displayField(field, line);
        input.setAttribute("aria-label", fieldLabel(field) + line.sku);
        input.disabled = !editable;
        input.addEventListener("focus", function () {
            input.value = editField(field, state.quote.lines[index]);
            input.select();
        });
        input.addEventListener("input", function () {
            assignField(state.quote.lines[index], field, parseClNumber(input.value));
            renderTotals();
        });
        input.addEventListener("blur", function () {
            var parsed = parseClNumber(input.value);
            if (isRate) {
                parsed = clampRate(parsed);
            }
            assignField(state.quote.lines[index], field, parsed);
            input.value = displayField(field, state.quote.lines[index]);
            renderTotals();
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
        syncDiscountAmount(line, field);
    }

    function syncDiscountAmount(line, field) {
        var priceRate = clampRate(line.price_discount);
        var marginRate = clampRate(line.margin_discount);
        if (priceRate > 0 || marginRate > 0) {
            line.discount_amount = lineFigures(line).discount;
            return;
        }
        if (field === "price_discount" || field === "margin_discount") {
            line.discount_amount = 0;
        }
    }

    function syncHeader() {
        state.quote.customer_name = els.customer.value.trim();
        state.quote.quote_type = els.type.value;
        state.quote.channel = currentChannel();
        state.quote.validity_days = els.validityDays.value === "" ? null : Number(els.validityDays.value);
        state.quote.validity_terms = els.validityTerms.value.trim();
    }

    function renderTotals() {
        var totals = calculate(state.quote.lines || []);
        state.quote.net_total = totals.net_total;
        state.quote.discount_total = totals.discount_total;
        state.quote.margin_percent = totals.margin_percent;
        state.quote.profit_total = totals.profit_total;
        els.net.textContent = formatMoney(totals.net_total);
        els.discount.textContent = formatMoney(totals.discount_total);
        els.margin.textContent = formatPercent(totals.margin_percent);
        els.profit.textContent = totals.profit_total === null ? "—" : formatMoney(totals.profit_total);
        var level = state.advanced ? alarmLevel(totals.profit_total, totals.margin_percent) : "";
        setAlarm(els.margin, level);
        setAlarm(els.profit, level);
        refreshLineFigures();
    }

    function refreshLineFigures() {
        var rows = els.lines.querySelectorAll("tr.cq-line");
        Array.prototype.forEach.call(rows, function (tr) {
            var index = Number(tr.dataset.index);
            var line = (state.quote.lines || [])[index];
            var cell = tr.querySelector(".cq-line-profit");
            if (!line || !cell) return;
            paintUtility(cell, lineFigures(line));
        });
        refreshFamilyHeaders();
    }

    function refreshFamilyHeaders() {
        var headers = els.lines.querySelectorAll("tr.cq-family-header");
        if (!headers.length) return;
        var plan = buildLineRenderPlan(state.quote.lines || []);
        var headerItems = plan.filter(function (item) { return item.type === "header"; });
        Array.prototype.forEach.call(headers, function (tr, i) {
            var item = headerItems[i];
            if (!item) return;
            var meta = tr.querySelector(".cq-family-header-meta");
            if (meta) {
                meta.textContent = "Lleva " + formatPlain(item.totalUnits) + " uds";
            }
        });
    }

    function paintUtility(cell, figures) {
        var level = state.advanced ? alarmLevel(figures.lineProfit, figures.lineMargin) : "";
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


    function openAdvancedSearch() {
        if (!els.modal) return;
        if (state.quote.editable === false) return;
        var prefill = (els.search && els.search.value ? els.search.value : "").trim();
        if (els.modalQ) {
            els.modalQ.value = prefill;
        }
        setModalScope(state.modalScope || "todo");
        state.modalResults = [];
        renderModalResults();
        setModalHint("");
        els.modal.hidden = false;
        els.modal.setAttribute("aria-hidden", "false");
        if (els.modalQ) {
            els.modalQ.focus();
            els.modalQ.select();
        }
        // Prefill no bloqueante: si hay texto, busca; si falla o vacío, el modal queda usable.
        if (prefill) {
            searchAdvanced();
        }
    }

    function closeAdvancedSearch() {
        if (!els.modal) return;
        els.modal.hidden = true;
        els.modal.setAttribute("aria-hidden", "true");
        setModalHint("");
    }

    function setModalScope(scope) {
        if (scope !== "descripcion" && scope !== "codigos") {
            scope = "todo";
        }
        state.modalScope = scope;
        if (!els.modal) return;
        var chips = els.modal.querySelectorAll(".cq-chip[data-scope]");
        Array.prototype.forEach.call(chips, function (chip) {
            var active = chip.getAttribute("data-scope") === scope;
            chip.classList.toggle("is-active", active);
            chip.setAttribute("aria-selected", active ? "true" : "false");
        });
        if (els.modalQ) {
            if (scope === "descripcion") {
                els.modalQ.placeholder = "Descripción (mín. 2 caracteres)";
            } else if (scope === "codigos") {
                els.modalQ.placeholder = "SKU, proveedor o código de barras";
            } else {
                els.modalQ.placeholder = "Todo: descripción o códigos";
            }
        }
    }

    function setModalHint(text, isError) {
        if (!els.modalHint) return;
        els.modalHint.textContent = text || "";
        els.modalHint.classList.toggle("is-error", !!isError && !!text);
    }

    function searchAdvanced() {
        if (!els.modalQ) return;
        var query = els.modalQ.value.trim();
        var scope = state.modalScope || "todo";
        if (!query) {
            setModalHint("Ingresa un término de búsqueda.", true);
            state.modalResults = [];
            renderModalResults();
            return;
        }
        if ((scope === "descripcion" || scope === "todo") && scope === "descripcion" && query.length < 2) {
            setModalHint("Escribe al menos 2 caracteres para buscar por descripción.", true);
            state.modalResults = [];
            renderModalResults();
            return;
        }
        if (scope === "todo" && query.length < 2) {
            // Con <2 chars en Todo, solo códigos (el servidor igual puede devolver por códigos).
        }
        setModalHint("Buscando…");
        post(cfg.actions.search, {
            q: query,
            mode: "advanced",
            scope: scope,
            channel: currentChannel()
        }).then(function (data) {
            state.modalResults = data.products || [];
            renderModalResults();
            if (state.modalResults.length === 0) {
                setModalHint(data.hint || ("Sin resultados para «" + query + "»."), true);
            } else {
                setModalHint(state.modalResults.length + " resultado(s). Elige Agregar — el modal no agrega solo.");
            }
        }).catch(function (error) {
            setModalHint(error.message, true);
        });
    }

    function renderModalResults() {
        if (!els.modalResults) return;
        els.modalResults.innerHTML = "";
        (state.modalResults || []).forEach(function (product) {
            var li = document.createElement("li");
            var info = document.createElement("div");
            var sku = document.createElement("div");
            sku.className = "cq-result-sku";
            sku.textContent = product.sku + " · " + (product.description || "");
            var meta = document.createElement("div");
            meta.className = "cq-result-meta";
            meta.textContent = "Proveedor " + (product.supplier_code || "—") + " · Barras " + (product.barcode || "—");
            appendPriceHint(meta, product);
            info.appendChild(sku);
            info.appendChild(meta);
            var add = document.createElement("button");
            add.type = "button";
            add.className = "cq-btn";
            add.textContent = lacksLocalPrice(product) ? "Agregar ($0)" : "Agregar";
            add.addEventListener("click", function () {
                if (!addProduct(product)) {
                    setModalHint("No se agregó «" + (product.sku || "?") + "»: sin precio Local (cancelado).", true);
                    return;
                }
                setModalHint("Agregado «" + product.sku + "». Puedes seguir buscando.", false);
            });
            li.appendChild(info);
            li.appendChild(add);
            els.modalResults.appendChild(li);
        });
    }

    function isLocalProduct(product) {
        if (!product) return false;
        if (product.channel === "local") return true;
        if (product.producto_base_id) return true;
        return currentChannel() === "local";
    }

    /** Local sin p_asignado/regla usable: unit_price 0 es dato, no path distinto por código. */
    function lacksLocalPrice(product) {
        if (!isLocalProduct(product)) return false;
        if (product.sin_precio_local === true) return true;
        if (product.has_local_price === false) return true;
        var price = Number(product.unit_price);
        return !(price > 0);
    }

    function confirmAddWithoutLocalPrice(product) {
        var sku = (product && product.sku) ? product.sku : "?";
        return window.confirm(
            "«" + sku + "» no tiene precio Local asignado (sin precio Local / $0).\n" +
            "No se inventará un precio. ¿Agregar la línea a $0 de todos modos?"
        );
    }

    function appendPriceHint(metaEl, product) {
        if (!metaEl || !lacksLocalPrice(product)) return;
        var badge = document.createElement("span");
        badge.className = "cq-badge cq-badge-no-price";
        badge.textContent = "sin precio Local";
        badge.title = "get_local_price / familia no devolvió p_asignado usable; unit_price=0 es dato, no inventado.";
        metaEl.appendChild(document.createTextNode(" · "));
        metaEl.appendChild(badge);
    }

    function searchProducts() {
        var query = els.search.value.trim();
        if (!query) {
            setMessage("Ingresa un SKU, código proveedor o código de barras.", true);
            return;
        }
        setMessage("");
        post(cfg.actions.search, { q: query, mode: "quick", channel: currentChannel() }).then(function (data) {
            state.results = data.products || [];
            // Rápida: un solo resultado con precio Local se auto-agrega. Nunca auto-agregar a $0.
            if (state.results.length === 1) {
                var only = state.results[0];
                if (lacksLocalPrice(only)) {
                    renderResults();
                    setMessage("«" + (only.sku || "?") + "» sin precio Local. Confirma Agregar si quieres la línea a $0.", true);
                    return;
                }
                addProduct(only, { auto: true });
                els.search.value = "";
                els.results.hidden = true;
                return;
            }
            renderResults();
            if (state.results.length === 0) {
                setMessage("Sin resultados para «" + query + "».", true);
            }
        }).catch(function (error) {
            setMessage(error.message, true);
        });
    }

    function renderResults() {
        els.results.innerHTML = "";
        els.results.hidden = state.results.length === 0;
        state.results.forEach(function (product) {
            var li = document.createElement("li");
            var info = document.createElement("div");
            var sku = document.createElement("div");
            sku.className = "cq-result-sku";
            sku.textContent = product.sku + " · " + (product.description || "");
            var meta = document.createElement("div");
            meta.className = "cq-result-meta";
            meta.textContent = "Proveedor " + (product.supplier_code || "—") + " · Barras " + (product.barcode || "—");
            appendPriceHint(meta, product);
            info.appendChild(sku);
            info.appendChild(meta);
            var add = document.createElement("button");
            add.type = "button";
            add.className = "cq-btn";
            add.textContent = lacksLocalPrice(product) ? "Agregar ($0)" : "Agregar";
            add.addEventListener("click", function () {
                addProduct(product);
            });
            li.appendChild(info);
            li.appendChild(add);
            els.results.appendChild(li);
        });
    }

    function addProduct(product, opts) {
        opts = opts || {};
        if (lacksLocalPrice(product)) {
            // Nunca auto-agregar / agregar en silencio a $0 (HF 1.8.26).
            if (opts.auto) {
                return false;
            }
            if (!confirmAddWithoutLocalPrice(product)) {
                setMessage("No se agregó «" + (product.sku || "?") + "»: sin precio Local.", true);
                return false;
            }
        }
        var lines = state.quote.lines;
        var sku = String(product.sku || "").toLowerCase();
        var addQty = product.quantity !== undefined && product.quantity !== null && product.quantity !== ""
            ? Number(product.quantity)
            : 1;
        if (!(addQty > 0)) {
            addQty = 1;
        }
        var existing = lines.find(function (line) {
            return String(line.sku || "").toLowerCase() === sku && sku !== "";
        });
        if (existing) {
            existing.quantity = round3(Number(existing.quantity || 0) + addQty);
            if (product.unit_cost !== null && product.unit_cost !== undefined && product.unit_cost !== "") {
                existing.unit_cost = Number(product.unit_cost);
            }
        } else {
            var fam = product.family || {};
            var mode = product.family_mode || fam.default_mode || "unitaria";
            var upp = product.units_per_pack != null ? Number(product.units_per_pack) : (fam.units_per_pack != null ? Number(fam.units_per_pack) : 1);
            if (!(upp > 0)) { upp = 1; }
            lines.push({
                product_id: product.product_id != null ? product.product_id : null,
                producto_base_id: product.producto_base_id != null ? product.producto_base_id : null,
                sku: product.sku,
                supplier_code: product.supplier_code || "",
                barcode: product.barcode || "",
                description: product.description || product.sku,
                quantity: round3(addQty),
                unit_price: Number(product.unit_price || 0),
                unit_cost: product.unit_cost === null || product.unit_cost === undefined || product.unit_cost === "" ? null : Number(product.unit_cost),
                price_discount: 0,
                margin_discount: 0,
                discount_amount: 0,
                family_mode: mode,
                packaging: product.packaging || fam.packaging || mode,
                units_per_pack: upp,
                local_only: !!product.local_only || !product.product_id,
                _family: fam
            });
        }
        var target = existing || lines[lines.length - 1];
        els.results.hidden = true;
        if (target && target.producto_base_id) {
            var idx = lines.indexOf(target);
            recalcLocalLinePrice(target, idx >= 0 ? idx : 0);
        } else {
            renderLines();
            renderTotals();
        }
        setMessage(lacksLocalPrice(product)
            ? ("Producto agregado a $0 (sin precio Local): «" + (product.sku || "?") + "».")
            : "Producto agregado.", false);
        return true;
    }

    function saveQuote() {
        syncHeader();
        var payload = {
            id: state.quote.id,
            customer_id: state.quote.customer_id,
            customer_name: state.quote.customer_name,
            quote_type: state.quote.quote_type,
            channel: state.quote.channel || currentChannel(),
            validity_days: state.quote.validity_days,
            validity_terms: state.quote.validity_terms,
            lines: (state.quote.lines || []).map(function (line) {
                return {
                    product_id: line.product_id,
                    producto_base_id: line.producto_base_id != null ? line.producto_base_id : null,
                    sku: line.sku,
                    supplier_code: line.supplier_code || "",
                    barcode: line.barcode || "",
                    description: line.description || "",
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    unit_cost: line.unit_cost,
                    discount_amount: line.discount_amount || 0,
                    price_discount: line.price_discount || 0,
                    margin_discount: line.margin_discount || 0,
                    family_mode: line.family_mode || null,
                    packaging: line.packaging || null,
                    units_per_pack: line.units_per_pack != null ? line.units_per_pack : null
                };
            })
        };
        els.save.disabled = true;
        post(cfg.actions.save, { payload: JSON.stringify(payload) }).then(function (data) {
            state.quote = data.quote;
            paintEditor();
            state.snapshot = serialize(state.quote);
            setMessage(data.message || "Cotización guardada.", false);
        }).catch(function (error) {
            setMessage(error.message, true);
        }).finally(function () {
            els.save.disabled = false;
        });
    }

    function clearQuote() {
        if (isDirty() && !window.confirm("¿Limpiar la cotización? Se perderán los cambios no guardados.")) {
            return;
        }
        openEditor(emptyQuote());
        setMessage("Cotización limpia.", false);
    }

    function transitionQuote() {
        var target = els.transition.dataset.status;
        if (!state.quote.id || !target) {
            setMessage("Guarda la cotización antes de cambiar el estado.", true);
            return;
        }
        post(cfg.actions.transition, { id: String(state.quote.id), status: target }).then(function (data) {
            state.quote = data.quote;
            paintEditor();
            state.snapshot = serialize(state.quote);
            setMessage(data.message || "Estado actualizado.", false);
        }).catch(function (error) {
            setMessage(error.message, true);
        });
    }

    function isDirty() {
        syncHeader();
        return serialize(state.quote) !== state.snapshot;
    }

    function serialize(quote) {
        return JSON.stringify({
            id: quote.id,
            customer_name: quote.customer_name || "",
            quote_type: quote.quote_type,
            channel: quote.channel || "local",
            validity_days: quote.validity_days,
            validity_terms: quote.validity_terms || "",
            lines: quote.lines || []
        });
    }


    function invoiceQuote() {
        if (!state.quote.id || state.invoicing) {
            return;
        }
        if (state.quote.status !== "listed" || state.quote.quote_type !== "venta") {
            setMessage("Solo se facturan cotizaciones Lista de tipo Venta.", true);
            return;
        }
        var lines = state.quote.lines || [];
        var cliente = state.quote.customer_name || "Sin cliente";
        var neto = formatMoney(state.quote.net_total);
        var n = lines.length;
        var msg = "¿Facturar cotización " + (state.quote.quote_number || "") + "?\n"
            + "Cliente: " + cliente + "\n"
            + "Neto: " + neto + "\n"
            + "Líneas: " + n + "\n\n"
            + "Se creará un pedido WooCommerce pendiente.";
        if (!window.confirm(msg)) {
            return;
        }
        state.invoicing = true;
        if (els.invoice) {
            els.invoice.disabled = true;
        }
        setMessage("Facturando…", false);
        post(cfg.actions.invoice, { id: String(state.quote.id) }).then(function (data) {
            state.quote = data.quote;
            paintEditor();
            state.snapshot = serialize(state.quote);
            setMessage(data.message || "Cotización facturada.", false);
        }).catch(function (error) {
            setMessage(error.message, true);
            if (els.invoice) {
                els.invoice.disabled = false;
            }
        }).finally(function () {
            state.invoicing = false;
        });
    }

    function openImportModal() {
        if (state.quote.editable === false) {
            return;
        }
        if (!els.importModal) {
            return;
        }
        els.importModal.hidden = false;
        els.importModal.setAttribute("aria-hidden", "false");
        showImportListStep();
        loadReceivedQuotes();
    }

    function closeImportModal() {
        if (!els.importModal) {
            return;
        }
        els.importModal.hidden = true;
        els.importModal.setAttribute("aria-hidden", "true");
        state.importReceivedId = 0;
    }

    function showImportListStep() {
        state.importReceivedId = 0;
        if (els.importStepList) {
            els.importStepList.hidden = false;
        }
        if (els.importStepPreview) {
            els.importStepPreview.hidden = true;
        }
    }

    function loadReceivedQuotes() {
        if (!cfg.actions || !cfg.actions.receivedList) {
            return;
        }
        if (els.importQuoteList) {
            els.importQuoteList.innerHTML = "";
        }
        post(cfg.actions.receivedList, {}).then(function (data) {
            var quotes = data.quotes || [];
            if (els.importListEmpty) {
                els.importListEmpty.hidden = quotes.length !== 0;
            }
            if (!els.importQuoteList) {
                return;
            }
            els.importQuoteList.innerHTML = "";
            quotes.forEach(function (q) {
                var li = document.createElement("li");
                li.className = "cq-result";
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "cq-result-btn";
                var label = (q.numero_documento || ("#" + q.id))
                    + (q.proveedor_nombre ? " — " + q.proveedor_nombre : "")
                    + (q.fecha_documento ? " (" + q.fecha_documento + ")" : "");
                btn.textContent = label;
                btn.addEventListener("click", function () {
                    previewReceivedQuote(q.id, label);
                });
                li.appendChild(btn);
                els.importQuoteList.appendChild(li);
            });
        }).catch(function (error) {
            setMessage(error.message, true);
        });
    }

    function previewReceivedQuote(id, label) {
        state.importReceivedId = id;
        post(cfg.actions.receivedPreview, { id: String(id) }).then(function (data) {
            if (els.importStepList) {
                els.importStepList.hidden = true;
            }
            if (els.importStepPreview) {
                els.importStepPreview.hidden = false;
            }
            if (els.importPreviewHead) {
                els.importPreviewHead.textContent = "Vista previa: " + (label || ("#" + id));
            }
            var skipped = data.skipped || {};
            if (els.importSkipped) {
                var parts = [];
                if (skipped.ambiguous) {
                    parts.push(skipped.ambiguous + " ambiguas");
                }
                if (skipped.not_found) {
                    parts.push(skipped.not_found + " sin match");
                }
                if (skipped.no_wc) {
                    parts.push(skipped.no_wc + " sin WC");
                }
                els.importSkipped.textContent = parts.length
                    ? ("Omitidas automáticamente: " + parts.join(", ") + ".")
                    : "Todas las líneas con producto WC están disponibles.";
            }
            renderImportLines(data.lines || []);
        }).catch(function (error) {
            setMessage(error.message, true);
        });
    }

    function renderImportLines(lines) {
        if (!els.importLines) {
            return;
        }
        els.importLines.innerHTML = "";
        if (els.importCheckAll) {
            els.importCheckAll.checked = lines.length > 0;
        }
        lines.forEach(function (line) {
            var tr = document.createElement("tr");
            var tdCheck = document.createElement("td");
            var box = document.createElement("input");
            box.type = "checkbox";
            box.checked = true;
            box.setAttribute("data-item-id", String(line.item_id));
            tdCheck.appendChild(box);
            tr.appendChild(tdCheck);
            tr.appendChild(cell(line.sku || ""));
            tr.appendChild(cell(line.description || ""));
            var q = cell(String(line.quantity));
            q.className = "cq-num";
            tr.appendChild(q);
            var c = cell(formatMoney(line.unit_cost));
            c.className = "cq-num";
            tr.appendChild(c);
            var p = cell(formatMoney(line.unit_price));
            p.className = "cq-num";
            tr.appendChild(p);
            els.importLines.appendChild(tr);
        });
    }

    function confirmImportLines() {
        if (!state.importReceivedId) {
            return;
        }
        var boxes = els.importLines ? els.importLines.querySelectorAll("input[type=checkbox][data-item-id]") : [];
        var ids = [];
        Array.prototype.forEach.call(boxes, function (box) {
            if (box.checked) {
                ids.push(parseInt(box.getAttribute("data-item-id"), 10));
            }
        });
        if (!ids.length) {
            setMessage("Selecciona al menos una línea.", true);
            return;
        }
        if (els.importConfirm) {
            els.importConfirm.disabled = true;
        }
        post(cfg.actions.receivedImport, {
            received_quote_id: String(state.importReceivedId),
            item_ids: JSON.stringify(ids)
        }).then(function (data) {
            var products = data.products || [];
            products.forEach(function (product) {
                addProduct(product);
            });
            closeImportModal();
            setMessage(data.message || (products.length + " línea(s) importadas."), false);
        }).catch(function (error) {
            setMessage(error.message, true);
        }).finally(function () {
            if (els.importConfirm) {
                els.importConfirm.disabled = false;
            }
        });
    }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.set("action", action);
        if (cfg.nonce) {
            body.set("nonce", cfg.nonce);
        }
        Object.keys(fields || {}).forEach(function (key) {
            if (fields[key] !== undefined && fields[key] !== null) {
                body.set(key, fields[key]);
            }
        });
        return fetch(cfg.ajaxUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok || !payload.success) {
                    var message = payload && payload.data && payload.data.message ? payload.data.message : "No se pudo completar la acción.";
                    throw new Error(message);
                }
                return payload.data;
            });
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

    function lineFigures(line) {
        var qty = round3(Number(line.quantity) || 0);
        var price = round2(Number(line.unit_price) || 0);
        var priceRate = clampRate(line.price_discount);
        var marginRate = clampRate(line.margin_discount);
        var gross = round2(qty * price);
        var hasCost = line.unit_cost !== null && line.unit_cost !== undefined && line.unit_cost !== "";
        var unitCost = hasCost ? round2(Number(line.unit_cost)) : null;
        var discount;
        if (priceRate > 0 || marginRate > 0) {
            var priceOff = round2(gross * priceRate / 100);
            var after = round2(gross - priceOff);
            var marginOff = 0;
            if (unitCost !== null && marginRate > 0) {
                var marginBase = round2(after - round2(qty * unitCost));
                if (marginBase > 0) {
                    marginOff = round2(marginBase * marginRate / 100);
                }
            }
            discount = round2(priceOff + marginOff);
            if (discount < 0) discount = 0;
            if (discount > gross) discount = gross;
        } else {
            discount = round2(Number(line.discount_amount) || 0);
            if (discount < 0) discount = 0;
            if (discount > gross) discount = gross;
        }
        var lineNet = round2(gross - discount);
        var lineProfit = null;
        var lineMargin = null;
        if (unitCost !== null) {
            lineProfit = round2(lineNet - round2(qty * unitCost));
            lineMargin = lineNet > 0 ? round2((lineProfit / lineNet) * 100) : 0;
        }
        return {
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
        return Number.isFinite(number) ? number : 0;
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

    function confianzaCell(line) {
        var td = document.createElement("td");
        td.className = "cq-advanced cq-conf-cell";
        var info = line && line._stock ? line._stock : null;
        var conf = info && info.estado_confianza ? String(info.estado_confianza) : "";
        if (!conf) {
            td.textContent = "—";
            return td;
        }
        var span = document.createElement("span");
        span.className = "cq-conf-badge is-" + conf;
        if (conf === "confiable") span.textContent = "Confiable";
        else if (conf === "poco_confiable") span.textContent = "Poco confiable";
        else if (conf === "dudoso") span.textContent = "Dudoso";
        else span.textContent = conf;
        td.appendChild(span);
        return td;
    }

    function inventariarCell(line) {
        var td = document.createElement("td");
        td.className = "cq-advanced cq-inv-cell";
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "cq-btn cq-btn-inventariar";
        btn.textContent = "Inventariar";
        var info = line && line._stock ? line._stock : null;
        var caps = cfg.caps || {};
        var canCap = !!caps.doInventory;
        var can = !!(info && info.can_inventory && canCap);
        btn.disabled = !can;
        if (!info || !info.producto_base_id) {
            btn.title = "Sin producto base mapeado";
        } else if (!info.has_local_sku) {
            btn.title = "Requiere SKU local para inventariar";
        } else if (!canCap) {
            btn.title = "Sin permiso para inventariar";
        } else {
            btn.title = "Abrir conteo de producto en Bodega";
        }
        btn.addEventListener("click", function () {
            if (!can || !info) return;
            openInventariar(info);
        });
        td.appendChild(btn);
        return td;
    }

    function applyStockRowAlarm(tr, line) {
        tr.classList.remove("cq-stock-critico", "cq-stock-alerta");
        var info = line && line._stock ? line._stock : null;
        if (!info) return;
        if (info.critico) tr.classList.add("cq-stock-critico");
        else if (info.alerta) tr.classList.add("cq-stock-alerta");
    }

    function openInventariar(info) {
        var base = (cfg.warehouseUrl || "/interno/warehouse/").replace(/\/?$/, "/");
        var url = base + "?start=producto&producto_base_id=" + encodeURIComponent(String(info.producto_base_id));
        if (info.canonical_sku) {
            url += "&sku=" + encodeURIComponent(String(info.canonical_sku));
        }
        if (info.nombre) {
            url += "&nombre=" + encodeURIComponent(String(info.nombre));
        }
        window.open(url, "_blank", "noopener");
    }

    function refreshLineStock() {
        if (!state.advanced) return;
        var caps = cfg.caps || {};
        if (!caps.viewStock) return;
        if (!cfg.actions || !cfg.actions.lineStock) return;
        var lines = state.quote.lines || [];
        var ids = [];
        lines.forEach(function (line) {
            var pid = line.product_id;
            if (pid !== null && pid !== undefined && pid !== "" && Number(pid) > 0) {
                ids.push(Number(pid));
            }
        });
        // unique
        var seen = {};
        var uniq = [];
        ids.forEach(function (id) {
            if (!seen[id]) {
                seen[id] = true;
                uniq.push(id);
            }
        });
        if (!uniq.length) {
            lines.forEach(function (line) { line._stock = null; });
            if (state.view === "editor") {
                renderLines();
                renderTotals();
            }
            return;
        }
        post(cfg.actions.lineStock, { product_ids: JSON.stringify(uniq) }).then(function (data) {
            var map = (data && data.stock) || {};
            (state.quote.lines || []).forEach(function (line) {
                var key = line.product_id !== null && line.product_id !== undefined ? String(line.product_id) : "";
                line._stock = key && map[key] ? map[key] : null;
            });
            if (state.view === "editor") {
                renderLines();
                renderTotals();
            }
        }).catch(function () {
            // Silencioso: stock es live informativo.
        });
    }


    function currentChannel() {
        if (els.channel && els.channel.value) {
            return els.channel.value === "online" ? "online" : "local";
        }
        return (state.quote && state.quote.channel === "online") ? "online" : "local";
    }

    function setChannel(channel, interactive) {
        channel = channel === "online" ? "online" : "local";
        if (interactive && state.quote && (state.quote.lines || []).length && state.quote.channel && state.quote.channel !== channel) {
            var ok = window.confirm(
                "Cambiar a " + (channel === "online" ? "Online" : "Local") +
                ".\nLas líneas ya cargadas se conservan; la búsqueda usará el nuevo canal.\n¿Continuar?"
            );
            if (!ok) { return; }
        }
        if (state.quote) { state.quote.channel = channel; }
        if (els.channel) { els.channel.value = channel; }
        if (els.channelLocal) { els.channelLocal.classList.toggle("is-active", channel === "local"); }
        if (els.channelOnline) { els.channelOnline.classList.toggle("is-active", channel === "online"); }
        if (els.search) {
            els.search.placeholder = channel === "local"
                ? "Local: SKU / barcode / proveedor (producto_base)"
                : "Online: SKU / barcode / proveedor (Woo)";
        }
    }

    function openFamilyModal(index) {
        if (!els.familyModal) return;
        var line = (state.quote.lines || [])[index];
        if (!line || state.quote.editable === false) return;
        var fam = line._family || {};
        var members = Array.isArray(fam.members) ? fam.members : [];
        if (!members.length) {
            setMessage("Esta línea no tiene miembros de familia para cambiar.", true);
            return;
        }
        state.familyLineIndex = index;
        if (els.familyTitle) {
            els.familyTitle.textContent = fam.family_name
                ? ("Cambiar: " + fam.family_name)
                : "Cambiar presentación";
        }
        if (els.familyHint) {
            els.familyHint.textContent = "SKU actual: " + (line.sku || "—")
                + ". Elegir reemplaza esta línea; Agregar suma otra presentación (requiere SKU Local).";
        }
        renderFamilyMembers(line, members);
        els.familyModal.hidden = false;
        els.familyModal.setAttribute("aria-hidden", "false");
    }

    function closeFamilyModal() {
        if (!els.familyModal) return;
        els.familyModal.hidden = true;
        els.familyModal.setAttribute("aria-hidden", "true");
        state.familyLineIndex = -1;
        if (els.familyMembers) {
            els.familyMembers.innerHTML = "";
        }
    }

    function memberHasLocalSku(member) {
        if (!member) return false;
        if (member.has_local_sku === false || member.es_local === false) return false;
        if (member.has_local_sku === true || member.es_local === true) return true;
        return String(member.sku_local || member.sku || "").trim() !== "";
    }

    function renderFamilyMembers(line, members) {
        if (!els.familyMembers) return;
        els.familyMembers.innerHTML = "";
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
            var info = document.createElement("div");
            var sku = document.createElement("div");
            sku.className = "cq-result-sku";
            sku.textContent = (member.sku || "?") + " · " + (member.description || "");
            var meta = document.createElement("div");
            meta.className = "cq-result-meta";
            meta.textContent = member.es_unitario
                ? "Presentación: embolsado"
                : ("Presentación: " + (member.label || ("×" + formatPlain(member.cantidad_unidades || 1))));
            if (!memberHasLocalSku(member)) {
                var warn = document.createElement("div");
                warn.className = "cq-result-meta cq-family-no-local";
                warn.textContent = "Sin SKU Local — no se puede agregar por ahora";
                info.appendChild(sku);
                info.appendChild(meta);
                info.appendChild(warn);
            } else {
                info.appendChild(sku);
                info.appendChild(meta);
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
                pick.addEventListener("click", function () {
                    swapFamilyMember(state.familyLineIndex, member);
                });
                actions.appendChild(pick);
            }
            var addBtn = document.createElement("button");
            addBtn.type = "button";
            addBtn.className = "cq-btn";
            addBtn.textContent = "Agregar";
            var canAdd = memberHasLocalSku(member);
            addBtn.disabled = !canAdd;
            addBtn.title = canAdd
                ? "Agregar esta presentación como línea nueva (cantidad 1)"
                : "Sin SKU Local: no se puede agregar por ahora";
            addBtn.addEventListener("click", function () {
                addFamilyMemberLine(member);
            });
            actions.appendChild(addBtn);
            li.appendChild(actions);
            els.familyMembers.appendChild(li);
        });
    }

    function addFamilyMemberLine(member) {
        if (!memberHasLocalSku(member)) {
            setMessage("No se puede agregar «" + (member.sku || "?") + "»: sin SKU Local.", true);
            return;
        }
        var source = (state.quote.lines || [])[state.familyLineIndex] || {};
        var fam = source._family || {};
        var upp = Number(member.cantidad_unidades || 1);
        if (!(upp > 0)) upp = 1;
        var mode = member.es_unitario ? "unitaria" : "pack";
        var pb = member.producto_base_id != null ? Number(member.producto_base_id) : 0;
        closeFamilyModal();
        addProduct({
            product_id: member.product_id != null ? member.product_id : null,
            producto_base_id: pb > 0 ? pb : null,
            sku: member.sku || "",
            description: member.description || member.sku || "",
            unit_price: 0,
            unit_cost: null,
            quantity: 1,
            units_per_pack: upp,
            family_mode: mode,
            packaging: mode,
            local_only: !member.product_id,
            family: fam
        });
    }

    function swapFamilyMember(index, member) {
        var lines = state.quote.lines || [];
        var line = lines[index];
        if (!line || !member) return;
        var newSku = String(member.sku || "").toLowerCase();
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
                recalcLocalLinePrice(target, dupIndex > index ? dupIndex - 1 : dupIndex);
            } else {
                renderLines();
                renderTotals();
            }
            setMessage("Se sumó la cantidad al SKU ya presente en la cotización.", false);
            return;
        }
        line.producto_base_id = newPb > 0 ? newPb : null;
        line.product_id = member.product_id != null ? member.product_id : null;
        line.sku = member.sku || line.sku;
        line.description = member.description || line.description || line.sku;
        line.units_per_pack = Number(member.cantidad_unidades || 1) > 0 ? Number(member.cantidad_unidades) : 1;
        line.family_mode = member.es_unitario ? "unitaria" : "pack";
        line.packaging = line.family_mode;
        line.local_only = !line.product_id;
        line.price_discount = priceDiscount;
        line.margin_discount = marginDiscount;
        line.discount_amount = discountAmount;
        line._family = fam;
        closeFamilyModal();
        if (line.producto_base_id) {
            recalcLocalLinePrice(line, index);
        } else {
            renderLines();
            renderTotals();
        }
        setMessage("Presentación cambiada a «" + (line.sku || "?") + "».", false);
    }

    function applyFamilyPriceResult(line, data) {
        if (!line || !data) return;
        if (data.unit_price != null) {
            line.unit_price = Number(data.unit_price);
        }
        if (data.pricing && data.pricing.unit_cost != null) {
            line.unit_cost = Number(data.pricing.unit_cost);
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

    function recalcFamilyPrices(grupoId) {
        if (!grupoId || !cfg.actions || !cfg.actions.familyPrice) {
            renderLines();
            renderTotals();
            return;
        }
        var lines = state.quote.lines || [];
        var indexes = familyLineIndexes(grupoId, lines);
        if (!indexes.length) {
            renderLines();
            renderTotals();
            return;
        }
        var familyQty = totalFamilyUnitsQuoted(grupoId, lines);
        if (!(familyQty > 0)) {
            familyQty = 1;
        }
        var seq = ++state.familyPriceSeq;
        var jobs = indexes.map(function (idx) {
            var line = lines[idx];
            var pb = line.producto_base_id;
            if (!pb) {
                return Promise.resolve();
            }
            return post(cfg.actions.familyPrice, {
                producto_base_id: String(pb),
                family_qty: String(familyQty)
            }).then(function (data) {
                if (seq !== state.familyPriceSeq) return;
                applyFamilyPriceResult(line, data);
            });
        });
        Promise.all(jobs).then(function () {
            if (seq !== state.familyPriceSeq) return;
            renderLines();
            renderTotals();
        }).catch(function () {
            if (seq !== state.familyPriceSeq) return;
            renderLines();
            renderTotals();
        });
    }

    function recalcLocalLinePrice(line, index) {
        var pb = line && line.producto_base_id;
        if (!pb || !cfg.actions || !cfg.actions.familyPrice) {
            renderLines();
            renderTotals();
            return;
        }
        var gid = lineGrupoId(line);
        if (gid) {
            recalcFamilyPrices(gid);
            return;
        }
        var packs = Number(line.quantity || 1);
        var upp = lineUnitsPerPack(line);
        if (!(upp > 0)) { upp = 1; }
        var seq = ++state.familyPriceSeq;
        post(cfg.actions.familyPrice, {
            producto_base_id: String(pb),
            family_qty: String(packs * upp)
        }).then(function (data) {
            if (seq !== state.familyPriceSeq) return;
            applyFamilyPriceResult(line, data);
            renderLines();
            renderTotals();
        }).catch(function () {
            if (seq !== state.familyPriceSeq) return;
            renderLines();
            renderTotals();
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

    function round2(number) {
        return Math.round((number + Number.EPSILON) * 100) / 100;
    }

    function round3(number) {
        return Math.round((number + Number.EPSILON) * 1000) / 1000;
    }

    function cell(text) {
        var td = document.createElement("td");
        td.textContent = text;
        return td;
    }

    function badgeCell(status, label) {
        var td = document.createElement("td");
        var span = document.createElement("span");
        span.className = "cq-badge cq-badge-" + status;
        span.textContent = label;
        td.appendChild(span);
        return td;
    }

    function setMessage(text, isError) {
        els.message.textContent = text || "";
        els.message.className = "cq-message" + (text ? (isError ? " is-error" : " is-ok") : "");
    }

    function setListMessage(text, isError) {
        els.listMessage.textContent = text || "";
        els.listMessage.className = "cq-message" + (text ? (isError ? " is-error" : " is-ok") : "");
    }
})();
