# Plan — Pedidos de compra

Fecha: 2026-10-10. Construcción de la interfaz. El ciclo que debe cumplir está en `docs/plan-avisos-y-pedidos-compra.md` (pasos 1 a 6, D10, D11, D17–D22).

Revisado el mismo día contra el código (plugin 1.8.61): módulo de OC, cotizaciones recibidas, avisos, recepción, estado de stock y emparejamientos.

## Dónde vive

En el portal interno, grupo **Compras**, ítem **Pedidos de compra**.

- URL: `/interno/pedidos-compra/`
- Registro: `Riverso_POS_Nav_Registry`, grupo `compras`, `portal_slug` `pedidos-compra`, solo superficie portal.
- Queda el primero del grupo, antes de Bandeja, Facturas y Cotizaciones Proveedores. Es la pantalla de trabajo de compras; las otras siguen siendo las suyas.

Avisos de compra se queda en Bodega (`/interno/avisos/`). Ahí se avisa desde el teléfono. Acá se arma el pedido.

No se crea otro modelo. La pantalla escribe `ordenes_compra` y `ordenes_compra_items`, que ya usan el módulo de OC y el paso “cotización → OC”. La tabla `orden_compra_items` no la lee nadie: no se migra y no se escribe. Sí hacen falta columnas nuevas y una tabla de enlace con los documentos recibidos: están en **Datos**.

## Qué es la pantalla

Un pedido es lo que Riverso le pide a un proveedor. La ficha lo sigue hasta que la mercadería entra o el pedido se cancela. No es la cotización del proveedor, ni la factura, ni el stock.

Cumple, en la misma ficha, lo que el ciclo pide:

1. Armar el pedido a un proveedor, a partir de avisos y de alertas.
2. Recibir la cotización del proveedor aunque no calce, y decidir cada diferencia.
3. Mandarle al proveedor la revisión de precios si corresponde; si no, aceptar.
4. Dejar lo aceptado como lo que Recepción espera. Recepción suma stock; ventas lo bajan. Esta pantalla no mueve stock.
5. Un emparejado entra una sola vez, al proveedor que se elija entre los que lo distribuyen.
6. Si el pedido no cubre la necesidad, la necesidad vuelve a la bandeja.

## Quién entra

| Acción | Quién |
|---|---|
| Ver la lista y la ficha | `riverso_view_purchases` |
| Armar, editar líneas, enviar, cancelar | `riverso_manage_purchase_notices` |
| Decidir líneas de la cotización, marcar la revisión enviada | `riverso_edit_received_quotes` |
| Aprobar la cotización | `riverso_approve_received_quotes` |
| Avisar que falta | Sigue en Avisos. Esta pantalla no crea avisos |

Compras ya tiene los cuatro permisos.

`riverso_edit_purchases` no se usa. Hoy exige ese permiso el alta de una OC, y el mismo permiso abre la entrada directa de stock; compras no lo tiene y no debe ganarlo. Los endpoints del pedido aceptan `riverso_manage_purchase_notices` para crear y editar.

Los endpoints de la cotización que la ficha reutiliza (guardar decisión, guardar ítem, subir archivo) hoy piden `edit_posts`, que el rol Operador Compras no trae. Deben aceptar también `riverso_edit_received_quotes`.

## La página

Una lista y una ficha. La ficha tiene cuatro zonas, en el orden del ciclo. Las zonas de más adelante pueden verse vacías mientras no se construyan; el lugar ya está.

### Lista

Pedidos con número, proveedor, estado, fecha y cuántas líneas. Filtros: borrador, enviado, en cotización, en revisión, aceptado, en recepción, recibido, cancelado. Botón **Armar pedido**.

Un pedido nacido de una cotización que llegó sin pedido previo (el camino que ya existe en Cotizaciones Proveedores) aparece en la misma lista, marcado “sin pedido previo”. No es el camino principal. Ese camino tiene dos arreglos pendientes:

- Hoy copia `cotizacion_items.producto_id`, que es un ID de WooCommerce, en `ordenes_compra_items.producto_base_id`. Debe traducirlo (`sku_match` → `producto_base.canonical_sku`). Las OC ya creadas así se revisan antes de mostrarlas.
- Si la cotización ya está vinculada a un pedido, “Convertir a llegada esperada” no crea otra OC: actualiza ese pedido.

### Armar

Bandeja de necesidades abiertas, agrupada por proveedor. Dos orígenes, misma lista:

- **Aviso.** Abierto, con foto, autor y cantidad propuesta. Puede no tener producto.
- **Alerta.** Bajo inventario de un producto, una familia o un emparejamiento. Sin autor ni foto. Se recalcula: si el stock sube, desaparece. Las reglas están en **Alertas**.

Lo semi-automático es la propuesta: el sistema agrupa, elige el proveedor del aviso o el preferido, y copia la cantidad confirmada o la propuesta. Lo automático no pasa de ahí. La persona marca líneas, cambia cantidad, unidad o proveedor, parte un grupo o lo deja fuera, y confirma.

Reglas de la propuesta:

- **Una línea por producto** (o por emparejamiento), aunque haya varios avisos. Todos esos avisos apuntan a la misma línea.
- **La cantidad no se suma.** Dos personas que avisan lo mismo vieron la misma falta. Se propone la confirmada por compras; si no hay, la mayor. Las demás se muestran al lado.
- **Unidades distintas no se convierten solas.** Si un aviso dice cajas y otro unidades, se muestran las dos y la persona elige. Solo hay equivalencia cuando el factor es mayor que 1 (D9).
- **“Sin proveedor” no se confirma.** Primero se elige proveedor, línea por línea o para el grupo.
- **Un borrador abierto por proveedor.** Confirmar crea ese borrador o lo completa.

Confirmar marca cada aviso `ingresado`, con `orden_compra_id` y `orden_compra_item_id`. Se usa la operación `ingresar` del servicio de avisos, que ya exige que el aviso siga `abierto`: si otra persona lo ingresó entre medio, esa línea se rechaza y se avisa quién fue. Una alerta convertida deja de ofrecerse; no se convierte en aviso.

Quitar una línea del borrador devuelve sus avisos a `abierto` y vuelve a ofrecer la alerta.

### Ficha — Pedido

Líneas de lo que pedimos: producto o foto del aviso sin identificar, código de proveedor, cantidad, unidad, precio si ya se conoce. Se edita mientras está en borrador.

**Enviar** deja el pedido en texto, como hoy “Copiar lista” en Avisos: número de pedido, y por línea código de proveedor, descripción, cantidad y unidad. Se copia y se manda por el canal de siempre; el sistema no lo envía. Guarda `enviado_en` y pasa a enviado: a partir de ahí las líneas pedidas no se reescriben; lo que cambie queda en la cotización.

El número del pedido es un correlativo numérico corto. Se le pide al proveedor que lo cite en la factura: así la factura encuentra sola a su pedido (ver Recepción). Las OC antiguas conservan su número.

Una línea sin producto (aviso sin identificar) se puede pedir. Toma el producto cuando se calza con una línea de la cotización. Sin producto no se puede aceptar: Recepción no sabría qué recibir.

### Ficha — Cotización

La cotización recibida se adjunta a este pedido (`ordenes_compra.cotizacion_id`). Puede cargarse aquí o vincularse una que ya esté en Cotizaciones Proveedores.

**Versiones.** Una cotización puede tener varias versiones (`version_group_id`, `version_n`): el proveedor manda otra después de una revisión. El pedido queda atado al grupo y la ficha trabaja siempre contra la versión final. Cuando llega una versión nueva, el diff se rehace contra ella. Las líneas que habíamos pedido quitar y siguen viniendo se marcan de nuevo como quitar; el resto lo decide otra vez la auto-decisión que ya existe.

**Calce de líneas.** Cada línea del pedido se calza con una de la cotización, en este orden:

1. Código de proveedor, sin espacios, guiones ni puntos y sin quitar ceros (D3): el de `producto_proveedor` de la línea contra `cotizacion_items.codigo_proveedor`.
2. Producto: `cotizacion_items.sku_match` contra `producto_base.canonical_sku`. No se compara `cotizacion_items.producto_id` con `producto_base_id`: el primero es un ID de WooCommerce.
3. A mano.

El calce queda guardado en la línea del pedido (`cotizacion_item_id`).

**Diff y decisión.** Por línea, sin dar por igual lo que no lo es. Las decisiones son las que la cotización recibida ya guarda:

| Marca | Qué pasó | Qué se decide |
|---|---|---|
| Igual | Misma cantidad, el costo no sube | `accepted`. Lo hace sola la auto-decisión |
| Precio | El costo sube más que el umbral | `claim` (pedir el precio anterior) o `accepted_increase` (se acepta el alza) |
| Cantidad | Pidieron una, cotizan otra | Aceptar la cotizada, o pedir que la corrijan (decisión nueva) |
| Solo pedido | No vino en la cotización | Pedir que la agreguen (`add`), o no se compra |
| Solo cotización | Vino algo que no pedimos | Aceptar (crea la línea en el pedido, origen cotización) o `remove` |

Un costo que baja se acepta solo y se muestra. `modified` y `rejected` son valores antiguos de la cotización: no se usan.

Lo aceptado es lo que se espera recibir, con la cantidad y el costo aceptados. Una línea que no se compra no se borra del pedido: queda constancia.

### Ficha — Revisión de precios

Es el correo al proveedor que Cotizaciones Proveedores ya arma con “Preparar revisión”. Tiene tres partes: usar el precio anterior (`claim`), quitar (`remove`) y agregar (`add`). La ficha abre ese mismo borrador (`riverso_quote_claim_draft`). No se construye otro.

- **Cuándo corresponde.** Cuando alguna línea quedó en `claim`, `remove`, `add` o corregir cantidad. Si todas quedaron aceptadas, no se manda nada y la cotización se aprueba.
- **Umbral.** Ya existe: un alza mayor a $0,01 (`DELTA_SIGNIFICATIVO`) deja la línea en `claim`. La persona puede pasarla a `accepted_increase`. Subir ese umbral es decisión de compras.
- **Cantidades.** El correo no tiene hoy una parte para pedir que corrijan una cantidad. Se agrega.
- **Envío.** El correo se copia; el sistema no lo manda. Por eso **Marcar revisión enviada** guarda la fecha y pasa el pedido a “en revisión”.
- **Respuesta.** Si el proveedor manda una cotización nueva, entra como versión del mismo grupo y el pedido vuelve a “en cotización” contra ella. Si contesta sin documento, la persona corrige el costo de la línea o cambia la decisión.

Mientras quede una línea en `claim`, `add` o corregir cantidad, el pedido no pasa a aceptado: o se espera la respuesta, o la persona la resuelve (acepta el alza, o no se compra). Hoy Cotizaciones Proveedores deja aprobar con líneas en `claim` y las manda a la OC al precio reclamado; desde la ficha no.

El precio de venta no se revisa aquí. Se revisa al procesar el folio de la factura (“Procesar folio”), que Recepción ya abre.

### Ficha — Recepción

Lo aceptado es la llegada esperada. Esta zona lista, por línea, pedido / cotizado / aceptado / recibido.

Recepción trabaja por documento (factura o guía), no por pedido. El enlace es documento ↔ pedido, y se busca en este orden:

1. **Solo.** La factura trae una referencia a orden de compra (tipo 801) con el número del pedido. `factura_referencias` ya guarda `tipo_doc_ref` y `folio_ref`; los escaneos, `documento_referencias`.
2. **Propuesto.** Entra un documento de un proveedor que tiene pedidos aceptados sin recibir: se propone el pedido cuyos productos calzan y una persona confirma.
3. **A mano.** “Vincular documento” desde la ficha.

Mientras no haya documento, la zona dice “Esperando factura o guía” y no hay nada que recibir. Con documento, **Recibir** abre Recepción (`/interno/recepcion/`) en él. El stock sube allá.

Lo recibido no se digita en el pedido. Se recalcula desde `recepcion_lineas` de los documentos vinculados, por producto y en unidades base, contra lo aceptado por su factor. Se recalcula cada vez que Recepción recibe, anula o reclama en uno de esos documentos, y con eso el pedido pasa a recepción parcial o recibido. Guía y factura del mismo despacho no se suman dos veces: Recepción ya lo resuelve. Si un documento cubre dos pedidos con el mismo producto, se completa primero el más antiguo.

Ventas bajan el stock al emitir el documento; aquí no hay acción.

### Cuando el pedido no cubre la necesidad

- **Línea entera sin comprar** (se quita del borrador, el proveedor no la cotiza y no se pide agregar, queda en `remove`, o el pedido se cancela): sus avisos vuelven a `abierto` y la alerta se ofrece de nuevo. En la bandeja aparecen con la marca “no lo cotizó X”. Si es un emparejado, se proponen primero los otros proveedores que lo distribuyen.
- **Parte sin cubrir** (se acepta menos de lo pedido, o Recepción cierra el documento con faltante): el aviso no se reabre. La ficha ofrece **Pedir el resto**, que deja una línea nueva en el borrador del proveedor que se elija, apuntando a la línea original. El faltante de Recepción sigue además su reclamo al proveedor, como hoy.

### Alertas

Ya se calculan; esta pantalla las lee, no las vuelve a calcular.

| Nivel | De dónde sale |
|---|---|
| Producto | Estado de stock de Bodega: `alerta`, `critico`, `estado_confianza` |
| Emparejamiento | `list_stock_alerts()` del módulo de emparejamientos, con su propio mínimo |
| Familia | Stock en unidades de `compute_family_stock`. No tiene mínimo propio: se usa el del producto unitario |

- **Un solo nivel.** Una necesidad aparece en el nivel más alto que la contiene: emparejamiento, si no familia, si no producto. No aparece dos veces.
- **Dudosa.** Si `estado_confianza` no es `confiable`, la alerta se muestra dudosa y no trae cantidad (D11). En familia y emparejamiento basta que un miembro no sea confiable.
- **En camino.** No se ofrece mientras haya una línea abierta de pedido para ese producto, familia o emparejamiento, desde borrador hasta recepción parcial. Vuelve si la línea se cancela o no se compra, o si se recibe y el stock sigue bajo el mínimo. No se guarda nada por alerta.
- **Cantidad.** Solo existen mínimo y crítico; no hay un stock objetivo. Se propone la última cantidad comprada a ese proveedor, en su unidad de compra. Sin historia, va vacía. Siempre la confirma una persona.

### Emparejados y familias, en Armar y en la ficha

Una necesidad de emparejamiento o de familia es una fila, no una por miembro. Esto vale para avisos y para alertas, y no depende de confiar en el stock: un aviso sobre un producto emparejado ya se muestra como necesidad del grupo, con todos sus proveedores.

- **Candidatos.** Los `producto_proveedor` activos de todos los miembros del emparejamiento y de la familia. El buscador de proveedores de Avisos (`suppliers_for`) ya junta producto y familia; hay que sumarle los miembros del emparejamiento.
- **Elegir uno** fija el producto miembro, el código, la unidad y el factor de esa línea. Recepción recibe ese producto; el stock del grupo lo suma igual.
- **Cantidad.** La necesidad está en unidades base. La línea la pasa a la unidad de compra del vínculo elegido si su factor es mayor que 1; si no, la escribe la persona.
- **Una sola vez.** Si ya está en una línea abierta de cualquier pedido, no se ofrece para otro proveedor. Moverla la saca del borrador anterior. Si el pedido anterior ya se envió, no se mueve: se deja sin comprar allá y la necesidad vuelve a la bandeja.

## Datos

Columnas y tabla nuevas, en una fase del activador (`add_column_if_missing`). Los avisos no cambian: ya tienen `orden_compra_id` y `orden_compra_item_id`.

**`ordenes_compra`.** Hoy se crea en dos lugares con definiciones distintas: el activador (`proveedor_id` obligatorio, con `cotizacion_id`, `total`, `enviado_en`) y el módulo de OC (`proveedor_id` opcional, sin esas columnas). Queda una sola, la del activador. Un pedido siempre tiene proveedor.

| Columna | Para qué |
|---|---|
| `numero` | Correlativo numérico corto en los pedidos nuevos |
| `revision_enviada_en` | Cuándo se marcó enviada la revisión de precios |

**`ordenes_compra_items`.** Hoy tiene producto, vínculo de proveedor, descripción, cantidad, cantidad recibida, unidad y precio.

| Columna | Para qué |
|---|---|
| `codigo_proveedor`, `factor` | Copia del vínculo al momento de pedir. El vínculo puede cambiar después |
| `origen` | `aviso`, `alerta`, `manual`, `cotizacion`, `resto` |
| `origen_item_id` | La línea original, cuando es “Pedir el resto” |
| `emparejamiento_id`, `grupo_id` | La necesidad es del grupo o de la familia, no del miembro |
| `cotizacion_item_id` | Con qué línea de la cotización calzó |
| `cantidad_aceptada`, `precio_aceptado` | Lo que se espera recibir |
| `estado` | `pendiente`, `aceptada`, `no_comprada`, `cerrada` |

Lo cotizado no se copia: se lee de la línea de cotización calzada.

**`orden_compra_documentos`** (nueva). `orden_id`, `factura_id`, cómo se vinculó (`referencia`, `propuesto`, `manual`), quién y cuándo. Un pedido puede llegar en varias facturas y una factura puede cubrir dos pedidos.

**`cotizacion_items.decision_status`.** Un valor nuevo para “corregir cantidad”. La columna ya es texto.

## Estados

Se alargan los de `Riverso_Purchase_Order_Module`. No se crea una tabla paralela.

| Estado | Cuándo |
|---|---|
| `borrador` | Se está armando. Las líneas se editan |
| `enviada` | El pedido salió. Las líneas pedidas quedan fijas |
| `en_cotizacion` | Hay una cotización vinculada y quedan diferencias sin decidir, o una revisión por mandar |
| `en_revision` | La revisión de precios se mandó y se espera la respuesta |
| `aceptada` | La versión final está aprobada: toda línea quedó aceptada o sin comprar. Hay llegada esperada |
| `recibida_parcial` | Recepción recibió una parte |
| `recibida` | Recepción recibió o cerró todo lo aceptado |
| `cancelada` | No se compra. Los avisos de sus líneas vuelven a `abierto` |

A mano solo se hacen tres cosas: enviar, marcar la revisión enviada y cancelar. Cancelar se permite mientras no se haya recibido nada. El resto de los estados lo calcula una sola función a partir de las líneas, la cotización y lo recibido, y se llama después de cada cambio. `riverso_po_update_status` hoy acepta cualquier salto a cualquier estado: queda limitado a enviar y cancelar.

## Qué hay que construir

El módulo de OC hoy lista, crea, lee y cambia estado. No edita líneas, no toma avisos, no adjunta cotización, no sigue la revisión de precios y no tiene página.

1. **Ítem de menú y página vacía** en Compras, con el permiso de ver.
2. **Datos, lista y ficha del borrador.** Columnas y definición única de `ordenes_compra`, numeración corta, permisos de los endpoints, estados limitados. Crear por proveedor, editar y quitar líneas, enviar (texto), cancelar. Precio opcional.
3. **Armar desde avisos abiertos, con emparejados.** Propuesta por proveedor, una línea por producto o grupo, selector con todos los proveedores del emparejado. Confirmar marca `ingresado` y llena `orden_compra_id` / `orden_compra_item_id`. Deshacer devuelve el aviso.
4. **Cotización en la ficha.** Vincular, calzar líneas, mostrar el diff contra la versión final, guardar la decisión por línea. Arreglar el camino “cotización → OC” (ID de producto y OC duplicada).
5. **Revisión de precios.** Abrir el borrador existente desde la ficha, agregarle las cantidades, marcar enviada, recibir la versión nueva. Pasar a `aceptada` cuando no quede nada esperando al proveedor.
6. **Llegada y recepción.** Enlace documento ↔ pedido (referencia 801, propuesta, a mano), recalcular lo recibido, mover el estado. “Pedir el resto” y devolver a la bandeja lo que no se compró. No hay una segunda entrada de stock.
7. **Alertas en Armar.** Misma bandeja que los avisos, con las reglas de nivel, dudosa, en camino y cantidad.

El orden es el de construcción: 1–3 dejan de usar “Copiar lista” en Avisos para el pedido del día, y ya permiten mandar un emparejado a cualquiera de sus proveedores. 4–6 cierran el ciclo con la cotización, la revisión y la recepción. 7 mete el stock en la bandeja cuando el conteo de esos productos se pueda creer.

## Fuera de esta interfaz

- Crear el aviso o sacar la foto. Sigue en Avisos.
- Leer la cotización del proveedor (archivo, match de códigos). Sigue en Cotizaciones Proveedores; aquí se vincula y se decide contra el pedido.
- Sumar o bajar stock. Recepción y ventas.
- Revisar el precio de venta. Se hace al procesar el folio de la factura.
- Mandar el pedido o la revisión desde el sistema, por correo o al portal del proveedor. Se copian.
- Elegir solo, sin una persona, qué se compra o a quién.

## Por definir con compras

- Si el umbral de alza sigue en $0,01 o sube.
- Si la cantidad de una alerta sale de la última compra, o se quiere un stock objetivo por producto.
- Si la familia usa el mínimo de su producto unitario, o se le da uno propio.

## Aceptación

- Compras abre `/interno/pedidos-compra/` desde el grupo Compras y no ve entrada directa de stock por entrar ahí.
- Con avisos abiertos de un proveedor, Armar propone el borrador, una persona confirma, y en Avisos esos quedan `ingresado` apuntando a la línea.
- Dos avisos del mismo producto dan una línea, con una cantidad y no la suma.
- Un emparejado con dos proveedores aparece una vez. Elegir el segundo lo saca del borrador del primero.
- Una cotización con una cantidad distinta y un precio distinto no se guarda como igual. Cada diferencia tiene decisión.
- Un costo que no sube no pide revisión. Un alza deja la línea en `claim`: se manda la revisión, o se acepta el alza. Con una línea en `claim` el pedido no queda aceptado.
- Al llegar la versión nueva de la cotización, la ficha muestra el diff contra ella y el pedido vuelve a “en cotización”.
- Una línea que el proveedor no cotizó y no se pidió agregar devuelve su aviso a `abierto`.
- Una factura que cita el número del pedido queda vinculada sola. Recibirla en Recepción mueve lo recibido en la ficha y el stock sube una sola vez.
- Con un pedido abierto para un producto bajo el mínimo, su alerta no se ofrece. Si el pedido se cancela, vuelve.
