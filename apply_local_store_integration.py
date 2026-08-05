#!/usr/bin/env python
"""Completar ítem 4: integrate_local_store_products vía MySQL (wp-load bloqueado)."""
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
MYSQL = "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -N -e"
P = 'nExLU_riverso_'


def q(ssh, sql):
    sql_esc = sql.replace('"', '\\"')
    _, stdout, stderr = ssh.exec_command(f'{MYSQL} "{sql_esc}"')
    out = stdout.read().decode(errors='replace').strip()
    err = stderr.read().decode(errors='replace').strip()
    err = '\n'.join(ln for ln in err.splitlines() if 'Warning' not in ln)
    return out, err


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    tables, _ = q(
        ssh,
        "SELECT table_name FROM information_schema.tables "
        "WHERE table_schema=DATABASE() AND table_name LIKE '%tienda_local%'"
    )
    print('tables:', tables or '(none)')
    if not tables:
        print('SKIP: no tienda_local tables')
        ssh.close()
        return

    # Prefer prefixed names
    tlp = f'{P}tienda_local_productos'
    tlb = f'{P}tienda_local_barcodes'
    if tlp not in tables.replace('\n', ' '):
        # try without riverso middle
        for cand in tables.splitlines():
            if cand.endswith('tienda_local_productos'):
                tlp = cand
            if cand.endswith('tienda_local_barcodes'):
                tlb = cand

    print('using', tlp, tlb)

    out, err = q(
        ssh,
        f"ALTER TABLE {tlp} ADD COLUMN integrated_at DATETIME DEFAULT NULL "
        f"COMMENT 'Migracion a producto_base'"
    )
    print('ADD COLUMN:', out or 'ok', err or '')

    # Count pending
    pending, _ = q(
        ssh,
        f"SELECT COUNT(*) FROM {tlp} tlp WHERE NOT EXISTS ("
        f" SELECT 1 FROM {P}producto_base pb "
        f" WHERE pb.canonical_sku = tlp.sku OR pb.canonical_sku LIKE CONCAT('%', tlp.sku, '%')"
        f")"
    )
    print('pending unmatched:', pending)

    # Insert missing producto_base (limit 100 like activator)
    # Use INSERT...SELECT for rows without match
    insert_sql = f"""
INSERT INTO {P}producto_base
  (canonical_sku, nombre_canonico, unidad_base, estado, created_by_system,
   requires_human_review, origen_datos)
SELECT tlp.sku, tlp.nombre, 'unidad', 'activo', 1, 1, 'tienda_local_legacy'
FROM {tlp} tlp
WHERE NOT EXISTS (
  SELECT 1 FROM {P}producto_base pb
  WHERE pb.canonical_sku = tlp.sku OR pb.canonical_sku LIKE CONCAT('%', tlp.sku, '%')
)
LIMIT 100
"""
    # MySQL may not allow LIMIT in INSERT SELECT subquery on some versions - try without LIMIT first with a safer approach
    # Use a temp table approach
    out, err = q(ssh, insert_sql.replace('\n', ' '))
    if err and 'LIMIT' in err.upper():
        insert_sql2 = insert_sql.replace('LIMIT 100', '')
        out, err = q(ssh, insert_sql2.replace('\n', ' '))
    print('insert producto_base:', out or 'ok', err or '')

    # Barcodes for newly created
    if 'tienda_local_barcodes' in tables or tlb in tables.replace('\n', ' '):
        bar_sql = f"""
INSERT IGNORE INTO {P}codigo_barra
  (codigo, tipo, producto_base_id, cantidad, unidad_medida, factor_a_unidad_base, activo, migrado_de_tabla)
SELECT tlb.barcode, 'internal', pb.id, 1, 'unidad', 1, 1, 'tienda_local_barcodes'
FROM {tlb} tlb
INNER JOIN {P}producto_base pb ON pb.canonical_sku = tlb.sku
WHERE pb.origen_datos = 'tienda_local_legacy'
  AND tlb.barcode IS NOT NULL AND tlb.barcode <> ''
"""
        out, err = q(ssh, bar_sql.replace('\n', ' '))
        print('insert barcodes:', out or 'ok', err or '')

    # Prices if column exists
    cols, _ = q(
        ssh,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{tlp}'"
    )
    print('tlp cols:', cols.replace('\n', ','))
    if 'precio' in cols.splitlines():
        # Detect precios table columns
        pcols, _ = q(
            ssh,
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}precios'"
        )
        if pcols:
            price_sql = f"""
INSERT IGNORE INTO {P}precios
  (producto_base_id, canal, woocommerce_variation_id, p_asignado, estado_aprobacion, created_by_system)
SELECT pb.id, 'local', 0, tlp.precio, 'aprobado', 1
FROM {tlp} tlp
INNER JOIN {P}producto_base pb ON pb.canonical_sku = tlp.sku
WHERE pb.origen_datos = 'tienda_local_legacy'
  AND tlp.precio IS NOT NULL AND tlp.precio > 0
"""
            out, err = q(ssh, price_sql.replace('\n', ' '))
            print('insert precios:', out or 'ok', err or '')

    # Mark integrated
    out, err = q(
        ssh,
        f"UPDATE {tlp} tlp SET integrated_at = NOW() "
        f"WHERE integrated_at IS NULL AND EXISTS ("
        f" SELECT 1 FROM {P}producto_base pb "
        f" WHERE pb.canonical_sku = tlp.sku OR pb.canonical_sku LIKE CONCAT('%', tlp.sku, '%')"
        f")"
    )
    print('mark integrated:', out or 'ok', err or '')

    legacy, _ = q(ssh, f"SELECT COUNT(*) FROM {P}producto_base WHERE origen_datos='tienda_local_legacy'")
    integrated, _ = q(ssh, f"SELECT COUNT(*) FROM {tlp} WHERE integrated_at IS NOT NULL")
    pending2, _ = q(ssh, f"SELECT COUNT(*) FROM {tlp} WHERE integrated_at IS NULL")
    print(f'RESULT legacy_bases={legacy} integrated={integrated} pending={pending2}')
    ssh.close()


if __name__ == '__main__':
    main()
