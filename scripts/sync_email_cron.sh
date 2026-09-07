#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${CALLTRACK_PROJECT_DIR:-/var/www/html/vr/calltrack}"
cd "$PROJECT_DIR"
exec /usr/bin/php api/sync_email.php
