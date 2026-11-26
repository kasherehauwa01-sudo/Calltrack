#!/usr/bin/env bash
# Explicit opt-in only. --check (the default) does not start synchronization.
set -euo pipefail
RELEASE_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
export CALLTRACK_STORAGE_DIR=/var/www/html/vr/calltrack/storage
export CALLTRACK_CLIENTS_DISABLE_SQLITE=1
MODE="${1:---check}"
[[ "$(id -un)" == www-data ]] || { echo "Run as www-data" >&2; exit 1; }
[[ -r "$RELEASE_DIR/api/refresh_clients_cache.php" && -w "$CALLTRACK_STORAGE_DIR/clients_cache_refresh.lock" && -w "$CALLTRACK_STORAGE_DIR/cache/clients" && -w "$CALLTRACK_STORAGE_DIR/logs" ]] || { echo "NO-GO: paths/permissions" >&2; exit 1; }
case "$MODE" in
  --check) exec /usr/bin/php "$RELEASE_DIR/scripts/build_client_identity_index.php" --check ;;
  full|delta) exec /usr/bin/timeout --signal=TERM --kill-after=30s 45m /usr/bin/php -d memory_limit=256M "$RELEASE_DIR/api/refresh_clients_cache.php" --source=cron --mode="$MODE" ;;
  *) echo "Use --check, full or delta" >&2; exit 2 ;;
esac
