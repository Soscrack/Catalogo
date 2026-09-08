# Cron de precios DIMAFI

Refresco diario automático de precios del catálogo [DIMAFI](https://www.dimafi.cl) (Shopify).

---

## Archivos involucrados

| Archivo | Descripción |
|---|---|
| `php/riverso-pos/cli/dimafi-precios-refresh.php` | Script PHP CLI principal |
| `php/riverso-pos/cli/run-dimafi-precios-cron.sh` | Wrapper bash (rutas chroot Plesk) |
| `tools/create_dimafi_precios_cron.py` | Instalador remoto del cron via SSH |
| `/etc/cron.d/riverso-dimafi-precios` *(servidor)* | Entrada de cron |

---

## Diferencia con el cron de Sande

Sande usa una API JSON privada (`listarProductosT3`).  
DIMAFI es Shopify y sus precios están en el endpoint público paginado:

```
GET https://www.dimafi.cl/collections/all/products.json?limit=250&page=N
```

El script PHP pagina este endpoint hasta obtener una lista vacía (máx. 30 páginas),
aplana todas las **variantes** y ejecuta la misma lógica de vigente + historial + prune.

---

## Lógica de precios

| Campo Shopify | Campo BD | Cálculo |
|---|---|---|
| `variant.price` (string CLP) | `precio` | cast a float |
| `variant.price * 1.19` | `precio_bruto_unitario` | round(6 decimales) |
| `variant.price * qty * 1.19` | `precio_bruto_total` | round entero |
| `variant.compare_at_price` | `precio_lista` | null si ausente |
| título del producto | `cantidad_min` | regex `(N UDS)` → N; default 1 |

Shopify devuelve el precio como **string CLP entero** (sin centavos), p. ej. `"14903"` = $14.903.

---

## Flujo de ejecución

```
02:00 America/Santiago
└── /etc/cron.d/riverso-dimafi-precios
    └── run-dimafi-precios-cron.sh
        └── dimafi-precios-refresh.php
            1. find_wp_config() → credenciales BD
            2. paginar /collections/all/products.json  (delay 150 ms entre páginas)
            3. abort-guard: < 50 variantes → exit(1)
            4. UPSERT competencia_precios (slug=dimafi)
            5. INSERT/UPDATE competencia_precios_historial (fecha=hoy)
            6. DELETE prune: borra snapshot_fecha ≠ hoy  AND  DAY ∉ {1,16}
            7. log resultado en riverso-logs/dimafi-precios.log
```

---

## Historial: política de conservación

- **Todos los días**: se guarda un snapshot de hoy en `competencia_precios_historial`.
- **Al hacer prune**: se borran los días intermedios que **no sean el 1 ni el 16** del mes.
- Resultado: se conservan siempre el precio del día 1 y del día 16 de cada mes, más el de hoy.

---

## Abort-guard

Si Shopify devuelve **< 50 variantes** en total (respuesta inesperadamente pequeña,
posible error o bloqueo), el script **sale sin tocar la BD** y registra el error en el log.

---

## Configuración del servidor

| Parámetro | Valor |
|---|---|
| Horario | `0 2 * * *` con `CRON_TZ=America/Santiago` |
| PHP | `/opt/plesk/php/8.4/bin/php` |
| Usuario | `riverso.cl_1xybiw6rlcq` |
| Log | `httpdocs/wp-content/uploads/riverso-logs/dimafi-precios.log` |

---

## Instalación / re-instalación

```bash
# Desde la máquina local (requiere .env.deploy con credenciales SSH)
python tools/create_dimafi_precios_cron.py
```

Esto:
1. Crea el directorio de logs si no existe.
2. Escribe `/etc/cron.d/riverso-dimafi-precios`.
3. Desactiva la tarea Plesk duplicada (si existe) para evitar doble ejecución.

---

## Deploy del script PHP

```bash
python deploy_plugin.py
```

Empaqueta y sube `riverso-pos` completo (incluye el nuevo `dimafi-precios-refresh.php`).

---

## Prueba manual

```bash
# Dry-run desde el servidor (imprime muestra, no escribe BD)
ssh root@<host> "sudo -u riverso.cl_1xybiw6rlcq /opt/plesk/php/8.4/bin/php \
  /var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/riverso-pos/cli/dimafi-precios-refresh.php \
  --dry-run"

# Verificar log
ssh root@<host> "tail -20 /var/www/vhosts/riverso.cl/httpdocs/wp-content/uploads/riverso-logs/dimafi-precios.log"
```

---

## Prerrequisitos BD

- **Phase 41** (`phase41_competencia_grupos_v1.sql`): agrega columnas `id_grupo_externo`, `descripcion` y seed de la fuente `dimafi`.
- **Phase 40** (cron Sande): crea `competencia_precios_historial` y la clave única en `competencia_precios`.

Si la migración no se ha corrido, el script aborta con:
```
ERROR: Fuente dimafi no existe; correr migración phase41 primero
```
