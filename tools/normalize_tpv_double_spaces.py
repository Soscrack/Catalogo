#!/usr/bin/env python3
"""
Detecta y normaliza nombres con espacios dobles (Riverso + contraste TPV legacy).

FACTO colapsa espacios a uno al guardar. TPV legacy a menudo tiene dos o más.
Este tool actualiza nombre_canonico en Riverso para que Export TPV emita EDITAR.

Uso:
  python tools/normalize_tpv_double_spaces.py           # dry-run
  python tools/normalize_tpv_double_spaces.py --apply   # escribe DB
  python tools/normalize_tpv_double_spaces.py --limit 50
"""
from __future__ import annotations

import argparse
import csv
import json
import os
import re
import sys
import textwrap
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
LEGACY_CSV = ROOT / 'TPV' / 'productos_legacy.csv'
MULTI_SPACE = re.compile(r'\s{2,}')


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


def collapse_spaces(name: str) -> str:
    return re.sub(r'\s+', ' ', (name or '')).strip()


def load_legacy_double_space() -> dict[str, dict]:
    """sku -> {nombre, nombre_norm} for legacy rows with double spaces."""
    out: dict[str, dict] = {}
    if not LEGACY_CSV.is_file():
        return out
    with LEGACY_CSV.open('r', encoding='utf-8-sig', newline='') as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            sku = (row.get('sku') or '').strip()
            nombre = row.get('nombre') or ''
            if not sku or not MULTI_SPACE.search(nombre):
                continue
            out[sku] = {
                'nombre': nombre,
                'nombre_norm': collapse_spaces(nombre),
            }
    return out


def build_php(apply: bool, limit: int) -> str:
    wp = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
    apply_flag = 'true' if apply else 'false'
    limit = max(0, int(limit))
    return textwrap.dedent(f"""\
    <?php
    require_once '{wp}/wp-load.php';

    global $wpdb;
    $p = $wpdb->prefix . 'riverso_';
    $apply = {apply_flag};
    $limit = {limit};

    $sql = "
        SELECT id, canonical_sku, nombre_canonico
        FROM {{$p}}producto_base
        WHERE deleted_at IS NULL
          AND archived_at IS NULL
          AND canonical_sku IS NOT NULL AND canonical_sku <> ''
          AND nombre_canonico IS NOT NULL AND nombre_canonico <> ''
          AND nombre_canonico REGEXP '[[:space:]][[:space:]]'
        ORDER BY canonical_sku ASC
    ";
    if ($limit > 0) {{
        $sql .= $wpdb->prepare(' LIMIT %d', $limit);
    }}

    $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
    $out = [
        'apply' => $apply,
        'candidates' => count($rows),
        'updated' => 0,
        'rows' => [],
    ];

    foreach ($rows as $r) {{
        $antes = (string) ($r['nombre_canonico'] ?? '');
        $despues = trim(preg_replace('/\\s+/u', ' ', $antes));
        $item = [
            'id' => (int) $r['id'],
            'sku' => (string) $r['canonical_sku'],
            'antes' => $antes,
            'despues' => $despues,
            'updated' => false,
        ];
        if ($apply && $despues !== '' && $despues !== $antes) {{
            $ok = $wpdb->update(
                "{{$p}}producto_base",
                [
                    'nombre_canonico' => $despues,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $r['id']]
            );
            $item['updated'] = ($ok !== false);
            if ($item['updated']) {{
                $out['updated']++;
            }}
        }}
        $out['rows'][] = $item;
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
    _, stdout, stderr = ssh.exec_command(cmd, timeout=300)
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


def print_summary(data: dict, legacy: dict[str, dict]) -> None:
    print('=== normalize_tpv_double_spaces ===')
    print(f"apply={data.get('apply')} riverso_candidates={data.get('candidates')} updated={data.get('updated')}")
    print(f"legacy_csv_double_space={len(legacy)}")

    rows = data.get('rows') or []
    in_both = 0
    only_riverso = 0
    print('\\n--- Riverso (antes → después) ---')
    for row in rows[:40]:
        sku = row.get('sku') or ''
        flag = ' [también en legacy TPV]' if sku in legacy else ''
        if sku in legacy:
            in_both += 1
        else:
            only_riverso += 1
        print(f"  {sku}: {row.get('antes')!r} → {row.get('despues')!r}{flag}")
    if len(rows) > 40:
        print(f'  … y {len(rows) - 40} más')

    # Recount full overlap
    in_both = sum(1 for r in rows if (r.get('sku') or '') in legacy)
    only_riverso = len(rows) - in_both
    riverso_skus = {(r.get('sku') or '') for r in rows}
    only_legacy = [s for s in legacy if s not in riverso_skus]
    print(f'\\noverlap_legacy={in_both} only_riverso={only_riverso} only_legacy_csv={len(only_legacy)}')
    if only_legacy[:15]:
        print('--- solo en legacy CSV (muestra) ---')
        for sku in only_legacy[:15]:
            print(f"  {sku}: {legacy[sku]['nombre']!r} → {legacy[sku]['nombre_norm']!r}")


def main() -> None:
    parser = argparse.ArgumentParser(description='Normalizar espacios dobles en nombres TPV/Riverso')
    parser.add_argument('--apply', action='store_true', help='Actualiza nombre_canonico en prod')
    parser.add_argument('--limit', type=int, default=0, help='Limitar candidatos Riverso (0=todos)')
    args = parser.parse_args()

    load_env()
    legacy = load_legacy_double_space()
    php = build_php(apply=args.apply, limit=args.limit)
    remote = 'normalize_tpv_spaces_apply.php' if args.apply else 'normalize_tpv_spaces_dry.php'
    data = run_remote(php, remote)
    print_summary(data, legacy)

    out_path = ROOT / 'tools' / ('_normalize_spaces_apply.json' if args.apply else '_normalize_spaces_dry.json')
    payload = {
        'riverso': data,
        'legacy_count': len(legacy),
        'legacy_sample': {k: legacy[k] for k in list(legacy)[:30]},
    }
    out_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'\\nJSON guardado en {out_path}')


if __name__ == '__main__':
    main()
