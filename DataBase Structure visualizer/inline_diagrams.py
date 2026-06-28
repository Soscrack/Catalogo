"""Regenera DIAGRAM_SVGS incrustado en index.html desde diagrams-inline.js."""
from pathlib import Path

base = Path(__file__).parent
html = (base / "index.html").read_text(encoding="utf-8")
inline = (base / "diagrams-inline.js").read_text(encoding="utf-8").strip()

start = html.find("window.DIAGRAM_SVGS = ")
end = html.find("\n    const zoomState", start)
if start == -1 or end == -1:
    raise SystemExit("No se encontro bloque DIAGRAM_SVGS en index.html")

html = html[:start] + inline + html[end:]
(base / "index.html").write_text(html, encoding="utf-8")
print("diagrams inlined OK")
