#!/usr/bin/env python3
"""Genera codes-search-index.json y codes-search-data.js para el visualizador."""

import csv
import json
import os
from collections import defaultdict
from datetime import datetime
from glob import glob

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(ROOT, "DataBase Structure visualizer")
BARCODE_DIR = os.path.join(ROOT, "CodigosBarra")


def find_barcode_csv():
    pattern = os.path.join(BARCODE_DIR, "codigos_barras_*.csv")
    files = sorted(glob(pattern), reverse=True)
    return files[0] if files else None


def load_barcodes(csv_path):
    rows = []
    with open(csv_path, encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f, delimiter=";")
        for row in reader:
            sku = (row.get("sku") or "").strip()
            barcode = (row.get("barcode") or "").strip()
            if not sku or not barcode:
                continue
            rows.append(
                {
                    "sku": sku,
                    "barcode": barcode,
                    "fecha": (row.get("fecha") or "").strip(),
                }
            )
    return rows


def main():
    sku_map_path = os.path.join(ROOT, "data", "sku_mapping.json")
    codigos_path = os.path.join(ROOT, "data", "codigos_proveedores.json")
    barcode_csv = find_barcode_csv()

    with open(sku_map_path, encoding="utf-8") as f:
        sku_mapping = json.load(f)

    local_to_online = defaultdict(list)
    for online, local in sku_mapping.items():
        local_s = str(local).strip()
        if local_s:
            local_to_online[local_s].append(online.strip())

    records = []
    seen = set()
    conflicts = []
    local_to_online_map = dict(sku_mapping)
    online_to_local = defaultdict(set)

    for online, local in sku_mapping.items():
        online_u = online.strip().upper()
        local_s = str(local).strip()
        online_to_local[online_u].add(local_s)

    for local, onlines in local_to_online.items():
        uniq = sorted(set(onlines))
        if len(uniq) > 1:
            conflicts.append(
                {"kind": "multiple_online_same_local", "sku_local": local, "codes": uniq}
            )

    for online_u, locals_set in online_to_local.items():
        if len(locals_set) > 1:
            conflicts.append(
                {
                    "kind": "same_online_different_local",
                    "sku_online": online_u,
                    "sku_locals": sorted(locals_set),
                }
            )

    for online, local in sku_mapping.items():
        if online.upper() == str(local).upper():
            conflicts.append(
                {"kind": "online_equals_local", "code": online, "sku_local": str(local)}
            )

    def add_record(r):
        key = (
            r.get("type"),
            str(r.get("code", "")).upper(),
            str(r.get("sku_local", "")),
            str(r.get("proveedor_rut", "")),
            str(r.get("codigo_barras", "")),
        )
        if key in seen:
            return
        seen.add(key)
        records.append(r)

    for online, local in sku_mapping.items():
        add_record(
            {
                "type": "sku_online",
                "code": online,
                "sku_local": str(local),
                "sku_online": online,
                "codigo_proveedor": online,
                "source": "sku_mapping.json",
            }
        )
        add_record(
            {
                "type": "sku_local",
                "code": str(local),
                "sku_local": str(local),
                "sku_online": online,
                "codigo_proveedor": online,
                "source": "sku_mapping.json",
            }
        )

    codigo_index = defaultdict(list)
    if os.path.exists(codigos_path):
        with open(codigos_path, encoding="utf-8") as f:
            prov = json.load(f)
        for rut, pdata in prov.items():
            for c in pdata.get("codigos", []):
                cod = c.get("codigo", "")
                if not cod:
                    continue
                codigo_index[cod.upper()].append(rut)
                local = None
                for k, v in sku_mapping.items():
                    if k.upper() == cod.upper():
                        local = str(v)
                        break
                add_record(
                    {
                        "type": "codigo_proveedor",
                        "code": cod,
                        "codigo_proveedor": cod,
                        "sku_local": local,
                        "sku_online": cod if local else None,
                        "proveedor_rut": rut,
                        "proveedor_nombre": pdata.get("razon_social", ""),
                        "nombre": c.get("nombre", ""),
                        "tipo": c.get("tipo", "INT1"),
                        "source": "codigos_proveedores.json",
                    }
                )

    for cod, ruts in codigo_index.items():
        if len(set(ruts)) > 1:
            conflicts.append(
                {
                    "kind": "codigo_multi_proveedor",
                    "codigo_proveedor": cod,
                    "proveedores": sorted(set(ruts)),
                }
            )

    barcode_stats = {"file": None, "rows": 0}
    if barcode_csv:
        barcode_rows = load_barcodes(barcode_csv)
        barcode_stats["file"] = os.path.basename(barcode_csv)
        barcode_stats["rows"] = len(barcode_rows)
        barcode_to_skus = defaultdict(set)
        sku_to_barcodes = defaultdict(set)

        for row in barcode_rows:
            barcode_to_skus[row["barcode"]].add(row["sku"])
            sku_to_barcodes[row["sku"]].add(row["barcode"])

        for barcode, skus in barcode_to_skus.items():
            if len(skus) > 1:
                conflicts.append(
                    {
                        "kind": "barcode_multi_sku",
                        "codigo_barras": barcode,
                        "sku_locals": sorted(skus),
                    }
                )

        source_name = os.path.basename(barcode_csv)
        for row in barcode_rows:
            sku = row["sku"]
            barcode = row["barcode"]
            onlines = local_to_online.get(sku, [])
            add_record(
                {
                    "type": "codigo_barras",
                    "code": barcode,
                    "codigo_barras": barcode,
                    "sku_local": sku,
                    "sku_online": onlines[0] if len(onlines) == 1 else (onlines[0] if onlines else None),
                    "sku_online_alternativas": onlines if len(onlines) > 1 else None,
                    "fecha_etiqueta": row["fecha"] or None,
                    "source": source_name,
                }
            )

    payload = {
        "generated_at": datetime.now().isoformat(),
        "stats": {
            "records": len(records),
            "conflicts": len(conflicts),
            "barcodes": barcode_stats,
        },
        "records": records,
        "conflicts": conflicts,
    }

    json_path = os.path.join(OUT_DIR, "codes-search-index.json")
    js_path = os.path.join(OUT_DIR, "codes-search-data.js")

    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)

    with open(js_path, "w", encoding="utf-8") as f:
        f.write("window.CODES_SEARCH_INDEX = ")
        json.dump(payload, f, ensure_ascii=False)
        f.write(";\n")

    print(f"OK: {len(records)} registros, {len(conflicts)} conflictos")
    if barcode_stats["file"]:
        print(f"Barcodes: {barcode_stats['rows']} filas desde {barcode_stats['file']}")
    print(json_path)
    print(js_path)


if __name__ == "__main__":
    main()
