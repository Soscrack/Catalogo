#!/usr/bin/env python3
"""
Extrae catálogo de competencia desde la API pública de Sande (mcatalogo).

Uso:
  python tools/scrape_sande_catalog.py --seccion 237184 --marca 165
  python tools/scrape_sande_catalog.py --seccion 237184 --marca 165 --skip-media
  python tools/scrape_sande_catalog.py --only-normalize --outdir data/competencia/sande
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUT = ROOT / "data" / "competencia" / "sande"

API_MAESTROS = "https://sandeonline.cl:2082/taskfocus/maestros/api"
API_MULTIMEDIA = "https://sandeonline.cl:2082/taskfocus/multimedia/api"
DEFAULT_PROGRAMA = "639745"
DEFAULT_EMPRESA = "1"
DEFAULT_USER = "1"
USER_AGENT = "RiversoCatalogBot/1.0 (+https://riverso.cl; competencia-interna)"
IVA_FACTOR = 1.19

TIPO_MULTIMEDIA = {1: "foto", 2: "pdf", 3: "link"}
TIPO_ADJUNTO = {1: "ficha", 2: "certificado", 3: "garantia", 0: ""}

SSL_CTX = ssl.create_default_context()
SSL_CTX.check_hostname = False
SSL_CTX.verify_mode = ssl.CERT_NONE


def log(msg: str) -> None:
    print(msg, flush=True)


def norm_code(value: Any) -> str:
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def parse_cl_decimal(value: Any) -> float | None:
    if value is None:
        return None
    s = str(value).strip()
    if not s:
        return None
    s = s.replace(".", "").replace(",", ".") if re.search(r",\d", s) else s.replace(",", "")
    try:
        return float(s)
    except ValueError:
        return None


def js_round(value: float) -> int:
    import math

    if value >= 0:
        return int(math.floor(value + 0.5))
    return int(math.ceil(value - 0.5))


def min_venta_qty(producto: dict) -> int:
    raw = producto.get("uniMinVta") or producto.get("idMinUniVta") or 1
    n = parse_cl_decimal(raw)
    if n is None or n < 1:
        return 1
    return int(n)


def catalog_unit_net(producto: dict) -> float | None:
    """Sande usa precioProductoL si existe; si no, precioProducto (función s5 del front)."""
    lista = parse_cl_decimal(producto.get("precioProductoL"))
    if lista is not None:
        return lista
    return parse_cl_decimal(producto.get("precioProducto"))


def clean_path_url(value: Any) -> str:
    if not value:
        return ""
    return str(value).replace("\\", "/").strip()


def http_json(
    url: str,
    *,
    method: str = "GET",
    payload: dict | None = None,
    timeout: int = 300,
    retries: int = 4,
    delay: float = 0.0,
) -> Any:
    data = None
    headers = {"User-Agent": USER_AGENT, "Accept": "application/json"}
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    last_err: Exception | None = None
    for attempt in range(retries):
        if delay > 0:
            time.sleep(delay)
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, context=SSL_CTX, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8", "replace")
                if not raw.strip():
                    return None
                return json.loads(raw)
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            last_err = exc
            time.sleep(min(2 ** attempt, 20))
    raise RuntimeError(f"HTTP falló {method} {url}: {last_err}")


def http_bytes(url: str, *, timeout: int = 25, retries: int = 2) -> tuple[bytes, str]:
    last_err: Exception | None = None
    for attempt in range(retries):
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
        try:
            with urllib.request.urlopen(req, context=SSL_CTX, timeout=timeout) as resp:
                body = resp.read()
                mime = resp.headers.get("Content-Type", "application/octet-stream")
                return body, mime
        except Exception as exc:
            last_err = exc
            time.sleep(min(2 ** attempt, 10))
    raise RuntimeError(f"Descarga falló {url}: {last_err}")


def write_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def append_jsonl(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def rewrite_jsonl(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def fetch_categorias(raw_dir: Path, programa: str) -> list[dict]:
    cache = raw_dir / "categorias.json"
    if cache.is_file():
        log(f"[cache] categorias -> {cache}")
        return json.loads(cache.read_text(encoding="utf-8"))
    log("[api] ListarCategoriasMaestroV3 ...")
    data = http_json(
        f"{API_MAESTROS}/Gestion/ListarCategoriasMaestroV3",
        method="POST",
        payload={
            "idEmpresa": int(DEFAULT_EMPRESA),
            "idUser": int(DEFAULT_USER),
            "idPrograma": programa,
            "idSeccion": "0",
            "listaTipoProducto": "",
            "listaIdSituacion": "",
        },
        timeout=180,
    )
    rows = data if isinstance(data, list) else []
    write_json(cache, rows)
    log(f"[ok] categorias: {len(rows)}")
    return rows


def fetch_productos_seccion(raw_dir: Path, seccion: str, programa: str) -> list[dict]:
    cache = raw_dir / f"productos_{seccion}.json"
    if cache.is_file():
        log(f"[cache] productos seccion {seccion} -> {cache}")
        return json.loads(cache.read_text(encoding="utf-8"))
    log(f"[api] listarProductosT3 seccion={seccion} (puede tardar ~3 min) ...")
    data = http_json(
        f"{API_MAESTROS}/MaestroProducto/listarProductosT3",
        method="POST",
        payload={
            "idEmpresa": DEFAULT_EMPRESA,
            "idUser": DEFAULT_USER,
            "idPrograma": programa,
            "tipoProducto": "0",
            "idCategoria": "0",
            "proveedor": "0",
            "marca": "0",
            "descripProd": "",
            "nPalabras": "0",
            "idSeccion": seccion,
        },
        timeout=600,
    )
    rows = data if isinstance(data, list) else []
    write_json(cache, rows)
    log(f"[ok] productos seccion {seccion}: {len(rows)}")
    return rows


def filter_marca(productos: list[dict], marca: str | None) -> list[dict]:
    if not marca:
        return productos
    return [p for p in productos if str(p.get("idMarca", "")) == str(marca)]


def fetch_multimedia_producto(producto_id: str, delay: float) -> list[dict]:
    url = f"{API_MULTIMEDIA}/Registro/VerMultimediaProducto?idproducto={urllib.parse.quote(producto_id)}"
    data = http_json(url, timeout=90, delay=delay)
    return data if isinstance(data, list) else []


def fetch_caracteristicas_producto(producto_id: str, delay: float) -> list[dict]:
    url = f"{API_MAESTROS}/FichaProducto/obtenerFichaProductoVistaCaracteristicas/{DEFAULT_EMPRESA}/{producto_id}/0"
    data = http_json(url, timeout=90, delay=delay)
    return data if isinstance(data, list) else []


def parallel_fetch(
    productos: list[dict],
    raw_dir: Path,
    subdir: str,
    worker,
    workers: int,
    delay: float,
    label: str,
) -> None:
    target = raw_dir / subdir
    target.mkdir(parents=True, exist_ok=True)
    pending = []
    for p in productos:
        pid = str(p.get("idProducto", "")).strip()
        if not pid:
            continue
        cache_file = target / f"{pid}.json"
        if not cache_file.is_file():
            pending.append(pid)
    if not pending:
        log(f"[skip] {label}: todo en cache ({len(productos)} productos)")
        return
    log(f"[api] {label}: {len(pending)} pendientes, workers={workers}")
    done = 0
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = {pool.submit(worker, pid, delay): pid for pid in pending}
        for fut in as_completed(futures):
            pid = futures[fut]
            try:
                rows = fut.result()
                write_json(target / f"{pid}.json", rows)
            except Exception as exc:
                write_json(target / f"{pid}.json", {"error": str(exc)})
            done += 1
            if done % 100 == 0 or done == len(pending):
                log(f"  {label}: {done}/{len(pending)}")


def build_media_url(row: dict) -> str:
    adjunto = str(row.get("adjunto", "")).strip()
    if not adjunto:
        return ""
    if adjunto.startswith("http://") or adjunto.startswith("https://"):
        return adjunto
    base = clean_path_url(row.get("rutaLogica", ""))
    if not base:
        return ""
    return f"{base.rstrip('/')}/{urllib.parse.quote(adjunto, safe='/')}"


def guess_ext(mime: str, url: str, tipo: str) -> str:
    mime = (mime or "").lower()
    if "pdf" in mime:
        return "pdf"
    if "png" in mime:
        return "png"
    if "jpeg" in mime or "jpg" in mime:
        return "jpg"
    if "webp" in mime:
        return "webp"
    path = urllib.parse.urlparse(url).path.lower()
    for ext in (".pdf", ".png", ".jpg", ".jpeg", ".webp", ".gif"):
        if path.endswith(ext):
            return ext.lstrip(".")
    if tipo == "pdf":
        return "pdf"
    if tipo == "foto":
        return "jpg"
    return "bin"


def download_media_file(url: str, media_dir: Path, tipo: str) -> dict | None:
    if not url:
        return None
    if tipo == "link" or "youtube.com" in url or "mamutstore.com" in url:
        return {
            "sha256": hashlib.sha256(url.encode("utf-8")).hexdigest(),
            "tipo": "link" if tipo == "link" else tipo,
            "url_origen": url,
            "mime": "text/uri-list",
            "bytes": 0,
            "ruta_local": "",
        }
    try:
        body, mime = http_bytes(url)
    except Exception:
        return None
    if not body:
        return None
    digest = hashlib.sha256(body).hexdigest()
    ext = guess_ext(mime, url, tipo)
    rel = f"media/{digest}.{ext}"
    dest = media_dir / f"{digest}.{ext}"
    if not dest.is_file():
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(body)
    return {
        "sha256": digest,
        "tipo": tipo,
        "url_origen": url,
        "mime": mime,
        "bytes": len(body),
        "ruta_local": rel,
    }


def collect_media_jobs(productos: list[dict], raw_dir: Path) -> list[dict]:
    jobs = []
    for p in productos:
        pid = str(p.get("idProducto", "")).strip()
        principal_url = clean_path_url(p.get("link", ""))
        if principal_url:
            jobs.append(
                {
                    "id_externo": pid,
                    "url": principal_url,
                    "tipo": "foto",
                    "subtipo": "principal",
                    "es_principal": True,
                }
            )
        mm_path = raw_dir / "multimedia" / f"{pid}.json"
        if not mm_path.is_file():
            continue
        try:
            mm = json.loads(mm_path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            continue
        if not isinstance(mm, list):
            continue
        for row in mm:
            tipo_id = int(row.get("idTipoMultimedia") or 0)
            adj_id = int(row.get("idTipoAdjunto") or 0)
            tipo = TIPO_MULTIMEDIA.get(tipo_id, "otro")
            url = build_media_url(row)
            if not url:
                continue
            jobs.append(
                {
                    "id_externo": pid,
                    "url": url,
                    "tipo": tipo,
                    "subtipo": TIPO_ADJUNTO.get(adj_id, ""),
                    "es_principal": False,
                }
            )
    return jobs


def download_unique_media(jobs: list[dict], media_dir: Path, workers: int = 8) -> dict[str, dict]:
    by_url: dict[str, dict] = {}
    unique = []
    seen = set()
    for job in jobs:
        url = job["url"]
        if url in seen:
            continue
        seen.add(url)
        unique.append((url, job["tipo"]))

    log(f"[media] URLs unicas a descargar: {len(unique)}")
    done = 0
    failed = 0

    def work(item):
        url, tipo = item
        return url, download_media_file(url, media_dir, tipo)

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(work, item) for item in unique]
        for fut in as_completed(futures):
            url, downloaded = fut.result()
            done += 1
            if downloaded:
                by_url[url] = downloaded
            else:
                failed += 1
            if done % 100 == 0 or done == len(unique):
                log(f"  media: {done}/{len(unique)} (fallidas={failed})")
    return by_url


def normalize_outputs(
    outdir: Path,
    raw_dir: Path,
    productos: list[dict],
    categorias: list[dict],
    fuente_slug: str = "sande",
) -> None:
    snapshot = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    media_dir = outdir / "media"
    media_dir.mkdir(parents=True, exist_ok=True)

    cat_rows = []
    seen_cats: set[str] = set()
    for c in categorias:
        cid = str(c.get("idCat") or c.get("idCategoria") or "").strip()
        if not cid or cid in seen_cats:
            continue
        seen_cats.add(cid)
        cat_rows.append(
            {
                "fuente": fuente_slug,
                "id_division": str(c.get("idDiv") or c.get("idDivision") or ""),
                "nombre_division": c.get("nombreDivision") or c.get("nombreDiv") or "",
                "id_seccion": str(c.get("idSec") or c.get("idSeccion") or ""),
                "nombre_seccion": c.get("nombreSeccion") or c.get("nombreSec") or "",
                "id_categoria": cid,
                "nombre_categoria": c.get("nombreCategoria") or c.get("nombreCat") or "",
                "link_imagen": clean_path_url(c.get("link", "")),
            }
        )

    producto_rows: list[dict] = []
    precio_rows: list[dict] = []
    atributo_rows: list[dict] = []

    jobs = collect_media_jobs(productos, raw_dir)
    downloaded_by_url = download_unique_media(jobs, media_dir, workers=8)
    medio_rows: dict[str, dict] = {}
    puente_rows: list[dict] = []
    for job in jobs:
        downloaded = downloaded_by_url.get(job["url"])
        if not downloaded:
            continue
        medio_rows[downloaded["sha256"]] = downloaded
        puente_rows.append(
            {
                "id_externo": job["id_externo"],
                "sha256": downloaded["sha256"],
                "es_principal": job["es_principal"],
                "tipo_multimedia": job["tipo"],
                "subtipo": job["subtipo"],
            }
        )

    for p in productos:
        pid = str(p.get("idProducto", "")).strip()
        codigo = str(p.get("codigoProducto", "")).strip()
        producto_rows.append(
            {
                "fuente": fuente_slug,
                "id_externo": pid,
                "codigo_externo": codigo,
                "codigo_normalizado": norm_code(codigo),
                "nombre": p.get("nombreProducto") or "",
                "slug": p.get("slug") or "",
                "url_producto": (
                    f"{SANDE_PRODUCT_URL}/{str(p.get('slug') or '').strip().strip('/')}"
                    if str(p.get("slug") or "").strip()
                    else ""
                ),
                "marca": p.get("nombreMarca") or "",
                "id_marca": str(p.get("idMarca") or ""),
                "fabricante": p.get("brand") or "",
                "id_division": str(p.get("idDivision") or ""),
                "id_seccion": str(p.get("idSeccion") or ""),
                "id_categoria": str(p.get("idCategoria") or ""),
                "nombre_division": p.get("nombreDivision") or "",
                "nombre_seccion": p.get("nombreSeccion") or "",
                "nombre_categoria": p.get("nombreCategoria") or "",
                "unidad_min_venta": p.get("uniMinVta") or p.get("idMinUniVta") or "",
                "tipo_unidad": p.get("tipoUnidad") or "",
                "peso": p.get("peso") or "",
                "stock": p.get("stockt") or p.get("stocke") or "",
                "situacion": p.get("situacion") or "",
                "imagen_principal": clean_path_url(p.get("link", "")),
                "capturado_at": snapshot,
            }
        )
        qty = min_venta_qty(p)
        neto_lista = catalog_unit_net(p)
        bruto_unitario = round(neto_lista * IVA_FACTOR, 6) if neto_lista is not None else None
        bruto_total = js_round(neto_lista * qty * IVA_FACTOR) if neto_lista is not None else None
        precio_rows.append(
            {
                "id_externo": pid,
                "snapshot_fecha": snapshot,
                "precio": parse_cl_decimal(p.get("precioProducto")),
                "precio_lista": parse_cl_decimal(p.get("precioProductoL")),
                "precio_bruto_unitario": bruto_unitario,
                "precio_bruto_total": bruto_total,
                "cantidad_min": qty,
                "iva": IVA_FACTOR,
                "costo": parse_cl_decimal(p.get("costoProducto") or p.get("precioCosto")),
                "moneda": p.get("moneda") or p.get("idMoneda") or "CLP",
                "oculto": str(p.get("ocultarPrecio") or "0") == "1",
            }
        )

        car_path = raw_dir / "caracteristicas" / f"{pid}.json"
        if car_path.is_file():
            try:
                attrs = json.loads(car_path.read_text(encoding="utf-8"))
                if isinstance(attrs, list):
                    for a in attrs:
                        atributo_rows.append(
                            {
                                "id_externo": pid,
                                "titulo": a.get("titulo") or "",
                                "valor": a.get("valorTitulo") or "",
                                "orden": int(a.get("orden") or 0),
                            }
                        )
            except json.JSONDecodeError:
                pass

    rewrite_jsonl(outdir / "categorias.jsonl", cat_rows)
    rewrite_jsonl(outdir / "productos.jsonl", producto_rows)
    rewrite_jsonl(outdir / "precios.jsonl", precio_rows)
    rewrite_jsonl(outdir / "atributos.jsonl", atributo_rows)
    rewrite_jsonl(outdir / "medios.jsonl", list(medio_rows.values()))
    rewrite_jsonl(outdir / "producto_medio.jsonl", puente_rows)

    meta = {
        "fuente": fuente_slug,
        "generado_at": datetime.now(timezone.utc).isoformat(),
        "productos": len(producto_rows),
        "categorias": len(cat_rows),
        "medios_unicos": len(medio_rows),
        "atributos": len(atributo_rows),
        "snapshot_fecha": snapshot,
    }
    write_json(outdir / "manifest.json", meta)
    log(
        f"[normalize] productos={len(producto_rows)} medios={len(medio_rows)} "
        f"atributos={len(atributo_rows)} -> {outdir}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Extractor catálogo Sande (competencia)")
    parser.add_argument("--outdir", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--seccion", default="237184", help="idSeccion (TORNILLOS/MAMUT=237184)")
    parser.add_argument("--marca", default="165", help="idMarca (MAMUT=165); vacío = sin filtrar")
    parser.add_argument("--programa", default=DEFAULT_PROGRAMA)
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--delay", type=float, default=0.15, help="Segundos entre requests por worker")
    parser.add_argument("--skip-multimedia", action="store_true")
    parser.add_argument("--skip-caracteristicas", action="store_true")
    parser.add_argument("--only-normalize", action="store_true", help="Solo JSONL desde raw cache")
    args = parser.parse_args()

    outdir = args.outdir.resolve()
    raw_dir = outdir / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)

    if args.only_normalize:
        prod_path = raw_dir / f"productos_{args.seccion}.json"
        if not prod_path.is_file():
            log(f"No existe cache {prod_path}")
            return 1
        productos = json.loads(prod_path.read_text(encoding="utf-8"))
        productos = filter_marca(productos, args.marca or None)
        categorias = fetch_categorias(raw_dir, args.programa)
        normalize_outputs(outdir, raw_dir, productos, categorias)
        return 0

    categorias = fetch_categorias(raw_dir, args.programa)
    productos = fetch_productos_seccion(raw_dir, args.seccion, args.programa)
    productos = filter_marca(productos, args.marca or None)
    log(f"[filter] productos tras marca {args.marca}: {len(productos)}")

    if not args.skip_multimedia:
        parallel_fetch(
            productos,
            raw_dir,
            "multimedia",
            lambda pid, d: fetch_multimedia_producto(pid, d),
            args.workers,
            args.delay,
            "multimedia",
        )
    if not args.skip_caracteristicas:
        parallel_fetch(
            productos,
            raw_dir,
            "caracteristicas",
            lambda pid, d: fetch_caracteristicas_producto(pid, d),
            args.workers,
            args.delay,
            "caracteristicas",
        )

    normalize_outputs(outdir, raw_dir, productos, categorias)
    return 0


if __name__ == "__main__":
    sys.exit(main())
