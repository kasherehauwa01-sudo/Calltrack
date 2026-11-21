#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
mode=${1:---check}
[[ "$mode" == --check || "$mode" == --apply ]] || { echo 'Usage: install_churn_permanent.sh [--check|--apply]'; exit 2; }
root=/var/www/html/vr/calltrack-v3-20261026
package=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
review="$package/deploy/churn-permanent/reviewed-git-baseline.json"
patchfile="$package/deploy/churn-permanent/integration.patch"
for tool in php patch sha256sum flock; do command -v "$tool" >/dev/null; done
verify_before() {
 php -r '
 $p=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
 if(($p["baseline_kind"]??null)!=="firstvds-production-lifecycle-user-diff")exit(1);
 if(!hash_equals($p["production_overlay"]["sha256"],hash_file("sha256",$argv[3]."/".$p["production_overlay"]["path"])))exit(1);
 if(!hash_equals($p["patch_sha256"],hash_file("sha256",$argv[3]."/deploy/churn-permanent/integration.patch")))exit(1);
 foreach($p["before"] as $name=>$hash){
  if(!preg_match("~^api/[a-z_]+[.]php$~D",$name)||!preg_match("/^[a-f0-9]{64}$/D",$hash))exit(1);
  $path=$argv[2]."/".$name;
  if(is_link($path)||!is_file($path)||!hash_equals($hash,hash_file("sha256",$path))){fwrite(STDERR,"Unsupported actual source: ".$name."; obtain actual files and review a new patch, never just refresh hashes\n");exit(1);}
 }
 foreach($p["after"] as $name=>$hash){
  $path=$argv[3]."/".$name;
  if(is_link($path)||!is_file($path)||!hash_equals($hash,hash_file("sha256",$path)))exit(1);
 }
 ' "$review" "$root" "$package"
 for file in analytics_permanent.php analytics_permanent_cli.php; do
  [[ ! -e "$root/api/$file" ]] || { echo 'Target exists; review prior installation'; return 1; }
  php -l "$package/api/$file" >/dev/null
 done
 patch --dry-run --batch --forward --fuzz=0 -d "$root" -p1 < "$patchfile" >/dev/null
}
verify_before
echo 'Exact reviewed production lifecycle baseline matches. This does not approve schema, keys, policy or production lifecycle compatibility.'
[[ "$mode" == --apply ]] || exit 0
[[ $EUID == 0 ]] || { echo 'Root operator required'; exit 1; }
# No service or configuration is changed by this script.
for unit in sales-churn-permanent.timer sales-churn-permanent.service calltrack-churn-pilot.timer calltrack-churn-pilot.service; do
 state=$(systemctl show "$unit" --property=ActiveState --value)
 [[ "$state" == inactive || "$state" == failed || -z "$state" ]] || { echo 'Stop concurrent consumers through the approved maintenance procedure first'; exit 1; }
done
[[ "${CALLTRACK_ANALYTICS_MODE:-}" == disabled ]] || { echo 'Maintenance must explicitly select disabled mode; separately verify all actual consumers are disabled'; exit 1; }
exec 9>/run/lock/calltrack-churn-install.lock
flock -n 9
verify_before
# This reads the real API config and information_schema only. No auto-DDL.
php "$package/scripts/check_churn_production_schema.php"
backup=$(mktemp -d /var/backups/calltrack-churn.XXXXXXXX)
cp -a -- "$review" "$backup/reviewed-baseline.json"
cp -a -- "$patchfile" "$backup/integration.patch"
files=(analytics_integration.php manager_tasks_service.php analytics_manager_pilot_budget.php analytics_verified_approval.php analytics_source_trust.php)
for file in "${files[@]}"; do cp -a -- "$root/api/$file" "$backup/$file"; done
switched=0
rollback() {
 rc=${1:-$?}; trap - ERR INT TERM
 if [[ $switched == 1 ]]; then
  for file in "${files[@]}"; do cp -a -- "$backup/$file" "$root/api/$file" || echo "MANUAL RESTORE REQUIRED: $file" >&2; done
 fi
 echo "Installation stopped; preserve backup $backup and any new files for audit" >&2
 exit "$rc"
}
trap rollback ERR
trap 'rollback 130' INT
trap 'rollback 143' TERM
switched=1
patch --batch --forward --fuzz=0 -d "$root" -p1 < "$patchfile" >/dev/null
for file in analytics_permanent.php analytics_permanent_cli.php; do install -o root -g root -m 0644 "$package/api/$file" "$root/api/$file"; done
for file in "${files[@]}" analytics_permanent.php analytics_permanent_cli.php; do php -l "$root/api/$file" >/dev/null; done
php -r '$p=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);foreach($p["after"] as $n=>$h)if(!hash_equals($h,hash_file("sha256",$argv[2]."/".$n)))exit(1);' "$review" "$root"
trap - ERR INT TERM
echo "Code installed; backup: $backup. No schema, storage, key, manifest, policy or service changes performed."
