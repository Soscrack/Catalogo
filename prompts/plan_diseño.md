Estoy trabajando en una tienda WooCommerce en un VPS con Plesk y quiero automatizar la creación de productos variables desde código.
Para eso contruir una GUI para llevar el control de los productos, sus variaciones y atributos y que atrubutos se usaran para  crear variaciones en WooCommerce.

# ENTORNO

- Servidor con Plesk
- WordPress instalado en:
  /var/www/vhosts/riverso.cl/httpdocs

- Base de datos:
  - DB name: wp_6z3tm
  - DB server: localhost:3306
  - Motor: MariaDB

- Prefijo de tablas de WordPress:
  nExLU_

- Versión WordPress:
  6.9.4

- WooCommerce:
  usa tablas estándar + lookup tables como:
  - nExLU_posts
  - nExLU_postmeta
  - nExLU_terms
  - nExLU_term_taxonomy
  - nExLU_term_relationships
  - nExLU_wc_product_meta_lookup

# IMPORTANTE

NO quiero crear productos insertando directamente en SQL.
Quiero hacerlo correctamente usando la API de WooCommerce / WordPress en PHP para que:

- se creen productos válidos
- se creen variaciones válidas
- se actualicen metadatos correctamente
- WooCommerce regenere lookup/meta correctamente
- el producto quede visible y usable en el panel

# CÓMO EJECUTO CÓDIGO

Tengo WP-CLI funcional con este alias:

alias wpr='sudo -u riverso.cl_1xybiw6rlcq /opt/plesk/php/8.3/bin/php /usr/local/bin/wp --path=/var/www/vhosts/riverso.cl/httpdocs'

Puedo ejecutar scripts PHP así:

wpr eval-file archivo.php

También puedo inspeccionar productos así:

wpr post list --post_type=product --fields=ID,post_title,post_status --format=table

wpr post list --post_type=product_variation --fields=ID,post_parent,post_title,post_status --format=table

wpr post meta list 120

# MODELO DE PRODUCTO QUE QUIERO

Estoy vendiendo productos tipo ferretería/tornillería.

Quiero modelar los productos variables usando:
- un atributo combinado REAL de variación:
  "Nominal X Largo"

Y además quiero conservar:
- "Nominal"
- "Largo"

como atributos informativos/no-variación.

# EJEMPLO REAL DE PRODUCTO VARIABLE

Producto padre:
- ID: 120
- Título: Tornillo de Prueba (copia)

Sus atributos están guardados en _product_attributes así:

- nominal
  - name = "Nominal"
  - values = "8 | 10"
  - is_visible = 1
  - is_variation = 0
  - is_taxonomy = 0

- largo
  - name = "Largo"
  - values = '1/2" | 3/4" | 1" | 1.1/4" | 1.1/2" | 2" | 2.1/2"'
  - is_visible = 1
  - is_variation = 0
  - is_taxonomy = 0

- nominal-x-largo
  - name = "Nominal X Largo"
  - values = '8 x 1/2" | 8 x 3/4" | 8 x 1" | 8 x 1.1/4" | 8 x 1.1/2" | 8 x 2" | 8 x 2.1/2" | 10 x 1/2" | 10 x 3/4" | 10 x 1" | 10 x 1.1/4" | 10 x 1.1/2" | 10 x 2" | 10 x 2.1/2"'
  - is_visible = 0
  - is_variation = 1
  - is_taxonomy = 0

También tiene:
- _default_attributes = nominal-x-largo => '8 x 1/2"'

# ESTRUCTURA DE VARIACIONES

Cada variación hija tiene SOLO este atributo:

attribute_nominal-x-largo

Ejemplo real:

Variación ID 121:
- parent = 120
- attribute_nominal-x-largo = '8 x 1/2"'
- _sku = 'facto0011-1'
- _regular_price = '15'
- _price = '15'
- _stock_status = 'instock'
- _manage_stock = 'no'

Otra variación:
- attribute_nominal-x-largo = '8 x 3/4"'
- _sku = 'facto0012-1'
- _price = '17'
- _stock = '10000'

# REGLAS DE NEGOCIO

Quiero crear productos variables de este tipo automáticamente desde código.

Cada producto debería poder recibir:

- nombre del producto
- descripción opcional
- estado (draft/private/publish)
- imagen destacada opcional (si después se agrega)
- lista de valores de Nominal
- lista de valores de Largo
- lista de combinaciones reales de "Nominal X Largo"
- para cada combinación:
  - sku
  - precio
  - stock opcional
  - estado de stock
  - descripción opcional de variación

# OBJETIVO DE AUTOMATIZACIÓN

Quiero un script PHP reutilizable que:

1. Cree un producto variable WooCommerce correctamente
2. Asigne el tipo "variable"
3. Configure atributos del producto:
   - Nominal (informativo)
   - Largo (informativo)
   - Nominal X Largo (variación real)
4. Cree las variaciones hijas
5. Asigne por cada variación:
   - SKU
   - precio regular
   - precio actual
   - stock si existe
   - manage_stock yes/no según corresponda
   - stock_status
   - attribute_nominal-x-largo
6. Defina un valor por defecto si se entrega
7. Guarde todo correctamente usando WooCommerce / WordPress
8. Devuelva el ID del producto creado
9. Sea fácil de reutilizar con arrays de entrada

# MUY IMPORTANTE

Prefiero usar clases oficiales WooCommerce si es posible, por ejemplo:

- WC_Product_Variable
- WC_Product_Variation
- WC_Product_Attribute

y funciones oficiales como:
- wp_set_object_terms
- update_post_meta si hace falta
- wc_get_product
- wc_delete_product_transients si es necesario
- WC_Product_Variable::sync si aplica según versión

# LO QUE NO QUIERO

- NO quiero SQL raw para insertar productos
- NO quiero hacks frágiles
- NO quiero depender del panel manualmente
- NO quiero usar plugins externos
- NO quiero código que asuma taxonomías globales si estoy usando atributos personalizados locales

# FORMA DE TRABAJO QUE QUIERO

Ayúdame a escribir:

1. un archivo PHP ejecutable con:
   wpr eval-file crear_producto_variable.php

2. y ojalá también una versión más limpia con una función como:

crear_producto_variable([
  'nombre' => 'TORNILLO HEXAGONAL INOX',
  'estado' => 'private',
  'nominales' => ['M6', 'M8'],
  'largos' => ['30MM', '40MM'],
  'variaciones' => [
    [
      'valor' => 'M6 x 30MM',
      'sku' => '15001',
      'precio' => '18.45',
      'stock' => null,
      'stock_status' => 'instock'
    ],
    [
      'valor' => 'M8 x 40MM',
      'sku' => '15002',
      'precio' => '25.29',
      'stock' => null,
      'stock_status' => 'instock'
    ]
  ],
  'default' => 'M6 x 30MM'
]);

# EXTRA

Si es posible, también quiero que el script:
- evite duplicar SKUs existentes
- opcionalmente actualice un producto si ya existe por nombre o SKU base
- pueda ser base para después importar desde CSV o JSON

# TAREA PARA TI

Quiero que me ayudes a construir ese script PHP correctamente para WooCommerce.
Quiero que WooCommerce tenga una estructura de permisos para Usuarios de Cotizaciones , Ventas y Edicion. Quiero que tanga un 

# CREDENCIALES PARA EXPLORACION

En .env estan las credencieles para explorar, WooCommerce tiene productos de prueba.

# Objetivos Extras
En C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\XML hay facturas de los archivos que nos llegan. Al mometo de llegar un pedido llega una de esas facturaz deberiamos ser capaces de encontrarla en la pagína, modificar el contenido si es que algo no llega, aceptarla. Y con los codigos del proveedor relacionado con los SKU's hacer tareas de etiquetado para los trabajadore, tareas de rodenar en la bodega que diga el lugar de la bodega asociado en la bade de datos, que los empleados puedan terminar las tareas. Que cuando falte informacion por que no se encuentrasn los codigos hacer tareas administrativas para rellenar lugares o realciones de codigos faltantes. Resolver y llevar historial de codigos tanto locales, proveedores y de barra. Hacer un gestor de tareas.



# PRIMERA IDEA  DE: Arquitectura técnica — Riverso POS para WooCommerce

## 1. Visión del sistema

**Riverso POS** es un plugin modular para WordPress + WooCommerce que convierte la tienda online en un **sistema POS / TPV / mini ERP interno** para operación de negocio.

No es solo una tienda.  
Debe servir para administrar:

- ventas de caja
- inventario real
- cuentas por cobrar / clientes con deuda
- promociones
- listados operativos
- estadísticas
- control interno
- comunicaciones con clientes

---

## 2. Objetivos técnicos

### Objetivos principales
1. **Extender WooCommerce**, no reemplazarlo.
2. Mantener a WooCommerce como **fuente de verdad** para:
   - productos
   - pedidos
   - clientes
   - stock base
   - cupones
3. Agregar una **capa operativa POS** encima de WooCommerce.
4. Implementar una arquitectura **modular, mantenible y escalable**.
5. Diseñar primero para **uso administrativo en escritorio**.
6. Preparar el sistema para evolucionar luego hacia:
   - multiusuario
   - caja real
   - métricas
   - facturación
   - integración con WhatsApp y medios de pago

---

## 3. Stack técnico esperado

### Backend
- PHP 8.x
- WordPress
- WooCommerce
- MySQL / MariaDB

### Frontend Admin
- HTML
- CSS
- JavaScript
- WordPress Admin UI
- AJAX o WP REST API

### Integraciones previstas
- WooCommerce Orders API
- WooCommerce Product API
- User Meta / Order Meta / Product Meta
- Posibles tablas personalizadas
- WhatsApp links / API futura
- Facturación electrónica futura
- Pasarelas de pago futuras

---

## 4. Filosofía arquitectónica

### Principio base
El sistema debe ser un plugin propio llamado algo como:

`riverso-pos`

### Razón
No conviene meter esta lógica en:
- `functions.php`
- snippets sueltos
- theme files

Porque eso vuelve el sistema:
- difícil de mantener
- difícil de migrar
- frágil ante actualizaciones

---

## 5. Arquitectura por capas

Quiero que el sistema siga una arquitectura separada en capas.

### Capas del sistema

#### A. Bootstrap Layer
Responsable de:
- cargar el plugin
- definir constantes
- registrar hooks principales
- cargar módulos

#### B. Core Layer
Responsable de:
- utilidades comunes
- permisos
- helpers
- base de datos
- assets
- AJAX / REST
- navegación del admin

#### C. Domain / Modules Layer
Responsable de la lógica de negocio real por módulo:
- ventas
- stock
- deuda
- promociones
- listados
- etc.

#### D. Presentation Layer
Responsable de:
- pantallas admin
- dashboard
- formularios
- tablas
- modales
- widgets POS

---

## 6. Estructura de carpetas propuesta

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
│   │   ├── class-dashboard-module.php
│   │   └── views/
│   │       └── dashboard-page.php
│   │
│   ├── maintenance/
│   │   ├── class-maintenance-module.php
│   │   └── views/
│   │       └── maintenance-page.php
│   │
│   ├── sales/
│   │   ├── class-sales-module.php
│   │   ├── class-sales-service.php
│   │   ├── class-cart-service.php
│   │   ├── class-checkout-service.php
│   │   └── views/
│   │       ├── sales-page.php
│   │       ├── sales-cart.php
│   │       └── sales-search.php
│   │
│   ├── listings/
│   │   ├── class-listings-module.php
│   │   ├── class-products-list-table.php
│   │   ├── class-orders-list-table.php
│   │   ├── class-debts-list-table.php
│   │   └── views/
│   │       └── listings-page.php
│   │
│   ├── stock/
│   │   ├── class-stock-module.php
│   │   ├── class-stock-service.php
│   │   ├── class-stock-movements-service.php
│   │   └── views/
│   │       ├── stock-page.php
│   │       ├── stock-adjustment-modal.php
│   │       └── stock-movements-page.php
│   │
│   ├── debts/
│   │   ├── class-debts-module.php
│   │   ├── class-debts-service.php
│   │   ├── class-payments-service.php
│   │   └── views/
│   │       ├── debts-page.php
│   │       ├── customer-debt-page.php
│   │       └── register-payment-modal.php
│   │
│   ├── promotions/
│   │   ├── class-promotions-module.php
│   │   ├── class-promotions-service.php
│   │   └── views/
│   │       └── promotions-page.php
│   │
│   ├── settings/
│   │   ├── class-settings-module.php
│   │   └── views/
│   │       └── settings-page.php
│   │
│   ├── access/
│   │   ├── class-access-module.php
│   │   └── views/
│   │       └── access-page.php
│   │
│   ├── agenda/
│   │   ├── class-agenda-module.php
│   │   └── views/
│   │       └── agenda-page.php
│   │
│   ├── stats/
│   │   ├── class-stats-module.php
│   │   ├── class-stats-service.php
│   │   └── views/
│   │       └── stats-page.php
│   │
│   ├── messaging/
│   │   ├── class-messaging-module.php
│   │   ├── class-whatsapp-service.php
│   │   ├── class-email-service.php
│   │   ├── class-sms-service.php
│   │   └── views/
│   │       └── messaging-page.php
│
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   ├── dashboard.css
│   │   ├── sales.css
│   │   ├── stock.css
│   │   ├── debts.css
│   │   └── components.css
│   │
│   ├── js/
│   │   ├── admin.js
│   │   ├── dashboard.js
│   │   ├── sales.js
│   │   ├── stock.js
│   │   ├── debts.js
│   │   └── messaging.js
│   │
│   └── img/
│       └── logo.png
│
├── templates/
│   ├── partials/
│   │   ├── header.php
│   │   ├── sidebar-left.php
│   │   ├── sidebar-right.php
│   │   ├── cards.php
│   │   └── empty-state.php
│
└── languages/
    └── riverso-pos.pot

