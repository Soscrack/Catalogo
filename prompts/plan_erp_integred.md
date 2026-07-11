# Reorganización de la Arquitectura de Riverso ERP

Quiero que revises toda la estructura actual del proyecto **`riverso/interno`** y propongas una reorganización completa de la arquitectura para convertirlo en un **ERP modular**, escalable y mantenible.

No quiero únicamente mover carpetas. Quiero un análisis arquitectónico del sistema actual, detectar módulos faltantes, dependencias incorrectas y proponer una estructura que pueda soportar el crecimiento del proyecto durante muchos años.

---

# Objetivos

Quiero que Riverso deje de ser únicamente una tienda WooCommerce con módulos adicionales y pase a ser un ERP donde WooCommerce sea solamente uno de los módulos del sistema.

WooCommerce seguirá siendo la fuente oficial para:

* Productos publicados
* Pedidos online
* Clientes de la tienda
* Carrito
* Checkout

Pero toda la lógica empresarial debe vivir dentro de Riverso.

---

# Primera tarea

Analiza todos los módulos existentes dentro de:

```text
riverso/interno
```

y responde:

* Qué hace cada módulo.
* Qué responsabilidades tiene.
* Qué responsabilidades están mezcladas.
* Qué responsabilidades faltan.
* Qué dependencias deberían eliminarse.
* Qué módulos deberían dividirse.
* Qué módulos deberían fusionarse.

Después propón una arquitectura más limpia.

---

# Arquitectura esperada

Quiero una arquitectura modular basada en dominios de negocio.

Por ejemplo (solo como referencia, puedes mejorarla):

```text
interno/

core/
auth/
employees/
permissions/
audit/
tasks/

catalog/
products/
attributes/
categories/
suppliers/
barcodes/

inventory/
stock/
warehouse/
locations/
stock_count/
movements/

pricing/
costs/
price_lists/
margin_rules/
market_analysis/

sales/
pos/
customer_quotes/
orders/

purchases/
received_quotes/
received_invoices/
supplier_products/

woocommerce/
sync/
import/
export/

reports/
dashboard/
notifications/

settings/
```

No copies exactamente esta estructura si encuentras una mejor.

Quiero que propongas la mejor arquitectura posible.

---

# Debe planificarse como un ERP completo

Quiero detectar qué módulos faltan para que Riverso pueda convertirse en un ERP completo.

Además de los módulos existentes, quiero que evalúes si hacen falta módulos como:

## Gestión Comercial

* POS
* Ventas
* Cotizaciones
* Pedidos
* Clientes
* Promociones
* Listas de precios

---

## Compras

* Cotizaciones recibidas
* Facturas recibidas
* Recepción de mercadería
* Órdenes de compra futuras
* Costos históricos

---

## Inventario

* Stock
* Conteo
* Movimientos
* Ajustes
* Kardex
* Reservas
* Inventario por ubicación

---

## Productos

* Productos locales
* Productos WooCommerce
* Productos equivalentes
* Productos de proveedor
* Variaciones
* Envases

---

## Logística

* Ubicaciones
* Bodega
* Picking
* Etiquetado
* Recepción

---

## Administración

* Usuarios
* Empleados
* Roles
* Auditoría
* Tareas
* Configuración

---

## Reportes

* Ventas
* Costos
* Márgenes
* Inventario
* Productos
* Compras
* Auditoría

---

# Integración Tienda Física ↔ Tienda Online

Quiero asegurar una integración correcta entre ambos mundos.

## Tienda Online

WooCommerce.

Debe gestionar:

* catálogo público
* pedidos online
* clientes web
* pagos

---

## Tienda Física

ERP Riverso.

Debe gestionar:

* POS
* inventario real
* recepción
* compras
* costos
* proveedores
* tareas

---

## Requisito

No quiero duplicar información innecesariamente.

Debe existir una sincronización clara entre ambos sistemas.

Para cada entidad importante quiero que definas:

* cuál es la fuente de verdad
* qué información sincroniza
* cuándo sincroniza
* en qué dirección sincroniza

Ejemplo:

Producto

Fuente de verdad:
Riverso

WooCommerce recibe:

* nombre
* imágenes
* atributos
* variaciones
* precios online
* stock online

---

# Sistema de Historial

Quiero que el ERP tenga historial completo.

Debe planificarse un módulo específico para:

## Historial de costos

Guardar:

* costo
* proveedor
* documento origen
* usuario
* fecha

---

## Historial de precios

Guardar:

* precio sugerido
* precio aprobado
* precio online
* precio local

---

## Historial de cambios

Registrar:

* quién cambió
* cuándo
* por qué
* valores anteriores
* valores nuevos

---

# Sistema de Gestión de Stock

Debe existir un módulo independiente.

Debe soportar:

## Stock por producto

## Stock por proveedor

## Stock por envase

## Stock abierto

## Stock reservado

## Stock comprometido

## Stock online

## Stock local

---

Debe poder generar movimientos:

* entrada
* salida
* ajuste
* recepción
* venta
* devolución
* apertura de envase
* generación de bolsas

---

# Asociación de Códigos

Quiero rediseñar completamente la gestión de códigos.

Cada código de barras debe poder asociarse a:

Producto

↓

Proveedor

↓

Cantidad

↓

Unidad de medida

↓

Envase

Conceptualmente:

```text
Código de Barra
        │
        ▼
Producto
        │
Proveedor
        │
Cantidad
        │
Unidad
        │
Envase
```

No todos los códigos representan el mismo envase.

Ejemplo:

Producto:

Tornillo Drywall

Código A

* Caja 100

Código B

* Bolsa 500

Código C

* Caja 1000

Todos pertenecen al mismo producto, pero representan distintos envases.

---

# Conteo de Inventario

Quiero un módulo especializado.

Debe permitir:

* conteo por producto
* conteo por ubicación
* conteo parcial
* conteo completo

Debe funcionar leyendo códigos de barras.

Cada lectura debe identificar:

* producto
* proveedor
* envase
* cantidad
* unidad

Debe acumular correctamente las cantidades.

Debe permitir:

* diferencias
* ajustes
* auditoría
* aprobación

---

# Sistema POS

Quiero un módulo POS independiente.

Debe incluir:

## Búsqueda

* SKU
* nombre
* código de barra

---

## Venta

* agregar productos
* modificar cantidades
* descuentos
* cliente
* métodos de pago

---

## Integración

Las ventas deben:

* descontar stock
* registrar movimientos
* generar auditoría
* sincronizar con WooCommerce cuando corresponda

---

# Sistema de Cotizaciones

Debe existir un módulo completo.

No quiero que sea solamente un pedido en borrador.

Debe soportar:

## Cotizaciones Locales

Para clientes de la tienda física.

---

## Cotizaciones Online

Relacionadas con WooCommerce.

---

Cada cotización debe permitir:

* agregar productos
* modificar precios
* aplicar descuentos
* vigencia
* observaciones
* conversión a venta

---

# Revisión de Dependencias

Durante el análisis quiero que detectes:

* código duplicado
* módulos demasiado grandes
* servicios que deberían separarse
* modelos repetidos
* utilidades reutilizables

---

# Resultado esperado

No quiero que implementes inmediatamente todos los cambios.

Primero quiero un documento técnico donde propongas:

1. La nueva estructura de módulos.
2. Qué módulos existentes deben moverse.
3. Qué módulos faltan.
4. Qué módulos deben dividirse.
5. Qué módulos deben fusionarse.
6. Qué dependencias deben eliminarse.
7. Un diagrama conceptual de cómo interactúan los módulos.
8. Un roadmap de implementación por fases.

Prioriza una arquitectura limpia, mantenible y preparada para crecer durante muchos años sin convertirse en un proyecto difícil de mantener.

---

# Requisito Crítico: Preservar y Mejorar los Sistemas Base

Durante la reorganización del proyecto **NO quiero perder funcionalidades existentes**.

Toda la refactorización debe preservar la compatibilidad con el sistema actual y mejorar su diseño, no reemplazarlo innecesariamente.

En particular, quiero que se preserve y mejore la arquitectura de los siguientes módulos:

## Sistema de Auditoría

El sistema de auditoría debe mantenerse como un componente central del ERP.

Debe ampliarse para registrar todas las acciones importantes del sistema.

Como mínimo debe auditar:

- creación
- modificación
- eliminación lógica
- restauración
- cambios de permisos
- cambios de precios
- cambios de costos
- cambios de inventario
- movimientos de stock
- publicaciones en WooCommerce
- sincronizaciones
- importaciones
- exportaciones
- aprobaciones humanas
- inicio y cierre de sesión
- ejecución de procesos automáticos

Cada evento debe registrar como mínimo:

- usuario
- empleado
- fecha y hora
- IP (cuando corresponda)
- módulo
- entidad afectada
- ID de la entidad
- acción realizada
- valores anteriores
- valores nuevos
- origen (human, computer, import, migration, api, cron, etc.)

La auditoría debe diseñarse para ser consultable mediante filtros y generar reportes históricos.

---

## Sistema de Tareas

El sistema de tareas debe mantenerse y convertirse en uno de los pilares del ERP.

No quiero un simple listado de tareas.

Debe evolucionar hacia un verdadero sistema de workflow.

Las tareas deben poder:

- asignarse a empleados
- reasignarse
- priorizarse
- depender de otras tareas
- tener fechas límite
- tener comentarios
- tener archivos adjuntos
- registrar historial
- registrar cambios de estado
- tener subtareas
- generar notificaciones

Las tareas deben poder ser creadas automáticamente por cualquier módulo del ERP.

Ejemplos:

- revisar un producto importado
- confirmar un soft-match
- aprobar un precio
- revisar una factura
- realizar un conteo de stock
- etiquetar productos
- almacenar mercadería
- corregir códigos de barra
- revisar diferencias de inventario

Quiero que cualquier módulo pueda generar tareas sin depender directamente del módulo de tareas, mediante una arquitectura desacoplada (servicios, eventos o colas).

---

## Sistema de Permisos

El sistema de permisos debe mantenerse pero ser rediseñado para soportar un ERP completo.

No quiero únicamente Roles de WordPress.

Debe existir un sistema de permisos propio de Riverso, integrado con los usuarios de WordPress.

Debe soportar:

- Roles
- Permisos
- Grupos
- Equipos
- Departamentos
- Permisos por módulo
- Permisos por acción
- Permisos por ubicación
- Permisos por sucursal (si existen en el futuro)

Ejemplos de permisos:

- Ver productos
- Crear productos
- Editar productos
- Eliminar productos
- Publicar productos
- Aprobar precios
- Modificar costos
- Aprobar facturas
- Crear cotizaciones
- Confirmar recepción
- Hacer conteos
- Ajustar inventario
- Usar POS
- Administrar usuarios
- Administrar permisos

El sistema debe ser fácilmente extensible para que cualquier nuevo módulo pueda registrar automáticamente sus propios permisos.

---

## Sistema de Empleados

El ERP debe diferenciar claramente:

- Usuarios de WordPress (clientes)
- Empleados de Riverso

Un empleado puede estar asociado a un usuario de WordPress, pero el modelo de empleados debe contener información propia del ERP, como:

- cargo
- departamento
- supervisor
- permisos
- historial de acciones
- tareas asignadas
- rendimiento
- actividad

Todos los módulos deberán trabajar con empleados cuando corresponda, no únicamente con usuarios de WordPress.

---

## Arquitectura Base

Estos cuatro sistemas deben considerarse infraestructura del ERP:

- Auditoría
- Tareas
- Permisos
- Empleados

Todos los demás módulos deberán integrarse con ellos.

No quiero lógica duplicada.

Quiero servicios reutilizables, desacoplados y fácilmente extensibles.

Si durante el análisis encuentras oportunidades para mejorar significativamente la arquitectura de estos sistemas, propón las mejoras, pero asegurándote de preservar la funcionalidad existente y facilitar una migración gradual sin romper el sistema actual.