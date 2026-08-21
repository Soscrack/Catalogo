<?php
/**
 * Sección de Categorías y Familias del Portal Interno
 * Incluida desde portal-main.php
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verificar permisos
if (!current_user_can('riverso_view_categories') && !current_user_can('riverso_view_families')) {
    echo '<div style="padding:20px; color:#d32f2f;">Sin permisos para acceder a esta sección</div>';
    return;
}

$can_manage_categories = current_user_can('riverso_manage_categories');
$can_manage_families = current_user_can('riverso_manage_families');
?>

<div class="portal-categories-section">
    <style>
        .portal-categories-section { max-width: 1200px; margin: 0 auto; }
        .cat-tabs { display: flex; gap: 10px; border-bottom: 2px solid #eee; margin-bottom: 20px; position: relative; z-index: 3; }
        .cat-tabs button { padding: 10px 15px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 16px; color: #666; }
        .cat-tabs button.active { border-bottom-color: #2196f3; color: #2196f3; font-weight: bold; }
        .cat-tab-content { display: none; }
        .cat-tab-content.active { display: block; }
        .cat-tree { background: white; border-radius: 4px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .cat-mgmt-node { margin-bottom: 4px; }
        .cat-mgmt-row { display: flex; align-items: center; gap: 6px; }
        .cat-node { margin: 0; padding: 8px; background: #f9f9f9; border-radius: 3px; display: flex; justify-content: space-between; align-items: center; flex: 1; }
        .cat-node-name { flex: 1; }
        .cat-node-actions { display: flex; gap: 5px; }
        .cat-branch-toggle {
            width: 22px; height: 22px; flex-shrink: 0; border: 1px solid #ccc; border-radius: 3px;
            background: #fff; cursor: pointer; padding: 0; font-size: 10px; line-height: 20px; color: #555;
        }
        .cat-branch-toggle:hover { background: #f0f0f0; }
        .cat-branch-spacer { width: 22px; flex-shrink: 0; display: inline-block; }
        .btn-tiny { padding: 4px 8px; background: #2196f3; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 12px; }
        .btn-tiny:hover { background: #1976d2; }
        .btn-tiny.danger { background: #d32f2f; }
        .btn-tiny.danger:hover { background: #b71c1c; }
        .btn-tiny.secondary { background: #666; }
        .btn-tiny.secondary:hover { background: #555; }
        .family-list { display: grid; gap: 15px; }
        .family-card { background: white; padding: 15px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .family-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .family-name { font-weight: bold; font-size: 15px; }
        .family-count { background: #e0e0e0; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
        .family-actions { display: flex; gap: 5px; }
    </style>

    <div style="margin-bottom: 20px;">
        <h2 style="margin: 0 0 15px 0;">Categorías y Familias</h2>

        <div class="cat-tabs">
            <button type="button" class="active" data-tab="categories">Categorías</button>
            <button type="button" data-tab="families">Familias</button>
        </div>

        <!-- TAB: CATEGORÍAS -->
        <div class="cat-tab-content active" data-tab="categories">
            <div class="cat-tree">
                <div style="margin-bottom: 15px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php if ($can_manage_categories): ?>
                    <button class="btn-tiny" id="btn-cat-create" style="background: #28a745;">+ Nueva Categoría Raíz</button>
                    <?php endif; ?>
                    <button class="btn-tiny" id="btn-cat-expand-all" style="background: #666;">Expandir todo</button>
                    <button class="btn-tiny" id="btn-cat-collapse-all" style="background: #666;">Colapsar todo</button>
                </div>
                <div id="categories-tree" style="min-height: 100px;">
                    <p style="color: #999; text-align: center; padding: 20px;">Cargando categorías...</p>
                </div>
            </div>
        </div>

        <!-- TAB: FAMILIAS -->
        <div class="cat-tab-content" data-tab="families">
            <div style="margin-bottom: 15px;">
                <?php if ($can_manage_families): ?>
                <button class="btn-tiny" id="btn-family-create" style="background: #28a745;">+ Nueva Familia</button>
                <?php endif; ?>
            </div>
            <div class="family-list" id="families-list">
                <p style="color: #999; text-align: center; padding: 20px;">Cargando familias...</p>
            </div>
        </div>
    </div>
</div>

<script>
(window.riversoWhenJQuery || function(fn){ jQuery(fn); })(function($) {
    const nonce = '<?php echo esc_js($nonce); ?>';
    const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    const canManageCategories = <?php echo $can_manage_categories ? 'true' : 'false'; ?>;
    const canManageFamilies = <?php echo $can_manage_families ? 'true' : 'false'; ?>;

    function esc(v) { return $('<div>').text(v === null || v === undefined ? '' : v).html(); }

    function post(action, data) {
        return $.post(ajaxUrl, { action, nonce, ...data });
    }

    window.riversoFamilyEditor = window.riversoFamilyEditor || {};
    window.riversoFamilyEditor.ajaxUrl = ajaxUrl;
    window.riversoFamilyEditor.nonce = nonce;
    window.riversoFamilyEditor.canManage = canManageFamilies;
    window.riversoFamilyEditor.onChanged = function() { loadFamilies(); };

    function ensureFamilyEditor() {
        if (!window.RiversoFamilyEditor) {
            alert('Editor de familias no cargado. Recarga la página (Ctrl+F5).');
            return false;
        }
        return true;
    }

    function loadCategories() {
        post('riverso_products_get_category_tree').done(function(r) {
            if (!r.success) {
                $('#categories-tree').html('<div style="color: #d32f2f; padding: 20px;">Error al cargar categorías</div>');
                return;
            }

            const renderTree = (cats, level) => {
                let html = '';
                (cats || []).forEach(cat => {
                    const hasChildren = !!(cat.children && cat.children.length);
                    const toggleHtml = hasChildren
                        ? '<button type="button" class="cat-branch-toggle" aria-expanded="false" title="Mostrar rama">▶</button>'
                        : '<span class="cat-branch-spacer"></span>';

                    html += '<div class="cat-mgmt-node" data-term-id="' + cat.id + '">';
                    html += '<div class="cat-mgmt-row">';
                    html += toggleHtml;
                    html += '<div class="cat-node" style="margin-left:' + (level * 12) + 'px;">';
                    html += '<div class="cat-node-name">' + esc(cat.name) + ' <small style="color: #999;">(' + (cat.count || 0) + ')</small></div>';
                    html += '<div class="cat-node-actions">';
                    if (canManageCategories) {
                        html += '<button class="btn-tiny" onclick="window.portalCat.renameCategory(' + cat.id + ')">Renombrar</button>';
                        html += '<button class="btn-tiny danger" onclick="window.portalCat.deleteCategory(' + cat.id + ')">Eliminar</button>';
                    }
                    html += '</div></div></div>';
                    if (hasChildren) {
                        html += '<div class="cat-mgmt-children" style="display:none;">' + renderTree(cat.children, level + 1) + '</div>';
                    }
                    html += '</div>';
                });
                return html;
            };

            $('#categories-tree').html(renderTree(r.data.tree || [], 0) || '<p style="color:#999;text-align:center;padding:20px;">Sin categorías</p>');
        });
    }

    function loadFamilies() {
        post('riverso_families_list').done(function(r) {
            if (!r.success) {
                $('#families-list').html('<div style="color: #d32f2f; padding: 20px;">Error al cargar familias</div>');
                return;
            }

            const families = r.data.families || [];
            let html = '';
            
            if (families.length === 0) {
                html = '<p style="color: #999; text-align: center; padding: 20px;">Sin familias</p>';
            } else {
                families.forEach(f => {
                    const stock = (f.stock_unidades !== undefined && f.stock_unidades !== null)
                        ? Number(f.stock_unidades).toLocaleString('es-CL') + ' u'
                        : '—';
                    html += '<div class="family-card">';
                    html += '<div class="family-header">';
                    html += '<div><div class="family-name">' + esc(f.nombre) + '</div><small style="color: #999;">' + esc(f.codigo_grupo) + ' · ' + esc(f.tipo_sustitucion || '') + '</small></div>';
                    html += '<div><span class="family-count">' + (f.miembros_count || 0) + ' miembros</span><br><small>Stock: ' + stock + '</small></div>';
                    html += '</div>';
                    html += '<div class="family-actions">';
                    html += '<button type="button" class="btn-tiny secondary" data-family-view="' + f.id + '">Ver</button>';
                    if (canManageFamilies) {
                        html += '<button type="button" class="btn-tiny" data-family-edit="' + f.id + '">Editar</button>';
                    }
                    html += '</div>';
                    html += '</div>';
                });
            }

            $('#families-list').html(html);
        });
    }

    $('.cat-tabs button').click(function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.cat-tabs button').removeClass('active');
        $(this).addClass('active');
        $('.cat-tab-content').removeClass('active');
        $('.cat-tab-content[data-tab="' + tab + '"]').addClass('active');
        
        if (tab === 'families') {
            loadFamilies();
        } else {
            loadCategories();
        }
    });

    $(document).on('click', '#categories-tree .cat-branch-toggle', function(e) {
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

    $(document).on('click', '[data-family-view]', function(e) {
        e.preventDefault();
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openView($(this).data('family-view'));
        }
    });

    $(document).on('click', '[data-family-edit]', function(e) {
        e.preventDefault();
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openEdit($(this).data('family-edit'));
        }
    });

    $('#btn-cat-expand-all').click(function() {
        $('#categories-tree .cat-mgmt-children').show();
        $('#categories-tree .cat-branch-toggle').attr('aria-expanded', 'true').attr('title', 'Ocultar rama').text('▼');
    });

    $('#btn-cat-collapse-all').click(function() {
        $('#categories-tree .cat-mgmt-children').hide();
        $('#categories-tree .cat-branch-toggle').attr('aria-expanded', 'false').attr('title', 'Mostrar rama').text('▶');
    });

    loadCategories();

    window.portalCat = {
        renameCategory: function(catId) {
            const newName = prompt('Nuevo nombre de categoría:');
            if (newName) {
                post('riverso_products_rename_category', { term_id: catId, name: newName }).done(function(r) {
                    if (r.success) {
                        loadCategories();
                    } else {
                        alert('Error: ' + r.data.message);
                    }
                });
            }
        },
        deleteCategory: function(catId) {
            if (confirm('¿Está seguro de que desea eliminar esta categoría?')) {
                post('riverso_products_delete_category', { term_id: catId }).done(function(r) {
                    if (r.success) {
                        loadCategories();
                    } else {
                        alert('Error: ' + r.data.message);
                    }
                });
            }
        },
        editFamily: function(familyId) {
            if (ensureFamilyEditor()) {
                RiversoFamilyEditor.openEdit(familyId);
            }
        },
        viewFamily: function(familyId) {
            if (ensureFamilyEditor()) {
                RiversoFamilyEditor.openView(familyId);
            }
        }
    };

    $('#btn-cat-create').click(() => {
        const name = prompt('Nombre de la nueva categoría:');
        if (name) {
            post('riverso_products_create_category', { name: name, parent_id: 0 }).done(function(r) {
                if (r.success) {
                    loadCategories();
                } else {
                    alert('Error: ' + r.data.message);
                }
            });
        }
    });

    $('#btn-family-create').click(() => {
        if (ensureFamilyEditor()) {
            RiversoFamilyEditor.openCreate(function() { loadFamilies(); });
        }
    });
});
</script>
