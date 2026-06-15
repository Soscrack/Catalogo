# Sistema de Productos, Costos, Equivalencias y Precios (Local + Online)

## Contexto

Actualmente el sistema utiliza los productos definidos en:

```txt
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\data\raw\productos.xlsx
```

Además existe un sistema local de ventas que utiliza los archivos:

```txt
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\CodigosBarra\codigos_barras_2026-04-01.csv

C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\CodigosBarra\productos_2026-04-01.csv
```

Estos archivos son actualmente la base operativa para:

* Ventas locales
* Lectura de códigos de barra
* Identificación de productos
* Manejo de lotes y cantidades

---

# Problema actual

Actualmente existen productos de distintos proveedores que han sido agrupados como si fueran un único producto.

Esto simplifica:

* precios
* ventas

pero genera problemas importantes en:

* inventario
* costos
* trazabilidad
* análisis de proveedores

Ejemplo:

Proveedor A:

* Tornillo 8x1"

Proveedor B:

* Tornillo 8x1"

Actualmente ambos pueden estar asociados al mismo producto lógico.

Esto impide:

* conocer el costo real por proveedor
* conocer el inventario real por origen
* analizar diferencias de costos
* comparar márgenes correctamente

---

# Objetivo

Diseñar una estructura escalable que permita:

## Mantener compatibilidad con el sistema actual

No quiero romper inmediatamente el sistema local existente.

La primera etapa debe permitir:

* seguir operando
* seguir vendiendo
* seguir leyendo códigos de barra

aunque internamente existan problemas heredados.

---

## Evolucionar progresivamente

Quiero diseñar una arquitectura que permita posteriormente:

### Productos independientes por proveedor

Cada producto físico debe poder existir individualmente.

Ejemplo:

```txt
Tornillo 8x1" - MAMUT
Tornillo 8x1" - FAST
Tornillo 8x1" - ACME
```

Cada uno con:

* inventario propio
* costo propio
* historial propio
* proveedor propio

---

### Productos equivalentes

Crear una capa superior de equivalencia.

Ejemplo:

```txt
Familia:
Tornillo 8x1"

Equivalentes:
- Tornillo 8x1" MAMUT
- Tornillo 8x1" FAST
- Tornillo 8x1" ACME
```

Esto permitirá:

* comparar precios
* comparar costos
* sincronizar estrategias comerciales
* analizar sustitutos

---

# Integración con WooCommerce

## Primera etapa online

Quiero comenzar cargando los productos de MAMUT.

Actualmente existe información extraída desde:

```txt
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\data\catalogo_mamut_2025_spatial.json
```

y otros archivos relacionados.

---

## Situación actual

Algunos productos MAMUT hacen match con productos existentes.

Otros no.

Además el sistema local muchas veces carece de:

* categorías
* envases
* relaciones correctas
* información comercial suficiente

---

## Objetivo

Diseñar un sistema escalable para relacionar:

### Producto local

Información heredada.

### Producto proveedor

Información proveniente de MAMUT u otros proveedores.

### Producto WooCommerce

Representación comercial online.

---

# Matching de productos

Diseñar un sistema de matching progresivo.

Estados posibles:

```txt
UNMATCHED
AUTO_MATCH
HUMAN_REVIEW
VERIFIED
REJECTED
```

---

## Matching automático

Usar:

* SKU
* nombre
* descripción
* medidas
* códigos proveedor
* códigos de barra

---

## Revisión humana

Todo match automático debe poder ser:

* aceptado
* corregido
* rechazado

---

## Auditoría

Toda relación creada automáticamente debe guardar:

```txt
origen = computer
estado = pendiente_revision
```

---

# Sistema de embolsado

El sistema debe soportar productos abiertos.

---

## Concepto

Un producto puede existir como:

### Envase cerrado

Ejemplo:

```txt
Caja 100 unidades
Bolsa 500 unidades
Caja 1000 unidades
```

---

### Producto abierto

Ejemplo:

```txt
TORNILLO_8X1_ABIERTO
```

---

## Flujo

Cuando se abre un envase:

```txt
Caja 100
↓
Producto abierto +100 unidades
```

El inventario del envase disminuye.

El inventario abierto aumenta.

---

# Código abierto

Agregar soporte para:

```txt
codigo_abierto
```

permitiendo asociar:

* producto origen
* cantidad abierta
* fecha
* usuario
* costo unitario

---

# Bolsas generadas

Desde inventario abierto se podrán generar bolsas personalizadas.

Ejemplo:

```txt
Bolsa 25
Bolsa 50
Bolsa 100
```

---

# EAN13 personalizado

Generar códigos EAN13 usando:

```txt
2
+
SKU (máx 6 dígitos)
+
CANTIDAD (5 dígitos)
+
dígito verificador
```

Formato conceptual:

```txt
2SSSSSSQQQQQX
```

Donde:

* S = SKU
* Q = cantidad
* X = dígito verificador

---

# Configuración de producto

Agregar campos:

```txt
unidad
cantidad_decimales
permite_ean13
```

Ejemplos:

* unidad
* kg
* metro
* litro

---

# Sistema de precios

El sistema debe soportar dos modelos independientes:

## PRECIO LOCAL

## PRECIO ONLINE

---

# PRECIO LOCAL

## Costo de referencia

Para cada familia de producto:

```txt
c_ref = máximo(costo_unitario)
```

entre todos los lotes utilizados para venta.

Ejemplo:

```txt
Caja 100 → costo/u = 8
Caja 500 → costo/u = 7
Caja 11000 → costo/u = 5

c_ref = 8
```

---

## Precio de referencia

```txt
p_ref = 1.8 * c_ref
```

---

## Precio asignado

```txt
p_asignado
```

es aprobado manualmente por usuario.

Puede modificarse por:

* competencia
* mercado
* experiencia comercial

---

## Alarmas

Generar alerta si:

```txt
p_asignado < 1.3 * c_ref
```

---

# Reglas de precios

Cada producto o familia puede tener una regla.

Ejemplo:

```txt
R-1

[1-20]
techo_decena(p_asignado*3)

si total < 30
usar 30

[21-50]
techo_decena(p_asignado*2)

[51-100]
p_asignado + 4

[101-299]
p_asignado + 3

[300-10999]
p_asignado

[11000+]
1.6~1.8 * p_asignado
```

---

## Requisitos

Cada tramo debe poder:

* editarse
* aprobarse
* versionarse

---

## Asignación

Las reglas pueden asignarse a:

* producto
* familia
* categoría

---

# Agrupación de cantidades

Las cantidades deben considerar la suma de los lotes equivalentes.

Ejemplo:

```txt
Caja 100
+
Bolsa 500

=
600 unidades equivalentes
```

Las reglas de precios trabajan sobre esa cantidad agregada.

---

# Márgenes

Cada producto debe tener:

```txt
factor_minimo = 1.3
factor_objetivo = 1.8
factor_maximo_referencia = 3
```

Generar alertas cuando se salga de estos rangos.

---

# Futuro

Más adelante se implementará:

## Web Scraping

De grandes ferreterías.

## Soft Matching

Entre:

* productos externos
* productos internos

## Inteligencia de mercado

Para:

* comparar precios
* detectar oportunidades
* detectar productos fuera de mercado

---

# PRECIO ONLINE

Para WooCommerce:

```txt
c_ref = costo de ese envase específico
```

```txt
p_ref = 1.8 * c_ref
```

No utiliza agrupación entre envases.

Cada producto WooCommerce mantiene su propio precio.

---

# Revisión humana obligatoria

Todo elemento creado automáticamente debe quedar marcado como:

```txt
created_by = computer
requires_human_review = true
```

---

# Integración con sistema de tareas

Cualquier acción automática debe crear una tarea si corresponde.

Ejemplos:

## Matching automático

Crear tarea:

```txt
Revisar relación de producto
```

---

## Producto MAMUT importado

Crear tarea:

```txt
Validar categoría
```

---

## EAN13 generado

Crear tarea:

```txt
Verificar etiquetado
```

---

## Regla de precio sugerida

Crear tarea:

```txt
Aprobar lista de precios
```

---

## Producto sin relación

Crear tarea:

```txt
Relacionar producto proveedor
```

---

# Requisito importante

Todas las entidades creadas automáticamente deben:

* guardar auditoría
* guardar origen
* guardar fecha
* guardar usuario responsable (computer)
* quedar pendientes de revisión humana

Hasta que un usuario las apruebe explícitamente.

```
```
