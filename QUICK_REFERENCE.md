# ⚡ REFERENCIA RÁPIDA - Sistema de Impresión

## 🎯 Lo que hace
Permite imprimir etiquetas con código de barras desde la web (portal/admin) a una impresora Brother física en otra PC.

---

## 📍 Dónde se puede imprimir

| Ubicación | URL | Cómo |
|-----------|-----|------|
| **Portal Barcodes** | `/interno/barcodes` | Buscar SKU → [🖨️] |
| **Tienda Local** | Admin panel | Buscar → [🖨️] |
| **Embolsado** | Admin → Packaging | Tabla bolsas → [🖨️] |
| **Tareas** | Admin → Tareas | Etiquetado → [🖨️] |

---

## 🖨️ Modal de impresión

```
Campos auto-rellenos:
• Nombre del producto
• SKU
• Precio

Campos ajustables:
• Cantidad EAN (1-99999)
• Copias (1-100)
• Modo (5 opciones)
• Color (BN/RN)
• Impresora (auto-detectada)
```

---

## 🚀 Instalar en otra PC

**Requisitos:**
- Windows 10+
- .NET 8 Runtime
- Brother bPAC3 SDK
- Impresora Brother

**Pasos:**
1. Recibir ejecutable compilado
2. Copiar a `C:\Riverso-LabelPrinter\`
3. Ejecutar: `Impresion.exe --agent`
4. Crear servicio Windows (opcional)

**Ver**: [`INSTALL_GUIDE_PC_IMPRESORA.md`](INSTALL_GUIDE_PC_IMPRESORA.md)

---

## 💻 Compilar

```powershell
cd Impresion2\Impresion-master\Impresion
dotnet build -c Release -p:PlatformTarget=x86
```

**Ver**: [`BUILD_INSTRUCTIONS.md`](BUILD_INSTRUCTIONS.md)

---

## 🔧 Endpoints del agente

```
GET  http://127.0.0.1:19284/health
     → { ok: true, printerReady: true }

GET  http://127.0.0.1:19284/printers
     → { printers: [...], preferred: "Brother QL-800" }

POST http://127.0.0.1:19284/printers/select
     → { printerName: "Brother QL-800" }

POST http://127.0.0.1:19284/print
     → { jobs: [{sku, nombre, cantidad, ...}] }
```

---

## 📋 5 Modos de etiqueta

1. **Bolsa** - Simple
2. **BolsaCOD** - Con código de barras (RECOMENDADO)
3. **EtiquetaSimple** - Solo texto
4. **EtiquetaLogo** - Con logo
5. **EtiquetaLogoPrecio** - Con precio

---

## 🎨 2 Colores

- **BN** - Blanco/Negro
- **RN** - Rojo/Negro

---

## 📚 Documentación

| Necesito | Archivo |
|----------|---------|
| Instalar en otra PC | `INSTALL_GUIDE_PC_IMPRESORA.md` ⭐ |
| Compilar | `BUILD_INSTRUCTIONS.md` |
| Ver interfaz | `UI_VISUAL_GUIDE.md` |
| Técnico | `PRINT_AGENT_DEPLOYMENT.md` |
| Índice | `DOCUMENTACION_INDEX.md` |
| Resumen | `PRESENTACION_FINAL.md` |

---

## 🔑 Permisos

Capability necesaria: `riverso_print_labels`

Asignada a:
- ✅ Administrador Riverso
- ✅ Operador Bodega

---

## ⚙️ Configuración

**WordPress**:
```php
riverso_label_print_agent_url = 'http://127.0.0.1:19284'
riverso_label_print_auth_token = ''
```

**Agente** (`appsettings.json`):
```json
{
  "AgentSettings": {
    "Port": 19284,
    "AuthToken": null
  }
}
```

---

## ❓ Problemas comunes

| Problema | Solución |
|----------|----------|
| Modal dice "Agente no disponible" | Verificar que agente corre en puerto 19284 |
| "Plantilla no encontrada" | Copiar .lbx a `%AppData%\Brother\P-touch\Template\Roll\BN\` |
| "No hay impresoras" | Emparejar Brother en Dispositivos Windows |
| Compilación falla | Instalar Brother bPAC3 SDK |

---

## ✅ Checklist rápido

```
☐ Agente compilado y copiado a otra PC
☐ .NET 8 Runtime instalado en PC con impresora
☐ Brother bPAC3 SDK instalado
☐ Impresora Brother emparejada en Windows
☐ Agente ejecutándose: Impresion.exe --agent
☐ WordPress ve el agente: http://127.0.0.1:19284/health
☐ Portal accesible: /interno/barcodes
☐ Botón 🖨️ visible en los 4 lugares
☐ Impresión de prueba exitosa
```

---

**Versión**: 1.0  
**Última actualización**: Junio 2026

