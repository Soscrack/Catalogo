#!/usr/bin/env python
"""Smoke-test catalog search by local + catalog SKU."""
import os
import paramiko
from pathlib import Path

ROOT = Path(__file__).resolve().parent

def load_env(path):
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))

load_env(ROOT / ".env.deploy")
WP = os.environ.get("RIVERSO_WP_PATH", "/var/www/vhosts/riverso.cl/httpdocs")

php = f'''<?php
require "{WP}/wp-load.php";
wp_set_current_user(1);
global $wpdb;
$p = $wpdb->prefix . "riverso_";

$local = $wpdb->get_row(
  "SELECT pb.canonical_sku, pb.woocommerce_product_id
   FROM ${{p}}producto_base pb
   WHERE pb.woocommerce_product_id IS NOT NULL AND pb.deleted_at IS NULL
     AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   LIMIT 1", ARRAY_A);
$cat = $wpdb->get_row(
  "SELECT pp.codigo_proveedor, pb.woocommerce_product_id
   FROM ${{p}}producto_proveedor pp
   INNER JOIN ${{p}}producto_base pb ON pb.id = pp.producto_base_id
   WHERE pp.activo = 1 AND pb.woocommerce_product_id IS NOT NULL AND pb.deleted_at IS NULL
     AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor <> ''
   LIMIT 1", ARRAY_A);

echo "local_sample=" . json_encode($local) . "\\n";
echo "cat_sample=" . json_encode($cat) . "\\n";
echo "version=" . RIVERSO_POS_VERSION . "\\n";
$r = new ReflectionClass("Riverso_Woo_Publisher_Module");
echo "file=" . $r->getFileName() . "\\n";

$pub = method_exists("Riverso_Woo_Publisher_Module", "get_instance")
  ? Riverso_Woo_Publisher_Module::get_instance()
  : new Riverso_Woo_Publisher_Module();

if ($local) {{
  $q = $local["canonical_sku"];
  $items = $pub->list_catalog_products(["search" => $q, "limit" => 20, "offset" => 0]);
  $ids = array_column($items, "product_id");
  echo "local_q=$q hit=" . (in_array($local["woocommerce_product_id"], $ids) || in_array((string)$local["woocommerce_product_id"], array_map("strval", $ids), true) ? "yes" : "no") . " n=" . count($items) . "\\n";
}}
if ($cat) {{
  $q = $cat["codigo_proveedor"];
  $items = $pub->list_catalog_products(["search" => $q, "limit" => 20, "offset" => 0]);
  $ids = array_map("strval", array_column($items, "product_id"));
  echo "catalog_q=$q hit=" . (in_array((string)$cat["woocommerce_product_id"], $ids, true) ? "yes" : "no") . " n=" . count($items) . "\\n";
}}
'''

# Fix: ${{p}} in f-string becomes ${p} which is wrong for PHP.
# Use concatenation instead.
php = f'''<?php
require "{WP}/wp-load.php";
wp_set_current_user(1);
global $wpdb;
$p = $wpdb->prefix . "riverso_";

$local = $wpdb->get_row(
  "SELECT pb.canonical_sku, pb.woocommerce_product_id
   FROM {{$p}}producto_base pb
   WHERE pb.woocommerce_product_id IS NOT NULL AND pb.deleted_at IS NULL
     AND pb.canonical_sku IS NOT NULL AND pb.canonical_sku <> ''
   LIMIT 1", ARRAY_A);
$cat = $wpdb->get_row(
  "SELECT pp.codigo_proveedor, pb.woocommerce_product_id
   FROM {{$p}}producto_proveedor pp
   INNER JOIN {{$p}}producto_base pb ON pb.id = pp.producto_base_id
   WHERE pp.activo = 1 AND pb.woocommerce_product_id IS NOT NULL AND pb.deleted_at IS NULL
     AND pp.codigo_proveedor IS NOT NULL AND pp.codigo_proveedor <> ''
   LIMIT 1", ARRAY_A);

echo "local_sample=" . json_encode($local) . "\\n";
echo "cat_sample=" . json_encode($cat) . "\\n";
echo "version=" . RIVERSO_POS_VERSION . "\\n";
$r = new ReflectionClass("Riverso_Woo_Publisher_Module");
echo "file=" . $r->getFileName() . "\\n";

$pub = method_exists("Riverso_Woo_Publisher_Module", "get_instance")
  ? Riverso_Woo_Publisher_Module::get_instance()
  : new Riverso_Woo_Publisher_Module();

if ($local) {{
  $q = $local["canonical_sku"];
  $items = $pub->list_catalog_products(["search" => $q, "limit" => 20, "offset" => 0]);
  $ids = array_map("strval", array_column($items, "product_id"));
  echo "local_q=$q hit=" . (in_array((string)$local["woocommerce_product_id"], $ids, true) ? "yes" : "no") . " n=" . count($items) . "\\n";
}}
if ($cat) {{
  $q = $cat["codigo_proveedor"];
  $items = $pub->list_catalog_products(["search" => $q, "limit" => 20, "offset" => 0]);
  $ids = array_map("strval", array_column($items, "product_id"));
  echo "catalog_q=$q hit=" . (in_array((string)$cat["woocommerce_product_id"], $ids, true) ? "yes" : "no") . " n=" . count($items) . "\\n";
}}
'''

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(os.environ["RIVERSO_DEPLOY_HOST"], username=os.environ["RIVERSO_DEPLOY_USER"], password=os.environ["RIVERSO_DEPLOY_PASSWORD"], timeout=30)
sftp = client.open_sftp()
with sftp.file("/tmp/riverso_search_test.php", "w") as f:
    f.write(php)
sftp.close()
stdin, stdout, stderr = client.exec_command(
    'PHP_BIN=$(ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1); sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/riverso_search_test.php',
    timeout=90,
)
print(stdout.read().decode("utf-8", "replace"))
err = stderr.read().decode("utf-8", "replace")
if err.strip():
    print("STDERR:", err[:2000])
client.close()
