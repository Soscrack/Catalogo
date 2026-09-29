(function () {
    var cfg = window.RIVERSO_CQ || {};
    var state = {
        view: "list",
        quotes: [],
        quote: emptyQuote(),
        snapshot: "",
        results: []
    };

    var els = {
        listView: document.getElementById("cq-list-view"),
        editorView: document.getElementById("cq-editor-view"),
        listBody: document.getElementById("cq-list-body"),
        empty: document.getElementById("cq-empty"),
        filter: document.getElementById("cq-status-filter"),
        listMessage: document.getElementById("cq-list-message"),
        title: document.getElementById("cq-editor-title"),
        status: document.getElementById("cq-status"),
        customer: document.getElementById("cq-customer"),
        type: document.getElementById("cq-type"),
        validityDays: document.getElementById("cq-validity-days"),
        validityTerms: document.getElementById("cq-validity-terms"),
        net: document.getElementById("cq-total-net"),
        discount: document.getElementById("cq-total-discount"),
        margin: document.getElementById("cq-total-margin"),
        profit: document.getElementById("cq-total-profit"),
        search: document.getElementById("cq-search"),
        results: document.getElementById("cq-results"),
        lines: document.getElementById("cq-lines"),
        linesEmpty: document.getElementById("cq-lines-empty"),
        message: document.getElementById("cq-message"),
        transition: document.getElementById("cq-transition"),
        save: document.getElementById("cq-save"),
        clear: document.getElementById("cq-clear")
    };

    document.getElementById("cq-new").addEventListener("click", function () {
        openEditor(emptyQuote());
    });
    document.getElementById("cq-back").addEventListener("click", showList);
    document.getElementById("cq-search-btn").addEventListener("click", searchProducts);
    els.search.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
            searchProducts();
        }
    });
    els.filter.addEventListener("change", renderList);
    els.save.addEventListener("click", saveQuote);
    els.clear.addEventListener("click", clearQuote);
    els.transition.addEventListener("click", transitionQuote);
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
            status: "draft",
            status_label: "Borrador",
            validity_days: null,
            validity_terms: "",
            net_total: 0,
            discount_total: 0,
            margin_percent: null,
            profit_total: null,
            editable: true,
            allowed_transitions: [],
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
    }

    function loadList() {
        post(cfg.actions.list, {}).then(function (data) {
            state.quotes = data.quotes || [];
            renderList();
        }).catch(function (error) {
            setListMessage(error.message, true);
        });
    }

    function renderList() {
        var filter = els.filter.value;
        var rows = state.quotes.filter(function (quote) {
            return filter === "all" || quote.status === filter;
        });
        els.listBody.innerHTML = "";
        els.empty.hidden = rows.length !== 0;
        rows.forEach(function (quote) {
            var tr = document.createElement("tr");
            tr.appendChild(cell(quote.quote_number));
            tr.appendChild(cell(formatDate(quote.updated_at)));
            tr.appendChild(cell(quote.customer_name || "Sin cliente"));
            tr.appendChild(cell(quote.quote_type_label || "Venta"));
            tr.appendChild(badgeCell(quote.status, quote.status_label));
            var net = cell(formatMoney(quote.net_total));
            net.className = "cq-num";
            tr.appendChild(net);
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
        els.customer.value = quote.customer_name || "";
        els.type.value = quote.quote_type || "venta";
        els.validityDays.value = quote.validity_days === null || quote.validity_days === undefined ? "" : String(quote.validity_days);
        els.validityTerms.value = quote.validity_terms || "";
        var editable = quote.editable !== false;
        [els.customer, els.type, els.validityDays, els.validityTerms, els.search].forEach(function (input) {
            input.disabled = !editable;
        });
        document.getElementById("cq-search-btn").disabled = !editable;
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
        renderLines();
        renderTotals();
        if (quote.status === "invoiced") {
            setMessage("Esta cotización está facturada y no se edita en este corte.", true);
        }
    }

    function renderLines() {
        els.lines.innerHTML = "";
        var lines = state.quote.lines || [];
        els.linesEmpty.hidden = lines.length !== 0;
        var editable = state.quote.editable !== false;
        lines.forEach(function (line, index) {
            var tr = document.createElement("tr");
            tr.className = "cq-line";
            var detail = document.createElement("td");
            var sku = document.createElement("span");
            sku.className = "cq-sku";
            sku.textContent = line.sku;
            var desc = document.createElement("span");
            desc.className = "cq-desc";
            desc.textContent = line.description || "";
            detail.appendChild(sku);
            detail.appendChild(desc);
            tr.appendChild(detail);
            tr.appendChild(inputCell(line, index, "quantity", editable));
            tr.appendChild(inputCell(line, index, "unit_price", editable));
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
                    state.quote.lines.splice(index, 1);
                    renderLines();
                    renderTotals();
                });
                actions.appendChild(edit);
                actions.appendChild(remove);
            }
            tr.appendChild(actions);
            els.lines.appendChild(tr);
        });
    }

    function inputCell(line, index, field, editable) {
        var td = document.createElement("td");
        td.className = "cq-num";
        var input = document.createElement("input");
        input.type = "text";
        input.inputMode = "decimal";
        input.dataset.field = field;
        input.dataset.index = String(index);
        input.value = field === "quantity" ? formatQty(line.quantity) : formatPlain(line.unit_price);
        input.setAttribute("aria-label", (field === "quantity" ? "Cantidad de " : "Precio de ") + line.sku);
        input.disabled = !editable;
        input.addEventListener("focus", function () {
            input.value = field === "quantity" ? String(line.quantity).replace(".", ",") : String(line.unit_price).replace(".", ",");
            input.select();
        });
        input.addEventListener("input", function () {
            var parsed = parseClNumber(input.value);
            if (field === "quantity") {
                state.quote.lines[index].quantity = parsed;
            } else {
                state.quote.lines[index].unit_price = parsed;
            }
            renderTotals();
        });
        input.addEventListener("blur", function () {
            var parsed = parseClNumber(input.value);
            if (field === "quantity") {
                state.quote.lines[index].quantity = parsed;
                input.value = formatQty(parsed);
            } else {
                state.quote.lines[index].unit_price = parsed;
                input.value = formatPlain(parsed);
            }
            renderTotals();
        });
        td.appendChild(input);
        return td;
    }

    function syncHeader() {
        state.quote.customer_name = els.customer.value.trim();
        state.quote.quote_type = els.type.value;
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
    }

    function searchProducts() {
        var query = els.search.value.trim();
        if (!query) {
            setMessage("Ingresa un SKU, código proveedor o código de barras.", true);
            return;
        }
        setMessage("");
        post(cfg.actions.search, { q: query }).then(function (data) {
            state.results = data.products || [];
            if (state.results.length === 1) {
                addProduct(state.results[0]);
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
            info.appendChild(sku);
            info.appendChild(meta);
            var add = document.createElement("button");
            add.type = "button";
            add.className = "cq-btn";
            add.textContent = "Agregar";
            add.addEventListener("click", function () {
                addProduct(product);
            });
            li.appendChild(info);
            li.appendChild(add);
            els.results.appendChild(li);
        });
    }

    function addProduct(product) {
        var lines = state.quote.lines;
        var sku = String(product.sku || "").toLowerCase();
        var existing = lines.find(function (line) {
            return String(line.sku || "").toLowerCase() === sku;
        });
        if (existing) {
            existing.quantity = round3(Number(existing.quantity || 0) + 1);
        } else {
            lines.push({
                product_id: product.product_id,
                sku: product.sku,
                supplier_code: product.supplier_code || "",
                barcode: product.barcode || "",
                description: product.description || product.sku,
                quantity: 1,
                unit_price: Number(product.unit_price || 0),
                unit_cost: product.unit_cost === null || product.unit_cost === undefined || product.unit_cost === "" ? null : Number(product.unit_cost),
                discount_amount: 0
            });
        }
        els.results.hidden = true;
        renderLines();
        renderTotals();
        setMessage("Producto agregado.", false);
    }

    function saveQuote() {
        syncHeader();
        var payload = {
            id: state.quote.id,
            customer_id: state.quote.customer_id,
            customer_name: state.quote.customer_name,
            quote_type: state.quote.quote_type,
            validity_days: state.quote.validity_days,
            validity_terms: state.quote.validity_terms,
            lines: (state.quote.lines || []).map(function (line) {
                return {
                    product_id: line.product_id,
                    sku: line.sku,
                    supplier_code: line.supplier_code || "",
                    barcode: line.barcode || "",
                    description: line.description || "",
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    unit_cost: line.unit_cost,
                    discount_amount: line.discount_amount || 0
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
            validity_days: quote.validity_days,
            validity_terms: quote.validity_terms || "",
            lines: quote.lines || []
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
            var qty = round3(Number(line.quantity) || 0);
            var price = round2(Number(line.unit_price) || 0);
            var discount = round2(Number(line.discount_amount) || 0);
            if (discount < 0) discount = 0;
            var gross = round2(qty * price);
            if (discount > gross) discount = gross;
            var lineNet = round2(gross - discount);
            var hasCost = line.unit_cost !== null && line.unit_cost !== undefined && line.unit_cost !== "";
            if (!hasCost) {
                profitKnown = false;
            } else {
                profit += round2(lineNet - round2(qty * round2(Number(line.unit_cost))));
            }
            net += lineNet;
            discounts += discount;
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
