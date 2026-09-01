#!/usr/bin/env python3
"""Genera sugerencias de match competencia -> producto_base vía MySQL remoto (sin wp-load)."""
from __future__ import annotations

import json
import os
import re
import sys
import tempfile
from difflib import SequenceMatcher
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
SKU_MAP = ROOT / "data" / "sku_mapping.json"
PREFIXES = ("KB", "KF", "K", "I", "B", "R", "F")


def load_env() -> None:
    path = ROOT / ".env.deploy"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def run(ssh, cmd: str, timeout: int = 300) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def fetch_db(ssh, wp_path: str) -> tuple[str, str, str, str, str]:
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
            raise RuntimeError(f"No se encontró {name}")
        return m.group(1)

    prefix_m = re.search(r"\$table_prefix\s*=\s*['\"]([^'\"]*)['\"]", content)
    if not prefix_m:
        raise RuntimeError("No se encontró table_prefix")
    return grab("DB_HOST"), grab("DB_USER"), grab("DB_PASSWORD"), grab("DB_NAME"), prefix_m.group(1) + "riverso_"


def mysql_cmd(dbhost: str, dbuser: str, dbpass: str, dbname: str, sql: str) -> str:
    dbhost_only = dbhost.split(":")[0]
    dbport = dbhost.split(":")[1] if ":" in dbhost else "3306"
    dbpass_shell = dbpass.replace("'", "'\\''")
    sql_esc = sql.replace("'", "'\\''")
    return (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' "
        f"'{dbname}' --batch --raw -N -e '{sql_esc}'"
    )


def sql_val(value) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return repr(value)
    s = str(value).replace("\\", "\\\\").replace("'", "\\'")
    return f"'{s}'"


def norm_code(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def strip_prefix(code: str) -> str:
    for pref in PREFIXES:
        if code.startswith(pref) and len(code) > len(pref):
            return code[len(pref):]
    return code


def norm_name(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", str(value or "").lower())


def parse_tsv(text: str) -> list[list[str]]:
    rows = []
    for line in text.splitlines():
        if not line.strip():
            continue
        rows.append(line.split("\t"))
    return rows


def load_sku_map() -> dict[str, str]:
    if not SKU_MAP.is_file():
        return {}
    raw = json.loads(SKU_MAP.read_text(encoding="utf-8"))
    out = {}
    for online, local in raw.items():
        online = str(online).strip()
        local = str(local).strip()
        if online and local and online.upper() != local.upper():
            out[online] = local
            out[online.upper()] = local
    return out


def main() -> int:
    load_env()
    host = os.environ.get("RIVERSO_DEPLOY_HOST")
    password = os.environ.get("RIVERSO_DEPLOY_PASSWORD")
    wp = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
    if not host or not password:
        print("Falta .env.deploy", flush=True)
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Conectando a {host}...", flush=True)
    ssh.connect(host, username=os.environ.get("RIVERSO_DEPLOY_USER", "root"), password=password, timeout=30)
    dbhost, dbuser, dbpass, dbname, p = fetch_db(ssh, wp)
    print(f"Prefijo: {p}", flush=True)

    def q(sql: str, timeout: int = 180) -> str:
        code, out, err = run(ssh, mysql_cmd(dbhost, dbuser, dbpass, dbname, sql), timeout=timeout)
        if code != 0:
            raise RuntimeError(err or out or f"mysql exit {code}")
        return out

    counts = q(
        "SELECT 'productos', COUNT(*) FROM `{p}competencia_productos` UNION ALL "
        "SELECT 'precios', COUNT(*) FROM `{p}competencia_precios` UNION ALL "
        "SELECT 'medios', COUNT(*) FROM `{p}competencia_medios` UNION ALL "
        "SELECT 'atributos', COUNT(*) FROM `{p}competencia_atributos` UNION ALL "
        "SELECT 'match', COUNT(*) FROM `{p}competencia_match`;".format(p=p)
    )
    print("Conteos:", flush=True)
    print(counts, flush=True)

    comps = parse_tsv(q(
        f"SELECT cp.id, cp.codigo_externo, cp.codigo_normalizado, cp.nombre "
        f"FROM `{p}competencia_productos` cp "
        f"INNER JOIN `{p}competencia_fuentes` f ON f.id=cp.fuente_id AND f.slug='sande'"
    ))
    print(f"Productos Sande: {len(comps)}", flush=True)
    if not comps:
        ssh.close()
        return 1

    codes_rows = parse_tsv(q(
        f"SELECT pp.codigo_proveedor, pp.producto_base_id "
        f"FROM `{p}producto_proveedor` pp "
        f"INNER JOIN `{p}catalogos` c ON c.id=pp.catalogo_id AND c.alias='mamut' AND c.activo=1 "
        f"WHERE pp.producto_base_id IS NOT NULL AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor!=''"
    ))
    codes: dict[str, int] = {}
    for codigo, pb in codes_rows:
        n = norm_code(codigo)
        if n:
            codes[n] = int(pb)
    print(f"Códigos proveedor Mamut: {len(codes)}", flush=True)

    pb_rows = parse_tsv(q(
        f"SELECT id, canonical_sku, nombre_canonico FROM `{p}producto_base` "
        f"WHERE deleted_at IS NULL AND estado='activo'"
    ))
    pb_by_id = {int(r[0]): r for r in pb_rows}
    pb_by_sku = {str(r[1]).strip(): int(r[0]) for r in pb_rows if r[1]}
    print(f"Productos base activos: {len(pb_by_id)}", flush=True)

    sku_map = load_sku_map()
    suggested = []
    leftovers = []
    stats = {"codigo_exacto": 0, "codigo_prefijo": 0, "sku_mapping": 0, "similitud": 0, "sin_match": 0}

    for cid, ext, norm, nombre in comps:
        cid_i = int(cid)
        code = norm_code(norm or ext)
        if code and code in codes:
            suggested.append((cid_i, codes[code], "codigo_exacto", 100.0, "Match por codigo_proveedor exacto"))
            stats["codigo_exacto"] += 1
            continue
        stripped = strip_prefix(code) if code else ""
        if stripped and stripped != code and stripped in codes:
            suggested.append((cid_i, codes[stripped], "codigo_prefijo", 85.0, "Match tras quitar prefijo de envase"))
            stats["codigo_prefijo"] += 1
            continue
        mapped = sku_map.get(str(ext).strip()) or sku_map.get(str(ext).strip().upper()) or sku_map.get(code)
        if mapped and mapped in pb_by_sku:
            suggested.append(
                (cid_i, pb_by_sku[mapped], "sku_mapping", 80.0, f"sku_mapping.json -> SKU local {mapped}")
            )
            stats["sku_mapping"] += 1
            continue
        leftovers.append((cid_i, nombre))

    print(f"Sin código: {len(leftovers)}. Calculando similitud de nombre...", flush=True)
    pb_names = [(int(r[0]), norm_name(r[2] if len(r) > 2 else "")) for r in pb_rows]
    pb_names = [(i, n) for i, n in pb_names if n]

    for cid_i, nombre in leftovers:
        target = norm_name(nombre)
        if not target:
            stats["sin_match"] += 1
            suggested.append((cid_i, None, "sin_match", 0.0, "Sin candidato automático"))
            continue
        best_id = 0
        best = 0.0
        for pb_id, n in pb_names:
            ratio = SequenceMatcher(None, target, n).ratio() * 100.0
            if ratio > best:
                best = ratio
                best_id = pb_id
        if best_id and best >= 60:
            suggested.append((cid_i, best_id, "similitud", round(best, 2), "similar_text sobre nombre"))
            stats["similitud"] += 1
        else:
            suggested.append((cid_i, None, "sin_match", 0.0, "Sin candidato automático"))
            stats["sin_match"] += 1

    print("Stats:", json.dumps(stats), flush=True)

    lines = ["SET NAMES utf8mb4;", "START TRANSACTION;"]
    for cid_i, pb_id, metodo, score, nota in suggested:
        estado = "sugerido" if pb_id else "sin_match"
        lines.append(
            f"INSERT INTO `{p}competencia_match` "
            f"(producto_competencia_id, producto_base_id, metodo, score, estado, nota) "
            f"VALUES ({cid_i}, {sql_val(pb_id)}, {sql_val(metodo)}, {sql_val(score)}, "
            f"{sql_val(estado)}, {sql_val(nota)}) "
            "ON DUPLICATE KEY UPDATE "
            "producto_base_id=IF(estado='confirmado', producto_base_id, VALUES(producto_base_id)), "
            "metodo=IF(estado='confirmado', metodo, VALUES(metodo)), "
            "score=IF(estado='confirmado', score, VALUES(score)), "
            "estado=IF(estado='confirmado', estado, VALUES(estado)), "
            "nota=IF(estado='confirmado', nota, VALUES(nota));"
        )
    lines.append("COMMIT;")
    sql_body = "\n".join(lines) + "\n"
    print(f"SQL match: {len(suggested)} filas, {len(sql_body)} bytes", flush=True)

    with tempfile.NamedTemporaryFile("w", suffix=".sql", delete=False, encoding="utf-8") as tmp:
        tmp.write(sql_body)
        local_sql = tmp.name
    remote_sql = "/tmp/competencia_match_suggest.sql"
    sftp = ssh.open_sftp()
    sftp.put(local_sql, remote_sql)
    sftp.close()
    os.unlink(local_sql)

    dbhost_only = dbhost.split(":")[0]
    dbport = dbhost.split(":")[1] if ":" in dbhost else "3306"
    dbpass_shell = dbpass.replace("'", "'\\''")
    apply = (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' '{dbname}' "
        f"< '{remote_sql}' && rm -f '{remote_sql}' && echo mysql-ok"
    )
    code, out, err = run(ssh, apply, timeout=600)
    print(out.strip(), flush=True)
    if code != 0:
        print(err[:2000], flush=True)
        ssh.close()
        return code

    print(
        q(
            f"SELECT estado, COUNT(*) FROM `{p}competencia_match` GROUP BY estado"
        ),
        flush=True,
    )
    ssh.close()
    print("Sugerencias aplicadas.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
