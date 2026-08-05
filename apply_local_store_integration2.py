#!/usr/bin/env python
"""Fix integrate local: ensure origen_datos + migrate pending SKUs."""
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
MYSQL = "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -N -e"
P = 'nExLU_riverso_'
TLP = f'{P}tienda_local_productos'
TLB = f'{P}tienda_local_barcodes'


def q(ssh, sql):
    sql_esc = sql.replace('"', '\\"')
    _, stdout, stderr = ssh.exec_command(f'{MYSQL} "{sql_esc}"', timeout=180)
    out = stdout.read().decode(errors='replace').strip()
    err = stderr.read().decode(errors='replace').strip()
    err = '\n'.join(ln for ln in err.splitlines() if 'Warning' not in ln)
    return out, err


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    cols, _ = q(
        ssh,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}producto_base' "
        "ORDER BY ORDINAL_POSITION"
    )
    print('producto_base cols sample:', ','.join(cols.splitlines()[:40]))

    if 'origen_datos' not in cols.splitlines():
        out, err = q(
            ssh,
            f"ALTER TABLE {P}producto_base ADD COLUMN origen_datos VARCHAR(64) DEFAULT NULL "
            f"COMMENT 'Fuente: xml, woo, tienda_local_legacy, etc' AFTER estado"
        )
        print('ADD origen_datos:', out or 'ok', err[:300] if err else '')

    # Also ensure requires_human_review / created_by_system exist
    needed = {
        'requires_human_review':
            f"ALTER TABLE {P}producto_base ADD COLUMN requires_human_review TINYINT(1) DEFAULT 0",
        'created_by_system':
            f"ALTER TABLE {P}producto_base ADD COLUMN created_by_system TINYINT(1) DEFAULT 0",
        'unidad_base':
            f"ALTER TABLE {P}producto_base ADD COLUMN unidad_base VARCHAR(32) DEFAULT 'unidad'",
        'nombre_canonico':
            f"ALTER TABLE {P}producto_base ADD COLUMN nombre_canonico VARCHAR(255) DEFAULT NULL",
        'canonical_sku':
            f"ALTER TABLE {P}producto_base ADD COLUMN canonical_sku VARCHAR(128) DEFAULT NULL",
        'estado':
            f"ALTER TABLE {P}producto_base ADD COLUMN estado VARCHAR(32) DEFAULT 'activo'",
    }
    cols2, _ = q(
        ssh,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}producto_base'"
    )
    colset = set(cols2.splitlines())
    for name, alter in needed.items():
        if name not in colset:
            out, err = q(ssh, alter)
            print(f'ADD {name}:', out or 'ok', err[:200] if err else '')

    # Re-check required columns for insert
    cols3, _ = q(
        ssh,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}producto_base'"
    )
    colset = set(cols3.splitlines())
    print('has origen_datos:', 'origen_datos' in colset)

    # Build insert with only existing columns
    fields = ['canonical_sku', 'nombre_canonico']
    selects = ['tlp.sku', 'tlp.nombre']
    for f, s in [
        ('unidad_base', "'unidad'"),
        ('estado', "'activo'"),
        ('created_by_system', '1'),
        ('requires_human_review', '1'),
        ('origen_datos', "'tienda_local_legacy'"),
    ]:
        if f in colset:
            fields.append(f)
            selects.append(s)

    insert = (
        f"INSERT INTO {P}producto_base ({', '.join(fields)}) "
        f"SELECT {', '.join(selects)} FROM {TLP} tlp "
        f"WHERE NOT EXISTS ("
        f" SELECT 1 FROM {P}producto_base pb "
        f" WHERE pb.canonical_sku = tlp.sku"
        f") "
        f"AND (tlp.sku IS NOT NULL AND tlp.sku <> '')"
    )
    # Avoid LIKE % which is slow/ambiguous; exact SKU match only for pending
    out, err = q(ssh, insert)
    print('insert bases:', out or 'ok')
    if err:
        print('ERR:', err[:500])

    # barcodes
    bar = (
        f"INSERT IGNORE INTO {P}codigo_barra "
        f"(codigo, tipo, producto_base_id, cantidad, unidad_medida, factor_a_unidad_base, activo, migrado_de_tabla) "
        f"SELECT tlb.barcode, 'internal', pb.id, 1, 'unidad', 1, 1, 'tienda_local_barcodes' "
        f"FROM {TLB} tlb "
        f"INNER JOIN {P}producto_base pb ON pb.canonical_sku = tlb.sku "
        f"WHERE pb.origen_datos = 'tienda_local_legacy' "
        f"AND tlb.barcode IS NOT NULL AND tlb.barcode <> ''"
    )
    out, err = q(ssh, bar)
    print('barcodes:', out or 'ok', err[:300] if err else '')

    # precios table check
    pcols, _ = q(
        ssh,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}precios'"
    )
    if pcols and 'p_asignado' in pcols.splitlines():
        price = (
            f"INSERT IGNORE INTO {P}precios "
            f"(producto_base_id, canal, woocommerce_variation_id, p_asignado, estado_aprobacion, created_by_system) "
            f"SELECT pb.id, 'local', 0, tlp.precio, 'aprobado', 1 "
            f"FROM {TLP} tlp INNER JOIN {P}producto_base pb ON pb.canonical_sku = tlp.sku "
            f"WHERE pb.origen_datos = 'tienda_local_legacy' AND tlp.precio IS NOT NULL AND tlp.precio > 0"
        )
        out, err = q(ssh, price)
        print('precios:', out or 'ok', err[:300] if err else '')
    else:
        print('precios table/cols skip:', pcols[:200] if pcols else 'missing')

    # mark integrated for exact SKU matches
    out, err = q(
        ssh,
        f"UPDATE {TLP} tlp SET integrated_at = NOW() "
        f"WHERE integrated_at IS NULL AND EXISTS ("
        f" SELECT 1 FROM {P}producto_base pb WHERE pb.canonical_sku = tlp.sku)"
    )
    print('mark:', out or 'ok', err[:200] if err else '')

    legacy, _ = q(ssh, f"SELECT COUNT(*) FROM {P}producto_base WHERE origen_datos='tienda_local_legacy'")
    integrated, _ = q(ssh, f"SELECT COUNT(*) FROM {TLP} WHERE integrated_at IS NOT NULL")
    pending, _ = q(ssh, f"SELECT COUNT(*) FROM {TLP} WHERE integrated_at IS NULL")
    total, _ = q(ssh, f"SELECT COUNT(*) FROM {TLP}")
    print(f'DONE total={total} integrated={integrated} pending={pending} legacy_bases={legacy}')
    ssh.close()


if __name__ == '__main__':
    main()
