#!/usr/bin/env python3
"""Vincula SKU 29731 al product_id de FACTO vía API."""
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
require_once '{wp}/wp-content/plugins/riverso-pos/includes/helpers-facto.php';
require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$sku = '29731';
$pb = $wpdb->get_row($wpdb->prepare(
    "SELECT id FROM {{$p}}producto_base WHERE canonical_sku = %s LIMIT 1", $sku
), ARRAY_A);
if (!$pb) {{
    echo json_encode(['error' => 'sku missing']);
    exit(1);
}}
$pid = (int) $pb['id'];
$client = new Riverso_Facto_Client();
$out = ['configured' => $client->is_configured()];
$resp = $client->list_products(['sku' => $sku, 'page' => 1]);
if (is_wp_error($resp)) {{
    $out['api_error'] = $resp->get_error_message();
    $out['api_data'] = $resp->get_error_data();
}} else {{
    $out['raw_type'] = gettype($resp);
    $out['raw_keys'] = is_array($resp) ? array_keys($resp) : null;
    $items = Riverso_Facto_Client::embed_collection($resp, 'products');
    $out['items_count'] = count($items);
    $facto_id = null;
    $sample = null;
    foreach ($items as $it) {{
        if (trim((string) ($it['sku'] ?? '')) === $sku) {{
            $facto_id = (int) ($it['product_id'] ?? $it['id'] ?? 0);
            $sample = $it;
            break;
        }}
    }}
    if (!$facto_id && count($items) === 1) {{
        $facto_id = (int) ($items[0]['product_id'] ?? $items[0]['id'] ?? 0);
        $sample = $items[0];
    }}
    // Paginar si hace falta
    if (!$facto_id) {{
        $resp2 = $client->list_products(['name' => 'Grapa Galvanizada', 'page' => 1]);
        if (!is_wp_error($resp2)) {{
            foreach (Riverso_Facto_Client::embed_collection($resp2, 'products') as $it) {{
                if (trim((string) ($it['sku'] ?? '')) === $sku) {{
                    $facto_id = (int) ($it['product_id'] ?? $it['id'] ?? 0);
                    $sample = $it;
                    break;
                }}
            }}
        }}
    }}
    if ($facto_id <= 0) {{
        $facto_id = null;
    }}
    $now = current_time('mysql');
    $updated = false;
    if ($facto_id) {{
        $map = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {{$p}}facto_producto_map WHERE producto_base_id = %d", $pid
        ), ARRAY_A);
        $data = [
            'facto_product_id' => $facto_id,
            'facto_sku' => $sku,
            'sync_state' => 'pendiente_excel',
            'last_error' => 'Vinculado a FACTO; pendiente export Excel precio/costo',
            'updated_at' => $now,
        ];
        if ($map) {{
            $wpdb->update("{{$p}}facto_producto_map", $data, ['id' => (int) $map['id']]);
        }} else {{
            $data['producto_base_id'] = $pid;
            $data['created_at'] = $now;
            $wpdb->insert("{{$p}}facto_producto_map", $data);
        }}
        $updated = true;
    }}
    $out['producto_base_id'] = $pid;
    $out['facto_product_id'] = $facto_id;
    $out['updated'] = $updated;
    $out['sample'] = $sample;
}}
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
    with sftp.open('/tmp/link_29731.php', 'w') as f:
        f.write(php)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/link_29731.php',
        timeout=120,
    )
    print(stdout.read().decode())
    err = stderr.read().decode()
    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex' not in ln and 'Failed to open stream' not in ln
        )
        if clean.strip():
            print(clean[:2000])
    ssh.close()


if __name__ == '__main__':
    main()
