<?php
if (!defined('ABSPATH')) {
    exit;
}
$nonce = wp_create_nonce('riverso_pos_nonce');
$can_manage = current_user_can('riverso_manage_products');
if (!$can_manage) {
    wp_die('No tienes permisos para acceder a esta página.');
}
?>
<div class="wrap">
    <h1>Categorías y Familias</h1>
    <p>Gestión centralizada de categorías WooCommerce y familias de productos con edición de árbol, movimiento de nodos e impacto de cambios.</p>

    <div style="border-bottom:2px solid #ddd; margin-bottom:20px;">
        <button class="nav-tab nav-tab-active" id="tab-categories-btn" data-tab="categories">Categorías</button>
        <button class="nav-tab" id="tab-families-btn" data-tab="families">Familias</button>
    </div>

    <!-- TAB: Categorías -->
    <div id="tab-categories-content" class="tab-content" style="display:block;">
        <h2>Árbol de Categorías WooCommerce</h2>
        <p style="color:#666; margin-bottom:16px;">Edita la estructura de categorías de tu tienda. Puedes crear, renombrar, mover y eliminar categorías. Los cambios se aplicarán automáticamente a los productos.</p>
        
        <div style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <button class="button button-primary" id="categories-add-root">+ Categoría Raíz</button>
            <button class="button" id="categories-expand-all">Expandir todo</button>
            <button class="button" id="categories-collapse-all">Colapsar todo</button>
        </div>

        <div id="categories-tree" style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa; max-height:600px; overflow-y:auto; margin-bottom:16px;">
            <p style="color:#999; text-align:center;">Cargando categorías...</p>
        </div>

        <!-- Modal de crear categoría -->
        <div id="category-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:white; padding:20px; border-radius:6px; max-width:400px; width:90%;">
                <h3>Crear Nueva Categoría</h3>
                <label style="display:block; margin-bottom:10px;">
                    <strong>Nombre:</strong><br>
                    <input type="text" id="category-modal-name" class="large-text" placeholder="Ej. Herramientas" style="width:100%; padding:6px; box-sizing:border-box;">
                </label>
                <label style="display:block; margin-bottom:16px;">
                    <strong>Categoría Padre:</strong><br>
                    <select id="category-modal-parent" style="width:100%; padding:6px; box-sizing:border-box;">
                        <option value="0">Sin padre (raíz)</option>
                    </select>
                </label>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button class="button" id="category-modal-cancel">Cancelar</button>
                    <button class="button button-primary" id="category-modal-save">Crear</button>
                </div>
            </div>
        </div>

        <!-- Modal de eliminar categoría -->
        <div id="category-delete-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:white; padding:20px; border-radius:6px; max-width:500px; width:90%;">
                <h3>Eliminar Categoría</h3>
                <div id="category-delete-warning" style="background:#fff3cd; border-left:4px solid #ffc107; padding:12px; border-radius:4px; margin-bottom:16px;">
                    <!-- Se puebla dinámicamente -->
                </div>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button class="button" id="category-delete-cancel">Cancelar</button>
                    <button class="button button-danger" id="category-delete-confirm" style="background:#dc3545;">Eliminar</button>
                </div>
            </div>
        </div>

        <!-- Modal de mover rama -->
        <div id="category-move-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:white; padding:20px; border-radius:6px; max-width:450px; width:90%;">
                <h3>Mover rama</h3>
                <p id="category-move-label" style="color:#666; margin-bottom:12px;"></p>
                <div id="category-move-impact" style="background:#fff3cd; border-left:4px solid #ffc107; padding:10px; border-radius:4px; margin-bottom:12px; display:none;"></div>
                <label style="display:block; margin-bottom:16px;">
                    <strong>Nuevo padre:</strong><br>
                    <select id="category-move-parent" style="width:100%; padding:6px; box-sizing:border-box;">
                        <option value="0">Sin padre (raíz)</option>
                    </select>
                </label>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button class="button" id="category-move-cancel">Cancelar</button>
                    <button class="button button-primary" id="category-move-confirm">Mover</button>
                </div>
            </div>
        </div>

        <!-- Modal elegir hermano (bajar nivel) -->
        <div id="category-sibling-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:white; padding:20px; border-radius:6px; max-width:450px; width:90%;">
                <h3>Bajar nivel</h3>
                <p id="category-sibling-label" style="color:#666; margin-bottom:12px;"></p>
                <label style="display:block; margin-bottom:16px;">
                    <strong>Convertir en hijo de:</strong><br>
                    <select id="category-sibling-parent" style="width:100%; padding:6px; box-sizing:border-box;"></select>
                </label>
                <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button class="button" id="category-sibling-cancel">Cancelar</button>
                    <button class="button button-primary" id="category-sibling-confirm">Bajar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: Familias -->
    <div id="tab-families-content" class="tab-content" style="display:none;">
        <h2>Familias de Productos</h2>
        <p style="color:#666; margin-bottom:16px;">Vista global de familias y grupos de equivalencia. Gestiona miembros, crea nuevas familias y edita sus propiedades.</p>
        
        <div style="margin-bottom:12px;">
            <button class="button button-primary" id="families-add-new">+ Nueva Familia</button>
        </div>

        <div id="families-list" style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa; max-height:600px; overflow-y:auto; margin-bottom:16px;">
            <p style="color:#999; text-align:center;">Cargando familias...</p>
        </div>
    </div>

</div>

<style>
.nav-tab {
    padding: 8px 12px;
    border-bottom: 2px solid transparent;
    text-decoration: none;
    color: #2271b1;
    cursor: pointer;
    display: inline-block;
    font-size: 14px;
}
.nav-tab.nav-tab-active {
    border-bottom-color: #2271b1;
    font-weight: bold;
}
.category-tree-item {
    padding: 10px;
    border-left: 3px solid #ccc;
    margin-left: 16px;
    margin-bottom: 6px;
    background: white;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background-color 0.15s, box-shadow 0.15s;
}
.category-tree-item:hover {
    background-color: #f0f7ff;
    box-shadow: 0 1px 4px rgba(34, 113, 177, 0.1);
}
.category-tree-item.root {
    margin-left: 0;
    border-left: 4px solid #2271b1;
    background: #f5f5f5;
    font-weight: bold;
}
.category-tree-item.root:hover {
    background-color: #e8f1f9;
}
.category-tree-name {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
}
.category-tree-count {
    font-size: 12px;
    color: #666;
    white-space: nowrap;
}
.category-tree-actions {
    display: flex;
    gap: 2px;
    margin-left: auto;
    flex-shrink: 0;
}
.category-tree-actions button {
    padding: 6px 8px;
    font-size: 13px;
    border: 1px solid #ddd;
    background: white;
    cursor: pointer;
    border-radius: 3px;
    transition: all 0.2s;
    min-width: 30px;
    text-align: center;
}
.category-tree-actions button:hover:not(:disabled) {
    background: #e7f3ff;
    border-color: #2271b1;
    color: #2271b1;
}
.category-tree-actions button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    color: #999;
}
.category-tree-actions button[title] {
    position: relative;
}
.cat-mgmt-node { margin-bottom: 4px; }
.cat-mgmt-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.cat-branch-toggle {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    border: 1px solid #ccc;
    border-radius: 3px;
    background: #fff;
    cursor: pointer;
    padding: 0;
    font-size: 10px;
    line-height: 20px;
    color: #555;
}
.cat-branch-toggle:hover { background: #f0f0f0; }
.cat-branch-spacer { width: 22px; flex-shrink: 0; display: inline-block; }
.cat-mgmt-children { margin-left: 0; }
.family-item {
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 8px;
    background: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.button.button-danger {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
}
.button.button-danger:hover {
    background-color: #c82333;
    border-color: #c82333;
}
#category-modal, #category-delete-modal, #category-move-modal, #category-sibling-modal {
    display: none !important;
}
#category-modal.show, #category-delete-modal.show, #category-move-modal.show, #category-sibling-modal.show {
    display: flex !important;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    let categoriesTree = [];
    let familiesList = [];
    let pendingDeleteCategory = null;
    let pendingMoveCategory = null;
    let pendingSiblingMove = null;

    function esc(v) { return $('<div>').text(v === null || v === undefined ? '' : v).html(); }

    // ===== TAB SWITCHING =====
    $('#tab-categories-btn').on('click', function() {
        $('#tab-categories-btn').addClass('nav-tab-active');
        $('#tab-families-btn').removeClass('nav-tab-active');
        $('#tab-categories-content').show();
        $('#tab-families-content').hide();
    });

    $('#tab-families-btn').on('click', function() {
        $('#tab-families-btn').addClass('nav-tab-active');
        $('#tab-categories-btn').removeClass('nav-tab-active');
        $('#tab-families-content').show();
        $('#tab-categories-content').hide();
        loadFamilies();
    });

    // ===== CATEGORÍAS =====
    function loadCategories() {
        $.post(ajaxurl, {
            action: 'riverso_products_get_category_tree',
            nonce: nonce
        }, function(r) {
            if (r.success && r.data.tree) {
                categoriesTree = r.data.tree || [];
                renderCategoriesTree(categoriesTree);
            }
        });
    }

    function renderCategoriesTree(cats, indent = 0) {
        if (!cats || cats.length === 0) {
            if (indent === 0) {
                $('#categories-tree').html('<p style="color:#999; text-align:center;">Sin categorías</p>');
            }
            return '';
        }

        const renderNode = (catList, level) => {
            let html = '';
            catList.forEach((cat) => {
                const isRoot = level === 0;
                const padding = level * 16;
                const canMoveUp = !isRoot;
                const canMoveDown = catList.length > 1;
                const productCount = cat.count || 0;
                const hasChildren = !!(cat.children && cat.children.length > 0);
                // Por defecto colapsado en gestión de categorías
                const isExpanded = false;
                const toggleHtml = hasChildren
                    ? `<button type="button" class="cat-branch-toggle" aria-expanded="false" title="Mostrar rama">▶</button>`
                    : '<span class="cat-branch-spacer"></span>';

                html += `<div class="cat-mgmt-node" data-term-id="${cat.id}">
                    <div class="cat-mgmt-row">
                        ${toggleHtml}
                        <div class="category-tree-item ${isRoot ? 'root' : ''}" style="margin-left:${padding}px; flex:1;" data-term-id="${cat.id}" data-parent-id="${cat.parent || 0}">
                            <div class="category-tree-name">
                                <span>${esc(cat.name)}</span>
                                <span class="category-tree-count" title="Productos directos">(${productCount})</span>
                            </div>
                            <div class="category-tree-actions">
                                <button class="button button-small category-add-child" data-parent-id="${cat.id}" title="Agregar subcategoría">+</button>
                                <button class="button button-small category-rename" data-term-id="${cat.id}" title="Renombrar">✎</button>
                                <button class="button button-small category-move-up" data-term-id="${cat.id}" ${!canMoveUp ? 'disabled' : ''} title="Subir nivel (hermano del padre)">↑</button>
                                <button class="button button-small category-move-down" data-term-id="${cat.id}" ${!canMoveDown ? 'disabled' : ''} title="Bajar nivel (hijo del hermano anterior)">↓</button>
                                <button class="button button-small category-move-branch" data-term-id="${cat.id}" title="Mover rama a otro padre">⇄</button>
                                <button class="button button-small category-delete" data-term-id="${cat.id}" title="Eliminar">🗑</button>
                            </div>
                        </div>
                    </div>
                    ${hasChildren ? `<div class="cat-mgmt-children" style="display:none;">${renderNode(cat.children, level + 1)}</div>` : ''}
                </div>`;
            });
            return html;
        };

        if (indent === 0) {
            $('#categories-tree').html(renderNode(cats, 0));
            attachCategoryEventListeners();
            return;
        }
        return renderNode(cats, indent);
    }

    function renderCategoriesTreeRecursive(cats, indent) {
        return '';
    }

    function attachCategoryEventListeners() {
        $(document).off('click.catTree');

        $(document).on('click.catTree', '.cat-branch-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            const $children = $btn.closest('.cat-mgmt-node').children('.cat-mgmt-children');
            const open = $children.is(':visible');
            if (open) {
                $children.hide();
                $btn.attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
            } else {
                $children.show();
                $btn.attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
            }
        });

        $(document).on('click.catTree', '.category-add-child', function(e) {
            e.preventDefault();
            openCategoryModal($(this).data('parent-id'));
        });

        $(document).on('click.catTree', '.category-rename', function(e) {
            e.preventDefault();
            const termId = $(this).data('term-id');
            const currentName = $(this).closest('.category-tree-item').find('.category-tree-name span:first').text();
            const newName = prompt('Nuevo nombre de categoría:', currentName);
            if (newName && newName.trim()) {
                $.post(ajaxurl, {
                    action: 'riverso_products_rename_category',
                    nonce: nonce,
                    term_id: termId,
                    name: newName
                }, function(r) {
                    if (r.success) {
                        loadCategories();
                    } else {
                        alert('Error: ' + (r.data?.message || 'No se pudo renombrar'));
                    }
                });
            }
        });

        $(document).on('click.catTree', '.category-move-up', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled')) return;
            const termId = $(this).data('term-id');
            const info = findNodeAndParent(categoriesTree, termId);
            if (!info || !info.parent) {
                alert('Esta categoría ya está en el nivel superior');
                return;
            }
            // Subir = pasar a ser hermano del padre (nuevo padre = abuelo)
            const newParentId = Number(info.parent.parent) || 0;
            moveCategory(termId, newParentId);
        });

        $(document).on('click.catTree', '.category-move-down', function(e) {
            e.preventDefault();
            if ($(this).is(':disabled')) return;
            const termId = Number($(this).data('term-id'));
            const siblings = findSiblings(categoriesTree, termId);
            if (!siblings || siblings.length < 2) {
                alert('No hay un hermano del mismo nivel bajo el cual anidar');
                return;
            }
            const candidates = siblings.filter(c => Number(c.id) !== termId);
            if (candidates.length === 0) {
                alert('No hay un hermano del mismo nivel bajo el cual anidar');
                return;
            }
            // Un solo hermano: bajar directo. Varios: preguntar cuál.
            if (candidates.length === 1) {
                moveCategory(termId, candidates[0].id);
                return;
            }
            openSiblingPickModal(termId, candidates);
        });

        $(document).on('click.catTree', '.category-move-branch', function(e) {
            e.preventDefault();
            openMoveCategoryModal($(this).data('term-id'));
        });

        $(document).on('click.catTree', '.category-delete', function(e) {
            e.preventDefault();
            openDeleteCategoryModal($(this).data('term-id'));
        });
    }

    function openCategoryModal(parentId = 0) {
        $('#category-modal-name').val('');
        populateCategoryParentDropdown('#category-modal-parent');
        $('#category-modal-parent').val(String(parentId || 0));
        $('#category-modal').addClass('show');
        $('#category-modal-name').focus();
    }

    function collectDescendantIds(node) {
        const ids = [node.id];
        (node.children || []).forEach(child => {
            ids.push(...collectDescendantIds(child));
        });
        return ids;
    }

    function populateCategoryParentDropdown(selector, excludeIds = []) {
        const exclude = new Set((excludeIds || []).map(id => Number(id)));
        let options = '<option value="0">Sin padre (raíz)</option>';
        function addOptions(cats, depth) {
            cats.forEach(cat => {
                if (exclude.has(Number(cat.id))) return;
                const indent = '\u00A0'.repeat(depth * 2);
                options += `<option value="${cat.id}">${indent}${esc(cat.name)}</option>`;
                if (cat.children && cat.children.length > 0) {
                    addOptions(cat.children, depth + 1);
                }
            });
        }
        addOptions(categoriesTree, 0);
        $(selector).html(options);
    }

    // Funciones auxiliares para navegación del árbol
    function findNodeAndParent(tree, termId, parent = null) {
        const targetId = Number(termId);
        for (const cat of tree) {
            if (Number(cat.id) === targetId) return { node: cat, parent: parent };
            if (cat.children && cat.children.length > 0) {
                const found = findNodeAndParent(cat.children, termId, cat);
                if (found) return found;
            }
        }
        return null;
    }

    function findSiblings(tree, termId) {
        const targetId = Number(termId);
        for (const cat of tree) {
            if (Number(cat.id) === targetId) return tree;
            if (cat.children && cat.children.length > 0) {
                const found = findSiblings(cat.children, termId);
                if (found) return found;
            }
        }
        return null;
    }

    function moveCategory(termId, newParentId) {
        $.post(ajaxurl, {
            action: 'riverso_products_category_impact',
            nonce: nonce,
            term_id: termId
        }, function(r) {
            if (!r.success) return;
            const data = r.data;
            const msg = `Al mover esta categoría:\n- ${data.direct_products} productos directos se verán afectados\n- ${data.children_count} subcategorías se moverán junto con ella\n- Total: ${data.total_products} productos\n\n¿Continuar?`;
            
            if (!confirm(msg)) return;
            
            $.post(ajaxurl, {
                action: 'riverso_products_move_category',
                nonce: nonce,
                term_id: termId,
                new_parent_id: newParentId
            }, function(r) {
                if (r.success) {
                    loadCategories();
                } else {
                    alert('Error: ' + (r.data?.message || 'No se pudo mover'));
                }
            });
        });
    }

    function openMoveCategoryModal(termId) {
        const info = findNodeAndParent(categoriesTree, termId);
        if (!info || !info.node) {
            alert('Categoría no encontrada');
            return;
        }
        pendingMoveCategory = Number(termId);
        const currentParentId = info.parent ? Number(info.parent.id) : 0;
        const excludeIds = collectDescendantIds(info.node);

        $('#category-move-label').text('Moviendo: ' + info.node.name + ' (y toda su rama)');
        $('#category-move-impact').hide().empty();
        populateCategoryParentDropdown('#category-move-parent', excludeIds);
        $('#category-move-parent').val(String(currentParentId));

        $.post(ajaxurl, {
            action: 'riverso_products_category_impact',
            nonce: nonce,
            term_id: termId
        }, function(r) {
            if (r.success) {
                const data = r.data;
                $('#category-move-impact').html(
                    `<strong>Impacto:</strong> ${data.direct_products} productos directos, ${data.children_count} subcategorías, ${data.total_products} productos en total.`
                ).show();
            }
        });

        $('#category-move-modal').addClass('show');
    }

    function openSiblingPickModal(termId, candidates) {
        const info = findNodeAndParent(categoriesTree, termId);
        const name = info && info.node ? info.node.name : ('#' + termId);
        pendingSiblingMove = Number(termId);

        // Preferir hermano anterior como opción preseleccionada
        const siblings = findSiblings(categoriesTree, termId) || [];
        const idx = siblings.findIndex(c => Number(c.id) === Number(termId));
        const preferredId = idx > 0 ? Number(siblings[idx - 1].id) : Number(candidates[0].id);

        let options = '';
        candidates.forEach(c => {
            options += `<option value="${c.id}">${esc(c.name)}</option>`;
        });
        $('#category-sibling-parent').html(options);
        $('#category-sibling-parent').val(String(preferredId));
        $('#category-sibling-label').text('Bajar "' + name + '" como hijo de un hermano del mismo nivel:');
        $('#category-sibling-modal').addClass('show');
    }

    $('#categories-add-root').on('click', function() {
        openCategoryModal(0);
    });

    $('#categories-expand-all').on('click', function() {
        $('#categories-tree .cat-mgmt-children').show();
        $('#categories-tree .cat-branch-toggle').attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
    });

    $('#categories-collapse-all').on('click', function() {
        $('#categories-tree .cat-mgmt-children').hide();
        $('#categories-tree .cat-branch-toggle').attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
    });

    $('#category-modal-cancel').on('click', function() {
        $('#category-modal').removeClass('show');
    });

    $('#category-modal-save').on('click', function() {
        const name = $('#category-modal-name').val().trim();
        const parentId = parseInt($('#category-modal-parent').val() || 0, 10);
        if (!name) {
            alert('El nombre es requerido');
            return;
        }
        $.post(ajaxurl, {
            action: 'riverso_products_create_category',
            nonce: nonce,
            name: name,
            parent_id: parentId
        }, function(r) {
            if (r.success) {
                $('#category-modal').removeClass('show');
                loadCategories();
            } else {
                alert('Error: ' + (r.data?.message || 'No se pudo crear'));
            }
        });
    });

    $('#category-move-cancel').on('click', function() {
        $('#category-move-modal').removeClass('show');
        pendingMoveCategory = null;
    });

    $('#category-move-confirm').on('click', function() {
        if (!pendingMoveCategory) return;
        const newParentId = parseInt($('#category-move-parent').val() || 0, 10);
        const termId = pendingMoveCategory;
        $.post(ajaxurl, {
            action: 'riverso_products_move_category',
            nonce: nonce,
            term_id: termId,
            new_parent_id: newParentId
        }, function(r) {
            if (r.success) {
                $('#category-move-modal').removeClass('show');
                pendingMoveCategory = null;
                loadCategories();
            } else {
                alert('Error: ' + (r.data?.message || 'No se pudo mover'));
            }
        });
    });

    $('#category-sibling-cancel').on('click', function() {
        $('#category-sibling-modal').removeClass('show');
        pendingSiblingMove = null;
    });

    $('#category-sibling-confirm').on('click', function() {
        if (!pendingSiblingMove) return;
        const termId = pendingSiblingMove;
        const newParentId = parseInt($('#category-sibling-parent').val() || 0, 10);
        if (!newParentId) {
            alert('Selecciona un hermano');
            return;
        }
        $('#category-sibling-modal').removeClass('show');
        pendingSiblingMove = null;
        moveCategory(termId, newParentId);
    });

    function openDeleteCategoryModal(termId) {
        pendingDeleteCategory = termId;
        $.post(ajaxurl, {
            action: 'riverso_products_category_impact',
            nonce: nonce,
            term_id: termId
        }, function(r) {
            if (r.success) {
                const data = r.data;
                let warningHtml = `
                    <p><strong>⚠️ Advertencia:</strong></p>
                    <ul>
                        <li>${data.direct_products} productos directos en esta categoría</li>
                        <li>${data.children_count} subcategorías</li>
                        <li><strong>${data.total_products} productos en total</strong> perderán esta categoría</li>
                    </ul>
                    <p>¿Estás seguro de que deseas eliminar esta categoría?</p>
                `;
                $('#category-delete-warning').html(warningHtml);
                $('#category-delete-modal').addClass('show');
            }
        });
    }

    $('#category-delete-cancel').on('click', function() {
        $('#category-delete-modal').removeClass('show');
        pendingDeleteCategory = null;
    });

    $('#category-delete-confirm').on('click', function() {
        if (!pendingDeleteCategory) return;
        $.post(ajaxurl, {
            action: 'riverso_products_delete_category',
            nonce: nonce,
            term_id: pendingDeleteCategory
        }, function(r) {
            if (r.success) {
                $('#category-delete-modal').removeClass('show');
                pendingDeleteCategory = null;
                loadCategories();
            } else {
                alert('Error: ' + (r.data?.message || 'No se pudo eliminar'));
            }
        });
    });

    // ===== FAMILIAS =====
    function loadFamilies() {
        $.post(ajaxurl, {
            action: 'riverso_families_list',
            nonce: nonce
        }, function(r) {
            if (r.success && r.data.families) {
                familiesList = r.data.families || [];
                renderFamiliesList(familiesList);
            }
        });
    }

    function renderFamiliesList(families) {
        if (!families || families.length === 0) {
            $('#families-list').html('<p style="color:#999; text-align:center;">Sin familias</p>');
            return;
        }

        let html = families.map(fam => `
            <div class="family-item" data-family-id="${fam.id}">
                <div>
                    <strong>${esc(fam.nombre)}</strong><br>
                    <small style="color:#666;">Tipo: ${esc(fam.tipo_sustitucion || '-')} | Miembros: ${fam.miembros_count || 0}</small>
                </div>
                <div style="display:flex; gap:4px;">
                    <button class="button button-small family-view" data-family-id="${fam.id}">Ver</button>
                    <button class="button button-small family-edit" data-family-id="${fam.id}">Editar</button>
                </div>
            </div>
        `).join('');

        $('#families-list').html(html);
        attachFamilyEventListeners();
    }

    function attachFamilyEventListeners() {
        $(document).on('click', '.family-view', function(e) {
            e.preventDefault();
            const familyId = $(this).data('family-id');
            openFamilyDetailsModal(familyId);
        });

        $(document).on('click', '.family-edit', function(e) {
            e.preventDefault();
            const familyId = $(this).data('family-id');
            openFamilyEditModal(familyId);
        });
    }

    function openFamilyDetailsModal(familyId) {
        $.post(ajaxurl, {
            action: 'riverso_families_get',
            nonce: nonce,
            grupo_id: familyId
        }, function(r) {
            if (r.success && r.data.family) {
                const fam = r.data.family;
                const members = fam.members || [];
                let membersHtml = '';
                if (members.length > 0) {
                    membersHtml = '<ul style="margin:10px 0;">' + members.map(m => 
                        `<li>${esc(m.nombre_canonico || '-')} (SKU: ${esc(m.canonical_sku || '-')})</li>`
                    ).join('') + '</ul>';
                } else {
                    membersHtml = '<p style="color:#999;">Sin miembros</p>';
                }
                
                const modalHtml = `
                    <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;">
                        <div style="background:white; padding:20px; border-radius:6px; max-width:500px; width:90%; max-height:80vh; overflow-y:auto;">
                            <h3>${esc(fam.nombre)}</h3>
                            <p><strong>Código:</strong> ${esc(fam.codigo_grupo)}</p>
                            <p><strong>Tipo:</strong> ${esc(fam.tipo_sustitucion || '-')}</p>
                            <p><strong>Miembros:</strong></p>
                            ${membersHtml}
                            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:16px;">
                                <button class="button" onclick="this.closest('div').parentElement.remove();">Cerrar</button>
                            </div>
                        </div>
                    </div>
                `;
                $('body').append(modalHtml);
            }
        });
    }

    function openFamilyEditModal(familyId) {
        $.post(ajaxurl, {
            action: 'riverso_families_get',
            nonce: nonce,
            grupo_id: familyId
        }, function(r) {
            if (r.success && r.data.family) {
                const fam = r.data.family;
                const modalHtml = `
                    <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;">
                        <div style="background:white; padding:20px; border-radius:6px; max-width:500px; width:90%; max-height:80vh; overflow-y:auto;">
                            <h3>Editar Familia</h3>
                            <label style="display:block; margin-bottom:10px;">
                                <strong>Nombre:</strong><br>
                                <input type="text" class="large-text family-edit-nombre" value="${esc(fam.nombre)}" style="width:100%; padding:6px; box-sizing:border-box;">
                            </label>
                            <label style="display:block; margin-bottom:10px;">
                                <strong>Código:</strong><br>
                                <input type="text" class="large-text family-edit-codigo" value="${esc(fam.codigo_grupo)}" style="width:100%; padding:6px; box-sizing:border-box;" disabled>
                            </label>
                            <label style="display:block; margin-bottom:16px;">
                                <strong>Tipo de Sustitución:</strong><br>
                                <select class="family-edit-tipo" style="width:100%; padding:6px; box-sizing:border-box;">
                                    <option value="exacta" ${fam.tipo_sustitucion === 'exacta' ? 'selected' : ''}>Exacta</option>
                                    <option value="preferida" ${fam.tipo_sustitucion === 'preferida' ? 'selected' : ''}>Preferida</option>
                                    <option value="complementaria" ${fam.tipo_sustitucion === 'complementaria' ? 'selected' : ''}>Complementaria</option>
                                </select>
                            </label>
                            <div style="display:flex; gap:8px; justify-content:flex-end;">
                                <button class="button" onclick="this.closest('div').parentElement.remove();">Cancelar</button>
                                <button class="button button-primary family-edit-save" data-family-id="${fam.id}">Guardar</button>
                            </div>
                        </div>
                    </div>
                `;
                const $modal = $(modalHtml);
                $('body').append($modal);
                
                $modal.find('.family-edit-save').on('click', function() {
                    const nombre = $modal.find('.family-edit-nombre').val().trim();
                    const tipo = $modal.find('.family-edit-tipo').val();
                    if (!nombre) {
                        alert('El nombre es requerido');
                        return;
                    }
                    $.post(ajaxurl, {
                        action: 'riverso_families_update',
                        nonce: nonce,
                        grupo_id: familyId,
                        nombre: nombre,
                        tipo_sustitucion: tipo
                    }, function(r) {
                        if (r.success) {
                            $modal.parent().remove();
                            loadFamilies();
                        } else {
                            alert('Error: ' + (r.data?.message || 'No se pudo guardar'));
                        }
                    });
                });
            }
        });
    }

    $('#families-add-new').on('click', function() {
        const modalHtml = `
            <div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;">
                <div style="background:white; padding:20px; border-radius:6px; max-width:500px; width:90%;">
                    <h3>Crear Nueva Familia</h3>
                    <label style="display:block; margin-bottom:10px;">
                        <strong>Nombre:</strong><br>
                        <input type="text" id="new-family-nombre" class="large-text" placeholder="Ej. Tuercas M6" style="width:100%; padding:6px; box-sizing:border-box;">
                    </label>
                    <label style="display:block; margin-bottom:10px;">
                        <strong>Código:</strong><br>
                        <input type="text" id="new-family-codigo" class="large-text" placeholder="Ej. TUERCAS_M6" style="width:100%; padding:6px; box-sizing:border-box;">
                    </label>
                    <label style="display:block; margin-bottom:16px;">
                        <strong>Tipo de Sustitución:</strong><br>
                        <select id="new-family-tipo" style="width:100%; padding:6px; box-sizing:border-box;">
                            <option value="exacta">Exacta</option>
                            <option value="preferida">Preferida</option>
                            <option value="complementaria">Complementaria</option>
                        </select>
                    </label>
                    <div style="display:flex; gap:8px; justify-content:flex-end;">
                        <button class="button" onclick="this.closest('div').parentElement.remove();">Cancelar</button>
                        <button class="button button-primary" id="new-family-save">Crear</button>
                    </div>
                </div>
            </div>
        `;
        const $modal = $(modalHtml);
        $('body').append($modal);
        
        $modal.find('#new-family-save').on('click', function() {
            const nombre = $modal.find('#new-family-nombre').val().trim();
            const codigo = $modal.find('#new-family-codigo').val().trim();
            const tipo = $modal.find('#new-family-tipo').val();
            if (!nombre || !codigo) {
                alert('Nombre y código son requeridos');
                return;
            }
            $.post(ajaxurl, {
                action: 'riverso_families_create',
                nonce: nonce,
                nombre: nombre,
                codigo_grupo: codigo,
                tipo_sustitucion: tipo
            }, function(r) {
                if (r.success) {
                    $modal.parent().remove();
                    loadFamilies();
                } else {
                    alert('Error: ' + (r.data?.message || 'No se pudo crear'));
                }
            });
        });
    });

    // Cargar categorías inicialmente
    loadCategories();
});
</script>
