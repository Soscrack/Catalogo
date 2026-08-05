#!/usr/bin/env python
"""Deploy riverso-pos plugin to server via Paramiko"""
import paramiko
import os

# SSH credentials are intentionally read from the environment.
HOST = os.environ.get('RIVERSO_DEPLOY_HOST', '72.61.37.37')
USER = os.environ.get('RIVERSO_DEPLOY_USER', 'root')
PASSWORD = os.environ.get('RIVERSO_DEPLOY_PASSWORD')
WP_PATH = os.environ.get('RIVERSO_WP_PATH', '/var/www/vhosts/riverso.cl/httpdocs')
PLUGIN_PATH = f'{WP_PATH}/wp-content/plugins/riverso-pos'

def main():
    if not PASSWORD:
        raise RuntimeError('Define RIVERSO_DEPLOY_PASSWORD antes de desplegar.')

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

    # Preflight, backup, deploy and migrate. A PHP syntax failure stops before
    # touching the active plugin; a migration failure restores the backup.
    commands = f'''
set -e
cd /tmp
rm -rf /tmp/riverso-pos-extract 2>/dev/null
mkdir -p /tmp/riverso-pos-extract
unzip -o riverso-pos-deploy.zip -d /tmp/riverso-pos-extract
PHP_BIN=$(ls /opt/plesk/php/*/bin/php 2>/dev/null | sort -V | tail -1)
test -n "$PHP_BIN"

unzip -Z1 /tmp/riverso-pos-deploy.zip | awk '/[.]php$/ {{print}}' | while IFS= read -r rel; do
  "$PHP_BIN" -l "/tmp/riverso-pos-extract/$rel" >/dev/null
done
echo 'PHP preflight passed'

# Backup current
BACKUP="{PLUGIN_PATH}.bak.$(date +%Y%m%d%H%M%S)"
cp -a {PLUGIN_PATH} "$BACKUP" 2>/dev/null || true

# Copy files (ZIP contains riverso-pos/ prefix)
if [ -d /tmp/riverso-pos-extract/riverso-pos ]; then
  cp -r /tmp/riverso-pos-extract/riverso-pos/* {PLUGIN_PATH}/
else
  cp -r /tmp/riverso-pos-extract/* {PLUGIN_PATH}/
fi

# Remove accidental nested copy from previous deploys
rm -rf {PLUGIN_PATH}/riverso-pos 2>/dev/null || true

# Fix permissions
chown -R riverso.cl_1xybiw6rlcq:psacln {PLUGIN_PATH}
chmod -R 755 {PLUGIN_PATH}

# Trigger idempotent migrations as the site owner.
if ! sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
  require "{WP_PATH}/wp-load.php";
  Riverso_POS_Activator::update_database();
  echo get_option("riverso_pos_db_version"), PHP_EOL;
'; then
  rm -rf {PLUGIN_PATH}
  cp -a "$BACKUP" {PLUGIN_PATH}
  chown -R riverso.cl_1xybiw6rlcq:psacln {PLUGIN_PATH}
  echo 'Migration failed; backup restored' >&2
  exit 1
fi

VERSION=$(sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
  require "{WP_PATH}/wp-load.php";
  echo defined("RIVERSO_POS_VERSION") ? RIVERSO_POS_VERSION : "missing";
')
test "$VERSION" = "1.5.4"

sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" -r '
  require "{WP_PATH}/wp-load.php";
  global $wpdb;
  foreach (["riverso_data_gaps", "riverso_ean_aliases"] as $suffix) {{
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {{
      fwrite(STDERR, "Missing table: " . $table . PHP_EOL);
      exit(1);
    }}
  }}
  echo "schema-ok", PHP_EOL;
'

# Cleanup only after successful verification.
rm -rf /tmp/riverso-pos-extract /tmp/riverso-pos-deploy.zip
echo "Files deployed: $VERSION"
'''

    print('Deploying files...')
    stdin, stdout, stderr = ssh.exec_command(commands, timeout=300)
    print('Output:', stdout.read().decode())
    err = stderr.read().decode()
    if err:
        print('Stderr:', err)
    status = stdout.channel.recv_exit_status()
    if status != 0:
        ssh.close()
        raise RuntimeError(f'Deployment failed with status {status}')

    ssh.close()
    print('\nDeployment complete!')

if __name__ == '__main__':
    main()
