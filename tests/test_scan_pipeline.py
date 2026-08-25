#!/usr/bin/env python3
"""
Prueba local del pipeline de escaneos (Gemini + validación).
No requiere WordPress; usa .env del repo.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENV_PATH = ROOT / ".env"
SCAN_DIR = Path(r"C:\Users\jorge\Downloads\escaneos")

SAMPLES = [
    "IMG_20260702_0001.pdf",       # 1 página, factura Soudal
    "IMG_20260817_0003.pdf",       # 2 páginas, misma factura
    "IMG_20260817_0009.pdf",       # 3 páginas, múltiples documentos
]


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    if not path.exists():
        return env
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()
    return env


def validate_rut(rut: str) -> bool:
    rut = re.sub(r"[^0-9kK]", "", rut.upper())
    if len(rut) < 2:
        return False
    dv, num = rut[-1], rut[:-1]
    if not num.isdigit():
        return False
    s, m = 0, 2
    for ch in reversed(num):
        s += int(ch) * m
        m = 2 if m == 7 else m + 1
    rest = 11 - (s % 11)
    exp = "0" if rest == 11 else ("K" if rest == 10 else str(rest))
    return dv == exp


def cuadratura_ok(tot: dict) -> bool:
    neto = float(tot.get("neto") or 0)
    exento = float(tot.get("exento") or 0)
    iva = float(tot.get("iva") or 0)
    flete = float(tot.get("flete") or 0)
    total = float(tot.get("total") or 0)
    if total <= 0:
        return True
    return abs(neto + exento + iva + flete - total) <= 2


def gemini_extract(api_key: str, model: str, pdf_path: Path) -> dict:
    data = base64.b64encode(pdf_path.read_bytes()).decode()
    prompt = (
        "Extrae documentos tributarios chilenos del PDF. "
        "Segmenta por folio/emisor. JSON con clave documentos[]. "
        "Incluye pagina_inicio, pagina_fin, tipo_dte, folio, emisor.rut, totales, items, referencias."
    )
    body = {
        "contents": [{
            "parts": [
                {"inline_data": {"mime_type": "application/pdf", "data": data}},
                {"text": prompt},
            ]
        }],
        "generationConfig": {"temperature": 0, "responseMimeType": "application/json"},
    }
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json", "x-goog-api-key": api_key},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=180) as resp:
        payload = json.loads(resp.read())
    text = payload["candidates"][0]["content"]["parts"][0]["text"]
    return json.loads(text)


def main() -> int:
    env = load_env(ENV_PATH)
    api_key = env.get("GEMINI_API_KEY", "")
    model = env.get("GEMINI_MODEL", "gemini-3.6-flash")
    if not api_key:
        print("ERROR: GEMINI_API_KEY no encontrada en .env")
        return 1
    if not SCAN_DIR.is_dir():
        print(f"ERROR: carpeta de escaneos no existe: {SCAN_DIR}")
        return 1

    results = []
    for name in SAMPLES:
        path = SCAN_DIR / name
        if not path.exists():
            print(f"SKIP: {name} no encontrado")
            continue
        file_hash = hashlib.sha256(path.read_bytes()).hexdigest()
        print(f"\n=== {name} sha256={file_hash[:16]}… ===")
        try:
            raw = gemini_extract(api_key, model, path)
        except Exception as e:
            print(f"FAIL Gemini: {e}")
            results.append({"file": name, "ok": False, "error": str(e)})
            continue
        docs = raw.get("documentos") or raw if isinstance(raw, list) else []
        if isinstance(raw, dict) and "documentos" in raw:
            docs = raw["documentos"]
        print(f"Documentos detectados: {len(docs)}")
        for i, doc in enumerate(docs, 1):
            folio = doc.get("folio")
            rut = (doc.get("emisor") or {}).get("rut", "")
            pag = f"{doc.get('pagina_inicio')}-{doc.get('pagina_fin')}"
            tot = doc.get("totales") or {}
            rut_ok = validate_rut(rut) if rut else None
            cuad_ok = cuadratura_ok(tot)
            print(f"  [{i}] pág {pag} folio={folio} rut={rut} rut_ok={rut_ok} cuadra={cuad_ok} total={tot.get('total')}")
            refs = doc.get("referencias") or []
            if refs:
                print(f"       refs: {[(r.get('tipo'), r.get('folio')) for r in refs[:4]]}")
        results.append({"file": name, "ok": True, "docs": len(docs)})

    out = ROOT / "tests" / "scan_test_results.json"
    out.write_text(json.dumps(results, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\nResultados guardados en {out}")
    failed = [r for r in results if not r.get("ok")]
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
