(function () {
    var cfg = window.RIVERSO_CQ || {};
    var state = {
        view: "list",
        quotes: [],
        filteredQuotes: [],
        quote: emptyQuote(),
        snapshot: "",
        results: [],
        advanced: false,
        modalScope: "todo",
        modalResults: [],
        modalContains: [],
        familyLineIndex: -1,
        familyPriceSeq: 0,
        priceRecalcChain: Promise.resolve(),
        importReceivedId: 0,
        invoicing: false,
        listPage: 1,
        listPageSize: 10,
        listQuery: "",
        orderBy: "date",
        orderDir: "DESC",
        expandedIds: {},
        openViewMenuId: null,
        autosave: {
            timer: null,
            saving: false,
            pending: false
        },
        lineModal: {
            index: -1,
            taxView: "bruto",
            mode: "auto",
            hasRule: false,
            isFamily: false,
            syncing: false,
            previewSeq: 0,
            debounceTimer: null,
            catalogP: null,
            unitario0: null,
            ruleTotal: null,
            editingField: null
        }
    };

    var IVA_FACTOR = 1.19;

    var els = {
        listView: document.getElementById("cq-list-view"),
        editorView: document.getElementById("cq-editor-view"),
        listBody: document.getElementById("cq-list-body"),
        empty: document.getElementById("cq-empty"),
        filter: document.getElementById("cq-status-filter"),
        typeFilter: document.getElementById("cq-type-filter"),
        dateFrom: document.getElementById("cq-date-from"),
        dateTo: document.getElementById("cq-date-to"),
        filterNumber: document.getElementById("cq-filter-number"),
        filterCustomer: document.getElementById("cq-filter-customer"),
        filterResponsable: document.getElementById("cq-filter-responsable"),
        includeQuotes: document.getElementById("cq-include-quotes"),
        resultsPanel: document.getElementById("cq-results-panel"),
        resultsCount: document.getElementById("cq-results-count"),
        exportExcel: document.getElementById("cq-export-excel"),
        orderBtn: document.getElementById("cq-order-btn"),
        orderMenu: document.getElementById("cq-order-menu"),
        pageSize: document.getElementById("cq-page-size"),
        tableSearch: document.getElementById("cq-table-search"),
        pagePrev: document.getElementById("cq-page-prev"),
        pageNext: document.getElementById("cq-page-next"),
        pageNumbers: document.getElementById("cq-page-numbers"),
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
        netHint: document.getElementById("cq-total-neto-hint"),
        discount: document.getElementById("cq-total-discount"),
        margin: document.getElementById("cq-total-margin"),
        profit: document.getElementById("cq-total-profit"),
        profitHint: document.getElementById("cq-total-profit-neto-hint"),
        search: document.getElementById("cq-search"),
        lupa: document.getElementById("cq-lupa"),
        manualBtn: document.getElementById("cq-manual"),
        advanced: document.getElementById("cq-advanced"),
        results: document.getElementById("cq-results"),
        modal: document.getElementById("cq-advanced-modal"),
        modalQ: document.getElementById("cq-modal-q"),
        modalSearchBtn: document.getElementById("cq-modal-search-btn"),
        modalResults: document.getElementById("cq-modal-results"),
        modalHint: document.getElementById("cq-modal-hint"),
        modalClose: document.getElementById("cq-modal-close"),
        modalContains: document.getElementById("cq-modal-contains"),
        modalContainsAdd: document.getElementById("cq-modal-contains-add"),
        modalContainsTags: document.getElementById("cq-modal-contains-tags"),
        manualModal: document.getElementById("cq-manual-modal"),
        manualClose: document.getElementById("cq-manual-close"),
        manualQty: document.getElementById("cq-manual-qty"),
        manualUnit: document.getElementById("cq-manual-unit"),
        manualConcepto: document.getElementById("cq-manual-concepto"),
        manualDescToggle: document.getElementById("cq-manual-desc-toggle"),
        manualDescWrap: document.getElementById("cq-manual-desc-wrap"),
        manualDescLarga: document.getElementById("cq-manual-desc-larga"),
        manualUnitNeto: document.getElementById("cq-manual-unit-neto"),
        manualUnitBruto: document.getElementById("cq-manual-unit-bruto"),
        manualAdjType: document.getElementById("cq-manual-adj-type"),
        manualPct: document.getElementById("cq-manual-pct"),
        manualTotalNeto: document.getElementById("cq-manual-total-neto"),
        manualTotalBruto: document.getElementById("cq-manual-total-bruto"),
        manualHint: document.getElementById("cq-manual-hint"),
        manualAdd: document.getElementById("cq-manual-add"),
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
        familyOpenAdmin: document.getElementById("cq-family-open-admin"),
        lineModal: document.getElementById("cq-line-modal"),
        lineTitle: document.getElementById("cq-line-title"),
        lineSubtitle: document.getElementById("cq-line-subtitle"),
        lineClose: document.getElementById("cq-line-close"),
        lineCancel: document.getElementById("cq-line-cancel"),
        lineSave: document.getElementById("cq-line-save"),
        lineTaxNeto: document.getElementById("cq-line-tax-neto"),
        lineTaxBruto: document.getElementById("cq-line-tax-bruto"),
        lineFamilyNote: document.getElementById("cq-line-family-note"),
        lineModeManualWrap: document.getElementById("cq-line-mode-manual-wrap"),
        lineModeManual: document.getElementById("cq-line-mode-manual"),
        lineModeRadios: document.getElementById("cq-line-mode-radios"),
        lineRuleInfo: document.getElementById("cq-line-rule-info"),
        lineQty: document.getElementById("cq-line-qty"),
        lineQtyHint: document.getElementById("cq-line-qty-hint"),
        linePrefField: document.getElementById("cq-line-pref-field"),
        linePref: document.getElementById("cq-line-pref"),
        linePrefHint: document.getElementById("cq-line-pref-hint"),
        lineUnit: document.getElementById("cq-line-unit"),
        lineUnitLabel: document.getElementById("cq-line-unit-label"),
        lineUnitHint: document.getElementById("cq-line-unit-hint"),
        lineTotal: document.getElementById("cq-line-total"),
        lineTotalLabel: document.getElementById("cq-line-total-label"),
        lineTotalHint: document.getElementById("cq-line-total-hint"),
        linePriceDiscount: document.getElementById("cq-line-price-discount"),
        lineMarginDiscount: document.getElementById("cq-line-margin-discount"),
        lineMarginHelp: document.getElementById("cq-line-margin-help"),
        lineMarginNa: document.getElementById("cq-line-margin-na"),
        lineDiscountHint: document.getElementById("cq-line-discount-hint"),
        linePreview: document.getElementById("cq-line-preview"),
        pdf: document.getElementById("cq-pdf"),
        pdfTemplate: document.getElementById("cq-pdf-template"),
        options: document.getElementById("cq-options"),
        expiredBadge: document.getElementById("cq-expired-badge"),
        save: document.getElementById("cq-save"),
        saveStatus: document.getElementById("cq-save-status"),
        clear: document.getElementById("cq-clear")
    };

    document.getElementById("cq-new").addEventListener("click", function () {
        openEditor(emptyQuote());
    });
    document.getElementById("cq-back").addEventListener("click", function () {
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        if (state.view === "editor" && state.quote.editable !== false
            && (state.quote.id || (state.quote.lines || []).length)) {
            saveQuote(true).finally(function () {
                showList();
            });
            return;
        }
        showList();
    });
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
    if (els.manualBtn) {
        els.manualBtn.addEventListener("click", openManualModal);
    }
    if (els.manualModal) {
        els.manualModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-manual-close") === "1") {
                closeManualModal();
            }
        });
    }
    if (els.manualDescToggle) {
        els.manualDescToggle.addEventListener("click", toggleManualDescLarga);
    }
    if (els.manualAdd) {
        els.manualAdd.addEventListener("click", submitManualLine);
    }
    ["manualQty", "manualUnitNeto", "manualUnitBruto", "manualPct"].forEach(function (key) {
        if (!els[key]) return;
        els[key].addEventListener("input", function () {
            onManualPriceInput(key);
        });
        els[key].addEventListener("change", function () {
            onManualPriceInput(key);
        });
    });
    if (els.manualAdjType) {
        els.manualAdjType.addEventListener("change", function () {
            syncManualTotals();
        });
    }
    if (els.manualConcepto) {
        els.manualConcepto.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                submitManualLine();
            }
        });
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
    if (els.modalContainsAdd) {
        els.modalContainsAdd.addEventListener("click", addModalContainsWord);
    }
    if (els.modalContains) {
        els.modalContains.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                addModalContainsWord();
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
        if (els.lineModal && !els.lineModal.hidden) {
            event.preventDefault();
            closeLineModal();
            return;
        }
        if (els.manualModal && !els.manualModal.hidden) {
            event.preventDefault();
            closeManualModal();
            return;
        }
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
    if (els.applyFilters) {
        els.applyFilters.addEventListener("click", function () {
            state.listPage = 1;
            loadList();
        });
    }
    if (els.filterNumber) {
        els.filterNumber.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                state.listPage = 1;
                loadList();
            }
        });
    }
    if (els.filterCustomer) {
        els.filterCustomer.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                state.listPage = 1;
                loadList();
            }
        });
    }
    if (els.pageSize) {
        els.pageSize.addEventListener("change", function () {
            state.listPageSize = parseInt(els.pageSize.value, 10) || 10;
            state.listPage = 1;
            renderList();
        });
    }
    if (els.tableSearch) {
        els.tableSearch.addEventListener("input", function () {
            state.listQuery = String(els.tableSearch.value || "");
            state.listPage = 1;
            renderList();
        });
    }
    if (els.pagePrev) {
        els.pagePrev.addEventListener("click", function () {
            if (state.listPage > 1) {
                state.listPage -= 1;
                renderList();
            }
        });
    }
    if (els.pageNext) {
        els.pageNext.addEventListener("click", function () {
            var totalPages = Math.max(1, Math.ceil((state.filteredQuotes || []).length / state.listPageSize));
            if (state.listPage < totalPages) {
                state.listPage += 1;
                renderList();
            }
        });
    }
    if (els.exportExcel) {
        els.exportExcel.addEventListener("click", exportQuotesCsv);
    }
    if (els.orderBtn && els.orderMenu) {
        els.orderBtn.addEventListener("click", function (event) {
            event.stopPropagation();
            var open = els.orderMenu.hidden;
            closeViewMenus();
            closeOrderMenu();
            if (open) {
                els.orderMenu.hidden = false;
                els.orderBtn.setAttribute("aria-expanded", "true");
            }
        });
        Array.prototype.forEach.call(els.orderMenu.querySelectorAll("[data-order-by]"), function (btn) {
            btn.addEventListener("click", function (event) {
                event.stopPropagation();
                state.orderBy = String(btn.getAttribute("data-order-by") || "date");
                state.orderDir = String(btn.getAttribute("data-order-dir") || "DESC").toUpperCase();
                if (state.orderDir !== "ASC" && state.orderDir !== "DESC") {
                    state.orderDir = "DESC";
                }
                updateOrderButtonLabel();
                closeOrderMenu();
                state.listPage = 1;
                loadList();
            });
        });
    }
    document.addEventListener("click", function () {
        closeViewMenus();
        closeOrderMenu();
    });
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
    els.save.addEventListener("click", function () { saveQuote(false); });
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
    if (els.familyOpenAdmin) {
        els.familyOpenAdmin.addEventListener("click", openFamilyInAdmin);
    }
    if (els.lineModal) {
        els.lineModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-line-close") === "1") {
                closeLineModal();
            }
        });
    }
    if (els.lineClose) {
        els.lineClose.addEventListener("click", closeLineModal);
    }
    if (els.lineCancel) {
        els.lineCancel.addEventListener("click", closeLineModal);
    }
    if (els.lineSave) {
        els.lineSave.addEventListener("click", applyLineModal);
    }
    if (els.lineTaxNeto) {
        els.lineTaxNeto.addEventListener("click", function () { setLineTaxView("neto"); });
    }
    if (els.lineTaxBruto) {
        els.lineTaxBruto.addEventListener("click", function () { setLineTaxView("bruto"); });
    }
    if (els.lineModeManual) {
        els.lineModeManual.addEventListener("change", function () {
            setLineModalMode(els.lineModeManual.checked ? "manual" : "auto");
        });
    }
    if (els.lineModeRadios) {
        Array.prototype.forEach.call(els.lineModeRadios.querySelectorAll('input[name="cq-line-mode"]'), function (radio) {
            radio.addEventListener("change", function () {
                if (radio.checked) setLineModalMode(radio.value);
            });
        });
    }
    if (els.lineQty) {
        els.lineQty.addEventListener("input", function () { onLineModalQtyInput(); });
        els.lineQty.addEventListener("blur", function () { onLineModalQtyInput(true); });
    }
    if (els.linePref) {
        els.linePref.addEventListener("input", function () { onLineModalPrefInput(); });
        els.linePref.addEventListener("blur", function () { onLineModalPrefInput(true); });
    }
    if (els.lineUnit) {
        els.lineUnit.addEventListener("input", function () { onLineModalUnitInput(); });
        els.lineUnit.addEventListener("blur", function () { onLineModalUnitInput(true); });
    }
    if (els.lineTotal) {
        els.lineTotal.addEventListener("input", function () { onLineModalTotalInput(); });
        els.lineTotal.addEventListener("blur", function () { onLineModalTotalInput(true); });
    }
    if (els.linePriceDiscount) {
        els.linePriceDiscount.addEventListener("input", function () { onLineModalDiscountInput("price_discount"); });
        els.linePriceDiscount.addEventListener("blur", function () { onLineModalDiscountInput("price_discount", true); });
    }
    if (els.lineMarginDiscount) {
        els.lineMarginDiscount.addEventListener("input", function () { onLineModalDiscountInput("margin_discount"); });
        els.lineMarginDiscount.addEventListener("blur", function () { onLineModalDiscountInput("margin_discount", true); });
    }
    if (els.importCheckAll) {
        els.importCheckAll.addEventListener("change", function () {
            var on = !!els.importCheckAll.checked;
            var boxes = els.importLines ? els.importLines.querySelectorAll("input[type=checkbox][data-item-id]") : [];
            Array.prototype.forEach.call(boxes, function (box) { box.checked = on; });
        });
    }
    if (els.pdfTemplate) {
        restorePdfTemplate();
        els.pdfTemplate.addEventListener("change", function () {
            persistPdfTemplate(els.pdfTemplate.value);
        });
    }
    if (els.pdf) {
        els.pdf.addEventListener("click", openQuotePdf);
    }
    if (els.options) {
        els.options.addEventListener("click", function () {
            setMessage("Opciones de cotización: próximamente (stub P1b).", false);
        });
    }
    ["input", "change"].forEach(function (eventName) {
        els.customer.addEventListener(eventName, function () {
            syncHeader();
            if (eventName === "change") scheduleAutosave(300);
            else scheduleAutosave(600);
        });
        els.type.addEventListener(eventName, function () {
            syncHeader();
            scheduleAutosave(300);
        });
        els.validityDays.addEventListener(eventName, function () {
            syncHeader();
            if (eventName === "change") scheduleAutosave(300);
            else scheduleAutosave(600);
        });
        els.validityTerms.addEventListener(eventName, function () {
            syncHeader();
            if (eventName === "change") scheduleAutosave(300);
            else scheduleAutosave(600);
        });
    });
    if (els.issueDate) {
        els.issueDate.addEventListener("change", function () {
            syncHeader();
            scheduleAutosave(0);
        });
    }

    window.addEventListener("beforeunload", function (event) {
        if (state.autosave.saving || state.autosave.pending || (state.autosave.timer && isDirty())) {
            event.preventDefault();
            event.returnValue = "";
        }
    });

    if (!bootFromDeepLink()) {
        updateOrderButtonLabel();
        showList();
    } else {
        updateOrderButtonLabel();
    }

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
            issue_date: todayIsoDate(),
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

    function bootFromDeepLink() {
        var params;
        try {
            params = new URLSearchParams(window.location.search || "");
        } catch (err) {
            return false;
        }
        var quoteId = parseInt(params.get("quote") || "0", 10);
        var isNew = params.get("nueva") === "1";
        if (quoteId > 0) {
            openQuote(quoteId);
            return true;
        }
        if (isNew) {
            openEditor(emptyQuote());
            return true;
        }
        return false;
    }

    function showList() {
        state.view = "list";
        els.listView.hidden = false;
        els.editorView.hidden = true;
        try {
            if (window.history && window.history.replaceState && cfg.portalUrl) {
                window.history.replaceState({}, "", cfg.portalUrl);
            }
        } catch (err) { /* ignore */ }
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
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        state.autosave.pending = false;
        setSaveStatus("");
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
        if (els.includeQuotes && !els.includeQuotes.checked) {
            state.quotes = [];
            state.filteredQuotes = [];
            if (els.resultsPanel) els.resultsPanel.hidden = false;
            renderList();
            setListMessage("Marca «Cotizaciones» para buscar en el listado.", true);
            return;
        }
        var fields = {};
        if (els.filter && els.filter.value && els.filter.value !== "all") {
            fields.status = String(els.filter.value);
        }
        if (els.typeFilter && els.typeFilter.value && els.typeFilter.value !== "all") {
            fields.quote_type = String(els.typeFilter.value);
        }
        if (els.filterNumber && els.filterNumber.value) {
            fields.quote_number = String(els.filterNumber.value).trim();
        }
        if (els.filterCustomer && els.filterCustomer.value) {
            fields.customer_name = String(els.filterCustomer.value).trim();
        }
        if (els.filterResponsable && els.filterResponsable.value && els.filterResponsable.value !== "0") {
            fields.created_by = String(els.filterResponsable.value);
        }
        if (els.dateFrom && els.dateFrom.value) {
            fields.date_from = String(els.dateFrom.value);
        }
        if (els.dateTo && els.dateTo.value) {
            fields.date_to = String(els.dateTo.value);
        }
        fields.order_by = state.orderBy || "date";
        fields.order_dir = state.orderDir || "DESC";
        setListMessage("");
        post(cfg.actions.list, fields).then(function (data) {
            state.quotes = data.quotes || [];
            if (data.order_by) state.orderBy = String(data.order_by);
            if (data.order_dir) state.orderDir = String(data.order_dir);
            updateOrderButtonLabel();
            fillResponsables(data.responsables || []);
            if (els.resultsPanel) els.resultsPanel.hidden = false;
            renderList();
        }).catch(function (error) {
            state.quotes = [];
            if (els.resultsPanel) els.resultsPanel.hidden = false;
            renderList();
            setListMessage(error.message, true);
        });
    }

    function fillResponsables(list) {
        if (!els.filterResponsable) return;
        var current = String(els.filterResponsable.value || "0");
        var html = '<option value="0">** Cualquier Responsable **</option>';
        (list || []).forEach(function (row) {
            var id = String(row.id || "");
            var name = String(row.name || ("Usuario #" + id));
            html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(name) + "</option>";
        });
        els.filterResponsable.innerHTML = html;
        if (current && current !== "0") {
            els.filterResponsable.value = current;
        }
    }

    function applyClientFilters() {
        var q = String(state.listQuery || "").trim().toLowerCase();
        var rows = state.quotes || [];
        if (!q) {
            state.filteredQuotes = rows.slice();
            return;
        }
        state.filteredQuotes = rows.filter(function (quote) {
            var hay = [
                quote.quote_number,
                quote.customer_name,
                quote.seller_name,
                quote.status_label,
                formatMoney(quote.net_total)
            ].join(" ").toLowerCase();
            return hay.indexOf(q) >= 0;
        });
    }

    function renderList() {
        applyClientFilters();
        var rows = state.filteredQuotes || [];
        var pageSize = state.listPageSize || 10;
        var totalPages = Math.max(1, Math.ceil(rows.length / pageSize) || 1);
        if (state.listPage > totalPages) state.listPage = totalPages;
        if (state.listPage < 1) state.listPage = 1;
        var start = (state.listPage - 1) * pageSize;
        var pageRows = rows.slice(start, start + pageSize);

        if (els.resultsCount) {
            els.resultsCount.textContent = "Cotizaciones mostradas: " + pageRows.length
                + (rows.length !== pageRows.length ? " de " + rows.length : "");
        }
        els.listBody.innerHTML = "";
        if (els.empty) {
            els.empty.hidden = rows.length !== 0;
        }
        pageRows.forEach(function (quote) {
            var tr = document.createElement("tr");
            if (quote.is_expired) {
                tr.className = "cq-row-expired";
            }
            var expandTd = document.createElement("td");
            expandTd.className = "cq-col-expand";
            var expandBtn = document.createElement("button");
            expandBtn.type = "button";
            expandBtn.className = "cq-expand-btn";
            expandBtn.setAttribute("aria-label", "Ver detalle");
            expandBtn.textContent = state.expandedIds[quote.id] ? "−" : "+";
            expandBtn.addEventListener("click", function (event) {
                event.stopPropagation();
                state.expandedIds[quote.id] = !state.expandedIds[quote.id];
                renderList();
            });
            expandTd.appendChild(expandBtn);
            tr.appendChild(expandTd);

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
            tr.appendChild(cell(quote.seller_name || "—"));
            tr.appendChild(badgeCell(quote.status, quote.status_label));
            tr.appendChild(cell("—"));
            tr.appendChild(cell("—"));
            var net = cell(formatMoney(quote.net_total));
            net.className = "cq-num";
            tr.appendChild(net);

            var actions = document.createElement("td");
            actions.appendChild(buildViewSplit(quote));
            tr.appendChild(actions);
            els.listBody.appendChild(tr);

            if (state.expandedIds[quote.id]) {
                var detail = document.createElement("tr");
                detail.className = "cq-expand-detail";
                var td = document.createElement("td");
                td.colSpan = 10;
                var bits = [];
                bits.push("Tipo: " + (quote.quote_type_label || "Venta"));
                if (quote.order_id) {
                    bits.push("Pedido WC #" + quote.order_id);
                }
                if (quote.channel) {
                    bits.push("Canal: " + quote.channel);
                }
                bits.push("Líneas: " + (quote.line_count != null ? quote.line_count : "—"));
                td.textContent = bits.join(" · ");
                detail.appendChild(td);
                els.listBody.appendChild(detail);
            }
        });
        renderPager(totalPages);
    }

    function buildViewSplit(quote) {
        var wrap = document.createElement("div");
        wrap.className = "cq-view-split";
        var main = document.createElement("button");
        main.type = "button";
        main.className = "cq-view-main";
        main.title = "Ver cotización en esta ventana";
        main.innerHTML = '<span aria-hidden="true">👁</span>';
        main.addEventListener("click", function (event) {
            event.stopPropagation();
            closeViewMenus();
            openQuote(quote.id);
        });
        var toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "cq-view-toggle";
        toggle.setAttribute("aria-label", "Más opciones de apertura");
        toggle.textContent = "▾";
        var menu = document.createElement("div");
        menu.className = "cq-view-menu";
        menu.hidden = true;
        var opt = document.createElement("button");
        opt.type = "button";
        opt.textContent = "Nueva Ventana";
        opt.addEventListener("click", function (event) {
            event.stopPropagation();
            closeViewMenus();
            openQuoteInNewWindow(quote.id);
        });
        menu.appendChild(opt);
        toggle.addEventListener("click", function (event) {
            event.stopPropagation();
            var willOpen = menu.hidden;
            closeViewMenus();
            if (willOpen) {
                menu.hidden = false;
                state.openViewMenuId = quote.id;
            }
        });
        wrap.appendChild(main);
        wrap.appendChild(toggle);
        wrap.appendChild(menu);
        return wrap;
    }

    function closeViewMenus() {
        state.openViewMenuId = null;
        var menus = document.querySelectorAll(".cq-view-menu");
        Array.prototype.forEach.call(menus, function (menu) {
            menu.hidden = true;
        });
    }

    function closeOrderMenu() {
        if (els.orderMenu) els.orderMenu.hidden = true;
        if (els.orderBtn) els.orderBtn.setAttribute("aria-expanded", "false");
    }

    function updateOrderButtonLabel() {
        if (!els.orderBtn) return;
        var by = state.orderBy || "date";
        var dir = (state.orderDir || "DESC").toUpperCase();
        var labels = {
            date: "Fecha",
            number: "Número",
            customer: "Cliente",
            amount: "Monto",
            status: "Estado"
        };
        var name = labels[by] || "Fecha";
        var arrow = dir === "ASC" ? "↑" : "↓";
        els.orderBtn.textContent = "Ordenar: " + name + " " + arrow;
        if (els.orderMenu) {
            Array.prototype.forEach.call(els.orderMenu.querySelectorAll("[data-order-by]"), function (btn) {
                var active = btn.getAttribute("data-order-by") === by
                    && String(btn.getAttribute("data-order-dir") || "").toUpperCase() === dir;
                btn.classList.toggle("is-active", active);
            });
        }
    }

    function quotePortalUrl(id) {
        var base = String(cfg.portalUrl || (window.location.origin + window.location.pathname));
        var sep = base.indexOf("?") >= 0 ? "&" : "?";
        return base + sep + "quote=" + encodeURIComponent(String(id));
    }

    function openQuoteInNewWindow(id) {
        var url = quotePortalUrl(id);
        var win = window.open(url, "_blank", "noopener");
        if (!win) {
            setListMessage("El navegador bloqueó la nueva ventana. Permite ventanas emergentes.", true);
        }
    }

    function renderPager(totalPages) {
        if (!els.pageNumbers) return;
        els.pageNumbers.innerHTML = "";
        for (var i = 1; i <= totalPages; i += 1) {
            (function (page) {
                var btn = document.createElement("button");
                btn.type = "button";
                btn.textContent = String(page);
                if (page === state.listPage) btn.className = "is-active";
                btn.addEventListener("click", function () {
                    state.listPage = page;
                    renderList();
                });
                els.pageNumbers.appendChild(btn);
            })(i);
        }
        if (els.pagePrev) els.pagePrev.disabled = state.listPage <= 1;
        if (els.pageNext) els.pageNext.disabled = state.listPage >= totalPages;
    }

    function exportQuotesCsv() {
        var rows = state.filteredQuotes || [];
        if (!rows.length) {
            setListMessage("No hay filas para exportar.", true);
            return;
        }
        var header = [
            "Número cotización",
            "Fecha creación",
            "Cliente Nombre",
            "Responsable",
            "Estado Cotización",
            "Estado de Venta",
            "DTE's Folios Asociados",
            "Monto"
        ];
        var lines = [header.map(csvEscape).join(";")];
        rows.forEach(function (quote) {
            lines.push([
                quote.quote_number || "",
                formatDate(quote.issue_date || quote.created_at || quote.updated_at),
                quote.customer_name || "",
                quote.seller_name || "",
                quote.status_label || "",
                "",
                "",
                String(quote.net_total != null ? quote.net_total : "")
            ].map(csvEscape).join(";"));
        });
        var blob = new Blob(["\ufeff" + lines.join("\n")], { type: "text/csv;charset=utf-8;" });
        var url = URL.createObjectURL(blob);
        var a = document.createElement("a");
        a.href = url;
        a.download = "cotizaciones.csv";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        setListMessage("Exportación lista (" + rows.length + " filas).", false);
    }

    function csvEscape(value) {
        var text = String(value == null ? "" : value);
        if (/[";\n\r]/.test(text)) {
            return '"' + text.replace(/"/g, '""') + '"';
        }
        return text;
    }

    function escapeHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
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
        var editable = quote.editable !== false;
        if (els.issueDate) {
            els.issueDate.value = toDateInputValue(quote.issue_date || quote.created_at) || (quote.id ? "" : todayIsoDate());
            els.issueDate.disabled = !editable;
        }
        if (els.seller) {
            els.seller.value = quote.seller_name || cfg.currentUserName || "";
        }
        els.customer.value = quote.customer_name || "";
        els.type.value = quote.quote_type || "venta";
        setChannel(quote.channel || "local", false);
        els.validityDays.value = quote.validity_days === null || quote.validity_days === undefined ? "" : String(quote.validity_days);
        els.validityTerms.value = quote.validity_terms || "";
        [els.customer, els.type, els.validityDays, els.validityTerms, els.search].forEach(function (input) {
            input.disabled = !editable;
        });
        if (els.channelLocal) els.channelLocal.disabled = !editable;
        if (els.channelOnline) els.channelOnline.disabled = !editable;
        document.getElementById("cq-search-btn").disabled = !editable;
        if (els.lupa) {
            els.lupa.disabled = !editable;
        }
        if (els.manualBtn) {
            els.manualBtn.disabled = !editable;
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
        if (editable) {
            recalcAllFamilyGroups();
        }
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
            var totalGross = 0;
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
            var fam = line._family || {};
            plan.push({
                type: "header",
                grupoId: gid,
                familyName: fam.family_name || fam.family_code || ("Familia #" + gid),
                totalUnits: totalUnits,
                totalGross: totalGross,
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
        meta.textContent = familyHeaderMetaText(item);
        td.appendChild(name);
        td.appendChild(meta);
        tr.appendChild(td);
        return tr;
    }

    function familyHeaderMetaText(item) {
        var units = "Lleva " + formatPlain(item.totalUnits) + " uds";
        var money = formatMoney(item.totalGross != null ? item.totalGross : 0);
        return units + " · Total " + money;
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
                var gid = lineGrupoId(state.quote.lines[index] || line);
                state.quote.lines.splice(index, 1);
                if (gid) {
                    trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                        scheduleAutosave(0);
                    });
                } else {
                    renderLines();
                    renderTotals();
                    scheduleAutosave(0);
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
            state.quote.lines[index].quantity = next;
            input.value = formatQty(next);
            updateQtyMeta(wrap, state.quote.lines[index]);
            if (state.quote.lines[index].producto_base_id) {
                trackPriceRecalc(recalcLocalLinePrice(state.quote.lines[index], index)).then(function () {
                    scheduleAutosave(0);
                });
                return;
            }
            renderTotals();
            scheduleAutosave(0);
        }
        function commitQty() {
            var parsed = parseClNumber(input.value);
            if (parsed < 0) parsed = 0;
            parsed = round3(parsed);
            var line = state.quote.lines[index];
            if (!line) return;
            line.quantity = parsed;
            input.value = formatQty(parsed);
            updateQtyMeta(wrap, line);
            var gid = lineGrupoId(line);
            if (gid) {
                trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                    scheduleAutosave(0);
                });
                return;
            }
            if (line.producto_base_id) {
                trackPriceRecalc(recalcLocalLinePrice(line, index)).then(function () {
                    scheduleAutosave(0);
                });
                return;
            }
            renderTotals();
            scheduleAutosave(0);
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
            input.value = editField(field, state.quote.lines[index]);
            input.select();
        });
        input.addEventListener("input", function () {
            var current = state.quote.lines[index];
            if (!current) return;
            assignField(current, field, parseClNumber(input.value));
            refreshSiblingDiscountInput(trFromInput(input), current, field);
            renderTotals();
            scheduleAutosave(400);
        });
        input.addEventListener("blur", function () {
            var current = state.quote.lines[index];
            if (!current) return;
            var parsed = parseClNumber(input.value);
            if (isRate) {
                parsed = clampRate(parsed);
            }
            assignField(current, field, parsed);
            input.value = displayField(field, current);
            refreshSiblingDiscountInput(trFromInput(input), current, field);
            renderTotals();
            scheduleAutosave(0);
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
     * source: 'price_discount' | 'margin_discount'
     */
    function syncEquivalentDiscounts(line, source) {
        if (!line) return;
        var bases = discountBases(line);
        var gross = bases.gross;
        var marginMoney = bases.marginMoney;
        var money = 0;
        if (source === "margin_discount") {
            var m = clampRate(line.margin_discount);
            if (!(marginMoney != null && marginMoney > 0)) {
                line.margin_discount = 0;
                line.price_discount = 0;
                line.discount_amount = 0;
                return;
            }
            money = m > 0 ? round2(marginMoney * m / 100) : 0;
            line.price_discount = gross > 0 && money > 0
                ? clampRate(round2(money / gross * 100))
                : 0;
            // Canónico precio: re-derivar margen para redondeo estable.
            money = clampRate(line.price_discount) > 0
                ? round2(gross * clampRate(line.price_discount) / 100)
                : 0;
            line.margin_discount = money > 0
                ? clampRate(round2(money / marginMoney * 100))
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

    function syncHeader() {
        state.quote.customer_name = els.customer.value.trim();
        state.quote.quote_type = els.type.value;
        state.quote.channel = currentChannel();
        state.quote.validity_days = els.validityDays.value === "" ? null : Number(els.validityDays.value);
        state.quote.validity_terms = els.validityTerms.value.trim();
        if (els.issueDate) {
            var issue = toDateInputValue(els.issueDate.value);
            if (issue) {
                state.quote.issue_date = issue;
            }
        }
    }

    function renderTotals() {
        (state.quote.lines || []).forEach(function (line) {
            if (!line) return;
            var p = clampRate(line.price_discount);
            var m = clampRate(line.margin_discount);
            if (p > 0) {
                syncEquivalentDiscounts(line, "price_discount");
            } else if (m > 0) {
                syncEquivalentDiscounts(line, "margin_discount");
            }
        });
        var totals = calculate(state.quote.lines || []);
        state.quote.net_total = totals.net_total;
        state.quote.discount_total = totals.discount_total;
        state.quote.margin_percent = totals.margin_percent;
        state.quote.profit_total = totals.profit_total;
        els.net.textContent = formatMoney(totals.net_total);
        if (els.netHint) {
            els.netHint.textContent = "(Neto: " + formatMoney(round2((Number(totals.net_total) || 0) / IVA_FACTOR)) + ")";
        }
        els.discount.textContent = formatMoney(totals.discount_total);
        els.margin.textContent = formatPercent(totals.margin_percent);
        els.profit.textContent = totals.profit_total === null ? "—" : formatMoney(totals.profit_total);
        if (els.profitHint) {
            if (totals.profit_total === null) {
                els.profitHint.hidden = true;
                els.profitHint.textContent = "(Neto: —)";
            } else {
                els.profitHint.hidden = false;
                els.profitHint.textContent = "(Neto: " + formatMoney(round2((Number(totals.profit_total) || 0) / IVA_FACTOR)) + ")";
            }
        }
        var level = state.advanced ? alarmLevel(totals.profit_total, totals.margin_percent) : "";
        setAlarm(els.margin, level);
        setAlarm(els.profit, level);
        refreshLineFigures();
        refreshAdvancedDiscountInputs();
    }

    function refreshAdvancedDiscountInputs() {
        if (!state.advanced || !els.lines) return;
        var rows = els.lines.querySelectorAll("tr.cq-line");
        Array.prototype.forEach.call(rows, function (tr) {
            var index = Number(tr.dataset.index);
            var line = (state.quote.lines || [])[index];
            if (!line) return;
            var priceInput = tr.querySelector('input[data-field="price_discount"]');
            if (priceInput && document.activeElement !== priceInput) {
                priceInput.value = displayField("price_discount", line);
            }
            var marginInput = tr.querySelector('input[data-field="margin_discount"]');
            var unavailable = tr.querySelector(".cq-margin-unavailable");
            if (!lineHasUnitCost(line)) {
                if (marginInput && marginInput.parentNode) {
                    var td = marginInput.parentNode;
                    td.innerHTML = "";
                    td.appendChild(marginDiscountUnavailableNode());
                } else if (!unavailable) {
                    // Celda ya sin input: nada que refrescar.
                }
                return;
            }
            if (unavailable && unavailable.parentNode) {
                var cell = unavailable.parentNode;
                cell.innerHTML = "";
                // Reconstruir input editable si ahora hay costo (p. ej. tras recalc).
                var rebuilt = inputCell(line, index, "margin_discount", state.quote.editable !== false);
                while (rebuilt.firstChild) {
                    cell.appendChild(rebuilt.firstChild);
                }
                cell.className = rebuilt.className;
                return;
            }
            if (marginInput && document.activeElement !== marginInput) {
                marginInput.value = displayField("margin_discount", line);
            }
        });
    }

    function refreshLineFigures() {
        var rows = els.lines.querySelectorAll("tr.cq-line");
        Array.prototype.forEach.call(rows, function (tr) {
            var index = Number(tr.dataset.index);
            var line = (state.quote.lines || [])[index];
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
        var headers = els.lines.querySelectorAll("tr.cq-family-header");
        if (!headers.length) return;
        var plan = buildLineRenderPlan(state.quote.lines || []);
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
        renderModalContainsTags();
        setModalHint("");
        els.modal.hidden = false;
        els.modal.setAttribute("aria-hidden", "false");
        if (els.modalQ) {
            els.modalQ.focus();
            els.modalQ.select();
        }
        // Prefill no bloqueante: si hay texto, busca; si falla o vacío, el modal queda usable.
        if (prefill || (state.modalContains && state.modalContains.length)) {
            searchAdvanced();
        }
    }

    function closeAdvancedSearch() {
        if (!els.modal) return;
        els.modal.hidden = true;
        els.modal.setAttribute("aria-hidden", "true");
        setModalHint("");
    }

    function setManualHint(text, isError) {
        if (!els.manualHint) return;
        els.manualHint.textContent = text || "";
        els.manualHint.classList.toggle("is-error", !!isError && !!text);
    }

    function openManualModal() {
        if (!els.manualModal) return;
        if (state.quote.editable === false) return;
        resetManualForm();
        setManualHint("");
        els.manualModal.hidden = false;
        els.manualModal.setAttribute("aria-hidden", "false");
        if (els.manualConcepto) {
            els.manualConcepto.focus();
        } else if (els.manualQty) {
            els.manualQty.focus();
        }
    }

    function closeManualModal() {
        if (!els.manualModal) return;
        els.manualModal.hidden = true;
        els.manualModal.setAttribute("aria-hidden", "true");
        setManualHint("");
    }

    function resetManualForm() {
        if (els.manualQty) els.manualQty.value = "1";
        if (els.manualUnit) els.manualUnit.value = "";
        if (els.manualConcepto) els.manualConcepto.value = "";
        if (els.manualDescLarga) els.manualDescLarga.value = "";
        if (els.manualDescWrap) els.manualDescWrap.hidden = true;
        if (els.manualDescToggle) els.manualDescToggle.setAttribute("aria-expanded", "false");
        if (els.manualUnitNeto) els.manualUnitNeto.value = "0";
        if (els.manualUnitBruto) els.manualUnitBruto.value = "0";
        if (els.manualAdjType) els.manualAdjType.value = "descuento";
        if (els.manualPct) els.manualPct.value = "0";
        if (els.manualTotalNeto) els.manualTotalNeto.value = "0";
        if (els.manualTotalBruto) els.manualTotalBruto.value = "0";
    }

    function toggleManualDescLarga() {
        if (!els.manualDescWrap || !els.manualDescToggle) return;
        var open = els.manualDescWrap.hidden;
        els.manualDescWrap.hidden = !open;
        els.manualDescToggle.setAttribute("aria-expanded", open ? "true" : "false");
        if (open && els.manualDescLarga) {
            els.manualDescLarga.focus();
        }
    }

    function onManualPriceInput(sourceKey) {
        if (sourceKey === "manualUnitNeto" && els.manualUnitNeto && els.manualUnitBruto) {
            var neto = parseClNumber(els.manualUnitNeto.value);
            if (neto < 0) neto = 0;
            els.manualUnitBruto.value = formatModalNumber(round2(neto * IVA_FACTOR), 2);
        } else if (sourceKey === "manualUnitBruto" && els.manualUnitBruto && els.manualUnitNeto) {
            var bruto = parseClNumber(els.manualUnitBruto.value);
            if (bruto < 0) bruto = 0;
            els.manualUnitNeto.value = formatModalNumber(round2(bruto / IVA_FACTOR), 2);
        }
        syncManualTotals();
    }

    function syncManualTotals() {
        var qty = parseClNumber(els.manualQty ? els.manualQty.value : 0);
        if (!(qty > 0)) qty = 0;
        var unitBruto = parseClNumber(els.manualUnitBruto ? els.manualUnitBruto.value : 0);
        if (unitBruto < 0) unitBruto = 0;
        var pct = clampRate(parseClNumber(els.manualPct ? els.manualPct.value : 0));
        var adj = els.manualAdjType ? els.manualAdjType.value : "descuento";
        var effectiveBruto = unitBruto;
        if (adj === "recargo" && pct > 0) {
            effectiveBruto = round2(unitBruto * (1 + pct / 100));
        }
        var totalBruto = round2(qty * effectiveBruto);
        if (adj === "descuento" && pct > 0) {
            totalBruto = round2(totalBruto * (1 - pct / 100));
        }
        if (totalBruto < 0) totalBruto = 0;
        var totalNeto = round2(totalBruto / IVA_FACTOR);
        if (els.manualTotalNeto) {
            els.manualTotalNeto.value = formatModalNumber(totalNeto, 2);
        }
        if (els.manualTotalBruto) {
            els.manualTotalBruto.value = formatModalNumber(totalBruto, 2);
        }
    }

    function buildManualDescription() {
        var concepto = String(els.manualConcepto ? els.manualConcepto.value : "").trim().replace(/\s+/g, " ");
        var unit = String(els.manualUnit ? els.manualUnit.value : "").trim();
        var larga = String(els.manualDescLarga ? els.manualDescLarga.value : "").trim().replace(/\s+/g, " ");
        var parts = [];
        if (concepto) {
            parts.push(unit ? (concepto + " (" + unit + ")") : concepto);
        }
        if (larga) {
            parts.push(larga);
        }
        var desc = parts.join(" — ");
        if (desc.length > 500) {
            desc = desc.slice(0, 500);
        }
        return desc;
    }

    function submitManualLine() {
        if (state.quote.editable === false) return;
        var qty = parseClNumber(els.manualQty ? els.manualQty.value : 0);
        if (!(qty > 0)) {
            setManualHint("La cantidad debe ser mayor a cero.", true);
            if (els.manualQty) els.manualQty.focus();
            return;
        }
        var concepto = String(els.manualConcepto ? els.manualConcepto.value : "").trim();
        if (!concepto) {
            setManualHint("El concepto es obligatorio.", true);
            if (els.manualConcepto) els.manualConcepto.focus();
            return;
        }
        var description = buildManualDescription();
        if (!description) {
            setManualHint("El concepto es obligatorio.", true);
            if (els.manualConcepto) els.manualConcepto.focus();
            return;
        }
        var unitBruto = parseClNumber(els.manualUnitBruto ? els.manualUnitBruto.value : 0);
        if (unitBruto < 0) unitBruto = 0;
        var pct = clampRate(parseClNumber(els.manualPct ? els.manualPct.value : 0));
        var adj = els.manualAdjType ? els.manualAdjType.value : "descuento";
        var priceDiscount = 0;
        if (adj === "recargo" && pct > 0) {
            unitBruto = round2(unitBruto * (1 + pct / 100));
        } else if (adj === "descuento" && pct > 0) {
            priceDiscount = pct;
        }
        state.quote.lines.push({
            product_id: null,
            producto_base_id: null,
            sku: "",
            supplier_code: "",
            barcode: "",
            description: description,
            quantity: round3(qty),
            unit_price: round2(unitBruto),
            unit_cost: null,
            price_discount: priceDiscount,
            margin_discount: 0,
            discount_amount: 0,
            family_mode: "unitaria",
            packaging: "unitaria",
            units_per_pack: 1,
            local_only: true,
            _family: {},
            price_mode: "manual",
            price_ref: null,
            price_total: null
        });
        var line = state.quote.lines[state.quote.lines.length - 1];
        if (priceDiscount > 0) {
            syncEquivalentDiscounts(line, "price_discount");
        }
        closeManualModal();
        renderLines();
        renderTotals();
        scheduleAutosave(0);
        setMessage("Detalle manual agregado.", false);
    }

    function normalizeContainsWord(raw) {
        return String(raw || "").trim().replace(/\s+/g, " ");
    }

    function addModalContainsWord() {
        if (!els.modalContains) return;
        var word = normalizeContainsWord(els.modalContains.value);
        if (!word) return;
        if (word.length < 2) {
            setModalHint("Cada palabra debe tener al menos 2 caracteres.", true);
            return;
        }
        var exists = (state.modalContains || []).some(function (w) {
            return w.toLowerCase() === word.toLowerCase();
        });
        if (!exists) {
            state.modalContains.push(word);
        }
        els.modalContains.value = "";
        renderModalContainsTags();
        els.modalContains.focus();
        if ((els.modalQ && els.modalQ.value.trim()) || state.modalContains.length) {
            searchAdvanced();
        }
    }

    function removeModalContainsWord(index) {
        if (!state.modalContains || index < 0 || index >= state.modalContains.length) return;
        state.modalContains.splice(index, 1);
        renderModalContainsTags();
        if ((els.modalQ && els.modalQ.value.trim()) || state.modalContains.length) {
            searchAdvanced();
        } else {
            state.modalResults = [];
            renderModalResults();
            setModalHint("");
        }
    }

    function renderModalContainsTags() {
        if (!els.modalContainsTags) return;
        els.modalContainsTags.innerHTML = "";
        (state.modalContains || []).forEach(function (word, index) {
            var tag = document.createElement("span");
            tag.className = "cq-contains-tag";
            var label = document.createElement("span");
            label.textContent = word;
            var remove = document.createElement("button");
            remove.type = "button";
            remove.className = "cq-contains-tag-remove";
            remove.setAttribute("aria-label", "Quitar «" + word + "»");
            remove.textContent = "×";
            remove.addEventListener("click", function () {
                removeModalContainsWord(index);
            });
            tag.appendChild(label);
            tag.appendChild(remove);
            els.modalContainsTags.appendChild(tag);
        });
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
        var contains = (state.modalContains || []).slice();
        if (!query && !contains.length) {
            setModalHint("Ingresa un término de búsqueda o agrega una palabra.", true);
            state.modalResults = [];
            renderModalResults();
            return;
        }
        if (!query && contains.length) {
            // Sin caja principal: la primera palabra alimenta la búsqueda; el resto filtra.
            query = contains[0];
        }
        if ((scope === "descripcion") && query.length < 2 && !contains.length) {
            setModalHint("Escribe al menos 2 caracteres para buscar por descripción.", true);
            state.modalResults = [];
            renderModalResults();
            return;
        }
        setModalHint("Buscando…");
        post(cfg.actions.search, {
            q: query,
            mode: "advanced",
            scope: scope,
            channel: currentChannel(),
            contains: JSON.stringify(contains)
        }).then(function (data) {
            state.modalResults = data.products || [];
            renderModalResults();
            if (state.modalResults.length === 0) {
                var label = contains.length
                    ? ("«" + query + "» + contiene: " + contains.join(", "))
                    : ("«" + query + "»");
                setModalHint(data.hint || ("Sin resultados para " + label + "."), true);
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
            if (product.quantity != null && Number(product.quantity) > 1) {
                meta.textContent += " · Cantidad " + formatQty(product.quantity);
            }
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
            if (product.quantity != null && Number(product.quantity) > 1) {
                meta.textContent += " · Cantidad " + formatQty(product.quantity);
            }
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
                _family: fam,
                price_mode: product.price_mode || "auto",
                price_ref: product.price_ref != null ? Number(product.price_ref) : null,
                price_total: product.price_total != null ? Number(product.price_total) : null
            });
        }
        var target = existing || lines[lines.length - 1];
        els.results.hidden = true;
        if (target && target.producto_base_id) {
            var idx = lines.indexOf(target);
            trackPriceRecalc(recalcLocalLinePrice(target, idx >= 0 ? idx : 0)).then(function () {
                scheduleAutosave(0);
            });
        } else {
            renderLines();
            renderTotals();
            scheduleAutosave(0);
        }
        setMessage(lacksLocalPrice(product)
            ? ("Producto agregado a $0 (sin precio Local): «" + (product.sku || "?") + "».")
            : (addQty > 1
                ? ("Producto agregado: «" + (product.sku || "?") + "» × " + formatQty(addQty) + ".")
                : "Producto agregado."), false);
        return true;
    }

    function buildSavePayload() {
        syncHeader();
        return {
            id: state.quote.id,
            customer_id: state.quote.customer_id,
            customer_name: state.quote.customer_name,
            quote_type: state.quote.quote_type,
            channel: state.quote.channel || currentChannel(),
            issue_date: state.quote.issue_date || todayIsoDate(),
            validity_days: state.quote.validity_days,
            validity_terms: state.quote.validity_terms,
            lines: (state.quote.lines || []).map(function (line) {
                var gid = lineGrupoId(line);
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
                    units_per_pack: line.units_per_pack != null ? line.units_per_pack : null,
                    grupo_id: gid || null,
                    rule_total: line._rule_adjusted && line._rule_total != null
                        ? line._rule_total
                        : null,
                    rule_adjusted: !!line._rule_adjusted,
                    price_mode: linePriceMode(line),
                    price_ref: linePriceMode(line) === "ref" && line.price_ref != null
                        ? line.price_ref
                        : null,
                    price_total: linePriceMode(line) === "manual" && line.price_total != null
                        ? line.price_total
                        : null
                };
            })
        };
    }

    function setSaveStatus(text, kind) {
        if (!els.saveStatus) return;
        els.saveStatus.textContent = text || "";
        els.saveStatus.className = "cq-save-status" + (kind ? " is-" + kind : "");
    }

    function scheduleAutosave(delayMs) {
        if (state.quote.editable === false) {
            return;
        }
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        var wait = delayMs == null ? 400 : delayMs;
        state.autosave.timer = setTimeout(function () {
            state.autosave.timer = null;
            flushAutosave();
        }, wait);
    }

    function flushAutosave() {
        if (state.quote.editable === false) {
            return;
        }
        syncHeader();
        var hasId = !!state.quote.id;
        var lineCount = (state.quote.lines || []).length;
        if (!hasId && lineCount === 0) {
            return;
        }
        if (state.autosave.saving) {
            state.autosave.pending = true;
            return;
        }
        var chain = state.priceRecalcChain || Promise.resolve();
        chain.then(function () {
            return performSave({ silent: true });
        }).catch(function () {
            // Error ya informado en performSave.
        });
    }

    function trackPriceRecalc(promise) {
        var tracked = Promise.resolve(promise).catch(function () { return null; });
        state.priceRecalcChain = (state.priceRecalcChain || Promise.resolve())
            .then(function () { return tracked; })
            .catch(function () { return null; });
        return tracked;
    }

    function applySavedMeta(quote) {
        if (!quote) return;
        state.quote.id = quote.id;
        state.quote.quote_number = quote.quote_number;
        state.quote.status = quote.status;
        state.quote.status_label = quote.status_label;
        state.quote.created_at = quote.created_at;
        if (quote.issue_date) {
            state.quote.issue_date = toDateInputValue(quote.issue_date) || state.quote.issue_date;
        }
        state.quote.seller_name = quote.seller_name;
        state.quote.updated_at = quote.updated_at;
        state.quote.allowed_transitions = quote.allowed_transitions || [];
        state.quote.editable = quote.editable !== false;
        state.quote.can_invoice = !!quote.can_invoice;
        state.quote.order_id = quote.order_id;
        state.quote.order_url = quote.order_url || "";
        state.quote.is_expired = !!quote.is_expired;
        state.quote.net_total = quote.net_total;
        state.quote.discount_total = quote.discount_total;
        state.quote.margin_percent = quote.margin_percent;
        state.quote.profit_total = quote.profit_total;

        if (els.quoteNumber) {
            els.quoteNumber.value = quote.quote_number || "";
        }
        if (els.title) {
            els.title.textContent = quote.quote_number || "Nueva cotización";
        }
        if (els.status) {
            els.status.textContent = quote.status_label || "Borrador";
            els.status.className = "cq-badge cq-badge-" + (quote.status || "draft");
        }
        if (els.issueDate && state.quote.issue_date) {
            els.issueDate.value = toDateInputValue(state.quote.issue_date);
        }
        if (els.expiredBadge) {
            els.expiredBadge.hidden = !state.quote.is_expired;
        }
        var transition = (state.quote.allowed_transitions || [])[0];
        if (state.quote.id && transition) {
            els.transition.hidden = false;
            els.transition.textContent = transition.label;
            els.transition.dataset.status = transition.status;
        } else {
            els.transition.hidden = true;
        }
        if (els.invoice) {
            var canInvoice = !!state.quote.can_invoice
                || (state.quote.status === "listed" && state.quote.quote_type === "venta" && !state.quote.order_id);
            els.invoice.hidden = !(state.quote.id && canInvoice);
        }
        if (els.orderLink) {
            if (state.quote.order_id && state.quote.order_url) {
                els.orderLink.hidden = false;
                els.orderLink.href = state.quote.order_url;
                els.orderLink.textContent = "Ver pedido #" + state.quote.order_id;
            } else {
                els.orderLink.hidden = true;
                els.orderLink.removeAttribute("href");
            }
        }
        state.snapshot = serialize(state.quote);
    }

    function performSave(opts) {
        opts = opts || {};
        var silent = !!opts.silent;
        syncHeader();
        var hasId = !!state.quote.id;
        var lineCount = (state.quote.lines || []).length;
        if (!hasId && lineCount === 0) {
            if (!silent) {
                setMessage("Agrega al menos un producto para guardar.", true);
            }
            return Promise.resolve(null);
        }
        if (state.quote.editable === false) {
            if (!silent) {
                setMessage("Esta cotización no se puede editar.", true);
            }
            return Promise.resolve(null);
        }
        if (state.autosave.saving) {
            state.autosave.pending = true;
            return Promise.resolve(null);
        }
        state.autosave.saving = true;
        setSaveStatus("Guardando…", "saving");
        if (!silent) {
            els.save.disabled = true;
        }
        return post(cfg.actions.save, { payload: JSON.stringify(buildSavePayload()) }).then(function (data) {
            if (silent) {
                applySavedMeta(data.quote);
            } else {
                state.quote = data.quote;
                paintEditor();
                state.snapshot = serialize(state.quote);
                setMessage(data.message || "Cotización guardada.", false);
            }
            setSaveStatus("Guardado", "saved");
            return data.quote;
        }).catch(function (error) {
            setSaveStatus("Error al guardar", "error");
            setMessage(error.message, true);
            throw error;
        }).finally(function () {
            state.autosave.saving = false;
            if (!silent) {
                els.save.disabled = false;
            }
            if (state.autosave.pending) {
                state.autosave.pending = false;
                scheduleAutosave(150);
            }
        });
    }

    function saveQuote(silent) {
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        var chain = state.priceRecalcChain || Promise.resolve();
        return chain.then(function () {
            return performSave({ silent: !!silent });
        }).catch(function () {
            return null;
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
            issue_date: toDateInputValue(quote.issue_date) || "",
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
        var bruto = formatMoney(state.quote.net_total);
        var neto = formatMoney(round2((Number(state.quote.net_total) || 0) / IVA_FACTOR));
        var n = lines.length;
        var msg = "¿Facturar cotización " + (state.quote.quote_number || "") + "?\n"
            + "Cliente: " + cliente + "\n"
            + "Bruto: " + bruto + " (Neto: " + neto + ")\n"
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
        var lines = state.quote.lines || [];
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
        if (priceRate > 0) {
            // Canónico: un solo descuento = % sobre el bruto (margen es vista equivalente).
            discount = round2(gross * priceRate / 100);
        } else if (marginRate > 0 && unitCost !== null) {
            // Legado: solo dscto margen (sin precio).
            var marginBase = round2(gross - round2(billable * unitCost));
            discount = marginBase > 0 ? round2(marginBase * marginRate / 100) : 0;
        } else {
            discount = round2(Number(line.discount_amount) || 0);
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
        if (interactive) {
            scheduleAutosave(0);
        }
    }

    function linePriceMode(line) {
        var mode = line && line.price_mode ? String(line.price_mode).toLowerCase() : "auto";
        if (mode === "manual" || mode === "ref") return mode;
        return "auto";
    }

    function familyGroupPriceMode(grupoId, lines) {
        lines = lines || state.quote.lines || [];
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
        var lines = state.quote.lines || [];
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
            return n / IVA_FACTOR;
        }
        return n;
    }

    function viewToBruto(value) {
        var n = Number(value) || 0;
        if (state.lineModal.taxView === "neto") {
            return n * IVA_FACTOR;
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
        if (els.lineTaxNeto) els.lineTaxNeto.classList.toggle("is-active", state.lineModal.taxView === "neto");
        if (els.lineTaxBruto) els.lineTaxBruto.classList.toggle("is-active", state.lineModal.taxView === "bruto");
        refreshLineModalFields(true);
    }

    function setLineModalMode(mode) {
        if (mode !== "manual" && mode !== "ref") mode = "auto";
        state.lineModal.mode = mode;
        if (els.lineModeManual) {
            els.lineModeManual.checked = mode === "manual";
        }
        if (els.lineModeRadios) {
            Array.prototype.forEach.call(els.lineModeRadios.querySelectorAll('input[name="cq-line-mode"]'), function (radio) {
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
        } else if (mode === "ref" || mode === "auto") {
            scheduleLineModalPreview();
        } else {
            refreshLineModalFields(true);
        }
    }

    function updateLineModalModeUi() {
        var lm = state.lineModal;
        var editablePrice = lm.mode === "manual";
        var showPref = lm.mode === "ref";
        if (els.linePrefField) els.linePrefField.hidden = !showPref;
        if (els.lineUnit) els.lineUnit.disabled = !editablePrice;
        if (els.lineTotal) els.lineTotal.disabled = !editablePrice;
        if (els.linePref) els.linePref.disabled = !showPref;
        if (els.lineUnitLabel) {
            els.lineUnitLabel.textContent = lm.taxView === "neto" ? "Precio unitario (neto)" : "Precio unitario";
        }
        if (els.lineTotalLabel) {
            if (lm.isFamily && lm.mode === "manual") {
                els.lineTotalLabel.textContent = lm.taxView === "neto"
                    ? "Precio total familia (neto)"
                    : "Precio total familia";
            } else {
                els.lineTotalLabel.textContent = lm.taxView === "neto" ? "Precio total (neto)" : "Precio total";
            }
        }
    }

    function lineModalBillable() {
        var line = (state.quote.lines || [])[state.lineModal.index];
        if (!line) return 1;
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
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
        var otherUnit = lm.taxView === "bruto" ? (unitBruto / IVA_FACTOR) : unitBruto;
        var otherTotal = lm.taxView === "bruto" ? (totalBruto / IVA_FACTOR) : totalBruto;
        if (els.lineUnit && (fromState || document.activeElement !== els.lineUnit)) {
            els.lineUnit.value = formatModalNumber(unitView, 4);
        }
        if (els.lineTotal && (fromState || document.activeElement !== els.lineTotal)) {
            els.lineTotal.value = formatModalNumber(totalView, 2);
        }
        if (els.lineUnitHint) {
            els.lineUnitHint.textContent = lm.taxView === "bruto"
                ? ("Sin IVA: " + formatModalNumber(otherUnit, 4))
                : ("Con IVA: " + formatModalNumber(otherUnit, 4));
        }
        if (els.lineTotalHint) {
            els.lineTotalHint.textContent = lm.taxView === "bruto"
                ? ("Sin IVA: " + formatModalNumber(otherTotal, 2))
                : ("Con IVA: " + formatModalNumber(otherTotal, 2));
        }
        if (els.linePref && lm.mode === "ref" && (fromState || document.activeElement !== els.linePref)) {
            var pref = Number(lm.priceRef) || 0;
            els.linePref.value = formatModalNumber(brutoToView(pref), 4);
        }
        if (els.linePrefHint) {
            if (lm.mode === "ref") {
                var prefB = Number(lm.priceRef) || 0;
                var otherPref = lm.taxView === "bruto" ? (prefB / IVA_FACTOR) : prefB;
                els.linePrefHint.textContent = lm.taxView === "bruto"
                    ? ("Sin IVA: " + formatModalNumber(otherPref, 4))
                    : ("Con IVA: " + formatModalNumber(otherPref, 4));
            } else {
                els.linePrefHint.textContent = "";
            }
        }
        updateLineModalRuleInfo();
        lm.syncing = false;
    }

    function updateLineModalRuleInfo() {
        var lm = state.lineModal;
        if (!els.lineRuleInfo) return;
        var parts = [];
        if (lm.catalogP != null) parts.push("P catálogo: " + formatMoney(lm.catalogP));
        if (lm.unitario0 != null) parts.push("unitario0: " + formatUnitPrice(lm.unitario0));
        if (lm.ruleTotal != null) parts.push("T_final: " + formatMoney(lm.ruleTotal));
        if (parts.length) {
            els.lineRuleInfo.hidden = false;
            els.lineRuleInfo.textContent = parts.join(" · ");
        } else {
            els.lineRuleInfo.hidden = true;
            els.lineRuleInfo.textContent = "";
        }
        if (els.linePreview) {
            if (lm.mode === "ref") {
                els.linePreview.textContent = "Vista previa con P de referencia: unitario "
                    + formatUnitPrice(lm.unitGross) + " · total " + formatMoney(lm.totalGross);
            } else if (lm.mode === "manual") {
                els.linePreview.textContent = "Precio final fijo (se respeta al cambiar cantidad).";
            } else {
                els.linePreview.textContent = "Precio automático de catálogo/regla.";
            }
        }
    }

    function lineModalFamilyQty() {
        var line = (state.quote.lines || [])[state.lineModal.index];
        if (!line) return lineModalBillable();
        var gid = lineGrupoId(line);
        if (!gid) return lineModalBillable();
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        var lines = (state.quote.lines || []).slice();
        var draft = {};
        Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
        draft.quantity = qty;
        lines[state.lineModal.index] = draft;
        var total = totalFamilyUnitsQuoted(gid, lines);
        return total > 0 ? total : 1;
    }

    function onLineModalQtyInput(format) {
        if (state.lineModal.syncing) return;
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : "0");
        if (qty < 0) qty = 0;
        if (format && els.lineQty) {
            els.lineQty.value = formatQty(qty);
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
        var view = parseClNumber(els.linePref ? els.linePref.value : "0");
        state.lineModal.priceRef = round4(viewToBruto(view));
        if (format && els.linePref) {
            els.linePref.value = formatModalNumber(brutoToView(state.lineModal.priceRef), 4);
        }
        scheduleLineModalPreview();
    }

    function onLineModalUnitInput(format) {
        if (state.lineModal.syncing || state.lineModal.mode !== "manual") return;
        var view = parseClNumber(els.lineUnit ? els.lineUnit.value : "0");
        state.lineModal.unitGross = round4(viewToBruto(view));
        var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
        state.lineModal.totalGross = round2(state.lineModal.unitGross * scale);
        if (format) refreshLineModalFields(true);
        else refreshLineModalFields(false);
        syncLineModalDiscountFields("price_discount", false);
    }

    function onLineModalTotalInput(format) {
        if (state.lineModal.syncing || state.lineModal.mode !== "manual") return;
        var view = parseClNumber(els.lineTotal ? els.lineTotal.value : "0");
        state.lineModal.totalGross = round2(viewToBruto(view));
        var scale = state.lineModal.isFamily ? lineModalFamilyQty() : lineModalBillable();
        state.lineModal.unitGross = scale > 0 ? round4(state.lineModal.totalGross / scale) : 0;
        if (format) refreshLineModalFields(true);
        else refreshLineModalFields(false);
        syncLineModalDiscountFields("price_discount", false);
    }

    function lineModalDraftForDiscount() {
        var line = (state.quote.lines || [])[state.lineModal.index];
        if (!line) return null;
        var draft = {};
        Object.keys(line).forEach(function (key) { draft[key] = line[key]; });
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
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
        draft.price_discount = clampRate(parseClNumber(els.linePriceDiscount ? els.linePriceDiscount.value : "0"));
        draft.margin_discount = clampRate(parseClNumber(els.lineMarginDiscount ? els.lineMarginDiscount.value : "0"));
        return draft;
    }

    function syncLineModalDiscountFields(source, format) {
        var draft = lineModalDraftForDiscount();
        if (!draft) return;
        if (source === "margin_discount") {
            draft.margin_discount = clampRate(parseClNumber(els.lineMarginDiscount ? els.lineMarginDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "margin_discount");
        } else {
            draft.price_discount = clampRate(parseClNumber(els.linePriceDiscount ? els.linePriceDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "price_discount");
        }
        state.lineModal.syncing = true;
        if (els.linePriceDiscount && (format || source === "margin_discount" || document.activeElement !== els.linePriceDiscount)) {
            els.linePriceDiscount.value = formatPlain(draft.price_discount || 0);
        }
        if (els.lineMarginDiscount && (format || source === "price_discount" || document.activeElement !== els.lineMarginDiscount)) {
            if (lineHasUnitCost(draft)) {
                els.lineMarginDiscount.value = formatPlain(draft.margin_discount || 0);
            } else {
                els.lineMarginDiscount.value = "";
            }
        }
        state.lineModal.syncing = false;
        updateLineModalDiscountHint(draft);
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
        if (els.lineMarginDiscount) {
            els.lineMarginDiscount.hidden = !hasCost;
            els.lineMarginDiscount.disabled = !hasCost;
        }
        if (els.lineMarginNa) {
            els.lineMarginNa.hidden = hasCost;
        }
        if (els.lineMarginHelp) {
            els.lineMarginHelp.hidden = hasCost;
            els.lineMarginHelp.title = "Costo no encontrado";
        }
        if (!els.lineDiscountHint) return;
        if (!hasCost) {
            els.lineDiscountHint.textContent = "Sin costo guardado: solo se puede editar el dscto precio.";
            return;
        }
        var bases = discountBases(line);
        if (!(bases.marginMoney > 0)) {
            els.lineDiscountHint.textContent = "Sin margen para equivaler.";
            return;
        }
        els.lineDiscountHint.textContent = "Dscto precio y dscto margen son el mismo descuento (equivalentes).";
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
        var line = (state.quote.lines || [])[state.lineModal.index];
        if (!line || !cfg.actions || !cfg.actions.familyPrice) {
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
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
        if (!(qty > 0)) qty = Number(line.quantity) || 1;
        var draft;
        var familyQty;
        if (gid) {
            // Preview with draft qty for this line only.
            var lines = (state.quote.lines || []).slice();
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
            family_qty: String(familyQty)
        };
        if (state.lineModal.mode === "ref") {
            var pref = Number(state.lineModal.priceRef);
            if (!(pref > 0) && els.linePref) {
                pref = round4(viewToBruto(parseClNumber(els.linePref.value)));
            }
            if (pref > 0) {
                payload.p_ref = String(pref);
                state.lineModal.priceRef = pref;
            }
        }
        var seq = ++state.lineModal.previewSeq;
        post(cfg.actions.familyPrice, payload).then(function (data) {
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
        lm.hasRule = !!(data.has_rule || (data.pricing && data.pricing.has_rule) || ruleAdjusted || (data.unit_price != null && lm.catalogP != null && Number(data.unit_price) !== Number(lm.catalogP)));
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
        configureLineModalModes(lm.hasRule, !!gid);
        refreshLineModalFields(true);
    }

    function configureLineModalModes(hasRule, isFamily) {
        var lm = state.lineModal;
        lm.hasRule = !!hasRule;
        lm.isFamily = !!isFamily;
        if (els.lineFamilyNote) {
            if (isFamily) {
                els.lineFamilyNote.hidden = false;
                els.lineFamilyNote.textContent = "Los cambios de precio aplican a toda la familia en esta cotización.";
            } else {
                els.lineFamilyNote.hidden = true;
                els.lineFamilyNote.textContent = "";
            }
        }
        if (hasRule && isFamily) {
            if (els.lineModeManualWrap) els.lineModeManualWrap.hidden = true;
            if (els.lineModeRadios) els.lineModeRadios.hidden = false;
        } else {
            if (els.lineModeManualWrap) els.lineModeManualWrap.hidden = false;
            if (els.lineModeRadios) els.lineModeRadios.hidden = true;
            if (lm.mode === "ref") lm.mode = "auto";
        }
        if (els.lineModeManual) els.lineModeManual.checked = lm.mode === "manual";
        if (els.lineModeRadios) {
            Array.prototype.forEach.call(els.lineModeRadios.querySelectorAll('input[name="cq-line-mode"]'), function (radio) {
                radio.checked = radio.value === lm.mode;
            });
        }
        updateLineModalModeUi();
    }

    function openLineModal(index) {
        if (!els.lineModal) return;
        var line = (state.quote.lines || [])[index];
        if (!line || state.quote.editable === false) return;
        var lm = state.lineModal;
        lm.index = index;
        lm.taxView = "bruto";
        lm.mode = linePriceMode(line);
        lm.hasRule = false;
        lm.isFamily = !!lineGrupoId(line);
        lm.catalogP = null;
        lm.unitario0 = null;
        lm.ruleTotal = line._rule_total != null ? Number(line._rule_total) : null;
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
        if (els.lineSubtitle) {
            els.lineSubtitle.textContent = (line.sku || "") + (line.description ? (" — " + line.description) : "");
        }
        if (els.lineQty) {
            els.lineQty.value = formatQty(line.quantity);
        }
        if (els.lineQtyHint) {
            var pack = presentationLabel(line);
            els.lineQtyHint.textContent = pack || "";
        }
        if (els.linePriceDiscount) {
            els.linePriceDiscount.value = formatPlain(line.price_discount || 0);
        }
        if (els.lineMarginDiscount) {
            els.lineMarginDiscount.value = lineHasUnitCost(line)
                ? formatPlain(line.margin_discount || 0)
                : "";
        }
        updateLineModalDiscountHint(line);
        setLineTaxView("bruto");
        configureLineModalModes(false, lm.isFamily);
        setLineModalMode(lm.mode);
        els.lineModal.hidden = false;
        els.lineModal.setAttribute("aria-hidden", "false");
        scheduleLineModalPreview();
        if (els.lineQty) els.lineQty.focus();
    }

    function closeLineModal() {
        if (state.lineModal.debounceTimer) {
            clearTimeout(state.lineModal.debounceTimer);
            state.lineModal.debounceTimer = null;
        }
        state.lineModal.index = -1;
        if (!els.lineModal) return;
        els.lineModal.hidden = true;
        els.lineModal.setAttribute("aria-hidden", "true");
    }

    function applyLineModal() {
        var index = state.lineModal.index;
        var line = (state.quote.lines || [])[index];
        if (!line) {
            closeLineModal();
            return;
        }
        var qty = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
        if (qty < 0) qty = 0;
        qty = round3(qty);
        if (!(qty > 0)) {
            setMessage("La cantidad debe ser mayor a cero.", true);
            return;
        }
        var mode = state.lineModal.mode;
        var draft = lineModalDraftForDiscount();
        if (draft) {
            var priceSrc = clampRate(parseClNumber(els.linePriceDiscount ? els.linePriceDiscount.value : "0"));
            var marginSrc = clampRate(parseClNumber(els.lineMarginDiscount ? els.lineMarginDiscount.value : "0"));
            if (document.activeElement === els.lineMarginDiscount || (priceSrc <= 0 && marginSrc > 0)) {
                draft.margin_discount = marginSrc;
                syncEquivalentDiscounts(draft, "margin_discount");
            } else {
                draft.price_discount = priceSrc;
                syncEquivalentDiscounts(draft, "price_discount");
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
            } else {
                applyPriceModeToGroup(gid, "auto", {});
            }
            closeLineModal();
            trackPriceRecalc(recalcFamilyPrices(gid)).then(function () {
                scheduleAutosave(0);
            });
            setMessage("Línea actualizada (aplica a la familia).", false);
            return;
        }

        line.price_mode = mode;
        if (mode === "manual") {
            line.unit_price = unitPrice;
            line.price_total = round2(unitPrice * lineBillableUnits(line));
            line.price_ref = null;
            line._rule_adjusted = false;
            line._rule_total = null;
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
                scheduleAutosave(0);
            });
        } else {
            renderLines();
            renderTotals();
            scheduleAutosave(0);
        }
        setMessage("Línea actualizada.", false);
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
                + ". Elegir reemplaza esta línea; Agregar suma 1 unidad. Sin SKU Local no se puede elegir ni agregar.";
        }
        renderFamilyMembers(line, members);
        updateFamilyOpenAdminButton(line);
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
        if (els.familyOpenAdmin) {
            els.familyOpenAdmin.disabled = true;
            els.familyOpenAdmin.dataset.grupoId = "";
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
        if (!els.familyOpenAdmin) return;
        var gid = familyGrupoIdFromLine(line);
        els.familyOpenAdmin.dataset.grupoId = gid > 0 ? String(gid) : "";
        els.familyOpenAdmin.disabled = !(gid > 0);
        els.familyOpenAdmin.title = gid > 0
            ? "Abrir editor de familia en Categorías → Familias"
            : "Sin grupo de familia para abrir";
    }

    function productsQuickSearchUrl(line) {
        var base = cfg.productsUrl || "";
        if (!base) {
            var admin = (cfg.adminUrl || "").replace(/\?.*$/, "");
            if (!admin) {
                admin = (window.ajaxurl || "/wp-admin/admin-ajax.php").replace(/admin-ajax\.php.*$/, "admin.php");
            }
            base = admin + (admin.indexOf("?") >= 0 ? "&" : "?") + "page=riverso-pos-products";
        }
        var pb = line && line.producto_base_id != null ? Number(line.producto_base_id) : 0;
        var sep = base.indexOf("?") >= 0 ? "&" : "?";
        if (pb > 0) {
            return base + sep + "quick_id=" + encodeURIComponent(String(pb));
        }
        var sku = line && line.sku ? String(line.sku).trim() : "";
        if (!sku) {
            return base;
        }
        return base + sep + "quick=" + encodeURIComponent(sku);
    }

    function openFamilyInAdmin() {
        if (!els.familyOpenAdmin || els.familyOpenAdmin.disabled) return;
        var gid = Number(els.familyOpenAdmin.dataset.grupoId || 0);
        if (!(gid > 0)) {
            setMessage("No hay familia asociada para abrir.", true);
            return;
        }
        var base = (cfg.adminUrl || "").replace(/\?.*$/, "");
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
                        setMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
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
            els.familyMembers.appendChild(li);
        });
    }

    function addFamilyMemberLine(member) {
        if (!memberHasLocalSku(member)) {
            setMessage("No se puede agregar «" + memberDisplayName(member) + "»: sin SKU Local.", true);
            return;
        }
        var source = (state.quote.lines || [])[state.familyLineIndex] || {};
        var fam = source._family || {};
        var upp = Number(member.cantidad_unidades || 1);
        if (!(upp > 0)) upp = 1;
        var mode = member.es_unitario ? "unitaria" : "pack";
        var pb = member.producto_base_id != null ? Number(member.producto_base_id) : 0;
        var sku = String(member.sku || "").trim();
        var lines = state.quote.lines || [];
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
                    scheduleAutosave(0);
                });
            } else {
                renderLines();
                renderTotals();
                scheduleAutosave(0);
            }
            setMessage("Se sumó 1 a «" + (existing.sku || memberDisplayName(member)) + "».", false);
            return;
        }
        addProduct({
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
        var lines = state.quote.lines || [];
        var line = lines[index];
        if (!line || !member) return;
        if (!memberHasLocalSku(member)) {
            setMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
            return;
        }
        var newSkuRaw = String(member.sku_local || member.sku || "").trim();
        if (!newSkuRaw) {
            setMessage("No se puede elegir «" + memberDisplayName(member) + "»: sin SKU Local.", true);
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
                    scheduleAutosave(0);
                });
            } else {
                renderLines();
                renderTotals();
                scheduleAutosave(0);
            }
            setMessage("Se sumó la cantidad al SKU ya presente en la cotización.", false);
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
                scheduleAutosave(0);
            });
        } else {
            renderLines();
            renderTotals();
            scheduleAutosave(0);
        }
        setMessage("Presentación cambiada a «" + newSkuRaw + "».", false);
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
        lines = lines || state.quote.lines || [];
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
        if (!grupoId || !cfg.actions || !cfg.actions.familyPrice) {
            renderLines();
            renderTotals();
            return Promise.resolve();
        }
        var lines = state.quote.lines || [];
        var indexes = familyLineIndexes(grupoId, lines);
        if (!indexes.length) {
            renderLines();
            renderTotals();
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
                } else {
                    line._rule_total = null;
                    line._rule_adjusted = false;
                }
                line.price_ref = null;
            });
            renderLines();
            renderTotals();
            return Promise.resolve();
        }

        var priceBaseId = familyPriceBaseId(grupoId, lines, indexes);
        if (!priceBaseId) {
            renderLines();
            renderTotals();
            return Promise.resolve();
        }
        var seq = ++state.familyPriceSeq;
        var payload = {
            producto_base_id: String(priceBaseId),
            family_qty: String(familyQty)
        };
        if (mode === "ref") {
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
        return post(cfg.actions.familyPrice, payload).then(function (data) {
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
            renderLines();
            renderTotals();
        }).catch(function () {
            if (seq !== state.familyPriceSeq) return;
            renderLines();
            renderTotals();
        });
    }

    function recalcAllFamilyGroups() {
        var seen = {};
        var gids = [];
        (state.quote.lines || []).forEach(function (line) {
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
        if (!pb || !cfg.actions || !cfg.actions.familyPrice) {
            renderLines();
            renderTotals();
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
            renderLines();
            renderTotals();
            return Promise.resolve();
        }
        var packs2 = Number(line.quantity || 1);
        var upp2 = lineUnitsPerPack(line);
        if (!(upp2 > 0)) { upp2 = 1; }
        var seq = ++state.familyPriceSeq;
        var payload = {
            producto_base_id: String(pb),
            family_qty: String(packs2 * upp2)
        };
        if (linePriceMode(line) === "ref" && line.price_ref != null && Number(line.price_ref) > 0) {
            payload.p_ref = String(line.price_ref);
        }
        return post(cfg.actions.familyPrice, payload).then(function (data) {
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

    function pdfTemplateKey() {
        return "riverso_cq_pdf_template";
    }

    function normalizePdfTemplate(value) {
        return value === "product" ? "product" : "family";
    }

    function restorePdfTemplate() {
        if (!els.pdfTemplate) return;
        var stored = "";
        try {
            stored = window.localStorage.getItem(pdfTemplateKey()) || "";
        } catch (err) {
            stored = "";
        }
        els.pdfTemplate.value = normalizePdfTemplate(stored);
    }

    function persistPdfTemplate(value) {
        var template = normalizePdfTemplate(value);
        try {
            window.localStorage.setItem(pdfTemplateKey(), template);
        } catch (err) {
            // ignore quota / private mode
        }
        if (els.pdfTemplate) {
            els.pdfTemplate.value = template;
        }
    }

    function openQuotePdf() {
        var id = state.quote && state.quote.id != null ? Number(state.quote.id) : 0;
        if (!(id > 0)) {
            setMessage("Guarda la cotización antes de generar el PDF.", true);
            return;
        }
        if (!cfg.ajaxUrl || !cfg.nonce) {
            setMessage("No se pudo abrir el PDF (configuración incompleta).", true);
            return;
        }
        var template = normalizePdfTemplate(els.pdfTemplate ? els.pdfTemplate.value : "family");
        persistPdfTemplate(template);
        var action = (cfg.actions && cfg.actions.pdf) ? cfg.actions.pdf : "riverso_cq_pdf";
        var url = cfg.ajaxUrl
            + (cfg.ajaxUrl.indexOf("?") >= 0 ? "&" : "?")
            + "action=" + encodeURIComponent(action)
            + "&id=" + encodeURIComponent(String(id))
            + "&template=" + encodeURIComponent(template)
            + "&nonce=" + encodeURIComponent(cfg.nonce);
        var win = window.open(url, "_blank");
        if (!win) {
            setMessage("El navegador bloqueó la ventana del PDF. Permite ventanas emergentes.", true);
            return;
        }
        setMessage("PDF abierto en una pestaña nueva. Usa Imprimir → Guardar como PDF.", false);
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
