"""Prueba la pantalla /interno/avisos/ con el usuario de pruebas del portal (.env).

Entra como ese usuario, revisa que la pantalla y el lector de barras estén servidos, y
pasa los códigos del corpus de fotos por el buscador de avisos: es la medición de cuántos
identifican un producto, hecha con la misma consulta que usa el teléfono.

Sin opciones solo lee. Con --escribir crea un aviso de prueba, se suma, lo revisa en
"Mis avisos" y lo retira (queda como descartado).

Uso: python tools/probar_avisos_portal.py [--escribir]
"""
import http.cookiejar
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
for raw in (ROOT / ".env").read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

PORTAL = os.environ["RIVERSO_PORTAL_URL"].rstrip("/")
SITE = PORTAL[: -len("/interno")] if PORTAL.endswith("/interno") else PORTAL
USER = os.environ["RIVERSO_PORTAL_USER"]
PASSWORD = os.environ["RIVERSO_PORTAL_PASS"]
WRITE = "--escribir" in sys.argv

# Códigos visibles en el corpus C:\Users\jorge\Downloads\Fotos.
CORPUS = {
    "EAN leídos por la cámara": (
        "camara",
        """7809831014728 7809831016098 7809831018092 7809831018184 7809831018696 7809831033644
        7809831065577 7809831074760 7809831148638 7809831157579 7809831191641 7809831196165
        4061975976819 4065746582193 4066443004025 4066443037122 7891799440688
        2000226001001 2000279001003""".split(),
    ),
    "Código corto Mamut": (
        "teclado",
        "01TRN 14CCM 29RLBC 14RTP 50ATPF-G 17RTPA 01TMPA 14RLPR 01TADB 02TADB 45ATPF-G 06MSA 17TMPA 08TFC 54TMPA 12TMPA F40ATPF-G 202TSN 80ATPF-G".split(),
    ),
    "Código Steelfix, como se digita": (
        "teclado",
        "000139 211119 000266 111176 000138 111115 111110 100321 100420 002347 312155 313115 313125 100627 100444".split(),
    ),
    "Artículo Würth": (
        "teclado",
        ["0672 200 040", "5986 000 029", "5986 212 181", "0577 116 040", "0577 116 120", "0617 401 100"],
    ),
    "Número manuscrito (SKU local)": (
        "teclado",
        "24574 24721 24722 24986 24987 22304 20552 24761 21274 279 226".split(),
    ),
}

jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
opener.addheaders = [("User-Agent", "riverso-avisos-test/1.0")]


def fetch(url, data=None, method=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, method=method)
    try:
        with opener.open(req, timeout=40) as res:
            return res.status, res.headers, res.read()
    except urllib.error.HTTPError as err:
        return err.code, err.headers, err.read()


def ajax(cfg, action, **fields):
    fields.update(action=cfg["actions"][action], nonce=cfg["nonce"])
    status, _, raw = fetch(cfg["ajaxUrl"], fields)
    try:
        res = json.loads(raw.decode("utf-8", "replace"))
    except ValueError:
        raise RuntimeError(f"{action}: respuesta no JSON (HTTP {status}): {raw[:160]!r}")
    if not res.get("success"):
        raise RuntimeError(f"{action}: {(res.get('data') or {}).get('message', res)}")
    return res["data"]


def main():
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    print(f"Portal: {PORTAL}  usuario: {USER}")

    fetch(SITE + "/wp-login.php")
    fetch(SITE + "/wp-login.php", {
        "log": USER, "pwd": PASSWORD, "wp-submit": "Acceder",
        "redirect_to": PORTAL + "/", "testcookie": "1",
    })
    if not any(c.name.startswith("wordpress_logged_in") for c in jar):
        print("FALLA: no se pudo iniciar sesión con RIVERSO_PORTAL_USER / RIVERSO_PORTAL_PASS.")
        return 1
    print("  ok   sesión iniciada")

    status, _, raw = fetch(PORTAL + "/avisos/")
    html = raw.decode("utf-8", "replace")
    match = re.search(r"window\.riversoAvisos\s*=\s*(\{.*?\});\s*</script>", html, re.S)
    if status != 200 or 'id="riverso-av"' not in html or not match:
        if "No tienes permiso para avisar" in html:
            print("FALLA: el usuario de pruebas no tiene permiso para avisar (riverso_report_shortage).")
        elif "falta la migración" in html:
            print("FALLA: la pantalla existe pero faltan las tablas (migración fase 75).")
        else:
            print(f"FALLA: /interno/avisos/ no muestra la pantalla (HTTP {status}). ¿Está desplegado el plugin 1.8.61?")
        return 1
    cfg = json.loads(match.group(1))
    print(f"  ok   pantalla cargada (bandeja: {'sí' if cfg.get('canManage') else 'no'}; avisos abiertos: {cfg['counts']['abiertos']})")
    print(f"  {'ok  ' if 'dashicons-megaphone' in html else 'FALLA'} aparece en el menú del portal")

    scanner = re.search(r'src="([^"]*/js/barcode-scanner\.js[^"]*)"', html)
    if not scanner:
        print("  FALLA la pantalla no carga barcode-scanner.js")
        return 1
    vendor = scanner.group(1).split("/js/barcode-scanner.js")[0] + "/vendor/zxing-wasm/"
    for label, url in (("avisos.js", re.search(r'src="([^"]*avisos\.js[^"]*)"', html).group(1)),
                       ("lector compartido", scanner.group(1)),
                       ("lector de barras (JS)", vendor + "zxing-reader.iife.js"),
                       ("lector de barras (WASM)", vendor + "zxing_reader.wasm")):
        st, headers, body = fetch(url)
        print(f"  {'ok  ' if st == 200 and len(body) > 1000 else 'FALLA'} {label}: HTTP {st}, {len(body)} bytes, {headers.get('Content-Type')}")
    policy = fetch(PORTAL + "/avisos/")[1].get("Permissions-Policy") or ""
    blocked = re.search(r"camera=\(\s*\)", policy)
    print(f"  {'FALLA' if blocked else 'ok  '} cabecera Permissions-Policy {'bloquea la cámara: ' + policy if blocked else 'no bloquea la cámara'}")

    print("\nCódigos del corpus contra el buscador de avisos")
    total_ok = total = 0
    for group, (origen, codes) in CORPUS.items():
        hits = 0
        lines = []
        for code in codes:
            data = ajax(cfg, "resolve", q=code, origen=origen)
            cands = data.get("candidatos") or []
            exact = data.get("exactos", 0)
            hits += 1 if exact >= 1 else 0
            if exact >= 1:
                first = cands[0]
                extra = f" (+{exact - 1} más)" if exact > 1 else ""
                supplier = (first.get("proveedores") or [{}])[0].get("nombre", "sin proveedor")
                lines.append(f"    {code:16} SKU {first['sku'] or '-':8} {first['nombre'][:44]:44} [{first['fuente_label']}; {supplier[:22]}]{extra}")
            else:
                lines.append(f"    {code:16} sin coincidencia exacta" + (f" ({len(cands)} por nombre)" if cands else ""))
        total_ok += hits
        total += len(codes)
        print(f"  {group}: {hits} de {len(codes)}")
        print("\n".join(lines))
    print(f"  Total: {total_ok} de {total} códigos identifican un producto.")

    mine = ajax(cfg, "mine")["avisos"]
    print(f"\n  ok   Mis avisos responde ({len(mine)})")
    if cfg.get("canManage"):
        tray = ajax(cfg, "list", estado="abiertos")
        print(f"  ok   Bandeja responde: {sum(len(g['avisos']) for g in tray['grupos'])} abiertos en {len(tray['grupos'])} proveedores")

    if not WRITE:
        print("\nSolo lectura. Con --escribir se crea, apoya y retira un aviso de prueba.")
        return 0

    print("\nEscritura (aviso de prueba)")
    created = ajax(cfg, "create", texto="PRUEBA automática: se puede borrar", nota="Creado por tools/probar_avisos_portal.py",
                   cantidad="2", unidad="caja", origen_codigo="teclado")["aviso"]
    print(f"  ok   creado #{created['id']}: {created['titulo']} · {created['cantidad']} {created['unidad_label']}")
    try:
        try:
            ajax(cfg, "create", texto="PRUEBA sin unidad", cantidad="3")
            print("  FALLA cantidad sin unidad fue aceptada")
        except RuntimeError as err:
            print(f"  ok   cantidad sin unidad se rechaza ({err})")
        joined = ajax(cfg, "join", id=created["id"], sin_stock="1")["aviso"]
        print(f"  ok   sumarse marca 'no queda': {joined['sin_stock']}")
        found = any(a["id"] == created["id"] for a in ajax(cfg, "mine")["avisos"])
        print(f"  {'ok  ' if found else 'FALLA'} aparece en Mis avisos")
    finally:
        gone = ajax(cfg, "update", id=created["id"], op="descartar", motivo="error")["aviso"]
        print(f"  ok   retirado: estado {gone['estado']} ({gone['motivo_label']})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
