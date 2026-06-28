# 🖨️ UBICACIONES DE BOTONES DE IMPRESIÓN EN RIVERSO POS

## 1. PORTAL INTERNO → Búsqueda de Barcodes

### Ruta: `/interno/barcodes`

**Flujo:**
1. Operario accede a http://tu-sitio.com/interno/barcodes
2. Escribe o escanea un SKU local (ej: "123456")
3. Click "Buscar"
4. Aparece tarjeta del producto con:

```
┌─────────────────────────────────────────┐
│  Producto ABC                           │
│  SKU local: 123456                      │
│  Precio: $5,990                         │
│  Stock: 150 unidades                    │
│                                         │
│  Códigos asociados (2)                  │
│  • 8901234567890                        │
│  • 9876543210123                        │
│                                         │
│  ┌─────────────────────────────────────┐│
│  │ 🖨️ IMPRIMIR                         ││  ← BOTÓN AQUÍ
│  └─────────────────────────────────────┘│
└─────────────────────────────────────────┘
```

**Al hacer click:**
- Se abre modal con opciones
- Pre-rellena: SKU, nombre, precio
- Permite ajustar: cantidad EAN, copias, modo, color, impresora
- Click "Imprimir" envía al agente

---

## 2. ADMIN → Tienda Local

### Ruta: `wp-admin/admin.php?page=riverso-pos-tienda-local`

**Flujo:**
1. Admin accede a "Riverso POS" → "Tienda Local"
2. En sección "Buscador rápido" escribe SKU/código/nombre
3. Aparece resultados con botón "🖨️ Imprimir":

```
┌──────────────────────────────────────────────────────┐
│ TIENDA LOCAL - Buscador rápido                      │
│                                                      │
│ [Escanea o escribe código...]  [Buscar]             │
│                                                      │
├──────────────────────────────────────────────────────┤
│                                                      │
│ Producto XYZ                                        │
│ SKU: 654321                                         │
│ Precio: $3,500                                      │
│ Stock: 75                                           │
│                                                     │
│ ┌────────────────────────────────────────────────┐│
│ │ 🖨️ IMPRIMIR                                   ││  ← BOTÓN
│ └────────────────────────────────────────────────┘│
│                                                     │
└──────────────────────────────────────────────────────┘
```

---

## 3. ADMIN → Embolsado

### Ruta: `wp-admin/admin.php?page=riverso-pos-packaging`

**Flujo:**
1. Admin accede a "Riverso POS" → "Embolsado"
2. Selecciona producto base
3. Genera bolsas (botón "Generar bolsa")
4. Tabla de bolsas muestra cada una con botón de impresión:

```
┌──────────────────────────────────────────────────────────────┐
│ EMBOLSADO Y PRODUCTO ABIERTO                                 │
├──────────────────────────────────────────────────────────────┤
│ Tabla de bolsas:                                             │
├──────┬────────┬──────────┬──────────┬────────┬────────┬──────┤
│ ID   │ SKU    │ Cantidad │ EAN13    │ Costo  │ Estado │ Acción│
├──────┼────────┼──────────┼──────────┼────────┼────────┼──────┤
│ 1    │ BLS001 │ 250      │2000100250... │ 1500 │ ok   │ 🖨️  │  ← BOTÓN
│ 2    │ BLS002 │ 100      │2000100100... │  600 │ ok   │ 🖨️  │  ← BOTÓN
│ 3    │ BLS003 │ 500      │2000100500... │ 3000 │ ok   │ 🖨️  │  ← BOTÓN
└──────┴────────┴──────────┴──────────┴────────┴────────┴──────┘
```

**Al hacer click:**
- Modal pre-rellena SKU bolsa, cantidad, y EAN13 generado
- Modo: BolsaCOD (fijo para bolsas)
- Permite ajustar copias e impresora

---

## 4. ADMIN → Gestión de Tareas

### Ruta: `wp-admin/admin.php?page=riverso-pos-tasks`

**Flujo:**
1. Admin accede a "Riverso POS" → "Gestión de Tareas"
2. Filtra por tipo: "Etiquetado"
3. Aparecen tarjetas de tareas con botón:

```
┌────────────────────────────────────────────┐
│ 🎯 TAREA: Etiquetar bolsas lote 5          │
│                                             │
│ Tipo: 📦 Etiquetado                        │
│ Prioridad: ⚠️ Alta                         │
│ Creada: 2026-06-28                         │
│ Asignado a: Juan López                     │
│ Límite: 2026-07-05                         │
│                                             │
│ ┌─────────────┬──────────┬────────────────┐│
│ │ → IR        │ ✓ COMPLETAR │ 🖨️ IMPRIMIR ││  ← BOTÓN
│ └─────────────┴──────────┴────────────────┘│
└────────────────────────────────────────────┘
```

**Al hacer click en "🖨️ IMPRIMIR":**
- Carga los items de la tarea (sku_local, cantidad, etc.)
- Si hay 1 item: abre modal
- Si hay +1 items: pide confirmación → imprime lote
- Envía todos al agente en una sola petición

---

## 📊 Resumen visual de flujos

```
BROWSER (Usuario)
    │
    ├─→ [Portal: busca SKU] → [Resultado producto] → [🖨️ IMPRIMIR]
    │
    ├─→ [Admin: Tienda Local] → [Busca producto] → [🖨️ IMPRIMIR]
    │
    ├─→ [Admin: Embolsado] → [Tabla bolsas] → [🖨️ IMPRIMIR (fila)]
    │
    └─→ [Admin: Tareas etiquetado] → [Tarjeta tarea] → [🖨️ IMPRIMIR]

    Todos convergen en:
    
    MODAL DE IMPRESIÓN
    ├─ Campos pre-rellenos (nombre, SKU, precio)
    ├─ Opciones ajustables (cantidad, copias, modo, color, impresora)
    └─ [IMPRIMIR] → POST http://127.0.0.1:19284/print
    
    ↓
    
    AGENTE .NET (http://127.0.0.1:19284)
    ├─ Recibe: { sku, nombre, cantidad, ean13, printerName, ... }
    ├─ Abre: template.lbx (BN o RN)
    ├─ Asigna: campos (nombre, BarCode=EAN13, cantidad, etc.)
    └─ Imprime: StartPrint() → Brother P-touch
    
    ↓
    
    IMPRESORA BROTHER
    └─ Etiqueta física con código de barras
```

---

## 🎬 Demostración paso a paso

### Escenario: Operario quiere imprimir bolsa embolsada

**1. Accede a Panel Admin:**
```
URL: https://tu-sitio.com/wp-admin/admin.php?page=riverso-pos-packaging
```

**2. Carga página Embolsado:**
- Tabla de bolsas se carga
- Ve columna "Acción" con botones 🖨️

**3. Hace click en botón de fila con bolsa:**
```
Fila: ID=5, SKU=BLS-MANZANA-100, Cantidad=100 unidades, EAN13=2000100100007
Click: [🖨️]
```

**4. Modal se abre con:**
```
┌─ IMPRIMIR ETIQUETA ─────────────────────────┐
│                                              │
│ Producto:   [Bolsa - Manzana] (readonly)    │
│ SKU:        [BLS-MANZANA-100] (readonly)    │
│                                              │
│ Cantidad EAN: [100]                         │
│ Copias:       [1]                           │
│                                              │
│ Modo:  [BolsaCOD ▼]                         │
│ Color: [BN ▼]                               │
│                                              │
│ Impresora: [Brother QL-800 (preferida) ▼]  │
│            (opciones: Brother QL-800,       │
│                       HP Laserjet,          │
│                       Default Windows)      │
│                                              │
│ [CANCELAR]  [IMPRIMIR ✓]                    │
└─────────────────────────────────────────────┘
```

**5. Operario puede ajustar:**
- Cambiar cantidad de copias a 3
- Cambiar modo a "EtiquetaLogoPrecio"
- Cambiar impresora a otra disponible

**6. Click "IMPRIMIR":**
```
Estado: "Imprimiendo..."

Respuesta exitosa:
✅ 3 etiquetas impresas correctamente

Respuesta error:
❌ Error: Plantilla no encontrada (EtiquetaLogoPrecio.lbx)
```

**7. 3 etiquetas físicas salen de la impresora**

---

## 🔐 Permisos requeridos

Para ver botones de impresión, usuario debe tener capability:
```php
riverso_print_labels
```

Asignada automáticamente a roles:
- ✅ Administrador Riverso
- ✅ Operador Bodega (riverso_bodega)

---

## ⚙️ Configuración

### En WordPress (si implementas panel de settings):

Opciones guardadas:
```php
riverso_label_print_agent_url    = 'http://127.0.0.1:19284'
riverso_label_print_auth_token   = ''  (vacío = sin autenticación)
```

### En agente (.NET appsettings.json):

```json
{
  "AgentSettings": {
    "Port": 19284,
    "AuthToken": null,
    "RollBasePath": null
  }
}
```

---

## ✨ Características del modal

✅ **Pre-rellenos inteligentes:**
- SKU desde tienda_local o bolsa
- Nombre desde BD
- Precio desde producto
- EAN13 pregenerado si existe

✅ **Selector de impresora dinámico:**
- Lista auto-descubierta del agente
- Marca Brother con (Brother)
- Marca predeterminada con (por defecto)
- Pre-selecciona impresora preferida del usuario

✅ **Validación:**
- Cantidad: 1-99999
- Copias: 1-100
- Detección automática si agente no disponible

✅ **Persistencia:**
- Impresora seleccionada se guarda automáticamente
- Próxima impresión pre-selecciona la misma

---

**Versión**: 1.0  
**Última actualización**: Junio 2026
