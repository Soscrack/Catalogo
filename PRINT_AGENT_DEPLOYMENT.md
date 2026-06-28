# Guía de despliegue - Agente de impresión de etiquetas Riverso

## Requisitos previos

- **SO**: Windows 10 o superior (x86 o x64)
- **SDK**: Brother bPAC3 SDK (última versión)
- **Editor**: P-touch Editor (instalado junto con bPAC)
- **.NET**: .NET 8.0 Runtime o SDK
- **Puerto**: 19284 (debe estar disponible en localhost)

## Instalación de dependencias

### 1. Instalar Brother bPAC3 SDK

Descarga e instala desde el sitio oficial de Brother:
- Accede a: https://www.brother.com/product/dev/bpac3/index.html
- Descarga el instalador para tu idioma
- Ejecuta el instalador con permisos de administrador
- Esto instalará automáticamente P-touch Editor y las plantillas de ejemplo

**Ruta de instalación típica**:
```
C:\Program Files (x86)\Brother bPAC3 SDK\
```

### 2. Verificar P-touch Editor

Abre P-touch Editor y verifica que puedas:
- Crear/editar plantillas `.lbx`
- Acceder a la carpeta de plantillas: `%AppData%\Brother\P-touch Editor\Template\Roll\`

### 3. Preparar plantillas de etiquetas

Copia o crea las plantillas `.lbx` esperadas en:

```
%AppData%\Brother\P-touch Editor\Template\Roll\BN\
├── EtiquetaBolsa.lbx
├── EtiquetaBolsaCOD.lbx
├── EtiquetaSimple.lbx
├── EtiquetaLogoSimple.lbx
└── EtiquetaLogoPrecioSimple.lbx

%AppData%\Brother\P-touch Editor\Template\Roll\RN\
└── (mismo conjunto para Rojo/Negro)
```

**Nota**: Las carpetas `BN` y `RN` se crean automáticamente si no existen.

### 4. Instalar .NET 8.0 Runtime

Si no lo tienes:
- Descarga desde: https://dotnet.microsoft.com/download/dotnet/8.0
- Selecciona "Runtime" (no SDK)
- Instala y verifica con: `dotnet --version`

## Compilar el agente

### Desde Visual Studio 2022

1. Abre la solución: `Impresion2\Impresion-master\Impresion.sln`
2. Verifica la configuración:
   - Platform: **x86** (requerido por bPAC COM)
   - Configuration: **Release**
3. Build → Build Solution
4. Ejecutable generado: `bin\x86\Release\net8.0-windows\Impresion.exe`

### Desde línea de comandos

```powershell
cd "Impresion2\Impresion-master\Impresion"
dotnet build -c Release -p:PlatformTarget=x86
```

## Ejecutar el agente

### Opción 1: Ejecución manual (pruebas)

```powershell
cd "ruta\al\Impresion"
.\bin\x86\Release\net8.0-windows\Impresion.exe --agent
```

Verás en consola:
```
Etiquetador Riverso - Agente de impresión iniciado
Escuchando en http://127.0.0.1:19284/
Presiona CTRL+C para detener...
```

### Opción 2: Ejecutar como servicio Windows

#### Crear servicio con NSSM (Non-Sucking Service Manager)

1. Descarga NSSM: https://nssm.cc/download
2. Extrae en una carpeta accesible, p. ej.: `C:\nssm\`
3. En PowerShell como administrador:

```powershell
$exePath = "C:\ruta\al\Impresion.exe"
$workDir = Split-Path -Parent $exePath

& "C:\nssm\nssm.exe" install "RiversoLabelPrinter" "$exePath" "--agent"
& "C:\nssm\nssm.exe" set "RiversoLabelPrinter" AppDirectory "$workDir"
& "C:\nssm\nssm.exe" set "RiversoLabelPrinter" AppStdout "C:\Logs\riverso-label-printer.log"
& "C:\nssm\nssm.exe" set "RiversoLabelPrinter" AppStderr "C:\Logs\riverso-label-printer.log"
```

Luego inicia el servicio:

```powershell
Start-Service RiversoLabelPrinter
```

Para detener:

```powershell
Stop-Service RiversoLabelPrinter
```

Para desinstalar:

```powershell
& "C:\nssm\nssm.exe" remove "RiversoLabelPrinter" confirm
```

#### Crear tarea programada (Task Scheduler)

1. Abre Task Scheduler (Programador de tareas)
2. Crear tarea básica:
   - Nombre: "Riverso Label Printer Agent"
   - Descripción: "Agente local de impresión de etiquetas"
3. Desencadenador: "Al iniciar el sistema"
4. Acción: "Iniciar programa"
   - Programa: `C:\ruta\al\Impresion.exe`
   - Argumentos: `--agent`
   - Iniciar en: `C:\ruta\al\` (carpeta padre)
5. Configuración:
   - ✓ Ejecutar con privilegios más altos (si es necesario)
   - ✓ Ejecutar incluso si el usuario no está conectado (para servicios)

### Opción 3: Acceso directo con inicio automático

1. Crea un acceso directo a `Impresion.exe` en:
   ```
   %AppData%\Microsoft\Windows\Start Menu\Programs\Startup\
   ```

2. Propiedades del acceso directo:
   - Destino: `C:\ruta\al\Impresion.exe --agent`
   - Inicio: `C:\ruta\al\`

## Configuración

### Archivo: `appsettings.json`

```json
{
  "AgentSettings": {
    "Port": 19284,
    "AuthToken": null,
    "RollBasePath": null
  }
}
```

- **Port**: Puerto en el que escucha el agente (por defecto 19284)
- **AuthToken**: Token Bearer opcional para autenticar peticiones (null = sin autenticación)
- **RollBasePath**: Ruta personalizada a plantillas (null = resolución automática)

### Opciones de WordPress

En el admin de WordPress, bajo Riverso POS → Etiquetas (si se implementa):

```php
update_option('riverso_label_print_agent_url', 'http://127.0.0.1:19284');
update_option('riverso_label_print_auth_token', 'tu_token_aqui');
```

## Permisos de firewall y URL ACL

### En Windows Firewall

Permitir comunicación local en puerto 19284:

```powershell
# Como administrador
netsh advfirewall firewall add rule name="RiversoLabelPrinter" dir=in action=allow protocol=tcp localport=19284 remoteip=localsubnet
```

### URL ACL (reserva de puerto)

```powershell
# Como administrador
netsh http add urlacl url=http://127.0.0.1:19284/ user="NT AUTHORITY\SYSTEM"
```

Para listar:
```powershell
netsh http show urlacl url=http://127.0.0.1:19284/
```

Para eliminar:
```powershell
netsh http delete urlacl url=http://127.0.0.1:19284/
```

## Verificación y troubleshooting

### 1. ¿Está escuchando el agente?

```powershell
# Verificar puerto 19284
netstat -ano | findstr :19284
```

Si no hay salida, el agente no está corriendo.

### 2. ¿Responde el endpoint /health?

```powershell
$response = Invoke-WebRequest -Uri "http://127.0.0.1:19284/health" -Method GET
$response.Content
```

Debería retornar algo como:
```json
{
  "ok": true,
  "version": "1.0",
  "printerReady": true,
  "activePrinter": "Brother QL-800"
}
```

### 3. ¿Están disponibles las impresoras?

```powershell
$response = Invoke-WebRequest -Uri "http://127.0.0.1:19284/printers" -Method GET
$response.Content | ConvertFrom-Json | Format-Table
```

### 4. ¿Se encuentra la plantilla?

Verifica que exista:
```powershell
Test-Path "$env:AppData\Brother\P-touch Editor\Template\Roll\BN\EtiquetaBolsaCOD.lbx"
```

Si retorna `False`, copia las plantillas necesarias.

### 5. Error: "bpac not found"

- Verifica que Brother bPAC3 SDK esté instalado en la ruta por defecto
- Si está en otra ruta, edita `LabelPrintService.cs` o usa la opción `RollBasePath` en `appsettings.json`

### 6. Error: "HRESULT: 0x80040154"

Típicamente significa que el COM de bpac.DLL no está registrado:

```powershell
# Como administrador
cd "C:\Program Files (x86)\Brother bPAC3 SDK\Samples\VBNET\Badge\bin\Release\"
regsvr32 Interop.bpac.dll
```

### 7. Logs de ejecución

Si ejecutas como servicio NSSM, los logs estarán en:
```
C:\Logs\riverso-label-printer.log
```

Consulta para errores relacionados con COM, impresora o plantillas.

## Sincronización con WordPress

### Configurar desde WordPress

1. Ve a: Admin → Riverso POS → Configuración (si existe)
2. Establece:
   - URL del agente: `http://127.0.0.1:19284`
   - Token (opcional)
3. Prueba con el botón "Verificar conexión"

### Desde terminal

```bash
wp option update riverso_label_print_agent_url 'http://127.0.0.1:19284' --path=/ruta/a/wordpress
wp option update riverso_label_print_auth_token '' --path=/ruta/a/wordpress
```

## Flujo de impresión end-to-end

```
1. Usuario en portal/tienda-local busca SKU
2. Hace clic en botón "Imprimir"
3. Modal solicita cantidad, copias, modo, color, impresora
4. JavaScript envía: POST http://127.0.0.1:19284/print
5. Agente recibe job, abre .lbx, asigna campos
6. Brother PrintOut envía a impresora seleccionada
7. Usuario ve confirmación ✅
```

## Soporte y actualización

- **Actualizaciones del agente**: Recompila con `dotnet build` y reemplaza el `.exe`
- **Cambios de plantillas**: Modifica `.lbx` en P-touch Editor, sin necesidad de reiniciar agente
- **Cambio de impresora**: Selecciona en el modal de impresión; la preferencia se persiste automáticamente

---

**Versión**: 1.0  
**Fecha**: Junio 2026
