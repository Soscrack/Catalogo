# Riverso Print Hub · «Imprimir Ya!»

Programa para el PC que tiene las impresoras (PC1). Revisa cada 2 segundos la cola de impresión
de riverso.cl e imprime los documentos con el preset configurado, sin el diálogo de Chrome.
Como solo hace conexiones de salida por HTTPS, funciona desde cualquier PC o celular con
sesión iniciada en `/interno/`, sin abrir puertos ni instalar nada en los celulares.

```
Celular / PC2 / PC1  ──«Imprimir Ya!»──►  riverso.cl (cola)  ◄──consulta HTTPS──  Riverso Print Hub (PC1)
                                                                                  ├─USB─► POS-58 (térmica)
                                                                                  └─red─► Canon GX7000
```

## 1. Generar el ejecutable

En un PC con el SDK de .NET 9:

```powershell
powershell -ExecutionPolicy Bypass -File print-hub\publish.ps1
```

Queda `print-hub\dist\RiversoPrintHub.exe`: un solo archivo que incluye .NET, así que no hay
que instalar nada más en PC1.

## 2. Instalar en PC1

1. Despliega el plugin (versión 1.8.59 o superior) con `deploy_plugin.py`. La migración crea las tablas `riverso_print_*`.
2. En riverso.cl abre **Facturación → Impresión → + Agregar hub**, ponle un nombre (ej. «PC1 Caja») y deja abierta la ventana con el **servidor** y el **token**. El token se muestra una sola vez.
3. Copia `RiversoPrintHub.exe` a PC1, por ejemplo en `C:\RiversoPrintHub\`, y ábrelo. Si aparece SmartScreen: *Más información → Ejecutar de todas formas*.
4. Pega el servidor y el token, y presiona **Probar y guardar**. El hub queda como un ícono junto al reloj y se abre solo al iniciar sesión en Windows.
5. Vuelve a **Facturación → Impresión**. El hub debe aparecer **En línea** y sus impresoras deben estar listadas.

El hub corre en la sesión del usuario de Windows, así que PC1 debe quedar con la sesión iniciada.
Si se cierra el hub (menú del ícono → Salir), «Imprimir Ya!» deja de funcionar en todos los dispositivos.

Colores del ícono:

| Color | Significado |
|---|---|
| Verde | Conectado. |
| Azul | Conectando. |
| Rojo | Sin conexión o token inválido. |
| Gris | Sin configurar. |

## 3. Configuración inicial (equivale a lo que hoy se elige en el diálogo de Chrome)

### Impresoras

- Desactiva los duplicados para que no confundan: `Canon GX7000 series (Copiar 1)` y `(Copiar 2)`. Las de FAX, PDF y OneNote ya quedan ocultas.
- Opcional: en la `Canon GX7000 series` usa **Editar** para escribir su IP (aparece en el panel de la Canon). Con la IP, el hub avisa «apagada» antes de imprimir en vez de esperar a que el trabajo falle.

### Presets

En el formulario de nuevo preset, los botones *Valores rápidos* ya rellenan estos valores:

| Preset | Impresora | Modo | Papel | Escala | Otros |
|---|---|---|---|---|---|
| Boleta térmica | POS-58 11.2.0.0 | Driver de Windows | ZPrinter Paper(58 x 3276mm) | Porcentaje 85 | 1 copia, sin color |
| Factura carta | Canon GX7000 series | Driver de Windows | Carta 22x28cm 8.5x11" | Ajustar al papel | Color, doble cara borde largo |

Los papeles se eligen de la lista que reporta cada impresora. Si el nombre no es idéntico al
que muestra Chrome, elige el equivalente.

Usa **Probar** en cada preset para imprimir una página de prueba de 50 mm. Si se ven los 4 bordes y la regla completa, la escala está bien.

Para la térmica también puedes probar el modo **ESC/POS directo**: 384 puntos, *Ajustar al papel*
y un avance final de 10 mm. Recorta los márgenes del PDF y usa todo el ancho del cabezal, sin
depender del papel de 3276 mm ni de la escala del driver. Quédate con el que imprima mejor el timbre.

### Ruteo

| Documento | Preset principal | Alternativa |
|---|---|---|
| Boleta electrónica | Boleta térmica | Factura carta |
| Factura electrónica | Factura carta | — |

Las **estaciones** son opcionales. Sirven si más adelante cada caja o celular debe imprimir en
una impresora distinta: cada dispositivo elige su estación en la misma pantalla, y una regla de
estación tiene prioridad sobre la general.

## 4. Uso

- **Imprimir Ya!** (vista del documento, a la izquierda de *Imprimir*): imprime con el preset del ruteo. El punto del botón indica el estado: verde lista, rojo con problema, gris sin regla. La flecha ▾ permite imprimir con otro preset.
- **Impresión rápida** (al emitir): el servidor encola la impresión junto con la emisión y la vista del documento muestra cómo avanza. La impresión nunca bloquea la emisión.
- Si algo falla, aparece el aviso de emergencia con el motivo y estas opciones: **Reintentar**, **imprimir en la alternativa** o **Imprimir normal** (el diálogo de siempre).

| Situación | Cómo se detecta | Tiempo |
|---|---|---|
| PC1 apagado, sin internet o hub cerrado | El hub lleva más de 25 s sin consultar la cola | Inmediato, antes de encolar |
| Térmica apagada o desenchufada | El puerto USB no tiene dispositivo | Inmediato (estado cada 10 s) |
| Canon apagada (con IP configurada) | No responde en la red | Inmediato (estado cada 10 s) |
| Sin papel, tapa abierta o atasco | Error en la cola de Windows | Unos 5 s |
| La impresora no termina el trabajo | Plazo de 30 s (ESC/POS) o 60 s (driver) | Se cancela para que no salga después |
| Nadie toma el trabajo | Plazo de 20 s | Se descarta y no sale después |

## 5. Problemas comunes

- **Registro:** menú del ícono → *Abrir registro* (`%LOCALAPPDATA%\RiversoPrintHub\logs`, se guardan 14 días).
- **Token rechazado** (ícono rojo): genera un token nuevo en *Facturación → Impresión → Nuevo token* y pégalo en *Configurar…*.
- **La térmica aparece «Sin conexión USB» estando encendida:** cierra el hub, pon `"UsbPresenceCheck": false` en `%LOCALAPPDATA%\RiversoPrintHub\config.json` y ábrelo de nuevo. Los problemas se seguirán detectando cuando el trabajo no avance en la cola.
- **«El driver no acepta ESC/POS directo»:** usa el modo *Driver de Windows* en ese preset.

## Notas técnicas

- Endpoints REST: `POST /wp-json/riverso/v1/print-hub/poll`, `GET …/jobs/{id}/file` y `POST …/jobs/{id}/status`. El token viaja en el encabezado `X-Riverso-Hub-Token`, y en el servidor solo se guarda su hash SHA-256.
- El PDF se pide a FACTO al momento de imprimir. Si recién se emitió y FACTO aún no lo genera, el hub reintenta durante 30 s.
- El render del PDF usa `Windows.Data.Pdf`, incluido en Windows 10/11, sin librerías externas.
- Proyecto en .NET 9. Para pasar a .NET 10 basta cambiar el `TargetFramework` a `net10.0-windows10.0.19041.0`.
