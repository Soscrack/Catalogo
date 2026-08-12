# Hub de Productos - Fases 5-9 Roadmap

**Completado**: Versión 1.5.15 (Fases 1-4)
**Pendiente**: Fases 5-9

---

## Fase 5: Visualizador de Árbol de Familias

### Objetivo
Panel con árbol de todas las familias y sus miembros. Cada familia es desplegable y muestra miembros con nombre + SKU + badge preferido.

### Implementación Backend
- Ya existe: `riverso_families_tree()` endpoint que retorna árbol con `children`

### Implementación Frontend (templates/products.php)

#### 1. Agregar Tab "Familias"
```html
<a href="#" class="detail-tab" data-tab="families">Familias</a>
```

#### 2. Crear contenedor de familias
```html
<div class="detail-tab-content" id="tab-families" style="display:none;">
    <div id="family-tree" style="max-height:600px; overflow-y:auto;"></div>
    <div style="margin-top:12px;">
        <button class="button button-primary" id="family-create-btn">+ Nueva familia</button>
    </div>
    <div id="family-create-form" style="display:none; margin-top:12px; padding:12px; background:#f9f9f9; border-radius:4px;">
        <input type="text" id="family-codigo" placeholder="Código único" class="regular-text">
        <input type="text" id="family-nombre" placeholder="Nombre" class="regular-text">
        <select id="family-tipo">
            <option value="compatible">Compatible</option>
            <option value="sustituto">Sustituto</option>
            <option value="preferido">Preferido</option>
        </select>
        <button class="button button-primary" id="family-save-btn">Crear</button>
        <button class="button" id="family-cancel-btn">Cancelar</button>
    </div>
</div>
```

#### 3. Función renderFamilyTree()
```javascript
function renderFamilyTree(families) {
    const renderTree = (items, indent = 0) => items.map(family => {
        const membersList = (family.children || []).map(m => 
            `<div style="margin-left:${(indent+1)*20}px; padding:4px; background:#fafafa; border-radius:3px; margin:4px 0;">
                <span>${esc(m.nombre_canonico)}</span>
                <small style="color:#666;"> (${esc(m.canonical_sku || '-')})</small>
                ${m.es_reemplazo_preferido ? '<span style="background:#28a745;color:white;padding:2px 6px;border-radius:3px;font-size:11px;margin-left:8px;">Preferido</span>' : ''}
                <button class="button button-small" data-action="remove-member" data-member-id="${m.id}" style="margin-left:8px;">Quitar</button>
                <button class="button button-small" data-action="open-product" data-product-id="${m.producto_base_id}" style="margin-left:4px;">Ver</button>
            </div>`
        ).join('');
        
        return `<div style="margin-bottom:12px; padding:12px; background:#fff; border:1px solid #ddd; border-radius:4px;">
            <div style="cursor:pointer; font-weight:bold;">
                <span onclick="this.parentElement.nextElementSibling.style.display = 
                    this.parentElement.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                    ▸ ${esc(family.nombre)} (${family.children ? family.children.length : 0} miembros)
                </span>
            </div>
            <div class="family-members" style="display:none; margin-top:8px;">
                ${membersList}
                <button class="button button-small" data-action="add-member" data-family-id="${family.id}" style="margin-top:8px;">+ Agregar miembro</button>
            </div>
        </div>`;
    }).join('');
    
    $('#family-tree').html(renderTree(families));
}
```

#### 4. Event Listeners
```javascript
$('#family-create-btn').on('click', function() {
    $('#family-create-form').toggle();
});

$('#family-save-btn').on('click', function() {
    const codigo = $('#family-codigo').val();
    const nombre = $('#family-nombre').val();
    const tipo = $('#family-tipo').val();
    
    $.post(ajaxurl, {
        action: 'riverso_families_create',
        nonce,
        codigo_grupo: codigo,
        nombre: nombre,
        tipo_sustitucion: tipo
    }, function(r) {
        if (r.success) {
            alert('Familia creada');
            loadFamilyTree();
        }
    });
});

$(document).on('click', '[data-action="add-member"]', function() {
    // Modal o form para buscar producto y agregarlo
});

$(document).on('click', '[data-action="remove-member"]', function() {
    const memberId = $(this).data('member-id');
    $.post(ajaxurl, {
        action: 'riverso_families_remove_member',
        nonce,
        member_id: memberId
    }, function(r) {
        if (r.success) loadFamilyTree();
    });
});

function loadFamilyTree() {
    $.post(ajaxurl, {
        action: 'riverso_families_tree',
        nonce
    }, function(r) {
        if (r.success) renderFamilyTree(r.data.tree);
    });
}
```

---

## Fase 6: Árbol de Categorías Online

### Objetivo
Mostrar árbol de `product_cat` WooCommerce dentro del tab Online con checkboxes para asignar/desasignar.

### Backend Endpoints (class-product-module.php)

```php
public function ajax_get_product_categories() {
    $woo_id = absint($_POST['woocommerce_product_id'] ?? 0);
    if (!$woo_id) wp_send_json_error(['message' => 'woo_id requerido']);
    
    $product = wc_get_product($woo_id);
    if (!$product) wp_send_json_error(['message' => 'Producto no encontrado']);
    
    $current_cats = wp_get_post_terms($woo_id, 'product_cat', ['fields' => 'ids']);
    wp_send_json_success(['current_categories' => $current_cats]);
}

public function ajax_set_product_categories() {
    $woo_id = absint($_POST['woocommerce_product_id'] ?? 0);
    $cat_ids = array_map('absint', (array) $_POST['category_ids'] ?? []);
    
    wp_set_object_terms($woo_id, $cat_ids, 'product_cat');
    wp_send_json_success(['message' => 'Categorías asignadas']);
}
```

### Frontend (templates/products.php)

#### Agregar sección en renderOnlineDetails():
```javascript
// Dentro de renderOnlineDetails()
if (onlineDetails.type === 'simple' || onlineDetails.type === 'variation') {
    html += '<div style="margin-bottom:20px;">';
    html += '<h5>Categorías WooCommerce</h5>';
    html += '<div id="online-categories-tree" style="border:1px solid #ddd; padding:12px; border-radius:4px;"></div>';
    html += '<button class="button button-primary" id="online-categories-save" style="margin-top:8px;">Guardar categorías</button>';
    html += '</div>';
}
```

#### Función renderCategoryTree():
```javascript
function loadCategoryTree(wooId) {
    $.post(ajaxurl, {
        action: 'riverso_category_tree',
        nonce,
        parent_id: 0
    }, function(r) {
        if (r.success) {
            // Obtener categorías actuales del producto
            $.post(ajaxurl, {
                action: 'riverso_products_get_product_categories',
                nonce,
                woocommerce_product_id: wooId
            }, function(r2) {
                const currentCats = r2.success ? r2.data.current_categories : [];
                renderCategoryTreeWithCheckboxes(r.data.tree, currentCats);
            });
        }
    });
}

function renderCategoryTreeWithCheckboxes(categories, selectedIds, indent = 0) {
    let html = '';
    categories.forEach(cat => {
        const checked = selectedIds.includes(cat.id) ? 'checked' : '';
        html += `<div style="margin-left:${indent*20}px; margin-bottom:6px;">
            <label>
                <input type="checkbox" class="category-checkbox" value="${cat.id}" ${checked} data-category-id="${cat.id}">
                ${esc(cat.name)} (${cat.count})
            </label>
            ${renderCategoryTreeWithCheckboxes(cat.children || [], selectedIds, indent+1)}
        </div>`;
    });
    $('#online-categories-tree').html(html);
}
```

---

## Fase 7: Campo Imagen + Media Picker

### 1. Migración de Schema

Crear archivo `migrations/phase13_hub_v2.sql`:
```sql
ALTER TABLE wp_riverso_producto_base 
  ADD COLUMN imagen_id BIGINT UNSIGNED DEFAULT NULL AFTER estado,
  ADD KEY idx_imagen_id (imagen_id);
```

Registrar en `class-activator.php`:
```php
require_once RIVERSO_POS_PLUGIN_DIR . 'migrations/phase13_hub_v2.sql';
```

### 2. Backend (class-product-module.php)

```php
// En get_product():
if (!empty($product['imagen_id'])) {
    $product['imagen_url'] = wp_get_attachment_image_url($product['imagen_id'], 'thumbnail');
    $product['imagen_full'] = wp_get_attachment_image_url($product['imagen_id'], 'full');
}

// Nuevo endpoint:
public function ajax_set_image() {
    $producto_id = absint($_POST['producto_id'] ?? 0);
    $imagen_id = absint($_POST['imagen_id'] ?? 0);
    
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'riverso_producto_base',
        ['imagen_id' => $imagen_id ?: null],
        ['id' => $producto_id],
        ['%d'],
        ['%d']
    );
    
    wp_send_json_success(['message' => 'Imagen actualizada']);
}
```

### 3. Frontend (templates/products.php)

#### En Tab Local:
```html
<tr>
    <th>Imagen Local</th>
    <td>
        <div id="local-image-view">
            <img id="local-image-thumb" src="" style="max-width:100px; max-height:100px; display:none; border-radius:4px; margin-bottom:8px;">
            <button class="button" id="local-image-select">Seleccionar imagen</button>
        </div>
    </td>
</tr>
```

#### JavaScript para Media Picker:
```javascript
$('#local-image-select').on('click', function(e) {
    e.preventDefault();
    
    wp.media({
        title: 'Seleccionar imagen',
        button: { text: 'Usar imagen' },
        multiple: false
    }).on('select', function() {
        const attachment = wp.media.frame.state().get('selection').first().toJSON();
        
        $.post(ajaxurl, {
            action: 'riverso_products_set_image',
            nonce,
            producto_id: currentProduct.id,
            imagen_id: attachment.id
        }, function(r) {
            if (r.success) {
                currentProduct.imagen_id = attachment.id;
                currentProduct.imagen_url = attachment.url;
                $('#local-image-thumb').attr('src', attachment.url).show();
            }
        });
    }).open();
});
```

---

## Fase 8: Indicadores de Exclamación por Campo

### Lógica Frontend

```javascript
function renderFieldAlerts(product) {
    const alerts = [];
    
    // SKU Local vacío
    if (!product.canonical_sku) {
        alerts.push({
            field: 'SKU Local',
            action: 'edit-sku',
            icon: '❌'
        });
    }
    
    // Sin precio local
    if (!product.precio_local || !product.precio_local.p_asignado) {
        alerts.push({
            field: 'Precio Local',
            action: 'edit-price',
            icon: '⚠️'
        });
    }
    
    // Sin familia
    if (!product.familia) {
        alerts.push({
            field: 'Familia',
            action: 'edit-family',
            icon: '⚠️'
        });
    }
    
    // Sin imagen
    if (!product.imagen_id) {
        alerts.push({
            field: 'Imagen',
            action: 'edit-image',
            icon: '📷'
        });
    }
    
    // Sin código proveedor
    if (product.proveedores_count === 0) {
        alerts.push({
            field: 'Código Proveedor',
            action: 'tab-suppliers',
            icon: '📦'
        });
    }
    
    // Sin barcode EAN-13
    const hasEan = product.barcodes && product.barcodes.some(b => b.tipo === 'ean13');
    if (!hasEan && product.woocommerce_product_id) {
        alerts.push({
            field: 'Barcode EAN-13',
            action: 'tab-barcodes',
            icon: '📊'
        });
    }
    
    // Online sin categorías
    if (product.woocommerce_product_id) {
        alerts.push({
            field: 'Categorías Online',
            action: 'tab-online',
            icon: '📂'
        });
    }
    
    // Badge contador en header
    if (alerts.length > 0) {
        $('#detail-alerts-badge').html(alerts.length).show();
    }
    
    return alerts;
}
```

### Agregar badge en header:
```html
<span id="detail-alerts-badge" style="display:none; background:red; color:white; border-radius:12px; padding:2px 8px; font-weight:bold; margin-left:8px;">0</span>
```

---

## Fase 9: Regla de Precio Visible

### Backend (class-product-module.php)

```php
// En get_product(), después de precio_local:
if (class_exists('Riverso_Price_Rules_Module')) {
    $rule_data = Riverso_Price_Rules_Module::get_instance()->resolve_rule_for_base($id);
    $product['regla_precio'] = $rule_data; // Contiene: id, nombre, origin (producto/familia/categoria)
}
```

### Frontend

En la sección de Precio Local del tab Local:

```html
<tr>
    <th>Regla de Precio</th>
    <td>
        <span id="regla-display">-</span>
        <small id="regla-origen" style="color:#666; display:none;"></small>
    </td>
</tr>
```

En showDetail():
```javascript
// Mostrar regla de precio
if (product.regla_precio && product.regla_precio.id) {
    const regla = product.regla_precio;
    const origen = regla.origin || 'producto';
    const originLabel = {
        'producto': 'Regla directa',
        'familia': 'Regla de familia',
        'categoria': 'Regla de categoría'
    }[origen] || origen;
    
    $('#regla-display').html(`<a href="${esc(admin_url)}/admin.php?page=riverso-pos-price-rules&id=${regla.id}" target="_blank">${esc(regla.nombre)}</a>`);
    $('#regla-origen').text(`(${originLabel})`).show();
} else {
    $('#regla-display').text('Sin regla');
}
```

---

## Resumen de Implementación

| Fase | Complejidad | Tiempo Est. | Prioridad |
|------|------------|-----------|-----------|
| 5 | Media | 2-3h | Alta |
| 6 | Media | 2-3h | Alta |
| 7 | Baja | 1-2h | Media |
| 8 | Media | 2-3h | Alta |
| 9 | Baja | 1h | Media |

**Total estimado**: 8-12 horas

---

## Instrucciones Próximas

1. Ejecutar migración de schema (Fase 7) primero
2. Implementar Fases 5-6 (árboles) en paralelo
3. Agregar Fase 7 (foto) UI
4. Implementar Fase 8 (alertas)
5. Finalizar Fase 9 (regla visible)
6. Bump versión a 1.5.16-1.5.20 según fases completadas
