#!/usr/bin/env python3
"""Diagnóstico: tablas competencia + tasa de match sugerido en producción."""
import json
import os
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]


def load_env():
    path = ROOT / ".env.deploy"
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        if "=" in line and not line.strip().startswith("#"):
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


PHP = """<?php
require_once '{wp}/wp-load.php';
require_once '{plugin}/modules/competencia/class-competencia-match-service.php';
global $wpdb;
$p = $wpdb->prefix . 'riverso_';
$tables = [
    'competencia_fuentes',
    'competencia_productos',
    'competencia_match',
    'competencia_medios',
];
$out = ['tables' => [], 'productos_sande' => 0, 'match_stats' => []];
foreach ($tables as $t) {
    $full = $p . $t;
    $out['tables'][$t] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) === $full);
}
$fid = (int) $wpdb->get_var("SELECT id FROM {$p}competencia_fuentes WHERE slug='sande' LIMIT 1");
$out['productos_sande'] = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}competencia_productos WHERE fuente_id=%d", $fid
));
$out['match_stats'] = $wpdb->get_results($wpdb->prepare(
    "SELECT COALESCE(cm.estado,'pendiente') AS estado, COUNT(*) AS total
     FROM {$p}competencia_productos cp
     LEFT JOIN {$p}competencia_match cm ON cm.producto_competencia_id = cp.id
     WHERE cp.fuente_id=%d GROUP BY COALESCE(cm.estado,'pendiente')", $fid
), ARRAY_A) ?: [];
if ($out['productos_sande'] > 0 && empty($out['match_stats'])) {
    $sample = $wpdb->get_row("SELECT * FROM {$p}competencia_productos WHERE fuente_id=$fid LIMIT 1", ARRAY_A);
    if ($sample) {
        $sug = Riverso_Competencia_Match_Service::suggest_for_product($sample);
        $out['sample_suggestion'] = $sug;
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""


def main():
    load_env()
    host = os.environ.get("RIVERSO_DEPLOY_HOST")
    password = os.environ.get("RIVERSO_DEPLOY_PASSWORD")
    wp = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")
    if not host or not password:
        print("Falta .env.deploy")
        return 1
    plugin = f"{wp}/wp-content/plugins/riverso-pos"
    code = PHP.replace("{wp}", wp.replace("'", "'\\''")).replace("{plugin}", plugin.replace("'", "'\\''"))
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=os.environ.get("RIVERSO_DEPLOY_USER", "root"), password=password, timeout=30)
    php_bin = "$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)"
    sftp = ssh.open_sftp()
    remote = "/tmp/diag_competencia.php"
    with sftp.open(remote, "w") as f:
        f.write(code)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        f'sudo -u riverso.cl_1xybiw6rlcq {php_bin} {remote}', timeout=120
    )
    print(stdout.read().decode())
    err = stderr.read().decode().strip()
    if err:
        print("stderr:", err[:500])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
