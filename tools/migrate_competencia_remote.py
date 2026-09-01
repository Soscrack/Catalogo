#!/usr/bin/env python3
"""Aplica migraciones competencia (phase39 + phase40) vía mysql remoto (sin wp-load.php)."""
import os
import re
import sys
import tempfile
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
SQL_PHASE39 = ROOT / "php" / "riverso-pos" / "migrations" / "phase39_competencia_catalog_v1.sql"
SQL_PHASE40 = ROOT / "php" / "riverso-pos" / "migrations" / "phase40_competencia_precios_historial_v1.sql"


def load_env():
    path = ROOT / ".env.deploy"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def run(ssh, cmd, timeout=300):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def fetch_db_from_wp_config(ssh, wp_path: str) -> tuple[str, str, str, str, str]:
    sftp = ssh.open_sftp()
    path = f"{wp_path.rstrip('/')}/wp-config.php"
    try:
        with sftp.open(path, "r") as handle:
            content = handle.read().decode("utf-8", errors="replace")
    finally:
        sftp.close()

    def grab(name: str) -> str:
        m = re.search(
            r"define\s*\(\s*['\"]" + re.escape(name) + r"['\"]\s*,\s*['\"]([^'\"]*)['\"]",
            content,
        )
        if not m:
            raise RuntimeError(f"No se encontró {name} en wp-config.php")
        return m.group(1)

    prefix_m = re.search(r"\$table_prefix\s*=\s*['\"]([^'\"]*)['\"]", content)
    if not prefix_m:
        raise RuntimeError("No se encontró table_prefix en wp-config.php")
    return (
        grab("DB_HOST"),
        grab("DB_USER"),
        grab("DB_PASSWORD"),
        grab("DB_NAME"),
        prefix_m.group(1) + "riverso_",
    )


def mysql_shell(dbhost: str, dbuser: str, dbpass: str, dbname: str) -> str:
    dbhost_only = dbhost.split(":")[0]
    dbport = dbhost.split(":")[1] if ":" in dbhost else "3306"
    dbpass_shell = dbpass.replace("'", "'\\''")
    return (
        f"mysql -h '{dbhost_only}' -P '{dbport}' -u '{dbuser}' -p'{dbpass_shell}' '{dbname}'"
    )


def upload_and_run_sql(ssh, mysql_bin: str, local_sql: str, remote_name: str) -> int:
    remote_sql = f"/tmp/{remote_name}"
    sftp = ssh.open_sftp()
    sftp.put(local_sql, remote_sql)
    sftp.close()
    code, out, err = run(ssh, f"{mysql_bin} < '{remote_sql}' && rm -f '{remote_sql}' && echo mysql-ok", timeout=180)
    print(out.strip())
    if code != 0:
        print(err[:2000])
    return code


def build_phase40_idempotent(rprefix: str, dbname: str) -> str:
    """SQL phase40 idempotente (checks information_schema)."""
    p = rprefix
    hist = f"{p}competencia_precios_historial"
    precios = f"{p}competencia_precios"
    return f"""
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `{hist}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id` BIGINT UNSIGNED NOT NULL,
    `snapshot_fecha` DATE NOT NULL,
    `precio` DECIMAL(18,6) DEFAULT NULL,
    `precio_lista` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_unitario` DECIMAL(18,6) DEFAULT NULL,
    `precio_bruto_total` DECIMAL(18,6) DEFAULT NULL,
    `cantidad_min` INT UNSIGNED DEFAULT NULL,
    `iva` DECIMAL(8,4) DEFAULT NULL,
    `moneda` VARCHAR(10) DEFAULT 'CLP',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_producto_snapshot` (`producto_id`, `snapshot_fecha`),
    KEY `idx_snapshot` (`snapshot_fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELETE p FROM `{precios}` p
INNER JOIN `{precios}` newer
  ON newer.producto_id = p.producto_id
 AND (
       newer.snapshot_fecha > p.snapshot_fecha
    OR (newer.snapshot_fecha = p.snapshot_fecha AND newer.id > p.id)
 );

SET @has_old := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = '{dbname}' AND table_name = '{precios}' AND index_name = 'ux_producto_snapshot'
);
SET @sql := IF(@has_old > 0, 'ALTER TABLE `{precios}` DROP INDEX `ux_producto_snapshot`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_new := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = '{dbname}' AND table_name = '{precios}' AND index_name = 'ux_producto_id'
);
SET @sql := IF(@has_new = 0, 'ALTER TABLE `{precios}` ADD UNIQUE KEY `ux_producto_id` (`producto_id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = '{dbname}' AND table_name = '{precios}' AND column_name = 'actualizado_at'
);
SET @sql := IF(@has_col = 0, 'ALTER TABLE `{precios}` ADD COLUMN `actualizado_at` DATETIME DEFAULT NULL AFTER `oculto`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `{precios}`
SET `actualizado_at` = COALESCE(`actualizado_at`, `created_at`, NOW())
WHERE `actualizado_at` IS NULL;

INSERT INTO `{hist}`
    (`producto_id`, `snapshot_fecha`, `precio`, `precio_lista`,
     `precio_bruto_unitario`, `precio_bruto_total`, `cantidad_min`, `iva`, `moneda`)
SELECT
    `producto_id`, `snapshot_fecha`, `precio`, `precio_lista`,
    `precio_bruto_unitario`, `precio_bruto_total`, `cantidad_min`, `iva`, `moneda`
FROM `{precios}`
ON DUPLICATE KEY UPDATE
    `precio` = VALUES(`precio`),
    `precio_lista` = VALUES(`precio_lista`),
    `precio_bruto_unitario` = VALUES(`precio_bruto_unitario`),
    `precio_bruto_total` = VALUES(`precio_bruto_total`),
    `cantidad_min` = VALUES(`cantidad_min`),
    `iva` = VALUES(`iva`),
    `moneda` = VALUES(`moneda`);
"""


def main():
    load_env()
    host = os.environ.get("RIVERSO_DEPLOY_HOST")
    password = os.environ.get("RIVERSO_DEPLOY_PASSWORD")
    wp = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
    if not host or not password:
        print("Falta .env.deploy")
        return 1
    if not SQL_PHASE39.is_file():
        print(f"No existe {SQL_PHASE39}")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=os.environ.get("RIVERSO_DEPLOY_USER", "root"), password=password, timeout=30)

    try:
        dbhost, dbuser, dbpass, dbname, rprefix = fetch_db_from_wp_config(ssh, wp)
    except RuntimeError as exc:
        print(exc)
        ssh.close()
        return 1

    mysql_bin = mysql_shell(dbhost, dbuser, dbpass, dbname)

    # Phase 39
    sql39 = SQL_PHASE39.read_text(encoding="utf-8").replace("{prefix}", rprefix)
    sql39 += (
        f"\nINSERT INTO `{rprefix}competencia_fuentes` (slug, nombre, base_url, activo) "
        "VALUES ('sande', 'Sande Distribución Industrial', 'https://www.sande.cl/mcatalogo', 1) "
        "ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), base_url=VALUES(base_url), activo=1;\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".sql", delete=False, encoding="utf-8") as tmp:
        tmp.write(sql39)
        local39 = tmp.name
    print("=== phase39 ===")
    code = upload_and_run_sql(ssh, mysql_bin, local39, "phase39_competencia.sql")
    os.unlink(local39)
    if code != 0:
        ssh.close()
        return code

    # Phase 40
    sql40 = build_phase40_idempotent(rprefix, dbname)
    with tempfile.NamedTemporaryFile("w", suffix=".sql", delete=False, encoding="utf-8") as tmp:
        tmp.write(sql40)
        local40 = tmp.name
    print("=== phase40 ===")
    code = upload_and_run_sql(ssh, mysql_bin, local40, "phase40_competencia.sql")
    os.unlink(local40)
    if code != 0:
        ssh.close()
        return code

    verify = (
        f"{mysql_bin} -N -e "
        f"\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{dbname}' "
        f"AND table_name LIKE '{rprefix}competencia_%'; "
        f"SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='{dbname}' "
        f"AND table_name='{rprefix}competencia_precios' AND index_name='ux_producto_id'; "
        f"SELECT COUNT(*) FROM `{rprefix}competencia_precios_historial`;\""
    )
    code, out, err = run(ssh, verify)
    print(f"Verify (tablas / ux_producto_id / hist_rows):\n{out.strip()}")
    if err.strip():
        print(err[:500])
    ssh.close()
    return 0 if code == 0 else code


if __name__ == "__main__":
    raise SystemExit(main())
