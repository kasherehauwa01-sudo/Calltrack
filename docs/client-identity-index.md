# Client card identity index

The card previously narrowed the ID search using a record shard, then scanned every
client again to prove name/phone/email uniqueness. The new reader uses immutable
file generations with 256 ID buckets and 256 identity-key buckets. No SQLite
extension is required. Normalization is shared with clientCatalogRecords.
A shared identity key records ambiguity instead of selecting an arbitrary client.
Manager authorization and explicit manager bindings still run on each request.
MAX message, call and purchase matching code is unchanged.

All managed full/delta writers acquire an exclusive identity lock, leave a durable
incomplete marker, invalidate the old manifest, publish source changes, build the
new index, and atomically replace its manifest before removing the marker.
HTTP takes a nonblocking shared lock, checks the source fingerprint and manifest,
and verifies size and SHA-256 for every bucket it reads. Missing, corrupt, stale or
in-progress generations return 503 with Retry-After, never a slow fallback.
A missing/ambiguous client remains inaccessible (403). No per-ID derived cache
can outlive a directory generation. The source fingerprint uses file identity,
size and timestamps for all record shards, JSON and optional SQLite/WAL.
External writers must use the same publication functions and locks; editing
canonical cache files in place outside this protocol is unsupported.

The index contains compact identity and assignment data. It is not a public
download and must remain in the existing private storage directory. Building is
streaming, with only one compact partition consolidated in memory. A partition
over 8 MiB fails closed rather than allowing an unbounded HTTP allocation.
A failed full publication requires a complete full refresh; an interrupted delta
can be resumed idempotently from its existing cursor. The offline builder refuses
to certify a directory carrying an incomplete-publication marker.

## Validation

Run PHP 8.5.12 with mbstring and without pdo_sqlite for the production-mode tests.
The client-identity-index workflow also runs the existing SQLite delta tests in
a separate compatibility job. It uses synthetic data only, no application DBs or
production endpoints. The benchmark creates 165,000 synthetic clients and starts
fresh PHP processes with memory_limit=128M. It compares the original shard-target
plus exhaustive uniqueness scan against the indexed lookup. It reports source
and index sizes, build time, p50/p95/max, and PHP peak memory. Its assertion is
directory resolution under 2 seconds and under 32 MiB. OS page cache is warm;
this is not an end-to-end latency claim for the remote purchases API.

## Safe FirstVDS rollout (manual, not executed by this change)

1. Record the actual serving path from nginx, PHP-FPM user/configuration, Git HEAD,
   pending local changes, storage path, cron jobs and deployed file hashes.
   Do not assume /var/www/html/vr/calltrack is the directory nginx serves.
2. Review the full deployment diff against those actual files. At implementation
   baseline, main was a4a02cf4716b37e03bb9d0ff221745127c2c4807 and
   production/max-message-sync was 715be8462deaf270c0729310abab25961f91772a.
   They diverge. Preserve production-only changes in add_max_messages.php,
   max_messages_service.php, its migration and tests. Do not reset the production
   tree to main or replace unrelated files. Recheck these refs at deployment time.
3. Back up the serving code, configuration and complete Clients cache with
   ownership/permissions. Check free space for backup, staged snapshot and new
   index using the benchmark size as an estimate, not a guaranteed upper bound.
   Pause Clients full/delta cron and workers and wait for existing workers to
   finish. Temporarily prevent new client-history requests during code switch.
   Do not stop unrelated services or change MariaDB/PostgreSQL.
4. Stage the reviewed code in a separate release directory, retaining the existing
   configuration and private storage path. Run php -l on changed PHP files and
   the isolated tests. Verify www-data can traverse/read the source and write the
   cache and identity.lock; do not chmod the entire application to 777.
5. With the same environment/storage path as FPM, build explicitly as www-data:
   sudo -u www-data env CALLTRACK_STORAGE_DIR=/ABSOLUTE/EXISTING/STORAGE php scripts/build_client_identity_index.php --build
   sudo -u www-data env CALLTRACK_STORAGE_DIR=/ABSOLUTE/EXISTING/STORAGE php scripts/build_client_identity_index.php --check
   Paths above are placeholders to replace with the verified deployment paths.
   --check is the default and does not build; --build only writes derived index
   files and reads the existing canonical snapshot. Neither invokes remote APIs,
   installs dependencies, changes client records, or connects to application DBs.
   If interrupted-publication marker exists, do not delete it to force success:
   investigate and complete the normal full refresh first under supervision.
6. Switch all reviewed reader and writer files as one release. Reload PHP-FPM
   gracefully for OPcache, then verify an authorized manager's own client,
   denied foreign client, an ambiguous identity and missing ID, plus MAX events.
   Measure authenticated HTTP purchases/events latency separately: upstream or
   database delays can remain even when directory lookup is fast.
7. Resume refresh workers only after confirming they load the new writer code.
   Verify an actual refresh publishes a new generation and --check passes.
   Update any approved code-integrity manifest through its normal review process;
   do not disable integrity enforcement to accommodate new file hashes.

No automation, cron installation, database migration or deployment is performed.

## Rollback

Pause the same readers/writers; retain the failed release and index for diagnosis.
Restore the backed-up code as a coherent release and reload PHP-FPM. The derived
index can remain unused by old code. Do not restore old source data over a newer
successful refresh or reset cursors. If rolling forward again after old writers
ran, rebuild and verify the index while writers are paused before reopening cards.
A partial canonical publication requires recovery/full refresh, not copying an old
manifest over new files. Rollback restores the former slow behavior and is an
emergency escape, not a performance fix.
