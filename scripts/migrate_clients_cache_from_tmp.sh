#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/html/vr/calltrack}"
TARGET="$PROJECT_DIR/storage/cache/clients"
install -d -o www-data -g www-data -m 0775 "$TARGET" "$TARGET/shards" "$TARGET/temp"

copy_newest() {
  local pattern="$1" target="$2" source
  source="$(find /tmp -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' 2>/dev/null | sort -nr | awk 'NR==1{$1="";sub(/^ /,"");print}')"
  [[ -n "$source" ]] || return 0
  install -o www-data -g www-data -m 0664 "$source" "$target.migrating"
  cmp -s "$source" "$target.migrating" || { echo "Ошибка проверки копии $source" >&2; exit 1; }
  mv -f "$target.migrating" "$target"
  echo "Скопирован $source -> $target ($(stat -c %s "$target") байт)"
}

copy_newest 'calltrack_clients_v2_*.json' "$TARGET/clients.json"
copy_newest 'calltrack_clients_phone_index_*.json' "$TARGET/phone_index.json"

# Старые файлы в /tmp намеренно не удаляются. Shards будут полностью созданы
# следующим успешным потоковым обновлением, после чего появится shards.ready.
echo "Миграция завершена. Старые файлы /tmp сохранены."
