#!/usr/bin/env python
"""Create barcodes table on server"""
import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('72.61.37.37', username='root', password='9.#R/S12yE(4LRSTOMaB', timeout=30)
print('Connected!')

sql = '''
CREATE TABLE IF NOT EXISTS nExLU_riverso_barcodes (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    barcode VARCHAR(50) NOT NULL,
    product_id BIGINT(20) UNSIGNED DEFAULT NULL,
    variation_id BIGINT(20) UNSIGNED DEFAULT NULL,
    sku VARCHAR(100) DEFAULT NULL,
    barcode_type ENUM('EAN13','EAN8','UPC','CODE128','CODE39','INTERNAL') DEFAULT 'EAN13',
    is_primary TINYINT(1) DEFAULT 0,
    notes TEXT,
    source VARCHAR(50) DEFAULT 'manual',
    created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_barcode (barcode),
    KEY idx_product (product_id),
    KEY idx_variation (variation_id),
    KEY idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
'''

# Write SQL to server
sftp = ssh.open_sftp()
with sftp.file('/tmp/create_barcodes.sql', 'w') as f:
    f.write(sql)
sftp.close()

# Execute
cmd = "mysql -u wp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm < /tmp/create_barcodes.sql && echo 'Table created!'"
stdin, stdout, stderr = ssh.exec_command(cmd)
print(stdout.read().decode())
err = stderr.read().decode()
if err and 'Warning' not in err:
    print('Stderr:', err)

# Verify
verify = "mysql -u wp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -e \"SHOW TABLES LIKE '%barcodes%';\""
stdin, stdout, stderr = ssh.exec_command(verify)
print('Tables:', stdout.read().decode())

# Cleanup
ssh.exec_command('rm -f /tmp/create_barcodes.sql')

ssh.close()
print('Done!')
