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

    function fmtMoney3(n) {
        if (n === null || n === undefined || n === '' || isNaN(Number(n))) {
            return '—';
        }
        return Number(n).toLocaleString('es-CL', {
            minimumFractionDigits: 3,
            maximumFractionDigits: 3
        });
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
            '.riverso-family-modal-panel .unit-panel-disabled .unit-fields{display:none !important;}' +
            '.riverso-family-modal-panel .unit-sku-search-box{border:1px solid #90caf9;border-radius:4px;padding:10px;background:#fff;margin-bottom:10px;}' +
            '.riverso-family-modal-panel .button-small{padding:3px 8px;font-size:12px;}' +
            '.riverso-family-modal-panel .large-text{font-size:14px;}' +
            '.riverso-family-list-preview{margin-top:8px;}' +
            '.riverso-family-list-preview .riverso-fp-toggle{cursor:pointer;color:#2271b1;text-decoration:none;background:none;border:none;}' +
            '.riverso-family-list-preview .riverso-fp-toggle:hover{text-decoration:underline;}' +
            '.riverso-family-list-preview .riverso-fp-members{border:1px solid #eee;border-radius:4px;background:#fafafa;margin-top:4px;}' +
            '.riverso-family-list-preview .riverso-fp-member{padding:6px 8px;border-top:1px solid #eee;font-size:12px;}' +
            '.riverso-family-list-preview .riverso-fp-member:first-child{border-top:none;}' +
            '.riverso-family-list-preview .riverso-fp-unit{display:inline-block;background:#7b1fa2;color:#fff;padding:0 5px;border-radius:3px;font-size:10px;font-weight:700;margin-right:4px;}' +
            '.riverso-family-list-preview .sku-local code{color:#1565c0;}' +
            '.riverso-family-list-preview .sku-online code{color:#2e7d32;}' +
            '.riverso-fp-inline-skus .sku-local code{color:#1565c0;}' +
            '.riverso-fp-inline-skus .sku-online code{color:#2e7d32;}' +
            '.riverso-family-modal-panel .unit-rule-suggest{border:1px solid #c3c4c7;background:#fff;max-height:180px;overflow-y:auto;margin-top:6px;}' +
            '.riverso-family-modal-panel .unit-rule-item{display:block;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid #f0f0f1;padding:7px 10px;cursor:pointer;}' +
            '.riverso-family-modal-panel .unit-rule-item:hover{background:#f0f6fc;}';
        document.head.appendChild(css);
    }

    function closeAll() {
        $(document).off('keydown.riversoFamilyEditor');
        $('.riverso-family-modal-overlay').remove();
    }

    function finalizeFamilySave($modal, $btn, fid, message) {
        if ($btn && $btn.length) {
            $btn.prop('disabled', false).text('Guardar todo');
        }
        if (typeof cfg().onChanged === 'function') {
            cfg().onChanged(fid);
        }
        if ($modal && $modal.length) {
            $modal.remove();
        }
        closeAll();
        if (message) {
            alert(message);
        }
    }

    function badges(esLocal, esOnline, esUnitario) {
        var html = '';
        if (esUnitario) {
            html += '<span title="Producto unitario de la familia" style="display:inline-block;background:#7b1fa2;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;font-weight:700;">U</span>';
        }
        if (esLocal) {
            html += '<span style="display:inline-block;background:#e3f2fd;color:#1565c0;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;">Local</span>';
        }
        if (esOnline) {
            html += '<span style="display:inline-block;background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;">Online</span>';
        }
        if (!esLocal && !esOnline && !esUnitario) {
            html += '<span style="display:inline-block;background:#f5f5f5;color:#888;padding:1px 6px;border-radius:3px;font-size:11px;">Sin clasificar</span>';
        }
        return html;
    }

    /** SKU lines: siempre Local y Online (vacío = —). */
    function skuLines(item) {
        var local = (item.sku_local != null && item.sku_local !== '')
            ? String(item.sku_local)
            : (item.canonical_sku || '');
        var online = item.sku_online != null ? String(item.sku_online) : '';
        return '<span style="color:#1565c0;">Local:</span> <code>' + esc(local || '—') + '</code>' +
            ' &nbsp; <span style="color:#2e7d32;">Online:</span> <code>' + esc(online || '—') + '</code>';
    }

    function highlightTerm(text, query) {
        var raw = text === null || text === undefined ? '' : String(text);
        var escaped = esc(raw);
        if (!query) {
            return escaped;
        }
        var q = String(query).trim();
        if (!q) {
            return escaped;
        }
        try {
            var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            return escaped.replace(re, '<mark style="background:#fff59d;padding:0 2px;">$1</mark>');
        } catch (e) {
            return escaped;
        }
    }

    /**
     * SKUs de miembros inline para la línea Tipo | Miembros | Stock.
     *
     * @param {Array} members
     * @param {{search?: string}} opts
     */
    function renderMemberSkusInline(members, opts) {
        ensureStyles();
        opts = opts || {};
        var query = (opts.search || '').trim();
        var list = members || [];
        if (!list.length) {
            return '';
        }

        var locals = list.map(function (m) {
            var v = m.sku_local || m.canonical_sku || '—';
            return query ? highlightTerm(v, query) : esc(v || '—');
        }).join(', ');

        var onlines = list.map(function (m) {
            var v = m.sku_online || '—';
            return query ? highlightTerm(v, query) : esc(v || '—');
        }).join(', ');

        return '<span class="riverso-fp-inline-skus">' +
            ' | <span class="sku-local">Local: <code>' + locals + '</code></span>' +
            ' | <span class="sku-online">Online: <code>' + onlines + '</code></span>' +
            '</span>';
    }

    /**
     * Preview compacto de miembros para la lista de familias (admin + portal).
     *
     * @param {Array} members
     * @param {{expanded?: boolean, search?: string}} opts
     */
    function renderListPreview(members, opts) {
        ensureStyles();
        opts = opts || {};
        var expanded = !!opts.expanded;
        var query = (opts.search || '').trim();
        var list = members || [];
        var count = list.length;

        if (!count) {
            return '<div class="riverso-family-list-preview" style="font-size:12px;color:#999;">Sin miembros</div>';
        }

        var toggleLabel = expanded ? '▼' : '▶';
        var bodyStyle = expanded ? '' : 'display:none;';
        var items = list.map(function (m) {
            var name = query
                ? highlightTerm(m.nombre_canonico || '-', query)
                : esc(m.nombre_canonico || '-');
            var local = m.sku_local || m.canonical_sku || '';
            var online = m.sku_online || '';
            var localHtml = query ? highlightTerm(local || '—', query) : esc(local || '—');
            var onlineHtml = query ? highlightTerm(online || '—', query) : esc(online || '—');
            var unitBadge = m.es_unitario_familia
                ? '<span class="riverso-fp-unit">U</span> '
                : '';
            return '<li class="riverso-fp-member">' +
                unitBadge + '<strong>' + name + '</strong><br>' +
                '<span class="riverso-fp-skus">' +
                '<span class="sku-local">Local: <code>' + localHtml + '</code></span> · ' +
                '<span class="sku-online">Online: <code>' + onlineHtml + '</code></span>' +
                '</span></li>';
        }).join('');

        return '<div class="riverso-family-list-preview" data-expanded="' + (expanded ? '1' : '0') + '">' +
            '<button type="button" class="riverso-fp-toggle">' +
            toggleLabel + ' ' + count + ' miembro(s)</button>' +
            '<ul class="riverso-fp-members" style="' + bodyStyle + 'list-style:none;margin:0;padding:0;">' +
            items + '</ul></div>';
    }

    function bindOverlay($overlay) {
        // Solo cerrar al clic en el fondo (no en burbuja desde hijos quitados del DOM).
        $overlay.on('click', function (ev) {
            if (ev.target === ev.currentTarget) {
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

    function renderMembersList(members, editable, packConflicts) {
        packConflicts = packConflicts || [];
        var mergeBySource = {};
        packConflicts.forEach(function (c) {
            if (c.mergeable && c.source_id) {
                mergeBySource[parseInt(c.source_id, 10)] = c;
            }
        });
        if (!members || !members.length) {
            return '<p style="color:#999;margin:8px 0;">Sin miembros</p>';
        }
        return '<ul class="riverso-family-members-list" style="list-style:none;margin:0;padding:0;">' + members.map(function (m) {
            var hasQty = m.cantidad_unidades != null && Number(m.cantidad_unidades) > 0;
            var units = hasQty ? (' · envase ' + m.cantidad_unidades) : '';
            var stockU = m.stock_unidades != null
                ? (' · ' + Number(m.stock_unidades).toLocaleString('es-CL') + ' u')
                : '';
            var missingWarn = !hasQty
                ? '<div style="margin-top:4px;color:#e65100;font-size:12px;">Sin cantidad de envase en Riverso</div>'
                : '';
            var envaseEdit = '';
            if (editable && canManage()) {
                envaseEdit =
                    '<div style="margin-top:6px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">' +
                    '<label style="font-size:12px;">Cant. envase ' +
                    '<input type="number" min="0.0001" step="1" class="riverso-family-envase-qty" ' +
                    'data-product-id="' + esc(String(m.producto_base_id || m.id)) + '" ' +
                    'value="' + esc(hasQty ? String(m.cantidad_unidades) : '') + '" ' +
                    'placeholder="ej. 100" style="width:90px;"></label>' +
                    '<button type="button" class="button button-small riverso-family-save-envase" ' +
                    'data-product-id="' + esc(String(m.producto_base_id || '')) + '">Guardar envase</button>' +
                    '</div>';
            }
            var createLocalBtn = '';
            var linkLocalBtn = '';
            if (editable && canManage() && !!m.es_online && !m.es_local) {
                var memberId = parseInt(m.producto_base_id || m.id, 10);
                var conflict = mergeBySource[memberId];
                if (conflict && conflict.target_id) {
                    linkLocalBtn =
                        '<button type="button" class="button button-small button-primary riverso-family-link-local" ' +
                        'data-source-id="' + esc(String(conflict.source_id)) + '" ' +
                        'data-target-id="' + esc(String(conflict.target_id)) + '" ' +
                        'style="margin-left:8px;">Vincular con local</button>';
                    createLocalBtn =
                        '<button type="button" class="button button-small riverso-family-create-local" ' +
                        'data-product-id="' + esc(String(m.producto_base_id || m.id || '')) + '" ' +
                        'data-nombre="' + esc(m.nombre_canonico || '') + '" ' +
                        'style="margin-left:8px;font-size:11px;color:#666;border-color:#ccc;">Crear SKU nuevo</button>';
                } else {
                    createLocalBtn =
                        '<button type="button" class="button button-small riverso-family-create-local" ' +
                        'data-product-id="' + esc(String(m.producto_base_id || m.id || '')) + '" ' +
                        'data-nombre="' + esc(m.nombre_canonico || '') + '" ' +
                        'style="margin-left:8px;border-color:#1565c0;color:#1565c0;">Crear SKU local</button>';
                }
            }
            var removeBtn = editable && canManage()
                ? '<button type="button" class="button button-small riverso-family-remove-member" data-member-id="' +
                esc(String(m.id || '')) + '" data-product-id="' + esc(String(m.producto_base_id || m.id || '')) +
                '" style="margin-left:8px;">Quitar</button>'
                : '';
            return '<li data-product-id="' + esc(String(m.producto_base_id || m.id || '')) + '" style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;padding:8px;border:1px solid #eee;border-radius:4px;margin-bottom:6px;background:#fafafa;">' +
                '<div style="flex:1;font-size:13px;">' +
                badges(!!m.es_local, !!m.es_online, !!m.es_unitario_familia) +
                '<strong>' + esc(m.nombre_canonico || '-') + '</strong><br>' +
                '<span style="font-size:12px;">' + skuLines(m) + '</span>' +
                '<span style="color:#666;">' + esc(units + stockU) + '</span>' +
                missingWarn + envaseEdit +
                '</div><div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">' +
                linkLocalBtn + createLocalBtn + removeBtn +
                '</div></li>';
        }).join('') + '</ul>';
    }

    function renderPackConflictsBanner(packConflicts, familyId) {
        if (!packConflicts || !packConflicts.length) {
            return '';
        }
        var blocks = packConflicts.map(function (c) {
            var membersTxt = (c.members || []).map(function (m) {
                var bits = [];
                if (m.sku_local) {
                    bits.push('L:' + m.sku_local);
                }
                if (m.sku_online) {
                    bits.push('O:' + m.sku_online);
                }
                if (!bits.length) {
                    bits.push('#' + m.producto_base_id);
                }
                return esc(m.nombre_canonico || '-') + ' (' + bits.join(' · ') + ')';
            }).join(' · ');
            var btn = '';
            if (c.mergeable && c.source_id && c.target_id && canManage()) {
                btn = '<button type="button" class="button button-small button-primary riverso-family-pack-merge-review" ' +
                    'data-source-id="' + esc(String(c.source_id)) + '" ' +
                    'data-target-id="' + esc(String(c.target_id)) + '" ' +
                    'data-family-id="' + esc(String(familyId || '')) + '">Revisar merge</button>';
            }
            var color = c.mergeable ? '#1565c0' : '#e65100';
            var bg = c.mergeable ? '#e3f2fd' : '#fff3e0';
            var border = c.mergeable ? '#90caf9' : '#ffb74d';
            return '<div style="margin-bottom:8px;padding:10px;background:' + bg + ';border:1px solid ' + border + ';border-radius:4px;font-size:13px;">' +
                '<strong style="color:' + color + ';">Envase ' + esc(c.qty_label || String(c.qty)) + ' u</strong> — ' +
                esc(c.message || '') + '<br><span style="font-size:12px;color:#555;">' + membersTxt + '</span>' +
                (btn ? ('<div style="margin-top:8px;">' + btn + '</div>') : '') +
                '</div>';
        }).join('');
        return '<div class="riverso-family-pack-conflicts">' + blocks + '</div>';
    }

    function updatePackConflictsBanner($modal, packConflicts, familyId) {
        packConflicts = packConflicts || [];
        $modal.data('packConflicts', packConflicts);
        var $wrap = $modal.find('.riverso-family-pack-conflicts-wrap');
        if ($wrap.length) {
            $wrap.html(renderPackConflictsBanner(packConflicts, familyId));
        }
    }

    function openPackMergeModal(merge) {
        return new Promise(function (resolve) {
            if (!merge) {
                resolve(false);
                return;
            }
            var src = merge.source || {};
            var tgt = merge.target || {};
            var woo = merge.woo || {};
            var codes = (merge.codes_to_transfer || []).map(function (c) { return c.codigo_proveedor; }).filter(Boolean);
            var barcodes = (merge.barcodes_to_transfer || []).map(function (b) { return b.codigo; }).filter(Boolean);
            var codesHTML = '';
            if (codes.length) {
                codesHTML = '<div style="margin-top:10px;"><strong>Códigos a heredar:</strong><br>' +
                    codes.map(function (c) {
                        return '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-right:4px;">' + esc(c) + '</code>';
                    }).join(' ') + '</div>';
            }
            var barcodesHTML = '';
            if (barcodes.length) {
                barcodesHTML = '<div style="margin-top:10px;"><strong>Barcodes a heredar:</strong><br>' +
                    barcodes.map(function (b) {
                        return '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;margin-right:4px;">' + esc(b) + '</code>';
                    }).join(' ') + '</div>';
            }
            var warningsHTML = '';
            (merge.warnings || []).forEach(function (w) {
                var sev = w.severity || 'info';
                var color = sev === 'error' ? '#f8d7da' : (sev === 'warning' ? '#fff3cd' : '#d1ecf1');
                var borderColor = sev === 'error' ? '#dc3545' : (sev === 'warning' ? '#ffc107' : '#17a2b8');
                warningsHTML += '<div style="background:' + color + ';border-left:4px solid ' + borderColor + ';padding:10px;margin-top:8px;border-radius:2px;font-size:13px;">' + esc(w.message) + '</div>';
            });
            var blocked = !!merge.block_merge;
            var packNote = merge.pack_qty_label
                ? '<div style="background:#e3f2fd;border:1px solid #90caf9;padding:10px;border-radius:4px;margin-bottom:12px;font-size:13px;"><strong>Envase en familia:</strong> ' + esc(merge.pack_qty_label) + ' u</div>'
                : '';
            var confirmBtn = blocked
                ? '<button type="button" class="button" disabled style="opacity:0.5;cursor:not-allowed;">Merge bloqueado</button>'
                : '<button type="button" id="riverso-family-merge-confirm" class="button button-primary" style="cursor:pointer;">Confirmar merge</button>';
            var html =
                '<div id="riverso-family-merge-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100010;display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.3);padding:24px;max-width:620px;width:92%;max-height:85vh;overflow-y:auto;">' +
                '<h2 style="margin:0 0 16px 0;color:#1d2327;">' + (blocked ? 'Merge bloqueado' : 'Revisar merge de productos') + '</h2>' +
                packNote +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">' +
                '<div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">' +
                '<h4 style="margin:0 0 8px 0;color:#d32f2f;">Origen (online, se elimina)</h4>' +
                '<div style="font-size:12px;line-height:1.6;">' +
                '<div><strong>ID:</strong> #' + esc(String(merge.source_id || '?')) + '</div>' +
                '<div><strong>SKU Local:</strong> ' + (src.canonical_sku || '<em style="color:#999;">sin SKU</em>') + '</div>' +
                '<div><strong>Nombre:</strong> ' + esc(src.nombre_canonico || 'Sin nombre') + '</div>' +
                '</div></div>' +
                '<div style="border:1px solid #ddd;padding:12px;border-radius:6px;background:#f9f9f9;">' +
                '<h4 style="margin:0 0 8px 0;color:#1976d2;">Destino (local, receptor)</h4>' +
                '<div style="font-size:12px;line-height:1.6;">' +
                '<div><strong>ID:</strong> #' + esc(String(merge.target_id || '?')) + '</div>' +
                '<div><strong>SKU Local:</strong> ' + (tgt.canonical_sku || '<em style="color:#999;">sin SKU</em>') + '</div>' +
                '<div><strong>Nombre:</strong> ' + esc(tgt.nombre_canonico || 'Sin nombre') + '</div>' +
                '</div></div></div>' +
                '<div style="background:#e8f5e9;border:1px solid #4caf50;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;">' +
                '<strong style="color:#2e7d32;">SKU Online:</strong> <code>' + esc(woo.sku || 'N/A') + '</code></div>' +
                codesHTML + barcodesHTML + warningsHTML +
                '<div style="margin-top:16px;padding-top:12px;border-top:1px solid #ddd;display:flex;gap:8px;justify-content:flex-end;">' +
                '<button type="button" id="riverso-family-merge-cancel" class="button">' + (blocked ? 'Cerrar' : 'Cancelar') + '</button>' +
                confirmBtn +
                '</div></div></div>';
            $('body').append(html);
            $('#riverso-family-merge-cancel').on('click', function () {
                $('#riverso-family-merge-overlay').remove();
                $(document).off('keydown.riversoFamilyMerge');
                resolve(false);
            });
            $('#riverso-family-merge-confirm').on('click', function () {
                $('#riverso-family-merge-overlay').remove();
                $(document).off('keydown.riversoFamilyMerge');
                resolve(true);
            });
            $(document).on('keydown.riversoFamilyMerge', function (e) {
                if (e.key === 'Escape') {
                    $('#riverso-family-merge-overlay').remove();
                    $(document).off('keydown.riversoFamilyMerge');
                    resolve(false);
                }
            });
        });
    }

    function runPackMergeFlow($modal, familyId, sourceId, targetId) {
        post('riverso_families_pack_merge_preview', {
            grupo_id: familyId,
            source_id: sourceId,
            target_id: targetId
        }).done(function (resp) {
            var merge = resp.data && resp.data.merge;
            if (!resp.success) {
                if (merge) {
                    openPackMergeModal(merge);
                    return;
                }
                alert((resp.data && resp.data.message) || 'No se pudo obtener preview del merge');
                return;
            }
            openPackMergeModal(merge).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                post('riverso_families_pack_merge_confirm', {
                    grupo_id: familyId,
                    source_id: sourceId,
                    target_id: targetId
                }).done(function (r2) {
                    if (!r2.success) {
                        alert((r2.data && r2.data.message) || 'Merge falló');
                        return;
                    }
                    alert(r2.data.message || 'Merge completado');
                    refreshEditMembers($modal, familyId);
                    loadUnitPanelIntoModal($modal, familyId);
                }).fail(function () {
                    alert('Error de red al confirmar merge');
                });
            });
        }).fail(function () {
            alert('Error de red al obtener preview del merge');
        });
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

    function isUnitEnabled(unit) {
        if (!unit) {
            return false;
        }
        return parseInt(unit.es_producto_unitario, 10) === 1;
    }

    function applyUnitPanelState($panel, enabled) {
        var $fields = $panel.find('.unit-fields');
        var $selected = $panel.find('.unit-sku-selected-always');
        $panel.toggleClass('unit-panel-disabled', !enabled);
        if (enabled) {
            $fields.stop(true, true).show();
            $fields.css({ opacity: 1, pointerEvents: 'auto' });
            $fields.find('input, select, button').prop('disabled', false);
            $panel.find('.unit-toggle-enabled').prop('disabled', false);
        } else {
            $fields.stop(true, true).hide();
            $fields.css({ opacity: 0.45, pointerEvents: 'none' });
            $fields.find('input, select, button').prop('disabled', true);
            $panel.find('.unit-toggle-enabled').prop('disabled', false);
            // Chip de SKU vinculado visible aunque el panel esté colapsado
            if ($selected.length && $panel.find('.unit-convert-id').val()) {
                $selected.show();
            }
        }
    }

    function actionLabel(action) {
        if (action === 'inherit') {
            return '<span style="color:#2e7d32;font-weight:600;">Heredar</span>';
        }
        if (action === 'keep_unit') {
            return '<span style="color:#1565c0;font-weight:600;">Queda en unitario</span>';
        }
        return '<span style="color:#e65100;font-weight:600;">Sin destino</span>';
    }

    function renderLinkPreview(preview) {
        if (!preview) {
            return '';
        }
        var rows = [];
        (preview.barcodes || []).forEach(function (b) {
            rows.push({
                tipo: 'Barcode',
                codigo: b.codigo,
                cantidad: b.cantidad,
                action: b.action,
                dest: b.suggested_label || '—',
                tasks: (b.task_ids || []).length
            });
        });
        (preview.supplier_codes || []).forEach(function (c) {
            rows.push({
                tipo: 'Código prov.',
                codigo: c.codigo,
                cantidad: c.cantidad,
                action: c.action,
                dest: c.suggested_label || '—',
                tasks: (c.task_ids || []).length
            });
        });
        var summary = preview.summary || {};
        var splitNote = preview.split_would_create_box
            ? '<p style="margin:6px 0;font-size:12px;color:#e65100;">Esta familia aún no tiene miembros-caja: al guardar se podrá crear un producto-caja (comportamiento clásico).</p>'
            : '<p style="margin:6px 0;font-size:12px;color:#2e7d32;">La familia ya tiene cajas: no se creará un producto-caja nuevo; se heredarán códigos a los miembros coincidentes.</p>';
        var table = !rows.length
            ? '<p style="margin:6px 0;font-size:12px;color:#666;">Sin códigos ni barcodes en el SKU seleccionado.</p>'
            : ('<table class="widefat striped" style="font-size:12px;margin-top:6px;"><thead><tr>' +
                '<th>Tipo</th><th>Código</th><th>Qty</th><th>Acción</th><th>Destino</th><th>Tareas</th></tr></thead><tbody>' +
                rows.map(function (r) {
                    return '<tr><td>' + esc(r.tipo) + '</td><td><code>' + esc(r.codigo || '—') + '</code></td>' +
                        '<td>' + esc(String(r.cantidad != null ? r.cantidad : '—')) + '</td>' +
                        '<td>' + actionLabel(r.action) + '</td><td>' + esc(r.dest) + '</td>' +
                        '<td>' + (r.tasks ? String(r.tasks) : '—') + '</td></tr>';
                }).join('') + '</tbody></table>');
        return '<div class="unit-link-preview" style="margin-top:10px;padding:10px;background:#fff;border:1px solid #90caf9;border-radius:4px;">' +
            '<strong>Preview al vincular</strong>' +
            '<div style="font-size:12px;color:#555;margin-top:4px;">Heredar: ' + (summary.inherit || 0) +
            ' · Quedan: ' + (summary.keep_unit || 0) +
            ' · Sin destino: ' + (summary.unresolved || 0) + '</div>' +
            splitNote + table +
            '<label style="display:block;margin-top:8px;font-size:12px;">' +
            '<input type="checkbox" class="unit-link-confirm"> Confirmo los movimientos anteriores al guardar</label>' +
            '</div>';
    }

    function loadLinkPreview($modal, familyId, productoBaseId) {
        var $wrap = $modal.find('.unit-link-preview-wrap');
        if (!$wrap.length) {
            return;
        }
        if (!productoBaseId) {
            $wrap.empty();
            return;
        }
        $wrap.html('<p style="font-size:12px;color:#999;margin:8px 0;">Cargando preview…</p>');
        post('riverso_families_unit_link_preview', {
            grupo_id: familyId,
            producto_base_id: productoBaseId
        }).done(function (r) {
            if (!r.success) {
                $wrap.html('<p style="color:#c62828;font-size:12px;">' + esc((r.data && r.data.message) || 'Error preview') + '</p>');
                return;
            }
            $wrap.html(renderLinkPreview(r.data.preview));
            $modal.data('unitLinkPreview', r.data.preview);
        }).fail(function () {
            $wrap.html('<p style="color:#c62828;font-size:12px;">Error de red en preview</p>');
        });
    }

    function renderUnitSkuResults(items, familyId, currentUnitId) {
        if (!items || !items.length) {
            return '<p style="color:#999;font-size:12px;margin:4px 0;">Sin resultados</p>';
        }
        return '<ul class="unit-sku-results" style="list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;background:#fff;">' +
            items.map(function (it) {
                var badge = '';
                if (it.es_unidad_minima) {
                    badge = '<span style="display:inline-block;background:#fff3e0;color:#e65100;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;">U</span>';
                }
                var warn = it.aviso
                    ? '<div style="font-size:11px;color:#e65100;margin-top:2px;">' + esc(it.aviso) + '</div>'
                    : '';
                var selectable = it.can_select !== false;
                var cls = selectable ? 'unit-sku-pick' : 'unit-sku-pick-disabled';
                var cur = currentUnitId && String(currentUnitId) === String(it.id)
                    ? ' style="background:#e8f5e9;"'
                    : '';
                return '<li class="' + cls + '" data-id="' + esc(String(it.id)) + '" data-sku="' + esc(it.canonical_sku || it.sku_local || '') + '" data-nombre="' + esc(it.nombre_canonico || '') + '" data-can-select="' + (selectable ? '1' : '0') + '" data-aviso="' + esc(it.aviso || '') + '"' + cur + '>' +
                    '<div style="padding:8px;cursor:' + (selectable ? 'pointer' : 'not-allowed') + ';opacity:' + (selectable ? '1' : '0.65') + ';">' +
                    '<strong>' + esc(it.canonical_sku || it.sku_local || '-') + '</strong>' + badge +
                    ' — ' + esc(it.nombre_canonico || '-') + warn +
                    '</div></li>';
            }).join('') +
            '</ul>';
    }

    function ruleLabel(rule) {
        if (!rule) {
            return '';
        }
        return (rule.codigo || '') + ' v' + (rule.version || '') + ' · ' + (rule.nombre || '');
    }

    function renderUnitRulePicker(unit) {
        var current = unit && unit.rule_assignment;
        var currentTxt = current
            ? ('Actual: <strong>' + esc(ruleLabel(current)) + '</strong> <span style="color:#666;">(' + esc(current.estado || '') + ')</span>')
            : 'Sin regla asignada a la familia.';
        var preId = current && current.id ? String(current.id) : '';
        var preLabel = current ? ruleLabel(current) : '';
        var checkAssign = false;
        var warnRule = (unit && unit.falta_regla_precio)
            ? '<div class="unit-rule-warning" style="margin:0 0 8px;padding:8px;background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;color:#7a5b00;font-size:12px;"><strong>Falta regla de precio.</strong> Se crea una tarea para asignarla. Búscala en Tareas → categoría Precios, o asígnala aquí.</div>'
            : '';
        return '<div class="unit-rule-box" style="margin-top:10px;padding:8px;background:#fff;border:1px solid #90caf9;border-radius:4px;">' +
            warnRule +
            '<p style="margin:0 0 6px;font-size:12px;">' + currentTxt + '</p>' +
            '<label style="display:block;margin-bottom:6px;font-size:12px;">Buscar regla de precio<br>' +
            '<input type="search" class="unit-rule-q regular-text" placeholder="Código o nombre (ej. R-1)…" style="width:100%;max-width:100%;box-sizing:border-box;"></label>' +
            '<div class="unit-rule-suggest" style="display:none;"></div>' +
            '<input type="hidden" class="unit-rule-id" value="' + esc(preId) + '">' +
            '<p class="unit-rule-selected" style="margin:6px 0;font-size:12px;">' +
            (preLabel ? ('Seleccionada: <strong>' + esc(preLabel) + '</strong>') : 'Ninguna seleccionada') +
            '</p>' +
            '<label style="font-size:13px;"><input type="checkbox" class="unit-confirm-r1"' + (checkAssign ? ' checked' : '') + '> Asignar la regla seleccionada a la familia al guardar</label>' +
            '</div>';
    }

    function renderUnitPanel(unit, familyId, members) {
        var enabled = isUnitEnabled(unit);
        var u = unit && unit.unit;
        var legacy = unit && unit.legacy_ref;
        var legacyHtml = '';
        if (legacy) {
            var costoTxt = legacy.costo_sin_dato
                ? '<span style="color:#999;">sin dato (referencia)</span>'
                : ('$' + fmtMoney3(legacy.costo_neto));
            var precioTxt = legacy.precio_total != null || legacy.precio_neto != null
                ? ('$' + fmtMoney3(legacy.precio_total != null ? legacy.precio_total : legacy.precio_neto))
                : '—';
            legacyHtml = '<div style="background:#f5f5f5;padding:8px;border-radius:4px;font-size:12px;margin-top:8px;">' +
                '<strong>Legacy (sugerencia):</strong> costo ' + costoTxt +
                ' · precio ' + precioTxt + '</div>';
        }
        var selectedSku = u && u.canonical_sku ? u.canonical_sku : '';
        var selectedId = u && u.id ? u.id : '';
        var disabledCls = enabled ? '' : ' unit-panel-disabled';
        var chipHtml =
            '<div class="unit-sku-selected unit-sku-selected-always" style="margin-top:8px;padding:8px;background:#e8f5e9;border:1px solid #4caf50;border-radius:4px;' + (selectedId ? '' : 'display:none;') + '">' +
            '<strong>Seleccionado:</strong> <code class="unit-sku-label">' + esc(selectedSku || '—') + '</code> ' +
            '<span class="unit-nombre-label">' + esc(u && u.nombre_canonico ? u.nombre_canonico : '') + '</span> ' +
            '<span class="unit-badge-u" style="display:inline-block;background:#7b1fa2;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700;margin-left:4px;">U</span> ' +
            (canManage() ? '<button type="button" class="button button-small unit-sku-clear" style="margin-left:6px;">Quitar</button>' : '') +
            '</div>';
        return '<div class="riverso-unit-panel' + disabledCls + '" style="margin:12px 0;padding:12px;border:1px solid #90caf9;border-radius:4px;background:#e3f2fd;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">' +
            '<strong>Producto unitario</strong>' +
            '<label style="font-size:13px;"><input type="checkbox" class="unit-toggle-enabled"' + (enabled ? ' checked' : '') + (canManage() ? '' : ' disabled') + '> Activado</label></div>' +
            chipHtml +
            '<input type="hidden" class="unit-convert-id" value="' + esc(String(selectedId)) + '">' +
            '<div class="unit-fields" style="margin-top:10px;font-size:13px;' + (enabled ? '' : 'display:none;') + '">' +
            '<div class="unit-sku-search-box">' +
            '<strong style="display:block;margin-bottom:6px;">Buscar SKU local para vincular</strong>' +
            '<input type="text" class="unit-sku-search regular-text" placeholder="Escribe SKU, nombre o código (mín. 2 caracteres)…" style="width:100%;max-width:100%;box-sizing:border-box;">' +
            '<div class="unit-sku-results-wrap" style="margin-top:8px;"></div>' +
            '<div class="unit-sku-warning" style="display:none;margin-top:8px;padding:8px;background:#fff3e0;border:1px solid #ffb74d;border-radius:4px;color:#e65100;font-size:12px;"></div>' +
            '<div class="unit-link-preview-wrap"></div>' +
            '</div>' +
            '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #90caf9;">' +
            '<p style="margin:0 0 8px;font-size:12px;color:#666;">O crear producto unitario nuevo:</p>' +
            '<label style="display:block;margin-bottom:6px;">SKU nuevo<br>' +
            '<input type="text" class="unit-sku" value="' + esc(selectedSku) + '" style="width:140px;"> ' +
            '<button type="button" class="button button-small unit-next-sku">Generar SKU</button></label>' +
            '<label style="display:block;margin-bottom:6px;">Nombre<br>' +
            '<input type="text" class="unit-nombre large-text" value="' + esc(u && u.nombre_canonico ? u.nombre_canonico : '') + '" style="width:100%;box-sizing:border-box;"></label>' +
            '</div>' +
            '<p style="margin:6px 0;">Coste calculado: <strong class="unit-coste">' +
            (unit && unit.coste_calculado != null ? ('$' + Number(unit.coste_calculado).toLocaleString('es-CL')) : '—') + '</strong></p>' +
            '<label style="display:block;margin-bottom:6px;">Precio asignado (P)<br>' +
            '<input type="number" step="0.01" class="unit-precio" value="' +
            (unit && unit.precio && unit.precio.p_asignado != null ? unit.precio.p_asignado : '') + '" style="width:120px;"></label>' +
            '<p style="margin:6px 0;">Stock suelto: <strong class="unit-stock">' +
            (unit && unit.stock != null ? Number(unit.stock).toLocaleString('es-CL') : '—') + '</strong>' +
            (unit && unit.ubicacion ? (' · Lugar: ' + esc(unit.ubicacion.codigo || unit.ubicacion.nombre || '')) : '') + '</p>' +
            legacyHtml +
            renderUnitRulePicker(unit) +
            '<button type="button" class="button button-primary unit-save" style="margin-top:8px;">Guardar producto unitario</button> ' +
            '<button type="button" class="button unit-preview-prices">Previsualizar precios</button>' +
            '<div class="unit-preview-table" style="margin-top:10px;overflow-x:auto;"></div>' +
            '</div></div>';
    }

    function buildUnitSavePayload($modal, familyId) {
        var convertId = $modal.find('.unit-convert-id').val();
        var typedSku = ($modal.find('.unit-sku').val() || '').trim();
        var payload = {
            grupo_id: familyId,
            nombre: $modal.find('.unit-nombre').val(),
            canonical_sku: typedSku,
            p_asignado: $modal.find('.unit-precio').val(),
            es_producto_unitario: $modal.find('.unit-toggle-enabled').is(':checked') ? 1 : 0,
            confirm_r1: $modal.find('.unit-confirm-r1').is(':checked') ? 1 : 0,
            rule_id: $modal.find('.unit-rule-id').val() || 0,
            confirm_link_preview: 1
        };
        if (convertId) {
            payload.convert_producto_base_id = convertId;
        }
        return payload;
    }

    function unitSaveNeedsConfirm($modal) {
        var convertId = $modal.find('.unit-convert-id').val();
        var typedSku = ($modal.find('.unit-sku').val() || '').trim();
        var preview = $modal.data('unitLinkPreview');
        var needsConfirm = !!(convertId || typedSku) && preview && (
            (preview.barcodes && preview.barcodes.length) ||
            (preview.supplier_codes && preview.supplier_codes.length) ||
            !preview.split_would_create_box
        );
        if (needsConfirm && !$modal.find('.unit-link-confirm').is(':checked')) {
            return 'Revisa el preview de códigos/barcodes/tareas y marca la casilla de confirmación antes de guardar el producto unitario.';
        }
        if ($modal.find('.unit-confirm-r1').is(':checked')) {
            // caller may confirm interactively
        }
        return null;
    }

    /**
     * Persiste producto unitario del panel. Resuelve con {ok, message, unit} o rechaza.
     */
    function saveUnitFromPanel($modal, familyId) {
        var d = $.Deferred();
        var convertId = $modal.find('.unit-convert-id').val();
        var typedSku = ($modal.find('.unit-sku').val() || '').trim();
        var enabled = $modal.find('.unit-toggle-enabled').is(':checked');

        // Nada que vincular
        if (!convertId && !typedSku && !enabled) {
            d.resolve({ ok: true, skipped: true, message: null });
            return d.promise();
        }
        // Activado pero sin SKU
        if (enabled && !convertId && !typedSku) {
            d.reject({ message: 'Producto unitario activado: elige un SKU local o escribe uno nuevo antes de guardar.' });
            return d.promise();
        }
        // Hay SKU seleccionado/escrito → forzar activado al guardar
        if ((convertId || typedSku) && !enabled) {
            $modal.find('.unit-toggle-enabled').prop('checked', true);
            applyUnitPanelState($modal.find('.riverso-unit-panel'), true);
        }

        var blockMsg = unitSaveNeedsConfirm($modal);
        if (blockMsg) {
            d.reject({ message: blockMsg });
            return d.promise();
        }
        if ($modal.find('.unit-confirm-r1').is(':checked')) {
            var ruleName = $modal.find('.unit-rule-selected strong').text() || 'R-1';
            if (!confirm('¿Confirmas asignar ' + ruleName + ' a esta familia?')) {
                d.reject({ message: 'Guardado cancelado (asignación de regla).' });
                return d.promise();
            }
        }

        post('riverso_families_unit_configure', buildUnitSavePayload($modal, familyId))
            .done(function (r) {
                if (!r.success) {
                    d.reject({ message: (r.data && r.data.message) || 'Error al guardar producto unitario' });
                    return;
                }
                var unit = r.data && r.data.unit;
                var linked = unit && (unit.unit_producto_base_id || (unit.unit && unit.unit.id));
                if (!linked) {
                    d.reject({
                        message: 'El servidor respondió OK pero no quedó producto unitario vinculado. Revisa envases (100/500) e intenta de nuevo.',
                        unit: unit
                    });
                    return;
                }
                var sku = unit.unit && unit.unit.canonical_sku ? unit.unit.canonical_sku : linked;
                d.resolve({
                    ok: true,
                    skipped: false,
                    message: 'Producto unitario vinculado: SKU ' + sku,
                    unit: unit
                });
            })
            .fail(function (xhr) {
                var msg = 'Error de red al guardar producto unitario';
                try {
                    var j = xhr.responseJSON;
                    if (j && j.data && j.data.message) {
                        msg = j.data.message;
                    }
                } catch (e) {}
                d.reject({ message: msg });
            });
        return d.promise();
    }

    function currentFamilyId($modal) {
        return parseInt($modal.data('familyId') || 0, 10) || 0;
    }

    function collectFamilyProductIds($modal) {
        var ids = [];
        $modal.find('.riverso-family-members-wrap [data-product-id]').each(function () {
            var id = parseInt($(this).attr('data-product-id') || $(this).data('product-id') || 0, 10);
            if (id) {
                ids.push(id);
            }
        });
        var unitId = parseInt($modal.find('.unit-convert-id').val() || 0, 10);
        if (unitId) {
            ids.push(unitId);
        }
        return Array.from(new Set(ids));
    }

    function getPendingMembers($modal) {
        return $modal.data('pendingMembers') || [];
    }

    function setPendingMembers($modal, list) {
        $modal.data('pendingMembers', list || []);
    }

    function renderPendingMembersList($modal) {
        var list = getPendingMembers($modal);
        var members = list.map(function (m) {
            return {
                id: 0,
                producto_base_id: m.producto_base_id,
                nombre_canonico: m.nombre_canonico,
                canonical_sku: m.sku_local || m.canonical_sku || '',
                sku_local: m.sku_local || '',
                sku_online: m.sku_online || '',
                es_local: !!m.es_local,
                es_online: !!m.es_online,
                cantidad_unidades: m.cantidad_unidades != null ? m.cantidad_unidades : null,
                es_unitario_familia: false
            };
        });
        $modal.find('.riverso-family-members-wrap').html(renderMembersList(members, true));
        $modal.find('.riverso-family-member-count').text(String(members.length));
        refreshNameSuggestions($modal);
    }

    function addPendingMember($modal, item) {
        var pid = parseInt(item.producto_base_id || item.id || 0, 10);
        if (!pid) {
            return false;
        }
        var list = getPendingMembers($modal);
        if (list.some(function (m) { return parseInt(m.producto_base_id, 10) === pid; })) {
            return false;
        }
        list.push({
            producto_base_id: pid,
            nombre_canonico: item.nombre_canonico || item.nombre || '',
            sku_local: item.sku_local || item.canonical_sku || '',
            sku_online: item.sku_online || '',
            es_local: !!item.es_local,
            es_online: !!item.es_online,
            cantidad_unidades: item.cantidad_unidades != null && item.cantidad_unidades !== ''
                ? Number(item.cantidad_unidades) : null
        });
        setPendingMembers($modal, list);
        renderPendingMembersList($modal);
        return true;
    }

    function removePendingMember($modal, productId) {
        var pid = parseInt(productId, 10);
        var list = getPendingMembers($modal).filter(function (m) {
            return parseInt(m.producto_base_id, 10) !== pid;
        });
        setPendingMembers($modal, list);
        renderPendingMembersList($modal);
    }

    function updatePendingEnvase($modal, productId, qty) {
        var pid = parseInt(productId, 10);
        var list = getPendingMembers($modal).map(function (m) {
            if (parseInt(m.producto_base_id, 10) === pid) {
                return Object.assign({}, m, { cantidad_unidades: qty });
            }
            return m;
        });
        setPendingMembers($modal, list);
        renderPendingMembersList($modal);
    }

    /**
     * Tras crear la familia, persiste miembros en cola (y envases si hay qty).
     * @returns {jQuery.Promise}
     */
    function flushPendingMembers($modal, familyId) {
        var d = $.Deferred();
        var list = getPendingMembers($modal).slice();
        if (!list.length) {
            d.resolve({ added: 0 });
            return d.promise();
        }

        var i = 0;
        var errors = [];

        function next() {
            if (i >= list.length) {
                setPendingMembers($modal, []);
                if (errors.length) {
                    d.reject({ message: errors.join('\n'), added: list.length - errors.length });
                } else {
                    d.resolve({ added: list.length });
                }
                return;
            }
            var m = list[i++];
            post('riverso_families_add_member', {
                grupo_id: familyId,
                producto_base_id: m.producto_base_id,
                prioridad: 100,
                es_preferido: 0
            }).done(function (resp) {
                if (!resp.success) {
                    errors.push((m.nombre_canonico || m.producto_base_id) + ': ' +
                        ((resp.data && resp.data.message) || 'No se pudo agregar'));
                    next();
                    return;
                }
                var qty = m.cantidad_unidades != null ? Number(m.cantidad_unidades) : 0;
                if (!(qty > 0)) {
                    next();
                    return;
                }
                post('riverso_families_set_member_envase', {
                    grupo_id: familyId,
                    producto_base_id: m.producto_base_id,
                    cantidad_unidades: qty
                }).always(function () {
                    next();
                });
            }).fail(function () {
                errors.push((m.nombre_canonico || m.producto_base_id) + ': error de red');
                next();
            });
        }

        next();
        return d.promise();
    }

    function renderNameSuggestions(items) {
        if (!items || !items.length) {
            return '';
        }
        return '<div style="font-size:12px;color:#666;margin-bottom:4px;">Sugerencias (clic para usar):</div>' +
            '<div style="display:flex;flex-wrap:wrap;gap:6px;">' +
            items.map(function (it) {
                return '<button type="button" class="button button-small family-name-pick" data-name="' +
                    esc(it.label || '') + '" title="' + esc(it.source || '') + '" style="max-width:100%;white-space:normal;text-align:left;height:auto;line-height:1.3;padding:4px 8px;">' +
                    esc(it.label || '') + '</button>';
            }).join('') +
            '</div>';
    }

    function refreshNameSuggestions($modal) {
        var $wrap = $modal.find('.family-name-suggestions');
        if (!$wrap.length) {
            return;
        }
        var ids = collectFamilyProductIds($modal);
        if (!ids.length) {
            $wrap.empty();
            return;
        }
        $wrap.html('<span style="font-size:12px;color:#999;">Sugerencias…</span>');
        post('riverso_families_suggest_names', { producto_base_ids: ids }).done(function (r) {
            if (!r.success) {
                $wrap.empty();
                return;
            }
            $wrap.html(renderNameSuggestions(r.data.suggestions || []));
        }).fail(function () {
            $wrap.empty();
        });
    }

    /** Preview de slug (solo UI; el servidor genera el definitivo). */
    function slugifyCodigoPreview(nombre) {
        var s = String(nombre || '');
        try {
            if (typeof s.normalize === 'function') {
                s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
        } catch (e) {}
        s = s.toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toUpperCase()
            .slice(0, 80);
        return s;
    }

    /**
     * Crea la familia si el modal aún no tiene grupo_id.
     * @returns {jQuery.Promise<number>}
     */
    function ensureFamilyCreated($modal) {
        var d = $.Deferred();
        var id = currentFamilyId($modal);
        if (id > 0) {
            d.resolve(id);
            return d.promise();
        }
        var nombre = ($modal.find('.family-edit-nombre').val() || '').trim();
        var codigo = ($modal.find('.family-edit-codigo').val() || '').trim();
        var tipo = $modal.find('.family-edit-tipo').val() || 'exacta';
        if (!nombre) {
            d.reject({ message: 'Indica el nombre de la familia antes de Guardar todo' });
            return d.promise();
        }
        post('riverso_families_create', {
            nombre: nombre,
            codigo_grupo: codigo,
            tipo_sustitucion: tipo
        }).done(function (resp) {
            if (!resp.success || !resp.data || !resp.data.family) {
                d.reject({ message: (resp.data && resp.data.message) || 'No se pudo crear la familia' });
                return;
            }
            var fam = resp.data.family;
            var newId = parseInt(fam.id, 10);
            $modal.data('familyId', newId);
            $modal.data('isCreate', false);
            $modal.find('.riverso-family-editor-title').text('Editar Familia');
            var $codigo = $modal.find('.family-edit-codigo');
            $codigo.val(fam.codigo_grupo || '').prop('disabled', true);
            $modal.data('codigoTouched', true);
            $modal.find('.family-codigo-hint').text('Código asignado').css('color', '#666');
            $modal.find('.riverso-family-delete').show().prop('disabled', false);
            if (typeof $modal.data('onCreated') === 'function') {
                $modal.data('onCreated')(fam);
            }
            if (typeof cfg().onChanged === 'function') {
                cfg().onChanged(newId);
            }
            d.resolve(newId);
        }).fail(function () {
            d.reject({ message: 'Error de red al crear la familia' });
        });
        return d.promise();
    }

    function bindUnitPanel($modal) {
        if (!canManage()) {
            return;
        }
        var $panel = $modal.find('.riverso-unit-panel');
        applyUnitPanelState($panel, isUnitEnabled({ es_producto_unitario: $panel.find('.unit-toggle-enabled').is(':checked') ? 1 : 0 }));

        function familyId() {
            return currentFamilyId($modal);
        }

        function reloadUnit() {
            var fid = familyId();
            if (!fid) {
                return;
            }
            post('riverso_families_unit_get', { grupo_id: fid }).done(function (r) {
                if (!r.success) {
                    return;
                }
                $modal.find('.riverso-unit-panel').replaceWith(renderUnitPanel(r.data.unit, fid, []));
                bindUnitPanel($modal);
                var uid = r.data.unit && r.data.unit.unit && r.data.unit.unit.id;
                if (uid) {
                    loadLinkPreview($modal, fid, uid);
                }
            });
            refreshEditMembers($modal, fid);
        }

            function setUnitSelection($modalInner, item) {
            $modalInner.find('.unit-convert-id').val(item.id || '');
            $modalInner.find('.unit-sku').val(item.sku || '');
            $modalInner.find('.unit-nombre').val(item.nombre || '');
            $modalInner.find('.unit-sku-label').text(item.sku || '—');
            $modalInner.find('.unit-nombre-label').text(item.nombre || '');
            $modalInner.find('.unit-sku-selected').show();
            if (item.warning) {
                $modalInner.find('.unit-sku-warning').text(item.warning).show();
            } else {
                $modalInner.find('.unit-sku-warning').hide().empty();
            }
            var fid = familyId();
            if (item.id) {
                if (fid) {
                    loadLinkPreview($modalInner, fid, item.id);
                } else {
                    $modalInner.find('.unit-link-preview-wrap').html(
                        '<p style="margin:8px 0;font-size:12px;color:#666;">El preview de herencia se calculará al guardar la familia.</p>'
                    );
                }
                refreshNameSuggestions($modalInner);
            }
        }

        $modal.find('.unit-toggle-enabled').off('change.unit').on('change.unit', function () {
            var enabled = $(this).is(':checked');
            applyUnitPanelState($panel, enabled);
            var fid = familyId();
            // En crear: solo abre/cierra el panel localmente; el nombre se exige al Guardar todo.
            if (!fid) {
                return;
            }
            post('riverso_families_unit_toggle', { grupo_id: fid, enabled: enabled ? 1 : 0 })
                .done(function (r) {
                    if (!r.success) {
                        alert((r.data && r.data.message) || 'No se pudo cambiar producto unitario');
                        $modal.find('.unit-toggle-enabled').prop('checked', !enabled);
                        applyUnitPanelState($panel, !enabled);
                        return;
                    }
                    if (r.data && r.data.unit) {
                        $modal.find('.riverso-unit-panel').replaceWith(renderUnitPanel(r.data.unit, fid, []));
                        bindUnitPanel($modal);
                    }
                });
        });

        $modal.find('.unit-next-sku').off('click.unit').on('click.unit', function () {
            post('riverso_products_next_sku', {}).done(function (r) {
                if (r.success && r.data && r.data.next_sku) {
                    $modal.find('.unit-sku').val(r.data.next_sku);
                    $modal.find('.unit-convert-id').val('');
                    $modal.find('.unit-sku-selected').hide();
                    $modal.find('.unit-sku-warning').hide().empty();
                }
            });
        });

        $modal.find('.unit-sku-clear').off('click.unit').on('click.unit', function () {
            $modal.find('.unit-convert-id').val('');
            $modal.find('.unit-sku-selected').hide();
            $modal.find('.unit-sku-warning').hide().empty();
            $modal.find('.unit-link-preview-wrap').empty();
            $modal.removeData('unitLinkPreview');
            refreshNameSuggestions($modal);
        });

        var unitSearchTimer = null;
        $modal.find('.unit-sku-search').off('input.unit').on('input.unit', function () {
            var q = $(this).val().trim();
            var $wrap = $modal.find('.unit-sku-results-wrap');
            clearTimeout(unitSearchTimer);
            if (q.length < 2 && !/^\d+$/.test(q)) {
                $wrap.html('<p style="color:#999;font-size:12px;margin:4px 0;">Escribe al menos 2 caracteres</p>');
                return;
            }
            $wrap.html('<p style="color:#999;font-size:12px;margin:4px 0;">Buscando…</p>');
            unitSearchTimer = setTimeout(function () {
                var fid = familyId() || 0;
                post('riverso_families_search_candidates', {
                    q: q,
                    grupo_id: fid,
                    for_unit: 1,
                    limit: 15
                }).done(function (resp) {
                    if (!resp.success) {
                        $wrap.html('<p style="color:#c62828;font-size:12px;">' + esc((resp.data && resp.data.message) || 'Error') + '</p>');
                        return;
                    }
                    var currentUnitId = $modal.find('.unit-convert-id').val();
                    $wrap.html(renderUnitSkuResults(resp.data.items || [], fid, currentUnitId));
                }).fail(function () {
                    $wrap.html('<p style="color:#c62828;font-size:12px;">Error de red</p>');
                });
            }, 300);
        });

        $modal.off('click.unitPick').on('click.unitPick', '.unit-sku-pick', function () {
            if (String($(this).attr('data-can-select')) !== '1') {
                return;
            }
            var id = $(this).data('id');
            var sku = $(this).data('sku');
            var nombre = $(this).data('nombre');
            var warning = $(this).attr('data-aviso') || '';
            setUnitSelection($modal, { id: id, sku: sku, nombre: nombre, warning: warning });
        });

        $modal.find('.unit-sku').off('change.unit blur.unit').on('change.unit blur.unit', function () {
            var sku = ($(this).val() || '').trim();
            if (!sku || $modal.find('.unit-convert-id').val()) {
                return;
            }
            var fid = familyId() || 0;
            post('riverso_families_search_candidates', {
                q: sku,
                grupo_id: fid,
                for_unit: 1,
                limit: 5
            }).done(function (resp) {
                if (!resp.success || !resp.data || !resp.data.items) {
                    return;
                }
                var exact = (resp.data.items || []).find(function (it) {
                    return it.can_select !== false && String(it.canonical_sku || it.sku_local || '') === sku;
                });
                if (exact) {
                    setUnitSelection($modal, {
                        id: exact.id,
                        sku: exact.canonical_sku || exact.sku_local || sku,
                        nombre: exact.nombre_canonico || '',
                        warning: exact.aviso || ''
                    });
                }
            });
        });

        var ruleSearchTimer = null;
        var cachedRules = null;

        function loadRules(cb) {
            if (cachedRules) {
                cb(cachedRules);
                return;
            }
            post('riverso_price_rules_list', {}).done(function (r) {
                cachedRules = (r.success && r.data && r.data.rules) ? r.data.rules : [];
                cb(cachedRules);
            }).fail(function () {
                cb([]);
            });
        }

        function renderRuleSuggest(q) {
            var $box = $modal.find('.unit-rule-suggest');
            loadRules(function (rules) {
                var query = (q || '').toLowerCase().trim();
                var list = (rules || []).filter(function (rl) {
                    if (rl.estado === 'archivada') {
                        return false;
                    }
                    if (!query) {
                        return true;
                    }
                    var hay = ((rl.codigo || '') + ' ' + (rl.nombre || '') + ' v' + (rl.version || '')).toLowerCase();
                    return hay.indexOf(query) !== -1;
                }).slice(0, 20);
                if (!list.length) {
                    $box.html('<div style="padding:8px 10px;color:#666;">Sin reglas</div>').show();
                    return;
                }
                $box.html(list.map(function (rl) {
                    return '<button type="button" class="unit-rule-item" data-id="' + esc(String(rl.id)) + '" data-label="' + esc(ruleLabel(rl)) + '">' +
                        esc(ruleLabel(rl)) + ' <span style="color:#666;">(' + esc(rl.estado || '') + ')</span></button>';
                }).join('')).show();
            });
        }

        $modal.find('.unit-rule-q').off('focus.unit input.unit').on('focus.unit input.unit', function () {
            var q = $(this).val();
            clearTimeout(ruleSearchTimer);
            ruleSearchTimer = setTimeout(function () {
                renderRuleSuggest(q);
            }, 200);
        });
        $modal.off('click.unitRule').on('click.unitRule', '.unit-rule-item', function () {
            var id = $(this).data('id');
            var label = $(this).data('label') || '';
            $modal.find('.unit-rule-id').val(id);
            $modal.find('.unit-rule-selected').html('Seleccionada: <strong>' + esc(label) + '</strong>');
            $modal.find('.unit-confirm-r1').prop('checked', true);
            $modal.find('.unit-rule-suggest').hide().empty();
            $modal.find('.unit-rule-q').val(label);
        });

        $modal.find('.unit-save').off('click.unit').on('click.unit', function () {
            var $btn = $(this).prop('disabled', true);
            ensureFamilyCreated($modal).done(function (fid) {
                saveUnitFromPanel($modal, fid).done(function (res) {
                    $btn.prop('disabled', false);
                    if (res.skipped) {
                        alert('Nada que guardar en producto unitario');
                        return;
                    }
                    alert(res.message || 'Producto unitario guardado');
                    reloadUnit();
                    if (typeof cfg().onChanged === 'function') {
                        cfg().onChanged(fid);
                    }
                }).fail(function (err) {
                    $btn.prop('disabled', false);
                    alert((err && err.message) || 'Error al guardar');
                    if (err && err.unit) {
                        reloadUnit();
                    }
                });
            }).fail(function (err) {
                $btn.prop('disabled', false);
                alert((err && err.message) || 'Indica el nombre de la familia y pulsa Guardar todo');
            });
        });

        $modal.find('.unit-preview-prices').off('click.unit').on('click.unit', function () {
            var fid = familyId();
            if (!fid) {
                alert('Guarda la familia (nombre + Guardar todo) para previsualizar precios');
                return;
            }
            post('riverso_families_unit_price_preview', {
                grupo_id: fid,
                p_asignado: $modal.find('.unit-precio').val()
            }).done(function (r) {
                if (!r.success) {
                    alert((r.data && r.data.message) || 'Error en preview');
                    return;
                }
                var rows = (r.data.preview.members || []).map(function (m) {
                    var shadow = m.regla_sombreada ? ' style="color:#e65100;" title="Regla directa en producto"' : '';
                    return '<tr' + shadow + '><td>' + esc(m.canonical_sku) + '</td><td>' + esc(m.nombre_canonico) + '</td>' +
                        '<td>' + m.cantidad_unidades + '</td><td>' + (m.precio_unitario_regla != null ? Number(m.precio_unitario_regla).toLocaleString('es-CL') : '—') + '</td>' +
                        '<td>' + (m.precio_total_presentacion != null ? Number(m.precio_total_presentacion).toLocaleString('es-CL') : '—') + '</td>' +
                        '<td>' + (m.regla_sombreada ? 'Sombreada' : '') + '</td></tr>';
                }).join('');
                $modal.find('.unit-preview-table').html(
                    '<table class="widefat striped" style="font-size:12px;"><thead><tr><th>SKU</th><th>Nombre</th><th>Qty</th><th>P/u regla</th><th>Total</th><th></th></tr></thead><tbody>' +
                    rows + '</tbody></table>'
                );
            });
        });
    }

    function loadUnitPanelIntoModal($modal, familyId, members) {
        if (!familyId) {
            $modal.find('.riverso-unit-panel-wrap').html(renderUnitPanel(null, 0, members || []));
            bindUnitPanel($modal);
            return;
        }
        post('riverso_families_unit_get', { grupo_id: familyId }).done(function (r) {
            var unit = r.success ? r.data.unit : null;
            var html = renderUnitPanel(unit, familyId, members);
            $modal.find('.riverso-unit-panel-wrap').html(html);
            bindUnitPanel($modal);
            var uid = unit && unit.unit && unit.unit.id;
            if (uid) {
                loadLinkPreview($modal, familyId, uid);
            }
        });
    }

    function renderViewUnitBlock(unit) {
        if (!unit || !unit.unit) {
            return '<div style="margin:12px 0;padding:10px;border:1px dashed #bbb;border-radius:4px;background:#fafafa;font-size:13px;color:#666;">Sin producto unitario vinculado</div>';
        }
        var u = unit.unit;
        var enabled = isUnitEnabled(unit);
        return '<div style="margin:12px 0;padding:12px;border:1px solid #90caf9;border-radius:4px;background:#e3f2fd;">' +
            '<strong>Producto unitario</strong> ' +
            (enabled ? '<span style="color:#2e7d32;font-size:12px;">(activado)</span>' : '<span style="color:#999;font-size:12px;">(desactivado)</span>') +
            '<div style="margin-top:8px;font-size:13px;">' +
            '<span style="display:inline-block;background:#7b1fa2;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700;margin-right:6px;">U</span>' +
            '<strong>SKU ' + esc(u.canonical_sku || '—') + '</strong> — ' + esc(u.nombre_canonico || '') +
            '</div></div>';
    }

    function openView(familyId) {
        ensureStyles();
        closeAll();
        $.when(
            post('riverso_families_get', { grupo_id: familyId }),
            post('riverso_families_unit_get', { grupo_id: familyId })
        ).done(function (famResp, unitResp) {
            var r = famResp[0];
            var ur = unitResp[0];
            if (!r.success || !r.data || !r.data.family) {
                alert((r.data && r.data.message) || 'No se pudo cargar la familia');
                return;
            }
            var fam = r.data.family;
            var members = fam.members || [];
            var unit = ur && ur.success ? ur.data.unit : null;
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
                renderViewUnitBlock(unit) +
                '<p><strong>Miembros</strong></p>' +
                renderMembersList(members, false) +
                renderPending(fam.pending) +
                '<div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px;">' +
                '<button type="button" class="button riverso-family-modal-close">Cerrar</button>' +
                (canManage()
                    ? '<button type="button" class="button button-primary riverso-family-view-edit" data-family-id="' + esc(String(fam.id || familyId)) + '">Editar</button>'
                    : '') +
                '</div></div></div>'
            );
            $('body').append($modal);
            bindOverlay($modal);
            $modal.find('.riverso-family-view-edit').on('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var id = parseInt($(this).data('family-id') || familyId, 10);
                if (id) {
                    openEdit(id);
                }
            });
        });
    }

    function refreshEditMembers($modal, familyId) {
        return post('riverso_families_get', { grupo_id: familyId }).done(function (r) {
            if (!r.success || !r.data || !r.data.family) {
                return;
            }
            var fam = r.data.family;
            $modal.find('.riverso-family-members-wrap').html(renderMembersList(fam.members || [], true, fam.pack_conflicts || []));
            updatePackConflictsBanner($modal, fam.pack_conflicts || [], familyId);
            $modal.find('.riverso-family-pending-wrap').html(renderPending(fam.pending));
            var stock = fam.stock && fam.stock.stock_unidades != null
                ? Number(fam.stock.stock_unidades).toLocaleString('es-CL') + ' u'
                : '—';
            $modal.find('.riverso-family-stock-label').text(stock);
            $modal.find('.riverso-family-member-count').text(String((fam.members || []).length));
            var warn = (fam.stock && fam.stock.warnings && fam.stock.warnings.length)
                ? fam.stock.warnings.join(' | ')
                : '';
            var $warn = $modal.find('.riverso-family-stock-warnings');
            if (!$warn.length) {
                $modal.find('.riverso-family-stock-label').closest('p').after(
                    '<p class="riverso-family-stock-warnings" style="color:#e65100;font-size:12px;margin:4px 0 8px;"></p>'
                );
                $warn = $modal.find('.riverso-family-stock-warnings');
            }
            if (warn) {
                $warn.text(warn).show();
            } else {
                $warn.hide().text('');
            }
            refreshNameSuggestions($modal);
            if (typeof cfg().onChanged === 'function') {
                cfg().onChanged(familyId);
            }
        });
    }

    function renderSearchResults(items, familyId, pendingIds) {
        if (!items || !items.length) {
            return '<p style="color:#999;font-size:13px;margin:8px 0;">Sin resultados</p>';
        }
        pendingIds = pendingIds || [];
        var pendingSet = {};
        pendingIds.forEach(function (id) {
            pendingSet[parseInt(id, 10)] = true;
        });
        return '<ul style="list-style:none;margin:0;padding:0;max-height:220px;overflow-y:auto;">' + items.map(function (it) {
            var pid = parseInt(it.id || it.producto_base_id || 0, 10);
            var alreadyPending = !!pendingSet[pid];
            var action;
            if (alreadyPending) {
                action = '<span style="color:#2e7d32;font-size:12px;">En lista</span>';
            } else if (it.can_add && canManage()) {
                action = '<button type="button" class="button button-small button-primary riverso-family-add-member" ' +
                    'data-product-id="' + esc(String(pid)) + '" ' +
                    'data-nombre="' + esc(it.nombre_canonico || '') + '" ' +
                    'data-sku-local="' + esc(it.sku_local || it.canonical_sku || '') + '" ' +
                    'data-sku-online="' + esc(it.sku_online || '') + '" ' +
                    'data-es-local="' + (it.es_local ? '1' : '0') + '" ' +
                    'data-es-online="' + (it.es_online ? '1' : '0') + '">Agregar</button>';
            } else if (it.aviso) {
                action = '<span style="color:#c62828;font-size:12px;">' + esc(it.aviso) + '</span>';
            } else {
                action = '';
            }
            return '<li style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px;border-bottom:1px solid #eee;font-size:13px;">' +
                '<div style="flex:1;min-width:0;">' +
                badges(!!it.es_local, !!it.es_online, false) +
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
            openEditor(r.data.family, { isCreate: false });
        });
    }

    function openCreate(onCreated) {
        if (!canManage()) {
            alert('Sin permisos para crear familias');
            return;
        }
        openEditor({
            id: 0,
            nombre: '',
            codigo_grupo: '',
            tipo_sustitucion: 'exacta',
            members: [],
            pending: [],
            stock: { stock_unidades: null, warnings: [] }
        }, { isCreate: true, onCreated: onCreated });
    }

    function openEditor(fam, opts) {
        opts = opts || {};
        var isCreate = !!opts.isCreate;
        var familyId = parseInt(fam.id || 0, 10) || 0;
        ensureStyles();
        closeAll();

        var stock = fam.stock && fam.stock.stock_unidades != null
            ? Number(fam.stock.stock_unidades).toLocaleString('es-CL') + ' u'
            : '—';
        var manage = canManage();
        var codigoDisabled = !isCreate;
        var metaBlock = manage
            ? (
                '<label style="display:block;margin-bottom:10px;"><strong>Nombre:</strong><br>' +
                '<input type="text" class="large-text family-edit-nombre" value="' + esc(fam.nombre || '') + '" placeholder="Ej. Tuercas M6" style="width:100%;padding:6px;box-sizing:border-box;">' +
                '<div class="family-name-suggestions" style="margin-top:6px;min-height:0;"></div></label>' +
                '<label style="display:block;margin-bottom:10px;"><strong>Código:</strong><br>' +
                '<input type="text" class="large-text family-edit-codigo" value="' + esc(fam.codigo_grupo || '') + '" ' +
                (codigoDisabled ? 'disabled ' : '') +
                'placeholder="Se genera del nombre si lo dejas vacío" style="width:100%;padding:6px;box-sizing:border-box;">' +
                '<small class="family-codigo-hint" style="color:#888;font-size:12px;">' +
                (isCreate ? 'Opcional: si está vacío se usa el slug del nombre' : '') +
                '</small></label>' +
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

        var deleteBtnStyle = isCreate ? 'display:none;color:#b71c1c;border-color:#e57373;' : 'color:#b71c1c;border-color:#e57373;';

        var $modal = $(
            '<div class="riverso-family-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;">' +
            '<div class="riverso-family-modal-panel" style="background:#fff;padding:20px;border-radius:6px;max-width:720px;width:94%;max-height:90vh;overflow-y:auto;">' +
            '<h3 class="riverso-family-editor-title" style="margin:0 0 12px;">' + (isCreate ? 'Crear Familia' : 'Editar Familia') + '</h3>' +
            metaBlock +
            '<div class="riverso-unit-panel-wrap"></div>' +
            '<p style="margin:0 0 8px;color:#666;font-size:13px;">Stock familia: <strong class="riverso-family-stock-label">' + esc(stock) +
            '</strong> · <span class="riverso-family-member-count">' + (fam.members || []).length + '</span> miembro(s)</p>' +
            '<p style="margin:12px 0 6px;"><strong>Miembros</strong></p>' +
            '<div class="riverso-family-pack-conflicts-wrap">' + renderPackConflictsBanner(fam.pack_conflicts || [], familyId) + '</div>' +
            '<div class="riverso-family-members-wrap">' + renderMembersList(fam.members || [], true, fam.pack_conflicts || []) + '</div>' +
            '<div class="riverso-family-pending-wrap">' + renderPending(fam.pending) + '</div>' +
            searchBlock +
            '<div style="display:flex;gap:8px;justify-content:space-between;margin-top:16px;padding-top:12px;border-top:1px solid #ddd;">' +
            (manage ? '<button type="button" class="button riverso-family-delete" style="' + deleteBtnStyle + '">Eliminar familia</button>' : '<span></span>') +
            '<div style="display:flex;gap:8px;">' +
            '<button type="button" class="button riverso-family-modal-close">Cerrar</button>' +
            (manage ? '<button type="button" class="button button-primary riverso-family-save-meta">Guardar todo</button>' : '') +
            '</div></div></div></div>'
        );

        $modal.data('familyId', familyId);
        $modal.data('isCreate', isCreate);
        $modal.data('codigoTouched', !isCreate || !!(fam.codigo_grupo));
        setPendingMembers($modal, []);
        if (typeof opts.onCreated === 'function') {
            $modal.data('onCreated', opts.onCreated);
        }

        $('body').append($modal);
        bindOverlay($modal);
        loadUnitPanelIntoModal($modal, familyId, fam.members || []);
        refreshNameSuggestions($modal);

        $modal.on('click', '.family-name-pick', function (ev) {
            ev.preventDefault();
            var name = $(this).attr('data-name') || '';
            if (!name) {
                return;
            }
            $modal.find('.family-edit-nombre').val(name).trigger('input');
        });

        if (manage && isCreate) {
            $modal.find('.family-edit-nombre').on('input', function () {
                if ($modal.data('codigoTouched')) {
                    return;
                }
                var preview = slugifyCodigoPreview($(this).val());
                $modal.find('.family-edit-codigo').attr('placeholder', preview
                    ? ('Se generará: ' + preview)
                    : 'Se genera del nombre si lo dejas vacío');
                $modal.find('.family-codigo-hint').text(preview
                    ? ('Vista previa: ' + preview)
                    : 'Opcional: si está vacío se usa el slug del nombre');
            });
            $modal.find('.family-edit-codigo').on('input', function () {
                $modal.data('codigoTouched', ($(this).val() || '').trim() !== '');
            });
            $modal.find('.family-edit-nombre').focus();
        }

        $modal.on('click', '.riverso-family-pack-merge-review, .riverso-family-link-local', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var sourceId = parseInt($(this).attr('data-source-id') || 0, 10);
            var targetId = parseInt($(this).attr('data-target-id') || 0, 10);
            var fid = currentFamilyId($modal) || parseInt($(this).attr('data-family-id') || 0, 10);
            if (!fid || !sourceId || !targetId) {
                return;
            }
            runPackMergeFlow($modal, fid, sourceId, targetId);
        });

        $modal.on('click', '.riverso-family-remove-member', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            if (!confirm('¿Quitar este miembro de la familia?')) {
                return;
            }
            var memberId = $(this).data('member-id');
            var productId = $(this).data('product-id') || $(this).attr('data-product-id');
            var $btn = $(this).prop('disabled', true);
            var fid = currentFamilyId($modal);
            if (!fid) {
                removePendingMember($modal, productId);
                var q = $modal.find('.riverso-family-search-input').val().trim();
                if (q) {
                    $modal.find('.riverso-family-search-input').trigger('input');
                }
                return;
            }
            post('riverso_families_remove_member', { member_id: memberId }).done(function (resp) {
                if (!resp.success) {
                    alert((resp.data && resp.data.message) || 'No se pudo quitar');
                    $btn.prop('disabled', false);
                    return;
                }
                refreshEditMembers($modal, fid);
            }).fail(function () {
                $btn.prop('disabled', false);
                alert('Error de red');
            });
        });

        $modal.on('click', '.riverso-family-save-envase', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var productId = $(this).data('product-id');
            var $row = $(this).closest('li');
            var qty = parseFloat($row.find('.riverso-family-envase-qty').val());
            if (!(qty > 0)) {
                alert('Indica una cantidad de envase mayor a 0 (ej. 100)');
                return;
            }
            var $btn = $(this).prop('disabled', true).text('…');
            var fid = currentFamilyId($modal);
            if (!fid) {
                updatePendingEnvase($modal, productId, qty);
                $btn.prop('disabled', false).text('Guardar envase');
                return;
            }
            post('riverso_families_set_member_envase', {
                grupo_id: fid,
                producto_base_id: productId,
                cantidad_unidades: qty
            }).done(function (resp) {
                $btn.prop('disabled', false).text('Guardar envase');
                if (!resp.success) {
                    alert((resp.data && resp.data.message) || 'No se pudo guardar');
                    return;
                }
                refreshEditMembers($modal, fid);
            }).fail(function () {
                $btn.prop('disabled', false).text('Guardar envase');
                alert('Error de red');
            });
        });

        $modal.on('click', '.riverso-family-create-local', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var productId = parseInt($(this).data('product-id') || $(this).attr('data-product-id') || 0, 10);
            var nombre = $(this).attr('data-nombre') || '';
            if (!productId) {
                return;
            }
            var $btn = $(this).prop('disabled', true).text('…');

            function askAndCreate(suggested) {
                var label = nombre ? (' «' + nombre + '»') : '';
                var chosen = window.prompt(
                    'SKU local para el producto online' + label + '.\nSolo dígitos, máx. 6.\nVacío = asignar el siguiente disponible.',
                    suggested || ''
                );
                if (chosen === null) {
                    $btn.prop('disabled', false).text('Crear SKU local');
                    return;
                }
                chosen = String(chosen || '').trim();
                if (chosen !== '' && !/^\d{1,6}$/.test(chosen)) {
                    alert('SKU Local debe ser numérico y máximo 6 dígitos');
                    $btn.prop('disabled', false).text('Crear SKU local');
                    return;
                }
                var fid = currentFamilyId($modal) || 0;
                var payload = {
                    producto_base_id: productId,
                    grupo_id: fid
                };
                if (chosen) {
                    payload.canonical_sku = chosen;
                }
                post('riverso_families_create_local_from_member', payload).done(function (resp) {
                    if (!resp.success) {
                        alert((resp.data && resp.data.message) || 'No se pudo crear el SKU local');
                        $btn.prop('disabled', false).text('Crear SKU local');
                        return;
                    }
                    var prod = (resp.data && resp.data.product) || {};
                    var assigned = prod.sku_local || chosen;
                    if (!fid) {
                        var list = getPendingMembers($modal).map(function (m) {
                            if (parseInt(m.producto_base_id, 10) !== productId) {
                                return m;
                            }
                            return Object.assign({}, m, {
                                sku_local: assigned,
                                canonical_sku: assigned,
                                es_local: true,
                                es_online: prod.es_online !== false,
                                sku_online: prod.sku_online != null ? prod.sku_online : m.sku_online,
                                nombre_canonico: prod.nombre_canonico || m.nombre_canonico
                            });
                        });
                        setPendingMembers($modal, list);
                        renderPendingMembersList($modal);
                        alert((resp.data && resp.data.message) || ('SKU local asignado: ' + assigned));
                        return;
                    }
                    refreshEditMembers($modal, fid).always(function () {
                        alert((resp.data && resp.data.message) || ('SKU local asignado: ' + assigned));
                    });
                }).fail(function () {
                    $btn.prop('disabled', false).text('Crear SKU local');
                    alert('Error de red');
                });
            }

            post('riverso_products_next_sku', {}).done(function (skuResp) {
                var suggested = (skuResp.success && skuResp.data && skuResp.data.next_sku)
                    ? String(skuResp.data.next_sku)
                    : '';
                askAndCreate(suggested);
            }).fail(function () {
                askAndCreate('');
            });
        });

        $modal.find('.riverso-family-delete').on('click', function () {
            var fid = currentFamilyId($modal);
            if (!fid) {
                closeAll();
                return;
            }
            var nombre = ($modal.find('.family-edit-nombre').val() || '').trim() || ('#' + fid);
            var count = parseInt($modal.find('.riverso-family-member-count').text() || '0', 10) || 0;
            var msg = '¿Eliminar la familia «' + nombre + '»?\n\n'
                + 'Se desactivará y se quitarán ' + count + ' miembro(s) de la familia.\n'
                + 'Los productos no se borran.\n\nEsta acción se puede auditar, pero la familia dejará de listarse.';
            if (!confirm(msg)) {
                return;
            }
            var $btn = $(this).prop('disabled', true).text('Eliminando…');
            post('riverso_families_delete', { grupo_id: fid }).done(function (resp) {
                if (!resp.success) {
                    $btn.prop('disabled', false).text('Eliminar familia');
                    alert((resp.data && resp.data.message) || 'No se pudo eliminar');
                    return;
                }
                closeAll();
                if (typeof cfg().onChanged === 'function') {
                    cfg().onChanged(fid);
                }
                alert('Familia eliminada');
            }).fail(function () {
                $btn.prop('disabled', false).text('Eliminar familia');
                alert('Error de red');
            });
        });

        $modal.find('.riverso-family-save-meta').on('click', function () {
            var nombre = ($modal.find('.family-edit-nombre').val() || '').trim();
            var tipo = $modal.find('.family-edit-tipo').val();
            if (!nombre) {
                var chips = $modal.find('.family-name-pick');
                if (chips.length) {
                    alert('Elige una sugerencia de nombre o escribe el nombre antes de Guardar todo');
                } else {
                    alert('El nombre es requerido');
                }
                return;
            }
            var $btn = $(this).prop('disabled', true).text('Guardando…');
            ensureFamilyCreated($modal).done(function (fid) {
                flushPendingMembers($modal, fid).done(function () {
                    finishSaveMeta(fid, nombre, tipo, $btn);
                }).fail(function (err) {
                    alert((err && err.message) || 'Algunos miembros no se pudieron agregar');
                    finishSaveMeta(fid, nombre, tipo, $btn);
                });
            }).fail(function (err) {
                $btn.prop('disabled', false).text('Guardar todo');
                alert((err && err.message) || 'No se pudo crear la familia');
            });
        });

        function finishSaveMeta(fid, nombre, tipo, $btn) {
            post('riverso_families_update', {
                grupo_id: fid,
                nombre: nombre,
                tipo_sustitucion: tipo
            }).done(function (resp) {
                if (!resp.success) {
                    $btn.prop('disabled', false).text('Guardar todo');
                    alert((resp.data && resp.data.message) || 'No se pudo guardar');
                    refreshEditMembers($modal, fid);
                    return;
                }
                // Disparar guardado unitario mientras el modal sigue en DOM; cerrar enseguida.
                var unitPromise = saveUnitFromPanel($modal, fid);
                finalizeFamilySave($modal, $btn, fid, 'Datos de familia guardados');
                unitPromise.done(function (unitRes) {
                    if (unitRes && !unitRes.skipped && unitRes.message) {
                        alert(unitRes.message);
                    }
                }).fail(function (err) {
                    alert('Producto unitario NO guardado:\n' + ((err && err.message) || 'Error'));
                });
            }).fail(function () {
                $btn.prop('disabled', false).text('Guardar todo');
                refreshEditMembers($modal, fid);
                alert('Error de red');
            });
        }

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
                var fid = currentFamilyId($modal) || 0;
                post('riverso_families_search_candidates', {
                    q: q,
                    grupo_id: fid,
                    limit: 15
                }).done(function (resp) {
                    if (!resp.success) {
                        $results.html('<p style="color:#c62828;font-size:12px;">' + esc((resp.data && resp.data.message) || 'Error') + '</p>');
                        return;
                    }
                    var pendingIds = getPendingMembers($modal).map(function (m) {
                        return m.producto_base_id;
                    });
                    $results.html(renderSearchResults(resp.data.items || [], fid, pendingIds));
                }).fail(function () {
                    $results.html('<p style="color:#c62828;font-size:12px;">Error de red</p>');
                });
            }, 300);
        });

        $modal.on('click', '.riverso-family-add-member', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            var $btn = $(this);
            var productId = parseInt($btn.data('product-id') || $btn.attr('data-product-id') || 0, 10);
            var fid = currentFamilyId($modal);
            if (!fid) {
                var added = addPendingMember($modal, {
                    producto_base_id: productId,
                    nombre_canonico: $btn.attr('data-nombre') || '',
                    sku_local: $btn.attr('data-sku-local') || '',
                    sku_online: $btn.attr('data-sku-online') || '',
                    es_local: $btn.attr('data-es-local') === '1',
                    es_online: $btn.attr('data-es-online') === '1'
                });
                if (!added) {
                    alert('Ese producto ya está en la lista');
                    return;
                }
                $btn.replaceWith('<span style="color:#2e7d32;font-size:12px;">En lista</span>');
                return;
            }
            $btn.prop('disabled', true).text('…');
            post('riverso_families_add_member', {
                grupo_id: fid,
                producto_base_id: productId,
                prioridad: 100,
                es_preferido: 0
            }).done(function (resp) {
                if (!resp.success) {
                    alert((resp.data && resp.data.message) || 'No se pudo agregar');
                    $btn.prop('disabled', false).text('Agregar');
                    return;
                }
                refreshEditMembers($modal, fid);
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
    }

    window.RiversoFamilyEditor = {
        openView: openView,
        openEdit: openEdit,
        openCreate: openCreate,
        closeAll: closeAll,
        renderListPreview: renderListPreview,
        renderMemberSkusInline: renderMemberSkusInline,
        skuLines: skuLines
    };
})(window, window.jQuery);
