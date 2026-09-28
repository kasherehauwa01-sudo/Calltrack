#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/html/vr/calltrack}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Оба установщика идемпотентны и заменяют только собственные маркированные
# блоки crontab пользователя Calltrack.
bash "$SCRIPT_DIR/install_clients_cache_cron.sh" "$PROJECT_DIR"
bash "$SCRIPT_DIR/install_email_sync_cron.sh" "$PROJECT_DIR"

echo "Фоновые задания Calltrack установлены"
