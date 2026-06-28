# 📸 INTERFAZ VISUAL - Sistema de Impresión

## PANTALLA 1: Portal → Búsqueda de Barcodes

```
╔════════════════════════════════════════════════════════════════╗
║ 🏠 PORTAL INTERNO / BARCODES                                  ║
║ ────────────────────────────────────────────────────────────── ║
║                                                                ║
║  📊 ESTADÍSTICAS                                              ║
║  ┌──────────┬──────────┬──────────────────┐                  ║
║  │ 150      │ 2,340    │ 1,890            │                  ║
║  │ productos│ códigos  │ productos con    │                  ║
║  │ locales  │          │ código           │                  ║
║  └──────────┴──────────┴──────────────────┘                  ║
║                                                                ║
║  🔍 BUSCADOR RÁPIDO                                           ║
║  ┌────────────────────────────────────────────────────────┐  ║
║  │ [Escanea o escribe código, SKU o nombre...]  [Buscar] │  ║
║  └────────────────────────────────────────────────────────┘  ║
║                                                                ║
║  ✅ RESULTADO ENCONTRADO                                      ║
║  ╔════════════════════════════════════════════════════════╗  ║
║  ║ 🎯 MANZANA ROJA PREMIUM                               ║  ║
║  ║                                                        ║  ║
║  ║ SKU local:  123456                                    ║  ║
║  ║ Precio:     $5,990                                    ║  ║
║  ║ Stock:      ✅ 250 unidades                           ║  ║
║  ║                                                        ║  ║
║  ║ 📋 Códigos asociados (3)                              ║  ║
║  ║ • 8901234567890 (2026-05-15)                         ║  ║
║  ║ • 8901234567891 (2026-05-16)                         ║  ║
║  ║ • 8901234567892 (2026-05-17)                         ║  ║
║  ║                                                        ║  ║
║  ║ ┌─────────────────────────────────────────────────┐  ║  ║
║  ║ │ 🖨️ IMPRIMIR                                      │  ║  ║ ← AQUÍ
║  ║ └─────────────────────────────────────────────────┘  ║  ║
║  ╚════════════════════════════════════════════════════════╝  ║
║                                                                ║
╚════════════════════════════════════════════════════════════════╝
```

---

## PANTALLA 2: Admin → Tienda Local

```
╔═══════════════════════════════════════════════════════════════╗
║ 🏪 TIENDA LOCAL                                               ║
║ ───────────────────────────────────────────────────────────── ║
║                                                               ║
║ Busca productos del sistema local por código de barra, SKU   ║
║ o nombre. Los datos provienen de los CSV en CodigosBarra/    ║
║                                                               ║
║ 📊 ESTADÍSTICAS                                              ║
║ ┌──────────┬──────────┬──────────┐                          ║
║ │150       │2,340     │1,890     │                          ║
║ │productos │códigos   │con código│                          ║
║ └──────────┴──────────┴──────────┘                          ║
║                                                               ║
║ 🔍 BUSCADOR RÁPIDO                                           ║
║ ┌────────────────────────────────────┐                      ║
║ │ [Escanea aquí...]     [Buscar]    │                      ║
║ └────────────────────────────────────┘                      ║
║                                                               ║
║ 📦 RESULTADO                                                 ║
║ ┌───────────────────────────────────────────────────────┐  ║
║ │ 🥕 ZANAHORIA ORGÁNICA FRESCA                         │  ║
║ │                                                      │  ║
║ │ SKU: 654321                                        │  ║
║ │ Precio: $2,500                                      │  ║
║ │ Stock: 🟢 500 kg                                    │  ║
║ │                                                      │  ║
║ │ 📋 Códigos (2)                                      │  ║
║ │ • 8801234567890                                    │  ║
║ │ • 8801234567891                                    │  ║
║ │                                                      │  ║
║ │ ┌──────────────────────────────────────────────┐   │  ║
║ │ │ 🖨️ IMPRIMIR                                 │   │  ║ ← AQUÍ
║ │ └──────────────────────────────────────────────┘   │  ║
║ └───────────────────────────────────────────────────────┘  ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

---

## PANTALLA 3: Admin → Embolsado

```
╔═══════════════════════════════════════════════════════════════╗
║ 📦 EMBOLSADO Y PRODUCTO ABIERTO                               ║
║ ───────────────────────────────────────────────────────────── ║
║                                                               ║
║ 📋 TABLA DE BOLSAS GENERADAS                                 ║
║                                                               ║
║ ┌─────┬──────────┬──────────┬──────────────┬────────┬────┬──┐║
║ │ ID  │ SKU      │ Cantidad │ EAN13        │ Costo  │Est.│Ac││
║ ├─────┼──────────┼──────────┼──────────────┼────────┼────┼──┤║
║ │ 1   │BLS-001  │ 250 ud   │2000100250... │ $1,500 │ ✅ │🖨️││ ← AQUÍ
║ │ 2   │BLS-002  │ 100 ud   │2000100100... │  $600  │ ✅ │🖨️││ ← AQUÍ
║ │ 3   │BLS-003  │ 500 ud   │2000100500... │ $3,000 │ ✅ │🖨️││ ← AQUÍ
║ │ 4   │BLS-004  │ 150 ud   │2000100150... │  $900  │ 🔄 │🖨️││ ← AQUÍ
║ └─────┴──────────┴──────────┴──────────────┴────────┴────┴──┘║
║                                                               ║
║ Cada 🖨️ abre el modal de impresión con:                      ║
║ • SKU bolsa pre-relleno                                      ║
║ • EAN13 generado automáticamente                             ║
║ • Modo: BolsaCOD (fijo)                                      ║
║ • Permite ajustar: copias, color, impresora                  ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

---

## PANTALLA 4: Admin → Tareas (Etiquetado)

```
╔═══════════════════════════════════════════════════════════════╗
║ 📋 GESTIÓN DE TAREAS                                          ║
║ ───────────────────────────────────────────────────────────── ║
║ Filtro: Tipo = [Todos ▼] Prioridad = [Todas ▼]  [🔄 Actualizar]║
║                                                               ║
║ 📌 TAREAS FILTRADAS: ETIQUETADO                              ║
║                                                               ║
║ ┌──────────────────────────────────────────────────────────┐ ║
║ │ 🎯 ETIQUETAR LOTE BOLSAS MANZANA                         │ ║
║ │                                                          │ ║
║ │ Tipo: 📦 Etiquetado                                     │ ║
║ │ Prioridad: ⚠️ ALTA                                       │ ║
║ │ Creada: 2026-06-28 09:15                                │ ║
║ │ Asignado a: Juan López (Operador Bodega)               │ ║
║ │ Límite: 2026-07-05                                      │ ║
║ │                                                          │ ║
║ │ Descripción: Generar y etiquetar 4 bolsas de manzana   │ ║
║ │ roja con EAN13 interno...                               │ ║
║ │                                                          │ ║
║ │ 📊 Items a etiquetar: 4                                 │ ║
║ │ • BLS-MANZANA-100 (100 ud)                            │ ║
║ │ • BLS-MANZANA-250 (250 ud)                            │ ║
║ │ • BLS-MANZANA-500 (500 ud)                            │ ║
║ │ • BLS-MANZANA-150 (150 ud)                            │ ║
║ │                                                          │ ║
║ │ ┌──────────────┬────────────┬─────────────────────────┐ │ ║
║ │ │ → IR A TAREA │ ✓ COMPLETAR│ 🖨️ IMPRIMIR TODO (4 items)│ │║
║ │ └──────────────┴────────────┴─────────────────────────┘ │ ║
║ │                                        ↑ AQUÍ            │ ║
║ └──────────────────────────────────────────────────────────┘ ║
║                                                               ║
║ ┌──────────────────────────────────────────────────────────┐ ║
║ │ ✅ ETIQUETAR BOLSAS NARANJA                              │ ║
║ │                                                          │ ║
║ │ Tipo: 📦 Etiquetado                                     │ ║
║ │ Prioridad: ℹ️ NORMAL                                    │ ║
║ │ Creada: 2026-06-27                                      │ ║
║ │ Asignado a: María Gonzáles                             │ ║
║ │ Límite: 2026-07-06                                      │ ║
║ │                                                          │ ║
║ │ 📊 Items: 2                                             │ ║
║ │ ┌──────────────┬────────────┬─────────────────────────┐ │ ║
║ │ │ → IR A TAREA │ ✓ COMPLETAR│ 🖨️ IMPRIMIR TODO (2 items)│ │║
║ │ └──────────────┴────────────┴─────────────────────────┘ │ ║
║ └──────────────────────────────────────────────────────────┘ ║
║                                                               ║
╚═══════════════════════════════════════════════════════════════╝
```

---

## MODAL DE IMPRESIÓN (común para todos)

```
╔════════════════════════════════════════════════════════════════╗
║                                                                ║
║  📋 IMPRIMIR ETIQUETA                                          ║
║  ────────────────────────────────────────────────────────────  ║
║                                                                ║
║  ✅ Agente de impresión: DISPONIBLE                            ║
║     Impresora activa: Brother QL-800                           ║
║                                                                ║
║  PRODUCTO                                                      ║
║  ┌──────────────────────────────────────────────────────────┐ ║
║  │ [Manzana Roja Premium]                                   │ ║
║  └──────────────────────────────────────────────────────────┘ ║
║                                                                ║
║  SKU                                                           ║
║  ┌──────────────────────────────────────────────────────────┐ ║
║  │ [123456]                                                 │ ║
║  └──────────────────────────────────────────────────────────┘ ║
║                                                                ║
║  CANTIDAD EAN            COPIAS                                ║
║  ┌────────────────┐      ┌────────────────┐                   ║
║  │ [100________]  │      │ [1_________]   │                   ║
║  └────────────────┘      └────────────────┘                   ║
║                                                                ║
║  MODO                    COLOR                                ║
║  ┌────────────────────┐  ┌────────────────────┐              ║
║  │ [BolsaCOD ▼]       │  │ [Blanco/Negro ▼]   │              ║
║  │ • Bolsa            │  │ • Blanco/Negro     │              ║
║  │ • BolsaCOD ✓       │  │ • Rojo/Negro ✓     │              ║
║  │ • EtiquetaSimple   │  └────────────────────┘              ║
║  │ • EtiquetaLogo     │                                       ║
║  │ • EtiquetaPrecio   │                                       ║
║  └────────────────────┘                                       ║
║                                                                ║
║  IMPRESORA                                                     ║
║  ┌──────────────────────────────────────────────────────────┐ ║
║  │ [Brother QL-800 (por defecto) ▼]                         │ ║
║  │                                                          │ ║
║  │ Disponibles:                                            │ ║
║  │ • Brother QL-800 (por defecto) ← PRE-SELECCIONADA      │ ║
║  │ • HP Laserjet M404n                                     │ ║
║  │ • Impresora local                                       │ ║
║  └──────────────────────────────────────────────────────────┘ ║
║                                                                ║
║  ┌──────────────────────────────────────────────────────────┐ ║
║  │ [CANCELAR]                    [IMPRIMIR ✓]              │ ║
║  └──────────────────────────────────────────────────────────┘ ║
║                                                                ║
║  ℹ️ Tip: La impresora seleccionada se guardará automáticamente ║
║          para futuras impresiones.                             ║
║                                                                ║
╚════════════════════════════════════════════════════════════════╝
```

---

## RESPUESTAS DEL SISTEMA

### ✅ Impresión exitosa

```
┌──────────────────────────────────────────────┐
│ ✅ Éxito                                      │
│                                              │
│ 3 etiquetas impresas correctamente           │
│                                              │
│ [Cerrar]                                    │
└──────────────────────────────────────────────┘
```

### ❌ Error de conexión

```
┌──────────────────────────────────────────────┐
│ ⚠️ Aviso                                      │
│                                              │
│ El agente de impresión no está disponible    │
│                                              │
│ Asegúrate de que EtiquetadorRS.exe está     │
│ ejecutándose en este PC.                    │
│                                              │
│ [OK]                                        │
└──────────────────────────────────────────────┘
```

### ❌ Error de plantilla

```
┌──────────────────────────────────────────────┐
│ ❌ Error                                      │
│                                              │
│ Error: Plantilla no encontrada               │
│ (EtiquetaLogoPrecio.lbx)                    │
│                                              │
│ Verifica que las plantillas están en:        │
│ %AppData%\Brother\P-touch Editor\            │
│ Template\Roll\BN\                            │
│                                              │
│ [Reintentar] [Cancelar]                    │
└──────────────────────────────────────────────┘
```

---

## FLUJO COMPLETO (Vista de usuario)

```
1. ACCEDE A PORTAL
   └─> http://tu-sitio.com/interno/barcodes

2. BUSCA PRODUCTO
   └─> Escanea o escribe SKU: 123456
   
3. VE RESULTADO
   └─> Producto encontrado con botón 🖨️
   
4. CLICK IMPRIMIR
   └─> Se abre MODAL
   
5. AJUSTA (OPCIONAL)
   └─> Cambiar cantidad, copias, modo, color, impresora
   
6. CLICK IMPRIMIR
   └─> POST al agente en puerto 19284
   
7. ESPERA CONFIRMACIÓN
   └─> "3 etiquetas impresas"
   
8. RESULTADO FÍSICO
   └─> 3 etiquetas salen de la impresora Brother
```

---

**Nota**: Todos los botones 🖨️ siguen este mismo flujo de modal → confirmación → impresión.

