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
        .family-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; gap: 8px; }
        .family-name { font-weight: bold; font-size: 15px; }
        .family-count { background: #e0e0e0; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
        .family-actions { display: flex; gap: 5px; flex-shrink: 0; }
        .families-search-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .families-search-row input[type="search"] { min-width: 240px; padding: 8px 10px; flex: 1; max-width: 480px; border: 1px solid #ccc; border-radius: 4px; }
        .families-search-count { font-size: 12px; color: #666; }
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
            <div class="families-search-row">
                <input type="search" id="families-search" placeholder="Nombre, miembro, SKU, código proveedor o barcode" autocomplete="off">
                <span id="families-search-count" class="families-search-count"></span>
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

    let familiesSearchTimer = null;
    let familiesSearchQuery = '';

    function loadFamilies(search) {
        const q = search !== undefined ? search : familiesSearchQuery;
        familiesSearchQuery = (q || '').trim();
        post('riverso_families_list', { search: familiesSearchQuery }).done(function(r) {
            if (!r.success) {
                $('#families-list').html('<div style="color: #d32f2f; padding: 20px;">Error al cargar familias</div>');
                return;
            }

            const families = r.data.families || [];
            const total = families.length;
            const label = familiesSearchQuery
                ? total + ' coincidencia' + (total !== 1 ? 's' : '')
                : total + ' familia' + (total !== 1 ? 's' : '');
            $('#families-search-count').text(label);

            let html = '';

            if (families.length === 0) {
                html = familiesSearchQuery
                    ? '<p style="color: #999; text-align: center; padding: 20px;">Sin coincidencias para «' + esc(familiesSearchQuery) + '»</p>'
                    : '<p style="color: #999; text-align: center; padding: 20px;">Sin familias</p>';
            } else {
                families.forEach(f => {
                    const stock = (f.stock_unidades !== undefined && f.stock_unidades !== null)
                        ? Number(f.stock_unidades).toLocaleString('es-CL') + ' u'
                        : '—';
                    const preview = renderFamilyPreview(f.members, familiesSearchQuery);
                    const memberSkus = renderFamilySkusInline(f.members, familiesSearchQuery);
                    html += '<div class="family-card">';
                    html += '<div class="family-header">';
                    html += '<div><div class="family-name">' + esc(f.nombre) + '</div>';
                    html += '<small style="color: #999;">Tipo: ' + esc(f.tipo_sustitucion || '-') + ' | Miembros: ' + (f.miembros_count || 0) + ' | Stock familia: ' + stock + memberSkus + '</small></div>';
                    html += '<div><span class="family-count">' + esc(f.codigo_grupo || '') + '</span></div>';
                    html += '</div>';
                    html += preview;
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

    $(document).on('click', '.riverso-fp-toggle', function(e) {
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
