#!/usr/bin/env python
"""Importa productos legacy (Excel) a riverso_legacy_precio_ref — solo referencia."""
import argparse
import os
import sys

try:
    import pandas as pd
except ImportError:
    print('Instala pandas: pip install pandas openpyxl')
    sys.exit(1)

try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass

DEFAULT_XLSX = os.path.expanduser(r'~\Downloads\productos (3).xlsx')
FUENTE = 'productos_xlsx_2026-08'


def get_connection():
    """MySQL vía variables de entorno o .env."""
    import pymysql

    host = os.getenv('DB_HOST', os.getenv('MYSQL_HOST', '127.0.0.1'))
    user = os.getenv('DB_USER', os.getenv('MYSQL_USER', 'root'))
    password = os.getenv('DB_PASSWORD', os.getenv('MYSQL_PASSWORD', ''))
    database = os.getenv('DB_NAME', os.getenv('MYSQL_DATABASE', ''))
    port = int(os.getenv('DB_PORT', os.getenv('MYSQL_PORT', '3306')))
    prefix = os.getenv('WP_TABLE_PREFIX', 'wp_riverso_')

    if not database:
        raise RuntimeError('Define DB_NAME o MYSQL_DATABASE en .env')

    conn = pymysql.connect(
        host=host,
        user=user,
        password=password,
        database=database,
        port=port,
        charset='utf8mb4',
    )
    return conn, prefix


def main():
    parser = argparse.ArgumentParser(description='Import legacy price reference from Excel')
    parser.add_argument('--xlsx', default=DEFAULT_XLSX, help='Ruta al Excel de productos')
    parser.add_argument('--dry-run', action='store_true', help='No escribe en BD')
    args = parser.parse_args()

    if not os.path.isfile(args.xlsx):
        print(f'Archivo no encontrado: {args.xlsx}')
        sys.exit(1)

    df = pd.read_excel(args.xlsx, sheet_name='Datos de producto')
    print(f'Filas leídas: {len(df)}')

    if args.dry_run:
        costo_cero = int(((df['Costo neto'].fillna(0)) == 0).sum())
        print(f'DRY-RUN: se importarían {len(df)} filas')
        print(f'Registros con costo 0 (referencia sin dato): {costo_cero}')
        return

    conn, prefix = get_connection()
    table = f'{prefix}legacy_precio_ref'
    cur = conn.cursor()

    inserted = 0
    updated = 0
    for _, row in df.iterrows():
        sku = str(row.get('SKU', '')).strip()
        if not sku or sku == 'nan':
            continue

        def fnum(col):
            v = row.get(col)
            if pd.isna(v):
                return None
            try:
                return float(v)
            except (TypeError, ValueError):
                return None

        def fstr(col):
            v = row.get(col)
            if pd.isna(v):
                return None
            return str(v).strip() or None

        data = (
            sku,
            fstr('Nombre'),
            fnum('Costo neto'),
            fnum('Venta: Precio neto'),
            fnum('Venta: Precio total'),
            fstr('Código de barras'),
            fnum('Disponibilidad en: Bodega general'),
            fnum('Disponibilidad en: Bodega de Cajas'),
            fnum('Disponibilidad en: Otros productos'),
            fnum('Stock mínimo'),
            fstr('Unidad'),
            fstr('Categoria'),
            FUENTE,
        )

        sql = f"""
            INSERT INTO `{table}`
              (sku, nombre, costo_neto, precio_neto, precio_total, codigo_barras,
               stock_bodega_general, stock_bodega_cajas, stock_otros, stock_minimo, unidad, categoria, fuente)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE
              nombre=VALUES(nombre),
              costo_neto=VALUES(costo_neto),
              precio_neto=VALUES(precio_neto),
              precio_total=VALUES(precio_total),
              codigo_barras=VALUES(codigo_barras),
              stock_bodega_general=VALUES(stock_bodega_general),
              stock_bodega_cajas=VALUES(stock_bodega_cajas),
              stock_otros=VALUES(stock_otros),
              stock_minimo=VALUES(stock_minimo),
              unidad=VALUES(unidad),
              categoria=VALUES(categoria),
              importado_at=CURRENT_TIMESTAMP
        """
        cur.execute(sql, data)
        if cur.rowcount == 1:
            inserted += 1
        else:
            updated += 1

    if not args.dry_run:
        conn.commit()
    conn.close()

    costo_cero = int(((df['Costo neto'].fillna(0)) == 0).sum())
    print(f'Importados/actualizados: {inserted + updated} (insert ~{inserted}, update ~{updated})')
    print(f'Registros con costo 0 en Excel (referencia sin dato): {costo_cero}')
    print('Listo.')


if __name__ == '__main__':
    main()
