#!/usr/bin/env python
"""Deploy riverso-pos plugin to server via Paramiko"""
import paramiko
import os

ROOT = os.path.dirname(os.path.abspath(__file__))


def _load_env_file(path):
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


_load_env_file(os.path.join(ROOT, '.env.deploy'))

# SSH: env > .env.deploy (gitignored). No hardcodear la clave en este archivo.
HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
PLUGIN_PATH = f'{WP_PATH}/wp-content/plugins/riverso-pos'

def build_zip(source=None):
    """Regenera riverso-pos-deploy.zip antes de subir.

    `source` permite empaquetar desde una copia preparada aparte (por ejemplo
    para desplegar sin arrastrar trabajo en curso). Por defecto usa el plugin
    del repo.
    """
    import zipfile
    from pathlib import Path

    plugin = Path(source) if source else Path(ROOT) / 'php' / 'riverso-pos'
    out = Path(ROOT) / 'riverso-pos-deploy.zip'
    if not plugin.is_dir():
        raise RuntimeError(f'No existe el plugin en {plugin}')
    if out.exists():
        out.unlink()
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for path in plugin.rglob('*'):
            if not path.is_file():
                continue
            if '__pycache__' in path.parts or path.suffix == '.pyc':
                continue
            arc = Path('riverso-pos') / path.relative_to(plugin)
            zf.write(path, arc.as_posix())
    print(f'ZIP rebuilt: {out.name} ({out.stat().st_size} bytes)')
    return str(out)


def main(source=None, skip_migration=False):
    if not PASSWORD:
        raise RuntimeError(
            'Falta la contraseña de deploy. Crea .env.deploy en la raíz del repo '
            'con RIVERSO_DEPLOY_PASSWORD=... o exporta esa variable.'
        )

    zip_path = build_zip(source)

    # Connect
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print('Connecting to server...')
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    print('Connected!')

    # Upload ZIP via SFTP
    sftp = ssh.open_sftp()
    print('Uploading ZIP...')
    sftp.put(zip_path, '/tmp/riverso-pos-deploy.zip')
    print('Upload complete!')
    sftp.close()

    # Preflight, backup, deploy and migrate. A PHP syntax failure stops before
    # touching the active plugin; a migration failure restores the backup.
    skip_flag = '1' if skip_migration else '0'
    commands = f'''
set -e
SKIP_MIGRATION={skip_flag}
cd /tmp
rm -rf /tmp/riverso-pos-extract 2>/dev/null
mkdir -p /tmp/riverso-pos-extract
unzip -o riverso-pos-deploy.zip -d /tmp/riverso-pos-extract
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
test -n "$PHP_BIN"

unzip -Z1 /tmp/riverso-pos-deploy.zip | awk '/[.]php$/ {{print}}' | while IFS= read -r rel; do
  "$PHP_BIN" -l "/tmp/riverso-pos-extract/$rel" >/dev/null
done
echo 'PHP preflight passed'

# Backup current
BACKUP="{PLUGIN_PATH}.bak.$(date +%Y%m%d%H%M%S)"
cp -a {PLUGIN_PATH} "$BACKUP" 2>/dev/null || true

# Copy files (ZIP contains riverso-pos/ prefix)
if [ -d /tmp/riverso-pos-extract/riverso-pos ]; then
  cp -r /tmp/riverso-pos-extract/riverso-pos/* {PLUGIN_PATH}/
else
  cp -r /tmp/riverso-pos-extract/* {PLUGIN_PATH}/
fi

# Remove accidental nested copy from previous deploys
rm -rf {PLUGIN_PATH}/riverso-pos 2>/dev/null || true

# Fix permissions
chown -R riverso.cl_1xybiw6rlcq:psacln {PLUGIN_PATH}
chmod -R 755 {PLUGIN_PATH}

# Trigger idempotent migrations as the site owner.
if [ "$SKIP_MIGRATION" = "1" ]; then
  echo 'Skipping wp-load migration (SKIP_MIGRATION=1)'
else
if ! sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -d memory_limit=1024M -r '
  require "{WP_PATH}/wp-load.php";
  Riverso_POS_Activator::update_database();
  echo get_option("riverso_pos_db_version"), PHP_EOL;
'; then
  rm -rf {PLUGIN_PATH}
  cp -a "$BACKUP" {PLUGIN_PATH}
  chown -R riverso.cl_1xybiw6rlcq:psacln {PLUGIN_PATH}
  echo 'Migration failed; backup restored' >&2
  exit 1
fi
fi

if [ "$SKIP_MIGRATION" = "1" ]; then
  VERSION=$(grep -E "define\\s*\\(\\s*'RIVERSO_POS_VERSION'" {PLUGIN_PATH}/riverso-pos.php | sed -E "s/.*'([0-9.]+)'.*/\\1/" | head -1)
else
VERSION=$(sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
  require "{WP_PATH}/wp-load.php";
  echo defined("RIVERSO_POS_VERSION") ? RIVERSO_POS_VERSION : "missing";
')
fi
test "$VERSION" = "1.6.55"

if [ "$SKIP_MIGRATION" = "1" ]; then
  echo "schema-skip competencia tables should be applied via tools/migrate_competencia_remote.py"
else
sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
  require "{WP_PATH}/wp-load.php";
  global $wpdb;
  foreach (["riverso_data_gaps", "riverso_ean_aliases", "riverso_factura_referencias", "riverso_factura_pagos", "riverso_factura_pago_documentos", "riverso_factura_reversa_inventario", "riverso_ordenes_impresion", "riverso_orden_impresion_items", "riverso_producto_ubicacion_preferida", "riverso_producto_ubicacion_historial", "riverso_conteo_scan_log", "riverso_ordenes_inventario", "riverso_orden_inventario_items", "riverso_envase_tipos"] as $suffix) {{
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {{
      fwrite(STDERR, "Missing table: " . $table . PHP_EOL);
      exit(1);
    }}
  }}
  $facturas = $wpdb->prefix . "riverso_facturas";
  $items = $wpdb->prefix . "riverso_factura_items";
  foreach (["estado_pago", "tasa_iva", "impuestos_adicionales", "tipo_confirmado"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$facturas` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $facturas" . PHP_EOL);
      exit(1);
    }}
  }}
  foreach (["costo_neto_base", "costo_bruto_base", "costo_neto_final", "costo_bruto_final", "descuento_monto", "recargo_monto", "impuesto_especifico_monto"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$items` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $items" . PHP_EOL);
      exit(1);
    }}
  }}
  $codigos = $wpdb->prefix . "riverso_codigos";
  foreach (["last_seen_document_date", "sku_mapped_at"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$codigos` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $codigos" . PHP_EOL);
      exit(1);
    }}
  }}
  $ub = $wpdb->prefix . "riverso_ubicaciones";
  foreach (["barcode", "zona"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$ub` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $ub" . PHP_EOL);
      exit(1);
    }}
  }}
  $cb = $wpdb->prefix . "riverso_codigo_barra";
  foreach (["estado", "origen_datos", "sku_local", "pending_sku", "legacy_ref", "conflicto"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$cb` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $cb" . PHP_EOL);
      exit(1);
    }}
  }}
  $conteos = $wpdb->prefix . "riverso_conteos";
  foreach (["nombre", "tipo_conteo"] as $colName) {{
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM `$conteos` LIKE %s", $colName));
    if (empty($col)) {{
      fwrite(STDERR, "Missing column $colName on $conteos" . PHP_EOL);
      exit(1);
    }}
  }}
  $filled = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$items` WHERE costo_neto_final IS NOT NULL");
  $catalogos = $wpdb->prefix . "riverso_catalogos";
  $pp = $wpdb->prefix . "riverso_producto_proveedor";
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $catalogos)) !== $catalogos) {{
    fwrite(STDERR, "Missing table: " . $catalogos . PHP_EOL);
    exit(1);
  }}
  $linked = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$pp` WHERE catalogo_id IS NOT NULL");
  $activo = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$pp` WHERE catalogo_id IS NOT NULL AND activo = 1");
  $pb = $wpdb->prefix . "riverso_producto_base";
  $confused = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT pb.id) FROM `$pb` pb
     INNER JOIN `$pp` pp ON pp.producto_base_id = pb.id AND pp.catalogo_id IS NOT NULL
     WHERE pb.deleted_at IS NULL AND pb.canonical_sku IS NOT NULL AND LENGTH(pb.canonical_sku) > 0
       AND pb.canonical_sku = pp.codigo_proveedor"
  );
  $empty_local = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT pb.id) FROM `$pb` pb
     INNER JOIN `$pp` pp ON pp.producto_base_id = pb.id AND pp.catalogo_id IS NOT NULL
     WHERE pb.deleted_at IS NULL AND (pb.canonical_sku IS NULL OR LENGTH(pb.canonical_sku) = 0)"
  );
  $tasks = $wpdb->prefix . "riverso_tareas";
  $local_tasks = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `$tasks` WHERE tipo = %s AND estado IN (%s, %s)",
    "crear_contraparte_local",
    "pendiente",
    "en_progreso"
  ));
  echo "schema-ok costs-filled=$filled catalog-linked=$linked catalog-activo=$activo local-confused=$confused catalog-empty-local=$empty_local local-sku-tasks=$local_tasks", PHP_EOL;
'
fi

# Cleanup only after successful verification.
rm -rf /tmp/riverso-pos-extract /tmp/riverso-pos-deploy.zip
echo "Files deployed: $VERSION"
'''

    print('Deploying files...')
    stdin, stdout, stderr = ssh.exec_command(commands, timeout=600)
    print('Output:', stdout.read().decode())
    err = stderr.read().decode()
    if err:
        print('Stderr:', err)
    status = stdout.channel.recv_exit_status()
    if status != 0:
        ssh.close()
        raise RuntimeError(f'Deployment failed with status {status}')

    ssh.close()
    print('\nDeployment complete!')

if __name__ == '__main__':
    import argparse

    parser = argparse.ArgumentParser(description='Despliega el plugin riverso-pos')
    parser.add_argument(
        '--source',
        help='Directorio del plugin a empaquetar (por defecto php/riverso-pos)'
    )
    parser.add_argument(
        '--skip-migration',
        action='store_true',
        help='Solo copia archivos; omite wp-load.php (útil si activator agota memoria)',
    )
    args = parser.parse_args()
    main(args.source, skip_migration=args.skip_migration)
