"""Medición SOLO LECTURA en producción para docs/plan-avisos-y-pedidos-compra.md.

Responde cuántos códigos del corpus de fotos identifican un producto, de qué producto
cuelgan los producto_proveedor y qué columnas tiene ordenes_compra.

Todo corre dentro de START TRANSACTION READ ONLY: cualquier escritura falla.
Las credenciales de la base se leen de wp-config.php en el servidor y no se imprimen.
Uso: python tools/medir_codigos_avisos.py
"""
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent
for raw in (ROOT / ".env.deploy").read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

EANS = """7809831014728 7809831016098 7809831018092 7809831018184 7809831018696 7809831033644
7809831065577 7809831074760 7809831148638 7809831157579 7809831191641 7809831196165
4061975976819 4065746582193 4066443004025 4066443037122 7891799440688
2000226001001 2000279001003""".split()

MAMUT = "01TRN 14CCM 29RLBC 14RTP 50ATPFG 17RTPA 01TMPA 14RLPR 01TADB 02TADB 45ATPFG 06MSA 17TMPA 08TFC 54TMPA 12TMPA F40ATPFG 202TSN 80ATPFG".split()
STEELFIX = "000139 211119 000266 111176 000138 111115 111110 100321 100420 002347 312155 313115 313125 100627 100444".split()
WURTH = "0672200040 5986000029 5986212181 0577116040 0577116120 0617401100".split()
HAND = "24574 24721 24722 24986 24987 22304 20552 24761 21274 279 226".split()


def q(values):
    return ",".join("'" + v + "'" for v in values)


NORM_PP = "UPPER(REPLACE(REPLACE(REPLACE(pp.codigo_proveedor,' ',''),'-',''),'.',''))"
NORM_CB = "UPPER(REPLACE(REPLACE(REPLACE(cb.codigo,' ',''),'-',''),'.',''))"
ALL_CODES = MAMUT + STEELFIX + WURTH
STRIPPED = sorted({c.lstrip("0") for c in STEELFIX + WURTH if c.startswith("0")})

SQL = f"""
START TRANSACTION READ ONLY;

SELECT '== Q0 tamaños ==' AS '';
SELECT COUNT(*) producto_base_activos, SUM(canonical_sku REGEXP '^[0-9]+$') sku_numerico, SUM(canonical_sku IS NULL OR TRIM(canonical_sku)='') sin_sku FROM @@P@@producto_base WHERE estado='activo';
SELECT COUNT(*) codigo_barra FROM @@P@@codigo_barra;
SELECT COUNT(*) producto_proveedor, SUM(activo=1) activos, SUM(activo=1 AND producto_base_id IS NULL) activos_sin_producto, SUM(activo=1 AND grupo_id IS NOT NULL) con_grupo FROM @@P@@producto_proveedor;

SELECT '== Q1 codigo_barra por tipo/estado ==' AS '';
SELECT tipo, estado, activo, COUNT(*) n, COUNT(DISTINCT producto_base_id) productos FROM @@P@@codigo_barra GROUP BY tipo, estado, activo ORDER BY n DESC;

SELECT '== Q2 proveedores con mas codigos (forma del codigo) ==' AS '';
SELECT pp.proveedor_id id, LEFT(p.nombre,34) nombre, COUNT(*) pp, SUM(pp.activo=1) act,
  SUM(pp.codigo_barras_proveedor IS NOT NULL AND pp.codigo_barras_proveedor<>'') con_barra,
  SUM(pp.unidad_compra IS NOT NULL AND pp.unidad_compra<>'') con_unidad,
  SUM(pp.factor_conversion IS NOT NULL AND pp.factor_conversion<>1) factor_no1,
  SUM(pp.es_preferido=1) pref,
  SUM(pp.codigo_proveedor LIKE '%-%') guion, SUM(pp.codigo_proveedor LIKE '% %') espacio,
  SUM(pp.codigo_proveedor REGEXP '^[0-9]+$') solo_dig, SUM(pp.codigo_proveedor REGEXP '^0') cero_izq,
  MIN(pp.codigo_proveedor) ej_min, MAX(pp.codigo_proveedor) ej_max
FROM @@P@@producto_proveedor pp LEFT JOIN @@P@@proveedores p ON p.id=pp.proveedor_id
GROUP BY pp.proveedor_id ORDER BY pp DESC LIMIT 14;

SELECT '== Q2b proveedores por nombre ==' AS '';
SELECT id, LEFT(nombre,50) nombre, activo FROM @@P@@proveedores WHERE LOWER(nombre) REGEXP 'mamut|fijacion|steel|rth|skairos|asgard' ORDER BY id;

SELECT '== Q3a EAN del corpus en codigo_barra ==' AS '';
SELECT cb.codigo, cb.tipo, cb.estado, cb.activo act, cb.conflicto conf, cb.proveedor_id prov, cb.cantidad cant, pb.canonical_sku sku, LEFT(pb.nombre_canonico,44) nombre
FROM @@P@@codigo_barra cb LEFT JOIN @@P@@producto_base pb ON pb.id=cb.producto_base_id
WHERE cb.codigo IN ({q(EANS)}) ORDER BY cb.codigo, cb.estado;

SELECT '== Q3b EAN del corpus en producto_proveedor.codigo_barras_proveedor ==' AS '';
SELECT pp.codigo_barras_proveedor, pp.proveedor_id prov, pp.codigo_proveedor, pp.activo act, pb.canonical_sku sku
FROM @@P@@producto_proveedor pp LEFT JOIN @@P@@producto_base pb ON pb.id=pp.producto_base_id
WHERE pp.codigo_barras_proveedor IN ({q(EANS)}) ORDER BY 1;

SELECT '== Q3c EAN del corpus en tablas legacy ==' AS '';
SELECT 'tienda_local_barcodes' src, b.barcode, b.sku FROM @@P@@tienda_local_barcodes b WHERE b.barcode IN ({q(EANS)}) ORDER BY 2;
SELECT 'barcodes' src, b.barcode, b.sku, b.is_active FROM @@P@@barcodes b WHERE b.barcode IN ({q(EANS)}) ORDER BY 2;

SELECT '== Q4a codigos impresos del corpus en producto_proveedor (normalizado) ==' AS '';
SELECT pp.codigo_proveedor crudo, pp.proveedor_id prov, pp.activo act, pp.es_preferido pref, pp.unidad_compra uc, pp.factor_conversion factor,
       pp.producto_base_id pb_id, pp.grupo_id grupo, pb.canonical_sku sku, LEFT(pb.nombre_canonico,40) nombre
FROM @@P@@producto_proveedor pp LEFT JOIN @@P@@producto_base pb ON pb.id=pp.producto_base_id
WHERE {NORM_PP} IN ({q(ALL_CODES)}) OR TRIM(LEADING '0' FROM {NORM_PP}) IN ({q(STRIPPED)})
ORDER BY pp.proveedor_id, pp.codigo_proveedor LIMIT 120;

SELECT '== Q4b codigos impresos guardados como barra (atajo viejo) ==' AS '';
SELECT cb.codigo, cb.tipo, cb.estado, cb.activo act, cb.proveedor_id prov, pb.canonical_sku sku, LEFT(pb.nombre_canonico,40) nombre
FROM @@P@@codigo_barra cb LEFT JOIN @@P@@producto_base pb ON pb.id=cb.producto_base_id
WHERE {NORM_CB} IN ({q(ALL_CODES)}) ORDER BY cb.codigo LIMIT 80;

SELECT '== Q4b2 codigos impresos en la tabla de barras antigua ==' AS '';
SELECT b.barcode, b.sku FROM @@P@@tienda_local_barcodes b
WHERE UPPER(REPLACE(REPLACE(REPLACE(b.barcode,' ',''),'-',''),'.','')) IN ({q(ALL_CODES)}) ORDER BY 1 LIMIT 80;

SELECT '== Q4c codigos impresos como canonical_sku o dentro del nombre ==' AS '';
SELECT pb.id, pb.canonical_sku sku, pb.estado, LEFT(pb.nombre_canonico,60) nombre
FROM @@P@@producto_base pb
WHERE UPPER(REPLACE(pb.canonical_sku,'-','')) IN ({q(ALL_CODES)})
   OR pb.nombre_canonico REGEXP '\\\\(({"|".join(["01TRN","14CCM","29RLBC","14RTP","50ATPF-G","17RTPA","01TMPA","14RLPR","01TADB","02TADB","45ATPF-G","06MSA","17TMPA","08TFC","54TMPA","12TMPA","F40ATPF-G","202TSN","80ATPF-G"])})\\\\)'
ORDER BY pb.canonical_sku LIMIT 80;

SELECT '== Q5 numeros manuscritos como SKU local ==' AS '';
SELECT canonical_sku sku, estado, LEFT(nombre_canonico,60) nombre FROM @@P@@producto_base WHERE canonical_sku IN ({q(HAND)}) ORDER BY 1;

SELECT '== Q6 proveedores activos por producto con SKU numerico (D4) ==' AS '';
SELECT n_prov, COUNT(*) productos FROM (
  SELECT pb.id, COUNT(DISTINCT pp.proveedor_id) n_prov
  FROM @@P@@producto_base pb LEFT JOIN @@P@@producto_proveedor pp ON pp.producto_base_id=pb.id AND pp.activo=1
  WHERE pb.estado='activo' AND pb.canonical_sku REGEXP '^[0-9]+$' GROUP BY pb.id) x
GROUP BY n_prov ORDER BY n_prov;

SELECT '== Q6b a que lado cuelgan los producto_proveedor activos ==' AS '';
SELECT CASE WHEN pb.id IS NULL THEN 'sin producto' WHEN pb.canonical_sku REGEXP '^[0-9]+$' THEN 'sku numerico (local)' WHEN pb.canonical_sku IS NULL OR TRIM(pb.canonical_sku)='' THEN 'sin sku' ELSE 'sku no numerico (online/proveedor)' END lado,
       COUNT(*) n
FROM @@P@@producto_proveedor pp LEFT JOIN @@P@@producto_base pb ON pb.id=pp.producto_base_id WHERE pp.activo=1 GROUP BY 1 ORDER BY n DESC;

SELECT '== Q7 ordenes de compra ==' AS '';
SHOW COLUMNS FROM @@P@@ordenes_compra;
SELECT estado, COUNT(*) n, MIN(created_at) desde, MAX(created_at) hasta FROM @@P@@ordenes_compra GROUP BY estado;
SELECT 'ordenes_compra_items' t, COUNT(*) n FROM @@P@@ordenes_compra_items;
SELECT 'orden_compra_items' t, COUNT(*) n FROM @@P@@orden_compra_items;

SELECT '== Q8 conteos ==' AS '';
SELECT tipo_conteo, estado, COUNT(*) n, MAX(cerrado_en) ultimo FROM @@P@@conteos GROUP BY 1,2 ORDER BY 1,2;
SELECT COUNT(DISTINCT producto_base_id) productos_con_conteo_producto_cerrado FROM @@P@@conteos WHERE estado='cerrado' AND tipo_conteo='producto';
"""

REMOTE = f"""
CFG="{WP}/wp-config.php"
val() {{ grep -oP "$1['\\"]\\s*,\\s*['\\"]\\K[^'\\"]+" "$CFG" | head -1; }}
DBN=$(val DB_NAME); DBU=$(val DB_USER); DBP=$(val DB_PASSWORD); DBH=$(val DB_HOST)
PFX=$(grep -oP "^\\s*\\\\\\$table_prefix\\s*=\\s*['\\"]\\K[^'\\"]+" "$CFG" | head -1)
[ -n "$DBN" ] && [ -n "$PFX" ] || {{ echo "no pude leer wp-config"; exit 2; }}
sed "s/@@P@@/${{PFX}}riverso_/g" | MYSQL_PWD="$DBP" mysql -u"$DBU" -h"${{DBH%%:*}}" "$DBN" --force -t 2>&1
"""

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
kwargs = {"hostname": os.environ["RIVERSO_DEPLOY_HOST"], "username": os.environ["RIVERSO_DEPLOY_USER"], "timeout": 30}
if os.environ.get("RIVERSO_DEPLOY_PASSWORD"):
    kwargs["password"] = os.environ["RIVERSO_DEPLOY_PASSWORD"]
else:
    kwargs["key_filename"] = os.path.expanduser("~/.ssh/id_ed25519")
client.connect(**kwargs)
stdin, stdout, stderr = client.exec_command(REMOTE, timeout=180)
stdin.write(SQL)
stdin.channel.shutdown_write()
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace").strip()
if err:
    print("STDERR:", err[:1500])
client.close()
