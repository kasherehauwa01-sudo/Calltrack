#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/html/vr/calltrack}"
CRON_USER="${CALLTRACK_CRON_USER:-www-data}"
CRONTAB_BIN="${CALLTRACK_CRONTAB_BIN:-crontab}"
CRON_MARKER_FILE="${CALLTRACK_CLIENTS_CRON_MARKER:-/etc/calltrack/clients-cache-cron.installed}"
MARKER="# CALLTRACK_CLIENTS_CACHE_REFRESH"
CRON_FILE="$(mktemp)"
trap 'rm -f "$CRON_FILE"' EXIT

"$CRONTAB_BIN" -u "$CRON_USER" -l 2>/dev/null | awk -v marker="$MARKER" '
  $0 == marker { skip=1; next }
  skip && $0 == marker " END" { skip=0; next }
  !skip { print }
' > "$CRON_FILE"

cat >> "$CRON_FILE" <<EOF
$MARKER
CRON_TZ=Europe/Moscow
0 4 * * * CALLTRACK_PROJECT_DIR=$(printf '%q' "$PROJECT_DIR") /usr/bin/env bash $(printf '%q' "$PROJECT_DIR/scripts/refresh_clients_cache_cron.sh") >/dev/null 2>&1
$MARKER END
EOF

"$CRONTAB_BIN" -u "$CRON_USER" "$CRON_FILE"
install -d -o "$CRON_USER" -g "$CRON_USER" -m 0775 "$PROJECT_DIR/storage" "$PROJECT_DIR/storage/logs"
install -d -m 0755 "$(dirname "$CRON_MARKER_FILE")"
printf 'installed_at=%s\ntimezone=Europe/Moscow\nschedule=0 4 * * *\n' "$(date -Iseconds)" > "$CRON_MARKER_FILE"
chmod 0644 "$CRON_MARKER_FILE"
echo "Cron обновления Clients установлен для пользователя $CRON_USER на 04:00 Europe/Moscow"
