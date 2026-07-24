# Resumen: arquitectura de productos vs `productos_diseño.md`

**Fecha:** Julio 2026  
**Alcance:** Revisión de BD/modelo de productos + cierre de 6 brechas  
**Estado:** Implementación de código y migraciones lista; pendiente ejecutar en entorno y validar e2e

---

## Diagnóstico (antes)

| Requisito | Antes |
|-----------|--------|
| Productos locales (legacy tienda local) | Parcial — `tienda_local_*` aislado del dominio |
| Productos online (Mamut / Woo) | Cumple |
| Productos proveedor por XML | Cumple |
| Proveedor → producto local **o** familia | No — solo a `producto_base` |
| Un producto online por base | Cumple |
| Código de barra con proveedor + envase + cantidad | Parcial — modelo unificado existía; POS no lo usaba |
| POS online / local | No — solo local |
| Precio por familia según cantidad agregada | Parcial — motor existía; POS no lo conectaba |
| Esquema de familia único | Riesgo — dos esquemas en paralelo |

---

## Qué se implementó

### 1. Consolidación de familia (phase 12)
- Canónico: `equivalence_groups` / `equivalence_members`
- Migración de `equivalencia_grupo` / `equivalencia_miembro` y deprecación
- Archivos: `migrations/phase12_family_consolidation_v1.sql` + `Activator::consolidate_family_schema()`

### 2. Proveedor → producto o familia (phase 13)
- Columna `grupo_id` en `producto_proveedor` (+ auditoría)
- API: `assign_to_family()`, `assign_to_product()`, `get_assignment()`
- Archivos: `migrations/phase13_supplier_to_family_v1.sql` + matching module + activator

### 3. Código de barra unificado en POS
- Búsqueda prioriza `Barcode_Model::resolve()` (producto + proveedor + envase + cantidad)
- Completada migración de envases en `phase11_catalog_codes_migrate.php`

### 4. Canal POS online / local
- AJAX `riverso_pos_set_channel`
- Precio online vía `get_online_price()`; local vía precio dominio + reglas

### 5. Precio por familia (cantidad agregada)
- AJAX `riverso_pos_recalc_family_price` y `riverso_pos_rule_price`
- Usa cantidad agregada **del carrito** (`cart_items` / `family_qty`), no stock de lotes
- Motor de tramos (ej. 350 uds → regla)

### 6. Integración tienda local → dominio
- `Activator::integrate_local_store_products()`: match o creación de `producto_base` con origen `tienda_local_legacy`

---

## Flujo objetivo (POS)

```text
Escaneo → Barcode_Model.resolve()
       → producto + proveedor + envase + cantidad
       → canal online?  → precio online por producto
       → canal local?   → qty familia en carrito → p_ref + regla por tramo
       → línea de carrito
```

---

## Archivos clave

| Tipo | Ruta |
|------|------|
| Migraciones | `php/riverso-pos/migrations/phase12_*.sql`, `phase13_*.sql` |
| Activator | `php/riverso-pos/includes/class-activator.php` |
| Matching | `php/riverso-pos/catalog/matching/class-matching-module.php` |
| POS | `php/riverso-pos/sales/pos/class-pos-module.php` |
| Pricing | `php/riverso-pos/pricing/price_lists/class-pricing-module.php` |
| Backfill códigos | `php/riverso-pos/migrations/phase11_catalog_codes_migrate.php` |
| Detalle técnico | [IMPLEMENTACION_ARQUITECTURA_PRODUCTOS.md](IMPLEMENTACION_ARQUITECTURA_PRODUCTOS.md) |
| Guía SQL | [MIGRACIONES_SQL_GUIA.md](MIGRACIONES_SQL_GUIA.md) |

---

## Pendiente operativo

1. Ejecutar migraciones / reactivar plugin en el entorno
2. Toggle UI online/local en `templates/pos.php` ✅ **HECHO**
3. Recalcular precios familia por cantidad carrito ✅ **HECHO**
4. Exponer AJAX matching (assign_to_family/assign_to_product/get_assignment) ✅ **HECHO**
5. Tests e2e: escaneo barcode, canal, regla 350 uds, asignación a familia
6. Validar integridad post-migración (queries en la guía SQL)

### Post-Implementación Gaps Cerrados

| Gap | Solución | Archivo |
|-----|----------|---------|
| Toggle canal en POS | Selector HTML + AJAX `riverso_pos_set_channel` | `templates/pos.php` |
| Carrito ignora `barcode_info` | Guardar `units_per_pack`, `cantidad`, `barcode_info` en item | `templates/pos.php::addToCart()` |
| Precio familia no recalcula | `recalculateFamilyPrices()` al cambiar qty; suma unidades por familia en carrito | `templates/pos.php` |
| `ajax_rule_price` usa stock, no carrito | Reescrito para aceptar `cart_items` JSON + `channel`; calcula `family_qty` agregada | `class-pos-module.php::ajax_rule_price()` |
| Matching familia sin AJAX | Endpoints assign_family/product/get_assignment + UI | `class-matching-module.php` + `catalog-domain.php` |
| Runtime cargaba `modules/*` legacy | Loader prioriza `sales/pos`, `catalog/matching`, `pricing/price_lists`; shims en modules/ | `riverso-pos.php` |
| Carrito `cantidad` vs backend `quantity` | Ambos sincronizados; totales = precio × packs × units_per_pack | `pos.php` + `ajax_get_cart_totals` / `ajax_create_order` |
| `assign_to_family` fallaba (NOT NULL) | `producto_base_id` nullable + SQL con NULL | phase13 / activator / matching |

### Checklist de Verificación Post-Migración

```
[ ] 1. Desactivar / reactivar plugin Riverso POS
[ ] 2. Ejecutar SQL de phase12 (consolidar equivalence_groups)
[ ] 3. Ejecutar SQL de phase13 (agregar grupo_id a producto_proveedor)
[ ] 4. Ejecutar PHP de Activator::integrate_local_store_products()
[ ] 5. Verificar integridad con queries de MIGRACIONES_SQL_GUIA.md
[ ] 6. Abrir POS: debe haber toggle canal online/local
[ ] 7. Escanear código con cantidad (ej. 2×100 = 200 uds)
[ ] 8. Verificar línea de carrito: muestra "×100 uds/envase"
[ ] 9. Agregar 2 códigos de la misma familia: cantidad debe sumar 350 uds
[ ] 10. Verificar precio se recalcula según tramo (p_ref + 3, redondeo a decena superior)
[ ] 11. Cambiar a canal online: precios deben ser online (sin regla)
[ ] 12. En UI de matching: asignar un código a familia (test assign_to_family)
[ ] 13. Verificar en DB: producto_proveedor.grupo_id NOT NULL; producto_base_id NULL
```

---

## Veredicto

La arquitectura **ahora cubre completamente** los requisitos de `productos_diseño.md`:

✅ **Modelo & Datos:** Consolidación de familias, asignación proveedor → producto/familia, código de barra unificado, integración tienda local  
✅ **Backend:** Motor de precios por familia (cantidad carrito), AJAX endpoints de matching, gestión de canales  
✅ **UI/POS:** Toggle online/local, carrito con información de envase, recalcular precios automático, asignación matching vía AJAX  

**Próximo paso operativo:** Ejecutar migraciones en VPS, ejecutar activator para consolidar datos, y validar con el checklist post-migración.
