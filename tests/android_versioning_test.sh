#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d /var/tmp/calltrack_versioning_test_XXXXXX)"
trap 'rm -rf "$TEST_ROOT"' EXIT
mkdir -p "$TEST_ROOT/scripts" "$TEST_ROOT/app/build/outputs/apk/release" "$TEST_ROOT/sdk/build-tools/35.0.0" "$TEST_ROOT/bin"
cp "$ROOT/scripts/publish_android_apk.sh" "$TEST_ROOT/scripts/"
cat > "$TEST_ROOT/version.properties" <<'EOF'
VERSION_CODE=15
VERSION_NAME_PREFIX=1.0
EOF
cat > "$TEST_ROOT/bin/gradle" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
[[ "${FAIL_BUILD:-0}" == 1 ]] && exit 42
code="" name=""
for arg in "$@"; do
  [[ "$arg" == -PcalltrackVersionCode=* ]] && code="${arg#*=}"
  [[ "$arg" == -PcalltrackVersionName=* ]] && name="${arg#*=}"
done
mkdir -p app/build/outputs/apk/release
printf 'fake apk' > "app/build/outputs/apk/release/calltrack_v${name}_${code}.apk"
MOCK
cat > "$TEST_ROOT/sdk/build-tools/35.0.0/aapt" <<'MOCK'
#!/usr/bin/env bash
file="${3##*/}"
version="${file#calltrack_v}"; version="${version%.apk}"
code="${version##*_}"; name="${version%_*}"
printf "package: name='com.example.calltrack' versionCode='%s' versionName='%s'\n" "$code" "$name"
MOCK
chmod +x "$TEST_ROOT/bin/gradle" "$TEST_ROOT/sdk/build-tools/35.0.0/aapt" "$TEST_ROOT/scripts/publish_android_apk.sh"

(
  cd "$TEST_ROOT"
  if PATH="$TEST_ROOT/bin:$PATH" ANDROID_SDK_ROOT="$TEST_ROOT/sdk" FAIL_BUILD=1 scripts/publish_android_apk.sh >/dev/null 2>&1; then
    echo 'Сборка с ошибкой неожиданно завершилась успешно' >&2; exit 1
  fi
  grep -qx 'VERSION_CODE=15' version.properties
  PATH="$TEST_ROOT/bin:$PATH" ANDROID_SDK_ROOT="$TEST_ROOT/sdk" scripts/publish_android_apk.sh >/dev/null & first=$!
  PATH="$TEST_ROOT/bin:$PATH" ANDROID_SDK_ROOT="$TEST_ROOT/sdk" scripts/publish_android_apk.sh >/dev/null & second=$!
  wait "$first" "$second"
)
grep -qx 'VERSION_CODE=17' "$TEST_ROOT/version.properties"
test -s "$TEST_ROOT/app/build/outputs/apk/release/calltrack_v1.0.16_16.apk"
test -s "$TEST_ROOT/app/build/outputs/apk/release/calltrack_v1.0.17_17.apk"
echo "android_versioning_test: OK"
