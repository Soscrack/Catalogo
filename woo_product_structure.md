# Estructura de productos WooCommerce

Este documento resume cómo WooCommerce almacena productos simples, variables, atributos, categorías, imágenes y variaciones, y cómo Riverso POS debe interactuar con esa estructura.

## Entidades base

WooCommerce persiste productos como posts de WordPress:

- Producto simple o variable: `wp_posts.post_type = product`.
- Variación: `wp_posts.post_type = product_variation`, con `post_parent = ID` del producto padre.
- Estado público: `wp_posts.post_status` (`draft`, `private`, `publish`, etc.).
- Metadatos comerciales: `wp_postmeta` (`_sku`, `_price`, `_regular_price`, `_stock`, `_manage_stock`, `_thumbnail_id`, `_product_attributes`, entre otros).

En código se debe preferir la API de WooCommerce:

- `WC_Product_Simple`
- `WC_Product_Variable`
- `WC_Product_Variation`
- `wc_get_product()`
- `wc_get_product_id_by_sku()`

## Productos variables

Un producto variable se crea con `WC_Product_Variable`. El producto padre contiene:

- Nombre, descripción y estado (`set_name`, `set_description`, `set_status`).
- Categorías (`set_category_ids`).
- Imagen destacada (`set_image_id`).
- Atributos disponibles para sus variaciones (`set_attributes`).

Después de crear o modificar variaciones, se debe ejecutar:

```php
WC_Product_Variable::sync($producto_id);
wc_delete_product_transients($producto_id);
```

Esto recalcula precios mínimos/máximos, disponibilidad y cachés internas.

## Atributos

WooCommerce soporta dos estilos:

- Atributos globales taxonómicos (`pa_*`): viven en taxonomías globales y se asignan con términos.
- Atributos custom de producto: viven en `_product_attributes` y se construyen con `WC_Product_Attribute::set_id(0)`.

El patrón actual de Riverso usa atributos custom (`set_id(0)`), porque no requiere mantener taxonomías globales por cada atributo del catálogo MAMUT.

Cada atributo define:

- `set_name()`: nombre visible.
- `set_options()`: valores posibles.
- `set_visible(true|false)`: si se muestra en la ficha.
- `set_variation(true|false)`: si participa en las variaciones.

## Regla NOMINAL x LARGO

Para MAMUT, si un SKU trae `NOMINAL` y `LARGO`, ambos deben seguir visibles como atributos informativos, pero la variación real usa un atributo combinado:

- `NOMINAL`: visible, no variación.
- `LARGO`: visible, no variación.
- `Nominal X Largo`: no visible, variación.

Ejemplo:

```txt
NOMINAL = #6-18
LARGO = 1"
Nominal X Largo = #6-18 x 1"
```

La variación guarda el atributo con slug normalizado:

```php
$variation->set_attributes([
    'nominal-x-largo' => '#6-18 x 1"',
]);
```

## Variaciones

Cada variación es un `WC_Product_Variation` con:

- `set_parent_id($product_id)`.
- `set_status('publish')` o el estado requerido.
- `set_sku($sku)`.
- `set_regular_price()` y `set_price()` cuando el precio ya está aprobado.
- Stock con `set_manage_stock()` y `set_stock_quantity()`.
- Atributos con `set_attributes()`.

Riverso POS no debe publicar automáticamente una variación con precio pendiente. Si falta validación humana de precio, el producto padre debe permanecer `draft` o `private`.

## Categorías

Las categorías de WooCommerce son términos de la taxonomía `product_cat`. La API recomendada para productos es:

```php
$product->set_category_ids([$category_id]);
```

Para una importación MAMUT, `category_path` debe mapearse a términos `product_cat`. La inferencia automática de categoría debe crear tarea de revisión humana antes de publicación.

## Imágenes

La imagen destacada se almacena como attachment de WordPress y se asigna con:

```php
$product->set_image_id($attachment_id);
```

Galerías adicionales usan metadatos WooCommerce. El JSON MAMUT actual no trae imágenes, por lo que los productos importados deben quedar con imagen pendiente o con placeholder hasta revisión humana.

## Relación Riverso POS ↔ WooCommerce

Riverso POS usa `riverso_producto_base` como dominio canónico:

- `woocommerce_product_id`: ID del producto WooCommerce padre.
- `woocommerce_variation_id`: ID de variación cuando aplica.
- `canonical_sku`: SKU interno/canónico.
- `nombre_canonico`: nombre interno.

El soft match online propone candidatos WooCommerce y solo fija `woocommerce_product_id` cuando un humano confirma la relación. La publicación también requiere gates humanos:

- `human_product_review = approved`
- `human_price_review = approved`
- `human_category_review = approved`
- `human_attribute_review = approved`

Si falta cualquiera, el producto debe permanecer `draft` o `private`.
