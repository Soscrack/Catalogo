Guiate por este plan. Haz preguntas y sugerencias de como implementarlo.
````markdown
# Prompt técnico — Riverso POS / ERP interno sobre WooCommerce

Estoy construyendo un sistema interno para mi empresa sobre **WordPress + WooCommerce**.  
Ya no es solo una tienda: quiero convertirlo en un **POS + mini ERP interno** para operación real de negocio.

Quiero que tomes este prompt como **especificación arquitectónica y funcional base** para implementar el sistema correctamente.

---

# 1. CONTEXTO GENERAL

## Plataforma actual
- **Servidor:** VPS con Plesk
- **WordPress instalado en:**  
  `/var/www/vhosts/riverso.cl/httpdocs`
- **WooCommerce activo**
- **PHP:** 8.3
- **Base de datos:** MariaDB / MySQL
- **DB name:** `wp_6z3tm`
- **DB host:** `localhost:3306`
- **Prefijo tablas WordPress:** `nExLU_`

## Objetivo
Quiero construir un plugin propio modular llamado algo como:

```txt
riverso-pos
````

Este plugin debe extender WooCommerce y convertirlo en un sistema interno para:

* ventas POS
* empleados
* permisos internos
* tareas operativas
* inventario
* recepción de compras
* cotizaciones recibidas
* facturas recibidas
* relaciones de códigos
* códigos de barra
* ubicaciones de bodega
* historial de costos
* cotizaciones emitidas a clientes
* auditoría completa de acciones

---

# 2. FILOSOFÍA TÉCNICA

## Reglas base importantes

### Sí quiero:

* usar **WordPress + WooCommerce como base**
* usar **API oficial** de WordPress/WooCommerce cuando aplique
* usar **custom tables** cuando la lógica empresarial lo requiera
* usar arquitectura modular y mantenible
* separar lógica por servicios / módulos / capas
* tener trazabilidad y auditoría real

### No quiero:

* meter toda la lógica en `functions.php`
* depender de snippets sueltos
* depender de edición manual del admin estándar de WordPress para todo
* hacer SQL crudo para cosas que WooCommerce ya resuelve bien (productos, pedidos, etc.)
* una arquitectura improvisada difícil de mantener

---

# 3. VISIÓN DEL SISTEMA

WooCommerce debe seguir siendo la **fuente de verdad comercial base** para:

* productos
* variaciones
* clientes web
* pedidos
* stock base
* cupones

Pero encima de eso quiero construir una **capa empresarial interna Riverso** para manejar la operación del negocio.

---

# 4. ARQUITECTURA CONCEPTUAL

El sistema debe dividirse en 3 grandes mundos:

---

## A. Mundo Comercial (salida)

Lo que vendemos.

Incluye:

* catálogo WooCommerce
* ventas POS
* pedidos
* clientes
* cotizaciones emitidas
* descuentos
* promociones

---

## B. Mundo Compras / Abastecimiento (entrada)

Lo que recibimos de proveedores.

Incluye:

* cotizaciones recibidas
* facturas recibidas
* XML / PDF / Excel / texto
* revisión de costos
* recepción física
* diferencias
* actualización de inventario
* relación proveedor ↔ SKU

---

## C. Mundo Operativo Interno

Lo que hace la empresa para funcionar.

Incluye:

* empleados
* permisos
* tareas
* bodegaje
* etiquetado
* códigos de barra
* ubicaciones de bodega
* auditoría
* administración de relaciones de códigos

---

# 5. ARQUITECTURA DE USUARIOS

Quiero separar claramente:

## A. Usuarios Cliente

Usuarios normales de WooCommerce:

* compran en la web
* tienen cuenta cliente
* no deben ver nada interno

Rol base:

* `customer`

---

## B. Usuarios Empleado

Usuarios internos de la empresa:

* inician sesión
* gestionan tareas
* operan documentos
* pueden usar POS si tienen permiso
* pueden editar o revisar ciertas partes del sistema según permisos

**Deben ser distintos conceptualmente de los clientes**, aunque técnicamente pueden vivir como usuarios WordPress.

---

# 6. MODELO DE PERMISOS Y ROLES

Quiero un sistema de permisos fino basado en **roles + capabilities**.

## Roles internos propuestos

### 1. `riverso_admin`

Administrador general.

Puede:

* todo
* gestionar sistema
* usuarios empleados
* permisos
* auditoría
* productos
* costos
* tareas
* POS
* compras
* recepciones
* cotizaciones emitidas

---

### 2. `riverso_ventas`

Vendedor / cotizador / POS.

Puede:

* usar POS
* crear ventas
* crear pedidos
* emitir cotizaciones a clientes
* ver clientes
* ver stock
* aplicar descuentos limitados

No puede:

* aprobar compras
* modificar costos sensibles
* administrar usuarios
* tocar configuraciones críticas

---

### 3. `riverso_bodega`

Operador de bodega.

Puede:

* ver tareas
* etiquetar productos
* marcar tareas completadas
* bodeguear productos
* revisar recepción física
* consultar ubicaciones de bodega

No puede:

* aprobar financieramente documentos
* modificar precios
* administrar usuarios

---

### 4. `riverso_compras`

Operador administrativo de compras.

Puede:

* ingresar cotizaciones recibidas
* ingresar facturas recibidas
* revisar XML / PDF / Excel / texto
* corregir ítems
* asociar productos
* aprobar/rechazar/modificar documentos según permiso
* revisar costos históricos

---

### 5. `riverso_recepciones`

Rol enfocado en recepción física.

Puede:

* registrar llegada física
* validar cantidades
* revisar ítems recibidos
* aprobar/modificar recepción
* generar incidencias

---

### 6. `riverso_edicion`

Editor de catálogo / datos maestros.

Puede:

* editar productos
* SKU
* imágenes
* relaciones de códigos
* códigos de barra
* ubicaciones de bodega
* atributos internos

---

# 7. CAPABILITIES RECOMENDADAS

Implementar capacidades finas como:

```php
manage_riverso_system
manage_riverso_users
view_riverso_dashboard
use_riverso_pos

view_received_quotes
create_received_quotes
edit_received_quotes
approve_received_quotes

view_received_invoices
create_received_invoices
edit_received_invoices
approve_received_invoices

view_tasks
assign_tasks
complete_tasks
approve_tasks

view_warehouse
edit_warehouse_locations

view_supplier_codes
edit_supplier_code_links

view_cost_history
approve_price_alerts

emit_customer_quotes
approve_customer_quotes

edit_catalog_products
edit_internal_skus
adjust_stock

view_audit_log
```

---

# 8. LOGIN Y EXPERIENCIA DE EMPLEADOS

No quiero que un empleado entre al `/wp-admin/` normal y vea menús innecesarios como:

* Entradas
* Comentarios
* Apariencia
* etc.

## Quiero un portal interno

Ejemplos:

* `/interno`
* `/empleados`
* `/pos`

### Flujo esperado

* Si el usuario es **empleado**, al iniciar sesión debe ir al portal interno
* Si el usuario es **cliente**, al iniciar sesión debe ir a **Mi Cuenta**

Usar login WordPress, pero experiencia controlada.

---

# 9. AUDITORÍA OBLIGATORIA

Quiero que el sistema tenga **auditoría completa**.

## Toda acción importante debe guardar:

* quién hizo la acción
* cuándo
* qué hizo
* en qué módulo
* sobre qué entidad
* qué cambió
* valor anterior
* valor nuevo
* observación opcional

## Tabla recomendada

`wp_riverso_audit_log`

Campos sugeridos:

* `id`
* `created_at`
* `user_id`
* `user_name_snapshot`
* `action_key`
* `entity_type`
* `entity_id`
* `entity_ref`
* `module`
* `severity`
* `old_data`
* `new_data`
* `message`
* `ip_address`

## Ejemplos de eventos

* `received_quote.created`
* `received_quote.approved`
* `received_invoice.item_modified`
* `received_invoice.approved`
* `supplier_code_link.created`
* `task.completed`
* `stock.adjusted`
* `price.alert.created`
* `customer_quote.sent`
* `barcode.assigned`
* `warehouse.location.updated`

---

# 10. SISTEMA DE TAREAS INTERNO

Quiero un **motor de tareas interno**.

## Casos reales:

* etiquetar productos
* bodegear productos
* revisar diferencias
* completar datos faltantes
* asociar códigos
* revisar alertas de precio
* ingresar documentos manualmente
* tareas administrativas urgentes

## Tabla sugerida

`wp_riverso_tasks`

Campos sugeridos:

* `id`
* `title`
* `description`
* `task_type`
* `priority`
* `status`
* `assigned_user_id`
* `assigned_role`
* `entity_type`
* `entity_id`
* `entity_ref`
* `due_at`
* `completed_at`
* `completed_by`
* `created_by`
* `created_at`
* `updated_at`

## Tipos de tarea esperados

* `receive_invoice_item`
* `review_unmatched_supplier_code`
* `label_item`
* `store_item_in_warehouse`
* `update_price_due_low_margin`
* `fill_missing_location`
* `review_cost_anomaly`
* `manual_document_entry`
* `assign_barcode_to_product`

---

# 11. COTIZACIÓN RECIBIDA (Documento de entrada)

Quiero modelar el documento **“Cotización recibida”** como un documento interno.

## Formatos de entrada posibles

* PDF
* Excel
* Texto
* Ingreso manual

## Recursos existentes

Tengo una base de código / sistema previo en:

```txt
C:\Users\jorge\source\repos\Bodega
```

Quiero que analices la estructura de ese sistema, su modelo de base de datos y su lógica útil para **integrarlo conceptualmente** dentro de este nuevo sistema Riverso.

## Requisito importante

Ese sistema debe **mejorarse** para incluir:

* auditoría de usuario
* trazabilidad
* integración con tareas
* integración con permisos

## Sobre extracción automática

Ese sistema previo tiene **extractor para PDF**, pero no soporta bien otros formatos.

### Quiero esta regla:

* si el documento puede parsearse automáticamente → usarlo
* si no puede parsearse → llevar a **ingreso manual asistido**

---

## Tabla sugerida: `wp_riverso_received_quotes`

Campos sugeridos:

* `id`
* `supplier_id`
* `document_number`
* `document_date`
* `received_at`
* `source_type`
* `source_file_path`
* `status`
* `currency`
* `notes`
* `created_by`
* `updated_by`
* `approved_by`
* `approved_at`

## Tabla sugerida: `wp_riverso_received_quote_items`

Campos sugeridos:

* `id`
* `quote_id`
* `line_number`
* `supplier_code`
* `supplier_barcode`
* `description`
* `qty`
* `unit`
* `cost_net`
* `cost_tax`
* `cost_total`
* `matched_product_id`
* `matched_variation_id`
* `matched_sku`
* `match_status`
* `decision_status`
* `decision_notes`
* `created_by`
* `updated_by`

---

## Flujo esperado de “Cotización recibida”

Estados sugeridos:

* `draft`
* `uploaded`
* `parsed`
* `under_review`
* `approved`
* `rejected`
* `converted_to_expected_arrival`
* `archived`

### Flujo

1. llega archivo
2. se sube
3. se intenta parsear
4. si no se puede, se ingresa manualmente
5. se revisan ítems
6. se comparan con precios/costos históricos
7. se corrigen si aplica
8. se aprueba
9. se deja trazabilidad de qué productos se espera que lleguen

---

# 12. FACTURACIÓN RECIBIDA (Documento de entrada crítico)

Quiero modelar **“Facturación recibida”** como un documento interno con flujo completo.

## Formato principal

* XML manualmente ingresado

## Contexto operativo

Cuando llega físicamente una compra, normalmente nos dice el **número de factura**, y desde ahí queremos iniciar el flujo de recepción.

## Casos reales

* a veces está asociada a una cotización recibida
* a veces no
* a veces no llega todo
* a veces llegan productos incorrectos
* a veces llegan cantidades distintas

---

## Tabla sugerida: `wp_riverso_received_invoices`

Campos sugeridos:

* `id`
* `supplier_id`
* `quote_id`
* `invoice_number`
* `document_date`
* `received_at`
* `source_type`
* `source_file_path`
* `xml_raw`
* `status`
* `currency`
* `notes`
* `created_by`
* `updated_by`
* `approved_by`
* `approved_at`

## Tabla sugerida: `wp_riverso_received_invoice_items`

Campos sugeridos:

* `id`
* `invoice_id`
* `line_number`
* `supplier_code`
* `supplier_barcode`
* `description`
* `qty_invoiced`
* `qty_received`
* `qty_approved`
* `unit`
* `unit_cost`
* `line_total`
* `matched_product_id`
* `matched_variation_id`
* `matched_sku`
* `match_status`
* `item_status`
* `item_decision_notes`
* `created_by`
* `updated_by`
* `approved_by`
* `approved_at`

---

## Flujo esperado de “Facturación recibida”

### Etapa 1 — Ingreso documental

* se sube XML
* se parsea
* se registra número de factura
* se crean los ítems

### Etapa 2 — Llegada física

Cuando físicamente llega:

* se busca por número de factura
* se inicia recepción

### Etapa 3 — Revisión por ítem

Cada ítem debe poder quedar en estados como:

* `pending`
* `received_ok`
* `modified`
* `missing`
* `extra`
* `rejected`
* `approved`

### Etapa 4 — Aprobación total

Solo cuando **todos los ítems** estén resueltos:

* aprobados
* modificados
* o rechazados/resueltos

…recién se puede aprobar el documento completo.

---

# 13. COMPORTAMIENTO AUTOMÁTICO AL APROBAR FACTURA RECIBIDA

Una vez aprobada una factura recibida:

## A. Guardar historial de costos

Cada ítem aprobado debe guardar histórico de costo.

## B. Intentar hacer match proveedor ↔ SKU interno

Usar:

* código proveedor
* código barra proveedor
* descripción
* relaciones previas

## C. Si hay match con producto

Entonces:

* actualizar inventario / recepción
* generar tarea de etiquetado
* generar tarea de bodegaje

## D. Si NO hay match

Entonces crear tarea urgente:

* `review_unmatched_supplier_code`

Que sirva para:

* asociar ese código del proveedor a un producto interno
* resolver el problema administrativamente

## E. Si el producto no tiene ubicación de bodega

Crear tarea:

* `fill_missing_location`

## F. Si el precio de venta está bajo respecto al nuevo costo

Aplicar regla:

```txt
si precio_venta < 1.5 * costo
```

Entonces crear tarea:

* `update_price_due_low_margin`

Esto debe servir como **alerta operativa/comercial**.

---

# 14. RELACIÓN DE CÓDIGOS PROVEEDOR ↔ PRODUCTO

Necesito un sistema fuerte para relacionar:

* SKU interno
* código proveedor
* código de barra
* descripción del proveedor
* producto / variación WooCommerce

## Tabla sugerida

`wp_riverso_supplier_product_links`

Campos sugeridos:

* `id`
* `supplier_id`
* `supplier_code`
* `supplier_barcode`
* `supplier_description`
* `product_id`
* `variation_id`
* `internal_sku`
* `is_primary`
* `is_active`
* `created_by`
* `updated_by`
* `created_at`
* `updated_at`

---

# 15. CÓDIGOS DE BARRA

## Recurso existente

Tengo códigos de barra guardados en:

```txt
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\CodigosBarra
```

Quiero que este recurso también se integre conceptualmente al sistema.

## Comportamiento esperado

Cuando el sistema lea / escanee / procese un **código de barra**:

### Si el código de barra ya existe

* mostrar a qué producto/variación corresponde
* permitir usarlo para búsqueda / recepción / etiquetado / venta

### Si el código de barra NO existe

Quiero que el sistema pregunte algo como:

> “Este código de barra no está asociado a ningún producto.
> ¿Deseas crear una tarea para asignarlo?”

Y debe permitir:

* crear tarea `assign_barcode_to_product`
* guardar el código leído
* asociarlo a proveedor si aplica
* dejar comentario
* **permitir guardar una foto**

  * del producto
  * del envase
  * de la etiqueta
  * o del código escaneado físicamente

## Esto es importante

La foto debe poder quedar asociada a:

* la tarea
* el documento
* el ítem
* o la propuesta de asignación de código

---

# 16. HISTORIAL DE COSTOS

Necesito guardar historial de costos por documento y por producto.

## Tabla sugerida

`wp_riverso_cost_history`

Campos sugeridos:

* `id`
* `product_id`
* `variation_id`
* `supplier_id`
* `source_type`
* `source_document_id`
* `source_item_id`
* `supplier_code`
* `cost`
* `currency`
* `document_date`
* `created_at`
* `created_by`

Esto debe servir para:

* comparar nuevas cotizaciones
* detectar alzas
* detectar caídas
* sugerir revisión de precio
* auditar costos históricos

---

# 17. UBICACIONES DE BODEGA

Quiero modelar ubicaciones de bodega de forma seria.

## Tabla sugerida

`wp_riverso_warehouse_locations`

Campos sugeridos:

* `id`
* `code`
* `name`
* `zone`
* `aisle`
* `rack`
* `level_name`
* `bin`
* `is_active`
* `notes`

## Relación producto ↔ ubicación

Tabla sugerida:
`wp_riverso_product_locations`

Campos sugeridos:

* `id`
* `product_id`
* `variation_id`
* `location_id`
* `is_primary`
* `min_stock`
* `max_stock`

## Uso esperado

Permitir tareas como:

* “Bodegear 30 unidades de SKU X en A-03-R2-B4”
* “Falta ubicación para este producto”
* “Mover producto de ubicación”

---

# 18. ETIQUETADO Y BODEGAJE

Una vez aprobada una recepción / factura:

Quiero generar tareas por ítem como:

## Tarea de etiquetado

* cantidad a etiquetar
* SKU / producto / variación
* código de barra si existe
* posibilidad de marcar completado

## Tarea de bodegaje

* cantidad a guardar
* ubicación sugerida
* ubicación faltante si no existe
* posibilidad de marcar completado

---

# 19. EMITIR COTIZACIÓN A CLIENTES

Quiero también un módulo de **Emitir Cotización** para clientes.

No debe ser solo un pedido draft.

## Debe permitir:

* seleccionar cliente o cliente manual
* agregar productos WooCommerce
* modificar precios
* ofrecer descuentos
* cotizar al por mayor
* dejar observaciones
* tener vigencia de **3 días**
* enviarse luego por PDF / WhatsApp / email
* poder convertirse a pedido / venta POS

---

## Tabla sugerida: `wp_riverso_customer_quotes`

Campos sugeridos:

* `id`
* `customer_id`
* `customer_name`
* `customer_email`
* `customer_phone`
* `quote_number`
* `status`
* `valid_until`
* `subtotal`
* `discount_total`
* `tax_total`
* `grand_total`
* `notes`
* `created_by`
* `updated_by`
* `approved_by`
* `created_at`
* `updated_at`

## Tabla sugerida: `wp_riverso_customer_quote_items`

Campos sugeridos:

* `id`
* `quote_id`
* `product_id`
* `variation_id`
* `sku`
* `description`
* `qty`
* `unit_price`
* `discount_amount`
* `line_total`

## Regla de negocio

La cotización debe vencer automáticamente en:

```txt
fecha_creación + 3 días
```

---

# 20. POS INTERNO

Quiero un POS interno para empleados autorizados.

Debe permitir:

* buscar productos
* agregar al carro
* vender rápido
* seleccionar cliente
* usar descuentos según permiso
* usar SKU / código de barra / nombre para búsqueda
* crear pedido/venta
* registrar método de pago
* funcionar como sistema de caja interna

El acceso debe depender de capability como:

```php
use_riverso_pos
```

---

# 21. PRODUCTOS VARIABLES Y CATÁLOGO OPERATIVO

Ya existe trabajo previo para automatizar productos variables WooCommerce correctamente usando APIs oficiales.

Eso debe convivir con este sistema.

Además quiero una GUI interna para:

* controlar atributos
* variaciones
* SKU
* relaciones proveedor ↔ producto
* códigos internos
* códigos de barra
* ubicaciones
* historial de costo

---

# 22. ESTRUCTURA TÉCNICA DE PLUGIN ESPERADA

Quiero una arquitectura modular y mantenible, separada por capas.

## Estructura objetivo aproximada

```txt
riverso-pos/
│
├── riverso-pos.php
├── uninstall.php
├── readme.txt
│
├── includes/
│   ├── class-loader.php
│   ├── class-assets.php
│   ├── class-admin-menu.php
│   ├── class-permissions.php
│   ├── class-db.php
│   ├── class-ajax.php
│   ├── class-rest.php
│   ├── class-helpers.php
│   ├── class-notices.php
│   └── class-logger.php
│
├── modules/
│   ├── dashboard/
│   ├── access/
│   ├── employees/
│   ├── tasks/
│   ├── suppliers/
│   ├── procurement/
│   ├── receiving/
│   ├── inventory/
│   ├── catalog/
│   ├── quotations/
│   ├── pos/
│   ├── audit/
│   ├── maintenance/
│   ├── settings/
│   ├── stats/
│   └── messaging/
│
├── assets/
│   ├── css/
│   ├── js/
│   └── img/
│
├── templates/
│   └── partials/
│
└── languages/
```

---

# 23. LO QUE QUIERO QUE IMPLEMENTES / GENERES

Quiero que uses este prompt para ayudarme a construir el sistema correctamente.

## Quiero que propongas e implementes progresivamente:

### Fase 1 — Fundaciones

1. sistema de roles y permissions
2. sistema de empleados
3. sistema de auditoría
4. sistema de tareas

### Fase 2 — Compras y recepción

5. cotizaciones recibidas
6. facturas recibidas
7. parser / ingreso manual
8. match proveedor ↔ SKU
9. tareas automáticas

### Fase 3 — Catálogo operativo

10. relaciones de códigos
11. códigos de barra
12. ubicaciones de bodega
13. historial de costos

### Fase 4 — Comercial

14. cotizaciones emitidas
15. POS interno
16. integración comercial con WooCommerce

---

# 24. REQUISITOS DE IMPLEMENTACIÓN IMPORTANTES

## Prioridades

* claridad de arquitectura
* seguridad
* mantenibilidad
* permisos correctos
* trazabilidad real
* evitar corrupción de stock/datos
* integrarse bien con WooCommerce

## En especial quiero:

* clases limpias
* separación por servicios
* custom tables con `dbDelta` cuando corresponda
* hooks bien organizados
* funciones reutilizables
* diseño preparado para crecer

---

# 25. SALIDA ESPERADA DE TU TRABAJO

Quiero que, usando esta especificación, me ayudes a producir cosas como:

* arquitectura técnica detallada
* diseño de base de datos
* clases PHP
* módulos del plugin
* flujos funcionales
* permisos y roles
* servicios de negocio
* pantallas admin / internas
* tareas y automatizaciones

---

# 26. PRIMERA TAREA PRIORITARIA

Quiero comenzar por la base correcta.

## Prioridad inmediata:

Diseñar e implementar primero:

1. **Módulo de Usuarios / Empleados / Permisos**
2. **Módulo de Auditoría**
3. **Módulo de Tareas**
4. **Base de datos custom inicial**
5. **Control de acceso al portal interno**

Ese debe ser el punto de partida del sistema.

```

Si quieres, el siguiente paso útil es que te lo convierta en una **versión aún más “Copilot-friendly”**, o sea:

- con **tickets concretos por módulo**
- checklist de implementación
- orden exacto de archivos PHP a crear
- y tablas SQL listas para construir

Esa versión te va a servir mucho más para **trabajar directamente con Copilot / Claude Code / Cursor**.
```
