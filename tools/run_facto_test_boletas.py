"""Sube y ejecuta tools/facto_test_boletas.php en el servidor. Uso: python tools/run_facto_test_boletas.py dry|emit"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import paramiko  # noqa: E402
import deploy_plugin as dp  # noqa: E402

mode = sys.argv[1] if len(sys.argv) > 1 else 'dry'
script = sys.argv[2] if len(sys.argv) > 2 else 'facto_test_boletas.php'
local = os.path.join(os.path.dirname(os.path.abspath(__file__)), script)

ssh = paramiko.SSHClient()
dp._ssh_connect(ssh)
sftp = ssh.open_sftp()
sftp.put(local, '/tmp/facto_test_boletas.php')
sftp.close()
cmd = (
    'chmod 644 /tmp/facto_test_boletas.php && '
    'PHP_BIN=$(ls /opt/plesk/php/*/bin/php | sort -V | tail -1) && '
    f'sudo -u riverso.cl_1xybiw6rlcq "$PHP_BIN" /tmp/facto_test_boletas.php {dp.WP_PATH} {mode}; '
    'rm -f /tmp/facto_test_boletas.php'
)
_, out, err = ssh.exec_command(cmd, timeout=600)
print(out.read().decode('utf-8', 'replace'))
e = err.read().decode('utf-8', 'replace')
if e.strip():
    print('STDERR:', e[-3000:])
ssh.close()
