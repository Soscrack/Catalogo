# PoC FACTO sandbox Chile — resultados

Fecha: 2026-08-23  
Base URL efectiva: `https://apifacto.com/v1`  
Cuenta sandbox: `account_id = 423`  
Producto de prueba: `product_id = 5845`, SKU inicial `RIVERSO-POC-88B199BE`

## Auth

- `POST /auth` con grant password (credenciales públicas sandbox Chile) → 200
- Token Bearer, `expires_in = 3600`
- Requiere `User-Agent` de navegador; sin él Cloudflare puede devolver 1010

## Catálogos descubiertos

| Endpoint | HTTP | Notas |
|---|---|---|
| `GET /product_categories` | 200 | 25 ítems; keys: `product_category_id`, `name`, `parent_product_category_id` |
| `GET /product_locations` | 200 | 25 ítems; keys: `product_location_id`, `name`, `status`, `company_branch_id` |
| `GET /product_price_lists` | 200 | 11 ítems; id `1` = "Precios base" |
| `GET /company_branches` | 200 | 3 ítems; id `1` = "Casa Matriz" |

## POST /products

- Schema legacy (guía Chile) → **201 Created**
- Persistió: `name`, `sku`, `brand`, `model`, `measure_unit`, `cost`, inventario en location 1
- `price` llegó `null` en el response (lista de precios no quedó vinculada en este intento)
- `barcode` enviado no apareció en el GET posterior (`null`)

## PUT /products/{id} — alcance real (bloqueante resuelto)

### A) Body documentado `{name, sku}`

| Campo | Enviado | Persistió |
|---|---|---|
| `name` | "…UPDATED name only" | **Sí** |
| `sku` | `…-A` | **No** (quedó el SKU original) |

### B) Body completo (precios, costo, inventario, barcode, brand, model, details)

| Campo | Enviado | Persistió |
|---|---|---|
| `name` | FULL UPDATE | **Sí** |
| `brand` | RIVERSO-FULL | **Sí** |
| `model` | POC-FULL | **Sí** |
| `additional_details` | additional updated | **Sí** |
| `invoicing_details` | invoice detail updated | **Sí** |
| `sku` | `…-B` | **No** |
| `barcode` | 7809999000999 | **No** |
| `cost.value` | 2500 | **No** (sigue 1000) |
| `price[]` | total 5000 | **No** (`price` sigue null) |
| `inventories` | 99 | **No** (sigue 5) |
| `favorite` | 1 | **No** |

## Conclusión de alcance

1. **Identidad SKU**: el SKU se define en el `POST` y **no se puede renombrar por PUT**. La identidad operativa debe anclarse en `facto_product_id`, y el SKU de FACTO debe quedar igual al `canonical_sku` al momento del alta/vínculo. Cambios posteriores de SKU en Riverso **no propagan** a FACTO vía API actual.
2. **Updates seguros vía PUT**: `name`, `brand`, `model`, `additional_details`, `invoicing_details` (y probablemente `status`/`measure_unit` — a confirmar en producción).
3. **No sincronizar por PUT**: precio, costo, stock, barcode. El alta puede intentar enviarlos en `POST`; actualizaciones de esos campos quedan fuera de esta fase (o requieren endpoints/soportes distintos).
4. **Delete**: mapear soft-delete Riverso → `status = 0` por PUT (nunca `DELETE` físico).

## Implicación para Riverso

- Sync push continuo: create = POST completo; update = PUT de campos descriptivos; archive/delete = PUT `status=0`.
- Si el usuario cambia `canonical_sku` en Riverso: actualizar mapping local, **no** esperar cambio en FACTO; emitir warning en outbox/`last_error`.
- Reconciliación inicial: `GET /products` (paginado) + match por `sku` → poblar `facto_producto_map`.

Artefacto crudo: `tests/facto_poc_results.json`
