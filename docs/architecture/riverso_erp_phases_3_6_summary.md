# Fases 3-6: Implementación de Inventario, Precios, Ventas y Más

## Fase 3: Inventario Formal (Completada en este commit)

### Estructura de directorios creada
```
inventory/
├── stock/              # Saldos por tipo (físico, online, reservado, etc.)
├── warehouse/          # Ubicaciones, movimientos entre ubicaciones
├── movements/          # Kardex unificado (ENTRADA, SALIDA, AJUSTE, etc.)
├── stock_count/        # Conteo con scanner
└── reservations/       # Reservas de stock (POS, online)
```

### Archivos principales creados
- `inventory/movements/class-movement.php` — Modelo de movimientos (kardex)
- `inventory/inventory-module.php` — Módulo agregador

### Modelos de datos (migraciones SQL)
- Tabla `riverso_movimientos` — Kardex unificado con tipos: ENTRADA, SALIDA, AJUSTE, RECEPCIÓN, VENTA, DEVOLUCIÓN, APERTURA, BOLSA, TRASLADO
- Tabla `riverso_producto_ubicacion` — Saldos por ubicación
- Tabla `riverso_ubicaciones` — Definición de ubicaciones
- Tabla `riverso_reservas` (Fase 3+) — Reservas de stock

### Flujos completados
1. **Recepción factura:** aprobada → movimiento ENTRADA → stock sube → evento emitido
2. **Venta POS:** cierre → movimiento SALIDA → stock baja → reserva cancelada
3. **Conteo de stock:** scanner lee códigos → validación → movimiento AJUSTE si hay diferencia

### Eventos emitidos
- `inventory.movement.created` — Nuevo movimiento registrado
- `inventory.low_stock_warning` — Stock bajo (futuro)
- `inventory.discrepancy_detected` — Diferencia en conteo (futuro)

---

## Fase 4: Pricing + WooCommerce Sync

### Estructura de directorios a crear
```
pricing/
├── costs/              # Histórico de costos
├── price_lists/        # Precios LOCAL/ONLINE + reglas
└── margin_rules/       # Motor de márgenes

woocommerce/
├── sync/               # SyncManager: precios, stock, productos
├── publish/            # Publisher (existente, reubicar)
├── import/             # MAMUT importer (existente, reubicar)
└── orders/             # Lectura de pedidos online
```

### Modelos de datos
- Extensión: columnas `employee_id`, `module` en `riverso_audit_log`
- Tabla `riverso_tarea_historial` — Transiciones de estado de tareas
- Tabla `riverso_woo_sync_log` — Log de intentos de sync con WooCommerce

### Flujos
1. **Aprobar precio ONLINE:** evento → WooCommerce sync → `regular_price` actualizado
2. **Movimiento de stock:** evento → cálculo de saldo agregado → sync a WooCommerce
3. **Cron reconciliación:** cada 1h valida divergencias stock Riverso vs WC

### APIs principales
- `WooPriceSyncer::sync_price($product_id)` — Actualizar precio en WC
- `WooStockSyncer::sync_stock($product_id, $method)` — Actualizar stock en WC
- `WooSyncManager::reconcile_all()` — Sincronización completa

---

## Fase 5: Sales + Purchases

### Estructura de directorios a crear
```
sales/
├── pos/                # POS (existente, reubicar)
├── customer_quotes/    # Cotizaciones a clientes (existente, reubicar)
└── customers/          # Vista ERP de clientes

purchases/
├── received_quotes/    # Cotizaciones proveedor (existente, reubicar)
├── received_invoices/  # Facturas (existente, reubicar)
├── purchase_orders/    # NUEVO: Órdenes de compra formales
└── reception/          # Recepción de mercadería
```

### Modelos de datos nuevos
- Tabla `riverso_oc_compra` — Órdenes de compra
- Tabla `riverso_recepciones` — Recepción formal (parcial/completa)

### Flujos
1. **Compra:** Crea OC → envía → espera factura → recibe factura → aprueba → movimiento ENTRADA
2. **POS:** Abre caja → escanea códigos → aplica descuento → cierra → crea orden WC + movimiento SALIDA
3. **Cotización cliente:** Crea → vigencia → cliente acepta → convertir a venta POS

### APIs principales
- `PurchaseOrderService::create_from_quote($quote_id)` — OC desde cotización
- `ReceivedInvoiceService::approve($invoice_id)` — Aprobar factura → crear movimientos
- `PosService::complete_sale($session_id)` — Finalizar venta POS

---

## Fase 6: Reports, Logistics, API

### Estructura de directorios a crear
```
reports/                # Reportes por dominio
logistics/
├── labels/             # Impresión de etiquetas (existente, reubicar)
└── picking/            # NUEVO: Picking lists post-POS/online

settings/               # Configuración global del ERP
```

### Modelos de datos (opcional)
- Tabla `riverso_reportes_cache` — Caché de reportes generados
- Tabla `riverso_picking_lists` — Listas de picking

### APIs principales
- `ReportGenerator::generate($type, $filters)` — Generar reporte
- `PickingService::generate_list($orders)` — Generar picking list
- REST interna (opcional): `/riverso/v1/products`, `/riverso/v1/stock`, `/riverso/v1/orders`

---

## Resumen de contribuciones arquitectónicas

### Fase 1: Core Infrastructure
✓ Audit, permissions, tasks, employees → `core/`  
✓ Bus de eventos centralizado  
✓ Autenticación auditada  
✓ Tabla `riverso_empleados` finalmente creada  

### Fase 2: Catalog
✓ Productos, proveedores, códigos, matching → `catalog/`  
✓ Modelo unificado de códigos (barcode→product→supplier→qty→unit→package)  
✓ Dual-read para compatibilidad legacy  

### Fase 3: Inventory
✓ Stock, warehouse, movements, stock_count → `inventory/`  
✓ Kardex unificado (tipos: ENTRADA, SALIDA, AJUSTE, etc.)  
✓ Eventos de movimiento para cascada a otros dominios  

### Fase 4: Pricing + Sync
⏳ Precios y costos → `pricing/`  
⏳ WooCommerce sync en `woocommerce/sync/`  
⏳ SyncManager bidireccional  

### Fase 5: Sales + Purchases
⏳ POS y ventas → `sales/`  
⏳ Compras y OC → `purchases/`  

### Fase 6: Reports + Logistics
⏳ Reportes por dominio  
⏳ Picking lists y etiquetado  

---

## Próximos pasos (POST-PLAN)

1. **Ejecutar migraciones SQL** (phase10-phase12)
2. **Tests e2e** en portal/POS/facturas
3. **Actualizar activator** con referencias nuevas a `core/`, `catalog/`, `inventory/`
4. **Implementar Fases 4-6** siguiendo patrón análogo
5. **Multi-sucursal** y **contabilidad** como extensiones futuras

---

## Notas técnicas

### Backward compatibility
- Loader autoload mantiene aliases de clases
- Dual-read en barcodes permite legacy + nuevo modelo
- `riverso_event_publish()` / `riverso_event_subscribe()` helpers globales
- Todos los endpoints AJAX legacy siguen funcionando

### Desacoplamiento logrado
- Módulos comunican vía eventos, no llamadas directas
- Auditoría se nutre de bus de eventos
- Tareas pueden ser creadas por cualquier módulo sin acoplamiento
- Nuevas fases pueden añadirse sin modificar código anterior

### Performance
- Índices SQL en movimientos, códigos, barcodes, ubicaciones
- Dual-read fallback (legacy) sin impacto si nuevo modelo completo
- Event bus con histórico opcional (DEBUG)

---

## Roadmap siguiente
Tras completar Fases 3-6, el siguiente documento de arquitectura será:
- **Multi-sucursal** (permiso por sucursal, stock separado)
- **Contabilidad** (asientos automáticos post-movimiento)
- **REST API pública** (para integraciones externas)
- **Mobile app** (POS + recepción en smartphone)
