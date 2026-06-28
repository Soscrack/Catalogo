# GUÍA RÁPIDA: Instalar agente de impresión en PC con la impresora

## 🎯 Resumen

La PC que tiene la impresora Brother necesita ejecutar un **agente .NET** que reciba pedidos de impresión desde la web (WordPress) y los envíe a la impresora.

**Tiempo estimado**: 30-45 minutos

---

## ✅ PASO 1: Verificar requisitos

En la PC con la impresora, abre **PowerShell como administrador** y verifica:

### 1.1 ¿Windows 10+ ?
```powershell
[System.Environment]::OSVersion.VersionString
```
Debe mostrar "Windows 10" o superior.

### 1.2 ¿Brother bPAC3 SDK instalado?
```powershell
Test-Path "C:\Program Files (x86)\Brother bPAC3 SDK\"
```
Si dice `False`, debes instalarlo (ve a Paso 3).

### 1.3 ¿.NET 8 Runtime disponible?
```powershell
dotnet --version
```
Debe mostrar `8.0.x` o superior. Si no existe, descarga desde: https://dotnet.microsoft.com/download/dotnet/8.0

---

## 📥 PASO 2: Obtener el ejecutable compilado

### Opción A: Compilar en tu PC actual (recomendado)

1. **En tu PC (la que tiene Visual Studio)**:
   - Abre `Impresion2\Impresion-master\Impresion.sln` en Visual Studio 2022
   - Verifica que tienes Brother bPAC3 SDK instalado localmente
   - Build → Build Solution (Configuration: Release, Platform: x86)
   - Archivo generado: `bin\x86\Release\net8.0-windows\Impresion.exe`

2. **Copia a una carpeta para distribuir**:
   ```powershell
   # En tu PC
   mkdir "C:\Riverso-LabelPrinter-Deploy"
   Copy-Item "Impresion2\Impresion-master\Impresion\bin\x86\Release\net8.0-windows\*" `
     -Destination "C:\Riverso-LabelPrinter-Deploy" -Recurse
   ```

3. **Copia a la otra PC** (por USB, red, o archivo compartido)

### Opción B: Descargar desde repositorio

(Cuando lo subas a GitHub con releases)

---

## 🔧 PASO 3: Instalar Brother bPAC3 SDK (en PC con impresora)

**Solo si el test del Paso 1.2 devolvió `False`**

1. Ve a: https://www.brother.com/product/dev/bpac3/index.html
2. Descarga el instalador para tu idioma
3. Ejecuta el instalador (.msi) con permisos de administrador
4. Sigue los pasos (acepta licencia, ubicación por defecto)
5. Al terminar, cierra y continúa con Paso 4

---

## 🚀 PASO 4: Desplegar el agente

### En PC con impresora (PowerShell como administrador)

**4.1 Crear carpeta de la aplicación**

```powershell
mkdir "C:\Riverso-LabelPrinter"
cd "C:\Riverso-LabelPrinter"
```

**4.2 Copiar archivos del ejecutable**

Copia todos los archivos de `Riverso-LabelPrinter-Deploy` a `C:\Riverso-LabelPrinter\`

Debe contener:
```
C:\Riverso-LabelPrinter\
├── Impresion.exe
├── appsettings.json
├── *.dll (múltiples)
└── ...
```

**4.3 Verificar que funciona (prueba manual)**

```powershell
cd "C:\Riverso-LabelPrinter"
.\Impresion.exe --agent
```

Deberías ver:
```
Etiquetador Riverso - Agente de impresión iniciado
Escuchando en http://127.0.0.1:19284/
Presiona CTRL+C para detener...
```

**4.4 Probar endpoint en otra ventana PowerShell**

```powershell
Invoke-WebRequest -Uri "http://127.0.0.1:19284/health" -Method GET | Format-List
```

Respuesta esperada:
```
StatusCode        : 200
StatusDescription : OK
RawContent        : HTTP/1.1 200 OK
                    ...
Content            : {"ok":true,"version":"1.0","printerReady":true,"activePrinter":"Brother QL-800"}
```

---

## 🔄 PASO 5: Ejecutar como servicio Windows (permanente)

### Opción A: Crear servicio con NSSM (recomendado para producción)

**5A.1 Descargar NSSM**

```powershell
# En C:\Riverso-LabelPrinter
curl -o nssm.zip "https://nssm.cc/download/nssm-2.24-103-gbaf282d.zip"
Expand-Archive nssm.zip
# Mueve nssm.exe a la carpeta
Move-Item "nssm\win64\nssm.exe" "."
```

**5A.2 Instalar servicio**

```powershell
# Como administrador, en C:\Riverso-LabelPrinter
.\nssm.exe install "RiversoLabelPrinter" "C:\Riverso-LabelPrinter\Impresion.exe" "--agent"
.\nssm.exe set "RiversoLabelPrinter" AppDirectory "C:\Riverso-LabelPrinter"
```

**5A.3 Iniciar servicio**

```powershell
Start-Service RiversoLabelPrinter
```

Verificar:
```powershell
Get-Service RiversoLabelPrinter | Select-Object Status
```

Debe mostrar: `Status : Running`

### Opción B: Crear tarea en Task Scheduler (más simple)

**5B.1 Abre Programador de tareas**

- Busca "Programador de tareas" en Windows
- O: `taskschd.msc` en PowerShell

**5B.2 Crear tarea básica**

- Click derecho en "Tareas programadas" → "Crear tarea básica..."
- Nombre: `RiversoLabelPrinter`
- Descripción: `Agente de impresión de etiquetas Riverso`

**5B.3 Desencadenador**

- Desencadenador: "Al iniciar el sistema"
- Marcar "Habilitado"

**5B.4 Acción**

- Programa: `C:\Riverso-LabelPrinter\Impresion.exe`
- Argumentos: `--agent`
- Iniciar en: `C:\Riverso-LabelPrinter`

**5B.5 Configuración**

- Marcar: "Ejecutar con privilegios más altos"
- Marcar: "Ejecutar incluso si el usuario no está conectado"

---

## 🔌 PASO 6: Configurar firewall (si es necesario)

```powershell
# Como administrador
netsh advfirewall firewall add rule name="RiversoLabelPrinter" `
  dir=in action=allow protocol=tcp localport=19284
```

---

## 🌐 PASO 7: Configurar WordPress para conectar al agente

### En la PC con WordPress (admin panel)

1. Ve a: **Riverso POS** → **Configuración** (si existe panel de config)
2. Busca: "Agente de impresión" o "Label Print Settings"
3. Establece:
   - **URL del agente**: `http://127.0.0.1:19284` (si WordPress está en la misma PC)
   - O: `http://<IP-PC-IMPRESORA>:19284` (si están en red diferente)
   - **Token** (opcional): Déjalo vacío por ahora

4. Click "Verificar conexión"

Si funciona, verás ✅ "Conectado"

---

## 📍 PASO 8: Ver dónde se puede imprimir

Una vez que el agente está corriendo, aparecen botones **"🖨️ Imprimir"** en:

### 1. **Portal → Barcodes** (`/interno/barcodes`)
   - Busca un SKU local
   - Click en botón "🖨️ Imprimir" en el producto
   - Se abre modal con opciones

### 2. **Admin → Tienda Local**
   - Busca producto
   - Click en "🖨️ Imprimir"

### 3. **Admin → Embolsado (Packaging)**
   - En la tabla de bolsas generadas
   - Columna "Acción" con botón "🖨️ Imprimir"
   - Incluye EAN13 pregenerado

### 4. **Admin → Tareas**
   - Filtra por tipo "Etiquetado"
   - Botón "🖨️ Imprimir" en la tarjeta
   - Imprime lote de items

---

## 🐛 Troubleshooting

| Problema | Solución |
|----------|----------|
| "Conexión rechazada en 127.0.0.1:19284" | Verifica que agente está corriendo: `netstat -ano \| findstr :19284` |
| "No se encuentra Impresion.exe" | Verifica la ruta y permisos de carpeta |
| "HRESULT: 0x80040154" COM error | Reinstala Brother bPAC3 SDK o ejecuta como administrador |
| "Plantilla no encontrada" | Verifica que `.lbx` existen en `%AppData%\Brother\P-touch Editor\Template\Roll\BN\` |
| "No hay impresoras disponibles" | Conecta/empareja la impresora Brother en Windows (Dispositivos → Impresoras) |
| Modal abierto pero "Agente no disponible" | Firewall bloqueando. Usa netsh para abrir puerto 19284 |

---

## 📋 Checklist final

- [ ] PC tiene .NET 8 Runtime
- [ ] Brother bPAC3 SDK instalado
- [ ] Impresion.exe copiado a `C:\Riverso-LabelPrinter\`
- [ ] Agente responde: `http://127.0.0.1:19284/health`
- [ ] Agente configurado para ejecutarse al iniciar Windows
- [ ] WordPress puede contactar al agente
- [ ] Impresora Brother está conectada y emparejada
- [ ] Plantillas `.lbx` en `%AppData%\Brother\P-touch Editor\Template\Roll\BN\`

---

## 📞 Soporte

Si algo falla:

1. Abre PowerShell en `C:\Riverso-LabelPrinter`
2. Ejecuta: `.\Impresion.exe --agent`
3. Copia los errores que aparezcan
4. Comparte con el equipo técnico

**Nota**: El agente genera logs automáticos si está configurado como servicio NSSM.

