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
        modalPage: 1,
        modalPages: 1,
        modalTotal: 0,
        modalPerPage: 30,
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
            pending: false,
            inflight: null
        },
        discardedId: null,
        lineModal: {
            index: -1,
            taxView: "bruto",
            mode: "auto",
            hasRule: false,
            isFamily: false,
            hasLocalProduct: false,
            syncing: false,
            previewSeq: 0,
            debounceTimer: null,
            catalogP: null,
            unitario0: null,
            ruleTotal: null,
            ruleCodigo: null,
            ruleNombre: null,
            stdRule: null,
            editingField: null
        },
        boundCustomerName: "",
        customerSearchRows: []
    };

    var IVA_FACTOR = 1.19;
    var MSG_DISCARDED_NEW = "La cotización estaba vacía y se descartó. Se reservó un número nuevo.";

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
        saleStatusFilter: document.getElementById("cq-sale-status-filter"),
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
        customerSearchBtn: document.getElementById("cq-customer-search"),
        customerNewBtn: document.getElementById("cq-customer-new"),
        customerClear: document.getElementById("cq-customer-clear"),
        custSearchModal: document.getElementById("cq-customer-search-modal"),
        custSearchNombre: document.getElementById("cq-cust-search-nombre"),
        custSearchRut: document.getElementById("cq-cust-search-rut"),
        custSearchRazon: document.getElementById("cq-cust-search-razon"),
        custSearchBtn: document.getElementById("cq-cust-search-btn"),
        custSearchHint: document.getElementById("cq-cust-search-hint"),
        custSearchResultsWrap: document.getElementById("cq-cust-search-results-wrap"),
        custSearchLocal: document.getElementById("cq-cust-search-local"),
        custSearchTbody: document.getElementById("cq-cust-search-tbody"),
        custNewModal: document.getElementById("cq-customer-new-modal"),
        custNewForm: document.getElementById("cq-cust-new-form"),
        custNewSave: document.getElementById("cq-cust-new-save"),
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
        scan: document.getElementById("cq-scan"),
        manualBtn: document.getElementById("cq-manual"),
        advanced: document.getElementById("cq-advanced"),
        sellerView: document.getElementById("cq-seller-view"),
        results: document.getElementById("cq-results"),
        modal: document.getElementById("cq-advanced-modal"),
        modalQ: document.getElementById("cq-modal-q"),
        modalSearchBtn: document.getElementById("cq-modal-search-btn"),
        modalResults: document.getElementById("cq-modal-results"),
        modalPager: document.getElementById("cq-modal-pager"),
        modalPagePrev: document.getElementById("cq-modal-page-prev"),
        modalPageNext: document.getElementById("cq-modal-page-next"),
        modalPageNumbers: document.getElementById("cq-modal-page-numbers"),
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
        transitions: document.getElementById("cq-transitions"),
        deleteBtn: document.getElementById("cq-delete"),
        invoice: document.getElementById("cq-invoice"),
        associatedCard: document.getElementById("cq-associated-card"),
        associatedList: document.getElementById("cq-associated-list"),
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
        lineModeAutoLabel: document.getElementById("cq-line-mode-auto-label"),
        lineModeStdWrap: document.getElementById("cq-line-mode-std-wrap"),
        lineModeStdLabel: document.getElementById("cq-line-mode-std-label"),
        lineRuleInfo: document.getElementById("cq-line-rule-info"),
        lineDesc: document.getElementById("cq-line-desc"),
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
        lineDiscountAmount: document.getElementById("cq-line-discount-amount"),
        lineFinalAmount: document.getElementById("cq-line-final-amount"),
        lineMarginHelp: document.getElementById("cq-line-margin-help"),
        lineMarginNa: document.getElementById("cq-line-margin-na"),
        lineByAmount: document.getElementById("cq-line-by-amount"),
        lineMonto: document.getElementById("cq-line-monto"),
        lineByAmountBtn: document.getElementById("cq-line-by-amount-btn"),
        lineByAmountResult: document.getElementById("cq-line-by-amount-result"),
        lineDiscountHint: document.getElementById("cq-line-discount-hint"),
        linePreview: document.getElementById("cq-line-preview"),
        pdf: document.getElementById("cq-pdf"),
        pdfTemplate: document.getElementById("cq-pdf-template"),
        options: document.getElementById("cq-options"),
        optionsMenu: document.getElementById("cq-options-menu"),
        optDraft: document.getElementById("cq-opt-draft"),
        optionsNote: document.getElementById("cq-options-note"),
        expiredBadge: document.getElementById("cq-expired-badge"),
        save: document.getElementById("cq-save"),
        saveStatus: document.getElementById("cq-save-status"),
        clear: document.getElementById("cq-clear")
    };

    document.getElementById("cq-new").addEventListener("click", function () {
        startNewQuote({ push: true });
    });
    document.getElementById("cq-back").addEventListener("click", function () {
        leaveCurrentEditor().then(function () {
            showList();
        });
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
    if (els.scan) {
        els.scan.addEventListener("click", scanBarcode);
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
        els.modalSearchBtn.addEventListener("click", function () { searchAdvanced(1); });
    }
    if (els.modalPagePrev) {
        els.modalPagePrev.addEventListener("click", function () {
            if ((state.modalPage || 1) > 1) {
                searchAdvanced((state.modalPage || 1) - 1);
            }
        });
    }
    if (els.modalPageNext) {
        els.modalPageNext.addEventListener("click", function () {
            if ((state.modalPage || 1) < (state.modalPages || 1)) {
                searchAdvanced((state.modalPage || 1) + 1);
            }
        });
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
            return;
        }
        if (els.optionsMenu && !els.optionsMenu.hidden) {
            closeOptionsMenu();
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
        if (els.saleStatusFilter) {
            els.saleStatusFilter.addEventListener("change", function () {
                state.listPage = 1;
                renderList();
            });
        }
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
        closeOptionsMenu();
    });
    // Interfaz vendedor: margen, utilidad, stock y costos solo a la vista del vendedor.
    // Parte siempre apagada (también si el navegador restaura el formulario).
    if (els.sellerView) {
        els.sellerView.checked = false;
        els.sellerView.addEventListener("change", function () {
            document.getElementById("riverso-cq").classList.toggle("is-seller", els.sellerView.checked);
        });
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
    els.save.addEventListener("click", function () { saveQuote(false); });
    els.clear.addEventListener("click", clearQuote);
    if (els.deleteBtn) {
        els.deleteBtn.addEventListener("click", function () {
            closeOptionsMenu();
            deleteQuote();
        });
    }
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
    if (els.lineByAmountBtn) {
        els.lineByAmountBtn.addEventListener("click", runLineByAmount);
    }
    if (els.lineMonto) {
        els.lineMonto.addEventListener("keydown", function (e) {
            if (e.key === "Enter") {
                e.preventDefault();
                runLineByAmount();
            }
        });
    }
    if (els.lineByAmountResult) {
        els.lineByAmountResult.addEventListener("click", function (e) {
            var btn = e.target.closest(".cq-qty-opt");
            if (!btn) return;
            var qty = Number(btn.getAttribute("data-qty"));
            if (!(qty > 0)) return;
            Array.prototype.forEach.call(els.lineByAmountResult.querySelectorAll(".cq-qty-opt"), function (b) {
                b.classList.toggle("is-selected", b === btn);
            });
            if (els.lineQty) {
                els.lineQty.value = formatQty(qty);
            }
            onLineModalQtyInput(true);
            scheduleLineModalPreview();
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
    if (els.lineDiscountAmount) {
        els.lineDiscountAmount.addEventListener("input", function () { onLineModalDiscountInput("discount_amount"); });
        els.lineDiscountAmount.addEventListener("blur", function () { onLineModalDiscountInput("discount_amount", true); });
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
    if (els.options && els.optionsMenu) {
        els.options.addEventListener("click", function (event) {
            event.stopPropagation();
            var open = els.optionsMenu.hidden;
            closeViewMenus();
            closeOrderMenu();
            closeOptionsMenu();
            if (open) {
                syncOptionsMenu(state.quote);
                els.optionsMenu.hidden = false;
                els.options.setAttribute("aria-expanded", "true");
            }
        });
        els.optionsMenu.addEventListener("click", function (event) {
            event.stopPropagation();
        });
    }
    if (els.optDraft) {
        els.optDraft.addEventListener("click", function () {
            closeOptionsMenu();
            returnQuoteToDraft();
        });
    }
    ["input", "change"].forEach(function (eventName) {
        els.customer.addEventListener(eventName, function () {
            syncHeader();
            updateCustomerClearLink();
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
    if (els.customerSearchBtn) {
        els.customerSearchBtn.addEventListener("click", openCustomerSearchModal);
    }
    if (els.customerNewBtn) {
        els.customerNewBtn.addEventListener("click", openCustomerNewModal);
    }
    if (els.customerClear) {
        els.customerClear.addEventListener("click", clearSelectedCustomer);
    }
    if (els.custSearchModal) {
        els.custSearchModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-cust-search-close") === "1") {
                closeCustomerSearchModal();
            }
        });
    }
    if (els.custSearchBtn) {
        els.custSearchBtn.addEventListener("click", runCustomerSearch);
    }
    ["custSearchNombre", "custSearchRut", "custSearchRazon"].forEach(function (key) {
        if (!els[key]) return;
        els[key].addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                runCustomerSearch();
            }
        });
    });
    if (els.custSearchLocal) {
        els.custSearchLocal.addEventListener("input", function () {
            renderCustomerSearchRows(state.customerSearchRows || []);
        });
    }
    if (els.custSearchTbody) {
        els.custSearchTbody.addEventListener("click", function (event) {
            var btn = event.target.closest("[data-select-customer]");
            if (!btn) return;
            var id = parseInt(btn.getAttribute("data-select-customer"), 10) || 0;
            var row = null;
            var rows = state.customerSearchRows || [];
            for (var i = 0; i < rows.length; i++) {
                if ((rows[i].id || 0) === id) {
                    row = rows[i];
                    break;
                }
            }
            if (row) {
                selectCustomer(row);
            }
        });
    }
    if (els.custNewModal) {
        els.custNewModal.addEventListener("click", function (event) {
            if (event.target && event.target.getAttribute("data-cq-cust-new-close") === "1") {
                closeCustomerNewModal();
            }
        });
    }
    if (els.custNewSave) {
        els.custNewSave.addEventListener("click", saveCustomerFromQuote);
    }
    if (els.custNewForm) {
        ["cust-has-contacto", "cust-has-facturacion", "cust-has-datos-extra"].forEach(function (id) {
            var cb = document.getElementById(id);
            if (cb) cb.addEventListener("change", syncCustomerFormSections);
        });
    }

    window.addEventListener("beforeunload", function (event) {
        if (state.autosave.saving || state.autosave.pending || (state.autosave.timer && isDirty())) {
            event.preventDefault();
            event.returnValue = "";
        }
    });

    // Cerrar pestaña, recargar o navegar fuera: un borrador vacío se descarta.
    window.addEventListener("pagehide", function () {
        if (state.view !== "editor" || !isEmptyDraft(state.quote)) {
            return;
        }
        state.discardedId = state.quote.id;
        sendDiscardBeacon(state.quote.id);
    });

    // Volver con Atrás desde caché del navegador: la cotización pudo descartarse en pagehide.
    window.addEventListener("pageshow", function (event) {
        if (!event.persisted || !state.discardedId) {
            return;
        }
        var id = state.discardedId;
        state.discardedId = null;
        if (state.view === "editor" && state.quote.id === id) {
            openQuote(id, { onMissing: function () { startNewQuote({ message: MSG_DISCARDED_NEW }); } });
        }
    });

    window.addEventListener("popstate", function () {
        var target = readLocationTarget();
        if (target.quoteId > 0 && state.view === "editor" && state.quote.id === target.quoteId) {
            return;
        }
        if (!target.quoteId && !target.isNew && state.view === "list") {
            return;
        }
        leaveCurrentEditor().then(function () {
            if (target.quoteId > 0) {
                openQuote(target.quoteId, {
                    onMissing: function () {
                        showList();
                        setListMessage("La cotización ya no existe.", true);
                    }
                });
            } else if (target.isNew) {
                startNewQuote();
            } else {
                showList();
            }
        });
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

    function readLocationTarget() {
        var params;
        try {
            params = new URLSearchParams(window.location.search || "");
        } catch (err) {
            return { quoteId: 0, isNew: false };
        }
        return {
            quoteId: parseInt(params.get("quote") || "0", 10) || 0,
            isNew: params.get("nueva") === "1"
        };
    }

    function bootFromDeepLink() {
        var target = readLocationTarget();
        if (target.quoteId > 0) {
            // 404 típico tras F5 sobre una cotización vacía (descartada en pagehide).
            openQuote(target.quoteId, { onMissing: function () { startNewQuote({ message: MSG_DISCARDED_NEW }); } });
            return true;
        }
        if (target.isNew) {
            startNewQuote();
            return true;
        }
        return false;
    }

    /**
     * Abre el editor vacío y reserva número en el servidor (borrador sin líneas).
     * Si el usuario sale sin agregar líneas, el borrador se descarta.
     */
    function startNewQuote(opts) {
        opts = opts || {};
        openEditor(emptyQuote(), { push: !!opts.push });
        if (opts.message) {
            setMessage(opts.message, false);
        }
        performSave({ silent: true, reserve: true }).catch(function () {
            // Sin reserva: el número se asigna al primer guardado con líneas (error ya informado).
        });
    }

    function isEmptyDraft(quote) {
        return !!(quote && quote.id)
            && quote.editable !== false
            && (quote.status || "draft") === "draft"
            && !(quote.lines || []).length;
    }

    /**
     * Cierra el trabajo pendiente del editor antes de salir de él:
     * espera recálculos/guardado en curso y luego descarta (si quedó vacío) o guarda.
     */
    function leaveCurrentEditor() {
        if (state.view !== "editor") {
            return Promise.resolve();
        }
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        state.autosave.pending = false;
        var quote = state.quote;
        return Promise.resolve(state.priceRecalcChain)
            .then(function () { return state.autosave.inflight; })
            .catch(function () { return null; })
            .then(function () {
                if (state.quote !== quote || quote.editable === false) {
                    return null;
                }
                if (isEmptyDraft(quote)) {
                    return post(cfg.actions.discardEmpty, { id: String(quote.id) }).catch(function () { return null; });
                }
                if (quote.id || (quote.lines || []).length) {
                    return performSave({ silent: true }).catch(function () { return null; });
                }
                return null;
            });
    }

    function sendDiscardBeacon(id) {
        if (!cfg.ajaxUrl || !cfg.actions || !cfg.actions.discardEmpty) {
            return;
        }
        var body = new FormData();
        body.append("action", cfg.actions.discardEmpty);
        if (cfg.nonce) {
            body.append("nonce", cfg.nonce);
        }
        body.append("id", String(id));
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajaxUrl, body)) {
                return;
            }
        } catch (err) { /* fallback abajo */ }
        try {
            fetch(cfg.ajaxUrl, { method: "POST", body: body, keepalive: true, credentials: "same-origin" });
        } catch (err) { /* ignore */ }
    }

    function newQuotePortalUrl() {
        var base = String(cfg.portalUrl || (window.location.origin + window.location.pathname));
        var sep = base.indexOf("?") >= 0 ? "&" : "?";
        return base + sep + "nueva=1";
    }

    function editorUrl() {
        return state.quote.id ? quotePortalUrl(state.quote.id) : newQuotePortalUrl();
    }

    function setBrowserUrl(url, push) {
        try {
            if (!url || !window.history || !window.history.replaceState) {
                return;
            }
            if (new URL(url, window.location.href).href === window.location.href) {
                return;
            }
            if (push) {
                window.history.pushState({ cq: 1 }, "", url);
            } else {
                window.history.replaceState({ cq: 1 }, "", url);
            }
        } catch (err) { /* ignore */ }
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

    /** Líneas recién leídas del servidor: detalle de entrega y total fijo de la regla. */
    function prepareLoadedLines(quote) {
        (quote.lines || []).forEach(function (line) {
            lineBreakdown(line);
            // T_final guardado de la regla: total fijo, sin rearmarlo desde el unitario.
            if (line.rule_adjusted && line.rule_total != null) {
                line._rule_adjusted = true;
                line._rule_total = Number(line.rule_total);
            }
        });
    }

    function openEditor(quote, opts) {
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
        if (opts && opts.push) {
            setBrowserUrl(editorUrl(), true);
        }
        els.listView.hidden = true;
        els.editorView.hidden = false;
        els.results.hidden = true;
        els.results.innerHTML = "";
        els.search.value = "";
        setMessage("");
        prepareLoadedLines(quote);
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
        var saleFilter = els.saleStatusFilter ? els.saleStatusFilter.value : "all";
        if (saleFilter && saleFilter !== "all") {
            rows = rows.filter(function (quote) {
                return (quote.sale && quote.sale.status ? quote.sale.status : "none") === saleFilter;
            });
        }
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
                quote.sale ? quote.sale.status_label : "",
                quote.sale ? (quote.sale.folios || []).join(" ") : "",
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
            var sale = quote.sale || { status: "none", status_label: "Sin documento", folios: [] };
            tr.appendChild(badgeCell("sale-" + sale.status, sale.status_label));
            tr.appendChild(cell((sale.folios || []).length ? sale.folios.join(", ") : "—"));
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
            openQuote(quote.id, { push: true });
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
                quote.sale ? quote.sale.status_label : "",
                quote.sale ? (quote.sale.folios || []).join(", ") : "",
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

    function openQuote(id, opts) {
        opts = opts || {};
        return post(cfg.actions.get, { id: String(id) }).then(function (data) {
            openEditor(data.quote, { push: !!opts.push });
        }).catch(function (error) {
            if (error && error.status === 404 && opts.onMissing) {
                opts.onMissing(error);
                return;
            }
            if (state.view !== "list") {
                showList();
            }
            setListMessage(error.message, true);
        });
    }

    function paintEditor() {
        var quote = state.quote;
        if (state.view === "editor") {
            setBrowserUrl(editorUrl(), false);
        }
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
        state.boundCustomerName = quote.customer_id ? (quote.customer_name || "") : "";
        els.type.value = quote.quote_type || "venta";
        setChannel(quote.channel || "local", false);
        els.validityDays.value = quote.validity_days === null || quote.validity_days === undefined ? "" : String(quote.validity_days);
        els.validityTerms.value = quote.validity_terms || "";
        [els.customer, els.type, els.validityDays, els.validityTerms, els.search].forEach(function (input) {
            input.disabled = !editable;
        });
        if (els.customerSearchBtn) els.customerSearchBtn.disabled = !editable;
        if (els.customerNewBtn) els.customerNewBtn.disabled = !editable;
        updateCustomerClearLink();
        if (els.channelLocal) els.channelLocal.disabled = !editable;
        if (els.channelOnline) els.channelOnline.disabled = !editable;
        document.getElementById("cq-search-btn").disabled = !editable;
        if (els.lupa) {
            els.lupa.disabled = !editable;
        }
        if (els.scan) {
            els.scan.disabled = !editable;
        }
        if (els.manualBtn) {
            els.manualBtn.disabled = !editable;
        }
        els.save.hidden = !editable;
        els.clear.hidden = !editable;
        renderTransitions(quote);
        if (els.invoice) {
            syncInvoiceButton(quote);
        }
        syncOptionsMenu(quote);
        renderAssociatedDocs(quote.associated_documents || [], quote.sale);
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
        var lockText = lockMessage(quote);
        if (lockText) {
            setMessage(lockText, false);
        }
    }

    /** Explica por qué la cotización no se puede editar y cómo modificarla. */
    function lockMessage(quote) {
        var hasDocs = (quote.associated_documents || []).length > 0;
        switch (quote.status) {
            case "listed":
                return hasDocs
                    ? "Cotización Aprobada con documento de venta en preparación; no se puede modificar. Para editarla, use Opciones › Devolver a Borrador."
                    : "Cotización Aprobada: bloqueada para edición y con stock reservado. Para modificarla, use Opciones › Devolver a Borrador.";
            case "invoiced":
                return quote.sale && quote.sale.comparison && quote.sale.comparison.has_changes
                    ? "Cotización Facturada con cambios respecto de lo cotizado (ver detalle en Documentos asociados); no se puede modificar."
                    : "Cotización Facturada; no se puede modificar.";
            case "rejected":
                return "Cotización Rechazada. Use «Reabrir» para volver a Borrador.";
            case "cancelled":
                return "Cotización Anulada. Use «Reabrir» para volver a Borrador.";
            default:
                return "";
        }
    }

    function renderTransitions(quote) {
        if (!els.transitions) {
            return;
        }
        els.transitions.innerHTML = "";
        if (!quote.id) {
            return;
        }
        (quote.allowed_transitions || []).forEach(function (transition) {
            // Aprobada → Borrador está en Opciones › Devolver a Borrador.
            if (quote.status === "listed" && transition.status === "draft") {
                return;
            }
            var btn = document.createElement("button");
            btn.type = "button";
            btn.className = "cq-btn" + (transition.status === "cancelled" || transition.status === "rejected" ? " cq-btn-danger" : "");
            btn.textContent = transition.label;
            btn.addEventListener("click", function () {
                transitionQuote(transition.status);
            });
            els.transitions.appendChild(btn);
        });
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
        utility.className = "cq-num cq-advanced cq-seller-only cq-line-profit";
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
            renderBreakdownChip(td, state.quote.lines[index], index, editable);
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
            renderBreakdownChip(td, line, index, editable);
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
        renderBreakdownChip(td, line, index, editable);
        return td;
    }

    /* ===== Detalle de entrega (bolsas leídas / sueltas) para la salida de stock ===== */

    /**
     * Detalle de entrega de la línea, ajustado a su cantidad. Leer el EAN13 de una bolsa suma
     * una bolsa; escribir la cantidad deja la diferencia como sueltas "supuestas" (editables).
     */
    function lineBreakdown(line) {
        var pb = line.producto_base_id != null ? Number(line.producto_base_id) : 0;
        var bd = line.stock_breakdown;
        if (!bd || typeof bd !== "object" || (bd.pb != null && Number(bd.pb) !== pb)) {
            // Sin detalle, o cambió el producto (otra presentación): todo como sueltas supuestas.
            bd = { bags: [], loose: round3(Number(line.quantity || 0)), assumed: true };
        }
        bd.bags = (Array.isArray(bd.bags) ? bd.bags : []).map(function (bag) {
            return { size: Number(bag.size) || 0, count: Math.floor(Number(bag.count) || 0), ean: bag.ean || "" };
        }).filter(function (bag) { return bag.size > 0 && bag.count > 0; });
        bd.loose = round3(Number(bd.loose || 0));
        bd.assumed = bd.assumed !== false;
        bd.pb = pb;
        line.stock_breakdown = bd;
        return reconcileBreakdown(line, bd);
    }

    function bagsUnits(bd) {
        var total = 0;
        (bd.bags || []).forEach(function (bag) { total += bag.size * bag.count; });
        return round3(total);
    }

    function reconcileBreakdown(line, bd) {
        var qty = round3(Number(line.quantity || 0));
        var total = round3(bagsUnits(bd) + bd.loose);
        if (Math.abs(total - qty) < 0.0005) {
            return bd;
        }
        if (qty > total) {
            bd.loose = round3(bd.loose + (qty - total));
            bd.assumed = true;
            return bd;
        }
        // Bajó la cantidad: primero sueltas, luego bolsas (la última leída).
        var excess = round3(total - qty);
        var fromLoose = Math.min(bd.loose, excess);
        bd.loose = round3(bd.loose - fromLoose);
        excess = round3(excess - fromLoose);
        while (excess > 0.0005 && bd.bags.length) {
            var last = bd.bags[bd.bags.length - 1];
            last.count -= 1;
            excess = round3(excess - last.size);
            if (last.count <= 0) {
                bd.bags.pop();
            }
        }
        if (excess < -0.0005) {
            bd.loose = round3(bd.loose - excess);
            bd.assumed = true;
        }
        if (bd.loose <= 0.0005) {
            bd.loose = 0;
            bd.assumed = false;
        }
        return bd;
    }

    /** Suma una lectura al detalle: EAN13 interno de bolsa = 1 bolsa; otro código = sueltas. */
    function addScanToBreakdown(bd, product, qty) {
        var bagSize = Number(product.scan_quantity || 0);
        if (bagSize > 0 && product.barcode) {
            var found = null;
            bd.bags.forEach(function (bag) {
                if (!found && Math.abs(bag.size - bagSize) < 0.0005) {
                    found = bag;
                }
            });
            if (found) {
                found.count += 1;
            } else {
                bd.bags.push({ size: bagSize, count: 1, ean: String(product.barcode) });
            }
            return bd;
        }
        bd.loose = round3(bd.loose + qty);
        return bd;
    }

    function breakdownPayload(line) {
        var bd = lineBreakdown(line);
        return {
            bags: bd.bags.map(function (bag) { return { size: bag.size, count: bag.count, ean: bag.ean || "" }; }),
            loose: bd.loose,
            assumed: !!bd.assumed
        };
    }

    /** Solo importa donde hay ambigüedad: unitario de familia (¿suelto o bolsa?) o bolsas leídas. */
    function lineNeedsBreakdown(line) {
        var bd = line.stock_breakdown;
        var hasBags = !!(bd && Array.isArray(bd.bags) && bd.bags.length);
        return hasBags || (!!lineGrupoId(line) && lineIsUnitario(line));
    }

    function breakdownText(bd) {
        var parts = bd.bags.map(function (bag) {
            return formatPlain(bag.count) + (bag.count === 1 ? " bolsa ×" : " bolsas ×") + formatPlain(bag.size);
        });
        if (bd.loose > 0) {
            parts.push(formatPlain(bd.loose) + (Math.abs(bd.loose - 1) < 0.0005 ? " suelta" : " sueltas"));
        }
        var text = parts.join(" + ") || "—";
        return bd.assumed && bd.loose > 0 ? "≈ " + text + " (supuesto)" : text;
    }

    function renderBreakdownChip(td, line, index, editable) {
        var old = td.querySelector(".cq-breakdown-chip");
        if (old) {
            old.parentNode.removeChild(old);
        }
        if (!line || !(Number(line.quantity) > 0) || !lineNeedsBreakdown(line)) {
            return;
        }
        var bd = lineBreakdown(line);
        var chip = document.createElement(editable ? "button" : "span");
        if (editable) {
            chip.type = "button";
            chip.addEventListener("click", function () { openBreakdownModal(index); });
        }
        chip.className = "cq-breakdown-chip" + (bd.assumed && bd.loose > 0 ? " is-assumed" : "");
        chip.textContent = breakdownText(bd);
        chip.title = editable
            ? "Cómo se entrega (para descontar stock al facturar). Clic para corregir."
            : "Cómo se entrega (para descontar stock al facturar).";
        td.appendChild(chip);
    }

    /** Reparto de la salida según la cascada de ubicaciones (vista previa, Modo avanzado). */
    function cascadePlan(cascada, qty) {
        var remaining = round3(qty);
        var plan = [];
        (cascada || []).forEach(function (row) {
            if (remaining <= 0.0005) {
                return;
            }
            var take = round3(Math.min(Number(row.cantidad) || 0, remaining));
            if (take > 0) {
                plan.push({ nombre: row.nombre || row.codigo || "?", cantidad: take, estimado: !!row.estimado });
                remaining = round3(remaining - take);
            }
        });
        if (remaining > 0.0005) {
            plan.push({ nombre: "Sin ubicar", cantidad: remaining, estimado: false });
        }
        return plan;
    }

    var breakdownModal = null;

    function ensureBreakdownModal() {
        if (breakdownModal) {
            return breakdownModal;
        }
        var root = document.createElement("div");
        root.className = "cq-modal";
        root.hidden = true;
        root.setAttribute("aria-hidden", "true");
        root.innerHTML = '<div class="cq-modal-backdrop" data-cq-bd-close="1"></div>'
            + '<div class="cq-modal-dialog cq-breakdown-dialog" role="dialog" aria-modal="true" aria-labelledby="cq-bd-title">'
            + '<header class="cq-modal-head"><h3 id="cq-bd-title">Detalle de entrega</h3>'
            + '<button type="button" class="cq-modal-close" aria-label="Cerrar" data-cq-bd-close="1">×</button></header>'
            + '<div class="cq-modal-body">'
            + '<p class="cq-modal-hint" data-role="line"></p>'
            + '<div class="cq-bd-rows" data-role="rows"></div>'
            + '<div class="cq-bd-add"><input type="text" inputmode="decimal" data-role="new-size" placeholder="Unidades por bolsa" aria-label="Otro tamaño de bolsa">'
            + '<button type="button" class="cq-btn" data-role="add-size">Agregar tamaño</button></div>'
            + '<p class="cq-bd-loose" data-role="loose"></p>'
            + '<div class="cq-bd-suggest" data-role="suggest"></div>'
            + '<p class="cq-modal-hint" data-role="info">Las bolsas registradas se marcan vendidas (sus unidades ya salieron al embolsar). '
            + 'Las no registradas y las sueltas descuentan unidades.</p>'
            + '</div>'
            + '<footer class="cq-modal-foot"><button type="button" class="cq-btn" data-cq-bd-close="1">Cancelar</button>'
            + '<button type="button" class="cq-btn cq-btn-primary" data-role="save">Guardar detalle</button></footer>'
            + '</div>';
        var host = document.getElementById("riverso-cq") || document.body;
        host.appendChild(root);
        breakdownModal = { root: root, index: -1, draft: null, sizes: [] };
        root.addEventListener("click", function (event) {
            var target = event.target;
            if (target && target.getAttribute && target.getAttribute("data-cq-bd-close")) {
                closeBreakdownModal();
            }
        });
        root.addEventListener("keydown", function (event) {
            if (event.key === "Escape") {
                closeBreakdownModal();
            }
        });
        root.querySelector('[data-role="add-size"]').addEventListener("click", function () {
            var input = root.querySelector('[data-role="new-size"]');
            var size = round3(parseClNumber(input.value));
            if (size > 0) {
                addBreakdownSize(size, 0);
                input.value = "";
                renderBreakdownModal();
            }
        });
        root.querySelector('[data-role="save"]').addEventListener("click", saveBreakdownModal);
        return breakdownModal;
    }

    function addBreakdownSize(size, disponibles) {
        var modal = breakdownModal;
        var found = null;
        modal.sizes.forEach(function (item) {
            if (!found && Math.abs(item.size - size) < 0.0005) {
                found = item;
            }
        });
        if (found) {
            if (disponibles != null) {
                found.disponibles = disponibles;
            }
            return found;
        }
        var item = { size: size, disponibles: disponibles == null ? null : disponibles };
        modal.sizes.push(item);
        modal.sizes.sort(function (a, b) { return a.size - b.size; });
        return item;
    }

    function breakdownDraftCount(size) {
        var count = 0;
        breakdownModal.draft.bags.forEach(function (bag) {
            if (Math.abs(bag.size - size) < 0.0005) {
                count = bag.count;
            }
        });
        return count;
    }

    function setBreakdownDraftCount(size, count, ean) {
        var bags = breakdownModal.draft.bags.filter(function (bag) { return Math.abs(bag.size - size) >= 0.0005; });
        if (count > 0) {
            bags.push({ size: size, count: count, ean: ean || "" });
        }
        bags.sort(function (a, b) { return a.size - b.size; });
        breakdownModal.draft.bags = bags;
    }

    function openBreakdownModal(index) {
        var line = state.quote.lines[index];
        if (!line || state.quote.editable === false) {
            return;
        }
        var modal = ensureBreakdownModal();
        var bd = lineBreakdown(line);
        modal.index = index;
        modal.draft = { bags: bd.bags.map(function (bag) { return { size: bag.size, count: bag.count, ean: bag.ean }; }) };
        modal.sizes = [];
        bd.bags.forEach(function (bag) { addBreakdownSize(bag.size, null); });
        modal.root.querySelector('[data-role="line"]').textContent = (line.sku ? line.sku + " · " : "")
            + (line.description || "") + " — cantidad " + formatPlain(line.quantity);
        modal.root.hidden = false;
        modal.root.setAttribute("aria-hidden", "false");
        renderBreakdownModal();
        if (cfg.actions && cfg.actions.bagSizes && line.producto_base_id) {
            post(cfg.actions.bagSizes, { producto_base_id: String(line.producto_base_id) }).then(function (data) {
                if (modal.index !== index) {
                    return;
                }
                (data.sizes || []).forEach(function (item) { addBreakdownSize(Number(item.size), Number(item.disponibles) || 0); });
                renderBreakdownModal();
            }).catch(function () { /* sin tamaños registrados: se puede escribir uno */ });
        }
    }

    function closeBreakdownModal() {
        if (!breakdownModal) {
            return;
        }
        breakdownModal.root.hidden = true;
        breakdownModal.root.setAttribute("aria-hidden", "true");
        breakdownModal.index = -1;
    }

    function breakdownModalLoose() {
        var line = state.quote.lines[breakdownModal.index];
        var qty = round3(Number(line ? line.quantity : 0));
        return round3(qty - bagsUnits(breakdownModal.draft));
    }

    function renderBreakdownModal() {
        var modal = breakdownModal;
        var rows = modal.root.querySelector('[data-role="rows"]');
        rows.innerHTML = "";
        if (!modal.sizes.length) {
            var empty = document.createElement("p");
            empty.className = "cq-modal-hint";
            empty.textContent = "Sin bolsas registradas para este producto. Si se entregó embolsado, agregue el tamaño.";
            rows.appendChild(empty);
        }
        modal.sizes.forEach(function (item) {
            var row = document.createElement("label");
            row.className = "cq-bd-row";
            var name = document.createElement("span");
            name.textContent = "Bolsas de " + formatPlain(item.size);
            var input = document.createElement("input");
            input.type = "number";
            input.min = "0";
            input.step = "1";
            input.value = String(breakdownDraftCount(item.size));
            input.addEventListener("input", function () {
                setBreakdownDraftCount(item.size, Math.max(0, Math.floor(Number(input.value) || 0)));
                renderBreakdownSummary();
            });
            var hint = document.createElement("em");
            hint.className = "cq-float-hint";
            hint.textContent = item.disponibles == null
                ? ""
                : (item.disponibles > 0 ? item.disponibles + " registradas" : "sin registrar (se descuentan como sueltas)");
            row.appendChild(name);
            row.appendChild(input);
            row.appendChild(hint);
            rows.appendChild(row);
        });
        renderBreakdownSummary();
    }

    function renderBreakdownSummary() {
        var modal = breakdownModal;
        var loose = breakdownModalLoose();
        var looseEl = modal.root.querySelector('[data-role="loose"]');
        var save = modal.root.querySelector('[data-role="save"]');
        if (loose < -0.0005) {
            looseEl.textContent = "Las bolsas superan la cantidad de la línea por " + formatPlain(-loose) + ".";
            looseEl.classList.add("is-error");
            save.disabled = true;
        } else {
            looseEl.textContent = "Sueltas: " + formatPlain(Math.max(0, loose));
            looseEl.classList.remove("is-error");
            save.disabled = false;
        }
        // Sugerencia: si las sueltas alcanzan para bolsas registradas, ofrecer usarlas.
        var suggest = modal.root.querySelector('[data-role="suggest"]');
        suggest.innerHTML = "";
        modal.sizes.forEach(function (item) {
            var free = (item.disponibles || 0) - breakdownDraftCount(item.size);
            var n = Math.min(Math.floor(Math.max(0, loose) / item.size), free);
            if (n > 0) {
                var btn = document.createElement("button");
                btn.type = "button";
                btn.className = "cq-btn cq-btn-small";
                btn.textContent = "Usar " + n + (n === 1 ? " bolsa" : " bolsas") + " de " + formatPlain(item.size);
                btn.addEventListener("click", function () {
                    setBreakdownDraftCount(item.size, breakdownDraftCount(item.size) + n);
                    renderBreakdownModal();
                });
                suggest.appendChild(btn);
            }
        });
    }

    function saveBreakdownModal() {
        var modal = breakdownModal;
        var line = state.quote.lines[modal.index];
        var loose = breakdownModalLoose();
        if (!line || loose < -0.0005) {
            return;
        }
        line.stock_breakdown = {
            bags: modal.draft.bags.filter(function (bag) { return bag.count > 0; }),
            loose: Math.max(0, loose),
            assumed: false,
            pb: line.producto_base_id != null ? Number(line.producto_base_id) : 0
        };
        closeBreakdownModal();
        renderLines();
        scheduleAutosave(0);
        setMessage("Detalle de entrega actualizado.", false);
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

    function syncHeader() {
        var name = els.customer.value.trim();
        state.quote.customer_name = name;
        if (state.quote.customer_id && state.boundCustomerName
            && name !== state.boundCustomerName) {
            state.quote.customer_id = null;
            state.boundCustomerName = "";
        }
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
            var amount = round2(Number(line.discount_amount) || 0);
            var p = clampRate(line.price_discount);
            var m = clampRate(line.margin_discount);
            // Preferir monto en pesos; no rearmar desde el % de 2 decimales.
            if (amount > 0) {
                syncEquivalentDiscounts(line, "discount_amount");
            } else if (p > 0) {
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
        state.modalPage = 1;
        state.modalPages = 1;
        state.modalTotal = 0;
        renderModalResults();
        renderModalPager();
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
            state.modalPage = 1;
            state.modalPages = 1;
            state.modalTotal = 0;
            renderModalResults();
            renderModalPager();
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

    function searchAdvanced(page) {
        if (!els.modalQ) return;
        var requested = (typeof page === "number" && isFinite(page) && page > 0) ? Math.floor(page) : 1;
        var query = els.modalQ.value.trim();
        var scope = state.modalScope || "todo";
        var contains = (state.modalContains || []).slice();
        if (!query && !contains.length) {
            setModalHint("Ingresa un término de búsqueda o agrega una palabra.", true);
            state.modalResults = [];
            state.modalPage = 1;
            state.modalPages = 1;
            state.modalTotal = 0;
            renderModalResults();
            renderModalPager();
            return;
        }
        if (!query && contains.length) {
            // Sin caja principal: la primera palabra alimenta la búsqueda; el resto filtra.
            query = contains[0];
        }
        if ((scope === "descripcion") && query.length < 2 && !contains.length) {
            setModalHint("Escribe al menos 2 caracteres para buscar por descripción.", true);
            state.modalResults = [];
            state.modalPage = 1;
            state.modalPages = 1;
            state.modalTotal = 0;
            renderModalResults();
            renderModalPager();
            return;
        }
        setModalHint("Buscando…");
        post(cfg.actions.search, {
            q: query,
            mode: "advanced",
            scope: scope,
            channel: currentChannel(),
            contains: JSON.stringify(contains),
            page: requested
        }).then(function (data) {
            state.modalResults = data.products || [];
            state.modalPage = data.page || requested;
            state.modalPages = data.pages || 1;
            state.modalTotal = data.total != null ? Number(data.total) : state.modalResults.length;
            state.modalPerPage = data.per_page || 30;
            renderModalResults();
            renderModalPager();
            if (state.modalResults.length === 0) {
                var label = contains.length
                    ? ("«" + query + "» + contiene: " + contains.join(", "))
                    : ("«" + query + "»");
                setModalHint(data.hint || ("Sin resultados para " + label + "."), true);
            } else {
                var from = ((state.modalPage - 1) * state.modalPerPage) + 1;
                var to = from + state.modalResults.length - 1;
                var hint = state.modalTotal + " resultado(s).";
                if (state.modalPages > 1) {
                    hint += " Página " + state.modalPage + " de " + state.modalPages + " (" + from + "–" + to + ").";
                }
                if (data.capped) {
                    hint += " Hay más coincidencias; afina la búsqueda.";
                }
                hint += " Elige Agregar — el modal no agrega solo.";
                setModalHint(hint, false);
            }
        }).catch(function (error) {
            setModalHint(error.message, true);
        });
    }

    function renderModalPager() {
        if (!els.modalPager) return;
        var pages = state.modalPages || 1;
        var current = state.modalPage || 1;
        if (pages <= 1) {
            els.modalPager.hidden = true;
            if (els.modalPageNumbers) els.modalPageNumbers.innerHTML = "";
            return;
        }
        els.modalPager.hidden = false;
        if (els.modalPagePrev) els.modalPagePrev.disabled = current <= 1;
        if (els.modalPageNext) els.modalPageNext.disabled = current >= pages;
        if (!els.modalPageNumbers) return;
        els.modalPageNumbers.innerHTML = "";
        var windowSize = 5;
        var start = Math.max(1, current - Math.floor(windowSize / 2));
        var end = Math.min(pages, start + windowSize - 1);
        start = Math.max(1, end - windowSize + 1);
        for (var i = start; i <= end; i += 1) {
            (function (page) {
                var btn = document.createElement("button");
                btn.type = "button";
                btn.textContent = String(page);
                btn.setAttribute("aria-label", "Página " + page);
                if (page === current) {
                    btn.className = "is-active";
                    btn.setAttribute("aria-current", "page");
                }
                btn.addEventListener("click", function () {
                    searchAdvanced(page);
                });
                els.modalPageNumbers.appendChild(btn);
            })(i);
        }
    }

    function renderModalResults() {
        if (!els.modalResults) return;
        els.modalResults.innerHTML = "";
        els.modalResults.scrollTop = 0;
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

    // Cámara: el código leído entra al buscador igual que con la pistola (código + Enter).
    function scanBarcode() {
        if (!window.RiversoBarcodeScanner) {
            return;
        }
        window.RiversoBarcodeScanner.open({
            onCode: function (code) {
                els.search.value = code;
                searchProducts();
            }
        });
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
            addScanToBreakdown(lineBreakdown(existing), product, addQty);
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
                price_total: product.price_total != null ? Number(product.price_total) : null,
                stock_breakdown: addScanToBreakdown({
                    bags: [],
                    loose: 0,
                    assumed: false,
                    pb: product.producto_base_id != null ? Number(product.producto_base_id) : 0
                }, product, round3(addQty))
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
                        : null,
                    stock_breakdown: breakdownPayload(line)
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
        if (state.view === "editor") {
            setBrowserUrl(editorUrl(), false);
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
        renderTransitions(state.quote);
        if (els.invoice) {
            syncInvoiceButton(state.quote);
        }
        if (quote.associated_documents) {
            state.quote.associated_documents = quote.associated_documents;
            renderAssociatedDocs(quote.associated_documents);
        }
        if (quote.options) {
            state.quote.options = quote.options;
        }
        syncOptionsMenu(state.quote);
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
        var reserve = !!opts.reserve;
        syncHeader();
        var hasId = !!state.quote.id;
        var lineCount = (state.quote.lines || []).length;
        if (reserve && hasId) {
            return Promise.resolve(null);
        }
        if (!reserve && !hasId && lineCount === 0) {
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
        setSaveStatus(reserve ? "Reservando número…" : "Guardando…", "saving");
        if (!silent) {
            els.save.disabled = true;
        }
        var target = state.quote;
        var request = post(cfg.actions.save, { payload: JSON.stringify(buildSavePayload()) }).then(function (data) {
            if (state.quote !== target) {
                // El editor cambió de cotización mientras se guardaba.
                return data.quote;
            }
            if (silent) {
                applySavedMeta(data.quote);
            } else {
                state.quote = data.quote;
                paintEditor();
                state.snapshot = serialize(state.quote);
                setMessage(data.message || "Cotización guardada.", false);
            }
            setSaveStatus(reserve ? "" : "Guardado", reserve ? "" : "saved");
            return data.quote;
        }).catch(function (error) {
            setSaveStatus(reserve ? "No se pudo reservar número" : "Error al guardar", "error");
            setMessage(error.message, true);
            throw error;
        }).finally(function () {
            state.autosave.saving = false;
            state.autosave.inflight = null;
            if (!silent) {
                els.save.disabled = false;
            }
            if (state.autosave.pending) {
                state.autosave.pending = false;
                scheduleAutosave(150);
            }
        });
        state.autosave.inflight = request.catch(function () { return null; });
        return request;
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

    /** Quita todas las líneas manteniendo la cotización (id y número). */
    function clearQuote() {
        if (state.quote.editable === false) {
            return;
        }
        var lines = state.quote.lines || [];
        if (!lines.length) {
            setMessage("La cotización no tiene líneas.", false);
            return;
        }
        var label = state.quote.quote_number ? " " + state.quote.quote_number : "";
        var warning = "¿Está seguro de limpiar la cotización" + label + "? Se quitarán todas las líneas (" + lines.length + ").";
        if ((state.quote.status || "draft") === "draft") {
            warning += "\n\nSi sale de la cotización sin agregar productos, se borrará automáticamente.";
        }
        if (!window.confirm(warning)) {
            return;
        }
        state.quote.lines = [];
        renderLines();
        renderTotals();
        scheduleAutosave(0);
        setMessage("Líneas eliminadas.", false);
    }

    function closeOptionsMenu() {
        if (els.optionsMenu) els.optionsMenu.hidden = true;
        if (els.options) els.options.setAttribute("aria-expanded", "false");
    }

    /** Opciones del servidor (present_quote_with_docs): volver a Borrador y borrar. */
    function quoteOptions(quote) {
        var opts = quote && quote.options ? quote.options : {};
        return {
            canReturnDraft: !!opts.can_return_draft,
            canDelete: !!opts.can_delete && cfg.canDelete !== false,
            blockedReason: opts.blocked_reason || "",
            discardsDocuments: Number(opts.discards_documents) || 0
        };
    }

    function syncOptionsMenu(quote) {
        var hasId = !!(quote && quote.id);
        var opts = quoteOptions(quote);
        var status = quote && quote.status ? quote.status : "draft";
        if (els.optDraft) {
            els.optDraft.disabled = !hasId || !opts.canReturnDraft;
            els.optDraft.title = status === "listed" ? "" : "Solo para cotizaciones Aprobadas.";
        }
        if (els.deleteBtn) {
            els.deleteBtn.disabled = !hasId || !opts.canDelete;
            els.deleteBtn.title = cfg.canDelete === false ? "No tienes permiso para borrar cotizaciones." : "";
        }
        if (els.optionsNote) {
            var note = "";
            if (!hasId) {
                note = "Guarda la cotización para ver estas opciones.";
            } else if (opts.blockedReason) {
                note = "No disponible: " + opts.blockedReason;
            } else if (opts.discardsDocuments > 0) {
                note = "Se descartará el documento en preparación en Facturación (sin emitir).";
            }
            els.optionsNote.textContent = note;
            els.optionsNote.hidden = note === "";
        }
    }

    function discardDocumentsText() {
        return quoteOptions(state.quote).discardsDocuments > 0
            ? "\n\nTambién se descartará el documento en preparación en Facturación (no emitido)."
            : "";
    }

    function returnQuoteToDraft() {
        var opts = quoteOptions(state.quote);
        if (!state.quote.id || !opts.canReturnDraft) {
            setMessage(opts.blockedReason
                ? "No se puede volver a Borrador: " + opts.blockedReason
                : "Solo una cotización Aprobada puede volver a Borrador.", true);
            return;
        }
        transitionQuote("draft");
    }

    function deleteQuote() {
        if (!state.quote.id) {
            setMessage("Guarda la cotización antes de borrarla.", true);
            return;
        }
        var opts = quoteOptions(state.quote);
        if (!opts.canDelete) {
            setMessage(opts.blockedReason
                ? "No se puede borrar: " + opts.blockedReason
                : "No tienes permiso para borrar cotizaciones.", true);
            syncOptionsMenu(state.quote);
            return;
        }
        var label = state.quote.quote_number || ("#" + state.quote.id);
        if (!window.confirm("¿Está seguro de borrar la cotización " + label + "? Esta acción no se puede deshacer."
            + discardDocumentsText())) {
            return;
        }
        if (els.deleteBtn) {
            els.deleteBtn.disabled = true;
        }
        post(cfg.actions.delete, { id: String(state.quote.id) }).then(function (data) {
            setMessage("");
            showList();
            setListMessage(data.message || "Cotización borrada.", false);
        }).catch(function (error) {
            setMessage(error.message, true);
        }).finally(function () {
            syncOptionsMenu(state.quote);
        });
    }

    function transitionConfirmText(target) {
        var label = state.quote.quote_number || "esta cotización";
        if (target === "listed") {
            return "¿Aprobar " + label + "?\n\nQuedará bloqueada para edición y se reservará el stock de sus productos.";
        }
        if (target === "rejected") {
            return "¿Marcar " + label + " como Rechazada?\n\nSe liberará el stock reservado, si lo hay.";
        }
        if (target === "cancelled") {
            return "¿Anular " + label + "?\n\nSe liberará el stock reservado, si lo hay.";
        }
        if (target === "draft" && state.quote.status === "listed") {
            return "¿Volver " + label + " a Borrador?\n\nSe liberará el stock reservado y podrá editarla de nuevo."
                + discardDocumentsText();
        }
        return "";
    }

    /** Espera recálculos y guardados en curso y guarda cambios pendientes. */
    function flushPendingSave() {
        if (state.autosave.timer) {
            clearTimeout(state.autosave.timer);
            state.autosave.timer = null;
        }
        state.autosave.pending = false;
        return Promise.resolve(state.priceRecalcChain)
            .then(function () { return state.autosave.inflight; })
            .catch(function () { return null; })
            .then(function () {
                if (state.quote.editable !== false && state.quote.id && isDirty()) {
                    return performSave({ silent: true });
                }
                return null;
            });
    }

    function transitionQuote(target) {
        if (!state.quote.id || !target) {
            setMessage("Guarda la cotización antes de cambiar el estado.", true);
            return;
        }
        var question = transitionConfirmText(target);
        if (question && !window.confirm(question)) {
            return;
        }
        flushPendingSave().then(function () {
            return post(cfg.actions.transition, { id: String(state.quote.id), status: target });
        }).then(function (data) {
            state.quote = data.quote;
            if (!state.quote.lines) {
                state.quote.lines = [];
            }
            prepareLoadedLines(state.quote);
            paintEditor();
            state.snapshot = serialize(state.quote);
            setMessage(data.message || "Estado actualizado.", false);
            if (state.advanced) {
                refreshLineStock();
            }
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
            customer_id: quote.customer_id || null,
            customer_name: quote.customer_name || "",
            quote_type: quote.quote_type,
            channel: quote.channel || "local",
            issue_date: toDateInputValue(quote.issue_date) || "",
            validity_days: quote.validity_days,
            validity_terms: quote.validity_terms || "",
            lines: quote.lines || []
        });
    }


    function billingUrlForQuote(id) {
        var base = cfg.billingEmitUrl || "/interno/facturacion/";
        var sep = base.indexOf("?") >= 0 ? "&" : "?";
        return base + sep + "quote_id=" + encodeURIComponent(String(id));
    }

    function canShowInvoice(quote) {
        var status = quote && quote.status ? quote.status : "draft";
        return !!(cfg.canEmitDte) && !!(quote && quote.id) && (quote.quote_type || "venta") === "venta"
            && (status === "draft" || status === "listed");
    }

    function syncInvoiceButton(quote) {
        if (!els.invoice) {
            return;
        }
        els.invoice.hidden = !canShowInvoice(quote);
        els.invoice.disabled = false;
    }

    function associatedDocUrl(doc) {
        var base = (cfg.billingEmitUrl || "/interno/facturacion/").replace(/\?.*$/, "");
        if (doc && doc.dte_id) {
            return base + "?vista=documento&dte_id=" + encodeURIComponent(String(doc.dte_id));
        }
        if (doc && doc.draft_id) {
            return base + "?draft_id=" + encodeURIComponent(String(doc.draft_id));
        }
        return "";
    }

    function renderAssociatedDocs(list, sale) {
        if (!els.associatedCard || !els.associatedList) {
            return;
        }
        var docs = Array.isArray(list) ? list : [];
        els.associatedList.innerHTML = "";
        Array.prototype.forEach.call(
            els.associatedCard.querySelectorAll(".cq-sale-summary, .cq-sale-compare"),
            function (node) { node.parentNode.removeChild(node); }
        );
        var summary = null;
        if (!docs.length) {
            els.associatedCard.hidden = true;
            return;
        }
        els.associatedCard.hidden = false;
        if (sale && sale.status && sale.status !== "none") {
            summary = document.createElement("p");
            summary.className = "cq-sale-summary";
            var text = "Estado de venta: " + (sale.status_label || "");
            if (sale.sold) {
                text += " · Pagado " + formatMoney(sale.paid || 0) + " de " + formatMoney(sale.total || 0);
            }
            summary.textContent = text;
            els.associatedCard.appendChild(summary);
            var compare = renderSaleComparison(sale.comparison);
            if (compare) {
                els.associatedCard.appendChild(compare);
            }
        }
        docs.forEach(function (doc) {
            var url = associatedDocUrl(doc);
            var li = document.createElement("li");
            li.className = "cq-associated-item";
            var btn = document.createElement(url ? "a" : "span");
            btn.className = "cq-btn cq-btn-associated";
            btn.textContent = "Ver documento";
            if (url) {
                btn.href = url;
            }
            var label = document.createElement("span");
            label.className = "cq-associated-label";
            label.textContent = doc.label || "Documento";
            li.appendChild(btn);
            li.appendChild(label);
            els.associatedList.appendChild(li);
        });
    }

    /** Cambios entre lo cotizado y lo facturado (la cotización no se modifica). */
    function renderSaleComparison(cmp) {
        if (!cmp) {
            return null;
        }
        var wrap = document.createElement("div");
        wrap.className = "cq-sale-compare";
        if (!cmp.available) {
            wrap.textContent = "No hay detalle de líneas del documento para compararlo con la cotización.";
            return wrap;
        }
        if (!cmp.has_changes) {
            wrap.textContent = "Facturada sin cambios respecto de lo cotizado.";
            return wrap;
        }
        var title = document.createElement("p");
        title.className = "cq-sale-compare-title";
        var diff = Number(cmp.document_total || 0) - Number(cmp.quote_total || 0);
        title.textContent = "Cambios respecto de lo cotizado · Total cotizado " + formatMoney(cmp.quote_total)
            + " · Facturado " + formatMoney(cmp.document_total)
            + " (" + (diff >= 0 ? "+" : "−") + formatMoney(Math.abs(diff)) + ")";
        wrap.appendChild(title);
        var lines = cmp.lines || [];
        if (!lines.length) {
            return wrap;
        }
        var table = document.createElement("table");
        table.className = "cq-table cq-sale-compare-table";
        var head = document.createElement("tr");
        ["Cambio", "Producto", "Cotizado", "Facturado"].forEach(function (text) {
            var th = document.createElement("th");
            th.scope = "col";
            th.textContent = text;
            head.appendChild(th);
        });
        var thead = document.createElement("thead");
        thead.appendChild(head);
        table.appendChild(thead);
        var body = document.createElement("tbody");
        var typeLabels = { added: "Agregado", removed: "Quitado", changed: "Modificado" };
        lines.forEach(function (line) {
            var tr = document.createElement("tr");
            tr.className = "cq-compare-" + line.type;
            tr.appendChild(cell(typeLabels[line.type] || line.type));
            tr.appendChild(cell((line.sku ? line.sku + " · " : "") + (line.description || "")));
            tr.appendChild(cell(line.quote_quantity == null ? "—"
                : formatPlain(line.quote_quantity) + " · " + formatMoney(line.quote_total)));
            tr.appendChild(cell(line.document_quantity == null ? "—"
                : formatPlain(line.document_quantity) + " · " + formatMoney(line.document_total)));
            body.appendChild(tr);
        });
        table.appendChild(body);
        wrap.appendChild(table);
        return wrap;
    }

    function invoiceQuote() {
        if ((state.quote.quote_type || "venta") !== "venta") {
            setMessage("Solo se pueden facturar cotizaciones de tipo Venta.", true);
            return;
        }
        if (!cfg.canEmitDte) {
            setMessage("No tienes permiso para facturar.", true);
            return;
        }
        if ((state.quote.status || "draft") === "draft" && !window.confirm(
            "Al facturar, la cotización pasa a Aprobada: queda bloqueada para edición y reserva el stock.\n\n¿Continuar a Facturación?"
        )) {
            return;
        }
        function go(id) {
            if (!id) {
                setMessage("Guarda la cotización antes de facturar.", true);
                if (els.invoice) {
                    els.invoice.disabled = false;
                }
                return;
            }
            window.location.href = billingUrlForQuote(id);
        }
        if (isDirty() || !state.quote.id) {
            if (els.invoice) {
                els.invoice.disabled = true;
            }
            setMessage("Guardando cotización…", false);
            saveQuote(false).then(function (quote) {
                go(quote && quote.id ? quote.id : state.quote.id);
            }).catch(function () {
                if (els.invoice) {
                    els.invoice.disabled = false;
                }
            });
            return;
        }
        go(state.quote.id);
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
            c.className = "cq-num cq-seller-only";
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
                    var error = new Error(message);
                    error.status = response.status;
                    throw error;
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
        return Number.isFinite(number) ? number : 0;
    }


    function stockCell(line) {
        var td = document.createElement("td");
        td.className = "cq-num cq-advanced cq-seller-only cq-stock-cell";
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
        var notes = [];
        if (info.critico) {
            notes.push("Stock crítico");
        } else if (info.alerta) {
            notes.push("Stock en alerta");
        }
        var reserved = Number(info.reservado || 0);
        if (reserved > 0) {
            var res = document.createElement("span");
            res.className = "cq-stock-reserved";
            res.textContent = "Res. " + formatPlain(reserved) + " · Disp. " + formatPlain(info.disponible);
            td.appendChild(res);
            notes.push("Reservado por cotizaciones aprobadas: " + formatPlain(reserved)
                + ". Disponible: " + formatPlain(info.disponible));
        }
        var looseQty = line && Number(line.quantity) > 0 ? lineBreakdown(line).loose : 0;
        if (Array.isArray(info.cascada) && looseQty > 0) {
            var plan = cascadePlan(info.cascada, looseQty);
            var preview = document.createElement("span");
            preview.className = "cq-stock-cascade";
            preview.textContent = "Sale de: " + plan.map(function (p) {
                return p.nombre + " " + (p.estimado ? "≈" : "") + formatPlain(p.cantidad);
            }).join(" · ");
            td.appendChild(preview);
            notes.push(preview.textContent);
        }
        if (Number(info.sin_ubicar) < -0.0005) {
            notes.push("Sin ubicar: " + formatPlain(info.sin_ubicar) + " (vendido sin lugar identificado; se cuadra al contar)");
        }
        if (notes.length) {
            td.title = notes.join(". ");
        }
        return td;
    }

    function confianzaCell(line) {
        var td = document.createElement("td");
        td.className = "cq-advanced cq-seller-only cq-conf-cell";
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
        td.className = "cq-advanced cq-seller-only cq-inv-cell";
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
                ? "Local: solo productos con SKU local"
                : "Online: SKU / barcode / proveedor (Woo)";
        }
        if (interactive) {
            scheduleAutosave(0);
        }
    }

    function linePriceMode(line) {
        var mode = line && line.price_mode ? String(line.price_mode).toLowerCase() : "auto";
        if (mode === "manual" || mode === "ref" || mode === "std") return mode;
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
                // auto | std
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
        if (mode !== "manual" && mode !== "ref" && mode !== "std") mode = "auto";
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
        updateLineModalFinalAmount();
        lm.syncing = false;
    }

    function updateLineModalFinalAmount() {
        if (!els.lineFinalAmount) return;
        var draft = lineModalDraftForDiscount();
        if (!draft) {
            els.lineFinalAmount.value = formatMoney(0);
            return;
        }
        els.lineFinalAmount.value = formatMoney(lineFigures(draft).lineNet);
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
        if (els.lineDiscountAmount) {
            draft.discount_amount = round2(parseClNumber(els.lineDiscountAmount.value));
        } else {
            draft.discount_amount = round2(Number(line.discount_amount) || 0);
        }
        return draft;
    }

    function syncLineModalDiscountFields(source, format) {
        var draft = lineModalDraftForDiscount();
        if (!draft) return;
        if (source === "discount_amount") {
            draft.discount_amount = round2(parseClNumber(els.lineDiscountAmount ? els.lineDiscountAmount.value : "0"));
            syncEquivalentDiscounts(draft, "discount_amount");
        } else if (source === "margin_discount") {
            draft.margin_discount = clampRate(parseClNumber(els.lineMarginDiscount ? els.lineMarginDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "margin_discount");
        } else {
            draft.price_discount = clampRate(parseClNumber(els.linePriceDiscount ? els.linePriceDiscount.value : "0"));
            syncEquivalentDiscounts(draft, "price_discount");
        }
        state.lineModal.syncing = true;
        if (els.linePriceDiscount && (format || source !== "price_discount" || document.activeElement !== els.linePriceDiscount)) {
            els.linePriceDiscount.value = formatPlain(draft.price_discount || 0);
        }
        if (els.lineMarginDiscount && (format || source !== "margin_discount" || document.activeElement !== els.lineMarginDiscount)) {
            if (lineHasUnitCost(draft)) {
                els.lineMarginDiscount.value = formatPlain(draft.margin_discount || 0);
            } else {
                els.lineMarginDiscount.value = "";
            }
        }
        if (els.lineDiscountAmount && (format || source !== "discount_amount" || document.activeElement !== els.lineDiscountAmount)) {
            els.lineDiscountAmount.value = formatPlain(draft.discount_amount || 0);
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
        els.lineDiscountHint.textContent = "Dscto precio, margen y monto son el mismo descuento (equivalentes).";
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
        if (!els.lineByAmountResult) return;
        if (!bt) {
            els.lineByAmountResult.innerHTML = "";
            return;
        }
        if (!bt.debajo) {
            var msg = bt.minimo
                ? ("El monto no alcanza para 1 u. (mínimo " + formatPlain(bt.minimo.total) + ")")
                : "Sin tramo aplicable";
            els.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">' + msg + "</span>"
                + (bt.arriba ? " " + formatByAmountOpt(bt.arriba, "Opción:") : "");
            return;
        }
        var html = formatByAmountOpt(bt.debajo, "Entregar", !!bt.exacto);
        if (!bt.exacto && bt.arriba) {
            html += " " + formatByAmountOpt(bt.arriba, "o");
        }
        els.lineByAmountResult.innerHTML = html;
    }

    function runLineByAmount() {
        var line = (state.quote.lines || [])[state.lineModal.index];
        if (!line || !cfg.actions || !cfg.actions.familyPrice) return;
        var montoView = parseClNumber(els.lineMonto ? els.lineMonto.value : "");
        if (!(montoView > 0)) {
            if (els.lineByAmountResult) {
                els.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Ingresa un monto válido</span>';
            }
            return;
        }
        var monto = round2(viewToBruto(montoView));
        var pb = Number(line.producto_base_id) || 0;
        var gid = lineGrupoId(line);
        if (gid) pb = familyPriceBaseId(gid) || pb;
        if (!pb) {
            if (els.lineByAmountResult) {
                els.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Sin producto local</span>';
            }
            return;
        }
        var upp = lineUnitsPerPack(line);
        if (!(upp > 0)) upp = 1;
        var others = 0;
        if (gid) {
            var total = totalFamilyUnitsQuoted(gid);
            var thisUnits = lineBillableUnits(line);
            // Usar qty del modal si está editada.
            var qtyDraft = parseClNumber(els.lineQty ? els.lineQty.value : line.quantity);
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
        if (els.lineByAmountResult) {
            els.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Calculando…</span>';
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
            if (!(pref > 0) && els.linePref) {
                pref = round4(viewToBruto(parseClNumber(els.linePref.value)));
            }
            if (pref > 0) payload.p_ref = String(pref);
        }
        post(cfg.actions.familyPrice, payload).then(function (data) {
            renderByAmountResult(data.by_total);
            if (data.std_rule) state.lineModal.stdRule = data.std_rule;
            if (data.assigned_rule_codigo !== undefined) state.lineModal.ruleCodigo = data.assigned_rule_codigo || null;
            updateRuleModeLabels();
        }).catch(function () {
            if (els.lineByAmountResult) {
                els.lineByAmountResult.innerHTML = '<span class="cq-modal-hint">Error al calcular</span>';
            }
        });
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
            family_qty: String(familyQty),
            rule_mode: state.lineModal.mode === "std" ? "std" : (state.lineModal.mode === "manual" ? "manual" : "auto")
        };
        if (state.lineModal.mode === "ref") {
            payload.rule_mode = "auto";
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
        if (els.lineFamilyNote) {
            if (isFamily) {
                els.lineFamilyNote.hidden = false;
                els.lineFamilyNote.textContent = "Los cambios de precio aplican a toda la familia en esta cotización.";
            } else {
                els.lineFamilyNote.hidden = true;
                els.lineFamilyNote.textContent = "";
            }
        }
        var showRadios = !!lm.hasLocalProduct;
        if (showRadios) {
            if (els.lineModeManualWrap) els.lineModeManualWrap.hidden = true;
            if (els.lineModeRadios) els.lineModeRadios.hidden = false;
        } else {
            if (els.lineModeManualWrap) els.lineModeManualWrap.hidden = false;
            if (els.lineModeRadios) els.lineModeRadios.hidden = true;
            if (lm.mode === "ref" || lm.mode === "std") lm.mode = "auto";
        }
        updateRuleModeLabels();
        if (els.lineModeManual) els.lineModeManual.checked = lm.mode === "manual";
        if (els.lineModeRadios) {
            Array.prototype.forEach.call(els.lineModeRadios.querySelectorAll('input[name="cq-line-mode"]'), function (radio) {
                radio.checked = radio.value === lm.mode;
            });
        }
        updateLineModalModeUi();
    }

    function updateRuleModeLabels() {
        var lm = state.lineModal;
        if (els.lineModeAutoLabel) {
            if (lm.ruleCodigo) {
                els.lineModeAutoLabel.textContent = "Usar regla (" + lm.ruleCodigo + ")";
            } else {
                els.lineModeAutoLabel.textContent = "Usar regla (sin regla: precio lista)";
            }
        }
        var std = lm.stdRule;
        var showStd = !!(std && std.codigo);
        if (showStd && lm.mode !== "std" && lm.ruleCodigo
            && String(lm.ruleCodigo).toUpperCase() === String(std.codigo).toUpperCase()) {
            showStd = false;
        }
        if (els.lineModeStdWrap) els.lineModeStdWrap.hidden = !showStd;
        if (els.lineModeStdLabel && std) {
            var label = (std.nombre || "Regla estándar") + " (" + std.codigo + ")";
            els.lineModeStdLabel.textContent = label;
        }
        if (els.lineByAmount) {
            els.lineByAmount.hidden = !lm.hasLocalProduct;
        }
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
        if (els.lineSubtitle) {
            els.lineSubtitle.textContent = (line.sku || "") + (line.description ? (" — " + line.description) : "");
        }
        if (!els.lineDesc) {
            els.lineDesc = document.getElementById("cq-line-desc");
        }
        if (els.lineDesc) {
            els.lineDesc.value = line.description || "";
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
        if (els.lineDiscountAmount) {
            els.lineDiscountAmount.value = formatPlain(line.discount_amount || 0);
        }
        updateLineModalDiscountHint(line);
        updateLineModalFinalAmount();
        setLineTaxView("bruto");
        if (els.lineByAmountResult) els.lineByAmountResult.innerHTML = "";
        if (els.lineMonto) els.lineMonto.value = "";
        configureLineModalModes(false, lm.isFamily, lm.hasLocalProduct);
        setLineModalMode(lm.mode);
        els.lineModal.hidden = false;
        els.lineModal.setAttribute("aria-hidden", "false");
        scheduleLineModalPreview();
        if (els.lineDesc) {
            els.lineDesc.focus();
            els.lineDesc.select();
        } else if (els.lineQty) {
            els.lineQty.focus();
        }
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
        var descEdited = "";
        if (!els.lineDesc) {
            els.lineDesc = document.getElementById("cq-line-desc");
        }
        if (els.lineDesc) {
            descEdited = String(els.lineDesc.value || "").trim();
        }
        if (descEdited) {
            line.description = descEdited;
        }
        var mode = state.lineModal.mode;
        var draft = lineModalDraftForDiscount();
        if (draft) {
            var priceSrc = clampRate(parseClNumber(els.linePriceDiscount ? els.linePriceDiscount.value : "0"));
            var marginSrc = clampRate(parseClNumber(els.lineMarginDiscount ? els.lineMarginDiscount.value : "0"));
            var amountSrc = round2(parseClNumber(els.lineDiscountAmount ? els.lineDiscountAmount.value : "0"));
            if (document.activeElement === els.linePriceDiscount) {
                draft.price_discount = priceSrc;
                syncEquivalentDiscounts(draft, "price_discount");
            } else if (document.activeElement === els.lineMarginDiscount) {
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

    function updateCustomerClearLink() {
        if (!els.customerClear) return;
        var has = !!(state.quote && state.quote.customer_id);
        els.customerClear.hidden = !has;
        els.customerClear.disabled = state.quote && state.quote.editable === false;
    }

    function selectCustomer(customer) {
        if (!customer) return;
        var name = (customer.nombre_fantasia || customer.razon_social || "").trim();
        state.quote.customer_id = customer.id ? parseInt(customer.id, 10) : null;
        if (!state.quote.customer_id) {
            state.quote.customer_id = null;
        }
        state.quote.customer_name = name;
        state.boundCustomerName = name;
        if (els.customer) {
            els.customer.value = name;
        }
        updateCustomerClearLink();
        closeCustomerSearchModal();
        closeCustomerNewModal();
        scheduleAutosave(0);
        setMessage(name ? ("Cliente «" + name + "» seleccionado.") : "Cliente seleccionado.", false);
    }

    function clearSelectedCustomer() {
        if (state.quote.editable === false) return;
        state.quote.customer_id = null;
        state.quote.customer_name = "";
        state.boundCustomerName = "";
        if (els.customer) els.customer.value = "";
        updateCustomerClearLink();
        scheduleAutosave(0);
    }

    function openCustomerSearchModal() {
        if (!els.custSearchModal) return;
        if (state.quote.editable === false) return;
        if (els.custSearchNombre) els.custSearchNombre.value = "";
        if (els.custSearchRut) els.custSearchRut.value = "";
        if (els.custSearchRazon) els.custSearchRazon.value = "";
        if (els.custSearchLocal) els.custSearchLocal.value = "";
        state.customerSearchRows = [];
        if (els.custSearchResultsWrap) els.custSearchResultsWrap.hidden = true;
        if (els.custSearchHint) {
            els.custSearchHint.hidden = false;
            els.custSearchHint.textContent = "Por favor seleccione parámetros para realizar la búsqueda";
            els.custSearchHint.classList.remove("is-error");
        }
        if (els.custSearchTbody) els.custSearchTbody.innerHTML = "";
        els.custSearchModal.hidden = false;
        els.custSearchModal.setAttribute("aria-hidden", "false");
        if (els.custSearchNombre) els.custSearchNombre.focus();
    }

    function closeCustomerSearchModal() {
        if (!els.custSearchModal) return;
        els.custSearchModal.hidden = true;
        els.custSearchModal.setAttribute("aria-hidden", "true");
    }

    function runCustomerSearch() {
        var nombre = els.custSearchNombre ? els.custSearchNombre.value.trim() : "";
        var rut = els.custSearchRut ? els.custSearchRut.value.trim() : "";
        var razon = els.custSearchRazon ? els.custSearchRazon.value.trim() : "";
        if (!nombre && !rut && !razon) {
            if (els.custSearchHint) {
                els.custSearchHint.hidden = false;
                els.custSearchHint.textContent = "Por favor seleccione parámetros para realizar la búsqueda";
                els.custSearchHint.classList.add("is-error");
            }
            if (els.custSearchResultsWrap) els.custSearchResultsWrap.hidden = true;
            return;
        }
        if (els.custSearchHint) {
            els.custSearchHint.hidden = false;
            els.custSearchHint.textContent = "Buscando…";
            els.custSearchHint.classList.remove("is-error");
        }
        var action = (cfg.actions && cfg.actions.customersSearch) || "riverso_cq_customers_search";
        post(action, { nombre: nombre, rut: rut, razon_social: razon })
            .then(function (data) {
                state.customerSearchRows = (data && data.customers) || [];
                if (els.custSearchHint) {
                    if (!state.customerSearchRows.length) {
                        els.custSearchHint.textContent = "No se encontraron clientes con esos parámetros.";
                        els.custSearchHint.classList.add("is-error");
                    } else {
                        els.custSearchHint.hidden = true;
                    }
                }
                renderCustomerSearchRows(state.customerSearchRows);
                if (els.custSearchResultsWrap) {
                    els.custSearchResultsWrap.hidden = !state.customerSearchRows.length;
                }
            })
            .catch(function (err) {
                if (els.custSearchHint) {
                    els.custSearchHint.hidden = false;
                    els.custSearchHint.textContent = (err && err.message) || "No se pudo buscar.";
                    els.custSearchHint.classList.add("is-error");
                }
                if (els.custSearchResultsWrap) els.custSearchResultsWrap.hidden = true;
            });
    }

    function renderCustomerSearchRows(rows) {
        if (!els.custSearchTbody) return;
        var q = els.custSearchLocal ? els.custSearchLocal.value.trim().toLowerCase() : "";
        var filtered = (rows || []).filter(function (c) {
            if (!q) return true;
            var blob = [
                c.nombre_fantasia || "",
                c.razon_social || "",
                c.rut || ""
            ].join(" ").toLowerCase();
            return blob.indexOf(q) !== -1;
        });
        if (!filtered.length) {
            els.custSearchTbody.innerHTML =
                '<tr><td colspan="4" class="cq-cust-empty">Sin resultados</td></tr>';
            return;
        }
        els.custSearchTbody.innerHTML = filtered
            .map(function (c) {
                return (
                    "<tr>" +
                    "<td>" +
                    escapeHtml(c.nombre_fantasia || c.razon_social || "—") +
                    "</td>" +
                    "<td>" +
                    escapeHtml(c.rut || "—") +
                    "</td>" +
                    "<td>Si</td>" +
                    '<td><button type="button" class="cq-btn cq-btn-customer-select" data-select-customer="' +
                    escapeHtml(c.id) +
                    '">Seleccionar</button></td>' +
                    "</tr>"
                );
            })
            .join("");
    }

    function openCustomerNewModal() {
        if (!els.custNewModal) return;
        if (state.quote.editable === false) return;
        if (!(cfg.caps && cfg.caps.editCustomers)) {
            setMessage("No tienes permisos para crear clientes.", true);
            return;
        }
        resetCustomerNewForm();
        els.custNewModal.hidden = false;
        els.custNewModal.setAttribute("aria-hidden", "false");
        var nombre = document.getElementById("cust-nombre-fantasia");
        if (nombre) nombre.focus();
    }

    function closeCustomerNewModal() {
        if (!els.custNewModal) return;
        els.custNewModal.hidden = true;
        els.custNewModal.setAttribute("aria-hidden", "true");
        setCustomerFormMsg("");
    }

    function resetCustomerNewForm() {
        if (!els.custNewForm) return;
        els.custNewForm.reset();
        var idEl = document.getElementById("cust-id");
        if (idEl) idEl.value = "0";
        var hasContacto = document.getElementById("cust-has-contacto");
        var hasFact = document.getElementById("cust-has-facturacion");
        var hasExtra = document.getElementById("cust-has-datos-extra");
        if (hasContacto) hasContacto.checked = true;
        if (hasFact) hasFact.checked = true;
        if (hasExtra) hasExtra.checked = true;
        var pais = document.getElementById("cust-pais");
        var tipoId = document.getElementById("cust-tipo-id");
        var codigo = document.getElementById("cust-codigo-postal");
        var comuna = document.getElementById("cust-comuna");
        if (pais) pais.value = "CHILE";
        if (tipoId) tipoId.value = "RUT_CLIENTE";
        if (codigo) codigo.value = "0";
        if (comuna) comuna.value = "";
        var names = els.custNewForm.querySelectorAll(".cust-extra-nombre");
        var values = els.custNewForm.querySelectorAll(".cust-extra-valor");
        for (var i = 0; i < names.length; i++) {
            names[i].value = "";
            if (values[i]) values[i].value = "";
        }
        var title = document.getElementById("cust-card-general-title");
        if (title) title.textContent = "Crear cliente";
        syncCustomerFormSections();
        setCustomerFormMsg("");
    }

    function syncCustomerFormSections() {
        toggleCustSection("cust-has-contacto", "cust-section-contacto");
        toggleCustSection("cust-has-facturacion", "cust-section-facturacion");
        toggleCustSection("cust-has-datos-extra", "cust-section-extra");
    }

    function toggleCustSection(checkboxId, sectionId) {
        var checkbox = document.getElementById(checkboxId);
        var section = document.getElementById(sectionId);
        if (!checkbox || !section) return;
        if (checkbox.checked) {
            section.classList.remove("is-collapsed");
        } else {
            section.classList.add("is-collapsed");
        }
    }

    function setCustomerFormMsg(text, ok) {
        var el = document.getElementById("cust-form-msg");
        if (!el) return;
        if (!text) {
            el.hidden = true;
            el.textContent = "";
            el.className = "cust-form-msg";
            return;
        }
        el.hidden = false;
        el.textContent = text;
        el.className = "cust-form-msg " + (ok ? "is-ok" : "is-error");
    }

    function collectCustomerDatosExtra() {
        if (!els.custNewForm) return [];
        var names = els.custNewForm.querySelectorAll(".cust-extra-nombre");
        var values = els.custNewForm.querySelectorAll(".cust-extra-valor");
        var out = [];
        for (var i = 0; i < 4; i++) {
            out.push({
                nombre: names[i] ? String(names[i].value || "").trim() : "",
                valor: values[i] ? String(values[i].value || "").trim() : ""
            });
        }
        return out;
    }

    function validateCustomerNewForm() {
        var nombre = document.getElementById("cust-nombre-fantasia");
        if (!nombre || !String(nombre.value || "").trim()) {
            return "El nombre de fantasía es obligatorio";
        }
        var hasContacto = document.getElementById("cust-has-contacto");
        if (hasContacto && hasContacto.checked) {
            var pn = document.getElementById("cust-primer-nombre");
            var ap = document.getElementById("cust-apellido-paterno");
            if (!pn || !String(pn.value || "").trim()) {
                return "El primer nombre del contacto es obligatorio";
            }
            if (!ap || !String(ap.value || "").trim()) {
                return "El apellido paterno del contacto es obligatorio";
            }
        }
        return "";
    }

    function boolFlag(el) {
        return el && el.checked ? "1" : "0";
    }

    function valOf(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || "") : "";
    }

    function saveCustomerFromQuote() {
        if (state.quote.editable === false) return;
        var err = validateCustomerNewForm();
        if (err) {
            setCustomerFormMsg(err, false);
            return;
        }
        if (els.custNewSave) els.custNewSave.disabled = true;
        setCustomerFormMsg("");
        var action = (cfg.actions && cfg.actions.customerSave) || "riverso_cq_customer_save";
        var payload = {
            id: 0,
            nombre_fantasia: valOf("cust-nombre-fantasia"),
            has_contacto: boolFlag(document.getElementById("cust-has-contacto")),
            primer_nombre: valOf("cust-primer-nombre"),
            apellido_paterno: valOf("cust-apellido-paterno"),
            contacto_telefono: valOf("cust-contacto-telefono"),
            contacto_email: valOf("cust-contacto-email"),
            has_facturacion: boolFlag(document.getElementById("cust-has-facturacion")),
            pais: valOf("cust-pais") || "CHILE",
            tipo_identificacion: valOf("cust-tipo-id") || "RUT_CLIENTE",
            rut: valOf("cust-rut"),
            razon_social: valOf("cust-razon-social"),
            direccion: valOf("cust-direccion"),
            comuna: valOf("cust-comuna"),
            ciudad: valOf("cust-ciudad"),
            giro: valOf("cust-giro"),
            facturacion_telefono: valOf("cust-facturacion-telefono"),
            codigo_postal: valOf("cust-codigo-postal") || "0",
            has_datos_extra: boolFlag(document.getElementById("cust-has-datos-extra")),
            datos_extra: JSON.stringify(collectCustomerDatosExtra()),
            activo: "1"
        };
        post(action, payload)
            .then(function (data) {
                if (els.custNewSave) els.custNewSave.disabled = false;
                var customer = data && data.customer ? data.customer : null;
                if (!customer && data && data.id) {
                    customer = {
                        id: data.id,
                        nombre_fantasia: payload.nombre_fantasia,
                        razon_social: payload.razon_social
                    };
                }
                if (!customer) {
                    setCustomerFormMsg("Cliente creado, pero no se pudo seleccionar.", false);
                    return;
                }
                setCustomerFormMsg(data.message || "Cliente creado", true);
                selectCustomer(customer);
            })
            .catch(function (e) {
                if (els.custNewSave) els.custNewSave.disabled = false;
                setCustomerFormMsg((e && e.message) || "No se pudo guardar", false);
            });
    }
})();
