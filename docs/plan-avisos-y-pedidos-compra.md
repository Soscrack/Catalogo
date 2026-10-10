# Plan — Avisos de bodega, escáner y portal de pedidos de compra

Fecha: 2026-10-10. Decisiones de diseño para reemplazar el grupo de WhatsApp de encargos (foto + “pedir N” + pulgar arriba) sin esperar a que el inventario esté cuadrado.

Revisado el mismo día con pruebas sobre las 72 fotos y el snapshot local de abril. Las fases 1 y 2 están implementadas (plugin 1.8.61, pantalla `/interno/avisos/`).

## Veredicto

Factible por partes. El primer entregable es el aviso con foto y su bandeja por proveedor: eso solo ya reemplaza el WhatsApp. La cámara de barras acelera la identificación y va en la misma pantalla. El portal de pedidos de compra viene después y hay que construirlo casi entero: hoy son cuatro endpoints sin pantalla. El OCR queda opcional. No se usa Gemini ni visión en el servidor.

## Contexto operativo

El inventario no se considera confiable para armar un pedido solo. Hoy un empleado que ve poco stock hace una de tres cosas, y el grupo de WhatsApp las mezcla en el mismo mensaje:

1. Cuenta lo que queda de ese producto, o de su familia.
2. Avisa que falta, sin decir cuánto. Otra persona define la cantidad.
3. Avisa y, a ojo, propone cuánto encargar.

Alguien arma el pedido al proveedor y reacciona con pulgar arriba cuando la línea quedó ingresada.

Los teléfonos de bodega son Android e iPhone. Ya entran al portal interno desde ahí. El portal ya tiene conteo (lugar, producto, recorrido) con campo de texto para pistola (`riverso_inventory_decode_barcode`).

## Qué muestran las fotos

Corpus de regresión, no de éxito del escáner en vivo: `C:\Users\jorge\Downloads\Fotos` (72 fotos de WhatsApp, comprimidas). Mamut 25, Steelfix 25, Wurth 22. Miden el peor caso: foto ya tomada, recortada, a veces rotada.

| Origen | Qué hay en la etiqueta | Camino de identificación |
|---|---|---|
| Mamut, caja amarilla | EAN-13 `780983…` y código corto impreso (`01TMPA`, `14RLBC`, `06MSA`) | Barras primero. El código corto también sirve digitado |
| Etiqueta propia Riverso | Code128 `2000…` con el SKU, más un QR con una URL | Barras. El QR se ignora |
| Steelfix, caja máster y caja chica | Sin barras. `CODIGO: 000-139`, `CODE: 100-321` | Código digitado (6 dígitos) |
| Skairos, Asgard, KBEEN (mismo proveedor) | `código: 211-119`; a veces barra propia | Código digitado o barras |
| Wurth con etiqueta | `Cód.` o `Art.` (`0617 401 100`) y EAN | Barras primero |
| Wurth, frente del producto | Solo el nombre: spray, alicate, pulverizador | Dar vuelta el producto o buscar por nombre |
| Sticker manuscrito | Número de 3 a 5 dígitos (`21274`, `Cod: 24574`) | Es el SKU local: se digita |
| Sin código | Nota a mano (`Hilo 1/4 métrico`) | Aviso sin identificar, con foto |

Lo medido:

- 35 de las 72 fotos tienen una barra en el encuadre: Mamut 23, Wurth 9, Steelfix 3. Las otras 37 no tienen ninguna. El camino sin barra es la mitad del uso.
- Sobre esas 35, ZXing-JS lee 9 y zxing-wasm (zxing-cpp compilado a WebAssembly) lee 20. Sin búsqueda exhaustiva ni rotación, zxing-wasm lee 8.
- 14 de 17 EAN de proveedor leídos tienen SKU en el snapshot local, y el nombre coincide con la foto.
- 19 de 19 códigos cortos Mamut están en `data/sku_mapping.json`.
- 14 de 15 códigos Steelfix y 5 de 6 artículos Wurth del corpus están en el snapshot **como código de barra** (el atajo viejo del POS antiguo). El snapshot tiene 280 barras con forma `NNN-NNN`.
- 6 de 9 números manuscritos son SKU locales cuyo nombre coincide con el producto fotografiado.
- OCR con Tesseract sobre 16 códigos Steelfix legibles: 7 en una pasada, 11 probando rotaciones, ninguno equivocado.
- Una caja Tricolor reutilizada con anclajes adentro: la barra identifica el producto equivocado.

En producción, con el buscador de avisos ya desplegado (`tools/probar_avisos_portal.py`), 67 de 70 códigos del corpus identifican un producto: 18 de 19 EAN, 19 de 19 códigos Mamut, 15 de 15 Steelfix, 6 de 6 Wurth y 9 de 11 números manuscritos. Dos cosas que deja ver esa corrida:

- La mayoría de las barras está en estado “sin confirmar” (propuestas desde el POS antiguo). Identifican bien, pero nadie las ha verificado.
- Casi ningún producto Steelfix o Wurth tiene vínculo en `producto_proveedor`: 13 de 15 y 5 de 6 salen “sin proveedor”. Mamut (TECBOLT SA) sí lo tiene.

## Decisiones

**D1. El aviso y el conteo son objetos distintos.** El conteo registra cuánto hay. El aviso registra que alguien quiere encargar. Un mesón vacío no implica que no haya caja en otro lugar. No se actualiza stock desde un aviso.

**D2. La cantidad del aviso es una propuesta.** Puede ir vacía. No es stock ni línea de pedido cerrada. Queda guardado quién la escribió y si alguien de compras la confirmó.

**D3. Un texto leído o escrito se busca en todas las fuentes, y se muestran todos los productos que calzan.** No se elige por la persona: si calza con más de uno, ella elige. Fuentes:

1. Barra en `codigo_barra` (y tablas antiguas), con y sin ceros a la izquierda. Incluye los códigos de proveedor guardados como barra.
2. `producto_proveedor.codigo_barras_proveedor`.
3. `producto_proveedor.codigo_proveedor` y el mapa Mamut, sin espacios, guiones ni puntos (`0617 401 100` = `0617401100`, `000139` = `000-139`).
4. `canonical_sku`.
5. Si nada calza: nombre, por palabras. Estos resultados nunca se abren solos.

En códigos de proveedor no se quitan ceros a la izquierda: `000-139` no es el SKU 139. No se copia el código de proveedor a `codigo_barra` para “hacerlo cómodo”. Si el resultado es la contraparte online de un producto Mamut, se usa el producto local.

Ya existían dos buscadores parecidos (`Riverso_Barcode_Model` y `Riverso_Product_Quick_View_Service`). El segundo quita ceros a los códigos de proveedor. Queda pendiente unificarlos con el de avisos.

**D4. El proveedor del aviso sale del match, y se puede corregir.** Un solo `producto_proveedor` activo: el aviso queda en ese proveedor. Varios: queda el que calzó por código, o el preferido, y la persona puede cambiarlo. Sin vínculo: se propone el proveedor del último aviso de ese producto; si no hubo, “No sé”, o el que elija. Las marcas no son el proveedor (Skairos y Asgard se piden a Steelfix), y una caja Steelfix puede terminar en el grupo de Mamut.

**D5. La cámara usa el mismo lector en Android y en iPhone.** `BarcodeDetector` cuando el teléfono lo trae. Si no (Safari), zxing-wasm servido desde el plugin, con búsqueda exhaustiva y rotación. Solo formatos de producto (EAN, UPC, Code128, Code39, ITF): sin QR. No se usa ZXing-JS. El portal ya es HTTPS, así que `getUserMedia` puede pedir la cámara. El lector es un componente compartido (`assets/js/barcode-scanner.js`): Avisos lo usa en su propia pantalla, y Cotizaciones y Facturación lo abren como diálogo desde el botón de cámara de su buscador, donde el código leído entra igual que con la pistola.

**D6. Un aviso sin match igual se guarda.** Foto y texto. “Sin identificar” no es un estado: es un aviso abierto sin producto, y se puede ingresar al pedido mirando la foto. Compras lo puede identificar después. Dar de alta el código que faltaba desde ahí queda pendiente.

**D7. La foto es evidencia para quien arma el pedido.** No se vuelve a leer como fuente de verdad. Notas manuscritas no se interpretan: la cantidad y el “no queda” son campos.

**D8. “Ingresado” reemplaza el pulgar arriba.** Estados: `abierto` → `ingresado` o `descartado` (con motivo). `ingresado` significa “ya está en el pedido al proveedor”, se haya armado donde se haya armado. Queda quién y cuándo. Quien avisó lo ve en “Mis avisos”.

**D9. La cantidad lleva unidad.** Si se escribe cantidad, la unidad es obligatoria: “300” sin decir si son unidades o cajas es el error caro. Se propone `unidad_compra` del vínculo con el proveedor. La equivalencia en unidades se muestra solo si hay un factor mayor que 1 o si la barra leída dice cuántas trae la caja. `factor_conversion` nace en 1, así que un 1 no dice nada. El mismo código Steelfix aparece en la caja máster (3000) y en la caja chica (500).

**D10. El portal de pedidos de compra es otra fase, y no crea otro modelo de OC.** Cuando exista, el borrador de `ordenes_compra` por proveedor toma los avisos **abiertos**, y agregar un aviso al borrador es lo que lo marca `ingresado`. Los ya ingresados no se vuelven a pedir. El aviso ya tiene `orden_compra_id` y `orden_compra_item_id` para ese vínculo.

**D11. No se arma pedido desde el stock del sistema** hasta que los conteos de esos productos estén cerrados y alguien confíe en ellos. El stock mínimo que ya existe en bodega puede sugerir, no encargar. El estado de stock ya calcula qué productos tienen conteo reciente.

**D12. El conteo de familia no es un inventario nuevo.** Al identificar un producto con hermanos, el conteo actual de producto pregunta si se cuentan los de la familia en la misma pasada. Sigue siendo conteo, no aviso.

**D13. Quién hace qué.** Avisa quien tiene `riverso_report_shortage` o ya puede contar inventario: bodega, mesón, recepción, editor, compras. La bandeja es de quien tiene `riverso_manage_purchase_notices`: compras y administradores. Quien avisó puede retirar su propio aviso mientras esté abierto. `riverso_view_purchases` y `riverso_edit_purchases` ahora están declarados; el segundo también abre la entrada directa de stock, así que queda solo para administradores.

**D14. El corpus de WhatsApp no es el criterio de aceptación de la cámara.** Sirve para no regresionar. La aceptación es: en Android y en iPhone, con la cámara del portal, leer la barra de una caja Mamut y de un blister Wurth y abrir el producto correcto si el código está en la base.

**D15. Antes de avisar se muestra si ya está avisado o pedido.** Al identificar el producto: “Ya avisado por X hace N” con un botón para sumarse a ese aviso en vez de crear otro, y “Ya pedido hace N por Y” si se ingresó en los últimos 30 días.

**D16. Lo que identificó la máquina lo confirma la persona.** Después de leer o digitar un código se muestra el nombre del producto antes de enviar, con “No es este”. Con dos códigos distintos en el encuadre, la cámara pregunta cuál.

## Flujo de la pantalla

`/interno/avisos/`, pensada para el teléfono. Tres pestañas:

1. **Avisar.** Un campo (código, SKU o nombre), “Escanear código de barras” y “No tiene código”. Luego: producto, avisos previos, proveedor, “No queda nada”, cantidad con unidad (opcional), foto, nota.
2. **Mis avisos.** Lo que la persona avisó o apoyó y en qué quedó.
3. **Por ingresar** (compras). Agrupada por proveedor. Cada aviso con foto, autor, cantidad propuesta o confirmada. Acciones: ingresado (con deshacer), cantidad, proveedor, identificar, descartar. “Copiar lista” deja el pedido del proveedor en texto.

Contar lo que queda sigue en Bodega, en el conteo de producto que ya existe.

## Fases

1. **Aviso y bandeja por proveedor.** Hecho.
2. **Cámara de barras** en la misma pantalla. Hecho y probada en Android; falta el iPhone (D14).
3. **Conteo de familia** como paso extra del conteo de producto.
4. **Portal de pedidos de compra.** Pantalla, edición de líneas y borrador por proveedor desde avisos abiertos (D10). Antes: dejar una sola tabla de ítems (`orden_compra_items` y `ordenes_compra_items` conviven) y conectar la recepción. Envío al proveedor y precios quedan fuera hasta que la bandeja se use.
5. **Sugerir cantidad desde stock** solo para productos con conteo cerrado. Nunca encargar solo.
6. **OCR de código impreso**, solo si digitar seis dígitos resulta ser un problema.

## Fuera de alcance

- Leer letra manuscrita.
- Gemini u otro modelo de visión en el servidor.
- Pedido automático al portal del proveedor.
- Reemplazar el conteo por lugar que ya funciona.
- Dar por bueno el stock actual para comprar.

## Pendiente

- Probar la cámara en un iPhone de bodega (D14). En Android funciona.
- Cargar los vínculos de proveedor de Steelfix y Wurth. Mientras falten, esos avisos caen en “Sin proveedor” salvo que alguien lo elija; después el aviso siguiente del mismo producto lo propone solo.
- `tools/medir_codigos_avisos.py` (solo lectura, por SSH) sigue sin correrse: dice de qué producto cuelgan los `producto_proveedor` y qué columnas tiene `ordenes_compra`.
- Avisos sin conexión: hoy, si falla el envío, el formulario queda lleno y se reintenta a mano. No hay cola.
- Dar de alta el código faltante al identificar un aviso (D6).
- Unificar los buscadores de código (D3).
