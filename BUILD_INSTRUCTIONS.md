# 🔨 COMPILACIÓN DEL AGENTE - Instrucciones simplificadas

## Opción 1: Compilar en PC actual y distribuir ⭐ RECOMENDADO

### En tu PC (con Visual Studio 2022 y Brother SDK instalado)

**Paso 1: Abre la solución**
```
Archivo → Abrir Proyecto/Solución
Navega a: Impresion2\Impresion-master\Impresion.sln
Click Abrir
```

**Paso 2: Verifica configuración**
- Arriba de la barra: Asegúrate que dice **"Release"** (no Debug)
- Junto a Release: Asegúrate que dice **"x86"** (no x64)

**Paso 3: Compila**
```
Build → Build Solution
```

Deberías ver al final:
```
========== Build: 1 succeeded, 0 failed ==========
Tiempo transcurrido: 00:00:XX
```

**Paso 4: Localiza el ejecutable compilado**
```
Impresion2\Impresion-master\Impresion\bin\x86\Release\net8.0-windows\
```

Debe contener:
```
Impresion.exe
appsettings.json
Interop.bpac.dll
System.*.dll
(y muchos más .dll)
```

**Paso 5: Empaqueta para distribuir**

Crea una carpeta para copiar todos los archivos:
```powershell
# En PowerShell
mkdir "C:\Deploy-Riverso-Impresion"
Copy-Item "Impresion2\Impresion-master\Impresion\bin\x86\Release\net8.0-windows\*" `
  -Destination "C:\Deploy-Riverso-Impresion" -Recurse
cd C:\Deploy-Riverso-Impresion
Get-ChildItem | wc -l  # Debe mostrar 50+ archivos
```

**Paso 6: Copia a USB o comparte con la otra PC**

Copia la carpeta `C:\Deploy-Riverso-Impresion\` a USB y lleva a la otra PC.

---

## Opción 2: Compilar en PC con impresora directamente

### En la PC que tiene la impresora

**Requisitos previos:**
- ✅ Windows 10+
- ✅ .NET 8 Runtime (https://dotnet.microsoft.com/download/dotnet/8.0)
- ✅ Git instalado (opcional, para clonar repo)
- ✅ Brother bPAC3 SDK instalado

**Paso 1: Obtén el código fuente**

Opción A: Clona desde GitHub
```powershell
git clone https://github.com/tu-usuario/Simulacion.git
cd Simulacion\Impresion2\Impresion-master\Impresion
```

Opción B: Copia manualmente la carpeta del proyecto

**Paso 2: Compila en línea de comandos**

```powershell
cd "Impresion2\Impresion-master\Impresion"
dotnet build -c Release -p:PlatformTarget=x86
```

Deberías ver:
```
Restaurando la solución...
Compilando...
Build succeeded.
```

**Paso 3: Ejecuta el agente**

```powershell
cd bin\x86\Release\net8.0-windows
.\Impresion.exe --agent
```

---

## ❌ Errores comunes y soluciones

### Error: "No se puede resolver la referencia Interop.bpac"

**Causa**: Brother bPAC3 SDK no está instalado

**Solución**:
1. Descarga Brother bPAC3 desde: https://www.brother.com/product/dev/bpac3/
2. Instala en ubicación por defecto
3. Reinicia Visual Studio
4. Intenta compilar de nuevo

### Error: "PlatformTarget x86 no soportado"

**Causa**: Versión antigua de .NET

**Solución**:
```powershell
# Verifica versión de .NET
dotnet --version
# Debe mostrar 8.0.x o superior
# Si no, descarga desde https://dotnet.microsoft.com/download/dotnet/8.0
```

### Error: "HRESULT: 0x80040154 (Class not registered)"

**Causa**: COM de bPAC no registrado

**Solución** (en PowerShell como administrador):
```powershell
cd "C:\Program Files (x86)\Brother bPAC3 SDK\Samples\VBNET\Badge\bin\Release\"
regsvr32 Interop.bpac.dll
```

---

## 📦 Distribución del compilado

### Estructura de carpeta para distribuir:

```
RiversoLabelPrinterAgent-v1.0.zip
└── RiversoLabelPrinter/
    ├── Impresion.exe
    ├── appsettings.json
    ├── *.dll (todos los archivos DLL)
    ├── INSTALL_GUIDE_PC_IMPRESORA.md (esta guía)
    ├── README.txt
    └── nssm.exe (opcional, para crear servicio)
```

### README.txt (para incluir):

```
Agente de Impresión Riverso - v1.0
===================================

INSTRUCCIONES DE INSTALACIÓN:

1. Descomprime esta carpeta a: C:\Riverso-LabelPrinter\

2. Abre PowerShell como administrador

3. Ejecuta para probar:
   cd C:\Riverso-LabelPrinter
   .\Impresion.exe --agent

4. Deberías ver:
   Etiquetador Riverso - Agente de impresión iniciado
   Escuchando en http://127.0.0.1:19284/

5. Para instalar como servicio Windows:
   .\nssm.exe install "RiversoLabelPrinter" "C:\Riverso-LabelPrinter\Impresion.exe" "--agent"
   Start-Service RiversoLabelPrinter

REQUISITOS:
- Windows 10 o superior
- .NET 8.0 Runtime (descargar si no lo tienes)
- Brother bPAC3 SDK instalado
- Impresora Brother emparejada en Windows

DOCUMENTACIÓN:
Ver archivo: INSTALL_GUIDE_PC_IMPRESORA.md
```

---

## 🧪 Verificación post-compilación

Después de compilar, verifica que todo está completo:

```powershell
cd "bin\x86\Release\net8.0-windows"

# Verifica que los archivos esenciales existen
Test-Path "Impresion.exe"            # Debe ser True
Test-Path "appsettings.json"         # Debe ser True
Test-Path "Interop.bpac.dll"         # Debe ser True

# Cuenta archivos DLL
@(Get-ChildItem -Filter "*.dll").Count  # Debe ser 40+
```

---

## 📝 Notas importantes

- **x86 obligatorio**: bPAC requiere arquitectura x86 (32 bits), no x64
- **Release mode**: Siempre compila en Release para producción, no Debug
- **Ubicación de plantillas**: `.lbx` van en `%AppData%\Brother\P-touch Editor\Template\Roll\BN\`
- **Puerto 19284**: No cambies de puerto a menos que configures firewall
- **Actualizaciones**: Para actualizar, solo reemplaza `Impresion.exe`

