# Implementación: Revisión y Mejora de Arquitectura de Productos

**Fecha:** Julio 2026  
**Estado:** Implementación Completada  
**Referencia:** [Plan original](REORGANIZACION_ERP_RESUMEN.md)

---

## Resumen Ejecutivo

Se ha completado la implementación de **6 mejoras arquitectónicas** al modelo de productos de Riverso POS, cerrando las brechas detectadas en la revisión de `@prompts/productos_diseño.md`. Los cambios garantizan que la plataforma cumpla con todos los requisitos de funcionalidad y permitirán una integración más limpia entre productos locales, online y de proveedor.

---

## Cambios Implementados

### 1. Consolidación del Esquema de Familia (Phase 12)

**Archivo:** `php/riverso-pos/migrations/phase12_family_consolidation_v1.sql`

**Descripción:**
- Unifica dos esquemas de familia que coexistían: `equivalence_groups/members` (activo) vs `equivalencia_grupo/miembro` (phase11, no usado)
- Mantiene `equivalence_groups/members` como esquema canónico
- Migra datos del schema legacy al canónico
- Marca tablas legacy como deprecadas para eventual eliminación

**Migraciones en `class-activator.php`:**
- Función `consolidate_family_schema()` ejecutada en activación
- Impacto: Sin cambios de datos existentes, solo consolidación de esquema duplicado

**Beneficios:**
- Reducción de deuda técnica
- Una única fuente de verdad para equivalencias
- Mejor mantenimiento futuro

---

### 2. Asignación Proveedor → Producto O Familia (Phase 13)

**Archivo:** `php/riverso-pos/migrations/phase13_supplier_to_family_v1.sql`

**Cambios en Base de Datos:**
- Columna nueva: `grupo_id` (FK a `equivalence_groups`) en `producto_proveedor`
- Columnas de auditoría: `assigned_to_family_at`, `assigned_to_family_by`
- CHECK constraint: exactamente uno de (`producto_base_id`, `grupo_id`) debe ser NOT NULL

**Cambios en Código:**
- **Archivo:** `php/riverso-pos/catalog/matching/class-matching-module.php`
- Métodos: `assign_to_family()`, `assign_to_product()`, `get_assignment()`
- AJAX: `riverso_matching_assign_family`, `assign_product`, `get_assignment`, `list_families`
- Listado matching con LEFT JOIN (soporta destino familia sin producto_base)

**UI:**
- **Archivo:** `php/riverso-pos/templates/catalog-domain.php`
- Botón Asignar + panel Producto | Familia + selector de `equivalence_groups`

**Beneficios:**
- Soporta asignación directa a familia (como pide `productos_diseño.md`)
- Mantiene backward compatibility: asignaciones existentes a `producto_base_id` siguen funcionando
- Auditoría clara de quién asignó a familia y cuándo

---

### 3. Resolución Unificada de Código de Barra en POS (Phase 11 mejorado)

**Cambios en Código:**

**Archivo:** `php/riverso-pos/sales/pos/class-pos-module.php`

- Nuevo método: `search_by_unified_barcode($search)` — Usa `Barcode_Model::resolve()` 
- Método extendido: `ajax_search_products()` prioriza modelo unificado antes que tablas legacy
- Enriquece respuesta con `barcode_info` contiendo:
  - `producto_base_id`, `proveedor_id`, `envase_id`
  - `cantidad`, `unidad_medida`, `factor`
  - Nombre del proveedor

**Archivo:** `php/riverso-pos/migrations/phase11_catalog_codes_migrate.php`

- Función mejorada: `riverso_migrate_packaging_codes_phase2()` — completada (era stub)
- Migra códigos de envases con cantidad/unidad
- Migra códigos de facturas con contexto proveedor

**Beneficios:**
- Escaneo unificado: un código resuelve producto + proveedor + envase + cantidad
- Eliminación de duplicaciones: fallback gracioso a legacy si es necesario
- Información enriquecida para logística y conteo

---

### 4. Modo POS Online/Local con Precios por Canal

**Cambios en Código:**

**Archivo:** `php/riverso-pos/sales/pos/class-pos-module.php`

- Nuevo hook AJAX: `wp_ajax_riverso_pos_set_channel` → `ajax_set_channel()`
- Nuevo método: `get_current_channel()` — persiste en transient por usuario
- Actualización: `format_product_extended()` ahora:
  - Detecta canal actual (online/local)
  - Si online: usa `get_online_price()` (canal = 'online')
  - Si local: usa `get_local_price()` (canal = 'local') + reglas de familia
- Enriquece respuesta con campo `channel` y `online_price`

**Archivo:** `php/riverso-pos/pricing/price_lists/class-pricing-module.php`

- Nuevo método: `get_online_price($producto_base_id, $woocommerce_variation_id)`
- Complementa `get_local_price()` para soporte bidireccional

**Beneficios:**
- Toggle on-the-fly entre online/local
- Precios correctos según contexto
- UI puede mostrar etiqueta "Online" o "Local" en caja
- Persistencia por usuario (transient 1 hora)

---

### 5. Precio por Familia + Cantidad Agregada (Phase 14 en POS)

**Cambios en Código:**

**Archivo:** `php/riverso-pos/sales/pos/class-pos-module.php`

- `ajax_rule_price()` ampliado: acepta `channel`, `cart_items` JSON y/o `family_qty`
- `calculate_family_qty_from_cart()`: suma `qty_packs × units_per_pack` de líneas de la misma familia
- `ajax_recalc_family_price()`: alias que usa **cantidad del carrito** (no `get_aggregated_quantity` / stock)
- `ajax_get_family_price()`: precio de referencia a nivel familia (reportes)

**UI (`templates/pos.php`):**
- Toggle canal Local/Online
- `addToCart` guarda `barcode_info` / `units_per_pack`
- Al cambiar qty → `recalculateFamilyPrices()` → `riverso_pos_rule_price`

**Flujo esperado:**
1. Empleado agrega 3 cajas de 50 (150 uds) a carrito → línea 1
2. Agrega 2 cajas de 100 (200 uds) → línea 2
3. POS suma 350 uds **en el carrito** (misma familia)
4. Aplica regla por tramo sobre esa cantidad
5. Precio unitario se actualiza en todas las líneas de la familia

**Beneficios:**
- Precios dinámicos según cantidad vendida en carrito (no stock teórico)
- Cumple ejemplo de diseño: "350 uds → regla aplica"
- Online: precio por producto sin agregación familiar

---

### 6. Integración Tienda Local Legacy al Dominio (Phase 14 en Activator)

**Cambios en Código:**

**Archivo:** `php/riverso-pos/includes/class-activator.php`

- Función: `integrate_local_store_products()`
  - Lee `tienda_local_productos` (CSV historico importado)
  - Matchea por SKU exacto o código de barra con `producto_base`
  - Para productos sin match: crea nuevo `producto_base` con:
    - `canonical_sku` = sku local
    - `origen_datos = 'tienda_local_legacy'`
    - `requires_human_review = 1`
  - Crea entradas en `codigo_barra` para barcodes locales
  - Crea precios locales aprobados si existían en tienda local
  - Marca `tienda_local_productos.integrated_at` para auditoría

**Tabla `tienda_local_productos`:**
- Columna nueva: `integrated_at` (datetime, marca migración)

**Beneficios:**
- Tienda local y dominio operan sobre misma fuente de verdad
- Catálogo unificado
- Puede deprecarse `tienda_local_*` después de validación
- Trazabilidad: sabe qué vino de legacy

---

## Estructura de Archivos Modificados

```
php/riverso-pos/
├── migrations/
│   ├── phase12_family_consolidation_v1.sql (NUEVO)
│   ├── phase13_supplier_to_family_v1.sql (NUEVO)
│   └── phase11_catalog_codes_migrate.php (ACTUALIZADO - completada función)
├── includes/
│   └── class-activator.php (ACTUALIZADO - 4 funciones nuevas)
├── catalog/
│   ├── matching/
│   │   └── class-matching-module.php (ACTUALIZADO - 3 métodos nuevos)
│   └── barcodes/
│       └── class-barcode-model.php (sin cambios, ahora sí usado por POS)
├── pricing/
│   └── price_lists/
│       ├── class-pricing-module.php (ACTUALIZADO - método get_online_price)
│       └── class-price-rules-module.php (sin cambios, ahora usados por POS)
└── sales/
    └── pos/
        └── class-pos-module.php (ACTUALIZADO - 4 métodos nuevos, 1 actualizado)
```

---

## Flujo de Validación: Ejemplo del Documento

**Escenario:** Venta POS en modo local con familia de productos

1. **Escaneo de código:**
   - Empleado escanea barcode "5901234567890"
   - `search_by_unified_barcode()` → `Barcode_Model::resolve()`
   - Resultado: `producto_base_id=42`, `cantidad=50`, `unidad=unidad`, `proveedor_id=3`

2. **Agregación a carrito (UI):**
   - `addToCart()` guarda `units_per_pack`, `cantidad` (packs), `producto_base_id`, `barcode_info`
   - Primera línea: 3 packs × 50 = 150 uds
   - Segunda línea: 2 packs × 100 = 200 uds
   - **Total en familia (carrito):** 350 unidades

3. **Cálculo de precio:**
   - Front llama `riverso_pos_rule_price` con `cart_items` JSON + `channel=local`
   - Backend: `calculate_family_qty_from_cart()` → 350 (NO usa stock de lotes)
   - `apply_for_base(42, qty=350, p_asignado)`
   - Resuelve regla para familia → tramo correspondiente
   - **Respuesta:** `{unit_price, family_qty: 350, channel: local}`

4. **Toggle canal:**
   - Select Local/Online → `riverso_pos_set_channel` → recalcula precios del carrito
   - Online: precio por producto sin agregación familiar

5. **Matching familia:**
   - En Catálogo Canónico → botón Asignar → Producto | Familia
   - AJAX `riverso_matching_assign_family` / `assign_product`

---

## Gaps UI cerrados (julio 2026, continuación)

| Gap | Solución | Archivo |
|-----|----------|---------|
| Toggle online/local | `#pos-channel-select` + AJAX set_channel | `templates/pos.php` |
| Carrito ignora barcode_info | Guarda units_per_pack / barcode_info | `templates/pos.php` |
| Qty no recalcula precio | `recalculateFamilyPrices()` | `templates/pos.php` |
| Bug `pos_nonce` indefinido | Unificado a variable `nonce` | `templates/pos.php` |
| `ajax_recalc_family_price` usaba stock | Reescrito con cart_items / family_qty | `class-pos-module.php` |
| Matching sin UI familia | Panel Producto\|Familia + list_families | `templates/catalog-domain.php` |
| Runtime usaba modules/ legacy | Paths canónicos + shims | `riverso-pos.php`, `modules/{pos,matching,pricing}/` |
| `cantidad` vs `quantity` rompía totales/venta | Sync dual + line total × units_per_pack | `pos.php` + POS AJAX |
| `producto_base_id` NOT NULL bloqueaba familia | MODIFY nullable + UPDATE SQL con NULL real | phase13 + activator + matching |

---

## Compatibilidad Backward

✅ **Totalmente Preservada:**
- Asignaciones existentes `producto_proveedor.producto_base_id` siguen funcionando
- Código de barra legacy `riverso_barcodes` sigue siendo leído (fallback)
- Precios WooCommerce se mantienen como fallback en modo online
- Familias existentes en `equivalence_groups` funcionan igual
- `get_aggregated_quantity()` permanece para stock/reportes (no POS venta)

⚠️ **Deprecación Gradual (sin breaking changes):**
- Tablas `equivalencia_grupo/miembro` marcadas como deprecated, no eliminadas
- `tienda_local_productos` seguirá existiendo; solo se lee en migración inicial
- Legacy SQL queries siguen disponibles para auditoría

---

## Próximos Pasos Recomendados

1. **Migración a prod / entorno:**
   - Reactivar plugin (activator: phase12/13 + integrate local)
   - Queries de integridad en `MIGRACIONES_SQL_GUIA.md`
   - Checklist en `RESUMEN_ARQUITECTURA_PRODUCTOS.md`

2. **Validación manual (no e2e automatizado en este alcance):**
   - Escanear código con cantidad → línea con “× N uds/envase”
   - 3×50 + 2×100 = 350 → tramo de regla
   - Toggle online → precios online por producto
   - Asignar `producto_proveedor` a familia vía UI matching

3. **Documentación de usuario (opcional):**
   - Guide POS modo local/online
   - Guide asignar proveedor a familia

---

## Referencias

- Plan original: `REORGANIZACION_ERP_RESUMEN.md`
- Especificación: `@prompts/productos_diseño.md`
- Resumen ejecutivo: `RESUMEN_ARQUITECTURA_PRODUCTOS.md`
- Guía SQL: `MIGRACIONES_SQL_GUIA.md`
- Arquitectura detallada: `docs/architecture/riverso_erp_architecture.md`

---

**Implementado por:** AI Assistant  
**Revisado por:** [Usuario]  
**Aprobado en producción:** [Pendiente]
