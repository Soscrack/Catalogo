/**
 * Lupa de proveedores: nombre oficial, RUT o apodo. Puede crear un apodo provisional.
 */
(function () {
    if (window.RiversoSupplierPicker) {
        return;
    }

    var root = null;
    var input = null;
    var results = null;
    var status = null;
    var createBtn = null;
    var timer = null;
    var current = null;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function ensure() {
        if (root) {
            return;
        }
        root = document.createElement('div');
        root.className = 'rsp-overlay';
        root.hidden = true;
        root.innerHTML =
            '<div class="rsp-modal" role="dialog" aria-modal="true" aria-labelledby="rsp-title">' +
                '<div class="rsp-head">' +
                    '<h3 id="rsp-title">Buscar proveedor</h3>' +
                    '<button type="button" class="rsp-close" aria-label="Cerrar">&times;</button>' +
                '</div>' +
                '<input type="search" class="rsp-input" placeholder="Nombre, apodo o RUT" autocomplete="off">' +
                '<div class="rsp-status"></div>' +
                '<div class="rsp-results"></div>' +
                '<button type="button" class="rsp-create" hidden>Agregar este apodo</button>' +
            '</div>';
        document.body.appendChild(root);
        input = root.querySelector('.rsp-input');
        results = root.querySelector('.rsp-results');
        status = root.querySelector('.rsp-status');
        createBtn = root.querySelector('.rsp-create');
        root.querySelector('.rsp-close').addEventListener('click', close);
        root.addEventListener('click', function (event) {
            if (event.target === root) {
                close();
            }
        });
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 250);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
        results.addEventListener('click', function (event) {
            var button = event.target.closest('[data-id]');
            if (!button || !current) {
                return;
            }
            var supplier = (current.list || []).filter(function (item) {
                return String(item.id) === String(button.getAttribute('data-id'));
            })[0];
            if (supplier && current.onSelect) {
                current.onSelect(supplier);
                close();
            }
        });
        createBtn.addEventListener('click', createProvisional);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root && !root.hidden) {
                close();
            }
        });
    }

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', current.nonce);
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key] == null ? '' : String(fields[key]));
        });
        return fetch(current.ajaxUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        });
    }

    function paint(list, query) {
        current.list = list || [];
        if (!list.length) {
            results.innerHTML = '<p class="rsp-empty">Sin proveedores para esa búsqueda.</p>';
        } else {
            results.innerHTML = list.map(function (supplier) {
                var extra = '';
                if (parseInt(supplier.provisional, 10) === 1) {
                    extra = '<span class="rsp-badge">Sin factura todavía</span>';
                } else if (supplier.rut) {
                    extra = '<span class="rsp-rut">' + esc(supplier.rut) + '</span>';
                }
                if (supplier.matched_apodo) {
                    extra += '<span class="rsp-apodo">Apodo: ' + esc(supplier.matched_apodo) + '</span>';
                }
                return '<button type="button" class="rsp-item" data-id="' + esc(supplier.id) + '">' +
                    '<strong>' + esc(supplier.nombre) + '</strong>' + extra +
                    '</button>';
            }).join('');
        }
        if (query.length >= 2) {
            createBtn.hidden = false;
            createBtn.textContent = 'Agregar apodo «' + query + '»';
        } else {
            createBtn.hidden = true;
        }
    }

    function search() {
        if (!current) {
            return;
        }
        var query = (input.value || '').trim();
        status.textContent = '';
        if (query.length < 2) {
            results.innerHTML = '<p class="rsp-empty">Escribe al menos 2 letras.</p>';
            createBtn.hidden = true;
            return;
        }
        post('riverso_search_suppliers', { search: query, limit: 20 }).then(function (data) {
            if (!current || (input.value || '').trim() !== query) {
                return;
            }
            if (!data || !data.success) {
                status.textContent = (data && data.data && data.data.message) || 'No se pudo buscar';
                return;
            }
            paint(data.data.suppliers || [], query);
        }).catch(function () {
            status.textContent = 'No se pudo buscar';
        });
    }

    function createProvisional() {
        var query = (input.value || '').trim();
        if (query.length < 2 || !current) {
            return;
        }
        createBtn.disabled = true;
        post('riverso_create_provisional_supplier', { apodo: query }).then(function (data) {
            createBtn.disabled = false;
            if (!data || !data.success) {
                status.textContent = (data && data.data && data.data.message) || 'No se pudo agregar el apodo';
                return;
            }
            var supplier = data.data.supplier || {};
            if (supplier.existing) {
                status.textContent = 'Ese apodo ya está registrado. Quedó seleccionado.';
            }
            if (current.onSelect) {
                current.onSelect(supplier);
            }
            close();
        }).catch(function () {
            createBtn.disabled = false;
            status.textContent = 'No se pudo agregar el apodo';
        });
    }

    function close() {
        if (!root) {
            return;
        }
        root.hidden = true;
        current = null;
    }

    window.RiversoSupplierPicker = {
        open: function (options) {
            ensure();
            current = {
                ajaxUrl: options.ajaxUrl || '',
                nonce: options.nonce || '',
                onSelect: options.onSelect || function () {}
            };
            root.querySelector('#rsp-title').textContent = options.title || 'Buscar proveedor';
            input.value = options.query || '';
            status.textContent = '';
            results.innerHTML = '';
            createBtn.hidden = true;
            root.hidden = false;
            input.focus();
            if ((input.value || '').trim().length >= 2) {
                search();
            } else {
                results.innerHTML = '<p class="rsp-empty">Escribe el nombre o el apodo.</p>';
            }
        }
    };
})();
