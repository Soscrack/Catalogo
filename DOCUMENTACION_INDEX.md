# 📑 ÍNDICE DE DOCUMENTACIÓN - Sistema de Impresión de Etiquetas

## 🚀 EMPEZAR AQUÍ

### ▶️ Para la PC con la impresora
**Leer primero**: [`INSTALL_GUIDE_PC_IMPRESORA.md`](INSTALL_GUIDE_PC_IMPRESORA.md)
- Guía paso-a-paso simplificada
- Requisitos previos
- Instalación de Brother SDK
- Despliegue del agente
- Troubleshooting

---

## 📚 DOCUMENTACIÓN COMPLETA

### 1. Para Técnicos que Compilan
| Documento | Descripción |
|-----------|-------------|
| [`BUILD_INSTRUCTIONS.md`](BUILD_INSTRUCTIONS.md) | Cómo compilar el agente .NET (2 opciones) |
| [`PRINT_AGENT_DEPLOYMENT.md`](PRINT_AGENT_DEPLOYMENT.md) | Guía técnica completa (servicio Windows, firewall, etc.) |

### 2. Para Usuarios que Usan la Interfaz
| Documento | Descripción |
|-----------|-------------|
| [`PRINT_LOCATIONS_GUIDE.md`](PRINT_LOCATIONS_GUIDE.md) | Dónde aparecen los botones 🖨️ de impresión |
| [`UI_VISUAL_GUIDE.md`](UI_VISUAL_GUIDE.md) | Pantallas visuales y flujos del usuario |
| [`PRINT_LOCATIONS_GUIDE.md`](PRINT_LOCATIONS_GUIDE.md) | Ejemplo paso-a-paso de impresión |

### 3. Resúmenes Generales
| Documento | Descripción |
|-----------|-------------|
| [`README_IMPRESION_FINAL.md`](README_IMPRESION_FINAL.md) | Resumen completo del proyecto |

---

## 🎯 GUÍAS POR PERFIL

### 👨‍💻 Desarrollador (mi PC)
1. Lee [`BUILD_INSTRUCTIONS.md`](BUILD_INSTRUCTIONS.md) - Sección "Opción 1"
2. Compila el proyecto
3. Empaqueta para distribuir
4. Entrega a PC con impresora

### 👨‍🔧 Técnico (PC con impresora)
1. **Lee**: [`INSTALL_GUIDE_PC_IMPRESORA.md`](INSTALL_GUIDE_PC_IMPRESORA.md)
2. Instala Brother bPAC3 SDK
3. Copia ejecutable compilado
4. Ejecuta como servicio Windows
5. Si algo falla, ve a sección "Troubleshooting"

### 👤 Operario (Portal/Admin)
1. **Lee**: [`PRINT_LOCATIONS_GUIDE.md`](PRINT_LOCATIONS_GUIDE.md) (Sección 1-4)
2. **Referencia**: [`UI_VISUAL_GUIDE.md`](UI_VISUAL_GUIDE.md) (cómo se ve)
3. Busca producto en portal
4. Haz click en 🖨️ IMPRIMIR
5. Ajusta opciones en modal
6. Confirma impresión

### 🏭 Admin/DevOps
1. **Lee**: [`PRINT_AGENT_DEPLOYMENT.md`](PRINT_AGENT_DEPLOYMENT.md)
2. Configura firewall
3. Crea servicio Windows
4. Monitorea logs
5. Gestiona actualizaciones

---

## 🗂️ ESTRUCTURA DE ARCHIVOS

```
Catalogo/
├── 📄 INSTALL_GUIDE_PC_IMPRESORA.md
│   └─> Guía simplificada para instalar en otra PC ⭐
├── 📄 BUILD_INSTRUCTIONS.md
│   └─> Cómo compilar el agente
├── 📄 PRINT_AGENT_DEPLOYMENT.md
│   └─> Guía técnica completa de despliegue
├── 📄 PRINT_LOCATIONS_GUIDE.md
│   └─> Dónde se ve la interfaz de impresión
├── 📄 UI_VISUAL_GUIDE.md
│   └─> Pantallas visuales y flujos
├── 📄 README_IMPRESION_FINAL.md
│   └─> Resumen de todo el proyecto
├── 📄 DOCUMENTACION_INDEX.md
│   └─> Este archivo
│
├── 📦 Impresion2/Impresion-master/Impresion/
│   ├── Services/
│   │   ├── Ean13Service.cs
│   │   ├── LabelPrintService.cs
│   │   ├── PrinterDiscoveryService.cs
│   │   └── PrintAgentHost.cs
│   ├── Models/
│   │   └── PrintJobRequest.cs
│   ├── Program.cs (actualizado)
│   └── appsettings.json
│
├── 📦 php/riverso-pos/
│   ├── modules/labels/
│   │   └── class-label-print-module.php
│   ├── assets/js/
│   │   └── label-print-client.js
│   ├── includes/
│   │   └── class-assets.php (actualizado)
│   ├── templates/
│   │   ├── portal/portal-main.php (botones añadidos)
│   │   ├── tienda-local.php (botones añadidos)
│   │   ├── packaging.php (botones añadidos)
│   │   └── tasks.php (botones añadidos)
│   └── riverso-pos.php (registro del módulo)
```

---

## ⚡ QUICK START

### Para empezar rápido (15 minutos)

1. **Compilar agente** (en tu PC):
   ```powershell
   cd Impresion2\Impresion-master\Impresion
   dotnet build -c Release -p:PlatformTarget=x86
   ```

2. **Copiar a otra PC**:
   ```powershell
   Copy-Item "bin\x86\Release\net8.0-windows\*" `
     -Destination "C:\Riverso-LabelPrinter" -Recurse
   ```

3. **Ejecutar en PC con impresora**:
   ```powershell
   cd C:\Riverso-LabelPrinter
   .\Impresion.exe --agent
   ```

4. **Probar desde navegador** (portal):
   ```
   https://tu-sitio.com/interno/barcodes
   [Busca SKU] → [Click 🖨️]
   ```

---

## 🔍 BÚSQUEDA RÁPIDA

### Necesito...

| Necesito | Documento |
|----------|-----------|
| **Instalar en otra PC** | [`INSTALL_GUIDE_PC_IMPRESORA.md`](INSTALL_GUIDE_PC_IMPRESORA.md) |
| **Compilar el código** | [`BUILD_INSTRUCTIONS.md`](BUILD_INSTRUCTIONS.md) |
| **Ver dónde está el botón Imprimir** | [`PRINT_LOCATIONS_GUIDE.md`](PRINT_LOCATIONS_GUIDE.md) |
| **Entender cómo se ve** | [`UI_VISUAL_GUIDE.md`](UI_VISUAL_GUIDE.md) |
| **Solucionar un problema** | [`INSTALL_GUIDE_PC_IMPRESORA.md#troubleshooting`](INSTALL_GUIDE_PC_IMPRESORA.md) |
| **Configurar servicio Windows** | [`PRINT_AGENT_DEPLOYMENT.md#opcion-2-servicio`](PRINT_AGENT_DEPLOYMENT.md) |
| **Ver resumen del proyecto** | [`README_IMPRESION_FINAL.md`](README_IMPRESION_FINAL.md) |
| **Entender la arquitectura** | [`README_IMPRESION_FINAL.md#flujo`](README_IMPRESION_FINAL.md) |

---

## ✅ CHECKLIST DE INSTALACIÓN

```
PREPARACIÓN (tu PC):
☐ Instalar Visual Studio 2022
☐ Instalar .NET 8 SDK
☐ Instalar Brother bPAC3 SDK
☐ Clonar repositorio

COMPILACIÓN (tu PC):
☐ Abrir Impresion.sln
☐ Establecer Release + x86
☐ Compilar (Build → Build Solution)
☐ Copiar archivos a carpeta Deploy

DISTRIBUCIÓN:
☐ Copiar carpeta Deploy a USB
☐ Llevar USB a PC con impresora

INSTALACIÓN (PC con impresora):
☐ Instalar .NET 8 Runtime
☐ Instalar Brother bPAC3 SDK
☐ Copiar archivos a C:\Riverso-LabelPrinter\
☐ Probar: Impresion.exe --agent
☐ Crear servicio Windows (opcional)

CONFIGURACIÓN (WordPress):
☐ Opciones: riverso_label_print_agent_url
☐ Probar conexión al agente
☐ Verificar permisos de usuario

VALIDACIÓN:
☐ Acceder a portal /interno/barcodes
☐ Buscar SKU local
☐ Click botón 🖨️ IMPRIMIR
☐ Modal abre correctamente
☐ Impresora disponible
☐ Imprimir 1 etiqueta de prueba
☐ Etiqueta sale de impresora físicamente
```

---

## 🚀 PRÓXIMAS FASES (Futuro)

### Fase 5: UI mejorada
- [ ] Vista previa de etiqueta en modal
- [ ] Historial de impresiones
- [ ] Reporte de etiquetas impresas

### Fase 6: Integración avanzada
- [ ] Panel de control del agente
- [ ] Logs centralizados
- [ ] Auto-actualización del agente

### Fase 7: Soporte adicional
- [ ] Múltiples impresoras
- [ ] Colas de impresión
- [ ] Reconexión automática

---

## 📞 SOPORTE

### Si algo no funciona

1. **Comprueba conexión**:
   ```powershell
   Invoke-WebRequest http://127.0.0.1:19284/health
   ```

2. **Comprueba logs** (si es servicio):
   ```
   C:\Logs\riverso-label-printer.log
   ```

3. **Consulta troubleshooting**:
   - [`INSTALL_GUIDE_PC_IMPRESORA.md#troubleshooting`](INSTALL_GUIDE_PC_IMPRESORA.md)
   - [`PRINT_AGENT_DEPLOYMENT.md#troubleshooting`](PRINT_AGENT_DEPLOYMENT.md)

---

## 📝 Notas importantes

⚠️ **Antes de empezar**:
- Verifica que Brother bPAC3 SDK está instalado en ubicación por defecto
- Compila siempre en modo **Release** y plataforma **x86**
- Las plantillas `.lbx` deben estar en `%AppData%\Brother\P-touch Editor\Template\Roll\BN\`
- Puerto 19284 debe estar disponible (configurable en appsettings.json)

---

**Versión**: 1.0  
**Última actualización**: Junio 2026  
**Mantenedor**: Equipo Técnico Riverso

