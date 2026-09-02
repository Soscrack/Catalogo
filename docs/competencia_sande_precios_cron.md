# Cron de precios Sande (competencia)

## Qué hace

Cada día a las **02:00 America/Santiago**:

1. Llama a la API pública `listarProductosT3` (sección TORNILLOS / marca MAMUT).
2. Actualiza el **precio vigente** en `{prefix}competencia_precios` (1 fila por producto).
3. Copia ese precio a `{prefix}competencia_precios_historial` con la fecha de **hoy**.
4. Borra snapshots diarios intermedios. Conserva siempre:
   - el snapshot de **hoy** (precio actual)
   - el **01 y el 16** de cada mes (histórico permanente)

Si el scrape falla, **no** escribe ni borra historial.

En la UI (Historial → Serie) se ve: precio de hoy + 01/16 de meses anteriores.

## Crontab en Plesk (servidor)

WP-Cron **no** sirve: no garantiza la hora.

El horario real está en **`/etc/cron.d/riverso-sande-precios`** con `CRON_TZ=America/Santiago` (`0 2 * * *` = 02:00 Chile, con horario de verano).

La tarea Plesk id **164** queda **inactiva** a propósito: el panel programa en UTC del servidor, y `0 2 * * *` corría a las **22:00 de Chile**. Sigue sirviendo para pulsar **Ejecutar ahora**.

- PHP: `/opt/plesk/php/8.4/bin/php`
- Script: `httpdocs/wp-content/plugins/riverso-pos/cli/sande-precios-refresh.php`
- Log: `httpdocs/wp-content/uploads/riverso-logs/sande-precios.log`

Para recrear el cron (idempotente vía SSH root):

### Cómo comprobar que corre bien

1. En Plesk, abre la tarea **Sande precios 02:00 America/Santiago** y pulsa **Run** / **Ejecutar ahora**.
2. Debe terminar en ~15–60 s (depende de la API Sande).
3. Revisa el log en el servidor:
   `httpdocs/wp-content/uploads/riverso-logs/sande-precios.log`
   - Si la API devuelve pocos productos: verás `abort` — es correcto (protección).
   - Si todo OK: `OK productos_api=… historial_affected=…`
4. En WP Admin → Competencia → Sande → **Historial**, la columna «Actualizado» debería ser de hoy.

**Nota:** no reactivar la tarea Plesk 164 con horario `0 2 * * *`: el servidor está en UTC y duplicaría/adelantaría la corrida.

Para recrearla (idempotente vía SSH root, sin usuario admin de Plesk):

```bash
python tools/create_sande_precios_cron.py
```

### Prueba manual

```bash
PHP_BIN=$(ls /opt/plesk/php/*/bin/php | sort -V | tail -1)

# Solo valida API + credenciales (no escribe BD)
"$PHP_BIN" .../cli/sande-precios-refresh.php --dry-run

# Corrida real
"$PHP_BIN" .../cli/sande-precios-refresh.php

# Forzar copia a historial (ya es el comportamiento diario; el flag queda por compatibilidad)
"$PHP_BIN" .../cli/sande-precios-refresh.php --force-historial
```

## Nota operativa (API)

`listarProductosT3` a veces responde HTTP 200 con `[]` vacío aunque `ListarCategoriasMaestroV3` sí funciona. El CLI **aborta** si hay menos de 100 productos para no pisar el vigente. Reintentar más tarde o probar:

```bash
"$PHP_BIN" .../cli/sande-precios-refresh.php --from-json=/ruta/productos_237184.json --force-historial
```

(`--from-json` es solo para pruebas / recuperación con cache del scraper.)


## Migración

Phase 40: `php/riverso-pos/migrations/phase40_competencia_precios_historial_v1.sql`

En producción (sin wp-load):

```bash
python tools/migrate_competencia_remote.py
```

Eso aplica phase39 (idempotente) + phase40 (unique 1:1 + tabla historial + seed).

## UI

WP Admin → Riverso POS → Competencia → Sande → **Historial**.

Muestra vigente, fecha de actualización, último snapshot (hoy) y el anterior conservado (01/16), más variación %. El botón **Serie** abre el modal con hoy + 01/16 de meses anteriores.
