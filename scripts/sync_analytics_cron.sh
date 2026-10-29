#!/usr/bin/env bash
set -euo pipefail
# No cron installation and no implicit enabling. Inherit protected environment.
: "${CALLTRACK_ANALYTICS_MODE:=disabled}"
export CALLTRACK_ANALYTICS_MODE
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec php "${script_dir}/../api/analytics_sync.php"
