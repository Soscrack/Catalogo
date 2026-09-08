#!/usr/bin/env python
"""Importa legacy_precio_ref en producción: Excel local → SQL → mysql remoto."""
import argparse
import os
import sys
import tempfile

import paramiko

try:
    import pandas as pd
except ImportError:
    print('Instala pandas: pip install pandas openpyxl')
    sys.exit(1)

ROOT = os.path.dirname(os.path.abspath(__file__))
FUENTE = 'productos_xlsx_2026-09'
DEFAULT_XLSX = os.path.expanduser(r'~\Downloads\productos (6).xlsx')
IVA_FACTOR = 1.19


def load_env_file(path):
    if not os.path.isfile(path):
        return
    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            key = key.strip()
            value = value.strip().strip('"').strip("'")
            if key and key not in os.environ:
                os.environ[key] = value


def sql_val(value):
    if value is None:
        return 'NULL'
    if isinstance(value, float):
        if pd.isna(value):
            return 'NULL'
        return repr(float(value))
    s = str(value).replace('\\', '\\\\').replace("'", "\\'")
    return f"'{s}'"


def normalize_iva(value):
    s = (value or '').strip().lower()
    return 'exento' if s == 'exento' else 'afecto'


def net_from_gross(bruto, iva_tipo):
    if bruto is None:
        return None
    if normalize_iva(iva_tipo) == 'exento':
        return round(float(bruto), 4)
    return round(float(bruto) / IVA_FACTOR, 4)


def build_sql(df, table, costo_es_bruto=False):
    lines = [
        'SET NAMES utf8mb4;',
        'START TRANSACTION;',
    ]
    skipped = 0
    convertidos = 0
    for _, row in df.iterrows():
        sku = str(row.get('SKU', '')).strip()
        if not sku or sku == 'nan':
            skipped += 1
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
            s = str(v).strip()
            return s or None

        costo = fnum('Costo neto')
        if costo_es_bruto and costo is not None and costo > 0:
            iva = normalize_iva(fstr('Venta: afecto/exento de IVA'))
            neto = net_from_gross(costo, iva)
            if iva == 'afecto' and neto is not None and abs(neto - costo) > 0.00005:
                convertidos += 1
            costo = neto

        vals = [
            sql_val(sku),
            sql_val(fstr('Nombre')),
            sql_val(costo),
            sql_val(fnum('Venta: Precio neto')),
            sql_val(fnum('Venta: Precio total')),
            sql_val(fstr('Código de barras')),
            sql_val(fnum('Disponibilidad en: Bodega general')),
            sql_val(fnum('Disponibilidad en: Bodega de Cajas')),
            sql_val(fnum('Disponibilidad en: Otros productos')),
            sql_val(fnum('Stock mínimo')),
            sql_val(fstr('Unidad')),
            sql_val(fstr('Categoria')),
            sql_val(FUENTE),
        ]
        lines.append(
            f"INSERT INTO `{table}` "
            '(sku, nombre, costo_neto, precio_neto, precio_total, codigo_barras, '
            'stock_bodega_general, stock_bodega_cajas, stock_otros, stock_minimo, unidad, categoria, fuente) '
            f"VALUES ({', '.join(vals)}) "
            'ON DUPLICATE KEY UPDATE '
            'nombre=VALUES(nombre), costo_neto=VALUES(costo_neto), precio_neto=VALUES(precio_neto), '
            'precio_total=VALUES(precio_total), codigo_barras=VALUES(codigo_barras), '
            'stock_bodega_general=VALUES(stock_bodega_general), '
            'stock_bodega_cajas=VALUES(stock_bodega_cajas), stock_otros=VALUES(stock_otros), '
            'stock_minimo=VALUES(stock_minimo), '
            'unidad=VALUES(unidad), categoria=VALUES(categoria), importado_at=CURRENT_TIMESTAMP;'
        )
    lines.append('COMMIT;')
    return '\n'.join(lines) + '\n', skipped, convertidos


def run(ssh, cmd, timeout=900):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors='replace')
    err = stderr.read().decode(errors='replace')
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main():
    parser = argparse.ArgumentParser(description='Import legacy_precio_ref remoto desde Excel FACTO')
    parser.add_argument('--xlsx', default=DEFAULT_XLSX, help='Ruta al Excel de productos')
    parser.add_argument(
        '--costo-es-bruto',
        action='store_true',
        help='La columna Costo neto del Excel es en realidad bruto; convierte afecto ÷ 1.19',
    )
    args = parser.parse_args()

    load_env_file(os.path.join(ROOT, '.env.deploy'))

    host = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
    user = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
    password = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
    wp_path = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    xlsx = args.xlsx

    if not password:
        print('Falta RIVERSO_DEPLOY_PASSWORD en .env.deploy')
        sys.exit(1)
    if not os.path.isfile(xlsx):
        print(f'No existe Excel: {xlsx}')
        sys.exit(1)

    print(f'Leyendo Excel: {xlsx}')
    if args.costo_es_bruto:
        print('Modo --costo-es-bruto: convertirá costos afecto a neto (÷ 1.19)')
    df = pd.read_excel(xlsx, sheet_name='Datos de producto')
    costo_cero = int(((df['Costo neto'].fillna(0)) == 0).sum())
    print(f'Filas leídas: {len(df)} (costo 0: {costo_cero})')

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f'Conectando a {host}...')
    ssh.connect(host, username=user, password=password, timeout=30)
    print('Conectado.')

    php_bin = '$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)'
    wp_load = wp_path.replace("'", "'\\''")

    check_php = (
        f"sudo -u riverso.cl_1xybiw6rlcq {php_bin} -r '"
        f'require "{wp_load}/wp-load.php"; '
        'global $wpdb; '
        '$t = $wpdb->prefix . "riverso_legacy_precio_ref"; '
        'echo ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t) ? "table-ok" : "table-missing"; '
        'echo "|" . DB_HOST . "|" . DB_USER . "|" . DB_PASSWORD . "|" . DB_NAME . "|" . $wpdb->prefix . "riverso_";'
        "'"
    )
    code, info, err = run(ssh, check_php, timeout=60)
    info = info.strip()
    if err.strip():
        print('PHP stderr:', err[:500])
    if code != 0 or 'table-ok' not in info:
        print('Tabla legacy_precio_ref no disponible:', info or err)
        ssh.close()
        sys.exit(1)

    _, dbhost, dbuser, dbpass, dbname, rprefix = info.split('|')
    table = f'{rprefix}legacy_precio_ref'
    print(f'Tabla destino: {table}')

    dbhost_only = dbhost.split(':')[0]
    dbport = dbhost.split(':')[1] if ':' in dbhost else '3306'

    sql_body, skipped, convertidos = build_sql(df, table, costo_es_bruto=args.costo_es_bruto)
    print(f'SQL generado: {len(df) - skipped} inserts (omitidos sin SKU: {skipped})')
    if args.costo_es_bruto:
        print(f'Costos convertidos bruto→neto (afecto): {convertidos}')

    with tempfile.NamedTemporaryFile('w', suffix='.sql', delete=False, encoding='utf-8') as tmp:
        tmp.write(sql_body)
        local_sql = tmp.name

    remote_sql = '/tmp/legacy_precio_ref_import.sql'
    sftp = ssh.open_sftp()
    print('Subiendo SQL...')
    sftp.put(local_sql, remote_sql)
    sftp.close()
    os.unlink(local_sql)

    dbpass_shell = dbpass.replace("'", "'\\''")
    mysql_cmd = (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' '{dbname}' "
        f"< '{remote_sql}' && rm -f '{remote_sql}' && echo 'mysql-ok'"
    )
    print('Ejecutando import MySQL en servidor...')
    code, out, err = run(ssh, mysql_cmd, timeout=900)
    print(out.strip())
    if err.strip():
        err_clean = '\n'.join(
            ln for ln in err.splitlines() if 'Using a password on the command line' not in ln
        )
        if err_clean.strip():
            print('stderr:', err_clean[:2000])
    if code != 0:
        print(f'Import falló (exit {code})')
        ssh.close()
        sys.exit(code)

    count_php = (
        f"sudo -u riverso.cl_1xybiw6rlcq {php_bin} -r '"
        f'require "{wp_load}/wp-load.php"; '
        'global $wpdb; '
        '$t = $wpdb->prefix . "riverso_legacy_precio_ref"; '
        'echo (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t`");'
        "'"
    )
    _, count, _ = run(ssh, count_php, timeout=30)
    print(f'Filas en legacy_precio_ref: {count.strip()}')
    ssh.close()
    print('Import completado.')


if __name__ == '__main__':
    main()
