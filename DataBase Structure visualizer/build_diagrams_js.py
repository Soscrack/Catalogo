#!/usr/bin/env python3
import json
import re
from pathlib import Path

BASE = Path(__file__).parent
files = {
    "erd": ("diagrams/woocommerce-erd.svg", "arrow"),
    "sku": ("diagrams/sku-codigos-erd.svg", "a2"),
    "hierarchy": ("diagrams/hierarchy.svg", "a3"),
}

out = {}
for key, (rel, mid) in files.items():
    svg = (BASE / rel).read_text(encoding="utf-8", errors="replace")
    new_mid = f"mk-{key}"
    svg = svg.replace(f'id="{mid}"', f'id="{new_mid}"')
    svg = svg.replace(f"url(#{mid})", f"url(#{new_mid})")
    svg = svg.replace("\ufffd", "-")
    svg = re.sub(r"WooCommerce . Entidad", "WooCommerce - Entidad", svg)
    out[key] = svg

(BASE / "diagrams-inline.js").write_text(
    "window.DIAGRAM_SVGS = " + json.dumps(out, ensure_ascii=False) + ";\n",
    encoding="utf-8",
)
print("OK", {k: len(v) for k, v in out.items()})
