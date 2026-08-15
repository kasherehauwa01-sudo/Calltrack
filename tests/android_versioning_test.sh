#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_FILE="$ROOT/app/build.gradle"

grep -Fq 'def generatedVersionCode = (System.currentTimeMillis() / 60000L).toInteger()' "$BUILD_FILE"
grep -Fq 'versionCode generatedVersionCode' "$BUILD_FILE"
grep -Fq 'versionName "1.0.15"' "$BUILD_FILE"

if grep -Eq 'version\.properties|publishReleaseApk|calltrackVersionCode|CALLTRACK_VERSION_CODE' "$BUILD_FILE"; then
  echo 'В build.gradle осталась часть прежнего файлового счётчика' >&2
  exit 1
fi

FIRST=$(( $(date +%s) / 60 ))
SECOND=$(( FIRST + 1 ))
(( FIRST > 0 && SECOND > FIRST && SECOND <= 2100000000 ))

echo "android_versioning_test: OK ($FIRST -> $SECOND)"
