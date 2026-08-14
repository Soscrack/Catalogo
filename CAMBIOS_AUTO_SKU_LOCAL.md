# Auto-generar SKU Local en Wizard Online - Cambios Implementados

## Resumen

Se ha implementado con éxito la funcionalidad de auto-generación de SKUs locales numéricos en el wizard online. Los usuarios ahora pueden generar automáticamente el siguiente SKU disponible (máximo 6 dígitos) al crear o vincular productos online, en lugar de tener que ingresar manualmente un SKU de WooCommerce o un valor `SKU-{uniqid}`.

## Cambios realizados

### 1. Backend: Endpoint AJAX `riverso_products_next_sku`

**Archivo**: `php/riverso-pos/modules/products/class-product-module.php`

- **Registración**: Se agregó la acción AJAX `riverso_products_next_sku` en el constructor del módulo
- **Implementación**: Nuevo método `ajax_next_sku()` que:
  - Consulta el máximo valor numérico actual de SKUs (máximo 6 dígitos) desde `producto_base.canonical_sku`
  - Calcula el siguiente índice disponible (`MAX + 1`)
  - Valida que no exceda 999999 (límite de 6 dígitos)
  - Maneja race conditions buscando el siguiente slot disponible
  - Retorna `{ next_sku: "XXXXX" }` como string sin padding

**Query SQL utilizada**:
```sql
SELECT MAX(CAST(canonical_sku AS UNSIGNED)) 
FROM {prefix}producto_base 
WHERE canonical_sku REGEXP '^[0-9]+$' 
AND CHAR_LENGTH(canonical_sku) <= 6
```

### 2. Frontend: UI del botón "Generar nuevo SKU"

**Archivo**: `php/riverso-pos/templates/products.php`

#### Pestaña "Crear nuevo":
- Se agregó un separador visual: `-- o --`
- Nuevo botón: `#create-local-generate-sku` con texto "Generar nuevo SKU Local"
- Vista previa editable con campo `#create-local-new-sku-input` (readonly por defecto, editable)
- Hidden input `#create-local-new-sku` que almacena el SKU generado

#### Pestaña "Vincular existente":
- Se agregó un separador visual: `-- o --`
- Nuevo botón: `#link-local-generate-sku` con texto "Generar nuevo SKU Local"
- Vista previa editable con campo `#link-local-new-sku-input` (readonly por defecto, editable)
- Hidden input `#link-local-new-sku` que almacena el SKU generado

#### Interactividad:
- Al hacer clic en el botón "Generar nuevo SKU":
  1. Se llama al endpoint `riverso_products_next_sku`
  2. Se muestra el SKU sugerido en un campo editable (con fondo verde y bordes)
  3. El usuario puede editarlo si lo desea
  4. Se limpian automáticamente los campos de búsqueda de Local existente
- Evento `change` en los campos de entrada para actualizar el hidden input cuando el usuario edita el SKU

#### Actualización de `resetOnlineWizard()`:
- Se agregó la limpieza de todos los campos de SKU generado al resetear el wizard

### 3. Backend: Parámetro `new_local_sku` en endpoints

**Archivo**: `php/riverso-pos/modules/products/class-product-module.php`

#### En `ajax_create_online()`:
- Se agregó el parámetro `new_local_sku` (sanitizado)
- Lógica de creación actualizada:
  1. Si hay `local_id`, usa ese producto local
  2. Si hay `new_local_sku` pero no `local_id`:
     - Valida que sea numérico puro (máximo 6 dígitos)
     - Verifica que no exista en `producto_base.canonical_sku`
     - Crea un nuevo `producto_base` con ese SKU y el nombre del producto Woo
  3. Si no hay ni `local_id` ni `new_local_sku`:
     - Crea un `producto_base` mínimo con el SKU del Woo (comportamiento fallback)

#### En `ajax_link_online_standalone()`:
- Se agregó el parámetro `new_local_sku` (sanitizado)
- Lógica similar a `ajax_create_online()`:
  1. Si hay `local_id`, usa ese
  2. Si hay `new_local_sku` pero no `local_id`:
     - Valida y crea `producto_base` con SKU generado
  3. Si hay ambos, prioriza `local_id`
  4. Si no hay ninguno, usa el SKU del Woo o genera uno con `uniqid()`

#### Validaciones:
- Regex: `/^\d{1,6}$/` (solo dígitos, máximo 6 caracteres)
- Unicidad: Se verifica con `SELECT id FROM producto_base WHERE canonical_sku = ?`
- Mensajes de error descriptivos en caso de fallo

### 4. JavaScript: Manejo de eventos en el wizard

**Archivo**: `php/riverso-pos/templates/products.php`

#### Handlers agregados:
- `#create-local-generate-sku` click: Genera SKU para la pestaña "Crear nuevo"
- `#link-local-generate-sku` click: Genera SKU para la pestaña "Vincular existente"
- `#create-local-new-sku-input` change: Actualiza el hidden input `create-local-new-sku`
- `#link-local-new-sku-input` change: Actualiza el hidden input `link-local-new-sku`

#### Modificaciones a selectores existentes:
- `.create-local-option` click: Ahora limpia los campos de SKU generado
- `.link-local-option` click: Ahora limpia los campos de SKU generado

#### Actualización de `handleCreateOnlineProduct()`:
- Se extrae `new_local_sku` del formulario
- Se envía en el payload AJAX como parámetro adicional

#### Actualización de `handleLinkOnlineProduct()`:
- Se extrae `new_local_sku` del formulario
- Se envía en el payload AJAX como parámetro adicional

## Flujo de usuario

### Flujo 1: Crear online sin Local → Generar nuevo SKU local

1. Usuario abre el wizard "Crear nuevo"
2. Ingresa nombre y SKU del producto Woo
3. En el bloque "Producto Local" hace clic en "Generar nuevo SKU Local"
4. El sistema consulta `riverso_products_next_sku` y obtiene, ej., `28928`
5. Se muestra el SKU en el campo editable (el usuario puede ajustarlo si desea)
6. Usuario hace clic en "Guardar"
7. Se envía `new_local_sku: "28928"` al servidor
8. Se crea un `producto_base` con `canonical_sku = "28928"` y el nombre del Woo
9. Se crea el producto online y se vincula al Local creado

### Flujo 2: Crear online sin Local → Buscar Local existente

1. Usuario abre el wizard "Crear nuevo"
2. Ingresa nombre y SKU del Woo
3. En el bloque "Producto Local" busca un Local existente
4. Selecciona el Local encontrado
5. Usuario hace clic en "Guardar"
6. Se envía `local_id: X` al servidor
7. Se crea el producto online y se vincula al Local existente

### Flujo 3: Vincular Woo existente → Generar nuevo SKU local

1. Usuario abre el wizard "Vincular existente"
2. Busca y selecciona un producto Woo
3. En el bloque "Producto Local" hace clic en "Generar nuevo SKU Local"
4. Se obtiene el siguiente SKU disponible y se muestra editable
5. Usuario hace clic en "Guardar"
6. Se crea un `producto_base` con el SKU generado
7. Se vincula el Woo al Local creado

## Validaciones y manejo de errores

### Validaciones de SKU:
- Debe ser numérico puro (`/^\d{1,6}$/`)
- Máximo 6 dígitos (rango 1-999999)
- Debe ser único en la tabla `producto_base.canonical_sku`

### Mensajes de error:
- "Se ha alcanzado el límite máximo de SKUs numéricos (999999)" - Cuando no hay huecos disponibles
- "SKU Local debe ser numérico y máximo 6 dígitos" - Formato inválido
- "El SKU Local X ya existe" - Duplicado

## Archivos modificados

1. **php/riverso-pos/modules/products/class-product-module.php**
   - Registro de AJAX action `riverso_products_next_sku`
   - Implementación de `ajax_next_sku()`
   - Actualización de `ajax_create_online()` para manejar `new_local_sku`
   - Actualización de `ajax_link_online_standalone()` para manejar `new_local_sku`

2. **php/riverso-pos/templates/products.php**
   - UI para el botón "Generar nuevo SKU" en ambas pestañas
   - JavaScript handlers para generar SKU
   - Limpieza de campos en `resetOnlineWizard()`
   - Actualización de `handleCreateOnlineProduct()` y `handleLinkOnlineProduct()`
   - Handlers para limpiar SKU generado cuando se selecciona Local existente

## Deployment

Se ha realizado el deploy del plugin con éxito:
- Versión desplegada: 1.5.37
- Todas las migraciones completadas
- Preflight checks exitosos

## Testing recomendado

1. Generar SKU nuevo en "Crear nuevo" - crear producto online sin Local existente
2. Generar SKU nuevo en "Vincular existente" - vincular Woo a un Local con SKU generado
3. Buscar Local existente - en lugar de generar SKU
4. Editar el SKU sugerido - validar que acepte cambios
5. Intentar usar un SKU duplicado - debe rechazarse
6. Crear 6 dígitos (999999) - validar límite máximo
7. Buscar Local → cambiar a generar SKU - validar que se limpian campos
8. Generar SKU → cambiar a buscar Local - validar que se limpian campos
