/**
 * Editor compartido de familias (wp-admin + portal).
 * Expone window.RiversoFamilyEditor.openEdit / openView / openCreate.
 */
(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    function cfg() {
        return window.riversoFamilyEditor || {};
    }

    function ajaxUrl() {
        return cfg().ajaxUrl || (window.riverso_pos && riverso_pos.ajax_url) || window.ajaxurl || '';
    }

    function nonce() {
        return cfg().nonce || (window.riverso_pos && riverso_pos.nonce) || '';
    }

    function canManage() {
        return cfg().canManage !== false;
    }

    function esc(v) {
        return $('<div>').text(v === null || v === undefined ? '' : String(v)).html();
    }

    function post(action, data) {
        return $.post(ajaxUrl(), Object.assign({ action: action, nonce: nonce() }, data || {}));
    }

    function ensureStyles() {
        if (document.getElementById('riverso-family-editor-styles')) {
            return;
        }
        var css = document.createElement('style');
        css.id = 'riverso-family-editor-styles';
        css.textContent =
            '.riverso-family-modal-panel .button{display:inline-block;padding:6px 12px;border:1px solid #ccc;border-radius:3px;background:#f7f7f7;color:#1d2327;cursor:pointer;font-size:13px;line-height:1.4;text-decoration:none;}' +
            '.riverso-family-modal-panel .button:hover{background:#eee;}' +
            '.riverso-family-modal-panel .button:disabled{opacity:.55;cursor:not-allowed;}' +
            '.riverso-family-modal-panel .button-primary{background:#2271b1;border-color:#2271b1;color:#fff;}' +
            '.riverso-family-modal-panel .button-primary:hover{background:#135e96;color:#fff;}' +
            '.riverso-family-modal-panel .button-small{padding:3px 8px;font-size:12px;}' +
            '.riverso-family-modal-panel .large-text{font-size:14px;}';
        document.head.appendChild(css);
    }

    function closeAll() {
        $('.riverso-family-modal-overlay').remove();
    }

    function badges(esLocal, esOnline) {
        var html = '';
        if (esLocal) {
            html += '<span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;">Local</span>';
        }
        if (esOnline) {
            html += '<span style="display:inline-block;background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;">Online</span>';
        }
        if (!esLocal && !esOnline) {
            html += '<span style="display:inline-block;background:#f5f5f5;color:#888;padding:1px 6px;border-radius:3px;font-size:11px;">Sin clasificar</span>';
        }
        return html;
    }

    /** SKU lines: solo local, solo online, o ambos si está completo. */
    function skuLines(item) {
        var local = (item.sku_local != null && item.sku_local !== '')
            ? String(item.sku_local)
            : (item.canonical_sku || '');
        var online = item.sku_online != null ? String(item.sku_online) : '';
        var esLocal = !!item.es_local || local !== '';
        var esOnline = !!item.es_online || online !== '';
        var parts = [];
        if (esLocal && esOnline) {
            parts.push('<span style="color:#1565c0;">Local:</span> <code>' + esc(local || '—') + '</code>');
            parts.push('<span style="color:#2e7d32;">Online:</span> <code>' + esc(online || '—') + '</code>');
        } else if (esOnline) {
            parts.push('<span style="color:#2e7d32;">Online:</span> <code>' + esc(online || '—') + '</code>');
        } else if (esLocal) {
            parts.push('<span style="color:#1565c0;">Local:</span> <code>' + esc(local || '—') + '</code>');
        } else {
            parts.push('<code>#' + esc(String(item.id || item.producto_base_id || '?')) + '</code>');
        }
        return parts.join(' &nbsp; ');
    }

    function bindOverlay($overlay) {
        $overlay.on('click', function (ev) {
            if ($(ev.target).closest('.riverso-family-modal-panel').length === 0) {
                closeAll();
            }
        });
        $overlay.find('.riverso-family-modal-close').on('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            closeAll();
        });
        $(document).off('keydown.riversoFamilyEditor').on('keydown.riversoFamilyEditor', function (e) {
            if (e.key === 'Escape') {
                closeAll();
                $(document).off('keydown.riversoFamilyEditor');
            }
        });
    }

    function renderMembersList(members, editable) {
        if (!members || !members.length) {
            return '<p style="color:#999;margin:8px 0;">Sin miembros con SKU local</p>';
        }
        return '<ul class="riverso-family-members-list" style="list-style:none;margin:0;padding:0;">' + members.map(function (m) {
            var units = m.cantidad_unidades != null ? (' · envase ' + m.cantidad_unidades) : '';
            var stockU = m.stock_unidades != null
                ? (' · ' + Number(m.stock_unidades).toLocaleString('es-CL') + ' u')
                : '';
            var removeBtn = editable && canManage()
                ? '<button type="button" class="button button-small riverso-family-remove-member" data-member-id="' + esc(String(m.id)) + '" style="margin-left:8px;">Quitar</button>'
                : '';
            return '<li style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:8px;border:1px solid #eee;border-radius:4px;margin-bottom:6px;background:#fafafa;">' +
                '<div style="flex:1;font-size:13px;">' +
                badges(!!m.es_local, !!m.es_online) +
                '<strong>' + esc(m.nombre_canonico || '-') + '</strong><br>' +
                '<span style="font-size:12px;">' + skuLines(m) + '</span>' +
                '<span style="color:#666;">' + esc(units + stockU) + '</span>' +
                '</div>' + removeBtn +
                '</li>';
        }).join('') + '</ul>';
    }

    function renderPending(pending) {
        if (!pending || !pending.length) {
            return '';
        }
        return '<div style="margin-top:12px;"><strong>Pendientes (proveedor):</strong><ul style="margin:6px 0 0 18px;font-size:13px;">' +
            pending.map(function (p) {
                var qty = p.cantidad_unidades ? (' · envase ' + p.cantidad_unidades) : '';
                return '<li style="color:#ef6c00;">' + esc(p.codigo_proveedor || '-') + esc(qty) + '</li>';
            }).join('') +
            '</ul></div>';
    }

    function openView(familyId) {
        ensureStyles();
        closeAll();
        post('riverso_families_get', { grupo_id: familyId }).done(function (r) {
            if (!r.success || !r.data || !r.data.family) {
                alert((r.data && r.data.message) || 'No se pudo cargar la familia');
                return;
            }
            var fam = r.data.family;
            var members = fam.members || [];
            var stock = fam.stock && fam.stock.stock_unidades != null
                ? Number(fam.stock.stock_unidades).toLocaleString('es-CL') + ' u'
                : '—';
            var warn = (fam.stock && fam.stock.warnings && fam.stock.warnings.length)
                ? '<p style="color:#e65100;font-size:12px;">' + esc(fam.stock.warnings.join(' | ')) + '</p>'
                : '';
            var $modal = $(
                '<div class="riverso-family-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;">' +
                '<div class="riverso-family-modal-panel" style="background:#fff;padding:20px;border-radius:6px;max-width:720px;width:94%;max-height:85vh;overflow-y:auto;">' +
                '<h3 style="margin:0 0 8px;">' + esc(fam.nombre || fam.codigo_grupo) + '</h3>' +
                '<p style="margin:0 0 12px;color:#666;font-size:13px;">' + esc(fam.codigo_grupo || '') +
                ' · ' + esc(fam.tipo_sustitucion || '') +
                ' · Stock: <strong>' + esc(stock) + '</strong> · ' + members.length + ' miembro(s)</p>' +
                warn +
                '<p><strong>Miembros</strong></p>' +
                renderMembersList(members, false) +
                renderPending(fam.pending) +
                '<div style="margin-top:16px;text-align:right;">' +
                '<button type="button" class="button riverso-family-modal-close">Cerrar</button>' +
                '</div></div></div>'
            );
            $('body').append($modal);
            bindOverlay($modal);
        });
    }

    function refreshEditMembers($modal, familyId) {
        return post('riverso_families_get', { grupo_id: familyId }).done(function (r) {
            if (!r.success || !r.data || !r.data.family) {
                return;
            }
            var fam = r.data.family;
            $modal.find('.riverso-family-members-wrap').html(renderMembersList(fam.members || [], true));
            $modal.find('.riverso-family-pending-wrap').html(renderPending(fam.pending));
            var stock = fam.stock && fam.stock.stock_unidades != null
                ? Number(fam.stock.stock_unidades).toLocaleString('es-CL') + ' u'
                : '—';
            $modal.find('.riverso-family-stock-label').text(stock);
            $modal.find('.riverso-family-member-count').text(String((fam.members || []).length));
            if (typeof cfg().onChanged === 'function') {
                cfg().onChanged(familyId);
            }
        });
    }

    function renderSearchResults(items, familyId) {
        if (!items || !items.length) {
            return '<p style="color:#999;font-size:13px;margin:8px 0;">Sin resultados</p>';
        }
        return '<ul style="list-style:none;margin:0;padding:0;max-height:220px;overflow-y:auto;">' + items.map(function (it) {
            var action;
            if (it.can_add && canManage()) {
                action = '<button type="button" class="button button-small button-primary riverso-family-add-member" data-product-id="' +
                    esc(String(it.id)) + '">Agregar</button>';
            } else if (it.aviso) {
                action = '<span style="color:#c62828;font-size:12px;">' + esc(it.aviso) + '</span>';
            } else {
                action = '';
            }
            return '<li style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px;border-bottom:1px solid #eee;font-size:13px;">' +
                '<div style="flex:1;min-width:0;">' +
                badges(!!it.es_local, !!it.es_online) +
                (it.exacto ? '<span style="font-size:11px;color:#6a1b9a;margin-right:4px;">exacto</span>' : '') +
                '<strong>' + esc(it.nombre_canonico || '-') + '</strong><br>' +
                '<span style="font-size:12px;">' + skuLines(it) + '</span>' +
                '</div>' + action +
                '</li>';
        }).join('') + '</ul>';
    }

    function openEdit(familyId) {
        ensureStyles();
        closeAll();
        post('riverso_families_get', { grupo_id: familyId }).done(function (r) {
            if (!r.success || !r.data || !r.data.family) {
                alert((r.data && r.data.message) || 'No se pudo cargar la familia');
                return;
            }
            var fam = r.data.family;
            var stock = fam.stock && fam.stock.stock_unidades != null
                ? Number(fam.stock.stock_unidades).toLocaleString('es-CL') + ' u'
                : '—';
            var manage = canManage();
            var metaBlock = manage
                ? (
                    '<label style="display:block;margin-bottom:10px;"><strong>Nombre:</strong><br>' +
                    '<input type="text" class="large-text family-edit-nombre" value="' + esc(fam.nombre) + '" style="width:100%;padding:6px;box-sizing:border-box;"></label>' +
                    '<label style="display:block;margin-bottom:10px;"><strong>Código:</strong><br>' +
                    '<input type="text" class="large-text" value="' + esc(fam.codigo_grupo) + '" style="width:100%;padding:6px;box-sizing:border-box;" disabled></label>' +
                    '<label style="display:block;margin-bottom:12px;"><strong>Tipo de Sustitución:</strong><br>' +
                    '<select class="family-edit-tipo" style="width:100%;padding:6px;box-sizing:border-box;">' +
                    '<option value="exacta"' + (fam.tipo_sustitucion === 'exacta' ? ' selected' : '') + '>Exacta</option>' +
                    '<option value="preferida"' + (fam.tipo_sustitucion === 'preferida' ? ' selected' : '') + '>Preferida</option>' +
                    '<option value="complementaria"' + (fam.tipo_sustitucion === 'complementaria' ? ' selected' : '') + '>Complementaria</option>' +
                    '</select></label>'
                )
                : (
                    '<p style="margin:0 0 12px;"><strong>' + esc(fam.nombre) + '</strong><br>' +
                    '<small style="color:#666;">' + esc(fam.codigo_grupo) + ' · ' + esc(fam.tipo_sustitucion || '') + '</small></p>'
                );

            var searchBlock = manage
                ? (
                    '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
                    '<strong>Agregar miembro</strong>' +
                    '<input type="search" class="riverso-family-search-input" placeholder="Buscar por SKU, barcode, código proveedor o nombre…" style="width:100%;padding:8px;margin:8px 0;box-sizing:border-box;">' +
                    '<div class="riverso-family-search-results" style="min-height:24px;"></div>' +
                    '</div>'
                )
                : '';

            var $modal = $(
                '<div class="riverso-family-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;">' +
                '<div class="riverso-family-modal-panel" style="background:#fff;padding:20px;border-radius:6px;max-width:720px;width:94%;max-height:90vh;overflow-y:auto;">' +
                '<h3 style="margin:0 0 12px;">Editar Familia</h3>' +
                metaBlock +
                '<p style="margin:0 0 8px;color:#666;font-size:13px;">Stock familia: <strong class="riverso-family-stock-label">' + esc(stock) +
                '</strong> · <span class="riverso-family-member-count">' + (fam.members || []).length + '</span> miembro(s)</p>' +
                '<p style="margin:12px 0 6px;"><strong>Miembros</strong></p>' +
                '<div class="riverso-family-members-wrap">' + renderMembersList(fam.members || [], true) + '</div>' +
                '<div class="riverso-family-pending-wrap">' + renderPending(fam.pending) + '</div>' +
                searchBlock +
                '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
                '<button type="button" class="button riverso-family-modal-close">Cerrar</button>' +
                (manage ? '<button type="button" class="button button-primary riverso-family-save-meta">Guardar datos</button>' : '') +
                '</div></div></div>'
            );

            $('body').append($modal);
            bindOverlay($modal);

            $modal.on('click', '.riverso-family-remove-member', function (ev) {
                ev.preventDefault();
                if (!confirm('¿Quitar este miembro de la familia?')) {
                    return;
                }
                var memberId = $(this).data('member-id');
                var $btn = $(this).prop('disabled', true);
                post('riverso_families_remove_member', { member_id: memberId }).done(function (resp) {
                    if (!resp.success) {
                        alert((resp.data && resp.data.message) || 'No se pudo quitar');
                        $btn.prop('disabled', false);
                        return;
                    }
                    refreshEditMembers($modal, familyId);
                }).fail(function () {
                    $btn.prop('disabled', false);
                    alert('Error de red');
                });
            });

            $modal.find('.riverso-family-save-meta').on('click', function () {
                var nombre = $modal.find('.family-edit-nombre').val().trim();
                var tipo = $modal.find('.family-edit-tipo').val();
                if (!nombre) {
                    alert('El nombre es requerido');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Guardando…');
                post('riverso_families_update', {
                    grupo_id: familyId,
                    nombre: nombre,
                    tipo_sustitucion: tipo
                }).done(function (resp) {
                    $btn.prop('disabled', false).text('Guardar datos');
                    if (!resp.success) {
                        alert((resp.data && resp.data.message) || 'No se pudo guardar');
                        return;
                    }
                    if (typeof cfg().onChanged === 'function') {
                        cfg().onChanged(familyId);
                    }
                    alert('Familia actualizada');
                }).fail(function () {
                    $btn.prop('disabled', false).text('Guardar datos');
                    alert('Error de red');
                });
            });

            var searchTimer = null;
            $modal.find('.riverso-family-search-input').on('input', function () {
                var q = $(this).val().trim();
                var $results = $modal.find('.riverso-family-search-results');
                clearTimeout(searchTimer);
                if (q.length < 2 && !/^\d+$/.test(q)) {
                    $results.html('<p style="color:#999;font-size:12px;margin:4px 0;">Escribe al menos 2 caracteres</p>');
                    return;
                }
                $results.html('<p style="color:#999;font-size:12px;margin:4px 0;">Buscando…</p>');
                searchTimer = setTimeout(function () {
                    post('riverso_families_search_candidates', {
                        q: q,
                        grupo_id: familyId,
                        limit: 15
                    }).done(function (resp) {
                        if (!resp.success) {
                            $results.html('<p style="color:#c62828;font-size:12px;">' + esc((resp.data && resp.data.message) || 'Error') + '</p>');
                            return;
                        }
                        $results.html(renderSearchResults(resp.data.items || [], familyId));
                    }).fail(function () {
                        $results.html('<p style="color:#c62828;font-size:12px;">Error de red</p>');
                    });
                }, 300);
            });

            $modal.on('click', '.riverso-family-add-member', function (ev) {
                ev.preventDefault();
                var productId = $(this).data('product-id');
                var $btn = $(this).prop('disabled', true).text('…');
                post('riverso_families_add_member', {
                    grupo_id: familyId,
                    producto_base_id: productId,
                    prioridad: 100,
                    es_preferido: 0
                }).done(function (resp) {
                    if (!resp.success) {
                        alert((resp.data && resp.data.message) || 'No se pudo agregar');
                        $btn.prop('disabled', false).text('Agregar');
                        return;
                    }
                    refreshEditMembers($modal, familyId);
                    var q = $modal.find('.riverso-family-search-input').val().trim();
                    if (q) {
                        $modal.find('.riverso-family-search-input').trigger('input');
                    } else {
                        $modal.find('.riverso-family-search-results').empty();
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Agregar');
                    alert('Error de red');
                });
            });
        });
    }

    function openCreate(onCreated) {
        if (!canManage()) {
            alert('Sin permisos para crear familias');
            return;
        }
        ensureStyles();
        closeAll();
        var $modal = $(
            '<div class="riverso-family-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;">' +
            '<div class="riverso-family-modal-panel" style="background:#fff;padding:20px;border-radius:6px;max-width:500px;width:94%;">' +
            '<h3 style="margin:0 0 12px;">Crear Nueva Familia</h3>' +
            '<label style="display:block;margin-bottom:10px;"><strong>Nombre:</strong><br>' +
            '<input type="text" id="riverso-new-family-nombre" class="large-text" placeholder="Ej. Tuercas M6" style="width:100%;padding:6px;box-sizing:border-box;"></label>' +
            '<label style="display:block;margin-bottom:10px;"><strong>Código:</strong><br>' +
            '<input type="text" id="riverso-new-family-codigo" class="large-text" placeholder="Ej. TUERCAS_M6" style="width:100%;padding:6px;box-sizing:border-box;"></label>' +
            '<label style="display:block;margin-bottom:16px;"><strong>Tipo de Sustitución:</strong><br>' +
            '<select id="riverso-new-family-tipo" style="width:100%;padding:6px;box-sizing:border-box;">' +
            '<option value="exacta">Exacta</option>' +
            '<option value="preferida">Preferida</option>' +
            '<option value="complementaria">Complementaria</option>' +
            '</select></label>' +
            '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
            '<button type="button" class="button riverso-family-modal-close">Cancelar</button>' +
            '<button type="button" class="button button-primary" id="riverso-new-family-save">Crear</button>' +
            '</div></div></div>'
        );
        $('body').append($modal);
        bindOverlay($modal);
        $modal.find('#riverso-new-family-nombre').focus();
        $modal.find('#riverso-new-family-save').on('click', function () {
            var nombre = $modal.find('#riverso-new-family-nombre').val().trim();
            var codigo = $modal.find('#riverso-new-family-codigo').val().trim();
            var tipo = $modal.find('#riverso-new-family-tipo').val();
            if (!nombre || !codigo) {
                alert('Nombre y código son requeridos');
                return;
            }
            var $btn = $(this).prop('disabled', true).text('Creando…');
            post('riverso_families_create', {
                nombre: nombre,
                codigo_grupo: codigo,
                tipo_sustitucion: tipo
            }).done(function (resp) {
                if (!resp.success) {
                    $btn.prop('disabled', false).text('Crear');
                    alert((resp.data && resp.data.message) || 'No se pudo crear');
                    return;
                }
                closeAll();
                if (typeof onCreated === 'function') {
                    onCreated(resp.data && resp.data.family);
                } else if (typeof cfg().onChanged === 'function') {
                    cfg().onChanged(resp.data && resp.data.family && resp.data.family.id);
                }
                var newId = resp.data && resp.data.family && resp.data.family.id;
                if (newId) {
                    openEdit(newId);
                }
            }).fail(function () {
                $btn.prop('disabled', false).text('Crear');
                alert('Error de red');
            });
        });
    }

    window.RiversoFamilyEditor = {
        openView: openView,
        openEdit: openEdit,
        openCreate: openCreate,
        closeAll: closeAll
    };
})(window, window.jQuery);
