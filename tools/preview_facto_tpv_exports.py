#!/usr/bin/env python3
"""Vista previa export FACTO/TPV para SKUs clave (29731, 12900, 129)."""
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    for raw in (ROOT / '.env.deploy').read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        k, v = line.split('=', 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def main():
    load_env()
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    php = f"""<?php
require_once '{wp}/wp-load.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-export-service.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/tpv/class-tpv-export-service.php';

$facto = new Riverso_Facto_Export_Service();
$tpv = new Riverso_Tpv_Export_Service();

$out = [];
$out['facto_29731'] = $facto->preview([
    'modo' => 'upsert',
    'sku' => '29731',
    'pending_only' => false,
    'only_changed' => false,
    'hydrate_facto' => false,
]);
// Compactar
$out['facto_29731_compact'] = [
    'total' => $out['facto_29731']['total'] ?? null,
    'can_download' => $out['facto_29731']['can_download'] ?? null,
    'mapped_count' => $out['facto_29731']['mapped_count'] ?? null,
    'validation_ok' => $out['facto_29731']['validation']['ok'] ?? null,
    'sample_errors' => $out['facto_29731']['sample_errors'] ?? [],
];
unset($out['facto_29731']);

$out['tpv_29731'] = $tpv->preview([
    'sku' => '29731',
    'only_changed' => true,
]);
$out['tpv_29731_compact'] = [
    'total_productos' => $out['tpv_29731']['total_productos'] ?? null,
    'total_barcodes' => $out['tpv_29731']['total_barcodes'] ?? null,
    'create_productos' => $out['tpv_29731']['create_productos'] ?? null,
    'update_productos' => $out['tpv_29731']['update_productos'] ?? null,
    'create_barcodes' => $out['tpv_29731']['create_barcodes'] ?? null,
    'changed_productos' => $out['tpv_29731']['changed_productos'] ?? null,
    'changed_barcodes' => $out['tpv_29731']['changed_barcodes'] ?? null,
    'baseline' => $out['tpv_29731']['baseline'] ?? null,
    'has_last_applied_batch' => $out['tpv_29731']['has_last_applied_batch'] ?? null,
    'preview_productos' => $out['tpv_29731']['preview_productos'] ?? [],
    'preview_barcodes' => $out['tpv_29731']['preview_barcodes'] ?? [],
];
unset($out['tpv_29731']);

$out['tpv_129_12900'] = $tpv->preview([
    'sku' => '129,12900',
    'only_changed' => true,
]);
$out['tpv_129_compact'] = [
    'total_productos' => $out['tpv_129_12900']['total_productos'] ?? null,
    'create_productos' => $out['tpv_129_12900']['create_productos'] ?? null,
    'update_productos' => $out['tpv_129_12900']['update_productos'] ?? null,
    'delete_productos' => $out['tpv_129_12900']['delete_productos'] ?? null,
    'create_barcodes' => $out['tpv_129_12900']['create_barcodes'] ?? null,
    'preview_productos' => $out['tpv_129_12900']['preview_productos'] ?? [],
    'preview_barcodes' => array_slice($out['tpv_129_12900']['preview_barcodes'] ?? [], 0, 20),
];
unset($out['tpv_129_12900']);

// Delta barcodes global (solo cambios) — no debe CREAR miles
$out['tpv_delta'] = $tpv->preview(['only_changed' => true]);
$out['tpv_delta_compact'] = [
    'total_productos' => $out['tpv_delta']['total_productos'] ?? null,
    'total_barcodes' => $out['tpv_delta']['total_barcodes'] ?? null,
    'create_productos' => $out['tpv_delta']['create_productos'] ?? null,
    'update_productos' => $out['tpv_delta']['update_productos'] ?? null,
    'delete_productos' => $out['tpv_delta']['delete_productos'] ?? null,
    'create_barcodes' => $out['tpv_delta']['create_barcodes'] ?? null,
    'changed_productos' => $out['tpv_delta']['changed_productos'] ?? null,
    'changed_barcodes' => $out['tpv_delta']['changed_barcodes'] ?? null,
    'baseline' => $out['tpv_delta']['baseline'] ?? null,
];
unset($out['tpv_delta']);

$out['facto_pending'] = $facto->preview([
    'modo' => 'upsert',
    'pending_only' => true,
    'only_changed' => false,
    'hydrate_facto' => false,
]);
$out['facto_pending_compact'] = [
    'total' => $out['facto_pending']['total'] ?? null,
    'pending' => $out['facto_pending']['pending'] ?? null,
    'can_download' => $out['facto_pending']['can_download'] ?? null,
];
unset($out['facto_pending']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37'),
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    sftp = ssh.open_sftp()
    with sftp.open('/tmp/preview_facto_tpv.php', 'w') as f:
        f.write(php)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/preview_facto_tpv.php',
        timeout=300,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print(clean[:2500])
    ssh.close()


if __name__ == '__main__':
    main()
