/**
 * Portal interno — ajustes para celular y tablet (estilos en portal-mobile.css).
 *
 * - Tablas de trabajo: bajo 640px se muestran como tarjetas. Aquí se etiqueta cada
 *   celda con el texto de su encabezado (data-label) y se marca su papel en la tarjeta.
 *   Las tablas se vuelven a dibujar desde cada módulo, así que se re-etiquetan al cambiar.
 * - Filtros plegables en Cotizaciones y Facturación · Buscar.
 * - Botón «Más» con las acciones secundarias del editor de cotización.
 */
(function () {
    "use strict";

    var PHONE = window.matchMedia("(max-width: 640px)");

    // title: encabezado de la celda que hace de título (por defecto, la primera columna
    // que calza con TITLE_RE). hide: columnas que no se muestran en la tarjeta.
    var TABLES = [
        { sel: "#rx-list-view .rx-table" },
        { sel: "#rx-tab-recibir .rx-lines", hide: ["#"] },
        { sel: "#rx-tab-ordenar .rx-table" },
        { sel: ".cq-results-table" },
        { sel: "#cq-editor-view .cq-table, #bill-boleta-lines-table" },
        { sel: ".bill-search-table", hide: ["Neto", "Impuestos"] },
        { sel: "#po-portal-table" },
        { sel: "#po-portal-editor .portal-table", title: "SKU" },
        { sel: ".portal-warehouse-section .wh-table" }
    ];
    var TITLE_RE = /^(Producto|Nombre|Título|Detalle|Documento|Receptor|Número)/i;
    var ACTIONS_RE = /^(Acciones|Ver cotización)$/i;
    var ROLES = ["rvm-title", "rvm-corner", "rvm-actions", "rvm-hide"];

    function headerColumns(table) {
        var head = table.tHead;
        var row = head && head.rows[head.rows.length - 1];
        if (!row) {
            return null;
        }
        var cols = [];
        Array.prototype.forEach.call(row.cells, function (th) {
            var col = {
                label: (th.textContent || "").replace(/\s+/g, " ").trim(),
                // Casilla de selección o botón de expandir: va a la esquina de la tarjeta.
                corner: !!th.querySelector("input[type=checkbox]") || th.classList.contains("cq-col-expand")
            };
            for (var i = 0; i < (th.colSpan || 1); i++) {
                cols.push(col);
            }
        });
        return cols;
    }

    function titleIndex(cols, wanted) {
        var i;
        for (i = 0; i < cols.length; i++) {
            if (wanted ? cols[i].label === wanted : TITLE_RE.test(cols[i].label)) {
                return i;
            }
        }
        for (i = 0; i < cols.length; i++) {
            if (!cols[i].corner && cols[i].label) {
                return i;
            }
        }
        return 0;
    }

    function cellRole(col, start, title, hide) {
        if (col.corner) {
            return "rvm-corner";
        }
        if (start === title) {
            return "rvm-title";
        }
        if (!col.label || ACTIONS_RE.test(col.label)) {
            return "rvm-actions";
        }
        return hide.indexOf(col.label) >= 0 ? "rvm-hide" : "";
    }

    function prepareTable(table, conf) {
        var cols = headerColumns(table);
        if (!cols || !cols.length) {
            return;
        }
        table.classList.add("rvm-cards");
        var wrap = table.parentElement;
        if (wrap && wrap.tagName === "DIV" && wrap.children.length === 1) {
            wrap.classList.add("rvm-cards-wrap");
        }
        var title = titleIndex(cols, conf.title);
        var hide = conf.hide || [];
        Array.prototype.forEach.call(table.tBodies, function (tbody) {
            Array.prototype.forEach.call(tbody.rows, function (tr) {
                // Filas de una sola celda (cargando, vacío, cabecera de familia, detalle expandido).
                if (tr.cells.length === 1 && cols.length > 1) {
                    tr.classList.add("rvm-row-full");
                    return;
                }
                var start = 0;
                Array.prototype.forEach.call(tr.cells, function (td) {
                    var col = cols[start] || { label: "", corner: false };
                    var role = cellRole(col, start, title, hide);
                    start += td.colSpan || 1;
                    ROLES.forEach(function (r) {
                        if (r !== role && td.classList.contains(r)) {
                            td.classList.remove(r);
                        }
                    });
                    if (role) {
                        td.classList.add(role);
                        td.removeAttribute("data-label");
                    } else if (td.getAttribute("data-label") !== col.label) {
                        td.setAttribute("data-label", col.label);
                    }
                });
            });
        });
    }

    function labelTables() {
        TABLES.forEach(function (conf) {
            Array.prototype.forEach.call(document.querySelectorAll(conf.sel), function (table) {
                prepareTable(table, conf);
            });
        });
    }

    var scheduled = false;
    function scheduleLabel() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            labelTables();
        });
    }

    /**
     * Tarjeta de filtros que se pliega tocando su título (solo bajo 640px; ver CSS).
     * Al buscar en celular se pliega y se baja a los resultados.
     */
    function collapsibleFilters(opts) {
        var card = document.querySelector(opts.card);
        var head = card && card.querySelector(opts.head);
        if (!head) {
            return;
        }
        card.classList.add("rvm-collapsible");
        head.classList.add("rvm-collapse-head");
        head.setAttribute("role", "button");
        head.setAttribute("tabindex", "0");
        function setCollapsed(collapsed) {
            card.classList.toggle("rvm-collapsed", collapsed);
            head.setAttribute("aria-expanded", collapsed ? "false" : "true");
        }
        setCollapsed(PHONE.matches && !!opts.startCollapsed);
        head.addEventListener("click", function () {
            setCollapsed(!card.classList.contains("rvm-collapsed"));
        });
        head.addEventListener("keydown", function (e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                setCollapsed(!card.classList.contains("rvm-collapsed"));
            }
        });
        var submit = document.querySelector(opts.submit);
        if (submit) {
            submit.addEventListener("click", function () {
                if (!PHONE.matches) {
                    return;
                }
                setCollapsed(true);
                window.setTimeout(function () {
                    var results = document.querySelector(opts.results);
                    if (results && !results.hidden) {
                        results.scrollIntoView({ behavior: "smooth", block: "start" });
                    }
                }, 350);
            });
        }
    }

    /** Editor de cotización: en celular, Borrar / Importar / plantilla PDF van detrás de «Más». */
    function quoteMoreButton() {
        var bar = document.querySelector("#cq-editor-view .cq-top-actions");
        if (!bar || bar.querySelector(".rvm-more-btn")) {
            return;
        }
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "cq-btn rvm-more-btn";
        btn.textContent = "Más ▾";
        btn.setAttribute("aria-expanded", "false");
        btn.addEventListener("click", function () {
            var open = bar.classList.toggle("rvm-more-open");
            btn.setAttribute("aria-expanded", open ? "true" : "false");
            btn.textContent = open ? "Menos ▴" : "Más ▾";
        });
        bar.appendChild(btn);
    }

    function init() {
        labelTables();
        new MutationObserver(scheduleLabel).observe(document.body, { childList: true, subtree: true });
        collapsibleFilters({
            card: "#cq-search-form",
            head: ".cq-search-card-title",
            submit: "#cq-apply-filters",
            results: "#cq-results-panel",
            startCollapsed: true
        });
        collapsibleFilters({
            card: "#riverso-billing-search .bill-search-card:not(.bill-search-advanced)",
            head: ".bill-search-card-head",
            submit: "#bs-search",
            results: "#bs-results"
        });
        quoteMoreButton();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
