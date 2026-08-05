#!/usr/bin/env python
"""Aplicar migraciones phase12/13 de arquitectura productos vía MySQL."""
import paramiko

HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
MYSQL = "mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm"
P = 'nExLU_riverso_'

sql_statements = [
    # Phase 12: consolidar familias (idempotente)
    f"""INSERT IGNORE INTO {P}equivalence_groups
        (codigo_grupo, nombre, tipo_sustitucion, activo, notas, created_at, updated_at)
        SELECT CONCAT('LEGACY_', eg.id), eg.nombre, 'compatible', eg.activo,
               CONCAT('Migrado de phase11 equivalencia_grupo id=', eg.id), eg.created_at, eg.updated_at
        FROM {P}equivalencia_grupo eg
        WHERE NOT EXISTS (
            SELECT 1 FROM {P}equivalence_groups eg2
            WHERE eg2.codigo_grupo = CONCAT('LEGACY_', eg.id)
        )""",

    f"""INSERT IGNORE INTO {P}equivalence_members
        (grupo_id, producto_base_id, prioridad, es_reemplazo_preferido, activo, created_at, updated_at)
        SELECT eg_canon.id, em.producto_base_id, em.prioridad, 0, 1, em.created_at, NOW()
        FROM {P}equivalencia_miembro em
        INNER JOIN {P}equivalencia_grupo eg ON eg.id = em.grupo_id
        LEFT JOIN {P}equivalence_groups eg_canon
            ON eg_canon.codigo_grupo = CONCAT('LEGACY_', eg.id)
        WHERE eg_canon.id IS NOT NULL
          AND NOT EXISTS (
            SELECT 1 FROM {P}equivalence_members em2
            WHERE em2.grupo_id = eg_canon.id AND em2.producto_base_id = em.producto_base_id
          )""",

    # Phase 13: nullable + grupo_id
    f"ALTER TABLE {P}producto_proveedor MODIFY COLUMN producto_base_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK producto_base; NULL si familia'",
    f"ALTER TABLE {P}producto_proveedor ADD COLUMN grupo_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK equivalence_groups' AFTER producto_base_id",
    f"ALTER TABLE {P}producto_proveedor ADD COLUMN assigned_to_family_at DATETIME DEFAULT NULL AFTER grupo_id",
    f"ALTER TABLE {P}producto_proveedor ADD COLUMN assigned_to_family_by BIGINT UNSIGNED DEFAULT NULL AFTER assigned_to_family_at",
    f"ALTER TABLE {P}producto_proveedor ADD KEY idx_grupo_id (grupo_id)",
]

# Optional statements that may fail if already applied / unsupported
optional = [
    f"ALTER TABLE {P}equivalencia_grupo ADD COLUMN deprecated_at DATETIME DEFAULT NULL",
    f"ALTER TABLE {P}equivalencia_miembro ADD COLUMN deprecated_at DATETIME DEFAULT NULL",
    f"ALTER TABLE {P}producto_proveedor ADD CONSTRAINT fk_pp_grupo_id FOREIGN KEY (grupo_id) REFERENCES {P}equivalence_groups(id) ON DELETE SET NULL",
    f"""ALTER TABLE {P}producto_proveedor ADD CONSTRAINT chk_producto_o_familia CHECK (
        (producto_base_id IS NOT NULL AND grupo_id IS NULL) OR
        (producto_base_id IS NULL AND grupo_id IS NOT NULL)
    )""",
    f"UPDATE {P}options_dummy SET x=1",  # placeholder removed below
]

optional = [s for s in optional if 'options_dummy' not in s]

verify = f"""SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}producto_proveedor' AND COLUMN_NAME='grupo_id') AS has_grupo_id,
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{P}producto_proveedor' AND COLUMN_NAME='producto_base_id') AS base_nullable,
  (SELECT COUNT(*) FROM {P}equivalence_groups) AS groups_count,
  (SELECT COUNT(*) FROM {P}equivalence_members) AS members_count;
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

# Check if legacy tables exist before phase12 inserts
_, stdout, _ = ssh.exec_command(
    f"{MYSQL} -N -e \"SHOW TABLES LIKE '{P}equivalencia_grupo'\""
)
has_legacy = bool(stdout.read().decode().strip())
print('legacy equivalencia_grupo:', 'yes' if has_legacy else 'no')

print('\n=== Applying required SQL ===')
for i, stmt in enumerate(sql_statements, 1):
    if not has_legacy and ('equivalencia_grupo' in stmt or 'equivalencia_miembro' in stmt):
        print(f'{i}. SKIP (no legacy tables)')
        continue
    # Escape for shell: write to temp file to avoid quoting hell
    remote_file = f'/tmp/riverso_mig_{i}.sql'
    sftp = ssh.open_sftp()
    with sftp.file(remote_file, 'w') as f:
        f.write(stmt + ';\n')
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f'{MYSQL} < {remote_file}; echo EXIT:$?')
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    # Filter mysql password warnings
    err_lines = [l for l in err.splitlines() if l and 'Warning' not in l and 'Using a password' not in l]
    status = 'OK' if out.endswith('EXIT:0') and not err_lines else 'WARN'
    print(f'{i}. {status} {out}')
    if err_lines:
        print('   ', err_lines[0][:200])
    ssh.exec_command(f'rm -f {remote_file}')

print('\n=== Optional SQL ===')
for i, stmt in enumerate(optional, 1):
    remote_file = f'/tmp/riverso_opt_{i}.sql'
    sftp = ssh.open_sftp()
    with sftp.file(remote_file, 'w') as f:
        f.write(stmt + ';\n')
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f'{MYSQL} < {remote_file}; echo EXIT:$?')
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    err_lines = [l for l in err.splitlines() if l and 'Warning' not in l and 'Using a password' not in l]
    print(f'{i}. {out} {(" | " + err_lines[0][:160]) if err_lines else ""}')
    ssh.exec_command(f'rm -f {remote_file}')

print('\n=== Verify ===')
sftp = ssh.open_sftp()
with sftp.file('/tmp/riverso_verify.sql', 'w') as f:
    f.write(verify)
sftp.close()
_, stdout, stderr = ssh.exec_command(f'{MYSQL} < /tmp/riverso_verify.sql')
print(stdout.read().decode().strip())
err = stderr.read().decode().strip()
err_lines = [l for l in err.splitlines() if l and 'Warning' not in l and 'Using a password' not in l]
if err_lines:
    print('err:', err_lines)

# Bump db version option so WP knows we're current
print('\n=== Set db version option ===')
opt_sql = (
    f"UPDATE {P.replace('riverso_', '')}options SET option_value='1.4.2' "
    f"WHERE option_name='riverso_pos_db_version'; "
    f"SELECT ROW_COUNT();"
)
# WordPress options table is typically nExLU_options not riverso_
opt_sql = (
    "UPDATE nExLU_options SET option_value='1.4.2' WHERE option_name='riverso_pos_db_version'; "
    "INSERT INTO nExLU_options (option_name, option_value, autoload) "
    "SELECT 'riverso_pos_db_version', '1.4.2', 'no' FROM DUAL "
    "WHERE NOT EXISTS (SELECT 1 FROM nExLU_options WHERE option_name='riverso_pos_db_version'); "
    "SELECT option_value FROM nExLU_options WHERE option_name='riverso_pos_db_version';"
)
sftp = ssh.open_sftp()
with sftp.file('/tmp/riverso_ver.sql', 'w') as f:
    f.write(opt_sql)
sftp.close()
_, stdout, stderr = ssh.exec_command(f'{MYSQL} < /tmp/riverso_ver.sql')
print(stdout.read().decode().strip())

ssh.close()
print('\nMigrations applied.')
