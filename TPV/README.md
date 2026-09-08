# TPV legacy

Catálogo base del programa TPV local (ventas offline).

| Archivo | Uso |
|---------|-----|
| `tpv_export_ejemplo_estructura.xlsx` | Ejemplo inventado del Excel que emite Riverso Export TPV (hojas `Productos` + `CodigosBarra`, acciones CREAR/EDITAR/CAMBIAR_SKU/ELIMINAR). Para probar el programa TPV local. |
| `productos_tpv.xlsx` | Snapshot TPV local (sku, nombre, precio, coste) |
| `productos_legacy.csv` | Baseline usado por Riverso Export TPV (`data/tpv/productos_legacy.csv` en el plugin) |
| `productos_facto.xlsx` | Catálogo FACTO (`productos (6).xlsx`) para crear SKU locales faltantes (nombres, IVA, códigos de barra). **No usar para costo ni precio.** |
| `codigos_barra.csv` | Dump TPV de códigos (sku, codigo_barras). Ingerir faltantes en Riverso como `propuesto` + tarea `confirmar_barcode_legacy` (`tools/ingest_tpv_barcodes_and_finish_exports.py`). Remap histórico: SKU `129` → `12900`. |

Columnas del legacy de productos: `sku`, `nombre`, `precio`, `coste` (3 decimales).

FACTO redondea precios y costos al exportar. En Riverso, `p_asignado` y `c_ref` locales salen de este snapshot TPV.

Al exportar desde Riverso sin lote aplicado, se compara contra este baseline:

- SKU ya en legacy → **EDITAR**
- SKU solo en Riverso → **CREAR**
- SKU solo en legacy → **ELIMINAR**

Con lote TPV **aplicado**, el baseline es la **fusión de todos** los lotes marcados como aplicados (no solo el último). Así un Excel incremental pequeño no hace reaparecer como CREAR el resto del catálogo.

Cambio de SKU en la orden TPV: acción **CAMBIAR_SKU** con columnas `SKU` (nuevo) + `SKU_Anterior` (no se emite ELIMINAR+CREAR para ese par). En FACTO el rename es manual: archivo aparte `facto-cambios-sku_*.xlsx` desde Export Excel a FACTO.

FACTO no admite dos espacios seguidos en el nombre. Si el TPV legacy tiene espacios dobles, Riverso los colapsa a uno y Export TPV emite **EDITAR** (columna Nombre) para renombrar en el programa local (`tools/normalize_tpv_double_spaces.py`).
