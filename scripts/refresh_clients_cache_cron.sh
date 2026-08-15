#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${CALLTRACK_PROJECT_DIR:-/var/www/html/vr/calltrack}"
cd "$PROJECT_DIR"
MODE="${1:-delta}"
[[ "$MODE" == "delta" || "$MODE" == "full" ]] || { echo "Режим должен быть delta или full" >&2; exit 2; }
exec /usr/bin/php api/refresh_clients_cache.php --source=cron --mode="$MODE"
