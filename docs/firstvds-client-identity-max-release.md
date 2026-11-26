# FirstVDS: client identity index + existing MAX integration

Status: **NO-GO for production until the gates below are satisfied**. This is a reviewable backend release candidate, not permission to deploy. No server, customer data, database, cron, production branch, or Sales Journal changes were performed. PR: https://github.com/kasherehauwa01-sudo/Calltrack/pull/521.

## Source and scope

| Reference | Verified SHA | Relationship / decision |
| --- | --- | --- |
| main | a4a02cf4716b37e03bb9d0ff221745127c2c4807 | Current baseline at preparation; recheck before deployment |
| origin/production/max-message-sync | 715be8462deaf270c0729310abab25961f91772a | Diverged: main has 64 commits, production has 1 beyond common ancestor |
| Actual server checkout | cfbab96539bbcadcf1963991f77c10ccc78ce5a8 | User-confirmed; ancestor of main by 18 commits; does not identify all serving files |
| PR #520 / feat/client-identity-index | 31ca7fc5acb63f394c2940842408c7dc0507fe70 | Release base: main plus the reviewed index implementation |
| Sales Journal | 2d194c0 | Existing API inspected read-only; not modified |

The production-only commit changes six paths: add_max_messages.php, max_messages_service.php, the MAX SQL migration, docs/max_message_sync.md and two MAX synchronization tests. **All six already have identical contents in main and this release.** No production path is missing from main. Twenty shared paths have newer contents in main, including Android and card functionality. Starting from PR #520 preserves those updates instead of reverting them to the older production branch. The automated audit verifies MAX equality and that this release does not change app/ or database/ relative to main.

There were no mechanical merge conflicts: the release starts from PR #520. Semantic integration changes are the MAX direct-client identity path, refresh publication/recovery, and the status path. The only observed HTML difference is the manually updated manager-tasks.js query version, 20261117-tasks-6; this release preserves it. The install package does **not** replace HTML or other frontend files.

Do not deploy the entire main/release tree over production. The Git tree also contains unrelated main analytics changes. The code-only package has a strict eleven-file payload and preserves the existing serving tree and private configuration.

## Observed installation (user's read-only evidence)

- Git: /var/www/html/vr/calltrack
- Actual API: /var/www/html/vr/calltrack-v3-20261026/api
- Actual frontend: /var/www/html/vr/calltrack-v3-20261026/analizmop
- Shared storage: /var/www/html/vr/calltrack/storage
- serving/storage is already a symlink to that shared storage.
- Nginx aliases and PHP SCRIPT_FILENAME point to the serving directory, not the Git checkout.
- PHP CLI 8.5.12, no pdo_sqlite; PHP-FPM user www-data.
- Serving tree 8.6 MiB; existing clients cache 177 MiB; latest disk availability **4.4 GiB**, superseding earlier 5.3 GiB.
- storage root root:root 0775 is not writable by www-data.
- Existing refresh lock root:www-data 0664, logs www-data:www-data 0750 and cache/clients www-data:www-data 0775 are writable.
- 25 selected PHP dependency hashes match the inspected Git baseline. Six selected frontend files match; the seventh, index.html, differs only in the confirmed script query version.
- deploy/firstvds/observed-files.json pins these 32 files. This is a dependency baseline, **not** proof that every other server file equals Git.

The new refresh status is written atomically inside cache/clients. Readers fall back to the old root status until the new one exists. The existing root status is retained; no broad chmod/chown is needed. The refresh lock and sync cursor locations do not change.

## Payload and protected files

Modified API:
- api/client_catalog.php
- api/client_catalog_lookup.php
- api/client_directory.php
- api/client_events.php
- api/client_purchases.php
- api/clients_cache_refresh.php
- api/sales_buyer_catalog.php
- api/max_message_history.php

New:
- api/client_identity_index.php
- scripts/build_client_identity_index.php
- scripts/firstvds_clients_refresh.sh

Never overwrite/delete configuration, .env, keys, storage, logs, client snapshots, databases, app/, database/, serving frontend, api/add_max_messages.php, api/max_messages_service.php or unrelated analytics/task APIs. The package contains no database migration, APK, secret or customer data.

MAX receiver, authenticated upload, source-event deduplication, incoming/outgoing metadata, SQL schema and migration are preserved. Client history retains per-day incoming/outgoing counts and existing timezone behavior. Here “outgoing” means observation/synchronization of a message sent in MAX; Calltrack is not being given a new API to send chat text. Message text is not introduced into storage. Direct client MAX matching now uses the same checked identity snapshot as purchases/events, avoiding a separate stale phone cache.

Sales Journal still uses the existing buyers/client-sales/client-insights/category/detail API contracts. Ambiguous names, phones or emails cannot establish a unique identity; manager ACL is checked before history access. Fixture HTTP tests cover contracts and outages; they do not certify the live Sales Journal container or network latency.

## Index publication and recovery

The source fence uses paths relative to the shared cache plus file device/inode/size/mtime/ctime. CLI and FPM can therefore use different symlink aliases to the same storage without invalidating a valid generation; different/changed source files still fail the fence. All writers must use coordinated publication; arbitrary in-place external edits preserving stat metadata are outside this guarantee.

The file-only index has immutable generations, sharded ID records and global normalized-name/phone/email uniqueness, version/source fences and checksums. Normal card requests read the relevant shards, not all 165,000 clients. Invalid, missing, stale, corrupt or concurrently publishing indexes return controlled 503, not a permissive fallback.

Full/delta mutation holds the identity publication lock and leaves an incomplete marker until records, keys and manifest agree. Offline --build refuses an incomplete mutation. A crash cannot be “fixed” by certifying partially changed records.

Delta recovery journals the exact page hash and allowed cursor before mutation. Retrying that same page is idempotent; another cursor or changed page is rejected. If the source no longer reproduces the pending page, perform a separately approved full refresh. **Never remove incomplete.json to bypass recovery.** An entire multi-page delta publishes one new generation, not one index per page. Removed/changed clients and manager assignments are indexed from the completed snapshot.

Readers fail quickly with 503 during the exclusive publication window. This deliberately trades availability for consistent identity and ACL. A full refresh downloads/stages before publication, but its final publication/rebuild still blocks readers. Delta holds the lock across its page series, including source waiting time. Do not interpret fast individual lookups as zero-downtime synchronization.

Abandoned derived generations and manifest temporary files are pruned under the index lock. Index/full staging have a 512 MiB per-directory guard and 256 MiB free-space floor. These are conservative guardrails, not a disk reservation or a universal limit on the whole cache. Repeated interrupted full staging can reach the guard and require operator inspection; never indiscriminately delete canonical records, clients.json, cursor or locks.

## Validation and measurements

Reproducible CI: .github/workflows/firstvds-release.yml, Ubuntu 24.04, PHP **8.5.12**, isolated MariaDB **11.8.6**, with and without SQLite. The no-SQLite PHP binary is actually built without the extension. Tests use disposable fixtures and synthetic data only.

Validation includes PHP lint, git diff --check, lookup/purchases/history/refresh/delta/index/catalog tests, MAX sync/history PHP and JS tests, MariaDB deduplication/history tests, HTTP role/CSRF/forged-owner tests, missing/corrupt/stale index HTTP 503, restored calls/email, purchase total, www-data permissions and package drift/partial-install rejection. A real Chromium test checks MAX card rendering. A separate HTTP benchmark adds 165,000 synthetic clients to the local integration fixture, then times ten sequential purchases/events/MAX card-load groups against actual PHP handlers and isolated MariaDB, using a local Sales Journal contract stub; the two-second assertion does not establish live FirstVDS latency. Android sources/schema remain byte-identical to main. Validation at 6656ac2: Android JVM execution was blocked because Gradle could not resolve com.google.devtools.ksp:1.9.24-1.0.20. The existing max_call_integration_test.php also expects Room version 8 and fails identically on main/release; the current schema is newer. These are disclosed limitations, not passing Android tests. The package distributes no APK.

Initial complete backend run: https://github.com/kasherehauwa01-sudo/Calltrack/actions/runs/45815390593 at 6562ae4709a61876056c32ccd93ff399bb4c6090; both PHP matrix jobs passed. Later runs extend validation and publish the package; consult the PR's final test report for their exact SHA/results.

165,000 synthetic records; source 53,786,671 bytes; 2 CPU / 4 GiB container; PHP memory_limit 128M; www-data; no SQLite:

| Measurement | Observed initial combined release |
| --- | --- |
| Original lookup + global uniqueness | 6.2144 s, 8 MiB PHP peak |
| Indexed lookup p50 / p95 / max, 20 fresh PHP processes | 0.00462 / 0.00484 / 0.00516 s |
| Indexed PHP peak | 4 MiB per process |
| Generate synthetic full snapshot + publish index | 11.083 s, 10 MiB PHP peak |
| Three delta pages + one final rebuild | 8.608 s; all 3 attempted reads safely blocked |
| Index files | 59,663,302 bytes (~56.9 MiB) |
| Total published synthetic cache | 233,892,888 bytes (~223 MiB) |

These measure **directory resolution and identity uniqueness**, not the entire HTTP card, remote API latency, process RSS or cold disk performance. OS page cache is warm. Added parallel benchmark runs four readers in five waves; results are in CI. The production goal of a complete card below two seconds remains a deployment smoke-test gate.

Budget conservatively for the current cache backup (~177 MiB), the new index (~57 MiB), another index generation (~57 MiB), full staged snapshot plus derived files (~223 MiB at the synthetic size), and code copy/backup (<20 MiB observed). Reserve at least 1 GiB free above measured staging/backup needs. Actual large/custom fields can exceed synthetic measurements. PHP peak is not RSS; service MemoryMax=512M and PHP memory_limit=256M bound the proposed refresh service, but require an approved server test before scheduling. Do not run full sync concurrently with other memory-heavy jobs.

## Build and verification (local/CI only)

From a clean checked-out release commit:
```bash
python3 scripts/build_firstvds_release.py plan
python3 scripts/build_firstvds_release.py build --output /tmp/calltrack-firstvds-release.tar.gz
python3 tests/firstvds_package_test.py
php tests/firstvds_storage_permissions_test.php
php -d memory_limit=128M tests/firstvds_release_benchmark.php
```
The permissions fixture requires an isolated root container with www-data; do not run tests on production. CI uploads the code-only tar.gz. Default builder mode only prints a plan. It never installs/enables anything. Verify the artifact SHA256 and embedded commit against the reviewed CI run, not a moving branch. Archive checksums are consistency checks, not a substitute for trusted artifact provenance.

## FirstVDS procedure — separate, explicitly approved maintenance

**Nothing in this section has been executed on FirstVDS.** Do not paste all phases as an unattended deploy script. Abort on any failed gate. Keep secrets/backups root-only; never send private configuration content.

### A. Read-only gates

Re-run the user's hash/permission/path checks immediately before installation. Confirm the actual FPM pool uses PHP8.5.12 and resolves the same shared storage, existing Clients/Sales Journal configuration and manager sessions. Verify HTTP disallows /storage/ and /scripts/ access; the index is private data. Check existing refresh cron/timers and background work locally without publishing environment values. Confirm no partial update marker is present and a current client source/cursor is usable.

```bash
readlink -f /var/www/html/vr/calltrack-v3-20261026/storage
stat -c '%U:%G %a %n' /var/www/html/vr/calltrack/storage/clients_cache_refresh.lock /var/www/html/vr/calltrack/storage/logs /var/www/html/vr/calltrack/storage/cache/clients
sudo du -sh /var/www/html/vr/calltrack-v3-20261026 /var/www/html/vr/calltrack/storage/cache/clients
df -h /var/www/html/vr /var/backups
php -r 'echo PHP_VERSION," sqlite=",extension_loaded("pdo_sqlite")?"yes":"no",PHP_EOL;'
```

The last confirmed successful delta was 2026-10-28. This release does not assert it is still current. An index built from old data is only consistent with that old snapshot. Source freshness and current manager assignments must be checked before approval.

### B. Stage and back up

Use a verified artifact extracted into the new absolute directory /var/tmp/calltrack-firstvds-bundle (refuse an existing directory). Inspect archive members first: only regular files, no absolute names, traversal, links, or unexpected payload files; the package test documents that layout. Extraction does not authorize installation.

```bash
sudo python3 /var/tmp/calltrack-firstvds-bundle/verify.py check \
  --bundle /var/tmp/calltrack-firstvds-bundle \
  --serving /var/www/html/vr/calltrack-v3-20261026 --phase baseline
```

If any dependency changed, **NO-GO**: re-audit it and rebuild the baseline; do not update hashes simply to bypass the check.

For the remaining commands use one root maintenance shell, set -euo pipefail and umask 077. Set RELEASE_SHA by reading manifest.json, require exactly 40 lowercase hex digits, and verify it is the reviewed commit. Paths below are deliberately fixed; do not substitute the Git checkout for the serving path.

```bash
set -euo pipefail
umask 077
BUNDLE=/var/tmp/calltrack-firstvds-bundle
SERVING=/var/www/html/vr/calltrack-v3-20261026
STORAGE=/var/www/html/vr/calltrack/storage
RELEASE_SHA=$(python3 -c 'import json; print(json.load(open("/var/tmp/calltrack-firstvds-bundle/manifest.json"))["commit"])')
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]
STAGE="/var/www/html/vr/calltrack-releases/$RELEASE_SHA"
BACKUP="/var/backups/calltrack-$RELEASE_SHA-$(date +%Y%m%d-%H%M%S)"
test -d "$SERVING/api"
test -L "$SERVING/storage"
test "$(readlink -f "$SERVING/storage")" = "$STORAGE"
test ! -e "$STAGE"
test ! -e "$BACKUP"
mkdir -p /var/www/html/vr/calltrack-releases
chmod 0755 /var/www/html/vr/calltrack-releases
mkdir -m 0700 "$BACKUP"
cp -a "$SERVING" "$BACKUP/serving"
cp -a "$SERVING" "$STAGE"
test "$(readlink -f "$STAGE/storage")" = "$STORAGE"
```

cp -a preserves the storage symlink; it must not follow it into live data. The private backup can include existing credentials, so keep /var/backups copy root-only and do not upload it. Preserve existing file ownership/modes in the serving clone.

Pause all old refresh entry points during the agreed maintenance window and make client-card routes unavailable before snapshot/build/switch. Do not stop unrelated services. Confirm the existing global refresh lock is free. Under that same lock, make a consistent cache backup (including cursor, manifests and derived index) before --build or any full/delta refresh:
```bash
/usr/bin/flock -n "$STORAGE/clients_cache_refresh.lock" \
  /bin/tar -czf "$BACKUP/clients-cache.tar.gz" -C "$STORAGE/cache" clients
```
Keep refresh scheduling paused until validation completes. Do not wrap the builder itself in this flock: it already acquires the same lock.

Apply the complete reviewed payload to **staging only**, then validate the full installed file set:
```bash
cp -a "$BUNDLE/payload/." "$STAGE/"
python3 "$BUNDLE/verify.py" check --bundle "$BUNDLE" --serving "$STAGE" --phase installed
find "$STAGE/api" "$STAGE/scripts" -type f -name '*.php' -print0 | xargs -0 -n1 /usr/bin/php -l
```
The package files are public code, not secrets. Ensure new files are root-owned 0644 and that the staging parent/root allow www-data traversal (0755 for the new release parent); preserve copied private-config permissions. Verify www-data can read the staged API/config and execute PHP, and only needs write access to existing cache/logs/lock. Do not apply recursive chmod or chown to storage.

### C. Build/check derived index as www-data

Only after the backup and approved maintenance:
```bash
sudo -u www-data env CALLTRACK_STORAGE_DIR=/var/www/html/vr/calltrack/storage \
  CALLTRACK_CLIENTS_DISABLE_SQLITE=1 /usr/bin/php -d memory_limit=256M \
  "$STAGE/scripts/build_client_identity_index.php" --build
sudo -u www-data env CALLTRACK_STORAGE_DIR=/var/www/html/vr/calltrack/storage \
  CALLTRACK_CLIENTS_DISABLE_SQLITE=1 /usr/bin/php \
  "$STAGE/scripts/build_client_identity_index.php" --check
```
--build reads the existing completed snapshot and writes derived files only; it does not fetch Clients, call Sales Journal or connect to databases. A dirty/incomplete marker blocks it. In that case separately review and approve full recovery; never discard the marker. --check validates every shard and reports ready/version/record count without customer details.

### D. Coherent switch

The initial serving path is a real directory, so a symlink cannot atomically replace it using plain mv. Use the approved maintenance window: move the directory aside on the same filesystem and install a symlink to the fully checked stage. The short path gap is covered by maintenance. Precheck all paths, same filesystem and absence of the rollback destination.

```bash
PREVIOUS="/var/www/html/vr/calltrack-v3-before-$RELEASE_SHA"
test ! -e "$PREVIOUS"
test ! -L "$SERVING"
test "$(stat -c %d "$SERVING")" = "$(stat -c %d "$STAGE")"
mv -- "$SERVING" "$PREVIOUS"
if ! ln -s -- "$STAGE" "$SERVING"; then
  mv -- "$PREVIOUS" "$SERVING"
  exit 1
fi
test "$(readlink -f "$SERVING")" = "$STAGE"
python3 "$BUNDLE/verify.py" check --bundle "$BUNDLE" --serving "$SERVING" --phase installed
```

Existing nginx aliases/SCRIPT_FILENAME continue to use the same serving path. A separately approved graceful reload of the applicable PHP-FPM pool/service is needed to avoid stale OPcache/realpath entries; CLI opcache_reset does not clear FPM. Do not restart unrelated applications or edit nginx aliases blindly. Later releases can switch an existing symlink with same-filesystem temporary-symlink + rename under the same validation gates.

Before lifting maintenance, check client cards as manager/admin: unique and duplicate identity, own/foreign customer, no purchases, missing client, purchases/calls/email and MAX incoming/outgoing/day totals. Observe HTTP timings/error logs; require complete representative card responses below two seconds without 500/504. Confirm Sales Journal healthy and authenticated API behavior without printing its token. If any gate fails, roll back.

### E. Rollback

Keep maintenance and refresh pause active. Verify PREVIOUS is the retained real directory, SERVING is the symlink to the exact rejected stage, and shared storage link is unchanged. Remove **only that symlink**, then move PREVIOUS back to SERVING. Never rm -rf the serving path or storage. Reload the applicable FPM only under the approved maintenance action.

A code-only rollback normally leaves the new derived identity directory unused by old code; original clients/records, cursor and MAX data remain. The old status file may show old diagnostics because the new release writes status inside cache. If an approved full/delta changed source data, do not blindly restore the cache backup: doing so can revert manager assignments/cursors. Review recovery under the global lock and an additional explicit data-restore approval. No SQL rollback/migration is required by this package.

Retain backup and rejected stage for investigation; remove only reviewed old release/backup paths after acceptance and retention review. Do not automate deletion here.

## Optional synchronization scheduling — not enabled

Existing CLI: api/refresh_clients_cache.php --source=cron --mode=full|delta. New wrapper defaults to --check and requires www-data, the fixed shared storage, writable existing lock/cache/logs; it sets a 45-minute timeout and 256M PHP memory limit.

After a separate approval, manually validate one full or delta run via:
```bash
sudo -u www-data /usr/bin/bash /var/www/html/vr/calltrack-v3-20261026/scripts/firstvds_clients_refresh.sh --check
# MUTATING SOURCE REFRESH: only in its separately approved maintenance window
sudo -u www-data /usr/bin/bash /var/www/html/vr/calltrack-v3-20261026/scripts/firstvds_clients_refresh.sh delta
```
Use full instead of delta only when a full source refresh/recovery is approved. Do not automatically fall back to full after a failed delta.

Templates in deploy/firstvds: calltrack-clients@.service, calltrack-clients-delta.timer (04:00 Europe/Moscow), calltrack-clients-full.timer (Sunday 03:00 Europe/Moscow). They are not part of the API payload and nothing installs/enables them. Review schedule against existing jobs; choose one scheduler only. The shared nonblocking refresh lock rejects overlap even if a full run crosses the delta slot. Persistent=false avoids surprise catch-up runs after reboot. Service limits protect the 4 GiB host; terminate/retry is subject to the incomplete-marker recovery rules.

For the separate scheduling stage: inspect templates, run systemd-analyze verify, install reviewed unit files, daemon-reload, perform the approved manual test, inspect journalctl and cache/clients/clients_cache_refresh.status.json, then explicitly approve timer enablement. Existing application logs are bounded; configure journal retention according to the host's existing policy. On failure, leave the index fail-closed, stop retries, inspect the safe status/error output locally, and follow full/delta recovery. No cron/systemd changes are performed by this PR or package.

## GO / NO-GO checklist

Code review GO requires final CI, complete payload/dependency checks, no MAX/ACL regressions and disclosed Android limitations. Deployment remains **NO-GO** until:
1. Artifact SHA/commit and a fresh serving baseline match.
2. FPM storage/config/permissions, private cache routing, and disk/memory gates are checked.
3. Source freshness, cursor and incomplete-marker state are acceptable; all refresh writers use the new coordinated implementation.
4. Existing signed automation checksum manifests are reviewed for affected files; no automation is enabled or manifest silently bypassed.
5. Maintenance, backups, staged build, rollback and limited FPM reload are separately approved.
6. Live manager isolation, Sales Journal and MAX smoke tests and complete-card timing pass.

No unit test, hash comparison or synthetic benchmark replaces those production acceptance checks.
