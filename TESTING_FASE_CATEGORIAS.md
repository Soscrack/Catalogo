# Testing - Fase de Categorías Mejoradas (v1.5.8)

## Cambios Implementados

### Backend (PHP)
1. **Exposición de `datos_extra` en tareas** (`class-product-module.php`)
   - Modificada función `get_product_tasks()` para incluir y decodificar campo `datos_extra`
   - Permite acceder a categorías sugeridas por el catálogo Mamut desde el frontend

2. **Endpoints para edición de categorías** (`class-product-module.php`)
   - `riverso_products_create_category`: Crear nueva categoría WooCommerce (`product_cat`)
   - `riverso_products_rename_category`: Renombrar categoría existente
   - Ambos endpoints registrados en `init()` del módulo de productos

### Frontend (JavaScript/HTML)
1. **Pre-selección de categorías sugeridas** (`products.php`)
   - Banner visual mostrando categoría sugerida por Mamut
   - Matching por nombre (case-insensitive) entre categorías Mamut y WooCommerce
   - Badge verde "Sugerido" en categorías que coinciden
   - Pre-selección automática de categorías sugeridas

2. **Panel de aceptación de tarea** (`products.php`)
   - Panel visible solo cuando existe tarea `validar_categoria` pendiente
   - Muestra categoría sugerida con claridad
   - Botón "Aceptar categorías y completar tarea" que:
     - Guarda categorías seleccionadas
     - Completa la tarea `validar_categoria`
     - Recarga datos del producto

3. **Interfaz de edición de categorías** (`products.php`)
   - Botón "+ Nueva categoría" para agregar categorías
   - Formulario inline con nombre y categoría padre
   - Botones "Editar" en cada categoría para renombrar
   - Dropdown para seleccionar categoría padre (jerárquico)

## Plan de Pruebas

### Test 1: Verificar pre-selección de categorías sugeridas
**Requisito previo**: Tener un producto con tarea `validar_categoria` pendiente

**Pasos**:
1. Abrir un producto que tenga task `validar_categoria`
2. Ir a tab "Online" → sección "Categorías WooCommerce"
3. **Verificar**:
   - [ ] Se muestra banner "Sugerido por catálogo Mamut" con la categoría
   - [ ] La categoría sugerida tiene badge verde "Sugerido"
   - [ ] La categoría está pre-seleccionada (checkbox marcado)
   - [ ] Panel "Tarea pendiente: Validar categoría" es visible

### Test 2: Crear nueva categoría
**Pasos**:
1. Desde la sección de categorías, hacer click en "+ Nueva categoría"
2. **Verificar**: Se expande formulario con campos
   - [ ] Campo "Nombre de la categoría" visible
   - [ ] Dropdown "Categoría padre" poblado con todas las categorías
   - [ ] Botones "Crear" y "Cancelar" presentes

3. Ingresa nombre "Test_Herramientas" y selecciona padre "Sin padre"
4. Click "Crear"
5. **Verificar**:
   - [ ] Mensaje "Categoría creada exitosamente"
   - [ ] Árbol de categorías se recarga automáticamente
   - [ ] Nueva categoría aparece en el árbol
   - [ ] Formulario se cierra

### Test 3: Renombrar categoría
**Pasos**:
1. En el árbol de categorías, buscar una categoría (ej. la recién creada "Test_Herramientas")
2. Hacer click en botón "Editar" (al lado del nombre)
3. **Verificar**: Aparece dialog de prompt para nuevo nombre
4. Ingresar nuevo nombre: "Herramientas_Mejoradas"
5. Click "OK"
6. **Verificar**:
   - [ ] Mensaje "Categoría actualizada exitosamente"
   - [ ] Árbol se recarga
   - [ ] El nombre nuevo aparece en el árbol

### Test 4: Aceptar categorías y completar tarea
**Requisito previo**: Tener un producto con task `validar_categoria`

**Pasos**:
1. Desde la sección de categorías (con el producto que tiene task)
2. Asegurarse que la categoría sugerida esté marcada (debería estarlo por defecto)
3. Opcionalmente, marcar/desmarcar otras categorías según sea necesario
4. Click botón "Aceptar categorías y completar tarea"
5. **Verificar**:
   - [ ] Las categorías se guardan (primera confirmación)
   - [ ] La tarea se completa (segunda confirmación)
   - [ ] Mensaje final: "¡Categorías aceptadas y tarea completada exitosamente!"
   - [ ] Panel de tarea desaparece
   - [ ] Al recargar el producto, la tarea ya no aparece como pendiente

### Test 5: Verificar datos_extra en tareas
**Pasos técnicos** (usando DevTools o verificar en BD):
1. Abrir un producto con task `validar_categoria`
2. Abrir DevTools → Network → buscar request `riverso_products_get_product`
3. Ver respuesta JSON
4. **Verificar**:
   - [ ] En `response.data.tasks[]`, existe campo `datos_extra`
   - [ ] `datos_extra` contiene `categoria` y `subcategoria` (si existen)
   - [ ] No es string JSON sino objeto parseado

### Test 6: Edición retroactiva (flujo completo)
**Pasos**:
1. Crear nueva categoría "Herramientas Especiales"
2. Con un producto diferente, asignar esa categoría
3. Si el producto tiene task con sugerencia a "Herramientas Especiales":
   - [ ] La nueva categoría creada aparece en el árbol
   - [ ] Si renombras la categoría a "Herramientas Premium", los productos asignados mantienen la asignación

## Checklist de Validación

- [ ] Backend: `datos_extra` se retorna decodificado en tasks
- [ ] Frontend: Banner de categoría sugerida aparece cuando hay task
- [ ] Frontend: Matching por nombre identifica categorías sugeridas
- [ ] Frontend: Pre-selección automática funciona
- [ ] Frontend: Botón "Editar" permite renombrar categorías
- [ ] Frontend: "+ Nueva categoría" permite crear categorías
- [ ] Frontend: Aceptar categorías + completar tarea en una acción
- [ ] Flujo completo: crear → renombrar → asignar → completar tarea

## Errores Conocidos / Notas

- Las categorías se persisten en WooCommerce (`product_cat`)
- Los cambios tienen efecto retroactivo automático
- El matching es case-insensitive pero exacto en nombre
- Las tareas completadas no volverán a aparecer como pendientes

## Archivos Modificados

- `php/riverso-pos/modules/products/class-product-module.php` (backend)
  - `get_product_tasks()`: Incluir `datos_extra`
  - `ajax_create_category()`: Nueva acción
  - `ajax_rename_category()`: Nueva acción

- `php/riverso-pos/templates/products.php` (frontend)
  - HTML: Banner sugerido, panel de tarea, formulario de nueva categoría
  - `loadCategoryTree()`: Detectar task y categoría sugerida
  - `renderCategoryTreeWithCheckboxes()`: Pre-selección y badges
  - `populateCategoryParentDropdown()`: Poblar dropdown de padre
  - Event listeners: Crear, renombrar, aceptar tarea

- `php/riverso-pos/riverso-pos.php`
  - Version bump: 1.5.7 → 1.5.8

- `deploy_plugin.py`
  - Version check actualizado a 1.5.8
