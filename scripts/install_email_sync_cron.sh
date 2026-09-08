#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/html/vr/calltrack}"
CRON_USER="${CALLTRACK_CRON_USER:-www-data}"
CRONTAB_BIN="${CALLTRACK_CRONTAB_BIN:-crontab}"
MARKER="# CALLTRACK_EMAIL_SYNC"
CRON_FILE="$(mktemp)"
trap 'rm -f "$CRON_FILE"' EXIT

"$CRONTAB_BIN" -u "$CRON_USER" -l 2>/dev/null | awk -v marker="$MARKER" '
  $0 == marker { skip=1; next }
  skip && $0 == marker " END" { skip=0; next }
  !skip { print }
' > "$CRON_FILE"

cat >> "$CRON_FILE" <<EOF
$MARKER
7 * * * * CALLTRACK_PROJECT_DIR=$(printf '%q' "$PROJECT_DIR") /usr/bin/env bash $(printf '%q' "$PROJECT_DIR/scripts/sync_email_cron.sh") >>$(printf '%q' "$PROJECT_DIR/storage/logs/email-sync.log") 2>&1
$MARKER END
EOF

install -d -o "$CRON_USER" -g "$CRON_USER" -m 0775 "$PROJECT_DIR/storage/logs"
"$CRONTAB_BIN" -u "$CRON_USER" "$CRON_FILE"
echo "Cron исходящих Email установлен: каждый час в 07 минут"
