#!/usr/bin/env python3
"""
Extrae catálogo de competencia desde DIMAFI (Shopify) a JSONL.

Objetivo:
 - Construir `data/competencia/dimafi/*` con contrato compatible con import_competencia_sande.py
 - Deduplicar medios por `sha256` (fotos y PDF/ficha)
 - 1 fila de `competencia_productos` por VARIANTE vendible (Shopify variant.id)
 - Guardar descripción HTML + ficha técnica asociada UNA SOLA VEZ por grupo (opcional):
     * descripción: solo en la variante canónica (variants[0])
     * media: se adjunta a cada variante para que UI/confirmaciones tengan contexto

Uso:
  python tools/scrape_dimafi_catalog.py
  python tools/scrape_dimafi_catalog.py --outdir data/competencia/dimafi
  python tools/scrape_dimafi_catalog.py --skip-media
  python tools/scrape_dimafi_catalog.py --only-normalize
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
DEFAULT_OUT = ROOT / "data" / "competencia" / "dimafi"
BASE_URL = "https://www.dimafi.cl"

USER_AGENT = "RiversoCatalogBot/1.0 (+https://riverso.cl; competencia-interna)"
IVA_FACTOR = 1.19

SSL_CTX = ssl.create_default_context()
SSL_CTX.check_hostname = False
SSL_CTX.verify_mode = ssl.CERT_NONE


def log(msg: str) -> None:
    print(msg, flush=True)


def norm_code(value: Any) -> str:
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def clean_html_fragment(html: str) -> str:
    # Shopify entrega HTML con espacios raros; lo normalizamos para almacenamiento estable.
    if not html:
        return ""
    html = html.replace("\r\n", "\n").replace("\r", "\n").strip()
    html = re.sub(r"\n{3,}", "\n\n", html)
    return html


def http_json(url: str, *, timeout: int = 60, retries: int = 3, delay: float = 0.0) -> Any:
    last_err: Exception | None = None
    headers = {"User-Agent": USER_AGENT, "Accept": "application/json"}
    for attempt in range(retries):
        if delay > 0:
            time.sleep(delay)
        try:
            req = urllib.request.Request(url, headers=headers, method="GET")
            with urllib.request.urlopen(req, context=SSL_CTX, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8", "replace")
                if not raw.strip():
                    return None
                return json.loads(raw)
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            last_err = exc
            time.sleep(min(2**attempt, 20))
    raise RuntimeError(f"HTTP falló GET {url}: {last_err}")


def http_bytes(url: str, *, timeout: int = 25, retries: int = 2) -> tuple[bytes, str]:
    last_err: Exception | None = None
    headers = {"User-Agent": USER_AGENT}
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers=headers, method="GET")
            with urllib.request.urlopen(req, context=SSL_CTX, timeout=timeout) as resp:
                body = resp.read()
                mime = resp.headers.get("Content-Type") or "application/octet-stream"
                return body, mime
        except Exception as exc:
            last_err = exc
            time.sleep(min(2**attempt, 10))
    raise RuntimeError(f"Descarga falló {url}: {last_err}")


def guess_ext(mime: str, url: str, tipo: str) -> str:
    mime = (mime or "").lower()
    if "pdf" in mime:
        return "pdf"
    if "png" in mime:
        return "png"
    if "jpeg" in mime:
        return "jpg"
    if "jpg" in mime:
        return "jpg"
    if "webp" in mime:
        return "webp"
    path = urllib.parse.urlparse(url).path.lower()
    for ext in (".pdf", ".png", ".jpg", ".jpeg", ".webp", ".gif"):
        if path.endswith(ext):
            return ext.lstrip(".")
    if tipo == "pdf":
        return "pdf"
    if tipo in ("foto", "image"):
        return "jpg"
    return "bin"


def js_round(value: float) -> int:
    import math

    if value >= 0:
        return int(math.floor(value + 0.5))
    return int(math.ceil(value - 0.5))


def pack_qty_from_title(title: str) -> tuple[int, str | None]:
    """
    Intenta inferir cantidad mínima y unidad desde el título.
    Ejemplos típicos:
      - "(500 UDS)"
      - "(10 UDS)"
    """
    if not title:
        return 1, None
    t = str(title)
    m = re.search(r"\((\d+)\s*U(D)?S\.?\)", t, flags=re.IGNORECASE)
    if m:
        return int(m.group(1)), "uds"
    m = re.search(r"\((\d+)\s*UDS?\)", t, flags=re.IGNORECASE)
    if m:
        return int(m.group(1)), "uds"
    # Caso sin paréntesis
    m = re.search(r"\b(\d+)\s*UDS?\b", t, flags=re.IGNORECASE)
    if m:
        return int(m.group(1)), "uds"
    return 1, None


def extract_ficha_links(body_html: str) -> list[str]:
    """
    Extrae URLs de "VER FICHA TÉCNICA" / "VER FICHA TECNICA".
    Hay productos con más de un link; devolvemos la lista deduplicada.
    """
    if not body_html:
        return []

    # Normaliza acentos en el texto a comparar (sin tocar URLs).
    html = body_html
    anchor_re = re.compile(r"<a[^>]*href=[\"']([^\"']+)[\"'][^>]*>(.*?)</a>", flags=re.IGNORECASE | re.DOTALL)

    out: list[str] = []
    seen: set[str] = set()
    for m in anchor_re.finditer(html):
        url = m.group(1).strip()
        inner = m.group(2) or ""
        # Quitar tags del texto interno del anchor.
        text = re.sub(r"<[^>]+>", " ", inner)
        text_norm = text.upper()
        if "FICHA" not in text_norm:
            continue
        # Evitar ensayos si el anchor solo dice ensayo.
        # (Igual si tiene FICHA, lo incluimos.)
        if "FICHA" in text_norm:
            if url and url not in seen:
                seen.add(url)
                out.append(url)
    return out


def download_media_file(url: str, media_dir: Path, tipo: str) -> dict | None:
    if not url:
        return None
    # Normalizamos saltos de línea / espacios.
    url = str(url).strip()
    sha = hashlib.sha256(url.encode("utf-8")).hexdigest()

    # Para "link" no intentamos descargar.
    if tipo == "link":
        return {
            "sha256": sha,
            "tipo": "link",
            "subtipo": None,
            "url_origen": url,
            "ruta_local": "",
            "bytes": 0,
            "mime": "text/uri-list",
        }

    try:
        body, mime = http_bytes(url)
        if not body:
            raise RuntimeError("empty body")
        ext = guess_ext(mime, url, tipo)
        rel = f"media/{sha}.{ext}"
        dest = media_dir / f"{sha}.{ext}"
        dest.parent.mkdir(parents=True, exist_ok=True)
        if not dest.is_file():
            dest.write_bytes(body)
        return {
            "sha256": sha,
            "tipo": tipo,
            "subtipo": None,
            "url_origen": url,
            "ruta_local": rel,
            "bytes": len(body),
            "mime": mime,
        }
    except Exception:
        # Fallback: guardamos la URL como recurso "link".
        return {
            "sha256": sha,
            "tipo": "link",
            "subtipo": None,
            "url_origen": url,
            "ruta_local": "",
            "bytes": 0,
            "mime": "text/uri-list",
        }


def download_unique_media(jobs: list[dict], media_dir: Path, workers: int = 8) -> dict[str, dict]:
    """
    jobs: {url, tipo, subtipo, sha_hint?}
    Deduplicamos por URL para no descargar dos veces.
    """
    by_url: dict[str, dict] = {}
    seen: set[str] = set()
    unique: list[tuple[str, str, str | None]] = []
    for job in jobs:
        url = str(job["url"]).strip()
        if url in seen:
            continue
        seen.add(url)
        unique.append((url, job["tipo"], job.get("subtipo")))

    log(f"[media] URLs unicas a descargar: {len(unique)}")

    def work(item: tuple[str, str, str | None]) -> tuple[str, dict]:
        url, tipo, subtipo = item
        media = download_media_file(url, media_dir, tipo)
        if media is None:
            # En teoría no debería pasar porque download_media_file siempre retorna al menos link fallback.
            raise RuntimeError(f"No se pudo descargar {url}")
        if subtipo:
            media["subtipo"] = subtipo
        return url, media

    done = 0
    failed = 0
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(work, item) for item in unique]
        for fut in as_completed(futures):
            try:
                url, downloaded = fut.result()
                by_url[url] = downloaded
            except Exception:
                failed += 1
            done += 1
            if done % 100 == 0 or done == len(unique):
                log(f"  media: {done}/{len(unique)} (fallidas={failed})")
    return by_url


def rewrite_jsonl(path: Path, rows: list[dict]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        for row in rows:
            f.write(json.dumps(row, ensure_ascii=False) + "\n")


def write_json(path: Path, obj: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(obj, ensure_ascii=False, indent=2), encoding="utf-8")


def fetch_all_products(raw_dir: Path, out_pages: Path) -> list[dict]:
    """
    Shopify: catálogo completo accesible vía:
      /collections/all/products.json?limit=250&page=N
    """
    products: list[dict] = []
    page = 1
    while True:
        url = f"{BASE_URL}/collections/all/products.json?limit=250&page={page}"
        data = http_json(url, timeout=60, retries=4, delay=0.0)
        if not data or "products" not in data:
            break
        page_products = data.get("products") or []
        # Guardamos cache por trazabilidad.
        cache_path = raw_dir / f"products_all_page_{page}.json"
        write_json(cache_path, data)
        if not page_products:
            break
        products.extend(page_products)
        page += 1
        if page > 30:
            break
    if not products:
        raise RuntimeError("No se pudo extraer el catálogo completo desde Shopify.")
    write_json(out_pages, {"count": len(products)})
    return products


def fetch_collections(raw_dir: Path) -> list[dict]:
    url = f"{BASE_URL}/collections.json?limit=250"
    data = http_json(url, timeout=60, retries=4)
    cache_path = raw_dir / "collections.json"
    write_json(cache_path, data)
    collections = data.get("collections") or []
    if not collections:
        raise RuntimeError("No se pudo extraer collections.json")
    return collections


def fetch_products_for_collection(raw_dir: Path, handle: str) -> list[dict]:
    """
    Obtiene todos los products de una colección.
    """
    page = 1
    out: list[dict] = []
    while True:
        url = f"{BASE_URL}/collections/{handle}/products.json?limit=250&page={page}"
        data = http_json(url, timeout=60, retries=4)
        cache_path = raw_dir / f"collection_{handle}_page_{page}.json"
        write_json(cache_path, data)
        page_products = data.get("products") or []
        if not page_products:
            break
        out.extend(page_products)
        page += 1
        if page > 20:
            break
    return out


def choose_category_handle(product_memberships: list[str], priority: list[str], fallback: list[str]) -> str:
    for h in priority:
        if h in product_memberships:
            return h
    for h in fallback:
        if h in product_memberships:
            return h
    # Last resort.
    return product_memberships[0] if product_memberships else ""


def main() -> int:
    parser = argparse.ArgumentParser(description="Extractor catálogo DIMAFI (competencia)")
    parser.add_argument("--outdir", type=Path, default=DEFAULT_OUT)
    parser.add_argument("--workers", type=int, default=10)
    parser.add_argument("--delay", type=float, default=0.0, help="Delay entre requests (solo si se requiere)")
    parser.add_argument("--skip-media", action="store_true", help="No descarga imágenes/PDF (igual genera JSONL sin archivos locales)")
    parser.add_argument("--only-normalize", action="store_true", help="Usa cache raw/*.json (si existe) y solo normaliza")
    args = parser.parse_args()

    outdir = args.outdir.resolve()
    raw_dir = outdir / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)
    media_dir = outdir / "media"
    media_dir.mkdir(parents=True, exist_ok=True)

    fuente_slug = "dimafi"
    priority = ["tornilleria", "fijaciones", "anclajes-mecanicos", "anclaje-quimico", "fijaciones-directas"]
    ignore_collections = {
        "all",
        "fichas-tecnicas",
        "productos-destacados",
        "ofertas",
        # En caso de existir, lo dejamos fuera del árbol principal.
        "productos-destacados-2",
    }

    # ---- Load / Fetch raw ----
    if args.only_normalize:
        # Productos all:
        products_all: list[dict] = []
        page = 1
        while True:
            cache_path = raw_dir / f"products_all_page_{page}.json"
            if not cache_path.is_file():
                break
            data = json.loads(cache_path.read_text(encoding="utf-8"))
            products_all.extend(data.get("products") or [])
            page += 1
        collections_data = json.loads((raw_dir / "collections.json").read_text(encoding="utf-8"))
        collections = collections_data.get("collections") or []
    else:
        collections = fetch_collections(raw_dir)
        products_all = fetch_all_products(raw_dir, out_pages=raw_dir / "_meta.json")

        # Construir membership product_id -> handles.
        #
        # Para evitar rate-limit (HTTP 429), NO intentamos mapear todas las colecciones.
        # Usamos solo las `priority` del plan como categoría primaria; el resto caerá en `otros`.
        relevant_handles: list[str] = []
        handle_to_collection: dict[str, dict] = {}
        for c in collections:
            handle = (c.get("handle") or "").strip()
            if not handle or handle in ignore_collections:
                continue
            if handle not in priority:
                continue
            if int(c.get("products_count") or 0) <= 0:
                continue
            handle_to_collection[handle] = c

        relevant_handles = [h for h in priority if h in handle_to_collection]

        memberships: dict[str, set[str]] = {}
        log(f"[cats] colecciones relevantes (priority): {len(relevant_handles)}")
        for i, handle in enumerate(relevant_handles, 1):
            log(f"[cats] {i}/{len(relevant_handles)} handle={handle}")
            col_products = fetch_products_for_collection(raw_dir, handle)
            for p in col_products:
                pid = str(p.get("id") or "").strip()
                if not pid:
                    continue
                memberships.setdefault(pid, set()).add(handle)
            # Respeta delay si se configuró.
            if args.delay > 0:
                time.sleep(args.delay)

        # cache para normalización
        write_json(raw_dir / "memberships.json", {k: sorted(list(v)) for k, v in memberships.items()})

    # ---- Load membership cache (needed for categories) ----
    memberships_json_path = raw_dir / "memberships.json"
    if not memberships_json_path.is_file():
        # Si only_normalize=true pero no existe cache, no hay con qué asignar categorías.
        # Generamos memberships mínimo usando los handles de priority (fallback).
        raise RuntimeError("Falta raw/memberships.json (ejecuta sin --only-normalize para generar cache).")

    memberships_json = json.loads(memberships_json_path.read_text(encoding="utf-8"))
    # memberships_json: product_id -> [handles...]
    # collections: se usa para títulos + link_imagen

    # Index collections by handle.
    collections_by_handle: dict[str, dict] = {str(c.get("handle") or "").strip(): c for c in collections if str(c.get("handle") or "").strip()}

    # ---- Build categories.jsonl ----
    # Para evitar 429, generamos una estructura mínima:
    # - categorías por `priority` (si existen)
    # - un bucket genérico `otros` para el resto
    priority_handles = [h for h in priority if h in collections_by_handle]

    cat_rows: list[dict] = []
    handle_to_id_categoria: dict[str, str] = {}

    for handle in priority_handles:
        c = collections_by_handle.get(handle) or {}
        title = c.get("title") or handle
        img_src = None
        if isinstance(c.get("image"), dict):
            img_src = c["image"].get("src") or None

        id_categoria = handle if len(handle) <= 32 else "cat_" + hashlib.sha256(handle.encode("utf-8")).hexdigest()[:24]
        handle_to_id_categoria[handle] = id_categoria

        cat_rows.append(
            {
                "fuente": fuente_slug,
                "id_division": "",
                "nombre_division": "",
                "id_seccion": "",
                "nombre_seccion": "",
                "id_categoria": id_categoria,
                "nombre_categoria": title,
                "link_imagen": img_src,
            }
        )

    # Bucket genérico.
    otros_handle = "otros"
    handle_to_id_categoria[otros_handle] = otros_handle
    cat_rows.append(
        {
            "fuente": fuente_slug,
            "id_division": "",
            "nombre_division": "",
            "id_seccion": "",
            "nombre_seccion": "",
            "id_categoria": otros_handle,
            "nombre_categoria": "OTROS",
            "link_imagen": None,
        }
    )

    # ---- Build products + attributes + prices ----
    producto_rows: list[dict] = []
    precio_rows: list[dict] = []
    atributo_rows: list[dict] = []

    snapshot = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    # Canonical: variante 0 por product.id

    # Para asociar media jobs a variantes, generamos una lista global.
    media_jobs: list[dict] = []
    medio_rows_by_sha: dict[str, dict] = {}
    puente_rows: list[dict] = []

    # Map product_id -> ficha_descripcion_html para no recalcular
    # (solo para canonical)
    for p in products_all:
        product_id = str(p.get("id") or "").strip()
        handle = str(p.get("handle") or "").strip()
        title = str(p.get("title") or "").strip()
        vendor = str(p.get("vendor") or "").strip()
        product_type = str(p.get("product_type") or "").strip()
        tags = p.get("tags") or []

        if not product_id or not handle:
            continue

        memberships = memberships_json.get(product_id) or []
        memberships = [str(x).strip() for x in memberships if x]

        chosen_handle = ""
        for h in priority:
            if h in memberships:
                chosen_handle = h
                break
        if chosen_handle == "":
            chosen_handle = "otros"

        chosen_id_categoria = handle_to_id_categoria.get(chosen_handle)
        chosen_name_categoria = (
            "OTROS" if chosen_handle == otros_handle
            else (collections_by_handle.get(chosen_handle, {}).get("title") or chosen_handle)
        )

        body_html = clean_html_fragment(str(p.get("body_html") or ""))
        ficha_links = extract_ficha_links(body_html)

        images = p.get("images") or []
        principal_image = images[0]["src"] if images else ""

        # Shopify options names come from product.options: [{name, position, values}]
        # Variants contienen option1/option2/option3.
        option_defs = p.get("options") or []
        option_names: list[str] = []
        for od in option_defs:
            if isinstance(od, dict):
                n = (od.get("name") or "").strip()
                if n:
                    option_names.append(n)
        while len(option_names) < 3:
            option_names.append("")

        variants = p.get("variants") or []
        if not variants:
            continue

        # Create per-variant rows
        for v_index, v in enumerate(variants):
            variant_id = str(v.get("id") or "").strip()
            if not variant_id:
                continue

            sku = v.get("sku") or ""
            sku = str(sku).strip()
            codigo_normalizado = norm_code(sku) if sku else ""

            option_values = [
                str(v.get("option1") or "").strip(),
                str(v.get("option2") or "").strip(),
                str(v.get("option3") or ""),
            ]
            option_values = [str(x).strip() for x in option_values]

            # Nombre: título del producto + opciones (para diferenciar variantes)
            variant_suffix_parts: list[str] = []
            for name, val in zip(option_names[:3], option_values[:3]):
                if name and val and name.lower() != "title":
                    variant_suffix_parts.append(f"{val}")
            # Si no hay option names útiles, fallback al option1
            if not variant_suffix_parts and option_values[0]:
                variant_suffix_parts.append(option_values[0])
            suffix = " / ".join(variant_suffix_parts) if variant_suffix_parts else ""

            nombre = title
            if suffix and suffix not in nombre:
                nombre = f"{title} — {suffix}"

            url_producto = f"{BASE_URL}/products/{handle}"

            # Cantidad mínima (si el producto contiene pack en el título)
            qty_min, qty_unit = pack_qty_from_title(title)
            if qty_unit is None:
                qty_unit = "unit" if qty_min == 1 else "uds"

            available = bool(v.get("available", True))
            situacion = "activo" if available else "inactivo"

            # Precio: Shopify price está en CLP. Interpretación inicial: precio mostrado = neto.
            raw_price = v.get("price")
            unit_price = None
            if raw_price is not None and str(raw_price).strip() != "":
                try:
                    unit_price = float(str(raw_price).replace(".", "").replace(",", "."))
                except ValueError:
                    unit_price = None

            raw_compare = v.get("compare_at_price")
            list_price = None
            if raw_compare is not None and str(raw_compare).strip() != "":
                try:
                    list_price = float(str(raw_compare).replace(".", "").replace(",", "."))
                except ValueError:
                    list_price = None

            bruto_unitario = round(unit_price * IVA_FACTOR, 6) if unit_price is not None else None
            bruto_total = js_round(unit_price * qty_min * IVA_FACTOR) if unit_price is not None else None

            # Media: adjuntamos fotos y ficha técnica a cada variante, pero
            # descripción y es_principal solo al canonical.
            is_canonical = v_index == 0
            if not args.skip_media:
                # Fotos
                for i_img, img in enumerate(images):
                    src = img.get("src") if isinstance(img, dict) else None
                    src = str(src or "").strip()
                    if not src:
                        continue
                    media_jobs.append(
                        {
                            "id_externo": variant_id,
                            "url": src,
                            "tipo": "foto",
                            "subtipo": "principal" if i_img == 0 else "galeria",
                            "es_principal": bool(is_canonical and i_img == 0),
                        }
                    )

                # PDF / ficha
                for link in ficha_links:
                    media_jobs.append(
                        {
                            "id_externo": variant_id,
                            "url": link,
                            "tipo": "pdf",
                            "subtipo": "ficha",
                            "es_principal": bool(is_canonical),
                        }
                    )

            # Productos.jsonl
            descripcion = body_html if is_canonical else None
            producto_rows.append(
                {
                    "fuente": fuente_slug,
                    "id_externo": variant_id,
                    "id_grupo_externo": product_id,
                    "codigo_externo": sku or None,
                    "codigo_normalizado": codigo_normalizado or None,
                    "nombre": nombre,
                    "slug": handle,
                    "url_producto": url_producto,
                    "marca": vendor,
                    "id_marca": vendor,
                    "fabricante": vendor,
                    "categoria_id": None,
                    "id_division": "",
                    "id_seccion": "",
                    "id_categoria": chosen_id_categoria,
                    "nombre_division": "",
                    "nombre_seccion": "",
                    "nombre_categoria": chosen_name_categoria or "",
                    "unidad_min_venta": qty_unit,
                    "tipo_unidad": qty_unit,
                    "peso": None,
                    "stock": None,
                    "situacion": situacion,
                    "imagen_principal": principal_image or "",
                    "capturado_at": snapshot,
                    # Nueva columna (competencia phase41_grupos): solo canonical.
                    "descripcion": descripcion,
                }
            )

            precio_rows.append(
                {
                    "id_externo": variant_id,
                    "snapshot_fecha": snapshot,
                    "precio": unit_price,
                    "precio_lista": list_price,
                    "precio_bruto_unitario": bruto_unitario,
                    "precio_bruto_total": bruto_total,
                    "cantidad_min": qty_min,
                    "iva": IVA_FACTOR,
                    "costo": None,
                    "moneda": "CLP",
                    "oculto": 0,
                }
            )

            # Atributos
            orden = 1
            # Opciones
            for name, val in zip(option_names[:3], option_values[:3]):
                if name and val:
                    atributo_rows.append({"id_externo": variant_id, "titulo": name, "valor": val, "orden": orden})
                    orden += 1

            if product_type:
                atributo_rows.append({"id_externo": variant_id, "titulo": "product_type", "valor": product_type, "orden": orden})
                orden += 1

            if tags:
                atributo_rows.append(
                    {"id_externo": variant_id, "titulo": "tags", "valor": ", ".join([str(t) for t in tags if t]), "orden": orden}
                )

    # ---- Download media and build media JSONL ----
    medios_jsonl: list[dict] = []
    puente_rows_final: list[dict] = []

    if not args.skip_media and media_jobs:
        # Descarga por URL (dedup).
        download_jobs = [
            {"url": j["url"], "tipo": j["tipo"], "subtipo": j.get("subtipo")}
            for j in media_jobs
        ]
        # Extra: deduplicamos por URL para no descargar repetido.
        # (download_unique_media ya dedup por URL, así que pasamos jobs completos.)
        downloaded_by_url = download_unique_media(download_jobs, media_dir, workers=args.workers)

        for job in media_jobs:
            url = job["url"]
            downloaded = downloaded_by_url.get(url)
            if not downloaded:
                continue
            sha = downloaded["sha256"]
            if sha not in medio_rows_by_sha:
                # Clon para poder setear subtipo desde el job si vino como None.
                media_row = dict(downloaded)
                media_row["subtipo"] = job.get("subtipo")
                medio_rows_by_sha[sha] = media_row
            else:
                # Si ya existe, mantenemos subtipo si no estaba.
                if not medio_rows_by_sha[sha].get("subtipo") and job.get("subtipo"):
                    medio_rows_by_sha[sha]["subtipo"] = job.get("subtipo")

            puente_rows_final.append(
                {
                    "id_externo": str(job["id_externo"]),
                    "sha256": sha,
                    "es_principal": 1 if job.get("es_principal") else 0,
                    # Mantener el tipo real según descarga (pdf/foto) o fallback (link).
                    "tipo_multimedia": downloaded.get("tipo"),
                    "subtipo": job.get("subtipo"),
                }
            )

    # Si skip-media: generamos medios/puentes vacíos (import puede seguir).
    medios_jsonl = list(medio_rows_by_sha.values())
    puente_rows_final = puente_rows_final or []

    # ---- Write JSONL ----
    rewrite_jsonl(outdir / "categorias.jsonl", cat_rows)
    rewrite_jsonl(outdir / "productos.jsonl", producto_rows)
    rewrite_jsonl(outdir / "precios.jsonl", precio_rows)
    rewrite_jsonl(outdir / "atributos.jsonl", atributo_rows)
    rewrite_jsonl(outdir / "medios.jsonl", medios_jsonl)
    rewrite_jsonl(outdir / "producto_medio.jsonl", puente_rows_final)

    meta = {
        "fuente": fuente_slug,
        "generado_at": datetime.now(timezone.utc).isoformat(),
        "productos": len(producto_rows),
        "categorias": len(cat_rows),
        "medios_unicos": len(medio_rows_by_sha),
        "atributos": len(atributo_rows),
        "snapshot_fecha": snapshot,
        "skip_media": bool(args.skip_media),
    }
    write_json(outdir / "manifest.json", meta)

    log(
        f"[ok] DIMAFI normalizado: productos={len(producto_rows)} categorias={len(cat_rows)} "
        f"medios={len(medio_rows_by_sha)} atributos={len(atributo_rows)} -> {outdir}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

