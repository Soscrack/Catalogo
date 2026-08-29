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
        <p style="color:#666; margin-bottom:16px;">Vista global de familias y grupos de equivalencia. Gestiona miembros, crea nuevas familias y edita sus propiedades. Tipo <strong>exacta</strong> = mismo ítem, distinto envase.</p>
        
        <div style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <button class="button button-primary" id="families-add-new">+ Nueva Familia</button>
            <button class="button" id="families-suggest-mamut">Sugerir desde Mamut</button>
        </div>

        <div id="families-suggestions" style="display:none; border:1px solid #90caf9; background:#e3f2fd; padding:12px; border-radius:4px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; gap:8px; flex-wrap:wrap;">
                <strong>Sugerencias Mamut (revisión humana)</strong>
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="search" id="families-suggestions-search" placeholder="Buscar código o nombre (ej. 02TADB)" style="min-width:220px; padding:4px 8px;">
                    <button type="button" class="button button-small" id="families-suggestions-close">Cerrar</button>
                </div>
            </div>
            <div id="families-suggestions-stats" style="font-size:12px; color:#555; margin-bottom:8px;"></div>
            <div id="families-suggestions-list" style="max-height:360px; overflow-y:auto;"></div>
        </div>

        <div style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <input type="search" id="families-search" placeholder="Nombre, miembro, SKU, código proveedor o barcode" style="min-width:280px; padding:6px 10px; flex:1; max-width:480px;" autocomplete="off">
            <span id="families-search-count" style="font-size:12px; color:#666;"></span>
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
    flex-direction: column;
    align-items: stretch;
    gap: 0;
}
.family-item-main {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    width: 100%;
    gap: 8px;
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
    let familiesSearchTimer = null;
    let familiesSearchQuery = '';

    function loadFamilies(search) {
        const q = search !== undefined ? search : familiesSearchQuery;
        familiesSearchQuery = (q || '').trim();
        $.post(ajaxurl, {
            action: 'riverso_families_list',
            nonce: nonce,
            search: familiesSearchQuery
        }, function(r) {
            if (r.success && r.data.families) {
                familiesList = r.data.families || [];
                renderFamiliesList(familiesList, familiesSearchQuery);
                const total = familiesList.length;
                const label = familiesSearchQuery
                    ? total + ' coincidencia' + (total !== 1 ? 's' : '')
                    : total + ' familia' + (total !== 1 ? 's' : '');
                $('#families-search-count').text(label);
            }
        });
    }

    function highlightSearchTerm(text, query) {
        const raw = text === null || text === undefined ? '' : String(text);
        const escaped = esc(raw || '—');
        const q = (query || '').trim();
        if (!q) return escaped;
        try {
            const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            return escaped.replace(re, '<mark style="background:#fff59d;padding:0 2px;">$1</mark>');
        } catch (e) {
            return escaped;
        }
    }

    function renderFamilySkusInline(members, search) {
        const list = members || [];
        if (!list.length) return '';
        const locals = list.map(m => highlightSearchTerm(m.sku_local || m.canonical_sku || '—', search)).join(', ');
        const onlines = list.map(m => highlightSearchTerm(m.sku_online || '—', search)).join(', ');
        return ' | <span class="riverso-fp-inline-skus">' +
            '<span class="sku-local" style="color:#1565c0;">Local: <code style="color:#1565c0;">' + locals + '</code></span> | ' +
            '<span class="sku-online" style="color:#2e7d32;">Online: <code style="color:#2e7d32;">' + onlines + '</code></span>' +
            '</span>';
    }

    function renderFamilyPreview(members, search) {
        if (window.RiversoFamilyEditor && typeof RiversoFamilyEditor.renderListPreview === 'function') {
            return RiversoFamilyEditor.renderListPreview(members || [], {
                expanded: !!search,
                search: search || ''
            });
        }
        return '';
    }

    function renderFamiliesList(families, search) {
        search = search || '';
        if (!families || families.length === 0) {
            const msg = search
                ? 'Sin coincidencias para «' + esc(search) + '»'
                : 'Sin familias';
            $('#families-list').html('<p style="color:#999; text-align:center;">' + msg + '</p>');
            return;
        }

        let html = families.map(fam => {
            const stock = (fam.stock_unidades !== undefined && fam.stock_unidades !== null)
                ? Number(fam.stock_unidades).toLocaleString('es-CL')
                : '—';
            const warn = (fam.stock_warnings && fam.stock_warnings.length)
                ? ` <span title="${esc((fam.stock_warnings || []).join(' | '))}" style="color:#e65100;">⚠</span>`
                : '';
            const unitSku = fam.unit_sku
                ? ` | Unitario: <code>${esc(fam.unit_sku)}</code>`
                : '';
            const memberSkus = renderFamilySkusInline(fam.members, search);
            const preview = renderFamilyPreview(fam.members, search);
            return `
            <div class="family-item" data-family-id="${fam.id}">
                <div class="family-item-main">
                    <div>
                        <strong>${esc(fam.nombre)}</strong><br>
                        <small style="color:#666;">Tipo: ${esc(fam.tipo_sustitucion || '-')} | Miembros: ${fam.miembros_count || 0} | Stock familia: ${stock} u${warn}${unitSku}${memberSkus}</small>
                    </div>
                    <div style="display:flex; gap:4px; flex-shrink:0;">
                        <button class="button button-small family-view" data-family-id="${fam.id}">Ver</button>
                        <button class="button button-small family-edit" data-family-id="${fam.id}">Editar</button>
                        <button class="button button-small family-delete" data-family-id="${fam.id}" data-family-name="${esc(fam.nombre || '')}" data-members="${fam.miembros_count || 0}" title="Eliminar" style="color:#b71c1c;">🗑</button>
                    </div>
                </div>
                ${preview}
            </div>`;
        }).join('');

        $('#families-list').html(html);
    }

    $(document).off('click.riversoFpToggle').on('click.riversoFpToggle', '.riverso-fp-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $wrap = $(this).closest('.riverso-family-list-preview');
        const $ul = $wrap.find('.riverso-fp-members');
        const open = !$ul.is(':visible');
        $ul.toggle(open);
        const count = $wrap.find('.riverso-fp-member').length;
        $(this).text((open ? '▼' : '▶') + ' ' + count + ' miembro(s)');
        $wrap.attr('data-expanded', open ? '1' : '0');
    });

    $('#families-search').on('input', function() {
        clearTimeout(familiesSearchTimer);
        const val = $(this).val().trim();
        familiesSearchTimer = setTimeout(function() {
            loadFamilies(val);
        }, 300);
    });

    // Editor compartido (assets/js/family-editor.js)
    window.riversoFamilyEditor = window.riversoFamilyEditor || {};
    window.riversoFamilyEditor.ajaxUrl = ajaxurl;
    window.riversoFamilyEditor.nonce = nonce;
    window.riversoFamilyEditor.canManage = true;
    window.riversoFamilyEditor.onChanged = function() { loadFamilies(); };

    function ensureFamilyEditor() {
        if (!window.RiversoFamilyEditor) {
            alert('Editor de familias no cargado. Recarga la página.');
            return false;
        }
        return true;
    }

    $(document).off('click.riversoFamilyView').on('click.riversoFamilyView', '.family-view', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openView($(this).data('family-id'));
        }
    });
    $(document).off('click.riversoFamilyEdit').on('click.riversoFamilyEdit', '.family-edit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openEdit($(this).data('family-id'));
        }
    });

    $(document).off('click.riversoFamilyDelete').on('click.riversoFamilyDelete', '.family-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const id = $(this).data('family-id');
        const nombre = $(this).data('family-name') || ('#' + id);
        const members = parseInt($(this).data('members') || 0, 10);
        if (!confirm(
            '¿Eliminar la familia «' + nombre + '»?\n\n' +
            'Se desactivará y se quitarán ' + members + ' miembro(s).\n' +
            'Los productos no se borran.'
        )) {
            return;
        }
        const $btn = $(this).prop('disabled', true);
        $.post(ajaxurl, {
            action: 'riverso_families_delete',
            nonce: nonce,
            grupo_id: id
        }, function(r) {
            if (!r.success) {
                $btn.prop('disabled', false);
                alert((r.data && r.data.message) || 'No se pudo eliminar');
                return;
            }
            loadFamilies();
        }).fail(function() {
            $btn.prop('disabled', false);
            alert('Error de red');
        });
    });

    $('#families-add-new').on('click', function() {
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openCreate(function() { loadFamilies(); });
        }
    });

    let pendingSuggestions = [];

    function requestMamutSuggestions() {
        const $btn = $('#families-suggest-mamut').prop('disabled', true).text('Buscando…');
        const search = ($('#families-suggestions-search').val() || '').trim();
        $.post(ajaxurl, {
            action: 'riverso_families_suggest',
            nonce: nonce,
            limit: 300,
            search: search
        }, function(r) {
            $btn.prop('disabled', false).text('Sugerir desde Mamut');
            if (!r.success) {
                alert(r.data?.message || 'No se pudieron generar sugerencias');
                return;
            }
            pendingSuggestions = r.data.suggestions || [];
            const stats = r.data.stats || {};
            const total = stats.total_available != null ? stats.total_available : pendingSuggestions.length;
            $('#families-suggestions-stats').text(
                `Mostrando ${pendingSuggestions.length} de ${total}`
                + (search ? ` (filtro: ${search})` : '')
                + ` · Candidatos: ${stats.candidate_groups || 0}`
                + ` · Sin ≥2 SKU local: ${stats.skipped_unresolved || 0}`
                + ` · Ya en familia: ${stats.skipped_already_in_family || 0}`
            );
            renderFamilySuggestions(pendingSuggestions);
            $('#families-suggestions').show();
        }).fail(function() {
            $btn.prop('disabled', false).text('Sugerir desde Mamut');
            alert('Error de red al sugerir familias');
        });
    }

    $('#families-suggest-mamut').on('click', function() {
        requestMamutSuggestions();
    });

    $('#families-suggestions-search').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            requestMamutSuggestions();
        }
    });

    $('#families-suggestions-close').on('click', function() {
        $('#families-suggestions').hide();
    });

    function renderFamilySuggestions(list) {
        if (!list.length) {
            $('#families-suggestions-list').html('<p style="color:#666;">No hay sugerencias pendientes.</p>');
            return;
        }
        const confColor = { alta: '#2e7d32', media: '#ef6c00', baja: '#c62828' };
        let html = list.map((s, idx) => {
            const members = (s.members || []).map(m => {
                const sku = m.canonical_sku || 'sin SKU local';
                const ok = m.resolved ? '✓' : '✗';
                return `${ok} ${esc(m.codigo_proveedor)} → ${esc(sku)} (${m.cantidad_unidades} u)`;
            }).join('<br>');
            const canAccept = (s.member_count || 0) >= 2 && ((s.resolved_count || 0) >= 1 || (s.member_count || 0) >= 2);
            const resolveNote = (s.resolved_count || 0) < 2
                ? `<div style="font-size:11px;color:#ef6c00;margin-top:4px;">Resueltos ${s.resolved_count || 0}/${s.member_count || 0} SKU local. Al aceptar, los sin local quedan pendientes en la familia y entran al vincularlos.</div>`
                : '';
            return `<div class="family-suggestion" data-idx="${idx}" style="background:#fff;border:1px solid #bbdefb;border-radius:4px;padding:10px;margin-bottom:8px;">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;">
                    <div>
                        <strong>${esc(s.nombre)}</strong>
                        <span style="margin-left:8px;font-size:11px;padding:2px 6px;border-radius:3px;color:#fff;background:${confColor[s.confidence] || '#666'};">${esc(s.confidence || '')}</span>
                        ${s.woo_confirmed ? '<span style="font-size:11px;color:#1565c0;margin-left:6px;">Woo OK</span>' : ''}
                        <div style="font-size:12px;color:#555;margin-top:4px;">${members}</div>
                        ${resolveNote}
                    </div>
                    <button class="button button-primary button-small family-accept-suggestion" data-idx="${idx}" ${canAccept ? '' : 'disabled'}>Aceptar</button>
                </div>
            </div>`;
        }).join('');
        $('#families-suggestions-list').html(html);
    }

    $(document).on('click', '.family-accept-suggestion', function() {
        const idx = parseInt($(this).data('idx'), 10);
        const suggestion = pendingSuggestions[idx];
        if (!suggestion) return;
        if (!confirm('¿Crear familia exacta "' + suggestion.nombre + '" con envases?')) return;
        const $btn = $(this).prop('disabled', true).text('Creando…');
        $.post(ajaxurl, {
            action: 'riverso_families_accept_suggestion',
            nonce: nonce,
            suggestion: JSON.stringify(suggestion)
        }, function(r) {
            if (r.success) {
                pendingSuggestions.splice(idx, 1);
                renderFamilySuggestions(pendingSuggestions);
                loadFamilies();
                alert('Familia creada: ' + (r.data.family?.nombre || ''));
            } else {
                $btn.prop('disabled', false).text('Aceptar');
                alert(r.data?.message || 'No se pudo aceptar la sugerencia');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Aceptar');
            alert('Error de red');
        });
    });

    // Cargar categorías inicialmente
    loadCategories();
});
</script>
