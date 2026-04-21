#!/usr/bin/env python
"""Deploy riverso-pos plugin to server via Paramiko"""
import paramiko
import os

# SSH credentials
HOST = '72.61.37.37'
USER = 'root'
PASSWORD = '9.#R/S12yE(4LRSTOMaB'
WP_PATH = '/var/www/vhosts/riverso.cl/httpdocs'
PLUGIN_PATH = f'{WP_PATH}/wp-content/plugins/riverso-pos'

def main():
    # Connect
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print('Connecting to server...')
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    print('Connected!')

    # Upload ZIP via SFTP
    sftp = ssh.open_sftp()
    print('Uploading ZIP...')
    sftp.put('riverso-pos-deploy.zip', '/tmp/riverso-pos-deploy.zip')
    print('Upload complete!')
    sftp.close()

    # Extract and setup
    commands = f'''
cd /tmp
rm -rf /tmp/riverso-pos-extract 2>/dev/null
mkdir -p /tmp/riverso-pos-extract
unzip -o riverso-pos-deploy.zip -d /tmp/riverso-pos-extract

# Backup current
cp -r {PLUGIN_PATH} {PLUGIN_PATH}.bak.$(date +%Y%m%d%H%M%S) 2>/dev/null || true

# Copy files
cp -r /tmp/riverso-pos-extract/* {PLUGIN_PATH}/

# Fix permissions
chown -R riverso.cl_1xybiw6rlcq:psacln {PLUGIN_PATH}
chmod -R 755 {PLUGIN_PATH}

# Cleanup
rm -rf /tmp/riverso-pos-extract /tmp/riverso-pos-deploy.zip

echo 'Files deployed!'
'''

    print('Deploying files...')
    stdin, stdout, stderr = ssh.exec_command(commands)
    print('Output:', stdout.read().decode())
    err = stderr.read().decode()
    if err:
        print('Stderr:', err)

    # Create POS tables
    mysql_cmd = '''
mysql -uwp_hsvmc -p'z7yCU31@7oZ1?ul@' wp_6z3tm -e "
CREATE TABLE IF NOT EXISTS nExLU_riverso_pos_sessions (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    register_name VARCHAR(100) DEFAULT 'Caja 1',
    opening_amount DECIMAL(12,2) DEFAULT 0,
    closing_amount DECIMAL(12,2) DEFAULT NULL,
    expected_amount DECIMAL(12,2) DEFAULT NULL,
    difference DECIMAL(12,2) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME DEFAULT NULL,
    notes TEXT,
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_status (status),
    KEY idx_opened_at (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS nExLU_riverso_pos_held_orders (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT(20) UNSIGNED NOT NULL,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
    customer_name VARCHAR(255) DEFAULT NULL,
    cart_data LONGTEXT NOT NULL,
    total DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session (session_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE IF NOT EXISTS nExLU_riverso_pos_payments (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT(20) UNSIGNED NOT NULL,
    order_id BIGINT(20) UNSIGNED NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session (session_id),
    KEY idx_order (order_id),
    KEY idx_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
"

echo "POS tables created!"
'''
    print('Creating POS tables...')
    stdin, stdout, stderr = ssh.exec_command(mysql_cmd)
    print('Output:', stdout.read().decode())
    err = stderr.read().decode()
    if err:
        print('Stderr:', err)

    ssh.close()
    print('\nDeployment complete!')

if __name__ == '__main__':
    main()
