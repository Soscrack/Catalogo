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
        
        <div style="margin-bottom:12px;">
            <button class="button button-primary" id="categories-add-root">+ Categoría Raíz</button>
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
    padding: 8px;
    border-left: 2px solid #ddd;
    margin-left: 20px;
    margin-bottom: 4px;
    background: white;
    border-radius: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.category-tree-item.root {
    margin-left: 0;
    border-left: none;
    background: #f5f5f5;
    font-weight: bold;
}
.category-tree-actions {
    display: flex;
    gap: 4px;
    margin-left: auto;
}
.category-tree-actions button {
    padding: 4px 8px;
    font-size: 12px;
}
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
#category-modal, #category-delete-modal {
    display: none !important;
}
#category-modal.show, #category-delete-modal.show {
    display: flex !important;
}
</style>

<script>
jQuery(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    let categoriesTree = [];
    let familiesList = [];
    let pendingDeleteCategory = null;

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
            return;
        }

        let html = '';
        cats.forEach(cat => {
            const isRoot = cat.parent === 0 || !cat.parent;
            const padding = isRoot ? 0 : indent * 20;
            html += `<div class="category-tree-item ${isRoot ? 'root' : ''}" style="margin-left:${padding}px;" data-term-id="${cat.id}">
                <span style="flex:1;">${esc(cat.name)}</span>
                <div class="category-tree-actions">
                    <button class="button button-small category-add-child" data-parent-id="${cat.id}">+</button>
                    <button class="button button-small category-rename" data-term-id="${cat.id}">✎</button>
                    <button class="button button-small category-delete" data-term-id="${cat.id}">🗑</button>
                </div>
            </div>`;
            
            // Renderizar hijos recursivamente
            if (cat.children && cat.children.length > 0) {
                const childHtml = renderCategoriesTreeRecursive(cat.children, indent + 1);
                html += childHtml;
            }
        });

        if (indent === 0) {
            $('#categories-tree').html(html);
            attachCategoryEventListeners();
        }
        return html;
    }

    function renderCategoriesTreeRecursive(cats, indent) {
        let html = '';
        cats.forEach(cat => {
            const padding = indent * 20;
            html += `<div class="category-tree-item" style="margin-left:${padding}px;" data-term-id="${cat.id}">
                <span style="flex:1;">${esc(cat.name)}</span>
                <div class="category-tree-actions">
                    <button class="button button-small category-add-child" data-parent-id="${cat.id}">+</button>
                    <button class="button button-small category-rename" data-term-id="${cat.id}">✎</button>
                    <button class="button button-small category-delete" data-term-id="${cat.id}">🗑</button>
                </div>
            </div>`;
            
            if (cat.children && cat.children.length > 0) {
                html += renderCategoriesTreeRecursive(cat.children, indent + 1);
            }
        });
        return html;
    }

    function attachCategoryEventListeners() {
        // Agregar hijo
        $(document).on('click', '.category-add-child', function(e) {
            e.preventDefault();
            const parentId = $(this).data('parent-id');
            openCategoryModal(parentId);
        });

        // Renombrar
        $(document).on('click', '.category-rename', function(e) {
            e.preventDefault();
            const termId = $(this).data('term-id');
            const currentName = $(this).closest('.category-tree-item').find('span:first').text();
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

        // Eliminar
        $(document).on('click', '.category-delete', function(e) {
            e.preventDefault();
            const termId = $(this).data('term-id');
            openDeleteCategoryModal(termId);
        });
    }

    function openCategoryModal(parentId = 0) {
        $('#category-modal-name').val('');
        $('#category-modal-parent').val(parentId);
        populateCategoryParentDropdown('#category-modal-parent');
        $('#category-modal').addClass('show');
    }

    function populateCategoryParentDropdown(selector) {
        // Generar opciones a partir de categoriesTree
        let options = '<option value="0">Sin padre (raíz)</option>';
        function addOptions(cats) {
            cats.forEach(cat => {
                options += `<option value="${cat.id}">${esc(cat.name)}</option>`;
                if (cat.children && cat.children.length > 0) {
                    addOptions(cat.children);
                }
            });
        }
        addOptions(categoriesTree);
        $(selector).html(options);
    }

    $('#categories-add-root').on('click', function() {
        openCategoryModal(0);
    });

    $('#category-modal-cancel').on('click', function() {
        $('#category-modal').removeClass('show');
    });

    $('#category-modal-save').on('click', function() {
        const name = $('#category-modal-name').val().trim();
        const parentId = parseInt($('#category-modal-parent').val() || 0);
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
