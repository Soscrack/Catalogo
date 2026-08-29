import re
from pathlib import Path

p = Path(__file__).resolve().parents[1] / "php/riverso-pos/templates/products.php"
text = p.read_text(encoding="utf-8")
m = re.search(r"<script>(.*?)</script>", text, re.S)
js = m.group(1)
js = re.sub(r"<\?php.*?\?>", "null", js, flags=re.S)
out = Path(__file__).resolve().parents[1] / "tmp_products.js"
out.write_text(js, encoding="utf-8")
print("written", out, len(js))
