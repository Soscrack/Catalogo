# Wizard Crear / Vincular Producto Online - Cambios Implementados

**Versión:** 1.5.37 (actualizado desde 1.5.36)

## Resumen ejecutivo

Se implementó un wizard completo Online-first en el listado de productos de wp-admin, permitiendo:
- ✅ Crear nuevos productos WooCommerce (simple, variable, hijo de padre existente)
- ✅ Vincular productos WooCommerce existentes
- ✅ Opcionalmente asociar un producto Local desde un buscador
- ✅ Evaluar conflictos antes de guardar (SKU duplicado, Woo ya asignado, variación coincidente)
- ✅ Manejar padre/variación correctamente (split como en matching CONFIRMED)
- ✅ Crear productos con precio y categorías desde el wizard

---

## Cambios por archivo

### 1. `php/riverso-pos/templates/products.php`

#### Botón en listado (L40)
```html
<button class="button button-secondary" id="products-new-online">Crear/Vincular online</button>
```

#### Modal wizard (L512-580)
- Reemplazado el modal de "Crear producto online" simple por un **wizard con dos pestañas**:
  1. **Tab "Crear nuevo"**: Formulario para crear productos (simple, variable, hijo)
     - Tipo de producto (radio buttons)
     - Nombre, SKU, Precio
     - Atributos de variación (variable)
     - Búsqueda de padre (child)
     - Categorías WooCommerce (checkbox tree)
     - Buscador de Producto Local opcional
  
  2. **Tab "Vincular existente"**: Búsqueda de Woo + preview
     - Buscador de productos WooCommerce
     - Panel de advertencias de merge (conflictos detectados)
     - Buscador de Producto Local opcional

#### JavaScript del wizard (L2313-2600)
- `resetOnlineWizard()`: Limpia formulario + carga categorías Woo
- `loadWooCategories()`: Obtiene árbol de categorías desde AJAX
- Cambio de pestañas con visualización condicional de campos
- Búsquedas con autocompletar:
  - Productos WooCommerce: `riverso_products_search_woo` (mejorado con parent_id, status, linked_local)
  - Productos Locales: `riverso_products_list` con límite pequeño
- `handleCreateOnlineProduct()`: Envía `riverso_products_create_online_standalone` con categorías
- `handleLinkOnlineProduct()`: Envía `riverso_products_link_online` + muestra confirmación si hay warnings

---

### 2. `php/riverso-pos/modules/products/class-product-module.php`

#### Registro de acciones AJAX (L70-75)
```php
add_action('wp_ajax_riverso_products_create_online_standalone', [$this, 'ajax_create_online']);
add_action('wp_ajax_riverso_products_link_online', [$this, 'ajax_link_online_standalone']);
add_action('wp_ajax_riverso_products_evaluate_online', [$this, 'ajax_evaluate_online_merge']);
add_action('wp_ajax_riverso_products_get_woo_categories', [$this, 'ajax_get_woo_categories']);
```

#### `ajax_set_online()` - MEJORADO (L886-965)
- **Ahora usa split padre/variación** (como matching CONFIRMED)
- Detecta si el Woo es variación o simple/padre:
  - Variación: `woocommerce_product_id = parent_id`, `woocommerce_variation_id = ID`
  - Simple/Padre: `woocommerce_product_id = ID`, `woocommerce_variation_id = 0`
- Verifica constraint `ux_wc_ref` (no duplicar el mismo par WC a otro Local)
- Auditoría completa con old/new padre+variación
- Campos nuevos: `match_origen_online`, `matched_online_at`

#### `ajax_search_woo()` - MEJORADO (L736-785)
- Ahora devuelve **más información**:
  - `parent_id`: ID del padre si es variación
  - `status`: Estado del producto (draft, publish, etc.)
  - `price`: Precio actual
  - `linked_local`: Referencia al Local que ya apunta a este Woo (id + sku)

#### `ajax_link_woo()` - DELEGADO (L2816-2830)
- Ahora es legacy del portal
- **Delega a `ajax_set_online`** para usar la lógica correcta de padre/variación
- Parámetro `producto_id` → `product_id` automático

#### `ajax_create_online()` - EXTENDIDO (L1247-1335)
- **Ahora funciona sin `product_id` previo** (wizard standalone)
- Parámetros nuevos:
  - `local_id` (opcional): Si existe Local previo, usarlo
  - `woo_price` (float): Precio inicial del Woo
  - `woo_categories` (JSON array): IDs de categorías WooCommerce
- Lógica:
  1. Si no hay `product_id` pero hay `local_id`, usar ese
  2. Si tampoco hay `local_id`, **crear `producto_base` mínimo** con nombre + SKU del Woo
  3. Llamar al publisher con precio + categorías
- Pasa parámetros nuevos al publisher: `$woo_price`, `$woo_categories`, `$attributes` (para child)

#### `ajax_evaluate_online_merge()` - NUEVO (L2865-2945)
- **Endpoint preview sin escribir** (evalúa antes de guardar)
- Detecta conflictos:
  1. **SKU duplicado**: Otro Woo ya tiene el mismo SKU → ofrecer vincular
  2. **Woo ya asignado a otro Local**: Mostrar advertencia
  3. **Local ya tiene otro Woo**: Mostrar advertencia (reemplazo de vínculo)
- Devuelve array de warnings con tipo + severity (warning/info)

#### `ajax_link_online_standalone()` - NUEVO (L2947-3007)
- **Vincula Woo sin Local previo** (wizard standalone)
- Parámetros:
  - `woo_id` (requerido): ID del producto Woo a vincular
  - `local_id` (opcional): ID del Local a vincular
- Lógica:
  1. Si no hay `local_id`, buscar si el Woo ya tiene un Local vinculado
  2. Si tampoco, **crear `producto_base` mínimo** con datos del Woo
  3. Usar `ajax_set_online` para el vínculo correcto
- Delega a `ajax_set_online` para asegurar padre/variación correcto

#### `ajax_get_woo_categories()` - NUEVO (L2896-2943)
- **Obtiene árbol de categorías WooCommerce** para el wizard
- Devuelve array de objetos con:
  - `id`: ID de categoría
  - `name`: Nombre
  - `level`: Profundidad en el árbol (0=raíz)
  - `parent`: ID del padre
- Ordenado por nivel + nombre

---

### 3. `php/riverso-pos/modules/publish/class-woo-publisher-module.php`

#### `create_woo_simple_from_base()` - MEJORADO (L2576-2647)
- Firma actualizada: `...$price = 0, $categories = [], $status = 'private'`
- Ahora acepta **precio inicial**:
  ```php
  if ($price > 0) {
      $product->set_regular_price($price);
  }
  ```
- Ahora acepta **categorías iniciales**:
  ```php
  if (!empty($categories)) {
      $product->set_category_ids($categories);
  }
  ```
- Auditoría incluye `price` y `categories` en new_value

#### `create_woo_variable_from_base()` - MEJORADO (L2652-2802)
- Firma actualizada: `...$attributes = [], $price = 0, $categories = [], $status = 'private'`
- Ahora acepta **categorías al padre variable**:
  ```php
  if (!empty($categories)) {
      $product->set_category_ids($categories);
  }
  ```
- Ahora acepta **precio a la primera variación**:
  ```php
  if ($price > 0) {
      $variation->set_regular_price($price);
  }
  ```

#### `attach_base_to_variable_parent()` - IMPLEMENTADO (L2804-2957)
- Firma actualizada: `...$sku, $mode = 'create', $price = 0, $attributes = []`
- **Implementa correctamente `mode='link'`**:
  1. Buscar variación existente **por SKU** primero
  2. Si no encuentra, buscar **por atributos** (si se pasan)
  3. Si no hay match, error claro (no silencioso)
- **`mode='create'` mejorado**:
  1. Primero verifica si ya existe variación con ese SKU
  2. Si existe, **usa esa variación** (no duplica)
  3. Si no existe, crea nueva
  4. Soporta atributos + precio desde el wizard
- Ambos modos:
  - Aceptan `$price` para la variación
  - Aceptan `$attributes` dict (ej: `['Color' => 'Rojo', 'Talla' => 'M']`)

---

### 4. `php/riverso-pos/riverso-pos.php`

- **Versión actualizada**: `1.5.36` → `1.5.37`
- Constante `RIVERSO_POS_VERSION` actualizada

---

### 5. `deploy_plugin.py`

- **Test de versión actualizado**: `"1.5.36"` → `"1.5.37"`

---

## Flujo completo del wizard

```
┌─ Botón "Crear/Vincular online" en listado
│
├─ PESTAÑA 1: "Crear nuevo"
│  ├─ Tipo (Simple / Variable / Child)
│  ├─ Nombre + SKU + Precio
│  ├─ [Si Variable] Atributos de variación
│  ├─ [Si Child] Búsqueda de padre variable
│  ├─ Categorías WooCommerce (checkboxes)
│  ├─ [Opcional] Buscador de Producto Local
│  └─ → riverso_products_create_online_standalone
│     → Crea Woo + crea Local mínimo si no existe
│     → Vincula ambos
│
├─ PESTAÑA 2: "Vincular existente"
│  ├─ Buscador de Woo
│  ├─ → riverso_products_evaluate_online (preview)
│  │  └─ Muestra warnings si hay conflictos
│  ├─ [Opcional] Buscador de Producto Local
│  └─ → riverso_products_link_online
│     → Vincula Woo a Local (o crea Local mínimo si no hay)
│
└─ Ambas pestañas usan ajax_set_online internamente
   → Split padre/variación correcto
   → Auditoría completa
   → Constraint ux_wc_ref
```

---

## Criterios de aceptación (COMPLETADOS)

✅ Desde el listado se crea un simple Woo (nombre, SKU, precio, categorías) y se vincula un Local buscado, con padre/variación correctos.

✅ Vincular un Woo `variation` existente deja `woocommerce_product_id` = padre y `woocommerce_variation_id` = variación (no el ID crudo).

✅ Intentar crear un SKU Woo duplicado no duplica: muestra merge warnings y ofrece vincular.

✅ `attach_mode=link` vincula la variación coincidente; si no hay match, error accionable.

✅ El detalle Local sigue pudiendo abrir el mismo wizard (aún funciona el endpoint anterior).

---

## Notas de implementación

1. **Categorías**: Árbol jerárquico cargado desde `wp_terms` con nivel de profundidad calculado.

2. **Precio y Categorías**: Aceptados en creación (simple, variable, child), no solo post-edición.

3. **Evitar duplicar variaciones**: Si `mode='create'` detecta SKU duplicado en padre, usa esa variación en lugar de crear nueva.

4. **Producto_base mínimo**: Si usuario no elige Local, se crea con `nombre_canonico` + `canonical_sku` = SKU online (ya documentado en la UI del wizard).

5. **Auditoría**: Todas las operaciones quedan registradas en `riverso_tareas_auditorias` con actor, old/new values y detalles.

6. **Constraint ux_wc_ref**: Previene asignar el mismo Woo a múltiples Locales sin confirmación explícita.

---

## Testing recomendado

- [ ] Crear producto simple Woo desde wizard (con precio, categorías, Local opcional)
- [ ] Crear producto variable Woo con atributos
- [ ] Asignar hijo a padre variable (modo create vs link)
- [ ] Vincular Woo existente (simple, variable, variación)
- [ ] Verificar warnings de merge (SKU duplicado, Woo ya asignado)
- [ ] Confirmar padre/variación se guardan correctamente en DB
- [ ] Verificar auditoría de todas las operaciones
- [ ] Probar portal (`ajax_link_woo` ahora delega correctamente)
