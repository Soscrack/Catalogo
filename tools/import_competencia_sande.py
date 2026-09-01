#!/usr/bin/env python3
"""
Importa JSONL de competencia Sande a la BD remota (staging riverso_competencia_*).

Requiere haber ejecutado antes:
  python tools/scrape_sande_catalog.py --seccion 237184 --marca 165

Uso:
  python tools/import_competencia_sande.py
  python tools/import_competencia_sande.py --dry-run
  python tools/import_competencia_sande.py --datadir data/competencia/sande --skip-media
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DATA = ROOT / "data" / "competencia" / "sande"
FUENTE_SLUG = "sande"


def load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


def read_jsonl(path: Path) -> list[dict]:
    if not path.is_file():
        return []
    rows = []
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line:
            continue
        rows.append(json.loads(line))
    return rows


def sql_val(value) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return repr(value)
    s = str(value).replace("\\", "\\\\").replace("'", "\\'")
    return f"'{s}'"


def run_ssh(ssh, cmd: str, timeout: int = 900) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def fetch_db_credentials(ssh, wp_path: str, php_bin: str) -> tuple[str, str, str, str, str]:
    del php_bin
    import re

    sftp = ssh.open_sftp()
    try:
        with sftp.open(f"{wp_path.rstrip('/')}/wp-config.php", "r") as handle:
            content = handle.read().decode("utf-8", errors="replace")
    finally:
        sftp.close()

    def grab(name: str) -> str:
        m = re.search(
            r"define\s*\(\s*['\"]" + re.escape(name) + r"['\"]\s*,\s*['\"]([^'\"]*)['\"]",
            content,
        )
        if not m:
            raise RuntimeError(f"No se encontró {name} en wp-config.php")
        return m.group(1)

    prefix_m = re.search(r"\$table_prefix\s*=\s*['\"]([^'\"]*)['\"]", content)
    if not prefix_m:
        raise RuntimeError("No se encontró table_prefix en wp-config.php")
    return grab("DB_HOST"), grab("DB_USER"), grab("DB_PASSWORD"), grab("DB_NAME"), prefix_m.group(1) + "riverso_"


def build_sql(datadir: Path, rprefix: str) -> str:
    categorias = read_jsonl(datadir / "categorias.jsonl")
    productos = read_jsonl(datadir / "productos.jsonl")
    precios = read_jsonl(datadir / "precios.jsonl")
    medios = read_jsonl(datadir / "medios.jsonl")
    puente = read_jsonl(datadir / "producto_medio.jsonl")
    atributos = read_jsonl(datadir / "atributos.jsonl")

    lines = ["SET NAMES utf8mb4;", "START TRANSACTION;"]

    lines.append(
        f"INSERT INTO `{rprefix}competencia_fuentes` (slug, nombre, base_url, activo) "
        f"VALUES ('sande', 'Sande Distribución Industrial', 'https://www.sande.cl/mcatalogo', 1) "
        "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), base_url=VALUES(base_url), activo=1;"
    )
    lines.append("SET @fuente_id = (SELECT id FROM `{p}competencia_fuentes` WHERE slug = 'sande' LIMIT 1);".format(p=rprefix))

    for c in categorias:
        lines.append(
            f"INSERT INTO `{rprefix}competencia_categorias` "
            "(fuente_id, id_division, id_seccion, id_categoria, nombre_division, nombre_seccion, nombre_categoria, link_imagen) "
            f"SELECT @fuente_id, {sql_val(c.get('id_division'))}, {sql_val(c.get('id_seccion'))}, "
            f"{sql_val(c.get('id_categoria'))}, {sql_val(c.get('nombre_division'))}, "
            f"{sql_val(c.get('nombre_seccion'))}, {sql_val(c.get('nombre_categoria'))}, "
            f"{sql_val(c.get('link_imagen'))} "
            "ON DUPLICATE KEY UPDATE "
            "nombre_division=VALUES(nombre_division), nombre_seccion=VALUES(nombre_seccion), "
            "nombre_categoria=VALUES(nombre_categoria), link_imagen=VALUES(link_imagen), "
            "updated_at=CURRENT_TIMESTAMP;"
        )

    for p in productos:
        ext_id = p.get("id_externo")
        lines.append(
            f"INSERT INTO `{rprefix}competencia_productos` "
            "(fuente_id, id_externo, codigo_externo, codigo_normalizado, nombre, slug, url_producto, marca, id_marca, "
            "fabricante, id_division, id_seccion, id_categoria, nombre_division, nombre_seccion, "
            "nombre_categoria, unidad_min_venta, tipo_unidad, peso, stock, situacion, imagen_principal, capturado_at) "
            f"SELECT @fuente_id, {sql_val(ext_id)}, {sql_val(p.get('codigo_externo'))}, "
            f"{sql_val(p.get('codigo_normalizado'))}, {sql_val(p.get('nombre'))}, {sql_val(p.get('slug'))}, "
            f"{sql_val(p.get('url_producto'))}, "
            f"{sql_val(p.get('marca'))}, {sql_val(p.get('id_marca'))}, {sql_val(p.get('fabricante'))}, "
            f"{sql_val(p.get('id_division'))}, {sql_val(p.get('id_seccion'))}, {sql_val(p.get('id_categoria'))}, "
            f"{sql_val(p.get('nombre_division'))}, {sql_val(p.get('nombre_seccion'))}, "
            f"{sql_val(p.get('nombre_categoria'))}, {sql_val(p.get('unidad_min_venta'))}, "
            f"{sql_val(p.get('tipo_unidad'))}, {sql_val(p.get('peso'))}, {sql_val(p.get('stock'))}, "
            f"{sql_val(p.get('situacion'))}, {sql_val(p.get('imagen_principal'))}, "
            f"{sql_val(p.get('capturado_at'))} "
            "ON DUPLICATE KEY UPDATE "
            "codigo_externo=VALUES(codigo_externo), codigo_normalizado=VALUES(codigo_normalizado), "
            "nombre=VALUES(nombre), slug=VALUES(slug), url_producto=VALUES(url_producto), "
            "marca=VALUES(marca), fabricante=VALUES(fabricante), "
            "id_division=VALUES(id_division), id_seccion=VALUES(id_seccion), id_categoria=VALUES(id_categoria), "
            "nombre_division=VALUES(nombre_division), nombre_seccion=VALUES(nombre_seccion), "
            "nombre_categoria=VALUES(nombre_categoria), unidad_min_venta=VALUES(unidad_min_venta), "
            "tipo_unidad=VALUES(tipo_unidad), peso=VALUES(peso), stock=VALUES(stock), situacion=VALUES(situacion), "
            "imagen_principal=VALUES(imagen_principal), capturado_at=VALUES(capturado_at), "
            "updated_at=CURRENT_TIMESTAMP;"
        )

    precio_by_ext = {str(x.get("id_externo")): x for x in precios}
    for ext_id, pr in precio_by_ext.items():
        lines.append(
            f"INSERT INTO `{rprefix}competencia_precios` "
            "(producto_id, snapshot_fecha, precio, precio_lista, precio_bruto_unitario, precio_bruto_total, "
            "cantidad_min, iva, costo, moneda, oculto, actualizado_at) "
            f"SELECT cp.id, {sql_val(pr.get('snapshot_fecha'))}, {sql_val(pr.get('precio'))}, "
            f"{sql_val(pr.get('precio_lista'))}, {sql_val(pr.get('precio_bruto_unitario'))}, "
            f"{sql_val(pr.get('precio_bruto_total'))}, {sql_val(pr.get('cantidad_min'))}, "
            f"{sql_val(pr.get('iva'))}, {sql_val(pr.get('costo'))}, "
            f"{sql_val(pr.get('moneda') or 'CLP')}, {sql_val(pr.get('oculto'))}, NOW() "
            f"FROM `{rprefix}competencia_productos` cp "
            f"INNER JOIN `{rprefix}competencia_fuentes` f ON f.id = cp.fuente_id AND f.slug = 'sande' "
            f"WHERE cp.id_externo = {sql_val(ext_id)} "
            "ON DUPLICATE KEY UPDATE "
            "snapshot_fecha=VALUES(snapshot_fecha), "
            "precio=VALUES(precio), precio_lista=VALUES(precio_lista), "
            "precio_bruto_unitario=VALUES(precio_bruto_unitario), "
            "precio_bruto_total=VALUES(precio_bruto_total), "
            "cantidad_min=VALUES(cantidad_min), iva=VALUES(iva), "
            "costo=VALUES(costo), moneda=VALUES(moneda), oculto=VALUES(oculto), "
            "actualizado_at=VALUES(actualizado_at);"
        )

    for m in medios:
        ruta = m.get("ruta_local") or ""
        if ruta and not ruta.startswith("riverso-competencia/"):
            ruta = f"riverso-competencia/{Path(ruta).name}"
        lines.append(
            f"INSERT INTO `{rprefix}competencia_medios` "
            "(sha256, tipo, subtipo, url_origen, ruta_local, bytes, mime) "
            f"VALUES ({sql_val(m.get('sha256'))}, {sql_val(m.get('tipo'))}, NULL, "
            f"{sql_val(m.get('url_origen'))}, {sql_val(ruta)}, {sql_val(m.get('bytes') or 0)}, "
            f"{sql_val(m.get('mime'))}) "
            "ON DUPLICATE KEY UPDATE "
            "url_origen=VALUES(url_origen), ruta_local=VALUES(ruta_local), "
            "bytes=VALUES(bytes), mime=VALUES(mime);"
        )

    for row in puente:
        ext_id = row.get("id_externo")
        lines.append(
            f"INSERT INTO `{rprefix}competencia_producto_medio` "
            "(producto_id, medio_id, es_principal, tipo_multimedia, subtipo) "
            f"SELECT cp.id, cm.id, {sql_val(row.get('es_principal'))}, "
            f"{sql_val(row.get('tipo_multimedia'))}, {sql_val(row.get('subtipo'))} "
            f"FROM `{rprefix}competencia_productos` cp "
            f"INNER JOIN `{rprefix}competencia_fuentes` f ON f.id = cp.fuente_id AND f.slug = 'sande' "
            f"INNER JOIN `{rprefix}competencia_medios` cm ON cm.sha256 = {sql_val(row.get('sha256'))} "
            f"WHERE cp.id_externo = {sql_val(ext_id)} "
            "ON DUPLICATE KEY UPDATE "
            "es_principal=VALUES(es_principal), tipo_multimedia=VALUES(tipo_multimedia), subtipo=VALUES(subtipo);"
        )

    for a in atributos:
        ext_id = a.get("id_externo")
        lines.append(
            f"INSERT INTO `{rprefix}competencia_atributos` "
            "(producto_id, titulo, valor, orden) "
            f"SELECT cp.id, {sql_val(a.get('titulo'))}, {sql_val(a.get('valor'))}, {sql_val(a.get('orden') or 0)} "
            f"FROM `{rprefix}competencia_productos` cp "
            f"INNER JOIN `{rprefix}competencia_fuentes` f ON f.id = cp.fuente_id AND f.slug = 'sande' "
            f"WHERE cp.id_externo = {sql_val(ext_id)} "
            "ON DUPLICATE KEY UPDATE valor=VALUES(valor), orden=VALUES(orden);"
        )

    lines.append("COMMIT;")
    return "\n".join(lines) + "\n"


def upload_media(ssh, datadir: Path, wp_path: str) -> int:
    media_dir = datadir / "media"
    if not media_dir.is_dir():
        return 0
    remote_base = f"{wp_path}/wp-content/uploads/riverso-competencia"
    run_ssh(ssh, f"mkdir -p '{remote_base}'", timeout=30)
    sftp = ssh.open_sftp()
    files = [p for p in media_dir.iterdir() if p.is_file()]
    print(f"Medios locales: {len(files)}", flush=True)
    uploaded = 0
    skipped = 0
    for i, local in enumerate(files, 1):
        remote = f"{remote_base}/{local.name}"
        try:
            sftp.stat(remote)
            skipped += 1
        except OSError:
            sftp.put(str(local), remote)
            uploaded += 1
        if i % 50 == 0 or i == len(files):
            print(f"  sftp: {i}/{len(files)} (nuevos={uploaded}, existentes={skipped})", flush=True)
    sftp.close()
    return uploaded


def main() -> int:
    parser = argparse.ArgumentParser(description="Import competencia Sande a BD remota")
    parser.add_argument("--datadir", type=Path, default=DEFAULT_DATA)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--skip-media", action="store_true")
    args = parser.parse_args()

    datadir = args.datadir.resolve()
    manifest = datadir / "manifest.json"
    if not manifest.is_file():
        print(f"No existe {manifest}. Ejecuta scrape_sande_catalog.py primero.")
        return 1

    meta = json.loads(manifest.read_text(encoding="utf-8"))
    print(f"Manifest: {meta.get('productos', 0)} productos, {meta.get('medios_unicos', 0)} medios", flush=True)

    load_env_file(ROOT / ".env.deploy")
    host = os.environ.get("RIVERSO_DEPLOY_HOST", "72.61.37.37")
    user = os.environ.get("RIVERSO_DEPLOY_USER", "root")
    password = os.environ.get("RIVERSO_DEPLOY_PASSWORD")
    wp_path = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

    if not password:
        print("Falta RIVERSO_DEPLOY_PASSWORD en .env.deploy")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Conectando a {host}...", flush=True)
    ssh.connect(host, username=user, password=password, timeout=30)

    php_bin = "$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)"
    dbhost, dbuser, dbpass, dbname, rprefix = fetch_db_credentials(ssh, wp_path, php_bin)
    print(f"Prefijo tablas: {rprefix}", flush=True)
    print("Generando SQL...", flush=True)

    sql_body = build_sql(datadir, rprefix)
    print(f"SQL generado: {len(sql_body)} bytes", flush=True)
    if args.dry_run:
        print(f"SQL generado: {len(sql_body)} bytes (dry-run, no se sube)")
        ssh.close()
        return 0

    if not args.skip_media:
        n = upload_media(ssh, datadir, wp_path)
        print(f"Medios subidos: {n}")

    with tempfile.NamedTemporaryFile("w", suffix=".sql", delete=False, encoding="utf-8") as tmp:
        tmp.write(sql_body)
        local_sql = tmp.name

    remote_sql = "/tmp/competencia_sande_import.sql"
    sftp = ssh.open_sftp()
    print("Subiendo SQL...")
    sftp.put(local_sql, remote_sql)
    sftp.close()
    os.unlink(local_sql)

    dbhost_only = dbhost.split(":")[0]
    dbport = dbhost.split(":")[1] if ":" in dbhost else "3306"
    dbpass_shell = dbpass.replace("'", "'\\''")
    mysql_cmd = (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' '{dbname}' "
        f"< '{remote_sql}' && rm -f '{remote_sql}' && echo 'mysql-ok'"
    )
    print("Ejecutando import MySQL...")
    code, out, err = run_ssh(ssh, mysql_cmd, timeout=1800)
    print(out.strip())
    if code != 0:
        print("stderr:", err[:2000])
        ssh.close()
        return code

    count_cmd = (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' '{dbname}' -N -e "
        f"\"SELECT COUNT(*) FROM `{rprefix}competencia_productos` p "
        f"INNER JOIN `{rprefix}competencia_fuentes` f ON f.id=p.fuente_id WHERE f.slug='sande';\""
    )
    _, count, _ = run_ssh(ssh, count_cmd, timeout=30)
    print(f"Productos competencia Sande en BD: {count.strip()}")
    ssh.close()
    print("Import completado.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
