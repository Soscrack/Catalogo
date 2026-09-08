#!/usr/bin/env python3
import os
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
for raw in (ROOT / '.env.deploy').read_text(encoding='utf-8').splitlines():
    line = raw.strip()
    if not line or line.startswith('#') or '=' not in line:
        continue
    k, v = line.split('=', 1)
    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))

wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
php = f"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
require_once '{wp}/wp-load.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/tpv/class-tpv-export-service.php';
$t0 = microtime(true);
try {{
    $t = new Riverso_Tpv_Export_Service();
    $p = $t->preview(['only_changed' => 1]);
    echo json_encode([
        'ok' => true,
        'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000),
        'total' => $p['total'] ?? null,
        'changed_productos' => $p['changed_productos'] ?? null,
        'changed_barcodes' => $p['changed_barcodes'] ?? null,
        'create_barcodes' => $p['create_barcodes'] ?? null,
        'can_download' => $p['can_download'] ?? null,
        'baseline' => $p['baseline'] ?? null,
        'empty_hint' => $p['empty_hint'] ?? null,
        'preview_productos' => count($p['preview_productos'] ?? []),
        'preview_barcodes' => count($p['preview_barcodes'] ?? []),
        'sample_barcodes' => array_slice($p['preview_barcodes'] ?? [], 0, 5),
        'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}} catch (Throwable $e) {{
    echo json_encode([
        'ok' => false,
        'elapsed_ms' => (int) ((microtime(true) - $t0) * 1000),
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(array_map(static function ($f) {{
            return ($f['file'] ?? '') . ':' . ($f['line'] ?? '') . ' ' . ($f['function'] ?? '');
        }}, $e->getTrace()), 0, 8),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}}
"""

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(
    os.environ['RIVERSO_DEPLOY_HOST'],
    username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
    password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
    timeout=30,
)
sftp = ssh.open_sftp()
with sftp.open('/tmp/tpv_preview_debug.php', 'w') as handle:
    handle.write(php)
sftp.close()
t0 = time.time()
_, stdout, stderr = ssh.exec_command(
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
    'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/tpv_preview_debug.php',
    timeout=300,
)
print(stdout.read().decode())
err = stderr.read().decode()
clean = '\n'.join(
    ln for ln in err.splitlines()
    if 'bluex' not in ln and 'Failed to open' not in ln and 'Duplicate' not in ln
)
if clean.strip():
    print('STDERR:', clean[:2500])
print(f'wall_ms={(time.time()-t0)*1000:.0f}')
ssh.close()
