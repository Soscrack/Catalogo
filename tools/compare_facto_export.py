#!/usr/bin/env python3
"""
Compara dos exports FACTO (xlsx) campo a campo.
Uso: python tools/compare_facto_export.py exportado.xlsx reexport_facto.xlsx [--sku 222433]
"""
import argparse
import sys

try:
    import pandas as pd
except ImportError:
    print("Requiere pandas: pip install pandas openpyxl", file=sys.stderr)
    sys.exit(1)

COMPARE_COLS = [
    "Categoria", "Nombre", "SKU", "Marca", "Modelo", "Unidad", "Código de barras",
    "Producto / Servicio", "Costo neto", "Venta: Precio neto",
    "Venta: afecto/exento de IVA", "Venta: Monto IVA", "Venta: Precio total",
    "Stock mínimo",
]


def norm(val):
    if val is None or (isinstance(val, float) and pd.isna(val)):
        return ""
    s = str(val).strip()
    if s.lower() in ("nan", "none"):
        return ""
    return s


def load(path):
    return pd.read_excel(path, sheet_name="Datos de producto", dtype=str)


def main():
    p = argparse.ArgumentParser(description="Comparar exports FACTO")
    p.add_argument("before", help="Archivo generado por Riverso")
    p.add_argument("after", help="Re-export desde FACTO tras import")
    p.add_argument("--sku", help="Comparar solo este SKU")
    args = p.parse_args()

    df_a = load(args.before)
    df_b = load(args.after)

    if args.sku:
        df_a = df_a[df_a["SKU"].astype(str).str.strip() == args.sku]
        df_b = df_b[df_b["SKU"].astype(str).str.strip() == args.sku]

    idx_a = {norm(r["SKU"]): r for _, r in df_a.iterrows() if norm(r.get("SKU"))}
    idx_b = {norm(r["SKU"]): r for _, r in df_b.iterrows() if norm(r.get("SKU"))}

    all_skus = sorted(set(idx_a) | set(idx_b))
    mismatches = 0
    for sku in all_skus:
        if sku not in idx_a:
            print(f"[FALTA en before] SKU {sku}")
            mismatches += 1
            continue
        if sku not in idx_b:
            print(f"[FALTA en after] SKU {sku}")
            mismatches += 1
            continue
        row_a, row_b = idx_a[sku], idx_b[sku]
        for col in COMPARE_COLS:
            if col not in row_a.index or col not in row_b.index:
                continue
            va, vb = norm(row_a[col]), norm(row_b[col])
            if va != vb:
                print(f"SKU {sku} | {col}: before={va!r} after={vb!r}")
                mismatches += 1

    if mismatches == 0:
        print("OK: columnas clave coinciden.")
    else:
        print(f"\nTotal diferencias: {mismatches}")
        sys.exit(1)


if __name__ == "__main__":
    main()
