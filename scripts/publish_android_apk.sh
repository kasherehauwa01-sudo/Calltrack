#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION_FILE="$PROJECT_DIR/version.properties"
LOCK_FILE="$PROJECT_DIR/.version.properties.lock"
exec 9>"$LOCK_FILE"
flock 9

read_property() {
  local key="$1"
  sed -n "s/^${key}=//p" "$VERSION_FILE" | tail -n 1 | tr -d '\r' | xargs
}

LAST_CODE="$(read_property VERSION_CODE)"
NAME_PREFIX="$(read_property VERSION_NAME_PREFIX)"
[[ "$LAST_CODE" =~ ^[1-9][0-9]*$ ]] || { echo "VERSION_CODE должен быть положительным Integer" >&2; exit 2; }
[[ "$NAME_PREFIX" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "VERSION_NAME_PREFIX должен иметь формат X.Y" >&2; exit 2; }
NEXT_CODE=$((LAST_CODE + 1))
NEXT_NAME="${NAME_PREFIX}.${NEXT_CODE}"

if [[ -x "$PROJECT_DIR/gradlew" ]]; then
  GRADLE=("$PROJECT_DIR/gradlew")
else
  GRADLE=("${GRADLE_BIN:-gradle}")
fi

"${GRADLE[@]}" --no-daemon --no-parallel --project-cache-dir "$PROJECT_DIR/.gradle/publish-worker" :app:assembleRelease \
  -PcalltrackVersionCode="$NEXT_CODE" \
  -PcalltrackVersionName="$NEXT_NAME"

APK="$PROJECT_DIR/app/build/outputs/apk/release/calltrack_v${NEXT_NAME}_${NEXT_CODE}.apk"
[[ -s "$APK" ]] || { echo "Публикационный APK не создан: $APK" >&2; exit 3; }

SDK_ROOT="${ANDROID_SDK_ROOT:-${ANDROID_HOME:-}}"
[[ -n "$SDK_ROOT" && -d "$SDK_ROOT/build-tools" ]] || { echo "Не найден Android SDK для проверки APK" >&2; exit 4; }
AAPT="$(find "$SDK_ROOT/build-tools" -mindepth 2 -maxdepth 2 -type f \( -name aapt -o -name aapt.exe \) | sort -V | tail -n 1)"
[[ -x "$AAPT" ]] || { echo "Не найден aapt для проверки APK" >&2; exit 4; }
BADGING="$($AAPT dump badging "$APK")"
PACKAGE_LINE="$(printf '%s\n' "$BADGING" | sed -n '1p')"
ACTUAL_CODE="$(printf '%s\n' "$PACKAGE_LINE" | sed -n "s/.*versionCode='\([0-9][0-9]*\)'.*/\1/p")"
ACTUAL_NAME="$(printf '%s\n' "$PACKAGE_LINE" | sed -n "s/.*versionName='\([^']*\)'.*/\1/p")"
[[ "$ACTUAL_CODE" == "$NEXT_CODE" && "$ACTUAL_NAME" == "$NEXT_NAME" ]] || {
  echo "Метаданные APK не совпали: ожидались $NEXT_NAME ($NEXT_CODE), получено $ACTUAL_NAME ($ACTUAL_CODE)" >&2
  exit 5
}

TEMP_FILE="$VERSION_FILE.tmp.$$"
printf '# Последний успешно созданный публикационный APK\nVERSION_CODE=%s\nVERSION_NAME_PREFIX=%s\n' "$NEXT_CODE" "$NAME_PREFIX" > "$TEMP_FILE"
mv -f "$TEMP_FILE" "$VERSION_FILE"
printf 'Создан %s: versionName=%s, versionCode=%s\n' "$APK" "$ACTUAL_NAME" "$ACTUAL_CODE"
