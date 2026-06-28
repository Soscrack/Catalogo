# DataBase Structure Visualizer — WooCommerce Productos

Visualización de la base de datos de WooCommerce centrada en **productos**, **variaciones**, **atributos**, **categorías** e **imágenes**.

Basado en la documentación interna del proyecto (`woo_product_structure.md`) y en el esquema estándar de WooCommerce sobre WordPress.

## Contenido

| Archivo | Descripción |
|---------|-------------|
| `index.html` | Visualizador interactivo: zoom, buscador y conflictos |
| `codes-search-data.js` | Índice de búsqueda (SKU / proveedor / barras) |
| `diagrams/*.svg` | Diagramas offline (sin CDN) |
| `build_search_index.py` | Regenera el índice desde `data/sku_mapping.json` |
| `woocommerce_products_erd.mmd` | Diagrama ER WooCommerce (Mermaid) |
| `riverso_sku_codigos_erd.mmd` | ER SKU local, online y proveedor |
| `woocommerce_product_hierarchy.mmd` | Jerarquía y flujo de tipos de producto |
| `meta_keys_reference.json` | Referencia de claves `_` en `wp_postmeta` |

## Cómo ver los diagramas

### Opción 1 — Navegador (recomendado)

Abre `index.html` con doble clic. Los diagramas van **incrustados en el HTML** (funciona sin internet ni servidor). El buscador usa `codes-search-data.js` en la misma carpeta.

Tras editar SVG en `diagrams/`, regenerar e incrustar:

```bash
python "DataBase Structure visualizer/build_diagrams_js.py"
python "DataBase Structure visualizer/inline_diagrams.py"
```

**Zoom:** botones +/−/⟲ en cada diagrama, Ctrl+rueda del mouse, o arrastrar para desplazar.

**Buscador:** SKU local, SKU online (Mamut), código proveedor o código de barras. Alerta conflictos como mapeos ambiguos o códigos en varios proveedores.

Regenerar índice (incluye `CodigosBarra/codigos_barras_*.csv`):

```bash
python "DataBase Structure visualizer/build_search_index.py"
python "DataBase Structure visualizer/inline_search.py"
```

Fuentes del buscador: `sku_mapping.json`, `codigos_proveedores.json`, CSV de códigos de barras (~5800 filas).

### Opción 2 — VS Code / Cursor

Instala la extensión **Mermaid Preview** y abre cualquier archivo `.mmd`.

### Opción 3 — GitHub

Los archivos `.mmd` se renderizan automáticamente en bloques Mermaid dentro de Markdown en GitHub.

## Modelo conceptual

WooCommerce **no tiene tablas propias de productos**. Reutiliza el modelo de WordPress:

```
wp_posts (producto o variación)
    ├── wp_postmeta (precio, SKU, stock, atributos serializados)
    ├── wp_term_relationships → wp_term_taxonomy → wp_terms (categorías, tags, pa_*)
    ├── wp_posts hijos (variaciones: post_type = product_variation)
    └── wp_posts attachment (imagen destacada y galería)
```

### Tipos de producto

| Tipo | `post_type` | `post_parent` | Meta clave |
|------|-------------|---------------|------------|
| Simple | `product` | `0` | `_product_type = simple` |
| Variable (padre) | `product` | `0` | `_product_type = variable` |
| Variación | `product_variation` | ID del padre | Atributos en meta + `set_attributes()` |
| Imagen | `attachment` | `0` | Referenciada por `_thumbnail_id` |

### Atributos

| Estilo | Almacenamiento | Uso típico |
|--------|----------------|------------|
| Global (`pa_color`, `pa_talla`) | Taxonomía `pa_*` + términos | Atributos reutilizables en catálogo |
| Custom de producto | `_product_attributes` en `wp_postmeta` | Atributos específicos (ej. MAMUT: Nominal, Largo) |

**Regla MAMUT (Riverso):** NOMINAL y LARGO son visibles pero no generan variación; la variación real usa el atributo combinado `nominal-x-largo`.

### Tablas de índice (WooCommerce 3.6+)

- `wc_product_meta_lookup` — acelera búsquedas por SKU, precio, stock.
- `wc_product_attributes_lookup` — acelera filtros por atributos en catálogo.

## Relación con Riverso POS

El dominio canónico interno (`riverso_producto_base`) referencia WooCommerce mediante:

- `woocommerce_product_id` → `wp_posts.ID` del producto padre
- `woocommerce_variation_id` → `wp_posts.ID` de la variación

La publicación exige revisión humana antes de cambiar `post_status` a `publish`.

## Prefijos de tabla

En instalaciones reales, `{prefix}` suele ser `wp_` o el prefijo personalizado de WordPress. Los diagramas usan nombres lógicos sin prefijo para claridad.
