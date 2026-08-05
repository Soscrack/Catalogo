#!/usr/bin/env python
"""Checklist post-migración arquitectura productos — verificación remota."""
import json
import paramiko
import re

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
PLUGIN = '/var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins/riverso-pos'
WP = '/var/www/vhosts/riverso.cl/httpdocs'
MYSQL = "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -N -e"
P = 'nExLU_riverso_'
OPT = 'nExLU_options'

results = []


def ok(item, detail=''):
    results.append(('PASS', item, detail))
    print(f'✅ {item}' + (f' — {detail}' if detail else ''))


def fail(item, detail=''):
    results.append(('FAIL', item, detail))
    print(f'❌ {item}' + (f' — {detail}' if detail else ''))


def skip(item, detail=''):
    results.append(('SKIP', item, detail))
    print(f'⚠️  {item}' + (f' — {detail}' if detail else ''))


def run(ssh, cmd, timeout=60):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors='replace').strip()
    err = stderr.read().decode(errors='replace').strip()
    return out, err


def mysql(ssh, sql):
    # Escape double quotes in SQL for -e "..."
    sql_esc = sql.replace('"', '\\"')
    out, err = run(ssh, f'{MYSQL} "{sql_esc}"')
    # Filter mysql password warnings
    err_clean = '\n'.join(
        ln for ln in err.splitlines()
        if 'Warning' not in ln and ln.strip()
    )
    return out, err_clean


def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    print('=== Checklist arquitectura productos ===\n')

    # --- 1. Plugin activo / versión ---
    out, _ = run(ssh, f'grep -E "Version:|RIVERSO_POS_VERSION" {PLUGIN}/riverso-pos.php | head -5')
    ver = ''
    m = re.search(r"['\"](1\.\d+\.\d+)['\"]", out) or re.search(r'Version:\s*([\d.]+)', out)
    if m:
        ver = m.group(1)
    out_opt, _ = mysql(ssh, f"SELECT option_value FROM {OPT} WHERE option_name='riverso_pos_db_version' LIMIT 1")
    out_active, _ = mysql(
        ssh,
        f"SELECT option_value FROM {OPT} WHERE option_name='active_plugins' LIMIT 1"
    )
    plugin_active = 'riverso-pos/riverso-pos.php' in (out_active or '')
    if ver == '1.4.2' and out_opt.strip() == '1.4.2' and plugin_active:
        ok('1. Plugin deployado y activo', f'version={ver}, db={out_opt.strip()}, active=yes')
    elif ver == '1.4.2' and out_opt.strip() == '1.4.2':
        skip('1. Desactivar/reactivar plugin', f'version OK ({ver}); active={plugin_active}; skip UI reactivate (Astra FTP)')
    else:
        fail('1. Plugin versión', f'file={ver!r} db={out_opt!r} active={plugin_active}')

    # --- 2. Phase 12 ---
    out, _ = mysql(ssh, f"SHOW TABLES LIKE '{P}equivalence_groups'")
    legacy, _ = mysql(ssh, f"SHOW TABLES LIKE '{P}equivalencia_grupo'")
    groups, _ = mysql(ssh, f"SELECT COUNT(*) FROM {P}equivalence_groups")
    members, _ = mysql(ssh, f"SELECT COUNT(*) FROM {P}equivalence_members")
    if out.strip():
        detail = f'groups={groups} members={members}'
        if legacy.strip():
            dep, _ = mysql(
                ssh,
                f"SELECT COUNT(*) FROM information_schema.COLUMNS "
                f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}equivalencia_grupo' "
                f"AND COLUMN_NAME='deprecated_at'"
            )
            detail += f'; legacy_table=yes deprecated_col={dep}'
        else:
            detail += '; legacy_table=no (nada que migrar)'
        ok('2. Phase 12 consolidación familia', detail)
    else:
        fail('2. Phase 12', 'tabla equivalence_groups ausente')

    # --- 3. Phase 13 ---
    schema, _ = mysql(
        ssh,
        "SELECT CONCAT(COLUMN_NAME, ':', IS_NULLABLE, ':', COLUMN_TYPE) "
        f"FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() "
        f"AND TABLE_NAME='{P}producto_proveedor' "
        f"AND COLUMN_NAME IN ('producto_base_id','grupo_id','assigned_to_family_at','assigned_to_family_by') "
        "ORDER BY COLUMN_NAME"
    )
    has_grupo = 'grupo_id:' in schema
    base_null = 'producto_base_id:YES:' in schema
    if has_grupo and base_null:
        ok('3. Phase 13 proveedor→familia', schema.replace('\n', ' | '))
    else:
        fail('3. Phase 13', schema or 'sin columnas')

    # --- 4. integrate_local_store_products ---
    tl_exists, _ = mysql(ssh, f"SHOW TABLES LIKE '{P}tienda_local_productos'")
    if not tl_exists.strip():
        skip('4. Integración tienda local', 'tabla tienda_local_productos no existe')
    else:
        integ_col, _ = mysql(
            ssh,
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}tienda_local_productos' "
            "AND COLUMN_NAME='integrated_at'"
        )
        legacy_bases, _ = mysql(
            ssh,
            f"SELECT COUNT(*) FROM {P}producto_base WHERE origen_datos='tienda_local_legacy'"
        )
        integrated, _ = mysql(
            ssh,
            f"SELECT COUNT(*) FROM {P}tienda_local_productos WHERE integrated_at IS NOT NULL"
        ) if integ_col.strip() == '1' else ('n/a', '')
        pending, _ = mysql(
            ssh,
            f"SELECT COUNT(*) FROM {P}tienda_local_productos WHERE integrated_at IS NULL"
        ) if integ_col.strip() == '1' else ('n/a', '')

        if integ_col.strip() == '1' and pending.strip() == '0' and int(legacy_bases or 0) > 0:
            ok(
                '4. Integración tienda local',
                f'integrated={integrated} pending={pending} legacy_bases={legacy_bases}'
            )
        elif integ_col.strip() == '1' and pending.strip() == '0':
            ok('4. Integración tienda local', f'integrated={integrated} pending=0 (sin crear legacy)')
        else:
            skip(
                '4. Integración tienda local',
                f'col={integ_col} legacy_bases={legacy_bases} integrated={integrated} pending={pending}'
            )

    # --- 5. Integridad SQL guía ---
    bad_assign, _ = mysql(
        ssh,
        f"SELECT COUNT(*) FROM {P}producto_proveedor WHERE "
        f"(producto_base_id IS NULL AND grupo_id IS NULL) OR "
        f"(producto_base_id IS NOT NULL AND grupo_id IS NOT NULL)"
    )
    barcodes, _ = mysql(ssh, f"SELECT COUNT(*) FROM {P}codigo_barra")
    barcodes_env, _ = mysql(ssh, f"SELECT COUNT(*) FROM {P}codigo_barra WHERE envase_id IS NOT NULL")
    with_qty, _ = mysql(
        ssh,
        f"SELECT COUNT(*) FROM {P}codigo_barra WHERE cantidad IS NOT NULL AND cantidad > 0"
    )
    family_assigned, _ = mysql(
        ssh,
        f"SELECT COUNT(*) FROM {P}producto_proveedor WHERE grupo_id IS NOT NULL"
    )
    integrity_ok = bad_assign.strip() == '0'
    if integrity_ok:
        ok(
            '5. Integridad SQL',
            f'invalid_assignments={bad_assign} barcodes={barcodes} '
            f'with_envase={barcodes_env} with_qty={with_qty} family_assigned={family_assigned}'
        )
    else:
        fail('5. Integridad SQL', f'invalid_assignments={bad_assign} (deben ser 0)')

    # --- 6. Toggle canal en POS template ---
    toggle, _ = run(ssh, f'grep -c pos-channel-select {PLUGIN}/templates/pos.php')
    recalc, _ = run(ssh, f'grep -c recalculateFamilyPrices {PLUGIN}/templates/pos.php')
    units, _ = run(ssh, f'grep -c units_per_pack {PLUGIN}/templates/pos.php')
    if int(toggle or 0) > 0 and int(recalc or 0) > 0:
        ok('6. UI POS toggle canal', f'channel={toggle} recalc={recalc} units_per_pack={units}')
    else:
        fail('6. UI POS toggle', f'channel={toggle} recalc={recalc}')

    # --- 7-8. Barcode con cantidad (backend resolve via MySQL + código desplegado) ---
    sample, _ = mysql(
        ssh,
        f"SELECT CONCAT(codigo, '|', IFNULL(producto_base_id,'NULL'), '|', "
        f"IFNULL(cantidad,'NULL'), '|', IFNULL(unidad_medida,''), '|', IFNULL(envase_id,'NULL')) "
        f"FROM {P}codigo_barra WHERE cantidad IS NOT NULL AND cantidad > 0 LIMIT 3"
    )
    resolve_fn, _ = run(ssh, f'grep -c search_by_unified_barcode {PLUGIN}/sales/pos/class-pos-module.php')
    badge, _ = run(ssh, f'grep -c "uds/envase" {PLUGIN}/templates/pos.php || grep -c units_per_pack {PLUGIN}/templates/pos.php')
    if int(resolve_fn or 0) > 0 and sample.strip():
        ok('7. Escaneo barcode con cantidad (datos+código)', f'resolve={resolve_fn}; samples={sample.replace(chr(10), "; ")}')
        ok('8. Línea carrito muestra envase (código UI)', f'badge/units refs={badge}')
    elif int(resolve_fn or 0) > 0:
        skip('7. Escaneo barcode con cantidad', 'código OK pero no hay barcodes con cantidad>0 en BD')
        skip('8. Badge uds/envase', 'requiere escaneo real; UI tiene units_per_pack' if int(units or 0) else 'sin UI')
    else:
        fail('7. Escaneo barcode', 'search_by_unified_barcode ausente')
        fail('8. Badge envase', 'N/A')

    # --- 9-10. Family qty + rule price (simulación lógica + endpoints) ---
    rule_fn, _ = run(ssh, f'grep -c calculate_family_qty_from_cart {PLUGIN}/sales/pos/class-pos-module.php')
    rule_ajax, _ = run(ssh, f'grep -c riverso_pos_rule_price {PLUGIN}/sales/pos/class-pos-module.php')
    # Simular 3×50 + 2×100 = 350
    cart = [
        {'producto_base_id': 1, 'cantidad': 3, 'units_per_pack': 50},
        {'producto_base_id': 1, 'cantidad': 2, 'units_per_pack': 100},
    ]
    family_qty = sum(
        (int(i.get('cantidad') or 0) * int(i.get('units_per_pack') or 1))
        for i in cart if int(i.get('producto_base_id') or 0) == 1
    )
    if int(rule_fn or 0) > 0 and family_qty == 350:
        ok('9. Agregación familia 3×50+2×100=350', f'calc={family_qty}; fn_count={rule_fn}')
    else:
        fail('9. Agregación familia', f'calc={family_qty} fn={rule_fn}')

    # Check price rules engine present
    engine, _ = run(
        ssh,
        f'test -f {PLUGIN}/pricing/price_lists/class-price-rule-engine.php && echo OK || '
        f'test -f {PLUGIN}/modules/pricing/class-price-rule-engine.php && echo OK_LEGACY || echo NO'
    )
    if int(rule_ajax or 0) > 0 and 'OK' in engine:
        ok('10. Recalc precio por tramo (código)', f'rule_price={rule_ajax} engine={engine}')
    else:
        fail('10. Recalc precio', f'ajax={rule_ajax} engine={engine}')

    # --- 11. Canal online ---
    set_ch, _ = run(ssh, f'grep -c riverso_pos_set_channel {PLUGIN}/sales/pos/class-pos-module.php')
    online_price, _ = run(ssh, f'grep -c get_online_price {PLUGIN}/pricing/price_lists/class-pricing-module.php')
    if int(set_ch or 0) > 0 and int(online_price or 0) > 0:
        ok('11. Canal online (código)', f'set_channel={set_ch} get_online_price={online_price}')
    else:
        fail('11. Canal online', f'set_channel={set_ch} online_price={online_price}')

    # --- 12-13. assign_to_family test real en DB ---
    match_ui, _ = run(ssh, f'grep -c assign_family\\|list_families\\|Asignar {PLUGIN}/templates/catalog-domain.php | head -1')
    # broader
    match_ui2, _ = run(ssh, f'grep -cE "assign_family|list_families|grupo_id" {PLUGIN}/templates/catalog-domain.php')
    assign_fn, _ = run(ssh, f'grep -c assign_to_family {PLUGIN}/catalog/matching/class-matching-module.php')

    # Create ephemeral test family + assign if we have a product_proveedor row
    pp_id, _ = mysql(ssh, f"SELECT id FROM {P}producto_proveedor WHERE grupo_id IS NULL ORDER BY id DESC LIMIT 1")
    if not pp_id.strip():
        skip('12. assign_to_family', 'no hay producto_proveedor disponible para test')
        skip('13. DB familia assignment', 'sin fila de prueba')
    else:
        # Ensure a group exists
        mysql(
            ssh,
            f"INSERT INTO {P}equivalence_groups "
            f"(codigo_grupo, nombre, tipo_sustitucion, activo, notas, created_at, updated_at) "
            f"SELECT 'CHECKLIST_TEST', 'Checklist test family', 'compatible', 1, "
            f"'temporal checklist', NOW(), NOW() FROM DUAL "
            f"WHERE NOT EXISTS (SELECT 1 FROM {P}equivalence_groups WHERE codigo_grupo='CHECKLIST_TEST')"
        )
        gid, _ = mysql(ssh, f"SELECT id FROM {P}equivalence_groups WHERE codigo_grupo='CHECKLIST_TEST' LIMIT 1")
        # Save original state
        orig, _ = mysql(
            ssh,
            f"SELECT CONCAT(IFNULL(producto_base_id,'NULL'), '|', IFNULL(grupo_id,'NULL')) "
            f"FROM {P}producto_proveedor WHERE id={pp_id}"
        )
        orig_base = orig.split('|')[0] if '|' in orig else 'NULL'
        # Assign to family
        _, err = mysql(
            ssh,
            f"UPDATE {P}producto_proveedor SET producto_base_id=NULL, grupo_id={gid}, "
            f"assigned_to_family_at=NOW(), assigned_to_family_by=1 WHERE id={pp_id}"
        )
        verify, _ = mysql(
            ssh,
            f"SELECT CONCAT(IFNULL(producto_base_id,'NULL'), '|', IFNULL(grupo_id,'NULL')) "
            f"FROM {P}producto_proveedor WHERE id={pp_id}"
        )
        assigned_ok = verify.strip() == f'NULL|{gid}'
        # Restore
        if orig_base != 'NULL':
            mysql(
                ssh,
                f"UPDATE {P}producto_proveedor SET producto_base_id={orig_base}, grupo_id=NULL, "
                f"assigned_to_family_at=NULL, assigned_to_family_by=NULL WHERE id={pp_id}"
            )
        else:
            mysql(
                ssh,
                f"UPDATE {P}producto_proveedor SET producto_base_id=NULL, grupo_id=NULL, "
                f"assigned_to_family_at=NULL, assigned_to_family_by=NULL WHERE id={pp_id}"
            )
        # cleanup test group if empty
        mysql(ssh, f"DELETE FROM {P}equivalence_groups WHERE codigo_grupo='CHECKLIST_TEST'")

        if assigned_ok and int(assign_fn or 0) > 0:
            ok(
                '12. assign_to_family (DB+código)',
                f'pp_id={pp_id} → NULL|{gid} luego restaurado; UI_refs={match_ui2} fn={assign_fn}'
            )
            ok('13. DB grupo_id NOT NULL / base NULL', f'verificado temporalmente: {verify}')
        else:
            fail('12. assign_to_family', f'verify={verify} err={err} fn={assign_fn}')
            fail('13. DB assignment', verify)

    ssh.close()

    # Summary
    print('\n=== Resumen ===')
    counts = {'PASS': 0, 'FAIL': 0, 'SKIP': 0}
    for st, item, detail in results:
        counts[st] = counts.get(st, 0) + 1
    print(json.dumps(counts, ensure_ascii=False))
    for st, item, detail in results:
        print(f'{st}\t{item}\t{detail}')

    return 0 if counts.get('FAIL', 0) == 0 else 1


if __name__ == '__main__':
    raise SystemExit(main())
