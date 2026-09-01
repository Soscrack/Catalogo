#!/bin/bash
# Cron Plesk (chroot del dominio): precios Sande 02:00 America/Santiago
export PATH=/opt/plesk/php/8.4/bin:/usr/local/bin:/usr/bin:/bin
export TZ=America/Santiago
LOG="httpdocs/wp-content/uploads/riverso-logs/sande-precios.log"
PHP="/opt/plesk/php/8.4/bin/php"
SCRIPT="httpdocs/wp-content/plugins/riverso-pos/cli/sande-precios-refresh.php"
mkdir -p "$(dirname "$LOG")"
exec "$PHP" "$SCRIPT" >>"$LOG" 2>&1
