# Prompt técnico — Sistema de Productos, Costos y Precios (Riverso)

Estoy desarrollando un sistema interno para gestión de productos, inventario y precios que debe integrarse con WooCommerce (online) y con un sistema local existente.

Este documento define cómo debe diseñarse el sistema de productos, relaciones, precios y reglas de negocio.

---

# 1. FUENTES DE DATOS ACTUALES

## Productos base
Actualmente los productos provienen de:


C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\data\raw\productos.xlsx


---

## Datos para ventas locales

El sistema local usa:


C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\CodigosBarra\codigos_barras_2026-04-01.csv
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\CodigosBarra\productos_2026-04-01.csv


Estos archivos se usan para:
- generar ventas
- leer códigos de barra
- identificar productos y lotes

---

# 2. PROBLEMA ACTUAL

Actualmente:

- Un mismo producto puede tener **múltiples proveedores**
- Esos productos están **unificados artificialmente**
- Esto ayuda a mantener precios, pero:
  - rompe el control de inventario
  - mezcla costos incorrectamente
  - dificulta trazabilidad

---

# 3. OBJETIVO PRINCIPAL

Separar correctamente:

## A. Productos por proveedor
Cada proveedor debe tener su propia representación real:
- distinto costo
- distinto lote
- distinta trazabilidad

## B. Productos equivalentes / sustitutos
Crear sistema para:

- agrupar productos equivalentes
- sincronizar precios entre ellos
- analizar diferencias de costo
- permitir reemplazos operativos

---

# 4. ESTRATEGIA DE TRANSICIÓN

## Etapa 1 (LOCAL)
- Mantener sistema actual funcional (aunque tenga errores)
- Registrar datos correctamente
- No romper flujo operativo

## Etapa 2
- Introducir estructura correcta:
  - productos separados
  - relaciones de equivalencia
  - normalización de datos

## Etapa 3 (ONLINE)
- Subir productos limpios a WooCommerce
- Mantener sincronización con sistema local

---

# 5. INTEGRACIÓN CON CATÁLOGO MAMUT

## Fuente de datos


C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\data\catalogo_mamut_2025_spatial.json


---

## Objetivo

- Importar productos de MAMUT al sistema online
- Crear categorías correctas
- Relacionar con productos existentes

---

## Problema

- Algunos productos hacen match
- Otros NO
- En local:
  - productos no tienen categoría
  - no tienen envase definido

---

## Solución requerida

Diseñar sistema escalable para:

- matching automático (soft matching)
- validación manual
- generación de tareas de revisión
- mejora progresiva del catálogo

---

# 6. SISTEMA DE ENVASES Y PRODUCTOS ABIERTOS

El sistema debe soportar:

## A. Productos cerrados (lotes)
Ejemplo:
- caja 100 unidades
- bolsa 500 unidades

---

## B. Productos abiertos

Concepto:

- abrir un lote
- transferir unidades a stock abierto

Ejemplo:


caja_100 → abrir → 100 unidades en producto_abierto


---

## C. Código especial: `codigo_abierto`

Debe permitir:
- representar inventario fraccionado
- mezclar unidades de distintos lotes

---

## D. Generación de códigos de barra EAN13

Formato:


2 + SKU (6 dígitos) + CANTIDAD (5 cifras) + dígito verificador


---

## E. Requisitos adicionales

Cada producto debe tener:

- unidad (ej: unidad, kg, metro)
- cantidad decimal permitida
- flag:
  - permite EAN13 personalizado o no

---

# 7. SISTEMA DE PRECIOS

El sistema debe soportar **dos modos independientes**:

---

# 7.1 PRECIO LOCAL

## Concepto clave

Para cada producto:

### Paso 1 — Calcular costo de referencia


c_ref = máximo(costo_por_unidad de todos los lotes)


Ejemplo:

- lote 100 → $10/u
- lote 500 → $8/u
- lote 11000 → $9/u

Entonces:


c_ref = 10


---

### Paso 2 — Precio de referencia


p_ref = 1.8 * c_ref


---

### Paso 3 — Precio asignado


p_asignado = definido por usuario


Debe permitir:
- ajuste manual
- criterio comercial
- análisis de mercado

---

### Paso 4 — Regla de seguridad

Generar alerta si:


p_asignado < 1.3 * c_ref


---

## Sistema de listas de precios

Cada producto puede tener una **lista de precios por tramos**.

Ejemplo:


R-1:
[1-20] techo_decena(p_asignado * 3), mínimo total = 30
[21-50] techo_decena(p_asignado * 2)
[51-100] p_asignado + 4
[101-299] p_asignado + 3
[300-10999] p_asignado
[11000+] (1.6 a 1.8) * p_asignado


---

## Requisitos del sistema de reglas

- cada tramo debe ser configurable
- cada regla puede:
  - aprobarse por tramo
  - aprobarse completa
- se puede asignar:
  - a producto
  - a categoría
  - a familia

---

## Agrupación de productos

Las cantidades deben considerar:


suma de lotes equivalentes del mismo producto


Ejemplo:

- caja 100
- bolsa 500

Total = 600 unidades del mismo producto

---

## Códigos de barra en ventas

El código debe identificar:
- producto
- lote (cantidad)

Ejemplo:
- caja 100 tornillo X
- bolsa 500 tornillo X

---

## Alarmas de margen

Cada producto debe tener:

- margen mínimo: 1.3 (default)
- margen máximo referencia: 3

Esto se usa para:
- análisis de precios
- alertas automáticas

---

## Futuro

Se integrará:

- web scraping de ferreterías grandes
- soft matching de productos
- análisis competitivo de precios

---

# 7.2 PRECIO ONLINE

Más simple:

Para cada producto:


p_ref = 1.8 * c_ref


Donde:


c_ref = costo del envase específico


---

# 8. OBJETIVOS DEL SISTEMA

El sistema debe permitir:

## Productos
- separación por proveedor
- relaciones de equivalencia
- control de lotes
- productos abiertos

## Precios
- reglas configurables
- precios por volumen
- control de margen
- análisis automático

## Inventario
- manejo correcto por lote
- mezcla controlada en abiertos
- trazabilidad

## Catálogo
- integración con MAMUT
- mejora progresiva
- matching automático + manual

## Operación
- compatible con sistema local
- preparado para WooCommerce online

---

# 9. REQUISITOS TÉCNICOS

- diseño escalable
- separación entre lógica local y online
- persistencia de datos limpia
- preparado para integración futura
- soporte para tareas humanas de validación
- manejo de errores y datos incompletos

---

# 10. RESULTADO ESPERADO

Quiero que uses este prompt para:

- diseñar estructura de datos
- definir modelos de productos
- implementar lógica de precios
- definir sistema de equivalencias
- crear lógica de códigos de barra
- preparar integración local + online
- soportar evolución progresiva del sistema