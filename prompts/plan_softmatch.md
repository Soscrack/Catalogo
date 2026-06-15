# Sistema de Gestión de Productos Locales y Publicación Controlada a WooCommerce

## Objetivo General

Quiero extender la arquitectura actual para que exista una plataforma centralizada de gestión de productos que permita administrar tanto los productos locales como los productos publicados en WooCommerce.

La plataforma debe ser considerada la fuente principal de administración de productos, relaciones, códigos y validaciones humanas.

---

# 1. Gestión Completa de Productos

Se debe implementar una interfaz administrativa para:

## Crear productos

Permitir crear:

* productos simples
* productos variables
* productos equivalentes
* productos de proveedores
* productos abiertos
* productos internos

---

## Editar productos

Permitir modificar:

* nombre
* descripción
* SKU
* categoría
* proveedor
* atributos
* envases
* precios
* códigos de barra
* imágenes
* relaciones
* equivalencias

---

## Eliminar productos

Permitir:

* eliminación lógica (soft delete)
* archivado
* restauración

Nunca eliminar físicamente información histórica.

---

# 2. Auditoría Obligatoria

Toda modificación debe quedar registrada.

## Eventos mínimos

* producto creado
* producto editado
* producto archivado
* producto restaurado
* cambio de precio
* cambio de SKU
* cambio de proveedor
* cambio de código de barra
* cambio de categoría
* cambio de atributos
* publicación online
* despublicación online

---

## Datos auditados

Guardar:

* usuario
* fecha
* IP
* acción
* valor anterior
* valor nuevo
* origen

---

## Origen posible

```txt
human
computer
migration
import
api
```

---

# 3. Gestión de Códigos

## Códigos de barra

La plataforma debe permitir:

* crear
* editar
* eliminar
* reasignar

códigos de barra.

---

## Relación código → producto

Un código de barra puede estar asociado a:

* producto simple
* producto variable
* envase
* producto abierto

---

## Códigos de proveedor

La plataforma debe permitir administrar:

* código proveedor
* descripción proveedor
* proveedor origen
* equivalencias

---

## Historial

Todo cambio debe quedar auditado.

---

# 4. Importación Inicial de Productos MAMUT

Los productos deben obtenerse desde:

```txt
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\data\catalogo_mamut_2025_spatial.json
```

---

# Objetivo

Subir estos productos a WooCommerce.

---

## Requisito

Antes de implementar la importación:

Analizar cómo WooCommerce almacena:

* productos simples
* productos variables
* atributos
* categorías
* imágenes
* variaciones

---

## Entregable

Generar documentación técnica en Markdown:

```txt
woo_product_structure.md
```

Explicando:

* estructura WooCommerce
* entidades utilizadas
* atributos
* taxonomías
* variaciones
* imágenes
* relaciones

---

# 5. Modelo de Productos Variables

## Regla principal

Los atributos visibles deben mantenerse visibles para el usuario.

---

## Caso especial

Cuando existan atributos:

```txt
NOMINAL
LARGO
```

NO quiero usar ambos como atributos de variación.

---

## Regla

Crear automáticamente:

```txt
NOMINAL X LARGO
```

---

Ejemplo:

```txt
Nominal = 8

Largo = 1"
```

produce:

```txt
8 x 1"
```

---

## Uso

Este atributo:

```txt
NOMINAL X LARGO
```

será el atributo de variación real.

---

Mientras que:

```txt
NOMINAL
LARGO
```

seguirán siendo atributos visibles.

---

# Ejemplo

Producto:

```txt
TORNILLO DRYWALL
```

Atributos:

```txt
NOMINAL
LARGO
ENVASE
ACABADO
```

Variaciones reales:

```txt
NOMINAL X LARGO
ENVASE
ACABADO
```

---

# 6. Experiencia de Usuario en WooCommerce

Quiero que el cliente vea selectores como:

```txt
Nominal x Largo
Envase
Acabado
```

y pueda cambiar cada uno.

---

# 7. Estrategia de URLs y Productos Públicos

No quiero que aparezcan cientos de productos duplicados por medidas.

---

## Ejemplo

Actualmente podrían existir:

```txt
TORNILLO DRYWALL ROSCA MADERA (CRS) - Zincado Brillante

TORNILLO DRYWALL ROSCA MADERA (CRS) - Fosfatizado
```

---

## Objetivo

Mantener un único producto base por acabado.

---

### Producto público

```txt
TORNILLO DRYWALL ROSCA MADERA (CRS) - Zincado Brillante
```

---

Dentro del producto:

Seleccionar:

* Nominal x Largo
* Envase

---

### Producto público alternativo

```txt
TORNILLO DRYWALL ROSCA MADERA (CRS) - Fosfatizado
```

---

## Navegación

Cuando un usuario encuentre un producto por acabado:

Debe abrir el producto correcto con:

```txt
ACABADO preseleccionado
```

---

# 8. Soft Match Local ↔ Online

Debe existir una relación entre:

## Producto local

Sistema interno.

## Producto online

WooCommerce.

---

# Requisito

El sistema debe intentar relacionarlos automáticamente.

---

## Métodos

Usar:

* SKU
* nombre
* medidas
* atributos
* categoría
* proveedor
* códigos de barra

---

# Estados

```txt
UNMATCHED
AUTO_MATCH
PENDING_REVIEW
CONFIRMED
REJECTED
```

---

# Confirmación Humana

Un match automático NO puede considerarse definitivo.

Siempre debe requerir:

```txt
confirmación humana
```

---

# Auditoría

Guardar:

```txt
created_by = computer
```

y

```txt
requires_human_review = true
```

---

# 9. Integración con Sistema de Tareas

Todo elemento generado automáticamente debe crear tareas.

---

## Match automático

Crear tarea:

```txt
Confirmar relación producto local ↔ online
```

---

## Producto MAMUT importado

Crear tarea:

```txt
Validar producto importado
```

---

## Categoría inferida

Crear tarea:

```txt
Confirmar categoría
```

---

## Variaciones generadas

Crear tarea:

```txt
Confirmar estructura de atributos
```

---

## Producto listo para publicar

Crear tarea:

```txt
Autorizar publicación
```

---

# 10. Regla de Publicación

Los productos NO pueden publicarse automáticamente.

---

## Requisitos para publicar

Debe cumplirse:

### 1

Producto confirmado por humano.

```txt
human_product_review = approved
```

---

### 2

Precio confirmado por humano.

```txt
human_price_review = approved
```

---

### 3

Categoría validada.

```txt
human_category_review = approved
```

---

### 4

Relaciones de atributos validadas.

```txt
human_attribute_review = approved
```

---

# Si falta alguna aprobación

El producto debe permanecer:

```txt
draft
```

o

```txt
private
```

en WooCommerce.

---

# 11. Pipeline de Publicación

## Etapa 1

Importación.

```txt
computer_created
```

---

## Etapa 2

Soft matching.

```txt
pending_review
```

---

## Etapa 3

Validación humana.

```txt
human_verified
```

---

## Etapa 4

Validación de precio.

```txt
price_verified
```

---

## Etapa 5

Autorización.

```txt
approved_for_publication
```

---

## Etapa 6

Publicación WooCommerce.

```txt
published
```

---

# 12. Objetivo de Arquitectura

Diseñar una solución escalable que permita:

* gestionar productos locales
* gestionar productos online
* administrar equivalencias
* administrar proveedores
* administrar códigos de barra
* administrar atributos
* administrar precios
* mantener auditoría completa
* soportar revisión humana obligatoria
* evitar publicaciones erróneas
* integrarse correctamente con WooCommerce
* preparar futuras automatizaciones mediante IA y soft matching

```
```
