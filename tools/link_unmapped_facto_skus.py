#!/usr/bin/env python3
"""
Identifica SKUs locales sin facto_product_id que ya existen en FACTO y los vincula.

Uso:
  python tools/link_unmapped_facto_skus.py           # dry-run
  python tools/link_unmapped_facto_skus.py --apply   # escribe el mapa
  python tools/link_unmapped_facto_skus.py --limit 50
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import textwrap
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env() -> None:
    env_path = ROOT / '.env.deploy'
    if not env_path.is_file():
        raise SystemExit('Falta .env.deploy')
    for raw in env_path.read_text(encoding='utf-8').splitlines():
        line = raw.strip()
        if not line or line.startswith('#') or '=' not in line:
            continue
        k, v = line.split('=', 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def build_php(apply: bool, limit: int) -> str:
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    apply_flag = 'true' if apply else 'false'
    limit = max(0, int(limit))
    return textwrap.dedent(f"""\
    <?php
    require_once '{wp}/wp-load.php';
    require_once '{wp}/wp-content/plugins/riverso-pos/includes/helpers-facto.php';
    require_once '{wp}/wp-content/plugins/riverso-pos/modules/integrations/facto/class-facto-client.php';

    global $wpdb;
    $p = $wpdb->prefix . 'riverso_';
    $apply = {apply_flag};
    $limit = {limit};

    $client = new Riverso_Facto_Client();
    $out = [
        'configured' => $client->is_configured(),
        'apply' => $apply,
        'candidates_total' => 0,
        'checked' => 0,
        'match' => 0,
        'not_found' => 0,
        'ambiguous' => 0,
        'errors' => 0,
        'updated' => 0,
        'rows' => [],
        'sku_22072' => null,
    ];

    if (!$client->is_configured()) {{
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit(1);
    }}

    $sql = "
        SELECT
            fm.id AS map_id,
            fm.producto_base_id,
            fm.sync_state,
            fm.facto_sku,
            fm.last_error,
            pb.canonical_sku,
            pb.nombre_canonico
        FROM {{$p}}facto_producto_map fm
        INNER JOIN {{$p}}producto_base pb ON pb.id = fm.producto_base_id
        WHERE fm.facto_product_id IS NULL
          AND pb.deleted_at IS NULL
          AND pb.archived_at IS NULL
          AND pb.canonical_sku IS NOT NULL
          AND pb.canonical_sku <> ''
        ORDER BY
          CASE WHEN fm.sync_state = 'pendiente_excel' THEN 0 ELSE 1 END,
          pb.canonical_sku ASC
    ";
    if ($limit > 0) {{
        $sql .= $wpdb->prepare(' LIMIT %d', $limit);
    }}

    $candidates = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $out['candidates_total'] = count($candidates);

    foreach ($candidates as $cand) {{
        $sku = trim((string) ($cand['canonical_sku'] ?? ''));
        $row = [
            'sku' => $sku,
            'nombre' => (string) ($cand['nombre_canonico'] ?? ''),
            'producto_base_id' => (int) ($cand['producto_base_id'] ?? 0),
            'map_id' => (int) ($cand['map_id'] ?? 0),
            'sync_state' => (string) ($cand['sync_state'] ?? ''),
            'status' => 'pending',
            'facto_product_id' => null,
            'facto_name' => null,
            'updated' => false,
            'error' => null,
        ];
        $out['checked']++;

        $resp = $client->list_products(['sku' => $sku, 'page' => 1]);
        if (is_wp_error($resp)) {{
            $row['status'] = 'error';
            $row['error'] = $resp->get_error_message();
            $out['errors']++;
            $out['rows'][] = $row;
            if ($sku === '22072') {{
                $out['sku_22072'] = $row;
            }}
            usleep(40000);
            continue;
        }}

        $items = Riverso_Facto_Client::embed_collection($resp, 'products');
        $exact = [];
        foreach ($items as $it) {{
            if (trim((string) ($it['sku'] ?? '')) === $sku) {{
                $exact[] = $it;
            }}
        }}

        if (count($exact) === 0 && count($items) === 1) {{
            $maybe = trim((string) ($items[0]['sku'] ?? ''));
            if ($maybe === '' || $maybe === $sku) {{
                $exact = [$items[0]];
            }}
        }}

        if (count($exact) === 0) {{
            $row['status'] = 'not_found';
            $out['not_found']++;
        }} elseif (count($exact) > 1) {{
            $row['status'] = 'ambiguous';
            $row['error'] = 'Varios product_id para el mismo SKU';
            $out['ambiguous']++;
        }} else {{
            $fid = (int) ($exact[0]['product_id'] ?? $exact[0]['id'] ?? 0);
            if ($fid <= 0) {{
                $row['status'] = 'error';
                $row['error'] = 'Match sin product_id';
                $out['errors']++;
            }} else {{
                $row['status'] = 'match';
                $row['facto_product_id'] = $fid;
                $row['facto_name'] = (string) ($exact[0]['name'] ?? '');
                $out['match']++;

                if ($apply) {{
                    $prev_state = (string) ($cand['sync_state'] ?? '');
                    $new_state = ($prev_state === 'pendiente_excel') ? 'pendiente_excel' : 'linked';
                    $msg = ($new_state === 'pendiente_excel')
                        ? 'Vinculado a FACTO existente; pendiente export Excel'
                        : null;
                    $now = current_time('mysql');
                    $ok = $wpdb->update(
                        "{{$p}}facto_producto_map",
                        [
                            'facto_product_id' => $fid,
                            'facto_sku' => $sku,
                            'sync_state' => $new_state,
                            'last_error' => $msg,
                            'last_synced_at' => $now,
                            'updated_at' => $now,
                        ],
                        ['id' => (int) $cand['map_id']]
                    );
                    $row['updated'] = ($ok !== false);
                    if ($row['updated']) {{
                        $out['updated']++;
                        $row['sync_state'] = $new_state;
                    }} else {{
                        $row['error'] = 'update failed: ' . $wpdb->last_error;
                        $out['errors']++;
                    }}
                }}
            }}
        }}

        $out['rows'][] = $row;
        if ($sku === '22072') {{
            $out['sku_22072'] = $row;
        }}
        usleep(40000);
    }}

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    """)


def run_remote(php: str, remote_name: str) -> dict:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        os.environ['RIVERSO_DEPLOY_HOST'],
        username=os.environ.get('RIVERSO_DEPLOY_USER', 'root'),
        password=os.environ['RIVERSO_DEPLOY_PASSWORD'],
        timeout=30,
    )
    remote_path = f'/tmp/{remote_name}'
    sftp = ssh.open_sftp()
    with sftp.open(remote_path, 'w') as handle:
        handle.write(php)
    sftp.close()

    cmd = (
        'PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); '
        f'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" {remote_path}; '
        f'rm -f {remote_path}'
    )
    _, stdout, stderr = ssh.exec_command(cmd, timeout=900)
    out = stdout.read().decode('utf-8', errors='replace')
    err = stderr.read().decode('utf-8', errors='replace')
    ssh.close()

    if err.strip():
        clean = '\n'.join(
            ln for ln in err.splitlines()
            if 'bluex' not in ln and 'Failed to open stream' not in ln and 'Warning:' not in ln
        )
        if clean.strip():
            print(clean[:3000], file=sys.stderr)

    try:
        return json.loads(out)
    except json.JSONDecodeError:
        print(out[:5000], file=sys.stderr)
        raise SystemExit('Respuesta PHP no es JSON válido')


def print_summary(data: dict) -> None:
    print('=== link_unmapped_facto_skus ===')
    print(f"configured={data.get('configured')} apply={data.get('apply')}")
    print(
        f"candidates={data.get('candidates_total')} checked={data.get('checked')} "
        f"match={data.get('match')} not_found={data.get('not_found')} "
        f"ambiguous={data.get('ambiguous')} errors={data.get('errors')} "
        f"updated={data.get('updated')}"
    )
    sku22072 = data.get('sku_22072')
    if sku22072:
        print('sku_22072=', json.dumps(sku22072, ensure_ascii=False))
    else:
        print('sku_22072= (no estaba en candidatos / no revisado)')

    print('\\n--- matches ---')
    for row in data.get('rows') or []:
        if row.get('status') != 'match':
            continue
        flag = ' UPDATED' if row.get('updated') else ''
        print(
            f"  {row.get('sku')} -> FACTO {row.get('facto_product_id')} "
            f"| {row.get('nombre')[:60]}{flag}"
        )

    print('\\n--- not_found (muestra) ---')
    shown = 0
    for row in data.get('rows') or []:
        if row.get('status') != 'not_found':
            continue
        print(f"  {row.get('sku')} | {row.get('nombre')[:60]}")
        shown += 1
        if shown >= 25:
            break

    bad = [r for r in (data.get('rows') or []) if r.get('status') in ('ambiguous', 'error')]
    if bad:
        print('\\n--- ambiguous/errors ---')
        for row in bad[:30]:
            print(f"  {row.get('sku')} [{row.get('status')}] {row.get('error')}")


def main() -> None:
    parser = argparse.ArgumentParser(description='Vincular SKUs locales ya existentes en FACTO')
    parser.add_argument('--apply', action='store_true', help='Escribe facto_product_id en el mapa')
    parser.add_argument('--limit', type=int, default=0, help='Limitar candidatos (0 = todos)')
    args = parser.parse_args()

    load_env()
    php = build_php(apply=args.apply, limit=args.limit)
    remote = 'link_unmapped_facto_skus_apply.php' if args.apply else 'link_unmapped_facto_skus_dry.php'
    data = run_remote(php, remote)
    print_summary(data)
    # JSON completo a archivo local para auditoría
    out_path = ROOT / 'tools' / ('_link_unmapped_apply.json' if args.apply else '_link_unmapped_dry.json')
    out_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'\\nJSON guardado en {out_path}')


if __name__ == '__main__':
    main()
