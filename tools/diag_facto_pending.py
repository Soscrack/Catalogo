#!/usr/bin/env python
"""Diagnóstico: pendientes FACTO vs filas exportables."""
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
env_path = ROOT / '.env.deploy'
if env_path.is_file():
    for line in env_path.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        key, value = line.split('=', 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ['RIVERSO_DEPLOY_PASSWORD']
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')

PHP = f"""<?php
require_once '{WP_PATH}/wp-load.php';
global $wpdb;
$p = $wpdb->prefix;
$map = "{{$p}}riverso_facto_producto_map";
$pb = "{{$p}}riverso_producto_base";

echo "=== PENDING (panel) ===\\n";
$rows = $wpdb->get_results(
    "SELECT pb.id, pb.canonical_sku, pb.archived_at, pb.marca, fm.facto_product_id, fm.sync_state, fm.facto_sku
     FROM {{$map}} fm
     INNER JOIN {{$pb}} pb ON pb.id = fm.producto_base_id
     WHERE fm.sync_state = 'pendiente_excel'
       AND pb.deleted_at IS NULL
       AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
     ORDER BY fm.updated_at DESC
     LIMIT 15",
    ARRAY_A
);
foreach ($rows as $r) {{
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\\n";
}}
echo 'panel_count=' . count($rows) . "\\n";

echo "=== EXPORT build_rows (pending, not archived, update_only) ===\\n";
$rows2 = $wpdb->get_results(
    "SELECT pb.id, pb.canonical_sku, pb.archived_at, fm.facto_product_id
     FROM {{$pb}} pb
     INNER JOIN {{$map}} fm ON fm.producto_base_id = pb.id
     WHERE pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
       AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
       AND fm.facto_product_id IS NOT NULL
       AND fm.sync_state = 'pendiente_excel'
     ORDER BY pb.canonical_sku
     LIMIT 15",
    ARRAY_A
);
foreach ($rows2 as $r) {{
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\\n";
}}
echo 'export_update_count=' . count($rows2) . "\\n";

echo "=== EXPORT build_rows (pending, not archived, upsert) ===\\n";
$rows3 = $wpdb->get_results(
    "SELECT pb.id, pb.canonical_sku, pb.archived_at, fm.facto_product_id
     FROM {{$pb}} pb
     INNER JOIN {{$map}} fm ON fm.producto_base_id = pb.id
     WHERE pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
       AND pb.deleted_at IS NULL AND pb.archived_at IS NULL
       AND fm.sync_state = 'pendiente_excel'
     ORDER BY pb.canonical_sku
     LIMIT 15",
    ARRAY_A
);
echo 'export_upsert_count=' . count($rows3) . "\\n";
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
sftp = ssh.open_sftp()
with sftp.open('/tmp/fe_diag.php', 'w') as handle:
    handle.write(PHP)
sftp.close()
stdin, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/fe_diag.php; rm -f /tmp/fe_diag.php'
)
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print('STDERR:', err)
ssh.close()
