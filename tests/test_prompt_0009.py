#!/usr/bin/env python3
"""Prueba rápida del prompt refinado en IMG_20260817_0009.pdf (3 páginas, 2+ docs)."""
import base64
import json
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env = {}
for line in (ROOT / ".env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1)
        env[k.strip()] = v.strip()

path = Path(r"C:\Users\jorge\Downloads\escaneos\IMG_20260817_0009.pdf")
data = base64.b64encode(path.read_bytes()).decode()

prompt = """Eres un extractor experto de documentos comerciales y tributarios chilenos a partir de PDFs escaneados. Usa VISIÓN, no OCR embebido.

Receptor: RUT 76.443.852-3 COMERCIALIZADORA YUBINZA RIVERA RAMIREZ EIRL.

SEGMENTACIÓN: identifica cada página. Agrupa páginas consecutivas solo si mismo RUT emisor + folio + tipo. Separa cuando cambia folio/emisor/tipo. Página "Detalle Embalaje del Despacho" o "NV:" es documento separado (nota_venta). Un PDF de 3 páginas puede tener 2-3 documentos.

Folio sin puntos (1.291.575→1291575). Montos: punto=miles (58.220→58220). Referencias tipo: guia_despacho, orden_compra, nro_pedido, factura_electronica.

Devuelve JSON con array documentos incluyendo pagina_inicio, pagina_fin, tipo_documento, tipo_dte, folio, emisor, totales, items, referencias, confianza_global."""

body = {
    "contents": [{"parts": [
        {"inline_data": {"mime_type": "application/pdf", "data": data}},
        {"text": prompt},
    ]}],
    "generationConfig": {"temperature": 0, "responseMimeType": "application/json"},
}
model = env.get("GEMINI_MODEL", "gemini-3.6-flash")
url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
req = urllib.request.Request(
    url,
    data=json.dumps(body).encode(),
    headers={"Content-Type": "application/json", "x-goog-api-key": env["GEMINI_API_KEY"]},
    method="POST",
)
with urllib.request.urlopen(req, timeout=180) as resp:
    payload = json.loads(resp.read())
text = payload["candidates"][0]["content"]["parts"][0]["text"]
raw = json.loads(text)
docs = raw.get("documentos", raw) if isinstance(raw, dict) else raw
if not isinstance(docs, list):
    docs = []
print(f"Documentos detectados: {len(docs)}")
for i, d in enumerate(docs, 1):
    tot = d.get("totales") or {}
    print(
        f"  [{i}] pág {d.get('pagina_inicio')}-{d.get('pagina_fin')} "
        f"{d.get('tipo_documento')} folio={d.get('folio')} total={tot.get('total')}"
    )
