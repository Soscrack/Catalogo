#!/usr/bin/env python
import paramiko

HOST = "72.61.37.37"
USER = "root"
PASSWORD = "9.#R/S12yE(4LRSTOMaB"
WP_PATH = "/var/www/vhosts/riverso.cl/httpdocs"

php_code = r'''
global $wpdb;
$p = $wpdb->prefix . "riverso_";
$product_id = $wpdb->get_var($wpdb->prepare(
    "SELECT pb.woocommerce_product_id
     FROM {$p}producto_proveedor pp
     INNER JOIN {$p}producto_base pb ON pb.id = pp.producto_base_id
     WHERE pp.codigo_proveedor = %s
     LIMIT 1",
    "59PHM8"
));
echo "product_id={$product_id}\n";
if ($product_id) {
    $terms = wp_get_post_terms($product_id, "product_cat");
    foreach ($terms as $t) {
        $anc = array_reverse(get_ancestors($t->term_id, "product_cat"));
        $path = [];
        foreach ($anc as $id) {
            $a = get_term($id, "product_cat");
            $path[] = $a->name;
        }
        $path[] = $t->name;
        echo implode(" > ", $path) . "\n";
    }
}
'''

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

sftp = ssh.open_sftp()
remote_php = "/tmp/verify_59phm8.php"
sftp.file(remote_php, "w").write("<?php\n" + php_code)
sftp.close()

command = (
    f"cd '{WP_PATH}' && "
    f"PATH='/opt/plesk/php/8.3/bin:/usr/local/bin:/usr/bin:/bin' "
    f"wp eval-file '{remote_php}' --allow-root"
)
stdin, stdout, stderr = ssh.exec_command(command, timeout=60)
print(stdout.read().decode(errors="replace"))
err = stderr.read().decode(errors="replace")
if err.strip():
    print("ERR:", err)

ssh.close()
