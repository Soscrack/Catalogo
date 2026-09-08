#!/usr/bin/env python3
"""Reconstruye TPV/productos_legacy.csv desde productos_tpv.xlsx.

Parsea formato chileno (1.450,00) y escribe precio/coste con 3 decimales.
Copia el CSV al plugin (data/tpv/productos_legacy.csv).
"""
import csv
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
XLSX = ROOT / 'TPV' / 'productos_tpv.xlsx'
CSV_OUT = ROOT / 'TPV' / 'productos_legacy.csv'
PLUGIN_CSV = ROOT / 'php' / 'riverso-pos' / 'data' / 'tpv' / 'productos_legacy.csv'


def parse_clp(value):
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return round(float(value), 3)
    s = str(value).strip().replace('\xa0', '').replace(' ', '')
    if not s or s.lower() == 'nan':
        return None
    if ',' in s and '.' in s:
        s = s.replace('.', '').replace(',', '.')
    elif ',' in s:
        s = s.replace(',', '.')
    try:
        return round(float(s), 3)
    except ValueError:
        return None


def fmt3(value):
    if value is None:
        return ''
    return f'{value:.3f}'


def main():
    if not XLSX.is_file():
        raise SystemExit(f'No existe {XLSX}')

    df = pd.read_excel(XLSX, sheet_name='productos')
    rows = []
    skipped = 0
    for _, raw in df.iterrows():
        sku = str(raw.get('sku', '')).strip()
        if not sku or sku.lower() == 'nan':
            skipped += 1
            continue
        nombre = str(raw.get('nombre', '') or '').strip()
        precio = parse_clp(raw.get('precio'))
        coste = parse_clp(raw.get('coste'))
        rows.append({
            'sku': sku,
            'nombre': nombre,
            'precio': fmt3(precio),
            'coste': fmt3(coste),
        })

    rows.sort(key=lambda r: r['sku'])
    CSV_OUT.parent.mkdir(parents=True, exist_ok=True)
    PLUGIN_CSV.parent.mkdir(parents=True, exist_ok=True)
    for dest in (CSV_OUT, PLUGIN_CSV):
        with dest.open('w', encoding='utf-8', newline='') as handle:
            writer = csv.DictWriter(handle, fieldnames=['sku', 'nombre', 'precio', 'coste'])
            writer.writeheader()
            writer.writerows(rows)

    print(f'Filas: {len(rows)} omitidas: {skipped}')
    print(f'Escrito: {CSV_OUT}')
    print(f'Escrito: {PLUGIN_CSV}')
    if rows:
        print('Ejemplo:', rows[0])


if __name__ == '__main__':
    main()
