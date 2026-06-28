"""Incrusta codes-search-data.js en index.html para uso offline (file://)."""
from pathlib import Path

base = Path(__file__).parent
html = (base / "index.html").read_text(encoding="utf-8")
search_js = (base / "codes-search-data.js").read_text(encoding="utf-8").strip()

external = '  <script src="codes-search-data.js"></script>'
inline_block = f"  <script>\n{search_js}\n  </script>"

if external in html:
    html = html.replace(external, inline_block)
elif "window.CODES_SEARCH_INDEX" not in html:
    html = html.replace("<style>", f"{inline_block}\n  <style>", 1)

(base / "index.html").write_text(html, encoding="utf-8")
print("search data inlined into index.html")
