# Emitir documento con pago inmediato y pagos mixtos sincronizados con FACTO

El botón «Emitir documento» de `https://riverso.cl/interno/facturacion/` abre un modal como el de FACTO: a la izquierda emisión y envío, a la derecha el pago. Cada pago se guarda en Riverso (caja y movimiento) y se envía a FACTO con `POST /payments`. Un documento puede tener varios pagos hasta llegar a monto impago $0.

## Lo que ya está probado contra FACTO (producción, 2026-10-05)

- `POST /v1/payments` con `payment_date`, `document_id`, `payment_type_id`, `payment_amount`, `payment_details`, `cash_account_id` responde `payment_id`. `GET /v1/payments/{id}` devuelve lo mismo.
- Boletas de $1 folios 79912–79915, un pago cada una (Efectivo, Débito, Crédito, Transferencia).
- Boleta de $4 folio 79916 con cuatro pagos de $1 (pago mixto). Los cuatro respondieron OK.
- No existen endpoints para listar cajas, tipos de pago ni pagos de un documento, ni para borrar un pago. Riverso es la fuente de verdad.
- El cobro (C#####) lo crea FACTO al emitir con `payment_conditions = "0"`.
- Configuración FACTO › Servicios API: «No generar borrador», «No marcar pagado el documento», mismo número de pedido «No».

## Decisiones tomadas

| Tema | Decisión |
| --- | --- |
| Pagos | Riverso guarda el pago y lo envía a FACTO con mapeo de IDs |
| «Cerrar y NO enviar al S.I.I.» | Cierre local en Riverso, sin folio ni llamada a FACTO |
| Correo | Lo envía Riverso con `wp_mail`, adjuntando PDF y XML de FACTO |
| Borrar un pago ya enviado | Se borra en Riverso, se revierte la caja y se crea una tarea para borrarlo a mano en FACTO |
| Alcance | Boleta y Factura |

## Mapeo de IDs FACTO

Cajas (`cash_account_id`): Efectivo 1, Tarjeta 2, Arca de Riverso 3, Arca Virtual 4, Transferencias 5, CHEQUE AL DIA 7.

Métodos (`payment_type_id`):

| Método | ID | Visible en modal | Pide datos de cheque | Permite vuelto |
| --- | --- | --- | --- | --- |
| Amipass | 13 | sí | | |
| Cheque a plazo | 3 | sí | sí | |
| Cheque al día | 2 | sí | sí | |
| Depósito bancario | 9 | sí | | |
| Edenred | 14 | sí | | |
| Efectivo | 1 | sí | | sí |
| Factura contra nota de crédito | 7 | no | | |
| GetNet | 19 | sí | | |
| Mercado Pago | 17 | sí | | |
| Nota de crédito | 6 | no | | |
| Otros documentos | 8 | sí | | |
| Pago automático API | 15 | no | | |
| Redelcom | 16 | sí | | |
| Sodexo | 12 | sí | | |
| Tarjeta de crédito | 4 | sí | | |
| Tarjeta de débito | 5 | sí | | |
| Transferencia electrónica bancaria | 10 | sí | | |
| WebPay | 18 | sí | | |

## Estado actual del código

- Boleta (tipo 37) usa borradores: `billing_drafts`, la pestaña «$ Pagos» y el modal «Registrar pago» ya existen en `templates/billing/app.php`. El botón `bill-boleta-emit` está deshabilitado como «Emitir documento [WIP]».
- `Riverso_Billing_Draft_Repository::save` fija `document_type_id` 37 y solo acepta los estados `draft` y `emitted`. `add_payment` exige `emitted` y no valida sobrepago.
- Factura (tipo 2) no usa borrador: `bill-emit` llama a `riverso_billing_emit` tras un `confirm()` y muestra `bill-done`. No tiene pestaña de pagos.
- `ajax_emit` no recibe `draft_id`, no liga el borrador con `dte_issued` ni marca el borrador como emitido.
- `ajax_draft_payment` valida caja abierta y permiso `pagar`, guarda el pago y llama a `register_payment_ingreso`. El método es texto libre (Efectivo, Mercado Pago, Transferencia, Tarjeta).
- `Riverso_Facto_Client` no tiene métodos de pagos; `request()` sirve para agregarlos.

## Fase 1 · Datos y mapeo

Nueva fase de schema en `includes/class-activator.php` (más `ensure_*` para deploy sin bump de versión):

1. Tabla `riverso_payment_methods`: `id`, `nombre`, `facto_payment_type_id`, `visible`, `requiere_cheque`, `permite_vuelto`, `orden`, `activo`. Seed con la tabla de arriba.
2. `riverso_cajas`: columna `facto_cash_account_id` (VARCHAR 16, NULL). Seed por nombre con los IDs de arriba.
3. `riverso_billing_draft_payments`:
   - `draft_id` pasa a NULL; nueva `dte_id` (BIGINT NULL, índice). Un pago pertenece a un borrador de boleta, a un DTE, o a ambos.
   - `method_id`, `amount_applied` (pagado − vuelto).
   - `cheque_numero`, `cheque_titular`, `cheque_banco`.
   - `facto_payment_id`, `facto_sync_status` (`off`, `pending`, `sending`, `ok`, `error`, `unknown`), `facto_sync_error`, `facto_synced_at`.
4. `riverso_billing_drafts`: columna `dte_id` y estado `closed_local` (además de `draft` y `emitted`).
5. Opción `riverso_facto_payments_sync` (1 por defecto, ya probado).

Código:

- `Riverso_Facto_Client::create_payment(array)` y `get_payment($id)`.
- `Riverso_Payment_Method_Repository` (listar visibles, obtener por id).
- En «Cuentas bancarias y efectivo» › Editar caja: campo «ID caja FACTO». Nueva sección «Métodos de pago» con ID FACTO, visible, cheque y vuelto.

## Fase 2 · Modal «Emitir documento»

Template nuevo en `templates/billing/app.php` (`bill-emit-modal`), compartido por Boleta y Factura. `bill-boleta-emit` se habilita y `bill-emit` deja de usar `confirm()`; los dos abren el modal.

Izquierda, «Emitir y enviar al S.I.I.»:

- Certificado: texto fijo «Certificado configurado en FACTO».
- «Enviar por correo» (checkbox), destinatario (email del cliente en factura, vacío en boleta), destinatarios extra separados por coma, adjuntos «PDF y XML». «Casilla de intercambio del cliente» como [WIP].
- «Impresión rápida» (checkbox): al terminar abre el PDF oficial para imprimir.
- «Enlace de pago»: [WIP].

Derecha, «PAGO DE DOCUMENTO»:

- «Marcar como pagado inmediatamente».
- Caja: cajas abiertas donde el usuario tiene `pagar` (`list_payable_open`).
- Método de pago: `payment_methods` visibles. Datos de cheque si el método los pide.
- Comentarios.

Botones:

- Verde «Emitir y enviar al S.I.I.» → `riverso_billing_emit`.
- Rojo «Cerrar y NO enviar al S.I.I.» → `riverso_billing_close_local` (solo Boleta en este corte, ver Riesgos).

`ajax_emit` recibe además `draft_id`, `mark_paid`, `pay_caja_id`, `pay_method_id`, `pay_notes`, cheque y correo. Orden:

1. Si `mark_paid`: validar caja abierta, permiso `pagar`, método visible y datos de cheque **antes** de emitir.
2. Emitir en FACTO (flujo actual, lock por cotización).
3. Insertar `dte_issued`. Si hay `draft_id`: borrador a `emitted` con `dte_id`, y los pagos `closed_local` previos del borrador pasan a `dte_id` y quedan `pending`.
4. Si `mark_paid`: pago por el total, `register_payment_ingreso` y sincronización (Fase 4).
5. Sincronizar los pagos `pending` del borrador.
6. Correo si se pidió (Fase 5).
7. Responder con el DTE y el resultado de cada paso. Un fallo en los pasos 4–6 no deshace la emisión: se informa y queda reintentable.

`riverso_billing_close_local`: guarda el borrador y lo pasa a `closed_local`. No llama a FACTO. Habilita la pestaña Pagos; esos pagos quedan `pending` hasta que el documento se emita.

## Fase 3 · Pestaña Pagos

En `renderPagosPanel` (`billing.js`), columnas como FACTO: Tipo, Fecha, Tipo, Caja, Doc Num, Doc Tit, Banco Doc, Detalles, Cobro/Pagos, Acciones.

- Fila COBRO: «Cobro contado», total del documento, botones «Pagar (saldo)» (si hay saldo) y «Borrar» [WIP].
- Fila PAGO por cada pago: fecha, método, caja, cheque, «Asociado a C…», `-monto aplicado`, icono de estado FACTO (ok / pendiente / error con «Reintentar» / desconocido), botón borrar.
- Totales: Total cobros, Total Pagos (rojo si $0), Monto impago.
- El panel se muestra en estado `emitted` y `closed_local`.

Factura: la pantalla `bill-done` muestra el mismo panel de pagos del DTE recién emitido (pagos por `dte_id`).

## Fase 4 · Registrar pago, pagos mixtos y sincronización

Modal «Registrar pago»:

- «Monto a pagar» = saldo pendiente (total − suma de `amount_applied`).
- Método desde `payment_methods`. Vuelto solo si `permite_vuelto`; en otros métodos «Monto pagado» no puede superar el saldo.
- Al guardar con saldo restante, el modal se vuelve a abrir con lo que falta.

Servidor (`ajax_draft_payment`, que pasa a aceptar `draft_id` o `dte_id`):

- Rechaza sobrepago: suma aplicada + nuevo aplicado ≤ total.
- Guarda el pago, `register_payment_ingreso` por `amount_applied`, y sincroniza si el documento tiene `facto_document_id`.

Servicio `Riverso_Billing_Payment_Sync::sync($payment_id)`:

1. Lock por pago (transient). Si ya tiene `facto_payment_id`, no hace nada.
2. Requiere `facto_cash_account_id` de la caja y `facto_payment_type_id` del método. Si falta: `error` con mensaje de mapeo.
3. Marca `sending` y llama `create_payment` con monto aplicado, fecha, detalle (método + notas + cheque).
4. Respuesta con `payment_id` → `ok`. Error HTTP claro → `error`. Timeout o respuesta sin cuerpo → `unknown` (puede haberse creado: no reintentar solo; tarea para revisar en FACTO).
5. Audit `billing.payment_facto_sync`. En `error`/`unknown`, tarea `sincronizar_pago_facto`.

AJAX `riverso_billing_payment_retry` para el botón «Reintentar» (solo `error`; `unknown` pide confirmación).

## Fase 5 · Correo, borrado y auditoría

- Correo: `wp_mail` a destinatario + extras, asunto «Boleta/Factura N° folio – Riverso», adjuntos PDF y XML decodificados de `electronic_document` de la respuesta de emisión. Si no vienen, `GET /documents/{id}`. Archivos temporales en uploads, se borran tras enviar. Audit `billing.dte_email`.
- Borrar pago (`riverso_billing_payment_delete`): permiso `borrar_pago` en la caja. Borra la fila, agrega movimiento inverso `reverso_pago` en la caja. Si tenía `facto_payment_id`: tarea `eliminar_pago_facto` con folio, `payment_id` y monto. Audit `billing.payment_deleted`.
- Tipos de tarea nuevos en `class-task-module.php`: `sincronizar_pago_facto`, `eliminar_pago_facto`.

## Archivos

- `includes/class-activator.php`: fase nueva + `ensure_*`.
- `modules/integrations/facto/class-facto-client.php`: `create_payment`, `get_payment`.
- `sales/billing/class-billing-module.php`: `ajax_emit` extendido, `ajax_close_local`, `ajax_draft_payment` generalizado, `ajax_payment_retry`, `ajax_payment_delete`, `ajax_payment_methods`, `app_config` con métodos.
- `sales/billing/class-billing-draft-repository.php`: estados, `dte_id`, pagos por DTE, validación de saldo.
- `sales/billing/class-billing-payment-sync.php` (nuevo).
- `sales/billing/class-payment-method-repository.php` (nuevo).
- `sales/cash/class-cash-repository.php` y `class-cash-module.php`: `facto_cash_account_id`, movimiento inverso, CRUD de métodos.
- `templates/billing/app.php`, `assets/js/billing.js`, `assets/css/billing.css`: modal de emisión, pestaña Pagos, modal Registrar pago.
- `templates/cash/accounts.php`, `assets/js/cash.js`: ID FACTO de caja y sección Métodos de pago.
- `core/tasks/class-task-module.php`: tipos de tarea.

## Riesgos

- Un pago creado directamente en la web de FACTO no aparece en Riverso.
- Factura no tiene borrador: «Cerrar y NO enviar» queda deshabilitado en Factura hasta que exista borrador de factura.
- Estado `unknown`: si FACTO creó el pago pero no respondió, un reintento lo duplica. Por eso no se reintenta solo.
- Las boletas de prueba 79912–79916 están en `dte_issued` con nota «PRUEBA»; sus pagos en FACTO no tienen fila en Riverso.

## Comprobación

Con una caja Efectivo y una Tarjeta abiertas:

1. Boleta de $1, modal con «Marcar como pagado» en Efectivo/Efectivo → folio, pago `ok` con `facto_payment_id`, movimiento en la caja, monto impago $0.
2. Boleta de $4 sin marcar pagado → monto impago $4. Pagar $1 Efectivo con $2 entregados (vuelto $1), luego $3 Débito → dos pagos `ok`, monto impago $0. En FACTO, pestaña Pagos con dos filas.
3. Intentar pagar $5 con débito en una boleta de $4 → rechazado.
4. Boleta «Cerrar y NO enviar», pagar $1, luego emitir → el pago pasa de `pending` a `ok`.
5. Caja sin `facto_cash_account_id` → pago `error` con mensaje de mapeo y «Reintentar» tras corregir.
6. Borrar un pago `ok` → movimiento inverso en la caja y tarea «Eliminar pago en FACTO».
7. Factura con pago inmediato → panel de pagos en `bill-done` con el pago `ok`.
8. Correo con PDF y XML llega al destinatario y a los extras.
