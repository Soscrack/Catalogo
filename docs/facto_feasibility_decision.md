# Decisión de alcance — Integración FACTO ↔ Riverso

## Veredicto

**Factible.** Identidad SKU ya alineada en producción. Complejidad media.

## Evidencia

### PoC sandbox (`docs/facto_poc_findings.md`)

- Auth OK en `https://apifacto.com/v1`
- `POST /products` crea con SKU, nombre, marca, modelo, costo, stock
- `PUT /products/{id}` **persiste**: name, brand, model, additional_details, invoicing_details
- `PUT` **NO persiste**: sku (inmutable tras create), barcode, cost, price, inventories
- Docs oficiales de PUT incompletas (declaran solo name+sku); la realidad es “descriptivos sí, comerciales/stock/sku no”

### Solapamiento producción vs export FACTO (`tests/facto_overlap_report.json`)

| Métrica | Valor |
|---|---|
| ERP `producto_base` | 9.847 (9.845 activos) |
| ERP con SKU | 4.701 (5.146 sin SKU) |
| FACTO export | 5.093 SKUs |
| Match exacto SKU | **4.691** |
| Solo ERP | 10 |
| Solo FACTO | 402 |
| Match rate vs ERP con SKU | **99.79%** |
| Match rate vs FACTO | 92.11% |

## Alcance v1 (implementar ahora)

1. **Dirección**: Riverso → FACTO (push) + backfill inicial FACTO→map
2. **Identidad**: `canonical_sku` ↔ `facto.sku` al vincular; anclar en `facto_product_id`
3. **Create**: POST con name, sku, brand/model si existen, measure_unit, status, cost/price/stock best-effort
4. **Update**: PUT solo campos que persisten (name, brand, model, details, status)
5. **SKU rename**: NO se propaga; se registra warning en outbox/map
6. **Delete/archive**: PUT `status=0` (nunca DELETE HTTP)
7. **Stock/precio continuo**: fuera de v1
8. **Outbox + cron** para no bloquear UI y respetar rate limits

## Fuera de alcance v1

- Sync bidireccional continuo
- Propagar cambios de precio/costo/stock/barcode
- Renombrar SKU en FACTO
- Crear en Riverso productos que solo existen en FACTO (solo se mapean)

## Estimación

| Bloque | Esfuerzo |
|---|---|
| Cliente + settings + migración | 0.5–1 d |
| Outbox/sync + hooks producto | 1–1.5 d |
| Backfill/reconciliación | 0.5 d |
| Pruebas en sandbox + dry-run prod | 0.5–1 d |
| **Total v1** | **~3–4 días** |

## Riesgo residual

- Credenciales de producción FACTO deben vivir en `wp-config.php` (constantes), no en options
- 402 SKUs solo en FACTO y 5.146 productos ERP sin SKU requieren limpieza operativa previa al backfill completo
- Confirmar en cuenta real (no sandbox) que PUT se comporta igual
